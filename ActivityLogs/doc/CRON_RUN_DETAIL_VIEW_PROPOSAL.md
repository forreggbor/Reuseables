# Cron Run Detail View — Proposal for Future Module Integration

## Overview

This document captures the cron-run detail view feature that was built in the TrafficJournal
project and removed when the hand-rolled `/admin/logs` viewer was replaced by the generic
`ActivityLogsAdmin` facade (v1.2.x). The intent is to re-implement this feature inside the
module in a future version, so it is available to all host projects without duplication.

The source that was deleted from TrafficJournal is embedded verbatim below.

---

## Trigger condition

A log row qualifies for the cron-run detail renderer when:

```js
log.action === 'cron.run' && log.entity_type === 'cron_task'
```

`entity_id` holds the task name (e.g. `camera_sync`). The module would need a host-registerable
per-action renderer callback so this renderer can be wired without forking the module.
See the **Module integration note** at the end.

---

## Data contract (written by `Scheduler::writeActivityRow()`)

The log row is written with the following payload. The verbatim PHP code that builds it:

```php
$newValues = array_merge([
    'status'        => $status,
    'duration_ms'   => $bufferData['duration_ms'],
    'action_count'  => $bufferData['action_count'],
    'warning_count' => $bufferData['warning_count'],
    'error_count'   => $bufferData['error_count'],
], $bufferData['summary']);

$context = [
    'task'        => $taskName,
    'started_at'  => $bufferData['started_at_iso'],
    'log_lines'   => $lines,
    'truncated'   => $truncated,
    'total_lines' => $totalLines,
];

ActivityLogger::log(
    null,         // 1: userId
    'cron.run',   // 2: action
    'cron_task',  // 3: entityType
    $taskName,    // 4: entityId
    null,         // 5: oldValues
    $newValues,   // 6: newValues
    'cron',       // 7: source
    $context      // 8: context
);
```

### `new_values` fields

| Key | Type | Notes |
|---|---|---|
| `status` | `string` | `'success'` or `'failure'` |
| `duration_ms` | `int` | Wall-clock milliseconds |
| `action_count` | `int` | Number of business-action log lines |
| `warning_count` | `int` | Number of WARNING-level lines |
| `error_count` | `int` | Number of ERROR-level lines |
| `synced` | `?int` | Task summary counter (present when the task emits it) |
| `failed` | `?int` | Task summary counter |
| `skipped` | `?int` | Task summary counter |
| `total` | `?int` | Task summary counter |
| `uploaded` | `?int` | Task summary counter |
| `checked` | `?int` | Task summary counter |
| `deleted_files` | `?int` | Task summary counter |
| `deleted_dirs` | `?int` | Task summary counter |

The task-summary keys come from `$bufferData['summary']` — an open-ended map populated by each
cron task. Only the keys the task actually wrote will be present; treat all as optional.

### `context` fields

| Key | Type | Notes |
|---|---|---|
| `task` | `string` | Human-readable task name |
| `started_at` | `string` | ISO 8601 timestamp |
| `log_lines` | `array` | Array of `{ts, level, msg, is_action}` objects |
| `truncated` | `bool` | `true` when the line count exceeded the 500-line cap |
| `total_lines` | `int` | Total untruncated line count |

#### `log_lines` element shape

```json
{ "ts": "14:32:05.123", "level": "WARNING", "msg": "Camera 3 unreachable", "is_action": false }
```

- `level`: `DEBUG` | `INFO` | `WARNING` | `ERROR`
- `is_action`: `true` when the line represents a business action (highlighted differently)

#### Truncation strategy

When filtered lines exceed 500:
1. Keep all WARNING/ERROR/action lines.
2. Append the tail of the full set to fill up to 500.
3. Set `truncated = true`, `total_lines` = original count.

---

## Six-section rendering spec

The detail view renders these six sections in order:

