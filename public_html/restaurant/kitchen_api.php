<?php
/**
 * Kitchen Display API: orders with items and kitchen_station for KDS.
 * Optional ?station=BAR|HOT|COLD|DESSERT to filter orders that have at least one item in that station.
 */

require_once __DIR__ . '/../../app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

require_login();
require_current_restaurant();
require_restaurant_role((int)($currentRestaurant['id'] ?? 0), ['owner', 'admin', 'staff']);

$pdo = db();
$restId = (int)$currentRestaurant['id'];

$stationFilter = isset($_GET['station']) ? strtoupper(trim((string)$_GET['station'])) : '';
$allowedStations = ['BAR', 'HOT', 'COLD', 'DESSERT'];
if ($stationFilter !== '' && !in_array($stationFilter, $allowedStations, true)) {
    $stationFilter = '';
}

$stmt = $pdo->prepare("
    SELECT o.id, o.order_status, o.created_at, o.table_id,
           t.name AS table_name
    FROM orders o
    LEFT JOIN tables t ON t.id = o.table_id AND t.restaurant_id = o.restaurant_id
    WHERE o.restaurant_id = :rest
      AND o.order_status IN ('new','accepted','cooking','ready','delivered')
    ORDER BY o.created_at ASC
    LIMIT 150
");
$stmt->execute(['rest' => $restId]);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

$orderIds = array_column($orders, 'id');
$itemsByOrder = [];
$orderNoteByOrder = [];

$hasStationCol = false;
try {
    $chk = $pdo->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'menu_items' AND COLUMN_NAME = 'kitchen_station' LIMIT 1");
    $hasStationCol = $chk && $chk->fetchColumn();
} catch (Throwable $e) {}

if (!empty($orderIds)) {
    $in = implode(',', array_map('intval', $orderIds));
    if ($hasStationCol) {
        $itemsSql = "
            SELECT oi.order_id, oi.menu_item_id, oi.item_name, oi.quantity, oi.price,
                   COALESCE(NULLIF(TRIM(mi.kitchen_station), ''), 'HOT') AS kitchen_station
            FROM order_items oi
            LEFT JOIN menu_items mi ON mi.id = oi.menu_item_id AND mi.restaurant_id = ?
            WHERE oi.order_id IN ($in)
            ORDER BY oi.order_id, oi.id
        ";
    } else {
        $itemsSql = "
            SELECT oi.order_id, oi.menu_item_id, oi.item_name, oi.quantity, oi.price, 'HOT' AS kitchen_station
            FROM order_items oi
            LEFT JOIN menu_items mi ON mi.id = oi.menu_item_id AND mi.restaurant_id = ?
            WHERE oi.order_id IN ($in)
            ORDER BY oi.order_id, oi.id
        ";
    }
    $itemsStmt = $pdo->prepare($itemsSql);
    $itemsStmt->execute([$restId]);
    $itemRows = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($itemRows as $row) {
        $oid = (int)$row['order_id'];
        $station = in_array($row['kitchen_station'], $allowedStations, true) ? $row['kitchen_station'] : 'HOT';
        if (!isset($itemsByOrder[$oid])) {
            $itemsByOrder[$oid] = [];
        }
        $itemsByOrder[$oid][] = [
            'menu_name' => $row['item_name'],
            'quantity'  => (int)$row['quantity'],
            'station'   => $station,
        ];
    }
}

$now = time();
$result = [];

foreach ($orders as $o) {
    $oid = (int)$o['id'];
    $items = $itemsByOrder[$oid] ?? [];

    if ($stationFilter !== '') {
        $hasStation = false;
        foreach ($items as $it) {
            if ($it['station'] === $stationFilter) {
                $hasStation = true;
                break;
            }
        }
        if (!$hasStation) {
            continue;
        }
    }

    $createdTs = strtotime($o['created_at'] ?? '');
    $sinceMinutes = $createdTs ? max(0, (int)floor(($now - $createdTs) / 60)) : 0;

    $result[] = [
        'id'               => $oid,
        'table_name'       => $o['table_name'] ?? ('Table ' . (int)$o['table_id']),
        'order_status'     => $o['order_status'],
        'created_at'       => $o['created_at'],
        'created_at_short' => date('H:i', $createdTs ?: time()),
        'since_minutes'    => $sinceMinutes,
        'items'            => $items,
        'note'             => $orderNoteByOrder[$oid] ?? null,
    ];
}

echo json_encode([
    'success' => true,
    'orders'  => $result,
], JSON_UNESCAPED_UNICODE);
