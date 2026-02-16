<?php
/**
 * Patch Maintenance Mode Page
 *
 * Self-contained view displayed to frontend users during patch installation.
 * Does NOT depend on database, session, or gettext — it may be rendered
 * before those subsystems are initialized.
 *
 * Variables available (passed via PatchModule::renderView or extracted from flag file):
 *   $flagFile  — Absolute path to the maintenance flag file
 *   $lang      — Language code ('hu' or 'en', default: 'en')
 *   $version   — Version being installed (from flag file)
 *
 * Usage in host application's entry point:
 *   if (file_exists($flagFile)) {
 *       http_response_code(503);
 *       echo $patchModule->renderView('maintenance', ['flagFile' => $flagFile]);
 *       exit;
 *   }
 *
 * @package PatchModule
 */

// Read maintenance flag for metadata (version, language)
$flagData = [];
if (isset($flagFile) && file_exists($flagFile)) {
    $raw = file_get_contents($flagFile);
    if ($raw !== false) {
        $flagData = json_decode($raw, true) ?: [];
    }
}

$lang = $flagData['language'] ?? ($lang ?? 'en');
$version = $flagData['version'] ?? ($version ?? '');

// Translations (hardcoded — gettext may not be available)
$texts = [
    'hu' => [
        'title'    => 'Rendszerfrissítés folyamatban',
        'heading'  => 'Rendszerfrissítés folyamatban',
        'message'  => 'Jelenleg frissítjük a rendszert. Kérjük, látogass vissza néhány perc múlva.',
        'version'  => 'Telepítés alatt álló verzió',
        'patience' => 'Köszönjük a türelmed!',
    ],
    'en' => [
        'title'    => 'System Update in Progress',
        'heading'  => 'System Update in Progress',
        'message'  => 'We are currently updating the system. Please check back in a few minutes.',
        'version'  => 'Version being installed',
        'patience' => 'Thank you for your patience!',
    ],
];

$t = $texts[$lang] ?? $texts['en'];
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($lang) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="refresh" content="30">
    <title><?= htmlspecialchars($t['title']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YcnS/1XCFKQjW6S1aYkYr6+Dgv/LQocdnM" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            color: white;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
        }
        .maintenance-container {
            max-width: 550px;
            text-align: center;
            padding: 2rem;
        }
        .maintenance-icon {
            font-size: 5rem;
            margin-bottom: 1.5rem;
            animation: pulse 2s ease-in-out infinite;
        }
        @keyframes pulse {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.7; transform: scale(1.05); }
        }
        .spinner-border {
            width: 1.5rem;
            height: 1.5rem;
            margin-right: 0.5rem;
        }
        .version-badge {
            display: inline-block;
            background: rgba(255,255,255,0.2);
            padding: 0.3rem 0.8rem;
            border-radius: 1rem;
            font-size: 0.9rem;
            margin-top: 0.5rem;
        }
    </style>
</head>
<body>
    <div class="maintenance-container">
        <div class="maintenance-icon">
            <i class="bi bi-arrow-repeat"></i>
        </div>
        <h1 class="mb-3"><?= htmlspecialchars($t['heading']) ?></h1>
        <p class="lead mb-4"><?= htmlspecialchars($t['message']) ?></p>
        <?php if (!empty($version)): ?>
            <div class="version-badge">
                <?= htmlspecialchars($t['version']) ?>: <strong>v<?= htmlspecialchars($version) ?></strong>
            </div>
        <?php endif; ?>
        <div class="mt-4">
            <span class="spinner-border spinner-border-sm" role="status"></span>
            <?= htmlspecialchars($t['patience']) ?>
        </div>
    </div>
</body>
</html>