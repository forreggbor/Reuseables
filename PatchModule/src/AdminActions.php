<?php
/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * HTTP action handler for the admin patch-management UI.
 */

declare(strict_types=1);

namespace PatchModule;

use PatchModule\Contracts\AuthAdapterInterface;
use PatchModule\Contracts\CsrfAdapterInterface;
use PatchModule\Contracts\CsrfRotatableInterface;
use PatchModule\Contracts\TranslatorInterface;

/**
 * AdminActions - HTTP action handler for the patch management admin interface
 *
 * Wraps PatchModule and provides one method per admin UI action. Every method
 * returns a plain array — no echo, no header() calls, no exit/die. The host's
 * thin controller calls these methods and sends the response. All user input is
 * received as explicit method parameters; no superglobals are read here.
 *
 * @package PatchModule
 */
class AdminActions
{
    /** @var array<string,string>|null Lazily-loaded fallback locale strings */
    private static ?array $fallbackLocale = null;

    /**
     * @param PatchModule              $module      The PatchModule facade instance
     * @param AuthAdapterInterface     $auth        Host-side authentication adapter
     * @param CsrfAdapterInterface     $csrf        Host-side CSRF adapter
     * @param string                   $tempPath    Writable temp directory for lock and progress files
     * @param string                   $rootPath    Project root directory for filesystem checks
     * @param TranslatorInterface|null $translator  Optional host-side translator; falls back to built-in en_US if null
     * @param int                      $maxUploadSize Maximum .tgz size in bytes (default: 100 MB)
     */
    public function __construct(
        private readonly PatchModule $module,
        private readonly AuthAdapterInterface $auth,
        private readonly CsrfAdapterInterface $csrf,
        private readonly string $tempPath,
        private readonly string $rootPath,
        private readonly ?TranslatorInterface $translator = null,
        private readonly int $maxUploadSize = 104857600,
    ) {
    }

    // =========================================================================
    // Public action methods
    // =========================================================================

    /**
     * Render the admin patch index page data
     *
     * Returns all data needed for the admin patch list: current version,
     * available patches with local IDs, install history, and the user map
     * for installed_by display names.
     *
     * Also performs a stale-availability sweep on each render: any available patch
     * whose version is already at or below the installed application version is marked
     * obsolete. This covers the case where the version was advanced by a direct file
     * copy on the server without going through PatchModule.
     *
     * @return array Response array with 'view', 'data', or 403 on permission failure
     */
    public function index(): array
    {
        if (!$this->auth->isSysadmin()) {
            return $this->forbidden();
        }

        $availability   = $this->module->isAvailable();
        $currentVersion = $this->module->getVersionResolver()->getCurrentVersion();

        // Sweep: mark any available patch whose version <= currentVersion as obsolete.
        // This handles direct file-copy installs that bypassed PatchModule.
        $this->markStaleAvailableRowsObsolete($currentVersion);

        $remotePatches   = $this->module->getAvailablePatches();
        $uploadedPatches = $this->module->getDatabase()->findUploadedAvailablePatches();
        $patches         = $this->enrichPatchesWithLocalIds($this->mergePatches($remotePatches, $uploadedPatches));
        $history         = $this->module->getHistory();

        // Oldest-first among available patches; this determines which row gets the Install button.
        $installableId = $this->selectInstallableId($patches);

        $installedByIds = array_filter(
            array_unique(array_column($history, 'installed_by')),
            fn($id) => $id !== null
        );
        $userMap = $this->auth->getUserMap(array_values(array_map('intval', $installedByIds)));

        return [
            'view' => 'admin/index',
            'data' => [
                'currentVersion' => $currentVersion,
                'patches'        => $patches,
                'history'        => $history,
                'userMap'        => $userMap,
                'installableId'  => $installableId,
                'disabled'       => !($availability['enabled'] ?? true),
                'disabledReason' => $availability['reason'] ?? '',
                'csrfToken'      => $this->csrf->getToken(),
                'baseUrl'        => $this->module->getBaseUrl(),
                'tr'             => $this->getViewTranslator(),
            ],
        ];
    }

    /**
     * Trigger a server-side update check (force-refresh the cache)
     *
     * @param string $csrfToken CSRF token from the request
     * @return array Response array with available patches count or error details
     */
    public function check(string $csrfToken): array
    {
        if (!$this->auth->isSysadmin()) {
            return $this->forbidden();
        }

        if (!$this->csrf->validate($csrfToken)) {
            return $this->csrfError();
        }

        $result = $this->module->checkForUpdates(true);

        return [
            'status' => 200,
            'data'   => [
                'success'    => true,
                'available'  => $result['available'] ?? false,
                'count'      => $result['count'] ?? 0,
                'patches'    => $result['patches'] ?? [],
                'csrf_token' => $this->csrfToken(),
            ],
        ];
    }

    /**
     * Return the file manifest and details for a specific history record
     *
     * @param int $id patch_history record ID
     * @return array Response array with record details and file manifest, or 403/404
     */
    public function details(int $id): array
    {
        if (!$this->auth->isSysadmin()) {
            return $this->forbidden();
        }

        $record = $this->module->getHistoryRecord($id);
        if ($record === null) {
            return [
                'status' => 404,
                'data'   => [
                    'success' => false,
                    'error'   => $this->t('TEXT_ERROR_PATCH_RECORD_NOT_FOUND'),
                ],
            ];
        }

        $manifest = null;
        if (!empty($record['manifest_json'])) {
            $decoded = json_decode((string) $record['manifest_json'], true);
            if (is_array($decoded)) {
                $manifest = $decoded;
            }
        }

        $files = $this->buildFilesManifest($manifest, (string) ($record['status'] ?? ''));

        $releaseNotesHtml = SimpleMarkdownRenderer::render(
            isset($record['release_notes']) ? (string) $record['release_notes'] : null
        );
        $isManualUpload = ($record['patch_server_id'] ?? null) === null;

        return [
            'status' => 200,
            'data'   => array_merge($record, [
                'files'              => $files,
                'release_notes_html' => $releaseNotesHtml,
                'is_manual_upload'   => $isManualUpload,
            ]),
        ];
    }

