# Changelog

All notable changes to PatchModule will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.4.0] - 2026-05-06

| Category | Description                                                                                             |
|----------|---------------------------------------------------------------------------------------------------------|
| Added    | Atomic file writes with mode preservation, install lock, abort protection, and Twig cache invalidation |
| Added    | DELIMITER-aware SQL parser for stored procedures and triggers                                           |
| Added    | Completed install snapshots and backups are now retained for rollback (configurable, default 3)        |
| Fixed    | Manifest schema validation now rejects non-string file paths and invalid version formats               |
| Fixed    | Verification step now confirms every manifest file exists and the new version reads back correctly     |
| Fixed    | Maintenance mode was never engaged during installs; it is now enabled for the full install/rollback    |

### Added
- **Atomic file writes** — files are first written to a `.patchtmp` staging name, then renamed into place (POSIX-atomic); avoids serving a half-written file if PHP is killed mid-install. A cross-filesystem `EXDEV` fallback (copy + unlink) is used when source and destination are on different mounts.
- **File mode preservation** — the original `chmod` value of every file being replaced or removed is recorded in `snapshot_meta.json` at snapshot time and restored on rollback, so executable scripts and configuration files keep their permissions after an install or rollback.
- **Stale temp file cleanup** — at the start of every install, any `*.patchtmp` files older than 24 hours are deleted from the project root to prevent debris from previously killed installs.
- **`ignore_user_abort(true)` + `set_time_limit(0)`** — the install/rollback entry point now survives a browser disconnect and sets no PHP execution time limit for the duration of the operation. A `finally` block guarantees maintenance mode is disabled even on an uncaught exception.
- **Maintenance mode activation** — `MaintenanceMode::enable()` is called at the start of every install and rollback, and disabled in a `finally` block. It was previously wired into the module but never called during the install pipeline.
- **Configurable compiled-cache flushing** — a new `cache_paths_to_clear` config key accepts a list of absolute directory paths (e.g. the Twig compiled-template cache). The module empties those directories after each file-mutation step and after every rollback, preventing stale rendered output.
- **Configurable rollback retention** — a new `keep_last_snapshots` config key (default `3`) controls how many completed installs retain their snapshot and DB backup for later rollback. Older snapshots are pruned automatically after each successful install. Failed install snapshots are kept until manually dismissed.
- **DELIMITER-aware SQL parser** — `PatchMigrator::parseSqlStatements()` now recognises `DELIMITER` directives so patches can ship stored procedures, triggers, and functions. Arbitrary terminators (`//`, `$$`, `;;`, etc.) are supported. Custom terminators are stripped from statement bodies before execution.
- **Centralised error codes** — `ErrorCode` class holds constants for all internal error code strings (`INVALID_MANIFEST_SCHEMA`, `INSTALL_IN_PROGRESS`, `VERIFICATION_FAILED`, and all pre-existing codes). All internal usages migrated to the constants.

### Fixed
- Manifest schema validation now checks that `version` matches a strict semver pattern (`x.y.z` or `x.y.z-pre`) and that every element in `files` and `removed_files` is a `string` — previously a mixed-type array or a path like `../../etc/passwd` would not be rejected at the schema level.
- Verification step (`verifyInstallation`) now checks that every file listed in `manifest.files` exists at its destination path, and reads back the stored `app_version` to confirm it matches the newly installed version. A scan-fallback install (empty `manifest.files`) re-scans the archive directory for the file list so that fallback installs are also verified. File sizes are compared against the archive source rather than requiring a non-zero size, so legitimate zero-byte files pass verification.
- Maintenance mode was injected into the module but never called during the install or rollback pipeline; it is now unconditionally engaged for the full duration of the operation.
- Rollback mode and size checks are now applied consistently when restoring files removed by a patch (previously only applied to replaced files).

## [1.3.0] - 2026-05-02

