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
 * or auto-flip-when-near-viewport-edge. `.dropdown-menu` opens directly
 * below its toggle via ordinary `position: absolute` CSS (see uikit.css);
 * `.dropdown-menu-end` right-aligns it. This is a smaller feature set than
 * Bootstrap's Popper-backed positioning, noted here rather than silently
 * matched, since it could show a menu running off-screen near a viewport
 * edge where Bootstrap would have flipped it.
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
            var toggle = findToggleForMenu(menu);
            if (toggle) {
                toggle.setAttribute('aria-expanded', 'false');
            }
        });
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
