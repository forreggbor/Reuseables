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
     * @param PatchModule              $module     The PatchModule facade instance
     * @param AuthAdapterInterface     $auth       Host-side authentication adapter
     * @param CsrfAdapterInterface     $csrf       Host-side CSRF adapter
     * @param string                   $tempPath   Writable temp directory for lock and progress files
     * @param string                   $rootPath   Project root directory for filesystem checks
     * @param TranslatorInterface|null $translator Optional host-side translator; falls back to built-in en_US if null
     */
    public function __construct(
        private readonly PatchModule $module,
        private readonly AuthAdapterInterface $auth,
        private readonly CsrfAdapterInterface $csrf,
        private readonly string $tempPath,
        private readonly string $rootPath,
        private readonly ?TranslatorInterface $translator = null,
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
     * @return array Response array with 'view', 'data', or 403 on permission failure
     */
    public function index(): array
    {
        if (!$this->auth->isSysadmin()) {
            return $this->forbidden();
        }

        $availability    = $this->module->isAvailable();
        $currentVersion  = $this->module->getVersionResolver()->getCurrentVersion();
        $patches         = $this->enrichPatchesWithLocalIds($this->module->getAvailablePatches());
        $history         = $this->module->getHistory();

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

        return [
            'status' => 200,
            'data'   => array_merge($record, ['files' => $files]),
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

            $result = $this->module->install($id, $createBackup, $this->auth->getCurrentUserId());

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
     * which is needed by the install and dismiss actions.
     *
     * @param array $patches List of patch objects from the patch server
     * @return array Patch list with 'id' populated where a local record exists
     */
    private function enrichPatchesWithLocalIds(array $patches): array
    {
        foreach ($patches as &$patch) {
            $version = (string) ($patch['version'] ?? '');
            if ($version !== '') {
                $existing = $this->module->findHistoryByVersion($version);
                if ($existing !== null) {
                    $patch['id'] = (int) $existing['id'];
                }
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
            ErrorCode::INSTALL_IN_PROGRESS     => 'TEXT_PATCH_ERROR_INSTALL_IN_PROGRESS',
            ErrorCode::INVALID_ARCHIVE         => 'TEXT_PATCH_ERROR_INVALID_ARCHIVE',
            ErrorCode::INVALID_LICENSE         => 'TEXT_PATCH_ERROR_INVALID_LICENSE',
            ErrorCode::INVALID_MANIFEST_PATH   => 'TEXT_PATCH_ERROR_INVALID_MANIFEST_PATH',
            ErrorCode::INVALID_MANIFEST_SCHEMA => 'TEXT_PATCH_ERROR_INVALID_MANIFEST_SCHEMA',
            ErrorCode::LICENSE_EXPIRED         => 'TEXT_PATCH_ERROR_LICENSE_EXPIRED',
            ErrorCode::LICENSE_IP_MISMATCH     => 'TEXT_PATCH_ERROR_LICENSE_IP_MISMATCH',
            ErrorCode::LICENSE_REVOKED         => 'TEXT_PATCH_ERROR_LICENSE_REVOKED',
            ErrorCode::NETWORK_ERROR           => 'TEXT_PATCH_ERROR_NETWORK_ERROR',
            ErrorCode::NOT_RECENTLY_VERIFIED   => 'TEXT_PATCH_ERROR_NOT_RECENTLY_VERIFIED',
            ErrorCode::PACKAGE_MISMATCH        => 'TEXT_PATCH_ERROR_PACKAGE_MISMATCH',
            ErrorCode::RATE_LIMITED            => 'TEXT_PATCH_ERROR_RATE_LIMITED',
            ErrorCode::SERVER_ERROR            => 'TEXT_PATCH_ERROR_SERVER_ERROR',
            ErrorCode::SIGNING_UNAVAILABLE     => 'TEXT_PATCH_ERROR_SIGNING_UNAVAILABLE',
            ErrorCode::VERIFICATION_FAILED     => 'TEXT_PATCH_ERROR_VERIFICATION_FAILED',
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