| Category | Description                                                                                    |
|----------|------------------------------------------------------------------------------------------------|
| Fixed    | Download precondition detection was silently broken — the `not_recently_verified` retry path now works correctly |
| Added    | Full server error code mapping, 429 rate-limit handling with `Retry-After`, file deletion support, `ServerErrorMapper` utility |
| Changed  | Translations migrated from gettext `.po` to PHP-array `locale/{lang}/messages.php`            |
| Security | Path-traversal hardening via `safeJoin()`, symlink rejection in archives and directory scans  |

### Fixed
- `PatchDownloader` was reading `error.message` from the server error response by casting the `error` object to string, which always produced the literal `"Array"`. The `not_recently_verified` precondition check never matched — the `license_verify_callback` retry path was effectively dead code in all previous versions. This is now corrected by the new `ServerErrorMapper` class that properly reads `error.message`.

### Added
- `ServerErrorMapper` — shared utility that maps every documented server HTTP response to a stable client `error_code` string and optional `retry_after` integer
- `PatchDownloader::download()` now returns `error_code` and `retry_after` keys for all failure paths, covering: `not_recently_verified`, `invalid_license`, `license_revoked`, `license_expired`, `license_ip_mismatch`, `package_mismatch`, `rate_limited`, `signing_unavailable`, `server_error`, `network_error`
- `PatchChecker::checkForUpdates()` now returns `error_code` and `retry_after` for failed server calls (both per-IP router-level 429 and endpoint-level 429 are handled)
- `PatchInstaller::install()` return array now includes `error_code` and `retry_after`; `error_message` stored in `patch_history` is prefixed with `[error_code]` for easy grepping
- 429 rate-limit handling with `Retry-After` header parsing (supports both delta-seconds and HTTP-date forms)
- `PatchFileManager::removeFiles()` — deletes obsolete files listed in `manifest.removed_files`; validates each path via `safeJoin()` before deletion; invalidates OPcache per file; missing files are logged as INFO and counted as success (idempotent)
- Manifest `removed_files` optional array support: files listed are deleted from the project root after `copyFiles()` succeeds; fully backward compatible (absent field treated as empty)
- Snapshot (`snapshot_meta.json`) now includes a `files_to_remove` list: existing files scheduled for deletion are backed up before removal, and restored on rollback
- `TEXT_PATCH_STEP_REMOVE_FILES` install step added to the progress pipeline
- `doc/reviewed/ERROR-CODES.md` — full reference of all client `error_code` values, the server conditions that produce them, and recommended translation keys
- `doc/reviewed/PATH-SAFETY.md` — documents `safeJoin()`, symlink rejection, and the `invalid_manifest_path` / `invalid_archive` error codes

### Changed
- Translations migrated from gettext `.po` files to project-standard PHP arrays at `locale/en_US/messages.php` and `locale/hu_HU/messages.php`. No compilation step needed. Old `locale/{lang}/LC_MESSAGES/patch.po` files removed.
- `CurlHttpClient::postJson()` now captures and lowercases response headers (matching `downloadFile()` behaviour), so `Retry-After` headers from `/patches/check` are accessible to callers

### Security
- `PatchFileManager::safeJoin()` private helper validates every file path before any filesystem operation: rejects empty strings, absolute paths (Unix and Windows), backslashes, NUL bytes, and `..`/`.`/empty segments; any traversal attempt aborts the install with `error_code = 'invalid_manifest_path'`
- Archive extraction is followed by a full symlink scan; any symlink found in the extracted tree causes the install to fail with `error_code = 'invalid_archive'` and the extraction directory is cleaned up
- `scanDirectory()` skips symlinks in the project root during the post-install verification scan
- `ExecTarAdapter` now passes `--no-same-owner --no-same-permissions` to `tar` to prevent archives from forcing unexpected file ownership or permissions

## [1.2.0] - 2026-04-27

| Category | Description                                                                      |
|----------|----------------------------------------------------------------------------------|
| Added    | Patch metadata signature verification against server public key (RSA/Ed25519)    |
| Changed  | Download precondition error handled with automatic license re-verification retry |

