# Changelog

All notable changes to PatchModule will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.0.1] - 2026-05-12

| Category | Description                                                                                 |
|----------|---------------------------------------------------------------------------------------------|
| Fixed    | Manual upload failed with "invalid request" — CSRF token was missing from the XHR header   |

### Fixed
- **Manual upload CSRF validation** — the upload XHR was sending the CSRF token only as a form field in the request body, while the host's CSRF adapter reads it from the `X-CSRF-Token` HTTP header. The header is now set on the XHR, consistent with all other admin requests.

---

## [2.0.0] - 2026-05-12

| Category | Description                                                                                                     |
|----------|-----------------------------------------------------------------------------------------------------------------|
| Removed  | Detached `.sig` requirement from manual upload — only the `.tgz` is uploaded and accepted                      |
| Changed  | Manual upload section in the admin UI is now a Bootstrap accordion, collapsed by default                        |
| Changed  | Trust warning rewritten to name PatrikMol Solutions Kft. as the only acceptable archive source                  |
| Security | Manual upload trust gate is now sysadmin authentication + CSRF (no signature file required or accepted)         |

### Removed
- **Manual upload `.sig` requirement** — the upload form no longer accepts a detached signature file. The archive is accepted based on sysadmin authentication and CSRF validation alone.
- **`ArchiveSignatureVerifierInterface`** — the contract is deleted; only the auto-flow `SignatureVerifierInterface` remains.
- **`OpenSslArchiveSignatureVerifier`** — the implementation is deleted; no `openssl dgst` subprocess calls are made during manual upload.
- **Config keys** `archive_signature_verifier` and `max_signature_size` — no longer read or documented.
- **Accessors** `getArchiveSignatureVerifier()` and `getMaxSignatureSize()` removed from `PatchModule`.
- **`AdminActions` constructor parameters** `$archiveSignatureVerifier`, `$expectedPublicKeyPem`, `$maxSignatureSize` removed.
- **Error codes** `upload_invalid_signature`, `upload_missing_pinned_key`, `upload_missing_signature` removed from `ErrorCode` and all locale files.
- **Translation keys** `TEXT_PATCH_ERROR_UPLOAD_INVALID_SIGNATURE`, `TEXT_PATCH_ERROR_UPLOAD_MISSING_PINNED_KEY`, `TEXT_PATCH_ERROR_UPLOAD_MISSING_SIGNATURE`, `TEXT_LABEL_SIGNATURE_FILE`, `TEXT_LABEL_SIGNATURE_FILE_HINT`, `TEXT_MANUAL_UPLOAD_VERIFYING` removed from en_US and hu_HU.
- **`/usr/bin/openssl` runtime dependency** — no longer required for manual upload.

### Changed
- **Manual upload section** — the upload card is now a Bootstrap accordion, collapsed by default. Sysadmins must expand it to access the upload form.
- **Trust warning** (`TEXT_MANUAL_UPLOAD_TRUST_WARNING`) — rewritten to explicitly name PatrikMol Solutions Kft. as the only acceptable archive source and to state that the sysadmin is responsible for verifying the source before installing.
- **`expected_public_key_pem`** — now documented and used for auto-flow (patch-server key pinning) only; the manual upload flow no longer reads this config key.

---

## [1.8.0] - 2026-05-12

| Category | Description                                                                                                    |
|----------|----------------------------------------------------------------------------------------------------------------|
| Added    | Multi-file SQL migrations — patches ship a `migrations/` directory; PatchModule executes files in order       |
| Added    | `patch_migrations` table with automatic first-run bootstrap and backfill from `database/migrations/*.sql`     |
| Changed  | Manifest `migrations[]` array replaces the legacy `has_migration` boolean                                     |
| Changed  | `execute_migration` step log promoted from DEBUG to INFO — no-migration case is now visible in production     |
| Removed  | Legacy single `migration.sql` at archive root; manifest `has_migration` boolean                               |

### Added
- **Multi-file SQL migrations** — PatchCreator auto-detects `database/migrations/*.sql` files from the git diff and ships them in a `migrations/` directory inside the patch archive. PatchInstaller v1.8.0 executes them in lexicographic (chronological `YYYY_MM_DD_HHMMSS_` prefix) order using `PatchMigrator::executeMigrationsDirectory()`.
- **`patch_migrations` tracking table** — each applied SQL file is recorded by filename (UNIQUE constraint). Re-installing the same patch is a no-op for SQL. The table is created automatically on first use (`CREATE TABLE IF NOT EXISTS`) and backfilled from the project's existing `database/migrations/*.sql` files (non-recursive, `.sql` only) — no manual operator step required for existing installations upgrading from v1.6.x.
- **`PatchMigrator::executeMigrationsDirectory()`** — new public method; replaces the single-file `executeMigration()` path for patch installs.
- **`PatchMigrator::ensureMigrationsTable()`** — private self-bootstrap: creates `patch_migrations`, backfills from `database/migrations/`, latches on the instance so it runs at most once.
- **`schema/patch_migrations.sql`** — canonical schema file for fresh integrations. Load after `patch_history.sql` (FK dependency).

