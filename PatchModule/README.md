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
- **Optional backup integration** (graceful skip if not available)
- **Optional logging** (app log + activity audit)
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

Import the database schema:

```bash
mysql -u user -p database < lib/PatchModule/schema/patch_history.sql
```

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
| `check_cache_hours`       | `int`                          | No       | `6`            | Hours to cache patch check results              |
| `min_disk_space`          | `int`                          | No       | `209715200`    | Minimum free bytes (200 MB)                     |
| `api_timeout`             | `int`                          | No       | `30`           | API request timeout in seconds                  |
| `download_timeout`        | `int`                          | No       | `300`          | Download timeout in seconds                     |
| `default_language`        | `string`                       | No       | `'en'`         | Maintenance page language                       |
| `expected_public_key_pem` | `string`                       | No       | `null`         | Pinned server public key PEM for key pinning    |
| `signature_verifier`      | `SignatureVerifierInterface`   | No       | OpenSSL impl  | Custom signature verifier implementation        |
| `license_verify_callback` | `callable`                     | No       | `null`         | Callback to refresh the server-side license check window before download |

*One of `get_pdo`, `pdo`, or `database_adapter` is required.

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

### VersionResolverInterface

Reads and writes the host application's version. Must be implemented by the host project.

## Patch Signature Verification

When the patch server has signing configured, each patch entry in the `/patches/check` response includes a `signature` (base64url-encoded) and a `public_key` (PEM). The module verifies these automatically using the bundled `OpenSslSignatureVerifier`.

### How verification works

1. **Key pinning** — If `expected_public_key_pem` is set in the config, the public key returned for each patch is compared against the pinned value using OpenSSL key details (whitespace-normalised). Patches with a mismatched key are excluded from the cache and logged at WARNING level.
2. **Full cryptographic verification** — When the server response also includes an `exp` field in the patch entry, the module reconstructs the exact canonical payload signed by the server (`patch_id`, `sha256`, `version`, `package_id`, `exp`) and calls `openssl_verify` with SHA-256. Patches that fail are excluded and logged at WARNING level.
3. **Partial data** — The current server release returns `signature` and `public_key` but does not yet include `exp` or `package_id` in patch entries. When `exp` is absent, full cryptographic verification is skipped and a DEBUG message is logged. Key pinning (point 1) remains active and is the primary defence in this configuration.
4. **No signing data** — Patches without `signature`/`public_key` are accepted silently (DEBUG log). This is normal for servers without signing configured.

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
| `install(int $id, bool $backup = true, ?int $userId = null)` | `array` | Install patch end-to-end      |
| `rollback(int $patchHistoryId)`                              | `array` | Rollback a failed installation|

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

### Views

| Method                                       | Returns  | Description                  |
|----------------------------------------------|----------|------------------------------|
| `renderView(string $name, array $data = [])` | `string` | Render a module view template|

## Patch Package Structure

A patch package is a `.tgz` archive with the following structure:

```
manifest.json      # Required: version, files list
migration.sql      # Optional: SQL migration statements
files/             # Optional: files to copy to project root
  ├── app/
  ├── lib/
  └── locale/
```

### manifest.json

```json
{
    "version": "2.31.2",
    "files": [
        "app/services/MyService.php",
        "lib/SomeModule/SomeFile.php"
    ],
    "release_notes": "Bug fixes and improvements"
}
```

## Installation Pipeline

1. **Preflight checks** — Disk space, writable root, version not already installed
2. **Download patch** — From patch server with SHA-256 verification
3. **Extract patch** — .tgz archive, validate manifest.json
4. **Create backup** — Full DB dump via BackupAdapter, only if `migration.sql` is present (skipped if no adapter or no migration)
5. **Execute migration** — SQL statements with FK checks disabled
6. **Copy files** — With per-file OPcache invalidation
7. **Update version** — Via VersionResolver
8. **Verify installation** — Database connection test
9. **Cleanup** — Remove temp files, delete backup on success

On failure at any step, automatic rollback is attempted (DB from backup + files from snapshot).

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

## Translations

The module ships with Hungarian (hu_HU) and English (en_US) translations in `locale/`. Projects can either:

1. Use the module's text domain: `bindtextdomain('patch', $modulePath . '/locale')`
2. Merge translation keys into the project's own PO files

## License

MIT License