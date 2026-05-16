<?php
/**
 * SaaS Business Intelligence Dashboard. Platform owner (project_owner) only.
 * Returns 403 for non-admin. Read-only analytics; does not modify billing.
 */

$config     = require __DIR__ . '/../../app/config.php';
$mainDomain = $config['app']['main_domain'] ?? 'localhost';
$protocol   = $config['app']['protocol'] ?? 'http';

$host = $_SERVER['HTTP_HOST'] ?? '';
$host = preg_replace('/:\d+$/', '', $host);
$isMainHost = (strtolower($host) === strtolower($mainDomain)) || (strtolower($host) === strtolower('www.' . $mainDomain));
if (!$isMainHost) {
    $uri = $_SERVER['REQUEST_URI'] ?? '/project-admin/saas_dashboard.php';
    header('Location: ' . $protocol . '://' . $mainDomain . $uri);
    exit;
}

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/schema_guard.php';

require_login();

if (!function_exists('is_project_owner') || !is_project_owner()) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>403 Forbidden</title></head><body><h1>403 Forbidden</h1><p>Platform owner only.</p></body></html>';
    exit;
}

require_once __DIR__ . '/../../app/saas_metrics.php';

if (!function_exists('e')) {
    function e($v): string {
        return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    }
}

$mrr       = get_mrr_metrics();
$growth    = get_platform_growth_metrics();
$churn     = get_saas_churn_metrics();
$adoption  = get_feature_adoption_metrics();
$insights  = get_platform_insights();
$revenue30 = get_saas_revenue_by_day_30();
$newRest30 = get_saas_new_restaurants_by_day_30();

