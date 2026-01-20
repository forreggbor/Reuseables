<?php

declare(strict_types=1);

namespace ActivityLogs;

use PDO;

/**
 * ActivityLogger - Universal activity logging for PHP applications
 *
 * Framework-agnostic activity logging with flexible schema, sensitive data masking,
 * integrity verification, and comprehensive query methods.
 *
 * @package ActivityLogs
 * @version 1.0.0
 * @license MIT
 */
class ActivityLogger
{
    /**
     * PDO database connection
     */
    private static ?PDO $pdo = null;

    /**
     * Configuration
     */
    private static array $config = [
        'encryption_key' => 'activity_log_default_key',
        'table_name' => 'activity_logs',
        'sensitive_fields' => [
            'password',
            'password_hash',
            'api_key',
            'token',
            'secret',
            'credit_card',
            'cvv',
            'remember_token',
            'csrf_token',
            'encryption_key',
            'private_key',
            'access_token',
            'refresh_token',
        ],
    ];

    /**
     * Instance PDO for multi-connection setups
     */
    private ?PDO $instancePdo = null;

    /**
     * Instance configuration
     */
    private array $instanceConfig;

    /**
     * Create a new ActivityLogger instance
     *
     * @param PDO $pdo Database connection
     * @param array $config Configuration options
     */
    public function __construct(PDO $pdo, array $config = [])
    {
        $this->instancePdo = $pdo;
        $this->instanceConfig = array_merge(self::$config, $config);
    }

    /**
     * Initialize the static logger with PDO connection
     *
     * @param PDO $pdo Database connection
     * @param array $config Configuration options:
     *   - encryption_key: Key for checksum generation
     *   - table_name: Database table name (default: activity_logs)
     *   - sensitive_fields: Array of field names to mask
     * @return void
     */
    public static function init(PDO $pdo, array $config = []): void
    {
        self::$pdo = $pdo;
        self::$config = array_merge(self::$config, $config);
    }

    /**
     * Get the PDO connection
     *
     * @return PDO
     * @throws \RuntimeException If not initialized
     */
    private static function getPdo(): PDO
    {
        if (self::$pdo === null) {
            throw new \RuntimeException('ActivityLogger not initialized. Call ActivityLogger::init($pdo) first.');
        }
        return self::$pdo;
    }

    /**
     * Log an activity
     *
     * @param int|null $userId User performing the action (null for system)
     * @param string $action Action name (required)
     * @param string|null $entityType Entity type affected (optional)
     * @param string|int|null $entityId Entity identifier (optional, any format)
     * @param array|null $oldValues Previous values (for updates/deletes)
     * @param array|null $newValues New values (for creates/updates)
     * @param string|null $source Source context (auto-detected if null)
     * @param array|null $context Additional context data
     * @param string|null $ipAddress Client IP (auto-detected if null)
     * @param string|null $userAgent Browser user agent (auto-detected if null)
     * @return int|false Log entry ID or false on failure
     */
    public static function log(
        ?int $userId,
        string $action,
        ?string $entityType = null,
        string|int|null $entityId = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $source = null,
        ?array $context = null,
        ?string $ipAddress = null,
        ?string $userAgent = null
    ): int|false {
        return self::writeLog(
            self::getPdo(),
            self::$config,
            $userId,
            $action,
            $entityType,
            $entityId,
            $oldValues,
            $newValues,
            $source,
            $context,
            $ipAddress,
            $userAgent
        );
    }

    /**
     * Log an activity using instance
     *
     * @param int|null $userId User performing the action
     * @param string $action Action name
     * @param string|null $entityType Entity type
     * @param string|int|null $entityId Entity identifier
     * @param array|null $oldValues Previous values
     * @param array|null $newValues New values
     * @param string|null $source Source context
     * @param array|null $context Additional context
     * @param string|null $ipAddress Client IP
     * @param string|null $userAgent Browser user agent
     * @return int|false Log entry ID or false on failure
     */
    public function write(
        ?int $userId,
        string $action,
        ?string $entityType = null,
        string|int|null $entityId = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $source = null,
        ?array $context = null,
        ?string $ipAddress = null,
        ?string $userAgent = null
    ): int|false {
        return self::writeLog(
            $this->instancePdo,
            $this->instanceConfig,
            $userId,
            $action,
            $entityType,
            $entityId,
            $oldValues,
            $newValues,
            $source,
            $context,
            $ipAddress,
            $userAgent
        );
    }

