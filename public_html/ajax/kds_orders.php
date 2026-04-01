<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

require_login();

if (!$currentRestaurant) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Restaurant context required'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_restaurant_role((int)$currentRestaurant['id'], ['staff', 'admin', 'owner']);

if (!function_exists('db')) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'DB unavailable'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = db();
$restId = (int)$currentRestaurant['id'];

try {
    $noteCol = '';
    if (function_exists('db_column_exists')) {
        if (db_column_exists('orders', 'note')) {
            $noteCol = ', o.note AS order_note';
        } elseif (db_column_exists('orders', 'comment')) {
            $noteCol = ', o.comment AS order_note';
        } elseif (db_column_exists('orders', 'customer_note')) {
            $noteCol = ', o.customer_note AS order_note';
        }
    }

    $stationFilter = isset($_GET['station']) ? strtoupper(trim((string)$_GET['station'])) : '';
    $allowedStations = ['BAR', 'HOT', 'COLD', 'DESSERT'];
    if ($stationFilter !== '' && !in_array($stationFilter, $allowedStations, true)) {
        $stationFilter = '';
    }

    $hasStationCol = false;
    $hasOrderItemNameCol = false;
    try {
        $chk = $pdo->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'menu_items' AND COLUMN_NAME = 'kitchen_station' LIMIT 1");
        $hasStationCol = $chk && $chk->fetchColumn();
    } catch (Throwable $e) {}
    try {
        $chk = $pdo->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_items' AND COLUMN_NAME = 'item_name' LIMIT 1");
        $hasOrderItemNameCol = $chk && $chk->fetchColumn();
    } catch (Throwable $e) {}

    $stmt = $pdo->prepare("
        SELECT
            o.id,
            o.restaurant_id,
            o.table_id,
            o.order_status,
            o.created_at
            {$noteCol},
            t.name AS table_name
        FROM orders o
        LEFT JOIN tables t ON t.id = o.table_id AND t.restaurant_id = o.restaurant_id
        WHERE o.restaurant_id = :rest
          AND o.order_status IN ('new','accepted','cooking','ready')
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
            $menuNameExpr = $hasOrderItemNameCol
                ? "COALESCE(NULLIF(TRIM(oi.item_name), ''), mi.name) AS menu_name"
                : 'mi.name AS menu_name';
            $stationExpr = $hasStationCol ? "COALESCE(NULLIF(TRIM(mi.kitchen_station), ''), 'HOT') AS kitchen_station" : "'HOT' AS kitchen_station";

            $itemsSql = "
                SELECT
                    oi.order_id,
                    oi.quantity,
                    {$menuNameExpr},
                    {$stationExpr}
                FROM order_items oi
                LEFT JOIN menu_items mi
                    ON mi.id = oi.menu_item_id
                   AND mi.restaurant_id = :rest
                WHERE oi.order_id IN ($in)
                ORDER BY oi.id ASC
            ";

            $itemsStmt = $pdo->prepare($itemsSql);
            $itemsStmt->execute([':rest' => $restId]);
            foreach ($itemsStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $oid = (int)$r['order_id'];
                $station = (string)($r['kitchen_station'] ?? 'HOT');
                $station = in_array($station, $allowedStations, true) ? $station : 'HOT';

                $item = [
                    'menu_name' => (string)($r['menu_name'] ?? 'Позиция'),
                    'quantity' => (int)($r['quantity'] ?? 1),
                    'station' => $station,
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

            $result[] = [
                'id' => $oid,
                'table_name' => (string)($o['table_name'] ?? ('Стол #' . (int)($o['table_id'] ?? 0))),
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

