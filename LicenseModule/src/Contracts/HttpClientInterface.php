<?php

declare(strict_types=1);

namespace LicenseModule\Contracts;

/**
 * HTTP client interface for license module
 *
 * Abstracts HTTP operations for framework independence.
 */
interface HttpClientInterface
{
    /**
     * Send a POST request
     *
     * @param string $url Target URL
     * @param array $data Data to send (will be JSON encoded)
     * @param array $headers Additional headers
     * @param int $timeout Timeout in seconds
     * @return array Response with keys: success, status_code, body, error
     */
    public function post(string $url, array $data, array $headers = [], int $timeout = 10): array;
}
