<?php

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/upsell_repo.php';
if (file_exists(__DIR__ . '/../../app/upsell_optimization.php')) {
    require_once __DIR__ . '/../../app/upsell_optimization.php';
}
if (file_exists(__DIR__ . '/../../app/growth_engine_arch.php')) {
    require_once __DIR__ . '/../../app/growth_engine_arch.php';
}

$rid = bin2hex(random_bytes(4));
set_exception_handler(function (Throwable $e) use ($rid) {
    error_log('RESTAURANT_UPSELLS rid=' . $rid . ' ' . $e->getMessage() . ' ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
    }
    echo '<h1>Ошибка</h1><p>Попробуйте позже.</p><p style="font-size:11px;color:#666">RID: ' . htmlspecialchars($rid) . '</p>';
    exit;
});

require_login();
require_current_restaurant();
require_current_restaurant_role(['owner', 'admin']);

if (file_exists(__DIR__ . '/../../app/trial_guard.php')) {
    require_once __DIR__ . '/../../app/trial_guard.php';
}
$trialRequiresUpgrade = is_demo_mode() ? false : (function_exists('trial_guard_requires_upgrade') && trial_guard_requires_upgrade());
$trialInfo = function_exists('trial_guard_trial_info') ? trial_guard_trial_info() : ['is_trial' => false, 'days_left' => 0, 'is_expired' => false, 'has_active_paid_plan' => false];

$pdo = db();
$restId = (int)$currentRestaurant['id'];

// Soft upsell gating: allow read-only; block mutations when feature disabled (demo unchanged).
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

$errors = [];
$success = null;
$optimizationSuggestions = ['best_pair' => null, 'weakest_pair' => null, 'suggestions' => []];
$growthUpsellSuggestions = [];

// Prefill from AI suggestion link (create_from_ai=1&base_item_id=X&upsell_item_id=Y)
$prefillBaseId = 0;
$prefillUpsellId = 0;
if (!empty($_GET['create_from_ai']) && isset($_GET['base_item_id'], $_GET['upsell_item_id'])) {
    $prefillBaseId = (int) $_GET['base_item_id'];
    $prefillUpsellId = (int) $_GET['upsell_item_id'];
}

