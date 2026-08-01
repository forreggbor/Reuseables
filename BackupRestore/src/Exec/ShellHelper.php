<?php

declare(strict_types=1);

/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * ShellHelper
 *
 * Shell/exec-based implementations for all backup/restore operations.
 * Uses native system commands (mysql, mysqldump, tar, gzip, sed, rsync)
 * for maximum performance on dedicated servers.
 *
 * WARNING: This file contains exec() calls and shell command construction
 * that may trigger malware scanners on shared hosting. Do NOT deploy this
 * file on shared hosting environments — the ExecHelper facade will
 * automatically fall back to PhpHelper when this file is absent.
 *
 * @see ExecHelper Facade that delegates to this class or PhpHelper
 * @see PhpHelper Pure PHP implementations (safe for shared hosting)
 */

namespace BackupRestore\Exec;

class ShellHelper
{
    /** @var bool|null Cached exec() availability result */
    private static ?bool $execAvailable = null;

    /** @var string Directory temporary MySQL option files are written to; configured once by the facade */
    private static string $tempDir = '';

    /**
     * Configure the directory used for temporary MySQL option files.
     * Called once by BackupRestore::__construct(); defaults to sys_get_temp_dir()
     * if never configured (e.g. direct unit-test use of this class).
     *
     * @param string $tempDir
     * @return void
     */
    public static function configureTempDir(string $tempDir): void
    {
        self::$tempDir = rtrim($tempDir, '/');
    }

    /**
     * @return string
     */
    private static function tempDir(): string
    {
        return self::$tempDir !== '' ? self::$tempDir : sys_get_temp_dir();
    }

    // ──────────────────────────────────────────────────────────
    //  Exec Detection
    // ──────────────────────────────────────────────────────────

    /**
     * Check if PHP exec() function is available.
     *
     * Checks both function_exists() and the disable_functions INI directive.
     * Result is cached for the lifetime of the request.
     *
     * @return bool True if exec() can be used
     */
    public static function isExecAvailable(): bool
    {
        if (self::$execAvailable !== null) {
            return self::$execAvailable;
        }

        if (!function_exists('exec')) {
            self::$execAvailable = false;
            return false;
        }

        $disabled = ini_get('disable_functions');
        if ($disabled !== false && $disabled !== '') {
            $disabledList = array_map('trim', explode(',', strtolower($disabled)));
            if (in_array('exec', $disabledList, true)) {
                self::$execAvailable = false;
                return false;
            }
        }

        self::$execAvailable = true;
        return true;
    }

    // ──────────────────────────────────────────────────────────
    //  MySQL Option File (relocated from the ported BackupService)
    // ──────────────────────────────────────────────────────────

