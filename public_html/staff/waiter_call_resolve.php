<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/waiter_calls.php';

header('Content-Type: application/json; charset=utf-8');
require_waiter_access();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Метод не поддерживается'], JSON_UNESCAPED_UNICODE);
    exit;
}

$restaurantId = (int)($currentRestaurant['id'] ?? 0);

$pdo = db();
if (!$pdo instanceof PDO) {
    echo json_encode(['success' => false, 'message' => 'Нет соединения с БД'], JSON_UNESCAPED_UNICODE);
    exit;
}

// CSRF
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
$csrfOk = isset($_POST['csrf'], $_SESSION['csrf'])
    && is_string($_POST['csrf'])
    && hash_equals((string)$_SESSION['csrf'], (string)$_POST['csrf']);
if (!$csrfOk) {
    echo json_encode(['success' => false, 'message' => 'Неверный CSRF-токен'], JSON_UNESCAPED_UNICODE);
    exit;
}

$waiterCallId = isset($_POST['waiter_call_id']) ? (int)$_POST['waiter_call_id'] : 0;
if ($waiterCallId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Некорректный ID вызова'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!waiter_calls_require_table()) {
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => 'Функция временно недоступна'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT id, restaurant_id, status
        FROM waiter_calls
        WHERE id = :id
          AND restaurant_id = :rid
          AND status = 'active'
          AND resolved_at IS NULL
        LIMIT 1
    ");
    $stmt->execute([
        ':id' => $waiterCallId,
        ':rid' => $restaurantId,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        echo json_encode(['success' => false, 'message' => 'Вызов не найден или уже обработан'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $upd = $pdo->prepare("
        UPDATE waiter_calls
        SET status = 'resolved',
            resolved_at = NOW()
        WHERE id = :id
          AND restaurant_id = :rid
    ");
    $upd->execute([
        ':id' => $waiterCallId,
        ':rid' => $restaurantId,
    ]);

    echo json_encode(['success' => true, 'message' => 'Вызов обработан'], JSON_UNESCAPED_UNICODE);
    exit;
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => 'Ошибка при обработке вызова'], JSON_UNESCAPED_UNICODE);
    exit;
}