### Changed
- **Manifest format** — `manifest.migrations[]` (always-present array) replaces `has_migration` (boolean). Empty array = no migrations.
- **`execute_migration` step** — log level promoted from DEBUG to INFO when there are no migrations to run. The step is always ticked in the progress tracker.
- **PatchFileManager** — `migrations` is now a required manifest field; each entry validated against `^[A-Za-z0-9_][A-Za-z0-9._-]*\.sql$`; post-extraction realpath/symlink guard on `migrations/` directory contents.

### Removed
- **Legacy `migration.sql` at archive root** — PatchInstaller v1.8.0 only processes the `migrations/` directory. Old archives using the legacy format are not supported.
- **`has_migration` boolean in manifest** — removed from both PatchCreator output and PatchFileManager validation.

---

## [1.7.1] - 2026-05-12

| Category | Description                                                                          |
|----------|--------------------------------------------------------------------------------------|
| Fixed    | Upload form allowed submission without selecting files, with no feedback to the user |

### Fixed
- **Upload form silent no-op on missing files** — the manual upload form had `novalidate` set, which disabled the browser's built-in `required` attribute enforcement. Clicking Upload with no files selected caused the submit handler to return silently with no toast, no validation message, and no visual feedback. Removed `novalidate`; the browser now intercepts the missing-file case before the submit event fires and shows a native validation callout.

---

## [1.7.0] - 2026-05-12

| Category | Description                                                                                                       |
|----------|-------------------------------------------------------------------------------------------------------------------|
| Added    | Manual patch upload — sysadmin can upload a .tgz + .sig pair and install it without an internet connection       |
| Added    | `ArchiveSignatureVerifierInterface` + `OpenSslArchiveSignatureVerifier` for detached RSA-SHA256 signature checks |
| Added    | 10 new `UPLOAD_*` error codes and matching locale keys in en_US and hu_HU                                        |

### Added
- **Manual patch upload** — a new upload card on `/admin/patch-management` lets a sysadmin upload the `.tgz` patch archive and its detached `.sig` signature file and install it locally without connecting to the patch server. Works when the remote channel is unavailable, the license is expired, or the server is unreachable. Signature is verified against the pinned `expected_public_key_pem` before the archive is accepted.
- **`ArchiveSignatureVerifierInterface`** — new contract for detached binary signature verification, separate from the existing JSON-payload `SignatureVerifierInterface`.
- **`OpenSslArchiveSignatureVerifier`** — default implementation that calls `/usr/bin/openssl dgst -sha256 -verify` via `proc_open` (array syntax — no shell injection). Streams the archive without loading it into PHP memory.
- **`PatchInstaller::installFromLocalArchive()`** — new pipeline entry point that reuses the existing step helpers (extract → backup → migrate → copy → remove → update_version → verify → cleanup), skipping only the download step.
- **`PatchModule::installFromUploadedArchive()`** — facade method for the manual install path.
- **New config keys**: `archive_signature_verifier`, `max_upload_size` (default 100 MB), `max_signature_size` (default 10 KB).
- **`findUploadedAvailablePatches()`** on `DatabaseAdapterInterface` / `PdoAdapter` / `CallableAdapter` — returns manually uploaded patches with `status='available'` so they appear in the merged available-patches table.
- **`sweepStaleTmpFiles()` extended** — now also removes orphaned `patch_uploaded_*.tgz` files whose `patch_history` row is missing or has a terminal status.
- **Upload card always visible** — the upload card renders even when the remote channel is disabled, preserving the disaster-recovery path.
- **"Manual upload" badge** — available-patches table shows a Secondary badge next to the version for rows sourced from a manual upload.
- **22 new locale keys** in both `en_US` and `hu_HU`, covering upload button, card heading, file input labels, trust warning, progress messages, and all 10 upload error codes.
- **`POST {base}/upload`** endpoint added to the wire format (10th admin endpoint).

---

## [1.6.4] - 2026-05-12

| Category | Description                                                                                          |
|----------|------------------------------------------------------------------------------------------------------|
| Fixed    | Banner suppression guard could throw a notice when `$disabled` was not set in the calling scope      |
| Docs     | README API reference corrected and expanded with missing methods and error codes                     |

