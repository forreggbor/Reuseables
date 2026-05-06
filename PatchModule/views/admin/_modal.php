<?php
/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * Patch Update Modal partial — Bootstrap 5 modal with three states:
 * details, password verification, and installation progress.
 * Supports sequential multi-patch installation with queue tracking.
 *
 * Variables expected (passed via extract($data) or included scope):
 *   $tr (callable) — translator callable
 *
 * Once-guard: this partial renders only once per request even if included
 * from both _banner.php and index.php.
 */

if (!empty($GLOBALS['__PATCH_MODAL_RENDERED'])) {
    return;
}
$GLOBALS['__PATCH_MODAL_RENDERED'] = true;
?>
<div class="modal fade" id="patchUpdateModal" tabindex="-1"
     aria-labelledby="patchUpdateModalLabel" aria-hidden="true"
     data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">

            <!-- Header -->
            <div class="modal-header patch-modal-header">
                <div>
                    <h5 class="modal-title" id="patchUpdateModalLabel">
                        <i class="bi bi-arrow-up-circle me-2"></i><?= htmlspecialchars($tr('TEXT_HEADING_PATCH_UPDATE')) ?>
                    </h5>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"
                        aria-label="<?= htmlspecialchars($tr('TEXT_BUTTON_SKIP_UPDATE')) ?>"
                        id="patchModalCloseBtn"></button>
            </div>

            <!-- Body -->
            <div class="modal-body">

                <!-- Patch Queue Panel (shared across states, hidden for single patch) -->
                <div id="patchQueuePanel" class="mb-3" style="display: none;">
                    <h6 class="fw-semibold mb-2">
                        <i class="bi bi-list-ol me-1"></i><?= htmlspecialchars($tr('TEXT_PATCH_QUEUE_HEADER')) ?>
                    </h6>
                    <div class="patch-queue-list" id="patchQueueList">
                        <!-- Populated by JavaScript -->
                    </div>
                </div>

                <!-- State 1: Details -->
                <div id="patchStateDetails">

                    <!-- Version info -->
                    <div class="row mb-3">
                        <div class="col-6">
                            <div class="patch-version-card">
                                <div class="patch-version-label"><?= htmlspecialchars($tr('TEXT_LABEL_CURRENT_VERSION')) ?></div>
                                <div class="patch-version-value" id="patchCurrentVersion">-</div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="patch-version-card patch-version-new">
                                <div class="patch-version-label"><?= htmlspecialchars($tr('TEXT_LABEL_NEW_VERSION')) ?></div>
                                <div class="patch-version-value" id="patchNewVersion">-</div>
                            </div>
                        </div>
                    </div>

                    <!-- Patch meta -->
                    <div class="d-flex gap-3 mb-3 text-muted small">
                        <span id="patchFileSize"><i class="bi bi-file-earmark-zip me-1"></i>-</span>
                        <span id="patchReleasedAt"><i class="bi bi-calendar3 me-1"></i>-</span>
                        <span id="patchUpdateCounter" class="ms-auto fw-semibold" style="display: none;"></span>
                    </div>

                    <!-- Release notes -->
                    <div class="mb-3">
                        <h6 class="fw-semibold"><?= htmlspecialchars($tr('TEXT_LABEL_RELEASE_NOTES')) ?></h6>
                        <div class="patch-release-notes" id="patchReleaseNotes">
                            <p class="text-muted"><?= htmlspecialchars($tr('TEXT_LABEL_NO_RELEASE_NOTES')) ?></p>
                        </div>
                    </div>

                    <!-- Backup checkbox -->
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="patchCreateBackup" checked>
                        <label class="form-check-label" for="patchCreateBackup">
                            <?= htmlspecialchars($tr('TEXT_PATCH_CREATE_BACKUP_BEFORE')) ?>
                        </label>
                        <div class="form-text"><?= htmlspecialchars($tr('TEXT_PATCH_BACKUP_RECOMMENDED')) ?></div>
                    </div>

                    <!-- Warning -->
                    <div class="alert alert-warning mb-0 small">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        <?= htmlspecialchars($tr('TEXT_MESSAGE_PATCH_INSTALL_WARNING')) ?>
                    </div>

                </div><!-- /patchStateDetails -->

                <!-- State 2: Password Verification -->
                <div id="patchStatePassword" style="display: none;">
                    <p class="mb-3"><?= htmlspecialchars($tr('TEXT_PATCH_VERIFY_PASSWORD')) ?></p>
                    <div class="mb-3">
                        <label for="patchPassword" class="form-label"><?= htmlspecialchars($tr('TEXT_LABEL_PASSWORD')) ?></label>
                        <input type="password" class="form-control" id="patchPassword"
                               autocomplete="current-password">
                        <div class="invalid-feedback" id="patchPasswordError"></div>
                    </div>
                </div><!-- /patchStatePassword -->

                <!-- State 3: Installation Progress -->
                <div id="patchStateProgress" style="display: none;">

                    <!-- Current patch label (shown for multi-patch queues) -->
                    <div id="patchProgressLabel" class="fw-semibold mb-2" style="display: none;"></div>

                    <div class="mb-3">
                        <div class="progress" style="height: 8px;">
                            <div class="progress-bar progress-bar-striped progress-bar-animated"
                                 id="patchProgressBar" style="width: 0%"></div>
                        </div>
                    </div>

                    <div class="patch-steps-list" id="patchStepsList">
                        <!-- Steps populated by JavaScript -->
                    </div>

                    <!-- Result messages -->
                    <div id="patchResultSuccess" class="alert alert-success mt-3" style="display: none;">
                        <i class="bi bi-check-circle me-2"></i>
                        <span id="patchSuccessMessage"><?= htmlspecialchars($tr('TEXT_MESSAGE_PATCH_INSTALLED')) ?></span>
                    </div>

                    <div id="patchResultError" class="alert alert-danger mt-3" style="display: none;">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        <span id="patchErrorMessage"></span>
                    </div>

                </div><!-- /patchStateProgress -->

            </div><!-- /modal-body -->

            <!-- Footer -->
            <div class="modal-footer" id="patchModalFooter">

                <!-- Details state buttons -->
                <div id="patchFooterDetails">
                    <button type="button" class="btn btn-secondary"
                            data-bs-dismiss="modal"><?= htmlspecialchars($tr('TEXT_BUTTON_SKIP_UPDATE')) ?></button>
                    <button type="button" class="btn btn-primary" id="patchInstallBtn">
                        <i class="bi bi-arrow-up-circle me-1"></i><?= htmlspecialchars($tr('TEXT_BUTTON_INSTALL_UPDATE')) ?>
                    </button>
                </div>

                <!-- Password state buttons -->
                <div id="patchFooterPassword" style="display: none;">
                    <button type="button" class="btn btn-secondary"
                            id="patchBackBtn"><?= htmlspecialchars($tr('TEXT_BUTTON_BACK')) ?></button>
                    <button type="button" class="btn btn-primary" id="patchVerifyBtn"
                            data-original-text="<?= htmlspecialchars($tr('TEXT_BUTTON_CONFIRM')) ?>">
                        <i class="bi bi-shield-lock me-1"></i><?= htmlspecialchars($tr('TEXT_BUTTON_CONFIRM')) ?>
                    </button>
                </div>

                <!-- Progress state buttons -->
                <div id="patchFooterProgress" style="display: none;">
                    <button type="button" class="btn btn-primary" id="patchReloadBtn"
                            style="display: none;">
                        <i class="bi bi-arrow-clockwise me-1"></i><?= htmlspecialchars($tr('TEXT_BUTTON_RELOAD_PAGE')) ?>
                    </button>
                    <button type="button" class="btn btn-primary" id="patchNextBtn"
                            style="display: none;">
                        <i class="bi bi-arrow-right-circle me-1"></i><span id="patchNextBtnLabel"></span>
                    </button>
                </div>

            </div><!-- /modal-footer -->

        </div><!-- /modal-content -->
    </div><!-- /modal-dialog -->
</div><!-- /patchUpdateModal -->
