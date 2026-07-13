<?php
/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * Optional full-page grace-period warning view. Not wired into
 * checkMiddleware() by design — a GRACE-status license is non-blocking, so
 * the module never renders this automatically. Available for hosts who want
 * a full-page grace notice instead of (or alongside) a custom banner built
 * from isInGracePeriod() + getDaysUntilGraceExpiration().
 */
$__ = static function (string $key, string $fallback): string {
    if (!function_exists('_')) {
        return $fallback;
    }
    $translated = _($key);
    // gettext returns the key itself when no catalog is bound for it — don't
    // leak that raw key to end users, fall back to the safe English text.
    return ($translated === $key) ? $fallback : $translated;
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $__('LICENSE_GRACE_TITLE', 'License Grace Period'); ?></title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .container {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            padding: 48px;
            max-width: 500px;
            text-align: center;
        }
        .icon {
            width: 80px;
            height: 80px;
            background: #dbeafe;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 24px;
        }
        .icon svg {
            width: 40px;
            height: 40px;
            color: #2563eb;
        }
        h1 {
            color: #1f2937;
            font-size: 1.875rem;
            font-weight: 700;
            margin-bottom: 16px;
        }
        .message {
            color: #6b7280;
            font-size: 1rem;
            line-height: 1.625;
            margin-bottom: 24px;
        }
        .grace-notice {
            background: #dbeafe;
            border: 1px solid #93c5fd;
            border-radius: 8px;
            padding: 16px;
            color: #1e40af;
            font-size: 0.875rem;
            margin-bottom: 24px;
        }
        .contact {
            color: #9ca3af;
            font-size: 0.875rem;
        }
        .contact a {
            color: #6366f1;
            text-decoration: none;
        }
        .contact a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="icon">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
        </div>
        <h1><?php echo $__('LICENSE_GRACE_TITLE', 'License Grace Period'); ?></h1>
        <p class="message">
            <?php echo $__('LICENSE_GRACE_MESSAGE', 'Your license has expired but is currently in a grace period. Please renew your license to avoid service interruption.'); ?>
        </p>
        <div class="grace-notice">
            <?php echo $__('LICENSE_GRACE_NOTICE', 'The system is fully operational during the grace period. Renew your license to ensure uninterrupted access.'); ?>
        </div>
        <p class="contact">
            <?php echo $__('LICENSE_CONTACT_SUPPORT', 'Please contact support to renew your license.'); ?>
        </p>
    </div>
</body>
</html>
