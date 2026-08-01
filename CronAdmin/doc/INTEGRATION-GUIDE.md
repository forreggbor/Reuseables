# CronAdmin — Integration Guide

Step-by-step recipe for wiring CronAdmin into a host project.

---

## Step 1 — Rsync both reusables

```bash
rsync -av --delete /home/gabor/development/Reusables/CronAdmin/   lib/CronAdmin/
rsync -av --delete /home/gabor/development/Reusables/ActivityLogs/ lib/ActivityLogs/
```

> **DO NOT edit anything under `lib/CronAdmin/` directly.** The `.synced-from-upstream` marker file flags it as vendored — future re-rsyncs (`--delete`) will wipe any local edits. All host customisation belongs in your adapters, manifest, and locale file.

---

## Step 2 — Composer autoload

Add both entries to `composer.json` once:

```json
"autoload": {
    "classmap": ["lib/CronAdmin/"],
    "psr-4": { "ActivityLogs\\": "lib/ActivityLogs/" }
}
```

Run `composer dump-autoload`, then verify:

```bash
php -r "var_dump(class_exists('CronAdmin\\CronAdmin'), class_exists('ActivityLogs\\ActivityLogger'));"
# Expected: bool(true) bool(true)
```

---

## Step 3 — Verify `__()` global function

The module requires a global `__(string $key): string` that **returns the unmodified key** when the key is absent from the merged translation array. The ScheduleFormatter and manifest self-referencing-key fallback both rely on this.

Check in the host bootstrap:
```php
// Must exist before CronAdmin is instantiated.
// Must return the key unchanged on miss — not null, not ''.
function __(string $key, array $params = []): string { /* host implementation */ }
```

---

## Step 4 — Initialise ActivityLogger in BOTH bootstraps

```php
// In HTTP bootstrap (e.g. public/index.php):
\ActivityLogs\ActivityLogger::init($pdo);

// In CLI bootstrap (e.g. cron/run.php):
\ActivityLogs\ActivityLogger::init($pdo);
```

CLI processes have separate static state — omitting the CLI init causes sync audit log writes to fail silently.

---

## Step 5 — Import schema

> **WARNING:** Do NOT run the migration on JupitERP until the CronAdmin integration plan has rewired every `label_key` reference in JupitERP application code. The migration's `CHANGE COLUMN label_key name_key` step breaks live code instantly.

**Greenfield hosts:**
```bash
mysql -u root -p yourdb < lib/CronAdmin/schema/cron_jobs.sql
mysql -u root -p yourdb < lib/ActivityLogs/schema.sql   # if not already imported
```

**Hosts upgrading from JupitERP v2.85.0 table shape:** run the migration AFTER the integration plan completes:
```bash
mysql -u root -p yourdb < lib/CronAdmin/schema/migrations/0001_uplift_from_v2_85_0.sql
```

Both paths reach identical end state. The migration is idempotent — safe to re-run if interrupted.

---

## Timezone behaviour (v1.3.0+)

CronAdmin writes all DATETIME columns explicitly in UTC (`UTC_TIMESTAMP()`), independent of the MariaDB session timezone. The admin UI and AJAX responses convert UTC → display TZ on output. The Scheduler also runs in this display TZ for schedule matching — `hour`/`minute` fields in `cron_jobs` and in the manifest's `default_hour`/`default_minute` are interpreted in `display_timezone`.

Default display TZ is `date_default_timezone_get()`. Override explicitly:

```php
'display_timezone' => 'Europe/Budapest',
```

**Recommended:** set `display_timezone` explicitly in the CronAdmin constructor config — the same value in both the HTTP bootstrap and the CLI bootstrap. PHP-FPM and the CLI commonly have separate `php.ini` files with different `date.timezone` values; relying on the default causes admin display and dispatcher schedule matching to diverge.

System cron's own timezone does not need to match `display_timezone`. Only the `* * * * *` cadence matters; the tick fires at the same wall-clock minute regardless of cron's TZ. PHP boot then reads the configured `display_timezone` for all schedule matching and display.

---

## Step 6 — Create `cron/jobs.php` manifest

> **Security:** `manifest_path` must point to a file in a deploy-user-owned directory that is outside the document root and not writable by the web process. The module `require`s this file on every dispatch tick and admin page load — a writable manifest is unconditional RCE. See `doc/reviewed/SECURITY.md` § Load-bearing trust assumption.

See `doc/MANIFEST-FORMAT.md` for the full schema. Minimal example:

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
    ],
];
```

---

## Step 7 — Create `cron/run.php` CLI bootstrap

```php
<?php
require __DIR__ . '/../vendor/autoload.php';
(new \Dotenv\Dotenv(__DIR__ . '/..'))->load();

$pdo = new PDO(getenv('DB_DSN'), getenv('DB_USER'), getenv('DB_PASS'));
\ActivityLogs\ActivityLogger::init($pdo);

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

---

## Step 8 — Add the crontab entry

```
* * * * * /usr/bin/php8.3 /path/to/project/cron/run.php >> /var/log/cron.log 2>&1
```

**Cadence MUST be `* * * * *`** — the Scheduler assumes 1-minute granularity. A `*/5` cadence silently misses between-tick triggers.

Use the absolute PHP path — cron's `PATH` may not point to PHP 8.3+. On hosts with multiple PHP versions, pin to the right one (e.g. `/usr/bin/php8.3`).

