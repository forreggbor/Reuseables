# WYSIWYGEditor

A lightweight WYSIWYG (What You See Is What You Get) rich text editor for textarea elements, built without external dependencies using native browser APIs.

## Features

- Zero dependencies - pure vanilla JavaScript
- Transforms any textarea into a rich text editor
- Customizable toolbar with dropdowns and color pickers
- Font size and font family selection
- Text and background color formatting
- Table insertion with configurable dimensions
- Table editing: properties (border, padding, width), add/delete rows and columns
- Image insertion via server gallery, local file upload (base64), or URL
- Image editing: resize by dragging, edit alt text, delete
- Code view for HTML source editing
- Keyboard shortcuts (Ctrl/Cmd + B, I, U, K, Z, Y)
- Paste as plain text option
- HTML sanitization on paste
- Link insertion with configurable target
- Automatic sync with hidden textarea for form submission
- CSS auto-injection (no separate stylesheet needed)
- Localization support (English, Hungarian) with auto-detection
- Clean API with destroy method

## Requirements

- Modern browser (Chrome, Firefox, Safari, Edge)
- No external dependencies
- Compatible with Bootstrap modals (focus trap aware)

## Installation

Copy `WYSIWYGEditor.js` to your project and include it:

```html
<script src="path/to/WYSIWYGEditor.js"></script>
```

## Quick Start

```html
<form method="post">
    <textarea id="content" name="content"></textarea>
    <button type="submit">Save</button>
</form>

<script src="WYSIWYGEditor.js"></script>
<script>
    const editor = new WYSIWYGEditor(document.getElementById('content'));
</script>
```

## Configuration

```javascript
const editor = new WYSIWYGEditor(document.getElementById('content'), {
    toolbar: [
        'bold', 'italic', 'underline', 'strikethrough', 'subscript', 'superscript', '|',
        'fontSize', 'fontName', '|',
        'textColor', 'bgColor', '|',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6', '|',
        'ul', 'ol', 'blockquote', 'pre', '|',
        'link', 'unlink', '|',
        'alignLeft', 'alignCenter', 'alignRight', 'justifyFull', '|',
        'indent', 'outdent', '|',
        'hr', 'table', 'image', '|',
        'undo', 'redo', '|',
        'clearFormat', 'codeView'
    ],
    placeholder: 'Start typing...',
    pasteAsPlainText: false,
    minHeight: '200px',
    maxHeight: '500px',
    shortcuts: true,
    classPrefix: 'wysiwyg',
    linkTargetBlank: true,
    fontSizes: ['12px', '14px', '16px', '18px', '20px', '24px', '32px', '48px'],
    fontFamilies: [
        { label: 'Arial', value: 'Arial, sans-serif' },
        { label: 'Times New Roman', value: '"Times New Roman", serif' },
        { label: 'Georgia', value: 'Georgia, serif' }
    ],
    colorPalette: ['#000000', '#ff0000', '#00ff00', '#0000ff', '#ffff00'],
    tableDefaults: { rows: 3, cols: 3 },
    imageUpload: true,
    maxImageSize: 5242880,
    allowedImageTypes: ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
    serverImages: null,
    onChange: function(html) {
        console.log('Content changed:', html);
    },
    onFocus: function() {
        console.log('Editor focused');
    },
    onBlur: function() {
        console.log('Editor blurred');
    }
});
```

