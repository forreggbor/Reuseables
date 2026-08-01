# Integration Guide (for the deferred host-integration session)

This module was built and verified **standalone**. This guide documents how
a **future session** wires it into a host application (JupitERP is the
reference host) — routing, auth, license gating, and the encryption
byte-compatibility concern. Nothing in this guide has been executed; the
host application is untouched.

## 1. Sync into the host

```bash
rsync -av --delete /home/gabor/development/Reusables/BackupRestore/ lib/BackupRestore/
```

Register the classmap in the host's root `composer.json` (mirrors
`lib/PatchModule`/`lib/ActivityLogs`):

```json
"classmap": [
    "lib/ActivityLogs/",
    "lib/BackupRestore/",
    "lib/CronAdmin/",
    "lib/LicenseModule/",
    "lib/PatchModule/",
    "lib/SzamlazzHuAgent/"
]
```

Run `composer install` inside `lib/BackupRestore/` once (or fold its
`require` — currently only `phpseclib/phpseclib`, already a JupitERP
dependency — into the host's own `composer.json` and drop the module's own
`vendor/`/`composer.lock`).

## 2. Host adapter classes

Create `app/services/BackupRestore/` mirroring `app/services/Patch/`:

| File | Wires to |
|------|----------|
| `BackupModuleFactory.php` | Memoized-per-request factory, clone of `PatchModuleFactory.php` |
| `EncryptorAdapter.php` | Delegates to `App\Helpers\Security::encrypt/decrypt` (see §5 — **do not** use the shipped `OpenSslGcmEncryptor` default) |
| `TranslatorAdapter.php` | Reads `lib/BackupRestore/locale/{lang}/messages.php`, `array_merge(enUS, localized)` — clone of `app/services/Patch/TranslatorAdapter.php`, does **not** touch the global `__()` |
| `MaintenanceGate.php` (optional) | Only needed if the host wants the flag stored somewhere other than `temp_path/.restore_maintenance` (the shipped `FileMaintenanceGate` is fine as-is) |

Example factory config:

```php
new BackupRestore\BackupRestore([
    'get_pdo' => fn() => Database::getInstance()->getConnection(),
    'db_credentials' => [
        'host' => $_ENV['DB_HOST'] ?? 'localhost',
        'port' => (int) ($_ENV['DB_PORT'] ?? 3306),
        'database' => $_ENV['DB_NAME'] ?? '',
        'username' => $_ENV['DB_USER'] ?? '',
        'password' => $_ENV['DB_PASSWORD'] ?? '',
    ],
    'root_path' => ROOT_PATH,
    'storage_path' => STORAGE_PATH . '/backup',
    'temp_path' => STORAGE_PATH . '/temp',
    'encryptor' => new \App\Services\BackupRestore\EncryptorAdapter(),
    'translator' => new \App\Services\BackupRestore\TranslatorAdapter(LocaleResolver::current()),
    'logger' => fn(string $msg, string $level) => app_log($msg, $level),
    'get_current_user_id' => fn() => Auth::id(),
    'get_user_map' => function (array $ids): array {
        $db = Database::getInstance()->getConnection();
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare("SELECT id, first_name, last_name FROM users WHERE id IN ({$placeholders})");
        $stmt->execute($ids);
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_OBJ) as $u) {
            $out[(int) $u->id] = \App\Helpers\Formatter::personName($u->first_name, $u->last_name);
        }
        return $out;
    },
    'critical_tables' => ['users', 'system_settings'],
]);
```

## 3. Controller + routes

Write a thin `BackupController` (~150 LOC target, vs. the original 1127)
whose methods call the facade directly and JSON-encode the result — no
business logic. The password re-auth flow, CSRF, and progress-token session
storage stay host-side:

```php
public function verifyPassword(): void
{
    if (!$this->requireSysadmin() || !$this->validateCsrf()) return;
    $password = $this->input('password') ?? '';
    if (!User::verifyPassword(Auth::id(), $password)) {
        $this->json(['success' => false, 'error' => __('TEXT_ERROR_INVALID_PASSWORD')]);
        return;
    }
    $_SESSION['restore_auth_token'] = $this->backupModule()->issueRestoreAuthorization(Auth::id());
    $this->json(['success' => true]);
}

public function restore(): void
{
    if (!$this->requireSysadmin() || !$this->validateCsrf()) return;
    $token = $_SESSION['restore_auth_token'] ?? '';
    if (!$this->backupModule()->consumeRestoreAuthorization($token, Auth::id())) {
        $this->json(['success' => false, 'error' => __('TEXT_ERROR_RESTORE_AUTH_EXPIRED')]);
        return;
    }
    unset($_SESSION['restore_auth_token']);

    $input = $this->getJsonInput();
    $progressToken = preg_replace('/[^a-zA-Z0-9_]/', '', $input['progress_token'] ?? '');
    if ($progressToken) {
        $_SESSION['restore_progress_token'] = $progressToken; // for the progress-polling route
    }
    session_write_close(); // let the polling endpoint respond concurrently

    $result = $this->backupModule()->restore(
        (int) $input['backup_id'],
        $input['restore_type'] ?? 'full',
        $input['db_name_confirm'] ?? null,
        $progressToken ?: null,
        Auth::id(),
    );
    $this->json($result);
}

public function restoreProgress(): void
{
    // No LicenseMiddleware, no Auth — DB may be mid-swap. Session-token check only.
    $token = preg_replace('/[^a-zA-Z0-9_]/', '', $this->input('token') ?? '');
    if (empty($token) || ($_SESSION['restore_progress_token'] ?? null) !== $token) {
        $this->json(['steps' => []]);
        return;
    }
    $path = $this->backupModule()->progressFilePath($token);
    $this->json(file_exists($path) ? (json_decode(file_get_contents($path), true) ?: ['steps' => []]) : ['steps' => []]);
}
```

