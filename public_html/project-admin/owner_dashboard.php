<?php
/**
 * Multi-restaurant owner analytics (project_owner). Read-only aggregates.
 */

$config     = require __DIR__ . '/../../app/config.php';
$mainDomain = $config['app']['main_domain'] ?? 'localhost';
$protocol   = $config['app']['protocol'] ?? 'http';

$host = $_SERVER['HTTP_HOST'] ?? '';
$host = preg_replace('/:\d+$/', '', $host);
$isMainHost = (strtolower($host) === strtolower($mainDomain)) || (strtolower($host) === strtolower('www.' . $mainDomain));
if (!$isMainHost) {
    $uri = $_SERVER['REQUEST_URI'] ?? '/project-admin/owner_dashboard.php';
    header('Location: ' . $protocol . '://' . $mainDomain . $uri);
    exit;
}

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/schema_guard.php';
require_once __DIR__ . '/../../app/owner_analytics.php';

require_login();

$currentUser = function_exists('auth_user') ? auth_user() : null;
if (!$currentUser || ($currentUser['global_role'] ?? null) !== 'project_owner') {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>403</title></head><body><h1>403</h1></body></html>';
    exit;
}

if (!function_exists('e')) {
    function e($v): string {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

$demo = function_exists('is_demo_mode') && is_demo_mode();
$days = isset($_GET['days']) ? (int)$_GET['days'] : 30;
$days = max(1, min(365, $days));
$sort = isset($_GET['sort']) ? (string)$_GET['sort'] : 'revenue';
$sortOpts = ['revenue', 'upsell_revenue', 'retention_revenue', 'return_rate'];
if (!in_array($sort, $sortOpts, true)) {
    $sort = 'revenue';
}
$drillId = isset($_GET['rid']) ? (int)$_GET['rid'] : 0;

$appName = $config['app']['name'] ?? 'QR-Rest';

$summary = [];
$top = [];
$problems = [];
$detail = null;
if (!$demo) {
    $summary = get_owner_summary($days);
    $top = get_top_restaurants($days, 20, $sort);
    $problems = get_problem_restaurants($days);
    if ($drillId > 0) {
        $detail = get_restaurant_full_metrics($drillId, $days);
        if (empty($detail['ok'])) {
            $detail = null;
        }
    }
}

$qsBase = 'days=' . (int)$days;
$sortLink = static function (string $field) use ($sort, $days, $qsBase): string {
    $active = $sort === $field ? 'text-sky-300' : 'text-slate-400 hover:text-slate-200';
    return '?days=' . (int)$days . '&sort=' . urlencode($field);
};

$flagLabels = [
    'no_upsell' => 'no upsell',
    'no_crm' => 'no CRM',
    'no_retention' => 'no retention',
    'low_return_rate' => 'low return',
    'low_attach_rate' => 'low attach',
];
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Owner analytics — <?= e($appName) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?= brand_head_tags() ?>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-950 text-slate-50">
<div class="max-w-6xl mx-auto px-4 py-6 sm:py-8">

    <header class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-slate-900/80 border border-slate-700 text-[11px] text-slate-300 mb-2">
                Platform owner • analytics
            </div>
            <h1 class="text-2xl sm:text-3xl font-semibold text-slate-50 mb-1">Owner analytics</h1>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <form method="get" action="" class="flex items-center gap-2 text-sm">
                <input type="hidden" name="sort" value="<?= e($sort) ?>">
                <label class="text-slate-400">Период (дней)</label>
                <input type="number" name="days" min="1" max="365" value="<?= (int)$days ?>" class="w-20 rounded-lg bg-slate-900 border border-slate-700 px-2 py-1 text-slate-100">
                <button type="submit" class="px-3 py-1.5 rounded-lg bg-slate-800 border border-slate-600 text-slate-200 text-sm">OK</button>
            </form>
            <a href="/project-admin/" class="inline-flex items-center px-3 py-1.5 rounded-xl bg-slate-800 border border-slate-700 text-sm text-slate-200 hover:border-sky-500 hover:text-sky-200 transition">← Dashboard</a>
        </div>
    </header>

    <?php if ($demo): ?>
        <section class="rounded-xl border border-slate-800 bg-[#121826] p-6">
            <p class="text-slate-400">Недоступно в демо</p>
        </section>
    <?php else: ?>

    <?php if ($detail !== null): ?>
        <section class="rounded-xl border border-slate-800 bg-[#121826] shadow-xl p-5 mb-6">
            <div class="flex flex-wrap items-center justify-between gap-2 mb-4">
                <h2 class="text-lg font-semibold text-slate-50"><?= e($detail['name'] ?? '') ?> <span class="text-slate-500 text-sm font-normal">#<?= (int)$detail['restaurant_id'] ?></span></h2>
                <a href="?<?= e($qsBase) ?>&amp;sort=<?= e(urlencode($sort)) ?>" class="text-sm text-sky-400 hover:text-sky-200">← Назад</a>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
                <div class="rounded-lg bg-slate-900/60 border border-slate-800 p-4">
                    <div class="text-xs text-slate-500 mb-2">Заказы / выручка</div>
                    <div class="text-slate-200 font-medium">
                        <?= $detail['summary']['total_orders'] !== null ? (int)$detail['summary']['total_orders'] : '—' ?> заказов
                        · <?= $detail['summary']['total_revenue'] !== null ? number_format((float)$detail['summary']['total_revenue'], 0, '.', ' ') . ' ₽' : '—' ?>
                    </div>
                </div>
                <div class="rounded-lg bg-slate-900/60 border border-slate-800 p-4">
                    <div class="text-xs text-slate-500 mb-2">Upsell</div>
                    <div class="text-slate-200 font-medium">
                        <?= $detail['upsell']['revenue'] !== null ? number_format((float)$detail['upsell']['revenue'], 0, '.', ' ') . ' ₽' : '—' ?>
                        <?php if ($detail['upsell']['attach_rate'] !== null): ?>
                            · attach <?= number_format(100 * (float)$detail['upsell']['attach_rate'], 1, '.', ' ') ?>%
                        <?php endif; ?>
                    </div>
                </div>
                <div class="rounded-lg bg-slate-900/60 border border-slate-800 p-4">
                    <div class="text-xs text-slate-500 mb-2">Retention</div>
                    <div class="text-slate-200 font-medium">
                        <?= $detail['retention']['return_rate'] !== null ? number_format(100 * (float)$detail['retention']['return_rate'], 1, '.', ' ') . '%' : '—' ?>
                        <?php
                        $retN = (int)($detail['retention']['sample_size_campaigns'] ?? 0);
                        $retConf = (string)($detail['retention']['confidence_level'] ?? 'low');
                        ?>
                        <div class="text-[12px] text-slate-400 mt-1">
                            N: <?= $retN ?> · confidence: <?= e($retConf) ?><?= $retConf === 'low' ? ' · мало данных' : '' ?>
                        </div>
                        <div class="text-[11px] text-slate-600 mt-1">
                            Confidence по выборке: low (N &lt; 3), medium (N 3–9), high (N &gt;= 10), где N = число accepted-кампаний для return rate.
                        </div>
                        <?php if ($detail['retention']['uplift_percent'] !== null): ?>
                            · uplift <?= number_format(100 * (float)$detail['retention']['uplift_percent'], 1, '.', ' ') ?>%
                        <?php endif; ?>
                        · <?= $detail['retention']['total_return_revenue'] !== null ? number_format((float)$detail['retention']['total_return_revenue'], 0, '.', ' ') . ' ₽' : '—' ?>
                    </div>
                </div>
                <div class="rounded-lg bg-slate-900/60 border border-slate-800 p-4">
                    <div class="text-xs text-slate-500 mb-2">CRM (campaigns)</div>
                    <div class="text-slate-200 font-medium">
                        total <?= $detail['crm']['total_campaigns'] !== null ? (int)$detail['crm']['total_campaigns'] : '—' ?>
                        · accepted <?= $detail['crm']['accepted_campaigns'] !== null ? (int)$detail['crm']['accepted_campaigns'] : '—' ?>
                        · failed <?= $detail['crm']['failed_campaigns'] !== null ? (int)$detail['crm']['failed_campaigns'] : '—' ?>
                    </div>
                </div>
            </div>
        </section>
    <?php else: ?>

    <section class="rounded-xl border border-slate-800 bg-[#121826] shadow-xl p-5 mb-6">
        <h2 class="text-lg font-semibold text-slate-50 mb-4">Сводка</h2>
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3 text-sm">
            <div class="rounded-lg bg-slate-900/60 border border-slate-800 p-3">
                <div class="text-xs text-slate-500">Всего ресторанов</div>
                <div class="text-lg font-semibold text-slate-100"><?= (int)($summary['total_restaurants'] ?? 0) ?></div>
            </div>
            <div class="rounded-lg bg-slate-900/60 border border-slate-800 p-3">
                <div class="text-xs text-slate-500">Активных</div>
                <div class="text-lg font-semibold text-slate-100"><?= (int)($summary['active_restaurants'] ?? 0) ?></div>
            </div>
            <div class="rounded-lg bg-slate-900/60 border border-slate-800 p-3">
                <div class="text-xs text-slate-500">Общая выручка</div>
                <div class="text-lg font-semibold text-emerald-300"><?= number_format((float)($summary['total_revenue'] ?? 0), 0, '.', ' ') ?> ₽</div>
            </div>
            <div class="rounded-lg bg-slate-900/60 border border-slate-800 p-3">
                <div class="text-xs text-slate-500">Выручка от допродаж</div>
                <div class="text-lg font-semibold text-sky-300"><?= number_format((float)($summary['total_upsell_revenue'] ?? 0), 0, '.', ' ') ?> ₽</div>
            </div>
            <div class="rounded-lg bg-slate-900/60 border border-slate-800 p-3">
                <div class="text-xs text-slate-500">Выручка от возвратов</div>
                <div class="text-lg font-semibold text-amber-300"><?= number_format((float)($summary['total_retention_revenue'] ?? 0), 0, '.', ' ') ?> ₽</div>
            </div>
            <div class="rounded-lg bg-slate-900/60 border border-slate-800 p-3">
                <div class="text-xs text-slate-500">Средний return rate</div>
                <div class="text-lg font-semibold text-slate-100"><?= isset($summary['avg_return_rate']) && $summary['avg_return_rate'] !== null ? number_format(100 * (float)$summary['avg_return_rate'], 1, '.', ' ') . '%' : '—' ?></div>
            </div>
            <div class="rounded-lg bg-slate-900/60 border border-slate-800 p-3">
                <div class="text-xs text-slate-500">Средний attach rate</div>
                <div class="text-lg font-semibold text-slate-100"><?= isset($summary['avg_attach_rate']) && $summary['avg_attach_rate'] !== null ? number_format(100 * (float)$summary['avg_attach_rate'], 1, '.', ' ') . '%' : '—' ?></div>
            </div>
            <div class="rounded-lg bg-slate-900/60 border border-slate-800 p-3">
                <div class="text-xs text-slate-500">Заказов (в периоде)</div>
                <div class="text-lg font-semibold text-slate-100"><?= (int)($summary['total_orders'] ?? 0) ?></div>
            </div>
        </div>
    </section>

    <section class="rounded-xl border border-slate-800 bg-[#121826] shadow-xl p-5 mb-6">
        <h2 class="text-lg font-semibold text-slate-50 mb-4">Топ ресторанов</h2>
        <div class="overflow-x-auto">
            <table class="w-full text-sm text-left">
                <thead>
                    <tr class="text-xs text-slate-500 border-b border-slate-800">
                        <th class="py-2 pr-3"><a class="<?= $sort === 'revenue' ? 'text-sky-300' : 'text-slate-400 hover:text-slate-200' ?>" href="?<?= e($qsBase) ?>&amp;sort=revenue">Ресторан</a></th>
                        <th class="py-2 pr-3"><a class="<?= $sort === 'revenue' ? 'text-sky-300' : 'text-slate-400 hover:text-slate-200' ?>" href="?<?= e($qsBase) ?>&amp;sort=revenue">Выручка</a></th>
                        <th class="py-2 pr-3"><a class="<?= $sort === 'upsell_revenue' ? 'text-sky-300' : 'text-slate-400 hover:text-slate-200' ?>" href="?<?= e($qsBase) ?>&amp;sort=upsell_revenue">Upsell (₽)</a></th>
                        <th class="py-2 pr-3"><a class="<?= $sort === 'retention_revenue' ? 'text-sky-300' : 'text-slate-400 hover:text-slate-200' ?>" href="?<?= e($qsBase) ?>&amp;sort=retention_revenue">Retention (₽)</a></th>
                        <th class="py-2 pr-3"><a class="<?= $sort === 'return_rate' ? 'text-sky-300' : 'text-slate-400 hover:text-slate-200' ?>" href="?<?= e($qsBase) ?>&amp;sort=return_rate">Return rate (%)</a></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($top as $row): ?>
                        <tr class="border-b border-slate-800/70">
                            <td class="py-2 pr-3">
                                <a href="?<?= e($qsBase) ?>&amp;sort=<?= e(urlencode($sort)) ?>&amp;rid=<?= (int)$row['restaurant_id'] ?>" class="text-sky-400 hover:text-sky-200"><?= e($row['name']) ?></a>
                            </td>
                            <td class="py-2 pr-3 text-slate-200"><?= $row['total_revenue'] !== null ? number_format((float)$row['total_revenue'], 0, '.', ' ') : '—' ?></td>
                            <td class="py-2 pr-3 text-slate-200"><?= $row['upsell_revenue'] !== null ? number_format((float)$row['upsell_revenue'], 0, '.', ' ') : '—' ?></td>
                            <td class="py-2 pr-3 text-slate-200"><?= $row['retention_revenue'] !== null ? number_format((float)$row['retention_revenue'], 0, '.', ' ') : '—' ?></td>
                            <td class="py-2 pr-3 text-slate-200">
                                <?= $row['return_rate'] !== null ? number_format(100 * (float)$row['return_rate'], 1, '.', ' ') : '—' ?>
                                <?php
                                $rowN = (int)($row['sample_size_campaigns'] ?? 0);
                                $rowConf = (string)($row['confidence_level'] ?? 'low');
                                ?>
                                <div class="text-[10px] text-slate-500 mt-0.5">
                                    N: <?= $rowN ?> · confidence: <?= e($rowConf) ?>
                                    <?= $rowConf === 'low' ? ' · мало данных' : '' ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($top === []): ?>
                        <tr><td colspan="5" class="py-4 text-slate-500">Нет данных</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="rounded-xl border border-slate-800 bg-[#121826] shadow-xl p-5 mb-6">
        <h2 class="text-lg font-semibold text-slate-50 mb-4">Проблемные сигналы</h2>
        <?php if ($problems === []): ?>
            <p class="text-sm text-slate-500">Нет записей</p>
        <?php else: ?>
            <ul class="space-y-3">
                <?php foreach ($problems as $p): ?>
                    <li class="flex flex-wrap items-start gap-2 rounded-lg border border-slate-800 bg-slate-900/40 p-3">
                        <a href="?<?= e($qsBase) ?>&amp;sort=<?= e(urlencode($sort)) ?>&amp;rid=<?= (int)$p['restaurant_id'] ?>" class="text-sky-400 hover:text-sky-200 font-medium"><?= e($p['name']) ?></a>
                        <span class="text-slate-600">·</span>
                        <div class="flex flex-wrap gap-1">
                            <?php foreach (($p['flags'] ?? []) as $fk => $on): ?>
                                <?php if ($on && isset($flagLabels[$fk])): ?>
                                    <span class="inline-block px-2 py-0.5 rounded text-[10px] uppercase tracking-wide bg-slate-800 text-slate-300 border border-slate-700"><?= e($flagLabels[$fk]) ?></span>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <?php endif; ?>
    <?php endif; ?>

</div>
</body>
</html>
