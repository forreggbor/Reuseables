/**
 * UiKit confirm dialog.
 *
 * `UiKit.confirm({ message, onConfirm, title, confirmText, cancelText, danger,
 * allowHtml })` shows a single, reusable native `<dialog>`-based confirmation
 * modal. Only one confirm dialog can exist — a second call while one is open
 * reconfigures and reuses the same element.
 *
 * Native `<dialog>` + `showModal()` provides, for free, what the Bootstrap
 * Modal this replaces had to implement by hand: a real focus trap, Escape-to-
 * close, and a `::backdrop` pseudo-element — so none of that is reimplemented
 * here.
 *
 * Labels (all overridable via `UiKit.configure({ labels: {...} })`):
 *   confirmTitle   — dialog heading, default "Confirm"
 *   confirmButton  — confirm button text, default "Confirm"
 *   cancelButton   — cancel button text, default "Cancel"
 *
 * Icon path data: Bootstrap Icons 1.11.3 (MIT), see the host project's own
 * LICENSES/bootstrap-icons-MIT.txt for the full license text.
 */
(function (global) {
    'use strict';

    var ICON_QUESTION = '<path d="M16 8A8 8 0 1 1 0 8a8 8 0 0 1 16 0M5.496 6.033h.825c.138 0 .248-.113.266-.25.09-.656.54-1.134 1.342-1.134.686 0 1.314.343 1.314 1.168 0 .635-.374.927-.965 1.371-.673.489-1.206 1.06-1.168 1.987l.003.217a.25.25 0 0 0 .25.246h.811a.25.25 0 0 0 .25-.25v-.105c0-.718.273-.927 1.01-1.486.609-.463 1.244-.977 1.244-2.056 0-1.511-1.276-2.241-2.673-2.241-1.267 0-2.655.59-2.75 2.286a.237.237 0 0 0 .241.247m2.325 6.443c.61 0 1.029-.394 1.029-.927 0-.552-.42-.94-1.029-.94-.584 0-1.009.388-1.009.94 0 .533.425.927 1.01.927z"/>';
    var ICON_CHECK = '<path d="M8 15A7 7 0 1 1 8 1a7 7 0 0 1 0 14m0 1A8 8 0 1 0 8 0a8 8 0 0 0 0 16"/><path d="m10.97 4.97-.02.022-3.473 4.425-2.093-2.094a.75.75 0 0 0-1.06 1.06L6.97 11.03a.75.75 0 0 0 1.079-.02l3.992-4.99a.75.75 0 0 0-1.071-1.05"/>';
    var ICON_CANCEL = '<path d="M8 15A7 7 0 1 1 8 1a7 7 0 0 1 0 14m0 1A8 8 0 1 0 8 0a8 8 0 0 0 0 16"/><path d="M4.646 4.646a.5.5 0 0 1 .708 0L8 7.293l2.646-2.647a.5.5 0 0 1 .708.708L8.707 8l2.647 2.646a.5.5 0 0 1-.708.708L8 8.707l-2.646 2.647a.5.5 0 0 1-.708-.708L7.293 8 4.646 5.354a.5.5 0 0 1 0-.708"/>';
    var ICON_CLOSE = '<path d="M2.146 2.854a.5.5 0 1 1 .708-.708L8 7.293l5.146-5.147a.5.5 0 0 1 .708.708L8.707 8l5.147 5.146a.5.5 0 0 1-.708.708L8 8.707l-5.146 5.147a.5.5 0 0 1-.708-.708L7.293 8z"/>';

    function svg(inner, cls) {
        return '<svg class="' + cls + '" viewBox="0 0 16 16" aria-hidden="true" focusable="false">' + inner + '</svg>';
    }

    var dialogEl = null;
    var els = {};
    var pendingConfirm = null;

    function build() {
        dialogEl = document.createElement('dialog');
        dialogEl.className = 'uik-dialog';
        dialogEl.innerHTML =
            '<div class="uik-dialog__header">' +
                '<h2 class="uik-dialog__title">' + svg(ICON_QUESTION, 'uik-dialog__title-icon') + '<span class="uik-dialog__title-text"></span></h2>' +
                '<button type="button" class="uik-dialog__close" aria-label="Close">' + svg(ICON_CLOSE, 'uik-dialog__close-icon') + '</button>' +
            '</div>' +
            '<div class="uik-dialog__body"></div>' +
            '<div class="uik-dialog__footer">' +
                '<button type="button" class="uik-dialog__btn uik-dialog__btn--cancel">' + svg(ICON_CANCEL, 'uik-dialog__btn-icon') + '<span class="uik-dialog__cancel-text"></span></button>' +
                '<button type="button" class="uik-dialog__btn uik-dialog__btn--confirm">' + svg(ICON_CHECK, 'uik-dialog__btn-icon') + '<span class="uik-dialog__confirm-text"></span></button>' +
            '</div>';
        document.body.appendChild(dialogEl);

        els = {
            titleText: dialogEl.querySelector('.uik-dialog__title-text'),
            body: dialogEl.querySelector('.uik-dialog__body'),
            closeBtn: dialogEl.querySelector('.uik-dialog__close'),
            cancelBtn: dialogEl.querySelector('.uik-dialog__btn--cancel'),
            cancelText: dialogEl.querySelector('.uik-dialog__cancel-text'),
            confirmBtn: dialogEl.querySelector('.uik-dialog__btn--confirm'),
            confirmText: dialogEl.querySelector('.uik-dialog__confirm-text')
        };

        dialogEl.addEventListener('click', function (e) {
            if (e.target === dialogEl) {
                closeDialog();
            }
        });
        els.closeBtn.addEventListener('click', closeDialog);
        els.cancelBtn.addEventListener('click', closeDialog);

        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter' || !dialogEl.open) {
                return;
            }
            var tag = e.target.tagName;
            if (tag !== 'INPUT' && tag !== 'SELECT') {
                return;
            }
            e.preventDefault();
            els.confirmBtn.click();
        });
    }

    function closeDialog() {
        pendingConfirm = null;
        dialogEl.close();
    }

    /**
     * Show the confirmation dialog.
     *
     * @param {{message: string, onConfirm?: Function, title?: string,
     *   confirmText?: string, cancelText?: string, danger?: boolean,
     *   allowHtml?: boolean}} options
     */
    function confirmDialog(options) {
        options = options || {};
        if (!dialogEl) {
            build();
        }

        els.titleText.textContent = options.title || global.UiKit._label('confirmTitle', 'Confirm');
        if (options.allowHtml === true) {
            els.body.innerHTML = options.message;
        } else {
            els.body.textContent = options.message;
        }

        els.confirmText.textContent = options.confirmText || global.UiKit._label('confirmButton', 'Confirm');
        els.cancelText.textContent = options.cancelText || global.UiKit._label('cancelButton', 'Cancel');
        els.confirmBtn.classList.toggle('uik-dialog__btn--danger', !!options.danger);

        pendingConfirm = typeof options.onConfirm === 'function' ? options.onConfirm : null;
        els.confirmBtn.onclick = function () {
            var cb = pendingConfirm;
            pendingConfirm = null;
            dialogEl.close();
            if (cb) {
                cb();
            }
        };

        if (dialogEl.open) {
            // showModal() throws InvalidStateError on an already-open <dialog> —
            // close first so a second confirm() call while one is showing
            // reconfigures and reopens in place instead of throwing.
            dialogEl.close();
        }
        dialogEl.classList.add('uik-dialog--opening');
        dialogEl.showModal();
        requestAnimationFrame(function () {
            requestAnimationFrame(function () {
                dialogEl.classList.remove('uik-dialog--opening');
            });
        });

        var focusable = els.body.querySelector('input, textarea, select');
        if (focusable) {
            focusable.focus();
            if (typeof focusable.select === 'function') {
                focusable.select();
            }
        }
    }

    global.UiKit = global.UiKit || {};
    global.UiKit.confirm = confirmDialog;
})(window);
