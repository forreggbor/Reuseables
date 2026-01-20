# Changelog

All notable changes to ErrorHandler will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.1] - 2026-01-20

### Fixed

- Removed deprecated `E_STRICT` constant from error type mapping (deprecated in PHP 8.4)

## [1.0.0] - 2026-01-20

### Added

- Initial release extracted and generalized from FlowerShop project
- `ErrorHandler` class with static and instance modes
- Log level support: ERROR, WARNING, INFO, DEBUG with priority filtering
- Convenience methods: `error()`, `warning()`, `info()`, `debug()`
- Context array support for structured log data
- `logException()` method for comprehensive exception logging
- `registerErrorHandler()` to catch PHP errors
- `registerExceptionHandler()` to catch uncaught exceptions
- `registerShutdownHandler()` to catch fatal errors
- `registerAllHandlers()` to register all handlers at once
- Handler restoration methods
- Runtime configuration: `setLogLevel()`, `setLogPath()`, `setLogFile()`
- `isLevelEnabled()` for conditional logging
- `getLogFilePath()` utility method
- File locking for concurrent-safe writes
- Automatic directory creation with configurable permissions
- Fallback to PHP's `error_log()` on write failure
- Comprehensive README with integration examples
