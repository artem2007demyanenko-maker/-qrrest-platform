<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/kds_helpers.php';

header('Content-Type: application/json; charset=utf-8');

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

$stationFilter = isset($_GET['station']) ? kds_normalize_station((string)$_GET['station']) : '';
if ($stationFilter !== '' && !in_array($stationFilter, kds_allowed_stations(), true)) {
    $stationFilter = '';
}

$statusFilter = isset($_GET['status']) ? strtolower(trim((string)$_GET['status'])) : 'all';
$allowedStatusFilters = ['all', 'new', 'accepted', 'cooking', 'ready'];
if (!in_array($statusFilter, $allowedStatusFilters, true)) {
    $statusFilter = 'all';
}

$includeClosed = isset($_GET['include_closed']) && $_GET['include_closed'] === '1';

$offlineTypes = ['cash', 'card_later', 'pay_later'];
$now = time();

// Waiter calls preflight.
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

$ordersSql = "
    SELECT
        o.id,
        o.table_id,
        o.order_status,
        o.payment_status,
        o.payment_type,
        o.total_price,
        o.total_amount,
        o.created_at,
        t.name AS table_name
    FROM orders o
    LEFT JOIN tables t ON t.id = o.table_id
    WHERE o.restaurant_id = :rid
";
if (!$includeClosed) {
    $ordersSql .= " AND o.order_status NOT IN ('delivered','canceled','cancelled') ";
}
$ordersSql .= " ORDER BY o.created_at ASC LIMIT 300";

$stmtOrders = $pdo->prepare($ordersSql);
$stmtOrders->execute([':rid' => $restaurantId]);
$orders = $stmtOrders->fetchAll(PDO::FETCH_ASSOC);

