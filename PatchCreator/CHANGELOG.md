# Changelog

All notable changes to PatchCreator will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.09.00] - 2026-05-27

| Category | Description |
|----------|-------------|
| Added    | Dual-language release notes: `# English` and `# Magyar` sections in `release_notes.md` when `CHANGELOG.hu.md` is committed alongside `CHANGELOG.md` |
| Changed  | Release-notes size warning removed; the boundary now lives in PatchModule's database column |
| Fixed    | Changelog summary table parser was English-specific; now works with any language's table headers |

### Added

- When a project commits `CHANGELOG.hu.md` alongside `CHANGELOG.md` (same Keep a Changelog format, identical `## [X.Y.Z] - date` version headers, section headings and table labels in Hungarian), PatchCreator extracts both and writes a single `release_notes.md` with `# English` and `# Magyar` H1 sections. Projects without `CHANGELOG.hu.md` produce the same bare English notes as before, byte-for-byte identical to the previous output.

### Changed

- The 60 KB release-notes warning (introduced in v1.08.01) is removed. The limit originated from PatchModule's `TEXT` database column; a future PatchModule update will widen the column to `MEDIUMTEXT`, making the boundary effectively zero.

### Fixed

- The changelog summary table parser skipped the header row by checking for the English string `Category`. Any language whose header differs (e.g. Hungarian `Kategória`) would leak into the consolidated table as a data row. The check is now separator-anchored: rows before the `|---|` separator row are always skipped regardless of their content.

---

## [1.08.01] - 2026-05-27

| Category | Description |
|----------|-------------|
| Fixed    | Multi-version patches only included the top version's changelog; all covered versions are now collected (closes #3) |

### Fixed

- When a cumulative patch spans multiple released versions, `release_notes.md` previously contained only the changelog block for the highest (target) version. All version blocks between the base version and the target are now collected.
- The generated `release_notes.md` starts with a consolidated `| Version | Category | Description |` summary table combining the summary rows from every covered version. The per-version detail sections (`### Added`, `### Fixed`, etc.) follow below without duplicating the table rows.
- A warning is emitted if the assembled release notes exceed 60 KB, since PatchModule silently discards oversized notes at install time.

---

## [1.08.00] - 2026-05-27

| Category | Description |
|----------|-------------|
| Added    | Warning when tracked autoload files have uncommitted changes that would be absent from the patch |

### Added

- When `vendor/autoload.php` or any file under `vendor/composer/` is tracked in git but has uncommitted working-tree changes, PatchCreator now prints a warning listing the affected files and prompts running `composer dump-autoload` and committing the result before building the patch. The patch ships the committed state of those files; without this warning, a freshly regenerated but uncommitted autoload map would silently be missing from the package.

---

## [1.07.01] - 2026-05-20

| Category | Description |
|----------|-------------|
| Fixed    | `-y` (auto-confirm) builds aborting because log lines leaked into a captured git ref |
| Fixed    | Rebuilding a patch whose version is already tagged at HEAD or present in the output dir produced a near-empty package |

### Fixed
- `info()`, `success()`, and `header()` now write to stderr (matching `warn()`/`error()`), so log lines no longer leak into command-substitution captures. Previously, `BASE_REF=$(resolve_cumulative_base ...)` captured both the `[INFO]` line and the ref, producing an invalid multi-line git reference and breaking subsequent `git` calls — most visibly under `-y`, where no interactive prompt could mask the failure.
- The cumulative-base resolver no longer selects an archive whose version is equal to or newer than the target version. Rebuilding `patch-X.Y.Z` while `patch-X.Y.Z.tgz` (or any newer archive) is present in the output directory now correctly resolves the base to the most recent prior-version archive. A final safety check also errors out if the resolved base ref still points to HEAD itself (e.g. when HEAD is already tagged with the target version and no prior archive exists), directing the user to pass `-b <prior-ref>` explicitly.

---

## [1.07.00] - 2026-05-19

| Category | Description |
|----------|-------------|
| Added    | Automatic patch upload to LicenseManager after a successful build |
| Changed  | Patch archives are now byte-reproducible across rebuilds |

### Added
- Patch packages can now be uploaded directly to LicenseManager after build, removing the manual admin-panel step.
- New `--upload` / `--no-upload` flags and `.patchcreator.local` config file for opt-in setup.
- New exit code `5` (`EXIT_UPLOAD_FAILED`) when the build succeeds but publishing fails.

