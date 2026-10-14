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
     * Cash register VAT is returned separately (cash_rows, cash_vat_total) and added to payable_vat; "rows" holds
     * invoices only (INBOUND/OUTBOUND).
     *
     * @param int    $companyId  nav_companies.id
     * @param string $yearMonth  "YYYY-MM", e.g. "2026-06"
     * @return array{
     *     rows: array<array{direction:string, vat_rate:string, net_huf:float, vat_huf:float}>,
     *     cash_rows: list<array{vat_rate:string, net_huf:string, vat_huf:string}>,
     *     outbound_vat_total: float,
     *     inbound_vat_total: float,
     *     cash_vat_total: float,
     *     payable_vat: float,
     *     coverage: array{inbound:array{total:int, fetched:int}, outbound:array{total:int, fetched:int}, total:int, fetched:int, no_vat_data:int, no_completion_date:int, cash:array{receipts:int, unmapped_lines:int}, cash_files_not_processed:int, cash_unsynced:int}
     * }
     */
    public function monthly(int $companyId, string $yearMonth): array
    {
        $dateFrom  = $yearMonth . '-01';
        $monthEnd  = (new \DateTimeImmutable($dateFrom, new \DateTimeZone('UTC')))->modify('last day of this month');
        $dateTo    = $monthEnd->format('Y-m-d');
        $nextStart = $monthEnd->modify('+1 day')->format('Y-m-d');

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

        $cashRows  = $this->cashRows($companyId, $dateFrom, $nextStart);
        $cashTotal = 0.0;
        foreach ($cashRows as $row) {
            $cashTotal += (float)$row['vat_huf'];
        }

        return [
            'rows'                => $rows,
            'cash_rows'           => $cashRows,
            'outbound_vat_total'  => $outboundTotal,
            'inbound_vat_total'   => $inboundTotal,
            'cash_vat_total'      => $cashTotal,
            'payable_vat'         => $outboundTotal + $cashTotal - $inboundTotal,
            'coverage'            => $this->coverage($companyId, $dateFrom, $dateTo, $nextStart),
        ];
    }

    /**
     * Cash register VAT of the month per rate: only receipts that count (not practice mode, not interrupted),
     * selected by closing time (Europe/Budapest wall-clock time, first and last second of the month included).
     *
     * @param int    $companyId nav_companies.id
     * @param string $dateFrom  First day of the month (Y-m-d).
     * @param string $nextStart First day of the following month (Y-m-d), the exclusive end of the month.
     * @return list<array{vat_rate:string, net_huf:string, vat_huf:string}>
     */
    private function cashRows(int $companyId, string $dateFrom, string $nextStart): array
    {
        $stmt = $this->pdo->prepare("
            SELECT v.vat_rate, SUM(v.net_huf) AS net_huf, SUM(v.vat_huf) AS vat_huf
            FROM nav_cash_receipt_vat v
            JOIN nav_cash_receipts r   ON r.id = v.receipt_id
            JOIN nav_cash_registers cr ON cr.id = r.register_id
            WHERE cr.company_id = :company_id AND r.practice = 0 AND r.interrupted = 0
              AND r.closed_at >= :from_at AND r.closed_at < :to_at
            GROUP BY v.vat_rate
            ORDER BY v.vat_rate ASC
        ");
        $stmt->execute([
            'company_id' => $companyId,
            'from_at'    => $dateFrom . ' 00:00:00',
            'to_at'      => $nextStart . ' 00:00:00',
        ]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * How complete a month is: per direction the number of invoices with a completion date in the month
     * and how many of them have their detail downloaded; of those, how many came back without a VAT summary
     * (they add nothing to the amounts); plus the invoices issued in the month that have no completion date
     * at all (they can never appear in the amounts).
     *
     * Cash register part: the counted receipts of the month and how many of their lines have no VAT rate. The
     * cash_files_not_processed (files not PARSED: pending, error or skipped) and cash_unsynced (registers with
     * files at NAV not downloaded yet) counters are register-wide, not month-specific: such a file may hide
     * receipts of any month, so the month is flagged as possibly incomplete until they are resolved. Files NAV
     * deleted before download cannot be shown here; they appear only in the sync log and the application log.
     *
     * @param int    $companyId nav_companies.id
     * @param string $dateFrom  First day of the month (Y-m-d).
     * @param string $dateTo    Last day of the month (Y-m-d).
     * @param string $nextStart First day of the following month (Y-m-d), the exclusive end of the month.
     * @return array{inbound:array{total:int, fetched:int}, outbound:array{total:int, fetched:int}, total:int, fetched:int, no_vat_data:int, no_completion_date:int, cash:array{receipts:int, unmapped_lines:int}, cash_files_not_processed:int, cash_unsynced:int}
     */
    private function coverage(int $companyId, string $dateFrom, string $dateTo, string $nextStart): array
    {
        $coverage = [
            'inbound'            => ['total' => 0, 'fetched' => 0],
            'outbound'           => ['total' => 0, 'fetched' => 0],
            'total'              => 0,
            'fetched'            => 0,
            'no_vat_data'        => 0,
            'no_completion_date' => 0,
            'cash'                    => ['receipts' => 0, 'unmapped_lines' => 0],
            'cash_files_not_processed' => 0,
            'cash_unsynced'           => 0,
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

        $stmt = $this->pdo->prepare('
            SELECT COUNT(*) AS receipts,
                   COALESCE(SUM((SELECT COUNT(*) FROM nav_cash_receipt_lines l WHERE l.receipt_id = r.id AND l.vat_rate IS NULL)), 0) AS unmapped
            FROM nav_cash_receipts r
            JOIN nav_cash_registers cr ON cr.id = r.register_id
            WHERE cr.company_id = :company_id AND r.practice = 0 AND r.interrupted = 0
              AND r.closed_at >= :from_at AND r.closed_at < :to_at
        ');
        $stmt->execute(['company_id' => $companyId, 'from_at' => $dateFrom . ' 00:00:00', 'to_at' => $nextStart . ' 00:00:00']);
        $cash = $stmt->fetch(PDO::FETCH_ASSOC);
        $coverage['cash'] = ['receipts' => (int)$cash['receipts'], 'unmapped_lines' => (int)$cash['unmapped']];

        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) FROM nav_cash_register_files f
            JOIN nav_cash_registers cr ON cr.id = f.register_id
            WHERE cr.company_id = :company_id AND f.parse_status <> 'PARSED'
        ");
        $stmt->execute(['company_id' => $companyId]);
        $coverage['cash_files_not_processed'] = (int)$stmt->fetchColumn();

        $stmt = $this->pdo->prepare('
            SELECT COUNT(*) FROM nav_cash_registers
            WHERE company_id = :company_id AND max_available_file_number > 0 AND last_synced_file_number < max_available_file_number
        ');
        $stmt->execute(['company_id' => $companyId]);
        $coverage['cash_unsynced'] = (int)$stmt->fetchColumn();

        return $coverage;
    }
}
