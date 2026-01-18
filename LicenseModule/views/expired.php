<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo function_exists('_') ? _('LICENSE_EXPIRED_TITLE') : 'License Expired'; ?></title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
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
            background: #fef3c7;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 24px;
        }
        .icon svg {
            width: 40px;
            height: 40px;
            color: #d97706;
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
        .readonly-notice {
            background: #fef3c7;
            border: 1px solid #fcd34d;
            border-radius: 8px;
            padding: 16px;
            color: #92400e;
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
        <h1><?php echo function_exists('_') ? _('LICENSE_EXPIRED_TITLE') : 'License Expired'; ?></h1>
        <p class="message">
            <?php echo function_exists('_') ? _('LICENSE_EXPIRED_MESSAGE') : 'Your license has expired. Please renew your license to continue using all features.'; ?>
        </p>
        <div class="readonly-notice">
            <?php echo function_exists('_') ? _('LICENSE_EXPIRED_READONLY') : 'The system is currently in read-only mode. You can view data but cannot make changes.'; ?>
        </div>
        <p class="contact">
            <?php echo function_exists('_') ? _('LICENSE_CONTACT_SUPPORT') : 'Please contact support to renew your license.'; ?>
        </p>
    </div>
</body>
</html>
