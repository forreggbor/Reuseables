<?php

declare(strict_types=1);

namespace LicenseModule\Adapters\Database;

use LicenseModule\Contracts\DatabaseAdapterInterface;
use LicenseModule\Exceptions\DatabaseUnavailableException;
use PDO;

/**
 * Callable database adapter for license module
 *
 * Wraps a PDO-returning callable for lazy connection.
 * This allows the host application to provide its existing PDO connection.
 *
 * @throws DatabaseUnavailableException When the PDO factory returns null
 */
class CallableAdapter implements DatabaseAdapterInterface
{
    /** @var callable():?PDO */
    private $pdoFactory;

    private ?PdoAdapter $adapter = null;

    /**
     * @param callable():?PDO $pdoFactory Callable that returns a PDO instance or null
     */
    public function __construct(callable $pdoFactory)
    {
        $this->pdoFactory = $pdoFactory;
    }

    /**
     * Get the underlying PDO adapter (lazy initialization)
     *
     * @throws DatabaseUnavailableException When PDO factory returns null
     */
    private function getAdapter(): PdoAdapter
    {
        if ($this->adapter === null) {
            $pdo = ($this->pdoFactory)();

            if ($pdo === null) {
                throw new DatabaseUnavailableException(
                    'PDO factory returned null - database connection is not available'
                );
            }

            $this->adapter = new PdoAdapter($pdo);
        }

        return $this->adapter;
    }

    /**
     * {@inheritDoc}
     */
    public function getLicenseInfo(): ?array
    {
        return $this->getAdapter()->getLicenseInfo();
    }

    /**
     * {@inheritDoc}
     */
    public function saveLicenseInfo(array $data): bool
    {
        return $this->getAdapter()->saveLicenseInfo($data);
    }

    /**
     * {@inheritDoc}
     */
    public function logValidation(int $licenseId, string $status, array $responseData = [], string $errorMessage = ''): bool
    {
        return $this->getAdapter()->logValidation($licenseId, $status, $responseData, $errorMessage);
    }
}
