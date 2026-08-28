# Changelog

All notable changes to the LicenseModule will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.1.0] - 2026-08-28

| Category | Description                                                                                   |
|----------|------------------------------------------------------------------------------------------------|
| Added    | New way for projects to read the full list of add-ons available for a license's package, for upsell/marketing use |

### Added

- `getAddonCatalog()` on the main module and its underlying feature-gating layer: returns every
  add-on available in the license's package (not just the currently enabled ones), including
  name, description, price, billing period, and required tier level — useful for showing upsell
  options for add-ons a customer hasn't activated yet

## [2.0.1] - 2026-07-13

| Category | Description                                                                          |
|----------|---------------------------------------------------------------------------------------|
| Security | Hardened language-selection handling on the license admin page against invalid input |

### Security

- The language/locale setting used when rendering the license admin page is now validated
  before being used internally. Previously, an unvalidated value passed through to this
  setting could have been used to attempt reading or executing unintended files on the
  server; only recognized language codes (such as `en_US` or `hu_HU`) are now accepted, and
  anything else safely falls back to the default language

## [2.0.0] - 2026-07-12

| Category | Description                                                                                   |
|----------|------------------------------------------------------------------------------------------------|
| Added    | New, simpler way for projects to check "is this feature allowed on the current license"       |
| Changed  | ⚠️ License tiers and add-ons are now read directly from the license server; projects no longer configure them locally |
| Changed  | ⚠️ A license with no tier or feature information now blocks access instead of allowing everything |
| Removed  | ⚠️ The previous per-project tier/module list configuration and its related checks             |
| Fixed    | Suspended licenses were sometimes reported as simply "invalid," hiding the real reason access was blocked |
| Fixed    | License notice pages could show raw internal text instead of a readable message           |
| Fixed    | A corrupted license date no longer produces an incorrect "days remaining" value            |
| Fixed    | Invalid advanced configuration settings now show a clear error, or are safely ignored, instead of crashing |

### Added

- Simple gating checks projects can call directly: "does this license have this tier", "is the
  tier at least this level", "does this feature key exist on this license", and a combined check
  that can require any one, or all, of several conditions at once
- The license's package information (if the license server sent one) is now available to read

### Changed

- ⚠️ BREAKING: License tiers and add-ons are no longer configured per project inside this module.
  All tier, add-on, and feature information now comes directly and only from the license server's
  response — this removes the risk of a project's local list of tiers/features drifting out of
  sync with what the license server actually offers
- ⚠️ BREAKING: A license that has no tier or feature information at all (for example a very old
  license format) now blocks all gated functionality by default, instead of unlocking everything.
  Projects that intentionally want to grant unrestricted access must do so explicitly through the
  license server, not by leaving the license's tier/feature data empty
- The license admin page's "included modules" list has been replaced with the plain list of
  feature names the license server actually granted, so what's displayed always matches what the
  server sent
- Fixed a display bug where a license without a tier, but with a package or feature data attached,
  could have its add-ons and feature list disappear from the admin page

### Removed

- ⚠️ BREAKING: The previous way of checking "does this license include module X" (and the related
  "list all enabled modules" / "list modules for this tier" checks) has been removed, along with
  the per-project configuration option that listed which modules belong to which tier or add-on.
  Projects using this module must switch to the new feature-based checks described above; see the
  module's `doc/HOST-GATING-INTEGRATION.md` and `doc/LEGACY-TIER-ADDON-SPEC.md` for the migration
  guide and a record of each project's previous tier/add-on setup

### Fixed

- Suspended licenses were sometimes reported as simply "invalid" throughout the system — in the
  license status check, in the status reported to other systems, and in the validation history
  log, where the record of that check could be missing entirely. Suspended and invalid licenses
  are now always correctly identified and logged
- If the license server could not be reached, the system could no longer tell an already-suspended
  or invalid license apart from one that had merely expired, risking an incorrect status change
  while offline. A suspended or invalid license now always stays that way until the license server
  says otherwise
- The pages shown for an expired, suspended, or grace-period license could display raw internal
  text instead of a readable message, if the surrounding system's translation setup wasn't fully
  configured for this module. A readable English message is now always shown as a fallback
- A corrupted or unreadable license date (for example an expiration date) no longer produces an
  incorrect result, such as a large negative number of days remaining; it is now safely treated as
  unknown
- An invalid custom connection setting for reaching the license server now shows a clear, specific
  error message instead of a confusing low-level crash
- An invalid logging setting no longer prevents the license system from starting

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
