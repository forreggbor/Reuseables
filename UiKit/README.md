# UiKit

Zero-dependency vanilla JS/CSS UI component kit — replaces the small set of Bootstrap JS
components (toast, modal/dialog, tooltip, collapse, dropdown, tabs) most host projects only
use for a handful of interaction patterns, without pulling in Bootstrap's JS bundle.

## Status

In progress. Components ship one at a time; each is usable standalone as soon as it lands.

| Component                          | Status                                          |
|------------------------------------|-------------------------------------------------|
| Toast (`UiKit.toast()`)            | ✅ done                                         |
| Confirm dialog (`UiKit.confirm()`) | ✅ done                                         |
| Tooltip                            | ✅ done                                         |
| Collapse                           | ✅ done                                         |
| Dropdown                           | ✅ done                                         |
| Tabs                               | ✅ done                                         |
| Popover                            | ✅ done                                         |
| Offcanvas                          | ✅ done                                         |
| Accordion                          | ✅ (via collapse, no separate component needed) |
| Modal (`UiKit.Modal`)              | ✅ done                                         |

## Design principles

- **Project-independent.** No host globals are read (no `#appConfig`-style JSON config,
  no `window.CSRF_TOKEN`, no `__()` translation function), no network requests, no
  dependency on any host CSS framework's class names (`btn`, `text-bg-*`, `modal-*`, …).
  A host wires its own translated text in via `UiKit.configure({ labels: {...} })`; every
  component still works with its own English built-in defaults if `configure()` is never
  called.
- **Own visual tokens.** All CSS lives under `.uik-*` class names and `--uik-*` custom
  properties, each with a sane built-in fallback. A host that wants to reskin a component
  to match its own theme does so by setting those custom properties (or overriding the
  `.uik-*` rules) — never by relying on the module reading host CSS variables directly.
  See `css/uikit.css`.
- **Own icons.** Components that need an icon embed their own inline SVG (path data
  copied from Bootstrap Icons, MIT-licensed — see the host project's own
  `LICENSES/bootstrap-icons-MIT.txt`), so the module never depends on a host icon
  stylesheet/webfont being loaded.

## Usage

```html
<link rel="stylesheet" href="css/uikit.css">
<script src="js/uikit.js"></script>
<script src="js/ui-toast.js"></script>
<script>
    // Optional — only needed to override built-in English defaults with host-translated text.
    UiKit.configure({ labels: { /* component-specific keys, see each component's own docs */ } });

    UiKit.toast('Saved successfully', 'success'); // 'success' | 'error' | 'warning' | 'info'
</script>
```

## Standalone dev test

Open `0_test/test.html` directly in a browser (no build step, no server needed) to try
every shipped component in isolation.

## Installation into a host project

Copy (or `rsync`) this folder into the host project, e.g.:

```bash
rsync -av --delete Reusables/UiKit/ lib/UiKit/
```

The host then loads `lib/UiKit/css/uikit.css` and `lib/UiKit/js/{uikit,ui-toast}.js` from
wherever it serves static assets, and writes a thin host-specific adapter that translates
its own conventions (e.g. reading a translated label from its own i18n system) into a
`UiKit.configure()` call — see the JupitERP integration for a worked example once it lands.
