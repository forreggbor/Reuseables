# CodeWarden

A bash utility for project maintenance tasks, focusing on PO/MO localization file management and file system operations.

## Features

- **PO Compilation & PHP-FPM Restart**: Validate and compile `.po` files to `.mo`, then restart PHP 8.4 FPM
- **PO Intelligence Analysis**: Detect sync issues, missing keys, unused translations, and duplicates
- **Cleanup**: Comment out unused translation keys
- **Ownership Management**: Set file ownership recursively
- **Permission Fixing**: Apply standard permissions (775 for directories, 664 for files, 775 for scripts)

## Requirements

- Bash
- `gettext` package (for `msgfmt`)
- `sudo` access (for FPM restart, ownership, and permission changes)

## Usage

```bash
./CodeWarden.sh [OPTIONS]
```

### General Options

| Option             | Description                                    |
|--------------------|------------------------------------------------|
| `-d, --dir <path>` | Project base path (default: current directory) |
| `-y, --yes`        | Auto-confirm sensitive operations              |
| `-v, --version`    | Display version information                    |
| `--dry-run`        | Show what would happen without making changes  |

### PO Intelligence & Localization

| Option                  | Description                                                                                    |
|-------------------------|------------------------------------------------------------------------------------------------|
| `-r, --restart`         | Compile PO files and restart PHP-8.4 FPM                                                       |
| `-p, --po-path <path>`  | Relative PO path (default: `locale/{LANG}/LC_MESSAGES/messages.po`)                            |
| `-u, --unused [sub...]` | Analyze PO files. Default: `sync`, `missing`, `unused`, `duplicates`, `dynamic`. Optional: `doconly` |
| `-c, --cleanup`         | Comment out strictly unused keys                                                               |
| `-f, --file`            | Save analysis report to file                                                                   |

### System Operations

| Option                     | Description                    |
|----------------------------|--------------------------------|
| `-o, --owner <user:group>` | Set file ownership             |
| `-m, --permissions`        | Fix permissions (dirs: 775, files: 664, scripts: 775) |
| `-h, --help`               | Display help message           |

## Examples

Analyze all PO issues with dry-run:
```bash
./CodeWarden.sh -d /var/www/myproject -u --dry-run
```

Check only unused and duplicate translations:
```bash
./CodeWarden.sh -u unused duplicates
```

Check dynamic prefixes and missing keys:
```bash
./CodeWarden.sh -u dynamic missing
```

Check keys used only in documentation:
```bash
./CodeWarden.sh -u doconly
```

Compile PO files and restart FPM:
```bash
./CodeWarden.sh -d /var/www/myproject -r
```

Fix ownership and permissions:
```bash
./CodeWarden.sh -d /var/www/myproject -o www-data:www-data -m
```

Full analysis with cleanup and report:
```bash
./CodeWarden.sh -d /var/www/myproject -u -c -f -y
```

## PO Intelligence Analysis

The `-u` switch provides intelligent analysis of translation keys. By default, it runs: `sync`, `missing`, `unused`, `duplicates`, `dynamic`. The `doconly` sub-option must be explicitly specified.

| Sub-option    | Description                                                                 |
|---------------|-----------------------------------------------------------------------------|
| `sync`        | Keys missing in one language file but present in the other                  |
| `missing`     | Full keys found in code (PHP/JS) but not in PO files                        |
| `unused`      | Keys in PO files but not used in code (PHP/JS)                              |
| `duplicates`  | Duplicate `msgid` entries within PO files                                   |
| `dynamic`     | Dynamic prefixes (keys ending with `_`) used for concatenation in code      |
| `doconly`     | Keys found only in documentation files (not enabled by default)             |

### Key Classification Rules

- **Dynamic key**: Ends with `_` (e.g., `ERROR_`) - prefix used for string concatenation
- **Full key**: Does NOT end with `_` (e.g., `ERROR_INVALID`) - must exist exactly in PO
- **Dynamically protected**: Full keys that match a detected dynamic prefix (e.g., `ERROR_INVALID` is protected when `ERROR_` is used dynamically in code)

| In Code (PHP/JS) | Dynamic Prefix | In PO | Classification              |
|------------------|----------------|-------|-----------------------------|
| Yes              | -              | Yes   | ✓ OK (used correctly)       |
| Yes              | -              | No    | Missing from PO             |
| No               | Yes            | Yes   | ✓ Dynamically protected     |
| No               | No             | Yes   | Unused in code              |
| No               | -              | No    | Used only in documentation  |

### Dynamic Protection

When code uses dynamic key construction like:
```php
__('TEXT_STATUS_DELIVERY_' . strtoupper($status))
```

CodeWarden detects the `TEXT_STATUS_DELIVERY_` prefix and automatically protects all matching PO keys (e.g., `TEXT_STATUS_DELIVERY_PENDING`, `TEXT_STATUS_DELIVERY_SHIPPED`) from being flagged as unused or commented out by cleanup.

**Note:** Prefixes that match ALL keys are excluded from dynamic detection as they represent naming conventions, not dynamic usage (e.g., if all keys start with `TEXT_`, this prefix is ignored).

## Configuration

Default excluded directories: `vendor`, `.claude`, `database`, `locale`, `.idea`, `.git`

Default excluded files: `composer.*`, `.git*`

Root-level `/storage` directory is excluded (but not `**/storage` paths like `app/views/.../storage/`)

Supported languages: `en_US`, `hu_HU`
