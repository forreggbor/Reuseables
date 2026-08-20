<?php

/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * PatchMigrator - SQL migration parser, executor, and migration-table manager
 */

declare(strict_types=1);

namespace PatchModule;

use PatchModule\Contracts\DatabaseAdapterInterface;
use PatchModule\Contracts\LoggerInterface;

/**
 * PatchMigrator - SQL migration parser and executor
 *
 * Runs individual SQL statements from a migration file separated by semicolons.
 * Disables foreign key checks during migration and re-enables them afterward.
 * Does NOT use database transactions — on failure, the caller handles rollback
 * via BackupAdapter.
 *
 * Also manages the patch_migrations tracking table: creates it on first use
 * (CREATE TABLE IF NOT EXISTS), backfills from database/migrations/*.sql only
 * when the schema itself looks genuinely fresh (not just when the tracking
 * table happens to be empty — see isFreshSchema()/Reusables#13), and records
 * each applied migration filename with UNIQUE enforcement.
 *
 * @package PatchModule
 */
class PatchMigrator
{
    /** DDL for the patch_migrations tracking table — keep in sync with schema/patch_migrations.sql */
    private const PATCH_MIGRATIONS_DDL = "
        CREATE TABLE IF NOT EXISTS `patch_migrations` (
            `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `patch_history_id` INT UNSIGNED NULL,
            `filename`         VARCHAR(255) NOT NULL,
            `executed_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_patch_migrations_filename` (`filename`),
            KEY `idx_patch_migrations_history` (`patch_history_id`),
            CONSTRAINT `fk_patch_migrations_history`
                FOREIGN KEY (`patch_history_id`) REFERENCES `patch_history` (`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";

    /** @var DatabaseAdapterInterface */
    private DatabaseAdapterInterface $database;

    /** @var LoggerInterface|null */
    private ?LoggerInterface $logger;

    /** @var string Absolute path to the project root (for bootstrap backfill) */
    private string $rootPath;

    /** @var bool Instance-local latch: true once ensureMigrationsTable() has completed */
    private bool $bootstrapDone = false;

    /**
     * @param DatabaseAdapterInterface $database Database adapter (for raw PDO access)
     * @param string                   $rootPath Absolute path to the project root
     * @param LoggerInterface|null     $logger   Optional logger
     */
    public function __construct(
        DatabaseAdapterInterface $database,
        string $rootPath,
        ?LoggerInterface $logger = null
    ) {
        $this->database = $database;
        $this->rootPath = $rootPath;
        $this->logger   = $logger;
    }

    /**
     * Execute every *.sql file in $dirPath in lexicographic (chronological) order
     *
     * Tracks applied filenames in patch_migrations (UNIQUE on filename). Files already
     * present in patch_migrations are skipped. Creates and backfills patch_migrations on
     * first use via ensureMigrationsTable().
     *
     * @param string   $dirPath        Absolute path to directory containing *.sql files
     * @param int|null $patchHistoryId patch_history.id to associate with applied migrations
     * @return array{success: bool, applied: string[], skipped: string[], failed_file: ?string, error: ?string}
     */
    public function executeMigrationsDirectory(string $dirPath, ?int $patchHistoryId): array
    {
        $this->ensureMigrationsTable();

        $files = glob($dirPath . '/*.sql');
        if ($files === false) {
            $files = [];
        }
        sort($files, SORT_STRING);

        $applied = [];
        $skipped = [];

        $pdo = $this->database->getPdo();

        foreach ($files as $filePath) {
            $filename = basename($filePath);

            // Skip already-applied migrations (UNIQUE filename)
            $stmt = $pdo->prepare('SELECT 1 FROM `patch_migrations` WHERE `filename` = ?');
            $stmt->execute([$filename]);
            if ($stmt->fetch() !== false) {
                $this->log("SQL migration already applied, skipping: {$filename}", 'INFO');
                $skipped[] = $filename;
                continue;
            }

            $result = $this->executeMigration($filePath);
            if (!$result['success']) {
                return [
                    'success'     => false,
                    'applied'     => $applied,
                    'skipped'     => $skipped,
                    'failed_file' => $filename,
                    'error'       => $result['error'],
                ];
            }

            $stmt = $pdo->prepare(
                'INSERT INTO `patch_migrations` (`patch_history_id`, `filename`, `executed_at`) VALUES (?, ?, NOW())'
            );
            $stmt->execute([$patchHistoryId, $filename]);

            $applied[] = $filename;
        }

        return [
            'success'     => true,
            'applied'     => $applied,
            'skipped'     => $skipped,
            'failed_file' => null,
            'error'       => null,
        ];
    }

    /**
     * Bootstrap the patch_migrations tracking table on first use
     *
     * Creates the table (idempotent). If the table is empty AND the schema looks
     * genuinely fresh (see isFreshSchema()), backfills from database/migrations/*.sql
     * on the assumption that a fresh install's schema/*.sql already reflects the
     * latest state, so migrations would be redundant. If the table is empty but the
     * schema is NOT fresh (an existing install whose tracking table got wiped), the
     * backfill is skipped instead — every migration then runs for real via
     * executeMigrationsDirectory(), which is safe because migrations are required to
     * be idempotent. See Reusables#13 for the incident that prompted this check.
     * Runs at most once per instance via $bootstrapDone latch.
     *
     * @throws \RuntimeException if CREATE TABLE fails (e.g. DB user lacks CREATE permission)
     * @return void
     */
    private function ensureMigrationsTable(): void
    {
        if ($this->bootstrapDone) {
            return;
        }

        try {
            $pdo = $this->database->getPdo();

            $pdo->exec(self::PATCH_MIGRATIONS_DDL);

            $count = (int) $pdo->query('SELECT COUNT(*) FROM `patch_migrations`')->fetchColumn();
            if ($count > 0) {
                $this->bootstrapDone = true;
                return;
            }

            if (!$this->isFreshSchema($pdo)) {
                // patch_migrations is empty but the rest of the schema is not — this
                // is an existing installation whose tracking table was wiped (e.g. a
                // partial DB restore), not a fresh install. Backfilling here would
                // falsely mark every migration as applied without their DDL ever
                // having run, permanently hiding real drift (Reusables#13). Leave the
                // table empty instead: executeMigrationsDirectory() will run every
                // migration for real, which is safe because migrations are required
                // to be idempotent (see CLAUDE.md SQL Migrations rules).
                $this->log(
                    'Bootstrap: patch_migrations is empty but the schema is not — skipping backfill, migrations will run for real (Reusables#13)',
                    'WARNING'
                );
                $this->bootstrapDone = true;
                return;
            }

            $dir = $this->rootPath . '/database/migrations';
            if (!is_dir($dir)) {
                $this->log('Bootstrap: patch_migrations table created (no database/migrations/ directory to backfill from)', 'INFO');
                $this->bootstrapDone = true;
                return;
            }

            $files = glob($dir . '/*.sql');
            if ($files === false) {
                $files = [];
            }
            sort($files, SORT_STRING);

            $stmt = $pdo->prepare(
                'INSERT IGNORE INTO `patch_migrations` (`filename`, `executed_at`) VALUES (?, NOW())'
            );
            foreach ($files as $filePath) {
                $stmt->execute([basename($filePath)]);
            }

            $this->log(
                'Bootstrap: patch_migrations table created and backfilled with ' . count($files) . ' existing migration(s)',
                'INFO'
            );

            $this->bootstrapDone = true;
        } catch (\Throwable $e) {
            $this->log('Bootstrap failed: ' . $e->getMessage(), 'ERROR');
            throw new \RuntimeException('patch_migrations bootstrap failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Heuristic for "is this a brand-new, empty database" vs. "an existing
     * installation whose patch_migrations table happens to be empty"
     *
     * Checks whether any OTHER base table already exists in the current schema.
     * Project-agnostic on purpose (this module is used across multiple products
     * with entirely different schemas) — it can't check for one specific
     * expected table, only for "is there any schema here at all besides the
     * tracking table this method just created".
     *
     * @param \PDO $pdo
     * @return bool True if patch_migrations is the only base table present
     */
    private function isFreshSchema(\PDO $pdo): bool
    {
        $stmt = $pdo->query(
            "SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_TYPE = 'BASE TABLE'
               AND TABLE_NAME != 'patch_migrations'"
        );

        return ((int) $stmt->fetchColumn()) === 0;
    }

    /**
     * Execute a SQL migration file
     *
     * Runs individual SQL statements separated by semicolons. Respects string
     * literals and comments when splitting. Disables FK checks during execution.
     *
     * @param string $sqlFilePath Path to migration.sql
     * @return array{success: bool, executed_count: int, total_count: int, error: ?string}
     */
    public function executeMigration(string $sqlFilePath): array
    {
        if (!file_exists($sqlFilePath)) {
            return ['success' => true, 'executed_count' => 0, 'total_count' => 0, 'error' => null];
        }

        $sqlContent = file_get_contents($sqlFilePath);
        if (empty(trim($sqlContent))) {
            return ['success' => true, 'executed_count' => 0, 'total_count' => 0, 'error' => null];
        }

        $statements = $this->parseSqlStatements($sqlContent);
        $totalCount = count($statements);
        $executedCount = 0;

        $pdo = $this->database->getPdo();

        // Disable FK checks for the duration of migration
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

        foreach ($statements as $statement) {
            try {
                $pdo->exec($statement);
                $executedCount++;
            } catch (\PDOException $e) {
                $this->log(
                    "SQL migration failed at statement {$executedCount}/{$totalCount}: " . $e->getMessage(),
                    'ERROR'
                );
                $this->log("Failed SQL: " . substr($statement, 0, 500), 'DEBUG');

                // Re-enable FK checks before returning
                $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

                return [
                    'success' => false,
                    'executed_count' => $executedCount,
                    'total_count' => $totalCount,
                    'error' => $e->getMessage(),
                ];
            }
        }

        // Re-enable FK checks
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

        return ['success' => true, 'executed_count' => $executedCount, 'total_count' => $totalCount, 'error' => null];
    }

    /**
     * Parse SQL content into individual statements
     *
     * Processes the SQL content line-by-line to handle DELIMITER directives, then
     * character-by-character to split on the active terminator while respecting
     * single-quoted and double-quoted string literals and -- line comments.
     * Standard block comments (/* ... *\/) are stripped before processing.
     *
     * Supports arbitrary terminators: ;, //, $$, ;;, END_OF_PROC, etc.
     * This allows migration.sql files to define stored procedures and triggers.
     *
     * Known limitation: MySQL conditional comments (/*!50000 ... *\/) are treated
     * as regular block comments and stripped. Do not use them in patch migrations.
     *
     * @param string $sql Raw SQL content
     * @return string[] List of individual SQL statements ready for execution
     */
    public function parseSqlStatements(string $sql): array
    {
        // Remove standard block comments — but preserve MySQL conditional comments /*!...*/
        $sql = (string) preg_replace('/\/\*(?!\!)[^*]*(?:\*(?!\/)[^*]*)*\*\//s', '', $sql);

        $delimiter = ';';
        $pending   = '';
        $collected = [];

        foreach (explode("\n", $sql) as $line) {
            // DELIMITER directive must appear at the start of a line (after optional whitespace)
            if (preg_match('/^\s*DELIMITER\s+(\S+)\s*$/i', $line, $m)) {
                // Flush any accumulated partial statement before the delimiter changes
                $flushed = trim($pending);
                if ($flushed !== '') {
                    $collected[] = $flushed;
                    $pending = '';
                }
                $delimiter = $m[1];
                continue;
            }

            $pending .= $line . "\n";

            // Extract all complete statements ending with the current delimiter
            $extracted = $this->splitOnDelimiter($pending, $delimiter, $pending);
            $collected = array_merge($collected, $extracted);
        }

        // Handle any trailing content not followed by a delimiter
        $flushed = trim($pending);
        if ($flushed !== '') {
            $delimLen = strlen($delimiter);
            if ($delimLen > 0 && substr($flushed, -$delimLen) === $delimiter) {
                $flushed = rtrim(substr($flushed, 0, -$delimLen));
            }
            if ($flushed !== '') {
                $collected[] = $flushed;
            }
        }

        return $collected;
    }

    /**
     * Split a buffer on the given delimiter, respecting string literals and line comments
     *
     * Returns an array of complete statements (delimiter stripped). The text after
     * the last complete statement is passed back via $remaining.
     *
     * @param string $buffer    Input text (may span multiple lines)
     * @param string $delimiter Active statement terminator
     * @param string $remaining Output: content after the last complete statement
     * @return string[] Complete statements with delimiter stripped
     */
    private function splitOnDelimiter(string $buffer, string $delimiter, string &$remaining): array
    {
        $statements    = [];
        $current       = '';
        $inSingleQuote = false;
        $inDoubleQuote = false;
        $length        = strlen($buffer);
        $delimLen      = strlen($delimiter);

        for ($i = 0; $i < $length; $i++) {
            $char     = $buffer[$i];
            $nextChar = $buffer[$i + 1] ?? '';

            // Skip -- line comments outside string literals
            if (!$inSingleQuote && !$inDoubleQuote && $char === '-' && $nextChar === '-') {
                $eol = strpos($buffer, "\n", $i);
                if ($eol === false) {
                    $current .= substr($buffer, $i);
                    $i = $length;
                    break;
                }
                $current .= substr($buffer, $i, $eol - $i + 1);
                $i = $eol;
                continue;
            }

            // Track single-quoted strings (handle '' escape)
            if ($char === "'" && !$inDoubleQuote) {
                if ($inSingleQuote && $nextChar === "'") {
                    $current .= "''";
                    $i++;
                    continue;
                }
                $inSingleQuote = !$inSingleQuote;
            } elseif ($char === '"' && !$inSingleQuote) {
                $inDoubleQuote = !$inDoubleQuote;
            }

            // Check for delimiter match outside string literals
            if (!$inSingleQuote && !$inDoubleQuote && $delimLen > 0
                && substr($buffer, $i, $delimLen) === $delimiter
            ) {
                $trimmed = trim($current);
                if ($trimmed !== '') {
                    $statements[] = $trimmed;
                }
                $current = '';
                $i += $delimLen - 1;
                continue;
            }

            $current .= $char;
        }

        $remaining = $current;

        return $statements;
    }

    /**
     * Log a message if logger is available
     *
     * @param string $message Log message
     * @param string $level Log level
     * @return void
     */
    private function log(string $message, string $level = 'INFO'): void
    {
        if ($this->logger !== null) {
            $this->logger->log($message, $level);
        }
    }
}