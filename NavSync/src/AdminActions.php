<?php
/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * HTTP action handlers for the NavSync admin interface.
 */

declare(strict_types=1);

namespace NavSync;

use ErrorHandling\ErrorHandler;

/**
 * Handles HTTP requests from the NavSync admin UI.
 *
 * The host app must perform authentication, authorization and CSRF token verification
 * (the POST actions change data) before delegating to any method here.
 * All methods emit JSON and terminate.
 */
class AdminActions
{
    /** Time budget of a manual sync started from the web UI (a web request must not run for hours). */
    private const WEB_SYNC_BUDGET_SECONDS = 20;

    public function __construct(
        private readonly InvoiceRepository  $invoiceRepo,
        private readonly CompanyRepository  $companyRepo,
        private readonly VatReportService   $vatReport,
        private readonly InvoiceSyncService $syncService,
        private readonly CashRegisterRepository $cashRepo,
        private readonly CashRegisterSyncService $cashSync
    ) {}

    /**
     * Returns a paginated invoice list as JSON.
     *
     * GET params: company_id, direction (INBOUND|OUTBOUND), dateFrom, dateTo,
     *             paid (0|1|overdue), search, sort (column key, see InvoiceRepository), dir (asc|desc),
     *             page (default 1), limit (default 50).
     *
     * @return never
     */
    public function listInvoices(): never
    {
        $companyId = (int)($_GET['company_id'] ?? 0);
        $direction = $_GET['direction'] ?? 'INBOUND';
        $page      = max(1, (int)($_GET['page'] ?? 1));
        $limit     = min(100, max(10, (int)($_GET['limit'] ?? 50)));

        if ($companyId <= 0 || !in_array($direction, ['INBOUND', 'OUTBOUND'], true)) {
            $this->json(['error' => 'invalid_params'], 400);
        }

        $result = $this->guarded('list', fn(): array => $this->invoiceRepo->list($companyId, $direction, [
            'dateFrom' => $_GET['dateFrom'] ?? '',
            'dateTo'   => $_GET['dateTo']   ?? '',
            'paid'     => $_GET['paid']      ?? '',
            'search'   => $_GET['search']    ?? '',
            'sort'     => $_GET['sort']      ?? '',
            'dir'      => $_GET['dir']       ?? '',
            'limit'    => $limit,
            'offset'   => ($page - 1) * $limit,
        ]));

        $this->json([
            'total'  => $result['total'],
            'page'   => $page,
            'limit'  => $limit,
            'rows'   => $result['rows'],
            'totals' => $result['totals'],
        ]);
    }

    /**
     * Returns invoice line items as JSON.
     *
     * GET params: invoice_id, company_id (ownership check performed in InvoiceRepository).
     *
     * @return never
     */
    public function getLines(): never
    {
        $invoiceId = (int)($_GET['invoice_id'] ?? 0);
        $companyId = (int)($_GET['company_id'] ?? 0);

        if ($invoiceId <= 0 || $companyId <= 0) {
            $this->json(['error' => 'invalid_params'], 400);
        }

        $this->json($this->guarded('lines', fn(): array => $this->invoiceRepo->findLines($invoiceId, $companyId)));
    }

    /**
     * Marks an invoice as paid or unpaid.
     *
     * POST params: invoice_id, company_id, paid (1|0), paid_at (YYYY-MM-DD, required if paid=1).
     *
     * @return never
     */
    public function markPaid(): never
    {
        $invoiceId = (int)($_POST['invoice_id'] ?? 0);
        $companyId = (int)($_POST['company_id'] ?? 0);
        $paid      = (int)($_POST['paid']       ?? 0) === 1;
        $paidAt    = $_POST['paid_at'] ?? null;

        if ($invoiceId <= 0 || $companyId <= 0) {
            $this->json(['error' => 'invalid_params'], 400);
        }
        if ($paid && !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$paidAt)) {
            $this->json(['error' => 'paid_at_required'], 400);
        }