1. **Header strip** — task name (bold), status badge (`bg-success` / `bg-danger`), `started_at`
   timestamp (muted small), duration in ms (muted small).
2. **Counter chips** — `action_count`, `warning_count`, `error_count` as colored badges, followed
   by all task-summary keys (`synced`, `failed`, `skipped`, `total`, `uploaded`, `checked`,
   `deleted_files`, `deleted_dirs`) as light bordered badges. Skip keys that are `null`.
3. **Standard meta block** — two-column grid (6-up on md+): time, user, action, entity type,
   task name (entity_id), source, IP, user-agent, session ID, checksum, integrity status.
4. **Filter toolbar** — four-button group (`All` / `Actions only` / `Warnings & errors` /
   `Errors only`) that toggles a `cron-log-filter--{all|actions|warnings|errors}` class on the
   timeline `<ol>`. CSS hide-rules (see below) do the filtering without JS per-line logic.
5. **Log timeline** — `<ol class="cron-log-timeline cron-log-filter--all">`, one `<li>` per line.
   Each `<li>` carries `cron-log-line--{level}` and optionally `cron-log-line--action`. Contents:
   `<span.cron-log-ts>` + level badge (Bootstrap icon + label) + optional action icon + `<code>`
   for the message.
6. **Truncation notice** — if `ctx.truncated === true`, show a muted small line:
   `"%d more lines hidden"` where `%d = total_lines − log_lines.length`.

---

## Verbatim JavaScript (`renderCronLogDetail`)

Source: `TrafficJournal/app/views/admin/logs/index.php:391–591` (deleted in v1.2.x migration)

> Note: the original is a PHP-template hybrid — `<?= json_encode(__('TEXT_*')) ?>` calls are
> inlined string literals. The resolved English strings are shown in the comment to the right of
> each such call. The `cronTaskNames` object referenced at line 394 is a PHP-rendered map of
> `entity_id → human-readable name` that the host injects when the page loads.

