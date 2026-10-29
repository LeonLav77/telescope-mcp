<?php

namespace LloydsDigital\TelescopeMcp\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Str;
use Laravel\Telescope\Storage\EntryModel;
use Throwable;

/**
 * Turns Telescope entries into compact, model-friendly arrays.
 */
final class Present
{
    /**
     * Telescope stores created_at in the app timezone; everything we return is UTC.
     */
    public static function time(DateTimeInterface|string|null $createdAt): ?string
    {
        if ($createdAt === null || $createdAt === '') {
            return null;
        }

        if ($createdAt instanceof DateTimeInterface) {
            return CarbonImmutable::instance($createdAt)->utc()->toIso8601ZuluString();
        }

        try {
            return CarbonImmutable::parse($createdAt, self::appTimezone())->utc()->toIso8601ZuluString();
        } catch (Throwable) {
            return $createdAt;
        }
    }

    public static function appTimezone(): string
    {
        return config('app.timezone') ?: 'UTC';
    }

    /**
     * @return array<string, mixed>
     */
    public static function content(EntryModel $row): array
    {
        // The model decodes its JSON on every access; a row is read several times.
        static $decoded = null;
        $decoded ??= new \WeakMap;

        return $decoded[$row] ??= is_array($content = $row->content) ? $content : [];
    }

    /**
     * One line per entry for lists.
     *
     * @return array{id: string, type: string, at: ?string, summary: string, batch_id: string}
     */
    public static function row(EntryModel $row): array
    {
        return [
            'id' => $row->uuid,
            'type' => $row->type,
            'at' => self::time($row->created_at),
            'summary' => self::summary($row->type, self::content($row)),
            'batch_id' => $row->batch_id,
        ];
    }

    /**
     * The whole entry. Exception traces are reduced to the app's own frames
     * unless $fullTrace; size limits are applied when the response is encoded.
     *
     * @param  list<string>  $tags
     * @return array<string, mixed>
     */
    public static function full(EntryModel $row, array $tags, bool $fullTrace = false): array
    {
        $content = self::content($row);

        if ($row->type === 'exception') {
            $content = Trace::present($content, $fullTrace);
        }

        if ($row->type === 'job' && is_array($content['exception'] ?? null)) {
            $content['exception'] = Trace::present($content['exception'], $fullTrace);
        }

        if ($row->type === 'job' && is_array($content['data']['chained'] ?? null)) {
            $content['data']['chained'] = array_map(fn ($job) => self::serializedClass($job), $content['data']['chained']);
        }

        return array_filter([
            'id' => $row->uuid,
            'type' => $row->type,
            'at' => self::time($row->created_at),
            'url' => self::link($row->type, $row->uuid),
            'batch_id' => $row->batch_id,
            'family_hash' => $row->family_hash,
            'tags' => $tags,
            'summary' => self::summary($row->type, self::content($row)),
            'content' => Redact::content($row->type, $content),
        ], fn ($value, $key) => $key !== 'url' || $value !== null, ARRAY_FILTER_USE_BOTH);
    }

    /**
     * The entry's page in Telescope's dashboard, or null when links are off
     * or the type has no detail page (dumps).
     */
    public static function link(string $type, string $uuid): ?string
    {
        $section = self::UI_SECTIONS[$type] ?? null;
        $base = self::telescopeUrl();

        return $section && $base ? "{$base}/{$section}/{$uuid}" : null;
    }

    /**
     * Request headers browsers send on every request, which say nothing
     * about the request itself; get_entry leaves them out unless asked.
     */
    private const NOISE_HEADERS = [
        'sec-ch-ua', 'sec-ch-ua-mobile', 'sec-ch-ua-platform', 'sec-fetch-site', 'sec-fetch-mode', 'sec-fetch-user',
        'sec-fetch-dest', 'sec-gpc', 'accept-encoding', 'accept-language', 'priority', 'upgrade-insecure-requests',
        'cache-control', 'pragma', 'connection', 'keep-alive', 'dnt', 'te', 'x-forwarded-host', 'x-forwarded-server',
        'x-forwarded-port',
    ];