### Added
- `SignatureVerifierInterface` — contract for signature verification implementations
- `OpenSslSignatureVerifier` — default implementation using `openssl_verify` with SHA-256
- Patch metadata signature verification in `PatchChecker`: patches signed by the server are verified against the public key returned alongside them; patches that fail verification are excluded from the cache
- Public key pinning: the optional `expected_public_key_pem` config key pins the trusted server key; patches presenting a different key are rejected regardless of whether the signature itself could be verified
- Signature, public key, and expiry fields are now stored in the patch cache so re-verification is possible when server adds expiry to responses
- `license_verify_callback` config option: a callable invoked before each download attempt to keep the server-side recently-verified window fresh

### Changed
- `PatchDownloader::download()` now returns an `error_code` key (`'not_recently_verified'`) when the server rejects the download because the license has not been checked recently enough (HTTP 403 with `license_key_not_recently_verified` or the legacy `license_key_ip_mismatch` error code)
- `PatchInstaller::install()` automatically retries the download once after invoking `license_verify_callback` when the server returns a recently-verified precondition failure; if the retry also fails, the error surfaces normally
- `HttpClientInterface::downloadFile()` now includes a `body` key in failure responses so error codes returned by the server can be inspected by the downloader

## [1.1.0] - 2026-03-11

| Category | Description                                                                        |
|----------|------------------------------------------------------------------------------------|
| Added    | Built-in database backup adapter supporting both MariaDB and MySQL                 |
| Fixed    | Backup is now skipped when a patch has no SQL migration, preventing wasted time and corrupt rollback state |
| Fixed    | MariaDB deprecation warnings no longer corrupt SQL dump files                      |
| Fixed    | mysqldump failures in piped commands are now correctly detected and reported       |

### Added
- `MysqldumpBackupAdapter` — ready-to-use `BackupAdapterInterface` implementation that auto-detects `mariadb-dump` or `mysqldump` (and `mariadb` or `mysql` for restore), eliminating the need for projects to write their own backup adapter

### Fixed
- Database backup is no longer created when the patch contains no `migration.sql` — previously a full dump was always made before extraction, even for file-only patches
- MariaDB deprecation warnings were piped into the SQL dump via `2>&1`, silently corrupting the backup file; stderr is now redirected to a temp file and kept separate from the SQL stream
- Piped shell commands (`mysqldump | gzip`, `gunzip | mysql`) now use `set -o pipefail` so a failure in the first command is not masked by the second command's exit code
- Installation pipeline step order updated: backup is now created after extraction so the presence of `migration.sql` can be confirmed first

## [1.0.0] - 2026-02-16

| Category | Details                                                     |
|----------|-------------------------------------------------------------|
| Type     | Initial release                                             |
| Summary  | Framework-agnostic patch management extracted from FlowerShop |
| Files    | 22 files created                                            |

### Added
- `PatchModule` main facade with configuration-driven adapter wiring
- 6 adapter interfaces: `DatabaseAdapterInterface`, `HttpClientInterface`, `ArchiveAdapterInterface`, `BackupAdapterInterface`, `LoggerInterface`, `VersionResolverInterface`
- Default adapters: `PdoAdapter`, `CallableAdapter`, `CurlHttpClient`, `ExecTarAdapter`, `PharTarAdapter`
- `PatchChecker` for update checking, caching, and dismiss management
- `PatchDownloader` with SHA-256 hash verification
- `PatchInstaller` orchestrator with full pipeline (preflight → backup → download → install → verify → cleanup)
- `PatchFileManager` for file copy, selective snapshot, and rollback
- `PatchMigrator` for SQL statement parsing and execution with FK check toggling
- `ProgressTracker` for atomic JSON file-based progress tracking
- `MaintenanceMode` for flag-file-based maintenance mode toggle
- Database schema: `patch_history` and `patch_settings` tables
- Self-contained maintenance page view (Bootstrap 5, no framework dependencies)
- Frontend JavaScript state machine (`patch-update.js`) for modal and progress UI
- Bilingual translations (Hungarian, English) in gettext PO format