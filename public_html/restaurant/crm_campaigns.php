<?php

require_once __DIR__ . '/../../app/bootstrap.php';
if (file_exists(__DIR__ . '/../../app/schema_guard.php')) {
    require_once __DIR__ . '/../../app/schema_guard.php';
}
if (file_exists(__DIR__ . '/../../app/runtime_schema_bootstrap.php')) {
    require_once __DIR__ . '/../../app/runtime_schema_bootstrap.php';
}
if (file_exists(__DIR__ . '/../../app/billing.php')) {
    require_once __DIR__ . '/../../app/billing.php';
}
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
$authUser = auth_user();
if (function_exists('runtime_schema_ensure_crm_core')) {
    runtime_schema_ensure_crm_core(db());
}
$crmCampaignsPaywallContext = function_exists('billing_get_feature_paywall_context')
    ? billing_get_feature_paywall_context((int)($authUser['id'] ?? 0), $restId, 'crm_campaigns')
    : null;

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
$warnings = [];
$crmCampaignsSchema = [
    'crm_templates' => function_exists('db_table_exists') ? db_table_exists('crm_templates') : true,
    'crm_campaigns' => function_exists('db_table_exists') ? db_table_exists('crm_campaigns') : true,
    'crm_outbox' => function_exists('db_table_exists') ? db_table_exists('crm_outbox') : true,
];
$crmCampaignsSchemaReady = $crmCampaignsSchema['crm_templates'] && $crmCampaignsSchema['crm_campaigns'];
if (!$crmCampaignsSchemaReady) {
    foreach ($crmCampaignsSchema as $tableName => $isReady) {
        if (!$isReady) {
            $warnings[] = 'Нет части данных: таблица «' . $tableName . '» не найдена. Кампании доступны только для просмотра — примените миграции.';
            error_log('CRM_CAMPAIGNS_SCHEMA_MISSING table=' . $tableName . ' restaurant_id=' . $restId);
        }
    }
}
$crmCampaignsWriteAllowed = $crmEnabled && $crmCampaignsSchemaReady;
$crmCampaignsRunAllowed = $crmCampaignsWriteAllowed && $crmCampaignsSchema['crm_outbox'];
$segmentLabels = function_exists('crm_campaign_segment_labels')
    ? crm_campaign_segment_labels()
    : [
        'first_visit' => 'Первый визит',
        'no_visit_7_days' => 'Нет визита 7 дней',
        'no_visit_14_days' => 'Нет визита 14 дней',
        'vip_guests' => 'VIP (3+ визита)',
    ];
$loyaltyScenarioCatalog = function_exists('crm_loyalty_retention_segment_catalog')
    ? crm_loyalty_retention_segment_catalog()
    : [];
