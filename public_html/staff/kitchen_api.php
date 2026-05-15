<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/kds_helpers.php';
require_once __DIR__ . '/../../app/order_payment_runtime.php';
require_once __DIR__ . '/../../app/waiter_calls.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if (!function_exists('require_kitchen_access')) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'auth_unavailable'], JSON_UNESCAPED_UNICODE);
    exit;
}
require_kitchen_access();
$restaurantId = (int)($currentRestaurant['id'] ?? 0);
$staffRole = function_exists('current_user_restaurant_role')
    ? normalize_restaurant_role((string)(current_user_restaurant_role($restaurantId) ?? ''))
    : '';
$staffStation = function_exists('current_staff_station')
    ? (string)current_staff_station($restaurantId)
    : ($staffRole === 'bar' ? 'bar' : 'hot');
$staffStationKds = function_exists('station_to_kds_key')
    ? station_to_kds_key($staffStation)
    : ($staffRole === 'bar' ? 'bar' : 'kitchen');
$stationFilterRaw = isset($_GET['station']) ? kds_normalize_station((string)$_GET['station']) : '';
$isStationRole = function_exists('restaurant_role_is_station_role')
    ? restaurant_role_is_station_role($staffRole)
    : in_array($staffRole, ['bar', 'kitchen'], true);
$isFixedStationRole = function_exists('restaurant_role_is_fixed_station_role')
    ? restaurant_role_is_fixed_station_role($staffRole)
    : ($staffRole === 'bar');
if ($isStationRole && $stationFilterRaw === '') {
    $stationFilterRaw = $staffStationKds;
}
if ($stationFilterRaw !== '' && !(function_exists('can_access_station')
    ? can_access_station($stationFilterRaw, $restaurantId, $staffRole)
    : user_has_station_access($staffRole, $stationFilterRaw))) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'station_access_denied'], JSON_UNESCAPED_UNICODE);
    exit;
}
$barStationLocked = ($staffRole === 'bar');

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

$stationList = function_exists('kitchen_station_list')
    ? kitchen_station_list($pdo, $restaurantId)
    : [];
$allowedStations = $stationList !== [] ? array_keys($stationList) : kds_allowed_stations();

$stationFilter = $stationFilterRaw !== '' ? kds_normalize_station($stationFilterRaw) : '';
if ($stationFilter !== '' && !in_array($stationFilter, $allowedStations, true)) {
    $stationFilter = '';
}
if ($isFixedStationRole && $staffStationKds !== 'all') {
    $stationFilter = $staffStationKds;
}
if ($barStationLocked) {
    $stationFilter = 'bar';
}

$statusFilter = isset($_GET['status']) ? strtolower(trim((string)$_GET['status'])) : 'all';
$allowedStatusFilters = ['all', 'new', 'pending', 'accepted', 'preparing', 'cooking', 'ready'];
if (!in_array($statusFilter, $allowedStatusFilters, true)) {
    $statusFilter = 'all';
}
if ($statusFilter === 'pending') {
    $statusFilter = 'accepted';
}
if ($statusFilter === 'preparing') {
    $statusFilter = 'cooking';
}

$includeClosed = isset($_GET['include_closed']) && $_GET['include_closed'] === '1';
$readyHideMinutes = function_exists('kds_ready_auto_hide_minutes') ? kds_ready_auto_hide_minutes() : 240;
$activeHideMinutes = function_exists('kds_active_auto_hide_minutes') ? kds_active_auto_hide_minutes() : 1440;

$now = time();

order_expire_due_orders($pdo, $restaurantId);

$orderTypeSql = (function_exists('db_column_exists') && db_column_exists('orders', 'order_type'))
    ? "o.order_type"
    : "NULL AS order_type";
$orderPaymentTypeSql = "NULL AS payment_type";
if (function_exists('db_column_exists') && db_column_exists('orders', 'payment_type')) {
    $orderPaymentTypeSql = "o.payment_type";
} elseif (function_exists('db_column_exists') && db_column_exists('orders', 'payment_method')) {
    $orderPaymentTypeSql = "o.payment_method AS payment_type";
}
$hasTotalAmountCol = function_exists('db_column_exists') && db_column_exists('orders', 'total_amount');
$hasTotalPriceCol = function_exists('db_column_exists') && db_column_exists('orders', 'total_price');
$hasTotalCol = function_exists('db_column_exists') && db_column_exists('orders', 'total');
$hasSubtotalCol = function_exists('db_column_exists') && db_column_exists('orders', 'subtotal');

