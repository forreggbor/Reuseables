<?php
/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * Admin interface facade for the ActivityLogs module.
 */

declare(strict_types=1);

namespace ActivityLogs;

use ActivityLogs\Contracts\AuthAdapterInterface;
use ActivityLogs\Contracts\TranslatorInterface;
use PDO;

/**
 * ActivityLogsAdmin - admin UI facade for the ActivityLogs module
 *
 * Single entry point the host instantiates. Handles rendering the admin view,
 * dispatching actions, providing query helpers to AdminActions, and validating
 * all configuration at construction time so failures surface at boot, not render.
 *
 * The host must call ActivityLogger::init() before or alongside instantiation;
 * this facade re-calls it (merge-safe) to guarantee the engine is usable and
 * pinned to the correct table name before every render/handle cycle.
 *
 * @package ActivityLogs
 * @version 1.2.2
 */
class ActivityLogsAdmin
{
    private string $tableName;
    private string $baseUrl;
    private int $pageSize;
    private int $printMaxRows;
    private string $assetBaseUrl;
    private string $timezone;
    private string $locale;
    private \DateTimeZone $tz;

    /** @var callable(string, string, array<mixed>): void */
    private mixed $logger;

    private AdminActions $adminActions;

    /**
     * @param PDO                       $pdo        Database connection (same one used for logging)
     * @param array<string,mixed>       $config     Configuration options (see README for keys)
     * @param AuthAdapterInterface      $auth       Host authentication adapter
     * @param TranslatorInterface|null  $translator Optional host translator; falls back to module locale
     * @throws \InvalidArgumentException If config is invalid
     */
    public function __construct(
        private readonly PDO $pdo,
        array $config,
        private readonly AuthAdapterInterface $auth,
        private readonly ?TranslatorInterface $translator = null,
    ) {
        $this->tableName    = $this->validateTableName($config['table_name'] ?? 'activity_logs');
        $this->pageSize     = $this->validatePageSize($config['page_size'] ?? 50);
        $this->printMaxRows = $this->validatePageSize($config['print_max_rows'] ?? 5000);
        $this->timezone     = $this->validateTimezone($config['timezone'] ?? date_default_timezone_get());
        $this->locale       = $this->validateLocale($config['locale'] ?? 'en_US');
        $this->tz           = new \DateTimeZone($this->timezone);
        $this->baseUrl      = $config['base_url'] ?? '';
        $this->assetBaseUrl = isset($config['asset_base_url']) && is_string($config['asset_base_url'])
            ? $config['asset_base_url']
            : '';
        $this->validateBaseUrl($this->baseUrl);

        $this->logger = $this->buildLogger($config);

        // Guarantee the engine is initialized with this facade's pdo + table name.
        // array_merge-safe: preserves any prior encryption_key set by the host.
        ActivityLogger::init($this->pdo, ['table_name' => $this->tableName]);

        $this->adminActions = new AdminActions(
            facade:     $this,
            auth:       $this->auth,
            resolvers:  new EntityResolverRegistry($this->logger),
            colors:     new ActionColorResolver(),
            translator: $this->translator,
        );
    }

    // =========================================================================
    // Public host API
    // =========================================================================

    /**
     * Render the admin index page and return a body fragment for embedding in the host admin layout.
     *
     * Convenience wrapper around handle('index', $request)['body'].
     *
     * @param array<string,mixed> $request Typically $_GET
     * @return string Rendered HTML
     */
    public function render(array $request): string
    {
        return (string)$this->handle('index', $request)['body'];
    }

    /**
     * Dispatch an action and return a uniform host-emittable envelope.
     *
     * Returned array shape:
     *   ['status' => int, 'content_type' => string, 'filename' => ?string, 'body' => string|\Generator]
     *
     * The host emits the envelope:
     *   header('Content-Type: ' . $envelope['content_type']);
     *   if ($envelope['filename']) { header('Content-Disposition: attachment; filename="...'"); }
     *   if ($envelope['body'] instanceof \Generator) { foreach ($envelope['body'] as $c) { echo $c; flush(); } }
     *   else { echo $envelope['body']; }
     *
     * @param string              $action  Action name: 'index', 'details', 'exportCsv', 'printView'
     * @param array<string,mixed> $request Request parameters (typically $_GET)
     * @return array<string,mixed> Host-emittable envelope
     */
    public function handle(string $action, array $request): array
    {
        // Re-assert engine config before any query so a second facade instance
        // (or any code that re-init'd the engine) does not cross-contaminate.
        ActivityLogger::init($this->pdo, ['table_name' => $this->tableName]);

        if (!$this->auth->isAuthorized()) {
            return $this->makeEnvelope(403, 'text/html; charset=UTF-8', null, $this->renderError('Access denied.'));
        }

        try {
            $inner = $this->adminActions->dispatch($action, $request);
            return $this->resolveEnvelope($inner);
        } catch (\Throwable $e) {
            ($this->logger)(
                'error',
                'ActivityLogsAdmin: unhandled exception in action "' . $action . '"',
                ['error' => $e->getMessage(), 'class' => $e::class]
            );
            return $this->makeEnvelope(500, 'text/html; charset=UTF-8', null, $this->renderError('A server error occurred.'));
        }
    }

