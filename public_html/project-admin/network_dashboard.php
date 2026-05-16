<?php
/**
 * Network Analytics Dashboard. Internal only — visible to project_admin (platform owner) only.
 * Returns 403 for non-admin users.
 */

$config     = require __DIR__ . '/../../app/config.php';
$mainDomain = $config['app']['main_domain'] ?? 'localhost';
$protocol   = $config['app']['protocol'] ?? 'http';

$host = $_SERVER['HTTP_HOST'] ?? '';
$host = preg_replace('/:\d+$/', '', $host);
$isMainHost = (strtolower($host) === strtolower($mainDomain)) || (strtolower($host) === strtolower('www.' . $mainDomain));
if (!$isMainHost) {
    $uri = $_SERVER['REQUEST_URI'] ?? '/project-admin/network_dashboard.php';
    header('Location: ' . $protocol . '://' . $mainDomain . $uri);
    exit;
}

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/schema_guard.php';

require_login();

if (!function_exists('is_project_owner') || !is_project_owner()) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>403 Доступ запрещён</title></head><body><h1>403 Доступ запрещён</h1><p>Только владелец платформы.</p></body></html>';
    exit;
}

require_once __DIR__ . '/../../app/network_analytics.php';

if (!function_exists('e')) {
    function e($v): string {
        return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    }
}

$overview   = get_network_overview();
$trends     = get_network_trends();
$topRest    = get_top_restaurants_by_revenue(10);
$perfTable  = get_restaurant_performance_table();
$insights   = get_network_growth_summary();

