<?php
/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * Patch Management admin index view — lists available patches and install
 * history, provides install/rollback actions via the patch update modal, and
 * offers a manual patch upload card that works regardless of remote availability.
 *
 * Variables expected (passed via extract($data)):
 *   $tr             (callable)          — translator callable
 *   $baseUrl        (string)            — base URL for patch-management routes
 *   $csrfToken      (string)            — CSRF token
 *   $disabled       (bool)             — true when remote channel is unavailable
 *   $disabledReason (string)           — human-readable reason when disabled
 *   $currentVersion (string)           — currently installed application version
 *   $patches        (array)            — available patch records (remote + uploaded merged)
 *   $history        (array)            — patch_history records
 *   $userMap        (array<int,string>) — maps installed_by user IDs to display names
 */

use PatchModule\PatchHistoryStatus;

/** @var callable $tr */
/** @var string   $baseUrl */
/** @var string   $csrfToken */
/** @var bool     $disabled */
/** @var string   $disabledReason */
/** @var string   $currentVersion */
/** @var array    $patches */
/** @var array    $history */
/** @var array    $userMap */

// ─── Status badge helper ─────────────────────────────────────────────────────
/**
 * Return Bootstrap badge HTML for a patch history status value.
 *
 * @param string   $status  Raw status value from patch_history
 * @param callable $tr      Translator callable
 * @return string           HTML badge string (already escaped)
 */
if (!function_exists('patchStatusBadge')) {
    function patchStatusBadge(string $status, callable $tr): string
    {
        $map = [
            PatchHistoryStatus::AVAILABLE   => ['bg-secondary', 'TEXT_PATCH_HISTORY_STATUS_AVAILABLE'],
            PatchHistoryStatus::DOWNLOADING => ['bg-info',      'TEXT_PATCH_HISTORY_STATUS_DOWNLOADING'],
            PatchHistoryStatus::INSTALLING  => ['bg-warning',   'TEXT_PATCH_HISTORY_STATUS_INSTALLING'],
            PatchHistoryStatus::COMPLETED   => ['bg-success',   'TEXT_PATCH_HISTORY_STATUS_COMPLETED'],
            PatchHistoryStatus::FAILED      => ['bg-danger',    'TEXT_PATCH_HISTORY_STATUS_FAILED'],
            PatchHistoryStatus::ROLLED_BACK => ['bg-dark',      'TEXT_PATCH_HISTORY_STATUS_ROLLED_BACK'],
        ];

        [$cls, $key] = $map[$status] ?? ['bg-secondary', 'TEXT_PATCH_HISTORY_STATUS_AVAILABLE'];

        return '<span class="badge ' . htmlspecialchars($cls) . '">'
            . htmlspecialchars($tr($key))
            . '</span>';
    }
}
?>
<div id="patch-mount"
     data-base-url="<?= htmlspecialchars($baseUrl) ?>"
     data-csrf-token="<?= htmlspecialchars($csrfToken) ?>"
     data-current-version="<?= htmlspecialchars($currentVersion) ?>"
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
         'updateXofN'     => $tr('TEXT_PATCH_UPDATE_X_OF_N'),
         'installAll'     => $tr('TEXT_BUTTON_INSTALL_ALL_UPDATES'),
         'installNext'    => $tr('TEXT_BUTTON_INSTALL_NEXT'),
         'allDone'        => $tr('TEXT_MESSAGE_ALL_PATCHES_DONE'),
         'noReleaseNotes' => $tr('TEXT_LABEL_NO_RELEASE_NOTES'),
         'checkNoUpdates' => $tr('TEXT_MESSAGE_PATCH_CHECK_NO_UPDATES'),
         'checkFound'     => $tr('TEXT_MESSAGE_PATCH_CHECK_FOUND'),
         'checkFailed'    => $tr('TEXT_MESSAGE_PATCH_CHECK_FAILED'),
         'genericError'   => $tr('TEXT_PATCH_ERROR_REQUEST_FAILED'),
     ], JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>'
     data-upload-i18n='<?= htmlspecialchars(json_encode([
         'uploading'        => $tr('TEXT_MANUAL_UPLOAD_UPLOADING'),
         'badge'            => $tr('TEXT_MANUAL_UPLOAD_BADGE'),
         'versionGapConfirm' => $tr('TEXT_PATCH_WARNING_VERSION_GAP', '%s'),
     ], JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>'>

<?php if ($disabled): ?>
    <div class="alert alert-warning mb-4" role="alert">
        <i class="bi bi-exclamation-triangle me-2"></i>
        <?= htmlspecialchars($disabledReason) ?>
    </div>
