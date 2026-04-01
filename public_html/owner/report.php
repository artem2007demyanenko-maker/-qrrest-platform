<?php
// public_html/owner/report.php
// Экспорт отчёта для владельца (CSV / PDF-HTML fallback) по scope (rest_id) и диапазону (range).

// ===== Domain guard: только основной домен =====
$config     = require __DIR__ . '/../../app/config.php';
$mainDomain = $config['app']['main_domain'] ?? 'localhost';
$protocol   = $config['app']['protocol'] ?? 'http';

$host = $_SERVER['HTTP_HOST'] ?? '';
$host = preg_replace('/:\d+$/', '', $host);

$isMainHost = (strtolower($host) === strtolower($mainDomain))
    || (strtolower($host) === strtolower('www.' . $mainDomain));

if (!$isMainHost) {
    $uri = $_SERVER['REQUEST_URI'] ?? '/owner/report.php';
    header('Location: ' . $protocol . '://' . $mainDomain . $uri);
    exit;
}

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/schema_guard.php';
require_once __DIR__ . '/../../app/stats.php';

require_login();
require_role(['owner', 'project_owner']);

$user  = auth_user();
$pdo   = db();

// ===== Параметры =====
$requestedRange = $_GET['range'] ?? '7d';
[$periodStart, $periodEnd, $range] = stats_period_range($requestedRange);

// ===== Доступные рестораны (как в dashboard) =====
$isProjectOwner = (($user['global_role'] ?? '') === 'project_owner');

