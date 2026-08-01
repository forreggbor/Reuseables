<?php

declare(strict_types=1);

/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * Internal translation helper shared by BackupEngine and RestoreEngine.
 * Delegates to a host-supplied TranslatorInterface when configured;
 * otherwise falls back to the module's own bundled locale/{lang}/messages.php
 * arrays (returning the raw key when no translation is found, matching the
 * TranslatorInterface contract).
 */

namespace BackupRestore;

use BackupRestore\Contracts\TranslatorInterface;

/**
 * @package BackupRestore
 */
final class Translator
{
    /** @var array<string, array<string,string>> Cached locale arrays keyed by language */
    private array $cache = [];

    /**
     * @param TranslatorInterface|null $host Host translator; null = use bundled locale/ files
     * @param string $language Language code (directory name under locale/), default 'en_US'
     */
    public function __construct(
        private readonly ?TranslatorInterface $host,
        private readonly string $language = 'en_US',
    ) {
    }

    /**
     * Translate a TEXT_* key, interpolating named `{name}` placeholders from
     * an associative $params array (NOT positional %s/%d/vsprintf) — see
     * TranslatorInterface for the rationale.
     *
     * @param string $key
     * @param array<string,string> $params
     * @return string
     */
    public function translate(string $key, array $params = []): string
    {
        if ($this->host !== null) {
            return $this->host->translate($key, $params);
        }

        $value = $this->messages($this->language)[$key]
            ?? $this->messages('en_US')[$key]
            ?? $key;

        foreach ($params as $placeholder => $paramValue) {
            $value = str_replace('{' . $placeholder . '}', (string) $paramValue, $value);
        }

        return $value;
    }

    /**
     * @param string $language
     * @return array<string,string>
     */
    private function messages(string $language): array
    {
        if (isset($this->cache[$language])) {
            return $this->cache[$language];
        }

        $file = dirname(__DIR__) . '/locale/' . $language . '/messages.php';
        $messages = is_file($file) ? (require $file) : [];

        return $this->cache[$language] = is_array($messages) ? $messages : [];
    }
}
