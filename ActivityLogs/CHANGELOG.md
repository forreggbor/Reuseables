# Changelog

All notable changes to ActivityLogger will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.2.0] - 2026-05-26

| Category | Description                                                                                                                               |
|----------|-------------------------------------------------------------------------------------------------------------------------------------------|
| Added    | Full-featured admin interface: paginated log viewer, filters, stats, export, detail modal; `print_max_rows` and `asset_base_url` config options |
| Fixed    | Out-of-memory risk on large print jobs, N+1 queries in CSV export, silent errors on malformed data, and two server-error handling issues   |

### Added

- `ActivityLogsAdmin` facade — single entry point for the admin UI. Wire it up with a PDO
  connection, a config array, and an auth adapter; call `handle($action, $_GET)` and emit the
  returned envelope. No host-side rendering logic needed.
- Six filter-aware stat cards (Total, Today, This Week, Active Users, Action Types, Entity Types)
  that recompute against all active filters. Boundaries for "Today" and "This Week" are computed
  in PHP using the configured timezone — never dependent on the MySQL server timezone.
- Source tabs and sticky filter bar (user, action type, entity type, source, date range, text
  search) with persistent filter state across pages.
- Paginated log table with colored action badges, resolved entity names, and expandable
  old/new-value diff rows (old values in red strikethrough, new values in green).
- Detail modal: click any row to fetch the full log entry as JSON and display it inline.
- CSV export with UTF-8 BOM, streamed via a PHP Generator (no full-file buffering), with
  CSV-injection guard for cells starting with `= + - @`.
- Print view with auto-triggered `window.print()`.
- `EntityResolverRegistry` — register per-entity-type callbacks so the viewer can show
  meaningful names instead of raw IDs. Supports single-item and batch resolvers; falls back to
  `"type #id"` and logs once per type if a resolver throws.
- `AuthAdapterInterface` + `CallableAuthAdapter` — wire auth with three closures: authorized?,
  current user id, and `fn(array $ids): array` id-to-name map.
- `ActionColorResolver` — maps action names to badge color classes by prefix (`create_*` → green,
  `update_*` → amber, `delete_*` → red, `*login*` → blue, export/import → purple). Host can add
  exact-match overrides.
- Locale support: `en_US` and `hu_HU` built-in; works without a host i18n system.
  Host can optionally pass a `TranslatorInterface` to use its own translations.
- `doc/INTEGRATION-GUIDE.md` — copy-paste host setup guide covering asset deployment, config
  options, entity resolvers, timezone notes, CSV streaming, and CSRF guidance.
- Self-contained CSS (`activity-logs.css`) and vanilla JS (`activity-logs.js`) with `al-`/`--al-`
  namespaced selectors — no Bootstrap, no jQuery, no external dependencies.
- `print_max_rows` config option (default: 5000): the print view caps the number of rows it loads
  and shows a notice when the result is trimmed; use CSV export for the full data set.
- `asset_base_url` config option: when set, the print view loads its own CSS and JS from this URL
  path, enabling styled output and automatic print dialog without an inline script — required for
  hosts running a strict `script-src` Content Security Policy.

### Fixed

- Admin page crashed with a fatal error when rendered more than once in the same request
  (PHP fatal on re-declaring view helper functions).
- Server errors thrown during view rendering caused the response body to be corrupted or empty
  instead of returning the proper error page.
- CSV export made one database query per row to look up user names; now uses batched lookups —
  exports with thousands of distinct users complete in a fraction of the time with no memory growth.
- Requesting log entry details for a record that contained invalid data returned an empty successful
  response instead of an error message.
- Print view loaded the entire result set into memory with no upper bound; see `print_max_rows`.

## [1.1.1] - 2026-05-21

| Category | Description                                                                       |
|----------|-----------------------------------------------------------------------------------|
| Fixed    | `maskSensitiveData()` crash on integer-keyed sub-arrays under PHP 8.3 strict mode |

### Fixed

- `ActivityLogger::log()` previously failed silently when `oldValues`, `newValues`, or `context`
  contained arrays with integer keys (e.g. `['added' => ['key_a', 'key_b']]`).
  `maskSensitiveData()` called `strtolower()` on every key during its recursive walk; PHP 8.3
  rejects an integer argument to `strtolower()`, killing the entire audit log write. Integer keys
  are now skipped — they can never match a `sensitive_fields` name anyway. Affects every consumer
  that passes nested arrays with non-string keys (CronAdmin's `sync_cron_manifest` diff in
  particular).

## [1.1.0] - 2026-03-11

| Category | Description                                                           |
|----------|-----------------------------------------------------------------------|
| Security | Fixed IP spoofing — proxy headers no longer trusted from any requester |
| Added    | `trusted_proxies` config option for declaring trusted reverse proxies  |
| Changed  | Default IP detection now uses `REMOTE_ADDR` only (was: all headers)   |

### Security

- Fixed IP spoofing vulnerability: `getClientIp()` previously trusted `X-Forwarded-For`,
  `CF-Connecting-IP`, and similar headers from any client, allowing anyone to write an arbitrary IP
  into the audit log by sending a fake header. Proxy headers are now only read when `REMOTE_ADDR`
  matches a configured trusted proxy.

### Added

- `trusted_proxies` configuration option: an array of IP addresses and CIDR ranges that are
  recognized as trusted reverse proxies. When empty (the default), only `REMOTE_ADDR` is used.

### Changed

- Default behavior is now secure: if `trusted_proxies` is not configured, `REMOTE_ADDR` is always
  recorded and all forwarded headers are ignored. Projects behind a reverse proxy or Cloudflare must
  add their proxy IPs to `trusted_proxies` after upgrading — see README for migration instructions.

## [1.0.0] - 2026-01-20

### Added

- Initial release extracted and generalized from FlowerShop project
- `ActivityLogger` class with static and instance modes
- Flexible schema: VARCHAR for source and entity_id, optional entity_type
- `context` JSON column for any additional structured data
- Core `log()` method with all parameters optional except action
- Sensitive data masking (passwords, tokens, API keys, etc.)
- Recursive masking for nested arrays
- Unchanged value filtering (only stores actual changes)
- SHA-256 integrity checksum generation and verification
- Auto-detection of source based on request URI
- Client IP detection with proxy support (Cloudflare, X-Forwarded-For)
- Session ID tracking for grouping related actions
- Query methods: `getAll()`, `getByUser()`, `getByEntity()`, `getBySession()`, `findById()`
- Filter support: user_id, action, entity_type, entity_id, source, date_from, date_to, search
- Pagination support in `getAll()` with limit and offset
- Statistics: `getStatistics()`, `getActivityTrend()`
- Unique value lists: `getUniqueActions()`, `getUniqueEntityTypes()`, `getUniqueSources()`
- `deleteOldLogs()` for cleanup
- `verifyIntegrity()` for tamper detection
- `addSensitiveFields()` for runtime configuration
- Instance mode for multiple logger configurations
- Database schema with optimized indexes
- Comprehensive README with integration examples
