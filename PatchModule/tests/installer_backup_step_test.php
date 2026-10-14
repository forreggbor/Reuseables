<?php

declare(strict_types=1);

/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * Regression fixture for Reusables#60 — the pre-patch database backup used to be made only for patches that ship
 * SQL migrations, so a patch without them (whose PHP code may still change data) had no database backup and a
 * rollback could not restore the database, while the progress step still looked done. The backup step now runs
 * for every patch that is installed with the backup option on and a backup adapter present.
 *
 * Plain script, no database: php tests/installer_backup_step_test.php
 */

require_once __DIR__ . '/../src/Contracts/DatabaseAdapterInterface.php';
require_once __DIR__ . '/../src/Contracts/BackupAdapterInterface.php';
require_once __DIR__ . '/../src/Contracts/LoggerInterface.php';
require_once __DIR__ . '/../src/ProgressTracker.php';
require_once __DIR__ . '/../src/PatchInstaller.php';

use PatchModule\Contracts\BackupAdapterInterface;
use PatchModule\Contracts\DatabaseAdapterInterface;
use PatchModule\PatchInstaller;
use PatchModule\ProgressTracker;

$failures = 0;

/**
 * Records one assertion and prints its result.
 *
 * @param string $name Description of what is asserted.
 * @param bool   $ok   Whether it holds.
 * @return void
 */
function check(string $name, bool $ok): void
{
    global $failures;
    echo ($ok ? '  ok   ' : '  FAIL ') . $name . "\n";
    if (!$ok) {
        $failures++;
    }
}

/** Backup adapter that records its calls. */
final class RecordingBackup implements BackupAdapterInterface
{
    /** @var list<array{string, ?int}> */
    public array $created = [];

    public bool $fail = false;

    public function createBackup(string $note, ?int $userId = null): array
    {
        $this->created[] = [$note, $userId];
        return $this->fail ? ['success' => false, 'backup_id' => null, 'error' => 'disk full'] : ['success' => true, 'backup_id' => 42, 'error' => null];
    }

    public function restoreDatabase(int $backupId): array { return ['success' => true, 'error' => null]; }

    public function deleteBackup(int $backupId): array { return ['success' => true, 'error' => null]; }

    public function getFreeDiskSpace(): int { return PHP_INT_MAX; }
}

/** Database adapter that only records history updates. */
final class RecordingDatabase implements DatabaseAdapterInterface
{
    /** @var list<array{int, array<string,mixed>}> */
    public array $updates = [];

    public function getHistoryRecord(int $id): ?array { return null; }
    public function findHistoryByVersion(string $version, array $statuses = ['available', 'downloading']): ?array { return null; }
    public function findLatestHistoryByVersion(string $version): ?array { return null; }
    public function getCompletedVersions(): array { return []; }
    public function getHistory(): array { return []; }
    public function findUploadedAvailablePatches(): array { return []; }
    public function findAvailableServerVersions(): array { return []; }
    public function markObsoleteByVersions(array $versions): int { return 0; }
    public function createHistoryRecord(array $data): int { return 1; }
    public function updateHistoryRecord(int $id, array $data): bool { $this->updates[] = [$id, $data]; return true; }
    public function getSetting(string $key): ?string { return null; }
    public function setSetting(string $key, ?string $value): bool { return true; }
    public function getPdo(): \PDO { throw new \LogicException('not used'); }
}

/**
 * Runs the private backupStep of an installer built without its collaborators.
 *
 * @param RecordingBackup  $backup   Backup adapter.
 * @param RecordingDatabase $database Database adapter.
 * @param array<string,mixed> $ctx    Installation context (returned by reference).
 * @return void
 * @throws \RuntimeException When the step fails.
 */
function runBackupStep(RecordingBackup $backup, RecordingDatabase $database, array &$ctx): void
{
    $ref       = new ReflectionClass(PatchInstaller::class);
    $installer = $ref->newInstanceWithoutConstructor();
    foreach (['backupAdapter' => $backup, 'database' => $database, 'progressTracker' => new ProgressTracker(sys_get_temp_dir()), 'logger' => null] as $prop => $value) {
        $p = $ref->getProperty($prop);
        $p->setAccessible(true);
        $p->setValue($installer, $value);
    }
    $m = $ref->getMethod('backupStep');
    $m->setAccessible(true);
    $m->invokeArgs($installer, [&$ctx]);
}

$baseCtx = ['patchHistoryId' => 7, 'previousVersion' => '1.0.0', 'version' => '1.0.1', 'userId' => 3, 'backupId' => null];

// A patch WITHOUT migrations: the backup is made now.
$backup = new RecordingBackup();
$db     = new RecordingDatabase();
$ctx    = $baseCtx + ['hasMigrationsDir' => false, 'hasMigration' => false];
runBackupStep($backup, $db, $ctx);
check('a patch without migrations gets a database backup', count($backup->created) === 1);
check('the backup is recorded in the context and in the history row', $ctx['backupId'] === 42 && $db->updates === [[7, ['backup_id' => 42]]]);
check('the backup note names both versions and the user is passed on', str_contains($backup->created[0][0], '1.0.0') && str_contains($backup->created[0][0], '1.0.1') && $backup->created[0][1] === 3);

// A patch WITH migrations: unchanged.
$backup = new RecordingBackup();
$db     = new RecordingDatabase();
$ctx    = $baseCtx + ['hasMigrationsDir' => true, 'hasMigration' => true];
runBackupStep($backup, $db, $ctx);
check('a patch with migrations still gets its backup', count($backup->created) === 1 && $ctx['backupId'] === 42);

// A failing backup stops the install.
$backup       = new RecordingBackup();
$backup->fail = true;
$db           = new RecordingDatabase();
$ctx          = $baseCtx + ['hasMigrationsDir' => false];
$message      = null;
try {
    runBackupStep($backup, $db, $ctx);
} catch (\RuntimeException $e) {
    $message = $e->getMessage();
}
check('a failing backup of a migration-less patch aborts the step with the adapter message', $message !== null && str_contains($message, 'disk full') && $db->updates === []);

echo $failures === 0 ? "\nall passed\n" : "\n{$failures} failed\n";
exit($failures === 0 ? 0 : 1);
