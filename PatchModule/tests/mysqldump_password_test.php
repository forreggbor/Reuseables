<?php

declare(strict_types=1);

/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * Regression fixture for Reusables#59 — MysqldumpBackupAdapter passed the database password as a
 * command-line argument (`--password=...`), visible in the process list. It now passes the credentials
 * through a temporary option file (`--defaults-extra-file`, mode 0600, removed afterwards).
 *
 * The test needs no database server: it puts fake `mariadb-dump` / `mariadb` binaries first on PATH
 * that record what they were called with, and uses an in-memory SQLite connection for the
 * `patch_backups` bookkeeping. Run: php tests/mysqldump_password_test.php
 */

require_once __DIR__ . '/../src/Contracts/BackupAdapterInterface.php';
require_once __DIR__ . '/../src/Contracts/BackupAdapterHealthInterface.php';
require_once __DIR__ . '/../Adapters/Backup/BackupDirectoryCheck.php';
require_once __DIR__ . '/../Adapters/Backup/MysqldumpBackupAdapter.php';

use PatchModule\Adapters\Backup\MysqldumpBackupAdapter;

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

// A password that would break naive quoting: hash, quotes, backslash, spaces, dollar, semicolon.
$password = 'p#ss"w\\ord \'x\' $HOME; end';
$work     = sys_get_temp_dir() . '/pm_mysqldump_test_' . bin2hex(random_bytes(4));
$bin      = $work . '/bin';
$log      = $work . '/log';
$backups  = $work . '/backups';
mkdir($bin, 0700, true);
mkdir($log, 0700, true);

// Fake binaries: they log argv, their own and their parent's command line, and the option file's
// content and permissions at call time. FAKE_FAIL=1 makes the dump/client fail.
$shim = <<<'SH'
#!/bin/bash
name=$(basename "$0")
if [ "$1" = "--version" ]; then echo "$name fake"; exit 0; fi
{
  printf '%s\n' "$name" "$@"
} > "$FAKE_LOG/$name.args"
tr '\0' ' ' < /proc/$$/cmdline > "$FAKE_LOG/$name.self"
tr '\0' ' ' < /proc/$PPID/cmdline > "$FAKE_LOG/$name.parent"
for a in "$@"; do
  case "$a" in --defaults-extra-file=*)
    f="${a#--defaults-extra-file=}"
    printf '%s' "$f" > "$FAKE_LOG/$name.optpath"
    cat "$f" > "$FAKE_LOG/$name.optcontent"
    stat -c %a "$f" > "$FAKE_LOG/$name.optmode"
  ;; esac
done
if [ "$FAKE_FAIL" = "1" ]; then echo "simulated failure" >&2; exit 1; fi
if [ "$name" = "mariadb-dump" ]; then echo "-- fake dump"; else cat > /dev/null; fi
exit 0
SH;
foreach (['mariadb-dump', 'mariadb'] as $name) {
    file_put_contents($bin . '/' . $name, $shim);
    chmod($bin . '/' . $name, 0700);
}
$origPath = (string)getenv('PATH');
putenv('PATH=' . $bin . ':' . $origPath);
putenv('FAKE_LOG=' . $log);

// In-memory bookkeeping database (the adapter's SQL uses NOW()).
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->sqliteCreateFunction('NOW', static fn(): string => date('Y-m-d H:i:s'));
$pdo->exec('CREATE TABLE patch_backups (id INTEGER PRIMARY KEY AUTOINCREMENT, filename TEXT, file_size INTEGER, note TEXT, created_at TEXT, created_by INTEGER)');

$adapter = new MysqldumpBackupAdapter($pdo, $backups, 'db.example', 'appdb', 'appuser', $password, 3307);

/**
 * Reads a recorded file of the fake binaries.
 *
 * @param string $file File name inside the log directory.
 * @return string Content, or '' when it was not written.
 */
function recorded(string $file): string
{
    global $log;
    return is_file($log . '/' . $file) ? (string)file_get_contents($log . '/' . $file) : '';
}

/**
 * Clears the recordings between scenarios.
 *
 * @return void
 */
function resetRecordings(): void
{
    global $log;
    foreach (glob($log . '/*') ?: [] as $f) {
        unlink($f);
    }
}

