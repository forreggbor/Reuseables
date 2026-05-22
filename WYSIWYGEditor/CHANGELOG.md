# Changelog

All notable changes to WYSIWYGEditor will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.6.0] - 2026-05-22

### Summary

| Category | Description                                                                        |
|----------|------------------------------------------------------------------------------------|
| Added    | `onImageInsert` callback hook for host applications to customise inserted HTML     |

### Added
- **`onImageInsert` callback**: optional config function called before every image insertion. Receives `{ url, alt, source, serverItem }` and controls the inserted HTML:
  - Return a **string** to insert that HTML verbatim (e.g. wrap in `<figure>`)
  - Return an **object** to merge extra attributes onto a plain `<img>` (e.g. `{ 'data-media-id': 42 }`)
  - Return `null` or `undefined` to use the default `<img src alt>` behaviour
- **`source`** field in the callback payload identifies the insertion origin: `'url'` (URL tab), `'upload'` (file upload tab), `'server'` (server gallery tab)
- **`serverItem`** field passes the full server item object through to the callback when `source === 'server'`, including any extra fields the host endpoint returns beyond `url`, `name`, and `thumb`
- **`selectedItem`** tracked in server gallery state so the full item is available at insert time
- `insertImageFromUrl(url, alt, options)` — third parameter `options` added (`{ source, serverItem }`); fully backward-compatible (existing callers with two arguments continue to work unchanged)

## [2.5.0] - 2026-05-22

### Summary

| Category      | Description                                                                              |
|---------------|------------------------------------------------------------------------------------------|
| Added         | Pagination, search, folder navigation, and wide modal for the Server image gallery tab   |

### Added
- **Server gallery pagination**: server returns one page at a time; client renders pager controls ("Previous / Page N of M / Next"); page size configurable via new `serverImagesPageSize` option (default 16)
- **Server gallery search**: debounced search input (300 ms) in the Server tab toolbar; URL form sends `?q=` to the endpoint; Array form filters in memory
- **Server gallery folder navigation**: left sidebar lists the full folder tree; URL form receives `folderTree[]` from the server; Array form infers the tree from item URL paths; breadcrumb shows clickable path segments
- **Wide modal**: when `serverImages` is configured, the image modal opens at 80 vw (capped at 1 100 px) to accommodate the sidebar layout
- **New JSON envelope** for URL endpoints: `{ items, total, page, pageSize, folder, folderTree }` — replaces the old bare array response; hosts must update existing endpoints (migration note in README)
- **Error state**: network or parse failures show an error message and a Retry button in the grid area; sidebar and pager remain mounted
- **`serverImagesPageSize`** configuration option (default `16`)
- **`test-images.php`** extended to honour `?page`, `?pageSize`, `?q`, `?folder`, return the new envelope, and scan subfolders recursively

## [2.4.0] - 2026-02-07

### Summary

| Area          | Change                                                            |
|---------------|-------------------------------------------------------------------|
| Localization  | English and Hungarian UI translations with browser auto-detection |
| Configuration | New `locale` option (`'auto'`, `'en'`, `'hu'`)                    |
| API           | New `t(key)` translation method with English fallback             |

### Added
- **Localization**: English and Hungarian UI translations with automatic browser language detection
- `locale` configuration option (`'auto'`, `'en'`, `'hu'`) — auto-detects browser language by default
- `t(key)` translation method with English fallback for all user-facing strings
- All tooltips, modal labels, button texts, prompts, and error messages are translated

## [2.3.0] - 2026-02-07

### Summary

| Area              | Change                                                         |
|-------------------|----------------------------------------------------------------|
| Toolbar buttons   | 9 new formatting buttons (subscript, superscript, h4-h6, etc.) |
| Toolbar shorthand | `all` option to include all buttons at once                    |
| Compatibility     | Cross-browser fixes for Firefox and Safari                     |

### Added
- Subscript (`subscript`) toolbar button
- Superscript (`superscript`) toolbar button
- Headings 4-6 (`h4`, `h5`, `h6`) toolbar buttons
- Block quote (`blockquote`) toolbar button
- Preformatted code block (`pre`) toolbar button
- Horizontal rule (`hr`) toolbar button for inserting `<hr>` separator
- Justify (`justifyFull`) toolbar button for full text justification
- Increase indent (`indent`) toolbar button
- Decrease indent (`outdent`) toolbar button
- `all` toolbar shorthand to include all available buttons at once (`toolbar: ['all']`)

### Fixed
- Blockquote and pre now toggle off (revert to `<p>`) instead of nesting in Firefox
- Indent/outdent uses consistent `margin-left` CSS across all browsers instead of browser-specific markup
- Subscript/superscript are mutually exclusive (applying one removes the other)
- Subscript/superscript active state detection uses DOM traversal instead of unreliable `queryCommandState` in Firefox
- Justify full active state detection uses computed CSS instead of unreliable `queryCommandState` in Safari
- Remove formatting now preserves links in Safari (Safari's native `removeFormat` strips anchor elements)

## [2.2.2] - 2026-01-23

### Fixed
- Move image/table toolbars outside contenteditable area to prevent them being saved with content
- Toolbars now append to wrapper instead of editor element
- Add z-index to modal and modal inputs to ensure they are clickable
- Add `sanitizeEditorUI()` method to strip any accidentally saved toolbar HTML from content on load
- Modal now appends inside Bootstrap modal when editor is inside one (fixes focus trap issue)

## [2.2.1] - 2026-01-23

### Fixed
- Code view now uses clean content, preventing toolbars from being included in HTML source
- Deselect images/tables before switching to code view
- Modal inputs now stop propagation for all event types (click, keyboard, focus)
- Prevent modal from losing focus when clicking inside

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
