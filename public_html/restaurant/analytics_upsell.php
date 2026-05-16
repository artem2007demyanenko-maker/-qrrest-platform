<?php

require_once __DIR__ . '/../../app/bootstrap.php';
if (file_exists(__DIR__ . '/../../app/billing.php')) {
    require_once __DIR__ . '/../../app/billing.php';
}
require_once __DIR__ . '/../../app/upsell_analytics.php';
if (file_exists(__DIR__ . '/../../app/upsell_optimization.php')) {
    require_once __DIR__ . '/../../app/upsell_optimization.php';
}

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
$authUser = auth_user();
$pdo = db();
$upsellEnabled = true;
if (!is_demo_mode() && file_exists(__DIR__ . '/../../app/subscription_plans.php')) {
    require_once __DIR__ . '/../../app/subscription_plans.php';
    $upsellEnabled = function_exists('check_feature') && check_feature($restId, 'upsell_enabled');
}

if (!function_exists('e')) {
    function e($v): string {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
$upsellAnalyticsPaywallContext = function_exists('billing_get_feature_paywall_context')
    ? billing_get_feature_paywall_context((int)($authUser['id'] ?? 0), $restId, 'upsell_analytics')
    : null;

$errors = [];
$success = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !is_demo_mode()) {
    $csrfOk = isset($_SESSION['csrf'], $_POST['csrf']) && hash_equals((string)$_SESSION['csrf'], (string)$_POST['csrf']);
    if (!$csrfOk) {
        $errors[] = 'Неверный токен. Обновите страницу.';
    } elseif (!$upsellEnabled) {
        $errors[] = 'Умные допродажи доступны на тарифе GROWTH.';
    } else {
        $action = trim((string)($_POST['action'] ?? ''));
        $baseItemId = (int)($_POST['base_item_id'] ?? 0);
        $upsellItemId = (int)($_POST['upsell_item_id'] ?? 0);
        if ($action === 'quick_boost_pair' && function_exists('upsell_quick_boost_pair')) {
            $result = upsell_quick_boost_pair($restId, $baseItemId, $upsellItemId, 50);
            if (!empty($result['success'])) {
                $success = (string)($result['message'] ?? 'Пара усилена.');
            } else {
                $errors[] = (string)($result['message'] ?? 'Не удалось усилить пару.');
            }
        } elseif ($action === 'quick_disable_pair' && function_exists('upsell_quick_disable_pair')) {
            $result = upsell_quick_disable_pair($restId, $baseItemId, $upsellItemId);
            if (!empty($result['success'])) {
                $success = (string)($result['message'] ?? 'Пара отключена.');
            } else {
                $errors[] = (string)($result['message'] ?? 'Не удалось отключить пару.');
            }
        }
    }
}
if (!function_exists('upsell_tone_classes')) {
    function upsell_tone_classes(string $tone): array {
        switch ($tone) {
            case 'emerald':
                return ['pill' => 'bg-emerald-500/10 text-emerald-200 border-emerald-500/30', 'accent' => 'text-emerald-300'];
            case 'amber':
                return ['pill' => 'bg-amber-500/10 text-amber-200 border-amber-500/30', 'accent' => 'text-amber-300'];
            case 'sky':
                return ['pill' => 'bg-sky-500/10 text-sky-200 border-sky-500/30', 'accent' => 'text-sky-300'];
            default:
                return ['pill' => 'bg-slate-800/80 text-slate-300 border-slate-700', 'accent' => 'text-slate-300'];
        }
    }
}
if (!function_exists('upsell_rule_state_classes')) {
    function upsell_rule_state_classes(array $row): array {
        if (empty($row['rule_exists'])) {
            return ['pill' => 'bg-slate-800/80 text-slate-300 border-slate-700', 'accent' => 'text-slate-400'];
        }
        if (!empty($row['rule_active'])) {
            return ['pill' => 'bg-emerald-500/10 text-emerald-200 border-emerald-500/30', 'accent' => 'text-emerald-300'];
        }
        return ['pill' => 'bg-amber-500/10 text-amber-200 border-amber-500/30', 'accent' => 'text-amber-300'];
    }
}

$rangeDays = isset($_GET['days']) ? max(1, min(90, (int)$_GET['days'])) : 7;
$stats = upsell_stats($restId, $rangeDays);

$revSummary = null;
$topUpsellItems = [];
$breakdown = [];
$trend = [];
$optimizationDashboard = [
    'summary' => ['pairs_analyzed' => 0, 'strong_pairs' => 0, 'weak_pairs' => 0, 'low_data_pairs' => 0],
    'top_performing' => [],
    'needs_attention' => [],
    'low_data' => [],
];
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
if (function_exists('get_upsell_optimization_dashboard')) {
    $optimizationDashboard = get_upsell_optimization_dashboard($restId);
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
<?php
$restaurantSidebarActive = 'analytics_upsell';
$restaurantSidebarName = (string)($currentRestaurant['name'] ?? 'Ресторан');
require __DIR__ . '/_sidebar_mobile.php';
?>

<?php
require __DIR__ . '/_sidebar.php';
?>

<main class="flex-1 p-4">
    <div class="max-w-6xl mx-auto space-y-4">
        <?php
        $businessNavActive = 'analytics_upsell';
        require __DIR__ . '/_restaurant_cabinet_context.php';
        require __DIR__ . '/_restaurant_business_nav.php';
        ?>
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

        <?php if ($success): ?>
            <div class="rounded-3xl bg-emerald-500/10 border border-emerald-500/60 px-4 py-3 text-sm text-emerald-100"><?= e($success) ?></div>
        <?php endif; ?>
        <?php if ($errors): ?>
            <div class="rounded-3xl bg-red-500/10 border border-red-500/60 px-4 py-3 text-sm text-red-100">
                <?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if (!$upsellEnabled): ?>
        <?php $ctx = is_array($upsellAnalyticsPaywallContext) ? $upsellAnalyticsPaywallContext : []; ?>
        <section class="rounded-2xl border border-amber-500/50 bg-amber-500/10 px-4 py-4 text-sm text-amber-200" role="status">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="max-w-3xl">
                    <div class="inline-flex items-center rounded-full border border-white/10 bg-white/5 px-3 py-1 text-[11px] font-semibold uppercase tracking-wide"><?= e((string)($ctx['phase_label'] ?? 'Следующий шаг')) ?></div>
                    <p class="font-medium mt-3"><?= e((string)($ctx['title'] ?? 'Аналитика допродаж')) ?></p>
                    <p class="text-xs text-amber-200/80 mt-1"><?= e((string)($ctx['subtitle'] ?? 'Подключите growth-тариф, чтобы видеть, какие допродажи реально работают.')) ?></p>
                </div>
                <a href="<?= e((string)($ctx['cta_url'] ?? '/restaurant/activate.php?plan=growth')) ?>" class="inline-flex items-center px-4 py-2 rounded-xl bg-amber-600 hover:bg-amber-500 text-white text-sm font-medium"><?= e((string)($ctx['cta_label'] ?? 'Открыть тариф GROWTH')) ?></a>
            </div>
            <div class="grid gap-3 lg:grid-cols-2 mt-4">
                <div>
                    <div class="text-[11px] font-semibold uppercase tracking-wide text-amber-100/70">Что откроется после активации</div>
                    <ul class="mt-2 space-y-2 text-xs text-amber-100/90">
                        <?php foreach (array_slice((array)($ctx['benefits'] ?? []), 0, 3) as $benefit): ?>
                            <li class="flex items-start gap-2"><span class="mt-1">•</span><span><?= e((string)$benefit) ?></span></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <div>
                    <div class="text-[11px] font-semibold uppercase tracking-wide text-amber-100/70">Что уже настроено и сохранится</div>
                    <ul class="mt-2 space-y-2 text-xs text-amber-100/90">
                        <?php foreach (array_slice((array)($ctx['proof_items'] ?? []), 0, 3) as $proof): ?>
                            <li class="flex items-start gap-2"><span class="mt-1">•</span><span><?= e((string)$proof) ?></span></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </section>
        <?php endif; ?>

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

        <section class="rounded-3xl bg-slate-900/80 border border-slate-800 p-4 space-y-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h3 class="text-sm font-semibold mb-1">Что усиливать в допродажах</h3>
                    <p class="text-xs text-slate-400">Поверх сырых событий показываем короткие выводы: что уже работает, что даёт много показов без результата и где пока рано принимать решение.</p>
                </div>
                <a href="/restaurant/upsells.php#optimization" class="text-xs text-indigo-400 hover:text-indigo-300">Открыть правила и подсказки →</a>
            </div>

            <div class="grid md:grid-cols-4 gap-3">
                <div class="rounded-2xl bg-slate-950/60 border border-slate-800 px-4 py-3">
                    <div class="text-[11px] uppercase tracking-wide text-slate-500">Пары в анализе</div>
                    <div class="text-2xl font-bold text-slate-100 mt-1"><?= (int)($optimizationDashboard['summary']['pairs_analyzed'] ?? 0) ?></div>
                </div>
                <div class="rounded-2xl bg-slate-950/60 border border-slate-800 px-4 py-3">
                    <div class="text-[11px] uppercase tracking-wide text-slate-500">Работают хорошо</div>
                    <div class="text-2xl font-bold text-emerald-300 mt-1"><?= (int)($optimizationDashboard['summary']['strong_pairs'] ?? 0) ?></div>
                </div>
                <div class="rounded-2xl bg-slate-950/60 border border-slate-800 px-4 py-3">
                    <div class="text-[11px] uppercase tracking-wide text-slate-500">Нужно пересобрать</div>
                    <div class="text-2xl font-bold text-amber-300 mt-1"><?= (int)($optimizationDashboard['summary']['weak_pairs'] ?? 0) ?></div>
                </div>
                <div class="rounded-2xl bg-slate-950/60 border border-slate-800 px-4 py-3">
                    <div class="text-[11px] uppercase tracking-wide text-slate-500">Пока мало данных</div>
                    <div class="text-2xl font-bold text-slate-200 mt-1"><?= (int)($optimizationDashboard['summary']['low_data_pairs'] ?? 0) ?></div>
                </div>
            </div>

            <?php if (
                empty($optimizationDashboard['top_performing'])
                && empty($optimizationDashboard['needs_attention'])
                && empty($optimizationDashboard['low_data'])
            ): ?>
                <div class="rounded-2xl bg-slate-950/60 border border-slate-800 px-4 py-4 text-sm text-slate-400">
                    Пока недостаточно pair-level данных. Когда накопятся показы и принятия допродаж, здесь появятся короткие выводы по конкретным парам.
                </div>
            <?php else: ?>
                <div class="grid xl:grid-cols-3 gap-3">
                    <div class="rounded-2xl bg-slate-950/60 border border-slate-800 p-4 space-y-3">
                        <div>
                            <h4 class="text-sm font-semibold text-emerald-200">Работает хорошо</h4>
                            <p class="text-xs text-slate-500 mt-1">Пары, которые уже дают уверенную конверсию и заслуживают большего веса.</p>
                        </div>
                        <?php if (empty($optimizationDashboard['top_performing'])): ?>
                            <p class="text-sm text-slate-500">Пока нет выраженных победителей.</p>
                        <?php else: ?>
                            <div class="space-y-3">
                                <?php foreach ($optimizationDashboard['top_performing'] as $row): ?>
                                    <?php $tone = upsell_tone_classes((string)($row['tone'] ?? 'slate')); ?>
                                    <?php $ruleTone = upsell_rule_state_classes($row); ?>
                                    <?php $pairLink = '/restaurant/upsells.php?create_from_ai=1&base_item_id=' . (int)($row['base_item_id'] ?? 0) . '&upsell_item_id=' . (int)($row['upsell_item_id'] ?? 0) . '#pair-form'; ?>
                                    <div class="rounded-2xl border border-slate-800 bg-slate-900/70 px-3 py-3">
                                        <div class="flex items-start justify-between gap-3">
                                            <div class="text-sm text-slate-100 font-medium"><?= e($row['base_item_name'] ?? '') ?> → <?= e($row['upsell_item_name'] ?? '') ?></div>
                                            <span class="text-[11px] px-2 py-1 rounded-full border <?= e($tone['pill']) ?>"><?= e($row['status_text'] ?? '') ?></span>
                                        </div>
                                        <div class="mt-2">
                                            <span class="text-[11px] px-2 py-1 rounded-full border <?= e($ruleTone['pill']) ?>"><?= e($row['rule_status_label'] ?? 'Правила ещё нет') ?></span>
                                            <span class="ml-2 text-xs <?= e($ruleTone['accent']) ?>"><?= e($row['rule_summary_text'] ?? '') ?></span>
                                        </div>
                                        <div class="flex flex-wrap gap-x-4 gap-y-1 mt-2 text-xs text-slate-400">
                                            <span>Показы: <span class="<?= e($tone['accent']) ?>"><?= (int)($row['shown'] ?? 0) ?></span></span>
                                            <span>В заказе: <span class="<?= e($tone['accent']) ?>"><?= (int)($row['accepted'] ?? 0) ?></span></span>
                                            <span>Конверсия: <span class="<?= e($tone['accent']) ?>"><?= number_format((float)($row['attach_rate_pct'] ?? 0), 1) ?>%</span></span>
                                        </div>
                                        <p class="mt-2 text-xs text-slate-300"><?= e($row['action_text'] ?? '') ?></p>
                                        <div class="mt-3 flex flex-wrap gap-2">
                                            <?php if ($upsellEnabled && !is_demo_mode()): ?>
                                                <form method="post" class="inline-flex">
                                                    <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                                                    <input type="hidden" name="action" value="quick_boost_pair">
                                                    <input type="hidden" name="base_item_id" value="<?= (int)($row['base_item_id'] ?? 0) ?>">
                                                    <input type="hidden" name="upsell_item_id" value="<?= (int)($row['upsell_item_id'] ?? 0) ?>">
                                                    <button type="submit" class="px-3 py-1.5 rounded-xl bg-emerald-500 hover:bg-emerald-400 text-slate-950 text-xs font-semibold">
                                                        <?= !empty($row['has_rule']) ? 'Усилить правило' : 'Создать и усилить' ?>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                            <a href="<?= e($pairLink) ?>" class="px-3 py-1.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs font-medium">Открыть в правилах</a>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="rounded-2xl bg-slate-950/60 border border-slate-800 p-4 space-y-3">
                        <div>
                            <h4 class="text-sm font-semibold text-amber-200">Стоит пересобрать или отключить</h4>
                            <p class="text-xs text-slate-500 mt-1">Пары, которые получают много показов, но редко попадают в оплаченные заказы.</p>
                        </div>
                        <?php if (empty($optimizationDashboard['needs_attention'])): ?>
                            <p class="text-sm text-slate-500">По текущим данным слабых пар не видно.</p>
                        <?php else: ?>
                            <div class="space-y-3">
                                <?php foreach ($optimizationDashboard['needs_attention'] as $row): ?>
                                    <?php $tone = upsell_tone_classes((string)($row['tone'] ?? 'amber')); ?>
                                    <?php $ruleTone = upsell_rule_state_classes($row); ?>
                                    <?php $pairLink = '/restaurant/upsells.php?create_from_ai=1&base_item_id=' . (int)($row['base_item_id'] ?? 0) . '&upsell_item_id=' . (int)($row['upsell_item_id'] ?? 0) . '#pair-form'; ?>
                                    <div class="rounded-2xl border border-slate-800 bg-slate-900/70 px-3 py-3">
                                        <div class="flex items-start justify-between gap-3">
                                            <div class="text-sm text-slate-100 font-medium"><?= e($row['base_item_name'] ?? '') ?> → <?= e($row['upsell_item_name'] ?? '') ?></div>
                                            <span class="text-[11px] px-2 py-1 rounded-full border <?= e($tone['pill']) ?>"><?= e($row['status_text'] ?? '') ?></span>
                                        </div>
                                        <div class="mt-2">
                                            <span class="text-[11px] px-2 py-1 rounded-full border <?= e($ruleTone['pill']) ?>"><?= e($row['rule_status_label'] ?? 'Правила ещё нет') ?></span>
                                            <span class="ml-2 text-xs <?= e($ruleTone['accent']) ?>"><?= e($row['rule_summary_text'] ?? '') ?></span>
                                        </div>
                                        <div class="flex flex-wrap gap-x-4 gap-y-1 mt-2 text-xs text-slate-400">
                                            <span>Показы: <span class="<?= e($tone['accent']) ?>"><?= (int)($row['shown'] ?? 0) ?></span></span>
                                            <span>В заказе: <span class="<?= e($tone['accent']) ?>"><?= (int)($row['accepted'] ?? 0) ?></span></span>
                                            <span>Конверсия: <span class="<?= e($tone['accent']) ?>"><?= number_format((float)($row['attach_rate_pct'] ?? 0), 1) ?>%</span></span>
                                        </div>
                                        <p class="mt-2 text-xs text-slate-300"><?= e($row['action_text'] ?? '') ?></p>
                                        <div class="mt-3 flex flex-wrap gap-2">
                                            <?php if ($upsellEnabled && !is_demo_mode() && !empty($row['has_rule'])): ?>
                                                <form method="post" class="inline-flex">
                                                    <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                                                    <input type="hidden" name="action" value="quick_disable_pair">
                                                    <input type="hidden" name="base_item_id" value="<?= (int)($row['base_item_id'] ?? 0) ?>">
                                                    <input type="hidden" name="upsell_item_id" value="<?= (int)($row['upsell_item_id'] ?? 0) ?>">
                                                    <button type="submit" class="px-3 py-1.5 rounded-xl bg-amber-500 hover:bg-amber-400 text-slate-950 text-xs font-semibold">Отключить</button>
                                                </form>
                                            <?php endif; ?>
                                            <a href="<?= e($pairLink) ?>" class="px-3 py-1.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs font-medium">Открыть и изменить</a>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="rounded-2xl bg-slate-950/60 border border-slate-800 p-4 space-y-3">
                        <div>
                            <h4 class="text-sm font-semibold text-slate-200">Пока мало данных</h4>
                            <p class="text-xs text-slate-500 mt-1">Пары, по которым ещё рано принимать решение. Их не стоит выключать слишком рано.</p>
                        </div>
                        <?php if (empty($optimizationDashboard['low_data'])): ?>
                            <p class="text-sm text-slate-500">Почти все пары уже набрали достаточно данных для решения.</p>
                        <?php else: ?>
                            <div class="space-y-3">
                                <?php foreach ($optimizationDashboard['low_data'] as $row): ?>
                                    <?php $tone = upsell_tone_classes((string)($row['tone'] ?? 'slate')); ?>
                                    <?php $ruleTone = upsell_rule_state_classes($row); ?>
                                    <?php $pairLink = '/restaurant/upsells.php?create_from_ai=1&base_item_id=' . (int)($row['base_item_id'] ?? 0) . '&upsell_item_id=' . (int)($row['upsell_item_id'] ?? 0) . '#pair-form'; ?>
                                    <div class="rounded-2xl border border-slate-800 bg-slate-900/70 px-3 py-3">
                                        <div class="flex items-start justify-between gap-3">
                                            <div class="text-sm text-slate-100 font-medium"><?= e($row['base_item_name'] ?? '') ?> → <?= e($row['upsell_item_name'] ?? '') ?></div>
                                            <span class="text-[11px] px-2 py-1 rounded-full border <?= e($tone['pill']) ?>"><?= e($row['status_text'] ?? '') ?></span>
                                        </div>
                                        <div class="mt-2">
                                            <span class="text-[11px] px-2 py-1 rounded-full border <?= e($ruleTone['pill']) ?>"><?= e($row['rule_status_label'] ?? 'Правила ещё нет') ?></span>
                                            <span class="ml-2 text-xs <?= e($ruleTone['accent']) ?>"><?= e($row['rule_summary_text'] ?? '') ?></span>
                                        </div>
                                        <div class="flex flex-wrap gap-x-4 gap-y-1 mt-2 text-xs text-slate-400">
                                            <span>Показы: <span class="<?= e($tone['accent']) ?>"><?= (int)($row['shown'] ?? 0) ?></span></span>
                                            <span>В заказе: <span class="<?= e($tone['accent']) ?>"><?= (int)($row['accepted'] ?? 0) ?></span></span>
                                            <span>Конверсия: <span class="<?= e($tone['accent']) ?>"><?= number_format((float)($row['attach_rate_pct'] ?? 0), 1) ?>%</span></span>
                                        </div>
                                        <p class="mt-2 text-xs text-slate-300"><?= e($row['action_text'] ?? '') ?></p>
                                        <div class="mt-3 flex flex-wrap gap-2">
                                            <a href="<?= e($pairLink) ?>" class="px-3 py-1.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs font-medium">Открыть правило</a>
                                            <span class="px-3 py-1.5 rounded-xl border border-slate-800 text-slate-500 text-xs">Собрать ещё данных</span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
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
                <h3 class="text-sm font-semibold mb-3">Выручка от допродаж</h3>
                <p class="text-sm text-slate-400">В демо-режиме детальная выручка по допродажам не показывается.</p>
            </section>
        <?php else: ?>
            <?php if ($revSummary === null || (int)($revSummary['orders_with_upsell'] ?? 0) <= 0): ?>
                <section class="rounded-3xl bg-slate-900/80 border border-slate-800 p-4">
                    <h3 class="text-sm font-semibold mb-3">Выручка от допродаж</h3>
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
                        <h3 class="text-sm font-semibold mb-3">Лидеры по выручке из допродаж</h3>
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
                        <h3 class="text-sm font-semibold mb-3">Структура по типам</h3>
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
                        <h3 class="text-sm font-semibold mb-3">Динамика по дням</h3>
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
    </div>
</main>
</body>
</html>
