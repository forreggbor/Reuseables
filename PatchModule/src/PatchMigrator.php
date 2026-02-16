<?php

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
 * @package PatchModule
 */
class PatchMigrator
{
    /** @var DatabaseAdapterInterface */
    private DatabaseAdapterInterface $database;

    /** @var LoggerInterface|null */
    private ?LoggerInterface $logger;

    /**
     * @param DatabaseAdapterInterface $database Database adapter (for raw PDO access)
     * @param LoggerInterface|null $logger Optional logger
     */
    public function __construct(DatabaseAdapterInterface $database, ?LoggerInterface $logger = null)
    {
        $this->database = $database;
        $this->logger = $logger;
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
     * Splits on semicolons while respecting string literals and comments.
     * Removes block comments. Does NOT support DELIMITER changes.
     *
     * @param string $sql Raw SQL content
     * @return string[] List of individual SQL statements
     */
    public function parseSqlStatements(string $sql): array
    {
        // Remove block comments
        $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);

        $statements = [];
        $current = '';
        $inSingleQuote = false;
        $inDoubleQuote = false;
        $length = strlen($sql);

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $nextChar = $sql[$i + 1] ?? '';

            // Handle line comments
            if (!$inSingleQuote && !$inDoubleQuote && $char === '-' && $nextChar === '-') {
                $eol = strpos($sql, "\n", $i);
                if ($eol === false) {
                    break;
                }
                $i = $eol;
                continue;
            }

            // Handle string literals
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

            // Split on semicolons outside strings
            if ($char === ';' && !$inSingleQuote && !$inDoubleQuote) {
                $trimmed = trim($current);
                if (!empty($trimmed)) {
                    $statements[] = $trimmed;
                }
                $current = '';
                continue;
            }

            $current .= $char;
        }

        // Last statement without trailing semicolon
        $trimmed = trim($current);
        if (!empty($trimmed)) {
            $statements[] = $trimmed;
        }

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