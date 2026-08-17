<?php

/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * Patch Maintenance Mode Page
 *
 * Self-contained view displayed to frontend users during patch installation.
 * Does NOT depend on database, session, gettext, the Composer autoloader, or
 * any other module asset — it may be rendered before those subsystems are
 * initialized (the module directory itself may be mid-rewrite during a
 * self-update). Its only external reference is a same-origin stylesheet
 * (see below); if that stylesheet fails to load, the page must still be
 * readable, so the markup here stays plain semantic HTML.
 *
 * Variables available (passed via PatchModule::renderView or extracted from flag file):
 *   $flagFile  — Absolute path to the maintenance flag file
 *   $lang      — Language code ('hu' or 'en', default: 'en')
 *   $version   — Version being installed (from flag file)
 *   $cssUrl    — Optional override for the stylesheet URL; defaults to the
 *                conventional path used by the module's other assets
 *                (see doc/INTEGRATION-GUIDE.md, "Assets" step)
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
$cssUrl = $cssUrl ?? '/css/patch-maintenance.css';

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
    <link rel="stylesheet" href="<?= htmlspecialchars($cssUrl) ?>">
</head>
<body class="patch-maintenance">
    <div class="patch-maintenance-container">
        <div class="patch-maintenance-icon" aria-hidden="true"></div>
        <h1 class="patch-maintenance-heading"><?= htmlspecialchars($t['heading']) ?></h1>
        <p class="patch-maintenance-message"><?= htmlspecialchars($t['message']) ?></p>
        <?php if (!empty($version)): ?>
            <div class="patch-maintenance-version">
                <?= htmlspecialchars($t['version']) ?>: <strong>v<?= htmlspecialchars($version) ?></strong>
            </div>
        <?php endif; ?>
        <div class="patch-maintenance-patience">
            <span class="patch-maintenance-spinner-sm" role="status" aria-hidden="true"></span>
            <?= htmlspecialchars($t['patience']) ?>
        </div>
    </div>
</body>
</html>
