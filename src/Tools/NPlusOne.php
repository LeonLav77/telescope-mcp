<?php

namespace LloydsDigital\TelescopeMcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Telescope\Storage\EntryModel;
use LloydsDigital\TelescopeMcp\Support\Digest;
use LloydsDigital\TelescopeMcp\Support\Entries;
use LloydsDigital\TelescopeMcp\Support\Input;
use LloydsDigital\TelescopeMcp\Support\Present;
use LloydsDigital\TelescopeMcp\Support\Window;

#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
class NPlusOne extends TelescopeTool
{
    private const SORTS = ['total_ms', 'batches', 'max_repeats'];

    private const MAX_BATCHES = 300;

    /** Batches whose request/command is looked up to say where an N+1 happens. */
    private const ORIGINS = 2000;

    protected string $name = 'n_plus_one';

    protected string $title = 'Find N+1 queries';

    protected string $description = <<<'TEXT'
        Find N+1 queries across the app: the same query run many times from one place within a single request or job
        (a loop that lazy-loads a relation). Groups by query and code location, with how many requests/jobs did it,
        the most repeats in one of them, total time spent, the routes affected, and a sample request to open with
        get_entry. kind "n+1" means different values each time (fix: eager load with with()/load() at the location);
        "identical" means the very same query repeated (fix: reuse the result, cache, or hoist it out of the loop).
        A location like "artisan:13" or "index.php:17" means the query comes from a package (vendor code).
        Default: the newest queries of the last 24h (scan-limited; `coverage` says how far back). Pass path (URI
        prefix) or name (controller or job class) to check specific endpoints or jobs thoroughly instead.
        TEXT;

    public function schema(JsonSchema $schema): array
    {
        return [
            'from' => $schema->string()->description('Window start, ISO 8601. UTC if no offset.'),
            'to' => $schema->string()->description('Window end, ISO 8601. Defaults to now.'),
            'minutes' => $schema->integer()->min(1)->max(Window::MAX_MINUTES)->description('Window length counting back from `to` (or now). Default 1440 (24h).'),
            'min_repeats' => $schema->integer()->min(2)->max(1000)->default(Digest::N_PLUS_ONE)->description('Repeats within one request/job to count.'),
            'path' => $schema->string()->max(255)->description('Only requests whose URI starts with this, e.g. "/api/orders".'),
            'name' => $schema->string()->max(255)->description('Only requests whose controller, or jobs whose class, contains this, e.g. "OrderController".'),
            'sort' => $schema->string()->enum(self::SORTS)->default('total_ms')->description('total_ms: time spent; batches: how widespread; max_repeats: worst single request or job.'),
            'limit' => $schema->integer()->min(1)->max(30)->default(10),
        ];
    }

    protected function run(Input $input, Entries $entries): array
    {
        $window = Window::fromInput($input, 1440);
        $minRepeats = $input->int('min_repeats', Digest::N_PLUS_ONE, 2, 1000);
        $path = $input->string('path', 255);
        $name = $input->string('name', 255);
        $sort = $input->enum('sort', self::SORTS) ?? 'total_ms';
        $limit = $input->int('limit', 10, 1, 30);

        [$perBatch, $coverage, $labels] = $path || $name
            ? $this->fromOrigins($entries, $window, $path, $name)
            : $this->fromQueries($entries, $window);

        $groups = $this->groups($perBatch, $minRepeats);
        uasort($groups, fn ($a, $b) => $b[$sort] <=> $a[$sort]);
        $groups = array_slice($groups, 0, $limit, true);

        // Which request/command each affected batch was, for up to ORIGINS batches in all.
        $checked = array_slice(array_values(array_unique(array_merge([], ...array_column($groups, '_batches')))), 0, self::ORIGINS);
        $origins = $entries->originsOf($checked);
        $checked = array_flip($checked);

        $groups = array_map(function ($group) use ($origins, $checked, $labels) {
            $routes = [];
            $unchecked = 0;

            foreach ($group['_batches'] as $batchId) {
                if (! isset($checked[$batchId])) {
                    $unchecked++;

                    continue;
                }

                $route = $labels[$batchId] ?? Entries::originLabel($origins[$batchId] ?? null);
                $routes[$route] = ($routes[$route] ?? 0) + 1;
            }

            arsort($routes);
            $sample = $origins[$group['_sample_batch']] ?? null;
            $group['where'] = array_slice($routes, 0, 5, true) + ($unchecked > 0 ? ['(not looked up)' => $unchecked] : []);
            $group['sample'] = $sample ? Present::row($sample) : array_filter([
                'run_of' => $labels[$group['_sample_batch']] ?? null,
                'batch_id' => $group['_sample_batch'],
                'query_id' => $group['sample_query_id'],
            ]);
            unset($group['_batches'], $group['_sample_batch'], $group['sample_query_id']);

            return $group;
        }, $groups);

        return [
            'window' => $window->toArray(),
            'min_repeats' => $minRepeats,
            'coverage' => $coverage,
            'found' => count($groups),
            'groups' => array_values($groups),
            'next' => 'get_entry(sample.id) shows the request with its repeated queries; read the code at `location` to fix it.',
        ];
    }

