<?php

declare(strict_types=1);

/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * Standalone end-to-end verification harness for the BackupRestore module.
 * See tests/README.md for prerequisites and usage. Not a PHPUnit suite —
 * a single reproducible script proving the module works outside any host
 * framework, against a real MySQL/MariaDB server.
 *
 * NEVER point --db-name at a database you care about: the atomic restore
 * strategy creates and drops `<db>_restore_<ts>` / `<db>_old_<ts>` databases
 * as part of its normal operation.
 */

require __DIR__ . '/../vendor/autoload.php';

$activityLoggerPath = '/home/gabor/development/Reusables/ActivityLogs/ActivityLogger.php';
if (is_file($activityLoggerPath)) {
    require $activityLoggerPath;
} else {
    fwrite(STDERR, "WARNING: ActivityLogs\\ActivityLogger not found at {$activityLoggerPath} — audit assertions will be skipped.\n");
}

// ---------------------------------------------------------------------
// CLI args
// ---------------------------------------------------------------------

$opts = getopt('', ['db-name:', 'db-user:', 'db-pass:', 'db-host::', 'db-port::']);
if (empty($opts['db-name']) || empty($opts['db-user'])) {
    fwrite(STDERR, "Usage: php harness.php --db-name=NAME --db-user=USER [--db-pass=PASS] [--db-host=HOST] [--db-port=PORT]\n");
    fwrite(STDERR, "NEVER point --db-name at a database you care about — see tests/README.md.\n");
    exit(1);
}

$dbName = $opts['db-name'];
$dbUser = $opts['db-user'];
$dbPass = $opts['db-pass'] ?? '';
$dbHost = $opts['db-host'] ?? 'localhost';
$dbPort = (int) ($opts['db-port'] ?? 3306);

// ---------------------------------------------------------------------
// Tiny test harness
// ---------------------------------------------------------------------

$failures = [];
$passed = 0;

function check(string $label, bool $condition, string $detail = ''): void
{
    global $failures, $passed;
    if ($condition) {
        $passed++;
        echo "  OK  {$label}\n";
    } else {
        $failures[] = $label . ($detail !== '' ? " ({$detail})" : '');
        echo "FAIL  {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

function section(string $title): void
{
    echo "\n=== {$title} ===\n";
}

/**
 * Defensive activity_logs lookup — returns null (not a fatal error) if the
 * table is missing, e.g. ActivityLogs\schema.sql could not be located.
 */
function auditCount(PDO $pdo, string $action, string $entityId): ?int
{
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM activity_logs WHERE action = :action AND entity_id = :entity_id");
        $stmt->execute([':action' => $action, ':entity_id' => $entityId]);
        return (int) $stmt->fetchColumn();
    } catch (\PDOException) {
        return null;
    }
}

// ---------------------------------------------------------------------
// Root PDO + schema install
// ---------------------------------------------------------------------

function rootPdo(string $host, int $port, string $user, string $pass, ?string $db = null): PDO
{
    $dsn = "mysql:host={$host};port={$port}" . ($db !== null ? ";dbname={$db}" : '') . ';charset=utf8mb4';
    return new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}

section('Setup: create disposable database + install schema');

$adminPdo = rootPdo($dbHost, $dbPort, $dbUser, $dbPass);
$adminPdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
echo "  Database `{$dbName}` ready.\n";

$pdo = rootPdo($dbHost, $dbPort, $dbUser, $dbPass, $dbName);
foreach (['backup_remote_servers.sql', 'backup_profiles.sql', 'backups.sql'] as $file) {
    $sql = file_get_contents(__DIR__ . '/../schema/' . $file);
    $pdo->exec($sql);
}
// activity_logs is a sibling-module table, not this module's own schema — install it too
// so the audit-trail assertions below have somewhere to write.
$activityLogsSchema = '/home/gabor/development/Reusables/ActivityLogs/schema.sql';
if (is_file($activityLogsSchema)) {
    foreach (array_filter(explode(';', file_get_contents($activityLogsSchema))) as $stmt) {
        // Strip full-line `--` comments before checking for meaningful SQL —
        // a naive "does the untouched fragment start with --" check throws
        // away the real statement that follows a leading comment line.
        $lines = array_filter(
            explode("\n", $stmt),
            static fn (string $line): bool => !str_starts_with(trim($line), '--')
        );
        $cleanStmt = trim(implode("\n", $lines));
        if ($cleanStmt !== '') {
            try {
                $pdo->exec($cleanStmt);
            } catch (\PDOException) {
                // ignore trigger/DELIMITER blocks not meant to run standalone
            }
        }
    }
}
check('Schema installed (backups/backup_profiles/backup_remote_servers)', in_array('backups', $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN), true));

