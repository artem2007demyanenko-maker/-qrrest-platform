<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/kds_helpers.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Метод не поддерживается'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_login();
if (!$currentRestaurant) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Контекст ресторана не найден'], JSON_UNESCAPED_UNICODE);
    exit;
}

$restaurantId = (int)($currentRestaurant['id'] ?? 0);
require_restaurant_role($restaurantId, ['staff', 'admin', 'owner']);

$pdo = db();
if (!$pdo instanceof PDO) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Нет соединения с БД'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (file_exists(__DIR__ . '/../../app/schema_guard.php')) {
    require_once __DIR__ . '/../../app/schema_guard.php';
}
kds_ensure_schema($pdo);

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
$csrfOk = isset($_POST['csrf']) && is_string($_POST['csrf']) && hash_equals((string)$_SESSION['csrf'], (string)$_POST['csrf']);
if (!$csrfOk) {
    echo json_encode(['success' => false, 'message' => 'Неверный CSRF-токен'], JSON_UNESCAPED_UNICODE);
    exit;
}

$orderId = isset($_POST['order_id']) ? (int)$_POST['order_id'] : 0;
$itemId = isset($_POST['item_id']) ? (int)$_POST['item_id'] : 0;
$station = kds_normalize_station((string)($_POST['station'] ?? ''));
$action = strtolower(trim((string)($_POST['action'] ?? '')));

if ($orderId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Некорректный заказ'], JSON_UNESCAPED_UNICODE);
    exit;
}

$allowedActions = ['accept', 'start', 'ready', 'notify_waiter'];
if (!in_array($action, $allowedActions, true)) {
    echo json_encode(['success' => false, 'message' => 'Некорректное действие'], JSON_UNESCAPED_UNICODE);
    exit;
}

$stmtOrder = $pdo->prepare("SELECT id, table_id, order_status FROM orders WHERE id = :oid AND restaurant_id = :rid LIMIT 1");
$stmtOrder->execute([':oid' => $orderId, ':rid' => $restaurantId]);
$order = $stmtOrder->fetch(PDO::FETCH_ASSOC);
if (!$order) {
    echo json_encode(['success' => false, 'message' => 'Заказ не найден'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo->beginTransaction();

    if ($action === 'notify_waiter') {
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
                        KEY idx_rest_status (restaurant_id, status),
                        KEY idx_rest_order (restaurant_id, order_id)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
                ");
            }
        } catch (Throwable $e) {
            // best-effort
        }

        $stmtActive = $pdo->prepare("
            SELECT id
            FROM waiter_calls
            WHERE restaurant_id = :rid
              AND table_id = :tid
              AND order_id = :oid
              AND status = 'active'
              AND resolved_at IS NULL
            LIMIT 1
        ");
        $stmtActive->execute([
            ':rid' => $restaurantId,
            ':tid' => (int)($order['table_id'] ?? 0),
            ':oid' => $orderId,
        ]);
        $activeId = (int)($stmtActive->fetchColumn() ?: 0);
        if ($activeId <= 0) {
            $insCall = $pdo->prepare("
                INSERT INTO waiter_calls (restaurant_id, table_id, order_id, status, created_at, resolved_at)
                VALUES (:rid, :tid, :oid, 'active', NOW(), NULL)
            ");
            $insCall->execute([
                ':rid' => $restaurantId,
                ':tid' => (int)($order['table_id'] ?? 0),
                ':oid' => $orderId,
            ]);
            $activeId = (int)$pdo->lastInsertId();
        }

        $pdo->commit();
        echo json_encode([
            'success' => true,
            'message' => 'Официант уведомлён',
            'waiter_call_id' => $activeId,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stationExpr = kds_menu_station_expr($pdo, 'mi');

    $targetStatus = 'new';
    $fromStatuses = ['new'];
    if ($action === 'accept') {
        $targetStatus = 'accepted';
        $fromStatuses = ['new'];
    } elseif ($action === 'start') {
        $targetStatus = 'cooking';
        $fromStatuses = ['accepted'];
    } elseif ($action === 'ready') {
        $targetStatus = 'ready';
        $fromStatuses = ['accepted', 'cooking'];
    }

    $statusPlaceholders = [];
    $params = [
        ':oid' => $orderId,
        ':station' => $station,
    ];
    foreach (array_values($fromStatuses) as $idx => $statusVal) {
        $ph = ':st' . $idx;
        $statusPlaceholders[] = $ph;
        $params[$ph] = $statusVal;
    }

    $selectSql = "
        SELECT oi.id
        FROM order_items oi
        LEFT JOIN menu_items mi ON mi.id = oi.menu_item_id
        WHERE oi.order_id = :oid
          AND {$stationExpr} = :station
          AND LOWER(COALESCE(NULLIF(TRIM(oi.station_status), ''), 'new')) IN (" . implode(',', $statusPlaceholders) . ")
    ";
    if ($itemId > 0) {
        $selectSql .= " AND oi.id = :item_id ";
    }
    $stmtItems = $pdo->prepare($selectSql);
    if ($itemId > 0) {
        $params[':item_id'] = $itemId;
    }
    $stmtItems->execute($params);
    $targetItemIds = array_values(array_filter(array_map('intval', $stmtItems->fetchAll(PDO::FETCH_COLUMN))));

    if (!$targetItemIds) {
        $pdo->rollBack();
        echo json_encode([
            'success' => false,
            'message' => 'Нет позиций для выбранного действия',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $in = implode(',', $targetItemIds);
    $set = "station_status = :st";
    if ($action === 'start' && function_exists('db_column_exists') && db_column_exists('order_items', 'started_at')) {
        $set .= ", started_at = COALESCE(started_at, NOW())";
    }
    if ($action === 'ready') {
        if (function_exists('db_column_exists') && db_column_exists('order_items', 'started_at')) {
            $set .= ", started_at = COALESCE(started_at, NOW())";
        }
        if (function_exists('db_column_exists') && db_column_exists('order_items', 'ready_at')) {
            $set .= ", ready_at = NOW()";
        }
    }

    $updSql = "UPDATE order_items SET {$set} WHERE id IN ({$in})";
    $upd = $pdo->prepare($updSql);
    $upd->execute([':st' => $targetStatus]);

    kds_recalculate_order_status($pdo, $orderId);
    $progress = kds_order_progress($pdo, $orderId);

    $stmtStatus = $pdo->prepare("SELECT order_status FROM orders WHERE id = :oid LIMIT 1");
    $stmtStatus->execute([':oid' => $orderId]);
    $newOrderStatus = (string)($stmtStatus->fetchColumn() ?: 'new');

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'order_id' => $orderId,
        'updated_items_count' => count($targetItemIds),
        'station' => $station,
        'station_status' => $targetStatus,
        'order_status' => $newOrderStatus,
        'progress' => $progress,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('KDS_ITEM_UPDATE_FAIL order_id=' . $orderId . ' ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Ошибка обновления позиции кухни',
    ], JSON_UNESCAPED_UNICODE);
}
