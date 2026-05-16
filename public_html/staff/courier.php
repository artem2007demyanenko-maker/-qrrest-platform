<?php

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/runtime_schema_bootstrap.php';
require_once __DIR__ . '/../../app/guest_order_loyalty_attach.php';

$staffRole = function_exists('require_courier_access')
    ? require_courier_access()
    : require_staff_role(['owner', 'admin', 'staff', 'courier']);
$currentUser = auth_user();
$currentUserId = (int)($currentUser['id'] ?? 0);

$pdo = db();
if (function_exists('runtime_schema_ensure_orders_order_type')) {
    runtime_schema_ensure_orders_order_type($pdo);
}
if (function_exists('runtime_schema_ensure_orders_courier_meta')) {
    runtime_schema_ensure_orders_courier_meta($pdo);
}
if (function_exists('runtime_schema_ensure_courier_locations')) {
    runtime_schema_ensure_courier_locations($pdo);
}
if (function_exists('runtime_schema_ensure_courier_shifts')) {
    runtime_schema_ensure_courier_shifts($pdo);
}
if (function_exists('runtime_schema_ensure_courier_earnings')) {
    runtime_schema_ensure_courier_earnings($pdo);
}
if (function_exists('runtime_schema_ensure_delivery_zones')) {
    runtime_schema_ensure_delivery_zones($pdo);
}
if (function_exists('runtime_schema_ensure_order_tips')) {
    runtime_schema_ensure_order_tips($pdo);
}

