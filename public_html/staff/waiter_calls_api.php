<?php

require_once __DIR__ . '/../../app/bootstrap.php';

require_login();

header('Content-Type: application/json; charset=utf-8');

if (!$currentRestaurant) {
    echo json_encode(['success' => false, 'message' => 'Контекст ресторана не найден'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!function_exists('require_restaurant_role')) {
    echo json_encode(['success' => false, 'message' => 'Ошибка проверки доступа'], JSON_UNESCAPED_UNICODE);
    exit;
}

$restaurantId = (int)($currentRestaurant['id'] ?? 0);
if ($restaurantId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Некорректный ресторан'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_restaurant_role($restaurantId, ['staff', 'admin', 'owner']);

$pdo = db();
if (!$pdo instanceof PDO) {
    echo json_encode(['success' => false, 'message' => 'Нет соединения с БД'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (file_exists(__DIR__ . '/../../app/schema_guard.php')) {
    require_once __DIR__ . '/../../app/schema_guard.php';
}

// Best-effort table creation (same as other endpoints).
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
                resolved_at DATETIME NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
    }
} catch (Throwable $e) {
    // ignore; proceed returning empty list
}

$stmt = $pdo->prepare("
    SELECT id, table_id, order_id, status, created_at, resolved_at
    FROM waiter_calls
    WHERE restaurant_id = :rid
      AND status = 'active'
      AND resolved_at IS NULL
    ORDER BY created_at DESC
    LIMIT 200
");
$stmt->execute([':rid' => $restaurantId]);
$calls = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'success' => true,
    'calls' => array_map(static function ($c) {
        return [
            'id' => (int)($c['id'] ?? 0),
            'table_id' => (int)($c['table_id'] ?? 0),
            'order_id' => isset($c['order_id']) ? (int)$c['order_id'] : null,
            'created_at' => $c['created_at'] ?? null,
        ];
    }, $calls),
], JSON_UNESCAPED_UNICODE);

