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
use LloydsDigital\TelescopeMcp\Support\Redact;
use LloydsDigital\TelescopeMcp\Support\Window;

#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
class UserActivity extends TelescopeTool
{
    private const CANDIDATES = 5;

    private const WRITES = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /** @var array<string, string> Sections that stopped early, and how to get the rest. */
    private array $incomplete = [];

    protected string $name = 'user_activity';

    protected string $title = 'User activity';

    protected string $description = <<<'TEXT'
        What one logged-in user did over a window (default: last 24h). `actions`: their writes, failed requests and
        any request that changed data, newest first, each with the records it created/updated/deleted and the fields
        set. `changes`: all records they changed, by model. `activity`: requests per hour with the main routes (shows
        sessions and polling). Also request totals, IPs, user agents, routes with timings, exceptions they hit and
        jobs they triggered. `user` is the id, email, or part of a name (several matches return candidates).
        Telescope only knows a user for entries recorded while they were logged in; queued jobs run without one.
        To see who was active at all: stats(type="request", group_by="user").
        TEXT;

    public function schema(JsonSchema $schema): array
    {
        return [
            'user' => $schema->string()->max(255)->description('User id, email, or (part of) a name.')->required(),
            'from' => $schema->string()->description('Window start, ISO 8601. UTC if no offset.'),
            'to' => $schema->string()->description('Window end, ISO 8601. Defaults to now.'),
            'minutes' => $schema->integer()->min(1)->max(Window::MAX_MINUTES)->description('Window length counting back from `to` (or now). Default 1440 (24h).'),
            'limit' => $schema->integer()->min(1)->max(100)->default(30)->description('Actions to list.'),
        ];
    }

    protected function run(Input $input, Entries $entries): array
    {
        $term = $input->string('user', 255) ?? throw new InvalidInput('[user] is required.');
        $window = Window::fromInput($input, 1440);
        $limit = $input->int('limit', 30, 1, 100);

        $user = $this->resolve($entries, $term, $window);

        if (isset($user['candidates'])) {
            return $user + ['window' => $window->toArray()];
        }

        // Small sections first: the request scan is the big one and may run out of time.
        $this->incomplete = [];
        $tag = 'Auth:'.$user['id'];
        $changes = $this->changes($entries, $tag, $window);
        $exceptions = $this->exceptions($entries, $tag, $window);
        $jobs = $this->jobs($entries, $tag, $window);
        $requests = $this->requests($entries, $tag, $window, $changes['byBatch'], $limit);

        return array_filter([
            'user' => $user,
            'window' => $window->toArray(),
            'requests' => $requests['summary'],
            'actions' => $requests['actions'],
            'changes' => $changes['summary'],
            'exceptions' => $exceptions,
            'jobs' => $jobs,
            'activity' => $requests['hours'],
            'routes' => $requests['routes'],
            'coverage' => $requests['coverage'],
            'incomplete' => $this->incomplete,
            'next' => 'get_entry(id) on an action for the full request and everything it did; record_history(model, key) for one record; list_entries(type, tag="'.$tag.'") for anything else they caused.',
        ], fn ($value) => $value !== null && $value !== []);
    }

