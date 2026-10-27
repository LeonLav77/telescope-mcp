<?php

namespace LloydsDigital\TelescopeMcp\Support;

/**
 * The access key is derived from APP_KEY (HMAC), so nothing is stored or
 * configured. HMAC is one-way: the key reveals nothing about APP_KEY.
 * Rotating APP_KEY rotates this key; so does changing the optional salt
 * (TELESCOPE_MCP_KEY_SALT), without touching APP_KEY. A per-environment salt
 * also keeps environments that share an APP_KEY from sharing a key.
 */
final class Key
{
    public const PREFIX = 'tmcp_';

    public static function expected(): ?string
    {
        $appKey = config('app.key');

        if (! is_string($appKey) || $appKey === '') {
            return null;
        }

        $salt = (string) config('telescope-mcp.key_salt', '');

        return self::PREFIX.hash_hmac('sha256', $salt === '' ? 'telescope-mcp' : "telescope-mcp:{$salt}", $appKey);
    }

    /**
     * The endpoint path: TELESCOPE_MCP_PATH when set, otherwise one derived
     * from APP_KEY ("mcp/3f9a…", 80 random bits), so a scanner can't find
     * the endpoint by trying a known path. Every rejection is already a 404
     * identical to a missing route; this removes the last way to tell that
     * the package is installed. No "telescope" in it: tools that exclude
     * telescope* paths (e.g. Debugbar) would otherwise treat it differently.
     * Shown by telescope-mcp:key.
     */
    public static function path(): ?string
    {
        $configured = trim((string) config('telescope-mcp.path'), '/');

        if ($configured !== '') {
            return $configured;
        }

        $appKey = config('app.key');

        return is_string($appKey) && $appKey !== ''
            ? 'mcp/'.substr(hash_hmac('sha256', 'telescope-mcp-path', $appKey), 0, 20)
            : null;
    }

    public static function matches(?string $key): bool
    {
        $expected = self::expected();

        return $expected !== null && $key !== null && hash_equals($expected, $key);
    }
}
