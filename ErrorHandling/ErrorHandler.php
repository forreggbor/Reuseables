<?php

declare(strict_types=1);

namespace ErrorHandling;

/**
 * ErrorHandler - Universal error and exception logging for PHP applications
 *
 * Framework-agnostic error handling with configurable severity levels,
 * file logging, and optional PHP error/exception handler registration.
 *
 * @package ErrorHandling
 * @version 1.0.0
 * @license MIT
 */
class ErrorHandler
{
    /**
     * Log level priorities (lower = higher priority)
     */
    private const LEVEL_PRIORITIES = [
        'ERROR' => 1,
        'WARNING' => 2,
        'INFO' => 3,
        'DEBUG' => 4,
    ];

    /**
     * PHP error type to log level mapping
     */
    private const ERROR_TYPE_MAP = [
        E_ERROR => 'ERROR',
        E_WARNING => 'WARNING',
        E_PARSE => 'ERROR',
        E_NOTICE => 'INFO',
        E_CORE_ERROR => 'ERROR',
        E_CORE_WARNING => 'WARNING',
        E_COMPILE_ERROR => 'ERROR',
        E_COMPILE_WARNING => 'WARNING',
        E_USER_ERROR => 'ERROR',
        E_USER_WARNING => 'WARNING',
        E_USER_NOTICE => 'INFO',
        E_STRICT => 'DEBUG',
        E_RECOVERABLE_ERROR => 'ERROR',
        E_DEPRECATED => 'DEBUG',
        E_USER_DEPRECATED => 'DEBUG',
    ];

    /**
     * Static configuration for global usage
     */
    private static array $config = [
        'log_path' => '/storage/logs',
        'log_file' => 'error.log',
        'log_level' => 'ERROR',
        'date_format' => 'Y-m-d H:i:s',
        'include_trace' => false,
        'permissions' => 0755,
    ];

    /**
     * Whether static mode has been initialized
     */
    private static bool $initialized = false;

    /**
     * Previous error handler (for chaining)
     */
    private static mixed $previousErrorHandler = null;

    /**
     * Previous exception handler (for chaining)
     */
    private static mixed $previousExceptionHandler = null;

    /**
     * Instance configuration (for multi-logger setups)
     */
    private array $instanceConfig;

    /**
     * Create a new ErrorHandler instance
     *
     * @param array $config Configuration options
     */
    public function __construct(array $config = [])
    {
        $this->instanceConfig = array_merge(self::$config, $config);
    }

    /**
     * Initialize the static error handler with configuration
     *
     * @param array $config Configuration options:
     *   - log_path: Directory for log files (default: /storage/logs)
     *   - log_file: Log filename (default: error.log)
     *   - log_level: Minimum level to log: ERROR, WARNING, INFO, DEBUG (default: ERROR)
     *   - date_format: Timestamp format (default: Y-m-d H:i:s)
     *   - include_trace: Include stack trace for errors (default: false)
     *   - permissions: Directory permissions on creation (default: 0755)
     * @return void
     */
    public static function init(array $config = []): void
    {
        self::$config = array_merge(self::$config, $config);
        self::$initialized = true;
    }

    /**
     * Get current configuration
     *
     * @return array Current configuration
     */
    public static function getConfig(): array
    {
        return self::$config;
    }

    /**
     * Log a message with the specified level
     *
     * @param string $message The message to log
     * @param string $level Log level (ERROR, WARNING, INFO, DEBUG)
     * @param array $context Optional context data
     * @return bool True if message was logged, false if filtered out
     */
    public static function log(string $message, string $level = 'ERROR', array $context = []): bool
    {
        return self::writeLog(self::$config, $message, $level, $context);
    }

    /**
     * Log an instance message
     *
     * @param string $message The message to log
     * @param string $level Log level
     * @param array $context Optional context data
     * @return bool True if message was logged
     */
    public function write(string $message, string $level = 'ERROR', array $context = []): bool
    {
        return self::writeLog($this->instanceConfig, $message, $level, $context);
    }

    /**
     * Log an error message
     *
     * @param string $message The message to log
     * @param array $context Optional context data
     * @return bool True if message was logged
     */
    public static function error(string $message, array $context = []): bool
    {
        return self::log($message, 'ERROR', $context);
    }

