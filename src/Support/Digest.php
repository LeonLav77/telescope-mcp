<?php

namespace LloydsDigital\TelescopeMcp\Support;

use Carbon\CarbonImmutable;
use Laravel\Telescope\Storage\EntryModel;

/**
 * What happened inside one batch (a request, job run, command, scheduler
 * run...), condensed so the model can decide what to open next.
 */
final class Digest
{
    private const MAX_ROWS = 2000;

    private const PER_TYPE = 10;

    private const MAX_TASKS = 15;

    /** Repeats of one query from one place, with different values, that count as an N+1. */
    public const N_PLUS_ONE = 5;

    public function __construct(private Entries $entries)
    {
    }

    /**
     * @param  bool  $brief  Only counts, origin and time breakdown.
     * @return array<string, mixed>
     */
    public function of(string $batchId, ?string $excludeId = null, bool $brief = false): array
    {
        $rows = $this->entries->batch($batchId)->orderBy('sequence')->limit(self::MAX_ROWS + 1)->get();
        $truncated = $rows->count() > self::MAX_ROWS;
        $rows = $rows->take(self::MAX_ROWS);
        $start = $rows->min('created_at');
        $tasks = $this->scheduledTasks($rows->where('type', 'schedule')->values()->all(), $rows->where('type', 'exception')->all(), $start);

        $digest = array_filter([
            'batch_id' => $batchId,
            'counts' => $this->entries->countsByType($this->entries->batch($batchId)),
            'origin' => $this->origin($batchId, $rows->all(), $tasks, $start),
            'time' => $this->time($rows->all(), $start, $rows->max('created_at')),
        ], fn ($value) => $value !== null);

        if ($brief) {
            return $digest + ['next' => 'get_entry(id, batch="full") or get_batch(batch_id) for the rest of this batch.'];
        }

        $queries = $rows->where('type', 'query');

        if ($queries->isNotEmpty()) {
            $digest['queries'] = $this->queries($queries->all());
        }

        if ($tasks !== []) {
            $digest['scheduled_tasks'] = $this->taskList($tasks);
        }

        foreach ($rows->whereNotIn('type', ['query', 'schedule'])->groupBy('type') as $type => $ofType) {
            $ofType = $ofType->reject(fn ($row) => $row->uuid === $excludeId);

            if ($ofType->isNotEmpty()) {
                $digest['entries'][$type] = $this->collapse($ofType->all());
            }
        }

        if ($truncated) {
            $digest['note'] = 'Batch has more than '.self::MAX_ROWS.' entries; digest covers the first '.self::MAX_ROWS.'. Use get_batch to page through all.';
        }

        $digest['next'] = 'get_entry(id) opens any entry; get_batch(batch_id, type) lists every entry of a type in order.';

        return $digest;
    }

    /**
     * What started this batch: its request/command, a scheduler run, or for a
     * queue worker batch, the job that was executed.
     *
     * @param  array<int, EntryModel>  $rows
     * @param  list<array<string, mixed>>  $tasks
     * @return array<string, mixed>|null
     */
    private function origin(string $batchId, array $rows, array $tasks, mixed $start): ?array
    {
        foreach ($rows as $row) {
            if (in_array($row->type, ['request', 'command'], true)) {
                return Present::row($row);
            }
        }

        // `schedule:run` itself is not recorded; its batch holds one entry per task it ran.
        if ($tasks !== []) {
            return [
                'scheduler_run' => [
                    'tasks' => count($tasks),
                    'failed' => count(array_filter($tasks, fn ($task) => isset($task['failed']))),
                    'from' => Present::time($start),
                    'to' => $tasks[count($tasks) - 1]['at'],
                ],
            ];
        }

        $job = $this->entries->jobExecutedIn($batchId);

        return $job ? ['executed_job' => Present::row($job)] : null;
    }

    /**
     * Where the time went, for spotting time spent outside the database. Only
     * requests record their duration; for job runs, commands and scheduler
     * runs the span from first to last entry stands in (second precision).
     *
     * @param  array<int, EntryModel>  $rows
     * @return array<string, float|string>|null
     */
    private function time(array $rows, mixed $first, mixed $last): ?array
    {
        $sums = ['queries_ms' => 0.0, 'redis_ms' => 0.0, 'http_out_ms' => 0.0];
        $total = null;

        foreach ($rows as $row) {
            $duration = Present::duration($row->type, Present::content($row)) ?? 0.0;

            match ($row->type) {
                'query' => $sums['queries_ms'] += $duration,
                'redis' => $sums['redis_ms'] += $duration,
                'client_request' => $sums['http_out_ms'] += $duration,
                'request' => $total ??= $duration,
                default => null,
            };
        }

        $time = array_map(fn ($ms) => round($ms, 2), array_filter($sums));

        if ($total !== null) {
            $time = ['total_ms' => $total] + $time + ['unaccounted_ms' => round(max(0, $total - array_sum($sums)), 2)];
        } elseif ($first !== null && $last !== null && count($rows) > 1) {
            $span = CarbonImmutable::parse(Present::time($first))->diffInSeconds(CarbonImmutable::parse(Present::time($last)), true);
            $time = ['span' => self::approx($span).' (first to last entry)'] + $time;
        }

        return $time === [] ? null : $time;
    }