// POST actions (disabled in demo; blocked when upsell not enabled on plan)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !is_demo_mode()) {
    $csrfOk = isset($_SESSION['csrf'], $_POST['csrf']) && hash_equals((string)$_SESSION['csrf'], (string)$_POST['csrf']);
    if (!$csrfOk) {
        $errors[] = 'Неверный токен. Обновите страницу.';
    } elseif (!$upsellEnabled) {
        $errors[] = 'Умные допродажи доступны на тарифе GROWTH. Подключите upsell в разделе тарифов.';
    } else {
        $action = trim($_POST['action'] ?? '');
        if ($action === 'apply_optimization') {
            $stype = trim($_POST['suggestion_type'] ?? '');
            $baseId = (int)($_POST['base_item_id'] ?? 0);
            $upsellId = (int)($_POST['upsell_item_id'] ?? 0);
            if ($baseId <= 0 || $upsellId <= 0) {
                $errors[] = 'Некорректные данные предложения.';
            } else {
                try {
                    $stmt = $pdo->prepare("SELECT id FROM menu_items WHERE id IN (?,?) AND restaurant_id = ? AND available = 1");
                    $stmt->execute([$baseId, $upsellId, $restId]);
                    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
                    if (count($ids) < 2) {
                        $errors[] = 'Оба блюда должны принадлежать ресторану и быть доступны.';
                    } else {
                        if ($stype === 'upsell_priority_change') {
                            $delta = (int)($_POST['weight_delta'] ?? 50);
                            $delta = max(10, min(300, $delta));
                            $exists = $pdo->prepare("SELECT id, weight FROM menu_item_upsells WHERE restaurant_id = ? AND base_item_id = ? AND upsell_item_id = ? LIMIT 1");
                            $exists->execute([$restId, $baseId, $upsellId]);
                            $row = $exists->fetch(PDO::FETCH_ASSOC);
                            if ($row && isset($row['id'])) {
                                $newWeight = max(0, min(1000, (int)($row['weight'] ?? 0) + $delta));
                                $upd = $pdo->prepare("UPDATE menu_item_upsells SET weight = ?, updated_at = NOW() WHERE id = ? AND restaurant_id = ?");
                                $upd->execute([$newWeight, (int)$row['id'], $restId]);
                                $success = 'Оптимизация применена: вес увеличен.';
                            } else {
                                $ins = $pdo->prepare("INSERT INTO menu_item_upsells (restaurant_id, base_item_id, upsell_item_id, weight, active) VALUES (?, ?, ?, ?, 1)");
                                $ins->execute([$restId, $baseId, $upsellId, 150]);
                                $success = 'Оптимизация применена: создано новое правило с повышенным весом.';
                            }
                        } elseif ($stype === 'upsell_disable') {
                            $stmt = $pdo->prepare("UPDATE menu_item_upsells SET active = 0, updated_at = NOW() WHERE restaurant_id = ? AND base_item_id = ? AND upsell_item_id = ?");
                            $stmt->execute([$restId, $baseId, $upsellId]);
                            if ($stmt->rowCount() > 0) {
                                $success = 'Оптимизация применена: правило отключено.';
                            } else {
                                $errors[] = 'Правило не найдено (нечего отключать).';
                            }
                        } elseif ($stype === 'upsell_new_pair') {
                            $weight = (int)($_POST['weight'] ?? 100);
                            $weight = max(0, min(1000, $weight));
                            $exists = $pdo->prepare("SELECT id FROM menu_item_upsells WHERE restaurant_id = ? AND base_item_id = ? AND upsell_item_id = ? LIMIT 1");
                            $exists->execute([$restId, $baseId, $upsellId]);
                            if ($exists->fetchColumn()) {
                                $upd = $pdo->prepare("UPDATE menu_item_upsells SET weight = ?, active = 1, updated_at = NOW() WHERE restaurant_id = ? AND base_item_id = ? AND upsell_item_id = ?");
                                $upd->execute([$weight, $restId, $baseId, $upsellId]);
                                $success = 'Оптимизация применена: правило обновлено.';
                            } else {
                                $ins = $pdo->prepare("INSERT INTO menu_item_upsells (restaurant_id, base_item_id, upsell_item_id, weight, active) VALUES (?, ?, ?, ?, 1)");
                                $ins->execute([$restId, $baseId, $upsellId, $weight]);
                                $success = 'Оптимизация применена: добавлено новое правило.';
                            }
                        } else {
                            $errors[] = 'Неизвестный тип оптимизации.';
                        }

                        $sid = (int)($_POST['growth_suggestion_id'] ?? 0);
                        if ($sid > 0 && function_exists('growth_engine_accept_suggestion')) {
                            growth_engine_accept_suggestion($restId, $sid);
                        }
                    }
                } catch (Throwable $e) {
                    $errors[] = 'Ошибка применения оптимизации. Попробуйте позже.';
                }
            }
        } elseif ($action === 'ignore_optimization') {
            $sid = (int)($_POST['growth_suggestion_id'] ?? 0);
            if ($sid > 0 && function_exists('growth_engine_dismiss_suggestion')) {
                if (growth_engine_dismiss_suggestion($restId, $sid)) {
                    $success = 'Предложение скрыто.';
                }
            } else {
                $success = 'Предложение скрыто.';
            }
        } elseif ($action === 'add') {
            $baseId = (int)($_POST['base_item_id'] ?? 0);
            $upsellId = (int)($_POST['upsell_item_id'] ?? 0);
            $weight = (int)($_POST['weight'] ?? 100);
            $active = isset($_POST['active']) ? 1 : 0;
            if ($baseId <= 0 || $upsellId <= 0) {
                $errors[] = 'Выберите базовое и предлагаемое блюдо.';
            } elseif ($baseId === $upsellId) {
                $errors[] = 'Базовое и предлагаемое блюдо не должны совпадать.';
            } else {
                $stmt = $pdo->prepare("SELECT id FROM menu_items WHERE id IN (?,?) AND restaurant_id = ? AND available = 1");
                $stmt->execute([$baseId, $upsellId, $restId]);
                $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
                if (count($ids) < 2) {
                    $errors[] = 'Оба блюда должны принадлежать ресторану и быть доступны.';
                } else {
                    $weight = max(0, min(1000, $weight));
                    $exists = $pdo->prepare("SELECT id FROM menu_item_upsells WHERE restaurant_id = ? AND base_item_id = ? AND upsell_item_id = ? LIMIT 1");
                    $exists->execute([$restId, $baseId, $upsellId]);
                    if ($exists->fetchColumn()) {
                        $upd = $pdo->prepare("UPDATE menu_item_upsells SET weight = ?, active = ?, updated_at = NOW() WHERE restaurant_id = ? AND base_item_id = ? AND upsell_item_id = ?");
                        $upd->execute([$weight, $active, $restId, $baseId, $upsellId]);
                        $success = 'Правило обновлено.';
                    } else {
                        $ins = $pdo->prepare("INSERT INTO menu_item_upsells (restaurant_id, base_item_id, upsell_item_id, weight, active) VALUES (?, ?, ?, ?, ?)");
                        $ins->execute([$restId, $baseId, $upsellId, $weight, $active]);
                        $success = 'Правило добавлено.';
                    }
                }
            }
        } elseif ($action === 'toggle') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                $stmt = $pdo->prepare("UPDATE menu_item_upsells SET active = NOT active, updated_at = NOW() WHERE id = ? AND restaurant_id = ?");
                $stmt->execute([$id, $restId]);
                if ($stmt->rowCount()) {
                    $success = 'Статус обновлён.';
                }
            }
        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                $stmt = $pdo->prepare("DELETE FROM menu_item_upsells WHERE id = ? AND restaurant_id = ?");
                $stmt->execute([$id, $restId]);
                if ($stmt->rowCount()) {
                    $success = 'Правило удалено.';
                }
            }
        } elseif ($action === 'edit_weight') {
            $id = (int)($_POST['id'] ?? 0);
            $weight = (int)($_POST['weight'] ?? 100);
            $weight = max(0, min(1000, $weight));
            if ($id > 0) {
                $stmt = $pdo->prepare("UPDATE menu_item_upsells SET weight = ?, updated_at = NOW() WHERE id = ? AND restaurant_id = ?");
                $stmt->execute([$weight, $id, $restId]);
                if ($stmt->rowCount()) {
                    $success = 'Вес обновлён.';
                }
            }
        }
    }
}

