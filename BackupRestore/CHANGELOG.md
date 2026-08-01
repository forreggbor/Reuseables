# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.1] - 2026-08-01

| Category | Description |
|----------|--------------|
| Fixed    | Facade docblock referenced a nonexistent `handle()` method |

### Fixed
- **Facade docblock accuracy** — the class-level docblock said a host "wires those around handle()'s envelope return value," but no such method exists on the facade. Corrected to describe the actual directly-callable method surface (`restore()`, `backupEngine()`, `profileService()`, `remoteService()`, ...), matching `doc/INTEGRATION-GUIDE.md` and the file's own internal note on the same subject.

## [0.1.0] - 2026-07-12

| Category | Description |
|----------|--------------|
| Added    | Initial standalone release: database and file backup/restore, atomic and in-place restore with automatic rollback, scheduled profiles, remote SFTP transfer, and a self-contained disaster-recovery script |

### Added
- Backup creation (full, database-only, or files-only) with integrity verification, listing, download, and deletion.
- Database restore with two strategies: atomic (temporary-database swap, requires `CREATE DATABASE` privilege) and in-place (table-rename fallback) — both roll back automatically on failure, verified against real induced failures. Exception: if the atomic strategy's post-swap foreign-key rebuild itself fails, the swap has already completed and the temporary databases are deliberately left in place for manual recovery instead of being rolled back.
- File restore with a pre-restore snapshot and automatic rollback if the restore is interrupted.
- Reusable backup profiles with daily/weekly/monthly scheduling and automatic retention cleanup.
- Remote server management and backup transfer over SFTP, with encrypted credential storage.
- A standalone, dependency-free disaster-recovery script (`standalone/restore.php`) that works even when the rest of the application is broken.
- Audit logging of every backup and restore action via the `ActivityLogs` reusable module.
- Admin screens (dashboard, profiles, remote servers) with a self-contained visual style — no external CSS/JS framework required.
- Hungarian and English translations (214 phrases).
- A reproducible end-to-end test script proving every feature works against a real database.

### Fixed
- The disaster-recovery script's database queries no longer get confused by informational messages some database servers print — a rare edge case that could have made the recovery script fail on newer database server versions.
