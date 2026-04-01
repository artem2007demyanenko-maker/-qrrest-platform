<?php
/**
 * API: accept lead form (name, restaurant_name, phone, city). Insert into lead_requests.
 */

$rid = bin2hex(random_bytes(4));
require_once __DIR__ . '/../../app/bootstrap.php';
set_exception_handler(function (Throwable $e) use ($rid) {
    error_log('STABILITY_ERROR api/lead_request rid=' . $rid . ' ' . $e->getMessage());
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
    }
    echo json_encode(['success' => false, 'error' => 'Server error']);
    exit;
});
if (file_exists(__DIR__ . '/../../app/schema_guard.php')) {
    require_once __DIR__ . '/../../app/schema_guard.php';
}

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$name            = trim((string)($_POST['name'] ?? ''));
$restaurant_name = trim((string)($_POST['restaurant_name'] ?? ''));
$phone           = trim((string)($_POST['phone'] ?? ''));
$city            = trim((string)($_POST['city'] ?? ''));

if (mb_strlen($name) > 255) {
    $name = mb_substr($name, 0, 255);
}
if (mb_strlen($restaurant_name) > 255) {
    $restaurant_name = mb_substr($restaurant_name, 0, 255);
}
if (mb_strlen($phone) > 64) {
    $phone = mb_substr($phone, 0, 64);
}
if (mb_strlen($city) > 255) {
    $city = mb_substr($city, 0, 255);
}

if (!function_exists('db_table_exists') || !db_table_exists('lead_requests')) {
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => 'Service unavailable']);
    exit;
}

try {
    $pdo = db();
    $now = date('Y-m-d H:i:s');
    $userId = null;
    if (function_exists('auth_user')) {
        $u = auth_user();
        $userId = isset($u['id']) ? (int)$u['id'] : null;
    }

    if (function_exists('db_column_exists') && db_column_exists('lead_requests', 'contact_name')) {
        $stmt = $pdo->prepare("INSERT INTO lead_requests (contact_name, contact_phone, restaurant_name, city, user_id, status, created_at) VALUES (?, ?, ?, ?, ?, 'new', ?)");
        $stmt->execute([$name ?: null, $phone ?: null, $restaurant_name ?: null, $city ?: null, $userId, $now]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO lead_requests (name, restaurant_name, phone, city, created_at) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$name ?: null, $restaurant_name ?: null, $phone ?: null, $city ?: null, $now]);
    }

    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    error_log('STABILITY_ERROR api/lead_request insert rid=' . $rid . ' ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error']);
}
