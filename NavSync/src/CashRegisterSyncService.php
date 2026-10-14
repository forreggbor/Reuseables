<?php
/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * Synchronises cash register log files from the NAV OPF service into the local store.
 */

declare(strict_types=1);

namespace NavSync;

use ErrorHandling\ErrorHandler;

/**
 * Downloads new log files of a company's cash registers, keeps the raw files on disk and stores the receipts.
 *
 * NAV holds files only about 14 days, so the raw file is written to disk and recorded before it is parsed;
 * a parse failure is retried from disk on the next run and never needs a new download.
 */
class CashRegisterSyncService
{
    private const LOCK_SCOPE = 'nav_cr_sync';

    /** What a client sees instead of an internal failure text. */
    public const GENERIC_ERROR = 'unexpected error, see the application log';

    /** Most files requested in one call; NAV's own size limit may return fewer. */
    private const CHUNK = 50;

    private readonly \Closure $clientFactory;

    private readonly \Closure $clock;

    /**
     * @param CashRegisterRepository $repo         Cash register persistence.
     * @param InvoiceRepository      $invoiceRepo  Used for the sync log and the per-company lock.
     * @param array<string,string>   $software     NAV software descriptor.
     * @param string                 $storageRoot  Host "storage" directory (the raw files go below navsync/cash-register/).
     * @param string                 $environment  'production' or 'test'.
     * @param callable|null          $clientFactory fn(object $company): CashRegisterClientInterface; default builds CashRegisterClient.
     * @param callable|null          $clock        fn(): int, current Unix time; default time().
     */
    public function __construct(
        private readonly CashRegisterRepository $repo,
        private readonly InvoiceRepository $invoiceRepo,
        private readonly array $software,
        private readonly string $storageRoot,
        private readonly string $environment = 'production',
        ?callable $clientFactory = null,
        ?callable $clock = null
    ) {
        $this->clientFactory = \Closure::fromCallable($clientFactory ?? fn(object $company): CashRegisterClientInterface => new CashRegisterClient($company, $this->software, $this->environment));
        $this->clock         = \Closure::fromCallable($clock ?? static fn(): int => time());
    }

    /**
     * Runs the sync for one company.
     *
     * @param object   $company       Row from CompanyRepository (plain-text sign key).
     * @param int|null $budgetSeconds Stop cleanly after roughly this many seconds; null = unlimited.
     * @return array{files:int, receipts:int, errors:string[], complete:bool}
     */
    public function sync(object $company, ?int $budgetSeconds = null): array
    {
        $companyId = (int)$company->id;
        $locked    = false;
        try {
            $locked = $this->invoiceRepo->tryLock($companyId, self::LOCK_SCOPE);
            if (!$locked) {
                ErrorHandler::warning('[NavSync] cash register sync refused: another run of this company is already running', ['module' => 'NavSync', 'step' => 'cash_register_lock', 'company_id' => $companyId]);
                return ['files' => 0, 'receipts' => 0, 'errors' => ['another cash register sync of this company is already running'], 'complete' => false];
            }
            return $this->run($company, $budgetSeconds);
        } catch (\Throwable $e) {
            ErrorHandler::logException($e, 'ERROR', ['module' => 'NavSync', 'step' => 'cash_register_sync', 'company_id' => $companyId]);
            return ['files' => 0, 'receipts' => 0, 'errors' => [self::GENERIC_ERROR], 'complete' => false];
        } finally {
            if ($locked) {
                try {
                    $this->invoiceRepo->unlock($companyId, self::LOCK_SCOPE);
                } catch (\Throwable $e) {
                    // The already computed result must not be replaced by a failed unlock.
                    ErrorHandler::logException($e, 'ERROR', ['module' => 'NavSync', 'step' => 'cash_register_unlock', 'company_id' => $companyId]);
                }
            }
        }
    }

