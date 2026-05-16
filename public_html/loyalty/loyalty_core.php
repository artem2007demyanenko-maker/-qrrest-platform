<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if (!$currentRestaurant) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'success' => false, 'error' => 'restaurant_required'], JSON_UNESCAPED_UNICODE);
    exit;
}

$token = trim($_GET['token'] ?? $_POST['token'] ?? '');
if ($token === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'success' => false, 'error' => 'token_required'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!function_exists('guest_card_parse_token') || !function_exists('guest_get_card_by_token')) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'success' => false, 'error' => 'unavailable'], JSON_UNESCAPED_UNICODE);
    exit;
}

$parsed = guest_card_parse_token($token);
if (!$parsed) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'success' => false, 'error' => 'invalid_token'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = db();
$card = $pdo instanceof PDO ? guest_get_card_by_token($pdo, $token) : null;
if (!$card) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'success' => false, 'error' => 'not_found'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ((int)($card['restaurant_id'] ?? 0) !== (int)$currentRestaurant['id']) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'success' => false, 'error' => 'invalid_token'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'ok' => true,
    'success' => true,
    'balance' => (int)($card['balance'] ?? 0),
], JSON_UNESCAPED_UNICODE);