### Fixed
- **`_banner.php` null-safety guard** — the early-return condition `if ($disabled || empty($patches))` could emit a PHP notice when `$disabled` was not defined in the including scope. Changed to `if (($disabled ?? false) || empty($patches))`. No behavioural change when the variable is properly set.

### Docs
- **README API reference corrected** — `install()` now documents the `?string $language = null` parameter; `rollback()` now documents the `?int $userId = null` parameter.
- **README API reference expanded** — new **Admin UI** section documents `getAdminActions()`, `isAvailable()`, and `getBaseUrl()`; new **Accessors** section documents `getDatabase()`, `getVersionResolver()`, `getProgressTracker()`, and `getMaintenanceMode()`.
- **README error codes table** — added `invalid_manifest_schema` and `verification_failed`.

---

## [1.6.3] - 2026-05-11

| Category | Description                                                                                                     |
|----------|-----------------------------------------------------------------------------------------------------------------|
| Added    | New translation key `TEXT_PATCH_ERROR_REQUEST_FAILED` exposed as `genericError` in the JS i18n config          |
| Changed  | All fetch operations now use a unified `parseResponse` helper for consistent error handling and CSRF rotation   |

### Added
- **`TEXT_PATCH_ERROR_REQUEST_FAILED` translation key** — added to both `en_US` and `hu_HU` locale files and exposed as `genericError` in the `data-i18n` JSON on `#patch-mount`. The JS client now shows a localised fallback toast whenever a generic network or server error occurs in `dismissAll`, `dismissPatch`, `checkUpdates`, `verifyPassword`, or `installCurrent`.

### Changed
- **`parseResponse` helper introduced** — all five fetch operations (`dismissAll`, `verifyPassword`, `installCurrent`, `checkUpdates`, `dismissPatch`) now route through a unified `parseResponse(response)` function that parses JSON safely, rotates the CSRF token, and returns a normalised `{ok, data, errorMessage}` object. Previously `dismissAll` and `dismissPatch` silently ignored server errors; they now display a toast. `checkUpdates` now re-enables its button on failure. `verifyPassword` uses the i18n fallback instead of a hardcoded English string.

---

## [1.6.2] - 2026-05-07

| Category | Description                                                                                  |
|----------|----------------------------------------------------------------------------------------------|
| Fixed    | "Details" and "Install" buttons in the available patches table were incorrectly disabled     |
| Fixed    | Update banner rendered as unstyled plain text — CSS rules for the banner were missing        |

### Fixed
- **Action buttons in the available patches table are now always enabled** — the "Details" and "Install" buttons were disabled whenever the patch had no matching `patch_history` row with status `available` or `downloading`. This happened when the patch cache was refreshed after a manual clear or after a previous row had moved to `failed`/`rolled_back`. The admin page now self-heals: if no suitable row exists it creates one before rendering, so the buttons are always clickable. On any DB failure during self-healing, the error is logged and the page still renders.
- **Update banner now has a styled design** — the sticky top banner advertising available updates had no visual appearance because its custom CSS classes (`patch-update-banner`, `patch-banner-inner`, etc.) had no rules. The missing styles have been added using the same dark-blue gradient as the modal header, with responsive stacking on narrow screens.

---

## [1.6.1] - 2026-05-07

| Category | Description                                                                                          |
|----------|------------------------------------------------------------------------------------------------------|
| Fixed    | "Check for updates" button showed no feedback when nothing was found or when the check failed        |
| Added    | Three translation keys for update-check outcomes (`CHECK_FAILED`, `CHECK_FOUND`, `CHECK_NO_UPDATES`) |

### Fixed
- **`checkUpdates()` now shows a toast instead of unconditionally reloading** — previously `PatchUpdate.checkUpdates()` called `window.location.reload()` regardless of `data.available`. The page now reloads only when `data.available === true` (new patches found). When `data.available === false` a localised "Your installation is up to date." toast is shown and the button re-enables. Network or server errors that previously left the button permanently disabled now show a "Update check failed." toast and also re-enable the button.

### Added
- **Three new translation keys** for update-check outcomes — `TEXT_MESSAGE_PATCH_CHECK_FAILED`, `TEXT_MESSAGE_PATCH_CHECK_FOUND`, and `TEXT_MESSAGE_PATCH_CHECK_NO_UPDATES` added to both `locale/en_US/messages.php` and `locale/hu_HU/messages.php`. `CHECK_FOUND` is included for symmetry and future use; the other two are wired into `checkUpdates()`.
- **`checkFailed`, `checkFound`, `checkNoUpdates` i18n keys** exposed via the `data-i18n` JSON on `#patch-mount` so the JS client picks them up without page reload.

