<?php

declare(strict_types=1);

/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * Regression fixture for Reusables#63 — ErrorHandler::sanitizeContext() only masked secret-looking keys whose
 * value was a scalar, never looked at values, and missed keys such as authorization / cookie / session. It now
 * masks the whole value of a secret-looking key at any depth, knows more key names and replaces Bearer/Basic
 * credentials and password=... fragments inside string values (and messages).
 *
 * Goes through the public logging API into a temporary log file: php tests/redaction_test.php
 */

require_once __DIR__ . '/../ErrorHandler.php';

use ErrorHandling\ErrorHandler;

$failures = 0;

/**
 * Records one assertion and prints its result.
 *
 * @param string $name Description of what is asserted.
 * @param bool   $ok   Whether it holds.
 * @return void
 */
function check(string $name, bool $ok): void
{
    global $failures;
    echo ($ok ? '  ok   ' : '  FAIL ') . $name . "\n";
    if (!$ok) {
        $failures++;
    }
}

$dir = sys_get_temp_dir() . '/eh_redaction_' . bin2hex(random_bytes(4));
mkdir($dir, 0700, true);
ErrorHandler::init(['log_path' => $dir, 'log_file' => 'test.log', 'log_level' => 'DEBUG']);

/**
 * Logs one message with a context and returns the log line written for it.
 *
 * @param string               $message Message.
 * @param array<string,mixed>  $context Context.
 * @return string The log file's content for this call.
 */
function logged(string $message, array $context): string
{
    global $dir;
    $file = $dir . '/test.log';
    @unlink($file);
    ErrorHandler::log($message, 'ERROR', $context);
    return is_file($file) ? (string)file_get_contents($file) : '';
}

// 1. A secret-looking key with an ARRAY value is masked as a whole.
$out = logged('m', ['token' => ['v' => 'abc-secret-111']]);
check('an array under a secret-named key is masked, not recursed into', !str_contains($out, 'abc-secret-111') && str_contains($out, '"token":"***"'));

// 2. Masking works at any depth.
$out = logged('m', ['a' => ['b' => ['c' => ['password' => 'deep-pass-222']]]]);
check('a secret key nested several levels down is masked', !str_contains($out, 'deep-pass-222'));

// 3. The wider key pattern.
foreach (['authorization', 'Authorization', 'bearer', 'cookie', 'Set-Cookie', 'session', 'session_id', 'credential', 'credentials', 'api_key', 'api-key', 'apikey', 'API Key'] as $key) {
    $out = logged('m', [$key => 'value-for-' . bin2hex($key)]);
    check("key \"{$key}\" is masked", !str_contains($out, 'value-for-'));
}

// 4. Values are inspected: Bearer / Basic credentials and password fragments inside strings.
$out = logged('m', ['note' => 'Authorization failed for Bearer abcdef0123456789.token.part']);
check('a Bearer credential inside a string value is replaced, the rest of the text is kept', !str_contains($out, 'abcdef0123456789') && str_contains($out, 'Bearer ***') && str_contains($out, 'Authorization failed for'));
$out = logged('m', ['header' => 'Basic dXNlcjpzZWNyZXRwYXNz']);
check('a Basic credential inside a string value is replaced', !str_contains($out, 'dXNlcjpzZWNyZXRwYXNz') && str_contains($out, 'Basic ***'));
$out = logged('m', ['sql' => 'connect failed: password=hunter2xyz; host=db']);
check('a password=... fragment inside a string value is replaced', !str_contains($out, 'hunter2xyz') && str_contains($out, 'password=***'));
$out = logged('m', ['list' => ['x', 'call with Bearer zzzzzzzzzzzz1234 header']]);
check('credentials inside strings in nested lists are replaced too', !str_contains($out, 'zzzzzzzzzzzz1234'));

// 5. The message text gets the same treatment.
$out = logged('request failed: Authorization: Bearer msgtoken9876543210', []);
check('a Bearer credential in the message text is replaced', !str_contains($out, 'msgtoken9876543210'));

// 6. Nothing else changes: ordinary context, short words and non-strings pass through.
$out = logged('m', ['company_id' => 7, 'step' => 'cash_register_sync', 'ok' => true, 'ratio' => 1.5, 'nothing' => null, 'text' => 'Basic setup finished']);
check('ordinary values are untouched', str_contains($out, '"company_id":7') && str_contains($out, '"step":"cash_register_sync"') && str_contains($out, '"ok":true') && str_contains($out, '"ratio":1.5') && str_contains($out, '"nothing":null'));
check('a short ordinary word after "Basic" is not mistaken for a credential', str_contains($out, 'Basic setup finished'));

exec('rm -rf ' . escapeshellarg($dir));
echo $failures === 0 ? "\nall passed\n" : "\n{$failures} failed\n";
exit($failures === 0 ? 0 : 1);
