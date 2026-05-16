<?php
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/order_payment_runtime.php';

$rid = bin2hex(random_bytes(4));

set_exception_handler(function (Throwable $e) use ($rid) {
    error_log('PUBLIC_PAGE_ERROR rid=' . $rid . ' ' . json_encode([
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
    echo json_encode(['success' => false, 'error' => 'internal_error', 'rid' => $rid]);
    exit;
});

$pdo = db();

if (file_exists(__DIR__ . '/../../app/schema_guard.php')) {
    require_once __DIR__ . '/../../app/schema_guard.php';
}
$orderPaymentTypeExpr = (function_exists('db_column_exists') && db_column_exists('orders', 'payment_type'))
    ? 'payment_type'
    : "'cash' AS payment_type";
$orderTypeExpr = (function_exists('db_column_exists') && db_column_exists('orders', 'order_type'))
    ? 'order_type'
    : "NULL AS order_type";
$orderCourierStatusExpr = (function_exists('db_column_exists') && db_column_exists('orders', 'courier_status'))
    ? 'courier_status'
    : "NULL AS courier_status";
$orderCourierUserIdExpr = (function_exists('db_column_exists') && db_column_exists('orders', 'courier_user_id'))
    ? 'courier_user_id'
    : "NULL AS courier_user_id";
$orderCourierTakenAtExpr = (function_exists('db_column_exists') && db_column_exists('orders', 'courier_taken_at'))
    ? 'courier_taken_at'
    : "NULL AS courier_taken_at";
$orderCourierOnWayAtExpr = (function_exists('db_column_exists') && db_column_exists('orders', 'courier_on_the_way_at'))
    ? 'courier_on_the_way_at'
    : "NULL AS courier_on_the_way_at";
$orderDeliveredAtExpr = (function_exists('db_column_exists') && db_column_exists('orders', 'delivered_at'))
    ? 'delivered_at'
    : "NULL AS delivered_at";

if (!$currentRestaurant) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'restaurant_context_required']);
    exit;
}


header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$tableId = isset($_GET['table_id']) ? (int)$_GET['table_id'] : 0;
$orderId = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;

if ($tableId <= 0 || $orderId <= 0) {
    echo json_encode(['success' => false, 'error' => 'bad_params']);
    exit;
}

order_expire_due_orders($pdo, (int)$currentRestaurant['id'], $orderId, $tableId);