        $ok = $this->guarded('mark_paid', fn(): bool => $this->invoiceRepo->markPaid($invoiceId, $companyId, $paid, $paidAt));
        $this->json(['success' => $ok]);
    }

    /**
     * Triggers a manual sync for the given company and returns the result.
     *
     * The run is time-boxed (see WEB_SYNC_BUDGET_SECONDS); "complete" in the result tells whether
     * everything up to now was fetched. Long backfills belong to the CLI script.
     *
     * POST params: company_id.
     *
     * @return never
     */
    public function triggerSync(): never
    {
        $companyId = (int)($_POST['company_id'] ?? 0);
        if ($companyId <= 0) {
            $this->json(['error' => 'invalid_params'], 400);
        }

        $company = $this->guarded('sync', fn(): ?object => $this->companyRepo->findById($companyId));
        if ($company === null || !(bool)$company->active) {
            $this->json(['error' => 'company_not_found'], 404);
        }

        $result = $this->syncService->sync($company, self::WEB_SYNC_BUDGET_SECONDS);
        $this->json(['success' => empty($result['errors']), 'complete' => $result['complete'], 'result' => $result]);
    }

    /**
     * Returns monthly VAT report data as JSON.
     *
     * GET params: company_id, month (YYYY-MM).
     *
     * @return never
     */
    public function vatReport(): never
    {
        $companyId = (int)($_GET['company_id'] ?? 0);
        $month     = $_GET['month'] ?? date('Y-m');

        if ($companyId <= 0 || !preg_match('/^\d{4}-\d{2}$/', $month)) {
            $this->json(['error' => 'invalid_params'], 400);
        }

        $this->json($this->guarded('vat_report', fn(): array => $this->vatReport->monthly($companyId, $month)));
    }

    /**
     * Returns one page of cash register receipts as JSON.
     *
     * GET params: company_id, ap (AP number, optional), dateFrom, dateTo (Y-m-d, optional), limit (1..200, default 50), offset (>= 0).
     *
     * @return never
     */
    public function listCashReceipts(): never
    {
        $companyId = self::positiveInt(self::param($_GET, 'company_id'));
        $ap        = self::param($_GET, 'ap');
        $dateFrom  = self::param($_GET, 'dateFrom');
        $dateTo    = self::param($_GET, 'dateTo');
        $rawLimit  = self::param($_GET, 'limit');
        $limit     = ctype_digit($rawLimit) && strlen($rawLimit) <= 9 ? min(200, max(1, (int)$rawLimit)) : 50;
        $offset    = max(0, (int)self::param($_GET, 'offset'));

        if ($companyId === 0
            || ($ap !== '' && !self::isValidApNumber($ap))
            || ($dateFrom !== '' && !self::isValidDate($dateFrom))
            || ($dateTo !== '' && !self::isValidDate($dateTo))) {
            $this->json(['error' => 'bad_request'], 400);
        }

        $result = $this->guarded('cash_list', fn(): array => $this->cashRepo->listReceipts(
            $companyId, $ap === '' ? null : $ap, $dateFrom === '' ? null : $dateFrom, $dateTo === '' ? null : $dateTo, $limit, $offset
        ));
        $this->json(['rows' => $result['rows'], 'total' => $result['total'], 'limit' => $limit, 'offset' => $offset]);
    }

    /**
     * Returns the lines of one cash register receipt as JSON (empty when the receipt is not the company's).
     *
     * GET params: company_id, receipt_id.
     *
     * @return never
     */
    public function getCashReceiptLines(): never
    {
        $companyId = self::positiveInt(self::param($_GET, 'company_id'));
        $receiptId = self::positiveInt(self::param($_GET, 'receipt_id'));
        if ($companyId === 0 || $receiptId === 0) {
            $this->json(['error' => 'bad_request'], 400);
        }

        $this->json(['lines' => $this->guarded('cash_lines', fn(): array => $this->cashRepo->receiptLines($receiptId, $companyId))]);
    }

    /**
     * Returns the cash registers of a company (with file counters), its unparsed files and the newest cash register sync log entry as JSON.
     *
     * GET params: company_id.
     *
     * @return never
     */
    public function cashRegisterStatus(): never
    {
        $companyId = self::positiveInt(self::param($_GET, 'company_id'));
        if ($companyId === 0) {
            $this->json(['error' => 'bad_request'], 400);
        }

        $this->json($this->guarded('cash_status', fn(): array => [
            'registers' => $this->cashRepo->registerStatus($companyId),
            'problem_files' => $this->cashRepo->problemFiles($companyId),
            'last_sync' => $this->invoiceRepo->lastSyncLog($companyId, 'CASH_REGISTER'),
        ]));
    }

    /**
     * Runs a time-boxed cash register sync for a company that has it switched on and returns the result.
     *
     * POST params: company_id.
     *
     * @return never
     */
    public function triggerCashRegisterSync(): never
    {
        $companyId = self::positiveInt(self::param($_POST, 'company_id'));
        if ($companyId === 0) {
            $this->json(['error' => 'bad_request'], 400);
        }

        $company = $this->guarded('cash_sync', fn(): ?object => $this->companyRepo->findById($companyId));
        if ($company === null || !(bool)$company->active) {
            $this->json(['error' => 'company_not_found'], 404);
        }
        if ((int)($company->cash_register_sync ?? 0) !== 1) {
            $this->json(['error' => 'not_enabled'], 400);
        }

        $result = $this->guarded('cash_sync', fn(): array => $this->cashSync->sync($company, self::WEB_SYNC_BUDGET_SECONDS));
        $this->json(['success' => empty($result['errors']), 'complete' => $result['complete'], 'result' => $result]);
    }

    /**
     * Reprocesses one stored cash register file from disk (no NAV contact) and answers whether it succeeded.
     *
     * POST params: company_id, file_id.
     *
     * @return never
     */
    public function reparseCashRegisterFile(): never
    {
        $companyId = self::positiveInt(self::param($_POST, 'company_id'));
        $fileId    = self::positiveInt(self::param($_POST, 'file_id'));
        if ($companyId === 0 || $fileId === 0) {
            $this->json(['error' => 'bad_request'], 400);
        }

        $company = $this->guarded('cash_reparse', fn(): ?object => $this->companyRepo->findById($companyId));
        if ($company === null || !(bool)$company->active) {
            $this->json(['error' => 'company_not_found'], 404);
        }
        if ((int)($company->cash_register_sync ?? 0) !== 1) {
            $this->json(['error' => 'not_enabled'], 400);
        }

        $result = $this->guarded('cash_reparse', fn(): array => $this->cashSync->reprocessFile($fileId, $companyId));
        if ($result['code'] === 'file_not_found') {
            $this->json(['error' => 'file_not_found'], 404);
        }
        if ($result['code'] === 'sync_running') {
            $this->json(['error' => 'sync_running'], 409);
        }
        $this->json(['success' => $result['ok'], 'code' => $result['code'], 'message' => $result['message']]);
    }

    /**
     * Whether a string is a real calendar date in the exact form Y-m-d.
     *
     * @param string $value Candidate.
     * @return bool
     */
    public static function isValidDate(string $value): bool
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})\z/', $value, $m) !== 1) {
            return false;
        }
        return checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
    }

    /**
     * Whether a string is a cash register AP number (one capital letter and eight digits).
     *
     * @param string $value Candidate.
     * @return bool
     */
    public static function isValidApNumber(string $value): bool
    {
        return preg_match(CashRegisterClientInterface::AP_NUMBER_PATTERN, $value) === 1;
    }

    /**
     * Parses a request value as a positive integer.
     *
     * @param string $value Raw request value.
     * @return int The number, or 0 when it is not a positive integer.
     */
    public static function positiveInt(string $value): int
    {
        return ctype_digit($value) && strlen($value) <= 9 ? (int)$value : 0;
    }

    /**
     * Reads one request value as a string; a missing or non-scalar (array) value counts as empty.
     *
     * @param array<string,mixed> $source $_GET or $_POST.
     * @param string              $key    Parameter name.
     * @return string
     */
    private static function param(array $source, string $key): string
    {
        $value = $source[$key] ?? '';
        return is_string($value) ? $value : '';
    }

    /**
     * Runs one data access of an action; on any failure logs it and answers with a generic 500 (never SQL or a stack trace).
     *
     * @param string   $action Action name for the log entry.
     * @param callable $fn     The data access.
     * @return mixed What $fn returns.
     */
    private function guarded(string $action, callable $fn): mixed
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            ErrorHandler::logException($e, 'ERROR', [
                'module' => 'NavSync', 'step' => 'action_' . $action,
                'company_id' => (int)($_POST['company_id'] ?? $_GET['company_id'] ?? 0),
            ]);
            $this->json(['error' => 'internal_error'], 500);
        }
    }

    /**
     * Emits a JSON response and terminates the request.
     *
     * @param mixed $data       Value to JSON-encode.
     * @param int   $statusCode HTTP status code.
     * @return never
     */
    private function json(mixed $data, int $statusCode = 200): never
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }
}
