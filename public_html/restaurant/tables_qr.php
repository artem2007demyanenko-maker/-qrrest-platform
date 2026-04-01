<?php

$rid = bin2hex(random_bytes(4));
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/qr_helpers.php';
set_exception_handler(function (Throwable $e) use ($rid) {
    error_log('STABILITY_ERROR restaurant/tables_qr.php rid=' . $rid . ' ' . $e->getMessage());
    if (!headers_sent()) {
        http_response_code(500);
    }
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Error</title></head><body><p>Something went wrong.</p><p style="font-size:11px;color:#666">RID: ' . htmlspecialchars($rid) . '</p></body></html>';
    exit;
});

require_login();
require_current_restaurant();
require_restaurant_role((int)($currentRestaurant['id'] ?? 0), ['owner', 'admin']);

if (!function_exists('e')) {
    function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

$isPrintMode = isset($_GET['print']) && $_GET['print'] === '1';

$config     = require __DIR__ . '/../../app/config.php';
$mainDomain = $config['app']['main_domain'] ?? 'lvh.me';
$protocol   = $config['app']['protocol'] ?? 'http';
$subdomain  = $currentRestaurant['subdomain'] ?? 'demo';

$pdo    = db();
$restId = (int)$currentRestaurant['id'];

$stmt = $pdo->prepare("SELECT id, name, qr_code_path FROM tables WHERE restaurant_id = ? ORDER BY id ASC");
$stmt->execute([$restId]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$tables = [];
foreach ($rows as $row) {
    $tableId = (int)($row['id'] ?? 0);
    $tableName = trim((string)($row['name'] ?? ''));
    if ($tableId <= 0 || $tableName === '') {
        continue;
    }

    $guestUrl = $protocol . '://' . $subdomain . '.' . $mainDomain . '/qr.php?table_id=' . $tableId;
    $qrPath = (string)($row['qr_code_path'] ?? '');
    $qrPublicPath = '';
    if ($qrPath !== '') {
        $qrPath = trim($qrPath);
        $qrPath = str_replace("\0", '', $qrPath);
        $qrPath = ltrim($qrPath, '/');
        // Harden against traversal / unexpected paths: accept only our generated pattern.
        if ($qrPath !== '' && strpos($qrPath, '..') === false && preg_match('~^qr/rest_[0-9]+_table_[0-9]+\\.png$~', $qrPath) === 1) {
            $candidate = __DIR__ . '/../storage/' . $qrPath;
            if (is_file($candidate)) {
                $qrPublicPath = '/storage/' . $qrPath;
            }
        }
    }

    if ($qrPublicPath === '') {
        $generated = generate_table_qr_image($currentRestaurant, ['id' => $tableId, 'name' => $tableName]);
        $qrPublicPath = '/storage/' . ltrim($generated, '/');
        if ($generated !== $qrPath) {
            $up = $pdo->prepare("UPDATE tables SET qr_code_path = :qr WHERE id = :id AND restaurant_id = :rest");
            $up->execute([
                'qr' => $generated,
                'id' => $tableId,
                'rest' => $restId,
            ]);
        }
    }

    $tables[] = [
        'id' => $tableId,
        'name' => $tableName,
        'guest_url' => $guestUrl,
        'qr_public_path' => $qrPublicPath,
    ];
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?= brand_head_tags() ?>
    <title>QR для столов — <?= e($currentRestaurant['name']) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @media print {
            body { background: #fff !important; color: #000 !important; }
            .no-print { display: none !important; }
            .print-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; }
            .qr-card { break-inside: avoid; page-break-inside: avoid; border-color: #ddd !important; background: #fff !important; }
        }
    </style>
</head>
<body class="min-h-screen bg-slate-950 text-slate-50 flex">
<?php if (!$isPrintMode): ?>
<aside class="no-print w-64 bg-slate-950/80 border-r border-slate-800 p-4 hidden md:block">
    <?= brand_restaurant_sidebar_header_html($currentRestaurant['name']) ?>
    <nav class="space-y-2 text-sm">
        <a href="/restaurant/dashboard.php" class="block px-3 py-2 rounded-xl hover:bg-slate-800/60">Обзор</a>
        <a href="/restaurant/revenue.php" class="block px-3 py-2 rounded-xl hover:bg-slate-800/60">Доход</a>
        <a href="/restaurant/menu_categories.php" class="block px-3 py-2 rounded-xl hover:bg-slate-800/60">Категории меню</a>
        <a href="/restaurant/menu_items.php" class="block px-3 py-2 rounded-xl hover:bg-slate-800/60">Блюда</a>
        <a href="/restaurant/tables.php" class="block px-3 py-2 rounded-xl hover:bg-slate-800/60">Столы и QR</a>
        <a href="/restaurant/tables_qr.php" class="block px-3 py-2 rounded-xl bg-slate-800/70">QR для столов</a>
        <a href="/restaurant/floorplan.php" class="block px-3 py-2 rounded-xl hover:bg-slate-800/60">Карта столов</a>
        <a href="/restaurant/orders.php" class="block px-3 py-2 rounded-xl hover:bg-slate-800/60">Заказы</a>
        <a href="/restaurant/settings.php" class="block px-3 py-2 rounded-xl hover:bg-slate-800/60">Настройки</a>
        <a href="/logout.php" class="block px-3 py-2 rounded-xl hover:bg-slate-800/60 text-red-300">Выйти</a>
    </nav>
</aside>
<?php endif; ?>

<main class="flex-1 p-4 <?= $isPrintMode ? '' : 'md:pl-6' ?>">
    <div class="max-w-6xl mx-auto space-y-4">
        <header class="no-print flex flex-wrap items-center justify-between gap-3 mb-2">
            <div>
                <h2 class="text-2xl font-bold mb-1">QR для столов</h2>
                <p class="text-xs text-slate-500">Печать карточек: ресторан, стол, QR, инструкция для гостей.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="/restaurant/tables.php" class="px-3 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-sm text-slate-100">К столам</a>
                <a href="/restaurant/tables_qr.php?print=1" class="px-3 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-sm text-slate-100">Print view</a>
                <button type="button" onclick="window.print();" class="px-3 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-sm text-white">Печать всех</button>
            </div>
        </header>

        <?php if (empty($tables)): ?>
            <div class="rounded-2xl border border-slate-800 bg-slate-900/80 p-6 text-center space-y-2">
                <p class="text-slate-300 text-sm">Нет столов.</p>
                <a href="/restaurant/tables.php" class="inline-flex items-center px-4 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-medium">
                    Добавить столы
                </a>
            </div>
        <?php else: ?>
            <section class="print-grid grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                <?php foreach ($tables as $t): ?>
                    <article class="qr-card rounded-2xl border border-slate-700 bg-slate-900/80 p-4 text-center space-y-2">
                        <div class="text-sm font-semibold text-slate-200"><?= e($currentRestaurant['name']) ?></div>
                        <div class="text-xl font-bold text-slate-50"><?= e($t['name']) ?></div>
                        <div class="flex justify-center">
                            <img src="<?= e($t['qr_public_path']) ?>" alt="QR <?= e($t['name']) ?>" class="w-56 h-56 object-contain rounded-lg bg-white p-2">
                        </div>
                        <div class="text-[11px] text-slate-400 break-all"><?= e($t['guest_url']) ?></div>
                        <div class="text-xs text-slate-300">Сканируйте QR для просмотра меню и заказа</div>
                        <div class="no-print pt-1 flex items-center justify-center gap-2">
                            <button type="button" onclick="printSingleCard(this)" class="px-3 py-1.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-xs text-slate-100">Печать стола</button>
                            <a href="<?= e($t['qr_public_path']) ?>" target="_blank" rel="noopener" class="px-3 py-1.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-xs text-slate-100">Открыть QR</a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>
    </div>
</main>
<?php if (!$isPrintMode): ?>
<script>
function printSingleCard(button) {
    var card = button.closest('.qr-card');
    if (!card) return;
    var original = document.body.innerHTML;
    document.body.innerHTML = '<div style="padding:24px;">' + card.outerHTML + '</div>';
    window.print();
    document.body.innerHTML = original;
    window.location.reload();
}
</script>
<?php endif; ?>
</body>
</html>

