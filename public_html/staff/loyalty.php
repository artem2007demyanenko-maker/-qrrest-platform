<?php
require_once __DIR__ . '/../../app/bootstrap.php';

require_login();

if (!$currentRestaurant) {
    http_response_code(404);
    echo "Restaurant context required";
    exit;
}

require_restaurant_role((int)$currentRestaurant['id'], ['owner', 'admin', 'staff']);

$pdo = db();
$restId = (int)$currentRestaurant['id'];

// Soft loyalty gating: allow read-only; mutations blocked on issue/scan (demo unchanged).
$loyaltyEnabledByPlan = true;
if (!is_demo_mode() && file_exists(__DIR__ . '/../../app/subscription_plans.php')) {
    require_once __DIR__ . '/../../app/subscription_plans.php';
    $loyaltyEnabledByPlan = function_exists('check_feature') && check_feature($restId, 'loyalty_enabled');
}

$analytics = [
    'cards_issued' => 0,
    'total_points_issued' => 0,
    'total_points_spent' => 0,
    'active_guests' => 0,
    'recent_activity' => [],
];

if (function_exists('is_demo_mode') && is_demo_mode()) {
    $analytics['cards_issued'] = 12;
    $analytics['total_points_issued'] = 480;
    $analytics['total_points_spent'] = 120;
    $analytics['active_guests'] = 8;
    $analytics['recent_activity'] = [
        ['type' => 'accrual', 'points' => 10, 'created_at' => date('Y-m-d H:i:s', strtotime('-1 hour'))],
        ['type' => 'spend', 'points' => -5, 'created_at' => date('Y-m-d H:i:s', strtotime('-2 hours'))],
    ];
} elseif (function_exists('guest_loyalty_analytics')) {
    $analytics = guest_loyalty_analytics($pdo, $restId);
}

if (!function_exists('e')) {
    function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <title>Лояльность — <?= e($currentRestaurant['name']) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-950 text-slate-50">
  <div class="max-w-4xl mx-auto p-4 space-y-6">
    <div>
      <div class="text-xs text-slate-400">STAFF</div>
      <h1 class="text-2xl font-semibold">Лояльность</h1>
      <div class="text-sm text-slate-400"><?= e($currentRestaurant['name']) ?></div>
    </div>

    <?php if (!$loyaltyEnabledByPlan): ?>
    <div class="rounded-2xl border border-amber-500/50 bg-amber-500/10 px-4 py-3 text-sm text-amber-200" role="status">
      <p class="font-medium">Программа лояльности доступна на тарифе PRO</p>
      <p class="text-xs text-amber-200/80 mt-1">Подключите loyalty, чтобы начислять баллы, удерживать гостей и повышать повторные визиты.</p>
      <a href="/owner/billing.php" class="inline-flex items-center mt-3 px-4 py-2 rounded-xl bg-amber-600 hover:bg-amber-500 text-white text-sm font-medium">Перейти на тариф PRO</a>
    </div>
    <?php endif; ?>

    <div class="grid sm:grid-cols-2 gap-4">
      <a href="/staff/loyalty_issue.php" class="rounded-3xl bg-slate-900/70 border border-slate-800 p-5 hover:border-emerald-500/60 transition-colors block <?= $loyaltyEnabledByPlan ? '' : 'opacity-75 pointer-events-none' ?>">
        <div class="text-sm font-semibold text-slate-100">Оформить карту гостя</div>
        <div class="text-xs text-slate-400 mt-1">По телефону. Гость должен быть зарегистрирован в Wallet.</div>
      </a>
      <a href="/staff/loyalty_scan.php" class="rounded-3xl bg-slate-900/70 border border-slate-800 p-5 hover:border-emerald-500/60 transition-colors block <?= $loyaltyEnabledByPlan ? '' : 'opacity-75 pointer-events-none' ?>">
        <div class="text-sm font-semibold text-slate-100">Сканировать QR</div>
        <div class="text-xs text-slate-400 mt-1">Начислить или списать баллы по QR из приложения гостя.</div>
      </a>
    </div>

    <section class="rounded-3xl bg-slate-900/70 border border-slate-800 p-5">
      <h2 class="text-sm font-semibold text-slate-200 mb-4">Аналитика лояльности</h2>
      <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
        <div class="rounded-2xl bg-slate-950/60 border border-slate-800 px-4 py-3">
          <div class="text-[11px] text-slate-400">Карт выдано</div>
          <div class="text-xl font-semibold text-slate-50"><?= (int)$analytics['cards_issued'] ?></div>
        </div>
        <div class="rounded-2xl bg-slate-950/60 border border-slate-800 px-4 py-3">
          <div class="text-[11px] text-slate-400">Баллов начислено</div>
          <div class="text-xl font-semibold text-emerald-400"><?= (int)$analytics['total_points_issued'] ?></div>
        </div>
        <div class="rounded-2xl bg-slate-950/60 border border-slate-800 px-4 py-3">
          <div class="text-[11px] text-slate-400">Баллов списано</div>
          <div class="text-xl font-semibold text-rose-400"><?= (int)$analytics['total_points_spent'] ?></div>
        </div>
        <div class="rounded-2xl bg-slate-950/60 border border-slate-800 px-4 py-3">
          <div class="text-[11px] text-slate-400">Гостей с балансом &gt; 0</div>
          <div class="text-xl font-semibold text-slate-50"><?= (int)$analytics['active_guests'] ?></div>
        </div>
      </div>
      <?php if (!empty($analytics['recent_activity'])): ?>
      <div class="mt-4 pt-4 border-t border-slate-800">
        <div class="text-[11px] text-slate-400 mb-2">Последняя активность</div>
        <ul class="space-y-1 text-xs">
          <?php foreach (array_slice($analytics['recent_activity'], 0, 10) as $a): ?>
            <li class="flex justify-between gap-2">
              <span><?= ($a['type'] ?? '') === 'accrual' ? '+' : '' ?><?= (int)($a['points'] ?? 0) ?> баллов</span>
              <span class="text-slate-500"><?= !empty($a['created_at']) ? e(date('d.m.y H:i', strtotime($a['created_at']))) : '' ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
      <?php endif; ?>
    </section>

    <div class="text-xs text-slate-500">
      <a href="/staff/orders.php" class="hover:text-slate-300">← Заказы</a>
    </div>
  </div>
</body>
</html>
