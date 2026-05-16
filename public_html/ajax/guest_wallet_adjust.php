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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'method_not_allowed',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

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

$csrfOk = isset($_POST['csrf'], $_SESSION['csrf'])
    && is_string($_POST['csrf'])
    && is_string($_SESSION['csrf'] ?? null)
    && hash_equals((string)$_SESSION['csrf'], (string)$_POST['csrf']);
if (!$csrfOk) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => 'invalid_csrf',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$readPost = static function (string $key, $default = null) {
    return array_key_exists($key, $_POST) ? $_POST[$key] : $default;
};

$guestProfileId = (int)$readPost('guest_profile_id', 0);
$phoneRaw = trim((string)$readPost('phone', $readPost('phone_normalized', '')));
$operationRaw = trim((string)$readPost('operation', ''));
$points = (int)$readPost('points', 0);
$orderId = (int)$readPost('order_id', 0);
$note = trim((string)$readPost('note', ''));
$adjustDirection = strtolower(trim((string)$readPost('adjust_direction', 'plus')));

if ($guestProfileId <= 0 && $phoneRaw === '') {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => 'guest_identity_required',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($points <= 0) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => 'points_must_be_positive',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($points > 1000000) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => 'points_too_large',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$operation = function_exists('loyalty_wallet_operation_normalize')
    ? loyalty_wallet_operation_normalize($operationRaw, $points)
    : strtolower($operationRaw);
$allowedOps = ['earn', 'spend', 'manual_adjustment', 'refund'];
if (!in_array($operation, $allowedOps, true)) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => 'invalid_operation',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$signedPoints = $points;
if ($operation === 'manual_adjustment' && $adjustDirection === 'minus') {
    $signedPoints = -$points;
}

$pdo = db();
if (function_exists('runtime_schema_ensure_guest_profiles')) {
    runtime_schema_ensure_guest_profiles($pdo);
}

$me = function_exists('auth_user') ? auth_user() : null;
$staffUserId = is_array($me) ? (int)($me['id'] ?? 0) : 0;

try {
    if (!function_exists('guest_history_resolve_phone') || !function_exists('loyalty_wallet_adjust') || !function_exists('loyalty_guest_balance') || !function_exists('loyalty_guest_ledger')) {
        throw new RuntimeException('wallet_write_helpers_missing');
    }

    $resolved = guest_history_resolve_phone($pdo, $restaurantId, [
        'guest_profile_id' => $guestProfileId,
        'phone' => $phoneRaw,
        'phone_normalized' => trim((string)$readPost('phone_normalized', '')),
        'guest_phone' => trim((string)$readPost('guest_phone', '')),
    ]);
    if (!is_array($resolved)) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'guest_not_found',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($orderId > 0) {
        $stmtOrder = $pdo->prepare("
            SELECT 1
            FROM orders
            WHERE id = :order_id
              AND restaurant_id = :restaurant_id
            LIMIT 1
        ");
        $stmtOrder->execute([
            ':order_id' => $orderId,
            ':restaurant_id' => $restaurantId,
        ]);
        if (!$stmtOrder->fetchColumn()) {
            http_response_code(422);
            echo json_encode([
                'success' => false,
                'message' => 'order_not_found',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    $walletBefore = loyalty_guest_balance($pdo, $restaurantId, $resolved);
    if ($operation === 'spend' && function_exists('loyalty_wallet_can_spend') && !loyalty_wallet_can_spend($walletBefore, $points)) {
        echo json_encode([
            'success' => false,
            'message' => 'insufficient_balance',
            'wallet' => $walletBefore,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($operation === 'manual_adjustment' && $signedPoints < 0 && function_exists('loyalty_wallet_can_spend') && !loyalty_wallet_can_spend($walletBefore, abs($signedPoints))) {
        echo json_encode([
            'success' => false,
            'message' => 'insufficient_balance',
            'wallet' => $walletBefore,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $adjust = loyalty_wallet_adjust(
        $pdo,
        $restaurantId,
        $resolved,
        $operation,
        $signedPoints,
        [
            'order_id' => $orderId > 0 ? $orderId : null,
            'staff_user_id' => $staffUserId > 0 ? $staffUserId : null,
            'note' => $note !== '' ? $note : null,
        ]
    );
    if (empty($adjust['ok'])) {
        echo json_encode([
            'success' => false,
            'message' => (string)($adjust['error'] ?? 'wallet_adjust_failed'),
            'source' => (string)($adjust['source'] ?? ''),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $wallet = loyalty_guest_balance($pdo, $restaurantId, $resolved);
    $ledger = loyalty_guest_ledger($pdo, $restaurantId, $resolved, 5);

    echo json_encode([
        'success' => true,
        'message' => 'ok',
        'operation' => $operation,
        'points' => $signedPoints,
        'wallet' => $wallet,
        'ledger' => $ledger,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('GUEST_WALLET_ADJUST_FAIL restaurant_id=' . $restaurantId . ' ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'internal_error',
    ], JSON_UNESCAPED_UNICODE);
}
