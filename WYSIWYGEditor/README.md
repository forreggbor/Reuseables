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
- Image insertion via URL or file upload (base64)
- Image editing: resize by dragging, edit alt text, delete
- Code view for HTML source editing
- Keyboard shortcuts (Ctrl/Cmd + B, I, U, K, Z, Y)
- Paste as plain text option
- HTML sanitization on paste
- Link insertion with configurable target
- Automatic sync with hidden textarea for form submission
- CSS auto-injection (no separate stylesheet needed)
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
| `onChange` | Function | `null` | Callback when content changes |
| `onFocus` | Function | `null` | Callback when editor gains focus |
| `onBlur` | Function | `null` | Callback when editor loses focus |

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
| `\|`          | Separator (vertical line)        |

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
| `insertImageFromUrl(url, alt)` | void | Insert image from URL |
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
