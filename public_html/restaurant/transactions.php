<?php

require_once __DIR__ . '/../../app/bootstrap.php';

require_login();
require_current_restaurant();
require_restaurant_role((int)($currentRestaurant['id'] ?? 0), ['owner', 'admin']);

$user = auth_user();
$pdo  = db();


$restaurantId   = (int)$currentRestaurant['id'];

$dateFrom       = $_GET['date_from'] ?? '';
$dateTo         = $_GET['date_to'] ?? '';
$paymentStatus  = $_GET['payment_status'] ?? 'all';
$paymentType    = $_GET['payment_type'] ?? 'all';
$export         = $_GET['export'] ?? '';

if ($dateFrom === '' && $dateTo === '') {
    $dateFrom = date('Y-m-d', strtotime('-7 days'));
    $dateTo   = date('Y-m-d');
}


$sql = "
    SELECT 
        o.*,
        t.name AS table_name
    FROM orders o
    LEFT JOIN tables t ON t.id = o.table_id
    WHERE o.restaurant_id = :rest
";
$params = [
    ':rest' => $restaurantId,
];

if ($dateFrom !== '') {
    $sql .= " AND o.created_at >= :date_from";
    $params[':date_from'] = $dateFrom . ' 00:00:00';
}
if ($dateTo !== '') {
    $sql .= " AND o.created_at <= :date_to";
    $params[':date_to'] = $dateTo . ' 23:59:59';
}

if ($paymentStatus !== 'all') {
    $sql .= " AND o.payment_status = :pstatus";
    $params[':pstatus'] = $paymentStatus;
}
if ($paymentType !== 'all') {
    $sql .= " AND o.payment_type = :ptype";
    $params[':ptype'] = $paymentType;
}

$sql .= " ORDER BY o.created_at DESC LIMIT 500";


function human_payment_type(string $type): string {
    if ($type === 'card_later') return 'Картой';
    if ($type === 'cash')       return 'Наличными';
    return $type;
}

function human_payment_status(string $status): string {
    if ($status === 'paid')     return 'Оплачен';
    if ($status === 'unpaid')   return 'Не оплачен';
    if ($status === 'pending')  return 'Ожидание';
    if ($status === 'canceled') return 'Отмена';
    return $status;
}

function human_order_status(string $status): string {
    if ($status === 'new')       return 'Новый';
    if ($status === 'accepted')  return 'Принят';
    if ($status === 'cooking')   return 'Готовится';
    if ($status === 'ready')     return 'Готово';
    if ($status === 'delivered') return 'Отдано';
    if ($status === 'canceled')  return 'Отменён';
    return $status;
}

function badge_payment_status_class(string $status): string {
    if ($status === 'paid')     return 'bg-emerald-500/10 text-emerald-200 border-emerald-400/70';
    if ($status === 'unpaid')   return 'bg-red-500/10 text-red-200 border-red-400/70';
    if ($status === 'pending')  return 'bg-amber-500/10 text-amber-200 border-amber-400/70';
    if ($status === 'canceled') return 'bg-slate-800/80 text-slate-200 border-slate-600/80';
    return 'bg-slate-800 text-slate-100 border-slate-700';
}

function badge_payment_type_class(string $type): string {
    if ($type === 'card_later') return 'bg-violet-500/10 text-violet-200 border-violet-400/70';
    if ($type === 'cash')       return 'bg-amber-500/10 text-amber-200 border-amber-400/70';
    return 'bg-slate-800 text-slate-100 border-slate-700';
}


