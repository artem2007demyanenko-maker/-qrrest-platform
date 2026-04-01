<?php
/**
 * QR Table Generator — PDF export: one card per table (Restaurant name, Table number, QR code).
 */

$rid = bin2hex(random_bytes(4));
require_once __DIR__ . '/../../app/bootstrap.php';
set_exception_handler(function (Throwable $e) use ($rid) {
    error_log('STABILITY_ERROR restaurant/qr_print_pdf.php rid=' . $rid . ' ' . $e->getMessage());
    if (!headers_sent()) {
        http_response_code(500);
    }
    echo 'PDF generation failed. RID: ' . htmlspecialchars($rid);
    exit;
});

require_login();
require_current_restaurant();
require_restaurant_role((int)($currentRestaurant['id'] ?? 0), ['owner', 'admin']);

$config    = require __DIR__ . '/../../app/config.php';
$mainDomain = $config['app']['main_domain'] ?? 'lvh.me';
$protocol  = $config['app']['protocol'] ?? 'http';
$subdomain = $currentRestaurant['subdomain'] ?? 'demo';
$baseUrl   = $protocol . '://' . $subdomain . '.' . $mainDomain . '/qr.php';
$restName  = $currentRestaurant['name'] ?? 'Restaurant';

$pdo = db();
$stmt = $pdo->prepare("SELECT id, name FROM tables WHERE restaurant_id = ? ORDER BY name");
$stmt->execute([(int)$currentRestaurant['id']]);
$tables = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($tables)) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>No tables</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?= brand_head_tags() ?>
</head><body><p>No tables. <a href="/restaurant/tables.php">Add tables</a>.</p></body></html>';
    exit;
}

require_once __DIR__ . '/../../vendor/autoload.php';

$pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8');
$pdf->SetCreator('QR Restaurant');
$pdf->SetAuthor($restName);
$pdf->SetTitle('QR codes — ' . $restName);
$pdf->SetAutoPageBreak(true, 12);
$pdf->SetMargins(12, 12, 12);
$pdf->SetAutoPageBreak(true, 15);
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->AddPage();

$cardW   = 85;
$cardH   = 58;
$qrSize  = 38;
$cols    = 2;
$marginX = 12;
$marginY = 12;
$gapX    = 10;
$gapY    = 10;
$startX  = $marginX;
$startY  = $marginY;
$pageH   = $pdf->getPageHeight();
$row     = 0;
$col     = 0;

foreach ($tables as $t) {
    $url = $baseUrl . '?table_id=' . (int)$t['id'];
    $x   = $startX + $col * ($cardW + $gapX);
    $y   = $startY + $row * ($cardH + $gapY);

    if ($y + $cardH > $pageH - 20) {
        $pdf->AddPage();
        $row = 0;
        $col = 0;
        $y   = $startY;
        $x   = $startX;
    }

    $pdf->SetXY($x, $y);
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->Cell($cardW, 6, $restName, 0, 1, 'C', false, '', 0, false, 'T', 'M');
    $pdf->SetX($x);
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->Cell($cardW, 8, $t['name'], 0, 1, 'C', false, '', 0, false, 'T', 'M');

    $qrX = $x + ($cardW - $qrSize) / 2;
    $qrY = $y + 16;
    $pdf->write2DBarcode($url, 'QRCODE,M', $qrX, $qrY, $qrSize, $qrSize);

    $pdf->SetXY($x, $qrY + $qrSize + 2);
    $pdf->SetFont('helvetica', '', 7);
    $pdf->Cell($cardW, 4, 'Scan to order', 0, 0, 'C');

    $col++;
    if ($col >= $cols) {
        $col = 0;
        $row++;
    }
}

$pdf->Output('qr-codes-' . preg_replace('/[^a-z0-9_-]/i', '-', $restName) . '.pdf', 'D');
