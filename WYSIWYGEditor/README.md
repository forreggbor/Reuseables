# WYSIWYGEditor

A lightweight WYSIWYG (What You See Is What You Get) rich text editor for textarea elements, built without external dependencies using native browser APIs.

## Features

- Zero dependencies - pure vanilla JavaScript
- Transforms any textarea into a rich text editor
- Customizable toolbar
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
        'bold', 'italic', 'underline', 'strikethrough', '|',
        'h1', 'h2', 'h3', '|',
        'ul', 'ol', '|',
        'link', 'unlink', '|',
        'alignLeft', 'alignCenter', 'alignRight', '|',
        'undo', 'redo', '|',
        'clearFormat'
    ],
    placeholder: 'Start typing...',
    pasteAsPlainText: false,
    minHeight: '200px',
    maxHeight: '500px',
    shortcuts: true,
    classPrefix: 'wysiwyg',
    linkTargetBlank: true,
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
| `onChange` | Function | `null` | Callback when content changes |
| `onFocus` | Function | `null` | Callback when editor gains focus |
| `onBlur` | Function | `null` | Callback when editor loses focus |

### Default Toolbar

```javascript
[
    'bold', 'italic', 'underline', 'strikethrough', '|',
    'h1', 'h2', 'h3', '|',
    'ul', 'ol', '|',
    'link', 'unlink', '|',
    'alignLeft', 'alignCenter', 'alignRight', '|',
    'undo', 'redo', '|',
    'clearFormat'
]
```

### Available Toolbar Buttons

| Button | Description |
|--------|-------------|
| `bold` | Bold text |
| `italic` | Italic text |
| `underline` | Underlined text |
| `strikethrough` | Strikethrough text |
| `h1` | Heading 1 |
| `h2` | Heading 2 |
| `h3` | Heading 3 |
| `ul` | Unordered (bullet) list |
| `ol` | Ordered (numbered) list |
| `link` | Insert hyperlink |
| `unlink` | Remove hyperlink |
| `alignLeft` | Align text left |
| `alignCenter` | Align text center |
| `alignRight` | Align text right |
| `undo` | Undo last action |
| `redo` | Redo last action |
| `clearFormat` | Remove all formatting |
| `\|` | Separator (vertical line) |

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