    /**
     * The sync itself, inside the lock.
     *
     * @param object   $company       Company row.
     * @param int|null $budgetSeconds Time budget, or null.
     * @return array{files:int, receipts:int, errors:string[], complete:bool}
     */
    private function run(object $company, ?int $budgetSeconds): array
    {
        $companyId = (int)$company->id;
        $deadline  = $budgetSeconds === null ? null : ($this->clock)() + $budgetSeconds;
        $logId     = $this->invoiceRepo->startSyncLog($companyId, 'CASH_REGISTER');
        $files     = 0;
        $receipts  = 0;
        $errors    = [];
        $complete  = true;

        try {
            // Retry what an earlier run stored but could not parse.
            foreach ($this->repo->filesToProcess($companyId) as $pending) {
                if ($deadline !== null && ($this->clock)() >= $deadline) {
                    $complete = false;
                    break;
                }
                $receipts += $this->processFile((int)$pending['id'], (int)$pending['register_id'], $companyId, (string)$pending['ap_number'], (int)$pending['file_number'], null, $errors);
            }

            $client = ($this->clientFactory)($company);
            foreach ($client->queryStatus() as $status) {
                $ap = (string)$status['ap_number'];
                if (!preg_match(CashRegisterClientInterface::AP_NUMBER_PATTERN, $ap)) {
                    $errors[] = "NAV returned an invalid cash register AP number '{$ap}'; skipped";
                    ErrorHandler::error('[NavSync] NAV returned an invalid cash register AP number', ['module' => 'NavSync', 'step' => 'cash_register_status', 'company_id' => $companyId, 'ap_number' => $ap]);
                    $complete = false;
                    continue;
                }
                try {
                    $result = $this->syncRegister($client, $companyId, $status, $deadline);
                } catch (\Throwable $e) {
                    // One register's failure must not stop the others.
                    $errors[] = "cash register {$ap}: " . self::clientMessage($e);
                    ErrorHandler::logException($e, 'ERROR', ['module' => 'NavSync', 'step' => 'cash_register_sync', 'company_id' => $companyId, 'ap_number' => $ap]);
                    $complete = false;
                    continue;
                }
                $files    += $result['files'];
                $receipts += $result['receipts'];
                array_push($errors, ...$result['errors']);
                $complete = $complete && $result['complete'];
                if ($result['out_of_time']) {
                    break;
                }
            }
        } catch (\Throwable $e) {
            $errors[] = self::clientMessage($e);
            $complete = false;
            ErrorHandler::logException($e, 'ERROR', ['module' => 'NavSync', 'step' => 'cash_register_sync', 'company_id' => $companyId]);
        }

        $this->invoiceRepo->finishSyncLog($logId, $files, $errors === [] ? null : implode("\n", $errors));
        return ['files' => $files, 'receipts' => $receipts, 'errors' => $errors, 'complete' => $complete && $errors === []];
    }