    /**
     * Core logging implementation
     */
    private static function writeLog(
        PDO $pdo,
        array $config,
        ?int $userId,
        string $action,
        ?string $entityType,
        string|int|null $entityId,
        ?array $oldValues,
        ?array $newValues,
        ?string $source,
        ?array $context,
        ?string $ipAddress,
        ?string $userAgent
    ): int|false {
        // Filter out unchanged values
        [$filteredOldValues, $filteredNewValues] = self::filterUnchangedValues($oldValues, $newValues);

        // Mask sensitive data
        $maskedOldValues = self::maskSensitiveData($filteredOldValues, $config['sensitive_fields']);
        $maskedNewValues = self::maskSensitiveData($filteredNewValues, $config['sensitive_fields']);
        $maskedContext = self::maskSensitiveData($context, $config['sensitive_fields']);

        // Get session ID if available
        $sessionId = (session_status() === PHP_SESSION_ACTIVE) ? session_id() : null;

        // Auto-detect source if not provided
        $source = $source ?? self::detectSource();

        // Get IP and user agent
        $ip = $ipAddress ?? self::getClientIp();
        $ua = $userAgent ?? ($_SERVER['HTTP_USER_AGENT'] ?? null);

        // Convert entity_id to string
        $entityIdStr = $entityId !== null ? (string)$entityId : null;

        // JSON encode values
        $oldValuesJson = $maskedOldValues ? json_encode($maskedOldValues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
        $newValuesJson = $maskedNewValues ? json_encode($maskedNewValues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
        $contextJson = $maskedContext ? json_encode($maskedContext, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;

        // Generate integrity checksum
        $createdAt = date('Y-m-d H:i:s');
        $checksum = self::generateChecksum(
            $config['encryption_key'],
            $userId,
            $action,
            $entityType,
            $entityIdStr,
            $oldValuesJson,
            $newValuesJson,
            $ip,
            $createdAt
        );

        $table = $config['table_name'];
        $sql = "INSERT INTO {$table}
                (user_id, source, action, entity_type, entity_id, old_values, new_values, context, ip_address, user_agent, session_id, checksum, created_at)
                VALUES (:user_id, :source, :action, :entity_type, :entity_id, :old_values, :new_values, :context, :ip_address, :user_agent, :session_id, :checksum, :created_at)";

        $stmt = $pdo->prepare($sql);

        $result = $stmt->execute([
            'user_id' => $userId,
            'source' => $source,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityIdStr,
            'old_values' => $oldValuesJson,
            'new_values' => $newValuesJson,
            'context' => $contextJson,
            'ip_address' => $ip,
            'user_agent' => $ua,
            'session_id' => $sessionId,
            'checksum' => $checksum,
            'created_at' => $createdAt,
        ]);

        return $result ? (int)$pdo->lastInsertId() : false;
    }

    /**
     * Auto-detect source based on request context
     */
    private static function detectSource(): ?string
    {
        // CLI = system
        if (php_sapi_name() === 'cli') {
            return 'cli';
        }

        $uri = $_SERVER['REQUEST_URI'] ?? '';

        if (strpos($uri, '/webhook') !== false) {
            return 'webhook';
        }

        if (strpos($uri, '/api/') !== false || strpos($uri, '/api') === 0) {
            return 'api';
        }

        if (strpos($uri, '/admin') !== false) {
            return 'admin';
        }

        return 'frontend';
    }

    /**
     * Get client IP address with proxy support
     */
    private static function getClientIp(): ?string
    {
        $headers = [
            'HTTP_CF_CONNECTING_IP',     // Cloudflare
            'HTTP_X_FORWARDED_FOR',      // Standard proxy
            'HTTP_X_REAL_IP',            // Nginx proxy
            'HTTP_CLIENT_IP',            // Client IP
            'REMOTE_ADDR',               // Direct connection
        ];

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];
                // Handle comma-separated IPs (take the first one)
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                // Validate IP
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return null;
    }

    /**
     * Filter out unchanged values from old and new value arrays
     */
    private static function filterUnchangedValues(?array $oldValues, ?array $newValues): array
    {
        if ($oldValues === null || $newValues === null) {
            return [$oldValues, $newValues];
        }

        $filteredOld = [];
        $filteredNew = [];

        $allKeys = array_unique(array_merge(array_keys($oldValues), array_keys($newValues)));

        foreach ($allKeys as $key) {
            $oldVal = $oldValues[$key] ?? null;
            $newVal = $newValues[$key] ?? null;

            $oldCompare = is_array($oldVal) ? json_encode($oldVal) : (string)$oldVal;
            $newCompare = is_array($newVal) ? json_encode($newVal) : (string)$newVal;

            if ($oldCompare !== $newCompare) {
                if (array_key_exists($key, $oldValues)) {
                    $filteredOld[$key] = $oldVal;
                }
                if (array_key_exists($key, $newValues)) {
                    $filteredNew[$key] = $newVal;
                }
            }
        }

        return [
            empty($filteredOld) ? null : $filteredOld,
            empty($filteredNew) ? null : $filteredNew,
        ];
    }

    /**
     * Mask sensitive data in array (recursive)
     */
    private static function maskSensitiveData(?array $data, array $sensitiveFields): ?array
    {
        if ($data === null) {
            return null;
        }

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::maskSensitiveData($value, $sensitiveFields);
            } elseif (in_array(strtolower($key), array_map('strtolower', $sensitiveFields), true)) {
                $data[$key] = '***MASKED***';
            }
        }

        return $data;
    }

