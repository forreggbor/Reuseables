<?php

declare(strict_types=1);

namespace PatchModule\Contracts;

/**
 * Resolves and updates the host application version
 *
 * Different applications store their version differently (constants, config
 * files, database, etc.). This interface abstracts version management so
 * PatchModule can work with any version storage mechanism.
 *
 * @package PatchModule
 */
interface VersionResolverInterface
{
    /**
     * Get the current application version
     *
     * @return string Semver version string (e.g., '2.31.2')
     */
    public function getCurrentVersion(): string;

    /**
     * Update the application version after a successful patch
     *
     * @param string $newVersion New semver version string
     * @return bool True on success
     */
    public function updateVersion(string $newVersion): bool;
}