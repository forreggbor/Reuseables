# CronAdmin

Framework-agnostic PHP 8.3+ cron job administration module. Provides a manifest-driven dispatcher, per-job scheduling, POSIX locking, admin UI, Run-Now, audit logging, and email reporting — all without coupling to any host framework.

## Features

- **Manifest-driven job declarations** — host owns a single `cron/jobs.php` file; module auto-syncs it to the database on every admin page load and on manifest file changes
- **Soft-delete with re-add semantics** — jobs removed from the manifest are flagged `active=0` (history preserved); readded jobs inherit prior admin settings
- **Five frequency modes** — `every_n_minutes`, `hourly`, `daily`, `weekly`, `monthly`
- **POSIX flock per job** — non-blocking exclusive lock; PID-based stale-lock detection with mtime fallback; non-POSIX hosts fall back to mtime-only
- **DST fall-back guard** — prevents double-firing of daily/weekly/monthly jobs during clock-back hours
- **Run-Now** — atomic claim via `UPDATE … WHERE trigger_pending=0`; async poll for completion; button is disabled while a job is queued; baseline poll resumes watching pre-queued jobs on page load
- **At-a-glance table** — dedicated columns show the queued-for-manual-run indicator (⌛ with queuer name and timestamp), DB logging flag (💾), and email report mode (⚠/✉) without opening the edit modal
- **Dispatcher kill switch** — admin can suspend all execution (scheduled + Run-Now) with one toggle; manifest sync continues so the UI stays current
- **Email reporting** — per-job opt-in; `off` / `on_failure` / `every_run`; HTML body with 2 KB output excerpt
- **Activity audit logging** — every sync, save, toggle, run-now, and dispatcher toggle is logged via `ActivityLogs\ActivityLogger`
- **CSP-safe views** — no inline `<script>`/`<style>`; runtime config exposed via `data-cra-*` attributes
- **Bootstrap 5 optional** — ships self-contained vanilla CSS (`.cra-*` scoped); Bootstrap-flavoured stylesheet available via `use_bootstrap` config flag
- **Asset cache-busting** — `CronAdmin::VERSION` constant emitted as `?v=<VERSION>` query string on CSS/JS `<link>`/`<script>` tags

## Requirements

- PHP 8.3+
- `ext-pdo` + `ext-pdo_mysql` (MariaDB connection)
- `ext-mbstring` (UTF-8-safe `mb_strcut` for output truncation)
- `ext-posix` *(recommended)* — stale-lock PID probe; on non-POSIX hosts stale-lock detection degrades to mtime-only
- MariaDB 10.5+ or MySQL 8.0+
- DB user needs `SELECT` on `INFORMATION_SCHEMA` — required by the bundled migration's idempotency guards
- **ActivityLogs reusable** vendored at `lib/ActivityLogs/`; host MUST call `ActivityLogs\ActivityLogger::init($pdo)` in **both** the HTTP bootstrap and the CLI bootstrap — CLI processes have separate static state
- Global `__(string $key): string` translation function MUST exist in the host and MUST return the **unmodified key** when the key is absent from the merged translation array
- PHP's `date.timezone` MUST be set correctly (in `php.ini` or via `date_default_timezone_set()`) **before** `CronAdmin` is instantiated
- **Crontab cadence MUST be `* * * * *`** — Scheduler assumes 1-minute granularity; `*/5` cadences silently miss between-tick triggers
- Bootstrap 5 is **optional** — module ships its own vanilla CSS; Bootstrap-flavoured stylesheet available when host already loads BS5

## Installation

```bash
# Rsync both reusables into lib/
rsync -av --delete /home/gabor/development/reusables/CronAdmin/   lib/CronAdmin/
rsync -av --delete /home/gabor/development/reusables/ActivityLogs/ lib/ActivityLogs/
```

Add to `composer.json` once:

```json
"autoload": {
    "classmap": ["lib/CronAdmin/"],
    "psr-4": { "ActivityLogs\\": "lib/ActivityLogs/" }
}
```

```bash
composer dump-autoload
php -r "var_dump(class_exists('CronAdmin\\CronAdmin'), class_exists('ActivityLogs\\ActivityLogger'));"
# Expected: bool(true) bool(true)
```

Import the database schema:

```bash
# Greenfield install:
mysql -u root -p yourdb < lib/CronAdmin/schema/cron_jobs.sql

# Upgrading from JupitERP v2.85.0 table shape (run AFTER integration plan completes):
mysql -u root -p yourdb < lib/CronAdmin/schema/migrations/0001_uplift_from_v2_85_0.sql
```

