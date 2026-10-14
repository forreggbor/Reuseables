<?php
/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * Database access for cash register log files, receipts and their VAT.
 */

declare(strict_types=1);

namespace NavSync;

use ErrorHandling\ErrorHandler;
use PDO;

/**
 * Stores and reads cash registers, their log files, receipts, lines and per-rate VAT.
 */
class CashRegisterRepository
{
    /**
     * @param PDO $pdo Database connection.
     */
    public function __construct(private readonly PDO $pdo) {}

    /**
     * Inserts or updates a cash register of a company and returns its id.
     *
     * @param int         $companyId           nav_companies.id
     * @param string      $apNumber            AP number, e.g. "A12345678".
     * @param string|null $lastCommunicationAt UTC "Y-m-d H:i:s" or null.
     * @param string|null $lastFileAt          UTC "Y-m-d H:i:s" or null.
     * @param int         $minFile             Oldest file number NAV holds (0 = none).
     * @param int         $maxFile             Newest file number NAV holds (0 = none).
     * @return int nav_cash_registers.id
     */
    public function upsertRegister(int $companyId, string $apNumber, ?string $lastCommunicationAt, ?string $lastFileAt, int $minFile, int $maxFile): int
    {
        $this->pdo->prepare('
            INSERT INTO nav_cash_registers
                (company_id, ap_number, last_communication_at, last_file_at, min_available_file_number, max_available_file_number)
            VALUES (:company_id, :ap, :comm, :file_at, :min_file, :max_file)
            ON DUPLICATE KEY UPDATE
                last_communication_at     = VALUES(last_communication_at),
                last_file_at              = VALUES(last_file_at),
                min_available_file_number = VALUES(min_available_file_number),
                max_available_file_number = VALUES(max_available_file_number)
        ')->execute([
            'company_id' => $companyId, 'ap' => $apNumber, 'comm' => $lastCommunicationAt, 'file_at' => $lastFileAt,
            'min_file' => $minFile, 'max_file' => $maxFile,
        ]);
        $stmt = $this->pdo->prepare('SELECT id FROM nav_cash_registers WHERE company_id = :company_id AND ap_number = :ap');
        $stmt->execute(['company_id' => $companyId, 'ap' => $apNumber]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * All cash registers of a company.
     *
     * @param int $companyId nav_companies.id
     * @return list<array<string,mixed>> Rows of nav_cash_registers ordered by AP number.
     */
    public function findRegisters(int $companyId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM nav_cash_registers WHERE company_id = :company_id ORDER BY ap_number');
        $stmt->execute(['company_id' => $companyId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * One page of a company's receipts, newest first, with the total of the whole selection.
     *
     * Only the company's own registers are reached (the join goes through nav_cash_registers.company_id).
     *
     * @param int         $companyId nav_companies.id
     * @param string|null $apNumber  Only this cash register (AP number), or null for all.
     * @param string|null $dateFrom  First closing day "Y-m-d" (inclusive), or null.
     * @param string|null $dateTo    Last closing day "Y-m-d" (inclusive), or null.
     * @param int         $limit     Page size.
     * @param int         $offset    Rows to skip.
     * @return array{rows:list<array<string,mixed>>, total:int} Rows: id, ap_number, receipt_number, kind, practice, interrupted, closed_at, gross_total.
     */
    public function listReceipts(int $companyId, ?string $apNumber, ?string $dateFrom, ?string $dateTo, int $limit, int $offset): array
    {
        $where  = ['r.company_id = :company_id'];
        $params = ['company_id' => $companyId];
        if ($apNumber !== null) {
            $where[]         = 'r.ap_number = :ap';
            $params['ap']    = $apNumber;
        }
        if ($dateFrom !== null) {
            $where[]             = 'c.closed_at >= :date_from';
            $params['date_from'] = $dateFrom;
        }
        if ($dateTo !== null) {
            $where[]           = 'c.closed_at < DATE_ADD(:date_to, INTERVAL 1 DAY)';
            $params['date_to'] = $dateTo;
        }
        $from = 'FROM nav_cash_receipts c JOIN nav_cash_registers r ON r.id = c.register_id WHERE ' . implode(' AND ', $where);

        $count = $this->pdo->prepare("SELECT COUNT(*) {$from}");
        $count->execute($params);
        $total = (int)$count->fetchColumn();

        $stmt = $this->pdo->prepare("
            SELECT c.id, r.ap_number, c.receipt_number, c.kind, c.practice, c.interrupted, c.closed_at, c.gross_total
            {$from}
            ORDER BY c.closed_at DESC, c.id DESC
            LIMIT :limit OFFSET :offset
        ");
        foreach ($params as $name => $value) {
            $stmt->bindValue(':' . $name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return ['rows' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => $total];
    }

    /**
     * The lines of one receipt, only when the receipt belongs to the company.
     *
     * @param int $receiptId nav_cash_receipts.id
     * @param int $companyId nav_companies.id
     * @return list<array<string,mixed>> Lines in order; empty when the receipt is unknown or another company's.
     */
    public function receiptLines(int $receiptId, int $companyId): array
    {
        $stmt = $this->pdo->prepare('
            SELECT l.line_number, l.description, l.article_no, l.unit_price, l.quantity, l.unit, l.item_type, l.gross_amount, l.vat_collector, l.vat_rate
            FROM nav_cash_receipt_lines l
            JOIN nav_cash_receipts c ON c.id = l.receipt_id
            JOIN nav_cash_registers r ON r.id = c.register_id
            WHERE l.receipt_id = :receipt_id AND r.company_id = :company_id
            ORDER BY l.line_number
        ');
        $stmt->execute(['receipt_id' => $receiptId, 'company_id' => $companyId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * The cash registers of a company with their file counters and the "not fully downloaded" flag.
     *
     * "unsynced" means NAV holds files that are not downloaded yet; files NAV already deleted are not tracked here by decision.
     *
     * @param int $companyId nav_companies.id
     * @return list<array<string,mixed>> Register columns plus files_total, files_error, files_pending and unsynced (bool), ordered by AP number.
     */
    public function registerStatus(int $companyId): array
    {
        $count = static fn(string $where): string => "(SELECT COUNT(*) FROM nav_cash_register_files f WHERE f.register_id = r.id{$where})";
        $stmt  = $this->pdo->prepare('
            SELECT r.*,
                   ' . $count('') . ' AS files_total,
                   ' . $count(" AND f.parse_status = 'ERROR'") . ' AS files_error,
                   ' . $count(" AND f.parse_status = 'PENDING'") . ' AS files_pending
            FROM nav_cash_registers r
            WHERE r.company_id = :company_id
            ORDER BY r.ap_number
        ');
        $stmt->execute(['company_id' => $companyId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['unsynced'] = (int)$row['max_available_file_number'] > 0
                && (int)$row['last_synced_file_number'] < (int)$row['max_available_file_number'];
        }
        unset($row);
        return $rows;
    }

    /**
     * Files of a company that are not parsed (failed or waiting first, then rejected by NAV), newest first within each group, for the admin screen.
     *
     * Deliberately leaves out stored_name and the hash: nothing about the raw file's location leaves the module.
     *
     * @param int $companyId nav_companies.id
     * @param int $limit     Maximum rows.
     * @return list<array<string,mixed>> Columns id, ap_number, file_number, parse_status, parse_error, validation_code, validation_error.
     */
    public function problemFiles(int $companyId, int $limit = 100): array
    {
        $stmt = $this->pdo->prepare("
            SELECT f.id, r.ap_number, f.file_number, f.parse_status, f.parse_error, f.validation_code, f.validation_error
            FROM nav_cash_register_files f
            JOIN nav_cash_registers r ON r.id = f.register_id
            WHERE r.company_id = :company_id AND f.parse_status <> 'PARSED'
            ORDER BY f.parse_status = 'SKIPPED', f.id DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':company_id', $companyId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * A stored log file by id, only when its register belongs to the company.
     *
     * @param int $fileId    nav_cash_register_files.id
     * @param int $companyId nav_companies.id
     * @return array<string,mixed>|null File row plus ap_number and company_id; null when unknown or another company's.
     */
    public function findFileForCompany(int $fileId, int $companyId): ?array
    {
        $stmt = $this->pdo->prepare('
            SELECT f.*, r.ap_number, r.company_id
            FROM nav_cash_register_files f
            JOIN nav_cash_registers r ON r.id = f.register_id
            WHERE f.id = :file_id AND r.company_id = :company_id
        ');
        $stmt->execute(['file_id' => $fileId, 'company_id' => $companyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * Records a downloaded log file; when the file number is already stored its id is returned and nothing changes.
     *
     * @param int         $registerId      nav_cash_registers.id
     * @param int         $fileNumber      NAV file number.
     * @param string      $storedName      Name on disk.
     * @param string      $sha256          Hex SHA-256 of the raw P7B.
     * @param string      $validationCode  'OK', 'WARN' or 'ERROR' (NAV fileValidationResultCode).
     * @param string|null $validationError NAV fileValidationErrorCode.
     * @return int nav_cash_register_files.id A file NAV validated as 'ERROR' is created as SKIPPED (never parsed), others as PENDING.
     * @throws \RuntimeException When no row id results.
     * @throws \PDOException On any database error (invalid register, over-long value, ...).
     */
    public function insertFile(int $registerId, int $fileNumber, string $storedName, string $sha256, string $validationCode, ?string $validationError): int
    {
        $this->pdo->prepare('
            INSERT INTO nav_cash_register_files (register_id, file_number, stored_name, sha256, validation_code, validation_error, parse_status)
            VALUES (:register_id, :file_number, :stored_name, :sha256, :code, :error, :parse_status)
            ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)
        ')->execute([
            'register_id' => $registerId, 'file_number' => $fileNumber, 'stored_name' => $storedName,
            'sha256' => $sha256, 'code' => $validationCode, 'error' => $validationError,
            'parse_status' => $validationCode === 'ERROR' ? 'SKIPPED' : 'PENDING',
        ]);
        $id = (int)$this->pdo->lastInsertId();
        if ($id === 0) {
            throw new \RuntimeException("Cash register file row was not stored (register {$registerId}, file {$fileNumber}).");
        }
        return $id;
    }

    /**
     * File numbers of a register that already have a stored row within an inclusive range.
     *
     * @param int $registerId nav_cash_registers.id
     * @param int $from       First file number of the range.
     * @param int $to         Last file number of the range.
     * @return list<int> Sorted file numbers; empty when the range is empty or nothing is stored in it.
     */
    public function storedFileNumbers(int $registerId, int $from, int $to): array
    {
        if ($to < $from) {
            return [];
        }
        $stmt = $this->pdo->prepare('
            SELECT file_number FROM nav_cash_register_files
            WHERE register_id = :register_id AND file_number BETWEEN :from_n AND :to_n
            ORDER BY file_number
        ');
        $stmt->execute(['register_id' => $registerId, 'from_n' => $from, 'to_n' => $to]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Raises a register's last synced file number to at least the given one.
     *
     * @param int $registerId  nav_cash_registers.id
     * @param int $fileNumber  File number just stored.
     * @return void
     */
    public function markSynced(int $registerId, int $fileNumber): void
    {
        $this->pdo->prepare('UPDATE nav_cash_registers SET last_synced_file_number = GREATEST(last_synced_file_number, :n) WHERE id = :id')
            ->execute(['n' => $fileNumber, 'id' => $registerId]);
    }

    /**
     * Files of a company that still need (re)processing: pending or failed, and not rejected by NAV's own validation.
     *
     * @param int $companyId nav_companies.id
     * @return list<array<string,mixed>> Columns id, register_id, company_id, ap_number, file_number, stored_name.
     */
    public function filesToProcess(int $companyId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT f.id, f.register_id, r.company_id, r.ap_number, f.file_number, f.stored_name
            FROM nav_cash_register_files f
            JOIN nav_cash_registers r ON r.id = f.register_id
            WHERE r.company_id = :company_id AND f.parse_status IN ('PENDING','ERROR') AND f.validation_code <> 'ERROR'
            ORDER BY r.ap_number, f.file_number
        ");
        $stmt->execute(['company_id' => $companyId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Already parsed files that still hold counted receipt lines without a VAT rate (e.g. parsed before the VAT table could be read).
     *
     * @param int $companyId nav_companies.id
     * @return list<array<string,mixed>> Columns id, register_id, company_id, ap_number, file_number, stored_name.
     */
    public function filesWithUnmappedLines(int $companyId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT f.id, f.register_id, r.company_id, r.ap_number, f.file_number, f.stored_name
            FROM nav_cash_register_files f
            JOIN nav_cash_registers r ON r.id = f.register_id
            WHERE r.company_id = :company_id AND f.parse_status = 'PARSED' AND f.validation_code <> 'ERROR'
              AND EXISTS (
                  SELECT 1 FROM nav_cash_receipts x
                  JOIN nav_cash_receipt_lines l ON l.receipt_id = x.id
                  WHERE x.file_id = f.id AND x.practice = 0 AND x.interrupted = 0 AND l.vat_rate IS NULL
              )
            ORDER BY r.ap_number, f.file_number
        ");
        $stmt->execute(['company_id' => $companyId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Marks a file as not to be parsed (NAV validated it as ERROR).
     *
     * @param int $fileId nav_cash_register_files.id
     * @return void
     */
    public function markSkipped(int $fileId): void
    {
        $this->pdo->prepare("UPDATE nav_cash_register_files SET parse_status = 'SKIPPED' WHERE id = :id")->execute(['id' => $fileId]);
    }

    /**
     * Records that parsing a file failed; the file is retried on the next sync run.
     *
     * @param int    $fileId  nav_cash_register_files.id
     * @param string $message Error text (cut to 1000 characters).
     * @return void
     */
    public function markParseError(int $fileId, string $message): void
    {
        $this->pdo->prepare("UPDATE nav_cash_register_files SET parse_status = 'ERROR', parse_error = :msg WHERE id = :id")
            ->execute(['msg' => mb_substr($message, 0, 1000), 'id' => $fileId]);
    }

    /**
     * Replaces everything stored for a file with freshly parsed receipts, in one transaction.
     *
     * Lines get their VAT rate from the collector map; per-rate VAT rows are written only for receipts that count
     * (neither practice mode nor interrupted). Inside a host transaction a savepoint is used instead.
     *
     * @param int                       $fileId     nav_cash_register_files.id
     * @param int                       $registerId nav_cash_registers.id
     * @param list<array<string,mixed>> $receipts   Output of ReceiptLogParser (receipts).
     * @param array<string,string>|null $vatMap     Collector → VAT rate, or null when the log's VAT table was unreadable.
     * @return array{receipts:int, unmapped_lines:int} "unmapped_lines" counts lines of counted receipts without a VAT rate.
     * @throws \InvalidArgumentException When a receipt has a malformed closed_at.
     * @throws \Throwable On any database failure; the previous state is restored first.
     */
    public function saveParsed(int $fileId, int $registerId, array $receipts, ?array $vatMap): array
    {
        // Validate before any write so a bad record fails deterministically, with nothing to roll back.
        foreach ($receipts as $receipt) {
            $closed = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string)$receipt['closed_at'], new \DateTimeZone('UTC'));
            if ($closed === false || $closed->format('Y-m-d H:i:s') !== $receipt['closed_at']) {
                throw new \InvalidArgumentException('Invalid closed_at value for receipt record ' . (string)$receipt['record_no'] . ': ' . (string)$receipt['closed_at']);
            }
        }

        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        } else {
            $this->pdo->exec('SAVEPOINT nav_cash_save_parsed');
        }
        try {
            $this->pdo->prepare('DELETE FROM nav_cash_receipts WHERE file_id = :file_id')->execute(['file_id' => $fileId]);
            $insertReceipt = $this->pdo->prepare('
                INSERT INTO nav_cash_receipts
                    (file_id, register_id, record_no, kind, practice, receipt_number, simplified_invoice_id, closed_at, gross_total, rounding, interrupted)
                VALUES (:file_id, :register_id, :record_no, :kind, :practice, :receipt_number, :iid, :closed_at, :gross_total, :rounding, :interrupted)
            ');
            $insertLine = $this->pdo->prepare('
                INSERT INTO nav_cash_receipt_lines
                    (receipt_id, line_number, description, article_no, unit_price, quantity, unit, item_type, gross_amount, vat_collector, vat_rate)
                VALUES (:receipt_id, :line_number, :description, :article_no, :unit_price, :quantity, :unit, :item_type, :gross_amount, :vat_collector, :vat_rate)
            ');
            $insertVat = $this->pdo->prepare('
                INSERT INTO nav_cash_receipt_vat (receipt_id, vat_rate, gross_huf, net_huf, vat_huf)
                VALUES (:receipt_id, :vat_rate, :gross, :net, :vat)
            ');

            $unmapped = 0;
            foreach ($receipts as $receipt) {
                $insertReceipt->execute([
                    'file_id' => $fileId, 'register_id' => $registerId, 'record_no' => $receipt['record_no'], 'kind' => $receipt['kind'],
                    'practice' => $receipt['practice'] ? 1 : 0, 'receipt_number' => $receipt['receipt_number'],
                    'iid' => $receipt['simplified_invoice_id'], 'closed_at' => $receipt['closed_at'],
                    'gross_total' => $receipt['gross_total'], 'rounding' => $receipt['rounding'], 'interrupted' => $receipt['interrupted'] ? 1 : 0,
                ]);
                $receiptId = (int)$this->pdo->lastInsertId();
                $lines     = CashReceiptVat::applyRates($receipt['lines'], $vatMap);
                foreach ($lines as $line) {
                    $insertLine->execute([
                        'receipt_id' => $receiptId, 'line_number' => $line['line_number'], 'description' => mb_substr($line['description'], 0, 200),
                        'article_no' => $line['article_no'], 'unit_price' => $line['unit_price'], 'quantity' => $line['quantity'],
                        'unit' => $line['unit'], 'item_type' => $line['item_type'], 'gross_amount' => $line['gross_amount'],
                        'vat_collector' => $line['vat_collector'], 'vat_rate' => $line['vat_rate'],
                    ]);
                }
                if ($receipt['practice'] || $receipt['interrupted']) {
                    continue;
                }
                foreach ($lines as $line) {
                    if ($line['vat_rate'] === null) {
                        $unmapped++;
                    }
                }
                foreach (CashReceiptVat::summarize($lines) as $row) {
                    $insertVat->execute(['receipt_id' => $receiptId, 'vat_rate' => $row['vat_rate'], 'gross' => $row['gross'], 'net' => $row['net'], 'vat' => $row['vat']]);
                }
            }
            $this->pdo->prepare("UPDATE nav_cash_register_files SET parse_status = 'PARSED', parse_error = NULL, parsed_at = NOW() WHERE id = :id")
                ->execute(['id' => $fileId]);

            if ($ownsTransaction) {
                $this->pdo->commit();
            } else {
                $this->pdo->exec('RELEASE SAVEPOINT nav_cash_save_parsed');
            }
            return ['receipts' => count($receipts), 'unmapped_lines' => $unmapped];
        } catch (\Throwable $e) {
            // A failing rollback must never replace the original error; it is attached to it instead.
            try {
                if ($ownsTransaction) {
                    if ($this->pdo->inTransaction()) {
                        $this->pdo->rollBack();
                    }
                } else {
                    $this->pdo->exec('ROLLBACK TO SAVEPOINT nav_cash_save_parsed');
                }
            } catch (\Throwable $rollbackError) {
                // Neither text goes into the message (it may reach a client); the original is chained, the rollback failure is logged.
                ErrorHandler::logException($rollbackError, 'ERROR', ['module' => 'NavSync', 'step' => 'cash_register_save_rollback', 'file_id' => $fileId, 'register_id' => $registerId]);
                throw new \RuntimeException('NavSync: saving the cash register file failed and the rollback failed as well', 0, $e);
            }
            throw $e;
        }
    }
}