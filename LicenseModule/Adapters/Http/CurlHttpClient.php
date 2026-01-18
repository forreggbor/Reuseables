<?php

declare(strict_types=1);

namespace LicenseModule\Adapters\Http;

use LicenseModule\Contracts\HttpClientInterface;

/**
 * cURL HTTP client for license module
 */
class CurlHttpClient implements HttpClientInterface
{
    private bool $verifySsl;

    /**
     * @param bool $verifySsl Whether to verify SSL certificates (default: true)
     */
    public function __construct(bool $verifySsl = true)
    {
        $this->verifySsl = $verifySsl;
    }

    /**
     * {@inheritDoc}
     */
    public function post(string $url, array $data, array $headers = [], int $timeout = 10): array
    {
        $ch = curl_init($url);

        if ($ch === false) {
            return [
                'success' => false,
                'status_code' => 0,
                'body' => null,
                'error' => 'Failed to initialize cURL',
            ];
        }

        $defaultHeaders = ['Content-Type: application/json'];
        $allHeaders = array_merge($defaultHeaders, $headers);

        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data, JSON_UNESCAPED_UNICODE));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $allHeaders);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $this->verifySsl);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error !== '') {
            return [
                'success' => false,
                'status_code' => $httpCode,
                'body' => null,
                'error' => $error,
            ];
        }

        return [
            'success' => true,
            'status_code' => $httpCode,
            'body' => $response,
            'error' => null,
        ];
    }
}
