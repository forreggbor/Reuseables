# Changelog

All notable changes to WYSIWYGEditor will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.2.0] - 2026-01-23

### Added
- **Table Editing**: Click on tables to edit them
  - Table properties modal (border width, border color, cell padding, table width)
  - Insert row above/below current row
  - Insert column left/right of current column
  - Delete row, delete column, delete table
  - Toolbar appears above selected table with all editing options
  - New cells inherit styles from existing cells

### Fixed
- Alt text input now works correctly in all modal dialogs (insert image, edit image alt)
- Centralized input event handling in `showModal()` for consistent behavior
- Editor UI elements (toolbars, resizers, selection classes) are now excluded from saved content

## [2.1.1] - 2026-01-23

### Fixed
- Image resize border and toolbar now correctly positioned around selected image
- Added `position: relative` to editor container for proper absolute positioning

## [2.1.0] - 2026-01-23

### Added
- **Image Editing**: Click on inserted images to edit them
  - Resize handles for drag-to-resize with aspect ratio preservation
  - Toolbar with quick actions (edit alt text, 50% size, 100% size, delete)
  - Edit alt text via modal dialog
  - Delete image button

### Fixed
- Alt text input now works correctly in image upload modal
- Improved modal event handling to prevent input focus issues

## [2.0.0] - 2026-01-23

### Added
- **Code View**: Toggle between WYSIWYG and HTML source editing mode
- **Font Size**: Dropdown selector with configurable font sizes (12px-48px)
- **Font Family**: Dropdown selector with web-safe fonts (Arial, Times New Roman, Georgia, Courier New, Verdana, Trebuchet MS)
- **Text Color**: Color picker palette for text foreground color
- **Background Color**: Color picker palette for text highlight/background color with remove option
- **Table Insertion**: Modal dialog to insert tables with configurable rows and columns
- **Image Insertion**: Modal dialog to insert images via URL or file upload (base64)
- New configuration options: `fontSizes`, `fontFamilies`, `colorPalette`, `tableDefaults`, `imageUpload`, `maxImageSize`, `allowedImageTypes`
- Reusable UI components: dropdown menus, color pickers, modal dialogs
- Selection save/restore for maintaining cursor position during popup interactions

### Changed
- Default toolbar now includes all new formatting options
- Updated CSS with styles for dropdowns, color pickers, modals, and code editor

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