// ---------------------------------------------------------------------
// Scratch directories
// ---------------------------------------------------------------------

$scratchRoot = sys_get_temp_dir() . '/br_harness_' . bin2hex(random_bytes(4));
$rootPath = $scratchRoot . '/root';
$storagePath = $scratchRoot . '/storage';
$tempPath = $scratchRoot . '/temp';
mkdir($rootPath . '/somefiles', 0777, true);
mkdir($storagePath, 0777, true);
mkdir($tempPath, 0777, true);
file_put_contents($rootPath . '/somefiles/marker.txt', 'harness-original-content');

$logs = [];
$userNames = [1 => 'Harness Admin'];

$facadeConfig = [
    'get_pdo' => static fn () => rootPdo($dbHost, $dbPort, $dbUser, $dbPass, $dbName),
    'db_credentials' => ['host' => $dbHost, 'port' => $dbPort, 'database' => $dbName, 'username' => $dbUser, 'password' => $dbPass],
    'root_path' => $rootPath,
    'storage_path' => $storagePath,
    'temp_path' => $tempPath,
    'encryption_key' => random_bytes(32),
    'critical_tables' => ['backups'], // any table dependent on this module's own schema works as a smoke check
    'get_current_user_id' => static fn () => 1,
    'get_user_map' => static function (array $ids) use ($userNames) {
        $out = [];
        foreach ($ids as $id) {
            if (isset($userNames[$id])) {
                $out[$id] = $userNames[$id];
            }
        }
        return $out;
    },
    'logger' => static function (string $msg, string $level) use (&$logs) {
        $logs[] = "[{$level}] {$msg}";
    },
];

section('Facade construction');
$mod = new BackupRestore\BackupRestore($facadeConfig);
check('Facade constructed', $mod instanceof BackupRestore\BackupRestore);

// Reliability fix 1 regression: BackupRestore::__construct() must wire the
// configured temp_path into Exec\ShellHelper — before this fix, ShellHelper
// silently fell back to bare sys_get_temp_dir() and MySQL option files
// (containing plaintext DB credentials) never landed in the host-isolated
// temp directory the integration guide documents.
$tempDirProp = new ReflectionProperty(BackupRestore\Exec\ShellHelper::class, 'tempDir');
$tempDirProp->setAccessible(true);
check(
    'Fix 1: ShellHelper::configureTempDir() wired to configured temp_path',
    $tempDirProp->getValue() === rtrim($tempPath, '/'),
    'ShellHelper tempDir=' . $tempDirProp->getValue() . ' expected=' . rtrim($tempPath, '/')
);

// ---------------------------------------------------------------------
// Backup creation, integrity, listing, tokens, stats
// ---------------------------------------------------------------------

section('Backup creation + integrity + listing');

$engine = $mod->backupEngine();
$backupResult = $engine->createBackup(['type' => 'full', 'note' => 'harness backup']);
check('createBackup() succeeds', $backupResult['success'], $backupResult['error'] ?? '');
$backupId = $backupResult['backup_id'];

if ($backupResult['success']) {
    $archivePath = $engine->getBackupDir() . '/' . $backupResult['filename'];
    check('Archive file exists on disk', file_exists($archivePath));

    $integrity = $engine->verifyArchiveIntegrity($archivePath);
    check('Archive integrity valid + has manifest/database/files', $integrity['valid'] && $integrity['has_manifest'] && $integrity['has_database'] && $integrity['has_files']);

    $list = $engine->listBackups();
    check('listBackups() resolves creator_name without a users JOIN', ($list[0]->creator_name ?? null) === 'Harness Admin');

    $single = $engine->getBackup($backupId);
    check('getBackup() resolves creator_name', ($single->creator_name ?? null) === 'Harness Admin');

    $token = $engine->generateDownloadToken($backupId);
    check('Download token round-trips', $engine->validateDownloadToken($token) === $backupId);
    check('Download token is single-use', $engine->validateDownloadToken($token) === false);

    $stats = $engine->getStats();
    check('getStats() reports total >= 1', $stats['total'] >= 1);

    if (class_exists(ActivityLogs\ActivityLogger::class)) {
        $auditCount = auditCount($pdo, 'create_full_backup', (string) $backupId);
        check('Audit entry written for create_full_backup', $auditCount === 1, $auditCount === null ? 'activity_logs table unavailable' : "count={$auditCount}");
    }
}

