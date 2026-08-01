/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * Backup & Restore admin UI — self-contained vanilla JS.
 * No dependency on Bootstrap, jQuery, or any external library.
 *
 * Expects `window.BackupRestoreConfig = { baseUrl, csrfToken, i18n, dbName }`
 * to be set by the host page before this file loads. `baseUrl` is the
 * module's mount path (no trailing slash), `i18n` is a flat key => string
 * map covering every TEXT_* key this file references (see locale/*.php).
 */

(function (window, document) {
    'use strict';

    const cfg = window.BackupRestoreConfig || {};
    const baseUrl = cfg.baseUrl || '';
    const i18n = cfg.i18n || {};

    function t(key) {
        return i18n[key] || key;
    }

    function api(path) {
        return baseUrl + path;
    }

    function jsonHeaders() {
        return { 'Content-Type': 'application/json', 'X-CSRF-Token': cfg.csrfToken || '' };
    }

    // ---------------------------------------------------------------------
    // Toast notifications (showNotification replacement)
    // ---------------------------------------------------------------------

    function ensureToastContainer() {
        let container = document.querySelector('.br-toast-container');
        if (!container) {
            container = document.createElement('div');
            container.className = 'br-toast-container';
            document.body.appendChild(container);
        }
        return container;
    }

    /**
     * @param {string} message
     * @param {'success'|'error'|'info'|'warning'} [type]
     */
    function notify(message, type) {
        const container = ensureToastContainer();
        const toast = document.createElement('div');
        toast.className = 'br-toast br-toast-' + (type || 'info');
        toast.textContent = message;
        container.appendChild(toast);
        setTimeout(function () {
            toast.remove();
        }, 4000);
    }

    // ---------------------------------------------------------------------
    // Confirm dialog (showConfirm replacement)
    // ---------------------------------------------------------------------

    /**
     * @param {string} message
     * @param {Function} onConfirm
     * @param {{danger?: boolean, confirmText?: string}} [options]
     */
    function confirmAction(message, onConfirm, options) {
        options = options || {};
        const backdrop = document.createElement('div');
        backdrop.className = 'br-modal-backdrop br-open';
        backdrop.innerHTML =
            '<div class="br-modal">' +
            '<div class="br-modal-body"><p class="br-mb-0">' + escapeHtml(message) + '</p></div>' +
            '<div class="br-modal-footer">' +
            '<button type="button" class="br-btn br-btn-secondary" data-role="cancel">' + escapeHtml(t('TEXT_BUTTON_CANCEL')) + '</button>' +
            '<button type="button" class="br-btn ' + (options.danger ? 'br-btn-danger' : 'br-btn-primary') + '" data-role="confirm">' +
            escapeHtml(options.confirmText || t('TEXT_BUTTON_CONTINUE')) + '</button>' +
            '</div></div>';
        document.body.appendChild(backdrop);

        function close() {
            backdrop.remove();
        }

        backdrop.querySelector('[data-role="cancel"]').addEventListener('click', close);
        backdrop.addEventListener('click', function (e) {
            if (e.target === backdrop) close();
        });
        backdrop.querySelector('[data-role="confirm"]').addEventListener('click', function () {
            close();
            onConfirm();
        });
    }

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = String(str);
        return div.innerHTML;
    }

    // ---------------------------------------------------------------------
    // Minimal modal show/hide (bootstrap.Modal replacement)
    // ---------------------------------------------------------------------

    function showModal(id) {
        const el = document.getElementById(id);
        if (el) el.classList.add('br-open');
    }

    function hideModal(id) {
        const el = document.getElementById(id);
        if (el) el.classList.remove('br-open');
    }

    function initModalDismissers() {
        document.querySelectorAll('[data-br-dismiss]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                hideModal(btn.getAttribute('data-br-dismiss'));
            });
        });
        document.querySelectorAll('.br-modal-backdrop[data-br-modal]').forEach(function (backdrop) {
            backdrop.addEventListener('click', function (e) {
                if (e.target === backdrop) backdrop.classList.remove('br-open');
            });
        });
    }

    // ---------------------------------------------------------------------
    // Collapse toggle (data-bs-toggle="collapse" replacement)
    // ---------------------------------------------------------------------

    function initCollapseToggles() {
        document.querySelectorAll('[data-br-collapse-target]').forEach(function (header) {
            header.addEventListener('click', function () {
                const targetId = header.getAttribute('data-br-collapse-target');
                const target = document.getElementById(targetId);
                if (target) target.classList.toggle('br-open');
            });
        });
    }

    // =======================================================================
    // Index page (backup dashboard)
    // =======================================================================

    let currentRestoreBackupId = null;
    let currentTransferBackupId = null;
    let restoreProgressInterval = null;
    let restoreProgressToken = null;

    const restoreStepLabels = {
        verify_archive: 'TEXT_RESTORE_STEP_VERIFY_ARCHIVE',
        extract_dump: 'TEXT_RESTORE_STEP_EXTRACT_DUMP',
        create_snapshot: 'TEXT_RESTORE_STEP_CREATE_SNAPSHOT',
        prepare_tables: 'TEXT_RESTORE_STEP_PREPARE_TABLES',
        import_db: 'TEXT_RESTORE_STEP_IMPORT_DB',
        verify_data: 'TEXT_RESTORE_STEP_VERIFY_DATA',
        finalize: 'TEXT_RESTORE_STEP_FINALIZE',
        create_temp_db: 'TEXT_RESTORE_STEP_CREATE_TEMP_DB',
        swap_databases: 'TEXT_RESTORE_STEP_SWAP_DATABASES',
        extract_files: 'TEXT_RESTORE_STEP_EXTRACT_FILES',
        pre_restore_snapshot: 'TEXT_RESTORE_STEP_PRE_RESTORE_SNAPSHOT',
        restore_files: 'TEXT_RESTORE_STEP_RESTORE_FILES',
    };

    function renderRestoreSteps(steps) {
        const container = document.getElementById('brRestoreStepList');
        if (!container || !steps || !steps.length) return;
        container.innerHTML = steps.map(function (step) {
            let icon;
            if (step.status === 'completed') icon = '<span class="br-text-success">&#10003;</span>';
            else if (step.status === 'active') icon = '<span class="br-spinner"></span>';
            else if (step.status === 'failed') icon = '<span class="br-text-danger">&#10007;</span>';
            else icon = '<span class="br-text-muted">&#9675;</span>';
            const label = t(restoreStepLabels[step.id] || step.id);
            const cls = step.status === 'pending' ? 'br-text-muted' : '';
            return '<div class="br-tree-row ' + cls + '">' + icon + ' <span>' + escapeHtml(label) + '</span></div>';
        }).join('');
    }

    function startRestoreProgressPolling() {
        restoreProgressToken = Math.random().toString(36).substring(2, 15);
        restoreProgressInterval = setInterval(function () {
            fetch(api('/restore-progress?token=' + restoreProgressToken))
                .then(function (r) { return r.json(); })
                .then(function (data) { if (data.steps && data.steps.length) renderRestoreSteps(data.steps); })
                .catch(function () {});
        }, 1500);
    }

    function stopRestoreProgressPolling() {
        if (restoreProgressInterval) {
            clearInterval(restoreProgressInterval);
            restoreProgressInterval = null;
        }
    }

    function createBackup() {
        const type = document.getElementById('brBackupType').value;
        const note = document.getElementById('brBackupNote').value;
        const btn = document.getElementById('brBtnCreateBackup');

        btn.disabled = true;
        const originalHtml = btn.innerHTML;
        btn.innerHTML = '<span class="br-spinner"></span> ' + escapeHtml(t('TEXT_MESSAGE_CREATING_BACKUP')) + '...';

        fetch(api('/create'), {
            method: 'POST', headers: jsonHeaders(), body: JSON.stringify({ type: type, note: note }),
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                btn.disabled = false;
                btn.innerHTML = originalHtml;

                if (data.success) {
                    notify(t('TEXT_MESSAGE_BACKUP_CREATED_SUCCESSFULLY'), 'success');
                    if (data.restore_token) {
                        document.getElementById('brRestoreTokenValue').value = data.restore_token;
                        document.getElementById('brTokenSavedCheck').checked = false;
                        document.getElementById('brBtnCloseTokenModal').disabled = true;
                        showModal('brRestoreTokenModal');
                    } else {
                        setTimeout(function () { location.reload(); }, 1500);
                    }
                } else {
                    notify(data.error || t('TEXT_ERROR_BACKUP_FAILED'), 'error');
                }
            })
            .catch(function () {
                btn.disabled = false;
                btn.innerHTML = originalHtml;
                notify(t('TEXT_ERROR_BACKUP_FAILED'), 'error');
            });
    }

    function downloadBackup(backupId) {
        fetch(api('/generate-download-token'), {
            method: 'POST', headers: jsonHeaders(), body: JSON.stringify({ backup_id: backupId }),
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.success && data.download_url) {
                    window.location.href = data.download_url;
                } else {
                    notify(data.error || t('TEXT_ERROR_DOWNLOAD_FAILED'), 'error');
                }
            });
    }

    function deleteBackupFile(backupId) {
        confirmAction(t('TEXT_CONFIRM_DELETE_BACKUP_FILE'), function () {
            fetch(api('/delete-file'), { method: 'POST', headers: jsonHeaders(), body: JSON.stringify({ id: backupId }) })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.success) {
                        notify(t('TEXT_MESSAGE_BACKUP_FILE_DELETED'), 'success');
                        setTimeout(function () { location.reload(); }, 1000);
                    } else {
                        notify(data.error, 'error');
                    }
                });
        }, { danger: true, confirmText: t('TEXT_BUTTON_DELETE') });
    }

    function deleteBackupFull(backupId) {
        confirmAction(t('TEXT_CONFIRM_DELETE_BACKUP_FULL'), function () {
            fetch(api('/delete-full'), { method: 'POST', headers: jsonHeaders(), body: JSON.stringify({ id: backupId }) })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.success) {
                        notify(t('TEXT_MESSAGE_BACKUP_DELETED'), 'success');
                        setTimeout(function () { location.reload(); }, 1000);
                    } else {
                        notify(data.error, 'error');
                    }
                });
        }, { danger: true, confirmText: t('TEXT_BUTTON_DELETE') });
    }

    function restoreBackup(backupId, type) {
        currentRestoreBackupId = backupId;

        document.getElementById('brRestoreStep1').style.display = 'block';
        document.getElementById('brRestoreStep2').style.display = 'none';
        document.getElementById('brRestoreStep3').style.display = 'none';
        document.getElementById('brRestoreStep4').style.display = 'none';
        document.getElementById('brRestoreStepList').innerHTML = '';
        stopRestoreProgressPolling();
        document.getElementById('brDbNameConfirm').value = '';
        document.getElementById('brRestorePassword').value = '';
        document.getElementById('brBtnRestoreStep1').disabled = true;

        const restoreTypeSelect = document.getElementById('brRestoreType');
        restoreTypeSelect.querySelectorAll('option').forEach(function (o) { o.disabled = false; });
        if (type === 'database') {
            restoreTypeSelect.value = 'database';
            restoreTypeSelect.querySelectorAll('option[value="files"], option[value="full"]').forEach(function (o) { o.disabled = true; });
        } else if (type === 'files') {
            restoreTypeSelect.value = 'files';
            restoreTypeSelect.querySelectorAll('option[value="database"], option[value="full"]').forEach(function (o) { o.disabled = true; });
        } else {
            restoreTypeSelect.value = 'full';
        }

        updateRestoreStep1Button();
        showModal('brRestoreModal');
    }

    function updateRestoreStep1Button() {
        const btn = document.getElementById('brBtnRestoreStep1');
        const restoreType = document.getElementById('brRestoreType').value;
        const dbValue = document.getElementById('brDbNameConfirm').value.trim();
        const expectedDbName = cfg.dbName || '';

        if (restoreType === 'files') {
            btn.disabled = false;
            btn.textContent = t('TEXT_BUTTON_CONTINUE');
        } else if (dbValue === '') {
            btn.disabled = true;
            btn.textContent = t('TEXT_BUTTON_CONTINUE');
        } else if (dbValue !== expectedDbName) {
            btn.disabled = true;
            btn.textContent = t('TEXT_ERROR_INVALID_DATABASE_NAME');
        } else {
            btn.disabled = false;
            btn.textContent = t('TEXT_BUTTON_CONTINUE');
        }
    }

    function restoreStep2() {
        document.getElementById('brRestoreStep1').style.display = 'none';
        document.getElementById('brRestoreStep2').style.display = 'block';
        document.getElementById('brRestorePassword').focus();
    }

    function verifyRestorePassword() {
        const password = document.getElementById('brRestorePassword').value;
        if (!password) {
            notify(t('TEXT_ERROR_INVALID_PASSWORD'), 'error');
            return;
        }

        fetch(api('/verify-password'), { method: 'POST', headers: jsonHeaders(), body: JSON.stringify({ password: password }) })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.success) {
                    document.getElementById('brRestoreStep2').style.display = 'none';
                    document.getElementById('brRestoreStep3').style.display = 'block';

                    let countdown = 5;
                    const btn = document.getElementById('brBtnExecuteRestore');
                    const countdownSpan = document.getElementById('brRestoreCountdown');
                    const interval = setInterval(function () {
                        countdown--;
                        countdownSpan.textContent = t('TEXT_BUTTON_RESTORE') + ' (' + countdown + ')';
                        if (countdown <= 0) {
                            clearInterval(interval);
                            btn.disabled = false;
                            countdownSpan.textContent = t('TEXT_BUTTON_RESTORE') + ' (' + t('TEXT_LABEL_IRREVERSIBLE') + ')';
                        }
                    }, 1000);
                } else {
                    notify(data.error || t('TEXT_ERROR_INVALID_PASSWORD'), 'error');
                }
            });
    }

    function executeRestore() {
        document.getElementById('brRestoreStep3').style.display = 'none';
        document.getElementById('brRestoreStep4').style.display = 'block';
        document.getElementById('brRestoreStepList').innerHTML = '';

        startRestoreProgressPolling();

        const restoreType = document.getElementById('brRestoreType').value;
        const dbNameConfirm = document.getElementById('brDbNameConfirm').value;

        fetch(api('/restore'), {
            method: 'POST', headers: jsonHeaders(),
            body: JSON.stringify({
                backup_id: currentRestoreBackupId, restore_type: restoreType,
                db_name_confirm: dbNameConfirm, progress_token: restoreProgressToken,
            }),
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                stopRestoreProgressPolling();
                if (data.progress && data.progress.length) renderRestoreSteps(data.progress);
                if (data.success) {
                    notify(data.message || t('TEXT_MESSAGE_BACKUP_RESTORED'), 'success');
                } else {
                    notify(data.error || t('TEXT_ERROR_RESTORE_FAILED'), 'error');
                }
            })
            .catch(function () {
                stopRestoreProgressPolling();
                notify(t('TEXT_ERROR_RESTORE_FAILED'), 'error');
            });
    }

    function transferBackup(backupId) {
        currentTransferBackupId = backupId;

        fetch(api('/remote-servers'), { headers: { Accept: 'application/json' } })
            .then(function (r) {
                const ct = r.headers.get('content-type') || '';
                return ct.includes('application/json') ? r.json() : null;
            })
            .then(function (data) {
                const select = document.getElementById('brTransferServerId');
                select.innerHTML = '<option value="">' + escapeHtml(t('TEXT_PLACEHOLDER_SELECT_SERVER')) + '</option>';
                if (data && Array.isArray(data)) {
                    data.forEach(function (server) {
                        const opt = document.createElement('option');
                        opt.value = server.id;
                        opt.textContent = server.name + ' (' + server.host + ')';
                        select.appendChild(opt);
                    });
                }
            });

        document.getElementById('brTransferProgress').style.display = 'none';
        showModal('brTransferModal');
    }

    function executeTransfer() {
        const serverId = document.getElementById('brTransferServerId').value;
        if (!serverId) {
            notify(t('TEXT_ERROR_SELECT_SERVER'), 'error');
            return;
        }

        document.getElementById('brBtnTransfer').disabled = true;
        document.getElementById('brTransferProgress').style.display = 'block';

        fetch(api('/transfer'), {
            method: 'POST', headers: jsonHeaders(),
            body: JSON.stringify({ backup_id: currentTransferBackupId, server_id: parseInt(serverId, 10) }),
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                document.getElementById('brBtnTransfer').disabled = false;
                if (data.success) {
                    document.getElementById('brTransferProgressBar').style.width = '100%';
                    document.getElementById('brTransferProgressText').textContent = t('TEXT_MESSAGE_TRANSFER_COMPLETE');
                    notify(t('TEXT_MESSAGE_TRANSFER_COMPLETE'), 'success');
                    setTimeout(function () {
                        hideModal('brTransferModal');
                        location.reload();
                    }, 1500);
                } else {
                    notify(data.error || t('TEXT_ERROR_TRANSFER_FAILED'), 'error');
                }
            })
            .catch(function () {
                document.getElementById('brBtnTransfer').disabled = false;
                notify(t('TEXT_ERROR_TRANSFER_FAILED'), 'error');
            });
    }

    function uploadRestoreFile() {
        document.getElementById('brUploadBackupFile').value = '';
        document.getElementById('brUploadProgress').style.display = 'none';
        showModal('brUploadRestoreModal');
    }

    function executeUploadRestore() {
        const fileInput = document.getElementById('brUploadBackupFile');
        const file = fileInput.files[0];

        if (!file) {
            notify(t('TEXT_ERROR_SELECT_FILE'), 'error');
            return;
        }
        if (!file.name.endsWith('.tgz')) {
            notify(t('TEXT_HELP_ONLY_TGZ_FILES'), 'error');
            return;
        }

        document.getElementById('brBtnUploadRestore').disabled = true;
        document.getElementById('brUploadProgress').style.display = 'block';

        const formData = new FormData();
        formData.append('backup_file', file);
        formData.append('csrf_token', cfg.csrfToken || '');

        const xhr = new XMLHttpRequest();
        xhr.open('POST', api('/upload-restore'));
        xhr.setRequestHeader('X-CSRF-Token', cfg.csrfToken || '');

        xhr.upload.addEventListener('progress', function (e) {
            if (e.lengthComputable) {
                const percent = Math.round((e.loaded / e.total) * 100);
                document.getElementById('brUploadProgressBar').style.width = percent + '%';
            }
        });

        xhr.onload = function () {
            document.getElementById('brBtnUploadRestore').disabled = false;
            try {
                const data = JSON.parse(xhr.responseText);
                if (data.success) {
                    hideModal('brUploadRestoreModal');
                    notify(t('TEXT_MESSAGE_FILE_UPLOADED'), 'success');
                    restoreBackup(data.backup_id, 'full');
                } else {
                    notify(data.error || t('TEXT_ERROR_UPLOAD_FAILED'), 'error');
                }
            } catch (e) {
                notify(t('TEXT_ERROR_UPLOAD_FAILED'), 'error');
            }
        };

        xhr.onerror = function () {
            document.getElementById('brBtnUploadRestore').disabled = false;
            notify(t('TEXT_ERROR_UPLOAD_FAILED'), 'error');
        };

        xhr.send(formData);
    }

    function copyRestoreToken() {
        const input = document.getElementById('brRestoreTokenValue');
        navigator.clipboard.writeText(input.value).then(function () {
            notify(t('TEXT_MESSAGE_COPIED_TO_CLIPBOARD'), 'success');
        });
    }

    // =======================================================================
    // Profiles page
    // =======================================================================

    let directoryTreeLoaded = false;
    let profilesData = [];

    function openProfileModal() {
        document.getElementById('brProfileId').value = '0';
        document.getElementById('brProfileModalTitle').textContent = t('TEXT_HEADING_CREATE_PROFILE');
        document.getElementById('brProfileName').value = '';
        document.getElementById('brProfileDescription').value = '';
        document.getElementById('brProfileType').value = 'full';
        document.getElementById('brProfileScheduleEnabled').checked = false;
        document.getElementById('brProfileScheduleType').value = 'daily';
        document.getElementById('brProfileScheduleTime').value = '02:00';
        document.getElementById('brProfileRemoteServer').value = '';
        document.getElementById('brProfileRetentionDays').value = '30';
        document.getElementById('brProfileIsActive').value = '1';

        toggleSchedule();
        loadDirectoryTree();
        showModal('brProfileModal');
    }

    function editProfile(id) {
        const profile = profilesData.find(function (p) { return p.id == id; }); // eslint-disable-line eqeqeq
        if (!profile) return;

        document.getElementById('brProfileId').value = profile.id;
        document.getElementById('brProfileModalTitle').textContent = t('TEXT_HEADING_EDIT_PROFILE');
        document.getElementById('brProfileName').value = profile.name;
        document.getElementById('brProfileDescription').value = profile.description || '';
        document.getElementById('brProfileType').value = profile.type;
        document.getElementById('brProfileScheduleEnabled').checked = !!profile.schedule_enabled;
        document.getElementById('brProfileScheduleType').value = profile.schedule_type || 'daily';
        document.getElementById('brProfileScheduleTime').value = profile.schedule_time ? profile.schedule_time.substring(0, 5) : '02:00';
        document.getElementById('brProfileScheduleDay').value = profile.schedule_day || '0';
        document.getElementById('brProfileRemoteServer').value = profile.remote_server_id || '';
        document.getElementById('brProfileRetentionDays').value = profile.retention_days || 30;
        document.getElementById('brProfileIsActive').value = profile.is_active ? '1' : '0';

        toggleSchedule();
        loadDirectoryTree(profile.excluded_paths ? JSON.parse(profile.excluded_paths) : []);
        showModal('brProfileModal');
    }

    function toggleSchedule() {
        const enabled = document.getElementById('brProfileScheduleEnabled').checked;
        document.querySelectorAll('.br-schedule-fields').forEach(function (el) {
            el.style.display = enabled ? 'block' : 'none';
        });
        toggleScheduleDay();
    }

    function toggleScheduleDay() {
        const enabled = document.getElementById('brProfileScheduleEnabled').checked;
        const type = document.getElementById('brProfileScheduleType').value;
        const dayField = document.querySelector('.br-schedule-day-field');
        const dayLabel = document.getElementById('brScheduleDayLabel');
        const daySelect = document.getElementById('brProfileScheduleDay');

        if (!enabled || type === 'daily') {
            dayField.style.display = 'none';
            return;
        }

        dayField.style.display = 'block';

        if (type === 'weekly') {
            dayLabel.textContent = t('TEXT_LABEL_DAY_OF_WEEK');
            const days = ['TEXT_DAY_SUNDAY', 'TEXT_DAY_MONDAY', 'TEXT_DAY_TUESDAY', 'TEXT_DAY_WEDNESDAY', 'TEXT_DAY_THURSDAY', 'TEXT_DAY_FRIDAY', 'TEXT_DAY_SATURDAY'];
            daySelect.innerHTML = days.map(function (key, i) {
                return '<option value="' + i + '">' + escapeHtml(t(key)) + '</option>';
            }).join('');
        } else if (type === 'monthly') {
            dayLabel.textContent = t('TEXT_LABEL_DAY_OF_MONTH');
            let options = '';
            for (let i = 1; i <= 28; i++) {
                options += '<option value="' + i + '">' + i + '</option>';
            }
            daySelect.innerHTML = options;
        }
    }

    function loadDirectoryTree(excludedPaths) {
        const container = document.getElementById('brDirectoryTree');
        container.innerHTML = '<div class="br-empty-row"><span class="br-spinner"></span> ' + escapeHtml(t('TEXT_LABEL_LOADING')) + '...</div>';

        fetch(api('/directory-tree'))
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.success && data.tree) {
                    container.innerHTML = '';
                    renderTreeItems(container, data.tree, excludedPaths || []);
                    directoryTreeLoaded = true;
                } else {
                    container.innerHTML = '<div class="br-text-danger">' + escapeHtml(t('TEXT_ERROR_LOADING_DIRECTORY_TREE')) + '</div>';
                }
            })
            .catch(function () {
                container.innerHTML = '<div class="br-text-danger">' + escapeHtml(t('TEXT_ERROR_LOADING_DIRECTORY_TREE')) + '</div>';
            });
    }

    function renderTreeItems(container, items, excludedPaths) {
        items.forEach(function (item) {
            const div = document.createElement('div');
            div.className = 'br-tree-item';

            const row = document.createElement('div');
            row.className = 'br-tree-row';

            if (item.has_children) {
                const toggle = document.createElement('span');
                toggle.className = 'br-tree-toggle';
                toggle.textContent = '▸';
                toggle.onclick = function () {
                    const childContainer = div.querySelector('.br-tree-children');
                    if (childContainer) {
                        const nowHidden = childContainer.style.display === 'none';
                        childContainer.style.display = nowHidden ? 'block' : 'none';
                        toggle.textContent = nowHidden ? '▾' : '▸';
                    } else {
                        loadSubTree(div, item.path, excludedPaths);
                        toggle.textContent = '▾';
                    }
                };
                row.appendChild(toggle);
            } else {
                const spacer = document.createElement('span');
                spacer.className = 'br-tree-spacer';
                row.appendChild(spacer);
            }

            const checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.dataset.path = item.path;

            if (item.is_locked) {
                checkbox.checked = false;
                checkbox.disabled = true;
                checkbox.title = t('TEXT_HELP_ALWAYS_EXCLUDED');
            } else {
                const isExcluded = excludedPaths.includes(item.path) || item.is_default_excluded;
                checkbox.checked = !isExcluded;
            }

            checkbox.onchange = function () {
                const childCheckboxes = div.querySelectorAll('.br-tree-children input[type="checkbox"]:not(:disabled)');
                childCheckboxes.forEach(function (cb) { cb.checked = checkbox.checked; });
            };

            row.appendChild(checkbox);

            const label = document.createElement('span');
            label.textContent = item.name;
            if (item.is_locked) label.className = 'br-tree-locked';
            row.appendChild(label);

            if (item.is_locked) {
                const badge = document.createElement('span');
                badge.className = 'br-badge br-badge-secondary br-small';
                badge.textContent = t('TEXT_LABEL_LOCKED');
                row.appendChild(badge);
            }

            div.appendChild(row);
            container.appendChild(div);
        });
    }

    function loadSubTree(parentDiv, path, excludedPaths) {
        const childContainer = document.createElement('div');
        childContainer.className = 'br-tree-children';
        childContainer.innerHTML = '<span class="br-spinner"></span>';
        parentDiv.appendChild(childContainer);

        fetch(api('/directory-tree?path=' + encodeURIComponent(path)))
            .then(function (r) { return r.json(); })
            .then(function (data) {
                childContainer.innerHTML = '';
                if (data.success && data.tree) renderTreeItems(childContainer, data.tree, excludedPaths);
            });
    }

    function getExcludedPaths() {
        const excluded = [];
        document.querySelectorAll('#brDirectoryTree input[type="checkbox"]:not(:disabled)').forEach(function (cb) {
            if (!cb.checked) excluded.push(cb.dataset.path);
        });
        return excluded;
    }

    function saveProfile() {
        const id = parseInt(document.getElementById('brProfileId').value, 10);
        const name = document.getElementById('brProfileName').value.trim();

        if (!name) {
            notify(t('TEXT_ERROR_NAME_REQUIRED'), 'error');
            return;
        }

        const excludedPaths = getExcludedPaths();

        const data = {
            id: id,
            name: name,
            description: document.getElementById('brProfileDescription').value,
            type: document.getElementById('brProfileType').value,
            include_database: 1,
            excluded_paths: excludedPaths.length > 0 ? excludedPaths : null,
            remote_server_id: document.getElementById('brProfileRemoteServer').value || null,
            schedule_enabled: document.getElementById('brProfileScheduleEnabled').checked ? 1 : 0,
            schedule_type: document.getElementById('brProfileScheduleType').value,
            schedule_time: document.getElementById('brProfileScheduleTime').value + ':00',
            schedule_day: parseInt(document.getElementById('brProfileScheduleDay').value, 10),
            retention_days: parseInt(document.getElementById('brProfileRetentionDays').value, 10) || 30,
            is_active: parseInt(document.getElementById('brProfileIsActive').value, 10),
        };

        fetch(api('/profiles/save'), { method: 'POST', headers: jsonHeaders(), body: JSON.stringify(data) })
            .then(function (r) { return r.json(); })
            .then(function (result) {
                if (result.success) {
                    notify(t('TEXT_MESSAGE_PROFILE_SAVED'), 'success');
                    hideModal('brProfileModal');
                    setTimeout(function () { location.reload(); }, 1000);
                } else {
                    notify(result.error || t('TEXT_ERROR_SAVE_FAILED'), 'error');
                }
            });
    }

    function deleteProfile(id) {
        confirmAction(t('TEXT_CONFIRM_DELETE_PROFILE'), function () {
            fetch(api('/profiles/delete'), { method: 'POST', headers: jsonHeaders(), body: JSON.stringify({ id: id }) })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.success) {
                        notify(t('TEXT_MESSAGE_PROFILE_DELETED'), 'success');
                        setTimeout(function () { location.reload(); }, 1000);
                    } else {
                        notify(data.error, 'error');
                    }
                });
        }, { danger: true, confirmText: t('TEXT_BUTTON_DELETE') });
    }

    function runProfile(id) {
        confirmAction(t('TEXT_CONFIRM_RUN_PROFILE'), function () {
            const profile = profilesData.find(function (p) { return p.id == id; }); // eslint-disable-line eqeqeq
            notify(t('TEXT_MESSAGE_CREATING_BACKUP') + '...', 'info');

            fetch(api('/create'), {
                method: 'POST', headers: jsonHeaders(),
                body: JSON.stringify({
                    type: profile ? profile.type : 'full',
                    note: 'Manual run: ' + (profile ? profile.name : 'Profile #' + id),
                }),
            })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.success) {
                        notify(t('TEXT_MESSAGE_BACKUP_CREATED_SUCCESSFULLY'), 'success');
                        setTimeout(function () { location.reload(); }, 1500);
                    } else {
                        notify(data.error || t('TEXT_ERROR_BACKUP_FAILED'), 'error');
                    }
                });
        }, { confirmText: t('TEXT_BUTTON_RUN_NOW') });
    }

    // =======================================================================
    // Remote servers page
    // =======================================================================

    let serversData = [];

    function toggleAuthFields() {
        const authType = document.getElementById('brServerAuthType').value;
        document.getElementById('brPasswordField').style.display = authType === 'password' ? 'block' : 'none';
        document.getElementById('brKeyField').style.display = authType === 'key' ? 'block' : 'none';
    }

    function openServerModal() {
        document.getElementById('brServerId').value = '0';
        document.getElementById('brServerModalTitle').textContent = t('TEXT_HEADING_ADD_SERVER');
        document.getElementById('brServerName').value = '';
        document.getElementById('brServerType').value = 'sftp';
        document.getElementById('brServerHost').value = '';
        document.getElementById('brServerPort').value = '22';
        document.getElementById('brServerUsername').value = '';
        document.getElementById('brServerAuthType').value = 'password';
        document.getElementById('brServerCredentials').value = '';
        document.getElementById('brServerKeyCredentials').value = '';
        document.getElementById('brServerRemotePath').value = '/backups';
        document.getElementById('brServerIsActive').value = '1';
        document.getElementById('brCredentialsHelp').style.display = 'none';

        toggleAuthFields();
        showModal('brServerModal');
    }

    function editServer(id) {
        const server = serversData.find(function (s) { return s.id == id; }); // eslint-disable-line eqeqeq
        if (!server) return;

        document.getElementById('brServerId').value = server.id;
        document.getElementById('brServerModalTitle').textContent = t('TEXT_HEADING_EDIT_SERVER');
        document.getElementById('brServerName').value = server.name;
        document.getElementById('brServerType').value = server.type;
        document.getElementById('brServerHost').value = server.host;
        document.getElementById('brServerPort').value = server.port;
        document.getElementById('brServerUsername').value = server.username;
        document.getElementById('brServerAuthType').value = server.auth_type;
        document.getElementById('brServerCredentials').value = '';
        document.getElementById('brServerKeyCredentials').value = '';
        document.getElementById('brServerRemotePath').value = server.remote_path;
        document.getElementById('brServerIsActive').value = server.is_active ? '1' : '0';
        document.getElementById('brCredentialsHelp').style.display = 'block';

        toggleAuthFields();
        showModal('brServerModal');
    }

    function saveServer() {
        const id = parseInt(document.getElementById('brServerId').value, 10);
        const name = document.getElementById('brServerName').value.trim();
        const host = document.getElementById('brServerHost').value.trim();
        const username = document.getElementById('brServerUsername').value.trim();

        if (!name || !host || !username) {
            notify(t('TEXT_ERROR_REQUIRED_FIELDS'), 'error');
            return;
        }

        const authType = document.getElementById('brServerAuthType').value;
        const credentials = authType === 'key'
            ? document.getElementById('brServerKeyCredentials').value
            : document.getElementById('brServerCredentials').value;

        const data = {
            id: id, name: name, type: document.getElementById('brServerType').value, host: host,
            port: parseInt(document.getElementById('brServerPort').value, 10) || 22,
            username: username, auth_type: authType, credentials: credentials,
            remote_path: document.getElementById('brServerRemotePath').value || '/backups',
            is_active: parseInt(document.getElementById('brServerIsActive').value, 10),
        };

        fetch(api('/remote-servers/save'), { method: 'POST', headers: jsonHeaders(), body: JSON.stringify(data) })
            .then(function (r) { return r.json(); })
            .then(function (result) {
                if (result.success) {
                    notify(t('TEXT_MESSAGE_SERVER_SAVED'), 'success');
                    hideModal('brServerModal');
                    setTimeout(function () { location.reload(); }, 1000);
                } else {
                    notify(result.error || t('TEXT_ERROR_SAVE_FAILED'), 'error');
                }
            });
    }

    function testServer(id) {
        notify(t('TEXT_MESSAGE_TESTING_CONNECTION') + '...', 'info');

        fetch(api('/remote-servers/test'), { method: 'POST', headers: jsonHeaders(), body: JSON.stringify({ id: id }) })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.success) {
                    notify(data.message || t('TEXT_MESSAGE_CONNECTION_SUCCESS'), 'success');
                } else {
                    notify(data.error || t('TEXT_ERROR_CONNECTION_FAILED'), 'error');
                }
            })
            .catch(function () {
                notify(t('TEXT_ERROR_CONNECTION_FAILED'), 'error');
            });
    }

    function deleteServer(id) {
        confirmAction(t('TEXT_CONFIRM_DELETE_SERVER'), function () {
            fetch(api('/remote-servers/delete'), { method: 'POST', headers: jsonHeaders(), body: JSON.stringify({ id: id }) })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.success) {
                        notify(t('TEXT_MESSAGE_SERVER_DELETED'), 'success');
                        setTimeout(function () { location.reload(); }, 1000);
                    } else {
                        notify(data.error, 'error');
                    }
                });
        }, { danger: true, confirmText: t('TEXT_BUTTON_DELETE') });
    }

    // ---------------------------------------------------------------------
    // Public namespace + auto-init
    // ---------------------------------------------------------------------

    window.BackupRestoreUI = {
        notify: notify,
        confirm: confirmAction,
        showModal: showModal,
        hideModal: hideModal,
        setProfilesData: function (data) { profilesData = data || []; },
        setServersData: function (data) { serversData = data || []; },
        // index page
        createBackup: createBackup,
        downloadBackup: downloadBackup,
        deleteBackupFile: deleteBackupFile,
        deleteBackupFull: deleteBackupFull,
        restoreBackup: restoreBackup,
        updateRestoreStep1Button: updateRestoreStep1Button,
        restoreStep2: restoreStep2,
        verifyRestorePassword: verifyRestorePassword,
        executeRestore: executeRestore,
        transferBackup: transferBackup,
        executeTransfer: executeTransfer,
        uploadRestoreFile: uploadRestoreFile,
        executeUploadRestore: executeUploadRestore,
        copyRestoreToken: copyRestoreToken,
        // profiles page
        openProfileModal: openProfileModal,
        editProfile: editProfile,
        toggleSchedule: toggleSchedule,
        toggleScheduleDay: toggleScheduleDay,
        saveProfile: saveProfile,
        deleteProfile: deleteProfile,
        runProfile: runProfile,
        // remote servers page
        toggleAuthFields: toggleAuthFields,
        openServerModal: openServerModal,
        editServer: editServer,
        saveServer: saveServer,
        testServer: testServer,
        deleteServer: deleteServer,
    };

    document.addEventListener('DOMContentLoaded', function () {
        initModalDismissers();
        initCollapseToggles();

        const dbNameInput = document.getElementById('brDbNameConfirm');
        if (dbNameInput) dbNameInput.addEventListener('input', updateRestoreStep1Button);

        const restoreTypeSelect = document.getElementById('brRestoreType');
        if (restoreTypeSelect) {
            restoreTypeSelect.addEventListener('change', function () {
                const dbInput = document.getElementById('brDbNameConfirm');
                if (this.value === 'files') {
                    dbInput.disabled = true;
                    dbInput.value = '';
                } else {
                    dbInput.disabled = false;
                }
                updateRestoreStep1Button();
            });
        }

        const tokenSavedCheck = document.getElementById('brTokenSavedCheck');
        if (tokenSavedCheck) {
            tokenSavedCheck.addEventListener('change', function () {
                document.getElementById('brBtnCloseTokenModal').disabled = !this.checked;
            });
        }

        const closeTokenBtn = document.getElementById('brBtnCloseTokenModal');
        if (closeTokenBtn) {
            closeTokenBtn.addEventListener('click', function () {
                hideModal('brRestoreTokenModal');
                location.reload();
            });
        }
    });
})(window, document);
