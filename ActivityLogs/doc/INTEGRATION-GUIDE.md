# ActivityLogs Admin Interface — Integration Guide

This guide shows the minimal steps required to add the full activity log admin UI to a host project.

## Prerequisites

- ActivityLogs module copied to `lib/ActivityLogs/` (via rsync)
- `ActivityLogger::init($pdo, ['table_name' => ..., 'encryption_key' => ...])` called in the host bootstrap (usually already done for logging)
- A protected admin route

## 1. Copy public assets

Copy the module's CSS and JS to your webroot once (or add it to your deploy script):

```bash
cp lib/ActivityLogs/css/activity-logs.css public/css/activity-logs.css
cp lib/ActivityLogs/js/activity-logs.js   public/js/activity-logs.js
```

## 2. Add asset tags to your admin layout

The activity-log index page renders as a body fragment embedded in this layout, so these tags are what style and activate the admin UI.

In your admin layout template, add:

```html
<link rel="stylesheet" href="/css/activity-logs.css">
```

And just before `</body>`:

```html
<script src="/js/activity-logs.js"></script>
```

## 3. Create the controller/route

```php
<?php

require_once 'lib/ActivityLogs/ActivityLogsAdmin.php';
// (or use your autoloader)

use ActivityLogs\ActivityLogsAdmin;
use ActivityLogs\Adapters\Auth\CallableAuthAdapter;

// 3a. Authentication adapter
$auth = new CallableAuthAdapter(
    fn()            => Auth::isSysadmin(),           // isAuthorized(): bool
    fn()            => Auth::id(),                   // getCurrentUserId(): ?int
    fn(array $ids)  => User::getNameMap($ids),       // getUserMap(array $ids): array<int,string>
);

// 3b. Instantiate the facade
$admin = new ActivityLogsAdmin(
    pdo:        $pdo,
    config:     [
        'base_url'  => '/admin/activity-log',
        'table_name' => 'activity_logs',
        'timezone'  => 'Europe/Budapest',
        'locale'    => 'hu_HU',
        'page_size' => 50,
        // Optional: custom logger
        // 'logger'  => fn(string $level, string $msg, array $ctx) => App::log($level, $msg, $ctx),
    ],
    auth:       $auth,
    // Optional: host translator
    // translator: $translator,
);

// 3c. Register entity resolvers (optional but recommended)
$admin->resolvers()->register('product',  fn(string $id) => Product::findName($id));
$admin->resolvers()->register('order',    fn(string $id) => Order::findLabel($id));
// Register a batch resolver for high-volume types:
$admin->resolvers()->registerBatch('user', fn(array $ids) => User::getNameMap(array_map('intval', $ids)));

// 3d. Dispatch
$action = $_GET['action'] ?? 'index';

// 3e. Index returns a body fragment — embed it in your admin layout (which loads the CSS/JS)
if ($action === 'index') {
    echo $layout->render('admin', ['content' => $admin->render($_GET)]);
    exit;
}

// 3f. Other actions: details (JSON), exportCsv (CSV stream), printView (standalone print window)
$envelope = $admin->handle($action, $_GET);
http_response_code($envelope['status']);
header('Content-Type: ' . $envelope['content_type']);
if ($envelope['filename'] !== null) {
    header('Content-Disposition: attachment; filename="' . addslashes($envelope['filename']) . '"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
}
if ($envelope['body'] instanceof \Generator) {
    foreach ($envelope['body'] as $chunk) {
        echo $chunk;
        flush();
    }
} else {
    echo $envelope['body'];
}
exit;
```

## Configuration options

| Key          | Type     | Default                       | Description                                          |
|--------------|----------|-------------------------------|------------------------------------------------------|
| `base_url`   | string   | **required**                  | Same-origin path, starts with `/`                    |
| `table_name` | string   | `activity_logs`               | Log table name (letters, digits, underscores only)   |
| `timezone`   | string   | `date_default_timezone_get()` | PHP timezone identifier used for all date math       |
| `locale`     | string   | `en_US`                       | UI language: `en_US` or `hu_HU`                      |
| `page_size`  | int      | `50`                          | Rows per page (must be >= 1)                         |
| `logger`        | callable | `error_log` fallback          | `fn(string $level, string $msg, array $ctx): void`      |
| `print_max_rows` | int     | `5000`                        | Maximum rows fetched for the print view; prevents OOM on large tables |
| `asset_base_url` | string  | `''`                          | URL base for `activity-logs.css`/`.js` served by the **print view only**; not needed for the index page (styled by the host layout). Required for auto-print and styled print output — see **Print view** below |