$stmt = $pdo->prepare("
    SELECT order_status, payment_status, {$orderPaymentTypeExpr}, {$orderTypeExpr}, {$orderCourierStatusExpr}, {$orderCourierUserIdExpr}, {$orderCourierTakenAtExpr}, {$orderCourierOnWayAtExpr}, {$orderDeliveredAtExpr}, table_id, created_at, updated_at
    FROM orders
    WHERE id = :oid
      AND restaurant_id = :rest
      AND table_id = :table
    LIMIT 1
");
$stmt->execute([
    ':oid'   => $orderId,
    ':rest'  => (int)$currentRestaurant['id'],
    ':table' => $tableId,
]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    echo json_encode(['success' => false, 'error' => 'not_found']);
    exit;
}

$orderType = function_exists('order_type_normalize')
    ? order_type_normalize((string)($row['order_type'] ?? ''), (int)($row['table_id'] ?? 0))
    : 'hall';
$courierStatus = function_exists('courier_status_normalize')
    ? courier_status_normalize((string)($row['courier_status'] ?? ''), $orderType)
    : '';
$courierStatusLabel = function_exists('courier_status_label')
    ? courier_status_label($courierStatus, $orderType)
    : '';
$orderTypeLabel = function_exists('order_type_label')
    ? order_type_label((string)($row['order_type'] ?? ''), (int)($row['table_id'] ?? 0))
    : 'Зал';
$guestStatusMeta = function_exists('order_guest_status_meta')
    ? order_guest_status_meta(
        (string)($row['order_status'] ?? 'new'),
        $orderType,
        (string)($row['payment_status'] ?? 'unpaid'),
        [
            'courier_status' => $courierStatus,
            'delivery_ready_await_courier' => ($orderType === 'delivery' && $courierStatus === 'waiting_courier'),
        ]
    )
    : [
        'code' => 'accepted',
        'label' => 'Заказ принят',
        'description' => 'Мы приняли ваш заказ и передали его на кухню.',
        'progress_percent' => 25,
        'step_key' => 'accepted',
        'is_final' => false,
        'tone' => 'info',
    ];
$label = (string)($guestStatusMeta['label'] ?? 'Заказ принят');
$desc = (string)($guestStatusMeta['description'] ?? 'Мы приняли ваш заказ и передали его на кухню.');
$progress = (int)($guestStatusMeta['progress_percent'] ?? 25);
$timingMeta = function_exists('courier_delivery_timing')
    ? courier_delivery_timing([
        'order_type' => $orderType,
        'order_status' => (string)($row['order_status'] ?? 'new'),
        'courier_status' => $courierStatus,
        'created_at' => $row['created_at'] ?? null,
        'courier_taken_at' => $row['courier_taken_at'] ?? null,
        'courier_on_the_way_at' => $row['courier_on_the_way_at'] ?? null,
        'delivered_at' => $row['delivered_at'] ?? null,
        'table_id' => (int)($row['table_id'] ?? 0),
    ])
    : [
        'eta_minutes' => 0,
        'eta_label' => '',
        'timing_state' => 'preparing',
        'timing_progress_percent' => $progress,
        'elapsed_minutes' => 0,
    ];
$locationPayload = [
    'has_location' => false,
    'state' => 'offline',
    'label' => 'Позиция недоступна',
    'last_update_seconds' => null,
    'last_update_human' => '',
];
if ($orderType === 'delivery' && function_exists('courier_location_latest') && function_exists('courier_location_public_payload')) {
    $locRow = courier_location_latest(
        $pdo,
        (int)$currentRestaurant['id'],
        $orderId,
        isset($row['courier_user_id']) ? (int)$row['courier_user_id'] : null
    );
    $locationPayload = courier_location_public_payload($locRow, ['live_sec' => 20, 'stale_sec' => 90]);
}

if ($orderType === 'delivery') {
    $timingProgress = (int)($timingMeta['timing_progress_percent'] ?? 0);
    if ($timingProgress > $progress) {
        $progress = min(100, $timingProgress);
    }
}

$readyItemsCount = 0;
$totalItemsCount = 0;
$partialReady = false;
$progressPercentByItems = null;
$hasKdsStatusCol = function_exists('db_column_exists') && db_column_exists('order_items', 'kds_status');
$hasStationStatusCol = function_exists('db_column_exists') && db_column_exists('order_items', 'station_status');
if ($hasKdsStatusCol || $hasStationStatusCol) {
    if ($hasKdsStatusCol && $hasStationStatusCol) {
        $itemStatusExpr = "LOWER(COALESCE(NULLIF(TRIM(kds_status), ''), NULLIF(TRIM(station_status), ''), 'new'))";
    } elseif ($hasKdsStatusCol) {
        $itemStatusExpr = "LOWER(COALESCE(NULLIF(TRIM(kds_status), ''), 'new'))";
    } else {
        $itemStatusExpr = "LOWER(COALESCE(NULLIF(TRIM(station_status), ''), 'new'))";
    }
    $stmtProg = $pdo->prepare("
        SELECT
            COUNT(*) AS total_cnt,
            SUM(CASE WHEN {$itemStatusExpr} = 'ready' THEN 1 ELSE 0 END) AS ready_cnt
        FROM order_items
        WHERE order_id = :oid
    ");
    $stmtProg->execute([':oid' => $orderId]);
    $pr = $stmtProg->fetch(PDO::FETCH_ASSOC) ?: [];
    $totalItemsCount = (int)($pr['total_cnt'] ?? 0);
    $readyItemsCount = (int)($pr['ready_cnt'] ?? 0);
    $partialReady = $readyItemsCount > 0 && $readyItemsCount < $totalItemsCount;
    if ($totalItemsCount > 0) {
        $progressPercentByItems = (int)floor(($readyItemsCount / $totalItemsCount) * 100);
        $progress = $progressPercentByItems;
    }
}

$countdownMeta = order_payment_timer_meta($row);
$countdownSecondsRemaining = $countdownMeta['seconds_remaining'];
$countdownExpired = $countdownMeta['expired'];
$countdownWarning = $countdownMeta['warning'];
$countdownMmss = $countdownMeta['mmss'];

echo json_encode([
    'success'                  => true,
    'order_id'                 => $orderId,
    'order_status'             => $row['order_status'] ?? 'new',
    'order_status_label'       => $label,
    'order_status_description' => $desc,
    'guest_status_code'        => (string)($guestStatusMeta['code'] ?? 'accepted'),
    'guest_status_label'       => $label,
    'guest_status_description' => $desc,
    'guest_status_step_key'    => (string)($guestStatusMeta['step_key'] ?? 'accepted'),
    'guest_status_tone'        => (string)($guestStatusMeta['tone'] ?? 'info'),
    'is_final'                 => (bool)($guestStatusMeta['is_final'] ?? false),
    'progress'                 => $progress,
    'order_type'               => $orderType,
    'order_type_label'         => $orderTypeLabel,
    'courier_status'           => $courierStatus,
    'courier_status_label'     => $courierStatusLabel,
    'courier_location'         => $locationPayload,
    'courier_location_state'   => (string)($locationPayload['state'] ?? 'offline'),
    'courier_location_label'   => (string)($locationPayload['label'] ?? 'Позиция недоступна'),
    'courier_location_last_update_seconds' => $locationPayload['last_update_seconds'] ?? null,
    'courier_location_last_update_human'   => (string)($locationPayload['last_update_human'] ?? ''),
    'eta_minutes'              => (int)($timingMeta['eta_minutes'] ?? 0),
    'eta_label'                => (string)($timingMeta['eta_label'] ?? ''),
    'timing_state'             => (string)($timingMeta['timing_state'] ?? ''),
    'timing_progress_percent'  => (int)($timingMeta['timing_progress_percent'] ?? 0),
    'timing_elapsed_minutes'   => (int)($timingMeta['elapsed_minutes'] ?? 0),
    'payment_status'           => $row['payment_status'] ?? 'unpaid',
    'payment_type'             => $row['payment_type'] ?? null,
    'ready_items_count'        => $readyItemsCount,
    'total_items_count'        => $totalItemsCount,
    'partial_ready'            => $partialReady,
    'progress_percent_items'   => $progressPercentByItems,
    'countdown_seconds_remaining' => $countdownSecondsRemaining,
    'countdown_expired'        => $countdownExpired,
    'countdown_warning'        => $countdownWarning,
    'countdown_mmss'           => $countdownMmss,
    'created_at'               => $row['created_at'] ?? null,
    'updated_at'               => $row['updated_at'] ?? null,
]);
