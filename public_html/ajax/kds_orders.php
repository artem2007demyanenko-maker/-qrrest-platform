<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/kds_helpers.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if (!function_exists('require_kitchen_access')) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'auth_unavailable'], JSON_UNESCAPED_UNICODE);
    exit;
}
require_kitchen_access();
if (!$currentRestaurant) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Restaurant context required'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!function_exists('db')) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'DB unavailable'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = db();
$restId = (int)$currentRestaurant['id'];
$staffRole = function_exists('current_user_restaurant_role')
    ? normalize_restaurant_role((string)(current_user_restaurant_role($restId) ?? ''))
    : '';
$barStationLocked = ($staffRole === 'bar');
$staffStation = function_exists('current_staff_station')
    ? (string)current_staff_station($restId)
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
    ? can_access_station($stationFilterRaw, $restId, $staffRole)
    : user_has_station_access($staffRole, $stationFilterRaw))) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'station_access_denied'], JSON_UNESCAPED_UNICODE);
    exit;
}
$stationList = function_exists('kitchen_station_list') ? kitchen_station_list($pdo, $restId) : [];
$allowedStations = $stationList !== [] ? array_keys($stationList) : kds_allowed_stations();

try {
    $noteCol = '';
    $paymentTypeCol = 'NULL AS payment_type';
    if (function_exists('db_column_exists')) {
        if (db_column_exists('orders', 'note')) {
            $noteCol = ', o.note AS order_note';
        } elseif (db_column_exists('orders', 'comment')) {
            $noteCol = ', o.comment AS order_note';
        } elseif (db_column_exists('orders', 'customer_note')) {
            $noteCol = ', o.customer_note AS order_note';
        }
        if (db_column_exists('orders', 'payment_type')) {
            $paymentTypeCol = 'o.payment_type';
        } elseif (db_column_exists('orders', 'payment_method')) {
            $paymentTypeCol = 'o.payment_method AS payment_type';
        }
    }

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

    $hasStationCol = false;
    $hasMenuStationCol = false;
    $hasProdStationCol = false;
    $hasOrderItemProdStationCol = false;
    $hasOrderItemNameCol = false;
    $hasOrderItemQuantityCol = false;
    $hasOrderItemQtyCol = false;
    $hasOrderItemKdsStatusCol = false;
    $hasOrderItemStationStatusCol = false;
    try {
        $chk = $pdo->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'menu_items' AND COLUMN_NAME = 'kitchen_station' LIMIT 1");
        $hasStationCol = $chk && $chk->fetchColumn();
    } catch (Throwable $e) {}
    try {
        $chk = $pdo->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'menu_items' AND COLUMN_NAME = 'station' LIMIT 1");
        $hasMenuStationCol = $chk && $chk->fetchColumn();
    } catch (Throwable $e) {}
    try {
        $chk = $pdo->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'menu_items' AND COLUMN_NAME = 'production_station' LIMIT 1");
        $hasProdStationCol = $chk && $chk->fetchColumn();
    } catch (Throwable $e) {}
    try {
        $chk = $pdo->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_items' AND COLUMN_NAME = 'production_station' LIMIT 1");
        $hasOrderItemProdStationCol = $chk && $chk->fetchColumn();
    } catch (Throwable $e) {}
    try {
        $chk = $pdo->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_items' AND COLUMN_NAME = 'item_name' LIMIT 1");
        $hasOrderItemNameCol = $chk && $chk->fetchColumn();
    } catch (Throwable $e) {}
    try {
        $chk = $pdo->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_items' AND COLUMN_NAME = 'quantity' LIMIT 1");
        $hasOrderItemQuantityCol = $chk && $chk->fetchColumn();
    } catch (Throwable $e) {}
    try {
        $chk = $pdo->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_items' AND COLUMN_NAME = 'qty' LIMIT 1");
        $hasOrderItemQtyCol = $chk && $chk->fetchColumn();
    } catch (Throwable $e) {}
    try {
        $chk = $pdo->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_items' AND COLUMN_NAME = 'kds_status' LIMIT 1");
        $hasOrderItemKdsStatusCol = $chk && $chk->fetchColumn();
    } catch (Throwable $e) {}
    try {
        $chk = $pdo->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_items' AND COLUMN_NAME = 'station_status' LIMIT 1");
        $hasOrderItemStationStatusCol = $chk && $chk->fetchColumn();
    } catch (Throwable $e) {}

    $readyHideMinutes = function_exists('kds_ready_auto_hide_minutes') ? kds_ready_auto_hide_minutes() : 240;
    $activeHideMinutes = function_exists('kds_active_auto_hide_minutes') ? kds_active_auto_hide_minutes() : 1440;
    $stmt = $pdo->prepare("
        SELECT
            o.id,
            o.restaurant_id,
            o.table_id,
            o.order_status,
            {$paymentTypeCol},
            o.created_at
            {$noteCol},
            t.name AS table_name
        FROM orders o
        LEFT JOIN tables t ON t.id = o.table_id AND t.restaurant_id = o.restaurant_id
        WHERE o.restaurant_id = :rest
          AND LOWER(COALESCE(TRIM(o.order_status), '')) IN ('new','pending','accepted','preparing','cooking','ready')
          AND o.created_at >= DATE_SUB(NOW(), INTERVAL {$activeHideMinutes} MINUTE)
          AND NOT (LOWER(COALESCE(TRIM(o.order_status), '')) = 'ready' AND o.created_at < DATE_SUB(NOW(), INTERVAL {$readyHideMinutes} MINUTE))
        ORDER BY o.created_at ASC
        LIMIT 200
    ");
    $stmt->execute([':rest' => $restId]);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $result = [];
    if ($orders) {
        $orderIds = array_map(static function (array $o): int { return (int)$o['id']; }, $orders);
        $in = implode(',', array_map('intval', $orderIds));

        $itemsByOrder = [];
        $itemsByStationByOrder = [];
        if ($in !== '') {
            $itemStationExpr = function_exists('kds_item_station_sql_expr')
                ? kds_item_station_sql_expr($pdo, 'oi', 'mi')
                : "CASE
                    WHEN LOWER(COALESCE(NULLIF(TRIM(" . ($hasOrderItemProdStationCol ? "oi.production_station" : "''") . "), ''), NULLIF(TRIM(" . ($hasProdStationCol ? "mi.production_station" : "''") . "), ''), 'kitchen')) IN ('', 'hot', 'kitchen') THEN 'kitchen'
                    ELSE LOWER(COALESCE(NULLIF(TRIM(" . ($hasOrderItemProdStationCol ? "oi.production_station" : "''") . "), ''), NULLIF(TRIM(" . ($hasProdStationCol ? "mi.production_station" : "''") . "), ''), 'kitchen'))
                END";
            if ($stationFilter !== '') {
                $orderIdsForStationSql = "
                    SELECT DISTINCT oi.order_id
                    FROM order_items oi
                    LEFT JOIN menu_items mi ON mi.id = oi.menu_item_id
                    WHERE oi.order_id IN ($in)
                      AND {$itemStationExpr} = :station_filter
                ";
                $stmtStationOrders = $pdo->prepare($orderIdsForStationSql);
                $stmtStationOrders->execute([':station_filter' => $stationFilter]);
                $stationOrderIds = array_values(array_filter(array_map('intval', $stmtStationOrders->fetchAll(PDO::FETCH_COLUMN))));
                if ($stationOrderIds === []) {
                    $orders = [];
                    $in = '';
                } else {
                    $allowedOrderSet = array_fill_keys($stationOrderIds, true);
                    $orders = array_values(array_filter($orders, static function (array $row) use ($allowedOrderSet): bool {
                        return isset($allowedOrderSet[(int)($row['id'] ?? 0)]);
                    }));
                    $orderIds = $stationOrderIds;
                    $in = implode(',', array_map('intval', $orderIds));
                }
            }
        }
        if ($in !== '') {
            $menuNameExpr = $hasOrderItemNameCol
                ? "COALESCE(NULLIF(TRIM(oi.item_name), ''), mi.name) AS menu_name"
                : 'mi.name AS menu_name';
            $stationExpr = (function_exists('kds_menu_station_expr') ? kds_menu_station_expr($pdo, 'mi') : "'kitchen'") . " AS kitchen_station";
            $oiProdExpr = "{$itemStationExpr} AS production_station";
            if ($hasOrderItemKdsStatusCol && $hasOrderItemStationStatusCol) {
                $itemStatusExpr = "LOWER(COALESCE(NULLIF(TRIM(oi.kds_status), ''), NULLIF(TRIM(oi.station_status), ''), 'new')) AS kds_status";
            } elseif ($hasOrderItemKdsStatusCol) {
                $itemStatusExpr = "LOWER(COALESCE(NULLIF(TRIM(oi.kds_status), ''), 'new')) AS kds_status";
            } elseif ($hasOrderItemStationStatusCol) {
                $itemStatusExpr = "LOWER(COALESCE(NULLIF(TRIM(oi.station_status), ''), 'new')) AS kds_status";
            } else {
                $itemStatusExpr = "'new' AS kds_status";
            }
            if ($hasOrderItemQuantityCol && $hasOrderItemQtyCol) {
                $qtyExpr = "COALESCE(NULLIF(oi.quantity, 0), oi.qty, 1) AS quantity";
            } elseif ($hasOrderItemQuantityCol) {
                $qtyExpr = "COALESCE(oi.quantity, 1) AS quantity";
            } elseif ($hasOrderItemQtyCol) {
                $qtyExpr = "COALESCE(oi.qty, 1) AS quantity";
            } else {
                $qtyExpr = "1 AS quantity";
            }

            $itemsSql = "
                SELECT
                    oi.order_id,
                    {$qtyExpr},
                    {$menuNameExpr},
                    {$oiProdExpr},
                    {$itemStatusExpr},
                    {$stationExpr}
                FROM order_items oi
                LEFT JOIN menu_items mi
                    ON mi.id = oi.menu_item_id
                   AND mi.restaurant_id = :rest
                WHERE oi.order_id IN ($in)
                " . ($stationFilter !== '' ? " AND {$itemStationExpr} = :station_filter" : "") . "
                ORDER BY oi.id ASC
            ";

            $itemsStmt = $pdo->prepare($itemsSql);
            $itemsParams = [':rest' => $restId];
            if ($stationFilter !== '') {
                $itemsParams[':station_filter'] = $stationFilter;
            }
            $itemsStmt->execute($itemsParams);
            foreach ($itemsStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $oid = (int)$r['order_id'];
                $route = function_exists('kitchen_station_item_route')
                    ? kitchen_station_item_route($pdo, $restId, [
                        'production_station' => (string)($r['production_station'] ?? ''),
                        'station_key' => (string)($r['kitchen_station'] ?? 'kitchen'),
                        'item_name' => (string)($r['menu_name'] ?? ''),
                    ], $stationList)
                    : [
                        'station_key' => (function_exists('kds_resolve_station_for_item')
                            ? kds_resolve_station_for_item([
                                'station_key' => (string)($r['kitchen_station'] ?? 'kitchen'),
                                'item_name' => (string)($r['menu_name'] ?? ''),
                            ])
                            : kds_normalize_station((string)($r['kitchen_station'] ?? 'kitchen'))),
                    ];
                $station = kds_normalize_station((string)($route['station_key'] ?? 'kitchen'));

                $item = [
                    'menu_name' => (string)($r['menu_name'] ?? 'Позиция'),
                    'quantity' => (int)($r['quantity'] ?? 1),
                    'station' => $station,
                    'kds_status' => (string)($r['kds_status'] ?? 'new'),
                ];

                $itemsByOrder[$oid][] = $item;
                if (!isset($itemsByStationByOrder[$oid])) $itemsByStationByOrder[$oid] = [];
                if (!isset($itemsByStationByOrder[$oid][$station])) $itemsByStationByOrder[$oid][$station] = [];
                $itemsByStationByOrder[$oid][$station][] = $item;
            }
        }

        $now = time();
        foreach ($orders as $o) {
            $statusRaw = (string)($o['order_status'] ?? 'new');
            $status = $statusRaw === 'delivered' ? 'completed' : $statusRaw;
            $createdTs = strtotime((string)($o['created_at'] ?? ''));
            $sinceMinutes = $createdTs ? max(0, (int)floor(($now - $createdTs) / 60)) : 0;

            $oid = (int)$o['id'];
            $itemsAll = $itemsByOrder[$oid] ?? [];

            if ($stationFilter !== '') {
                // Filter cards by selected station.
                if (empty($itemsByStationByOrder[$oid][$stationFilter] ?? [])) {
                    continue;
                }
                // Reduce payload to selected station only.
                $itemsAll = $itemsByStationByOrder[$oid][$stationFilter];
                $itemsByStationByOrder[$oid] = [$stationFilter => $itemsAll];
            } else {
                if (!isset($itemsByStationByOrder[$oid])) $itemsByStationByOrder[$oid] = [];
            }

            $rawTn = (string)($o['table_name'] ?? '');
            $labelTn = function_exists('qr_public_owner_order_table_label') ? qr_public_owner_order_table_label($rawTn) : $rawTn;
            if ($labelTn === '') {
                $labelTn = 'Стол #' . (int)($o['table_id'] ?? 0);
            }

            $result[] = [
                'id' => $oid,
                'table_name' => $labelTn,
                'status' => $status,
                'created_at' => (string)($o['created_at'] ?? ''),
                'since_minutes' => $sinceMinutes,
                'note' => trim((string)($o['order_note'] ?? '')),
                'items' => $itemsAll,
                'items_by_station' => $itemsByStationByOrder[$oid] ?? [],
            ];
        }
    }

    echo json_encode(['success' => true, 'orders' => $result], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('kds_orders rest_id=' . $restId . ' ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Ошибка загрузки KDS'], JSON_UNESCAPED_UNICODE);
}
