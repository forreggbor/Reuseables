<?php
/**
 * Standalone Restore Bootstrap
 *
 * Self-contained restore script for disaster recovery.
 * No MVC framework dependency - works even if framework is broken.
 *
 * Supports dual-mode operation:
 *   - exec() mode (faster): Uses native shell commands (mysql, tar, sed, rsync)
 *   - PHP mode (portable): Uses PDO, PharData — no shell access needed
 *
 * Activation: Create a `.restore_enabled` file in the project root.
 *   e.g.: touch /var/www/app/.restore_enabled
 *
 * Security:
 *   - Disabled by default (requires .restore_enabled file)
 *   - Restore token required (from backup manifest.json)
 *   - Brute-force protection (3 failed attempts = 15-min lockout)
 *   - Auto-deletes .restore_enabled after successful restore
 *
 * CLI mode:
 *   php restore.php --file=backup.tgz --token=abc123 \
 *       --db-host=localhost --db-user=root --db-pass=secret --db-name=jupiterp
 */

// --- Constants ---
define('RESTORE_VERSION', '1.0.0');
define('MAX_TOKEN_ATTEMPTS', 3);
define('LOCKOUT_DURATION', 900); // 15 minutes
define('RESTORE_ROOT', __DIR__);

// --- PDO connection cache for PHP fallback mode ---
$GLOBALS['_restore_pdo_cache'] = [];

// --- CLI Mode ---
$isCli = (PHP_SAPI === 'cli');

if ($isCli) {
    handleCliRestore();
    exit;
}

// --- Web Mode ---

// Check activation
if (!file_exists(RESTORE_ROOT . '/.restore_enabled')) {
    renderDisabledPage();
    exit;
}

// Check brute-force lockout
$lockoutFile = sys_get_temp_dir() . '/app_restore_lockout_' . md5($_SERVER['REMOTE_ADDR'] ?? 'unknown');
if (file_exists($lockoutFile) && (time() - filemtime($lockoutFile)) < LOCKOUT_DURATION) {
    $remaining = LOCKOUT_DURATION - (time() - filemtime($lockoutFile));
    renderPage('Locked Out', '<div class="alert alert-danger">Too many failed attempts. Please wait ' . ceil($remaining / 60) . ' minutes.</div>');
    exit;
}

// Start session for state management. Secure is conditional on the request
// actually being HTTPS — this is a disaster-recovery tool that must still
// work if it's ever reached over plain HTTP (e.g. mid-outage before TLS is
// re-provisioned); forcing Secure on an HTTP request would make the browser
// silently refuse to store the cookie at all, breaking the whole flow.
$restoreIsHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['SERVER_PORT'] ?? null) == 443)
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'httponly' => true,
    'secure' => $restoreIsHttps,
    'samesite' => 'Strict',
]);
session_start();

$step = $_POST['step'] ?? $_GET['step'] ?? 'token';
$error = '';

switch ($step) {
    case 'token':
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['restore_token'])) {
            if (!restore_verify_csrf($_POST['csrf_token'] ?? null)) {
                $error = 'Your session expired. Please try again.';
                renderTokenStep($error);
                break;
            }
            $token = trim($_POST['restore_token']);
            if (empty($token)) {
                $error = 'Please enter a restore token.';
            } else {
                $_SESSION['restore_token'] = $token;
                $_SESSION['token_attempts'] = ($_SESSION['token_attempts'] ?? 0);
                header('Location: ?step=database');
                exit;
            }
        }
        renderTokenStep($error);
        break;

    case 'database':
        if (!isset($_SESSION['restore_token'])) {
            header('Location: ?step=token');
            exit;
        }

        // Pre-fill from .env if available
        $envCreds = readEnvCredentials();

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['db_host'])) {
            if (!restore_verify_csrf($_POST['csrf_token'] ?? null)) {
                $error = 'Your session expired. Please try again.';
                renderDatabaseStep($envCreds, $error);
                break;
            }
            try {
                $dbName = restore_sanitize_identifier(trim($_POST['db_name'] ?? ''), 'database name');
            } catch (InvalidArgumentException $validationError) {
                $error = $validationError->getMessage();
                renderDatabaseStep($envCreds, $error);
                break;
            }
            $_SESSION['db_creds'] = [
                'host' => trim($_POST['db_host']),
                'port' => (int)($_POST['db_port'] ?: 3306),
                'username' => trim($_POST['db_user']),
                'password' => $_POST['db_pass'],
                'database' => $dbName,
            ];
            header('Location: ?step=upload');
            exit;
        }
        renderDatabaseStep($envCreds, $error);
        break;

    case 'upload':
        if (!isset($_SESSION['restore_token']) || !isset($_SESSION['db_creds'])) {
            header('Location: ?step=token');
            exit;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['backup_file'])) {
            if (!restore_verify_csrf($_POST['csrf_token'] ?? null)) {
                $error = 'Your session expired. Please try again.';
                renderUploadStep($error);
                break;
            }
            $file = $_FILES['backup_file'];
            if ($file['error'] !== UPLOAD_ERR_OK) {
                $error = 'File upload failed (error code: ' . $file['error'] . ')';
            } elseif (!str_ends_with($file['name'], '.tgz')) {
                $error = 'Only .tgz files are accepted.';
            } elseif (!in_array((new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']), ['application/gzip', 'application/x-gzip'], true)) {
                // Extension alone is trivially spoofable — sniff the actual
                // content before trusting a ".tgz" upload is really gzip.
                $error = 'The uploaded file is not a valid gzip archive.';
            } else {
                $tempDir = sys_get_temp_dir() . '/app_restore_' . bin2hex(random_bytes(8));
                mkdir($tempDir, 0775, true);
                $archivePath = $tempDir . '/backup.tgz';
                move_uploaded_file($file['tmp_name'], $archivePath);

                $_SESSION['archive_path'] = $archivePath;
                $_SESSION['temp_dir'] = $tempDir;

                // Extract and verify manifest
                $manifest = extractManifest($archivePath, $tempDir);
                if (!$manifest) {
                    $error = 'Invalid archive: no manifest.json found.';
                    cleanupDir($tempDir);
                } else {
                    // Verify token (timing-safe — mitigated further by the
                    // 3-attempt lockout below regardless)
                    if (!hash_equals((string) $_SESSION['restore_token'], (string) ($manifest['restore_token'] ?? ''))) {
                        $_SESSION['token_attempts'] = ($_SESSION['token_attempts'] ?? 0) + 1;

                        if ($_SESSION['token_attempts'] >= MAX_TOKEN_ATTEMPTS) {
                            file_put_contents($lockoutFile, time());
                            session_destroy();
                            cleanupDir($tempDir);
                            renderPage('Locked Out', '<div class="alert alert-danger">Too many failed token attempts. Locked for 15 minutes.</div>');
                            exit;
                        }

                        $error = 'Invalid restore token. Attempt ' . $_SESSION['token_attempts'] . '/' . MAX_TOKEN_ATTEMPTS . '.';
                        cleanupDir($tempDir);
                    } else {
                        $_SESSION['manifest'] = $manifest;
                        header('Location: ?step=confirm');
                        exit;
                    }
                }
            }
        }
        renderUploadStep($error);
        break;

    case 'confirm':
        if (!isset($_SESSION['manifest']) || !isset($_SESSION['archive_path'])) {
            header('Location: ?step=token');
            exit;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm'])) {
            if (!restore_verify_csrf($_POST['csrf_token'] ?? null)) {
                renderConfirmStep($_SESSION['manifest'], 'Your session expired. Please try again.');
                break;
            }
            // execute is reached via a plain GET redirect (no form of its own) —
            // the CSRF token travels in the query string so execute can verify it
            // too; a bare-URL CSRF attempt (e.g. an <img> tag) would still fail
            // without ever having passed through this validated confirm step.
            header('Location: ?step=execute&csrf=' . urlencode(restore_csrf_token()));
            exit;
        }
        renderConfirmStep($_SESSION['manifest']);
        break;

    case 'execute':
        if (!isset($_SESSION['manifest']) || !isset($_SESSION['archive_path'])) {
            header('Location: ?step=token');
            exit;
        }
        if (!restore_verify_csrf($_GET['csrf'] ?? null)) {
            header('Location: ?step=confirm');
            exit;
        }

        set_time_limit(600);
        $result = executeRestore(
            $_SESSION['archive_path'],
            $_SESSION['temp_dir'],
            $_SESSION['db_creds'],
            $_SESSION['manifest']
        );

        // Cleanup
        if (isset($_SESSION['temp_dir'])) {
            cleanupDir($_SESSION['temp_dir']);
        }

        if ($result['success']) {
            // Remove .restore_enabled
            if (file_exists(RESTORE_ROOT . '/.restore_enabled')) {
                unlink(RESTORE_ROOT . '/.restore_enabled');
            }
            session_destroy();
            renderPage('Restore Complete', '<div class="alert alert-success"><strong>Restore completed successfully!</strong><br>The .restore_enabled file has been removed. You can now access your application normally.</div><a href="/" class="btn btn-primary">Go to Application</a>');
        } else {
            renderPage('Restore Failed', '<div class="alert alert-danger"><strong>Restore failed:</strong><br>' . htmlspecialchars($result['error']) . '</div><a href="?step=upload" class="btn btn-warning">Try Again</a>');
        }
        break;

    default:
        header('Location: ?step=token');
        exit;
}

// ============================================================================
// FUNCTIONS
// ============================================================================

// --- Standalone SQL-identifier / trigger-DDL whitelist helpers ---

/**
 * Backtick-quote a MySQL/MariaDB identifier (table/trigger/constraint name),
 * doubling any embedded backtick per MySQL's own identifier-quoting rule.
 * Standalone copy of BackupRestore\Identifier::quote() — restore.php cannot
 * rely on the framework autoloader. Use this (not restore_sanitize_identifier())
 * for table/trigger/column names read back from a restored (untrusted)
 * database via information_schema/SHOW CREATE TRIGGER — those are never
 * safely reducible to the identifier whitelist below, since a legitimate
 * table name can contain characters restore_sanitize_identifier() rejects.
 */
function restore_quote_identifier(string $name): string
{
    return '`' . str_replace('`', '``', $name) . '`';
}

/**
 * Whitelist-validate a SQL identifier (database name only — restore.php builds
 * `_restore_<ts>`/`_old_<ts>` temp database names from the host-supplied
 * db_credentials['database'], which the facade never enforces this shape on
 * either) that is about to be interpolated into a statement as a backtick-
 * quoted identifier. Rejects anything outside [A-Za-z0-9_-] or longer than
 * MySQL's 64-character limit. Standalone copy — restore.php cannot rely on
 * the framework autoloader.
 *
 * @throws InvalidArgumentException on any disallowed input
 */
function restore_sanitize_identifier(string $name, string $context = 'identifier'): string
{
    if ($name === '' || strlen($name) > 64 || !preg_match('/^[A-Za-z0-9_-]+$/', $name)) {
        throw new InvalidArgumentException("Invalid SQL {$context}");
    }
    return $name;
}

/**
 * Defence-in-depth guard for trigger DDL pulled out of a restored backup database.
 * Accepts only statements that begin with CREATE TRIGGER or CREATE DEFINER=... TRIGGER
 * — the two shapes SHOW CREATE TRIGGER returns. A prefix check cannot stop a
 * sufficiently crafted payload; full safety requires signed/HMAC-verified backups
 * (tracked as future hardening scope).
 */
function restore_is_safe_trigger_ddl(string $ddl): bool
{
    $trimmed = ltrim($ddl);
    return (bool)preg_match('/^CREATE\s+(DEFINER\s*=\s*`[^`]*`@`[^`]*`\s+)?TRIGGER\s+/i', $trimmed);
}

// --- CSRF protection ---
// Every state-changing step (token/database/upload/confirm — all POST — and
// the final execute, reached via a plain GET redirect) is CSRF-protected.
// Without this, a page the victim visits while a restore session is active
// could forge a POST to e.g. ?step=database with attacker-chosen db_host/
// db_user/db_pass, silently redirecting the eventual restore at an
// attacker-controlled MySQL server.

/**
 * Get (creating on first call) the per-session CSRF token.
 */
function restore_csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Timing-safe verification of a submitted CSRF token against the session's.
 */
function restore_verify_csrf(?string $submitted): bool
{
    return is_string($submitted) && !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $submitted);
}

