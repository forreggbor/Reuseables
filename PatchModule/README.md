# PatchModule

Framework-agnostic patch management module for PHP applications. Handles checking for updates, downloading patches, installing them (SQL migration + file copy), progress tracking, maintenance mode, and rollback.

## Features

- **Update checking** with configurable cache duration
- **Sequential multi-patch installation** (oldest-first ordering)
- **SHA-256 hash verification** for downloaded patches
- **SQL migration execution** with statement-level parsing
- **Selective file snapshot/rollback** (backs up only affected files)
- **Atomic progress tracking** via JSON files (works during DB unavailability)
- **Maintenance mode** via flag file (no DB dependency)
- **OPcache invalidation** per-file and full reset
- **Manual patch upload** — install patches offline without an internet connection
- **Optional backup integration** (graceful skip if not available)
- **Optional logging** (app log + 6 activity audit events, including rollback outcomes)
- **Optional CSRF rotation** (`CsrfRotatableInterface`) — module returns fresh token on every mutating response
- **Shared hosting support** (PharData fallback for tar extraction)

## Requirements

- PHP 8.1+
- PDO with MySQL/MariaDB
- cURL extension
- Phar extension (for shared hosting tar extraction fallback)

## Installation

Copy the module to your project's library directory:

```bash
rsync -av --delete /path/to/reusables/PatchModule/ /path/to/project/lib/PatchModule/
```

Import the database schema (order matters — `patch_migrations` has a FK to `patch_history`):

```bash
mysql -u user -p database < lib/PatchModule/schema/patch_history.sql
mysql -u user -p database < lib/PatchModule/schema/patch_backups.sql      # if using MysqldumpBackupAdapter
mysql -u user -p database < lib/PatchModule/schema/patch_migrations.sql   # required for SQL migration tracking
```

> **Existing installations:** `patch_migrations` is created automatically on first patch install — no manual schema step required when upgrading from v1.6.x or v1.7.x.

## Quick Start

```php
require_once '/path/to/lib/PatchModule/PatchModule.php';

use PatchModule\PatchModule;

$module = new PatchModule([
    'get_pdo'          => fn() => $pdo,
    'patch_server_url' => 'https://lm.example.com/api/v1',
    'license_key'      => fn() => $licenseKey,
    'version_resolver' => new MyVersionResolver(),
    'root_path'        => '/var/www/project',
    'temp_path'        => '/var/www/project/storage/temp',
]);

// Check for updates
$result = $module->checkForUpdates();
if ($result['available']) {
    echo "Updates available: " . $result['count'];
}

// Get available patches
$patches = $module->getAvailablePatches();

// Install a patch
$result = $module->install($patchHistoryId, true, $userId);
```

## Configuration

