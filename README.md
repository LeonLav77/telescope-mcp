# lloyds-digital/telescope-mcp

Minimal, read-only, key-protected [MCP](https://modelcontextprotocol.io) server that lets Claude (or any MCP client) read a Laravel app's **Telescope** data and follow it from a request into its queries, exceptions, logs and dispatched jobs, then into each job's own execution.

- **Installs with `composer require`, nothing else.** No env vars, no migrations, no published files, no config changes in the app.
- Built on [`laravel/mcp`](https://github.com/laravel/mcp) v1. PHP 8.2+, Laravel 11+, Telescope 5.

## Tools

| Tool | What it does |
|---|---|
| `overview` | Start here. App name and environment, server time (UTC), oldest/newest retained entry, and for a window (default last 60 min): counts per type, recording hosts, exception groups, 5xx requests, failed jobs, slow queries, each with ids. Short windows also get a count of the last 24h's exceptions and failed jobs. |
| `stats` | Aggregates one type over a window (default 24h): requests by route/controller, jobs by class (with status counts), queries by normalized SQL, commands and scheduled tasks by name, exceptions by family, logs by level + message, and more. Count, errors, p50/p95/max/total ms, a sample id. `compare_previous` adds the window before it and flags new groups. |
| `list_entries` | Specific entries of one type, newest first or `sort=slowest`, one line each. Filters: `from`/`to`/`minutes`, `tag`, `name` (job/command/event/mail class…), `level` (logs), `status` (jobs), `min_status`/`max_status`, `method`, `path`, `min_ms`, `hostname`, `search`, `exclude`. Exceptions are grouped by family (same file and line) with occurrence counts (`group=false` for every occurrence). Paging with `cursor`; `continue_with` holds the arguments for the next call. |
| `search` | Text search (order number, email, URL, class, error message…) across every type except the bulky `query`, `view`, `redis` and `cache` entries (`all_types=true` or `type` to include them). Newest first; about 15 s of work per call, then `continue_with` goes further back. |
| `get_entry` | Full entry plus what it connects to. Exceptions and failed jobs show `app_frames` (the app's own stack frames; the full trace with `include=["trace"]`). The batch digest has the origin (request, command, executed job or scheduler run), a time breakdown (total vs queries, redis and outgoing HTTP, plus unaccounted time), slowest and duplicate (N+1) queries, failed scheduled tasks with their runtime, and other entries with problems first and repeats collapsed. For jobs, also the batch the job ran in. `batch="brief"` or `"none"` when opening several entries of one batch. |
| `get_batch` | Everything from one request / job run / command / scheduler run, in order, optionally one type only. Paging with `cursor`. |
| `user_activity` | What one logged-in user did, found by id, email or name: their actions (writes, failed requests and requests that changed data, each with the records changed and fields set), all records they changed, requests per hour (sessions and polling), routes with timings, IPs, user agents, exceptions they hit, jobs they triggered. |
| `record_history` | One Eloquent record's history (`model` + `key`), oldest first: created/updated/deleted with the fields set, who did it, and through which request, command or job; plus jobs, mail and notifications that carried it. |
| `n_plus_one` | N+1 queries across the app: the same query repeated in one request or job, grouped by query and code location, with how many requests/jobs did it, the most repeats, time spent and the routes affected. `kind` is `n+1` (different values: eager load) or `identical` (same values: reuse or cache). Narrow with `path` or `name` (controller or job class) for a thorough check. |
| `list_tags` | Tags in use (Telescope's `Auth:<id>`, `App\Models\Order:<key>`, `failed`, `slow`, plus the app's own), summarized by prefix, so the `tag` filter can be used without guessing. |

Prompts (shown by clients that support MCP prompts): `investigate_error`, `investigate_user`, `why_slow`, `triage`.

`stats` can also group by `user`, `ip`, `status` or `hostname` (`group_by`), e.g. who is active or which IPs get 4xx responses, and takes the `list_entries` filters. Summary lines name the logged-in user (`· Kashif`) where Telescope recorded one.

Every call has a time budget (`time_budget_seconds`, default 15): long scans stop and say how far they got instead of hitting PHP's execution limit.

### Response size

Results are compact JSON fitted into `max_response_chars` (default 60,000 characters, which is within Claude Code's default ~25k-token limit for MCP output). Long strings and nested lists are shortened step by step and marked; the JSON is never cut mid-way, and top-level lists (a page of entries) always keep every item so cursors stay correct.

How the drill-down works: Telescope gives every entry recorded during one request (or job run, or command) the same `batch_id`. A job entry is recorded in its *dispatcher's* batch, and Telescope stores the worker's batch in the job's `updated_batch_id`. So the path request → queries → job → that job's queries and exceptions is fully navigable.

## Install (per project)

```bash
composer require lloyds-digital/telescope-mcp
```

Deploy, and the endpoint is live at `https://your-app.com/mcp/<20 hex chars>`: the path is derived from `APP_KEY` so it can't be guessed (set `TELESCOPE_MCP_PATH` to choose your own). To get the URL and the access key, run this wherever the app's production `APP_KEY` is set (e.g. on the server):

```bash
php artisan telescope-mcp:key
```

It prints the key and a ready-to-paste `claude mcp add` command. It writes nothing: the key is derived from `APP_KEY` (HMAC-SHA256), so nothing is stored and every instance behind a load balancer agrees on it. The key can't be used to recover `APP_KEY`, but anyone who has `APP_KEY` can derive it, so it is exactly as secret as `APP_KEY`. Rotating `APP_KEY` rotates the key; to revoke a leaked key without that, or to give environments that share an `APP_KEY` (e.g. a staging copy of production) different keys, set `TELESCOPE_MCP_KEY_SALT`.

The package is private. Add the Git repository to each project's `composer.json` before requiring it:

```json
"repositories": [
    { "type": "vcs", "url": "git@github.com:lloyds-digital/telescope-mcp.git" }
]
```

## Connect Claude Code

```bash
claude mcp add --transport http telescope-myapp https://your-app.com/mcp/3f9a… --header "Authorization: Bearer tmcp_..."
```

Or commit a project-scoped `.mcp.json` and keep the key in your shell environment:

```json
{
  "mcpServers": {
    "telescope": {
      "type": "http",
      "url": "https://your-app.com/mcp/3f9a…",
      "headers": { "Authorization": "Bearer ${TELESCOPE_MCP_KEY_MYAPP}" }
    }
  }
}
```

## Security

| Concern | How it's handled |
|---|---|
| Access | One 256-bit key per app, derived from `APP_KEY` (and an optional salt) with HMAC-SHA256 (one-way); constant-time comparison. Only the `Authorization: Bearer` header is accepted. Nothing is stored. |
| Fail closed | No `APP_KEY` (or `TELESCOPE_MCP_ENABLED=false`) means no route. Checked again on every request, so it also holds with `route:cache`. |
| Discovery | The endpoint path is derived from `APP_KEY` (`mcp/` plus 80 random bits; no "telescope" in it, so tools that exclude `telescope*` paths, like Debugbar, treat it like any other URL), so it can't be found by guessing. Every rejection (no key, wrong key, `Origin` header, plain HTTP) is the router's own 404 (same exception and message, so custom 404 pages render it identically) for every HTTP method (GET, HEAD, POST, PUT, PATCH, DELETE, OPTIONS), identical to a missing route, so probing reveals nothing either. After rotating `APP_KEY`, rebuild the route cache: the path moves with it. |
| Transport | HTTPS required in production. Behind a load balancer that terminates TLS (e.g. AWS ALB), `X-Forwarded-Proto: https` is accepted from private network addresses, or from any proxy the app trusts via Laravel's `TrustProxies`; a client can't fake it from the internet. |
| Browsers | Requests with an `Origin` header are rejected (CSRF / DNS rebinding). No session or cookie middleware. The path sits outside `api/*`, so the app's CORS config never applies. |
| Writes | Tools only read, through Telescope's own `EntryModel`. Every tool call also runs in a transaction that is always rolled back, so nothing a call does can persist. Plain Laravel, no driver-specific code: works on any database Telescope supports. |
| Load | 120 tool calls per minute per app (`rate_limit`, after authentication), capped limits, capped scans (`scan_limit`, `stats_scan_limit`, `query_scan_limit`), a per-call time budget (15 s), indexed time windows, 64 KB request bodies, 60k-char responses. |
| Injection | Strict input validation: type enum, UUID format, integer ranges, ISO dates. All SQL is parameterised; `LIKE` wildcards are escaped. |
| Self-recording | MCP requests are never recorded by Telescope, so the key never lands in stored headers and the tool's own reads don't pollute the data. |
| Secrets in data | Returned as Telescope stored them. Telescope itself hides only the `authorization` header and password inputs, so results include session cookies (`Set-Cookie`), tokens in outgoing request headers and password hashes in sessions, and they reach the AI provider behind the MCP client. Fine when only trusted people hold the key; `TELESCOPE_MCP_REDACT=true` masks values under secret-looking keys (`*token*`, `*secret*`, `*password*`, `*cookie*`, `*api_key*`, `authorization`, `*signature*`, `*hmac*`, card fields…), `Bearer`/`Basic` credentials, secret query parameters and SQL literals compared with token/password columns as `********`; add key names with `redact_keys`. |
| Prompt injection | Recorded data is largely written by users and anyone on the internet (URLs, payloads, user agents, messages, job data) and is read by an AI agent that may be able to run commands. Every result is cleaned before it is returned: characters that hide text from people (Unicode tag characters, which are decoded to visible text, zero-width and bidirectional controls, ANSI escapes, control characters) are removed; any value or key that reads as instructions to an AI (overriding instructions, role changes, fake `System:`/`Assistant:` turns, chat-template tokens, addressing Claude/the assistant with orders, "don't tell the user", "run the following command", `curl … \| sh`, markdown-image exfiltration links) is replaced with `[withheld: …]`, also inside summaries, stats labels and error messages; and each result starts with a notice that its contents are data, not instructions, on top of the server instructions saying the same. `get_entry(include=["suspicious"])` shows withheld values wrapped in an untrusted-data label. Detection is heuristic and tuned against real Telescope data; agents with shell or write access should still run with their normal permission prompts. `TELESCOPE_MCP_INJECTION_GUARD=false` turns off withholding (hidden characters are always removed). |

Data is returned as stored (apart from the prompt-injection guard and optional redaction), and errors include full details (class, message, file:line, trace), since the endpoint is only reachable with the key. Treat the key like read access to the database.

## Retention and time windows

The server only sees what Telescope has kept (`telescope:prune --hours=…`). `overview` always reports the oldest and newest retained entry, so "errors from 3 days ago" works on a 120h project and gets a clear "older data has been pruned" note on a 24h one.

Only Telescope's default indexes are used:

- Time windows become primary-key (`sequence`) ranges through the `created_at` index.
- Type-filtered reads go through the `(type, should_display_on_index)` index (forced on MySQL/MariaDB, where the optimizer otherwise walks the primary key through every type).
- Scans run newest-first in sequence slices sized by how long the previous statement took, so sparse filters cover ground in few statements and expensive ones (content matching) stop in time.
- Counts per type use a primary-key range for short windows and the type index for long ones.
- Tag filters use the tag index for rare tags (one record) and a per-entry primary-key check for common ones (an active user's `Auth:<id>`).
- A request's `path` is matched at the start of its stored content, where Telescope writes the URI.
- Exception grouping uses `family_hash`, drill-downs `batch_id`/`uuid`.

Free-text matching (`search`, `exclude`, status filters) is the only full content scan; it is bounded by the per-call time budget and continues on the next call.

All times in and out are UTC (Telescope stores them in the app timezone; conversion is handled).

## Optional env

| Variable | Default | |
|---|---|---|
| `TELESCOPE_MCP_ENABLED` | `true` | Kill switch |
| `TELESCOPE_MCP_PATH` | derived from `APP_KEY` | Endpoint path |
| `TELESCOPE_MCP_REQUIRE_HTTPS` | production only | Force on/off |
| `TELESCOPE_MCP_KEY_SALT` | empty | Change to rotate the key without rotating `APP_KEY`; set per environment |
| `TELESCOPE_MCP_REDACT` | `false` | Mask secret-looking values in results |
| `TELESCOPE_MCP_INJECTION_GUARD` | `true` | Withhold values that read as instructions to an AI |

Data is read from Telescope's own storage connection.

## Development

```bash
composer install
php vendor/bin/pest                      # SQLite
TEST_DB=mysql php vendor/bin/pest        # MySQL on 127.0.0.1:3307 (see tests/TestCase.php)
TEST_DB=pgsql php vendor/bin/pest        # Postgres on 127.0.0.1:5433
```
