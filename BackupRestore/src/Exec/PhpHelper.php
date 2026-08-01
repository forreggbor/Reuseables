<?php

declare(strict_types=1);

/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * PhpHelper
 *
 * Pure PHP implementations for all backup/restore operations.
 * Uses PDO for database operations, PharData for archive operations,
 * and native PHP file functions for filesystem operations.
 *
 * This file contains NO exec() calls, NO shell command construction,
 * and NO malware-triggering patterns. Safe for shared hosting environments.
 *
 * @see ExecHelper Facade that delegates to this class or ShellHelper
 * @see ShellHelper Exec-mode implementations (faster, requires exec())
 */

namespace BackupRestore\Exec;

class PhpHelper
{
    /** @var array<string, \PDO> Cached PDO connections keyed by credentials hash */
    private static array $pdoCache = [];

    // ──────────────────────────────────────────────────────────
    //  PDO Connection Management
    // ──────────────────────────────────────────────────────────

    /**
     * Create a PDO connection from credentials array.
     *
     * Connections are cached by a hash of credentials + database name
     * so that repeated calls with the same parameters reuse the connection.
     *
     * @param array $credentials Database credentials (host, port, username, password, database)
     * @param string|null $database Override database name (null = use credentials['database'], empty string = no database)
     * @return \PDO PDO connection instance
     * @throws \PDOException On connection failure
     */
    private static function createPdoConnection(array $credentials, ?string $database = null): \PDO
    {
        $db = $database ?? $credentials['database'];
        $cacheKey = md5($credentials['host'] . ':' . $credentials['port'] . ':' . $credentials['username'] . ':' . $db);

        if (isset(self::$pdoCache[$cacheKey])) {
            return self::$pdoCache[$cacheKey];
        }

        $dsn = 'mysql:host=' . $credentials['host'] . ';port=' . $credentials['port'];
        if ($db !== '') {
            $dsn .= ';dbname=' . $db;
        }
        $dsn .= ';charset=utf8mb4';

        $pdo = new \PDO($dsn, $credentials['username'], $credentials['password'], [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_NUM,
            \PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
        ]);

        self::$pdoCache[$cacheKey] = $pdo;
        return $pdo;
    }

    /**
     * Create an unbuffered PDO connection for streaming large result sets.
     *
     * Returns a fresh (non-cached) PDO connection with buffered queries disabled.
     * The caller is responsible for iterating the entire result before executing new queries.
     *
     * @param array $credentials Database credentials
     * @param string|null $database Override database name
     * @return \PDO Unbuffered PDO connection
     * @throws \PDOException On connection failure
     */
    private static function createUnbufferedPdoConnection(array $credentials, ?string $database = null): \PDO
    {
        $db = $database ?? $credentials['database'];
        $dsn = 'mysql:host=' . $credentials['host'] . ';port=' . $credentials['port'];
        if ($db !== '') {
            $dsn .= ';dbname=' . $db;
        }
        $dsn .= ';charset=utf8mb4';

        return new \PDO($dsn, $credentials['username'], $credentials['password'], [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_NUM,
            \PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false,
        ]);
    }

    /**
     * Clear the cached PDO connections.
     *
     * Should be called when connections to databases being dropped/replaced
     * need to be released.
     *
     * @return void
     */
    public static function clearPdoCache(): void
    {
        self::$pdoCache = [];
    }

    // ──────────────────────────────────────────────────────────
    //  MySQL Operations (PDO)
    // ──────────────────────────────────────────────────────────

    /**
     * Execute a SELECT-like MySQL query via PDO and return result rows.
     *
     * @param string $sql SQL query to execute
     * @param array $credentials Database credentials
     * @param string|null $database Database name (null = use credentials['database'])
     * @return array{success: bool, rows: array<int, array>, error: string|null}
     */
    public static function mysqlQuery(string $sql, array $credentials, ?string $database = null): array
    {
        try {
            $db = $database ?? $credentials['database'];
            $pdo = self::createPdoConnection($credentials, $db);
            $stmt = $pdo->query($sql);
            $rows = $stmt->fetchAll(\PDO::FETCH_NUM);

            return ['success' => true, 'rows' => $rows, 'error' => null];
        } catch (\PDOException $e) {
            return ['success' => false, 'rows' => [], 'error' => $e->getMessage()];
        }
    }