    /**
     * Log a warning message
     *
     * @param string $message The message to log
     * @param array $context Optional context data
     * @return bool True if message was logged
     */
    public static function warning(string $message, array $context = []): bool
    {
        return self::log($message, 'WARNING', $context);
    }

    /**
     * Log an info message
     *
     * @param string $message The message to log
     * @param array $context Optional context data
     * @return bool True if message was logged
     */
    public static function info(string $message, array $context = []): bool
    {
        return self::log($message, 'INFO', $context);
    }

    /**
     * Log a debug message
     *
     * @param string $message The message to log
     * @param array $context Optional context data
     * @return bool True if message was logged
     */
    public static function debug(string $message, array $context = []): bool
    {
        return self::log($message, 'DEBUG', $context);
    }

    /**
     * Register as PHP error handler
     *
     * Converts PHP errors to log entries. Does not throw exceptions.
     *
     * @param bool $chainPrevious Whether to call previous handler after logging
     * @return void
     */
    public static function registerErrorHandler(bool $chainPrevious = true): void
    {
        self::$previousErrorHandler = set_error_handler(
            function (int $errno, string $errstr, string $errfile, int $errline) use ($chainPrevious): bool {
                // Respect error_reporting setting
                if (!(error_reporting() & $errno)) {
                    return false;
                }

                $level = self::ERROR_TYPE_MAP[$errno] ?? 'ERROR';
                $message = "{$errstr} in {$errfile}:{$errline}";

                self::log($message, $level, [
                    'error_type' => $errno,
                    'file' => $errfile,
                    'line' => $errline,
                ]);

                // Chain to previous handler if requested
                if ($chainPrevious && self::$previousErrorHandler !== null) {
                    return call_user_func(
                        self::$previousErrorHandler,
                        $errno,
                        $errstr,
                        $errfile,
                        $errline
                    );
                }

                // Return false to allow PHP's internal error handler to run
                return false;
            }
        );
    }

    /**
     * Register as PHP exception handler
     *
     * Catches uncaught exceptions and logs them.
     *
     * @param bool $chainPrevious Whether to call previous handler after logging
     * @return void
     */
    public static function registerExceptionHandler(bool $chainPrevious = true): void
    {
        self::$previousExceptionHandler = set_exception_handler(
            function (\Throwable $exception) use ($chainPrevious): void {
                $message = sprintf(
                    'Uncaught %s: %s in %s:%d',
                    get_class($exception),
                    $exception->getMessage(),
                    $exception->getFile(),
                    $exception->getLine()
                );

                $context = [
                    'exception_class' => get_class($exception),
                    'code' => $exception->getCode(),
                    'file' => $exception->getFile(),
                    'line' => $exception->getLine(),
                ];

                // Include trace if configured
                if (self::$config['include_trace']) {
                    $context['trace'] = $exception->getTraceAsString();
                }

                self::log($message, 'ERROR', $context);

                // Chain to previous handler if requested
                if ($chainPrevious && self::$previousExceptionHandler !== null) {
                    call_user_func(self::$previousExceptionHandler, $exception);
                }
            }
        );
    }

