# Changelog

All notable changes to the LicenseModule will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
