<?php

namespace LloydsDigital\TelescopeMcp\Support;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Connection;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Laravel\Telescope\Storage\EntryModel;
use PDO;
use Throwable;
use WeakMap;

/**
 * All reads against Telescope's storage, through Telescope's own EntryModel.
 *
 * Only Telescope's default indexes exist (uuid, batch_id, family_hash,
 * created_at, (type, should_display_on_index), tag), so time windows are
 * turned into `sequence` (primary key) ranges via the created_at index.
 */
final class Entries
{
    /** Types whose entries are small (no payloads or bodies), read in bigger chunks. */
    public const SMALL_TYPES = ['query', 'cache', 'redis', 'model', 'view', 'event', 'gate'];

    public const TYPES = [
        'request', 'query', 'exception', 'log', 'job', 'cache', 'event', 'mail', 'model',
        'notification', 'redis', 'schedule', 'view', 'gate', 'client_request', 'command', 'dump', 'batch',
    ];

    /**
     * Telescope writes a batch's entries when the request/job finishes, so
     * created_at and sequence order differ by up to a request's duration.
     * The sequence range is widened by this much and made exact with created_at.
     */
    private const SLACK_MINUTES = 10;

    /** Tags on at most this many entries are resolved to a list of uuids. */
    private const RARE_TAG = 2000;

    /** Sequences per slice when walking a query newest-first; slices grow while matches are sparse. */
    private const FIRST_SLICE = 100_000;

    /** Seconds one slice statement should take; slices grow or shrink towards it. */
    private const SLICE_SECONDS = 0.5;

    /** Window share of the table above which counts scan the type index instead of the primary key. */
    private const INDEX_COUNT_SHARE = 0.07;

    /** @var array<string, array{0: ?int, 1: ?int}|false> */
    private array $bounds = [];

    /** @var WeakMap<Builder, array{0: ?int, 1: ?int}>|null The sequence range within() put on each query. */
    private ?WeakMap $ranges = null;

    /** @var array{0: int, 1: int}|null|false First and last sequence in the table. */
    private array|false|null $extent = null;

    /** When this tool call has to wrap up (microtime), so it never hits PHP's execution limit. */
    private float $deadline;

    public function __construct()
    {
        $this->deadline = microtime(true) + (float) config('telescope-mcp.time_budget_seconds', 15);
    }

    /**
     * Why a capped scan stopped, for coverage notes.
     *
     * @param  array<string, mixed>  $scan
     */
    public static function stoppedBecause(array $scan): string
    {
        return ($scan['stopped_by'] ?? 'limit') === 'time'
            ? 'the '.config('telescope-mcp.time_budget_seconds', 15).'s time limit (filters that scan content, like path or exclude, are slow on large windows)'
            : 'the scan limit';
    }

    /**
     * Whether the call's time budget is spent; long scans stop and report partial coverage.
     */
    public function outOfTime(): bool
    {
        return microtime(true) > $this->deadline;
    }

    public static function connection(): Connection
    {
        return (new EntryModel)->getConnection();
    }

    /**
     * @return Builder<EntryModel>
     */
    public function query(): Builder
    {
        return EntryModel::query();
    }

    /**
     * Entries of the given type(s), read through Telescope's (type,
     * should_display_on_index) index. Telescope only clears that flag on
     * older occurrences of an exception, so other types match it exactly and
     * the index range is (type, 1, sequence): MySQL left to itself walks the
     * primary key through every type, which is several times slower for
     * anything but the most common type.
     *
     * @param  string|list<string>  $types
     * @return Builder<EntryModel>
     */
    public function typed(string|array $types): Builder
    {
        $types = (array) $types;
        $query = count($types) === 1 ? $this->query()->where('type', $types[0]) : $this->query()->whereIn('type', $types);

        if (! in_array('exception', $types, true)) {
            $query->where('should_display_on_index', true);
        }

        // With several types MySQL would have to sort every match to order by
        // sequence; the primary key walk with a type filter is better there.
        if (count($types) === 1 && ($index = self::typeIndex())) {
            $query->getQuery()->forceIndex($index);
        }

        return $query;
    }

