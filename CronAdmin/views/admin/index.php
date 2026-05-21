<?php
/**
 * CronAdmin admin page — cron job management.
 *
 * @var array<int, array<string, mixed>> $jobs
 * @var bool                             $dispatcherEnabled
 * @var string                           $csrf_token
 * @var array<int, string>               $userMap
 * @var list<string>|null                $manifestError
 * @var bool                             $manifestBroken
 * @var string                           $baseUrl
 * @var string                           $assetBaseUrl
 * @var bool                             $useBootstrap
 */
?>
<div class="cra-root"
     data-cra-base-url="<?= htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8') ?>"
     data-cra-bootstrap="<?= $useBootstrap ? '1' : '0' ?>"
     data-cra-i18n-run-confirm="<?= htmlspecialchars(__('TEXT_CRON_RUN_NOW_CONFIRM'), ENT_QUOTES, 'UTF-8') ?>"
     data-cra-i18n-queued="<?= htmlspecialchars(__('TEXT_CRON_RUN_NOW_QUEUED'), ENT_QUOTES, 'UTF-8') ?>"
     data-cra-i18n-running="<?= htmlspecialchars(__('TEXT_CRON_RUN_NOW_STILL_RUNNING'), ENT_QUOTES, 'UTF-8') ?>"
     data-cra-i18n-save-success="<?= htmlspecialchars(__('TEXT_CRON_SAVE_SUCCESS'), ENT_QUOTES, 'UTF-8') ?>"
     data-cra-i18n-error-generic="<?= htmlspecialchars(__('TEXT_ERROR_GENERIC'), ENT_QUOTES, 'UTF-8') ?>"
     data-cra-manifest-broken="<?= $manifestBroken ? '1' : '0' ?>">

    <?php if ($manifestBroken): ?>
    <div class="cra-alert cra-alert--danger" role="alert">
        <strong><?= htmlspecialchars(__('TEXT_CRON_MANIFEST_BROKEN'), ENT_QUOTES, 'UTF-8') ?></strong>
        <ul class="cra-violation-list">
            <?php foreach ($manifestError as $violation): ?>
            <li><?= htmlspecialchars($violation, ENT_QUOTES, 'UTF-8') ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <?php if (!$dispatcherEnabled): ?>
    <div class="cra-alert cra-alert--warning" role="alert">
        <?= htmlspecialchars(__('TEXT_CRON_DISPATCHER_DISABLED_BANNER'), ENT_QUOTES, 'UTF-8') ?>
    </div>
    <?php endif; ?>

    <?php if (empty($jobs) && !$manifestBroken): ?>
    <div class="cra-alert cra-alert--info" role="alert">
        <?= htmlspecialchars(__('TEXT_CRON_NO_JOBS_DECLARED'), ENT_QUOTES, 'UTF-8') ?>
    </div>
    <?php endif; ?>

    <!-- Dispatcher kill switch -->
    <div class="cra-card cra-mb-4">
        <div class="cra-card-body">
            <label class="cra-toggle-label">
                <input type="checkbox" class="cra-toggle-input" id="craDispatcherEnabled"
                       <?= $dispatcherEnabled ? 'checked' : '' ?>
                       data-csrf="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
                <span class="cra-toggle-slider"></span>
                <strong><?= htmlspecialchars(__('TEXT_CRON_DISPATCHER_ENABLED'), ENT_QUOTES, 'UTF-8') ?></strong>
            </label>
            <p class="cra-form-hint"><?= htmlspecialchars(__('TEXT_CRON_DISPATCHER_ENABLED_HELP'), ENT_QUOTES, 'UTF-8') ?></p>
        </div>
    </div>

    <!-- Job table -->
    <?php if (!empty($jobs)): ?>
    <div class="cra-card">
        <div class="cra-card-body cra-p-0">
            <div class="cra-table-responsive">
                <table class="cra-table" id="craJobsTable">
                    <thead>
                        <tr>
                            <th><?= htmlspecialchars(__('TEXT_CRON_ENABLED'), ENT_QUOTES, 'UTF-8') ?></th>
                            <th><?= htmlspecialchars(__('TEXT_CRON_JOB_NAME'), ENT_QUOTES, 'UTF-8') ?></th>
                            <th><?= htmlspecialchars(__('TEXT_CRON_FREQUENCY'), ENT_QUOTES, 'UTF-8') ?></th>
                            <th><?= htmlspecialchars(__('TEXT_CRON_LAST_RUN_AT'), ENT_QUOTES, 'UTF-8') ?></th>
                            <th><?= htmlspecialchars(__('TEXT_CRON_LAST_STATUS'), ENT_QUOTES, 'UTF-8') ?></th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="craJobsTableBody">
                        <?php foreach ($jobs as $job): ?>
                            <?php include __DIR__ . '/_job_row.php'; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

</div>

<?php include __DIR__ . '/_edit_modal.php'; ?>
<?php include __DIR__ . '/_output_modal.php'; ?>
