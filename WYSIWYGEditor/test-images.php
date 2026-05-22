<?php
/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * Development-only image listing endpoint for WYSIWYGEditor server-browse testing.
 * Supports pagination, search, and folder navigation; returns the JSON envelope
 * expected by the client.
 *
 * WARNING: This file is for local development only. Do NOT deploy to production.
 * It serves files without authentication. Exclude from rsync when deploying the editor.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache');
header('X-Content-Type-Options: nosniff');

$imagesRoot      = __DIR__ . '/images';
$allowedExts     = ['svg', 'png', 'jpg', 'jpeg', 'gif', 'webp'];

$page     = max(1, (int) ($_GET['page']     ?? 1));
$pageSize = max(1, min(100, (int) ($_GET['pageSize'] ?? 16)));
$q        = strtolower(trim((string) ($_GET['q']      ?? '')));
$folder   = trim((string) ($_GET['folder'] ?? ''), '/');

// --- Validate folder path ---
$resolvedDir = $imagesRoot;
if ($folder !== '') {
    // Reject any path component that contains .. or is absolute
    foreach (explode('/', $folder) as $segment) {
        if ($segment === '' || $segment === '..' || $segment === '.') {
            $folder      = '';
            $resolvedDir = $imagesRoot;
            break;
        }
    }
    if ($folder !== '') {
        $candidate = realpath($imagesRoot . '/' . $folder);
        // realpath() returns false if path does not exist; also reject escapes
        if ($candidate === false || strncmp($candidate, $imagesRoot . DIRECTORY_SEPARATOR, strlen($imagesRoot) + 1) !== 0) {
            $folder      = '';
            $resolvedDir = $imagesRoot;
        } else {
            $resolvedDir = $candidate;
        }
    }
}

// --- List files in resolved folder ---
$extPattern = '{' . implode(',', $allowedExts) . '}';
$rawFiles   = glob($resolvedDir . '/*.' . $extPattern, GLOB_BRACE);
if ($rawFiles === false) {
    $rawFiles = [];
}

// Filter by search query
$filtered = [];
foreach ($rawFiles as $file) {
    $basename = basename($file);
    if ($q === '' || str_contains(strtolower($basename), $q)) {
        $relUrl = 'images/' . ($folder !== '' ? $folder . '/' : '') . $basename;
        $filtered[] = ['url' => $relUrl, 'name' => $basename];
    }
}

$total = count($filtered);
$items = array_slice($filtered, ($page - 1) * $pageSize, $pageSize);

// --- Build folderTree by recursive scan ---
$folderTree = [];
$iterator   = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($imagesRoot, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);
foreach ($iterator as $entry) {
    if ($entry->isDir()) {
        $rel = ltrim(substr($entry->getPathname(), strlen($imagesRoot)), DIRECTORY_SEPARATOR);
        $rel = str_replace(DIRECTORY_SEPARATOR, '/', $rel);
        $folderTree[] = $rel;
    }
}
sort($folderTree);

echo json_encode([
    'items'      => $items,
    'total'      => $total,
    'page'       => $page,
    'pageSize'   => $pageSize,
    'folder'     => $folder,
    'folderTree' => $folderTree,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
