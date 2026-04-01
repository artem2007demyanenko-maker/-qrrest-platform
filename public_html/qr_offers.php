<?php

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/upsell_repo.php';
require_once __DIR__ . '/../app/upsell_engine.php';
require_once __DIR__ . '/../app/upsell_guest.php';

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

if (!$currentRestaurant) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'restaurant_not_found'], JSON_UNESCAPED_UNICODE);
    exit;
}

$tableId = isset($_GET['table_id']) ? (int)$_GET['table_id'] : 0;
if ($tableId <= 0) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'table_id_required'], JSON_UNESCAPED_UNICODE);
    exit;
}


$stmt = $pdo->prepare("SELECT id FROM tables WHERE id=:id AND restaurant_id=:r LIMIT 1");
$stmt->execute([':id' => $tableId, ':r' => (int)$currentRestaurant['id']]);
if (!$stmt->fetchColumn()) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'table_not_found'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Soft upsell gating: return no offers when plan has upsell disabled (demo unchanged).
$upsellEnabled = true;
if (!function_exists('is_demo_mode') || !is_demo_mode()) {
    if (file_exists(__DIR__ . '/../app/subscription_plans.php')) {
        require_once __DIR__ . '/../app/subscription_plans.php';
        $upsellEnabled = function_exists('check_feature') && check_feature((int)$currentRestaurant['id'], 'upsell_enabled');
    }
}
if (!$upsellEnabled) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true, 'enabled' => false, 'items' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

if (session_status() === PHP_SESSION_NONE) session_start();

$cartKey = 'cart_' . (int)$currentRestaurant['id'] . '_' . $tableId;
$cart = $_SESSION[$cartKey] ?? [];
if (!is_array($cart)) $cart = [];

$guestUpsellOn = upsell_guest_layer_enabled($currentRestaurant, $upsellEnabled);
$limit = upsell_guest_max_items($currentRestaurant);

header('Content-Type: application/json; charset=utf-8');

