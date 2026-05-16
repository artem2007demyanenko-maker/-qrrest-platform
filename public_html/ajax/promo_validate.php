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

if (!isset($currentRestaurant) || (int)($currentRestaurant['id'] ?? 0) <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'restaurant_context_required',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$restaurantId = (int)($currentRestaurant['id'] ?? 0);
$pdo = db();

if (function_exists('runtime_schema_ensure_restaurant_promotions')) {
    runtime_schema_ensure_restaurant_promotions($pdo);
}
if (function_exists('runtime_schema_ensure_guest_profiles')) {
    runtime_schema_ensure_guest_profiles($pdo);
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

$rawBody = file_get_contents('php://input');
$jsonBody = json_decode((string)$rawBody, true);
if (!is_array($jsonBody)) {
    $jsonBody = [];
}

$input = static function (string $key, $default = null) use ($jsonBody, $readInput) {
    if (array_key_exists($key, $jsonBody)) {
        return $jsonBody[$key];
    }
    return $readInput($key, $default);
};

$code = trim((string)$input('code', $input('promo_code', $input('coupon_code', ''))));
$orderTotal = (float)$input('order_total', $input('subtotal', $input('cart_total', 0)));

if ($code === '') {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'promo_code_required',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$resolvedGuest = null;
if (function_exists('guest_history_resolve_phone')) {
    try {
        $resolvedGuest = guest_history_resolve_phone($pdo, $restaurantId, [
            'guest_profile_id' => (int)$input('guest_profile_id', 0),
            'phone' => (string)$input('phone', ''),
            'phone_normalized' => (string)$input('phone_normalized', ''),
            'guest_phone' => (string)$input('guest_phone', ''),
            'customer_phone' => (string)$input('customer_phone', ''),
            'delivery_phone' => (string)$input('delivery_phone', ''),
            'loyalty_phone' => (string)$input('loyalty_phone', ''),
        ]);
    } catch (Throwable $e) {
        $resolvedGuest = null;
        if (function_exists('error_log')) {
            error_log('PROMO_VALIDATE_GUEST_RESOLVE_FAIL restaurant_id=' . $restaurantId . ' ' . $e->getMessage());
        }
    }
}

try {
    if (!function_exists('promo_order_apply_preview')) {
        throw new RuntimeException('promo_helpers_missing');
    }

    $result = promo_order_apply_preview($pdo, $restaurantId, $code, [
        'order_total' => $orderTotal,
        'resolved_guest' => is_array($resolvedGuest) ? $resolvedGuest : [],
    ]);

    $promotion = is_array($result['promotion'] ?? null) ? $result['promotion'] : null;
    $preview = is_array($result['preview'] ?? null) ? $result['preview'] : null;

    $response = [
        'success' => (bool)($result['ok'] ?? false),
        'ok' => (bool)($result['ok'] ?? false),
        'reason' => (string)($result['reason'] ?? 'unknown'),
        'message' => (string)($result['message'] ?? ''),
        'code' => function_exists('promo_normalize_code') ? promo_normalize_code($code) : $code,
        'guest' => [
            'known' => is_array($resolvedGuest),
            'guest_profile_id' => is_array($resolvedGuest) ? (int)($resolvedGuest['guest_profile_id'] ?? 0) : 0,
            'phone_normalized' => is_array($resolvedGuest) ? (string)($resolvedGuest['phone_normalized'] ?? '') : '',
            'guest_name' => is_array($resolvedGuest) ? (string)($resolvedGuest['guest_name'] ?? '') : '',
        ],
        'promotion' => null,
        'preview' => null,
    ];

    if (is_array($promotion)) {
        $response['promotion'] = [
            'id' => (int)($promotion['id'] ?? 0),
            'title' => (string)($promotion['title'] ?? ''),
            'description' => (string)($promotion['description'] ?? ''),
            'discount_type' => (string)($promotion['discount_type'] ?? ''),
            'discount_value' => (float)($promotion['discount_value'] ?? 0),
            'min_order_amount' => (float)($promotion['min_order_amount'] ?? 0),
            'is_active' => (int)($promotion['is_active'] ?? 0) === 1,
            'valid_from' => $promotion['valid_from'] ?? null,
            'valid_until' => $promotion['valid_until'] ?? null,
            'usage_limit_total' => isset($promotion['usage_limit_total']) ? (int)$promotion['usage_limit_total'] : 0,
            'usage_limit_per_guest' => isset($promotion['usage_limit_per_guest']) ? (int)$promotion['usage_limit_per_guest'] : 0,
            'used_count' => isset($promotion['used_count']) ? (int)$promotion['used_count'] : 0,
        ];
    }
    if (is_array($preview)) {
        $response['preview'] = [
            'subtotal' => (float)($preview['subtotal'] ?? $orderTotal),
            'discount_amount' => (float)($preview['discount_amount'] ?? 0),
            'final_total' => (float)($preview['final_total'] ?? $orderTotal),
            'discount_type' => (string)($preview['discount_type'] ?? ''),
            'discount_value' => (float)($preview['discount_value'] ?? 0),
            'min_order_amount' => (float)($preview['min_order_amount'] ?? 0),
            'guest_usage_count' => (int)($preview['guest_usage_count'] ?? 0),
            'guest_usage_limit' => (int)($preview['guest_usage_limit'] ?? 0),
            'total_used_count' => (int)($preview['total_used_count'] ?? 0),
            'total_usage_limit' => (int)($preview['total_usage_limit'] ?? 0),
        ];
    }

    echo json_encode($response, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if (function_exists('error_log')) {
        error_log('PROMO_VALIDATE_ENDPOINT_FAIL restaurant_id=' . $restaurantId . ' ' . $e->getMessage());
    }
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'internal_error',
    ], JSON_UNESCAPED_UNICODE);
}

