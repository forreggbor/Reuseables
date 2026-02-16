<?php

declare(strict_types=1);

namespace PatchModule;

/**
 * ProgressTracker - Atomic JSON file-based progress tracking
 *
 * Tracks installation step progress via JSON files for AJAX polling.
 * Uses atomic writes (write to .tmp then rename) to prevent partial reads.
 * Designed to work without database access (important during DB migrations).
 *
 * @package PatchModule
 */
class ProgressTracker
{
    /** @var string Absolute path to the progress JSON file */
    private string $progressFile = '';

    /** @var array Current progress steps state */
    private array $progressSteps = [];

    /** @var string Temp directory for progress files */
    private string $tempDir;

    /**
     * @param string $tempDir Absolute path to the temp directory
     */
    public function __construct(string $tempDir)
    {
        $this->tempDir = $tempDir;
    }

    /**
     * Set the progress file path for install progress tracking
     *
     * @param string $path Absolute path to the progress JSON file
     * @return void
     */
    public function setProgressFile(string $path): void
    {
        $this->progressFile = $path;
    }

    /**
     * Get the current progress file path
     *
     * @return string
     */
    public function getProgressFile(): string
    {
        return $this->progressFile;
    }

    /**
     * Initialize progress with a list of step IDs (all set to 'pending')
     *
     * @param array $stepIds List of step ID strings
     * @return void
     */
    public function initProgress(array $stepIds): void
    {
        $this->progressSteps = array_map(
            fn(string $id) => ['id' => $id, 'status' => 'pending'],
            $stepIds
        );
        $this->writeProgress();
    }

    /**
     * Mark a step as active (in progress)
     *
     * Any previously active step is automatically marked as completed.
     *
     * @param string $stepId The step ID to mark as active
     * @return void
     */
    public function stepProgress(string $stepId): void
    {
        if (empty($this->progressFile)) {
            return;
        }

        foreach ($this->progressSteps as &$step) {
            if ($step['id'] === $stepId) {
                $step['status'] = 'active';
                break;
            }
            if ($step['status'] === 'active' || $step['status'] === 'pending') {
                $step['status'] = 'completed';
            }
        }
        unset($step);
        $this->writeProgress();
    }

    /**
     * Mark all remaining steps as completed
     *
     * @return void
     */
    public function completeProgress(): void
    {
        if (empty($this->progressFile)) {
            return;
        }

        foreach ($this->progressSteps as &$step) {
            if ($step['status'] === 'active' || $step['status'] === 'pending') {
                $step['status'] = 'completed';
            }
        }
        unset($step);
        $this->writeProgress();
    }

    /**
     * Mark a step as failed
     *
     * @param string $stepId The step ID that failed
     * @return void
     */
    public function failProgress(string $stepId): void
    {
        if (empty($this->progressFile)) {
            return;
        }

        foreach ($this->progressSteps as &$step) {
            if ($step['id'] === $stepId) {
                $step['status'] = 'failed';
            } elseif ($step['status'] === 'active') {
                $step['status'] = 'failed';
            }
        }
        unset($step);
        $this->writeProgress();
    }

    /**
     * Get the currently active step ID (for error reporting)
     *
     * @return string The step ID that is currently active, or 'unknown'
     */
    public function getActiveStepId(): string
    {
        foreach ($this->progressSteps as $step) {
            if ($step['status'] === 'active') {
                return $step['id'];
            }
        }
        return 'unknown';
    }

    /**
     * Read install progress from a progress file
     *
     * @param string $token Progress token
     * @return array|null Progress data or null if file not found
     */
    public function getInstallProgress(string $token): ?array
    {
        $path = $this->tempDir . '/patch_progress_' . $token . '.json';
        if (!file_exists($path)) {
            return null;
        }

        $data = file_get_contents($path);
        if ($data === false) {
            return null;
        }

        return json_decode($data, true);
    }

    /**
     * Delete the progress file for a given token
     *
     * Called after the frontend has read the terminal state (completed/failed).
     *
     * @param string $token Progress token
     * @return void
     */
    public function deleteProgressFile(string $token): void
    {
        $path = $this->tempDir . '/patch_progress_' . $token . '.json';
        if (file_exists($path)) {
            @unlink($path);
        }
    }

    /**
     * Clean up stale progress files older than 1 hour
     *
     * @return void
     */
    public function cleanupStaleProgressFiles(): void
    {
        $pattern = $this->tempDir . '/patch_progress_*.json';
        $files = glob($pattern);
        if (!$files) {
            return;
        }

        $threshold = time() - 3600;
        foreach ($files as $file) {
            if (filemtime($file) < $threshold) {
                @unlink($file);
            }
        }
    }

    /**
     * Write progress state to the progress file atomically
     *
     * Uses write-to-temp-then-rename pattern to prevent partial reads.
     *
     * @return void
     */
    private function writeProgress(): void
    {
        if (empty($this->progressFile)) {
            return;
        }

        $dir = dirname($this->progressFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $data = json_encode(['steps' => $this->progressSteps], JSON_UNESCAPED_UNICODE);
        $tmp = $this->progressFile . '.tmp';
        file_put_contents($tmp, $data);
        rename($tmp, $this->progressFile);
    }
}