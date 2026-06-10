# Changelog

All notable changes to the LicenseModule will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.6.1] - 2026-06-10

| Category | Description                                                        |
|----------|--------------------------------------------------------------------|
| Fixed    | Suspended licenses displayed as "Invalid" on the admin page        |

### Fixed

- Admin page showed "Invalid" status badge and styling for suspended licenses; status is now read directly from the stored license row so all statuses (including suspended and invalid) display correctly
- Output buffer could remain open when the admin view raised a PHP exception, potentially leaking partial HTML into the host page
- Date fields on the admin page showed `1970-01-01` instead of "Never" for rows where the database stored a zero date value
- License save operation crashed in strict PDO error mode due to an unchecked database call

## [1.6.0] - 2026-06-10

| Category | Description                                                                               |
|----------|-------------------------------------------------------------------------------------------|
| Added    | Self-contained admin page with tier, addon, and validation history display                |
| Changed  | ⚠️ `DatabaseAdapterInterface` gained two new required methods (breaking for custom adapters) |

### Added

- `LicenseModule::renderAdminPage()` — renders a complete, framework-agnostic license admin page as an HTML fragment; the host embeds it in its own layout and provides routing, CSRF, and asset context via an options array
- Addon display with click-to-reveal descriptions: each addon renders as a badge; clicking it opens a description panel below (accordion behaviour, works on all browsers including Safari and iOS touch)
- `LicenseModule::getAddons()` — returns full addon rows (key, name, slug, description) from the active license
- `LicenseModule::getTierModules()` — returns modules enabled by the current tier, excluding addon modules
- `LicenseModule::getLatestLicenseInfo()` — returns the most recent license row regardless of status, so suspended and invalid licenses are visible on the admin page
- `LicenseModule::getValidationHistory()` — returns recent validation log rows for display
- `DatabaseAdapterInterface::getLatestLicenseInfo()` and `::getValidationHistory()` — new database methods implemented by `PdoAdapter` and `CallableAdapter`
- `TranslatorInterface` — optional host bridge; if injected, host translations take priority over the module's built-ins
- Admin page locale strings in `locale/en_US/messages.php` and `locale/hu_HU/messages.php`
- Shipped assets: `public/license-admin.css` and `public/license-admin.js` (vanilla CSS/JS, no external dependencies)

### Changed

- `getTier()` now includes a `description` field in its return array
- ⚠️ BREAKING: `DatabaseAdapterInterface` has two new required methods; projects supplying a custom adapter must add `getLatestLicenseInfo()` and `getValidationHistory()`. Projects using the bundled `PdoAdapter` or `CallableAdapter` are unaffected.

## [1.5.0] - 2026-04-27

| Category | Description                                                                                                         |
|----------|---------------------------------------------------------------------------------------------------------------------|
| Added    | Graceful handling of 429 Too Many Requests from license server without consuming offline grace period               |

### Added

- `RateLimitedException` — thrown when the license server returns 429; caught in `validate()` and returned as a `THROTTLED` result without touching `last_check_at` or the offline grace window
- `LicenseStatus::THROTTLED` constant for the rate-limited state

## [1.4.1] - 2026-04-27

| Category | Description                                                                         |
|----------|-------------------------------------------------------------------------------------|
| Fixed    | First-time license save always failed; license key was missing from save data       |

### Fixed

- `PdoAdapter::saveLicenseInfo()` used the status-filtered `getLicenseInfo()` to check for an existing row — on an empty table this returned `null` and the method returned `false` without ever executing an `INSERT`. The check now queries for any row regardless of status, so the very first save correctly performs an `INSERT`.
- `LicenseValidator::validate()` did not include `license_key` in the data passed to `saveLicenseInfo()`, which would have caused a database error on the `NOT NULL` column even after the INSERT fix above.

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
