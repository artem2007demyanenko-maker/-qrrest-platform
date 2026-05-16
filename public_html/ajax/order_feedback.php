<?php
/**
 * Guest feedback after order. POST: order_id, table_id, rating, comment, feedback_token.
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
if (function_exists('runtime_schema_ensure_guest_reviews')) {
    runtime_schema_ensure_guest_reviews($pdo);
}

$orderIdRaw = trim((string)($_POST['order_id'] ?? ''));
$tableIdRaw = trim((string)($_POST['table_id'] ?? ''));
$ratingRaw = trim((string)($_POST['rating'] ?? ''));
$feedbackToken = trim((string)($_POST['feedback_token'] ?? ''));
$comment = trim((string)($_POST['comment'] ?? ''));
$orderId = (ctype_digit($orderIdRaw) && $orderIdRaw !== '') ? (int)$orderIdRaw : 0;
$tableId = (ctype_digit($tableIdRaw) && $tableIdRaw !== '') ? (int)$tableIdRaw : 0;
$rating = (ctype_digit($ratingRaw) && $ratingRaw !== '') ? (int)$ratingRaw : 0;

if ($orderId <= 0 || $tableId <= 0 || $feedbackToken === '') {
    echo json_encode(['success' => false, 'message' => 'Некорректные данные отзыва.']);
    exit;
}
if ($rating < 1 || $rating > 5) {
    echo json_encode(['success' => false, 'message' => 'Оценка должна быть от 1 до 5.']);
    exit;
}

$comment = mb_substr($comment, 0, 2000);

if (!function_exists('db_table_exists') || !db_table_exists('orders')) {
    echo json_encode(['success' => false, 'message' => 'Форма отзывов временно недоступна.']);
    exit;
}
$hasLegacyFeedback = db_table_exists('order_feedback');
$hasGuestReviews = db_table_exists('guest_reviews');
if (!$hasLegacyFeedback && !$hasGuestReviews) {
    echo json_encode(['success' => false, 'message' => 'Форма отзывов временно недоступна.']);
    exit;
}

try {
    $sessionTokenKey = 'order_feedback_token_' . $orderId;
    $sessionToken = (string)($_SESSION[$sessionTokenKey] ?? '');
    if ($sessionToken === '' || !hash_equals($sessionToken, $feedbackToken)) {
        echo json_encode(['success' => false, 'message' => 'Сессия отзыва устарела. Обновите страницу.']);
        exit;
    }

    $stmt = $pdo->prepare('SELECT id, restaurant_id, table_id, order_status FROM orders WHERE id = :oid LIMIT 1');
    $stmt->execute([':oid' => $orderId]);
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
    if ($orderTableId !== $tableId) {
        echo json_encode(['success' => false, 'message' => 'Контекст заказа не совпадает.']);
        exit;
    }

    if ($currentRestaurant && (int)($currentRestaurant['id'] ?? 0) > 0) {
        if ((int)$currentRestaurant['id'] !== $restaurantId) {
            echo json_encode(['success' => false, 'message' => 'Контекст ресторана не совпадает.']);
            exit;
        }
    }

    if (!order_feedback_track_context_valid($restaurantId, $tableId, $orderId)) {
        echo json_encode(['success' => false, 'message' => 'Контекст заказа недействителен.']);
        exit;
    }

    if (function_exists('db_column_exists') && db_column_exists('orders', 'order_status')) {
        $status = mb_strtolower(trim((string)($order['order_status'] ?? '')), 'UTF-8');
        if (!in_array($status, ['delivered', 'completed'], true)) {
            echo json_encode(['success' => false, 'message' => 'Отзыв доступен после получения заказа.']);
            exit;
        }
    }

    if (function_exists('is_demo_mode') && is_demo_mode()) {
        unset($_SESSION[$sessionTokenKey]);
        echo json_encode(['success' => true]);
        exit;
    }

    $alreadyExists = false;
    if ($hasGuestReviews) {
        $check = $pdo->prepare('SELECT id FROM guest_reviews WHERE order_id = :oid LIMIT 1');
        $check->execute([':oid' => $orderId]);
        $alreadyExists = (bool)$check->fetch(PDO::FETCH_ASSOC);
    }
    if (!$alreadyExists && $hasLegacyFeedback) {
        $check = $pdo->prepare('SELECT id FROM order_feedback WHERE order_id = :oid LIMIT 1');
        $check->execute([':oid' => $orderId]);
        $alreadyExists = (bool)$check->fetch(PDO::FETCH_ASSOC);
    }
    if ($alreadyExists) {
        echo json_encode(['success' => true]);
        exit;
    }

    if ($hasLegacyFeedback) {
        $stmt = $pdo->prepare('
            INSERT INTO order_feedback (order_id, restaurant_id, rating, comment, created_at)
            VALUES (:oid, :rest, :rating, :comment, NOW())
        ');
        $stmt->execute([
            ':oid' => $orderId,
            ':rest' => $restaurantId,
            ':rating' => $rating,
            ':comment' => $comment === '' ? null : $comment,
        ]);
    }
    unset($_SESSION[$sessionTokenKey]);
} catch (Throwable $e) {
    $msg = $e->getMessage();
    $isDuplicate = false;
    if ($e instanceof PDOException) {
        $sqlState = (string)($e->getCode() ?? '');
        $isDuplicate = ($sqlState === '23000') || strpos($msg, 'Duplicate entry') !== false;
    } else {
        $isDuplicate = strpos($msg, 'Duplicate entry') !== false;
    }
    if ($isDuplicate) {
        echo json_encode(['success' => true]);
        exit;
    }
    error_log('order_feedback insert: ' . $msg);
    echo json_encode(['success' => false, 'message' => 'Не удалось сохранить отзыв. Попробуйте позже.']);
    exit;
}

echo json_encode(['success' => true]);
