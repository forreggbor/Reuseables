# BackupRestore

Framework-agnostic database and file backup/restore module for PHP 8.3+.
Creates TGZ backup archives (mysqldump + tar), lists/downloads/deletes them,
transfers them to a remote SFTP server, runs them on a schedule via
reusable profiles, and restores them — atomically (temp-database RENAME
swap) or in-place (table-rename fallback), each with automatic rollback on
failure in the common case (see the foreign-key-rebuild exception under
[Status](#status)) — plus a fully self-contained disaster-recovery script
(`standalone/restore.php`) that works even if the rest of your application
is broken.

Built by porting and hardening JupitERP's Backup & Restore feature into a
standalone reusable module, following the same pattern as the sibling
`ActivityLogs` and `PatchModule` reusables.

## Status

This module has been built and verified **standalone** — see
[`tests/README.md`](tests/README.md) and [`tests/harness.php`](tests/harness.php)
for a reproducible end-to-end proof (backup creation, atomic restore,
in-place restore + forced-failure rollback, file restore, audit trail,
standalone CLI restore — 43/43 checks passing against a real MySQL/MariaDB
server). **HTTP/host-controller integration is a separate, deferred step**
— see [`doc/INTEGRATION-GUIDE.md`](doc/INTEGRATION-GUIDE.md).

**Rollback exception:** for an atomic (partial/table-scoped) restore, if the
post-swap foreign-key rebuild step itself fails, the restore does **not**
roll back — the swap has already completed, so the temporary databases are
deliberately left in place for manual recovery instead of being touched
further (`src/RestoreEngine.php`, `TEXT_BACKUP_ROLLBACK_DB_FAILED_MANUAL_RECOVERY`).
Every other failure mode in both restore strategies does roll back
automatically.

## Requirements

- PHP 8.3+
- `ext-pdo`, `ext-openssl`
- MySQL/MariaDB with `mysql`/`mysqldump` CLI (optional — falls back to pure
  PHP via PDO/PharData when `exec()` is unavailable)
- `tar`, `gzip`, `sed`, `rsync` (optional, same pure-PHP fallback)
- [`phpseclib/phpseclib`](https://github.com/phpseclib/phpseclib) `^3.0` for
  SFTP transfer (installed automatically via Composer)
- A sibling copy of the `ActivityLogs` reusable module, autoloadable by the
  host — this module depends on `ActivityLogs\ActivityLogger` directly for
  audit logging (no contract, no adapter — same as `CronAdmin` does)

## Installation

```bash
composer install
```

Then install the module's own database schema (self-contained — no foreign
key to any host `users` table; run in this exact order):

```bash
mysql your_db < schema/backup_remote_servers.sql
mysql your_db < schema/backup_profiles.sql
mysql your_db < schema/backups.sql
```

## Quick start

```php
require 'vendor/autoload.php';
require '/path/to/ActivityLogs/ActivityLogger.php'; // sibling reusable, no Composer package

$backup = new BackupRestore\BackupRestore([
    // Required
    'get_pdo'         => fn() => $pdo,                 // bookkeeping connection
    'db_credentials'  => [
        'host' => 'localhost', 'port' => 3306,
        'database' => 'myapp', 'username' => 'myapp_user', 'password' => '...',
    ],
    'root_path'       => '/var/www/myapp',              // file-backup base
    'storage_path'    => '/var/www/myapp/storage/backup',
    'temp_path'       => '/var/www/myapp/storage/temp',
    'encryption_key'  => $_ENV['ENCRYPTION_KEY'],        // for SFTP credential encryption

    // Optional callables
    'logger'              => fn(string $msg, string $level) => app_log($msg, $level),
    'get_current_user_id' => fn() => Auth::id(),
    'get_user_map'        => fn(array $ids) => User::namesById($ids), // batch: int[] -> array<int,string>

    // Optional: reachability sanity-check tables (see RestoreEngine docblock)
    'critical_tables' => ['users', 'system_settings'],
]);

// Create a backup
$result = $backup->backupEngine()->createBackup(['type' => 'full', 'note' => 'pre-deploy']);

// List backups (creator names resolved via get_user_map — no `users` JOIN)
$backups = $backup->backupEngine()->listBackups();

// Restore (orchestrates lock + maintenance flag + db/files restore + audit)
$result = $backup->restore(backupId: 5, restoreType: 'full', dbNameConfirm: 'myapp');
```

## Architecture

- **`BackupRestore.php`** — facade. Validates config at construction
  (fail-at-boot), lazily builds the engines, exposes `restore()` orchestration
  and `issue/consumeRestoreAuthorization()` for a host's password re-auth gate.
- **`src/BackupEngine.php`** — backup creation, listing, deletion, integrity,
  disk space, statistics, download tokens.
- **`src/RestoreEngine.php`** — the safety-critical part: atomic and in-place
  database restore (both with automatic rollback — see the
  [Status](#status) section for one documented exception), file restore
  (rsync + pre-restore snapshot + rollback), progress tracking.
- **`src/ProfileService.php`** — reusable backup-profile CRUD, scheduling,
  retention cleanup.
- **`src/RemoteService.php`** — SFTP remote-server CRUD and backup transfer
  (via `phpseclib3`).
- **`src/Exec/`** — the `mysqldump`/`mysql`/`tar`/`rsync` execution layer,
  auto-selecting shell commands (fast) or pure-PHP fallback (portable). Zero
  host coupling — takes credentials/paths as plain arguments.
- **`src/Contracts/`** — 4 typed interfaces for cross-cutting/multi-method
  concerns (`TranslatorInterface`, `EncryptorInterface`,
  `MaintenanceGateInterface`, `TokenStoreInterface`). Single-method seams
  (database, logging, current-user, user-name resolution) are plain
  injected **callables**, not contracts — see `BackupRestore.php`'s
  constructor docblock.
- **`standalone/restore.php`** — a byte-independent disaster-recovery
  script; no dependency on the rest of this module or any framework.

## Translations

`locale/{en_US,hu_HU}/messages.php` — 214 `TEXT_*` keys, sorted
alphabetically, `{name}`-style named placeholders (not `%s`/`%d`). Used
automatically when no `translator` is configured; pass a `TranslatorInterface`
to delegate to a host's own i18n system instead.

## License gating (for the deferred integration)

This module ships **license-agnostic**. A host gates it behind a feature key
(e.g. `backup_restore`) using its own license/module system — see
`doc/INTEGRATION-GUIDE.md` for the JupitERP-specific recipe.

## License

MIT
