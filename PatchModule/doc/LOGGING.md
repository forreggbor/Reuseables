# PatchModule — Logging Integration Guide

This document covers every logging call emitted by PatchModule so you can build a correct
`LoggerInterface` implementation in the host application.

---

## Architecture

PatchModule never writes to a log table itself. Logging is fully optional and contract-driven:
inject an object that implements `PatchModule\Contracts\LoggerInterface` and the module
forwards all log events to it. If no logger is injected, every log call is silently skipped.

```
PatchModule (PatchInstaller / PatchChecker)
    │
    │  $this->logger->log(...)           ← application / system messages
    │  $this->logger->activity(...)      ← audit trail events
    ▼
LoggerInterface  (your implementation)
    │
    ├── write to host error log
    ├── insert into activity_logs table
    └── or anything else
```

---

## The Interface

**File:** `src/Contracts/LoggerInterface.php`

```php
namespace PatchModule\Contracts;

interface LoggerInterface
{
    public function log(string $message, string $level = 'INFO'): void;

    public function activity(
        string $action,
        string $entityType,
        ?int $entityId,
        ?array $oldValues,
        ?array $newValues,
        ?int $userId = null
    ): void;
}
```

### `log()` parameters

| Parameter | Type     | Description                              |
|-----------|----------|------------------------------------------|
| `$message`| `string` | Human-readable message string            |
| `$level`  | `string` | One of `ERROR`, `WARNING`, `INFO`, `DEBUG` |

### `activity()` parameters

