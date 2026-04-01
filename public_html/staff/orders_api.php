<?php

require_once __DIR__ . '/../../app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

require_login();

if (!$currentRestaurant) {
    echo json_encode(['success' => false, 'message' => 'Контекст ресторана не найден'], JSON_UNESCAPED_UNICODE);
    exit;
}


if (!user_has_restaurant_role((int)$currentRestaurant['id'], ['staff','admin','owner'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Доступ запрещен'], JSON_UNESCAPED_UNICODE);
    exit;
}

$tableId = isset($_GET['table_id']) ? (int)$_GET['table_id'] : 0;

$pdo = db();

if (file_exists(__DIR__ . '/../../app/kds_helpers.php')) {
    require_once __DIR__ . '/../../app/kds_helpers.php';
}
if (file_exists(__DIR__ . '/../../app/schema_guard.php')) {
    require_once __DIR__ . '/../../app/schema_guard.php';
}

$sql = "
    SELECT o.*, t.name AS table_name
    FROM orders o
    LEFT JOIN tables t ON t.id = o.table_id
    WHERE o.restaurant_id = :rest
";
if ($tableId > 0) {
    $sql .= " AND o.table_id = :table ";
}
$sql .= "
    ORDER BY o.created_at DESC
    LIMIT 100
";
$stmt = $pdo->prepare($sql);
$params = ['rest' => $currentRestaurant['id']];
if ($tableId > 0) {
    $params['table'] = $tableId;
}
$stmt->execute($params);
$orders = $stmt->fetchAll();

$result = [];

if ($orders) {
    $orderIds = array_values(array_filter(array_map('intval', array_column($orders, 'id'))));
    $rows = [];
    if ($orderIds !== []) {
        $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
        $stmt = $pdo->prepare("
            SELECT oi.*, mi.name AS menu_name
            FROM order_items oi
            LEFT JOIN menu_items mi ON mi.id = oi.menu_item_id
            WHERE oi.order_id IN ($placeholders)
            ORDER BY oi.id ASC
        ");
        $stmt->execute($orderIds);
        $rows = $stmt->fetchAll();
    }

    $itemsByOrder = [];
    foreach ($rows as $row) {
        $itemsByOrder[$row['order_id']][] = [
            'menu_name' => (string)($row['menu_name'] ?? ''),
            'quantity'  => (int)($row['quantity'] ?? 0),
            'price'     => (float)($row['price'] ?? 0),
        ];
    }

    $progressByOrder = [];
    if (function_exists('db_column_exists') && db_column_exists('order_items', 'station_status')) {
        $stmtProg = $pdo->prepare("
            SELECT
                oi.order_id AS oid,
                COUNT(*) AS total_cnt,
                SUM(CASE WHEN oi.station_status = 'ready' THEN 1 ELSE 0 END) AS ready_cnt
            FROM order_items oi
            WHERE oi.order_id IN ($placeholders)
            GROUP BY oi.order_id
        ");
        $stmtProg->execute($orderIds);
        foreach ($stmtProg->fetchAll(PDO::FETCH_ASSOC) as $pr) {
            $oid = (int)($pr['oid'] ?? 0);
            $totalCnt = (int)($pr['total_cnt'] ?? 0);
            $readyCnt = (int)($pr['ready_cnt'] ?? 0);
            $progressByOrder[$oid] = [
                'total_items_count' => $totalCnt,
                'ready_items_count' => $readyCnt,
                'progress_percent' => $totalCnt > 0 ? (int)floor(($readyCnt / $totalCnt) * 100) : 0,
                'partial_ready' => $readyCnt > 0 && $readyCnt < $totalCnt,
            ];
        }
    }

    $waiterCallByOrder = [];
    if (function_exists('db_table_exists') && db_table_exists('waiter_calls')) {
        $stmtCalls = $pdo->prepare("
            SELECT order_id
            FROM waiter_calls
            WHERE restaurant_id = :rid
              AND status = 'active'
              AND resolved_at IS NULL
              AND order_id IS NOT NULL
        ");
        $stmtCalls->execute([':rid' => (int)$currentRestaurant['id']]);
        foreach ($stmtCalls->fetchAll(PDO::FETCH_ASSOC) as $cr) {
            $oid = (int)($cr['order_id'] ?? 0);
            if ($oid > 0) {
                $waiterCallByOrder[$oid] = true;
            }
        }
    }

    $now = time();
    foreach ($orders as $o) {
        $createdTs = strtotime($o['created_at'] ?? '');
        $sinceMinutes = $createdTs ? max(0, (int)floor(($now - $createdTs) / 60)) : 0;
        $createdAtShort = !empty($o['created_at']) ? date('H:i', strtotime((string)$o['created_at'])) : '';

        // Таймер ожидания оплаты для офлайн-оплат (cash/card_later/pay_later) — 10 минут после создания заказа.
        $offlinePayTypes = ['cash', 'card_later', 'pay_later'];
        $paymentType = (string)($o['payment_type'] ?? '');
        $paymentStatus = (string)($o['payment_status'] ?? 'unpaid');
        $countdownSecondsRemaining = null;
        $countdownExpired = false;
        $countdownWarning = false;
        $countdownMmss = null;
        if ($createdTs && $paymentStatus === 'unpaid' && in_array($paymentType, $offlinePayTypes, true)) {
            $elapsedSeconds = max(0, $now - $createdTs);
            $remaining = (10 * 60) - $elapsedSeconds;
            $countdownSecondsRemaining = max(0, (int)$remaining);
            $countdownExpired = $remaining <= 0;
            $countdownWarning = !$countdownExpired && $countdownSecondsRemaining <= (3 * 60);
            $countdownMmss = gmdate('i:s', (int)$countdownSecondsRemaining);
        }
        $result[] = [
            'id'              => (int)$o['id'],
            'table_name'      => (string)($o['table_name'] ?? ''),
            'table_id'        => (int)($o['table_id'] ?? 0),
            'total_price'     => (float)$o['total_price'],
            'payment_type'    => (string)($o['payment_type'] ?? ''),
            'payment_status'  => (string)($o['payment_status'] ?? ''),
            'order_status'    => (string)($o['order_status'] ?? ''),
            'created_at_short'=> $createdAtShort,
            'since_minutes'   => $sinceMinutes,
            'countdown_seconds_remaining' => $countdownSecondsRemaining,
            'countdown_expired' => $countdownExpired,
            'countdown_warning' => $countdownWarning,
            'countdown_mmss' => $countdownMmss,
            'ready_items_count' => (int)($progressByOrder[(int)$o['id']]['ready_items_count'] ?? 0),
            'total_items_count' => (int)($progressByOrder[(int)$o['id']]['total_items_count'] ?? 0),
            'progress_percent' => (int)($progressByOrder[(int)$o['id']]['progress_percent'] ?? 0),
            'partial_ready' => (bool)($progressByOrder[(int)$o['id']]['partial_ready'] ?? false),
            'has_waiter_call' => !empty($waiterCallByOrder[(int)$o['id']]),
            'items'           => $itemsByOrder[$o['id']] ?? [],
        ];
    }
}

echo json_encode([
    'success' => true,
    'orders'  => $result,
], JSON_UNESCAPED_UNICODE);
