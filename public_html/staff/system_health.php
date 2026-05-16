<?php

require_once __DIR__ . '/../../app/bootstrap.php';
$role = function_exists('require_staff_restaurant_access')
    ? require_staff_restaurant_access()
    : require_staff_role(['owner', 'admin', 'staff']);

if (!in_array((string)$role, ['owner', 'admin', 'staff', 'waiter', 'courier'], true)) {
    http_response_code(403);
    echo 'Access denied';
    exit;
}

$pdo = db();
$restaurantId = (int)($currentRestaurant['id'] ?? 0);
$summary = function_exists('operational_health_summary')
    ? operational_health_summary($pdo, $restaurantId, ['include_optional_warnings' => true])
    : [
        'generated_at' => date('Y-m-d H:i:s'),
        'restaurant_id' => $restaurantId,
        'runtime_integrity' => ['db_ready' => $pdo instanceof PDO, 'tenant_safe_context' => $restaurantId > 0],
        'modules' => [],
        'flows' => [],
        'warnings' => [],
        'degraded_modules' => [],
        'degraded_count' => 0,
    ];

$format = strtolower(trim((string)($_GET['format'] ?? '')));
if ($format === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => true,
        'summary' => $summary,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!function_exists('e')) {
    function e($v): string
    {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

$runtime = is_array($summary['runtime_integrity'] ?? null) ? $summary['runtime_integrity'] : [];
$modules = is_array($summary['modules'] ?? null) ? $summary['modules'] : [];
$flows = is_array($summary['flows'] ?? null) ? $summary['flows'] : [];
$warnings = is_array($summary['warnings'] ?? null) ? $summary['warnings'] : [];
$degradedCount = (int)($summary['degraded_count'] ?? 0);
$generatedAt = (string)($summary['generated_at'] ?? date('Y-m-d H:i:s'));
$restaurantName = (string)($currentRestaurant['name'] ?? 'Restaurant');

$manualLinks = [
    ['label' => 'Staff dashboard', 'url' => '/staff/dashboard.php'],
    ['label' => 'Restaurant dashboard', 'url' => '/restaurant/dashboard.php'],
    ['label' => 'Courier', 'url' => '/staff/courier.php'],
    ['label' => 'Kitchen KDS', 'url' => '/staff/kitchen.php'],
    ['label' => 'Orders', 'url' => '/staff/orders.php'],
    ['label' => 'POS', 'url' => '/staff/pos.php'],
    ['label' => 'Floorplan', 'url' => '/staff/floorplan.php'],
    ['label' => 'QR menu', 'url' => '/qr.php'],
    ['label' => 'Order tracking', 'url' => '/order_track.php'],
    ['label' => 'Analytics JSON', 'url' => '/ajax/analytics_summary.php?range=today'],
];
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>System Health — <?= e($restaurantName) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-950 text-slate-50">
<main class="max-w-6xl mx-auto p-4 md:p-6 space-y-4">
    <header class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <div class="text-xs text-slate-400">Operational QA</div>
            <h1 class="text-2xl font-semibold">System health</h1>
            <p class="text-sm text-slate-400 mt-1"><?= e($restaurantName) ?> · restaurant_id=<?= (int)$restaurantId ?></p>
        </div>
        <div class="text-right text-xs text-slate-400">
            <div>Updated: <?= e($generatedAt) ?></div>
            <div class="<?= $degradedCount > 0 ? 'text-amber-300' : 'text-emerald-300' ?>">
                <?= $degradedCount > 0 ? ('Degraded modules: ' . $degradedCount) : 'All core modules OK' ?>
            </div>
            <a class="inline-flex mt-2 rounded-lg border border-slate-700 bg-slate-900/70 px-2 py-1 text-slate-200 hover:bg-slate-800/70" href="/staff/system_health.php?format=json">JSON view</a>
        </div>
    </header>

    <section class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-2 text-xs">
        <?php foreach ($runtime as $k => $v): ?>
            <?php
            $ok = is_array($v)
                ? !in_array(false, array_map(static fn($x) => (bool)$x, $v), true)
                : (bool)$v;
            $metaText = '';
            if (is_array($v)) {
                $total = count($v);
                $ready = 0;
                foreach ($v as $vv) {
                    if ((bool)$vv) {
                        $ready++;
                    }
                }
                $metaText = $ready . '/' . $total;
            }
            ?>
            <div class="rounded-xl border px-2 py-2 <?= $ok ? 'border-emerald-500/40 bg-emerald-500/10' : 'border-red-500/40 bg-red-500/10' ?>">
                <div class="<?= $ok ? 'text-emerald-200/80' : 'text-red-200/80' ?>"><?= e((string)$k) ?></div>
                <div class="mt-1 font-semibold <?= $ok ? 'text-emerald-100' : 'text-red-100' ?>">
                    <?= $ok ? 'OK' : 'DEGRADED' ?><?= $metaText !== '' ? (' · ' . e($metaText)) : '' ?>
                </div>
            </div>
        <?php endforeach; ?>
    </section>

    <section class="rounded-2xl border border-slate-800 bg-slate-900/70 p-3">
        <h2 class="text-sm font-semibold text-slate-100 mb-2">Flows</h2>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-2 text-xs">
            <?php foreach ($flows as $flowKey => $flowOk): ?>
                <?php $ok = (bool)$flowOk; ?>
                <div class="rounded-lg border px-2 py-2 <?= $ok ? 'border-emerald-500/40 bg-emerald-500/10' : 'border-amber-500/40 bg-amber-500/10' ?>">
                    <div class="<?= $ok ? 'text-emerald-200/80' : 'text-amber-200/80' ?>"><?= e((string)$flowKey) ?></div>
                    <div class="mt-1 font-semibold <?= $ok ? 'text-emerald-100' : 'text-amber-100' ?>"><?= $ok ? 'ready' : 'fallback/degraded' ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="rounded-2xl border border-slate-800 bg-slate-900/70 p-3 overflow-x-auto">
        <h2 class="text-sm font-semibold text-slate-100 mb-2">Module readiness</h2>
        <table class="w-full text-xs">
            <thead>
            <tr class="text-left text-slate-400 border-b border-slate-800">
                <th class="py-1 pr-2">Module</th>
                <th class="py-1 pr-2">Status</th>
                <th class="py-1 pr-2">Missing required</th>
                <th class="py-1 pr-2">Optional fallback</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($modules as $moduleKey => $module): ?>
                <?php
                $status = strtolower(trim((string)($module['status'] ?? 'degraded')));
                $missingRequired = array_merge(
                    is_array($module['missing_tables'] ?? null) ? $module['missing_tables'] : [],
                    is_array($module['missing_columns'] ?? null) ? $module['missing_columns'] : []
                );
                $missingOptional = array_merge(
                    is_array($module['optional_missing_tables'] ?? null) ? $module['optional_missing_tables'] : [],
                    is_array($module['optional_missing_columns'] ?? null) ? $module['optional_missing_columns'] : []
                );
                ?>
                <tr class="border-b border-slate-900">
                    <td class="py-1 pr-2 text-slate-200">
                        <?= e((string)($module['label'] ?? $moduleKey)) ?>
                        <div class="text-[10px] text-slate-500"><?= e((string)$moduleKey) ?></div>
                    </td>
                    <td class="py-1 pr-2">
                        <span class="inline-flex rounded-full px-2 py-0.5 border <?= $status === 'ok' ? 'border-emerald-500/40 bg-emerald-500/10 text-emerald-100' : 'border-amber-500/40 bg-amber-500/10 text-amber-100' ?>">
                            <?= e($status) ?>
                        </span>
                    </td>
                    <td class="py-1 pr-2 text-slate-300">
                        <?= $missingRequired !== [] ? e(implode(', ', $missingRequired)) : '<span class="text-emerald-300">none</span>' ?>
                    </td>
                    <td class="py-1 pr-2 text-slate-400">
                        <?= $missingOptional !== [] ? e(implode(', ', array_slice($missingOptional, 0, 8))) : 'none' ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>

    <section class="rounded-2xl border border-slate-800 bg-slate-900/70 p-3">
        <h2 class="text-sm font-semibold text-slate-100 mb-2">Warnings</h2>
        <?php if ($warnings === []): ?>
            <div class="text-xs text-emerald-300">No critical warnings.</div>
        <?php else: ?>
            <div class="space-y-1">
                <?php foreach ($warnings as $w): ?>
                    <?php
                    $lvl = strtolower(trim((string)($w['level'] ?? 'warning')));
                    $tone = $lvl === 'critical'
                        ? 'border-red-500/50 bg-red-500/10 text-red-200'
                        : 'border-amber-500/50 bg-amber-500/10 text-amber-200';
                    ?>
                    <div class="rounded-lg border px-2 py-1 text-xs <?= e($tone) ?>">
                        <span class="font-semibold"><?= e((string)($w['label'] ?? 'Warning')) ?>:</span>
                        <?= e((string)($w['message'] ?? '')) ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="rounded-2xl border border-slate-800 bg-slate-900/70 p-3">
        <h2 class="text-sm font-semibold text-slate-100 mb-2">Manual smoke links</h2>
        <div class="flex flex-wrap gap-2">
            <?php foreach ($manualLinks as $lnk): ?>
                <a href="<?= e((string)$lnk['url']) ?>" class="rounded-lg border border-slate-700 bg-slate-900/60 px-2 py-1 text-xs text-slate-200 hover:bg-slate-800/70">
                    <?= e((string)$lnk['label']) ?>
                </a>
            <?php endforeach; ?>
        </div>
    </section>
</main>
</body>
</html>