/**
 * Hidden-field HTML embedding the current CSRF token, for use inside every form.
 */
function restore_csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(restore_csrf_token()) . '">';
}

// --- Standalone path-containment helpers (zip-slip / traversal guard) ---
// Standalone copies of BackupRestore\PathGuard's logic — restore.php cannot
// rely on the framework autoloader.

/**
 * Lexically resolve `.`/`..` segments in an absolute path, without touching
 * the filesystem — usable on a path that doesn't exist yet (realpath()
 * requires the target to already exist, which a not-yet-written destination
 * never does).
 */
function restore_path_normalize(string $path): string
{
    $resolved = [];
    foreach (explode('/', $path) as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }
        if ($segment === '..') {
            array_pop($resolved);
            continue;
        }
        $resolved[] = $segment;
    }
    return '/' . implode('/', $resolved);
}

/**
 * Assert that $candidate resolves to a path inside (or equal to) $base.
 * Both arguments must be absolute.
 *
 * @throws RuntimeException When $candidate escapes $base
 */
function restore_assert_path_contained(string $base, string $candidate): void
{
    if (!str_starts_with($base, '/') || !str_starts_with($candidate, '/')) {
        throw new RuntimeException('BackupRestore: path containment check requires absolute paths');
    }

    $normalizedBase = restore_path_normalize($base);
    $normalizedCandidate = restore_path_normalize($candidate);
    $prefix = rtrim($normalizedBase, '/') . '/';

    if ($normalizedCandidate !== rtrim($normalizedBase, '/') && !str_starts_with($normalizedCandidate . '/', $prefix)) {
        throw new RuntimeException("BackupRestore: path \"{$candidate}\" escapes its containing directory \"{$base}\"");
    }
}

/**
 * Assert that every member of an archive's file list would extract to a
 * location inside $destDir — call this BEFORE running the actual extraction.
 * A malicious member has already escaped the destination the instant an
 * extractor writes it, so a post-extraction scan could only ever discover
 * the damage, never prevent it.
 *
 * @param array<int,string> $memberPaths Relative member paths (e.g. "./database/x.sql")
 * @throws RuntimeException When any member escapes $destDir
 */
function restore_assert_archive_members_contained(array $memberPaths, string $destDir): void
{
    $destDir = rtrim($destDir, '/');
    foreach ($memberPaths as $member) {
        $relative = rtrim((string) preg_replace('#^\./+#', '', $member), '/');
        if ($relative === '') {
            // "." / "./" — the archive's own root-directory entry (from
            // "tar -czf ... -C dir ."); trivially resolves to $destDir.
            continue;
        }
        if (str_starts_with($relative, '/')) {
            throw new RuntimeException("BackupRestore: archive member has an absolute path: \"{$member}\"");
        }
        restore_assert_path_contained($destDir, $destDir . '/' . $relative);
    }
}

// --- Dual-Mode Utility Functions ---

/**
 * Check if PHP exec() function is available
 *
 * Checks both function_exists() and the disable_functions INI directive.
 * Result is cached for the lifetime of the request.
 * (Standalone helpers for identifier and DDL whitelisting are defined below.)
 *
 * @return bool True if exec() can be used
 */
function isExecAvailable(): bool
{
    static $available = null;

    if ($available !== null) {
        return $available;
    }

    if (!function_exists('exec')) {
        $available = false;
        return false;
    }

    $disabled = ini_get('disable_functions');
    if ($disabled !== false && $disabled !== '') {
        $disabledList = array_map('trim', explode(',', strtolower($disabled)));
        if (in_array('exec', $disabledList, true)) {
            $available = false;
            return false;
        }
    }

    $available = true;
    return true;
}

/**
 * Get a cached PDO connection for restore operations
 *
 * Connections are cached by credentials + database combination.
 * Used in PHP fallback mode when exec() is unavailable.
 *
 * @param array $creds Database credentials (host, port, username, password)
 * @param string|null $database Optional database name to connect to
 * @return PDO PDO connection instance
 */
