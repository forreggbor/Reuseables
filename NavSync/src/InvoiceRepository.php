<?php
/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * Repository for invoice storage — nav_invoices, nav_invoice_lines, nav_sync_log.
 */

declare(strict_types=1);

namespace NavSync;

use PDO;

/**
 * Provides CRUD operations for NAV invoice data and sync history.
 */
class InvoiceRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * Inserts or updates an invoice row from digest data.
     *
     * Uses INSERT ... ON DUPLICATE KEY UPDATE to handle the UNIQUE constraint on
     * (company_id, direction, invoice_number). detail_fetched is NOT reset on update
     * so existing full-data fetches are preserved. A simplified invoice's digest carries no net or VAT amount
     * (NAV sends none for invoiceCategory SIMPLIFIED): its amounts come from the invoice data (saveDetail), so a
     * re-sync of its digest leaves them alone instead of zeroing them. NAV sends no buyer name for a private person: an empty name never
     * erases one typed in later (#116), a name NAV does send replaces it; the buyer address columns are never written here.
     *
     * @param int    $companyId nav_companies.id
     * @param string $direction 'INBOUND' or 'OUTBOUND'
     * @param array  $data      Keys matching nav_invoices columns (from NavApiClient::parseDigestResponse).
     * @return int Inserted or existing row ID.
     */
    public function upsertFromDigest(int $companyId, string $direction, array $data): int
    {
        $operation = ($data['invoice_operation'] ?? '') ?: null;
        if ($operation !== null && !in_array($operation, InvoiceType::OPERATIONS, true)) {
            throw new \RuntimeException("NavSync: unknown invoiceOperation '{$operation}' on invoice {$data['invoice_number']}");
        }
        $amounts = ($data['invoice_category'] ?? '') === 'SIMPLIFIED' ? '' : '
                net_amount          = VALUES(net_amount),
                vat_amount          = VALUES(vat_amount),
                gross_amount        = VALUES(gross_amount),
                net_amount_huf      = VALUES(net_amount_huf),
                vat_amount_huf      = VALUES(vat_amount_huf),
                gross_amount_huf    = VALUES(gross_amount_huf),';
        $this->pdo->prepare('
            INSERT INTO nav_invoices
                (company_id, direction, invoice_number, issue_date, completion_date, payment_date,
                 supplier_name, supplier_tax_number, customer_name, customer_tax_number,
                 currency, net_amount, vat_amount, gross_amount,
                 net_amount_huf, vat_amount_huf, gross_amount_huf, nav_insert_date, invoice_operation)
            VALUES
                (:company_id, :direction, :invoice_number, :issue_date, :completion_date, :payment_date,
                 :supplier_name, :supplier_tax_number, :customer_name, :customer_tax_number,
                 :currency, :net_amount, :vat_amount, :gross_amount,
                 :net_amount_huf, :vat_amount_huf, :gross_amount_huf, :nav_insert_date, :invoice_operation)
            ON DUPLICATE KEY UPDATE
                invoice_operation   = COALESCE(VALUES(invoice_operation), invoice_operation),
                issue_date          = VALUES(issue_date),
                completion_date     = VALUES(completion_date),
                payment_date        = VALUES(payment_date),
                supplier_name       = VALUES(supplier_name),
                supplier_tax_number = VALUES(supplier_tax_number),
                customer_name       = COALESCE(VALUES(customer_name), customer_name),
                customer_tax_number = VALUES(customer_tax_number),
                currency            = VALUES(currency),' . $amounts . '
                nav_insert_date     = VALUES(nav_insert_date)
        ')->execute([
            'company_id'          => $companyId,
            'direction'           => $direction,
            'invoice_number'      => $data['invoice_number'],
            'issue_date'          => $data['issue_date'],
            'completion_date'     => $data['completion_date'] ?: null,
            'payment_date'        => $data['payment_date'] ?: null,
            'supplier_name'       => $data['supplier_name'],
            'supplier_tax_number' => $data['supplier_tax_number'] ?: null,
            'customer_name'       => $data['customer_name'] ?: null,
            'customer_tax_number' => $data['customer_tax_number'] ?: null,
            'currency'            => $data['currency'] ?: 'HUF',
            'net_amount'          => $data['net_amount'],
            'vat_amount'          => $data['vat_amount'],
            'gross_amount'        => $data['gross_amount'],
            'net_amount_huf'      => $data['net_amount_huf'],
            'vat_amount_huf'      => $data['vat_amount_huf'],
            'gross_amount_huf'    => $data['gross_amount_huf'],
            'nav_insert_date'     => $data['nav_insert_date'] ?: null,
            'invoice_operation'   => $operation,
        ]);

        $stmt = $this->pdo->prepare('
            SELECT id FROM nav_invoices
            WHERE company_id = :company_id AND direction = :direction AND invoice_number = :invoice_number
        ');
        $stmt->execute([
            'company_id'     => $companyId,
            'direction'      => $direction,
            'invoice_number' => $data['invoice_number'],
        ]);

        return (int)$stmt->fetchColumn();
    }

    /**
     * Builds the ORDER BY clause of the invoice list from a whitelisted column key.
     *
     * Only keys of the table below ever reach the SQL; anything else gives the default order (newest issue
     * date first). Empty values (no completion date, no deadline, ...) always sort last, and text columns
     * use the Hungarian collation, which sorts the accented ö/ő/ü/ű as letters of their own after o/u. Ties are broken by the
     * default order so paging stays stable.
     *
     * @param string $direction 'INBOUND' or 'OUTBOUND' (decides which name the "partner" column shows).
     * @param string $sort      Column key: invoice_number, partner, bank_account, issue_date, completion_date,
     *                          payment_date, net, vat, gross, paid.
     * @param string $dir       'asc' or 'desc' (anything else means ascending).
     * @return string SQL fragment for ORDER BY.
     */
    private static function orderBy(string $direction, string $sort, string $dir): string
    {
        $default = 'issue_date DESC, id DESC';
        // key => [column, collation or '', nullable]
        $columns = [
            'invoice_number'  => ['invoice_number', '', false],
            'partner'         => [$direction === 'INBOUND' ? 'supplier_name' : 'customer_name', ' COLLATE utf8mb4_hungarian_ci', true],
            'bank_account'    => ['supplier_bank_account', '', true],
            'issue_date'      => ['issue_date', '', false],
            'completion_date' => ['completion_date', '', true],
            'payment_date'    => ['payment_date', '', true],
            'net'             => ['net_amount_huf', '', false],
            'vat'             => ['vat_amount_huf', '', false],
            'gross'           => ['gross_amount_huf', '', false],
            'paid'            => ['paid', '', false],
        ];
        if (!isset($columns[$sort])) {
            return $default;
        }
        [$column, $collation, $nullable] = $columns[$sort];
        $way   = strtolower($dir) === 'desc' ? 'DESC' : 'ASC';
        $order = ($nullable ? "{$column} IS NULL, " : '') . "{$column}{$collation} {$way}";
        if ($sort === 'paid') {
            $order .= ', payment_date IS NULL, payment_date ASC';   // unpaid: earliest deadline first
        }
        return $order . ($sort === 'issue_date' ? ', id DESC' : ', ' . $default);
    }

    /**
     * Returns the invoices of a company that need a queryInvoiceData call: those whose data was never fetched, and (#81) those
     * fetched before the invoice type existed (advance_type still NULL), which only need their type filled in (see saveType; an
     * invoice without lines is among them, its correction or storno original is still to be read). Never-fetched invoices come first,
     * each group newest first.
     *
     * @param int $companyId nav_companies.id
     * @return array<array{id:int, invoice_number:string, direction:string, supplier_tax_number:string|null, detail_fetched:int}>
     */
    public function findUnfetched(int $companyId): array
    {
        $stmt = $this->pdo->prepare('
            SELECT id, invoice_number, direction, supplier_tax_number, detail_fetched
            FROM nav_invoices
            WHERE company_id = :company_id
              AND (detail_fetched = 0 OR advance_type IS NULL)
            ORDER BY detail_fetched ASC, issue_date DESC
        ');
        $stmt->execute(['company_id' => $companyId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Stores only the type facts read from an invoice's data (advance marking and referenced invoice), for an invoice whose lines
     * were saved before the type existed: lines, amounts, bank account and the fetched flag stay as they are.
     *
     * @param int         $invoiceId               nav_invoices.id
     * @param string      $advanceType             NONE, ADVANCE or FINAL (see InvoiceType::ADVANCE_TYPES).
     * @param string|null $referencedInvoiceNumber Original invoice of a correction or storno, else the advance invoice; or null.
     * @return void
     * @throws \InvalidArgumentException When $advanceType is not one of the three values.
     */
    public function saveType(int $invoiceId, string $advanceType, ?string $referencedInvoiceNumber): void
    {
        if (!in_array($advanceType, InvoiceType::ADVANCE_TYPES, true)) {
            throw new \InvalidArgumentException("NavSync: unknown advance type '{$advanceType}'.");
        }
        $this->pdo->prepare('UPDATE nav_invoices SET advance_type = :advance_type, referenced_invoice_number = :referenced WHERE id = :id')
            ->execute(['advance_type' => $advanceType, 'referenced' => $referencedInvoiceNumber, 'id' => $invoiceId]);
    }

    /**
     * Saves full invoice detail: bank account, marks detail_fetched = 1, replaces line items and the per-rate VAT summary,
     * and for a simplified invoice also its net / VAT / gross amounts (its digest has none).
     *
     * @param int         $invoiceId          nav_invoices.id
     * @param string|null $supplierBankAccount Bank account number or null.
     * @param array       $lines              Line item arrays from NavApiClient::parseDataResponse.
     * @param array       $vatSummary         Per-rate rows (vat_rate, net_amount_huf, vat_amount_huf) from parseDataResponse.
     * @param array|null  $totals             Amounts of a simplified invoice (net_amount, vat_amount, gross_amount and their _huf
     *                                        pairs) from parseDataResponse; null leaves the amounts from the digest untouched.
     * @param string|null $advanceType        NONE, ADVANCE or FINAL from parseDataResponse (#81); null leaves the stored one.
     * @param string|null $referencedInvoiceNumber Referenced invoice from parseDataResponse; written only together with $advanceType.
     * @return void
     * @throws \InvalidArgumentException When $advanceType is not one of the three values.
     */
    public function saveDetail(int $invoiceId, ?string $supplierBankAccount, array $lines, array $vatSummary = [], ?array $totals = null,
                               ?string $advanceType = null, ?string $referencedInvoiceNumber = null): void
    {
        if ($advanceType !== null && !in_array($advanceType, InvoiceType::ADVANCE_TYPES, true)) {
            throw new \InvalidArgumentException("NavSync: unknown advance type '{$advanceType}'.");
        }
        // Own the transaction when there is none; inside a host transaction use a savepoint instead.
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        } else {
            $this->pdo->exec('SAVEPOINT nav_save_detail');
        }
        try {
            $this->pdo->prepare('
                UPDATE nav_invoices
                SET supplier_bank_account = :bank, detail_fetched = 1
                WHERE id = :id
            ')->execute(['bank' => $supplierBankAccount, 'id' => $invoiceId]);

            if ($advanceType !== null) {
                $this->saveType($invoiceId, $advanceType, $referencedInvoiceNumber);
            }

            if ($totals !== null) {
                $this->pdo->prepare('
                    UPDATE nav_invoices
                    SET net_amount = :net, vat_amount = :vat, gross_amount = :gross,
                        net_amount_huf = :net_huf, vat_amount_huf = :vat_huf, gross_amount_huf = :gross_huf
                    WHERE id = :id
                ')->execute([
                    'net'     => $totals['net_amount'],     'vat'     => $totals['vat_amount'],     'gross'     => $totals['gross_amount'],
                    'net_huf' => $totals['net_amount_huf'], 'vat_huf' => $totals['vat_amount_huf'], 'gross_huf' => $totals['gross_amount_huf'],
                    'id'      => $invoiceId,
                ]);
            }

            $this->pdo->prepare('DELETE FROM nav_invoice_lines WHERE invoice_id = :id')
                ->execute(['id' => $invoiceId]);

            if (!empty($lines)) {
                $stmt = $this->pdo->prepare('
                    INSERT INTO nav_invoice_lines
                        (invoice_id, line_number, line_description, nature_indicator,
                         quantity, unit_of_measure, unit_of_measure_own,
                         unit_price, unit_price_huf,
                         net_amount, net_amount_huf,
                         vat_rate, vat_amount, vat_amount_huf,
                         gross_amount, gross_amount_huf)
                    VALUES
                        (:invoice_id, :line_number, :line_description, :nature_indicator,
                         :quantity, :unit_of_measure, :unit_of_measure_own,
                         :unit_price, :unit_price_huf,
                         :net_amount, :net_amount_huf,
                         :vat_rate, :vat_amount, :vat_amount_huf,
                         :gross_amount, :gross_amount_huf)
                ');

                foreach ($lines as $line) {
                    $stmt->execute(array_merge(['invoice_id' => $invoiceId], $line));
                }
            }

            $this->pdo->prepare('DELETE FROM nav_invoice_vat WHERE invoice_id = :id')
                ->execute(['id' => $invoiceId]);
            $vatStmt = $this->pdo->prepare('
                INSERT INTO nav_invoice_vat (invoice_id, vat_rate, net_amount_huf, vat_amount_huf)
                VALUES (:invoice_id, :vat_rate, :net_amount_huf, :vat_amount_huf)
            ');
            foreach ($vatSummary as $row) {
                $vatStmt->execute([
                    'invoice_id'     => $invoiceId,
                    'vat_rate'       => $row['vat_rate'],
                    'net_amount_huf' => $row['net_amount_huf'],
                    'vat_amount_huf' => $row['vat_amount_huf'],
                ]);
            }

            if ($ownsTransaction) {
                $this->pdo->commit();
            } else {
                $this->pdo->exec('RELEASE SAVEPOINT nav_save_detail');
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction) {
                $this->pdo->rollBack();
            } else {
                $this->pdo->exec('ROLLBACK TO SAVEPOINT nav_save_detail');
            }
            throw $e;
        }
    }

    /**
     * Marks an invoice as paid (or unpaid).
     *
     * @param int         $invoiceId  nav_invoices.id
     * @param int         $companyId  Ownership check.
     * @param bool        $paid       True = paid, false = unpaid.
     * @param string|null $paidAt     Payment date (YYYY-MM-DD), required when paid = true.
     * @return bool True if a row was updated.
     */
    public function markPaid(int $invoiceId, int $companyId, bool $paid, ?string $paidAt): bool
    {
        $stmt = $this->pdo->prepare('
            UPDATE nav_invoices
            SET paid = :paid, paid_at = :paid_at
            WHERE id = :id AND company_id = :company_id
        ');
        $stmt->execute([
            'paid'       => (int)$paid,
            'paid_at'    => $paid ? $paidAt : null,
            'id'         => $invoiceId,
            'company_id' => $companyId,
        ]);
        return $stmt->rowCount() >= 1;
    }

    /**
     * Returns a paginated, filtered invoice list for the given company.
     *
     * @param int    $companyId  nav_companies.id
     * @param string $direction  'INBOUND' or 'OUTBOUND'
     * @param array  $filters    Optional: dateFrom, dateTo (completion_date), paid (0|1|'overdue'), search (partner name,
     *                           invoice number or the description of any of its lines), limit, offset.
     * @return array{total:int, rows:list<object>, totals:list<array{currency:string, invoices:int, net:string, vat:string, gross:string, outstanding:string}>}
     *         Every row also carries "invoice_kind" (InvoiceType::of, null while unknown), "stornoed_by" (the number of the storno
     *         invoice that cancels it, or null) and "corrected_by" (the number of the correcting invoice, or null).
     *         "totals": per currency (HUF first) the sums over every invoice the filters select, not only the page: the
     *         original-currency net, VAT and gross amounts and the outstanding amount (gross of the unpaid invoices).
     */
    public function list(int $companyId, string $direction, array $filters = []): array
    {
        $where  = ['company_id = :company_id', 'direction = :direction'];
        $params = ['company_id' => $companyId, 'direction' => $direction];

        if (!empty($filters['dateFrom'])) {
            $where[]              = 'completion_date >= :date_from';
            $params['date_from']  = $filters['dateFrom'];
        }
        if (!empty($filters['dateTo'])) {
            $where[]             = 'completion_date <= :date_to';
            $params['date_to']   = $filters['dateTo'];
        }
        if (isset($filters['paid']) && $filters['paid'] !== '') {
            if ($filters['paid'] === 'overdue') {
                $where[] = 'paid = 0 AND payment_date < CURDATE()';
            } else {
                $where[]        = 'paid = :paid';
                $params['paid'] = (int)$filters['paid'];
            }
        }
        if (!empty($filters['search'])) {
            $where[]            = '(supplier_name LIKE :search OR customer_name LIKE :search2 OR invoice_number LIKE :search3
                                    OR EXISTS (SELECT 1 FROM nav_invoice_lines l WHERE l.invoice_id = nav_invoices.id AND l.line_description LIKE :search4))';
            $params['search']   = '%' . $filters['search'] . '%';
            $params['search2']  = '%' . $filters['search'] . '%';
            $params['search3']  = '%' . $filters['search'] . '%';
            $params['search4']  = '%' . $filters['search'] . '%';
        }

        $whereClause = implode(' AND ', $where);
        $countStmt   = $this->pdo->prepare("SELECT COUNT(*) FROM nav_invoices WHERE {$whereClause}");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $totalsStmt = $this->pdo->prepare("
            SELECT currency, COUNT(*) AS invoices, COALESCE(SUM(net_amount), 0) AS net, COALESCE(SUM(vat_amount), 0) AS vat,
                   COALESCE(SUM(gross_amount), 0) AS gross, COALESCE(SUM(CASE WHEN paid = 0 THEN gross_amount ELSE 0 END), 0) AS outstanding
            FROM nav_invoices
            WHERE {$whereClause}
            GROUP BY currency
            ORDER BY currency = 'HUF' DESC, currency
        ");
        $totalsStmt->execute($params);
        $totals = array_map(static fn(array $r): array => ['currency' => (string)$r['currency'], 'invoices' => (int)$r['invoices'], 'net' => (string)$r['net'],
            'vat' => (string)$r['vat'], 'gross' => (string)$r['gross'], 'outstanding' => (string)$r['outstanding']], $totalsStmt->fetchAll(PDO::FETCH_ASSOC));

        $limit   = (int)($filters['limit']  ?? 50);
        $offset  = (int)($filters['offset'] ?? 0);
        $orderBy = self::orderBy($direction, (string)($filters['sort'] ?? ''), (string)($filters['dir'] ?? ''));

        // stornoed_by / corrected_by: the number of the storno or correcting (MODIFY) invoice that names this one as its original (the number
        // is unique per company and direction; the first one when there are several), null when none does
        $rowStmt = $this->pdo->prepare("
            SELECT nav_invoices.*,
                   (SELECT s.invoice_number FROM nav_invoices s
                    WHERE s.company_id = nav_invoices.company_id AND s.direction = nav_invoices.direction
                      AND s.invoice_operation = 'STORNO' AND s.referenced_invoice_number = nav_invoices.invoice_number
                    ORDER BY s.issue_date, s.id LIMIT 1) AS stornoed_by,
                   (SELECT m.invoice_number FROM nav_invoices m
                    WHERE m.company_id = nav_invoices.company_id AND m.direction = nav_invoices.direction
                      AND m.invoice_operation = 'MODIFY' AND m.referenced_invoice_number = nav_invoices.invoice_number
                    ORDER BY m.issue_date, m.id LIMIT 1) AS corrected_by
            FROM nav_invoices
            WHERE {$whereClause}
            ORDER BY {$orderBy}
            LIMIT :limit OFFSET :offset
        ");
        foreach ($params as $k => $v) {
            $rowStmt->bindValue(':' . $k, $v);
        }
        $rowStmt->bindValue(':limit',  $limit,  PDO::PARAM_INT);
        $rowStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $rowStmt->execute();

        $rows = $rowStmt->fetchAll(PDO::FETCH_OBJ);
        foreach ($rows as $row) {
            $row->invoice_kind = InvoiceType::of($row->invoice_operation, $row->advance_type);
        }

        return ['total' => $total, 'rows' => $rows, 'totals' => $totals];
    }

    /**
     * Returns all line items for a given invoice, scoped to a specific company.
     *
     * @param int $invoiceId  nav_invoices.id
     * @param int $companyId  Ownership check — must own this invoice.
     * @return array Line item rows ordered by line_number.
     */
    public function findLines(int $invoiceId, int $companyId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT l.* FROM nav_invoice_lines l
             JOIN nav_invoices i ON i.id = l.invoice_id
             WHERE l.invoice_id = :id AND i.company_id = :company_id
             ORDER BY l.line_number ASC'
        );
        $stmt->execute(['id' => $invoiceId, 'company_id' => $companyId]);
        return $stmt->fetchAll(PDO::FETCH_OBJ);
    }

    /**
     * The newest sync log entry of a company, or null when it never ran.
     *
     * @param int $companyId nav_companies.id
     * @return array{started_at:string, finished_at:?string, fetched_count:int, error:?string}|null
     */
    public function lastSyncLog(int $companyId): ?array
    {
        $stmt = $this->pdo->prepare('
            SELECT started_at, finished_at, fetched_count, error
            FROM nav_sync_log WHERE company_id = :company_id ORDER BY id DESC LIMIT 1
        ');
        $stmt->execute(['company_id' => $companyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * Tries to take the per-company sync lock (a database named lock, held by this connection).
     *
     * @param int $companyId nav_companies.id
     * @return bool True when the lock was acquired; false when another sync of the company is running.
     */
    public function tryLock(int $companyId): bool
    {
        $stmt = $this->pdo->prepare('SELECT GET_LOCK(:name, 0)');
        $stmt->execute(['name' => self::lockName($companyId)]);
        return (int)$stmt->fetchColumn() === 1;
    }

    /**
     * Releases the per-company sync lock taken by tryLock().
     *
     * @param int $companyId nav_companies.id
     * @return void
     */
    public function unlock(int $companyId): void
    {
        $stmt = $this->pdo->prepare('SELECT RELEASE_LOCK(:name)');
        $stmt->execute(['name' => self::lockName($companyId)]);
        $stmt->fetchColumn();
    }

    /**
     * Name of the sync lock of a company.
     *
     * @param int $companyId nav_companies.id
     * @return string
     */
    private static function lockName(int $companyId): string
    {
        return 'nav_sync_' . $companyId;
    }

    /**
     * Inserts a new sync log entry and returns its ID.
     *
     * @param int    $companyId nav_companies.id
     * @param string $direction 'INBOUND', 'OUTBOUND', or 'BOTH'
     * @return int Log entry ID.
     */
    public function startSyncLog(int $companyId, string $direction): int
    {
        $this->pdo->prepare('
            INSERT INTO nav_sync_log (company_id, started_at, direction)
            VALUES (:company_id, NOW(), :direction)
        ')->execute(['company_id' => $companyId, 'direction' => $direction]);
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Closes a sync log entry with result data.
     *
     * @param int         $logId        nav_sync_log.id
     * @param int         $fetchedCount Number of invoices processed.
     * @param string|null $error        Error message, or null on success.
     * @return void
     */
    public function finishSyncLog(int $logId, int $fetchedCount, ?string $error = null): void
    {
        $this->pdo->prepare('
            UPDATE nav_sync_log
            SET finished_at = NOW(), fetched_count = :count, error = :error
            WHERE id = :id
        ')->execute(['count' => $fetchedCount, 'error' => $error, 'id' => $logId]);
    }
}