---

## [1.6.0] - 2026-05-07

| Category | Description                                                                                                    |
|----------|----------------------------------------------------------------------------------------------------------------|
| Added    | `base_url` config key and `PatchModule::getBaseUrl()` for self-contained admin UI URL management              |
| Added    | Rollback audit events (`rollback_patch`, `rollback_patch_failed`) — closes compliance gap vs. old controllers |
| Added    | `AdminActions::getViewTranslator()` — public closure factory for host-embedded module views                    |
| Added    | `CsrfRotatableInterface` — optional contract enabling per-request CSRF token rotation                          |
| Changed  | Admin views now receive the base URL automatically — host controllers no longer inject it manually              |
| Changed  | Every successful mutating response now includes `csrf_token` so JS always has the current token               |
| Changed  | `PatchModule::rollback()` and `PatchInstaller::rollback()` accept optional `?int $userId`                      |
| Security | `base_url` validated at construction against eight rules to prevent unsafe URL patterns                        |
| Security | Rollback audit trail closes a compliance gap; error responses never include or rotate the CSRF token           |

### Added
- **`base_url` config key** — required when `auth_adapter` and `csrf_adapter` are set. Accepted format: same-origin path starting with `/`, no trailing slash, no `..`, `?`, `#`, `//`, whitespace, control characters, or percent-encoded sequences. Fails fast at `PatchModule::__construct()` with a descriptive error, eliminating a silent integration foot-gun where a forgotten base URL caused all admin JS endpoints to 404.
- **`PatchModule::getBaseUrl(): string`** — returns the validated and normalised admin UI base path. Use `$module->getBaseUrl()` in the host layout banner include instead of repeating the literal URL string.
- **Rollback audit events** — `PatchInstaller::doRollback()` now emits `rollback_patch` on success and `rollback_patch_failed` on failure via `LoggerInterface::activity()`. User ID is threaded through from `AdminActions::rollback()` via the new `?int $userId` parameter. Internal rollbacks triggered by install failures also emit these events (with whatever `userId` the install carried), alongside the existing `install_patch_failed` event — the dual emission mirrors the old per-project `PatchController` behaviour and gives each operation its own audit row.
- **`AdminActions::getViewTranslator(): \Closure`** — returns a variadic-to-array bridge closure identical to the one already used internally by the module's index view. Hosts embedding module views from their own layout (e.g. `_banner.php` in an admin layout include) should call `$tr = $actions->getViewTranslator()` rather than building the bridge manually.
- **`CsrfRotatableInterface`** (`src/Contracts/CsrfRotatableInterface.php`) — optional interface with a single `rotate(): string` method. Hosts whose CSRF implementation generates a new token after each mutating action implement this alongside `CsrfAdapterInterface`. The module calls `rotate()` exactly once per successful mutating action and includes the new token in the response. Existing adapters that implement only `CsrfAdapterInterface` are unaffected.

### Changed
- **`AdminActions::index()` injects `baseUrl` automatically** — the data array returned by `index()` now includes `baseUrl` sourced from the module config. Host controllers no longer need to merge the URL in before calling `renderView()`.
- **Integration guide and README updated** — `INTEGRATION-GUIDE.md` Steps 5, 7, 8, and 11 and `README.md` config table and quick-start checklist updated to document the new features.
- **`base_url` validation extracted to `validateBaseUrl()`** — the eight validation rules are now in a dedicated private method, keeping `validateConfig()` focused on core required keys.
- **Every successful mutating response includes `csrf_token`** — `check`, `dismiss`, `dismissAll`, `verifyPassword`, `install`, and `rollback` now always return `csrf_token` in the response body. When the adapter implements `CsrfRotatableInterface` the value is a freshly rotated token; otherwise it is the unchanged session token. The JS client applies it on every response, keeping its internal token in sync without page reloads.
- **`PatchModule::rollback()` and `PatchInstaller::rollback()` accept `?int $userId = null`** — backwards-compatible; calling `rollback($id)` without a user ID continues to work. The parameter is forwarded to the audit event.
- **`AdminActions::index()` uses `getViewTranslator()`** — removed the inline closure duplicate; the `'tr'` data key is now provided by the new public factory method.

