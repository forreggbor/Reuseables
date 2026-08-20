# Changelog

All notable changes to UiKit will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.9.2] - 2026-08-20

### Summary

| Category | Description |
|----------|--------------|
| Added    | Modal sizing: host-overridable `min-width` floor, alongside the existing `max-width` override |

### Added
- `css/uikit.css` — `.modal-dialog`/`.modal-sm`/`.modal-lg`/`.modal-xl` now also accept a `min-width` override (`--uik-modal-min-width`, `--uik-modal-sm-min-width`, `--uik-modal-lg-min-width`, `--uik-modal-xl-min-width`), mirroring [0.9.1]'s `max-width` custom properties, defaulting to no floor (`0px`) — no modal is forced wider than before unless a host opts in. [0.9.1] removed the previous invented `.modal-lg` min-width floor without adding a replacement mechanism, which turned out to be a real gap: a specific host modal (flagged from another session working directly in JupitERP, tracing back to that host's own issue #438) needs a guaranteed minimum width so its fields don't crowd, and had no way to ask for one short of editing this file. The floor is wrapped in the same `min(94vw, ...)` as `max-width` so a host-set floor still yields to the viewport instead of forcing horizontal page overflow on a screen too narrow to fit it. Verified live: a 700px floor on a 320px viewport degrades to ~94vw with no page overflow; the same floor on a wide viewport, applied to the base `.modal-dialog` (default `max-width` 500px), correctly wins to 700px, matching the CSS spec's own min-width-over-max-width precedence.

## [0.9.1] - 2026-08-20

### Summary

| Category | Description |
|----------|--------------|
| Fixed    | Modal sizing (`.modal-dialog`/`.modal-sm`/`.modal-lg`/`.modal-xl`) always overrode a host's own width, with no way to opt out |
| Fixed    | `.modal-lg` used an invented 900px cap instead of Bootstrap 5's real 800px |

### Fixed
- `css/uikit.css` — `.modal-dialog`/`.modal-sm`/`.modal-lg`/`.modal-xl` `max-width` are now CSS custom properties with a fallback (`--uik-modal-max-width`, `--uik-modal-sm-max-width`, `--uik-modal-lg-max-width`, `--uik-modal-xl-max-width`), the same pattern already used for `--uik-dialog-max-width`. Previously these were bare px values in a rule that — by this file's own documented design ("this file always loads last, its own rule wins regardless") — always won over a host's own `.modal-dialog`/`.modal-lg`/`.modal-xl` sizing, with no override mechanism short of a higher-specificity selector. A real host rule (`app.css`'s `.modal-image-80 .modal-dialog`) only survived because it happened to be higher-specificity; a host targeting the bare class would have silently lost. A host can now override sizing globally (`:root { --uik-modal-lg-max-width: ...; }`) or scoped to one modal instance (setting the same property on that modal's wrapper), without fighting specificity or load order.
- `css/uikit.css` — `.modal-lg`'s fallback was `900px`, an invented value that never matched Bootstrap 5's real default (`800px`, confirmed against JupitERP's own extracted-Bootstrap `framework.css`, whose `--bs-modal-width` for `.modal-lg` is `800px`) — a real mismatch given this module's own stated goal of being "drop-in `bootstrap.Modal`-compatible". Fixed to `800px`; **visible behavior change** for any host page's `.modal-lg` instances (100px narrower) — 36 files in JupitERP alone use `.modal-lg`. Also removed the invented `min-width: min(94vw, 700px)` floor on `.modal-lg` (not a real Bootstrap convention). Added `.modal-sm` (`300px` fallback, matching Bootstrap) — previously entirely absent from `uikit.css`, silently relying on whatever host CSS happened to still define it.

## [0.9.0] - 2026-08-20

### Summary

| Category | Description |
|----------|--------------|
| Added    | Modal/Offcanvas: Tab/Shift+Tab focus trap while open |
| Added    | Offcanvas: auto-focus on open |
| Added    | Dropdown: viewport-edge clamping (auto right-align / flip upward) |
| Added    | `prefers-reduced-motion` support across every animated component |
| Added    | Tabs: roving tabindex + Left/Right/Home/End (Up/Down for vertical) keyboard navigation |

### Added
- `js/ui-modal.js` / `js/ui-offcanvas.js` — Tab/Shift+Tab is now trapped within the panel while open (`trapFocus()`), matching `bootstrap.Modal`'s and `bootstrap.Offcanvas`'s own focus-trap behavior. The native `<dialog>`-based `ui-dialog.js` already got this for free from the browser via `showModal()`; these two build their own panel on a plain element, so they didn't have it until now. Verified live: focusing the last focusable element and dispatching Tab wraps focus back to the first (and Shift+Tab from the first wraps to the last).
- `js/ui-offcanvas.js` — the panel itself is now focused once fully shown (requires `tabindex="-1"` on the panel, same markup requirement `bootstrap.Offcanvas` already has), matching Bootstrap's own behavior; previously nothing was auto-focused on open.
- `js/ui-dropdown.js` — `adjustPosition()` now keeps an opened menu from rendering off the right or bottom edge of the viewport: adds `dropdown-menu-end` itself when it would overflow the right edge (tracked via `data-uik-auto-end` so an explicitly-set `dropdown-menu-end` in the markup is never touched or removed), and a new `uik-dropdown-menu--dropup` class (`css/uikit.css`) when it would overflow the bottom edge, flipping it to open upward. Still no Popper-style collision detection/auto-flip-to-opposite-side — this only prevents rendering off-screen, same simplification already documented, and the same "just stay on screen" approach already shipped for `ui-popover.js`/`ui-tooltip.js` in 0.8.2. Verified live: a dropdown forced near the bottom-right corner of the viewport gets both classes and renders fully on-screen.
- `css/uikit.css` — a single `prefers-reduced-motion: reduce` media query now shortens every animation/transition across the toast, dialog, tooltip, collapse, tabs, offcanvas, and modal/backdrop to a near-zero (not literal 0, so `animationend`/`transitionend` still fire) duration. The JS-driven completion timers in `ui-collapse.js`/`ui-offcanvas.js`/`ui-modal.js` (`TRANSITION_MS`) now check the same media query at runtime and drop to 0 too, so state no longer finalizes ~300ms after an animation the user never actually saw.
- `js/ui-tabs.js` — full roving-tabindex + arrow-key navigation, per the ARIA APG Tabs Pattern and matching `bootstrap.Tab`'s own keyboard behavior: only the active tab in a tablist is `tabindex="0"` (Tab-reachable), every other tab is `tabindex="-1"`; Left/Right (or Up/Down when the tablist has `aria-orientation="vertical"`) and Home/End move focus to and activate the target tab. A one-time init pass on script load sets the initial tabindex state for every existing tab (tabs aren't added dynamically anywhere in the host codebase, so no re-init hook was needed). This closes the "known simplification" stated since [0.6.0]. Verified live: arrow-right from the initially-active tab moves focus, activates the target tab and pane, and swaps the tabindex values correctly.

