# Changelog

All notable changes to ErrorHandler will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.2.0] - 2026-07-04

| Category | Description                                                                    |
|----------|----------------------------------------------------------------------------------|
| Added    | `on_fatal` hook to let host apps render an error page after a fatal error        |

### Added

- `on_fatal` configuration option — a `callable(array $error): void` invoked by the shutdown
  handler right after a fatal error has been logged, letting the host application show a proper
  error page instead of a blank response. Never triggered during CLI runs, and a failure inside
  the callback itself cannot mask the original error.

## [1.1.0] - 2026-06-10

| Category | Description                                                           |
|----------|-----------------------------------------------------------------------|
| Added    | Log injection prevention and automatic credential redaction in logs   |
| Fixed    | Credential keys matching `api_key` / `api-key` were not redacted      |

### Added

- Log messages are sanitized before writing: ASCII control characters (including newlines) are replaced with spaces so a single message can never span multiple log lines
- Password-like patterns in message text (e.g. `password=secret`) are automatically redacted
- Context array values are masked when the key matches common credential names: `password`, `passwd`, `pwd`, `secret`, `token`, `api_key`, `apikey` — masking is applied recursively to nested arrays

### Fixed

- Credential redaction for `api_key` and `api-key` context keys did not work due to an invalid character class range in the masking regex

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