$appName = $config['app']['name'] ?? 'QR-Rest Cloud';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Сетевая аналитика — <?= e($appName) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?= brand_head_tags() ?>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="min-h-screen bg-slate-950 text-slate-50">
<div class="max-w-6xl mx-auto px-4 py-6 sm:py-8">

    <header class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-slate-900/80 border border-slate-700 text-[11px] text-slate-300 mb-2">
                Владелец платформы • Сетевая аналитика
            </div>
            <h1 class="text-2xl sm:text-3xl font-semibold text-slate-50 mb-1">Сетевая аналитика</h1>
            <p class="text-sm text-slate-400">Метрики по всем ресторанам. Только для внутреннего использования.</p>
        </div>
        <div class="flex items-center gap-2">
            <a href="/project-admin/" class="inline-flex items-center px-3 py-1.5 rounded-xl bg-slate-800 border border-slate-700 text-sm text-slate-200 hover:border-sky-500 hover:text-sky-200 transition">← Панель</a>
        </div>
    </header>

    <!-- Network Overview -->
    <section class="rounded-xl border border-gray-800 bg-[#121826] shadow-xl p-5 card-motion mb-6">
        <h2 class="text-lg font-semibold text-slate-50 mb-4">Обзор сети</h2>
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-7 gap-4 text-center sm:text-left">
            <div>
                <div class="text-xs text-slate-400">Всего ресторанов</div>
                <div class="text-xl font-semibold text-slate-50"><?= (int) $overview['total_restaurants'] ?></div>
            </div>
            <div>
                <div class="text-xs text-slate-400">Активных (7 дн.)</div>
                <div class="text-xl font-semibold text-emerald-300"><?= (int) $overview['active_restaurants_last_7_days'] ?></div>
            </div>
            <div>
                <div class="text-xs text-slate-400">Заказы (7 дн.)</div>
                <div class="text-xl font-semibold text-slate-50"><?= (int) $overview['total_orders_7_days'] ?></div>
            </div>
            <div>
                <div class="text-xs text-slate-400">Выручка (7 дн.)</div>
                <div class="text-xl font-semibold text-emerald-300"><?= number_format($overview['total_revenue_7_days'], 0, '.', ' ') ?></div>
            </div>
            <div>
                <div class="text-xs text-slate-400">Средний чек</div>
                <div class="text-xl font-semibold text-slate-50"><?= number_format($overview['average_order_network'], 2) ?></div>
            </div>
            <div>
                <div class="text-xs text-slate-400">Конв. оформления</div>
                <div class="text-xl font-semibold text-sky-300"><?= number_format($overview['average_checkout_conversion'], 1) ?>%</div>
            </div>
            <div>
                <div class="text-xs text-slate-400">Возврат гостей</div>
                <div class="text-xl font-semibold text-amber-300"><?= number_format($overview['average_return_rate'], 1) ?>%</div>
            </div>
        </div>
    </section>

    <!-- Revenue Trends Chart -->
    <section class="rounded-xl border border-gray-800 bg-[#121826] shadow-xl p-5 card-motion mb-6">
        <h2 class="text-lg font-semibold text-slate-50 mb-4">Динамика выручки (30 дней)</h2>
        <div class="h-64">
            <canvas id="revenue-trends-chart"></canvas>
        </div>
    </section>

    <!-- Top Restaurants by Revenue -->
    <section class="rounded-xl border border-gray-800 bg-[#121826] shadow-xl p-5 card-motion mb-6">
        <h2 class="text-lg font-semibold text-slate-50 mb-4">Топ ресторанов по выручке (30 дн.)</h2>
        <?php if (empty($topRest)): ?>
            <p class="text-slate-400 text-sm">Пока нет данных.</p>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-slate-400 border-b border-slate-700">
                            <th class="pb-2 pr-4">Ресторан</th>
                            <th class="pb-2 pr-4">Выручка (30 дн.)</th>
                            <th class="pb-2 pr-4">Заказы</th>
                            <th class="pb-2">Средний чек</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($topRest as $row): ?>
                            <tr class="border-b border-slate-800/80">
                                <td class="py-2 pr-4 text-slate-50"><?= e($row['restaurant_name']) ?></td>
                                <td class="py-2 pr-4 text-emerald-300"><?= number_format($row['revenue_last_30_days'], 0, '.', ' ') ?></td>
                                <td class="py-2 pr-4 text-slate-200"><?= (int) $row['orders_last_30_days'] ?></td>
                                <td class="py-2 text-slate-200"><?= number_format($row['average_order'], 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <!-- Restaurant Performance Table -->
    <section class="rounded-xl border border-gray-800 bg-[#121826] shadow-xl p-5 card-motion mb-6">
        <h2 class="text-lg font-semibold text-slate-50 mb-4">Эффективность ресторанов (7 дн.)</h2>
        <?php if (empty($perfTable)): ?>
            <p class="text-slate-400 text-sm">Нет ресторанов или данных.</p>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-slate-400 border-b border-slate-700">
                            <th class="pb-2 pr-4">Ресторан</th>
                            <th class="pb-2 pr-4">Заказы</th>
                            <th class="pb-2 pr-4">Выручка</th>
                            <th class="pb-2 pr-4">Средний чек</th>
                            <th class="pb-2 pr-4">Конверсия</th>
                            <th class="pb-2 pr-4">Возврат</th>
                            <th class="pb-2">Здоровье</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($perfTable as $row): ?>
                            <tr class="border-b border-slate-800/80">
                                <td class="py-2 pr-4 text-slate-50"><?= e($row['restaurant_name']) ?></td>
                                <td class="py-2 pr-4 text-slate-200"><?= (int) $row['orders_last_7_days'] ?></td>
                                <td class="py-2 pr-4 text-emerald-300"><?= number_format($row['revenue_last_7_days'], 0, '.', ' ') ?></td>
                                <td class="py-2 pr-4 text-slate-200"><?= number_format($row['average_order_value'], 2) ?></td>
                                <td class="py-2 pr-4 text-sky-300"><?= number_format($row['checkout_conversion'], 1) ?>%</td>
                                <td class="py-2 pr-4 text-amber-300"><?= number_format($row['return_rate'], 1) ?>%</td>
                                <td class="py-2"><span class="font-semibold text-slate-50"><?= (int) $row['health_score'] ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <!-- Network Insights -->
    <section class="rounded-xl border border-gray-800 bg-[#121826] shadow-xl p-5 card-motion">
        <h2 class="text-lg font-semibold text-slate-50 mb-4">Инсайты по сети</h2>
        <?php if (empty($insights)): ?>
            <p class="text-slate-400 text-sm">Пока нет инсайтов.</p>
        <?php else: ?>
            <ul class="space-y-2 text-sm text-slate-300">
                <?php foreach ($insights as $line): ?>
                    <li class="flex items-start gap-2">
                        <span class="text-emerald-400 mt-0.5">•</span>
                        <span><?= e($line) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <footer class="mt-6 py-3 text-[11px] text-slate-500">
        <?= e($appName) ?> • Сетевая аналитика (внутренняя)
    </footer>
</div>

<script>
(function() {
    var trends = <?= json_encode($trends) ?>;
    var labels = Object.keys(trends.revenue_last_30_days_by_day || {});
    var revenue = labels.map(function(d) { return (trends.revenue_last_30_days_by_day || {})[d] || 0; });
    var ctx = document.getElementById('revenue-trends-chart');
    if (ctx && labels.length) {
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Выручка по дням',
                    data: revenue,
                    borderColor: 'rgb(16, 185, 129)',
                    backgroundColor: 'rgba(16, 185, 129, 0.1)',
                    fill: true,
                    tension: 0.2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { ticks: { color: '#94a3b8', maxTicksLimit: 12 } },
                    y: { ticks: { color: '#94a3b8' } }
                }
            }
        });
    }
})();
</script>
</body>
</html>