    /**
     * Return the entity resolver registry so the host can register resolvers.
     *
     * @return EntityResolverRegistry
     */
    public function resolvers(): EntityResolverRegistry
    {
        return $this->adminActions->getResolverRegistry();
    }

    // =========================================================================
    // Package-internal API (used by AdminActions, not part of the host contract)
    // =========================================================================

    /**
     * Run one filtered aggregate stats query covering all six stat-card values.
     *
     * "Today" and "this week" boundaries are computed in PHP in the configured
     * timezone so the result never depends on the MySQL server TZ.
     *
     * @param array<string,mixed> $filters Same filter keys as ActivityLogger::getAll()
     * @return array{total:int,today:int,this_week:int,unique_users:int,unique_actions:int,unique_entity_types:int}
     */
    public function computeFilteredStats(array $filters): array
    {
        $now        = new \DateTimeImmutable('now', $this->tz);
        $todayStart = $now->setTime(0, 0, 0)->format('Y-m-d H:i:s');
        $weekStart  = $now->modify('-7 days')->setTime(0, 0, 0)->format('Y-m-d H:i:s');

        ['sql' => $where, 'params' => $params] = $this->buildFilterClause($filters);

        $sql = "SELECT
                    COUNT(*) AS total,
                    COUNT(DISTINCT user_id) AS unique_users,
                    COUNT(DISTINCT action) AS unique_actions,
                    COUNT(DISTINCT entity_type) AS unique_entity_types,
                    SUM(CASE WHEN created_at >= :today_start THEN 1 ELSE 0 END) AS today,
                    SUM(CASE WHEN created_at >= :week_start THEN 1 ELSE 0 END) AS this_week
                FROM {$this->tableName}
                WHERE 1=1{$where}";

        $params['today_start'] = $todayStart;
        $params['week_start']  = $weekStart;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return [
            'total'               => (int)($row['total'] ?? 0),
            'today'               => (int)($row['today'] ?? 0),
            'this_week'           => (int)($row['this_week'] ?? 0),
            'unique_users'        => (int)($row['unique_users'] ?? 0),
            'unique_actions'      => (int)($row['unique_actions'] ?? 0),
            'unique_entity_types' => (int)($row['unique_entity_types'] ?? 0),
        ];
    }

