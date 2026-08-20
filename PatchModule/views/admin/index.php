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
 *   $installableId  (int|null)         — patch_history ID of the oldest installable patch
 */

use PatchModule\PatchHistoryStatus;
use PatchModule\PatchIcon;

/** @var callable  $tr */
/** @var string    $baseUrl */
/** @var string    $csrfToken */
/** @var bool      $disabled */
/** @var string    $disabledReason */
/** @var string    $currentVersion */
/** @var array     $patches */
/** @var array     $history */
/** @var array     $userMap */
/** @var int|null  $installableId */

// ─── Status badge helper ─────────────────────────────────────────────────────
/**
 * Return badge HTML for a patch history status value.
 *
 * @param string   $status  Raw status value from patch_history
 * @param callable $tr      Translator callable
 * @return string           HTML badge string (already escaped)
 */
if (!function_exists('patchStatusBadge')) {
    function patchStatusBadge(string $status, callable $tr): string
    {
        $map = [
            PatchHistoryStatus::AVAILABLE   => ['patch-badge-secondary', 'TEXT_PATCH_HISTORY_STATUS_AVAILABLE'],
            PatchHistoryStatus::DOWNLOADING => ['patch-badge-info',      'TEXT_PATCH_HISTORY_STATUS_DOWNLOADING'],
            PatchHistoryStatus::INSTALLING  => ['patch-badge-warning',   'TEXT_PATCH_HISTORY_STATUS_INSTALLING'],
            PatchHistoryStatus::COMPLETED   => ['patch-badge-success',   'TEXT_PATCH_HISTORY_STATUS_COMPLETED'],
            PatchHistoryStatus::FAILED      => ['patch-badge-danger',    'TEXT_PATCH_HISTORY_STATUS_FAILED'],
            PatchHistoryStatus::OBSOLETE    => ['patch-badge-secondary patch-badge-obsolete', 'TEXT_PATCH_HISTORY_STATUS_OBSOLETE'],
            PatchHistoryStatus::ROLLED_BACK => ['patch-badge-dark',      'TEXT_PATCH_HISTORY_STATUS_ROLLED_BACK'],
        ];

        [$cls, $key] = $map[$status] ?? ['patch-badge-secondary', 'TEXT_PATCH_HISTORY_STATUS_AVAILABLE'];

        return '<span class="patch-badge ' . htmlspecialchars($cls) . '">'
            . htmlspecialchars($tr($key))
            . '</span>';
    }
}
?>
<div id="patch-mount"
     class="patch-root"
     <?php include __DIR__ . '/_mount_data.php'; ?>>

<?php if ($disabled): ?>
    <div class="patch-alert patch-alert-warning" role="alert">
        <?= PatchIcon::svg('exclamation-triangle') ?>
        <?= htmlspecialchars($disabledReason) ?>
    </div>
