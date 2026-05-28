<?php
/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * HTTP action handlers for the CronAdmin admin UI.
 */

declare(strict_types=1);

namespace CronAdmin;

use ActivityLogs\ActivityLogger;
use CronAdmin\Contracts\AuthAdapterInterface;
use CronAdmin\Contracts\CsrfAdapterInterface;
use CronAdmin\Contracts\DatabaseAdapterInterface;
use CronAdmin\Contracts\DispatcherKillSwitchAdapterInterface;
use CronAdmin\Contracts\LoggerInterface;
use CronAdmin\Exceptions\InvalidManifestException;

/**
 * Handles all HTTP interactions for the cron job management admin page.
 *
 * The host's router MUST apply its existing admin authentication middleware
 * to all routes before calling these methods — AdminActions assumes the request
 * has already cleared the gate and performs an isAuthorized() defence-in-depth
 * check for every mutation.
 *
 * All responses are written directly via echo + http_response_code().
 * The host MUST NOT wrap the return values — these methods are void.
 */
class AdminActions
{
    /** @var list<string> */
    private const VALID_FREQUENCIES = ['every_n_minutes', 'hourly', 'daily', 'weekly', 'monthly'];

    /** @var list<string> */
    private const VALID_EMAIL_REPORT = ['off', 'on_failure', 'every_run'];

    /** @var list<int> */
    private const VALID_EVERY_N = [1, 5, 10, 15, 20, 30, 60, 120, 180, 240, 360, 720, 1440];

    /**
     * @param DatabaseAdapterInterface             $db
     * @param AuthAdapterInterface                 $auth
     * @param CsrfAdapterInterface                 $csrf
     * @param DispatcherKillSwitchAdapterInterface  $killSwitch
     * @param ManifestReader                       $manifestReader
     * @param ManifestSyncService                  $syncService
     * @param LoggerInterface                      $logger
     * @param string                               $manifestPath
     * @param string                               $baseUrl
     * @param string                               $assetBaseUrl
     * @param bool                                 $useBootstrap
     * @param TimeZoneHelper                       $tz
     */
    public function __construct(
        private readonly DatabaseAdapterInterface            $db,
        private readonly AuthAdapterInterface                $auth,
        private readonly CsrfAdapterInterface                $csrf,
        private readonly DispatcherKillSwitchAdapterInterface $killSwitch,
        private readonly ManifestReader                      $manifestReader,
        private readonly ManifestSyncService                 $syncService,
        private readonly LoggerInterface                     $logger,
        private readonly string                              $manifestPath,
        private readonly string                              $baseUrl,
        private readonly string                              $assetBaseUrl,
        private readonly bool                                $useBootstrap,
        private readonly TimeZoneHelper                      $tz,
    ) {}

    // =========================================================================
    // Page render
    // =========================================================================

    /**
     * Renders the cron job management admin page.
     *
     * Runs manifest sync on every page load so the table reflects the current
     * manifest state. On manifest errors, renders the page with a red banner
     * listing all violations; existing active rows are shown read-only.
     *
     * @return void
     */
    public function index(): void
    {
        $this->requireMethod('GET');
        $this->requireAuth('view');

        $manifestError = null;
        $classMap      = [];

        try {
            $manifest = $this->manifestReader->load($this->manifestPath);
            $classMap = array_column($manifest, 'class', 'key');
            $this->syncService->sync($manifest, 'admin', $this->auth->getCurrentUserId());
        } catch (InvalidManifestException $e) {
            $manifestError = $e->getViolations();
        } catch (\Throwable $e) {
            $this->logger->error('CronAdmin: sync failed on index: ' . $e->getMessage());
            $manifestError = [$e->getMessage()];
        }

        $jobs = $this->db->fetchAll('SELECT * FROM cron_jobs WHERE active = 1 ORDER BY id');

        // Add display-TZ companions; raw UTC values are preserved for JS data attributes.
        foreach ($jobs as &$job) {
            $job['last_run_at_display']        = $this->tz->utcToDisplay($job['last_run_at']        ?? null);
            $job['trigger_pending_at_display'] = $this->tz->utcToDisplay($job['trigger_pending_at'] ?? null);
        }
        unset($job);

        $dispatcherEnabled = $this->killSwitch->get();
        $csrfToken         = $this->csrf->generate();
        $userIds           = $this->collectUserIds($jobs);
        $userMap           = $this->auth->getUserMap($userIds);

        $viewData = [
            'jobs'              => $jobs,
            'dispatcherEnabled' => $dispatcherEnabled,
            'csrf_token'        => $csrfToken,
            'userMap'           => $userMap,
            'manifestError'     => $manifestError,
            'manifestBroken'    => $manifestError !== null,
            'baseUrl'           => $this->baseUrl,
            'assetBaseUrl'      => $this->assetBaseUrl,
            'useBootstrap'      => $this->useBootstrap,
        ];

        $this->renderView('admin/index.php', $viewData);
    }

