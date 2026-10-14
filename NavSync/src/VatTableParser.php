<?php
/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * Reads the VAT table (turnover collector → VAT rate) NAV cash registers write into their log.
 */

declare(strict_types=1);

namespace NavSync;

/**
 * Parses the free-text "VAT" field of the INF record (and the equal VATUPD event text).
 *
 * The format is not defined in NAV's XSD; it was read from real NAV log files of a FISCAT AEE register:
 *
 *   VATUPD~A24119414~20140328080654~A-5-4,76-04,76%~B-18-15,25-15,25%~C-27-21,26-21,26%~D-0-0-AJT~E-0-0-AM~72910FE016
 *
 * Fields are separated by "~": the "VATUPD" marker, the AP number, the table timestamp (YYYYMMDDHHMMSS), one or more
 * groups "<letter>-<rate>-<VAT content of the gross>-<display>", and a 10 character check value. For a group without a
 * numeric rate the fourth part is a text label (AJT, AM) instead of a percentage. Anything that does not match this
 * shape is "unreadable" (null); the format is never guessed.
 */
final class VatTableParser
{
    /** Marker the table text starts with. */
    private const MARKER = 'VATUPD';

    /**
     * Turns the VAT table text into a collector letter → rate map.
     *
     * The receipt lines carry the collector as table letter plus two digits (C00), so callers look a line up by its first letter.
     *
     * @param string $text Content of INF/VAT.
     * @return array<string, string>|null Table letter ("A") → VAT rate ("27", "5", "0") or the group's text label ("AJT", "AM"); null when unreadable.
     */
    public static function parse(string $text): ?array
    {
        $fields = explode('~', trim($text));
        // marker, AP number, timestamp, at least one group, check value
        if (count($fields) < 5 || $fields[0] !== self::MARKER) {
            return null;
        }
        $check = array_pop($fields);
        if (preg_match('/^[0-9A-Za-z]{10}\z/', $check) !== 1 || preg_match('/^\d{14}\z/', $fields[2]) !== 1 || $fields[1] === '') {
            return null;
        }

        $map = [];
        foreach (array_slice($fields, 3) as $group) {
            $parts = explode('-', $group);
            if (count($parts) !== 4 || preg_match('/^[A-Z]\z/', $parts[0]) !== 1 || isset($map[$parts[0]])) {
                return null;
            }
            [$letter, $rate, $content, $display] = $parts;
            if (preg_match('/^\d{1,3}\z/', $rate) !== 1 || preg_match('/^\d+(,\d+)?\z/', $content) !== 1) {
                return null;
            }
            if (preg_match('/^[A-Za-z]{1,10}\z/', $display) === 1) {
                // A text label (AJT, AM) replaces the rate: such a group carries no VAT, and the label shows up in the report.
                if ((int)$rate !== 0) {
                    return null;
                }
                $map[$letter] = strtoupper($display);
            } elseif (preg_match('/^\d+(,\d+)?%\z/', $display) === 1) {
                $map[$letter] = (string)(int)$rate;
            } else {
                return null;
            }
        }
        return $map;
    }
}
