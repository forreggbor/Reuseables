/**
 * Patch Update Manager
 *
 * Handles the update banner interactions, modal state management,
 * password verification, patch installation, and progress polling.
 * Supports sequential multi-patch installation with queue tracking.
 */
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
     * Step labels for progress display (set from PHP via window.patchStepLabels)
     * @type {Object<string, string>}
     */
    stepLabels: {},

    /**
     * Queue status labels (set from PHP via window.patchQueueLabels)
     * @type {Object<string, string>}
     */
    queueLabels: {},

    /**
     * Initialize labels from PHP-provided translations
     */
    init: function () {
        this.stepLabels = window.patchStepLabels || {};
        this.queueLabels = window.patchQueueLabels || {};
    },

    /**
     * Show the patch details modal
     * Fetches latest details from the server and populates the modal.
     */
    showDetails: function () {
        this.init();

        fetch('/admin/settings/patch-management/details', {
            headers: { 'Accept': 'application/json', 'X-CSRF-Token': window.CSRF_TOKEN }
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
            notesEl.innerHTML = '<p class="text-muted">' + escapeHtml(window.patchNoReleaseNotes || 'No release notes available') + '</p>';
        }

        // Update counter (e.g. "Update 1 of 3")
        var counterEl = document.getElementById('patchUpdateCounter');
        if (this.totalPatches > 1) {
            counterEl.textContent = (window.patchUpdateXofN || 'Update %d of %d')
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
            var label = (window.patchInstallAllLabel || 'Install all %d updates')
                .replace('%d', this.totalPatches);
            btn.innerHTML = '<i class="bi bi-arrow-up-circle me-1"></i>' + escapeHtml(label);
        }
    },

    /**
     * Dismiss all available patches (used from banner)
     */
    dismissAll: function () {
        fetch('/admin/settings/patch-management/dismiss-all', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': window.CSRF_TOKEN
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

        fetch('/admin/settings/patch-management/verify-password', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': window.CSRF_TOKEN
            },
            body: JSON.stringify({ password: password })
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-shield-lock me-1"></i>' + (btn.getAttribute('data-original-text') || 'Confirm');

                if (data.success) {
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

        // Generate progress token on client side so polling can start immediately
        this.progressToken = this.generateToken();

        var currentPatch = this.patches[this.currentPatchIndex];
        var isFirstPatch = this.currentPatchIndex === 0;

        // Prevent modal from closing during installation
        document.getElementById('patchModalCloseBtn').style.display = 'none';

        // Switch to progress state
        this.switchState('progress');

        // Update queue status to installing
        if (this.totalPatches > 1) {
            this.updateQueueItemStatus(this.currentPatchIndex, 'installing');

            // Show progress label
            var progressLabel = document.getElementById('patchProgressLabel');
            var labelText = (window.patchUpdateXofN || 'Update %d of %d')
                .replace('%d', this.currentPatchIndex + 1)
                .replace('%d', this.totalPatches);
            progressLabel.textContent = labelText + ': v' + currentPatch.version;
            progressLabel.style.display = '';
        }

        // Build initial steps list
        var createBackup = isFirstPatch && document.getElementById('patchCreateBackup').checked;
        var steps = ['preflight_checks'];
        if (createBackup) {
            steps.push('create_backup');
        }
        steps = steps.concat([
            'download_patch', 'extract_patch', 'execute_migration',
            'copy_files', 'update_version', 'verify_installation', 'cleanup'
        ]);

        this.renderSteps(steps);

        // Reset progress bar
        var bar = document.getElementById('patchProgressBar');
        bar.style.width = '0%';
        bar.className = 'progress-bar progress-bar-striped progress-bar-animated';

        // Reset result messages
        document.getElementById('patchResultSuccess').style.display = 'none';
        document.getElementById('patchResultError').style.display = 'none';

        // Start polling BEFORE sending the install request
        this.startPolling();

        // Start installation (long-running request)
        fetch('/admin/settings/patch-management/install', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': window.CSRF_TOKEN
            },
            body: JSON.stringify({
                version: currentPatch.version,
                create_backup: createBackup,
                progress_token: this.progressToken
            })
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                PatchUpdate.installing = false;
                PatchUpdate.stopPolling();

                // Final poll to get the latest step statuses
                PatchUpdate.pollOnce(PatchUpdate.progressToken, function () {
                    if (data.success) {
                        PatchUpdate.installedCount++;
                        PatchUpdate.updateQueueItemStatus(PatchUpdate.currentPatchIndex, 'installed');

                        if (data.has_next && data.next_version) {
                            // More patches to install — show "Install next" button
                            PatchUpdate.showNextPrompt(data.next_version);
                        } else {
                            // All patches installed
                            PatchUpdate.showResult(true);
                        }
                    } else {
                        PatchUpdate.updateQueueItemStatus(PatchUpdate.currentPatchIndex, 'failed');
                        PatchUpdate.showResult(false, data.error || 'Installation failed');
                    }
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
        var nextLabel = (window.patchInstallNextLabel || 'Install next: v%s')
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

        fetch('/admin/settings/patch-management/install-progress?token=' + encodeURIComponent(token), {
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
        var completedCount = 0;
        var totalCount = steps.length;

        for (var i = 0; i < steps.length; i++) {
            var step = steps[i];
            var el = document.getElementById('patch-step-' + step.id);
            if (!el) continue;

            var iconEl = el.querySelector('.patch-step-icon');
            el.className = 'patch-step ' + step.status;

            switch (step.status) {
                case 'completed':
                    iconEl.innerHTML = '<i class="bi bi-check-circle-fill"></i>';
                    completedCount++;
                    break;
                case 'active':
                    iconEl.innerHTML = '<i class="bi bi-arrow-repeat"></i>';
                    completedCount += 0.5;
                    break;
                case 'failed':
                    iconEl.innerHTML = '<i class="bi bi-x-circle-fill"></i>';
                    break;
                default:
                    iconEl.innerHTML = '<i class="bi bi-circle"></i>';
                    break;
            }
        }

        // Update progress bar
        var percent = totalCount > 0 ? Math.round((completedCount / totalCount) * 100) : 0;
        var bar = document.getElementById('patchProgressBar');
        bar.style.width = percent + '%';

        // Check if all completed
        var allCompleted = steps.every(function (s) { return s.status === 'completed'; });
        if (allCompleted) {
            bar.classList.remove('progress-bar-animated');
            bar.classList.add('bg-success');
        }

        // Check if any failed
        var hasFailed = steps.some(function (s) { return s.status === 'failed'; });
        if (hasFailed) {
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
                var allDoneMsg = (window.patchAllDoneLabel || 'All %d updates installed successfully.')
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

// Handle Enter key on password input
document.addEventListener('DOMContentLoaded', function () {
    var passInput = document.getElementById('patchPassword');
    if (passInput) {
        passInput.addEventListener('keypress', function (e) {
            if (e.key === 'Enter') {
                PatchUpdate.verifyPassword();
            }
        });
    }
});
