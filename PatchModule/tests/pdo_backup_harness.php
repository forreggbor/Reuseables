<?php

declare(strict_types=1);

/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * Verification harness for PdoDumpBackupAdapter (Reusables#54): backup/restore
 * round trip, rollback through PatchModule, no exec(), bounded memory, file
 * safety, and the opt-in config keys.
 *
 * Not a PHPUnit suite — a reproducible script against a real MySQL/MariaDB
 * server, like tests/harness.php. Run it with the process functions disabled
 * to prove the adapter does not need them:
 *
 *   php -d disable_functions=exec,shell_exec,system,passthru,popen,proc_open \
 *       tests/pdo_backup_harness.php --db-name=NAME --db-user=USER --db-pass=PASS
 *
 * The adapter dumps and restores the WHOLE database, so --db-name MUST be a
 * dedicated, EMPTY scratch database: the harness aborts when it finds any table,
 * view, routine, event or trigger. NEVER point it at a database you care about.
 */

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'PatchModule\\')) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, strlen('PatchModule\\'))) . '.php';
    foreach ([__DIR__ . '/..', __DIR__ . '/../src'] as $base) {
        if (is_file($base . '/' . $relative)) {
            require_once $base . '/' . $relative;
            return;
        }
    }
});

use PatchModule\Adapters\Backup\PdoDumpBackupAdapter;
use PatchModule\Contracts\BackupAdapterInterface;
use PatchModule\Contracts\VersionResolverInterface;
use PatchModule\PatchModule;

// ---------------------------------------------------------------------
// CLI args + tiny test helpers
// ---------------------------------------------------------------------

$opts = getopt('', ['db-name:', 'db-user:', 'db-pass::', 'db-host::', 'db-port::']);
if (empty($opts['db-name']) || empty($opts['db-user'])) {
    fwrite(STDERR, "Usage: php pdo_backup_harness.php --db-name=NAME --db-user=USER [--db-pass=PASS] [--db-host=HOST] [--db-port=PORT]\n");
    fwrite(STDERR, "--db-name must be an EMPTY scratch database — see the file header.\n");
    exit(1);
}

$dbName = (string) $opts['db-name'];
$dbUser = (string) $opts['db-user'];
$dbPass = (string) ($opts['db-pass'] ?? '');
$dbHost = (string) ($opts['db-host'] ?? 'localhost');
$dbPort = (int) ($opts['db-port'] ?? 3306);

$failures = [];
$passed   = 0;

/**
 * Record one check result.
 *
 * @param string $label     What is being verified.
 * @param bool   $condition Whether it holds.
 * @param string $detail    Extra context printed on failure.
 * @return void
 */
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

/**
 * Print a section heading.
 *
 * @param string $title Heading text.
 * @return void
 */
function section(string $title): void
{
    echo "\n=== {$title} ===\n";
}

/**
 * Quote an identifier.
 *
 * @param string $name Raw name.
 * @return string Backtick-quoted name.
 */
function q(string $name): string
{
    return '`' . str_replace('`', '``', $name) . '`';
}

/**
 * Remove a directory tree (the harness's own scratch directories only).
 *
 * @param string $dir Directory to remove.
 * @return void
 */
function rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir . '/' . $entry;
        is_dir($path) ? rrmdir($path) : unlink($path);
    }
    rmdir($dir);
}

/**
 * Fingerprint every object of the current database except the named tables.
 *
 * Tables are compared by definition and by an ordered hash of all rows (generated
 * columns included); views, routines, events and triggers by their definitions.
 *
 * @param PDO      $pdo     Connection.
 * @param string[] $exclude Table names to leave out.
 * @return array<string, string> Map of "kind:name" to hash.
 */