```js
function renderCronLogDetail(container, log, verified) {
    const nv  = log.new_values  || {};
    const ctx = log.context     || {};
    const taskName   = cronTaskNames[log.entity_id] || log.entity_id || '-';
    const status     = nv.status || 'unknown';
    const durationMs = nv.duration_ms != null ? nv.duration_ms + ' ms' : '-';

    // --- 1. Header strip ---
    const header = document.createElement('div');
    header.className = 'mb-3 d-flex flex-wrap align-items-center gap-2';

    const heading = document.createElement('h6');
    heading.className = 'mb-0 fw-bold';
    heading.textContent = taskName;
    header.appendChild(heading);

    const statusBadge = document.createElement('span');
    statusBadge.className = 'badge ' + (status === 'success' ? 'bg-success' : 'bg-danger');
    statusBadge.textContent = status === 'success'
        ? /* TEXT_STATUS_CRON_SUCCESS */ 'Success'
        : /* TEXT_STATUS_CRON_FAILURE */ 'Failure';
    header.appendChild(statusBadge);

    const startedSpan = document.createElement('span');
    startedSpan.className = 'text-muted small';
    startedSpan.textContent = ctx.started_at || log.created_at || '-';
    header.appendChild(startedSpan);

    const durationSpan = document.createElement('span');
    durationSpan.className = 'text-muted small';
    durationSpan.textContent = durationMs;
    header.appendChild(durationSpan);

    container.appendChild(header);

    // --- 2. Counter chips ---
    const chips = document.createElement('div');
    chips.className = 'mb-3 d-flex flex-wrap gap-2';

    const counterDefs = [
        [/* TEXT_LABEL_CRON_ACTIONS_COUNT  */ 'Actions',   nv.action_count,  'bg-secondary'],
        [/* TEXT_LABEL_CRON_WARNINGS_COUNT */ 'Warnings',  nv.warning_count, 'bg-warning text-dark'],
        [/* TEXT_LABEL_CRON_ERRORS_COUNT   */ 'Errors',    nv.error_count,   'bg-danger'],
    ];
    counterDefs.forEach(function (def) {
        if (def[1] == null) { return; }
        const chip = document.createElement('span');
        chip.className = 'badge ' + def[2];
        chip.textContent = def[0] + ': ' + def[1];
        chips.appendChild(chip);
    });

    const taskCounters = ['synced', 'failed', 'skipped', 'total', 'uploaded', 'checked',
                          'deleted_files', 'deleted_dirs'];
    taskCounters.forEach(function (key) {
        if (nv[key] == null) { return; }
        const chip = document.createElement('span');
        chip.className = 'badge bg-light text-dark border';
        chip.textContent = key + ': ' + nv[key];
        chips.appendChild(chip);
    });

    container.appendChild(chips);

    // --- 3. Standard meta block ---
    const meta = document.createElement('div');
    meta.className = 'row g-2 mb-3 small';
    const metaItems = [
        [/* TEXT_TABLE_TIME        */ 'Time',        log.created_at],
        [/* TEXT_TABLE_USER        */ 'User',         log.user_name || log.user_id || '-'],
        [/* TEXT_TABLE_ACTION      */ 'Action',       log.action],
        [/* TEXT_TABLE_ENTITY_TYPE */ 'Entity type',  log.entity_type || '-'],
        [/* TEXT_LABEL_CRON_TASK   */ 'Task',         log.entity_id   || '-'],
        [/* TEXT_TABLE_SOURCE      */ 'Source',       log.source       || '-'],
        [/* TEXT_TABLE_IP_ADDRESS  */ 'IP address',   log.ip_address   || '-'],
        [/* TEXT_LABEL_USER_AGENT  */ 'User agent',   log.user_agent   || '-'],
        [/* TEXT_LABEL_SESSION_ID  */ 'Session ID',   log.session_id   || '-'],
        [/* TEXT_LABEL_CHECKSUM    */ 'Checksum',     log.checksum     || '-'],
        [/* TEXT_TABLE_INTEGRITY   */ 'Integrity',    verified
            ? /* TEXT_STATUS_INTEGRITY_VALID   */ 'Valid'
            : /* TEXT_STATUS_INTEGRITY_INVALID */ 'Invalid'],
    ];
    metaItems.forEach(function (pair) {
        const col = document.createElement('div');
        col.className = 'col-6 col-md-4';
        const lbl = document.createElement('div');
        lbl.className = 'fw-semibold text-muted';
        lbl.textContent = pair[0];
        const val = document.createElement('div');
        val.className = 'text-break';
        val.textContent = String(pair[1]);
        col.appendChild(lbl);
        col.appendChild(val);
        meta.appendChild(col);
    });
    container.appendChild(meta);

    // --- 4. Filter toolbar ---
    const logLines = ctx.log_lines || [];
    if (logLines.length === 0) { return; }

    const toolbar = document.createElement('div');
    toolbar.className = 'mb-2 d-flex align-items-center gap-2 flex-wrap';

    const filterLabel = document.createElement('span');
    filterLabel.className = 'text-muted small';
    filterLabel.textContent = /* TEXT_BUTTON_FILTER */ 'Filter' + ':';
    toolbar.appendChild(filterLabel);

    const btnGroup = document.createElement('div');
    btnGroup.className = 'btn-group btn-group-sm';

    const filterDefs = [
        ['all',      /* TEXT_BUTTON_FILTER_ALL                  */ 'All'],
        ['actions',  /* TEXT_BUTTON_FILTER_ACTIONS_ONLY         */ 'Actions only'],
        ['warnings', /* TEXT_BUTTON_FILTER_WARNINGS_AND_ERRORS  */ 'Warnings & errors'],
        ['errors',   /* TEXT_BUTTON_FILTER_ERRORS_ONLY          */ 'Errors only'],
    ];

    let timeline;

    filterDefs.forEach(function (fd, idx) {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn btn-outline-secondary' + (idx === 0 ? ' active' : '');
        btn.textContent = fd[1];
        btn.addEventListener('click', function () {
            btnGroup.querySelectorAll('.btn').forEach(function (b) { b.classList.remove('active'); });
            btn.classList.add('active');
            if (timeline) {
                timeline.className = timeline.className.replace(/\bcron-log-filter--\S+/g, '').trim();
                timeline.classList.add('cron-log-filter--' + fd[0]);
            }
        });
        btnGroup.appendChild(btn);
    });

    toolbar.appendChild(btnGroup);
    container.appendChild(toolbar);

    // --- 5. Log timeline ---
    timeline = document.createElement('ol');
    timeline.className = 'cron-log-timeline cron-log-filter--all';

    const levelIconMap = {
        'DEBUG':   'bi-bug',
        'INFO':    'bi-info-circle',
        'WARNING': 'bi-exclamation-triangle',
        'ERROR':   'bi-x-octagon',
    };
    const levelBadgeMap = {
        'DEBUG':   'bg-secondary',
        'INFO':    'bg-info text-dark',
        'WARNING': 'bg-warning text-dark',
        'ERROR':   'bg-danger',
    };

    logLines.forEach(function (line) {
        const li = document.createElement('li');
        li.className = 'cron-log-line cron-log-line--' + (line.level || 'INFO').toLowerCase();
        if (line.is_action) { li.classList.add('cron-log-line--action'); }

        const ts = document.createElement('span');
        ts.className = 'cron-log-ts';
        ts.textContent = line.ts || '';
        li.appendChild(ts);

        const lvlBadge = document.createElement('span');
        const lvl = (line.level || 'INFO').toUpperCase();
        lvlBadge.className = 'badge ' + (levelBadgeMap[lvl] || 'bg-secondary');
        const lvlIcon = document.createElement('i');
        lvlIcon.className = 'bi ' + (levelIconMap[lvl] || 'bi-info-circle') + ' me-1';
        lvlBadge.appendChild(lvlIcon);
        lvlBadge.appendChild(document.createTextNode(lvl));
        li.appendChild(lvlBadge);

        if (line.is_action) {
            const actionIcon = document.createElement('i');
            actionIcon.className = 'bi bi-lightning-charge cron-log-action-icon';
            li.appendChild(actionIcon);
        }

        const msg = document.createElement('code');
        msg.className = 'text-break';
        msg.textContent = line.msg || '';
        li.appendChild(msg);

        timeline.appendChild(li);
    });

    container.appendChild(timeline);

    // --- 6. Truncation notice ---
    if (ctx.truncated === true) {
        const hidden = (ctx.total_lines || 0) - logLines.length;
        const notice = document.createElement('div');
        notice.className = 'text-muted small mt-1';
        notice.textContent = /* TEXT_LABEL_CRON_TRUNCATED_HINT: '%d more lines hidden' */
            (hidden + ' more lines hidden');
        container.appendChild(notice);
    }
}
```

