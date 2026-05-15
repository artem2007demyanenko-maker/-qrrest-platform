<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/kds_helpers.php';
require_once __DIR__ . '/../../app/waiter_calls.php';
require_once __DIR__ . '/../../app/guest_order_loyalty_attach.php';

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
require_kitchen_access($restaurantId);
$staffRole = function_exists('current_user_restaurant_role')
    ? normalize_restaurant_role((string)(current_user_restaurant_role($restaurantId) ?? ''))
    : '';
$staffStation = function_exists('current_staff_station')
    ? (string)current_staff_station($restaurantId)
    : ($staffRole === 'bar' ? 'bar' : 'hot');
$staffStationKds = function_exists('station_to_kds_key')
    ? station_to_kds_key($staffStation)
    : ($staffRole === 'bar' ? 'bar' : 'kitchen');
$barStationLocked = ($staffRole === 'bar');
$isStationRole = function_exists('restaurant_role_is_station_role')
    ? restaurant_role_is_station_role($staffRole)
    : in_array($staffRole, ['bar', 'kitchen'], true);
$isFixedStationRole = function_exists('restaurant_role_is_fixed_station_role')
    ? restaurant_role_is_fixed_station_role($staffRole)
    : ($staffRole === 'bar');

function kitchen_orders_sync_loyalty_safe(PDO $pdo, array $restaurantRow, int $orderId): void
{
    if (!function_exists('guest_order_loyalty_sync')) {
        return;
    }

    try {
        $res = guest_order_loyalty_sync($pdo, $restaurantRow, $orderId, null);
        if (!is_array($res) || empty($res['ok'])) {
            error_log('staff/kitchen_item_update loyalty_sync order_id=' . $orderId . ' error=' . (string)($res['error'] ?? 'unknown'));
        }
    } catch (Throwable $e) {
        error_log('staff/kitchen_item_update loyalty_sync order_id=' . $orderId . ' ' . $e->getMessage());
    }
}

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
$hasStationCompletedAtCol = function_exists('db_column_exists') && db_column_exists('order_items', 'station_completed_at');
$hasOrderItemStationStatusCol = function_exists('db_column_exists') && db_column_exists('order_items', 'station_status');
$hasOrderItemKdsStatusCol = function_exists('db_column_exists') && db_column_exists('order_items', 'kds_status');
$hasOrderItemStartedAtCol = function_exists('db_column_exists') && db_column_exists('order_items', 'started_at');
$hasOrderItemReadyAtCol = function_exists('db_column_exists') && db_column_exists('order_items', 'ready_at');
$hasOrderItemKdsStartedAtCol = function_exists('db_column_exists') && db_column_exists('order_items', 'kds_started_at');
$hasOrderItemKdsReadyAtCol = function_exists('db_column_exists') && db_column_exists('order_items', 'kds_ready_at');
$hasOrderItemProdStationCol = function_exists('db_column_exists') && db_column_exists('order_items', 'production_station');
$hasMenuProdStationCol = function_exists('db_column_exists') && db_column_exists('menu_items', 'production_station');
$hasMenuStationCol = function_exists('db_column_exists') && db_column_exists('menu_items', 'station');
$hasMenuKitchenStationCol = function_exists('db_column_exists') && db_column_exists('menu_items', 'kitchen_station');

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
if ($isFixedStationRole && $staffStationKds !== 'all') {
    $station = $staffStationKds;
}
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
if ($action === 'notify_waiter' && !waiter_calls_require_table()) {
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => 'Функция временно недоступна'], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($action !== 'notify_waiter' && $station === '') {
    echo json_encode(['success' => false, 'message' => 'Некорректная станция'], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($action !== 'notify_waiter' && !(function_exists('can_access_station')
    ? can_access_station($station, $restaurantId, $staffRole)
    : user_has_station_access($staffRole, $station))) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'station_access_denied'], JSON_UNESCAPED_UNICODE);
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

    $statusExpr = function_exists('kds_item_status_sql_expr')
        ? kds_item_status_sql_expr('oi')
        : "LOWER(COALESCE(NULLIF(TRIM(oi.station_status), ''), 'new'))";
    $itemStationSqlExpr = function_exists('kds_item_station_sql_expr')
        ? kds_item_station_sql_expr($pdo, 'oi', 'mi')
        : "CASE
            WHEN LOWER(COALESCE(NULLIF(TRIM(" . ($hasOrderItemProdStationCol ? "oi.production_station" : "''") . "), ''), NULLIF(TRIM(" . ($hasMenuProdStationCol ? "mi.production_station" : "''") . "), ''), 'kitchen')) IN ('', 'hot', 'kitchen') THEN 'kitchen'
            ELSE LOWER(COALESCE(NULLIF(TRIM(" . ($hasOrderItemProdStationCol ? "oi.production_station" : "''") . "), ''), NULLIF(TRIM(" . ($hasMenuProdStationCol ? "mi.production_station" : "''") . "), ''), 'kitchen'))
        END";

    $selectSql = "
        SELECT
            oi.id,
            oi.menu_item_id,
            " . ((function_exists('db_column_exists') && db_column_exists('order_items', 'item_name')) ? "oi.item_name" : "NULL") . " AS item_name,
            {$itemStationSqlExpr} AS production_station,
            " . kds_menu_station_expr($pdo, 'mi') . " AS station_key,
            {$statusExpr} AS item_kds_status
        FROM order_items oi
        LEFT JOIN menu_items mi ON mi.id = oi.menu_item_id
        LEFT JOIN menu_categories mc ON mc.id = mi.category_id
        WHERE oi.order_id = :oid
          AND {$statusExpr} IN (" . implode(',', $statusPlaceholders) . ")
          AND {$itemStationSqlExpr} = :station
    ";
    if ($itemId > 0) {
        $selectSql .= " AND oi.id = :item_id ";
    }
    $selectSql .= " FOR UPDATE";
    $stmtItems = $pdo->prepare($selectSql);
    if ($itemId > 0) {
        $params[':item_id'] = $itemId;
    }
    $stmtItems->execute($params);
    $targetRows = $stmtItems->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $targetItemIds = [];
    foreach ($targetRows as $targetRow) {
        $route = function_exists('kitchen_station_item_route')
            ? kitchen_station_item_route($pdo, $restaurantId, $targetRow)
            : [
                'station_key' => (function_exists('kds_resolve_station_for_item')
                    ? kds_resolve_station_for_item($targetRow)
                    : kds_normalize_station((string)($targetRow['station_key'] ?? 'kitchen'))),
            ];
        $itemStation = kds_normalize_station((string)($route['station_key'] ?? 'kitchen'));
        if ($itemStation !== $station) {
            continue;
        }
        $targetItemIds[] = (int)($targetRow['id'] ?? 0);
    }
    $targetItemIds = array_values(array_filter($targetItemIds));

    if (!$targetItemIds) {
        $pdo->rollBack();
        echo json_encode([
            'success' => false,
            'message' => 'Нет позиций для выбранного действия',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $in = implode(',', $targetItemIds);
    $setParts = [];
    $updateParams = [];
    if ($hasOrderItemKdsStatusCol) {
        $setParts[] = "kds_status = :st_kds";
        $updateParams[':st_kds'] = $targetStatus;
    }
    if ($hasOrderItemStationStatusCol) {
        $setParts[] = "station_status = :st_station";
        $updateParams[':st_station'] = $targetStatus;
    }
    if ($setParts === []) {
        $setParts[] = "station_status = :st_station";
        $updateParams[':st_station'] = $targetStatus;
    }

    if ($action === 'start' || $action === 'accept') {
        if ($hasOrderItemKdsStartedAtCol) {
            $setParts[] = "kds_started_at = COALESCE(kds_started_at, NOW())";
        }
        if ($hasOrderItemStartedAtCol) {
            $setParts[] = "started_at = COALESCE(started_at, NOW())";
        }
        if ($hasStationCompletedAtCol) {
            $setParts[] = "station_completed_at = NULL";
        }
    }
    if ($action === 'ready') {
        if ($hasOrderItemKdsStartedAtCol) {
            $setParts[] = "kds_started_at = COALESCE(kds_started_at, NOW())";
        }
        if ($hasOrderItemStartedAtCol) {
            $setParts[] = "started_at = COALESCE(started_at, NOW())";
        }
        if ($hasOrderItemKdsReadyAtCol) {
            $setParts[] = "kds_ready_at = NOW()";
        }
        if ($hasOrderItemReadyAtCol) {
            $setParts[] = "ready_at = NOW()";
        }
        if ($hasStationCompletedAtCol) {
            $setParts[] = "station_completed_at = NOW()";
        }
    }

    $updSql = "UPDATE order_items SET " . implode(', ', $setParts) . " WHERE id IN ({$in})";
    $upd = $pdo->prepare($updSql);
    $upd->execute($updateParams);
    $progress = kds_order_progress($pdo, $orderId);
    $kdsState = function_exists('calculate_order_kds_state')
        ? calculate_order_kds_state($pdo, $orderId)
        : ['code' => 'new', 'label' => 'Новый'];
    $readyForServe = function_exists('is_order_ready_for_serve')
        ? is_order_ready_for_serve($pdo, $orderId)
        : false;

    $stmtStatus = $pdo->prepare("SELECT order_status FROM orders WHERE id = :oid LIMIT 1");
    $stmtStatus->execute([':oid' => $orderId]);
    $newOrderStatus = (string)($stmtStatus->fetchColumn() ?: 'new');

    kitchen_orders_sync_loyalty_safe($pdo, $currentRestaurant, $orderId);

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'order_id' => $orderId,
        'updated_items_count' => count($targetItemIds),
        'station' => $station,
        'station_status' => $targetStatus,
        'kds_status' => $targetStatus,
        'order_status' => $newOrderStatus,
        'progress' => $progress,
        'kds_order_state' => $kdsState,
        'ready_for_serve' => $readyForServe,
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
