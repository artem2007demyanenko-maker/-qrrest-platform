<?php
/**
 * Returns feedback eligibility + fresh token only for trusted order_track session context.
 * GET: table_id, order_id
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

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    echo json_encode(['success' => false, 'message' => 'Некорректный метод запроса.']);
    exit;
}

$orderIdRaw = trim((string)($_GET['order_id'] ?? ''));
$tableIdRaw = trim((string)($_GET['table_id'] ?? ''));
$orderId = (ctype_digit($orderIdRaw) && $orderIdRaw !== '') ? (int)$orderIdRaw : 0;
$tableId = (ctype_digit($tableIdRaw) && $tableIdRaw !== '') ? (int)$tableIdRaw : 0;

if ($orderId <= 0 || $tableId <= 0) {
    echo json_encode(['success' => false, 'eligible' => false, 'feedback_exists' => false, 'stop_feedback_retry' => true, 'message' => 'Некорректные параметры.']);
    exit;
}

if (!function_exists('db_table_exists') || !db_table_exists('orders')) {
    echo json_encode(['success' => false, 'eligible' => false, 'feedback_exists' => false, 'message' => 'Форма отзывов временно недоступна.']);
    exit;
}

$pdo = db();
if (function_exists('runtime_schema_ensure_guest_reviews')) {
    runtime_schema_ensure_guest_reviews($pdo);
}

try {
    $stmt = $pdo->prepare('SELECT id, restaurant_id, table_id, order_status FROM orders WHERE id = :oid LIMIT 1');
    $stmt->execute([':oid' => $orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        echo json_encode(['success' => false, 'eligible' => false, 'feedback_exists' => false, 'stop_feedback_retry' => true, 'message' => 'Заказ не найден.']);
        exit;
    }

    $restaurantId = (int)($order['restaurant_id'] ?? 0);
    $orderTableId = (int)($order['table_id'] ?? 0);
    if ($restaurantId <= 0 || $orderTableId !== $tableId) {
        echo json_encode(['success' => false, 'eligible' => false, 'feedback_exists' => false, 'stop_feedback_retry' => true, 'message' => 'Контекст заказа не совпадает.']);
        exit;
    }

    if ($currentRestaurant && (int)($currentRestaurant['id'] ?? 0) > 0) {
        if ((int)$currentRestaurant['id'] !== $restaurantId) {
            echo json_encode(['success' => false, 'eligible' => false, 'feedback_exists' => false, 'stop_feedback_retry' => true, 'message' => 'Контекст ресторана не совпадает.']);
            exit;
        }
    }

    if (!order_feedback_track_context_valid($restaurantId, $tableId, $orderId)) {
        echo json_encode(['success' => true, 'eligible' => false, 'feedback_exists' => false, 'stop_feedback_retry' => true, 'message' => '']);
        exit;
    }

    if (function_exists('is_demo_mode') && is_demo_mode()) {
        $delivered = !function_exists('db_column_exists') || !db_column_exists('orders', 'order_status')
            || (($order['order_status'] ?? '') === 'delivered');
        if (!$delivered) {
            echo json_encode(['success' => true, 'eligible' => false, 'feedback_exists' => false]);
            exit;
        }
        $token = order_feedback_mint_token($orderId);
        echo json_encode(['success' => true, 'eligible' => true, 'feedback_exists' => false, 'feedback_token' => $token]);
        exit;
    }

    $hasLegacyFeedback = function_exists('db_table_exists') && db_table_exists('order_feedback');
    $hasGuestReviews = function_exists('db_table_exists') && db_table_exists('guest_reviews');
    if (!$hasLegacyFeedback && !$hasGuestReviews) {
        echo json_encode(['success' => false, 'eligible' => false, 'feedback_exists' => false, 'message' => 'Форма отзывов временно недоступна.']);
        exit;
    }

    $feedbackExists = false;
    if ($hasGuestReviews) {
        $fbStmt = $pdo->prepare('SELECT id FROM guest_reviews WHERE order_id = :oid LIMIT 1');
        $fbStmt->execute([':oid' => $orderId]);
        $feedbackExists = (bool)$fbStmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$feedbackExists && $hasLegacyFeedback) {
        $fbStmt = $pdo->prepare('SELECT id FROM order_feedback WHERE order_id = :oid LIMIT 1');
        $fbStmt->execute([':oid' => $orderId]);
        $feedbackExists = (bool)$fbStmt->fetch(PDO::FETCH_ASSOC);
    }
    if ($feedbackExists) {
        echo json_encode(['success' => true, 'eligible' => false, 'feedback_exists' => true, 'stop_feedback_retry' => true]);
        exit;
    }

    if (function_exists('db_column_exists') && db_column_exists('orders', 'order_status')) {
        $status = mb_strtolower(trim((string)($order['order_status'] ?? '')), 'UTF-8');
        if (!in_array($status, ['delivered', 'completed'], true)) {
            echo json_encode(['success' => true, 'eligible' => false, 'feedback_exists' => false]);
            exit;
        }
    }

    $token = order_feedback_mint_token($orderId);
    echo json_encode([
        'success' => true,
        'eligible' => true,
        'feedback_exists' => false,
        'feedback_token' => $token,
    ]);
} catch (Throwable $e) {
    error_log('order_feedback_state: ' . $e->getMessage());
    echo json_encode(['success' => false, 'eligible' => false, 'feedback_exists' => false, 'message' => 'Не удалось сохранить отзыв. Попробуйте позже.']);
}
