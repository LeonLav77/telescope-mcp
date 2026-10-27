# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
