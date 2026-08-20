<?php

/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * Generic confirmation dialog partial — native <dialog> replacing
 * window.confirm() calls (patch rollback, manual-upload version-gap
 * warning) with a styled modal consistent with the module's own
 * .patch-dialog convention. Content is populated per call by
 * PatchUpdate.confirmDialog() in JS; this partial only provides the
 * shared markup and starts empty. ESC and clicking outside both dismiss
 * it (data-patch-light-dismiss wires the backdrop click in JS; ESC is
 * native <dialog> behavior, unsuppressed) — both resolve the promise to
 * false, matching window.confirm()'s own cancel semantics.
 *
 * Once-guard: this partial renders only once per request even if included multiple times.
 *
 * Variables expected (passed via extract($data) or included scope):
 *   $tr (callable) — translator callable
 */

use PatchModule\PatchIcon;

if (!empty($GLOBALS['__PATCH_CONFIRM_DIALOG_RENDERED'])) {
    return;
}
$GLOBALS['__PATCH_CONFIRM_DIALOG_RENDERED'] = true;
?>
<dialog id="patchConfirmDialog" class="patch-root patch-dialog"
        aria-labelledby="patchConfirmTitle" data-patch-light-dismiss>

    <!-- Header -->
    <div class="patch-dialog-header" id="patchConfirmHeader">
        <h5 class="patch-dialog-title" id="patchConfirmTitle">
            <?= PatchIcon::svg('exclamation-triangle') ?><span id="patchConfirmTitleText"></span>
        </h5>
        <button type="button" class="patch-btn-close" data-patch-dismiss
                aria-label="<?= htmlspecialchars($tr('TEXT_BUTTON_CLOSE')) ?>"><?= PatchIcon::svg('x-lg') ?></button>
    </div>

    <!-- Body -->
    <div class="patch-dialog-body">
        <p id="patchConfirmMessage"></p>
    </div>

    <!-- Footer -->
    <div class="patch-dialog-footer">
        <button type="button" class="patch-btn patch-btn-secondary"
                data-patch-dismiss><span id="patchConfirmCancelText"><?= htmlspecialchars($tr('TEXT_BUTTON_CANCEL')) ?></span></button>
        <button type="button" class="patch-btn patch-btn-primary" id="patchConfirmOkBtn"></button>
    </div>

</dialog><!-- /patchConfirmDialog -->
