<?php
/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * Contract of the NAV cash register (OPF) query client (lets the sync run against a fake in tests).
 */

declare(strict_types=1);

namespace NavSync;

/**
 * The two OPF query operations the cash register sync needs.
 */
interface CashRegisterClientInterface
{
    /** Pattern of a valid AP number (cash register identifier): one capital letter and eight digits, nothing after. */
    public const AP_NUMBER_PATTERN = '/^[A-Z][0-9]{8}\z/';

    /**
     * Reads the state of every cash register of the technical user.
     *
     * @return list<array{ap_number:string, last_communication_at:?string, last_file_at:?string, min_file:int, max_file:int}>
     *         Dates are UTC "Y-m-d H:i:s" or null; min/max file are 0 when NAV holds no file.
     * @throws \RuntimeException On HTTP, cURL, malformed response or NAV functional error.
     */
    public function queryStatus(): array;

    /**
     * Downloads the log files of one cash register for a file-number range.
     *
     * NAV may return fewer files than asked for (size limit); "all_sent" tells whether the range is complete.
     *
     * @param string $apNumber AP number.
     * @param int    $from     First file number.
     * @param int    $to       Last file number.
     * @return array{files: list<array{file_number:int, p7b:?string, validation_code:string, validation_error:?string, error:?string}>, all_sent:bool}
     *         Per file either the unzipped P7B bytes or an error text (missing attachment, bad ZIP).
     * @throws \InvalidArgumentException On an invalid AP number or file-number range (before any request is sent).
     * @throws \RuntimeException On HTTP, cURL, malformed response or NAV functional error.
     */
    public function queryFiles(string $apNumber, int $from, int $to): array;
}
