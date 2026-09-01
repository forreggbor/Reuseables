/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * UiKit tooltip.
 *
 * Self-initializing on load — no explicit init call needed. Uses a single
 * document-level delegated listener (`mouseover`/`mouseout`/`focusin`/
 * `focusout`) instead of Bootstrap Tooltip's one-instance-per-element model,
 * so dynamically-added trigger elements work automatically without any
 * "re-initialize tooltips on this new row" call anywhere — there is nothing
 * to initialize or dispose.
 *
 * Compatibility note: for markup compatibility with the very large number of
 * existing `data-bs-toggle="tooltip"` trigger elements this replaces, this
 * component recognizes that same attribute name (plus `data-bs-placement`)
 * as its trigger convention, rather than inventing a new one — reading an
 * attribute name is not a dependency on Bootstrap itself; nothing here needs
 * Bootstrap's CSS or JS to be present.
 *
 * Content comes from the trigger's `title` attribute, read once on first
 * hover/focus and then moved to `data-uik-tooltip-content` (and the native
 * `title` removed) so the browser's own native tooltip never also appears.
 * Content is always rendered as plain text — there is no HTML-content mode,
 * matching the fact that no existing trigger in the host codebase uses
 * Bootstrap's `data-bs-html="true"` option.
 */
(function (global) {
    'use strict';

    var CONTENT_ATTR = 'data-uik-tooltip-content';
    var GAP = 8;

    var tooltipEl = null;
    var innerEl = null;
    var currentTrigger = null;

    function ensureTooltipEl() {
        if (tooltipEl) {
            return tooltipEl;
        }
        tooltipEl = document.createElement('div');
        tooltipEl.className = 'uik-tooltip';
        tooltipEl.setAttribute('role', 'tooltip');
        tooltipEl.innerHTML = '<div class="uik-tooltip__arrow"></div><div class="uik-tooltip__inner"></div>';
        innerEl = tooltipEl.querySelector('.uik-tooltip__inner');
        document.body.appendChild(tooltipEl);
        return tooltipEl;
    }

    function captureContent(trigger) {
        if (!trigger.hasAttribute(CONTENT_ATTR)) {
            var title = trigger.getAttribute('title');
            if (title) {
                trigger.setAttribute(CONTENT_ATTR, title);
                trigger.removeAttribute('title');
            }
        }
        return trigger.getAttribute(CONTENT_ATTR);
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
        var tipRect = tooltipEl.getBoundingClientRect();
        var top, left;

        switch (placement) {
            case 'bottom':
                top = triggerRect.bottom + GAP;
                left = triggerRect.left + triggerRect.width / 2 - tipRect.width / 2;
                break;
            case 'left':
                top = triggerRect.top + triggerRect.height / 2 - tipRect.height / 2;
                left = triggerRect.left - tipRect.width - GAP;
                break;
            case 'right':
                top = triggerRect.top + triggerRect.height / 2 - tipRect.height / 2;
                left = triggerRect.right + GAP;
                break;
            default: // top
                top = triggerRect.top - tipRect.height - GAP;
                left = triggerRect.left + triggerRect.width / 2 - tipRect.width / 2;
        }

        // Keep the tooltip fully on-screen — no Popper-style flip/collision
        // detection, just prevent it from rendering off the edge (matches
        // the same simplification documented in ui-dropdown.js).
        left = clampToViewport(left, tipRect.width, window.innerWidth);
        top = clampToViewport(top, tipRect.height, window.innerHeight);

        tooltipEl.style.top = Math.round(top + window.scrollY) + 'px';
        tooltipEl.style.left = Math.round(left + window.scrollX) + 'px';
    }

    function show(trigger) {
        var content = captureContent(trigger);
        if (!content) {
            return;
        }
        currentTrigger = trigger;
        var placement = trigger.getAttribute('data-bs-placement') || 'top';

        ensureTooltipEl();
        innerEl.textContent = content;
        tooltipEl.className = 'uik-tooltip uik-tooltip--' + placement + ' uik-tooltip--shown';
        position(trigger, placement);
        requestAnimationFrame(function () {
            if (currentTrigger === trigger) {
                tooltipEl.classList.add('uik-tooltip--visible');
            }
        });
    }

    function hide(trigger) {
        if (currentTrigger !== trigger || !tooltipEl) {
            return;
        }
        currentTrigger = null;
        tooltipEl.classList.remove('uik-tooltip--shown', 'uik-tooltip--visible');
    }

    function findTrigger(el) {
        return el && el.closest ? el.closest('[data-bs-toggle="tooltip"]') : null;
    }

    document.addEventListener('mouseover', function (e) {
        var trigger = findTrigger(e.target);
        if (trigger) {
            show(trigger);
        }
    });
    document.addEventListener('mouseout', function (e) {
        var trigger = findTrigger(e.target);
        if (trigger) {
            hide(trigger);
        }
    });
    document.addEventListener('focusin', function (e) {
        var trigger = findTrigger(e.target);
        if (trigger) {
            show(trigger);
        }
    });
    document.addEventListener('focusout', function (e) {
        var trigger = findTrigger(e.target);
        if (trigger) {
            hide(trigger);
        }
    });

    global.UiKit = global.UiKit || {};
})(window);