Merge translations in your translation loader:

```php
$cronMessages = require __DIR__ . '/../lib/CronAdmin/locale/' . $lang . '/messages.php';
$hostMessages = require __DIR__ . '/../locale/' . $lang . '/messages.php';
$messages     = array_merge($cronMessages, $hostMessages);   // host keys win on collision
```

Link stylesheet and JS in admin layout:

```html
<link rel="stylesheet" href="/lib/CronAdmin/css/cron-admin.css?v=<?= \CronAdmin\CronAdmin::VERSION ?>">
<script src="/lib/CronAdmin/js/cron-admin.js?v=<?= \CronAdmin\CronAdmin::VERSION ?>" defer></script>
```

*(Bootstrap 5 hosts: use `cron-admin-bootstrap.css` and set `'use_bootstrap' => true` in config.)*

See [`doc/INTEGRATION-GUIDE.md`](doc/INTEGRATION-GUIDE.md) for the full 15-step recipe.

## Quick Start

**HTTP bootstrap (`public/index.php`):**

```php
\ActivityLogs\ActivityLogger::init($pdo);   // required before CronAdmin

$cron = new \CronAdmin\CronAdmin([
    'database'               => new \CronAdmin\Adapters\Database\PdoAdapter($pdo),
    'manifest_path'          => __DIR__ . '/../cron/jobs.php',
    'lock_dir'               => __DIR__ . '/../storage/temp/cron',
    'dispatcher_kill_switch' => new \App\Adapters\Cron\KillSwitchAdapter($pdo),
    'auth_adapter'           => new \App\Adapters\Cron\AuthAdapter(),
    'csrf_adapter'           => new \App\Adapters\Cron\CsrfAdapter(),
    'base_url'               => '/admin/cron',
    'use_bootstrap'          => true,
    'mail_adapter'           => new \App\Adapters\Cron\MailAdapter(),
    'recipient_resolver'     => new \App\Adapters\Cron\RecipientResolver($pdo),
    'logger'                 => new \App\Adapters\Cron\LoggerAdapter(),
]);

// Wire 6 routes:
$actions = $cron->getAdminActions();
$router->get('/admin/cron',                 fn()          => $actions->index());
$router->post('/admin/cron/dispatcher',     fn()          => $actions->toggleDispatcher());
$router->post('/admin/cron/{id}/save',      fn(int $id)   => $actions->saveOne($id));
$router->post('/admin/cron/{id}/toggle',    fn(int $id)   => $actions->toggle($id));
$router->post('/admin/cron/{id}/run-now',   fn(int $id)   => $actions->runNow($id));
$router->get('/admin/cron/{id}/run-status', fn(int $id)   => $actions->pollRunStatus($id));
```

**CLI bootstrap (`cron/run.php`):**

```php
<?php
require __DIR__ . '/../vendor/autoload.php';
(new \Dotenv\Dotenv(__DIR__ . '/..'))->load();

$pdo = new PDO(getenv('DB_DSN'), getenv('DB_USER'), getenv('DB_PASS'));
\ActivityLogs\ActivityLogger::init($pdo);   // must be before CronAdmin

$cron = new \CronAdmin\CronAdmin([
    'database'               => new \CronAdmin\Adapters\Database\PdoAdapter($pdo),
    'manifest_path'          => __DIR__ . '/jobs.php',
    'lock_dir'               => __DIR__ . '/../storage/temp/cron',
    'dispatcher_kill_switch' => new \App\Adapters\Cron\KillSwitchAdapter($pdo),
    'mail_adapter'           => new \App\Adapters\Cron\MailAdapter(),
    'recipient_resolver'     => new \App\Adapters\Cron\RecipientResolver($pdo),
    'logger'                 => new \App\Adapters\Cron\LoggerAdapter(),
]);

$cron->dispatch();
```

**Crontab (`* * * * *` is mandatory):**

```
* * * * * /usr/bin/php8.3 /path/to/project/cron/run.php >> /var/log/cron.log 2>&1
```

## Configuration