<?php endif; ?>

    <!-- Page header -->
    <div class="patch-page-header">
        <div>
            <h4><?= htmlspecialchars($tr('TEXT_HEADING_PATCH_MANAGEMENT')) ?></h4>
            <span class="patch-badge patch-badge-secondary">
                <?= htmlspecialchars($tr('TEXT_LABEL_CURRENT_VERSION')) ?>:
                v<?= htmlspecialchars($currentVersion) ?>
            </span>
        </div>
        <?php if (!$disabled): ?>
            <button type="button" class="patch-btn patch-btn-outline-primary patch-btn-sm" id="patchCheckUpdatesBtn">
                <?= PatchIcon::svg('arrow-clockwise') ?><?= htmlspecialchars($tr('TEXT_ACTION_CHECK_PATCH')) ?>
            </button>
        <?php endif; ?>
    </div>

    <!-- Manual upload — native <details>, collapsed by default, works without remote connectivity -->
    <details class="patch-details">
        <summary class="patch-summary">
            <?= PatchIcon::svg('upload') ?><?= htmlspecialchars($tr('TEXT_HEADING_MANUAL_UPLOAD')) ?>
            <?= PatchIcon::svg('chevron-down', 'patch-icon-chevron') ?>
        </summary>
        <div class="patch-details-body">
            <p class="patch-text-muted"><?= htmlspecialchars($tr('TEXT_MANUAL_UPLOAD_DESCRIPTION')) ?></p>
            <div class="patch-alert patch-alert-warning" role="alert">
                <?= PatchIcon::svg('exclamation-triangle-fill') ?><?= htmlspecialchars($tr('TEXT_MANUAL_UPLOAD_TRUST_WARNING')) ?>
            </div>
            <form id="patchUploadForm"
                  data-action="<?= htmlspecialchars($baseUrl . '/upload') ?>">
                <div class="patch-form-group">
                    <label for="patchUploadFile" class="patch-label">
                        <?= htmlspecialchars($tr('TEXT_LABEL_PATCH_FILE')) ?>
                    </label>
                    <input type="file" class="patch-input" id="patchUploadFile" accept=".tgz" required>
                    <div class="patch-form-text"><?= htmlspecialchars($tr('TEXT_LABEL_PATCH_FILE_HINT')) ?></div>
                </div>
                <div class="patch-hidden patch-form-group" id="patchUploadProgressWrap">
                    <div class="patch-progress">
                        <div class="patch-progress-bar patch-striped patch-animated"
                             role="progressbar"
                             style="width: 0%"
                             id="patchUploadProgressBar"
                             aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"></div>
                    </div>
                </div>
                <div class="patch-hidden patch-small patch-text-muted patch-form-group" id="patchUploadStatus"></div>
                <button type="submit" class="patch-btn patch-btn-primary" id="patchUploadSubmitBtn">
                    <?= PatchIcon::svg('upload') ?><?= htmlspecialchars($tr('TEXT_BUTTON_UPLOAD_PATCH')) ?>
                </button>
            </form>
        </div>
    </details>

    <!-- Available patches card -->
    <div class="patch-card">
        <div class="patch-card-header">
            <?= PatchIcon::svg('download') ?><?= htmlspecialchars($tr('TEXT_HEADING_AVAILABLE_PATCHES')) ?>
            <?php if (!empty($patches)): ?>
                <span class="patch-badge patch-badge-primary"><?= count($patches) ?></span>
            <?php endif; ?>
        </div>
        <div class="patch-card-body patch-card-body-flush">
            <?php if (empty($patches)): ?>
                <p class="patch-text-muted" style="padding: 1rem;">
                    <?= PatchIcon::svg('check-circle', 'patch-text-success') ?>
                    <?= htmlspecialchars($tr('TEXT_ERROR_NO_PATCH_AVAILABLE')) ?>
                </p>
            <?php else: ?>
                <div class="patch-table-responsive">
                    <table class="patch-table">
                        <thead>
                            <tr>
                                <th><?= htmlspecialchars($tr('TEXT_LABEL_NEW_VERSION')) ?></th>
                                <th><?= htmlspecialchars($tr('TEXT_LABEL_RELEASED_AT')) ?></th>
                                <th><?= htmlspecialchars($tr('TEXT_LABEL_FILE_SIZE')) ?></th>
                                <th class="patch-table-end"><?= htmlspecialchars($tr('TEXT_LABEL_ACTIONS')) ?></th>
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
                                $isInstallable   = ($patchId > 0 && $patchId === $installableId);
                                ?>
                                <tr>
                                    <td>
                                        <span class="patch-mono" style="font-weight: 600;">v<?= $patchVersion ?></span>
                                        <?php if ($isUploaded): ?>
                                            <span class="patch-badge patch-badge-secondary"><?= htmlspecialchars($tr('TEXT_MANUAL_UPLOAD_BADGE')) ?></span>
                                        <?php endif; ?>
                                        <?php if (!$isInstallable): ?>
                                            <span class="patch-badge patch-badge-light"><?= htmlspecialchars($tr('TEXT_LABEL_QUEUED_PATCH')) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="patch-text-muted patch-small"><?= $patchDate !== '' ? $patchDate : '—' ?></td>
                                    <td class="patch-text-muted patch-small"><?= $patchSize > 0 ? number_format($patchSize / 1024 / 1024, 1) . ' MB' : '—' ?></td>
                                    <td class="patch-table-end">
                                        <button type="button"
                                                class="patch-btn patch-btn-sm patch-btn-outline-secondary patch-details-btn"
                                                data-patch-id="<?= $patchId ?>"
                                                data-patch-version="<?= $patchVersion ?>">
                                            <?= PatchIcon::svg('info-circle') ?><?= htmlspecialchars($tr('TEXT_PATCH_VIEW_DETAILS')) ?>
                                        </button>
                                        <?php if ($isInstallable): ?>
                                            <button type="button"
                                                    class="patch-btn patch-btn-sm patch-btn-primary patch-install-btn"
                                                    data-patch-id="<?= $patchId ?>"
                                                    data-patch-version="<?= $patchVersion ?>">
                                                <?= PatchIcon::svg('arrow-up-circle') ?><?= htmlspecialchars($tr('TEXT_ACTION_INSTALL_PATCH')) ?>
                                            </button>
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

    <!-- Patch history card -->
    <div class="patch-card">
        <div class="patch-card-header">
            <?= PatchIcon::svg('clock-history') ?><?= htmlspecialchars($tr('TEXT_HEADING_PATCH_HISTORY')) ?>
        </div>
        <div class="patch-card-body patch-card-body-flush">
            <?php if (empty($history)): ?>
                <p class="patch-text-muted" style="padding: 1rem;">
                    <?= htmlspecialchars($tr('TEXT_MESSAGE_NO_PATCH_HISTORY')) ?>
                </p>
            <?php else: ?>
                <div class="patch-table-responsive">
                    <table class="patch-table">
                        <thead>
                            <tr>
                                <th><?= htmlspecialchars($tr('TEXT_LABEL_VERSION')) ?></th>
                                <th><?= htmlspecialchars($tr('TEXT_LABEL_PREVIOUS_VERSION')) ?></th>
                                <th><?= htmlspecialchars($tr('TEXT_LABEL_STATUS')) ?></th>
                                <th><?= htmlspecialchars($tr('TEXT_LABEL_INSTALLED_AT')) ?></th>
                                <th><?= htmlspecialchars($tr('TEXT_LABEL_INSTALLED_BY')) ?></th>
                                <th class="patch-table-end"><?= htmlspecialchars($tr('TEXT_LABEL_ACTIONS')) ?></th>
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
                                $isObsolete         = $recStatus === PatchHistoryStatus::OBSOLETE;
                                ?>
                                <tr<?= $isObsolete ? ' class="patch-row-obsolete"' : '' ?>>
                                    <td class="patch-mono" style="font-weight: 600;">v<?= $recVersion ?></td>
                                    <td class="patch-text-muted patch-mono">
                                        <?= $recPrevVersion !== '-' ? 'v' . $recPrevVersion : '-' ?>
                                    </td>
                                    <td>
                                        <?= patchStatusBadge($recStatus, $tr) ?>
                                        <?php if (($record['patch_server_id'] ?? null) === null): ?>
                                            <span class="patch-badge patch-badge-secondary"><?= htmlspecialchars($tr('TEXT_MANUAL_UPLOAD_BADGE')) ?></span>
                                        <?php endif; ?>
                                        <?php if ($recStatus === 'failed' && $recErrorMsg !== ''): ?>
                                            <span class="patch-text-danger patch-small" style="display: block; margin-top: 0.25rem;"><?= $recErrorMsg ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="patch-text-muted patch-small"><?= $recInstalledAt ?></td>
                                    <td class="patch-text-muted patch-small"><?= $recInstalledByName ?></td>
                                    <td class="patch-table-end">
                                        <button type="button"
                                                class="patch-btn patch-btn-sm patch-btn-outline-secondary patch-changelog-btn"
                                                data-id="<?= $recordId ?>"
                                                data-version="<?= $recVersion ?>">
                                            <?= PatchIcon::svg('journal-text') ?><?= htmlspecialchars($tr('TEXT_BUTTON_SHOW_CHANGELOG')) ?>
                                        </button>
                                        <?php if ($canRollback): ?>
                                            <button type="button"
                                                    class="patch-btn patch-btn-sm patch-btn-outline-danger patch-rollback-btn"
                                                    data-id="<?= $recordId ?>">
                                                <?= PatchIcon::svg('arrow-counterclockwise') ?><?= htmlspecialchars($tr('TEXT_ACTION_ROLLBACK_PATCH')) ?>
                                            </button>
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
<?php include __DIR__ . '/_changelog_modal.php'; ?>
<?php include __DIR__ . '/_confirm_dialog.php'; ?>