    /**
     * Downloads and stores the new files of one cash register.
     *
     * "last synced" only moves over a gap-free run of files (files already stored count as present): a missing
     * attachment or numbers NAV skipped inside an answer stay unsynced, while the request cursor still moves on, so
     * files behind such a hole are fetched before NAV's retention window closes. Numbers that already have a stored
     * row are never requested again (and, when NAV returns them anyway, ignored), so a persistent hole costs one
     * small request per run instead of re-downloading everything behind it.
     *
     * @param CashRegisterClientInterface $client    NAV client.
     * @param int                         $companyId nav_companies.id
     * @param array<string,mixed>         $status    One row of queryStatus() (AP number already validated).
     * @param int|null                    $deadline  Unix time to stop at, or null.
     * @return array{files:int, receipts:int, errors:string[], complete:bool, out_of_time:bool}
     * @throws \Throwable On a failure of the NAV call or the database; the caller isolates it per register.
     */
    private function syncRegister(CashRegisterClientInterface $client, int $companyId, array $status, ?int $deadline): array
    {
        $ap         = (string)$status['ap_number'];
        $minFile    = (int)$status['min_file'];
        $maxFile    = (int)$status['max_file'];
        $registerId = $this->repo->upsertRegister($companyId, $ap, $status['last_communication_at'], $status['last_file_at'], $minFile, $maxFile);
        $out        = ['files' => 0, 'receipts' => 0, 'errors' => [], 'complete' => true, 'out_of_time' => false];
        if ($maxFile < 1) {
            return $out;
        }

        $lastSynced = (int)$this->registerRow($companyId, $registerId)['last_synced_file_number'];
        $next       = $lastSynced + 1;
        if ($next < $minFile) {
            if ($lastSynced === 0 && $this->repo->storedFileNumbers($registerId, 1, $maxFile) === []) {
                // First run of a register that already existed at NAV: no file was ever stored, so nothing is lost.
                ErrorHandler::info('[NavSync] first sync of a cash register starts at the NAV minimum', [
                    'module' => 'NavSync', 'step' => 'cash_register_first_run', 'company_id' => $companyId, 'ap_number' => $ap, 'start' => $minFile,
                ]);
                // Acknowledge the pre-history, so a retry of this run (e.g. after a hole at the minimum) is not a first run again.
                $this->repo->markSynced($registerId, $minFile - 1);
            } else {
                $storedBelow = array_flip($this->repo->storedFileNumbers($registerId, $next, $minFile - 1));
                $lost        = array_values(array_filter(range($next, $minFile - 1), static fn(int $n): bool => !isset($storedBelow[$n])));
                if ($lost !== []) {
                    $list            = self::compactRanges($lost);
                    $out['errors'][] = count($lost) === 1
                        ? "cash register {$ap}: the file {$list} is no longer available at NAV and is lost"
                        : "cash register {$ap}: the files {$list} are no longer available at NAV and are lost";
                    ErrorHandler::error('[NavSync] cash register file gap: files no longer available at NAV', [
                        'module' => 'NavSync', 'step' => 'cash_register_gap', 'company_id' => $companyId, 'ap_number' => $ap, 'lost' => $list,
                    ]);
                }
            }
            $next = $minFile;
        }

        $stored     = array_flip($this->repo->storedFileNumbers($registerId, $next, $maxFile));
        $expected   = $next;   // the file number that keeps the run gap-free
        $contiguous = true;
        // Moves "expected" over numbers that are already stored; they count as present for the gap-free run.
        $skipStored = function () use (&$expected, &$contiguous, $stored, $registerId): void {
            $from = $expected;
            while (isset($stored[$expected])) {
                $expected++;
            }
            if ($expected > $from && $contiguous) {
                $this->repo->markSynced($registerId, $expected - 1);
            }
        };
        $skipStored();

        while (true) {
            while ($next <= $maxFile && isset($stored[$next])) {
                $next++;
            }
            if ($next > $maxFile) {
                break;
            }
            if ($deadline !== null && ($this->clock)() >= $deadline) {
                $out['complete']    = false;
                $out['out_of_time'] = true;
                break;
            }
            $to = min($next + self::CHUNK - 1, $maxFile);
            while (isset($stored[$to])) {
                $to--;   // never ends below $next: $next itself is not stored
            }
            $answer   = $client->queryFiles($ap, $next, $to);
            $advanced = $next;
            foreach ($answer['files'] as $file) {
                $number = (int)$file['file_number'];
                $skipStored();
                if ($number < $expected) {
                    if (!isset($stored[$number])) {
                        $out['errors'][] = "cash register {$ap}: NAV returned the unexpected file number {$number}";
                        ErrorHandler::error('[NavSync] NAV returned an unexpected cash register file number', ['module' => 'NavSync', 'step' => 'cash_register_file', 'company_id' => $companyId, 'ap_number' => $ap, 'file_number' => $number, 'expected' => $expected]);
                    }
                    continue;
                }
                if ($number > $expected) {
                    $contiguous      = false;
                    $missing         = array_values(array_filter(range($expected, $number - 1), static fn(int $n): bool => !isset($stored[$n])));
                    $list            = self::compactRanges($missing);
                    $out['errors'][] = count($missing) === 1
                        ? "cash register {$ap}: the file {$list} is missing from the NAV answer"
                        : "cash register {$ap}: the files {$list} are missing from the NAV answer";
                    ErrorHandler::error('[NavSync] cash register files missing from the NAV answer', ['module' => 'NavSync', 'step' => 'cash_register_file', 'company_id' => $companyId, 'ap_number' => $ap, 'missing' => $list]);
                }
                $expected = $number + 1;
                $advanced = max($advanced, $expected);
                if (isset($stored[$number])) {
                    continue;   // already stored by an earlier run: never fetched, parsed or counted twice
                }
                if ($file['p7b'] === null) {
                    $contiguous      = false;
                    $out['errors'][] = "cash register {$ap} file {$number}: {$file['error']}";
                    ErrorHandler::error('[NavSync] cash register file could not be read from the response', ['module' => 'NavSync', 'step' => 'cash_register_file', 'company_id' => $companyId, 'ap_number' => $ap, 'file_number' => $number, 'reason' => $file['error']]);
                    continue;
                }
                $path   = $this->store($companyId, $ap, $number, $file['p7b']);
                $sha    = hash_file('sha256', $path);
                if ($sha === false) {
                    ErrorHandler::error('[NavSync] cannot hash the stored cash register file', ['module' => 'NavSync', 'step' => 'cash_register_store', 'company_id' => $companyId, 'ap_number' => $ap, 'file_number' => $number, 'path' => $path]);
                    throw new \RuntimeException("NavSync: cannot hash the stored cash register file {$ap} number {$number}");
                }
                $fileId = $this->repo->insertFile($registerId, $number, basename($path), $sha, $file['validation_code'], $file['validation_error']);
                if ($contiguous) {
                    $this->repo->markSynced($registerId, $number);
                }
                $out['files']++;
                if ($file['validation_code'] === 'ERROR') {
                    $out['errors'][] = "cash register {$ap} file {$number}: NAV validation ERROR " . ($file['validation_error'] ?? '');
                    ErrorHandler::error('[NavSync] NAV rejected a cash register file in its validation', ['module' => 'NavSync', 'step' => 'cash_register_validation', 'company_id' => $companyId, 'ap_number' => $ap, 'file_number' => $number, 'code' => $file['validation_error']]);
                    continue;
                }
                if ($file['validation_code'] === 'WARN') {
                    ErrorHandler::warning('[NavSync] NAV warned about a cash register file', ['module' => 'NavSync', 'step' => 'cash_register_validation', 'company_id' => $companyId, 'ap_number' => $ap, 'file_number' => $number, 'code' => $file['validation_error']]);
                }
                $out['receipts'] += $this->processFile($fileId, $registerId, $companyId, $ap, $number, $path, $out['errors']);
            }
            if ($advanced === $next) {
                // Nothing usable came back: stop this register instead of asking for the same range forever.
                $out['complete'] = false;
                if ($answer['files'] === []) {
                    $out['errors'][] = "cash register {$ap}: NAV returned no file for {$next}-{$to}";
                }
                break;
            }
            $next = $advanced;
        }
        $skipStored();
        return $out;
    }

