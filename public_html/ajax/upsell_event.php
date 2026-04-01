<?php
require_once __DIR__ . '/../../app/bootstrap.php';

$rid = bin2hex(random_bytes(4));
set_exception_handler(function (Throwable $e) use ($rid) {
    error_log('UPSELL_EVENT rid=' . $rid . ' ' . $e->getMessage());
    if (!headers_sent()) {
        http_response_code(200);
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'internal_error', 'rid' => $rid]);
    exit;
});

header('Content-Type: application/json; charset=utf-8');

if (!$currentRestaurant) {
    echo json_encode(['success' => false, 'error' => 'restaurant_not_found']);
    exit;
}

if (function_exists('is_demo_mode') && is_demo_mode()) {
    echo json_encode(['success' => true]);
    exit;
}

// Soft upsell gating: no tracking when plan has upsell disabled (no-op success).
if (file_exists(__DIR__ . '/../../app/subscription_plans.php')) {
    require_once __DIR__ . '/../../app/subscription_plans.php';
    if (function_exists('check_feature') && !check_feature((int)$currentRestaurant['id'], 'upsell_enabled')) {
        echo json_encode(['success' => true]);
        exit;
    }
}

$allowedEvents = [
    // Legacy events (existing upsell analytics).
    'shown',
    'add_click',
    'accepted_in_order',
    // Extended events (conversion-based scoring / self-learning).
    'upsell_shown',
    'upsell_clicked',
    'upsell_added_to_cart',
];
$event = trim($_POST['event'] ?? '');
if ($event === '' || !in_array($event, $allowedEvents, true)) {
    echo json_encode(['success' => false, 'error' => 'invalid_event']);
    exit;
}

$tableIdRaw = $_POST['table_id'] ?? '';
$tableId = (ctype_digit((string)$tableIdRaw) && $tableIdRaw !== '') ? (int)$tableIdRaw : null;
if ($tableId === null || $tableId <= 0) {
    echo json_encode(['success' => false, 'error' => 'table_id_required']);
    exit;
}

$pdo = db();
$stmt = $pdo->prepare("SELECT id FROM tables WHERE id = ? AND restaurant_id = ? LIMIT 1");
$stmt->execute([$tableId, (int)$currentRestaurant['id']]);
if (!$stmt->fetchColumn()) {
    echo json_encode(['success' => false, 'error' => 'table_not_found']);
    exit;
}

$orderId = null;
$orderIdRaw = $_POST['order_id'] ?? '';
if ($orderIdRaw !== '' && ctype_digit((string)$orderIdRaw)) {
    $orderId = (int)$orderIdRaw;
}

$upsellItemId = null;
$upsellItemIdRaw = $_POST['upsell_item_id'] ?? '';
if ($upsellItemIdRaw !== '' && ctype_digit((string)$upsellItemIdRaw)) {
    $upsellItemId = (int)$upsellItemIdRaw;
}

$baseItemId = null;
$baseItemIdRaw = $_POST['base_item_id'] ?? '';
if ($baseItemIdRaw !== '' && ctype_digit((string)$baseItemIdRaw)) {
    $baseItemId = (int)$baseItemIdRaw;
}

// Ownership validation to prevent analytics poisoning (tenant safety).
// For conversion learning events we need a real upsell item id.
if (in_array($event, ['upsell_shown', 'upsell_clicked', 'upsell_added_to_cart'], true)) {
    if ($upsellItemId === null || $upsellItemId <= 0) {
        echo json_encode(['success' => false, 'error' => 'upsell_item_id_required']);
        exit;
    }
}

if ($orderId !== null && $orderId > 0) {
    $stmt = $pdo->prepare("SELECT 1 FROM orders WHERE id = ? AND restaurant_id = ? LIMIT 1");
    $stmt->execute([$orderId, (int)$currentRestaurant['id']]);
    if (!$stmt->fetchColumn()) {
        echo json_encode(['success' => false, 'error' => 'order_not_found']);
        exit;
    }
}
if ($baseItemId !== null && $baseItemId > 0) {
    $stmt = $pdo->prepare("SELECT 1 FROM menu_items WHERE id = ? AND restaurant_id = ? LIMIT 1");
    $stmt->execute([$baseItemId, (int)$currentRestaurant['id']]);
    if (!$stmt->fetchColumn()) {
        echo json_encode(['success' => false, 'error' => 'base_item_not_found']);
        exit;
    }
}
if ($upsellItemId !== null && $upsellItemId > 0) {
    $stmt = $pdo->prepare("SELECT 1 FROM menu_items WHERE id = ? AND restaurant_id = ? LIMIT 1");
    $stmt->execute([$upsellItemId, (int)$currentRestaurant['id']]);
    if (!$stmt->fetchColumn()) {
        echo json_encode(['success' => false, 'error' => 'upsell_item_not_found']);
        exit;
    }
}

if (file_exists(__DIR__ . '/../../app/upsell_analytics.php')) {
    require_once __DIR__ . '/../../app/upsell_analytics.php';

    // System Consistency: stable session key + flow_id injection (best-effort).
    $sessionKey = null;
    if (file_exists(__DIR__ . '/../../app/flow_id.php')) {
        require_once __DIR__ . '/../../app/flow_id.php';
        app_flow_id_ensure_current((int)$currentRestaurant['id']);
        $sessionKey = app_upsell_session_key_get();
    }

    upsell_track(
        (int)$currentRestaurant['id'],
        $event,
        $tableId,
        $orderId,
        $sessionKey,
        $baseItemId,
        $upsellItemId,
        []
    );
}

echo json_encode(['success' => true]);
