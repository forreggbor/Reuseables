# Changelog

All notable changes to PatchCreator will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.00.00] - 2026-02-16

### Added

- Initial release
- Git-based file detection via `git diff` with configurable base reference
- Version auto-detection from project source files (APP_VERSION define pattern)
- Automatic release notes extraction from CHANGELOG.md (Keep a Changelog format)
- SHA-256 hash generation alongside the archive
- Configurable default exclude patterns for common non-deployable paths
- Manual file list override via `-f` flag
- SQL migration file inclusion via `-m` flag
- Dry run mode (`--dry-run`) for previewing package contents
- Auto-confirm mode (`-y`) for CI/CD pipelines
- Deleted file detection with warnings
- Color terminal output with automatic detection
- Compatibility with PatchModule v1.00.00+