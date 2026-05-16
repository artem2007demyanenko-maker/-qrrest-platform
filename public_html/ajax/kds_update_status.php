<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/order_payment_runtime.php';
require_once __DIR__ . '/../../app/guest_order_loyalty_attach.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!function_exists('require_kitchen_access')) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'auth_unavailable'], JSON_UNESCAPED_UNICODE);
    exit;
}
require_kitchen_access();
if (!$currentRestaurant) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Restaurant context required'], JSON_UNESCAPED_UNICODE);
    exit;
}
$restaurantId = (int)($currentRestaurant['id'] ?? 0);
$staffRole = function_exists('current_user_restaurant_role')
    ? normalize_restaurant_role((string)(current_user_restaurant_role($restaurantId) ?? ''))
    : '';
if ($staffRole === 'bar' || (
    function_exists('restaurant_role_is_station_role')
    && restaurant_role_is_station_role($staffRole)
    && $staffRole !== 'kitchen'
)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'station_access_denied'], JSON_UNESCAPED_UNICODE);
    exit;
}

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
$restId = $restaurantId;

function kds_orders_sync_loyalty_safe(PDO $pdo, array $restaurantRow, int $orderId): void
{
    if (!function_exists('guest_order_loyalty_sync')) {
        return;
    }

    try {
        $res = guest_order_loyalty_sync($pdo, $restaurantRow, $orderId, null);
        if (!is_array($res) || empty($res['ok'])) {
            error_log('kds_update_status loyalty_sync order_id=' . $orderId . ' error=' . (string)($res['error'] ?? 'unknown'));
        }
    } catch (Throwable $e) {
        error_log('kds_update_status loyalty_sync order_id=' . $orderId . ' ' . $e->getMessage());
    }
}

try {
    order_expire_due_orders($pdo, $restId, $orderId);

    $stmt = $pdo->prepare("SELECT id, order_status FROM orders WHERE id = :id AND restaurant_id = :rest LIMIT 1");
    $stmt->execute([':id' => $orderId, ':rest' => $restId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        echo json_encode(['success' => false, 'message' => 'Заказ не найден'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $old = (string)($order['order_status'] ?? '');
    if (strtolower($old) === 'canceled' && $newDbStatus !== 'canceled') {
        echo json_encode(['success' => false, 'message' => 'Заказ уже отменён по таймауту'], JSON_UNESCAPED_UNICODE);
        exit;
    }
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

    kds_orders_sync_loyalty_safe($pdo, $currentRestaurant, $orderId);

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
