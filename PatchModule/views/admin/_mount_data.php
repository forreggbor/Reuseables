<?php

/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * Shared data-* attribute block for #patch-mount and #patchUpdateBanner.
 *
 * Both mount points instantiate the same PatchUpdate JS object (showDetails()
 * is reachable from either one), so both need the same JSON payloads — before
 * this partial existed, index.php and _banner.php each carried their own
 * near-duplicate copy, and the banner's copy was missing a few i18n keys that
 * showDetails() relies on (a "no update available" / "failed to load" message
 * would silently fall back to a hardcoded English string when reached from the
 * banner). Sharing one copy removes both the duplication and that gap.
 *
 * Meant to be `include`d directly inside the opening tag of the mount element,
 * e.g.:
 *   <div id="patch-mount" <?php include __DIR__ . '/_mount_data.php'; ?>>
 *
 * Variables expected in scope (via extract($data) in the including view):
 *   $tr             (callable)     — translator callable
 *   $baseUrl        (string)       — base URL for patch-management routes
 *   $csrfToken      (string)       — CSRF token
 *   $currentVersion (string|null)  — omit entirely on the banner; index.php only
 */

use PatchModule\PatchIcon;

/** @var callable $tr */
/** @var string $baseUrl */
/** @var string $csrfToken */
/** @var string|null $currentVersion */

$patchIcons = PatchIcon::map([
    'circle', 'check-circle-fill', 'arrow-repeat', 'x-circle-fill', 'arrow-right-circle',
    'arrow-up-circle', 'shield-lock', 'file-earmark-zip', 'calendar3',
]);
?>
data-base-url="<?= htmlspecialchars($baseUrl) ?>"
data-csrf-token="<?= htmlspecialchars($csrfToken) ?>"
<?php if (isset($currentVersion)): ?>
data-current-version="<?= htmlspecialchars($currentVersion) ?>"
<?php endif; ?>
data-icons='<?= htmlspecialchars(json_encode($patchIcons, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>'
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
    'invalid_archive'                    => $tr('TEXT_PATCH_ERROR_INVALID_ARCHIVE'),
    'invalid_manifest_path'              => $tr('TEXT_PATCH_ERROR_INVALID_MANIFEST_PATH'),
    'invalid_manifest_schema'            => $tr('TEXT_PATCH_ERROR_INVALID_MANIFEST_SCHEMA'),
    'install_in_progress'                => $tr('TEXT_PATCH_ERROR_INSTALL_IN_PROGRESS'),
    'network_error'                      => $tr('TEXT_PATCH_ERROR_NETWORK_ERROR'),
    'rate_limited'                       => $tr('TEXT_PATCH_ERROR_RATE_LIMITED'),
    'signing_unavailable'                => $tr('TEXT_PATCH_ERROR_SIGNING_UNAVAILABLE'),
    'server_error'                       => $tr('TEXT_PATCH_ERROR_SERVER_ERROR'),
    'not_recently_verified'              => $tr('TEXT_PATCH_ERROR_NOT_RECENTLY_VERIFIED'),
    'package_mismatch'                   => $tr('TEXT_PATCH_ERROR_PACKAGE_MISMATCH'),
    'invalid_license'                    => $tr('TEXT_PATCH_ERROR_INVALID_LICENSE'),
    'license_expired'                    => $tr('TEXT_PATCH_ERROR_LICENSE_EXPIRED'),
    'license_ip_mismatch'                => $tr('TEXT_PATCH_ERROR_LICENSE_IP_MISMATCH'),
    'license_revoked'                    => $tr('TEXT_PATCH_ERROR_LICENSE_REVOKED'),
    'verification_failed'                => $tr('TEXT_PATCH_ERROR_VERIFICATION_FAILED'),
    'upload_failed'                      => $tr('TEXT_PATCH_ERROR_UPLOAD_FAILED'),
    'upload_invalid_archive'             => $tr('TEXT_PATCH_ERROR_UPLOAD_INVALID_ARCHIVE'),
    'upload_invalid_manifest'            => $tr('TEXT_PATCH_ERROR_UPLOAD_INVALID_MANIFEST'),
    'upload_invalid_mime'                => $tr('TEXT_PATCH_ERROR_UPLOAD_INVALID_MIME'),
    'upload_too_large'                   => $tr('TEXT_PATCH_ERROR_UPLOAD_TOO_LARGE'),
    'upload_version_already_installed'   => $tr('TEXT_PATCH_ERROR_UPLOAD_VERSION_ALREADY_INSTALLED'),
    'upload_version_downgrade'           => $tr('TEXT_PATCH_ERROR_UPLOAD_VERSION_DOWNGRADE'),
], JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>'
data-i18n='<?= htmlspecialchars(json_encode([
    'updateXofN'          => $tr('TEXT_PATCH_UPDATE_X_OF_N'),
    'installAll'          => $tr('TEXT_BUTTON_INSTALL_ALL_UPDATES'),
    'installNext'         => $tr('TEXT_BUTTON_INSTALL_NEXT'),
    'allDone'             => $tr('TEXT_MESSAGE_ALL_PATCHES_DONE'),
    'noReleaseNotes'      => $tr('TEXT_LABEL_NO_RELEASE_NOTES'),
    'checkNoUpdates'      => $tr('TEXT_MESSAGE_PATCH_CHECK_NO_UPDATES'),
    'checkFound'          => $tr('TEXT_MESSAGE_PATCH_CHECK_FOUND'),
    'checkFailed'         => $tr('TEXT_MESSAGE_PATCH_CHECK_FAILED'),
    'genericError'        => $tr('TEXT_PATCH_ERROR_REQUEST_FAILED'),
    'changelogLoadFailed' => $tr('TEXT_MESSAGE_CHANGELOG_LOAD_FAILED'),
    'confirmRollback'     => $tr('TEXT_PATCH_CONFIRM_ROLLBACK'),
    'versionInstalled'    => $tr('TEXT_PATCH_VERSION_INSTALLED'),
], JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>'
data-upload-i18n='<?= htmlspecialchars(json_encode([
    'uploading'         => $tr('TEXT_MANUAL_UPLOAD_UPLOADING'),
    'badge'             => $tr('TEXT_MANUAL_UPLOAD_BADGE'),
    'versionGapConfirm' => $tr('TEXT_PATCH_WARNING_VERSION_GAP', '%s'),
], JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>'
