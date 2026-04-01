<?php
/**
 * Balance lookup: requires signed card token (GC1:uid:sig). Returns only balance.
 * No raw phone or UID lookup — no public enumeration of guest balances.
 */
require_once __DIR__ . '/../../app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if (!$currentRestaurant) {
    echo json_encode(['success' => false, 'message' => 'Restaurant context required']);
    exit;
}

$token = trim($_GET['token'] ?? $_POST['token'] ?? '');
if ($token === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'token_required']);
    exit;
}

if (!function_exists('guest_card_parse_token') || !function_exists('guest_get_card_by_token')) {
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => 'unavailable']);
    exit;
}

$parsed = guest_card_parse_token($token);
if (!$parsed) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'invalid_token']);
    exit;
}

$pdo = db();
$card = guest_get_card_by_token($pdo, $token);
if (!$card) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'not_found']);
    exit;
}

if ((int)($card['restaurant_id'] ?? 0) !== (int)$currentRestaurant['id']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'invalid_token']);
    exit;
}

echo json_encode([
    'success' => true,
    'balance'  => (int)($card['balance'] ?? 0),
]);
