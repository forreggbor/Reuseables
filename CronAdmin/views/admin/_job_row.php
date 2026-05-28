<?php
/**
 * Partial: one table row per cron job.
 *
 * @var array<string, mixed> $job
 * @var string               $csrf_token
 * @var array<int, string>   $userMap
 * @var bool                 $manifestBroken
 * @var string               $baseUrl
 */
$jId    = (int) $job['id'];
$jKey   = htmlspecialchars((string) $job['job_key'], ENT_QUOTES, 'UTF-8');
$freq   = (string) $job['frequency'];

$statusClass = match ((string) ($job['last_status'] ?? '')) {
    'success' => 'cra-badge--success',
    'failure' => 'cra-badge--danger',
    'skipped' => 'cra-badge--muted',
    default   => 'cra-badge--light',
};
$statusLabel = $job['last_status']
    ? __('TEXT_CRON_STATUS_' . strtoupper((string) $job['last_status']))
    : '—';

$scheduleSummary = \CronAdmin\ScheduleFormatter::summarize($job);

$updatedByLabel = '';
if (!empty($job['updated_by'])) {
    $uid = (int) $job['updated_by'];
    $updatedByLabel = $userMap[$uid] ?? "#{$uid}";
}
?>
<tr class="cra-job-row"
    data-job-id="<?= $jId ?>"
    data-job-key="<?= $jKey ?>"
    data-frequency="<?= htmlspecialchars($freq, ENT_QUOTES, 'UTF-8') ?>"
    data-every-n="<?= (int) ($job['every_n_minutes'] ?? 0) ?>"
    data-hour="<?= $job['hour'] !== null ? (int) $job['hour'] : '' ?>"
    data-minute="<?= $job['minute'] !== null ? (int) $job['minute'] : '' ?>"
    data-days-of-week="<?= htmlspecialchars((string) ($job['days_of_week'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
    data-days-of-month="<?= htmlspecialchars((string) ($job['days_of_month'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
    data-log-to-db="<?= (int) $job['log_to_db'] ?>"
    data-email-report="<?= htmlspecialchars((string) ($job['email_report'] ?? 'off'), ENT_QUOTES, 'UTF-8') ?>"
    data-trigger-pending="<?= (int) $job['trigger_pending'] ?>"
    data-trigger-pending-at="<?= htmlspecialchars((string) ($job['trigger_pending_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
    <td class="cra-job-toggle-cell">
        <label class="cra-toggle <?= $manifestBroken ? 'cra-toggle--disabled' : '' ?>">
            <input type="checkbox" class="cra-toggle-input cra-job-toggle"
                   data-job-id="<?= $jId ?>"
                   <?= (int) $job['enabled'] ? 'checked' : '' ?>
                   <?= $manifestBroken ? 'disabled' : '' ?>>
            <span class="cra-toggle-slider"></span>
        </label>
    </td>
    <td class="cra-job-name-cell">
        <span class="cra-job-name"><?= htmlspecialchars(__((string) $job['name_key']), ENT_QUOTES, 'UTF-8') ?></span>
        <?php if ($job['description_key'] ?? ''): ?>
        <span class="cra-job-desc"><?= htmlspecialchars(__((string) $job['description_key']), ENT_QUOTES, 'UTF-8') ?></span>
        <?php endif; ?>
    </td>
    <td class="cra-job-schedule-cell">
        <span class="cra-schedule-summary"><?= htmlspecialchars($scheduleSummary, ENT_QUOTES, 'UTF-8') ?></span>
    </td>
    <td class="cra-job-run-cell">
        <?php if ($job['last_run_at']): ?>
        <span class="cra-last-run" data-job-id="<?= $jId ?>"><?= htmlspecialchars((string) $job['last_run_at'], ENT_QUOTES, 'UTF-8') ?></span>
        <?php else: ?>
        <span class="cra-last-run cra-muted" data-job-id="<?= $jId ?>">—</span>
        <?php endif; ?>
    </td>
    <td class="cra-job-status-cell">
        <span class="cra-badge <?= $statusClass ?> cra-status-badge" data-job-id="<?= $jId ?>">
            <?= htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') ?>
        </span>
    </td>
    <td class="cra-job-queued-cell">
        <?php if ((int) $job['trigger_pending'] === 1):
            $queuerName = !empty($job['trigger_pending_by'])
                ? ($userMap[(int) $job['trigger_pending_by']] ?? "#{$job['trigger_pending_by']}")
                : null;
            $tooltip = $queuerName
                ? sprintf(__('TEXT_CRON_QUEUED_TOOLTIP'), $queuerName, (string) ($job['trigger_pending_at'] ?? ''))
                : sprintf(__('TEXT_CRON_QUEUED_TOOLTIP_NO_USER'), (string) ($job['trigger_pending_at'] ?? ''));
        ?>
        <span class="cra-queued-icon" title="<?= htmlspecialchars($tooltip, ENT_QUOTES, 'UTF-8') ?>">&#x231b;</span>
        <?php else: ?>&mdash;<?php endif; ?>
    </td>
    <td class="cra-job-log-to-db-cell">
        <?php if ((int) $job['log_to_db'] === 1): ?>
        <span class="cra-icon-tip" title="<?= htmlspecialchars(__('TEXT_CRON_LOG_TO_DB'), ENT_QUOTES, 'UTF-8') ?>">&#x1F4BE;</span>
        <?php else: ?>&mdash;<?php endif; ?>
    </td>
    <td class="cra-job-email-report-cell">
        <?php $emailReport = (string) ($job['email_report'] ?? 'off'); ?>
        <?php if ($emailReport === 'on_failure'): ?>
        <span class="cra-icon-tip" title="<?= htmlspecialchars(__('TEXT_CRON_EMAIL_REPORT_ON_FAILURE'), ENT_QUOTES, 'UTF-8') ?>">&#x26A0;</span>
        <?php elseif ($emailReport === 'every_run'): ?>
        <span class="cra-icon-tip" title="<?= htmlspecialchars(__('TEXT_CRON_EMAIL_REPORT_EVERY_RUN'), ENT_QUOTES, 'UTF-8') ?>">&#x2709;</span>
        <?php else: ?>&mdash;<?php endif; ?>
    </td>
    <td class="cra-job-actions-cell">
        <?php if ($job['last_output_excerpt']): ?>
        <button type="button" class="cra-btn cra-btn--sm cra-btn--outline cra-show-output"
                data-output="<?= htmlspecialchars((string) $job['last_output_excerpt'], ENT_QUOTES, 'UTF-8') ?>"
                data-label="<?= htmlspecialchars(__((string) $job['name_key']), ENT_QUOTES, 'UTF-8') ?>"
                <?= $manifestBroken ? 'disabled' : '' ?>>
            <?= htmlspecialchars(__('TEXT_CRON_VIEW_LAST_OUTPUT'), ENT_QUOTES, 'UTF-8') ?>
        </button>
        <?php endif; ?>
        <button type="button" class="cra-btn cra-btn--sm cra-btn--outline cra-edit-job"
                data-job-id="<?= $jId ?>"
                <?= $manifestBroken ? 'disabled' : '' ?>>
            <?= htmlspecialchars(__('TEXT_BUTTON_EDIT'), ENT_QUOTES, 'UTF-8') ?>
        </button>
        <?php $isPending = (int) $job['trigger_pending'] === 1; ?>
        <button type="button" class="cra-btn cra-btn--sm cra-btn--primary cra-run-now"
                data-job-id="<?= $jId ?>"
                data-csrf="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>"
                <?= ($manifestBroken || $isPending) ? 'disabled' : '' ?>
                <?= $isPending ? 'title="' . htmlspecialchars(__('TEXT_CRON_RUN_NOW_ALREADY_PENDING'), ENT_QUOTES, 'UTF-8') . '"' : '' ?>>
            ▶ <?= htmlspecialchars(__('TEXT_CRON_RUN_NOW'), ENT_QUOTES, 'UTF-8') ?>
        </button>
        <span class="cra-run-status" data-job-id="<?= $jId ?>" style="display:none;"></span>
    </td>
</tr>