    // =========================================================================
    // AJAX — single-job save
    // =========================================================================

    /**
     * Saves schedule settings for a single job from the per-job edit modal.
     *
     * @param int $id  cron_jobs.id
     * @return void
     */
    public function saveOne(int $id): void
    {
        $this->requireMethod('POST');
        $this->requireAuth('save');
        $this->requireCsrf();
        $this->requireManifestHealthy();

        $old = $this->db->fetchOne('SELECT * FROM cron_jobs WHERE id = ? AND active = 1', [$id]);
        if ($old === null) {
            $this->jsonError(__('TEXT_ERROR_NOT_FOUND'), 404);
            return;
        }

        // Frequency
        $freq = $_POST['frequency'] ?? '';
        if (!in_array($freq, self::VALID_FREQUENCIES, true)) {
            $freq = (string) $old['frequency'];
        }

        // Email report
        $emailReport = $_POST['email_report'] ?? 'off';
        if (!in_array($emailReport, self::VALID_EMAIL_REPORT, true)) {
            $emailReport = 'off';
        }

        $logToDB = isset($_POST['log_to_db']) ? 1 : 0;

        // Frequency-specific fields
        $everyN    = null;
        $dow       = '';
        $dom       = '';
        $hour      = null;
        $minute    = null;

        switch ($freq) {
            case 'every_n_minutes':
                $n = (int) ($_POST['every_n_minutes'] ?? 0);
                if (!in_array($n, self::VALID_EVERY_N, true)) {
                    $this->jsonError(__('TEXT_CRON_VALIDATION_INVALID_EVERY_N_MINUTES'), 422);
                    return;
                }
                $everyN = $n;
                break;

            case 'hourly':
                $m = isset($_POST['minute']) ? (int) $_POST['minute'] : -1;
                if ($m < 0 || $m > 59) {
                    $this->jsonError(__('TEXT_CRON_VALIDATION_HOUR_MINUTE_REQUIRED'), 422);
                    return;
                }
                $minute = $m;
                break;

            case 'daily':
                [$hour, $minute, $err] = $this->parseHourMinute();
                if ($err !== null) {
                    $this->jsonError($err, 422);
                    return;
                }
                break;

            case 'weekly':
                [$hour, $minute, $err] = $this->parseHourMinute();
                if ($err !== null) {
                    $this->jsonError($err, 422);
                    return;
                }
                $dow = $this->parseDaysOfWeek();
                if ($dow === '') {
                    $this->jsonError(__('TEXT_CRON_VALIDATION_DAYS_OF_WEEK_REQUIRED'), 422);
                    return;
                }
                break;

            case 'monthly':
                [$hour, $minute, $err] = $this->parseHourMinute();
                if ($err !== null) {
                    $this->jsonError($err, 422);
                    return;
                }
                $dom = $this->parseDaysOfMonth();
                if ($dom === '') {
                    $this->jsonError(__('TEXT_CRON_VALIDATION_DAYS_OF_MONTH_REQUIRED'), 422);
                    return;
                }
                break;
        }

        $userId = $this->auth->getCurrentUserId();

        $this->db->execute(
            'UPDATE cron_jobs
             SET frequency=?, every_n_minutes=?, days_of_week=?, days_of_month=?,
                 hour=?, minute=?, log_to_db=?, email_report=?, updated_by=?, updated_at=UTC_TIMESTAMP()
             WHERE id=? AND active=1',
            [$freq, $everyN, $dow, $dom, $hour, $minute, $logToDB, $emailReport, $userId, $id]
        );

        try {
            $new = ['frequency' => $freq, 'every_n_minutes' => $everyN, 'days_of_week' => $dow,
                    'days_of_month' => $dom, 'hour' => $hour, 'minute' => $minute,
                    'log_to_db' => $logToDB, 'email_report' => $emailReport];
            ActivityLogger::log(
                $userId,
                'update_cron_jobs',
                'cron_job',
                $id,
                $old,
                $new,
                'admin',
                null,
                null,
                (string) $old['job_key']
            );
        } catch (\Throwable $e) {
            $this->logger->warning('CronAdmin: failed to write saveOne audit log: ' . $e->getMessage());
        }

        $this->jsonOk(['message' => __('TEXT_CRON_SAVE_SUCCESS')]);
    }

