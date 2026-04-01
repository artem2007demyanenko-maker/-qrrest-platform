<?php

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/upsell_analytics.php';

$rid = bin2hex(random_bytes(4));
set_exception_handler(function (Throwable $e) use ($rid) {
    error_log('RESTAURANT_ANALYTICS_UPSELL rid=' . $rid . ' ' . $e->getMessage());
    if (!headers_sent()) {
        http_response_code(500);
    }
    echo '<h1>Ошибка</h1><p>Попробуйте позже.</p><p style="font-size:11px;color:#666">RID: ' . htmlspecialchars($rid) . '</p>';
    exit;
});

require_login();
require_current_restaurant();
require_current_restaurant_role(['owner', 'admin']);

$restId = (int)$currentRestaurant['id'];
$pdo = db();

if (!function_exists('e')) {
    function e($v): string {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

$rangeDays = isset($_GET['days']) ? max(1, min(90, (int)$_GET['days'])) : 7;
$stats = upsell_stats($restId, $rangeDays);

$revSummary = null;
$topUpsellItems = [];
$breakdown = [];
$trend = [];
if (!is_demo_mode()) {
    $revSummary = function_exists('get_upsell_analytics_summary_cached')
        ? get_upsell_analytics_summary_cached($restId, $rangeDays)
        : get_upsell_analytics_summary($restId, $rangeDays);

    // Lazy loading: не считаем тяжелые breakdown/trend/top, если по факту нет upsell.
    $hasUpsell = is_array($revSummary) && ((int)($revSummary['orders_with_upsell'] ?? 0) > 0);
    if ($hasUpsell) {
        $topUpsellItems = function_exists('get_top_upsell_items_cached')
            ? get_top_upsell_items_cached($restId, $rangeDays, 5)
            : get_top_upsell_items($restId, $rangeDays, 5);

        $breakdown = function_exists('get_upsell_performance_breakdown_cached')
            ? get_upsell_performance_breakdown_cached($restId, $rangeDays)
            : get_upsell_performance_breakdown($restId, $rangeDays);

        $trend = function_exists('get_upsell_revenue_trend_cached')
            ? get_upsell_revenue_trend_cached($restId, $rangeDays)
            : get_upsell_revenue_trend($restId, $rangeDays);
    }
}

$topWithNames = [];
$topIds = [];
foreach ($stats['top_upsell'] as $row) {
    $id = (int)($row['upsell_item_id'] ?? 0);
    if ($id > 0) {
        $topIds[$id] = true;
    }
}
$namesById = [];
if ($topIds !== []) {
    $ids = array_keys($topIds);
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT id, name FROM menu_items WHERE restaurant_id = ? AND id IN ($ph)");
    $stmt->execute(array_merge([$restId], $ids));
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $iid = (int)($r['id'] ?? 0);
        if ($iid <= 0) continue;
        $namesById[$iid] = (string)($r['name'] ?? '');
    }
}
foreach ($stats['top_upsell'] as $row) {
    $id = (int)($row['upsell_item_id'] ?? 0);
    $name = $id > 0 && isset($namesById[$id]) ? (string)$namesById[$id] : '';
    $topWithNames[] = ['upsell_item_id' => $id, 'name' => $name !== '' ? $name : ('ID ' . $id), 'count' => (int)($row['count'] ?? 0)];
}
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Аналитика допродаж — <?= e($currentRestaurant['name']) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?= brand_head_tags() ?>

    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-950 text-slate-50 flex">

<aside class="w-64 bg-slate-950/80 border-r border-slate-800 p-4 hidden md:block">
    <?= brand_restaurant_sidebar_header_html($currentRestaurant['name']) ?>
    <nav class="space-y-2 text-sm">
        <a href="/restaurant/dashboard.php" class="block px-3 py-2 rounded-xl hover:bg-slate-800/60">Обзор</a>
        <a href="/restaurant/revenue.php" class="block px-3 py-2 rounded-xl hover:bg-slate-800/60">Доход</a>
        <a href="/restaurant/menu_categories.php" class="block px-3 py-2 rounded-xl hover:bg-slate-800/60">Категории меню</a>
        <a href="/restaurant/menu_items.php" class="block px-3 py-2 rounded-xl hover:bg-slate-800/60">Блюда</a>
        <a href="/restaurant/tables.php" class="block px-3 py-2 rounded-xl hover:bg-slate-800/60">Столы и QR</a>
        <a href="/restaurant/qr_print.php" class="block px-3 py-2 rounded-xl hover:bg-slate-800/60">QR Print</a>
        <a href="/restaurant/floorplan.php" class="block px-3 py-2 rounded-xl hover:bg-slate-800/60">Карта столов</a>
        <a href="/restaurant/orders.php" class="block px-3 py-2 rounded-xl hover:bg-slate-800/60">Заказы</a>
        <a href="/restaurant/upsells.php" class="block px-3 py-2 rounded-xl hover:bg-slate-800/60">Допродажи</a>
        <a href="/restaurant/upsell_rules.php" class="block px-3 py-2 rounded-xl hover:bg-slate-800/60">Правила допродаж</a>
        <a href="/restaurant/analytics_upsell.php" class="block px-3 py-2 rounded-xl bg-slate-800/70">Аналитика допродаж</a>
        <a href="/restaurant/crm.php" class="block px-3 py-2 rounded-xl hover:bg-slate-800/60">CRM</a>
        <a href="/restaurant/crm_campaigns.php" class="block px-3 py-2 rounded-xl hover:bg-slate-800/60">CRM кампании</a>
        <a href="/restaurant/staff.php" class="block px-3 py-2 rounded-xl hover:bg-slate-800/60">Сотрудники</a>
        <a href="/restaurant/settings.php" class="block px-3 py-2 rounded-xl hover:bg-slate-800/60">Настройки</a>
        <a href="/logout.php" class="block px-3 py-2 rounded-xl hover:bg-slate-800/60 text-red-300">Выйти</a>
    </nav>
</aside>

<main class="flex-1 p-4">
    <div class="max-w-4xl mx-auto space-y-4">
        <header class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="text-2xl font-bold mb-1">Аналитика допродаж</h2>
                <p class="text-xs text-slate-500">События: показы, клики «Добавить», попавшие в заказ.</p>
            </div>
            <div class="flex gap-2 text-xs">
                <a href="?days=7" class="px-2 py-1 rounded-lg <?= $rangeDays === 7 ? 'bg-slate-700 text-slate-100' : 'bg-slate-800/60 text-slate-400 hover:text-slate-200' ?>">7 дн.</a>
                <a href="?days=30" class="px-2 py-1 rounded-lg <?= $rangeDays === 30 ? 'bg-slate-700 text-slate-100' : 'bg-slate-800/60 text-slate-400 hover:text-slate-200' ?>">30 дн.</a>
            </div>
        </header>

        <section class="grid md:grid-cols-4 gap-3">
            <div class="rounded-3xl bg-slate-900/80 border border-slate-800 p-4">
                <div class="text-xs text-slate-400 uppercase tracking-wide">Показы</div>
                <div class="text-2xl font-bold text-slate-100 mt-1"><?= (int)$stats['by_event']['shown'] ?></div>
            </div>
            <div class="rounded-3xl bg-slate-900/80 border border-slate-800 p-4">
                <div class="text-xs text-slate-400 uppercase tracking-wide">Клики «Добавить»</div>
                <div class="text-2xl font-bold text-amber-300 mt-1"><?= (int)$stats['by_event']['add_click'] ?></div>
            </div>
            <div class="rounded-3xl bg-slate-900/80 border border-slate-800 p-4">
                <div class="text-xs text-slate-400 uppercase tracking-wide">В заказе</div>
                <div class="text-2xl font-bold text-emerald-400 mt-1"><?= (int)$stats['by_event']['accepted_in_order'] ?></div>
            </div>
            <div class="rounded-3xl bg-slate-900/80 border border-slate-800 p-4">
                <div class="text-xs text-slate-400 uppercase tracking-wide">Конверсия</div>
                <div class="text-2xl font-bold text-sky-300 mt-1"><?= number_format($stats['conversion_pct'], 1) ?>%</div>
            </div>
        </section>

        <section class="rounded-3xl bg-slate-900/80 border border-slate-800 p-4">
            <h3 class="text-sm font-semibold mb-3">Топ-5 блюд по кликам «Добавить»</h3>
            <?php if (empty($topWithNames)): ?>
                <p class="text-sm text-slate-400">Нет данных за выбранный период.</p>
            <?php else: ?>
                <ul class="space-y-2">
                    <?php foreach ($topWithNames as $i => $row): ?>
                        <li class="flex items-center justify-between gap-2 text-sm">
                            <span class="text-slate-300"><?= $i + 1 ?>. <?= e($row['name']) ?></span>
                            <span class="font-semibold text-amber-300"><?= (int)$row['count'] ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <?php if (is_demo_mode()): ?>
            <section class="rounded-3xl bg-slate-900/80 border border-slate-800 p-4">
                <h3 class="text-sm font-semibold mb-3">Upsell Revenue Analytics</h3>
                <p class="text-sm text-slate-400">Demo data unavailable</p>
            </section>
        <?php else: ?>
            <?php if ($revSummary === null || (int)($revSummary['orders_with_upsell'] ?? 0) <= 0): ?>
                <section class="rounded-3xl bg-slate-900/80 border border-slate-800 p-4">
                    <h3 class="text-sm font-semibold mb-3">Upsell Revenue Analytics</h3>
                    <p class="text-sm text-slate-400">Недостаточно данных по допродажам</p>
                </section>
            <?php else: ?>
                <section class="space-y-3">
                    <div class="grid md:grid-cols-4 gap-3">
                        <div class="rounded-3xl bg-slate-900/80 border border-slate-800 p-4">
                            <div class="text-xs text-slate-400 uppercase tracking-wide">Выручка от допродаж</div>
                            <div class="text-2xl font-bold text-emerald-300 mt-1"><?= number_format((float)($revSummary['upsell_revenue'] ?? 0), 0, '.', ' ') ?> ₽</div>
                        </div>
                        <div class="rounded-3xl bg-slate-900/80 border border-slate-800 p-4">
                            <div class="text-xs text-slate-400 uppercase tracking-wide">Доля допродаж</div>
                            <div class="text-2xl font-bold text-emerald-300 mt-1"><?= number_format(100 * (float)($revSummary['upsell_revenue_share'] ?? 0), 1) ?>%</div>
                        </div>
                        <div class="rounded-3xl bg-slate-900/80 border border-slate-800 p-4">
                            <div class="text-xs text-slate-400 uppercase tracking-wide">Attach rate</div>
                            <div class="text-2xl font-bold text-emerald-300 mt-1"><?= number_format(100 * (float)($revSummary['upsell_attach_rate'] ?? 0), 1) ?>%</div>
                        </div>
                        <div class="rounded-3xl bg-slate-900/80 border border-slate-800 p-4">
                            <div class="text-xs text-slate-400 uppercase tracking-wide">Средний чек</div>
                            <?php
                                $liftVal = (float)($revSummary['aov_lift_value'] ?? 0);
                                $liftPct = $revSummary['aov_lift_percent'] ?? null;
                            ?>
                            <div class="text-2xl font-bold text-sky-300 mt-1">
                                <?= ($liftVal >= 0 ? '+' : '-') . number_format(abs($liftVal), 0, '.', ' ') ?> ₽
                                <?php if ($liftPct !== null): ?>
                                    <span class="text-sm text-sky-200">(<?= number_format(100 * (float)$liftPct, 1) ?>%)</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="rounded-3xl bg-slate-900/80 border border-slate-800 p-4">
                        <h3 class="text-sm font-semibold mb-3">Top upsell items</h3>
                        <?php if (empty($topUpsellItems)): ?>
                            <p class="text-sm text-slate-400">Нет данных.</p>
                        <?php else: ?>
                            <ul class="space-y-2">
                                <?php foreach ($topUpsellItems as $i => $it): ?>
                                    <li class="flex items-center justify-between gap-2 text-sm">
                                        <span class="text-slate-300"><?= $i + 1 ?>. <?= e($it['name'] ?? '') ?></span>
                                        <span class="text-amber-300 font-semibold"><?= number_format((float)($it['revenue_generated'] ?? 0), 0, '.', ' ') ?> ₽</span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>

                    <div class="rounded-3xl bg-slate-900/80 border border-slate-800 p-4">
                        <h3 class="text-sm font-semibold mb-3">Breakdown by type</h3>
                        <?php if (empty($breakdown)): ?>
                            <p class="text-sm text-slate-400">Нет данных для breakdown.</p>
                        <?php else: ?>
                            <div class="space-y-2 text-sm">
                                <?php foreach ($breakdown as $type => $v): ?>
                                    <div class="flex items-center justify-between gap-2 rounded-lg bg-gray-900/60 px-3 py-2">
                                        <span class="text-slate-300"><?= e($type) ?></span>
                                        <span class="text-slate-100 font-semibold">
                                            <?= number_format((float)($v['revenue'] ?? 0), 0, '.', ' ') ?> ₽
                                            <span class="text-gray-400 font-normal"> (<?= number_format((float)($v['share_percent'] ?? 0), 1) ?>%)</span>
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="rounded-3xl bg-slate-900/80 border border-slate-800 p-4">
                        <h3 class="text-sm font-semibold mb-3">Daily trend</h3>
                        <?php if (empty($trend)): ?>
                            <p class="text-sm text-slate-400">Нет данных.</p>
                        <?php else: ?>
                            <div class="space-y-2">
                                <?php foreach (array_slice($trend, -30) as $t): ?>
                                    <div class="flex items-center justify-between gap-2 text-sm">
                                        <span class="text-slate-400"><?= e($t['date'] ?? '') ?></span>
                                        <span class="text-slate-100 font-semibold"><?= number_format((float)($t['upsell_revenue'] ?? 0), 0, '.', ' ') ?> ₽</span>
                                        <span class="text-slate-300"><?= (int)($t['orders_with_upsell'] ?? 0) ?> заказ(ов)</span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </section>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</main>
</body>
</html>
