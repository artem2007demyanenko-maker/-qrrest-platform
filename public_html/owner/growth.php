<?php
// public_html/owner/growth.php — Рост: рефералы, метрики, апселл (Variant 6)

$config     = require __DIR__ . '/../../app/config.php';
$mainDomain = $config['app']['main_domain'] ?? 'localhost';
$protocol   = $config['app']['protocol'] ?? 'http';
$host = $_SERVER['HTTP_HOST'] ?? '';
$host = preg_replace('/:\d+$/', '', $host);
$isMainHost = (strtolower($host) === strtolower($mainDomain)) || (strtolower($host) === strtolower('www.' . $mainDomain));
if (!$isMainHost) {
    $uri = $_SERVER['REQUEST_URI'] ?? '/owner/growth.php';
    safe_redirect($protocol . '://' . $mainDomain . $uri);
}

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/schema_guard.php';
require_once __DIR__ . '/../../app/billing.php';
require_once __DIR__ . '/../../app/growth.php';

require_login();
require_role(['owner', 'project_owner']);
$user = auth_user();
$userId = (int)($user['id'] ?? 0);
if ($userId <= 0) {
    safe_redirect('/owner/dashboard.php');
}

if (!schema_guard_growth_ready()) {
    error_log('SCHEMA_MISSING growth user_id=' . $userId . ' uri=' . ($_SERVER['REQUEST_URI'] ?? ''));
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="ru"><head><meta charset="utf-8"><title>Рост</title></head><body class="min-h-screen bg-slate-950 text-slate-50 flex items-center justify-center"><div class="max-w-md p-6 text-center"><h1 class="text-xl font-semibold text-amber-200 mb-2">Growth unavailable</h1><p class="text-slate-400">Database migrations for growth features have not been applied yet. Please apply migrations and try again.</p><p class="mt-4"><a href="/owner/dashboard.php" class="text-emerald-400 hover:underline">← Back to dashboard</a></p></div></body></html>';
    exit;
}

$referral = ['id' => 0, 'code' => '', 'clicks_count' => 0, 'conversions_count' => 0];
$referralStats = ['code' => '', 'clicks_count' => 0, 'conversions_count' => 0];
$usageData = [];
$suggestions = [];
try {
    $referral = growth_ensure_referral_code($userId);
    $referralStats = growth_get_referral_stats($userId);
    $usageChartDays = isset($_GET['days']) ? max(7, min(90, (int)$_GET['days'])) : 30;
    $usageData = growth_get_usage_for_chart($userId, $usageChartDays);
    $suggestions = growth_upsell_suggestions($userId);
} catch (Throwable $e) {
    error_log('STABILITY_ERROR owner/growth.php user_id=' . $userId . ' uri=' . ($_SERVER['REQUEST_URI'] ?? '') . ' ' . $e->getMessage());
}

