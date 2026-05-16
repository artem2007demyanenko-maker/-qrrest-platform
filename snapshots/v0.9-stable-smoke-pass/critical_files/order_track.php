<?php

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/order_payment_runtime.php';

$rid = bin2hex(random_bytes(4));

set_exception_handler(function (Throwable $e) use ($rid) {
    error_log('PUBLIC_PAGE_ERROR rid=' . $rid . ' ' . json_encode([
        'uri' => $_SERVER['REQUEST_URI'] ?? '',
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'time' => gmdate('c'),
    ], JSON_UNESCAPED_UNICODE));
    if (!headers_sent()) {
        http_response_code(200);
    }
    $isJson = strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false;
    if ($isJson) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'internal_error', 'rid' => $rid]);
    } else {
        echo '<h1>Ошибка</h1><p>Попробуйте позже.</p><p style="font-size:11px;color:#666">RID: ' . htmlspecialchars($rid) . '</p>';
    }
    exit;
});

$pdo = db();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../app/order_feedback_guard.php';
require_once __DIR__ . '/../app/yandex_review_url_validate.php';
if (file_exists(__DIR__ . '/../app/schema_guard.php')) {
    require_once __DIR__ . '/../app/schema_guard.php';
}
if (file_exists(__DIR__ . '/../app/runtime_schema_bootstrap.php')) {
    require_once __DIR__ . '/../app/runtime_schema_bootstrap.php';
}
if (function_exists('runtime_schema_ensure_guest_reviews')) {
    runtime_schema_ensure_guest_reviews($pdo);
}
if (function_exists('runtime_schema_ensure_order_tips')) {
    runtime_schema_ensure_order_tips($pdo);
}

$orderGuestIdExpr = (function_exists('db_column_exists') && db_column_exists('orders', 'guest_id'))
    ? 'guest_id'
    : '0 AS guest_id';
$orderGuestCardIdExpr = (function_exists('db_column_exists') && db_column_exists('orders', 'guest_card_id'))
    ? 'guest_card_id'
    : '0 AS guest_card_id';
$orderPaymentTypeExpr = (function_exists('db_column_exists') && db_column_exists('orders', 'payment_type'))
    ? 'payment_type'
    : "'cash' AS payment_type";
$orderTypeExpr = (function_exists('db_column_exists') && db_column_exists('orders', 'order_type'))
    ? 'order_type'
    : "NULL AS order_type";
$orderCourierStatusExpr = (function_exists('db_column_exists') && db_column_exists('orders', 'courier_status'))
    ? 'courier_status'
    : "NULL AS courier_status";
$orderLoyaltyPhoneExpr = (function_exists('db_column_exists') && db_column_exists('orders', 'loyalty_phone'))
    ? 'loyalty_phone'
    : "NULL AS loyalty_phone";
$orderLoyaltyPointsAccruedExpr = (function_exists('db_column_exists') && db_column_exists('orders', 'loyalty_points_accrued'))
    ? 'loyalty_points_accrued'
    : "0 AS loyalty_points_accrued";
$orderLoyaltyPointsSpentExpr = (function_exists('db_column_exists') && db_column_exists('orders', 'loyalty_points_spent'))
    ? 'loyalty_points_spent'
    : "0 AS loyalty_points_spent";
$orderLoyaltyPointsBalanceAfterExpr = (function_exists('db_column_exists') && db_column_exists('orders', 'loyalty_points_balance_after'))
    ? 'loyalty_points_balance_after'
    : "0 AS loyalty_points_balance_after";

$tableId  = isset($_GET['table_id']) ? (int)$_GET['table_id'] : 0;
$orderIdRaw = isset($_GET['order_id']) ? trim((string)$_GET['order_id']) : '';
$orderId  = (ctype_digit($orderIdRaw) && $orderIdRaw !== '') ? (int)$orderIdRaw : 0;
$restaurantIdParam = isset($_GET['restaurant_id']) ? (int)$_GET['restaurant_id'] : 0;
$trackTokenRaw = trim((string)($_GET['track_token'] ?? ($_GET['token'] ?? '')));

if (!$currentRestaurant && $restaurantIdParam > 0 && function_exists('get_restaurant_by_id_active')) {
    $restById = get_restaurant_by_id_active($restaurantIdParam);
    if ($restById) {
        $currentRestaurant = $restById;
    }
}

