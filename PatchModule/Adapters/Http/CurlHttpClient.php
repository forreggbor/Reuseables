<?php

declare(strict_types=1);

namespace PatchModule\Adapters\Http;

use PatchModule\Contracts\HttpClientInterface;

/**
 * Default cURL-based HTTP client for patch server communication
 *
 * Supports JSON API requests and binary file downloads with SSL verification.
 *
 * @package PatchModule
 */
class CurlHttpClient implements HttpClientInterface
{
    /**
     * {@inheritdoc}
     */
    public function postJson(string $url, array $data, int $timeout = 30): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false || !empty($curlError)) {
            return [
                'success' => false,
                'status_code' => $httpCode,
                'body' => null,
                'error' => 'cURL error: ' . $curlError,
            ];
        }

        return [
            'success' => $httpCode >= 200 && $httpCode < 300,
            'status_code' => $httpCode,
            'body' => $response,
            'error' => null,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function downloadFile(string $url, array $postData, string $destPath, int $timeout = 300): array
    {
        $fp = fopen($destPath, 'wb');
        if ($fp === false) {
            return [
                'success' => false,
                'status_code' => 0,
                'headers' => [],
                'error' => 'Cannot create destination file: ' . $destPath,
                'body' => null,
            ];
        }

        $responseHeaders = [];
        $headerCallback = function ($ch, string $header) use (&$responseHeaders): int {
            $parts = explode(':', $header, 2);
            if (count($parts) === 2) {
                $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
            return strlen($header);
        };

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($postData),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_FILE => $fp,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HEADERFUNCTION => $headerCallback,
        ]);

        curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        fclose($fp);

        if (!empty($curlError)) {
            @unlink($destPath);
            return [
                'success' => false,
                'status_code' => $httpCode,
                'headers' => $responseHeaders,
                'error' => 'Download failed: ' . $curlError,
                'body' => null,
            ];
        }

        if ($httpCode !== 200) {
            // Read the error body before deleting the temp file so callers can inspect error codes
            $errorBody = is_readable($destPath) ? (file_get_contents($destPath) ?: null) : null;
            @unlink($destPath);
            return [
                'success' => false,
                'status_code' => $httpCode,
                'headers' => $responseHeaders,
                'error' => 'Download failed with HTTP ' . $httpCode,
                'body' => $errorBody,
            ];
        }

        return [
            'success' => true,
            'status_code' => $httpCode,
            'headers' => $responseHeaders,
            'error' => null,
            'body' => null,
        ];
    }
}