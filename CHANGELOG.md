# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- `record_history` finds records by a business attribute (`attribute` + `value`, e.g. an order number) for
  models and attributes listed in the new `record_lookup` config; several matches give one merged history.
- `record_history` items carry `via_id` (the request, command or job) and a `url`.
- `get_entry` returns a `url` to the entry in Telescope's dashboard (`TELESCOPE_MCP_TELESCOPE_URL`).
- `stats` request groups carry `median_gap_s`, which shows polling.

### Changed

- `Cookie` and `Set-Cookie` header values are masked by default, also with redaction off
  (`TELESCOPE_MCP_MASK_COOKIES=false` to return them as stored).
- `get_entry` leaves browser boilerplate headers and a query string repeated in `payload` out of requests;
  `include=["headers"]` returns them.

### Fixed

- Long scans (`search` over all retained data especially) could run far past the time budget in a single
  statement and be cut off by a proxy timeout (502). Scans now start with a small slice, and on MySQL/MariaDB
  every statement is stopped by the database at the deadline.

## [1.0.0]

First public release.

- Read-only MCP endpoint for Laravel Telescope data, protected by a key derived from `APP_KEY`.
- Tools: `overview`, `stats`, `list_entries`, `search`, `get_entry`, `get_batch`, `user_activity`,
  `record_history`, `n_plus_one`, `list_tags`.
- Prompts: `investigate_error`, `investigate_user`, `why_slow`, `triage`.
- `php artisan telescope-mcp:key` prints the access key and a `claude mcp add` command.
- Prompt-injection guard, optional redaction, per-call time budget and response size limit.

[Unreleased]: https://github.com/LeonLav77/telescope-mcp/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/LeonLav77/telescope-mcp/releases/tag/v1.0.0