function getRestorePdo(array $creds, ?string $database = null): PDO
{
    $key = md5(json_encode([$creds['host'], $creds['port'], $creds['username'], $database]));

    if (isset($GLOBALS['_restore_pdo_cache'][$key])) {
        return $GLOBALS['_restore_pdo_cache'][$key];
    }

    $dsn = "mysql:host={$creds['host']};port={$creds['port']};charset=utf8mb4";
    if ($database !== null) {
        $dsn .= ";dbname={$database}";
    }

    $pdo = new PDO($dsn, $creds['username'], $creds['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $GLOBALS['_restore_pdo_cache'][$key] = $pdo;
    return $pdo;
}

/**
 * Clear all cached PDO connections
 *
 * Must be called before dropping databases that may have open connections.
 *
 * @return void
 */
function clearRestorePdoCache(): void
{
    $GLOBALS['_restore_pdo_cache'] = [];
}

// --- Main Functions ---

/**
 * Handle CLI restore mode
 */
function handleCliRestore(): void
{
    $options = getopt('', ['file:', 'token:', 'db-host:', 'db-user:', 'db-pass:', 'db-name:', 'db-port::']);

    if (empty($options['file']) || empty($options['token'])) {
        echo "Standalone Restore v" . RESTORE_VERSION . "\n\n";
        echo "Usage:\n";
        echo "  php restore.php --file=backup.tgz --token=RESTORE_TOKEN \\\n";
        echo "    --db-host=localhost --db-user=root --db-pass=secret --db-name=jupiterp\n\n";
        echo "Options:\n";
        echo "  --file      Path to backup TGZ file (required)\n";
        echo "  --token     Restore token from backup manifest (required)\n";
        echo "  --db-host   Database host (default: from .env or localhost)\n";
        echo "  --db-port   Database port (default: 3306)\n";
        echo "  --db-user   Database username (default: from .env)\n";
        echo "  --db-pass   Database password (default: from .env)\n";
        echo "  --db-name   Database name (default: from .env)\n";
        exit(1);
    }

    $archivePath = $options['file'];
    if (!file_exists($archivePath)) {
        echo "ERROR: File not found: {$archivePath}\n";
        exit(1);
    }

    // Read env credentials as defaults
    $envCreds = readEnvCredentials();

    $creds = [
        'host' => $options['db-host'] ?? $envCreds['host'] ?? 'localhost',
        'port' => (int)($options['db-port'] ?? $envCreds['port'] ?? 3306),
        'username' => $options['db-user'] ?? $envCreds['username'] ?? '',
        'password' => $options['db-pass'] ?? $envCreds['password'] ?? '',
        'database' => $options['db-name'] ?? $envCreds['database'] ?? '',
    ];

    if (empty($creds['database'])) {
        echo "ERROR: Database name is required.\n";
        exit(1);
    }

    try {
        $creds['database'] = restore_sanitize_identifier($creds['database'], 'database name');
    } catch (InvalidArgumentException $e) {
        echo "ERROR: " . $e->getMessage() . "\n";
        exit(1);
    }

    echo "Standalone Restore v" . RESTORE_VERSION . "\n";
    echo str_repeat('=', 50) . "\n";

    if (!isExecAvailable()) {
        echo "Mode: Pure PHP (exec() not available)\n";
    }

    // Extract and verify manifest
    $tempDir = sys_get_temp_dir() . '/app_restore_cli_' . bin2hex(random_bytes(4));
    mkdir($tempDir, 0775, true);

    echo "Extracting manifest...\n";
    $manifest = extractManifest($archivePath, $tempDir);
    if (!$manifest) {
        echo "ERROR: Invalid archive - no manifest.json found.\n";
        cleanupDir($tempDir);
        exit(1);
    }

    // Verify token (timing-safe)
    if (!hash_equals((string) $options['token'], (string) ($manifest['restore_token'] ?? ''))) {
        echo "ERROR: Invalid restore token.\n";
        cleanupDir($tempDir);
        exit(1);
    }

    echo "Manifest verified. Backup date: " . ($manifest['backup_date'] ?? 'unknown') . "\n";
    echo "Type: " . ($manifest['backup_type'] ?? 'full') . "\n";
    echo "Tables: " . ($manifest['database']['tables_count'] ?? '?') . "\n";
    echo "Files: " . ($manifest['files']['total_files'] ?? '?') . "\n";
    echo str_repeat('=', 50) . "\n";

    $result = executeRestore($archivePath, $tempDir, $creds, $manifest);
    cleanupDir($tempDir);

    if ($result['success']) {
        echo "\nRestore completed successfully!\n";
        // Remove .restore_enabled
        if (file_exists(RESTORE_ROOT . '/.restore_enabled')) {
            unlink(RESTORE_ROOT . '/.restore_enabled');
            echo ".restore_enabled file removed.\n";
        }
        exit(0);
    } else {
        echo "\nRestore FAILED: " . $result['error'] . "\n";
        exit(1);
    }
}

/**
 * Read database credentials from .env file
 */
function readEnvCredentials(): array
{
    $envFile = RESTORE_ROOT . '/.env';
    $creds = ['host' => 'localhost', 'port' => 3306, 'username' => '', 'password' => '', 'database' => ''];

    if (!file_exists($envFile)) {
        return $creds;
    }

    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (str_starts_with(trim($line), '#')) continue;
        if (!str_contains($line, '=')) continue;

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value, " \t\n\r\0\x0B\"'");

        match ($key) {
            'DB_HOST' => $creds['host'] = $value,
            'DB_PORT' => $creds['port'] = (int)$value,
            'DB_USER' => $creds['username'] = $value,
            'DB_PASSWORD' => $creds['password'] = $value,
            'DB_NAME' => $creds['database'] = $value,
            default => null,
        };
    }

    return $creds;
}

/**
 * Extract manifest.json from a TGZ archive
 *
 * In exec mode: uses tar CLI.
 * In PHP mode: uses PharData.
 *
 * @param string $archivePath Path to TGZ archive
 * @param string $tempDir Temporary directory for extraction
 * @return array|null Decoded manifest or null on failure
 */
function extractManifest(string $archivePath, string $tempDir): ?array
{
    if (isExecAvailable()) {
        $cmd = sprintf(
            'tar -xzf %s -C %s ./manifest.json 2>&1',
            escapeshellarg($archivePath),
            escapeshellarg($tempDir)
        );
        exec($cmd, $output, $returnCode);
    } else {
        try {
            $phar = new PharData($archivePath);
            $extracted = false;

            // Archive may store path with or without ./ prefix
            foreach (['manifest.json', './manifest.json'] as $path) {
                try {
                    $phar->extractTo($tempDir, $path, true);
                    $extracted = true;
                    break;
                } catch (Exception $e) {
                    continue;
                }
            }

            if (!$extracted) {
                return null;
            }
        } catch (Exception $e) {
            return null;
        }
    }

    $manifestPath = $tempDir . '/manifest.json';
    if (!file_exists($manifestPath)) {
        return null;
    }

    $content = file_get_contents($manifestPath);
    $manifest = json_decode($content, true);

    return is_array($manifest) ? $manifest : null;
}

/**
 * Execute the full restore process
 *
 * @param string $archivePath Path to TGZ archive
 * @param string $tempDir Temporary directory
 * @param array $creds Database credentials
 * @param array $manifest Backup manifest data
 * @return array{success: bool, error: string|null}
 */