    // =========================================================================
    // AJAX — toggle enabled
    // =========================================================================

    /**
     * Toggles the enabled flag for a single job.
     *
     * @param int $id
     * @return void
     */
    public function toggle(int $id): void
    {
        $this->requireMethod('POST');
        $this->requireAuth('toggle');
        $this->requireCsrf();
        $this->requireManifestHealthy();

        $job = $this->db->fetchOne('SELECT id, job_key, enabled FROM cron_jobs WHERE id = ? AND active = 1', [$id]);
        if ($job === null) {
            $this->jsonError(__('TEXT_ERROR_NOT_FOUND'), 404);
            return;
        }

        $newEnabled = (int) $job['enabled'] === 1 ? 0 : 1;
        $userId     = $this->auth->getCurrentUserId();

        $this->db->execute(
            'UPDATE cron_jobs SET enabled = ?, updated_by = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?',
            [$newEnabled, $userId, $id]
        );

        $action = $newEnabled ? 'enable_cron_job' : 'disable_cron_job';
        try {
            ActivityLogger::log(
                $userId,
                $action,
                'cron_job',
                $id,
                ['enabled' => (int) $job['enabled']],
                ['enabled' => $newEnabled],
                'admin',
                null,
                null,
                (string) $job['job_key']
            );
        } catch (\Throwable $e) {
            $this->logger->warning('CronAdmin: failed to write toggle audit log: ' . $e->getMessage());
        }

        $this->jsonOk(['enabled' => $newEnabled]);
    }

    // =========================================================================
    // AJAX — run now
    // =========================================================================

    /**
     * Enqueues an async manual run for the given job.
     *
     * Uses an atomic claim (WHERE trigger_pending=0) to prevent stacking.
     * Returns 202 Accepted on success, 409 Conflict when already pending.
     *
     * @param int $id
     * @return void
     */
    public function runNow(int $id): void
    {
        $this->requireMethod('POST');
        $this->requireAuth('run_now');
        $this->requireCsrf();
        $this->requireManifestHealthy();

        $job = $this->db->fetchOne('SELECT id, job_key, last_run_at FROM cron_jobs WHERE id = ? AND active = 1', [$id]);
        if ($job === null) {
            $this->jsonError(__('TEXT_ERROR_NOT_FOUND'), 404);
            return;
        }

        $userId  = $this->auth->getCurrentUserId();
        $claimed = $this->db->execute(
            'UPDATE cron_jobs SET trigger_pending = 1, trigger_pending_at = UTC_TIMESTAMP(), trigger_pending_by = ?
             WHERE id = ? AND trigger_pending = 0',
            [$userId, $id]
        );

        if ($claimed !== 1) {
            $this->jsonError(__('TEXT_CRON_RUN_NOW_ALREADY_PENDING'), 409);
            return;
        }

        $sinceTs = ($job['last_run_at'] !== null) ? $job['last_run_at'] : '1970-01-01 00:00:00';

        http_response_code(202);
        $this->json(['accepted' => true, 'since_ts' => $sinceTs]);
    }