if ($orderId <= 0 && $trackTokenRaw !== '' && function_exists('db_column_exists') && db_column_exists('orders', 'flow_id')) {
    try {
        $sqlTrack = "
            SELECT id
            FROM orders
            WHERE flow_id = :flow_id
        ";
        $paramsTrack = [':flow_id' => $trackTokenRaw];
        if ($restaurantIdParam > 0) {
            $sqlTrack .= " AND restaurant_id = :rest_id ";
            $paramsTrack[':rest_id'] = $restaurantIdParam;
        }
        $sqlTrack .= " ORDER BY id DESC LIMIT 1";
        $stmtTrack = $pdo->prepare($sqlTrack);
        $stmtTrack->execute($paramsTrack);
        $orderId = (int)($stmtTrack->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        error_log('ORDER_TRACK_TOKEN_LOOKUP_FAIL rid=' . $rid . ' ' . $e->getMessage());
    }
}

$orderIdMode = ($orderId > 0);
$isDeliveryOrder = false;

// Mode 1: order_id only — load order, derive restaurant and table from it.
if ($orderIdMode && $orderId > 0) {
    $stmt = $pdo->prepare("SELECT id, restaurant_id, table_id, total_price, {$orderPaymentTypeExpr}, {$orderTypeExpr}, {$orderCourierStatusExpr}, payment_status, order_status,
        {$orderGuestIdExpr}, {$orderGuestCardIdExpr},
        {$orderLoyaltyPhoneExpr}, {$orderLoyaltyPointsAccruedExpr}, {$orderLoyaltyPointsSpentExpr}, {$orderLoyaltyPointsBalanceAfterExpr}, created_at, updated_at
        FROM orders WHERE id = :oid LIMIT 1");
    $stmt->execute([':oid' => $orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'order_not_found'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $restaurantId = (int)$order['restaurant_id'];
    $tableId      = (int)$order['table_id'];
    if ($restaurantId <= 0 || $tableId <= 0) {
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'order_invalid'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!function_exists('schema_guard_restaurants_deleted_sql')) {
        require_once __DIR__ . '/../app/schema_guard.php';
    }
    $deletedSql = schema_guard_restaurants_deleted_sql('r');
    $statusSql  = (function_exists('db_column_exists') && db_column_exists('restaurants', 'status')) ? " AND r.status = 'active'" : '';
    $stmt = $pdo->prepare("SELECT r.* FROM restaurants r WHERE r.id = :rid" . $statusSql . $deletedSql . " LIMIT 1");
    $stmt->execute([':rid' => $restaurantId]);
    $currentRestaurant = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$currentRestaurant) {
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'restaurant_not_found'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $table = null;
    $tStmt = $pdo->prepare("SELECT * FROM tables WHERE id = :id AND restaurant_id = :rest LIMIT 1");
    $tStmt->execute([':id' => $tableId, ':rest' => $restaurantId]);
    $table = $tStmt->fetch(PDO::FETCH_ASSOC);
    if (!$table) {
        http_response_code(404);
        echo "Стол не найден.";
        exit;
    }
    $isDeliveryOrder = qr_public_is_delivery_table_row($table);
    // Continue to order items and HTML (same order row already loaded).
} else {
    // Mode 2: require subdomain restaurant + table_id + order_id + session.
    if (!$currentRestaurant) {
        http_response_code(404);
        echo '<!doctype html><html lang="ru"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Ресторан не выбран</title><script src="https://cdn.tailwindcss.com"></script></head><body class="min-h-screen bg-slate-950 text-slate-100 flex items-center justify-center p-4"><div class="w-full max-w-md rounded-3xl border border-slate-800 bg-slate-900/80 p-6"><h1 class="text-xl font-semibold">Ресторан не выбран</h1><p class="mt-2 text-sm text-slate-400">Откройте трекинг по ссылке с <code>order_id</code> или выберите ресторан.</p><div class="mt-5 flex flex-wrap gap-2"><a href="/login.php" class="px-4 py-2 rounded-xl bg-emerald-500 hover:bg-emerald-400 text-slate-950 text-sm font-semibold">Войти</a><a href="/project-admin/restaurants.php" class="px-4 py-2 rounded-xl border border-slate-700 bg-slate-950/60 hover:bg-slate-800 text-sm">Выбрать ресторан</a></div></div></body></html>';
        exit;
    }
    if ($tableId <= 0 || $orderId <= 0) {
        http_response_code(400);
        echo "Некорректные параметры.";
        exit;
    }
    $trackKey = 'last_order_' . $currentRestaurant['id'] . '_' . $tableId;
    if (empty($_SESSION[$trackKey]) || (int)$_SESSION[$trackKey] !== $orderId) {
        http_response_code(404);
        echo "Заказ не найден.";
        exit;
    }
    $stmt = $pdo->prepare("SELECT * FROM tables WHERE id = :id AND restaurant_id = :rest LIMIT 1");
    $stmt->execute([':id' => $tableId, ':rest' => $currentRestaurant['id']]);
    $table = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$table) {
        http_response_code(404);
        echo "Стол не найден.";
        exit;
    }
    $isDeliveryOrder = qr_public_is_delivery_table_row($table);
    $stmt = $pdo->prepare("
        SELECT id, restaurant_id, total_price, {$orderPaymentTypeExpr}, {$orderTypeExpr}, {$orderCourierStatusExpr}, payment_status, order_status,
               {$orderGuestIdExpr}, {$orderGuestCardIdExpr},
               {$orderLoyaltyPhoneExpr}, {$orderLoyaltyPointsAccruedExpr}, {$orderLoyaltyPointsSpentExpr}, {$orderLoyaltyPointsBalanceAfterExpr},
               created_at, updated_at
        FROM orders
        WHERE id = :oid AND restaurant_id = :rest AND table_id = :table
        LIMIT 1
    ");
    $stmt->execute([
        ':oid'   => $orderId,
        ':rest'  => $currentRestaurant['id'],
        ':table' => $tableId,
    ]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        http_response_code(404);
        echo "Заказ не найден.";
        exit;
    }
}

$orderRestaurantId = (int)($order['restaurant_id'] ?? ($currentRestaurant['id'] ?? 0));
if ($orderRestaurantId > 0) {
    order_expire_due_orders($pdo, $orderRestaurantId, $orderId, $tableId);

    $refreshStmt = $pdo->prepare("
        SELECT id, restaurant_id, table_id, total_price, {$orderPaymentTypeExpr}, {$orderTypeExpr}, {$orderCourierStatusExpr}, payment_status, order_status,
               {$orderGuestIdExpr}, {$orderGuestCardIdExpr},
               {$orderLoyaltyPhoneExpr}, {$orderLoyaltyPointsAccruedExpr}, {$orderLoyaltyPointsSpentExpr}, {$orderLoyaltyPointsBalanceAfterExpr},
               created_at, updated_at
        FROM orders
        WHERE id = :oid
          AND restaurant_id = :rest
          AND table_id = :table
        LIMIT 1
    ");
    $refreshStmt->execute([
        ':oid' => $orderId,
        ':rest' => $orderRestaurantId,
        ':table' => $tableId,
    ]);
    $refreshedOrder = $refreshStmt->fetch(PDO::FETCH_ASSOC);
    if ($refreshedOrder) {
        $order = $refreshedOrder;
    }
}

$feedbackRestId = (int)($order['restaurant_id'] ?? 0);
if ($feedbackRestId <= 0) {
    $feedbackRestId = (int)($currentRestaurant['id'] ?? 0);
}
$feedbackTrackTrusted = order_feedback_track_context_valid($feedbackRestId, $tableId, $orderId);

$orderItems = [];
$orderTrackReadonly = false;
if (function_exists('db_table_exists') && db_table_exists('order_items')) {
    try {
        $stmt = $pdo->prepare("
            SELECT oi.quantity, oi.price, mi.name
            FROM order_items oi
            JOIN menu_items mi ON mi.id = oi.menu_item_id
            WHERE oi.order_id = :oid
            ORDER BY oi.id ASC
        ");
        $stmt->execute([':oid' => $orderId]);
        $orderItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $orderTrackReadonly = true;
        error_log('ORDER_TRACK_ITEMS_FAIL rid=' . $rid . ' order_id=' . (int)$orderId . ' ' . $e->getMessage());
    }
} else {
    // Partial schema fallback: keep page available with totals from order row only.
    $orderTrackReadonly = true;
}

$orderPayableTotal = (float)($order['total_price'] ?? 0);
$orderSpentTotal = max(0, (int)($order['loyalty_points_spent'] ?? 0));
$orderGrossFromItems = 0.0;
foreach ($orderItems as $oi) {
    $qtyVal = isset($oi['quantity']) ? (int)$oi['quantity'] : (int)($oi['qty'] ?? 0);
    if ($qtyVal <= 0) {
        $qtyVal = 1;
    }
    $orderGrossFromItems += ((float)($oi['price'] ?? 0)) * $qtyVal;
}
$orderGrossTotal = $orderGrossFromItems > 0 ? $orderGrossFromItems : ($orderPayableTotal + $orderSpentTotal);

$hasOrderFeedback = false;
$orderFeedbackRating = 0;
$orderFeedbackNps = null;
try {
    if (file_exists(__DIR__ . '/../app/schema_guard.php')) {
        require_once __DIR__ . '/../app/schema_guard.php';
    }
    if (function_exists('db_table_exists') && db_table_exists('guest_reviews')) {
        $fbStmt = $pdo->prepare("SELECT id, rating, nps_score FROM guest_reviews WHERE order_id = :oid LIMIT 1");
        $fbStmt->execute([':oid' => $orderId]);
        $fbRow = $fbStmt->fetch(PDO::FETCH_ASSOC);
        if ($fbRow) {
            $hasOrderFeedback = true;
            $orderFeedbackRating = (int)($fbRow['rating'] ?? 0);
            $orderFeedbackNps = ($fbRow['nps_score'] ?? null) !== null ? (int)$fbRow['nps_score'] : null;
        }
    }
    if (!$hasOrderFeedback && function_exists('db_table_exists') && db_table_exists('order_feedback')) {
        $fbStmt = $pdo->prepare("SELECT id, rating FROM order_feedback WHERE order_id = :oid LIMIT 1");
        $fbStmt->execute([':oid' => $orderId]);
        $fbRow = $fbStmt->fetch(PDO::FETCH_ASSOC);
        if ($fbRow) {
            $hasOrderFeedback = true;
            $orderFeedbackRating = (int)($fbRow['rating'] ?? 0);
        }
    }
} catch (Throwable $e) {
    error_log('ORDER_TRACK_FEEDBACK_CHECK_FAIL rid=' . $rid . ' order_id=' . (int)$orderId . ' ' . $e->getMessage());
    $hasOrderFeedback = false;
}

$hasOrderTip = false;
$orderTipAmount = 0.0;
$orderTipStatus = '';
$orderTipTarget = '';
try {
    if (function_exists('db_table_exists') && db_table_exists('order_tips')) {
        $tipStmt = $pdo->prepare("
            SELECT amount, status, staff_user_id, courier_user_id
            FROM order_tips
            WHERE order_id = :oid
              AND restaurant_id = :rest
            ORDER BY id DESC
            LIMIT 1
        ");
        $tipStmt->execute([
            ':oid' => $orderId,
            ':rest' => $feedbackRestId > 0 ? $feedbackRestId : (int)($currentRestaurant['id'] ?? 0),
        ]);
        $tipRow = $tipStmt->fetch(PDO::FETCH_ASSOC);
        if ($tipRow) {
            $hasOrderTip = true;
            $orderTipAmount = (float)($tipRow['amount'] ?? 0);
            $orderTipStatus = trim((string)($tipRow['status'] ?? 'pending'));
            $orderTipTarget = ((int)($tipRow['courier_user_id'] ?? 0) > 0) ? 'courier' : 'waiter';
        }
    }
} catch (Throwable $e) {
    error_log('ORDER_TRACK_TIP_CHECK_FAIL rid=' . $rid . ' order_id=' . (int)$orderId . ' ' . $e->getMessage());
    $hasOrderTip = false;
}

$yandexReviewUrl = trim((string)($currentRestaurant['yandex_review_url'] ?? ''));
if ($yandexReviewUrl !== '') {
    $yandexReviewUrl = normalize_yandex_maps_review_url($yandexReviewUrl);
    if (!yandex_maps_review_url_is_valid($yandexReviewUrl)) {
        $yandexReviewUrl = '';
    }
}
$showYandexReviewPrompt = !is_demo_mode()
    && $yandexReviewUrl !== ''
    && in_array(mb_strtolower(trim((string)($order['order_status'] ?? '')), 'UTF-8'), ['delivered', 'completed'], true)
    && $hasOrderFeedback
    && $orderFeedbackRating >= 5;

$orderTypeRaw = (string)($order['order_type'] ?? '');
$orderTypeNormalized = function_exists('order_type_normalize')
    ? order_type_normalize($orderTypeRaw, (int)($order['table_id'] ?? $tableId))
    : ($isDeliveryOrder ? 'delivery' : 'hall');
if ($isDeliveryOrder && $orderTypeNormalized === 'hall') {
    $orderTypeNormalized = 'delivery';
}
$orderTypeLabel = function_exists('order_type_label')
    ? order_type_label($orderTypeNormalized, (int)($order['table_id'] ?? $tableId))
    : ($isDeliveryOrder ? 'Доставка' : 'Зал');
$orderSourceLabel = function_exists('order_source_label')
    ? order_source_label($orderTypeNormalized, (int)($order['table_id'] ?? $tableId), (string)($table['name'] ?? ''))
    : ($isDeliveryOrder ? 'Доставка' : 'QR / Зал');
$courierStatusTrack = function_exists('courier_status_normalize')
    ? courier_status_normalize((string)($order['courier_status'] ?? ''), $orderTypeNormalized)
    : '';
$courierStatusLabelTrack = function_exists('courier_status_label')
    ? courier_status_label($courierStatusTrack, $orderTypeNormalized)
    : '';

$guestStatusMeta = function_exists('order_guest_status_meta')
    ? order_guest_status_meta(
        (string)($order['order_status'] ?? 'new'),
        $orderTypeNormalized,
        (string)($order['payment_status'] ?? 'unpaid'),
        [
            'courier_status' => $courierStatusTrack,
            'delivery_ready_await_courier' => ($orderTypeNormalized === 'delivery' && $courierStatusTrack === 'waiting_courier'),
        ]
    )
    : [
        'label' => 'Заказ принят',
        'description' => 'Мы приняли ваш заказ и передали его на кухню.',
        'progress_percent' => 25,
    ];
$label = (string)($guestStatusMeta['label'] ?? 'Заказ принят');
$desc = (string)($guestStatusMeta['description'] ?? 'Мы приняли ваш заказ и передали его на кухню.');
$progress = (int)($guestStatusMeta['progress_percent'] ?? 25);
$deliveryTimingMeta = function_exists('courier_delivery_timing')
    ? courier_delivery_timing([
        'order_type' => $orderTypeNormalized,
        'order_status' => (string)($order['order_status'] ?? 'new'),
        'courier_status' => $courierStatusTrack,
        'created_at' => $order['created_at'] ?? null,
        'courier_taken_at' => $order['courier_taken_at'] ?? null,
        'courier_on_the_way_at' => $order['courier_on_the_way_at'] ?? null,
        'delivered_at' => $order['delivered_at'] ?? null,
        'table_id' => (int)($order['table_id'] ?? $tableId),
    ])
    : [
        'eta_minutes' => 0,
        'eta_label' => '',
        'timing_state' => '',
        'timing_progress_percent' => $progress,
        'elapsed_minutes' => 0,
    ];
$deliveryLocationPayload = [
    'has_location' => false,
    'state' => 'offline',
    'label' => 'Геопозиция курьера недоступна',
    'last_update_seconds' => null,
    'last_update_human' => '',
];
if (
    $orderTypeNormalized === 'delivery'
    && function_exists('courier_location_latest')
    && function_exists('courier_location_public_payload')
) {
    $deliveryLocRow = courier_location_latest(
        $pdo,
        (int)$currentRestaurant['id'],
        (int)$orderId,
        isset($order['courier_user_id']) ? (int)$order['courier_user_id'] : null
    );
    $deliveryLocationPayload = courier_location_public_payload($deliveryLocRow, ['live_sec' => 20, 'stale_sec' => 90]);
}
if ($orderTypeNormalized === 'delivery') {
    $timingProgressInit = (int)($deliveryTimingMeta['timing_progress_percent'] ?? 0);
    if ($timingProgressInit > $progress) {
        $progress = min(100, $timingProgressInit);
    }
}

$orderStatusRaw = (string)($order['order_status'] ?? 'new');
$countdownMeta = order_payment_timer_meta($order);
$countdownSecondsRemaining = $countdownMeta['seconds_remaining'];
$countdownExpired = $countdownMeta['expired'];
$countdownWarning = $countdownMeta['warning'];
$countdownMmss = $countdownMeta['mmss'];

$finalOrderStatuses = ['delivered', 'completed', 'cancelled', 'canceled'];
$isActiveOrderForGuest = !in_array($orderStatusRaw, $finalOrderStatuses, true);

// Soft limit / upgrade prompts (non-blocking; for display only)
$planLimitExceeded = false;
$planLimitWarning = false;
$restaurantIdForPlan = (int)($currentRestaurant['id'] ?? 0);
if ($restaurantIdForPlan > 0 && !is_demo_mode() && file_exists(__DIR__ . '/../app/subscription_plans.php')) {
    require_once __DIR__ . '/../app/subscription_plans.php';
    if (function_exists('check_limit') && function_exists('get_usage_percent')) {
        $planLimitExceeded = !check_limit($restaurantIdForPlan, 'orders');
        $usagePercent = get_usage_percent($restaurantIdForPlan, 'orders');
        $planLimitWarning = ($usagePercent >= 80 && $usagePercent < 100);
    }
}

$_SESSION['guest_last_menu_context'] = $isDeliveryOrder ? 'delivery' : 'table';
$_SESSION['guest_last_table_id'] = $isDeliveryOrder ? 0 : (int)$tableId;

$trackLoyaltyEnabled = false;
if (function_exists('loyalty_is_enabled_for_restaurant')) {
    $trackLoyaltyEnabled = loyalty_is_enabled_for_restaurant($currentRestaurant);
}
if ($trackLoyaltyEnabled && !is_demo_mode() && file_exists(__DIR__ . '/../app/subscription_plans.php')) {
    require_once __DIR__ . '/../app/subscription_plans.php';
    if (function_exists('check_feature') && !check_feature((int)$currentRestaurant['id'], 'loyalty_enabled')) {
        $trackLoyaltyEnabled = false;
    }
}

$orderLpTrack = trim((string)($order['loyalty_phone'] ?? ''));
$orderGuestIdTrack = (int)($order['guest_id'] ?? 0);
$orderAccrTrack = (int)($order['loyalty_points_accrued'] ?? 0);
$orderStatusForBonus = strtolower((string)($order['order_status'] ?? ''));

$showOrderBonusAttach = false;
if (!is_demo_mode() && $trackLoyaltyEnabled && !in_array($orderStatusForBonus, ['canceled', 'cancelled'], true)) {
    if ($orderLpTrack !== '' || $orderGuestIdTrack > 0) {
        $showOrderBonusAttach = false;
    } else {
        $showOrderBonusAttach = true;
    }
}

$guestTrackRow = function_exists('guest_current') ? guest_current($pdo) : null;
$guestBalanceTrack = 0;
if ($guestTrackRow && $trackLoyaltyEnabled && function_exists('guest_loyalty_balance_by_guest_rest')) {
    $guestBalanceTrack = guest_loyalty_balance_by_guest_rest($pdo, (int)$currentRestaurant['id'], (int)$guestTrackRow['id']);
}

$orderTrackBonusOtp = $showOrderBonusAttach && !$guestTrackRow;
$orderTrackBonusInstant = $showOrderBonusAttach && (bool)$guestTrackRow;

$orderTrackMenuUrl = qr_public_build_url('/qr.php', $isDeliveryOrder ? [] : ['table_id' => (int)$tableId]);
$orderTrackMenuBackLabel = $isDeliveryOrder ? '← Назад в меню доставки' : '← Назад в меню этого стола';

?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title><?= e($orderTypeLabel) ?> · #<?= (int)$orderId ?> — <?= e($currentRestaurant['name']) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-950 text-slate-50 overflow-x-hidden">
<div class="max-w-2xl mx-auto px-4 py-6 space-y-4">

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0 flex-1">
            <div class="text-xs text-slate-400">Ресторан</div>
            <div class="text-lg font-semibold"><?= e($currentRestaurant['name']) ?></div>
            <div class="text-xs text-slate-500 mt-0.5">
                <?php if ($isDeliveryOrder): ?>
                    <span class="text-slate-300">Заказ на доставку</span>
                <?php else: ?>
                    Заказ в зале · стол <span class="text-slate-200 font-medium"><?= e($table['name']) ?></span>
                <?php endif; ?>
            </div>
        </div>

        <a href="<?= e($orderTrackMenuUrl) ?>"
           class="px-3 py-2 rounded-2xl bg-slate-900 border border-slate-800 text-xs text-slate-200 hover:border-emerald-500/60 whitespace-nowrap">
            <?= e($orderTrackMenuBackLabel) ?>
        </a>
    </div>

    <?php if ($planLimitExceeded): ?>
    <div class="rounded-2xl border border-amber-500/50 bg-amber-500/10 px-4 py-3 text-sm text-amber-200" role="alert">
        <p class="font-medium">Вы превысили лимит заказов на текущем тарифе</p>
        <p class="text-xs text-amber-200/80 mt-1">Заказы по-прежнему принимаются. Рекомендуем перейти на тариф GROWTH для больших лимитов.</p>
        <a href="/owner/billing.php" class="inline-flex items-center mt-3 px-4 py-2 rounded-xl bg-amber-600 hover:bg-amber-500 text-white text-sm font-medium">Перейти на тариф GROWTH</a>
    </div>
    <?php elseif ($planLimitWarning): ?>
    <div class="rounded-2xl border border-sky-500/40 bg-sky-500/10 px-4 py-3 text-sm text-sky-200" role="status">
        <p class="font-medium">Вы приближаетесь к лимиту заказов</p>
        <p class="text-xs text-sky-200/80 mt-1">Рекомендуем перейти на тариф GROWTH, чтобы не ограничивать приём заказов.</p>
        <a href="/owner/billing.php" class="inline-flex items-center mt-2 px-3 py-1.5 rounded-xl bg-sky-600/80 hover:bg-sky-500 text-white text-xs font-medium">Перейти на тариф GROWTH</a>
    </div>
    <?php endif; ?>
    <?php if ($orderTrackReadonly): ?>
    <div class="rounded-2xl border border-amber-500/50 bg-amber-500/10 px-4 py-3 text-sm text-amber-200" role="status">
        <p class="font-medium">Режим только чтение</p>
        <p class="text-xs text-amber-200/80 mt-1">Часть таблиц недоступна. Статус заказа отображается по данным заказа, детализация позиций может быть неполной.</p>
    </div>
    <?php endif; ?>

    <?php if ($showOrderBonusAttach): ?>
    <div id="order-track-bonus-banner" class="rounded-3xl border border-emerald-400/45 bg-gradient-to-br from-emerald-500/20 via-slate-900/95 to-slate-950 p-5 sm:p-6 shadow-xl shadow-emerald-950/40 ring-1 ring-emerald-500/30">
        <h3 class="text-xl font-bold text-slate-50 leading-snug">Привязать номер для бонусов</h3>
        <p class="mt-2 text-sm text-slate-400 leading-relaxed max-w-xl">
            <?php if ($orderTrackBonusInstant): ?>
                Сохраним номер за этим заказом и начислим бонусы после подтверждённой оплаты.
            <?php else: ?>
                Войдите по номеру телефона — привяжем заказ к вашему счёту и начислим бонусы после подтверждённой оплаты.
            <?php endif; ?>
        </p>
        <div class="mt-5 flex flex-col sm:flex-row gap-3 sm:items-center">
            <?php if ($orderTrackBonusInstant): ?>
                <button type="button" id="order-track-bonus-instant"
                        class="min-h-[52px] px-8 rounded-2xl bg-emerald-500 hover:bg-emerald-400 text-slate-950 text-sm font-bold shadow-lg shadow-emerald-900/35 touch-manipulation">
                    Сохранить номер для бонусов
                </button>
            <?php else: ?>
                <button type="button" id="guest-otp-open-track"
                        class="min-h-[52px] px-8 rounded-2xl bg-emerald-500 hover:bg-emerald-400 text-slate-950 text-sm font-bold shadow-lg shadow-emerald-900/35 touch-manipulation">
                    Войти и сохранить номер
                </button>
            <?php endif; ?>
        </div>
        <p id="order-track-bonus-msg" class="mt-4 hidden text-base font-semibold text-emerald-200 leading-snug"></p>
        <p id="order-track-bonus-err" class="mt-2 hidden text-sm text-red-300"></p>
    </div>
    <?php endif; ?>

    <div class="rounded-3xl bg-slate-900/80 border border-slate-800 p-4">
        <div class="flex items-center justify-between gap-2">
            <div>
                <div class="text-xs text-slate-400">Ваш заказ</div>
                <div class="text-sm font-semibold">#<?= (int)$orderId ?></div>
            </div>
            <div class="text-xs text-slate-500">обновляется автоматически</div>
        </div>

        <div class="mt-2 flex flex-wrap items-center gap-2 text-xs">
            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full border border-cyan-400/50 bg-cyan-500/10 text-cyan-100">
                Тип: <?= e($orderTypeLabel) ?>
            </span>
            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full border border-sky-400/40 bg-sky-500/10 text-sky-100">
                Источник: <?= e($orderSourceLabel) ?>
            </span>
            <?php if ($orderTypeNormalized === 'delivery' && $courierStatusLabelTrack !== ''): ?>
                <span id="delivery-step-badge" class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full border border-violet-400/50 bg-violet-500/10 text-violet-100">
                    Доставка: <?= e($courierStatusLabelTrack) ?>
                </span>
            <?php else: ?>
                <span id="delivery-step-badge" class="hidden"></span>
            <?php endif; ?>
            <?php if ($orderTypeNormalized === 'delivery'): ?>
                <span id="delivery-eta-badge" class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full border border-amber-400/50 bg-amber-500/10 text-amber-100">
                    ETA: <?= e((string)($deliveryTimingMeta['eta_label'] ?? '—')) ?>
                </span>
            <?php else: ?>
                <span id="delivery-eta-badge" class="hidden"></span>
            <?php endif; ?>
        </div>

        <div class="mt-3">
            <div class="text-sm font-semibold">
                Статус: <span id="st_label" class="text-emerald-300"><?= e($label) ?></span>
            </div>
            <div id="st_desc" class="text-xs text-slate-400 mt-1"><?= e($desc) ?></div>
            <?php if ($orderTypeNormalized === 'delivery'): ?>
                <div id="delivery-timing-line" class="text-xs text-amber-200/90 mt-1">
                    <?= e((string)($deliveryTimingMeta['eta_label'] ?? '')) ?>
                </div>
                <div id="delivery-location-line" class="text-xs mt-1 <?= (($deliveryLocationPayload['state'] ?? 'offline') === 'live') ? 'text-emerald-200' : ((($deliveryLocationPayload['state'] ?? 'offline') === 'stale') ? 'text-amber-200' : 'text-slate-400') ?>">
                    <?= e((string)($deliveryLocationPayload['label'] ?? 'Геопозиция курьера недоступна')) ?>
                    <?php if (!empty($deliveryLocationPayload['last_update_human'])): ?>
                        <span class="text-slate-500">· <?= e((string)$deliveryLocationPayload['last_update_human']) ?></span>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div id="delivery-timing-line" class="hidden"></div>
                <div id="delivery-location-line" class="hidden"></div>
            <?php endif; ?>

            <div class="mt-3 w-full h-2 rounded-full bg-slate-800 overflow-hidden">
                <div id="st_bar"
                     class="h-full bg-emerald-500 transition-all duration-700"
                     style="width: <?= (int)$progress ?>%"></div>
            </div>

            <div id="order-progress-items" class="mt-2 hidden text-xs text-slate-400">
                Готовность по позициям: <span id="order-progress-items-value" class="text-slate-200 font-semibold">0/0</span>
                <span id="order-progress-items-note" class="ml-2 text-indigo-300 hidden">Частично готово</span>
            </div>
        </div>

        <div class="mt-3 grid gap-1.5 text-xs text-slate-500">
            <div>
                Создан: <span class="text-slate-200"><?= e((string)($order['created_at'] ?? '')) ?></span>
                <?php if (!empty($order['updated_at'])): ?>
                    <span class="text-slate-600">·</span>
                    Обновлён: <span class="text-slate-200"><?= e((string)$order['updated_at']) ?></span>
                <?php endif; ?>
            </div>
            <div>
                Сумма блюд: <span class="text-slate-200 font-semibold"><?= number_format($orderGrossTotal, 0, '.', ' ') ?> ₽</span>
                <?php if ($orderSpentTotal > 0): ?>
                    <span class="text-slate-600">·</span>
                    Списано бонусами: <span class="text-emerald-300 font-semibold"><?= number_format($orderSpentTotal, 0, '.', ' ') ?> ₽</span>
                    <span class="text-slate-600">·</span>
                    К оплате: <span class="text-slate-200 font-semibold"><?= number_format($orderPayableTotal, 0, '.', ' ') ?> ₽</span>
                <?php endif; ?>
            </div>
            <div>
                Оплата: <span class="text-slate-200"><?= e($order['payment_type'] ?: 'cash') ?></span>
                <span class="text-slate-600">·</span>
                Статус оплаты: <span class="text-slate-200"><?= e($order['payment_status'] ?: 'unpaid') ?></span>
            </div>
        </div>

        <?php if ($countdownSecondsRemaining !== null): ?>
            <?php if ($countdownExpired): ?>
                <div id="payment-timer-block" class="mt-3 rounded-2xl border border-red-500/40 bg-red-500/10 px-3 py-2">
                    <div class="text-sm font-semibold text-red-200">Время ожидания оплаты истекло</div>
                    <div class="text-[11px] text-red-200/80 mt-1">
                        Осталось: <span id="payment-timer-mmss"><?= e($countdownMmss ?: '00:00') ?></span>
                    </div>
                </div>
            <?php else: ?>
                <?php
                $timerIsWarning = $countdownWarning;
                $timerBorder = $timerIsWarning ? 'border-amber-500/40 bg-amber-500/10' : 'border-emerald-500/30 bg-emerald-500/10';
                $timerText = $timerIsWarning ? 'text-amber-200' : 'text-emerald-200';
                ?>
                <div id="payment-timer-block" class="mt-3 rounded-2xl border <?= e($timerBorder) ?> px-3 py-2">
                    <div class="text-sm font-semibold <?= e($timerText) ?>">Официант должен подойти в течение 10 минут</div>
                    <div class="text-[11px] <?= e($timerText) ?>/80 mt-1">
                        Осталось: <span id="payment-timer-mmss"><?= e($countdownMmss ?: '10:00') ?></span>
                    </div>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div id="payment-timer-block" class="mt-3 hidden"></div>
        <?php endif; ?>

        <?php if ($isActiveOrderForGuest): ?>
            <div id="waiter-call-block" class="mt-4 rounded-2xl border border-slate-800 bg-slate-900/50 p-3">
                <button id="btn-waiter-call"
                        type="button"
                        class="w-full min-h-[48px] px-4 py-3 rounded-xl bg-gradient-to-r from-emerald-500 to-teal-500 hover:from-emerald-400 hover:to-teal-400 text-[#0B0F19] text-sm font-bold transition touch-manipulation">
                    Позвать официанта
                </button>
                <div id="waiter-call-message" class="mt-2 hidden text-sm text-emerald-200 font-semibold"></div>
            </div>
        <?php else: ?>
            <div id="waiter-call-block" class="mt-4 hidden"></div>
            <div id="waiter-call-message" class="hidden"></div>
        <?php endif; ?>
    </div>

    <div class="rounded-3xl bg-slate-900/80 border border-slate-800 p-4">
        <div class="text-sm font-semibold mb-2">Состав заказа</div>
        <?php if (!$orderItems): ?>
            <div class="text-xs text-slate-500">Позиции не найдены.</div>
        <?php else: ?>
            <div class="space-y-2">
                <?php foreach ($orderItems as $oi): ?>
                    <?php
                    $qty = (int)$oi['quantity'];
                    $price = (float)$oi['price'];
                    $sum = $qty * $price;
                    ?>
                    <div class="flex items-center justify-between gap-3 bg-slate-950/60 border border-slate-800 rounded-2xl px-3 py-2">
                        <div class="min-w-0">
                            <div class="text-sm truncate"><?= e($oi['name']) ?></div>
                            <div class="text-[11px] text-slate-500"><?= number_format($price, 0, '.', ' ') ?> ₽ × <?= $qty ?></div>
                        </div>
                        <div class="text-sm font-semibold text-slate-200 whitespace-nowrap">
                            <?= number_format($sum, 0, '.', ' ') ?> ₽
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="mt-3 rounded-2xl border border-slate-800 bg-slate-950/50 px-3 py-3 space-y-1.5 text-sm">
                <div class="flex items-center justify-between gap-3">
                    <span class="text-slate-500">Сумма блюд</span>
                    <span class="text-slate-200 font-semibold"><?= number_format($orderGrossTotal, 0, '.', ' ') ?> ₽</span>
                </div>
                <?php if ($orderSpentTotal > 0): ?>
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-amber-300">Списано бонусами</span>
                        <span class="text-amber-300 font-semibold">-<?= number_format($orderSpentTotal, 0, '.', ' ') ?> ₽</span>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-slate-400">К оплате</span>
                        <span class="text-emerald-300 font-semibold"><?= number_format($orderPayableTotal, 0, '.', ' ') ?> ₽</span>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <div id="order-track-upsell" class="rounded-3xl bg-slate-900/80 border border-slate-800 p-4 hidden">
        <div class="text-sm font-semibold mb-2">Добавить к заказу?</div>
        <div class="text-[11px] text-slate-500 mb-2">Часто берут вместе — можно добавить в меню.</div>
        <div id="order-track-upsell-items" class="flex flex-wrap gap-2"></div>
    </div>

    <?php
    $orderStatusForFeedback = mb_strtolower(trim((string)($order['order_status'] ?? '')), 'UTF-8');
    $feedbackStatusReady = in_array($orderStatusForFeedback, ['delivered', 'completed'], true);
    $showFeedbackForm = ($feedbackStatusReady && !$hasOrderFeedback && $feedbackTrackTrusted);
    $showTipForm = ($feedbackStatusReady && $feedbackTrackTrusted);
    $feedbackToken = '';
    if ($showFeedbackForm) {
        $feedbackToken = order_feedback_mint_token((int)$orderId);
    }
    $feedbackPendingPoll = ($feedbackTrackTrusted && !$hasOrderFeedback && !$feedbackStatusReady);
    $tipToken = '';
    if ($showTipForm) {
        $tipToken = bin2hex(random_bytes(16));
        $_SESSION['order_tip_token_' . (int)$orderId] = $tipToken;
    }
    ?>
    <?php if ($feedbackTrackTrusted && !$hasOrderFeedback): ?>
    <div id="order-track-feedback"
         class="rounded-3xl bg-slate-900/80 border border-slate-800 p-4 <?= $showFeedbackForm ? '' : 'hidden' ?>"
         data-pending-poll="<?= $feedbackPendingPoll ? '1' : '0' ?>">
        <div class="text-sm font-semibold mb-2">Оцените ваш заказ</div>
        <p id="feedback-loading" class="hidden text-xs text-slate-400 mb-2">Подготавливаем форму отзыва...</p>
        <form id="feedback-form" class="space-y-3 <?= $showFeedbackForm ? '' : 'hidden' ?>">
            <input type="hidden" name="order_id" value="<?= (int)$orderId ?>">
            <input type="hidden" name="table_id" value="<?= (int)$tableId ?>">
            <input type="hidden" name="feedback_token" id="feedback-token" value="<?= e($feedbackToken) ?>">
            <input type="hidden" name="source" value="order_track">
            <div>
                <label class="block text-xs text-slate-400 mb-1">Оценка</label>
                <div class="flex gap-2" role="group" aria-label="Оценка от 1 до 5">
                    <?php for ($i = 1; $i <= 5; $i++): ?>
                        <button type="button" class="feedback-star w-10 h-10 rounded-xl border border-slate-600 bg-slate-800/80 text-slate-400 hover:border-amber-500/60 hover:text-amber-400 transition-colors" data-rating="<?= $i ?>" aria-pressed="false" title="<?= $i ?>">⭐</button>
                    <?php endfor; ?>
                </div>
                <input type="hidden" name="rating" id="feedback-rating" value="">
            </div>
            <div>
                <label class="block text-xs text-slate-400 mb-1">NPS (0–10)</label>
                <div class="grid grid-cols-6 sm:grid-cols-11 gap-1.5" role="group" aria-label="Оценка NPS от 0 до 10">
                    <?php for ($n = 0; $n <= 10; $n++): ?>
                        <button type="button" class="feedback-nps-btn h-8 rounded-lg border border-slate-600 bg-slate-800/80 text-[11px] text-slate-300 hover:border-indigo-400/60 hover:text-indigo-200 transition-colors" data-nps="<?= $n ?>" aria-pressed="false"><?= $n ?></button>
                    <?php endfor; ?>
                </div>
                <input type="hidden" name="nps_score" id="feedback-nps" value="">
            </div>
            <div>
                <label class="block text-xs text-slate-400 mb-1">Что особенно запомнилось?</label>
                <div class="flex flex-wrap gap-2">
                    <label class="inline-flex items-center gap-1.5 text-xs text-slate-300">
                        <input type="checkbox" name="review_tags[]" value="fast_delivery" class="rounded border-slate-600 bg-slate-900"> Быстрая доставка
                    </label>
                    <label class="inline-flex items-center gap-1.5 text-xs text-slate-300">
                        <input type="checkbox" name="review_tags[]" value="tasty_food" class="rounded border-slate-600 bg-slate-900"> Вкусная еда
                    </label>
                    <label class="inline-flex items-center gap-1.5 text-xs text-slate-300">
                        <input type="checkbox" name="review_tags[]" value="cold_food" class="rounded border-slate-600 bg-slate-900"> Еда остыла
                    </label>
                    <label class="inline-flex items-center gap-1.5 text-xs text-slate-300">
                        <input type="checkbox" name="review_tags[]" value="late_delivery" class="rounded border-slate-600 bg-slate-900"> Долгая доставка
                    </label>
                    <label class="inline-flex items-center gap-1.5 text-xs text-slate-300">
                        <input type="checkbox" name="review_tags[]" value="polite_courier" class="rounded border-slate-600 bg-slate-900"> Вежливый курьер
                    </label>
                    <label class="inline-flex items-center gap-1.5 text-xs text-slate-300">
                        <input type="checkbox" name="review_tags[]" value="bad_packaging" class="rounded border-slate-600 bg-slate-900"> Плохая упаковка
                    </label>
                </div>
            </div>
            <div>
                <label class="block text-xs text-slate-400 mb-1">Комментарий (необязательно)</label>
                <textarea name="review_text" id="feedback-comment" rows="2" maxlength="2000" placeholder="Напишите отзыв..."
                    class="w-full rounded-xl bg-slate-950/70 border border-slate-700 px-3 py-2 text-sm text-slate-100 placeholder-slate-500"></textarea>
            </div>
            <button type="submit" id="feedback-submit" disabled class="px-4 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white text-sm font-medium disabled:opacity-50">
                Отправить
            </button>
        </form>
        <p id="feedback-thanks" class="hidden mt-2 text-sm text-emerald-300">Спасибо за отзыв!</p>
        <p id="feedback-error" class="hidden mt-2 text-sm text-red-300">Не удалось сохранить отзыв. Попробуйте позже.</p>
        <p id="feedback-handshake-error" class="hidden mt-2 text-sm text-red-300">Не удалось подготовить форму отзыва. Обновите страницу.</p>
    </div>
    <?php endif; ?>
    <div id="yandex-review-prompt" class="rounded-3xl bg-slate-900/80 border border-slate-800 p-4 <?= $showYandexReviewPrompt ? '' : 'hidden' ?>">
        <div class="text-sm font-semibold text-slate-100 mb-1">Спасибо за высокую оценку!</div>
        <p class="text-xs text-slate-400 mb-3">
            Если вам всё понравилось, нам будет очень приятно, если вы оставите отзыв в Яндекс.Картах.
        </p>
        <a id="yandex-review-link" href="<?= e($yandexReviewUrl) ?>" target="_blank" rel="noopener"
           class="inline-flex items-center px-4 py-2 rounded-xl bg-amber-500 hover:bg-amber-400 text-slate-950 text-sm font-semibold">
            Оставить отзыв в Яндексе
        </a>
        <p class="text-[11px] text-slate-500 mt-2">Это поможет другим гостям найти нас.</p>
    </div>

    <?php if ($showTipForm): ?>
    <div id="order-track-tip" class="rounded-3xl bg-slate-900/80 border border-slate-800 p-4">
        <div class="text-sm font-semibold text-slate-100 mb-1">Оставить чаевые</div>
        <p class="text-xs text-slate-400 mb-3">Поддержите <?= $orderTypeNormalized === 'delivery' ? 'курьера' : 'официанта' ?>. Сейчас это tip-intent без списания оплаты.</p>
        <?php if ($hasOrderTip): ?>
            <div class="rounded-2xl border border-emerald-500/40 bg-emerald-500/10 px-3 py-2 text-sm text-emerald-100">
                Чаевые уже сохранены: <?= number_format($orderTipAmount, 0, '.', ' ') ?> ₽ · <?= e($orderTipStatus) ?> · <?= $orderTipTarget === 'courier' ? 'курьеру' : 'официанту' ?>
            </div>
        <?php else: ?>
            <form id="tip-form" class="space-y-3">
                <input type="hidden" name="order_id" value="<?= (int)$orderId ?>">
                <input type="hidden" name="table_id" value="<?= (int)$tableId ?>">
                <input type="hidden" name="tip_token" id="tip-token" value="<?= e($tipToken) ?>">
                <input type="hidden" name="target" value="<?= e($orderTypeNormalized === 'delivery' ? 'courier' : 'waiter') ?>">
                <input type="hidden" name="currency" value="RUB">
                <div class="grid grid-cols-4 gap-2">
                    <?php foreach ([50, 100, 200] as $tipQuick): ?>
                        <button type="button" class="tip-quick-btn rounded-xl border border-slate-600 bg-slate-800/80 py-2 text-sm text-slate-200 hover:border-emerald-500/60 hover:text-emerald-200" data-amount="<?= (int)$tipQuick ?>">
                            <?= (int)$tipQuick ?> ₽
                        </button>
                    <?php endforeach; ?>
                    <div class="rounded-xl border border-slate-700 bg-slate-950/70 px-2 py-1 flex items-center">
                        <input type="number" min="1" step="1" placeholder="Своя" id="tip-custom-input" class="w-full bg-transparent text-sm text-slate-100 placeholder-slate-500 focus:outline-none">
                    </div>
                </div>
                <input type="hidden" name="amount" id="tip-amount" value="">
                <div>
                    <label class="block text-xs text-slate-400 mb-1">Комментарий (необязательно)</label>
                    <input type="text" name="note" maxlength="255" placeholder="Спасибо за сервис" class="w-full rounded-xl bg-slate-950/70 border border-slate-700 px-3 py-2 text-sm text-slate-100 placeholder-slate-500">
                </div>
                <button type="submit" id="tip-submit" disabled class="px-4 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white text-sm font-medium disabled:opacity-50">
                    Зафиксировать чаевые
                </button>
            </form>
            <p id="tip-thanks" class="hidden mt-2 text-sm text-emerald-300">Чаевые сохранены. Спасибо!</p>
            <p id="tip-error" class="hidden mt-2 text-sm text-red-300">Не удалось сохранить чаевые. Попробуйте позже.</p>
        <?php endif; ?>
    </div>
    <?php endif; ?>

</div>

<script>
(function(){
    const tableId = <?= (int)$tableId ?>;
    const orderId = <?= (int)$orderId ?>;
    const menuBackUrl = <?= json_encode($orderTrackMenuUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const BASE_INTERVAL = 10000;
    const BACKOFFS = [15000, 30000, 60000];
    const FINAL_STATUSES = ['delivered', 'completed', 'cancelled', 'canceled'];
    const yandexReviewUrl = <?= json_encode((!is_demo_mode() ? $yandexReviewUrl : ''), JSON_UNESCAPED_UNICODE) ?>;

    const elLabel = document.getElementById('st_label');
    const elDesc  = document.getElementById('st_desc');
    const elBar   = document.getElementById('st_bar');
    const orderProgressItems = document.getElementById('order-progress-items');
    const orderProgressItemsValue = document.getElementById('order-progress-items-value');
    const orderProgressItemsNote = document.getElementById('order-progress-items-note');
    const deliveryStepBadge = document.getElementById('delivery-step-badge');
    const deliveryEtaBadge = document.getElementById('delivery-eta-badge');
    const deliveryTimingLine = document.getElementById('delivery-timing-line');
    const deliveryLocationLine = document.getElementById('delivery-location-line');

    const restaurantId = <?= (int)($currentRestaurant['id'] ?? 0) ?>;
    const paymentTimerBlock = document.getElementById('payment-timer-block');
    const waiterCallBlock = document.getElementById('waiter-call-block');
    const waiterCallBtn = document.getElementById('btn-waiter-call');
    const waiterCallMessage = document.getElementById('waiter-call-message');
    const initialPaymentTimerSeconds = <?= $countdownSecondsRemaining !== null ? (int)$countdownSecondsRemaining : 'null' ?>;
    let paymentTimerSeconds = initialPaymentTimerSeconds;
    let paymentTimerTick = null;

    function formatMMSS(sec) {
        sec = Math.max(0, Number(sec || 0));
        const mm = String(Math.floor(sec / 60)).padStart(2, '0');
        const ss = String(sec % 60).padStart(2, '0');
        return mm + ':' + ss;
    }

    function applyPaymentTimerState(seconds) {
        if (!paymentTimerBlock) return;
        if (seconds === null || typeof seconds === 'undefined') {
            paymentTimerBlock.classList.add('hidden');
            return;
        }
        paymentTimerBlock.classList.remove('hidden');
        const s = Math.max(0, Number(seconds || 0));
        const expired = s <= 0;
        const warning = !expired && s <= (3 * 60);

        const state = expired ? 'expired' : (warning ? 'warning' : 'ok');
        if (paymentTimerBlock.dataset.state === state && document.getElementById('payment-timer-mmss')) {
            // Только обновим цифры при стабильном состоянии.
            const mmss = document.getElementById('payment-timer-mmss');
            if (mmss) mmss.textContent = formatMMSS(s);
            return;
        }

        paymentTimerBlock.dataset.state = state;
        if (expired) {
            paymentTimerBlock.className = 'mt-3 rounded-2xl border border-red-500/40 bg-red-500/10 px-3 py-2';
            paymentTimerBlock.innerHTML = `
                <div class="text-sm font-semibold text-red-200">Время ожидания оплаты истекло</div>
                <div class="text-[11px] text-red-200/80 mt-1">Осталось: <span id="payment-timer-mmss">${formatMMSS(s)}</span></div>
            `;
        } else {
            const border = warning ? 'border-amber-500/40 bg-amber-500/10' : 'border-emerald-500/30 bg-emerald-500/10';
            const text = warning ? 'text-amber-200' : 'text-emerald-200';
            paymentTimerBlock.className = 'mt-3 rounded-2xl border ' + border + ' px-3 py-2';
            paymentTimerBlock.innerHTML = `
                <div class="text-sm font-semibold ${text}">Официант должен подойти в течение 10 минут</div>
                <div class="text-[11px] ${text}/80 mt-1">Осталось: <span id="payment-timer-mmss">${formatMMSS(s)}</span></div>
            `;
        }
    }

    function startPaymentTimerTick() {
        if (paymentTimerTick) clearInterval(paymentTimerTick);
        if (paymentTimerSeconds === null || typeof paymentTimerSeconds === 'undefined') return;
        paymentTimerTick = setInterval(function () {
            if (paymentTimerSeconds === null || typeof paymentTimerSeconds === 'undefined') return;
            paymentTimerSeconds = Math.max(0, paymentTimerSeconds - 1);
            applyPaymentTimerState(paymentTimerSeconds);
        }, 1000);
    }

    async function syncPaymentTimer() {
        if (!paymentTimerBlock) return;
        try {
            const r = await fetch('/guest/order_timer_status.php?table_id=' + tableId + '&order_id=' + orderId, {
                method: 'GET',
                cache: 'no-store',
                headers: { 'Accept': 'application/json' }
            });
            const data = await r.json();
            if (!data || !data.success) return;

            if (!data.countdown_active) {
                paymentTimerSeconds = null;
                applyPaymentTimerState(null);
                if (paymentTimerTick) clearInterval(paymentTimerTick);
                paymentTimerTick = null;
                return;
            }

            paymentTimerSeconds = typeof data.countdown_seconds_remaining !== 'undefined'
                ? Number(data.countdown_seconds_remaining || 0)
                : 0;
            applyPaymentTimerState(paymentTimerSeconds);
        } catch (e) {}
    }

    if (paymentTimerSeconds !== null && typeof paymentTimerSeconds !== 'undefined') {
        applyPaymentTimerState(paymentTimerSeconds);
        startPaymentTimerTick();
    }

    // Кнопка "Позвать официанта"
    if (waiterCallBtn) {
        const cooldownKey = 'waiter_call_last_' + restaurantId + '_' + tableId + '_' + orderId;
        const COOLDOWN_MS = 60 * 1000;
        waiterCallBtn.addEventListener('click', async function () {
            const now = Date.now();
            const last = parseInt(localStorage.getItem(cooldownKey) || '0', 10);
            if (now - last < COOLDOWN_MS) {
                if (waiterCallMessage) {
                    waiterCallMessage.textContent = 'Запрос уже отправлен. Ожидайте.';
                    waiterCallMessage.classList.remove('hidden');
                }
                return;
            }

            waiterCallBtn.disabled = true;
            waiterCallBtn.classList.add('opacity-70', 'cursor-not-allowed');
            localStorage.setItem(cooldownKey, String(now));

            try {
                const form = new FormData();
                form.append('table_id', tableId);
                form.append('order_id', orderId);

                const r = await fetch('/guest/waiter_call.php', {
                    method: 'POST',
                    body: form,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });
                const data = await r.json();
                if (waiterCallMessage) {
                    waiterCallMessage.classList.remove('hidden');
                    waiterCallMessage.textContent = (data && data.success)
                        ? 'Официант уведомлён'
                        : ((data && data.message) ? data.message : 'Не удалось вызвать официанта');
                }
            } catch (e) {
                if (waiterCallMessage) {
                    waiterCallMessage.classList.remove('hidden');
                    waiterCallMessage.textContent = 'Ошибка сети при вызове официанта';
                }
            } finally {
                setTimeout(function () {
                    waiterCallBtn.disabled = false;
                    waiterCallBtn.classList.remove('opacity-70', 'cursor-not-allowed');
                }, COOLDOWN_MS);
            }
        });
    }

    let timer = null;
    let inFlight = false;
    let backoffIndex = -1;
    let nextDelay = BASE_INTERVAL;

    var FEEDBACK_STATE_MS = 2500;
    var FEEDBACK_STATE_MAX = 24;
    var feedbackHandshakeDone = false;
    var feedbackHandshakeStarted = false;
    var feedbackStateInFlight = false;
    var feedbackHandshakeAttempts = 0;
    var feedbackStateTimer = null;

    function hideFeedbackLoading() {
        var ld = document.getElementById('feedback-loading');
        if (ld) ld.classList.add('hidden');
    }
    function showFeedbackLoading() {
        var ld = document.getElementById('feedback-loading');
        if (ld) ld.classList.remove('hidden');
    }
    function markFeedbackHandshakeResolved() {
        feedbackHandshakeDone = true;
        if (feedbackStateTimer) clearTimeout(feedbackStateTimer);
        feedbackStateTimer = null;
        hideFeedbackLoading();
    }
    function hideFeedbackBlockQuietly() {
        var fb = document.getElementById('order-track-feedback');
        var he = document.getElementById('feedback-handshake-error');
        hideFeedbackLoading();
        if (fb) fb.classList.add('hidden');
        if (he) he.classList.add('hidden');
    }
    function failFeedbackHandshake() {
        markFeedbackHandshakeResolved();
        var he = document.getElementById('feedback-handshake-error');
        var fb = document.getElementById('order-track-feedback');
        hideFeedbackLoading();
        if (fb) {
            var fm = document.getElementById('feedback-form');
            if (fm) fm.classList.add('hidden');
            fb.classList.remove('hidden');
        }
        if (he) he.classList.remove('hidden');
    }
    function scheduleFeedbackStateRetry() {
        if (feedbackHandshakeDone) return;
        if (feedbackHandshakeAttempts >= FEEDBACK_STATE_MAX) {
            failFeedbackHandshake();
            return;
        }
        if (feedbackStateTimer) clearTimeout(feedbackStateTimer);
        feedbackStateTimer = setTimeout(function() {
            feedbackStateTimer = null;
            runFeedbackStateAttempt();
        }, FEEDBACK_STATE_MS);
    }
    function runFeedbackStateAttempt() {
        if (feedbackHandshakeDone) return;
        var fb = document.getElementById('order-track-feedback');
        if (!fb || fb.dataset.submitted === '1') {
            markFeedbackHandshakeResolved();
            return;
        }
        var tok = document.getElementById('feedback-token');
        if (tok && tok.value) {
            var fm = document.getElementById('feedback-form');
            if (fm) fm.classList.remove('hidden');
            fb.classList.remove('hidden');
            markFeedbackHandshakeResolved();
            return;
        }
        if (feedbackStateInFlight) return;
        if (feedbackHandshakeAttempts >= FEEDBACK_STATE_MAX) {
            failFeedbackHandshake();
            return;
        }
        feedbackHandshakeAttempts++;
        feedbackStateInFlight = true;
        showFeedbackLoading();
        fb.classList.remove('hidden');
        var formEl = document.getElementById('feedback-form');
        if (formEl) formEl.classList.add('hidden');
        var he = document.getElementById('feedback-handshake-error');
        if (he) he.classList.add('hidden');

        fetch('/ajax/order_feedback_state.php?table_id=' + tableId + '&order_id=' + orderId, {
            method: 'GET',
            cache: 'no-store',
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        })
        .then(function(r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        })
        .then(function(st) {
            feedbackStateInFlight = false;
            if (feedbackHandshakeDone) return;
            if (!st) {
                scheduleFeedbackStateRetry();
                return;
            }
            if (st.success === false) {
                if (st.stop_feedback_retry) {
                    hideFeedbackBlockQuietly();
                    markFeedbackHandshakeResolved();
                    return;
                }
                scheduleFeedbackStateRetry();
                return;
            }
            if (st.feedback_exists) {
                fb.dataset.submitted = '1';
                var th = document.getElementById('feedback-thanks');
                var fm = document.getElementById('feedback-form');
                if (fm) fm.classList.add('hidden');
                if (th) { th.textContent = 'Спасибо за отзыв!'; th.classList.remove('hidden'); }
                fb.classList.remove('hidden');
                markFeedbackHandshakeResolved();
                return;
            }
            if (st.eligible && st.feedback_token && tok) {
                tok.value = st.feedback_token;
                if (formEl) formEl.classList.remove('hidden');
                hideFeedbackLoading();
                fb.classList.remove('hidden');
                markFeedbackHandshakeResolved();
                return;
            }
            if (st.stop_feedback_retry) {
                hideFeedbackBlockQuietly();
                markFeedbackHandshakeResolved();
                return;
            }
            scheduleFeedbackStateRetry();
        })
        .catch(function() {
            feedbackStateInFlight = false;
            if (feedbackHandshakeDone) return;
            scheduleFeedbackStateRetry();
        });
    }
    function startFeedbackHandshake() {
        if (feedbackHandshakeDone) return;
        var fb = document.getElementById('order-track-feedback');
        if (!fb || fb.dataset.submitted === '1') {
            markFeedbackHandshakeResolved();
            return;
        }
        var tok = document.getElementById('feedback-token');
        if (tok && tok.value) {
            var fm = document.getElementById('feedback-form');
            if (fm) fm.classList.remove('hidden');
            fb.classList.remove('hidden');
            markFeedbackHandshakeResolved();
            return;
        }
        if (feedbackHandshakeStarted) return;
        feedbackHandshakeStarted = true;
        feedbackHandshakeAttempts = 0;
        runFeedbackStateAttempt();
    }

    function isFinal(status) {
        if (!status) return false;
        const s = String(status).toLowerCase();
        return FINAL_STATUSES.some(function(f){ return f === s; });
    }

    function scheduleNext() {
        if (timer) clearTimeout(timer);
        timer = setTimeout(refresh, nextDelay);
    }

    function refresh() {
        if (inFlight) return;
        inFlight = true;
        fetch('/ajax/order_status.php?table_id=' + tableId + '&order_id=' + orderId, {
            method: 'GET',
            cache: 'no-store',
            headers: { 'Accept': 'application/json' }
        })
        .then(function(r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        })
        .then(function(data) {
            inFlight = false;
            if (!data || !data.success) {
                backoffIndex = Math.min(backoffIndex + 1, BACKOFFS.length - 1);
                nextDelay = BACKOFFS[backoffIndex];
                scheduleNext();
                return;
            }
            backoffIndex = -1;
            nextDelay = BASE_INTERVAL;

            if (elLabel) elLabel.textContent = data.guest_status_label || data.order_status_label || '';
            if (elDesc)  elDesc.textContent  = data.guest_status_description || data.order_status_description || '';
            if (deliveryStepBadge) {
                var dt = String(data.order_type || '');
                var csLabel = String(data.courier_status_label || '');
                if (dt === 'delivery' && csLabel) {
                    deliveryStepBadge.classList.remove('hidden');
                    deliveryStepBadge.textContent = 'Доставка: ' + csLabel;
                } else {
                    deliveryStepBadge.classList.add('hidden');
                    deliveryStepBadge.textContent = '';
                }
            }
            if (deliveryEtaBadge || deliveryTimingLine) {
                var dt2 = String(data.order_type || '');
                var etaLabel = String(data.eta_label || '');
                var timingState = String(data.timing_state || '');
                if (dt2 === 'delivery' && etaLabel) {
                    if (deliveryEtaBadge) {
                        deliveryEtaBadge.classList.remove('hidden');
                        deliveryEtaBadge.textContent = 'ETA: ' + etaLabel;
                    }
                    if (deliveryTimingLine) {
                        deliveryTimingLine.classList.remove('hidden');
                        deliveryTimingLine.textContent = etaLabel;
                        deliveryTimingLine.classList.remove('text-amber-200/90', 'text-red-200', 'text-emerald-200');
                        if (timingState === 'almost_arrived') {
                            deliveryTimingLine.classList.add('text-emerald-200');
                        } else if (timingState === 'searching_courier') {
                            deliveryTimingLine.classList.add('text-red-200');
                        } else {
                            deliveryTimingLine.classList.add('text-amber-200/90');
                        }
                    }
                } else {
                    if (deliveryEtaBadge) {
                        deliveryEtaBadge.classList.add('hidden');
                        deliveryEtaBadge.textContent = '';
                    }
                    if (deliveryTimingLine) {
                        deliveryTimingLine.classList.add('hidden');
                        deliveryTimingLine.textContent = '';
                    }
                }
            }
            if (deliveryLocationLine) {
                var dtLoc = String(data.order_type || '');
                var locLabel = String(data.courier_location_label || '');
                var locAgo = String(data.courier_location_last_update_human || '');
                var locState = String(data.courier_location_state || 'offline');
                if (dtLoc === 'delivery') {
                    deliveryLocationLine.classList.remove('hidden');
                    var txt = locLabel || 'Геопозиция курьера недоступна';
                    if (locAgo) txt += ' · ' + locAgo;
                    deliveryLocationLine.textContent = txt;
                    deliveryLocationLine.classList.remove('text-emerald-200', 'text-amber-200', 'text-slate-400');
                    if (locState === 'live') {
                        deliveryLocationLine.classList.add('text-emerald-200');
                    } else if (locState === 'stale') {
                        deliveryLocationLine.classList.add('text-amber-200');
                    } else {
                        deliveryLocationLine.classList.add('text-slate-400');
                    }
                } else {
                    deliveryLocationLine.classList.add('hidden');
                    deliveryLocationLine.textContent = '';
                }
            }

            if (elBar && typeof data.progress !== 'undefined') {
                var p = Number(data.progress) || 0;
                if (p < 0) p = 0;
                if (p > 100) p = 100;
                elBar.style.width = p + '%';
            }

            if (orderProgressItems && orderProgressItemsValue) {
                var totalItemsCount = Number(data.total_items_count || 0);
                var readyItemsCount = Number(data.ready_items_count || 0);
                var partialReady = !!data.partial_ready;
                if (totalItemsCount > 0) {
                    orderProgressItems.classList.remove('hidden');
                    orderProgressItemsValue.textContent = readyItemsCount + '/' + totalItemsCount;
                    if (orderProgressItemsNote) {
                        orderProgressItemsNote.classList.toggle('hidden', !partialReady);
                    }
                } else {
                    orderProgressItems.classList.add('hidden');
                    if (orderProgressItemsNote) orderProgressItemsNote.classList.add('hidden');
                }
            }

            // Синхронизируем 10-минутный таймер ожидания оплаты (офлайн и unpaid)
            syncPaymentTimer().catch(function () {});

            if (data.order_status === 'delivered' || data.order_status === 'completed') {
                var fb = document.getElementById('order-track-feedback');
                if (fb && !fb.dataset.submitted) {
                    var tok = document.getElementById('feedback-token');
                    if (tok && tok.value) {
                        fb.classList.remove('hidden');
                    } else {
                        startFeedbackHandshake();
                    }
                }
            }
            if (data.is_final || isFinal(data.order_status)) {
                if (timer) clearTimeout(timer);
                timer = null;
                return;
            }
            scheduleNext();
        })
        .catch(function() {
            inFlight = false;
            backoffIndex = Math.min(backoffIndex + 1, BACKOFFS.length - 1);
            nextDelay = BACKOFFS[backoffIndex];
            scheduleNext();
        });
    }

    refresh();

    // Offers by order context (order_id passed to qr_offers)
    (function(){
        var container = document.getElementById('order-track-upsell');
        var itemsEl = document.getElementById('order-track-upsell-items');
        if (!container || !itemsEl) return;
        fetch('/qr_offers.php?table_id=' + tableId + '&order_id=' + orderId, { cache: 'no-store' })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data || !data.success || !Array.isArray(data.items) || data.items.length === 0) return;
                var qrUrl = menuBackUrl || ('/qr.php?table_id=' + tableId);
                itemsEl.innerHTML = data.items.slice(0, 3).map(function(it) {
                    var name = (it.name || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
                    var price = (Number(it.price || 0)).toLocaleString('ru-RU', { maximumFractionDigits: 0 });
                    return '<a href="' + qrUrl + '" class="inline-flex items-center gap-1.5 rounded-xl bg-slate-950/80 border border-slate-700 px-3 py-2 text-xs text-slate-200 hover:border-emerald-500/60">' +
                        '<span>' + name + ' — ' + price + ' ₽</span>' +
                        '<span class="text-emerald-400">+</span></a>';
                }).join('');
                container.classList.remove('hidden');
            })
            .catch(function() {});
    })();

    // Feedback: stars + submit
    (function(){
        var form = document.getElementById('feedback-form');
        var block = document.getElementById('order-track-feedback');
        var yandexPrompt = document.getElementById('yandex-review-prompt');
        var yandexLink = document.getElementById('yandex-review-link');
        var ratingInput = document.getElementById('feedback-rating');
        var npsInput = document.getElementById('feedback-nps');
        var submitBtn = document.getElementById('feedback-submit');
        var thanksEl = document.getElementById('feedback-thanks');
        var errorEl = document.getElementById('feedback-error');
        if (!form || !block) return;

        var stars = block.querySelectorAll('.feedback-star');
        var npsButtons = block.querySelectorAll('.feedback-nps-btn');
        var selectedRating = 0;
        var selectedNps = null;
        stars.forEach(function(btn) {
            btn.addEventListener('click', function() {
                var r = parseInt(btn.getAttribute('data-rating'), 10);
                selectedRating = r;
                if (ratingInput) ratingInput.value = r;
                if (submitBtn) submitBtn.disabled = false;
                stars.forEach(function(s) {
                    var sr = parseInt(s.getAttribute('data-rating'), 10);
                    s.setAttribute('aria-pressed', sr <= r ? 'true' : 'false');
                    s.classList.toggle('border-amber-500', sr <= r);
                    s.classList.toggle('text-amber-400', sr <= r);
                    s.classList.toggle('bg-amber-500/20', sr <= r);
                });
            });
        });
        npsButtons.forEach(function(btn) {
            btn.addEventListener('click', function() {
                var n = parseInt(btn.getAttribute('data-nps'), 10);
                if (isNaN(n) || n < 0 || n > 10) return;
                selectedNps = n;
                if (npsInput) npsInput.value = String(n);
                npsButtons.forEach(function(b) {
                    var bn = parseInt(b.getAttribute('data-nps'), 10);
                    var active = bn === n;
                    b.setAttribute('aria-pressed', active ? 'true' : 'false');
                    b.classList.toggle('border-indigo-400', active);
                    b.classList.toggle('text-indigo-100', active);
                    b.classList.toggle('bg-indigo-500/20', active);
                });
            });
        });

        form.addEventListener('submit', function(e) {
            e.preventDefault();
            if (errorEl) errorEl.classList.add('hidden');
            if (!selectedRating || selectedRating < 1 || selectedRating > 5) {
                return;
            }
            var tokEl = document.getElementById('feedback-token');
            if (!tokEl || !tokEl.value) {
                if (errorEl) {
                    errorEl.textContent = 'Обновите страницу, чтобы отправить отзыв.';
                    errorEl.classList.remove('hidden');
                }
                return;
            }
            if (submitBtn) submitBtn.disabled = true;
            var fd = new FormData(form);
            fd.append('rating', String(selectedRating));
            if (selectedNps !== null) {
                fd.set('nps_score', String(selectedNps));
            }
            fetch('/ajax/guest_review_submit.php', { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data && data.success) {
                        block.dataset.submitted = '1';
                        if (thanksEl) thanksEl.classList.remove('hidden');
                        form.classList.add('hidden');
                        if (selectedRating >= 5 && yandexPrompt && yandexReviewUrl) {
                            if (yandexLink) yandexLink.href = yandexReviewUrl;
                            yandexPrompt.classList.remove('hidden');
                        }
                        return;
                    }
                    if (submitBtn) submitBtn.disabled = false;
                    if (errorEl) {
                        errorEl.textContent = (data && data.message) ? data.message : 'Не удалось сохранить отзыв. Попробуйте позже.';
                        errorEl.classList.remove('hidden');
                    }
                })
                .catch(function() {
                    if (submitBtn) submitBtn.disabled = false;
                    if (errorEl) {
                        errorEl.textContent = 'Не удалось сохранить отзыв. Попробуйте позже.';
                        errorEl.classList.remove('hidden');
                    }
                });
        });
    })();

    // Tips: intent only (no acquiring on this step)
    (function () {
        var form = document.getElementById('tip-form');
        if (!form) return;
        var amountInput = document.getElementById('tip-amount');
        var customInput = document.getElementById('tip-custom-input');
        var submitBtn = document.getElementById('tip-submit');
        var thanksEl = document.getElementById('tip-thanks');
        var errorEl = document.getElementById('tip-error');
        var quickButtons = document.querySelectorAll('.tip-quick-btn');

        function setAmount(v) {
            var n = Number(v || 0);
            if (!isFinite(n) || n <= 0) {
                if (amountInput) amountInput.value = '';
                if (submitBtn) submitBtn.disabled = true;
                return;
            }
            n = Math.round(n);
            if (amountInput) amountInput.value = String(n);
            if (submitBtn) submitBtn.disabled = false;
        }

        quickButtons.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var v = parseInt(btn.getAttribute('data-amount') || '0', 10);
                if (!isFinite(v) || v <= 0) return;
                setAmount(v);
                quickButtons.forEach(function (b) {
                    var active = b === btn;
                    b.classList.toggle('border-emerald-400', active);
                    b.classList.toggle('text-emerald-100', active);
                    b.classList.toggle('bg-emerald-500/20', active);
                });
                if (customInput) customInput.value = '';
            });
        });

        if (customInput) {
            customInput.addEventListener('input', function () {
                quickButtons.forEach(function (b) {
                    b.classList.remove('border-emerald-400', 'text-emerald-100', 'bg-emerald-500/20');
                });
                setAmount(customInput.value);
            });
        }

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            if (errorEl) errorEl.classList.add('hidden');
            if (thanksEl) thanksEl.classList.add('hidden');
            var amountVal = Number((amountInput && amountInput.value) ? amountInput.value : 0);
            if (!isFinite(amountVal) || amountVal <= 0) {
                if (errorEl) {
                    errorEl.textContent = 'Выберите сумму чаевых.';
                    errorEl.classList.remove('hidden');
                }
                return;
            }
            if (submitBtn) submitBtn.disabled = true;
            var fd = new FormData(form);
            fetch('/ajax/order_tip_intent.php', { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data && data.success) {
                        if (thanksEl) {
                            thanksEl.textContent = (data.message ? String(data.message) : 'Чаевые сохранены.');
                            thanksEl.classList.remove('hidden');
                        }
                        form.classList.add('hidden');
                        return;
                    }
                    if (submitBtn) submitBtn.disabled = false;
                    if (errorEl) {
                        errorEl.textContent = (data && data.message) ? String(data.message) : 'Не удалось сохранить чаевые.';
                        errorEl.classList.remove('hidden');
                    }
                })
                .catch(function () {
                    if (submitBtn) submitBtn.disabled = false;
                    if (errorEl) {
                        errorEl.textContent = 'Не удалось сохранить чаевые.';
                        errorEl.classList.remove('hidden');
                    }
                });
        });
    })();
})();
</script>
<?php if ($showOrderBonusAttach): ?>
<script>
(function () {
    var orderId = <?= (int)$orderId ?>;
    var msgEl = document.getElementById('order-track-bonus-msg');
    var errEl = document.getElementById('order-track-bonus-err');
    var banner = document.getElementById('order-track-bonus-banner');

    function mapAttachErr(code) {
        var m = {
            order_not_found: 'Заказ не найден',
            order_phone_conflict: 'Этот заказ уже привязан к другому номеру',
            order_guest_conflict: 'Этот заказ уже привязан к другому гостю',
            loyalty_disabled: 'Бонусы сейчас недоступны',
            card_issue_failed: 'Не удалось оформить карту лояльности',
            accrual_failed: 'Не удалось начислить бонусы — попробуйте позже',
            save_failed: 'Не удалось сохранить — попробуйте позже'
        };
        return m[code] || 'Не получилось — попробуйте позже';
    }

    function showBannerOk(balance, points, already, pendingPayment) {
        if (errEl) errEl.classList.add('hidden');
        if (msgEl) {
            var balPart = (balance != null && balance !== '') ? (' Ваш баланс: ' + balance + ' бонусов.') : '';
            if (already) {
                msgEl.textContent = 'Бонусы за этот заказ уже на вашем счёте.' + balPart;
            } else if (pendingPayment) {
                msgEl.textContent = 'Номер сохранён. Бонусы начислим после подтверждённой оплаты.';
            } else if (points != null && points > 0) {
                msgEl.textContent = 'Готово! Бонусы начислены.' + balPart;
            } else {
                msgEl.textContent = 'Готово! Заказ привязан к вашему счёту.' + balPart;
            }
            msgEl.classList.remove('hidden');
        }
        if (banner) {
            var actions = banner.querySelectorAll('button');
            actions.forEach(function (b) { b.classList.add('hidden'); });
        }
    }

    function showBannerErr(t) {
        if (errEl) {
            errEl.textContent = t || 'Ошибка';
            errEl.classList.remove('hidden');
        }
    }

    function attachOrder() {
        return fetch('/guest/attach_order_loyalty.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({ order_id: orderId })
        }).then(function (r) {
            return r.json().then(function (j) { return { ok: r.ok, j: j }; });
        }).then(function (x) {
            if (!x.ok || !x.j.success) {
                var code = x.j && x.j.error ? x.j.error : '';
                throw new Error(mapAttachErr(code));
            }
            return x.j;
        });
    }

    var instant = document.getElementById('order-track-bonus-instant');
    if (instant) {
        instant.addEventListener('click', function () {
            instant.disabled = true;
            if (errEl) errEl.classList.add('hidden');
            attachOrder()
                .then(function (j) {
                    showBannerOk(j.balance, j.points, j.already, j.pending_payment);
                })
                .catch(function (e) {
                    showBannerErr(e.message || '');
                })
                .finally(function () {
                    instant.disabled = false;
                });
        });
    }
})();
</script>
<?php endif; ?>
<?php if ($orderTrackBonusOtp): ?>
<div id="guest-otp-backdrop-track" class="fixed inset-0 z-[60] hidden bg-black/65 backdrop-blur-[2px]" aria-hidden="true"></div>
<div id="guest-otp-modal-track" class="fixed inset-x-0 bottom-0 z-[60] hidden px-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] pt-6" role="dialog" aria-modal="true" aria-labelledby="guest-otp-title-track">
    <div class="mx-auto max-w-md rounded-t-3xl border border-slate-800 bg-slate-950/95 shadow-2xl shadow-black/50 backdrop-blur-xl overflow-hidden">
        <div class="flex items-center justify-between gap-2 px-4 py-3 border-b border-slate-800">
            <div id="guest-otp-title-track" class="text-sm font-semibold text-slate-100">Подтверждение номера</div>
            <button type="button" id="guest-otp-close-track" class="min-w-[44px] min-h-[44px] rounded-2xl bg-slate-800 text-slate-200 text-lg leading-none" aria-label="Закрыть">×</button>
        </div>
        <div class="p-4 space-y-4">
            <div id="guest-otp-step-phone-track" class="space-y-2">
                <p class="text-xs text-slate-500">Отправим SMS с кодом (действует 5 минут).</p>
                <label class="block text-[11px] text-slate-400">Телефон</label>
                <input type="tel" id="guest-otp-phone-track" autocomplete="tel" placeholder="+7 900 000-00-00"
                       class="w-full min-h-[48px] rounded-2xl bg-slate-900 border border-slate-700 px-3 text-base text-slate-50 placeholder:text-slate-600 focus:outline-none focus:ring-2 focus:ring-emerald-500">
                <p id="guest-otp-err-phone-track" class="text-xs text-red-300 hidden"></p>
                <button type="button" id="guest-otp-send-track" class="w-full min-h-[48px] rounded-2xl bg-emerald-500 hover:bg-emerald-400 text-slate-950 text-sm font-semibold">Получить код</button>
            </div>
            <div id="guest-otp-step-code-track" class="space-y-2 hidden">
                <div id="guest-otp-test-box-track" class="hidden rounded-2xl border border-sky-500/25 bg-sky-500/[0.08] px-3 py-3 space-y-2">
                    <div class="text-[11px] font-semibold text-sky-200/90 tracking-wide">Тестовый код</div>
                    <p id="guest-otp-test-msg-track" class="text-xs text-sky-100/80 leading-relaxed"></p>
                    <button type="button" id="guest-otp-test-fill-track" class="hidden w-full min-h-[44px] rounded-xl bg-slate-800/90 border border-slate-600/80 text-xs font-medium text-slate-200 hover:border-sky-500/40">Подставить тестовый код</button>
                </div>
                <p class="text-xs text-slate-500">Введите код из SMS</p>
                <label class="block text-[11px] text-slate-400">Код</label>
                <input type="text" inputmode="numeric" id="guest-otp-code-track" maxlength="8" autocomplete="one-time-code" placeholder="••••••"
                       class="w-full min-h-[48px] rounded-2xl bg-slate-900 border border-slate-700 px-3 text-lg tracking-widest text-slate-50 text-center focus:outline-none focus:ring-2 focus:ring-emerald-500">
                <p id="guest-otp-err-code-track" class="text-xs text-red-300 hidden"></p>
                <button type="button" id="guest-otp-verify-track" class="w-full min-h-[48px] rounded-2xl bg-emerald-500 hover:bg-emerald-400 text-slate-950 text-sm font-semibold">Подтвердить</button>
                <button type="button" id="guest-otp-back-track" class="w-full text-xs text-slate-500 hover:text-slate-300 py-2">Изменить номер</button>
            </div>
        </div>
    </div>