## Configuration Options

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `toolbar` | Array | See below | Toolbar buttons to display |
| `placeholder` | String | `''` | Placeholder text when editor is empty |
| `pasteAsPlainText` | Boolean | `false` | Strip formatting when pasting |
| `minHeight` | String | `'200px'` | Minimum editor height |
| `maxHeight` | String | `null` | Maximum editor height (null for unlimited) |
| `shortcuts` | Boolean | `true` | Enable keyboard shortcuts |
| `classPrefix` | String | `'wysiwyg'` | CSS class prefix for styling |
| `linkTargetBlank` | Boolean | `true` | Add target="_blank" to inserted links |
| `fontSizes` | Array | `['12px', '14px', ...]` | Available font sizes for dropdown |
| `fontFamilies` | Array | See below | Available fonts `[{label, value}]` |
| `colorPalette` | Array | 24 colors | Hex colors for color picker |
| `tableDefaults` | Object | `{rows: 3, cols: 3}` | Default table dimensions |
| `imageUpload` | Boolean | `true` | Enable file upload for images |
| `maxImageSize` | Number | `5242880` | Max upload size in bytes (5MB) |
| `allowedImageTypes` | Array | `['image/jpeg', ...]` | Allowed MIME types |
| `serverImages` | String\|Array\|null | `null` | Enable server gallery tab. String: URL endpoint returning JSON envelope. Array: pre-built image list. See below. |
| `serverImagesPageSize` | Number | `16` | Number of images per page in the server gallery. |
| `locale` | String | `'auto'` | UI language: `'auto'`, `'en'`, or `'hu'` |
| `onChange` | Function | `null` | Callback when content changes |
| `onFocus` | Function | `null` | Callback when editor gains focus |
| `onBlur` | Function | `null` | Callback when editor loses focus |
| `onImageInsert` | Function | `null` | Hook called before every image insertion. Controls the inserted HTML. See below. |

### Default Toolbar

```javascript
[
    'bold', 'italic', 'underline', 'strikethrough', 'subscript', 'superscript', '|',
    'fontSize', 'fontName', '|',
    'textColor', 'bgColor', '|',
    'h1', 'h2', 'h3', 'h4', 'h5', 'h6', '|',
    'ul', 'ol', 'blockquote', 'pre', '|',
    'link', 'unlink', '|',
    'alignLeft', 'alignCenter', 'alignRight', 'justifyFull', '|',
    'indent', 'outdent', '|',
    'hr', 'table', 'image', '|',
    'undo', 'redo', '|',
    'clearFormat', 'codeView'
]
```

### Available Toolbar Buttons

| Button        | Description                      |
|---------------|----------------------------------|
| `bold`        | Bold text                        |
| `italic`      | Italic text                      |
| `underline`   | Underlined text                  |
| `strikethrough` | Strikethrough text             |
| `subscript`   | Subscript text                   |
| `superscript` | Superscript text                 |
| `fontSize`    | Font size dropdown               |
| `fontName`    | Font family dropdown             |
| `textColor`   | Text color picker                |
| `bgColor`     | Background/highlight color picker |
| `h1`          | Heading 1                        |
| `h2`          | Heading 2                        |
| `h3`          | Heading 3                        |
| `h4`          | Heading 4                        |
| `h5`          | Heading 5                        |
| `h6`          | Heading 6                        |
| `blockquote`  | Block quote                      |
| `pre`         | Preformatted code block          |
| `ul`          | Unordered (bullet) list          |
| `ol`          | Ordered (numbered) list          |
| `hr`          | Horizontal rule                  |
| `link`        | Insert hyperlink                 |
| `unlink`      | Remove hyperlink                 |
| `alignLeft`   | Align text left                  |
| `alignCenter` | Align text center                |
| `alignRight`  | Align text right                 |
| `justifyFull` | Justify text                     |
| `indent`      | Increase indentation             |
| `outdent`     | Decrease indentation             |
| `table`       | Insert table                     |
| `image`       | Insert image                     |
| `undo`        | Undo last action                 |
| `redo`        | Redo last action                 |
| `clearFormat` | Remove all formatting            |
| `codeView`    | Toggle HTML source view          |
| `all`         | Include all buttons (shorthand)  |
| `\|`          | Separator (vertical line)        |

## Image Insert

The image modal has three tabs rendered in this order: **Server | Upload | URL**.

Default active tab is chosen automatically:
- **Server** — when `serverImages` is configured
- **Upload** — when `serverImages` is not set and `imageUpload` is `true` (the default)
- **URL** — when neither is enabled

The Server tab is always visible. When `serverImages` is not set it shows an empty state. When `imageUpload: false` the modal shows Server and URL tabs only.

## Server Image Gallery

The `serverImages` option enables a browsable server-side image gallery in the image modal. Content editors can pick existing assets from the server instead of re-uploading them.

The gallery modal is always wide (~80 vw, max 1100 px) when `serverImages` is configured. It includes:

