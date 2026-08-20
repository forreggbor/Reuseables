/**
 * UiKit dropdown.
 *
 * Self-initializing, single delegated `click` listener — no per-element
 * setup. Recognizes the existing `data-bs-toggle="dropdown"` markup
 * convention (toggle element followed by a sibling `.dropdown-menu`, or one
 * found within the same `.dropdown` wrapper) for the same reason documented
 * in ui-collapse.js: reusing the convention ~18 existing trigger elements
 * already use is far less risky than rewriting their markup.
 *
 * Auto-close behavior matches Bootstrap's default (`data-bs-auto-close`
 * is "true" — the only value used anywhere in the host codebase): any click
 * that isn't on a toggle closes every open menu, including a click on an
 * item inside the open menu itself. Escape closes every open menu too.
 *
 * Positioning is deliberately simple — no Popper-style collision detection
 * or auto-flip-to-the-opposite-side. `.dropdown-menu` opens directly below
 * its toggle via ordinary `position: absolute` CSS (see uikit.css) in the
 * common case; `.dropdown-menu-end` right-aligns it, `data-bs-toggle`d
 * explicitly by the host or added automatically below. This is a smaller
 * feature set than Bootstrap's Popper-backed positioning, noted here rather
 * than silently matched. `adjustPosition()` still keeps the menu from
 * actually rendering off-screen near a viewport edge: after opening, it
 * measures the menu's rect and, if it would overflow the right edge, adds
 * `dropdown-menu-end` itself (removed again on close, tracked via
 * `data-uik-auto-end` so an explicitly-set `dropdown-menu-end` in the
 * markup is never touched); if it would overflow the bottom edge, it adds
 * `uik-dropdown-menu--dropup` (flips to open upward — see uikit.css).
 *
 * Coexistence with Bootstrap JS: see ui-collapse.js's docblock for the full
 * rationale — Bootstrap's own Dropdown also self-initializes a capture-phase
 * document click listener with no explicit instantiation needed, so this
 * component steps aside whenever Bootstrap is present and takes over
 * automatically once it's removed.
 */
(function (global) {
    'use strict';

    function bootstrapDropdownAvailable() {
        return typeof global.bootstrap !== 'undefined' && !!global.bootstrap.Dropdown;
    }

    function findMenu(toggle) {
        var next = toggle.nextElementSibling;
        if (next && next.classList.contains('dropdown-menu')) {
            return next;
        }
        return toggle.parentElement ? toggle.parentElement.querySelector('.dropdown-menu') : null;
    }

    function findToggleForMenu(menu) {
        var prev = menu.previousElementSibling;
        if (prev && prev.hasAttribute('data-bs-toggle')) {
            return prev;
        }
        return menu.parentElement ? menu.parentElement.querySelector('[data-bs-toggle="dropdown"]') : null;
    }

    function closeAll() {
        document.querySelectorAll('.dropdown-menu.show').forEach(function (menu) {
            menu.classList.remove('show');
            resetPosition(menu);
            var toggle = findToggleForMenu(menu);
            if (toggle) {
                toggle.setAttribute('aria-expanded', 'false');
            }
        });
    }

    var EDGE_GAP = 8;

    function resetPosition(menu) {
        if (menu.getAttribute('data-uik-auto-end') === 'true') {
            menu.classList.remove('dropdown-menu-end');
            menu.removeAttribute('data-uik-auto-end');
        }
        menu.classList.remove('uik-dropdown-menu--dropup');
    }

    // Plain-CSS positioning (`top: 100%; left: 0`) covers the common case —
    // this only steps in to keep the menu from rendering off-screen near a
    // viewport edge. No Popper-style auto-flip-to-opposite-side, just "stay
    // on screen" (same simplification already documented above, and the
    // same approach already used in ui-popover.js/ui-tooltip.js).
    function adjustPosition(menu) {
        resetPosition(menu);
        var rect = menu.getBoundingClientRect();
        if (rect.right > window.innerWidth - EDGE_GAP) {
            menu.classList.add('dropdown-menu-end');
            menu.setAttribute('data-uik-auto-end', 'true');
        }
        rect = menu.getBoundingClientRect();
        if (rect.bottom > window.innerHeight - EDGE_GAP) {
            menu.classList.add('uik-dropdown-menu--dropup');
        }
    }

    document.addEventListener('click', function (e) {
        if (bootstrapDropdownAvailable()) {
            return;
        }
        var toggle = e.target.closest('[data-bs-toggle="dropdown"]');
        if (toggle) {
            e.preventDefault();
            var menu = findMenu(toggle);
            if (!menu) {
                return;
            }
            var wasOpen = menu.classList.contains('show');
            closeAll();
            if (!wasOpen) {
                menu.classList.add('show');
                toggle.setAttribute('aria-expanded', 'true');
                adjustPosition(menu);
            }
            return;
        }
        closeAll();
    }, true);

    document.addEventListener('keydown', function (e) {
        if (bootstrapDropdownAvailable()) {
            return;
        }
        if (e.key === 'Escape') {
            closeAll();
        }
    });

    global.UiKit = global.UiKit || {};
})(window);
