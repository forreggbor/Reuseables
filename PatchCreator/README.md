# PatchCreator

Patch Package Builder for PatchModule. Creates `.tgz` patch archives compatible with [PatchModule](../PatchModule/) by detecting changed files via `git diff`, auto-extracting release notes from `CHANGELOG.md`, and generating a SHA-256-verified archive.

## Features

- **Git-based file detection** — Automatically finds added, modified, and deleted files between a base reference and HEAD
- **Auto-detected SQL migrations** — `database/migrations/*.sql` files in the diff are shipped in a `migrations/` directory automatically; no flag needed
- **Version auto-detection** — Reads the current version from project source files
- **CHANGELOG.md extraction** — Parses Keep a Changelog format; cumulative patches covering multiple versions produce a consolidated `| Version | Category | Description |` summary table followed by the per-version detail sections
- **SHA-256 verification** — Generates a `.sha256` hash file alongside the archive
- **Configurable excludes** — Default exclude patterns with additional user-defined patterns
- **Dry run mode** — Preview what would be packaged without creating the archive
- **Manual file list** — Override git detection with an explicit file list
- **Auto-upload** — Optionally push the finished archive to LicenseManager via a bearer token (see [AUTO_UPLOAD.md](AUTO_UPLOAD.md))

## Requirements

- Bash 4.0+
- Git
- tar, sha256sum
- grep with Perl-compatible regex (`-P` flag)
- jq (JSON processing — used for escaping and manifest validation)
- `curl` (optional — only required when `--upload` is set)

## Installation

Copy to your project's `lib/` directory or use directly from the reusables folder:

```bash
# Direct usage
/path/to/reusables/PatchCreator/PatchCreator.sh [options]

# Or symlink to /usr/local/bin
sudo ln -s /path/to/reusables/PatchCreator/PatchCreator.sh /usr/local/bin/PatchCreator.sh
```

## Quick Start

```bash
# Navigate to your project
cd /path/to/project

# Create a patch from the latest git tag (auto-detect everything)
PatchCreator.sh

# Preview without creating
PatchCreator.sh --dry-run

# Create with explicit version (SQL migrations in database/migrations/ auto-detected)
PatchCreator.sh -v 2.33.0
```

## Usage

```
PatchCreator.sh [options]
```

### Options

| Flag | Argument | Default | Description |
|------|----------|---------|-------------|
| `-d` | `<path>` | Current directory | Project root directory |
| `-v` | `<version>` | Auto-detect | Target patch version |
| `-b` | `<git-ref>` | Latest git tag | Base git reference to diff against |
| `-o` | `<dir>` | `<project>/storage/patch` | Output directory |
| `-r` | `<file>` | Auto from CHANGELOG.md | Release notes file |
| `-f` | `<file>` | — | File list override (one path per line) |
| `-e` | `<pattern>` | — | Exclude glob pattern (repeatable) |
| `-p` | `<pattern>` | APP_VERSION define | Version detection regex |
| `--no-changelog` | — | — | Skip CHANGELOG.md extraction |
| `--dry-run` | — | — | Preview without creating archive |
| `--no-validate` | — | — | Skip PatchModule compatibility validation of the manifest |
| `--upload` | — | — | Force upload to LicenseManager even if auto-detection would skip it |
| `--no-upload` | — | — | Skip upload even if a configuration source is present |
| `-y` | — | — | Auto-confirm (skip prompts) |
| `-h` | — | — | Show help |
| `--version` | — | — | Show script version |

## Examples

### Basic usage (auto-detect everything)

```bash
PatchCreator.sh
```

Searches for `define('APP_VERSION', 'X.Y.Z')` across conventional file locations, diffs against the latest git tag, extracts release notes from `CHANGELOG.md`, and creates the archive in `storage/patch/`.

### Patch against a specific commit

```bash
PatchCreator.sh -b abc1234
```

### SQL migrations (auto-detected)

Any `database/migrations/*.sql` file that appears in the git diff (added or modified) is automatically included in the `migrations/` directory of the archive. No flag is needed.

> **Warning:** PatchModule's SQL parser strips both standard block comments (`/* ... */`) and MySQL conditional comments (`/*! ... */`). Do not rely on `/*! */` for version-gated SQL in migration files — rewrite the logic as plain SQL or split it into separate migrations.

> **PHP migrations** (`database/migrations/*.php`) are skipped with a WARN and must be applied out-of-band by the operator.

### Use an explicit file list

```bash
PatchCreator.sh -v 2.33.0 -f patch_files.txt
```

Where `patch_files.txt` contains one relative path per line:

```
app/helpers/functions.php
app/services/OrderService.php
public/js/common.js
```

### Exclude additional patterns

```bash
PatchCreator.sh -e "public/uploads/*" -e "*.tmp"
```

### Non-interactive (CI/CD)

```bash
PatchCreator.sh -v 2.33.0 -b v2.32.0 -y
```

### Specify a different project directory

```bash
PatchCreator.sh -d /var/www/myproject -o /tmp/patches
```

### Auto-upload to LicenseManager

Create a `.patchcreator.local` file in the project root (never commit this file):

```json
{
  "upload_url": "https://your-licensemanager.example.com/api/v1/patches/upload",
  "token": "lcmu_your_token_here"
}
```

```bash
chmod 600 .patchcreator.local
```

Then run as usual — the upload step happens automatically after a successful build:

```bash
PatchCreator.sh -y -b v1.06.00
```