### Security
- **`base_url` validated at construction** — eight sequential checks reject: non-string or empty value; path not starting with `/`; protocol-relative prefix (`//…`); absolute URL (`scheme://…`); path traversal (`..`); percent-encoded sequences (`%`); query string (`?`), fragment (`#`), whitespace, or non-ASCII characters (including high-byte UTF-8, 0x80–0xFF); and consecutive slashes inside the path (`//`). Any violation throws `\InvalidArgumentException` immediately, before the object is used.
- **Rollback audit trail** — admin-initiated and automatic rollbacks are now audit-logged, closing a compliance gap where rollback operations were invisible to the activity log. The host's existing `LoggerInterface` implementation receives these events without any changes.
- **CSRF token not returned on error paths** — `csrfError()`, `forbidden()`, and all 4xx/5xx error responses do not call `rotate()` or append `csrf_token`. This prevents an attacker from repeatedly triggering CSRF validation errors as a token-churn denial-of-service against the admin user.

## [1.5.1] - 2026-05-07

| Category | Description                                                    |
|----------|----------------------------------------------------------------|
| Added    | Logging integration guide covering every emitted log message and activity event |

### Added
- **`doc/LOGGING.md`** — complete reference for implementing `LoggerInterface` in the host application: all four activity events with exact payload shapes, every `log()` message with level and source line, constructor wiring examples, and a minimal ready-to-use implementation.

## [1.5.0] - 2026-05-06

| Category | Description                                                                      |
|----------|----------------------------------------------------------------------------------|
| Added    | Built-in admin UI: banner, modal, index page, multi-patch queue, progress view   |
| Added    | AuthAdapterInterface, CsrfAdapterInterface, TranslatorInterface contracts        |
| Added    | AdminActions class: 9 typed HTTP action methods, no framework assumptions        |
| Added    | PatchHistoryStatus class with constants for all patch_history status values      |
| Added    | WIRE-FORMAT.md and INTEGRATION-GUIDE.md for host-side integration                |
| Fixed    | escapeHtml and showNotification helpers defined in patch-update.js               |
| Changed  | AdminActions refactored: install lock, path validation, and response building extracted to private helpers |
| Changed  | patch-update.js refactored: install UI setup, response handling, and progress bar update extracted to named methods |
| Security | Failed password attempts are now logged by AdminActions                          |

### Fixed
- **`escapeHtml` and `showNotification` now self-contained** — both helper functions were called in `js/patch-update.js` but never defined there, causing a `ReferenceError` crash in all HTML-building code paths. Both are now defined at the top of the file with a host-override guard so hosts that already provide a global version are unaffected.

### Security
- **Failed password attempts logged** — `AdminActions::verifyPassword()` now calls `error_log()` on every incorrect password so failed attempts appear in the PHP error log unconditionally, regardless of whether the host has throttling middleware in place.

### Changed
- **`AdminActions` install/rollback lock handling centralised** — duplicate lock-acquire/release code extracted to `withInstallLock(callable $fn)`. Both `install()` and `rollback()` now delegate lock management to this single helper; the lock can never be left held on exception.
- **`AdminActions::install()` input validation extracted** — five guard clauses (sysadmin, CSRF, auth token, ID bounds, progress token format) moved to `validateInstallRequest()` so `install()` is a concise orchestrator rather than a wall of defensive checks.
- **Path-safety check centralised in `AdminActions`** — the repeated three-condition path guard in `buildFilesManifest()` extracted to `isUnsafePath()`, eliminating duplicated logic between the files and removed-files loops.
- **`PatchHistoryStatus` constants replace bare string literals** — `'completed'`, `'rolled_back'`, and the other history status values are now referenced via `PatchHistoryStatus::COMPLETED` etc. throughout `AdminActions` and `views/admin/index.php`.
- **`patchStatusBadge()` guarded against redeclaration** — the view-local function is now wrapped in `if (!function_exists(...))` so including `index.php` twice in the same request no longer causes a fatal PHP error.
- **`patch-update.js` install flow split into focused methods** — `startInstall()` reduced from 107 to 42 lines by extracting `setupInstallUI(currentPatch, createBackup)` (modal state and steps list) and `handleInstallResponse(data)` (success/failure branching); nesting depth reduced from 5 to 2.
- **`updateProgressBar(steps)` extracted from `updateStepsUI()`** — progress bar width, colour, and animation state are now updated by a dedicated method called at the end of the steps loop, keeping icon rendering and progress display separate.