---

## Step 9 — Implement adapters

See `doc/ADAPTER-INTERFACES.md` for per-project examples. You need at minimum:

- `AuthAdapterInterface` — bridge to host auth (`Auth::id()`, `Auth::isSysadmin()`)
- `CsrfAdapterInterface` — reads `$_POST['csrf_token']` and validates against session
- `DispatcherKillSwitchAdapterInterface` — wraps a `system_settings` row or similar
- *(Optional)* `MailAdapterInterface` + `MailRecipientResolverInterface` — needed when any job has `email_report ≠ 'off'`
- *(Optional)* `LoggerInterface` — bridges to host's `app_log()` or PSR-3 logger

---

## Step 10 — Register 6 routes

Apply the host's admin auth middleware to every route. Cast `{id}` to `int` before calling.

```php
// Pseudo-code — adapt to your router syntax.
$router->get('/admin/cron',                 fn() => $actions->index());
$router->post('/admin/cron/dispatcher',     fn() => $actions->toggleDispatcher());
$router->post('/admin/cron/{id}/save',      fn(int $id) => $actions->saveOne($id));
$router->post('/admin/cron/{id}/toggle',    fn(int $id) => $actions->toggle($id));
$router->post('/admin/cron/{id}/run-now',   fn(int $id) => $actions->runNow($id));
$router->get('/admin/cron/{id}/run-status', fn(int $id) => $actions->pollRunStatus($id));
```

`$actions = $cron->getAdminActions();` — returns `null` when admin UI adapters are not configured.

**Response ownership differs by route:**

- **AJAX routes** (`toggleDispatcher`, `saveOne`, `toggle`, `runNow`, `pollRunStatus`) — these call `echo json_encode(...)` and own the full response. Do **not** wrap or buffer their output.
- **`index()`** — outputs a **view fragment** only (no surrounding layout). Your controller must wrap it in the admin layout. The standard pattern is output buffering:

```php
// In your CronController::index():
ob_start();
$actions->index();
$content = ob_get_clean();
// Then render your admin layout with $content embedded.
```

---

## Step 11 — Choose stylesheet

Grep the host's admin layout for Bootstrap presence:
```bash
grep -r "bootstrap.bundle.min.js\|bootstrap.min.css" app/views/partials/head.php
```

- **Bootstrap found** → set `'use_bootstrap' => true` in config, link `lib/CronAdmin/css/cron-admin-bootstrap.css` in admin layout.
- **Bootstrap absent** → keep `'use_bootstrap' => false` (default), link `lib/CronAdmin/css/cron-admin.css`.

The module never auto-detects Bootstrap — the host explicitly opts in.

---

## Step 12 — Link the JS file

Add to admin layout with `defer`:
```html
<script src="/lib/CronAdmin/js/cron-admin.js?v=<?= \CronAdmin\CronAdmin::VERSION ?>" defer></script>
```

Works without Bootstrap JS — the module uses its own lightweight modal and confirm helpers. If the host provides `window.showNotification` / `window.showConfirm`, those are used instead.

---

## Step 13 — Configure asset_base_url (if needed)

The default is `/lib/CronAdmin`. Override if your webroot serves `lib/` from a different path:

```php
'asset_base_url' => '/assets/vendor/CronAdmin',
```

---

## Step 14 — Merge translations

In your translation loader, merge the module's locale array before the host's own locale. **Host keys win on collision:**

```php
$lang = 'en_US'; // or 'hu_HU'
$cronMessages = require __DIR__ . '/../lib/CronAdmin/locale/' . $lang . '/messages.php';
$hostMessages = require __DIR__ . '/../locale/' . $lang . '/messages.php';
$messages     = array_merge($cronMessages, $hostMessages);
```

The module ships only `TEXT_CRON_*` and `TEXT_DAY_OF_WEEK_*` keys. Per-job `TEXT_JOB_*` labels belong in the host's own locale file.

---

## Step 15 — Add audit action keys (JupitERP only)

JupitERP maintains a `config/audit_actions.php` allowlist. Add these entries if you use that file:

```php
'update_cron_jobs', 'enable_cron_job', 'disable_cron_job',
'run_cron_job', 'run_cron_job_manual', 'sync_cron_manifest',
'toggle_cron_dispatcher',
```

Skip this step on hosts without an `audit_actions` config (TrafficJournal, LicenseManager).

---

## Minimal wiring example (HTTP bootstrap)

```php
$cron = new \CronAdmin\CronAdmin([
    'database'               => new \CronAdmin\Adapters\Database\PdoAdapter($pdo),
    'manifest_path'          => __DIR__ . '/../cron/jobs.php',
    'lock_dir'               => __DIR__ . '/../storage/temp/cron',
    'dispatcher_kill_switch' => new MyKillSwitchAdapter($pdo),
    'auth_adapter'           => new MyAuthAdapter(),
    'csrf_adapter'           => new MyCsrfAdapter(),
    'base_url'               => '/admin/cron',
    'asset_base_url'         => '/lib/CronAdmin',
    'use_bootstrap'          => true,   // host uses Bootstrap 5
    'mail_adapter'           => new MyMailAdapter(),
    'recipient_resolver'     => new MyRecipientResolver($pdo),
    'logger'                 => new MyLoggerAdapter(),
]);
```