> **Note:** Always pass `-b <prev_version>` explicitly until the cumulative-base detection bug is fixed (see `AUTO_UPLOAD.md` — Pre-existing bug section). Without `-b`, the base reference may be corrupted and cause the build to fail.

To skip upload on a specific run:

```bash
PatchCreator.sh -y --no-upload
```

## Output

The script creates two files in the output directory:

```
storage/patch/
├── patch-2.33.0.tgz         # The patch archive
└── patch-2.33.0.tgz.sha256  # SHA-256 checksum
```

### Archive Contents

```
patch-2.33.0.tgz
├── manifest.json       # Package metadata
├── migrations/         # SQL migration files (omitted when none)
│   └── 2026_05_11_151403_create_foo.sql
├── files/              # Changed files (preserving directory structure)
│   ├── app/
│   ├── public/
│   └── ...
└── release_notes.md    # Release notes: consolidated summary table + per-version details
```

### manifest.json Format

```json
{
    "version": "2.33.0",
    "migrations": [
        "2026_05_11_151403_create_foo.sql"
    ],
    "files": [
        "app/helpers/functions.php",
        "app/services/OrderService.php",
        "public/js/common.js"
    ],
    "removed_files": [
        "app/legacy/OldService.php"
    ]
}
```

**`migrations`** is always present (empty array when no SQL migrations). PatchModule v1.8.0+ executes the listed files in lexicographic order and tracks each applied filename in `patch_migrations`.

**`removed_files`** is omitted entirely when no files were deleted. PatchModule v1.3.0+ deletes the listed files from the project root during installation and backs them up to the snapshot for rollback. Older PatchModule versions silently ignore the field.

## Default Exclude Patterns

The following paths are excluded from git diff results by default. Use `-f` with a manual file list to bypass these:

| Pattern | Reason |
|---------|--------|
| `.git/`, `.gitignore`, `.gitattributes` | Version control |
| `storage/` | Runtime data |
| `vendor/`, `node_modules/` | Dependencies |
| `.env`, `.env.*` | Environment config |
| `*.log` | Log files |
| `CLAUDE.md`, `.claude/` | AI tooling |
| `database/schema/` | Schema files |
| `composer.lock`, `package-lock.json` | Lock files |
| `README.md`, `CHANGELOG.md` | Documentation |
| `doc/` | Documentation folder |
| `tests/`, `phpunit.xml` | Test files |

### Composer autoload files

`vendor/autoload.php` and files directly under `vendor/composer/` are re-admitted past the `vendor/` exclude when they appear in the git diff — they ship in the patch like any other changed file. To ensure the patch carries fresh autoload maps, run `composer dump-autoload` and **commit** the result before building the patch. PatchCreator warns if tracked autoload files have uncommitted changes.

## Version Auto-Detection

The script searches for `define('APP_VERSION', 'X.Y.Z')` across the following candidate files, in order, stopping at the first match:

| Priority | Path |
|----------|------|
| 1 | `app/helpers/functions.php` |
| 2 | `webroot/app/helpers/functions.php` |
| 3 | `public/app/helpers/functions.php` |

Both `helpers` and `Helpers` folder casings are accepted at each location.

If none of the above files yield a version, the script exits with an error listing the searched paths and suggests using `-v` to specify the version explicitly.

Override the detection pattern with `-p` if the project defines its version under a different constant name.

## Exit Codes

| Code | Constant              | Meaning |
|------|-----------------------|---------|
| 0    | —                     | Success |
| 1    | —                     | General error (invalid arguments, missing files) |
| 2    | —                     | No changed or deleted files to package |
| 3    | —                     | Git error (not a repository, invalid reference) |
| 4    | —                     | User cancelled |
| 5    | `EXIT_UPLOAD_FAILED`  | Build succeeded but the upload to LicenseManager failed |

## Server Compatibility

PatchCreator output is validated by the LicenseManager server upload pipeline (v2.8.0+). All checks are satisfied by the archives this script produces:

| Check         | Details                                                                                 | Status |
|---------------|-----------------------------------------------------------------------------------------|--------|
| Extension     | `.tgz` — server allows `tgz` and `tar.gz`                                               | ✓      |
| Magic bytes   | Gzip header `1F 8B` — produced by `tar -czf`                                            | ✓      |
| MIME type     | `application/gzip` — reported by `finfo` on `.tgz` archives                            | ✓      |
| Archive parse | PHP-native `PharData` extraction, no shell execution risk                               | ✓      |
| Release notes | Optional `release_notes.md` auto-detected at archive root or one level deep            | ✓      |
| Migrations    | Optional `migrations/` directory at archive root (auto-detected from `database/migrations/`) | ✓ |

## Compatibility

Designed to work with [PatchModule](../PatchModule/) v1.00.00+. The generated archive format matches the expected structure for `PatchInstaller::install()`.

The `removed_files` manifest field requires **PatchModule v1.3.0 or later** to take effect. Archives produced by this version of PatchCreator are fully backward compatible — older PatchModule versions install the archive normally and silently ignore the new field.

By default, PatchCreator validates the generated manifest against the same rules that PatchModule v1.8.0 enforces (JSON schema, semver format, `migrations[]` array with safe filenames, path safety, no symlinks). This catches incompatible output at build time rather than at customer install time. Disable with `--no-validate` if needed.

**Breaking change in v1.03.00:** The `-m <file>` flag is removed. The manifest no longer emits `has_migration`. Requires PatchModule v1.8.0 for migration execution.