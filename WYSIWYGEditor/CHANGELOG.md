# Changelog

All notable changes to WYSIWYGEditor will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.1] - 2026-01-21

### Fixed
- Use `<p>` tags for paragraphs instead of browser default `<div>` tags
- Normalize content on sync to convert any `<div>` wrappers to proper `<p>` tags
- Unwrap block elements (lists, headings) from unnecessary `<div>` wrappers

### Added
- Form submit event listener to ensure content sync before submission

## [1.0.0] - 2026-01-21

### Added
- Initial release
- Core formatting: bold, italic, underline, strikethrough
- Headings: H1, H2, H3
- Lists: ordered and unordered
- Link insertion and removal
- Text alignment: left, center, right
- Undo/redo support
- Clear formatting
- Keyboard shortcuts (Ctrl/Cmd + B, I, U, K, Z, Y)
- Paste as plain text option
- HTML sanitization on paste
- Configurable toolbar
- Auto-sync with hidden textarea
- Embedded CSS (auto-injection)
- Static factory method for multiple editors
- Destroy method to restore original textarea
