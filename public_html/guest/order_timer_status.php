<?php

require_once __DIR__ . '/../../app/bootstrap.php';

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

$stmt = $pdo->prepare("
    SELECT id, table_id, order_status, payment_status, payment_type, created_at
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
$createdTs = strtotime((string)($order['created_at'] ?? ''));
$now = time();

$offlineTypes = ['cash', 'card_later', 'pay_later'];
$apply = ($paymentStatus === 'unpaid') && in_array($paymentType, $offlineTypes, true);

$countdownActive = false;
$countdownExpired = false;
$secondsRemaining = null;
$countdownMmss = null;

if ($apply && $createdTs) {
    $elapsedSeconds = max(0, $now - $createdTs);
    $remaining = (10 * 60) - $elapsedSeconds;
    $secondsRemaining = max(0, (int)$remaining);
    $countdownExpired = $remaining <= 0;
    $countdownActive = !$countdownExpired;
    $countdownMmss = gmdate('i:s', $secondsRemaining);
}

echo json_encode([
    'success' => true,
    'order_id' => (int)$orderId,
    'payment_type' => $paymentType,
    'payment_status' => $paymentStatus,
    'countdown_active' => $countdownActive,
    'countdown_expired' => $countdownExpired,
    'countdown_seconds_remaining' => $secondsRemaining,
    'countdown_mmss' => $countdownMmss,
], JSON_UNESCAPED_UNICODE);

