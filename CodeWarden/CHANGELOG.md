# Changelog

All notable changes to this project will be documented in this file.

## [v1.03.00] - 2026-01-27

### Added
- New "Dynamic Matches" section for keys ending with `_` (prefixes used for concatenation)
- New "Used Only in Documentation" section for keys in docs but not in PO or code
- New sub-options: `dynamic` and `doconly` for `-u` switch
- Doc-only count in summary
- Empty line before each sub-section header for better readability

### Changed
- Complete rewrite of key classification logic
- Keys are now properly categorized: code (PHP/JS), docs, or PO
- "Unused in Code" section now correctly shows keys in PO but not used in code
- "Missing from PO" section now only shows full keys (not dynamic prefixes)
- Improved tracking prevents keys from appearing in wrong sections
- Storage directory exclusion now only applies to root-level `/storage` (not `**/storage`)

### Fixed
- Keys marked `[used in: md]` when actually also used in code
- Dynamic prefixes (ending with `_`) no longer listed under "Missing from PO"
- Keys in paths like `app/views/.../storage/file.php` are now properly scanned

## [v1.02.00] - 2026-01-27

### Added
- Missing keys count in summary (keys found in code but not in PO files)

### Removed
- "Dynamic Matches" section that incorrectly marked keys as safe based on prefix matching

### Fixed
- Keys like `ERROR_INVALID` no longer incorrectly marked as "safe" just because `ERROR_` prefix exists in code

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
