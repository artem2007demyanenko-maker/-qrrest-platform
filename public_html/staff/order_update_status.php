<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'success' => false,
        'message' => 'Метод не поддерживается'
    ]);
    exit;
}

if (!function_exists('require_login') || !function_exists('auth_user')) {
    echo json_encode([
        'success' => false,
        'message' => 'Ошибка загрузки авторизации'
    ]);
    exit;
}

require_login();

$user = auth_user();
if (!$user) {
    echo json_encode([
        'success' => false,
        'message' => 'Необходима авторизация'
    ]);
    exit;
}

global $currentRestaurant;
if (empty($currentRestaurant) || empty($currentRestaurant['id'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Ресторан не определён'
    ]);
    exit;
}

$restaurantId = (int)$currentRestaurant['id'];

if (!function_exists('require_restaurant_role')) {
    echo json_encode([
        'success' => false,
        'message' => 'Ошибка проверки доступа'
    ]);
    exit;
}

require_restaurant_role($restaurantId, ['staff', 'admin', 'owner']);

if (function_exists('is_demo_mode') && is_demo_mode()) {
    echo json_encode([
        'success' => false,
        'message' => 'В демо-режиме изменение статусов заказов отключено.',
    ]);
    exit;
}

$pdo = function_exists('db') ? db() : ($GLOBALS['pdo'] ?? null);
if (!$pdo instanceof PDO) {
    echo json_encode([
        'success' => false,
        'message' => 'Нет соединения с БД'
    ]);
    exit;
}

$orderId        = isset($_POST['order_id']) ? (int)$_POST['order_id'] : 0;
$newOrderStatus = isset($_POST['order_status']) ? trim((string)$_POST['order_status']) : '';

function normalize_order_status_for_db(string $status): string
{
    $s = strtolower(trim($status));
    $map = [
        'created' => 'new',
        'in_progress' => 'cooking',
        'completed' => 'delivered',
        'cancelled' => 'canceled',
        'expired' => 'canceled',
    ];
    return $map[$s] ?? $s;
}

function normalize_payment_status_for_db(string $status): string
{
    $s = strtolower(trim($status));
    $map = [
        'pending_payment' => 'pending',
        'cancelled' => 'canceled',
    ];
    return $map[$s] ?? $s;
}

function can_transition_order_status(string $from, string $to): bool
{
    if ($from === $to) return true; // идемпотентность (повторяемый запрос)
    $allowed = [
        'new' => ['accepted'],
        'accepted' => ['cooking'],
        'cooking' => ['ready'],
        'ready' => ['delivered'],
    ];
    return in_array($to, $allowed[$from] ?? [], true);
}

if ($orderId <= 0) {
    echo json_encode([
        'success' => false,
        'message' => 'Некорректный ID заказа'
    ]);
    exit;
}

if ($newOrderStatus === '') {
    echo json_encode([
        'success' => false,
        'message' => 'Нет данных для обновления'
    ]);
    exit;
}

// CSRF: используем токен из $_SESSION так же, как на странице официанта
$csrfOk = isset($_POST['csrf'], $_SESSION['csrf'])
    && is_string($_POST['csrf'])
    && is_string($_SESSION['csrf'] ?? null)
    && hash_equals((string)$_SESSION['csrf'], (string)$_POST['csrf']);
