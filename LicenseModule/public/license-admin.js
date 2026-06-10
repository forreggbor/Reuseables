/* Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved. */

/**
 * Addon toggle — accordion behaviour for addon description panels.
 * Closes any other open description when a new one is opened.
 */
document.addEventListener('DOMContentLoaded', () => {
    document.addEventListener('click', e => {
        const btn = e.target.closest('.lm-addon-badge[data-desc-target]');
        if (!btn) return;

        const targetId = btn.dataset.descTarget;
        const desc = document.getElementById(targetId);
        if (!desc) return;

        const isOpen = btn.getAttribute('aria-expanded') === 'true';

        // Close all other open descriptions on the same page
        document.querySelectorAll('.lm-addon-badge[aria-expanded="true"]').forEach(b => {
            if (b !== btn) {
                b.setAttribute('aria-expanded', 'false');
                const otherId = b.dataset.descTarget;
                const otherDesc = document.getElementById(otherId);
                if (otherDesc) otherDesc.hidden = true;
            }
        });

        // Toggle this one
        btn.setAttribute('aria-expanded', String(!isOpen));
        desc.hidden = isOpen;
    });
});

/**
 * Validate Now button — posts a CSRF-protected request to the validate URL,
 * shows a success/error alert, and reloads the page on success.
 */
document.addEventListener('DOMContentLoaded', () => {
    const validateBtn = document.getElementById('lm-validate-btn');
    if (!validateBtn) return;

    validateBtn.addEventListener('click', async e => {
        e.preventDefault();

        const url        = validateBtn.dataset.url;
        const csrf       = validateBtn.dataset.csrf;
        const msgSuccess = validateBtn.dataset.msgSuccess || 'License validated successfully';
        const msgError   = validateBtn.dataset.msgError   || 'Validation failed';
        const msgNetwork = validateBtn.dataset.msgNetwork  || 'Request failed. Please try again.';

        validateBtn.disabled = true;
        validateBtn.classList.add('lm-btn-loading');

        const alertContainer = document.getElementById('lm-alert-container');

        try {
            const resp = await fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ csrf_token: csrf }),
            });
            const data = await resp.json();

            if (data.success) {
                showLmAlert(alertContainer, data.message || msgSuccess, 'success');
                setTimeout(() => window.location.reload(), 2000);
            } else {
                showLmAlert(alertContainer, data.message || data.error || msgError, 'warning');
            }
        } catch {
            showLmAlert(alertContainer, msgNetwork, 'danger');
        } finally {
            validateBtn.disabled = false;
            validateBtn.classList.remove('lm-btn-loading');
        }
    });
});

/**
 * Displays a temporary alert message inside the given container element.
 *
 * @param {HTMLElement|null} container - The element to render the alert into.
 * @param {string}           message   - The message text to display.
 * @param {string}           type      - Alert type: 'success', 'warning', 'danger', or 'info'.
 */
function showLmAlert(container, message, type) {
    if (!container) return;
    const div = document.createElement('div');
    div.className = `lm-alert lm-alert-${type}`;
    div.textContent = message;
    container.innerHTML = '';
    container.appendChild(div);
    setTimeout(() => div.remove(), 5000);
}
