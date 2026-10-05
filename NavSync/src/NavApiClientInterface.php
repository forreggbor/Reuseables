<?php
/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * Contract of the NAV Online Számla query client (lets the sync run against a fake in tests).
 */

declare(strict_types=1);

namespace NavSync;

/**
 * The two NAV query operations the synchronisation needs.
 */
interface NavApiClientInterface
{
    /**
     * Fetches one page (up to 100 rows) of invoice digests for an insDate interval.
     *
     * @param string $direction 'INBOUND' or 'OUTBOUND'.
     * @param string $dateFrom  ISO8601 UTC datetime, e.g. "2026-06-01T00:00:00.000Z".
     * @param string $dateTo    ISO8601 UTC datetime (at most 35 days after $dateFrom, a NAV rule).
     * @param int    $page      Page number, starting at 1.
     * @return array Parsed digest rows.
     * @throws \RuntimeException On HTTP or NAV functional error.
     */
    public function queryInvoiceDigest(string $direction, string $dateFrom, string $dateTo, int $page = 1): array;

    /**
     * Fetches the full data (bank account, lines, per-rate VAT summary) of one invoice.
     *
     * @param string      $invoiceNumber     The invoice number.
     * @param string      $direction         'INBOUND' or 'OUTBOUND'.
     * @param string|null $supplierTaxNumber Required for INBOUND invoices.
     * @return array Keys: supplierBankAccount (string|null), lines (array), vatSummary (list of vat_rate / net_amount_huf / vat_amount_huf),
     *               totals (net / VAT / gross in the invoice currency and in HUF for a simplified invoice, null otherwise; optional).
     *               advanceType (NONE, ADVANCE or FINAL from the advance marking of the lines, null when there is no line to judge by) and
     *               referencedInvoiceNumber (original invoice of a correction or storno, else the advance invoice, or null); both optional.
     * @throws \RuntimeException On HTTP or NAV functional error.
     */
    public function queryInvoiceData(string $invoiceNumber, string $direction, ?string $supplierTaxNumber = null): array;
}
