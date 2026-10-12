<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * Regression fixture for issue #56: PhpHelper write paths must catch Throwable
 * (not only Exception) and log the failure before returning an error result.
 * Runs without a database: php tests/catch_throwable_regression.php
 */

require __DIR__ . '/../src/Exec/Logger.php';
require __DIR__ . '/../src/Exec/PhpHelper.php';

use BackupRestore\Exec\Logger;
use BackupRestore\Exec\PhpHelper;

/** @var list<array{level: string, message: string}> $captured */
$captured = [];
Logger::configure(static function (string $message, string $level) use (&$captured): void {
    $captured[] = ['level' => $level, 'message' => $message];
});

// A non-existent source directory makes rtrim() receive false, which raises a
// TypeError (an Error, not an Exception). Before the fix this escaped the
// method uncaught; after it, the failure must be logged and returned as an error.
$sourceDir = __DIR__ . '/__missing_source_dir__';
$outputTar = sys_get_temp_dir() . '/catch_throwable_regression_' . bin2hex(random_bytes(4)) . '.tar';

try {
    $result = PhpHelper::tarCreate($outputTar, $sourceDir);
} catch (\Throwable $t) {
    fwrite(STDERR, "FAIL: TypeError escaped tarCreate: " . $t->getMessage() . PHP_EOL);
    exit(1);
} finally {
    @unlink($outputTar);
}

$failures = [];

if ($result['success'] !== false) {
    $failures[] = 'expected success=false, got ' . var_export($result['success'], true);
}

$errorLogs = array_filter(
    $captured,
    static fn (array $entry): bool => $entry['level'] === 'ERROR'
        && str_contains($entry['message'], '[PhpHelper] tarCreate failed:')
);

if (count($errorLogs) !== 1) {
    $failures[] = 'expected exactly one ERROR log line from tarCreate, got ' . count($errorLogs);
}

if ($failures !== []) {
    fwrite(STDERR, "FAIL:\n - " . implode("\n - ", $failures) . PHP_EOL);
    exit(1);
}

echo "PASS: tarCreate catches Throwable and logs the failure\n";
exit(0);