    /**
     * Execute a non-SELECT MySQL statement via PDO.
     *
     * Handles compound statements (multiple statements separated by semicolons)
     * by splitting and executing them individually.
     *
     * @param string $sql SQL statement(s)
     * @param array $credentials Database credentials
     * @param string|null $database Database name
     * @return array{success: bool, error: string|null}
     */
    public static function mysqlExec(string $sql, array $credentials, ?string $database = null): array
    {
        try {
            $db = $database ?? $credentials['database'];
            $pdo = self::createPdoConnection($credentials, $db);

            // Split compound statements and execute individually
            $statements = self::splitStatements($sql);
            foreach ($statements as $stmt) {
                $stmt = trim($stmt);
                if ($stmt !== '') {
                    $pdo->exec($stmt);
                }
            }

            return ['success' => true, 'error' => null];
        } catch (\PDOException $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Split SQL string into individual statements.
     *
     * Simple semicolon-based splitting that respects quoted strings.
     * For complex DELIMITER handling, use mysqlImport() instead.
     *
     * @param string $sql SQL string potentially containing multiple statements
     * @return array<string> Individual statements
     */
    private static function splitStatements(string $sql): array
    {
        $statements = [];
        $current = '';
        $inSingleQuote = false;
        $inDoubleQuote = false;
        $inBacktick = false;
        $escaped = false;

        for ($i = 0, $len = strlen($sql); $i < $len; $i++) {
            $char = $sql[$i];

            if ($escaped) {
                $current .= $char;
                $escaped = false;
                continue;
            }

            if ($char === '\\') {
                $current .= $char;
                $escaped = true;
                continue;
            }

            if ($char === "'" && !$inDoubleQuote && !$inBacktick) {
                $inSingleQuote = !$inSingleQuote;
            } elseif ($char === '"' && !$inSingleQuote && !$inBacktick) {
                $inDoubleQuote = !$inDoubleQuote;
            } elseif ($char === '`' && !$inSingleQuote && !$inDoubleQuote) {
                $inBacktick = !$inBacktick;
            }

            if ($char === ';' && !$inSingleQuote && !$inDoubleQuote && !$inBacktick) {
                $stmt = trim($current);
                if ($stmt !== '') {
                    $statements[] = $stmt;
                }
                $current = '';
                continue;
            }

            $current .= $char;
        }

        $remaining = trim($current);
        if ($remaining !== '') {
            $statements[] = $remaining;
        }

        return $statements;
    }

    // ──────────────────────────────────────────────────────────
    //  DEFINER Stripping (preg_replace)
    // ──────────────────────────────────────────────────────────

    /**
     * Strip DEFINER clauses from a SQL file via preg_replace.
     *
     * Uses streaming approach for files > 10MB to avoid memory issues.
     *
     * @param string $filePath Path to SQL file
     * @return bool True on success
     */
    public static function stripDefinerFromFile(string $filePath): bool
    {
        if (!file_exists($filePath) || filesize($filePath) === 0) {
            Logger::log("[PhpHelper] stripDefiner: File not found or empty: {$filePath}", 'WARNING');
            return false;
        }

        $fileSize = filesize($filePath);

        // Pattern 1: /*!50017 DEFINER=`root`@`localhost`*/  (conditional comment form)
        $pattern1 = '/\/\*![0-9]+ DEFINER=`[^`]*`@`[^`]*`\s*\*\//';
        // Pattern 2: DEFINER=`root`@`localhost`  (bare form)
        $pattern2 = '/DEFINER=`[^`]*`@`[^`]*`\s*/';

        if ($fileSize <= 10 * 1024 * 1024) {
            // Small file: load entirely
            $content = file_get_contents($filePath);
            if ($content === false) {
                return false;
            }
            $content = preg_replace($pattern1, '', $content);
            $content = preg_replace($pattern2, '', $content);
            file_put_contents($filePath, $content);
        } else {
            // Large file: stream line by line via temp file
            $tempPath = $filePath . '.tmp_definer_' . getmypid();
            $readHandle = fopen($filePath, 'r');
            $writeHandle = fopen($tempPath, 'w');

            if (!$readHandle || !$writeHandle) {
                if ($readHandle) fclose($readHandle);
                if ($writeHandle) fclose($writeHandle);
                return false;
            }

            while (($line = fgets($readHandle)) !== false) {
                $line = preg_replace($pattern1, '', $line);
                $line = preg_replace($pattern2, '', $line);
                fwrite($writeHandle, $line);
            }

            fclose($readHandle);
            fclose($writeHandle);

            // Atomic replace
            rename($tempPath, $filePath);
        }

        return self::validateFileAfterDefinerStrip($filePath);
    }

    /**
     * Validate file still exists and is non-empty after DEFINER stripping.
     *
     * @param string $filePath Path to SQL file
     * @return bool True if file is valid
     */
    private static function validateFileAfterDefinerStrip(string $filePath): bool
    {
        if (!file_exists($filePath) || filesize($filePath) === 0) {
            Logger::log("[PhpHelper] File became empty after DEFINER stripping: {$filePath}", 'WARNING');
            return false;
        }
        return true;
    }

    // ──────────────────────────────────────────────────────────
    //  Gzip Integrity Test
    // ──────────────────────────────────────────────────────────

    /**
     * Test gzip file integrity by reading through the file.
     *
     * Opens with gzopen and reads all chunks. If any read fails or
     * produces an error, the file is considered corrupt.
     *
     * @param string $filePath Path to gzip file
     * @return bool True if file is valid gzip
     */
    public static function gzipTest(string $filePath): bool
    {
        if (!file_exists($filePath)) {
            return false;
        }

        $gz = @gzopen($filePath, 'rb');
        if ($gz === false) {
            return false;
        }

        while (!gzeof($gz)) {
            $data = @gzread($gz, 8192);
            if ($data === false) {
                gzclose($gz);
                return false;
            }
        }

        gzclose($gz);
        return true;
    }

    // ──────────────────────────────────────────────────────────
    //  Tar Archive Operations (PharData)
    // ──────────────────────────────────────────────────────────

    /**
     * List contents of a tar.gz archive via PharData.
     *
     * @param string $archivePath Path to .tar.gz or .tgz file
     * @return array{success: bool, files: array<string>, error: string|null}
     */
    public static function tarList(string $archivePath): array
    {
        if (!file_exists($archivePath)) {
            return ['success' => false, 'files' => [], 'error' => 'Archive file not found'];
        }

        try {
            $phar = new \PharData($archivePath);
            $files = [];
            $iterator = new \RecursiveIteratorIterator($phar);

            foreach ($iterator as $file) {
                $files[] = './' . $file->getPathname();
            }

            return ['success' => true, 'files' => $files, 'error' => null];
        } catch (\Exception $e) {
            return ['success' => false, 'files' => [], 'error' => $e->getMessage()];
        }
    }

    /**
     * Count files in a tar archive via PharData iteration.
     *
     * @param string $archivePath Path to tar file (uncompressed)
     * @return int Number of entries
     */
    public static function tarCount(string $archivePath): int
    {
        if (!file_exists($archivePath)) {
            return 0;
        }

        try {
            $phar = new \PharData($archivePath);
            $count = 0;
            $iterator = new \RecursiveIteratorIterator($phar);
            foreach ($iterator as $_) {
                $count++;
            }
            return $count;
        } catch (\Exception $e) {
            Logger::log("[PhpHelper] tarCount failed: " . $e->getMessage(), 'WARNING');
            return 0;
        }
    }

    /**
     * Create a tar archive via PharData.
     *
     * @param string $outputPath Destination path for tar file
     * @param string $sourceDir Source directory to archive
     * @param array $excludes Relative paths to exclude (e.g., ['storage/backup', 'node_modules'])
     * @param array|null $includes Specific relative paths to include (null = everything)
     * @return array{success: bool, error: string|null}
     */
    public static function tarCreate(string $outputPath, string $sourceDir, array $excludes = [], ?array $includes = null): array
    {
        try {
            // Ensure the output file doesn't exist (PharData requires this)
            if (file_exists($outputPath)) {
                unlink($outputPath);
            }

            $phar = new \PharData($outputPath);
            $sourceDir = rtrim(realpath($sourceDir), '/');

            // Build exclude patterns (normalize to relative paths without leading ./)
            $normalizedExcludes = array_map(function ($path) {
                return trim($path, './');
            }, $excludes);

            // Build include patterns
            $normalizedIncludes = null;
            if ($includes !== null && !empty($includes)) {
                $normalizedIncludes = array_map(function ($path) {
                    return trim($path, './');
                }, $includes);
            }

            $directoryIterator = new \RecursiveDirectoryIterator(
                $sourceDir,
                \RecursiveDirectoryIterator::SKIP_DOTS | \RecursiveDirectoryIterator::FOLLOW_SYMLINKS
            );

            $filterIterator = new \RecursiveCallbackFilterIterator(
                $directoryIterator,
                function (\SplFileInfo $file, string $key, \RecursiveDirectoryIterator $iterator) use ($sourceDir, $normalizedExcludes, $normalizedIncludes) {
                    $relativePath = ltrim(str_replace($sourceDir, '', $file->getPathname()), '/');

                    // Check excludes
                    foreach ($normalizedExcludes as $exclude) {
                        if (str_starts_with($relativePath, $exclude)) {
                            return false;
                        }
                    }

                    // Check includes (if specified)
                    if ($normalizedIncludes !== null) {
                        // For directories, allow if any include path starts with this dir
                        if ($file->isDir()) {
                            foreach ($normalizedIncludes as $include) {
                                if (str_starts_with($include, $relativePath) || str_starts_with($relativePath, $include)) {
                                    return true;
                                }
                            }
                            return false;
                        }
                        // For files, check if under any included path
                        foreach ($normalizedIncludes as $include) {
                            if (str_starts_with($relativePath, $include)) {
                                return true;
                            }
                        }
                        return false;
                    }

                    return true;
                }
            );

            $flatIterator = new \RecursiveIteratorIterator($filterIterator);
            $phar->buildFromIterator($flatIterator, $sourceDir);

            return ['success' => true, 'error' => null];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Create a compressed tar.gz archive via PharData.
     *
     * Creates a .tar first, compresses to .tar.gz, then renames if needed
     * and cleans up intermediate files.
     *
     * @param string $outputPath Destination path for .tar.gz or .tgz file
     * @param string $sourceDir Source directory to archive
     * @return array{success: bool, error: string|null}
     */
    public static function tarCreateGz(string $outputPath, string $sourceDir): array
    {
        try {
            // PharData::compress() creates .tar.gz from .tar
            // We create a temp .tar first, then compress
            $tempTarPath = $outputPath . '.tmp_' . getmypid() . '.tar';

            if (file_exists($tempTarPath)) {
                unlink($tempTarPath);
            }

            $phar = new \PharData($tempTarPath);
            $sourceDir = rtrim(realpath($sourceDir), '/');

            $directoryIterator = new \RecursiveDirectoryIterator(
                $sourceDir,
                \RecursiveDirectoryIterator::SKIP_DOTS | \RecursiveDirectoryIterator::FOLLOW_SYMLINKS
            );
            $flatIterator = new \RecursiveIteratorIterator($directoryIterator);
            $phar->buildFromIterator($flatIterator, $sourceDir);

            // Compress to .tar.gz
            $compressedPhar = $phar->compress(\Phar::GZ);
            $compressedPath = $compressedPhar->getPath();

            // Clean up the intermediate .tar
            unset($phar);
            if (file_exists($tempTarPath)) {
                unlink($tempTarPath);
            }

            // Rename compressed file to desired output path
            if ($compressedPath !== $outputPath) {
                if (file_exists($outputPath)) {
                    unlink($outputPath);
                }
                rename($compressedPath, $outputPath);
            }

            return ['success' => true, 'error' => null];
        } catch (\Exception $e) {
            // Cleanup on failure
            if (isset($tempTarPath) && file_exists($tempTarPath)) {
                unlink($tempTarPath);
            }
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Extract a tar.gz archive via PharData.
     *
     * When a pattern is provided, iterates archive entries and extracts
     * only those matching the pattern.
     *
     * @param string $archivePath Path to tar.gz file
     * @param string $destDir Destination directory
     * @param string|null $pattern Optional wildcard pattern for selective extraction (e.g., './database/*')
     * @return array{success: bool, error: string|null}
     */
    public static function tarExtract(string $archivePath, string $destDir, ?string $pattern = null): array
    {
        if (!file_exists($archivePath)) {
            return ['success' => false, 'error' => 'Archive file not found'];
        }

        try {
            $phar = new \PharData($archivePath);

            if (!is_dir($destDir)) {
                mkdir($destDir, 0775, true);
            }

            if ($pattern === null) {
                // Full extraction — enumerate and validate every member's
                // destination BEFORE PharData writes anything (see
                // ShellHelper::tarExtract()'s comment: once written, a
                // `../`-escaping member has already done its damage; a
                // post-extraction scan could only ever discover it).
                $allMembers = [];
                foreach (new \RecursiveIteratorIterator($phar) as $file) {
                    $allMembers[] = $file->getPathname();
                }
                try {
                    \BackupRestore\PathGuard::assertArchiveMembersContained($allMembers, $destDir);
                } catch (\RuntimeException $e) {
                    return ['success' => false, 'error' => 'Refusing to extract unsafe archive: ' . $e->getMessage()];
                }

                $phar->extractTo($destDir, null, true);
            } else {
                // Selective extraction: convert wildcard pattern to regex and filter
                $patternDir = rtrim(str_replace(['*', './'], ['', ''], $pattern), '/');
                $matchingFiles = [];

                $iterator = new \RecursiveIteratorIterator($phar);
                foreach ($iterator as $file) {
                    $relativePath = $file->getPathname();
                    if (str_starts_with($relativePath, $patternDir . '/') || $relativePath === $patternDir) {
                        $matchingFiles[] = $relativePath;
                    }
                }

                if (!empty($matchingFiles)) {
                    try {
                        \BackupRestore\PathGuard::assertArchiveMembersContained($matchingFiles, $destDir);
                    } catch (\RuntimeException $e) {
                        return ['success' => false, 'error' => 'Refusing to extract unsafe archive: ' . $e->getMessage()];
                    }

                    $phar->extractTo($destDir, $matchingFiles, true);
                }
            }

            return ['success' => true, 'error' => null];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Extract an uncompressed tar archive via PharData.
     *
     * @param string $archivePath Path to .tar file
     * @param string $destDir Destination directory
     * @return array{success: bool, error: string|null}
     */
    public static function tarExtractUncompressed(string $archivePath, string $destDir): array
    {
        if (!file_exists($archivePath)) {
            return ['success' => false, 'error' => 'Archive file not found'];
        }

        try {
            $phar = new \PharData($archivePath);
            if (!is_dir($destDir)) {
                mkdir($destDir, 0775, true);
            }

            $allMembers = [];
            foreach (new \RecursiveIteratorIterator($phar) as $file) {
                $allMembers[] = $file->getPathname();
            }
            try {
                \BackupRestore\PathGuard::assertArchiveMembersContained($allMembers, $destDir);
            } catch (\RuntimeException $e) {
                return ['success' => false, 'error' => 'Refusing to extract unsafe archive: ' . $e->getMessage()];
            }

            $phar->extractTo($destDir, null, true);
            return ['success' => true, 'error' => null];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // ──────────────────────────────────────────────────────────
    //  MySQL Dump (PDO)
    // ──────────────────────────────────────────────────────────

    /**
     * Create database dump via pure PHP using PDO.
     *
     * Generates a mysqldump-compatible SQL file by querying table structures,
     * data, triggers, routines, and events via PDO.
     *
     * Uses unbuffered queries for data export to handle large tables
     * without running out of memory.
     *
     * @param array $credentials Database credentials
     * @param string $outputPath Destination path for SQL dump file
     * @param bool $routines Include stored procedures and functions
     * @param bool $triggers Include triggers
     * @param bool $events Include scheduled events
     * @param array|null $tables Restrict dump to these table names; null = all tables
     * @return array{success: bool, tables_count: int, error: string|null}
     */
    public static function mysqldump(array $credentials, string $outputPath, bool $routines = true, bool $triggers = true, bool $events = true, ?array $tables = null): array
    {
        try {
            $dbName = $credentials['database'];
            $metaPdo = self::createPdoConnection($credentials);
            $dataPdo = self::createUnbufferedPdoConnection($credentials);

            $handle = fopen($outputPath, 'w');
            if (!$handle) {
                return ['success' => false, 'tables_count' => 0, 'error' => 'Cannot open output file for writing'];
            }

            // Header
            fwrite($handle, "-- PHP-generated database dump\n");
            fwrite($handle, "-- Database: {$dbName}\n");
            fwrite($handle, "-- Date: " . date('Y-m-d H:i:s') . "\n\n");
            fwrite($handle, "/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;\n");
            fwrite($handle, "/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;\n");
            fwrite($handle, "/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;\n");
            fwrite($handle, "/*!40101 SET NAMES utf8mb4 */;\n");
            fwrite($handle, "/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;\n");
            fwrite($handle, "/*!40103 SET TIME_ZONE='+00:00' */;\n");
            fwrite($handle, "/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;\n");
            fwrite($handle, "/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;\n");
            fwrite($handle, "/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;\n");
            fwrite($handle, "/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;\n\n");

            // Get tables — optionally restricted to a named subset
            $allowedSet = $tables !== null ? array_flip($tables) : null;
            $stmt = $metaPdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
            $allTables = $stmt->fetchAll(\PDO::FETCH_NUM);
            if ($allowedSet !== null) {
                $allTables = array_filter($allTables, fn($r) => isset($allowedSet[$r[0]]));
                $allTables = array_values($allTables);
            }
            $tableRows = $allTables;
            $tablesCount = count($tableRows);

            // Dump each table
            foreach ($tableRows as $tableRow) {
                $tableName = $tableRow[0];

                // Table structure
                fwrite($handle, "--\n-- Table structure for table `{$tableName}`\n--\n\n");
                fwrite($handle, "DROP TABLE IF EXISTS `{$tableName}`;\n");

                $createStmt = $metaPdo->query("SHOW CREATE TABLE `{$tableName}`");
                $createRow = $createStmt->fetch(\PDO::FETCH_NUM);
                fwrite($handle, "/*!40101 SET @saved_cs_client     = @@character_set_client */;\n");
                fwrite($handle, "/*!40101 SET character_set_client = utf8 */;\n");
                fwrite($handle, $createRow[1] . ";\n");
                fwrite($handle, "/*!40101 SET character_set_client = @saved_cs_client */;\n\n");

                // Get column info for proper value formatting
                $colStmt = $metaPdo->query("SHOW COLUMNS FROM `{$tableName}`");
                $columns = $colStmt->fetchAll(\PDO::FETCH_ASSOC);
                $binaryColumns = [];
                $numericColumns = [];
                foreach ($columns as $idx => $col) {
                    $type = strtolower($col['Type']);
                    if (preg_match('/^(binary|varbinary|blob|tinyblob|mediumblob|longblob|bit)/', $type)) {
                        $binaryColumns[$idx] = true;
                    } elseif (preg_match('/^(tinyint|smallint|mediumint|int|integer|bigint|decimal|numeric|float|double|real|year)\b/', $type)) {
                        $numericColumns[$idx] = true;
                    }
                }

                // Data dump using unbuffered queries
                fwrite($handle, "--\n-- Dumping data for table `{$tableName}`\n--\n\n");
                fwrite($handle, "LOCK TABLES `{$tableName}` WRITE;\n");
                fwrite($handle, "/*!40000 ALTER TABLE `{$tableName}` DISABLE KEYS */;\n");

                $dataStmt = $dataPdo->query("SELECT * FROM `{$tableName}`");
                $batch = [];
                $batchCount = 0;
                $columnNames = [];

                while ($row = $dataStmt->fetch(\PDO::FETCH_NUM)) {
                    if (empty($columnNames)) {
                        $colCount = count($row);
                        for ($i = 0; $i < $colCount; $i++) {
                            $colMeta = $dataStmt->getColumnMeta($i);
                            $columnNames[] = '`' . $colMeta['name'] . '`';
                        }
                    }

                    // Format values. Only emit a bare (unquoted) literal for columns whose
                    // SCHEMA type is genuinely numeric ($numericColumns, from SHOW COLUMNS) —
                    // NOT merely because the runtime value happens to look numeric
                    // (is_numeric()). A VARCHAR/CHAR column holding "0123", "1e3", or "+5"
                    // would otherwise be written unquoted and come back as the integer
                    // 123/1000/5 on import, silently corrupting the data (e.g. zip/postal
                    // codes with leading zeros). Everything else is safely quoted.
                    $values = [];
                    foreach ($row as $idx => $value) {
                        if ($value === null) {
                            $values[] = 'NULL';
                        } elseif (isset($binaryColumns[$idx])) {
                            $values[] = '0x' . bin2hex($value);
                        } elseif (isset($numericColumns[$idx])) {
                            $values[] = $value;
                        } else {
                            $values[] = $metaPdo->quote($value);
                        }
                    }

                    $batch[] = '(' . implode(',', $values) . ')';
                    $batchCount++;

                    // Flush batch every 500 rows
                    if ($batchCount >= 500) {
                        fwrite($handle, 'INSERT INTO `' . $tableName . '` VALUES ' . implode(',', $batch) . ";\n");
                        $batch = [];
                        $batchCount = 0;
                    }
                }

                // Flush remaining rows
                if (!empty($batch)) {
                    fwrite($handle, 'INSERT INTO `' . $tableName . '` VALUES ' . implode(',', $batch) . ";\n");
                }

                fwrite($handle, "/*!40000 ALTER TABLE `{$tableName}` ENABLE KEYS */;\n");
                fwrite($handle, "UNLOCK TABLES;\n\n");
            }

            // Views
            $viewStmt = $metaPdo->query("SHOW FULL TABLES WHERE Table_type = 'VIEW'");
            $views = $viewStmt->fetchAll(\PDO::FETCH_NUM);
            foreach ($views as $viewRow) {
                $viewName = $viewRow[0];
                fwrite($handle, "--\n-- View: `{$viewName}`\n--\n\n");
                $createView = $metaPdo->query("SHOW CREATE VIEW `{$viewName}`");
                $viewDdl = $createView->fetch(\PDO::FETCH_NUM);
                $viewSql = preg_replace('/\/\*![0-9]+ DEFINER=`[^`]*`@`[^`]*`\s*\*\//', '', $viewDdl[1]);
                $viewSql = preg_replace('/DEFINER=`[^`]*`@`[^`]*`\s*/', '', $viewSql);
                fwrite($handle, "/*!50001 DROP VIEW IF EXISTS `{$viewName}` */;\n");
                fwrite($handle, $viewSql . ";\n\n");
            }

            // Triggers
            if ($triggers) {
                $trigStmt = $metaPdo->query("SHOW TRIGGERS FROM `{$dbName}`");
                $triggerList = $trigStmt->fetchAll(\PDO::FETCH_ASSOC);
                foreach ($triggerList as $trig) {
                    $trigName = $trig['Trigger'];
                    fwrite($handle, "--\n-- Trigger: `{$trigName}`\n--\n\n");
                    $createTrig = $metaPdo->query("SHOW CREATE TRIGGER `{$trigName}`");
                    $trigDdl = $createTrig->fetch(\PDO::FETCH_ASSOC);
                    $trigSql = $trigDdl['SQL Original Statement'] ?? '';
                    $trigSql = preg_replace('/DEFINER=`[^`]*`@`[^`]*`\s*/', '', $trigSql);
                    fwrite($handle, "/*!50003 DROP TRIGGER IF EXISTS `{$trigName}` */;\n");
                    fwrite($handle, "DELIMITER ;;\n");
                    fwrite($handle, "/*!50003 " . $trigSql . " */;;\n");
                    fwrite($handle, "DELIMITER ;\n\n");
                }
            }

            // Routines (stored procedures and functions)
            if ($routines) {
                // Procedures
                $procStmt = $metaPdo->query("SHOW PROCEDURE STATUS WHERE Db = " . $metaPdo->quote($dbName));
                $procedures = $procStmt->fetchAll(\PDO::FETCH_ASSOC);
                foreach ($procedures as $proc) {
                    $procName = $proc['Name'];
                    fwrite($handle, "--\n-- Procedure: `{$procName}`\n--\n\n");
                    $createProc = $metaPdo->query("SHOW CREATE PROCEDURE `{$procName}`");
                    $procDdl = $createProc->fetch(\PDO::FETCH_ASSOC);
                    $procSql = $procDdl['Create Procedure'] ?? '';
                    $procSql = preg_replace('/DEFINER=`[^`]*`@`[^`]*`\s*/', '', $procSql);
                    fwrite($handle, "/*!50003 DROP PROCEDURE IF EXISTS `{$procName}` */;\n");
                    fwrite($handle, "DELIMITER ;;\n");
                    fwrite($handle, $procSql . " ;;\n");
                    fwrite($handle, "DELIMITER ;\n\n");
                }

                // Functions
                $funcStmt = $metaPdo->query("SHOW FUNCTION STATUS WHERE Db = " . $metaPdo->quote($dbName));
                $functions = $funcStmt->fetchAll(\PDO::FETCH_ASSOC);
                foreach ($functions as $func) {
                    $funcName = $func['Name'];
                    fwrite($handle, "--\n-- Function: `{$funcName}`\n--\n\n");
                    $createFunc = $metaPdo->query("SHOW CREATE FUNCTION `{$funcName}`");
                    $funcDdl = $createFunc->fetch(\PDO::FETCH_ASSOC);
                    $funcSql = $funcDdl['Create Function'] ?? '';
                    $funcSql = preg_replace('/DEFINER=`[^`]*`@`[^`]*`\s*/', '', $funcSql);
                    fwrite($handle, "/*!50003 DROP FUNCTION IF EXISTS `{$funcName}` */;\n");
                    fwrite($handle, "DELIMITER ;;\n");
                    fwrite($handle, $funcSql . " ;;\n");
                    fwrite($handle, "DELIMITER ;\n\n");
                }
            }

            // Events
            if ($events) {
                $eventStmt = $metaPdo->query("SHOW EVENTS FROM `{$dbName}`");
                $eventList = $eventStmt->fetchAll(\PDO::FETCH_ASSOC);
                foreach ($eventList as $event) {
                    $eventName = $event['Name'];
                    fwrite($handle, "--\n-- Event: `{$eventName}`\n--\n\n");
                    $createEvent = $metaPdo->query("SHOW CREATE EVENT `{$eventName}`");
                    $eventDdl = $createEvent->fetch(\PDO::FETCH_ASSOC);
                    $eventSql = $eventDdl['Create Event'] ?? '';
                    $eventSql = preg_replace('/DEFINER=`[^`]*`@`[^`]*`\s*/', '', $eventSql);
                    fwrite($handle, "/*!50106 DROP EVENT IF EXISTS `{$eventName}` */;\n");
                    fwrite($handle, "DELIMITER ;;\n");
                    fwrite($handle, $eventSql . " ;;\n");
                    fwrite($handle, "DELIMITER ;\n\n");
                }
            }

            // Footer
            fwrite($handle, "/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;\n");
            fwrite($handle, "/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;\n");
            fwrite($handle, "/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;\n");
            fwrite($handle, "/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;\n");
            fwrite($handle, "/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;\n");
            fwrite($handle, "/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;\n");
            fwrite($handle, "/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;\n");
            fwrite($handle, "/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;\n\n");
            fwrite($handle, "-- Dump completed on " . date('Y-m-d H:i:s') . "\n");

            fclose($handle);

            return ['success' => true, 'tables_count' => $tablesCount, 'error' => null];
        } catch (\Exception $e) {
            if (isset($handle) && is_resource($handle)) {
                fclose($handle);
            }
            return ['success' => false, 'tables_count' => 0, 'error' => $e->getMessage()];
        }
    }

    // ──────────────────────────────────────────────────────────
    //  MySQL Import (Streaming PDO Parser)
    // ──────────────────────────────────────────────────────────

    /**
     * Import a SQL dump file via pure PHP with streaming DELIMITER-aware parser.
     *
     * Reads the SQL file line-by-line (memory-efficient for large files).
     * Handles DELIMITER directives for triggers, routines, and events.
     *
     * @param array $credentials Database credentials
     * @param string $database Target database name
     * @param string $sqlFilePath Path to SQL dump file
     * @return array{success: bool, error: string|null}
     */
    public static function mysqlImport(array $credentials, string $database, string $sqlFilePath): array
    {
        if (!file_exists($sqlFilePath)) {
            return ['success' => false, 'error' => 'SQL file not found: ' . $sqlFilePath];
        }

        try {
            $pdo = self::createPdoConnection($credentials, $database);

            // Set session variables for import
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
            $pdo->exec("SET NAMES utf8mb4");
            $pdo->exec("SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO'");
            $pdo->exec("SET @OLD_UNIQUE_CHECKS = @@UNIQUE_CHECKS, UNIQUE_CHECKS = 0");

            $handle = fopen($sqlFilePath, 'r');
            if (!$handle) {
                return ['success' => false, 'error' => 'Cannot open SQL file for reading'];
            }

            $delimiter = ';';
            $currentStatement = '';
            $errors = [];

            while (($line = fgets($handle)) !== false) {
                $trimmedLine = trim($line);

                // Skip empty lines and standalone comments
                if ($trimmedLine === '' || str_starts_with($trimmedLine, '--')) {
                    if ($currentStatement !== '') {
                        $currentStatement .= $line;
                    }
                    continue;
                }

                // Handle DELIMITER directive
                if (preg_match('/^DELIMITER\s+(\S+)\s*$/i', $trimmedLine, $matches)) {
                    $delimiter = $matches[1];
                    continue;
                }

                $currentStatement .= $line;

                // Check if line ends with current delimiter
                if (str_ends_with($trimmedLine, $delimiter)) {
                    $stmt = substr(rtrim($currentStatement), 0, -strlen($delimiter));
                    $stmt = trim($stmt);

                    if ($stmt !== '' && !self::isCommentOnly($stmt)) {
                        try {
                            $pdo->exec($stmt);
                        } catch (\PDOException $e) {
                            // Log but continue (matching mysql CLI behavior)
                            $errors[] = $e->getMessage();
                            Logger::log("[PhpHelper] SQL import statement failed: " . mb_strimwidth($e->getMessage(), 0, 200), 'WARNING');
                        }
                    }

                    $currentStatement = '';
                }
            }

            // Handle remaining statement
            $remaining = trim($currentStatement);
            if ($remaining !== '' && !self::isCommentOnly($remaining)) {
                try {
                    $pdo->exec($remaining);
                } catch (\PDOException $e) {
                    $errors[] = $e->getMessage();
                }
            }

            fclose($handle);

            // Restore session variables
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
            $pdo->exec("SET UNIQUE_CHECKS = @OLD_UNIQUE_CHECKS");

            // Fail-fast: any per-statement failure means the import did not complete
            // cleanly. Report failure (matching the shell path's non-zero exit-code
            // semantics) so the caller triggers rollback instead of dropping the
            // pre-restore _bak_* originals on a partially-imported database.
            if (!empty($errors)) {
                $summary = mb_strimwidth($errors[0], 0, 200);
                Logger::log("[PhpHelper] SQL import FAILED with " . count($errors) . " error(s); first: " . $summary, 'ERROR');
                return [
                    'success' => false,
                    'error'   => 'SQL import failed with ' . count($errors) . ' error(s); first: ' . $summary,
                ];
            }

            return ['success' => true, 'error' => null];
        } catch (\Exception $e) {
            if (isset($handle) && is_resource($handle)) {
                fclose($handle);
            }
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Check if a SQL string contains only comments.
     *
     * @param string $sql SQL string to check
     * @return bool True if string is only comments
     */
    private static function isCommentOnly(string $sql): bool
    {
        $lines = explode("\n", $sql);
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed !== '' && !str_starts_with($trimmed, '--') && !str_starts_with($trimmed, '/*')) {
                return false;
            }
        }
        return true;
    }

    // ──────────────────────────────────────────────────────────
    //  Directory Sync (PHP)
    // ──────────────────────────────────────────────────────────

    /**
     * Synchronize directories via pure PHP.
     *
     * Phase 1: Copy all files from source to dest (create dirs, overwrite changed files).
     * Phase 2: Delete files in dest that don't exist in source (respecting excludes).
     *
     * @param string $source Source directory
     * @param string $dest Destination directory
     * @param array $excludes Relative paths to exclude from deletion (e.g., ['storage/backup'])
     * @return array{success: bool, error: string|null}
     */
    public static function syncDirectories(string $source, string $dest, array $excludes = []): array
    {
        try {
            $source = rtrim(realpath($source), '/');
            $dest = rtrim(realpath($dest) ?: $dest, '/');

            if (!is_dir($source)) {
                return ['success' => false, 'error' => 'Source directory does not exist'];
            }

            // Normalize exclude paths
            $normalizedExcludes = array_map(function ($path) {
                return trim($path, '/');
            }, $excludes);

            // Phase 1: Copy source -> dest
            $sourceFiles = self::buildFileManifest($source);
            foreach ($sourceFiles as $relativePath => $info) {
                // Check excludes
                if (self::isExcluded($relativePath, $normalizedExcludes)) {
                    continue;
                }

                $srcPath = $source . '/' . $relativePath;
                $dstPath = $dest . '/' . $relativePath;

                // Defense-in-depth: $relativePath is walked from an already-extracted
                // (already containment-checked at extraction time) tree, so this
                // should be unreachable in practice — except for a symlink placed
                // inside that tree pointing outside it, which this catches, and a
                // not-yet-existing $dstPath, which realpath()-based checks can't
                // (PathGuard::assertContained() works lexically instead).
                if (is_link($srcPath)) {
                    $real = realpath($srcPath);
                    if ($real === false || !str_starts_with($real . '/', $source . '/')) {
                        continue;
                    }
                }
                \BackupRestore\PathGuard::assertContained($dest, $dstPath);

                if ($info['type'] === 'dir') {
                    if (!is_dir($dstPath)) {
                        mkdir($dstPath, 0775, true);
                    }
                } else {
                    // Copy if dest doesn't exist or differs
                    $dstDir = dirname($dstPath);
                    if (!is_dir($dstDir)) {
                        mkdir($dstDir, 0775, true);
                    }

                    if (!file_exists($dstPath) || filesize($srcPath) !== filesize($dstPath) || filemtime($srcPath) !== filemtime($dstPath)) {
                        copy($srcPath, $dstPath);
                        touch($dstPath, filemtime($srcPath));
                    }

                    // Preserve permissions
                    $perms = fileperms($srcPath) & 0777;
                    @chmod($dstPath, $perms);
                }
            }

            // Phase 2: Delete orphans from dest
            $destFiles = self::buildFileManifest($dest);
            // Process files first (deepest first), then directories
            $filesToDelete = [];
            $dirsToDelete = [];

            foreach ($destFiles as $relativePath => $info) {
                if (self::isExcluded($relativePath, $normalizedExcludes)) {
                    continue;
                }

                if (!isset($sourceFiles[$relativePath])) {
                    if ($info['type'] === 'dir') {
                        $dirsToDelete[] = $dest . '/' . $relativePath;
                    } else {
                        $filesToDelete[] = $dest . '/' . $relativePath;
                    }
                }
            }

            // Delete orphan files
            foreach ($filesToDelete as $file) {
                @unlink($file);
            }

            // Delete orphan directories (deepest first)
            usort($dirsToDelete, function ($a, $b) {
                return strlen($b) - strlen($a);
            });
            foreach ($dirsToDelete as $dir) {
                @rmdir($dir);
            }

            return ['success' => true, 'error' => null];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Build a manifest of all files and directories under a given path.
     *
     * @param string $basePath Base directory path
     * @return array<string, array{type: string}> Map of relative paths to file info
     */
    private static function buildFileManifest(string $basePath): array
    {
        $manifest = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($basePath, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            $relativePath = ltrim(str_replace($basePath, '', $file->getPathname()), '/');
            $manifest[$relativePath] = [
                'type' => $file->isDir() ? 'dir' : 'file',
            ];
        }

        return $manifest;
    }

    /**
     * Check if a relative path is excluded.
     *
     * @param string $relativePath Path to check
     * @param array $excludes Normalized exclude patterns
     * @return bool True if path should be excluded
     */
    private static function isExcluded(string $relativePath, array $excludes): bool
    {
        foreach ($excludes as $exclude) {
            if (str_starts_with($relativePath, $exclude)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Compute the total size in bytes of a directory tree via a recursive PHP
     * scan, excluding the given relative paths. Pure-PHP fallback for
     * environments without exec() — see ShellHelper::directorySize().
     *
     * @param string $path Absolute path to the directory
     * @param array $excludes Relative paths (from $path) to exclude from the sum
     * @return int Total size in bytes (0 if the path does not exist)
     */
    public static function directorySize(string $path, array $excludes = []): int
    {
        if (!is_dir($path)) {
            return 0;
        }

        $normalizedExcludes = array_map(fn($p) => trim($p, '/'), $excludes);
        $total = 0;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            $relativePath = ltrim(str_replace($path, '', $file->getPathname()), '/');
            if (self::isExcluded($relativePath, $normalizedExcludes)) {
                continue;
            }
            if ($file->isFile()) {
                $total += $file->getSize();
            }
        }

        return $total;
    }
}
