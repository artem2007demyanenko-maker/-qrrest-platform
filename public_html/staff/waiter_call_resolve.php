<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';

require_login();

header('Content-Type: application/json; charset=utf-8');

if (!$currentRestaurant) {
    echo json_encode(['success' => false, 'message' => 'Контекст ресторана не найден'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Метод не поддерживается'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!function_exists('require_restaurant_role')) {
    echo json_encode(['success' => false, 'message' => 'Ошибка проверки доступа'], JSON_UNESCAPED_UNICODE);
    exit;
}

$restaurantId = (int)($currentRestaurant['id'] ?? 0);
require_restaurant_role($restaurantId, ['staff', 'admin', 'owner']);

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

if (file_exists(__DIR__ . '/../../app/schema_guard.php')) {
    require_once __DIR__ . '/../../app/schema_guard.php';
}

// Ensure table exists (best-effort).
try {
    if (!function_exists('db_table_exists') || !db_table_exists('waiter_calls')) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS waiter_calls (
                id INT AUTO_INCREMENT PRIMARY KEY,
                restaurant_id INT NOT NULL,
                table_id INT NOT NULL,
                order_id INT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'active',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                resolved_at DATETIME NULL,
                KEY idx_rest_table (restaurant_id, table_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
    }
} catch (Throwable $e) {
    // ignore
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