Register all routes under `[LicenseMiddleware::class, ModuleMiddleware::require('backup_restore')]`
(see §4) except `restoreProgress` (no middleware — must work mid-restore,
same as the original).

`downloadRestoreScript` serves `lib/BackupRestore/standalone/restore.php`
directly (the module's `__DIR__`-relative path — not `ROOT_PATH . '/restore.php'`
like the original; either keep a copy at project root too, or repoint this
one route).

## 4. License gating — feature key `backup_restore`

The module ships license-agnostic. Gate it exactly like any other addon:

1. **License server** (`lm.patrikmol.com`): add addon `feature_key = backup_restore`
   to the relevant tier/package definitions.
2. **`app/services/ModuleService.php`**:
   - `ADDON_MODULES`: add `'backup_restore' => ['backup_restore'],`
   - `MODULE_NAMES`: add `'backup_restore' => 'TEXT_MODULE_BACKUP_RESTORE',`
     (locale keys `TEXT_NAV_BACKUP_RESTORE`/`TEXT_DESC_BACKUP_RESTORE` already
     exist in JupitERP's own locale files)
3. **Routes**: `[LicenseMiddleware::class, ModuleMiddleware::require('backup_restore')]`
   on all 24 backup routes (`public/index.php`).
4. **Settings card**: wrap with `\App\Services\ModuleService::isEnabled('backup_restore')`.
5. Optional: mirror into `lib/LicenseModule/src/FeatureGate.php`'s `DEFAULT_ADDONS`.

Demo mode (`Environment::isDemo()`) already early-returns `true` in
`ModuleService::isEnabled()`, so `backup_restore` is enabled in demo
automatically — no extra work needed.

## 5. Encryption byte-compatibility (important — do not skip)

JupitERP's `backup_remote_servers.credentials` column holds values written
by `App\Helpers\Security::encrypt()`, which reads/writes a `v2:`-prefixed
AES-256-GCM format **and must keep reading legacy AES-256-CBC blobs** during
the ongoing migration window (see `Security.php`'s own docblock).

The module's shipped default (`Adapters\Crypto\OpenSslGcmEncryptor`) is a
**generic** AES-256-GCM implementation for fresh hosts — it does **not**
read the legacy CBC format. **Do not use it for JupitERP.** Instead, write
`EncryptorAdapter implements BackupRestore\Contracts\EncryptorInterface`
delegating straight to `App\Helpers\Security::encrypt()`/`decrypt()`, so
existing stored SFTP credentials keep working unchanged. No re-encryption,
no migration step.

## 6. Cron

`app/cron/tasks/BackupTask.php` stays a thin `CronAdmin` shim. Replace its
direct `BackupService`/`BackupProfileService`/`BackupRemoteService` calls
with calls through the module facade (`$module->profileService()->...`,
`$module->remoteService()->...`); keep its own `ActivityLog::log` calls for
cron-run bookkeeping (cron is host orchestration, not module concern) and
its `SystemSettingsService::get('backup_manual_retention_days', 0)` read.

## 7. Views

Embed the module's view fragments (`lib/BackupRestore/views/admin/*.php`)
the same way `ActivityLogsAdmin::render()` is embedded — they render a body
fragment, not a full page. Copy `css/backup-restore.css` and
`js/backup-restore.js` to `public/css/`/`public/js/` at deploy time and load
them once per admin page (see `doc/reviewed/` conventions for other
reusables). Pass `$t`, `$baseUrl` (e.g. `/admin/settings/backup-restore`),
and `$csrfToken` as documented in each view file's header docblock.

## 8. Known differences from the original JupitERP feature

- **No `users` JOIN** — `backups.created_by` is a plain nullable INT;
  creator names come from the injected `get_user_map` callable. The schema
  has no FK to `users` — do not add one.
- **`downloadRestoreScript`** serves the module's own bundled copy of
  `restore.php` (already fixed for the stderr/stdout MariaDB
  "Deprecated program name" bug — see `CHANGELOG.md`), not the
  project-root one — decide whether to keep both in sync or retire the
  project-root copy.
- **No generic `handle(action, input)` dispatcher** — the facade exposes
  direct methods (`restore()`, `backupEngine()`, `profileService()`,
  `remoteService()`). The host controller calls these directly rather than
  dispatching through an action-string envelope.