    /**
     * The user as Telescope stored them: by id (their "Auth:<id>" tag), else
     * by email or name from requests in the window.
     *
     * @return array<string, mixed>
     */
    private function resolve(Entries $entries, string $term, Window $window): array
    {
        if ($entries->tagExists('Auth:'.$term)) {
            $query = $entries->typed('request');
            $entries->tagged($query, 'Auth:'.$term);
            $latest = $query->orderByDesc('sequence')->first();

            return ['id' => $term] + ($latest ? $this->profile(Present::content($latest)) : []);
        }

        $query = $entries->typed('request');

        if (! $entries->within($query, $window)) {
            throw new InvalidInput("No requests in the window, so no user matching \"{$term}\" can be found. Widen the window or pass the user id.");
        }

        $entries->contains($query, $term);
        $needle = mb_strtolower($term);
        $found = [];

        // Text matches can be any field of a request; a few hundred settle who is meant.
        $entries->each($query, 500, function (EntryModel $row) use (&$found, $needle) {
            $user = Present::content($row)['user'] ?? null;

            if (! is_array($user) || ! isset($user['id']) || isset($found[$user['id']])) {
                return count($found) < self::CANDIDATES;
            }

            $email = mb_strtolower((string) ($user['email'] ?? ''));
            $name = mb_strtolower((string) ($user['name'] ?? ''));

            if ($email === $needle || (string) $user['id'] === $needle) {
                $found = [$user['id'] => ['exact' => true] + $this->profile(['user' => $user])];

                return false;
            }

            if (str_contains($name, $needle) || str_contains($email, $needle)) {
                $found[$user['id']] = ['exact' => false] + $this->profile(['user' => $user]);
            }

            return count($found) < self::CANDIDATES;
        });

        if ($found === []) {
            throw new InvalidInput("No logged-in user matching \"{$term}\" made a request in the window. Widen the window, or pass the user id (stats(type=\"request\", group_by=\"user\") lists who was active).");
        }

        if (count($found) > 1) {
            return [
                'candidates' => array_values(array_map(fn ($user, $id) => ['id' => (string) $id] + array_diff_key($user, ['exact' => true]), $found, array_keys($found))),
                'next' => 'Call again with user set to one of these ids.',
            ];
        }

        $id = (string) array_key_first($found);

        return ['id' => $id] + array_diff_key($found[$id], ['exact' => true]);
    }

    /**
     * @param  array<string, mixed>  $c
     * @return array<string, string>
     */
    private function profile(array $c): array
    {
        return array_filter([
            'name' => $c['user']['name'] ?? null,
            'email' => $c['user']['email'] ?? null,
        ], fn ($value) => is_string($value) && $value !== '');
    }

