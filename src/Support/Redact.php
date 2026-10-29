<?php

namespace LloydsDigital\TelescopeMcp\Support;

/**
 * Masks secrets in what the tools return. Telescope itself only hides a few
 * fields (the authorization header, password inputs), so stored entries
 * still hold session cookies in Set-Cookie, API tokens in outgoing request
 * headers, password hashes in sessions, tokens in SQL and URLs. Whatever a
 * tool returns also goes to the AI provider behind the MCP client.
 *
 * Applied to output only (full entries, summaries): filters and stats work
 * on the stored values.
 */
final class Redact
{
    public const MASK = '********';

    /** Substrings of key names whose values are secret (case-insensitive). */
    private const KEYS = [
        'password', 'passwd', 'secret', 'token', 'api_key', 'apikey', 'api-key', 'access_key', 'private_key',
        'authorization', 'cookie', 'credential', 'signature', 'hmac', 'cvv', 'cvc', 'card_number',
    ];

    /** Columns whose literal values in SQL are masked. */
    private const SQL_COLUMNS = '\w*(?:password|token|secret|api_key|apikey|access_key|private_key)\w*';

    public static function enabled(): bool
    {
        return filter_var(config('telescope-mcp.redact', true), FILTER_VALIDATE_BOOL);
    }

    /**
     * Cookie headers carry live sessions: masked even with redaction off, unless switched off too.
     */
    public static function cookiesMasked(): bool
    {
        return filter_var(config('telescope-mcp.mask_cookies', true), FILTER_VALIDATE_BOOL);
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    public static function content(string $type, array $content): array
    {
        if (! self::enabled()) {
            return self::cookiesMasked() ? self::walk($content, []) : $content;
        }

        $content = self::walk($content, self::keys());

        if (isset($content['uri']) && is_string($content['uri'])) {
            $content['uri'] = self::uri($content['uri']);
        }

        if ($type === 'query' && isset($content['sql']) && is_string($content['sql'])) {
            $content['sql'] = self::sql($content['sql']);
        }

        if ($type === 'exception' && isset($content['message']) && is_string($content['message'])) {
            $content['message'] = self::sql($content['message']);
        }

        return $content;
    }

    /**
     * Literal values compared with secret-looking columns: `remember_token` = '…'.
     */
    public static function sql(string $sql): string
    {
        if (! self::enabled()) {
            return $sql;
        }

        return (string) preg_replace(
            '/([`"\[]?'.self::SQL_COLUMNS.'[`"\]]?\s*(?:=|!=|<>|like|in\s*\()\s*)\'(?:[^\'\\\\]|\\\\.)*\'/i',
            "\$1'".self::MASK."'",
            $sql,
        );
    }

    /**
     * Query string parameters with secret-looking names: ?token=…&signature=….
     */
    public static function uri(string $uri): string
    {
        if (! self::enabled() || ! str_contains($uri, '?')) {
            return $uri;
        }

        return (string) preg_replace_callback('/([?&])([^=&#]+)=([^&#]*)/', function ($match) {
            return self::secretKey(urldecode($match[2]), self::keys()) ? $match[1].$match[2].'='.self::MASK : $match[0];
        }, $uri);
    }

    /**
     * @param  list<string>  $keys
     */
    private static function walk(mixed $value, array $keys, ?string $key = null): mixed
    {
        if ($key !== null && strtolower($key) === 'set-cookie') {
            return self::setCookie($value);
        }

        if ($key !== null && strtolower($key) === 'cookie') {
            return $value === null || $value === '' || $value === [] ? $value : self::MASK;
        }

        if ($key !== null && self::secretKey($key, $keys)) {
            return $value === null || $value === '' || $value === [] || $value === self::MASK ? $value : self::MASK;
        }

        if (is_array($value)) {
            foreach ($value as $childKey => $child) {
                $value[$childKey] = self::walk($child, $keys, is_string($childKey) ? $childKey : $key);
            }

            return $value;
        }

        // "Bearer …" / "Basic …" credentials wherever they appear (full redaction only).
        if ($keys !== [] && is_string($value) && preg_match('/\b(bearer|basic)\s/i', $value) === 1) {
            return (string) preg_replace('/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/=-]{8,}/i', '$1 '.self::MASK, $value);
        }

        return $value;
    }

    /**
     * Keeps cookie names and attributes (useful for debugging), masks values:
     * "laravel_session=********; expires=…; path=/; httponly".
     */
    private static function setCookie(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(fn ($cookie) => self::setCookie($cookie), $value);
        }

        if (! is_string($value)) {
            return $value;
        }

        return (string) preg_replace('/(^|,\s*)([^=;,\s]+)=([^;]*)/', '$1$2='.self::MASK, $value);
    }

    /**
     * @param  list<string>  $keys
     */
    private static function secretKey(string $key, array $keys): bool
    {
        $key = strtolower($key);

        foreach ($keys as $secret) {
            if (str_contains($key, $secret)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private static function keys(): array
    {
        $extra = array_map(fn ($key) => strtolower((string) $key), (array) config('telescope-mcp.redact_keys', []));

        return array_values(array_unique(array_merge(self::KEYS, array_filter($extra))));
    }
}