if (!$csrfOk) {
    echo json_encode([
        'success' => false,
        'message' => 'Неверный CSRF-токен'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $startedTx = false;
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $startedTx = true;
    }
    $stmt = $pdo->prepare("
        SELECT id, restaurant_id, order_status, payment_status
        FROM orders
        WHERE id = :id AND restaurant_id = :rid
        LIMIT 1
    ");
    $stmt->execute([
        ':id'  => $orderId,
        ':rid' => $restaurantId,
    ]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        if ($startedTx && $pdo->inTransaction()) $pdo->rollBack();
        echo json_encode([
            'success' => false,
            'message' => 'Заказ не найден в этом ресторане'
        ]);
        exit;
    }

    $setParts = [];
    $params   = [':id' => $orderId, ':rid' => $restaurantId];

    if ($newOrderStatus !== '') {
        $newOrderStatus = normalize_order_status_for_db($newOrderStatus);
        $allowedOrderStatuses = ['new', 'accepted', 'cooking', 'ready', 'delivered'];
        if (!in_array($newOrderStatus, $allowedOrderStatuses, true)) {
            echo json_encode([
                'success' => false,
                'message' => 'Недопустимый статус заказа'
            ]);
            if ($startedTx && $pdo->inTransaction()) $pdo->rollBack();
            exit;
        }
        $currentOrderStatus = normalize_order_status_for_db((string)($order['order_status'] ?? ''));
        if (!can_transition_order_status($currentOrderStatus, $newOrderStatus)) {
            echo json_encode([
                'success' => false,
                'message' => 'Нелогичный переход статуса заказа'
            ]);
            if ($startedTx && $pdo->inTransaction()) $pdo->rollBack();
            exit;
        }
        $setParts[]              = 'order_status = :order_status';
        $params[':order_status'] = $newOrderStatus;
    }

    if (empty($setParts)) {
        if ($startedTx && $pdo->inTransaction()) $pdo->rollBack();
        echo json_encode([
            'success' => false,
            'message' => 'Нет полей для обновления'
        ]);
        exit;
    }

    $sql = "UPDATE orders SET " . implode(', ', $setParts) . " WHERE id = :id AND restaurant_id = :rid";
    $upd = $pdo->prepare($sql);
    $upd->execute($params);

    if ($upd->rowCount() === 0) {
        if ($startedTx && $pdo->inTransaction()) $pdo->commit();
        echo json_encode([
            'success' => true,
            'message' => 'Изменений нет (идемпотентный повтор)'
        ]);
        exit;
    }

    if (!is_demo_mode() && file_exists(__DIR__ . '/../../app/crm_repo.php')) {
        require_once __DIR__ . '/../../app/crm_repo.php';
        if (function_exists('crm_finalize_order_visit')) {
            try {
                crm_finalize_order_visit($restaurantId, $orderId);
            } catch (Throwable $e) {
                error_log('CRM_FINALIZE_ORDER_VISIT order_id=' . $orderId . ' ' . $e->getMessage());
            }
        }
    }

    if ($startedTx && $pdo->inTransaction()) {
        $pdo->commit();
    }

    if (function_exists('add_log')) {
        $msgParts = [];
        if ($newOrderStatus !== '') {
            $msgParts[] = 'order_status: ' . ($order['order_status'] ?? '') . ' → ' . $newOrderStatus;
        }

        add_log($pdo, [
            'user_id'       => (int)$user['id'],
            'restaurant_id' => $restaurantId,
            'level'         => 'info',
            'action'        => 'order_update_status',
            'message'       => 'Заказ #' . $orderId . ' обновлён сотрудником #' . (int)$user['id']
                . (empty($msgParts) ? '' : ' (' . implode('; ', $msgParts) . ')'),
        ]);
    }

    echo json_encode([
        'success' => true,
        'message' => 'Статус заказа обновлён'
    ]);
    exit;

} catch (PDOException $e) {
    if (isset($startedTx) && $startedTx && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if (function_exists('add_log')) {
        add_log($pdo, [
            'user_id'       => (int)$user['id'],
            'restaurant_id' => $restaurantId,
            'level'         => 'error',
            'action'        => 'order_update_status_error',
            'message'       => 'Ошибка БД при обновлении заказа #' . $orderId . ': ' . $e->getMessage(),
        ]);
    }

    echo json_encode([
        'success' => false,
        'message' => 'Внутренняя ошибка при обновлении статуса. Попробуйте позже.'
    ]);
    exit;
}
