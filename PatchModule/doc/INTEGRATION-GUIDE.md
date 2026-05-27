# PatchModule v2.1.2 — Integration Guide

Complete recipe for adding the PatchModule admin UI to any PHP MVC project.
After following this guide, you will have replaced ~1,300–1,500 lines of
per-project patch-management code with ~40-line routes + ~80-line adapters +
one sidebar entry + one toast notification include.

---

## Table of Contents

1. [Prerequisites](#1-prerequisites)
2. [Step 1: Sync the module](#2-step-1-sync-the-module)
3. [Step 2: Database schema](#3-step-2-database-schema)
4. [Step 3: Implement adapters](#4-step-3-implement-adapters)
5. [Step 4: Factory wiring](#5-step-4-factory-wiring)
6. [Step 5: Routes](#6-step-5-routes)
7. [Step 6: Sidebar entry](#7-step-6-sidebar-entry)
8. [Step 7: Admin layout toast notification](#8-step-7-admin-layout-toast-notification)
9. [Step 8: Translator wiring](#9-step-8-translator-wiring)
10. [Step 9: Assets](#10-step-9-assets)
11. [Step 10: Security & deployment requirements](#11-step-10-security--deployment-requirements)
12. [Step 11: Removing old per-project code](#12-step-11-removing-old-per-project-code)
13. [Step 12: Verification checklist](#13-step-12-verification-checklist)
14. [Troubleshooting](#14-troubleshooting)
15. [Upgrade notes (v1.4.0 → v1.5.0)](#15-upgrade-notes-v140--v150)
16. [Upgrade notes (v1.5.x → v1.6.0)](#16-upgrade-notes-v15x--v160)
17. [Upgrade notes (v1.6.0 → v1.6.1)](#17-upgrade-notes-v160--v161)
18. [Upgrade notes (v1.6.1 → v1.6.2)](#18-upgrade-notes-v161--v162)
19. [Upgrade notes (v1.6.2 → v1.6.3)](#19-upgrade-notes-v162--v163)
20. [Upgrade notes (v1.6.3 → v1.6.4)](#20-upgrade-notes-v163--v164)
21. [Upgrade notes (v1.6.4 → v1.7.0)](#21-upgrade-notes-v164--v170)
22. [Upgrade notes (v1.7.0 → v1.8.0)](#22-upgrade-notes-v170--v180)
23. [Upgrade notes (v1.x → v2.0.0)](#23-upgrade-notes-v1x--v200)
24. [Upgrade notes (v2.0.x → v2.1.0)](#24-upgrade-notes-v20x--v210)

---

## 1. Prerequisites

- PHP 8.1+ with extensions: `pdo`, `pdo_mysql`, `curl`, `phar`, `openssl`
- Bootstrap 5 + Bootstrap Icons already loaded in the admin layout
- A writable temp directory (e.g. `storage/temp/`)
- The project's existing auth system must expose: current user's sysadmin
  status, password verification, and the current user's ID

---

## 2. Step 1: Sync the module

```bash
rsync -av --delete reusables/PatchModule/ lib/PatchModule/
```

Add the module namespace to your autoloader. If you use Composer's `psr-4`
section in `composer.json`:

```json
{
  "autoload": {
    "psr-4": {
      "PatchModule\\": "lib/PatchModule/src/"
    }
  }
}
```

Then run `composer dump-autoload`.

If you use a custom autoloader, register the `PatchModule\` namespace to
`lib/PatchModule/src/`.

---

## 3. Step 2: Database schema

Import the schema files in order (FK dependencies):

```bash
mariadb -u root -p your_db < lib/PatchModule/schema/patch_history.sql      # 1: FK target for patch_migrations
mariadb -u root -p your_db < lib/PatchModule/schema/patch_backups.sql      # 2: existing
mariadb -u root -p your_db < lib/PatchModule/schema/patch_migrations.sql   # 3: new in v1.8.0; FK depends on patch_history
```

> **Required DB grants:** The application DB user needs `CREATE` privilege on the project database. For existing installations upgrading from v1.6.x, PatchModule creates `patch_migrations` automatically on first use — this requires `CREATE`. Fresh integrations that run the schema file above do not need runtime `CREATE`.

---

### Upgrading from v1.6.x

No manual SQL step is needed. When a patch with SQL migrations is installed for the first time after upgrading to PatchModule v1.8.0, `PatchMigrator` automatically:

1. Creates `patch_migrations` via `CREATE TABLE IF NOT EXISTS`.
2. Backfills one row per existing `*.sql` file in `database/migrations/` (non-recursive; excludes `.php` files). These rows get `patch_history_id = NULL` to distinguish them from future patch-applied migrations.

This bootstrap runs once. Subsequent installs check the `$bootstrapDone` latch and skip it.

---

### Recovery — `patch_migrations` damaged or out of sync

If `patch_migrations` is dropped or corrupted:

1. `TRUNCATE TABLE patch_migrations;` (or drop and re-create from `schema/patch_migrations.sql`).
2. Trigger any patch install or visit an admin page that instantiates PatchModule — bootstrap re-fires because the table is empty.
3. If `database/migrations/*.sql` no longer reflects what was actually applied (e.g. old migration files deleted from the repo), manually insert the missing rows before the next patch install:
   ```sql
   INSERT IGNORE INTO patch_migrations (filename) VALUES ('2026_01_01_000000_example.sql');
   ```
   Otherwise PatchModule will attempt to re-apply them and may fail.

---

## 4. Step 3: Implement adapters

### AuthAdapter (~60 lines)

```php
<?php
/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * AuthAdapter bridges the project's auth system to PatchModule.
 */

declare(strict_types=1);

namespace App\Services\Patch;

use PatchModule\Contracts\AuthAdapterInterface;

/**
 * AuthAdapter - bridges the host auth system to PatchModule's admin UI
 *
 * @package App\Services\Patch
 */
class AuthAdapter implements AuthAdapterInterface
{
    /**
     * @param callable $getCurrentUser Returns the logged-in user object/array, or null
     */
    public function __construct(private readonly mixed $getCurrentUser)
    {
    }

    /** @inheritDoc */
    public function isSysadmin(): bool
    {
        $user = ($this->getCurrentUser)();
        return $user !== null && (bool) ($user->is_sysadmin ?? $user['is_sysadmin'] ?? false);
    }

    /** @inheritDoc */
    public function verifyPassword(string $plain): bool
    {
        $user = ($this->getCurrentUser)();
        if ($user === null) {
            return false;
        }
        $hash = $user->password ?? $user['password'] ?? '';
        return password_verify($plain, (string) $hash);
    }

    /** @inheritDoc */
    public function getCurrentUserId(): ?int
    {
        $user = ($this->getCurrentUser)();
        $id = $user?->id ?? $user['id'] ?? null;
        return $id !== null ? (int) $id : null;
    }

    /** @inheritDoc */
    public function getUserMap(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }
        // Adjust the query to match your users table and name columns
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT id, CONCAT(lastname, ' ', firstname) AS display_name
               FROM users
              WHERE id IN ($placeholders)"
        );
        $stmt->execute($ids);
        $map = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_OBJ) as $row) {
            $map[(int) $row->id] = $row->display_name;
        }
        return $map;
    }

    /** @inheritDoc */
    public function issueInstallAuthorization(int $ttlSeconds = 86400): string
    {
        $token = bin2hex(random_bytes(32));
        $_SESSION['patch_install_auth'] = [
            'token'   => $token,
            'expires' => time() + $ttlSeconds,
        ];
        return $token;
    }

    /** @inheritDoc */
    public function consumeInstallAuthorization(string $token): bool
    {
        $stored = $_SESSION['patch_install_auth'] ?? null;
        if ($stored === null) {
            return false;
        }
        unset($_SESSION['patch_install_auth']);
        if (($stored['expires'] ?? 0) < time()) {
            return false;
        }
        return hash_equals($stored['token'], $token);
    }
}
```

**Notes:**
- Replace `$user->is_sysadmin` with whatever your user model uses.
- Replace the `getUserMap` query to match your `users` table.
- For Laravel: replace `$_SESSION` with `session()` or the cache driver.
- For Symfony: inject the session service into the adapter.
- JWT-based auth: store the token in the DB or a short-lived cache entry.

### CsrfAdapter (~20 lines)

```php
<?php
/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * CsrfAdapter bridges the project's CSRF system to PatchModule.
 */

declare(strict_types=1);

namespace App\Services\Patch;

use PatchModule\Contracts\CsrfAdapterInterface;

/**
 * CsrfAdapter - wraps the host CSRF token provider
 *
 * @package App\Services\Patch
 */
class CsrfAdapter implements CsrfAdapterInterface
{
    /** @inheritDoc */
    public function getToken(): string
    {
        // Adjust to your framework's CSRF token getter:
        // Laravel:   csrf_token()
        // Slim:      $csrfMiddleware->getTokenNameKey() / getTokenValueKey()
        // Custom:    $_SESSION['csrf_token'] ?? ''
        return (string) ($_SESSION['csrf_token'] ?? '');
    }

    /** @inheritDoc */
    public function validate(string $token): bool
    {
        $stored = (string) ($_SESSION['csrf_token'] ?? '');
        return $stored !== '' && hash_equals($stored, $token);
    }
}
```

---

## 5. Step 4: Factory wiring

Assuming you have a `PatchModuleFactory` singleton class. Adjust the paths
and resolver to match your project:

```php
<?php

use App\Services\Patch\AuthAdapter;
use App\Services\Patch\CsrfAdapter;
use PatchModule\Adapters\Backup\DbAndFilesBackupAdapter;
use PatchModule\Adapters\Version\FileVersionResolver;
use PatchModule\PatchModule;

// Retrieve your auth / user provider from wherever it lives in your project
$getCurrentUser = fn() => Auth::user(); // or $container->get(AuthService::class)->getUser();

$module = new PatchModule([
    // ── Required ────────────────────────────────────────────────────────────
    'get_pdo'          => fn() => $container->get(PDO::class),
    'patch_server_url' => $_ENV['PATCH_SERVER_URL'],
    'license_key'      => fn() => $_ENV['LICENSE_KEY'],
    'version_resolver' => new FileVersionResolver(BASE_PATH . '/version.txt'),
    'root_path'        => BASE_PATH,
    'temp_path'        => BASE_PATH . '/storage/temp',

    // ── Admin UI adapters (required for the admin UI) ───────────────────────
    'auth_adapter'     => new AuthAdapter($getCurrentUser),
    'csrf_adapter'     => new CsrfAdapter(),
    'base_url'         => '/admin/patch-management', // same-origin path, no trailing slash

    // ── Optional translator (omit to use module's built-in en_US locale) ────
    // 'translator'    => new MyTranslatorAdapter($container->get(Translator::class)),

    // ── Optional: enable backups ─────────────────────────────────────────────
    // 'backup_adapter' => new DbAndFilesBackupAdapter($pdo, BASE_PATH . '/storage/backups'),

    // ── Optional settings ────────────────────────────────────────────────────
    'check_cache_hours'   => 6,
    'keep_last_snapshots' => 3,
]);
```

---

## 6. Step 5: Routes

Add 10 routes delegating to `AdminActions`. Each is a 5-line pass-through.
The host reads `$r['status']` and `$r['data']`, sets the HTTP status code,
and JSON-encodes the data.

### Slim 4 example

```php
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

$app->group('/admin/patch-management', function ($group) use ($module) {
    $actions = $module->getAdminActions();

    // Index: serve HTML (GET) or JSON (XHR)
    $group->get('', function (Request $req, Response $res) use ($actions, $module) {
        $r = $actions->index();
        if (str_contains($req->getHeaderLine('Accept'), 'application/json')) {
            $res->getBody()->write(json_encode($r['data']));
            return $res->withHeader('Content-Type', 'application/json');
        }
        // Render the view (adjust to your template engine)
        $html = $module->renderView($r['view'], $r['data']);
        $res->getBody()->write($html);
        return $res->withHeader('Content-Type', 'text/html');
    });

    // details without ID = all available patches (used by toast JS)
    $group->get('/details', function (Request $req, Response $res) use ($actions) {
        $r = $actions->index();
        $data = [
            'available'       => !empty($r['data']['patches']),
            'patches'         => $r['data']['patches'],
            'count'           => count($r['data']['patches']),
            'current_version' => $r['data']['currentVersion'],
        ];
        $res->getBody()->write(json_encode($data));
        return $res->withHeader('Content-Type', 'application/json');
    });

    $group->get('/details/{id}', function (Request $req, Response $res, array $args) use ($actions) {
        $r = $actions->details((int) $args['id']);
        $res->getBody()->write(json_encode($r['data']));
        return $res->withStatus($r['status'])->withHeader('Content-Type', 'application/json');
    });

    $group->post('/check', function (Request $req, Response $res) use ($actions) {
        $body = (array) $req->getParsedBody();
        $r = $actions->check($req->getHeaderLine('X-CSRF-Token'));
        $res->getBody()->write(json_encode($r['data']));
        return $res->withStatus($r['status'])->withHeader('Content-Type', 'application/json');
    });

    $group->post('/dismiss', function (Request $req, Response $res) use ($actions) {
        $body = (array) $req->getParsedBody();
        $r = $actions->dismiss((string) ($body['version'] ?? ''), $req->getHeaderLine('X-CSRF-Token'));
        $res->getBody()->write(json_encode($r['data']));
        return $res->withStatus($r['status'])->withHeader('Content-Type', 'application/json');
    });

    $group->post('/dismiss-all', function (Request $req, Response $res) use ($actions) {
        $r = $actions->dismissAll($req->getHeaderLine('X-CSRF-Token'));
        $res->getBody()->write(json_encode($r['data']));
        return $res->withStatus($r['status'])->withHeader('Content-Type', 'application/json');
    });

    $group->post('/verify-password', function (Request $req, Response $res) use ($actions) {
        $body = (array) $req->getParsedBody();
        $r = $actions->verifyPassword((string) ($body['password'] ?? ''), $req->getHeaderLine('X-CSRF-Token'));
        $res->getBody()->write(json_encode($r['data']));
        return $res->withStatus($r['status'])->withHeader('Content-Type', 'application/json');
    });

    $group->post('/install', function (Request $req, Response $res) use ($actions) {
        $body = (array) $req->getParsedBody();
        $r = $actions->install(
            (int)    ($body['patch_history_id'] ?? 0),
            (string) ($body['install_token']    ?? ''),
            (bool)   ($body['create_backup']    ?? true),
            (string) ($body['progress_token']   ?? ''),
            $req->getHeaderLine('X-CSRF-Token')
        );
        $res->getBody()->write(json_encode($r['data']));
        return $res->withStatus($r['status'])->withHeader('Content-Type', 'application/json');
    });

    $group->get('/progress', function (Request $req, Response $res) use ($actions) {
        $token = (string) ($req->getQueryParams()['token'] ?? '');
        $r = $actions->progress($token);
        $res->getBody()->write(json_encode($r['data']));
        return $res->withStatus($r['status'])->withHeader('Content-Type', 'application/json');
    });

    $group->post('/rollback', function (Request $req, Response $res) use ($actions) {
        $body = (array) $req->getParsedBody();
        $r = $actions->rollback((int) ($body['id'] ?? 0), $req->getHeaderLine('X-CSRF-Token'));
        $res->getBody()->write(json_encode($r['data']));
        return $res->withStatus($r['status'])->withHeader('Content-Type', 'application/json');
    });

    // multipart/form-data — CSRF token is a form field, not a header
    $group->post('/upload', function (Request $req, Response $res) use ($actions) {
        $body = (array) ($req->getParsedBody() ?? []);
        $r = $actions->upload(
            (string) ($body['csrf_token'] ?? ''),
            $_FILES
        );
        $res->getBody()->write(json_encode($r['data']));
        return $res->withStatus($r['status'])->withHeader('Content-Type', 'application/json');
    });
});
```

### Laravel example

```php
// routes/web.php (inside admin middleware group)
Route::prefix('admin/patch-management')->name('patch.')->group(function () {
    Route::get('',            [PatchController::class, 'index'])   ->name('index');
    Route::get('details',     [PatchController::class, 'details']) ->name('details');
    Route::get('details/{id}',[PatchController::class, 'detailsId'])->name('details.id');
    Route::post('check',       [PatchController::class, 'check'])  ->name('check');
    Route::post('dismiss',     [PatchController::class, 'dismiss'])->name('dismiss');
    Route::post('dismiss-all', [PatchController::class, 'dismissAll'])->name('dismiss-all');
    Route::post('verify-password',[PatchController::class, 'verifyPassword'])->name('verify-password');
    Route::post('install',    [PatchController::class, 'install']) ->name('install');
    Route::get('progress',    [PatchController::class, 'progress'])->name('progress');
    Route::post('rollback',   [PatchController::class, 'rollback'])->name('rollback');
    Route::post('upload',     [PatchController::class, 'upload'])  ->name('upload');
});
```

```php
// app/Http/Controllers/PatchController.php
class PatchController extends Controller
{
    public function install(Request $request): JsonResponse
    {
        $r = $this->actions()->install(
            (int)    $request->input('patch_history_id', 0),
            (string) $request->input('install_token', ''),
            (bool)   $request->input('create_backup', true),
            (string) $request->input('progress_token', ''),
            $request->header('X-CSRF-Token', '')
        );
        return response()->json($r['data'], $r['status']);
    }

    // multipart/form-data — CSRF token is a form field, not a header
    public function upload(Request $request): JsonResponse
    {
        $r = $this->actions()->upload(
            (string) $request->input('csrf_token', ''),
            $_FILES
        );
        return response()->json($r['data'], $r['status']);
    }
    // … (same pattern for all other actions)
}
```

### Vanilla PHP router example

```php
// In your router's admin route handler:
$actions = $patchModule->getAdminActions();

function patchJsonResponse(array $r): void {
    http_response_code($r['status']);
    header('Content-Type: application/json');
    echo json_encode($r['data']);
    exit;
}

// Dynamic route: GET /admin/patch-management/details/{id}
if ($method === 'GET' && preg_match('#^/admin/patch-management/details/(\d+)$#', $path, $m)) {
    patchJsonResponse($actions->details((int) $m[1]));
}

match ($method . ' ' . $path) {
    'GET /admin/patch-management'           => (/* render HTML or JSON */)(),
    'GET /admin/patch-management/details'   => patchJsonResponse(/* see Step 6 */),
    'POST /admin/patch-management/check'    => patchJsonResponse($actions->check($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')),
    'POST /admin/patch-management/dismiss'  => patchJsonResponse($actions->dismiss((string)($body['version']??''), $_SERVER['HTTP_X_CSRF_TOKEN']??'')),
    'POST /admin/patch-management/dismiss-all' => patchJsonResponse($actions->dismissAll($_SERVER['HTTP_X_CSRF_TOKEN']??'')),
    'POST /admin/patch-management/verify-password' => patchJsonResponse($actions->verifyPassword((string)($body['password']??''), $_SERVER['HTTP_X_CSRF_TOKEN']??'')),
    'POST /admin/patch-management/install'  => patchJsonResponse($actions->install((int)($body['patch_history_id']??0),(string)($body['install_token']??''),(bool)($body['create_backup']??true),(string)($body['progress_token']??''), $_SERVER['HTTP_X_CSRF_TOKEN']??'')),
    'GET /admin/patch-management/progress'  => patchJsonResponse($actions->progress($_GET['token']??'')),
    'POST /admin/patch-management/rollback' => patchJsonResponse($actions->rollback((int)($body['id']??0), $_SERVER['HTTP_X_CSRF_TOKEN']??'')),
    // multipart/form-data — CSRF token is a form field, not a header
    'POST /admin/patch-management/upload'   => patchJsonResponse($actions->upload((string)($body['csrf_token']??''), $_FILES)),
    default => http_response_code(404),
};
```

---

## 7. Step 6: Sidebar entry

Add one `<a>` element guarded by sysadmin, with a badge showing the count of
available patches. The badge is driven by a pre-fetched value to avoid an
extra DB query on every page load (fetch once in the controller).

```php
<?php
// In the layout/sidebar where admin nav is rendered:
$patchCount = 0;
if ($isSysadmin && $module->isAvailable()['enabled']) {
    $patchCount = count($module->getAvailablePatches());
}
?>
<?php if ($isSysadmin): ?>
<li class="nav-item">
    <a class="nav-link <?= $activeSection === 'patch-management' ? 'active' : '' ?>"
       href="/admin/patch-management">
        <i class="bi bi-arrow-up-circle me-2"></i>
        <?= __('TEXT_NAV_PATCH_MANAGEMENT') ?>
        <?php if ($patchCount > 0): ?>
            <span class="badge bg-primary ms-auto"><?= $patchCount ?></span>
        <?php endif; ?>
    </a>
</li>
<?php endif; ?>
```

---

## 8. Step 7: Admin layout toast notification

Add one include anywhere in the admin layout (position in the HTML is irrelevant
because the notification uses `position: fixed`). The toast self-suppresses when
no patches are available or when the module is not configured.

```php
<?php
// At the top of your admin layout (or just before .main-content):
if (isset($isSysadmin) && $isSysadmin) {
    // Pass the logged-in admin's UI language so release notes render in the correct section.
    // Any prefix-matched code works: 'hu', 'hu_HU', 'en', 'en_US'. Null defaults to English.
    $module->setCurrentLanguage($currentAdmin->getLanguage() ?? 'en');

    $availability = $module->isAvailable();
    $patches      = $availability['enabled'] ? $module->getAvailablePatches() : [];
    $actions      = $module->getAdminActions();
    $tr           = $actions ? $actions->getViewTranslator() : fn(string $k, mixed ...$p): string => $k;
    $baseUrl      = $module->getBaseUrl(); // reads the 'base_url' you configured in the factory
    $csrfToken    = $csrfAdapter->getToken();
    $disabled     = !$availability['enabled'];

    include __DIR__ . '/../../../lib/PatchModule/views/admin/_banner.php';
}
?>
```

`$module` here is whatever local variable name your project uses for the
`PatchModule` instance — substitute accordingly.

When the module is not configured (no `auth_adapter` / `csrf_adapter`),
`$module->isAvailable()['enabled']` is `false` and the toast never renders —
no DB query is executed.

The `_modal.php` partial is included automatically by `_banner.php` via a
once-guard, so it will render only once even when both the toast and the
index page include it on the same request. `_modal.php` does **not** require
`$baseUrl` in scope — no host action is needed for it.

---

## 9. Step 8: Translator wiring

### Option A (recommended): implement TranslatorInterface

```php
<?php

declare(strict_types=1);

namespace App\Services\Patch;

use PatchModule\Contracts\TranslatorInterface;

/**
 * Bridges the host translator to PatchModule.
 */
class PatchTranslatorAdapter implements TranslatorInterface
{
    public function __construct(private readonly \App\Services\Translator $t)
    {
    }

    public function t(string $key, array $params = []): string
    {
        return $params
            ? vsprintf($this->t->get($key), $params)
            : $this->t->get($key);
    }
}
```

Pass it as `'translator' => new PatchTranslatorAdapter($translator)` in the
factory config and ensure all `TEXT_*` keys from `lib/PatchModule/locale/en_US/messages.php`
are merged into your project's locale files.

### Option B: merge locale arrays at boot

```php
// In your bootstrap or locale-loading code:
$moduleLocale = require BASE_PATH . '/lib/PatchModule/locale/' . $lang . '/messages.php';
$appLocale    = array_merge($appLocale, $moduleLocale); // module keys take precedence
```

### Option C: use module's built-in fallback (simplest)

Omit the `translator` config key entirely. The module reads its own
`locale/en_US/messages.php` file and all UI strings appear in English.
Switch to Option A or B when you need localized output.

### `AdminActions::getViewTranslator()`

Module views call `$tr` with variadic positional arguments:
`$tr('TEXT_KEY', $param1, $param2)`. This is incompatible with a raw
`TranslatorInterface::t($key, array $params)` callable. `AdminActions` bridges
the two signatures internally. When embedding module views directly from your
own layout (e.g. the toast snippet above), use `getViewTranslator()` to get
the same bridge closure rather than building it yourself:

```php
$tr = $actions->getViewTranslator();
// Now safe to include any module view that calls $tr(...)
include __DIR__ . '/../../../lib/PatchModule/views/admin/_banner.php';
```

The method returns `fn(string $k, mixed ...$p): string => $this->t($k, ...$p)`
and can be called multiple times — each call returns an equivalent closure.

---

## 10. Step 9: Assets

Add the module's CSS and JS to your admin layout. Both files must be served
from your public directory. Either symlink them or copy them to `public/`:

```bash
cp lib/PatchModule/css/patch-update.css public/css/patch-update.css
cp lib/PatchModule/js/patch-update.js   public/js/patch-update.js
```

In your admin layout:

```html
<!-- In <head> (after Bootstrap CSS): -->
<link rel="stylesheet" href="/css/patch-update.css?v=<?= APP_VERSION ?>">

<!-- Before </body> (after Bootstrap JS): -->
<script src="/js/patch-update.js?v=<?= APP_VERSION ?>"></script>
```

The JS file has no global side-effects during load. It initializes by reading
`data-*` attributes from `#patch-mount` or `#patchUpdateBanner` on
`DOMContentLoaded`.

#### Host notification bridge (required for toasts)

`patch-update.js` calls `showNotification(message, type)` to surface toast
messages to the user. The file ships a console-only fallback — you must define
`window.showNotification` **before** `patch-update.js` loads for toasts to
actually appear.

Type values used by the module: `'info'`, `'success'`, `'warning'`, `'error'`.
Map `'error'` to your framework's `'danger'` class if needed:

```js
// In your shared JS file (loaded before patch-update.js):
if (typeof window.showNotification !== 'function') {
    window.showNotification = function (message, type) {
        var mapped = type === 'error' ? 'danger' : (type || 'danger');
        window.showAlert('', message, mapped); // adapt to your toast API
    };
}
```

---

## 11. Step 10: Security & deployment requirements

### CSP

No `unsafe-inline` is required. The module ships no inline `<script>` or
`<style>` blocks. A strict `Content-Security-Policy` is fully compatible:

```
Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self';
```

### PHP-FPM timeout

The install step can take several minutes. Ensure your FPM pool allows it:

```ini
; /etc/php/8.x/fpm/pool.d/www.conf
request_terminate_timeout = 600
```

The module calls `set_time_limit(0)` and `ignore_user_abort(true)` inside
`AdminActions::install()`, but PHP-FPM's `request_terminate_timeout` is
enforced externally and overrides `set_time_limit`.

### php.ini upload limits (manual upload)

The manual upload endpoint accepts files up to `max_upload_size` (default 100 MB). PHP's own
limits must be at least as large, or the upload will be rejected before it reaches the module:

```ini
; /etc/php/8.x/fpm/php.ini  (or php.ini for CLI)
upload_max_filesize = 100M
post_max_size       = 101M   ; must be larger than upload_max_filesize
```

Restart PHP-FPM after changing these values. If your deployment enforces smaller limits for other
routes, apply the overrides only to the `/upload` route via `.htaccess` or nginx's `fastcgi_param`.

### Rate-limiting verify-password and upload

The `verify-password` endpoint must be throttled to prevent brute-force
attacks. The `upload` endpoint should also be rate-limited to prevent
resource exhaustion from repeated large uploads. Sample middleware for Slim:

```php
use Slim\Routing\RouteCollectorProxy;

// Apply to the verify-password route only
$app->post('/admin/patch-management/verify-password', $handler)
    ->add(new RateLimitMiddleware(maxAttempts: 5, windowSeconds: 60));

// Apply to the upload route
$app->post('/admin/patch-management/upload', $handler)
    ->add(new RateLimitMiddleware(maxAttempts: 5, windowSeconds: 60));
```

For Laravel: use `throttle:5,1` on both routes.

### CSRF token contract

The module's JS client (`patch-update.js`) reads the CSRF token once at page
load and reuses it across the entire install flow. Every successful mutating
response (`check`, `dismiss`, `dismissAll`, `verifyPassword`, `install`,
`rollback`) includes a `csrf_token` field in the JSON body so the client can
update its copy before the next request.

**Default (single-token) mode** — implement only `CsrfAdapterInterface`:

Your `validate()` MUST NOT rotate the token. The same token is valid for the
lifetime of the admin session. The module returns it in every mutating response
as a no-op update. This is the correct mode for most simple session-token
implementations.

**Rotating mode** — additionally implement `CsrfRotatableInterface`:

```php
use PatchModule\Contracts\CsrfAdapterInterface;
use PatchModule\Contracts\CsrfRotatableInterface;

class PatchCsrfAdapter implements CsrfAdapterInterface, CsrfRotatableInterface
{
    public function getToken(): string
    {
        return $_SESSION['csrf_token'] ?? '';
    }

    public function validate(string $token): bool
    {
        // MUST NOT rotate here — rotation happens only in rotate()
        return hash_equals($_SESSION['csrf_token'] ?? '', $token);
    }

    public function rotate(): string
    {
        $new = bin2hex(random_bytes(32));
        $_SESSION['csrf_token'] = $new;
        return $new;
    }
}
```

The module calls `rotate()` exactly once per successful mutating action and
returns the new token to the client.

> ⚠️ **Do not rotate in `validate()`** when implementing `CsrfRotatableInterface`.
> If your existing `validateCsrf(rotate: true)` pattern rotates on every call,
> refactor it to a non-rotating `validate()` before using the optional interface.
> Rotating in both places means the token is renewed twice per request and the
> client token becomes stale immediately after the first call.

### Session lifecycle during install

Host controllers that call `session_write_close()` before delegating to `$actions->install()` — to keep `GET /progress` polls unblocked during the long-running install — **must** guard any subsequent `session_start()` with `session_status() === PHP_SESSION_NONE`. A session-aware adapter (e.g. `LicenseModule\NativeSessionAdapter`) accessed during the install may lazily re-open the session, so by the time `install()` returns the session can already be active again. Calling `session_start()` unconditionally at that point emits a PHP Notice.

The canonical post-install block for persisting a rotated CSRF token:

```php
if (isset($r['data']['csrf_token']) && is_string($r['data']['csrf_token'])) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['csrf_token'] = $r['data']['csrf_token'];
    session_write_close();
}
```

### Single-node deployment / sticky sessions

Progress is tracked via a JSON file in `temp_path`. In a multi-node setup,
the polling request must reach the **same node** as the install request.
Use sticky sessions in your load balancer during installs, or point
`temp_path` to a shared network filesystem (NFS, EFS).

### Opcache invalidation

The module calls `opcache_invalidate()` on each modified PHP file after
installation. If your deployment uses a separate opcache (e.g. via FPM
workers across multiple servers), a manual opcache reset may be necessary
after the install completes.

---

## 12. Step 11: Removing old per-project code

Once the module integration is verified, delete the old per-project files.

### TrafficJournal

| Delete | Replaced by |
|--------|-------------|
| `app/controllers/PatchController.php` | `lib/PatchModule/views/admin/` + adapter routes |
| `app/views/admin/settings/patch-management/` | `lib/PatchModule/views/admin/` |
| `public/js/patch-update.js` | `lib/PatchModule/js/patch-update.js` |

Locale keys to remove from `locale/{lang}/messages.php` (if they now come
from the module locale): all `TEXT_PATCH_*`, `TEXT_ACTION_*_PATCH`, and
`TEXT_BUTTON_*` keys that duplicate the module's set.

### JupitERP

| Delete | Replaced by |
|--------|-------------|
| `app/controllers/PatchController.php` | adapter routes |
| `app/views/partials/patch_update_banner.php` | `lib/PatchModule/views/admin/_banner.php` |
| `app/views/partials/patch_update_modal.php` | `lib/PatchModule/views/admin/_modal.php` |
| `public/js/patch-update.js` | `lib/PatchModule/js/patch-update.js` |

Remove the `<link>` tag for any old `patch-update.css` inline blocks — the
module's `css/patch-update.css` replaces them.

### UniCMS

| Delete | Replaced by |
|--------|-------------|
| `app/Controllers/Admin/PatchController.php` | adapter routes |
| `app/Views/admin/patches/index.php` | `lib/PatchModule/views/admin/index.php` |
| `assets/js/patch-update.js` | `lib/PatchModule/js/patch-update.js` |

---

## 13. Step 12: Verification checklist

Run through these after integration before considering it complete.

**Module setup**
- [ ] Schema imported; `patch_history` and `patch_backups` tables exist
- [ ] Factory wired; `$module->getAdminActions()` returns a non-null instance
- [ ] Sidebar badge shows correct count of available patches for sysadmin
- [ ] Sidebar badge absent for non-sysadmin users
- [ ] Banner shows on admin pages for sysadmin when patches available
- [ ] Banner absent when no patches available
- [ ] Banner absent when `isAvailable()['enabled'] === false` (no DB query in that path)

**Install flow**
- [ ] "View Details" / "Install" opens modal with version info and release notes
- [ ] Backup checkbox visible on first patch; hidden on subsequent queue patches
- [ ] Password modal accepts correct password, rejects wrong password
- [ ] Progress modal shows 9 steps animating in real time
- [ ] Progress bar reaches 100% on success; turns red on failure
- [ ] Success state shows "Reload page" button
- [ ] Reload restores the page to updated state

**Multi-patch queue**
- [ ] With 2+ patches: queue panel shows all patches with statuses
- [ ] "Install next" button appears after first patch completes
- [ ] "Install next" installs without re-entering password
- [ ] "All N done" message after all patches installed

**Error handling**
- [ ] Simulate CSRF mismatch: POST without `X-CSRF-Token` → 403 response
- [ ] Non-sysadmin POST → 403 on every endpoint
- [ ] Concurrent install attempt → 409 with `install_in_progress` error code
- [ ] Error label from `data-error-labels` is shown in the modal on failure

**Rollback**
- [ ] "Rollback" button present on `completed` rows only
- [ ] Confirm dialog appears before rollback
- [ ] Page reloads after successful rollback; row status changes to `rolled_back`

**Dismiss**
- [ ] Toast does not render when there are no available patches
- [ ] Per-version dismiss (from the index page) removes the patch from the available list; toast disappears on next page load when no patches remain
- [ ] Dismissed patches do not reappear on reload

**i18n**
- [ ] Switching language (hu_HU / en_US) updates all step, queue, error, and
     button labels without changing any JS file

**CSP**
- [ ] Admin page passes `script-src 'self'` — no console errors about
     `unsafe-inline` or `unsafe-eval`

---

## 14. Troubleshooting

**CSRF mismatch after password verify**
The `verify-password` response includes a refreshed `csrf_token`. The JS
stores it in `PatchUpdate.csrfToken` and uses it for subsequent requests.
If you see 403 on `install`, check that your `CsrfAdapter::validate()` accepts
the rotated token.

**Stuck progress file**
If a PHP-FPM process was killed mid-install, a `patch_progress_*.json` file
may remain in `temp_path`. The file is safe to delete manually. The maintenance
flag (`maintenance.flag` in the module's temp path) may also need to be
removed.

**Maintenance flag not cleared**
If the install process was killed, `MaintenanceMode::disable()` was never
called. Remove the maintenance flag file from `temp_path` manually and verify
the maintenance page is no longer shown to users.

**Opcache showing stale files after install**
Add your compiled cache directories to the `cache_paths_to_clear` config key:

```php
'cache_paths_to_clear' => [BASE_PATH . '/storage/cache/twig'],
```

**Banner not rendering when enabled**
Confirm `$module->isAvailable()['enabled']` is `true`. Common causes: missing
`auth_adapter` or `csrf_adapter` in the factory config, or `patch_server_url`
not set.

**Signature verification disabled**
If you see `signing_unavailable` errors, the patch server's signing service
may be temporarily down. The module retries once automatically. If persistent,
check your `expected_public_key_pem` config value.

---

## 15. Upgrade notes (v1.4.0 → v1.5.0)

### Breaking changes

1. **`AdminActions` is now injected**, not duplicated. Delete per-project
   `PatchController` and all per-project patch views. Replace with the adapter
   + route pattern described in this guide.

2. **Install request body changed.** The JS now sends `patch_history_id` (int)
   instead of `version` (string), and includes `install_token`. Update any
   custom controller that forwarded the old body to `PatchModule::install()`.

3. **No inline `<script>` blocks.** If your layout emits `window.patchStepLabels`
   or similar globals, they are no longer read by the JS. Remove them; all
   config is now delivered via `data-*` attributes on `#patch-mount` or
   `#patchUpdateBanner`.

4. **CSS extracted.** Remove any per-project `patch-update.css` or inline
   `<style>` blocks. Link `lib/PatchModule/css/patch-update.css` instead.

### New features in v1.5.0

- Unified admin views (`index.php`, `_modal.php`, `_banner.php`) — shared
  across all host projects.
- `AdminActions` class with 9 typed methods; each returns `['status', 'data']`
  array.
- `AuthAdapterInterface`, `CsrfAdapterInterface`, `TranslatorInterface`
  contracts.
- CSP-strict: zero inline `<script>` / `<style>` / `onclick=` in module views.
- Multi-patch install authorization via `next_install_token` in install
  response — no re-authentication needed between queued patches.
- `PatchModule::isAvailable()` for zero-cost banner suppression when module
  is not configured.
- `WIRE-FORMAT.md` — frozen HTTP contract for all 9 endpoints.

---

## 16. Upgrade notes (v1.5.x → v1.6.0)

### Breaking changes

1. **`base_url` is now required** when `auth_adapter` and `csrf_adapter` are set. The factory validates it at construction time and throws a descriptive `\InvalidArgumentException` if it is missing or malformed (must be a same-origin path starting with `/`, no trailing slash, no `..`, `?`, `#`, `//`, whitespace, control characters, or percent-encoded sequences). If you integrated without this key in v1.5.x (relying on a hardcoded URL in your layout), add it now:
   ```php
   $module = new PatchModule([
       // ...
       'base_url' => '/admin/patch-management',
   ]);
   ```

2. **Every successful mutating response now includes `csrf_token`.** The responses for `check`, `dismiss`, `dismiss-all`, `install`, and `rollback` all carry a `csrf_token` field (in addition to `verify-password`, which already included it). The JS client reads this value and updates its stored token automatically — no changes needed on the host side unless you have custom code consuming these responses.

### New features in v1.6.0

- **`PatchModule::getBaseUrl(): string`** — use `$module->getBaseUrl()` in your banner layout instead of repeating the literal URL:
  ```php
  $baseUrl = $module->getBaseUrl();
  include __DIR__ . '/../../../lib/PatchModule/views/admin/_banner.php';
  ```

- **`CsrfRotatableInterface`** — optional interface for hosts that rotate the CSRF token on every mutating request. Implement `rotate(): string` alongside `CsrfAdapterInterface`. The module calls `rotate()` exactly once per successful mutating action.
  > **Do not** rotate inside `validate()` when implementing this interface. If your existing pattern rotates in `validate()`, refactor it to a non-rotating `validate()` before adding `CsrfRotatableInterface`.

- **Rollback audit events** — `PatchInstaller` now emits `rollback_patch` and `rollback_patch_failed` via `LoggerInterface::activity()`. No changes needed unless you inspect or filter activity event types.

- **`AdminActions::getViewTranslator()`** — if you embed module views from your own layout (e.g. `_banner.php`), call `$tr = $actions->getViewTranslator()` to get a compatible closure instead of building the bridge yourself.

---

## 17. Upgrade notes (v1.6.0 → v1.6.1)

### Breaking changes

None.

### Action required

**Define `window.showNotification` in your host JS** if you have not done so
already (see [Step 9 — Host notification bridge](#host-notification-bridge-required-for-toasts)). Without it, the new "Your installation is up to date." and "Update check failed." messages fall back to `console.warn` only and are invisible to the sysadmin.

### What changed

- `checkUpdates()` no longer unconditionally reloads the page. It reloads only
  when `data.available === true`. In all other cases it shows a toast and
  re-enables the button.
- Three new translation keys added to both locale files. Existing integrations
  pick them up automatically on the next rsync — no controller or adapter
  changes needed.

---

## 18. Upgrade notes (v1.6.1 → v1.6.2)

### Breaking changes

None.

### Action required

None — `rsync -av --delete reusables/PatchModule/ lib/PatchModule/` and you are done. No controller, adapter, or config changes are needed.

### What changed

- **Action buttons in the available patches table are always enabled.** The "Details" and "Install" buttons were previously disabled whenever no `patch_history` row with status `available` or `downloading` existed (e.g. after a manual cache clear or after a row moved to `failed`/`rolled_back`). The admin index view now self-heals: if no suitable row exists it creates one before rendering. DB failures during self-healing are logged and the page still renders.
- **Update banner now renders with a styled design.** The sticky top banner advertising available updates was previously unstyled because its custom CSS classes (`patch-update-banner`, `patch-banner-inner`, etc.) had no rules. The missing styles have been added: a dark-blue gradient matching the modal header, with responsive stacking on narrow screens.

---

## 19. Upgrade notes (v1.6.2 → v1.6.3)

### Breaking changes

None.

### Action required

After rsync, the host picks up the updated `locale/en_US/messages.php` and `locale/hu_HU/messages.php` files — the new `TEXT_PATCH_ERROR_REQUEST_FAILED` key is included automatically if the host loads or merges the module locale files directly (see README §Translations). No controller, adapter, config, or wire-format changes are needed.

> **Reminder from v1.6.1:** `window.showNotification(message, type)` must be defined in the host JS (see [Step 9 — Host notification bridge](#host-notification-bridge-required-for-toasts)). Without it, the new error toasts introduced in this version fall back to `console.warn` only and are invisible to the sysadmin.

### What changed

- **New `PatchUpdate.parseResponse(response)` JS helper.** All five fetch operations (`dismissAll`, `verifyPassword`, `installCurrent`, `checkUpdates`, `dismissPatch`) now route through a unified helper that safely parses JSON, rotates the CSRF token, and returns a normalised `{ok, data, errorMessage}` object. Previously each operation contained its own inline JSON parse and CSRF update; now these happen exactly once per request.
- **Silent failures fixed.** `dismissAll` and `dismissPatch` previously ignored server errors with no user feedback; they now show a localised error toast. `checkUpdates` now re-enables its button on failure (previously it could be left permanently disabled via a second code path). `verifyPassword` replaces the hardcoded English "Verification failed" string with the i18n fallback.
- **New `TEXT_PATCH_ERROR_REQUEST_FAILED` translation key** added to both `locale/en_US/messages.php` and `locale/hu_HU/messages.php`. It is exposed as `genericError` in the `data-i18n` JSON on `#patch-mount` in `views/admin/index.php`. The JS client uses it as a localised fallback toast whenever a generic network or server error occurs.

---

## 20. Upgrade notes (v1.6.3 → v1.6.4)

### Breaking changes

None.

### Action required

None — `rsync -av --delete reusables/PatchModule/ lib/PatchModule/` and you are done. No controller, adapter, config, or wire-format changes are needed.

### What changed

- **`_banner.php` null-safety guard** — the early-return check now uses `($disabled ?? false)` instead of `$disabled`. This prevents a PHP notice when the variable is not defined in the including scope. Hosts that already set `$disabled` before including the banner are unaffected.
- **README API reference corrected and expanded** — `install()` and `rollback()` signatures updated; new Admin UI and Accessors method tables added; `invalid_manifest_schema` and `verification_failed` error codes documented.

---

## 21. Upgrade notes (v1.6.4 → v1.7.0)

### Breaking changes

None. All existing adapters, routes, and config keys continue to work unchanged.

### New route

Add one route for the manual upload endpoint. See [Step 5: Routes](#6-step-5-routes) for the
framework-specific snippets (Slim 4, Laravel, vanilla PHP). The route accepts
`multipart/form-data`; the CSRF token is a form field (`csrf_token`), not a request header.

### New php.ini limits (if manual upload is used)

Raise PHP's upload limits to match `max_upload_size` (default 100 MB):

```ini
upload_max_filesize = 100M
post_max_size       = 101M
```

See [Step 10 — php.ini upload limits](#phpini-upload-limits-manual-upload) for details.

### New config key (optional)

| Key             | Default              | Purpose                               |
|-----------------|----------------------|---------------------------------------|
| `max_upload_size` | `104857600` (100 MB) | Maximum `.tgz` size for manual upload |

### Action required

1. **Add the `/upload` route** — see [Step 5](#6-step-5-routes).
2. **Raise php.ini limits** if you plan to use manual upload.
3. `rsync -av --delete reusables/PatchModule/ lib/PatchModule/` as usual.

No adapter, schema, or existing config changes are required.

---

## 22. Upgrade notes (v1.7.0 → v1.8.0)

### Breaking changes

| Area | Change |
|------|--------|
| Wire format | Legacy `migration.sql` at archive root is no longer supported. PatchInstaller v1.8.0 only reads the `migrations/` directory. |
| Manifest | `has_migration` boolean removed. `migrations[]` array is now required (empty array = no SQL migrations). |
| PatchCreator | `-m <file>` flag removed. Use PatchCreator v1.03.00+ which auto-detects migrations from `database/migrations/`. |

Old patch archives built with PatchCreator v1.02.00 or earlier that contain a `migration.sql` at
the archive root will install without running the SQL — the migration step simply finds no
`migrations/` directory and logs INFO `"Patch install: no SQL migrations, skipping"`. Archives
that had no SQL migration at all are unaffected.

### New runtime requirement — `CREATE` privilege

PatchMigrator creates the `patch_migrations` table on first use. The application DB user must
have `CREATE` on the project database:

```sql
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE ON your_db.* TO 'app_user'@'localhost';
```

Fresh integrations that run `schema/patch_migrations.sql` before deploying do not need runtime
`CREATE` for the first install, but do need it for subsequent ones if the table is ever dropped.

### No manual schema step for existing installations

When the first v1.8.0-installed patch runs, PatchMigrator automatically:

1. Executes `CREATE TABLE IF NOT EXISTS patch_migrations (...)`.
2. Backfills one row per `*.sql` file in `database/migrations/` (non-recursive) so that already-applied
   migrations are not re-executed.

No operator action is required.

### Action required

1. **Upgrade PatchCreator to v1.03.00** before building new patch archives.
2. **Grant `CREATE` to the app DB user** if not already present (see above).
3. `rsync -av --delete reusables/PatchModule/ lib/PatchModule/` as usual.
4. Build and install the upgrade patch — the v1.6.4 installer silently skips the missing `migrations/`
   directory; the v1.8.0 code lands in place; bootstrap fires on the next patch install.

---

## 23. Upgrade notes (v1.x → v2.0.0)

### Breaking changes

| Area | Change |
|------|--------|
| Manual upload form | `.sig` file input removed — only the `.tgz` is uploaded |
| `AdminActions` constructor | Parameters `$archiveSignatureVerifier`, `$expectedPublicKeyPem`, `$maxSignatureSize` removed |
| Config keys | `archive_signature_verifier` and `max_signature_size` removed |
| Public accessors | `getArchiveSignatureVerifier()` and `getMaxSignatureSize()` removed from `PatchModule` |
| Classes removed | `ArchiveSignatureVerifierInterface` and `OpenSslArchiveSignatureVerifier` deleted |
| Error codes removed | `upload_invalid_signature`, `upload_missing_pinned_key`, `upload_missing_signature` |
| `expected_public_key_pem` | Now auto-flow only (patch-server key pinning); no longer used by manual upload |
| Manual upload UI | Moved into a Bootstrap accordion, collapsed by default |

### What changed

The manual upload flow no longer verifies a detached `.sig` file. The trust gate for manual upload
is sysadmin authentication + CSRF. The archive source responsibility is communicated via a
rewritten warning that names PatrikMol Solutions Kft. as the only acceptable origin.

### Action required

1. **Remove any `archive_signature_verifier` and `max_signature_size` keys** from your config arrays — they are silently ignored in v1.x but will cause no harm if left; clean them up at your convenience.
2. **Remove any code that calls `getArchiveSignatureVerifier()` or `getMaxSignatureSize()`** on the `PatchModule` instance — these methods no longer exist.
3. **`expected_public_key_pem` is now auto-flow-only** — if you configured it only because manual upload required it, you can remove it. If you use it for patch-server key pinning, keep it.
4. `rsync -av --delete reusables/PatchModule/ lib/PatchModule/` as usual.

---

## 24. Upgrade notes (v2.0.x → v2.1.0)

### Breaking changes

| Area | Change |
|------|--------|
| `DatabaseAdapterInterface` | Two new methods added — custom adapter implementations must implement them |
| `patch_history.status` ENUM | `'obsolete'` value added — run the schema migration before deploying |

### New adapter contract methods

`DatabaseAdapterInterface` gains two methods that all adapter implementations must add:

```php
/**
 * Get version strings of all server-fetched patches currently marked available.
 * @return string[] Version strings
 */
public function findAvailableServerVersions(): array;

/**
 * Mark server-fetched available/downloading patches as obsolete.
 * Only affects rows where patch_server_id IS NOT NULL and status IN ('available','downloading').
 * @param string[] $versions Version strings to mark obsolete
 * @return int Number of rows updated
 */
public function markObsoleteByVersions(array $versions): int;
```

`PdoAdapter` ships a complete implementation. If you use a custom adapter (e.g. a `CallableAdapter` wrapper around your project's ORM), add both methods.

### Schema migration required

Run the migration to extend the `status` ENUM before deploying:

```bash
mariadb -u root -p your_db < lib/PatchModule/schema/migrations/2026_05_13_103450_patch_history_add_obsolete_status.sql
```

Alternatively, run the SQL inline:

```sql
ALTER TABLE `patch_history`
    MODIFY COLUMN `status`
        ENUM('available','downloading','installing','completed','failed','rolled_back','obsolete')
        NOT NULL DEFAULT 'available';
```

### New `obsolete` status

Patches are now automatically marked `obsolete` in two cases:

1. **Yanked from server** — when `checkForUpdates()` fetches a new patch list and a previously available server patch is no longer in the response, it is marked `obsolete`.
2. **Direct file-copy install** — on every `AdminActions::index()` render, any `available` row whose version is `≤` the current application version is marked `obsolete`. This handles deployments where the application version is updated by copying files directly rather than through the patch installer.

Obsolete patches are hidden from the available patches table and displayed in the history table with a strikethrough "Obsolete" badge and a greyed row. The rollback button is never shown for obsolete rows.

### Install button gating

The Install button in the available patches table is now shown **only for the oldest available patch** (patches must be installed in order, oldest first). All other available patches show a Details button and a "Queued" badge. This prevents accidentally installing patches out of order.

### Manual upload dedupe improved

Re-uploading a patch version that already has a `patch_history` row (including rows originally inserted by a server check) now **updates the existing row in place** rather than inserting a duplicate. The row's `patch_server_id` is set to `NULL` to reflect that it is now a manually uploaded patch.

### Locale keys added

Add two new keys to your project's locale files if you merge the module locale manually:

| Key | en_US | hu_HU |
|-----|-------|-------|
| `TEXT_PATCH_HISTORY_STATUS_OBSOLETE` | Obsolete | Elavult |
| `TEXT_LABEL_QUEUED_PATCH` | Queued | Sorban áll |

### New route required

Add the `GET /details/{id}` route for the per-record details endpoint. The Slim 4 and Laravel examples in [Step 5](#6-step-5-routes) already show it. For vanilla PHP routers, add a `preg_match` check before your `match` block (see the vanilla PHP example in Step 5).

### Action required

1. **Run the schema migration** (see above) before deploying.
2. **Add both new methods** to any custom `DatabaseAdapterInterface` implementation.
3. **Add the `GET /details/{id}` route** to the host router (see above).
4. `rsync -av --delete reusables/PatchModule/ lib/PatchModule/` as usual.