$hasOrderTypeCol = function_exists('db_column_exists') && db_column_exists('orders', 'order_type');
$hasCourierStatusCol = function_exists('db_column_exists') && db_column_exists('orders', 'courier_status');
$hasCourierUserIdCol = function_exists('db_column_exists') && db_column_exists('orders', 'courier_user_id');
$hasCourierTakenAtCol = function_exists('db_column_exists') && db_column_exists('orders', 'courier_taken_at');
$hasCourierOnTheWayAtCol = function_exists('db_column_exists') && db_column_exists('orders', 'courier_on_the_way_at');
$hasDeliveredAtCol = function_exists('db_column_exists') && db_column_exists('orders', 'delivered_at');
if (function_exists('qa_runtime_warn_once') && (!$hasOrderTypeCol || !$hasCourierStatusCol)) {
    qa_runtime_warn_once(
        'courier_core_schema_degraded',
        'Courier screen running in degraded schema mode',
        [
            'restaurant_id' => (int)($currentRestaurant['id'] ?? 0),
            'has_order_type' => $hasOrderTypeCol,
            'has_courier_status' => $hasCourierStatusCol,
        ]
    );
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

$errors = [];
$success = null;
$canManageDelivery = in_array($staffRole, ['owner', 'admin', 'staff'], true);
$isCourierOnly = ($staffRole === 'courier');

function courier_orders_sync_loyalty_safe(PDO $pdo, array $restaurantRow, int $orderId): void
{
    if (!function_exists('guest_order_loyalty_sync')) {
        return;
    }

    try {
        $res = guest_order_loyalty_sync($pdo, $restaurantRow, $orderId, null);
        if (!is_array($res) || empty($res['ok'])) {
            error_log('staff/courier loyalty_sync order_id=' . $orderId . ' error=' . (string)($res['error'] ?? 'unknown'));
        }
    } catch (Throwable $e) {
        error_log('staff/courier loyalty_sync order_id=' . $orderId . ' ' . $e->getMessage());
    }
}

function courier_can_transition(string $from, string $to): bool
{
    if ($from === $to) {
        return true;
    }
    $allowed = [
        'waiting_courier' => ['handed_to_courier', 'on_the_way', 'delivered'],
        'handed_to_courier' => ['on_the_way', 'delivered'],
        'on_the_way' => ['delivered'],
        'delivered' => [],
    ];
    return in_array($to, $allowed[$from] ?? [], true);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfOk = isset($_POST['csrf'], $_SESSION['csrf']) && hash_equals((string)$_SESSION['csrf'], (string)$_POST['csrf']);
    if (!$csrfOk) {
        $errors[] = 'Неверный запрос. Обновите страницу и попробуйте снова.';
    } else {
        $shiftAction = strtolower(trim((string)($_POST['shift_action'] ?? '')));
        if ($shiftAction !== '') {
            try {
                if (!in_array($shiftAction, ['start', 'pause', 'resume', 'end'], true)) {
                    throw new RuntimeException('unknown_shift_action');
                }
                if (!function_exists('courier_shift_start') || !function_exists('courier_shift_pause') || !function_exists('courier_shift_resume') || !function_exists('courier_shift_end')) {
                    throw new RuntimeException('shift_helpers_missing');
                }
                if ($shiftAction === 'start') {
                    $res = courier_shift_start($pdo, (int)$currentRestaurant['id'], $currentUserId);
                } elseif ($shiftAction === 'pause') {
                    $res = courier_shift_pause($pdo, (int)$currentRestaurant['id'], $currentUserId);
                } elseif ($shiftAction === 'resume') {
                    $res = courier_shift_resume($pdo, (int)$currentRestaurant['id'], $currentUserId);
                } else {
                    $res = courier_shift_end($pdo, (int)$currentRestaurant['id'], $currentUserId);
                }
                if (empty($res['ok'])) {
                    throw new RuntimeException((string)($res['error'] ?? 'shift_action_failed'));
                }
                $success = match ($shiftAction) {
                    'start' => 'Смена открыта.',
                    'pause' => 'Смена поставлена на паузу.',
                    'resume' => 'Смена возобновлена.',
                    'end' => 'Смена закрыта.',
                    default => 'Состояние смены обновлено.',
                };
            } catch (Throwable $e) {
                $shiftErr = $e->getMessage();
                if ($shiftErr === 'no_active_shift') {
                    $errors[] = 'Активная смена не найдена.';
                } elseif ($shiftErr === 'unknown_shift_action') {
                    $errors[] = 'Неизвестное действие со сменой.';
                } elseif ($shiftErr === 'shift_helpers_missing' || $shiftErr === 'schema_missing') {
                    $errors[] = 'Shift foundation ещё не готов.';
                } else {
                    $errors[] = 'Не удалось обновить состояние смены.';
                }
                error_log('STAFF_COURIER_SHIFT_ACTION_FAIL user_id=' . $currentUserId . ' action=' . $shiftAction . ' ' . $shiftErr);
            }
        }

        $dispatchAction = strtolower(trim((string)($_POST['dispatch_action'] ?? '')));
        if ($dispatchAction !== '') {
            $dispatchOrderId = (int)($_POST['order_id'] ?? 0);
            if ($dispatchOrderId <= 0) {
                $errors[] = 'Не выбран заказ для dispatch-действия.';
            } else {
                try {
                    if ($dispatchAction === 'assign') {
                        $targetCourierId = (int)($_POST['courier_user_id'] ?? 0);
                        if ($targetCourierId <= 0) {
                            throw new RuntimeException('courier_required');
                        }
                        $res = function_exists('delivery_dispatch_assign')
                            ? delivery_dispatch_assign(
                                $pdo,
                                (int)$currentRestaurant['id'],
                                $dispatchOrderId,
                                $targetCourierId,
                                $currentUserId,
                                ['allow_override' => $canManageDelivery]
                            )
                            : ['ok' => false, 'error' => 'dispatch_helpers_missing'];
                        if (empty($res['ok'])) {
                            throw new RuntimeException((string)($res['error'] ?? 'dispatch_assign_failed'));
                        }
                        $success = 'Dispatch: заказ назначен курьеру.';
                    } elseif ($dispatchAction === 'unassign') {
                        if (!$canManageDelivery) {
                            throw new RuntimeException('forbidden_action');
                        }
                        $res = function_exists('delivery_dispatch_unassign')
                            ? delivery_dispatch_unassign(
                                $pdo,
                                (int)$currentRestaurant['id'],
                                $dispatchOrderId,
                                $currentUserId
                            )
                            : ['ok' => false, 'error' => 'dispatch_helpers_missing'];
                        if (empty($res['ok'])) {
                            throw new RuntimeException((string)($res['error'] ?? 'dispatch_unassign_failed'));
                        }
                        $success = 'Dispatch: заказ возвращён в очередь.';
                    } else {
                        throw new RuntimeException('unknown_dispatch_action');
                    }
                } catch (Throwable $e) {
                    $messageKey = $e->getMessage();
                    if ($messageKey === 'courier_required') {
                        $errors[] = 'Выберите курьера для назначения.';
                    } elseif ($messageKey === 'courier_unavailable_shift') {
                        $errors[] = 'Курьер недоступен: оффлайн/пауза/перегрузка смены.';
                    } elseif (in_array($messageKey, ['forbidden_action', 'assigned_to_other'], true)) {
                        $errors[] = 'Недостаточно прав для назначения/переназначения.';
                    } elseif (in_array($messageKey, ['order_not_found', 'not_delivery', 'order_closed'], true)) {
                        $errors[] = 'Dispatch: заказ недоступен для назначения.';
                    } else {
                        $errors[] = 'Dispatch-действие не выполнено.';
                    }
                    error_log('STAFF_DISPATCH_ACTION_FAIL order_id=' . $dispatchOrderId . ' action=' . $dispatchAction . ' ' . $messageKey);
                }
            }
        }

        $orderId = (int)($_POST['order_id'] ?? 0);
        $action = strtolower(trim((string)($_POST['courier_action'] ?? '')));
        $targetByAction = [
            'take' => 'waiting_courier',
            'handed' => 'handed_to_courier',
            'on_the_way' => 'on_the_way',
            'delivered' => 'delivered',
            'reset_waiting' => 'waiting_courier',
        ];
        $targetStatus = $targetByAction[$action] ?? '';

        if ($action !== '') {
            if ($orderId <= 0 || $targetStatus === '') {
                $errors[] = 'Некорректные параметры действия.';
            }
            if (!$hasOrderTypeCol || !$hasCourierStatusCol) {
                $errors[] = 'Courier schema не готова для обновления статусов.';
            }
        }

        if ($action !== '' && !$errors) {
            $startedTx = false;
            try {
                if (!$pdo->inTransaction()) {
                    $pdo->beginTransaction();
                    $startedTx = true;
                }

                $stmtOrder = $pdo->prepare("
                    SELECT id, restaurant_id, table_id, order_type, order_status, courier_status, courier_user_id
                    FROM orders
                    WHERE id = :id AND restaurant_id = :rest
                    LIMIT 1
                    FOR UPDATE
                ");
                $stmtOrder->execute([
                    ':id' => $orderId,
                    ':rest' => (int)$currentRestaurant['id'],
                ]);
                $orderRow = $stmtOrder->fetch(PDO::FETCH_ASSOC) ?: null;
                if (!$orderRow) {
                    throw new RuntimeException('order_not_found');
                }

                $orderType = function_exists('order_type_normalize')
                    ? order_type_normalize((string)($orderRow['order_type'] ?? ''), (int)($orderRow['table_id'] ?? 0))
                    : 'hall';
                if ($orderType !== 'delivery') {
                    throw new RuntimeException('not_delivery_order');
                }

                $currentCourierId = (int)($orderRow['courier_user_id'] ?? 0);
                $currentCourierStatus = function_exists('courier_status_normalize')
                    ? courier_status_normalize((string)($orderRow['courier_status'] ?? ''), $orderType)
                    : 'waiting_courier';
                $currentOrderStatus = strtolower(trim((string)($orderRow['order_status'] ?? 'new')));

                if ($isCourierOnly && $currentCourierId > 0 && $currentCourierId !== $currentUserId) {
                    throw new RuntimeException('courier_assigned_to_other');
                }
                if ($isCourierOnly && $action === 'reset_waiting') {
                    throw new RuntimeException('forbidden_action');
                }
                if (!$isCourierOnly && !$canManageDelivery && $action === 'reset_waiting') {
                    throw new RuntimeException('forbidden_action');
                }
                if ($action === 'take' && $currentCourierStatus === 'delivered') {
                    throw new RuntimeException('invalid_transition');
                }

                $nextCourierId = $currentCourierId;
                if ($action === 'take') {
                    $nextCourierId = $currentUserId > 0 ? $currentUserId : $currentCourierId;
                } elseif (in_array($targetStatus, ['handed_to_courier', 'on_the_way', 'delivered'], true) && $nextCourierId <= 0 && $currentUserId > 0) {
                    $nextCourierId = $currentUserId;
                } elseif ($action === 'reset_waiting' && $canManageDelivery) {
                    $nextCourierId = 0;
                }

                if ($action !== 'take' && !courier_can_transition($currentCourierStatus, $targetStatus)) {
                    throw new RuntimeException('invalid_transition');
                }

                $setParts = ['courier_status = :courier_status'];
                $params = [
                    ':courier_status' => $targetStatus,
                    ':id' => $orderId,
                    ':rest' => (int)$currentRestaurant['id'],
                ];

                if ($hasCourierUserIdCol) {
                    $setParts[] = 'courier_user_id = :courier_user_id';
                    $params[':courier_user_id'] = $nextCourierId > 0 ? $nextCourierId : null;
                }

                if ($action === 'take' && $hasCourierTakenAtCol) {
                    $setParts[] = 'courier_taken_at = NOW()';
                }
                if ($action === 'on_the_way' && $hasCourierOnTheWayAtCol) {
                    $setParts[] = 'courier_on_the_way_at = NOW()';
                }
                if ($action === 'delivered' && $hasDeliveredAtCol) {
                    $setParts[] = 'delivered_at = COALESCE(delivered_at, NOW())';
                }
                if ($action === 'reset_waiting') {
                    if ($hasCourierTakenAtCol) {
                        $setParts[] = 'courier_taken_at = NULL';
                    }
                    if ($hasCourierOnTheWayAtCol) {
                        $setParts[] = 'courier_on_the_way_at = NULL';
                    }
                }

                if (
                    $targetStatus === 'delivered'
                    && !in_array($currentOrderStatus, ['delivered', 'completed', 'canceled', 'cancelled'], true)
                ) {
                    $setParts[] = "order_status = 'delivered'";
                }

                $upd = $pdo->prepare("
                    UPDATE orders
                    SET " . implode(', ', $setParts) . "
                    WHERE id = :id AND restaurant_id = :rest
                ");
                $upd->execute($params);

                courier_orders_sync_loyalty_safe($pdo, $currentRestaurant, $orderId);
                if ($targetStatus === 'delivered' && function_exists('courier_earnings_order')) {
                    try {
                        $earningRes = courier_earnings_order($pdo, (int)$currentRestaurant['id'], $orderId, [
                            'earning_type' => 'delivery',
                            'base_rate' => 0.06,
                            'base_floor' => 90.0,
                            'overload_threshold' => 4,
                        ]);
                        if (empty($earningRes['ok'])) {
                            error_log('STAFF_COURIER_EARNINGS_SYNC_FAIL order_id=' . $orderId . ' ' . (string)($earningRes['error'] ?? 'unknown'));
                        }
                    } catch (Throwable $e) {
                        error_log('STAFF_COURIER_EARNINGS_SYNC_FAIL order_id=' . $orderId . ' ' . $e->getMessage());
                    }
                }

                if ($startedTx && $pdo->inTransaction()) {
                    $pdo->commit();
                }

                $success = 'Статус курьера обновлён.';
            } catch (Throwable $e) {
                if ($startedTx && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if ($e->getMessage() === 'order_not_found') {
                    $errors[] = 'Заказ не найден.';
                } elseif ($e->getMessage() === 'not_delivery_order') {
                    $errors[] = 'Это не заказ доставки.';
                } elseif ($e->getMessage() === 'courier_assigned_to_other') {
                    $errors[] = 'Заказ уже назначен другому курьеру.';
                } elseif ($e->getMessage() === 'invalid_transition') {
                    $errors[] = 'Недопустимый переход статуса курьера.';
                } elseif ($e->getMessage() === 'forbidden_action') {
                    $errors[] = 'Недостаточно прав для этого действия.';
                } else {
                    $errors[] = 'Не удалось обновить courier-статус.';
                }
                error_log('STAFF_COURIER_UPDATE_FAIL order_id=' . $orderId . ' action=' . $action . ' ' . $e->getMessage());
            }
        }
    }
}

$scope = strtolower(trim((string)($_GET['scope'] ?? ($isCourierOnly ? 'mine' : 'active'))));
$allowedScopes = ['active', 'all', 'mine', 'done'];
if (!in_array($scope, $allowedScopes, true)) {
    $scope = $isCourierOnly ? 'mine' : 'active';
}
$focusOrderId = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;

$courierStatusSql = $hasCourierStatusCol
    ? "LOWER(COALESCE(o.courier_status, 'waiting_courier'))"
    : "'waiting_courier'";
$courierUserIdSql = $hasCourierUserIdCol
    ? "o.courier_user_id"
    : "NULL";
$courierUserJoinSql = $hasCourierUserIdCol
    ? "LEFT JOIN users u ON u.id = o.courier_user_id"
    : "";
$courierUserNameSql = $hasCourierUserIdCol
    ? "u.name AS courier_user_name"
    : "NULL AS courier_user_name";

$orderPaymentTypeExpr = (function_exists('db_column_exists') && db_column_exists('orders', 'payment_type'))
    ? 'o.payment_type'
    : "'cash'";
$orderCommentCol = null;
foreach (['comment', 'notes', 'note'] as $candidateCol) {
    if (function_exists('db_column_exists') && db_column_exists('orders', $candidateCol)) {
        $orderCommentCol = $candidateCol;
        break;
    }
}

$where = [
    'o.restaurant_id = :rest',
    "LOWER(COALESCE(o.order_status, 'new')) NOT IN ('canceled', 'cancelled')",
];
$where[] = $hasOrderTypeCol
    ? "LOWER(TRIM(COALESCE(o.order_type, ''))) = 'delivery'"
    : "1 = 0";
$params = [':rest' => (int)$currentRestaurant['id']];

if ($scope === 'active') {
    $where[] = "{$courierStatusSql} <> 'delivered'";
} elseif ($scope === 'done') {
    $where[] = "{$courierStatusSql} = 'delivered'";
}
if ($scope === 'mine') {
    if ($isCourierOnly) {
        $where[] = "({$courierUserIdSql} = :uid OR ({$courierUserIdSql} IS NULL AND {$courierStatusSql} = 'waiting_courier'))";
    } else {
        $where[] = "{$courierUserIdSql} = :uid";
    }
    $params[':uid'] = $currentUserId;
} elseif ($isCourierOnly) {
    $where[] = "({$courierUserIdSql} = :uid OR ({$courierUserIdSql} IS NULL AND {$courierStatusSql} = 'waiting_courier'))";
    $params[':uid'] = $currentUserId;
}
if ($focusOrderId > 0) {
    $where[] = 'o.id = :focus_order';
    $params[':focus_order'] = $focusOrderId;
}

$sql = "
    SELECT
        o.*,
        t.name AS table_name,
        {$orderPaymentTypeExpr} AS payment_type_safe,
        {$courierUserNameSql}
    FROM orders o
    LEFT JOIN tables t ON t.id = o.table_id AND t.restaurant_id = o.restaurant_id
    {$courierUserJoinSql}
    WHERE " . implode(' AND ', $where) . "
    ORDER BY o.created_at DESC
    LIMIT 150
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

$summary = [
    'awaiting' => 0,
    'assigned' => 0,
    'on_the_way' => 0,
    'delivered_today' => 0,
    'delivery_revenue_today' => 0.0,
    'avg_delivery_minutes' => null,
    'avg_pickup_minutes' => null,
    'overdue_deliveries' => 0,
    'sla_breach_count' => 0,
    'location_live_count' => 0,
    'location_stale_count' => 0,
    'location_offline_count' => 0,
    'active_couriers_count' => 0,
    'active_deliveries_count' => 0,
];
if ($hasOrderTypeCol && $hasCourierStatusCol) {
    $deliveredDateExpr = $hasDeliveredAtCol ? "COALESCE(o.delivered_at, o.updated_at, o.created_at)" : "COALESCE(o.updated_at, o.created_at)";
    $summaryCourierUserIdExpr = $hasCourierUserIdCol ? 'o.courier_user_id' : 'NULL';
    $pickupDurationExpr = $hasCourierTakenAtCol
        ? "TIMESTAMPDIFF(MINUTE, o.created_at, COALESCE(o.courier_taken_at, o.updated_at, o.created_at))"
        : "TIMESTAMPDIFF(MINUTE, o.created_at, COALESCE(o.updated_at, o.created_at))";
    $deliveryDurationExpr = ($hasCourierTakenAtCol && $hasDeliveredAtCol)
        ? "TIMESTAMPDIFF(MINUTE, COALESCE(o.courier_taken_at, o.created_at), COALESCE(o.delivered_at, o.updated_at, o.created_at))"
        : "TIMESTAMPDIFF(MINUTE, o.created_at, COALESCE(o.updated_at, o.created_at))";
    $activeElapsedExpr = "TIMESTAMPDIFF(MINUTE, COALESCE(o.courier_taken_at, o.created_at), NOW())";
    $summarySql = "
        SELECT
            SUM(CASE WHEN {$courierStatusSql} = 'waiting_courier' THEN 1 ELSE 0 END) AS awaiting_cnt,
            SUM(CASE WHEN {$courierStatusSql} = 'handed_to_courier' THEN 1 ELSE 0 END) AS assigned_cnt,
            SUM(CASE WHEN {$courierStatusSql} = 'on_the_way' THEN 1 ELSE 0 END) AS on_way_cnt,
            SUM(CASE WHEN {$courierStatusSql} = 'delivered' AND DATE({$deliveredDateExpr}) = CURRENT_DATE THEN 1 ELSE 0 END) AS delivered_today_cnt,
            SUM(CASE WHEN {$courierStatusSql} = 'delivered' AND DATE({$deliveredDateExpr}) = CURRENT_DATE THEN COALESCE(o.total_price, 0) ELSE 0 END) AS delivered_today_revenue,
            AVG(CASE WHEN {$courierStatusSql} = 'delivered' AND DATE({$deliveredDateExpr}) = CURRENT_DATE THEN {$deliveryDurationExpr} END) AS avg_delivery_minutes,
            AVG(CASE WHEN {$courierStatusSql} IN ('handed_to_courier','on_the_way','delivered') AND DATE({$deliveredDateExpr}) = CURRENT_DATE THEN {$pickupDurationExpr} END) AS avg_pickup_minutes,
            SUM(CASE WHEN {$courierStatusSql} = 'delivered' AND DATE({$deliveredDateExpr}) = CURRENT_DATE AND {$deliveryDurationExpr} > 35 THEN 1 ELSE 0 END) AS overdue_deliveries_cnt,
            SUM(CASE WHEN {$courierStatusSql} IN ('waiting_courier','handed_to_courier','on_the_way') AND {$activeElapsedExpr} > 35 THEN 1 ELSE 0 END) AS active_sla_breach_cnt,
            COUNT(DISTINCT CASE WHEN {$courierStatusSql} IN ('waiting_courier','handed_to_courier','on_the_way') AND {$summaryCourierUserIdExpr} IS NOT NULL THEN {$summaryCourierUserIdExpr} END) AS active_couriers_cnt,
            SUM(CASE WHEN {$courierStatusSql} IN ('waiting_courier','handed_to_courier','on_the_way') THEN 1 ELSE 0 END) AS active_deliveries_cnt
        FROM orders o
        WHERE o.restaurant_id = :rest
          AND LOWER(TRIM(COALESCE(o.order_type, ''))) = 'delivery'
          AND LOWER(COALESCE(o.order_status, 'new')) NOT IN ('canceled', 'cancelled')
    ";
    $stmtSummary = $pdo->prepare($summarySql);
    $stmtSummary->execute([':rest' => (int)$currentRestaurant['id']]);
    $sumRow = $stmtSummary->fetch(PDO::FETCH_ASSOC) ?: [];
    $summary['awaiting'] = (int)($sumRow['awaiting_cnt'] ?? 0);
    $summary['assigned'] = (int)($sumRow['assigned_cnt'] ?? 0);
    $summary['on_the_way'] = (int)($sumRow['on_way_cnt'] ?? 0);
    $summary['delivered_today'] = (int)($sumRow['delivered_today_cnt'] ?? 0);
    $summary['delivery_revenue_today'] = (float)($sumRow['delivered_today_revenue'] ?? 0);
    $summary['avg_delivery_minutes'] = isset($sumRow['avg_delivery_minutes']) && $sumRow['avg_delivery_minutes'] !== null
        ? (int)round((float)$sumRow['avg_delivery_minutes'])
        : null;
    $summary['avg_pickup_minutes'] = isset($sumRow['avg_pickup_minutes']) && $sumRow['avg_pickup_minutes'] !== null
        ? (int)round((float)$sumRow['avg_pickup_minutes'])
        : null;
    $summary['overdue_deliveries'] = (int)($sumRow['overdue_deliveries_cnt'] ?? 0);
    $summary['sla_breach_count'] = (int)($sumRow['active_sla_breach_cnt'] ?? 0);
    $summary['active_couriers_count'] = (int)($sumRow['active_couriers_cnt'] ?? 0);
    $summary['active_deliveries_count'] = (int)($sumRow['active_deliveries_cnt'] ?? 0);
}

$tipsSummaryGlobal = [
    'total_tips' => 0.0,
    'waiter_tips' => 0.0,
    'courier_tips' => 0.0,
    'average_tip' => 0.0,
    'tips_count' => 0,
    'pending_count' => 0,
    'paid_count' => 0,
    'cancelled_count' => 0,
    'active_tips_count' => 0,
    'earned_tips' => 0.0,
    'recent_tips' => [],
];
$tipsSummaryCourier = $tipsSummaryGlobal;
if (function_exists('order_tip_summary')) {
    try {
        $tipsSummaryGlobal = order_tip_summary($pdo, (int)$currentRestaurant['id'], [
            'scope' => 'courier',
            'days' => 120,
            'limit' => 8,
        ]);
        $courierTipUserId = $isCourierOnly ? $currentUserId : 0;
        $tipsSummaryCourier = order_tip_summary($pdo, (int)$currentRestaurant['id'], [
            'scope' => 'courier',
            'days' => 120,
            'limit' => 8,
            'courier_user_id' => $courierTipUserId > 0 ? $courierTipUserId : 0,
        ]);
    } catch (Throwable $e) {
        error_log('STAFF_COURIER_TIPS_SUMMARY_FAIL rest=' . (int)$currentRestaurant['id'] . ' ' . $e->getMessage());
    }
}

$earningsSummaryGlobal = function_exists('courier_earnings_summary')
    ? courier_earnings_summary($pdo, (int)$currentRestaurant['id'], ['days' => 30])
    : [
        'today' => [
            'records_count' => 0,
            'deliveries_count' => 0,
            'base_amount' => 0.0,
            'bonus_amount' => 0.0,
            'total_amount' => 0.0,
            'payout_ready_amount' => 0.0,
            'payout_hold_amount' => 0.0,
            'pending_review_amount' => 0.0,
            'payout_estimated_amount' => 0.0,
        ],
        'period' => [],
        'alerts' => [],
    ];
$earningsSummaryCourier = function_exists('courier_earnings_shift')
    ? courier_earnings_shift(
        $pdo,
        (int)$currentRestaurant['id'],
        $isCourierOnly ? $currentUserId : 0,
        0
    )
    : [
        'total_amount' => 0.0,
        'base_amount' => 0.0,
        'bonus_amount' => 0.0,
        'bonus_earned' => 0.0,
        'tips_earned' => 0.0,
        'earnings_per_hour' => 0.0,
        'payout_ready_amount' => 0.0,
        'payout_hold_amount' => 0.0,
        'pending_review_amount' => 0.0,
    ];

$tipByOrder = [];
$earningByOrder = [];
$orderIdsForTips = [];
foreach ($orders as $oRow) {
    $oid = (int)($oRow['id'] ?? 0);
    if ($oid > 0) {
        $orderIdsForTips[] = $oid;
    }
}
$orderIdsForTips = array_values(array_unique($orderIdsForTips));
if (
    $orderIdsForTips !== []
    && function_exists('db_table_exists')
    && db_table_exists('order_tips')
) {
    try {
        $ph = implode(',', array_fill(0, count($orderIdsForTips), '?'));
        $sqlTips = "
            SELECT
                order_id,
                COALESCE(SUM(CASE WHEN status = 'paid' THEN amount ELSE 0 END), 0) AS paid_amount,
                COALESCE(SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END), 0) AS pending_amount,
                MAX(currency) AS currency,
                COUNT(*) AS tip_count
            FROM order_tips
            WHERE restaurant_id = ?
              AND order_id IN ({$ph})
            GROUP BY order_id
        ";
        $stmtTips = $pdo->prepare($sqlTips);
        $stmtTips->execute(array_merge([(int)$currentRestaurant['id']], $orderIdsForTips));
        foreach (($stmtTips->fetchAll(PDO::FETCH_ASSOC) ?: []) as $tipRow) {
            $oid = (int)($tipRow['order_id'] ?? 0);
            if ($oid <= 0) {
                continue;
            }
            $tipByOrder[$oid] = [
                'paid_amount' => (float)($tipRow['paid_amount'] ?? 0),
                'pending_amount' => (float)($tipRow['pending_amount'] ?? 0),
                'currency' => trim((string)($tipRow['currency'] ?? 'RUB')),
                'tip_count' => (int)($tipRow['tip_count'] ?? 0),
            ];
        }
    } catch (Throwable $e) {
        error_log('STAFF_COURIER_TIP_ORDER_MAP_FAIL rest=' . (int)$currentRestaurant['id'] . ' ' . $e->getMessage());
    }
}
if (
    $orderIdsForTips !== []
    && function_exists('db_table_exists')
    && db_table_exists('courier_earnings')
) {
    try {
        $ph = implode(',', array_fill(0, count($orderIdsForTips), '?'));
        $sqlEarnings = "
            SELECT
                order_id,
                COALESCE(SUM(base_amount), 0) AS base_amount,
                COALESCE(SUM(bonus_amount), 0) AS bonus_amount,
                COALESCE(SUM(total_amount), 0) AS total_amount,
                MAX(payout_status) AS payout_status
            FROM courier_earnings
            WHERE restaurant_id = ?
              AND order_id IN ({$ph})
            GROUP BY order_id
        ";
        $stmtEarn = $pdo->prepare($sqlEarnings);
        $stmtEarn->execute(array_merge([(int)$currentRestaurant['id']], $orderIdsForTips));
        foreach (($stmtEarn->fetchAll(PDO::FETCH_ASSOC) ?: []) as $erow) {
            $oid = (int)($erow['order_id'] ?? 0);
            if ($oid <= 0) {
                continue;
            }
            $earningByOrder[$oid] = [
                'base_amount' => (float)($erow['base_amount'] ?? 0),
                'bonus_amount' => (float)($erow['bonus_amount'] ?? 0),
                'total_amount' => (float)($erow['total_amount'] ?? 0),
                'payout_status' => (string)($erow['payout_status'] ?? 'pending_review'),
            ];
        }
    } catch (Throwable $e) {
        error_log('STAFF_COURIER_EARNING_ORDER_MAP_FAIL rest=' . (int)$currentRestaurant['id'] . ' ' . $e->getMessage());
    }
}

foreach ($orders as &$orderRow) {
    $orderRow['courier_status_norm'] = function_exists('courier_status_normalize')
        ? courier_status_normalize((string)($orderRow['courier_status'] ?? ''), 'delivery')
        : 'waiting_courier';
    $orderRow['sla_meta'] = function_exists('courier_order_sla_meta')
        ? courier_order_sla_meta($orderRow)
        : ['level' => 'normal', 'label' => 'В норме', 'elapsed_minutes' => 0];
    $orderRow['timing_meta'] = function_exists('courier_delivery_timing')
        ? courier_delivery_timing([
            'order_type' => 'delivery',
            'order_status' => (string)($orderRow['order_status'] ?? 'new'),
            'courier_status' => (string)($orderRow['courier_status_norm'] ?? 'waiting_courier'),
            'created_at' => $orderRow['created_at'] ?? null,
            'courier_taken_at' => $orderRow['courier_taken_at'] ?? null,
            'courier_on_the_way_at' => $orderRow['courier_on_the_way_at'] ?? null,
            'delivered_at' => $orderRow['delivered_at'] ?? null,
            'table_id' => (int)($orderRow['table_id'] ?? 0),
        ], [
            'avg_delivery_minutes' => (int)($summary['avg_delivery_minutes'] ?? 35),
            'avg_pickup_minutes' => (int)($summary['avg_pickup_minutes'] ?? 10),
            'avg_on_the_way_minutes' => 18,
        ])
        : ['eta_minutes' => 0, 'eta_label' => '', 'timing_state' => 'preparing', 'timing_progress_percent' => 0, 'elapsed_minutes' => 0];

    $locRow = function_exists('courier_location_latest')
        ? courier_location_latest(
            $pdo,
            (int)$currentRestaurant['id'],
            (int)($orderRow['id'] ?? 0),
            (int)($orderRow['courier_user_id'] ?? 0) > 0 ? (int)$orderRow['courier_user_id'] : null
        )
        : null;
    $orderRow['location_meta'] = function_exists('courier_location_public_payload')
        ? courier_location_public_payload($locRow, ['live_sec' => 20, 'stale_sec' => 90])
        : ['has_location' => false, 'state' => 'offline', 'label' => 'Позиция недоступна', 'last_update_human' => ''];
    $locState = (string)($orderRow['location_meta']['state'] ?? 'offline');
    if ($locState === 'live') {
        $summary['location_live_count']++;
    } elseif ($locState === 'stale') {
        $summary['location_stale_count']++;
    } else {
        $summary['location_offline_count']++;
    }
}
unset($orderRow);

usort($orders, static function (array $a, array $b): int {
    $aStatus = (string)($a['courier_status_norm'] ?? 'waiting_courier');
    $bStatus = (string)($b['courier_status_norm'] ?? 'waiting_courier');
    $weight = static function (string $status): int {
        return match ($status) {
            'waiting_courier' => 0,
            'on_the_way' => 1,
            'handed_to_courier' => 2,
            default => 3,
        };
    };
    $aw = $weight($aStatus);
    $bw = $weight($bStatus);
    if ($aw !== $bw) {
        return $aw <=> $bw;
    }

    $aCreatedTs = strtotime((string)($a['created_at'] ?? '')) ?: 0;
    $bCreatedTs = strtotime((string)($b['created_at'] ?? '')) ?: 0;
    if ($aStatus === 'waiting_courier') {
        // Waiting longest first
        return $aCreatedTs <=> $bCreatedTs;
    }
    if ($aStatus === 'on_the_way') {
        $aWayTs = strtotime((string)($a['courier_on_the_way_at'] ?? '')) ?: $aCreatedTs;
        $bWayTs = strtotime((string)($b['courier_on_the_way_at'] ?? '')) ?: $bCreatedTs;
        return $aWayTs <=> $bWayTs;
    }
    // Newer first for lower-priority groups.
    return $bCreatedTs <=> $aCreatedTs;
});

$dispatchQueue = function_exists('delivery_dispatch_queue')
    ? delivery_dispatch_queue($pdo, (int)$currentRestaurant['id'], [
        'scope' => $scope,
        'viewer_user_id' => $currentUserId,
        'viewer_role' => $staffRole,
        'limit' => 180,
        'avg_delivery_minutes' => (int)($summary['avg_delivery_minutes'] ?? 35),
        'avg_pickup_minutes' => (int)($summary['avg_pickup_minutes'] ?? 10),
        'avg_on_the_way_minutes' => 18,
    ])
    : [];
$dispatchQueueByOrderId = [];
foreach ($dispatchQueue as $dqRow) {
    $dqId = (int)($dqRow['id'] ?? 0);
    if ($dqId > 0) {
        $dispatchQueueByOrderId[$dqId] = $dqRow;
    }
}
$dispatchBatchCandidates = function_exists('delivery_dispatch_batch_candidates')
    ? delivery_dispatch_batch_candidates($dispatchQueue, ['window_minutes' => 30, 'max_candidates' => 3, 'max_batch_size' => 3])
    : [];
$dispatchCourierLoad = function_exists('delivery_dispatch_courier_load')
    ? delivery_dispatch_courier_load($pdo, (int)$currentRestaurant['id'], ['overload_threshold' => 4])
    : [];
$dispatchZones = function_exists('delivery_zone_list')
    ? delivery_zone_list($pdo, (int)$currentRestaurant['id'])
    : [];
$dispatchSummary = function_exists('delivery_dispatch_summary')
    ? delivery_dispatch_summary($dispatchQueue, $dispatchCourierLoad, [
        'delivery_zones' => $dispatchZones,
        'waiting_sla_minutes' => 20,
    ])
    : [
        'total' => 0,
        'waiting_dispatch' => 0,
        'assigned' => 0,
        'courier_arriving' => 0,
        'picked_up' => 0,
        'on_the_way' => 0,
        'delivered' => 0,
        'failed' => 0,
        'cancelled' => 0,
        'avg_dispatch_minutes' => null,
        'avg_courier_load' => 0.0,
        'dispatch_sla_percent' => 100.0,
        'assignment_efficiency_percent' => 0.0,
        'delivery_throughput' => 0,
        'queue_pressure' => 0,
        'zone_summary' => [],
        'zone_alerts' => [],
        'zones_overloaded' => 0,
        'zones_hotspot' => 0,
        'zones_batching_opportunity' => 0,
        'alerts' => [],
    ];
$dispatchZoneSummary = is_array($dispatchSummary['zone_summary'] ?? null) ? $dispatchSummary['zone_summary'] : [];
$dispatchZoneAlerts = is_array($dispatchSummary['zone_alerts'] ?? null) ? $dispatchSummary['zone_alerts'] : [];

$deliveryOpsPeriodToday = function_exists('analytics_period_bounds')
    ? analytics_period_bounds('today')
    : [
        'range_key' => 'today',
        'label' => 'Сегодня',
        'start_at' => date('Y-m-d 00:00:00'),
        'end_at' => date('Y-m-d H:i:s'),
        'duration_seconds' => 86400,
    ];
$deliveryOpsPeriodWeek = function_exists('analytics_period_bounds')
    ? analytics_period_bounds('week')
    : [
        'range_key' => 'week',
        'label' => '7 дней',
        'start_at' => date('Y-m-d 00:00:00', strtotime('-6 days')),
        'end_at' => date('Y-m-d H:i:s'),
        'duration_seconds' => 7 * 86400,
    ];
$deliveryOpsToday = function_exists('delivery_operational_analytics')
    ? delivery_operational_analytics($pdo, (int)$currentRestaurant['id'], $deliveryOpsPeriodToday, ['delivery_sla_target_minutes' => 35])
    : ['heatmap' => [], 'zone' => ['rows' => []], 'sla' => [], 'courier' => [], 'profitability' => [], 'alerts' => []];
$deliveryOpsWeek = function_exists('delivery_operational_analytics')
    ? delivery_operational_analytics($pdo, (int)$currentRestaurant['id'], $deliveryOpsPeriodWeek, ['delivery_sla_target_minutes' => 35])
    : ['heatmap' => [], 'zone' => ['rows' => []], 'sla' => [], 'courier' => [], 'profitability' => [], 'alerts' => []];
$forecastNextShift = function_exists('forecast_operational_foundation')
    ? forecast_operational_foundation($pdo, (int)$currentRestaurant['id'], ['target' => 'next_shift'])
    : ['orders' => [], 'delivery_load' => [], 'zone_pressure' => ['peak_zone' => null], 'staffing_need' => [], 'alerts' => []];

$currentShiftActive = function_exists('courier_shift_active')
    ? courier_shift_active($pdo, (int)$currentRestaurant['id'], $currentUserId)
    : null;
$currentShiftWorkload = function_exists('courier_shift_workload')
    ? courier_shift_workload($pdo, (int)$currentRestaurant['id'], $currentUserId, ['overload_threshold' => 4])
    : [
        'shift_active' => false,
        'shift_status' => 'offline',
        'active_hours' => 0.0,
        'total_online_minutes' => 0,
        'active_deliveries' => 0,
        'completed_deliveries' => 0,
        'avg_delivery_duration' => null,
        'deliveries_per_hour' => 0.0,
        'idle_minutes' => null,
        'overload_periods' => 0,
        'active_queue_minutes' => 0,
    ];
$currentShiftLocationState = 'offline';
if (function_exists('db_table_exists') && db_table_exists('courier_locations') && function_exists('courier_location_is_fresh')) {
    try {
        $stmtLocShift = $pdo->prepare("
            SELECT MAX(updated_at) AS last_location_at
            FROM courier_locations
            WHERE restaurant_id = :rest
              AND courier_user_id = :uid
        ");
        $stmtLocShift->execute([
            ':rest' => (int)$currentRestaurant['id'],
            ':uid' => $currentUserId,
        ]);
        $lastLocationShift = (string)($stmtLocShift->fetchColumn() ?: '');
        $locMetaShift = courier_location_is_fresh($lastLocationShift, 20, 90);
        $currentShiftLocationState = (string)($locMetaShift['state'] ?? 'offline');
    } catch (Throwable $e) {
        $currentShiftLocationState = 'offline';
    }
}
$currentShiftStateMeta = function_exists('courier_shift_status')
    ? courier_shift_status(
        is_array($currentShiftActive) ? $currentShiftActive : null,
        (int)($currentShiftWorkload['active_deliveries'] ?? 0),
        $currentShiftLocationState,
        4
    )
    : [
        'key' => 'offline',
        'label' => 'Оффлайн',
        'is_available' => false,
        'is_active_shift' => false,
        'is_paused' => false,
        'is_overloaded' => false,
        'reason' => 'fallback',
    ];
$currentShiftId = is_array($currentShiftActive) ? (int)($currentShiftActive['id'] ?? 0) : 0;
if (function_exists('courier_earnings_shift') && $isCourierOnly && $currentUserId > 0) {
    $earningsSummaryCourier = courier_earnings_shift(
        $pdo,
        (int)$currentRestaurant['id'],
        $currentUserId,
        $currentShiftId
    );
}
$shiftSummary = function_exists('courier_shift_summary')
    ? courier_shift_summary($pdo, (int)$currentRestaurant['id'], [
        'overload_threshold' => 4,
        'paused_limit_minutes' => 30,
        'stale_shift_minutes' => 720,
    ])
    : [
        'active_couriers' => 0,
        'online_couriers' => 0,
        'available_couriers' => 0,
        'paused_couriers' => 0,
        'busy_couriers' => 0,
        'overloaded_couriers' => 0,
        'avg_online_minutes' => 0,
        'avg_online_hours' => 0.0,
        'shift_efficiency' => 0.0,
        'deliveries_per_hour' => 0.0,
        'courier_utilization' => 0.0,
        'alerts' => [],
    ];

$guestById = [];
$guestIds = [];
foreach ($orders as $o) {
    $gid = (int)($o['guest_id'] ?? 0);
    if ($gid > 0) {
        $guestIds[] = $gid;
    }
}
$guestIds = array_values(array_unique($guestIds));
if ($guestIds !== [] && function_exists('db_table_exists') && db_table_exists('guests')) {
    $ph = implode(',', array_fill(0, count($guestIds), '?'));
    $stmtGuests = $pdo->prepare("SELECT id, name, phone FROM guests WHERE id IN ($ph)");
    $stmtGuests->execute($guestIds);
    foreach ($stmtGuests->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $guestById[(int)($row['id'] ?? 0)] = [
            'name' => trim((string)($row['name'] ?? '')),
            'phone' => trim((string)($row['phone'] ?? '')),
        ];
    }
}

function courier_status_badge_class(string $status): string
{
    return match ($status) {
        'waiting_courier' => 'border-amber-400/70 bg-amber-500/10 text-amber-100',
        'handed_to_courier' => 'border-indigo-400/70 bg-indigo-500/10 text-indigo-100',
        'on_the_way' => 'border-sky-400/70 bg-sky-500/10 text-sky-100',
        'delivered' => 'border-emerald-400/70 bg-emerald-500/10 text-emerald-100',
        default => 'border-slate-700 bg-slate-900/80 text-slate-200',
    };
}

function courier_sla_badge_class(string $level): string
{
    return match ($level) {
        'warning' => 'border-amber-400/70 bg-amber-500/10 text-amber-100',
        'critical' => 'border-red-400/70 bg-red-500/10 text-red-100',
        default => 'border-emerald-400/70 bg-emerald-500/10 text-emerald-100',
    };
}

function courier_eta_badge_class(string $timingState): string
{
    return match ($timingState) {
        'almost_arrived' => 'border-emerald-400/70 bg-emerald-500/10 text-emerald-100',
        'on_the_way', 'courier_arriving' => 'border-sky-400/70 bg-sky-500/10 text-sky-100',
        'searching_courier' => 'border-amber-400/70 bg-amber-500/10 text-amber-100',
        'preparing' => 'border-violet-400/70 bg-violet-500/10 text-violet-100',
        'delivered' => 'border-emerald-400/70 bg-emerald-500/10 text-emerald-100',
        default => 'border-slate-700 bg-slate-900/80 text-slate-200',
    };
}

function courier_location_badge_class(string $state): string
{
    return match ($state) {
        'live' => 'border-emerald-400/70 bg-emerald-500/10 text-emerald-100',
        'stale' => 'border-amber-400/70 bg-amber-500/10 text-amber-100',
        default => 'border-slate-700 bg-slate-900/80 text-slate-300',
    };
}

function courier_shift_badge_class(string $state): string
{
    return match ($state) {
        'available' => 'border-emerald-400/70 bg-emerald-500/10 text-emerald-100',
        'busy' => 'border-sky-400/70 bg-sky-500/10 text-sky-100',
        'overloaded' => 'border-red-400/70 bg-red-500/10 text-red-100',
        'paused' => 'border-amber-400/70 bg-amber-500/10 text-amber-100',
        'online' => 'border-violet-400/70 bg-violet-500/10 text-violet-100',
        default => 'border-slate-700 bg-slate-900/80 text-slate-300',
    };
}

function dispatch_priority_badge_class(string $level): string
{
    return match ($level) {
        'critical' => 'border-red-400/70 bg-red-500/10 text-red-100',
        'warning' => 'border-amber-400/70 bg-amber-500/10 text-amber-100',
        default => 'border-emerald-400/70 bg-emerald-500/10 text-emerald-100',
    };
}

function dispatch_alert_badge_class(string $level): string
{
    return match ($level) {
        'critical' => 'border-red-500/70 bg-red-500/10 text-red-100',
        'warning' => 'border-amber-500/70 bg-amber-500/10 text-amber-100',
        default => 'border-slate-700 bg-slate-900/80 text-slate-200',
    };
}

function dispatch_zone_pressure_badge_class(bool $overloaded, bool $hotspot): string
{
    if ($overloaded) {
        return 'border-red-500/70 bg-red-500/10 text-red-100';
    }
    if ($hotspot) {
        return 'border-amber-500/70 bg-amber-500/10 text-amber-100';
    }
    return 'border-emerald-500/70 bg-emerald-500/10 text-emerald-100';
}

function courier_payout_badge_class(string $status): string
{
    return match ($status) {
        'ready_for_payout' => 'border-emerald-400/70 bg-emerald-500/10 text-emerald-100',
        'pending_review' => 'border-amber-400/70 bg-amber-500/10 text-amber-100',
        'payout_hold' => 'border-red-400/70 bg-red-500/10 text-red-100',
        'paid' => 'border-sky-400/70 bg-sky-500/10 text-sky-100',
        default => 'border-slate-700 bg-slate-900/80 text-slate-300',
    };
}

?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Courier Screen — <?= e($currentRestaurant['name']) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-950 text-slate-50">
<main class="max-w-6xl mx-auto p-4 md:p-6 space-y-4">
    <header class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <div class="text-xs uppercase tracking-wide text-violet-300">Delivery Operations</div>
            <h1 class="text-2xl font-semibold">Курьерский экран</h1>
            <p class="text-sm text-slate-400 mt-1"><?= e($currentRestaurant['name']) ?> · роль: <?= e($staffRole) ?></p>
            <div id="courier-gps-status" class="mt-1 text-[11px] text-slate-500">GPS: ожидание геопозиции…</div>
        </div>
        <div class="flex flex-wrap gap-2 text-sm">
            <a href="/staff/orders.php" class="px-3 py-2 rounded-xl border border-slate-700 bg-slate-900/70 hover:bg-slate-800">Заказы</a>
            <a href="/staff/floorplan.php" class="px-3 py-2 rounded-xl border border-slate-700 bg-slate-900/70 hover:bg-slate-800">Карта зала</a>
            <a href="/staff/pos.php" class="px-3 py-2 rounded-xl border border-emerald-500/40 bg-emerald-500/10 text-emerald-100 hover:bg-emerald-500/20">POS</a>
        </div>
    </header>

    <div class="flex flex-wrap gap-2 text-xs">
        <?php foreach (['active' => 'Активные', 'mine' => 'Мои', 'done' => 'Доставленные', 'all' => 'Все'] as $key => $label): ?>
            <a
                href="/staff/courier.php?scope=<?= urlencode($key) ?>"
                class="px-3 py-1.5 rounded-xl border <?= $scope === $key
                    ? 'border-violet-400 bg-violet-500/15 text-violet-100'
                    : 'border-slate-700 bg-slate-900/70 text-slate-200 hover:bg-slate-800' ?>"
            ><?= e($label) ?></a>
        <?php endforeach; ?>
    </div>

    <section class="rounded-2xl border border-cyan-500/35 bg-cyan-500/10 p-3 space-y-3">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div>
                <div class="text-xs uppercase tracking-wide text-cyan-200">Courier shifts</div>
                <div class="text-sm text-cyan-100/90">Смены, доступность и operational-нагрузка курьеров.</div>
            </div>
            <span class="inline-flex items-center px-2.5 py-1 rounded-full border text-xs <?= e(courier_shift_badge_class((string)($currentShiftStateMeta['key'] ?? 'offline'))) ?>">
                Моя смена: <?= e((string)($currentShiftStateMeta['label'] ?? 'Оффлайн')) ?>
            </span>
        </div>

        <div class="grid grid-cols-2 md:grid-cols-4 xl:grid-cols-8 gap-2 text-xs">
            <div class="rounded-xl border border-slate-700 bg-slate-900/70 px-2 py-2"><div class="text-slate-400">Active couriers</div><div class="text-slate-100 font-semibold mt-1"><?= (int)($shiftSummary['active_couriers'] ?? 0) ?></div></div>
            <div class="rounded-xl border border-emerald-500/40 bg-emerald-500/10 px-2 py-2"><div class="text-emerald-200/80">Available</div><div class="text-emerald-100 font-semibold mt-1"><?= (int)($shiftSummary['available_couriers'] ?? 0) ?></div></div>
            <div class="rounded-xl border border-sky-500/40 bg-sky-500/10 px-2 py-2"><div class="text-sky-200/80">Busy</div><div class="text-sky-100 font-semibold mt-1"><?= (int)($shiftSummary['busy_couriers'] ?? 0) ?></div></div>
            <div class="rounded-xl border border-red-500/40 bg-red-500/10 px-2 py-2"><div class="text-red-200/80">Overloaded</div><div class="text-red-100 font-semibold mt-1"><?= (int)($shiftSummary['overloaded_couriers'] ?? 0) ?></div></div>
            <div class="rounded-xl border border-amber-500/40 bg-amber-500/10 px-2 py-2"><div class="text-amber-200/80">Paused</div><div class="text-amber-100 font-semibold mt-1"><?= (int)($shiftSummary['paused_couriers'] ?? 0) ?></div></div>
            <div class="rounded-xl border border-slate-700 bg-slate-900/70 px-2 py-2"><div class="text-slate-400">Avg online</div><div class="text-slate-100 font-semibold mt-1"><?= (int)($shiftSummary['avg_online_minutes'] ?? 0) ?> мин</div></div>
            <div class="rounded-xl border border-slate-700 bg-slate-900/70 px-2 py-2"><div class="text-slate-400">Deliveries/hr</div><div class="text-slate-100 font-semibold mt-1"><?= number_format((float)($shiftSummary['deliveries_per_hour'] ?? 0), 2, '.', ' ') ?></div></div>
            <div class="rounded-xl border border-slate-700 bg-slate-900/70 px-2 py-2"><div class="text-slate-400">Utilization</div><div class="text-slate-100 font-semibold mt-1"><?= number_format((float)($shiftSummary['courier_utilization'] ?? 0), 1, '.', ' ') ?>%</div></div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-2 text-xs">
            <div class="rounded-xl border border-slate-700 bg-slate-900/70 px-2.5 py-2 space-y-1">
                <div class="text-slate-300">Моя загрузка: active <?= (int)($currentShiftWorkload['active_deliveries'] ?? 0) ?> · done <?= (int)($currentShiftWorkload['completed_deliveries'] ?? 0) ?></div>
                <div class="text-slate-400">online <?= (int)($currentShiftWorkload['total_online_minutes'] ?? 0) ?> мин · dph <?= number_format((float)($currentShiftWorkload['deliveries_per_hour'] ?? 0), 2, '.', ' ') ?></div>
                <div class="text-slate-400">idle <?= $currentShiftWorkload['idle_minutes'] !== null ? ((int)$currentShiftWorkload['idle_minutes'] . ' мин') : '—' ?> · queue <?= (int)($currentShiftWorkload['active_queue_minutes'] ?? 0) ?> мин · GPS <?= e($currentShiftLocationState) ?></div>
            </div>
            <form method="post" class="rounded-xl border border-slate-700 bg-slate-900/70 px-2.5 py-2 grid grid-cols-2 sm:grid-cols-4 gap-2 items-center">
                <input type="hidden" name="csrf" value="<?= e((string)($_SESSION['csrf'] ?? '')) ?>">
                <?php $myShiftState = (string)($currentShiftStateMeta['key'] ?? 'offline'); ?>
                <?php if ($myShiftState === 'offline'): ?>
                    <button type="submit" name="shift_action" value="start" class="min-h-[40px] rounded-lg border border-emerald-400/50 bg-emerald-500/10 text-emerald-100 font-semibold">Start shift</button>
                <?php else: ?>
                    <button type="submit" name="shift_action" value="start" class="min-h-[40px] rounded-lg border border-slate-700 bg-slate-900/60 text-slate-500 font-semibold cursor-default" disabled>Start shift</button>
                <?php endif; ?>
                <?php if ($myShiftState === 'paused'): ?>
                    <button type="submit" name="shift_action" value="resume" class="min-h-[40px] rounded-lg border border-sky-400/50 bg-sky-500/10 text-sky-100 font-semibold">Resume</button>
                <?php else: ?>
                    <button type="submit" name="shift_action" value="pause" class="min-h-[40px] rounded-lg border border-amber-400/50 bg-amber-500/10 text-amber-100 font-semibold" <?= $myShiftState === 'offline' ? 'disabled' : '' ?>>Pause</button>
                <?php endif; ?>
                <button type="submit" name="shift_action" value="end" class="min-h-[40px] rounded-lg border border-red-400/50 bg-red-500/10 text-red-100 font-semibold" <?= $myShiftState === 'offline' ? 'disabled' : '' ?>>End shift</button>
                <div class="text-[11px] text-slate-400">
                    SLA breach periods: <span class="text-slate-200"><?= (int)($currentShiftWorkload['overload_periods'] ?? 0) ?></span>
                </div>
            </form>
        </div>

        <?php $shiftAlerts = is_array($shiftSummary['alerts'] ?? null) ? $shiftSummary['alerts'] : []; ?>
        <?php if ($shiftAlerts !== []): ?>
            <div class="space-y-1">
                <?php foreach (array_slice($shiftAlerts, 0, 4) as $shiftAlert): ?>
                    <?php $shiftAlertLevel = strtolower(trim((string)($shiftAlert['level'] ?? 'warning'))); ?>
                    <div class="rounded-lg border px-2 py-1 text-xs <?= e(dispatch_alert_badge_class($shiftAlertLevel)) ?>">
                        <span class="font-semibold"><?= e((string)($shiftAlert['label'] ?? 'Shift alert')) ?>:</span>
                        <?= e((string)($shiftAlert['message'] ?? '')) ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="rounded-2xl border border-violet-500/35 bg-violet-500/10 p-3 space-y-3">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div>
                <div class="text-xs uppercase tracking-wide text-violet-200">Dispatch foundation</div>
                <div class="text-sm text-violet-100/90">Очередь, назначение, нагрузка курьеров и batching-кандидаты.</div>
            </div>
            <div class="text-xs text-violet-200/80">
                queue pressure: <span class="font-semibold"><?= (int)($dispatchSummary['queue_pressure'] ?? 0) ?>%</span> ·
                assignment efficiency: <span class="font-semibold"><?= number_format((float)($dispatchSummary['assignment_efficiency_percent'] ?? 0), 1, '.', ' ') ?>%</span> ·
                dispatch SLA: <span class="font-semibold"><?= number_format((float)($dispatchSummary['dispatch_sla_percent'] ?? 100), 1, '.', ' ') ?>%</span> ·
                avg courier load: <span class="font-semibold"><?= number_format((float)($dispatchSummary['avg_courier_load'] ?? 0), 2, '.', ' ') ?></span> ·
                zone overloads: <span class="font-semibold"><?= (int)($dispatchSummary['zones_overloaded'] ?? 0) ?></span>
            </div>
        </div>

        <div class="grid grid-cols-2 md:grid-cols-4 xl:grid-cols-8 gap-2 text-xs">
            <div class="rounded-xl border border-slate-700 bg-slate-900/70 px-2 py-2"><div class="text-slate-400">Queue</div><div class="text-slate-100 font-semibold mt-1"><?= (int)($dispatchSummary['total'] ?? 0) ?></div></div>
            <div class="rounded-xl border border-amber-500/40 bg-amber-500/10 px-2 py-2"><div class="text-amber-200/80">Waiting</div><div class="text-amber-100 font-semibold mt-1"><?= (int)($dispatchSummary['waiting_dispatch'] ?? 0) ?></div></div>
            <div class="rounded-xl border border-indigo-500/40 bg-indigo-500/10 px-2 py-2"><div class="text-indigo-200/80">Assigned</div><div class="text-indigo-100 font-semibold mt-1"><?= (int)($dispatchSummary['assigned'] ?? 0) ?></div></div>
            <div class="rounded-xl border border-sky-500/40 bg-sky-500/10 px-2 py-2"><div class="text-sky-200/80">Arriving</div><div class="text-sky-100 font-semibold mt-1"><?= (int)($dispatchSummary['courier_arriving'] ?? 0) ?></div></div>
            <div class="rounded-xl border border-cyan-500/40 bg-cyan-500/10 px-2 py-2"><div class="text-cyan-200/80">Picked up</div><div class="text-cyan-100 font-semibold mt-1"><?= (int)($dispatchSummary['picked_up'] ?? 0) ?></div></div>
            <div class="rounded-xl border border-sky-500/40 bg-sky-500/10 px-2 py-2"><div class="text-sky-200/80">On way</div><div class="text-sky-100 font-semibold mt-1"><?= (int)($dispatchSummary['on_the_way'] ?? 0) ?></div></div>
            <div class="rounded-xl border border-emerald-500/40 bg-emerald-500/10 px-2 py-2"><div class="text-emerald-200/80">Throughput</div><div class="text-emerald-100 font-semibold mt-1"><?= (int)($dispatchSummary['delivery_throughput'] ?? 0) ?></div></div>
            <div class="rounded-xl border border-slate-700 bg-slate-900/70 px-2 py-2"><div class="text-slate-400">Avg dispatch</div><div class="text-slate-100 font-semibold mt-1"><?= $dispatchSummary['avg_dispatch_minutes'] !== null ? ((int)$dispatchSummary['avg_dispatch_minutes'] . ' мин') : '—' ?></div></div>
        </div>

        <?php $dispatchAlerts = is_array($dispatchSummary['alerts'] ?? null) ? $dispatchSummary['alerts'] : []; ?>
        <div class="space-y-1">
            <?php if ($dispatchAlerts === []): ?>
                <div class="text-xs text-emerald-200">Dispatch alerts: критичных сигналов нет.</div>
            <?php else: ?>
                <?php foreach (array_slice($dispatchAlerts, 0, 4) as $alert): ?>
                    <?php $alertLevel = strtolower(trim((string)($alert['level'] ?? 'warning'))); ?>
                    <div class="rounded-lg border px-2 py-1 text-xs <?= e(dispatch_alert_badge_class($alertLevel)) ?>">
                        <span class="font-semibold"><?= e((string)($alert['label'] ?? 'Alert')) ?>:</span>
                        <?= e((string)($alert['message'] ?? '')) ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <?php if ($dispatchCourierLoad !== []): ?>
            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-2 text-xs">
                <?php foreach (array_slice($dispatchCourierLoad, 0, 6) as $courierLoadRow): ?>
                    <?php
                    $loadStateCls = !empty($courierLoadRow['is_overloaded'])
                        ? 'border-red-500/40 bg-red-500/10'
                        : 'border-slate-700 bg-slate-900/70';
                    $courierShiftKey = strtolower(trim((string)($courierLoadRow['shift_status'] ?? 'offline')));
                    ?>
                    <div class="rounded-xl border px-2.5 py-2 <?= e($loadStateCls) ?>">
                        <div class="flex items-center justify-between gap-2">
                            <div class="text-slate-100 font-semibold"><?= e((string)($courierLoadRow['name'] ?? ('User #' . (int)($courierLoadRow['user_id'] ?? 0)))) ?></div>
                            <span class="text-[11px] px-1.5 py-0.5 rounded border <?= e(courier_shift_badge_class($courierShiftKey)) ?>">
                                <?= e((string)($courierLoadRow['shift_status_label'] ?? $courierShiftKey)) ?>
                            </span>
                        </div>
                        <div class="mt-1 flex items-center justify-between gap-2">
                            <span class="text-[11px] px-1.5 py-0.5 rounded border <?= e(courier_location_badge_class((string)($courierLoadRow['location_state'] ?? 'offline'))) ?>">
                                GPS <?= e((string)($courierLoadRow['location_state'] ?? 'offline')) ?>
                            </span>
                            <span class="text-[11px] text-slate-400">online <?= (int)($courierLoadRow['shift_online_minutes'] ?? 0) ?> мин</span>
                        </div>
                        <div class="mt-1 text-slate-300">
                            active <?= (int)($courierLoadRow['active_deliveries'] ?? 0) ?> · done today <?= (int)($courierLoadRow['completed_today'] ?? 0) ?>
                        </div>
                        <div class="text-slate-400">
                            avg <?= isset($courierLoadRow['avg_delivery_minutes']) && $courierLoadRow['avg_delivery_minutes'] !== null ? ((int)$courierLoadRow['avg_delivery_minutes'] . ' мин') : '—' ?> ·
                            idle <?= $courierLoadRow['idle_minutes'] !== null ? ((int)$courierLoadRow['idle_minutes'] . ' мин') : '—' ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($dispatchQueue !== []): ?>
            <div class="rounded-xl border border-slate-700 bg-slate-900/70 p-2.5 space-y-1.5">
                <div class="text-xs text-slate-400">Dispatch queue (top priority)</div>
                <?php foreach (array_slice($dispatchQueue, 0, 6) as $dqTop): ?>
                    <?php
                    $dqOrderId = (int)($dqTop['id'] ?? 0);
                    $dqPr = is_array($dqTop['priority_meta'] ?? null) ? $dqTop['priority_meta'] : ['score' => 0, 'label' => 'Планово', 'level' => 'normal'];
                    $dqState = (string)($dqTop['dispatch_state'] ?? 'waiting_dispatch');
                    ?>
                    <div class="flex flex-wrap items-center justify-between gap-2 text-xs rounded-lg border border-slate-700/80 bg-slate-950/60 px-2 py-1.5">
                        <div class="text-slate-200">
                            <a class="hover:underline" href="/staff/courier.php?scope=active&order_id=<?= $dqOrderId ?>">#<?= $dqOrderId ?></a>
                            · <?= e(delivery_dispatch_status_label($dqState)) ?>
                            · <?= e((string)($dqTop['delivery_zone_label'] ?? ($dqTop['delivery_zone_key_resolved'] ?? 'unknown'))) ?>
                            · <?= e((string)($dqTop['delivery_bucket'] ?? 'unknown')) ?>
                        </div>
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full border <?= e(dispatch_priority_badge_class((string)($dqPr['level'] ?? 'normal'))) ?>">
                            <?= e((string)($dqPr['label'] ?? 'Планово')) ?> · <?= (int)($dqPr['score'] ?? 0) ?>
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($dispatchZoneSummary !== []): ?>
            <div class="rounded-xl border border-slate-700 bg-slate-900/70 p-2.5 space-y-2">
                <div class="text-xs text-slate-400">Delivery zones</div>
                <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-2 text-xs">
                    <?php foreach (array_slice($dispatchZoneSummary, 0, 6) as $zoneRow): ?>
                        <?php
                        $workload = is_array($zoneRow['workload'] ?? null) ? $zoneRow['workload'] : [];
                        $sla = is_array($zoneRow['sla'] ?? null) ? $zoneRow['sla'] : [];
                        $isOverloaded = !empty($zoneRow['is_overloaded']);
                        $isHotspot = !empty($zoneRow['is_hotspot']);
                        ?>
                        <div class="rounded-xl border border-slate-700 bg-slate-950/60 px-2.5 py-2">
                            <div class="flex items-center justify-between gap-2">
                                <div class="text-slate-100 font-semibold"><?= e((string)($zoneRow['zone_name'] ?? 'Zone')) ?></div>
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full border text-[11px] <?= e(dispatch_zone_pressure_badge_class($isOverloaded, $isHotspot)) ?>">
                                    pressure <?= (int)($workload['queue_pressure'] ?? 0) ?>%
                                </span>
                            </div>
                            <div class="mt-1 text-slate-300">
                                active <?= (int)($workload['active'] ?? 0) ?> · waiting <?= (int)($workload['waiting_dispatch'] ?? 0) ?> · couriers <?= (int)($workload['active_couriers'] ?? 0) ?>
                            </div>
                            <div class="text-slate-400">
                                SLA <?= number_format((float)($sla['sla_percent'] ?? 100), 1, '.', ' ') ?>% · overdue <?= (int)($sla['overdue_count'] ?? 0) ?> · batch-ready <?= (int)($workload['batching_ready'] ?? 0) ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php if ($dispatchZoneAlerts !== []): ?>
                    <div class="space-y-1">
                        <?php foreach (array_slice($dispatchZoneAlerts, 0, 4) as $zoneAlert): ?>
                            <?php $zoneAlertLevel = strtolower(trim((string)($zoneAlert['level'] ?? 'warning'))); ?>
                            <div class="rounded-lg border px-2 py-1 text-xs <?= e(dispatch_alert_badge_class($zoneAlertLevel)) ?>">
                                <span class="font-semibold"><?= e((string)($zoneAlert['label'] ?? 'Zone alert')) ?>:</span>
                                <?= e((string)($zoneAlert['message'] ?? '')) ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="grid grid-cols-2 md:grid-cols-4 gap-2">
        <div class="rounded-2xl border border-amber-500/40 bg-amber-500/10 px-3 py-2">
            <div class="text-[11px] text-amber-200/80">Ожидают курьера</div>
            <div class="text-lg font-bold text-amber-100"><?= (int)$summary['awaiting'] ?></div>
        </div>
        <div class="rounded-2xl border border-indigo-500/40 bg-indigo-500/10 px-3 py-2">
            <div class="text-[11px] text-indigo-200/80">Назначены</div>
            <div class="text-lg font-bold text-indigo-100"><?= (int)$summary['assigned'] ?></div>
        </div>
        <div class="rounded-2xl border border-sky-500/40 bg-sky-500/10 px-3 py-2">
            <div class="text-[11px] text-sky-200/80">В пути</div>
            <div class="text-lg font-bold text-sky-100"><?= (int)$summary['on_the_way'] ?></div>
        </div>
        <div class="rounded-2xl border border-emerald-500/40 bg-emerald-500/10 px-3 py-2">
            <div class="text-[11px] text-emerald-200/80">Доставлено сегодня</div>
            <div class="text-lg font-bold text-emerald-100"><?= (int)$summary['delivered_today'] ?></div>
        </div>
    </section>

    <section class="grid grid-cols-2 md:grid-cols-4 gap-2 text-xs">
        <div class="rounded-2xl border border-slate-700 bg-slate-900/70 px-3 py-2">
            <div class="text-slate-400">Выручка доставок сегодня</div>
            <div class="mt-1 text-sm font-semibold text-slate-100"><?= number_format((float)$summary['delivery_revenue_today'], 0, '.', ' ') ?> ₽</div>
        </div>
        <div class="rounded-2xl border border-slate-700 bg-slate-900/70 px-3 py-2">
            <div class="text-slate-400">Среднее время доставки</div>
            <div class="mt-1 text-sm font-semibold text-slate-100">
                <?= $summary['avg_delivery_minutes'] !== null ? ((int)$summary['avg_delivery_minutes'] . ' мин') : '—' ?>
            </div>
        </div>
        <div class="rounded-2xl border border-slate-700 bg-slate-900/70 px-3 py-2">
            <div class="text-slate-400">Средний pickup</div>
            <div class="mt-1 text-sm font-semibold text-slate-100"><?= $summary['avg_pickup_minutes'] !== null ? ((int)$summary['avg_pickup_minutes'] . ' мин') : '—' ?></div>
        </div>
        <div class="rounded-2xl border border-slate-700 bg-slate-900/70 px-3 py-2">
            <div class="text-slate-400">SLA breaches</div>
            <div class="mt-1 text-sm font-semibold <?= ((int)$summary['sla_breach_count'] > 0 || (int)$summary['overdue_deliveries'] > 0) ? 'text-red-300' : 'text-slate-100' ?>">
                активные <?= (int)$summary['sla_breach_count'] ?> · today <?= (int)$summary['overdue_deliveries'] ?>
            </div>
        </div>
    </section>
    <section class="grid grid-cols-2 md:grid-cols-2 gap-2 text-xs">
        <div class="rounded-2xl border border-slate-700 bg-slate-900/70 px-3 py-2">
            <div class="text-slate-400">Активные курьеры</div>
            <div class="mt-1 text-sm font-semibold text-slate-100"><?= (int)$summary['active_couriers_count'] ?></div>
        </div>
        <div class="rounded-2xl border border-slate-700 bg-slate-900/70 px-3 py-2">
            <div class="text-slate-400">Активные доставки</div>
            <div class="mt-1 text-sm font-semibold text-slate-100"><?= (int)$summary['active_deliveries_count'] ?></div>
        </div>
    </section>
    <section class="grid grid-cols-3 gap-2 text-xs">
        <div class="rounded-2xl border border-emerald-500/40 bg-emerald-500/10 px-3 py-2">
            <div class="text-emerald-200/80">GPS live</div>
            <div class="mt-1 text-sm font-semibold text-emerald-100"><?= (int)$summary['location_live_count'] ?></div>
        </div>
        <div class="rounded-2xl border border-amber-500/40 bg-amber-500/10 px-3 py-2">
            <div class="text-amber-200/80">GPS stale</div>
            <div class="mt-1 text-sm font-semibold text-amber-100"><?= (int)$summary['location_stale_count'] ?></div>
        </div>
        <div class="rounded-2xl border border-slate-700 bg-slate-900/70 px-3 py-2">
            <div class="text-slate-400">GPS offline</div>
            <div class="mt-1 text-sm font-semibold text-slate-100"><?= (int)$summary['location_offline_count'] ?></div>
        </div>
    </section>

    <?php $earnToday = is_array($earningsSummaryGlobal['today'] ?? null) ? $earningsSummaryGlobal['today'] : []; ?>
    <section class="rounded-2xl border border-emerald-500/35 bg-emerald-500/10 p-3 space-y-3">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div>
                <div class="text-xs uppercase tracking-wide text-emerald-200">Courier earnings foundation</div>
                <div class="text-sm text-emerald-100/90">Начисления по доставкам, бонусы и готовность к payout.</div>
            </div>
            <div class="text-xs text-emerald-100/80">
                today deliveries: <span class="font-semibold"><?= (int)($earnToday['deliveries_count'] ?? 0) ?></span> ·
                total: <span class="font-semibold"><?= number_format((float)($earnToday['total_amount'] ?? 0), 0, '.', ' ') ?> ₽</span> ·
                bonus: <span class="font-semibold"><?= number_format((float)($earnToday['bonus_amount'] ?? 0), 0, '.', ' ') ?> ₽</span>
            </div>
        </div>
        <div class="grid grid-cols-2 md:grid-cols-4 xl:grid-cols-8 gap-2 text-xs">
            <div class="rounded-xl border border-slate-700 bg-slate-900/70 px-2 py-2"><div class="text-slate-400">Today earned</div><div class="text-slate-100 font-semibold mt-1"><?= number_format((float)($earnToday['total_amount'] ?? 0), 0, '.', ' ') ?> ₽</div></div>
            <div class="rounded-xl border border-slate-700 bg-slate-900/70 px-2 py-2"><div class="text-slate-400">Base</div><div class="text-slate-100 font-semibold mt-1"><?= number_format((float)($earnToday['base_amount'] ?? 0), 0, '.', ' ') ?> ₽</div></div>
            <div class="rounded-xl border border-violet-500/40 bg-violet-500/10 px-2 py-2"><div class="text-violet-200/80">Bonus</div><div class="text-violet-100 font-semibold mt-1"><?= number_format((float)($earnToday['bonus_amount'] ?? 0), 0, '.', ' ') ?> ₽</div></div>
            <div class="rounded-xl border border-emerald-500/40 bg-emerald-500/10 px-2 py-2"><div class="text-emerald-200/80">Ready payout</div><div class="text-emerald-100 font-semibold mt-1"><?= number_format((float)($earnToday['payout_ready_amount'] ?? 0), 0, '.', ' ') ?> ₽</div></div>
            <div class="rounded-xl border border-amber-500/40 bg-amber-500/10 px-2 py-2"><div class="text-amber-200/80">Pending review</div><div class="text-amber-100 font-semibold mt-1"><?= number_format((float)($earnToday['pending_review_amount'] ?? 0), 0, '.', ' ') ?> ₽</div></div>
            <div class="rounded-xl border border-red-500/40 bg-red-500/10 px-2 py-2"><div class="text-red-200/80">Payout hold</div><div class="text-red-100 font-semibold mt-1"><?= number_format((float)($earnToday['payout_hold_amount'] ?? 0), 0, '.', ' ') ?> ₽</div></div>
            <div class="rounded-xl border border-slate-700 bg-slate-900/70 px-2 py-2"><div class="text-slate-400">My earnings/hr</div><div class="text-slate-100 font-semibold mt-1"><?= number_format((float)($earningsSummaryCourier['earnings_per_hour'] ?? 0), 2, '.', ' ') ?></div></div>
            <div class="rounded-xl border border-fuchsia-500/40 bg-fuchsia-500/10 px-2 py-2"><div class="text-fuchsia-200/80">My tips</div><div class="text-fuchsia-100 font-semibold mt-1"><?= number_format((float)($earningsSummaryCourier['tips_earned'] ?? 0), 0, '.', ' ') ?> ₽</div></div>
        </div>
        <?php $earnAlerts = is_array($earningsSummaryGlobal['alerts'] ?? null) ? $earningsSummaryGlobal['alerts'] : []; ?>
        <?php if ($earnAlerts !== []): ?>
            <div class="space-y-1">
                <?php foreach (array_slice($earnAlerts, 0, 4) as $eAlert): ?>
                    <?php $eAlertLevel = strtolower(trim((string)($eAlert['level'] ?? 'warning'))); ?>
                    <div class="rounded-lg border px-2 py-1 text-xs <?= e(dispatch_alert_badge_class($eAlertLevel)) ?>">
                        <span class="font-semibold"><?= e((string)($eAlert['label'] ?? 'Earnings alert')) ?>:</span>
                        <?= e((string)($eAlert['message'] ?? '')) ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <?php
    $opsTodayHeatmap = is_array($deliveryOpsToday['heatmap'] ?? null) ? $deliveryOpsToday['heatmap'] : [];
    $opsTodaySla = is_array($deliveryOpsToday['sla'] ?? null) ? $deliveryOpsToday['sla'] : [];
    $opsTodayProfit = is_array($deliveryOpsToday['profitability'] ?? null) ? $deliveryOpsToday['profitability'] : [];
    $opsTodayCourier = is_array($deliveryOpsToday['courier'] ?? null) ? $deliveryOpsToday['courier'] : [];
    $opsTodayAlerts = is_array($deliveryOpsToday['alerts'] ?? null) ? $deliveryOpsToday['alerts'] : [];
    $opsWeekCourier = is_array($deliveryOpsWeek['courier'] ?? null) ? $deliveryOpsWeek['courier'] : [];
    $opsTopZone = (array)($opsTodayHeatmap['zone_density'][0] ?? []);
    $opsTopDistrict = (array)($opsTodayHeatmap['district_density'][0] ?? []);
    $opsTopCourier = (array)($opsWeekCourier['top_couriers'][0] ?? []);
    $fcOrders = is_array($forecastNextShift['orders'] ?? null) ? $forecastNextShift['orders'] : [];
    $fcDelivery = is_array($forecastNextShift['delivery_load'] ?? null) ? $forecastNextShift['delivery_load'] : [];
    $fcStaff = is_array($forecastNextShift['staffing_need'] ?? null) ? $forecastNextShift['staffing_need'] : [];
    $fcZone = is_array($forecastNextShift['zone_pressure'] ?? null) ? $forecastNextShift['zone_pressure'] : ['peak_zone' => null];
    $fcPeakZone = is_array($fcZone['peak_zone'] ?? null) ? $fcZone['peak_zone'] : [];
    $fcAlerts = is_array($forecastNextShift['alerts'] ?? null) ? $forecastNextShift['alerts'] : [];
    ?>
    <section class="rounded-2xl border border-cyan-500/35 bg-cyan-500/10 p-3 space-y-3">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div>
                <div class="text-xs uppercase tracking-wide text-cyan-200">Delivery operational analytics</div>
                <div class="text-sm text-cyan-100/90">Heatmap, hotspots, SLA visibility, courier performance и маржинальность доставок.</div>
            </div>
            <div class="text-xs text-cyan-100/80">
                today delivery: <span class="font-semibold"><?= (int)($opsTodayHeatmap['total_delivery_orders'] ?? 0) ?></span>
            </div>
        </div>
        <div class="grid grid-cols-2 md:grid-cols-4 xl:grid-cols-8 gap-2 text-xs">
            <div class="rounded-xl border border-slate-700 bg-slate-900/70 px-2 py-2"><div class="text-slate-400">Top district</div><div class="text-slate-100 font-semibold mt-1"><?= e((string)($opsTopDistrict['bucket'] ?? '—')) ?></div></div>
            <div class="rounded-xl border border-slate-700 bg-slate-900/70 px-2 py-2"><div class="text-slate-400">Top zone</div><div class="text-slate-100 font-semibold mt-1"><?= e((string)($opsTopZone['zone_key'] ?? '—')) ?></div></div>
            <div class="rounded-xl border border-emerald-500/40 bg-emerald-500/10 px-2 py-2"><div class="text-emerald-200/80">SLA success</div><div class="text-emerald-100 font-semibold mt-1"><?= number_format((float)($opsTodaySla['sla_success_percent'] ?? 0), 1, '.', ' ') ?>%</div></div>
            <div class="rounded-xl border border-amber-500/40 bg-amber-500/10 px-2 py-2"><div class="text-amber-200/80">Overdue %</div><div class="text-amber-100 font-semibold mt-1"><?= number_format((float)($opsTodaySla['overdue_delivery_percent'] ?? 0), 1, '.', ' ') ?>%</div></div>
            <div class="rounded-xl border border-violet-500/40 bg-violet-500/10 px-2 py-2"><div class="text-violet-200/80">Margin</div><div class="text-violet-100 font-semibold mt-1"><?= number_format((float)($opsTodayProfit['estimated_margin'] ?? 0), 0, '.', ' ') ?> ₽</div></div>
            <div class="rounded-xl border border-slate-700 bg-slate-900/70 px-2 py-2"><div class="text-slate-400">Courier cost</div><div class="text-slate-100 font-semibold mt-1"><?= number_format((float)($opsTodayProfit['courier_cost_total'] ?? 0), 0, '.', ' ') ?> ₽</div></div>
            <div class="rounded-xl border border-fuchsia-500/40 bg-fuchsia-500/10 px-2 py-2"><div class="text-fuchsia-200/80">Avg tips</div><div class="text-fuchsia-100 font-semibold mt-1"><?= number_format((float)($opsTodayProfit['avg_tips'] ?? 0), 0, '.', ' ') ?> ₽</div></div>
            <div class="rounded-xl border border-cyan-400/40 bg-cyan-500/10 px-2 py-2"><div class="text-cyan-200/80">Top courier</div><div class="text-cyan-100 font-semibold mt-1"><?= e((string)($opsTopCourier['name'] ?? '—')) ?></div></div>
        </div>
        <div class="text-xs text-slate-300">
            <?php if ($opsTopCourier !== []): ?>
                Leaderboard: <?= e((string)($opsTopCourier['name'] ?? '—')) ?> · delivered <?= (int)($opsTopCourier['delivered_count'] ?? 0) ?> · SLA <?= number_format((float)($opsTopCourier['sla_success_percent'] ?? 0), 1, '.', ' ') ?>% · eph <?= number_format((float)($opsTopCourier['earnings_per_hour'] ?? 0), 2, '.', ' ') ?>
            <?php else: ?>
                Leaderboard: данных пока недостаточно.
            <?php endif; ?>
        </div>
        <?php if ($opsTodayAlerts !== []): ?>
            <div class="space-y-1">
                <?php foreach (array_slice($opsTodayAlerts, 0, 4) as $opsAlert): ?>
                    <?php $opsLevel = strtolower(trim((string)($opsAlert['level'] ?? 'warning'))); ?>
                    <div class="rounded-lg border px-2 py-1 text-xs <?= e(dispatch_alert_badge_class($opsLevel)) ?>">
                        <span class="font-semibold"><?= e((string)($opsAlert['label'] ?? 'Delivery alert')) ?>:</span>
                        <?= e((string)($opsAlert['message'] ?? '')) ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="rounded-2xl border border-amber-500/35 bg-amber-500/10 p-3 space-y-3">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div>
                <div class="text-xs uppercase tracking-wide text-amber-200">Forecast next shift</div>
                <div class="text-sm text-amber-100/90">Прогноз нагрузки доставки и staffing-рекомендации без ML, на historical patterns.</div>
            </div>
            <div class="text-xs text-amber-100/80">
                orders: <span class="font-semibold"><?= (int)($fcOrders['expected_orders'] ?? 0) ?></span> · delivery: <span class="font-semibold"><?= (int)($fcDelivery['expected_delivery_orders'] ?? 0) ?></span>
            </div>
        </div>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-2 text-xs">
            <div class="rounded-xl border border-slate-700 bg-slate-900/70 px-2 py-2"><div class="text-slate-400">SLA risk</div><div class="text-slate-100 font-semibold mt-1"><?= e((string)($fcDelivery['predicted_sla_risk'] ?? 'low')) ?></div></div>
            <div class="rounded-xl border border-sky-500/40 bg-sky-500/10 px-2 py-2"><div class="text-sky-200/80">Queue pressure</div><div class="text-sky-100 font-semibold mt-1"><?= (int)($fcDelivery['predicted_queue_pressure'] ?? 0) ?>%</div></div>
            <div class="rounded-xl border border-emerald-500/40 bg-emerald-500/10 px-2 py-2"><div class="text-emerald-200/80">Need couriers</div><div class="text-emerald-100 font-semibold mt-1"><?= (int)($fcDelivery['expected_active_couriers'] ?? 0) ?></div></div>
            <div class="rounded-xl border border-violet-500/40 bg-violet-500/10 px-2 py-2"><div class="text-violet-200/80">Peak zone</div><div class="text-violet-100 font-semibold mt-1"><?= e((string)($fcPeakZone['zone_label'] ?? $fcPeakZone['zone_key'] ?? '—')) ?></div></div>
        </div>
        <div class="text-xs text-slate-300">
            Staffing gaps: courier <?= (int)($fcStaff['courier_gap'] ?? 0) ?> · kitchen <?= (int)($fcStaff['kitchen_gap'] ?? 0) ?> · waiter <?= (int)($fcStaff['waiter_gap'] ?? 0) ?>
        </div>
        <?php if ($fcAlerts !== []): ?>
            <div class="space-y-1">
                <?php foreach (array_slice($fcAlerts, 0, 3) as $fAlert): ?>
                    <?php $fLevel = strtolower(trim((string)($fAlert['level'] ?? 'warning'))); ?>
                    <div class="rounded-lg border px-2 py-1 text-xs <?= e(dispatch_alert_badge_class($fLevel)) ?>">
                        <span class="font-semibold"><?= e((string)($fAlert['label'] ?? 'Forecast alert')) ?>:</span>
                        <?= e((string)($fAlert['message'] ?? '')) ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="grid grid-cols-1 md:grid-cols-2 gap-2 text-xs">
        <div class="rounded-2xl border border-slate-700 bg-slate-900/70 px-3 py-3 space-y-1.5">
            <div class="text-slate-400">Tips по доставке (ресторан)</div>
            <div class="text-sm text-slate-100 font-semibold">
                Оплачено: <?= number_format((float)($tipsSummaryGlobal['courier_tips'] ?? 0), 0, '.', ' ') ?> ₽
            </div>
            <div class="text-slate-300">
                pending <?= (int)($tipsSummaryGlobal['pending_count'] ?? 0) ?> · paid <?= (int)($tipsSummaryGlobal['paid_count'] ?? 0) ?> · cancelled <?= (int)($tipsSummaryGlobal['cancelled_count'] ?? 0) ?>
            </div>
            <div class="text-slate-400">
                Средние чаевые: <?= number_format((float)($tipsSummaryGlobal['average_tip'] ?? 0), 0, '.', ' ') ?> ₽
            </div>
        </div>
        <div class="rounded-2xl border border-violet-500/40 bg-violet-500/10 px-3 py-3 space-y-1.5">
            <div class="text-violet-200/90"><?= $isCourierOnly ? 'Мои чаевые' : 'Чаевые выбранного courier scope' ?></div>
            <div class="text-sm text-violet-100 font-semibold">
                Earned: <?= number_format((float)($tipsSummaryCourier['earned_tips'] ?? 0), 0, '.', ' ') ?> ₽
            </div>
            <div class="text-violet-100/80">
                Active: <?= (int)($tipsSummaryCourier['active_tips_count'] ?? 0) ?> · Count: <?= (int)($tipsSummaryCourier['tips_count'] ?? 0) ?>
            </div>
            <?php $recentTipsCourier = is_array($tipsSummaryCourier['recent_tips'] ?? null) ? $tipsSummaryCourier['recent_tips'] : []; ?>
            <?php if ($recentTipsCourier !== []): ?>
                <div class="text-[11px] text-violet-100/70">
                    Recent:
                    <?php foreach (array_slice($recentTipsCourier, 0, 3) as $idx => $tipRow): ?>
                        <?php if ($idx > 0): ?> · <?php endif; ?>
                        #<?= (int)($tipRow['order_id'] ?? 0) ?> <?= number_format((float)($tipRow['amount'] ?? 0), 0, '.', ' ') ?> <?= e((string)($tipRow['currency'] ?? 'RUB')) ?> (<?= e((string)($tipRow['status'] ?? 'pending')) ?>)
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <?php if ($success): ?>
        <div class="rounded-2xl border border-emerald-500/50 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-100"><?= e($success) ?></div>
    <?php endif; ?>
    <?php if ($errors): ?>
        <div class="rounded-2xl border border-red-500/50 bg-red-500/10 px-4 py-3 text-sm text-red-100 space-y-1">
            <?php foreach ($errors as $err): ?>
                <div><?= e($err) ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if (!$hasOrderTypeCol || !$hasCourierStatusCol): ?>
        <div class="rounded-2xl border border-amber-500/50 bg-amber-500/10 px-4 py-3 text-sm text-amber-100">
            Courier foundation schema ещё не готова. Проверьте миграции/ensure для `orders.order_type` и `orders.courier_status`.
        </div>
    <?php endif; ?>

    <?php if (!$orders): ?>
        <div class="rounded-3xl border border-slate-800 bg-slate-900/70 p-8 text-center text-slate-300">
            Нет delivery-заказов для выбранного фильтра.
        </div>
    <?php else: ?>
        <div class="grid gap-3 md:grid-cols-2">
            <?php foreach ($orders as $o): ?>
                <?php
                $orderId = (int)($o['id'] ?? 0);
                $guestId = (int)($o['guest_id'] ?? 0);
                $guestName = trim((string)($guestById[$guestId]['name'] ?? ''));
                $guestPhone = trim((string)($guestById[$guestId]['phone'] ?? ''));
                $customerName = trim((string)($o['customer_name'] ?? ''));
                $customerPhone = trim((string)($o['customer_phone'] ?? ''));
                $deliveryFullName = trim((string)($o['delivery_full_name'] ?? ''));
                $deliveryPhone = trim((string)($o['delivery_phone'] ?? ''));
                $deliveryAddress = trim((string)($o['delivery_address'] ?? ''));
                $contactName = $customerName !== '' ? $customerName : ($deliveryFullName !== '' ? $deliveryFullName : $guestName);
                $contactPhone = $customerPhone !== '' ? $customerPhone : ($deliveryPhone !== '' ? $deliveryPhone : $guestPhone);
                $courierStatus = (string)($o['courier_status_norm'] ?? 'waiting_courier');
                $courierStatusLabel = function_exists('courier_status_label')
                    ? courier_status_label($courierStatus, 'delivery')
                    : $courierStatus;
                $slaMeta = is_array($o['sla_meta'] ?? null) ? $o['sla_meta'] : ['level' => 'normal', 'label' => 'В норме', 'elapsed_minutes' => 0];
                $timingMeta = is_array($o['timing_meta'] ?? null) ? $o['timing_meta'] : ['eta_minutes' => 0, 'eta_label' => '', 'timing_state' => 'preparing', 'timing_progress_percent' => 0];
                $courierUserId = (int)($o['courier_user_id'] ?? 0);
                $courierUserName = trim((string)($o['courier_user_name'] ?? ''));
                $orderComment = $orderCommentCol !== null ? trim((string)($o[$orderCommentCol] ?? '')) : '';
                $locationMeta = is_array($o['location_meta'] ?? null) ? $o['location_meta'] : ['state' => 'offline', 'label' => 'Позиция недоступна', 'last_update_human' => ''];
                $takenAtDisplay = $hasCourierTakenAtCol && !empty($o['courier_taken_at']) ? date('d.m H:i', strtotime((string)$o['courier_taken_at'])) : '';
                $onWayAtDisplay = $hasCourierOnTheWayAtCol && !empty($o['courier_on_the_way_at']) ? date('d.m H:i', strtotime((string)$o['courier_on_the_way_at'])) : '';
                $deliveredAtDisplay = $hasDeliveredAtCol && !empty($o['delivered_at']) ? date('d.m H:i', strtotime((string)$o['delivered_at'])) : '';
                $canInteract = !$isCourierOnly || $courierUserId === 0 || $courierUserId === $currentUserId;
                $isAssignedToCurrent = $courierUserId > 0 && $courierUserId === $currentUserId;
                $locationState = (string)($locationMeta['state'] ?? 'offline');
                $locationLabel = (string)($locationMeta['label'] ?? 'Позиция недоступна');
                $locationAgo = trim((string)($locationMeta['last_update_human'] ?? ''));
                $geoTrackEligible = !$deliveredAtDisplay && $isAssignedToCurrent;
                $tipMeta = $tipByOrder[$orderId] ?? null;
                $tipPaidAmount = is_array($tipMeta) ? (float)($tipMeta['paid_amount'] ?? 0) : 0.0;
                $tipPendingAmount = is_array($tipMeta) ? (float)($tipMeta['pending_amount'] ?? 0) : 0.0;
                $tipCurrency = is_array($tipMeta) ? trim((string)($tipMeta['currency'] ?? 'RUB')) : 'RUB';
                $tipCount = is_array($tipMeta) ? (int)($tipMeta['tip_count'] ?? 0) : 0;
                $earningMeta = $earningByOrder[$orderId] ?? null;
                $earningTotal = is_array($earningMeta) ? (float)($earningMeta['total_amount'] ?? 0) : 0.0;
                $earningBonus = is_array($earningMeta) ? (float)($earningMeta['bonus_amount'] ?? 0) : 0.0;
                $earningPayoutStatus = is_array($earningMeta) ? courier_payout_status_normalize((string)($earningMeta['payout_status'] ?? 'pending_review')) : '';
                $dispatchMeta = $dispatchQueueByOrderId[$orderId] ?? null;
                $dispatchPriority = is_array($dispatchMeta['priority_meta'] ?? null) ? $dispatchMeta['priority_meta'] : ['score' => 0, 'level' => 'normal', 'label' => 'Планово', 'reasons' => []];
                $dispatchState = is_array($dispatchMeta) ? (string)($dispatchMeta['dispatch_state'] ?? $courierStatus) : $courierStatus;
                $dispatchStateLabel = function_exists('delivery_dispatch_status_label')
                    ? delivery_dispatch_status_label($dispatchState)
                    : $courierStatusLabel;
                $batchCandidates = is_array($dispatchBatchCandidates[$orderId] ?? null) ? $dispatchBatchCandidates[$orderId] : [];
                $deliveryZoneLabel = is_array($dispatchMeta) ? (string)($dispatchMeta['delivery_zone_label'] ?? ($dispatchMeta['delivery_zone_key_resolved'] ?? 'Не определена')) : 'Не определена';
                $deliveryZoneColor = is_array($dispatchMeta) ? (string)($dispatchMeta['delivery_zone_color'] ?? '#94A3B8') : '#94A3B8';
                $batchReadinessScore = is_array($dispatchMeta) ? (int)($dispatchMeta['batch_readiness_score'] ?? 0) : 0;
                ?>
                <article class="rounded-3xl border border-slate-800 bg-slate-900/70 p-4 space-y-3" data-courier-track="<?= $geoTrackEligible ? '1' : '0' ?>" data-order-id="<?= $orderId ?>">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <div class="text-sm text-slate-300">Заказ <span class="font-mono text-white">#<?= $orderId ?></span></div>
                            <div class="text-[11px] text-slate-500 mt-1">Создан: <?= e((string)($o['created_at'] ?? '')) ?></div>
                        </div>
                        <div class="text-right">
                            <div class="text-lg font-semibold text-emerald-300"><?= number_format((float)($o['total_price'] ?? 0), 0, '.', ' ') ?> ₽</div>
                            <div class="text-[11px] text-slate-500"><?= e((string)($o['payment_type_safe'] ?? 'cash')) ?> · <?= e((string)($o['payment_status'] ?? '')) ?></div>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full border text-[11px] <?= e(courier_status_badge_class($courierStatus)) ?>">
                            <?= e($courierStatusLabel) ?>
                        </span>
                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full border text-[11px] <?= e(dispatch_priority_badge_class((string)($dispatchPriority['level'] ?? 'normal'))) ?>">
                            Dispatch: <?= e((string)($dispatchPriority['label'] ?? 'Планово')) ?> · <?= (int)($dispatchPriority['score'] ?? 0) ?>
                        </span>
                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full border border-violet-400/60 bg-violet-500/10 text-[11px] text-violet-100">
                            <?= e($dispatchStateLabel) ?>
                        </span>
                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full border text-[11px] text-slate-100" style="border-color: <?= e($deliveryZoneColor) ?>; background-color: rgba(15,23,42,0.75);">
                            Zone: <?= e($deliveryZoneLabel) ?>
                        </span>
                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full border text-[11px] <?= e(courier_sla_badge_class((string)($slaMeta['level'] ?? 'normal'))) ?>">
                            SLA: <?= e((string)($slaMeta['label'] ?? 'В норме')) ?> · <?= (int)($slaMeta['elapsed_minutes'] ?? 0) ?> мин
                        </span>
                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full border text-[11px] <?= e(courier_eta_badge_class((string)($timingMeta['timing_state'] ?? 'preparing'))) ?>">
                            ETA: <?= e((string)($timingMeta['eta_label'] ?? '—')) ?>
                        </span>
                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full border text-[11px] <?= e(courier_location_badge_class($locationState)) ?>">
                            GPS: <?= e($locationState) ?>
                        </span>
                        <?php if ($courierUserName !== ''): ?>
                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full border border-violet-400/50 bg-violet-500/10 text-[11px] text-violet-100">
                                Курьер: <?= e($courierUserName) ?>
                            </span>
                        <?php endif; ?>
                        <?php if ($isAssignedToCurrent): ?>
                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full border border-emerald-400/50 bg-emerald-500/10 text-[11px] text-emerald-100">
                                Мой заказ
                            </span>
                        <?php endif; ?>
                        <?php if ($tipCount > 0): ?>
                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full border border-fuchsia-400/50 bg-fuchsia-500/10 text-[11px] text-fuchsia-100">
                                Tips: +<?= number_format($tipPaidAmount, 0, '.', ' ') ?> <?= e($tipCurrency) ?><?= $tipPendingAmount > 0 ? (' · pending ' . number_format($tipPendingAmount, 0, '.', ' ')) : '' ?>
                            </span>
                        <?php endif; ?>
                        <?php if ($earningTotal > 0): ?>
                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full border text-[11px] <?= e(courier_payout_badge_class($earningPayoutStatus)) ?>">
                                Earnings: <?= number_format($earningTotal, 0, '.', ' ') ?> ₽<?= $earningBonus > 0 ? (' · bonus ' . number_format($earningBonus, 0, '.', ' ')) : '' ?>
                            </span>
                        <?php endif; ?>
                    </div>

                    <?php if (is_array($dispatchPriority['reasons'] ?? null) && $dispatchPriority['reasons'] !== []): ?>
                        <div class="text-[11px] text-slate-400">
                            Причины приоритета: <?= e(implode(' · ', array_slice(array_values($dispatchPriority['reasons']), 0, 3))) ?>
                        </div>
                    <?php endif; ?>
                    <div class="text-[11px] text-slate-400">
                        Batch readiness: <span class="text-slate-200"><?= $batchReadinessScore ?>%</span>
                    </div>
                    <?php if ($batchCandidates !== []): ?>
                        <div class="flex flex-wrap items-center gap-1.5 text-[11px]">
                            <span class="text-slate-500">Batch candidates:</span>
                            <?php foreach ($batchCandidates as $cand): ?>
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full border border-slate-700 bg-slate-900/70 text-slate-200">
                                    #<?= (int)($cand['order_id'] ?? 0) ?> · <?= e((string)($cand['zone_key'] ?? 'unknown')) ?> · <?= (int)($cand['batch_score'] ?? 0) ?>%
                                </span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <div class="text-xs text-slate-300 space-y-1">
                        <?php if ($contactName !== '' || $contactPhone !== ''): ?>
                            <div>Получатель: <span class="text-slate-100"><?= e(trim($contactName . ($contactName !== '' && $contactPhone !== '' ? ' · ' : '') . $contactPhone)) ?></span></div>
                        <?php endif; ?>
                        <?php if ($deliveryAddress !== ''): ?>
                            <div>Адрес: <span class="text-slate-100"><?= e($deliveryAddress) ?></span></div>
                        <?php endif; ?>
                        <?php if ($orderComment !== ''): ?>
                            <div>Комментарий: <span class="text-slate-100"><?= e($orderComment) ?></span></div>
                        <?php endif; ?>
                        <div>Статус заказа: <span class="text-slate-100"><?= e((string)($o['order_status'] ?? '')) ?></span></div>
                        <div>ETA модель: <span class="text-slate-100"><?= e((string)($timingMeta['timing_state'] ?? 'preparing')) ?></span> · <span class="text-slate-100"><?= (int)($timingMeta['timing_progress_percent'] ?? 0) ?>%</span></div>
                        <?php if ($tipCount > 0): ?>
                            <div>Чаевые: <span class="text-emerald-200">paid <?= number_format($tipPaidAmount, 0, '.', ' ') ?> <?= e($tipCurrency) ?></span><?= $tipPendingAmount > 0 ? (' · <span class="text-amber-200">pending ' . number_format($tipPendingAmount, 0, '.', ' ') . ' ' . e($tipCurrency) . '</span>') : '' ?></div>
                        <?php endif; ?>
                        <?php if ($earningTotal > 0): ?>
                            <div>Начисление: <span class="text-emerald-200"><?= number_format($earningTotal, 0, '.', ' ') ?> ₽</span><?= $earningBonus > 0 ? (' · bonus ' . number_format($earningBonus, 0, '.', ' ') . ' ₽') : '' ?> · payout: <span class="text-slate-100"><?= e($earningPayoutStatus) ?></span></div>
                        <?php endif; ?>
                        <div>GPS: <span class="text-slate-100 js-location-state-label"><?= e($locationLabel) ?></span> · <span class="text-slate-400 js-location-age-label"><?= e($locationAgo !== '' ? $locationAgo : '—') ?></span></div>
                        <?php if ($takenAtDisplay !== ''): ?>
                            <div>Взял: <span class="text-slate-100"><?= e($takenAtDisplay) ?></span></div>
                        <?php endif; ?>
                        <?php if ($onWayAtDisplay !== ''): ?>
                            <div>В пути с: <span class="text-slate-100"><?= e($onWayAtDisplay) ?></span></div>
                        <?php endif; ?>
                        <?php if ($deliveredAtDisplay !== ''): ?>
                            <div>Доставлен: <span class="text-slate-100"><?= e($deliveredAtDisplay) ?></span></div>
                        <?php endif; ?>
                    </div>

                    <div class="grid grid-cols-2 gap-2">
                        <?php if ($canManageDelivery): ?>
                            <form method="post" class="col-span-2 grid grid-cols-1 sm:grid-cols-[1fr_auto_auto] gap-2 items-center rounded-xl border border-slate-700 bg-slate-900/60 p-2">
                                <input type="hidden" name="csrf" value="<?= e((string)($_SESSION['csrf'] ?? '')) ?>">
                                <input type="hidden" name="order_id" value="<?= $orderId ?>">
                                <select name="courier_user_id" class="w-full rounded-lg border border-slate-700 bg-slate-950 px-2 py-2 text-sm text-slate-100">
                                    <option value="">Выбрать курьера…</option>
                                    <?php foreach ($dispatchCourierLoad as $courierCandidate): ?>
                                        <?php $candidateId = (int)($courierCandidate['user_id'] ?? 0); ?>
                                        <?php if ($candidateId <= 0): continue; endif; ?>
                                        <?php
                                        $candidateShiftLabel = trim((string)($courierCandidate['shift_status_label'] ?? ''));
                                        $candidateAvailMark = !empty($courierCandidate['is_available']) ? 'available' : 'unavailable';
                                        ?>
                                        <option value="<?= $candidateId ?>" <?= $candidateId === $courierUserId ? 'selected' : '' ?>>
                                            <?= e((string)($courierCandidate['name'] ?? ('User #' . $candidateId))) ?> · load <?= (int)($courierCandidate['active_deliveries'] ?? 0) ?> · <?= e($candidateShiftLabel !== '' ? $candidateShiftLabel : 'shift') ?> · <?= e($candidateAvailMark) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" name="dispatch_action" value="assign" class="min-h-[42px] rounded-xl border border-violet-400/50 bg-violet-500/10 px-3 text-sm font-semibold text-violet-100 hover:bg-violet-500/20">
                                    Assign
                                </button>
                                <?php if ($courierUserId > 0): ?>
                                    <button type="submit" name="dispatch_action" value="unassign" class="min-h-[42px] rounded-xl border border-amber-400/50 bg-amber-500/10 px-3 text-sm font-semibold text-amber-100 hover:bg-amber-500/20">
                                        Unassign
                                    </button>
                                <?php endif; ?>
                            </form>
                        <?php endif; ?>

                        <?php if ($canInteract && $courierUserId <= 0 && $courierStatus === 'waiting_courier'): ?>
                            <form method="post">
                                <input type="hidden" name="csrf" value="<?= e((string)($_SESSION['csrf'] ?? '')) ?>">
                                <input type="hidden" name="order_id" value="<?= $orderId ?>">
                                <input type="hidden" name="courier_action" value="take">
                                <button type="submit" class="w-full min-h-[42px] rounded-xl border border-violet-400/50 bg-violet-500/10 text-sm font-semibold text-violet-100 hover:bg-violet-500/20">
                                    Взять
                                </button>
                            </form>
                        <?php endif; ?>

                        <?php if ($canInteract && in_array($courierStatus, ['waiting_courier', 'handed_to_courier'], true)): ?>
                            <form method="post">
                                <input type="hidden" name="csrf" value="<?= e((string)($_SESSION['csrf'] ?? '')) ?>">
                                <input type="hidden" name="order_id" value="<?= $orderId ?>">
                                <input type="hidden" name="courier_action" value="handed">
                                <button type="submit" class="w-full min-h-[42px] rounded-xl border border-indigo-400/50 bg-indigo-500/10 text-sm font-semibold text-indigo-100 hover:bg-indigo-500/20">
                                    Забрал
                                </button>
                            </form>
                        <?php endif; ?>

                        <?php if ($canInteract && in_array($courierStatus, ['handed_to_courier', 'on_the_way'], true)): ?>
                            <form method="post">
                                <input type="hidden" name="csrf" value="<?= e((string)($_SESSION['csrf'] ?? '')) ?>">
                                <input type="hidden" name="order_id" value="<?= $orderId ?>">
                                <input type="hidden" name="courier_action" value="on_the_way">
                                <button type="submit" class="w-full min-h-[42px] rounded-xl border border-sky-400/50 bg-sky-500/10 text-sm font-semibold text-sky-100 hover:bg-sky-500/20">
                                    В пути
                                </button>
                            </form>
                        <?php endif; ?>

                        <?php if ($canInteract && $courierStatus !== 'delivered'): ?>
                            <form method="post">
                                <input type="hidden" name="csrf" value="<?= e((string)($_SESSION['csrf'] ?? '')) ?>">
                                <input type="hidden" name="order_id" value="<?= $orderId ?>">
                                <input type="hidden" name="courier_action" value="delivered">
                                <button type="submit" class="w-full min-h-[42px] rounded-xl border border-emerald-400/50 bg-emerald-500/10 text-sm font-semibold text-emerald-100 hover:bg-emerald-500/20">
                                    Доставил
                                </button>
                            </form>
                        <?php endif; ?>

                        <?php if ($canManageDelivery && $courierStatus !== 'waiting_courier'): ?>
                            <form method="post">
                                <input type="hidden" name="csrf" value="<?= e((string)($_SESSION['csrf'] ?? '')) ?>">
                                <input type="hidden" name="order_id" value="<?= $orderId ?>">
                                <input type="hidden" name="courier_action" value="reset_waiting">
                                <button type="submit" class="w-full min-h-[42px] rounded-xl border border-amber-400/50 bg-amber-500/10 text-sm font-semibold text-amber-100 hover:bg-amber-500/20">
                                    Вернуть в ожидание
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</main>
<script>
(function () {
    var gpsStatusEl = document.getElementById('courier-gps-status');
    var canGeoTrack = <?= $staffRole === 'courier' ? 'true' : 'false' ?>;
    var minIntervalMs = 8000;
    var lastSentAtByOrder = {};

    function setGpsStatus(text, tone) {
        if (!gpsStatusEl) return;
        gpsStatusEl.textContent = text;
        gpsStatusEl.classList.remove('text-slate-500', 'text-emerald-300', 'text-amber-300', 'text-red-300');
        if (tone === 'ok') gpsStatusEl.classList.add('text-emerald-300');
        else if (tone === 'warn') gpsStatusEl.classList.add('text-amber-300');
        else if (tone === 'err') gpsStatusEl.classList.add('text-red-300');
        else gpsStatusEl.classList.add('text-slate-500');
    }

    function eligibleOrderCards() {
        return Array.prototype.slice.call(document.querySelectorAll('article[data-courier-track="1"][data-order-id]'));
    }

    function postLocation(orderId, coords) {
        var now = Date.now();
        var prevTs = Number(lastSentAtByOrder[orderId] || 0);
        if (prevTs > 0 && (now - prevTs) < minIntervalMs) return Promise.resolve(null);
        lastSentAtByOrder[orderId] = now;

        return fetch('/ajax/courier_location_update.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({
                order_id: Number(orderId),
                lat: Number(coords.latitude),
                lng: Number(coords.longitude),
                accuracy: Number(coords.accuracy || 0),
                speed: Number(coords.speed || 0),
                heading: Number(coords.heading || 0),
            })
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (!data || !data.success) return null;
            var card = document.querySelector('article[data-order-id="' + String(orderId) + '"]');
            if (card && data.location) {
                var lbl = card.querySelector('.js-location-state-label');
                var age = card.querySelector('.js-location-age-label');
                if (lbl) lbl.textContent = String(data.location.label || 'Геопозиция обновляется');
                if (age) age.textContent = String(data.location.last_update_human || '');
            }
            return data;
        })
        .catch(function () {
            return null;
        });
    }

    if (!canGeoTrack) {
        setGpsStatus('GPS: режим наблюдения доступен для роли courier', 'muted');
        return;
    }
    if (!navigator.geolocation || typeof navigator.geolocation.watchPosition !== 'function') {
        setGpsStatus('GPS: браузер не поддерживает геолокацию', 'warn');
        return;
    }

    var cards = eligibleOrderCards();
    if (!cards.length) {
        setGpsStatus('GPS: нет назначенных активных доставок', 'warn');
        return;
    }

    var watchId = navigator.geolocation.watchPosition(function (pos) {
        var coords = pos && pos.coords ? pos.coords : null;
        if (!coords) return;
        var activeCards = eligibleOrderCards();
        if (!activeCards.length) {
            setGpsStatus('GPS: нет активных назначенных заказов', 'warn');
            return;
        }

        setGpsStatus('GPS: позиция отправляется…', 'ok');
        Promise.all(activeCards.map(function (card) {
            return postLocation(card.getAttribute('data-order-id'), coords);
        })).then(function (results) {
            var ok = results.filter(Boolean).length;
            if (ok > 0) {
                setGpsStatus('GPS: позиция обновлена · ' + ok + ' заказ(ов)', 'ok');
            } else {
                setGpsStatus('GPS: ожидаем окно обновления', 'muted');
            }
        });
    }, function (err) {
        var msg = 'GPS: ошибка доступа к геопозиции';
        if (err && typeof err.code !== 'undefined') {
            if (err.code === 1) msg = 'GPS: доступ к геопозиции запрещён';
            else if (err.code === 2) msg = 'GPS: позиция недоступна';
            else if (err.code === 3) msg = 'GPS: таймаут геопозиции';
        }
        setGpsStatus(msg, 'err');
    }, {
        enableHighAccuracy: true,
        maximumAge: 5000,
        timeout: 10000
    });

    window.addEventListener('beforeunload', function () {
        if (typeof watchId === 'number' && navigator.geolocation && typeof navigator.geolocation.clearWatch === 'function') {
            navigator.geolocation.clearWatch(watchId);
        }
    });
})();
</script>
</body>
</html>
