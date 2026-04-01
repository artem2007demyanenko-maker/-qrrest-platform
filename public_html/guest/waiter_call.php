<?php

require_once __DIR__ . '/../../app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if (empty($currentRestaurant) || empty($currentRestaurant['id'])) {
    echo json_encode(['success' => false, 'message' => 'Ресторан не найден'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Метод не поддерживается'], JSON_UNESCAPED_UNICODE);
    exit;
}

$restaurantId = (int)$currentRestaurant['id'];
$tableId = isset($_POST['table_id']) ? (int)$_POST['table_id'] : 0;
$orderId = isset($_POST['order_id']) ? (int)$_POST['order_id'] : 0;

if ($tableId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Некорректный стол'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = db();
if (!$pdo instanceof PDO) {
    echo json_encode(['success' => false, 'message' => 'Нет соединения с БД'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (file_exists(__DIR__ . '/../../app/schema_guard.php')) {
    require_once __DIR__ . '/../../app/schema_guard.php';
}

// Validate table exists for this restaurant.
$stmtTable = $pdo->prepare("
    SELECT id
    FROM tables
    WHERE id = :tid AND restaurant_id = :rid
    LIMIT 1
");
$stmtTable->execute([':tid' => $tableId, ':rid' => $restaurantId]);
if (!$stmtTable->fetch(PDO::FETCH_ASSOC)) {
    echo json_encode(['success' => false, 'message' => 'Стол не найден'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($orderId > 0) {
    $stmtOrder = $pdo->prepare("
        SELECT id
        FROM orders
        WHERE id = :oid AND restaurant_id = :rid AND table_id = :tid
        LIMIT 1
    ");
    $stmtOrder->execute([':oid' => $orderId, ':rid' => $restaurantId, ':tid' => $tableId]);
    if (!$stmtOrder->fetch(PDO::FETCH_ASSOC)) {
        echo json_encode(['success' => false, 'message' => 'Заказ не найден для этого стола'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// Ensure waiter_calls table exists.
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
                KEY idx_rest_table (restaurant_id, table_id),
                KEY idx_rest_status (restaurant_id, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }
} catch (Throwable $e) {
    // If creation fails (permissions), feature will degrade gracefully.
}

$now = time();

// Find existing active call for the table (avoid duplicates).
$stmtActive = $pdo->prepare("
    SELECT id, created_at, order_id
    FROM waiter_calls
    WHERE restaurant_id = :rid
      AND table_id = :tid
      AND status = 'active'
      AND resolved_at IS NULL
    ORDER BY created_at DESC
    LIMIT 1
");
$stmtActive->execute([':rid' => $restaurantId, ':tid' => $tableId]);
$active = $stmtActive->fetch(PDO::FETCH_ASSOC);

if ($active) {
    $activeId = (int)($active['id'] ?? 0);
    $createdTs = strtotime((string)($active['created_at'] ?? ''));
    $elapsed = $createdTs ? max(0, $now - $createdTs) : 0;
    $cooldownSeconds = max(0, 60 - (int)$elapsed);

    if ($cooldownSeconds > 0) {
        echo json_encode([
            'success' => true,
            'message' => 'Официант уведомлён',
            'waiter_call_id' => $activeId,
            'reused' => true,
            'cooldown_seconds_remaining' => $cooldownSeconds,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Update existing call to associate with current order (if provided).
    if ($orderId > 0) {
        $upd = $pdo->prepare("
            UPDATE waiter_calls
            SET order_id = :oid,
                created_at = NOW()
            WHERE id = :id
              AND restaurant_id = :rid
        ");
        $upd->execute([
            ':oid' => $orderId,
            ':id'  => $activeId,
            ':rid' => $restaurantId,
        ]);
    }

    echo json_encode([
        'success' => true,
        'message' => 'Официант уведомлён',
        'waiter_call_id' => $activeId,
        'reused' => true,
        'cooldown_seconds_remaining' => 60,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$ins = $pdo->prepare("
    INSERT INTO waiter_calls (restaurant_id, table_id, order_id, status, created_at, resolved_at)
    VALUES (:rid, :tid, :oid, 'active', NOW(), NULL)
");
$ins->execute([
    ':rid' => $restaurantId,
    ':tid' => $tableId,
    ':oid' => $orderId > 0 ? $orderId : null,
]);
$newId = (int)$pdo->lastInsertId();

echo json_encode([
    'success' => true,
    'message' => 'Официант уведомлён',
    'waiter_call_id' => $newId,
    'reused' => false,
    'cooldown_seconds_remaining' => 60,
], JSON_UNESCAPED_UNICODE);

exit;

