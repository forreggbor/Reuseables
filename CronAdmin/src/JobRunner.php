<?php
/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * Executes a single cron task: acquires the lock, captures output, persists
 * the result, writes audit logs, and sends email reports.
 */

declare(strict_types=1);

namespace CronAdmin;

use ActivityLogs\ActivityLogger;
use CronAdmin\Contracts\DatabaseAdapterInterface;
use CronAdmin\Contracts\LoggerInterface;
use CronAdmin\Contracts\MailAdapterInterface;
use CronAdmin\Contracts\MailRecipientResolverInterface;
use CronAdmin\Tasks\CronTaskInterface;
use CronAdmin\Tasks\CronTaskResult;

/**
 * Handles all per-job execution concerns for the CronAdmin dispatcher.
 *
 * Called by Dispatcher for both scheduled and manual (Run-Now) runs.
 * Output captured from the task (stdout via ob_start/ob_get_clean) is
 * truncated to 8 KB (UTF-8 safe via mb_strcut) before storage.
 * Email bodies truncate the output excerpt to 2 KB.
 */
class JobRunner
{
    /** @var bool Track whether the mail-soft-skip warning was already emitted this tick. */
    private bool $mailWarnEmitted = false;

    /**
     * @param DatabaseAdapterInterface            $db
     * @param LockManager                         $lockManager
     * @param LoggerInterface                     $logger
     * @param MailAdapterInterface|null           $mailAdapter       Optional.
     * @param MailRecipientResolverInterface|null $recipientResolver Optional.
     */
    public function __construct(
        private readonly DatabaseAdapterInterface            $db,
        private readonly LockManager                        $lockManager,
        private readonly LoggerInterface                    $logger,
        private readonly ?MailAdapterInterface              $mailAdapter,
        private readonly ?MailRecipientResolverInterface    $recipientResolver,
    ) {}

    /**
     * Resets the per-tick mail-warning flag.
     *
     * Call once at the top of each dispatch() tick so the soft-skip warning
     * is emitted at most once per minute, not once per job.
     *
     * @return void
     */
    public function resetTickState(): void
    {
        $this->mailWarnEmitted = false;
    }

    /**
     * Acquires the lock, runs the task, persists the result, and sends email.
     *
     * @param array<string, mixed> $job            A cron_jobs row.
     * @param bool                 $manual         True for Run-Now triggers.
     * @param bool                 $logToActivity  False for runByKey() shim runs (no DB/ActivityLog).
     * @return CronTaskResult
     */
    public function run(array $job, bool $manual = false, bool $logToActivity = true): CronTaskResult
    {
        $jobKey = (string) $job['job_key'];
        $jobId  = (int) $job['id'];

        // Resolve and instantiate the task class.
        $class = (string) ($job['class_name'] ?? '');
        if ($class === '' || !class_exists($class, true)) {
            $result = CronTaskResult::failure(
                'class_not_found',
                "Task class '{$class}' not found for job '{$jobKey}'."
            );
            if ($logToActivity) {
                $this->persistResult($jobId, $result, null);
            }
            return $result;
        }

        if (!is_a($class, CronTaskInterface::class, true)) {
            $result = CronTaskResult::failure(
                'class_invalid',
                "Task class '{$class}' does not implement CronTaskInterface."
            );
            if ($logToActivity) {
                $this->persistResult($jobId, $result, null);
            }
            return $result;
        }

        /** @var CronTaskInterface $task */
        $task       = new $class();
        $lockTimeout = (int) ($job['lock_timeout_seconds'] ?? 3600);

        $fp = $this->lockManager->acquire($jobKey, $lockTimeout);
        if ($fp === null) {
            $result = CronTaskResult::skipped('locked');
            if ($logToActivity) {
                $this->persistResult($jobId, $result, null);
            }
            return $result;
        }

        $outputExcerpt = '';
        $result        = CronTaskResult::failure('unknown');

        try {
            ob_start();
            $startMs = (int) round(microtime(true) * 1000);

            try {
                $result = $task->run();
            } catch (\Throwable $e) {
                // 'exception' sentinel keeps last_status readable; full message goes to last_error.
                // Truncate to avoid hitting last_error VARCHAR(1024) on very long exception messages.
                $result = CronTaskResult::failure('exception', mb_strcut($e->getMessage(), 0, 1024, 'UTF-8'));
            }

            $endMs         = (int) round(microtime(true) * 1000);
            $rawOutput     = (string) ob_get_clean();
            $durationMs    = max(0, $endMs - $startMs);
            $outputExcerpt = mb_strcut($rawOutput, 0, 8192, 'UTF-8');

            // Rebuild result with captured duration (task's result may carry 0).
            $result = new CronTaskResult(
                $result->status,
                $result->message,
                $durationMs,
                $result->errorMessage,
            );

        } finally {
            $this->lockManager->release($fp);
        }

        if ($logToActivity) {
            $this->persistResult($jobId, $result, $outputExcerpt);
            $this->writeActivityLog($job, $result, $manual);
        } else {
            // runByKey shim: log to LoggerInterface only, no DB writes.
            $level = $result->isFailure() ? LoggerInterface::ERROR : LoggerInterface::INFO;
            $this->logger->log(
                "runByKey '{$jobKey}': {$result->status} — {$result->message}",
                $level
            );
        }

        $this->sendEmailReport($job, $result, $outputExcerpt);

        return $result;
    }