    /**
     * Generate integrity checksum for log entry
     */
    private static function generateChecksum(
        string $key,
        ?int $userId,
        string $action,
        ?string $entityType,
        ?string $entityId,
        ?string $oldValues,
        ?string $newValues,
        ?string $ipAddress,
        string $createdAt
    ): string {
        $checksumData = implode('|', [
            $userId ?? 'null',
            $action,
            $entityType ?? 'null',
            $entityId ?? 'null',
            $oldValues ?? '',
            $newValues ?? '',
            $ipAddress ?? '',
            $createdAt,
        ]);

        return hash('sha256', $checksumData . $key);
    }

    /**
     * Verify integrity of a log entry
     *
     * @param int $logId Log entry ID
     * @return bool True if checksum is valid
     */
    public static function verifyIntegrity(int $logId): bool
    {
        $log = self::findById($logId);
        if (!$log) {
            return false;
        }

        if (empty($log->checksum)) {
            return true; // Legacy entries without checksum
        }

        $expectedChecksum = self::generateChecksum(
            self::$config['encryption_key'],
            $log->user_id,
            $log->action,
            $log->entity_type,
            $log->entity_id,
            $log->old_values,
            $log->new_values,
            $log->ip_address,
            $log->created_at
        );

        return hash_equals($log->checksum, $expectedChecksum);
    }

    /**
     * Find log entry by ID
     *
     * @param int $id Log entry ID
     * @return object|null Log entry or null
     */
    public static function findById(int $id): ?object
    {
        $table = self::$config['table_name'];
        $sql = "SELECT * FROM {$table} WHERE id = :id";

        $stmt = self::getPdo()->prepare($sql);
        $stmt->execute(['id' => $id]);

        return $stmt->fetch(PDO::FETCH_OBJ) ?: null;
    }