- **Folder sidebar** — click any folder to navigate; `Root` shows top-level items.
- **Breadcrumb** — current path, each segment clickable.
- **Search input** — debounced 300 ms; filters by image name.
- **Pagination** — `« Previous | Page N of M | Next »`; controlled by `serverImagesPageSize` (default 16).

### URL endpoint (string)

```javascript
const editor = new WYSIWYGEditor(document.getElementById('content'), {
    serverImages: '/admin/images/list',
    serverImagesPageSize: 20,
});
```

The editor sends a `GET` request with query parameters and expects a JSON envelope:

```
GET /admin/images/list?page=1&pageSize=20&q=&folder=
```

| Param | Description |
|-------|-------------|
| `page` | 1-based page number |
| `pageSize` | mirrors `serverImagesPageSize` |
| `q` | search string (may be empty) |
| `folder` | folder path relative to gallery root (`""` for root) |

**Response envelope:**

```json
{
  "items": [
    { "url": "/uploads/hero.jpg", "name": "hero.jpg" },
    { "url": "/uploads/logo.svg", "name": "logo.svg", "thumb": "/uploads/.thumbs/logo.svg" }
  ],
  "total": 87,
  "page": 1,
  "pageSize": 20,
  "folder": "",
  "folderTree": ["2026", "2026/04", "2026/05"]
}
```

| Field | Required | Description |
|-------|----------|-------------|
| `items` | yes | Page of image objects |
| `items[].url` | yes | URL used as `<img src>` when inserted |
| `items[].name` | yes | Display label shown below the thumbnail |
| `items[].thumb` | no | Thumbnail URL; falls back to `url` |
| `total` | yes | Total matching items (used to compute page count) |
| `page` | yes | Echoed current page (client detects server-side clamping) |
| `pageSize` | yes | Echoed page size |
| `folder` | yes | Echoed current folder |
| `folderTree` | yes | Flat sorted list of all folder paths relative to root |

The endpoint must be same-origin (or send CORS headers). Session cookies are sent via `credentials: 'same-origin'`; no auth headers are added — return only images the current user may embed.

**PHP endpoint skeleton:**

```php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// Add your own auth/permission check here
// if (!$currentUser->can('manage_media')) { http_response_code(403); echo json_encode(['items'=>[],'total'=>0,'page'=>1,'pageSize'=>16,'folder'=>'','folderTree'=>[]]); exit; }

$root     = '/var/www/project/storage/uploads';
$page     = max(1, (int)($_GET['page']     ?? 1));
$pageSize = max(1, min(100, (int)($_GET['pageSize'] ?? 16)));
$q        = strtolower(trim($_GET['q']     ?? ''));
$folder   = trim($_GET['folder'] ?? '', '/');

// Validate folder (reject .. and escapes), then list + paginate + build folderTree
// See test-images.php in the WYSIWYGEditor source for a complete reference implementation.
```

### Pre-built array (no fetch)

Pass an array directly — useful when the list is already available in the page. The client handles sidebar, search, and pagination entirely in memory. No server changes needed.

```javascript
const editor = new WYSIWYGEditor(document.getElementById('content'), {
    serverImages: [
        { url: '/uploads/hero.jpg', name: 'hero.jpg' },
        { url: '/uploads/2026/logo.svg', name: 'logo.svg' },
    ],
    serverImagesPageSize: 20,
});
```

Folder paths are inferred from the `url` field: the path portion before the last `/` is the folder. Items whose URL contains no `/` belong to the root.

Per-item shape is unchanged (`{url, name, thumb?}`) — no migration needed for Array users.

### Deployment note

The bundled `test-images.php` and `images/` folder are **development-only**. Exclude them from your rsync when deploying:

```bash
rsync -av --delete \
  --exclude=test.html \
  --exclude=test-images.php \
  --exclude=images/ \
  reusables/WYSIWYGEditor/ project/public/js/WYSIWYGEditor.js
```

### Migration note — breaking JSON contract change

If you implemented the basic Server tab (before pagination was added), your endpoint returned a bare JSON **array** (`[{url, name}, ...]`). The client now expects a **JSON object envelope** (`{items, total, page, pageSize, folder, folderTree}`). You must update your endpoint to return the new shape; the bare-array format is no longer supported.

### Migration note (`imageUpload: false`)

