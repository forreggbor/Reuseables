<?php

/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * Patch Changelog Modal partial — native <dialog> for displaying per-version
 * release notes from patch history. Not marked no-esc: ESC and clicking
 * outside the dialog both dismiss it (data-patch-light-dismiss wires the
 * backdrop click in JS; ESC is native <dialog> behavior, unsuppressed).
 *
 * Once-guard: this partial renders only once per request even if included multiple times.
 *
 * Variables expected (passed via extract($data) or included scope):
 *   $tr (callable) — translator callable
 */

use PatchModule\PatchIcon;

if (!empty($GLOBALS['__PATCH_CHANGELOG_MODAL_RENDERED'])) {
    return;
}
$GLOBALS['__PATCH_CHANGELOG_MODAL_RENDERED'] = true;
?>
<dialog id="patchChangelogModal" class="patch-root patch-dialog patch-dialog-lg"
        aria-labelledby="patchChangelogModalLabel" data-patch-light-dismiss>

    <!-- Header -->
    <div class="patch-dialog-header patch-dialog-header-plain">
        <h5 class="patch-dialog-title" id="patchChangelogModalLabel">
            <?= PatchIcon::svg('journal-text') ?><?= htmlspecialchars($tr('TEXT_HEADING_PATCH_CHANGELOG')) ?><span id="patchChangelogVersion" class="patch-text-muted patch-mono" style="margin-left: 0.5rem;"></span>
        </h5>
        <button type="button" class="patch-btn-close" data-patch-dismiss
                aria-label="<?= htmlspecialchars($tr('TEXT_BUTTON_CLOSE')) ?>"><?= PatchIcon::svg('x-lg') ?></button>
    </div>

    <!-- Body -->
    <div class="patch-dialog-body">
        <div id="patchChangelogContent" class="patch-changelog-content patch-md patch-hidden"></div>
        <div id="patchChangelogEmpty" class="patch-text-muted">
            <?= htmlspecialchars($tr('TEXT_LABEL_NO_RELEASE_NOTES')) ?>
        </div>
    </div>

    <!-- Footer -->
    <div class="patch-dialog-footer">
        <button type="button" class="patch-btn patch-btn-secondary"
                data-patch-dismiss><?= htmlspecialchars($tr('TEXT_BUTTON_CLOSE')) ?></button>
    </div>

</dialog><!-- /patchChangelogModal -->
