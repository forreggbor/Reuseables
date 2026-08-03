# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.2] - 2026-08-03

| Category | Description |
|----------|--------------|
| Security | Fixed a bug where restoring a corrupted or malicious backup could delete files outside the intended restore area |
| Security | Restore/backup audit trail no longer fails silently when the audit logging module is missing |
| Security | On hosts without shell access, a failed database restore now stops immediately instead of wasting time on an already-broken backup file |
| Security | The disaster-recovery restore script no longer reports a database restore as successful when it actually failed |
| Changed  | Simplified internal foreign-key handling during partial database restores |

### Security
- Fixed a bug in the restore cleanup process where a specially crafted or corrupted backup archive could cause files outside the intended restore folder to be deleted — both right after a restore and during the scheduled cleanup of old restore files (#8).
- The backup/restore audit trail no longer silently stops recording without warning when the audit logging module isn't available — a clear warning is now logged instead (#8).
- On hosts without shell access (the fallback mode used on some shared hosting), a database restore that hits a broken statement now stops right away instead of continuing to process the rest of an already-failed backup file — avoiding wasted time that could otherwise let the restore process get killed by the server before it can safely undo its changes (#9).
- The standalone disaster-recovery restore script now correctly reports a database restore as failed when it actually failed on hosts without shell access — previously it could continue past the error silently and claim the restore succeeded (#9).

### Changed
- Simplified the internal foreign-key handling code used during partial database restores, reducing duplicated logic and the risk of future inconsistencies (#8).

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