$loyaltyTemplateLibrary = function_exists('crm_loyalty_retention_template_library')
    ? crm_loyalty_retention_template_library()
    : [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));
    if (is_demo_mode()) {
        $errors[] = 'В демо-режиме запись отключена.';
    } elseif (!$crmCampaignsSchemaReady) {
        $errors[] = 'Невозможно сохранить: схема CRM-кампаний не готова. Примените миграции.';
    } elseif ($action === 'run' && !$crmCampaignsRunAllowed) {
        $errors[] = 'Запуск кампании недоступен: отсутствует таблица crm_outbox. Примените миграции.';
    } elseif (!$crmEnabled) {
        $errors[] = 'CRM-возврат гостей доступен на тарифе GROWTH. Подключите CRM в разделе тарифов.';
    } else {
        $csrfOk = isset($_SESSION['csrf'], $_POST['csrf']) && hash_equals((string)$_SESSION['csrf'], (string)$_POST['csrf']);
        if (!$csrfOk) {
            $errors[] = 'Неверный токен. Обновите страницу.';
        } else {
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

$templates = [];
$campaigns = [];
if ($crmCampaignsSchemaReady) {
    try {
        $templates = crm_template_list($restId);
    } catch (Throwable $e) {
        error_log('RESTAURANT_CRM_CAMPAIGNS_TEMPLATES_FAIL rid=' . $rid . ' ' . $e->getMessage());
        $warnings[] = 'Шаблоны временно недоступны. Проверьте схему БД.';
        $templates = [];
    }
    try {
        $campaigns = crm_campaign_list($restId);
    } catch (Throwable $e) {
        error_log('RESTAURANT_CRM_CAMPAIGNS_LIST_FAIL rid=' . $rid . ' ' . $e->getMessage());
        $warnings[] = 'Список кампаний временно недоступен. Проверьте схему БД.';
        $campaigns = [];
    }
}
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
<body class="min-h-screen bg-slate-950 text-slate-50 flex overflow-x-hidden">
<?php
$restaurantSidebarActive = 'crm_campaigns';
$restaurantSidebarName = (string)($currentRestaurant['name'] ?? 'Ресторан');
require __DIR__ . '/_sidebar_mobile.php';
?>

<?php require __DIR__ . '/_sidebar.php'; ?>

<main class="flex-1 min-w-0 p-4 md:p-6 overflow-x-hidden">
    <div class="max-w-6xl mx-auto space-y-4">
        <?php
        $businessNavActive = 'crm_campaigns';
        require __DIR__ . '/_restaurant_cabinet_context.php';
        require __DIR__ . '/_restaurant_business_nav.php';
        ?>
        <div class="max-w-4xl mx-auto space-y-4">
        <header>
            <h2 class="text-2xl font-bold mb-1">CRM кампании</h2>
            <p class="text-xs text-slate-500">Шаблоны и кампании для возврата гостей: от готовых loyalty-сценариев до ручных retention-запусков.</p>
        </header>

        <?php if (!$crmEnabled): ?>
        <?php $ctx = is_array($crmCampaignsPaywallContext) ? $crmCampaignsPaywallContext : []; ?>
        <div class="rounded-2xl border border-amber-500/50 bg-amber-500/10 px-4 py-4 text-sm text-amber-200" role="status">
            <div class="inline-flex items-center rounded-full border border-white/10 bg-white/5 px-3 py-1 text-[11px] font-semibold uppercase tracking-wide"><?= e((string)($ctx['phase_label'] ?? 'Следующий шаг')) ?></div>
            <p class="font-medium mt-3"><?= e((string)($ctx['title'] ?? 'CRM кампании и retention-запуски')) ?></p>
            <p class="text-xs text-amber-200/80 mt-1"><?= e((string)($ctx['subtitle'] ?? 'Подключите CRM, чтобы запускать retention-кампании по готовым сценариям.')) ?></p>
            <ul class="mt-3 space-y-2 text-xs text-amber-100/90">
                <?php foreach (array_slice((array)($ctx['benefits'] ?? []), 0, 3) as $benefit): ?>
                    <li class="flex items-start gap-2"><span class="mt-1">•</span><span><?= e((string)$benefit) ?></span></li>
                <?php endforeach; ?>
            </ul>
            <p class="text-[11px] text-amber-100/70 mt-3"><?= e((string)($ctx['preservation_text'] ?? '')) ?></p>
            <a href="<?= e((string)($ctx['cta_url'] ?? '/restaurant/activate.php?plan=growth')) ?>" class="inline-flex items-center mt-3 px-4 py-2 rounded-xl bg-amber-600 hover:bg-amber-500 text-white text-sm font-medium"><?= e((string)($ctx['cta_label'] ?? 'Открыть тариф GROWTH')) ?></a>
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
        <?php if ($warnings): ?>
            <div class="rounded-3xl bg-amber-500/10 border border-amber-500/60 px-4 py-3 text-sm text-amber-100 space-y-1" role="status">
                <?php foreach ($warnings as $warning): ?><div><?= e($warning) ?></div><?php endforeach; ?>
            </div>
        <?php endif; ?>

        <section class="bg-slate-900/80 border border-slate-800 rounded-3xl p-4">
            <h3 class="text-sm font-semibold mb-3">Добавить шаблон</h3>
            <form method="post" class="space-y-3">
                <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                <input type="hidden" name="action" value="add_template">
                <div>
                    <label class="block text-[11px] text-slate-400 mb-1">Название</label>
                    <input type="text" name="template_name" class="w-full max-w-md rounded-2xl bg-slate-950 border border-slate-700 px-3 py-2 text-sm" placeholder="Например: Возврат через 7 дней" <?= $crmCampaignsWriteAllowed ? '' : 'readonly' ?>>
                </div>
                <div>
                    <label class="block text-[11px] text-slate-400 mb-1">Канал</label>
                    <input type="text" name="channel" value="stub" class="w-24 rounded-2xl bg-slate-950 border border-slate-700 px-3 py-2 text-sm" <?= $crmCampaignsWriteAllowed ? '' : 'readonly' ?>>
                </div>
                <div>
                    <label class="block text-[11px] text-slate-400 mb-1">Текст сообщения</label>
                    <textarea name="template_text" rows="3" class="w-full max-w-md rounded-2xl bg-slate-950 border border-slate-700 px-3 py-2 text-sm" placeholder="Спасибо за визит! Вернитесь и получите десерт 🎁" <?= $crmCampaignsWriteAllowed ? '' : 'readonly' ?>></textarea>
                </div>
                <button type="submit" class="px-4 py-2 rounded-2xl bg-emerald-500 hover:bg-emerald-400 text-slate-950 text-sm font-semibold" <?= $crmCampaignsWriteAllowed ? '' : 'disabled' ?>>Добавить шаблон</button>
            </form>
        </section>

        <section class="bg-slate-900/80 border border-slate-800 rounded-3xl p-4">
            <h3 class="text-sm font-semibold mb-3">Создать кампанию</h3>
            <p class="text-xs text-slate-500 mb-3">К базовым CRM-сегментам добавлены loyalty-aware сегменты: по бонусному балансу, отсутствию возврата после первого оплаченного визита, давнему неиспользованию бонусов и ценным гостям.</p>
            <form method="post" class="flex flex-wrap items-end gap-3">
                <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                <input type="hidden" name="action" value="add_campaign">
                <div class="min-w-[180px]">
                    <label class="block text-[11px] text-slate-400 mb-1">Название кампании</label>
                    <input type="text" name="campaign_name" class="w-full rounded-2xl bg-slate-950 border border-slate-700 px-3 py-2 text-sm" placeholder="Например: Возврат 7 дней" <?= $crmCampaignsWriteAllowed ? '' : 'readonly' ?>>
                </div>
                <div class="min-w-[180px]">
                    <label class="block text-[11px] text-slate-400 mb-1">Шаблон</label>
                    <select name="template_id" class="w-full rounded-2xl bg-slate-950 border border-slate-700 px-3 py-2 text-sm" <?= $crmCampaignsWriteAllowed ? '' : 'disabled' ?>>
                        <option value="">— выбрать —</option>
                        <?php foreach ($templates as $t): ?>
                            <option value="<?= (int)$t['id'] ?>"><?= e($t['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="min-w-[160px]">
                    <label class="block text-[11px] text-slate-400 mb-1">Сегмент</label>
                    <select name="segment_type" class="w-full rounded-2xl bg-slate-950 border border-slate-700 px-3 py-2 text-sm" <?= $crmCampaignsWriteAllowed ? '' : 'disabled' ?>>
                        <?php foreach ($segmentLabels as $k => $label): ?>
                            <option value="<?= e($k) ?>"><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="w-24">
                    <label class="block text-[11px] text-slate-400 mb-1">Задержка (дней)</label>
                    <input type="number" name="delay_days" value="0" min="0" max="365" class="w-full rounded-2xl bg-slate-950 border border-slate-700 px-3 py-2 text-sm" <?= $crmCampaignsWriteAllowed ? '' : 'readonly' ?>>
                </div>
                <button type="submit" class="px-4 py-2 rounded-2xl bg-emerald-500 hover:bg-emerald-400 text-slate-950 text-sm font-semibold" <?= $crmCampaignsWriteAllowed ? '' : 'disabled' ?>>Создать кампанию</button>
            </form>
            <?php if (empty($templates)): ?>
                <p class="text-xs text-slate-500 mt-2">Сначала добавьте шаблон выше.</p>
            <?php endif; ?>
        </section>

        <?php if ($loyaltyScenarioCatalog !== []): ?>
        <section class="bg-slate-900/80 border border-slate-800 rounded-3xl p-4">
            <h3 class="text-sm font-semibold mb-3">Готовые loyalty retention-сценарии</h3>
            <div class="grid gap-3">
                <?php foreach ($loyaltyScenarioCatalog as $segmentKey => $scenario): ?>
                    <div class="rounded-2xl border border-slate-800 bg-slate-950/60 px-4 py-3 space-y-2">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div class="text-sm font-medium text-white"><?= e((string)($scenario['label'] ?? $segmentKey)) ?></div>
                            <div class="text-[11px] text-emerald-300">Сегмент кампании: <code><?= e($segmentKey) ?></code></div>
                        </div>
                        <div class="text-xs text-slate-400"><?= e((string)($scenario['description'] ?? '')) ?></div>
                        <?php if (!empty($scenario['goal'])): ?>
                            <div class="text-[11px] text-slate-500">Зачем запускать: <span class="text-slate-300"><?= e((string)$scenario['goal']) ?></span></div>
                        <?php endif; ?>
                        <?php if (!empty($scenario['offer_framing'])): ?>
                            <div class="text-[11px] text-slate-500">Как звучит сценарий: <span class="text-slate-300"><?= e((string)$scenario['offer_framing']) ?></span></div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

        <?php if ($loyaltyTemplateLibrary !== []): ?>
        <section class="bg-slate-900/80 border border-slate-800 rounded-3xl p-4">
            <h3 class="text-sm font-semibold mb-3">Библиотека retention-шаблонов</h3>
            <p class="text-xs text-slate-500 mb-3">Эти готовые шаблоны используются в loyalty CRM как быстрый выбор при создании черновика. Их можно применять как есть или брать за основу для ручного шаблона кампании.</p>
            <div class="grid gap-3">
                <?php foreach ($loyaltyTemplateLibrary as $templateKey => $templateCfg): ?>
                    <div class="rounded-2xl border border-slate-800 bg-slate-950/60 px-4 py-3 space-y-2">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div class="text-sm font-medium text-white"><?= e((string)($templateCfg['name'] ?? $templateKey)) ?></div>
                            <div class="text-[11px] text-emerald-300"><code><?= e($templateKey) ?></code></div>
                        </div>
                        <div class="text-xs text-slate-400"><?= e((string)($templateCfg['purpose'] ?? '')) ?></div>
                        <div class="text-xs text-slate-300 rounded-xl bg-slate-900/70 border border-slate-800 px-3 py-2"><?= e((string)($templateCfg['template_text'] ?? '')) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

        <section class="bg-slate-900/80 border border-slate-800 rounded-3xl p-4">
            <h3 class="text-sm font-semibold mb-3">Кампании</h3>
            <?php if (empty($campaigns)): ?>
                <div class="empty-state-box text-left">
                    <svg class="empty-state-icon mx-auto text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                    <div class="empty-state-title">Кампаний пока нет</div>
                    <div class="empty-state-text">Сначала подготовьте шаблон выше, затем соберите первую кампанию для возврата гостей по нужному сегменту.</div>
                    <a href="/restaurant/crm.php" class="inline-block px-4 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-medium btn-motion">Открыть CRM возврата</a>
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
                                        <?php if ($crmCampaignsRunAllowed): ?>
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
    </div>
</main>
</body>
</html>
