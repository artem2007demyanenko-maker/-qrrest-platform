<?php
require_once __DIR__ . '/../../app/bootstrap.php';

$rid = bin2hex(random_bytes(4));

set_exception_handler(function (Throwable $e) use ($rid) {
    error_log('PUBLIC_PAGE_ERROR rid=' . $rid . ' ' . json_encode([
        'uri' => $_SERVER['REQUEST_URI'] ?? '',
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'time' => gmdate('c'),
    ], JSON_UNESCAPED_UNICODE));
    if (!headers_sent()) {
        http_response_code(200);
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'internal_error', 'rid' => $rid]);
    exit;
});

$pdo = db();

if (file_exists(__DIR__ . '/../../app/schema_guard.php')) {
    require_once __DIR__ . '/../../app/schema_guard.php';
}

if (!$currentRestaurant) {
    http_response_code(404);
    exit;
}


header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$tableId = isset($_GET['table_id']) ? (int)$_GET['table_id'] : 0;
$orderId = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;

if ($tableId <= 0 || $orderId <= 0) {
    echo json_encode(['success' => false, 'error' => 'bad_params']);
    exit;
}

function map_order_status_for_guest(string $status): array {
    $s = trim(mb_strtolower($status ?: 'new'));

  
    if (in_array($s, ['new','created','accepted','confirmed','pending'], true)) {
        return ['Заказ принят', 'Мы приняли ваш заказ и передали его на кухню.', 25];
    }
    if (in_array($s, ['in_progress','progress','cooking','preparing','kitchen','processing'], true)) {
        return ['Готовится', 'Кухня уже готовит ваш заказ.', 60];
    }
    if (in_array($s, ['done','ready','completed','served','finish','finished'], true)) {
        return ['Готов', 'Ваш заказ готов, скоро его принесут к столу.', 100];
    }
    if ($s === 'delivered') {
        return ['Заказ получен', 'Спасибо! Оцените, пожалуйста, ваш заказ.', 100];
    }
    if (in_array($s, ['canceled','cancelled','rejected','declined'], true)) {
        return ['Отменён', 'Заказ отменён. Уточните детали у персонала.', 0];
    }


    return ['Заказ принят', 'Мы приняли ваш заказ и скоро передадим его на кухню.', 25];
}

$stmt = $pdo->prepare("
    SELECT order_status, payment_status, payment_type, created_at, updated_at
    FROM orders
    WHERE id = :oid
      AND restaurant_id = :rest
      AND table_id = :table
    LIMIT 1
");
$stmt->execute([
    ':oid'   => $orderId,
    ':rest'  => (int)$currentRestaurant['id'],
    ':table' => $tableId,
]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    echo json_encode(['success' => false, 'error' => 'not_found']);
    exit;
}

[$label, $desc, $progress] = map_order_status_for_guest($row['order_status'] ?? 'new');

$readyItemsCount = 0;
$totalItemsCount = 0;
$partialReady = false;
$progressPercentByItems = null;
if (function_exists('db_column_exists') && db_column_exists('order_items', 'station_status')) {
    $stmtProg = $pdo->prepare("
        SELECT
            COUNT(*) AS total_cnt,
            SUM(CASE WHEN station_status = 'ready' THEN 1 ELSE 0 END) AS ready_cnt
        FROM order_items
        WHERE order_id = :oid
    ");
    $stmtProg->execute([':oid' => $orderId]);
    $pr = $stmtProg->fetch(PDO::FETCH_ASSOC) ?: [];
    $totalItemsCount = (int)($pr['total_cnt'] ?? 0);
    $readyItemsCount = (int)($pr['ready_cnt'] ?? 0);
    $partialReady = $readyItemsCount > 0 && $readyItemsCount < $totalItemsCount;
    if ($totalItemsCount > 0) {
        $progressPercentByItems = (int)floor(($readyItemsCount / $totalItemsCount) * 100);
        $progress = $progressPercentByItems;
    }
}

$offlineTypes = ['cash', 'card_later', 'pay_later'];
$paymentStatus = (string)($row['payment_status'] ?? 'unpaid');
$paymentType = (string)($row['payment_type'] ?? '');
$createdTs = strtotime((string)($row['created_at'] ?? ''));
$countdownSecondsRemaining = null;
$countdownExpired = false;
$countdownWarning = false;
$countdownMmss = null;
if ($createdTs && $paymentStatus === 'unpaid' && in_array($paymentType, $offlineTypes, true)) {
    $elapsed = max(0, time() - $createdTs);
    $remaining = (10 * 60) - $elapsed;
    $countdownSecondsRemaining = max(0, (int)$remaining);
    $countdownExpired = $remaining <= 0;
    $countdownWarning = !$countdownExpired && $countdownSecondsRemaining <= (3 * 60);
    $countdownMmss = gmdate('i:s', $countdownSecondsRemaining);
}

echo json_encode([
    'success'                  => true,
    'order_id'                 => $orderId,
    'order_status'             => $row['order_status'] ?? 'new',
    'order_status_label'       => $label,
    'order_status_description' => $desc,
    'progress'                 => $progress,
    'payment_status'           => $row['payment_status'] ?? 'unpaid',
    'payment_type'             => $row['payment_type'] ?? null,
    'ready_items_count'        => $readyItemsCount,
    'total_items_count'        => $totalItemsCount,
    'partial_ready'            => $partialReady,
    'progress_percent_items'   => $progressPercentByItems,
    'countdown_seconds_remaining' => $countdownSecondsRemaining,
    'countdown_expired'        => $countdownExpired,
    'countdown_warning'        => $countdownWarning,
    'countdown_mmss'           => $countdownMmss,
    'created_at'               => $row['created_at'] ?? null,
    'updated_at'               => $row['updated_at'] ?? null,
]);
