# CronAdmin — Manifest File Format

The manifest is a host-owned PHP file at the path passed via `manifest_path` in the config (typically `cron/jobs.php`). It must `return` an array of job entries.

---

## Full entry schema

```php
<?php
return [
    [
        // ── Required ───────────────────────────────────────────────────────────
        'key'               => 'backup',                           // unique, /^[a-z0-9_]{1,64}$/
        'class'             => \App\Cron\Tasks\BackupTask::class,  // must implement CronTaskInterface, no-arg constructor
        'name'              => 'TEXT_JOB_BACKUP',                  // translation key (falls back to itself when not found)
        'description'       => 'TEXT_JOB_BACKUP_DESC',            // translation key for tooltip
        'default_frequency' => 'daily',                            // every_n_minutes|hourly|daily|weekly|monthly

        // ── Conditional (required per frequency) ───────────────────────────────
        'default_hour'            => 3,       // 0–23  — required when frequency ∈ {hourly,daily,weekly,monthly}
        'default_minute'          => 0,       // 0–59  — required when frequency ∈ {hourly,daily,weekly,monthly}
        'default_days_of_week'    => '',      // CSV 0–6 (0=Sun) — required when frequency=weekly, e.g. '0,3'
        'default_days_of_month'   => '',      // CSV 1–31 — required when frequency=monthly, e.g. '1,15'
        'default_every_n_minutes' => null,    // ∈ {1,5,10,15,20,30,60,120,180,240,360,720,1440} — required when frequency=every_n_minutes

        // ── Optional (omit to use defaults) ────────────────────────────────────
        'default_enabled'    => 1,            // 0|1 (default: 1)
        'default_email_report' => 'on_failure', // off|on_failure|every_run (default: off)
        'default_log_to_db'  => 0,            // 0|1 (default: 0)
        'lock_timeout_seconds' => 3600,       // positive int (default: 3600)
    ],
];
```

---

## Key validation rules

| Key | Rule |
|-----|------|
| `key` | Required. Unique within manifest. Matches `/^[a-z0-9_]{1,64}$/`. |
| `class` | Required. Class must exist. Must implement `CronTaskInterface`. Must have a no-arg constructor. |
| `name` | Required. Non-empty string. Treated as a translation key — `__('TEXT_JOB_BACKUP')`. Falls back to the literal value when not found. |
| `description` | Required. Non-empty string. Same translation-key treatment as `name`. |
| `default_frequency` | Required. One of: `every_n_minutes`, `hourly`, `daily`, `weekly`, `monthly`. |
| `default_every_n_minutes` | Required when `default_frequency='every_n_minutes'`. Must be ∈ `{1,5,10,15,20,30,60,120,180,240,360,720,1440}`. |
| `default_minute` | Required when `default_frequency ∈ {hourly,daily,weekly,monthly}`. Integer 0–59. Interpreted in `display_timezone`. |
| `default_hour` | Required when `default_frequency ∈ {daily,weekly,monthly}`. Integer 0–23. Interpreted in `display_timezone`. |
| `default_days_of_week` | Required when `default_frequency='weekly'`. CSV of digits 0–6 matching `/^[0-6](,[0-6])*$/`. |
| `default_days_of_month` | Required when `default_frequency='monthly'`. CSV of 1–31 matching `/^([1-9]\|[12]\d\|3[01])(,([1-9]\|[12]\d\|3[01]))*$/`. |
| `default_enabled` | Optional. 0 or 1. Default: 1. |
| `default_email_report` | Optional. One of `off`, `on_failure`, `every_run`. Default: `off`. |
| `default_log_to_db` | Optional. 0 or 1. Default: 0. |
| `lock_timeout_seconds` | Optional. Positive integer. Default: 3600. |

`ManifestReader::load()` collects ALL violations and throws a single `InvalidManifestException` — no fix-one-at-a-time loops.

---

## Sync behaviour

### When sync runs

