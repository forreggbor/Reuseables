/**
 * UiKit modal.
 *
 * `UiKit.Modal` is a drop-in-compatible replacement for `bootstrap.Modal`:
 * same constructor signature, same static `getInstance()`/`getOrCreateInstance()`
 * helpers, same instance methods (`show()`/`hide()`/`toggle()`/`dispose()`/
 * `handleUpdate()`), same `show.bs.modal`/`shown.bs.modal`/`hide.bs.modal`/
 * `hidden.bs.modal`/`hidePrevented.bs.modal` events, and it recognizes the
 * same `data-bs-toggle="modal"` / `data-bs-target` / `data-bs-dismiss="modal"`
 * / `data-bs-backdrop` / `data-bs-keyboard` markup convention already used by
 * every existing `.modal` in the host codebase — so migrating a call site is
 * a mechanical `bootstrap.Modal` -> `UiKit.Modal` rename, with zero markup
 * changes. This mirrors the same "reuse the existing convention" approach as
 * ui-collapse.js/ui-dropdown.js/ui-tabs.js/ui-offcanvas.js.
 *
 * Visual styling (`.modal`, `.modal-dialog`, `.modal-content`,
 * `.modal-backdrop`, `.fade`/`.show`, size/scroll/centered variants) is
 * intentionally NOT duplicated here — Bootstrap CSS is still loaded at this
 * point (removed only in a later, separate step) and already styles these
 * class names identically for markup this component doesn't touch. This
 * component only manages class/attribute state, timing, and events; it
 * creates the `.modal-backdrop` element with the same class names Bootstrap
 * itself uses, so the still-loaded CSS renders it unchanged.
 *
 * Coexistence with Bootstrap JS (transitional, until it is fully removed):
 * like Collapse/Dropdown/Tab/Offcanvas (and unlike Toast/Tooltip/Popover),
 * Bootstrap's own Modal *also* self-initializes a capture-phase delegated
 * click listener on `document` for `data-bs-toggle="modal"` and
 * `data-bs-dismiss="modal"` the moment bootstrap.bundle.js loads (confirmed
 * by reading Bootstrap 5.3.0's own source). Unlike the other components,
 * Modal is driven by an explicit, stateful instance object rather than pure
 * markup-class toggling, and every migrated call site does its own explicit
 * `UiKit.Modal.getOrCreateInstance(el).show()` — so a blanket "step aside
 * whenever Bootstrap is present" guard (the approach used by the other
 * components) is wrong here: a real bug, found and fixed during migration,
 * where a modal opened via `UiKit.Modal` had its `data-bs-dismiss="modal"`
 * button silently do nothing, because that click always went to Bootstrap's
 * own (still-registered) delegated listener, which — knowing nothing about
 * this component's instance — created a fresh, never-shown `bootstrap.Modal`
 * instance for the element and called `.hide()` on it, a guaranteed no-op.
 * The click-delegation paths below check *this component's own* instance
 * registry per element: if a `UiKit.Modal` instance already exists for the
 * target (i.e. the call site was migrated), this component handles the click
 * and calls `stopImmediatePropagation()` so Bootstrap's listener never also
 * reacts; otherwise (a modal not yet migrated, still opened only through
 * literal `bootstrap.Modal` code) the click is left alone entirely, so
 * Bootstrap's own listener keeps handling it exactly as before — zero
 * regression for areas not yet migrated. A handful of purely-declarative
 * modals with no backing JS anywhere (e.g. a `data-bs-toggle="modal"` help
 * button with no explicit instantiation) fall into this "not yet migrated"
 * case too and simply keep working via Bootstrap until it's removed.
 *
 * This registry check alone is not sufficient, though: `stopImmediatePropagation()`
 * only stops listeners that haven't run yet. `ui-modal.js` is therefore loaded
 * *before* the Bootstrap JS bundle `<script>` tag (unlike every other UiKit
 * component, which loads after it) so this component's capture-phase listener
 * always registers, and therefore always runs, first — a real double-backdrop
 * bug was found and fixed during migration on a modal reachable via both a
 * migrated explicit `UiKit.Modal` call site (e.g. an "edit" action) and a
 * plain `data-bs-toggle="modal"` button with no JS of its own (e.g. an "add
 * new" action next to it): with the original after-Bootstrap script order,
 * Bootstrap's listener always ran first and opened its own (harmless but
 * real) instance before this component's listener got a chance to check
 * ownership and call `stopImmediatePropagation()` — too late to undo an
 * open that already happened, producing two stacked backdrops.
 *
 * Known simplification, stated up front: no scrollbar-width compensation on
 * fixed-position elements (Bootstrap's `.fixed-top`/`.sticky-top` margin
 * adjustment) — only `<body>` itself gets the padding-right compensation.
 * No true nested-modal stacking support (opening a second modal from inside
 * an already-open one) — the codebase never does this.
 *
 * Tab/Shift+Tab is trapped within the modal while open (`trapFocus()`),
 * matching `bootstrap.Modal`'s own focus-trap behavior — the native
 * `<dialog>`-based `ui-dialog.js` gets this for free from the browser via
 * `showModal()`, but this component builds its own modal on a plain element,
 * so it has to implement the trap itself.
 */
