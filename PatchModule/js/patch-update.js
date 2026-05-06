/**
 * Patch Update Manager
 *
 * Handles the update banner interactions, modal state management,
 * password verification, patch installation, and progress polling.
 * Supports sequential multi-patch installation with queue tracking.
 */

/**
 * Escape a string for safe insertion into HTML.
 * Hosts that already provide a global escapeHtml are not overridden.
 *
 * @param {*} str
 * @returns {string}
 */
if (typeof escapeHtml !== 'function') {
    var escapeHtml = function (str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    };
}

/**
 * Show a notification to the user.
 * Hosts that already provide a global showNotification are not overridden.
 * The built-in fallback writes to the console only.
 *
 * @param {string} message
 * @param {'info'|'error'|'success'|'warning'} type
 */
if (typeof showNotification !== 'function') {
    var showNotification = function (message, type) {
        console.warn('[PatchUpdate] ' + type + ': ' + message);
    };
}

const PatchUpdate = {
    /** @type {bootstrap.Modal|null} Modal instance */
    modal: null,

    /** @type {string|null} Progress polling token */
    progressToken: null,

    /** @type {number|null} Polling interval ID */
    pollInterval: null,

    /** @type {boolean} Whether installation is in progress */
    installing: false,

    /** @type {Array<Object>} Full patch queue from server */
    patches: [],

    /** @type {number} Index of the currently active patch in the queue */
    currentPatchIndex: 0,

    /** @type {number} Total number of patches in the queue */
    totalPatches: 0,

    /** @type {number} Number of successfully installed patches in this session */
    installedCount: 0,

    /**
     * Step labels for progress display
     * @type {Object<string, string>}
     */
    stepLabels: {},

    /**
     * Queue status labels
     * @type {Object<string, string>}
     */
    queueLabels: {},

    /** @type {string} Base URL for patch-management routes (no trailing slash) */
    baseUrl: '',

    /** @type {string} CSRF token (may be rotated after verifyPassword) */
    csrfToken: '',

    /**
     * Error code labels for user-facing messages
     * @type {Object<string, string>}
     */
    errorLabels: {},

    /**
     * i18n strings read from data-i18n attribute
     * @type {Object<string, string>}
     */
    i18n: {},

    /** @type {string|null} One-time install authorization token; cleared after each install POST */
    installToken: null,

    /**
     * Initialize configuration from the mount element's data attributes
     */
    init: function () {
        var mount = document.getElementById('patch-mount') || document.getElementById('patchUpdateBanner');
        if (!mount) return;
        this.baseUrl     = (mount.dataset.baseUrl || '').replace(/\/$/, '');
        this.csrfToken   = mount.dataset.csrfToken || '';
        this.stepLabels  = JSON.parse(mount.dataset.stepLabels  || '{}');
        this.queueLabels = JSON.parse(mount.dataset.queueLabels || '{}');
        this.errorLabels = JSON.parse(mount.dataset.errorLabels || '{}');
        this.i18n        = JSON.parse(mount.dataset.i18n        || '{}');
    },

    /**
     * Show the patch details modal
     * Fetches latest details from the server and populates the modal.
     */
    showDetails: function () {
        fetch(this.baseUrl + '/details', {
            headers: { 'Accept': 'application/json', 'X-CSRF-Token': this.csrfToken }
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.available) {
                    showNotification('No update available', 'info');
                    return;
                }

                PatchUpdate.patches = data.patches || [];
                PatchUpdate.totalPatches = data.count || PatchUpdate.patches.length;
                PatchUpdate.currentPatchIndex = 0;
                PatchUpdate.installedCount = 0;

                // Render queue panel (only for multi-patch)
                PatchUpdate.renderQueue();

                // Populate details with first patch
                PatchUpdate.populatePatchDetails(PatchUpdate.patches[0], data.current_version);

                // Update install button text
                PatchUpdate.updateInstallButton();

                // Reset to details state
                PatchUpdate.switchState('details');

                // Show modal
                if (!PatchUpdate.modal) {
                    PatchUpdate.modal = new bootstrap.Modal(document.getElementById('patchUpdateModal'));
                }
                PatchUpdate.modal.show();
            })
            .catch(function () {
                showNotification('Failed to load update details', 'error');
            });
    },

    /**
     * Populate modal details with a specific patch's data
     * @param {Object} patch - Patch data object
     * @param {string} currentVersion - Current app version
     */
    populatePatchDetails: function (patch, currentVersion) {
        document.getElementById('patchCurrentVersion').textContent = 'v' + (currentVersion || '-');
        document.getElementById('patchNewVersion').textContent = 'v' + (patch.version || '-');

        // File size
        var sizeEl = document.getElementById('patchFileSize');
        sizeEl.innerHTML = '<i class="bi bi-file-earmark-zip me-1"></i>' + PatchUpdate.formatFileSize(patch.file_size || 0);

        // Release date
        var dateEl = document.getElementById('patchReleasedAt');
        if (patch.released_at) {
            var d = new Date(patch.released_at);
            dateEl.innerHTML = '<i class="bi bi-calendar3 me-1"></i>' + d.toLocaleDateString();
        } else {
            dateEl.innerHTML = '<i class="bi bi-calendar3 me-1"></i>-';
        }

        // Release notes
        var notesEl = document.getElementById('patchReleaseNotes');
        if (patch.release_notes) {
            notesEl.textContent = patch.release_notes;
        } else {
            notesEl.innerHTML = '<p class="text-muted">' + escapeHtml(PatchUpdate.i18n.noReleaseNotes || 'No release notes available') + '</p>';
        }

        // Update counter (e.g. "Update 1 of 3")
        var counterEl = document.getElementById('patchUpdateCounter');
        if (this.totalPatches > 1) {
            counterEl.textContent = (PatchUpdate.i18n.updateXofN || 'Update %d of %d')
                .replace('%d', this.currentPatchIndex + 1)
                .replace('%d', this.totalPatches);
            counterEl.style.display = '';
        } else {
            counterEl.style.display = 'none';
        }
    },

    /**
     * Render the patch queue panel
     * Shows a compact list of all patches with their status.
     * Only visible when there are multiple patches.
     */
    renderQueue: function () {
        var panel = document.getElementById('patchQueuePanel');
        var list = document.getElementById('patchQueueList');

        if (this.totalPatches <= 1) {
            panel.style.display = 'none';
            return;
        }

        panel.style.display = '';
        list.innerHTML = '';

        for (var i = 0; i < this.patches.length; i++) {
            var patch = this.patches[i];
            var status = 'pending';
            var statusLabel = this.queueLabels.pending || 'Pending';

            if (i === 0) {
                status = 'next';
                statusLabel = this.queueLabels.next || 'Next';
            }

            var item = document.createElement('div');
            item.className = 'patch-queue-item';
            item.id = 'patchQueueItem-' + i;
            item.innerHTML =
                '<span class="patch-queue-icon">' + PatchUpdate.getQueueIcon(status) + '</span>' +
                '<span class="patch-queue-version">v' + escapeHtml(patch.version) + '</span>' +
                '<span class="patch-queue-date">' + PatchUpdate.formatDate(patch.released_at) + '</span>' +
                '<span class="patch-queue-size">' + PatchUpdate.formatFileSize(patch.file_size || 0) + '</span>' +
                '<span class="patch-queue-status" id="patchQueueStatus-' + i + '">' + escapeHtml(statusLabel) + '</span>';

            list.appendChild(item);
        }
    },

    /**
     * Update a queue item's visual status
     * @param {number} index - Queue item index
     * @param {'next'|'pending'|'installing'|'installed'|'failed'} status
     */
    updateQueueItemStatus: function (index, status) {
        var item = document.getElementById('patchQueueItem-' + index);
        var statusEl = document.getElementById('patchQueueStatus-' + index);
        if (!item || !statusEl) return;

        // Remove old queue classes
        item.classList.remove('queue-active', 'queue-completed', 'queue-failed');

        // Set new class
        switch (status) {
            case 'installing':
                item.classList.add('queue-active');
                break;
            case 'installed':
                item.classList.add('queue-completed');
                break;
            case 'failed':
                item.classList.add('queue-failed');
                break;
        }

        // Update icon
        var iconEl = item.querySelector('.patch-queue-icon');
        if (iconEl) {
            iconEl.innerHTML = PatchUpdate.getQueueIcon(status);
        }

        // Update status label
        statusEl.textContent = this.queueLabels[status] || status;
    },

    /**
     * Get the icon HTML for a queue status
     * @param {string} status
     * @returns {string} Icon HTML
     */
    getQueueIcon: function (status) {
        switch (status) {
            case 'installed':
                return '<i class="bi bi-check-circle-fill text-success"></i>';
            case 'installing':
                return '<i class="bi bi-arrow-repeat text-primary"></i>';
            case 'failed':
                return '<i class="bi bi-x-circle-fill text-danger"></i>';
            case 'next':
                return '<i class="bi bi-arrow-right-circle text-primary"></i>';
            default:
                return '<i class="bi bi-circle text-muted"></i>';
        }
    },

    /**
     * Update the install button text based on patch count
     */
    updateInstallButton: function () {
        var btn = document.getElementById('patchInstallBtn');
        if (!btn) return;

        if (this.totalPatches > 1) {
            var label = (PatchUpdate.i18n.installAll || 'Install all %d updates')
                .replace('%d', this.totalPatches);
            btn.innerHTML = '<i class="bi bi-arrow-up-circle me-1"></i>' + escapeHtml(label);
        }
    },

    /**
     * Dismiss all available patches (used from banner)
     */
    dismissAll: function () {
        fetch(this.baseUrl + '/dismiss-all', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': this.csrfToken
            },
            body: JSON.stringify({})
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.success) {
                    var banner = document.getElementById('patchUpdateBanner');
                    if (banner) {
                        banner.style.display = 'none';
                    }
                }
            });
    },

    /**
     * Switch to password verification step
     */
    showPasswordStep: function () {
        this.switchState('password');
        var passInput = document.getElementById('patchPassword');
        passInput.value = '';
        passInput.classList.remove('is-invalid');
        setTimeout(function () { passInput.focus(); }, 300);
    },

    /**
     * Go back to details view from password step
     */
    backToDetails: function () {
        this.switchState('details');
    },

    /**
     * Verify sysadmin password
     */
    verifyPassword: function () {
        var password = document.getElementById('patchPassword').value;
        if (!password) {
            document.getElementById('patchPassword').classList.add('is-invalid');
            return;
        }

        var btn = document.getElementById('patchVerifyBtn');
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>...';

        fetch(PatchUpdate.baseUrl + '/verify-password', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': PatchUpdate.csrfToken
            },
            body: JSON.stringify({ password: password })
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-shield-lock me-1"></i>' + (btn.getAttribute('data-original-text') || 'Confirm');

                if (data.success) {
                    PatchUpdate.installToken = data.install_token;
                    if (data.csrf_token) PatchUpdate.csrfToken = data.csrf_token;
                    PatchUpdate.startInstall();
                } else {
                    document.getElementById('patchPassword').classList.add('is-invalid');
                    document.getElementById('patchPasswordError').textContent = data.error || 'Invalid password';
                }
            })
            .catch(function () {
                btn.disabled = false;
                showNotification('Verification failed', 'error');
            });
    },

    /**
     * Generate a random hex token for progress tracking
     * @returns {string} 32-character hex string
     */
    generateToken: function () {
        var arr = new Uint8Array(16);
        crypto.getRandomValues(arr);
        return Array.from(arr, function (b) { return b.toString(16).padStart(2, '0'); }).join('');
    },

    /**
     * Start patch installation for the current patch in the queue
     */
    startInstall: function () {
        this.installing = true;
        this.progressToken = this.generateToken();

        var currentPatch = this.patches[this.currentPatchIndex];
        var isFirstPatch = this.currentPatchIndex === 0;
        var createBackup = isFirstPatch && document.getElementById('patchCreateBackup').checked;

        this.setupInstallUI(currentPatch, createBackup);
        this.startPolling();

        var installToken = this.installToken;
        this.installToken = null;

        fetch(PatchUpdate.baseUrl + '/install', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': PatchUpdate.csrfToken
            },
            body: JSON.stringify({
                patch_history_id: currentPatch.id,
                install_token:    installToken,
                create_backup:    createBackup,
                progress_token:   PatchUpdate.progressToken
            })
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                PatchUpdate.installing = false;
                PatchUpdate.stopPolling();
                PatchUpdate.pollOnce(PatchUpdate.progressToken, function () {
                    PatchUpdate.handleInstallResponse(data);
                });
            })
            .catch(function () {
                PatchUpdate.installing = false;
                PatchUpdate.stopPolling();
                PatchUpdate.updateQueueItemStatus(PatchUpdate.currentPatchIndex, 'failed');
                PatchUpdate.showResult(false, 'Connection lost during installation');
            });
    },

    /**
     * Prepare the progress modal UI before an install starts
     * @param {Object} currentPatch - The patch about to be installed
     * @param {boolean} createBackup - Whether a DB backup step will run
     */
    setupInstallUI: function (currentPatch, createBackup) {
        document.getElementById('patchModalCloseBtn').style.display = 'none';
        this.switchState('progress');

        if (this.totalPatches > 1) {
            this.updateQueueItemStatus(this.currentPatchIndex, 'installing');
            var progressLabel = document.getElementById('patchProgressLabel');
            var labelText = (PatchUpdate.i18n.updateXofN || 'Update %d of %d')
                .replace('%d', this.currentPatchIndex + 1)
                .replace('%d', this.totalPatches);
            progressLabel.textContent = labelText + ': v' + currentPatch.version;
            progressLabel.style.display = '';
        }

        var steps = ['preflight_checks'];
        if (createBackup) { steps.push('create_backup'); }
        steps = steps.concat([
            'download_patch', 'extract_patch', 'execute_migration',
            'copy_files', 'update_version', 'verify_installation', 'cleanup'
        ]);
        this.renderSteps(steps);

        var bar = document.getElementById('patchProgressBar');
        bar.style.width = '0%';
        bar.className = 'progress-bar progress-bar-striped progress-bar-animated';

        document.getElementById('patchResultSuccess').style.display = 'none';
        document.getElementById('patchResultError').style.display = 'none';
    },

    /**
     * Handle the server response from a completed install request
     * @param {Object} data - Parsed JSON response from the install endpoint
     */
    handleInstallResponse: function (data) {
        if (data.success) {
            PatchUpdate.installedCount++;
            PatchUpdate.updateQueueItemStatus(PatchUpdate.currentPatchIndex, 'installed');

            if (data.has_next && data.next_install_token) {
                PatchUpdate.installToken = data.next_install_token;
            }

            if (data.has_next && data.next_version) {
                PatchUpdate.showNextPrompt(data.next_version);
            } else {
                PatchUpdate.showResult(true);
            }
        } else {
            var errMsg = (data.error_code && PatchUpdate.errorLabels[data.error_code])
                ? PatchUpdate.errorLabels[data.error_code]
                : (data.error || 'Installation failed');
            PatchUpdate.updateQueueItemStatus(PatchUpdate.currentPatchIndex, 'failed');
            PatchUpdate.showResult(false, errMsg);
        }
    },

    /**
     * Show the "Install next" prompt between sequential patches
     * @param {string} nextVersion - Version of the next patch
     */
    showNextPrompt: function (nextVersion) {
        document.getElementById('patchModalCloseBtn').style.display = '';

        // Show success for current patch
        var successEl = document.getElementById('patchResultSuccess');
        var currentPatch = this.patches[this.currentPatchIndex];
        document.getElementById('patchSuccessMessage').textContent =
            'v' + currentPatch.version + ' installed successfully';
        successEl.style.display = 'block';

        // Update progress bar to 100%
        var bar = document.getElementById('patchProgressBar');
        bar.style.width = '100%';
        bar.classList.remove('progress-bar-animated');
        bar.classList.add('bg-success');

        // Show "Install next" button
        var nextBtn = document.getElementById('patchNextBtn');
        var nextLabel = (PatchUpdate.i18n.installNext || 'Install next: v%s')
            .replace('%s', nextVersion);
        document.getElementById('patchNextBtnLabel').textContent = nextLabel;
        nextBtn.style.display = '';

        // Also show reload button as alternative
        var reloadBtn = document.getElementById('patchReloadBtn');
        reloadBtn.style.display = '';

        // Mark next patch in queue as "next"
        for (var i = this.currentPatchIndex + 1; i < this.patches.length; i++) {
            if (i === this.currentPatchIndex + 1) {
                this.updateQueueItemStatus(i, 'next');
            }
        }
    },

    /**
     * Install the next patch in the queue
     * Called when admin clicks "Install next" button
     */
    installNext: function () {
        this.currentPatchIndex++;

        if (this.currentPatchIndex >= this.patches.length) {
            this.showResult(true);
            return;
        }

        // Hide buttons
        document.getElementById('patchNextBtn').style.display = 'none';
        document.getElementById('patchReloadBtn').style.display = 'none';

        // Start installing next patch
        this.startInstall();
    },

    /**
     * Start polling the progress endpoint
     */
    startPolling: function () {
        if (this.pollInterval) return;

        this.pollInterval = setInterval(function () {
            if (!PatchUpdate.installing) {
                PatchUpdate.stopPolling();
                return;
            }

            PatchUpdate.pollOnce(PatchUpdate.progressToken);
        }, 1500);
    },

    /**
     * Stop the polling interval
     */
    stopPolling: function () {
        if (this.pollInterval) {
            clearInterval(this.pollInterval);
            this.pollInterval = null;
        }
    },

    /**
     * Poll progress once with a specific token
     * @param {string} token - Progress token
     * @param {function} [callback] - Optional callback after successful poll
     */
    pollOnce: function (token, callback) {
        if (!token) return;

        fetch(PatchUpdate.baseUrl + '/progress?token=' + encodeURIComponent(token), {
            headers: { 'Accept': 'application/json' }
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.steps) {
                    PatchUpdate.updateStepsUI(data.steps);
                }
                if (callback) callback();
            })
            .catch(function () {
                if (callback) callback();
            });
    },

    /**
     * Render initial steps list in the progress view
     * @param {string[]} stepIds - Array of step IDs
     */
    renderSteps: function (stepIds) {
        var container = document.getElementById('patchStepsList');
        container.innerHTML = '';

        for (var i = 0; i < stepIds.length; i++) {
            var stepId = stepIds[i];
            var label = this.stepLabels[stepId] || stepId;

            var div = document.createElement('div');
            div.className = 'patch-step';
            div.id = 'patch-step-' + stepId;
            div.innerHTML =
                '<span class="patch-step-icon"><i class="bi bi-circle"></i></span>' +
                '<span class="patch-step-label">' + escapeHtml(label) + '</span>';

            container.appendChild(div);
        }
    },

    /**
     * Update steps UI from progress data
     * @param {Array<{id: string, status: string}>} steps - Steps with status
     */
    updateStepsUI: function (steps) {
        for (var i = 0; i < steps.length; i++) {
            var step = steps[i];
            var el = document.getElementById('patch-step-' + step.id);
            if (!el) continue;

            var iconEl = el.querySelector('.patch-step-icon');
            el.className = 'patch-step ' + step.status;

            switch (step.status) {
                case 'completed':
                    iconEl.innerHTML = '<i class="bi bi-check-circle-fill"></i>';
                    break;
                case 'active':
                    iconEl.innerHTML = '<i class="bi bi-arrow-repeat"></i>';
                    break;
                case 'failed':
                    iconEl.innerHTML = '<i class="bi bi-x-circle-fill"></i>';
                    break;
                default:
                    iconEl.innerHTML = '<i class="bi bi-circle"></i>';
                    break;
            }
        }

        this.updateProgressBar(steps);
    },

    /**
     * Update the progress bar width and colour based on step statuses
     * @param {Array<{status: string}>} steps - Current step list
     */
    updateProgressBar: function (steps) {
        var completedCount = 0;
        for (var i = 0; i < steps.length; i++) {
            if (steps[i].status === 'completed') completedCount++;
            else if (steps[i].status === 'active') completedCount += 0.5;
        }

        var percent = steps.length > 0 ? Math.round((completedCount / steps.length) * 100) : 0;
        var bar = document.getElementById('patchProgressBar');
        bar.style.width = percent + '%';

        if (steps.every(function (s) { return s.status === 'completed'; })) {
            bar.classList.remove('progress-bar-animated');
            bar.classList.add('bg-success');
        }

        if (steps.some(function (s) { return s.status === 'failed'; })) {
            bar.classList.remove('progress-bar-animated');
            bar.classList.add('bg-danger');
        }
    },

    /**
     * Show installation result
     * @param {boolean} success - Whether installation succeeded
     * @param {string} [errorMsg] - Error message on failure
     */
    showResult: function (success, errorMsg) {
        document.getElementById('patchModalCloseBtn').style.display = '';

        if (success) {
            var successEl = document.getElementById('patchResultSuccess');
            var messageEl = document.getElementById('patchSuccessMessage');

            if (this.installedCount > 1) {
                // All patches done
                var allDoneMsg = (PatchUpdate.i18n.allDone || 'All %d updates installed successfully.')
                    .replace('%d', this.installedCount);
                messageEl.textContent = allDoneMsg;
            }

            successEl.style.display = 'block';
            document.getElementById('patchReloadBtn').style.display = '';
            document.getElementById('patchNextBtn').style.display = 'none';

            // Update progress bar to 100%
            var bar = document.getElementById('patchProgressBar');
            bar.style.width = '100%';
            bar.classList.remove('progress-bar-animated');
            bar.classList.add('bg-success');
        } else {
            document.getElementById('patchResultError').style.display = 'block';
            document.getElementById('patchErrorMessage').textContent = errorMsg || 'Unknown error';

            // Show reload button
            document.getElementById('patchReloadBtn').style.display = '';
            document.getElementById('patchNextBtn').style.display = 'none';
        }
    },

    /**
     * Switch modal to a specific state
     * @param {'details'|'password'|'progress'} state
     */
    switchState: function (state) {
        var states = ['details', 'password', 'progress'];
        for (var i = 0; i < states.length; i++) {
            var s = states[i];
            var stateEl = document.getElementById('patchState' + s.charAt(0).toUpperCase() + s.slice(1));
            var footerEl = document.getElementById('patchFooter' + s.charAt(0).toUpperCase() + s.slice(1));
            if (stateEl) stateEl.style.display = (s === state) ? '' : 'none';
            if (footerEl) footerEl.style.display = (s === state) ? '' : 'none';
        }

        // Reset result messages only when entering progress state
        if (state === 'progress') {
            document.getElementById('patchResultSuccess').style.display = 'none';
            document.getElementById('patchResultError').style.display = 'none';
            document.getElementById('patchReloadBtn').style.display = 'none';
            document.getElementById('patchNextBtn').style.display = 'none';
        }
    },

    /**
     * Trigger a server-side update check and reload the page on success
     */
    checkUpdates: function () {
        var btn = document.getElementById('patchCheckUpdatesBtn');
        if (btn) { btn.disabled = true; }
        fetch(this.baseUrl + '/check', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.csrfToken }
        })
            .then(function (r) { return r.json(); })
            .then(function () { window.location.reload(); })
            .catch(function () { if (btn) btn.disabled = false; });
    },

    /**
     * Roll back a previously installed patch after user confirmation
     * @param {number} id - patch_history record ID to roll back
     */
    rollback: function (id) {
        if (!window.confirm('Roll back this patch?')) return;
        fetch(PatchUpdate.baseUrl + '/rollback', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': PatchUpdate.csrfToken },
            body: JSON.stringify({ id: id })
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.success) { window.location.reload(); }
            });
    },

    /**
     * Format file size in human-readable form
     * @param {number} bytes - File size in bytes
     * @returns {string} Formatted size (e.g., "15.2 MB")
     */
    formatFileSize: function (bytes) {
        if (bytes === 0) return '0 B';
        var units = ['B', 'KB', 'MB', 'GB'];
        var i = Math.floor(Math.log(bytes) / Math.log(1024));
        if (i >= units.length) i = units.length - 1;
        return (bytes / Math.pow(1024, i)).toFixed(i > 0 ? 1 : 0) + ' ' + units[i];
    },

    /**
     * Format a date string for display
     * @param {string|null} dateStr - ISO date string
     * @returns {string} Formatted date
     */
    formatDate: function (dateStr) {
        if (!dateStr) return '-';
        var d = new Date(dateStr);
        return d.toLocaleDateString();
    }
};

