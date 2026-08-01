<?php
/**
 * Remote (SFTP) servers management — body fragment for embedding in a host admin layout.
 *
 * @var callable $t          Translator: function(string $key, array $params = []): string
 * @var string   $baseUrl    Module mount path, no trailing slash
 * @var string   $csrfToken  CSRF token for the host's forms
 * @var array<int,object> $servers  RemoteService::getAll() (credentials excluded)
 */

$i18nForJs = [];
foreach ([
    'TEXT_BUTTON_CANCEL', 'TEXT_HEADING_ADD_SERVER', 'TEXT_HEADING_EDIT_SERVER',
    'TEXT_ERROR_REQUIRED_FIELDS', 'TEXT_MESSAGE_SERVER_SAVED', 'TEXT_ERROR_SAVE_FAILED',
    'TEXT_MESSAGE_TESTING_CONNECTION', 'TEXT_MESSAGE_CONNECTION_SUCCESS', 'TEXT_ERROR_CONNECTION_FAILED',
    'TEXT_CONFIRM_DELETE_SERVER', 'TEXT_MESSAGE_SERVER_DELETED', 'TEXT_BUTTON_DELETE',
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
            BackupRestoreUI.setServersData(<?= json_encode($servers, JSON_UNESCAPED_UNICODE) ?>);
        });</script>

    <div class="br-page-header">
        <h2><?= htmlspecialchars($t('TEXT_HEADING_REMOTE_SERVERS')) ?></h2>
        <div class="br-actions">
            <a href="<?= htmlspecialchars($baseUrl) ?>" class="br-action-card br-secondary">
                <span class="br-action-icon">&#8592;</span>
                <span class="br-action-text"><?= htmlspecialchars($t('TEXT_BUTTON_BACK')) ?></span>
            </a>
            <a href="#" class="br-action-card br-success" onclick="BackupRestoreUI.openServerModal(); return false;">
                <span class="br-action-icon">&#43;</span>
                <span class="br-action-text"><?= htmlspecialchars($t('TEXT_BUTTON_ADD_SERVER')) ?></span>
            </a>
        </div>
    </div>

    <div class="br-card">
        <div class="br-card-header"><span><?= htmlspecialchars($t('TEXT_HEADING_REMOTE_SERVERS')) ?> (<?= count($servers) ?>)</span></div>
        <div class="br-table-wrap">
            <table class="br-table">
                <thead>
                <tr>
                    <th><?= htmlspecialchars($t('TEXT_HEADING_NAME')) ?></th>
                    <th><?= htmlspecialchars($t('TEXT_LABEL_TYPE')) ?></th>
                    <th><?= htmlspecialchars($t('TEXT_LABEL_HOST')) ?></th>
                    <th><?= htmlspecialchars($t('TEXT_LABEL_USERNAME')) ?></th>
                    <th><?= htmlspecialchars($t('TEXT_LABEL_AUTH_TYPE')) ?></th>
                    <th><?= htmlspecialchars($t('TEXT_LABEL_REMOTE_PATH')) ?></th>
                    <th class="br-text-center"><?= htmlspecialchars($t('TEXT_LABEL_STATUS')) ?></th>
                    <th><?= htmlspecialchars($t('TEXT_LABEL_LAST_CONNECTED')) ?></th>
                    <th class="br-text-end"><?= htmlspecialchars($t('TEXT_HEADING_ACTIONS')) ?></th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($servers)): ?>
                    <tr>
                        <td colspan="9" class="br-empty-row"><?= htmlspecialchars($t('TEXT_MESSAGE_NO_REMOTE_SERVERS')) ?></td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($servers as $server): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($server->name) ?></strong></td>
                            <td><span class="br-badge br-badge-info"><?= htmlspecialchars(strtoupper($server->type)) ?></span></td>
                            <td>
                                <?= htmlspecialchars($server->host) ?>
                                <?php if ((int) $server->port !== 22): ?>
                                    <span class="br-text-muted">:<?= (int) $server->port ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($server->username) ?></td>
                            <td>
                                <?php if ($server->auth_type === 'key'): ?>
                                    <?= htmlspecialchars($t('TEXT_OPTION_SSH_KEY')) ?>
                                <?php else: ?>
                                    <?= htmlspecialchars($t('TEXT_OPTION_PASSWORD')) ?>
                                <?php endif; ?>
                            </td>
                            <td><code class="br-mono"><?= htmlspecialchars($server->remote_path) ?></code></td>
                            <td class="br-text-center">
                                <?php if ($server->is_active): ?>
                                    <span class="br-badge br-badge-success"><?= htmlspecialchars($t('TEXT_STATUS_ACTIVE')) ?></span>
                                <?php else: ?>
                                    <span class="br-badge br-badge-secondary"><?= htmlspecialchars($t('TEXT_STATUS_INACTIVE')) ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?= $server->last_connected ? htmlspecialchars($server->last_connected) : '<span class="br-text-muted">-</span>' ?></td>
                            <td class="br-text-end">
                                <div class="br-btn-group">
                                    <button class="br-btn br-btn-sm br-btn-outline-success" onclick="BackupRestoreUI.testServer(<?= (int) $server->id ?>)" title="<?= htmlspecialchars($t('TEXT_BUTTON_TEST_CONNECTION')) ?>">&#128268;</button>
                                    <button class="br-btn br-btn-sm br-btn-outline-primary" onclick="BackupRestoreUI.editServer(<?= (int) $server->id ?>)" title="<?= htmlspecialchars($t('TEXT_BUTTON_EDIT')) ?>">&#9998;</button>
                                    <button class="br-btn br-btn-sm br-btn-outline-danger" onclick="BackupRestoreUI.deleteServer(<?= (int) $server->id ?>)" title="<?= htmlspecialchars($t('TEXT_BUTTON_DELETE')) ?>">&#10007;</button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Server create/edit modal -->
    <div class="br-modal-backdrop" id="brServerModal" data-br-modal>
        <div class="br-modal">
            <div class="br-modal-header">
                <h5 class="br-modal-title" id="brServerModalTitle"><?= htmlspecialchars($t('TEXT_HEADING_ADD_SERVER')) ?></h5>
                <button type="button" class="br-modal-close" data-br-dismiss="brServerModal">&times;</button>
            </div>
            <div class="br-modal-body">
                <input type="hidden" id="brServerId" value="0">
                <div class="br-form-grid">
                    <div class="br-col-12">
                        <label class="br-label"><?= htmlspecialchars($t('TEXT_HEADING_NAME')) ?> <span class="br-required">*</span></label>
                        <input type="text" class="br-input" id="brServerName" maxlength="100" placeholder="<?= htmlspecialchars($t('TEXT_PLACEHOLDER_SERVER_NAME')) ?>">
                    </div>
                    <div class="br-col-6">
                        <label class="br-label"><?= htmlspecialchars($t('TEXT_LABEL_TYPE')) ?></label>
                        <select class="br-select" id="brServerType">
                            <option value="sftp">SFTP</option>
                            <option value="ssh">SSH</option>
                        </select>
                    </div>
                    <div class="br-col-6">
                        <label class="br-label"><?= htmlspecialchars($t('TEXT_LABEL_STATUS')) ?></label>
                        <select class="br-select" id="brServerIsActive">
                            <option value="1"><?= htmlspecialchars($t('TEXT_STATUS_ACTIVE')) ?></option>
                            <option value="0"><?= htmlspecialchars($t('TEXT_STATUS_INACTIVE')) ?></option>
                        </select>
                    </div>
                    <div class="br-col-8">
                        <label class="br-label"><?= htmlspecialchars($t('TEXT_LABEL_HOST')) ?> <span class="br-required">*</span></label>
                        <input type="text" class="br-input" id="brServerHost" placeholder="backup.example.com">
                    </div>
                    <div class="br-col-4">
                        <label class="br-label"><?= htmlspecialchars($t('TEXT_LABEL_PORT')) ?></label>
                        <input type="number" class="br-input" id="brServerPort" value="22" min="1" max="65535">
                    </div>
                    <div class="br-col-6">
                        <label class="br-label"><?= htmlspecialchars($t('TEXT_LABEL_USERNAME')) ?> <span class="br-required">*</span></label>
                        <input type="text" class="br-input" id="brServerUsername">
                    </div>
                    <div class="br-col-6">
                        <label class="br-label"><?= htmlspecialchars($t('TEXT_LABEL_AUTH_TYPE')) ?></label>
                        <select class="br-select" id="brServerAuthType" onchange="BackupRestoreUI.toggleAuthFields()">
                            <option value="password"><?= htmlspecialchars($t('TEXT_OPTION_PASSWORD')) ?></option>
                            <option value="key"><?= htmlspecialchars($t('TEXT_OPTION_SSH_KEY')) ?></option>
                        </select>
                    </div>
                    <div class="br-col-12" id="brPasswordField">
                        <label class="br-label"><?= htmlspecialchars($t('TEXT_PLACEHOLDER_PASSWORD')) ?></label>
                        <input type="password" class="br-input" id="brServerCredentials" autocomplete="new-password">
                        <div class="br-form-text" id="brCredentialsHelp"><?= htmlspecialchars($t('TEXT_HELP_LEAVE_EMPTY_TO_KEEP')) ?></div>
                    </div>
                    <div class="br-col-12" id="brKeyField" style="display:none;">
                        <label class="br-label"><?= htmlspecialchars($t('TEXT_LABEL_SSH_PRIVATE_KEY')) ?></label>
                        <textarea class="br-textarea" id="brServerKeyCredentials" rows="5" placeholder="-----BEGIN RSA PRIVATE KEY-----"></textarea>
                        <div class="br-form-text"><?= htmlspecialchars($t('TEXT_HELP_LEAVE_EMPTY_TO_KEEP')) ?></div>
                    </div>
                    <div class="br-col-12">
                        <label class="br-label"><?= htmlspecialchars($t('TEXT_LABEL_REMOTE_PATH')) ?></label>
                        <input type="text" class="br-input" id="brServerRemotePath" value="/backups" placeholder="/backups">
                    </div>
                </div>
            </div>
            <div class="br-modal-footer">
                <button type="button" class="br-btn br-btn-secondary" data-br-dismiss="brServerModal"><?= htmlspecialchars($t('TEXT_BUTTON_CANCEL')) ?></button>
                <button type="button" class="br-btn br-btn-primary" onclick="BackupRestoreUI.saveServer()"><?= htmlspecialchars($t('TEXT_BUTTON_SAVE')) ?></button>
            </div>
        </div>
    </div>
</div>
