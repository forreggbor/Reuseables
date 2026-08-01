<?php
/**
 * Backup profiles management — body fragment for embedding in a host admin layout.
 *
 * @var callable $t          Translator: function(string $key, array $params = []): string
 * @var string   $baseUrl    Module mount path, no trailing slash
 * @var string   $csrfToken  CSRF token for the host's forms
 * @var array<int,object> $profiles       ProfileService::getAll()
 * @var array<int,object> $remoteServers  RemoteService::getAll()
 */

$typeLabels = [
    'full' => $t('TEXT_OPTION_FULL_BACKUP'),
    'database' => $t('TEXT_OPTION_DATABASE_ONLY'),
    'files' => $t('TEXT_OPTION_FILES_ONLY'),
];
$scheduleLabels = [
    'daily' => $t('TEXT_OPTION_DAILY'),
    'weekly' => $t('TEXT_OPTION_WEEKLY'),
    'monthly' => $t('TEXT_OPTION_MONTHLY'),
];
$dayLabels = [
    0 => $t('TEXT_DAY_SUNDAY'), 1 => $t('TEXT_DAY_MONDAY'), 2 => $t('TEXT_DAY_TUESDAY'),
    3 => $t('TEXT_DAY_WEDNESDAY'), 4 => $t('TEXT_DAY_THURSDAY'), 5 => $t('TEXT_DAY_FRIDAY'), 6 => $t('TEXT_DAY_SATURDAY'),
];