$couponUsages = [];
try {
    $pdo = db();
    $stmt = $pdo->prepare("
        SELECT c.code, c.type, c.value, cu.created_at
        FROM coupon_usages cu
        JOIN coupons c ON c.id = cu.coupon_id
        WHERE cu.user_id = :uid
        ORDER BY cu.created_at DESC
        LIMIT 10
    ");
    $stmt->execute(['uid' => $userId]);
    $couponUsages = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $couponUsages = [];
}

$appName = $config['app']['name'] ?? 'QR-Rest';
$debugEnabled = isset($_GET['debug']) && (($user['global_role'] ?? '') === 'owner');

$refLink = $referralStats['code'] !== ''
    ? ($protocol . '://' . $mainDomain . '/login.php?ref=' . urlencode($referralStats['code']))
    : '';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Рост и рефералы — <?= e($appName) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-950 text-slate-50">
<div class="max-w-4xl mx-auto p-4">
    <header class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-bold">Рост и рефералы</h1>
        <a href="/owner/dashboard.php" class="text-sm text-slate-400 hover:text-slate-200">← Назад в кабинет</a>
    </header>

    <section class="mb-8 p-4 rounded-2xl bg-slate-900/80 border border-slate-700">
        <h2 class="text-lg font-semibold mb-2">Реферальная ссылка</h2>
        <p class="text-sm text-slate-400 mb-2">Делитесь ссылкой — новые владельцы, зарегистрированные по ней, учитываются в конверсиях. Награда (если настроена) сохраняется в подписке.</p>
        <?php if ($referralStats['code'] !== ''): ?>
            <div class="flex flex-wrap items-center gap-2">
                <input type="text" id="refLink" readonly value="<?= e($refLink) ?>"
                       class="flex-1 min-w-[200px] py-2 px-3 rounded-lg bg-slate-800 border border-slate-600 text-sm text-slate-100 font-mono">
                <button type="button" onclick="navigator.clipboard.writeText(document.getElementById('refLink').value); this.textContent='Скопировано'; setTimeout(()=>this.textContent='Копировать', 2000)"
                        class="py-2 px-4 rounded-lg bg-slate-700 hover:bg-slate-600 text-sm">
                    Копировать
                </button>
            </div>
            <div class="mt-3 flex gap-4 text-sm">
                <span class="text-slate-300">Клики: <strong><?= (int)$referralStats['clicks_count'] ?></strong></span>
                <span class="text-slate-300">Регистрации: <strong><?= (int)$referralStats['conversions_count'] ?></strong></span>
            </div>
        <?php else: ?>
            <p class="text-slate-500">Реферальный код не найден. Обновите страницу.</p>
        <?php endif; ?>
    </section>

    <section class="mb-8 p-4 rounded-2xl bg-slate-900/80 border border-slate-700">
        <h2 class="text-lg font-semibold mb-2">Использованные промокоды</h2>
        <?php if (empty($couponUsages)): ?>
            <p class="text-slate-500 text-sm">Вы пока не использовали промокоды.</p>
        <?php else: ?>
            <ul class="text-sm space-y-1">
                <?php foreach ($couponUsages as $u): ?>
                    <li class="text-slate-300">
                        <strong><?= e($u['code']) ?></strong>
                        (<?= $u['type'] === 'percent' ? (float)$u['value'] . '%' : (float)$u['value'] . ' ₽' ?>)
                        — <?= e(date('d.m.Y H:i', strtotime($u['created_at']))) ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <section class="mb-8 p-4 rounded-2xl bg-slate-900/80 border border-slate-700">
        <h2 class="text-lg font-semibold mb-2">Метрики использования</h2>
        <p class="text-sm text-slate-400 mb-3">За последние <strong><?= $usageChartDays ?></strong> дней (UTC). <a href="?days=7" class="text-emerald-400 hover:underline">7 дней</a> · <a href="?days=30" class="text-emerald-400 hover:underline">30 дней</a></p>
        <?php if (empty($usageData)): ?>
            <p class="text-slate-500 text-sm">Нет данных за выбранный период.</p>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full text-sm text-left">
                    <thead>
                        <tr class="border-b border-slate-700">
                            <th class="py-2 pr-2">Дата</th>
                            <th class="py-2 pr-2">Рестораны</th>
                            <th class="py-2 pr-2">Заказы</th>
                            <th class="py-2 pr-2">Выручка</th>
                            <th class="py-2 pr-2">Сотрудники</th>
                            <th class="py-2 pr-2">Позиции меню</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_reverse($usageData) as $row): ?>
                            <tr class="border-b border-slate-800">
                                <td class="py-1 pr-2"><?= e($row['date']) ?></td>
                                <td class="py-1 pr-2"><?= (int)$row['restaurants_count'] ?></td>
                                <td class="py-1 pr-2"><?= (int)$row['orders_count'] ?></td>
                                <td class="py-1 pr-2"><?= number_format((float)$row['revenue'], 0, '.', ' ') ?> ₽</td>
                                <td class="py-1 pr-2"><?= (int)$row['staff_count'] ?></td>
                                <td class="py-1 pr-2"><?= (int)$row['menu_items_count'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="mb-8 p-4 rounded-2xl bg-slate-900/80 border border-slate-700">
        <h2 class="text-lg font-semibold mb-2">Рекомендации</h2>
        <?php if (empty($suggestions)): ?>
            <p class="text-slate-500 text-sm">Пока нет персональных рекомендаций.</p>
        <?php else: ?>
            <ul class="space-y-2">
                <?php foreach ($suggestions as $s): ?>
                    <li class="p-2 rounded-lg bg-slate-800/80 border border-slate-700">
                        <span class="font-medium text-amber-200"><?= e($s['title']) ?></span>
                        <p class="text-sm text-slate-400 mt-0.5"><?= e($s['message']) ?></p>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <?php if ($debugEnabled): ?>
    <section class="mt-6 p-4 rounded-xl bg-slate-900/80 border border-amber-500/50 text-xs font-mono">
        <h3 class="text-amber-200 mb-2">Debug (owner)</h3>
        <pre class="whitespace-pre-wrap overflow-x-auto text-slate-300"><?= e(json_encode([
            'referral'     => $referralStats,
            'usage_rows'   => count($usageData),
            'suggestions'  => $suggestions,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
    </section>
    <?php endif; ?>
</div>
</body>
</html>