    /**
     * Formats ascending numbers compactly, e.g. [4,7,8,9] becomes "4, 7-9".
     *
     * @param list<int> $numbers Ascending file numbers.
     * @return string
     */
    private static function compactRanges(array $numbers): string
    {
        $parts = [];
        $count = count($numbers);
        for ($i = 0; $i < $count; $i++) {
            $start = $numbers[$i];
            while ($i + 1 < $count && $numbers[$i + 1] === $numbers[$i] + 1) {
                $i++;
            }
            $parts[] = $numbers[$i] === $start ? (string)$start : "{$start}-{$numbers[$i]}";
        }
        return implode(', ', $parts);
    }

    /**
     * Reprocesses one stored file from disk (admin "reparse"); the NAV service is not contacted.
     *
     * Files NAV itself validated as ERROR are never parsed (same rule as the sync), so they are refused here.
     * The per-company cash register lock is taken without waiting, so a reparse never overlaps a sync run.
     * Messages never contain a filesystem path or database text.
     *
     * @param int $fileId    nav_cash_register_files.id
     * @param int $companyId nav_companies.id the file must belong to.
     * @return array{ok:bool, code:string, message:string} code: 'ok', 'file_not_found', 'validation_error', 'sync_running' or 'failed'.
     */
    public function reprocessFile(int $fileId, int $companyId): array
    {
        $file = $this->repo->findFileForCompany($fileId, $companyId);
        if ($file === null) {
            return ['ok' => false, 'code' => 'file_not_found', 'message' => 'file not found'];
        }
        if ((string)$file['validation_code'] === 'ERROR') {
            return ['ok' => false, 'code' => 'validation_error', 'message' => 'file was rejected by NAV validation and is not parsed'];
        }
        $locked = false;
        try {
            $locked = $this->invoiceRepo->tryLock($companyId, self::LOCK_SCOPE);
            if (!$locked) {
                return ['ok' => false, 'code' => 'sync_running', 'message' => 'a cash register sync of this company is running, try again later'];
            }
            $errors = [];
            $count  = $this->processFile((int)$file['id'], (int)$file['register_id'], $companyId, (string)$file['ap_number'], (int)$file['file_number'], null, $errors);
            return $errors === []
                ? ['ok' => true, 'code' => 'ok', 'message' => "{$count} receipts"]
                : ['ok' => false, 'code' => 'failed', 'message' => implode("\n", $errors)];
        } catch (\Throwable $e) {
            ErrorHandler::logException($e, 'ERROR', ['module' => 'NavSync', 'step' => 'cash_register_reparse', 'company_id' => $companyId, 'file_id' => $fileId]);
            return ['ok' => false, 'code' => 'failed', 'message' => self::GENERIC_ERROR];
        } finally {
            if ($locked) {
                try {
                    $this->invoiceRepo->unlock($companyId, self::LOCK_SCOPE);
                } catch (\Throwable $e) {
                    // The already computed result must not be replaced by a failed unlock.
                    ErrorHandler::logException($e, 'ERROR', ['module' => 'NavSync', 'step' => 'cash_register_unlock', 'company_id' => $companyId]);
                }
            }
        }
    }