document.addEventListener('DOMContentLoaded', function () {
    PatchUpdate.init();

    // Password input Enter key
    var passInput = document.getElementById('patchPassword');
    if (passInput) {
        passInput.addEventListener('keypress', function (e) {
            if (e.key === 'Enter') PatchUpdate.verifyPassword();
        });
    }

    // Banner buttons
    var bannerDetailsBtn = document.getElementById('patchBannerDetailsBtn');
    if (bannerDetailsBtn) {
        bannerDetailsBtn.addEventListener('click', function () { PatchUpdate.showDetails(); });
    }
    var bannerDismissBtn = document.getElementById('patchBannerDismissBtn');
    if (bannerDismissBtn) {
        bannerDismissBtn.addEventListener('click', function () { PatchUpdate.dismissAll(); });
    }

    // Modal buttons
    var installBtn = document.getElementById('patchInstallBtn');
    if (installBtn) {
        installBtn.addEventListener('click', function () { PatchUpdate.showPasswordStep(); });
    }
    var backBtn = document.getElementById('patchBackBtn');
    if (backBtn) {
        backBtn.addEventListener('click', function () { PatchUpdate.backToDetails(); });
    }
    var verifyBtn = document.getElementById('patchVerifyBtn');
    if (verifyBtn) {
        verifyBtn.addEventListener('click', function () { PatchUpdate.verifyPassword(); });
    }
    var reloadBtn = document.getElementById('patchReloadBtn');
    if (reloadBtn) {
        reloadBtn.addEventListener('click', function () { window.location.reload(); });
    }
    var nextBtn = document.getElementById('patchNextBtn');
    if (nextBtn) {
        nextBtn.addEventListener('click', function () { PatchUpdate.installNext(); });
    }

    // Index page: Check Updates button
    var checkBtn = document.getElementById('patchCheckUpdatesBtn');
    if (checkBtn) {
        checkBtn.addEventListener('click', function () { PatchUpdate.checkUpdates(); });
    }

    // Index page: per-patch buttons (Install, Details, Rollback) — delegated
    document.addEventListener('click', function (e) {
        if (e.target.closest('.patch-install-btn') || e.target.closest('.patch-details-btn')) {
            PatchUpdate.showDetails();
        }
        var rollbackBtn = e.target.closest('.patch-rollback-btn');
        if (rollbackBtn) {
            var id = parseInt(rollbackBtn.dataset.id || '0', 10);
            if (id > 0) PatchUpdate.rollback(id);
        }
    });
});
