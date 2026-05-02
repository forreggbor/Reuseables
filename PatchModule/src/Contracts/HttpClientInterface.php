<?php

declare(strict_types=1);

namespace PatchModule\Contracts;

/**
 * HTTP client for patch server communication
 *
 * Supports both JSON API requests and binary file downloads.
 *
 * @package PatchModule
 */
interface HttpClientInterface
{
    /**
     * Send a JSON POST request
     *
     * @param string $url Target URL
     * @param array $data Data to JSON-encode as POST body
     * @param int $timeout Timeout in seconds
     * @return array{success: bool, status_code: int, headers: array, body: ?string, error: ?string}
     *         headers contains response headers as lowercase-key => value pairs
     */
    public function postJson(string $url, array $data, int $timeout = 30): array;

    /**
     * Download a file via POST request to disk
     *
     * On success the response body is written to destPath. On failure (non-200 or cURL error)
     * the file is removed and the response body is returned in the result array so callers
     * can inspect error codes returned by the server.
     *
     * @param string $url Download URL
     * @param array $postData Data to JSON-encode as POST body
     * @param string $destPath Local file path to write the download to
     * @param int $timeout Timeout in seconds
     * @return array{success: bool, status_code: int, headers: array, error: ?string, body: ?string}
     *         headers contains response headers as lowercase-key => value pairs;
     *         body is populated on failure only
     */
    public function downloadFile(string $url, array $postData, string $destPath, int $timeout = 300): array;
}