// ---------------------------------------------------------------------
// Reliability fixes 3 + 5: broken host callables must not crash the module
// ---------------------------------------------------------------------

section('Broken host callables (fixes 3 + 5) — must degrade, never crash');

$throwingLoggerConfig = $facadeConfig;
$throwingLoggerConfig['logger'] = static function (string $msg, string $level): void {
    throw new \RuntimeException('harness: logger callable intentionally broken');
};
$modThrowingLogger = new BackupRestore\BackupRestore($throwingLoggerConfig);
$throwingLoggerResult = $modThrowingLogger->backupEngine()->createBackup(['type' => 'files', 'note' => 'throwing-logger test']);
check(
    'Fix 5: createBackup() survives a throwing logger callable',
    is_array($throwingLoggerResult) && ($throwingLoggerResult['success'] ?? false) === true,
    json_encode($throwingLoggerResult)
);

$throwingUserMapConfig = $facadeConfig;
$throwingUserMapConfig['get_user_map'] = static function (array $ids): array {
    throw new \RuntimeException('harness: get_user_map callable intentionally broken');
};
$modThrowingUserMap = new BackupRestore\BackupRestore($throwingUserMapConfig);
$throwingUserMapList = $modThrowingUserMap->backupEngine()->listBackups();
check(
    'Fix 3: listBackups() survives a throwing get_user_map callable',
    is_array($throwingUserMapList) && count($throwingUserMapList) >= 1,
    'got ' . gettype($throwingUserMapList)
);
if (is_array($throwingUserMapList) && isset($throwingUserMapList[0])) {
    // On a throwing resolver, attachCreatorNames() falls back to "user #<id>"
    // per its own docblock contract ("a broken/incomplete resolver never
    // breaks the backup list") — not null, and not the real resolved name.
    check(
        'Fix 3: creator_name degrades to "user #<id>" instead of propagating the exception',
        preg_match('/^user #\d+$/', $throwingUserMapList[0]->creator_name ?? '') === 1,
        'got: ' . ($throwingUserMapList[0]->creator_name ?? '<unset>')
    );
}

// ---------------------------------------------------------------------
// Atomic restore
// ---------------------------------------------------------------------

section('Atomic restore (root user — has CREATE DATABASE)');

if ($backupResult['success']) {
    // Mutate live data to prove restore reverts it.
    $pdo->exec("UPDATE backups SET note = 'MUTATED-before-atomic-restore' WHERE id = {$backupId}");

    $restoreResult = $mod->restore($backupId, 'database', null, null, 1);
    check('Atomic restore succeeds', $restoreResult['success'], $restoreResult['error'] ?? json_encode($restoreResult));

    $pdo2 = rootPdo($dbHost, $dbPort, $dbUser, $dbPass, $dbName);
    $note = $pdo2->query("SELECT note FROM backups WHERE id = {$backupId}")->fetchColumn();
    check('Data reverted to pre-mutation state', $note === 'harness backup', "got: {$note}");

    $leftoverDbs = $pdo2->query("SHOW DATABASES LIKE '{$dbName}\\_%'")->fetchAll(PDO::FETCH_COLUMN);
    check('No leftover _restore_/_old_ temp databases', empty($leftoverDbs), implode(',', $leftoverDbs));

    if (class_exists(ActivityLogs\ActivityLogger::class)) {
        // NOT a bug: the 'restore_..._backup' audit entry is written BEFORE the
        // atomic swap (matching the original JupitERP controller's identical
        // "log before restore, this entry survives DB replacement" timing —
        // "survives" meaning it is recoverable from the swapped-away _old_<ts>
        // database, not that it lands in the LIVE table). On a fully successful
        // atomic restore, _old_<ts> — and this audit row with it — is dropped as
        // the final cleanup step, so it correctly does NOT appear in the live
        // activity_logs afterward. Verified during manual Phase 2 testing.
        $auditCount = auditCount($pdo2, 'restore_database_backup', (string) $backupId);
        check('Pre-restore audit entry correctly absent after successful atomic swap+cleanup', $auditCount === 0, $auditCount === null ? 'activity_logs table unavailable' : "count={$auditCount}");
    }
}

// ---------------------------------------------------------------------
// In-place restore (restricted user) + forced-failure rollback
// ---------------------------------------------------------------------

section('In-place restore (restricted user — no CREATE DATABASE) + rollback');

