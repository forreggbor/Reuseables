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
 */
(function (global) {
    'use strict';

    var TRANSITION_MS = 300;
    var backdropEl = null;
    var openPanel = null;

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
        if (!panel || panel.classList.contains('show') || panel.classList.contains('showing')) {
            return;
        }
        fire(panel, 'show.bs.offcanvas');
        openPanel = panel;
        document.body.appendChild(ensureBackdrop());
        document.body.style.overflow = 'hidden';
        panel.setAttribute('aria-modal', 'true');
        panel.setAttribute('role', 'dialog');
        panel.classList.add('showing');
        window.setTimeout(function () {
            panel.classList.remove('showing');
            panel.classList.add('show');
            fire(panel, 'shown.bs.offcanvas');
        }, TRANSITION_MS);
    }

    function hide(panel) {
        if (!panel || (!panel.classList.contains('show') && !panel.classList.contains('showing'))) {
            return;
        }
        fire(panel, 'hide.bs.offcanvas');
        panel.classList.add('hiding');
        if (backdropEl && backdropEl.parentNode) {
            backdropEl.parentNode.removeChild(backdropEl);
        }
        window.setTimeout(function () {
            panel.classList.remove('show', 'showing', 'hiding');
            panel.removeAttribute('aria-modal');
            panel.removeAttribute('role');
            document.body.style.overflow = '';
            if (openPanel === panel) {
                openPanel = null;
            }
            fire(panel, 'hidden.bs.offcanvas');
        }, TRANSITION_MS);
    }

    document.addEventListener('click', function (e) {
        if (bootstrapOffcanvasAvailable()) {
            return;
        }
        var toggle = e.target.closest('[data-bs-toggle="offcanvas"]');
        if (toggle) {
            var sel = toggle.getAttribute('data-bs-target') || toggle.getAttribute('href');
            var panel = sel ? document.querySelector(sel) : null;
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
        if (bootstrapOffcanvasAvailable()) {
            return;
        }
        if (e.key === 'Escape' && openPanel) {
            hide(openPanel);
        }
    });

    global.UiKit = global.UiKit || {};
    global.UiKit.offcanvas = { show: show, hide: hide };
})(window);
