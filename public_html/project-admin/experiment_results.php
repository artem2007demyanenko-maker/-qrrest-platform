<?php
/**
 * Experiment results: variant A vs B metrics and Chart.js comparison. Platform owner only.
 */

$config     = require __DIR__ . '/../../app/config.php';
$mainDomain = $config['app']['main_domain'] ?? 'localhost';
$protocol   = $config['app']['protocol'] ?? 'http';

$host = $_SERVER['HTTP_HOST'] ?? '';
$host = preg_replace('/:\d+$/', '', $host);
$isMainHost = (strtolower($host) === strtolower($mainDomain)) || (strtolower($host) === strtolower('www.' . $mainDomain));
if (!$isMainHost) {
    $uri = $_SERVER['REQUEST_URI'] ?? '/project-admin/experiment_results.php';
    header('Location: ' . $protocol . '://' . $mainDomain . ($_GET['id'] ? $uri : '/project-admin/experiments_dashboard.php'));
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

require_once __DIR__ . '/../../app/experiments.php';

if (!function_exists('e')) {
    function e($v): string {
        return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    }
}

$experimentId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($experimentId <= 0) {
    header('Location: /project-admin/experiments_dashboard.php');
    exit;
}

$experiments = get_experiments_list();
$experiment = null;
foreach ($experiments as $e) {
    if ((int) $e['id'] === $experimentId) {
        $experiment = $e;
        break;
    }
}
if (!$experiment) {
    header('Location: /project-admin/experiments_dashboard.php');
    exit;
}

$results = get_experiment_results($experimentId);
$appName = $config['app']['name'] ?? 'QR-Rest Cloud';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Results: <?= e($experiment['name']) ?> — <?= e($appName) ?></title>
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
                Platform owner • Experiment results
            </div>
            <h1 class="text-2xl sm:text-3xl font-semibold text-slate-50 mb-1"><?= e($experiment['name']) ?></h1>
            <p class="text-sm text-slate-400">Variant A vs B — revenue and conversion</p>
        </div>
        <div class="flex items-center gap-2">
            <a href="/project-admin/experiments_dashboard.php" class="inline-flex items-center px-3 py-1.5 rounded-xl bg-slate-800 border border-slate-700 text-sm text-slate-200 hover:border-sky-500 hover:text-sky-200 transition">← Experiments</a>
        </div>
    </header>

    <section class="rounded-xl border border-gray-800 bg-[#121826] shadow-xl p-5 mb-6">
        <h2 class="text-lg font-semibold text-slate-50 mb-4">Variant metrics</h2>
        <div class="grid grid-cols-2 gap-4 mb-6">
            <div class="rounded-lg bg-slate-900/60 border border-slate-800 p-4">
                <div class="text-xs text-slate-400 mb-1">Variant A — restaurants</div>
                <div class="text-xl font-semibold text-slate-50"><?= (int) $results['variant_a_restaurants'] ?></div>
                <div class="text-xs text-slate-500 mt-2">Revenue: <?= number_format($results['variant_a_revenue'], 0, '.', ' ') ?></div>
                <div class="text-xs text-slate-500">Conversion: <?= number_format($results['variant_a_conversion'], 1) ?>%</div>
            </div>
            <div class="rounded-lg bg-slate-900/60 border border-slate-800 p-4">
                <div class="text-xs text-slate-400 mb-1">Variant B — restaurants</div>
                <div class="text-xl font-semibold text-slate-50"><?= (int) $results['variant_b_restaurants'] ?></div>
                <div class="text-xs text-slate-500 mt-2">Revenue: <?= number_format($results['variant_b_revenue'], 0, '.', ' ') ?></div>
                <div class="text-xs text-slate-500">Conversion: <?= number_format($results['variant_b_conversion'], 1) ?>%</div>
            </div>
        </div>
        <?php if ($results['winner'] !== ''): ?>
            <div class="rounded-lg border border-emerald-700 bg-emerald-900/20 px-4 py-2 text-sm text-emerald-200">
                Winner: <strong>Variant <?= e($results['winner']) ?></strong> (conversion difference &gt; 5%)
            </div>
        <?php else: ?>
            <div class="rounded-lg border border-slate-700 bg-slate-900/40 px-4 py-2 text-sm text-slate-400">
                No clear winner yet (conversion difference ≤ 5%).
            </div>
        <?php endif; ?>
    </section>

    <section class="rounded-xl border border-gray-800 bg-[#121826] shadow-xl p-5 mb-6">
        <h2 class="text-lg font-semibold text-slate-50 mb-4">Revenue comparison</h2>
        <div class="h-64">
            <canvas id="revenueChart"></canvas>
        </div>
    </section>

    <section class="rounded-xl border border-gray-800 bg-[#121826] shadow-xl p-5 mb-6">
        <h2 class="text-lg font-semibold text-slate-50 mb-4">Conversion comparison</h2>
        <div class="h-64">
            <canvas id="conversionChart"></canvas>
        </div>
    </section>
</div>

<script>
(function() {
    const revenueData = {
        labels: ['Variant A', 'Variant B'],
        datasets: [{
            label: 'Revenue',
            data: [<?= (float) $results['variant_a_revenue'] ?>, <?= (float) $results['variant_b_revenue'] ?>],
            backgroundColor: ['rgba(59, 130, 246, 0.6)', 'rgba(16, 185, 129, 0.6)'],
            borderColor: ['rgb(59, 130, 246)', 'rgb(16, 185, 129)'],
            borderWidth: 1
        }]
    };
    new Chart(document.getElementById('revenueChart'), {
        type: 'bar',
        data: revenueData,
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                y: { beginAtZero: true, grid: { color: 'rgba(148, 163, 184, 0.15)' }, ticks: { color: '#94a3b8' } },
                x: { grid: { display: false }, ticks: { color: '#94a3b8' } }
            }
        }
    });

    const conversionData = {
        labels: ['Variant A', 'Variant B'],
        datasets: [{
            label: 'Conversion %',
            data: [<?= (float) $results['variant_a_conversion'] ?>, <?= (float) $results['variant_b_conversion'] ?>],
            backgroundColor: ['rgba(59, 130, 246, 0.6)', 'rgba(16, 185, 129, 0.6)'],
            borderColor: ['rgb(59, 130, 246)', 'rgb(16, 185, 129)'],
            borderWidth: 1
        }]
    };
    new Chart(document.getElementById('conversionChart'), {
        type: 'bar',
        data: conversionData,
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                y: { beginAtZero: true, max: 100, grid: { color: 'rgba(148, 163, 184, 0.15)' }, ticks: { color: '#94a3b8' } },
                x: { grid: { display: false }, ticks: { color: '#94a3b8' } }
            }
        }
    });
})();
</script>
</body>
</html>