### Added
- **`PatchHistoryStatus` class** — central constants for all `patch_history.status` values (`available`, `downloading`, `installing`, `completed`, `failed`, `rolled_back`), following the same pattern as `ErrorCode`. Eliminates bare string literals in `AdminActions` and the admin index view.
- **Built-in admin UI views** — `views/admin/index.php`, `_modal.php`, and `_banner.php` provide a complete patch-management interface. No per-project view duplication needed.
- **`AdminActions` class** — 9 methods, one per HTTP endpoint (index, check, details, dismiss, dismissAll, verifyPassword, install, progress, rollback). Each returns a plain `['status' => int, 'data' => array]` array — no `echo`, no `header()`, no superglobals. Host controllers are 5-line pass-throughs.
- **`AuthAdapterInterface`** — framework-agnostic contract for sysadmin check, password verify, user map, and one-time install authorization tokens. Replaces direct `$_SESSION` writes so Laravel, Symfony, JWT, and custom session backends all work.
- **`CsrfAdapterInterface`** — wraps the host CSRF token getter and validator.
- **`TranslatorInterface`** — optional; module falls back to its own `locale/en_US/messages.php` when not provided.
- **`PatchModule::getAdminActions()`** — lazy factory returning an `AdminActions` instance, or `null` when auth/csrf adapters are not configured.
- **`PatchModule::isAvailable()`** — returns `{enabled: bool, reason: string}`; used by the banner to skip all DB queries when the module is not fully configured.
- **CSP-strict views** — no inline `<script>`, `<style>`, or `onclick=` anywhere. All PHP-to-JS config bridged via `data-*` attributes on `#patch-mount` / `#patchUpdateBanner`.
- **Multi-patch install token chaining** — install success response includes `next_install_token` when `has_next` is true, so the user does not need to re-enter their password for queued patches.
- **`css/patch-update.css`** — extracted from per-project inline styles; step-icon, version-card, queue-item, and banner CSS in one file.
- **`doc/WIRE-FORMAT.md`** — frozen HTTP contract for all 9 endpoints.
- **`doc/INTEGRATION-GUIDE.md`** — complete host-side recipe including adapter samples, route examples for Slim / Laravel / vanilla PHP, translator wiring, security requirements, and migration recipes for TrafficJournal, JupitERP, and UniCMS.

## [1.4.0] - 2026-05-06

| Category | Description                                                                                             |
|----------|---------------------------------------------------------------------------------------------------------|
| Added    | Atomic file writes with mode preservation, install lock, abort protection, and Twig cache invalidation |
| Added    | DELIMITER-aware SQL parser for stored procedures and triggers                                           |
| Added    | Completed install snapshots and backups are now retained for rollback (configurable, default 3)        |
| Fixed    | Manifest schema validation now rejects non-string file paths and invalid version formats               |
| Fixed    | Verification step now confirms every manifest file exists and the new version reads back correctly     |
| Fixed    | Maintenance mode was never engaged during installs; it is now enabled for the full install/rollback    |

### Added
- **Atomic file writes** — files are first written to a `.patchtmp` staging name, then renamed into place (POSIX-atomic); avoids serving a half-written file if PHP is killed mid-install. A cross-filesystem `EXDEV` fallback (copy + unlink) is used when source and destination are on different mounts.
- **File mode preservation** — the original `chmod` value of every file being replaced or removed is recorded in `snapshot_meta.json` at snapshot time and restored on rollback, so executable scripts and configuration files keep their permissions after an install or rollback.
- **Stale temp file cleanup** — at the start of every install, any `*.patchtmp` files older than 24 hours are deleted from the project root to prevent debris from previously killed installs.
- **`ignore_user_abort(true)` + `set_time_limit(0)`** — the install/rollback entry point now survives a browser disconnect and sets no PHP execution time limit for the duration of the operation. A `finally` block guarantees maintenance mode is disabled even on an uncaught exception.
- **Maintenance mode activation** — `MaintenanceMode::enable()` is called at the start of every install and rollback, and disabled in a `finally` block. It was previously wired into the module but never called during the install pipeline.
- **Configurable compiled-cache flushing** — a new `cache_paths_to_clear` config key accepts a list of absolute directory paths (e.g. the Twig compiled-template cache). The module empties those directories after each file-mutation step and after every rollback, preventing stale rendered output.
- **Configurable rollback retention** — a new `keep_last_snapshots` config key (default `3`) controls how many completed installs retain their snapshot and DB backup for later rollback. Older snapshots are pruned automatically after each successful install. Failed install snapshots are kept until manually dismissed.
- **DELIMITER-aware SQL parser** — `PatchMigrator::parseSqlStatements()` now recognises `DELIMITER` directives so patches can ship stored procedures, triggers, and functions. Arbitrary terminators (`//`, `$$`, `;;`, etc.) are supported. Custom terminators are stripped from statement bodies before execution.
- **Centralised error codes** — `ErrorCode` class holds constants for all internal error code strings (`INVALID_MANIFEST_SCHEMA`, `INSTALL_IN_PROGRESS`, `VERIFICATION_FAILED`, and all pre-existing codes). All internal usages migrated to the constants.

