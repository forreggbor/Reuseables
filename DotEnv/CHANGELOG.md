# Changelog

All notable changes to this project will be documented in this file.

## [v1.00.01] - 2026-08-01

| Type    | Count |
|---------|-------|
| Added   | 0     |
| Changed | 0     |
| Fixed   | 1     |
| Removed | 0     |

### Fixed
- `RequiredValidator` class docblock's usage example chained `->required()` directly onto `load()`'s return value (an array) — that call would fatal with "Call to a member function required() on array". Corrected to show `required()` called on the `DotEnv` instance, matching the README's own (already-correct) examples.

## [v1.00.00] - 2026-02-25

| Type    | Count |
|---------|-------|
| Added   | 7     |
| Changed | 0     |
| Fixed   | 0     |
| Removed | 0     |

### Added
- `DotEnv::createImmutable()` factory for immutable `.env` loading (existing `$_ENV` keys are never overwritten)
- `load()` — parses `.env` file, throws `RuntimeException` if file is missing or unreadable
- `safeLoad()` — silently returns empty array if file is missing or unreadable
- `required(array $keys)->notEmpty()` — validation chain for mandatory environment variables
- Support for double-quoted values with `\n`, `\t`, `\\` escape sequences
- Support for single-quoted values (no escape processing)
- Inline comment stripping for unquoted values (comment must be preceded by a space)