    /**
     * Persists the task result into the cron_jobs row, clearing trigger_pending.
     *
     * @param int            $jobId
     * @param CronTaskResult $result
     * @param string|null    $outputExcerpt
     * @return void
     */
    private function persistResult(int $jobId, CronTaskResult $result, ?string $outputExcerpt): void
    {
        try {
            $errorMessage = $result->errorMessage !== null
                ? mb_strcut($result->errorMessage, 0, 1024, 'UTF-8')
                : null;

            $this->db->execute(
                'UPDATE cron_jobs
                 SET last_run_at         = UTC_TIMESTAMP(),
                     last_status         = ?,
                     last_duration_ms    = ?,
                     last_output_excerpt = ?,
                     last_error          = ?,
                     trigger_pending     = 0
                 WHERE id = ?',
                [
                    $result->status,
                    $result->durationMs,
                    $outputExcerpt !== '' ? $outputExcerpt : null,
                    $errorMessage,
                    $jobId,
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->error("CronAdmin: failed to persist result for job #{$jobId}: " . $e->getMessage());
        }
    }

    /**
     * Writes an ActivityLogger entry for automatic or manual runs.
     *
     * Automatic runs: only when log_to_db=1.
     * Manual runs: always (the trigger event was already logged before execution;
     * this entry records the outcome).
     *
     * @param array<string, mixed> $job
     * @param CronTaskResult       $result
     * @param bool                 $manual
     * @return void
     */
    private function writeActivityLog(array $job, CronTaskResult $result, bool $manual): void
    {
        $jobId  = (int) $job['id'];
        $jobKey = (string) $job['job_key'];

        if ($manual) {
            $triggeredBy = $job['trigger_pending_by'] !== null ? (int) $job['trigger_pending_by'] : null;
            try {
                ActivityLogger::log(
                    $triggeredBy,
                    'run_cron_job_manual',
                    'cron_job',
                    $jobId,
                    null,
                    ['status' => $result->status, 'duration_ms' => $result->durationMs],
                    'admin',
                    null,
                    null,
                    $jobKey
                );
            } catch (\Throwable $e) {
                $this->logger->warning('CronAdmin: failed to write manual-run audit log: ' . $e->getMessage());
            }
            return;
        }

        if ((int) ($job['log_to_db'] ?? 0) !== 1) {
            return;
        }

        try {
            ActivityLogger::log(
                null,
                'run_cron_job',
                'cron_job',
                $jobId,
                null,
                ['status' => $result->status, 'duration_ms' => $result->durationMs],
                'system',
                null,
                null,
                $jobKey
            );
        } catch (\Throwable $e) {
            $this->logger->warning('CronAdmin: failed to write auto-run audit log: ' . $e->getMessage());
        }
    }

    /**
     * Sends an email report according to the job's tri-state email_report setting.
     *
     * Soft-skip (with one WARN per tick) when no mail adapter is configured.
     * Delivery failures are caught and logged — never bubble up.
     *
     * @param array<string, mixed> $job
     * @param CronTaskResult       $result
     * @param string               $outputExcerpt
     * @return void
     */
    private function sendEmailReport(array $job, CronTaskResult $result, string $outputExcerpt): void
    {
        $mode = (string) ($job['email_report'] ?? 'off');

        if ($mode === 'off') {
            return;
        }
        if ($mode === 'on_failure' && !$result->isFailure()) {
            return;
        }

        if ($this->mailAdapter === null || $this->recipientResolver === null) {
            if (!$this->mailWarnEmitted) {
                $this->logger->warning(
                    'CronAdmin: email_report is enabled for one or more jobs but no mail_adapter or recipient_resolver is configured — skipping mail.'
                );
                $this->mailWarnEmitted = true;
            }
            return;
        }

        $recipients = $this->recipientResolver->getRecipients((string) $job['job_key']);
        if (empty($recipients)) {
            return;
        }

        try {
            $jobName  = __((string) $job['name_key']);
            $schedule = ScheduleFormatter::summarize($job);
            $status   = __('TEXT_CRON_STATUS_' . strtoupper($result->status));
            $subject  = __('TEXT_CRON_EMAIL_REPORT_SUBJECT', ['label' => $jobName, 'status' => $status]);

            $bodyLines = [
                '<strong>' . htmlspecialchars($jobName, ENT_QUOTES, 'UTF-8') . '</strong>',
                __('TEXT_CRON_SCHEDULE_LABEL')   . ': ' . htmlspecialchars($schedule, ENT_QUOTES, 'UTF-8'),
                __('TEXT_CRON_LAST_STATUS')      . ': ' . htmlspecialchars($status, ENT_QUOTES, 'UTF-8'),
                __('TEXT_CRON_LAST_DURATION')    . ': ' . $result->durationMs . ' ms',
            ];

            if ($result->errorMessage !== null) {
                $bodyLines[] = '<strong>' . __('TEXT_LABEL_ERROR') . ':</strong> '
                    . htmlspecialchars($result->errorMessage, ENT_QUOTES, 'UTF-8');
            }

            if ($outputExcerpt !== '') {
                $excerpt     = mb_strcut($outputExcerpt, 0, 2048, 'UTF-8');
                $bodyLines[] = '<pre>' . htmlspecialchars($excerpt, ENT_QUOTES, 'UTF-8') . '</pre>';
            }

            $body = implode('<br>', $bodyLines);

            foreach ($recipients as $to) {
                try {
                    $this->mailAdapter->send($to, $subject, $body, true);
                } catch (\Throwable $e) {
                    $this->logger->error("CronAdmin: email delivery failed to [{$to}]: " . $e->getMessage());
                }
            }

        } catch (\Throwable $e) {
            $this->logger->error('CronAdmin: failed to build/send email report: ' . $e->getMessage());
        }
    }
}
