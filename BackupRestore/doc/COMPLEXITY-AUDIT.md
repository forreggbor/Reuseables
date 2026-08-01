# Complexity Audit — BackupRestore reusable module

**Date:** 2026-07-13
**Scope:** Full module (`BackupRestore.php`, `src/`, `Adapters/`, `standalone/restore.php`) — 10 364 lines across 19 PHP files.
**Method:** 4 parallel audits (RestoreEngine.php; BackupEngine/ProfileService/RemoteService; facade+Exec+Adapters; standalone/restore.php), each against the standard thresholds (cyclomatic complexity, method/class length, nesting depth, parameter count, constructor dependencies) plus an explicit forward-looking pass: assume the module keeps growing with new backup types, restore strategies, remote protocols, and UI steps — flag anything that would make that growth linear-cost-per-feature instead of additive.

This file is the raw findings + priorities for a **future refactoring session**. No code was changed as part of this audit — see git history / harness status for the module's current (fully tested, 43/43 harness) state.

---

## Summary

```
COMPLEXITY SCORE: 4/10
Issues: [COMPLEXITY] 23 | [NESTING] 8 | [LENGTH] 12 | [GOD] 6 | [COUPLING] 14 | [MAGIC] 11 | [CLEVER] 6 | [DEAD] 3
Refactor priority: high (for the structural GOD/COUPLING items; MAGIC/CLEVER items are low-effort, can be done anytime)
Top recommendation: Introduce a BackupOps interface behind ShellHelper/PhpHelper — this simultaneously de-risks "two implementations must stay in sync by hand" AND prepares the ground for a future 3rd execution mode (e.g. async/queue), at medium effort relative to its payoff.
```

**The core forward-looking risk in one sentence:** the module currently duplicates every "which backend" decision **twice** — once on the Shell-vs-PHP axis (Exec layer) and once on the library-vs-standalone axis (`standalone/restore.php`). Any new capability (new backup target, new restore strategy, new remote protocol) that touches those axes gets tripled, not doubled, unless the two structural items below are addressed first.

---

## A) Structural / forward-looking findings (address these first)

### [GOD] Two God Classes carry the module's core: RestoreEngine (2017 lines, 29 methods) and BackupEngine (1094 lines, 33 methods)
**File:** `src/RestoreEngine.php:27-2017`, `src/BackupEngine.php:28-1094`
**Problem:** Each mixes 6-7 unrelated responsibilities in one class (progress-tracking, disk-space, DEFINER-stripping, trigger handling, orphan-cleanup, 3 restore strategies + rollback / resp. archive creation, listing, deletion, integrity, disk-stats, directory-tree, token management). Every future feature lands in one of these two files because there's nowhere else to put it — this is where the bottleneck will be for every new feature.
**Suggestion:** Split along the existing comment-section seams into collaborators:
- RestoreEngine → `RestoreProgress`, `TriggerManager`, `OrphanCleaner`, `RollbackSnapshot` + the 3 strategies
- BackupEngine → `BackupArchiver`, `BackupRepository`, `BackupIntegrity`, `DiskSpaceInspector`, `DirectoryTreeBuilder`, `DownloadTokenService`
**Effort:** high

### [GOD] No RestoreStrategy abstraction — a 3rd restore mode would mean another 300+ line method
**File:** `src/RestoreEngine.php:953-971` (dispatch), `1000-1340` (atomic), `1357-1665` (in-place)
**Problem:** The atomic/in-place choice is a hard if/else; each branch alone is already critically complex (~50+ decision points). The FK/trigger/table plumbing is duplicated verbatim THREE times across the two strategies (see COUPLING below). A third strategy would copy it a fourth time.
**Suggestion:** `interface RestoreStrategy { supports(); restore(); }` — Atomic/InPlace implementations sharing an injected FK/trigger helper. A new strategy becomes one small class, not a new monster method.
**Effort:** high

