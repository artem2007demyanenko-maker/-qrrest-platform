<?php

require_once __DIR__ . '/../../app/bootstrap.php';

require_login();

header('Content-Type: application/json; charset=utf-8');

if (!$currentRestaurant) {
    echo json_encode([
        'success' => false,
        'message' => 'Контекст ресторана не найден',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!function_exists('require_restaurant_role')) {
    echo json_encode([
        'success' => false,
        'message' => 'Ошибка проверки доступа',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$restaurantId = (int)($currentRestaurant['id'] ?? 0);
if ($restaurantId <= 0) {
    echo json_encode([
        'success' => false,
        'message' => 'Некорректный ресторан',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

require_restaurant_role($restaurantId, ['staff', 'admin', 'owner']);

$pdo = db();
if (!$pdo instanceof PDO) {
    echo json_encode([
        'success' => false,
        'message' => 'Нет соединения с БД',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (file_exists(__DIR__ . '/../../app/schema_guard.php')) {
    require_once __DIR__ . '/../../app/schema_guard.php';
}

/**
 * Ensure waiter_calls table exists (safe runtime preflight).
 */
function fp_waiter_calls_ensure_table(PDO $pdo): void
{
    if (function_exists('db_table_exists') && db_table_exists('waiter_calls')) {
        return;
    }

    // Best-effort: create if missing.
    // If DB user lacks privileges, feature will be disabled gracefully by returning empty calls.
    try {
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
    } catch (Throwable $e) {
        // ignore - table may be absent due to permissions
    }
}

fp_waiter_calls_ensure_table($pdo);

$now = time();
$offlineTypes = ['cash', 'card_later', 'pay_later'];

// 1) Fetch latest non-final / non-canceled order per table.
// Consider as "occupied" if:
// - order_status != canceled
// - and NOT (delivered + paid)  (delivered+paid should be treated as free)
$stmtOrders = $pdo->prepare("
    SELECT
        o.id,
        o.table_id,
        o.order_status,
        o.payment_status,
        o.payment_type,
        o.total_price,
        o.total_amount,
        o.created_at
    FROM orders o
    WHERE o.restaurant_id = :rid
      AND o.order_status NOT IN ('canceled', 'cancelled')
    ORDER BY o.created_at DESC
    LIMIT 500
");
$stmtOrders->execute([':rid' => $restaurantId]);
$orders = $stmtOrders->fetchAll(PDO::FETCH_ASSOC);

$orderByTable = [];
foreach ($orders as $o) {
    $tid = (int)($o['table_id'] ?? 0);
    if ($tid <= 0) continue;

    if (!isset($orderByTable[$tid])) {
        $orderStatus = (string)($o['order_status'] ?? '');
        $paymentStatus = (string)($o['payment_status'] ?? '');

        // If already delivered and paid, treat table as free.
        if ($orderStatus === 'delivered' && $paymentStatus === 'paid') {
            continue;
        }

        $createdTs = strtotime((string)($o['created_at'] ?? ''));
        $sinceMinutes = $createdTs ? max(0, (int)floor(($now - $createdTs) / 60)) : 0;

        $paymentType = (string)($o['payment_type'] ?? '');
        $paymentStatusNorm = $paymentStatus !== '' ? $paymentStatus : 'unpaid';

        $countdownSecondsRemaining = null;
        $countdownExpired = false;
        $countdownWarning = false;

        if ($paymentStatusNorm === 'unpaid' && in_array($paymentType, $offlineTypes, true)) {
            $createdTs2 = $createdTs ?: 0;
            $elapsedSeconds = max(0, $now - $createdTs2);
            $remaining = (10 * 60) - $elapsedSeconds;
            $countdownSecondsRemaining = max(0, (int)$remaining);
            $countdownExpired = $countdownSecondsRemaining <= 0;
            $countdownWarning = !$countdownExpired && $countdownSecondsRemaining <= (3 * 60);
        }

        $createdAtShort = '';
        if (!empty($o['created_at'])) {
            $createdAtShort = date('H:i', strtotime((string)$o['created_at']));
        }

        $total = (isset($o['total_price']) && $o['total_price'] !== null && $o['total_price'] !== '') ? (float)$o['total_price'] : (float)($o['total_amount'] ?? 0);

        $orderByTable[$tid] = [
            'id' => (int)($o['id'] ?? 0),
            'order_status' => (string)($o['order_status'] ?? ''),
            'payment_status' => $paymentStatusNorm,
            'payment_type' => $paymentType,
            'total_price' => $total,
            'created_at_short' => $createdAtShort,
            'created_at' => $o['created_at'] ?? null,
            'since_minutes' => $sinceMinutes,
            'countdown_seconds_remaining' => $countdownSecondsRemaining,
            'countdown_expired' => $countdownExpired,
            'countdown_warning' => $countdownWarning,
            'countdown_mmss' => $countdownSecondsRemaining !== null ? gmdate('i:s', $countdownSecondsRemaining) : null,
        ];
    }
}

// 1.1) Add order progress (ready/total/partial) when station_status exists.
if ($orderByTable) {
    $orderIds = [];
    foreach ($orderByTable as $ob) {
        $oid = (int)($ob['id'] ?? 0);
        if ($oid > 0) {
            $orderIds[] = $oid;
        }
    }
    $orderIds = array_values(array_unique($orderIds));
    if ($orderIds && function_exists('db_column_exists') && db_column_exists('order_items', 'station_status')) {
        $in = implode(',', array_map('intval', $orderIds));
        $stmtProg = $pdo->query("
            SELECT
                oi.order_id AS oid,
                COUNT(*) AS total_cnt,
                SUM(CASE WHEN oi.station_status = 'ready' THEN 1 ELSE 0 END) AS ready_cnt
            FROM order_items oi
            WHERE oi.order_id IN ({$in})
            GROUP BY oi.order_id
        ");
        $progressByOrder = [];
        foreach (($stmtProg ? $stmtProg->fetchAll(PDO::FETCH_ASSOC) : []) as $pr) {
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

        foreach ($orderByTable as $tid => $od) {
            $oid = (int)($od['id'] ?? 0);
            $pr = $progressByOrder[$oid] ?? [
                'total_items_count' => 0,
                'ready_items_count' => 0,
                'progress_percent' => 0,
                'partial_ready' => false,
            ];
            $orderByTable[$tid]['total_items_count'] = (int)$pr['total_items_count'];
            $orderByTable[$tid]['ready_items_count'] = (int)$pr['ready_items_count'];
            $orderByTable[$tid]['progress_percent'] = (int)$pr['progress_percent'];
            $orderByTable[$tid]['partial_ready'] = (bool)$pr['partial_ready'];
        }
    } else {
        foreach ($orderByTable as $tid => $od) {
            $orderByTable[$tid]['total_items_count'] = 0;
            $orderByTable[$tid]['ready_items_count'] = 0;
            $orderByTable[$tid]['progress_percent'] = 0;
            $orderByTable[$tid]['partial_ready'] = false;
        }
    }
}

// 2) Fetch active waiter calls by table.
$callsByTable = [];
$stmtCalls = $pdo->prepare("
    SELECT id, restaurant_id, table_id, order_id, status, created_at, resolved_at
    FROM waiter_calls
    WHERE restaurant_id = :rid
      AND status = 'active'
      AND resolved_at IS NULL
    ORDER BY created_at DESC
");
$stmtCalls->execute([':rid' => $restaurantId]);
$callRows = $stmtCalls->fetchAll(PDO::FETCH_ASSOC);

foreach ($callRows as $cr) {
    $tid = (int)($cr['table_id'] ?? 0);
    if ($tid <= 0) continue;
    if (isset($callsByTable[$tid])) continue; // keep latest

    $createdTs = strtotime((string)($cr['created_at'] ?? ''));
    $sinceMinutes = $createdTs ? max(0, (int)floor(($now - $createdTs) / 60)) : 0;

    $callsByTable[$tid] = [
        'id' => (int)($cr['id'] ?? 0),
        'order_id' => isset($cr['order_id']) ? (int)$cr['order_id'] : null,
        'created_at' => $cr['created_at'] ?? null,
        'since_minutes' => $sinceMinutes,
    ];
}

// 3) Load all tables and build response.
$stmtTables = $pdo->prepare("
    SELECT id, name
    FROM tables
    WHERE restaurant_id = :rid
    ORDER BY id ASC
");
$stmtTables->execute([':rid' => $restaurantId]);
$tables = $stmtTables->fetchAll(PDO::FETCH_ASSOC);

$byTable = [];
foreach ($tables as $t) {
    $tid = (int)($t['id'] ?? 0);
    if ($tid <= 0) continue;

    $byTable[$tid] = [
        'table_id' => $tid,
        'table_name' => (string)($t['name'] ?? ''),
        'order' => $orderByTable[$tid] ?? null,
        'waiter_call' => $callsByTable[$tid] ?? null,
    ];
}

echo json_encode([
    'success' => true,
    'by_table' => $byTable,
], JSON_UNESCAPED_UNICODE);