## Entity resolvers

Register one callable per `entity_type` value you store in the log:

```php
// Single-item resolver (called once per row on the current page)
$admin->resolvers()->register('product', fn(string $id) => Product::find($id)?->name ?? 'Product #' . $id);

// Batch resolver (called once per page render — preferred for DB-backed types)
$admin->resolvers()->registerBatch('product', function (array $ids): array {
    $rows = Product::whereIn('id', $ids)->pluck('name', 'id');
    return iterator_to_array($rows);
});
```

If no resolver is registered for an `entity_type`, the viewer falls back to `"{entity_type} #{entity_id}"`.
If a resolver throws, the fallback is used and the error is logged once per entity type (not per row).

## Timezone

All boundary math (Today, This Week) and all timestamp display use the configured `timezone` —
never the MySQL server TZ. The ActivityLogger engine stores `created_at` as a PHP wall-clock string
(`date('Y-m-d H:i:s')`), so this value should match your PHP default timezone.

**Note:** When a date filter (`date_from`/`date_to`) restricts results to a past period, Today and
This Week will show 0 — they count "of the filtered rows, how many fall within the last 24h/7d."
This is correct behavior; it is not a bug.

## CSV export streaming

The CSV export response body is a PHP `\Generator`. The emit loop at step 3f streams it without
buffering the entire file in memory, which is important for large audit tables.

**Known limitation:** The offset-based chunking uses `ORDER BY created_at DESC`. If rows are
inserted during a large export, the window may shift and produce a few duplicate rows near chunk
boundaries. This is acceptable for an audit log viewer. If it becomes a problem, replace the
chunking with cursor-based paging (cursor on `created_at`/`id`).

## Print view

The print view (`?action=printView`) opens as a standalone page via `window.open()`, so it cannot
inherit your admin layout's CSS or JS. This makes `asset_base_url` print-only — the index page
is styled via the host admin layout (step 2), not this config option.

To get a styled printout and automatic print-dialog:

1. Set `asset_base_url` in your config to the public URL path where you deployed the module assets:

```php
$admin = new ActivityLogsAdmin($pdo, [
    'base_url'        => '/admin/activity-log',
    'asset_base_url'  => '/assets/activity-logs/', // trailing slash required
    // ...
], $auth);
```

2. Ensure the assets exist at that path (the same files you copied in step 1):

```bash
cp lib/ActivityLogs/css/activity-logs.css public/assets/activity-logs/activity-logs.css
cp lib/ActivityLogs/js/activity-logs.js   public/assets/activity-logs/activity-logs.js
```

When `asset_base_url` is set, the print page:
- Loads `{asset_base_url}activity-logs.css` for styled output.
- Loads `{asset_base_url}activity-logs.js`, which detects the `.al-print-root` element and calls
  `window.print()` automatically.
- Emits **no inline `<script>`** — fully compatible with a strict `script-src 'self'` CSP.

When `asset_base_url` is not set (the default), the page renders unstyled and the print dialog
does not open automatically. The CSP finding is still closed — there is no inline script fallback.

**Row cap:** By default the print view loads at most 5 000 rows. If the filtered result set exceeds
this limit, a visible notice is shown and the full data is available via CSV export. Adjust with
`print_max_rows` in config.

## CSRF

All four actions (`index`, `details`, `exportCsv`, `printView`) are read-only GET requests.
No CSRF token is required for read-only operations. If destructive actions (e.g. delete-old-logs)
are added in the future, a CSRF adapter will need to be wired before those actions are dispatched.

## PDO note

Pass the **same** PDO connection to `ActivityLogsAdmin` that you use for `ActivityLogger::init()`.
The facade re-calls `ActivityLogger::init($pdo, ['table_name' => ...])` on every request to pin
the static engine to this instance's configuration. Using a different PDO will redirect all
`ActivityLogger::log()` calls on the same request to the facade's connection.
