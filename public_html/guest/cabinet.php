<?php
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/guest_auth.php';
if (file_exists(__DIR__ . '/../../app/schema_guard.php')) {
    require_once __DIR__ . '/../../app/schema_guard.php';
}

$pdo = db();

if (!$currentRestaurant) {
    http_response_code(404);
    echo "Ресторан не найден (по поддомену).";
    exit;
}

$restId = (int)$currentRestaurant['id'];
$guest  = guest_require_login($pdo, $restId);

// Soft loyalty gating: when plan has loyalty disabled, show safe message and do not expose balance/history.
$loyaltyDisabledByPlan = false;
if (file_exists(__DIR__ . '/../../app/subscription_plans.php')) {
    require_once __DIR__ . '/../../app/subscription_plans.php';
    if (function_exists('check_feature') && !check_feature($restId, 'loyalty_enabled')) {
        $loyaltyDisabledByPlan = true;
    }
}

$balance = $loyaltyDisabledByPlan ? 0 : (int)($guest['loyalty_balance'] ?? 0);

// Canonical ledger: guest_loyalty_tx (empty when loyalty disabled by plan)
$txs = [];
if (!$loyaltyDisabledByPlan && function_exists('guest_loyalty_recent_tx')) {
    $txs = guest_loyalty_recent_tx($pdo, $restId, (int)$guest['id'], 20);
} elseif (!$loyaltyDisabledByPlan) {
    $stmt = $pdo->prepare("
        SELECT id, type, points, note, created_at
        FROM guest_loyalty_tx
        WHERE restaurant_id = ? AND guest_id = ?
        ORDER BY created_at DESC
        LIMIT 20
    ");
    $stmt->execute([$restId, (int)$guest['id']]);
    $txs = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$guestOrders = [];
if (function_exists('db_table_exists') && db_table_exists('orders')) {
    $amtExpr = (function_exists('db_column_exists') && db_column_exists('orders', 'total_amount'))
        ? 'COALESCE(total_amount, total_price, 0)'
        : 'total_price';
    $ptsExpr = (function_exists('db_column_exists') && db_column_exists('orders', 'loyalty_points_accrued'))
        ? 'loyalty_points_accrued'
        : '0';
    $spentExpr = (function_exists('db_column_exists') && db_column_exists('orders', 'loyalty_points_spent'))
        ? 'loyalty_points_spent'
        : '0';
    $hasOrderGuestIdCol = function_exists('db_column_exists') && db_column_exists('orders', 'guest_id');
    $hasOrderLoyaltyPhoneCol = function_exists('db_column_exists') && db_column_exists('orders', 'loyalty_phone');
    try {
        if ($hasOrderGuestIdCol && $hasOrderLoyaltyPhoneCol) {
            $stOrd = $pdo->prepare("
                SELECT id, order_status, created_at, {$amtExpr} AS total_amt, {$ptsExpr} AS pts, {$spentExpr} AS spent
                FROM orders
                WHERE restaurant_id = ?
                  AND (
                    guest_id = ?
                    OR ((guest_id IS NULL OR guest_id = 0) AND loyalty_phone = ?)
                  )
                ORDER BY id DESC
                LIMIT 20
            ");
            $stOrd->execute([$restId, (int)$guest['id'], (string)$guest['phone']]);
            $guestOrders = $stOrd->fetchAll(PDO::FETCH_ASSOC);
        } elseif ($hasOrderGuestIdCol) {
            $stOrd = $pdo->prepare("
                SELECT id, order_status, created_at, {$amtExpr} AS total_amt, {$ptsExpr} AS pts, {$spentExpr} AS spent
                FROM orders
                WHERE restaurant_id = ? AND guest_id = ?
                ORDER BY id DESC
                LIMIT 20
            ");
            $stOrd->execute([$restId, (int)$guest['id']]);
            $guestOrders = $stOrd->fetchAll(PDO::FETCH_ASSOC);
        } elseif ($hasOrderLoyaltyPhoneCol) {
            $stOrd = $pdo->prepare("
                SELECT id, order_status, created_at, {$amtExpr} AS total_amt, {$ptsExpr} AS pts, {$spentExpr} AS spent
                FROM orders
                WHERE restaurant_id = ? AND loyalty_phone = ?
                ORDER BY id DESC
                LIMIT 20
            ");
            $stOrd->execute([$restId, (string)$guest['phone']]);
            $guestOrders = $stOrd->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Throwable $e) {
        $guestOrders = [];
    }
}

$itemsByOrder = [];
$pointsByOrder = [];
$spentByOrder = [];
$orderIds = [];
foreach ($guestOrders as $go) {
    $oid = (int)($go['id'] ?? 0);
    if ($oid > 0) {
        $orderIds[] = $oid;
    }
}
if ($orderIds !== [] && function_exists('db_table_exists') && db_table_exists('order_items')) {
    try {
        $orderIds = array_values(array_unique($orderIds));
        $ph = implode(',', array_fill(0, count($orderIds), '?'));
        $stIt = $pdo->prepare("
            SELECT order_id, item_name,
                   COALESCE(NULLIF(quantity, 0), NULLIF(qty, 0), 1) AS line_qty,
                   price
            FROM order_items
            WHERE order_id IN ($ph)
            ORDER BY id ASC
        ");
        $stIt->execute($orderIds);
        while ($row = $stIt->fetch(PDO::FETCH_ASSOC)) {
            $oid = (int)$row['order_id'];
            if (!isset($itemsByOrder[$oid])) {
                $itemsByOrder[$oid] = [];
            }
            $itemsByOrder[$oid][] = $row;
        }
    } catch (Throwable $e) {
        $itemsByOrder = [];
    }
}
if ($orderIds !== [] && function_exists('db_table_exists') && db_table_exists('guest_loyalty_tx')) {
    try {
        $ph = implode(',', array_fill(0, count($orderIds), '?'));
        $params = array_merge([(int)$restId, (int)$guest['id']], $orderIds);
        $stPts = $pdo->prepare("
            SELECT order_id, SUM(points) AS pts
            FROM guest_loyalty_tx
            WHERE restaurant_id = ?
              AND guest_id = ?
              AND type = 'accrual'
              AND order_id IN ($ph)
            GROUP BY order_id
        ");
        $stPts->execute($params);
        foreach ($stPts->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $oid = (int)($row['order_id'] ?? 0);
            if ($oid > 0) {
                $pointsByOrder[$oid] = max(0, (int)($row['pts'] ?? 0));
            }
        }
    } catch (Throwable $e) {
        $pointsByOrder = [];
    }
    try {
        $ph = implode(',', array_fill(0, count($orderIds), '?'));
        $params = array_merge([(int)$restId, (int)$guest['id']], $orderIds);
        $stSpend = $pdo->prepare("
            SELECT order_id, COALESCE(SUM(ABS(points)), 0) AS pts
            FROM guest_loyalty_tx
            WHERE restaurant_id = ?
              AND guest_id = ?
              AND type = 'spend'
              AND order_id IN ($ph)
            GROUP BY order_id
        ");
        $stSpend->execute($params);
        foreach ($stSpend->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $oid = (int)($row['order_id'] ?? 0);
            if ($oid > 0) {
                $spentByOrder[$oid] = max(0, (int)($row['pts'] ?? 0));
            }
        }
    } catch (Throwable $e) {
        $spentByOrder = [];
    }
}

$menuTableId = (int)($_SESSION['guest_last_table_id'] ?? 0);
$menuCtx = (string)($_SESSION['guest_last_menu_context'] ?? '');
$menuBackHref = ($menuCtx === 'delivery')
    ? qr_public_build_url('/qr.php', [])
    : ($menuTableId > 0 ? qr_public_build_url('/qr.php', ['table_id' => $menuTableId]) : '/');
$earnPercentUi = (float)($currentRestaurant['loyalty_percent'] ?? 0);
if ($earnPercentUi <= 0) {
    $earnPercentUi = 5.0;
}

$qrUrl = "/staff/loyalty/scan.php?token=" . urlencode((string)$guest['qr_token']);

function mask_phone(string $p): string {

    $digits = preg_replace('/\D+/', '', $p);
    if (strlen($digits) === 11 && $digits[0] === '7') {
        return '+7 (' . substr($digits, 1, 3) . ') ***-**-' . substr($digits, 9, 2);
    }
    return $p;
}

function guest_cabinet_fmt_dt(?string $raw): string {
    if ($raw === null || $raw === '') {
        return '';
    }
    $ts = strtotime((string)$raw);
    if ($ts === false) {
        return (string)$raw;
    }
    return date('d.m.Y · H:i', $ts);
}

function guest_cabinet_order_status_label(string $st): string {
    $k = strtolower(trim($st));
    $map = [
        'new'       => 'Принят',
        'pending'   => 'В очереди',
        'cooking'   => 'Готовится',
        'ready'     => 'Готов',
        'served'    => 'Подано',
        'completed' => 'Завершён',
        'done'      => 'Завершён',
        'cancelled' => 'Отменён',
        'canceled'  => 'Отменён',
    ];
    return $map[$k] ?? $st;
}

?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Личный кабинет — <?= guest_e($currentRestaurant['name']) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
    <style>
        .float-a{animation:fa 18s ease-in-out infinite}
        .float-b{animation:fb 26s ease-in-out infinite}
        @keyframes fa{0%,100%{transform:translate3d(0,0,0) scale(1)}50%{transform:translate3d(18px,-22px,0) scale(1.05)}}
        @keyframes fb{0%,100%{transform:translate3d(0,0,0) scale(1)}50%{transform:translate3d(-22px,26px,0) scale(1.06)}}
        details.guest-order > summary { list-style: none; }
        details.guest-order > summary::-webkit-details-marker { display: none; }
    </style>
</head>
<body class="min-h-screen bg-slate-950 text-slate-50">
<div class="relative max-w-2xl mx-auto px-4 py-6 pb-28 sm:pb-8">
    <div class="pointer-events-none absolute inset-0 -z-10">
        <div class="absolute -top-44 -left-28 w-96 h-96 bg-emerald-500/20 blur-3xl rounded-full float-a"></div>
        <div class="absolute bottom-[-12rem] right-[-4rem] w-[34rem] h-[34rem] bg-sky-500/16 blur-3xl rounded-full float-b"></div>
        <div class="absolute top-1/3 right-16 w-72 h-72 bg-fuchsia-500/14 blur-3xl rounded-full"></div>
    </div>

    <header class="rounded-3xl border border-slate-800/90 bg-slate-950/80 backdrop-blur-xl p-5 sm:p-6 shadow-2xl shadow-black/40 mb-5">
        <p class="text-[11px] uppercase tracking-wider text-slate-500 mb-1"><?= guest_e($currentRestaurant['name']) ?></p>
        <h1 class="text-2xl sm:text-3xl font-bold tracking-tight text-white">Личный кабинет</h1>
        <p class="text-sm text-slate-400 mt-2 leading-relaxed">Главный центр бонусов, операций и заказов этого ресторана</p>
        <?php if (!empty($guest['name'])): ?>
            <p class="text-sm text-slate-300 mt-3"><?= guest_e((string)$guest['name']) ?></p>
        <?php endif; ?>
        <p class="text-base font-medium text-slate-100 mt-1"><?= guest_e(mask_phone((string)$guest['phone'])) ?></p>
        <?php if (!$loyaltyDisabledByPlan): ?>
            <p class="text-xs text-slate-500 mt-3">Текущий баланс: <span class="text-emerald-300 font-semibold"><?= number_format($balance, 0, '.', ' ') ?> бонусов</span></p>
        <?php endif; ?>
    </header>

    <div class="grid sm:grid-cols-2 gap-3 mb-5">
        <a href="/guest/wallet.php"
           class="flex min-h-[52px] w-full items-center justify-center rounded-2xl border border-slate-700 bg-slate-900/80 px-4 text-sm font-semibold text-slate-100 hover:border-emerald-500/50 transition-colors">
            Все карты
        </a>
        <a href="<?= guest_e($menuBackHref) ?>"
           class="flex min-h-[52px] w-full items-center justify-center rounded-2xl bg-emerald-500 px-4 text-sm font-semibold text-slate-950 shadow-lg shadow-emerald-900/30 hover:bg-emerald-400 transition-colors">
            Вернуться в меню
        </a>
    </div>

    <?php if ($loyaltyDisabledByPlan): ?>
    <section class="rounded-3xl border border-amber-500/35 bg-amber-500/[0.07] p-5 mb-5">
        <p class="font-medium text-amber-100">Программа лояльности сейчас недоступна</p>
        <p class="text-sm text-amber-100/75 mt-2 leading-relaxed">Начисление и списание бонусов временно отключены. За подробностями обратитесь в ресторан.</p>
    </section>
    <?php else: ?>
    <section class="rounded-3xl border border-slate-800 bg-slate-950/85 backdrop-blur-xl p-5 sm:p-6 shadow-xl shadow-black/30 mb-5">
        <h2 class="text-xs font-semibold uppercase tracking-wide text-slate-500">Ваш бонусный баланс</h2>
        <div class="mt-3 text-4xl sm:text-5xl font-extrabold tracking-tight text-white tabular-nums">
            <?= number_format($balance, 0, '.', ' ') ?>
            <span class="text-lg sm:text-xl font-semibold text-slate-400">бонусов</span>
        </div>
        <?php if ($balance > 0): ?>
            <p class="text-sm text-slate-400 mt-4 leading-relaxed">Бонусы можно использовать в этом ресторане при следующих заказах: в QR-оформлении или через персонал.</p>
        <?php else: ?>
            <p class="text-sm text-slate-400 mt-4 leading-relaxed">Пока бонусов нет — оформите заказ, и мы начислим их после подтверждённой оплаты.</p>
        <?php endif; ?>
    </section>

    <section class="rounded-3xl border border-slate-800 bg-slate-950/85 backdrop-blur-xl p-5 sm:p-6 shadow-xl shadow-black/30 mb-5">
        <div class="flex items-baseline justify-between gap-3 mb-4">
            <h2 class="text-lg font-semibold text-white">Правила программы</h2>
            <a href="/guest/wallet.php" class="text-xs text-emerald-300 hover:text-emerald-200">Все карты</a>
        </div>
        <div class="grid sm:grid-cols-2 gap-3 text-sm">
            <div class="rounded-2xl bg-slate-900/60 border border-slate-800/80 px-4 py-3">
                <div class="text-[11px] uppercase tracking-wide text-slate-500">Начисление</div>
                <div class="mt-1 text-slate-100 font-medium">+<?= rtrim(rtrim(number_format($earnPercentUi, 2, '.', ''), '0'), '.') ?>% после подтверждённой оплаты</div>
            </div>
            <div class="rounded-2xl bg-slate-900/60 border border-slate-800/80 px-4 py-3">
                <div class="text-[11px] uppercase tracking-wide text-slate-500">Курс бонусов</div>
                <div class="mt-1 text-slate-100 font-medium">1 бонус = 1 ₽</div>
            </div>
            <div class="rounded-2xl bg-slate-900/60 border border-slate-800/80 px-4 py-3">
                <div class="text-[11px] uppercase tracking-wide text-slate-500">Списание</div>
                <div class="mt-1 text-slate-100 font-medium">До 20% суммы заказа в QR-оформлении</div>
            </div>
            <div class="rounded-2xl bg-slate-900/60 border border-slate-800/80 px-4 py-3">
                <div class="text-[11px] uppercase tracking-wide text-slate-500">Где смотреть всё сразу</div>
                <div class="mt-1 text-slate-100 font-medium">В кошельке карт по всем ресторанам</div>
            </div>
        </div>
        <p class="mt-4 text-xs text-slate-500 leading-relaxed">
            Этот кабинет показывает баланс, операции и историю заказов именно для <?= guest_e((string)$currentRestaurant['name']) ?>.
        </p>
    </section>
    <?php endif; ?>

    <section class="rounded-3xl border border-slate-800 bg-slate-950/85 backdrop-blur-xl p-5 sm:p-6 shadow-xl shadow-black/30 mb-5">
        <div class="flex items-baseline justify-between gap-3 mb-4">
            <h2 class="text-lg font-semibold text-white">История заказов</h2>
            <?php if (!empty($guestOrders)): ?>
                <span class="text-xs text-slate-500">Последние <?= count($guestOrders) ?></span>
            <?php endif; ?>
        </div>

        <?php if (empty($guestOrders)): ?>
            <p class="text-sm text-slate-400 leading-relaxed">После первого заказа в этом ресторане здесь появятся история заказов и начисления по ним.</p>
        <?php else: ?>
            <div class="space-y-3">
                <?php foreach ($guestOrders as $go): ?>
                    <?php
                        $oid = (int)($go['id'] ?? 0);
                        $stRaw = (string)($go['order_status'] ?? '');
                        $stLabel = guest_cabinet_order_status_label($stRaw);
                        $amt = (float)($go['total_amt'] ?? 0);
                        $pts = array_key_exists($oid, $pointsByOrder)
                            ? (int)$pointsByOrder[$oid]
                            : (int)($go['pts'] ?? 0);
                        $spent = array_key_exists($oid, $spentByOrder)
                            ? (int)$spentByOrder[$oid]
                            : (int)($go['spent'] ?? 0);
                        $lines = $itemsByOrder[$oid] ?? [];
                        $gross = 0.0;
                        $lineCount = count($lines);
                        $sumQty = 0;
                        foreach ($lines as $ln) {
                            $lineQty = (int)($ln['line_qty'] ?? 1);
                            $sumQty += $lineQty;
                            $gross += $lineQty * (float)($ln['price'] ?? 0);
                        }
                        if ($lineCount === 0) {
                            $sumQty = 0;
                        }
                        if ($gross <= 0) {
                            $gross = $amt + $spent;
                        }
                        $dt = guest_cabinet_fmt_dt(isset($go['created_at']) ? (string)$go['created_at'] : '');
                        $track = $menuTableId > 0 ? ('/order_track.php?table_id=' . $menuTableId . '&order_id=' . $oid) : '';
                    ?>
                    <details class="guest-order group rounded-2xl border border-slate-800 bg-slate-900/50 overflow-hidden">
                        <summary class="flex cursor-pointer flex-col gap-2 p-4 sm:flex-row sm:items-center sm:justify-between sm:gap-4 outline-none focus-visible:ring-2 focus-visible:ring-emerald-500/60 rounded-2xl">
                            <div class="min-w-0 text-left">
                                <div class="text-sm font-semibold text-white">Заказ №<?= $oid ?></div>
                                <div class="text-xs text-slate-500 mt-1"><?= guest_e($dt) ?></div>
                                <div class="text-xs text-slate-400 mt-1"><?= guest_e($stLabel) ?><?= $lineCount > 0 ? ' · ' . $lineCount . ' ' . ($lineCount === 1 ? 'позиция' : 'позиций') : '' ?></div>
                            </div>
                            <div class="flex shrink-0 flex-col items-stretch sm:items-end gap-2 text-left sm:text-right">
                                <div class="text-base font-semibold text-white tabular-nums"><?= number_format($amt, 0, '.', ' ') ?> ₽</div>
                                <?php if ($spent > 0): ?>
                                    <div class="text-[11px] text-slate-500">из <?= number_format($gross, 0, '.', ' ') ?> ₽</div>
                                <?php endif; ?>
                                <?php if ($pts > 0): ?>
                                    <div class="text-xs text-emerald-400">+<?= $pts ?> бонусов после оплаты</div>
                                <?php endif; ?>
                                <?php if ($spent > 0): ?>
                                    <div class="text-xs text-amber-300">-<?= $spent ?> бонусов при оплате</div>
                                <?php endif; ?>
                                <span class="text-[11px] text-slate-500 group-open:hidden">Развернуть детали</span>
                            </div>
                        </summary>
                        <div class="border-t border-slate-800/80 px-4 pb-4 pt-3 space-y-3">
                            <?php if ($lineCount > 0): ?>
                                <p class="text-[11px] uppercase tracking-wide text-slate-500">Детали заказа</p>
                                <ul class="space-y-2">
                                    <?php foreach ($lines as $ln): ?>
                                        <?php
                                            $q = (int)($ln['line_qty'] ?? 1);
                                            $pr = (float)($ln['price'] ?? 0);
                                            $nm = (string)($ln['item_name'] ?? '');
                                            $lineTotal = $q * $pr;
                                        ?>
                                        <li class="flex justify-between gap-3 text-sm">
                                            <span class="text-slate-300 min-w-0"><?= guest_e($nm) ?> × <?= $q ?></span>
                                            <span class="text-slate-400 shrink-0 tabular-nums"><?= number_format($lineTotal, 0, '.', ' ') ?> ₽</span>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                                <div class="flex justify-between text-sm font-semibold text-white pt-2 border-t border-slate-800/60">
                                    <span>Сумма блюд</span>
                                    <span class="tabular-nums"><?= number_format($gross, 0, '.', ' ') ?> ₽</span>
                                </div>
                                <?php if ($spent > 0): ?>
                                    <div class="flex justify-between text-xs text-amber-300">
                                        <span>Списано бонусами</span>
                                        <span class="tabular-nums">-<?= number_format($spent, 0, '.', ' ') ?> ₽</span>
                                    </div>
                                    <div class="flex justify-between text-sm font-semibold text-slate-200">
                                        <span>К оплате</span>
                                        <span class="tabular-nums"><?= number_format($amt, 0, '.', ' ') ?> ₽</span>
                                    </div>
                                <?php endif; ?>
                                <?php if ($sumQty > 0): ?>
                                    <p class="text-xs text-slate-500"><?= $sumQty ?> <?= $sumQty === 1 ? 'порция' : 'порций' ?> в заказе</p>
                                <?php endif; ?>
                            <?php else: ?>
                                <p class="text-xs text-slate-500">Состав заказа недоступен для отображения.</p>
                                <div class="flex justify-between text-sm font-semibold text-white pt-1">
                                    <span>Сумма блюд</span>
                                    <span class="tabular-nums"><?= number_format($gross, 0, '.', ' ') ?> ₽</span>
                                </div>
                                <?php if ($spent > 0): ?>
                                    <div class="flex justify-between text-xs text-amber-300">
                                        <span>Списано бонусами</span>
                                        <span class="tabular-nums">-<?= number_format($spent, 0, '.', ' ') ?> ₽</span>
                                    </div>
                                    <div class="flex justify-between text-sm font-semibold text-slate-200">
                                        <span>К оплате</span>
                                        <span class="tabular-nums"><?= number_format($amt, 0, '.', ' ') ?> ₽</span>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                            <?php if ($track !== ''): ?>
                                <a href="<?= guest_e($track) ?>" class="inline-flex min-h-[44px] items-center justify-center rounded-xl border border-slate-700 bg-slate-800/50 px-4 text-xs font-medium text-slate-200 hover:border-emerald-500/40 w-full sm:w-auto">Статус заказа</a>
                            <?php endif; ?>
                            <?php /* TODO: «Повторить заказ» — безопасно связать с корзиной qr.php (проверка menu_item_id, ресторан, актуальность цен). */ ?>
                        </div>
                    </details>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <?php if (!$loyaltyDisabledByPlan): ?>
    <div class="grid sm:grid-cols-2 gap-4 mb-5">
        <aside class="rounded-3xl border border-slate-800 bg-slate-950/85 backdrop-blur-xl p-5 shadow-xl shadow-black/30">
            <div class="flex items-center justify-between mb-3">
                <div>
                    <h2 class="text-xs font-semibold uppercase tracking-wide text-slate-500">Ваш код</h2>
                    <p class="text-sm font-medium text-slate-100 mt-1">Покажите персоналу для ручного начисления или списания</p>
                </div>
            </div>
            <div class="rounded-2xl bg-white p-3 flex items-center justify-center">
                <div id="qr-box"></div>
            </div>
            <p class="mt-3 text-[11px] text-slate-500 leading-relaxed">Работает на странице этого ресторана.</p>
            <button type="button"
                    onclick="navigator.clipboard?.writeText(location.origin + '<?= guest_e($qrUrl) ?>')"
                    class="mt-3 w-full min-h-[44px] rounded-2xl bg-slate-900/70 border border-slate-700 text-xs font-medium text-slate-200 hover:border-emerald-500/50">
                Скопировать ссылку
            </button>
        </aside>

        <section class="rounded-3xl border border-slate-800 bg-slate-950/85 backdrop-blur-xl p-5 shadow-xl shadow-black/30">
            <div class="flex items-baseline justify-between gap-3 mb-3">
                <h2 class="text-lg font-semibold text-white">Операции с бонусами</h2>
                <span class="text-xs text-slate-500">До 20</span>
            </div>
            <?php if (empty($txs)): ?>
                <p class="text-sm text-slate-400">После подтверждённой оплаты или списания в QR-оформлении здесь появятся все операции по бонусам.</p>
            <?php else: ?>
                <div class="space-y-2 max-h-[320px] overflow-y-auto pr-1">
                    <?php foreach ($txs as $tx): ?>
                        <?php
                            $type = $tx['type'];
                            $pts  = (int)$tx['points'];
                            $isPlus = ($type === 'accrual' || ($type === 'adjust' && $pts > 0));
                            $label = $type === 'accrual' ? 'Начисление' : ($type === 'spend' ? 'Списание' : 'Изменение');
                        ?>
                        <div class="flex items-center justify-between gap-3 rounded-2xl bg-slate-900/60 border border-slate-800/80 px-3 py-3">
                            <div class="min-w-0">
                                <div class="text-sm font-medium text-slate-100"><?= guest_e($label) ?></div>
                                <div class="text-[11px] text-slate-500 mt-0.5">
                                    <?= guest_e((string)$tx['created_at']) ?>
                                    <?php if (!empty($tx['note']) || !empty($tx['comment'])): ?>
                                        · <?= guest_e((string)($tx['note'] ?? $tx['comment'] ?? '')) ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="text-right shrink-0">
                                <div class="text-sm font-bold <?= $isPlus ? 'text-emerald-300' : 'text-rose-300' ?>">
                                    <?= $isPlus ? '+' : '' ?><?= number_format($pts, 0, '.', ' ') ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>
    <?php endif; ?>

    <div class="fixed bottom-0 left-0 right-0 p-4 pb-[max(1rem,env(safe-area-inset-bottom))] bg-gradient-to-t from-slate-950 via-slate-950 to-transparent sm:static sm:bg-transparent sm:p-0 sm:pb-0 flex flex-col sm:flex-row gap-3">
        <a href="/guest/wallet.php"
           class="flex min-h-[48px] flex-1 items-center justify-center rounded-2xl border border-slate-700 bg-slate-900/80 text-sm font-medium text-slate-200 hover:border-emerald-500/50">
            Все карты
        </a>
        <a href="<?= guest_e($menuBackHref) ?>"
           class="flex min-h-[48px] flex-1 items-center justify-center rounded-2xl border border-slate-700 bg-slate-900/80 text-sm font-medium text-slate-200 hover:border-slate-500">
            В меню
        </a>
        <a href="/guest/logout.php"
           class="flex min-h-[48px] flex-1 items-center justify-center rounded-2xl border border-slate-700/80 bg-transparent text-sm font-medium text-slate-400 hover:text-slate-200 hover:border-slate-600">
            Выйти
        </a>
    </div>
</div>

<script>
(function(){
    const url = location.origin + <?= json_encode($qrUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    const el = document.getElementById("qr-box");
    if (el && typeof QRCode !== 'undefined') {
        new QRCode(el, {
            text: url,
            width: 200,
            height: 200,
            correctLevel: QRCode.CorrectLevel.M
        });
    }
})();
</script>
</body>
</html>
