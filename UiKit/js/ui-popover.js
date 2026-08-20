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
 *   `title` attribute            → `.popover-header` (plain text)
 *   `data-bs-content` attribute  → `.popover-body` (HTML — the only actual
 *                                   caller in the host codebase always sets
 *                                   HTML content, so there is no plain-text
 *                                   mode here, unlike ui-tooltip.js)
 *   `data-bs-placement`          → top/bottom/left/right, default top
 *
 * Only the programmatic API is provided — no click/focus/hover
 * auto-triggering. The one real, currently-working caller in the host
 * codebase (`vat_validation_js.php`) already used `data-bs-trigger="manual"`
 * (i.e. Bootstrap Popover with all auto-triggering turned off) and drove
 * show/hide entirely from its own click handler, so this covers it exactly;
 * a second, `trigger:"focus"`-configured caller (`admin/products/edit.php`)
 * turned out to have no matching trigger markup anywhere on that page at
 * all — dead code, removed rather than ported.
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
        popoverEl.style.top = Math.round(top + window.scrollY) + 'px';
        popoverEl.style.left = Math.round(left + window.scrollX) + 'px';
    }

    function show(trigger) {
        ensurePopoverEl();
        if (currentTrigger && currentTrigger !== trigger) {
            hide(currentTrigger);
        }
        var titleText = trigger.getAttribute('title') || trigger.getAttribute('data-bs-original-title') || '';
        var content = trigger.getAttribute('data-bs-content') || '';
        var placement = trigger.getAttribute('data-bs-placement') || 'top';

        innerHeader.textContent = titleText;
        innerHeader.style.display = titleText ? '' : 'none';
        innerBody.innerHTML = content;

        popoverEl.className = 'popover bs-popover-auto uik-popover--' + placement;
        popoverEl.style.display = 'block';
        currentTrigger = trigger;
        trigger.setAttribute('aria-describedby', 'uik-popover');
        popoverEl.id = 'uik-popover';
        position(trigger, placement);
    }

    function hide(trigger) {
        if (currentTrigger !== trigger) {
            return;
        }
        currentTrigger = null;
        if (popoverEl) {
            popoverEl.style.display = 'none';
        }
        trigger.removeAttribute('aria-describedby');
    }

    function toggle(trigger) {
        if (currentTrigger === trigger) {
            hide(trigger);
        } else {
            show(trigger);
        }
    }

    function isShown(trigger) {
        return currentTrigger === trigger;
    }

    global.UiKit = global.UiKit || {};
    global.UiKit.popover = { show: show, hide: hide, toggle: toggle, isShown: isShown };
})(window);
