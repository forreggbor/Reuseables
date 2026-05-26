/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * Activity log admin interface — vanilla JS IIFE.
 * No external libraries. Reads config from data-al-* attributes on .al-root.
 */
(function () {
    'use strict';

    // -------------------------------------------------------------------------
    // Boot
    // -------------------------------------------------------------------------

    document.addEventListener('DOMContentLoaded', function () {
        if (document.querySelector('.al-print-root')) {
            window.print();
            return;
        }

        var root = document.querySelector('.al-root');
        if (!root) { return; }

        var detailUrl = root.getAttribute('data-al-detail-url') || '';

        initExpandRows();
        initDetailModal(detailUrl);
        initPrintButton();
    });

    // -------------------------------------------------------------------------
    // Expand / collapse diff rows
    // -------------------------------------------------------------------------

    function initExpandRows() {
        document.querySelectorAll('.al-expand-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var tr     = btn.closest('.al-tr');
                var logId  = tr ? tr.getAttribute('data-log-id') : null;
                if (!logId) { return; }

                var diffRow = document.querySelector('.al-tr-diff[data-diff-for="' + logId + '"]');
                if (!diffRow) { return; }

                var expanded = btn.getAttribute('aria-expanded') === 'true';
                if (expanded) {
                    diffRow.hidden = true;
                    btn.setAttribute('aria-expanded', 'false');
                } else {
                    diffRow.hidden = false;
                    btn.setAttribute('aria-expanded', 'true');
                }
            });
        });
    }

    // -------------------------------------------------------------------------
    // Detail modal
    // -------------------------------------------------------------------------

    function initDetailModal(detailUrl) {
        var overlay = document.getElementById('al-modal');
        if (!overlay) { return; }

        var body      = document.getElementById('al-modal-body');
        var closeBtn  = overlay.querySelector('.al-modal-close');

        // Open modal on details button click
        document.querySelectorAll('.al-details-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var logId = btn.getAttribute('data-log-id');
                openModal(overlay, body, detailUrl, logId);
            });
        });

        // Close on button click
        if (closeBtn) {
            closeBtn.addEventListener('click', function () { closeModal(overlay, body); });
        }

        // Close on overlay backdrop click
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) { closeModal(overlay, body); }
        });

        // Close on Escape key
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && overlay.classList.contains('al-modal-open')) {
                closeModal(overlay, body);
            }
        });
    }

    function openModal(overlay, body, detailUrl, logId) {
        var loadingEl = body.querySelector('.al-modal-loading');
        if (loadingEl) {
            loadingEl.style.display = '';
        } else {
            body.textContent = '';
            var p = document.createElement('p');
            p.className = 'al-modal-loading';
            p.textContent = 'Loading…';
            body.appendChild(p);
        }

        overlay.setAttribute('aria-hidden', 'false');
        overlay.classList.add('al-modal-open');
        document.body.style.overflow = 'hidden';

        var url = detailUrl + '&id=' + encodeURIComponent(logId);
        fetch(url, { headers: { 'Accept': 'application/json' } })
            .then(function (res) {
                if (!res.ok) { throw new Error('HTTP ' + res.status); }
                return res.json();
            })
            .then(function (json) {
                if (json.error) {
                    body.textContent = '';
                    var p = document.createElement('p');
                    p.className = 'al-modal-loading';
                    // textContent — never innerHTML — so server error messages are escaped
                    p.textContent = json.error;
                    body.appendChild(p);
                    return;
                }
                renderModal(body, json);
            })
            .catch(function () {
                body.textContent = '';
                var p = document.createElement('p');
                p.className = 'al-modal-loading';
                p.textContent = 'Failed to load details.';
                body.appendChild(p);
            });
    }

    function closeModal(overlay, body) {
        overlay.classList.remove('al-modal-open');
        overlay.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
        // Clear body after transition
        setTimeout(function () {
            var p = document.createElement('p');
            p.className = 'al-modal-loading';
            p.textContent = 'Loading…';
            body.textContent = '';
            body.appendChild(p);
        }, 200);
    }

    function renderModal(body, data) {
        body.textContent = '';

        var table = document.createElement('table');
        table.className = 'al-detail-table';

        var rows = [
            ['ID',           data.id],
            ['Timestamp',    data.created_at],
            ['User',         data.user_id !== null ? String(data.user_id) : '—'],
            ['Source',       data.source || '—'],
            ['Action',       data.action],
            ['Entity',       data.entity_type ? (data.entity_type + (data.entity_id ? ' #' + data.entity_id : '')) : '—'],
            ['IP Address',   data.ip_address || '—'],
            ['User Agent',   data.user_agent || '—'],
            ['Session ID',   data.session_id || '—'],
            ['Checksum',     data.checksum || '—'],
        ];

        rows.forEach(function (pair) {
            var tr = document.createElement('tr');
            var th = document.createElement('th');
            var td = document.createElement('td');
            // textContent throughout — never innerHTML
            th.textContent = pair[0];
            td.textContent = pair[1] !== undefined && pair[1] !== null ? String(pair[1]) : '—';
            tr.appendChild(th);
            tr.appendChild(td);
            table.appendChild(tr);
        });

        body.appendChild(table);

        // Old / new / context as preformatted JSON
        [['Previous Values', data.old_values], ['New Values', data.new_values], ['Context', data.context]]
            .forEach(function (pair) {
                if (!pair[1]) { return; }
                var h = document.createElement('h3');
                h.style.fontSize = '0.875rem';
                h.style.marginTop = '1rem';
                h.style.marginBottom = '0.375rem';
                h.textContent = pair[0];
                body.appendChild(h);

                var pre = document.createElement('pre');
                pre.className = 'al-detail-pre';
                pre.textContent = JSON.stringify(pair[1], null, 2);
                body.appendChild(pre);
            });
    }

    // -------------------------------------------------------------------------
    // Print button
    // -------------------------------------------------------------------------

    function initPrintButton() {
        document.querySelectorAll('[data-al-print-url]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var url = btn.getAttribute('data-al-print-url');
                if (url) { window.open(url, '_blank'); }
            });
        });
    }

}());