</div>
<script>
(function () {
    var orderId = <?= (int)$orderId ?>;
    var openBtn = document.getElementById('guest-otp-open-track');
    var backdrop = document.getElementById('guest-otp-backdrop-track');
    var modal = document.getElementById('guest-otp-modal-track');
    var closeBtn = document.getElementById('guest-otp-close-track');
    if (!backdrop || !modal) return;

    var stepPhone = document.getElementById('guest-otp-step-phone-track');
    var stepCode = document.getElementById('guest-otp-step-code-track');
    var inpPhone = document.getElementById('guest-otp-phone-track');
    var inpCode = document.getElementById('guest-otp-code-track');
    var errPhone = document.getElementById('guest-otp-err-phone-track');
    var errCode = document.getElementById('guest-otp-err-code-track');
    var btnSend = document.getElementById('guest-otp-send-track');
    var btnVerify = document.getElementById('guest-otp-verify-track');
    var btnBack = document.getElementById('guest-otp-back-track');
    var testBox = document.getElementById('guest-otp-test-box-track');
    var testMsg = document.getElementById('guest-otp-test-msg-track');
    var testFill = document.getElementById('guest-otp-test-fill-track');

    var msgEl = document.getElementById('order-track-bonus-msg');
    var errEl = document.getElementById('order-track-bonus-err');
    var banner = document.getElementById('order-track-bonus-banner');

    var lastPhone = '';
    var lastTestCode = '';

    function resetOtpTestUiTrack() {
        lastTestCode = '';
        if (testBox) testBox.classList.add('hidden');
        if (testMsg) testMsg.textContent = '';
        if (testFill) testFill.classList.add('hidden');
    }

    function mapErr(code) {
        var m = {
            invalid_phone: 'Проверьте формат номера',
            invalid_input: 'Введите номер и код',
            invalid_code: 'Код должен состоять из 6 цифр',
            rate_limited: 'Подождите минуту перед повторной отправкой',
            wrong_code: 'Неверный код',
            expired: 'Код истёк — запросите новый',
            too_many_attempts: 'Слишком много попыток — запросите новый код',
            service_unavailable: 'Сервис временно недоступен',
            internal_error: 'Временная ошибка сервиса. Попробуйте ещё раз',
            no_code: 'Сначала запросите код',
            used: 'Код уже использован — запросите новый'
        };
        return m[code] || 'Не получилось — попробуйте ещё раз';
    }

    function mapAttachErr(code) {
        var m2 = {
            order_not_found: 'Заказ не найден',
            order_phone_conflict: 'Этот заказ уже привязан к другому номеру',
            loyalty_disabled: 'Бонусы сейчас недоступны',
            card_issue_failed: 'Не удалось оформить карту лояльности',
            accrual_failed: 'Не удалось начислить бонусы',
            save_failed: 'Не удалось сохранить'
        };
        return m2[code] || 'Не получилось сохранить бонусы';
    }

    function showE(el, msg) {
        if (!el) return;
        if (!msg) {
            el.classList.add('hidden');
            el.textContent = '';
            return;
        }
        el.textContent = msg;
        el.classList.remove('hidden');
    }

    function openM() {
        backdrop.classList.remove('hidden');
        modal.classList.remove('hidden');
        document.body.style.overflow = 'hidden';
        stepPhone.classList.remove('hidden');
        stepCode.classList.add('hidden');
        showE(errPhone, '');
        showE(errCode, '');
        resetOtpTestUiTrack();
        if (inpPhone) inpPhone.focus();
    }

    function closeM() {
        backdrop.classList.add('hidden');
        modal.classList.add('hidden');
        document.body.style.overflow = '';
    }

    function showBannerOk(balance, points, already, pendingPayment) {
        if (errEl) errEl.classList.add('hidden');
        if (msgEl) {
            var balPart = (balance != null && balance !== '') ? (' Ваш баланс: ' + balance + ' бонусов.') : '';
            if (already) {
                msgEl.textContent = 'Бонусы за этот заказ уже на вашем счёте.' + balPart;
            } else if (pendingPayment) {
                msgEl.textContent = 'Номер сохранён. Бонусы начислим после подтверждённой оплаты.';
            } else if (points != null && points > 0) {
                msgEl.textContent = 'Готово! Бонусы начислены.' + balPart;
            } else {
                msgEl.textContent = 'Готово! Заказ привязан к вашему счёту.' + balPart;
            }
            msgEl.classList.remove('hidden');
        }
        if (banner) {
            var actions = banner.querySelectorAll('button');
            actions.forEach(function (b) { b.classList.add('hidden'); });
        }
    }

    function showBannerErr(t) {
        if (errEl) {
            errEl.textContent = t || 'Ошибка';
            errEl.classList.remove('hidden');
        }
    }

    function attachOrder() {
        return fetch('/guest/attach_order_loyalty.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({ order_id: orderId })
        }).then(function (r) {
            return r.json().then(function (j) { return { ok: r.ok, j: j }; });
        }).then(function (x) {
            if (!x.ok || !x.j.success) {
                var c = x.j && x.j.error ? x.j.error : '';
                throw new Error(mapAttachErr(c));
            }
            return x.j;
        });
    }

    if (openBtn) openBtn.addEventListener('click', openM);
    if (closeBtn) closeBtn.addEventListener('click', closeM);
    backdrop.addEventListener('click', closeM);

    if (btnSend) btnSend.addEventListener('click', function () {
        showE(errPhone, '');
        var phone = inpPhone ? inpPhone.value.trim() : '';
        lastPhone = phone;
        btnSend.disabled = true;
        fetch('/guest/send_otp.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({ phone: phone })
        })
            .then(function (r) { return r.json().then(function (j) { return { r: r, j: j }; }); })
            .then(function (x) {
                if (!x.r.ok || !x.j.success) {
                    showE(errPhone, mapErr(x.j.error) || 'Ошибка');
                    return;
                }
                resetOtpTestUiTrack();
                if (x.j.test_mode === true && x.j.test_code) {
                    lastTestCode = String(x.j.test_code);
                    if (testMsg) testMsg.textContent = 'Для тестирования используйте код: ' + x.j.test_code;
                    if (testBox) testBox.classList.remove('hidden');
                    if (testFill) testFill.classList.remove('hidden');
                }
                stepPhone.classList.add('hidden');
                stepCode.classList.remove('hidden');
                if (inpCode) inpCode.focus();
            })
            .catch(function () {
                showE(errPhone, 'Нет соединения');
            })
            .finally(function () {
                btnSend.disabled = false;
            });
    });

    if (btnVerify) btnVerify.addEventListener('click', function () {
        showE(errCode, '');
        var code = inpCode ? inpCode.value.trim() : '';
        btnVerify.disabled = true;
        fetch('/guest/verify_otp.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({
                phone: lastPhone || (inpPhone ? inpPhone.value.trim() : ''),
                code: code
            })
        })
            .then(function (r) { return r.json().then(function (j) { return { r: r, j: j }; }); })
            .then(function (x) {
                if (!x.r.ok || !x.j.success) {
                    showE(errCode, mapErr(x.j.error) || 'Проверьте код');
                    return;
                }
                resetOtpTestUiTrack();
                return attachOrder()
                    .then(function (aj) {
                        closeM();
                        showBannerOk(aj.balance, aj.points, aj.already, aj.pending_payment);
                    })
                    .catch(function (e) {
                        closeM();
                        showBannerErr(e.message || '');
                    });
            })
            .catch(function () {
                showE(errCode, 'Нет соединения');
            })
            .finally(function () {
                btnVerify.disabled = false;
            });
    });

    if (testFill) testFill.addEventListener('click', function () {
        if (!inpCode || !lastTestCode) return;
        inpCode.value = lastTestCode;
        inpCode.focus();
    });

    if (btnBack) btnBack.addEventListener('click', function () {
        stepCode.classList.add('hidden');
        stepPhone.classList.remove('hidden');
        showE(errCode, '');
        resetOtpTestUiTrack();
    });
})();
</script>
<?php endif; ?>
</body>
</html>