### Changed
- Patch archives (`.tgz`) are now byte-reproducible: building the same source twice produces the same SHA-256. Enables safe automatic retry of failed uploads on CI.

---

## [1.06.00] - 2026-05-19

| Category | Description |
|----------|-------------|
| Added    | Cumulative base resolution — diffs from the last built patch package's commit SHA, not just the latest git tag |
| Added    | `built_from_commit` field in `manifest.json` anchors the next patch to an exact commit, surviving missed releases |

### Added

- **Cumulative patch base resolution** — PatchCreator now scans `OUTPUT_DIR` for the highest-version `patch-*.tgz` and reads the `built_from_commit` SHA from its `manifest.json`. If that commit is reachable from HEAD, it becomes the diff base, ensuring every file changed since the last *built* patch is included — even when one or more tagged releases were skipped without creating a patch package. Falls back to a `vX.Y.Z` tag lookup for archives built before this feature, and then to the latest reachable git tag when `OUTPUT_DIR` has no previous patches (preserving the original behavior on first run). If a previous patch is found but neither a SHA nor a matching tag can be resolved, the build aborts rather than silently missing commits.
- **`built_from_commit` in `manifest.json`** — every newly built archive embeds the HEAD commit SHA at build time. This makes the base-resolution above exact and tag-independent for all future patches. The field is ignored by all current PatchModule versions (unknown fields are not rejected). Build-time manifest validation checks that the field, when present, is a valid 40-character hex SHA.

---

## [1.05.00] - 2026-05-19

| Category | Description |
|----------|-------------|
| Added    | Allow-override list and `-i` flag let specific paths bypass broad directory excludes |
| Changed  | Composer lock, documentation, and schema reference files are now included in patches when changed |

### Added

- **Allow-override list** — a new `DEFAULT_ALLOW_OVERRIDES` array (`vendor/autoload.php`, `vendor/composer/`) lets specific paths bypass broad directory excludes. Files matched by an exclude pattern are re-admitted if they also match an allow-override, keeping `vendor/` broadly excluded while still shipping the PSR-4 autoload map when it changes.
- **`-i <pattern>` flag** — symmetric to `-e`, adds a runtime allow-override pattern. Useful when a vendor package ships files that need to reach the remote server (e.g. `-i "vendor/acme/"`). Repeatable.

### Changed

- `composer.lock`, `README.md`, `CHANGELOG.md`, `doc/`, and `database/schema/` removed from `DEFAULT_EXCLUDES` — these files are now included in patch archives when they change. Composer lock and autoload files travel with the application on servers where `composer` cannot be run; schema references and documentation are relevant on field installations without remote access.

---

## [1.04.00] - 2026-05-14

| Category | Description |
|----------|-------------|
| Changed  | Version auto-detection searches multiple conventional file locations instead of a single hardcoded path |

### Changed

- **Universal version auto-detection** — the script now searches `app/[Hh]elpers/functions.php`, `webroot/app/[Hh]elpers/functions.php`, and `public/app/[Hh]elpers/functions.php` in order, stopping at the first match. Projects with a webroot-prefixed layout (e.g. UniCMS) are auto-detected without any extra flags. Both `helpers` and `Helpers` folder casings are accepted.

---

## [1.03.00] - 2026-05-12

| Category | Description |
|----------|-------------|
| Added    | Auto-detection of SQL migrations from `database/migrations/` in git diff; shipped in `migrations/` directory inside the archive |
| Added    | `migrations[]` array in `manifest.json` (always present, empty when no migrations); validator checks each entry |
| Changed  | Manifest no longer emits `has_migration` boolean |
| Removed  | `-m <file>` CLI flag — migrations are now auto-detected, no manual flag needed |

### Added

- **Auto-detected SQL migrations** — `database/migrations/*.sql` files added or modified in the git diff are automatically collected into a `migrations/` directory inside the archive. No `-m` flag is needed. PHP files under `database/migrations/` emit a WARN and are skipped. Files in subdirectories (e.g. `database/migrations/archive/`) emit a WARN and are skipped. Deletions of migration files are silently dropped from the wire format.
- **Filename sanity checks** — each migration filename is validated against `^[A-Za-z0-9_][A-Za-z0-9._-]*\.sql$`; filenames without a valid `YYYY_MM_DD_HHMMSS_` prefix emit a WARN (may sort incorrectly).
- **`migrations[]` array in manifest** — always present; empty when no migrations. The build-time validator now checks this field: each entry must match the filename regex and have a corresponding file on disk; every file in `migrations/` on disk must be listed in the manifest.

