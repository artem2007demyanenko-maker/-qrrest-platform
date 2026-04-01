<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_login();

if (!$currentRestaurant) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Restaurant context required'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_restaurant_role((int)$currentRestaurant['id'], ['staff', 'admin', 'owner']);

if (function_exists('is_demo_mode') && is_demo_mode()) {
    echo json_encode(['success' => false, 'message' => 'В демо-режиме статус не изменяется'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!function_exists('db')) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'DB unavailable'], JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = file_get_contents('php://input');
$json = json_decode((string)$raw, true);
$payload = is_array($json) ? $json : $_POST;

$orderId = (int)($payload['order_id'] ?? 0);
$statusIn = trim((string)($payload['status'] ?? ''));
if ($orderId <= 0 || $statusIn === '') {
    echo json_encode(['success' => false, 'message' => 'Некорректные параметры'], JSON_UNESCAPED_UNICODE);
    exit;
}

// KDS canonical statuses; completed maps to existing delivered status.
$statusMap = [
    'new' => 'new',
    'accepted' => 'accepted',
    'cooking' => 'cooking',
    'ready' => 'ready',
    'completed' => 'delivered',
];
if (!isset($statusMap[$statusIn])) {
    echo json_encode(['success' => false, 'message' => 'Недопустимый статус'], JSON_UNESCAPED_UNICODE);
    exit;
}
$newDbStatus = $statusMap[$statusIn];

$pdo = db();
$restId = (int)$currentRestaurant['id'];

try {
    $stmt = $pdo->prepare("SELECT id, order_status FROM orders WHERE id = :id AND restaurant_id = :rest LIMIT 1");
    $stmt->execute([':id' => $orderId, ':rest' => $restId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        echo json_encode(['success' => false, 'message' => 'Заказ не найден'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $old = (string)($order['order_status'] ?? '');
    // Restrict transitions to the safe kitchen flow only:
    // new -> accepted
    // accepted -> cooking
    // cooking -> ready
    // ready -> delivered (completed)
    $allowedTransition = [
        'new' => ['accepted'],
        'accepted' => ['cooking'],
        'cooking' => ['ready'],
        'ready' => ['delivered'],
        'delivered' => [],
        'canceled' => [],
    ];
    $nexts = $allowedTransition[$old] ?? [];
    if ($old !== $newDbStatus && !in_array($newDbStatus, $nexts, true)) {
        echo json_encode(['success' => false, 'message' => 'Недопустимый переход статуса'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($old !== $newDbStatus) {
        $upd = $pdo->prepare("UPDATE orders SET order_status = :st WHERE id = :id AND restaurant_id = :rest");
        $upd->execute([':st' => $newDbStatus, ':id' => $orderId, ':rest' => $restId]);
    }

    // Keep existing CRM finalization behavior on status updates.
    if (file_exists(__DIR__ . '/../../app/crm_repo.php')) {
        require_once __DIR__ . '/../../app/crm_repo.php';
        if (function_exists('crm_finalize_order_visit')) {
            try {
                crm_finalize_order_visit($restId, $orderId);
            } catch (Throwable $e) {
                error_log('kds crm_finalize_order_visit order_id=' . $orderId . ' ' . $e->getMessage());
            }
        }
    }

    echo json_encode([
        'success' => true,
        'status' => $statusIn,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('kds_update_status rest_id=' . $restId . ' order_id=' . $orderId . ' ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Ошибка обновления статуса'], JSON_UNESCAPED_UNICODE);
}

