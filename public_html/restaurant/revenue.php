<?php

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/revenue_stats.php';
if (file_exists(__DIR__ . '/../../app/schema_guard.php')) {
    require_once __DIR__ . '/../../app/schema_guard.php';
}

require_login();
require_current_restaurant();
require_current_restaurant_role(['owner', 'admin']);

if (file_exists(__DIR__ . '/../../app/trial_guard.php')) {
    require_once __DIR__ . '/../../app/trial_guard.php';
}
$trialRequiresUpgrade = is_demo_mode() ? false : (function_exists('trial_guard_requires_upgrade') && trial_guard_requires_upgrade());
$trialInfo = function_exists('trial_guard_trial_info') ? trial_guard_trial_info() : ['is_trial' => false, 'days_left' => 0, 'is_expired' => false, 'has_active_paid_plan' => false];

$restId = (int)$currentRestaurant['id'];
$days = 30;
$revenueWarnings = [];
$revenueSchema = [
    'orders' => function_exists('db_table_exists') ? db_table_exists('orders') : true,
    'order_items' => function_exists('db_table_exists') ? db_table_exists('order_items') : true,
    'menu_items' => function_exists('db_table_exists') ? db_table_exists('menu_items') : true,
];
$revenueSchemaReady = $revenueSchema['orders'] && $revenueSchema['order_items'];
if (!$revenueSchemaReady) {
    foreach ($revenueSchema as $tableName => $isReady) {
        if (!$isReady) {
            $revenueWarnings[] = 'Данные ограничены: таблица ' . $tableName . ' отсутствует. Показаны fallback-значения.';
            error_log('REVENUE_SCHEMA_MISSING table=' . $tableName . ' restaurant_id=' . $restId);
        }
    }
}
$statsFallback = [
    'total_revenue' => 0.0,
    'total_orders' => 0,
    'avg_check' => 0.0,
    'upsell_revenue' => 0.0,
    'crm_return_visits' => 0,
    'orders_per_day' => [],
];
$stats = is_demo_mode()
    ? demo_revenue_stats()
    : ($revenueSchemaReady ? revenue_stats_get($restId, $days) : $statsFallback);

$menuPerformance = ['top' => [], 'low' => [], 'no_sales' => [], 'insights' => []];
if (file_exists(__DIR__ . '/../../app/menu_performance.php')) {
    require_once __DIR__ . '/../../app/menu_performance.php';
    if ($revenueSchema['orders'] && $revenueSchema['order_items'] && $revenueSchema['menu_items']) {
        $menuPerformance = menu_performance_insights($restId, 7);
    }
}
if (is_demo_mode() && empty($menuPerformance['insights'])) {
    $menuPerformance = [
        'top' => [['id' => 5, 'name' => 'Стейк рибай 250 г', 'qty' => 38], ['id' => 7, 'name' => 'Паста карбонара', 'qty' => 34]],
        'low' => [['id' => 8, 'name' => 'Ризотто с белыми грибами', 'qty' => 4]],
        'no_sales' => [],
        'insights' => ['Стейк рибай — лидер продаж; паста карбонара на втором месте.', 'Ризотто можно продвинуть выше в категории «Основные блюда».'],
    ];
}

$menuOpportunities = ['promote' => [], 'add_photo' => [], 'pair_with' => [], 'move_higher' => [], 'consider_combo' => []];
$menuWarnings = ['no_sales' => [], 'low_performer' => [], 'weak_category' => []];
if (file_exists(__DIR__ . '/../../app/menu_intelligence.php')) {
    require_once __DIR__ . '/../../app/menu_intelligence.php';
    if ($revenueSchema['orders'] && $revenueSchema['menu_items']) {
        $menuIntel = get_menu_intelligence($restId);
        $menuOpportunities = $menuIntel['opportunities'] ?? $menuOpportunities;
        $menuWarnings = $menuIntel['warnings'] ?? $menuWarnings;
    }
}

