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

/**
 * Open a <dialog> element by ID via showModal().
 *
 * Guards against the InvalidStateError a second showModal() call throws on
 * an already-open dialog — native <dialog> differs from Bootstrap's
 * bootstrap.Modal#show(), which was a silent no-op when already shown.
 *
 * @param {string} id
 * @returns {HTMLDialogElement|null}
 */
function openPatchDialog(id) {
    var d = document.getElementById(id);
    if (d && !d.open) { d.showModal(); }
    return d;
}

const PatchUpdate = {
    /** @type {Object<string, string>} Icon name => full <svg> markup, from data-icons */
    icons: {},

    /**
     * Look up a pre-rendered icon by name.
     * @param {string} name
     * @returns {string} <svg> markup, or '' if not present in data-icons
     */
    icon: function (name) {
        return this.icons[name] || '';
    },

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
        this.icons       = JSON.parse(mount.dataset.icons       || '{}');
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
            .then(PatchUpdate.parseResponse)
            .then(function (result) {
                if (!result.ok) {
                    showNotification(result.errorMessage, 'error');
                    return;
                }
                var data = result.data;

                if (!data.available) {
                    showNotification(PatchUpdate.i18n.checkNoUpdates || 'No update available', 'info');
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
                openPatchDialog('patchUpdateModal');
            })
            .catch(function () {
                showNotification(PatchUpdate.i18n.genericError || 'Request failed. Please try again.', 'error');
            });
    },

    /**
     * Show the patch details modal for a single history record by ID.
     * Calls the per-record details endpoint, builds a single-item queue, and opens the modal.
     * Used by per-row Install and Details buttons on the index page.
     * @param {number} id - patch_history record ID
     */
    showSinglePatchDetails: function (id) {
        var mount = document.getElementById('patch-mount');
        var currentVersion = mount ? (mount.dataset.currentVersion || null) : null;

        fetch(this.baseUrl + '/details/' + id, {
            headers: { 'Accept': 'application/json', 'X-CSRF-Token': this.csrfToken }
        })
            .then(PatchUpdate.parseResponse)
            .then(function (result) {
                if (!result.ok) {
                    showNotification(result.errorMessage, 'error');
                    return;
                }

                var record = result.data;
                PatchUpdate.patches = [{
                    id:                 record.id,
                    version:            record.version            || '',
                    release_notes_html: record.release_notes_html || null,
                    file_size:          record.file_size          || 0,
                    released_at:        record.released_at        || null
                }];
                PatchUpdate.totalPatches      = 1;
                PatchUpdate.currentPatchIndex = 0;
                PatchUpdate.installedCount    = 0;

                PatchUpdate.renderQueue();
                PatchUpdate.populatePatchDetails(PatchUpdate.patches[0], currentVersion);
                PatchUpdate.updateInstallButton();
                PatchUpdate.switchState('details');

                openPatchDialog('patchUpdateModal');
            })
            .catch(function () {
                showNotification(PatchUpdate.i18n.genericError || 'Request failed.', 'error');
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
        sizeEl.innerHTML = PatchUpdate.icon('file-earmark-zip') + PatchUpdate.formatFileSize(patch.file_size || 0);

        // Release date
        var dateEl = document.getElementById('patchReleasedAt');
        if (patch.released_at) {
            var d = new Date(patch.released_at);
            dateEl.innerHTML = PatchUpdate.icon('calendar3') + d.toLocaleDateString();
        } else {
            dateEl.innerHTML = PatchUpdate.icon('calendar3') + '-';
        }

        // Release notes
        var notesEl = document.getElementById('patchReleaseNotes');
        if (patch.release_notes_html) {
            notesEl.innerHTML = patch.release_notes_html;
        } else if (patch.release_notes) {
            notesEl.textContent = patch.release_notes;
        } else {
            notesEl.innerHTML = '<p class="patch-text-muted">' + escapeHtml(PatchUpdate.i18n.noReleaseNotes || 'No release notes available') + '</p>';
        }

        // Update counter (e.g. "Update 1 of 3")
        var counterEl = document.getElementById('patchUpdateCounter');
        if (this.totalPatches > 1) {
            counterEl.textContent = (PatchUpdate.i18n.updateXofN || 'Update %d of %d')
                .replace('%d', this.currentPatchIndex + 1)
                .replace('%d', this.totalPatches);
            counterEl.classList.remove('patch-hidden');
        } else {
            counterEl.classList.add('patch-hidden');
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
            panel.classList.add('patch-hidden');
            return;
        }

        panel.classList.remove('patch-hidden');
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
                return '<span class="patch-text-success">' + PatchUpdate.icon('check-circle-fill') + '</span>';
            case 'installing':
                return '<span class="patch-text-primary">' + PatchUpdate.icon('arrow-repeat') + '</span>';
            case 'failed':
                return '<span class="patch-text-danger">' + PatchUpdate.icon('x-circle-fill') + '</span>';
            case 'next':
                return '<span class="patch-text-primary">' + PatchUpdate.icon('arrow-right-circle') + '</span>';
            default:
                return '<span class="patch-text-muted">' + PatchUpdate.icon('circle') + '</span>';
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
            btn.innerHTML = PatchUpdate.icon('arrow-up-circle') + escapeHtml(label);
        }
    },

    /**
     * Apply a refreshed CSRF token from a server response if one is present
     * @param {Object} data - Parsed JSON response that may contain csrf_token
     */
    applyCsrfFromResponse: function (data) {
        if (data && data.csrf_token) PatchUpdate.csrfToken = data.csrf_token;
    },

    /**
     * Parse a fetch Response into a normalised result object.
     * Captures a rotated CSRF token when present, then resolves with
     * {ok, data, errorMessage} — ok is false when the HTTP status or the JSON
     * body indicate an error.
     *
     * @param {Response} response
     * @returns {Promise<{ok: boolean, data: Object, errorMessage: string|null}>}
     */
    parseResponse: function (response) {
        return response.json().catch(function () { return {}; }).then(function (data) {
            PatchUpdate.applyCsrfFromResponse(data);
            var ok = response.ok && data && data.success !== false;
            var errorMessage = null;
            if (!ok) {
                errorMessage = (data && (data.error || data.message))
                    || (PatchUpdate.i18n.genericError || 'Request failed.');
            }
            return { ok: ok, data: data, errorMessage: errorMessage };
        });
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
            .then(PatchUpdate.parseResponse)
            .then(function (result) {
                if (!result.ok) {
                    showNotification(result.errorMessage, 'error');
                    return;
                }
                var banner = document.getElementById('patchUpdateBanner');
                if (banner) { banner.classList.add('patch-hidden'); }
            })
            .catch(function () {
                showNotification(PatchUpdate.i18n.genericError || 'Request failed.', 'error');
            });
    },

    /**
     * Switch to password verification step
     */
    showPasswordStep: function () {
        this.switchState('password');
        var passInput = document.getElementById('patchPassword');
        passInput.value = '';
        passInput.classList.remove('patch-invalid');
        passInput.removeAttribute('aria-invalid');
        // No .fade transition to outlast (Bootstrap's was 300ms) — focus
        // on the next frame instead of an arbitrary timeout.
        requestAnimationFrame(function () { passInput.focus(); });
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
        var passInput = document.getElementById('patchPassword');
        var password = passInput.value;
        if (!password) {
            passInput.classList.add('patch-invalid');
            passInput.setAttribute('aria-invalid', 'true');
            return;
        }

        var btn = document.getElementById('patchVerifyBtn');
        btn.disabled = true;
        btn.innerHTML = '<span class="patch-spinner"></span>...';

        fetch(PatchUpdate.baseUrl + '/verify-password', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': PatchUpdate.csrfToken
            },
            body: JSON.stringify({ password: password })
        })
            .then(PatchUpdate.parseResponse)
            .then(function (result) {
                btn.disabled = false;
                btn.innerHTML = PatchUpdate.icon('shield-lock') + escapeHtml(btn.getAttribute('data-original-text') || 'Confirm');
                if (!result.ok) {
                    passInput.classList.add('patch-invalid');
                    passInput.setAttribute('aria-invalid', 'true');
                    document.getElementById('patchPasswordError').textContent = result.errorMessage || 'Invalid password';
                    return;
                }
                PatchUpdate.installToken = result.data.install_token;
                PatchUpdate.startInstall();
            })
            .catch(function () {
                btn.disabled = false;
                showNotification(PatchUpdate.i18n.genericError || 'Request failed.', 'error');
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
            .then(PatchUpdate.parseResponse)
            .then(function (result) {
                PatchUpdate.installing = false;
                PatchUpdate.stopPolling();
                if (!result.ok) {
                    PatchUpdate.updateQueueItemStatus(PatchUpdate.currentPatchIndex, 'failed');
                    PatchUpdate.showResult(false, result.errorMessage);
                    return;
                }
                PatchUpdate.pollOnce(PatchUpdate.progressToken, function () {
                    PatchUpdate.handleInstallResponse(result.data);
                });
            })
            .catch(function () {
                PatchUpdate.installing = false;
                PatchUpdate.stopPolling();
                PatchUpdate.updateQueueItemStatus(PatchUpdate.currentPatchIndex, 'failed');
                PatchUpdate.showResult(false, PatchUpdate.i18n.genericError || 'Connection lost during installation');
            });
    },

    /**
     * Prepare the progress modal UI before an install starts
     * @param {Object} currentPatch - The patch about to be installed
     * @param {boolean} createBackup - Whether a DB backup step will run
     */
    setupInstallUI: function (currentPatch, createBackup) {
        document.getElementById('patchModalCloseBtn').classList.add('patch-hidden');
        this.switchState('progress');

        if (this.totalPatches > 1) {
            this.updateQueueItemStatus(this.currentPatchIndex, 'installing');
            var progressLabel = document.getElementById('patchProgressLabel');
            var labelText = (PatchUpdate.i18n.updateXofN || 'Update %d of %d')
                .replace('%d', this.currentPatchIndex + 1)
                .replace('%d', this.totalPatches);
            progressLabel.textContent = labelText + ': v' + currentPatch.version;
            progressLabel.classList.remove('patch-hidden');
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
        bar.className = 'patch-progress-bar patch-striped patch-animated';

        document.getElementById('patchResultSuccess').classList.add('patch-hidden');
        document.getElementById('patchResultError').classList.add('patch-hidden');
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
        document.getElementById('patchModalCloseBtn').classList.remove('patch-hidden');

        // Show success for current patch
        var successEl = document.getElementById('patchResultSuccess');
        var currentPatch = this.patches[this.currentPatchIndex];
        document.getElementById('patchSuccessMessage').textContent =
            (PatchUpdate.i18n.versionInstalled || 'v%s installed successfully').replace('%s', currentPatch.version);
        successEl.classList.remove('patch-hidden');

        // Update progress bar to 100%
        var bar = document.getElementById('patchProgressBar');
        bar.style.width = '100%';
        bar.classList.remove('patch-animated');
        bar.classList.add('patch-bg-success');

        // Show "Install next" button
        var nextBtn = document.getElementById('patchNextBtn');
        var nextLabel = (PatchUpdate.i18n.installNext || 'Install next: v%s')
            .replace('%s', nextVersion);
        document.getElementById('patchNextBtnLabel').textContent = nextLabel;
        nextBtn.classList.remove('patch-hidden');

        // Also show reload button as alternative
        var reloadBtn = document.getElementById('patchReloadBtn');
        reloadBtn.classList.remove('patch-hidden');

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
        document.getElementById('patchNextBtn').classList.add('patch-hidden');
        document.getElementById('patchReloadBtn').classList.add('patch-hidden');

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
            .catch(function (error) {
                // Non-fatal: self-healing, the next poll tick retries — the
                // authoritative install-result POST is what actually reports
                // success/failure. Still worth logging for debugging (#478/#480).
                console.error('PatchUpdate.pollOnce: progress poll failed', error);
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
                '<span class="patch-step-icon">' + PatchUpdate.icon('circle') + '</span>' +
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
                    iconEl.innerHTML = PatchUpdate.icon('check-circle-fill');
                    break;
                case 'active':
                    iconEl.innerHTML = PatchUpdate.icon('arrow-repeat');
                    break;
                case 'failed':
                    iconEl.innerHTML = PatchUpdate.icon('x-circle-fill');
                    break;
                default:
                    iconEl.innerHTML = PatchUpdate.icon('circle');
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
            bar.classList.remove('patch-animated');
            bar.classList.add('patch-bg-success');
        }

        if (steps.some(function (s) { return s.status === 'failed'; })) {
            bar.classList.remove('patch-animated');
            bar.classList.add('patch-bg-danger');
        }
    },

    /**
     * Show installation result
     * @param {boolean} success - Whether installation succeeded
     * @param {string} [errorMsg] - Error message on failure
     */
    showResult: function (success, errorMsg) {
        document.getElementById('patchModalCloseBtn').classList.remove('patch-hidden');

        if (success) {
            var successEl = document.getElementById('patchResultSuccess');
            var messageEl = document.getElementById('patchSuccessMessage');

            if (this.installedCount > 1) {
                // All patches done
                var allDoneMsg = (PatchUpdate.i18n.allDone || 'All %d updates installed successfully.')
                    .replace('%d', this.installedCount);
                messageEl.textContent = allDoneMsg;
            }

            successEl.classList.remove('patch-hidden');
            document.getElementById('patchReloadBtn').classList.remove('patch-hidden');
            document.getElementById('patchNextBtn').classList.add('patch-hidden');

            // Update progress bar to 100%
            var bar = document.getElementById('patchProgressBar');
            bar.style.width = '100%';
            bar.classList.remove('patch-animated');
            bar.classList.add('patch-bg-success');
        } else {
            document.getElementById('patchResultError').classList.remove('patch-hidden');
            document.getElementById('patchErrorMessage').textContent = errorMsg || 'Unknown error';

            // Show reload button
            document.getElementById('patchReloadBtn').classList.remove('patch-hidden');
            document.getElementById('patchNextBtn').classList.add('patch-hidden');
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
            if (stateEl) stateEl.classList.toggle('patch-hidden', s !== state);
            if (footerEl) footerEl.classList.toggle('patch-hidden', s !== state);
        }

        // Reset result messages only when entering progress state
        if (state === 'progress') {
            document.getElementById('patchResultSuccess').classList.add('patch-hidden');
            document.getElementById('patchResultError').classList.add('patch-hidden');
            document.getElementById('patchReloadBtn').classList.add('patch-hidden');
            document.getElementById('patchNextBtn').classList.add('patch-hidden');
        }
    },

    /**
     * Trigger a server-side update check.
     * Reloads the page only when new patches are available; otherwise shows a toast.
     */
    checkUpdates: function () {
        var btn = document.getElementById('patchCheckUpdatesBtn');
        if (btn) { btn.disabled = true; }
        fetch(this.baseUrl + '/check', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.csrfToken }
        })
            .then(PatchUpdate.parseResponse)
            .then(function (result) {
                if (!result.ok) {
                    showNotification(result.errorMessage, 'error');
                    if (btn) { btn.disabled = false; }
                    return;
                }
                if (result.data.available) {
                    window.location.reload();
                } else {
                    showNotification(PatchUpdate.i18n.checkNoUpdates || 'Your installation is up to date.', 'info');
                    if (btn) { btn.disabled = false; }
                }
            })
            .catch(function () {
                showNotification(PatchUpdate.i18n.checkFailed || 'Update check failed.', 'error');
                if (btn) { btn.disabled = false; }
            });
    },

    /**
     * Show a styled confirmation dialog (native <dialog>, #patchConfirmDialog),
     * replacing window.confirm(). If the partial somehow isn't present in the
     * page (e.g. included from a context that only renders _banner.php, not the
     * full admin index), fails closed — resolves false (same as "cancelled") and
     * surfaces the problem loudly, rather than falling back to native
     * window.confirm() (never used in this app — #478/#480).
     *
     * @param {Object} options
     * @param {string} options.message - Body text (plain text, not HTML)
     * @param {string} [options.title] - Header title text
     * @param {string} [options.confirmText] - Confirm button label (defaults to i18n.confirmButtonGeneric)
     * @param {'danger'|'warning'} [options.variant] - Header/button color; omit for the default (primary) styling
     * @returns {Promise<boolean>} Resolves true if confirmed, false if cancelled/dismissed/unavailable
     */
    confirmDialog: function (options) {
        options = options || {};
        return new Promise(function (resolve) {
            var dialog = document.getElementById('patchConfirmDialog');
            if (!dialog) {
                console.error('PatchUpdate.confirmDialog: #patchConfirmDialog is missing from the page — cannot confirm, aborting the action.');
                if (typeof showNotification === 'function') {
                    showNotification(PatchUpdate.i18n.genericError || 'Request failed. Please try again.', 'error');
                }
                resolve(false);
                return;
            }

            var header      = document.getElementById('patchConfirmHeader');
            var titleTextEl = document.getElementById('patchConfirmTitleText');
            var msgEl       = document.getElementById('patchConfirmMessage');
            var confirmBtn  = document.getElementById('patchConfirmOkBtn');
            var cancelText  = document.getElementById('patchConfirmCancelText');

            header.classList.remove('patch-dialog-header-danger', 'patch-dialog-header-warning');
            if (options.variant === 'danger' || options.variant === 'warning') {
                header.classList.add('patch-dialog-header-' + options.variant);
            }

            titleTextEl.textContent = options.title || PatchUpdate.i18n.confirmButtonGeneric || 'Confirm';
            msgEl.textContent = options.message || '';
            confirmBtn.textContent = options.confirmText || PatchUpdate.i18n.confirmButtonGeneric || 'Confirm';
            cancelText.textContent = PatchUpdate.i18n.cancelButton || 'Cancel';
            confirmBtn.classList.toggle('patch-btn-danger', options.variant === 'danger');
            confirmBtn.classList.toggle('patch-btn-primary', options.variant !== 'danger');

            var settled = false;
            function finish(result) {
                if (settled) { return; }
                settled = true;
                confirmBtn.removeEventListener('click', onConfirm);
                dialog.removeEventListener('close', onClose);
                resolve(result);
            }
            // Order matters: settle (and detach the 'close' listener) BEFORE
            // calling close() — close() fires 'close' synchronously, and if
            // onClose ran first it would win the settled-guard race and
            // resolve false instead of true.
            function onConfirm() { finish(true); dialog.close(); }
            // Covers both the Cancel button (data-patch-dismiss, handled by
            // the existing delegated dismiss listener) and native dismissal
            // (Escape, backdrop click via data-patch-light-dismiss) — all of
            // them end up calling dialog.close(), which fires this.
            function onClose() { finish(false); }

            confirmBtn.addEventListener('click', onConfirm);
            dialog.addEventListener('close', onClose);

            openPatchDialog('patchConfirmDialog');
        });
    },

    /**
     * Roll back a previously installed patch after user confirmation
     * @param {number} id - patch_history record ID to roll back
     */
    rollback: function (id) {
        PatchUpdate.confirmDialog({
            message: PatchUpdate.i18n.confirmRollback || 'Roll back this patch?',
            title: PatchUpdate.i18n.rollbackAction || 'Rollback patch',
            confirmText: PatchUpdate.i18n.rollbackAction || 'Rollback patch',
            variant: 'danger'
        }).then(function (confirmed) {
            if (!confirmed) { return; }
            PatchUpdate.doRollback(id);
        });
    },

    /**
     * Perform the actual rollback request — split out from rollback() so the
     * confirmation step above can be async (native <dialog> can't block
     * synchronously the way window.confirm() did).
     * @param {number} id - patch_history record ID to roll back
     */
    doRollback: function (id) {
        fetch(PatchUpdate.baseUrl + '/rollback', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': PatchUpdate.csrfToken },
            body: JSON.stringify({ id: id })
        })
            .then(PatchUpdate.parseResponse)
            .then(function (result) {
                if (!result.ok) {
                    showNotification(result.errorMessage, 'error');
                    return;
                }
                window.location.reload();
            })
            .catch(function () {
                showNotification(PatchUpdate.i18n.genericError || 'Request failed.', 'error');
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

/**
 * Manual Patch Upload handler
 *
 * Manages the upload accordion on the patch-management admin page: sends the
 * .tgz file via XHR (for progress reporting), handles version-gap confirmation,
 * and hands off to the existing PatchUpdate install modal.
 */
const PatchUpload = {
    /** @type {boolean} */
    uploading: false,

    /** @type {Object<string, string>} Upload-specific i18n strings */
    i18n: {},

    /**
     * Read upload i18n strings from the mount element
     */
    init: function () {
        var mount = document.getElementById('patch-mount');
        if (!mount) return;
        this.i18n = JSON.parse(mount.dataset.uploadI18n || '{}');
    },

    /**
     * Handle upload form submit
     * @param {Event} e
     */
    handleSubmit: function (e) {
        e.preventDefault();
        if (this.uploading) return;

        var fileInput = document.getElementById('patchUploadFile');
        if (!fileInput || !fileInput.files || !fileInput.files[0]) return;

        var mount     = document.getElementById('patch-mount');
        var formEl    = document.getElementById('patchUploadForm');
        var uploadUrl = formEl ? formEl.dataset.action : '';
        var csrfToken = mount ? (mount.dataset.csrfToken || '') : '';

        var formData = new FormData();
        formData.append('patch_file',  fileInput.files[0]);
        formData.append('csrf_token',  csrfToken);

        this.uploading = true;
        this.setFormEnabled(false);
        this.showProgress();
        this.setStatus(this.i18n.uploading || 'Uploading…');

        var self = this;
        var xhr  = new XMLHttpRequest();
        xhr.open('POST', uploadUrl, true);
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.setRequestHeader('X-CSRF-Token', csrfToken);

        xhr.upload.onprogress = function (ev) {
            if (ev.lengthComputable) {
                var pct = Math.round((ev.loaded / ev.total) * 100);
                self.setProgress(pct < 95 ? pct : 95);
            }
        };

        xhr.onload = function () {
            self.uploading = false;

            var data;
            try { data = JSON.parse(xhr.responseText); } catch (ex) { self.onError(null); return; }

            if (data.csrf_token) {
                PatchUpdate.csrfToken = data.csrf_token;
                if (mount) { mount.dataset.csrfToken = data.csrf_token; }
            }

            if (!data.success) { self.onError(data); return; }

            self.setProgress(100);

            setTimeout(function () { self.onUploadSuccess(data); }, 600);
        };

        xhr.onerror = function () { self.uploading = false; self.onError(null); };

        xhr.send(formData);
    },

    /**
     * Handle successful upload — confirm version gap if present, then open install modal
     * @param {Object} data - Parsed server JSON (success=true)
     */
    onUploadSuccess: function (data) {
        var self = this;

        function proceed() {
            var mount          = document.getElementById('patch-mount');
            var currentVersion = mount ? (mount.dataset.currentVersion || null) : null;

            PatchUpdate.patches           = [{
                id:                 data.patch_history_id,
                version:            data.version            || '',
                release_notes_html: data.release_notes_html || null,
                file_size:          data.file_size          || 0,
                released_at:        data.released_at        || null
            }];
            PatchUpdate.totalPatches      = 1;
            PatchUpdate.currentPatchIndex = 0;
            PatchUpdate.installedCount    = 0;

            PatchUpdate.renderQueue();
            PatchUpdate.populatePatchDetails(PatchUpdate.patches[0], currentVersion);
            PatchUpdate.updateInstallButton();
            PatchUpdate.switchState('details');

            openPatchDialog('patchUpdateModal');

            self.reset();
        }

        // Native <dialog> can't block synchronously the way window.confirm()
        // did — the no-warning path below still runs synchronously (same
        // ordering as before), only the warning path is now async.
        if (data.warning === 'version_gap' && data.warning_message) {
            PatchUpdate.confirmDialog({
                message: data.warning_message,
                title: PatchUpdate.i18n.versionGapWarning || 'Version gap warning',
                variant: 'warning'
            }).then(function (confirmed) {
                if (!confirmed) {
                    self.reset();
                    return;
                }
                proceed();
            });
            return;
        }

        proceed();
    },

    /**
     * Show an error notification and re-enable the form
     * @param {Object|null} data - Parsed response or null on network error
     */
    onError: function (data) {
        var errorCode   = data ? (data.error_code || null) : null;
        var errorLabels = {};
        var mount       = document.getElementById('patch-mount');
        if (mount) {
            try { errorLabels = JSON.parse(mount.dataset.errorLabels || '{}'); } catch (ex) { /* keep default {} */ }
        }
        var message = (errorCode && errorLabels[errorCode])
            ? errorLabels[errorCode]
            : (data && data.error ? data.error : 'Upload failed.');

        this.hideProgress();
        this.setFormEnabled(true);
        showNotification(message, 'error');
    },

    /** Reset form to initial idle state */
    reset: function () {
        this.uploading = false;
        this.hideProgress();
        this.setFormEnabled(true);
        this.setStatus('');
        var f = document.getElementById('patchUploadFile');
        if (f) f.value = '';
    },

    setFormEnabled: function (enabled) {
        var ids = ['patchUploadSubmitBtn', 'patchUploadFile'];
        for (var i = 0; i < ids.length; i++) {
            var el = document.getElementById(ids[i]);
            if (el) { el.disabled = !enabled; }
        }
    },

    showProgress: function () {
        var wrap = document.getElementById('patchUploadProgressWrap');
        if (wrap) { wrap.classList.remove('patch-hidden'); }
    },

    hideProgress: function () {
        var wrap = document.getElementById('patchUploadProgressWrap');
        if (wrap) { wrap.classList.add('patch-hidden'); }
        this.setProgress(0);
    },

    setProgress: function (pct) {
        var bar = document.getElementById('patchUploadProgressBar');
        if (!bar) return;
        bar.style.width = pct + '%';
        bar.setAttribute('aria-valuenow', String(pct));
    },

    setStatus: function (text) {
        var el = document.getElementById('patchUploadStatus');
        if (!el) return;
        el.textContent = text;
        el.classList.toggle('patch-hidden', text === '');
    }
};

/**
 * Patch Changelog Viewer
 *
 * Opens a read-only modal showing the rendered release notes for a history record.
 * Reuses PatchUpdate's baseUrl, csrfToken, and parseResponse helpers.
 */
const PatchChangelog = {
    /**
     * Open the changelog modal for a given patch history record.
     *
     * @param {number|string} id      patch_history record ID
     * @param {string}        version Human-readable version string (already HTML-escaped from data attribute)
     */
    open: function (id, version) {
        var contentEl  = document.getElementById('patchChangelogContent');
        var emptyEl    = document.getElementById('patchChangelogEmpty');
        var versionEl  = document.getElementById('patchChangelogVersion');
        var modalEl    = document.getElementById('patchChangelogModal');

        if (!contentEl || !emptyEl || !modalEl) return;

        // Reset state: show empty-state while loading
        if (versionEl) versionEl.textContent = version ? ' v' + version : '';
        contentEl.classList.add('patch-hidden');
        contentEl.innerHTML     = '';
        emptyEl.classList.remove('patch-hidden');
        emptyEl.textContent     = PatchUpdate.i18n.noReleaseNotes || '';

        openPatchDialog('patchChangelogModal');

        fetch(PatchUpdate.baseUrl + '/details/' + id, {
            headers: { 'Accept': 'application/json', 'X-CSRF-Token': PatchUpdate.csrfToken }
        })
            .then(PatchUpdate.parseResponse)
            .then(function (result) {
                if (!result.ok) {
                    emptyEl.textContent = result.errorMessage
                        || PatchUpdate.i18n.changelogLoadFailed
                        || 'Could not load changelog.';
                    return;
                }

                var html = result.data && result.data.release_notes_html;
                if (html) {
                    // HTML is pre-rendered and sanitised server-side by SimpleMarkdownRenderer
                    contentEl.innerHTML = html;
                    contentEl.classList.remove('patch-hidden');
                    emptyEl.classList.add('patch-hidden');
                } else {
                    emptyEl.textContent = PatchUpdate.i18n.noReleaseNotes || '';
                }
            })
            .catch(function () {
                emptyEl.textContent = PatchUpdate.i18n.changelogLoadFailed || 'Could not load changelog.';
            });
    }
};

document.addEventListener('DOMContentLoaded', function () {
    PatchUpdate.init();
    PatchUpload.init();

    // ESC suppression on dialogs marked data-patch-no-esc — replaces Bootstrap's
    // data-bs-keyboard="false". Blocks ESC unconditionally (matching today's
    // behavior exactly: the update modal was never ESC-dismissable, not only
    // while an install is actually running).
    document.querySelectorAll('dialog[data-patch-no-esc]').forEach(function (d) {
        d.addEventListener('cancel', function (e) { e.preventDefault(); });
    });

    // Backdrop click-to-dismiss on dialogs marked data-patch-light-dismiss —
    // native <dialog> has no built-in light-dismiss; Bootstrap's non-static
    // modal did, so this restores it for the changelog dialog specifically.
    document.querySelectorAll('dialog[data-patch-light-dismiss]').forEach(function (d) {
        d.addEventListener('click', function (e) {
            if (e.target === d) d.close();
        });
    });

    // Password input Enter key
    var passInput = document.getElementById('patchPassword');
    if (passInput) {
        passInput.addEventListener('keypress', function (e) {
            if (e.key === 'Enter') PatchUpdate.verifyPassword();
        });
    }

    // Toast button
    var bannerDetailsBtn = document.getElementById('patchBannerDetailsBtn');
    if (bannerDetailsBtn) {
        bannerDetailsBtn.addEventListener('click', function () { PatchUpdate.showDetails(); });
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

    // Index page: Manual upload form
    var uploadForm = document.getElementById('patchUploadForm');
    if (uploadForm) {
        uploadForm.addEventListener('submit', function (e) { PatchUpload.handleSubmit(e); });
    }

    // Index page: per-patch buttons (Install, Details, Rollback, Changelog) — delegated
    document.addEventListener('click', function (e) {
        var installBtn = e.target.closest('.patch-install-btn');
        var detailsBtn = e.target.closest('.patch-details-btn');
        if (installBtn || detailsBtn) {
            var actionBtn = installBtn || detailsBtn;
            var patchId = parseInt(actionBtn.dataset.patchId || '0', 10);
            if (patchId > 0) PatchUpdate.showSinglePatchDetails(patchId);
        }
        var rollbackBtn = e.target.closest('.patch-rollback-btn');
        if (rollbackBtn) {
            var rollbackId = parseInt(rollbackBtn.dataset.id || '0', 10);
            if (rollbackId > 0) PatchUpdate.rollback(rollbackId);
        }
        var changelogBtn = e.target.closest('.patch-changelog-btn');
        if (changelogBtn) {
            var historyId = parseInt(changelogBtn.dataset.id || '0', 10);
            if (historyId > 0) PatchChangelog.open(historyId, changelogBtn.dataset.version || '');
        }

        // Dialog dismiss buttons — replaces Bootstrap's data-bs-dismiss="modal"
        var dismissEl = e.target.closest('[data-patch-dismiss]');
        if (dismissEl) {
            var dialogToClose = dismissEl.closest('dialog');
            if (dialogToClose && dialogToClose.open) dialogToClose.close();
        }
    });
});
