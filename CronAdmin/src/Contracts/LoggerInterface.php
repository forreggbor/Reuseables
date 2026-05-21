<?php
/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * PSR-3-lite logger contract for the CronAdmin module.
 */

declare(strict_types=1);

namespace CronAdmin\Contracts;

/**
 * Minimal logging interface for CronAdmin diagnostic output.
 *
 * Hosts provide a concrete implementation that bridges to their app_log(),
 * a PSR-3 logger, or any other logging sink. If no logger is supplied in the
 * config, the module uses a no-op implementation that discards all messages.
 *
 * Level constants match PSR-3 naming for compatibility.
 */
interface LoggerInterface
{
    public const DEBUG   = 'debug';
    public const INFO    = 'info';
    public const WARNING = 'warning';
    public const ERROR   = 'error';

    /**
     * Logs a message at the given level.
     *
     * @param string $message  Human-readable log message.
     * @param string $level    One of the level constants: debug, info, warning, error.
     * @param array  $context  Optional key-value pairs for structured logging.
     * @return void
     */
    public function log(string $message, string $level = self::INFO, array $context = []): void;

    /**
     * Logs a debug-level message.
     *
     * @param string $message
     * @param array  $context
     * @return void
     */
    public function debug(string $message, array $context = []): void;

    /**
     * Logs an info-level message.
     *
     * @param string $message
     * @param array  $context
     * @return void
     */
    public function info(string $message, array $context = []): void;

    /**
     * Logs a warning-level message.
     *
     * @param string $message
     * @param array  $context
     * @return void
     */
    public function warning(string $message, array $context = []): void;

    /**
     * Logs an error-level message.
     *
     * @param string $message
     * @param array  $context
     * @return void
     */
    public function error(string $message, array $context = []): void;
}
