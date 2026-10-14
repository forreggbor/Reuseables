<?php
/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * NavSync — NAV Online Számla API v3.0 integration module.
 */

declare(strict_types=1);

namespace NavSync;

use ErrorHandling\ErrorHandler;
use PDO;

/**
 * Entry point for the NavSync module.
 *
 * Instantiate this class with a PDO connection, the AES encryption key
 * (NAV_SIGN_KEY_SECRET from .env), a software descriptor array and, optionally,
 * the first day to import on a company's very first sync (default 2020-01-01),
 * then call getAdminActions() to handle HTTP requests.
 *
 * Software descriptor keys:
 *   softwareId, softwareName, softwareMainVersion, softwareDevName, softwareDevContact
 */
class NavSync
{
    private CompanyRepository  $companyRepo;
    private InvoiceRepository  $invoiceRepo;
    private VatReportService   $vatReportService;
    private CashRegisterRepository $cashRegisterRepo;

    public function __construct(
        private readonly PDO    $pdo,
        private readonly string $encryptionKey,
        private readonly array  $software,
        private readonly string $syncFrom = '2020-01-01',
        private readonly string $cashRegisterEnvironment = 'production'
    ) {
        $this->companyRepo      = new CompanyRepository($pdo, $encryptionKey);
        $this->invoiceRepo      = new InvoiceRepository($pdo);
        $this->vatReportService = new VatReportService($pdo);
        $this->cashRegisterRepo = new CashRegisterRepository($pdo);
    }

    /**
     * Returns the AdminActions handler with the invoice and cash register sync services ready to use.
     *
     * The NavApiClient is built per-sync inside InvoiceSyncService::sync(),
     * so no company ID is needed here.
     *
     * @return AdminActions
     */
    public function getAdminActions(): AdminActions
    {
        $syncService = $this->buildSyncService();

        return new AdminActions(
            $this->invoiceRepo,
            $this->companyRepo,
            $this->vatReportService,
            $syncService,
            $this->cashRegisterRepo,
            $this->buildCashRegisterService()
        );
    }

    /**
     * Returns the CompanyRepository for host-app use (e.g. company management forms).
     *
     * @return CompanyRepository
     */
    public function companies(): CompanyRepository
    {
        return $this->companyRepo;
    }

    /**
     * Tests the stored credentials of a company with one read-only NAV request.
     *
     * @param int $companyId nav_companies.id
     * @return array{ok:bool, message:string} "message" carries NAV's error text when not ok.
     */
    public function testConnection(int $companyId): array
    {
        $company = $this->companyRepo->findById($companyId);
        if ($company === null) {
            return ['ok' => false, 'message' => 'company not found'];
        }
        return $this->buildSyncService()->probe($company);
    }

    /**
     * The newest sync attempt of a company (time, count, error text), or null when it never ran.
     *
     * @param int $companyId nav_companies.id
     * @return array{started_at:string, finished_at:?string, fetched_count:int, error:?string}|null
     */
    public function lastSync(int $companyId): ?array
    {
        return $this->invoiceRepo->lastSyncLog($companyId);
    }

    /**
     * Runs sync for all active companies — call this from a cron job or the CLI script.
     *
     * The first sync of a company walks the whole history in 30-day slices and can take hours
     * (NAV allows ~30 requests a minute); progress is saved per slice, so an interrupted or failed
     * run simply resumes on the next call.
     *
     * @param int|null $budgetSeconds Stop each company cleanly after roughly this many seconds; null = unlimited.
     * @return array<int, array{fetched:int, errors:string[], complete:bool}> Keyed by company ID.
     */
    public function syncAll(?int $budgetSeconds = null): array
    {
        $syncService = $this->buildSyncService();
        $results     = [];
        foreach ($this->companyRepo->findAllActive() as $company) {
            $results[$company->id] = $syncService->sync($company, $budgetSeconds);
        }
        return $results;
    }

    /**
     * Runs the cash register log sync for every active company that has it switched on — call this from a daily cron job.
     *
     * NAV keeps the files only about 14 days, so a missed week of runs loses data; the run logs and reports any gap.
     *
     * @param int|null $budgetSeconds Stop each company cleanly after roughly this many seconds; null = unlimited.
     * @return array<int, array{files:int, receipts:int, errors:string[], complete:bool}> Keyed by company ID.
     */
    public function syncCashRegisters(?int $budgetSeconds = null): array
    {
        $service = $this->buildCashRegisterService();
        $results = [];
        foreach ($this->companyRepo->findAllActive() as $company) {
            if ((int)($company->cash_register_sync ?? 0) !== 1) {
                continue;
            }
            try {
                $results[$company->id] = $service->sync($company, $budgetSeconds);
            } catch (\Throwable $e) {
                // One company's failure must never stop the others.
                ErrorHandler::logException($e, 'ERROR', ['module' => 'NavSync', 'step' => 'cash_register_sync_company', 'company_id' => (int)$company->id]);
                $results[$company->id] = ['files' => 0, 'receipts' => 0, 'errors' => [CashRegisterSyncService::GENERIC_ERROR], 'complete' => false];
            }
        }
        return $results;
    }

    /**
     * Builds the cash register sync service; raw files go to the host's storage directory (two levels above lib/NavSync).
     *
     * @return CashRegisterSyncService
     */
    protected function buildCashRegisterService(): CashRegisterSyncService
    {
        return new CashRegisterSyncService(
            $this->cashRegisterRepo,
            $this->invoiceRepo,
            $this->software,
            dirname(__DIR__, 2) . '/storage',
            $this->cashRegisterEnvironment
        );
    }

    /**
     * Builds an InvoiceSyncService; the NavApiClient is created per sync() call inside the service.
     *
     * @return InvoiceSyncService
     */
    private function buildSyncService(): InvoiceSyncService
    {
        return new InvoiceSyncService(
            $this->invoiceRepo,
            $this->companyRepo,
            $this->software,
            $this->syncFrom
        );
    }
}
