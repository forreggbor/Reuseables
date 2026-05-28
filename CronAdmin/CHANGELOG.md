# Changelog

All notable changes to CronAdmin are documented in this file.

Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) + Semantic Versioning.

---

## 1.3.0 — 2026-05-28

| Category | Description |
|----------|-------------|
| Added    | New `display_timezone` config key; all timestamps in the admin UI now display in the host's configured timezone instead of UTC |
| Changed  | All DATETIME writes now use `UTC_TIMESTAMP()` explicitly — DB session timezone is no longer load-bearing |
| Fixed    | Scheduler DST fall-back guard compared dates in PHP's default timezone instead of the display timezone, risking double-fire near midnight during DST transitions |

### Added

- New optional `display_timezone` config key (IANA identifier, e.g. `'Europe/Budapest'`); defaults to `date_default_timezone_get()`. Set it explicitly when PHP-FPM and CLI `php.ini` files may carry different `date.timezone` values
- New `TimeZoneHelper` internal class handles all UTC-to-display-timezone conversion in one place
- Admin table and Run-Now polling now show timestamps in the configured display timezone (Last run column, queued-indicator tooltip, AJAX poll response)

### Changed

- All DATETIME write sites (`last_run_at`, `trigger_pending_at`, `updated_at`, `created_at`) now use `UTC_TIMESTAMP()` instead of `NOW()` — values are explicitly UTC regardless of the MariaDB session timezone
- Dispatcher constructs `$now` in the display timezone so schedule fields (`hour`, `minute`) are evaluated in the same timezone the admin used when configuring the job
- `doc/INTEGRATION-GUIDE.md`: Timezone alignment prerequisite replaced with the new UTC-storage + `display_timezone` contract
- `doc/MANIFEST-FORMAT.md`: `default_hour` / `default_minute` documented as being interpreted in `display_timezone`

### Fixed

- Scheduler DST fall-back guard parsed `last_run_at` as a bare string in PHP's default timezone; with UTC storage it now parses as UTC and converts to display TZ before the date comparison — prevents same-day double-fire during DST fall-back

### Notes

- **Upgrade note:** hosts whose MariaDB session timezone was non-UTC before this upgrade may briefly see old `last_run_at` rows displayed with a shifted time in the UI. New writes will be correctly UTC. The shift is cosmetic and self-heals on the next job run.

---

## 1.2.0 — 2026-05-28

| Category | Description |
|----------|-------------|
| Added    | Three new at-a-glance table columns: manually-queued indicator, DB-logging flag, and email-report mode |
| Changed  | Run now button is disabled while a job is queued; page load starts a polling loop for any already-queued jobs |
| Changed  | Integration guide clarified: `index()` outputs a view fragment — host controller must wrap it in the admin layout |

### Added

- Three new read-only columns in the cron job table: a ⌛ hourglass (with queuer name and timestamp in a tooltip) when a job is manually queued; a 💾 icon when DB logging is active; ⚠ or ✉ for the email-report mode — all previously visible only in the edit modal
- Baseline poll on page load: jobs that were already queued before the page was opened now show the indicator and auto-clear when the dispatcher picks them up, without requiring a page refresh

### Changed

- Run now button renders as `disabled` with a tooltip while `trigger_pending = 1`, preventing the 409 "already queued" error on a repeat click
- `INTEGRATION-GUIDE.md` Step 10 now clearly separates response ownership: `index()` outputs a view fragment the host must wrap in its layout; AJAX endpoints write complete responses and must not be buffered

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
