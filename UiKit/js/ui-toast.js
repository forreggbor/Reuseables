/**
 * UiKit toast notification.
 *
 * `UiKit.toast(message, type)` shows a single, auto-dismissing, dismissible
 * corner notification. Only one toast is ever visible at a time — calling
 * this while one is already showing replaces it, matching the behavior of
 * the single reusable toast element this component replaces.
 *
 * `message` is rendered as plain text (no HTML) — there is no equivalent of
 * an "allowHtml" option here, unlike UiKit.confirm().
 *
 * Icon path data: Bootstrap Icons 1.11.3 (MIT), see the host project's own
 * LICENSES/bootstrap-icons-MIT.txt for the full license text.
 */
(function (global) {
    'use strict';

    var ICONS = {
        success: '<path d="M16 8A8 8 0 1 1 0 8a8 8 0 0 1 16 0m-3.97-3.03a.75.75 0 0 0-1.08.022L7.477 9.417 5.384 7.323a.75.75 0 0 0-1.06 1.06L6.97 11.03a.75.75 0 0 0 1.079-.02l3.992-4.99a.75.75 0 0 0-.01-1.05z"/>',
        error: '<path d="M8.982 1.566a1.13 1.13 0 0 0-1.96 0L.165 13.233c-.457.778.091 1.767.98 1.767h13.713c.889 0 1.438-.99.98-1.767zM8 5c.535 0 .954.462.9.995l-.35 3.507a.552.552 0 0 1-1.1 0L7.1 5.995A.905.905 0 0 1 8 5m.002 6a1 1 0 1 1 0 2 1 1 0 0 1 0-2"/>',
        warning: '<path d="M16 8A8 8 0 1 1 0 8a8 8 0 0 1 16 0M8 4a.905.905 0 0 0-.9.995l.35 3.507a.552.552 0 0 0 1.1 0l.35-3.507A.905.905 0 0 0 8 4m.002 6a1 1 0 1 0 0 2 1 1 0 0 0 0-2"/>',
        info: '<path d="M8 16A8 8 0 1 0 8 0a8 8 0 0 0 0 16m.93-9.412-1 4.705c-.07.34.029.533.304.533.194 0 .487-.07.686-.246l-.088.416c-.287.346-.92.598-1.465.598-.703 0-1.002-.422-.808-1.319l.738-3.468c.064-.293.006-.399-.287-.47l-.451-.081.082-.381 2.29-.287zM8 5.5a1 1 0 1 1 0-2 1 1 0 0 1 0 2"/>'
    };
    var CLOSE_ICON = '<path d="M2.146 2.854a.5.5 0 1 1 .708-.708L8 7.293l5.146-5.147a.5.5 0 0 1 .708.708L8.707 8l5.147 5.146a.5.5 0 0 1-.708.708L8 8.707l-5.146 5.147a.5.5 0 0 1-.708-.708L7.293 8z"/>';

    function svg(innerPath, extraClass) {
        return '<svg class="' + extraClass + '" viewBox="0 0 16 16" aria-hidden="true" focusable="false">' + innerPath + '</svg>';
    }

    var container = null;
    var activeEl = null;
    var hideTimer = null;
    var DISMISS_FALLBACK_MS = 400; // .uik-toast--hide keyframe is 300ms — see css/uikit.css

    function ensureContainer() {
        if (container) {
            return container;
        }
        container = document.createElement('div');
        container.className = 'uik-toast-container';
        document.body.appendChild(container);
        return container;
    }

    function dismiss(el) {
        el.classList.remove('uik-toast--showing');
        el.classList.add('uik-toast--hide');

        var done = false;
        var fallbackTimer;
        function finish() {
            if (done) {
                return;
            }
            done = true;
            el.removeEventListener('animationend', finish);
            clearTimeout(fallbackTimer);
            el.remove();
            if (activeEl === el) {
                activeEl = null;
            }
        }
        el.addEventListener('animationend', finish);
        // Fallback: if the slide-out animation never fires 'animationend' (host
        // CSS overrides/disables animations, an ancestor goes display:none
        // mid-transition, etc.), still clean up instead of leaking the element.
        fallbackTimer = setTimeout(finish, DISMISS_FALLBACK_MS);
    }

    /**
     * Show a toast notification.
     *
     * @param {string} message Plain-text message
     * @param {'success'|'error'|'warning'|'info'} [type='info']
     */
    function toast(message, type) {
        type = ICONS[type] ? type : 'info';
        ensureContainer();

        if (activeEl) {
            clearTimeout(hideTimer);
            activeEl.remove();
            activeEl = null;
        }

        var el = document.createElement('div');
        el.className = 'uik-toast uik-toast--' + type + ' uik-toast--showing';
        el.setAttribute('role', 'alert');
        el.setAttribute('aria-live', 'assertive');
        el.setAttribute('aria-atomic', 'true');
        el.innerHTML =
            svg(ICONS[type], 'uik-toast__icon') +
            '<span class="uik-toast__body"></span>' +
            '<button type="button" class="uik-toast__close" aria-label="Close">' + svg(CLOSE_ICON, 'uik-toast__close-icon') + '</button>';
        el.querySelector('.uik-toast__body').textContent = message;
        el.querySelector('.uik-toast__close').addEventListener('click', function () {
            clearTimeout(hideTimer);
            dismiss(el);
        });

        container.appendChild(el);
        activeEl = el;
        hideTimer = setTimeout(function () {
            dismiss(el);
        }, 5000);
    }

    global.UiKit = global.UiKit || {};
    global.UiKit.toast = toast;
})(window);
