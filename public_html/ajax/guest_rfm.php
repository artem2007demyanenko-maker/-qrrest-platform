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

$guestProfileId = (int)$readInput('guest_profile_id', 0);
$phoneRaw = trim((string)$readInput('phone', $readInput('phone_normalized', $readInput('guest_phone', ''))));

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
    if (
        !function_exists('guest_history_resolve_phone')
        || !function_exists('crm_guest_rfm_metrics')
        || !function_exists('crm_guest_rfm_score')
        || !function_exists('crm_guest_rfm_segment')
    ) {
        throw new RuntimeException('guest_rfm_helpers_missing');
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
            'guest' => null,
            'rfm' => null,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $metrics = crm_guest_rfm_metrics($pdo, $restaurantId, $resolved);
    if ($metrics === null) {
        echo json_encode([
            'success' => true,
            'found' => false,
            'guest' => null,
            'rfm' => null,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $score = crm_guest_rfm_score($metrics);
    $segment = crm_guest_rfm_segment($metrics, $score);

    echo json_encode([
        'success' => true,
        'found' => true,
        'guest' => [
            'guest_profile_id' => (int)($resolved['guest_profile_id'] ?? 0),
            'phone_normalized' => (string)($metrics['phone_normalized'] ?? ''),
            'guest_name' => (string)($metrics['guest_name'] ?? 'Гость'),
            'orders_count' => (int)($metrics['orders_count'] ?? 0),
            'total_spent' => (float)($metrics['total_spent'] ?? 0),
            'average_check' => (float)($metrics['average_check'] ?? 0),
            'first_order_at' => $metrics['first_order_at'] ?? null,
            'last_order_at' => $metrics['last_order_at'] ?? null,
        ],
        'rfm' => [
            'metrics' => [
                'recency_days' => $metrics['recency_days'],
                'frequency_count' => (int)($metrics['frequency_count'] ?? 0),
                'monetary_total' => (float)($metrics['monetary_total'] ?? 0),
                'monetary_avg' => (float)($metrics['monetary_avg'] ?? 0),
            ],
            'score' => [
                'r' => (int)($score['r'] ?? 1),
                'f' => (int)($score['f'] ?? 1),
                'm' => (int)($score['m'] ?? 1),
                'combined' => (int)($score['combined'] ?? 111),
                'code' => (string)($score['code'] ?? '111'),
            ],
            'segment' => [
                'key' => (string)($segment['key'] ?? 'regular'),
                'label' => (string)($segment['label'] ?? 'Regular'),
                'description' => (string)($segment['description'] ?? ''),
            ],
        ],
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('GUEST_RFM_ENDPOINT_FAIL restaurant_id=' . $restaurantId . ' ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'internal_error',
    ], JSON_UNESCAPED_UNICODE);
}