    /**
     * Get logs by session ID
     *
     * @param string $sessionId Session ID
     * @param int $limit Maximum entries
     * @return array Log entries
     */
    public static function getBySession(string $sessionId, int $limit = 100): array
    {
        $table = self::$config['table_name'];
        $sql = "SELECT * FROM {$table}
                WHERE session_id = :session_id
                ORDER BY created_at ASC
                LIMIT :limit";

        $stmt = self::getPdo()->prepare($sql);
        $stmt->bindValue(':session_id', $sessionId);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_OBJ);
    }

    /**
     * Get all activity logs with filters
     *
     * @param array $filters Filters: user_id, action, entity_type, entity_id, source, date_from, date_to, search, limit, offset
     * @return array Log entries
     */
    public static function getAll(array $filters = []): array
    {
        $table = self::$config['table_name'];
        $sql = "SELECT * FROM {$table} WHERE 1=1";
        $params = [];

        if (!empty($filters['user_id'])) {
            $sql .= " AND user_id = :user_id";
            $params['user_id'] = $filters['user_id'];
        }

        if (!empty($filters['action'])) {
            $sql .= " AND action = :action";
            $params['action'] = $filters['action'];
        }

        if (!empty($filters['entity_type'])) {
            $sql .= " AND entity_type = :entity_type";
            $params['entity_type'] = $filters['entity_type'];
        }

        if (!empty($filters['entity_id'])) {
            $sql .= " AND entity_id = :entity_id";
            $params['entity_id'] = (string)$filters['entity_id'];
        }

        if (!empty($filters['source'])) {
            $sql .= " AND source = :source";
            $params['source'] = $filters['source'];
        }

        if (!empty($filters['date_from'])) {
            $sql .= " AND created_at >= :date_from";
            $params['date_from'] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $sql .= " AND created_at <= :date_to";
            $params['date_to'] = $filters['date_to'] . ' 23:59:59';
        }

        if (!empty($filters['search'])) {
            $sql .= " AND (action LIKE :search1 OR entity_type LIKE :search2)";
            $params['search1'] = '%' . $filters['search'] . '%';
            $params['search2'] = '%' . $filters['search'] . '%';
        }

        $sql .= " ORDER BY created_at DESC";

        if (isset($filters['limit'])) {
            $sql .= " LIMIT :limit OFFSET :offset";
        }

        $stmt = self::getPdo()->prepare($sql);

        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }

        if (isset($filters['limit'])) {
            $stmt->bindValue(':limit', (int)$filters['limit'], PDO::PARAM_INT);
            $stmt->bindValue(':offset', (int)($filters['offset'] ?? 0), PDO::PARAM_INT);
        }

        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_OBJ);
    }

    /**
     * Get logs for specific user
     *
     * @param int $userId User ID
     * @param int $limit Maximum entries
     * @return array Log entries
     */
    public static function getByUser(int $userId, int $limit = 50): array
    {
        $table = self::$config['table_name'];
        $sql = "SELECT * FROM {$table}
                WHERE user_id = :user_id
                ORDER BY created_at DESC
                LIMIT :limit";

        $stmt = self::getPdo()->prepare($sql);
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_OBJ);
    }

    /**
     * Get logs for specific entity
     *
     * @param string $entityType Entity type
     * @param string|int $entityId Entity ID
     * @param int $limit Maximum entries
     * @return array Log entries
     */
    public static function getByEntity(string $entityType, string|int $entityId, int $limit = 50): array
    {
        $table = self::$config['table_name'];
        $sql = "SELECT * FROM {$table}
                WHERE entity_type = :entity_type AND entity_id = :entity_id
                ORDER BY created_at DESC
                LIMIT :limit";

        $stmt = self::getPdo()->prepare($sql);
        $stmt->bindValue(':entity_type', $entityType);
        $stmt->bindValue(':entity_id', (string)$entityId);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_OBJ);
    }

    /**
     * Get count with filters
     *
     * @param array $filters Same filters as getAll()
     * @return int Count
     */
    public static function getCount(array $filters = []): int
    {
        $table = self::$config['table_name'];
        $sql = "SELECT COUNT(*) as count FROM {$table} WHERE 1=1";
        $params = [];

        if (!empty($filters['user_id'])) {
            $sql .= " AND user_id = :user_id";
            $params['user_id'] = $filters['user_id'];
        }

        if (!empty($filters['action'])) {
            $sql .= " AND action = :action";
            $params['action'] = $filters['action'];
        }

        if (!empty($filters['entity_type'])) {
            $sql .= " AND entity_type = :entity_type";
            $params['entity_type'] = $filters['entity_type'];
        }

        if (!empty($filters['entity_id'])) {
            $sql .= " AND entity_id = :entity_id";
            $params['entity_id'] = (string)$filters['entity_id'];
        }

        if (!empty($filters['source'])) {
            $sql .= " AND source = :source";
            $params['source'] = $filters['source'];
        }

        if (!empty($filters['date_from'])) {
            $sql .= " AND created_at >= :date_from";
            $params['date_from'] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $sql .= " AND created_at <= :date_to";
            $params['date_to'] = $filters['date_to'] . ' 23:59:59';
        }

        if (!empty($filters['search'])) {
            $sql .= " AND (action LIKE :search1 OR entity_type LIKE :search2)";
            $params['search1'] = '%' . $filters['search'] . '%';
            $params['search2'] = '%' . $filters['search'] . '%';
        }

        $stmt = self::getPdo()->prepare($sql);
        $stmt->execute($params);

        return (int)$stmt->fetch(PDO::FETCH_OBJ)->count;
    }

    /**
     * Get unique actions
     *
     * @return array List of action names
     */
    public static function getUniqueActions(): array
    {
        $table = self::$config['table_name'];
        $sql = "SELECT DISTINCT action FROM {$table} ORDER BY action ASC";

        $stmt = self::getPdo()->prepare($sql);
        $stmt->execute();

        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'action');
    }

    /**
     * Get unique entity types
     *
     * @return array List of entity types
     */
    public static function getUniqueEntityTypes(): array
    {
        $table = self::$config['table_name'];
        $sql = "SELECT DISTINCT entity_type FROM {$table} WHERE entity_type IS NOT NULL ORDER BY entity_type ASC";

        $stmt = self::getPdo()->prepare($sql);
        $stmt->execute();

        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'entity_type');
    }

    /**
     * Get unique sources
     *
     * @return array List of sources
     */
    public static function getUniqueSources(): array
    {
        $table = self::$config['table_name'];
        $sql = "SELECT DISTINCT source FROM {$table} WHERE source IS NOT NULL ORDER BY source ASC";

        $stmt = self::getPdo()->prepare($sql);
        $stmt->execute();

        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'source');
    }

    /**
     * Get statistics
     *
     * @param array $filters date_from, date_to
     * @return array Statistics
     */
    public static function getStatistics(array $filters = []): array
    {
        $table = self::$config['table_name'];
        $sql = "SELECT
                    COUNT(*) as total,
                    COUNT(DISTINCT user_id) as unique_users,
                    COUNT(DISTINCT entity_type) as unique_entity_types,
                    COUNT(DISTINCT action) as unique_actions,
                    COUNT(DISTINCT source) as unique_sources,
                    SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) as today,
                    SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) as this_week
                FROM {$table}
                WHERE 1=1";

        $params = [];

        if (!empty($filters['date_from'])) {
            $sql .= " AND created_at >= :date_from";
            $params['date_from'] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $sql .= " AND created_at <= :date_to";
            $params['date_to'] = $filters['date_to'] . ' 23:59:59';
        }

        $stmt = self::getPdo()->prepare($sql);
        $stmt->execute($params);

        return (array)$stmt->fetch(PDO::FETCH_OBJ);
    }

    /**
     * Get activity trend (daily counts)
     *
     * @param int $days Number of days
     * @return array Daily counts
     */
    public static function getActivityTrend(int $days = 30): array
    {
        $table = self::$config['table_name'];
        $sql = "SELECT
                    DATE(created_at) as date,
                    COUNT(*) as count
                FROM {$table}
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
                GROUP BY DATE(created_at)
                ORDER BY date ASC";

        $stmt = self::getPdo()->prepare($sql);
        $stmt->bindValue(':days', $days, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_OBJ);
    }

    /**
     * Delete old logs
     *
     * @param int $days Delete logs older than this many days
     * @return int Number of deleted rows
     */
    public static function deleteOldLogs(int $days = 90): int
    {
        $table = self::$config['table_name'];
        $sql = "DELETE FROM {$table}
                WHERE created_at < DATE_SUB(NOW(), INTERVAL :days DAY)";

        $stmt = self::getPdo()->prepare($sql);
        $stmt->bindValue(':days', $days, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount();
    }

    /**
     * Get current configuration
     *
     * @return array Configuration
     */
    public static function getConfig(): array
    {
        return self::$config;
    }

    /**
     * Add sensitive fields to the list
     *
     * @param array $fields Field names to add
     * @return void
     */
    public static function addSensitiveFields(array $fields): void
    {
        self::$config['sensitive_fields'] = array_unique(
            array_merge(self::$config['sensitive_fields'], $fields)
        );
    }
}