    /**
     * Create a temporary MySQL option file for secure credential passing.
     *
     * Creates a temporary file with [client] section containing credentials
     * so that passwords are not exposed in the process list via command-line args.
     *
     * @param array $credentials Database credentials array
     * @return string|false Path to temporary option file, or false on failure
     */
    private static function createMysqlOptionFile(array $credentials): string|false
    {
        // A CR/LF in any credential value would inject arbitrary [client]
        // directives into the option file (e.g. a rogue `password=` line
        // overriding this one). None of host/port/username/password are
        // legitimately multi-line, so reject rather than attempt to escape.
        foreach (['host', 'port', 'username', 'password'] as $field) {
            if (isset($credentials[$field]) && preg_match('/[\r\n]/', (string) $credentials[$field])) {
                Logger::log("[ShellHelper] Refusing to build MySQL option file: \"{$field}\" contains a newline", 'ERROR');
                return false;
            }
        }

        $tempDir = self::tempDir();
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0775, true);
        }

        $optionFile = $tempDir . '/mysql_' . bin2hex(random_bytes(8)) . '.cnf';

        $content = "[client]\n";
        $content .= "host=" . $credentials['host'] . "\n";
        $content .= "port=" . $credentials['port'] . "\n";
        $content .= "user=" . $credentials['username'] . "\n";
        $content .= "password=" . $credentials['password'] . "\n";

        if (file_put_contents($optionFile, $content) === false) {
            return false;
        }

        chmod($optionFile, 0600);

        return $optionFile;
    }

    /**
     * Remove a temporary MySQL option file.
     *
     * @param string $optionFile Path to option file
     * @return void
     */
    private static function removeMysqlOptionFile(string $optionFile): void
    {
        if (file_exists($optionFile)) {
            // Overwrite file content before deletion for extra security
            file_put_contents($optionFile, str_repeat("\0", 256));
            unlink($optionFile);
        }
    }

    // ──────────────────────────────────────────────────────────
    //  MySQL Operations (CLI)
    // ──────────────────────────────────────────────────────────

    /**
     * Execute a SELECT-like MySQL query via mysql CLI.
     *
     * Runs `mysql -N -e "SQL"` and parses tab-separated output.
     *
     * @param string $sql SQL query to execute
     * @param array $credentials Database credentials
     * @param string|null $database Database name (null = use credentials['database'])
     * @return array{success: bool, rows: array<int, array>, error: string|null}
     */
    public static function mysqlQuery(string $sql, array $credentials, ?string $database = null): array
    {
        $optionFile = self::createMysqlOptionFile($credentials);
        if ($optionFile === false) {
            return ['success' => false, 'rows' => [], 'error' => 'Failed to create MySQL option file'];
        }

        try {
            $mysql   = self::findBinary('mysql');
            $dbArg   = ($database !== null && $database !== '') ? ' ' . escapeshellarg($database) : '';
            $errFile = tempnam(sys_get_temp_dir(), 'mysqlq_err_');

            $cmd = sprintf(
                '%s --defaults-extra-file=%s%s -N -e %s 2>%s',
                escapeshellarg($mysql),
                escapeshellarg($optionFile),
                $dbArg,
                escapeshellarg($sql),
                escapeshellarg($errFile)
            );

            $output     = [];
            $returnCode = 0;
            exec($cmd, $output, $returnCode);

            if ($returnCode !== 0) {
                $errorMsg = file_exists($errFile) ? trim(file_get_contents($errFile)) : implode("\n", $output);
                $errorMsg = preg_replace('/.*password.*\n?/i', '', $errorMsg);
                return ['success' => false, 'rows' => [], 'error' => trim($errorMsg) ?: 'mysql query failed with exit code ' . $returnCode];
            }

            // Parse tab-separated output into row arrays. Field values are unescaped
            // (see unescapeTsvField()) because `mysql -N` batch output backslash-escapes
            // control characters — a multi-line DDL's embedded newlines otherwise come
            // back as the literal two-character sequence `\n`, not an actual newline.
            $rows = [];
            foreach ($output as $line) {
                $line = trim($line);
                if ($line !== '') {
                    $rows[] = array_map([self::class, 'unescapeTsvField'], explode("\t", $line));
                }
            }

            return ['success' => true, 'rows' => $rows, 'error' => null];
        } finally {
            self::removeMysqlOptionFile($optionFile);
            if (isset($errFile) && file_exists($errFile)) {
                @unlink($errFile);
            }
        }
    }

    /**
     * Reverse the backslash-escaping `mysql -N` batch output applies to field values.
     *
     * The CLI escapes NUL, backslash, tab, newline, carriage return, and Ctrl-Z so a
     * field's own content can never be mistaken for the tab/newline row-and-column
     * delimiters — e.g. a stored trigger/view/routine DDL's embedded newlines come back
     * as the literal two characters `\n`, not an actual newline, unless reversed here.
     * MySQL's own SQL NULL marker (`\N`, capital N — distinct from `\n`) becomes PHP null.
     *
     * @param string $field Raw field value as emitted by `mysql -N`
     * @return string|null Unescaped value; null for MySQL's `\N` NULL marker
     */
    private static function unescapeTsvField(string $field): ?string
    {
        if ($field === '\\N') {
            return null;
        }

        return preg_replace_callback('/\\\\([0nrtZ\\\\])/', static function (array $m): string {
            return match ($m[1]) {
                '0' => "\0",
                'n' => "\n",
                'r' => "\r",
                't' => "\t",
                'Z' => "\x1a",
                default => '\\', // the '\\' case: an escaped literal backslash
            };
        }, $field);
    }

    /**
     * Execute a non-SELECT MySQL statement via mysql CLI.
     *
     * Runs `mysql -e "SQL"`.
     *
     * @param string $sql SQL statement to execute
     * @param array $credentials Database credentials
     * @param string|null $database Database name (null = use credentials['database'])
     * @return array{success: bool, error: string|null}
     */
    public static function mysqlExec(string $sql, array $credentials, ?string $database = null): array
    {
        $optionFile = self::createMysqlOptionFile($credentials);
        if ($optionFile === false) {
            return ['success' => false, 'error' => 'Failed to create MySQL option file'];
        }

        try {
            $mysql   = self::findBinary('mysql');
            $dbArg   = ($database !== null && $database !== '') ? ' ' . escapeshellarg($database) : '';
            $errFile = tempnam(sys_get_temp_dir(), 'mysqle_err_');

            $cmd = sprintf(
                '%s --defaults-extra-file=%s%s -e %s 2>%s',
                escapeshellarg($mysql),
                escapeshellarg($optionFile),
                $dbArg,
                escapeshellarg($sql),
                escapeshellarg($errFile)
            );

            $output     = [];
            $returnCode = 0;
            exec($cmd, $output, $returnCode);

            if ($returnCode !== 0) {
                $errorMsg = file_exists($errFile) ? trim(file_get_contents($errFile)) : implode("\n", $output);
                $errorMsg = preg_replace('/.*password.*\n?/i', '', $errorMsg);
                return ['success' => false, 'error' => trim($errorMsg) ?: 'mysql exec failed with exit code ' . $returnCode];
            }

            return ['success' => true, 'error' => null];
        } finally {
            self::removeMysqlOptionFile($optionFile);
            if (isset($errFile) && file_exists($errFile)) {
                @unlink($errFile);
            }
        }
    }

    // ──────────────────────────────────────────────────────────
    //  DEFINER Stripping (sed)
    // ──────────────────────────────────────────────────────────

    /**
     * Strip DEFINER clauses from a SQL file via sed command.
     *
     * @param string $filePath Path to SQL file
     * @return bool True on success
     */
    public static function stripDefinerFromFile(string $filePath): bool
    {
        if (!file_exists($filePath) || filesize($filePath) === 0) {
            Logger::log("[ShellHelper] stripDefiner: File not found or empty: {$filePath}", 'WARNING');
            return false;
        }

        $cmd = sprintf(
            'sed -i -e %s -e %s %s 2>&1',
            escapeshellarg('s/\/\*![0-9]* DEFINER=`[^`]*`@`[^`]*`\s*\*\///g'),
            escapeshellarg('s/DEFINER=`[^`]*`@`[^`]*`\s*//g'),
            escapeshellarg($filePath)
        );

        $output = [];
        $returnCode = 0;
        exec($cmd, $output, $returnCode);

        if ($returnCode !== 0) {
            Logger::log("[ShellHelper] sed failed (exit code {$returnCode}): " . implode(' ', $output), 'WARNING');
            return false;
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
            Logger::log("[ShellHelper] File became empty after DEFINER stripping: {$filePath}", 'WARNING');
            return false;
        }
        return true;
    }

    // ──────────────────────────────────────────────────────────
    //  Gzip Integrity Test
    // ──────────────────────────────────────────────────────────

    /**
     * Test gzip file integrity via gzip -t command.
     *
     * @param string $filePath Path to gzip file
     * @return bool True if file is valid gzip
     */
    public static function gzipTest(string $filePath): bool
    {
        if (!file_exists($filePath)) {
            return false;
        }

        $output = [];
        $returnCode = 0;
        exec('gzip -t ' . escapeshellarg($filePath) . ' 2>&1', $output, $returnCode);
        return $returnCode === 0;
    }

    // ──────────────────────────────────────────────────────────
    //  Tar Archive Operations (CLI)
    // ──────────────────────────────────────────────────────────

    /**
     * List contents of a tar.gz archive via tar -tzf.
     *
     * @param string $archivePath Path to .tar.gz or .tgz file
     * @return array{success: bool, files: array<string>, error: string|null}
     */
    public static function tarList(string $archivePath): array
    {
        if (!file_exists($archivePath)) {
            return ['success' => false, 'files' => [], 'error' => 'Archive file not found'];
        }

        $output = [];
        $returnCode = 0;
        exec('tar -tzf ' . escapeshellarg($archivePath) . ' 2>&1', $output, $returnCode);

        if ($returnCode !== 0) {
            return ['success' => false, 'files' => [], 'error' => 'Cannot read archive: ' . implode(' ', $output)];
        }

        return ['success' => true, 'files' => $output, 'error' => null];
    }

    /**
     * Count files in a tar archive via tar -tf | wc -l.
     *
     * @param string $archivePath Path to tar file (uncompressed)
     * @return int Number of entries
     */
    public static function tarCount(string $archivePath): int
    {
        if (!file_exists($archivePath)) {
            return 0;
        }

        $output = [];
        exec('tar -tf ' . escapeshellarg($archivePath) . ' | wc -l', $output);
        return (int)($output[0] ?? 0);
    }

    /**
     * Create a tar archive via tar -cf.
     *
     * @param string $outputPath Destination path for tar file
     * @param string $sourceDir Source directory to archive
     * @param array $excludes Relative paths to exclude
     * @param array|null $includes Specific relative paths to include (null = everything)
     * @return array{success: bool, error: string|null}
     */
    public static function tarCreate(string $outputPath, string $sourceDir, array $excludes = [], ?array $includes = null): array
    {
        $excludeArgs = '';
        foreach ($excludes as $path) {
            $excludeArgs .= ' --exclude=' . escapeshellarg('./' . ltrim($path, './'));
        }

        if ($includes === null || empty($includes)) {
            $includeArg = '.';
        } else {
            $includeArg = '';
            foreach ($includes as $path) {
                $includeArg .= ' ' . escapeshellarg('./' . ltrim($path, './'));
            }
        }

        $cmd = sprintf(
            'tar -cf %s -C %s%s %s 2>&1',
            escapeshellarg($outputPath),
            escapeshellarg($sourceDir),
            $excludeArgs,
            $includeArg
        );

        $output = [];
        $returnCode = 0;
        exec($cmd, $output, $returnCode);

        if ($returnCode !== 0) {
            return ['success' => false, 'error' => implode("\n", $output) ?: 'tar failed with exit code ' . $returnCode];
        }

        return ['success' => true, 'error' => null];
    }

    /**
     * Create a compressed tar.gz archive via tar -czf.
     *
     * @param string $outputPath Destination path for .tar.gz or .tgz file
     * @param string $sourceDir Source directory to archive
     * @return array{success: bool, error: string|null}
     */
    public static function tarCreateGz(string $outputPath, string $sourceDir): array
    {
        $cmd = sprintf(
            'tar -czf %s -C %s . 2>&1',
            escapeshellarg($outputPath),
            escapeshellarg($sourceDir)
        );

        $output = [];
        $returnCode = 0;
        exec($cmd, $output, $returnCode);

        if ($returnCode !== 0) {
            return ['success' => false, 'error' => implode("\n", $output) ?: 'tar -czf failed with exit code ' . $returnCode];
        }

        return ['success' => true, 'error' => null];
    }

    /**
     * Extract a tar.gz archive via tar -xzf.
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

        // Backup archives are untrusted input (SFTP pull, break-glass upload,
        // or an external path passed to restoreFromPath()) — validate every
        // member's destination BEFORE tar ever writes anything. Once tar has
        // written a `../`-escaping member, the damage is already done; a
        // post-extraction scan could only ever discover it, not prevent it.
        $listResult = self::tarList($archivePath);
        if (!$listResult['success']) {
            return ['success' => false, 'error' => 'Could not list archive contents: ' . ($listResult['error'] ?? 'unknown')];
        }
        try {
            \BackupRestore\PathGuard::assertArchiveMembersContained($listResult['files'], $destDir);
        } catch (\RuntimeException $e) {
            return ['success' => false, 'error' => 'Refusing to extract unsafe archive: ' . $e->getMessage()];
        }

        // No explicit "no absolute names" flag needed: GNU tar already strips
        // a leading "/" from member names by default (only -P/--absolute-names
        // would disable that, and it's never passed here) — the real
        // containment guarantee is the pre-extraction validation above.
        if ($pattern !== null) {
            $cmd = sprintf(
                'tar -xzf %s -C %s --wildcards %s 2>&1',
                escapeshellarg($archivePath),
                escapeshellarg($destDir),
                escapeshellarg($pattern)
            );
        } else {
            $cmd = sprintf(
                'tar -xzf %s -C %s 2>&1',
                escapeshellarg($archivePath),
                escapeshellarg($destDir)
            );
        }

        $output = [];
        $returnCode = 0;
        exec($cmd, $output, $returnCode);

        if ($returnCode !== 0) {
            return ['success' => false, 'error' => implode("\n", $output) ?: 'tar extract failed with exit code ' . $returnCode];
        }

        return ['success' => true, 'error' => null];
    }

    /**
     * Extract an uncompressed tar archive via tar -xf.
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

        // Same pre-extraction containment validation as tarExtract() — see
        // that method's comment. tarList() can't be reused here: it forces
        // `-z` (gzip), which errors on a plain (uncompressed) tar file.
        $listOutput = [];
        $listReturnCode = 0;
        exec('tar -tf ' . escapeshellarg($archivePath) . ' 2>&1', $listOutput, $listReturnCode);
        if ($listReturnCode !== 0) {
            return ['success' => false, 'error' => 'Could not list archive contents: ' . implode("\n", $listOutput)];
        }
        try {
            \BackupRestore\PathGuard::assertArchiveMembersContained($listOutput, $destDir);
        } catch (\RuntimeException $e) {
            return ['success' => false, 'error' => 'Refusing to extract unsafe archive: ' . $e->getMessage()];
        }

        $cmd = sprintf(
            'tar -xf %s -C %s 2>&1',
            escapeshellarg($archivePath),
            escapeshellarg($destDir)
        );

        $output = [];
        $returnCode = 0;
        exec($cmd, $output, $returnCode);

        if ($returnCode !== 0) {
            return ['success' => false, 'error' => implode("\n", $output) ?: 'tar extract failed with exit code ' . $returnCode];
        }

        return ['success' => true, 'error' => null];
    }

    // ──────────────────────────────────────────────────────────
    //  MySQL Dump (native mysqldump)
    // ──────────────────────────────────────────────────────────

    /**
     * Create a MySQL database dump via native mysqldump binary.
     *
     * @param array $credentials Database credentials
     * @param string $outputPath Destination path for SQL dump file
     * @param bool $routines Include stored procedures and functions (unused — native mysqldump always includes)
     * @param bool $triggers Include triggers (unused — native mysqldump always includes)
     * @param bool $events Include scheduled events (unused — native mysqldump always includes)
     * @param array|null $tables Restrict dump to these table names; null = all tables
     * @return array{success: bool, tables_count: int, error: string|null}
     */
    public static function mysqldump(array $credentials, string $outputPath, bool $routines = true, bool $triggers = true, bool $events = true, ?array $tables = null): array
    {
        $optionFile = self::createMysqlOptionFile($credentials);
        if ($optionFile === false) {
            return ['success' => false, 'tables_count' => 0, 'error' => 'Failed to create MySQL option file'];
        }

        try {
            $mysqldump = self::findBinary('mysqldump');

            // Build optional table list (no --routines/--events when limiting to specific tables)
            $tableArgs = '';
            if (!empty($tables)) {
                $tableArgs = implode(' ', array_map('escapeshellarg', $tables));
                // Suppress routines/events for partial dumps (they're schema-wide, not per-table)
                $routines = false;
                $events   = false;
            }

            // stderr goes to a separate temp file so that deprecation warnings (e.g.
            // "mysqldump: Deprecated program name") are never written to the SQL dump.
            $stderrFile = $outputPath . '.stderr';
            $cmd = sprintf(
                '%s --defaults-extra-file=%s --single-transaction --quick --lock-tables=false%s%s%s %s %s > %s 2>%s',
                escapeshellarg($mysqldump),
                escapeshellarg($optionFile),
                $routines  ? ' --routines'  : '',
                $triggers  ? ' --triggers'  : ' --skip-triggers',
                $events    ? ' --events'    : '',
                escapeshellarg($credentials['database']),
                $tableArgs,
                escapeshellarg($outputPath),
                escapeshellarg($stderrFile)
            );

            $output = [];
            $returnCode = 0;
            exec($cmd, $output, $returnCode);

            $stderrContent = file_exists($stderrFile) ? (string)file_get_contents($stderrFile) : '';
            @unlink($stderrFile);

            if ($returnCode !== 0) {
                $errorMsg = preg_replace('/.*password.*\n?/i', '', $stderrContent);
                return ['success' => false, 'tables_count' => 0, 'error' => trim($errorMsg) ?: 'mysqldump failed with exit code ' . $returnCode];
            }

            // Count tables in dump
            $tablesCount = 0;
            if (file_exists($outputPath)) {
                $handle = fopen($outputPath, 'r');
                if ($handle) {
                    while (($line = fgets($handle)) !== false) {
                        if (preg_match('/^CREATE TABLE/', $line)) {
                            $tablesCount++;
                        }
                    }
                    fclose($handle);
                }
            }

            return ['success' => true, 'tables_count' => $tablesCount, 'error' => null];
        } finally {
            self::removeMysqlOptionFile($optionFile);
        }
    }

    // ──────────────────────────────────────────────────────────
    //  MySQL Import (CLI)
    // ──────────────────────────────────────────────────────────

    /**
     * Import a SQL dump file via mysql CLI (mysql < file.sql).
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

        $optionFile = self::createMysqlOptionFile($credentials);
        if ($optionFile === false) {
            return ['success' => false, 'error' => 'Failed to create MySQL option file'];
        }

        try {
            $mysql = self::findBinary('mysql');

            $cmd = sprintf(
                '%s --defaults-extra-file=%s %s < %s 2>&1',
                escapeshellarg($mysql),
                escapeshellarg($optionFile),
                escapeshellarg($database),
                escapeshellarg($sqlFilePath)
            );

            $output = [];
            $returnCode = 0;
            exec($cmd, $output, $returnCode);

            if ($returnCode !== 0) {
                $errorMsg = implode("\n", $output);
                $errorMsg = preg_replace('/.*password.*\n?/i', '', $errorMsg);
                return ['success' => false, 'error' => trim($errorMsg) ?: 'mysql import failed with exit code ' . $returnCode];
            }

            return ['success' => true, 'error' => null];
        } finally {
            self::removeMysqlOptionFile($optionFile);
        }
    }

    // ──────────────────────────────────────────────────────────
    //  Directory Sync (rsync)
    // ──────────────────────────────────────────────────────────

    /**
     * Synchronize directories via rsync -a --delete.
     *
     * @param string $source Source directory
     * @param string $dest Destination directory
     * @param array $excludes Relative paths to exclude from deletion
     * @return array{success: bool, error: string|null}
     */
    public static function syncDirectories(string $source, string $dest, array $excludes = []): array
    {
        $excludeArgs = '';
        foreach ($excludes as $path) {
            $excludeArgs .= ' --exclude=' . escapeshellarg($path);
        }

        $cmd = sprintf(
            'rsync -a --delete%s %s/ %s/ 2>&1',
            $excludeArgs,
            escapeshellarg($source),
            escapeshellarg($dest)
        );

        $output = [];
        $returnCode = 0;
        exec($cmd, $output, $returnCode);

        if ($returnCode !== 0) {
            return ['success' => false, 'error' => implode("\n", $output) ?: 'rsync failed with exit code ' . $returnCode];
        }

        return ['success' => true, 'error' => null];
    }

    /**
     * Compute the total size in bytes of a directory tree via `du -sb`, excluding
     * the given relative paths.
     *
     * @param string $path Absolute path to the directory
     * @param array $excludes Relative paths (from $path) to exclude from the sum
     * @return int Total size in bytes (0 if the path does not exist or du fails)
     */
    public static function directorySize(string $path, array $excludes = []): int
    {
        if (!is_dir($path)) {
            return 0;
        }

        $excludeArgs = '';
        foreach ($excludes as $exclude) {
            $excludeArgs .= ' --exclude=' . escapeshellarg(trim($exclude, '/'));
        }

        $cmd = sprintf('du -sb%s %s 2>/dev/null', $excludeArgs, escapeshellarg(rtrim($path, '/')));
        $output = [];
        $returnCode = 0;
        exec($cmd, $output, $returnCode);

        if ($returnCode === 0 && !empty($output)) {
            $parts = preg_split('/\s+/', trim($output[0]));
            if (isset($parts[0]) && is_numeric($parts[0])) {
                return (int)$parts[0];
            }
        }

        // du unavailable or failed — fall back to the pure-PHP recursive sum.
        return PhpHelper::directorySize($path, $excludes);
    }

    // ──────────────────────────────────────────────────────────
    //  Utility
    // ──────────────────────────────────────────────────────────

    /**
     * Find a system binary path.
     *
     * @param string $binary Binary name (e.g., 'mysql', 'mysqldump')
     * @return string Full path to binary
     */
    private static function findBinary(string $binary): string
    {
        $paths = ['/bin/' . $binary, '/usr/bin/' . $binary, '/usr/local/bin/' . $binary];
        foreach ($paths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }
        return '/usr/bin/' . $binary;
    }
}
