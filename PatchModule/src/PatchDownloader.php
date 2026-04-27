<?php

declare(strict_types=1);

namespace PatchModule;

use PatchModule\Contracts\DatabaseAdapterInterface;
use PatchModule\Contracts\HttpClientInterface;
use PatchModule\Contracts\LoggerInterface;

/**
 * PatchDownloader - Download and verify patch archives
 *
 * Downloads patch files from the patch server, verifies SHA-256 hash integrity,
 * and updates the patch history record status.
 *
 * @package PatchModule
 */
class PatchDownloader
{
    /** @var HttpClientInterface */
    private HttpClientInterface $httpClient;

    /** @var DatabaseAdapterInterface */
    private DatabaseAdapterInterface $database;

    /** @var LoggerInterface|null */
    private ?LoggerInterface $logger;

    /** @var string Patch server base URL */
    private string $serverUrl;

    /** @var string Temp directory for downloads */
    private string $tempDir;

    /** @var int Download timeout in seconds */
    private int $downloadTimeout;

    /**
     * @param HttpClientInterface $httpClient HTTP client for downloads
     * @param DatabaseAdapterInterface $database Database adapter for status updates
     * @param string $serverUrl Patch server base URL
     * @param string $tempDir Temp directory path
     * @param int $downloadTimeout Download timeout in seconds
     * @param LoggerInterface|null $logger Optional logger
     */
    public function __construct(
        HttpClientInterface $httpClient,
        DatabaseAdapterInterface $database,
        string $serverUrl,
        string $tempDir,
        int $downloadTimeout = 300,
        ?LoggerInterface $logger = null
    ) {
        $this->httpClient = $httpClient;
        $this->database = $database;
        $this->serverUrl = $serverUrl;
        $this->tempDir = $tempDir;
        $this->downloadTimeout = $downloadTimeout;
        $this->logger = $logger;
    }

    /**
     * Download a patch from the patch server
     *
     * Verifies SHA-256 hash after download. Stores file in the temp directory.
     * When the server returns HTTP 403 with a recently-verified precondition error,
     * the result includes an error_code of 'not_recently_verified' so callers can
     * retry after refreshing the license check.
     *
     * @param int $patchServerId Patch ID on the patch server
     * @param string $expectedSha256 Expected SHA-256 hash
     * @param int $patchHistoryId Local patch_history record ID
     * @param string $licenseKey License key for authentication
     * @return array{success: bool, file_path: ?string, error: ?string, error_code: ?string}
     */
    public function download(
        int $patchServerId,
        string $expectedSha256,
        int $patchHistoryId,
        string $licenseKey
    ): array {
        if (!is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0775, true);
        }

        $downloadUrl = $this->serverUrl . '/patches/' . $patchServerId . '/download';
        $tempFile = $this->tempDir . '/patch_download_' . time() . '.tgz';

        // Update status to downloading
        $this->database->updateHistoryRecord($patchHistoryId, ['status' => 'downloading']);

        // Download the file
        $result = $this->httpClient->downloadFile(
            $downloadUrl,
            ['license_key' => $licenseKey],
            $tempFile,
            $this->downloadTimeout
        );

        if (!$result['success']) {
            @unlink($tempFile);

            // Detect the recently-verified precondition failure (introduced in server v2.8.0).
            // Also accept the legacy code from the pre-patch v2.8.0 release for compatibility.
            if (($result['status_code'] ?? 0) === 403) {
                $errorCode = $this->extractErrorCode($result['body'] ?? '');
                if (
                    $errorCode === 'license_key_not_recently_verified'
                    || $errorCode === 'license_key_ip_mismatch'
                ) {
                    return [
                        'success' => false,
                        'file_path' => null,
                        'error' => $result['error'] ?? 'License must be verified before downloading',
                        'error_code' => 'not_recently_verified',
                    ];
                }
            }

            return ['success' => false, 'file_path' => null, 'error' => $result['error'], 'error_code' => null];
        }

        // Verify SHA-256 against expected hash
        $fileSha256 = hash_file('sha256', $tempFile);
        if ($fileSha256 !== $expectedSha256) {
            @unlink($tempFile);
            $this->log(
                "Patch download: SHA-256 mismatch. Expected: {$expectedSha256}, Got: {$fileSha256}",
                'ERROR'
            );
            return ['success' => false, 'file_path' => null, 'error' => 'File integrity check failed (SHA-256 mismatch)', 'error_code' => null];
        }

        // Also verify against server response header if available
        $responseSha256 = $result['headers']['x-content-sha256'] ?? '';
        if (!empty($responseSha256) && $fileSha256 !== $responseSha256) {
            @unlink($tempFile);
            $this->log(
                "Patch download: SHA-256 header mismatch. Header: {$responseSha256}, File: {$fileSha256}",
                'ERROR'
            );
            return ['success' => false, 'file_path' => null, 'error' => 'File integrity check failed (header SHA-256 mismatch)', 'error_code' => null];
        }

        // Update history with download timestamp
        $this->database->updateHistoryRecord($patchHistoryId, ['downloaded_at' => date('Y-m-d H:i:s')]);

        $this->log("Patch downloaded successfully: {$tempFile} (SHA-256 verified)", 'INFO');

        return ['success' => true, 'file_path' => $tempFile, 'error' => null, 'error_code' => null];
    }

    /**
     * Extract the error code string from a JSON response body
     *
     * Parses the body as JSON and returns the value of the top-level "error" key,
     * or an empty string when the body is not valid JSON or the key is absent.
     *
     * @param string $body Raw response body text
     * @return string Error code string, or empty string if not found
     */
    private function extractErrorCode(string $body): string
    {
        if (empty($body)) {
            return '';
        }
        $decoded = json_decode($body, true);
        return is_array($decoded) ? (string) ($decoded['error'] ?? '') : '';
    }

    /**
     * Log a message if logger is available
     *
     * @param string $message Log message
     * @param string $level Log level
     * @return void
     */
    private function log(string $message, string $level = 'INFO'): void
    {
        if ($this->logger !== null) {
            $this->logger->log($message, $level);
        }
    }
}