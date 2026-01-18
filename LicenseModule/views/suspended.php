<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo function_exists('_') ? _('LICENSE_SUSPENDED_TITLE') : 'License Suspended'; ?></title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
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
            background: #fee2e2;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 24px;
        }
        .icon svg {
            width: 40px;
            height: 40px;
            color: #dc2626;
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
        .suspended-notice {
            background: #fee2e2;
            border: 1px solid #fca5a5;
            border-radius: 8px;
            padding: 16px;
            color: #991b1b;
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
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636" />
            </svg>
        </div>
        <h1><?php echo function_exists('_') ? _('LICENSE_SUSPENDED_TITLE') : 'License Suspended'; ?></h1>
        <p class="message">
            <?php echo function_exists('_') ? _('LICENSE_SUSPENDED_MESSAGE') : 'Your license has been suspended. Access to the system has been temporarily disabled.'; ?>
        </p>
        <div class="suspended-notice">
            <?php echo function_exists('_') ? _('LICENSE_SUSPENDED_NOTICE') : 'All system functionality is currently unavailable. Please contact support to resolve this issue.'; ?>
        </div>
        <p class="contact">
            <?php echo function_exists('_') ? _('LICENSE_CONTACT_SUPPORT') : 'Please contact support to restore your license.'; ?>
        </p>
    </div>
</body>
</html>
