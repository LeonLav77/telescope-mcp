<?php

namespace LloydsDigital\TelescopeMcp\Tools;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use LloydsDigital\TelescopeMcp\Support\Entries;
use LloydsDigital\TelescopeMcp\Support\Input;
use LloydsDigital\TelescopeMcp\Support\Present;
use LloydsDigital\TelescopeMcp\Support\Window;
use Throwable;

#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
class Overview extends TelescopeTool
{
    private const HOST_SAMPLE = 300;

    protected string $name = 'overview';

    protected string $title = 'Telescope overview';

    protected string $description = <<<'TEXT'
        Start here. Which app and environment this is, the server's current time (UTC), how far back data is
        retained, and for a window (default: last 60 minutes): entry counts per type, exception groups, 5xx requests,
        failed jobs and slow queries, each with ids for get_entry. When the window is shorter than a day, failures of
        the last 24h are counted too. Use from/to for a specific period, e.g. "3 days ago".
        TEXT;

    public function schema(JsonSchema $schema): array
    {
        return [
            'from' => $schema->string()->description('Window start, ISO 8601. UTC if no offset.'),
            'to' => $schema->string()->description('Window end, ISO 8601. Defaults to now.'),
            'minutes' => $schema->integer()->min(1)->max(Window::MAX_MINUTES)->description('Window length counting back from `to` (or now). Default 60.'),
        ];
    }

    protected function run(Input $input, Entries $entries): array
    {
        $window = Window::fromInput($input, 60);
        $retention = $entries->retention();

        $data = [
            'app' => ['name' => config('app.name'), 'environment' => app()->environment()],
            'now' => CarbonImmutable::now('UTC')->toIso8601ZuluString(),
            'app_timezone' => Present::appTimezone(),
            'data_available' => $retention,
            'window' => $window->toArray(),
        ];

        if ($retention === null) {
            return $data + ['note' => 'Telescope has no stored entries (is it enabled and recording on this environment?).'];
        }

        if ($window->from && $window->from->toIso8601ZuluString() < $retention['oldest']) {
            $data['note'] = "The window starts before the oldest retained entry ({$retention['oldest']}); older data has been pruned.";
        }

        // Each section stands alone: one failing doesn't sink the rest.
        $section = function (callable $build) use ($entries) {
            try {
                return $entries->isolated($build);
            } catch (Throwable $e) {
                return ['error' => get_class($e).': '.$e->getMessage()];
            }
        };

        // A list cut short by the time budget must say so: an empty "failed_jobs" has to mean none, not "didn't look".
        $incomplete = [];
        $rows = function (string $name, array $result, string $instead) use (&$incomplete) {
            if ($result['scan_capped']) {
                $incomplete[$name] = "Stopped at the time limit after {$result['scanned']} entries; {$instead} continues.";
            }

            return array_map(fn ($row) => Present::row($row), $result['rows']);
        };

        // Cheap, indexed sections first; the 5xx check scans request content, so it goes last.
        $data['counts'] = $section(fn () => (object) $entries->countsInWindow($window));
        $data['exception_groups'] = $section(fn () => $entries->exceptionGroups($window, 10));
        $data['failed_jobs'] = $section(fn () => $rows('failed_jobs', ListEntries::find($entries, 'job', $window, ['tag' => 'failed'], 10), 'list_entries(type="job", status="failed")'));
        $data['slow_queries'] = $section(fn () => $rows('slow_queries', ListEntries::find($entries, 'query', $window, ['tag' => 'slow'], 10), 'list_entries(type="query", tag="slow")'));
        $data['hosts'] = $section(fn () => $this->hosts($entries, $window));

        if ($window->hours() < 24 && $window->to === null) {
            $data['last_24h'] = $section(fn () => $this->lastDay($entries));
        }

        $data['server_errors'] = $section(fn () => $rows('server_errors', ListEntries::find($entries, 'request', $window, ['min_status' => 500], 10), 'list_entries(type="request", min_status=500)'));

        if ($incomplete !== []) {
            $data['incomplete'] = $incomplete;
        }

        $data['next'] = 'get_entry(id) opens an entry with everything related to it; stats(type) ranks routes/jobs/queries by count, errors and duration; list_entries(type, ...) finds specific entries.';

        return $data;
    }

    /**
     * Servers that recorded the newest entries of the window, so data from
     * several hosts (or copied from another environment) is noticed.
     *
     * @return array<string, int>
     */
    private function hosts(Entries $entries, Window $window): array
    {
        $query = $entries->typed(['request', 'command', 'job', 'exception']);

        if (! $entries->within($query, $window)) {
            return [];
        }

        return $query->orderByDesc('sequence')->limit(self::HOST_SAMPLE)->get()
            ->map(fn ($row) => Present::content($row)['hostname'] ?? null)
            ->filter()
            ->countBy()
            ->sortDesc()
            ->all();
    }

    /**
     * @return array<string, int>
     */
    private function lastDay(Entries $entries): array
    {
        $day = new Window(CarbonImmutable::now('UTC')->subDay(), null);
        $count = function (string $type, ?string $tag = null) use ($entries, $day) {
            $query = $entries->typed($type);

            if (! $entries->within($query, $day)) {
                return 0;
            }

            if ($tag) {
                $entries->tagged($query, $tag);
            }

            return $query->count();
        };

        return [
            'exceptions' => $count('exception'),
            'failed_jobs' => $count('job', 'failed'),
        ];
    }
}