// ---- Dump ----
$result = $adapter->createBackup('test');
check('the dump succeeds with the fake binary', $result['success'] === true);
$all = recorded('mariadb-dump.args') . recorded('mariadb-dump.self') . recorded('mariadb-dump.parent');
check('the password is in no argument of the dump binary', $all !== '' && !str_contains($all, 'p#ss') && !str_contains($all, $password));
check('no --password= argument is used', !str_contains($all, '--password'));
$args = explode("\n", trim(recorded('mariadb-dump.args')));
check('--defaults-extra-file is the first option (the client requires that)', isset($args[1]) && str_starts_with($args[1], '--defaults-extra-file='));
$content = recorded('mariadb-dump.optcontent');
check('the option file carries the credentials', str_contains($content, "[client]\n") && str_contains($content, 'user="appuser"') && str_contains($content, 'host="db.example"') && str_contains($content, 'port=3307'));
check('the password is quoted and escaped in the option file', str_contains($content, 'password="p#ss\\"w\\\\ord \'x\' $HOME; end"'));
check('the option file had mode 0600 while the dump ran', trim(recorded('mariadb-dump.optmode')) === '600');
check('the option file is removed after a successful dump', recorded('mariadb-dump.optpath') !== '' && !file_exists(recorded('mariadb-dump.optpath')));

// The real client must understand the quoting: --print-defaults parses the file without a server.
$real = trim((string)shell_exec('PATH=' . escapeshellarg($origPath) . ' sh -c ' . escapeshellarg('command -v mariadb || command -v mysql')));
$tmpOpt = tempnam(sys_get_temp_dir(), 'pm_opt_');
file_put_contents($tmpOpt, $content);
if ($real !== '' && $real !== $bin . '/mariadb') {
    $printed = (string)shell_exec(escapeshellarg($real) . ' --defaults-file=' . escapeshellarg($tmpOpt) . ' --print-defaults 2>&1');
    check('the real client parses the quoted password back to the exact original', str_contains($printed, '--password=' . $password));
} else {
    echo "  skip the real client is not installed, quoting not checked against it\n";
}
unlink($tmpOpt);

// ---- Dump failure ----
resetRecordings();
putenv('FAKE_FAIL=1');
$result = $adapter->createBackup('test');
check('a failing dump is reported', $result['success'] === false);
check('the option file is removed after a failed dump too', recorded('mariadb-dump.optpath') !== '' && !file_exists(recorded('mariadb-dump.optpath')));
check('the error text does not contain the password', !str_contains((string)$result['error'], 'p#ss'));
putenv('FAKE_FAIL');

// ---- Restore ----
resetRecordings();
$pdo->exec("INSERT INTO patch_backups (filename, file_size, note, created_at, created_by) VALUES ('restore.sql.gz', 1, 'x', 'now', NULL)");
$id = (int)$pdo->lastInsertId();
$gz = gzopen($backups . '/restore.sql.gz', 'w');
gzwrite($gz, "SELECT 1;\n");
gzclose($gz);
$result = $adapter->restoreDatabase($id);
check('the restore succeeds with the fake client', $result['success'] === true);
$all = recorded('mariadb.args') . recorded('mariadb.self') . recorded('mariadb.parent');
check('the password is in no argument of the restore client', $all !== '' && !str_contains($all, 'p#ss') && !str_contains($all, '--password'));
check('the restore uses an option file with mode 0600 that is removed afterwards', trim(recorded('mariadb.optmode')) === '600' && recorded('mariadb.optpath') !== '' && !file_exists(recorded('mariadb.optpath')));

resetRecordings();
putenv('FAKE_FAIL=1');
$result = $adapter->restoreDatabase($id);
check('a failing restore is reported', $result['success'] === false);
check('the option file is removed after a failed restore too', recorded('mariadb.optpath') !== '' && !file_exists(recorded('mariadb.optpath')));
putenv('FAKE_FAIL');

// ---- A credential that would inject option-file directives is refused ----
$evil = new MysqldumpBackupAdapter($pdo, $backups, 'db.example', 'appdb', 'appuser', "x\npassword=other", 3306);
resetRecordings();
$result = $evil->createBackup('test');
check('a password with a line break is refused before any binary runs', $result['success'] === false && recorded('mariadb-dump.args') === '');

exec('rm -rf ' . escapeshellarg($work));
echo $failures === 0 ? "\nall passed\n" : "\n{$failures} failed\n";
exit($failures === 0 ? 0 : 1);