| Key                | Type                        | Required | Default        | Description                                |
|--------------------|-----------------------------|----------|----------------|--------------------------------------------|
| `get_pdo`          | `callable():PDO`            | Yes*     | —              | Lazy PDO factory                           |
| `pdo`              | `PDO`                       | Yes*     | —              | Direct PDO instance                        |
| `database_adapter` | `DatabaseAdapterInterface`  | Yes*     | —              | Custom database adapter                    |
| `patch_server_url` | `string`                    | Yes      | —              | Patch server base URL                      |
| `license_key`      | `string\|callable`          | Yes      | —              | License key or callable returning it       |
| `version_resolver` | `VersionResolverInterface`  | Yes      | —              | Application version get/set implementation |
| `root_path`        | `string`                    | Yes      | —              | Project root directory                     |
| `temp_path`        | `string`                    | Yes      | —              | Writable temp directory                    |
| `backup_adapter`   | `BackupAdapterInterface`    | No       | `null`         | Backup service (null = skip backup)        |
| `archive_adapter`  | `ArchiveAdapterInterface`   | No       | auto-detect    | Archive extractor                          |
| `http_client`      | `HttpClientInterface`       | No       | CurlHttpClient | HTTP client                                |
| `logger`           | `LoggerInterface`           | No       | `null`         | Logger (null = silent)                     |
| `auth_adapter`     | `AuthAdapterInterface`      | No†      | `null`         | Host auth bridge (required for admin UI)   |
| `csrf_adapter`     | `CsrfAdapterInterface`      | No†      | `null`         | Host CSRF bridge (required for admin UI)   |
| `base_url`         | `string`                    | No†      | —              | Admin UI base path, e.g. `/admin/patch-management`; same-origin, no trailing slash (required when auth_adapter and csrf_adapter are set) |
| `translator`       | `TranslatorInterface`       | No       | `null`         | Host translator (null = built-in en_US)    |
| `check_cache_hours`       | `int`                          | No       | `6`            | Hours to cache patch check results              |
| `min_disk_space`          | `int`                          | No       | `209715200`    | Minimum free bytes (200 MB)                     |
| `api_timeout`             | `int`                          | No       | `30`           | API request timeout in seconds                  |
| `download_timeout`        | `int`                          | No       | `300`          | Download timeout in seconds                     |
| `default_language`        | `string`                       | No       | `'en'`         | Maintenance page language                       |
| `expected_public_key_pem`    | `string`                              | No       | `null`         | Pinned patch-server public key PEM; auto-flow only — when set, patches whose `public_key` does not match are rejected |
| `signature_verifier`         | `SignatureVerifierInterface`          | No       | OpenSSL impl  | Custom JSON-payload signature verifier (remote installs)                  |
| `max_upload_size`            | `int`                                 | No       | `104857600`    | Maximum `.tgz` size in bytes for manual upload (default 100 MB)           |
| `license_verify_callback`    | `callable`                            | No       | `null`         | Callback to refresh the server-side license check window before download  |
| `cache_paths_to_clear`       | `string[]`                            | No       | `[]`           | Absolute paths to compiled-cache directories (e.g. Twig) cleared after each file-mutation step and after rollback |
| `keep_last_snapshots`        | `int`                                 | No       | `3`            | Number of completed installs whose snapshot and DB backup are retained for rollback; older ones are pruned |

*One of `get_pdo`, `pdo`, or `database_adapter` is required.  
†Required together when using the built-in admin UI (`getAdminActions()`).

## Admin UI

PatchModule ships a complete admin patch-management UI (fixed-position toast
notification, modal, index page, progress tracking, multi-patch queue) that
can be integrated into any PHP MVC project by implementing two thin adapter
classes and adding one route block.

Quick integration summary:

1. Implement `AuthAdapterInterface` and `CsrfAdapterInterface` (see below).
2. Pass them as `auth_adapter`, `csrf_adapter`, and `base_url` in the factory config.
3. Add 10 routes delegating to `$module->getAdminActions()->{method}()`.
4. Include `views/admin/_banner.php` in your admin layout (use `$module->getBaseUrl()` for the `$baseUrl` local variable).
5. Link `css/patch-update.css` and `js/patch-update.js` in your admin layout.

The patch history table shows a **Manual upload** badge in the Status column for patches installed from an uploaded archive rather than from the patch server. Each history row also has a **Show changelog** button that opens a modal with the rendered release notes for that version — populated from the `release_notes.md` file inside the patch archive and stored in `patch_history.release_notes`.

See [`doc/INTEGRATION-GUIDE.md`](doc/INTEGRATION-GUIDE.md) for the full
step-by-step recipe (adapters, route examples for Slim / Laravel / vanilla,
translator wiring, security checklist, migration recipes for TrafficJournal /
JupitERP / UniCMS).

See [`doc/WIRE-FORMAT.md`](doc/WIRE-FORMAT.md) for the frozen HTTP contract
for all 10 endpoints.

## Adapter Interfaces

### DatabaseAdapterInterface

Manages patch history records and settings cache. The default `PdoAdapter` uses `patch_history` and `patch_settings` tables. Projects with existing settings infrastructure can implement a custom adapter.

### HttpClientInterface

Handles JSON API requests and file downloads. Default: `CurlHttpClient`.