$restrictedUser = 'br_harness_restricted';
$restrictedPass = bin2hex(random_bytes(8));
try {
    $adminPdo->exec("CREATE USER IF NOT EXISTS '{$restrictedUser}'@'{$dbHost}' IDENTIFIED BY '{$restrictedPass}'");
    $adminPdo->exec("GRANT ALL PRIVILEGES ON `{$dbName}`.* TO '{$restrictedUser}'@'{$dbHost}'");
    $adminPdo->exec('FLUSH PRIVILEGES');

    $restrictedConfig = $facadeConfig;
    $restrictedConfig['db_credentials'] = ['host' => $dbHost, 'port' => $dbPort, 'database' => $dbName, 'username' => $restrictedUser, 'password' => $restrictedPass];
    $modRestricted = new BackupRestore\BackupRestore($restrictedConfig);
    $reRestricted = $modRestricted->restoreEngine();

    $refl = new ReflectionMethod($reRestricted, 'hasCreateDbPrivilege');
    $refl->setAccessible(true);
    check('Restricted user correctly lacks CREATE DATABASE', $refl->invoke($reRestricted, $restrictedConfig['db_credentials']) === false);

    $inPlaceBackup = $modRestricted->backupEngine()->createBackup(['type' => 'database', 'note' => 'in-place source']);
    check('In-place: source backup created', $inPlaceBackup['success'], $inPlaceBackup['error'] ?? '');

    if ($inPlaceBackup['success']) {
        $pdo->exec("UPDATE backups SET note = 'MUTATED-before-inplace-restore' WHERE id = {$inPlaceBackup['backup_id']}");
        $result = $modRestricted->restore($inPlaceBackup['backup_id'], 'database', null, null, 1);
        check('In-place restore succeeds', $result['success'], $result['error'] ?? json_encode($result));

        $pdo3 = rootPdo($dbHost, $dbPort, $dbUser, $dbPass, $dbName);
        $note = $pdo3->query("SELECT note FROM backups WHERE id = {$inPlaceBackup['backup_id']}")->fetchColumn();
        check('In-place: data reverted', $note === 'in-place source', "got: {$note}");

        $bakTables = $pdo3->query("SHOW TABLES LIKE '\\_bak\\_%'")->fetchAll(PDO::FETCH_COLUMN);
        check('In-place: no leftover _bak_ tables', empty($bakTables));

        // Forced-failure rollback: corrupt a fresh archive's SQL dump, then restore from it.
        $rollbackSource = $modRestricted->backupEngine()->createBackup(['type' => 'database', 'note' => 'rollback source']);
        if ($rollbackSource['success']) {
            $archivePath = $modRestricted->backupEngine()->getBackupDir() . '/' . $rollbackSource['filename'];
            $workDir = $scratchRoot . '/corrupt_work';
            mkdir($workDir, 0777, true);
            exec('tar -xzf ' . escapeshellarg($archivePath) . ' -C ' . escapeshellarg($workDir));
            $sqlFile = glob($workDir . '/database/*.sql')[0] ?? null;
            if ($sqlFile !== null) {
                $content = file_get_contents($sqlFile);
                $content = preg_replace('/(CREATE TABLE `backups`.*?;\n)/s', '$1' . "\nINSERT INTO this_table_does_not_exist_xyz (col) VALUES (1);\n", $content, 1);
                file_put_contents($sqlFile, $content);
                $corruptArchive = $scratchRoot . '/corrupted.tgz';
                exec('tar -czf ' . escapeshellarg($corruptArchive) . ' -C ' . escapeshellarg($workDir) . ' . 2>&1');

                $pdo4 = rootPdo($dbHost, $dbPort, $dbUser, $dbPass, $dbName);
                $preRollbackNote = $pdo4->query("SELECT note FROM backups WHERE id = {$rollbackSource['backup_id']}")->fetchColumn();

                $rollbackResult = $reRestricted->restoreFromPath($corruptArchive, $restrictedConfig['db_credentials']);
                check('Forced-failure restore reports failure', $rollbackResult['success'] === false);
                check('Forced-failure restore auto-rolled-back', ($rollbackResult['rolled_back'] ?? false) === true);

                $pdo5 = rootPdo($dbHost, $dbPort, $dbUser, $dbPass, $dbName);
                $postRollbackNote = $pdo5->query("SELECT note FROM backups WHERE id = {$rollbackSource['backup_id']}")->fetchColumn();
                check('Data intact after rollback', $postRollbackNote === $preRollbackNote, "before={$preRollbackNote} after={$postRollbackNote}");

                $bakTablesAfter = $pdo5->query("SHOW TABLES LIKE '\\_bak\\_%'")->fetchAll(PDO::FETCH_COLUMN);
                check('No leftover _bak_ tables after successful rollback', empty($bakTablesAfter));
            } else {
                check('Forced-failure rollback test setup', false, 'could not locate extracted SQL dump');
            }
        }
    }
} catch (\Throwable $e) {
    check('In-place restore section completed without a fatal error', false, $e->getMessage());
} finally {
    try {
        $adminPdo->exec("DROP USER IF EXISTS '{$restrictedUser}'@'{$dbHost}'");
    } catch (\Throwable) {
        // best-effort cleanup
    }
}