### [COUPLING] ShellHelper/PhpHelper dual implementation, bound by nothing
**File:** `src/Exec/ExecHelper.php` (272 lines, 14 dispatch methods), `src/Exec/ShellHelper.php`, `src/Exec/PhpHelper.php`
**Problem:** Every primitive (mysqldump, tar, rsync…) exists twice (shell CLI + pure-PHP fallback) with no interface/type enforcing parity. A new primitive (e.g. encrypted archives, incremental backup) means editing 3 files, and PHP's type system won't catch a signature drift between the two. `clearPdoCache()` is already asymmetric (only calls PhpHelper) — proof the abstraction already leaks.
**Suggestion:** `interface BackupOps` declaring the ~14 primitives; `ShellOps`/`PhpOps implements BackupOps`. ExecHelper collapses to one selector. A new primitive = one interface method + two implementations; the compiler flags a missing one.
**Effort:** medium

### [GOD] standalone/restore.php is a hand-maintained fork of five library classes, with no structural sync guard
**File:** `standalone/restore.php:262-1493`
**Problem:** `restore_quote_identifier()`, `restore_sanitize_identifier()`, the PathGuard functions, the whole restoreDatabase/restoreFiles/trigger handling — all hand-copied from the library equivalents. The recent security hardening (identifier-quoting, path-containment) had to land in both places by hand. Nothing flags a future RestoreEngine fix that doesn't get ported here — this already happened once in the security-review session (the table-RENAME quoting gap was only caught by the regression test).
**Suggestion:** Extract the dependency-free helpers (Identifier::quote, PathGuard's 3 functions, DEFINER regex, trigger-DDL whitelist) into one namespace-free file both sides `require`. At minimum, add a parity test asserting byte-identical output between the standalone and library helpers, so CI breaks the instant they diverge.
**Effort:** high

### [COUPLING] Hardcoded type branching where the schema already implies pluggability
**File:** `src/BackupEngine.php:361,376` (backup type if/else), `src/ProfileService.php:276-306` (schedule switch), `src/RemoteService.php` (`type` column defaults to 'sftp' but nothing dispatches on it)
**Problem:** Backup type, schedule type, and remote protocol are all baked into hard if/switch statements. RemoteService is the clearest case: the `type` column already implies a second protocol is expected, but every method calls phpseclib SFTP directly — a second protocol (FTPS, S3) would mean wedging if/elseif into every method.
**Suggestion:** Data-driven table for backup types; `ScheduleStrategy` interface for scheduling; `RemoteTransport` interface + factory for the protocol. In all three: a new type/protocol is a new table row / a new small class, zero edits to existing branches.
**Effort:** medium (type/schedule) / high (transport)

---

## B) Largest individual complexity hotspots

### [COMPLEXITY] restoreDatabaseAtomic / restoreDatabaseInPlace — ~50+ decision points, 340 / 308 lines
**File:** `src/RestoreEngine.php:1000-1340`, `1357-1665`
**Problem:** The two most safety-critical (destructive DB-swap) methods in the module are also by far the most complex — the opposite of what you'd want for safety-critical code. `restoreDatabaseInPlace` additionally repeats an identical rollback-and-fail return block 6 times.
**Suggestion:** Split into named steps (`prepareTempDatabase`, `importAndVerify`, `captureExternalFks`, `swapTablesWithTriggers`, `rebuildForeignKeys`); collapse the 6 rollback blocks into one `rollbackAndFail()` helper.
**Effort:** high

### [COUPLING] FK-capture/rebuild logic and base-table enumeration duplicated repeatedly inside RestoreEngine
**File:** FK-capture 3× (`1098-1131`, `1142-1170`, `1407-1440`), FK-rebuild DDL 3× (`1271-1290`, `1297-1317`, `1623-1648`), base-table SELECT ~11×
**Problem:** The same `information_schema` query and DDL-building logic copied 3-11 times. A DDL quirk fix (e.g. a MariaDB-specific edge case) must be applied by hand in every copy — high risk in destructive code.
**Suggestion:** `queryForeignKeys()`, `buildFkRebuildSql()`, `listBaseTables()` shared helpers.
**Effort:** medium

### [LENGTH]/[COMPLEXITY] transferToRemote (166 lines, ~16 decisions) and createBackupLocked (213 lines, ~17 decisions)
**File:** `src/RemoteService.php:431-597`, `src/BackupEngine.php:288-501`
**Problem:** Both run the entire flow (validate → I/O → rollback/audit) in one method; the most important logic (e.g. transferToRemote's rename-swap recovery) sits deeply buried at the bottom.
**Suggestion:** Split into named private steps (e.g. `prepareTransfer`/`uploadToTemp`/`publishAtomically`).
**Effort:** high

### [COMPLEXITY]/[NESTING] PhpHelper::mysqldump (231 lines, ~25 CC, 4-level nesting) and the top-level switch($step) dispatch in standalone
**File:** `src/Exec/PhpHelper.php:668-899`, `standalone/restore.php:79-256`
**Problem:** mysqldump repeats 6 near-identical blocks (tables/views/triggers/procedures/functions/events), each with a duplicated DEFINER-strip regex. The standalone dispatch mixes HTTP parsing + CSRF + business logic + rendering into every case — every future UI step (likely with future growth) is another ~40-line case repeating the same boilerplate.
**Suggestion:** `writeRoutineBlock()` + `stripDefiner()` helper for mysqldump; per-step handler functions + a step→handler table for the standalone dispatch.
**Effort:** medium

---

## C) Quick wins (low effort, do anytime)

- **Magic constants everywhere**: `0775`/`0755` mkdir mode (6+ sites), `'_restore_'`/`'_old_'`/`'_bak_'`/`'Ymd_His'` (RestoreEngine — the generator↔parser coupling between name-generation and orphan-cleanup's strict re-parse is implicit and dangerous to break), `set_time_limit(300/600)`, port `22`, `/backups`, `'02:00:00'`, retention `30`, day-cap `28`, `10*1024*1024` streaming threshold, batch size `500` — all should become named constants.
- **"100MB" duplication**: `BackupEngine::MIN_FREE_BYTES_FOR_BACKUP` constant exists, but the error message (`src/BackupEngine.php:310`) repeats "100MB" as a separate hardcoded string — derive the message from the constant.
- **`RemoteService::update()`** — the with/without-credentials UPDATE branches repeat 40+ lines differing only by the `:credentials` clause; build the SET fragments in an array instead.
- **`ProfileService::create()`/`update()`** — 13-key bound-param array duplicated; extract `bindProfileParams()`.
- **Nested ternaries / clever code**: RestoreEngine repeats the rollback-message ternary 6×; `OpenSslGcmEncryptor`'s constructor correctly-but-confusingly validates a key it doesn't literally use for encryption (intentional, JupitERP wire-compat — but worth a named constant to make the intent explicit rather than only a comment).
- **`RestoreEngine::$logger`** is an untyped property — violates the project's own strict-typing standard (`private $logger` → `private readonly \Closure $logger`).

---

## Notes for whoever picks this up next

- The smaller helper files (`Identifier`, `PathGuard`, `Fs`, `Lock`, `Excludes`, `Translator`, `Logger`, `ArrayTokenStore`, `FileMaintenanceGate`) are all clean, within every threshold — no action needed there.
- This audit ran immediately after a `/reliability-check` and `/security-review` pass on the same module (see `CHANGELOG.md`/git log for that work) — the guard clauses and validation added during those passes are part of why complexity climbed; they were correctness-necessary, not accidental bloat.
- Recommended order if tackling this: (1) quick wins section C first — cheap, zero risk; (2) the `BackupOps` interface (highest leverage-to-effort ratio of the structural items); (3) the RestoreEngine FK/table helper extraction (de-risks the most dangerous code without a full God-Class split); (4) the two God-Class splits and the RestoreStrategy interface, ideally timed to land just before/alongside whatever new feature actually needs the extension point, rather than as a standalone refactor.