| Parameter     | Type          | Description                                                                 |
|---------------|---------------|-----------------------------------------------------------------------------|
| `$action`     | `string`      | Stable action identifier (see [Activity Events](#activity-events) below)    |
| `$entityType` | `string`      | Always `'patch'` in current version                                         |
| `$entityId`   | `int\|null`   | `patch_history.id` for install events; `null` for dismiss events            |
| `$oldValues`  | `array\|null` | State before the action; `null` when not applicable                         |
| `$newValues`  | `array\|null` | State after the action; always populated                                    |
| `$userId`     | `int\|null`   | ID of the user who triggered the action; `null` for automated actions       |

---

## Activity Events

These are the six audit events emitted via `activity()`. Store them in your activity log table.

### `install_patch`

Emitted after a successful patch installation.

**Source:** `src/PatchInstaller.php:377`

| Field        | Value                                 |
|--------------|---------------------------------------|
| `action`     | `install_patch`                       |
| `entityType` | `patch`                               |
| `entityId`   | `patch_history.id` of the record      |
| `oldValues`  | `['version' => '<previous_version>']` |
| `newValues`  | `['version' => '<installed_version>']`|
| `userId`     | user who clicked Install, or `null`   |

**Example:**
```
action:     install_patch
entityType: patch
entityId:   42
oldValues:  {"version": "1.4.0"}
newValues:  {"version": "1.5.0"}
userId:     7
```

---

### `install_patch_failed`

Emitted when installation fails (with or without a successful rollback).

**Source:** `src/PatchInstaller.php:710`

| Field        | Value                                                                   |
|--------------|-------------------------------------------------------------------------|
| `action`     | `install_patch_failed`                                                  |
| `entityType` | `patch`                                                                 |
| `entityId`   | `patch_history.id` of the record                                        |
| `oldValues`  | `null`                                                                  |
| `newValues`  | `['version' => '<target_version>', 'error' => '<message>', 'rolled_back' => bool]` |
| `userId`     | user who clicked Install, or `null`                                     |

The `rolled_back` boolean is `true` when the automatic rollback succeeded after the failure.

**Example:**
```
action:     install_patch_failed
entityType: patch
entityId:   42
oldValues:  null
newValues:  {"version": "1.5.0", "error": "SQL migration failed at statement 3/5: ...", "rolled_back": true}
userId:     7
```

---

### `rollback_patch`

Emitted after a successful admin-initiated or install-failure rollback.

**Source:** `src/PatchInstaller.php` (success branch of `doRollback()`)

| Field        | Value                                 |
|--------------|---------------------------------------|
| `action`     | `rollback_patch`                      |
| `entityType` | `patch`                               |
| `entityId`   | `patch_history.id` of the record      |
| `oldValues`  | `null`                                |
| `newValues`  | `['version' => '<rolled_back_version>']` |
| `userId`     | user who clicked Rollback, or `null` for automated rollbacks |

**Example:**
```
action:     rollback_patch
entityType: patch
entityId:   42
oldValues:  null
newValues:  {"version": "1.5.0"}
userId:     7
```

---

### `rollback_patch_failed`

Emitted when a rollback fails (database restore or file restore step failed).

**Source:** `src/PatchInstaller.php` (failure branches of `doRollback()`)

| Field        | Value                                                          |
|--------------|----------------------------------------------------------------|
| `action`     | `rollback_patch_failed`                                        |
| `entityType` | `patch`                                                        |
| `entityId`   | `patch_history.id` of the record                               |
| `oldValues`  | `null`                                                         |
| `newValues`  | `['version' => '<target_version>', 'error' => '<message>']`   |
| `userId`     | user who clicked Rollback, or `null` for automated rollbacks   |

**Example:**
```
action:     rollback_patch_failed
entityType: patch
entityId:   42
oldValues:  null
newValues:  {"version": "1.5.0", "error": "File restore failed: snapshot not found"}
userId:     7
```

---

### Dual emission on install failure with automatic rollback

When a patch install fails and the module automatically rolls back, two audit events are emitted:

1. `rollback_patch` or `rollback_patch_failed` — from `doRollback()`, with `userId` carrying the user who started the install
2. `install_patch_failed` — from `handleInstallFailure()`, with the same `userId` and the `rolled_back` boolean in `newValues`

The `rolled_back` boolean in `install_patch_failed` is retained for backwards compatibility. The standalone `rollback_patch[_failed]` events are the canonical audit source for rollback outcomes.

---

### `dismiss_patch`

Emitted when a user dismisses a single patch notification.

**Source:** `src/PatchChecker.php:348`

| Field        | Value                               |
|--------------|-------------------------------------|
| `action`     | `dismiss_patch`                     |
| `entityType` | `patch`                             |
| `entityId`   | `null`                              |
| `oldValues`  | `null`                              |
| `newValues`  | `['version' => '<dismissed_version>']` |
| `userId`     | user who dismissed, or `null`       |

---

### `dismiss_all_patches`

Emitted when a user dismisses all available patch notifications at once.

**Source:** `src/PatchChecker.php:378`

| Field        | Value                                                       |
|--------------|-------------------------------------------------------------|
| `action`     | `dismiss_all_patches`                                       |
| `entityType` | `patch`                                                     |
| `entityId`   | `null`                                                      |
| `oldValues`  | `null`                                                      |
| `newValues`  | `['versions' => ['1.4.1', '1.5.0', ...]]` (all dismissed)  |
| `userId`     | user who dismissed, or `null`                               |

---

## Application Log Messages

These are all messages emitted via `log()`, grouped by source class.
Use them to populate your error log, debug log, or monitoring system.

### PatchInstaller — Install Pipeline

Messages are emitted in pipeline order during a successful installation.

| Level   | Message pattern                                                             | Source line |
|---------|-----------------------------------------------------------------------------|-------------|
| INFO    | `Patch install: starting preflight checks for v{version}`                   | :183        |
| INFO    | `Patch install: downloading patch v{version}`                               | :195        |
| WARNING | `Patch install: license check stale, refreshing and retrying download`      | :215        |
| INFO    | `Patch install: extracting patch`                                           | :236        |
| INFO    | `Patch install: creating pre-patch backup`                                  | :256        |
| INFO    | `Patch install: backup created (ID: {backupId})`                            | :273        |
| INFO    | `Patch install: no migration.sql found, skipping backup`                    | :276        |
| INFO    | `Patch install: executing SQL migration`                                    | :284        |
| INFO    | `Patch install: SQL migration completed ({N} statements)`                   | :294        |
| DEBUG   | `Patch install: no migration.sql found, skipping`                           | :296        |
| INFO    | `Patch install: copying files`                                              | :301        |
| INFO    | `Patch install: {N} files copied`                                           | :315        |
| INFO    | `Patch install: {N} obsolete files removed`                                 | :326        |
| INFO    | `Patch install: updating version to {version}`                              | :336        |
| INFO    | `Patch install: verifying installation`                                     | :344        |
| INFO    | `Patch install: cleaning up`                                                | :354        |
| INFO    | `Patch install: v{version} installed successfully`                          | :386        |
| ERROR   | `Patch install: failed to disable maintenance mode: {message}`              | :401        |

### PatchInstaller — Failure Handling

| Level   | Message pattern                                                                              | Source line |
|---------|----------------------------------------------------------------------------------------------|-------------|
| ERROR   | `Patch install failed: {errorMessage}`                                                       | :678        |
| WARNING | `Could not update patch_history failure status: {message}`                                   | :689        |
| WARNING | `Patch install: attempting rollback (backup: {backupId\|none}, snapshot: yes\|no)`           | :695        |

### PatchInstaller — Rollback

| Level   | Message pattern                                                             | Source line |
|---------|-----------------------------------------------------------------------------|-------------|
| WARNING | `Patch rollback: starting for v{version} (ID: {patchHistoryId})`           | :422        |
| ERROR   | `Patch rollback: failed to disable maintenance mode: {message}`             | :435        |
| ERROR   | `Patch rollback: database restore failed - {error}`                         | :453        |
| INFO    | `Patch rollback: database restored from backup ID {backupId}`               | :456        |
| ERROR   | `Patch rollback: file restore failed - {error}`                             | :460        |
| WARNING | `Patch rollback: could not update status - {message}`                       | :471        |
| INFO    | `Patch rollback completed successfully`                                     | :481        |

### PatchInstaller — Snapshot Pruning

| Level   | Message pattern                                                             | Source line |
|---------|-----------------------------------------------------------------------------|-------------|
| WARNING | `Patch prune: could not load history - {message}`                           | :613        |
| WARNING | `Patch prune: could not clear backup_id for #{id}: {message}`               | :639        |

### PatchChecker — Update Check

| Level   | Message pattern                                                                               | Source line |
|---------|-----------------------------------------------------------------------------------------------|-------------|
| WARNING | `Patch check: no license key provided`                                                        | :130        |
| WARNING | `Patch check: request failed - {error}`                                                       | :148        |
| WARNING | `Patch check: invalid response from server`                                                   | :158        |
| WARNING | `Patch check: signature validation failed for v{version}, excluding from cache`               | :209        |
| INFO    | `Patch check: {N} update(s) available - v{first} to v{last} (current: {version})`            | :228        |
| WARNING | `getAvailablePatches: failed to query completed versions - {message}`                         | :292        |

### PatchChecker — Signature Verification

These are all DEBUG level. Suppress them in production unless you are debugging signature issues.

| Level   | Message pattern                                                                                                            | Source line |
|---------|----------------------------------------------------------------------------------------------------------------------------|-------------|
| DEBUG   | `Patch check: v{version} has no signature (unsigned server)`                                                               | :441        |
| WARNING | `Patch check: v{version} has an unexpected public key (pinning mismatch)`                                                  | :449        |
| DEBUG   | `Patch check: v{version} has exp but package_id is unavailable; cryptographic verification skipped`                        | :463        |
| DEBUG   | `Patch check: v{version} has a signature but no verifier is configured`                                                    | :473        |
| DEBUG   | `Patch check: v{version} signature verified OK`                                                                            | :493        |
| DEBUG   | `Patch check: v{version} signature present but exp missing; cryptographic verification skipped (public-key pinning is the active defense)` | :499 |
| DEBUG   | `Patch check: v{version} has partial signing data (signature without public_key or vice versa), accepting`                 | :508        |

---

## Wiring the Logger

The logger is injected into both `PatchInstaller` and `PatchChecker` via constructor.
`PatchModule.php` (the facade) takes the logger and passes it through internally.

Typical wiring via the facade:

```php
$patchModule = new PatchModule(
    logger: new YourLoggerAdapter($db),
    // ... other adapters
);
```

Direct injection into `PatchInstaller`:

```php
$installer = new PatchInstaller(
    database:        $dbAdapter,
    checker:         $checker,
    // ... other deps
    logger:          new YourLoggerAdapter($db),
);
```

Direct injection into `PatchChecker`:

```php
$checker = new PatchChecker(
    database:     $dbAdapter,
    httpClient:   $httpClient,
    // ... other deps
    logger:       new YourLoggerAdapter($db),
);
```

---

## Minimal Implementation

```php
<?php

declare(strict_types=1);

use PatchModule\Contracts\LoggerInterface;

class PatchLogger implements LoggerInterface
{
    public function __construct(private \PDO $pdo) {}

    public function log(string $message, string $level = 'INFO'): void
    {
        // Write to host application error log
        error_log("[{$level}] {$message}");
    }

    public function activity(
        string $action,
        string $entityType,
        ?int $entityId,
        ?array $oldValues,
        ?array $newValues,
        ?int $userId = null
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO activity_logs
             (action, entity_type, entity_id, old_values, new_values, user_id, created_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())'
        );
        $stmt->execute([
            $action,
            $entityType,
            $entityId,
            $oldValues !== null ? json_encode($oldValues) : null,
            $newValues !== null ? json_encode($newValues) : null,
            $userId,
        ]);
    }
}
```

---

## Log Level Guidelines

| Level   | When to use                                                                 |
|---------|-----------------------------------------------------------------------------|
| ERROR   | Operation failed; user or operator attention required                       |
| WARNING | Unexpected condition encountered; operation continued or was retried        |
| INFO    | Normal operational milestone (install started, step completed, etc.)        |
| DEBUG   | Diagnostic detail; safe to suppress in production                           |

Recommended minimum production level: **INFO**.  
Set to **DEBUG** only when investigating signature verification or cache behaviour.