    /**
     * Reprocesses, from the stored files, every parsed file that still has lines without a VAT rate (admin bulk "reparse").
     *
     * The NAV service is not contacted. The per-company cash register lock is taken without waiting, so this never overlaps
     * a sync run. Files that still end up without a rate (no readable VAT table) stay listed for the next call.
     *
     * @param int      $companyId     nav_companies.id
     * @param int|null $budgetSeconds Stop cleanly after roughly this many seconds; null = unlimited.
     * @return array{ok:bool, code:string, message:string, files:int, complete:bool} code: 'ok', 'sync_running' or 'failed'.
     */
    public function reprocessUnmapped(int $companyId, ?int $budgetSeconds = null): array
    {
        $locked   = false;
        $deadline = $budgetSeconds === null ? null : ($this->clock)() + $budgetSeconds;
        try {
            $locked = $this->invoiceRepo->tryLock($companyId, self::LOCK_SCOPE);
            if (!$locked) {
                return ['ok' => false, 'code' => 'sync_running', 'message' => 'a cash register sync of this company is running, try again later', 'files' => 0, 'complete' => false];
            }
            $errors   = [];
            $files    = 0;
            $complete = true;
            foreach ($this->repo->filesWithUnmappedLines($companyId) as $file) {
                if ($deadline !== null && ($this->clock)() >= $deadline) {
                    $complete = false;
                    break;
                }
                $this->processFile((int)$file['id'], (int)$file['register_id'], $companyId, (string)$file['ap_number'], (int)$file['file_number'], null, $errors);
                $files++;
            }
            return $errors === []
                ? ['ok' => true, 'code' => 'ok', 'message' => "{$files} files", 'files' => $files, 'complete' => $complete]
                : ['ok' => false, 'code' => 'failed', 'message' => implode("\n", $errors), 'files' => $files, 'complete' => $complete];
        } catch (\Throwable $e) {
            ErrorHandler::logException($e, 'ERROR', ['module' => 'NavSync', 'step' => 'cash_register_reparse', 'company_id' => $companyId]);
            return ['ok' => false, 'code' => 'failed', 'message' => self::GENERIC_ERROR, 'files' => 0, 'complete' => false];
        } finally {
            if ($locked) {
                try {
                    $this->invoiceRepo->unlock($companyId, self::LOCK_SCOPE);
                } catch (\Throwable $e) {
                    // The already computed result must not be replaced by a failed unlock.
                    ErrorHandler::logException($e, 'ERROR', ['module' => 'NavSync', 'step' => 'cash_register_unlock', 'company_id' => $companyId]);
                }
            }
        }
    }

