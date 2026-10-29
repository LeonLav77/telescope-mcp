<?php

namespace LloydsDigital\TelescopeMcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Telescope\Storage\EntryModel;
use LloydsDigital\TelescopeMcp\Support\Entries;
use LloydsDigital\TelescopeMcp\Support\Input;
use LloydsDigital\TelescopeMcp\Support\InvalidInput;
use LloydsDigital\TelescopeMcp\Support\Present;
use LloydsDigital\TelescopeMcp\Support\Trace;
use LloydsDigital\TelescopeMcp\Support\Window;

#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
class Stats extends TelescopeTool
{
    private const SORTS = ['count', 'errors', 'total_ms', 'p95_ms', 'max_ms'];

    private const GROUP_BY = ['default', 'user', 'ip', 'status', 'hostname'];

    protected string $name = 'stats';

    protected string $title = 'Telescope stats';

    protected string $description = <<<'TEXT'
        Aggregate one entry type over a window (default: last 24h) to find what is slow, failing or noisy.
        Groups: requests by method + route/controller, client_requests by method + host + path, jobs by class
        (with status counts), queries by normalized SQL, commands and scheduled tasks by name, exceptions by family,
        logs by level + message, mail/notifications/events by class, cache by operation + key pattern, redis by command.
        group_by="user" groups by logged-in user instead (who is active, who hits errors), "ip" and "status" by
        client IP and HTTP status (requests), "hostname" by server.
        Each group has count, errors (5xx, failed jobs, non-zero exits, error logs...), duration percentiles where
        recorded (p50/p95/max/total ms), last_seen and a sample id for get_entry. Request groups also get
        median_gap_s, the typical seconds between two of its requests: a small, steady gap for one user or IP means
        something is polling (an auto-refresh, a script). compare_previous=true adds the same
        numbers for the window just before (e.g. before/after a deploy) and flags new groups.
        Takes the filters of list_entries (except search). Reads at most the newest scan limit entries of the window
        within the time limit; `coverage` says how much was read.
        TEXT;

    public function schema(JsonSchema $schema): array
    {
        return [
            'type' => $schema->string()->enum(Entries::TYPES)->description('Entry type to aggregate.')->required(),
            'from' => $schema->string()->description('Window start, ISO 8601. UTC if no offset.'),
            'to' => $schema->string()->description('Window end, ISO 8601. Defaults to now.'),
            'minutes' => $schema->integer()->min(1)->max(Window::MAX_MINUTES)->description('Window length counting back from `to` (or now). Default 1440 (24h).'),
            'sort' => $schema->string()->enum(self::SORTS)->description('Default: total_ms for types with durations (where the time goes), count otherwise.'),
            'group_by' => $schema->string()->enum(self::GROUP_BY)->default('default')
                ->description('"default" groups as described; "user", "ip" (requests), "status" (requests, client_requests) or "hostname" instead.'),
            'compare_previous' => $schema->boolean()->description('Also aggregate the window of the same length just before, per group.'),
            'tag' => $schema->string()->max(255)->description('Only entries with this exact tag (see list_tags).'),
            'exclude' => $schema->string()->max(200)->description('Leave out entries whose content contains this text.'),
            'name' => $schema->string()->max(255)->description('Substring of the class/command/key (as in list_entries).'),
            'path' => $schema->string()->max(255)->description('Requests: URI path prefix. Client_requests: URL substring.'),
            'hostname' => $schema->string()->max(255)->description('Only entries recorded on this server.'),
            'status' => $schema->string()->enum(['pending', 'processed', 'failed', 'released'])->description('Jobs only.'),
            'level' => $schema->string()->max(100)->description('Logs only: levels, comma-separated.'),
            'min_status' => $schema->integer()->min(100)->max(599)->description('Requests / client_requests only: minimum HTTP status.'),
            'max_status' => $schema->integer()->min(100)->max(599)->description('Requests / client_requests only: maximum HTTP status.'),
            'method' => $schema->string()->max(10)->description('Requests / client_requests only: HTTP method.'),
            'min_ms' => $schema->number()->description('Queries, requests, client_requests, redis only: minimum duration in ms.'),
            'limit' => $schema->integer()->min(1)->max(50)->default(20)->description('Number of groups.'),
        ];
    }

