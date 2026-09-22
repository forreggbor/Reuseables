# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.3.2] - 2026-09-22

| Category | Description |
|----------|--------------|
| Fixed    | `createFileArchive()` no longer tries to archive its own in-progress temp workdir when tempPath is nested under rootPath |
| Fixed    | `.git` and other dot-prefixed excludes/includes actually match now (both tar-creation backends) |
| Fixed    | A non-fatal `tar` diagnostic (exit code 1) no longer aborts an otherwise-successful backup |

### Fixed
- `BackupEngine::createFileArchive()` only excluded the backup directory and `.git`. When a host configured its temp path inside the project root (e.g. `<base>/storage/temp`), the archive-in-progress `files.tar` ended up inside the very tree being archived, and `tar` refused to dump it ("archive cannot contain itself; not dumped"). `Excludes::always()` now unconditionally excludes the temp path when it resolves under the root path, mirroring the restore-side `Excludes::fileSync()` (Reuseables#40).
- Both tar-creation backends (`Exec\ShellHelper::tarCreate()` and `Exec\PhpHelper::tarCreate()`) normalized exclude/include paths with `ltrim($path, './')`/`trim($path, './')`, which strips a leading `.` from any dot-prefixed path (`.git` → `git`) because the second argument is a character mask, not a literal prefix. Any exclude or include starting with a dot silently never matched — confirmed live on a real TFL-ERP backup, which contained the project's entire `.git` despite `Excludes::always()` listing it for exclusion. Fixed to strip only a literal `./` prefix (Reuseables#42).
- `Exec\ShellHelper::tarCreate()`/`tarCreateGz()` treated `tar` exit code 1 (non-fatal — "some files differ", e.g. a session file that changed mid-scan) identically to exit code 2 (fatal), aborting the whole backup on a transient, essentially unavoidable condition on a live filesystem. Exit code 1 is now logged as a warning and the archive is treated as successfully created; only exit code 2 or higher is reported as a failure (Reuseables#41).

## [0.3.1] - 2026-09-22

| Category | Description |
|----------|--------------|
| Fixed    | "Run now" on a backup profile now tells the host which profile to run, so its include/exclude paths apply |
| Fixed    | Backup profile and remote server create/update/delete/test are now audited |

### Fixed
- "Run now" on the profiles page sent only the backup type and a note, so a host could not apply the profile's included/excluded paths — a profile that excluded a folder still archived it. The request now also carries `profile_id`; a host that honours it loads the profile and passes its paths (and the profile id) to `createBackup()`, while a host that ignores the field behaves exactly as before (Reuseables#38).
- `ProfileService` had no audit logging at all, and `RemoteService` only audited its host-key reset/pin events — creating, updating, deleting or testing a backup profile or a remote server left no trace in the activity log. Both services now write an audit entry for create/update/delete (and, for remote servers, a successful connection test), using the same `acting_user_id` field a host already passes for the host-key-reset event. `ProfileService::delete()` and `RemoteService::delete()`/`testConnection()` gained an optional trailing `$actingUserId` parameter; existing calls without it keep working, just unattributed (Reuseables#39).

## [0.3.0] - 2026-09-16

| Category | Description |
|----------|--------------|
| Added    | Admin views work under a nonce-only Content-Security-Policy (no inline event handlers) |
| Added    | Uploaded backup archives can be registered through the engine |
| Changed  | Hosts must re-copy `js/backup-restore.js` when syncing this version |
| Fixed    | Typo in the Hungarian "Backup & Restore" heading |

### Added
- The `index`, `profiles`, and `remote-servers` admin views no longer use inline `onclick`/`onchange` attributes. Buttons and selects now carry `data-br-action` / `data-br-change` attributes and `js/backup-restore.js` dispatches them to the unchanged public `BackupRestoreUI` API, so hosts whose CSP forbids inline handlers work without weakening their policy. Hosts with their own custom views that still call `BackupRestoreUI.*` inline keep working.
- `BackupEngine::registerUploadedArchive()` lets a host register an archive it has already placed in the backup directory (e.g. an admin upload) as a completed backup, with containment and integrity checks and an `upload_backup` audit entry. Previously hosts had to insert into the module's `backups` table themselves.

### Changed
- Because the views and the JS now depend on each other through the `data-br-*` contract, a host that syncs `views/` must also re-copy `js/backup-restore.js` into its public asset directory (the deploy step documented in the integration guide). Syncing only one of the two leaves the buttons inactive.

### Fixed
- The Hungarian dashboard heading read "Visszaéllítás" instead of "Visszaállítás".

## [0.2.0] - 2026-08-31

| Category | Description |
|----------|--------------|
| Added    | Admin views now support a CSP nonce for their inline `<script>` tags |

### Added
- The `index`, `profiles`, and `remote-servers` admin views now accept an optional `$nonce` value and apply it to every inline `<script>` tag, so hosts enforcing a nonce-based Content-Security-Policy can allow these scripts without weakening the policy.

## [0.1.3] - 2026-08-03

| Category | Description |
|----------|--------------|
| Fixed    | Restoring on hosts without shell access now works for backups that were created normally (with shell access) |
| Security | On hosts without shell access, restoring from a specially crafted malicious backup can no longer write files outside the intended restore folder |

### Fixed
- Restoring a backup on a host without shell access (the fallback mode used on some shared hosting) now works correctly for backups that were created normally — previously, this failed outright for a full restore, and silently skipped restoring the database or files at all for a scoped/partial restore, without any error being shown (#10).

### Security
- On hosts without shell access, the safety check that refuses to restore a specially crafted malicious backup (one designed to write files outside the intended restore folder) now actually runs — previously it could be silently skipped on that hosting mode, relying only on an incidental, undocumented protection elsewhere (#11).

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
