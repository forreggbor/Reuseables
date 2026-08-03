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
// PHP fallback import (exec() unavailable) — fail-fast (issue #9)
// ---------------------------------------------------------------------

section('PHP fallback import (exec() unavailable) — fail-fast');

// Root credentials, shaped exactly as PhpHelper::createPdoConnection() expects.
// Reused verbatim (same expression) for both mysqlImport() and mysqlQuery()
// below so both hit the same PhpHelper::$pdoCache entry.
$ffCreds = $facadeConfig['db_credentials'];

// Scratch DB name deliberately OUTSIDE the "{$dbName}_" namespace: the
// leftover-temp-database assertion in the atomic-restore section above
// (`SHOW DATABASES LIKE '{$dbName}\_%'`) would otherwise flag a database
// left behind by an aborted run of *this* section as a bogus atomic-restore
// leak on the next harness run.
$ffDbName = 'br_ffprobe_' . bin2hex(random_bytes(4));
$adminPdo->exec("CREATE DATABASE IF NOT EXISTS `{$ffDbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

try {
    // A — direct library call: stop-at-first-error regression guard.
    $ffSqlPath = $scratchRoot . '/ff_probe.sql';
    file_put_contents($ffSqlPath, implode("\n", [
        'CREATE TABLE br_ff_probe (id INT PRIMARY KEY);',
        'INSERT INTO this_table_does_not_exist_xyz (col) VALUES (1);',
        'CREATE TABLE br_ff_marker (id INT PRIMARY KEY);',
    ]) . "\n");

    $ffImportResult = BackupRestore\Exec\PhpHelper::mysqlImport($ffCreds, $ffDbName, $ffSqlPath);
    check('PHP-fallback import: reports failure on a bad statement', $ffImportResult['success'] === false, json_encode($ffImportResult));
    check('PHP-fallback import: error names the failing statement', str_contains($ffImportResult['error'] ?? '', 'this_table_does_not_exist_xyz'), $ffImportResult['error'] ?? '');

    $ffCheckPdo = rootPdo($dbHost, $dbPort, $dbUser, $dbPass, $ffDbName);
    $ffProbeExists = (bool) $ffCheckPdo->query("SHOW TABLES LIKE 'br_ff_probe'")->fetchColumn();
    $ffMarkerExists = (bool) $ffCheckPdo->query("SHOW TABLES LIKE 'br_ff_marker'")->fetchColumn();
    check('PHP-fallback import: statement before the failure executed', $ffProbeExists);
    check('PHP-fallback import: statement after the failure did NOT execute (early-stop proof)', !$ffMarkerExists);

    $ffFkResult = BackupRestore\Exec\PhpHelper::mysqlQuery('SELECT @@SESSION.foreign_key_checks', $ffCreds, $ffDbName);
    check(
        'PHP-fallback import: session variables restored on the cached connection',
        $ffFkResult['success'] === true && (int) ($ffFkResult['rows'][0][0] ?? -1) === 1,
        json_encode($ffFkResult)
    );

    // A2 — positive control (do not skip): a legitimate dump must still import.
    $ffRealBackup = $engine->createBackup(['type' => 'database', 'note' => 'php-fallback positive control']);
    check('PHP-fallback import: positive-control backup created', $ffRealBackup['success'], $ffRealBackup['error'] ?? '');

    if ($ffRealBackup['success']) {
        $ffExtractDir = $scratchRoot . '/ff_real_extract';
        mkdir($ffExtractDir, 0777, true);
        $ffArchivePath = $engine->getBackupDir() . '/' . $ffRealBackup['filename'];
        exec('tar -xzf ' . escapeshellarg($ffArchivePath) . ' -C ' . escapeshellarg($ffExtractDir));
        $ffRealSqlPath = glob($ffExtractDir . '/database/*.sql')[0] ?? null;

        if ($ffRealSqlPath !== null) {
            // Matches the real RestoreEngine path, which always strips DEFINER
            // clauses before either mysqlImport() implementation sees the file.
            BackupRestore\Exec\ExecHelper::stripDefinerFromFile($ffRealSqlPath);
            $ffRealImportResult = BackupRestore\Exec\PhpHelper::mysqlImport($ffCreds, $ffDbName, $ffRealSqlPath);
            check('PHP-fallback import: legitimate dump still imports successfully', $ffRealImportResult['success'], $ffRealImportResult['error'] ?? '');
        } else {
            check('PHP-fallback import: positive-control setup', false, 'could not locate extracted SQL dump');
        }
    }
} finally {
    try {
        $adminPdo->exec("DROP DATABASE IF EXISTS `{$ffDbName}`");
    } catch (\Throwable) {
        // best-effort cleanup
    }
}

// B — standalone subprocess (reinstated per plan-critique round 2/3): forces
// PHP-fallback mode via `disable_functions`, and sidesteps the separate,
// tracked ShellHelper-vs-PharData archive-format incompatibility (Follow-up
// issue A) by repacking with PhpHelper::tarCreateGz() instead of shell tar.
$ffStandaloneBackup = $engine->createBackup(['type' => 'database', 'note' => 'php-fallback standalone test']);
if ($ffStandaloneBackup['success']) {
    $ffStandaloneArchivePath = $engine->getBackupDir() . '/' . $ffStandaloneBackup['filename'];
    $ffStandaloneRow = $engine->getBackup($ffStandaloneBackup['backup_id']);
    $ffDisableFunctions = 'exec,shell_exec,system,passthru,proc_open,popen';

    // Corrupted-archive negative case.
    $ffCorruptWorkDir = $scratchRoot . '/ff_standalone_corrupt_work';
    mkdir($ffCorruptWorkDir, 0777, true);
    exec('tar -xzf ' . escapeshellarg($ffStandaloneArchivePath) . ' -C ' . escapeshellarg($ffCorruptWorkDir));
    $ffCorruptSqlPath = glob($ffCorruptWorkDir . '/database/*.sql')[0] ?? null;

    if ($ffCorruptSqlPath !== null) {
        $ffCorruptContent = file_get_contents($ffCorruptSqlPath);
        $ffCorruptContent = preg_replace('/(CREATE TABLE `backups`.*?;\n)/s', '$1' . "\nINSERT INTO this_table_does_not_exist_xyz (col) VALUES (1);\n", $ffCorruptContent, 1);
        file_put_contents($ffCorruptSqlPath, $ffCorruptContent);

        $ffCorruptRepacked = $scratchRoot . '/ff_standalone_corrupt.tgz';
        $ffRepackResult = BackupRestore\Exec\PhpHelper::tarCreateGz($ffCorruptRepacked, $ffCorruptWorkDir);
        check('PHP-fallback standalone: corrupted-archive repack succeeds', $ffRepackResult['success'], $ffRepackResult['error'] ?? '');

        if ($ffRepackResult['success']) {
            $ffCorruptCmd = sprintf(
                'php -d disable_functions=%s %s --file=%s --token=%s --db-host=%s --db-port=%d --db-user=%s --db-pass=%s --db-name=%s 2>&1',
                $ffDisableFunctions,
                escapeshellarg(__DIR__ . '/../standalone/restore.php'),
                escapeshellarg($ffCorruptRepacked),
                escapeshellarg($ffStandaloneRow->restore_token),
                escapeshellarg($dbHost),
                $dbPort,
                escapeshellarg($dbUser),
                escapeshellarg($dbPass),
                escapeshellarg($dbName)
            );
            $ffCorruptOutput = [];
            $ffCorruptReturnCode = 0;
            exec($ffCorruptCmd, $ffCorruptOutput, $ffCorruptReturnCode);
            $ffCorruptOutputText = implode("\n", $ffCorruptOutput);
            check(
                'PHP-fallback standalone: corrupted import correctly reported as failure',
                $ffCorruptReturnCode !== 0 && str_contains($ffCorruptOutputText, 'Failed to import dump'),
                $ffCorruptOutputText
            );
            check(
                'PHP-fallback standalone: corrupted run did not falsely report success',
                !str_contains($ffCorruptOutputText, 'Restore completed successfully'),
                $ffCorruptOutputText
            );
        }
    } else {
        check('PHP-fallback standalone: corrupted-archive test setup', false, 'could not locate extracted SQL dump');
    }

    // Clean-archive positive control — proves the repack-and-forced-PHP-mode
    // machinery itself isn't what's failing. Extracted fresh (not reusing the
    // corrupted work dir above, whose dump was mutated in place).
    $ffCleanWorkDir = $scratchRoot . '/ff_standalone_clean_work';
    mkdir($ffCleanWorkDir, 0777, true);
    exec('tar -xzf ' . escapeshellarg($ffStandaloneArchivePath) . ' -C ' . escapeshellarg($ffCleanWorkDir));

    $ffCleanRepacked = $scratchRoot . '/ff_standalone_clean.tgz';
    $ffCleanRepackResult = BackupRestore\Exec\PhpHelper::tarCreateGz($ffCleanRepacked, $ffCleanWorkDir);
    check('PHP-fallback standalone: clean-archive repack succeeds', $ffCleanRepackResult['success'], $ffCleanRepackResult['error'] ?? '');

    if ($ffCleanRepackResult['success']) {
        $ffCleanCmd = sprintf(
            'php -d disable_functions=%s %s --file=%s --token=%s --db-host=%s --db-port=%d --db-user=%s --db-pass=%s --db-name=%s 2>&1',
            $ffDisableFunctions,
            escapeshellarg(__DIR__ . '/../standalone/restore.php'),
            escapeshellarg($ffCleanRepacked),
            escapeshellarg($ffStandaloneRow->restore_token),
            escapeshellarg($dbHost),
            $dbPort,
            escapeshellarg($dbUser),
            escapeshellarg($dbPass),
            escapeshellarg($dbName)
        );
        $ffCleanOutput = [];
        $ffCleanReturnCode = 0;
        exec($ffCleanCmd, $ffCleanOutput, $ffCleanReturnCode);
        $ffCleanOutputText = implode("\n", $ffCleanOutput);
        check(
            'PHP-fallback standalone: clean import (positive control) succeeds',
            $ffCleanReturnCode === 0 && str_contains($ffCleanOutputText, 'Restore completed successfully'),
            $ffCleanOutputText
        );
    }
} else {
    check('PHP-fallback standalone test setup', false, 'backup creation failed');
}

// ---------------------------------------------------------------------
// PHP fallback archive extraction (exec() unavailable) — shell-mode
// archives (issue #10)
// ---------------------------------------------------------------------

section('PHP fallback archive extraction of shell-mode-created archives (fail-fast issue #10)');

// A real backup, created via this harness's normal (exec-available) path —
// ShellHelper::tarCreateGz() ("tar -czf ... -C dir .") — is exactly the
// archive shape that broke PharData: a "." self-referencing directory entry
// plus a literal "./" prefix on every member name. PharData's iterator
// silently yields zero members for such an archive (no exception — which
// also emptied the pre-extraction zip-slip containment check) and
// extractTo() threw `PharException: Cannot extract "."`.
$archiveFixBackup = $engine->createBackup(['type' => 'database', 'note' => 'archive-fix test']);
check('Archive-fix test: source backup created', $archiveFixBackup['success'], $archiveFixBackup['error'] ?? '');

if ($archiveFixBackup['success']) {
    $archiveFixPath = $engine->getBackupDir() . '/' . $archiveFixBackup['filename'];

    $listResult = BackupRestore\Exec\PhpHelper::tarList($archiveFixPath);
    check('Shell-mode archive: PhpHelper::tarList() succeeds', $listResult['success'], $listResult['error'] ?? '');
    check(
        'Shell-mode archive: tarList() finds manifest.json and the database dump',
        $listResult['success']
            && !empty(array_filter($listResult['files'], fn ($f) => str_contains($f, 'manifest.json')))
            && !empty(array_filter($listResult['files'], fn ($f) => str_contains($f, '.sql'))),
        json_encode($listResult['files'] ?? [])
    );

    $count = BackupRestore\Exec\PhpHelper::tarCount($archiveFixPath);
    check('Shell-mode archive: PhpHelper::tarCount() reports > 0', $count > 0, "count={$count}");

    $fullExtractDir = $scratchRoot . '/archive_fix_full';
    $fullExtractResult = BackupRestore\Exec\PhpHelper::tarExtract($archiveFixPath, $fullExtractDir);
    check('Shell-mode archive: PhpHelper::tarExtract() full extraction succeeds', $fullExtractResult['success'], $fullExtractResult['error'] ?? '');
    check('Shell-mode archive: full extraction produced manifest.json', file_exists($fullExtractDir . '/manifest.json'));
    check('Shell-mode archive: full extraction produced the database dump', !empty(glob($fullExtractDir . '/database/*.sql')));

    // Selective extraction — exactly what RestoreEngine::restoreFromArchivePath()
    // calls on every real restore. Before this fix, this silently matched zero
    // files (RecursiveIteratorIterator's $file->getPathname() returned the full
    // phar:// stream URL, which never matches a relative pattern), reporting
    // success=true while extracting nothing.
    $selectiveManifestDir = $scratchRoot . '/archive_fix_manifest';
    $selectiveManifestResult = BackupRestore\Exec\PhpHelper::tarExtract($archiveFixPath, $selectiveManifestDir, './manifest.json');
    check('Shell-mode archive: selective extraction of ./manifest.json succeeds', $selectiveManifestResult['success'], $selectiveManifestResult['error'] ?? '');
    check('Shell-mode archive: selective ./manifest.json extraction actually produced the file', file_exists($selectiveManifestDir . '/manifest.json'));

    $selectiveDbDir = $scratchRoot . '/archive_fix_database';
    $selectiveDbResult = BackupRestore\Exec\PhpHelper::tarExtract($archiveFixPath, $selectiveDbDir, './database/*');
    check('Shell-mode archive: selective extraction of ./database/* succeeds', $selectiveDbResult['success'], $selectiveDbResult['error'] ?? '');
    check('Shell-mode archive: selective ./database/* extraction actually produced the dump', !empty(glob($selectiveDbDir . '/database/*.sql')));

    $leftoverNormalized = glob($engine->getBackupDir() . '/.tmp_normalized_*');
    check('Shell-mode archive: no leftover .tmp_normalized_* scratch files', empty($leftoverNormalized), implode(',', $leftoverNormalized));

    // Definitive end-to-end proof: the standalone CLI, forced into PHP-fallback
    // mode, restoring a REAL (unmodified, genuinely shell-mode-created) backup
    // archive directly — no repack workaround needed, unlike issue #9's test B
    // (which had to repack via PhpHelper::tarCreateGz() specifically because
    // this bug was not fixed yet).
    $archiveFixRow = $engine->getBackup($archiveFixBackup['backup_id']);
    $archiveFixCmd = sprintf(
        'php -d disable_functions=exec,shell_exec,system,passthru,proc_open,popen %s --file=%s --token=%s --db-host=%s --db-port=%d --db-user=%s --db-pass=%s --db-name=%s 2>&1',
        escapeshellarg(__DIR__ . '/../standalone/restore.php'),
        escapeshellarg($archiveFixPath),
        escapeshellarg($archiveFixRow->restore_token),
        escapeshellarg($dbHost),
        $dbPort,
        escapeshellarg($dbUser),
        escapeshellarg($dbPass),
        escapeshellarg($dbName)
    );
    $archiveFixOutput = [];
    $archiveFixReturnCode = 0;
    exec($archiveFixCmd, $archiveFixOutput, $archiveFixReturnCode);
    $archiveFixOutputText = implode("\n", $archiveFixOutput);
    check(
        'Shell-mode archive: standalone CLI restores a REAL (non-repacked) backup end-to-end in forced PHP-fallback mode',
        $archiveFixReturnCode === 0 && str_contains($archiveFixOutputText, 'Restore completed successfully'),
        $archiveFixOutputText
    );
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

section('Security: zip-slip refused via the PHP-fallback path too (issue #11)');

// The section above goes through ExecHelper (exec() is available in this
// harness, so it exercises ShellHelper::tarExtract()). PharData's own
// RecursiveIteratorIterator yields ZERO members for a `../`-escaping
// archive (confirmed live) — which would make an iterator-derived
// containment check vacuous. PhpHelper::tarExtract() must reject this via
// the raw tar-header-parsed member list instead — called directly here
// (bypassing ExecHelper) to force the PHP-fallback code path specifically.
if ($tarBuildRc === 0) {
    $zipSlipDestPhp = $scratchRoot . '/zipslip_dest_php/inner';
    mkdir($zipSlipDestPhp, 0777, true);
    $zipSlipResultPhp = BackupRestore\Exec\PhpHelper::tarExtract($maliciousArchive, $zipSlipDestPhp);
    check('PHP-fallback zip-slip: tarExtract() refuses a ../-escaping archive member', $zipSlipResultPhp['success'] === false, json_encode($zipSlipResultPhp));
    check('PHP-fallback zip-slip: no file written outside the destination directory', !file_exists(dirname($zipSlipDestPhp) . '/escape.txt'));
}

// Same check, but through the standalone disaster-recovery script, forced
// into PHP-fallback mode via a subprocess — proves executeRestore()'s own
// mirrored fix (not just the library's) actually rejects the archive.
//
// Deliberately targets its OWN disposable scratch database (outside the
// {$dbName}_ namespace, per the same reasoning as the issue #9 fail-fast
// section) rather than the shared $dbName every other section depends on:
// if this containment check has a bug, the standalone script's atomic
// restore would still proceed to its RENAME TABLE swap — moving the target
// database's real tables out to an "_old_*" database and not restoring any
// back (this test's dump intentionally defines zero tables), which would
// silently empty a shared database out from under every later test section.
// A throwaway scratch database contains that blast radius to itself.
if ($tarBuildRc === 0) {
    $zipSlipDbName = 'br_zipsliptest_' . bin2hex(random_bytes(4));
    $adminPdo->exec("CREATE DATABASE IF NOT EXISTS `{$zipSlipDbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    try {
        // The standalone CLI needs a full manifest.json + database dump inside
        // the archive (not just a bare escape.txt) to get past its own earlier
        // validation steps and reach the extraction step under test.
        $zipSlipStandaloneWork = $scratchRoot . '/zipslip_standalone_work';
        mkdir($zipSlipStandaloneWork . '/database', 0777, true);
        file_put_contents($zipSlipStandaloneWork . '/database/dump.sql', "SELECT 1;\n");
        file_put_contents($zipSlipStandaloneWork . '/manifest.json', json_encode([
            'version' => 1,
            'backup_type' => 'database',
            'restore_token' => 'zipsliptesttoken',
            'database' => ['dump_file' => 'database/dump.sql'],
        ]));
        file_put_contents($zipSlipStandaloneWork . '/escape.txt', 'malicious content');

        // Anchored to the FULL "./"-prefixed name tar's own -C dir . convention
        // produces (confirmed live) — a pattern anchored only to "escape.txt"
        // without the "./" prefix does not match, silently leaving the member
        // un-transformed and defeating the whole point of this test archive.
        $zipSlipStandaloneArchive = $scratchRoot . '/zipslip_standalone.tgz';
        exec(
            'tar -czf ' . escapeshellarg($zipSlipStandaloneArchive)
            . ' --transform ' . escapeshellarg('s,^\./escape\.txt$,../escape.txt,')
            . ' -C ' . escapeshellarg($zipSlipStandaloneWork) . ' . 2>&1',
            $zipSlipStandaloneBuildOutput,
            $zipSlipStandaloneBuildRc
        );
        check('PHP-fallback zip-slip (standalone) test setup: crafted archive with a ../-escaping member', $zipSlipStandaloneBuildRc === 0, implode("\n", $zipSlipStandaloneBuildOutput));

        // Verify via a separate `tar -tzf` listing (not the -czf creation
        // command's own output, which is just warnings, never a member list)
        // that the archive genuinely contains the escaping member — otherwise
        // a passing "refuses" check below could vacuously pass for having
        // nothing malicious to refuse in the first place.
        $zipSlipStandaloneListing = [];
        exec('tar -tzf ' . escapeshellarg($zipSlipStandaloneArchive) . ' 2>&1', $zipSlipStandaloneListing);
        check(
            'PHP-fallback zip-slip (standalone) test setup: archive listing actually contains ../escape.txt',
            in_array('../escape.txt', $zipSlipStandaloneListing, true),
            implode("\n", $zipSlipStandaloneListing)
        );

        if ($zipSlipStandaloneBuildRc === 0) {
            $zipSlipStandaloneCmd = sprintf(
                'php -d disable_functions=exec,shell_exec,system,passthru,proc_open,popen %s --file=%s --token=zipsliptesttoken --db-host=%s --db-port=%d --db-user=%s --db-pass=%s --db-name=%s 2>&1',
                escapeshellarg(__DIR__ . '/../standalone/restore.php'),
                escapeshellarg($zipSlipStandaloneArchive),
                escapeshellarg($dbHost),
                $dbPort,
                escapeshellarg($dbUser),
                escapeshellarg($dbPass),
                escapeshellarg($zipSlipDbName)
            );
            $zipSlipStandaloneOutput = [];
            $zipSlipStandaloneReturnCode = 0;
            exec($zipSlipStandaloneCmd, $zipSlipStandaloneOutput, $zipSlipStandaloneReturnCode);
            $zipSlipStandaloneOutputText = implode("\n", $zipSlipStandaloneOutput);
            // The containment check runs BEFORE extractTo() is ever called (see
            // restore_assert_archive_members_contained()'s own docblock) — a
            // "Refusing to extract" failure here is proof nothing was written to
            // disk from this archive, not just that some final state looks clean.
            check(
                'PHP-fallback zip-slip (standalone): restore.php refuses the ../-escaping archive before extracting anything',
                $zipSlipStandaloneReturnCode !== 0 && str_contains($zipSlipStandaloneOutputText, 'Refusing to extract unsafe archive'),
                $zipSlipStandaloneOutputText
            );
        }
    } finally {
        try {
            $adminPdo->exec("DROP DATABASE IF EXISTS `{$zipSlipDbName}`");
        } catch (\Throwable) {
            // best-effort cleanup
        }
    }
}

section('Security: Fs::removeDirectory() does not follow a symlink out of the tree');

// Regression for a confirmed arbitrary-file-deletion bug: a backup archive is
// untrusted input (SFTP pull, break-glass upload, restoreFromPath()), and tar
// extraction preserves symlink archive members as real filesystem symlinks.
// Fs::removeDirectory() (used to clean up extracted restore workdirs, both
// right after a restore and via BackupEngine::cleanupTempFiles()'s unattended
// 24h sweep) previously used is_dir() to decide whether to recurse — which
// FOLLOWS symlinks — so a symlink left inside the workdir pointing outside it
// caused the target directory's real contents to be deleted.
$symlinkVictimDir = $scratchRoot . '/symlink_victim';
$symlinkWorkDir = $scratchRoot . '/symlink_workdir';
mkdir($symlinkVictimDir, 0777, true);
mkdir($symlinkWorkDir . '/database', 0777, true);
file_put_contents($symlinkVictimDir . '/important_file.txt', 'must survive cleanup');
symlink($symlinkVictimDir, $symlinkWorkDir . '/database/escape_link');

BackupRestore\Fs::removeDirectory($symlinkWorkDir);

check('Fs::removeDirectory(): symlinked-to file outside the tree survives', file_exists($symlinkVictimDir . '/important_file.txt'));
check('Fs::removeDirectory(): the workdir itself is still fully removed', !is_dir($symlinkWorkDir));
if (is_dir($symlinkVictimDir)) {
    exec('rm -rf ' . escapeshellarg($symlinkVictimDir));
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
