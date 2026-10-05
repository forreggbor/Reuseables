<?php
/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * Orchestrates NAV invoice synchronization for a single company.
 */

declare(strict_types=1);

namespace NavSync;

use ErrorHandling\ErrorHandler;

/**
 * Runs the two-phase NAV sync for one company.
 *
 * Phase 1 — queryInvoiceDigest, sliced into intervals of at most 30 days (NAV rejects more than 35 days
 * with BAD_QUERY_PARAM_RANGE_EXCEEDED). Each slice asks OUTBOUND then INBOUND; only after both succeeded
 * the company's last_sync_at cursor moves to the slice end, so a failure or an exhausted time budget
 * never loses progress and the next run resumes where this one stopped.
 *
 * Phase 2 — queryInvoiceData for every invoice whose lines are not fetched yet, newest first. This is
 * independent of the cursor: invoices that failed are simply retried on the next run. Invoices whose lines were saved before
 * the invoice type existed (#81) are asked for after those, and only their type is stored.
 *
 * NAV allows about 30 requests a minute (NavApiClient sleeps 2 s per call), so the first full history
 * takes hours; run it from the CLI without a time budget. The web button passes a small budget.
 */
class InvoiceSyncService
{
    /** Slice length in seconds; NAV's hard limit is 35 days. */
    private const SLICE_SECONDS = 30 * 86400;

    /** Seconds subtracted from the stored cursor on incremental runs, to tolerate clock skew. */
    private const OVERLAP_SECONDS = 3600;

    /** NAV returns at most this many digests per page. */
    private const DIGEST_PAGE_SIZE = 100;

    /** Consecutive failed detail requests after which the detail phase gives up (e.g. bad credentials). */
    private const MAX_CONSECUTIVE_DETAIL_FAILURES = 5;

    /** @var callable(object): NavApiClientInterface */
    private $clientFactory;

    /** @var callable(): int */
    private $clock;

