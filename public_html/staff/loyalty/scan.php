<?php
require_once __DIR__ . '/../../app/bootstrap.php';

require_login();

if (!$currentRestaurant) {
    http_response_code(404);
    echo "Restaurant context required";
    exit;
}

require_restaurant_role((int)$currentRestaurant['id'], ['owner','admin','staff']);

$enabled = restaurant_loyalty_enabled($currentRestaurant);
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
  <div class="max-w-4xl mx-auto p-4 space-y-4">
    <div class="flex items-center justify-between">
      <div>
        <div class="text-xs text-slate-400">STAFF</div>
        <h1 class="text-2xl font-semibold">Лояльность</h1>
        <div class="text-sm text-slate-400"><?= e($currentRestaurant['name']) ?></div>
      </div>
      <a href="/staff/orders.php" class="px-3 py-2 rounded-2xl bg-slate-900 border border-slate-800 text-xs hover:bg-slate-800">← Заказы</a>
    </div>

    <?php if (!$enabled): ?>
      <div class="rounded-3xl bg-amber-500/10 border border-amber-500/60 px-4 py-3 text-sm text-amber-100">
        Лояльность сейчас выключена для этого ресторана. (Включение/проценты настроим потом.)
        Но выдача карт и ручные операции уже готовы.
      </div>
    <?php endif; ?>

    <div class="grid sm:grid-cols-2 gap-3">
      <a href="/staff/loyalty_issue.php" class="rounded-3xl bg-slate-900/70 border border-slate-800 p-4 hover:border-emerald-500/60">
        <div class="text-lg font-semibold">Оформить карту гостя</div>
        <div class="text-sm text-slate-400 mt-1">Введите телефон — система создаст гостя (если нет) и выпустит карту для этого ресторана.</div>
      </a>

      <a href="/staff/loyalty_scan.php" class="rounded-3xl bg-slate-900/70 border border-slate-800 p-4 hover:border-emerald-500/60">
        <div class="text-lg font-semibold">Сканировать QR</div>
        <div class="text-sm text-slate-400 mt-1">Сканируйте QR из Wallet гостя → начислить/списать баллы.</div>
      </a>
    </div>
  </div>
</body>
</html>
