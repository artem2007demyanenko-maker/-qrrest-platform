<?php

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/order_payment_runtime.php';
require_once __DIR__ . '/../../app/guest_order_loyalty_attach.php';
require_once __DIR__ . '/../../app/guest_loyalty.php';
require_once __DIR__ . '/../../app/runtime_schema_bootstrap.php';

require_login();
require_current_restaurant();
require_restaurant_role((int)($currentRestaurant['id'] ?? 0), ['owner', 'admin', 'staff']);

$pdo = db();
if (function_exists('runtime_schema_ensure_orders_order_type')) {
    runtime_schema_ensure_orders_order_type($pdo);
}
if (function_exists('runtime_schema_ensure_orders_courier_meta')) {
    runtime_schema_ensure_orders_courier_meta($pdo);
}
$errors = [];
$success = null;
$currentUser = auth_user();

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

function human_order_status(string $status): string {
    return [
        'new' => 'Новый',
        'accepted' => 'Принят',
        'cooking' => 'Готовится',
        'ready' => 'Готово',
        'delivered' => 'Отдано',
        'canceled' => 'Отменён',
    ][$status] ?? $status;
}

function human_payment_status(string $status): string {
    return [
        'pending' => 'Ожидание',
        'paid' => 'Оплачен',
        'unpaid' => 'Не оплачен',
        'canceled' => 'Отмена',
    ][$status] ?? $status;
}

function human_preorder_receive_type(string $type): string {
    return [
        'pickup' => 'Самовывоз',
        'delivery' => 'Доставка',
    ][strtolower(trim($type))] ?? '';
}

function normalize_order_status_for_db(string $status): string {
    $s = strtolower(trim($status));
    $map = [
        'created' => 'new',
        'in_progress' => 'cooking',
        'completed' => 'delivered',
        'cancelled' => 'canceled',
        'expired' => 'canceled',
    ];
    return $map[$s] ?? $s;
}

function normalize_payment_status_for_db(string $status): string {
    $s = strtolower(trim($status));
    $map = [
        'pending_payment' => 'pending',
        'cancelled' => 'canceled',
    ];
    return $map[$s] ?? $s;
}

function can_transition_order_status(string $from, string $to): bool {
    if ($from === $to) {
        return true;
    }
    $allowed = [
        'new' => ['accepted', 'cooking', 'canceled'],
        'accepted' => ['cooking', 'canceled'],
        'cooking' => ['ready', 'canceled'],
        'ready' => ['delivered', 'canceled'],
        'delivered' => [],
        'canceled' => [],
    ];
    return in_array($to, $allowed[$from] ?? [], true);
}

function orders_finalize_crm_visit_safe(int $restaurantId, int $orderId): void {
    if (is_demo_mode() || !file_exists(__DIR__ . '/../../app/crm_repo.php')) {
        return;
    }

    require_once __DIR__ . '/../../app/crm_repo.php';
    if (!function_exists('crm_finalize_order_visit')) {
        return;
    }

    try {
        crm_finalize_order_visit($restaurantId, $orderId);
    } catch (Throwable $e) {
        error_log('restaurant/orders crm_finalize_order_visit order_id=' . $orderId . ' ' . $e->getMessage());
    }
}

function orders_sync_loyalty_safe(PDO $pdo, array $restaurantRow, int $orderId): void {
    if (!function_exists('guest_order_loyalty_sync')) {
        return;
    }

    try {
        $res = guest_order_loyalty_sync($pdo, $restaurantRow, $orderId, null);
        if (!is_array($res) || empty($res['ok'])) {
            error_log('restaurant/orders loyalty_sync order_id=' . $orderId . ' error=' . (string)($res['error'] ?? 'unknown'));
        }
    } catch (Throwable $e) {
        error_log('restaurant/orders loyalty_sync order_id=' . $orderId . ' ' . $e->getMessage());
    }
}

function order_loyalty_manual_marker(array $ctx): array {
    $txCount = (int)($ctx['manual_tx_count'] ?? 0);
    $accrualCount = (int)($ctx['manual_accrual_count'] ?? 0);
    $spendCount = (int)($ctx['manual_spend_count'] ?? 0);

    if ($txCount <= 0) {
        return ['label' => '', 'class' => ''];
    }
    if ($txCount > 1 || ($accrualCount > 0 && $spendCount > 0)) {
        return [
            'label' => $txCount . ' ручн. операции',
            'class' => 'border-slate-600 bg-slate-800/80 text-slate-100',
        ];
    }
    if ($accrualCount > 0) {
        return [
            'label' => 'Ручное начисление',
            'class' => 'border-emerald-500/60 bg-emerald-500/10 text-emerald-100',
        ];
    }
    return [
        'label' => 'Ручное списание',
        'class' => 'border-amber-500/60 bg-amber-500/10 text-amber-100',
    ];
}

