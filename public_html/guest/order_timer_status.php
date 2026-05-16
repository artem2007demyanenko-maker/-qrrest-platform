<?php

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/order_payment_runtime.php';

header('Content-Type: application/json; charset=utf-8');

if (empty($currentRestaurant) || empty($currentRestaurant['id'])) {
    echo json_encode(['success' => false, 'message' => 'Ресторан не найден'], JSON_UNESCAPED_UNICODE);
    exit;
}

$restaurantId = (int)$currentRestaurant['id'];
$tableId = isset($_GET['table_id']) ? (int)$_GET['table_id'] : 0;
$orderId = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;

if ($tableId <= 0 || $orderId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Некорректные параметры'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = db();
if (!$pdo instanceof PDO) {
    echo json_encode(['success' => false, 'message' => 'Нет соединения с БД'], JSON_UNESCAPED_UNICODE);
    exit;
}

order_expire_due_orders($pdo, $restaurantId, $orderId, $tableId);

$paymentTypeSelect = (function_exists('db_column_exists') && db_column_exists('orders', 'payment_type'))
    ? 'payment_type'
    : "'cash' AS payment_type";

$stmt = $pdo->prepare("
    SELECT id, table_id, order_status, payment_status, {$paymentTypeSelect}, created_at
    FROM orders
    WHERE id = :oid
      AND restaurant_id = :rid
      AND table_id = :tid
    LIMIT 1
");
$stmt->execute([':oid' => $orderId, ':rid' => $restaurantId, ':tid' => $tableId]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$order) {
    echo json_encode(['success' => false, 'message' => 'Заказ не найден'], JSON_UNESCAPED_UNICODE);
    exit;
}

$paymentStatus = (string)($order['payment_status'] ?? 'unpaid');
$paymentType = (string)($order['payment_type'] ?? 'cash');
$countdownMeta = order_payment_timer_meta($order);
$countdownActive = (bool)$countdownMeta['active'];
$countdownExpired = (bool)$countdownMeta['expired'];
$secondsRemaining = $countdownMeta['seconds_remaining'];
$countdownMmss = $countdownMeta['mmss'];

echo json_encode([
    'success' => true,
    'order_id' => (int)$orderId,
    'order_status' => (string)($order['order_status'] ?? ''),
    'payment_type' => $paymentType,
    'payment_status' => $paymentStatus,
    'countdown_active' => $countdownActive,
    'countdown_expired' => $countdownExpired,
    'countdown_seconds_remaining' => $secondsRemaining,
    'countdown_mmss' => $countdownMmss,
], JSON_UNESCAPED_UNICODE);
