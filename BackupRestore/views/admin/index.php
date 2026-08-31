<?php
/**
 * Backup & Restore dashboard — body fragment for embedding in a host admin layout.
 *
 * Expected variables (host controller passes these):
 * @var callable $t            Translator: function(string $key, array $params = []): string
 * @var string   $baseUrl      Module mount path, no trailing slash (e.g. '/admin/settings/backup-restore')
 * @var string   $csrfToken    CSRF token for the host's forms
 * @var string   $nonce        CSP nonce for inline <script> tags (empty string if the host has no CSP nonce)
 * @var string   $dbName       Target database name, for the restore confirmation step
 * @var array    $stats        BackupEngine::getStats()
 * @var array    $diskSpace    BackupEngine::getDiskSpaceInfo()
 * @var array<int,object> $backups  BackupEngine::listBackups() (each row has ->creator_name)
 * @var bool     $phpFallbackMode  True when Exec\ExecHelper::isExecAvailable() === false
 *
 * Host integration note: this file renders ONLY a body fragment (no <html>/<head>),
 * matching the ActivityLogs/PatchModule convention — the host embeds it in its own
 * admin layout and is responsible for loading css/backup-restore.css and
 * js/backup-restore.js once per page.
 */

$progressPercent = $diskSpace['usage_percent'] ?? 0;
$progressClass = 'br-bg-success';
if ($progressPercent > 80) {
    $progressClass = 'br-bg-danger';
} elseif ($progressPercent > 60) {
    $progressClass = 'br-bg-warning';
}

$typeLabels = [
    'full' => $t('TEXT_OPTION_FULL_BACKUP'),
    'database' => $t('TEXT_OPTION_DATABASE_ONLY'),
    'files' => $t('TEXT_OPTION_FILES_ONLY'),
];
$statusLabels = [
    'completed' => $t('TEXT_STATUS_COMPLETED'),
    'in_progress' => $t('TEXT_STATUS_IN_PROGRESS'),
    'failed' => $t('TEXT_STATUS_FAILED'),
];