    /**
     * Records the user created, updated or deleted (Telescope's model watcher).
     *
     * @return array{summary: list<array<string, mixed>>, byBatch: array<string, list<string>>}
     */
    private function changes(Entries $entries, string $tag, Window $window): array
    {
        $query = $entries->typed('model');

        if (! $entries->within($query, $window)) {
            return ['summary' => [], 'byBatch' => []];
        }

        $entries->tagged($query, $tag);
        $entries->notContains($query, '"action":"retrieved"');
        $groups = [];
        $byBatch = [];

        $scan = $entries->each($query, (int) config('telescope-mcp.scan_limit', 5000), function (EntryModel $row) use (&$groups, &$byBatch) {
            $c = Present::content($row);
            [$class, $key] = array_pad(explode(':', (string) ($c['model'] ?? '?'), 2), 2, null);
            $action = (string) ($c['action'] ?? '?');
            $fields = array_values(array_diff(is_array($c['changes'] ?? null) ? array_keys($c['changes']) : [], ['updated_at', 'created_at']));
            $group = "{$action} {$class}";

            $groups[$group]['change'] = $group;
            $groups[$group]['times'] = ($groups[$group]['times'] ?? 0) + 1;
            $groups[$group]['sample_id'] ??= $row->uuid;

            if ($key !== null && (isset($groups[$group]['records'][$key]) || count($groups[$group]['records'] ?? []) < 5)) {
                $groups[$group]['records'][$key] = ($groups[$group]['records'][$key] ?? 0) + 1;
            }

            foreach ($fields as $field) {
                $groups[$group]['fields'][$field] = true;
            }

            $line = "{$action} ".class_basename((string) $class).($key !== null ? ":{$key}" : '').($fields ? ' ['.implode(', ', array_slice($fields, 0, 6)).']' : '');

            if (isset($byBatch[$row->batch_id][$line]) || count($byBatch[$row->batch_id] ?? []) < 8) {
                $byBatch[$row->batch_id][$line] = ($byBatch[$row->batch_id][$line] ?? 0) + 1;
            }
        });

        $this->incomplete('changes', $scan, "list_entries(type=\"model\", tag=\"{$tag}\")");

        // Oldest first within a request, repeats counted: "updated Media:5 [responsive_images] ×3".
        $byBatch = array_map(fn ($lines) => array_reverse(array_map(
            fn ($line, $times) => $times > 1 ? "{$line} ×{$times}" : $line,
            array_keys($lines),
            $lines,
        )), $byBatch);

        usort($groups, fn ($a, $b) => $b['times'] <=> $a['times']);

        $summary = array_map(fn ($group) => array_filter([
            'change' => $group['change'],
            'times' => $group['times'],
            'records' => $group['records'] ?? null,
            'fields' => isset($group['fields']) ? array_slice(array_keys($group['fields']), 0, 12) : null,
            'sample_id' => $group['sample_id'],
        ], fn ($value) => $value !== null), array_slice($groups, 0, 15));

        return ['summary' => $summary, 'byBatch' => $byBatch];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function exceptions(Entries $entries, string $tag, Window $window): array
    {
        $result = ListEntries::find($entries, 'exception', $window, ['tag' => $tag], 10);
        $this->incomplete('exceptions', ['capped' => $result['scan_capped'], 'scanned' => $result['scanned'], 'stopped_by' => 'time'], "list_entries(type=\"exception\", tag=\"{$tag}\", group=false)");

        return array_map(function ($row) {
            $item = Present::row($row);
            unset($item['type']);

            return $item;
        }, $result['rows']);
    }

    /**
     * Notes a section that stopped early, so its list isn't read as complete.
     *
     * @param  array<string, mixed>  $scan
     */
    private function incomplete(string $section, array $scan, string $instead): void
    {
        if ($scan['capped'] ?? false) {
            $this->incomplete[$section] = "Stopped after {$scan['scanned']} entries (".Entries::stoppedBecause($scan)."); {$instead} lists them all.";
        }
    }

    /**
     * Jobs dispatched while the user was logged in, by class.
     *
     * @return list<array<string, mixed>>
     */
    private function jobs(Entries $entries, string $tag, Window $window): array
    {
        $query = $entries->typed('job');

        if (! $entries->within($query, $window)) {
            return [];
        }

        $entries->tagged($query, $tag);
        $jobs = [];

        $scan = $entries->each($query, 2000, function (EntryModel $row) use (&$jobs) {
            $c = Present::content($row);
            $name = (string) ($c['name'] ?? '?');
            $jobs[$name]['job'] = $name;
            $jobs[$name]['count'] = ($jobs[$name]['count'] ?? 0) + 1;

            if (($c['status'] ?? null) === 'failed') {
                $jobs[$name]['failed'] = ($jobs[$name]['failed'] ?? 0) + 1;
                $jobs[$name]['failed_id'] ??= $row->uuid;
            }
        });

        $this->incomplete('jobs', $scan, "stats(type=\"job\", tag=\"{$tag}\")");
        usort($jobs, fn ($a, $b) => $b['count'] <=> $a['count']);

        return array_slice($jobs, 0, 10);
    }

    /**
     * Their requests: totals, routes, per-hour activity, and the actions
     * (writes, errors, requests that changed data), newest first.
     *
     * @param  array<string, list<string>>  $changes
     * @return array<string, mixed>
     */
    private function requests(Entries $entries, string $tag, Window $window, array $changes, int $limit): array
    {
        $query = $entries->typed('request');
        $summary = ['count' => 0, 'writes' => 0, 'server_errors' => 0, 'client_errors' => 0];
        $routes = [];
        $hours = [];
        $ips = [];
        $agents = [];
        $actions = [];
        $first = $last = null;

        if (! $entries->within($query, $window)) {
            return ['summary' => $summary, 'actions' => [], 'hours' => [], 'routes' => [], 'coverage' => null];
        }

        $entries->tagged($query, $tag);

        $scan = $entries->each($query, (int) config('telescope-mcp.stats_scan_limit', 20000), function (EntryModel $row) use (&$summary, &$routes, &$hours, &$ips, &$agents, &$actions, &$first, &$last, $changes, $limit) {
            $c = Present::content($row);
            $status = (int) ($c['response_status'] ?? 0);
            $method = strtoupper((string) ($c['method'] ?? ''));
            $route = Present::route($c);
            $write = in_array($method, self::WRITES, true);
            $at = Present::time($row->created_at);
            $hour = substr((string) $at, 0, 13).':00Z';

            $summary['count']++;
            $summary['writes'] += $write ? 1 : 0;
            $summary['server_errors'] += $status >= 500 ? 1 : 0;
            $summary['client_errors'] += $status >= 400 && $status < 500 ? 1 : 0;
            $last ??= $at;
            $first = $at;

            $routes[$route]['count'] = ($routes[$route]['count'] ?? 0) + 1;
            $routes[$route]['errors'] = ($routes[$route]['errors'] ?? 0) + ($status >= 400 ? 1 : 0);
            $routes[$route]['ms'][] = (float) ($c['duration'] ?? 0);

            $hours[$hour]['requests'] = ($hours[$hour]['requests'] ?? 0) + 1;
            $hours[$hour]['writes'] = ($hours[$hour]['writes'] ?? 0) + ($write ? 1 : 0);
            $hours[$hour]['errors'] = ($hours[$hour]['errors'] ?? 0) + ($status >= 400 ? 1 : 0);
            $hours[$hour]['routes'][$route] = ($hours[$hour]['routes'][$route] ?? 0) + 1;

            if (isset($c['ip_address'])) {
                $ips[$c['ip_address']] = ($ips[$c['ip_address']] ?? 0) + 1;
            }

            if (is_string($agent = $c['headers']['user-agent'] ?? null)) {
                $agents[$agent] = ($agents[$agent] ?? 0) + 1;
            }

            $changed = $changes[$row->batch_id] ?? [];

            if (($write || $status >= 400 || $changed !== []) && count($actions) < $limit) {
                $actions[] = array_filter([
                    'at' => $at,
                    'request' => "{$method} ".Present::shortUri(Redact::uri((string) ($c['uri'] ?? ''))).' → '.($status ?: '?').' ('.($c['duration'] ?? '?').'ms)'
                        .(isset($c['controller_action']) ? ' '.class_basename((string) $c['controller_action']) : ''),
                    'changed' => $changed,
                    'id' => $row->uuid,
                ], fn ($value) => $value !== []);
            }
        });

        arsort($ips);
        arsort($agents);
        uasort($routes, fn ($a, $b) => $b['count'] <=> $a['count']);

        $summary += array_filter(['first' => $first, 'last' => $last, 'ips' => array_slice($ips, 0, 5, true), 'user_agents' => array_slice($agents, 0, 3, true)]);

        return [
            'summary' => $summary,
            'actions' => $actions,
            'hours' => array_values(array_map(function ($hour, $stats) {
                arsort($stats['routes']);

                return array_filter([
                    'hour' => $hour,
                    'requests' => $stats['requests'],
                    'writes' => $stats['writes'] ?: null,
                    'errors' => $stats['errors'] ?: null,
                    'top' => implode(', ', array_map(fn ($route, $count) => "{$route} ×{$count}", array_keys(array_slice($stats['routes'], 0, 3, true)), array_slice($stats['routes'], 0, 3, true))),
                ], fn ($value) => $value !== null);
            }, array_keys(array_slice($hours, 0, 48, true)), array_slice($hours, 0, 48, true))),
            'routes' => array_values(array_map(function ($route, $stats) {
                sort($stats['ms']);

                return array_filter([
                    'route' => $route,
                    'count' => $stats['count'],
                    'errors' => $stats['errors'] ?: null,
                    'p95_ms' => $stats['ms'][(int) max(0, ceil(0.95 * count($stats['ms'])) - 1)],
                ], fn ($value) => $value !== null);
            }, array_keys(array_slice($routes, 0, 15, true)), array_slice($routes, 0, 15, true))),
            'coverage' => $scan['capped']
                ? "Read the user's newest {$scan['scanned']} requests (back to {$scan['oldest']}), stopped by ".Entries::stoppedBecause($scan).'; narrow the window for older activity.'
                : null,
        ];
    }
}