    /**
     * The batch's queries: total, slowest, and repeated statements. A query
     * repeated with different values from one place is an N+1 (a loop that
     * should eager load); repeated with the same values it is a duplicate
     * (cache or hoist it).
     *
     * @param  array<int, EntryModel>  $rows
     * @return array<string, mixed>
     */
    private function queries(array $rows): array
    {
        $items = array_map(function ($row) {
            $content = Present::content($row);
            $sql = (string) ($content['sql'] ?? '');
            $location = isset($content['file']) ? basename((string) $content['file']).':'.($content['line'] ?? '?') : null;

            return [
                'row' => $row,
                'time' => (float) ($content['time'] ?? 0),
                'key' => ($content['hash'] ?? Present::pattern($sql, true)).'@'.$location,
                'sql' => $sql,
                'location' => $location,
            ];
        }, array_values($rows));

        $slowest = $items;
        usort($slowest, fn ($a, $b) => $b['time'] <=> $a['time']);

        $groups = [];
        foreach ($items as $item) {
            $groups[$item['key']][] = $item;
        }

        $repeated = array_filter($groups, fn ($group) => count($group) > 1);
        uasort($repeated, fn ($a, $b) => count($b) <=> count($a) ?: array_sum(array_column($b, 'time')) <=> array_sum(array_column($a, 'time')));

        $repeated = array_map(function ($group) {
            $distinct = count(array_unique(array_column($group, 'sql')));

            return array_filter([
                'kind' => $distinct > 1 ? 'n+1' : 'identical',
                'times' => count($group),
                'distinct_values' => $distinct > 1 ? $distinct : null,
                'total_ms' => round(array_sum(array_column($group, 'time')), 2),
                'location' => $group[0]['location'],
                'sql' => mb_substr(Present::pattern($group[0]['sql'], true), 0, 300),
                'sample_id' => $group[0]['row']->uuid,
            ], fn ($value) => $value !== null);
        }, $repeated);

        return array_filter([
            'count' => count($items),
            'total_ms' => round(array_sum(array_column($items, 'time')), 2),
            'n_plus_one' => count(array_filter($repeated, fn ($group) => $group['kind'] === 'n+1' && $group['times'] >= self::N_PLUS_ONE)) ?: null,
            'slowest' => array_map(fn ($item) => [
                'id' => $item['row']->uuid,
                'summary' => Present::summary('query', Present::content($item['row'])),
            ], array_slice($slowest, 0, 5)),
            'repeated' => array_values(array_slice($repeated, 0, 5)),
        ], fn ($value) => $value !== null);
    }

    /**
     * Entries of one type: identical summaries collapsed with a count,
     * problems first, at most PER_TYPE lines.
     *
     * @param  array<int, EntryModel>  $rows
     * @return list<array<string, mixed>|string>
     */
    private function collapse(array $rows): array
    {
        $groups = [];

        foreach ($rows as $row) {
            $content = Present::content($row);
            $summary = self::similar(Present::summary($row->type, $content));

            if (isset($groups[$summary])) {
                $groups[$summary]['times']++;

                continue;
            }

            $item = Present::row($row);
            unset($item['batch_id'], $item['type']);

            if ($row->type === 'job') {
                $item['execution_batch'] = $content['updated_batch_id'] ?? null;
            }

            $groups[$summary] = ['item' => $item, 'times' => 1, 'anomaly' => Present::isAnomaly($row->type, $content)];
        }

        // usort is stable: problems first, otherwise in the order they happened.
        $groups = array_values($groups);
        usort($groups, fn ($a, $b) => $b['anomaly'] <=> $a['anomaly']);

        $lines = array_map(
            fn ($group) => $group['item'] + ($group['times'] > 1 ? ['times' => $group['times']] : []),
            array_slice($groups, 0, self::PER_TYPE),
        );

        if (count($groups) > self::PER_TYPE) {
            $lines[] = '… +'.(count($groups) - self::PER_TYPE).' more distinct (get_batch with type for all)';
        }

        return $lines;
    }

