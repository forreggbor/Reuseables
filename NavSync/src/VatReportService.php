<?php
/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * Aggregates invoice VAT summaries by month and tax rate for NAV VAT reporting.
 */

declare(strict_types=1);

namespace NavSync;

use PDO;

/**
 * Produces monthly VAT summaries from the stored per-rate invoice VAT summaries (nav_invoice_vat).
 *
 * Amounts are always in HUF (as provided by NAV) to match the NAV VAT declaration.
 * Filtering is based on completion_date (teljesítési dátum) of the parent invoice.
 */
class VatReportService
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * Returns VAT totals grouped by direction and vat_rate for the given month.
     *
     * The amounts come from each invoice's VAT summary (net and VAT per rate, as NAV holds them), not from the
     * lines: NAV makes per-line VAT optional, the summary is always there. An invoice contributes only once its
     * detail is downloaded; "coverage" tells how complete the month is (see coverage()).
     *
     * @param int    $companyId  nav_companies.id
     * @param string $yearMonth  "YYYY-MM", e.g. "2026-06"
     * @return array{
     *     rows: array<array{direction:string, vat_rate:string, net_huf:float, vat_huf:float}>,
     *     outbound_vat_total: float,
     *     inbound_vat_total: float,
     *     payable_vat: float,
     *     coverage: array{inbound:array{total:int, fetched:int}, outbound:array{total:int, fetched:int}, total:int, fetched:int, no_vat_data:int, no_completion_date:int}
     * }
     */
    public function monthly(int $companyId, string $yearMonth): array
    {
        $dateFrom = $yearMonth . '-01';
        $dateTo   = date('Y-m-t', strtotime($dateFrom));

        $stmt = $this->pdo->prepare('
            SELECT
                i.direction,
                v.vat_rate,
                SUM(v.net_amount_huf) AS net_huf,
                SUM(v.vat_amount_huf) AS vat_huf
            FROM nav_invoice_vat v
            JOIN nav_invoices i ON i.id = v.invoice_id
            WHERE i.company_id = :company_id
              AND i.completion_date BETWEEN :date_from AND :date_to
            GROUP BY i.direction, v.vat_rate
            ORDER BY i.direction DESC, v.vat_rate ASC
        ');
        $stmt->execute([
            'company_id' => $companyId,
            'date_from'  => $dateFrom,
            'date_to'    => $dateTo,
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $outboundTotal = 0.0;
        $inboundTotal  = 0.0;

        foreach ($rows as $row) {
            if ($row['direction'] === 'OUTBOUND') {
                $outboundTotal += (float)$row['vat_huf'];
            } else {
                $inboundTotal += (float)$row['vat_huf'];
            }
        }

        return [
            'rows'                => $rows,
            'outbound_vat_total'  => $outboundTotal,
            'inbound_vat_total'   => $inboundTotal,
            'payable_vat'         => $outboundTotal - $inboundTotal,
            'coverage'            => $this->coverage($companyId, $dateFrom, $dateTo),
        ];
    }

    /**
     * How complete a month is: per direction the number of invoices with a completion date in the month
     * and how many of them have their detail downloaded; of those, how many came back without a VAT summary
     * (they add nothing to the amounts); plus the invoices issued in the month that have no completion date
     * at all (they can never appear in the amounts).
     *
     * @param int    $companyId nav_companies.id
     * @param string $dateFrom  First day of the month (Y-m-d).
     * @param string $dateTo    Last day of the month (Y-m-d).
     * @return array{inbound:array{total:int, fetched:int}, outbound:array{total:int, fetched:int}, total:int, fetched:int, no_vat_data:int, no_completion_date:int}
     */
    private function coverage(int $companyId, string $dateFrom, string $dateTo): array
    {
        $coverage = [
            'inbound'            => ['total' => 0, 'fetched' => 0],
            'outbound'           => ['total' => 0, 'fetched' => 0],
            'total'              => 0,
            'fetched'            => 0,
            'no_vat_data'        => 0,
            'no_completion_date' => 0,
        ];

        $stmt = $this->pdo->prepare('
            SELECT
                i.direction,
                COUNT(*) AS total,
                SUM(i.detail_fetched = 1) AS fetched,
                SUM(i.detail_fetched = 1 AND NOT EXISTS (SELECT 1 FROM nav_invoice_vat v WHERE v.invoice_id = i.id)) AS no_vat_data
            FROM nav_invoices i
            WHERE i.company_id = :company_id AND i.completion_date BETWEEN :date_from AND :date_to
            GROUP BY i.direction
        ');
        $stmt->execute(['company_id' => $companyId, 'date_from' => $dateFrom, 'date_to' => $dateTo]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key                     = strtolower((string)$row['direction']);
            $coverage[$key]['total']   = (int)$row['total'];
            $coverage[$key]['fetched'] = (int)$row['fetched'];
            $coverage['total']        += (int)$row['total'];
            $coverage['fetched']      += (int)$row['fetched'];
            $coverage['no_vat_data']  += (int)$row['no_vat_data'];
        }

        $stmt = $this->pdo->prepare('
            SELECT COUNT(*) FROM nav_invoices
            WHERE company_id = :company_id AND completion_date IS NULL AND issue_date BETWEEN :date_from AND :date_to
        ');
        $stmt->execute(['company_id' => $companyId, 'date_from' => $dateFrom, 'date_to' => $dateTo]);
        $coverage['no_completion_date'] = (int)$stmt->fetchColumn();

        return $coverage;
    }
}
