<?php
/**
 * Loyalty lookup: requires signed card token (GC1:uid:sig). Returns only balance (no card/guest data).
 * No raw UID lookup — prevents tenant/privacy exposure.
 */
require_once __DIR__ . '/../../app/bootstrap.php';

set_exception_handler(function (Throwable $e) {
    error_log('PUBLIC_PAGE_ERROR ' . json_encode([
        'uri' => $_SERVER['REQUEST_URI'] ?? '',
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'time' => gmdate('c'),
    ], JSON_UNESCAPED_UNICODE));
    if (!headers_sent()) {
        http_response_code(200);
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'internal_error']);
    exit;
});

if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
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

if (empty($currentRestaurant) || empty($currentRestaurant['id'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'restaurant_required'], JSON_UNESCAPED_UNICODE);
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

// Soft loyalty gating: return balance 0 when plan has loyalty disabled (no write; safe for readers).
$balance = (int)($card['balance'] ?? 0);
if (file_exists(__DIR__ . '/../../app/subscription_plans.php')) {
    require_once __DIR__ . '/../../app/subscription_plans.php';
    if (function_exists('check_feature') && !check_feature((int)$currentRestaurant['id'], 'loyalty_enabled')) {
        $balance = 0;
    }
}

echo json_encode([
    'ok' => true,
    'balance' => $balance,
], JSON_UNESCAPED_UNICODE);
