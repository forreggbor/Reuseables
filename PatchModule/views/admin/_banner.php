<?php
/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * Patch Update Banner partial — sticky top banner shown in the admin layout
 * when at least one patch is available and the module is not disabled.
 *
 * Variables expected (passed via extract($data) or included scope):
 *   $tr         (callable) — translator callable
 *   $baseUrl    (string)   — base URL for patch-management routes
 *   $csrfToken  (string)   — CSRF token
 *   $disabled   (bool)     — true when module is unavailable
 *   $patches    (array)    — available patch records (pre-fetched by host)
 */

if (($disabled ?? false) || empty($patches)) {
    return;
}

$latestPatch = $patches[0];
$patchCount  = count($patches);
$latestVersion = htmlspecialchars((string) ($latestPatch['version'] ?? ''));
$oldestVersion = htmlspecialchars((string) ($patches[$patchCount - 1]['version'] ?? $latestVersion));

if ($patchCount === 1) {
    $bannerText = htmlspecialchars(
        $tr('TEXT_PATCH_UPDATE_AVAILABLE', $latestVersion)
    );
} else {
    $bannerText = htmlspecialchars(
        $tr('TEXT_PATCH_UPDATES_AVAILABLE', $patchCount, $oldestVersion, $latestVersion)
    );
}
?>
<div id="patchUpdateBanner"
     class="patch-update-banner"
     data-base-url="<?= htmlspecialchars($baseUrl) ?>"
     data-csrf-token="<?= htmlspecialchars($csrfToken) ?>"
     data-step-labels='<?= htmlspecialchars(json_encode([
         'preflight_checks'    => $tr('TEXT_PATCH_STEP_PREFLIGHT'),
         'create_backup'       => $tr('TEXT_PATCH_STEP_BACKUP'),
         'download_patch'      => $tr('TEXT_PATCH_STEP_DOWNLOAD'),
         'extract_patch'       => $tr('TEXT_PATCH_STEP_EXTRACT'),
         'execute_migration'   => $tr('TEXT_PATCH_STEP_MIGRATION'),
         'copy_files'          => $tr('TEXT_PATCH_STEP_COPY_FILES'),
         'remove_files'        => $tr('TEXT_PATCH_STEP_REMOVE_FILES'),
         'update_version'      => $tr('TEXT_PATCH_STEP_UPDATE_VERSION'),
         'verify_installation' => $tr('TEXT_PATCH_STEP_VERIFY'),
         'cleanup'             => $tr('TEXT_PATCH_STEP_CLEANUP'),
     ], JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>'
     data-queue-labels='<?= htmlspecialchars(json_encode([
         'next'       => $tr('TEXT_PATCH_STATUS_NEXT'),
         'pending'    => $tr('TEXT_PATCH_STATUS_PENDING'),
         'installing' => $tr('TEXT_PATCH_STATUS_INSTALLING'),
         'installed'  => $tr('TEXT_PATCH_STATUS_INSTALLED'),
         'failed'     => $tr('TEXT_PATCH_STATUS_FAILED'),
     ], JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>'
     data-error-labels='<?= htmlspecialchars(json_encode([
         'invalid_archive'         => $tr('TEXT_PATCH_ERROR_INVALID_ARCHIVE'),
         'invalid_manifest_path'   => $tr('TEXT_PATCH_ERROR_INVALID_MANIFEST_PATH'),
         'invalid_manifest_schema' => $tr('TEXT_PATCH_ERROR_INVALID_MANIFEST_SCHEMA'),
         'install_in_progress'     => $tr('TEXT_PATCH_ERROR_INSTALL_IN_PROGRESS'),
         'network_error'           => $tr('TEXT_PATCH_ERROR_NETWORK_ERROR'),
         'rate_limited'            => $tr('TEXT_PATCH_ERROR_RATE_LIMITED'),
         'signing_unavailable'     => $tr('TEXT_PATCH_ERROR_SIGNING_UNAVAILABLE'),
         'server_error'            => $tr('TEXT_PATCH_ERROR_SERVER_ERROR'),
         'not_recently_verified'   => $tr('TEXT_PATCH_ERROR_NOT_RECENTLY_VERIFIED'),
         'package_mismatch'        => $tr('TEXT_PATCH_ERROR_PACKAGE_MISMATCH'),
         'invalid_license'         => $tr('TEXT_PATCH_ERROR_INVALID_LICENSE'),
         'license_expired'         => $tr('TEXT_PATCH_ERROR_LICENSE_EXPIRED'),
         'license_ip_mismatch'     => $tr('TEXT_PATCH_ERROR_LICENSE_IP_MISMATCH'),
         'license_revoked'         => $tr('TEXT_PATCH_ERROR_LICENSE_REVOKED'),
         'verification_failed'     => $tr('TEXT_PATCH_ERROR_VERIFICATION_FAILED'),
     ], JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>'
     data-i18n='<?= htmlspecialchars(json_encode([
         'updateXofN'     => $tr('TEXT_PATCH_UPDATE_X_OF_N'),
         'installAll'     => $tr('TEXT_BUTTON_INSTALL_ALL_UPDATES'),
         'installNext'    => $tr('TEXT_BUTTON_INSTALL_NEXT'),
         'allDone'        => $tr('TEXT_MESSAGE_ALL_PATCHES_DONE'),
         'noReleaseNotes' => $tr('TEXT_LABEL_NO_RELEASE_NOTES'),
     ], JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>'>
    <div class="patch-banner-inner">
        <span class="patch-banner-icon">
            <i class="bi bi-arrow-up-circle-fill"></i>
        </span>
        <span class="patch-banner-message"><?= $bannerText ?></span>
        <div class="patch-banner-actions">
            <button type="button" class="btn btn-sm btn-light patch-banner-details-btn"
                    id="patchBannerDetailsBtn">
                <?= htmlspecialchars($tr('TEXT_PATCH_VIEW_DETAILS')) ?>
            </button>
            <button type="button" class="btn btn-sm btn-outline-light patch-banner-dismiss-btn"
                    id="patchBannerDismissBtn">
                <?= htmlspecialchars($tr('TEXT_PATCH_DISMISS_ALL')) ?>
            </button>
        </div>
    </div>
</div>
<?php include __DIR__ . '/_modal.php'; ?>