> **Host dependency:** The function references `cronTaskNames` — a JS object mapping
> `entity_id (task key) → display name` that the host renders inline when the page loads, e.g.
> `const cronTaskNames = {"camera_sync":"Camera Sync","cleanup_images":"Traffic Image Cleanup"};`.
> When porting into the module, this map must be provided through a host registration mechanism
> (e.g. resolved via `EntityResolverRegistry` and injected as a JS variable by the module).

---

## Verbatim CSS

Source: `TrafficJournal/public/css/admin.css:996–1057` (still present in the host project;
these rules would move into `activity-logs.css` inside the module)

```css
.cron-log-timeline {
    list-style: none;
    padding: 0;
    margin: 0;
    max-height: 50vh;
    overflow-y: auto;
    border: 1px solid var(--bs-border-color);
    border-radius: var(--bs-border-radius);
    background: var(--bs-light-bg-subtle);
}

.cron-log-line {
    display: flex;
    align-items: flex-start;
    gap: .4rem;
    padding: .25rem .6rem;
    border-bottom: 1px solid var(--bs-border-color-translucent);
    font-size: .8rem;
    flex-wrap: wrap;
}

.cron-log-line:last-child {
    border-bottom: none;
}

.cron-log-line--action {
    background-color: var(--bs-primary-bg-subtle);
}

.cron-log-ts {
    font-family: monospace;
    color: var(--bs-secondary-color);
    white-space: nowrap;
    flex-shrink: 0;
    font-size: .75rem;
    padding-top: .1rem;
}

.cron-log-action-icon {
    color: var(--bs-primary);
    flex-shrink: 0;
    padding-top: .15rem;
}

.cron-log-line code {
    word-break: break-all;
    flex: 1 1 0;
    min-width: 0;
    background: none;
    color: inherit;
    font-size: inherit;
    padding: 0;
}

/* Filter state classes — hide non-matching lines */
.cron-log-filter--actions .cron-log-line:not(.cron-log-line--action) { display: none; }

.cron-log-filter--warnings .cron-log-line:not(.cron-log-line--warning):not(.cron-log-line--error) { display: none; }

.cron-log-filter--errors .cron-log-line:not(.cron-log-line--error) { display: none; }
```