$orderTotalAmountSql = "NULL AS total_amount";
if ($hasTotalAmountCol) {
    $orderTotalAmountSql = "o.total_amount";
} elseif ($hasTotalPriceCol) {
    $orderTotalAmountSql = "o.total_price AS total_amount";
} elseif ($hasTotalCol) {
    $orderTotalAmountSql = "o.`total` AS total_amount";
} elseif ($hasSubtotalCol) {
    $orderTotalAmountSql = "o.subtotal AS total_amount";
}

$orderTotalPriceSql = "NULL AS total_price";
if ($hasTotalPriceCol) {
    $orderTotalPriceSql = "o.total_price";
} elseif ($hasTotalAmountCol) {
    $orderTotalPriceSql = "o.total_amount AS total_price";
} elseif ($hasTotalCol) {
    $orderTotalPriceSql = "o.`total` AS total_price";
} elseif ($hasSubtotalCol) {
    $orderTotalPriceSql = "o.subtotal AS total_price";
}
$orderCommentSql = "NULL AS comment";
if (function_exists('db_column_exists') && db_column_exists('orders', 'comment')) {
    $orderCommentSql = "o.comment";
} elseif (function_exists('db_column_exists') && db_column_exists('orders', 'notes')) {
    $orderCommentSql = "o.notes AS comment";
}

$ordersSql = "
    SELECT
        o.id,
        o.table_id,
        {$orderTypeSql},
        o.order_status,
        o.payment_status,
        {$orderPaymentTypeSql},
        {$orderTotalPriceSql},
        {$orderTotalAmountSql},
        {$orderCommentSql},
        o.created_at,
        t.name AS table_name
    FROM orders o
    LEFT JOIN tables t ON t.id = o.table_id
    WHERE o.restaurant_id = :rid
