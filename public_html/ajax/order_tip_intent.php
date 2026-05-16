<?php
/**
 * Guest tip intent endpoint (no acquiring yet).
 * POST: order_id, table_id, amount, target, note, currency, status, tip_token
 */

require_once __DIR__ . '/../../app/bootstrap.php';
if (file_exists(__DIR__ . '/../../app/schema_guard.php')) {
    require_once __DIR__ . '/../../app/schema_guard.php';
}
if (file_exists(__DIR__ . '/../../app/runtime_schema_bootstrap.php')) {
    require_once __DIR__ . '/../../app/runtime_schema_bootstrap.php';
}
require_once __DIR__ . '/../../app/order_feedback_guard.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Некорректный метод запроса.']);
    exit;
}

$pdo = db();
if (function_exists('runtime_schema_ensure_order_tips')) {
    runtime_schema_ensure_order_tips($pdo);
}
if (function_exists('runtime_schema_ensure_guest_profiles')) {
    runtime_schema_ensure_guest_profiles($pdo);
}

$orderIdRaw = trim((string)($_POST['order_id'] ?? ''));
$tableIdRaw = trim((string)($_POST['table_id'] ?? ''));
$amountRaw = trim((string)($_POST['amount'] ?? ''));
$targetRaw = trim((string)($_POST['target'] ?? ''));
$noteRaw = trim((string)($_POST['note'] ?? ''));
$currencyRaw = trim((string)($_POST['currency'] ?? 'RUB'));
$statusRaw = trim((string)($_POST['status'] ?? 'pending'));
$tipToken = trim((string)($_POST['tip_token'] ?? ''));
$orderId = (ctype_digit($orderIdRaw) && $orderIdRaw !== '') ? (int)$orderIdRaw : 0;
$tableId = (ctype_digit($tableIdRaw) && $tableIdRaw !== '') ? (int)$tableIdRaw : 0;

if ($orderId <= 0 || $tipToken === '') {
    echo json_encode(['success' => false, 'message' => 'Некорректные данные чаевых.']);
    exit;
}

$amountNormalized = str_replace(',', '.', preg_replace('/[^\d,\.\-]+/u', '', $amountRaw));
$amount = is_numeric($amountNormalized) ? (float)$amountNormalized : 0.0;
if (!is_finite($amount) || $amount <= 0) {
    echo json_encode(['success' => false, 'message' => 'Сумма чаевых должна быть больше нуля.']);
    exit;
}

if (!function_exists('db_table_exists') || !db_table_exists('orders')) {
    echo json_encode(['success' => false, 'message' => 'Сервис чаевых временно недоступен.']);
    exit;
}
if (!function_exists('db_table_exists') || !db_table_exists('order_tips')) {
    echo json_encode(['success' => false, 'message' => 'Сервис чаевых временно недоступен.']);
    exit;
}

try {
    $sessionTokenKey = 'order_tip_token_' . $orderId;
    $sessionToken = (string)($_SESSION[$sessionTokenKey] ?? '');
    if ($sessionToken === '' || !hash_equals($sessionToken, $tipToken)) {
        echo json_encode(['success' => false, 'message' => 'Сессия чаевых устарела. Обновите страницу.']);
        exit;
    }

    $selectCols = [
        'id',
        'restaurant_id',
        'table_id',
        'order_status',
    ];
    foreach (['order_type', 'courier_user_id', 'customer_phone', 'delivery_phone', 'guest_phone', 'loyalty_phone'] as $col) {
        if (function_exists('db_column_exists') && db_column_exists('orders', $col)) {
            $selectCols[] = $col;
        }
    }
    foreach (['staff_user_id', 'waiter_user_id', 'assigned_waiter_id', 'user_id', 'created_by_user_id'] as $candidate) {
        if (function_exists('db_column_exists') && db_column_exists('orders', $candidate)) {
            $selectCols[] = $candidate;
        }
    }

    $stmt = $pdo->prepare('SELECT ' . implode(', ', array_unique($selectCols)) . ' FROM orders WHERE id = :order_id LIMIT 1');
    $stmt->execute([':order_id' => $orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        echo json_encode(['success' => false, 'message' => 'Заказ не найден.']);
        exit;
    }

    $restaurantId = (int)($order['restaurant_id'] ?? 0);
    $orderTableId = (int)($order['table_id'] ?? 0);
    if ($restaurantId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Заказ не найден.']);
        exit;
    }
    if ($tableId > 0 && $orderTableId > 0 && $tableId !== $orderTableId) {
        echo json_encode(['success' => false, 'message' => 'Контекст заказа не совпадает.']);
        exit;
    }

    if ($currentRestaurant && (int)($currentRestaurant['id'] ?? 0) > 0 && (int)$currentRestaurant['id'] !== $restaurantId) {
        echo json_encode(['success' => false, 'message' => 'Контекст ресторана не совпадает.']);
        exit;
    }

    if (!order_feedback_track_context_valid($restaurantId, $orderTableId, $orderId)) {
        echo json_encode(['success' => false, 'message' => 'Контекст заказа недействителен.']);
        exit;
    }

    $status = mb_strtolower(trim((string)($order['order_status'] ?? '')), 'UTF-8');
    if (!in_array($status, ['completed', 'delivered'], true)) {
        echo json_encode(['success' => false, 'message' => 'Чаевые доступны после завершения заказа.']);
        exit;
    }

    if (!function_exists('order_tip_create')) {
        throw new RuntimeException('order_tip_helper_missing');
    }

    $tipPayload = $order;
    $tipPayload['amount'] = $amount;
    $tipPayload['target'] = $targetRaw;
    $tipPayload['note'] = $noteRaw;
    $tipPayload['currency'] = $currencyRaw;
    $tipPayload['status'] = $statusRaw !== '' ? $statusRaw : 'pending';
    $tipPayload['source'] = 'order_track';

    if (function_exists('guest_history_resolve_phone')) {
        try {
            $resolvedGuest = guest_history_resolve_phone($pdo, $restaurantId, [
                'customer_phone' => (string)($order['customer_phone'] ?? ''),
                'delivery_phone' => (string)($order['delivery_phone'] ?? ''),
                'guest_phone' => (string)($order['guest_phone'] ?? ''),
                'loyalty_phone' => (string)($order['loyalty_phone'] ?? ''),
            ]);
            if (is_array($resolvedGuest)) {
                $tipPayload['guest_profile_id'] = (int)($resolvedGuest['guest_profile_id'] ?? 0);
                $tipPayload['phone_normalized'] = (string)($resolvedGuest['phone_normalized'] ?? '');
            }
        } catch (Throwable $e) {
            // graceful fallback
        }
    }

    $res = order_tip_create($pdo, $restaurantId, $orderId, $tipPayload);
    if (empty($res['ok'])) {
        echo json_encode(['success' => false, 'message' => 'Не удалось сохранить чаевые. Попробуйте позже.']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'tip_id' => (int)($res['tip_id'] ?? 0),
        'created' => !empty($res['created']),
        'updated' => !empty($res['updated']),
        'status' => (string)($res['status'] ?? 'pending'),
        'message' => 'Чаевые зафиксированы. Оплата будет доступна в следующем этапе.',
    ]);
} catch (Throwable $e) {
    error_log('ORDER_TIP_INTENT_FAIL order_id=' . $orderId . ' ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Не удалось сохранить чаевые. Попробуйте позже.']);
}

