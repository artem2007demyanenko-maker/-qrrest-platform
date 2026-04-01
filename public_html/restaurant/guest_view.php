<?php
/**
 * Guest visit timeline: visits count, total spent, last visit, timeline list.
 */

require_once __DIR__ . '/../../app/bootstrap.php';
require_login();
require_current_restaurant();
require_current_restaurant_role(['owner', 'admin']);

$restId = (int) $currentRestaurant['id'];
$guestId = (int) ($_GET['guest_id'] ?? 0);
$phone = trim((string) ($_GET['phone'] ?? ''));

if (!function_exists('e')) {
    function e($v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
}

$timeline = ['visits' => [], 'visits_count' => 0, 'total_spent' => 0.0, 'last_visit' => null, 'guest' => null];
if ($guestId > 0 || $phone !== '') {
    if (file_exists(__DIR__ . '/../../app/guest_timeline.php')) {
        require_once __DIR__ . '/../../app/guest_timeline.php';
        $timeline = get_guest_timeline($restId, $guestId, $phone !== '' ? $phone : null);
    }
}
if (is_demo_mode() && empty($timeline['visits']) && $guestId > 0) {
    $timeline = [
        'guest' => ['id' => $guestId, 'phone' => '+7 916 100-42-18', 'visits_count' => 4, 'last_seen_at' => date('Y-m-d H:i:s', strtotime('-2 days'))],
        'visits_count' => 4,
        'total_spent' => 18420.0,
        'last_visit' => date('Y-m-d H:i:s', strtotime('-2 days')),
        'visits' => [
            ['date' => date('Y-m-d H:i:s', strtotime('-2 days')), 'amount' => 5240, 'order_id' => 1001, 'items_preview' => 'Стейк рибай, капучино ×2'],
            ['date' => date('Y-m-d H:i:s', strtotime('-12 days')), 'amount' => 4180, 'order_id' => 1002, 'items_preview' => 'Паста карбонара, лимонад'],
            ['date' => date('Y-m-d H:i:s', strtotime('-25 days')), 'amount' => 3820, 'order_id' => 1003, 'items_preview' => 'Салат с креветками, тирамису'],
            ['date' => date('Y-m-d H:i:s', strtotime('-40 days')), 'amount' => 5180, 'order_id' => 1004, 'items_preview' => 'Бургер «Домашний», крылья BBQ'],
        ],
    ];
}
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Guest timeline — <?= e($currentRestaurant['name']) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?= brand_head_tags() ?>

    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-950 text-slate-50">
<div class="max-w-2xl mx-auto p-4 space-y-6">
    <a href="/restaurant/crm.php" class="inline-block text-sm text-slate-400 hover:text-slate-200">← CRM</a>
    <h1 class="text-xl font-bold text-slate-100">Guest timeline</h1>
    <?php if ($timeline['guest'] === null && $timeline['visits_count'] === 0): ?>
        <div class="rounded-xl border border-slate-800 bg-slate-900/80 p-6 text-center">
            <p class="text-slate-400">Guest not found or no visits.</p>
            <p class="text-xs text-slate-500 mt-2">Use guest_id or phone in URL.</p>
        </div>
    <?php else: ?>
        <div class="rounded-xl border border-slate-800 bg-slate-900/80 p-4 grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
            <div><span class="text-slate-500 block">Visits</span><span class="text-lg font-semibold text-slate-100"><?= (int) $timeline['visits_count'] ?></span></div>
            <div><span class="text-slate-500 block">Total spent</span><span class="text-lg font-semibold text-emerald-400"><?= number_format($timeline['total_spent'], 0) ?> ₽</span></div>
            <div><span class="text-slate-500 block">Last visit</span><span class="text-slate-200"><?= $timeline['last_visit'] ? e(date('M j, Y H:i', strtotime($timeline['last_visit']))) : '—' ?></span></div>
            <?php if ($timeline['guest'] && isset($timeline['guest']['phone'])): ?>
            <div><span class="text-slate-500 block">Phone</span><span class="text-slate-200"><?= e($timeline['guest']['phone']) ?></span></div>
            <?php endif; ?>
        </div>
        <div class="rounded-xl border border-slate-800 bg-slate-900/80 p-4">
            <h2 class="text-sm font-semibold text-slate-300 mb-3">Visit history</h2>
            <ul class="space-y-2">
                <?php foreach ($timeline['visits'] as $v): ?>
                <li class="flex justify-between gap-2 rounded-lg bg-slate-800/60 px-3 py-2 text-sm">
                    <div>
                        <span class="text-slate-200"><?= $v['date'] ? e(date('M j, Y H:i', strtotime($v['date']))) : '—' ?></span>
                        <span class="text-slate-500 block text-xs"><?= e($v['items_preview']) ?></span>
                    </div>
                    <span class="font-medium text-emerald-400"><?= number_format($v['amount'], 0) ?> ₽</span>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php if (empty($timeline['visits'])): ?>
            <p class="text-slate-500 text-sm py-4 text-center">No visits recorded.</p>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
