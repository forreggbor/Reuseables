<?php

/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * Maps LicenseManager API HTTP error responses to stable client error codes and retry hints.
 */

declare(strict_types=1);

namespace PatchModule;

/**
 * ServerErrorMapper - Map patch server HTTP errors to client error codes
 *
 * Parses the LicenseManager API's wrapped error shape
 * {success, status, error:{message, detail}} and returns a stable
 * error_code string and an optional retry_after seconds hint.
 *
 * Mapping is based on exact (http_status, error.message) tuples.
 * For HTTP 403 + "License not valid", the error.detail field carries a stable
 * i18n key from LicenseValidationService::validate() (TEXT_API_LICENSE_*).
 *
 * @package PatchModule
 */
class ServerErrorMapper
{
    /**
     * Map an HTTP result array to a client error_code and optional retry_after hint
     *
     * @param array $httpResult HTTP result with keys: status_code, headers (optional), body (optional)
     * @return array{error_code: ?string, retry_after: ?int}
     */
    public static function map(array $httpResult): array
    {
        $statusCode = $httpResult['status_code'] ?? 0;
        $body       = (string) ($httpResult['body'] ?? '');
        $headers    = $httpResult['headers'] ?? [];

        if ($statusCode === 0) {
            return ['error_code' => 'network_error', 'retry_after' => null];
        }

        if ($statusCode === 429) {
            return [
                'error_code'  => 'rate_limited',
                'retry_after' => self::parseRetryAfter((string) ($headers['retry-after'] ?? '')),
            ];
        }

        if ($statusCode >= 500) {
            $message = self::extractMessage($body);
            if ($statusCode === 503 && $message === 'signing_unavailable') {
                return ['error_code' => 'signing_unavailable', 'retry_after' => null];
            }
            return ['error_code' => 'server_error', 'retry_after' => null];
        }

        if ($statusCode === 403) {
            $message = self::extractMessage($body);
            $detail  = self::extractDetail($body);

            if ($message === 'license_key_not_recently_verified' || $message === 'license_key_ip_mismatch') {
                return ['error_code' => 'not_recently_verified', 'retry_after' => null];
            }
            if ($message === 'Package mismatch') {
                return ['error_code' => 'package_mismatch', 'retry_after' => null];
            }
            if ($message === 'Invalid license') {
                return ['error_code' => 'invalid_license', 'retry_after' => null];
            }
            if ($message === 'License not valid') {
                return ['error_code' => self::mapLicenseDetail($detail), 'retry_after' => null];
            }
        }

        return ['error_code' => null, 'retry_after' => null];
    }

    /**
     * Extract the error.message value from a JSON response body
     *
     * Handles both the wrapped {error:{message, detail}} shape (current server) and the
     * legacy flat {error:"..."} shape (pre-v2.8 server builds).
     *
     * @param string $body Raw response body
     * @return string
     */
    private static function extractMessage(string $body): string
    {
        if ($body === '') {
            return '';
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return '';
        }
        $errorNode = $decoded['error'] ?? null;
        if (is_array($errorNode)) {
            return (string) ($errorNode['message'] ?? '');
        }
        return (string) ($errorNode ?? '');
    }

    /**
     * Extract the error.detail value from a JSON response body
     *
     * @param string $body Raw response body
     * @return string
     */
    private static function extractDetail(string $body): string
    {
        if ($body === '') {
            return '';
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return '';
        }
        $errorNode = $decoded['error'] ?? null;
        return is_array($errorNode) ? (string) ($errorNode['detail'] ?? '') : '';
    }

    /**
     * Map a license-state detail key to a client error_code
     *
     * The detail value is a stable i18n key returned by LicenseValidationService::validate()
     * — not a localized string — so exact-string matching is reliable.
     *
     * @param string $detail The error.detail value (TEXT_API_* key)
     * @return string
     */
    private static function mapLicenseDetail(string $detail): string
    {
        return match ($detail) {
            'TEXT_API_LICENSE_REVOKED'                              => 'license_revoked',
            'TEXT_API_LICENSE_EXPIRED', 'TEXT_API_LICENSE_GRACE_PERIOD' => 'license_expired',
            'TEXT_API_IP_MISMATCH'                                  => 'license_ip_mismatch',
            default                                                  => 'invalid_license',
        };
    }

    /**
     * Parse a Retry-After header value to a number of seconds
     *
     * Accepts both delta-seconds ("3600") and HTTP-date ("Wed, 21 Oct 2025 07:28:00 GMT").
     *
     * @param string $value Raw Retry-After header value
     * @return int|null Seconds to wait, or null when value is absent or unparseable
     */
    private static function parseRetryAfter(string $value): ?int
    {
        if ($value === '') {
            return null;
        }
        if (ctype_digit($value)) {
            return (int) $value;
        }
        $ts = strtotime($value);
        return $ts !== false ? max(0, $ts - time()) : null;
    }
}
