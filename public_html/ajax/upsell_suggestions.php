<?php

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/upsell_engine.php';
require_once __DIR__ . '/../../app/upsell_guest.php';

header('Content-Type: application/json; charset=utf-8');

if (!$currentRestaurant) {
    echo json_encode(['success' => false, 'error' => 'restaurant_not_found'], JSON_UNESCAPED_UNICODE);
    exit;
}

$tableId = isset($_GET['table_id']) ? (int)$_GET['table_id'] : 0;
if ($tableId <= 0) {
    echo json_encode(['success' => false, 'error' => 'table_id_required'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = db();
$stmt = $pdo->prepare('SELECT id FROM tables WHERE id = :id AND restaurant_id = :r LIMIT 1');
$stmt->execute([':id' => $tableId, ':r' => (int)$currentRestaurant['id']]);
if (!$stmt->fetchColumn()) {
    echo json_encode(['success' => false, 'error' => 'table_not_found'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Gate parity with qr_offers.php: plan-level upsell feature.
$upsellEnabled = true;
if (!function_exists('is_demo_mode') || !is_demo_mode()) {
    if (file_exists(__DIR__ . '/../../app/subscription_plans.php')) {
        require_once __DIR__ . '/../../app/subscription_plans.php';
        $upsellEnabled = function_exists('check_feature') && check_feature((int)$currentRestaurant['id'], 'upsell_enabled');
    }
}
if (!$upsellEnabled) {
    echo json_encode(['success' => true, 'enabled' => false, 'suggestions' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

$guestUpsellOn = upsell_guest_layer_enabled($currentRestaurant, $upsellEnabled);
if (!$guestUpsellOn) {
    echo json_encode(['success' => true, 'enabled' => false, 'suggestions' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

$limit = upsell_guest_max_items($currentRestaurant);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$cartKey = 'cart_' . (int)$currentRestaurant['id'] . '_' . $tableId;
$cart = $_SESSION[$cartKey] ?? [];
if (!is_array($cart)) {
    $cart = [];
}

$mode = isset($_GET['mode']) ? trim((string)$_GET['mode']) : 'context';
$cartTotal = isset($_GET['cart_total']) ? (float)$_GET['cart_total'] : 0.0;
if ($cartTotal <= 0) {
    $ctx = extract_cart_context((int)$currentRestaurant['id'], $cart, []);
    $cartTotal = (float)($ctx['cart_total'] ?? 0);
}

$sessionViews = (int)($_SESSION['upsell_views'] ?? 0);
$orderUpsellCount = (int)($_GET['upsell_added_count'] ?? 0);

if ($mode === 'cart') {
    $items = get_cart_upsells((int)$currentRestaurant['id'], $cartTotal, $cart, 1000.0, $sessionViews, $orderUpsellCount, $limit);
} else {
    $items = get_contextual_upsells((int)$currentRestaurant['id'], $cart, [], $sessionViews, $orderUpsellCount, $limit);
}

// Parity with qr_offers.php: enforce restaurant-level offer limit.
if ($limit > 0 && count($items) > $limit) {
    $items = array_slice($items, 0, $limit);
}

if (count($items) > 0) {
    $_SESSION['upsell_views'] = ($_SESSION['upsell_views'] ?? 0) + 1;
}

$suggestions = [];
foreach ($items as $it) {
    $suggestions[] = [
        'item_id' => (int)($it['id'] ?? 0),
        'name' => (string)($it['name'] ?? ''),
        'price' => (float)($it['price'] ?? 0),
        'image' => $it['image'] ?? null,
        'reason' => (string)($it['reason'] ?? 'Подходит к вашему заказу'),
    ];
}

echo json_encode(['success' => true, 'enabled' => true, 'suggestions' => $suggestions], JSON_UNESCAPED_UNICODE);
