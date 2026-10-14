<?php

declare(strict_types=1);

/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * Fixture for Reusables#58/#60 — the install modal must only offer the backup option when a backup adapter
 * exists AND can actually work (PatchModule::describeBackup / getBackupStatus; the built-in adapters report
 * their own health through BackupAdapterHealthInterface).
 *
 * Plain script, no database server (fake dump binaries on PATH, in-memory SQLite):
 *   php tests/backup_status_test.php
 */

require_once __DIR__ . '/../src/Contracts/BackupAdapterInterface.php';
require_once __DIR__ . '/../src/Contracts/BackupAdapterHealthInterface.php';
require_once __DIR__ . '/../src/Contracts/DatabaseAdapterInterface.php';
require_once __DIR__ . '/../src/Contracts/LoggerInterface.php';
require_once __DIR__ . '/../Adapters/Backup/BackupDirectoryCheck.php';
require_once __DIR__ . '/../Adapters/Backup/MysqldumpBackupAdapter.php';
require_once __DIR__ . '/../Adapters/Backup/PdoDumpBackupAdapter.php';
require_once __DIR__ . '/../PatchModule.php';

use PatchModule\Adapters\Backup\MysqldumpBackupAdapter;
use PatchModule\Adapters\Backup\PdoDumpBackupAdapter;
use PatchModule\Contracts\BackupAdapterHealthInterface;
use PatchModule\Contracts\BackupAdapterInterface;
use PatchModule\Contracts\DatabaseAdapterInterface;
use PatchModule\PatchModule;

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

/** Minimal adapter without any health reporting (a custom host adapter). */
final class PlainBackup implements BackupAdapterInterface
{
    public function createBackup(string $note, ?int $userId = null): array { return ['success' => true, 'backup_id' => 1, 'error' => null]; }
    public function restoreDatabase(int $backupId): array { return ['success' => true, 'error' => null]; }
    public function deleteBackup(int $backupId): array { return ['success' => true, 'error' => null]; }
    public function getFreeDiskSpace(): int { return 1; }
}

/** Adapter whose health answer (or exception) is chosen by the test. */
final class HealthBackup implements BackupAdapterInterface, BackupAdapterHealthInterface
{
    public function __construct(private readonly ?array $answer, private readonly ?\Throwable $throw = null) {}
    public function checkOperational(): array { if ($this->throw !== null) { throw $this->throw; } return $this->answer; }
    public function createBackup(string $note, ?int $userId = null): array { return ['success' => true, 'backup_id' => 1, 'error' => null]; }
    public function restoreDatabase(int $backupId): array { return ['success' => true, 'error' => null]; }
    public function deleteBackup(int $backupId): array { return ['success' => true, 'error' => null]; }
    public function getFreeDiskSpace(): int { return 1; }
}

/** Database adapter handing out a given PDO. */
final class PdoOnlyDatabase implements DatabaseAdapterInterface
{
    public function __construct(private readonly \PDO $pdo) {}
    public function getHistoryRecord(int $id): ?array { return null; }
    public function findHistoryByVersion(string $version, array $statuses = ['available', 'downloading']): ?array { return null; }
    public function findLatestHistoryByVersion(string $version): ?array { return null; }
    public function getCompletedVersions(): array { return []; }
    public function getHistory(): array { return []; }
    public function findUploadedAvailablePatches(): array { return []; }
    public function findAvailableServerVersions(): array { return []; }
    public function markObsoleteByVersions(array $versions): int { return 0; }
    public function createHistoryRecord(array $data): int { return 1; }
    public function updateHistoryRecord(int $id, array $data): bool { return true; }
    public function getSetting(string $key): ?string { return null; }
    public function setSetting(string $key, ?string $value): bool { return true; }
    public function getPdo(): \PDO { return $this->pdo; }
}

// ---- PatchModule::describeBackup ----
$none = PatchModule::describeBackup(null);
check('no adapter: not available, with a reason', $none['available'] === false && is_string($none['reason']) && $none['reason'] !== '');
check('a custom adapter without health reporting is assumed to work', PatchModule::describeBackup(new PlainBackup()) === ['available' => true, 'reason' => null]);
check('a healthy adapter is available', PatchModule::describeBackup(new HealthBackup(['ok' => true, 'reason' => null]))['available'] === true);
$sick = PatchModule::describeBackup(new HealthBackup(['ok' => false, 'reason' => 'no dump binary']));
check('a sick adapter is not available and keeps its reason', $sick['available'] === false && $sick['reason'] === 'no dump binary');
$boom = PatchModule::describeBackup(new HealthBackup(null, new \RuntimeException('probe exploded')));
check('a health probe that throws means not available, reason mentions the error', $boom['available'] === false && str_contains((string)$boom['reason'], 'probe exploded'));

// ---- MysqldumpBackupAdapter::checkOperational ----
$work = sys_get_temp_dir() . '/pm_backup_status_' . bin2hex(random_bytes(4));
mkdir($work . '/bin', 0700, true);
foreach (['mariadb-dump', 'mariadb'] as $name) {
    file_put_contents($work . '/bin/' . $name, "#!/bin/sh\nexit 0\n");
    chmod($work . '/bin/' . $name, 0700);
}
putenv('PATH=' . $work . '/bin:' . getenv('PATH'));

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$dir = $work . '/backups';
mkdir($dir, 0700);

$adapter = new MysqldumpBackupAdapter($pdo, $dir, 'h', 'd', 'u', 'p');
check('MysqldumpBackupAdapter is health-aware', $adapter instanceof BackupAdapterHealthInterface);
$r = $adapter->checkOperational();
check('without the patch_backups table the adapter is not operational', $r['ok'] === false && str_contains((string)$r['reason'], 'patch_backups'));
$pdo->exec('CREATE TABLE patch_backups (id INTEGER PRIMARY KEY, filename TEXT)');
check('with table, binaries and a writable directory it is operational', $adapter->checkOperational() === ['ok' => true, 'reason' => null]);
$badDir = new MysqldumpBackupAdapter($pdo, '/proc/nope/backups', 'h', 'd', 'u', 'p');
$r      = $badDir->checkOperational();
check('an uncreatable backup directory is reported', $r['ok'] === false && str_contains((string)$r['reason'], '/proc/nope/backups'));
$missingButCreatable = new MysqldumpBackupAdapter($pdo, $work . '/not-yet/backups', 'h', 'd', 'u', 'p');
check('a directory that does not exist yet but can be created is fine', $missingButCreatable->checkOperational()['ok'] === true);

// ---- PdoDumpBackupAdapter::checkOperational ----
$pdoAdapter = new PdoDumpBackupAdapter(new PdoOnlyDatabase($pdo), $dir);
check('PdoDumpBackupAdapter is health-aware', $pdoAdapter instanceof BackupAdapterHealthInterface);
$r = $pdoAdapter->checkOperational();
check('the built-in adapter is operational with extensions, directory and a working connection', $r['ok'] === PdoDumpBackupAdapter::isAvailable() && ($r['ok'] || str_contains((string)$r['reason'], 'pdo_mysql')));
$badPdo = new PdoDumpBackupAdapter(new PdoOnlyDatabase($pdo), '/proc/nope/backups');
check('the built-in adapter reports an unusable directory', $badPdo->checkOperational()['ok'] === false);

exec('rm -rf ' . escapeshellarg($work));
echo $failures === 0 ? "\nall passed\n" : "\n{$failures} failed\n";
exit($failures === 0 ? 0 : 1);
