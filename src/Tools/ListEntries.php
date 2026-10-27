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
use LloydsDigital\TelescopeMcp\Support\Window;

#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
class ListEntries extends TelescopeTool
{
    /** The content field `name` matches, per type. */
    public const NAME_FIELDS = [
        'job' => 'name', 'command' => 'command', 'schedule' => 'command', 'event' => 'name', 'mail' => 'mailable',
        'notification' => 'notification', 'model' => 'model', 'view' => 'name', 'gate' => 'ability', 'cache' => 'key',
        'batch' => 'name',
    ];

    public const DURATION_TYPES = ['query', 'request', 'client_request', 'redis'];

    private const HTTP_TYPES = ['request', 'client_request'];

    protected string $name = 'list_entries';

    protected string $title = 'List Telescope entries';

    protected string $description = <<<'TEXT'
        Find specific entries of one type, newest first (or slowest first with sort="slowest"), one line each with
        ids for get_entry. To rank or count instead (slowest routes, most failing jobs, noisiest queries) use `stats`.
        Filters combine: time window (from/to or minutes), tag (see list_tags), name (job/command/event/mail class...),
        level (logs), status (jobs), min_status/max_status, method and path (HTTP), min_ms, hostname, free-text search
        and exclude. Exceptions are grouped by family (same file and line) with occurrence counts unless group=false.
        Each call works for about 15 seconds at most; `continue_with` holds the arguments for the next page, or for
        continuing a scan that stopped early (content filters like search or exclude are slow on long windows).
        TEXT;

    public function schema(JsonSchema $schema): array
    {
        return [
            'type' => $schema->string()->enum(Entries::TYPES)->description('Entry type.')->required(),
            'from' => $schema->string()->description('Window start, ISO 8601 (e.g. 2026-10-14T09:00:00Z). UTC if no offset.'),
            'to' => $schema->string()->description('Window end, ISO 8601. Defaults to now.'),
            'minutes' => $schema->integer()->min(1)->max(Window::MAX_MINUTES)->description('Window length counting back from `to` (or now). Alternative to `from`.'),
            'tag' => $schema->string()->max(255)->description('Exact Telescope tag, e.g. "Auth:5", "App\\Models\\Order:12", "slow", "failed". Tags differ per app: see list_tags.'),
            'search' => $schema->string()->max(200)->description('Case-insensitive text match on raw entry content (URL, SQL, message, payload...). Scans content: slow on long windows.'),
            'exclude' => $schema->string()->max(200)->description('Leave out entries whose content contains this text, e.g. "HealthQueueJob" or "wp-".'),
            'name' => $schema->string()->max(255)->description('Substring of the class/command/key, for: '.implode(', ', array_keys(self::NAME_FIELDS)).'. E.g. "SendInvoice", "media-library:clean".'),
            'status' => $schema->string()->enum(['pending', 'processed', 'failed', 'released'])->description('Jobs only.'),
            'level' => $schema->string()->max(100)->description('Logs only: one or more levels, comma-separated, e.g. "error,critical".'),
            'min_status' => $schema->integer()->min(100)->max(599)->description('Requests / client_requests only: minimum HTTP status, e.g. 500.'),
            'max_status' => $schema->integer()->min(100)->max(599)->description('Requests / client_requests only: maximum HTTP status, e.g. 499 with min_status=400 for client errors.'),
            'method' => $schema->string()->max(10)->description('Requests / client_requests only: HTTP method, e.g. POST.'),
            'path' => $schema->string()->max(255)->description('Requests: URI path prefix, e.g. "/api/orders". Client_requests: substring of the URL.'),
            'min_ms' => $schema->number()->description('Queries, requests, client_requests, redis only: minimum duration in ms.'),
            'hostname' => $schema->string()->max(255)->description('Only entries recorded on this server (content hostname).'),
            'sort' => $schema->string()->enum(['newest', 'slowest'])->default('newest')->description('"slowest" ranks by duration (queries, requests, client_requests, redis); it reads up to the scan limit and has no cursor.'),
            'group' => $schema->boolean()->description('Exceptions only: group by family. Default true.'),
            'limit' => $schema->integer()->min(1)->max(100)->default(25),
            'cursor' => $schema->integer()->description('next_cursor from a previous call, to get older entries.'),
        ];
    }

