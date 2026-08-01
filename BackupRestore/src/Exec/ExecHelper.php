<?php

declare(strict_types=1);

/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * ExecHelper — Facade
 *
 * Thin facade that auto-selects the optimal implementation for all backup/restore
 * operations. When ShellHelper is available and exec() is enabled, delegates to
 * shell commands for maximum performance. Otherwise, falls back to PhpHelper which
 * uses pure PHP (PDO, PharData, file functions).
 *
 * Deployment:
 *   - Dedicated server: Deploy all 3 files (facade + ShellHelper + PhpHelper)
 *   - Shared hosting:   Deploy facade + PhpHelper only (omit ShellHelper.php)
 *
 * All callers (BackupEngine, RestoreEngine, ProfileService) use this facade
 * exclusively — no caller changes needed when switching deployment mode.
 */

namespace BackupRestore\Exec;

/**
 * @see ShellHelper Exec-mode implementations (faster, requires exec())
 * @see PhpHelper Pure PHP implementations (safe for shared hosting)
 * @package BackupRestore\Exec
 */
class ExecHelper
{
    /**
     * Check if exec-mode (shell commands) is available.
     *
     * Returns true only when ShellHelper is deployed AND exec() is enabled.
     *
     * @return bool True if shell commands can be used
     */
    public static function isExecAvailable(): bool
    {
        return class_exists(ShellHelper::class) && ShellHelper::isExecAvailable();
    }

    /**
     * Execute a SELECT-like MySQL query and return result rows.
     *
     * @param string $sql SQL query to execute
     * @param array $credentials Database credentials
     * @param string|null $database Database name (null = use credentials['database'])
     * @return array{success: bool, rows: array<int, array>, error: string|null}
     */
    public static function mysqlQuery(string $sql, array $credentials, ?string $database = null): array
    {
        if (self::isExecAvailable()) {
            return ShellHelper::mysqlQuery($sql, $credentials, $database);
        }
        return PhpHelper::mysqlQuery($sql, $credentials, $database);
    }

    /**
     * Execute a non-SELECT MySQL statement (CREATE, DROP, RENAME, ALTER, UPDATE, etc.)
     *
     * @param string $sql SQL statement to execute
     * @param array $credentials Database credentials
     * @param string|null $database Database name (null = use credentials['database'])
     * @return array{success: bool, error: string|null}
     */
    public static function mysqlExec(string $sql, array $credentials, ?string $database = null): array
    {
        if (self::isExecAvailable()) {
            return ShellHelper::mysqlExec($sql, $credentials, $database);
        }
        return PhpHelper::mysqlExec($sql, $credentials, $database);
    }

    /**
     * Strip DEFINER clauses from a SQL file.
     *
     * @param string $filePath Path to SQL file
     * @return bool True on success
     */
    public static function stripDefinerFromFile(string $filePath): bool
    {
        if (self::isExecAvailable()) {
            return ShellHelper::stripDefinerFromFile($filePath);
        }
        return PhpHelper::stripDefinerFromFile($filePath);
    }

    /**
     * Test gzip file integrity.
     *
     * @param string $filePath Path to gzip file
     * @return bool True if file is valid gzip
     */
    public static function gzipTest(string $filePath): bool
    {
        if (self::isExecAvailable()) {
            return ShellHelper::gzipTest($filePath);
        }
        return PhpHelper::gzipTest($filePath);
    }

    /**
     * List contents of a tar.gz archive.
     *
     * @param string $archivePath Path to .tar.gz or .tgz file
     * @return array{success: bool, files: array<string>, error: string|null}
     */
    public static function tarList(string $archivePath): array
    {
        if (self::isExecAvailable()) {
            return ShellHelper::tarList($archivePath);
        }
        return PhpHelper::tarList($archivePath);
    }

    /**
     * Count files in a tar archive (uncompressed).
     *
     * @param string $archivePath Path to tar file (uncompressed)
     * @return int Number of entries
     */
    public static function tarCount(string $archivePath): int
    {
        if (self::isExecAvailable()) {
            return ShellHelper::tarCount($archivePath);
        }
        return PhpHelper::tarCount($archivePath);
    }

