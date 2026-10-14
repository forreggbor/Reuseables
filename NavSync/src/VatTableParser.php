<?php
/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * Reads the VAT table (turnover collector → VAT rate) NAV cash registers write into their log.
 */

declare(strict_types=1);

namespace NavSync;

/**
 * Parses the free-text "VAT" field of the INF record.
 *
 * The format is not defined in NAV's XSD and is empty in the published samples, so this parser deliberately
 * answers "unreadable" (null) until the format is confirmed on real test-environment data (Task 5B of the plan).
 * Callers treat null as "no mapping": lines stay unmapped and are reported, never guessed.
 */
final class VatTableParser
{
    /**
     * Turns the VAT table text into a collector → rate map.
     *
     * @param string $text Content of INF/VAT.
     * @return array<string, string>|null Collector (e.g. "A01") → VAT rate ("27", "5", "0", "AAM", ...); null when unreadable.
     */
    public static function parse(string $text): ?array
    {
        return null;
    }
}
