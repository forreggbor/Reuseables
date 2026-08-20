/**
 * UiKit popover.
 *
 * `UiKit.popover.show(el)` / `.hide(el)` / `.toggle(el)` — a single shared
 * floating panel (like ui-tooltip.js) built with the same DOM structure
 * Bootstrap Popover uses (`.popover` > `.popover-arrow` + `.popover-header`
 * + `.popover-body`), so Bootstrap's own CSS (still loaded — removed in a
 * later step of the host project's Bootstrap-removal work) continues to
 * style it with zero visual change, exactly like ui-dropdown.js/ui-tabs.js.
 *
 * Content source, read fresh on every `show()` call (so updating
 * `data-bs-content` between calls — e.g. a "loading…" placeholder replaced
 * once an AJAX request resolves — works without any extra API):
 *   `title` attribute            → `.popover-header` (plain text, always)
 *   `data-bs-content` attribute  → `.popover-body` — plain text by default
 *                                   (`textContent`); rendered as HTML only
 *                                   when the trigger also carries
 *                                   `data-bs-html="true"` (Bootstrap
 *                                   Popover's own opt-in convention).
 *                                   Security: without this gate, a caller
 *                                   populating `data-bs-content` from
 *                                   unescaped user-controlled data would be
 *                                   a direct DOM XSS sink — same rationale
 *                                   as `allowHtml` on ui-dialog.js, the
 *                                   caller must explicitly opt in to HTML
 *                                   mode and stays responsible for only
 *                                   passing trusted markup once it does.
 *   `data-bs-placement`          → top/bottom/left/right, default top
 *
 * Two trigger modes are supported, matching each real caller in the host
 * codebase exactly:
 *   `data-bs-trigger="manual"` — no auto-triggering; the caller drives
 *     show/hide/toggle itself (`vat_validation_js.php`'s own click handler).
 *   `data-bs-trigger="focus"`  — self-initializing delegated `focusin`/
 *     `focusout` listener (same pattern as ui-tooltip.js), opens on focus
 *     and closes on blur (`renderFieldHelpIcon()` in `functions.php`,
 *     rendering the field-level "?" help-icon buttons).
 * No click/hover auto-triggering is implemented — neither real caller needs
 * it.
 *
 * Positioning is plain CSS-rect math, clamped to stay fully within the
 * viewport — no Popper-style auto-flip to the opposite side near an edge,
 * just prevented from rendering off-screen (matches the same simplification
 * documented in ui-dropdown.js).
 */
(function (global) {
    'use strict';

    var popoverEl = null;
    var innerHeader = null;
    var innerBody = null;
    var currentTrigger = null;

    function ensurePopoverEl() {
        if (popoverEl) {
            return popoverEl;
        }
        popoverEl = document.createElement('div');
        popoverEl.className = 'popover';
        popoverEl.setAttribute('role', 'tooltip');
        popoverEl.innerHTML = '<div class="popover-arrow"></div><h3 class="popover-header"></h3><div class="popover-body"></div>';
        innerHeader = popoverEl.querySelector('.popover-header');
        innerBody = popoverEl.querySelector('.popover-body');
        document.body.appendChild(popoverEl);
        document.addEventListener('click', function (e) {
            if (currentTrigger && !popoverEl.contains(e.target) && e.target !== currentTrigger && !currentTrigger.contains(e.target)) {
                hide(currentTrigger);
            }
        });
        return popoverEl;
    }

    var EDGE_GAP = 8; // minimum distance kept from the viewport edge

    function clampToViewport(value, size, viewportSize) {
        var max = viewportSize - size - EDGE_GAP;
        if (max < EDGE_GAP) {
            // Content wider/taller than the viewport itself — pin to the
            // start edge rather than producing a negative max.
            return EDGE_GAP;
        }
        return Math.min(Math.max(value, EDGE_GAP), max);
    }

    function position(trigger, placement) {
        var triggerRect = trigger.getBoundingClientRect();
        var popRect = popoverEl.getBoundingClientRect();
        var gap = 8;
        var top, left;
        switch (placement) {
            case 'bottom':
                top = triggerRect.bottom + gap;
                left = triggerRect.left + triggerRect.width / 2 - popRect.width / 2;
                break;
            case 'left':
                top = triggerRect.top + triggerRect.height / 2 - popRect.height / 2;
                left = triggerRect.left - popRect.width - gap;
                break;
            case 'right':
                top = triggerRect.top + triggerRect.height / 2 - popRect.height / 2;
                left = triggerRect.right + gap;
                break;
            default:
                top = triggerRect.top - popRect.height - gap;
                left = triggerRect.left + triggerRect.width / 2 - popRect.width / 2;
        }
        // Keep the panel fully on-screen — no Popper-style flip/collision
        // detection (see the module's own "known simplification" elsewhere
        // in this codebase), just prevent it from rendering off the edge.
        left = clampToViewport(left, popRect.width, window.innerWidth);
        top = clampToViewport(top, popRect.height, window.innerHeight);
        popoverEl.style.top = Math.round(top + window.scrollY) + 'px';
        popoverEl.style.left = Math.round(left + window.scrollX) + 'px';
    }

    function show(trigger) {
        if (!trigger) {
            return;
        }
        ensurePopoverEl();
        if (currentTrigger && currentTrigger !== trigger) {
            hide(currentTrigger);
        }
        var titleText = trigger.getAttribute('title') || trigger.getAttribute('data-bs-original-title') || '';
        var content = trigger.getAttribute('data-bs-content') || '';
        var placement = trigger.getAttribute('data-bs-placement') || 'top';

        innerHeader.textContent = titleText;
        innerHeader.style.display = titleText ? '' : 'none';
        if (trigger.getAttribute('data-bs-html') === 'true') {
            innerBody.innerHTML = content;
        } else {
            innerBody.textContent = content;
        }

        popoverEl.className = 'popover bs-popover-auto uik-popover--' + placement;
        popoverEl.style.display = 'block';
        currentTrigger = trigger;
        trigger.setAttribute('aria-describedby', 'uik-popover');
        popoverEl.id = 'uik-popover';
        position(trigger, placement);
    }

    function hide(trigger) {
        if (!trigger || currentTrigger !== trigger) {
            return;
        }
        currentTrigger = null;
        if (popoverEl) {
            popoverEl.style.display = 'none';
        }
        trigger.removeAttribute('aria-describedby');
    }

    function toggle(trigger) {
        if (!trigger) {
            return;
        }
        if (currentTrigger === trigger) {
            hide(trigger);
        } else {
            show(trigger);
        }
    }

    function isShown(trigger) {
        return !!trigger && currentTrigger === trigger;
    }

    function findFocusTrigger(el) {
        return el && el.closest ? el.closest('[data-bs-toggle="popover"][data-bs-trigger="focus"]') : null;
    }

    document.addEventListener('focusin', function (e) {
        var trigger = findFocusTrigger(e.target);
        if (trigger) {
            show(trigger);
        }
    });
    document.addEventListener('focusout', function (e) {
        var trigger = findFocusTrigger(e.target);
        if (trigger) {
            hide(trigger);
        }
    });

    global.UiKit = global.UiKit || {};
    global.UiKit.popover = { show: show, hide: hide, toggle: toggle, isShown: isShown };
})(window);
