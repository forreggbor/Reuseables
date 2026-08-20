# Changelog

All notable changes to UiKit will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.8.0] - 2026-08-19

### Summary

| Category | Description |
|----------|--------------|
| Added    | `UiKit.Modal` — drop-in `bootstrap.Modal`-compatible class |

### Added
- `js/ui-modal.js` + `css/uikit.css` (structural rules) — `UiKit.Modal`, matching `bootstrap.Modal`'s own constructor signature, static `getInstance()`/`getOrCreateInstance()`, instance `show()`/`hide()`/`toggle()`/`dispose()`/`handleUpdate()`, and `show.bs.modal`/`shown.bs.modal`/`hide.bs.modal`/`hidden.bs.modal`/`hidePrevented.bs.modal` events. Recognizes the same `data-bs-toggle="modal"`/`data-bs-target`/`data-bs-dismiss="modal"`/`data-bs-backdrop`/`data-bs-keyboard` markup convention already used by every existing modal in the host codebase, so a call site migrates with a mechanical `bootstrap.Modal` → `UiKit.Modal` rename and zero markup changes — this is a different, larger-surface strategy than the other components (which each expose their own small API): a general-purpose modal is used for ~166 call sites across ~69 files, many with page-specific form content, so matching Bootstrap's own class shape exactly was far less code than designing and porting each call site to a new bespoke API.
- Coexistence with Bootstrap is per-element-ownership-based rather than the blanket "step aside while Bootstrap is present" guard used by collapse/dropdown/tabs/offcanvas — Modal is driven by an explicit, stateful instance object, and a blanket guard was found (during live migration testing) to break two real cases: a dismiss button on an already-`UiKit.Modal`-owned modal silently doing nothing (Bootstrap's own dismiss handler created a fresh, never-shown, harmless-but-useless instance instead), and a double backdrop appearing when the same modal was reachable both via a migrated explicit call site (e.g. "edit") and a plain `data-bs-toggle="modal"` button with no JS of its own (e.g. "add new") — Bootstrap's listener, registered first, always got to open its own instance before this component's listener could claim ownership and stop it. Fixed by (1) deciding per-click, per-element whether *this component's own* instance registry already owns the target, deferring to Bootstrap entirely when it doesn't (zero regression for not-yet-migrated modals), and (2) loading `ui-modal.js` *before* the Bootstrap JS bundle `<script>` tag specifically (unlike every other UiKit component) so this component's delegated listener always runs first and can reliably call `stopImmediatePropagation()` before Bootstrap's own listener gets a chance to act.
- `css/uikit.css` — structural-only rules (display toggle, backdrop, fade transform), matching the same "defer visual box styling to the still-loaded Bootstrap CSS" approach as dropdown/tabs/popover/offcanvas; existing `.modal-sm`/`.modal-lg`/`.modal-xl`/`.modal-dialog-centered`/`.modal-dialog-scrollable` variants are untouched so Bootstrap CSS keeps styling them.
- **Known simplification, stated up front**: no scrollbar-width compensation on fixed-position elements (only `<body>` gets the padding-right compensation); no true nested-modal stacking (opening a second modal while one is already open closes the first, matching the codebase's existing single-modal-at-a-time usage).
- `0_test/test.html` — modal demo (JS-call open, declarative open, backdrop-click close, Escape close, static-backdrop shake-not-close).

## [0.7.0] - 2026-08-19

### Summary

| Category | Description |
|----------|--------------|
| Added    | Popover — programmatic API, matches the one real (manual-trigger) caller |
| Added    | Offcanvas — data-bs-toggle-compatible, defers to Bootstrap JS while it's still loaded |

### Added
- `js/ui-popover.js` + `css/uikit.css` — `UiKit.popover.show/hide/toggle/isShown(el)`. Single shared floating panel using Bootstrap Popover's own DOM structure (`.popover` > `.popover-arrow`/`.popover-header`/`.popover-body`) so its still-loaded CSS keeps styling it. Content is read fresh from `title`/`data-bs-content` on every `show()` call, so a caller can update the content attribute and call `show()` again to refresh an already-open popover (e.g. a "loading…" placeholder replaced once an async request resolves) with no extra API needed. No auto-triggering (click/focus/hover) — programmatic only, matching the one real caller's `data-bs-trigger="manual"` usage exactly. A second, `trigger:"focus"`-configured caller turned out to have no matching markup anywhere on its page — dead code, removed rather than ported (same finding pattern as A4/tooltip's dead `admin-init.js`).
- `js/ui-offcanvas.js` + `css/uikit.css` — self-initializing delegated click handling for `data-bs-toggle="offcanvas"` / `data-bs-dismiss="offcanvas"`, a shared backdrop (click closes), Escape-to-close, page-scroll lock while open. Same Bootstrap-coexistence handling as collapse/dropdown/tabs. Known simplification: no scrollbar-width compensation on desktop (both real callers are mobile-only, `.d-lg-none`, where this practically never applies).
- Accordion (`.accordion`/`.accordion-collapse`, `data-bs-parent`) required no new component at all — Bootstrap doesn't actually ship a separate "Accordion" JS class, it's Collapse plus `data-bs-parent`, already fully covered by [0.4.0]'s collapse work.
- `0_test/test.html` — popover and offcanvas demo sections.

## [0.6.0] - 2026-08-19

### Summary

| Category | Description |
|----------|--------------|
| Added    | Tabs — data-bs-toggle-compatible, defers to Bootstrap JS while it's still loaded |

### Added
- `js/ui-tabs.js` + `css/uikit.css` (structural rules) — `UiKit.tab.show(tabEl)` plus self-initializing delegated click handling for `data-bs-toggle="tab"`/`"pill"`/`"list"`. Fires `hide.bs.tab`/`show.bs.tab`/`hidden.bs.tab`/`shown.bs.tab` matching Bootstrap Tab's own event names/targets. Same Bootstrap-coexistence handling as collapse/dropdown.
- **Known simplification, stated up front**: no arrow-key roving-tabindex navigation between tabs (Bootstrap's own keyboard behavior) — click and `.show()` activation both work fully.
- `0_test/test.html` — tabs demo (two panes, fade transition, event firing).

## [0.5.0] - 2026-08-19

### Summary

| Category | Description |
|----------|--------------|
| Added    | Dropdown — data-bs-toggle-compatible, defers to Bootstrap JS while it's still loaded |

### Added
- `js/ui-dropdown.js` + `css/uikit.css` (minimal structural rules) — self-initializing delegated click handling for `data-bs-toggle="dropdown"`. Auto-close matches Bootstrap's default (`data-bs-auto-close="true"`, the only value used in the host codebase): any click that isn't on a toggle closes every open menu, Escape closes every open menu. Same Bootstrap-coexistence handling as collapse (steps aside while Bootstrap JS is loaded, takes over automatically once it's removed).
- **Known simplification, stated up front rather than silently matched**: positioning is plain CSS (opens directly below the toggle; `.dropdown-menu-end` right-aligns it) — no Popper-style collision detection or auto-flip near a viewport edge. Visual styling (colors/borders/shadows) is intentionally left to Bootstrap CSS for now (still loaded) rather than duplicated here; a later step of the host project's Bootstrap-removal work replaces that CSS in one place.
- `0_test/test.html` — dropdown demo (two independent menus, one right-aligned) demonstrating open/switch/outside-click-close/Escape-close.

## [0.4.0] - 2026-08-19

### Summary

| Category | Description |
|----------|--------------|
| Added    | Collapse — data-bs-toggle-compatible, defers to Bootstrap JS while it's still loaded |

### Added
- `js/ui-collapse.js` + `css/uikit.css` (collapse rules) — `UiKit.collapse.show(el)` / `.hide(el)` / `.toggle(el)`. Self-initializing delegated click listener for `data-bs-toggle="collapse"` (+ `data-bs-target`/`href`/`data-bs-parent` accordion support), reusing the `.collapse`/`.collapsing`/`.show` class names already present in a very large amount of existing markup rather than inventing a new convention. Fires the same `show.bs.collapse`/`shown.bs.collapse`/`hide.bs.collapse`/`hidden.bs.collapse` events and toggles `.collapsed` on triggers, matching Bootstrap Collapse's own behavior exactly.
- **Bootstrap coexistence handling**: unlike Toast/Modal/Tooltip, Bootstrap's own Collapse self-initializes a capture-phase delegated click listener the moment `bootstrap.bundle.js` loads (no explicit instantiation needed) — a real, discovered issue where both this component and Bootstrap's own reacted to the same click and fought over the same classes, net effect: nothing visibly toggled. Fixed by having the click-driven path check for Bootstrap's presence and step aside if found, letting Bootstrap drive clicks (its result is class-for-class identical) until it's actually removed from the page — at which point this component takes over automatically, no code changes needed anywhere. The programmatic `show/hide/toggle()` API always uses this component's own implementation regardless.
- `0_test/test.html` — collapse demo section (plain toggle + `show.bs.collapse`/`hide.bs.collapse`-driven chevron rotation) and an accordion demo (`data-bs-parent`).

## [0.3.0] - 2026-08-19

### Summary

| Category | Description |
|----------|--------------|
| Added    | Tooltip — self-initializing, delegated-event based, no per-element setup |

### Added
- `js/ui-tooltip.js` + `css/uikit.css` (tooltip rules) — self-initializing on load via a single document-level delegated `mouseover`/`mouseout`/`focusin`/`focusout` listener, so dynamically-added trigger elements work automatically with zero re-init calls. Recognizes `data-bs-toggle="tooltip"` + `data-bs-placement` (top/bottom/left/right, default top) for markup compatibility with existing Bootstrap-authored trigger elements — this is an attribute-name convention only, no Bootstrap CSS/JS dependency. Content comes from the trigger's `title` attribute, captured once and moved to `data-uik-tooltip-content` (native `title` removed) so the browser's own tooltip never also appears. Plain-text content only (no HTML mode).
- `0_test/test.html` — tooltip demo section (all 4 placements + a dynamically-added trigger, to demonstrate the no-init-needed behavior).

## [0.2.0] - 2026-08-19

### Summary

| Category | Description |
|----------|--------------|
| Added    | `UiKit.confirm()` — native `<dialog>`-based confirmation dialog |

### Added
- `js/ui-dialog.js` + `css/uikit.css` (dialog rules) — `UiKit.confirm({ message, onConfirm, title, confirmText, cancelText, danger, allowHtml })`. Built on native `<dialog>`/`showModal()`, so focus trap, Escape-to-close, and the backdrop all come from the browser instead of being reimplemented. Auto-focuses and selects the first input/textarea/select in the message body; Enter in an input/select submits (textareas excluded so Enter still inserts a newline). `danger: true` swaps the confirm button to a red/destructive style. `allowHtml: true` renders `message` as HTML instead of plain text — caller's responsibility to only pass trusted markup, same contract as `UiKit.toast()`'s escaping default.
- New labels (overridable via `UiKit.configure({ labels })`): `confirmTitle` (default "Confirm"), `confirmButton` (default "Confirm"), `cancelButton` (default "Cancel").
- `0_test/test.html` — dialog demo section (default/danger/custom-labels/input+Enter/allowHtml).

### Fixed
- Dialog buttons had no explicit `:hover` styling, so a host page's own generic `button:hover` rule (present in the dev test harness itself, and possibly in some host projects) could visually wash them out. Added explicit hover/focus-visible states for both buttons so the component no longer depends on the absence of ambient page styles.

## [0.1.0] - 2026-08-19

### Summary

| Category | Description |
|----------|--------------|
| Added    | `UiKit.configure()` shared core (label overrides, no host globals/network reads) |
| Added    | `UiKit.toast()` — dismissible, auto-hiding corner notification (4 types) |

### Added
- `js/uikit.js` — shared core (`UiKit.configure({ labels })`, internal `_label()` resolver). Project-independent by design: no host config object, CSRF token, or translation function is ever read, and the module never makes a network request.
- `js/ui-toast.js` + `css/uikit.css` (toast rules) — `UiKit.toast(message, type)`, `type` one of `success`/`error`/`warning`/`info` (unknown values fall back to `info`). Auto-dismisses after 5s or on close-button click; only one toast is shown at a time (a new call replaces the current one). Own inline SVG icon set (Bootstrap Icons 1.11.3 path data, MIT-licensed).
- `0_test/test.html` — standalone dev harness, no build step or host project required.
