/**
 * CronAdmin — admin page vanilla JS.
 *
 * Reads configuration from data-cra-* attributes on .cra-root.
 * Uses window.showNotification / window.showConfirm when the host provides them;
 * otherwise falls back to lightweight built-in implementations.
 *
 * All URL paths are constructed from data-cra-base-url to remain host-agnostic.
 */
(function () {
    'use strict';

    // =========================================================================
    // Bootstrap
    // =========================================================================

    const root = document.querySelector('.cra-root');
    if (!root) return;

    const BASE_URL        = root.dataset.craBaseUrl || '/admin/cron';
    const MANIFEST_BROKEN = root.dataset.craManifestBroken === '1';

    const I18N = {
        runConfirm  : root.dataset.craI18nRunConfirm   || 'Run this job now?',
        queued      : root.dataset.craI18nQueued        || 'Queued…',
        running     : root.dataset.craI18nRunning       || 'Still running — refresh later',
        saveSuccess : root.dataset.craI18nSaveSuccess   || 'Saved',
        errorGeneric: root.dataset.craI18nErrorGeneric  || 'An error occurred',
    };

    const POLL_INTERVAL_MS = 5000;
    const POLL_TIMEOUT_MS  = 180000;

    // =========================================================================
    // Host-provided helpers / built-in fallbacks
    // =========================================================================

    function notify(message, type) {
        if (typeof window.showNotification === 'function') {
            window.showNotification(message, type);
            return;
        }
        const el = document.createElement('div');
        el.className = 'cra-notification cra-notification--' + (type || 'info');
        el.textContent = message;
        document.body.appendChild(el);
        setTimeout(function () {
            el.classList.add('cra-notification--fade');
            setTimeout(function () { el.remove(); }, 400);
        }, 3000);
    }

    function confirmAction(message, callback) {
        if (typeof window.showConfirm === 'function') {
            window.showConfirm(message, callback);
            return;
        }
        if (window.confirm(message)) {
            callback();
        }
    }

    // =========================================================================
    // Job enable/disable toggle
    // =========================================================================

    root.addEventListener('change', function (e) {
        const cb = e.target;
        if (!cb.classList.contains('cra-job-toggle')) return;
        if (MANIFEST_BROKEN) { cb.checked = !cb.checked; return; }

        const jobId = cb.dataset.jobId;
        const csrf  = getCsrfToken();

        postJson(BASE_URL + '/' + jobId + '/toggle', { csrf_token: csrf })
            .then(function (data) {
                if (!data.success) {
                    cb.checked = !cb.checked;
                    notify(data.error || I18N.errorGeneric, 'error');
                }
            })
            .catch(function () {
                cb.checked = !cb.checked;
                notify(I18N.errorGeneric, 'error');
            });
    });

    // =========================================================================
    // Dispatcher kill-switch toggle
    // =========================================================================

    const dispatcherToggle = document.getElementById('craDispatcherEnabled');
    if (dispatcherToggle) {
        dispatcherToggle.addEventListener('change', function () {
            const csrf = this.dataset.csrf || getCsrfToken();
            const self = this;

            postJson(BASE_URL + '/dispatcher', { csrf_token: csrf })
                .then(function (data) {
                    if (!data.success) {
                        self.checked = !self.checked;
                        notify(data.error || I18N.errorGeneric, 'error');
                    }
                })
                .catch(function () {
                    self.checked = !self.checked;
                    notify(I18N.errorGeneric, 'error');
                });
        });
    }

    // =========================================================================
    // Output modal
    // =========================================================================

    const outputModal  = document.getElementById('craOutputModal');
    const outputPre    = document.getElementById('craOutputContent');
    const outputTitle  = document.getElementById('craOutputModalTitle');

    root.addEventListener('click', function (e) {
        const btn = e.target.closest('.cra-show-output');
        if (!btn) return;
        if (outputPre)   outputPre.textContent   = btn.dataset.output || '';
        if (outputTitle) outputTitle.textContent  = btn.dataset.label  || '';
        openModal(outputModal);
    });

    document.querySelectorAll('.cra-output-modal-close').forEach(function (btn) {
        btn.addEventListener('click', function () { closeModal(outputModal); });
    });

    // =========================================================================
    // Edit modal
    // =========================================================================

    const editModal = document.getElementById('craEditModal');
    const editForm  = document.getElementById('craEditForm');

    root.addEventListener('click', function (e) {
        const btn = e.target.closest('.cra-edit-job');
        if (!btn || MANIFEST_BROKEN) return;
        populateEditModal(btn.dataset.jobId);
        openModal(editModal);
    });

    document.querySelectorAll('.cra-modal-close-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            closeModal(this.closest('.cra-modal'));
        });
    });

    if (editForm) {
        const freqSel  = editForm.querySelector('#craFrequency');
        const domInput = editForm.querySelector('#craDaysOfMonth');
        const emailSel = editForm.querySelector('#craEmailReport');
        const logCb    = editForm.querySelector('.cra-log-to-db');

        if (freqSel)  freqSel.addEventListener('change',  function () { updateEditFields(freqSel.value); });
        if (domInput) domInput.addEventListener('input',   function () { checkDomWarn(domInput); });
        if (emailSel) emailSel.addEventListener('change',  function () { checkEmailWarn(); });
        if (logCb)    logCb.addEventListener('change',     function () { checkLogWarn(); });

        editForm.addEventListener('submit', function (e) {
            e.preventDefault();
            if (MANIFEST_BROKEN) return;

            const jobId = document.getElementById('craEditJobId').value;
            if (!jobId) return;

            const data = collectEditFormData();
            data.csrf_token = getCsrfToken();

            postJson(BASE_URL + '/' + jobId + '/save', data)
                .then(function (d) {
                    if (d.success) {
                        closeModal(editModal);
                        notify(d.message || I18N.saveSuccess, 'success');
                        updateRowDataAttrs(jobId, data);
                    } else {
                        notify(d.error || I18N.errorGeneric, 'error');
                    }
                })
                .catch(function () { notify(I18N.errorGeneric, 'error'); });
        });
    }

    // =========================================================================
    // Run Now
    // =========================================================================

    root.addEventListener('click', function (e) {
        const btn = e.target.closest('.cra-run-now');
        if (!btn || MANIFEST_BROKEN) return;

        const jobId = btn.dataset.jobId;
        const csrf  = btn.dataset.csrf || getCsrfToken();

        confirmAction(I18N.runConfirm, function () {
            btn.disabled = true;

            const statusEl = root.querySelector('.cra-run-status[data-job-id="' + jobId + '"]');
            if (statusEl) {
                statusEl.textContent = '';
                const spinner = document.createElement('span');
                spinner.className = 'cra-spinner';
                const msg = document.createElement('span');
                msg.className = 'cra-run-msg';
                msg.textContent = ' ' + I18N.queued;
                statusEl.appendChild(spinner);
                statusEl.appendChild(msg);
                statusEl.style.display = '';
            }

            postJson(BASE_URL + '/' + jobId + '/run-now', { csrf_token: csrf })
                .then(function (data) {
                    if (data.accepted) {
                        pollRunStatus(jobId, data.since_ts, statusEl, btn, Date.now());
                    } else {
                        if (statusEl) statusEl.style.display = 'none';
                        btn.disabled = false;
                        notify(data.error || I18N.errorGeneric, 'error');
                    }
                })
                .catch(function (err) {
                    if (statusEl) statusEl.style.display = 'none';
                    btn.disabled = false;
                    notify(err.message || I18N.errorGeneric, 'error');
                });
        });
    });

    function pollRunStatus(jobId, sinceTs, statusEl, btn, startMs) {
        if (Date.now() - startMs > POLL_TIMEOUT_MS) {
            if (statusEl) {
                statusEl.textContent = I18N.running;
                statusEl.style.display = '';
            }
            btn.disabled = false;
            return;
        }

        setTimeout(function () {
            fetch(BASE_URL + '/' + jobId + '/run-status?since_ts=' + encodeURIComponent(sinceTs), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.completed) {
                    if (statusEl) statusEl.style.display = 'none';
                    btn.disabled = false;
                    updateJobRowStatus(jobId, data);
                } else {
                    pollRunStatus(jobId, sinceTs, statusEl, btn, startMs);
                }
            })
            .catch(function () { pollRunStatus(jobId, sinceTs, statusEl, btn, startMs); });
        }, POLL_INTERVAL_MS);
    }

    function updateJobRowStatus(jobId, data) {
        const badge   = root.querySelector('.cra-status-badge[data-job-id="' + jobId + '"]');
        const lastRun = root.querySelector('.cra-last-run[data-job-id="' + jobId + '"]');

        if (badge && data.last_status) {
            badge.className = 'cra-badge cra-status-badge ' + statusBadgeClass(data.last_status);
            badge.textContent = data.last_status;
        }
        if (lastRun && data.last_run_at) {
            lastRun.textContent = data.last_run_at;
        }
    }

    function statusBadgeClass(status) {
        var map = { success: 'cra-badge--success', failure: 'cra-badge--danger', skipped: 'cra-badge--muted' };
        return map[status] || 'cra-badge--light';
    }

    // =========================================================================
    // Edit modal helpers
    // =========================================================================

    function populateEditModal(jobId) {
        const row = root.querySelector('.cra-job-row[data-job-id="' + jobId + '"]');
        document.getElementById('craEditJobId').value = jobId;

        var freq     = (row && row.dataset.frequency)    || 'daily';
        var everyN   = (row && row.dataset.everyN)        || '5';
        var hour     = (row && row.dataset.hour)          || '0';
        var minute   = (row && row.dataset.minute)        || '0';
        var dow      = (row && row.dataset.daysOfWeek)    || '';
        var dom      = (row && row.dataset.daysOfMonth)   || '';
        var logToDB  = (row && row.dataset.logToDb)       || '0';
        var emailRep = (row && row.dataset.emailReport)   || 'off';

        setVal('#craFrequency', freq);
        setVal('#craEveryN', everyN);
        setVal('#craHour', hour);
        setVal('#craMinute', minute);
        setVal('#craDaysOfMonth', dom);
        setVal('#craEmailReport', emailRep);

        var logCbEl = editForm.querySelector('.cra-log-to-db');
        if (logCbEl) logCbEl.checked = logToDB === '1';

        var selectedDows = dow ? dow.split(',') : [];
        editForm.querySelectorAll('.cra-dow-cb').forEach(function (cb) {
            cb.checked = selectedDows.includes(cb.dataset.dow);
        });

        updateEditFields(freq);
    }

    function collectEditFormData() {
        var freq     = editForm.querySelector('#craFrequency').value;
        var emailRep = editForm.querySelector('#craEmailReport').value;
        var logCbEl  = editForm.querySelector('.cra-log-to-db');
        var data     = { frequency: freq, email_report: emailRep };

        if (logCbEl && logCbEl.checked) data.log_to_db = '1';

        if (freq === 'every_n_minutes') {
            data.every_n_minutes = editForm.querySelector('#craEveryN').value;
        } else {
            data.minute = editForm.querySelector('#craMinute').value;
            if (freq !== 'hourly') data.hour = editForm.querySelector('#craHour').value;
            if (freq === 'weekly') {
                editForm.querySelectorAll('.cra-dow-cb:checked').forEach(function (cb) {
                    data['days_of_week_cb[' + cb.dataset.dow + ']'] = '1';
                });
            }
            if (freq === 'monthly') {
                data.days_of_month = editForm.querySelector('#craDaysOfMonth').value;
            }
        }

        return data;
    }

    function updateEditFields(freq) {
        ['every_n_minutes', 'hour', 'minute', 'days_of_week', 'days_of_month'].forEach(function (f) {
            editForm.querySelectorAll('.cra-field-' + f).forEach(function (el) { el.hidden = true; });
        });

        function show(name) {
            editForm.querySelectorAll('.cra-field-' + name).forEach(function (el) { el.hidden = false; });
        }

        switch (freq) {
            case 'every_n_minutes': show('every_n_minutes'); break;
            case 'hourly':          show('minute');           break;
            case 'daily':           show('hour'); show('minute'); break;
            case 'weekly':          show('hour'); show('minute'); show('days_of_week'); break;
            case 'monthly':         show('hour'); show('minute'); show('days_of_month'); break;
        }

        checkEmailWarn();
        checkLogWarn();
    }

    function checkDomWarn(input) {
        const warn = editForm.querySelector('.cra-dom-high-day-warning');
        if (!warn) return;
        var hasHigh = String(input.value).split(',').map(Number).some(function (d) { return d >= 29; });
        warn.hidden = !hasHigh;
    }

    function checkEmailWarn() {
        const warn   = editForm.querySelector('.cra-email-high-freq-warning');
        if (!warn) return;
        var freq   = editForm.querySelector('#craFrequency').value;
        var report = editForm.querySelector('#craEmailReport').value;
        var everyN = parseInt(editForm.querySelector('#craEveryN').value, 10) || 0;
        var isHigh = freq === 'hourly' || (freq === 'every_n_minutes' && everyN > 0 && everyN <= 60);
        warn.hidden = !(report === 'every_run' && isHigh);
    }

    function checkLogWarn() {
        const warn  = editForm.querySelector('.cra-log-high-freq-warning');
        const logCb = editForm.querySelector('.cra-log-to-db');
        if (!warn || !logCb) return;
        var freq   = editForm.querySelector('#craFrequency').value;
        var everyN = parseInt(editForm.querySelector('#craEveryN').value, 10) || 0;
        var isHigh = logCb.checked && freq === 'every_n_minutes' && everyN > 0 && everyN <= 15;
        warn.hidden = !isHigh;
    }

    function updateRowDataAttrs(jobId, data) {
        const row = root.querySelector('.cra-job-row[data-job-id="' + jobId + '"]');
        if (!row) return;
        if (data.frequency)       row.dataset.frequency   = data.frequency;
        if (data.every_n_minutes) row.dataset.everyN      = data.every_n_minutes;
        if (data.hour !== undefined)   row.dataset.hour   = data.hour;
        if (data.minute !== undefined) row.dataset.minute = data.minute;
        if (data.email_report)    row.dataset.emailReport = data.email_report;
        row.dataset.logToDb = data.log_to_db ? '1' : '0';
    }

    // =========================================================================
    // Modal primitives
    // =========================================================================

    function openModal(modal) {
        if (!modal) return;
        modal.hidden = false;
        modal.classList.add('cra-modal--open');
        document.body.classList.add('cra-body-modal-open');
    }

    function closeModal(modal) {
        if (!modal) return;
        modal.hidden = true;
        modal.classList.remove('cra-modal--open');
        document.body.classList.remove('cra-body-modal-open');
    }

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('.cra-modal--open').forEach(closeModal);
        }
    });

    // =========================================================================
    // Fetch helpers
    // =========================================================================

    function postJson(url, data) {
        const params = new URLSearchParams();
        Object.entries(data).forEach(function (pair) { params.append(pair[0], pair[1]); });
        return fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: params.toString(),
        })
        .then(function (r) {
            if (r.status === 409) {
                return r.json().then(function (d) { throw new Error(d.error || 'Conflict'); });
            }
            return r.json();
        });
    }

    function getCsrfToken() {
        const el = document.querySelector('#craEditCsrf, input[name="csrf_token"]');
        return el ? el.value : '';
    }

    function setVal(selector, value) {
        const el = editForm.querySelector(selector);
        if (el) el.value = value;
    }

})();
