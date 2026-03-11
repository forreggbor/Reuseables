# Changelog

All notable changes to PatchModule will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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