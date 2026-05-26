<?php
/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * HTTP action handler for the ActivityLogs admin UI.
 */

declare(strict_types=1);

namespace ActivityLogs;

use ActivityLogs\Contracts\AuthAdapterInterface;
use ActivityLogs\Contracts\TranslatorInterface;

/**
 * AdminActions - HTTP action handler for the activity log admin interface
 *
 * One method per action, each returning a plain internal array envelope — no
 * echo, no header() calls, no exit/die. The facade calls these methods, resolves
 * view envelopes via renderView(), and emits the result to the host.
 *
 * @package ActivityLogs
 */
class AdminActions
{
    /** @var array<string,string>|null Lazily-loaded fallback locale strings */
    private static ?array $fallbackLocale = null;

    /**
     * @param ActivityLogsAdmin        $facade     The admin facade (provides query helpers)
     * @param AuthAdapterInterface     $auth       Host authentication adapter
     * @param EntityResolverRegistry   $resolvers  Entity display-name resolver registry
     * @param ActionColorResolver      $colors     Action badge color resolver
     * @param TranslatorInterface|null $translator Optional host translator
     */
    public function __construct(
        private readonly ActivityLogsAdmin $facade,
        private readonly AuthAdapterInterface $auth,
        private readonly EntityResolverRegistry $resolvers,
        private readonly ActionColorResolver $colors,
        private readonly ?TranslatorInterface $translator = null,
    ) {
    }

    // =========================================================================
    // Public action methods
    // =========================================================================

    /**
     * Build and return the admin index page data.
     *
     * @param array<string,mixed> $request Request parameters ($_GET)
     * @return array{view:string,data:array<string,mixed>}
     */
    public function index(array $request): array
    {
        $filters  = $this->buildFilters($request);
        $page     = max(1, (int)($request['page'] ?? 1));
        $pageSize = $this->facade->getPageSize();
        $offset   = ($page - 1) * $pageSize;

        $total = \ActivityLogs\ActivityLogger::getCount($filters);

        $totalPages = $total > 0 ? (int)ceil($total / $pageSize) : 1;
        $page       = min($page, $totalPages);
        $offset     = ($page - 1) * $pageSize;

        $rows = \ActivityLogs\ActivityLogger::getAll(array_merge($filters, [
            'limit'  => $pageSize,
            'offset' => $offset,
        ]));

        $stats        = $this->facade->computeFilteredStats($filters);
        $actions      = \ActivityLogs\ActivityLogger::getUniqueActions();
        $entityTypes  = \ActivityLogs\ActivityLogger::getUniqueEntityTypes();
        $sources      = \ActivityLogs\ActivityLogger::getUniqueSources();
        $userIds      = $this->facade->getDistinctUserIds();
        $userMap      = $this->auth->getUserMap($userIds);

        // Resolve entity names and badge colors for current page
        $resolvedRows = $this->resolveRows($rows, $userMap);

        $tr      = $this->getViewTranslator();
        $baseUrl = $this->facade->getBaseUrl();
        $locale  = $this->facade->getLocale();

        return [
            'view' => 'admin/index',
            'data' => compact(
                'tr', 'baseUrl', 'locale',
                'filters', 'page', 'pageSize', 'total', 'totalPages',
                'stats', 'actions', 'entityTypes', 'sources', 'userMap',
                'resolvedRows',
                'request',
            ),
        ];
    }

    /**
     * Return a single log entry as JSON for the detail modal.
     *
     * @param array<string,mixed> $request Request parameters
     * @return array{status:int,data:array<string,mixed>}
     */
    public function details(array $request): array
    {
        $id = isset($request['id']) ? (int)$request['id'] : 0;
        if ($id < 1) {
            return ['status' => 400, 'data' => ['error' => 'Invalid id.']];
        }

        $entry = \ActivityLogs\ActivityLogger::findById($id);
        if ($entry === null) {
            return ['status' => 404, 'data' => ['error' => 'Entry not found.']];
        }

        return [
            'status' => 200,
            'data'   => [
                'id'          => $entry->id,
                'created_at'  => $this->facade->formatTs($entry->created_at),
                'user_id'     => $entry->user_id,
                'source'      => $entry->source,
                'action'      => $entry->action,
                'entity_type' => $entry->entity_type,
                'entity_id'   => $entry->entity_id,
                'old_values'  => $this->decodeJson($entry->old_values ?? null),
                'new_values'  => $this->decodeJson($entry->new_values ?? null),
                'context'     => $this->decodeJson($entry->context ?? null),
                'ip_address'  => $entry->ip_address,
                'user_agent'  => $entry->user_agent,
                'session_id'  => $entry->session_id,
                'checksum'    => $entry->checksum,
            ],
        ];
    }

