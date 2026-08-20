/**
 * UiKit tabs.
 *
 * Self-initializing delegated `click` listener for `data-bs-toggle="tab"`
 * (and the `"pill"`/`"list"` variants, Bootstrap Tab's own equivalents) —
 * same compatibility rationale as ui-collapse.js/ui-dropdown.js: reuses the
 * existing attribute + `.nav-link`/`.tab-pane`/`.active`/`.show`/`.fade`
 * class-name convention rather than a new one.
 *
 * `UiKit.tab.show(tabEl)` is also exposed directly for programmatic
 * activation (e.g. restoring the active tab from a URL hash on page load).
 * Fires `hide.bs.tab`/`show.bs.tab`/`hidden.bs.tab`/`shown.bs.tab` on the
 * relevant tab elements, matching Bootstrap Tab's own event names/targets.
 *
 * Known simplification, stated up front: no arrow-key navigation between
 * tabs (Bootstrap's own roving-tabindex keyboard behavior). Activation by
 * click or by calling `.show()` directly both work fully.
 *
 * Coexistence with Bootstrap JS: see ui-collapse.js's docblock — Bootstrap's
 * own Tab also self-initializes a capture-phase document click listener, so
 * this component steps aside while Bootstrap is present.
 */
(function (global) {
    'use strict';

    function bootstrapTabAvailable() {
        return typeof global.bootstrap !== 'undefined' && !!global.bootstrap.Tab;
    }

    function findPane(tab) {
        var sel = tab.getAttribute('data-bs-target') || tab.getAttribute('href');
        if (!sel) {
            return null;
        }
        try {
            return document.querySelector(sel);
        } catch (e) {
            return null;
        }
    }

    function fire(el, name) {
        el.dispatchEvent(new CustomEvent(name, { bubbles: true }));
    }

    function isTabToggle(el) {
        var toggle = el.getAttribute('data-bs-toggle');
        return toggle === 'tab' || toggle === 'pill' || toggle === 'list';
    }

    function show(tab) {
        if (!tab || tab.classList.contains('active')) {
            return;
        }
        var pane = findPane(tab);
        if (!pane) {
            return;
        }
        var tablist = tab.closest('[role="tablist"]') || tab.parentElement;
        var tabContent = pane.parentElement;

        var prevTab = tablist ? tablist.querySelector('.active[data-bs-toggle="tab"], .active[data-bs-toggle="pill"], .active[data-bs-toggle="list"]') : null;
        var prevPane = null;
        if (tabContent) {
            for (var i = 0; i < tabContent.children.length; i++) {
                if (tabContent.children[i].classList.contains('active')) {
                    prevPane = tabContent.children[i];
                    break;
                }
            }
        }

        if (prevTab) {
            fire(prevTab, 'hide.bs.tab');
            prevTab.classList.remove('active');
            prevTab.setAttribute('aria-selected', 'false');
        }
        if (prevPane) {
            prevPane.classList.remove('show', 'active');
        }

        fire(tab, 'show.bs.tab');
        tab.classList.add('active');
        tab.setAttribute('aria-selected', 'true');
        pane.classList.add('active');
        pane.getBoundingClientRect(); // force reflow so the fade transition animates
        pane.classList.add('show');

        fire(tab, 'shown.bs.tab');
        if (prevTab) {
            fire(prevTab, 'hidden.bs.tab');
        }
    }

    document.addEventListener('click', function (e) {
        if (bootstrapTabAvailable()) {
            return;
        }
        var tab = e.target.closest('[data-bs-toggle="tab"], [data-bs-toggle="pill"], [data-bs-toggle="list"]');
        if (!tab || !isTabToggle(tab)) {
            return;
        }
        e.preventDefault();
        show(tab);
    }, true);

    global.UiKit = global.UiKit || {};
    global.UiKit.tab = { show: show };
})(window);
