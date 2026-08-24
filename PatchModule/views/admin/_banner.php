<?php

/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * Patch Update Toast partial — fixed top-right notification shown when at least
 * one patch is available and the module is not disabled.
 *
 * Variables expected (passed via extract($data) or included scope):
 *   $tr         (callable) — translator callable
 *   $baseUrl    (string)   — base URL for patch-management routes
 *   $csrfToken  (string)   — CSRF token
 *   $disabled   (bool)     — true when module is unavailable
 *   $patches    (array)    — available patch records (pre-fetched by host)
 */

use PatchModule\PatchIcon;

if (($disabled ?? false) || empty($patches)) {
    return;
}

$latestPatch   = $patches[0];
$patchCount    = count($patches);
$latestVersion = htmlspecialchars((string) ($latestPatch['version'] ?? ''));
$oldestVersion = htmlspecialchars((string) ($patches[$patchCount - 1]['version'] ?? $latestVersion));

if ($patchCount === 1) {
    $toastText = htmlspecialchars(
        $tr('TEXT_PATCH_UPDATE_AVAILABLE', $latestVersion)
    );
} else {
    $toastText = htmlspecialchars(
        $tr('TEXT_PATCH_UPDATES_AVAILABLE', $patchCount, $oldestVersion, $latestVersion)
    );
}
?>
<div id="patchUpdateBanner"
     class="patch-root patch-update-toast"
     <?php include __DIR__ . '/_mount_data.php'; ?>>
    <div class="patch-toast-inner">
        <span class="patch-toast-icon">
            <?= PatchIcon::svg('arrow-up-circle-fill') ?>
        </span>
        <span class="patch-toast-message"><?= $toastText ?></span>
        <button type="button" class="patch-btn patch-btn-sm patch-btn-light patch-banner-details-btn"
                id="patchBannerDetailsBtn">
            <?= htmlspecialchars($tr('TEXT_PATCH_VIEW_DETAILS')) ?>
        </button>
    </div>
</div>
<?php include __DIR__ . '/_modal.php'; ?>
<?php include __DIR__ . '/_confirm_dialog.php'; ?>