| Key | Type | Required | Default | Description |
|-----|------|----------|---------|-------------|
| `database` | `DatabaseAdapterInterface` | Yes | — | PDO adapter or callable adapter |
| `manifest_path` | `string` | Yes | — | Absolute path to `cron/jobs.php` |
| `lock_dir` | `string` | Yes | — | Writable directory for lock files (created on first run) |
| `dispatcher_kill_switch` | `DispatcherKillSwitchAdapterInterface` | Yes | — | Master enable/disable toggle |
| `auth_adapter` | `AuthAdapterInterface` | Admin UI | — | Host auth bridge |
| `csrf_adapter` | `CsrfAdapterInterface` | Admin UI | — | Host CSRF bridge (reads `$_POST['csrf_token']`) |
| `base_url` | `string` | Admin UI | — | Admin UI base path, e.g. `/admin/cron` |
| `asset_base_url` | `string` | No | `/lib/CronAdmin` | Path from which CSS/JS are served |
| `use_bootstrap` | `bool` | No | `false` | `true` → link `cron-admin-bootstrap.css` |
| `mail_adapter` | `MailAdapterInterface` | No | — | Required when any job has `email_report ≠ 'off'` |
| `recipient_resolver` | `MailRecipientResolverInterface` | No | — | Returns admin email list |
| `logger` | `LoggerInterface` | No | no-op | Bridges to host `app_log()` or PSR-3 logger |

All three admin UI keys (`auth_adapter`, `csrf_adapter`, `base_url`) must be provided together or omitted together — partial configuration returns `null` from `getAdminActions()`.

## Admin UI

The admin page provides a table with one row per job: enabled toggle, name, schedule summary, last run, status badge, and action buttons (Edit, Run-Now, View Output). Frequency, email reporting, and log-to-DB settings are edited per job in a modal — there is no bulk-save endpoint.

`AdminActions` methods write the HTTP response themselves (echo HTML for `index`; `echo json_encode(...)` for AJAX). **Do NOT wrap the return value in another response.**

Apply the host's admin auth middleware to all `/admin/cron*` routes **before** calling these methods.

## Adapter Interfaces

See [`doc/ADAPTER-INTERFACES.md`](doc/ADAPTER-INTERFACES.md) for required method signatures and per-project implementation examples (JupitERP / TrafficJournal / LicenseManager).

| Interface | Required | Purpose |
|-----------|----------|---------|
| `DatabaseAdapterInterface` | Yes | `fetchAll`, `fetchOne`, `execute`, `lastInsertId`, `withTransaction` |
| `DispatcherKillSwitchAdapterInterface` | Yes | `get(): bool`, `set(bool): void` |
| `AuthAdapterInterface` | Admin UI | `getCurrentUserId`, `isAuthorized`, `getUserMap` |
| `CsrfAdapterInterface` | Admin UI | `generate(): string`, `validate(): bool` |
| `MailAdapterInterface` | Optional | `send(to, subject, body, isHtml): bool` |
| `MailRecipientResolverInterface` | Optional | `getRecipients(?jobKey): list<string>` |
| `LoggerInterface` | Optional | `debug`, `info`, `warning`, `error` |

## API Reference

| Method | Description |
|--------|-------------|
| `dispatch(): void` | Main dispatcher loop — called from `cron/run.php` every minute |
| `runByKey(string $jobKey): CronTaskResult` | Legacy shim — run a single job by key, bypasses kill switch |
| `getAdminActions(): ?AdminActions` | Returns admin action handler; `null` when admin UI is not configured |
| `isAvailable(): array{enabled: bool, reason: ?string}` | Whether admin UI adapters are fully wired |

## Manifest Format

See [`doc/MANIFEST-FORMAT.md`](doc/MANIFEST-FORMAT.md) for the full schema, validation rules, sync algorithm, re-add semantics, and a 3-job worked example.

Minimal example (`cron/jobs.php`):

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
];
```

Every task class must implement `CronAdmin\Tasks\CronTaskInterface` and have a no-argument constructor:

```php
class BackupTask extends \CronAdmin\Tasks\AbstractCronTask
{
    public function run(): \CronAdmin\Tasks\CronTaskResult
    {
        // ... backup logic ...
        return $this->success('Backup complete');
    }
}
```

## Translations

The module ships `TEXT_CRON_*` and `TEXT_DAY_OF_WEEK_*` keys only. Per-job `TEXT_JOB_*` labels belong in the host's own locale file.

Merge in the host's translation loader — **host keys win on collision**:

```php
$cronMessages = require __DIR__ . '/../lib/CronAdmin/locale/' . $lang . '/messages.php';
$hostMessages = require __DIR__ . '/../locale/' . $lang . '/messages.php';
$messages     = array_merge($cronMessages, $hostMessages);
```

Available locale files: `locale/en_US/messages.php`, `locale/hu_HU/messages.php`.

## License

Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.

## Changelog

See [`CHANGELOG.md`](CHANGELOG.md).