> **Note:** These CSS rules use Bootstrap 5 custom properties (`--bs-*`). The module's
> `activity-logs.css` uses its own `--al-*` tokens; the ported rules should be rewritten to use
> `--al-*` equivalents for portability (no Bootstrap dependency).

---

## Translation keys used

All keys below were present in the TrafficJournal `locale/{en_US,hu_HU}/messages.php` files.
They must be added to the module's built-in `en_US`/`hu_HU` string tables when this feature is
ported.

| Key | en_US value |
|---|---|
| `TEXT_STATUS_CRON_SUCCESS` | `Success` |
| `TEXT_STATUS_CRON_FAILURE` | `Failure` |
| `TEXT_LABEL_CRON_TASK` | `Task` |
| `TEXT_LABEL_CRON_ACTIONS_COUNT` | `Actions` |
| `TEXT_LABEL_CRON_WARNINGS_COUNT` | `Warnings` |
| `TEXT_LABEL_CRON_ERRORS_COUNT` | `Errors` |
| `TEXT_LABEL_CRON_TRUNCATED_HINT` | `%d more lines hidden` |
| `TEXT_BUTTON_FILTER` | `Filter` |
| `TEXT_BUTTON_FILTER_ALL` | `All` |
| `TEXT_BUTTON_FILTER_ACTIONS_ONLY` | `Actions only` |
| `TEXT_BUTTON_FILTER_WARNINGS_AND_ERRORS` | `Warnings & errors` |
| `TEXT_BUTTON_FILTER_ERRORS_ONLY` | `Errors only` |

Host-specific `TEXT_CRON_TASK_*` keys (e.g. `TEXT_CRON_TASK_CAMERA_SYNC`) are resolved via the
`cronTaskNames` map (see the host-dependency note above) — they do not need to move into the module.

---

## Module integration note

The generic `ActivityLogsAdmin` detail modal currently renders any log row as raw JSON. To
support the cron-run renderer (and future action-specific renderers) cleanly, the module needs
a **host-registerable per-action renderer hook**:

```php
// Proposed API sketch (not yet implemented)
$admin->registerDetailRenderer(
    match: fn(array $row): bool => $row['action'] === 'cron.run' && $row['entity_type'] === 'cron_task',
    jsCallbackName: 'renderCronLogDetail',
    jsSource: '/assets/cron-log-detail.js',
);
```

When a row is opened in the modal:
1. The module checks registered matchers in order.
2. If one matches, it calls the host-supplied JS callback instead of the raw-JSON fallback.
3. If none match, the existing raw-JSON display is used.

Without this hook, the cron renderer cannot be wired without forking the module's detail modal
template.