function fingerprint(PDO $pdo, array $exclude = []): array
{
    $strip = static fn (string $ddl): string => (string) preg_replace('/\sDEFINER\s*=\s*\S+@\S+/i', '', $ddl);
    $out   = [];

    $objects = $pdo->query(
        "SELECT TABLE_NAME, TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME"
    )->fetchAll(PDO::FETCH_NUM);

    foreach ($objects as [$name, $type]) {
        if (in_array($name, $exclude, true) || $name === 'patch_backups') {
            continue;
        }
        if ($type === 'VIEW') {
            $row = $pdo->query('SHOW CREATE VIEW ' . q($name))->fetch(PDO::FETCH_ASSOC);
            $out["view:{$name}"] = md5($strip((string) $row['Create View']));
            continue;
        }

        $row = $pdo->query('SHOW CREATE TABLE ' . q($name))->fetch(PDO::FETCH_ASSOC);
        $cols = $pdo->query(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '
            . $pdo->quote($name) . ' ORDER BY ORDINAL_POSITION'
        )->fetchAll(PDO::FETCH_COLUMN);
        $list  = implode(', ', array_map('q', $cols));
        $order = implode(', ', array_map('q', $cols));

        $hash = hash_init('md5');
        $stmt = $pdo->query("SELECT {$list} FROM " . q($name) . " ORDER BY {$order}");
        while (($data = $stmt->fetch(PDO::FETCH_NUM)) !== false) {
            hash_update($hash, json_encode(array_map(
                static fn ($v) => $v === null ? null : (preg_match('//u', (string) $v) === 1 ? (string) $v : 'b64:' . base64_encode((string) $v)),
                $data
            ), JSON_UNESCAPED_UNICODE) . "\n");
        }
        $out["table:{$name}"] = md5($row['Create Table']) . '/' . hash_final($hash);
    }

    $queries = [
        'routine' => "SELECT CONCAT(ROUTINE_TYPE, ' ', ROUTINE_NAME), MD5(CONCAT_WS('|', ROUTINE_DEFINITION, SQL_MODE, IFNULL(DTD_IDENTIFIER, '')))
                      FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() ORDER BY 1",
        'trigger' => "SELECT TRIGGER_NAME, MD5(CONCAT_WS('|', EVENT_OBJECT_TABLE, ACTION_TIMING, EVENT_MANIPULATION, ACTION_STATEMENT, SQL_MODE))
                      FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() ORDER BY 1",
        'event'   => "SELECT EVENT_NAME, MD5(CONCAT_WS('|', EVENT_DEFINITION, STATUS, EVENT_TYPE, IFNULL(INTERVAL_VALUE, ''), IFNULL(INTERVAL_FIELD, '')))
                      FROM information_schema.EVENTS WHERE EVENT_SCHEMA = DATABASE() ORDER BY 1",
    ];
    foreach ($queries as $kind => $sql) {
        foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_NUM) as [$name, $hash]) {
            $out["{$kind}:{$name}"] = (string) $hash;
        }
    }

    ksort($out);

    return $out;
}

/**
 * List the keys whose hashes differ between two fingerprints.
 *
 * @param array<string, string> $a First fingerprint.
 * @param array<string, string> $b Second fingerprint.
 * @return string[] Differing keys.
 */
function fingerprintDiff(array $a, array $b): array
{
    $keys = array_unique(array_merge(array_keys($a), array_keys($b)));
    return array_values(array_filter($keys, static fn (string $k): bool => ($a[$k] ?? null) !== ($b[$k] ?? null)));
}

/**
 * Read the session settings the adapter promises to restore.
 *
 * @param PDO $pdo Connection.
 * @return array<string, mixed> Current values.
 */
function sessionState(PDO $pdo): array
{
    return $pdo->query(
        'SELECT @@session.sql_mode AS sql_mode, @@session.time_zone AS time_zone, @@session.foreign_key_checks AS fk,
                @@session.character_set_client AS cs_client, @@session.character_set_results AS cs_results,
                @@session.collation_connection AS collation'
    )->fetch(PDO::FETCH_ASSOC);
}

// ---------------------------------------------------------------------
// Setup + preconditions
// ---------------------------------------------------------------------

section('Setup');

echo '  PHP ' . PHP_VERSION . ', disable_functions="' . ini_get('disable_functions') . "\"\n";
$execDisabled = !function_exists('exec');
if (!$execDisabled) {
    echo "  NOTE: exec() is available — re-run with -d disable_functions=exec,shell_exec,system,passthru,popen,proc_open for the full check.\n";
}

