# Changelog

All notable changes to PatchModule will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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