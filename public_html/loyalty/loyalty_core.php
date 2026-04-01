<?php
/**
 * Loyalty lookup: requires signed card token (GC1:uid:sig) and current restaurant.
 * Returns only balance. Token must belong to current restaurant (tenant-bound).
 */
require_once __DIR__ . '/../../app/bootstrap.php';

if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
}

if (empty($currentRestaurant) || empty($currentRestaurant['id'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'restaurant_required'], JSON_UNESCAPED_UNICODE);
    exit;
}

$token = trim($_GET['token'] ?? $_POST['token'] ?? '');
if ($token === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'token_required'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!function_exists('guest_card_parse_token') || !function_exists('guest_get_card_by_token')) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'unavailable'], JSON_UNESCAPED_UNICODE);
    exit;
}

$parsed = guest_card_parse_token($token);
if (!$parsed) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'invalid_token'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = db();
$card = guest_get_card_by_token($pdo, $token);
if (!$card) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'not_found'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ((int)($card['restaurant_id'] ?? 0) !== (int)$currentRestaurant['id']) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'invalid_token'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'ok' => true,
    'balance' => (int)($card['balance'] ?? 0),
], JSON_UNESCAPED_UNICODE);
