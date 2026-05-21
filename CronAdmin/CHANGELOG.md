# Changelog

All notable changes to CronAdmin are documented in this file.

Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) + Semantic Versioning.

---

## 1.1.0 — 2026-05-21

| Category | Description |
|----------|-------------|
| Fixed    | Seven bug fixes: Sunday-only weekly jobs never fired, OOM-killed processes held the lock until timeout, and exception messages over 255 bytes caused the result write to fail silently |
| Added    | Manifest typo detection, job-key and path validation, `since_ts` input guard, schema migration to widen `last_error` |
| Security | Documented `manifest_path` as a critical trust boundary; added path-traversal guard in `ConfigValidator` |
| Changed  | Integration guide and manifest format docs updated with timezone alignment and rename semantics |

### Fixed

- Weekly jobs with a Sunday-only schedule (`days_of_week='0'`) never fired — `array_filter` without a callback treated `"0"` as falsy; now uses an explicit non-empty callback
- Processes killed by OOM or SIGKILL held the job lock for up to `lock_timeout_seconds` (default 3600 s) because the PID-liveness probe only ran after the mtime threshold; it now runs on every lock-file existence check and reclaims dead-PID locks immediately
- Exception messages longer than 255 bytes caused the `last_status` / `last_duration_ms` `UPDATE` to fail silently (MariaDB "Data too long"); error message is now truncated at 1024 bytes at both the exception catch site and the persistence boundary
- `PdoAdapter::lastInsertId()` could return `false` from PDO; now always casts to string
- `PdoAdapter::withTransaction()` threw when called inside an existing transaction; it now joins the outer transaction via `inTransaction()` check; rollback exceptions no longer swallow the original error
- Translation strings in admin views were output raw; all are now wrapped with `htmlspecialchars`
- `AdminActions::json()` and `requireManifestHealthy()` did not use `JSON_THROW_ON_ERROR`; encode failures were silently swallowed

### Added

- `ManifestReader` now rejects unknown entry keys and reports them as validation violations — catches typos such as `default_minutes` before they silently do nothing
- `LockManager::acquire()` validates `job_key` against `/^[a-z0-9_]+$/` before building the lock file path
- `ConfigValidator` rejects `manifest_path` values containing `..`; also checks that an existing `lock_dir` is writable by the current process and throws `InvalidConfigException` on failure
- `AdminActions::pollRunStatus()` validates the `since_ts` query parameter format and returns HTTP 400 on malformed input
- Translation key `TEXT_CRON_INVALID_SINCE_TS` added to `en_US` and `hu_HU` locales
- Schema migration `schema/migrations/0002_widen_last_error.sql` — widens `last_error` from `VARCHAR(255)` to `VARCHAR(1024)`

### Security

- `doc/reviewed/SECURITY.md`: added "Load-bearing trust assumption" section documenting that `manifest_path` is executed via `require` on every dispatch tick and admin page load; the file must reside in a deploy-user-owned, web-unreachable directory
- `ConfigValidator`: `manifest_path` values containing `..` are now rejected at startup

### Changed

- `doc/INTEGRATION-GUIDE.md`: added timezone alignment prerequisite — DB session timezone must match PHP `date.timezone` to avoid DST fall-back guard misfires; added manifest write-protection security note before Step 6
- `doc/MANIFEST-FORMAT.md`: added explicit "Renaming a job_key" section warning that a key rename drops all admin customizations and providing a DB migration workaround
- `doc/reviewed/LOCKING.md`: updated stale-lock detection description to reflect that the PID probe now runs on every lock-file existence check, not only after the mtime threshold

---

## 1.0.0 — 2026-05-20

| Category | Description |
|----------|-------------|
| Added    | Initial release — full cron admin module extracted from JupitERP |

### Added

- Manifest-driven job declaration (`cron/jobs.php`) with full validation and soft-delete sync
- Scheduler with DST fall-back guard and `* * * * *` 1-minute granularity assumption
- Dispatcher loop with kill-switch gate, mtime-driven manifest sync (with sync flock), POSIX flock + PID stale-detection
- JobRunner: output capture (8 KB, UTF-8 safe), tri-state email reporting (2 KB excerpt), ActivityLogger integration
- AdminActions: per-job edit modal (AJAX saveOne), enable/disable toggle, Run-Now with polling, dispatcher kill switch
- Self-contained vanilla CSS (`cron-admin.css`) + Bootstrap 5 variant (`cron-admin-bootstrap.css`)
- Vanilla JS (`cron-admin.js`) with built-in modal, notification, and confirm fallbacks
- Locale files: `en_US` and `hu_HU` with all `TEXT_CRON_*` and `TEXT_DAY_OF_WEEK_*` keys
- Schema: `schema/cron_jobs.sql` (greenfield) + `schema/migrations/0001_uplift_from_v2_85_0.sql` (JupitERP uplift)
- Docs: `INTEGRATION-GUIDE.md`, `MANIFEST-FORMAT.md`, `ADAPTER-INTERFACES.md`, `reviewed/LOCKING.md`, `reviewed/SECURITY.md`