$peakHours = ['hourly_counts' => [], 'peak_hour_range_text' => '', 'recommendation_text' => ''];
$menuHeatmap = ['top' => [], 'low' => [], 'no_sales' => [], 'has_data' => false];
$checkoutConversion = ['started' => 0, 'completed' => 0, 'conversion_pct' => null, 'recommendation_text' => ''];
$tableTurnoverRev = ['tables' => [], 'busiest_table' => '', 'recommendation_text' => '', 'has_data' => false];
$benchmarkRev = ['available' => false];
if (file_exists(__DIR__ . '/../../app/peak_hours.php') && $revenueSchema['orders']) { require_once __DIR__ . '/../../app/peak_hours.php'; $peakHours = get_peak_hours_summary($restId, 7); }
if (file_exists(__DIR__ . '/../../app/menu_heatmap.php') && $revenueSchema['orders']) { require_once __DIR__ . '/../../app/menu_heatmap.php'; $menuHeatmap = get_menu_heatmap($restId, 7); }
if (file_exists(__DIR__ . '/../../app/checkout_analytics.php') && $revenueSchema['orders']) { require_once __DIR__ . '/../../app/checkout_analytics.php'; $checkoutConversion = get_checkout_conversion($restId, 7); }
if (file_exists(__DIR__ . '/../../app/table_turnover.php') && $revenueSchema['orders']) { require_once __DIR__ . '/../../app/table_turnover.php'; $tableTurnoverRev = get_table_turnover($restId, 7); }
if (file_exists(__DIR__ . '/../../app/network_benchmark.php')) { require_once __DIR__ . '/../../app/network_benchmark.php'; $benchmarkRev = get_restaurant_benchmark($restId); }
if (is_demo_mode()) {
    if ($peakHours['peak_hour_range_text'] === '') { $peakHours = ['hourly_counts' => array_fill(0, 24, 0), 'peak_hour_range_text' => 'Больше всего заказов 18:00–20:00', 'recommendation_text' => 'На пик усильте смену зала и бара.']; for ($i = 18; $i < 21; $i++) { $peakHours['hourly_counts'][$i] = 12 + $i; } }
    if (!$menuHeatmap['has_data']) { $menuHeatmap = ['top' => [['name' => 'Стейк рибай 250 г', 'qty' => 38], ['name' => 'Лимонад домашний 0,5 л', 'qty' => 62]], 'low' => [['name' => 'Ризотто с белыми грибами', 'qty' => 4]], 'no_sales' => [], 'has_data' => true]; }
    if ($checkoutConversion['started'] === 0 && $checkoutConversion['completed'] === 0) { $checkoutConversion = ['started' => 84, 'completed' => 72, 'conversion_pct' => 85.7, 'recommendation_text' => 'Конверсия оформления в демо выглядит здоровой.']; }
    if (!$tableTurnoverRev['has_data']) { $tableTurnoverRev = ['tables' => [['name' => 'Зал · стол 1', 'orders_count' => 24], ['name' => 'Терраса · стол 3', 'orders_count' => 18]], 'busiest_table' => 'Зал · стол 1 (24 заказа)', 'recommendation_text' => 'На загруженных столах ускорьте подачу и расчёт.', 'has_data' => true]; }
    // Demo can show benchmark-like values, but live logic must be honest (no fabricated peers).
    if (!$benchmarkRev['available']) { $benchmarkRev = ['available' => true, 'your_aov' => 1680, 'peer_aov' => 1820, 'recommendation_text' => 'Демо: сравнение среднего чека с условным эталоном.']; }
}

