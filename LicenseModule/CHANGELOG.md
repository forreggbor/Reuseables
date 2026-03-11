# Changelog

All notable changes to the LicenseModule will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.4.0] - 2026-03-11

| Category | Description                                                      |
|----------|------------------------------------------------------------------|
| Added    | In-memory session adapter for CLI and cron contexts              |

### Added

- `MemorySessionAdapter` — a `SessionAdapterInterface` implementation that stores session values in a plain PHP array, with no dependency on `session_start()` or `$_SESSION`; intended for CLI scripts, cron jobs, and test environments

## [1.3.0] - 2026-02-16

| Category | Details                                                        |
|----------|----------------------------------------------------------------|
| Type     | Feature                                                        |
| Summary  | Server-side grace period support for expired licenses          |
| Files    | 10 files (4 modified, 1 created, 2 locale, 2 docs, 1 schema)  |
| Schema   | `license_info`: new `grace_expires_at` column, updated `status` ENUM |

### Added

- Server-side grace period support: licenses expired on the server can remain operational within a configured grace window
- `GRACE` status constant in `LicenseStatus` with `ACTIVE_STATUSES` grouping
- `LicenseStatus::isGrace()` static method for grace period status check
- `LicenseModule::isInGracePeriod()` to check if license is in server-side grace period
- `LicenseModule::getGraceExpiresAt()` to retrieve the grace period expiry date
- `LicenseModule::getDaysUntilGraceExpiration()` to get remaining days in grace period
- `grace_expires_at` column in `license_info` database table
- `grace` value in `status` ENUM for `license_info` and `license_validation_history` tables
- Grace period warning view (`views/grace.php`) with blue/info styling
- Translation keys: `LICENSE_GRACE_TITLE`, `LICENSE_GRACE_MESSAGE`, `LICENSE_GRACE_NOTICE` (EN/HU)

### Changed

- `LicenseStatus::isActive()` now returns `true` for both `active` and `grace` statuses
- `LicenseStatus::mapFromServer()` maps server `'grace'` status to `LicenseStatus::GRACE`
- `LicenseValidator::getCurrentStatus()` detects expired grace periods and returns `EXPIRED`
- `PdoAdapter::getLicenseInfo()` query includes `'grace'` in status filter

## [1.2.0] - 2026-02-02

### Added

- `DatabaseUnavailableException` for graceful handling when PDO factory returns null (e.g., during installation)
- Documentation for handling installation scenarios in README

### Changed

- `CallableAdapter` now throws `DatabaseUnavailableException` instead of `TypeError` when PDO is unavailable

## [1.1.1] - 2026-01-23

### Fixed

- Added JSON_UNESCAPED_UNICODE flag to debug log output for proper Hungarian character display

## [1.1.0] - 2026-01-22

### Changed

- Restructured default tier configuration:
  - Moved `delivery` and `storage_management` modules from Tier 4 (Pro) to Tier 3 (Advanced)
  - Added new Tier 4 (Pro) modules: `supplier`, `incoming_goods`, `purchasing`

## [1.0.1] - 2025-01-18

### Fixed

- Added explicit `'valid'` status mapping in `LicenseStatus::mapFromServer()` for compatibility with license server API

## [1.0.0] - 2025-01-18

### Added

- Initial release extracted from FlowerShop licensing system
- `LicenseModule` facade with public API for license validation and feature gating
- `LicenseValidator` for online validation with license server
- `FeatureGate` for tier-based and addon-based module access control
- `GracePeriodManager` for offline mode handling (7-day grace period)
- `LicenseStatus` constants class
- Adapter interfaces for framework independence:
  - `DatabaseAdapterInterface`
  - `SessionAdapterInterface`
  - `HttpClientInterface`
- Bundled adapters:
  - `PdoAdapter` for direct PDO usage
  - `CallableAdapter` for lazy PDO initialization
  - `NativeSessionAdapter` for PHP native sessions
  - `CurlHttpClient` for HTTP requests
- Translatable views for expired and suspended states
- Locale files for English (en_US) and Hungarian (hu_HU)
- SQL schema migration for license tables
- Comprehensive README with integration examples