Hosts using `imageUpload: false` previously saw a single URL input with no tabs. They will now see **Server | URL** tabs, with URL pre-selected. Insert behavior is unchanged.

## Image Insert Hook

The `onImageInsert` callback lets host applications control what HTML is inserted into the editor whenever a user confirms an image from any tab (Server, Upload, or URL). Use it to inject database IDs, wrap images in custom markup, or add any application-specific attributes — without monkey-patching the editor instance.

### Callback signature

```javascript
onImageInsert: function({ url, alt, source, serverItem }) {
    // url        — resolved image URL or base64 data URI
    // alt        — alt text entered by the user
    // source     — 'url' | 'upload' | 'server'
    // serverItem — full server item object when source === 'server', null otherwise
}
```

### Return values

| Return value | Behaviour |
|---|---|
| `string` | Inserted verbatim as HTML. Use for full control (e.g. wrapping in `<figure>`). |
| `object` | Key/value pairs merged as extra attributes on a plain `<img src alt …>`. |
| `null` / `undefined` | Default `<img src alt>` is inserted unchanged. |

### Extra attributes (object return)

```javascript
new WYSIWYGEditor('#content', {
    serverImages: '/admin/media/editor-api',
    onImageInsert: function({ source, serverItem }) {
        if (source === 'server' && serverItem?.id) {
            return { 'data-media-id': serverItem.id };
        }
    }
});
// Inserts: <img src="…" alt="…" data-media-id="42">
```

### Custom HTML (string return)

```javascript
new WYSIWYGEditor('#content', {
    serverImages: '/admin/media/editor-api',
    onImageInsert: function({ url, alt, source, serverItem }) {
        if (source === 'server' && serverItem) {
            return '<figure class="post-figure">'
                + '<img src="' + url + '" alt="' + (serverItem.alt_text || alt) + '"'
                + ' data-media-id="' + serverItem.id + '">'
                + '<figcaption>' + (serverItem.caption || '') + '</figcaption>'
                + '</figure>';
        }
        // Fall back to default for URL and upload tabs
        return null;
    }
});
```

### Passing extra fields via the server endpoint

The editor uses only `url`, `name`, and `thumb` from each item for display. Any additional fields your endpoint returns are ignored by the editor but passed through unchanged in `serverItem` inside the callback. Extend the response freely:

```json
{
  "items": [
    {
      "url": "/uploads/photo.jpg",
      "name": "photo.jpg",
      "thumb": "/uploads/.thumbs/photo.jpg",
      "id": 42,
      "alt_text": "A descriptive alt",
      "caption": "Photo caption",
      "title": "Photo title",
      "description": "Longer description"
    }
  ],
  "total": 1, "page": 1, "pageSize": 16, "folder": "", "folderTree": []
}
```

All fields (`id`, `alt_text`, `caption`, `title`, `description`, or any custom field) will be available on `serverItem` in the callback.

## API Reference

### Constructor

```javascript
new WYSIWYGEditor(textarea, options)
```

| Parameter | Type | Description |
|-----------|------|-------------|
| `textarea` | HTMLTextAreaElement \| String | Textarea element or CSS selector |
| `options` | Object | Configuration options |

### Instance Methods

| Method | Returns | Description |
|--------|---------|-------------|
| `getContent()` | String | Get current HTML content |
| `setContent(html)` | void | Set editor HTML content |
| `getText()` | String | Get current plain text content |
| `focus()` | void | Focus the editor |
| `blur()` | void | Blur the editor |
| `isEmpty()` | Boolean | Check if editor has no content |
| `sync()` | void | Manually sync content to textarea |
| `toggleCodeView()` | void | Toggle between WYSIWYG and HTML view |
| `insertTable(rows, cols)` | void | Insert table with dimensions |
| `selectTable(table, cell)` | void | Select a table for editing |
| `deselectTable()` | void | Deselect the currently selected table |
| `insertTableRow(table, cell, pos)` | void | Insert row above/below cell |
| `insertTableColumn(table, cell, pos)` | void | Insert column left/right of cell |
| `deleteTableRow(table, cell)` | void | Delete row containing cell |
| `deleteTableColumn(table, cell)` | void | Delete column containing cell |
| `deleteTable(table)` | void | Delete the entire table |
| `insertImageFromUrl(url, alt, options?)` | void | Insert image from URL. Optional `options.source` and `options.serverItem` are forwarded to `onImageInsert`. |
| `selectImage(img)` | void | Select an image for editing |
| `deselectImage()` | void | Deselect the currently selected image |
| `editImageAlt(img)` | void | Open alt text editor for image |
| `deleteImage(img)` | void | Delete the specified image |
| `destroy()` | void | Remove editor and restore textarea |