### Fixed
- Manifest schema validation now checks that `version` matches a strict semver pattern (`x.y.z` or `x.y.z-pre`) and that every element in `files` and `removed_files` is a `string` — previously a mixed-type array or a path like `../../etc/passwd` would not be rejected at the schema level.
- Verification step (`verifyInstallation`) now checks that every file listed in `manifest.files` exists at its destination path, and reads back the stored `app_version` to confirm it matches the newly installed version. A scan-fallback install (empty `manifest.files`) re-scans the archive directory for the file list so that fallback installs are also verified. File sizes are compared against the archive source rather than requiring a non-zero size, so legitimate zero-byte files pass verification.
- Maintenance mode was injected into the module but never called during the install or rollback pipeline; it is now unconditionally engaged for the full duration of the operation.
- Rollback mode and size checks are now applied consistently when restoring files removed by a patch (previously only applied to replaced files).

## [1.3.0] - 2026-05-02

| Category | Description                                                                                    |
|----------|------------------------------------------------------------------------------------------------|
| Fixed    | Download precondition detection was silently broken — the `not_recently_verified` retry path now works correctly |
| Added    | Full server error code mapping, 429 rate-limit handling with `Retry-After`, file deletion support, `ServerErrorMapper` utility |
| Changed  | Translations migrated from gettext `.po` to PHP-array `locale/{lang}/messages.php`            |
| Security | Path-traversal hardening via `safeJoin()`, symlink rejection in archives and directory scans  |

### Fixed
- `PatchDownloader` was reading `error.message` from the server error response by casting the `error` object to string, which always produced the literal `"Array"`. The `not_recently_verified` precondition check never matched — the `license_verify_callback` retry path was effectively dead code in all previous versions. This is now corrected by the new `ServerErrorMapper` class that properly reads `error.message`.

### Added
- `ServerErrorMapper` — shared utility that maps every documented server HTTP response to a stable client `error_code` string and optional `retry_after` integer
- `PatchDownloader::download()` now returns `error_code` and `retry_after` keys for all failure paths, covering: `not_recently_verified`, `invalid_license`, `license_revoked`, `license_expired`, `license_ip_mismatch`, `package_mismatch`, `rate_limited`, `signing_unavailable`, `server_error`, `network_error`
- `PatchChecker::checkForUpdates()` now returns `error_code` and `retry_after` for failed server calls (both per-IP router-level 429 and endpoint-level 429 are handled)
- `PatchInstaller::install()` return array now includes `error_code` and `retry_after`; `error_message` stored in `patch_history` is prefixed with `[error_code]` for easy grepping
- 429 rate-limit handling with `Retry-After` header parsing (supports both delta-seconds and HTTP-date forms)
- `PatchFileManager::removeFiles()` — deletes obsolete files listed in `manifest.removed_files`; validates each path via `safeJoin()` before deletion; invalidates OPcache per file; missing files are logged as INFO and counted as success (idempotent)
- Manifest `removed_files` optional array support: files listed are deleted from the project root after `copyFiles()` succeeds; fully backward compatible (absent field treated as empty)
- Snapshot (`snapshot_meta.json`) now includes a `files_to_remove` list: existing files scheduled for deletion are backed up before removal, and restored on rollback
- `TEXT_PATCH_STEP_REMOVE_FILES` install step added to the progress pipeline
- `doc/reviewed/ERROR-CODES.md` — full reference of all client `error_code` values, the server conditions that produce them, and recommended translation keys
- `doc/reviewed/PATH-SAFETY.md` — documents `safeJoin()`, symlink rejection, and the `invalid_manifest_path` / `invalid_archive` error codes

### Changed
- Translations migrated from gettext `.po` files to project-standard PHP arrays at `locale/en_US/messages.php` and `locale/hu_HU/messages.php`. No compilation step needed. Old `locale/{lang}/LC_MESSAGES/patch.po` files removed.
- `CurlHttpClient::postJson()` now captures and lowercases response headers (matching `downloadFile()` behaviour), so `Retry-After` headers from `/patches/check` are accessible to callers

### Security
- `PatchFileManager::safeJoin()` private helper validates every file path before any filesystem operation: rejects empty strings, absolute paths (Unix and Windows), backslashes, NUL bytes, and `..`/`.`/empty segments; any traversal attempt aborts the install with `error_code = 'invalid_manifest_path'`
- Archive extraction is followed by a full symlink scan; any symlink found in the extracted tree causes the install to fail with `error_code = 'invalid_archive'` and the extraction directory is cleaned up
- `scanDirectory()` skips symlinks in the project root during the post-install verification scan
- `ExecTarAdapter` now passes `--no-same-owner --no-same-permissions` to `tar` to prevent archives from forcing unexpected file ownership or permissions

