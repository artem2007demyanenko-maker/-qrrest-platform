<?php

require_once __DIR__ . '/../../app/bootstrap.php';
if (file_exists(__DIR__ . '/../../app/runtime_schema_bootstrap.php')) {
    require_once __DIR__ . '/../../app/runtime_schema_bootstrap.php';
}

$rid = bin2hex(random_bytes(4));
set_exception_handler(function (Throwable $e) use ($rid) {
    error_log('RESTAURANT_UPSELL_RULES rid=' . $rid . ' ' . $e->getMessage());
    if (!headers_sent()) {
        http_response_code(500);
    }
    echo '<h1>Ошибка</h1><p>Попробуйте позже.</p><p style="font-size:11px;color:#666">RID: ' . htmlspecialchars($rid) . '</p>';
    exit;
});

require_login();
require_current_restaurant();
require_current_restaurant_role(['owner', 'admin']);

$pdo = db();
$restId = (int)$currentRestaurant['id'];
if (function_exists('runtime_schema_ensure_upsell_rules')) {
    runtime_schema_ensure_upsell_rules($pdo);
}
$upsellRulesTableReady = function_exists('db_table_exists') ? db_table_exists('upsell_rules') : true;

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

$ruleTypes = [
    'exclude_if_in_cart' => 'Не предлагать то, что уже в корзине',
    'limit_per_session' => 'Лимит показов за сессию (value = число)',
    'limit_per_order' => 'Лимит допродаж за заказ (value = число)',
    'exclude_same_category' => 'Исключить ту же категорию',
    'exclude_same_item' => 'Исключить тот же товар',
    'max_items_to_show' => 'Макс. кол-во позиций (value = число)',
];

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

$errors = [];
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfOk = isset($_SESSION['csrf'], $_POST['csrf']) && hash_equals((string)$_SESSION['csrf'], (string)$_POST['csrf']);
    if (!$csrfOk) {
        $errors[] = 'Неверный токен. Обновите страницу.';
    } elseif (!$upsellRulesTableReady) {
        $errors[] = 'Таблица правил допродаж пока не создана. Примените миграции, чтобы редактировать правила.';
    } elseif (!$upsellEnabled) {
        $errors[] = 'Умные допродажи доступны на тарифе GROWTH. Подключите upsell в разделе тарифов.';
    } else {
        $action = trim($_POST['action'] ?? '');
        if ($action === 'add') {
            $ruleType = trim($_POST['rule_type'] ?? '');
            $ruleValue = trim($_POST['rule_value'] ?? '');
            $priority = (int)($_POST['priority'] ?? 100);
            $active = isset($_POST['active']) ? 1 : 0;
            if ($ruleType === '' || !isset($ruleTypes[$ruleType])) {
                $errors[] = 'Выберите тип правила.';
            } else {
                try {
                    $stmt = $pdo->prepare("INSERT INTO upsell_rules (restaurant_id, rule_type, rule_value, priority, active) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$restId, $ruleType, $ruleValue === '' ? null : $ruleValue, $priority, $active]);
                    $success = 'Правило добавлено.';
                } catch (Throwable $e) {
                    $errors[] = 'Ошибка сохранения. Таблица upsell_rules есть?';
                }
            }
        } elseif ($action === 'toggle') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                try {
                    $stmt = $pdo->prepare("UPDATE upsell_rules SET active = NOT active WHERE id = ? AND restaurant_id = ?");
                    $stmt->execute([$id, $restId]);
                    if ($stmt->rowCount()) {
                        $success = 'Статус обновлён.';
                    }
                } catch (Throwable $e) {}
            }
        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                try {
                    $stmt = $pdo->prepare("DELETE FROM upsell_rules WHERE id = ? AND restaurant_id = ?");
                    $stmt->execute([$id, $restId]);
                    if ($stmt->rowCount()) {
                        $success = 'Правило удалено.';
                    }
                } catch (Throwable $e) {}
            }
        }
    }
}

$rules = [];
if ($upsellRulesTableReady) {
    try {
        $stmt = $pdo->prepare("SELECT id, rule_type, rule_value, priority, active, created_at FROM upsell_rules WHERE restaurant_id = ? ORDER BY priority ASC, id ASC");
        $stmt->execute([$restId]);
        $rules = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('upsell_rules list rid=' . $rid . ' ' . $e->getMessage());
        $rules = [];
    }
}
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Правила допродаж — <?= e($currentRestaurant['name']) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?= brand_head_tags() ?>

    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-950 text-slate-50 flex">
<?php
$restaurantSidebarActive = 'upsell_rules';
$restaurantSidebarName = (string)($currentRestaurant['name'] ?? 'Ресторан');
require __DIR__ . '/_sidebar_mobile.php';
?>
<?php require __DIR__ . '/_sidebar.php'; ?>