function executeRestore(string $archivePath, string $tempDir, array $creds, array $manifest): array
{
    $log = function (string $msg) {
        if (PHP_SAPI === 'cli') {
            echo $msg . "\n";
        }
    };

    $backupType = $manifest['backup_type'] ?? 'full';
    $hasDatabase = !empty($manifest['database']['dump_file']);
    $hasFiles = !empty($manifest['files']);

    // Step 1: Extract full archive. The uploaded/CLI-supplied archive is
    // untrusted — every member's destination is validated BEFORE anything is
    // written (see restore_assert_archive_members_contained()'s docblock:
    // once written, a `../`-escaping member has already done its damage).
    $log("Extracting archive...");
    if (isExecAvailable()) {
        $listOutput = [];
        $listReturnCode = 0;
        exec('tar -tzf ' . escapeshellarg($archivePath) . ' 2>&1', $listOutput, $listReturnCode);
        if ($listReturnCode !== 0) {
            return ['success' => false, 'error' => 'Could not list archive contents: ' . implode("\n", $listOutput)];
        }
        try {
            restore_assert_archive_members_contained($listOutput, $tempDir);
        } catch (RuntimeException $e) {
            return ['success' => false, 'error' => 'Refusing to extract unsafe archive: ' . $e->getMessage()];
        }

        // No explicit "no absolute names" flag needed: GNU tar already strips a
        // leading "/" from member names by default — the real containment
        // guarantee is restore_assert_archive_members_contained() above.
        $cmd = sprintf(
            'tar -xzf %s -C %s 2>&1',
            escapeshellarg($archivePath),
            escapeshellarg($tempDir)
        );
        exec($cmd, $output, $returnCode);
        if ($returnCode !== 0) {
            return ['success' => false, 'error' => 'Failed to extract archive: ' . implode("\n", $output)];
        }
    } else {
        try {
            $phar = new PharData($archivePath);
            $allMembers = [];
            foreach (new RecursiveIteratorIterator($phar) as $file) {
                $allMembers[] = $file->getPathname();
            }
            restore_assert_archive_members_contained($allMembers, $tempDir);

            $phar->extractTo($tempDir, null, true);
        } catch (RuntimeException $e) {
            return ['success' => false, 'error' => 'Refusing to extract unsafe archive: ' . $e->getMessage()];
        } catch (Exception $e) {
            return ['success' => false, 'error' => 'Failed to extract archive: ' . $e->getMessage()];
        }
    }

    // Step 2: Database restore (if applicable)
    if ($hasDatabase && ($backupType === 'full' || $backupType === 'database')) {
        $log("Restoring database...");
        $dbResult = restoreDatabase($tempDir, $creds, $manifest, $log);
        if (!$dbResult['success']) {
            return $dbResult;
        }
    }

    // Step 3: File restore (if applicable)
    if ($hasFiles && ($backupType === 'full' || $backupType === 'files')) {
        $filesDir = $tempDir . '/files';
        if (is_dir($filesDir)) {
            $log("Restoring files...");
            $filesResult = restoreFiles($filesDir, $log);
            if (!$filesResult['success']) {
                return $filesResult;
            }
        }
    }

    $log("Restore process complete.");
    return ['success' => true, 'error' => null];
}

/**
 * Restore database using temp DB + atomic RENAME TABLE swap
 *
 * In exec mode: uses mysql CLI for all operations.
 * In PHP mode: uses PDO for all operations with streaming SQL import.
 *
 * @param string $tempDir Temporary directory with extracted archive
 * @param array $creds Database credentials
 * @param array $manifest Backup manifest data
 * @param callable $log Logging callback
 * @return array{success: bool, error: string|null}
 */
