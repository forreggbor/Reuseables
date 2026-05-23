# Images and Galleries — Integration Guide

This guide covers every image- and gallery-related feature added in v2.5.0–v2.7.0:

- [Server image gallery](#server-image-gallery) (`serverImages`, `serverImagesPageSize`)
- [Image insert hook](#image-insert-hook) (`onImageInsert`)
- [Public insert API](#public-insert-api) (`insertImageFromUrl`)
- [Gallery picker](#gallery-picker) (`serverGalleries`, `serverGalleriesPageSize`, `onGalleryInsert`)
- [Content transform hooks](#content-transform-hooks) (`onContentIn`, `onContentOut`)
- [Full CMS example](#full-cms-example)

---

## Image modal — tab behaviour

The image modal always shows three tabs in this order: **Server | Upload | URL**.

Which tab is active on open:

| Condition                                             | Default active tab |
|-------------------------------------------------------|--------------------|
| `serverImages` is configured                          | Server             |
| `serverImages` not set, `imageUpload: true` (default) | Upload             |
| Neither is enabled                                    | URL                |

When `imageUpload: false`, the Upload tab is hidden — only Server and URL remain.

When `serverImages` is configured the modal opens wide (~80 vw, capped at 1100 px) to accommodate the sidebar layout.

---

## Server image gallery

Enable a browsable server-side image gallery inside the image modal so editors can pick existing assets instead of re-uploading them.

```javascript
new WYSIWYGEditor('#content', {
    serverImages: '/admin/media/editor-api',
    serverImagesPageSize: 20,
});
```

The gallery includes:

- **Folder sidebar** — full folder tree; click any item to navigate; "Root" shows top-level assets.
- **Breadcrumb** — current path with each segment clickable.
- **Search input** — debounced 300 ms; sends `?q=` to the endpoint (or filters in memory for array sources).
- **Pagination** — `« Previous | Page N of M | Next »` controlled by `serverImagesPageSize`.
- **Error + retry** — network or parse failures show an error message and a Retry button; modal stays open.

### URL endpoint

```javascript
serverImages: '/admin/media/editor-api',
serverImagesPageSize: 16,   // default; controls the ?pageSize= query param
```

The editor sends a `GET` request:

```
GET /admin/media/editor-api?page=1&pageSize=16&q=beach&folder=2026/05
```

| Query param | Description                                          |
|-------------|------------------------------------------------------|
| `page`      | 1-based page number                                  |
| `pageSize`  | mirrors `serverImagesPageSize`                       |
| `q`         | search string (may be empty)                         |
| `folder`    | folder path relative to gallery root (`""` for root) |

**Required response envelope:**

```json
{
  "items": [
    {
      "url":   "/uploads/hero.jpg",
      "name":  "hero.jpg",
      "thumb": "/uploads/.thumbs/hero.jpg"
    }
  ],
  "total":      87,
  "page":       1,
  "pageSize":   16,
  "folder":     "2026/05",
  "folderTree": ["2026", "2026/04", "2026/05"]
}
```

| Field           | Required | Notes                                                 |
|-----------------|----------|-------------------------------------------------------|
| `items`         | yes      | Current page of image objects                         |
| `items[].url`   | yes      | The URL inserted as `<img src>`                       |
| `items[].name`  | yes      | Label shown beneath the thumbnail                     |
| `items[].thumb` | no       | Thumbnail URL; falls back to `url`                    |
| `total`         | yes      | Total matching items (used to compute page count)     |
| `page`          | yes      | Echoed back (client detects server-side clamping)     |
| `pageSize`      | yes      | Echoed back                                           |
| `folder`        | yes      | Echoed current folder                                 |
| `folderTree`    | yes      | Flat sorted list of all folder paths relative to root |

**Extra fields** (`id`, `alt_text`, `caption`, `title`, or any custom field) are ignored by the editor for display but pass through unchanged into [`onImageInsert`](#image-insert-hook) via `serverItem`. Extend the response freely.

The endpoint must be same-origin or send CORS headers. Requests use `credentials: 'same-origin'`; no custom auth headers are added — return only images the current user may embed.

**Minimal PHP skeleton:**

```php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// Auth check: abort if unauthorized
if (!$currentUser->can('manage_media')) {
    http_response_code(403);
    echo json_encode(['items' => [], 'total' => 0, 'page' => 1,
                      'pageSize' => 16, 'folder' => '', 'folderTree' => []]);
    exit;
}

$root     = '/var/www/project/storage/uploads';
$page     = max(1, (int)($_GET['page']     ?? 1));
$pageSize = max(1, min(100, (int)($_GET['pageSize'] ?? 16)));
$q        = strtolower(trim($_GET['q']     ?? ''));
$folder   = trim($_GET['folder'] ?? '', '/');

// Validate $folder: reject '..' and path traversal before building the path.
// Scan, filter by $q, paginate, collect folderTree, then encode as JSON.
// See the bundled test-images.php for a complete reference implementation.
```

### Breaking change — JSON contract (v2.5.0)

If you implemented the Server tab before v2.5.0, your endpoint returned a bare JSON array (`[{url, name}, ...]`). The client now expects the object envelope above. Update your endpoint; the bare-array format is no longer supported.

### Pre-built array (no fetch)

Pass the full list directly. Pagination, search, and folder navigation all work in memory — no server changes needed.

```javascript
new WYSIWYGEditor('#content', {
    serverImages: [
        { url: '/uploads/hero.jpg',      name: 'hero.jpg' },
        { url: '/uploads/2026/logo.svg', name: 'logo.svg', thumb: '/uploads/.thumbs/logo.svg' },
    ],
    serverImagesPageSize: 20,
});
```

Folder paths are inferred from `url`: everything before the last `/` is the folder. Items with no `/` in their URL belong to the root.

---

## Image insert hook

`onImageInsert` fires before every image insertion — from any tab (Server, Upload, URL). Use it to inject database IDs, wrap images in custom markup, or add application-specific attributes without monkey-patching the editor.

### Signature

```javascript
onImageInsert: function({ url, alt, source, serverItem }) { … }
```

| Parameter    | Type           | Description                                                          |
|--------------|----------------|----------------------------------------------------------------------|
| `url`        | `string`       | Resolved image URL or base64 data URI                                |
| `alt`        | `string`       | Alt text entered by the user                                         |
| `source`     | `string`       | `'url'` \| `'upload'` \| `'server'`                                  |
| `serverItem` | `Object\|null` | Full server item object when `source === 'server'`; `null` otherwise |

### Return values

| Return type           | Behaviour                                                                                     |
|-----------------------|-----------------------------------------------------------------------------------------------|
| `string`              | Inserted verbatim as HTML. Full control: use for `<figure>` wrappers, custom attributes, etc. |
| `object`              | Key/value pairs merged as extra attributes on a plain `<img src alt …>`.                      |
| `null` \| `undefined` | Default `<img src alt>` inserted unchanged.                                                   |

### Object return — extra attributes

Use this to attach a database ID to a plain `<img>` without building the full tag:

```javascript
new WYSIWYGEditor('#content', {
    serverImages: '/admin/media/editor-api',
    onImageInsert: function({ source, serverItem }) {
        if (source === 'server' && serverItem?.id) {
            return { 'data-media-id': serverItem.id };
        }
        // null → default <img> for upload and URL tabs
    }
});
// Result: <img src="…" alt="…" data-media-id="42">
```

All attribute values are HTML-escaped by the editor.

### String return — full custom HTML

Use this to wrap the image in application markup and pull through any extra server fields:

```javascript
new WYSIWYGEditor('#content', {
    serverImages: '/admin/media/editor-api',
    onImageInsert: function({ url, alt, source, serverItem }) {
        if (source === 'server' && serverItem) {
            const id      = serverItem.id       ?? 0;
            const altText = serverItem.alt_text ?? alt;
            const caption = serverItem.caption  ?? '';

            return '<figure class="post-body__figure">'
                + '<img src="' + url + '" alt="' + altText + '" data-media-id="' + id + '">'
                + (caption ? '<figcaption class="post-body__figcaption">' + caption + '</figcaption>' : '')
                + '</figure>';
        }
        return null;  // default <img> for URL and upload tabs
    }
});
```

### Passing extra fields from the server

The editor uses only `url`, `name`, and `thumb` for display. All other fields in each item object are ignored by the gallery but forwarded unchanged to `serverItem` inside the callback. Extend your endpoint freely:

```json
{
  "items": [
    {
      "url":         "/uploads/photo.jpg",
      "name":        "photo.jpg",
      "thumb":       "/uploads/.thumbs/photo.jpg",
      "id":          42,
      "alt_text":    "A descriptive alt text",
      "caption":     "Photo taken at the annual event",
      "title":       "Annual Event Photo",
      "description": "High-res photo from the 2026 annual conference"
    }
  ],
  "total": 1, "page": 1, "pageSize": 16, "folder": "", "folderTree": []
}
```

Every field (`id`, `alt_text`, `caption`, `title`, `description`, or any custom field) is available on `serverItem` inside the callback.

---

## Public insert API

`insertImageFromUrl(url, alt, options?)` inserts an image programmatically, passing through the `onImageInsert` hook just like the modal does.

```javascript
const editor = new WYSIWYGEditor('#content', {
    serverImages: '/admin/media/editor-api',
    onImageInsert: function({ source, serverItem }) {
        if (source === 'server' && serverItem?.id) {
            return { 'data-media-id': serverItem.id };
        }
    }
});

// Programmatic insert — hook receives source: 'server', serverItem: { id: 42, ... }
editor.insertImageFromUrl('/uploads/photo.jpg', 'Alt text', {
    source: 'server',
    serverItem: { id: 42, alt_text: 'Alt text', caption: 'Caption' }
});
```

| Parameter            | Type           | Default | Description                                  |
|----------------------|----------------|---------|----------------------------------------------|
| `url`                | `string`       | —       | Image URL or base64 data URI                 |
| `alt`                | `string`       | `''`    | Alt text                                     |
| `options.source`     | `string`       | `'url'` | Forwarded as `source` to `onImageInsert`     |
| `options.serverItem` | `Object\|null` | `null`  | Forwarded as `serverItem` to `onImageInsert` |

Calling with two arguments (`url`, `alt`) is unchanged from earlier versions — fully backward-compatible.

---

## Gallery picker

The gallery picker adds a dedicated toolbar button that opens a modal where editors can browse and insert galleries (photo albums, collections, etc.). The reusable handles all fetch, pagination, and modal UI — the host application only needs to configure the source and control what gets inserted.

Enable by adding `'gallery'` to `toolbar` and setting `serverGalleries`:

```javascript
new WYSIWYGEditor('#content', {
    toolbar: ['bold', 'italic', '|', 'image', 'gallery', '|', 'codeView'],
    serverGalleries: '/admin/galleries/api',
    serverGalleriesPageSize: 12,    // default
    onGalleryInsert: function({ gallery }) {
        return '<figure class="gallery-embed" data-gallery-id="' + gallery.id + '">'
            + '<span>' + gallery.name + ' (' + gallery.image_count + ' images)</span>'
            + '</figure>';
    }
});
```

The picker modal is a single-pane card grid: cover thumbnail + gallery name + image count. Cancel/Insert buttons. ESC and outside-click dismiss. The Insert button is disabled until a card is selected.

### URL endpoint

```javascript
serverGalleries: '/admin/galleries/api',
serverGalleriesPageSize: 12,   // controls ?pageSize= query param
```

The editor sends:

```
GET /admin/galleries/api?page=1&pageSize=12
```

**Response envelope:**

```json
{
  "items": [
    {
      "id":          12,
      "name":        "Summer 2024",
      "image_count": 18,
      "cover":       "/uploads/galleries/12/cover.jpg"
    }
  ],
  "total":    42,
  "page":     1,
  "pageSize": 12
}
```

| Field                       | Required | Notes                                                                        |
|-----------------------------|----------|------------------------------------------------------------------------------|
| `items`                     | yes      | Current page of gallery objects                                              |
| `items[].id`                | yes      | Gallery identifier                                                           |
| `items[].name`              | yes      | Display name                                                                 |
| `items[].image_count`       | yes      | Shown in the card                                                            |
| `items[].cover`             | no       | Cover thumbnail URL; card shows a placeholder icon when absent               |
| `total`, `page`, `pageSize` | no       | Pagination fields are optional — the picker handles a flat list without them |

Extra item fields pass through unchanged to `onGalleryInsert({ gallery })`.

### Pre-built array (no fetch)

```javascript
serverGalleries: [
    { id: 1, name: 'Portraits',   image_count: 12, cover: '/img/cover1.jpg' },
    { id: 2, name: 'Events 2026', image_count: 34 },
]
```

### `onGalleryInsert` callback

```javascript
onGalleryInsert: function({ gallery, source }) { … }
```

| Parameter | Type     | Description                                                                                       |
|-----------|----------|---------------------------------------------------------------------------------------------------|
| `gallery` | `Object` | Full gallery item from the endpoint (`id`, `name`, `image_count`, `cover`, plus any extra fields) |
| `source`  | `string` | Always `'picker'` (reserved for future sources)                                                   |

| Return type           | Behaviour                                                                                                                    |
|-----------------------|------------------------------------------------------------------------------------------------------------------------------|
| `string`              | Inserted verbatim as HTML                                                                                                    |
| `null` \| `undefined` | Generic block-level placeholder: `<div class="wysiwyg-gallery-embed" data-gallery-id="N" contenteditable="false">name</div>` |

The `object` return form is **not** supported for `onGalleryInsert` (unlike `onImageInsert`). Return a string or null.

---

## Content transform hooks

`onContentIn` and `onContentOut` are a **matched pair** of synchronous string transforms. They let the host application translate between its storage format and the editor's DOM representation on every load and save — without monkey-patching internal editor methods.

| Hook                          | When it runs                                                                     | Direction                 |
|-------------------------------|----------------------------------------------------------------------------------|---------------------------|
| `onContentIn(html) => html'`  | End of `sanitizeEditorUI` — on `setContent`, on code-view toggle back to WYSIWYG | host storage → editor DOM |
| `onContentOut(html) => html'` | End of `getCleanContent` — on every save, on code-view toggle forward            | editor DOM → host storage |

**Register both or neither.** Wiring only one silently breaks the round-trip: shortcodes either leak as literal text into the DB or placeholder HTML persists where shortcodes should be.

**Requirements for hook implementations:**

- **Pure** — no side effects; same input always produces same output.
- **Synchronous** — the editor cannot await a Promise.
- **Idempotent** — running the same hook twice on already-transformed content must be safe.
- **Total** — the function must return a string for every input, including empty string.

Both hooks fire on every call, not only when gallery markup is present. Keep them fast and simple.

### Pipeline overview

```
textarea.value ──► sanitizeEditorUI ──► onContentIn ──► editor DOM
                                                               │
                                                         (user edits)
                                                               │
                         DB / save ◄── onContentOut ◄── getCleanContent
```

Code-view round-trip:
```
WYSIWYG ──► codeView toggle ──► getCleanContent ──► onContentOut ──► <textarea>
<textarea> ──► toggle back ──► sanitizeEditorUI ──► onContentIn ──► WYSIWYG
```

### Shortcode round-trip example

This is the primary use case: shortcodes in storage (`[gallery id=N]`), visual placeholders in the editor (`<figure data-gallery-id="N">`).

```javascript
var galleryCache = {};  // id (string) → { id, name, image_count, cover }

new WYSIWYGEditor('#content', {
    serverGalleries: '/admin/galleries/api',

    onGalleryInsert: function({ gallery }) {
        return buildGalleryEmbed(gallery.id, gallery.name, gallery.image_count);
    },

    onContentIn: function(html) {
        // [gallery id=N] → visual placeholder (runs on load and code-view toggle back)
        return html.replace(/\[gallery id=(\d+)\]/g, function(_, id) {
            var info = galleryCache[String(id)] || { name: '#' + id, image_count: '?' };
            return buildGalleryEmbed(id, info.name, info.image_count);
        });
    },

    onContentOut: function(html) {
        // visual placeholder → [gallery id=N] (runs on save and code-view toggle forward)
        html = html.replace(
            /<(figure|div|p)\b[^>]*data-gallery-id="(\d+)"[^>]*>[\s\S]*?<\/\1>/gi,
            '[gallery id=$2]'
        );
        return html.replace(/\s*contenteditable="[^"]*"/gi, '');
    }
});

function buildGalleryEmbed(id, name, imageCount) {
    return '<figure class="gallery-embed" data-gallery-id="' + parseInt(id, 10)
        + '" contenteditable="false">'
        + '<span class="gallery-embed__name">' + escHtml(String(name)) + '</span>'
        + '<span class="gallery-embed__count">' + imageCount + '</span>'
        + '</figure>';
}
```

---

## Full CMS example

A realistic CMS page with a server image gallery, `onImageInsert` for media library IDs, a gallery picker with shortcode storage, and a fetch-first pattern that ensures the gallery cache is populated before the editor renders so shortcodes resolve to real names on first paint.

```javascript
var galleryCache = {};  // populated before editor init

(function() {
    if (typeof WYSIWYGEditor === 'undefined') { return; }

    var textareas = Array.from(document.querySelectorAll('textarea.wysiwyg-editor'));
    if (textareas.length === 0) { return; }

    var csrfToken = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

    // Only fetch gallery data when the textarea content actually contains shortcodes.
    // Avoids a network request on gallery-free pages.
    var needsGalleries = textareas.some(function(ta) {
        return /\[gallery\s+id=\d+\]/.test(ta.value);
    });

    function initEditors() {
        textareas.forEach(function(textarea) {
            new WYSIWYGEditor(textarea, {
                minHeight:    '300px',
                locale:       'hu',
                imageUpload:  true,
                serverImages: '/admin/media/editor-api',
                serverGalleries: '/admin/galeria/api',

                toolbar: [
                    'bold', 'italic', 'underline', 'strikethrough', '|',
                    'h2', 'h3', 'h4', '|',
                    'ul', 'ol', 'blockquote', '|',
                    'link', 'unlink', 'image', 'gallery', '|',
                    'alignLeft', 'alignCenter', 'alignRight', '|',
                    'table', 'hr', '|',
                    'undo', 'redo', '|',
                    'clearFormat', 'codeView'
                ],

                onImageInsert: function(data) {
                    // Only customise server-sourced images (media library)
                    if (data.source !== 'server' || !data.serverItem) { return null; }
                    var item    = data.serverItem;
                    var mediaId = parseInt(item.id, 10) || 0;
                    var src     = escHtmlAttr(item.url      || '');
                    var alt     = escHtmlAttr(item.alt_text || data.alt || '');
                    var caption = item.caption || '';

                    return '<figure class="post-body__figure">'
                        + '<img src="' + src + '" alt="' + alt + '" data-media-id="' + mediaId + '">'
                        + (caption ? '<figcaption>' + escHtml(caption) + '</figcaption>' : '')
                        + '</figure>';
                },

                onGalleryInsert: function(data) {
                    var g = data.gallery;
                    return buildGalleryEmbed(g.id, g.name, g.image_count);
                },

                onContentIn: function(html) {
                    return html.replace(/\[gallery id=(\d+)\]/g, function(_, id) {
                        var info = galleryCache[String(id)] || { name: '#' + id, image_count: '?' };
                        return buildGalleryEmbed(id, info.name, info.image_count);
                    });
                },

                onContentOut: function(html) {
                    html = html.replace(
                        /<(figure|div|p)\b[^>]*data-gallery-id="(\d+)"[^>]*>[\s\S]*?<\/\1>/gi,
                        '[gallery id=$2]'
                    );
                    return html.replace(/\s*contenteditable="[^"]*"/gi, '');
                }
            });
        });
    }

    if (needsGalleries) {
        // Fetch gallery list first so onContentIn can resolve shortcodes to real names.
        // Promise.race caps the wait at 3 s — on timeout the editor still opens with #N/? fallbacks.
        var fetchDone = fetch('/admin/galeria/api', {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrfToken }
        })
        .then(function(r) { return r.ok ? r.json() : { items: [] }; })
        .catch(function()  { return { items: [] }; })
        .then(function(data) {
            (data.items || []).forEach(function(g) {
                galleryCache[String(g.id)] = g;
            });
        });

        var timeout = new Promise(function(resolve) { setTimeout(resolve, 3000); });
        Promise.race([fetchDone, timeout]).then(initEditors);
    } else {
        initEditors();
    }
}());

function buildGalleryEmbed(id, name, imageCount) {
    var count = (imageCount === '?' || imageCount === undefined)
        ? '?' : String(parseInt(imageCount, 10));
    return '<figure class="wysiwyg-gallery-embed" data-gallery-id="' + parseInt(id, 10)
        + '" contenteditable="false">'
        + '<span class="wysiwyg-gallery-embed__name">' + escHtml(String(name)) + '</span>'
        + '<span class="wysiwyg-gallery-embed__count">' + count + ' kép</span>'
        + '</figure>';
}

function escHtml(str) {
    return str.replace(/&/g, '&amp;').replace(/</g, '&lt;')
              .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}
function escHtmlAttr(str) { return escHtml(str); }
```

---

## Security considerations

- **Server-side auth on every endpoint.** The editor sends `credentials: 'same-origin'`, so session cookies are forwarded. Your endpoint must verify permissions on every request — never rely on the editor only showing the button to authorised users.
- **Validate `folder` parameter.** Reject any value containing `..` or path-separator sequences before constructing a filesystem path. The bundled `test-images.php` shows a reference implementation.
- **Sanitize HTML server-side.** `onContentOut` produces the HTML that gets saved. Always run a server-side HTML sanitiser (e.g. HTMLPurifier) before storing editor output in the database. The editor's client-side sanitization is for display only.
- **`onContentIn` receives database content.** If your shortcodes contain user-supplied values (e.g., a gallery name embedded in the shortcode), escape them when building placeholder HTML — do not inject them raw into innerHTML.
- **File upload (base64).** The Upload tab encodes the selected file as a base64 data URI and inserts it directly into the editor DOM. It never sends the file to the server. If you need server-side file storage, use `onImageInsert` with `source === 'upload'` to intercept the data URI and POST it yourself.

---

## Version history

| Version | Feature                                                                                      |
|---------|----------------------------------------------------------------------------------------------|
| v2.5.0  | Server image gallery with pagination, search, folder navigation, wide modal                  |
| v2.6.0  | `onImageInsert` callback; `serverItem` passthrough; `insertImageFromUrl` `options` parameter |
| v2.7.0  | `serverGalleries`, `onGalleryInsert`, `onContentIn`, `onContentOut`, gallery picker modal    |