## [0.8.2] - 2026-08-20

### Summary

| Category | Description |
|----------|--------------|
| Fixed    | Popover/tooltip could render partly off-screen near a viewport edge |

### Fixed
- `js/ui-popover.js` / `js/ui-tooltip.js` — `position()` computed the panel's `left`/`top` purely from the trigger element's own rect (centered on it, offset by placement), with no clamping to the viewport bounds. A trigger close enough to an edge relative to the panel's own rendered width/height could push it partly or fully off-screen — reproduced with `0_test/test.html`'s popover demo, which rendered 20px past the left edge of a 1905px-wide window. Fixed with a shared `clampToViewport()` helper (`EDGE_GAP` = 8px) added to both files, applied after the placement-based position is computed — the panel still follows its trigger dynamically in the normal case; the clamp only nudges it when the computed position would otherwise render outside the viewport, matching the same "no Popper-style auto-flip, just stay on-screen" simplification already documented for `ui-dropdown.js`. Verified live: moving the trigger to different positions confirms the panel keeps following it (not pinned to a fixed edge), and only the near-edge case gets clamped.
- `0_test/test.html` — added demo-only box styling (background/border/shadow) for `.popover`/`.popover-header`/`.popover-body` and hover/active styling for `.dropdown-item`, matching the same pattern already used for `.btn-row button:hover`. `css/uikit.css` deliberately ships no visual styling for either (see its own comments above `.dropdown-menu`/`.popover`) — real host pages get it from their own still-loaded CSS, but this standalone harness intentionally loads nothing else, so the demo needs its own. Not a UiKit component change — this was misread as a possible component bug, investigated, and confirmed to be a harness-only gap (verified against JupitERP's live `framework.css`).

## [0.8.1] - 2026-08-20

### Summary

| Category | Description |
|----------|--------------|
| Fixed    | Toast could leak in the DOM forever if its dismiss animation never fired |
| Fixed    | Collapse: a hide() call during a show() transition was silently dropped |
| Fixed    | Offcanvas: could not reopen a panel while it was still closing |
| Fixed    | Dialog: a second confirm() call while one was open threw instead of reconfiguring |
| Fixed    | Offcanvas: an `href="#"` trigger threw instead of failing gracefully |
| Fixed    | Popover/Collapse: some public API calls threw on a null/undefined element argument |

### Fixed
- `js/ui-toast.js` — `dismiss()` relied solely on the `animationend` event to remove the toast element; if the slide-out animation never fires (host CSS disables/overrides animations, an ancestor goes `display:none` mid-transition), the element leaked in the DOM indefinitely, since `.uik-toast--hide` has no static (non-animated) hidden state. Added a 400ms fallback `setTimeout` that performs the same cleanup if `animationend` doesn't fire first. Verified live: with animations disabled via an injected stylesheet, the toast is still present at 100ms and correctly removed by 500ms.
- `js/ui-collapse.js` — `show()`/`hide()`/`toggle()` did not guard against being re-invoked while a previous transition was still in flight. `isShown()` only reflects the settled `.show` class, not the transitional `.collapsing` state, so calling `hide()` while a `show()`'s 350ms timer was still pending returned early and silently dropped the hide request; conversely, overlapping un-cancelled `setTimeout` callbacks from two interleaved calls could finalize the wrong class state and double-fire `show.bs.collapse`/`hide.bs.collapse`. Fixed with a per-element pending-timer registry (`WeakMap`) cleared at the start of every `show()`/`hide()` call, and guards that now also recognize the `.collapsing` transitional state so an interrupting call takes over instead of no-op'ing or being dropped. Verified live: `show()` then `hide()` mid-transition now ends in the correctly closed state.
- `js/ui-offcanvas.js` — the same overlapping-timer issue as collapse, plus a stricter symptom: `hide()`'s guard didn't exclude the `.showing` transitional state, and `show()`'s guard still saw the stale `.show` class during a `hide()`'s `.hiding` window, so a panel could not be reopened while it was still closing. Fixed with the same per-element pending-timer pattern as `ui-collapse.js`. Verified live: `show()` → `hide()` → `show()` in rapid succession now ends in the correctly open state.
- `js/ui-dialog.js` — `confirmDialog()` called `showModal()` unconditionally; per the HTML spec this throws `InvalidStateError` if the `<dialog>` already has the `open` attribute, so a second `UiKit.confirm()` call while one was still showing threw instead of reconfiguring in place as the file's own docblock promised. Fixed by closing the dialog first when `dialogEl.open` is true. Verified live: two back-to-back `confirm()` calls no longer throw, and the second call's message replaces the first's.
- `js/ui-offcanvas.js` — the click handler's `document.querySelector(sel)` (deriving the panel from `data-bs-target`/`href`) was not wrapped in `try`/`catch`, unlike the identical pattern already guarded in `ui-collapse.js`/`ui-tabs.js`/`ui-modal.js`. An `href="#"` trigger — a common markup convention, and exactly what the sibling components' guard exists for — is an invalid CSS selector and threw a `SyntaxError`. Fixed by wrapping the lookup in `try`/`catch`, matching the sibling components. Verified live: an `href="#"` trigger no longer throws.
- `js/ui-popover.js` / `js/ui-collapse.js` — `UiKit.popover.show()`/`.toggle()`/`.isShown()` and `UiKit.collapse.toggle()` did not guard against a `null`/`undefined` element argument, unlike `UiKit.collapse.show()`/`.hide()`, `UiKit.offcanvas.show()`/`.hide()`, and `UiKit.tab.show()`, which already do. A caller passing a mistyped/missing element id (e.g. `document.getElementById()` returning `null`) hit an uncaught `TypeError` instead of a predictable no-op. Fixed by adding the same `if (!el) return;` guard consistently across all exported entry points. Verified live: all six now no-op cleanly on `null`.

## [0.8.0] - 2026-08-19

### Summary

| Category | Description |
|----------|--------------|
| Added    | `UiKit.Modal` — drop-in `bootstrap.Modal`-compatible class |
| Fixed    | Popover `data-bs-content` no longer renders as HTML by default (XSS hardening) |
| Fixed    | Popover now supports `data-bs-trigger="focus"` — the field-help-icon popover was silently non-functional since the Bootstrap migration |

### Fixed
- `js/ui-popover.js` — `data-bs-content` is now rendered as plain text (`textContent`) by default; HTML rendering requires the trigger to also carry `data-bs-html="true"` (Bootstrap Popover's own opt-in convention), matching the same explicit-opt-in pattern as `ui-dialog.js`'s `allowHtml`. Previously the component rendered this attribute as HTML unconditionally with no plain-text mode — a caller populating it from unescaped user-controlled data would have been a direct DOM XSS sink. The one real HTML-content caller in the host codebase (`vat_validation_js.php`) already set `data-bs-trigger="manual"` together with `data-bs-html="true"`, so it is unaffected.
- `js/ui-popover.js` — added self-initializing delegated `focusin`/`focusout` handling for `data-bs-trigger="focus"` (same pattern as `ui-tooltip.js`). A second real caller (`renderFieldHelpIcon()` in `functions.php`, rendering field-level "?" help-icon buttons) uses this trigger mode; the component previously only supported the programmatic API (matching the one *other* known caller, which manages show/hide itself), so this caller's popover never actually opened since the Bootstrap → UiKit migration. The previous 0.7.0 changelog entry incorrectly described this caller as removed dead code — it was live, just non-functional.

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
