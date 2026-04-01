<?php

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/crm_repo.php';
require_once __DIR__ . '/../../app/crm_campaign_repo.php';

$rid = bin2hex(random_bytes(4));
set_exception_handler(function (Throwable $e) use ($rid) {
    error_log('RESTAURANT_CRM_CAMPAIGNS rid=' . $rid . ' ' . $e->getMessage());
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

// Soft CRM gating: allow read-only; block mutations when feature disabled (demo unchanged).
$crmEnabled = true;
if (!is_demo_mode() && file_exists(__DIR__ . '/../../app/subscription_plans.php')) {
    require_once __DIR__ . '/../../app/subscription_plans.php';
    $crmEnabled = function_exists('check_feature') && check_feature($restId, 'crm_enabled');
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
$segmentLabels = [
    'first_visit' => 'Первый визит',
    'no_visit_7_days' => 'Нет визита 7 дней',
    'no_visit_14_days' => 'Нет визита 14 дней',
    'vip_guests' => 'VIP (3+ визита)',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (is_demo_mode()) {
        $errors[] = 'В демо-режиме запись отключена.';
    } elseif (!$crmEnabled) {
        $errors[] = 'CRM-возврат гостей доступен на тарифе GROWTH. Подключите CRM в разделе тарифов.';
    } else {
        $csrfOk = isset($_SESSION['csrf'], $_POST['csrf']) && hash_equals((string)$_SESSION['csrf'], (string)$_POST['csrf']);
        if (!$csrfOk) {
            $errors[] = 'Неверный токен. Обновите страницу.';
        } else {
            $action = trim($_POST['action'] ?? '');
            if ($action === 'add_template') {
                $name = trim($_POST['template_name'] ?? '');
                $channel = trim($_POST['channel'] ?? 'stub');
                $text = trim($_POST['template_text'] ?? '');
                if ($name === '' || $text === '') {
                    $errors[] = 'Название и текст шаблона обязательны.';
                } else {
                    try {
                        crm_template_create($restId, $name, $channel, $text);
                        $success = 'Шаблон добавлен.';
                    } catch (Throwable $e) {
                        error_log('RESTAURANT_CRM_CAMPAIGNS_TEMPLATE_ERROR rid=' . $rid . ' ' . $e->getMessage());
                        $errors[] = 'Не удалось сохранить шаблон. Попробуйте позже.';
                    }
                }
            } elseif ($action === 'add_campaign') {
                $name = trim($_POST['campaign_name'] ?? '');
                $templateId = (int)($_POST['template_id'] ?? 0);
                $segmentType = trim($_POST['segment_type'] ?? '');
                $delayDays = (int)($_POST['delay_days'] ?? 0);
                if ($name === '' || $templateId <= 0 || !isset($segmentLabels[$segmentType])) {
                    $errors[] = 'Заполните название, выберите шаблон и сегмент.';
                } else {
                    try {
                        crm_campaign_create($restId, $name, $templateId, $segmentType, $delayDays);
                        $success = 'Кампания создана.';
                    } catch (Throwable $e) {
                        error_log('RESTAURANT_CRM_CAMPAIGNS_CREATE_ERROR rid=' . $rid . ' ' . $e->getMessage());
                        $errors[] = 'Не удалось создать кампанию. Попробуйте позже.';
                    }
                }
            } elseif ($action === 'run') {
                $campaignId = (int)($_POST['campaign_id'] ?? 0);
                if ($campaignId > 0) {
                    $count = crm_campaign_run($restId, $campaignId);
                    $success = "Кампания запущена: запланировано сообщений: {$count}.";
                }
            }
        }
    }
}

$templates = crm_template_list($restId);
$campaigns = crm_campaign_list($restId);
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>CRM кампании — <?= e($currentRestaurant['name']) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?= brand_head_tags() ?>

    <script src="https://cdn.tailwindcss.com"></script>
    <link href="/assets/css/polish.css" rel="stylesheet">
</head>
<body class="min-h-screen bg-slate-950 text-slate-50 flex">

<aside class="w-64 bg-slate-950/80 border-r border-slate-800 p-4 hidden md:block">
    <?= brand_restaurant_sidebar_header_html($currentRestaurant['name']) ?>
    <nav class="space-y-2 text-sm">
        <a href="/restaurant/dashboard.php" class="block px-3 py-2 rounded-xl hover:bg-slate-800/60">Обзор</a>
        <a href="/restaurant/revenue.php" class="block px-3 py-2 rounded-xl hover:bg-slate-800/60">Доход</a>
        <a href="/restaurant/menu_categories.php" class="block px-3 py-2 rounded-xl hover:bg-slate-800/60">Категории меню</a>
        <a href="/restaurant/menu_items.php" class="block px-3 py-2 rounded-xl hover:bg-slate-800/60">Блюда</a>
        <a href="/restaurant/tables.php" class="block px-3 py-2 rounded-xl hover:bg-slate-800/60">Столы и QR</a>
        <a href="/restaurant/qr_print.php" class="block px-3 py-2 rounded-xl hover:bg-slate-800/60">QR Print</a>
        <a href="/restaurant/floorplan.php" class="block px-3 py-2 rounded-xl hover:bg-slate-800/60">Карта столов</a>
        <a href="/restaurant/orders.php" class="block px-3 py-2 rounded-xl hover:bg-slate-800/60">Заказы</a>
        <a href="/restaurant/upsells.php" class="block px-3 py-2 rounded-xl hover:bg-slate-800/60">Допродажи</a>
        <a href="/restaurant/upsell_rules.php" class="block px-3 py-2 rounded-xl hover:bg-slate-800/60">Правила допродаж</a>
        <a href="/restaurant/analytics_upsell.php" class="block px-3 py-2 rounded-xl hover:bg-slate-800/60">Аналитика допродаж</a>
        <a href="/restaurant/crm.php" class="block px-3 py-2 rounded-xl hover:bg-slate-800/60">CRM</a>
        <a href="/restaurant/crm_campaigns.php" class="block px-3 py-2 rounded-xl bg-slate-800/70">CRM кампании</a>
        <a href="/restaurant/staff.php" class="block px-3 py-2 rounded-xl hover:bg-slate-800/60">Сотрудники</a>
        <a href="/restaurant/settings.php" class="block px-3 py-2 rounded-xl hover:bg-slate-800/60">Настройки</a>
        <a href="/logout.php" class="block px-3 py-2 rounded-xl hover:bg-slate-800/60 text-red-300">Выйти</a>
    </nav>
</aside>

<main class="flex-1 p-4">
    <div class="max-w-4xl mx-auto space-y-4">
        <header>
            <h2 class="text-2xl font-bold mb-1">CRM кампании</h2>
            <p class="text-xs text-slate-500">Шаблоны сообщений и кампании по сегментам гостей.</p>
        </header>

        <?php if (!$crmEnabled): ?>
        <div class="rounded-2xl border border-amber-500/50 bg-amber-500/10 px-4 py-3 text-sm text-amber-200" role="status">
            <p class="font-medium">CRM-возврат гостей доступен на тарифе GROWTH</p>
            <p class="text-xs text-amber-200/80 mt-1">Подключите CRM, чтобы возвращать гостей и запускать кампании.</p>
            <a href="/owner/billing.php" class="inline-flex items-center mt-3 px-4 py-2 rounded-xl bg-amber-600 hover:bg-amber-500 text-white text-sm font-medium">Перейти на тариф GROWTH</a>
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
            <h3 class="text-sm font-semibold mb-3">Добавить шаблон</h3>
            <form method="post" class="space-y-3">
                <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                <input type="hidden" name="action" value="add_template">
                <div>
                    <label class="block text-[11px] text-slate-400 mb-1">Название</label>
                    <input type="text" name="template_name" class="w-full max-w-md rounded-2xl bg-slate-950 border border-slate-700 px-3 py-2 text-sm" placeholder="Например: Возврат через 7 дней" <?= $crmEnabled ? '' : 'readonly' ?>>
                </div>
                <div>
                    <label class="block text-[11px] text-slate-400 mb-1">Канал</label>
                    <input type="text" name="channel" value="stub" class="w-24 rounded-2xl bg-slate-950 border border-slate-700 px-3 py-2 text-sm" <?= $crmEnabled ? '' : 'readonly' ?>>
                </div>
                <div>
                    <label class="block text-[11px] text-slate-400 mb-1">Текст сообщения</label>
                    <textarea name="template_text" rows="3" class="w-full max-w-md rounded-2xl bg-slate-950 border border-slate-700 px-3 py-2 text-sm" placeholder="Спасибо за визит! Вернитесь и получите десерт 🎁" <?= $crmEnabled ? '' : 'readonly' ?>></textarea>
                </div>
                <button type="submit" class="px-4 py-2 rounded-2xl bg-emerald-500 hover:bg-emerald-400 text-slate-950 text-sm font-semibold" <?= $crmEnabled ? '' : 'disabled' ?>>Добавить шаблон</button>
            </form>
        </section>

        <section class="bg-slate-900/80 border border-slate-800 rounded-3xl p-4">
            <h3 class="text-sm font-semibold mb-3">Создать кампанию</h3>
            <form method="post" class="flex flex-wrap items-end gap-3">
                <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                <input type="hidden" name="action" value="add_campaign">
                <div class="min-w-[180px]">
                    <label class="block text-[11px] text-slate-400 mb-1">Название кампании</label>
                    <input type="text" name="campaign_name" class="w-full rounded-2xl bg-slate-950 border border-slate-700 px-3 py-2 text-sm" placeholder="Например: Возврат 7 дней" <?= $crmEnabled ? '' : 'readonly' ?>>
                </div>
                <div class="min-w-[180px]">
                    <label class="block text-[11px] text-slate-400 mb-1">Шаблон</label>
                    <select name="template_id" class="w-full rounded-2xl bg-slate-950 border border-slate-700 px-3 py-2 text-sm" <?= $crmEnabled ? '' : 'disabled' ?>>
                        <option value="">— выбрать —</option>
                        <?php foreach ($templates as $t): ?>
                            <option value="<?= (int)$t['id'] ?>"><?= e($t['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="min-w-[160px]">
                    <label class="block text-[11px] text-slate-400 mb-1">Сегмент</label>
                    <select name="segment_type" class="w-full rounded-2xl bg-slate-950 border border-slate-700 px-3 py-2 text-sm" <?= $crmEnabled ? '' : 'disabled' ?>>
                        <?php foreach ($segmentLabels as $k => $label): ?>
                            <option value="<?= e($k) ?>"><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="w-24">
                    <label class="block text-[11px] text-slate-400 mb-1">Задержка (дней)</label>
                    <input type="number" name="delay_days" value="0" min="0" max="365" class="w-full rounded-2xl bg-slate-950 border border-slate-700 px-3 py-2 text-sm" <?= $crmEnabled ? '' : 'readonly' ?>>
                </div>
                <button type="submit" class="px-4 py-2 rounded-2xl bg-emerald-500 hover:bg-emerald-400 text-slate-950 text-sm font-semibold" <?= $crmEnabled ? '' : 'disabled' ?>>Создать кампанию</button>
            </form>
            <?php if (empty($templates)): ?>
                <p class="text-xs text-slate-500 mt-2">Сначала добавьте шаблон выше.</p>
            <?php endif; ?>
        </section>

        <section class="bg-slate-900/80 border border-slate-800 rounded-3xl p-4">
            <h3 class="text-sm font-semibold mb-3">Кампании</h3>
            <?php if (empty($campaigns)): ?>
                <div class="empty-state-box text-left">
                    <svg class="empty-state-icon mx-auto text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                    <div class="empty-state-title">No campaigns yet</div>
                    <div class="empty-state-text">Create a template above, then add a campaign to send comeback messages to guests.</div>
                    <a href="/restaurant/crm.php" class="inline-block px-4 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-medium btn-motion">Guest return opportunities</a>
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-slate-400 border-b border-slate-700">
                                <th class="pb-2 pr-2">Название</th>
                                <th class="pb-2 pr-2">Шаблон</th>
                                <th class="pb-2 pr-2">Сегмент</th>
                                <th class="pb-2 pr-2">Задержка</th>
                                <th class="pb-2">Действие</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($campaigns as $c): ?>
                                <tr class="border-b border-slate-800/80">
                                    <td class="py-2 pr-2"><?= e($c['name']) ?></td>
                                    <td class="py-2 pr-2"><?= e($c['template_name'] ?? '—') ?></td>
                                    <td class="py-2 pr-2"><?= e($segmentLabels[$c['segment_type']] ?? $c['segment_type']) ?></td>
                                    <td class="py-2 pr-2"><?= (int)$c['delay_days'] ?> дн.</td>
                                    <td class="py-2">
                                        <?php if ($crmEnabled): ?>
                                        <form method="post" class="inline">
                                            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                                            <input type="hidden" name="action" value="run">
                                            <input type="hidden" name="campaign_id" value="<?= (int)$c['id'] ?>">
                                            <button type="submit" class="text-[11px] text-emerald-400 hover:underline">Запустить</button>
                                        </form>
                                        <?php else: ?>
                                        <span class="text-[11px] text-slate-500 cursor-not-allowed">Запустить</span>
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
