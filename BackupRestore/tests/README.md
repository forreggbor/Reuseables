# BackupRestore — standalone test harness

This is a **manual verification script**, not a PHPUnit suite. It proves the
module works end-to-end outside of any host framework, against a real
MySQL/MariaDB server.

## Prerequisites

- A running MySQL/MariaDB server.
- A **disposable** database the harness may freely `CREATE`/`DROP` tables and
  databases in (the atomic restore strategy creates and drops
  `<db>_restore_<timestamp>` / `<db>_old_<timestamp>` databases as part of its
  normal operation). **Never point this at a database you care about.**
- The shell binaries: `mysql`, `mysqldump`, `tar`, `gzip`, `sed`, `rsync`
  (the harness also exercises the pure-PHP fallback path automatically when
  these are unavailable — see `Exec\ShellHelper::isExecAvailable()`).
- `composer install` run once inside this module directory (pulls
  `phpseclib/phpseclib`, generates the classmap autoloader).
- `ActivityLogs\ActivityLogger` autoloadable — the harness `require`s it
  directly from `/home/gabor/development/Reusables/ActivityLogs/ActivityLogger.php`
  (adjust the path in `harness.php` if ActivityLogs lives elsewhere on your
  machine; there is no Composer package for it, matching the sibling
  reusable-module convention).

## Running

```bash
composer install
php tests/harness.php --db-name=backuprestore_test --db-user=root --db-pass=a
```

The harness will:

1. `CREATE DATABASE IF NOT EXISTS <db-name>` and install the module's own
   `schema/*.sql`.
2. Run the full backup → integrity → list/get → download-token →
   stats/disk-space cycle.
3. Run the **atomic** restore strategy (requires `CREATE DATABASE`
   privilege) and verify data round-trips correctly.
4. Run the **in-place** restore strategy against a second, privilege-
   restricted MySQL user (created via `CREATE USER IF NOT EXISTS`, scoped
   only to `<db-name>`) and verify both the happy path and a forced-failure
   rollback (a deliberately corrupted dump) leave the original data intact.
5. Run a file-restore round trip (mutate → restore → verify) using a scratch
   directory tree, never touching the machine outside `sys_get_temp_dir()`.
6. Run `standalone/restore.php` in CLI mode against a freshly created
   archive, proving it works with zero dependency on the rest of the module.
7. Print a pass/fail summary. Non-zero exit code on any failure.

## What it does NOT do

- It does not exercise `RemoteService`/SFTP transfer (no test SFTP server is
  assumed) — that class is unit-verified only insofar as it constructs and
  the `EncryptorInterface` round-trips correctly.
- It does not test the (deferred, not-yet-built) HTTP/host-controller layer.
- It is not idempotent across unrelated runs sharing the same `<db-name>` —
  re-run against a fresh disposable database, or expect leftover rows from a
  previous run to appear in listings.

## Cleanup

The harness leaves the schema tables in place (so you can inspect the
result) but drops its own scratch temp/storage directories. To fully reset,
drop the database yourself:

```sql
DROP DATABASE IF EXISTS <db-name>;
DROP USER IF EXISTS 'br_harness_restricted'@'localhost';
```
