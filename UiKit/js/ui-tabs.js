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
 * Roving tabindex + arrow-key navigation, per the ARIA APG Tabs Pattern and
 * matching Bootstrap Tab's own keyboard behavior: only the active tab is
 * Tab-reachable (`tabindex="0"`, every other tab in the same tablist gets
 * `tabindex="-1"`); Left/Right (or Up/Down when the tablist has
 * `aria-orientation="vertical"`) and Home/End move focus AND activate the
 * target tab, matching Bootstrap Tab's automatic-activation model.
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
            prevTab.setAttribute('tabindex', '-1');
        }
        if (prevPane) {
            prevPane.classList.remove('show', 'active');
        }

        fire(tab, 'show.bs.tab');
        tab.classList.add('active');
        tab.setAttribute('aria-selected', 'true');
        tab.setAttribute('tabindex', '0');
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

    var TAB_SELECTOR = '[data-bs-toggle="tab"], [data-bs-toggle="pill"], [data-bs-toggle="list"]';

    function getTabsInList(tablist) {
        return Array.prototype.slice.call(tablist.querySelectorAll(TAB_SELECTOR));
    }

    document.addEventListener('keydown', function (e) {
        if (bootstrapTabAvailable()) {
            return;
        }
        var current = e.target;
        if (!current || !isTabToggle(current)) {
            return;
        }

        var tablist = current.closest('[role="tablist"]') || current.parentElement;
        var vertical = tablist && tablist.getAttribute('aria-orientation') === 'vertical';
        var prevKey = vertical ? 'ArrowUp' : 'ArrowLeft';
        var nextKey = vertical ? 'ArrowDown' : 'ArrowRight';

        var key = e.key;
        if (key !== prevKey && key !== nextKey && key !== 'Home' && key !== 'End') {
            return;
        }

        var tabs = tablist ? getTabsInList(tablist) : [];
        if (tabs.length < 2) {
            return;
        }
        var index = tabs.indexOf(current);
        if (index === -1) {
            return;
        }

        var nextIndex;
        if (key === 'Home') {
            nextIndex = 0;
        } else if (key === 'End') {
            nextIndex = tabs.length - 1;
        } else if (key === nextKey) {
            nextIndex = (index + 1) % tabs.length;
        } else {
            nextIndex = (index - 1 + tabs.length) % tabs.length;
        }

        e.preventDefault();
        tabs[nextIndex].focus();
        show(tabs[nextIndex]);
    });

    // Initial roving-tabindex pass: only the already-active tab in each
    // tablist stays in the natural Tab order, matching what show() then
    // maintains on every activation. Runs once at load — tabs aren't added
    // dynamically anywhere in the host codebase (unlike e.g. tooltip
    // triggers), so a one-time pass is sufficient.
    document.querySelectorAll(TAB_SELECTOR).forEach(function (tab) {
        if (tab.classList.contains('active')) {
            tab.setAttribute('tabindex', '0');
        } else if (!tab.hasAttribute('tabindex')) {
            tab.setAttribute('tabindex', '-1');
        }
    });

    global.UiKit = global.UiKit || {};
    global.UiKit.tab = { show: show };
})(window);
