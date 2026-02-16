<?php

declare(strict_types=1);

namespace Virtualjog;

/**
 * Internal cURL-based HTTP client for the Virtualjog API
 *
 * Handles all HTTP communication with the Virtualjog SaaS service.
 * Returns ApiResult objects for consistent error handling.
 */
class ApiClient
{
    /** @var string API base URL */
    private string $baseUrl;

    /** @var int cURL timeout in seconds */
    private int $timeout;

    /** @var callable|null Optional logging callback fn(string $message, string $level) */
    private mixed $logCallback;

    /**
     * Initialize the API client
     *
     * @param string        $baseUrl     API base URL (with trailing slash)
     * @param int           $timeout     cURL timeout in seconds (default: 15)
     * @param callable|null $logCallback Optional logging callback fn(string $message, string $level)
     * @throws \RuntimeException If the cURL extension is not loaded
     */
    public function __construct(string $baseUrl, int $timeout = 15, ?callable $logCallback = null)
    {
        if (!extension_loaded('curl')) {
            throw new \RuntimeException('The cURL extension is required for VirtualjogClient.');
        }

        $this->baseUrl = $baseUrl;
        $this->timeout = $timeout;
        $this->logCallback = $logCallback;
    }

    /**
     * Send a POST request to a Virtualjog API endpoint
     *
     * @param string               $endpoint Endpoint path (appended to base URL)
     * @param array<string, mixed> $params   POST parameters
     * @return ApiResult
     */
    public function post(string $endpoint, array $params): ApiResult
    {
        $url = $this->baseUrl . $endpoint;
        $this->log("POST {$url}", 'INFO');

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($params),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
            ],
        ]);

        $responseBody = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $curlErrno = curl_errno($ch);
        curl_close($ch);

        if ($curlErrno !== 0) {
            $message = "cURL error ({$curlErrno}): {$curlError}";
            $this->log($message, 'ERROR');

            return ApiResult::error($message);
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $message = "HTTP error: received status code {$httpCode}";
            $this->log($message, 'ERROR');

            return ApiResult::error($message, $httpCode);
        }

        if (!is_string($responseBody) || $responseBody === '') {
            $message = 'Empty response body from API';
            $this->log($message, 'ERROR');

            return ApiResult::error($message, $httpCode);
        }

        $data = json_decode($responseBody, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $message = 'Invalid JSON response: ' . json_last_error_msg();
            $this->log($message, 'ERROR');

            return ApiResult::error($message, $httpCode);
        }

        $this->log("Response OK (HTTP {$httpCode})", 'INFO');

        return ApiResult::success($data);
    }

    /**
     * Log a message via the configured callback
     *
     * @param string $message Log message
     * @param string $level   Log level (INFO, ERROR)
     * @return void
     */
    private function log(string $message, string $level): void
    {
        if ($this->logCallback !== null) {
            ($this->logCallback)($message, $level);
        }
    }
}