    protected function run(Input $input, Entries $entries): array
    {
        $type = $input->type();
        $limit = $input->int('limit', 20, 1, 50);
        $timed = in_array($type, ListEntries::DURATION_TYPES, true);
        $sort = $input->enum('sort', self::SORTS) ?? ($timed ? 'total_ms' : 'count');
        $window = Window::fromInput($input, 1440);
        // Every list_entries filter except free-text search, which would scan content across the whole window.
        $filters = array_diff_key(ListEntries::filters($input), ['search' => true]);
        $groupBy = $input->enum('group_by', self::GROUP_BY) ?? 'default';

        if ($groupBy === 'ip') {
            ListEntries::assertType($type, ['request'], 'group_by=ip');
        }

        if ($groupBy === 'status') {
            ListEntries::assertType($type, ['request', 'client_request'], 'group_by=status');
        }

        if (! $timed && in_array($sort, ['total_ms', 'p95_ms', 'max_ms'], true)) {
            throw new InvalidInput("[sort={$sort}] needs durations, which only ".implode(', ', ListEntries::DURATION_TYPES).' record.');
        }

        $current = $this->aggregate($entries, $type, $window, $filters, $groupBy);
        $groups = $current['groups'];
        uasort($groups, fn ($a, $b) => ($b[$sort] ?? 0) <=> ($a[$sort] ?? 0) ?: $b['count'] <=> $a['count']);
        $groups = array_slice($groups, 0, $limit, true);

        $data = array_filter([
            'type' => $type,
            'window' => $window->toArray(),
            'filters' => (object) $filters,
            'sort' => $sort,
            'group_by' => $groupBy === 'default' ? null : $groupBy,
            'coverage' => $current['coverage'],
            'totals' => $current['totals'],
        ], fn ($value) => $value !== null);

        if ($input->bool('compare_previous', false) && ($previousWindow = $window->previous())) {
            $previous = $this->aggregate($entries, $type, $previousWindow, $filters, $groupBy);
            $data['previous'] = ['window' => $previousWindow->toArray(), 'coverage' => $previous['coverage'], 'totals' => $previous['totals']];

            foreach ($groups as $key => $group) {
                $before = $previous['groups'][$key] ?? null;
                $groups[$key]['previous'] = $before
                    ? array_intersect_key($before, array_flip(['count', 'errors', 'p95_ms', 'total_ms']))
                    : 'new: not seen in the previous window';
            }
        }

        $data['groups'] = array_values($groups);
        $data['next'] = 'get_entry(sample_id) to see one occurrence; list_entries(type, name/path/..., sort="slowest") for more of a group.';

        return $data;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{groups: array<string, array<string, mixed>>, totals: array<string, mixed>, coverage: array<string, mixed>}
     */
    private function aggregate(Entries $entries, string $type, Window $window, array $filters, string $groupBy = 'default'): array
    {
        $query = $entries->typed($type);
        $empty = ['groups' => [], 'totals' => ['count' => 0], 'coverage' => ['scanned' => 0, 'complete' => true]];

        if (! $entries->within($query, $window)) {
            return $empty;
        }

        $filter = ListEntries::apply($entries, $query, $type, $filters);
        $groups = [];
        $durations = [];
        $gaps = [];
        $previous = [];

        $scan = $entries->each($query, (int) config('telescope-mcp.stats_scan_limit', 20000), function (EntryModel $row) use ($type, $filter, $groupBy, &$groups, &$durations, &$gaps, &$previous) {
            if ($filter && ! $filter($row)) {
                return;
            }

            $c = Present::content($row);
            [$key, $label] = $groupBy === 'default' ? self::key($type, $c, $row) : self::groupKey($groupBy, $c);
            $anomaly = Present::isAnomaly($type, $c);

            $group = $groups[$key] ??= array_filter([
                'group' => $label,
                'count' => 0,
                'errors' => 0,
                'statuses' => $type === 'job' ? [] : null,
                'last_seen' => Present::time($row->created_at),
                'sample_id' => $row->uuid,
            ], fn ($value) => $value !== null);

            $group['count']++;

            if ($anomaly) {
                $group['errors']++;
                $group['sample_error_id'] ??= $row->uuid;
            }

            if ($type === 'job') {
                $status = (string) ($c['status'] ?? 'unknown');
                $group['statuses'][$status] = ($group['statuses'][$status] ?? 0) + 1;
            }

            if ($type === 'request' && ($status = (int) ($c['response_status'] ?? 0)) >= 400 && $status < 500) {
                $group['client_errors'] = ($group['client_errors'] ?? 0) + 1;
            }

            if (($ms = Present::duration($type, $c)) !== null) {
                $durations[$key][] = $ms;
            }

            // Rows come newest first: the gap to the group's previous (newer) one.
            if ($type === 'request' && ($at = self::timestamp($row)) !== null) {
                if (isset($previous[$key])) {
                    $gaps[$key][] = $previous[$key] - $at;
                }

                $previous[$key] = $at;
            }

            $groups[$key] = $group;
        }, chunk: in_array($type, Entries::SMALL_TYPES, true) ? 5000 : 1000);

        foreach ($gaps as $key => $values) {
            if (count($values) >= 2) {
                sort($values);
                $groups[$key]['median_gap_s'] = self::percentile($values, 0.5);
            }
        }

        foreach ($durations as $key => $values) {
            sort($values);
            $groups[$key] += [
                'total_ms' => round(array_sum($values), 2),
                'p50_ms' => self::percentile($values, 0.5),
                'p95_ms' => self::percentile($values, 0.95),
                'max_ms' => round(end($values), 2),
            ];
        }

        $totals = ['count' => array_sum(array_column($groups, 'count')), 'errors' => array_sum(array_column($groups, 'errors')), 'groups' => count($groups)];

        if ($durations !== []) {
            $totals['total_ms'] = round(array_sum(array_column($groups, 'total_ms')), 2);
        }

        $coverage = ['scanned' => $scan['scanned'], 'complete' => ! $scan['capped']];

        if ($scan['capped']) {
            $coverage['covers_from'] = $scan['oldest'];
            $coverage['note'] = "Read the newest {$scan['scanned']} matching entries (back to {$scan['oldest']}), stopped by ".Entries::stoppedBecause($scan).'; narrow the window for complete numbers.';
        }

        return ['groups' => $groups, 'totals' => $totals, 'coverage' => $coverage];
    }

    /**
     * The group an entry belongs to: [stable key, readable label].
     *
     * @param  array<string, mixed>  $c
     * @return array{0: string, 1: string}
     */
    private static function key(string $type, array $c, EntryModel $row): array
    {
        $s = fn (string $field): string => is_scalar($c[$field] ?? null) ? (string) $c[$field] : '';

        $label = match ($type) {
            'request' => $s('method').' '.($s('controller_action') ?: Present::path($s('uri'))),
            'client_request' => $s('method').' '.Present::url($s('uri')),
            'query' => mb_substr(Present::pattern($s('sql'), true), 0, 200).(isset($c['file']) ? ' ('.basename($s('file')).':'.$s('line').')' : ''),
            'exception' => class_basename($s('class')).(($at = Trace::location($c)) ? " ({$at})" : '').': '.mb_substr(Present::pattern($s('message')), 0, 120),
            'log' => strtoupper($s('level')).': '.mb_substr(Present::pattern($s('message')), 0, 150),
            'schedule' => trim((string) preg_replace("/^.*?'?artisan'?\\s+/", '', $s('command'))) ?: ($s('description') ?: 'Closure'),
            'cache' => $s('type').' '.Present::pattern($s('key')),
            'redis' => strtoupper(strtok($s('command'), ' ') ?: '?'),
            'notification' => class_basename($s('notification')).' via '.$s('channel'),
            'model' => $s('action').' '.$s('model'),
            'gate' => $s('ability').' → '.$s('result'),
            'job', 'event', 'view', 'batch' => $s('name'),
            'command' => $s('command'),
            'mail' => $s('mailable'),
            default => $type,
        };

        $key = match ($type) {
            'query' => $s('hash') ?: $label,
            'exception' => $row->family_hash ?: $label,
            default => $label,
        };

        return [$key, $label];
    }

    /**
     * @param  array<string, mixed>  $c
     * @return array{0: string, 1: string}
     */
    private static function groupKey(string $groupBy, array $c): array
    {
        return match ($groupBy) {
            'user' => isset($c['user']['id'])
                ? ['user:'.$c['user']['id'], (string) Present::user($c, true)]
                : ['guest', 'not logged in'],
            'ip' => [(string) ($c['ip_address'] ?? '?'), (string) ($c['ip_address'] ?? 'unknown')],
            'status' => [(string) ($c['response_status'] ?? 'none'), (string) ($c['response_status'] ?? 'no response')],
            default => [(string) ($c['hostname'] ?? '?'), (string) ($c['hostname'] ?? 'unknown')],
        };
    }

    private static function timestamp(EntryModel $row): ?int
    {
        $at = Present::time($row->created_at);

        return $at === null ? null : (strtotime($at) ?: null);
    }

    /**
     * @param  list<float|int>  $sorted
     */
    private static function percentile(array $sorted, float $p): float
    {
        return round($sorted[(int) max(0, ceil($p * count($sorted)) - 1)], 2);
    }
}