";
if (!$includeClosed) {
    $ordersSql .= " AND LOWER(COALESCE(TRIM(o.order_status), '')) IN ('new','pending','accepted','preparing','cooking','ready') ";
    $ordersSql .= " AND o.created_at >= DATE_SUB(NOW(), INTERVAL {$activeHideMinutes} MINUTE) ";
    $ordersSql .= " AND NOT (LOWER(COALESCE(TRIM(o.order_status), '')) = 'ready' AND o.created_at < DATE_SUB(NOW(), INTERVAL {$readyHideMinutes} MINUTE)) ";
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
$hasOrderItemProdStationCol = function_exists('db_column_exists') && db_column_exists('order_items', 'production_station');
$hasMenuProdStationCol = function_exists('db_column_exists') && db_column_exists('menu_items', 'production_station');
$hasItemNameCol = function_exists('db_column_exists') && db_column_exists('order_items', 'item_name');
$hasQuantityCol = function_exists('db_column_exists') && db_column_exists('order_items', 'quantity');
$hasQtyCol = function_exists('db_column_exists') && db_column_exists('order_items', 'qty');
if ($hasQuantityCol && $hasQtyCol) {
    $qtyExpr = "COALESCE(NULLIF(oi.quantity, 0), oi.qty, 1)";
} elseif ($hasQuantityCol) {
    $qtyExpr = "COALESCE(oi.quantity, 1)";
} elseif ($hasQtyCol) {
    $qtyExpr = "COALESCE(oi.qty, 1)";
} else {
    $qtyExpr = "1";
}
$itemNameExpr = $hasItemNameCol
    ? "COALESCE(NULLIF(TRIM(oi.item_name), ''), mi.name)"
    : "COALESCE(mi.name, 'Позиция')";
$stationStatusExpr = function_exists('kds_item_status_sql_expr')
    ? kds_item_status_sql_expr('oi')
    : "LOWER(COALESCE(NULLIF(TRIM(oi.station_status), ''), 'new'))";
$startedAtExpr = function_exists('kds_item_started_at_sql_expr')
    ? kds_item_started_at_sql_expr('oi')
    : ((function_exists('db_column_exists') && db_column_exists('order_items', 'started_at')) ? "oi.started_at" : "NULL");
$readyAtExpr = function_exists('kds_item_ready_at_sql_expr')
    ? kds_item_ready_at_sql_expr('oi')
    : ((function_exists('db_column_exists') && db_column_exists('order_items', 'ready_at')) ? "oi.ready_at" : "NULL");

$itemStationSqlExpr = function_exists('kds_item_station_sql_expr')
    ? kds_item_station_sql_expr($pdo, 'oi', 'mi')
    : "CASE
        WHEN LOWER(COALESCE(NULLIF(TRIM(" . ($hasOrderItemProdStationCol ? "oi.production_station" : "''") . "), ''), NULLIF(TRIM(" . ($hasMenuProdStationCol ? "mi.production_station" : "''") . "), ''), 'kitchen')) IN ('', 'hot', 'kitchen') THEN 'kitchen'
        ELSE LOWER(COALESCE(NULLIF(TRIM(" . ($hasOrderItemProdStationCol ? "oi.production_station" : "''") . "), ''), NULLIF(TRIM(" . ($hasMenuProdStationCol ? "mi.production_station" : "''") . "), ''), 'kitchen'))
    END";

if ($stationFilter !== '') {
    $orderIdsForStationSql = "
        SELECT DISTINCT oi.order_id
        FROM order_items oi
        LEFT JOIN menu_items mi ON mi.id = oi.menu_item_id
        WHERE oi.order_id IN ({$orderIdsIn})
          AND {$itemStationSqlExpr} = :station_filter
    ";
    $stmtOrderIdsForStation = $pdo->prepare($orderIdsForStationSql);
    $stmtOrderIdsForStation->execute([':station_filter' => $stationFilter]);
    $stationOrderIds = array_values(array_filter(array_map('intval', $stmtOrderIdsForStation->fetchAll(PDO::FETCH_COLUMN))));
    if ($stationOrderIds === []) {
        echo json_encode([
            'success' => true,
            'station' => $stationFilter,
            'status' => $statusFilter,
            'stations' => array_values($stationList),
            'station_summary' => [],
            'station_alerts' => [],
            'tickets' => [],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $orderIds = $stationOrderIds;
    $orderIdsIn = implode(',', $orderIds);
}

$itemsSql = "
    SELECT
        oi.id,
        oi.order_id,
        oi.menu_item_id,
        {$itemNameExpr} AS item_name,
        {$qtyExpr} AS quantity,
        oi.price,
        {$stationExpr} AS station_key,
        {$itemStationSqlExpr} AS production_station,
        mc.name AS category_name,
        {$stationStatusExpr} AS station_status,
        {$stationStatusExpr} AS kds_status,
        {$startedAtExpr} AS started_at,
        {$readyAtExpr} AS ready_at
    FROM order_items oi
    LEFT JOIN menu_items mi ON mi.id = oi.menu_item_id
    LEFT JOIN menu_categories mc ON mc.id = mi.category_id
    WHERE oi.order_id IN ({$orderIdsIn})
      " . ($stationFilter !== '' ? " AND {$itemStationSqlExpr} = :station_filter" : "") . "
    ORDER BY oi.order_id ASC, oi.id ASC
";
$stmtItems = $pdo->prepare($itemsSql);
$stmtItems->execute($stationFilter !== '' ? [':station_filter' => $stationFilter] : []);
$itemRows = $stmtItems ? $stmtItems->fetchAll(PDO::FETCH_ASSOC) : [];

$itemsByOrder = [];
foreach ($itemRows as $row) {
    $oid = (int)($row['order_id'] ?? 0);
    if ($oid <= 0) {
        continue;
    }
    $route = function_exists('kitchen_station_item_route')
        ? kitchen_station_item_route($pdo, $restaurantId, $row, $stationList)
        : [
            'station_key' => (function_exists('kds_resolve_station_for_item')
                ? kds_resolve_station_for_item($row)
                : kds_normalize_station((string)($row['station_key'] ?? 'kitchen'))),
            'station_label' => '',
            'fallback_used' => false,
            'route_reason' => 'legacy',
        ];
    $station = kds_normalize_station((string)($route['station_key'] ?? 'kitchen'));
    $st = strtolower(trim((string)($row['station_status'] ?? '')));
    if (!in_array($st, ['new', 'accepted', 'cooking', 'ready', 'served'], true)) {
        $st = 'new';
    }
    $itemsByOrder[$oid][] = [
        'id' => (int)($row['id'] ?? 0),
        'menu_item_id' => isset($row['menu_item_id']) ? (int)$row['menu_item_id'] : null,
        'item_name' => (string)($row['item_name'] ?? 'Позиция'),
        'quantity' => max(1, (int)($row['quantity'] ?? 1)),
        'price' => (float)($row['price'] ?? 0),
        'station' => $station,
        'station_label' => (string)($route['station_label'] ?? kds_station_label_ru($station)),
        'station_status' => $st,
        'kds_status' => $st,
        'started_at' => $row['started_at'] ?? null,
        'ready_at' => $row['ready_at'] ?? null,
        'route_fallback' => !empty($route['fallback_used']),
        'route_reason' => (string)($route['route_reason'] ?? 'direct'),
    ];
}

$orderAggregate = [];
if ($orderIdsIn !== '') {
    $aggReadyExpr = "SUM(CASE WHEN {$stationStatusExpr} = 'ready' THEN 1 ELSE 0 END)";
    $aggSql = "
        SELECT
            oi.order_id,
            COUNT(*) AS total_cnt,
            {$aggReadyExpr} AS ready_cnt,
            COUNT(DISTINCT {$itemStationSqlExpr}) AS station_cnt
        FROM order_items oi
        LEFT JOIN menu_items mi ON mi.id = oi.menu_item_id
        WHERE oi.order_id IN ({$orderIdsIn})
        GROUP BY oi.order_id
    ";
    $stmtAgg = $pdo->query($aggSql);
    $aggRows = $stmtAgg ? $stmtAgg->fetchAll(PDO::FETCH_ASSOC) : [];
    foreach ($aggRows as $aggRow) {
        $oid = (int)($aggRow['order_id'] ?? 0);
        if ($oid <= 0) {
            continue;
        }
        $orderAggregate[$oid] = [
            'total_cnt' => (int)($aggRow['total_cnt'] ?? 0),
            'ready_cnt' => (int)($aggRow['ready_cnt'] ?? 0),
            'station_cnt' => max(1, (int)($aggRow['station_cnt'] ?? 1)),
        ];
    }
}

$waiterByOrder = [];
$waiterByTable = [];
if (waiter_calls_require_table(false)) {
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
    $orderType = function_exists('order_type_normalize')
        ? order_type_normalize((string)($o['order_type'] ?? ''), $tableId)
        : 'hall';
    $orderTypeLabel = function_exists('order_type_label')
        ? order_type_label((string)($o['order_type'] ?? ''), $tableId)
        : 'Зал';
    $paymentStatus = (string)($o['payment_status'] ?? 'unpaid');
    $paymentType = (string)($o['payment_type'] ?? '');
    $orderComment = trim((string)($o['comment'] ?? ''));
    $createdTs = strtotime((string)($o['created_at'] ?? ''));
    $sinceMinutes = $createdTs ? max(0, (int)floor(($now - $createdTs) / 60)) : 0;

    $countdownMeta = order_payment_timer_meta($o, $now);

    $aggOrder = $orderAggregate[$orderId] ?? [];
    $totalItemsCount = max(0, (int)($aggOrder['total_cnt'] ?? count($items)));
    $readyItemsCount = max(0, (int)($aggOrder['ready_cnt'] ?? 0));
    if ($totalItemsCount === 0 && !empty($items)) {
        $totalItemsCount = count($items);
        $readyItemsCount = 0;
        foreach ($items as $it) {
            if (($it['station_status'] ?? '') === 'ready') {
                $readyItemsCount++;
            }
        }
    }
    $partialReady = $readyItemsCount > 0 && $readyItemsCount < $totalItemsCount;
    $progressPercent = $totalItemsCount > 0 ? (int)floor(($readyItemsCount / $totalItemsCount) * 100) : 0;
    $splitStationCount = max(1, (int)($aggOrder['station_cnt'] ?? 1));

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
            if ($sst === 'ready' || $sst === 'served') $stationReady++;
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

        $tnRaw = (string)($o['table_name'] ?? '');
        $tnLabel = function_exists('qr_public_owner_order_table_label') ? qr_public_owner_order_table_label($tnRaw) : $tnRaw;
        if ($tnLabel === '') {
            $tnLabel = 'Стол #' . $tableId;
        }

        $ticket = [
            'ticket_id' => $orderId . ':' . $station,
            'order_id' => $orderId,
            'station' => $station,
            'station_label' => (string)($stationList[$station]['station_name'] ?? kds_station_label_ru($station)),
            'station_status' => $stationStatus,
            'table_id' => $tableId,
            'table_name' => $tnLabel,
            'order_type' => $orderType,
            'order_type_label' => $orderTypeLabel,
            'source_label' => function_exists('order_source_label')
                ? order_source_label($orderType, $tableId, $tnRaw)
                : (($orderType === 'hall') ? 'QR / Зал' : $orderTypeLabel),
            'comment' => $orderComment,
            'created_at' => $o['created_at'] ?? null,
            'minutes_since_created' => $sinceMinutes,
            'total_price' => isset($o['total_price']) && $o['total_price'] !== null && $o['total_price'] !== ''
                ? (float)$o['total_price']
                : (float)($o['total_amount'] ?? 0),
            'payment_type' => $paymentType,
            'payment_status' => $paymentStatus,
            'order_status' => $orderStatus,
            'countdown_seconds_remaining' => $countdownMeta['seconds_remaining'],
            'countdown_warning' => $countdownMeta['warning'],
            'countdown_expired' => $countdownMeta['expired'],
            'countdown_mmss' => $countdownMeta['mmss'],
            'ready_items_count' => $readyItemsCount,
            'total_items_count' => $totalItemsCount,
            'progress_percent' => $progressPercent,
            'partial_ready' => $partialReady,
            'station_ready_items_count' => $stationReady,
            'station_total_items_count' => count($stationItems),
            'station_partial_ready' => $stationReady > 0 && $stationReady < count($stationItems),
            'split_ticket' => $splitStationCount > 1,
            'split_station_count' => $splitStationCount,
            'station_item_lines' => count($stationItems),
            'items' => $stationItems,
            'station_prep_time_avg' => max(1, (int)($stationList[$station]['prep_time_avg'] ?? 15)),
            'has_waiter_call' => $hasWaiterCall,
            'waiter_call_id' => $waiterCallId,
            'vip_priority' => false,
            'priority' => [
                'waiter_call' => $hasWaiterCall,
                'payment_expired' => $countdownMeta['expired'],
                'payment_warning' => $countdownMeta['warning'],
                'age_minutes' => $sinceMinutes,
            ],
        ];

        $prepAvgMinutes = max(1, (int)($stationList[$station]['prep_time_avg'] ?? 15));
        $stationStartedTs = null;
        foreach ($stationItems as $si) {
            $startedRaw = trim((string)($si['started_at'] ?? ''));
            if ($startedRaw === '') {
                continue;
            }
            $startedTs = strtotime($startedRaw);
            if (!$startedTs) {
                continue;
            }
            if ($stationStartedTs === null || $startedTs < $stationStartedTs) {
                $stationStartedTs = $startedTs;
            }
        }
        $prepAnchorTs = $stationStartedTs ?: ($createdTs ?: $now);
        $elapsedPrepMinutes = max(0, (int)floor(($now - $prepAnchorTs) / 60));
        $expectedReadyTs = $prepAnchorTs + ($prepAvgMinutes * 60);
        $prepProgress = min(100, (int)round(($elapsedPrepMinutes / max(1, $prepAvgMinutes)) * 100));
        $ticket['prep_timer'] = [
            'expected_ready_at' => date('Y-m-d H:i:s', $expectedReadyTs),
            'elapsed_minutes' => $elapsedPrepMinutes,
            'expected_minutes' => $prepAvgMinutes,
            'progress_percent' => $prepProgress,
            'is_overdue' => $elapsedPrepMinutes > $prepAvgMinutes,
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

    $statusRank = ['new' => 0, 'accepted' => 1, 'cooking' => 2, 'ready' => 3, 'served' => 4];
    $as = $statusRank[$a['station_status'] ?? 'ready'] ?? 3;
    $bs = $statusRank[$b['station_status'] ?? 'ready'] ?? 3;
    if ($as !== $bs) return $as <=> $bs;

    $aAge = (int)($ap['age_minutes'] ?? 0);
    $bAge = (int)($bp['age_minutes'] ?? 0);
    return $bAge <=> $aAge;
});

$stationSummary = function_exists('kitchen_station_summary')
    ? kitchen_station_summary($tickets, $stationList)
    : [];
if ($stationSummary !== [] && function_exists('is_station_overloaded')) {
    foreach ($stationSummary as $sKey => &$sRow) {
        try {
            $ov = is_station_overloaded($pdo, $restaurantId, (string)$sKey);
            $sRow['overload'] = $ov;
            if (!empty($ov['overloaded'])) {
                $sRow['workload']['overloaded'] = true;
            }
        } catch (Throwable $e) {
            // keep response stable
        }
    }
    unset($sRow);
}
$stationAlerts = function_exists('kitchen_station_alerts')
    ? kitchen_station_alerts($stationSummary)
    : [];

$routingFallbackCount = 0;
foreach ($tickets as $ticket) {
    foreach ((array)($ticket['items'] ?? []) as $item) {
        if (!empty($item['route_fallback'])) {
            $routingFallbackCount++;
        }
    }
}
if ($routingFallbackCount > 0) {
    $stationAlerts[] = [
        'level' => 'warning',
        'label' => 'Routing fallback',
        'message' => 'Часть позиций ушла в fallback station: ' . $routingFallbackCount . '.',
        'station_key' => $stationFilter !== '' ? $stationFilter : 'kitchen',
    ];
}

echo json_encode([
    'success' => true,
    'station' => $stationFilter !== '' ? $stationFilter : null,
    'status' => $statusFilter,
    'stations' => array_values($stationList),
    'station_summary' => $stationSummary,
    'station_alerts' => $stationAlerts,
    'tickets' => $tickets,
], JSON_UNESCAPED_UNICODE);
