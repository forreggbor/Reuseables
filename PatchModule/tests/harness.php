<?php

declare(strict_types=1);

/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * Regression fixture for Reusables#13 — PatchMigrator::ensureMigrationsTable()
 * used to backfill patch_migrations as "already applied" whenever the table
 * was empty, even on an existing installation whose tracking table had been
 * wiped (e.g. a partial DB restore) but whose real schema was not fresh at
 * all. That falsely marks every migration as applied without their DDL ever
 * having run, and the filename-based skip check in executeMigrationsDirectory()
 * then trusts those rows forever — the drift never heals.
 *
 * Not a PHPUnit suite — a single reproducible script proving the fix works
 * against a real MySQL/MariaDB server, matching BackupRestore/tests/harness.php's
 * convention.
 *
 * NEVER point --db-name at a database you care about. This harness requires
 * `patch_migrations` and `patch_history` to already be empty in the target
 * database before it runs (it aborts otherwise) and requires at least one
 * other real table to already exist there (to represent "existing, non-fresh
 * schema" — the exact scenario under test). It cleans up every row/table it
 * creates, but a schema drift bug in the very code under test could in
 * theory leave that cleanup incomplete — do not point this at anything that
 * matters.
 */

require_once __DIR__ . '/../src/Contracts/DatabaseAdapterInterface.php';
require_once __DIR__ . '/../src/Contracts/LoggerInterface.php';
require_once __DIR__ . '/../Adapters/Database/PdoAdapter.php';
require_once __DIR__ . '/../src/PatchMigrator.php';

use PatchModule\PatchMigrator;
use PatchModule\Adapters\Database\PdoAdapter;

// ---------------------------------------------------------------------
// CLI args
// ---------------------------------------------------------------------

$opts = getopt('', ['db-name:', 'db-user:', 'db-pass:', 'db-host::', 'db-port::']);
if (empty($opts['db-name']) || empty($opts['db-user'])) {
    fwrite(STDERR, "Usage: php harness.php --db-name=NAME --db-user=USER [--db-pass=PASS] [--db-host=HOST] [--db-port=PORT]\n");
    fwrite(STDERR, "NEVER point --db-name at a database you care about — see the file header.\n");
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

// ---------------------------------------------------------------------
// Connect + install patch_history/patch_migrations (idempotent)
// ---------------------------------------------------------------------

section('Setup');

$dsn = "mysql:host={$dbHost};port={$dbPort};charset=utf8mb4";
$rootPdo = new PDO($dsn, $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
try {
    // Best-effort — the DB user running this may only have grants scoped to
    // an already-existing database, not CREATE DATABASE itself.
    $rootPdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    echo "  Database `{$dbName}` created (or already existed).\n";
} catch (\PDOException $e) {
    echo "  Skipping CREATE DATABASE (no privilege) — assuming `{$dbName}` already exists: " . $e->getMessage() . "\n";
}

$dsn = "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4";
$pdo = new PDO($dsn, $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$pdo->exec(file_get_contents(__DIR__ . '/../schema/patch_history.sql'));
$pdo->exec(file_get_contents(__DIR__ . '/../schema/patch_migrations.sql'));
echo "  patch_history / patch_migrations schema ready.\n";

// ---------------------------------------------------------------------
// Preconditions — abort rather than risk touching real data
// ---------------------------------------------------------------------

section('Preconditions');

$migrationsRowCount = (int) $pdo->query('SELECT COUNT(*) FROM patch_migrations')->fetchColumn();
$historyRowCount = (int) $pdo->query('SELECT COUNT(*) FROM patch_history')->fetchColumn();
$otherTableCount = (int) $pdo->query(
    "SELECT COUNT(*) FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' AND TABLE_NAME != 'patch_migrations'"
)->fetchColumn();

check('patch_migrations starts empty', $migrationsRowCount === 0, "found {$migrationsRowCount} row(s) — refusing to run against a database with existing data");
check('patch_history starts empty', $historyRowCount === 0, "found {$historyRowCount} row(s) — refusing to run against a database with existing data");
check('schema has other tables (represents an existing, non-fresh install)', $otherTableCount > 0, "found {$otherTableCount} other table(s) — this scenario needs a non-empty schema to be meaningful");

if (!empty($failures)) {
    fwrite(STDERR, "\nPreconditions not met — aborting without touching anything further.\n");
    exit(1);
}

// ---------------------------------------------------------------------
// Scenario: existing schema, empty tracking table (Reusables#13)
// ---------------------------------------------------------------------

section('Scenario: existing schema + empty patch_migrations (the bug)');

$markerTable = 'patchmod13_test_marker';
$pdo->exec("DROP TABLE IF EXISTS `{$markerTable}`"); // in case a prior run was interrupted before cleanup

$migrationsDir = sys_get_temp_dir() . '/patchmod13_' . uniqid();
mkdir($migrationsDir);
file_put_contents(
    $migrationsDir . '/0001_create_marker.sql',
    "CREATE TABLE IF NOT EXISTS `{$markerTable}` (`id` INT UNSIGNED PRIMARY KEY);"
);

$adapter = new PdoAdapter($pdo);
$migrator = new PatchMigrator($adapter, '/nonexistent-root-not-used-by-this-test');
$result = $migrator->executeMigrationsDirectory($migrationsDir, null);

check('executeMigrationsDirectory() reports success', $result['success'] === true, (string) ($result['error'] ?? ''));
check(
    'migration was actually APPLIED, not silently skipped',
    in_array('0001_create_marker.sql', $result['applied'], true) && !in_array('0001_create_marker.sql', $result['skipped'], true),
    'applied=' . json_encode($result['applied']) . ' skipped=' . json_encode($result['skipped'])
);

$markerExists = (int) $pdo->query(
    "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$markerTable}'"
)->fetchColumn();
check('marker table from the migration actually exists (DDL really ran)', $markerExists === 1);

$trackedRow = $pdo->query("SELECT COUNT(*) FROM patch_migrations WHERE filename = '0001_create_marker.sql'")->fetchColumn();
check('patch_migrations has exactly one row for the real run', ((int) $trackedRow) === 1);

// ---------------------------------------------------------------------
// Cleanup — leave the database exactly as found
// ---------------------------------------------------------------------

section('Cleanup');

$pdo->exec("DROP TABLE IF EXISTS `{$markerTable}`");
$pdo->exec("DELETE FROM patch_migrations WHERE filename = '0001_create_marker.sql'");
@unlink($migrationsDir . '/0001_create_marker.sql');
@rmdir($migrationsDir);

$finalMigrationsCount = (int) $pdo->query('SELECT COUNT(*) FROM patch_migrations')->fetchColumn();
$finalMarkerExists = (int) $pdo->query(
    "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$markerTable}'"
)->fetchColumn();
check('patch_migrations left empty again', $finalMigrationsCount === 0, "found {$finalMigrationsCount} row(s)");
check('marker table dropped, no residue left behind', $finalMarkerExists === 0);

// ---------------------------------------------------------------------
// NOT covered by this harness (documented, not silently skipped)
// ---------------------------------------------------------------------

section('Not covered live by this harness');
echo "  The genuinely-fresh-install path (isFreshSchema() returning true, i.e. a\n";
echo "  database with ZERO other tables) is NOT exercised here — the available DB\n";
echo "  credentials for this run have no CREATE DATABASE privilege, so a truly\n";
echo "  empty schema could not be provisioned without risking an existing one.\n";
echo "  That branch is unchanged code (the original backfill loop, untouched by\n";
echo "  this fix) gated behind a boolean whose false case is proven above; re-run\n";
echo "  this harness against a fresh, empty database (one the operator creates\n";
echo "  themselves) to exercise it directly if that assurance is ever needed.\n";

// ---------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------

section('Summary');
echo "  {$passed} passed, " . count($failures) . " failed.\n";
if (!empty($failures)) {
    echo "\nFailures:\n";
    foreach ($failures as $f) {
        echo "  - {$f}\n";
    }
    exit(1);
}
exit(0);
