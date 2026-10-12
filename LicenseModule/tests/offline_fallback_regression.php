<?php

declare(strict_types=1);

/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * Regression fixture for Reusables#57: LicenseValidator::validate() must fall back to
 * offline grace mode only for \Exception failures (server unreachable, bad response),
 * never for an \Error such as a TypeError — that is a programming error and must reach
 * the host's error handler instead of silently extending the grace period.
 *
 * Runs without a database or network: php tests/offline_fallback_regression.php
 */

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'LicenseModule\\')) {
        return;
    }
    $file = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen('LicenseModule\\'))) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

use LicenseModule\Contracts\DatabaseAdapterInterface;
use LicenseModule\Contracts\HttpClientInterface;
use LicenseModule\LicenseValidator;

/** In-memory license store that records every write. */
$database = new class implements DatabaseAdapterInterface {
    /** @var array<int, array<string, mixed>> Data passed to saveLicenseInfo(). */
    public array $saved = [];

    /** {@inheritdoc} */
    public function getLicenseInfo(): ?array
    {
        return $this->getLatestLicenseInfo();
    }

    /** {@inheritdoc} */
    public function saveLicenseInfo(array $data): bool
    {
        $this->saved[] = $data;
        return true;
    }

    /** {@inheritdoc} */
    public function logValidation(int $licenseId, string $status, array $responseData = [], string $errorMessage = ''): bool
    {
        return true;
    }

    /** {@inheritdoc} */
    public function getLatestLicenseInfo(): ?array
    {
        return ['id' => 1, 'status' => 'active', 'validated_at' => date('Y-m-d H:i:s', time() - 86400)];
    }

    /** {@inheritdoc} */
    public function getValidationHistory(int $licenseId, int $limit): array
    {
        return [];
    }
};

/**
 * Build an HTTP client stub that returns a fixed response.
 *
 * @param array<string, mixed> $response Response array (success, status_code, body, error).
 * @return HttpClientInterface Stub client.
 */
$client = static fn (array $response): HttpClientInterface => new class ($response) implements HttpClientInterface {
    /** @param array<string, mixed> $response Fixed response. */
    public function __construct(private array $response)
    {
    }

    /** {@inheritdoc} */
    public function post(string $url, array $data, array $headers = [], int $timeout = 10): array
    {
        return $this->response;
    }
};

$failures = [];

// 1. A programming error while parsing the server answer: "status" is an array, which makes
//    LicenseStatus::mapFromServer(string ...) throw a TypeError. It must NOT become offline grace.
$database->saved = [];
$validator = new LicenseValidator(
    $database,
    $client(['success' => true, 'status_code' => 200, 'body' => '{"data":{"valid":true,"status":["x"]}}', 'error' => null]),
    'https://license.invalid'
);
try {
    $result = $validator->validate('KEY', 'example.test');
    $failures[] = 'TypeError was swallowed and turned into a result: ' . json_encode($result);
} catch (\TypeError) {
    // expected: the Error reaches the caller
}
if ($database->saved !== []) {
    $failures[] = 'grace period was extended by a programming error (license row was written)';
}

// 2. An ordinary failure to reach the server must still use the offline grace path.
$database->saved = [];
$validator = new LicenseValidator(
    $database,
    $client(['success' => false, 'status_code' => 0, 'body' => '', 'error' => 'timeout']),
    'https://license.invalid'
);
$result = $validator->validate('KEY', 'example.test');
if (($result['offline'] ?? false) !== true || ($result['success'] ?? false) !== true) {
    $failures[] = 'unreachable server no longer falls back to offline grace mode: ' . json_encode($result);
}

// 3. A malformed (but reachable) server answer is an \Exception and also takes the offline path.
$validator = new LicenseValidator(
    $database,
    $client(['success' => true, 'status_code' => 200, 'body' => 'not json', 'error' => null]),
    'https://license.invalid'
);
$result = $validator->validate('KEY', 'example.test');
if (($result['offline'] ?? false) !== true) {
    $failures[] = 'invalid JSON from the server no longer falls back to offline grace mode: ' . json_encode($result);
}

if ($failures !== []) {
    fwrite(STDERR, "FAIL:\n - " . implode("\n - ", $failures) . PHP_EOL);
    exit(1);
}

echo "PASS: offline grace is used for \\Exception failures only; \\Error propagates\n";
exit(0);