### Changed

- Manifest no longer emits `has_migration` boolean — `count(migrations) > 0` is the signal.
- `database/migrations/` removed from `DEFAULT_EXCLUDES` — migration files are now routed to `migrations/` instead of being silently ignored.

### Removed

- **`-m <file>` CLI flag** — removed entirely. SQL migrations are auto-detected from the git diff.

---

## [1.02.00] - 2026-05-07

| Category | Description |
|----------|-------------|
| Added    | Build-time manifest validator (`--validate`, on by default) that mirrors PatchModule's install-time rules |
| Changed  | Semver prerelease allows hyphens (matches PatchModule's format); JSON escaping uses `jq` for full Unicode safety; symlink dereferencing is now explicit |

### Added

- Build-time manifest validator enabled by default: after assembling the archive contents but before creating the `.tgz`, the script now checks that `manifest.json` is a valid JSON object, `version` is a proper semver string, `files` and `removed_files` (when present) are string arrays, every path is safe (no traversal, no absolute paths, no backslash), and no symbolic links exist in the archive tree. Incompatible archives are rejected at build time rather than on the customer's machine. Use `--no-validate` to skip.

### Changed

- Semver prerelease validation now matches PatchModule's accepted range: hyphens are allowed in the prerelease segment (e.g. `2.0.0-beta-1`)
- JSON string escaping in `manifest.json` now uses `jq` instead of `sed`, correctly handling Unicode, control characters, and NUL bytes in file paths
- Symlink dereferencing in the file copy step is now explicit (`cp -L`), ensuring source symlinks are always resolved to regular files and never appear as links inside the archive

## [1.01.00] - 2026-05-02

| Category | Description                                                                               |
|----------|-------------------------------------------------------------------------------------------|
| Added    | Automatic detection of deleted files; `removed_files` array in manifest; deletion count in summary |

### Added
- Deleted files (git `--diff-filter=D`) are now automatically detected and collected into a `REMOVED_FILES` array; the same `matches_exclude()` filter that applies to added/modified files is applied to deletions
- `manifest.json` now includes a `removed_files` array listing all files to be deleted on the install side; the key is omitted entirely when there are no deletions (backward compatible with all existing PatchModule versions)
- Package summary now shows both counts: "X added/modified, Y to remove"
- Deletion-only patches (no added/modified files, only removed ones) are now valid and can be packaged
- Deletions are not detected when using `-f` (explicit file list override) — the explicit list wins; deletion auto-detection is only active in git diff mode
- PatchModule v1.3.0+ is required to act on `removed_files`; older versions silently ignore the field

## [1.00.02] - 2026-04-27

| Category | Description                                                                              |
|----------|------------------------------------------------------------------------------------------|
| Changed  | Version bump; documented compatibility with LicenseManager v2.8.x hardened upload validation |

### Changed

- Version bump only; no functional changes
- Added "Server Compatibility" section to README.md documenting that generated archives satisfy all LicenseManager v2.8.0+ upload validation checks (extension, magic bytes, MIME type, `PharData` parsing, optional release notes and migration)

## [1.00.01] - 2026-03-11

| Category | Description |
|----------|-------------|
| Fixed    | Terminal color and bold formatting not rendering correctly |

### Fixed

- Terminal color codes were stored as literal strings instead of escape characters, causing ANSI sequences to appear as raw text in the output

## [1.00.00] - 2026-02-16

### Added

- Initial release
- Git-based file detection via `git diff` with configurable base reference
- Version auto-detection from project source files (APP_VERSION define pattern)
- Automatic release notes extraction from CHANGELOG.md (Keep a Changelog format)
- SHA-256 hash generation alongside the archive
- Configurable default exclude patterns for common non-deployable paths
- Manual file list override via `-f` flag
- SQL migration file inclusion via `-m` flag
- Dry run mode (`--dry-run`) for previewing package contents
- Auto-confirm mode (`-y`) for CI/CD pipelines
- Deleted file detection with warnings
- Color terminal output with automatic detection
- Compatibility with PatchModule v1.00.00+