    /**
     * Tasks of a scheduler run. Telescope records each task when it finishes,
     * so a task ran from the previous task's end to its own entry. A failed
     * task shows up only as the scheduler's "failed with exit code" exception.
     *
     * @param  list<EntryModel>  $rows
     * @param  array<int, EntryModel>  $exceptions
     * @return list<array<string, mixed>>
     */
    private function scheduledTasks(array $rows, array $exceptions, mixed $start): array
    {
        if ($rows === []) {
            return [];
        }

        $failures = [];

        foreach ($exceptions as $exception) {
            $message = (string) (Present::content($exception)['message'] ?? '');

            if (preg_match('/^Scheduled command \[(.*)\] failed with exit code \[(-?\d+)\]/s', $message, $match) === 1) {
                $failures[$match[1]] = ['exit_code' => (int) $match[2], 'exception_id' => $exception->uuid];
            }
        }

        $tasks = [];
        $previous = $start ?? $rows[0]->created_at;

        foreach ($rows as $row) {
            $content = Present::content($row);
            $command = (string) ($content['command'] ?? '');
            $started = CarbonImmutable::parse(Present::time($previous));
            $finished = CarbonImmutable::parse(Present::time($row->created_at));

            $task = [
                'id' => $row->uuid,
                'at' => Present::time($row->created_at),
                'task' => self::taskName($command) ?: ($content['description'] ?? 'Closure'),
                'ran_for' => self::approx($started->diffInSeconds($finished, true)),
            ];

            if (isset($failures[$command])) {
                $task['failed'] = 'exit code '.$failures[$command]['exit_code'];
                $task['exception_id'] = $failures[$command]['exception_id'];
                $task += $this->taskRun($command, $started, $finished);
            }

            $tasks[] = $task;
            $previous = $row->created_at;
        }

        return $tasks;
    }

    /**
     * @param  list<array<string, mixed>>  $tasks
     * @return list<array<string, mixed>|string>
     */
    private function taskList(array $tasks): array
    {
        usort($tasks, fn ($a, $b) => isset($b['failed']) <=> isset($a['failed']));
        $list = array_slice($tasks, 0, self::MAX_TASKS);

        if (count($tasks) > self::MAX_TASKS) {
            $list[] = '… +'.(count($tasks) - self::MAX_TASKS).' more tasks (get_batch with type=schedule for all)';
        }

        return $list;
    }

    /**
     * The failed task's own process is a separate artisan command with its
     * own batch. When it died hard (fatal error, memory limit, killed) it
     * never reached Telescope's terminate hook and left no entry.
     *
     * @return array<string, mixed>
     */
    private function taskRun(string $command, CarbonImmutable $started, CarbonImmutable $finished): array
    {
        if (preg_match("/artisan'?\\s+'?([\\w:.\\-]+)/", $command, $match) !== 1) {
            return [];
        }

        $name = $match[1];
        $query = $this->entries->typed('command');

        if ($this->entries->within($query, new Window($started->subMinute(), $finished->addMinute()))) {
            $this->entries->containsJson($query, '"command":"'.$name.'"');
            $run = $query->orderByDesc('sequence')->first();

            if ($run) {
                return ['command_entry' => Present::row($run)];
            }
        }

        return ['note' => "The task's own process ({$name}) left no Telescope entry: it most likely died before finishing (fatal error, memory limit, timeout or killed). Check the server's PHP and cron logs."];
    }

    /**
     * "media-library:clean --force" from "'/usr/bin/php8.4' 'artisan' media-library:clean --force".
     */
    private static function taskName(string $command): string
    {
        return trim((string) preg_replace("/^.*?'?artisan'?\\s+/", '', $command));
    }

    private static function approx(float $seconds): string
    {
        $seconds = (int) round($seconds);

        return match (true) {
            $seconds < 1 => '<1s',
            $seconds < 60 => "~{$seconds}s",
            $seconds < 3600 => '~'.intdiv($seconds, 60).'m '.($seconds % 60).'s',
            default => '~'.intdiv($seconds, 3600).'h '.intdiv($seconds % 3600, 60).'m',
        };
    }

    /**
     * Summaries that differ only in timings or ids count as the same line.
     */
    private static function similar(string $summary): string
    {
        return (string) preg_replace(
            ['/^\d+(\.\d+)?ms /', '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', '/[0-9A-HJKMNP-TV-Z]{26}/', '/:\d+\b/'],
            ['', '…', '…', ':…'],
            $summary,
        );
    }
}