    /**
     * The text of a failure that may be shown to a client: the module's own RuntimeException messages, nothing else.
     *
     * Database errors (PDOException is a RuntimeException), TypeErrors and any other throwable could carry SQL, paths or
     * other internals, so they are replaced by a fixed text; the full message goes to the application log by the caller.
     *
     * @param \Throwable $e The failure.
     * @return string
     */
    private static function clientMessage(\Throwable $e): string
    {
        return $e instanceof \RuntimeException && !($e instanceof \PDOException) ? $e->getMessage() : self::GENERIC_ERROR;
    }

    /**
     * Extracts, parses and saves one stored file; any failure marks the file ERROR and is logged and reported.
     *
     * @param int      $fileId     nav_cash_register_files.id
     * @param int      $registerId nav_cash_registers.id
     * @param int      $companyId  nav_companies.id
     * @param string   $apNumber   AP number (for messages).
     * @param int      $fileNumber File number (for messages).
     * @param string|null $path    Path of the stored raw file; null = the standard path of this AP number and file number.
     * @param string[] $errors     Collects error messages (by reference).
     * @return int Number of receipts saved (0 on failure).
     */
    private function processFile(int $fileId, int $registerId, int $companyId, string $apNumber, int $fileNumber, ?string $path, array &$errors): int
    {
        try {
            $path ??= $this->path($companyId, $apNumber, $fileNumber);
            $raw = is_file($path) ? file_get_contents($path) : false;
            if ($raw === false) {
                ErrorHandler::error('[NavSync] the stored cash register file is not readable', ['module' => 'NavSync', 'step' => 'cash_register_read', 'company_id' => $companyId, 'ap_number' => $apNumber, 'file_number' => $fileNumber, 'path' => $path]);
                throw new \RuntimeException("NavSync: the stored cash register file of {$apNumber} number {$fileNumber} is not readable");
            }
            $extracted = (new LogfileExtractor())->extract($raw);
            if ($extracted['recoded']) {
                ErrorHandler::warning('[NavSync] cash register file had invalid UTF-8 and was recoded from Windows-1250', [
                    'module' => 'NavSync', 'step' => 'cash_register_extract', 'company_id' => $companyId, 'ap_number' => $apNumber, 'file_number' => $fileNumber,
                ]);
            }
            $parsed = (new ReceiptLogParser())->parse($extracted['xml']);
            $map    = VatTableParser::parse($parsed['vat_table_text']);
            if ($map === null && $parsed['vat_table_text'] !== '') {
                ErrorHandler::warning('[NavSync] cash register VAT table could not be read, lines stay without a VAT rate', [
                    'module' => 'NavSync', 'step' => 'cash_register_vat_table', 'company_id' => $companyId, 'ap_number' => $apNumber, 'file_number' => $fileNumber,
                ]);
            }
            if ($parsed['vat_table_count'] > 1) {
                // Lines are mapped with the first table only; a file with a changed table needs the per-receipt validity (not implemented).
                ErrorHandler::warning('[NavSync] cash register file holds more than one different VAT table, the first one is applied to all lines', [
                    'module' => 'NavSync', 'step' => 'cash_register_vat_table', 'company_id' => $companyId, 'ap_number' => $apNumber, 'file_number' => $fileNumber,
                    'vat_tables' => $parsed['vat_table_count'],
                ]);
            }
            $saved = $this->repo->saveParsed($fileId, $registerId, $parsed['receipts'], $map);
            if ($saved['unmapped_lines'] > 0) {
                ErrorHandler::warning('[NavSync] cash register lines without a VAT rate', [
                    'module' => 'NavSync', 'step' => 'cash_register_vat_table', 'company_id' => $companyId, 'ap_number' => $apNumber,
                    'file_number' => $fileNumber, 'unmapped_lines' => $saved['unmapped_lines'],
                ]);
            }
            return $saved['receipts'];
        } catch (\Throwable $e) {
            $errors[] = "cash register {$apNumber} file {$fileNumber}: " . self::clientMessage($e);
            ErrorHandler::logException($e, 'ERROR', [
                'module' => 'NavSync', 'step' => 'cash_register_parse', 'company_id' => $companyId, 'ap_number' => $apNumber, 'file_number' => $fileNumber,
                'path' => $path,
            ]);
            try {
                $this->repo->markParseError($fileId, self::clientMessage($e));
            } catch (\Throwable $markError) {
                ErrorHandler::logException($markError, 'ERROR', [
                    'module' => 'NavSync', 'step' => 'cash_register_mark_parse_error', 'company_id' => $companyId, 'ap_number' => $apNumber, 'file_number' => $fileNumber,
                ]);
            }
            return 0;
        }
    }