    // =========================================================================
    // AJAX — poll run status
    // =========================================================================

    /**
     * Returns the current run state of a job for the admin UI polling loop.
     *
     * @param int $id
     * @return void
     */
    public function pollRunStatus(int $id): void
    {
        $this->requireMethod('GET');
        $this->requireAuth('view');

        $sinceTs = (string) ($_GET['since_ts'] ?? '1970-01-01 00:00:00');
        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $sinceTs)) {
            http_response_code(400);
            $this->json(['error' => __('TEXT_CRON_INVALID_SINCE_TS')]);
            return;
        }

        $row = $this->db->fetchOne(
            'SELECT trigger_pending, last_run_at, last_status, last_duration_ms, last_output_excerpt
             FROM cron_jobs WHERE id = ?',
            [$id]
        );

        if ($row === null) {
            $this->json(['trigger_pending' => false, 'last_run_at' => null, 'last_status' => null,
                         'last_duration_ms' => null, 'output_excerpt' => null, 'completed' => false]);
            return;
        }

        $lastRunAtRaw = $row['last_run_at']; // UTC string from DB

        $completed = (int) $row['trigger_pending'] === 0
            && $lastRunAtRaw !== null
            && strtotime((string) $lastRunAtRaw) > strtotime($sinceTs); // both UTC — comparison is consistent

        $this->json([
            'trigger_pending'  => (bool) $row['trigger_pending'],
            'last_run_at'      => $this->tz->utcToDisplay($lastRunAtRaw), // display TZ for DOM update
            'last_status'      => $row['last_status'],
            'last_duration_ms' => $row['last_duration_ms'] !== null ? (int) $row['last_duration_ms'] : null,
            'output_excerpt'   => $row['last_output_excerpt'],
            'completed'        => $completed,
        ]);
    }

    // =========================================================================
    // AJAX — toggle dispatcher
    // =========================================================================

    /**
     * Flips the master dispatcher kill switch.
     *
     * @return void
     */
    public function toggleDispatcher(): void
    {
        $this->requireMethod('POST');
        $this->requireAuth('toggle_dispatcher');
        $this->requireCsrf();
        $this->requireManifestHealthy();

        $old    = $this->killSwitch->get();
        $new    = !$old;
        $userId = $this->auth->getCurrentUserId();

        $this->killSwitch->set($new);

        try {
            ActivityLogger::log(
                $userId,
                'toggle_cron_dispatcher',
                'cron_dispatcher',
                null,
                ['enabled' => $old],
                ['enabled' => $new],
                'admin',
                null,
                null,
                null
            );
        } catch (\Throwable $e) {
            $this->logger->warning('CronAdmin: failed to write dispatcher toggle audit log: ' . $e->getMessage());
        }

        $this->jsonOk(['enabled' => $new]);
    }

    // =========================================================================
    // Internal helpers
    // =========================================================================

    /**
     * Aborts with 405 when the request method does not match.
     *
     * @param string $expected  'GET' or 'POST'.
     * @return void
     */
    private function requireMethod(string $expected): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== $expected) {
            http_response_code(405);
            header('Allow: ' . $expected);
            exit;
        }
    }

    /**
     * Aborts with 403 when the current user is not authorised.
     *
     * @param string $action
     * @return void
     */
    private function requireAuth(string $action): void
    {
        if (!$this->auth->isAuthorized($action)) {
            $this->jsonError(__('TEXT_ERROR_FORBIDDEN'), 403);
            exit;
        }
    }

    /**
     * Aborts with 419 when the CSRF token is invalid.
     *
     * @return void
     */
    private function requireCsrf(): void
    {
        if (!$this->csrf->validate()) {
            $this->jsonError(__('TEXT_CRON_CSRF_FAILED'), 419);
            exit;
        }
    }

    /**
     * Aborts with 422 when the manifest currently fails validation.
     *
     * Prevents crafted requests from sneaking past the UI's disabled buttons.
     *
     * @return void
     */
    private function requireManifestHealthy(): void
    {
        try {
            $this->manifestReader->load($this->manifestPath);
        } catch (InvalidManifestException $e) {
            http_response_code(422);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'error'   => __('TEXT_CRON_MANIFEST_BROKEN'),
                'details' => $e->getViolations(),
            ], JSON_THROW_ON_ERROR);
            exit;
        }
    }

    /**
     * Emits a JSON success response.
     *
     * @param array<string, mixed> $data  Merged with {success: true}.
     * @return void
     */
    private function jsonOk(array $data = []): void
    {
        $this->json(array_merge(['success' => true], $data));
    }

    /**
     * Emits a JSON error response.
     *
     * @param string $message
     * @param int    $code
     * @return void
     */
    private function jsonError(string $message, int $code = 400): void
    {
        http_response_code($code);
        $this->json(['error' => $message]);
    }

    /**
     * Emits a JSON-encoded response with the correct Content-Type header.
     *
     * @param mixed $data
     * @return void
     */
    private function json(mixed $data): void
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_THROW_ON_ERROR);
    }

    /**
     * Renders a module view file with the given data.
     *
     * @param string               $view      Path relative to views/ (e.g. 'admin/index.php').
     * @param array<string, mixed> $data
     * @return void
     */
    private function renderView(string $view, array $data): void
    {
        $viewFile = dirname(__DIR__) . '/views/' . ltrim($view, '/');
        if (!file_exists($viewFile)) {
            $this->logger->error("CronAdmin: view file not found: {$viewFile}");
            http_response_code(500);
            return;
        }
        extract($data, EXTR_SKIP);
        include $viewFile;
    }

    /**
     * Parses hour (0–23) and minute (0–59) from $_POST, returning [h, m, errorMsg|null].
     *
     * @return array{int|null, int|null, string|null}
     */
    private function parseHourMinute(): array
    {
        $h = isset($_POST['hour'])   ? (int) $_POST['hour']   : -1;
        $m = isset($_POST['minute']) ? (int) $_POST['minute'] : -1;
        if ($h < 0 || $h > 23 || $m < 0 || $m > 59) {
            return [null, null, __('TEXT_CRON_VALIDATION_HOUR_MINUTE_REQUIRED')];
        }
        return [$h, $m, null];
    }

    /**
     * Parses days_of_week checkboxes from $_POST, returns CSV of 0–6 or ''.
     *
     * @return string
     */
    private function parseDaysOfWeek(): string
    {
        $raw  = $_POST['days_of_week_cb'] ?? [];
        if (!is_array($raw)) {
            return '';
        }
        $valid = array_filter(array_keys($raw), fn($d) => (int) $d >= 0 && (int) $d <= 6);
        return $valid ? implode(',', array_map('intval', $valid)) : '';
    }

    /**
     * Parses days_of_month from $_POST, returns CSV of 1–31 or ''.
     *
     * @return string
     */
    private function parseDaysOfMonth(): string
    {
        $raw  = (string) ($_POST['days_of_month'] ?? '');
        $nums = array_filter(array_map('intval', explode(',', $raw)));
        $valid = array_filter($nums, fn($d) => $d >= 1 && $d <= 31);
        return $valid ? implode(',', $valid) : '';
    }

    /**
     * Collects all user IDs referenced in the jobs list for the userMap lookup.
     *
     * @param list<array<string, mixed>> $jobs
     * @return list<int>
     */
    private function collectUserIds(array $jobs): array
    {
        $ids = [];
        foreach ($jobs as $job) {
            foreach (['updated_by', 'trigger_pending_by'] as $col) {
                if (isset($job[$col]) && $job[$col] !== null) {
                    $ids[] = (int) $job[$col];
                }
            }
        }
        return array_values(array_unique($ids));
    }
}