    /**
     * Dismiss a patch notification for a specific version
     *
     * @param string $version    Version string to dismiss
     * @param string $csrfToken  CSRF token from the request
     * @return array Response array with success flag or error details
     */
    public function dismiss(string $version, string $csrfToken): array
    {
        if (!$this->auth->isSysadmin()) {
            return $this->forbidden();
        }

        if (!$this->csrf->validate($csrfToken)) {
            return $this->csrfError();
        }

        $this->module->dismissPatch($version, $this->auth->getCurrentUserId());

        return [
            'status' => 200,
            'data'   => ['success' => true, 'csrf_token' => $this->csrfToken()],
        ];
    }

    /**
     * Dismiss all available patch notifications at once
     *
     * @param string $csrfToken CSRF token from the request
     * @return array Response array with success flag or error details
     */
    public function dismissAll(string $csrfToken): array
    {
        if (!$this->auth->isSysadmin()) {
            return $this->forbidden();
        }

        if (!$this->csrf->validate($csrfToken)) {
            return $this->csrfError();
        }

        $this->module->dismissAllPatches($this->auth->getCurrentUserId());

        return [
            'status' => 200,
            'data'   => ['success' => true, 'csrf_token' => $this->csrfToken()],
        ];
    }

    /**
     * Verify the current user's password and issue an install authorization token
     *
     * The token is returned to the client so it can be submitted with the install request,
     * replacing direct session writes in the module layer.
     *
     * @param string $pw        Plaintext password to verify
     * @param string $csrfToken CSRF token from the request
     * @return array Response array with install_token on success, or 400/401/403 on failure
     */
    public function verifyPassword(string $pw, string $csrfToken): array
    {
        if (!$this->auth->isSysadmin()) {
            return $this->forbidden();
        }

        if (!$this->csrf->validate($csrfToken)) {
            return $this->csrfError();
        }

        if ($pw === '') {
            return [
                'status' => 400,
                'data'   => [
                    'success' => false,
                    'error'   => $this->t('TEXT_ERROR_PASSWORD_REQUIRED'),
                ],
            ];
        }

        if (!$this->auth->verifyPassword($pw)) {
            error_log(sprintf(
                '[PatchModule] verifyPassword failed for user %s',
                (string) $this->auth->getCurrentUserId()
            ));

            return [
                'status' => 401,
                'data'   => [
                    'success' => false,
                    'error'   => $this->t('TEXT_ERROR_PASSWORD_INCORRECT'),
                ],
            ];
        }

        $installToken = $this->auth->issueInstallAuthorization(1800);

        return [
            'status' => 200,
            'data'   => [
                'success'       => true,
                'install_token' => $installToken,
                'csrf_token'    => $this->csrfToken(),
            ],
        ];
    }

    /**
     * Install a patch identified by its history record ID
     *
     * Validates the one-time install authorization token, acquires a file lock to
     * prevent concurrent installs, and delegates to PatchModule::install(). After a
     * successful install it checks whether more patches are queued.
     *
     * @param int    $id            patch_history record ID
     * @param string $authToken     One-time install authorization token (from verifyPassword)
     * @param bool   $createBackup  Whether to create a DB/file backup before installing
     * @param string $progressToken 32-char hex token used for progress file naming
     * @param string $csrfToken     CSRF token from the request
     * @return array Response array with success flag and optional next-patch info, or error details
     */
    public function install(
        int $id,
        string $authToken,
        bool $createBackup,
        string $progressToken,
        string $csrfToken
    ): array {
        $error = $this->validateInstallRequest($id, $authToken, $progressToken, $csrfToken);
        if ($error !== null) {
            return $error;
        }

        $this->module->setProgressFile(
            $this->tempPath . '/patch_progress_' . $progressToken . '.json'
        );

        return $this->withInstallLock(function () use ($id, $createBackup): array {
            set_time_limit(0);
            ignore_user_abort(true);

            $record = $this->module->getHistoryRecord($id);
            if ($record === null) {
                return [
                    'status' => 404,
                    'data'   => [
                        'success' => false,
                        'error'   => $this->t('TEXT_ERROR_PATCH_RECORD_NOT_FOUND'),
                    ],
                ];
            }
            if ($record['patch_server_id'] === null) {
                $stagedPath = $this->tempPath . '/patch_uploaded_' . $id . '.tgz';
                if (!is_file($stagedPath)) {
                    return [
                        'status' => 500,
                        'data'   => [
                            'success'    => false,
                            'error_code' => ErrorCode::UPLOAD_FAILED,
                            'error'      => $this->t('TEXT_PATCH_ERROR_UPLOAD_FAILED'),
                        ],
                    ];
                }
                $result = $this->module->installFromUploadedArchive($id, $stagedPath, $this->auth->getCurrentUserId());
            } else {
                $result = $this->module->install($id, $createBackup, $this->auth->getCurrentUserId());
            }

            if (!$result['success']) {
                $errorCode    = $result['error_code'] ?? null;
                $humanMessage = $this->translateErrorCode($errorCode) ?? ($result['error'] ?? $this->t('TEXT_MESSAGE_PATCH_FAILED'));

                return [
                    'status' => 500,
                    'data'   => [
                        'success'    => false,
                        'error_code' => $errorCode,
                        'error'      => $humanMessage,
                    ],
                ];
            }

            $remaining = $this->module->getAvailablePatches();
            $hasNext   = count($remaining) > 0;
            $nextPatch = $hasNext ? ($remaining[0] ?? null) : null;

            return [
                'status' => 200,
                'data'   => [
                    'success'            => true,
                    'has_next'           => $hasNext,
                    'next_version'       => $hasNext && $nextPatch !== null ? ($nextPatch['version'] ?? null) : null,
                    'next_install_token' => $hasNext ? $this->auth->issueInstallAuthorization(1800) : null,
                    'csrf_token'         => $this->csrfToken(),
                ],
            ];
        });
    }