    /**
     * Stream a CSV export of filtered log entries.
     *
     * The body is a Generator that yields chunks so the entire export is never
     * buffered in memory. The host must foreach the Generator and echo+flush each chunk.
     *
     * @param array<string,mixed> $request Request parameters
     * @return array{download:array{content_type:string,filename:string,body:\Generator}}
     */
    public function exportCsv(array $request): array
    {
        $filters  = $this->buildFilters($request);
        $filename = 'activity-log-' . date('Ymd') . '.csv';

        $facade = $this->facade;
        $auth   = $this->auth;

        $generator = (function () use ($filters, $facade, $auth): \Generator {
            // UTF-8 BOM for Excel
            yield "\xEF\xBB\xBF";

            // Header row
            yield $this->csvRow([
                'ID', 'Time', 'User', 'Source', 'Action',
                'Entity Type', 'Entity ID', 'IP Address', 'Old Values', 'New Values', 'Context',
            ]);

            $chunkSize = 1000;
            $offset    = 0;
            // Persistent cache across all chunks: every distinct user_id is looked up exactly once.
            $userCache = [];

            do {
                $rows = \ActivityLogs\ActivityLogger::getAll(array_merge($filters, [
                    'limit'  => $chunkSize,
                    'offset' => $offset,
                ]));

                // Batch-fetch user names for any IDs not yet in the cache.
                $missingIds = [];
                foreach ($rows as $row) {
                    if ($row->user_id !== null && !isset($userCache[(int)$row->user_id])) {
                        $missingIds[] = (int)$row->user_id;
                    }
                }
                $missingIds = array_values(array_unique($missingIds));
                if ($missingIds !== []) {
                    $map = $auth->getUserMap($missingIds);
                    foreach ($missingIds as $id) {
                        // Cache the fallback for deleted/unresolved IDs so they are never re-queried.
                        $userCache[$id] = $map[$id] ?? (string)$id;
                    }
                }

                foreach ($rows as $row) {
                    $userId    = $row->user_id;
                    $userLabel = $userId !== null ? $userCache[(int)$userId] : '';

                    yield $this->csvRow([
                        $row->id,
                        $facade->formatTs($row->created_at),
                        $userLabel,
                        $row->source ?? '',
                        $row->action,
                        $row->entity_type ?? '',
                        $row->entity_id ?? '',
                        $row->ip_address ?? '',
                        $this->jsonForCsv($row->old_values ?? null),
                        $this->jsonForCsv($row->new_values ?? null),
                        $this->jsonForCsv($row->context ?? null),
                    ]);
                }

                $count   = count($rows);
                $offset += $chunkSize;
            } while ($count === $chunkSize);
        })();

        return [
            'download' => [
                'content_type' => 'text/csv; charset=UTF-8',
                'filename'     => $filename,
                'body'         => $generator,
            ],
        ];
    }

    /**
     * Render a printer-friendly page of filtered log entries.
     *
     * @param array<string,mixed> $request Request parameters
     * @return array{view:string,data:array<string,mixed>}
     */
    public function printView(array $request): array
    {
        $filters      = $this->buildFilters($request);
        $printMaxRows = $this->facade->getPrintMaxRows();

        $allRows   = [];
        $chunkSize = 1000;
        $offset    = 0;
        $truncated = false;

        do {
            $rows    = \ActivityLogs\ActivityLogger::getAll(array_merge($filters, [
                'limit'  => $chunkSize,
                'offset' => $offset,
            ]));
            $allRows = array_merge($allRows, $rows);
            $count   = count($rows);
            $offset += $chunkSize;

            if (count($allRows) > $printMaxRows) {
                $truncated = true;
                break;
            }
        } while ($count === $chunkSize);

        $allRows = array_slice($allRows, 0, $printMaxRows);

        $userIds  = array_unique(array_filter(array_column($allRows, 'user_id')));
        $userMap  = $this->auth->getUserMap(array_map('intval', $userIds));

        $resolvedRows = $this->resolveRows($allRows, $userMap);

        $tr           = $this->getViewTranslator();
        $baseUrl      = $this->facade->getBaseUrl();
        $assetBaseUrl = $this->facade->getAssetBaseUrl();
        $generatedAt  = $this->facade->formatTs(date('Y-m-d H:i:s'));

        return [
            'view' => 'admin/print',
            'data' => compact('tr', 'baseUrl', 'assetBaseUrl', 'filters', 'resolvedRows', 'generatedAt', 'truncated'),
        ];
    }