### ArchiveAdapterInterface

Extracts .tar.gz archives. Auto-detected: `ExecTarAdapter` (shell exec) or `PharTarAdapter` (pure PHP fallback).

### BackupAdapterInterface (optional)

Pre-patch backup creation and rollback. If not provided, backup/restore steps are skipped and only file-snapshot rollback is available.

The module ships with `MysqldumpBackupAdapter` (in `Adapters/Backup/`) as a ready-to-use implementation. It auto-detects `mariadb-dump` or `mysqldump` for dumps and `mariadb` or `mysql` for restores. It requires the database schema extension in `schema/patch_backups.sql`.

### LoggerInterface (optional)

Application logging (`log()`) and activity audit (`activity()`). If not provided, all logging is silently skipped.

See [`doc/LOGGING.md`](doc/LOGGING.md) for a complete reference of every emitted message and activity event, with implementation guidance.

### VersionResolverInterface

Reads and writes the host application's version. Must be implemented by the host project.

### AuthAdapterInterface (admin UI)

Bridges the host's auth system to the admin UI. Required when using the built-in
admin views and `AdminActions`. Methods: `isSysadmin()`, `verifyPassword()`,
`getCurrentUserId()`, `getUserMap()`, `issueInstallAuthorization()`,
`consumeInstallAuthorization()`. The install authorization methods replace
direct `$_SESSION` writes so any auth backend (Laravel session, Symfony session,
DB-backed token) can be supported.

### CsrfAdapterInterface (admin UI)

Bridges the host's CSRF system. Methods: `getToken()` and `validate(string $token)`.
`validate()` receives the value of the `X-CSRF-Token` request header.

### TranslatorInterface (admin UI, optional)

Optional bridge to the host's translator. Single method: `t(string $key, array $params = []): string`.
When omitted, the module reads its own `locale/en_US/messages.php` file.

## Patch Signature Verification

When the patch server has signing configured, each patch entry in the `/patches/check` response includes a `signature` (base64url-encoded) and a `public_key` (PEM). The module verifies these automatically using the bundled `OpenSslSignatureVerifier`.

### How verification works

1. **Key pinning** — If `expected_public_key_pem` is set in the config, the public key returned for each patch is compared against the pinned value using OpenSSL key details (whitespace-normalised). Patches with a mismatched key are excluded from the cache and logged at WARNING level.
2. **Full cryptographic verification** — When the server response also includes an `exp` field in the patch entry, the module reconstructs the exact canonical payload signed by the server (`patch_id`, `sha256`, `version`, `package_id`, `exp`) and calls `openssl_verify` with SHA-256. Patches that fail are excluded and logged at WARNING level.
3. **Partial data** — The current server release returns `signature` and `public_key` but does not yet include `exp` or `package_id` in patch entries. When `exp` is absent, full cryptographic verification is skipped and a DEBUG message is logged. Key pinning (point 1) remains active and is the primary defence in this configuration.
4. **No signing data** — Patches without `signature`/`public_key` are accepted silently (DEBUG log). This is normal for servers without signing configured.

### Implementation details

- **Algorithm**: RSA-SHA256 (`openssl_sign` / `openssl_verify` with `OPENSSL_ALGO_SHA256`).
- **Signature encoding**: base64url (RFC 4648 §5) — `+` → `-`, `/` → `_`, no padding. Decode by reversing the substitution and padding to a 4-byte boundary before `base64_decode`.
- **Canonical payload**: `json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)` where `$payload = ['patch_id' => int, 'sha256' => string, 'version' => string, 'package_id' => int, 'exp' => int]`.
- **`package_id` source**: top-level `response.data.package.id` field in the check response. Defaults to `0` when absent (current server does not yet return it).
- **`exp` source**: per-patch field in the check response. Absent in the current server release; full verification is skipped when it is missing.

### Pinning the server's public key

```php
$module = new PatchModule([
    // ... required config ...
    'expected_public_key_pem' => file_get_contents('/path/to/server-public.pem'),
]);
```

