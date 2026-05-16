<?php
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/guest_order_loyalty_attach.php';

header('Content-Type: application/json; charset=utf-8');

if (!$currentRestaurant) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'restaurant_not_found'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'method_not_allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = db();
$guest = guest_current($pdo);
if (!$guest) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = file_get_contents('php://input');
$body = json_decode((string)$raw, true);
if (!is_array($body)) {
    $body = $_POST;
}

$orderId = (int)($body['order_id'] ?? 0);
if ($orderId <= 0) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'invalid_order'], JSON_UNESCAPED_UNICODE);
    exit;
}

$res = guest_order_attach_loyalty($pdo, $currentRestaurant, $orderId, (int)$guest['id']);
if (!$res['ok']) {
    $err = (string)($res['error'] ?? 'error');
    $code = 400;
    if ($err === 'unauthorized') {
        $code = 401;
    }
    if ($err === 'order_not_found') {
        $code = 404;
    }
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $err], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'success' => true,
    'already' => !empty($res['already']),
    'points' => (int)($res['points'] ?? 0),
    'balance' => (int)($res['balance'] ?? 0),
    'pending_payment' => !empty($res['pending_payment']),
    'attached' => !empty($res['attached']),
    'skipped' => !empty($res['skipped']),
], JSON_UNESCAPED_UNICODE);
