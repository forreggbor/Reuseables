<?php
/**
 * Dev-only gallery list endpoint for StarEditor gallery picker.
 * No auth — development use only.
 */
header('Content-Type: application/json');
header('Cache-Control: no-cache');

$galleries = [
    ['id' => 1, 'name' => 'Nature',       'cover' => 'images/landscape/placeholder-1.svg', 'image_count' => 8],
    ['id' => 2, 'name' => 'Icons',        'cover' => 'images/icons/placeholder-2.svg',     'image_count' => 3],
    ['id' => 3, 'name' => 'Samples',      'cover' => 'images/placeholder-1.svg',           'image_count' => 6],
    ['id' => 4, 'name' => 'Architecture', 'cover' => null,                                  'image_count' => 0],
    ['id' => 5, 'name' => 'People',       'cover' => null,                                  'image_count' => 12],
];

$page     = max(1, (int) ($_GET['page']     ?? 1));
$pageSize = min(100, max(1, (int) ($_GET['pageSize'] ?? 12)));
$total    = count($galleries);
$items    = array_slice($galleries, ($page - 1) * $pageSize, $pageSize);

echo json_encode([
    'items'    => $items,
    'total'    => $total,
    'page'     => $page,
    'pageSize' => $pageSize,
]);