<?php endif; ?>

    <!-- Page header -->
    <div class="d-flex align-items-center justify-content-between mb-4">
        <div>
            <h4 class="mb-1"><?= htmlspecialchars($tr('TEXT_HEADING_PATCH_MANAGEMENT')) ?></h4>
            <span class="badge bg-secondary">
                <?= htmlspecialchars($tr('TEXT_LABEL_CURRENT_VERSION')) ?>:
                v<?= htmlspecialchars($currentVersion) ?>
            </span>
        </div>
        <?php if (!$disabled): ?>
            <button type="button" class="btn btn-outline-primary btn-sm" id="patchCheckUpdatesBtn">
                <i class="bi bi-arrow-clockwise me-1"></i><?= htmlspecialchars($tr('TEXT_ACTION_CHECK_PATCH')) ?>
            </button>
        <?php endif; ?>
    </div>

    <!-- Manual upload accordion — collapsed by default, works without remote connectivity -->
    <div class="accordion mb-4" id="patchManualUploadAccordion">
        <div class="accordion-item">
            <h2 class="accordion-header">
                <button class="accordion-button collapsed"
                        type="button"
                        data-bs-toggle="collapse"
                        data-bs-target="#patchManualUploadCollapse"
                        aria-expanded="false"
                        aria-controls="patchManualUploadCollapse">
                    <i class="bi bi-upload me-2"></i><?= htmlspecialchars($tr('TEXT_HEADING_MANUAL_UPLOAD')) ?>
                </button>
            </h2>
            <div id="patchManualUploadCollapse"
                 class="accordion-collapse collapse"
                 data-bs-parent="#patchManualUploadAccordion">
                <div class="accordion-body">
                    <p class="text-muted mb-3"><?= htmlspecialchars($tr('TEXT_MANUAL_UPLOAD_DESCRIPTION')) ?></p>
                    <div class="alert alert-warning" role="alert">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i><?= htmlspecialchars($tr('TEXT_MANUAL_UPLOAD_TRUST_WARNING')) ?>
                    </div>
                    <form id="patchUploadForm"
                          data-action="<?= htmlspecialchars($baseUrl . '/upload') ?>">
                        <div class="mb-3">
                            <label for="patchUploadFile" class="form-label fw-semibold">
                                <?= htmlspecialchars($tr('TEXT_LABEL_PATCH_FILE')) ?>
                            </label>
                            <input type="file" class="form-control" id="patchUploadFile" accept=".tgz" required>
                            <div class="form-text"><?= htmlspecialchars($tr('TEXT_LABEL_PATCH_FILE_HINT')) ?></div>
                        </div>
                        <div class="d-none mb-3" id="patchUploadProgressWrap">
                            <div class="progress">
                                <div class="progress-bar progress-bar-striped progress-bar-animated"
                                     role="progressbar"
                                     style="width: 0%"
                                     id="patchUploadProgressBar"
                                     aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"></div>
                            </div>
                        </div>
                        <div class="d-none small text-muted mb-3" id="patchUploadStatus"></div>
                        <button type="submit" class="btn btn-primary" id="patchUploadSubmitBtn">
                            <i class="bi bi-upload me-1"></i><?= htmlspecialchars($tr('TEXT_BUTTON_UPLOAD_PATCH')) ?>
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Available patches card -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0">
                <i class="bi bi-download me-2"></i><?= htmlspecialchars($tr('TEXT_HEADING_AVAILABLE_PATCHES')) ?>
                <?php if (!empty($patches)): ?>
                    <span class="badge bg-primary ms-2"><?= count($patches) ?></span>
                <?php endif; ?>
            </h5>
        </div>
        <div class="card-body p-0">
            <?php if (empty($patches)): ?>
                <p class="text-muted p-3 mb-0">
                    <i class="bi bi-check-circle me-1 text-success"></i>
                    <?= htmlspecialchars($tr('TEXT_ERROR_NO_PATCH_AVAILABLE')) ?>
                </p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th><?= htmlspecialchars($tr('TEXT_LABEL_NEW_VERSION')) ?></th>
                                <th><?= htmlspecialchars($tr('TEXT_LABEL_RELEASED_AT')) ?></th>
                                <th><?= htmlspecialchars($tr('TEXT_LABEL_FILE_SIZE')) ?></th>
                                <th class="text-end"><?= htmlspecialchars($tr('TEXT_LABEL_ACTIONS')) ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($patches as $patch): ?>
                                <?php
                                $patchId         = (int) ($patch['id'] ?? 0);
                                $patchVersion    = htmlspecialchars((string) ($patch['version'] ?? ''));
                                $patchDate       = htmlspecialchars((string) ($patch['released_at'] ?? ''));
                                $patchSize       = (int) ($patch['file_size'] ?? 0);
                                $isUploaded      = !empty($patch['is_uploaded']);
                                ?>
                                <tr>
                                    <td>
                                        <span class="fw-semibold font-monospace">v<?= $patchVersion ?></span>
                                        <?php if ($isUploaded): ?>
                                            <span class="badge bg-secondary ms-1"><?= htmlspecialchars($tr('TEXT_MANUAL_UPLOAD_BADGE')) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-muted small"><?= $patchDate !== '' ? $patchDate : '—' ?></td>
                                    <td class="text-muted small"><?= $patchSize > 0 ? number_format($patchSize / 1024 / 1024, 1) . ' MB' : '—' ?></td>
                                    <td class="text-end">
                                        <button type="button"
                                                class="btn btn-sm btn-outline-secondary me-1 patch-details-btn"
                                                data-patch-id="<?= $patchId ?>"
                                                data-patch-version="<?= $patchVersion ?>">
                                            <i class="bi bi-info-circle me-1"></i><?= htmlspecialchars($tr('TEXT_PATCH_VIEW_DETAILS')) ?>
                                        </button>
                                        <button type="button"
                                                class="btn btn-sm btn-primary patch-install-btn"
                                                data-patch-id="<?= $patchId ?>"
                                                data-patch-version="<?= $patchVersion ?>">
                                            <i class="bi bi-arrow-up-circle me-1"></i><?= htmlspecialchars($tr('TEXT_ACTION_INSTALL_PATCH')) ?>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Patch history card -->
    <div class="card">
        <div class="card-header">
            <h5 class="mb-0">
                <i class="bi bi-clock-history me-2"></i><?= htmlspecialchars($tr('TEXT_HEADING_PATCH_HISTORY')) ?>
            </h5>
        </div>
        <div class="card-body p-0">
            <?php if (empty($history)): ?>
                <p class="text-muted p-3 mb-0">
                    <?= htmlspecialchars($tr('TEXT_MESSAGE_NO_PATCH_HISTORY')) ?>
                </p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th><?= htmlspecialchars($tr('TEXT_LABEL_VERSION')) ?></th>
                                <th><?= htmlspecialchars($tr('TEXT_LABEL_PREVIOUS_VERSION')) ?></th>
                                <th><?= htmlspecialchars($tr('TEXT_LABEL_STATUS')) ?></th>
                                <th><?= htmlspecialchars($tr('TEXT_LABEL_INSTALLED_AT')) ?></th>
                                <th><?= htmlspecialchars($tr('TEXT_LABEL_INSTALLED_BY')) ?></th>
                                <th class="text-end"><?= htmlspecialchars($tr('TEXT_LABEL_ACTIONS')) ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($history as $record): ?>
                                <?php
                                $recordId           = (int) ($record['id'] ?? 0);
                                $recVersion         = htmlspecialchars((string) ($record['version'] ?? ''));
                                $recPrevVersion     = htmlspecialchars((string) ($record['previous_version'] ?? '-'));
                                $recStatus          = (string) ($record['status'] ?? 'available');
                                $recInstalledAt     = htmlspecialchars((string) ($record['installed_at'] ?? ''));
                                $recInstalledBy     = (int) ($record['installed_by'] ?? 0);
                                $recInstalledByName = htmlspecialchars($userMap[$recInstalledBy] ?? '-');
                                $recErrorMsg        = htmlspecialchars((string) ($record['error_message'] ?? ''));
                                $canRollback        = $recStatus === PatchHistoryStatus::COMPLETED;
                                ?>
                                <tr>
                                    <td class="fw-semibold font-monospace">v<?= $recVersion ?></td>
                                    <td class="text-muted font-monospace">
                                        <?= $recPrevVersion !== '-' ? 'v' . $recPrevVersion : '-' ?>
                                    </td>
                                    <td>
                                        <?= patchStatusBadge($recStatus, $tr) ?>
                                        <?php if ($recStatus === 'failed' && $recErrorMsg !== ''): ?>
                                            <span class="d-block text-danger small mt-1"><?= $recErrorMsg ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-muted small"><?= $recInstalledAt ?></td>
                                    <td class="text-muted small"><?= $recInstalledByName ?></td>
                                    <td class="text-end">
                                        <?php if ($canRollback): ?>
                                            <button type="button"
                                                    class="btn btn-sm btn-outline-danger patch-rollback-btn"
                                                    data-id="<?= $recordId ?>">
                                                <i class="bi bi-arrow-counterclockwise me-1"></i><?= htmlspecialchars($tr('TEXT_ACTION_ROLLBACK_PATCH')) ?>
                                            </button>
                                        <?php else: ?>
                                            <span class="text-muted small">—</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

</div><!-- /patch-mount -->

<?php include __DIR__ . '/_modal.php'; ?>
