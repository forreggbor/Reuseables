# Changelog

All notable changes to ActivityLogger will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
