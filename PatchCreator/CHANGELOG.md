# Changelog

All notable changes to PatchCreator will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.01.00] - 2026-05-02

| Category | Description                                                                               |
|----------|-------------------------------------------------------------------------------------------|
| Added    | Automatic detection of deleted files; `removed_files` array in manifest; deletion count in summary |

### Added
- Deleted files (git `--diff-filter=D`) are now automatically detected and collected into a `REMOVED_FILES` array; the same `matches_exclude()` filter that applies to added/modified files is applied to deletions
- `manifest.json` now includes a `removed_files` array listing all files to be deleted on the install side; the key is omitted entirely when there are no deletions (backward compatible with all existing PatchModule versions)
- Package summary now shows both counts: "X added/modified, Y to remove"
- Deletion-only patches (no added/modified files, only removed ones) are now valid and can be packaged
- Deletions are not detected when using `-f` (explicit file list override) — the explicit list wins; deletion auto-detection is only active in git diff mode
- PatchModule v1.3.0+ is required to act on `removed_files`; older versions silently ignore the field

## [1.00.02] - 2026-04-27

| Category | Description                                                                              |
|----------|------------------------------------------------------------------------------------------|
| Changed  | Version bump; documented compatibility with LicenseManager v2.8.x hardened upload validation |

### Changed

- Version bump only; no functional changes
- Added "Server Compatibility" section to README.md documenting that generated archives satisfy all LicenseManager v2.8.0+ upload validation checks (extension, magic bytes, MIME type, `PharData` parsing, optional release notes and migration)

## [1.00.01] - 2026-03-11

| Category | Description |
|----------|-------------|
| Fixed    | Terminal color and bold formatting not rendering correctly |

### Fixed

- Terminal color codes were stored as literal strings instead of escape characters, causing ANSI sequences to appear as raw text in the output

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