## [1.2.0] - 2026-04-27

| Category | Description                                                                      |
|----------|----------------------------------------------------------------------------------|
| Added    | Patch metadata signature verification against server public key (RSA/Ed25519)    |
| Changed  | Download precondition error handled with automatic license re-verification retry |

### Added
- `SignatureVerifierInterface` — contract for signature verification implementations
- `OpenSslSignatureVerifier` — default implementation using `openssl_verify` with SHA-256
- Patch metadata signature verification in `PatchChecker`: patches signed by the server are verified against the public key returned alongside them; patches that fail verification are excluded from the cache
- Public key pinning: the optional `expected_public_key_pem` config key pins the trusted server key; patches presenting a different key are rejected regardless of whether the signature itself could be verified
- Signature, public key, and expiry fields are now stored in the patch cache so re-verification is possible when server adds expiry to responses
- `license_verify_callback` config option: a callable invoked before each download attempt to keep the server-side recently-verified window fresh

### Changed
- `PatchDownloader::download()` now returns an `error_code` key (`'not_recently_verified'`) when the server rejects the download because the license has not been checked recently enough (HTTP 403 with `license_key_not_recently_verified` or the legacy `license_key_ip_mismatch` error code)
- `PatchInstaller::install()` automatically retries the download once after invoking `license_verify_callback` when the server returns a recently-verified precondition failure; if the retry also fails, the error surfaces normally
- `HttpClientInterface::downloadFile()` now includes a `body` key in failure responses so error codes returned by the server can be inspected by the downloader

## [1.1.0] - 2026-03-11

| Category | Description                                                                        |
|----------|------------------------------------------------------------------------------------|
| Added    | Built-in database backup adapter supporting both MariaDB and MySQL                 |
| Fixed    | Backup is now skipped when a patch has no SQL migration, preventing wasted time and corrupt rollback state |
| Fixed    | MariaDB deprecation warnings no longer corrupt SQL dump files                      |
| Fixed    | mysqldump failures in piped commands are now correctly detected and reported       |

### Added
- `MysqldumpBackupAdapter` — ready-to-use `BackupAdapterInterface` implementation that auto-detects `mariadb-dump` or `mysqldump` (and `mariadb` or `mysql` for restore), eliminating the need for projects to write their own backup adapter

### Fixed
- Database backup is no longer created when the patch contains no `migration.sql` — previously a full dump was always made before extraction, even for file-only patches
- MariaDB deprecation warnings were piped into the SQL dump via `2>&1`, silently corrupting the backup file; stderr is now redirected to a temp file and kept separate from the SQL stream
- Piped shell commands (`mysqldump | gzip`, `gunzip | mysql`) now use `set -o pipefail` so a failure in the first command is not masked by the second command's exit code
- Installation pipeline step order updated: backup is now created after extraction so the presence of `migration.sql` can be confirmed first

## [1.0.0] - 2026-02-16

| Category | Details                                                     |
|----------|-------------------------------------------------------------|
| Type     | Initial release                                             |
| Summary  | Framework-agnostic patch management extracted from FlowerShop |
| Files    | 22 files created                                            |

### Added
- `PatchModule` main facade with configuration-driven adapter wiring
- 6 adapter interfaces: `DatabaseAdapterInterface`, `HttpClientInterface`, `ArchiveAdapterInterface`, `BackupAdapterInterface`, `LoggerInterface`, `VersionResolverInterface`
- Default adapters: `PdoAdapter`, `CallableAdapter`, `CurlHttpClient`, `ExecTarAdapter`, `PharTarAdapter`
- `PatchChecker` for update checking, caching, and dismiss management
- `PatchDownloader` with SHA-256 hash verification
- `PatchInstaller` orchestrator with full pipeline (preflight → backup → download → install → verify → cleanup)
- `PatchFileManager` for file copy, selective snapshot, and rollback
- `PatchMigrator` for SQL statement parsing and execution with FK check toggling
- `ProgressTracker` for atomic JSON file-based progress tracking
- `MaintenanceMode` for flag-file-based maintenance mode toggle
- Database schema: `patch_history` and `patch_settings` tables
- Self-contained maintenance page view (Bootstrap 5, no framework dependencies)
- Frontend JavaScript state machine (`patch-update.js`) for modal and progress UI
- Bilingual translations (Hungarian, English) in gettext PO format