<main class="flex-1 p-4">
    <div class="max-w-4xl mx-auto space-y-4">
        <header>
            <h2 class="text-2xl font-bold mb-1">Правила допродаж (Smart Rules v2)</h2>
            <p class="text-xs text-slate-500">Ограничения и фильтры для блоков «Часто берут вместе».</p>
        </header>

        <?php if (!$upsellEnabled): ?>
        <div class="rounded-2xl border border-amber-500/50 bg-amber-500/10 px-4 py-3 text-sm text-amber-200" role="status">
            <p class="font-medium">Умные допродажи доступны на тарифе GROWTH</p>
            <p class="text-xs text-amber-200/80 mt-1">Подключите upsell, чтобы увеличивать средний чек и показывать рекомендации к заказу.</p>
            <a href="/owner/billing.php" class="inline-flex items-center mt-3 px-4 py-2 rounded-xl bg-amber-600 hover:bg-amber-500 text-white text-sm font-medium">Перейти на тариф GROWTH</a>
        </div>
        <?php endif; ?>
        <?php if (!$upsellRulesTableReady): ?>
        <div class="rounded-2xl border border-slate-700 bg-slate-900/70 px-4 py-3 text-sm text-slate-200" role="status">
            Таблица `upsell_rules` отсутствует в текущей БД. Экран работает в safe read-only режиме без падений и без лог-флуда.
        </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="rounded-3xl bg-emerald-500/10 border border-emerald-500/60 px-4 py-3 text-sm text-emerald-100"><?= e($success) ?></div>
        <?php endif; ?>
        <?php if ($errors): ?>
            <div class="rounded-3xl bg-red-500/10 border border-red-500/60 px-4 py-3 text-sm text-red-100">
                <?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?>
            </div>
        <?php endif; ?>

        <section class="bg-slate-900/80 border border-slate-800 rounded-3xl p-4">
            <h3 class="text-sm font-semibold mb-3">Добавить правило</h3>
            <form method="post" class="flex flex-wrap items-end gap-3">
                <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                <input type="hidden" name="action" value="add">
                <div class="min-w-[200px]">
                    <label class="block text-[11px] text-slate-400 mb-1">Тип правила</label>
                    <select name="rule_type" class="w-full rounded-2xl bg-slate-950 border border-slate-700 px-3 py-2 text-sm" <?= ($upsellEnabled && $upsellRulesTableReady) ? '' : 'disabled' ?>>
                        <option value="">— выбрать —</option>
                        <?php foreach ($ruleTypes as $k => $label): ?>
                            <option value="<?= e($k) ?>"><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="w-24">
                    <label class="block text-[11px] text-slate-400 mb-1">Значение</label>
                    <input type="text" name="rule_value" placeholder="например 3" maxlength="128" class="w-full rounded-2xl bg-slate-950 border border-slate-700 px-3 py-2 text-sm" <?= ($upsellEnabled && $upsellRulesTableReady) ? '' : 'readonly' ?>>
                </div>
                <div class="w-20">
                    <label class="block text-[11px] text-slate-400 mb-1">Приоритет</label>
                    <input type="number" name="priority" value="100" min="0" max="999" class="w-full rounded-2xl bg-slate-950 border border-slate-700 px-3 py-2 text-sm" <?= ($upsellEnabled && $upsellRulesTableReady) ? '' : 'readonly' ?>>
                </div>
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" name="active" value="1" checked class="rounded bg-slate-950 border-slate-700" <?= ($upsellEnabled && $upsellRulesTableReady) ? '' : 'disabled' ?>>
                    Активно
                </label>
                <button type="submit" class="px-4 py-2 rounded-2xl bg-emerald-500 hover:bg-emerald-400 text-slate-950 text-sm font-semibold" <?= ($upsellEnabled && $upsellRulesTableReady) ? '' : 'disabled' ?>>Добавить</button>
            </form>
        </section>

        <section class="bg-slate-900/80 border border-slate-800 rounded-3xl p-4">
            <h3 class="text-sm font-semibold mb-3">Список правил</h3>
            <?php if (empty($rules)): ?>
                <p class="text-sm text-slate-400">Правил пока нет. Добавьте правило выше.</p>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-slate-400 border-b border-slate-700">
                                <th class="pb-2 pr-2">Тип</th>
                                <th class="pb-2 pr-2">Значение</th>
                                <th class="pb-2 pr-2">Приоритет</th>
                                <th class="pb-2 pr-2">Активно</th>
                                <th class="pb-2">Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rules as $r): ?>
                                <tr class="border-b border-slate-800/80">
                                    <td class="py-2 pr-2"><?= e($r['rule_type'] ?? '') ?></td>
                                    <td class="py-2 pr-2"><?= e($r['rule_value'] ?? '—') ?></td>
                                    <td class="py-2 pr-2"><?= (int)($r['priority'] ?? 100) ?></td>
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
    </div>
</main>
</body>
</html>
