# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-01-23

### Added

- Initial release of GettextFallback component
- Automatic locale availability detection using `setlocale()` with UTF-8 variants
- Custom binary MO file parser using PHP's `unpack()`
- Transparent fallback to custom parser when native gettext unavailable
- Full plural form support with C-style expression evaluation
- Support for multiple text domains via `bindDomain()` and `setDomain()`
- Context-aware translations via `pTranslate()` and `npTranslate()`
- In-memory translation caching with `clearCache()` method
- Global function wrappers in `functions.php`:
  - `_()`, `gettext()`, `ngettext()`
  - `dgettext()`, `dngettext()`
  - `pgettext()`, `npgettext()`
  - `textdomain()`, `bindtextdomain()`, `bind_textdomain_codeset()`
- Configuration options for locale path, default domain, and caching
- Status methods: `isUsingNativeGettext()`, `isLocaleAvailable()`