function restoreDatabase(string $tempDir, array $creds, array $manifest, callable $log): array
{
    $timestamp = date('Ymd_His');
    $restoreDbName = restore_sanitize_identifier($creds['database'] . '_restore_' . $timestamp, 'temp database name');
    $oldDbName = restore_sanitize_identifier($creds['database'] . '_old_' . $timestamp, 'old database name');

    // Find SQL dump file. dump_file is an untrusted manifest value — the dump
    // always sits directly under $tempDir/database/, so a path component
    // (e.g. "../../etc/passwd") is never legitimate; basename() strips one.
    $dumpFile = $manifest['database']['dump_file'] ?? null;
    $sqlPath = $dumpFile ? $tempDir . '/database/' . basename($dumpFile) : null;

    if (!$sqlPath || !file_exists($sqlPath)) {
        // Try to find any .sql file in database/
        $sqlFiles = glob($tempDir . '/database/*.sql');
        if (empty($sqlFiles)) {
            return ['success' => false, 'error' => 'No SQL dump file found in archive'];
        }
        $sqlPath = $sqlFiles[0];
    }

    $execAvailable = isExecAvailable();
    $mysql = '';
    $optionFile = '';

    if ($execAvailable) {
        // A CR/LF in any credential value (web mode: admin-entered on the
        // database step) would inject arbitrary [client] directives into the
        // option file — reject rather than attempt to escape.
        foreach (['host', 'port', 'username', 'password'] as $field) {
            if (isset($creds[$field]) && preg_match('/[\r\n]/', (string) $creds[$field])) {
                return ['success' => false, 'error' => "Invalid database credential: \"{$field}\" must not contain a newline"];
            }
        }

        // Create temp MySQL option file
        $optionFile = tempnam(sys_get_temp_dir(), 'mysql_');
        $optionContent = "[client]\nhost={$creds['host']}\nport={$creds['port']}\nuser={$creds['username']}\npassword={$creds['password']}\n";
        file_put_contents($optionFile, $optionContent);
        chmod($optionFile, 0600);
        $mysql = findMysqlBinary();
    }

    try {
        // Create temporary restore database
        $log("  Creating temporary database: {$restoreDbName}");
        if ($execAvailable) {
            $cmd = sprintf(
                '%s --defaults-extra-file=%s -e %s 2>&1',
                escapeshellarg($mysql),
                escapeshellarg($optionFile),
                escapeshellarg("CREATE DATABASE `{$restoreDbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")
            );
            $output = [];
            exec($cmd, $output, $returnCode);
            if ($returnCode !== 0) {
                return ['success' => false, 'error' => 'Failed to create temporary database: ' . implode("\n", $output)];
            }
        } else {
            try {
                $pdo = getRestorePdo($creds);
                $pdo->exec("CREATE DATABASE `{$restoreDbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            } catch (PDOException $e) {
                return ['success' => false, 'error' => 'Failed to create temporary database: ' . $e->getMessage()];
            }
        }

        // Strip DEFINER clauses from dump file so non-root users can import
        stripDefinerFromSqlFile($sqlPath);

        // Import dump into temp database
        $log("  Importing SQL dump...");
        if ($execAvailable) {
            $cmd = sprintf(
                '%s --defaults-extra-file=%s %s < %s 2>&1',
                escapeshellarg($mysql),
                escapeshellarg($optionFile),
                escapeshellarg($restoreDbName),
                escapeshellarg($sqlPath)
            );
            $output = [];
            exec($cmd, $output, $returnCode);
            if ($returnCode !== 0) {
                dropDatabase($creds, $restoreDbName, $mysql, $optionFile);
                return ['success' => false, 'error' => 'Failed to import dump: ' . implode("\n", $output)];
            }
        } else {
            $importResult = phpImportSql($creds, $restoreDbName, $sqlPath);
            if (!$importResult['success']) {
                dropDatabase($creds, $restoreDbName);
                return ['success' => false, 'error' => 'Failed to import dump: ' . ($importResult['error'] ?? 'unknown error')];
            }
        }

        // Verify table count
        $log("  Verifying integrity...");
        if ($execAvailable) {
            $cmd = sprintf(
                // stderr routed to /dev/null, NOT merged via 2>&1 — the mysql CLI on
                // modern MariaDB emits a "Deprecated program name" NOTICE to stderr on
                // every invocation; merging it into stdout would corrupt this
                // single-value parse (silently producing (int)"...notice text..." = 0).
                '%s --defaults-extra-file=%s -N -e %s 2>/dev/null',
                escapeshellarg($mysql),
                escapeshellarg($optionFile),
                escapeshellarg("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '{$restoreDbName}'")
            );
            $output = [];
            exec($cmd, $output, $returnCode);
            $restoredCount = (int)trim($output[0] ?? '0');
        } else {
            $pdo = getRestorePdo($creds);
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ?");
            $stmt->execute([$restoreDbName]);
            $restoredCount = (int)$stmt->fetchColumn();
        }
        $log("  Tables in restore: {$restoredCount}");

        // Get current and restore table lists
        $currentTables = getTableList($creds, $creds['database'], $mysql, $optionFile);
        $restoreTables = getTableList($creds, $restoreDbName, $mysql, $optionFile);

        // Create old database for swap
        if ($execAvailable) {
            $cmd = sprintf(
                '%s --defaults-extra-file=%s -e %s 2>&1',
                escapeshellarg($mysql),
                escapeshellarg($optionFile),
                escapeshellarg("CREATE DATABASE `{$oldDbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")
            );
            $output = [];
            exec($cmd, $output, $returnCode);
            if ($returnCode !== 0) {
                dropDatabase($creds, $restoreDbName, $mysql, $optionFile);
                return ['success' => false, 'error' => 'Failed to create old database for swap'];
            }
        } else {
            try {
                $pdo = getRestorePdo($creds);
                $pdo->exec("CREATE DATABASE `{$oldDbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            } catch (PDOException $e) {
                dropDatabase($creds, $restoreDbName);
                return ['success' => false, 'error' => 'Failed to create old database for swap: ' . $e->getMessage()];
            }
        }

        // Build atomic RENAME TABLE statement. Db names ($creds['database'],
        // $oldDbName, $restoreDbName) were restore_sanitize_identifier()'d/
        // derived-from-validated above; $table is untrusted (from
        // information_schema of the imported database) and must be quoted.
        $renames = [];
        foreach ($currentTables as $table) {
            $renames[] = "`{$creds['database']}`." . restore_quote_identifier($table) . " TO `{$oldDbName}`." . restore_quote_identifier($table);
        }
        foreach ($restoreTables as $table) {
            $renames[] = "`{$restoreDbName}`." . restore_quote_identifier($table) . " TO `{$creds['database']}`." . restore_quote_identifier($table);
        }

        if (empty($renames)) {
            dropDatabase($creds, $restoreDbName, $mysql, $optionFile);
            dropDatabase($creds, $oldDbName, $mysql, $optionFile);
            return ['success' => false, 'error' => 'No tables found to swap'];
        }

        // Save and drop triggers from BOTH databases before cross-DB rename
        // (MySQL does not allow RENAME TABLE across databases when tables have triggers)
        $log("  Saving triggers before cross-database rename...");
        $currentTriggers = getTriggerList($creds, $creds['database'], $mysql, $optionFile);
        $restoreTriggers = getTriggerList($creds, $restoreDbName, $mysql, $optionFile);

        if (!empty($currentTriggers)) {
            $log("  Dropping " . count($currentTriggers) . " triggers from current database...");
            dropTriggerList($creds, $creds['database'], $currentTriggers, $mysql, $optionFile);
        }
        if (!empty($restoreTriggers)) {
            $log("  Dropping " . count($restoreTriggers) . " triggers from restore database...");
            dropTriggerList($creds, $restoreDbName, $restoreTriggers, $mysql, $optionFile);
        }

        // Execute atomic swap
        $log("  Executing atomic table swap (" . count($renames) . " operations)...");
        $renameSQL = "RENAME TABLE " . implode(', ', $renames);

        if ($execAvailable) {
            $fullSQL = "SET FOREIGN_KEY_CHECKS = 0; {$renameSQL}; SET FOREIGN_KEY_CHECKS = 1;";
            $cmd = sprintf(
                '%s --defaults-extra-file=%s -e %s 2>&1',
                escapeshellarg($mysql),
                escapeshellarg($optionFile),
                escapeshellarg($fullSQL)
            );
            $output = [];
            exec($cmd, $output, $returnCode);

            if ($returnCode !== 0) {
                $log("  SWAP FAILED - restoring current database triggers...");
                if (!empty($currentTriggers)) {
                    recreateTriggerList($creds, $creds['database'], $currentTriggers, $mysql, $optionFile);
                }
                dropDatabase($creds, $oldDbName, $mysql, $optionFile);
                dropDatabase($creds, $restoreDbName, $mysql, $optionFile);
                return ['success' => false, 'error' => 'Atomic table swap failed: ' . implode("\n", $output)];
            }
        } else {
            try {
                $pdo = getRestorePdo($creds);
                $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
                $pdo->exec($renameSQL);
                $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
            } catch (PDOException $e) {
                $log("  SWAP FAILED - restoring current database triggers...");
                if (!empty($currentTriggers)) {
                    recreateTriggerList($creds, $creds['database'], $currentTriggers, $mysql, $optionFile);
                }
                dropDatabase($creds, $oldDbName);
                dropDatabase($creds, $restoreDbName);
                return ['success' => false, 'error' => 'Atomic table swap failed: ' . $e->getMessage()];
            }
        }

        // Recreate triggers from the restore DB in the current DB (tables are now swapped)
        if (!empty($restoreTriggers)) {
            $log("  Recreating " . count($restoreTriggers) . " triggers in current database...");
            recreateTriggerList($creds, $creds['database'], $restoreTriggers, $mysql, $optionFile);
        }

        // Cleanup: drop old and empty restore databases
        $log("  Cleaning up temporary databases...");
        clearRestorePdoCache();
        dropDatabase($creds, $oldDbName, $mysql, $optionFile);
        dropDatabase($creds, $restoreDbName, $mysql, $optionFile);

        // Fix backup records captured mid-creation with 'in_progress' status
        if ($execAvailable) {
            $cmd = sprintf(
                '%s --defaults-extra-file=%s %s -e %s 2>&1',
                escapeshellarg($mysql),
                escapeshellarg($optionFile),
                escapeshellarg($creds['database']),
                escapeshellarg("UPDATE backups SET status = 'completed' WHERE status = 'in_progress'")
            );
            exec($cmd);
        } else {
            try {
                $pdo = getRestorePdo($creds, $creds['database']);
                $pdo->exec("UPDATE backups SET status = 'completed' WHERE status = 'in_progress'");
            } catch (PDOException $e) {
                // Non-critical, ignore
            }
        }

        $log("  Database restore successful.");
        return ['success' => true, 'error' => null];

    } finally {
        // Securely remove option file (exec mode only)
        if ($execAvailable && $optionFile && file_exists($optionFile)) {
            file_put_contents($optionFile, str_repeat("\0", 256));
            unlink($optionFile);
        }
    }
}

/**
 * Restore files from extracted archive
 *
 * In exec mode: uses rsync.
 * In PHP mode: uses pure PHP directory sync.
 *
 * @param string $filesDir Extracted files directory
 * @param callable $log Logging callback
 * @return array{success: bool, error: string|null}
 */
function restoreFiles(string $filesDir, callable $log): array
{
    $rootDir = RESTORE_ROOT;

    // Create maintenance mode flag
    file_put_contents($rootDir . '/.maintenance', date('c'));

    $excludes = ['storage/backup', '.restore_enabled'];

    if (isExecAvailable()) {
        $cmd = sprintf(
            'rsync -a --delete --exclude=%s --exclude=%s %s/ %s/ 2>&1',
            escapeshellarg('storage/backup'),
            escapeshellarg('.restore_enabled'),
            escapeshellarg($filesDir),
            escapeshellarg($rootDir)
        );
        $output = [];
        exec($cmd, $output, $returnCode);
        $success = ($returnCode === 0);
        $error = $success ? null : 'File restore (rsync) failed: ' . implode("\n", $output);
    } else {
        $result = phpSyncDirectories($filesDir, $rootDir, $excludes);
        $success = $result['success'];
        $error = $result['error'];
    }

    // Remove maintenance mode
    if (file_exists($rootDir . '/.maintenance')) {
        unlink($rootDir . '/.maintenance');
    }

    // Clear OPcache
    if (function_exists('opcache_reset')) {
        opcache_reset();
    }

    if (!$success) {
        return ['success' => false, 'error' => $error ?? 'File restore failed'];
    }

    $log("  File restore successful.");
    return ['success' => true, 'error' => null];
}

/**
 * Find mysql binary path
 *
 * @return string Path to mysql binary
 */
function findMysqlBinary(): string
{
    foreach (['/bin/mysql', '/usr/bin/mysql', '/usr/local/bin/mysql'] as $path) {
        if (file_exists($path)) {
            return $path;
        }
    }

    $output = [];
    exec('which mysql 2>&1', $output);
    return trim($output[0] ?? 'mysql');
}

/**
 * Get list of base tables in a database
 *
 * In exec mode: uses mysql CLI.
 * In PHP mode: uses PDO query on information_schema.
 *
 * @param array $creds Database credentials
 * @param string $database Database name
 * @param string $mysql MySQL binary path (exec mode only)
 * @param string $optionFile MySQL option file path (exec mode only)
 * @return array List of table names
 */
function getTableList(array $creds, string $database, string $mysql = '', string $optionFile = ''): array
{
    if (isExecAvailable() && $mysql && $optionFile) {
        // stderr routed to /dev/null, NOT merged via 2>&1 — see the identical note
        // on the COUNT(*) query above; here every stdout line is taken literally
        // as a table name, so a stray notice line would masquerade as one.
        $cmd = sprintf(
            '%s --defaults-extra-file=%s -N -e %s 2>/dev/null',
            escapeshellarg($mysql),
            escapeshellarg($optionFile),
            escapeshellarg("SELECT table_name FROM information_schema.tables WHERE table_schema = '{$database}' AND table_type = 'BASE TABLE'")
        );
        $output = [];
        exec($cmd, $output);
        return array_filter(array_map('trim', $output));
    }

    try {
        $pdo = getRestorePdo($creds);
        $stmt = $pdo->prepare("SELECT TABLE_NAME FROM information_schema.tables WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE'");
        $stmt->execute([$database]);
        return array_column($stmt->fetchAll(), 'TABLE_NAME');
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * Drop a database
 *
 * In exec mode: uses mysql CLI.
 * In PHP mode: uses PDO.
 *
 * @param array $creds Database credentials
 * @param string $database Database name to drop
 * @param string $mysql MySQL binary path (exec mode only)
 * @param string $optionFile MySQL option file path (exec mode only)
 * @return void
 */
function dropDatabase(array $creds, string $database, string $mysql = '', string $optionFile = ''): void
{
    if (isExecAvailable() && $mysql && $optionFile) {
        $cmd = sprintf(
            '%s --defaults-extra-file=%s -e %s 2>&1',
            escapeshellarg($mysql),
            escapeshellarg($optionFile),
            escapeshellarg("DROP DATABASE IF EXISTS `{$database}`")
        );
        exec($cmd);
        return;
    }

    try {
        $pdo = getRestorePdo($creds);
        $pdo->exec("DROP DATABASE IF EXISTS `{$database}`");
    } catch (PDOException $e) {
        // Best-effort cleanup
    }
}

/**
 * Get all trigger definitions from a database
 *
 * In exec mode: uses mysql CLI.
 * In PHP mode: uses PDO queries.
 *
 * @param array $creds Database credentials
 * @param string $database Database name
 * @param string $mysql MySQL binary path (exec mode only)
 * @param string $optionFile MySQL option file path (exec mode only)
 * @return array<int, array{name: string, ddl: string}> Array of trigger info
 */
function getTriggerList(array $creds, string $database, string $mysql = '', string $optionFile = ''): array
{
    if (isExecAvailable() && $mysql && $optionFile) {
        // stderr routed to /dev/null — see the note on getTableList()'s identical pattern.
        $cmd = sprintf(
            '%s --defaults-extra-file=%s -N -e %s 2>/dev/null',
            escapeshellarg($mysql),
            escapeshellarg($optionFile),
            escapeshellarg("SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = '{$database}'")
        );
        $output = [];
        exec($cmd, $output, $returnCode);

        if ($returnCode !== 0 || empty($output)) {
            return [];
        }

        $triggerNames = array_filter(array_map('trim', $output));
        $triggers = [];

        foreach ($triggerNames as $triggerName) {
            try {
                $triggerName = restore_sanitize_identifier($triggerName, 'trigger name');
            } catch (InvalidArgumentException $e) {
                continue; // skip triggers with non-whitelist names (defence-in-depth)
            }
            // stderr routed to /dev/null — see the note on getTableList()'s identical
            // pattern; here it additionally protects the tab-indexed column parse below.
            $cmd = sprintf(
                '%s --defaults-extra-file=%s %s -N -e %s 2>/dev/null',
                escapeshellarg($mysql),
                escapeshellarg($optionFile),
                escapeshellarg($database),
                escapeshellarg("SHOW CREATE TRIGGER `{$triggerName}`")
            );
            $ddlOutput = [];
            exec($cmd, $ddlOutput, $ddlReturnCode);

            if ($ddlReturnCode === 0 && !empty($ddlOutput)) {
                $parts = explode("\t", $ddlOutput[0]);
                $ddl = $parts[2] ?? '';
                if (!empty($ddl)) {
                    $ddl = preg_replace('/\s*DEFINER=`[^`]*`@`[^`]*`\s*/', ' ', $ddl);
                    $triggers[] = ['name' => $triggerName, 'ddl' => $ddl];
                }
            }
        }

        return $triggers;
    }

    // PHP fallback via PDO
    try {
        $pdo = getRestorePdo($creds, $database);

        $stmt = $pdo->prepare("SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ?");
        $stmt->execute([$database]);
        $triggerNames = array_column($stmt->fetchAll(), 'TRIGGER_NAME');

        if (empty($triggerNames)) {
            return [];
        }

        $triggers = [];
        foreach ($triggerNames as $triggerName) {
            try {
                $triggerName = restore_sanitize_identifier($triggerName, 'trigger name');
            } catch (InvalidArgumentException $e) {
                continue; // skip triggers with non-whitelist names (defence-in-depth)
            }
            try {
                $stmt = $pdo->query("SHOW CREATE TRIGGER `{$triggerName}`");
                $row = $stmt->fetch(PDO::FETCH_NUM);
                $ddl = $row[2] ?? '';
                if (!empty($ddl)) {
                    $ddl = preg_replace('/\s*DEFINER=`[^`]*`@`[^`]*`\s*/', ' ', $ddl);
                    $triggers[] = ['name' => $triggerName, 'ddl' => $ddl];
                }
            } catch (PDOException $e) {
                continue;
            }
        }

        return $triggers;
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * Drop all specified triggers from a database
 *
 * In exec mode: uses mysql CLI.
 * In PHP mode: uses PDO.
 *
 * @param array $creds Database credentials
 * @param string $database Database name
 * @param array<int, array{name: string, ddl: string}> $triggers Triggers to drop
 * @param string $mysql MySQL binary path (exec mode only)
 * @param string $optionFile MySQL option file path (exec mode only)
 * @return void
 */
function dropTriggerList(array $creds, string $database, array $triggers, string $mysql = '', string $optionFile = ''): void
{
    if (empty($triggers)) {
        return;
    }

    if (isExecAvailable() && $mysql && $optionFile) {
        $dropStatements = [];
        foreach ($triggers as $trigger) {
            $dropStatements[] = "DROP TRIGGER IF EXISTS `{$trigger['name']}`";
        }
        $dropSQL = implode('; ', $dropStatements) . ';';

        $cmd = sprintf(
            '%s --defaults-extra-file=%s %s -e %s 2>&1',
            escapeshellarg($mysql),
            escapeshellarg($optionFile),
            escapeshellarg($database),
            escapeshellarg($dropSQL)
        );
        exec($cmd);
        return;
    }

    // PHP fallback via PDO
    try {
        $pdo = getRestorePdo($creds, $database);
        foreach ($triggers as $trigger) {
            try {
                $pdo->exec("DROP TRIGGER IF EXISTS `{$trigger['name']}`");
            } catch (PDOException $e) {
                continue;
            }
        }
    } catch (PDOException $e) {
        // Best-effort
    }
}

/**
 * Recreate triggers in a database from saved DDL statements
 *
 * In exec mode: uses mysql CLI.
 * In PHP mode: uses PDO.
 *
 * @param array $creds Database credentials
 * @param string $database Database name
 * @param array<int, array{name: string, ddl: string}> $triggers Triggers to recreate
 * @param string $mysql MySQL binary path (exec mode only)
 * @param string $optionFile MySQL option file path (exec mode only)
 * @return void
 */
function recreateTriggerList(array $creds, string $database, array $triggers, string $mysql = '', string $optionFile = ''): void
{
    if (empty($triggers)) {
        return;
    }

    if (isExecAvailable() && $mysql && $optionFile) {
        foreach ($triggers as $trigger) {
            if (!restore_is_safe_trigger_ddl($trigger['ddl'] ?? '')) {
                // Refuse to replay trigger DDL that does not begin with CREATE [DEFINER=...] TRIGGER.
                // Defence-in-depth against a crafted backup; the trigger is simply skipped.
                continue;
            }
            $sql = "DROP TRIGGER IF EXISTS `{$trigger['name']}`; {$trigger['ddl']}";
            $cmd = sprintf(
                '%s --defaults-extra-file=%s %s -e %s 2>&1',
                escapeshellarg($mysql),
                escapeshellarg($optionFile),
                escapeshellarg($database),
                escapeshellarg($sql)
            );
            exec($cmd);
        }
        return;
    }

    // PHP fallback via PDO
    try {
        $pdo = getRestorePdo($creds, $database);
        foreach ($triggers as $trigger) {
            if (!restore_is_safe_trigger_ddl($trigger['ddl'] ?? '')) {
                continue; // same DDL guard as the exec branch above
            }
            try {
                $pdo->exec("DROP TRIGGER IF EXISTS `{$trigger['name']}`");
                $pdo->exec($trigger['ddl']);
            } catch (PDOException $e) {
                continue;
            }
        }
    } catch (PDOException $e) {
        // Best-effort
    }
}

/**
 * Strip DEFINER clauses from a SQL file to allow import by non-root users
 *
 * In exec mode: uses sed.
 * In PHP mode: uses preg_replace with streaming for large files.
 *
 * @param string $filePath Path to the SQL file to modify in place
 * @return bool True if stripping succeeded, false on failure
 */
function stripDefinerFromSqlFile(string $filePath): bool
{
    if (!file_exists($filePath) || filesize($filePath) === 0) {
        return false;
    }

    // Pattern 1: /*!50017 DEFINER=`root`@`localhost`*/  (conditional comment form)
    $pattern1 = '/\/\*![0-9]+ DEFINER=`[^`]*`@`[^`]*`\s*\*\//';
    // Pattern 2: DEFINER=`root`@`localhost`  (bare form)
    $pattern2 = '/DEFINER=`[^`]*`@`[^`]*`\s*/';

    if (isExecAvailable()) {
        $cmd = sprintf(
            'sed -i -e %s -e %s %s 2>&1',
            escapeshellarg('s/\/\*![0-9]* DEFINER=`[^`]*`@`[^`]*`\s*\*\///g'),
            escapeshellarg('s/DEFINER=`[^`]*`@`[^`]*`\s*//g'),
            escapeshellarg($filePath)
        );

        $output = [];
        $returnCode = 0;
        exec($cmd, $output, $returnCode);

        if ($returnCode !== 0 || !file_exists($filePath) || filesize($filePath) === 0) {
            return false;
        }

        return true;
    }

    // PHP fallback
    $fileSize = filesize($filePath);

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

    return file_exists($filePath) && filesize($filePath) > 0;
}

// --- PHP Fallback Functions (inline for standalone operation) ---

/**
 * Import a SQL file into a database using PDO with streaming DELIMITER-aware parsing
 *
 * Reads the file line-by-line to avoid memory issues with large dumps.
 * Handles DELIMITER directives for triggers, routines, and events.
 *
 * @param array $creds Database credentials
 * @param string $database Target database name
 * @param string $sqlFilePath Path to SQL file
 * @return array{success: bool, error: string|null}
 */
function phpImportSql(array $creds, string $database, string $sqlFilePath): array
{
    try {
        $pdo = getRestorePdo($creds, $database);

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

                if ($stmt !== '' && !isCommentOnly($stmt)) {
                    try {
                        $pdo->exec($stmt);
                    } catch (PDOException $e) {
                        // Log but continue (matching mysql CLI behavior)
                        $errors[] = $e->getMessage();
                    }
                }

                $currentStatement = '';
            }
        }

        // Handle remaining statement
        $remaining = trim($currentStatement);
        if ($remaining !== '' && !isCommentOnly($remaining)) {
            try {
                $pdo->exec($remaining);
            } catch (PDOException $e) {
                $errors[] = $e->getMessage();
            }
        }

        fclose($handle);

        // Restore session variables
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
        $pdo->exec("SET UNIQUE_CHECKS = @OLD_UNIQUE_CHECKS");

        return ['success' => true, 'error' => null];
    } catch (Exception $e) {
        if (isset($handle) && is_resource($handle)) {
            fclose($handle);
        }
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Check if a SQL string contains only comments
 *
 * @param string $sql SQL string to check
 * @return bool True if string is only comments
 */
function isCommentOnly(string $sql): bool
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

/**
 * Synchronize directories using pure PHP (rsync replacement)
 *
 * Phase 1: Copy all files from source to dest (create dirs, overwrite changed files).
 * Phase 2: Delete files in dest that don't exist in source (respecting excludes).
 *
 * @param string $source Source directory
 * @param string $dest Destination directory
 * @param array $excludes Relative paths to exclude from sync/deletion
 * @return array{success: bool, error: string|null}
 */
function phpSyncDirectories(string $source, string $dest, array $excludes = []): array
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

        // Phase 1: Copy source → dest
        $sourceFiles = buildFileManifest($source);
        foreach ($sourceFiles as $relativePath => $info) {
            if (isPathExcluded($relativePath, $normalizedExcludes)) {
                continue;
            }

            $srcPath = $source . '/' . $relativePath;
            $dstPath = $dest . '/' . $relativePath;

            // Defense-in-depth: $relativePath is walked from an already-extracted
            // (already containment-checked at extraction time) tree, so this
            // should be unreachable in practice — except for a symlink placed
            // inside that tree pointing outside it, which this catches, and a
            // not-yet-existing $dstPath, which realpath()-based checks can't
            // (restore_assert_path_contained() works lexically instead).
            if (is_link($srcPath)) {
                $real = realpath($srcPath);
                if ($real === false || !str_starts_with($real . '/', $source . '/')) {
                    continue;
                }
            }
            restore_assert_path_contained($dest, $dstPath);

            if ($info['type'] === 'dir') {
                if (!is_dir($dstPath)) {
                    mkdir($dstPath, 0775, true);
                }
            } else {
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
        $destFiles = buildFileManifest($dest);
        $filesToDelete = [];
        $dirsToDelete = [];

        foreach ($destFiles as $relativePath => $info) {
            if (isPathExcluded($relativePath, $normalizedExcludes)) {
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
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Build a manifest of all files and directories under a given path
 *
 * @param string $basePath Base directory path
 * @return array<string, array{type: string}> Map of relative paths to file info
 */
function buildFileManifest(string $basePath): array
{
    $manifest = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($basePath, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
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
 * Check if a relative path is excluded from sync operations
 *
 * @param string $relativePath Path to check
 * @param array $excludes Normalized exclude patterns
 * @return bool True if path should be excluded
 */
function isPathExcluded(string $relativePath, array $excludes): bool
{
    foreach ($excludes as $exclude) {
        if (str_starts_with($relativePath, $exclude)) {
            return true;
        }
    }
    return false;
}

/**
 * Recursively remove a directory
 */
function cleanupDir(string $dir): void
{
    if (!is_dir($dir)) return;

    $entries = scandir($dir);
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $path = $dir . '/' . $entry;
        is_dir($path) ? cleanupDir($path) : unlink($path);
    }
    rmdir($dir);
}

// ============================================================================
// RENDER FUNCTIONS (self-contained HTML)
// ============================================================================

/**
 * Render the disabled page
 */
function renderDisabledPage(): void
{
    renderPage('Restore Disabled', '
        <div class="alert alert-warning">
            <h5>Restore mode is disabled</h5>
            <p>To enable the restore functionality, create a file named <code>.restore_enabled</code> in the project root directory.</p>
            <p>For example, via SSH:</p>
            <pre>touch ' . htmlspecialchars(RESTORE_ROOT) . '/.restore_enabled</pre>
        </div>
    ');
}

/**
 * Render Step 1: Restore Token
 */
function renderTokenStep(string $error): void
{
    $errorHtml = $error ? '<div class="alert alert-danger">' . htmlspecialchars($error) . '</div>' : '';

    renderPage('Step 1: Restore Token', '
        ' . $errorHtml . '
        <p>Enter the restore token that was provided when the backup was created. This token is stored in the <code>manifest.json</code> file inside the backup archive.</p>
        <form method="POST" action="?step=token">
            ' . restore_csrf_field() . '
            <div class="mb-3">
                <label class="form-label">Restore Token</label>
                <input type="text" name="restore_token" class="form-control" required placeholder="Enter restore token" autocomplete="off">
            </div>
            <button type="submit" class="btn btn-primary w-100">Continue</button>
        </form>
    ', 1);
}

/**
 * Render Step 2: Database Credentials
 */
function renderDatabaseStep(array $creds, string $error): void
{
    $errorHtml = $error ? '<div class="alert alert-danger">' . htmlspecialchars($error) . '</div>' : '';

    renderPage('Step 2: Database Credentials', '
        ' . $errorHtml . '
        <p>Enter the database credentials for the target server. Fields are pre-filled from <code>.env</code> if available.</p>
        <form method="POST" action="?step=database">
            ' . restore_csrf_field() . '
            <div class="row g-3">
                <div class="col-8">
                    <label class="form-label">Host</label>
                    <input type="text" name="db_host" class="form-control" value="' . htmlspecialchars($creds['host']) . '" required>
                </div>
                <div class="col-4">
                    <label class="form-label">Port</label>
                    <input type="number" name="db_port" class="form-control" value="' . (int)$creds['port'] . '">
                </div>
                <div class="col-6">
                    <label class="form-label">Username</label>
                    <input type="text" name="db_user" class="form-control" value="' . htmlspecialchars($creds['username']) . '" required>
                </div>
                <div class="col-6">
                    <label class="form-label">Password</label>
                    <input type="password" name="db_pass" class="form-control" value="' . htmlspecialchars($creds['password']) . '">
                </div>
                <div class="col-12">
                    <label class="form-label">Database Name</label>
                    <input type="text" name="db_name" class="form-control" value="' . htmlspecialchars($creds['database']) . '" required>
                </div>
            </div>
            <button type="submit" class="btn btn-primary w-100 mt-3">Continue</button>
        </form>
    ', 2);
}

/**
 * Render Step 3: Upload TGZ
 */
function renderUploadStep(string $error): void
{
    $errorHtml = $error ? '<div class="alert alert-danger">' . htmlspecialchars($error) . '</div>' : '';

    renderPage('Step 3: Upload Backup', '
        ' . $errorHtml . '
        <p>Select the backup TGZ archive to restore from.</p>
        <form method="POST" action="?step=upload" enctype="multipart/form-data">
            ' . restore_csrf_field() . '
            <div class="mb-3">
                <label class="form-label">Backup File (.tgz)</label>
                <input type="file" name="backup_file" class="form-control" accept=".tgz" required>
            </div>
            <button type="submit" class="btn btn-primary w-100">Upload &amp; Verify</button>
        </form>
    ', 3);
}

/**
 * Render Step 4: Confirmation
 */
function renderConfirmStep(array $manifest, string $error = ''): void
{
    $date = $manifest['backup_date'] ?? 'Unknown';
    $type = $manifest['backup_type'] ?? 'full';
    $version = $manifest['app_version'] ?? 'Unknown';
    $tables = $manifest['database']['tables_count'] ?? '?';
    $files = $manifest['files']['total_files'] ?? '?';
    $creator = $manifest['created_by'] ?? 'Unknown';
    $errorHtml = $error ? '<div class="alert alert-danger">' . htmlspecialchars($error) . '</div>' : '';

    renderPage('Step 4: Confirm Restore', '
        ' . $errorHtml . '
        <div class="alert alert-danger">
            <strong>WARNING:</strong> This will permanently replace the current database and files. This action cannot be undone.
        </div>
        <table class="table table-sm">
            <tr><th>Backup Date</th><td>' . htmlspecialchars($date) . '</td></tr>
            <tr><th>Type</th><td>' . htmlspecialchars($type) . '</td></tr>
            <tr><th>App Version</th><td>' . htmlspecialchars($version) . '</td></tr>
            <tr><th>Tables</th><td>' . htmlspecialchars((string)$tables) . '</td></tr>
            <tr><th>Files</th><td>' . htmlspecialchars((string)$files) . '</td></tr>
            <tr><th>Created By</th><td>' . htmlspecialchars($creator) . '</td></tr>
        </table>
        <form method="POST" action="?step=confirm">
            ' . restore_csrf_field() . '
            <input type="hidden" name="confirm" value="1">
            <button type="submit" class="btn btn-danger w-100" onclick="this.disabled=true;this.innerHTML=\'Restoring... Please wait.\';this.form.submit();">
                Execute Restore (IRREVERSIBLE)
            </button>
        </form>
    ', 4);
}

/**
 * Render a page with the base layout
 */
function renderPage(string $title, string $content, int $step = 0): void
{
    $steps = [1 => 'Token', 2 => 'Database', 3 => 'Upload', 4 => 'Confirm'];
    $stepsHtml = '';
    if ($step > 0) {
        $stepsHtml = '<div style="display:flex;gap:8px;margin-bottom:20px;">';
        foreach ($steps as $num => $label) {
            $active = $num === $step ? 'background:#0d6efd;color:#fff;' : ($num < $step ? 'background:#198754;color:#fff;' : 'background:#e9ecef;color:#6c757d;');
            $stepsHtml .= '<div style="flex:1;text-align:center;padding:8px;border-radius:4px;font-size:13px;' . $active . '">' . $num . '. ' . $label . '</div>';
        }
        $stepsHtml .= '</div>';
    }

    echo '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Restore - ' . htmlspecialchars($title) . '</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f8f9fa; color: #212529; line-height: 1.5; }
        .container { max-width: 540px; margin: 40px auto; padding: 0 15px; }
        .card { background: #fff; border: 1px solid #dee2e6; border-radius: 8px; overflow: hidden; }
        .card-header { background: #343a40; color: #fff; padding: 15px 20px; }
        .card-header h4 { margin: 0; font-size: 18px; }
        .card-body { padding: 20px; }
        .form-label { display: block; font-weight: 500; margin-bottom: 4px; font-size: 14px; }
        .form-control { width: 100%; padding: 8px 12px; border: 1px solid #ced4da; border-radius: 4px; font-size: 14px; }
        .form-control:focus { border-color: #86b7fe; outline: 0; box-shadow: 0 0 0 3px rgba(13,110,253,.25); }
        .btn { display: inline-block; padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; font-size: 14px; text-decoration: none; text-align: center; }
        .btn-primary { background: #0d6efd; color: #fff; }
        .btn-primary:hover { background: #0b5ed7; }
        .btn-danger { background: #dc3545; color: #fff; }
        .btn-danger:hover { background: #bb2d3b; }
        .btn-warning { background: #ffc107; color: #000; }
        .alert { padding: 12px 16px; border-radius: 4px; margin-bottom: 15px; font-size: 14px; }
        .alert-danger { background: #f8d7da; color: #842029; border: 1px solid #f5c2c7; }
        .alert-warning { background: #fff3cd; color: #664d03; border: 1px solid #ffecb5; }
        .alert-success { background: #d1e7dd; color: #0f5132; border: 1px solid #badbcc; }
        .alert-info { background: #cff4fc; color: #055160; border: 1px solid #b6effb; }
        .table { width: 100%; border-collapse: collapse; margin-bottom: 15px; font-size: 14px; }
        .table th, .table td { padding: 6px 10px; border-bottom: 1px solid #dee2e6; text-align: left; }
        .table th { width: 120px; color: #6c757d; }
        .row { display: flex; flex-wrap: wrap; gap: 12px; }
        .col-4 { flex: 0 0 calc(33.33% - 8px); }
        .col-6 { flex: 0 0 calc(50% - 6px); }
        .col-8 { flex: 0 0 calc(66.67% - 4px); }
        .col-12 { flex: 0 0 100%; }
        .mb-3 { margin-bottom: 12px; }
        .mt-3 { margin-top: 12px; }
        .w-100 { width: 100%; }
        pre { background: #f8f9fa; padding: 10px; border-radius: 4px; overflow-x: auto; font-size: 13px; }
        code { background: #e9ecef; padding: 2px 6px; border-radius: 3px; font-size: 13px; }
        .text-muted { color: #6c757d; font-size: 12px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <div class="card-header">
                <h4>Restore</h4>
                <span class="text-muted" style="color:#adb5bd;font-size:12px;">v' . RESTORE_VERSION . '</span>
            </div>
            <div class="card-body">
                ' . $stepsHtml . '
                <h5 style="margin-bottom:15px;">' . htmlspecialchars($title) . '</h5>
                ' . $content . '
            </div>
        </div>
    </div>
</body>
</html>';
}
