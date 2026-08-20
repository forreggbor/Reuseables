/**
 * UiKit collapse.
 *
 * Self-initializing, single delegated `click` listener — no per-element
 * setup, dynamically-added trigger/target pairs work automatically.
 *
 * Compatibility note (see ui-tooltip.js for the same rationale): recognizes
 * the existing `data-bs-toggle="collapse"` / `data-bs-target` / `href` /
 * `data-bs-parent` attribute convention, and toggles the same `.collapse` /
 * `.collapsing` / `.show` class names the existing markup and CSS across the
 * host codebase already use — rewriting ~85 existing collapsible sections'
 * markup to a new class/attribute convention would be a much larger, riskier
 * change than reusing names that are, on their own, generic English words.
 * None of this requires Bootstrap's own CSS or JS to be present.
 *
 * Fires the same `show.bs.collapse` / `shown.bs.collapse` / `hide.bs.collapse`
 * / `hidden.bs.collapse` custom events on the target element that the
 * Bootstrap Collapse component fired, and toggles a `.collapsed` class on
 * every trigger pointing at a given target — existing listeners for these
 * (e.g. a chevron-icon toggle) keep working unmodified.
 *
 * Coexistence with Bootstrap JS (transitional, until it is fully removed):
 * unlike Toast/Modal/Tooltip, Bootstrap's own Collapse self-initializes a
 * *capture-phase* delegated click listener on `document` the moment
 * bootstrap.bundle.js loads — with no explicit `new bootstrap.Collapse()`
 * call needed. Since that script loads earlier in the page than this one,
 * its capture-phase listener runs before this one on every click, so it
 * always gets to react first regardless of what this listener does. Its own
 * class manipulation is functionally identical to this component's (same
 * `.collapse`/`.collapsing`/`.show` classes, same events, same `.collapsed`
 * trigger toggling — because this component was deliberately built to match
 * it), so the click-driven path below simply steps aside and lets Bootstrap
 * handle it whenever Bootstrap is present, instead of both reacting to the
 * same click and racing each other. The moment Bootstrap JS is actually
 * removed from the page (a later step), this listener starts driving clicks
 * itself, with zero code changes anywhere. The programmatic `UiKit.collapse.
 * show/hide/toggle()` API always uses this component's own implementation,
 * regardless of whether Bootstrap is present.
 */
(function (global) {
    'use strict';

    // Matches the `prefers-reduced-motion` handling in css/uikit.css, which
    // shortens the actual CSS transition to near-zero — keep this JS-driven
    // completion timer in step so state doesn't finalize ~350ms after the
    // (invisible) transition already ended.
    var TRANSITION_MS = window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : 350;
    var pendingTimers = new WeakMap();

    function clearPending(target) {
        var id = pendingTimers.get(target);
        if (id) {
            window.clearTimeout(id);
            pendingTimers.delete(target);
        }
    }

    function resolveTarget(trigger) {
        var sel = trigger.getAttribute('data-bs-target') || trigger.getAttribute('href');
        if (!sel) {
            return null;
        }
        try {
            return document.querySelector(sel);
        } catch (e) {
            return null;
        }
    }

    function isShown(el) {
        return el.classList.contains('show');
    }

    function fire(el, name) {
        el.dispatchEvent(new CustomEvent(name, { bubbles: true }));
    }

    function updateTriggers(target, expanded) {
        document.querySelectorAll('[data-bs-toggle="collapse"]').forEach(function (trigger) {
            if (resolveTarget(trigger) === target) {
                trigger.setAttribute('aria-expanded', expanded ? 'true' : 'false');
                trigger.classList.toggle('collapsed', !expanded);
            }
        });
    }

    function hideOthersInAccordion(target) {
        var parentSel = target.getAttribute('data-bs-parent');
        if (!parentSel) {
            return;
        }
        document.querySelectorAll('.collapse.show[data-bs-parent="' + parentSel + '"]').forEach(function (other) {
            if (other !== target) {
                hide(other);
            }
        });
    }

    function show(target) {
        if (!target || (isShown(target) && !target.classList.contains('collapsing'))) {
            return;
        }
        clearPending(target);
        hideOthersInAccordion(target);
        fire(target, 'show.bs.collapse');
        target.classList.remove('collapse');
        target.classList.add('collapsing');
        var height = target.scrollHeight;
        target.style.height = '0px';
        target.getBoundingClientRect(); // force reflow so the transition below animates
        target.style.height = height + 'px';
        updateTriggers(target, true);
        var timerId = window.setTimeout(function () {
            pendingTimers.delete(target);
            target.classList.remove('collapsing');
            target.classList.add('collapse', 'show');
            target.style.height = '';
            fire(target, 'shown.bs.collapse');
        }, TRANSITION_MS);
        pendingTimers.set(target, timerId);
    }

    function hide(target) {
        if (!target || (!isShown(target) && !target.classList.contains('collapsing'))) {
            return;
        }
        clearPending(target);
        fire(target, 'hide.bs.collapse');
        target.style.height = target.scrollHeight + 'px';
        target.getBoundingClientRect(); // force reflow
        target.classList.remove('collapse', 'show');
        target.classList.add('collapsing');
        target.style.height = '0px';
        updateTriggers(target, false);
        var timerId = window.setTimeout(function () {
            pendingTimers.delete(target);
            target.classList.remove('collapsing');
            target.classList.add('collapse');
            target.style.height = '';
            fire(target, 'hidden.bs.collapse');
        }, TRANSITION_MS);
        pendingTimers.set(target, timerId);
    }

    function toggle(target) {
        if (!target) {
            return;
        }
        if (isShown(target)) {
            hide(target);
        } else {
            show(target);
        }
    }

    // See the docblock above for why this defers to Bootstrap when present.
    function bootstrapCollapseAvailable() {
        return typeof global.bootstrap !== 'undefined' && !!global.bootstrap.Collapse;
    }

    document.addEventListener('click', function (e) {
        if (bootstrapCollapseAvailable()) {
            return;
        }
        var trigger = e.target.closest('[data-bs-toggle="collapse"]');
        if (!trigger) {
            return;
        }
        var target = resolveTarget(trigger);
        if (!target) {
            return;
        }
        e.preventDefault();
        toggle(target);
    }, true);

    global.UiKit = global.UiKit || {};
    global.UiKit.collapse = { show: show, hide: hide, toggle: toggle };
})(window);
