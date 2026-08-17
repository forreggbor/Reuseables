<?php

/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * Patch Update Modal partial — native <dialog> with three states:
 * details, password verification, and installation progress.
 * Supports sequential multi-patch installation with queue tracking.
 *
 * Variables expected (passed via extract($data) or included scope):
 *   $tr (callable) — translator callable
 *
 * Once-guard: this partial renders only once per request even if included
 * from both _banner.php and index.php.
 */

use PatchModule\PatchIcon;

if (!empty($GLOBALS['__PATCH_MODAL_RENDERED'])) {
    return;
}
$GLOBALS['__PATCH_MODAL_RENDERED'] = true;
?>
<dialog id="patchUpdateModal" class="patch-root patch-dialog patch-dialog-lg"
        aria-labelledby="patchUpdateModalLabel" data-patch-no-esc>

    <!-- Header -->
    <div class="patch-dialog-header">
        <h5 class="patch-dialog-title" id="patchUpdateModalLabel">
            <?= PatchIcon::svg('arrow-up-circle') ?><?= htmlspecialchars($tr('TEXT_HEADING_PATCH_UPDATE')) ?>
        </h5>
        <button type="button" class="patch-btn-close" data-patch-dismiss
                aria-label="<?= htmlspecialchars($tr('TEXT_BUTTON_CLOSE')) ?>"
                id="patchModalCloseBtn"><?= PatchIcon::svg('x-lg') ?></button>
    </div>

    <!-- Body -->
    <div class="patch-dialog-body">

        <!-- Patch Queue Panel (shared across states, hidden for single patch) -->
        <div id="patchQueuePanel" class="patch-hidden">
            <h6><?= PatchIcon::svg('list-ol') ?><?= htmlspecialchars($tr('TEXT_PATCH_QUEUE_HEADER')) ?></h6>
            <div class="patch-queue-list" id="patchQueueList">
                <!-- Populated by JavaScript -->
            </div>
        </div>

        <!-- State 1: Details -->
        <div id="patchStateDetails">

            <!-- Version info -->
            <div class="patch-version-grid">
                <div class="patch-version-card">
                    <div class="patch-version-label"><?= htmlspecialchars($tr('TEXT_LABEL_CURRENT_VERSION')) ?></div>
                    <div class="patch-version-value" id="patchCurrentVersion">-</div>
                </div>
                <div class="patch-version-card patch-version-new">
                    <div class="patch-version-label"><?= htmlspecialchars($tr('TEXT_LABEL_NEW_VERSION')) ?></div>
                    <div class="patch-version-value" id="patchNewVersion">-</div>
                </div>
            </div>

            <!-- Patch meta -->
            <div class="patch-meta">
                <span id="patchFileSize">-</span>
                <span id="patchReleasedAt">-</span>
                <span id="patchUpdateCounter" class="patch-hidden patch-meta-push"></span>
            </div>

            <!-- Release notes -->
            <div class="patch-form-group">
                <h6><?= htmlspecialchars($tr('TEXT_LABEL_RELEASE_NOTES')) ?></h6>
                <div class="patch-release-notes patch-md" id="patchReleaseNotes">
                    <p class="patch-text-muted"><?= htmlspecialchars($tr('TEXT_LABEL_NO_RELEASE_NOTES')) ?></p>
                </div>
            </div>

            <!-- Backup checkbox -->
            <div class="patch-form-check patch-form-group">
                <input class="patch-form-check-input" type="checkbox" id="patchCreateBackup" checked>
                <label class="patch-form-check-label" for="patchCreateBackup">
                    <?= htmlspecialchars($tr('TEXT_PATCH_CREATE_BACKUP_BEFORE')) ?>
                </label>
                <div class="patch-form-text"><?= htmlspecialchars($tr('TEXT_PATCH_BACKUP_RECOMMENDED')) ?></div>
            </div>

            <!-- Warning -->
            <div class="patch-alert patch-alert-warning patch-small">
                <?= PatchIcon::svg('exclamation-triangle') ?>
                <?= htmlspecialchars($tr('TEXT_MESSAGE_PATCH_INSTALL_WARNING')) ?>
            </div>

        </div><!-- /patchStateDetails -->

        <!-- State 2: Password Verification -->
        <div id="patchStatePassword" class="patch-hidden">
            <p><?= htmlspecialchars($tr('TEXT_PATCH_VERIFY_PASSWORD')) ?></p>
            <div class="patch-form-group">
                <label for="patchPassword" class="patch-label"><?= htmlspecialchars($tr('TEXT_LABEL_PASSWORD')) ?></label>
                <input type="password" class="patch-input" id="patchPassword"
                       autocomplete="current-password" aria-describedby="patchPasswordError">
                <div class="patch-field-error" id="patchPasswordError" role="alert"></div>
            </div>
        </div><!-- /patchStatePassword -->

        <!-- State 3: Installation Progress -->
        <div id="patchStateProgress" class="patch-hidden">

            <!-- Current patch label (shown for multi-patch queues) -->
            <div id="patchProgressLabel" class="patch-hidden"></div>

            <div class="patch-form-group">
                <div class="patch-progress" style="height: 8px;">
                    <div class="patch-progress-bar patch-striped patch-animated"
                         id="patchProgressBar" style="width: 0%"></div>
                </div>
            </div>

            <div class="patch-steps-list" id="patchStepsList">
                <!-- Steps populated by JavaScript -->
            </div>

            <!-- Result messages -->
            <div id="patchResultSuccess" class="patch-alert patch-alert-success patch-hidden" style="margin-top: 1rem;">
                <?= PatchIcon::svg('check-circle') ?>
                <span id="patchSuccessMessage"><?= htmlspecialchars($tr('TEXT_MESSAGE_PATCH_INSTALLED')) ?></span>
            </div>

            <div id="patchResultError" class="patch-alert patch-alert-danger patch-hidden" style="margin-top: 1rem;">
                <?= PatchIcon::svg('exclamation-triangle') ?>
                <span id="patchErrorMessage"></span>
            </div>

        </div><!-- /patchStateProgress -->

    </div><!-- /patch-dialog-body -->

    <!-- Footer -->
    <div class="patch-dialog-footer" id="patchModalFooter">

        <!-- Details state buttons -->
        <div id="patchFooterDetails">
            <button type="button" class="patch-btn patch-btn-secondary"
                    data-patch-dismiss><?= htmlspecialchars($tr('TEXT_BUTTON_SKIP_UPDATE')) ?></button>
            <button type="button" class="patch-btn patch-btn-primary" id="patchInstallBtn">
                <?= PatchIcon::svg('arrow-up-circle') ?><?= htmlspecialchars($tr('TEXT_BUTTON_INSTALL_UPDATE')) ?>
            </button>
        </div>

        <!-- Password state buttons -->
        <div id="patchFooterPassword" class="patch-hidden">
            <button type="button" class="patch-btn patch-btn-secondary"
                    id="patchBackBtn"><?= htmlspecialchars($tr('TEXT_BUTTON_BACK')) ?></button>
            <button type="button" class="patch-btn patch-btn-primary" id="patchVerifyBtn"
                    data-original-text="<?= htmlspecialchars($tr('TEXT_BUTTON_CONFIRM')) ?>">
                <?= PatchIcon::svg('shield-lock') ?><?= htmlspecialchars($tr('TEXT_BUTTON_CONFIRM')) ?>
            </button>
        </div>

        <!-- Progress state buttons -->
        <div id="patchFooterProgress" class="patch-hidden">
            <button type="button" class="patch-btn patch-btn-primary patch-hidden" id="patchReloadBtn">
                <?= PatchIcon::svg('arrow-clockwise') ?><?= htmlspecialchars($tr('TEXT_BUTTON_RELOAD_PAGE')) ?>
            </button>
            <button type="button" class="patch-btn patch-btn-primary patch-hidden" id="patchNextBtn">
                <?= PatchIcon::svg('arrow-right-circle') ?><span id="patchNextBtnLabel"></span>
            </button>
        </div>

    </div><!-- /patch-dialog-footer -->

</dialog><!-- /patchUpdateModal -->
