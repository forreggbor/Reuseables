# Changelog

All notable changes to ActivityLogger will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
