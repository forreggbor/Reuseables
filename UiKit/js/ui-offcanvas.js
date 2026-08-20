/**
 * UiKit offcanvas.
 *
 * Self-initializing delegated click handling for `data-bs-toggle="offcanvas"`
 * (open) and `data-bs-dismiss="offcanvas"` (close), reusing the existing
 * `.offcanvas`/`.showing`/`.show`/`.hiding` class-name convention and DOM
 * structure (`.offcanvas-header`/`.offcanvas-title`/`.offcanvas-body`) so
 * Bootstrap's own CSS (still loaded) continues to style it — same rationale
 * as the other UiKit components, see ui-collapse.js's docblock in full.
 *
 * Adds a single shared `.offcanvas-backdrop` element (click closes),
 * Escape-to-close, and locks page scroll while open via a plain
 * `overflow: hidden` on `<body>` — Bootstrap's own scrollbar-width
 * compensation is not replicated (a cosmetic simplification: the page can
 * shift by a scrollbar's width while an offcanvas is open, on desktop
 * browsers with a visible scrollbar; both real callers are mobile-only
 * (`.d-lg-none`), where this practically never applies).
 *
 * Coexistence with Bootstrap JS: see ui-collapse.js's docblock — Bootstrap's
 * own Offcanvas also self-initializes a capture-phase document click
 * listener, so this component steps aside while Bootstrap is present.
 *
 * Focuses the panel itself once shown (requires `tabindex="-1"` on the
 * panel, same markup requirement as Bootstrap's own Offcanvas) and traps
 * Tab/Shift+Tab within it while open, matching `bootstrap.Offcanvas`'s own
 * (backdrop:true default) focus-trap behavior — see ui-modal.js's docblock
 * for the same rationale.
 */
(function (global) {
    'use strict';

    // Matches the `prefers-reduced-motion` handling in css/uikit.css, which
    // shortens the actual CSS transition to near-zero — keep this JS-driven
    // completion timer in step so state doesn't finalize ~300ms after the
    // (invisible) transition already ended.
    var TRANSITION_MS = window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : 300;
    var backdropEl = null;
    var openPanel = null;
    var pendingTimers = new WeakMap();

    function clearPending(panel) {
        var id = pendingTimers.get(panel);
        if (id) {
            window.clearTimeout(id);
            pendingTimers.delete(panel);
        }
    }

    var FOCUSABLE_SELECTOR = 'a[href], button:not([disabled]), textarea:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), [tabindex]:not([tabindex="-1"])';

    function getFocusableElements(container) {
        return Array.prototype.filter.call(container.querySelectorAll(FOCUSABLE_SELECTOR), function (el) {
            return el.offsetWidth > 0 || el.offsetHeight > 0 || el === document.activeElement;
        });
    }

    // Bootstrap's own Offcanvas (with backdrop:true, the default) traps
    // focus while open, same rationale/implementation as ui-modal.js.
    function trapFocus(container, e) {
        var focusable = getFocusableElements(container);
        if (!focusable.length) {
            e.preventDefault();
            return;
        }
        var first = focusable[0];
        var last = focusable[focusable.length - 1];
        var active = document.activeElement;
        if (e.shiftKey) {
            if (active === first || active === container) {
                e.preventDefault();
                last.focus();
            }
        } else if (active === last || active === container) {
            e.preventDefault();
            first.focus();
        }
    }

    function focusPanel(panel) {
        if (!panel.contains(document.activeElement)) {
            panel.focus();
        }
    }

    function bootstrapOffcanvasAvailable() {
        return typeof global.bootstrap !== 'undefined' && !!global.bootstrap.Offcanvas;
    }

    function fire(el, name) {
        el.dispatchEvent(new CustomEvent(name, { bubbles: true }));
    }

    function ensureBackdrop() {
        if (backdropEl) {
            return backdropEl;
        }
        backdropEl = document.createElement('div');
        backdropEl.className = 'offcanvas-backdrop';
        backdropEl.addEventListener('click', function () {
            if (openPanel) {
                hide(openPanel);
            }
        });
        return backdropEl;
    }

    function show(panel) {
        if (!panel || (panel.classList.contains('show') && !panel.classList.contains('hiding')) || panel.classList.contains('showing')) {
            return;
        }
        clearPending(panel);
        panel.classList.remove('hiding');
        fire(panel, 'show.bs.offcanvas');
        openPanel = panel;
        document.body.appendChild(ensureBackdrop());
        document.body.style.overflow = 'hidden';
        panel.setAttribute('aria-modal', 'true');
        panel.setAttribute('role', 'dialog');
        panel.classList.add('showing');
        var timerId = window.setTimeout(function () {
            pendingTimers.delete(panel);
            panel.classList.remove('showing');
            panel.classList.add('show');
            focusPanel(panel);
            fire(panel, 'shown.bs.offcanvas');
        }, TRANSITION_MS);
        pendingTimers.set(panel, timerId);
    }

    function hide(panel) {
        if (!panel || (!panel.classList.contains('show') && !panel.classList.contains('showing'))) {
            return;
        }
        clearPending(panel);
        fire(panel, 'hide.bs.offcanvas');
        panel.classList.remove('showing');
        panel.classList.add('hiding');
        if (backdropEl && backdropEl.parentNode) {
            backdropEl.parentNode.removeChild(backdropEl);
        }
        var timerId = window.setTimeout(function () {
            pendingTimers.delete(panel);
            panel.classList.remove('show', 'showing', 'hiding');
            panel.removeAttribute('aria-modal');
            panel.removeAttribute('role');
            document.body.style.overflow = '';
            if (openPanel === panel) {
                openPanel = null;
            }
            fire(panel, 'hidden.bs.offcanvas');
        }, TRANSITION_MS);
        pendingTimers.set(panel, timerId);
    }

    document.addEventListener('click', function (e) {
        if (bootstrapOffcanvasAvailable()) {
            return;
        }
        var toggle = e.target.closest('[data-bs-toggle="offcanvas"]');
        if (toggle) {
            var sel = toggle.getAttribute('data-bs-target') || toggle.getAttribute('href');
            var panel = null;
            if (sel) {
                try {
                    panel = document.querySelector(sel);
                } catch (err) {
                    panel = null;
                }
            }
            if (panel) {
                e.preventDefault();
                show(panel);
            }
            return;
        }
        var dismiss = e.target.closest('[data-bs-dismiss="offcanvas"]');
        if (dismiss) {
            var openEl = dismiss.closest('.offcanvas');
            if (openEl) {
                hide(openEl);
            }
        }
    }, true);

    document.addEventListener('keydown', function (e) {
        if (bootstrapOffcanvasAvailable() || !openPanel) {
            return;
        }
        if (e.key === 'Tab') {
            trapFocus(openPanel, e);
            return;
        }
        if (e.key === 'Escape') {
            hide(openPanel);
        }
    });

    global.UiKit = global.UiKit || {};
    global.UiKit.offcanvas = { show: show, hide: hide };
})(window);