$serverPdo = new PDO("mysql:host={$dbHost};port={$dbPort};charset=utf8mb4", $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$serverPdo->exec('CREATE DATABASE IF NOT EXISTS ' . q($dbName) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

$pdo = new PDO(
    "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4",
    $dbUser,
    $dbPass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$existing = (int) $pdo->query(
    "SELECT (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE())
          + (SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE())
          + (SELECT COUNT(*) FROM information_schema.EVENTS WHERE EVENT_SCHEMA = DATABASE())
          + (SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE())"
)->fetchColumn();
if ($existing !== 0) {
    fwrite(STDERR, "Database `{$dbName}` is not empty ({$existing} objects) — refusing to run; use an empty scratch database.\n");
    exit(1);
}
echo "  Database `{$dbName}` is empty.\n";

$work = sys_get_temp_dir() . '/pdo_backup_harness_' . bin2hex(random_bytes(4));
mkdir($work, 0750, true);
$backupDir = $work . '/backups';

/** @var string[] $logLines Lines the adapter wrote to its logger. */
$logLines = [];
$logger   = new class ($logLines) implements \PatchModule\Contracts\LoggerInterface {
    /** @param string[] $lines Sink for log lines. */
    public function __construct(private array &$lines)
    {
    }

    /** {@inheritdoc} */
    public function log(string $message, string $level = 'INFO'): void
    {
        $this->lines[] = "[{$level}] {$message}";
    }

    /** {@inheritdoc} */
    public function activity(
        string $action,
        string $entityType,
        ?int $entityId = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?int $userId = null
    ): void {
    }
};

// ---------------------------------------------------------------------
// Fixture schema: tables, FK, generated columns, view-on-view, trigger, routines, event
// ---------------------------------------------------------------------

// PatchModule's own tables, so the rollback scenario below can use a real history row.
$pdo->exec((string) file_get_contents(__DIR__ . '/../schema/patch_history.sql'));

$pdo->exec("CREATE TABLE parent_t (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    note MEDIUMTEXT NULL,
    price DECIMAL(12,2) NULL,
    created DATETIME NULL,
    ts TIMESTAMP NULL DEFAULT NULL,
    flag TINYINT(1) NOT NULL DEFAULT 0,
    mood ENUM('happy','sad') NULL,
    dbl DOUBLE NULL,
    bin BLOB NULL,
    meta JSON NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci AUTO_INCREMENT=100");

$pdo->exec("CREATE TABLE child_t (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    parent_id INT UNSIGNED NOT NULL,
    qty INT NOT NULL,
    doubled INT AS (qty * 2) VIRTUAL,
    tripled INT AS (qty * 3) STORED,
    CONSTRAINT fk_child_parent FOREIGN KEY (parent_id) REFERENCES parent_t (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$pdo->exec("CREATE TABLE audit_t (id INT AUTO_INCREMENT PRIMARY KEY, msg VARCHAR(255) NOT NULL) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE " . q('weird`name') . " (" . q('col`x') . " INT PRIMARY KEY, v VARCHAR(20)) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE zero_t (id INT AUTO_INCREMENT PRIMARY KEY) ENGINE=InnoDB");

$pdo->exec("CREATE TRIGGER trg_parent_ai AFTER INSERT ON parent_t FOR EACH ROW INSERT INTO audit_t (msg) VALUES (CONCAT('inserted ', NEW.name))");
$pdo->exec("CREATE VIEW z_view AS SELECT id, name FROM parent_t");
$pdo->exec("CREATE VIEW a_view AS SELECT id FROM z_view");
$pdo->exec("CREATE FUNCTION f_double(x INT) RETURNS INT DETERMINISTIC RETURN x * 2");
$pdo->exec("CREATE PROCEDURE p_count(OUT n INT) BEGIN DECLARE c INT; SELECT COUNT(*) INTO c FROM parent_t; SET n = c; END");
$eventOk = true;
try {
    $pdo->exec("CREATE EVENT ev_noop ON SCHEDULE EVERY 1 YEAR STARTS '2099-01-01 00:00:00' DISABLE DO UPDATE audit_t SET msg = msg WHERE id = 0");
} catch (\Throwable $e) {
    $eventOk = false;
    echo '  NOTE: events not available here (' . $e->getMessage() . ") — event round trip skipped.\n";
}

$ins = $pdo->prepare(
    'INSERT INTO parent_t (name, note, price, created, ts, flag, mood, dbl, bin, meta) VALUES (?,?,?,?,?,?,?,?,?,?)'
);
$seed = [
    ['plain', 'hello', '12.50', '2026-10-12 14:00:00', '2026-10-12 14:00:00', 1, 'happy', 0.1, "\x00\xFF\xFEbinary", '{"a":1}'],
    ["quote'\"back\\slash", "line1\nline2\r\n\ttab", null, null, null, 0, null, null, null, null],
    ['unicode ☃ 😀 árvíztűrő', str_repeat('x', 70000), '-0.01', '1970-01-02 00:00:00', '2026-10-25 02:30:00', 0, 'sad', 1.7976931348623157E+308, '', '[]'],
];
foreach ($seed as $row) {
    $ins->execute($row);
}
$pdo->exec('INSERT INTO child_t (parent_id, qty) VALUES (100, 3), (100, 4), (102, 5)');
$pdo->exec("INSERT INTO " . q('weird`name') . " VALUES (1, 'a'), (2, NULL)");
$pdo->exec('DELETE FROM parent_t WHERE id = 101');   // leaves an AUTO_INCREMENT gap
$pdo->exec('INSERT INTO parent_t (name) VALUES (\'after gap\')');

$baseline = fingerprint($pdo);
echo '  Fixture ready: ' . count($baseline) . " objects fingerprinted.\n";

// ---------------------------------------------------------------------
section('Round trip');
// ---------------------------------------------------------------------

$adapter = new PdoDumpBackupAdapter(new \PatchModule\Adapters\Database\PdoAdapter($pdo), $backupDir, $logger);
check('implements BackupAdapterInterface', $adapter instanceof BackupAdapterInterface);
check('isAvailable() is true', PdoDumpBackupAdapter::isAvailable());

$sessionBefore = sessionState($pdo);
$tableExistsBefore = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'patch_backups'")->fetchColumn();
check('patch_backups does not exist before first use', $tableExistsBefore === 0);

$freeBefore = $adapter->getFreeDiskSpace();
check('getFreeDiskSpace() works before the backup directory exists', $freeBefore > 0 && !is_dir($backupDir), "free={$freeBefore}");

$b1 = $adapter->createBackup('harness backup 1', 7);
check('createBackup() succeeds', $b1['success'] === true && is_int($b1['backup_id']), (string) ($b1['error'] ?? ''));
$id1 = (int) $b1['backup_id'];

$tableExistsAfter = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'patch_backups'")->fetchColumn();
check('patch_backups is created on first use', $tableExistsAfter === 1);
check('session settings are restored after createBackup()', sessionState($pdo) === $sessionBefore);
check('database content is unchanged by the dump', fingerprintDiff($baseline, fingerprint($pdo)) === []);

$record = $pdo->query("SELECT * FROM patch_backups WHERE id = {$id1}")->fetch(PDO::FETCH_ASSOC);
check('patch_backups record holds file, size, note and user', $record !== false
    && $record['note'] === 'harness backup 1' && (int) $record['created_by'] === 7 && (int) $record['file_size'] > 0);

$file1 = $backupDir . '/' . ($record['filename'] ?? '');
check('backup file exists with mode 0640', is_file($file1) && (fileperms($file1) & 0777) === 0640);
check('.htaccess and index.html guard the backup directory', is_file($backupDir . '/.htaccess') && is_file($backupDir . '/index.html'));
check('backup file name is unguessable (random suffix)', preg_match('/_[0-9a-f]{8}\.jsonl\.gz$/', (string) $record['filename']) === 1);

$b1b = $adapter->createBackup('harness backup 1b');
check('a second createBackup() is harmless (table bootstrap is idempotent)', $b1b['success'] === true);

// ---------------------------------------------------------------------
section('Restore after damage');
// ---------------------------------------------------------------------

$pdo->exec('UPDATE parent_t SET name = \'CHANGED\'');
$pdo->exec('DELETE FROM parent_t WHERE id = 102');
$pdo->exec('DROP TABLE child_t');
$pdo->exec('DROP TRIGGER trg_parent_ai');
$pdo->exec('DROP VIEW a_view');
$pdo->exec('DROP PROCEDURE p_count');
$pdo->exec('INSERT INTO audit_t (msg) VALUES (\'junk\')');
$pdo->exec('CREATE TABLE extra_t (id INT PRIMARY KEY) ENGINE=InnoDB');
check('damage is visible in the fingerprint', fingerprintDiff($baseline, fingerprint($pdo, ['extra_t'])) !== []);

$r1 = $adapter->restoreDatabase($id1);
check('restoreDatabase() succeeds', $r1['success'] === true, (string) ($r1['error'] ?? ''));
check('session settings are restored after restoreDatabase()', sessionState($pdo) === $sessionBefore);

$diff = fingerprintDiff($baseline, fingerprint($pdo, ['extra_t']));
check('schema and rows equal the original (tables, FK, generated columns, views, trigger, routines, events)', $diff === [], implode(', ', $diff));
check('triggers did not fire while rows were restored (audit_t equals the original)', !in_array('table:audit_t', $diff, true));
check('binary, unicode, multi-line, 70 kB text and NULL values survive', !in_array('table:parent_t', $diff, true));
try {
    $pdo->exec('SET SESSION information_schema_stats_expiry = 0');   // MySQL 8 caches table statistics; MariaDB has no such variable
} catch (\Throwable) {
}
check('AUTO_INCREMENT counter (with gap) is preserved', (int) $pdo->query("SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'parent_t'")->fetchColumn() === 104);
check('view that depends on a later view is recreated (a_view -> z_view)', $pdo->query('SELECT COUNT(*) FROM a_view')->fetchColumn() !== false);
check('trigger is active again after the restore', (function () use ($pdo): bool {
    $before = (int) $pdo->query('SELECT COUNT(*) FROM audit_t')->fetchColumn();
    $pdo->exec("INSERT INTO parent_t (name) VALUES ('trigger probe')");
    $after = (int) $pdo->query('SELECT COUNT(*) FROM audit_t')->fetchColumn();
    $pdo->exec("DELETE FROM parent_t WHERE name = 'trigger probe'");
    $pdo->exec("DELETE FROM audit_t WHERE msg = 'inserted trigger probe'");
    return $after === $before + 1;
})());
check('objects that are not in the backup are left alone (mysqldump parity)', (int) $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'extra_t'")->fetchColumn() === 1);
check('patch_backups still holds the record after the restore', (int) $pdo->query("SELECT COUNT(*) FROM patch_backups WHERE id = {$id1}")->fetchColumn() === 1);

$r2 = $adapter->restoreDatabase($id1);
check('a second restore of the same backup succeeds and still matches', $r2['success'] === true && fingerprintDiff($baseline, fingerprint($pdo, ['extra_t'])) === []);
$pdo->exec('DROP TABLE extra_t');

// ---------------------------------------------------------------------
section('Refusals and safety');
// ---------------------------------------------------------------------

$b2 = $adapter->createBackup('to be truncated');
$id2 = (int) $b2['backup_id'];
$file2 = $backupDir . '/' . $pdo->query("SELECT filename FROM patch_backups WHERE id = {$id2}")->fetchColumn();
$bytes = (string) file_get_contents($file2);
file_put_contents($file2, substr($bytes, 0, (int) (strlen($bytes) * 0.6)));
$pdo->exec("INSERT INTO audit_t (msg) VALUES ('marker')");
$rBad = $adapter->restoreDatabase($id2);
check('a truncated backup is refused', $rBad['success'] === false && str_contains((string) $rBad['error'], 'incomplete or corrupt'), (string) ($rBad['error'] ?? ''));
check('the database is untouched by a refused restore', (int) $pdo->query("SELECT COUNT(*) FROM audit_t WHERE msg = 'marker'")->fetchColumn() === 1);
$pdo->exec("DELETE FROM audit_t WHERE msg = 'marker'");

check('restore of an unknown id reports it', $adapter->restoreDatabase(999999)['success'] === false);

$pdo->exec("INSERT INTO patch_backups (filename, note) VALUES ('../../etc/passwd', 'evil')");
$evilId = (int) $pdo->lastInsertId();
$rEvil = $adapter->restoreDatabase($evilId);
$dEvil = $adapter->deleteBackup($evilId);
check('a path-traversal file name is rejected for restore and delete', $rEvil['success'] === false && $dEvil['success'] === false
    && str_contains((string) $rEvil['error'], 'Invalid backup file name'));
$pdo->exec("DELETE FROM patch_backups WHERE id = {$evilId}");

$pdo->exec("INSERT INTO patch_backups (filename, note) VALUES ('foreign.sql.gz', 'other adapter')");
$foreignId = (int) $pdo->lastInsertId();
file_put_contents($backupDir . '/foreign.sql.gz', gzencode("-- mysqldump style file\nCREATE TABLE x (id INT);\n"));
$rForeign = $adapter->restoreDatabase($foreignId);
check('a file from another adapter is refused with a clear message', $rForeign['success'] === false && str_contains((string) $rForeign['error'], 'Not a built-in backup file'), (string) ($rForeign['error'] ?? ''));
$pdo->exec("DELETE FROM patch_backups WHERE id = {$foreignId}");
unlink($backupDir . '/foreign.sql.gz');

$del = $adapter->deleteBackup($id2);
check('deleteBackup() removes file and record', $del['success'] === true && !is_file($file2)
    && (int) $pdo->query("SELECT COUNT(*) FROM patch_backups WHERE id = {$id2}")->fetchColumn() === 0);
check('deleting an already deleted backup reports it', $adapter->deleteBackup($id2)['success'] === false);

try {
    $pdo->exec('CREATE SEQUENCE harness_seq');
    $sequenceOk = true;
} catch (\Throwable) {
    $sequenceOk = false;
    echo "  NOTE: sequences are MariaDB-only — unsupported-object check skipped.\n";
}
if ($sequenceOk) {
    $beforeFiles = glob($backupDir . '/*.jsonl.gz') ?: [];
    $rSeq = $adapter->createBackup('sequence present');
    $afterFiles = glob($backupDir . '/*.jsonl.gz') ?: [];
    check('an unsupported object (sequence) fails loudly and leaves no partial file', $rSeq['success'] === false
        && str_contains((string) $rSeq['error'], 'Unsupported object type') && $beforeFiles === $afterFiles, (string) ($rSeq['error'] ?? ''));
    $pdo->exec('DROP SEQUENCE harness_seq');
}

check('failures were written to the logger', count(array_filter($logLines, static fn (string $l): bool => str_starts_with($l, '[ERROR]'))) >= 3);

// ---------------------------------------------------------------------
section('No exec() / no credentials / no process');
// ---------------------------------------------------------------------

$source = (string) file_get_contents(__DIR__ . '/../Adapters/Backup/PdoDumpBackupAdapter.php');
$tokens = token_get_all($source);
$forbidden = ['exec', 'shell_exec', 'system', 'passthru', 'popen', 'proc_open', 'escapeshellarg', 'escapeshellcmd', 'pcntl_exec'];
$hits = [];
foreach ($tokens as $i => $token) {
    if (is_array($token) && $token[0] === T_STRING && in_array(strtolower($token[1]), $forbidden, true)) {
        $next = $tokens[$i + 1] ?? null;
        $prev = $tokens[$i - 1] ?? null;
        $isMethodCall = is_array($prev) && in_array($prev[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true);
        if ($next === '(' && !$isMethodCall) {
            $hits[] = $token[1] . ' on line ' . $token[2];
        }
    }
}
check('adapter source calls no process function (so no password can reach a command line)', $hits === [], implode(', ', $hits));
check('adapter constructor takes no credentials', (new ReflectionMethod(PdoDumpBackupAdapter::class, '__construct'))->getNumberOfParameters() === 3);

// ---------------------------------------------------------------------
section('Opt-in wiring in PatchModule');
// ---------------------------------------------------------------------

$resolver = new class implements VersionResolverInterface {
    /** @var string Version held for the test. */
    private string $version = '1.0.0';

    /** {@inheritdoc} */
    public function getCurrentVersion(): string
    {
        return $this->version;
    }

    /** {@inheritdoc} */
    public function updateVersion(string $newVersion): bool
    {
        $this->version = $newVersion;
        return true;
    }
};
$tempPath = $work . '/storage/temp';
mkdir($tempPath, 0750, true);

$baseConfig = [
    'get_pdo'          => static fn (): PDO => $pdo,
    'patch_server_url' => 'https://patch.invalid',
    'license_key'      => 'harness',
    'version_resolver' => $resolver,
    'root_path'        => $work,
    'temp_path'        => $tempPath,
];
$backupOf = static function (PatchModule $module): ?BackupAdapterInterface {
    $property = new ReflectionProperty(PatchModule::class, 'backupAdapter');
    return $property->getValue($module);
};
$dirOf = static function (BackupAdapterInterface $adapter): string {
    return (new ReflectionProperty(PdoDumpBackupAdapter::class, 'backupDir'))->getValue($adapter);
};

check('config without the new keys: no backup adapter (existing hosts behave as before)', $backupOf(new PatchModule($baseConfig)) === null);
check('builtin_backup=false: no backup adapter', $backupOf(new PatchModule($baseConfig + ['builtin_backup' => false])) === null);

$builtin = $backupOf(new PatchModule($baseConfig + ['builtin_backup' => true]));
check('builtin_backup=true selects PdoDumpBackupAdapter', $builtin instanceof PdoDumpBackupAdapter);
check('default backup directory is "patch-backups" next to temp_path', $builtin instanceof PdoDumpBackupAdapter && $dirOf($builtin) === $work . '/storage/patch-backups');

$custom = $backupOf(new PatchModule($baseConfig + ['builtin_backup' => true, 'backup_path' => $work . '/custom']));
check('backup_path overrides the directory', $custom instanceof PdoDumpBackupAdapter && $dirOf($custom) === $work . '/custom');

$explicit = new class implements BackupAdapterInterface {
    /** {@inheritdoc} */
    public function createBackup(string $note, ?int $userId = null): array
    {
        return ['success' => true, 'backup_id' => 1, 'error' => null];
    }

    /** {@inheritdoc} */
    public function restoreDatabase(int $backupId): array
    {
        return ['success' => true, 'error' => null];
    }

    /** {@inheritdoc} */
    public function deleteBackup(int $backupId): array
    {
        return ['success' => true, 'error' => null];
    }

    /** {@inheritdoc} */
    public function getFreeDiskSpace(): int
    {
        return PHP_INT_MAX;
    }
};
check('an explicit backup_adapter keeps precedence over builtin_backup', $backupOf(new PatchModule($baseConfig + ['builtin_backup' => true, 'backup_adapter' => $explicit])) === $explicit);

$rejected = 0;
foreach ([['builtin_backup' => 'yes'], ['backup_path' => ''], ['backup_path' => 5]] as $bad) {
    try {
        new PatchModule($baseConfig + $bad);
    } catch (\InvalidArgumentException) {
        $rejected++;
    }
}
check('wrongly typed builtin_backup / backup_path are rejected at construction', $rejected === 3, "rejected {$rejected}/3");

// ---------------------------------------------------------------------
section('Rollback through PatchModule (the existing rollback path)');
// ---------------------------------------------------------------------

$module  = new PatchModule($baseConfig + ['builtin_backup' => true, 'backup_path' => $work . '/rollback-backups']);
$history = $module->getDatabase()->createHistoryRecord([
    'version'         => '9.9.9',
    'status'          => 'installing',
    'release_notes'   => '',
    'file_size'       => 0,
    'sha256_hash'     => str_repeat('0', 64),
    'patch_server_id' => null,
    'released_at'     => null,
]);
$moduleAdapter = $backupOf($module);
$pre = fingerprint($pdo);
$made = $moduleAdapter->createBackup('Pre-patch backup (v1.0.0 → v9.9.9)', null);
check('installer-style backup is created through the module adapter', $made['success'] === true, (string) ($made['error'] ?? ''));
$module->getDatabase()->updateHistoryRecord($history, ['backup_id' => (int) $made['backup_id']]);

// Simulate a half-applied migration, then the failure rollback.
$pdo->exec('DROP TABLE zero_t');
$pdo->exec("UPDATE parent_t SET name = 'migrated'");
$pdo->exec('ALTER TABLE audit_t ADD COLUMN half_done INT NULL');

$rolled = $module->rollback($history);
check('PatchModule::rollback() succeeds with the built-in backup', $rolled['success'] === true, (string) ($rolled['error'] ?? ''));

$afterRollback = fingerprint($pdo);
unset($afterRollback['table:patch_history'], $pre['table:patch_history']);
$diff = fingerprintDiff($pre, $afterRollback);
check('database is back to its pre-patch state', $diff === [], implode(', ', $diff));
check('history row is marked rolled_back after the restore', ($module->getHistoryRecord($history)['status'] ?? null) === 'rolled_back');
check('the backup record survives the rollback (so pruning can still delete its file)', (int) $pdo->query('SELECT COUNT(*) FROM patch_backups WHERE id = ' . (int) $made['backup_id'])->fetchColumn() === 1);

$pruned = $moduleAdapter->deleteBackup((int) $made['backup_id']);
check('pruning the backup afterwards works', $pruned['success'] === true);

// ---------------------------------------------------------------------
section('Streaming: bounded memory on a larger table');
// ---------------------------------------------------------------------

$pdo->exec('CREATE TABLE seed_t (n INT PRIMARY KEY) ENGINE=InnoDB');
$values = implode(',', array_map(static fn (int $i): string => "({$i})", range(1, 500)));
$pdo->exec("INSERT INTO seed_t VALUES {$values}");
$pdo->exec('CREATE TABLE big_t (id INT AUTO_INCREMENT PRIMARY KEY, payload VARCHAR(255) NOT NULL) ENGINE=InnoDB');
$pdo->exec('INSERT INTO big_t (payload) SELECT REPEAT(MD5(a.n * 1000 + b.n), 6) FROM seed_t a CROSS JOIN seed_t b');
$bigCount = (int) $pdo->query('SELECT COUNT(*) FROM big_t')->fetchColumn();
$bigSum   = (string) $pdo->query('SELECT SUM(CRC32(payload)) FROM big_t')->fetchColumn();
$rawBytes = (int) $pdo->query('SELECT SUM(LENGTH(payload)) FROM big_t')->fetchColumn();
echo "  big_t: {$bigCount} rows, " . round($rawBytes / 1048576, 1) . " MB of payload.\n";

gc_collect_cycles();
memory_reset_peak_usage();
$baseMemory = memory_get_usage();
$bigBackup  = $moduleAdapter->createBackup('big');
$dumpPeak   = memory_get_peak_usage() - $baseMemory;
check('big dump succeeds', $bigBackup['success'] === true, (string) ($bigBackup['error'] ?? ''));
check('dump memory stays bounded (peak growth < 32 MB for ' . round($rawBytes / 1048576) . ' MB of data)', $dumpPeak < 32 * 1048576, round($dumpPeak / 1048576, 1) . ' MB');

$pdo->exec('DELETE FROM big_t');
memory_reset_peak_usage();
$baseMemory = memory_get_usage();
$bigRestore = $moduleAdapter->restoreDatabase((int) $bigBackup['backup_id']);
$restorePeak = memory_get_peak_usage() - $baseMemory;
check('big restore succeeds', $bigRestore['success'] === true, (string) ($bigRestore['error'] ?? ''));
check('restore memory stays bounded (peak growth < 32 MB)', $restorePeak < 32 * 1048576, round($restorePeak / 1048576, 1) . ' MB');
check('all rows and values are back', (int) $pdo->query('SELECT COUNT(*) FROM big_t')->fetchColumn() === $bigCount
    && (string) $pdo->query('SELECT SUM(CRC32(payload)) FROM big_t')->fetchColumn() === $bigSum);

// ---------------------------------------------------------------------
// Cleanup: only what this harness created
// ---------------------------------------------------------------------

section('Cleanup');
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach ($pdo->query("SELECT TABLE_NAME, TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()")->fetchAll(PDO::FETCH_NUM) as [$name, $type]) {
    $pdo->exec(($type === 'VIEW' ? 'DROP VIEW ' : 'DROP TABLE ') . q($name));
}
foreach ($pdo->query("SELECT ROUTINE_TYPE, ROUTINE_NAME FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE()")->fetchAll(PDO::FETCH_NUM) as [$type, $name]) {
    $pdo->exec("DROP {$type} " . q($name));
}
foreach ($pdo->query("SELECT EVENT_NAME FROM information_schema.EVENTS WHERE EVENT_SCHEMA = DATABASE()")->fetchAll(PDO::FETCH_COLUMN) as $name) {
    $pdo->exec('DROP EVENT ' . q($name));
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
rrmdir($work);
echo "  Scratch objects and files removed (database `{$dbName}` itself is left in place, empty).\n";

// ---------------------------------------------------------------------
section('Summary');
// ---------------------------------------------------------------------

echo "  {$passed} passed, " . count($failures) . " failed.\n";
if ($failures !== []) {
    echo "\nFailures:\n";
    foreach ($failures as $f) {
        echo "  - {$f}\n";
    }
    echo "\nAdapter log:\n";
    foreach ($logLines as $line) {
        echo "  {$line}\n";
    }
    exit(1);
}
exit(0);
