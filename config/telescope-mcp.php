<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Master switch
    |--------------------------------------------------------------------------
    |
    | Kill switch. The access key is derived from APP_KEY; show it with
    |   php artisan telescope-mcp:key
    | Clients send it as "Authorization: Bearer <key>".
    |
    */

    'enabled' => env('TELESCOPE_MCP_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Endpoint path
    |--------------------------------------------------------------------------
    */

    // Empty: "mcp/<20 hex chars derived from APP_KEY>", so the endpoint can't be
    // found by guessing. php artisan telescope-mcp:key prints the full URL.
    'path' => env('TELESCOPE_MCP_PATH'),

    /*
    |--------------------------------------------------------------------------
    | Require HTTPS
    |--------------------------------------------------------------------------
    |
    | null = required in production only. Requests forwarded by a load
    | balancer with "X-Forwarded-Proto: https" count as HTTPS when they come
    | from a private network address or a proxy trusted via TrustProxies.
    |
    */

    'require_https' => env('TELESCOPE_MCP_REQUIRE_HTTPS'),

    /*
    |--------------------------------------------------------------------------
    | Key salt
    |--------------------------------------------------------------------------
    |
    | Mixed into the key derivation. Change it to revoke a leaked key without
    | rotating APP_KEY, or set a different one per environment when
    | environments share an APP_KEY (e.g. staging restored from production).
    |
    */

    'key_salt' => env('TELESCOPE_MCP_KEY_SALT', ''),

    /*
    |--------------------------------------------------------------------------
    | Redaction
    |--------------------------------------------------------------------------
    |
    | Off by default: data is returned as Telescope stored it. Telescope masks
    | only a few fields itself (authorization header, password inputs), so
    | results include session cookies (Set-Cookie), tokens in outgoing request
    | headers and password hashes in sessions, and they reach the AI provider
    | behind the MCP client. TELESCOPE_MCP_REDACT=true replaces values under
    | secret-looking keys with "********"; add key names (case-insensitive,
    | substring match) to redact_keys.
    |
    */

    'redact' => env('TELESCOPE_MCP_REDACT', false),

    'redact_keys' => [],

    /*
    |--------------------------------------------------------------------------
    | Prompt injection guard
    |--------------------------------------------------------------------------
    |
    | Recorded data is written by users and the internet, and is read by an AI
    | agent that may be able to run commands. Hidden characters are always
    | removed; with the guard on, values that read as instructions to an AI
    | are withheld (get_entry include=["suspicious"] shows them, labelled).
    |
    */

    'injection_guard' => env('TELESCOPE_MCP_INJECTION_GUARD', true),

    // Max tool calls per minute for the whole app (0 = unlimited).
    'rate_limit' => 120,

    /*
    |--------------------------------------------------------------------------
    | Limits
    |--------------------------------------------------------------------------
    */

    // Max request body accepted by the endpoint.
    'max_body_bytes' => 65536,

    // Long strings inside entry content (response bodies, SQL, payloads...) are cut here.
    'max_string_length' => 4000,

    // Nested lists and objects inside entry content (job data, headers...) keep this many items.
    'max_array_items' => 50,

    // Cap on a single tool response, in characters. Claude Code shows MCP results up to
    // ~25k tokens by default; values are shortened further (never cut mid-JSON) to fit.
    'max_response_chars' => 60000,

    // Max rows examined when a filter has to be checked in PHP (e.g. min_ms, sort=slowest).
    'scan_limit' => 5000,

    // Max rows the stats and user_activity tools aggregate per window (newest first).
    'stats_scan_limit' => 20000,

    // Max queries the n_plus_one tool reads when not narrowed to a path or name.
    'query_scan_limit' => 50000,

    // Long scans (stats, search, n_plus_one, user_activity...) stop after about this many seconds
    // and report partial coverage, well inside PHP's max_execution_time.
    'time_budget_seconds' => 15,

];