    /**
     * Writes a raw file below the storage root (temp file, then rename) and returns its final path; an existing file is kept as is.
     *
     * @param int    $companyId  nav_companies.id
     * @param string $apNumber   AP number, validated here.
     * @param int    $fileNumber File number.
     * @param string $bytes      Raw P7B bytes.
     * @return string Absolute path of the stored file.
     * @throws \RuntimeException On an invalid AP number or a write failure.
     */
    private function store(int $companyId, string $apNumber, int $fileNumber, string $bytes): string
    {
        $path = $this->path($companyId, $apNumber, $fileNumber);
        if (is_file($path)) {
            // Never overwrite a stored raw file (e.g. left by a run that crashed before recording it).
            if (hash_file('sha256', $path) !== hash('sha256', $bytes)) {
                ErrorHandler::warning('[NavSync] a stored cash register file differs from the downloaded bytes; the stored copy is kept', [
                    'module' => 'NavSync', 'step' => 'cash_register_store', 'company_id' => $companyId, 'ap_number' => $apNumber, 'file_number' => $fileNumber,
                    'new_sha256' => hash('sha256', $bytes),
                ]);
            }
            return $path;
        }
        $dir  = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            ErrorHandler::error('[NavSync] cannot create the cash register storage directory', ['module' => 'NavSync', 'step' => 'cash_register_store', 'company_id' => $companyId, 'ap_number' => $apNumber, 'file_number' => $fileNumber, 'dir' => $dir]);
            throw new \RuntimeException("NavSync: cannot create the cash register storage directory of {$apNumber} (company {$companyId})");
        }
        $tmp = $path . '.tmp';
        if (file_put_contents($tmp, $bytes) === false || !rename($tmp, $path)) {
            if (is_file($tmp)) {
                unlink($tmp);
            }
            ErrorHandler::error('[NavSync] cannot write the cash register file', ['module' => 'NavSync', 'step' => 'cash_register_store', 'company_id' => $companyId, 'ap_number' => $apNumber, 'file_number' => $fileNumber, 'path' => $path]);
            throw new \RuntimeException("NavSync: cannot write the cash register file {$apNumber} number {$fileNumber}");
        }
        return $path;
    }

    /**
     * Path of a stored file; the name is built from validated parts only.
     *
     * @param int    $companyId  nav_companies.id
     * @param string $apNumber   AP number.
     * @param int    $fileNumber File number.
     * @return string Absolute path.
     * @throws \RuntimeException When the AP number or file number is not valid.
     */
    private function path(int $companyId, string $apNumber, int $fileNumber): string
    {
        if (!preg_match(CashRegisterClientInterface::AP_NUMBER_PATTERN, $apNumber) || $fileNumber < 1) {
            throw new \RuntimeException("NavSync: refusing an invalid cash register file identity ({$apNumber}, {$fileNumber})");
        }
        return rtrim($this->storageRoot, '/') . "/navsync/cash-register/{$companyId}/{$apNumber}/{$apNumber}_{$fileNumber}.p7b";
    }

    /**
     * The register row for an id.
     *
     * @param int $companyId  nav_companies.id
     * @param int $registerId nav_cash_registers.id
     * @return array<string,mixed>
     * @throws \RuntimeException When the register vanished.
     */
    private function registerRow(int $companyId, int $registerId): array
    {
        foreach ($this->repo->findRegisters($companyId) as $row) {
            if ((int)$row['id'] === $registerId) {
                return $row;
            }
        }
        throw new \RuntimeException("NavSync: cash register {$registerId} not found after upsert");
    }
}