    protected function run(Input $input, Entries $entries): array
    {
        $type = $input->type();
        $limit = $input->int('limit', 25, 1, 100);
        $cursor = $input->int('cursor', null, 1, PHP_INT_MAX);
        $sort = $input->enum('sort', ['newest', 'slowest']) ?? 'newest';
        $filters = self::filters($input);
        $window = Window::fromInput($input);
        $continue = null;

        if ($type === 'exception' && $input->bool('group', true)) {
            $unsupported = array_diff(array_keys($filters), ['tag', 'search', 'exclude', 'hostname']);

            if ($unsupported !== []) {
                throw new InvalidInput(implode(', ', $unsupported).' do not apply to grouped exceptions.');
            }

            return array_filter([
                'type' => 'exception',
                'grouped' => true,
                'window' => $window->toArray(),
                'filters' => (object) $filters,
                'groups' => $entries->exceptionGroups($window, $limit, $filters['tag'] ?? null, $filters['search'] ?? null, $filters['exclude'] ?? null),
                'continue_with' => $continue,
                'next' => 'get_entry(latest.id) for the app frames and the request/job it happened in; group=false lists every occurrence.',
            ], fn ($value) => $value !== null);
        }

        if ($sort === 'slowest') {
            self::assertType($type, self::DURATION_TYPES, 'sort=slowest');
            $result = self::slowest($entries, $type, $window, $filters, $limit);
        } else {
            $result = self::find($entries, $type, $window, $filters, $limit, $cursor);
        }

        if ($result['next_cursor'] !== null) {
            $continue = self::arguments($input, ['cursor' => $result['next_cursor']]);
        }

        return array_filter([
            'type' => $type,
            'window' => $window->toArray(),
            'filters' => (object) $filters,
            'sort' => $sort === 'slowest' ? 'slowest first' : null,
            'count' => count($result['rows']),
            'entries' => array_map(fn ($row) => Present::row($row), $result['rows']),
            'next_cursor' => $result['next_cursor'],
            'continue_with' => $continue,
            'note' => $result['scan_capped']
                ? "Stopped after examining {$result['scanned']} entries".($sort === 'slowest' ? '; the ranking covers those only, narrow the window for a complete one.' : '; pass cursor=next_cursor to continue, or narrow the window.')
                : null,
        ], fn ($value) => $value !== null);
    }

    /**
     * @return array<string, mixed>
     */
    public static function filters(Input $input): array
    {
        return array_filter([
            'tag' => $input->string('tag', 255),
            'search' => $input->string('search', 200),
            'exclude' => $input->string('exclude', 200),
            'name' => $input->string('name', 255),
            'status' => $input->enum('status', ['pending', 'processed', 'failed', 'released']),
            'level' => $input->string('level', 100),
            'min_status' => $input->int('min_status', null, 100, 599),
            'max_status' => $input->int('max_status', null, 100, 599),
            'method' => ($method = $input->string('method', 10)) ? strtoupper($method) : null,
            'path' => $input->string('path', 255),
            'min_ms' => $input->float('min_ms', 0, 86_400_000),
            'hostname' => $input->string('hostname', 255),
        ], fn ($value) => $value !== null);
    }

    /**
     * The arguments of this call with some replaced: what to call next.
     *
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    public static function arguments(Input $input, array $changes): array
    {
        return array_filter(array_merge($input->all(), $changes), fn ($value) => $value !== null);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{rows: list<EntryModel>, next_cursor: ?int, scanned: int, scan_capped: bool}
     */
    public static function find(Entries $entries, string $type, Window $window, array $filters, int $limit, ?int $cursor = null): array
    {
        $query = $entries->typed($type);

        if (! $entries->within($query, $window)) {
            return ['rows' => [], 'next_cursor' => null, 'scanned' => 0, 'scan_capped' => false];
        }

        return $entries->scan($query, $limit, $cursor, self::apply($entries, $query, $type, $filters));
    }

    /**
     * The slowest matches among the newest scan_limit entries of the window.
     *
     * @param  array<string, mixed>  $filters
     * @return array{rows: list<EntryModel>, next_cursor: ?int, scanned: int, scan_capped: bool}
     */
    private static function slowest(Entries $entries, string $type, Window $window, array $filters, int $limit): array
    {
        $query = $entries->typed($type);

        if (! $entries->within($query, $window)) {
            return ['rows' => [], 'next_cursor' => null, 'scanned' => 0, 'scan_capped' => false];
        }

        $filter = self::apply($entries, $query, $type, $filters);
        $rows = [];

        $scan = $entries->each($query, (int) config('telescope-mcp.scan_limit', 5000), function (EntryModel $row) use (&$rows, $filter, $type, $limit) {
            if ($filter && ! $filter($row)) {
                return;
            }

            $rows[] = ['row' => $row, 'ms' => Present::duration($type, Present::content($row)) ?? 0.0];

            if (count($rows) > $limit * 4) {
                usort($rows, fn ($a, $b) => $b['ms'] <=> $a['ms']);
                $rows = array_slice($rows, 0, $limit);
            }
        });

        usort($rows, fn ($a, $b) => $b['ms'] <=> $a['ms']);

        return [
            'rows' => array_column(array_slice($rows, 0, $limit), 'row'),
            'next_cursor' => null,
            'scanned' => $scan['scanned'],
            'scan_capped' => $scan['capped'],
        ];
    }

