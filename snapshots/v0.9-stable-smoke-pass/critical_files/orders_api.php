<?php

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/order_payment_runtime.php';

header('Content-Type: application/json; charset=utf-8');

if (!function_exists('require_staff_login') || !function_exists('current_user_restaurant_role')) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Ошибка загрузки авторизации'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!function_exists('auth_user') || !auth_user()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (!isset($currentRestaurant) || (int)($currentRestaurant['id'] ?? 0) <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'restaurant_context_required'], JSON_UNESCAPED_UNICODE);
    exit;
}

global $currentRestaurant;
$restaurantId = (int)($currentRestaurant['id'] ?? 0);
$staffRole = current_user_restaurant_role($restaurantId);
$waiterAllowedRoles = ['owner', 'admin', 'waiter', 'staff'];
if (!in_array((string)$staffRole, $waiterAllowedRoles, true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied'], JSON_UNESCAPED_UNICODE);
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
if (file_exists(__DIR__ . '/../../app/runtime_schema_bootstrap.php')) {
    require_once __DIR__ . '/../../app/runtime_schema_bootstrap.php';
    if (function_exists('runtime_schema_ensure_orders_order_type')) {
        runtime_schema_ensure_orders_order_type($pdo);
    }
    if (function_exists('runtime_schema_ensure_orders_courier_meta')) {
        runtime_schema_ensure_orders_courier_meta($pdo);
    }
    if (function_exists('runtime_schema_ensure_guest_profiles')) {
        runtime_schema_ensure_guest_profiles($pdo);
    }
}

order_expire_due_orders($pdo, $restaurantId, null, $tableId > 0 ? $tableId : null);

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
$params = ['rest' => $restaurantId];
if ($tableId > 0) {
    $params['table'] = $tableId;
}
$stmt->execute($params);
$orders = $stmt->fetchAll();

$result = [];

if ($orders) {
    $orderIds = array_values(array_filter(array_map('intval', array_column($orders, 'id'))));
    $hasGuestIdCol = function_exists('db_column_exists') && db_column_exists('orders', 'guest_id');
    $hasGuestCardIdCol = function_exists('db_column_exists') && db_column_exists('orders', 'guest_card_id');
    $hasLoyaltyPhoneCol = function_exists('db_column_exists') && db_column_exists('orders', 'loyalty_phone');
    $hasOrderTypeCol = function_exists('db_column_exists') && db_column_exists('orders', 'order_type');
    $hasCustomerNameCol = function_exists('db_column_exists') && db_column_exists('orders', 'customer_name');
    $hasCustomerPhoneCol = function_exists('db_column_exists') && db_column_exists('orders', 'customer_phone');
    $hasDeliveryFullNameCol = function_exists('db_column_exists') && db_column_exists('orders', 'delivery_full_name');
    $hasDeliveryPhoneCol = function_exists('db_column_exists') && db_column_exists('orders', 'delivery_phone');
    $hasDeliveryAddressCol = function_exists('db_column_exists') && db_column_exists('orders', 'delivery_address');
    $hasScheduledForCol = function_exists('db_column_exists') && db_column_exists('orders', 'scheduled_for');
    $hasPreorderReceiveTypeCol = function_exists('db_column_exists') && db_column_exists('orders', 'preorder_receive_type');
    $hasCourierStatusCol = function_exists('db_column_exists') && db_column_exists('orders', 'courier_status');
    $hasCourierUserIdCol = function_exists('db_column_exists') && db_column_exists('orders', 'courier_user_id');
    $hasGuestProfilesTable = function_exists('db_table_exists') && db_table_exists('guest_profiles');
    $orderCommentCol = null;
    foreach (['comment', 'notes', 'note'] as $candidateCol) {
        if (function_exists('db_column_exists') && db_column_exists('orders', $candidateCol)) {
            $orderCommentCol = $candidateCol;
            break;
        }
    }
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
        $stmtCalls->execute([':rid' => $restaurantId]);
        foreach ($stmtCalls->fetchAll(PDO::FETCH_ASSOC) as $cr) {
            $oid = (int)($cr['order_id'] ?? 0);
            if ($oid > 0) {
                $waiterCallByOrder[$oid] = true;
            }
        }
    }

    $guestIds = [];
    foreach ($orders as $o) {
        $gid = (int)($o['guest_id'] ?? 0);
        if ($gid > 0) {
            $guestIds[] = $gid;
        }
    }
    $guestIds = array_values(array_unique($guestIds));
    $guestById = [];
    if ($guestIds !== [] && function_exists('db_table_exists') && db_table_exists('guests')) {
        $phGuest = implode(',', array_fill(0, count($guestIds), '?'));
        $stmtGuests = $pdo->prepare("
            SELECT id, name, phone
            FROM guests
            WHERE id IN ($phGuest)
        ");
        $stmtGuests->execute($guestIds);
        foreach ($stmtGuests->fetchAll(PDO::FETCH_ASSOC) as $grow) {
            $guestById[(int)($grow['id'] ?? 0)] = [
                'name' => (string)($grow['name'] ?? ''),
                'phone' => (string)($grow['phone'] ?? ''),
            ];
        }
    }

    $balanceByGuest = [];
    if ($guestIds !== [] && function_exists('db_table_exists') && db_table_exists('guest_loyalty_accounts')) {
        $phGuest = implode(',', array_fill(0, count($guestIds), '?'));
        $stmtBalances = $pdo->prepare("
            SELECT guest_id, balance
            FROM guest_loyalty_accounts
            WHERE restaurant_id = ?
              AND guest_id IN ($phGuest)
        ");
        $stmtBalances->execute(array_merge([$restaurantId], $guestIds));
        foreach ($stmtBalances->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $balanceByGuest[(int)($row['guest_id'] ?? 0)] = (int)($row['balance'] ?? 0);
        }
    }

    $cardGuestIds = [];
    if ($guestIds !== [] && function_exists('db_table_exists') && db_table_exists('guest_cards')) {
        $phGuest = implode(',', array_fill(0, count($guestIds), '?'));
        $stmtCards = $pdo->prepare("
            SELECT DISTINCT guest_id
            FROM guest_cards
            WHERE restaurant_id = ?
              AND guest_id IN ($phGuest)
        ");
        $stmtCards->execute(array_merge([$restaurantId], $guestIds));
        foreach ($stmtCards->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $cardGuestIds[(int)($row['guest_id'] ?? 0)] = true;
        }
    }

    $manualTxByOrder = [];
    if ($orderIds !== [] && function_exists('db_table_exists') && db_table_exists('guest_loyalty_tx')) {
        $manualStaffCond = '';
        if (function_exists('db_column_exists') && db_column_exists('guest_loyalty_tx', 'staff_user_id')) {
            $manualStaffCond = " AND staff_user_id IS NOT NULL ";
        }
        $stmtManualTx = $pdo->prepare("
            SELECT
                order_id,
                COUNT(*) AS tx_count,
                SUM(CASE WHEN type = 'accrual' THEN 1 ELSE 0 END) AS accrual_count,
                SUM(CASE WHEN type = 'spend' THEN 1 ELSE 0 END) AS spend_count
            FROM guest_loyalty_tx
            WHERE restaurant_id = ?
              {$manualStaffCond}
              AND order_id IN ($placeholders)
            GROUP BY order_id
        ");
        $stmtManualTx->execute(array_merge([$restaurantId], $orderIds));
        foreach ($stmtManualTx->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $oid = (int)($row['order_id'] ?? 0);
            $manualTxByOrder[$oid] = [
                'count' => (int)($row['tx_count'] ?? 0),
                'accrual_count' => (int)($row['accrual_count'] ?? 0),
                'spend_count' => (int)($row['spend_count'] ?? 0),
            ];
        }
    }

    $courierUserIds = [];
    if ($hasCourierUserIdCol) {
        foreach ($orders as $o) {
            $cid = (int)($o['courier_user_id'] ?? 0);
            if ($cid > 0) {
                $courierUserIds[] = $cid;
            }
        }
    }
    $courierUserIds = array_values(array_unique($courierUserIds));
    $courierUserById = [];
    if ($courierUserIds !== []) {
        $phCourier = implode(',', array_fill(0, count($courierUserIds), '?'));
        $stmtCourierUsers = $pdo->prepare("
            SELECT id, name
            FROM users
            WHERE id IN ($phCourier)
        ");
        $stmtCourierUsers->execute($courierUserIds);
        foreach ($stmtCourierUsers->fetchAll(PDO::FETCH_ASSOC) as $urow) {
            $uid = (int)($urow['id'] ?? 0);
            if ($uid > 0) {
                $courierUserById[$uid] = trim((string)($urow['name'] ?? ''));
            }
        }
    }

    $guestProfileByPhone = [];
    if ($hasGuestProfilesTable && function_exists('guest_normalize_phone')) {
        $profilePhoneSet = [];
        foreach ($orders as $o) {
            $guestId = $hasGuestIdCol ? (int)($o['guest_id'] ?? 0) : 0;
            $guestPhoneFromGuest = $guestId > 0 ? (string)($guestById[$guestId]['phone'] ?? '') : '';
            $loyaltyPhone = $hasLoyaltyPhoneCol ? (string)($o['loyalty_phone'] ?? '') : '';
            $customerPhone = $hasCustomerPhoneCol ? trim((string)($o['customer_phone'] ?? '')) : '';
            $deliveryPhone = $hasDeliveryPhoneCol ? trim((string)($o['delivery_phone'] ?? '')) : '';
            foreach ([$customerPhone, $deliveryPhone, $guestPhoneFromGuest, $loyaltyPhone] as $phoneRaw) {
                $phoneNorm = guest_normalize_phone((string)$phoneRaw);
                if ($phoneNorm !== null) {
                    $profilePhoneSet[$phoneNorm] = true;
                }
            }
        }
        $profilePhones = array_keys($profilePhoneSet);
        if ($profilePhones !== []) {
            $profilePh = implode(',', array_fill(0, count($profilePhones), '?'));
            $stmtProfiles = $pdo->prepare("
                SELECT phone_normalized, orders_count, total_spent
                FROM guest_profiles
                WHERE restaurant_id = ?
                  AND phone_normalized IN ($profilePh)
            ");
            $stmtProfiles->execute(array_merge([$restaurantId], $profilePhones));
            foreach ($stmtProfiles->fetchAll(PDO::FETCH_ASSOC) as $prow) {
                $pn = trim((string)($prow['phone_normalized'] ?? ''));
                if ($pn === '') {
                    continue;
                }
                $guestProfileByPhone[$pn] = [
                    'orders_count' => (int)($prow['orders_count'] ?? 0),
                    'total_spent' => (float)($prow['total_spent'] ?? 0),
                ];
            }
        }
    }

    $now = time();
    $receiveTypeLabels = [
        'pickup' => 'Самовывоз',
        'delivery' => 'Доставка',
    ];
    foreach ($orders as $o) {
        $createdTs = strtotime($o['created_at'] ?? '');
        $sinceMinutes = $createdTs ? max(0, (int)floor(($now - $createdTs) / 60)) : 0;
        $createdAtShort = !empty($o['created_at']) ? date('H:i', strtotime((string)$o['created_at'])) : '';
        $orderId = (int)($o['id'] ?? 0);
        $guestId = $hasGuestIdCol ? (int)($o['guest_id'] ?? 0) : 0;
        $guestCardId = $hasGuestCardIdCol ? (int)($o['guest_card_id'] ?? 0) : 0;
        $loyaltyPhone = $hasLoyaltyPhoneCol ? (string)($o['loyalty_phone'] ?? '') : '';
        $guestName = (string)($guestById[$guestId]['name'] ?? '');
        $guestPhone = (string)($guestById[$guestId]['phone'] ?? '');
        if ($guestPhone === '' && $loyaltyPhone !== '') {
            $guestPhone = $loyaltyPhone;
        }
        $customerName = $hasCustomerNameCol ? trim((string)($o['customer_name'] ?? '')) : '';
        $customerPhone = $hasCustomerPhoneCol ? trim((string)($o['customer_phone'] ?? '')) : '';
        $deliveryFullName = $hasDeliveryFullNameCol ? trim((string)($o['delivery_full_name'] ?? '')) : '';
        $deliveryPhone = $hasDeliveryPhoneCol ? trim((string)($o['delivery_phone'] ?? '')) : '';
        $deliveryAddress = $hasDeliveryAddressCol ? trim((string)($o['delivery_address'] ?? '')) : '';
        $scheduledFor = $hasScheduledForCol ? trim((string)($o['scheduled_for'] ?? '')) : '';
        $preorderReceiveType = $hasPreorderReceiveTypeCol ? strtolower(trim((string)($o['preorder_receive_type'] ?? ''))) : '';
        if (!in_array($preorderReceiveType, ['pickup', 'delivery'], true)) {
            $preorderReceiveType = '';
        }
        $customerNameDisplay = $customerName !== '' ? $customerName : ($deliveryFullName !== '' ? $deliveryFullName : $guestName);
        $customerPhoneDisplay = $customerPhone !== '' ? $customerPhone : ($deliveryPhone !== '' ? $deliveryPhone : $guestPhone);
        $guestPhoneNormalized = function_exists('guest_normalize_phone')
            ? guest_normalize_phone($customerPhoneDisplay)
            : null;
        $guestProfile = ($guestPhoneNormalized !== null && isset($guestProfileByPhone[$guestPhoneNormalized]))
            ? $guestProfileByPhone[$guestPhoneNormalized]
            : null;
        $orderTypeRaw = $hasOrderTypeCol ? (string)($o['order_type'] ?? '') : '';
        $orderType = function_exists('order_type_normalize')
            ? order_type_normalize($orderTypeRaw, (int)($o['table_id'] ?? 0))
            : 'hall';
        $orderTypeLabel = function_exists('order_type_label')
            ? order_type_label($orderTypeRaw, (int)($o['table_id'] ?? 0))
            : 'Зал';
        $tableNameLabel = function_exists('qr_public_owner_order_table_label')
            ? qr_public_owner_order_table_label((string)($o['table_name'] ?? ''))
            : (string)($o['table_name'] ?? '');
        $sourceLabel = function_exists('order_source_label')
            ? order_source_label($orderTypeRaw, (int)($o['table_id'] ?? 0), $tableNameLabel)
            : (($orderType === 'hall') ? 'QR / Зал' : $orderTypeLabel);
        $hasCard = $guestCardId > 0 || !empty($cardGuestIds[$guestId]);
        $balance = $guestId > 0 ? (int)($balanceByGuest[$guestId] ?? 0) : 0;
        $manualTx = $manualTxByOrder[$orderId] ?? ['count' => 0, 'accrual_count' => 0, 'spend_count' => 0];
        $orderComment = $orderCommentCol !== null ? trim((string)($o[$orderCommentCol] ?? '')) : '';
        $scheduledForDisplay = '';
        if ($scheduledFor !== '') {
            $scheduledTs = strtotime($scheduledFor);
            if ($scheduledTs !== false && $scheduledTs > 0) {
                $scheduledForDisplay = date('d.m H:i', $scheduledTs);
            } else {
                $scheduledForDisplay = $scheduledFor;
            }
        }
        $fulfillmentSummaryParts = [];
        if ($orderType === 'delivery') {
            if ($customerNameDisplay !== '') $fulfillmentSummaryParts[] = $customerNameDisplay;
            if ($customerPhoneDisplay !== '') $fulfillmentSummaryParts[] = $customerPhoneDisplay;
            if ($deliveryAddress !== '') $fulfillmentSummaryParts[] = 'Адрес: ' . $deliveryAddress;
        } elseif ($orderType === 'pickup') {
            if ($customerNameDisplay !== '') $fulfillmentSummaryParts[] = $customerNameDisplay;
            if ($customerPhoneDisplay !== '') $fulfillmentSummaryParts[] = $customerPhoneDisplay;
            $fulfillmentSummaryParts[] = 'Самовывоз';
        } elseif ($orderType === 'preorder') {
            if ($scheduledForDisplay !== '') $fulfillmentSummaryParts[] = 'На ' . $scheduledForDisplay;
            if ($preorderReceiveType !== '') $fulfillmentSummaryParts[] = $receiveTypeLabels[$preorderReceiveType] ?? $preorderReceiveType;
            if ($customerNameDisplay !== '') $fulfillmentSummaryParts[] = $customerNameDisplay;
            if ($customerPhoneDisplay !== '') $fulfillmentSummaryParts[] = $customerPhoneDisplay;
            if ($preorderReceiveType === 'delivery' && $deliveryAddress !== '') {
                $fulfillmentSummaryParts[] = 'Адрес: ' . $deliveryAddress;
            }
        } elseif ($orderType === 'manual') {
            if ($customerNameDisplay !== '') $fulfillmentSummaryParts[] = $customerNameDisplay;
            if ($customerPhoneDisplay !== '') $fulfillmentSummaryParts[] = $customerPhoneDisplay;
        }
        $fulfillmentSummary = implode(' · ', $fulfillmentSummaryParts);
        $courierUserId = $hasCourierUserIdCol ? (int)($o['courier_user_id'] ?? 0) : 0;
        $courierStatusRaw = $hasCourierStatusCol ? trim((string)($o['courier_status'] ?? '')) : '';
        $courierStatus = function_exists('courier_status_normalize')
            ? courier_status_normalize($courierStatusRaw, $orderType)
            : (($orderType === 'delivery') ? ($courierStatusRaw !== '' ? strtolower($courierStatusRaw) : 'waiting_courier') : '');
        if ($orderType !== 'delivery') {
            $courierStatus = '';
        }
        $courierStatusLabel = function_exists('courier_status_label')
            ? courier_status_label($courierStatus, $orderType)
            : ($courierStatus === '' ? '' : $courierStatus);
        $courierUserName = $courierUserId > 0 ? (string)($courierUserById[$courierUserId] ?? '') : '';

        $countdownMeta = order_payment_timer_meta($o, $now);
        $items = $itemsByOrder[$o['id']] ?? [];
        $result[] = [
            'id'              => $orderId,
            'order_number'    => '#' . $orderId,
            'table_name'      => $tableNameLabel,
            'table_id'        => (int)($o['table_id'] ?? 0),
            'total_price'     => (float)$o['total_price'],
            'loyalty_points_spent' => (int)($o['loyalty_points_spent'] ?? 0),
            'payment_type'    => (string)($o['payment_type'] ?? 'cash'),
            'payment_status'  => (string)($o['payment_status'] ?? ''),
            'order_status'    => (string)($o['order_status'] ?? ''),
            'order_type'      => $orderType,
            'order_type_label'=> $orderTypeLabel,
            'source_label'    => $sourceLabel,
            'comment'         => $orderComment,
            'created_at'      => (string)($o['created_at'] ?? ''),
            'created_at_short'=> $createdAtShort,
            'since_minutes'   => $sinceMinutes,
            'countdown_seconds_remaining' => $countdownMeta['seconds_remaining'],
            'countdown_expired' => $countdownMeta['expired'],
            'countdown_warning' => $countdownMeta['warning'],
            'countdown_mmss' => $countdownMeta['mmss'],
            'ready_items_count' => (int)($progressByOrder[(int)$o['id']]['ready_items_count'] ?? 0),
            'total_items_count' => (int)($progressByOrder[(int)$o['id']]['total_items_count'] ?? 0),
            'progress_percent' => (int)($progressByOrder[(int)$o['id']]['progress_percent'] ?? 0),
            'partial_ready' => (bool)($progressByOrder[(int)$o['id']]['partial_ready'] ?? false),
            'has_waiter_call' => !empty($waiterCallByOrder[(int)$o['id']]),
            'loyalty_guest_id' => $guestId,
            'loyalty_guest_card_id' => $guestCardId,
            'loyalty_phone' => $loyaltyPhone,
            'guest_name' => $guestName,
            'guest_phone' => $guestPhone,
            'loyalty_has_card' => $hasCard,
            'loyalty_balance' => $balance,
            'manual_loyalty_tx_count' => (int)$manualTx['count'],
            'manual_loyalty_accrual_count' => (int)$manualTx['accrual_count'],
            'manual_loyalty_spend_count' => (int)$manualTx['spend_count'],
            'customer_name_display' => $customerNameDisplay,
            'customer_phone_display' => $customerPhoneDisplay,
            'guest_phone_normalized' => $guestPhoneNormalized,
            'guest_orders_count' => (int)($guestProfile['orders_count'] ?? 0),
            'guest_total_spent' => (float)($guestProfile['total_spent'] ?? 0),
            'known_guest' => $guestProfile !== null,
            'delivery_address' => $deliveryAddress,
            'scheduled_for' => $scheduledFor,
            'scheduled_for_display' => $scheduledForDisplay,
            'preorder_receive_type' => $preorderReceiveType,
            'preorder_receive_type_label' => $preorderReceiveType !== '' ? ($receiveTypeLabels[$preorderReceiveType] ?? '') : '',
            'fulfillment_summary' => $fulfillmentSummary,
            'courier_status' => $courierStatus,
            'courier_status_label' => $courierStatusLabel,
            'courier_user_id' => $courierUserId,
            'courier_user_name' => $courierUserName,
            'items_count'     => count($items),
            'items'           => $items,
        ];
    }
}

echo json_encode([
    'success' => true,
    'orders'  => $result,
], JSON_UNESCAPED_UNICODE);
