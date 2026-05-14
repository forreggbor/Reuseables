<?php
/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * Patch Changelog Modal partial — Bootstrap 5 modal for displaying per-version
 * release notes from patch history. Non-static backdrop; user can click outside to dismiss.
 *
 * Once-guard: this partial renders only once per request even if included multiple times.
 *
 * Variables expected (passed via extract($data) or included scope):
 *   $tr (callable) — translator callable
 */

if (!empty($GLOBALS['__PATCH_CHANGELOG_MODAL_RENDERED'])) {
    return;
}
$GLOBALS['__PATCH_CHANGELOG_MODAL_RENDERED'] = true;
?>
<div class="modal fade" id="patchChangelogModal" tabindex="-1"
     aria-labelledby="patchChangelogModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">

            <!-- Header -->
            <div class="modal-header">
                <h5 class="modal-title" id="patchChangelogModalLabel">
                    <i class="bi bi-journal-text me-2"></i><?= htmlspecialchars($tr('TEXT_HEADING_PATCH_CHANGELOG')) ?><span id="patchChangelogVersion" class="ms-2 text-muted font-monospace"></span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"
                        aria-label="<?= htmlspecialchars($tr('TEXT_BUTTON_CLOSE')) ?>"></button>
            </div>

            <!-- Body -->
            <div class="modal-body">
                <div id="patchChangelogContent" class="patch-changelog-content" style="display: none;"></div>
                <div id="patchChangelogEmpty" class="text-muted">
                    <?= htmlspecialchars($tr('TEXT_LABEL_NO_RELEASE_NOTES')) ?>
                </div>
            </div>

            <!-- Footer -->
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary"
                        data-bs-dismiss="modal"><?= htmlspecialchars($tr('TEXT_BUTTON_CLOSE')) ?></button>
            </div>

        </div><!-- /modal-content -->
    </div><!-- /modal-dialog -->
</div><!-- /patchChangelogModal -->