    /**
     * Dispatch a named action to the appropriate method.
     *
     * @param string              $action  Action name
     * @param array<string,mixed> $request Request parameters
     * @return array<string,mixed> Internal envelope
     */
    public function dispatch(string $action, array $request): array
    {
        return match ($action) {
            'index'     => $this->index($request),
            'details'   => $this->details($request),
            'exportCsv' => $this->exportCsv($request),
            'printView' => $this->printView($request),
            default     => ['status' => 404, 'data' => ['error' => 'Unknown action.']],
        };
    }

    /**
     * Return the entity resolver registry.
     *
     * @return EntityResolverRegistry
     */
    public function getResolverRegistry(): EntityResolverRegistry
    {
        return $this->resolvers;
    }

    /**
     * Return a translator closure to pass into view templates.
     *
     * @return \Closure fn(string $key, mixed ...$params): string
     */
    public function getViewTranslator(): \Closure
    {
        return fn(string $k, mixed ...$p): string => $this->t($k, ...$p);
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Build a validated filter array from request parameters.
     *
     * @param array<string,mixed> $request
     * @return array<string,mixed>
     */
    private function buildFilters(array $request): array
    {
        $filters = [];

        if (!empty($request['user_id'])) {
            $filters['user_id'] = (int)$request['user_id'];
        }
        if (!empty($request['log_action'])) {
            $filters['action']     = (string)$request['log_action']; // ActivityLogger query key
            $filters['log_action'] = (string)$request['log_action']; // URL-building key for views
        }
        if (!empty($request['entity_type'])) {
            $filters['entity_type'] = (string)$request['entity_type'];
        }
        if (!empty($request['entity_id'])) {
            $filters['entity_id'] = (string)$request['entity_id'];
        }
        if (!empty($request['source'])) {
            $filters['source'] = (string)$request['source'];
        }
        if (!empty($request['date_from']) && $this->isValidDate($request['date_from'])) {
            $filters['date_from'] = (string)$request['date_from'];
        }
        if (!empty($request['date_to']) && $this->isValidDate($request['date_to'])) {
            $filters['date_to'] = (string)$request['date_to'];
        }
        if (!empty($request['search'])) {
            $filters['search'] = (string)$request['search'];
        }

        return $filters;
    }

    /**
     * Validate a date string as YYYY-MM-DD.
     *
     * @param mixed $date
     * @return bool
     */
    private function isValidDate(mixed $date): bool
    {
        if (!is_string($date)) {
            return false;
        }
        $d = \DateTimeImmutable::createFromFormat('Y-m-d', $date);
        return $d !== false && $d->format('Y-m-d') === $date;
    }

    /**
     * Attach resolved entity names and badge colors to each row.
     *
     * @param array<object>       $rows    Raw log rows from ActivityLogger::getAll()
     * @param array<int,string>   $userMap User ID → display name map
     * @return array<int,array<string,mixed>> Augmented row data
     */
    private function resolveRows(array $rows, array $userMap): array
    {
        // Group entity IDs per type for batch resolution
        $byType = [];
        foreach ($rows as $row) {
            if ($row->entity_type !== null && $row->entity_id !== null) {
                $byType[$row->entity_type][] = $row->entity_id;
            }
        }
        $entityNames = [];
        foreach ($byType as $type => $ids) {
            $entityNames[$type] = $this->resolvers->resolveBatch($type, array_unique($ids));
        }

        $resolved = [];
        foreach ($rows as $row) {
            $entityName = null;
            if ($row->entity_type !== null && $row->entity_id !== null) {
                $entityName = $entityNames[$row->entity_type][$row->entity_id]
                    ?? ($row->entity_type . ' #' . $row->entity_id);
            }

            $userId = $row->user_id;
            $userDisplay = $userId !== null
                ? ($userMap[$userId] ?? $this->t('TEXT_UNKNOWN_USER'))
                : $this->t('TEXT_SYSTEM_USER');

            $oldValues = $this->decodeJsonSafe($row->old_values ?? null);
            $newValues = $this->decodeJsonSafe($row->new_values ?? null);
            $context   = $this->decodeJsonSafe($row->context ?? null);

            $resolved[] = [
                'id'           => $row->id,
                'created_at'   => $this->facade->formatTs($row->created_at),
                'user_id'      => $userId,
                'user_display' => $userDisplay,
                'source'       => $row->source,
                'action'       => $row->action,
                'badge_class'  => $this->colors->resolve($row->action),
                'entity_type'  => $row->entity_type,
                'entity_id'    => $row->entity_id,
                'entity_name'  => $entityName,
                'old_values'   => $oldValues,
                'new_values'   => $newValues,
                'context'      => $context,
                'ip_address'   => $row->ip_address,
                'has_diff'     => $oldValues !== null || $newValues !== null,
            ];
        }

        return $resolved;
    }

    /**
     * Decode a stored JSON string with JSON_THROW_ON_ERROR; on failure, log and return null.
     *
     * @param string|null $json
     * @return array<string,mixed>|null
     */
    private function decodeJsonSafe(?string $json): ?array
    {
        if ($json === null || $json === '') {
            return null;
        }
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : null;
        } catch (\JsonException $e) {
            $this->facade->log('warning', 'ActivityLogs: malformed stored JSON value', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Decode a JSON string for use in the details() response (returns raw value or null).
     *
     * @param string|null $json
     * @return mixed
     */
    private function decodeJson(?string $json): mixed
    {
        if ($json === null || $json === '') {
            return null;
        }
        try {
            return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $json;
        }
    }

    /**
     * Format a JSON value for CSV export (compact JSON or empty string).
     *
     * @param string|null $json
     * @return string
     */
    private function jsonForCsv(?string $json): string
    {
        if ($json === null || $json === '') {
            return '';
        }
        return $json;
    }

    /**
     * Format a single CSV row with injection guard and CRLF line ending.
     *
     * Leading =, +, -, @, or tab characters are prefixed with a single quote
     * to prevent formula injection in spreadsheet applications.
     *
     * @param array<mixed> $fields
     * @return string
     */
    private function csvRow(array $fields): string
    {
        $cells = [];
        foreach ($fields as $field) {
            $value = (string)$field;
            // CSV injection guard
            if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t"], true)) {
                $value = "'" . $value;
            }
            $cells[] = '"' . str_replace('"', '""', $value) . '"';
        }
        return implode(',', $cells) . "\r\n";
    }

    /**
     * Translate a TEXT_* key with optional positional parameters.
     *
     * Delegates to the injected TranslatorInterface when available; otherwise loads
     * the module's own locale PHP-array. Falls back to en_US if the configured locale
     * file is missing a key.
     *
     * @param string $key       Translation key
     * @param mixed  ...$params Positional values for %s/%d placeholders
     * @return string
     */
    public function t(string $key, mixed ...$params): string
    {
        if ($this->translator !== null) {
            return $this->translator->t($key, $params);
        }

        $locale = $this->loadLocale($this->facade->getLocale());
        $string = $locale[$key] ?? $this->loadFallbackLocale()[$key] ?? $key;

        if ($params !== [] && (str_contains($string, '%s') || str_contains($string, '%d'))) {
            return vsprintf($string, $params);
        }

        return $string;
    }

    /**
     * Lazily load and cache a locale array by locale code.
     *
     * @param string $locale Locale code (e.g. 'hu_HU')
     * @return array<string,string>
     */
    private function loadLocale(string $locale): array
    {
        static $cache = [];
        if (isset($cache[$locale])) {
            return $cache[$locale];
        }
        $path = dirname(__DIR__) . '/locale/' . $locale . '/messages.php';
        if (file_exists($path)) {
            $loaded = require $path;
            $cache[$locale] = is_array($loaded) ? $loaded : [];
        } else {
            $cache[$locale] = [];
        }
        return $cache[$locale];
    }

    /**
     * Lazily load and cache the module's built-in en_US fallback locale.
     *
     * @return array<string,string>
     */
    private function loadFallbackLocale(): array
    {
        if (self::$fallbackLocale === null) {
            $path = dirname(__DIR__) . '/locale/en_US/messages.php';
            if (file_exists($path)) {
                $loaded = require $path;
                self::$fallbackLocale = is_array($loaded) ? $loaded : [];
            } else {
                self::$fallbackLocale = [];
            }
        }
        return self::$fallbackLocale;
    }
}
