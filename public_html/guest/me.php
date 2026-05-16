<?php
require_once __DIR__ . '/../../app/bootstrap.php';

if (!$currentRestaurant) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'restaurant_not_found'], JSON_UNESCAPED_UNICODE);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

$pdo = db();
$g = guest_current($pdo);
if (!$g) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

$restaurantId = (int)$currentRestaurant['id'];
$guestId = (int)$g['id'];
$balance = 0;
if (function_exists('guest_loyalty_balance_by_guest_rest')) {
    $balance = guest_loyalty_balance_by_guest_rest($pdo, $restaurantId, $guestId);
}

echo json_encode([
    'success' => true,
    'guest' => [
        'id' => $guestId,
        'phone' => (string)($g['phone'] ?? ''),
        'name' => $g['name'] ?? null,
        'loyalty_balance' => $balance,
    ],
], JSON_UNESCAPED_UNICODE);
