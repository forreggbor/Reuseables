# PatchCreator

Patch Package Builder for PatchModule. Creates `.tgz` patch archives compatible with [PatchModule](../PatchModule/) by detecting changed files via `git diff`, auto-extracting release notes from `CHANGELOG.md`, and generating a SHA-256-verified archive.

## Features

- **Git-based file detection** — Automatically finds added, modified, and deleted files between a base reference and HEAD
- **Version auto-detection** — Reads the current version from project source files
- **CHANGELOG.md extraction** — Parses Keep a Changelog format to include release notes
- **SHA-256 verification** — Generates a `.sha256` hash file alongside the archive
- **Configurable excludes** — Default exclude patterns with additional user-defined patterns
- **Dry run mode** — Preview what would be packaged without creating the archive
- **Migration support** — Include SQL migration files in the patch package
- **Manual file list** — Override git detection with an explicit file list

## Requirements

- Bash 4.0+
- Git
- tar, sha256sum
- grep with Perl-compatible regex (`-P` flag)
- jq (JSON processing — used for escaping and manifest validation)

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

# Create with explicit version and migration
PatchCreator.sh -v 2.33.0 -m database/migrations/2026_02_16_feature.sql
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
| `-m` | `<file>` | — | SQL migration file to include |
| `-o` | `<dir>` | `<project>/storage/patch` | Output directory |
| `-r` | `<file>` | Auto from CHANGELOG.md | Release notes file |
| `-f` | `<file>` | — | File list override (one path per line) |
| `-e` | `<pattern>` | — | Exclude glob pattern (repeatable) |
| `-p` | `<pattern>` | APP_VERSION define | Version detection regex |
| `--no-changelog` | — | — | Skip CHANGELOG.md extraction |
| `--dry-run` | — | — | Preview without creating archive |
| `--no-validate` | — | — | Skip PatchModule compatibility validation of the manifest |
| `-y` | — | — | Auto-confirm (skip prompts) |
| `-h` | — | — | Show help |
| `--version` | — | — | Show script version |

## Examples

### Basic usage (auto-detect everything)

```bash
PatchCreator.sh
```

Detects the version from `app/helpers/functions.php`, diffs against the latest git tag, extracts release notes from `CHANGELOG.md`, and creates the archive in `storage/patch/`.

### Patch against a specific commit

```bash
PatchCreator.sh -b abc1234
```

### Include a SQL migration

```bash
PatchCreator.sh -v 2.33.0 -m database/migrations/2026_02_16_new_feature.sql
```

> **Warning:** PatchModule's SQL parser strips both standard block comments (`/* ... */`) and MySQL conditional comments (`/*! ... */`). Do not rely on `/*! */` for version-gated SQL in migration files — rewrite the logic as plain SQL or split it into separate migrations.

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
├── files/              # Changed files (preserving directory structure)
│   ├── app/
│   ├── public/
│   └── ...
├── migration.sql       # SQL migration (if provided via -m)
└── release_notes.md    # Release notes (if available)
```

### manifest.json Format

```json
{
    "version": "2.33.0",
    "has_migration": true,
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

**`has_migration`** is informational metadata included for upload-pipeline tooling. PatchModule itself does **not** read this field — it triggers migration execution based solely on the presence of `migration.sql` inside the archive.

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
| `database/schema/`, `database/migrations/` | Schema files (use `-m` for migrations) |
| `composer.lock`, `package-lock.json` | Lock files |
| `README.md`, `CHANGELOG.md` | Documentation |
| `doc/` | Documentation folder |
| `tests/`, `phpunit.xml` | Test files |

## Version Auto-Detection

By default, the script looks for `define('APP_VERSION', 'X.Y.Z')` in `app/helpers/functions.php`. Override the pattern with `-p`:

```bash
# Custom version constant
PatchCreator.sh -p "const VERSION = '([^']+)'"
```

## Exit Codes

| Code | Meaning |
|------|---------|
| 0 | Success |
| 1 | General error (invalid arguments, missing files) |
| 2 | No changed or deleted files to package |
| 3 | Git error (not a repository, invalid reference) |
| 4 | User cancelled |

## Server Compatibility

PatchCreator output is validated by the LicenseManager server upload pipeline (v2.8.0+). All checks are satisfied by the archives this script produces:

| Check         | Details                                                                                 | Status |
|---------------|-----------------------------------------------------------------------------------------|--------|
| Extension     | `.tgz` — server allows `tgz` and `tar.gz`                                               | ✓      |
| Magic bytes   | Gzip header `1F 8B` — produced by `tar -czf`                                            | ✓      |
| MIME type     | `application/gzip` — reported by `finfo` on `.tgz` archives                            | ✓      |
| Archive parse | PHP-native `PharData` extraction, no shell execution risk                               | ✓      |
| Release notes | Optional `release_notes.md` auto-detected at archive root or one level deep            | ✓      |
| Migration     | Optional `migration.sql` at archive root (include via `-m`)                             | ✓      |

## Compatibility

Designed to work with [PatchModule](../PatchModule/) v1.00.00+. The generated archive format matches the expected structure for `PatchInstaller::install()`.

The `removed_files` manifest field requires **PatchModule v1.3.0 or later** to take effect. Archives produced by this version of PatchCreator are fully backward compatible — older PatchModule versions install the archive normally and silently ignore the new field.

By default, PatchCreator validates the generated manifest against the same rules that PatchModule v1.6.0 enforces (JSON schema, semver format, path safety, no symlinks). This catches incompatible output at build time rather than at customer install time. Disable with `--no-validate` if needed.