$appName = $config['app']['name'] ?? 'QR-Rest Cloud';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>SaaS Metrics — <?= e($appName) ?></title>
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
                Platform owner • SaaS BI
            </div>
            <h1 class="text-2xl sm:text-3xl font-semibold text-slate-50 mb-1">SaaS Metrics</h1>
            <p class="text-sm text-slate-400">MRR, churn, growth, feature adoption. Read-only.</p>
        </div>
        <div class="flex items-center gap-2">
            <a href="/project-admin/" class="inline-flex items-center px-3 py-1.5 rounded-xl bg-slate-800 border border-slate-700 text-sm text-slate-200 hover:border-sky-500 hover:text-sky-200 transition">← Dashboard</a>
        </div>
    </header>

    <!-- SaaS Overview -->
    <section class="rounded-xl border border-gray-800 bg-[#121826] shadow-xl p-5 card-motion mb-6">
        <h2 class="text-lg font-semibold text-slate-50 mb-4">SaaS Overview</h2>
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-4">
            <div class="rounded-lg bg-slate-900/60 border border-slate-800 p-4">
                <div class="text-xs text-slate-400">MRR</div>
                <div class="text-xl font-semibold text-emerald-300"><?= number_format($mrr['mrr'], 0, '.', ' ') ?></div>
            </div>
            <div class="rounded-lg bg-slate-900/60 border border-slate-800 p-4">
                <div class="text-xs text-slate-400">ARR</div>
                <div class="text-xl font-semibold text-emerald-300"><?= number_format($mrr['arr'], 0, '.', ' ') ?></div>
            </div>
            <div class="rounded-lg bg-slate-900/60 border border-slate-800 p-4">
                <div class="text-xs text-slate-400">Active subscriptions</div>
                <div class="text-xl font-semibold text-slate-50"><?= (int) $mrr['active_subscriptions'] ?></div>
            </div>
            <div class="rounded-lg bg-slate-900/60 border border-slate-800 p-4">
                <div class="text-xs text-slate-400">Trial users</div>
                <div class="text-xl font-semibold text-sky-300"><?= (int) $mrr['trial_users'] ?></div>
            </div>
            <div class="rounded-lg bg-slate-900/60 border border-slate-800 p-4">
                <div class="text-xs text-slate-400">Trial conversion rate</div>
                <div class="text-xl font-semibold text-amber-300"><?= number_format($mrr['trial_conversion_rate'], 1) ?>%</div>
            </div>
        </div>
    </section>

    <!-- Growth Metrics -->
    <section class="rounded-xl border border-gray-800 bg-[#121826] shadow-xl p-5 card-motion mb-6">
        <h2 class="text-lg font-semibold text-slate-50 mb-4">Growth Metrics</h2>
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-4">
            <div class="rounded-lg bg-slate-900/60 border border-slate-800 p-4">
                <div class="text-xs text-slate-400">Total restaurants</div>
                <div class="text-xl font-semibold text-slate-50"><?= (int) $growth['restaurants_total'] ?></div>
            </div>
            <div class="rounded-lg bg-slate-900/60 border border-slate-800 p-4">
                <div class="text-xs text-slate-400">New (30 days)</div>
                <div class="text-xl font-semibold text-emerald-300"><?= (int) $growth['restaurants_last_30_days'] ?></div>
            </div>
            <div class="rounded-lg bg-slate-900/60 border border-slate-800 p-4">
                <div class="text-xs text-slate-400">Growth rate</div>
                <div class="text-xl font-semibold text-sky-300"><?= number_format($growth['restaurants_growth_rate'], 1) ?>%</div>
            </div>
            <div class="rounded-lg bg-slate-900/60 border border-slate-800 p-4">
                <div class="text-xs text-slate-400">Orders (30 days)</div>
                <div class="text-xl font-semibold text-slate-50"><?= (int) $growth['orders_last_30_days'] ?></div>
            </div>
            <div class="rounded-lg bg-slate-900/60 border border-slate-800 p-4">
                <div class="text-xs text-slate-400">Revenue (30 days)</div>
                <div class="text-xl font-semibold text-emerald-300"><?= number_format($growth['revenue_last_30_days'], 0, '.', ' ') ?></div>
            </div>
        </div>
    </section>

    <!-- Charts -->
    <section class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
        <div class="rounded-xl border border-gray-800 bg-[#121826] shadow-xl p-5 card-motion">
            <h2 class="text-lg font-semibold text-slate-50 mb-4">Revenue last 30 days</h2>
            <div class="h-64">
                <canvas id="chart-revenue-30"></canvas>
            </div>
        </div>
        <div class="rounded-xl border border-gray-800 bg-[#121826] shadow-xl p-5 card-motion">
            <h2 class="text-lg font-semibold text-slate-50 mb-4">New restaurants per day (30 days)</h2>
            <div class="h-64">
                <canvas id="chart-new-rest-30"></canvas>
            </div>
        </div>
    </section>

    <!-- Churn Risk -->
    <section class="rounded-xl border border-gray-800 bg-[#121826] shadow-xl p-5 card-motion mb-6">
        <h2 class="text-lg font-semibold text-slate-50 mb-4">Churn Risk</h2>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div class="rounded-lg bg-slate-900/60 border border-slate-800 p-4">
                <div class="text-xs text-slate-400">Restaurants at risk</div>
                <div class="text-xl font-semibold text-amber-300"><?= (int) $churn['at_risk_restaurants'] ?></div>
            </div>
            <div class="rounded-lg bg-slate-900/60 border border-slate-800 p-4">
                <div class="text-xs text-slate-400">Inactive 14 days</div>
                <div class="text-xl font-semibold text-slate-50"><?= (int) $churn['inactive_restaurants_14_days'] ?></div>
            </div>
            <div class="rounded-lg bg-slate-900/60 border border-slate-800 p-4">
                <div class="text-xs text-slate-400">Low health score</div>
                <div class="text-xl font-semibold text-rose-300"><?= (int) $churn['low_health_restaurants'] ?></div>
            </div>
        </div>
    </section>

    <!-- Feature Adoption -->
    <section class="rounded-xl border border-gray-800 bg-[#121826] shadow-xl p-5 card-motion mb-6">
        <h2 class="text-lg font-semibold text-slate-50 mb-4">Feature Adoption</h2>
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
            <div class="rounded-lg bg-slate-900/60 border border-slate-800 p-4">
                <div class="text-xs text-slate-400">QR orders</div>
                <div class="text-xl font-semibold text-slate-50"><?= (int) $adoption['qr_orders_enabled'] ?></div>
            </div>
            <div class="rounded-lg bg-slate-900/60 border border-slate-800 p-4">
                <div class="text-xs text-slate-400">Upsell rules</div>
                <div class="text-xl font-semibold text-sky-300"><?= (int) $adoption['upsell_rules_active'] ?></div>
            </div>
            <div class="rounded-lg bg-slate-900/60 border border-slate-800 p-4">
                <div class="text-xs text-slate-400">CRM campaigns</div>
                <div class="text-xl font-semibold text-slate-50"><?= (int) $adoption['crm_campaigns_created'] ?></div>
            </div>
            <div class="rounded-lg bg-slate-900/60 border border-slate-800 p-4">
                <div class="text-xs text-slate-400">Combos</div>
                <div class="text-xl font-semibold text-emerald-300"><?= (int) $adoption['combos_created'] ?></div>
            </div>
        </div>
    </section>

    <!-- Platform Insights -->
    <section class="rounded-xl border border-gray-800 bg-[#121826] shadow-xl p-5 card-motion">
        <h2 class="text-lg font-semibold text-slate-50 mb-4">Platform Insights</h2>
        <?php if (empty($insights)): ?>
            <p class="text-slate-400 text-sm">No insights yet.</p>
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
        <?= e($appName) ?> • SaaS Metrics (internal)
    </footer>
</div>

<script>
(function() {
    var revenue30 = <?= json_encode($revenue30) ?>;
    var newRest30 = <?= json_encode($newRest30) ?>;
    var labels = Object.keys(revenue30 || {});

    if (labels.length && document.getElementById('chart-revenue-30')) {
        new Chart(document.getElementById('chart-revenue-30'), {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Revenue',
                    data: labels.map(function(d) { return (revenue30 || {})[d] || 0; }),
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

    if (labels.length && document.getElementById('chart-new-rest-30')) {
        new Chart(document.getElementById('chart-new-rest-30'), {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: 'New restaurants',
                    data: labels.map(function(d) { return (newRest30 || {})[d] || 0; }),
                    backgroundColor: 'rgba(99, 102, 241, 0.6)',
                    borderColor: 'rgb(99, 102, 241)',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { ticks: { color: '#94a3b8', maxTicksLimit: 12 } },
                    y: { ticks: { color: '#94a3b8', stepSize: 1 } }
                }
            }
        });
    }
})();
</script>
</body>
</html>