    /** Response headers that are the same on every response (security policy, server boilerplate). */
    private const NOISE_RESPONSE_HEADERS = [
        'date', 'connection', 'vary', 'content-security-policy', 'strict-transport-security', 'x-content-type-options',
        'x-frame-options', 'x-xss-protection', 'referrer-policy', 'permissions-policy', 'cross-origin-opener-policy',
        'cross-origin-resource-policy', 'cross-origin-embedder-policy', 'cache-control',
    ];

    /**
     * A request's content without what repeats or never matters: browser
     * boilerplate headers, and the query string when every parameter is in
     * the payload already. Returns the content and what was left out.
     *
     * @param  array<string, mixed>  $content
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    public static function compactRequest(array $content): array
    {
        $omitted = [];

        foreach (['headers' => self::NOISE_HEADERS, 'response_headers' => self::NOISE_RESPONSE_HEADERS] as $field => $noise) {
            if (! is_array($content[$field] ?? null)) {
                continue;
            }

            $kept = array_filter($content[$field], fn ($name) => ! in_array(strtolower((string) $name), $noise, true), ARRAY_FILTER_USE_KEY);

            if (($dropped = count($content[$field]) - count($kept)) > 0) {
                $content[$field] = $kept;
                $omitted[] = "{$dropped} boilerplate ".str_replace('_', ' ', $field);
            }
        }

        $uri = is_string($content['uri'] ?? null) ? $content['uri'] : '';
        $position = strpos($uri, '?');

        if ($position !== false && is_array($content['payload'] ?? null)) {
            $query = substr($uri, $position + 1);
            parse_str($query, $parameters);

            if ($parameters !== [] && array_diff_key($parameters, $content['payload']) === []) {
                $content['uri'] = substr($uri, 0, $position).'?… ('.(substr_count($query, '&') + 1).' params, see payload)';
                $omitted[] = 'the query string (its parameters are in payload)';
            }
        }

        return [$content, $omitted];
    }

    /** Telescope dashboard section per entry type. */
    private const UI_SECTIONS = [
        'request' => 'requests', 'query' => 'queries', 'exception' => 'exceptions', 'log' => 'logs', 'job' => 'jobs',
        'cache' => 'cache', 'event' => 'events', 'mail' => 'mail', 'model' => 'models', 'notification' => 'notifications',
        'redis' => 'redis', 'schedule' => 'schedule', 'view' => 'views', 'gate' => 'gates', 'client_request' => 'client-requests',
        'command' => 'commands', 'batch' => 'batches',
    ];

    private static function telescopeUrl(): ?string
    {
        $configured = config('telescope-mcp.telescope_url');

        if ($configured === false) {
            return null;
        }

        if (is_string($configured) && trim($configured) !== '') {
            return rtrim(trim($configured), '/');
        }

        $appUrl = rtrim((string) config('app.url'), '/');
        $domain = config('telescope.domain');
        $root = is_string($domain) && $domain !== ''
            ? (parse_url($appUrl, PHP_URL_SCHEME) ?: 'https').'://'.$domain
            : $appUrl;

        return $root === '' ? null : $root.'/'.trim((string) config('telescope.path', 'telescope'), '/');
    }

