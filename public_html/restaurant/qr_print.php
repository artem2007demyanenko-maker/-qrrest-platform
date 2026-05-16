<?php
/**
 * QR print: list tables, show QR for each (api.qrserver.com), printable grid. Button "Print all QR codes".
 */

$rid = bin2hex(random_bytes(4));
require_once __DIR__ . '/../../app/bootstrap.php';
set_exception_handler(function (Throwable $e) use ($rid) {
    error_log('STABILITY_ERROR restaurant/qr_print.php rid=' . $rid . ' ' . $e->getMessage());
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
    function e($v): string {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

$config   = require __DIR__ . '/../../app/config.php';
$mainDomain = $config['app']['main_domain'] ?? 'lvh.me';
$protocol   = $config['app']['protocol'] ?? 'http';
$subdomain  = $currentRestaurant['subdomain'] ?? 'demo';
$baseUrl    = $protocol . '://' . $subdomain . '.' . $mainDomain . '/qr.php';
$landingUrl = $protocol . '://' . $mainDomain;

$pdo    = db();
$restId = (int)$currentRestaurant['id'];

if ($restId > 0 && function_exists('onboarding_progress_mark_visited_qr_print')) {
    require_once __DIR__ . '/../../app/onboarding_progress.php';
    onboarding_progress_mark_visited_qr_print($restId);
}

$stmt = $pdo->prepare("
    SELECT t.id, t.name FROM tables AS t
    WHERE t.restaurant_id = ?
    " . qr_public_sql_exclude_delivery($pdo, 't') . "
    ORDER BY t.name
");
$stmt->execute([$restId]);
$tables = $stmt->fetchAll(PDO::FETCH_ASSOC);

$qrBase = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?= brand_head_tags() ?>
    <title>Печать QR-кодов — <?= e($currentRestaurant['name']) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @media print {
            body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .no-print { display: none !important; }
            .print-card { break-inside: avoid; page-break-inside: avoid; }
            .qr-print-grid { gap: 1rem; }
        }
        @media screen {
            .print-card { min-width: 0; }
        }
    </style>
</head>
<body class="min-h-screen bg-slate-950 text-slate-50">
<div class="min-h-screen flex flex-col">
    <?php
    $restaurantSidebarActive = 'qr_print';
    $restaurantSidebarName = (string)($currentRestaurant['name'] ?? 'Ресторан');
    require __DIR__ . '/_sidebar_mobile.php';
    ?>
    <header class="no-print p-4 border-b border-slate-800 flex items-center justify-between">
        <h1 class="text-xl font-semibold">QR-коды столов</h1>
        <div class="flex items-center gap-2">
            <a href="/restaurant/tables.php" class="px-3 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-sm">Столы и QR</a>
            <a href="/restaurant/qr_print_pdf.php" target="_blank" rel="noopener" class="px-4 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-medium">Скачать PDF</a>
            <button type="button" onclick="window.print();" class="px-4 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white text-sm font-medium">Печать всех QR-кодов</button>
        </div>
    </header>

    <?php
    $restaurantSidebarActive = 'qr_print';
    $restaurantSidebarName = (string)($currentRestaurant['name'] ?? 'Ресторан');
    $restaurantSidebarNavClass = 'space-y-2 text-sm';
    ?>
    <aside class="no-print w-64 bg-slate-950/80 border-r border-slate-800 p-4 hidden md:block fixed left-0 top-0 bottom-0">
        <?= brand_restaurant_sidebar_header_html($restaurantSidebarName) ?>
        <?php require __DIR__ . '/_sidebar_nav.php'; ?>
    </aside>

    <main class="flex-1 p-4 md:pl-72 pt-20 md:pt-4">
        <div class="no-print">
            <?php
            $operationalNavActive = 'qr_print';
            require __DIR__ . '/_restaurant_cabinet_context.php';
            require __DIR__ . '/_restaurant_operational_nav.php';
            ?>
        </div>
        <?php if (empty($tables)): ?>
            <p class="text-slate-400">Нет столов. <a href="/restaurant/tables.php" class="text-sky-300 hover:underline">Добавить столы</a>.</p>
        <?php else: ?>
            <div class="qr-print-grid grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
                <?php foreach ($tables as $t): ?>
                    <?php
                    $url = $baseUrl . '?table_id=' . (int)$t['id'];
                    $qrUrl = $qrBase . urlencode($url);
                    $restName = $currentRestaurant['name'] ?? 'Restaurant';
                    ?>
                    <div class="print-card rounded-2xl border border-slate-700 bg-slate-900/80 p-4 flex flex-col items-center text-center">
                        <div class="text-sm font-semibold text-slate-200 mb-1"><?= e($restName) ?></div>
                        <div class="text-lg font-bold text-slate-50 mb-2"><?= e($t['name']) ?></div>
                        <img src="<?= e($qrUrl) ?>" alt="QR <?= e($t['name']) ?>" class="w-[200px] h-[200px] md:w-[240px] md:h-[240px] rounded-lg flex-shrink-0" width="300" height="300">
                        <div class="mt-2 text-xs text-slate-400">Scan to order</div>
                        <div class="mt-3 pt-2 border-t border-slate-700/80 w-full flex items-center justify-center gap-1.5 text-[10px] text-slate-500">
                            <span>QR menu · <a href="<?= e($landingUrl) ?>" target="_blank" rel="noopener" class="text-slate-500 hover:text-indigo-400 no-print"><?= e($mainDomain) ?></a></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </main>
</div>
</body>
</html>