if ($export === 'csv') {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $filename = 'transactions_' . $dateFrom . '_' . $dateTo . '.csv';

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');


    echo "\xEF\xBB\xBF";

    $out = fopen('php://output', 'w');


    fputcsv($out, [
        'ID заказа',
        'Дата/время',
        'Стол',
        'Сумма, руб',
        'Тип оплаты',
        'Статус оплаты',
        'Статус заказа',
    ], ';');

    foreach ($orders as $o) {
        fputcsv($out, [
            $o['id'],
            $o['created_at'],
            $o['table_name'] ?? '',
            (float)$o['total_price'],
            human_payment_type($o['payment_type'] ?? ''),
            human_payment_status($o['payment_status'] ?? ''),
            human_order_status($o['order_status'] ?? ''),
        ], ';');
    }

    fclose($out);
    exit;
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalOrders       = count($orders);
$totalPaidAmount   = 0.0;
$totalUnpaidCount  = 0;
$totalPendingCount = 0;
$sumForAverage     = 0.0;

foreach ($orders as $o) {
    $price = (float)$o['total_price'];

    if (($o['payment_status'] ?? '') === 'paid') {
        $totalPaidAmount += $price;
    } elseif (($o['payment_status'] ?? '') === 'unpaid') {
        $totalUnpaidCount++;
    } elseif (($o['payment_status'] ?? '') === 'pending') {
        $totalPendingCount++;
    }

    $sumForAverage += $price;
}

$avgCheck = $totalOrders > 0 ? ($sumForAverage / $totalOrders) : 0.0;


$exportParams = $_GET;
$exportParams['export'] = 'csv';
$exportUrl = '?' . http_build_query($exportParams);
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Транзакции — <?= e($currentRestaurant['name']) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?= brand_head_tags() ?>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-950 text-slate-50">
<div class="min-h-screen relative overflow-hidden">
    <!-- фон -->
    <div class="pointer-events-none absolute inset-0">
        <div class="absolute -top-32 -left-24 w-72 h-72 bg-emerald-500/10 blur-3xl rounded-full"></div>
        <div class="absolute -bottom-40 right-0 w-80 h-80 bg-sky-500/10 blur-3xl rounded-full"></div>
    </div>

    <div class="relative z-10 flex flex-col min-h-screen">
        <!-- header -->
        <header class="border-b border-slate-800/70 bg-slate-950/90 backdrop-blur-xl">
            <div class="max-w-6xl mx-auto px-4 py-3 flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                <div>
                    <div class="inline-flex items-center gap-2 rounded-full bg-sky-500/10 border border-sky-500/40 px-3 py-1 mb-1">
                        <span class="w-1.5 h-1.5 rounded-full bg-sky-400 animate-pulse"></span>
                        <span class="text-[11px] text-sky-100">
                            Финансовая статистика ресторана
                        </span>
                    </div>
                    <div class="flex items-baseline gap-2">
                        <h1 class="text-xl md:text-2xl font-semibold leading-tight">
                            Транзакции
                        </h1>
                        <span class="text-[11px] text-slate-400 uppercase tracking-[0.2em]">
                            <?= e($currentRestaurant['name']) ?>
                        </span>
                    </div>
                    <div class="mt-1 text-[11px] text-slate-400">
                        Владелец/админ: <span class="font-medium text-slate-100"><?= e($user['name']) ?></span>
                    </div>
                </div>

                <div class="flex flex-col items-start md:items-end gap-2">
                    <div class="text-[11px] text-slate-400 md:text-right">
                        Показываются заказы из таблицы <span class="font-mono text-slate-200">orders</span>.<br>
                        Интервал по дате можно менять в фильтрах.
                    </div>
                    <a href="<?= e($exportUrl) ?>"
                       class="inline-flex items-center gap-2 px-3 py-1.5 rounded-xl bg-emerald-500 hover:bg-emerald-400 text-xs font-semibold text-slate-950 shadow-sm shadow-emerald-500/40">
                        <span class="text-lg leading-none">⬇</span>
                        <span>Экспорт в CSV</span>
                    </a>
                </div>
            </div>
        </header>

        <!-- content -->
        <main class="flex-1">
            <div class="max-w-6xl mx-auto px-3 md:px-4 py-4 space-y-4">

                <!-- summary -->
                <section
                    class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 rounded-3xl bg-slate-950/80 border border-slate-800/80 p-3 md:p-4 shadow-xl shadow-slate-950/60">
                    <div class="flex flex-col justify-between rounded-2xl bg-slate-900/60 border border-slate-700/80 px-3 py-3">
                        <div class="text-[11px] text-slate-400">Заказы в выборке</div>
                        <div class="mt-1 text-2xl font-semibold text-slate-50">
                            <?= (int)$totalOrders ?>
                        </div>
                    </div>
                    <div class="flex flex-col justify-between rounded-2xl bg-emerald-500/10 border border-emerald-500/60 px-3 py-3">
                        <div class="text-[11px] text-emerald-100">Сумма оплаченных</div>
                        <div class="mt-1 text-2xl font-semibold text-emerald-300">
                            <?= number_format($totalPaidAmount, 0, '.', ' ') ?> ₽
                        </div>
                    </div>
                    <div class="flex flex-col justify-between rounded-2xl bg-red-500/10 border border-red-500/60 px-3 py-3">
                        <div class="text-[11px] text-red-100">Неоплаченные заказы</div>
                        <div class="mt-1 text-2xl font-semibold text-red-200">
                            <?= (int)$totalUnpaidCount ?>
                        </div>
                    </div>
                    <div class="flex flex-col justify-between rounded-2xl bg-slate-900/60 border border-slate-700/80 px-3 py-3">
                        <div class="text-[11px] text-slate-400">Средний чек</div>
                        <div class="mt-1 text-2xl font-semibold text-slate-50">
                            <?= number_format($avgCheck, 0, '.', ' ') ?> ₽
                        </div>
                    </div>
                </section>

                <!-- filters -->
                <section
                    class="rounded-3xl bg-slate-950/80 border border-slate-800/80 p-3 md:p-4 shadow-lg shadow-slate-950/60">
                    <form method="get" class="grid grid-cols-1 md:grid-cols-4 gap-3 items-end">
                        <div>
                            <label class="block text-[11px] text-slate-400 mb-1">Дата с</label>
                            <input type="date" name="date_from"
                                   value="<?= e($dateFrom) ?>"
                                   class="w-full rounded-xl bg-slate-900 border border-slate-700 px-3 py-2 text-sm text-slate-50 focus:outline-none focus:ring-2 focus:ring-sky-500 focus:border-sky-500">
                        </div>
                        <div>
                            <label class="block text-[11px] text-slate-400 mb-1">Дата по</label>
                            <input type="date" name="date_to"
                                   value="<?= e($dateTo) ?>"
                                   class="w-full rounded-xl bg-slate-900 border border-slate-700 px-3 py-2 text-sm text-slate-50 focus:outline-none focus:ring-2 focus:ring-sky-500 focus:border-sky-500">
                        </div>
                        <div>
                            <label class="block text-[11px] text-slate-400 mb-1">Статус оплаты</label>
                            <select name="payment_status"
                                    class="w-full rounded-xl bg-slate-900 border border-slate-700 px-3 py-2 text-sm text-slate-50 focus:outline-none focus:ring-2 focus:ring-sky-500 focus:border-sky-500">
                                <option value="all"     <?= $paymentStatus === 'all' ? 'selected' : '' ?>>Все</option>
                                <option value="paid"    <?= $paymentStatus === 'paid' ? 'selected' : '' ?>>Оплачены</option>
                                <option value="unpaid"  <?= $paymentStatus === 'unpaid' ? 'selected' : '' ?>>Не оплачены</option>
                                <option value="pending" <?= $paymentStatus === 'pending' ? 'selected' : '' ?>>Ожидание</option>
                                <option value="canceled" <?= $paymentStatus === 'canceled' ? 'selected' : '' ?>>Отмена</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-[11px] text-slate-400 mb-1">Тип оплаты</label>
                            <div class="flex gap-2">
                                <select name="payment_type"
                                        class="w-full rounded-xl bg-slate-900 border border-slate-700 px-3 py-2 text-sm text-slate-50 focus:outline-none focus:ring-2 focus:ring-sky-500 focus:border-sky-500">
                                    <option value="all"       <?= $paymentType === 'all' ? 'selected' : '' ?>>Все</option>
                                    <option value="card_later" <?= $paymentType === 'card_later' ? 'selected' : '' ?>>Картой</option>
                                    <option value="cash"      <?= $paymentType === 'cash' ? 'selected' : '' ?>>Наличными</option>
                                </select>
                                <button type="submit"
                                        class="shrink-0 inline-flex items-center justify-center px-3 py-2 rounded-xl bg-sky-500 hover:bg-sky-400 text-xs font-semibold text-slate-950">
                                    Применить
                                </button>
                            </div>
                        </div>
                    </form>
                </section>

                <!-- list -->
                <section class="space-y-2">
                    <div class="flex items-center justify-between">
                        <div class="text-xs text-slate-400">
                            Найдено заказов: <span class="text-slate-100 font-semibold"><?= (int)$totalOrders ?></span>
                        </div>
                    </div>

                    <?php if (!$orders): ?>
                        <div class="rounded-3xl bg-slate-900/80 border border-slate-800 px-4 py-4 text-sm text-slate-300 shadow-lg shadow-slate-950/60">
                            В выбранном интервале заказов не найдено.
                        </div>
                    <?php else: ?>
                        <div class="space-y-3">
                            <?php foreach ($orders as $order): ?>
                                <?php
                                $createdShort = date('d.m H:i', strtotime($order['created_at'] ?? 'now'));
                                $statusClass  = badge_payment_status_class($order['payment_status'] ?? '');
                                $typeClass    = badge_payment_type_class($order['payment_type'] ?? '');
                                ?>
                                <div class="bg-slate-950/80 border border-slate-800 rounded-3xl p-3 md:p-4 shadow-lg shadow-slate-950/60">
                                    <div class="flex flex-wrap items-start justify-between gap-3 mb-2">
                                        <div>
                                            <div class="text-xs text-slate-500">
                                                Заказ #<span class="font-mono text-slate-100"><?= (int)$order['id'] ?></span>
                                                · Стол:
                                                <span class="text-slate-100">
                                                    <?= e($order['table_name'] ?? '—') ?>
                                                </span>
                                            </div>
                                            <div class="text-[11px] text-slate-500">
                                                <?= e($createdShort) ?>
                                            </div>
                                        </div>
                                        <div class="text-right">
                                            <div class="text-base md:text-lg font-semibold text-emerald-400 leading-tight">
                                                <?= number_format((float)$order['total_price'], 0, '.', ' ') ?> ₽
                                            </div>
                                            <div class="text-[11px] text-slate-500">
                                                <?= human_payment_type($order['payment_type'] ?? '') ?>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="flex flex-wrap items-center gap-2 mb-2">
                                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full border text-[11px] <?= e($statusClass) ?>">
                                            <?= human_payment_status($order['payment_status'] ?? '') ?>
                                        </span>
                                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full border text-[11px] <?= e($typeClass) ?>">
                                            <?= human_payment_type($order['payment_type'] ?? '') ?>
                                        </span>

                                        <?php if (($order['payment_status'] ?? '') !== 'paid' && ($order['order_status'] ?? '') === 'delivered'): ?>
                                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full border text-[11px] bg-red-500/20 border-red-500/80 text-red-50">
                                                <span class="w-2 h-2 rounded-full bg-red-400 animate-pulse"></span>
                                                ВНИМАНИЕ: отдан гостю, не оплачен
                                            </span>
                                        <?php endif; ?>
                                    </div>

                                    <div class="text-[11px] text-slate-500">
                                        Статус заказа:
                                        <span class="text-slate-100">
                                            <?= human_order_status($order['order_status'] ?? '') ?>
                                        </span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>
            </div>
        </main>
    </div>
</div>
</body>
</html>
