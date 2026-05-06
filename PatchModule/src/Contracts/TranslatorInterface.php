<?php
/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * Optional interface for host-side translation of TEXT_* keys.
 */

declare(strict_types=1);

namespace PatchModule\Contracts;

/**
 * Optional translator adapter for the patch module
 *
 * When provided to PatchModule, the host application's translation system
 * is used instead of the module's built-in PHP-array locale fallback.
 * Keys follow the TEXT_* convention used across the project.
 *
 * @package PatchModule
 */
interface TranslatorInterface
{
    /**
     * Translate a TEXT_* key, optionally interpolating positional parameters
     *
     * Occurrences of %s and %d placeholders in the translation string are
     * replaced with values from $params in order. If no translation is found
     * for the given key, the key itself is returned unchanged.
     *
     * @param string $key    Translation key (e.g. TEXT_PATCH_INSTALL_SUCCESS)
     * @param array  $params Ordered list of values to substitute into placeholders
     * @return string Translated and interpolated string, or the key if not found
     */
    public function t(string $key, array $params = []): string;
}