    /**
     * The name of Telescope's (type, should_display_on_index) index where an
     * index hint is supported (MySQL/MariaDB) and the index exists.
     */
    private static function typeIndex(): ?string
    {
        static $index = [];
        $connection = self::connection();
        $key = $connection->getName();

        if (array_key_exists($key, $index)) {
            return $index[$key];
        }

        if (! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true) || ! method_exists(QueryBuilder::class, 'forceIndex')) {
            return $index[$key] = null;
        }

        try {
            $found = collect($connection->getSchemaBuilder()->getIndexes('telescope_entries'))
                ->first(fn ($candidate) => array_map('strtolower', $candidate['columns']) === ['type', 'should_display_on_index']);
        } catch (Throwable) {
            $found = null;
        }

        return $index[$key] = $found['name'] ?? null;
    }

    public function find(string $uuid): ?EntryModel
    {
        return $this->query()->where('uuid', $uuid)->first();
    }

    /**
     * @return list<string>
     */
    public function tags(string $uuid): array
    {
        return self::connection()->table('telescope_entries_tags')->where('entry_uuid', $uuid)->orderBy('tag')->pluck('tag')->all();
    }

    /**
     * Oldest and newest retained entry.
     *
     * @return array{oldest: ?string, newest: ?string}|null
     */
    public function retention(): ?array
    {
        // created_at is indexed; sequence order can differ from time order by a request's duration.
        $oldest = $this->query()->orderBy('created_at')->value('created_at');

        if ($oldest === null) {
            return null;
        }

        return [
            'oldest' => Present::time($oldest),
            'newest' => Present::time($this->query()->orderByDesc('created_at')->value('created_at')),
        ];
    }

    /**
     * When the entry at (or just above) a sequence position was recorded, as UTC.
     */
    public function timeAt(int $sequence): ?string
    {
        return Present::time($this->query()->where('sequence', '>=', $sequence)->orderBy('sequence')->value('created_at'));
    }

    /**
     * Restricts the query to the window. Returns false when the window holds no entries.
     */
    public function within(Builder $query, Window $window): bool
    {
        $bounds = $this->sequenceBounds($window);

        if ($bounds === false) {
            return false;
        }

        [$low, $high] = $bounds;

        if ($window->from) {
            $query->where('sequence', '>=', $low)->where('created_at', '>=', $this->dbTime($window->from));
        }

        if ($window->to) {
            $query->where('sequence', '<=', $high)->where('created_at', '<=', $this->dbTime($window->to));
        }

        $this->ranges ??= new WeakMap;
        $this->ranges[$query] = [$low, $high];

        return true;
    }

    /**
     * Entries per type in the window, most first: one count per type over
     * its index range (index-only), instead of grouping every row's type.
     * Counts are by sequence, so entries written in the same second as a
     * window edge may fall on either side of it.
     *
     * @return array<string, int>
     */
    public function countsInWindow(Window $window): array
    {
        $low = $window->from ? $this->query()->where('created_at', '>=', $this->dbTime($window->from))->orderBy('created_at')->value('sequence') : null;
        $high = $window->to ? $this->query()->where('created_at', '<=', $this->dbTime($window->to))->orderByDesc('created_at')->value('sequence') : null;

        if (($window->from && $low === null) || ($window->to && $high === null)) {
            return [];
        }

        $extent = $this->extent();

        if ($extent === false) {
            return [];
        }

        $low ??= $extent[0];
        $high ??= $extent[1];
        $query = $this->query()->whereBetween('sequence', [$low, $high]);

        // Counting a range of the primary key reads whole rows (content and
        // all); scanning the whole (type, ...) index reads small entries but
        // all of them. The index wins once the window is more than a few
        // percent of the table.
        if (($high - $low) > self::INDEX_COUNT_SHARE * max(1, $extent[1] - $extent[0]) && ($index = self::typeIndex())) {
            $query->getQuery()->forceIndex($index);
        } else {
            // Reading the rows anyway: make the edges exact.
            $query->when($window->from, fn (Builder $q) => $q->where('created_at', '>=', $this->dbTime($window->from)))
                ->when($window->to, fn (Builder $q) => $q->where('created_at', '<=', $this->dbTime($window->to)));
        }

        return $this->countsByType($query);
    }

    /**
     * Entries carrying the tag. A rare tag (one record, a failed job) is
     * looked up through the tag index; a common one (an active user's
     * "Auth:<id>" is on every query they caused) is checked per scanned
     * entry through the (entry_uuid, tag) primary key, because MySQL would
     * otherwise materialize millions of uuids for every chunk.
     * Several tags match entries carrying any of them.
     *
     * @param  string|list<string>  $tag
     */
    public function tagged(Builder $query, string|array $tag): void
    {
        $tags = (array) $tag;
        $uuids = self::connection()->table('telescope_entries_tags')->whereIn('tag', $tags)->limit(self::RARE_TAG + 1)->distinct()->pluck('entry_uuid');

        if ($uuids->count() <= self::RARE_TAG) {
            // No match: an always-false condition, not uuid in (''), which Postgres rejects for its uuid column.
            $uuids->isEmpty() ? $query->whereRaw('1 = 0') : $query->whereIn('uuid', $uuids->all());

            // A short uuid list beats any range scan: let the uuid index drive.
            $query->getQuery()->indexHint = null;

            return;
        }

        $table = $query->getModel()->getTable();

        $query->whereExists(fn ($sub) => $sub->selectRaw('1')
            ->from('telescope_entries_tags')
            ->whereColumn('telescope_entries_tags.entry_uuid', "{$table}.uuid")
            ->whereIn('telescope_entries_tags.tag', $tags));
    }

    /**
     * Which of the given tags each entry carries.
     *
     * @param  list<string>  $uuids
     * @param  list<string>  $tags
     * @return array<string, list<string>> uuid => tags
     */
    public function tagsAmong(array $uuids, array $tags): array
    {
        $found = [];

        foreach (array_chunk($uuids, 500) as $chunk) {
            self::connection()->table('telescope_entries_tags')->whereIn('entry_uuid', $chunk)->whereIn('tag', $tags)->get(['entry_uuid', 'tag'])
                ->each(function ($row) use (&$found) {
                    $found[$row->entry_uuid][] = $row->tag;
                });
        }

        return $found;
    }

    /**
     * Whether any entry carries the tag.
     */
    public function tagExists(string $tag): bool
    {
        return self::connection()->table('telescope_entries_tags')->where('tag', $tag)->exists();
    }

    /**
     * Distinct tags starting with $prefix, across all retained data (a range scan of the tag index).
     *
     * @return list<string>
     */
    public function tagsStartingWith(string $prefix, int $limit): array
    {
        return self::connection()->table('telescope_entries_tags')
            ->whereRaw("tag like ? escape '!'", [self::escapeLike($prefix).'%'])
            ->distinct()
            ->orderBy('tag')
            ->limit($limit)
            ->pluck('tag')
            ->all();
    }

    /**
     * The request or command each batch belongs to, keyed by batch id; for a
     * scheduler run (where `schedule:run` itself isn't recorded), a task of it.
     *
     * @param  list<string>  $batchIds
     * @return array<string, EntryModel>
     */
    public function originsOf(array $batchIds): array
    {
        $origins = [];

        foreach (array_chunk(array_values(array_unique($batchIds)), 500) as $chunk) {
            $this->query()->whereIn('batch_id', $chunk)->whereIn('type', ['request', 'command', 'schedule'])->get()
                ->each(function (EntryModel $row) use (&$origins) {
                    if (! isset($origins[$row->batch_id]) || $origins[$row->batch_id]->type === 'schedule') {
                        $origins[$row->batch_id] = $row;
                    }
                });
        }

        return $origins;
    }

    /**
     * "GET OrderController@index", "artisan migrate", "scheduler run", or "queued job or other" for a batch's origin.
     */
    public static function originLabel(?EntryModel $origin): string
    {
        return match ($origin?->type) {
            'request' => Present::route(Present::content($origin)),
            'command' => 'artisan '.(Present::content($origin)['command'] ?? '?'),
            'schedule' => 'scheduler run',
            default => 'queued job or other',
        };
    }

    /**
     * Case-insensitive substring match on the raw JSON content.
     *
     * Telescope stores content with json_encode's defaults, so "/" and unicode
     * never appear unescaped: the term is matched in its JSON-escaped form, and
     * as typed only when it holds quotes or backslashes (it may be raw JSON,
     * e.g. `"status":"failed"`). Most terms cost a single LIKE per row.
     *
     * No lower() on the column, which would copy every row's content: Postgres
     * gets ILIKE, and LIKE is already case-insensitive under the default
     * collations of MySQL/MariaDB, SQL Server and SQLite (ASCII).
     */
    public function contains(Builder $query, string $term): void
    {
        $variants = self::variants($term);
        $operator = self::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

        $query->where(function (Builder $where) use ($variants, $operator) {
            foreach ($variants as $variant) {
                $where->orWhereRaw("content {$operator} ? escape '!'", ['%'.self::escapeLike($variant).'%']);
            }
        });
    }

    /**
     * Requests whose URI starts with $path. Telescope writes a request's
     * "uri" right after "ip_address", so only the start of the content is
     * matched instead of all of it (headers, payload, response): several
     * times cheaper, and an exact prefix match. Results are re-checked in PHP.
     */
    public function uriStartsWith(Builder $query, string $path): void
    {
        $driver = self::connection()->getDriverName();
        $prefix = $driver === 'sqlsrv' ? 'substring(content, 1, 400)' : 'substr(content, 1, 400)';
        $operator = $driver === 'pgsql' ? 'ilike' : 'like';
        $term = substr((string) json_encode($path, JSON_INVALID_UTF8_SUBSTITUTE), 1, -1);

        $query->whereRaw("{$prefix} {$operator} ? escape '!'", ['%"uri":"'.self::escapeLike($term).'%']);
    }

    /**
     * Excludes entries whose raw content contains the term (same matching as contains()).
     */
    public function notContains(Builder $query, string $term): void
    {
        $operator = self::connection()->getDriverName() === 'pgsql' ? 'not ilike' : 'not like';

        foreach (self::variants($term) as $variant) {
            $query->whereRaw("content {$operator} ? escape '!'", ['%'.self::escapeLike($variant).'%']);
        }
    }

    /**
     * Substring match on a JSON fragment exactly as Telescope writes it, e.g.
     * `"status":"failed"`: one LIKE, no case folding.
     */
    public function containsJson(Builder $query, string $json): void
    {
        $query->whereRaw("content like ? escape '!'", ['%'.self::escapeLike($json).'%']);
    }

    /**
     * Walks the query newest-first, applying an optional PHP-side filter,
     * until $limit matches, the scan cap or the time budget is reached.
     *
     * @param  (Closure(EntryModel): bool)|null  $filter
     * @return array{rows: list<EntryModel>, next_cursor: ?int, scanned: int, scan_capped: bool}
     */
    public function scan(Builder $query, int $limit, ?int $cursor = null, ?Closure $filter = null): array
    {
        $cap = max($limit, (int) config('telescope-mcp.scan_limit', 5000));
        $rows = [];
        $scanned = 0;
        $position = $cursor;
        $pages = $this->pages($query, $filter ? 500 : $limit, $cursor);

        foreach ($pages as [$page, $position]) {
            foreach ($page as $row) {
                $scanned++;

                if (! $filter || $filter($row)) {
                    $rows[] = $row;

                    if (count($rows) >= $limit) {
                        return ['rows' => $rows, 'next_cursor' => (int) $row->sequence, 'scanned' => $scanned, 'scan_capped' => false];
                    }
                }
            }

            if ($scanned >= $cap) {
                return ['rows' => $rows, 'next_cursor' => $position, 'scanned' => $scanned, 'scan_capped' => true];
            }
        }

        $complete = $pages->getReturn();

        // Out of time before reading anything: continue from where it would have started.
        if (! $complete && $position === null) {
            $extent = $this->extent();
            $position = $extent === false ? null : (($this->ranges[$query][1] ?? null) ?? $extent[1]) + 1;
        }

        return ['rows' => $rows, 'next_cursor' => $complete ? null : $position, 'scanned' => $scanned, 'scan_capped' => ! $complete];
    }

    /**
     * Walks the query newest-first, handing every row to $each, until $cap
     * rows have been read, the time budget is spent, or $each returns false.
     *
     * @param  Closure(EntryModel): (bool|void)  $each
     * @param  int  $chunk  Rows per statement: larger for small rows (queries, cache, redis) means fewer round trips.
     * @return array{scanned: int, capped: bool, oldest: ?string, stopped_by?: string}
     */
    public function each(Builder $query, int $cap, Closure $each, int $chunk = 1000): array
    {
        $scanned = 0;
        $oldest = null;
        $pages = $this->pages($query, min($chunk, max(1, $cap)));

        foreach ($pages as [$page]) {
            foreach ($page as $row) {
                if ($scanned >= $cap) {
                    return ['scanned' => $scanned, 'capped' => true, 'oldest' => Present::time($oldest), 'stopped_by' => 'limit'];
                }

                $scanned++;
                $oldest = $row->created_at;

                if ($each($row) === false) {
                    return ['scanned' => $scanned, 'capped' => false, 'oldest' => Present::time($oldest)];
                }
            }
        }

        $complete = $pages->getReturn();

        return ['scanned' => $scanned, 'capped' => ! $complete, 'oldest' => Present::time($oldest)] + ($complete ? [] : ['stopped_by' => 'time']);
    }

    /**
     * Pages of the query, newest first, read in sequence slices sized by
     * how long the last one took: sparse but cheap filters (a rare type, a
     * quiet user) cover ground in a few statements, expensive ones (content
     * matching) in many short ones, so the time budget is checked often.
     * A single unsliced statement over a sparse filter can run for longer
     * than PHP allows.
     *
     * Yields [rows, position]: everything from `position` up has been read.
     * Returns whether the query was read to its end.
     *
     * @return Generator<int, array{0: Collection<int, EntryModel>, 1: int}, mixed, bool>
     */
    private function pages(Builder $query, int $chunk, ?int $cursor = null): Generator
    {
        $extent = $this->extent();

        if ($extent === false) {
            return true;
        }

        [$low, $high] = $this->ranges[$query] ?? [null, null];
        $floor = max($low ?? $extent[0], $extent[0]);
        $ceiling = min($cursor ?? PHP_INT_MAX, ($high ?? $extent[1]) + 1);
        // Always a small first slice: a share of a large window as the first
        // statement can, with content matching, outlast the whole budget (and
        // the web server's proxy timeout) before the deadline is checked again.
        $span = self::FIRST_SLICE;

        while ($ceiling > $floor) {
            if ($this->outOfTime()) {
                return false;
            }

            $bottom = max($floor, $ceiling - $span);
            $started = microtime(true);
            $page = $this->beforeDeadline(fn () => (clone $query)
                ->where('sequence', '<', $ceiling)
                ->where('sequence', '>=', $bottom)
                ->orderByDesc('sequence')
                ->limit($chunk)
                ->get());

            if ($page === null) {
                return false;
            }

            $took = microtime(true) - $started;

            if ($page->count() >= $chunk) {
                $ceiling = (int) $page->last()->sequence;
            } else {
                $ceiling = $bottom;
            }

            // Cheap statements (an index range, few matches) cover more ground
            // next time; expensive ones (content matching) less.
            if ($took < self::SLICE_SECONDS / 4) {
                $span *= $page->count() >= $chunk ? 1 : 8;
            } elseif ($took > self::SLICE_SECONDS) {
                $span = max(1000, intdiv($span, 2));
            }

            yield [$page, $ceiling];
        }

        return true;
    }

    /**
     * Runs a read that the database stops at the call's deadline, where it
     * can (MySQL, MariaDB); null when it was stopped. The deadline is only
     * checked between statements otherwise, so one slow statement could run
     * long past it.
     *
     * @template T
     *
     * @param  Closure(): T  $read
     * @return T|null
     */
    private function beforeDeadline(Closure $read): mixed
    {
        $timeout = self::statementTimeout();

        if ($timeout === null) {
            return $read();
        }

        [$variable, $original] = $timeout;
        $seconds = max(0.05, $this->deadline - microtime(true));
        $connection = self::connection();
        // MySQL's max_execution_time is in milliseconds (SELECT only), MariaDB's max_statement_time in seconds.
        $connection->statement("set session {$variable} = ".($variable === 'max_execution_time' ? (int) ceil($seconds * 1000) : round($seconds, 2)));

        try {
            return $read();
        } catch (QueryException $e) {
            // 3024: MySQL, 1969: MariaDB, maximum statement execution time exceeded.
            if (in_array((int) ($e->errorInfo[1] ?? 0), [3024, 1969], true)) {
                return null;
            }

            throw $e;
        } finally {
            $connection->statement("set session {$variable} = {$original}");
        }
    }

    /**
     * The session variable that limits a statement's run time on this
     * connection and its value before we change it, or null where there is
     * none (or it can't be read).
     *
     * @return array{0: string, 1: int|float}|null
     */
    private static function statementTimeout(): ?array
    {
        $connection = self::connection();

        if (! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            return null;
        }

        try {
            $version = (string) $connection->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION);
            $variable = stripos($version, 'mariadb') !== false ? 'max_statement_time' : 'max_execution_time';
            $original = $connection->selectOne("select @@session.{$variable} as value")->value ?? 0;
        } catch (Throwable) {
            return null;
        }

        return [$variable, is_numeric($original) ? $original + 0 : 0];
    }

    /**
     * @return array{0: int, 1: int}|false
     */
    private function extent(): array|false
    {
        if ($this->extent === null) {
            $row = $this->query()->toBase()->selectRaw('min(sequence) as first, max(sequence) as last')->first();
            $this->extent = $row?->first === null ? false : [(int) $row->first, (int) $row->last];
        }

        return $this->extent;
    }

    /**
     * Tags of the newest $cap entries in a window, most used first. Counting
     * every tag of a large window joins the whole tags table, so it is
     * bounded like any other scan.
     *
     * @return array{tags: array<string, int>, scanned: int, capped: bool, oldest: ?string}
     */
    public function tagCounts(Window $window, ?string $type, ?string $prefix, int $cap): array
    {
        $query = ($type ? $this->typed($type) : $this->query())->select(['uuid', 'sequence', 'created_at']);

        if (! $this->within($query, $window)) {
            return ['tags' => [], 'scanned' => 0, 'capped' => false, 'oldest' => null];
        }
        $uuids = [];
        $scan = $this->each($query, $cap, function (EntryModel $row) use (&$uuids) {
            $uuids[] = $row->uuid;
        });

        $tags = [];

        foreach (array_chunk($uuids, 1000) as $chunk) {
            self::connection()->table('telescope_entries_tags')
                ->whereIn('entry_uuid', $chunk)
                ->when($prefix, fn ($q) => $q->whereRaw("tag like ? escape '!'", [self::escapeLike((string) $prefix).'%']))
                ->pluck('tag')
                ->each(function ($tag) use (&$tags) {
                    $tags[$tag] = ($tags[$tag] ?? 0) + 1;
                });
        }

        arsort($tags);

        return ['tags' => $tags] + $scan;
    }

    /**
     * Exceptions grouped by family (same class + location), newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function exceptionGroups(Window $window, int $limit, ?string $tag = null, ?string $search = null, ?string $exclude = null): array
    {
        $query = $this->typed('exception')->whereNotNull('family_hash');

        if (! $this->within($query, $window)) {
            return [];
        }

        if ($tag) {
            $this->tagged($query, $tag);
        }

        if ($search) {
            $this->contains($query, $search);
        }

        if ($exclude) {
            $this->notContains($query, $exclude);
        }

        $groups = $query
            ->select('family_hash')
            ->selectRaw('count(*) as occurrences, max(sequence) as last_sequence, min(created_at) as first_seen, max(created_at) as last_seen')
            ->groupBy('family_hash')
            ->orderByDesc('last_sequence')
            ->limit($limit)
            ->toBase()
            ->get();

        $latest = $this->query()->whereIn('sequence', $groups->pluck('last_sequence')->all())->get()->keyBy('sequence');

        return $groups->map(fn ($group) => [
            'family_hash' => $group->family_hash,
            'occurrences_in_window' => (int) $group->occurrences,
            'first_seen' => Present::time($group->first_seen),
            'last_seen' => Present::time($group->last_seen),
            'latest' => isset($latest[$group->last_sequence]) ? Present::row($latest[$group->last_sequence]) : null,
        ])->values()->all();
    }

    /**
     * Occurrences of an exception family across all retained data.
     *
     * @return array{occurrences: int, first_seen: ?string, last_seen: ?string}
     */
    public function family(string $familyHash): array
    {
        $stats = $this->query()
            ->where('family_hash', $familyHash)
            ->selectRaw('count(*) as occurrences, min(created_at) as first_seen, max(created_at) as last_seen')
            ->toBase()
            ->first();

        return [
            'occurrences' => (int) ($stats->occurrences ?? 0),
            'first_seen' => Present::time($stats->first_seen ?? null),
            'last_seen' => Present::time($stats->last_seen ?? null),
        ];
    }

    /**
     * @return array<string, int>
     */
    public function countsByType(Builder $query): array
    {
        return (clone $query)
            ->select('type')
            ->selectRaw('count(*) as total')
            ->groupBy('type')
            ->orderByDesc('total')
            ->toBase()
            ->pluck('total', 'type')
            ->map(fn ($total) => (int) $total)
            ->all();
    }

    /**
     * @return Builder<EntryModel>
     */
    public function batch(string $batchId, ?string $type = null): Builder
    {
        return $this->query()
            ->where('batch_id', $batchId)
            ->when($type, fn (Builder $q) => $q->where('type', $type));
    }

    /**
     * The job whose worker execution produced the given batch (Telescope links
     * them through the job's `updated_batch_id`).
     */
    public function jobExecutedIn(string $batchId): ?EntryModel
    {
        $first = $this->batch($batchId)->orderBy('sequence')->first(['sequence', 'created_at']);

        if ($first === null) {
            return null;
        }

        // Jobs run soon after they are dispatched; bounding the scan keeps a
        // batch without a recorded job (e.g. a command) from reading every job.
        $query = $this->typed('job')->where('sequence', '<', $first->sequence);
        $started = CarbonImmutable::parse(Present::time($first->created_at));

        if (! $this->within($query, new Window($started->subDay(), null))) {
            return null;
        }

        $this->containsJson($query, '"updated_batch_id":"'.$batchId.'"');

        return $query->orderByDesc('sequence')->first();
    }

    /**
     * Runs $callback in a nested transaction (a savepoint), so a failing
     * statement doesn't abort the surrounding transaction (Postgres does that).
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function isolated(Closure $callback): mixed
    {
        return self::connection()->transaction(fn () => $callback());
    }

    /**
     * Window bounds are UTC; Telescope stores created_at in the app timezone.
     */
    private function dbTime(CarbonImmutable $time): CarbonImmutable
    {
        return $time->setTimezone(Present::appTimezone());
    }

    /**
     * @return array{0: ?int, 1: ?int}|false
     */
    private function sequenceBounds(Window $window): array|false
    {
        $key = ($window->from?->getTimestamp() ?? '-').':'.($window->to?->getTimestamp() ?? '-');

        return $this->bounds[$key] ??= $this->resolveBounds($window);
    }

    /**
     * @return array{0: ?int, 1: ?int}|false
     */
    private function resolveBounds(Window $window): array|false
    {
        $low = $high = null;

        if ($window->from) {
            $low = $this->query()
                ->where('created_at', '>=', $this->dbTime($window->from->subMinutes(self::SLACK_MINUTES)))
                ->orderBy('created_at')
                ->value('sequence');

            if ($low === null) {
                return false;
            }
        }

        if ($window->to) {
            $high = $this->query()
                ->where('created_at', '<=', $this->dbTime($window->to->addMinutes(self::SLACK_MINUTES)))
                ->orderByDesc('created_at')
                ->value('sequence');

            if ($high === null || ($low !== null && $high < $low)) {
                return false;
            }
        }

        return [$low === null ? null : (int) $low, $high === null ? null : (int) $high];
    }

    /**
     * The term as Telescope's json_encode writes it, and as typed when it may be raw JSON.
     *
     * @return list<string>
     */
    private static function variants(string $term): array
    {
        $variants = [substr((string) json_encode($term, JSON_INVALID_UTF8_SUBSTITUTE), 1, -1)];

        if (strpbrk($term, '"\\') !== false) {
            $variants[] = $term;
        }

        return array_values(array_unique($variants));
    }

    private static function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }
}
