<?php

declare(strict_types=1);

namespace LicenseModule\Exceptions;

/**
 * Exception thrown when the database connection is unavailable
 *
 * This exception is thrown when the PDO factory callable returns null,
 * typically during application installation when database tables don't exist yet.
 * Host applications should catch this exception and handle it appropriately
 * (e.g., redirect to installation wizard).
 *
 * @example
 * try {
 *     $license = new LicenseModule([
 *         'get_pdo' => fn() => $db->getConnection(),
 *     ]);
 * } catch (DatabaseUnavailableException $e) {
 *     // Database not ready - show installation screen
 *     return $this->showInstallationPage();
 * }
 */
class DatabaseUnavailableException extends \RuntimeException
{
    /**
     * @param string $message Exception message
     * @param int $code Exception code
     * @param \Throwable|null $previous Previous exception for chaining
     */
    public function __construct(
        string $message = 'Database connection is not available',
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }
}
