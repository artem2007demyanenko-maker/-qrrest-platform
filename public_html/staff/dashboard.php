<?php
require_once __DIR__ . '/../../app/bootstrap.php';
require_login();

if (!$currentRestaurant) {
    http_response_code(404);
    echo 'Restaurant context required';
    exit;
}
require_restaurant_role((int)$currentRestaurant['id'], ['staff', 'admin', 'owner']);

if (!function_exists('e')) {
    function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Панель сотрудников — <?= e($currentRestaurant['name']) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-950 text-slate-50">
<main class="max-w-4xl mx-auto p-4 md:p-6 space-y-4">
    <header>
        <div class="text-xs text-slate-400">STAFF</div>
        <h1 class="text-2xl font-semibold">Панель сотрудников</h1>
        <p class="text-sm text-slate-400 mt-1"><?= e($currentRestaurant['name']) ?></p>
    </header>

    <section class="grid gap-3 sm:grid-cols-2">
        <a href="/staff/kds.php" class="rounded-2xl border border-emerald-500/40 bg-emerald-500/10 px-4 py-4 hover:bg-emerald-500/15">
            <div class="text-base font-semibold text-emerald-100">Кухня (KDS)</div>
            <div class="text-xs text-emerald-200/80 mt-1">Реальное время: новые, в работе, готово</div>
        </a>
        <a href="/staff/orders.php" class="rounded-2xl border border-slate-700 bg-slate-900/70 px-4 py-4 hover:bg-slate-800/70">
            <div class="text-base font-semibold">Заказы</div>
            <div class="text-xs text-slate-400 mt-1">Список заказов и статусы</div>
        </a>
        <a href="/staff/pos.php" class="rounded-2xl border border-slate-700 bg-slate-900/70 px-4 py-4 hover:bg-slate-800/70">
            <div class="text-base font-semibold">POS</div>
            <div class="text-xs text-slate-400 mt-1">Создание заказа сотрудником</div>
        </a>
        <a href="/staff/floorplan.php" class="rounded-2xl border border-slate-700 bg-slate-900/70 px-4 py-4 hover:bg-slate-800/70">
            <div class="text-base font-semibold">Карта зала</div>
            <div class="text-xs text-slate-400 mt-1">Статусы столов и навигация</div>
        </a>
    </section>
</main>
</body>
</html>

