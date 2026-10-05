<?php
/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * NAV API authentication helper — computes passwordHash and requestSignature.
 */

declare(strict_types=1);

namespace NavSync;

/**
 * Computes NAV Online Számla API v3.0 authentication fields.
 *
 * Both methods are stateless and have no side effects.
 */
class NavAuthHelper
{
    /**
     * Computes the SHA-512 password hash required in every NAV API request.
     *
     * @param string $plainPassword The NAV technical user's plain text password.
     * @return string Uppercase hex SHA-512 hash.
     */
    public static function passwordHash(string $plainPassword): string
    {
        return strtoupper(hash('sha512', $plainPassword));
    }

    /**
     * Computes the SHA3-512 request signature required in every NAV API request.
     *
     * NAV Online Számla v3 (non-manage operations): SHA3-512 of the concatenation, without separators, of
     *   requestId + timestamp + signKey
     * where the timestamp is the header timestamp reduced to digits only, "yyyyMMddHHmmss" in UTC
     * (milliseconds, dashes, colons, "T" and "Z" removed).
     *
     * @param string $requestId Unique request id, the same value as in <common:requestId>.
     * @param string $timestamp ISO8601 UTC timestamp as sent in <common:timestamp>, e.g. "2026-06-26T10:00:00.000Z".
     * @param string $signKey   Plain text XML signing key ("aláírókulcs", already decrypted).
     * @return string Uppercase hex SHA3-512 hash.
     */
    public static function requestSignature(string $requestId, string $timestamp, string $signKey): string
    {
        $digits = preg_replace('/\.\d{3}|\D+/', '', $timestamp) ?? '';
        return strtoupper(hash('sha3-512', $requestId . $digits . $signKey));
    }

    /**
     * Generates a unique request ID in NAV format: "RID" + 12 random digits.
     *
     * @return string Request ID, e.g. "RID042381957623".
     * @throws \Random\RandomException
     */
    public static function requestId(): string
    {
        return 'RID' . str_pad((string)random_int(0, 999999999999), 12, '0', STR_PAD_LEFT);
    }

    /**
     * Returns the current UTC time formatted as NAV expects in <common:timestamp>.
     *
     * @return string e.g. "2026-06-26T10:00:00.000Z"
     */
    public static function timestamp(): string
    {
        return gmdate('Y-m-d\TH:i:s') . '.000Z';
    }
}