// Optimization suggestions (read-only unless manually applied)
if (function_exists('get_upsell_optimization_suggestions')) {
    $optimizationSuggestions = get_upsell_optimization_suggestions($restId);
}
// No automatic suggestion publishing on page load (must be explicit via Growth Engine automation).
if (function_exists('growth_engine_list_suggestions')) {
    $all = growth_engine_list_suggestions($restId, 'pending', 80);
    foreach ($all as $s) {
        $t = (string)($s['type'] ?? '');
        if (in_array($t, ['upsell_priority_change', 'upsell_disable', 'upsell_new_pair'], true)) {
            $growthUpsellSuggestions[] = $s;
        }
    }
}

// Load menu items for dropdowns and existing rules
$menuItems = [];
$rules = [];
if (is_demo_mode()) {
    foreach (demo_menu_items() as $it) {
        $menuItems[(int)$it['id']] = (string)$it['name'];
    }
    $rules = demo_upsell_rules();
} else {
    $stmt = $pdo->prepare("SELECT id, name FROM menu_items WHERE restaurant_id = ? AND available = 1 ORDER BY name ASC");
    $stmt->execute([$restId]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $menuItems[(int)$row['id']] = (string)$row['name'];
    }
    if (upsell_table_exists()) {
        $stmt = $pdo->prepare("
            SELECT u.id, u.base_item_id, u.upsell_item_id, u.weight, u.active, u.created_at, u.updated_at,
                   b.name AS base_item_name, s.name AS upsell_item_name
            FROM menu_item_upsells u
            LEFT JOIN menu_items b ON b.id = u.base_item_id AND b.restaurant_id = u.restaurant_id
            LEFT JOIN menu_items s ON s.id = u.upsell_item_id AND s.restaurant_id = u.restaurant_id
            WHERE u.restaurant_id = ?
            ORDER BY u.created_at DESC
        ");
        $stmt->execute([$restId]);
        $rules = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Допродажи — <?= e($currentRestaurant['name']) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?= brand_head_tags() ?>

    <script src="https://cdn.tailwindcss.com"></script>
    <link href="/assets/css/motion.css" rel="stylesheet">
</head>
<body class="min-h-screen bg-slate-950 text-slate-50 flex <?= is_demo_mode() ? 'demo-mode' : '' ?>">

<aside class="w-64 bg-slate-950/80 border-r border-slate-800 p-4 hidden md:block">
    <?= brand_restaurant_sidebar_header_html($currentRestaurant['name']) ?>
    <nav class="sidebar-nav space-y-2 text-sm">
        <a href="/restaurant/dashboard.php" class="block px-3 py-2 rounded-lg hover:bg-slate-800/60">Обзор</a>
        <a href="/restaurant/revenue.php" class="block px-3 py-2 rounded-lg hover:bg-slate-800/60">Доход</a>
        <a href="/restaurant/menu_categories.php" class="block px-3 py-2 rounded-lg hover:bg-slate-800/60">Категории меню</a>
        <a href="/restaurant/menu_items.php" class="block px-3 py-2 rounded-lg hover:bg-slate-800/60">Блюда</a>
        <a href="/restaurant/tables.php" class="block px-3 py-2 rounded-lg hover:bg-slate-800/60">Столы и QR</a>
        <a href="/restaurant/qr_print.php" class="block px-3 py-2 rounded-lg hover:bg-slate-800/60">QR Print</a>
        <a href="/restaurant/floorplan.php" class="block px-3 py-2 rounded-lg hover:bg-slate-800/60">Карта столов</a>
        <a href="/restaurant/orders.php" class="block px-3 py-2 rounded-lg hover:bg-slate-800/60">Заказы</a>
        <a href="/restaurant/upsells.php" class="block px-3 py-2 rounded-lg sidebar-active">Допродажи</a>
        <a href="/restaurant/upsell_rules.php" class="block px-3 py-2 rounded-lg hover:bg-slate-800/60">Правила допродаж</a>
        <a href="/restaurant/analytics_upsell.php" class="block px-3 py-2 rounded-lg hover:bg-slate-800/60">Аналитика допродаж</a>
        <a href="/restaurant/crm.php" class="block px-3 py-2 rounded-lg hover:bg-slate-800/60">CRM</a>
        <a href="/restaurant/crm_campaigns.php" class="block px-3 py-2 rounded-lg hover:bg-slate-800/60">CRM кампании</a>
        <a href="/restaurant/staff.php" class="block px-3 py-2 rounded-lg hover:bg-slate-800/60">Сотрудники</a>
        <a href="/restaurant/settings.php" class="block px-3 py-2 rounded-lg hover:bg-slate-800/60">Настройки</a>
        <a href="/logout.php" class="block px-3 py-2 rounded-lg hover:bg-slate-800/60 text-red-300">Выйти</a>
    </nav>
</aside>

<main class="flex-1 p-4">
    <div class="max-w-4xl mx-auto space-y-4 page-enter">
        <?php if (is_demo_mode()): ?>
        <div class="rounded-xl bg-amber-500/10 border border-amber-500/40 px-4 py-2.5 flex items-center justify-center gap-2 text-sm text-amber-200">
            <span aria-hidden="true">⚠</span>
            <span>Demo environment — actions are simulated.</span>
        </div>
        <?php endif; ?>
        <?php if ($trialRequiresUpgrade): ?>
        <div class="rounded-2xl bg-red-500/10 border border-red-500/50 px-4 py-4 text-center">
            <p class="text-slate-100 font-medium mb-2">Доступ к допродажам доступен после активации подписки</p>
            <a href="/restaurant/activate.php" class="inline-block px-4 py-2 rounded-xl bg-red-500/40 hover:bg-red-500/60 text-white text-sm font-medium">Активировать подписку</a>
        </div>
        <?php elseif ($trialInfo['is_trial'] && !$trialInfo['is_expired']): ?>
        <div class="rounded-2xl bg-sky-500/10 border border-sky-500/50 px-4 py-3 flex flex-wrap items-center justify-between gap-2">
            <span class="text-sm text-sky-100">Пробный период: осталось <?= (int)$trialInfo['days_left'] ?> дн.</span>
            <a href="/restaurant/activate.php" class="px-3 py-1.5 rounded-xl bg-sky-500/30 hover:bg-sky-500/50 text-sky-100 text-sm font-medium">Выбрать тариф</a>
        </div>
        <?php endif; ?>

        <?php if (!$upsellEnabled): ?>
        <div class="rounded-2xl border border-amber-500/50 bg-amber-500/10 px-4 py-3 text-sm text-amber-200" role="status">
            <p class="font-medium">Умные допродажи доступны на тарифе GROWTH</p>
            <p class="text-xs text-amber-200/80 mt-1">Подключите upsell, чтобы увеличивать средний чек и показывать рекомендации к заказу.</p>
            <a href="/owner/billing.php" class="inline-flex items-center mt-3 px-4 py-2 rounded-xl bg-amber-600 hover:bg-amber-500 text-white text-sm font-medium">Перейти на тариф GROWTH</a>
        </div>
        <?php endif; ?>

        <?php if (!$trialRequiresUpgrade): ?>
        <header class="section reveal">
            <h2 class="text-2xl font-bold mb-1">Правила допродаж</h2>
            <p class="text-xs text-slate-500">«Часто берут вместе» — укажите пары блюд и вес. Гостям будут предлагаться допродажи в QR-меню.</p>
            <p class="text-[11px] text-slate-600 mt-2">Включение блока «Добавьте к заказу» и число карточек (1–3): <a href="/restaurant/settings.php#smart-upsell-guest" class="text-indigo-400 hover:text-indigo-300 underline decoration-dotted">настройки ресторана → умные допродажи в QR</a>.</p>
        </header>

        <!-- Optimization Suggestions -->
        <section id="optimization" class="section reveal dashboard-card card-motion bg-slate-900/80 border border-slate-800 rounded-3xl p-4 space-y-3">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h3 class="text-sm font-semibold">Optimization Suggestions</h3>
                    <p class="text-xs text-slate-500">Based on real upsell conversion events. Nothing changes without manual approval.</p>
                </div>
                <a href="/restaurant/analytics_upsell.php" class="text-xs text-indigo-400 hover:text-indigo-300">View upsell analytics →</a>
            </div>
            <?php if (is_demo_mode()): ?>
                <div class="text-xs text-amber-200 rounded-2xl bg-amber-500/10 border border-amber-500/30 px-3 py-2">Demo: suggestions are simulated. No writes.</div>
            <?php endif; ?>
            <?php if (!empty($growthUpsellSuggestions)): ?>
                <div class="space-y-2">
                    <?php foreach (array_slice($growthUpsellSuggestions, 0, 6) as $gs): ?>
                        <?php
                        $payload = [];
                        $pj = (string)($gs['payload_json'] ?? '');
                        if ($pj !== '') {
                            $decoded = json_decode($pj, true);
                            if (is_array($decoded)) { $payload = $decoded; }
                        }
                        $baseId = (int)($payload['base_item_id'] ?? 0);
                        $upsellId = (int)($payload['upsell_item_id'] ?? 0);
                        ?>
                        <div class="rounded-2xl border border-slate-800 bg-slate-950/40 px-3 py-3">
                            <div class="text-sm text-slate-100 font-medium"><?= e($gs['title'] ?? '') ?></div>
                            <div class="text-xs text-slate-400 mt-1"><?= e($gs['description'] ?? '') ?></div>
                            <div class="mt-3 flex flex-wrap gap-2">
                                <?php if ($upsellEnabled && !is_demo_mode()): ?>
                                <form method="post" class="inline-flex">
                                    <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                                    <input type="hidden" name="action" value="apply_optimization">
                                    <input type="hidden" name="growth_suggestion_id" value="<?= (int)($gs['id'] ?? 0) ?>">
                                    <input type="hidden" name="suggestion_type" value="<?= e((string)($gs['type'] ?? '')) ?>">
                                    <input type="hidden" name="base_item_id" value="<?= $baseId ?>">
                                    <input type="hidden" name="upsell_item_id" value="<?= $upsellId ?>">
                                    <?php if (($gs['type'] ?? '') === 'upsell_priority_change'): ?>
                                        <input type="hidden" name="weight_delta" value="50">
                                    <?php elseif (($gs['type'] ?? '') === 'upsell_new_pair'): ?>
                                        <input type="hidden" name="weight" value="<?= (int)($payload['recommended_weight'] ?? 100) ?>">
                                    <?php endif; ?>
                                    <button type="submit" class="px-3 py-1.5 rounded-xl bg-emerald-500 hover:bg-emerald-400 text-slate-950 text-xs font-semibold btn-motion">Accept & apply</button>
                                </form>
                                <form method="post" class="inline-flex">
                                    <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                                    <input type="hidden" name="action" value="ignore_optimization">
                                    <input type="hidden" name="growth_suggestion_id" value="<?= (int)($gs['id'] ?? 0) ?>">
                                    <button type="submit" class="px-3 py-1.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs font-medium">Ignore</button>
                                </form>
                                <?php elseif (!$upsellEnabled): ?>
                                <span class="text-xs text-slate-500">Upsell на тарифе GROWTH. <a href="/owner/billing.php" class="text-amber-400 hover:underline">Перейти на тариф</a></span>
                                <?php else: ?>
                                <span class="text-xs text-slate-500">Demo mode: approval disabled.</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php elseif (!empty($optimizationSuggestions['suggestions'] ?? [])): ?>
                <ul class="space-y-2 text-sm text-slate-200">
                    <?php foreach (array_slice($optimizationSuggestions['suggestions'], 0, 5) as $s): ?>
                        <li class="flex items-start gap-2">
                            <span class="text-indigo-400 mt-0.5">•</span>
                            <span><?= e($s['title'] ?? '') ?> — <span class="text-slate-400"><?= e($s['description'] ?? '') ?></span></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <p class="text-xs text-slate-500">Enable Growth Engine suggestions to accept/ignore directly from this page.</p>
            <?php else: ?>
                <p class="text-sm text-slate-400">No optimization suggestions yet. Collect more upsell events (offers shown and accepted) to unlock suggestions.</p>
            <?php endif; ?>
        </section>

        <?php if ($success): ?>
            <div class="rounded-3xl bg-emerald-500/10 border border-emerald-500/60 px-4 py-3 text-sm text-emerald-100"><?= e($success) ?></div>
        <?php endif; ?>
        <?php if ($errors): ?>
            <div class="rounded-3xl bg-red-500/10 border border-red-500/60 px-4 py-3 text-sm text-red-100">
                <?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?>
            </div>
        <?php endif; ?>

        <section class="section reveal dashboard-card card-motion bg-slate-900/80 border border-slate-800 rounded-3xl p-4">
            <h3 class="text-sm font-semibold mb-3">Добавить правило</h3>
            <form method="post" class="flex flex-wrap items-end gap-3">
                <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                <input type="hidden" name="action" value="add">
                <div class="min-w-[180px]">
                    <label class="block text-[11px] text-slate-400 mb-1">Базовое блюдо</label>
                    <select name="base_item_id" class="w-full rounded-2xl bg-slate-950 border border-slate-700 px-3 py-2 text-sm" <?= $upsellEnabled ? '' : 'disabled' ?>>
                        <option value="">— выбрать —</option>
                        <?php foreach ($menuItems as $id => $name): ?>
                            <option value="<?= $id ?>"<?= ($prefillBaseId > 0 && $id === $prefillBaseId) ? ' selected' : '' ?>><?= e($name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="min-w-[180px]">
                    <label class="block text-[11px] text-slate-400 mb-1">Предлагать блюдо</label>
                    <select name="upsell_item_id" class="w-full rounded-2xl bg-slate-950 border border-slate-700 px-3 py-2 text-sm" <?= $upsellEnabled ? '' : 'disabled' ?>>
                        <option value="">— выбрать —</option>
                        <?php foreach ($menuItems as $id => $name): ?>
                            <option value="<?= $id ?>"<?= ($prefillUpsellId > 0 && $id === $prefillUpsellId) ? ' selected' : '' ?>><?= e($name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="w-20">
                    <label class="block text-[11px] text-slate-400 mb-1">Вес</label>
                    <input type="number" name="weight" value="100" min="0" max="1000" class="w-full rounded-2xl bg-slate-950 border border-slate-700 px-3 py-2 text-sm" <?= $upsellEnabled ? '' : 'readonly' ?>>
                </div>
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" name="active" value="1" checked class="rounded bg-slate-950 border-slate-700" <?= $upsellEnabled ? '' : 'disabled' ?>>
                    Активно
                </label>
                <button type="submit" class="px-4 py-2 rounded-2xl bg-emerald-500 hover:bg-emerald-400 text-slate-950 text-sm font-semibold btn-motion" <?= $upsellEnabled ? '' : 'disabled' ?>>Добавить</button>
            </form>
        </section>

        <section class="section reveal dashboard-card card-motion bg-slate-900/80 border border-slate-800 rounded-3xl p-4">
            <h3 class="text-sm font-semibold mb-3">Существующие правила</h3>
            <?php if (empty($rules)): ?>
                <div class="empty-state">
                    <svg class="empty-state-icon mx-auto text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>
                    <div class="empty-state-title">Нет правил допродаж</div>
                    <div class="empty-state-text">Добавьте пары «базовое блюдо → предлагаемое» в форме выше.</div>
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-slate-400 border-b border-slate-700">
                                <th class="pb-2 pr-2">Базовое блюдо</th>
                                <th class="pb-2 pr-2">Предлагать</th>
                                <th class="pb-2 pr-2">Вес</th>
                                <th class="pb-2 pr-2">Активно</th>
                                <th class="pb-2 pr-2">Создано</th>
                                <th class="pb-2">Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rules as $r): ?>
                                <tr class="table-row-motion border-b border-slate-800/80">
                                    <td class="py-2 pr-2"><?= e($r['base_item_name'] ?? 'ID ' . $r['base_item_id']) ?></td>
                                    <td class="py-2 pr-2"><?= e($r['upsell_item_name'] ?? 'ID ' . $r['upsell_item_id']) ?></td>
                                    <td class="py-2 pr-2">
                                        <?php if ($upsellEnabled): ?>
                                        <form method="post" class="inline-flex items-center gap-1">
                                            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                                            <input type="hidden" name="action" value="edit_weight">
                                            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                            <input type="number" name="weight" value="<?= (int)$r['weight'] ?>" min="0" max="1000" class="w-16 rounded-xl bg-slate-950 border border-slate-700 px-2 py-1 text-xs">
                                            <button type="submit" class="text-[11px] text-emerald-400 hover:underline">OK</button>
                                        </form>
                                        <?php else: ?>
                                        <span class="text-slate-500 text-xs"><?= (int)$r['weight'] ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-2 pr-2">
                                        <?php if ($upsellEnabled): ?>
                                        <form method="post" class="inline">
                                            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                                            <input type="hidden" name="action" value="toggle">
                                            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                            <button type="submit" class="text-[11px] <?= (int)($r['active'] ?? 1) ? 'text-emerald-400' : 'text-slate-500' ?> hover:underline">
                                                <?= (int)($r['active'] ?? 1) ? 'Да' : 'Нет' ?>
                                            </button>
                                        </form>
                                        <?php else: ?>
                                        <span class="text-[11px] text-slate-500"><?= (int)($r['active'] ?? 1) ? 'Да' : 'Нет' ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-2 pr-2 text-slate-500 text-xs"><?= e($r['created_at'] ?? '') ?></td>
                                    <td class="py-2">
                                        <?php if ($upsellEnabled): ?>
                                        <form method="post" class="inline" onsubmit="return confirm('Удалить правило?');">
                                            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                            <button type="submit" class="text-[11px] text-red-400 hover:underline">Удалить</button>
                                        </form>
                                        <?php else: ?>
                                        <span class="text-[11px] text-slate-500 cursor-not-allowed">Удалить</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
        <?php endif; ?>
    </div>
</main>
<script src="/assets/js/motion.js"></script>
<?php if (is_demo_mode()): ?>
<script>(function(){var k='demo_pages_visited';var v=[];try{v=JSON.parse(sessionStorage.getItem(k)||'[]');}catch(e){}var p=location.pathname;if(v.indexOf(p)===-1){v.push(p);try{sessionStorage.setItem(k,JSON.stringify(v));}catch(e){}}})();</script>
<?php endif; ?>
</body>
</html>
