<?php
/**
 * Legacy public card issue: state-changing only via POST + CSRF. Uses canonical guest_issue_card_for_restaurant.
 * GET returns 405. Demo blocks writes.
 */
require_once __DIR__ . '/../../app/bootstrap.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (function_exists('is_demo_mode') && is_demo_mode()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'demo_write_disabled'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
$csrfOk = isset($_POST['csrf'], $_SESSION['csrf']) && hash_equals((string)$_SESSION['csrf'], (string)$_POST['csrf']);
if (!$csrfOk) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_csrf'], JSON_UNESCAPED_UNICODE);
    exit;
}

$guest_id = (int)($_SESSION['guest_id'] ?? 0);
if (!$guest_id) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'guest_not_logged'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!$currentRestaurant) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'restaurant_not_detected'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = db();
$stmt = $pdo->prepare("SELECT id, phone, name FROM guests WHERE id = ? LIMIT 1");
$stmt->execute([$guest_id]);
$guest = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$guest) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'guest_not_found'], JSON_UNESCAPED_UNICODE);
    exit;
}

$restaurant_id = (int)$currentRestaurant['id'];
$result = guest_issue_card_for_restaurant($pdo, $restaurant_id, (string)$guest['phone'], $guest['name'] ?? null);

if (empty($result['ok'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $result['error'] ?? 'issue_failed', 'need_register' => !empty($result['need_register'])], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'ok' => true,
    'card' => $result['card'] ?? null,
    'balance' => (int)($result['balance'] ?? 0),
], JSON_UNESCAPED_UNICODE);