if (!function_exists('e')) {
    function e($v): string {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

$chartLabels = array_keys($stats['orders_per_day']);
$chartValues = array_values($stats['orders_per_day']);
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Доход — <?= e($currentRestaurant['name']) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?= brand_head_tags() ?>

    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="/assets/css/motion.css">
    <link rel="stylesheet" href="/assets/css/polish.css">
</head>
<body class="min-h-screen bg-slate-950 text-slate-50 flex overflow-x-hidden <?= is_demo_mode() ? 'demo-mode' : '' ?>">
<?php
$restaurantSidebarActive = 'revenue';
$restaurantSidebarName = (string)($currentRestaurant['name'] ?? 'Ресторан');
require __DIR__ . '/_sidebar_mobile.php';
?>

<?php
require __DIR__ . '/_sidebar.php';
?>

<main class="flex-1 min-w-0 p-4 md:p-6 overflow-x-hidden">
    <div class="page-enter max-w-6xl mx-auto space-y-8">
        <?php
        $businessNavActive = 'revenue';
        require __DIR__ . '/_restaurant_cabinet_context.php';
        require __DIR__ . '/_restaurant_business_nav.php';
        ?>
        <?php if (is_demo_mode()): ?>
        <div class="rounded-xl bg-amber-500/10 border border-amber-500/40 px-4 py-2.5 flex flex-wrap items-center justify-center gap-2 text-sm text-amber-200 text-center">
            <span aria-hidden="true">⚠</span>
            <span>Демо-среда: показатели и действия имитируются.</span>
        </div>
        <?php endif; ?>
        <?php if ($revenueWarnings): ?>
        <div class="rounded-xl bg-amber-500/10 border border-amber-500/40 px-4 py-2.5 text-sm text-amber-200 space-y-1" role="status">
            <?php foreach ($revenueWarnings as $warning): ?>
                <div><?= e($warning) ?></div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php if ($trialRequiresUpgrade): ?>
        <div class="rounded-2xl bg-red-500/10 border border-red-500/50 px-4 py-4 text-center">
            <p class="text-slate-100 font-medium mb-2">Доступ к отчётам по выручке доступен после активации подписки</p>
            <a href="/restaurant/activate.php" class="inline-block px-4 py-2 rounded-xl bg-red-500/40 hover:bg-red-500/60 text-white text-sm font-medium">Активировать подписку</a>
        </div>
        <?php elseif ($trialInfo['is_trial'] && !$trialInfo['is_expired']): ?>
        <div class="rounded-2xl bg-sky-500/10 border border-sky-500/50 px-4 py-3 flex flex-wrap items-center justify-between gap-2">
            <span class="text-sm text-sky-100">Пробный период: осталось <?= (int)$trialInfo['days_left'] ?> дн.</span>
            <a href="/restaurant/activate.php" class="px-3 py-1.5 rounded-xl bg-sky-500/30 hover:bg-sky-500/50 text-sky-100 text-sm font-medium">Выбрать тариф</a>
        </div>
        <?php endif; ?>

        <?php if (!$trialRequiresUpgrade): ?>
        <header class="space-y-1">
            <h2 class="text-xl font-semibold tracking-tight text-slate-50">Доход</h2>
            <p class="text-sm text-gray-400">Выручка, заказы, допродажи и возврат гостей за последние <?= $days ?> дней.</p>
        </header>

        <!-- Revenue overview: KPI row -->
        <section class="space-y-6">
            <h3 class="text-xl font-semibold tracking-tight text-slate-100">Сводка по выручке</h3>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4">
                <div class="dashboard-card rounded-xl border border-gray-800 bg-[#121826] shadow-lg p-5 card-motion flex items-start gap-3">
                    <div class="w-10 h-10 rounded-lg bg-emerald-500/20 flex items-center justify-center flex-shrink-0 text-emerald-400">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    </div>
                    <div class="min-w-0">
                        <div class="text-2xl font-bold text-emerald-400"><?= number_format($stats['total_revenue'], 0, '.', ' ') ?> ₽</div>
                        <div class="text-xs text-gray-400 uppercase tracking-wide mt-0.5">Выручка</div>
                    </div>
                </div>
                <div class="dashboard-card rounded-xl border border-gray-800 bg-[#121826] shadow-lg p-5 card-motion flex items-start gap-3">
                    <div class="w-10 h-10 rounded-lg bg-sky-500/20 flex items-center justify-center flex-shrink-0 text-sky-400">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                    </div>
                    <div class="min-w-0">
                        <div class="text-2xl font-bold text-slate-100"><?= (int)$stats['total_orders'] ?></div>
                        <div class="text-xs text-gray-400 uppercase tracking-wide mt-0.5">Заказы</div>
                    </div>
                </div>
                <div class="dashboard-card rounded-xl border border-gray-800 bg-[#121826] shadow-lg p-5 card-motion flex items-start gap-3">
                    <div class="w-10 h-10 rounded-lg bg-indigo-500/20 flex items-center justify-center flex-shrink-0 text-indigo-400">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 12l3-3 3 3 4-4M8 21l4-4 4 4M3 4h18M4 4h16v12a1 1 0 01-1 1H5a1 1 0 01-1-1V4z"/></svg>
                    </div>
                    <div class="min-w-0">
                        <div class="text-2xl font-bold text-sky-300"><?= number_format($stats['avg_check'], 0, '.', ' ') ?> ₽</div>
                        <div class="text-xs text-gray-400 uppercase tracking-wide mt-0.5">Средний чек</div>
                    </div>
                </div>
                <div class="dashboard-card rounded-xl border border-gray-800 bg-[#121826] shadow-lg p-5 card-motion flex items-start gap-3">
                    <div class="w-10 h-10 rounded-lg bg-amber-500/20 flex items-center justify-center flex-shrink-0 text-amber-400">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>
                    </div>
                    <div class="min-w-0">
                        <div class="text-2xl font-bold text-amber-300"><?= number_format($stats['upsell_revenue'], 0, '.', ' ') ?> ₽</div>
                        <div class="text-xs text-gray-400 uppercase tracking-wide mt-0.5">Допродажи</div>
                    </div>
                </div>
                <div class="dashboard-card rounded-xl border border-gray-800 bg-[#121826] shadow-lg p-5 card-motion flex items-start gap-3">
                    <div class="w-10 h-10 rounded-lg bg-violet-500/20 flex items-center justify-center flex-shrink-0 text-violet-400">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                    </div>
                    <div class="min-w-0">
                        <div class="text-2xl font-bold text-violet-300"><?= (int)$stats['crm_return_visits'] ?></div>
                        <div class="text-xs text-gray-400 uppercase tracking-wide mt-0.5">Возвраты (CRM)</div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Chart -->
        <section class="space-y-6">
            <h3 class="text-xl font-semibold tracking-tight text-slate-100">Заказы по дням</h3>
            <div class="rounded-xl bg-gray-900/60 border border-gray-800 p-6 card-motion">
                <div class="h-64">
                    <canvas id="ordersChart"></canvas>
                </div>
            </div>
        </section>

        <!-- Menu performance insights -->
        <section class="space-y-6">
            <h3 class="text-xl font-semibold tracking-tight text-slate-100">Меню: что продаётся</h3>
            <div class="rounded-xl bg-gray-900/60 border border-gray-800 p-6 card-motion">
                <?php if (!empty($menuPerformance['insights'])): ?>
                    <ul class="space-y-2 mb-4">
                        <?php foreach (array_slice($menuPerformance['insights'], 0, 5) as $insight): ?>
                            <li class="flex items-start gap-2 text-sm text-slate-200">
                                <span class="text-indigo-400 mt-0.5">•</span>
                                <span><?= e($insight) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php if (!empty($menuPerformance['top'])): ?>
                    <p class="text-xs text-slate-500 mt-3 mb-1">Лидеры продаж (7 дней)</p>
                    <div class="flex flex-wrap gap-2">
                        <?php foreach (array_slice($menuPerformance['top'], 0, 5) as $t): ?>
                            <span class="px-3 py-1 rounded-lg bg-emerald-500/20 text-emerald-300 text-xs"><?= e($t['name']) ?> ×<?= (int)$t['qty'] ?></span>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($menuPerformance['no_sales'])): ?>
                    <p class="text-xs text-slate-500 mt-3 mb-1">Без продаж за период</p>
                    <div class="flex flex-wrap gap-2">
                        <?php foreach (array_slice($menuPerformance['no_sales'], 0, 5) as $n): ?>
                            <span class="px-3 py-1 rounded-lg bg-slate-700/60 text-slate-400 text-xs"><?= e($n['name']) ?></span>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="empty-state-box">
                        <svg class="empty-state-icon mx-auto text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>
                        <div class="empty-state-title">Пока нет выводов по меню</div>
                        <div class="empty-state-text">Добавьте позиции в меню и накопите заказы — здесь появятся лидеры продаж и подсказки.</div>
                        <a href="/restaurant/menu_manage.php#dishes" class="inline-block px-4 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-medium btn-motion">К меню</a>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <!-- Menu Opportunities -->
        <section class="space-y-6">
            <h3 class="text-xl font-semibold tracking-tight text-slate-100">Возможности по меню</h3>
            <div class="rounded-xl bg-gray-900/60 border border-gray-800 p-6 card-motion">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <h4 class="text-sm font-semibold text-emerald-400 uppercase mb-2">Сильные позиции</h4>
                        <?php if (!empty($menuOpportunities['promote'])): ?>
                        <ul class="space-y-1 text-sm text-slate-200">
                            <?php foreach (array_slice($menuOpportunities['promote'], 0, 5) as $p): ?>
                                <li><?= e($p['item_name']) ?> — <?= e($p['reason'] ?? 'top_seller') ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <?php elseif (!empty($menuPerformance['top'])): ?>
                        <ul class="space-y-1 text-sm text-slate-200">
                            <?php foreach (array_slice($menuPerformance['top'], 0, 5) as $t): ?>
                                <li><?= e($t['name']) ?> ×<?= (int)($t['qty'] ?? 0) ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <?php else: ?>
                        <p class="text-sm text-slate-500">Пока нет данных.</p>
                        <?php endif; ?>
                    </div>
                    <div>
                        <h4 class="text-sm font-semibold text-amber-400 uppercase mb-2">Слабые позиции</h4>
                        <?php
                        $underperformers = array_merge(
                            array_slice($menuWarnings['no_sales'] ?? [], 0, 3),
                            array_slice($menuWarnings['low_performer'] ?? [], 0, 3)
                        );
                        ?>
                        <?php if (!empty($underperformers)): ?>
                        <ul class="space-y-1 text-sm text-slate-300">
                            <?php foreach ($underperformers as $u): ?>
                                <li><?= e($u['name'] ?? '') ?><?= isset($u['qty']) ? ' (шт: ' . (int)$u['qty'] . ')' : ' — нет продаж' ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <?php elseif (!empty($menuPerformance['no_sales']) || !empty($menuPerformance['low'])): ?>
                        <ul class="space-y-1 text-sm text-slate-300">
                            <?php foreach (array_slice($menuPerformance['no_sales'] ?? [], 0, 3) as $n): ?><li><?= e($n['name']) ?> — нет продаж</li><?php endforeach; ?>
                            <?php foreach (array_slice($menuPerformance['low'] ?? [], 0, 3) as $l): ?><li><?= e($l['name']) ?> ×<?= (int)($l['qty'] ?? 0) ?></li><?php endforeach; ?>
                        </ul>
                        <?php else: ?>
                        <p class="text-sm text-slate-500">За период нет явных аутсайдеров.</p>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="mt-4 pt-4 border-t border-slate-700/60 grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <h4 class="text-sm font-semibold text-indigo-400 uppercase mb-2">Комбо и сочетания</h4>
                        <?php if (!empty($menuOpportunities['consider_combo'])): ?>
                        <ul class="space-y-1 text-sm text-slate-200">
                            <?php foreach (array_slice($menuOpportunities['consider_combo'], 0, 5) as $c): ?>
                                <li><?= e($c['item_a_name']) ?> + <?= e($c['item_b_name']) ?> (<?= (int)$c['orders_together'] ?>× together)</li>
                            <?php endforeach; ?>
                        </ul>
                        <?php else: ?>
                        <p class="text-sm text-slate-500">Накопите заказы — появятся частые сочетания позиций.</p>
                        <?php endif; ?>
                    </div>
                    <div>
                        <h4 class="text-sm font-semibold text-violet-400 uppercase mb-2">Допродажи и пары</h4>
                        <?php if (!empty($menuOpportunities['pair_with'])): ?>
                        <ul class="space-y-1 text-sm text-slate-200">
                            <?php foreach (array_slice($menuOpportunities['pair_with'], 0, 5) as $p): ?>
                                <li>Сочетайте «<?= e($p['item_a_name']) ?>» с «<?= e($p['item_b_name']) ?>»</li>
                            <?php endforeach; ?>
                        </ul>
                        <?php elseif (!empty($menuOpportunities['add_photo'])): ?>
                        <p class="text-sm text-slate-300">Добавьте фото: <?= implode(', ', array_map(function ($a) { return e($a['item_name']); }, array_slice($menuOpportunities['add_photo'], 0, 3))) ?>.</p>
                        <?php else: ?>
                        <p class="text-sm text-slate-500">Подсказки появятся по паттернам заказов.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </section>

        <!-- Peak hours -->
        <section class="space-y-6">
            <h3 class="text-xl font-semibold tracking-tight text-slate-100">Пиковые часы</h3>
            <div class="rounded-xl bg-gray-900/60 border border-gray-800 p-6 card-motion">
                <?php if ($peakHours['peak_hour_range_text'] !== ''): ?>
                    <p class="text-lg font-medium text-slate-100 mb-2"><?= e($peakHours['peak_hour_range_text']) ?></p>
                    <p class="text-sm text-slate-500"><?= e($peakHours['recommendation_text']) ?></p>
                    <div class="h-32 mt-4 flex items-end gap-0.5">
                        <?php for ($h = 0; $h < 24; $h++): $cnt = (int)($peakHours['hourly_counts'][$h] ?? 0); $max = max(1, max($peakHours['hourly_counts'])); $hgt = $max > 0 ? round(100 * $cnt / $max) : 0; ?>
                        <div class="flex-1 rounded-t bg-slate-700/80 hover:bg-emerald-500/60 transition-colors" style="height:<?= $hgt ?>%" title="<?= sprintf('%02d:00', $h) ?>: <?= $cnt ?>"></div>
                        <?php endfor; ?>
                    </div>
                    <p class="text-xs text-slate-500 mt-2">Последние 7 дней · по часам</p>
                <?php else: ?>
                    <div class="empty-state-box py-6">
                        <div class="text-sm text-slate-400">Появятся заказы — здесь покажем часы пика.</div>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <!-- Menu attention (heatmap) -->
        <section class="space-y-6">
            <h3 class="text-xl font-semibold tracking-tight text-slate-100">Внимание к позициям меню</h3>
            <div class="rounded-xl bg-gray-900/60 border border-gray-800 p-6 card-motion">
                <p class="text-xs text-slate-500 mb-3">Какие позиции чаще заказывают и на что обращают внимание</p>
                <?php if ($menuHeatmap['has_data']): ?>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div><h4 class="text-xs font-semibold text-emerald-400 uppercase mb-2">Топ</h4><ul class="space-y-1 text-sm text-slate-200"><?php foreach (array_slice($menuHeatmap['top'], 0, 5) as $t): ?><li><?= e($t['name']) ?> (<?= (int)$t['qty'] ?>)</li><?php endforeach; ?></ul></div>
                        <div><h4 class="text-xs font-semibold text-amber-400 uppercase mb-2">Низко</h4><ul class="space-y-1 text-sm text-slate-300"><?php foreach (array_slice($menuHeatmap['low'], 0, 3) as $l): ?><li><?= e($l['name']) ?> (<?= (int)$l['qty'] ?>)</li><?php endforeach; ?></ul></div>
                        <div><h4 class="text-xs font-semibold text-slate-500 uppercase mb-2">Без продаж</h4><ul class="space-y-1 text-sm text-slate-500"><?php foreach (array_slice($menuHeatmap['no_sales'], 0, 3) as $n): ?><li><?= e($n['name']) ?></li><?php endforeach; ?></ul></div>
                    </div>
                <?php else: ?>
                    <p class="text-sm text-slate-500">Пока мало данных: как только появятся заказы, здесь сложится картина внимания к позициям.</p>
                <?php endif; ?>
            </div>
        </section>

        <!-- Checkout conversion -->
        <section class="space-y-6">
            <h3 class="text-xl font-semibold tracking-tight text-slate-100">Конверсия оформления</h3>
            <div class="rounded-xl bg-gray-900/60 border border-gray-800 p-6 card-motion">
                <?php if ($checkoutConversion['started'] > 0 || $checkoutConversion['completed'] > 0): ?>
                    <div class="flex flex-wrap gap-6">
                        <div><span class="text-slate-500 text-sm">Начали оформление</span><div class="text-xl font-semibold text-slate-100"><?= (int)$checkoutConversion['started'] ?></div></div>
                        <div><span class="text-slate-500 text-sm">Завершили заказ</span><div class="text-xl font-semibold text-emerald-400"><?= (int)$checkoutConversion['completed'] ?></div></div>
                        <div><span class="text-slate-500 text-sm">Конверсия</span><div class="text-xl font-semibold text-slate-100"><?= $checkoutConversion['conversion_pct'] !== null ? (float)$checkoutConversion['conversion_pct'] . '%' : '—' ?></div></div>
                    </div>
                    <p class="text-xs text-slate-500 mt-3"><?= e($checkoutConversion['recommendation_text']) ?></p>
                <?php else: ?>
                    <p class="text-sm text-slate-500"><?= e($checkoutConversion['recommendation_text']) ?></p>
                <?php endif; ?>
            </div>
        </section>

        <!-- Table turnover -->
        <section class="space-y-6">
            <h3 class="text-xl font-semibold tracking-tight text-slate-100">Столы: нагрузка</h3>
            <div class="rounded-xl bg-gray-900/60 border border-gray-800 p-6 card-motion">
                <?php if ($tableTurnoverRev['has_data'] && !empty($tableTurnoverRev['tables'])): ?>
                    <p class="text-slate-200 font-medium"><?= e($tableTurnoverRev['busiest_table']) ?></p>
                    <ul class="mt-2 space-y-1 text-sm text-slate-300"><?php foreach (array_slice($tableTurnoverRev['tables'], 0, 5) as $t): ?><li><?= e($t['name']) ?> — <?= (int)$t['orders_count'] ?> заказов</li><?php endforeach; ?></ul>
                    <p class="text-xs text-slate-500 mt-2"><?= e($tableTurnoverRev['recommendation_text']) ?></p>
                <?php else: ?>
                    <p class="text-sm text-slate-500">Когда появятся заказы со столов, здесь покажем самые загруженные места.</p>
                <?php endif; ?>
            </div>
        </section>

        <?php if ($benchmarkRev['available']): ?>
        <section class="space-y-6">
            <h3 class="text-xl font-semibold tracking-tight text-slate-100">Сравнение со схожими точками</h3>
            <div class="rounded-xl bg-gray-900/60 border border-gray-800 p-6 card-motion">
                <div class="grid grid-cols-2 gap-4"><div><span class="text-slate-500 text-sm">Ваш средний чек</span><div class="text-lg font-semibold text-slate-100"><?= $benchmarkRev['your_aov'] !== null ? number_format($benchmarkRev['your_aov'], 0) . ' ₽' : '—' ?></div></div><div><span class="text-slate-500 text-sm">Схожие рестораны</span><div class="text-lg font-semibold text-emerald-400"><?= $benchmarkRev['peer_aov'] !== null ? number_format($benchmarkRev['peer_aov'], 0) . ' ₽' : '—' ?></div></div></div>
                <?php if ($benchmarkRev['recommendation_text'] !== ''): ?><p class="text-xs text-slate-500 mt-2"><?= e($benchmarkRev['recommendation_text']) ?></p><?php endif; ?>
            </div>
        </section>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</main>

<?php if (!$trialRequiresUpgrade): ?>
<script>
(function() {
    const labels = <?= json_encode($chartLabels, JSON_UNESCAPED_UNICODE) ?>;
    const data = <?= json_encode($chartValues, JSON_UNESCAPED_UNICODE) ?>;
    const ctx = document.getElementById('ordersChart');
    if (!ctx) return;
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'Заказы',
                data: data,
                backgroundColor: 'rgba(52, 211, 153, 0.4)',
                borderColor: 'rgb(52, 211, 153)',
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: { beginAtZero: true }
            }
        }
    });
})();
</script>
<?php endif; ?>
<script src="/assets/js/motion.js"></script>
<?php if (is_demo_mode()): ?>
<script>(function(){var k='demo_pages_visited';var v=[];try{v=JSON.parse(sessionStorage.getItem(k)||'[]');}catch(e){}var p=location.pathname;if(v.indexOf(p)===-1){v.push(p);try{sessionStorage.setItem(k,JSON.stringify(v));}catch(e){}}})();</script>
<?php endif; ?>
</body>
</html>
