<?php

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/runtime_schema_bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$staffRole = function_exists('require_courier_access')
    ? require_courier_access()
    : require_staff_role(['owner', 'admin', 'staff', 'courier']);
$currentUser = auth_user();
$currentUserId = (int)($currentUser['id'] ?? 0);
$isCourierOnly = ($staffRole === 'courier');
$canManageAll = in_array($staffRole, ['owner', 'admin', 'staff'], true);

if (!$currentRestaurant || (int)($currentRestaurant['id'] ?? 0) <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'restaurant_context_required'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = db();
if (function_exists('runtime_schema_ensure_orders_order_type')) {
    runtime_schema_ensure_orders_order_type($pdo);
}
if (function_exists('runtime_schema_ensure_orders_courier_meta')) {
    runtime_schema_ensure_orders_courier_meta($pdo);
}
if (function_exists('runtime_schema_ensure_courier_locations')) {
    runtime_schema_ensure_courier_locations($pdo);
}

$rawBody = file_get_contents('php://input');
$jsonBody = json_decode((string)$rawBody, true);
if (!is_array($jsonBody)) {
    $jsonBody = [];
}
$input = static function (string $key, $default = null) use ($jsonBody) {
    if (array_key_exists($key, $jsonBody)) {
        return $jsonBody[$key];
    }
    if (array_key_exists($key, $_POST)) {
        return $_POST[$key];
    }
    if (array_key_exists($key, $_GET)) {
        return $_GET[$key];
    }
    return $default;
};

$orderId = (int)$input('order_id', 0);
$lat = $input('lat', null);
$lng = $input('lng', null);
$accuracy = $input('accuracy', null);
$speed = $input('speed', null);
$heading = $input('heading', null);
$batteryLevel = $input('battery_level', null);

if ($orderId <= 0 || $lat === null || $lng === null) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'invalid_payload'], JSON_UNESCAPED_UNICODE);
    exit;
}

$restaurantId = (int)$currentRestaurant['id'];

$hasOrderTypeCol = function_exists('db_column_exists') && db_column_exists('orders', 'order_type');
$hasCourierUserIdCol = function_exists('db_column_exists') && db_column_exists('orders', 'courier_user_id');
$hasCourierStatusCol = function_exists('db_column_exists') && db_column_exists('orders', 'courier_status');

if (!$hasOrderTypeCol) {
    http_response_code(409);
    echo json_encode(['success' => false, 'error' => 'order_type_missing'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $selectCols = [
        'id',
        'restaurant_id',
        'table_id',
        'order_type',
        'order_status',
    ];
    if ($hasCourierUserIdCol) {
        $selectCols[] = 'courier_user_id';
    } else {
        $selectCols[] = "NULL AS courier_user_id";
    }
    if ($hasCourierStatusCol) {
        $selectCols[] = 'courier_status';
    } else {
        $selectCols[] = "NULL AS courier_status";
    }

    $stmtOrder = $pdo->prepare("
        SELECT " . implode(', ', $selectCols) . "
        FROM orders
        WHERE id = :id AND restaurant_id = :rest
        LIMIT 1
    ");
    $stmtOrder->execute([
        ':id' => $orderId,
        ':rest' => $restaurantId,
    ]);
    $order = $stmtOrder->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$order) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'order_not_found'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $orderType = function_exists('order_type_normalize')
        ? order_type_normalize((string)($order['order_type'] ?? ''), (int)($order['table_id'] ?? 0))
        : 'hall';
    if ($orderType !== 'delivery') {
        http_response_code(409);
        echo json_encode(['success' => false, 'error' => 'not_delivery_order'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $assignedCourierId = (int)($order['courier_user_id'] ?? 0);
    if ($isCourierOnly) {
        if ($assignedCourierId <= 0 || $assignedCourierId !== $currentUserId) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'courier_not_assigned'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    } elseif (!$canManageAll && $assignedCourierId > 0 && $assignedCourierId !== $currentUserId) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $courierUserId = $assignedCourierId > 0 ? $assignedCourierId : $currentUserId;
    if ($courierUserId <= 0) {
        http_response_code(409);
        echo json_encode(['success' => false, 'error' => 'courier_identity_missing'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $storeRes = function_exists('courier_location_store')
        ? courier_location_store($pdo, $restaurantId, $orderId, $courierUserId, [
            'lat' => $lat,
            'lng' => $lng,
            'accuracy' => $accuracy,
            'speed' => $speed,
            'heading' => $heading,
            'battery_level' => $batteryLevel,
            'min_interval_sec' => 5,
        ])
        : ['ok' => false, 'reason' => 'helper_missing', 'stored' => false, 'throttled' => false, 'updated_at' => null];

    if (empty($storeRes['ok'])) {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => (string)($storeRes['reason'] ?? 'store_failed')], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $latest = function_exists('courier_location_latest')
        ? courier_location_latest($pdo, $restaurantId, $orderId, $courierUserId)
        : null;
    $publicPayload = function_exists('courier_location_public_payload')
        ? courier_location_public_payload($latest, ['live_sec' => 20, 'stale_sec' => 90])
        : ['has_location' => false, 'state' => 'offline', 'label' => 'Позиция недоступна'];

    echo json_encode([
        'success' => true,
        'order_id' => $orderId,
        'courier_user_id' => $courierUserId,
        'stored' => (bool)($storeRes['stored'] ?? false),
        'throttled' => (bool)($storeRes['throttled'] ?? false),
        'location' => $publicPayload,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('COURIER_LOCATION_UPDATE_FAIL rid=' . $restaurantId . ' order_id=' . $orderId . ' ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'internal_error'], JSON_UNESCAPED_UNICODE);
}