    /**
     * Return distinct non-null user IDs from the log table for the user filter dropdown.
     *
     * @return array<int> Sorted list of user IDs
     */
    public function getDistinctUserIds(): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT DISTINCT user_id FROM {$this->tableName} WHERE user_id IS NOT NULL ORDER BY user_id ASC"
        );
        $stmt->execute();
        return array_map('intval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'user_id'));
    }

    /**
     * Format a stored created_at wall-clock string for display in the configured timezone.
     *
     * @param string $createdAt Raw value from the database (Y-m-d H:i:s in app TZ)
     * @return string Formatted datetime string
     */
    public function formatTs(string $createdAt): string
    {
        try {
            return (new \DateTimeImmutable($createdAt, $this->tz))->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return $createdAt;
        }
    }

    /**
     * Return the configured timezone string.
     *
     * @return string e.g. 'Europe/Budapest'
     */
    public function getTimezone(): string
    {
        return $this->timezone;
    }

    /**
     * Return the configured page size.
     *
     * @return int
     */
    public function getPageSize(): int
    {
        return $this->pageSize;
    }

    /**
     * Return the configured base URL.
     *
     * @return string
     */
    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * Return the configured locale code.
     *
     * @return string
     */
    public function getLocale(): string
    {
        return $this->locale;
    }

    /**
     * Return the configured print view row cap.
     *
     * @return int
     */
    public function getPrintMaxRows(): int
    {
        return $this->printMaxRows;
    }

    /**
     * Return the configured asset base URL for the print view.
     *
     * @return string Empty string when not configured.
     */
    public function getAssetBaseUrl(): string
    {
        return $this->assetBaseUrl;
    }

    /**
     * Log a message via the configured logger callable.
     *
     * @param string       $level  PSR-3-ish level: 'error', 'warning', 'info', 'debug'
     * @param string       $msg    Log message
     * @param array<mixed> $ctx    Context data
     * @return void
     */
    public function log(string $level, string $msg, array $ctx = []): void
    {
        ($this->logger)($level, $msg, $ctx);
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Build a SQL WHERE clause fragment and bound parameters from filter options.
     *
     * Mirrors the engine's internal WHERE builder so facade-side queries (stats,
     * distinct user IDs) apply identical filter logic to ActivityLogger::getAll().
     *
     * @param array<string,mixed> $filters Filter options
     * @return array{sql:string,params:array<string,mixed>}
     */
    private function buildFilterClause(array $filters): array
    {
        $sql    = '';
        $params = [];

        if (!empty($filters['user_id'])) {
            $sql .= ' AND user_id = :user_id';
            $params['user_id'] = (int)$filters['user_id'];
        }
        if (!empty($filters['action'])) {
            $sql .= ' AND action = :action';
            $params['action'] = $filters['action'];
        }
        if (!empty($filters['entity_type'])) {
            $sql .= ' AND entity_type = :entity_type';
            $params['entity_type'] = $filters['entity_type'];
        }
        if (!empty($filters['entity_id'])) {
            $sql .= ' AND entity_id = :entity_id';
            $params['entity_id'] = (string)$filters['entity_id'];
        }
        if (!empty($filters['source'])) {
            $sql .= ' AND source = :source';
            $params['source'] = $filters['source'];
        }
        if (!empty($filters['date_from'])) {
            $sql .= ' AND created_at >= :date_from';
            $params['date_from'] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $sql .= ' AND created_at <= :date_to';
            $params['date_to'] = $filters['date_to'] . ' 23:59:59';
        }
        if (!empty($filters['search'])) {
            $sql .= ' AND (action LIKE :search1 OR entity_type LIKE :search2)';
            $params['search1'] = '%' . $filters['search'] . '%';
            $params['search2'] = '%' . $filters['search'] . '%';
        }

        return ['sql' => $sql, 'params' => $params];
    }

    /**
     * Render a view template using ob_start/extract/include (copied from PatchModule).
     *
     * @param string              $viewName Relative path under views/ without .php
     * @param array<string,mixed> $data     Variables extracted into view scope
     * @return string Rendered HTML
     */
    public function renderView(string $viewName, array $data = []): string
    {
        $viewPath = __DIR__ . '/views/' . $viewName . '.php';
        if (!file_exists($viewPath)) {
            return '';
        }
        extract($data);
        ob_start();
        try {
            include $viewPath;
            return ob_get_clean() ?: '';
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
    }

    /**
     * Resolve an AdminActions internal envelope into the uniform host-emittable envelope.
     *
     * @param array<string,mixed> $inner Internal envelope from AdminActions
     * @return array<string,mixed> Host envelope
     */
    private function resolveEnvelope(array $inner): array
    {
        if (isset($inner['view'])) {
            $html = $this->renderView($inner['view'], $inner['data'] ?? []);
            return $this->makeEnvelope(200, 'text/html; charset=UTF-8', null, $html);
        }

        if (isset($inner['download'])) {
            $dl = $inner['download'];
            return $this->makeEnvelope(200, $dl['content_type'], $dl['filename'], $dl['body']);
        }

        $status = $inner['status'] ?? 200;
        $json   = json_encode(
            $inner['data'] ?? [],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );
        if ($json === false) {
            $this->log('error', 'ActivityLogsAdmin: json_encode failed', ['error' => json_last_error_msg()]);
            return $this->makeEnvelope(500, 'text/html; charset=UTF-8', null, $this->renderError('A server error occurred.'));
        }
        return $this->makeEnvelope($status, 'application/json; charset=UTF-8', null, $json);
    }

    /**
     * Create a host-emittable envelope array.
     *
     * @param int                      $status      HTTP status
     * @param string                   $contentType Content-Type value
     * @param string|null              $filename    Attachment filename or null
     * @param string|\Generator|false  $body        Response body
     * @return array<string,mixed>
     */
    private function makeEnvelope(int $status, string $contentType, ?string $filename, string|\Generator|false $body): array
    {
        return [
            'status'       => $status,
            'content_type' => $contentType,
            'filename'     => $filename,
            'body'         => $body !== false ? $body : '',
        ];
    }

    /**
     * Render a minimal error page (used for 403 / 500 before views are available).
     *
     * @param string $message Error message to display
     * @return string HTML
     */
    private function renderError(string $message): string
    {
        $msg = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Error</title></head>'
            . '<body><p>' . $msg . '</p></body></html>';
    }

    /**
     * Build a logger callable: use config['logger'] if callable, else fall back to error_log.
     *
     * @param array<string,mixed> $config
     * @return callable(string, string, array<mixed>): void
     */
    private function buildLogger(array $config): callable
    {
        if (isset($config['logger']) && is_callable($config['logger'])) {
            return $config['logger'];
        }
        return static function (string $level, string $msg, array $ctx): void {
            error_log(
                '[ActivityLogs][' . strtoupper($level) . '] ' . $msg
                . (empty($ctx) ? '' : ' ' . json_encode($ctx, JSON_UNESCAPED_UNICODE))
            );
        };
    }

    /**
     * Validate and normalize the table name (must match ^[A-Za-z0-9_]+$).
     *
     * @param mixed $name
     * @return string
     * @throws \InvalidArgumentException
     */
    private function validateTableName(mixed $name): string
    {
        if (!is_string($name) || !preg_match('/^[A-Za-z0-9_]+$/', $name)) {
            throw new \InvalidArgumentException(
                'ActivityLogsAdmin "table_name" must be a non-empty string of letters, digits, and underscores.'
            );
        }
        return $name;
    }

    /**
     * Validate and normalize page size.
     *
     * @param mixed $size
     * @return int
     * @throws \InvalidArgumentException
     */
    private function validatePageSize(mixed $size): int
    {
        $n = (int)$size;
        if ($n < 1) {
            throw new \InvalidArgumentException('ActivityLogsAdmin "page_size" must be >= 1.');
        }
        return $n;
    }

    /**
     * Validate that the timezone string is a recognized PHP timezone identifier.
     *
     * @param mixed $tz
     * @return string
     * @throws \InvalidArgumentException
     */
    private function validateTimezone(mixed $tz): string
    {
        if (!is_string($tz) || $tz === '') {
            throw new \InvalidArgumentException('ActivityLogsAdmin "timezone" must be a non-empty string.');
        }
        try {
            new \DateTimeZone($tz);
        } catch (\Exception) {
            throw new \InvalidArgumentException(
                'ActivityLogsAdmin "timezone" "' . $tz . '" is not a valid PHP timezone identifier.'
            );
        }
        return $tz;
    }

    /**
     * Validate the locale code — unknown codes fall back to 'en_US' without throwing.
     *
     * @param mixed $locale
     * @return string
     */
    private function validateLocale(mixed $locale): string
    {
        $allowed = ['en_US', 'hu_HU'];
        if (!is_string($locale) || !in_array($locale, $allowed, true)) {
            return 'en_US';
        }
        return $locale;
    }

    /**
     * Validate base_url: must be a same-origin path starting with "/".
     * Copied verbatim from PatchModule's validateBaseUrl() — modules are standalone.
     *
     * @param string $url
     * @return void
     * @throws \InvalidArgumentException
     */
    private function validateBaseUrl(string $url): void
    {
        if ($url === '') {
            throw new \InvalidArgumentException(
                'ActivityLogsAdmin requires "base_url" (string starting with "/")'
            );
        }
        if ($url[0] !== '/') {
            throw new \InvalidArgumentException(
                'ActivityLogsAdmin requires "base_url" (string starting with "/")'
            );
        }
        if (strlen($url) > 1 && $url[1] === '/') {
            throw new \InvalidArgumentException(
                'ActivityLogsAdmin "base_url" must be a same-origin path; protocol-relative ("//...") URLs are rejected'
            );
        }
        if (str_contains($url, '://')) {
            throw new \InvalidArgumentException(
                'ActivityLogsAdmin "base_url" must be a same-origin path; absolute ("scheme://...") URLs are rejected'
            );
        }
        if (str_contains($url, '..')) {
            throw new \InvalidArgumentException(
                'ActivityLogsAdmin "base_url" must not contain path traversal sequences ("..")'
            );
        }
        if (str_contains($url, '%')) {
            throw new \InvalidArgumentException(
                'ActivityLogsAdmin "base_url" must not contain percent-encoded sequences'
            );
        }
        if (preg_match('/[?#\s\x00-\x1F\x7F\x80-\xFF]/', $url)) {
            throw new \InvalidArgumentException(
                'ActivityLogsAdmin "base_url" must not contain "?", "#", whitespace, or non-ASCII characters'
            );
        }
        if (preg_match('#//#', substr($url, 1))) {
            throw new \InvalidArgumentException(
                'ActivityLogsAdmin "base_url" must not contain consecutive slashes ("//")'
            );
        }
    }
}