if (!$orders) {
    echo json_encode(['success' => true, 'tickets' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

$orderIds = array_values(array_filter(array_map('intval', array_column($orders, 'id'))));
$orderIdsIn = implode(',', $orderIds);

$stationExpr = kds_menu_station_expr($pdo, 'mi');
$hasStationStatusCol = function_exists('db_column_exists') && db_column_exists('order_items', 'station_status');
$hasStartedAtCol = function_exists('db_column_exists') && db_column_exists('order_items', 'started_at');
$hasReadyAtCol = function_exists('db_column_exists') && db_column_exists('order_items', 'ready_at');
$hasItemNameCol = function_exists('db_column_exists') && db_column_exists('order_items', 'item_name');
$hasQtyCol = function_exists('db_column_exists') && db_column_exists('order_items', 'qty');
$qtyExpr = $hasQtyCol ? "COALESCE(NULLIF(oi.quantity, 0), oi.qty, 1)" : "COALESCE(oi.quantity, 1)";
$itemNameExpr = $hasItemNameCol
    ? "COALESCE(NULLIF(TRIM(oi.item_name), ''), mi.name)"
    : "COALESCE(mi.name, 'Позиция')";
$stationStatusExpr = $hasStationStatusCol ? "LOWER(COALESCE(NULLIF(TRIM(oi.station_status), ''), 'new'))" : "NULL";
$startedAtExpr = $hasStartedAtCol ? "oi.started_at" : "NULL";
$readyAtExpr = $hasReadyAtCol ? "oi.ready_at" : "NULL";

$itemsSql = "
    SELECT
        oi.id,
        oi.order_id,
        oi.menu_item_id,
        {$itemNameExpr} AS item_name,
        {$qtyExpr} AS quantity,
        oi.price,
        {$stationExpr} AS station_key,
        {$stationStatusExpr} AS station_status,
        {$startedAtExpr} AS started_at,
        {$readyAtExpr} AS ready_at
    FROM order_items oi
    LEFT JOIN menu_items mi ON mi.id = oi.menu_item_id
    WHERE oi.order_id IN ({$orderIdsIn})
    ORDER BY oi.order_id ASC, oi.id ASC
";
$stmtItems = $pdo->query($itemsSql);
$itemRows = $stmtItems ? $stmtItems->fetchAll(PDO::FETCH_ASSOC) : [];

$itemsByOrder = [];
foreach ($itemRows as $row) {
    $oid = (int)($row['order_id'] ?? 0);
    if ($oid <= 0) {
        continue;
    }
    $station = kds_normalize_station((string)($row['station_key'] ?? 'hot'));
    $st = strtolower(trim((string)($row['station_status'] ?? '')));
    if (!in_array($st, ['new', 'accepted', 'cooking', 'ready'], true)) {
        $st = 'new';
    }
    $itemsByOrder[$oid][] = [
        'id' => (int)($row['id'] ?? 0),
        'menu_item_id' => isset($row['menu_item_id']) ? (int)$row['menu_item_id'] : null,
        'item_name' => (string)($row['item_name'] ?? 'Позиция'),
        'quantity' => max(1, (int)($row['quantity'] ?? 1)),
        'price' => (float)($row['price'] ?? 0),
        'station' => $station,
        'station_status' => $st,
        'started_at' => $row['started_at'] ?? null,
        'ready_at' => $row['ready_at'] ?? null,
    ];
}

$waiterByOrder = [];
$waiterByTable = [];
try {
    $stmtCalls = $pdo->prepare("
        SELECT id, table_id, order_id, created_at
        FROM waiter_calls
        WHERE restaurant_id = :rid
          AND status = 'active'
          AND resolved_at IS NULL
        ORDER BY created_at DESC
        LIMIT 500
    ");
    $stmtCalls->execute([':rid' => $restaurantId]);
    foreach ($stmtCalls->fetchAll(PDO::FETCH_ASSOC) as $call) {
        $cid = (int)($call['id'] ?? 0);
        $oid = (int)($call['order_id'] ?? 0);
        $tid = (int)($call['table_id'] ?? 0);
        if ($oid > 0 && !isset($waiterByOrder[$oid])) {
            $waiterByOrder[$oid] = $cid;
        }
        if ($tid > 0 && !isset($waiterByTable[$tid])) {
            $waiterByTable[$tid] = $cid;
        }
    }
} catch (Throwable $e) {
    // ignore
}

$tickets = [];
foreach ($orders as $o) {
    $orderId = (int)($o['id'] ?? 0);
    if ($orderId <= 0) {
        continue;
    }
    $items = $itemsByOrder[$orderId] ?? [];
    if (!$items) {
        continue;
    }

    $tableId = (int)($o['table_id'] ?? 0);
    $orderStatus = (string)($o['order_status'] ?? 'new');
    $paymentStatus = (string)($o['payment_status'] ?? 'unpaid');
    $paymentType = (string)($o['payment_type'] ?? '');
    $createdTs = strtotime((string)($o['created_at'] ?? ''));
    $sinceMinutes = $createdTs ? max(0, (int)floor(($now - $createdTs) / 60)) : 0;

    $countdownSecondsRemaining = null;
    $countdownExpired = false;
    $countdownWarning = false;
    if ($paymentStatus === 'unpaid' && in_array($paymentType, $offlineTypes, true) && $createdTs) {
        $elapsed = max(0, $now - $createdTs);
        $remaining = (10 * 60) - $elapsed;
        $countdownSecondsRemaining = max(0, (int)$remaining);
        $countdownExpired = $remaining <= 0;
        $countdownWarning = !$countdownExpired && $countdownSecondsRemaining <= (3 * 60);
    }

    $totalItemsCount = count($items);
    $readyItemsCount = 0;
    foreach ($items as $it) {
        if (($it['station_status'] ?? '') === 'ready') {
            $readyItemsCount++;
        }
    }
    $partialReady = $readyItemsCount > 0 && $readyItemsCount < $totalItemsCount;
    $progressPercent = $totalItemsCount > 0 ? (int)floor(($readyItemsCount / $totalItemsCount) * 100) : 0;

    $itemsByStation = [];
    foreach ($items as $it) {
        $st = $it['station'];
        $itemsByStation[$st][] = $it;
    }

    foreach ($itemsByStation as $station => $stationItems) {
        if ($stationFilter !== '' && $station !== $stationFilter) {
            continue;
        }

        $stationReady = 0;
        $stationAccepted = 0;
        $stationCooking = 0;
        foreach ($stationItems as $si) {
            $sst = (string)($si['station_status'] ?? 'new');
            if ($sst === 'ready') $stationReady++;
            if ($sst === 'accepted') $stationAccepted++;
            if ($sst === 'cooking') $stationCooking++;
        }

        $stationStatus = 'new';
        if ($stationReady === count($stationItems)) {
            $stationStatus = 'ready';
        } elseif ($stationCooking > 0) {
            $stationStatus = 'cooking';
        } elseif ($stationAccepted > 0) {
            $stationStatus = 'accepted';
        }

        if ($statusFilter !== 'all' && $stationStatus !== $statusFilter) {
            continue;
        }

        $waiterCallId = $waiterByOrder[$orderId] ?? ($waiterByTable[$tableId] ?? null);
        $hasWaiterCall = $waiterCallId !== null;

        $ticket = [
            'ticket_id' => $orderId . ':' . $station,
            'order_id' => $orderId,
            'station' => $station,
            'station_label' => kds_station_label_ru($station),
            'station_status' => $stationStatus,
            'table_id' => $tableId,
            'table_name' => (string)($o['table_name'] ?? ('Стол #' . $tableId)),
            'created_at' => $o['created_at'] ?? null,
            'minutes_since_created' => $sinceMinutes,
            'total_price' => isset($o['total_price']) && $o['total_price'] !== null && $o['total_price'] !== ''
                ? (float)$o['total_price']
                : (float)($o['total_amount'] ?? 0),
            'payment_type' => $paymentType,
            'payment_status' => $paymentStatus,
            'order_status' => $orderStatus,
            'countdown_seconds_remaining' => $countdownSecondsRemaining,
            'countdown_warning' => $countdownWarning,
            'countdown_expired' => $countdownExpired,
            'countdown_mmss' => $countdownSecondsRemaining !== null ? gmdate('i:s', $countdownSecondsRemaining) : null,
            'ready_items_count' => $readyItemsCount,
            'total_items_count' => $totalItemsCount,
            'progress_percent' => $progressPercent,
            'partial_ready' => $partialReady,
            'station_ready_items_count' => $stationReady,
            'station_total_items_count' => count($stationItems),
            'station_partial_ready' => $stationReady > 0 && $stationReady < count($stationItems),
            'items' => $stationItems,
            'has_waiter_call' => $hasWaiterCall,
            'waiter_call_id' => $waiterCallId,
            'vip_priority' => false,
            'priority' => [
                'waiter_call' => $hasWaiterCall,
                'payment_expired' => $countdownExpired,
                'payment_warning' => $countdownWarning,
                'age_minutes' => $sinceMinutes,
            ],
        ];
        $tickets[] = $ticket;
    }
}

usort($tickets, static function (array $a, array $b): int {
    $ap = $a['priority'] ?? [];
    $bp = $b['priority'] ?? [];

    $aCall = !empty($ap['waiter_call']) ? 1 : 0;
    $bCall = !empty($bp['waiter_call']) ? 1 : 0;
    if ($aCall !== $bCall) return $bCall <=> $aCall;

    $aExpired = !empty($ap['payment_expired']) ? 1 : 0;
    $bExpired = !empty($bp['payment_expired']) ? 1 : 0;
    if ($aExpired !== $bExpired) return $bExpired <=> $aExpired;

    $aWarn = !empty($ap['payment_warning']) ? 1 : 0;
    $bWarn = !empty($bp['payment_warning']) ? 1 : 0;
    if ($aWarn !== $bWarn) return $bWarn <=> $aWarn;

    $statusRank = ['new' => 0, 'accepted' => 1, 'cooking' => 2, 'ready' => 3];
    $as = $statusRank[$a['station_status'] ?? 'ready'] ?? 3;
    $bs = $statusRank[$b['station_status'] ?? 'ready'] ?? 3;
    if ($as !== $bs) return $as <=> $bs;

    $aAge = (int)($ap['age_minutes'] ?? 0);
    $bAge = (int)($bp['age_minutes'] ?? 0);
    return $bAge <=> $aAge;
});

echo json_encode([
    'success' => true,
    'station' => $stationFilter !== '' ? $stationFilter : null,
    'status' => $statusFilter,
    'tickets' => $tickets,
], JSON_UNESCAPED_UNICODE);
