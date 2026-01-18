# Changelog

All notable changes to this project will be documented in this file.

## [v1.01.00] - 2026-01-18

### Added
- `--version` flag to display version information
- `require_arg()` validation for options requiring arguments
- `escape_regex()` helper for safe sed operations
- Owner format validation (user:group)
- Directory permissions (775) in permission fixing
- Confirmation prompt for cleanup (bypass with `-y`)
- Success/failure status output for all sections

### Fixed
- `LANG` variable renamed to `LANG_CODE` to avoid shadowing system locale
- Empty PO file handling with warning message
- Array globbing vulnerability replaced with `mapfile`
- Regex metacharacters now escaped in cleanup sed commands
- Added dry-run messages for all operations

### Changed
- Unknown arguments now show warning instead of silent ignore
- Unknown sub-options for `-u` now show warning

## [v1.00.00] - 2026-01-18

### Added
- PO compilation and PHP-FPM restart functionality
- PO intelligence analysis (sync, missing, unused, duplicates)
- Cleanup feature to comment out unused translation keys
- File ownership management
- File permission fixing (664/775)
- Dry-run mode
- Report file output option