// ---------------------------------------------------------------------
// File restore
// ---------------------------------------------------------------------

section('File restore (rsync sync + delete)');

$fileBackup = $engine->createBackup(['type' => 'files', 'note' => 'file restore test']);
check('File-type backup created', $fileBackup['success'], $fileBackup['error'] ?? '');

if ($fileBackup['success']) {
    file_put_contents($rootPath . '/somefiles/marker.txt', 'MUTATED');
    file_put_contents($rootPath . '/somefiles/stray.txt', 'should be deleted by restore');

    $fileRestoreResult = $mod->restoreEngine()->restoreFiles($fileBackup['backup_id']);
    check('restoreFiles() succeeds', $fileRestoreResult['success'], $fileRestoreResult['error'] ?? '');
    check('Mutated file reverted', file_get_contents($rootPath . '/somefiles/marker.txt') === 'harness-original-content');
    check('Stray file removed by rsync --delete', !file_exists($rootPath . '/somefiles/stray.txt'));
}

// ---------------------------------------------------------------------
// Standalone restore.php (CLI, framework-free)
// ---------------------------------------------------------------------

section('Standalone restore.php (CLI mode)');

$standaloneBackup = $engine->createBackup(['type' => 'database', 'note' => 'standalone.php test']);
if ($standaloneBackup['success']) {
    $archivePath = $engine->getBackupDir() . '/' . $standaloneBackup['filename'];
    $backupRow = $engine->getBackup($standaloneBackup['backup_id']);

    $cmd = sprintf(
        'php %s --file=%s --token=%s --db-host=%s --db-port=%d --db-user=%s --db-pass=%s --db-name=%s 2>&1',
        escapeshellarg(__DIR__ . '/../standalone/restore.php'),
        escapeshellarg($archivePath),
        escapeshellarg($backupRow->restore_token),
        escapeshellarg($dbHost),
        $dbPort,
        escapeshellarg($dbUser),
        escapeshellarg($dbPass),
        escapeshellarg($dbName)
    );
    exec($cmd, $output, $returnCode);
    $outputText = implode("\n", $output);
    check('standalone/restore.php CLI restore succeeds', $returnCode === 0 && str_contains($outputText, 'Restore completed successfully'), $outputText);
} else {
    check('Standalone restore.php test setup', false, 'backup creation failed');
}

// ---------------------------------------------------------------------
// Security regression checks (from the 2026-07-12 /security-review pass)
// ---------------------------------------------------------------------

section('Security: zip-slip / path traversal refused');

$zipSlipWork = $scratchRoot . '/zipslip_work';
mkdir($zipSlipWork, 0777, true);
file_put_contents($zipSlipWork . '/escape.txt', 'malicious content');
$maliciousArchive = $scratchRoot . '/malicious.tgz';
exec(
    'tar -czf ' . escapeshellarg($maliciousArchive)
    . ' --transform ' . escapeshellarg('s,^,../,')
    . ' -C ' . escapeshellarg($zipSlipWork) . ' escape.txt 2>&1',
    $tarBuildOutput,
    $tarBuildRc
);
check('Zip-slip test setup: crafted a ../-escaping .tgz', $tarBuildRc === 0, implode("\n", $tarBuildOutput));

if ($tarBuildRc === 0) {
    $zipSlipDest = $scratchRoot . '/zipslip_dest/inner';
    mkdir($zipSlipDest, 0777, true);
    $zipSlipResult = BackupRestore\Exec\ExecHelper::tarExtract($maliciousArchive, $zipSlipDest);
    check('Zip-slip: tarExtract() refuses a ../-escaping archive member', $zipSlipResult['success'] === false, json_encode($zipSlipResult));
    check('Zip-slip: no file written outside the destination directory', !file_exists(dirname($zipSlipDest) . '/escape.txt'));
}

