<?php

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/order_payment_runtime.php';
require_once __DIR__ . '/../../app/waiter_calls.php';
if (file_exists(__DIR__ . '/../../app/runtime_schema_bootstrap.php')) {
    require_once __DIR__ . '/../../app/runtime_schema_bootstrap.php';
}

header('Content-Type: application/json; charset=utf-8');
$floorplanRole = function_exists('require_staff_restaurant_access')
    ? require_staff_restaurant_access()
    : null;
if (!is_string($floorplanRole) || !in_array($floorplanRole, ['owner', 'admin', 'waiter', 'staff'], true)) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Access denied',
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

$pdo = db();
if (!$pdo instanceof PDO) {
    echo json_encode([
        'success' => false,
        'message' => 'Нет соединения с БД',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
if (function_exists('runtime_schema_ensure_table_reservations')) {
    runtime_schema_ensure_table_reservations($pdo);
}

$now = time();
$orderPaymentTypeExpr = (function_exists('db_column_exists') && db_column_exists('orders', 'payment_type'))
    ? 'o.payment_type'
    : "'cash' AS payment_type";
$orderTypeExpr = (function_exists('db_column_exists') && db_column_exists('orders', 'order_type'))
    ? 'o.order_type'
    : "NULL AS order_type";
order_expire_due_orders($pdo, $restaurantId);

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
        {$orderPaymentTypeExpr},
        {$orderTypeExpr},
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
        $orderTypeRaw = (string)($o['order_type'] ?? '');
        $orderType = function_exists('order_type_normalize')
            ? order_type_normalize($orderTypeRaw, $tid)
            : 'hall';
        $orderTypeLabel = function_exists('order_type_label')
            ? order_type_label($orderTypeRaw, $tid)
            : 'Зал';
        $sourceLabel = function_exists('order_source_label')
            ? order_source_label($orderType, $tid, null)
            : (($orderType === 'hall') ? 'QR / Зал' : $orderTypeLabel);
        $paymentStatusNorm = $paymentStatus !== '' ? $paymentStatus : 'unpaid';
        $countdownMeta = order_payment_timer_meta($o, $now);

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
            'order_type' => $orderType,
            'order_type_label' => $orderTypeLabel,
            'source_label' => $sourceLabel,
            'total_price' => $total,
            'created_at_short' => $createdAtShort,
            'created_at' => $o['created_at'] ?? null,
            'since_minutes' => $sinceMinutes,
            'countdown_seconds_remaining' => $countdownMeta['seconds_remaining'],
            'countdown_expired' => $countdownMeta['expired'],
            'countdown_warning' => $countdownMeta['warning'],
            'countdown_mmss' => $countdownMeta['mmss'],
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
if (waiter_calls_require_table(false)) {
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
}

// 3) Load all tables and build response.
$stmtTables = $pdo->prepare("
    SELECT t.id, t.name
    FROM tables AS t
    WHERE t.restaurant_id = :rid
    " . qr_public_sql_exclude_delivery($pdo, 't') . "
    ORDER BY t.id ASC
");
$stmtTables->execute([':rid' => $restaurantId]);
$tables = $stmtTables->fetchAll(PDO::FETCH_ASSOC);

$reservationsByTable = [];
$reservationSummary = [
    'upcoming_count' => 0,
    'current_count' => 0,
    'no_show_count' => 0,
    'occupancy_estimate' => 0,
];
if (function_exists('guest_history_has_table') && guest_history_has_table($pdo, 'table_reservations')) {
    try {
        if (function_exists('reservation_summary')) {
            $reservationSummary = reservation_summary($pdo, $restaurantId, [
                'horizon_minutes' => 240,
                'limit' => 5,
            ]);
        }
        $windowStart = date('Y-m-d H:i:s', $now - 1800);
        $windowEnd = date('Y-m-d H:i:s', $now + (12 * 3600));
        $stmtReservations = $pdo->prepare("
            SELECT
                id,
                table_id,
                guest_name,
                guest_phone,
                guests_count,
                reservation_datetime,
                duration_minutes,
                status,
                comment
            FROM table_reservations
            WHERE restaurant_id = :restaurant_id
              AND status IN ('pending', 'confirmed', 'seated')
              AND reservation_datetime <= :window_end
              AND DATE_ADD(reservation_datetime, INTERVAL COALESCE(NULLIF(duration_minutes, 0), 120) MINUTE) >= :window_start
            ORDER BY reservation_datetime ASC, id ASC
        ");
        $stmtReservations->execute([
            ':restaurant_id' => $restaurantId,
            ':window_start' => $windowStart,
            ':window_end' => $windowEnd,
        ]);
        $reservationRows = $stmtReservations->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($reservationRows as $row) {
            $tableId = (int)($row['table_id'] ?? 0);
            if ($tableId <= 0) {
                continue;
            }
            $startTs = strtotime((string)($row['reservation_datetime'] ?? ''));
            if ($startTs === false) {
                continue;
            }
            $duration = max(30, (int)($row['duration_minutes'] ?? 120));
            $endTs = $startTs + ($duration * 60);
            $isCurrent = ($now >= $startTs && $now <= $endTs);
            $minutesUntil = (int)floor(($startTs - $now) / 60);
            $candidate = [
                'id' => (int)($row['id'] ?? 0),
                'table_id' => $tableId,
                'guest_name' => trim((string)($row['guest_name'] ?? '')),
                'guest_phone' => trim((string)($row['guest_phone'] ?? '')),
                'guests_count' => (int)($row['guests_count'] ?? 1),
                'reservation_datetime' => (string)($row['reservation_datetime'] ?? ''),
                'duration_minutes' => $duration,
                'status' => (string)($row['status'] ?? 'pending'),
                'status_label' => function_exists('reservation_status_label')
                    ? reservation_status_label((string)($row['status'] ?? 'pending'))
                    : 'Бронь',
                'comment' => trim((string)($row['comment'] ?? '')),
                'minutes_until' => $minutesUntil,
                'is_current' => $isCurrent,
            ];

            if (!isset($reservationsByTable[$tableId])) {
                $reservationsByTable[$tableId] = $candidate;
                continue;
            }
            $existing = $reservationsByTable[$tableId];
            $existingCurrent = !empty($existing['is_current']);
            if ($isCurrent && !$existingCurrent) {
                $reservationsByTable[$tableId] = $candidate;
                continue;
            }
            if ($isCurrent === $existingCurrent && $minutesUntil < (int)($existing['minutes_until'] ?? 0)) {
                $reservationsByTable[$tableId] = $candidate;
            }
        }
    } catch (Throwable $e) {
        error_log('FLOORPLAN_RESERVATIONS_LOAD_FAIL rest_id=' . $restaurantId . ' ' . $e->getMessage());
    }
}

$byTable = [];
foreach ($tables as $t) {
    $tid = (int)($t['id'] ?? 0);
    if ($tid <= 0) continue;

    $order = $orderByTable[$tid] ?? null;
    $waiterCall = $callsByTable[$tid] ?? null;
    $reservation = $reservationsByTable[$tid] ?? null;
    $meta = function_exists('floorplan_table_status_meta')
        ? floorplan_table_status_meta($order, $waiterCall)
        : [
            'status_key' => $order ? 'active_order' : 'free',
            'status_label' => $order ? 'Активный заказ' : 'Свободно',
            'color_key' => $order ? 'sky' : 'slate',
            'priority' => $order ? 60 : 0,
            'is_attention' => false,
        ];
    if (
        !$order
        && !$waiterCall
        && is_array($reservation)
        && (($meta['status_key'] ?? 'free') === 'free')
    ) {
        $isCurrentReservation = !empty($reservation['is_current']);
        $minutesUntil = (int)($reservation['minutes_until'] ?? 0);
        $meta = [
            'status_key' => 'reserved',
            'status_label' => $isCurrentReservation
                ? 'Стол забронирован'
                : (($minutesUntil > 0 && $minutesUntil <= 60) ? ('Бронь через ' . $minutesUntil . ' мин') : 'Ожидается бронь'),
            'color_key' => $isCurrentReservation ? 'amber' : 'indigo',
            'priority' => $isCurrentReservation ? 55 : 40,
            'is_attention' => $isCurrentReservation,
        ];
    }

    $paymentTypeShort = '';
    $paymentStatusShort = '';
    if ($order) {
        $pt = (string)($order['payment_type'] ?? '');
        $ps = (string)($order['payment_status'] ?? '');
        $paymentTypeShort = [
            'cash' => 'Наличные',
            'card_later' => 'Карта позже',
            'pay_later' => 'Позже',
        ][$pt] ?? $pt;
        $paymentStatusShort = [
            'paid' => 'Оплачен',
            'unpaid' => 'Не оплачен',
            'pending' => 'Ожидает',
            'canceled' => 'Отменён',
        ][$ps] ?? $ps;
    }

    $byTable[$tid] = [
        'table_id' => $tid,
        'table_name' => (string)($t['name'] ?? ''),
        'status_key' => (string)($meta['status_key'] ?? 'free'),
        'status_label' => (string)($meta['status_label'] ?? 'Свободно'),
        'color_key' => (string)($meta['color_key'] ?? 'slate'),
        'priority' => (int)($meta['priority'] ?? 0),
        'is_attention' => (bool)($meta['is_attention'] ?? false),
        'active_order_id' => $order ? (int)($order['id'] ?? 0) : null,
        'order_total' => $order ? (float)($order['total_price'] ?? 0) : null,
        'order_type' => $order ? (string)($order['order_type'] ?? 'hall') : null,
        'order_type_label' => $order ? (string)($order['order_type_label'] ?? 'Зал') : null,
        'source_label' => $order ? (string)($order['source_label'] ?? '') : null,
        'payment_status' => $order ? (string)($order['payment_status'] ?? 'unpaid') : null,
        'payment_status_short' => $order ? $paymentStatusShort : null,
        'payment_type' => $order ? (string)($order['payment_type'] ?? 'cash') : null,
        'payment_type_short' => $order ? $paymentTypeShort : null,
        'waiting_minutes' => $order ? (int)($order['since_minutes'] ?? 0) : null,
        'created_at' => $order ? ($order['created_at'] ?? null) : null,
        'created_at_short' => $order ? ($order['created_at_short'] ?? null) : null,
        'countdown_mmss' => $order ? ($order['countdown_mmss'] ?? null) : null,
        'countdown_warning' => $order ? (bool)($order['countdown_warning'] ?? false) : false,
        'countdown_expired' => $order ? (bool)($order['countdown_expired'] ?? false) : false,
        'ready_items_count' => $order ? (int)($order['ready_items_count'] ?? 0) : 0,
        'total_items_count' => $order ? (int)($order['total_items_count'] ?? 0) : 0,
        'partial_ready' => $order ? (bool)($order['partial_ready'] ?? false) : false,
        'waiter_call_flag' => $waiterCall ? true : false,
        'reservation_flag' => is_array($reservation),
        'reservation' => $reservation,
        'order' => $order,
        'waiter_call' => $waiterCall,
    ];
}

echo json_encode([
    'success' => true,
    'by_table' => $byTable,
    'reservation_summary' => [
        'upcoming_count' => (int)($reservationSummary['upcoming_count'] ?? 0),
        'current_count' => (int)($reservationSummary['current_count'] ?? 0),
        'no_show_count' => (int)($reservationSummary['no_show_count'] ?? 0),
        'occupancy_estimate' => (int)($reservationSummary['occupancy_estimate'] ?? 0),
    ],
], JSON_UNESCAPED_UNICODE);