### Static Methods

| Method | Returns | Description |
|--------|---------|-------------|
| `WYSIWYGEditor.init(selector, options)` | WYSIWYGEditor \| Array | Create editor(s) from CSS selector |

## Keyboard Shortcuts

| Shortcut | Action |
|----------|--------|
| Ctrl/Cmd + B | Bold |
| Ctrl/Cmd + I | Italic |
| Ctrl/Cmd + U | Underline |
| Ctrl/Cmd + K | Insert link |
| Ctrl/Cmd + Z | Undo |
| Ctrl/Cmd + Y | Redo |
| Ctrl/Cmd + Shift + Z | Redo (alternative) |

## Examples

### All Buttons

```javascript
new WYSIWYGEditor('#content', {
    toolbar: ['all']
});
```

### Hungarian Locale

```javascript
new WYSIWYGEditor('#content', {
    locale: 'hu'
});
```

### Minimal Toolbar

```javascript
new WYSIWYGEditor('#content', {
    toolbar: ['bold', 'italic', '|', 'link']
});
```

### Multiple Editors

```javascript
// Using static init method
const editors = WYSIWYGEditor.init('.wysiwyg-textarea', {
    minHeight: '150px'
});
```

### With onChange Callback

```javascript
new WYSIWYGEditor('#content', {
    onChange: function(html) {
        document.getElementById('preview').innerHTML = html;
        document.getElementById('char-count').textContent = html.length;
    }
});
```

### Plain Text Paste

```javascript
new WYSIWYGEditor('#content', {
    pasteAsPlainText: true
});
```

### Custom Height

```javascript
new WYSIWYGEditor('#content', {
    minHeight: '300px',
    maxHeight: '600px'
});
```

### Destroy and Restore

```javascript
const editor = new WYSIWYGEditor('#content');

// Later, when you need to restore the original textarea
editor.destroy();
```

## Form Submission

The editor automatically syncs its HTML content to the hidden textarea. On form submission, the textarea's value contains the HTML content.

```html
<form method="post" action="/save">
    <textarea id="content" name="content"></textarea>
    <button type="submit">Save</button>
</form>

<script>
    new WYSIWYGEditor('#content');
    // Form submission will include the HTML content in the "content" field
</script>
```

## Styling Customization

The editor automatically injects CSS styles. To customize, either:

1. Override styles using the class prefix:

```css
.wysiwyg-wrapper {
    border-color: #007bff;
}
.wysiwyg-toolbar {
    background: #f8f9fa;
}
.wysiwyg-editor {
    font-family: Georgia, serif;
}
```

2. Use a custom class prefix:

```javascript
new WYSIWYGEditor('#content', {
    classPrefix: 'my-editor'
});
```

Then style with `.my-editor-wrapper`, `.my-editor-toolbar`, etc.

## Localization

The editor supports multiple UI languages. By default (`locale: 'auto'`), it detects the browser language and uses Hungarian if the browser is set to Hungarian, otherwise English.

| Locale | Language  |
|--------|-----------|
| `auto` | Auto-detect from browser (default) |
| `en`   | English   |
| `hu`   | Hungarian |

All tooltips, modal labels, button texts, prompts, and error messages are translated.

## Security

The editor sanitizes pasted HTML content by:

- Removing `<script>`, `<style>`, `<iframe>`, `<object>`, `<embed>` elements
- Removing all `on*` event handler attributes
- Escaping URLs in link insertion

For server-side processing, always sanitize HTML content before storing or displaying.

## Browser Support

| Browser | Support |
|---------|---------|
| Chrome | Full |
| Firefox | Full |
| Safari | Full |
| Edge | Full |

## License

MIT