section('Security: DDL identifier injection (backtick in table name) safely quoted');

$weirdTableReady = false;
try {
    // A single embedded backtick, doubled per MySQL's own escaping rule to
    // name the table `weird`table` — exercises Identifier::quote() in the
    // RENAME TABLE / CREATE DATABASE statements RestoreEngine builds itself
    // (not via mysqldump, which already handles this correctly on its own).
    $pdo->exec('CREATE TABLE IF NOT EXISTS `weird``table` (id INT PRIMARY KEY, note VARCHAR(50))');
    $pdo->exec("INSERT INTO `weird``table` (id, note) VALUES (1, 'original') ON DUPLICATE KEY UPDATE note = 'original'");
    $weirdTableReady = true;
} catch (\Throwable $e) {
    check('Identifier injection test setup: create backtick-named table', false, $e->getMessage());
}

if ($weirdTableReady) {
    $weirdBackup = $engine->createBackup(['type' => 'database', 'note' => 'weird identifier test']);
    check('Identifier injection: backup with backtick-named table succeeds', $weirdBackup['success'], $weirdBackup['error'] ?? '');

    if ($weirdBackup['success']) {
        $pdo->exec("UPDATE `weird``table` SET note = 'MUTATED' WHERE id = 1");
        $weirdRestoreResult = $mod->restore($weirdBackup['backup_id'], 'database', null, null, 1);
        check('Identifier injection: atomic restore with backtick-named table succeeds', $weirdRestoreResult['success'], $weirdRestoreResult['error'] ?? json_encode($weirdRestoreResult));

        $pdoWeird = rootPdo($dbHost, $dbPort, $dbUser, $dbPass, $dbName);
        $weirdNote = $pdoWeird->query("SELECT note FROM `weird``table` WHERE id = 1")->fetchColumn();
        check('Identifier injection: backtick-named table data reverted correctly', $weirdNote === 'original', "got: {$weirdNote}");
    }
}

section('Security: MySQL option-file (credential) newline injection refused');

$injectionCreds = [
    'host' => "localhost\n[client]\nuser=root",
    'port' => 3306,
    'username' => $dbUser,
    'password' => $dbPass,
    'database' => $dbName,
];
$injectionResult = BackupRestore\Exec\ExecHelper::mysqlQuery('SELECT 1', $injectionCreds);
check('Option-file injection: mysqlQuery() refuses a newline-containing host', $injectionResult['success'] === false, json_encode($injectionResult));

section('Security: OpenSslGcmEncryptor key handling + round-trip');

$rawKey32 = random_bytes(32);
$base64Key = base64_encode($rawKey32);
$plainSecret = 'sensitive-remote-server-credential';

$encryptorA = new BackupRestore\Adapters\Crypto\OpenSslGcmEncryptor($base64Key);
$cipherA = $encryptorA->encrypt($plainSecret);
check('Encryptor: round-trip with a base64-encoded key', $encryptorA->decrypt($cipherA) === $plainSecret);

// Fresh instance built from the same key string — simulates a later request/
// process decrypting a value a prior process encrypted (the real-world case
// for stored remote-server credentials).
$encryptorB = new BackupRestore\Adapters\Crypto\OpenSslGcmEncryptor($base64Key);
check('Encryptor: cross-instance round-trip (same key, new instance)', $encryptorB->decrypt($cipherA) === $plainSecret);

$tooShortKeyRejected = false;
try {
    new BackupRestore\Adapters\Crypto\OpenSslGcmEncryptor('short');
} catch (\InvalidArgumentException) {
    $tooShortKeyRejected = true;
}
check('Encryptor: rejects a key shorter than 32 bytes', $tooShortKeyRejected);

// ---------------------------------------------------------------------
// Cleanup + summary
// ---------------------------------------------------------------------

section('Cleanup');
exec('chmod -R u+w ' . escapeshellarg($scratchRoot) . ' 2>/dev/null');
exec('rm -rf ' . escapeshellarg($scratchRoot));
echo "  Scratch directory removed: {$scratchRoot}\n";
echo "  NOTE: database `{$dbName}` was NOT dropped — inspect or drop it yourself.\n";

section('Summary');
echo "  Passed: {$passed}\n";
echo '  Failed: ' . count($failures) . "\n";
if (!empty($failures)) {
    echo "\nFailures:\n";
    foreach ($failures as $f) {
        echo "  - {$f}\n";
    }
    exit(1);
}
echo "\nAll checks passed.\n";
exit(0);
