# Changelog

All notable changes to PatchModule will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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