$i18nForJs = [];
foreach ([
    'TEXT_BUTTON_CANCEL', 'TEXT_BUTTON_CONTINUE', 'TEXT_BUTTON_DELETE', 'TEXT_BUTTON_RESTORE',
    'TEXT_BUTTON_CREATE_BACKUP', 'TEXT_MESSAGE_CREATING_BACKUP', 'TEXT_MESSAGE_BACKUP_CREATED_SUCCESSFULLY',
    'TEXT_ERROR_BACKUP_FAILED', 'TEXT_ERROR_DOWNLOAD_FAILED', 'TEXT_CONFIRM_DELETE_BACKUP_FILE',
    'TEXT_MESSAGE_BACKUP_FILE_DELETED', 'TEXT_CONFIRM_DELETE_BACKUP_FULL', 'TEXT_MESSAGE_BACKUP_DELETED',
    'TEXT_ERROR_INVALID_DATABASE_NAME', 'TEXT_ERROR_INVALID_PASSWORD', 'TEXT_LABEL_IRREVERSIBLE',
    'TEXT_ERROR_RESTORE_FAILED', 'TEXT_MESSAGE_BACKUP_RESTORED', 'TEXT_PLACEHOLDER_SELECT_SERVER',
    'TEXT_ERROR_SELECT_SERVER', 'TEXT_MESSAGE_TRANSFER_COMPLETE', 'TEXT_ERROR_TRANSFER_FAILED',
    'TEXT_ERROR_SELECT_FILE', 'TEXT_HELP_ONLY_TGZ_FILES', 'TEXT_MESSAGE_FILE_UPLOADED',
    'TEXT_ERROR_UPLOAD_FAILED', 'TEXT_MESSAGE_COPIED_TO_CLIPBOARD',
    'TEXT_RESTORE_STEP_VERIFY_ARCHIVE', 'TEXT_RESTORE_STEP_EXTRACT_DUMP', 'TEXT_RESTORE_STEP_CREATE_SNAPSHOT',
    'TEXT_RESTORE_STEP_PREPARE_TABLES', 'TEXT_RESTORE_STEP_IMPORT_DB', 'TEXT_RESTORE_STEP_VERIFY_DATA',
    'TEXT_RESTORE_STEP_FINALIZE', 'TEXT_RESTORE_STEP_CREATE_TEMP_DB', 'TEXT_RESTORE_STEP_SWAP_DATABASES',
    'TEXT_RESTORE_STEP_EXTRACT_FILES', 'TEXT_RESTORE_STEP_PRE_RESTORE_SNAPSHOT', 'TEXT_RESTORE_STEP_RESTORE_FILES',
] as $jsKey) {
    $i18nForJs[$jsKey] = $t($jsKey);
}
?>
<div class="br-root">
    <script nonce="<?= htmlspecialchars($nonce ?? '', ENT_QUOTES) ?>">
        window.BackupRestoreConfig = {
            baseUrl: <?= json_encode($baseUrl) ?>,
            csrfToken: <?= json_encode($csrfToken) ?>,
            dbName: <?= json_encode($dbName) ?>,
            i18n: <?= json_encode($i18nForJs, JSON_UNESCAPED_UNICODE) ?>
        };
    </script>

    <div class="br-page-header">
        <h2><?= htmlspecialchars($t('TEXT_HEADING_BACKUP_RESTORE')) ?></h2>
        <div class="br-actions">
            <a href="<?= htmlspecialchars($baseUrl) ?>/profiles" class="br-action-card br-secondary">
                <span class="br-action-icon">&#128193;</span>
                <span class="br-action-text"><?= htmlspecialchars($t('TEXT_HEADING_PROFILES')) ?></span>
            </a>
            <a href="<?= htmlspecialchars($baseUrl) ?>/remote-servers" class="br-action-card br-info">
                <span class="br-action-icon">&#9729;</span>
                <span class="br-action-text"><?= htmlspecialchars($t('TEXT_HEADING_REMOTE_SERVERS')) ?></span>
            </a>
        </div>
    </div>

    <?php if (!empty($phpFallbackMode)): ?>
        <div class="br-alert br-alert-info"><?= htmlspecialchars($t('TEXT_INFO_BACKUP_PHP_MODE')) ?></div>
    <?php endif; ?>

    <div class="br-grid">
        <div class="br-stat">
            <div class="br-stat-label"><?= htmlspecialchars($t('TEXT_LABEL_TOTAL_BACKUPS')) ?></div>
            <div class="br-stat-value"><?= (int) $stats['total'] ?></div>
        </div>
        <div class="br-stat br-stat-success">
            <div class="br-stat-label"><?= htmlspecialchars($t('TEXT_LABEL_COMPLETED')) ?></div>
            <div class="br-stat-value"><?= (int) $stats['completed'] ?></div>
        </div>
        <div class="br-stat br-stat-danger">
            <div class="br-stat-label"><?= htmlspecialchars($t('TEXT_LABEL_FAILED')) ?></div>
            <div class="br-stat-value"><?= (int) $stats['failed'] ?></div>
        </div>
        <div class="br-stat br-stat-warning">
            <div class="br-stat-label"><?= htmlspecialchars($t('TEXT_LABEL_STORAGE_USED')) ?></div>
            <div class="br-stat-value"><?= htmlspecialchars($stats['storage']['total_human']) ?></div>
            <div class="br-stat-sub"><?= (int) $stats['storage']['count'] ?> <?= htmlspecialchars($t('TEXT_LABEL_FILES')) ?></div>
        </div>
    </div>

    <div class="br-card">
        <div class="br-card-body">
            <div class="br-page-header" style="margin-bottom:0.25rem;">
                <span class="br-small br-text-muted">
                    <?= htmlspecialchars($t('TEXT_LABEL_DISK_SPACE')) ?>:
                    <?= htmlspecialchars($diskSpace['used_human']) ?> / <?= htmlspecialchars($diskSpace['total_human']) ?>
                    (<?= htmlspecialchars($diskSpace['free_human']) ?> <?= htmlspecialchars($t('TEXT_LABEL_FREE')) ?>)
                </span>
                <span class="br-small br-text-muted"><?= htmlspecialchars((string) $progressPercent) ?>%</span>
            </div>
            <div class="br-progress">
                <div class="br-progress-bar <?= $progressClass ?>" style="width: <?= (float) $progressPercent ?>%"></div>
            </div>
        </div>
    </div>

    <div class="br-card">
        <div class="br-card-header br-clickable" data-br-collapse-target="brCreateBackupBody">
            <h5><?= htmlspecialchars($t('TEXT_HEADING_CREATE_BACKUP')) ?></h5>
        </div>
        <div class="br-collapse" id="brCreateBackupBody">
            <div class="br-card-body">
                <div class="br-form-grid">
                    <div class="br-col-3">
                        <label class="br-label"><?= htmlspecialchars($t('TEXT_LABEL_BACKUP_TYPE')) ?></label>
                        <select class="br-select" id="brBackupType">
                            <option value="full"><?= htmlspecialchars($t('TEXT_OPTION_FULL_BACKUP')) ?></option>
                            <option value="database"><?= htmlspecialchars($t('TEXT_OPTION_DATABASE_ONLY')) ?></option>
                            <option value="files"><?= htmlspecialchars($t('TEXT_OPTION_FILES_ONLY')) ?></option>
                        </select>
                    </div>
                    <div class="br-col-6">
                        <label class="br-label"><?= htmlspecialchars($t('TEXT_LABEL_NOTE')) ?> (<?= htmlspecialchars($t('TEXT_LABEL_OPTIONAL')) ?>)</label>
                        <input type="text" class="br-input" id="brBackupNote" placeholder="<?= htmlspecialchars($t('TEXT_PLACEHOLDER_BACKUP_NOTE')) ?>" maxlength="500">
                    </div>
                    <div class="br-col-3">
                        <label class="br-label">&nbsp;</label>
                        <button type="button" class="br-btn br-btn-primary br-btn-block" id="brBtnCreateBackup" onclick="BackupRestoreUI.createBackup()">
                            <?= htmlspecialchars($t('TEXT_BUTTON_CREATE_BACKUP')) ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="br-card">
        <div class="br-card-header br-clickable" data-br-collapse-target="brRestoreGuideBody">
            <h5><?= htmlspecialchars($t('TEXT_HEADING_MANUAL_RESTORE_GUIDE')) ?></h5>
        </div>
        <div class="br-collapse" id="brRestoreGuideBody">
            <div class="br-card-body">
                <p class="br-text-muted">
                    <?= htmlspecialchars($t('TEXT_MESSAGE_MANUAL_RESTORE_INTRO')) ?>
                    <a href="<?= htmlspecialchars($baseUrl) ?>/download-restore-script" class="br-btn br-btn-sm br-btn-outline-primary">
                        <?= htmlspecialchars($t('TEXT_BUTTON_DOWNLOAD_RESTORE_SCRIPT')) ?>
                    </a>
                </p>
                <?php
                $steps = [1, 2, 3, 4, 5, 6];
                foreach ($steps as $n):
                    ?>
                    <p>
                        <strong><?= (int) $n ?>. <?= htmlspecialchars($t('TEXT_RESTORE_GUIDE_STEP' . $n . '_TITLE')) ?></strong><br>
                        <span class="br-small br-text-muted"><?= htmlspecialchars($t('TEXT_RESTORE_GUIDE_STEP' . $n . '_DESC')) ?></span>
                    </p>
                <?php endforeach; ?>
                <div class="br-alert br-alert-warning br-small br-mb-0">
                    <?= htmlspecialchars($t('TEXT_RESTORE_GUIDE_NOTE_CLI')) ?><br>
                    <code class="br-mono br-small">php restore.php --file=backup.tgz --token=TOKEN --db-host=localhost --db-user=root --db-pass=SECRET --db-name=<?= htmlspecialchars($dbName) ?></code>
                </div>
            </div>
        </div>
    </div>

    <div class="br-card">
        <div class="br-card-header">
            <span><?= htmlspecialchars($t('TEXT_HEADING_BACKUP_HISTORY')) ?> (<?= count($backups) ?>)</span>
            <?php if (!empty($backups)): ?>
                <button type="button" class="br-btn br-btn-sm br-btn-outline-secondary" onclick="BackupRestoreUI.uploadRestoreFile()">
                    <?= htmlspecialchars($t('TEXT_BUTTON_UPLOAD_RESTORE')) ?>
                </button>
            <?php endif; ?>
        </div>
        <div class="br-table-wrap">
            <table class="br-table">
                <thead>
                <tr>
                    <th><?= htmlspecialchars($t('TEXT_LABEL_FILENAME')) ?></th>
                    <th><?= htmlspecialchars($t('TEXT_LABEL_BACKUP_TYPE')) ?></th>
                    <th><?= htmlspecialchars($t('TEXT_LABEL_SIZE')) ?></th>
                    <th><?= htmlspecialchars($t('TEXT_LABEL_STATUS')) ?></th>
                    <th><?= htmlspecialchars($t('TEXT_LABEL_CREATED_AT')) ?></th>
                    <th><?= htmlspecialchars($t('TEXT_LABEL_NOTE')) ?></th>
                    <th class="br-text-center"><?= htmlspecialchars($t('TEXT_LABEL_REMOTE')) ?></th>
                    <th class="br-text-end"><?= htmlspecialchars($t('TEXT_HEADING_ACTIONS')) ?></th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($backups)): ?>
                    <tr>
                        <td colspan="8" class="br-empty-row"><?= htmlspecialchars($t('TEXT_MESSAGE_NO_BACKUPS_FOUND')) ?></td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($backups as $backup): ?>
                        <tr id="backup-row-<?= (int) $backup->id ?>">
                            <td>
                                <span class="br-mono br-small"><?= htmlspecialchars($backup->filename) ?></span>
                                <?php if ($backup->file_deleted_at): ?>
                                    <br><span class="br-badge br-badge-secondary"><?= htmlspecialchars($t('TEXT_LABEL_FILE_DELETED')) ?></span>
                                <?php endif; ?>
                                <?php if (!empty($backup->profile_name)): ?>
                                    <br><span class="br-badge br-badge-info"><?= htmlspecialchars($backup->profile_name) ?></span>
                                <?php endif; ?>
                            </td>
                            <td><span class="br-badge br-badge-primary"><?= htmlspecialchars($typeLabels[$backup->type] ?? $backup->type) ?></span></td>
                            <td><?= $backup->size_bytes ? htmlspecialchars(\BackupRestore\FileSize::format((int) $backup->size_bytes)) : '-' ?></td>
                            <td>
                                <span class="br-badge <?= $backup->status === 'completed' ? 'br-badge-success' : ($backup->status === 'failed' ? 'br-badge-danger' : 'br-badge-warning') ?>">
                                    <?= htmlspecialchars($statusLabels[$backup->status] ?? $backup->status) ?>
                                </span>
                                <?php if ($backup->status === 'failed' && $backup->error_message): ?>
                                    <span class="br-text-danger br-small" title="<?= htmlspecialchars($backup->error_message) ?>">&#9888;</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="br-small"><?= htmlspecialchars($backup->created_at) ?></span>
                                <br><span class="br-text-muted br-small"><?= htmlspecialchars($backup->creator_name) ?></span>
                            </td>
                            <td><span class="br-small br-text-muted"><?= $backup->note ? htmlspecialchars(mb_strimwidth($backup->note, 0, 50, '...')) : '-' ?></span></td>
                            <td class="br-text-center">
                                <?php if ($backup->remote_synced): ?>
                                    <span class="br-text-success" title="<?= htmlspecialchars($backup->remote_server_name ?? '') ?>">&#9729;&#10003;</span>
                                <?php else: ?>
                                    <span class="br-text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="br-text-end">
                                <div class="br-btn-group">
                                    <?php if ($backup->status === 'completed' && !$backup->file_deleted_at): ?>
                                        <button class="br-btn br-btn-sm br-btn-outline-primary" onclick="BackupRestoreUI.downloadBackup(<?= (int) $backup->id ?>)" title="<?= htmlspecialchars($t('TEXT_BUTTON_DOWNLOAD')) ?>">&#8681;</button>
                                        <button class="br-btn br-btn-sm br-btn-outline-warning" onclick="BackupRestoreUI.restoreBackup(<?= (int) $backup->id ?>, '<?= htmlspecialchars($backup->type) ?>')" title="<?= htmlspecialchars($t('TEXT_BUTTON_RESTORE')) ?>">&#8635;</button>
                                        <button class="br-btn br-btn-sm br-btn-outline-info" onclick="BackupRestoreUI.transferBackup(<?= (int) $backup->id ?>)" title="<?= htmlspecialchars($t('TEXT_BUTTON_TRANSFER_REMOTE')) ?>">&#9729;</button>
                                        <button class="br-btn br-btn-sm br-btn-outline-secondary" onclick="BackupRestoreUI.deleteBackupFile(<?= (int) $backup->id ?>)" title="<?= htmlspecialchars($t('TEXT_BUTTON_DELETE_FILE')) ?>">&#128465;</button>
                                    <?php endif; ?>
                                    <button class="br-btn br-btn-sm br-btn-outline-danger" onclick="BackupRestoreUI.deleteBackupFull(<?= (int) $backup->id ?>)" title="<?= htmlspecialchars($t('TEXT_BUTTON_DELETE_FULL')) ?>">&#10007;</button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Restore modal -->
    <div class="br-modal-backdrop" id="brRestoreModal" data-br-modal>
        <div class="br-modal">
            <div class="br-modal-header br-bg-danger">
                <h5 class="br-modal-title"><?= htmlspecialchars($t('TEXT_HEADING_RESTORE_BACKUP')) ?></h5>
                <button type="button" class="br-modal-close" data-br-dismiss="brRestoreModal">&times;</button>
            </div>
            <div class="br-modal-body">
                <div class="br-alert br-alert-danger"><strong><?= htmlspecialchars($t('TEXT_WARNING_RESTORE_IRREVERSIBLE')) ?></strong></div>

                <div id="brRestoreStep1">
                    <label class="br-label"><?= htmlspecialchars($t('TEXT_LABEL_RESTORE_TYPE')) ?></label>
                    <select class="br-select br-mb-0" id="brRestoreType" style="margin-bottom:0.75rem;">
                        <option value="full"><?= htmlspecialchars($t('TEXT_OPTION_FULL_RESTORE')) ?></option>
                        <option value="database"><?= htmlspecialchars($t('TEXT_OPTION_DATABASE_ONLY')) ?></option>
                        <option value="files"><?= htmlspecialchars($t('TEXT_OPTION_FILES_ONLY')) ?></option>
                    </select>

                    <label class="br-label"><?= htmlspecialchars($t('TEXT_LABEL_TYPE_DATABASE_NAME')) ?></label>
                    <p class="br-text-muted br-small"><?= htmlspecialchars($t('TEXT_MESSAGE_TYPE_DB_NAME_TO_CONFIRM')) ?></p>
                    <input type="text" class="br-input" id="brDbNameConfirm" style="margin-bottom:0.75rem;" placeholder="<?= htmlspecialchars($t('TEXT_PLACEHOLDER_DATABASE_NAME')) ?>" autocomplete="off">

                    <button type="button" class="br-btn br-btn-outline-danger br-btn-block" id="brBtnRestoreStep1" onclick="BackupRestoreUI.restoreStep2()" disabled>
                        <?= htmlspecialchars($t('TEXT_BUTTON_CONTINUE')) ?>
                    </button>
                </div>

                <div id="brRestoreStep2" style="display:none;">
                    <label class="br-label"><?= htmlspecialchars($t('TEXT_LABEL_VERIFY_PASSWORD')) ?></label>
                    <p class="br-text-muted br-small"><?= htmlspecialchars($t('TEXT_MESSAGE_ENTER_PASSWORD_TO_CONFIRM')) ?></p>
                    <input type="password" class="br-input" id="brRestorePassword" style="margin-bottom:0.75rem;" placeholder="<?= htmlspecialchars($t('TEXT_PLACEHOLDER_PASSWORD')) ?>" autocomplete="off">
                    <button type="button" class="br-btn br-btn-outline-danger br-btn-block" onclick="BackupRestoreUI.verifyRestorePassword()">
                        <?= htmlspecialchars($t('TEXT_BUTTON_VERIFY_PASSWORD')) ?>
                    </button>
                </div>

                <div id="brRestoreStep3" style="display:none;">
                    <div style="text-align:center;">
                        <h5><?= htmlspecialchars($t('TEXT_HEADING_FINAL_CONFIRMATION')) ?></h5>
                        <p class="br-text-danger"><?= htmlspecialchars($t('TEXT_WARNING_RESTORE_FINAL')) ?></p>
                        <button type="button" class="br-btn br-btn-danger br-btn-block" id="brBtnExecuteRestore" onclick="BackupRestoreUI.executeRestore()" disabled>
                            <span id="brRestoreCountdown"><?= htmlspecialchars($t('TEXT_BUTTON_RESTORE')) ?> (5)</span>
                        </button>
                    </div>
                </div>

                <div id="brRestoreStep4" style="display:none;">
                    <h5><?= htmlspecialchars($t('TEXT_MESSAGE_RESTORING_BACKUP')) ?></h5>
                    <div id="brRestoreStepList"></div>
                    <p class="br-text-muted br-small br-mt-3"><?= htmlspecialchars($t('TEXT_MESSAGE_DO_NOT_CLOSE')) ?></p>
                </div>
            </div>
        </div>
    </div>

    <!-- Transfer modal -->
    <div class="br-modal-backdrop" id="brTransferModal" data-br-modal>
        <div class="br-modal">
            <div class="br-modal-header">
                <h5 class="br-modal-title"><?= htmlspecialchars($t('TEXT_HEADING_TRANSFER_TO_REMOTE')) ?></h5>
                <button type="button" class="br-modal-close" data-br-dismiss="brTransferModal">&times;</button>
            </div>
            <div class="br-modal-body">
                <label class="br-label"><?= htmlspecialchars($t('TEXT_LABEL_SELECT_REMOTE_SERVER')) ?></label>
                <select class="br-select" id="brTransferServerId">
                    <option value=""><?= htmlspecialchars($t('TEXT_PLACEHOLDER_SELECT_SERVER')) ?></option>
                </select>
                <div id="brTransferProgress" style="display:none; margin-top:0.75rem;">
                    <div class="br-progress br-progress-lg">
                        <div class="br-progress-bar br-striped" id="brTransferProgressBar" style="width:0%"></div>
                    </div>
                    <p class="br-text-muted br-small" id="brTransferProgressText" style="text-align:center;"></p>
                </div>
            </div>
            <div class="br-modal-footer">
                <button type="button" class="br-btn br-btn-secondary" data-br-dismiss="brTransferModal"><?= htmlspecialchars($t('TEXT_BUTTON_CANCEL')) ?></button>
                <button type="button" class="br-btn br-btn-primary" id="brBtnTransfer" onclick="BackupRestoreUI.executeTransfer()"><?= htmlspecialchars($t('TEXT_BUTTON_TRANSFER')) ?></button>
            </div>
        </div>
    </div>

    <!-- Upload restore modal -->
    <div class="br-modal-backdrop" id="brUploadRestoreModal" data-br-modal>
        <div class="br-modal">
            <div class="br-modal-header">
                <h5 class="br-modal-title"><?= htmlspecialchars($t('TEXT_HEADING_UPLOAD_RESTORE')) ?></h5>
                <button type="button" class="br-modal-close" data-br-dismiss="brUploadRestoreModal">&times;</button>
            </div>
            <div class="br-modal-body">
                <label class="br-label"><?= htmlspecialchars($t('TEXT_LABEL_SELECT_BACKUP_FILE')) ?></label>
                <input type="file" class="br-input" id="brUploadBackupFile" accept=".tgz">
                <div class="br-form-text"><?= htmlspecialchars($t('TEXT_HELP_ONLY_TGZ_FILES')) ?></div>
                <div id="brUploadProgress" style="display:none; margin-top:0.75rem;">
                    <div class="br-progress br-progress-lg">
                        <div class="br-progress-bar br-striped" id="brUploadProgressBar" style="width:0%"></div>
                    </div>
                </div>
            </div>
            <div class="br-modal-footer">
                <button type="button" class="br-btn br-btn-secondary" data-br-dismiss="brUploadRestoreModal"><?= htmlspecialchars($t('TEXT_BUTTON_CANCEL')) ?></button>
                <button type="button" class="br-btn br-btn-warning" id="brBtnUploadRestore" onclick="BackupRestoreUI.executeUploadRestore()"><?= htmlspecialchars($t('TEXT_BUTTON_UPLOAD_AND_RESTORE')) ?></button>
            </div>
        </div>
    </div>

    <!-- Restore token modal -->
    <div class="br-modal-backdrop" id="brRestoreTokenModal" data-br-modal>
        <div class="br-modal">
            <div class="br-modal-header br-bg-success">
                <h5 class="br-modal-title"><?= htmlspecialchars($t('TEXT_HEADING_BACKUP_CREATED')) ?></h5>
            </div>
            <div class="br-modal-body">
                <div class="br-alert br-alert-info"><?= htmlspecialchars($t('TEXT_MESSAGE_SAVE_RESTORE_TOKEN')) ?></div>
                <label class="br-label" style="font-weight:600;"><?= htmlspecialchars($t('TEXT_LABEL_RESTORE_TOKEN')) ?></label>
                <input type="text" class="br-input br-mono" id="brRestoreTokenValue" readonly>
                <button type="button" class="br-btn br-btn-sm br-btn-outline-secondary br-mt-3" onclick="BackupRestoreUI.copyRestoreToken()" title="<?= htmlspecialchars($t('TEXT_LABEL_RESTORE_TOKEN')) ?>">&#128203;</button>
                <div class="br-form-text"><?= htmlspecialchars($t('TEXT_HELP_RESTORE_TOKEN')) ?></div>
                <div class="br-switch br-mt-3">
                    <input type="checkbox" id="brTokenSavedCheck">
                    <label for="brTokenSavedCheck"><?= htmlspecialchars($t('TEXT_LABEL_TOKEN_SAVED_CONFIRM')) ?></label>
                </div>
            </div>
            <div class="br-modal-footer">
                <button type="button" class="br-btn br-btn-success" id="brBtnCloseTokenModal" disabled><?= htmlspecialchars($t('TEXT_BUTTON_CLOSE')) ?></button>
            </div>
        </div>
    </div>
</div>
