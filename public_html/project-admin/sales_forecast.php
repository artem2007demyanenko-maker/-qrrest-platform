<?php
/**
 * Sales pipeline forecast: total leads, active deals, forecast MRR, closed MRR, active deals table, optional chart.
 */

$rid = bin2hex(random_bytes(4));
set_exception_handler(function (Throwable $e) use ($rid) {
    error_log('STABILITY_ERROR sales_forecast.php rid=' . $rid . ' ' . $e->getMessage());
    if (!headers_sent()) {
        http_response_code(500);
    }
    echo '<h1>Ошибка</h1><p>Попробуйте позже.</p><p style="font-size:11px;color:#666">RID: ' . htmlspecialchars($rid) . '</p>';
    exit;
});

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/sales_forecast_repo.php';

if (!function_exists('e')) {
    function e($v): string {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

require_login();
require_role(['project_owner']);

$summary = sales_forecast_summary();
$activeDeals = sales_forecast_active_deals(200);
$monthlyForecast = sales_forecast_monthly_forecast();

$statusLabels = [
    'contacted'      => 'Связались',
    'demo_scheduled' => 'Демо запланировано',
    'negotiation'    => 'Переговоры',
];

$appName = 'QR-Rest Cloud';
$configPath = __DIR__ . '/../../app/config.php';
if (is_file($configPath)) {
    $cfg = require $configPath;
    if (!empty($cfg['app']['name'])) {
        $appName = $cfg['app']['name'];
    }
}
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Sales Forecast — <?= e($appName) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?= brand_head_tags() ?>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="/assets/css/motion.css">
    <link rel="stylesheet" href="/assets/css/polish.css">
    <style>
        .badge-contacted { background: rgba(59,130,246,0.2); border: 1px solid rgba(59,130,246,0.5); color: #93C5FD; }
        .badge-demo_scheduled { background: rgba(99,102,241,0.2); border: 1px solid rgba(99,102,241,0.5); color: #A5B4FC; }
        .badge-negotiation { background: rgba(234,179,8,0.2); border: 1px solid rgba(234,179,8,0.5); color: #FDE047; }
    </style>
</head>
<body class="min-h-screen bg-slate-950 text-slate-50">
<div class="min-h-screen bg-gradient-to-br from-slate-950 via-slate-950 to-slate-900">
    <div class="max-w-6xl mx-auto px-4 py-8 space-y-8">

        <header class="flex flex-wrap items-center justify-between gap-4">
            <div class="space-y-1">
                <h1 class="text-xl font-semibold tracking-tight text-slate-50">Sales Forecast</h1>
                <p class="text-sm text-gray-400">Прогноз выручки по активным сделкам</p>
            </div>
            <div class="flex items-center gap-2">
                <a href="/project-admin/leads.php" class="text-sm text-slate-400 hover:text-slate-200">Лиды</a>
                <a href="/project-admin/lead_scoring.php" class="text-sm text-amber-300 hover:text-amber-200">Scoring</a>
                <a href="/project-admin/diagnostics.php" class="text-sm text-slate-400 hover:text-slate-200">Diagnostics</a>
                <a href="/project-admin/" class="text-sm text-slate-400 hover:text-slate-200">← В панель</a>
            </div>
        </header>

        <!-- Summary KPI cards -->
        <section class="space-y-6">
            <h2 class="text-xl font-semibold tracking-tight text-slate-100">Summary</h2>
            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-6">
                <div class="rounded-xl border border-gray-800 bg-[#121826] shadow-lg p-5 flex items-start gap-3 card-motion">
                    <div class="w-10 h-10 rounded-lg bg-slate-500/20 flex items-center justify-center flex-shrink-0 text-slate-300">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"/></svg>
                    </div>
                    <div class="min-w-0"><div class="text-2xl font-bold text-slate-100"><?= (int)$summary['total_leads'] ?></div><div class="text-xs text-gray-400 uppercase tracking-wide mt-0.5">Total leads</div></div>
                </div>
                <div class="rounded-xl border border-gray-800 bg-[#121826] shadow-lg p-5 flex items-start gap-3 card-motion">
                    <div class="w-10 h-10 rounded-lg bg-sky-500/20 flex items-center justify-center flex-shrink-0 text-sky-400">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/></svg>
                    </div>
                    <div class="min-w-0"><div class="text-2xl font-bold text-sky-300"><?= (int)$summary['active_deals'] ?></div><div class="text-xs text-gray-400 uppercase tracking-wide mt-0.5">Active deals</div></div>
                </div>
                <div class="rounded-xl border border-gray-800 bg-[#121826] shadow-lg p-5 flex items-start gap-3 card-motion">
                    <div class="w-10 h-10 rounded-lg bg-emerald-500/20 flex items-center justify-center flex-shrink-0 text-emerald-400">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>
                    </div>
                    <div class="min-w-0"><div class="text-2xl font-bold text-emerald-400"><?= number_format($summary['forecast_mrr'], 0, '.', ' ') ?></div><div class="text-xs text-gray-400 uppercase tracking-wide mt-0.5">Forecast MRR</div></div>
                </div>
                <div class="rounded-xl border border-gray-800 bg-[#121826] shadow-lg p-5 flex items-start gap-3 card-motion">
                    <div class="w-10 h-10 rounded-lg bg-emerald-500/20 flex items-center justify-center flex-shrink-0 text-emerald-300">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    </div>
                    <div class="min-w-0"><div class="text-2xl font-bold text-emerald-300"><?= number_format($summary['closed_mrr'], 0, '.', ' ') ?></div><div class="text-xs text-gray-400 uppercase tracking-wide mt-0.5">Closed MRR</div></div>
                </div>
                <div class="rounded-xl border border-gray-800 bg-[#121826] shadow-lg p-5 flex items-start gap-3 card-motion">
                    <div class="w-10 h-10 rounded-lg bg-green-500/20 flex items-center justify-center flex-shrink-0 text-green-400">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    </div>
                    <div class="min-w-0"><div class="text-2xl font-bold text-green-300"><?= (int)$summary['won_deals'] ?></div><div class="text-xs text-gray-400 uppercase tracking-wide mt-0.5">Won deals</div></div>
                </div>
                <div class="rounded-xl border border-gray-800 bg-[#121826] shadow-lg p-5 flex items-start gap-3 card-motion">
                    <div class="w-10 h-10 rounded-lg bg-red-500/20 flex items-center justify-center flex-shrink-0 text-red-400">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    </div>
                    <div class="min-w-0"><div class="text-2xl font-bold text-red-300"><?= (int)$summary['lost_deals'] ?></div><div class="text-xs text-gray-400 uppercase tracking-wide mt-0.5">Lost deals</div></div>
                </div>
            </div>
        </section>

        <!-- Optional chart -->
        <?php if (count($monthlyForecast) > 0): ?>
        <section class="space-y-6">
            <h2 class="text-xl font-semibold tracking-tight text-slate-100">Forecast MRR по месяцам</h2>
            <div class="rounded-xl bg-gray-900/60 border border-gray-800 p-6 card-motion">
                <div class="h-64">
                    <canvas id="forecastChart"></canvas>
                </div>
            </div>
            <script>
            (function(){
                var ctx = document.getElementById('forecastChart');
                if (!ctx) return;
                var data = <?= json_encode(array_column($monthlyForecast, 'mrr')) ?>;
                var labels = <?= json_encode(array_column($monthlyForecast, 'month')) ?>;
                new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: labels,
                        datasets: [{
                            label: 'Expected MRR',
                            data: data,
                            backgroundColor: 'rgba(52, 211, 153, 0.3)',
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
        </section>
        <?php endif; ?>

        <!-- Active deals table -->
        <section class="space-y-6">
            <h2 class="text-xl font-semibold tracking-tight text-slate-100">Active deals</h2>
            <?php if (empty($activeDeals)): ?>
                <div class="rounded-xl border border-gray-800 bg-[#121826] shadow-lg px-6 py-16 text-center">
                    <svg class="w-14 h-14 mx-auto text-gray-500 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                    <div class="text-lg font-medium text-slate-200 mb-1">Нет активных сделок</div>
                    <div class="text-sm text-gray-400 max-w-sm mx-auto mb-6">Сделки в стадиях contacted, demo_scheduled, negotiation появятся здесь.</div>
                    <a href="/project-admin/leads.php" class="inline-block px-4 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-medium">К лидам</a>
                </div>
            <?php else: ?>
                <div class="overflow-x-auto rounded-xl border border-gray-800 bg-[#121826] shadow-lg">
                    <table class="table-saas w-full text-sm">
                        <thead>
                            <tr class="text-xs uppercase tracking-wide text-gray-400">
                                <th>Ресторан</th>
                                <th>Город</th>
                                <th>Stage</th>
                                <th>Expected MRR</th>
                                <th>Создан</th>
                                <th>Действие</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($activeDeals as $row): ?>
                                <?php
                                $st = $row['status'] ?? '';
                                $badgeClass = 'badge-' . preg_replace('/[^a-z0-9_]/', '', $st);
                                $createdAt = !empty($row['created_at']) ? date('d.m.Y H:i', strtotime($row['created_at'])) : '—';
                                ?>
                                <tr>
                                    <td class="text-slate-100 font-medium"><?= e($row['restaurant_name'] ?? '—') ?></td>
                                    <td class="text-gray-400"><?= e($row['city'] ?? '—') ?></td>
                                    <td>
                                        <span class="px-2 py-1 rounded-lg border text-xs font-medium <?= $badgeClass ?>"><?= e($statusLabels[$st] ?? $st) ?></span>
                                    </td>
                                    <td class="text-slate-200"><?= $row['expected_mrr'] !== null ? number_format($row['expected_mrr'], 0, '.', ' ') : '—' ?></td>
                                    <td class="text-gray-400"><?= e($createdAt) ?></td>
                                    <td>
                                        <a href="/project-admin/lead_view.php?id=<?= (int)$row['id'] ?>" class="text-indigo-400 hover:text-indigo-300 text-sm font-medium">Открыть</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

    </div>
</div>
</body>
</html>
