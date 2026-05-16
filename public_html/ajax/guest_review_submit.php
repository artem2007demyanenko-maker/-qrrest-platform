<?php
/**
 * Guest review submit endpoint (rating + NPS + optional text/tags).
 * POST: order_id, table_id, rating, nps_score, review_text, review_tags[], feedback_token
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
if (function_exists('runtime_schema_ensure_guest_profiles')) {
    runtime_schema_ensure_guest_profiles($pdo);
}
if (function_exists('runtime_schema_ensure_guest_reviews')) {
    runtime_schema_ensure_guest_reviews($pdo);
}

$orderIdRaw = trim((string)($_POST['order_id'] ?? ''));
$tableIdRaw = trim((string)($_POST['table_id'] ?? ''));
$ratingRaw = trim((string)($_POST['rating'] ?? ''));
$npsScoreRaw = trim((string)($_POST['nps_score'] ?? ''));
$feedbackToken = trim((string)($_POST['feedback_token'] ?? ''));
$reviewText = trim((string)($_POST['review_text'] ?? ($_POST['comment'] ?? '')));
$sourceRaw = trim((string)($_POST['source'] ?? 'order_track'));
$orderId = (ctype_digit($orderIdRaw) && $orderIdRaw !== '') ? (int)$orderIdRaw : 0;
$tableId = (ctype_digit($tableIdRaw) && $tableIdRaw !== '') ? (int)$tableIdRaw : 0;
$rating = (ctype_digit($ratingRaw) && $ratingRaw !== '') ? (int)$ratingRaw : 0;
$npsScore = null;
if ($npsScoreRaw !== '') {
    if (ctype_digit($npsScoreRaw)) {
        $npsScore = (int)$npsScoreRaw;
    } else {
        echo json_encode(['success' => false, 'message' => 'NPS должен быть от 0 до 10.']);
        exit;
    }
}

if ($orderId <= 0 || $feedbackToken === '') {
    echo json_encode(['success' => false, 'message' => 'Некорректные данные отзыва.']);
    exit;
}
if ($rating < 1 || $rating > 5) {
    echo json_encode(['success' => false, 'message' => 'Оценка должна быть от 1 до 5.']);
    exit;
}
if ($npsScore !== null && ($npsScore < 0 || $npsScore > 10)) {
    echo json_encode(['success' => false, 'message' => 'NPS должен быть от 0 до 10.']);
    exit;
}

$reviewText = mb_substr($reviewText, 0, 2000);
$reviewTags = $_POST['review_tags'] ?? [];
if (!is_array($reviewTags)) {
    $reviewTags = [$reviewTags];
}

if (!function_exists('db_table_exists') || !db_table_exists('orders')) {
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

    $phoneSelect = [];
    foreach (['customer_phone', 'delivery_phone', 'guest_phone', 'loyalty_phone'] as $col) {
        if (function_exists('db_column_exists') && db_column_exists('orders', $col)) {
            $phoneSelect[] = "o.{$col}";
        }
    }
    $phoneSelectSql = $phoneSelect ? (', ' . implode(', ', $phoneSelect)) : '';
    $stmt = $pdo->prepare("
        SELECT o.id, o.restaurant_id, o.table_id, o.order_status{$phoneSelectSql}
        FROM orders o
        WHERE o.id = :order_id
        LIMIT 1
    ");
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
    if ($tableId > 0 && $orderTableId > 0 && $orderTableId !== $tableId) {
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

    if (function_exists('db_column_exists') && db_column_exists('orders', 'order_status')) {
        $orderStatus = mb_strtolower(trim((string)($order['order_status'] ?? '')), 'UTF-8');
        if (!in_array($orderStatus, ['delivered', 'completed'], true)) {
            echo json_encode(['success' => false, 'message' => 'Отзыв доступен после получения заказа.']);
            exit;
        }
    }

    if (function_exists('is_demo_mode') && is_demo_mode()) {
        unset($_SESSION[$sessionTokenKey]);
        echo json_encode(['success' => true]);
        exit;
    }

    if (!function_exists('guest_review_create')) {
        throw new RuntimeException('guest_review_helper_missing');
    }

    $create = guest_review_create($pdo, $restaurantId, [
        'order_id' => $orderId,
        'rating' => $rating,
        'nps_score' => $npsScore,
        'review_text' => $reviewText,
        'review_tags' => $reviewTags,
        'source' => $sourceRaw !== '' ? $sourceRaw : 'order_track',
        'customer_phone' => (string)($order['customer_phone'] ?? ''),
        'delivery_phone' => (string)($order['delivery_phone'] ?? ''),
        'guest_phone' => (string)($order['guest_phone'] ?? ''),
        'loyalty_phone' => (string)($order['loyalty_phone'] ?? ''),
    ]);
    if (empty($create['ok'])) {
        echo json_encode(['success' => false, 'message' => 'Не удалось сохранить отзыв. Попробуйте позже.']);
        exit;
    }

    // Keep legacy feedback analytics path alive (best effort).
    if (function_exists('db_table_exists') && db_table_exists('order_feedback')) {
        try {
            $stmtLegacy = $pdo->prepare("
                INSERT INTO order_feedback (order_id, restaurant_id, rating, comment, created_at)
                VALUES (:order_id, :restaurant_id, :rating, :comment, NOW())
                ON DUPLICATE KEY UPDATE
                    rating = VALUES(rating),
                    comment = VALUES(comment)
            ");
            $stmtLegacy->execute([
                ':order_id' => $orderId,
                ':restaurant_id' => $restaurantId,
                ':rating' => $rating,
                ':comment' => $reviewText !== '' ? $reviewText : null,
            ]);
        } catch (Throwable $legacyE) {
            error_log('GUEST_REVIEW_LEGACY_SYNC_FAIL order_id=' . $orderId . ' ' . $legacyE->getMessage());
        }
    }

    unset($_SESSION[$sessionTokenKey]);

    echo json_encode([
        'success' => true,
        'review_id' => (int)($create['review_id'] ?? 0),
        'created' => !empty($create['created']),
        'updated' => !empty($create['updated']),
    ]);
} catch (Throwable $e) {
    error_log('GUEST_REVIEW_SUBMIT_FAIL order_id=' . $orderId . ' ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Не удалось сохранить отзыв. Попробуйте позже.']);
}

