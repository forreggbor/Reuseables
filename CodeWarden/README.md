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

| Option                  | Description                                                              |
|-------------------------|--------------------------------------------------------------------------|
| `-r, --restart`         | Compile PO files and restart PHP-8.4 FPM                                 |
| `-p, --po-path <path>`  | Relative PO path (default: `locale/{LANG}/LC_MESSAGES/messages.po`)      |
| `-u, --unused [sub...]` | Analyze PO files. Sub-options: `sync`, `missing`, `unused`, `duplicates` |
| `-c, --cleanup`         | Comment out strictly unused keys                                         |
| `-f, --file`            | Save analysis report to file                                             |

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

## Configuration

Default excluded directories: `vendor`, `.claude`, `database`, `locale`, `storage`, `.idea`, `.git`

Default excluded files: `composer.*`, `.git*`

Supported languages: `en_US`, `hu_HU`