### Custom verifier

Implement `SignatureVerifierInterface` and pass it as `signature_verifier` in the config to replace the OpenSSL default with your own implementation.

## Download and the Recently-Verified Precondition

Patch servers running v2.8.0 or later require a recent successful license check before serving a download. The check window is configurable on the server (default: 7 days). If the window has expired, the server returns HTTP 403 with error code `license_key_not_recently_verified`.

The module handles this automatically when `license_verify_callback` is configured:

```php
$module = new PatchModule([
    // ... required config ...
    'license_verify_callback' => function () use ($licenseModule) {
        // Trigger a fresh license check against the same server
        $licenseModule->check();
    },
]);
```

- The callback is invoked **before** every download attempt to keep the window fresh proactively.
- If the server still rejects the download (stale window despite the pre-call), the callback is invoked again and the download is retried **once**. If the retry also fails, the error is surfaced normally — no infinite loop.

Without a callback the module behaves as before: a 403 precondition failure causes the installation to fail with an error message.

## API Reference

### Patch Checking

| Method                                        | Returns    | Description                              |
|-----------------------------------------------|------------|------------------------------------------|
| `checkForUpdates(bool $force = false)`        | `array`    | Check server for updates (cached)        |
| `getAvailablePatches()`                       | `array`    | Get non-installed, non-dismissed patches  |
| `getAvailablePatch()`                         | `?array`   | Get first available patch                |
| `dismissPatch(string $version, ?int $userId)` | `void`     | Dismiss a specific version               |
| `dismissAllPatches(?int $userId)`             | `void`     | Dismiss all available patches            |

### Installation

| Method                                                       | Returns | Description                   |
|--------------------------------------------------------------|---------|-------------------------------|
| `install(int $id, bool $backup = true, ?int $userId = null, ?string $language = null)` | `array` | Install patch from patch server end-to-end |
| `installFromUploadedArchive(int $id, string $archivePath, ?int $userId = null, ?string $language = null)` | `array` | Install a manually uploaded patch (skips download step) |
| `rollback(int $patchHistoryId, ?int $userId = null)`         | `array` | Rollback a failed installation|

### Progress Tracking

| Method                                | Returns  | Description                      |
|---------------------------------------|----------|----------------------------------|
| `setProgressFile(string $path)`       | `void`   | Set progress file path           |
| `getInstallProgress(string $token)`   | `?array` | Read progress from file          |
| `deleteProgressFile(string $token)`   | `void`   | Delete progress file             |

### Maintenance Mode

| Method                                                  | Returns  | Description                     |
|---------------------------------------------------------|----------|---------------------------------|
| `enableMaintenanceMode(string $version, ?string $lang)` | `void`   | Enable maintenance mode         |
| `disableMaintenanceMode()`                              | `void`   | Disable maintenance mode        |
| `isMaintenanceModeActive()`                             | `bool`   | Check if active                 |
| `getMaintenanceFlagPath()`                              | `string` | Get flag file path              |

### History

| Method                                                             | Returns  | Description                 |
|--------------------------------------------------------------------|----------|-----------------------------|
| `getHistory()`                                                     | `array`  | Full patch history          |
| `getHistoryRecord(int $id)`                                       | `?array` | Single record by ID         |
| `findHistoryByVersion(string $version, array $statuses)`           | `?array` | Find by version and status  |

**`patch_history.status` values:**

| Status | Meaning |
|--------|---------|
| `available` | Patch is available to install |
| `downloading` | Archive download in progress |
| `installing` | Install pipeline running |
| `completed` | Successfully installed |
| `failed` | Install failed (see `error_message`) |
| `rolled_back` | Changes were rolled back after failure |
| `obsolete` | Patch was yanked from the server or superseded by a direct file-copy install |

### Admin UI