    /**
     * Poll the progress of an ongoing patch installation
     *
     * @param string $token 32-char hex progress token
     * @return array Response array with current progress data, or 400/404 on failure
     */
    public function progress(string $token): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            return [
                'status' => 400,
                'data'   => [
                    'success' => false,
                    'error'   => $this->t('TEXT_ERROR_INVALID_REQUEST'),
                ],
            ];
        }

        $progressData = $this->module->getInstallProgress($token);

        if ($progressData === null) {
            return [
                'status' => 404,
                'data'   => [
                    'success' => false,
                    'error'   => $this->t('TEXT_ERROR_PATCH_RECORD_NOT_FOUND'),
                ],
            ];
        }

        if ($this->isProgressTerminal($progressData)) {
            $this->module->deleteProgressFile($token);
        }

        return [
            'status' => 200,
            'data'   => $progressData,
        ];
    }

    /**
     * Roll back a patch by restoring its snapshot and database backup
     *
     * @param int    $id        patch_history record ID to roll back
     * @param string $csrfToken CSRF token from the request
     * @return array Response array with success flag or error details
     */
    public function rollback(int $id, string $csrfToken): array
    {
        if (!$this->auth->isSysadmin()) {
            return $this->forbidden();
        }

        if (!$this->csrf->validate($csrfToken)) {
            return $this->csrfError();
        }

        if ($id <= 0) {
            return [
                'status' => 400,
                'data'   => [
                    'success' => false,
                    'error'   => $this->t('TEXT_ERROR_INVALID_REQUEST'),
                ],
            ];
        }

        return $this->withInstallLock(function () use ($id): array {
            $result = $this->module->rollback($id, $this->auth->getCurrentUserId());

            if (!$result['success']) {
                return [
                    'status' => 500,
                    'data'   => [
                        'success' => false,
                        'error'   => $result['error'] ?? $this->t('TEXT_MESSAGE_PATCH_FAILED'),
                    ],
                ];
            }

            return [
                'status' => 200,
                'data'   => ['success' => true, 'csrf_token' => $this->csrfToken()],
            ];
        });
    }

    /**
     * Handle a manual patch upload request
     *
     * Accepts a multipart/form-data request containing a .tgz patch archive,
     * extracts the manifest, enforces version policy, and stores the staged archive
     * in {temp_path} with a patch_history record. The uploaded archive is processed
     * by the existing install pipeline when the client subsequently calls install()
     * with the returned ID. Trust gate: sysadmin authentication + CSRF.
     *
     * @param string $csrfToken CSRF token from the request
     * @param array  $files     $_FILES array containing 'patch_file'
     * @return array Response array with patch_history_id and optional version-gap warning, or error details
     */
    public function upload(string $csrfToken, array $files): array
    {
        if (!$this->auth->isSysadmin()) {
            return $this->forbidden();
        }

        if (!$this->csrf->validate($csrfToken)) {
            return $this->csrfError();
        }

        $validationError = $this->validateUploadFiles($files);
        if ($validationError !== null) {
            return $validationError;
        }

        $patchFile = $files['patch_file'];

        if ((int) ($patchFile['size'] ?? 0) > $this->maxUploadSize) {
            return $this->uploadError(400, ErrorCode::UPLOAD_TOO_LARGE, 'TEXT_PATCH_ERROR_UPLOAD_TOO_LARGE');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($patchFile['tmp_name']);
        if (!in_array($mime, ['application/gzip', 'application/x-gzip'], true)) {
            return $this->uploadError(400, ErrorCode::UPLOAD_INVALID_MIME, 'TEXT_PATCH_ERROR_UPLOAD_INVALID_MIME');
        }

        $stagedTgz = null;

        try {
            $token     = bin2hex(random_bytes(16));
            $stagedTgz = $this->tempPath . '/patch_upload_' . $token . '.tgz';

            if (!move_uploaded_file($patchFile['tmp_name'], $stagedTgz)) {
                $stagedTgz = null;
                return $this->uploadError(500, ErrorCode::UPLOAD_FAILED, 'TEXT_PATCH_ERROR_UPLOAD_FAILED');
            }
            chmod($stagedTgz, 0600);

            $sha256 = hash_file('sha256', $stagedTgz);

            [$uploadedVersion, $releaseNotes, $manifestJson, $releasedAt] = $this->extractManifestFromArchive($stagedTgz);
            if ($uploadedVersion === null) {
                return $this->uploadError(422, ErrorCode::UPLOAD_INVALID_MANIFEST, 'TEXT_PATCH_ERROR_UPLOAD_INVALID_MANIFEST');
            }

            $currentVersion = $this->module->getVersionResolver()->getCurrentVersion();
            $policyError    = $this->checkVersionPolicy($uploadedVersion, $currentVersion);
            if ($policyError !== null) {
                return $policyError;
            }

            $warning        = null;
            $warningMessage = null;
            foreach ($this->module->getAvailablePatches() as $remotePatch) {
                $rv = (string) ($remotePatch['version'] ?? '');
                if ($rv !== ''
                    && version_compare($rv, $currentVersion, '>')
                    && version_compare($rv, $uploadedVersion, '<')) {
                    $warning        = 'version_gap';
                    $warningMessage = $this->t('TEXT_PATCH_WARNING_VERSION_GAP', $rv);
                    break;
                }
            }

            $fileSize       = (int) filesize($stagedTgz);
            $patchHistoryId = null;

            $lockResult = $this->withUploadLock(function () use (
                $uploadedVersion,
                $releaseNotes,
                $manifestJson,
                $releasedAt,
                $sha256,
                $fileSize,
                &$stagedTgz,
                &$patchHistoryId
            ): ?array {
                $currentVersionInLock = $this->module->getVersionResolver()->getCurrentVersion();
                $policyError          = $this->checkVersionPolicy($uploadedVersion, $currentVersionInLock);
                if ($policyError !== null) {
                    return $policyError;
                }

                $db  = $this->module->getDatabase();
                $pdo = $db->getPdo();

                $pdo->beginTransaction();

                try {
                    // Upsert in place: find any existing available row for this version
                    // (server-fetched or previously uploaded) and UPDATE it so no duplicate
                    // rows are created. Manual upload always wins: patch_server_id is cleared.
                    $prior = $db->findHistoryByVersion($uploadedVersion, [PatchHistoryStatus::AVAILABLE]);

                    if ($prior !== null) {
                        $priorId    = (int) $prior['id'];
                        $oldTgzPath = $this->tempPath . '/patch_uploaded_' . $priorId . '.tgz';

                        $db->updateHistoryRecord($priorId, [
                            'release_notes'   => $releaseNotes,
                            'file_size'       => $fileSize,
                            'sha256_hash'     => $sha256,
                            'patch_server_id' => null,
                            'released_at'     => $releasedAt,
                            'manifest_json'   => $manifestJson,
                        ]);

                        $finalPath = $this->tempPath . '/patch_uploaded_' . $priorId . '.tgz';
                        if (!rename($stagedTgz, $finalPath)) {
                            $pdo->rollBack();
                            return $this->uploadError(500, ErrorCode::UPLOAD_FAILED, 'TEXT_PATCH_ERROR_UPLOAD_FAILED');
                        }
                        $stagedTgz = null;

                        $pdo->commit();
                        $patchHistoryId = $priorId;

                        // Remove the old tgz only when it was a different file (server row had none)
                        if ($oldTgzPath !== $finalPath && is_file($oldTgzPath)) {
                            @unlink($oldTgzPath);
                        }
                    } else {
                        $newId = $db->createHistoryRecord([
                            'version'         => $uploadedVersion,
                            'status'          => PatchHistoryStatus::AVAILABLE,
                            'release_notes'   => $releaseNotes,
                            'file_size'       => $fileSize,
                            'sha256_hash'     => $sha256,
                            'patch_server_id' => null,
                            'released_at'     => $releasedAt,
                        ]);

                        if ($manifestJson !== null) {
                            $db->updateHistoryRecord($newId, ['manifest_json' => $manifestJson]);
                        }

                        $finalPath = $this->tempPath . '/patch_uploaded_' . $newId . '.tgz';
                        if (!rename($stagedTgz, $finalPath)) {
                            $pdo->rollBack();
                            return $this->uploadError(500, ErrorCode::UPLOAD_FAILED, 'TEXT_PATCH_ERROR_UPLOAD_FAILED');
                        }
                        $stagedTgz = null;

                        $pdo->commit();
                        $patchHistoryId = $newId;
                    }

                    return null;
                } catch (\Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    error_log('[PatchModule] upload: transaction failed: ' . $e->getMessage());
                    return $this->serverError();
                }
            });

            if ($lockResult !== null) {
                return $lockResult;
            }

            return [
                'status' => 200,
                'data'   => [
                    'success'          => true,
                    'patch_history_id' => $patchHistoryId,
                    'version'          => $uploadedVersion,
                    'release_notes'    => $releaseNotes,
                    'file_size'        => $fileSize,
                    'released_at'      => $releasedAt,
                    'sha256'           => $sha256,
                    'warning'          => $warning,
                    'warning_message'  => $warningMessage,
                    'csrf_token'       => $this->csrfToken(),
                ],
            ];
        } finally {
            if ($stagedTgz !== null && is_file($stagedTgz)) {
                @unlink($stagedTgz);
            }
        }
    }

    /**
     * Get a translation callable pre-wired to the module's variadic-to-array bridge
     *
     * Returns a closure compatible with the variadic positional argument convention
     * used by all module views: $tr('TEXT_KEY', $param1, $param2, ...). Hosts
     * embedding module views (such as _banner.php) from their own layout templates
     * should use this method instead of building the bridge closure themselves:
     *
     *   $tr = $actions->getViewTranslator();
     *   include __DIR__ . '/../lib/PatchModule/views/admin/_banner.php';
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
     * Translate a TEXT_* key, optionally interpolating positional parameters
     *
     * Delegates to the injected TranslatorInterface when available; otherwise looks
     * up the key in the module's own en_US locale PHP-array fallback. Handles %s/%d
     * substitution via vsprintf when params are provided.
     *
     * @param string $key    Translation key (e.g. TEXT_PATCH_INSTALL_SUCCESS)
     * @param mixed  ...$params Positional values to substitute into %s/%d placeholders
     * @return string Translated and interpolated string, or the key if not found
     */
    private function t(string $key, mixed ...$params): string
    {
        if ($this->translator !== null) {
            return $this->translator->t($key, $params);
        }

        $locale = $this->loadFallbackLocale();
        $string = $locale[$key] ?? $key;

        if ($params !== [] && (str_contains($string, '%s') || str_contains($string, '%d'))) {
            return vsprintf($string, $params);
        }

        return $string;
    }

    /**
     * Lazily load and cache the module's built-in en_US locale array
     *
     * @return array<string,string> Map of TEXT_* keys to English strings
     */
    private function loadFallbackLocale(): array
    {
        if (self::$fallbackLocale === null) {
            $localePath = dirname(__DIR__) . '/locale/en_US/messages.php';
            if (file_exists($localePath)) {
                /** @var array<string,string> $loaded */
                $loaded = require $localePath;
                self::$fallbackLocale = is_array($loaded) ? $loaded : [];
            } else {
                self::$fallbackLocale = [];
            }
        }

        return self::$fallbackLocale;
    }

    /**
     * Build a classified file list from a patch manifest
     *
     * Classifies each file as 'modified', 'added', or 'deleted'. For patches
     * that have not yet been applied, filesystem presence is checked to
     * distinguish between modified and added files. Paths containing '..'
     * or null bytes are skipped to prevent path traversal.
     *
     * @param array|null $manifest Decoded manifest array, or null if unavailable
     * @param string     $status   Patch status (e.g. 'completed', 'rolled_back')
     * @return array<int,array{path:string,action:string}> Classified file list
     */
    private function buildFilesManifest(?array $manifest, string $status): array
    {
        if ($manifest === null) {
            return [];
        }

        $checkFilesystem = !in_array($status, [PatchHistoryStatus::COMPLETED, PatchHistoryStatus::ROLLED_BACK], true);
        $result          = [];

        foreach ($manifest['files'] ?? [] as $path) {
            $path = (string) $path;
            if ($this->isUnsafePath($path)) {
                continue;
            }

            $action = 'modified';
            if ($checkFilesystem && !file_exists($this->rootPath . '/' . ltrim($path, '/'))) {
                $action = 'added';
            }

            $result[] = ['path' => $path, 'action' => $action];
        }

        foreach ($manifest['removed_files'] ?? [] as $path) {
            $path = (string) $path;
            if ($this->isUnsafePath($path)) {
                continue;
            }

            $result[] = ['path' => $path, 'action' => 'deleted'];
        }

        return $result;
    }

    /**
     * Enrich available-patch objects with their local patch_history IDs
     *
     * The patch server returns patches identified by version. This method
     * looks up each version in the local history to add the DB record ID,
     * which is needed by the install and dismiss actions. When no row exists
     * yet (e.g. the cache was refreshed after a manual clear) the method
     * self-heals by creating an 'available' record so that the UI stays
     * functional without requiring a fresh update-check cycle.
     *
     * @param array $patches List of patch objects from the patch server
     * @return array Patch list with 'id' populated where a local record exists
     */
    private function enrichPatchesWithLocalIds(array $patches): array
    {
        foreach ($patches as &$patch) {
            $version = (string) ($patch['version'] ?? '');
            if ($version === '') {
                continue;
            }

            // Skip patches that already carry a valid local ID (e.g. manually uploaded)
            if (isset($patch['id']) && (int) $patch['id'] > 0) {
                continue;
            }

            $existing = $this->module->findHistoryByVersion($version);
            if ($existing !== null) {
                $patch['id'] = (int) $existing['id'];
                continue;
            }

            // Self-heal: the patch cache has this version but no available/downloading row
            // exists. Before creating one, check whether any terminal row (completed, failed,
            // rolled_back, obsolete) already exists — creating a duplicate available row on
            // top of a completed row would produce spurious entries in the history table.
            $terminalRow = $this->module->getDatabase()->findHistoryByVersion(
                $version,
                ['completed', 'failed', 'rolled_back', 'obsolete']
            );
            if ($terminalRow !== null) {
                // Version already processed; skip this patch entry entirely.
                continue;
            }

            try {
                $this->module->getDatabase()->createHistoryRecord([
                    'version'         => $version,
                    'status'          => PatchHistoryStatus::AVAILABLE,
                    'release_notes'   => $patch['release_notes'] ?? null,
                    'file_size'       => isset($patch['file_size']) ? (int) $patch['file_size'] : null,
                    'sha256_hash'     => $patch['sha256'] ?? null,
                    'patch_server_id' => $patch['patch_id'] ?? null,
                    'released_at'     => $patch['released_at'] ?? null,
                ]);

                $created = $this->module->findHistoryByVersion($version);
                if ($created !== null) {
                    $patch['id'] = (int) $created['id'];
                }
            } catch (\Throwable $e) {
                error_log(sprintf(
                    '[PatchModule] enrichPatchesWithLocalIds: failed to self-heal patch_history for v%s — %s',
                    $version,
                    $e->getMessage()
                ));
            }
        }
        unset($patch);

        return $patches;
    }

    /**
     * Translate an error_code string to a human-readable message
     *
     * Maps the stable error_code constants from ErrorCode to their corresponding
     * TEXT_PATCH_ERROR_* translation keys. Returns null if the code is unknown.
     *
     * @param string|null $errorCode Stable error code from a PatchModule result array
     * @return string|null Human-readable error message, or null if the code is not mapped
     */
    private function translateErrorCode(?string $errorCode): ?string
    {
        $map = [
            ErrorCode::INSTALL_IN_PROGRESS                => 'TEXT_PATCH_ERROR_INSTALL_IN_PROGRESS',
            ErrorCode::INVALID_ARCHIVE                    => 'TEXT_PATCH_ERROR_INVALID_ARCHIVE',
            ErrorCode::INVALID_LICENSE                    => 'TEXT_PATCH_ERROR_INVALID_LICENSE',
            ErrorCode::INVALID_MANIFEST_PATH              => 'TEXT_PATCH_ERROR_INVALID_MANIFEST_PATH',
            ErrorCode::INVALID_MANIFEST_SCHEMA            => 'TEXT_PATCH_ERROR_INVALID_MANIFEST_SCHEMA',
            ErrorCode::LICENSE_EXPIRED                    => 'TEXT_PATCH_ERROR_LICENSE_EXPIRED',
            ErrorCode::LICENSE_IP_MISMATCH                => 'TEXT_PATCH_ERROR_LICENSE_IP_MISMATCH',
            ErrorCode::LICENSE_REVOKED                    => 'TEXT_PATCH_ERROR_LICENSE_REVOKED',
            ErrorCode::NETWORK_ERROR                      => 'TEXT_PATCH_ERROR_NETWORK_ERROR',
            ErrorCode::NOT_RECENTLY_VERIFIED              => 'TEXT_PATCH_ERROR_NOT_RECENTLY_VERIFIED',
            ErrorCode::PACKAGE_MISMATCH                   => 'TEXT_PATCH_ERROR_PACKAGE_MISMATCH',
            ErrorCode::RATE_LIMITED                       => 'TEXT_PATCH_ERROR_RATE_LIMITED',
            ErrorCode::SERVER_ERROR                       => 'TEXT_PATCH_ERROR_SERVER_ERROR',
            ErrorCode::SIGNING_UNAVAILABLE                => 'TEXT_PATCH_ERROR_SIGNING_UNAVAILABLE',
            ErrorCode::UPLOAD_FAILED                      => 'TEXT_PATCH_ERROR_UPLOAD_FAILED',
            ErrorCode::UPLOAD_INVALID_ARCHIVE             => 'TEXT_PATCH_ERROR_UPLOAD_INVALID_ARCHIVE',
            ErrorCode::UPLOAD_INVALID_MANIFEST            => 'TEXT_PATCH_ERROR_UPLOAD_INVALID_MANIFEST',
            ErrorCode::UPLOAD_INVALID_MIME                => 'TEXT_PATCH_ERROR_UPLOAD_INVALID_MIME',
            ErrorCode::UPLOAD_TOO_LARGE                   => 'TEXT_PATCH_ERROR_UPLOAD_TOO_LARGE',
            ErrorCode::UPLOAD_VERSION_ALREADY_INSTALLED   => 'TEXT_PATCH_ERROR_UPLOAD_VERSION_ALREADY_INSTALLED',
            ErrorCode::UPLOAD_VERSION_DOWNGRADE           => 'TEXT_PATCH_ERROR_UPLOAD_VERSION_DOWNGRADE',
            ErrorCode::VERIFICATION_FAILED                => 'TEXT_PATCH_ERROR_VERIFICATION_FAILED',
        ];

        if ($errorCode === null || !isset($map[$errorCode])) {
            return null;
        }

        return $this->t($map[$errorCode]);
    }

    /**
     * Determine whether all progress steps have reached a terminal state
     *
     * A progress report is considered terminal when every step is either
     * 'completed' or 'failed' (i.e. the install finished or aborted).
     *
     * @param array $progressData Progress data array returned by getInstallProgress()
     * @return bool True if no steps are still pending or in progress
     */
    private function isProgressTerminal(array $progressData): bool
    {
        $steps = $progressData['steps'] ?? [];
        if ($steps === []) {
            return false;
        }

        foreach ($steps as $step) {
            $state = $step['state'] ?? '';
            if (!in_array($state, ['completed', 'failed'], true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Mark available patch rows obsolete when their version is at or below the current version
     *
     * Covers the case where the application version was bumped by a direct file copy on
     * the server, bypassing PatchModule. On every admin index render the locally-known
     * current version is compared against all available patch rows; any row whose version
     * would be a downgrade or re-install is flipped to 'obsolete'.
     *
     * @param string $currentVersion Currently installed application version
     * @return void
     */
    private function markStaleAvailableRowsObsolete(string $currentVersion): void
    {
        $db  = $this->module->getDatabase();
        $pdo = $db->getPdo();

        $stmt = $pdo->query(
            "SELECT id, version FROM patch_history WHERE status = 'available'"
        );
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            $v = (string) ($row['version'] ?? '');
            if ($v !== '' && version_compare($v, $currentVersion, '<=')) {
                $db->updateHistoryRecord((int) $row['id'], ['status' => PatchHistoryStatus::OBSOLETE]);
            }
        }
    }

    /**
     * Return the patch_history ID of the oldest (lowest version) available patch
     *
     * Patches are not cumulative — only the oldest not-yet-installed patch should show
     * the Install button. The $patches array has already been merged and sorted
     * oldest-first by AdminActions::index().
     *
     * @param array $patches Merged available patches list
     * @return int|null ID of the installable patch, or null when the list is empty
     */
    private function selectInstallableId(array $patches): ?int
    {
        foreach ($patches as $patch) {
            $id = (int) ($patch['id'] ?? 0);
            if ($id > 0) {
                return $id;
            }
        }
        return null;
    }

    /**
     * Build a standard 403 Forbidden response array
     *
     * @return array Response array with status 403 and a translated error message
     */
    private function forbidden(): array
    {
        return [
            'status' => 403,
            'data'   => [
                'success' => false,
                'error'   => $this->t('TEXT_ERROR_SYSADMIN_ONLY'),
            ],
        ];
    }

    /**
     * Build a standard 403 response for CSRF validation failures
     *
     * @return array Response array with status 403 and a translated error message
     */
    private function csrfError(): array
    {
        return [
            'status' => 403,
            'data'   => [
                'success'    => false,
                'error_code' => 'csrf_invalid',
                'error'      => $this->t('TEXT_ERROR_INVALID_REQUEST'),
            ],
        ];
    }

    /**
     * Build a standard 500 server error response array
     *
     * @return array Response array with status 500 and server_error code
     */
    private function serverError(): array
    {
        return [
            'status' => 500,
            'data'   => [
                'success'    => false,
                'error_code' => ErrorCode::SERVER_ERROR,
                'error'      => $this->t('TEXT_PATCH_ERROR_SERVER_ERROR'),
            ],
        ];
    }

    /**
     * Get the current CSRF token, rotating it when the adapter supports rotation
     *
     * When the injected adapter implements CsrfRotatableInterface, calls rotate() to
     * invalidate the old token, generate a fresh one, and return it. Otherwise returns
     * the session-stable token via getToken(). Call this exactly once per successful
     * mutating response — never on error responses or read-only endpoints.
     *
     * @return string The active (potentially new) CSRF token
     */
    private function csrfToken(): string
    {
        return $this->csrf instanceof CsrfRotatableInterface
            ? $this->csrf->rotate()
            : $this->csrf->getToken();
    }

    /**
     * Build a standard 409 response for concurrent install/rollback attempts
     *
     * @return array Response array with status 409 and install_in_progress code
     */
    private function lockConflict(): array
    {
        return [
            'status' => 409,
            'data'   => [
                'success'    => false,
                'error_code' => ErrorCode::INSTALL_IN_PROGRESS,
                'error'      => $this->t('TEXT_PATCH_ERROR_INSTALL_IN_PROGRESS'),
            ],
        ];
    }

    /**
     * Acquire the exclusive install lock, execute $fn, then release the lock
     *
     * Returns a 500 response if the lock file cannot be opened, or a 409 response
     * if another install/rollback already holds the lock. The lock is always released
     * in a finally block so it cannot be held by a crashed process.
     *
     * @param callable $fn Code to run while the lock is held; must return a response array
     * @return array HTTP response array
     */
    private function withInstallLock(callable $fn): array
    {
        $lockFile = $this->tempPath . '/patch_install.lock';
        $lockFh   = fopen($lockFile, 'c');

        if ($lockFh === false) {
            return $this->serverError();
        }

        if (!flock($lockFh, LOCK_EX | LOCK_NB)) {
            fclose($lockFh);
            return $this->lockConflict();
        }

        try {
            return $fn();
        } finally {
            flock($lockFh, LOCK_UN);
            fclose($lockFh);
        }
    }

    /**
     * Validate all inputs for an install request before any side effects occur
     *
     * Checks sysadmin permission, CSRF token, install authorization token (consuming
     * it atomically), patch ID bounds, and progress token format. Returns null when
     * all checks pass; returns an error response array on the first failure.
     *
     * @param int    $id            patch_history record ID
     * @param string $authToken     One-time install authorization token
     * @param string $progressToken 32-char hex progress token
     * @param string $csrfToken     CSRF token from the request
     * @return array|null Error response array, or null if all checks pass
     */
    private function validateInstallRequest(
        int $id,
        string $authToken,
        string $progressToken,
        string $csrfToken
    ): ?array {
        if (!$this->auth->isSysadmin()) {
            return $this->forbidden();
        }

        if (!$this->csrf->validate($csrfToken)) {
            return $this->csrfError();
        }

        if (!$this->auth->consumeInstallAuthorization($authToken)) {
            return [
                'status' => 403,
                'data'   => [
                    'success'    => false,
                    'error_code' => 'not_recently_verified',
                    'error'      => $this->t('TEXT_ERROR_PATCH_AUTH_EXPIRED'),
                ],
            ];
        }

        if ($id <= 0) {
            return [
                'status' => 400,
                'data'   => [
                    'success' => false,
                    'error'   => $this->t('TEXT_ERROR_INVALID_REQUEST'),
                ],
            ];
        }

        if (!preg_match('/^[a-f0-9]{32}$/', $progressToken)) {
            return [
                'status' => 400,
                'data'   => [
                    'success' => false,
                    'error'   => $this->t('TEXT_ERROR_INVALID_REQUEST'),
                ],
            ];
        }

        return null;
    }

    /**
     * Merge remote-fetched patches with manually uploaded patches
     *
     * Produces a by-version map where uploaded patches replace remote patches on
     * version collision. Uploaded entries are flagged with 'is_uploaded => true'
     * so the view can render a "Manual upload" badge.
     *
     * @param array $remote   Patches from the patch server (getAvailablePatches())
     * @param array $uploaded Rows from patch_history where patch_server_id IS NULL AND status='available'
     * @return array Merged list, one entry per version, ordered: remote first then uploaded-only
     */
    private function mergePatches(array $remote, array $uploaded): array
    {
        $byVersion = [];

        foreach ($remote as $patch) {
            $version = (string) ($patch['version'] ?? '');
            if ($version !== '') {
                $byVersion[$version] = $patch;
            }
        }

        foreach ($uploaded as $patch) {
            $version = (string) ($patch['version'] ?? '');
            if ($version !== '') {
                $patch['is_uploaded'] = true;
                $byVersion[$version]  = $patch;
            }
        }

        $merged = array_values($byVersion);
        usort($merged, fn($a, $b) => version_compare($a['version'], $b['version']));

        return $merged;
    }

    /**
     * Validate the upload file entries from $_FILES
     *
     * Checks that the patch_file slot is present and error-free, mapping
     * PHP's UPLOAD_ERR_INI_SIZE/UPLOAD_ERR_FORM_SIZE to UPLOAD_TOO_LARGE.
     *
     * @param array $files $_FILES array
     * @return array|null Error response array, or null if the file looks valid
     */
    private function validateUploadFiles(array $files): ?array
    {
        $patchErr = $files['patch_file']['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($patchErr === UPLOAD_ERR_INI_SIZE || $patchErr === UPLOAD_ERR_FORM_SIZE) {
            return $this->uploadError(400, ErrorCode::UPLOAD_TOO_LARGE, 'TEXT_PATCH_ERROR_UPLOAD_TOO_LARGE');
        }
        if ($patchErr !== UPLOAD_ERR_OK || empty($files['patch_file']['tmp_name'])) {
            return $this->uploadError(400, ErrorCode::UPLOAD_INVALID_ARCHIVE, 'TEXT_PATCH_ERROR_UPLOAD_INVALID_ARCHIVE');
        }

        return null;
    }

    /**
     * Build a standardized upload error response
     *
     * @param int    $status    HTTP status code
     * @param string $errorCode Stable error code from ErrorCode
     * @param string $textKey   Translation key for the human-readable message
     * @return array Response array
     */
    private function uploadError(int $status, string $errorCode, string $textKey): array
    {
        return [
            'status' => $status,
            'data'   => [
                'success'    => false,
                'error_code' => $errorCode,
                'error'      => $this->t($textKey),
            ],
        ];
    }

    /**
     * Enforce version policy for a manual upload
     *
     * Rejects downgrades, re-installs of the current version, and re-installs
     * of already-completed versions. Returns null when the version is acceptable.
     *
     * @param string $uploadedVersion Version string extracted from the uploaded manifest
     * @param string $currentVersion  Currently installed application version
     * @return array|null Error response array, or null if the version passes policy
     */
    private function checkVersionPolicy(string $uploadedVersion, string $currentVersion): ?array
    {
        if (version_compare($uploadedVersion, $currentVersion, '<')) {
            return $this->uploadError(409, ErrorCode::UPLOAD_VERSION_DOWNGRADE, 'TEXT_PATCH_ERROR_UPLOAD_VERSION_DOWNGRADE');
        }
        if (version_compare($uploadedVersion, $currentVersion, '==')) {
            return $this->uploadError(409, ErrorCode::UPLOAD_VERSION_ALREADY_INSTALLED, 'TEXT_PATCH_ERROR_UPLOAD_VERSION_ALREADY_INSTALLED');
        }
        if ($this->module->getDatabase()->findHistoryByVersion($uploadedVersion, [PatchHistoryStatus::COMPLETED]) !== null) {
            return $this->uploadError(409, ErrorCode::UPLOAD_VERSION_ALREADY_INSTALLED, 'TEXT_PATCH_ERROR_UPLOAD_VERSION_ALREADY_INSTALLED');
        }
        return null;
    }

    /**
     * Acquire the exclusive upload lock, execute $fn, then release the lock
     *
     * Uses a blocking flock to serialize concurrent uploads. Returns null on success
     * (meaning $fn ran and returned null), or an error response array if the lock
     * file cannot be opened or $fn itself returns an error.
     *
     * @param callable $fn Code to run while the lock is held; must return null on success or an error response array
     * @return array|null Null on success, or an error response array
     */
    private function withUploadLock(callable $fn): ?array
    {
        $lockFile = $this->tempPath . '/.patch_upload.lock';
        $lockFh   = fopen($lockFile, 'c');

        if ($lockFh === false) {
            return $this->serverError();
        }

        if (!flock($lockFh, LOCK_EX)) {
            fclose($lockFh);
            return $this->serverError();
        }

        try {
            return $fn();
        } finally {
            flock($lockFh, LOCK_UN);
            fclose($lockFh);
        }
    }

    /**
     * Extract version, release notes, released_at, and JSON-encoded manifest from a patch archive
     *
     * Delegates to PatchFileManager::extractPatch() which validates the archive,
     * symlink-checks, and parses manifest.json. The extract directory is cleaned up
     * before returning. When the manifest does not include a released_at field the
     * current timestamp is used so the admin UI always has a date to display.
     *
     * @param string $archivePath Absolute path to the staged .tgz archive
     * @return array{0: string|null, 1: string|null, 2: string|null, 3: string|null} [version, release_notes, manifest_json, released_at]
     */
    private function extractManifestFromArchive(string $archivePath): array
    {
        try {
            $result = $this->module->getFileManager()->extractPatch($archivePath);
        } catch (\Throwable $e) {
            error_log('[PatchModule] upload: extractPatch failed: ' . $e->getMessage());
            return [null, null, null, null];
        }

        if (!($result['success'] ?? false) || !is_array($result['manifest'] ?? null)) {
            return [null, null, null, null];
        }

        $manifest   = $result['manifest'];
        $extractDir = $result['extract_dir'] ?? null;

        if ($extractDir !== null && is_dir($extractDir)) {
            $this->module->getFileManager()->cleanupDir($extractDir);
        }

        $version      = isset($manifest['version']) ? (string) $manifest['version'] : null;
        $releaseNotes = (isset($result['release_notes_md']) && $result['release_notes_md'] !== '')
            ? (string) $result['release_notes_md']
            : null;
        $manifestJson = json_encode($manifest) ?: null;
        $releasedAt   = isset($manifest['released_at']) ? (string) $manifest['released_at'] : date('Y-m-d H:i:s');

        if ($version === null || $version === '') {
            return [null, null, null, null];
        }

        return [$version, $releaseNotes, $manifestJson, $releasedAt];
    }

    /**
     * Check whether a file path is unsafe for filesystem operations
     *
     * Rejects empty strings, paths containing directory traversal sequences,
     * and paths with null bytes.
     *
     * @param string $path File path to validate
     * @return bool True if the path should be rejected
     */
    private function isUnsafePath(string $path): bool
    {
        return $path === ''
            || str_contains($path, '..')
            || str_contains($path, "\0");
    }
}
