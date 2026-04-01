<?php
/**
 * Growth suggestions: view and manage saved suggestions (lifecycle: accept / dismiss).
 */

require_once __DIR__ . '/../../app/bootstrap.php';
if (file_exists(__DIR__ . '/../../app/feedback_crm_bridge.php')) {
    require_once __DIR__ . '/../../app/feedback_crm_bridge.php';
}
if (file_exists(__DIR__ . '/../../app/feedback_growth_automation.php')) {
    require_once __DIR__ . '/../../app/feedback_growth_automation.php';
}
if (file_exists(__DIR__ . '/../../app/loyalty_return_mode.php')) {
    require_once __DIR__ . '/../../app/loyalty_return_mode.php';
}
if (file_exists(__DIR__ . '/../../app/retention_analytics.php')) {
    require_once __DIR__ . '/../../app/retention_analytics.php';
}
require_login();
require_current_restaurant();
require_current_restaurant_role(['owner', 'admin']);

$restId = (int) $currentRestaurant['id'];

if (!function_exists('e')) {
    function e($v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

$success = null;
$error = null;
$statusFilter = isset($_GET['status']) ? trim($_GET['status']) : 'pending';
if (!in_array($statusFilter, ['pending', 'accepted', 'dismissed', 'all'], true)) {
    $statusFilter = 'pending';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !is_demo_mode()) {
    $csrfOk = isset($_POST['csrf'], $_SESSION['csrf']) && hash_equals((string) $_SESSION['csrf'], (string) $_POST['csrf']);
    if ($csrfOk) {
        $action = trim($_POST['action'] ?? '');
        $suggestionId = (int) ($_POST['suggestion_id'] ?? 0);
        $redirectTo = trim($_POST['redirect_to'] ?? '');
        if ($action === 'dismiss' && $suggestionId > 0 && file_exists(__DIR__ . '/../../app/growth_engine_arch.php')) {
            require_once __DIR__ . '/../../app/growth_engine_arch.php';
            if (growth_engine_dismiss_suggestion($restId, $suggestionId)) {
                $success = 'Предложение скрыто.';
            } else {
                $error = 'Не удалось скрыть.';
            }
        } elseif ($action === 'accept' && $suggestionId > 0 && file_exists(__DIR__ . '/../../app/growth_engine_arch.php')) {
            require_once __DIR__ . '/../../app/growth_engine_arch.php';
            $suggestionType = '';
            try {
                if (function_exists('db')) {
                    $pdo = db();
                    $st = $pdo->prepare("SELECT type FROM growth_engine_suggestions WHERE id = ? AND restaurant_id = ? LIMIT 1");
                    $st->execute([$suggestionId, $restId]);
                    $suggestionType = (string)($st->fetchColumn() ?: '');
                }
            } catch (Throwable $e) {}

            $useLoyaltyOffer = in_array($suggestionType, ['loyalty_recovery_offer', 'loyalty_return_offer'], true);
            $useBridge = in_array($suggestionType, [
                'feedback_recovery_draft',
                'feedback_positive_return_draft',
                'feedback_recovery_upsell',
                'feedback_neutral_upsell',
                'feedback_loyalty_upsell',
            ], true);
            if ($useLoyaltyOffer && function_exists('loyalty_return_mode_accept_suggestion')) {
                $loyRes = loyalty_return_mode_accept_suggestion($restId, $suggestionId);
                if (!empty($loyRes['ok'])) {
                    $success = (string)($loyRes['message'] ?? 'Предложение принято.');
                } elseif (!empty($loyRes['message'])) {
                    $error = (string)$loyRes['message'];
                }
            } elseif ($useBridge && function_exists('feedback_crm_bridge_accept_suggestion')) {
                $bridgeResult = feedback_crm_bridge_accept_suggestion($restId, $suggestionId);
                if (!empty($bridgeResult['ok'])) {
                    $success = (string)($bridgeResult['message'] ?? 'Предложение принято.');
                    if ($redirectTo === '') {
                        $redirectTo = '/restaurant/crm.php#feedback-suggestions';
                    }
                } elseif (!empty($bridgeResult['message'])) {
                    $error = (string)$bridgeResult['message'];
                }
            }
            if ($error === null && $success === null && $suggestionType === 'crm_retention_with_offer'
                && function_exists('retention_ensure_campaign_payload_on_accept')
            ) {
                retention_ensure_campaign_payload_on_accept($restId, $suggestionId);
            }
            if ($error === null && $success === null && growth_engine_accept_suggestion($restId, $suggestionId)) {
                if ($redirectTo !== '' && preg_match('/^\/restaurant\//', $redirectTo)) {
                    header('Location: ' . $redirectTo);
                    exit;
                }
                $success = 'Предложение принято.';
            }
        } elseif ($action === 'publish_feedback_growth' && function_exists('publish_feedback_growth_suggestions')) {
            $created = publish_feedback_growth_suggestions($restId, 30);
            if ($created > 0) {
                $success = 'Созданы growth-подсказки из отзывов: ' . $created . '.';
            } else {
                $error = 'Новых feedback-based growth подсказок не найдено.';
            }
        }
    }
}

$suggestions = [];
if (file_exists(__DIR__ . '/../../app/growth_engine_arch.php')) {
    require_once __DIR__ . '/../../app/growth_engine_arch.php';
    try {
        if (is_demo_mode()) {
            $suggestions = growth_engine_demo_suggestions_list();
            if ($statusFilter !== 'pending' && $statusFilter !== 'all') {
                $suggestions = array_values(array_filter($suggestions, function ($s) use ($statusFilter) {
                    return ($s['status'] ?? '') === $statusFilter;
                }));
            }
        } else {
            $suggestions = growth_engine_list_suggestions($restId, $statusFilter, 50);
        }
    } catch (Throwable $e) {
        $error = 'Не удалось загрузить предложения.';
    }
}

?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Предложения роста — <?= e($currentRestaurant['name']) ?></title>
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
        <a href="/restaurant/growth_suggestions.php" class="block px-3 py-2 rounded-lg bg-slate-800/70">Предложения роста</a>
        <a href="/restaurant/revenue.php" class="block px-3 py-2 rounded-lg hover:bg-slate-800/60">Доход</a>
        <a href="/restaurant/crm.php" class="block px-3 py-2 rounded-lg hover:bg-slate-800/60">CRM</a>
        <a href="/restaurant/crm_campaigns.php" class="block px-3 py-2 rounded-lg hover:bg-slate-800/60">CRM кампании</a>
        <a href="/restaurant/menu_items.php" class="block px-3 py-2 rounded-lg hover:bg-slate-800/60">Блюда</a>
        <a href="/restaurant/upsell_rules.php" class="block px-3 py-2 rounded-lg hover:bg-slate-800/60">Правила допродаж</a>
        <a href="/restaurant/settings.php" class="block px-3 py-2 rounded-lg hover:bg-slate-800/60">Настройки</a>
        <a href="/logout.php" class="block px-3 py-2 rounded-lg hover:bg-slate-800/60 text-red-300">Выйти</a>
    </nav>
</aside>

<main class="flex-1 p-4">
    <div class="max-w-4xl mx-auto space-y-4">
        <?php if (is_demo_mode()): ?>
        <div class="rounded-xl bg-amber-500/10 border border-amber-500/40 px-4 py-2.5 flex items-center gap-2 text-sm text-amber-200">
            <span aria-hidden="true">⚠</span>
            <span>Демо — данные не сохраняются.</span>
        </div>
        <?php endif; ?>

        <header class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-2xl font-bold">Предложения роста</h2>
            <div class="flex items-center gap-2">
                <?php if (!is_demo_mode() && function_exists('publish_feedback_growth_suggestions')): ?>
                <form method="post" class="inline">
                    <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                    <input type="hidden" name="action" value="publish_feedback_growth">
                    <button type="submit" class="px-3 py-1.5 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-medium">
                        Создать growth-подсказки из отзывов
                    </button>
                </form>
                <?php endif; ?>
                <a href="/restaurant/dashboard.php" class="text-sm text-slate-400 hover:text-slate-200">← Панель управления</a>
            </div>
        </header>

        <?php if ($success): ?>
        <div class="rounded-xl bg-emerald-500/10 border border-emerald-500/40 px-4 py-3 text-sm text-emerald-200"><?= e($success) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
        <div class="rounded-xl bg-red-500/10 border border-red-500/40 px-4 py-3 text-sm text-red-200"><?= e($error) ?></div>
        <?php endif; ?>

        <div class="flex flex-wrap gap-2 mb-3">
            <a href="?status=pending" class="px-3 py-1.5 rounded-xl text-xs font-medium <?= $statusFilter === 'pending' ? 'bg-slate-700 text-slate-100' : 'bg-slate-800/60 text-slate-400 hover:text-slate-200' ?>">Ожидают</a>
            <a href="?status=accepted" class="px-3 py-1.5 rounded-xl text-xs font-medium <?= $statusFilter === 'accepted' ? 'bg-slate-700 text-slate-100' : 'bg-slate-800/60 text-slate-400 hover:text-slate-200' ?>">Приняты</a>
            <a href="?status=dismissed" class="px-3 py-1.5 rounded-xl text-xs font-medium <?= $statusFilter === 'dismissed' ? 'bg-slate-700 text-slate-100' : 'bg-slate-800/60 text-slate-400 hover:text-slate-200' ?>">Скрыты</a>
            <a href="?status=all" class="px-3 py-1.5 rounded-xl text-xs font-medium <?= $statusFilter === 'all' ? 'bg-slate-700 text-slate-100' : 'bg-slate-800/60 text-slate-400 hover:text-slate-200' ?>">Все</a>
        </div>

        <section class="rounded-xl border border-gray-800 bg-[#121826] shadow-xl overflow-hidden card-motion">
            <?php if (empty($suggestions)): ?>
            <div class="p-8 text-center text-slate-400">
                <p class="font-medium text-slate-300">Нет предложений</p>
                <p class="text-sm mt-1">Нажмите «Создать черновики и предложения» на панели, чтобы создать предложения.</p>
                <a href="/restaurant/dashboard.php" class="inline-block mt-3 px-4 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-medium">Перейти в панель</a>
            </div>
            <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-slate-400 border-b border-slate-700 bg-slate-900/50">
                            <th class="px-4 py-3">Тип</th>
                            <th class="px-4 py-3">Название</th>
                            <th class="px-4 py-3">Приоритет</th>
                            <th class="px-4 py-3">Источник</th>
                            <th class="px-4 py-3">Создано</th>
                            <th class="px-4 py-3">Действия</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($suggestions as $s): ?>
                        <tr class="border-b border-slate-800/80 hover:bg-slate-800/30">
                            <td class="px-4 py-3 text-slate-300"><?= e($s['type'] ?? '—') ?></td>
                            <td class="px-4 py-3">
                                <span class="font-medium text-slate-100"><?= e($s['title'] ?? '') ?></span>
                                <?php if (!empty($s['description'])): ?>
                                <p class="text-xs text-slate-500 mt-0.5"><?= e($s['description']) ?></p>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3">
                                <?php
                                $p = $s['priority'] ?? 'medium';
                                $pClass = $p === 'high' ? 'bg-amber-500/20 text-amber-300' : ($p === 'low' ? 'bg-slate-600 text-slate-400' : 'bg-sky-500/20 text-sky-300');
                                ?>
                                <span class="px-2 py-0.5 rounded text-xs font-medium <?= $pClass ?>"><?= e($p) ?></span>
                            </td>
                            <td class="px-4 py-3 text-slate-500 text-xs"><?= e($s['source'] ?? '—') ?></td>
                            <td class="px-4 py-3 text-slate-500 text-xs"><?= !empty($s['created_at']) ? e(date('M j, Y H:i', strtotime($s['created_at']))) : '—' ?></td>
                            <td class="px-4 py-3">
                                <?php if (($s['status'] ?? '') === 'pending'): ?>
                                <?php
                                $link = '/restaurant/dashboard.php';
                                if (($s['type'] ?? '') === 'crm_campaign_draft') $link = '/restaurant/crm_campaigns.php';
                                elseif (($s['type'] ?? '') === 'combo_suggestion') $link = '/restaurant/dashboard.php';
                                elseif (($s['type'] ?? '') === 'feedback_recovery_draft' || ($s['type'] ?? '') === 'feedback_positive_return_draft') {
                                    $link = '/restaurant/crm.php#feedback-suggestions';
                                }
                                elseif (($s['type'] ?? '') === 'feedback_recovery_upsell' || ($s['type'] ?? '') === 'feedback_neutral_upsell' || ($s['type'] ?? '') === 'feedback_loyalty_upsell') {
                                    $link = '/restaurant/crm.php#feedback-upsell-drafts';
                                }
                                elseif (($s['type'] ?? '') === 'menu_promote') {
                                    $payload = !empty($s['payload_json']) ? json_decode($s['payload_json'], true) : [];
                                    $link = '/restaurant/menu_items.php' . (isset($payload['menu_item_id']) && $payload['menu_item_id'] ? '?edit=' . (int)$payload['menu_item_id'] : '');
                                }
                                ?>
                                <form method="post" class="inline mr-1">
                                    <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                                    <input type="hidden" name="action" value="accept">
                                    <input type="hidden" name="suggestion_id" value="<?= (int)($s['id']) ?>">
                                    <input type="hidden" name="redirect_to" value="<?= e($link) ?>">
                                    <?php
                                    $type = (string)($s['type'] ?? '');
                                    $btnText = 'Создать комбо';
                                    if ($type === 'crm_campaign_draft') {
                                        $btnText = 'Создать кампанию';
                                    } elseif ($type === 'menu_promote') {
                                        $btnText = 'Открыть блюдо';
                                    } elseif ($type === 'feedback_recovery_upsell' || $type === 'feedback_neutral_upsell' || $type === 'feedback_loyalty_upsell') {
                                        $btnText = 'Подготовить предложение гостю';
                                    } elseif ($type === 'feedback_recovery_draft' || $type === 'feedback_positive_return_draft') {
                                        $btnText = 'Открыть CRM';
                                    }
                                    ?>
                                    <button type="submit" class="inline-block px-2 py-1 rounded-lg bg-indigo-600/80 hover:bg-indigo-500 text-white text-xs font-medium"><?= e($btnText) ?></button>
                                </form>
                                <?php if (!is_demo_mode()): ?>
                                <form method="post" class="inline" onsubmit="return confirm('Скрыть это предложение?');">
                                    <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                                    <input type="hidden" name="action" value="dismiss">
                                    <input type="hidden" name="suggestion_id" value="<?= (int)($s['id']) ?>">
                                    <button type="submit" class="inline-block px-2 py-1 rounded-lg bg-slate-700 hover:bg-slate-600 text-slate-300 text-xs font-medium">Скрыть</button>
                                </form>
                                <?php endif; ?>
                                <?php else: ?>
                                <span class="text-slate-500 text-xs"><?= e($s['status'] ?? '') ?></span>
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
<script src="/assets/js/motion.js"></script>
</body>
</html>