    /**
     * Create a tar archive (uncompressed).
     *
     * @param string $outputPath Destination path for tar file
     * @param string $sourceDir Source directory to archive
     * @param array $excludes Relative paths to exclude
     * @param array|null $includes Specific relative paths to include (null = everything)
     * @return array{success: bool, error: string|null}
     */
    public static function tarCreate(string $outputPath, string $sourceDir, array $excludes = [], ?array $includes = null): array
    {
        if (self::isExecAvailable()) {
            return ShellHelper::tarCreate($outputPath, $sourceDir, $excludes, $includes);
        }
        return PhpHelper::tarCreate($outputPath, $sourceDir, $excludes, $includes);
    }

    /**
     * Create a compressed tar.gz archive from a directory.
     *
     * @param string $outputPath Destination path for .tar.gz or .tgz file
     * @param string $sourceDir Source directory to archive
     * @return array{success: bool, error: string|null}
     */
    public static function tarCreateGz(string $outputPath, string $sourceDir): array
    {
        if (self::isExecAvailable()) {
            return ShellHelper::tarCreateGz($outputPath, $sourceDir);
        }
        return PhpHelper::tarCreateGz($outputPath, $sourceDir);
    }

    /**
     * Extract a tar.gz archive.
     *
     * @param string $archivePath Path to tar.gz file
     * @param string $destDir Destination directory
     * @param string|null $pattern Optional wildcard pattern for selective extraction
     * @return array{success: bool, error: string|null}
     */
    public static function tarExtract(string $archivePath, string $destDir, ?string $pattern = null): array
    {
        if (self::isExecAvailable()) {
            return ShellHelper::tarExtract($archivePath, $destDir, $pattern);
        }
        return PhpHelper::tarExtract($archivePath, $destDir, $pattern);
    }

    /**
     * Extract an uncompressed tar archive.
     *
     * @param string $archivePath Path to .tar file
     * @param string $destDir Destination directory
     * @return array{success: bool, error: string|null}
     */
    public static function tarExtractUncompressed(string $archivePath, string $destDir): array
    {
        if (self::isExecAvailable()) {
            return ShellHelper::tarExtractUncompressed($archivePath, $destDir);
        }
        return PhpHelper::tarExtractUncompressed($archivePath, $destDir);
    }

    /**
     * Create a MySQL database dump.
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
        if (self::isExecAvailable()) {
            return ShellHelper::mysqldump($credentials, $outputPath, $routines, $triggers, $events, $tables);
        }
        return PhpHelper::mysqldump($credentials, $outputPath, $routines, $triggers, $events, $tables);
    }

    /**
     * Import a SQL dump file into a database.
     *
     * @param array $credentials Database credentials
     * @param string $database Target database name
     * @param string $sqlFilePath Path to SQL dump file
     * @return array{success: bool, error: string|null}
     */
    public static function mysqlImport(array $credentials, string $database, string $sqlFilePath): array
    {
        if (self::isExecAvailable()) {
            return ShellHelper::mysqlImport($credentials, $database, $sqlFilePath);
        }
        return PhpHelper::mysqlImport($credentials, $database, $sqlFilePath);
    }

    /**
     * Synchronize directories.
     *
     * @param string $source Source directory
     * @param string $dest Destination directory
     * @param array $excludes Relative paths to exclude from deletion
     * @return array{success: bool, error: string|null}
     */
    public static function syncDirectories(string $source, string $dest, array $excludes = []): array
    {
        if (self::isExecAvailable()) {
            return ShellHelper::syncDirectories($source, $dest, $excludes);
        }
        return PhpHelper::syncDirectories($source, $dest, $excludes);
    }

    /**
     * Clear the cached PDO connections.
     *
     * @return void
     */
    public static function clearPdoCache(): void
    {
        PhpHelper::clearPdoCache();
    }

    /**
     * Compute the total size in bytes of a directory tree, excluding the given
     * relative paths. Used by the pre-restore disk-space check to estimate the
     * size of the full-root snapshot before it is taken.
     *
     * @param string $path Absolute path to the directory
     * @param array $excludes Relative paths (from $path) to exclude from the sum
     * @return int Total size in bytes (0 if the path does not exist)
     */
    public static function directorySize(string $path, array $excludes = []): int
    {
        if (self::isExecAvailable()) {
            return ShellHelper::directorySize($path, $excludes);
        }
        return PhpHelper::directorySize($path, $excludes);
    }
}
