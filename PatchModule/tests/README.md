# PatchModule — regression fixtures

Besides the database harness below, plain PHP scripts that need **no database server** guard the backup behaviour
(run each with `php tests/<name>.php`):

- `modal_backup_checkbox_test.php` — the install modal shows a notice instead of the backup checkbox when no working backup exists (#58)
- `backup_status_test.php` — `PatchModule::describeBackup()` and the health checks of both built-in adapters (#58, #60)
- `installer_backup_step_test.php` — the pre-patch backup is made for every patch (#60)
- `mysqldump_password_test.php` — the database password never appears on a command line (#59)

# Regression fixture for Reusables#13

This is a **manual verification script**, not a PHPUnit suite. It proves
`PatchMigrator::ensureMigrationsTable()`'s fresh-install detection fix works
against a real MySQL/MariaDB server — it does not exercise the rest of
PatchModule (patch download/apply/rollback), only the migration bootstrap.

## What it verifies

Before this fix, `ensureMigrationsTable()` backfilled `patch_migrations` as
"already applied" for every `database/migrations/*.sql` file whenever the
table was empty — even on an existing installation whose tracking table had
been wiped (e.g. a partial DB restore) but whose real schema was not fresh at
all. That falsely marked migrations as applied without their DDL ever having
run, and the filename-based skip check then trusted those rows forever.

The harness proves the fix: given a schema that already has other real
tables (i.e. clearly not a fresh install) and an empty `patch_migrations`, a
migration placed in the target directory actually **runs** — its DDL effect
is verifiable, and it's recorded in the `applied` list, not `skipped`.

## Prerequisites

- A running MySQL/MariaDB server.
- A database where `patch_migrations` and `patch_history` are currently
  **empty** and at least one other real table already exists — the harness
  refuses to run otherwise (see "Preconditions" in its output), so it will
  not touch a database in the wrong state. It does not need `CREATE
  DATABASE` privilege; a database-scoped grant on an already-existing
  database is enough.
- **Never point this at a database you care about**, even though the
  harness only touches `patch_migrations`/`patch_history` rows it inserted
  itself and one uniquely-named scratch table it drops on completion.

## Running

```bash
php tests/harness.php --db-name=some_existing_db --db-user=USER --db-pass=PASS [--db-host=HOST] [--db-port=PORT]
```

## What it does NOT cover

The genuinely-fresh-install path (`isFreshSchema()` returning `true`, i.e. a
database with **zero** other tables) is not exercised live — doing so needs
`CREATE DATABASE` privilege to provision a truly empty schema without risking
an existing one, which the credentials used to write this fixture didn't
have. That branch is the original backfill loop, unchanged by this fix,
gated behind a boolean whose `false` case this harness does prove. Re-run
against a genuinely empty, freshly created database to exercise it directly
if that assurance is ever needed.

## Cleanup

The harness cleans up everything it creates (the scratch marker table, its
own rows in `patch_migrations`, its own temp migration directory) and
verifies the database is left exactly as it was found. Idempotent — safe to
re-run repeatedly against the same database.

---

# pdo_backup_harness.php — built-in backup (Reusables#54)

A second manual script, `tests/pdo_backup_harness.php`, verifies
`Adapters/Backup/PdoDumpBackupAdapter` and its opt-in wiring in `PatchModule`:

- backup and restore round trip on a database with tables, a foreign key,
  generated columns, binary/unicode/NULL values, a view that depends on a later
  view, a trigger, a stored procedure and function and an event; the restored
  schema and rows equal the original and triggers do not fire during restore;
- `PatchModule::rollback()` restores the database from the built-in backup;
- truncated, foreign-format and path-traversal backups are refused, a database
  with a sequence fails loudly without leaving a partial file;
- the adapter source calls no process function and takes no credentials;
- a 250,000-row table is dumped and restored with bounded memory;
- `builtin_backup` / `backup_path` / `backup_adapter` precedence and validation.

Run it against a **dedicated, empty** database — the adapter dumps and restores the
whole database, so the harness aborts when it finds any table, view, routine,
event or trigger. It removes everything it creates and leaves the database empty:

```bash
php -d disable_functions=exec,shell_exec,system,passthru,popen,proc_open \
    tests/pdo_backup_harness.php --db-name=SCRATCH_DB --db-user=USER --db-pass=PASS
```

The user needs the privileges listed in `doc/INTEGRATION-GUIDE.md` (built-in backup), which
cover the fixture too. The unsupported-object check uses `CREATE SEQUENCE` and is skipped on MySQL.
