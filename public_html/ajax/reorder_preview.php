<?php

require_once __DIR__ . '/../../app/bootstrap.php';
if (file_exists(__DIR__ . '/../../app/schema_guard.php')) {
    require_once __DIR__ . '/../../app/schema_guard.php';
}
if (file_exists(__DIR__ . '/../../app/runtime_schema_bootstrap.php')) {
    require_once __DIR__ . '/../../app/runtime_schema_bootstrap.php';
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if (!function_exists('auth_user') || !auth_user()) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'unauthorized',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($currentRestaurant) || (int)($currentRestaurant['id'] ?? 0) <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'restaurant_context_required',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$restaurantId = (int)($currentRestaurant['id'] ?? 0);
$staffRole = function_exists('current_user_restaurant_role')
    ? (string)current_user_restaurant_role($restaurantId)
    : '';
$allowedRoles = ['owner', 'admin', 'waiter', 'staff'];
if (!in_array($staffRole, $allowedRoles, true)) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'forbidden',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$readInput = static function (string $key, $default = null) {
    if (array_key_exists($key, $_POST)) {
        return $_POST[$key];
    }
    if (array_key_exists($key, $_GET)) {
        return $_GET[$key];
    }
    return $default;
};

$orderId = (int)$readInput('order_id', 0);
$guestProfileId = (int)$readInput('guest_profile_id', 0);
$phoneRaw = trim((string)$readInput('phone', $readInput('phone_normalized', $readInput('guest_phone', ''))));

if ($orderId <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'order_id_required',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($guestProfileId <= 0 && $phoneRaw === '') {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'guest_identity_required',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = db();
if (function_exists('runtime_schema_ensure_guest_profiles')) {
    runtime_schema_ensure_guest_profiles($pdo);
}

try {
    if (!function_exists('guest_history_resolve_phone') || !function_exists('guest_order_reorder_payload')) {
        throw new RuntimeException('reorder_helpers_missing');
    }

    $resolved = guest_history_resolve_phone($pdo, $restaurantId, [
        'phone' => $phoneRaw,
        'phone_normalized' => trim((string)$readInput('phone_normalized', '')),
        'guest_phone' => trim((string)$readInput('guest_phone', '')),
        'customer_phone' => trim((string)$readInput('customer_phone', '')),
        'delivery_phone' => trim((string)$readInput('delivery_phone', '')),
        'loyalty_phone' => trim((string)$readInput('loyalty_phone', '')),
        'guest_profile_id' => $guestProfileId,
    ]);
    if ($resolved === null) {
        echo json_encode([
            'success' => true,
            'found' => false,
            'message' => 'guest_not_found',
            'reorder_preview' => null,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $payload = guest_order_reorder_payload($pdo, $restaurantId, $resolved, $orderId);
    if ($payload === null) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'order_not_found_or_not_owned',
            'reorder_preview' => null,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode([
        'success' => true,
        'found' => true,
        'reorder_preview' => $payload,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('REORDER_PREVIEW_FAIL restaurant_id=' . $restaurantId . ' order_id=' . $orderId . ' ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'internal_error',
        'reorder_preview' => null,
    ], JSON_UNESCAPED_UNICODE);
}