    /**
     * Register shutdown handler to catch fatal errors
     *
     * @return void
     */
    public static function registerShutdownHandler(): void
    {
        register_shutdown_function(function (): void {
            $error = error_get_last();

            if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                $message = sprintf(
                    'Fatal error: %s in %s:%d',
                    $error['message'],
                    $error['file'],
                    $error['line']
                );

                self::log($message, 'ERROR', [
                    'error_type' => $error['type'],
                    'file' => $error['file'],
                    'line' => $error['line'],
                ]);
            }
        });
    }

    /**
     * Register all handlers (error, exception, shutdown)
     *
     * @param bool $chainPrevious Whether to chain to previous handlers
     * @return void
     */
    public static function registerAllHandlers(bool $chainPrevious = true): void
    {
        self::registerErrorHandler($chainPrevious);
        self::registerExceptionHandler($chainPrevious);
        self::registerShutdownHandler();
    }

    /**
     * Restore original error handler
     *
     * @return void
     */
    public static function restoreErrorHandler(): void
    {
        restore_error_handler();
        self::$previousErrorHandler = null;
    }

    /**
     * Restore original exception handler
     *
     * @return void
     */
    public static function restoreExceptionHandler(): void
    {
        restore_exception_handler();
        self::$previousExceptionHandler = null;
    }

    /**
     * Set the minimum log level
     *
     * @param string $level Log level (ERROR, WARNING, INFO, DEBUG)
     * @return void
     */
    public static function setLogLevel(string $level): void
    {
        $level = strtoupper($level);
        if (isset(self::LEVEL_PRIORITIES[$level])) {
            self::$config['log_level'] = $level;
        }
    }

    /**
     * Set the log path
     *
     * @param string $path Directory path for log files
     * @return void
     */
    public static function setLogPath(string $path): void
    {
        self::$config['log_path'] = $path;
    }

    /**
     * Set the log filename
     *
     * @param string $filename Log filename
     * @return void
     */
    public static function setLogFile(string $filename): void
    {
        self::$config['log_file'] = $filename;
    }

    /**
     * Core logging implementation
     *
     * @param array $config Configuration to use
     * @param string $message Message to log
     * @param string $level Log level
     * @param array $context Context data
     * @return bool True if logged successfully
     */
    private static function writeLog(array $config, string $message, string $level, array $context): bool
    {
        $level = strtoupper($level);

        // Validate and get priorities
        $messagePriority = self::LEVEL_PRIORITIES[$level] ?? 1;
        $configuredPriority = self::LEVEL_PRIORITIES[$config['log_level']] ?? 1;

        // Filter by log level
        if ($messagePriority > $configuredPriority) {
            return false;
        }

        // Format timestamp
        $timestamp = date($config['date_format']);

        // Format context as JSON if present
        $contextStr = !empty($context) ? ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';

        // Build log entry
        $formattedMessage = "[{$timestamp}] [{$level}] {$message}{$contextStr}" . PHP_EOL;

        // Determine log file path
        $logPath = rtrim($config['log_path'], '/');
        $logFile = $logPath . '/' . $config['log_file'];

        // Ensure directory exists
        if (!is_dir($logPath)) {
            if (!@mkdir($logPath, $config['permissions'], true)) {
                // Fallback to PHP's error_log if directory creation fails
                error_log($message);
                return false;
            }
        }

        // Write to log file with locking
        $result = @file_put_contents($logFile, $formattedMessage, FILE_APPEND | LOCK_EX);

        if ($result === false) {
            // Fallback to PHP's error_log
            error_log($message);
            return false;
        }

        return true;
    }

    /**
     * Log an exception with full details
     *
     * @param \Throwable $exception The exception to log
     * @param string $level Log level (default: ERROR)
     * @param array $additionalContext Additional context data
     * @return bool True if logged successfully
     */
    public static function logException(\Throwable $exception, string $level = 'ERROR', array $additionalContext = []): bool
    {
        $message = sprintf(
            '%s: %s in %s:%d',
            get_class($exception),
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine()
        );

        $context = array_merge([
            'exception_class' => get_class($exception),
            'code' => $exception->getCode(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
        ], $additionalContext);

        // Include trace if configured
        if (self::$config['include_trace']) {
            $context['trace'] = $exception->getTraceAsString();
        }

        return self::log($message, $level, $context);
    }

    /**
     * Check if a log level would be logged with current configuration
     *
     * @param string $level Log level to check
     * @return bool True if level would be logged
     */
    public static function isLevelEnabled(string $level): bool
    {
        $level = strtoupper($level);
        $messagePriority = self::LEVEL_PRIORITIES[$level] ?? 1;
        $configuredPriority = self::LEVEL_PRIORITIES[self::$config['log_level']] ?? 1;

        return $messagePriority <= $configuredPriority;
    }

    /**
     * Get the full path to the current log file
     *
     * @return string Full path to log file
     */
    public static function getLogFilePath(): string
    {
        return rtrim(self::$config['log_path'], '/') . '/' . self::$config['log_file'];
    }
}