- **Always** at the top of every `AdminActions::index()` call.
- **From `Dispatcher::dispatch()`** only when the manifest file's mtime has changed since the last successful sync (stored in `<lock_dir>/.manifest_mtime`).

### Reconciliation algorithm

| Situation | Action |
|-----------|--------|
| Key in manifest, NOT in DB | INSERT new row with manifest defaults. `active=1`, `enabled=default_enabled`. |
| Key in manifest, already in DB | UPDATE `name_key`, `description_key`, `lock_timeout_seconds`, `active=1`. All other fields preserved. |
| Key in DB with `active=1`, NOT in manifest | `active=0` (soft-delete). History preserved. |

**Preserved fields (never overwritten by sync):** `enabled`, `frequency`, `hour`, `minute`, `days_of_week`, `days_of_month`, `every_n_minutes`, `email_report`, `log_to_db`, `last_run_at`, `last_status`, `last_output_excerpt`.

### Re-add semantics

When a previously removed job (row `active=0`) reappears in the manifest:
- `active` flips back to `1`.
- `name_key`, `description_key`, `lock_timeout_seconds` are updated to current manifest values.
- All other fields — including `enabled`, `frequency`, `email_report` — are **preserved as they were at removal time**. The admin's prior intent survives a manifest round-trip.

### Renaming a job_key

**Renaming a key resets all admin customisations.** The sync algorithm matches entries by `key`. A renamed entry (`backup` → `db_backup`) is treated as:

1. INSERT a new row for `db_backup` using manifest defaults (`default_frequency`, `default_enabled`, etc.).
2. Soft-delete (`active=0`) the old `backup` row.

All admin edits on the old row (schedule, enabled state, email_report, log_to_db) are **lost** — the new row starts from manifest defaults. This is different from a remove+re-add of the same key, where preserved fields survive.

**To rename a key without losing admin customisations:** deploy a database migration that renames `job_key` in `cron_jobs`, then update the manifest in the same release:

```sql
UPDATE cron_jobs SET job_key = 'db_backup' WHERE job_key = 'backup';
```

### Missing vs empty manifest

| State | `Dispatcher::dispatch()` | `AdminActions::index()` |
|-------|--------------------------|--------------------------|
| File missing | ERROR log, return early — no jobs run | Red error banner |
| `return []` | Valid — all `active=1` rows soft-deleted | "No jobs declared" info banner |

---

## Deployment caveat: `every_run` email_report

A job with `email_report='every_run'` set by the admin will send one email per execution. At `every_n_minutes=1` this produces 1440 emails per day. This is by design — the admin explicitly opts in per job. Document it in your onboarding materials.

---

## Worked example (3 jobs)

```php
<?php
return [
    [
        'key'               => 'backup',
        'class'             => \App\Cron\Tasks\BackupTask::class,
        'name'              => 'TEXT_JOB_BACKUP',
        'description'       => 'TEXT_JOB_BACKUP_DESC',
        'default_frequency' => 'daily',
        'default_hour'      => 3,
        'default_minute'    => 0,
        'default_email_report' => 'on_failure',
    ],
    [
        'key'                  => 'membership_validation',
        'class'                => \App\Cron\Tasks\MembershipValidationTask::class,
        'name'                 => 'TEXT_JOB_MEMBERSHIP_VALIDATION',
        'description'          => 'TEXT_JOB_MEMBERSHIP_VALIDATION_DESC',
        'default_frequency'    => 'weekly',
        'default_enabled'      => 1,
        'default_hour'         => 23,
        'default_minute'       => 59,
        'default_days_of_week' => '0',
    ],
    [
        'key'                   => 'optimize_database',
        'class'                 => \App\Cron\Tasks\OptimizeDatabaseTask::class,
        'name'                  => 'TEXT_JOB_OPTIMIZE_DATABASE',
        'description'           => 'TEXT_JOB_OPTIMIZE_DATABASE_DESC',
        'default_frequency'     => 'monthly',
        'default_enabled'       => 0,
        'default_hour'          => 2,
        'default_minute'        => 0,
        'default_days_of_month' => '1',
    ],
];
```