    /**
     * Narrows the query with what SQL can check cheaply and returns the
     * PHP-side check for the rest (null when there is none).
     *
     * @param  array<string, mixed>  $filters
     * @return (\Closure(EntryModel): bool)|null
     */
    public static function apply(Entries $entries, $query, string $type, array $filters): ?\Closure
    {
        $checks = [];

        if (isset($filters['tag'])) {
            $entries->tagged($query, $filters['tag']);
        }

        if (isset($filters['search'])) {
            $entries->contains($query, $filters['search']);
        }

        if (isset($filters['exclude'])) {
            $entries->notContains($query, $filters['exclude']);
        }

        if (isset($filters['hostname'])) {
            $hostname = $filters['hostname'];
            $entries->containsJson($query, '"hostname":'.json_encode($hostname));
            $checks[] = fn (array $c) => ($c['hostname'] ?? null) === $hostname;
        }

        if (isset($filters['status'])) {
            self::assertType($type, ['job'], 'status');
            $status = $filters['status'];

            // Failed jobs carry Telescope's "failed" tag, which is indexed.
            $status === 'failed'
                ? $entries->tagged($query, 'failed')
                : $entries->containsJson($query, '"status":"'.$status.'"');

            $checks[] = fn (array $c) => strtolower((string) ($c['status'] ?? '')) === $status;
        }

        if (isset($filters['name'])) {
            self::assertType($type, array_keys(self::NAME_FIELDS), 'name');
            $field = self::NAME_FIELDS[$type];
            $name = mb_strtolower($filters['name']);

            $entries->contains($query, $filters['name']);
            $checks[] = fn (array $c) => str_contains(mb_strtolower((string) (is_scalar($c[$field] ?? null) ? $c[$field] : '')), $name);
        }

        if (isset($filters['level'])) {
            self::assertType($type, ['log'], 'level');
            $levels = array_values(array_filter(array_map(fn ($level) => strtolower(trim($level)), explode(',', $filters['level']))));

            $query->where(function ($where) use ($entries, $levels) {
                foreach ($levels as $level) {
                    $where->orWhere(fn ($or) => $entries->containsJson($or, '"level":'.json_encode($level)));
                }
            });

            $checks[] = fn (array $c) => in_array(strtolower((string) ($c['level'] ?? '')), $levels, true);
        }

        if (isset($filters['min_status']) || isset($filters['max_status'])) {
            self::assertType($type, self::HTTP_TYPES, isset($filters['min_status']) ? 'min_status' : 'max_status');
            $min = $filters['min_status'] ?? 100;
            $max = $filters['max_status'] ?? 599;

            if ($min > $max) {
                throw new InvalidInput('[min_status] must not be above [max_status].');
            }

            $query->where(function ($where) use ($entries, $min, $max) {
                foreach (range(intdiv($min, 100), intdiv($max, 100)) as $hundred) {
                    $where->orWhere(fn ($or) => $entries->containsJson($or, '"response_status":'.$hundred));
                }
            });

            $checks[] = fn (array $c) => isset($c['response_status']) && (int) $c['response_status'] >= $min && (int) $c['response_status'] <= $max;
        }

        if (isset($filters['method'])) {
            self::assertType($type, self::HTTP_TYPES, 'method');
            $method = $filters['method'];

            $entries->containsJson($query, '"method":'.json_encode($method));
            $checks[] = fn (array $c) => strtoupper((string) ($c['method'] ?? '')) === $method;
        }

        if (isset($filters['path'])) {
            self::assertType($type, self::HTTP_TYPES, 'path');
            $path = mb_strtolower($filters['path']);

            $type === 'request'
                ? $entries->uriStartsWith($query, $filters['path'])
                : $entries->contains($query, $filters['path']);
            $checks[] = $type === 'request'
                ? fn (array $c) => str_starts_with(mb_strtolower((string) ($c['uri'] ?? '')), $path)
                : fn (array $c) => str_contains(mb_strtolower((string) ($c['uri'] ?? '')), $path);
        }

        if (isset($filters['min_ms'])) {
            self::assertType($type, self::DURATION_TYPES, 'min_ms');
            $minMs = $filters['min_ms'];

            $checks[] = fn (array $c) => (Present::duration($type, $c) ?? 0) >= $minMs;
        }

        if ($checks === []) {
            return null;
        }

        return function (EntryModel $row) use ($checks) {
            $content = Present::content($row);

            foreach ($checks as $check) {
                if (! $check($content)) {
                    return false;
                }
            }

            return true;
        };
    }

    /**
     * @param  list<string>  $allowed
     */
    public static function assertType(string $type, array $allowed, string $filter): void
    {
        if (! in_array($type, $allowed, true)) {
            throw new InvalidInput("[{$filter}] only applies to: ".implode(', ', $allowed).'.');
        }
    }
}
