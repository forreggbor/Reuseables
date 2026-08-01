<?php

declare(strict_types=1);

/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * RemoteService — remote (SFTP) server configuration CRUD and backup transfer.
 */

namespace BackupRestore;

use ActivityLogs\ActivityLogger;
use BackupRestore\Contracts\EncryptorInterface;
use PDO;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SFTP;

/**
 * Manages remote server configurations and SFTP file transfers.
 *
 * Credentials are stored encrypted via the injected {@see EncryptorInterface}.
 * Uses phpseclib 3.x for SFTP operations (pure PHP, no ext-ssh2 required).
 *
 * @package BackupRestore
 */
final class RemoteService
{
    /**
     * @param PDO $pdo Bookkeeping connection
     * @param BackupEngine $backupEngine Used to resolve backup file paths for transfer
     * @param EncryptorInterface $encryptor Encrypts/decrypts stored remote-server credentials
     * @param array{backups:string,backup_profiles:string,backup_remote_servers:string} $tableNames
     * @param callable(string,string):void $logger
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly BackupEngine $backupEngine,
        private readonly EncryptorInterface $encryptor,
        private readonly array $tableNames,
        private $logger,
    ) {
    }

    /**
     * Get all remote server configurations.
     *
     * @return array<int,object> List of server objects (credentials excluded)
     */
    public function getAll(): array
    {
        $table = $this->tableNames['backup_remote_servers'];
        $stmt = $this->pdo->query(
            "SELECT id, name, type, host, port, username, auth_type,
                    remote_path, is_active, last_connected, created_at, updated_at
             FROM {$table}
             ORDER BY name ASC"
        );

        return $stmt->fetchAll(PDO::FETCH_OBJ);
    }

    /**
     * Get a single remote server by ID.
     *
     * @param int $id Server ID
     * @return object|null Server record or null if not found
     */
    public function getById(int $id): ?object
    {
        $table = $this->tableNames['backup_remote_servers'];
        $stmt = $this->pdo->prepare(
            "SELECT id, name, type, host, port, username, auth_type,
                    remote_path, is_active, last_connected, created_at, updated_at
             FROM {$table} WHERE id = :id"
        );
        $stmt->execute([':id' => $id]);

        $result = $stmt->fetch(PDO::FETCH_OBJ);
        return $result ?: null;
    }