$deletedSql = schema_guard_restaurants_deleted_sql('r');
if ($isProjectOwner) {
    $stmt = $pdo->query("
        SELECT r.*
        FROM restaurants r
        WHERE 1=1 {$deletedSql}
        ORDER BY r.id DESC
    ");
    $restaurants = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $stmt = $pdo->prepare("
        SELECT r.*
        FROM restaurants r
        JOIN users_restaurants ur ON ur.restaurant_id = r.id
        WHERE ur.user_id = :uid
          AND ur.restaurant_role = 'owner'
          {$deletedSql}
        ORDER BY r.id DESC
    ");
    $stmt->execute(['uid' => (int)$user['id']]);
    $restaurants = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$allowedIds = array_map(fn($r) => (int)$r['id'], $restaurants);

if (!$allowedIds) {
    http_response_code(403);
    echo "Нет доступных ресторанов для отчёта.";
    exit;
}

$requestedRest = $_GET['rest_id'] ?? 'all';
$scopeIds      = $allowedIds;
$scopeLabel    = 'all';

if ($requestedRest !== 'all') {
    $reqId = (int)$requestedRest;
    if ($reqId > 0 && in_array($reqId, $allowedIds, true)) {
        $scopeIds   = [$reqId];
        $scopeLabel = (string)$reqId;
    } else {
        // неверный rest_id: редирект на all с сохранением range/format
        $fmt      = $_GET['format'] ?? 'csv';
        $backRange = urlencode($requestedRange);
        $backFmt   = urlencode($fmt);
        header("Location: /owner/report.php?range={$backRange}&rest_id=all&format={$backFmt}");
        exit;
    }
}

// ===== Собираем payload отчёта =====
$summaryCurrent = stats_revenue_summary($scopeIds, $periodStart, $periodEnd);
$conversion     = stats_conversion($scopeIds, $periodStart, $periodEnd);
$topItems       = stats_top_items($scopeIds, $periodStart, $periodEnd, 3);
$topCategories  = stats_top_categories($scopeIds, $periodStart, $periodEnd, 3);
$margin         = stats_margin($scopeIds, $periodStart, $periodEnd);
$topShare       = stats_top_share($scopeIds, $periodStart, $periodEnd);
$heatmap        = stats_hourly_heatmap($scopeIds, $periodStart, $periodEnd);

$format = strtolower($_GET['format'] ?? 'csv');

if ($format === 'csv') {
    // ===== CSV экспорт =====
    $filename = sprintf(
        'owner-report_%s_%s_%s.csv',
        $range,
        $scopeLabel,
        date('Ymd_His')
    );

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    // BOM для Excel
    echo "\xEF\xBB\xBF";

    $out = fopen('php://output', 'w');
    $sep = ';';

    $fput = function(array $row) use ($out, $sep) {
        fputcsv($out, $row, $sep);
    };

    // Блок 1: информация о периоде и скоупе
    $fput(['Range', 'Start (MSK)', 'End (MSK)', 'Scope (rest_id)']);
    $fput([$range, $periodStart ?? '', $periodEnd ?? '', $scopeLabel]);
    $fput([]);

    // Блок 2: KPI summary
    $fput(['Summary']);
    $fput(['Revenue', 'Orders', 'Average check', 'Conversion paid %', 'Canceled %', 'Margin %', 'Top3 share %']);

    $convPaidPct = $conversion['conversion_paid_pct'] ?? null;
    $cancPct     = $conversion['canceled_pct'] ?? null;
    $marginPct   = $margin['margin_pct'] ?? null;
    $sharePct    = $topShare['share_pct'] ?? null;

    $fmtPct = function (?float $v): string {
        if ($v === null) return '';
        return (string)round($v, 2);
    };

    $fput([
        number_format((float)$summaryCurrent['revenue'], 2, ',', ' '),
        (int)$summaryCurrent['orders'],
        number_format((float)$summaryCurrent['avg'], 2, ',', ' '),
        $fmtPct($convPaidPct),
        $fmtPct($cancPct),
        $fmtPct($marginPct),
        $fmtPct($sharePct),
    ]);
    $fput([]);

    // Блок 3: Top Items
    $fput(['Top Items']);
    $fput(['Name', 'Qty', 'Revenue']);
    if ($topItems) {
        foreach ($topItems as $item) {
            $fput([
                $item['name'],
                (int)$item['qty'],
                number_format((float)$item['revenue'], 2, ',', ' '),
            ]);
        }
    } else {
        $fput(['Нет данных', '', '']);
    }
    $fput([]);

    // Блок 4: Top Categories
    $fput(['Top Categories']);
    $fput(['Name', 'Qty', 'Revenue']);
    if ($topCategories) {
        foreach ($topCategories as $cat) {
            $fput([
                $cat['name'],
                (int)$cat['qty'],
                number_format((float)$cat['revenue'], 2, ',', ' '),
            ]);
        }
    } else {
        $fput(['Нет данных', '', '']);
    }
    $fput([]);

    // Блок 5: Heatmap
    $fput(['Hourly Heatmap (paid orders)']);
    $fput(['Hour', 'Count']);
    if ($heatmap) {
        foreach ($heatmap as $h => $cnt) {
            $fput([$h, (int)$cnt]);
        }
    } else {
        $fput(['Нет данных', '']);
    }

    fclose($out);
    exit;
}

// ===== PDF (HTML) экспорт =====
$title = 'Отчёт владельца — ' . $range;

// Простейший HTML-отчёт, который можно сохранить как PDF через печать.
header('Content-Type: text/html; charset=UTF-8');

?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>
    <style>
        body { font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; font-size: 14px; margin: 20px; color: #111827; }
        h1 { font-size: 20px; margin-bottom: 8px; }
        h2 { font-size: 16px; margin-top: 16px; margin-bottom: 6px; }
        table { border-collapse: collapse; width: 100%; margin-bottom: 12px; }
        th, td { border: 1px solid #D1D5DB; padding: 4px 6px; text-align: left; }
        th { background: #F3F4F6; }
        .meta { font-size: 12px; color: #6B7280; margin-bottom: 12px; }
        .badge { display: inline-block; padding: 2px 6px; border-radius: 999px; background: #E5E7EB; font-size: 11px; }
        .kpi { display: flex; gap: 16px; flex-wrap: wrap; margin: 8px 0 12px; }
        .kpi-block { border: 1px solid #E5E7EB; border-radius: 8px; padding: 6px 8px; min-width: 150px; }
        .kpi-label { font-size: 11px; color: #6B7280; margin-bottom: 2px; }
        .kpi-value { font-weight: 600; }
        .note { font-size: 12px; color: #6B7280; margin-top: 12px; }
        .print-btn { margin-bottom: 12px; }
    </style>
</head>
<body>

<button class="print-btn" onclick="window.print()">Печать / Сохранить в PDF</button>

<h1>Отчёт владельца</h1>
<div class="meta">
    Диапазон: <span class="badge"><?= htmlspecialchars($range, ENT_QUOTES, 'UTF-8') ?></span><br>
    Начало (MSK): <?= htmlspecialchars($periodStart ?? '—', ENT_QUOTES, 'UTF-8') ?><br>
    Конец (MSK): <?= htmlspecialchars($periodEnd ?? '—', ENT_QUOTES, 'UTF-8') ?><br>
    Срез: <?= htmlspecialchars($scopeLabel, ENT_QUOTES, 'UTF-8') ?><br>
    Сгенерировано: <?= htmlspecialchars(date('Y-m-d H:i:s'), ENT_QUOTES, 'UTF-8') ?>
</div>

<?php
$fmtMoney = function (float $v): string {
    return format_money($v);
};
$fmtPct = function (?float $v): string {
    if ($v === null) return '—';
    return round($v, 1) . '%';
};
?>

<h2>KPI</h2>
<div class="kpi">
    <div class="kpi-block">
        <div class="kpi-label">Выручка</div>
        <div class="kpi-value"><?= $fmtMoney((float)$summaryCurrent['revenue']) ?></div>
    </div>
    <div class="kpi-block">
        <div class="kpi-label">Оплаченных заказов</div>
        <div class="kpi-value"><?= (int)$summaryCurrent['orders'] ?></div>
    </div>
    <div class="kpi-block">
        <div class="kpi-label">Средний чек</div>
        <div class="kpi-value"><?= $fmtMoney((float)$summaryCurrent['avg']) ?></div>
    </div>
    <div class="kpi-block">
        <div class="kpi-label">Конверсия (оплачено)</div>
        <div class="kpi-value"><?= $fmtPct($conversion['conversion_paid_pct'] ?? null) ?></div>
    </div>
    <div class="kpi-block">
        <div class="kpi-label">Отменено</div>
        <div class="kpi-value"><?= $fmtPct($conversion['canceled_pct'] ?? null) ?></div>
    </div>
    <div class="kpi-block">
        <div class="kpi-label">Маржа</div>
        <div class="kpi-value"><?= $fmtPct($margin['margin_pct'] ?? null) ?></div>
    </div>
    <div class="kpi-block">
        <div class="kpi-label">Доля топ-3 блюд</div>
        <div class="kpi-value"><?= $fmtPct($topShare['share_pct'] ?? null) ?></div>
    </div>
</div>

<h2>Top Items</h2>
<table>
    <thead>
    <tr><th>Блюдо</th><th>Кол-во</th><th>Выручка</th></tr>
    </thead>
    <tbody>
    <?php if ($topItems): ?>
        <?php foreach ($topItems as $item): ?>
            <tr>
                <td><?= htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= (int)$item['qty'] ?></td>
                <td><?= $fmtMoney((float)$item['revenue']) ?></td>
            </tr>
        <?php endforeach; ?>
    <?php else: ?>
        <tr><td colspan="3">Нет данных</td></tr>
    <?php endif; ?>
    </tbody>
</table>

<h2>Top Categories</h2>
<table>
    <thead>
    <tr><th>Категория</th><th>Кол-во</th><th>Выручка</th></tr>
    </thead>
    <tbody>
    <?php if ($topCategories): ?>
        <?php foreach ($topCategories as $cat): ?>
            <tr>
                <td><?= htmlspecialchars($cat['name'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= (int)$cat['qty'] ?></td>
                <td><?= $fmtMoney((float)$cat['revenue']) ?></td>
            </tr>
        <?php endforeach; ?>
    <?php else: ?>
        <tr><td colspan="3">Нет данных</td></tr>
    <?php endif; ?>
    </tbody>
</table>

<h2>Hourly Heatmap (paid orders)</h2>
<table>
    <thead>
    <tr><th>Час</th><th>Количество заказов</th></tr>
    </thead>
    <tbody>
    <?php if ($heatmap): ?>
        <?php foreach ($heatmap as $h => $cnt): ?>
            <tr>
                <td><?= (int)$h ?></td>
                <td><?= (int)$cnt ?></td>
            </tr>
        <?php endforeach; ?>
    <?php else: ?>
        <tr><td colspan="2">Нет данных</td></tr>
    <?php endif; ?>
    </tbody>
</table>

<div class="note">
    PDF-генератор (dompdf) не установлен, поэтому отчёт отдаётся в виде HTML-страницы.
    Используйте кнопку «Печать / Сохранить в PDF» браузера, чтобы сохранить этот отчёт в PDF.
</div>

</body>
</html>