    /**
     * @param InvoiceRepository  $invoiceRepo   Invoice data access.
     * @param CompanyRepository  $companyRepo   Company data access.
     * @param array              $software      NAV software descriptor.
     * @param string             $syncFrom      First day (Y-m-d, UTC) to fetch on a company's very first sync.
     * @param callable|null      $clientFactory fn(object $company): NavApiClientInterface; default builds a NavApiClient.
     * @param callable|null      $clock         fn(): int returning the current Unix time; default time().
     * @throws \InvalidArgumentException When $syncFrom is not a Y-m-d date.
     */
    public function __construct(
        private readonly InvoiceRepository $invoiceRepo,
        private readonly CompanyRepository $companyRepo,
        private readonly array $software,
        private readonly string $syncFrom = '2020-01-01',
        ?callable $clientFactory = null,
        ?callable $clock = null
    ) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $syncFrom) || strtotime($syncFrom . ' UTC') === false) {
            throw new \InvalidArgumentException("NavSync: syncFrom must be a Y-m-d date, got '{$syncFrom}'.");
        }
        $this->clientFactory = $clientFactory ?? fn(object $company): NavApiClientInterface => new NavApiClient($company, $this->software);
        $this->clock         = $clock ?? static fn(): int => time();
    }

    /**
     * Runs the sync for the given company and returns a result summary.
     *
     * @param object   $company       Row from CompanyRepository::findById() (with the plain-text sign key).
     * @param int|null $budgetSeconds Stop cleanly after roughly this many seconds; null = unlimited.
     * @return array{fetched:int, errors:string[], complete:bool} "complete" is true only when everything
     *         up to now was fetched without errors and without running out of time.
     */
    public function sync(object $company, ?int $budgetSeconds = null): array
    {
        if (!$this->invoiceRepo->tryLock((int)$company->id)) {
            ErrorHandler::warning('[NavSync] sync refused: another sync of this company is already running', ['company_id' => (int)$company->id]);
            return ['fetched' => 0, 'errors' => ['another sync of this company is already running'], 'complete' => false];
        }
        try {
            return $this->run($company, $budgetSeconds);
        } catch (\Throwable $e) {
            // Anything the steps inside run() did not handle (database failure, programming error): never leaves the caller without a result
            ErrorHandler::logException($e, 'ERROR', ['module' => 'NavSync', 'step' => 'sync', 'company_id' => (int)$company->id]);
            return ['fetched' => 0, 'errors' => ['unexpected error, see the application log'], 'complete' => false];
        } finally {
            $this->invoiceRepo->unlock((int)$company->id);
        }
    }

    /**
     * Checks that NAV accepts the company's credentials with one small, read-only request
     * (INBOUND digests of the last 24 hours). Touches neither the cursor nor the sync log.
     *
     * @param object $company Company row with the plain-text sign key.
     * @return array{ok:bool, message:string} "message" carries NAV's error text when not ok.
     */
    public function probe(object $company): array
    {
        $now = ($this->clock)();
        try {
            ($this->clientFactory)($company)->queryInvoiceDigest('INBOUND', self::iso($now - 86400), self::iso($now), 1);
            return ['ok' => true, 'message' => ''];
        } catch (\Throwable $e) {
            ErrorHandler::logException($e, 'ERROR', ['module' => 'NavSync', 'step' => 'probe', 'company_id' => (int)$company->id]);
            return ['ok' => false, 'message' => $e instanceof \RuntimeException ? $e->getMessage() : 'unexpected error, see the application log'];
        }
    }

    /**
     * The sync proper (lock already held).
     *
     * @param object   $company       Company row.
     * @param int|null $budgetSeconds Time budget, or null.
     * @return array{fetched:int, errors:string[], complete:bool}
     */
    private function run(object $company, ?int $budgetSeconds): array
    {
        $client   = ($this->clientFactory)($company);
        $now      = ($this->clock)();
        $deadline = $budgetSeconds === null ? null : $now + $budgetSeconds;
        $logId    = $this->invoiceRepo->startSyncLog((int)$company->id, 'BOTH');

        $fetched  = 0;
        $errors   = [];
        $complete = true;

        // Phase 1: digests, slice by slice; the cursor moves only after a slice fully succeeded.
        $cursor = $this->startTimestamp($company->last_sync_at);
        while ($cursor < $now) {
            $end = min($cursor + self::SLICE_SECONDS, $now);
            try {
                $sliceCount = 0;
                foreach (['OUTBOUND', 'INBOUND'] as $direction) {
                    $count = $this->fetchDigests($client, (int)$company->id, $direction, $cursor, $end, $deadline);
                    if ($count === null) {
                        $complete = false;
                        break 2;
                    }
                    $sliceCount += $count;
                }
            } catch (\Throwable $e) {
                $errors[]  = "digest {$direction} " . self::iso($cursor) . ' - ' . self::iso($end) . ': ' . $e->getMessage();
                ErrorHandler::logException($e, 'ERROR', [
                    'module' => 'NavSync', 'step' => 'digest', 'company_id' => (int)$company->id,
                    'direction' => $direction, 'from' => self::iso($cursor), 'to' => self::iso($end),
                ]);
                $complete  = false;
                break;
            }
            $fetched += $sliceCount;
            $this->companyRepo->updateLastSyncAt((int)$company->id, date('Y-m-d H:i:s', $end));
            $cursor = $end;
        }

        // Phase 2: invoice lines for everything not fetched yet (newest first).
        $consecutiveFailures = 0;
        foreach ($this->invoiceRepo->findUnfetched((int)$company->id) as $row) {
            if ($this->outOfTime($deadline)) {
                $complete = false;
                break;
            }
            try {
                $detail = $client->queryInvoiceData($row['invoice_number'], $row['direction'], $row['supplier_tax_number']);
                if ((int)$row['detail_fetched'] === 1) {
                    // fetched before the invoice type existed (#81): only the type is missing, the saved lines stay as they are
                    if (($detail['advanceType'] ?? null) === null) {
                        $errors[] = "type #{$row['id']}: the NAV data of {$row['invoice_number']} is not invoice data, so its type cannot be read";
                        ErrorHandler::warning('[NavSync] invoice type cannot be read: the NAV data is not invoice data', [
                            'step' => 'type', 'company_id' => (int)$company->id, 'invoice_id' => (int)$row['id'], 'invoice_number' => $row['invoice_number'],
                        ]);
                    } else {
                        $this->invoiceRepo->saveType((int)$row['id'], $detail['advanceType'], $detail['referencedInvoiceNumber'] ?? null);
                    }
                } else {
                    $this->invoiceRepo->saveDetail((int)$row['id'], $detail['supplierBankAccount'], $detail['lines'], $detail['vatSummary'] ?? [], $detail['totals'] ?? null,
                        $detail['advanceType'] ?? null, $detail['referencedInvoiceNumber'] ?? null);
                }
                $consecutiveFailures = 0;
            } catch (\Throwable $e) {
                $errors[] = "data #{$row['id']}: " . $e->getMessage();
                ErrorHandler::logException($e, 'ERROR', [
                    'module' => 'NavSync', 'step' => 'data', 'company_id' => (int)$company->id,
                    'invoice_id' => (int)$row['id'], 'invoice_number' => $row['invoice_number'], 'direction' => $row['direction'],
                ]);
                if (++$consecutiveFailures >= self::MAX_CONSECUTIVE_DETAIL_FAILURES) {
                    $errors[]  = 'giving up on invoice data after ' . self::MAX_CONSECUTIVE_DETAIL_FAILURES . ' consecutive failures';
                    ErrorHandler::error('[NavSync] giving up on invoice data after consecutive failures', [
                        'step' => 'data', 'company_id' => (int)$company->id, 'failures' => self::MAX_CONSECUTIVE_DETAIL_FAILURES,
                    ]);
                    $complete  = false;
                    break;
                }
            }
        }

        $this->invoiceRepo->finishSyncLog($logId, $fetched, $errors === [] ? null : implode("\n", $errors));

        return ['fetched' => $fetched, 'errors' => $errors, 'complete' => $complete && $errors === []];
    }

    /**
     * Fetches and stores all digest pages of one direction for one slice.
     *
     * @param NavApiClientInterface $client    NAV client.
     * @param int                   $companyId nav_companies.id
     * @param string                $direction 'INBOUND' or 'OUTBOUND'.
     * @param int                   $from      Slice start (Unix time).
     * @param int                   $to        Slice end (Unix time).
     * @param int|null              $deadline  Unix time after which no new request may start, or null.
     * @return int|null Number of digests stored, or null when the time budget ran out first.
     * @throws \RuntimeException On a NAV error.
     */
    private function fetchDigests(NavApiClientInterface $client, int $companyId, string $direction, int $from, int $to, ?int $deadline): ?int
    {
        $count = 0;
        $page  = 1;
        do {
            if ($this->outOfTime($deadline)) {
                return null;
            }
            $digests = $client->queryInvoiceDigest($direction, self::iso($from), self::iso($to), $page);
            foreach ($digests as $digest) {
                $this->invoiceRepo->upsertFromDigest($companyId, $direction, $digest);
                $count++;
            }
            $page++;
        } while (count($digests) === self::DIGEST_PAGE_SIZE);

        return $count;
    }

    /**
     * Where phase 1 starts: the configured history start on the first sync, otherwise the stored cursor
     * minus a small overlap.
     *
     * @param string|null $lastSyncAt Stored cursor (Y-m-d H:i:s, server time) or null.
     * @return int Unix time.
     */
    private function startTimestamp(?string $lastSyncAt): int
    {
        if ($lastSyncAt === null) {
            return (int)strtotime($this->syncFrom . ' UTC');
        }
        return (int)strtotime($lastSyncAt) - self::OVERLAP_SECONDS;
    }

    /**
     * Whether the time budget is used up.
     *
     * @param int|null $deadline Unix time or null (unlimited).
     * @return bool
     */
    private function outOfTime(?int $deadline): bool
    {
        return $deadline !== null && ($this->clock)() >= $deadline;
    }

    /**
     * Formats a Unix time the way NAV expects it.
     *
     * @param int $timestamp Unix time.
     * @return string e.g. "2026-06-26T09:00:00.000Z"
     */
    private static function iso(int $timestamp): string
    {
        return gmdate('Y-m-d\TH:i:s', $timestamp) . '.000Z';
    }
}