| Method              | Returns                                  | Description                                                        |
|---------------------|------------------------------------------|--------------------------------------------------------------------|
| `getAdminActions()` | `?AdminActions`                          | Admin action handler; null when auth_adapter or csrf_adapter is missing |
| `isAvailable()`     | `array{enabled: bool, reason: string}`   | Whether the admin UI is fully usable                               |
| `getBaseUrl()`      | `string`                                 | Validated admin UI base path; empty string when not configured     |

### Accessors

| Method                          | Returns                              | Description                                     |
|---------------------------------|--------------------------------------|-------------------------------------------------|
| `getDatabase()`                 | `DatabaseAdapterInterface`           | Returns the database adapter                    |
| `getVersionResolver()`          | `VersionResolverInterface`           | Returns the version resolver                    |
| `getProgressTracker()`          | `ProgressTracker`                    | Returns the progress tracker                    |
| `getMaintenanceMode()`          | `MaintenanceMode`                    | Returns the maintenance mode manager            |
| `getMaxUploadSize()`            | `int`                                | Returns the configured max upload size in bytes |
| `getExpectedPublicKeyPem()`     | `?string`                            | Returns the pinned public key PEM, or null      |

### Views

| Method                                       | Returns  | Description                  |
|----------------------------------------------|----------|------------------------------|
| `renderView(string $name, array $data = [])` | `string` | Render a module view template|

## Manual Upload

When a server is offline, has an expired license, or the patch server is unreachable, patches can
be installed by uploading a `.tgz` archive directly via the admin UI. The upload funnels into the
same install pipeline (extract → backup → migrate → copy → verify → cleanup) with the download
step skipped.

### Prerequisites

- PHP `upload_max_filesize` and `post_max_size` must be at least as large as `max_upload_size` (default 100 MB).

### Trust model

The trust gate for manual upload is sysadmin authentication + CSRF validation. Only upload
archives received directly from PatrikMol Solutions Kft. for this specific product and
installation. The admin UI displays a persistent warning to this effect above the upload form.

### Version policy

- **Downgrades** (uploaded version older than current) — rejected with 409 `upload_version_downgrade`.
- **Re-installs** (same version as current, or already installed) — rejected with 409 `upload_version_already_installed`.
- **Version gaps** (patches are available between current and the uploaded version) — allowed with a UI warning that the sysadmin must confirm before proceeding to install.

## Patch Package Structure

A patch package is a `.tgz` archive with the following structure:

```
manifest.json      # Required: version, files list, migrations list
release_notes.md   # Optional: Markdown release notes (sliced from CHANGELOG.md by PatchCreator)
migrations/        # Optional: SQL migration files (omitted when none)
  ├── 2026_05_11_151403_create_foo.sql
  └── 2026_05_11_151503_add_bar.sql
files/             # Optional: files to copy to project root
  ├── app/
  ├── lib/
  └── locale/
```

### manifest.json

```json
{
    "version": "2.31.2",
    "migrations": [
        "2026_05_11_151403_create_foo.sql",
        "2026_05_11_151503_add_bar.sql"
    ],
    "files": [
        "app/services/MyService.php",
        "lib/SomeModule/SomeFile.php"
    ],
    "removed_files": [
        "app/legacy/OldService.php"
    ]
}
```

`migrations` is **always present** (empty array when the patch has no SQL migrations). SQL files in the archive's `migrations/` directory are executed in lexicographic (chronological) order by PatchInstaller. Each filename is tracked in `patch_migrations` by filename — re-installing the same patch is a no-op for SQL (already-applied filenames are skipped).

`removed_files` is optional — absent or empty means no deletions. Each listed file is deleted from the project root after new files are copied, with path-traversal protection and pre-deletion backup for rollback.

> **Filesystem note:** PatchInstaller does **not** copy migration files into the project's `database/migrations/` directory. The archive's `migrations/` directory is executed in place and the filenames are tracked in `patch_migrations`. The project's `database/migrations/` is only read during bootstrap (to backfill `patch_migrations` on first use) and is otherwise untouched.

## Installation Pipeline