if (!$guestUpsellOn) {
    echo json_encode(['success' => true, 'enabled' => false, 'items' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

$baseIds = [];

// Optional: base items from order (when order_id is passed, e.g. from order_track)
$orderIdRaw = isset($_GET['order_id']) ? trim((string)$_GET['order_id']) : '';
$orderIdForContext = (ctype_digit($orderIdRaw) && $orderIdRaw !== '') ? (int)$orderIdRaw : 0;
if ($orderIdForContext > 0) {
    try {
        $orderStmt = $pdo->prepare("SELECT id, restaurant_id FROM orders WHERE id = ? LIMIT 1");
        $orderStmt->execute([$orderIdForContext]);
        $orderRow = $orderStmt->fetch(PDO::FETCH_ASSOC);
        if (!$orderRow || (int)$orderRow['restaurant_id'] !== (int)$currentRestaurant['id']) {
            error_log('UPSSELL_ORDER_CONTEXT_FAIL rid=' . $rid . ' order_id=' . $orderIdForContext . ' reason=order_not_found_or_wrong_restaurant');
        } else {
            $hasMenuItemId = function_exists('db_column_exists') ? db_column_exists('order_items', 'menu_item_id') : true;
            if (!function_exists('db_column_exists')) {
                require_once __DIR__ . '/../app/schema_guard.php';
                $hasMenuItemId = db_column_exists('order_items', 'menu_item_id');
            }
            if ($hasMenuItemId) {
                $oiStmt = $pdo->prepare("SELECT DISTINCT menu_item_id FROM order_items WHERE order_id = ? AND menu_item_id IS NOT NULL AND menu_item_id > 0");
                $oiStmt->execute([$orderIdForContext]);
                while ($row = $oiStmt->fetch(PDO::FETCH_COLUMN)) {
                    $baseIds[] = (int)$row;
                }
            }
            if (empty($baseIds) && db_column_exists('order_items', 'item_name')) {
                $oiStmt = $pdo->prepare("SELECT DISTINCT item_name FROM order_items WHERE order_id = ? AND TRIM(COALESCE(item_name,'')) <> ''");
                $oiStmt->execute([$orderIdForContext]);
                $names = $oiStmt->fetchAll(PDO::FETCH_COLUMN);
                foreach ($names as $itemName) {
                    $mStmt = $pdo->prepare("SELECT id FROM menu_items WHERE restaurant_id = ? AND name = ? AND available = 1 LIMIT 1");
                    $mStmt->execute([(int)$currentRestaurant['id'], $itemName]);
                    $mid = $mStmt->fetchColumn();
                    if ($mid) {
                        $baseIds[] = (int)$mid;
                    }
                }
            }
        }
    } catch (Throwable $e) {
        error_log('UPSSELL_ORDER_CONTEXT_FAIL rid=' . $rid . ' order_id=' . $orderIdForContext . ' reason=' . $e->getMessage());
    }
}

// Fallback: base items from cart
if (empty($baseIds)) {
    foreach ($cart as $id => $qty) {
        if ((int)$qty > 0) $baseIds[] = (int)$id;
    }
}

try {
    $sessionViews = (int)($_SESSION['upsell_views'] ?? 0);
    $orderUpsellCount = (int)($_GET['upsell_added_count'] ?? 0);
    $items = get_contextual_upsells(
        (int)$currentRestaurant['id'],
        $cart,
        $baseIds,
        $sessionViews,
        $orderUpsellCount,
        $limit
    );
    if ($limit > 0 && count($items) > $limit) {
        $items = array_slice($items, 0, $limit);
    }
} catch (Throwable $e) {
    error_log('qr_offers rid=' . $rid . ' ' . $e->getMessage());
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'internal_error', 'rid' => $rid]);
    exit;
}

$cartItemIds = [];
foreach ($cart as $id => $qty) {
    if ((int)$qty > 0) {
        $cartItemIds[] = (int)$id;
    }
}

if (count($items) > 0) {
    if (file_exists(__DIR__ . '/../app/flow_id.php')) {
        require_once __DIR__ . '/../app/flow_id.php';
        app_flow_id_ensure_current((int)$currentRestaurant['id']);
        $upsellSessionKey = app_upsell_session_key_get();
    } else {
        $upsellSessionKey = null;
    }

    $_SESSION['upsell_views'] = ($_SESSION['upsell_views'] ?? 0) + 1;

    if (file_exists(__DIR__ . '/../app/upsell_analytics.php')) {
        require_once __DIR__ . '/../app/upsell_analytics.php';
        upsell_track((int)$currentRestaurant['id'], 'shown', $tableId, null, $upsellSessionKey, null, null, ['items_count' => count($items)]);

            // New per-item impression events for conversion_rate learning.
            foreach ($items as $it) {
                $iid = (int)($it['id'] ?? 0);
                if ($iid > 0) {
                    upsell_track((int)$currentRestaurant['id'], 'upsell_shown', $tableId, null, $upsellSessionKey, null, $iid, []);
                }
            }
    }

    // Usage metrics: count real upsell impressions when offers are shown.
    if (file_exists(__DIR__ . '/../app/subscription_plans.php')) {
        require_once __DIR__ . '/../app/subscription_plans.php';
        if (function_exists('increment_usage') && function_exists('is_demo_mode') && !is_demo_mode()) {
            try {
                increment_usage((int)$currentRestaurant['id'], 'upsell_shown');
            } catch (Throwable $eUsage) {
                // no-op on error
            }
        }
    }
}

$responseItems = array_map(function ($it) {
    $row = [
        'id' => (int)$it['id'],
        'name' => (string)($it['name'] ?? ''),
        'price' => (float)($it['price'] ?? 0),
        'image' => $it['image'] ?? null,
        'description' => (string)($it['description'] ?? ''),
    ];
    if (isset($it['reason'])) {
        $row['reason'] = (string)$it['reason'];
    }
    return $row;
}, $items);

echo json_encode([
    'success' => true,
    'enabled' => true,
    'title' => 'А может добавить к заказу?',
    'subtitle' => 'Часто берут вместе — можно добавить одним тапом.',
    'items' => $responseItems,
], JSON_UNESCAPED_UNICODE);