    /**
     * Queries of the window, newest first, counted per batch.
     *
     * @return array{0: array<string, array<string, array<string, mixed>>>, 1: array<string, mixed>, 2: array<string, string>}
     */
    private function fromQueries(Entries $entries, Window $window): array
    {
        $query = $entries->typed('query');
        $perBatch = [];

        if (! $entries->within($query, $window)) {
            return [[], ['queries' => 0, 'complete' => true], []];
        }

        $scan = $entries->each($query, (int) config('telescope-mcp.query_scan_limit', 50000), function (EntryModel $row) use (&$perBatch) {
            $this->count($perBatch, $row);
        }, chunk: 5000);

        $coverage = ['queries' => $scan['scanned'], 'batches' => count($perBatch), 'complete' => ! $scan['capped']];

        if ($scan['capped']) {
            $coverage['covers_from'] = $scan['oldest'];
            $coverage['note'] = "Read the newest {$scan['scanned']} queries (back to {$scan['oldest']}), stopped by ".Entries::stoppedBecause($scan).'. Narrow the window, or pass path/name to check specific endpoints thoroughly.';
        }

        return [$perBatch, $coverage, []];
    }

    /**
     * All queries of the newest matching requests (or job runs).
     *
     * @return array{0: array<string, array<string, array<string, mixed>>>, 1: array<string, mixed>, 2: array<string, string>}
     */
    private function fromOrigins(Entries $entries, Window $window, ?string $path, ?string $name): array
    {
        $batches = [];

        if ($path || $name) {
            $filters = array_filter(['path' => $path]);
            $requests = $entries->typed('request');

            if ($entries->within($requests, $window)) {
                $filter = ListEntries::apply($entries, $requests, 'request', $filters);
                $entries->each($requests, (int) config('telescope-mcp.scan_limit', 5000), function (EntryModel $row) use (&$batches, $filter, $name) {
                    if ($filter && ! $filter($row)) {
                        return;
                    }

                    if ($name && ! str_contains(mb_strtolower((string) (Present::content($row)['controller_action'] ?? '')), mb_strtolower($name))) {
                        return;
                    }

                    $batches[$row->batch_id] = true;

                    return count($batches) < self::MAX_BATCHES;
                });
            }
        }

        // No matching requests: a job class. Its queries are in its own run's batch (updated_batch_id).
        if ($name && ! $path && $batches === []) {
            $jobs = $entries->typed('job');

            if ($entries->within($jobs, $window)) {
                $entries->contains($jobs, $name);
                $entries->each($jobs, (int) config('telescope-mcp.scan_limit', 5000), function (EntryModel $row) use (&$batches, $name) {
                    $c = Present::content($row);

                    if (str_contains(mb_strtolower((string) ($c['name'] ?? '')), mb_strtolower($name)) && ! empty($c['updated_batch_id'])) {
                        $batches[$c['updated_batch_id']] = 'job '.class_basename((string) $c['name']);
                    }

                    return count($batches) < self::MAX_BATCHES;
                });
            }
        }

        $perBatch = [];
        $queries = 0;

        foreach (array_chunk(array_keys($batches), 50) as $chunk) {
            if ($entries->outOfTime()) {
                break;
            }

            $entries->query()->where('type', 'query')->whereIn('batch_id', $chunk)->get()
                ->each(function (EntryModel $row) use (&$perBatch, &$queries) {
                    $queries++;
                    $this->count($perBatch, $row);
                });
        }

        return [
            $perBatch,
            ['matched_batches' => count($batches), 'queries' => $queries, 'complete' => ! $entries->outOfTime() && count($batches) < self::MAX_BATCHES],
            array_filter($batches, 'is_string'),
        ];
    }

    /**
     * @param  array<string, array<string, array<string, mixed>>>  $perBatch
     */
    private function count(array &$perBatch, EntryModel $row): void
    {
        $c = Present::content($row);
        $sql = (string) ($c['sql'] ?? '');
        $location = isset($c['file']) ? basename((string) $c['file']).':'.($c['line'] ?? '?') : '?';
        $key = ($c['hash'] ?? md5(Present::pattern($sql, true))).'@'.$location;
        $entry = &$perBatch[$row->batch_id][$key];

        if ($entry === null) {
            $entry = ['times' => 0, 'ms' => 0.0, 'sql' => $sql, 'varies' => false, 'location' => $location, 'id' => $row->uuid];
        }

        $entry['times']++;
        $entry['ms'] += (float) ($c['time'] ?? 0);
        $entry['varies'] = $entry['varies'] || $sql !== $entry['sql'];
    }

    /**
     * Repeats across batches, per query and location.
     *
     * @param  array<string, array<string, array<string, mixed>>>  $perBatch
     * @return array<string, array<string, mixed>>
     */
    private function groups(array $perBatch, int $minRepeats): array
    {
        $groups = [];

        foreach ($perBatch as $batchId => $queries) {
            foreach ($queries as $key => $query) {
                if ($query['times'] < $minRepeats) {
                    continue;
                }

                $group = &$groups[$key];
                $group ??= [
                    'kind' => 'identical',
                    'sql' => mb_substr(Present::pattern($query['sql'], true), 0, 300),
                    'location' => $query['location'],
                    'batches' => 0,
                    'max_repeats' => 0,
                    'avg_repeats' => 0,
                    'total_ms' => 0.0,
                    'sample_query_id' => $query['id'],
                    '_sample_batch' => $batchId,
                    '_batches' => [],
                ];

                if ($query['varies']) {
                    $group['kind'] = 'n+1';
                }

                if ($query['times'] > $group['max_repeats']) {
                    $group['max_repeats'] = $query['times'];
                    $group['_sample_batch'] = $batchId;
                    $group['sample_query_id'] = $query['id'];
                }

                $group['batches']++;
                $group['total_ms'] = round($group['total_ms'] + $query['ms'], 2);
                $group['_batches'][] = $batchId;
                $group['avg_repeats'] = round((($group['avg_repeats'] * ($group['batches'] - 1)) + $query['times']) / $group['batches'], 1);
                unset($group);
            }
        }

        return $groups;
    }
}