1. **Preflight checks** — Disk space, writable root, version not already installed
2. **Download patch** — From patch server with SHA-256 verification
3. **Extract patch** — .tgz archive, validate manifest.json (including `migrations[]`); symlink and path-traversal scan rejects unsafe archives
4. **Create backup** — Full DB dump via BackupAdapter, only if `migrations/` directory is present (skipped if no adapter or no migrations)
5. **Execute migration** — Runs each `*.sql` file in `migrations/` in lexicographic order; tracks applied filenames in `patch_migrations`; bootstraps the table on first use
6. **Copy files** — With per-file OPcache invalidation; all paths validated against traversal
7. **Remove obsolete files** — Delete files listed in `manifest.removed_files`; backed-up to snapshot before deletion for rollback
8. **Update version** — Via VersionResolver
9. **Verify installation** — Database connection test
10. **Cleanup** — Remove temp files, delete backup on success

On failure at any step, automatic rollback is attempted (DB from backup + files from snapshot, including restoration of deleted files).

## Maintenance Mode Integration

Add this check early in your application's entry point (before DB initialization):

```php
$flagFile = '/path/to/storage/temp/.patch_maintenance';
if (file_exists($flagFile)) {
    // Allow admin routes and static assets through
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    if (strpos($uri, '/admin/') !== 0 && !preg_match('/\.(css|js|png|jpg|ico)$/', $uri)) {
        http_response_code(503);
        echo $patchModule->renderView('maintenance', ['flagFile' => $flagFile]);
        exit;
    }
}
```

## Error Codes

`PatchInstaller::install()`, `PatchDownloader::download()`, and `PatchChecker::checkForUpdates()` return
an `error_code` key on failure. The full reference is in `doc/reviewed/ERROR-CODES.md`.

| `error_code`              | Cause                                                        |
|---------------------------|--------------------------------------------------------------|
| `not_recently_verified`   | License not checked recently enough — retry after re-verification |
| `invalid_license`         | License key not valid for this product                       |
| `license_revoked`         | License has been revoked                                     |
| `license_expired`         | License has expired (or is in grace period)                  |
| `license_ip_mismatch`     | Download not allowed from this IP address                    |
| `package_mismatch`        | Patch not compatible with installed package                  |
| `rate_limited`            | Too many requests — check `retry_after` for seconds to wait  |
| `signing_unavailable`     | Server signing service temporarily unavailable               |
| `server_error`            | Unexpected server-side error                                 |
| `network_error`           | Could not reach the patch server                             |
| `invalid_archive`         | Archive contained symlinks (path-traversal guard)            |
| `invalid_manifest_path`   | Manifest contained a `..`, absolute, or otherwise unsafe path |
| `invalid_manifest_schema` | Manifest JSON failed schema validation (missing required fields or wrong types) |
| `verification_failed`              | Post-install verification failed — version mismatch or a copied file is missing    |
| `upload_failed`                    | Upload storage failure or lock timeout                                             |
| `upload_invalid_archive`           | Uploaded file is not a valid patch archive                                         |
| `upload_invalid_manifest`          | Uploaded archive has no valid manifest                                             |
| `upload_invalid_mime`              | Uploaded file is not a `.tgz` archive                                              |
| `upload_too_large`                 | Uploaded file exceeds the configured size limit                                    |
| `upload_version_already_installed` | Uploaded version is already installed                                              |
| `upload_version_downgrade`         | Uploaded version is older than the current version                                 |

When `error_code` is `rate_limited`, the `retry_after` key contains the number of seconds to wait
before retrying (from the server's `Retry-After` header), or `null` if the header was absent. The
module does not retry automatically on rate-limit — the caller decides when to reschedule.

## Translations

The module ships with Hungarian (hu_HU) and English (en_US) translations as PHP arrays in `locale/`.
No compilation step is needed.

| File                              | Locale  |
|-----------------------------------|---------|
| `locale/en_US/messages.php`       | English |
| `locale/hu_HU/messages.php`       | Hungarian |

Projects can load translations by including the file and merging the returned array into the project's
own translation map, or by loading it directly as the module-specific message source.

## License

MIT License