    /**
     * Duration in ms for types that record one.
     *
     * @param  array<string, mixed>  $c
     */
    public static function duration(string $type, array $c): ?float
    {
        $value = match ($type) {
            'query', 'redis' => $c['time'] ?? null,
            'request', 'client_request' => $c['duration'] ?? null,
            default => null,
        };

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * Whether an entry is a problem worth showing before routine entries.
     *
     * @param  array<string, mixed>  $c
     */
    public static function isAnomaly(string $type, array $c): bool
    {
        return match ($type) {
            'exception' => true,
            'request' => (int) ($c['response_status'] ?? 0) >= 500,
            'client_request' => ! isset($c['response_status']) || (int) $c['response_status'] >= 400,
            'job' => in_array($c['status'] ?? null, ['failed', 'released'], true),
            'command' => (int) ($c['exit_code'] ?? 0) !== 0,
            'log' => in_array(strtolower((string) ($c['level'] ?? '')), ['emergency', 'alert', 'critical', 'error', 'warning'], true),
            'query' => ! empty($c['slow']),
            'gate' => ($c['result'] ?? null) === 'denied',
            default => false,
        };
    }

    /**
     * Literals out, so the same statement or message with different values
     * groups together. SQL keeps double quotes: they quote identifiers there.
     */
    public static function pattern(string $text, bool $sql = false): string
    {
        if (Untrusted::reason(Untrusted::visible($text)) !== null) {
            return Untrusted::string($text);
        }

        $patterns = ['/\s+/' => ' ', "/'(?:[^'\\\\]|\\\\.)*'/" => '?'];

        if (! $sql) {
            $patterns['/"(?:[^"\\\\]|\\\\.)*"/'] = '?';
        }

        $patterns += [
            '/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i' => '?',
            '/\b[0-9a-f]{16,}\b/i' => '?',
            '/\b\d+(\.\d+)?\b/' => '?',
            '/\((\?, )+\?\)/' => '(?…)',
        ];

        return (string) preg_replace(array_keys($patterns), array_values($patterns), trim($text));
    }

    /**
     * "/orders/{id}/items" from "/orders/42/items?page=2"; segments that are
     * ids, hashes or generated file names ("file-1792182870-41.png") become
     * "{id}" (keeping a file extension), so one route groups as one.
     */
    public static function path(string $uri): string
    {
        $path = (string) (parse_url($uri, PHP_URL_PATH) ?? $uri);

        return implode('/', array_map(function (string $segment) {
            $extension = preg_match('/^(.+)(\.[a-z0-9]{2,5})$/i', $segment, $match) === 1 ? $match[2] : '';
            $name = $extension !== '' ? $match[1] : $segment;

            $generated = preg_match('/^\d+$/', $name) === 1
                || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $name) === 1
                || preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $name) === 1
                || preg_match('/^[0-9a-f]{16,}$/i', $name) === 1
                || preg_match('/\d{4,}/', $name) === 1;

            return $generated ? '{id}'.$extension : $segment;
        }, explode('/', $path)));
    }

    /**
     * "api.example.com/orders/{id}" from a full URL.
     */
    public static function url(string $url): string
    {
        return (parse_url($url, PHP_URL_HOST) ?: '').self::path($url);
    }

    /**
     * "GET OrderController@index" (or "GET /orders/{id}" without a controller) for a request.
     *
     * @param  array<string, mixed>  $c
     */
    public static function route(array $c): string
    {
        $action = is_string($c['controller_action'] ?? null) ? $c['controller_action'] : '';

        return trim(((string) ($c['method'] ?? '')).' '.($action !== '' ? class_basename($action) : self::path((string) ($c['uri'] ?? ''))));
    }

    /**
     * "/orders?… (63 params)" for URIs with long query strings; the full URI stays in get_entry.
     */
    public static function shortUri(string $uri): string
    {
        $position = strpos($uri, '?');

        if ($position === false || strlen($uri) - $position <= 80) {
            return $uri;
        }

        $query = substr($uri, $position + 1);

        return substr($uri, 0, $position).'?… ('.(substr_count($query, '&') + 1).' params)';
    }

    /**
     * @param  array<string, mixed>  $c
     */
    public static function summary(string $type, array $c, bool $withUser = true): string
    {
        $s = fn (string $key, int $limit = 160): string => self::scalar($c[$key] ?? null, $limit);
        $at = fn (): string => isset($c['file']) ? ' ('.basename((string) $c['file']).':'.$s('line').')' : '';
        $where = fn (): string => ($location = Trace::location($c)) ? " ({$location})" : '';
        $uri = fn (): string => self::shortUri(Redact::uri(self::scalar($c['uri'] ?? null, 2000)));

        $line = match ($type) {
            'request' => "{$s('method')} {$uri()} → {$s('response_status')} ({$s('duration')}ms) {$s('controller_action')}",
            'query' => "{$s('time')}ms ".self::scalar(Redact::sql((string) ($c['sql'] ?? '')), 200).$at().(! empty($c['slow']) ? ' [slow]' : ''),
            'exception' => class_basename($s('class')).': '.self::scalar(Redact::sql((string) ($c['message'] ?? '')), 200).$where()
                .(isset($c['occurrences']) ? " [occurrence {$s('occurrences')}]" : ''),
            'job' => "{$s('name')} {$s('status')} (queue: {$s('queue')}, connection: {$s('connection')})"
                .(isset($c['exception']['message']) ? ' – '.self::scalar($c['exception']['message'], 160) : ''),
            'log' => strtoupper($s('level')).': '.$s('message', 200),
            'cache' => "{$s('type')} {$s('key')}",
            'event' => $s('name').' ('.(is_array($c['listeners'] ?? null) ? count($c['listeners']) : 0).' listeners)'
                .(! empty($c['broadcast']) ? ' [broadcast]' : ''),
            'mail' => "{$s('mailable')} \"{$s('subject')}\" to ".self::addresses($c['to'] ?? null),
            'model' => "{$s('action')} {$s('model')}".(isset($c['count']) ? " (×{$s('count')})" : ''),
            'notification' => class_basename($s('notification'))." via {$s('channel')} to {$s('notifiable')}",
            'redis' => "{$s('time')}ms {$s('command')}",
            'schedule' => "{$s('command')} ({$s('expression')})",
            'view' => "{$s('name')} {$s('path')}",
            'gate' => "{$s('ability')} → {$s('result')}",
            'client_request' => "{$s('method')} {$uri()} → ".($s('response_status') ?: 'no response')
                .(isset($c['duration']) ? " ({$s('duration')}ms)" : ''),
            'command' => "{$s('command')} exit {$s('exit_code')}",
            'dump' => strip_tags($s('dump', 200)),
            'batch' => "{$s('name')} (jobs: {$s('totalJobs')}, failed: {$s('failedJobs')}, progress: {$s('progress')}%)",
            default => self::scalar($c, 160),
        };

        // Who did it, for entries recorded while a user was logged in.
        if ($withUser && in_array($type, ['request', 'exception', 'model', 'job'], true) && ($user = self::userName($c)) !== null) {
            $line .= " · {$user}";
        }

        // Checked before truncation and whitespace collapsing hide what made it suspicious
        // (a payload cut short, or "\n\nSystem:" flattened into " System:").
        foreach (array_merge([$line], array_filter($c, 'is_string')) as $source) {
            if (Untrusted::reason(Untrusted::visible($source)) !== null) {
                return Untrusted::string($source);
            }
        }

        return trim(preg_replace('/\s+/', ' ', $line));
    }

    /**
     * Short form for one-line summaries: the name, else the email, else the id.
     *
     * @param  array<string, mixed>  $c
     */
    public static function userName(array $c): ?string
    {
        $user = is_array($c['user'] ?? null) ? $c['user'] : [];

        foreach (['name', 'email', 'id'] as $field) {
            if (is_scalar($user[$field] ?? null) && trim((string) $user[$field]) !== '') {
                return trim((string) $user[$field]);
            }
        }

        return null;
    }

    /**
     * "Kashif <kashif@example.com>" for the logged-in user Telescope stored on the entry.
     *
     * @param  array<string, mixed>  $c
     */
    public static function user(array $c, bool $withId = false): ?string
    {
        $user = $c['user'] ?? null;

        if (! is_array($user) || ($user['id'] ?? null) === null) {
            return null;
        }

        $name = trim((string) ($user['name'] ?? ''));
        $email = trim((string) ($user['email'] ?? ''));
        $label = trim($name.($email !== '' ? " <{$email}>" : '')) ?: 'user '.$user['id'];

        return $withId ? "{$label} [id {$user['id']}]" : $label;
    }

    /**
     * Chained jobs are stored as serialize() output: unreadable, and often
     * many kilobytes. Their class says what will run next.
     */
    private static function serializedClass(mixed $job): mixed
    {
        if (is_string($job) && preg_match('/^O:\d+:"([^"]+)"/', $job, $match) === 1) {
            return $match[1].' (serialized, '.strlen($job).' bytes)';
        }

        return $job;
    }

    private static function scalar(mixed $value, int $limit): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        $string = is_scalar($value) ? (string) $value : (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return Str::limit($string, $limit);
    }

    private static function addresses(mixed $to): string
    {
        if (! is_array($to)) {
            return self::scalar($to, 80);
        }

        return Str::limit(implode(', ', array_map(
            fn ($address, $key) => is_string($key) ? $key : (is_scalar($address) ? (string) $address : ''),
            $to,
            array_keys($to),
        )), 120);
    }
}
