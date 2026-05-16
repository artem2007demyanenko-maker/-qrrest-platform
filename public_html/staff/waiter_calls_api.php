<?php

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/waiter_calls.php';

header('Content-Type: application/json; charset=utf-8');
require_waiter_access();

$restaurantId = (int)($currentRestaurant['id'] ?? 0);
if ($restaurantId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Некорректный ресторан'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = db();
if (!$pdo instanceof PDO) {
    echo json_encode(['success' => false, 'message' => 'Нет соединения с БД'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!waiter_calls_require_table(false)) {
    echo json_encode([
        'success' => true,
        'calls' => [],
    ], JSON_UNESCAPED_UNICODE);
    exit;
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