    /**
     * Create a new remote server configuration. Credentials are encrypted before storage.
     *
     * @param array $data Server configuration data
     * @return array{success: bool, id: ?int, error: ?string}
     */
    public function create(array $data): array
    {
        $table = $this->tableNames['backup_remote_servers'];

        try {
            $encryptedCredentials = $this->encryptor->encrypt($data['credentials'] ?? '');

            $stmt = $this->pdo->prepare(
                "INSERT INTO {$table}
                 (name, type, host, port, username, auth_type, credentials, remote_path, is_active, created_at)
                 VALUES
                 (:name, :type, :host, :port, :username, :auth_type, :credentials, :remote_path, :is_active, NOW())"
            );

            $stmt->execute([
                ':name' => $data['name'],
                ':type' => $data['type'] ?? 'sftp',
                ':host' => $data['host'],
                ':port' => $data['port'] ?? 22,
                ':username' => $data['username'],
                ':auth_type' => $data['auth_type'] ?? 'password',
                ':credentials' => $encryptedCredentials,
                ':remote_path' => $data['remote_path'] ?? '/backups',
                ':is_active' => $data['is_active'] ?? 1,
            ]);

            return ['success' => true, 'id' => (int) $this->pdo->lastInsertId(), 'error' => null];
        } catch (\Throwable $e) {
            $this->log('Failed to create remote server: ' . $e->getMessage(), 'ERROR');
            return ['success' => false, 'id' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * Update an existing remote server configuration.
     *
     * If credentials are provided, they are re-encrypted. If credentials are
     * empty, existing credentials are kept.
     *
     * @param int $id Server ID
     * @param array $data Updated server data. `reset_host_key` (bool): clears
     *        the pinned SSH host-key fingerprint so the next connection
     *        re-pins via trust-on-first-use — use only after confirming a
     *        legitimate key rotation. `acting_user_id` (int|null): attributed
     *        on the reset's audit entry.
     * @return array{success: bool, error: ?string}
     */
    public function update(int $id, array $data): array
    {
        $table = $this->tableNames['backup_remote_servers'];

        $server = $this->getById($id);
        if (!$server) {
            return ['success' => false, 'error' => 'Remote server not found'];
        }

        try {
            if (!empty($data['credentials'])) {
                $encryptedCredentials = $this->encryptor->encrypt($data['credentials']);

                $stmt = $this->pdo->prepare(
                    "UPDATE {$table} SET
                     name = :name, type = :type, host = :host, port = :port,
                     username = :username, auth_type = :auth_type,
                     credentials = :credentials, remote_path = :remote_path,
                     is_active = :is_active, updated_at = NOW()
                     WHERE id = :id"
                );
                $stmt->execute([
                    ':name' => $data['name'],
                    ':type' => $data['type'] ?? 'sftp',
                    ':host' => $data['host'],
                    ':port' => $data['port'] ?? 22,
                    ':username' => $data['username'],
                    ':auth_type' => $data['auth_type'] ?? 'password',
                    ':credentials' => $encryptedCredentials,
                    ':remote_path' => $data['remote_path'] ?? '/backups',
                    ':is_active' => $data['is_active'] ?? 1,
                    ':id' => $id,
                ]);
            } else {
                $stmt = $this->pdo->prepare(
                    "UPDATE {$table} SET
                     name = :name, type = :type, host = :host, port = :port,
                     username = :username, auth_type = :auth_type,
                     remote_path = :remote_path, is_active = :is_active,
                     updated_at = NOW()
                     WHERE id = :id"
                );
                $stmt->execute([
                    ':name' => $data['name'],
                    ':type' => $data['type'] ?? 'sftp',
                    ':host' => $data['host'],
                    ':port' => $data['port'] ?? 22,
                    ':username' => $data['username'],
                    ':auth_type' => $data['auth_type'] ?? 'password',
                    ':remote_path' => $data['remote_path'] ?? '/backups',
                    ':is_active' => $data['is_active'] ?? 1,
                    ':id' => $id,
                ]);
            }

            // Explicit, audited opt-in only — clearing the pinned host key is a
            // deliberate trust decision (e.g. the operator confirmed a legitimate
            // key rotation), never an implicit side effect of an unrelated edit.
            if (!empty($data['reset_host_key'])) {
                $priorFpStmt = $this->pdo->prepare("SELECT host_key_fingerprint FROM {$table} WHERE id = :id");
                $priorFpStmt->execute([':id' => $id]);
                $priorFingerprint = $priorFpStmt->fetchColumn() ?: null;

                $resetStmt = $this->pdo->prepare("UPDATE {$table} SET host_key_fingerprint = NULL WHERE id = :id");
                $resetStmt->execute([':id' => $id]);
                $this->log("[Remote] Host key fingerprint reset for server #{$id} — will re-pin (TOFU) on next connection", 'WARNING');
                $this->audit('remote_server_host_key_reset', $id, ['fingerprint' => $priorFingerprint], null, $data['acting_user_id'] ?? null);
            }

            return ['success' => true, 'error' => null];
        } catch (\Throwable $e) {
            $this->log('Failed to update remote server: ' . $e->getMessage(), 'ERROR');
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Delete a remote server configuration.
     *
     * @param int $id Server ID
     * @return array{success: bool, error: ?string}
     */
    public function delete(int $id): array
    {
        $table = $this->tableNames['backup_remote_servers'];

        try {
            $stmt = $this->pdo->prepare("DELETE FROM {$table} WHERE id = :id");
            $stmt->execute([':id' => $id]);

            return ['success' => true, 'error' => null];
        } catch (\Throwable $e) {
            $this->log('Failed to delete remote server: ' . $e->getMessage(), 'ERROR');
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get decrypted credentials for a remote server.
     *
     * @param int $id Server ID
     * @return string|false Decrypted credentials or false on failure
     */
    public function getDecryptedCredentials(int $id): string|false
    {
        $table = $this->tableNames['backup_remote_servers'];
        $stmt = $this->pdo->prepare("SELECT credentials, auth_type FROM {$table} WHERE id = :id");
        $stmt->execute([':id' => $id]);

        $row = $stmt->fetch(PDO::FETCH_OBJ);
        if (!$row) {
            return false;
        }

        return $this->encryptor->decrypt($row->credentials) ?? false;
    }

    /**
     * Create an SFTP connection to a remote server.
     *
     * @param int $serverId Server ID
     * @return SFTP Connected SFTP instance
     * @throws \RuntimeException If connection or authentication fails
     */
    private function createConnection(int $serverId): SFTP
    {
        $table = $this->tableNames['backup_remote_servers'];
        $stmt = $this->pdo->prepare("SELECT * FROM {$table} WHERE id = :id");
        $stmt->execute([':id' => $serverId]);
        $server = $stmt->fetch(PDO::FETCH_OBJ);

        if (!$server) {
            throw new \RuntimeException('Remote server not found');
        }

        if (!$server->is_active) {
            throw new \RuntimeException('Remote server is inactive');
        }

        $sftp = new SFTP($server->host, (int) $server->port, 30);

        // Host-key verification BEFORE any credential leaves this process:
        // getServerPublicHostKey() only drives the transport-layer key
        // exchange, not authentication, so the key is available and can be
        // rejected here without ever calling login(). Without this, a
        // network MITM presenting its own host key would silently capture
        // the plaintext DB dump (and, for password auth, the SFTP password)
        // on every transfer.
        $this->verifyHostKey($sftp, $server, $serverId, $table);

        $credentials = $this->encryptor->decrypt($server->credentials);
        if ($credentials === null) {
            throw new \RuntimeException('Failed to decrypt server credentials');
        }

        $authenticated = false;

        if ($server->auth_type === 'key') {
            try {
                $key = PublicKeyLoader::load($credentials);
                $authenticated = $sftp->login($server->username, $key);
            } catch (\Throwable $e) {
                throw new \RuntimeException('SSH key authentication failed: ' . $e->getMessage());
            }
        } else {
            $authenticated = $sftp->login($server->username, $credentials);
        }

        if (!$authenticated) {
            throw new \RuntimeException('Authentication failed for ' . $server->username . '@' . $server->host);
        }

        $updateStmt = $this->pdo->prepare("UPDATE {$table} SET last_connected = NOW() WHERE id = :id");
        $updateStmt->execute([':id' => $serverId]);

        return $sftp;
    }

    /**
     * Verify the SFTP server's host key against a pinned fingerprint
     * (trust-on-first-use). The first successful connection to a server
     * persists its host key's fingerprint; every later connection must
     * present the same key or the connection is aborted before any
     * credential is sent.
     *
     * @param SFTP $sftp Connected-but-not-yet-authenticated SFTP instance
     * @param object $server Remote server row (must include id, host, host_key_fingerprint)
     * @param int $serverId
     * @param string $table Resolved backup_remote_servers table name
     * @throws \RuntimeException When the key cannot be read, or does not match the pinned fingerprint
     * @return void
     */
    private function verifyHostKey(SFTP $sftp, object $server, int $serverId, string $table): void
    {
        $hostKey = $sftp->getServerPublicHostKey();
        if ($hostKey === false) {
            throw new \RuntimeException('Could not retrieve host key from ' . $server->host);
        }

        $fingerprint = self::computeHostKeyFingerprint($hostKey);
        $pinned = $server->host_key_fingerprint ?? null;

        if ($pinned !== null && $pinned !== '') {
            if (!hash_equals($pinned, $fingerprint)) {
                $this->log("[Remote] Host key mismatch for {$server->host}: pinned={$pinned} presented={$fingerprint}", 'ERROR');
                throw new \RuntimeException(
                    'Host key verification failed for ' . $server->host . ': the presented key does not match ' .
                    'the pinned fingerprint. This may indicate a man-in-the-middle attack, or the server key was ' .
                    'legitimately rotated — if the latter, clear host_key_fingerprint for this server to re-pin.'
                );
            }
            return;
        }

        // TOFU: no fingerprint pinned yet — persist this one and audit it.
        $pinStmt = $this->pdo->prepare("UPDATE {$table} SET host_key_fingerprint = :fp WHERE id = :id");
        $pinStmt->execute([':fp' => $fingerprint, ':id' => $serverId]);
        $this->log("[Remote] Host key pinned (first connection) for {$server->host}: {$fingerprint}", 'INFO');
        $this->audit('remote_server_host_key_pinned', $serverId, null, ['fingerprint' => $fingerprint, 'host' => $server->host], null);
    }

    /**
     * Compute an OpenSSH-compatible `SHA256:<base64>` fingerprint from the
     * raw "<algorithm> <base64-key>" string {@see SFTP::getServerPublicHostKey()}
     * returns, matching the format `ssh-keygen -E sha256 -lf` produces —
     * operators can pre-seed a fingerprint by comparing against that tool's
     * output.
     *
     * @param string $rawHostKey e.g. "ssh-rsa AAAAB3NzaC1yc2EA..."
     * @return string
     */
    private static function computeHostKeyFingerprint(string $rawHostKey): string
    {
        $parts = explode(' ', $rawHostKey, 3);
        $keyBlob = $parts[1] ?? $rawHostKey;
        $decoded = base64_decode($keyBlob, true);

        if ($decoded === false) {
            // Malformed/unexpected format — still produce a stable, comparable
            // fingerprint (prefixed distinctly so it's never confused with a
            // real OpenSSH SHA256 fingerprint an operator might pre-seed).
            return 'RAW-SHA256:' . base64_encode(hash('sha256', $rawHostKey, true));
        }

        return 'SHA256:' . rtrim(base64_encode(hash('sha256', $decoded, true)), '=');
    }

    /**
     * Test connection to a remote server.
     *
     * Verifies connectivity, authentication, and write permissions on the remote path.
     *
     * @param int $serverId Server ID
     * @return array{success: bool, error: ?string, message: ?string}
     */
    public function testConnection(int $serverId): array
    {
        try {
            $sftp = $this->createConnection($serverId);

            $server = $this->getById($serverId);
            $remotePath = $server->remote_path ?? '/backups';

            if (!$sftp->is_dir($remotePath)) {
                if (!$sftp->mkdir($remotePath, 0775, true)) {
                    return ['success' => false, 'error' => 'Remote path does not exist and could not be created: ' . $remotePath, 'message' => null];
                }
            }

            $testFile = $remotePath . '/.app_test_' . bin2hex(random_bytes(4));
            if (!$sftp->put($testFile, 'test')) {
                return ['success' => false, 'error' => 'Cannot write to remote path: ' . $remotePath, 'message' => null];
            }
            $sftp->delete($testFile);

            return [
                'success' => true,
                'error' => null,
                'message' => 'Connection successful. Write permission verified on ' . $remotePath,
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage(), 'message' => null];
        }
    }

    /**
     * Transfer a backup file to a remote server.
     *
     * Uploads the backup TGZ file to the remote server's configured path.
     * Progress is tracked via a temporary file for polling.
     *
     * @param int $backupId Backup record ID
     * @param int $serverId Remote server ID
     * @return array{success: bool, error: ?string}
     */
    public function transferToRemote(int $backupId, int $serverId): array
    {
        $backup = $this->backupEngine->getBackup($backupId);
        if (!$backup) {
            return ['success' => false, 'error' => 'Backup not found'];
        }

        if ($backup->file_deleted_at !== null) {
            return ['success' => false, 'error' => 'Backup file has been deleted'];
        }

        $localPath = $this->backupEngine->getBackupDir() . '/' . $backup->filename;
        if (!file_exists($localPath)) {
            return ['success' => false, 'error' => 'Backup file not found on disk'];
        }

        $server = $this->getById($serverId);
        if (!$server) {
            return ['success' => false, 'error' => 'Remote server not found'];
        }

        $progressKey = 'transfer_' . $backupId . '_' . $serverId . '_' . time();
        $progressFile = $this->backupEngine->getTempDir() . '/' . $progressKey . '.json';

        $tempDir = $this->backupEngine->getTempDir();
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0775, true);
        }

        set_time_limit(600);

        try {
            $sftp = $this->createConnection($serverId);

            $remotePath = $server->remote_path ?? '/backups';
            $remoteFile = $remotePath . '/' . $backup->filename;
            // Upload to a temp name first so an interrupted transfer never leaves a
            // truncated file at the final name. The per-attempt random suffix keeps
            // two concurrent transfer attempts for the same backup+server from
            // colliding on the same remote temp path. The hex goes BEFORE ".part" so
            // cleanupOrphanedPartFiles()'s str_ends_with($name, '.part') match still
            // recognizes orphans of either form.
            $remoteTempFile = $remotePath . '/' . $backup->filename . '.' . bin2hex(random_bytes(6)) . '.part';

            if (!$sftp->is_dir($remotePath)) {
                $sftp->mkdir($remotePath, 0775, true);
            }

            $fileSize = filesize($localPath);

            $this->writeProgress($progressFile, [
                'status' => 'uploading',
                'percent' => 0,
                'bytes_sent' => 0,
                'total_bytes' => $fileSize,
                'filename' => $backup->filename,
            ]);

            $bytesSent = 0;
            $lastProgressUpdate = 0;

            $result = $sftp->put($remoteTempFile, $localPath, SFTP::SOURCE_LOCAL_FILE, -1, -1, function ($sent) use ($progressFile, $fileSize, &$bytesSent, &$lastProgressUpdate) {
                $bytesSent = $sent;
                $now = time();
                if ($now > $lastProgressUpdate) {
                    $lastProgressUpdate = $now;
                    $percent = $fileSize > 0 ? round(($sent / $fileSize) * 100, 1) : 0;
                    $this->writeProgress($progressFile, [
                        'status' => 'uploading',
                        'percent' => $percent,
                        'bytes_sent' => $sent,
                        'total_bytes' => $fileSize,
                    ]);
                }
            });

            if (!$result) {
                $sftp->delete($remoteTempFile);
                $this->writeProgress($progressFile, ['status' => 'failed', 'error' => 'Upload failed']);
                return ['success' => false, 'error' => 'Failed to upload file to remote server'];
            }

            // Verify the upload landed completely before trusting it — a true remote
            // SHA-256 isn't cheaply obtainable over plain SFTP, so size comparison is
            // the practical check (still catches truncation).
            $remoteSize = $sftp->size($remoteTempFile);
            if ($remoteSize === false || (int) $remoteSize !== $fileSize) {
                $sftp->delete($remoteTempFile);
                $sizeInfo = $remoteSize === false ? 'could not stat remote file' : "remote size {$remoteSize} != local size {$fileSize}";
                $this->log("Remote transfer size mismatch for backup_id={$backupId}: {$sizeInfo}", 'ERROR');
                $this->writeProgress($progressFile, ['status' => 'failed', 'error' => 'Uploaded file size mismatch: ' . $sizeInfo]);
                return ['success' => false, 'error' => 'Uploaded file failed integrity check (size mismatch) and was discarded: ' . $sizeInfo];
            }

            // Publish under the final name via a rename-SWAP rather than delete-then-rename.
            // Most SFTP servers refuse to rename onto an existing path, so a same-named file
            // from a previous transfer must be moved out of the way first — but deleting it
            // outright would mean a subsequent rename() failure left NO valid file at
            // $remoteFile at all. Sidelining it to a uniquely-named .bak-<hex> instead means
            // there is always a recoverable copy, and a failed publish can put it straight back.
            $hadExisting = false;
            $remoteBackupOfExisting = $remoteFile . '.bak-' . bin2hex(random_bytes(6));
            if ($sftp->file_exists($remoteFile)) {
                if (!$sftp->rename($remoteFile, $remoteBackupOfExisting)) {
                    $sftp->delete($remoteTempFile);
                    $this->log("Remote transfer: could not sideline existing {$remoteFile} before publish", 'ERROR');
                    $this->writeProgress($progressFile, ['status' => 'failed', 'error' => 'Failed to finalize uploaded file on remote server']);
                    return ['success' => false, 'error' => 'Failed to finalize uploaded file on remote server'];
                }
                $hadExisting = true;
            }

            if (!$sftp->rename($remoteTempFile, $remoteFile)) {
                $this->log("Remote transfer: failed to rename {$remoteTempFile} to {$remoteFile}", 'ERROR');
                if ($hadExisting && !$sftp->rename($remoteBackupOfExisting, $remoteFile)) {
                    $this->log(
                        "Remote transfer: CRITICAL — publish failed AND could not restore the previous backup from " .
                        "{$remoteBackupOfExisting}; the previous backup is preserved under that name for manual recovery",
                        'ERROR'
                    );
                }
                $sftp->delete($remoteTempFile);
                $this->writeProgress($progressFile, ['status' => 'failed', 'error' => 'Failed to finalize uploaded file on remote server']);
                return ['success' => false, 'error' => 'Failed to finalize uploaded file on remote server'];
            }

            if ($hadExisting) {
                $sftp->delete($remoteBackupOfExisting);
            }

            $this->writeProgress($progressFile, [
                'status' => 'completed',
                'percent' => 100,
                'bytes_sent' => $fileSize,
                'total_bytes' => $fileSize,
            ]);

            // Update backup record — only reached once the file is verified complete
            // and published under its final name.
            $backupsTable = $this->tableNames['backups'];
            $stmt = $this->pdo->prepare(
                "UPDATE {$backupsTable} SET remote_synced = 1, remote_server_id = :server_id WHERE id = :id"
            );
            $stmt->execute([
                ':server_id' => $serverId,
                ':id' => $backupId,
            ]);

            return ['success' => true, 'error' => null, 'progress_key' => $progressKey];
        } catch (\Throwable $e) {
            // Best-effort cleanup of the partial upload — a secondary failure
            // here (e.g. the connection that just failed can't be used to
            // delete anything either) must not replace the real error below
            // with an uncaught exception; cleanupOrphanedPartFiles() reclaims
            // any ".part" left behind on the next sweep regardless.
            if (isset($sftp, $remoteTempFile)) {
                try {
                    $sftp->delete($remoteTempFile);
                } catch (\Throwable $cleanupError) {
                    $this->log('Could not clean up partial upload ' . $remoteTempFile . ': ' . $cleanupError->getMessage(), 'WARNING');
                }
            }
            $this->writeProgress($progressFile, ['status' => 'failed', 'error' => $e->getMessage()]);
            $this->log('Remote transfer failed: ' . $e->getMessage(), 'ERROR');
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get transfer progress from tracking file.
     *
     * @param string $key Progress tracking key
     * @return array Progress data
     */
    public function getTransferProgress(string $key): array
    {
        if (empty($key) || !preg_match('/^transfer_\d+_\d+_\d+$/', $key)) {
            return ['status' => 'unknown', 'error' => 'Invalid progress key'];
        }

        $progressFile = $this->backupEngine->getTempDir() . '/' . $key . '.json';

        if (!file_exists($progressFile)) {
            return ['status' => 'unknown', 'error' => 'Progress data not found'];
        }

        $data = json_decode(file_get_contents($progressFile), true);
        if (!$data) {
            return ['status' => 'unknown', 'error' => 'Invalid progress data'];
        }

        // Clean up completed/failed progress files after reading
        if (in_array($data['status'] ?? '', ['completed', 'failed'], true)) {
            unlink($progressFile);
        }

        return $data;
    }

    /**
     * List backup files on a remote server.
     *
     * @param int $serverId Server ID
     * @return array{success: bool, files: ?array, error: ?string}
     */
    public function listRemoteBackups(int $serverId): array
    {
        try {
            $sftp = $this->createConnection($serverId);

            $server = $this->getById($serverId);
            $remotePath = $server->remote_path ?? '/backups';

            $listing = $sftp->rawlist($remotePath);
            if ($listing === false) {
                return ['success' => false, 'files' => null, 'error' => 'Cannot list remote directory'];
            }

            $files = [];
            foreach ($listing as $name => $attrs) {
                if ($name === '.' || $name === '..') {
                    continue;
                }

                if (!str_ends_with($name, '.tgz')) {
                    continue;
                }

                $files[] = [
                    'name' => $name,
                    'size' => $attrs['size'] ?? 0,
                    'size_human' => FileSize::format((int) ($attrs['size'] ?? 0)),
                    'modified' => isset($attrs['mtime']) ? date('Y-m-d H:i:s', $attrs['mtime']) : null,
                ];
            }

            usort($files, fn ($a, $b) => strcmp($b['modified'] ?? '', $a['modified'] ?? ''));

            return ['success' => true, 'files' => $files, 'error' => null];
        } catch (\Throwable $e) {
            return ['success' => false, 'files' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * Delete orphaned ".part" upload temp files older than $maxAgeHours from a
     * remote server's backup directory.
     *
     * Every upload-failure branch in {@see transferToRemote()} attempts to
     * delete() its own ".part" file, but that delete's return value is never
     * checked — a cleanup that itself silently fails (or a request that dies
     * mid-transfer before reaching any cleanup code) leaves the ".part" behind
     * with no other reclaim mechanism.
     *
     * Non-fatal by design: any connection/listing failure is logged and
     * treated as "nothing cleaned" rather than propagated, so a single
     * unreachable remote server can never break the caller's run.
     *
     * @param int $serverId Server ID
     * @param int $maxAgeHours Delete orphaned .part files older than this (default: 24 hours)
     * @return int Number of orphaned .part files deleted
     */
    public function cleanupOrphanedPartFiles(int $serverId, int $maxAgeHours = 24): int
    {
        try {
            $sftp = $this->createConnection($serverId);
        } catch (\Throwable $e) {
            $this->log("Remote cleanup: could not connect to server {$serverId}: " . $e->getMessage(), 'WARNING');
            return 0;
        }

        $server = $this->getById($serverId);
        $remotePath = $server->remote_path ?? '/backups';

        $listing = $sftp->rawlist($remotePath);
        if ($listing === false) {
            $this->log("Remote cleanup: cannot list remote directory {$remotePath} on server {$serverId}", 'WARNING');
            return 0;
        }

        $cutoff = time() - $maxAgeHours * 3600;
        $deleted = 0;

        foreach ($listing as $name => $attrs) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            if (!str_ends_with($name, '.part')) {
                continue;
            }
            // Never delete an entry we can't age-check — no mtime means no confidence
            // it's actually stale, not merely uploaded moments ago.
            if (!isset($attrs['mtime']) || $attrs['mtime'] >= $cutoff) {
                continue;
            }

            if ($sftp->delete($remotePath . '/' . $name)) {
                $deleted++;
            } else {
                $this->log("Remote cleanup: failed to delete orphaned {$remotePath}/{$name}", 'WARNING');
            }
        }

        return $deleted;
    }

    /**
     * Delete a backup file from a remote server.
     *
     * @param int $serverId Server ID
     * @param string $filename Filename to delete on remote server
     * @return array{success: bool, error: ?string}
     */
    public function deleteRemoteBackup(int $serverId, string $filename): array
    {
        // Validate filename (prevent path traversal)
        if (str_contains($filename, '/') || str_contains($filename, '..')) {
            return ['success' => false, 'error' => 'Invalid filename'];
        }

        try {
            $sftp = $this->createConnection($serverId);

            $server = $this->getById($serverId);
            $remotePath = ($server->remote_path ?? '/backups') . '/' . $filename;

            if (!$sftp->delete($remotePath)) {
                return ['success' => false, 'error' => 'Failed to delete remote file'];
            }

            return ['success' => true, 'error' => null];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Write progress data to a tracking file.
     *
     * @param string $filePath Path to progress file
     * @param array $data Progress data to write
     * @return void
     */
    private function writeProgress(string $filePath, array $data): void
    {
        $data['updated_at'] = date('c');
        file_put_contents($filePath, json_encode($data), LOCK_EX);
    }

    /**
     * @param string $message
     * @param string $level
     * @return void
     */
    private function log(string $message, string $level = 'INFO'): void
    {
        try {
            ($this->logger)($message, $level);
        } catch (\Throwable) {
            // A broken host logger must never break a backup/restore operation.
        }
    }

    /**
     * Write an audit entry via the sibling ActivityLogs\ActivityLogger module
     * (direct lib-to-lib dependency, not a contract — see BackupRestore.php).
     * Never lets a logging failure affect the caller: ActivityLogger::log()
     * itself never throws, and a missing ActivityLogger class (host has not
     * made it autoloadable) is silently skipped.
     *
     * @param string $action e.g. 'remote_server_host_key_pinned'
     * @param string|int|null $entityId
     * @param array|null $oldValues
     * @param array|null $newValues
     * @param int|null $userId
     * @return void
     */
    private function audit(string $action, string|int|null $entityId, ?array $oldValues, ?array $newValues, ?int $userId): void
    {
        if (!class_exists(ActivityLogger::class)) {
            return;
        }

        ActivityLogger::log($userId, $action, 'remote_server', $entityId, $oldValues, $newValues, $userId === null ? 'system' : 'admin');
    }
}