(function (global) {
    'use strict';

    var instances = new WeakMap();
    // Matches the `prefers-reduced-motion` handling in css/uikit.css, which
    // shortens the actual CSS transition to near-zero — keep this JS-driven
    // completion timer in step so state doesn't finalize ~150ms after the
    // (invisible) transition already ended.
    var TRANSITION_MS = window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : 150;

    function getSelector(el) {
        var sel = el.getAttribute('data-bs-target');
        if (sel && sel !== '#') {
            return sel;
        }
        var href = el.getAttribute('href');
        return href && href.charAt(0) === '#' && href.length > 1 ? href : null;
    }

    function resolveTarget(el) {
        var sel = getSelector(el);
        if (!sel) {
            return null;
        }
        try {
            return document.querySelector(sel);
        } catch (e) {
            return null;
        }
    }

    function fire(el, name, detail) {
        var evt = new CustomEvent(name, { bubbles: true, cancelable: true, detail: detail || {} });
        el.dispatchEvent(evt);
        return evt;
    }

    function scrollbarWidth() {
        return Math.max(0, window.innerWidth - document.documentElement.clientWidth);
    }

    function isAnimated(el) {
        return el.classList.contains('fade');
    }

    function reflow(el) {
        return el.offsetHeight;
    }

    function Modal(element, config) {
        if (typeof element === 'string') {
            element = document.querySelector(element);
        }
        if (!element) {
            throw new TypeError('UiKit.Modal: element not found');
        }
        if (instances.has(element)) {
            return instances.get(element);
        }

        this._element = element;
        this._dialog = element.querySelector('.modal-dialog');
        this._backdropEl = null;
        this._isShown = false;
        this._isTransitioning = false;
        this._config = { backdrop: true, keyboard: true, focus: true };
        if (config) {
            for (var key in config) {
                if (Object.prototype.hasOwnProperty.call(config, key)) {
                    this._config[key] = config[key];
                }
            }
        }
        var attrBackdrop = element.getAttribute('data-bs-backdrop');
        if (attrBackdrop === 'static') {
            this._config.backdrop = 'static';
        } else if (attrBackdrop === 'false') {
            this._config.backdrop = false;
        }
        if (element.getAttribute('data-bs-keyboard') === 'false') {
            this._config.keyboard = false;
        }

        this._onKeydown = onKeydown.bind(this);
        this._onMousedown = onMousedown.bind(this);
        this._element.addEventListener('keydown', this._onKeydown);
        this._element.addEventListener('mousedown', this._onMousedown);

        instances.set(element, this);
        return this;
    }

    function onKeydown(e) {
        if (e.key === 'Tab') {
            trapFocus(this._element, e);
            return;
        }
        if (e.key !== 'Escape') {
            return;
        }
        if (this._config.keyboard) {
            this.hide();
        } else {
            triggerBackdropTransition(this);
        }
    }

    var FOCUSABLE_SELECTOR = 'a[href], button:not([disabled]), textarea:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), [tabindex]:not([tabindex="-1"])';

    function getFocusableElements(container) {
        return Array.prototype.filter.call(container.querySelectorAll(FOCUSABLE_SELECTOR), function (el) {
            return el.offsetWidth > 0 || el.offsetHeight > 0 || el === document.activeElement;
        });
    }

    // Native <dialog> (ui-dialog.js) gets this for free from the browser via
    // showModal(); this component builds its own modal on a plain element,
    // so it has to trap Tab/Shift+Tab itself to actually match bootstrap.
    // Modal's behavior, which does the same.
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

    function onMousedown(e) {
        var self = this;
        if (e.target !== self._element) {
            return;
        }
        var onClick = function (e2) {
            self._element.removeEventListener('click', onClick);
            if (e2.target !== self._element) {
                return;
            }
            if (self._config.backdrop === 'static') {
                triggerBackdropTransition(self);
                return;
            }
            if (self._config.backdrop) {
                self.hide();
            }
        };
        self._element.addEventListener('click', onClick);
    }

    function triggerBackdropTransition(instance) {
        var hideEvent = fire(instance._element, 'hidePrevented.bs.modal');
        if (hideEvent.defaultPrevented) {
            return;
        }
        instance._element.classList.add('modal-static');
        window.setTimeout(function () {
            instance._element.classList.remove('modal-static');
        }, TRANSITION_MS);
    }

    function adjustDialog(instance) {
        var isModalOverflowing = instance._element.scrollHeight > document.documentElement.clientHeight;
        var width = scrollbarWidth();
        var isBodyOverflowing = width > 0;
        if (isBodyOverflowing && !isModalOverflowing) {
            instance._element.style.paddingRight = width + 'px';
        }
        if (!isBodyOverflowing && isModalOverflowing) {
            instance._element.style.paddingLeft = width + 'px';
        }
    }

    function resetAdjustments(instance) {
        instance._element.style.paddingLeft = '';
        instance._element.style.paddingRight = '';
    }

    function lockScroll() {
        var width = scrollbarWidth();
        if (width > 0) {
            document.body.style.paddingRight = width + 'px';
        }
        document.body.style.overflow = 'hidden';
    }

    function unlockScroll() {
        document.body.style.paddingRight = '';
        document.body.style.overflow = '';
    }

    function showBackdrop(instance, callback) {
        if (!instance._config.backdrop) {
            callback();
            return;
        }
        var backdrop = document.createElement('div');
        backdrop.className = 'modal-backdrop' + (isAnimated(instance._element) ? ' fade' : '');
        document.body.appendChild(backdrop);
        instance._backdropEl = backdrop;
        if (!isAnimated(instance._element)) {
            callback();
            return;
        }
        reflow(backdrop);
        backdrop.classList.add('show');
        window.setTimeout(callback, TRANSITION_MS);
    }

    function hideBackdrop(instance, callback) {
        var backdrop = instance._backdropEl;
        instance._backdropEl = null;
        if (!backdrop) {
            callback();
            return;
        }
        if (!isAnimated(instance._element)) {
            backdrop.remove();
            callback();
            return;
        }
        backdrop.classList.remove('show');
        window.setTimeout(function () {
            backdrop.remove();
            callback();
        }, TRANSITION_MS);
    }

    function focusFirstElement(instance) {
        if (!instance._config.focus) {
            return;
        }
        if (instance._element.contains(document.activeElement)) {
            return;
        }
        instance._element.focus();
    }

    Modal.prototype.toggle = function (relatedTarget) {
        return this._isShown ? this.hide() : this.show(relatedTarget);
    };

    Modal.prototype.show = function (relatedTarget) {
        var self = this;
        if (self._isShown || self._isTransitioning) {
            return;
        }
        var showEvent = fire(self._element, 'show.bs.modal', { relatedTarget: relatedTarget });
        if (showEvent.defaultPrevented) {
            return;
        }
        self._isShown = true;
        self._isTransitioning = true;
        lockScroll();
        document.body.classList.add('modal-open');
        adjustDialog(self);

        if (!document.body.contains(self._element)) {
            document.body.appendChild(self._element);
        }
        self._element.style.display = 'block';
        self._element.removeAttribute('aria-hidden');
        self._element.setAttribute('aria-modal', 'true');
        self._element.setAttribute('role', 'dialog');
        self._element.scrollTop = 0;
        var body = self._element.querySelector('.modal-body');
        if (body) {
            body.scrollTop = 0;
        }

        showBackdrop(self, function () {
            reflow(self._element);
            self._element.classList.add('show');
            var complete = function () {
                self._isTransitioning = false;
                focusFirstElement(self);
                fire(self._element, 'shown.bs.modal', { relatedTarget: relatedTarget });
            };
            if (isAnimated(self._element)) {
                window.setTimeout(complete, TRANSITION_MS);
            } else {
                complete();
            }
        });
    };

    Modal.prototype.hide = function () {
        var self = this;
        if (!self._isShown || self._isTransitioning) {
            return;
        }
        var hideEvent = fire(self._element, 'hide.bs.modal');
        if (hideEvent.defaultPrevented) {
            return;
        }
        self._isShown = false;
        self._isTransitioning = true;
        self._element.classList.remove('show');

        var finishHide = function () {
            self._element.style.display = 'none';
            self._element.setAttribute('aria-hidden', 'true');
            self._element.removeAttribute('aria-modal');
            self._element.removeAttribute('role');
            self._isTransitioning = false;
            hideBackdrop(self, function () {
                document.body.classList.remove('modal-open');
                resetAdjustments(self);
                unlockScroll();
                fire(self._element, 'hidden.bs.modal');
            });
        };
        if (isAnimated(self._element)) {
            window.setTimeout(finishHide, TRANSITION_MS);
        } else {
            finishHide();
        }
    };

    Modal.prototype.dispose = function () {
        this._element.removeEventListener('keydown', this._onKeydown);
        this._element.removeEventListener('mousedown', this._onMousedown);
        if (this._backdropEl) {
            this._backdropEl.remove();
            this._backdropEl = null;
        }
        instances.delete(this._element);
    };

    Modal.prototype.handleUpdate = function () {
        adjustDialog(this);
    };

    Modal.getInstance = function (element) {
        if (typeof element === 'string') {
            element = document.querySelector(element);
        }
        return (element && instances.get(element)) || null;
    };

    Modal.getOrCreateInstance = function (element, config) {
        return Modal.getInstance(element) || new Modal(element, config);
    };

    // See the docblock above for why ownership is decided per-element via
    // this component's own instance registry, not a blanket "Bootstrap
    // present?" check.
    document.addEventListener('click', function (e) {
        var trigger = e.target.closest('[data-bs-toggle="modal"]');
        if (trigger) {
            var target = resolveTarget(trigger);
            if (target && Modal.getInstance(target)) {
                e.stopImmediatePropagation();
                if (trigger.tagName === 'A' || trigger.tagName === 'AREA') {
                    e.preventDefault();
                }
                var alreadyOpen = document.querySelector('.modal.show');
                if (alreadyOpen && alreadyOpen !== target) {
                    var openInstance = Modal.getInstance(alreadyOpen);
                    if (openInstance) {
                        openInstance.hide();
                    }
                }
                Modal.getInstance(target).toggle(trigger);
            }
            return;
        }

        var dismiss = e.target.closest('[data-bs-dismiss="modal"]');
        if (dismiss) {
            var modalEl = dismiss.closest('.modal');
            var instance = modalEl && Modal.getInstance(modalEl);
            if (instance) {
                e.stopImmediatePropagation();
                if (dismiss.tagName === 'A' || dismiss.tagName === 'AREA') {
                    e.preventDefault();
                }
                instance.hide();
            }
        }
    }, true);

    global.UiKit = global.UiKit || {};
    global.UiKit.Modal = Modal;
})(window);