$i18nForJs = [];
foreach ([
    'TEXT_BUTTON_CANCEL', 'TEXT_BUTTON_CONTINUE', 'TEXT_BUTTON_DELETE', 'TEXT_BUTTON_RUN_NOW',
    'TEXT_HEADING_CREATE_PROFILE', 'TEXT_HEADING_EDIT_PROFILE', 'TEXT_LABEL_DAY_OF_WEEK', 'TEXT_LABEL_DAY_OF_MONTH',
    'TEXT_DAY_SUNDAY', 'TEXT_DAY_MONDAY', 'TEXT_DAY_TUESDAY', 'TEXT_DAY_WEDNESDAY', 'TEXT_DAY_THURSDAY', 'TEXT_DAY_FRIDAY', 'TEXT_DAY_SATURDAY',
    'TEXT_LABEL_LOADING', 'TEXT_ERROR_LOADING_DIRECTORY_TREE', 'TEXT_HELP_ALWAYS_EXCLUDED', 'TEXT_LABEL_LOCKED',
    'TEXT_ERROR_NAME_REQUIRED', 'TEXT_MESSAGE_PROFILE_SAVED', 'TEXT_ERROR_SAVE_FAILED', 'TEXT_CONFIRM_DELETE_PROFILE',
    'TEXT_MESSAGE_PROFILE_DELETED', 'TEXT_CONFIRM_RUN_PROFILE', 'TEXT_MESSAGE_CREATING_BACKUP',
    'TEXT_MESSAGE_BACKUP_CREATED_SUCCESSFULLY', 'TEXT_ERROR_BACKUP_FAILED',
] as $jsKey) {
    $i18nForJs[$jsKey] = $t($jsKey);
}
?>
<div class="br-root">
    <script>
        window.BackupRestoreConfig = {
            baseUrl: <?= json_encode($baseUrl) ?>,
            csrfToken: <?= json_encode($csrfToken) ?>,
            i18n: <?= json_encode($i18nForJs, JSON_UNESCAPED_UNICODE) ?>
        };
    </script>
    <script>document.addEventListener('DOMContentLoaded', function () {
            BackupRestoreUI.setProfilesData(<?= json_encode($profiles, JSON_UNESCAPED_UNICODE) ?>);
        });</script>

    <div class="br-page-header">
        <h2><?= htmlspecialchars($t('TEXT_HEADING_BACKUP_PROFILES')) ?></h2>
        <div class="br-actions">
            <a href="<?= htmlspecialchars($baseUrl) ?>" class="br-action-card br-secondary">
                <span class="br-action-icon">&#8592;</span>
                <span class="br-action-text"><?= htmlspecialchars($t('TEXT_BUTTON_BACK')) ?></span>
            </a>
            <a href="#" class="br-action-card br-success" onclick="BackupRestoreUI.openProfileModal(); return false;">
                <span class="br-action-icon">&#43;</span>
                <span class="br-action-text"><?= htmlspecialchars($t('TEXT_BUTTON_CREATE_PROFILE')) ?></span>
            </a>
        </div>
    </div>

    <div class="br-card">
        <div class="br-card-header"><span><?= htmlspecialchars($t('TEXT_HEADING_BACKUP_PROFILES')) ?> (<?= count($profiles) ?>)</span></div>
        <div class="br-table-wrap">
            <table class="br-table">
                <thead>
                <tr>
                    <th><?= htmlspecialchars($t('TEXT_HEADING_NAME')) ?></th>
                    <th><?= htmlspecialchars($t('TEXT_LABEL_BACKUP_TYPE')) ?></th>
                    <th><?= htmlspecialchars($t('TEXT_LABEL_SCHEDULE')) ?></th>
                    <th><?= htmlspecialchars($t('TEXT_LABEL_REMOTE_SERVER')) ?></th>
                    <th><?= htmlspecialchars($t('TEXT_LABEL_RETENTION')) ?></th>
                    <th class="br-text-center"><?= htmlspecialchars($t('TEXT_LABEL_STATUS')) ?></th>
                    <th><?= htmlspecialchars($t('TEXT_LABEL_LAST_RUN')) ?></th>
                    <th class="br-text-end"><?= htmlspecialchars($t('TEXT_HEADING_ACTIONS')) ?></th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($profiles)): ?>
                    <tr>
                        <td colspan="8" class="br-empty-row"><?= htmlspecialchars($t('TEXT_MESSAGE_NO_PROFILES_FOUND')) ?></td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($profiles as $profile): ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars($profile->name) ?></strong>
                                <?php if ($profile->description): ?>
                                    <br><span class="br-text-muted br-small"><?= htmlspecialchars(mb_strimwidth($profile->description, 0, 60, '...')) ?></span>
                                <?php endif; ?>
                            </td>
                            <td><span class="br-badge br-badge-primary"><?= htmlspecialchars($typeLabels[$profile->type] ?? $profile->type) ?></span></td>
                            <td>
                                <?php if ($profile->schedule_enabled): ?>
                                    <span class="br-badge br-badge-info"><?= htmlspecialchars($scheduleLabels[$profile->schedule_type] ?? '-') ?></span>
                                    <?php if ($profile->schedule_time): ?>
                                        <br><span class="br-text-muted br-small"><?= htmlspecialchars(substr($profile->schedule_time, 0, 5)) ?></span>
                                    <?php endif; ?>
                                    <?php if ($profile->next_run_at): ?>
                                        <br><span class="br-text-muted br-small"><?= htmlspecialchars($t('TEXT_LABEL_NEXT')) ?>: <?= htmlspecialchars($profile->next_run_at) ?></span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="br-text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td><?= $profile->remote_server_name ? htmlspecialchars($profile->remote_server_name) : '<span class="br-text-muted">-</span>' ?></td>
                            <td><?= (int) $profile->retention_days ?> <?= htmlspecialchars($t('TEXT_LABEL_DAYS')) ?></td>
                            <td class="br-text-center">
                                <?php if ($profile->is_active): ?>
                                    <span class="br-badge br-badge-success"><?= htmlspecialchars($t('TEXT_STATUS_ACTIVE')) ?></span>
                                <?php else: ?>
                                    <span class="br-badge br-badge-secondary"><?= htmlspecialchars($t('TEXT_STATUS_INACTIVE')) ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($profile->last_run_at): ?>
                                    <?= htmlspecialchars($profile->last_run_at) ?>
                                    <?php if ($profile->last_status === 'failure'): ?>
                                        <br><span class="br-badge br-badge-danger" title="<?= htmlspecialchars($profile->last_error ?? '') ?>"><?= htmlspecialchars($t('TEXT_STATUS_FAILED')) ?></span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="br-text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="br-text-end">
                                <div class="br-btn-group">
                                    <button class="br-btn br-btn-sm br-btn-outline-primary" onclick="BackupRestoreUI.editProfile(<?= (int) $profile->id ?>)" title="<?= htmlspecialchars($t('TEXT_BUTTON_EDIT')) ?>">&#9998;</button>
                                    <button class="br-btn br-btn-sm br-btn-outline-success" onclick="BackupRestoreUI.runProfile(<?= (int) $profile->id ?>)" title="<?= htmlspecialchars($t('TEXT_BUTTON_RUN_NOW')) ?>">&#9654;</button>
                                    <button class="br-btn br-btn-sm br-btn-outline-danger" onclick="BackupRestoreUI.deleteProfile(<?= (int) $profile->id ?>)" title="<?= htmlspecialchars($t('TEXT_BUTTON_DELETE')) ?>">&#10007;</button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Profile create/edit modal -->
    <div class="br-modal-backdrop" id="brProfileModal" data-br-modal>
        <div class="br-modal br-modal-lg">
            <div class="br-modal-header">
                <h5 class="br-modal-title" id="brProfileModalTitle"><?= htmlspecialchars($t('TEXT_HEADING_CREATE_PROFILE')) ?></h5>
                <button type="button" class="br-modal-close" data-br-dismiss="brProfileModal">&times;</button>
            </div>
            <div class="br-modal-body">
                <input type="hidden" id="brProfileId" value="0">
                <div class="br-form-grid">
                    <div class="br-col-6">
                        <label class="br-label"><?= htmlspecialchars($t('TEXT_HEADING_NAME')) ?> <span class="br-required">*</span></label>
                        <input type="text" class="br-input" id="brProfileName" maxlength="100">
                    </div>
                    <div class="br-col-6">
                        <label class="br-label"><?= htmlspecialchars($t('TEXT_LABEL_BACKUP_TYPE')) ?></label>
                        <select class="br-select" id="brProfileType">
                            <option value="full"><?= htmlspecialchars($t('TEXT_OPTION_FULL_BACKUP')) ?></option>
                            <option value="database"><?= htmlspecialchars($t('TEXT_OPTION_DATABASE_ONLY')) ?></option>
                            <option value="files"><?= htmlspecialchars($t('TEXT_OPTION_FILES_ONLY')) ?></option>
                        </select>
                    </div>
                    <div class="br-col-12">
                        <label class="br-label"><?= htmlspecialchars($t('TEXT_LABEL_DESCRIPTION')) ?></label>
                        <textarea class="br-textarea" id="brProfileDescription" rows="2"></textarea>
                    </div>

                    <div class="br-col-12"><hr class="br-hr"><h5><?= htmlspecialchars($t('TEXT_HEADING_SCHEDULE')) ?></h5></div>
                    <div class="br-col-4">
                        <div class="br-switch">
                            <input type="checkbox" id="brProfileScheduleEnabled" onchange="BackupRestoreUI.toggleSchedule()">
                            <label for="brProfileScheduleEnabled"><?= htmlspecialchars($t('TEXT_LABEL_ENABLE_SCHEDULE')) ?></label>
                        </div>
                    </div>
                    <div class="br-col-4 br-schedule-fields" style="display:none;">
                        <label class="br-label"><?= htmlspecialchars($t('TEXT_LABEL_SCHEDULE_TYPE')) ?></label>
                        <select class="br-select" id="brProfileScheduleType" onchange="BackupRestoreUI.toggleScheduleDay()">
                            <option value="daily"><?= htmlspecialchars($t('TEXT_OPTION_DAILY')) ?></option>
                            <option value="weekly"><?= htmlspecialchars($t('TEXT_OPTION_WEEKLY')) ?></option>
                            <option value="monthly"><?= htmlspecialchars($t('TEXT_OPTION_MONTHLY')) ?></option>
                        </select>
                    </div>
                    <div class="br-col-4 br-schedule-fields" style="display:none;">
                        <label class="br-label"><?= htmlspecialchars($t('TEXT_LABEL_TIME')) ?></label>
                        <input type="time" class="br-input" id="brProfileScheduleTime" value="02:00">
                    </div>
                    <div class="br-col-4 br-schedule-day-field" style="display:none;">
                        <label class="br-label" id="brScheduleDayLabel"><?= htmlspecialchars($t('TEXT_LABEL_DAY')) ?></label>
                        <select class="br-select" id="brProfileScheduleDay">
                            <?php foreach ($dayLabels as $val => $label): ?>
                                <option value="<?= (int) $val ?>"><?= htmlspecialchars($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="br-col-12"><hr class="br-hr"><h5><?= htmlspecialchars($t('TEXT_HEADING_OPTIONS')) ?></h5></div>
                    <div class="br-col-4">
                        <label class="br-label"><?= htmlspecialchars($t('TEXT_LABEL_REMOTE_SERVER')) ?></label>
                        <select class="br-select" id="brProfileRemoteServer">
                            <option value=""><?= htmlspecialchars($t('TEXT_OPTION_NONE')) ?></option>
                            <?php foreach ($remoteServers as $server): ?>
                                <option value="<?= (int) $server->id ?>"><?= htmlspecialchars($server->name) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="br-col-4">
                        <label class="br-label"><?= htmlspecialchars($t('TEXT_LABEL_RETENTION_DAYS')) ?></label>
                        <input type="number" class="br-input" id="brProfileRetentionDays" value="30" min="1" max="365">
                    </div>
                    <div class="br-col-4">
                        <label class="br-label"><?= htmlspecialchars($t('TEXT_LABEL_STATUS')) ?></label>
                        <select class="br-select" id="brProfileIsActive">
                            <option value="1"><?= htmlspecialchars($t('TEXT_STATUS_ACTIVE')) ?></option>
                            <option value="0"><?= htmlspecialchars($t('TEXT_STATUS_INACTIVE')) ?></option>
                        </select>
                    </div>

                    <div class="br-col-12">
                        <hr class="br-hr">
                        <h5><?= htmlspecialchars($t('TEXT_HEADING_FOLDER_SELECTION')) ?></h5>
                        <p class="br-text-muted br-small"><?= htmlspecialchars($t('TEXT_HELP_FOLDER_SELECTION')) ?></p>
                        <div id="brDirectoryTree" class="br-tree">
                            <div class="br-empty-row"><span class="br-spinner"></span> <?= htmlspecialchars($t('TEXT_LABEL_LOADING')) ?>...</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="br-modal-footer">
                <button type="button" class="br-btn br-btn-secondary" data-br-dismiss="brProfileModal"><?= htmlspecialchars($t('TEXT_BUTTON_CANCEL')) ?></button>
                <button type="button" class="br-btn br-btn-primary" onclick="BackupRestoreUI.saveProfile()"><?= htmlspecialchars($t('TEXT_BUTTON_SAVE')) ?></button>
            </div>
        </div>
    </div>
</div>