$allowedOrderStatuses = ['new', 'accepted', 'cooking', 'ready', 'delivered', 'canceled'];
$allowedPaymentStatuses = ['pending', 'paid', 'unpaid', 'canceled'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfOk = isset($_POST['csrf'], $_SESSION['csrf']) && hash_equals((string)$_SESSION['csrf'], (string)$_POST['csrf']);
    if (!$csrfOk) {
        $errors[] = 'Неверный запрос. Обновите страницу и попробуйте снова.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'set_status') {
            $orderId = (int)($_POST['order_id'] ?? 0);
            $newStatus = normalize_order_status_for_db((string)($_POST['order_status'] ?? ''));

            if ($orderId <= 0 || !in_array($newStatus, $allowedOrderStatuses, true)) {
                $errors[] = 'Некорректные данные статуса.';
            }

            if (!$errors) {
                $startedTx = false;
                try {
                    if (!$pdo->inTransaction()) {
                        $pdo->beginTransaction();
                        $startedTx = true;
                    }

                    order_expire_due_orders($pdo, (int)$currentRestaurant['id'], $orderId);

                    $cur = $pdo->prepare("SELECT order_status, payment_status FROM orders WHERE id = :id AND restaurant_id = :rest LIMIT 1");
                    $cur->execute(['id' => $orderId, 'rest' => $currentRestaurant['id']]);
                    $orderRow = $cur->fetch(PDO::FETCH_ASSOC) ?: null;
                    $oldStatus = (string)($orderRow['order_status'] ?? '');
                    if ($oldStatus === '') {
                        throw new RuntimeException('order_not_found');
                    }
                    if (normalize_order_status_for_db($oldStatus) === 'canceled' && $newStatus !== 'canceled') {
                        throw new RuntimeException('order_canceled');
                    }
                    if (!can_transition_order_status(normalize_order_status_for_db($oldStatus), $newStatus)) {
                        throw new RuntimeException('invalid_status_transition');
                    }

                    $stmt = $pdo->prepare("
                        UPDATE orders
                        SET order_status = :st
                        WHERE id = :id AND restaurant_id = :rest
                    ");
                    $stmt->execute([
                        'st' => $newStatus,
                        'id' => $orderId,
                        'rest' => $currentRestaurant['id'],
                    ]);

                    orders_sync_loyalty_safe($pdo, $currentRestaurant, $orderId);

                    if ($stmt->rowCount() > 0) {
                        orders_finalize_crm_visit_safe((int)$currentRestaurant['id'], $orderId);
                    }

                    if ($startedTx && $pdo->inTransaction()) {
                        $pdo->commit();
                    }
                    $success = $stmt->rowCount() > 0 ? 'Статус заказа обновлён.' : 'Изменений нет (идемпотентный повтор).';
                    log_action($currentUser['id'] ?? null, $currentRestaurant['id'], 'update_order_status', "Заказ #{$orderId} → {$newStatus}");
                } catch (Throwable $e) {
                    if ($startedTx && $pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    if ($e->getMessage() === 'order_not_found') {
                        $errors[] = 'Заказ не найден.';
                    } elseif ($e->getMessage() === 'order_canceled') {
                        $errors[] = 'Заказ уже отменён по таймауту.';
                    } elseif ($e->getMessage() === 'invalid_status_transition') {
                        $errors[] = 'Нелогичный переход статуса.';
                    } else {
                        $errors[] = 'Не удалось обновить статус заказа.';
                    }
                    error_log('restaurant/orders set_status order_id=' . $orderId . ' ' . $e->getMessage());
                }
            }
        }

        if ($action === 'set_payment_status') {
            $orderId = (int)($_POST['order_id'] ?? 0);
            $newPayStatus = normalize_payment_status_for_db((string)($_POST['payment_status'] ?? ''));

            if ($orderId <= 0 || !in_array($newPayStatus, $allowedPaymentStatuses, true)) {
                $errors[] = 'Некорректные данные оплаты.';
            }

            if (!$errors) {
                $startedTx = false;
                try {
                    if (!$pdo->inTransaction()) {
                        $pdo->beginTransaction();
                        $startedTx = true;
                    }

                    order_expire_due_orders($pdo, (int)$currentRestaurant['id'], $orderId);

                    $cur = $pdo->prepare("
                        SELECT order_status, payment_status
                        FROM orders
                        WHERE id = :id AND restaurant_id = :rest
                        LIMIT 1
                    ");
                    $cur->execute([
                        'id' => $orderId,
                        'rest' => $currentRestaurant['id'],
                    ]);
                    $orderRow = $cur->fetch(PDO::FETCH_ASSOC) ?: null;
                    if (!$orderRow) {
                        throw new RuntimeException('order_not_found');
                    }
                    if (normalize_order_status_for_db((string)($orderRow['order_status'] ?? '')) === 'canceled' && $newPayStatus !== 'canceled') {
                        throw new RuntimeException('order_canceled');
                    }

                    $stmt = $pdo->prepare("
                        UPDATE orders
                        SET payment_status = :ps
                        WHERE id = :id AND restaurant_id = :rest
                    ");
                    $stmt->execute([
                        'ps' => $newPayStatus,
                        'id' => $orderId,
                        'rest' => $currentRestaurant['id'],
                    ]);

                    if ($newPayStatus === 'paid') {
                        orders_sync_loyalty_safe($pdo, $currentRestaurant, $orderId);
                    }

                    if ($stmt->rowCount() > 0) {
                        orders_finalize_crm_visit_safe((int)$currentRestaurant['id'], $orderId);
                    }

                    if ($startedTx && $pdo->inTransaction()) {
                        $pdo->commit();
                    }
                    $success = $stmt->rowCount() > 0 ? 'Статус оплаты обновлён.' : 'Изменений нет (идемпотентный повтор).';
                    log_action($currentUser['id'] ?? null, $currentRestaurant['id'], 'update_payment_status', "Заказ #{$orderId} оплата → {$newPayStatus}");
                } catch (Throwable $e) {
                    if ($startedTx && $pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    if ($e->getMessage() === 'order_not_found') {
                        $errors[] = 'Заказ не найден.';
                    } elseif ($e->getMessage() === 'order_canceled') {
                        $errors[] = 'Нельзя изменить оплату у отменённого заказа.';
                    } else {
                        $errors[] = 'Не удалось обновить статус оплаты.';
                    }
                    error_log('restaurant/orders set_payment_status order_id=' . $orderId . ' ' . $e->getMessage());
                }
            }
        }
    }
}

$filterStatus = $_GET['status'] ?? 'active';
$filterType = isset($_GET['order_type']) ? strtolower(trim((string)$_GET['order_type'])) : 'all';
$allowedOrderTypes = ['hall', 'delivery', 'pickup', 'preorder', 'manual'];
$filterType = in_array($filterType, array_merge(['all'], $allowedOrderTypes), true) ? $filterType : 'all';
$hasOrderTypeCol = function_exists('db_column_exists') && db_column_exists('orders', 'order_type');
$where = "o.restaurant_id = :rest";
$params = ['rest' => $currentRestaurant['id']];

if ($filterStatus === 'active') {
    $where .= " AND o.order_status IN ('new','accepted','cooking','ready')";
} elseif (in_array($filterStatus, $allowedOrderStatuses, true)) {
    $where .= " AND o.order_status = :st";
    $params['st'] = $filterStatus;
}

if ($filterType !== 'all' && in_array($filterType, $allowedOrderTypes, true)) {
    if ($hasOrderTypeCol) {
        if ($filterType === 'hall') {
            $where .= " AND (LOWER(TRIM(COALESCE(o.order_type, ''))) IN ('', 'hall'))";
        } else {
            $where .= " AND LOWER(TRIM(COALESCE(o.order_type, ''))) = :order_type";
            $params['order_type'] = $filterType;
        }
    } elseif ($filterType !== 'hall') {
        $where .= " AND 1 = 0";
    }
}

order_expire_due_orders($pdo, (int)$currentRestaurant['id']);

$orderTypeSql = $hasOrderTypeCol
    ? "CASE WHEN o.order_type IS NULL OR TRIM(o.order_type) = '' THEN 'hall' ELSE LOWER(TRIM(o.order_type)) END AS order_type"
    : "'hall' AS order_type";

$stmt = $pdo->prepare("
    SELECT o.*, t.name AS table_name, {$orderTypeSql}
    FROM orders o
    LEFT JOIN tables t ON t.id = o.table_id AND t.restaurant_id = o.restaurant_id
    WHERE {$where}
    ORDER BY o.created_at DESC
    LIMIT 100
");
$stmt->execute($params);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
$orderCommentCol = null;
foreach (['comment', 'notes', 'note'] as $candidateCol) {
    if (function_exists('db_column_exists') && db_column_exists('orders', $candidateCol)) {
        $orderCommentCol = $candidateCol;
        break;
    }
}
$hasCourierStatusCol = function_exists('db_column_exists') && db_column_exists('orders', 'courier_status');
$hasCourierUserIdCol = function_exists('db_column_exists') && db_column_exists('orders', 'courier_user_id');
$orderIds = array_values(array_filter(array_map('intval', array_column($orders, 'id'))));
$itemsByOrder = [];
if ($orderIds !== []) {
    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
    $stmtItems = $pdo->prepare("
        SELECT oi.order_id, oi.quantity, oi.price, mi.name AS menu_name
        FROM order_items oi
        LEFT JOIN menu_items mi ON mi.id = oi.menu_item_id
        WHERE oi.order_id IN ($placeholders)
        ORDER BY oi.id ASC
    ");
    $stmtItems->execute($orderIds);
    foreach ($stmtItems->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $oid = (int)($row['order_id'] ?? 0);
        if ($oid <= 0) {
            continue;
        }
        $itemsByOrder[$oid][] = [
            'menu_name' => (string)($row['menu_name'] ?? ''),
            'quantity' => (int)($row['quantity'] ?? 0),
            'price' => (float)($row['price'] ?? 0),
        ];
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
    $stmtGuests = $pdo->prepare("SELECT id, name, phone FROM guests WHERE id IN ($phGuest)");
    $stmtGuests->execute($guestIds);
    foreach ($stmtGuests->fetchAll(PDO::FETCH_ASSOC) as $grow) {
        $guestById[(int)($grow['id'] ?? 0)] = [
            'name' => (string)($grow['name'] ?? ''),
            'phone' => (string)($grow['phone'] ?? ''),
        ];
    }
}
$courierUserById = [];
if ($hasCourierUserIdCol) {
    $courierUserIds = [];
    foreach ($orders as $o) {
        $cid = (int)($o['courier_user_id'] ?? 0);
        if ($cid > 0) {
            $courierUserIds[] = $cid;
        }
    }
    $courierUserIds = array_values(array_unique($courierUserIds));
    if ($courierUserIds !== []) {
        $phCourier = implode(',', array_fill(0, count($courierUserIds), '?'));
        $stmtCourierUsers = $pdo->prepare("SELECT id, name FROM users WHERE id IN ($phCourier)");
        $stmtCourierUsers->execute($courierUserIds);
        foreach ($stmtCourierUsers->fetchAll(PDO::FETCH_ASSOC) as $urow) {
            $uid = (int)($urow['id'] ?? 0);
            if ($uid > 0) {
                $courierUserById[$uid] = trim((string)($urow['name'] ?? ''));
            }
        }
    }
}
$loyaltyContextByOrder = function_exists('guest_loyalty_order_context_map')
    ? guest_loyalty_order_context_map($pdo, (int)$currentRestaurant['id'], $orders)
    : [];
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Заказы — <?= e($currentRestaurant['name']) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?= brand_head_tags() ?>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-950 text-slate-50 flex">
<?php
$restaurantSidebarActive = 'orders';
$restaurantSidebarName = (string)($currentRestaurant['name'] ?? 'Ресторан');
require __DIR__ . '/_sidebar_mobile.php';
require __DIR__ . '/_sidebar.php';
?>

<main class="flex-1 p-4">
    <div class="max-w-6xl mx-auto space-y-4">
        <?php
        $cabinetQuickNavActive = 'orders';
        $operationalNavActive = 'orders';
        require __DIR__ . '/_restaurant_cabinet_context.php';
        require __DIR__ . '/_restaurant_cabinet_quick_nav.php';
        require __DIR__ . '/_restaurant_operational_nav.php';
        ?>
        <div class="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-start sm:justify-between border-b border-slate-800/80 pb-4">
            <header class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-wider text-emerald-500/85 mb-1">Продажи</p>
                <h1 class="text-2xl font-bold text-slate-50 mb-1">Заказы</h1>
                <div class="text-xs text-slate-500">Последние 100 заказов этого ресторана</div>
            </header>
            <div class="flex flex-wrap gap-2 text-xs">
                <?php
                $filters = [
                    'active' => 'Активные',
                    'all' => 'Все',
                    'new' => 'Новые',
                    'cooking' => 'Готовятся',
                    'ready' => 'Готовы',
                    'delivered' => 'Отданы',
                    'canceled' => 'Отменённые',
                ];
                foreach ($filters as $key => $label):
                    $active = ($filterStatus === $key);
                ?>
                    <a
                        href="/restaurant/orders.php?status=<?= urlencode($key) ?>&order_type=<?= urlencode($filterType) ?>"
                        class="px-3 py-1.5 rounded-xl border text-xs <?= $active
                            ? 'border-emerald-500 bg-emerald-500/10 text-emerald-100'
                            : 'border-slate-700 bg-slate-900/60 text-slate-200 hover:bg-slate-800/80' ?>"
                    >
                        <?= e($label) ?>
                    </a>
                <?php endforeach; ?>
            </div>
            <div class="flex flex-wrap gap-2 text-xs">
                <?php
                $typeFilters = [
                    'all' => 'Все типы',
                    'hall' => 'Зал',
                    'delivery' => 'Доставка',
                    'pickup' => 'Самовывоз',
                    'preorder' => 'Предзаказ',
                    'manual' => 'Ручные',
                ];
                foreach ($typeFilters as $key => $label):
                    $active = ($filterType === $key);
                ?>
                    <a
                        href="/restaurant/orders.php?status=<?= urlencode($filterStatus) ?>&order_type=<?= urlencode($key) ?>"
                        class="px-3 py-1.5 rounded-xl border text-xs <?= $active
                            ? 'border-cyan-400 bg-cyan-500/15 text-cyan-100'
                            : 'border-slate-700 bg-slate-900/60 text-slate-200 hover:bg-slate-800/80' ?>"
                    >
                        <?= e($label) ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <?php if ($success): ?>
            <div class="rounded-2xl bg-emerald-500/10 border border-emerald-500/50 px-4 py-3 text-sm text-emerald-100">
                <?= e($success) ?>
            </div>
        <?php endif; ?>

        <?php if ($errors): ?>
            <div class="rounded-2xl bg-red-500/10 border border-red-500/50 px-4 py-3 text-sm text-red-100 space-y-1">
                <?php foreach ($errors as $err): ?>
                    <div><?= e($err) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="bg-slate-900/80 border border-slate-800 rounded-3xl p-4">
            <?php if (!$orders): ?>
                <div class="rounded-xl border border-gray-800 bg-[#121826] p-8 text-center space-y-4">
                    <div class="inline-flex items-center justify-center w-14 h-14 rounded-2xl bg-slate-800 text-slate-400">
                        <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/></svg>
                    </div>
                    <div>
                        <h3 class="text-lg font-semibold text-[#F3F4F6]">No orders yet</h3>
                        <p class="text-sm text-gray-400 mt-1">Orders from the QR menu will appear here. Try placing a test order to see the flow.</p>
                    </div>
                    <?php
                    $config = require __DIR__ . '/../../app/config.php';
                    $mainDomain = $config['app']['main_domain'] ?? 'lvh.me';
                    $protocol = $config['app']['protocol'] ?? 'http';
                    $baseUrl = $protocol . '://' . ($currentRestaurant['subdomain'] ?? '') . '.' . $mainDomain;
                    ?>
                    <a
                        href="<?= e($baseUrl) ?>/qr.php?table_id=1"
                        target="_blank"
                        rel="noopener"
                        class="inline-flex items-center px-4 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-medium"
                    >
                        Open QR Menu
                    </a>
                </div>
            <?php else: ?>
                <div class="space-y-2 text-xs">
                    <?php foreach ($orders as $o): ?>
                        <?php
                        $orderId = (int)($o['id'] ?? 0);
                        $loyaltyCtx = $loyaltyContextByOrder[$orderId] ?? [
                            'guest_id' => 0,
                            'guest_card_id' => 0,
                            'loyalty_phone' => '',
                            'has_link' => false,
                            'has_card' => false,
                            'balance' => 0,
                            'manual_tx_count' => 0,
                            'manual_accrual_count' => 0,
                            'manual_spend_count' => 0,
                        ];
                        $manualMarker = order_loyalty_manual_marker($loyaltyCtx);
                        $loyaltyHasCard = !empty($loyaltyCtx['has_card']);
                        $loyaltyHasLink = !empty($loyaltyCtx['has_link']);
                        $loyaltyBalance = (int)($loyaltyCtx['balance'] ?? 0);
                        $orderSrcLabel = function_exists('qr_public_owner_order_table_label')
                            ? qr_public_owner_order_table_label((string)($o['table_name'] ?? ''))
                            : (string)($o['table_name'] ?? '');
                        $orderTypeValue = function_exists('order_type_normalize')
                            ? order_type_normalize((string)($o['order_type'] ?? 'hall'), (int)($o['table_id'] ?? 0))
                            : 'hall';
                        $orderTypeLabelValue = function_exists('order_type_label')
                            ? order_type_label((string)($o['order_type'] ?? 'hall'), (int)($o['table_id'] ?? 0))
                            : 'Зал';
                        $sourceLabelValue = function_exists('order_source_label')
                            ? order_source_label((string)($o['order_type'] ?? 'hall'), (int)($o['table_id'] ?? 0), $orderSrcLabel)
                            : (($orderTypeValue === 'hall') ? 'QR / Зал' : $orderTypeLabelValue);
                        $guestId = (int)($o['guest_id'] ?? 0);
                        $guestName = trim((string)($guestById[$guestId]['name'] ?? ''));
                        $guestPhone = trim((string)($guestById[$guestId]['phone'] ?? ($loyaltyCtx['loyalty_phone'] ?? '')));
                        $customerNameRaw = trim((string)($o['customer_name'] ?? ''));
                        $customerPhoneRaw = trim((string)($o['customer_phone'] ?? ''));
                        $deliveryFullName = trim((string)($o['delivery_full_name'] ?? ''));
                        $deliveryPhone = trim((string)($o['delivery_phone'] ?? ''));
                        $deliveryAddress = trim((string)($o['delivery_address'] ?? ''));
                        $scheduledForRaw = trim((string)($o['scheduled_for'] ?? ''));
                        $preorderReceiveType = strtolower(trim((string)($o['preorder_receive_type'] ?? '')));
                        if (!in_array($preorderReceiveType, ['pickup', 'delivery'], true)) {
                            $preorderReceiveType = '';
                        }
                        $preorderReceiveTypeLabel = human_preorder_receive_type($preorderReceiveType);
                        $customerNameDisplay = $customerNameRaw !== '' ? $customerNameRaw : ($deliveryFullName !== '' ? $deliveryFullName : $guestName);
                        $customerPhoneDisplay = $customerPhoneRaw !== '' ? $customerPhoneRaw : ($deliveryPhone !== '' ? $deliveryPhone : $guestPhone);
                        $scheduledForDisplay = '';
                        if ($scheduledForRaw !== '') {
                            $scheduledTs = strtotime($scheduledForRaw);
                            $scheduledForDisplay = $scheduledTs !== false ? date('d.m H:i', $scheduledTs) : $scheduledForRaw;
                        }
                        $courierUserId = $hasCourierUserIdCol ? (int)($o['courier_user_id'] ?? 0) : 0;
                        $courierStatusRaw = $hasCourierStatusCol ? trim((string)($o['courier_status'] ?? '')) : '';
                        $courierStatus = function_exists('courier_status_normalize')
                            ? courier_status_normalize($courierStatusRaw, $orderTypeValue)
                            : (($orderTypeValue === 'delivery') ? ($courierStatusRaw !== '' ? strtolower($courierStatusRaw) : 'waiting_courier') : '');
                        if ($orderTypeValue !== 'delivery') {
                            $courierStatus = '';
                        }
                        $courierStatusLabel = function_exists('courier_status_label')
                            ? courier_status_label($courierStatus, $orderTypeValue)
                            : ($courierStatus === '' ? '' : $courierStatus);
                        $courierUserName = $courierUserId > 0 ? trim((string)($courierUserById[$courierUserId] ?? '')) : '';
                        $fulfillmentLines = [];
                        if ($orderTypeValue === 'delivery') {
                            $deliveryContact = [];
                            if ($customerNameDisplay !== '') {
                                $deliveryContact[] = $customerNameDisplay;
                            }
                            if ($customerPhoneDisplay !== '') {
                                $deliveryContact[] = $customerPhoneDisplay;
                            }
                            if ($deliveryContact !== []) {
                                $fulfillmentLines[] = 'Получатель: ' . implode(' · ', $deliveryContact);
                            }
                            if ($deliveryAddress !== '') {
                                $fulfillmentLines[] = 'Адрес: ' . $deliveryAddress;
                            }
                        } elseif ($orderTypeValue === 'pickup') {
                            $pickupContact = [];
                            if ($customerNameDisplay !== '') {
                                $pickupContact[] = $customerNameDisplay;
                            }
                            if ($customerPhoneDisplay !== '') {
                                $pickupContact[] = $customerPhoneDisplay;
                            }
                            if ($pickupContact !== []) {
                                $fulfillmentLines[] = 'Самовывоз: ' . implode(' · ', $pickupContact);
                            }
                        } elseif ($orderTypeValue === 'preorder') {
                            if ($scheduledForDisplay !== '') {
                                $fulfillmentLines[] = 'Предзаказ на: ' . $scheduledForDisplay;
                            }
                            if ($preorderReceiveTypeLabel !== '') {
                                $fulfillmentLines[] = 'Формат: ' . $preorderReceiveTypeLabel;
                            }
                            $preorderContact = [];
                            if ($customerNameDisplay !== '') {
                                $preorderContact[] = $customerNameDisplay;
                            }
                            if ($customerPhoneDisplay !== '') {
                                $preorderContact[] = $customerPhoneDisplay;
                            }
                            if ($preorderContact !== []) {
                                $fulfillmentLines[] = 'Контакт: ' . implode(' · ', $preorderContact);
                            }
                            if ($preorderReceiveType === 'delivery' && $deliveryAddress !== '') {
                                $fulfillmentLines[] = 'Адрес: ' . $deliveryAddress;
                            }
                        } elseif ($orderTypeValue === 'manual') {
                            $manualContact = [];
                            if ($customerNameDisplay !== '') {
                                $manualContact[] = $customerNameDisplay;
                            }
                            if ($customerPhoneDisplay !== '') {
                                $manualContact[] = $customerPhoneDisplay;
                            }
                            if ($manualContact !== []) {
                                $fulfillmentLines[] = 'Контакт: ' . implode(' · ', $manualContact);
                            }
                        }
                        if ($orderTypeValue === 'delivery' && $courierStatusLabel !== '') {
                            $courierLine = 'Курьер: ' . $courierStatusLabel;
                            if ($courierUserName !== '') {
                                $courierLine .= ' · ' . $courierUserName;
                            }
                            $fulfillmentLines[] = $courierLine;
                        }
                        $orderComment = $orderCommentCol !== null ? trim((string)($o[$orderCommentCol] ?? '')) : '';
                        $orderItems = $itemsByOrder[$orderId] ?? [];
                        $orderItemsCount = count($orderItems);
                        ?>
                        <div class="bg-slate-950/80 border border-slate-800 rounded-2xl px-3 py-3 flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-[240px]">
                                <div class="text-slate-100">
                                    Заказ #<?= $orderId ?>
                                    <span class="text-slate-500">•</span>
                                    <span class="text-slate-400"><?= date('d.m H:i', strtotime((string)$o['created_at'])) ?></span>
                                </div>
                                <div class="text-[11px] text-slate-500 mt-0.5">
                                    Тип заказа:
                                    <span class="text-cyan-200"><?= e($orderTypeLabelValue) ?></span>
                                </div>
                                <div class="text-[11px] text-slate-500 mt-0.5">
                                    Источник:
                                    <span class="text-sky-200"><?= e($sourceLabelValue) ?></span>
                                </div>
                                <?php if ($orderSrcLabel !== ''): ?>
                                    <div class="text-[11px] text-slate-500 mt-0.5">
                                        Стол:
                                        <span class="text-slate-200"><?= e($orderSrcLabel) ?></span>
                                    </div>
                                <?php endif; ?>
                                <?php if ($guestName !== '' || $guestPhone !== ''): ?>
                                    <div class="text-[11px] text-slate-500 mt-0.5">
                                        Гость:
                                        <span class="text-slate-200"><?= e(trim($guestName . ($guestName !== '' && $guestPhone !== '' ? ' · ' : '') . $guestPhone)) ?></span>
                                    </div>
                                <?php endif; ?>
                                <?php if ($fulfillmentLines !== []): ?>
                                    <div class="mt-1 space-y-0.5">
                                        <?php foreach ($fulfillmentLines as $line): ?>
                                            <div class="text-[11px] text-slate-500">
                                                <span class="text-slate-200"><?= e($line) ?></span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                                <?php if ($orderComment !== ''): ?>
                                    <div class="mt-2 rounded-xl border border-slate-800/80 bg-slate-900/40 px-2.5 py-2 text-[11px] text-slate-300">
                                        <span class="text-slate-500">Комментарий:</span> <?= e($orderComment) ?>
                                    </div>
                                <?php endif; ?>
                                <div class="text-[11px] text-slate-500 mt-1">
                                    Позиций: <span class="text-slate-200"><?= $orderItemsCount ?></span>
                                </div>
                                <div class="text-[11px] text-slate-500 mt-0.5">
                                    Способ оплаты:
                                    <span class="text-slate-300"><?= e((string)($o['payment_type'] ?? '')) ?></span>
                                </div>
                                <div class="mt-2 flex flex-wrap items-center gap-1.5">
                                    <?php if ($loyaltyHasCard): ?>
                                        <span class="inline-flex items-center gap-1 rounded-full border border-emerald-500/60 bg-emerald-500/10 px-2.5 py-1 text-[11px] text-emerald-100">
                                            Карта есть
                                        </span>
                                    <?php elseif ($loyaltyHasLink): ?>
                                        <span class="inline-flex items-center gap-1 rounded-full border border-amber-500/60 bg-amber-500/10 px-2.5 py-1 text-[11px] text-amber-100">
                                            Карты нет
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center gap-1 rounded-full border border-slate-700 bg-slate-900/80 px-2.5 py-1 text-[11px] text-slate-300">
                                            Лояльность не привязана
                                        </span>
                                    <?php endif; ?>

                                    <?php if ($loyaltyHasCard || (int)($loyaltyCtx['guest_id'] ?? 0) > 0): ?>
                                        <span class="inline-flex items-center gap-1 rounded-full border border-sky-500/60 bg-sky-500/10 px-2.5 py-1 text-[11px] text-sky-100">
                                            Баланс <?= number_format($loyaltyBalance, 0, '.', ' ') ?> бонусов
                                        </span>
                                    <?php endif; ?>

                                    <?php if ($manualMarker['label'] !== ''): ?>
                                        <span class="inline-flex items-center gap-1 rounded-full border px-2.5 py-1 text-[11px] <?= e($manualMarker['class']) ?>">
                                            <?= e($manualMarker['label']) ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php if ($orderItemsCount > 0): ?>
                                <div class="w-full rounded-xl border border-slate-800/70 bg-slate-900/35 p-2.5 text-[11px] text-slate-300 space-y-1">
                                    <?php foreach ($orderItems as $it): ?>
                                        <div class="flex items-start justify-between gap-2">
                                            <span class="truncate"><?= e((string)($it['menu_name'] ?? 'Позиция')) ?> × <?= (int)($it['quantity'] ?? 0) ?></span>
                                            <span class="text-slate-400 whitespace-nowrap"><?= number_format(((int)($it['quantity'] ?? 0)) * ((float)($it['price'] ?? 0)), 0, '.', ' ') ?> ₽</span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <div class="flex flex-wrap items-center gap-3">
                                <div class="text-right">
                                    <div class="text-sm font-semibold text-emerald-400">
                                        <?= number_format((float)$o['total_price'], 0, '.', ' ') ?> ₽
                                    </div>
                                    <?php if ((int)($o['loyalty_points_spent'] ?? 0) > 0): ?>
                                        <div class="text-[11px] text-slate-500 mt-0.5">
                                            из <?= number_format((float)$o['total_price'] + (int)$o['loyalty_points_spent'], 0, '.', ' ') ?> ₽
                                            · −<?= number_format((int)$o['loyalty_points_spent'], 0, '.', ' ') ?> бонусов
                                        </div>
                                    <?php endif; ?>
                                    <div class="text-[11px] text-slate-500 mt-0.5">
                                        Оплата:
                                        <span class="text-slate-200"><?= human_payment_status((string)($o['payment_status'] ?? '')) ?></span>
                                    </div>
                                    <div class="text-[11px] text-slate-500 mt-0.5">
                                        Статус:
                                        <span class="text-slate-200"><?= human_order_status((string)($o['order_status'] ?? '')) ?></span>
                                    </div>
                                </div>
                            </div>

                            <div class="flex flex-col gap-2">
                                <form method="post" class="flex items-center gap-1">
                                    <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf'] ?? '') ?>">
                                    <input type="hidden" name="action" value="set_status">
                                    <input type="hidden" name="order_id" value="<?= $orderId ?>">
                                    <select name="order_status" class="rounded-lg bg-slate-950/80 border border-slate-700 px-2 py-1 text-[11px]">
                                        <?php foreach ($allowedOrderStatuses as $st): ?>
                                            <option value="<?= e($st) ?>" <?= ($o['order_status'] ?? '') === $st ? 'selected' : '' ?>>
                                                <?= human_order_status($st) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit" class="px-3 py-1 rounded-xl bg-slate-800 hover:bg-slate-700 text-[11px] text-slate-100">OK</button>
                                </form>
                                <form method="post" class="flex items-center gap-1">
                                    <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf'] ?? '') ?>">
                                    <input type="hidden" name="action" value="set_payment_status">
                                    <input type="hidden" name="order_id" value="<?= $orderId ?>">
                                    <select name="payment_status" class="rounded-lg bg-slate-950/80 border border-slate-700 px-2 py-1 text-[11px]">
                                        <?php foreach ($allowedPaymentStatuses as $ps): ?>
                                            <option value="<?= e($ps) ?>" <?= ($o['payment_status'] ?? '') === $ps ? 'selected' : '' ?>>
                                                <?= human_payment_status($ps) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit" class="px-3 py-1 rounded-xl bg-slate-800 hover:bg-slate-700 text-[11px] text-slate-100">OK</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</main>
</body>
</html>
