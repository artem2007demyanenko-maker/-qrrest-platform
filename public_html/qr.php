<?php

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/upsell.php';
if (file_exists(__DIR__ . '/../app/upsell_engine.php')) {
    require_once __DIR__ . '/../app/upsell_engine.php';
}
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
    $isJson = strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false
        || strpos($_SERVER['REQUEST_URI'] ?? '', '/ajax/') !== false;
    if ($isJson) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'internal_error', 'rid' => $rid]);
    } else {
        echo '<h1>Ошибка</h1><p>Попробуйте позже.</p><p style="font-size:11px;color:#666">RID: ' . htmlspecialchars($rid) . '</p>';
    }
    exit;
});

$pdo = db();


if (!$currentRestaurant) {
    http_response_code(404);
    echo "Ресторан не найден (по поддомену).";
    exit;
}


$tableId = isset($_GET['table_id']) ? (int)$_GET['table_id'] : 0;
if ($tableId <= 0) {
    http_response_code(400);
    echo "Не указан стол (table_id).";
    exit;
}

if (is_demo_mode()) {
    $table = demo_table_by_id($tableId);
} else {
    $stmt = $pdo->prepare("
        SELECT * FROM tables
        WHERE id = :id AND restaurant_id = :rest
        LIMIT 1
    ");
    $stmt->execute([
        'id'   => $tableId,
        'rest' => $currentRestaurant['id'],
    ]);
    $table = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$table) {
        http_response_code(404);
        echo "Стол не найден.";
        exit;
    }
}


$allowCardLater = isset($currentRestaurant['allow_payment_card_later'])
    ? (int)$currentRestaurant['allow_payment_card_later']
    : 1;
$allowCash = isset($currentRestaurant['allow_payment_cash'])
    ? (int)$currentRestaurant['allow_payment_cash']
    : 1;


if (!$allowCardLater && !$allowCash) {
    $allowCash = 1;
}


$loyaltyEnabled = false;
if (function_exists('loyalty_is_enabled_for_restaurant')) {
    $loyaltyEnabled = loyalty_is_enabled_for_restaurant($currentRestaurant);
} else {
    if (!empty($currentRestaurant['loyalty_enabled'])) {
        $loyaltyEnabled = true;
    }
}
// Soft loyalty gating: no accrual/messaging when plan has loyalty disabled (demo unchanged).
if ($loyaltyEnabled && !is_demo_mode() && !empty($currentRestaurant['id']) && file_exists(__DIR__ . '/../app/subscription_plans.php')) {
    require_once __DIR__ . '/../app/subscription_plans.php';
    if (function_exists('check_feature') && !check_feature((int)$currentRestaurant['id'], 'loyalty_enabled')) {
        $loyaltyEnabled = false;
    }
}

$loyaltyPercent = 5;
if (isset($currentRestaurant['loyalty_percent'])) {
    $lp = (int)$currentRestaurant['loyalty_percent'];
    if ($lp >= 0 && $lp <= 100) {
        $loyaltyPercent = $lp;
    }
}

// Branding (visual only): logo, banner, and accent override on top of theme.
$brandLogoSrc = null;
$brandBannerSrc = null;
$brandAccentColorNorm = null;

$brandLogoRaw = isset($currentRestaurant['brand_logo_url']) ? (string)$currentRestaurant['brand_logo_url'] : '';
$brandBannerRaw = isset($currentRestaurant['brand_banner_url']) ? (string)$currentRestaurant['brand_banner_url'] : '';
$brandAccentRaw = isset($currentRestaurant['brand_accent_color']) ? (string)$currentRestaurant['brand_accent_color'] : '';

$brandAccentCandidate = preg_match('~^#[0-9a-fA-F]{6}$~', trim($brandAccentRaw)) === 1 ? strtoupper(trim($brandAccentRaw)) : null;
$brandAccentColorNorm = $brandAccentCandidate;
$hasBrandAccent = ($brandAccentColorNorm !== null);
$brandAccentTextOnButton = '#ffffff';
if ($brandAccentColorNorm !== null) {
    $hex = ltrim($brandAccentColorNorm, '#');
    if (strlen($hex) === 6) {
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        // Lightweight luminance estimate for readability.
        $luma = (int)(($r * 299 + $g * 587 + $b * 114) / 1000);
        $brandAccentTextOnButton = $luma >= 186 ? '#111827' : '#ffffff';
    }
}

if (!function_exists('qr_brand_public_url_for_prefix')) {
    /**
     * Only /storage/brand_logos/* and /storage/brand_banners/*; file must exist on disk.
     */
    function qr_brand_public_url_for_prefix(string $raw, string $prefix): ?string
    {
        if ($prefix !== 'brand_logos' && $prefix !== 'brand_banners') {
            return null;
        }
        $s = trim($raw);
        if ($s === '' || strpos($s, '..') !== false) {
            return null;
        }
        if (preg_match('~^https?://~i', $s) === 1) {
            return null;
        }
        $rel = null;
        if (preg_match('~^/storage/(brand_logos/.+)$~', $s, $m) === 1 && $prefix === 'brand_logos') {
            $rel = $m[1];
        } elseif (preg_match('~^/storage/(brand_banners/.+)$~', $s, $m) === 1 && $prefix === 'brand_banners') {
            $rel = $m[1];
        } elseif (preg_match('~^(brand_logos/.+)$~', $s, $m) === 1 && $prefix === 'brand_logos') {
            $rel = $m[1];
        } elseif (preg_match('~^(brand_banners/.+)$~', $s, $m) === 1 && $prefix === 'brand_banners') {
            $rel = $m[1];
        }
        if ($rel === null || strpos($rel, '..') !== false) {
            return null;
        }
        if (preg_match('~^' . preg_quote($prefix, '~') . '/~', $rel) !== 1) {
            return null;
        }
        $fs = __DIR__ . '/storage/' . $rel;
        if (!is_file($fs)) {
            return null;
        }
        $mtime = @filemtime($fs);
        if ($mtime !== false) {
            return '/storage/' . $rel . '?v=' . (int)$mtime;
        }
        return '/storage/' . $rel;
    }
}

$brandLogoSrc = qr_brand_public_url_for_prefix($brandLogoRaw, 'brand_logos');
$brandBannerSrc = qr_brand_public_url_for_prefix($brandBannerRaw, 'brand_banners');


// Theme resolution (visual only):
// 1) owner preview param `theme_preview` (only for restaurant owners/admins)
// 2) saved restaurant `qr_theme`
// 3) fallback to builtin default handled inside qr_get_theme_classes()
$themeKeySaved = $currentRestaurant['qr_theme'] ?? 'dark_glass';
$themeKey = $themeKeySaved;
$themePreview = isset($_GET['theme_preview']) ? strtolower(trim((string)$_GET['theme_preview'])) : '';
$allowThemePreview = false;
if ($themePreview !== '' && isset($currentRestaurant['id']) && (int)$currentRestaurant['id'] > 0) {
    // Only honor preview for authenticated restaurant users with elevated role.
    if (function_exists('user_has_restaurant_role')) {
        $themePreviewOkFormat = preg_match('~^[a-z0-9_-]{3,50}$~', $themePreview) === 1;
        if ($themePreviewOkFormat) {
            $allowThemePreview = user_has_restaurant_role((int)$currentRestaurant['id'], ['owner', 'admin']);
        }
    }
}
if ($allowThemePreview) {
    $themeKey = $themePreview;
}
$ui = qr_get_theme_classes($pdo, (int)$currentRestaurant['id'], $themeKey);

// Soft upsell gating (used in POST checkout and later for suggestions/JS).
$upsellEnabled = true;
if (!is_demo_mode() && !empty($currentRestaurant['id']) && file_exists(__DIR__ . '/../app/subscription_plans.php')) {
    require_once __DIR__ . '/../app/subscription_plans.php';
    $upsellEnabled = function_exists('check_feature') && check_feature((int)$currentRestaurant['id'], 'upsell_enabled');
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}


$cartKey = 'cart_' . $currentRestaurant['id'] . '_' . $tableId;
if (!isset($_SESSION[$cartKey]) || !is_array($_SESSION[$cartKey])) {
    $_SESSION[$cartKey] = [];
}
$cart = &$_SESSION[$cartKey];

$errors = [];
$lastAddedItemId = 0;


if ($_SERVER['REQUEST_METHOD'] === 'POST') {


    if (isset($_POST['add_item'])) {
        $itemId = (int)($_POST['item_id'] ?? 0);
        if ($itemId > 0) {
            $qty = isset($cart[$itemId]) ? (int)$cart[$itemId] : 0;
            $cart[$itemId] = $qty + 1;
            $lastAddedItemId = $itemId;
        }
    }


    if (isset($_POST['remove_item'])) {
        $itemId = (int)($_POST['remove_item'] ?? 0);
        if ($itemId > 0 && isset($cart[$itemId])) {
            unset($cart[$itemId]);
        }
    }


    if (isset($_POST['clear_cart'])) {
        $cart = [];
    }

    if (isset($_POST['update_cart']) || isset($_POST['checkout'])) {

        // Собираем новые количества
        $qtys    = $_POST['qty'] ?? [];
        $newCart = [];

        foreach ($qtys as $itemId => $q) {
            $itemId = (int)$itemId;
            $q      = (int)$q;
            if ($itemId > 0 && $q > 0) {
                $newCart[$itemId] = $q;
            }
        }

        $cart = $newCart;

        // Оформление заказа (в demo не сохраняем в БД)
        if (isset($_POST['checkout'])) {
            if (is_demo_mode()) {
                $cart = [];
                header('Location: ' . (isset($_SERVER['REQUEST_URI']) ? explode('?', $_SERVER['REQUEST_URI'])[0] : '/qr.php') . '?table_id=' . (int)$tableId . '&demo_order=1');
                exit;
            }

            if (!$cart) {
                $errors[] = 'Корзина пуста.';
            }

            // Выбранный способ оплаты
            $paymentType = $_POST['payment_type'] ?? 'cash';

            // Разрешённые способы
            $allowedTypes = [];
            if ($allowCardLater) $allowedTypes[] = 'card_later';
            if ($allowCash)      $allowedTypes[] = 'cash';
            if (!$allowedTypes) {
                $allowedTypes = ['cash'];
            }

            if (!in_array($paymentType, $allowedTypes, true)) {
                $errors[] = 'Выбранный способ оплаты недоступен. Обновите страницу и попробуйте ещё раз.';
            }

            // Поля лояльности с формы
            $loyaltyPhoneRaw        = trim($_POST['loyalty_phone'] ?? '');
            $loyaltyPhoneNormalized = null;
            $loyaltyPointsAccrued   = 0;
            $loyaltyBalanceAfter    = 0;

            if ($loyaltyEnabled && $loyaltyPhoneRaw !== '') {
                if (function_exists('loyalty_normalize_phone')) {
                    $loyaltyPhoneNormalized = loyalty_normalize_phone($loyaltyPhoneRaw);
                    if (!$loyaltyPhoneNormalized) {
                        $errors[] = 'Пожалуйста, введите корректный номер телефона или оставьте поле пустым.';
                    }
                } else {
                    $loyaltyPhoneNormalized = $loyaltyPhoneRaw;
                }
            }

            if (!$errors) {
                $checkoutFingerprint = hash('sha256', json_encode([
                    'rid' => (int)$currentRestaurant['id'],
                    'tid' => (int)$tableId,
                    'cart' => $cart,
                    'payment_type' => (string)$paymentType,
                ], JSON_UNESCAPED_UNICODE));
                if (!isset($_SESSION['checkout_idem']) || !is_array($_SESSION['checkout_idem'])) {
                    $_SESSION['checkout_idem'] = [];
                }
                $idem = $_SESSION['checkout_idem'][$checkoutFingerprint] ?? null;
                if (is_array($idem)) {
                    $idemState = (string)($idem['state'] ?? '');
                    $idemOrderId = (int)($idem['order_id'] ?? 0);
                    $idemTs = (int)($idem['ts'] ?? 0);
                    if ($idemState === 'done' && $idemOrderId > 0 && (time() - $idemTs) <= 600) {
                        header("Location: /order_track.php?table_id={$tableId}&order_id={$idemOrderId}");
                        exit;
                    }
                    if ($idemState === 'processing' && (time() - $idemTs) <= 30) {
                        $errors[] = 'Заказ уже обрабатывается. Подождите несколько секунд.';
                    }
                }
            }

            if (!$errors) {
                $_SESSION['checkout_idem'][$checkoutFingerprint] = [
                    'state' => 'processing',
                    'order_id' => 0,
                    'ts' => time(),
                ];
                // Получаем блюда из корзины
                $ids = array_keys($cart);
                if (!$ids) {
                    $errors[] = 'Корзина пуста.';
                } else {
                    $placeholders = implode(',', array_fill(0, count($ids), '?'));
                    $sql = "
                        SELECT id, name, price
                        FROM menu_items
                        WHERE restaurant_id = ?
                          AND id IN ($placeholders)
                          AND available = 1
                    ";
                    $params = array_merge([$currentRestaurant['id']], $ids);
                    $stmt   = $pdo->prepare($sql);
                    $stmt->execute($params);
                    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

                    if (!$items) {
                        $errors[] = 'Не удалось загрузить блюда для заказа.';
                    } else {
                        $total     = 0;
                        $itemsById = [];

                        foreach ($items as $it) {
                            $mid = (int)$it['id'];
                            $itemsById[$mid] = $it;
                            $qty = $cart[$mid] ?? 0;
                            if ($qty > 0) {
                                $total += ((float)$it['price']) * $qty;
                            }
                        }

                        if ($total <= 0) {
                            $errors[] = 'Сумма заказа должна быть больше нуля.';
                        } else {
                            if (!is_demo_mode() && file_exists(__DIR__ . '/../app/checkout_analytics.php')) {
                                require_once __DIR__ . '/../app/checkout_analytics.php';
                                checkout_event_record((int)$currentRestaurant['id'], 'started_checkout', (int)$tableId);
                            }
                            $paymentStatus = 'unpaid';
                            $paymentTypeVal = $paymentType ?? 'cash';
                            $orderId = 0;
                            $orderItemsOk = true;

                            // System Consistency: create/keep a stable flow_id for this checkout.
                            if (file_exists(__DIR__ . '/../app/flow_id.php')) {
                                require_once __DIR__ . '/../app/flow_id.php';
                                $flowId = app_flow_id_ensure_current((int)$currentRestaurant['id']);
                            } else {
                                $flowId = null;
                            }
                            if (!function_exists('db_column_exists') && file_exists(__DIR__ . '/../app/schema_guard.php')) {
                                require_once __DIR__ . '/../app/schema_guard.php';
                            }
                            $hasOrdersFlowIdCol = (function_exists('db_column_exists') && db_column_exists('orders', 'flow_id'));

                            try {
                                if (!$pdo->inTransaction()) {
                                    $pdo->beginTransaction();
                                }

                                // Создаём заказ (схема: restaurant_id, table_id, order_status, payment_status, payment_type, total_amount, total_price)
                                if ($hasOrdersFlowIdCol && $flowId !== null) {
                                    $stmt = $pdo->prepare("
                                        INSERT INTO orders (
                                            restaurant_id,
                                            table_id,
                                            order_status,
                                            payment_status,
                                            payment_type,
                                            total_amount,
                                            total_price,
                                            flow_id
                                        ) VALUES (
                                            :rest,
                                            :table_id,
                                            :order_status,
                                            :payment_status,
                                            :payment_type,
                                            :total_amount,
                                            :total_price,
                                            :flow_id
                                        )
                                    ");
                                    $stmt->execute([
                                        'rest'           => (int)$currentRestaurant['id'],
                                        'table_id'       => (int)$tableId,
                                        'order_status'   => 'new',
                                        'payment_status' => $paymentStatus,
                                        'payment_type'   => $paymentTypeVal,
                                        'total_amount'   => $total,
                                        'total_price'    => $total,
                                        'flow_id'        => (string)$flowId,
                                    ]);
                                } else {
                                    $stmt = $pdo->prepare("
                                        INSERT INTO orders (
                                            restaurant_id,
                                            table_id,
                                            order_status,
                                            payment_status,
                                            payment_type,
                                            total_amount,
                                            total_price
                                        ) VALUES (
                                            :rest,
                                            :table_id,
                                            :order_status,
                                            :payment_status,
                                            :payment_type,
                                            :total_amount,
                                            :total_price
                                        )
                                    ");
                                    $stmt->execute([
                                        'rest'           => (int)$currentRestaurant['id'],
                                        'table_id'       => (int)$tableId,
                                        'order_status'   => 'new',
                                        'payment_status' => $paymentStatus,
                                        'payment_type'   => $paymentTypeVal,
                                        'total_amount'   => $total,
                                        'total_price'    => $total,
                                    ]);
                                }
                                $orderId = (int)$pdo->lastInsertId();

                                // Позиции заказа (order_items: item_name NOT NULL, qty и quantity)
                                $stmt = $pdo->prepare("
                                    INSERT INTO order_items (
                                        order_id,
                                        menu_item_id,
                                        item_name,
                                        qty,
                                        quantity,
                                        price
                                    ) VALUES (
                                        :order_id,
                                        :menu_item_id,
                                        :item_name,
                                        :qty,
                                        :quantity,
                                        :price
                                    )
                                ");
                                foreach ($cart as $itemId => $qty) {
                                    $qty = (int)$qty;
                                    $itemId = (int)$itemId;
                                    if ($qty <= 0) continue;
                                    if (!isset($itemsById[$itemId])) continue;

                                    $itemName  = (string)($itemsById[$itemId]['name'] ?? '');
                                    $itemPrice = (float)($itemsById[$itemId]['price'] ?? 0);
                                    if ($itemName === '' || $itemPrice <= 0) continue;

                                    $stmt->execute([
                                        'order_id'     => $orderId,
                                        'menu_item_id' => $itemId,
                                        'item_name'    => $itemName,
                                        'qty'          => $qty,
                                        'quantity'     => $qty,
                                        'price'        => $itemPrice,
                                    ]);
                                }

                                // ЛОЯЛЬНОСТЬ: только канонический ledger (guest_loyalty). Без fallback на legacy.
                                if ($loyaltyEnabled && $loyaltyPhoneNormalized) {
                                    if ($loyaltyPercent > 0) {
                                        $loyaltyPointsAccrued = (int) floor($total * $loyaltyPercent / 100);
                                    }

                                    if ($loyaltyPointsAccrued > 0 && function_exists('guest_find_by_phone') && function_exists('guest_loyalty_add_points')) {
                                        $guest = guest_find_by_phone($pdo, $loyaltyPhoneNormalized);
                                        if ($guest) {
                                            $res = guest_loyalty_add_points($pdo, (int)$currentRestaurant['id'], (int)$guest['id'], $loyaltyPointsAccrued, null, $orderId, 'QR заказ');
                                            if (is_array($res) && !empty($res['ok']) && isset($res['balance'])) {
                                                $loyaltyBalanceAfter = (int)$res['balance'];
                                            }
                                        }
                                    }

                                    $upd = $pdo->prepare("
                                        UPDATE orders
                                        SET loyalty_phone = :phone,
                                            loyalty_points_accrued = :accr,
                                            loyalty_points_balance_after = :bal
                                        WHERE id = :id
                                    ");
                                    $upd->execute([
                                        ':phone' => $loyaltyPhoneNormalized,
                                        ':accr'  => $loyaltyPointsAccrued,
                                        ':bal'   => $loyaltyBalanceAfter,
                                        ':id'    => $orderId,
                                    ]);
                                }
                                try {
                                    // CRM: сохраняем контакт на заказе в рамках той же транзакции.
                                    $crmPhoneRaw = trim($_POST['crm_phone'] ?? '');
                                    $crmConsent = isset($_POST['crm_consent']) && $_POST['crm_consent'] === '1';
                                    if ($crmPhoneRaw !== '' && file_exists(__DIR__ . '/../app/crm_repo.php')) {
                                        require_once __DIR__ . '/../app/crm_repo.php';
                                        if (function_exists('crm_store_order_contact')) {
                                            crm_store_order_contact((int)$currentRestaurant['id'], $orderId, $crmPhoneRaw, $crmConsent);
                                        }
                                    }
                                } catch (Throwable $e) {
                                    error_log('CRM_AFTER_CHECKOUT order_id=' . $orderId . ' ' . $e->getMessage());
                                }

                                $pdo->commit();

                                // Event logging only: must never affect checkout flow.
                                if (function_exists('app_event')) {
                                    app_event('order_created', [
                                        'restaurant_id' => (int)$currentRestaurant['id'],
                                        'order_id' => (int)$orderId,
                                        'table_id' => (int)$tableId,
                                        'total' => (float)$total,
                                        'payment_status' => (string)$paymentStatus,
                                        'payment_type' => (string)$paymentTypeVal,
                                    ]);
                                }
                            } catch (Throwable $e) {
                                if ($pdo->inTransaction()) {
                                    $pdo->rollBack();
                                }
                                $orderItemsOk = false;
                                $errors[] = 'Не удалось оформить заказ. Попробуйте снова.';
                                error_log('QR_CHECKOUT_TX_FAIL rid=' . (int)$currentRestaurant['id'] . ' table=' . (int)$tableId . ' ' . $e->getMessage());
                            }

                            if (!$orderItemsOk) {
                                // не редиректим — пользователь видит ошибку и может повторить
                            } else {
                                $_SESSION['checkout_idem'][$checkoutFingerprint] = [
                                    'state' => 'done',
                                    'order_id' => $orderId,
                                    'ts' => time(),
                                ];
                                // Usage tracking for billing (passive; no blocking)
                                if (!is_demo_mode() && file_exists(__DIR__ . '/../app/subscription_plans.php')) {
                                    try {
                                        require_once __DIR__ . '/../app/subscription_plans.php';
                                        if (function_exists('increment_usage')) {
                                            increment_usage((int)$currentRestaurant['id'], 'orders');
                                        }
                                    } catch (Throwable $e) {
                                        if (function_exists('error_log')) {
                                            error_log('QR usage_track order_id=' . $orderId . ' ' . $e->getMessage());
                                        }
                                    }
                                }
                                if (!is_demo_mode() && function_exists('checkout_event_record')) {
                                    checkout_event_record((int)$currentRestaurant['id'], 'completed_checkout', (int)$tableId);
                                }
                                // Upsell analytics: accepted_in_order только при включённом upsell на тарифе
                                if ($upsellEnabled) {
                                    $upsellAdded = $_POST['upsell_added'] ?? null;
                                    if (is_array($upsellAdded) && $upsellAdded !== [] && file_exists(__DIR__ . '/../app/upsell_analytics.php')) {
                                        require_once __DIR__ . '/../app/upsell_analytics.php';

                                        // Usage metrics: count one accepted upsell per order when at least one item is added.
                                        if (file_exists(__DIR__ . '/../app/subscription_plans.php')) {
                                            require_once __DIR__ . '/../app/subscription_plans.php';
                                            if (function_exists('increment_usage') && function_exists('is_demo_mode') && !is_demo_mode()) {
                                                try {
                                                    increment_usage((int)$currentRestaurant['id'], 'upsell_accepted');
                                                } catch (Throwable $eUsage) {
                                                    // do not affect checkout
                                                }
                                            }
                                        }


                                        $upsellSessionKey = null;
                                        if (file_exists(__DIR__ . '/../app/flow_id.php')) {
                                            require_once __DIR__ . '/../app/flow_id.php';
                                            app_flow_id_ensure_current((int)$currentRestaurant['id']);
                                            $upsellSessionKey = app_upsell_session_key_get();
                                        }

                                        foreach ($upsellAdded as $id) {
                                            $id = (int)$id;
                                            if ($id > 0) {
                                                try {
                                                    upsell_track((int)$currentRestaurant['id'], 'accepted_in_order', (int)$tableId, $orderId, $upsellSessionKey, null, $id, []);
                                                } catch (Throwable $e) {
                                                    error_log('upsell accepted_in_order ' . $e->getMessage());
                                                }
                                            }
                                        }

                                        // Event logging only (never affects checkout).
                                        if (function_exists('app_event') && is_array($upsellAdded) && $upsellAdded !== []) {
                                            $upsellIds = array_values(array_filter(array_map('intval', $upsellAdded), static fn($v) => $v > 0));
                                            if ($upsellIds !== []) {
                                                app_event('upsell_accepted', [
                                                    'restaurant_id' => (int)$currentRestaurant['id'],
                                                    'order_id' => (int)$orderId,
                                                    'table_id' => (int)$tableId,
                                                    'upsell_item_ids' => $upsellIds,
                                                ]);
                                            }
                                        }
                                    }
                                }

                                // Очищаем корзину
                                $cart = [];

                                $trackKey = 'last_order_' . $currentRestaurant['id'] . '_' . $tableId;
                                $_SESSION[$trackKey] = $orderId;

                                header("Location: /order_track.php?table_id={$tableId}&order_id={$orderId}");
                                exit;
                            }
                        }
                    }
                }
                if (!empty($errors)) {
                    $_SESSION['checkout_idem'][$checkoutFingerprint] = [
                        'state' => 'failed',
                        'order_id' => 0,
                        'ts' => time(),
                    ];
                }
            }
        }
    }
}

// ---------------- ПОДСЧЁТ КОРЗИНЫ ----------------
$cartItems    = [];
$cartTotalQty = 0;
$cartTotalSum = 0.0;

if ($cart) {
    if (is_demo_mode()) {
        $dataById = [];
        foreach (demo_menu_items() as $row) {
            $dataById[(int)$row['id']] = $row;
        }
        foreach ($cart as $itemId => $qty) {
            $itemId = (int)$itemId;
            $qty    = (int)$qty;
            if ($qty <= 0) continue;
            if (!isset($dataById[$itemId])) continue;
            $name  = $dataById[$itemId]['name'];
            $price = (float)$dataById[$itemId]['price'];
            $sum   = $price * $qty;
            $cartTotalQty += $qty;
            $cartTotalSum += $sum;
            $cartItems[] = [
                'id'    => $itemId,
                'name'  => $name,
                'price' => $price,
                'qty'   => $qty,
                'sum'   => $sum,
            ];
        }
    } else {
        $ids = [];
        foreach ($cart as $itemId => $qty) {
            $qty = (int)$qty;
            if ($qty > 0) $ids[] = (int)$itemId;
        }
        if ($ids) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $sql = "SELECT id, name, price
                    FROM menu_items
                    WHERE restaurant_id = ?
                      AND id IN ($placeholders)";
            $params = array_merge([$currentRestaurant['id']], $ids);
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $dataById = [];
            foreach ($rows as $row) {
                $dataById[(int)$row['id']] = $row;
            }
            foreach ($cart as $itemId => $qty) {
                $itemId = (int)$itemId;
                $qty    = (int)$qty;
                if ($qty <= 0) continue;
                if (!isset($dataById[$itemId])) continue;
                $name  = $dataById[$itemId]['name'];
                $price = (float)$dataById[$itemId]['price'];
                $sum   = $price * $qty;
                $cartTotalQty += $qty;
                $cartTotalSum += $sum;
                $cartItems[] = [
                    'id'    => $itemId,
                    'name'  => $name,
                    'price' => $price,
                    'qty'   => $qty,
                    'sum'   => $sum,
                ];
            }
        }
    }
}

// Smart upsell: menu_upsell_rules + engine (menu_item_upsells, rules, fallbacks). Max 1–3 from restaurant settings.
$upsellGuestOn = upsell_guest_layer_enabled($currentRestaurant, $upsellEnabled);
$upsellMaxItems = upsell_guest_max_items($currentRestaurant);
$upsellSuggestions = [];
if ($upsellGuestOn && $cartTotalQty > 0 && function_exists('get_smart_upsell')) {
    $triggerUpsell = $lastAddedItemId > 0 ? $lastAddedItemId : null;
    $upsellSuggestions = get_smart_upsell(
        (int)$currentRestaurant['id'],
        $cart,
        $upsellMaxItems,
        $triggerUpsell,
        (int)($_SESSION['upsell_views'] ?? 0),
        0
    );
}

// Если это AJAX-запрос для корзины (?ajax=1)
if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success'               => true,
        'cartTotalQty'          => $cartTotalQty,
        'cartTotalSum'          => $cartTotalSum,
        'cartTotalSumFormatted' => number_format($cartTotalSum, 0, '.', ' '),
        'items'                 => $cartItems,
        'smart_upsells'         => $upsellSuggestions,
        'upsell_guest_on'       => $upsellGuestOn,
        'last_added_item_id'    => $lastAddedItemId,
    ]);
    exit;
}

// ---------------- ЗАГРУЗКА МЕНЮ ----------------
if (is_demo_mode()) {
    $categories = demo_menu_categories();
    $items = demo_menu_items();
    $itemsByCategory = [];
    foreach ($items as $it) {
        $catName = $it['category_name'] ?? 'Меню';
        $itemsByCategory[$catName][] = $it;
    }
} else {
    $stmt = $pdo->prepare("
        SELECT * FROM menu_categories
        WHERE restaurant_id = :rest_cat
        ORDER BY sort_order ASC, id ASC
    ");
    $stmt->execute(['rest_cat' => $currentRestaurant['id']]);
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
        SELECT
            mi.*,
            mc.name       AS category_name,
            mc.sort_order AS category_sort
        FROM menu_items mi
        LEFT JOIN menu_categories mc
            ON mc.id = mi.category_id
           AND mc.restaurant_id = :rest_mc
        WHERE mi.restaurant_id = :rest_mi
          AND mi.available = 1
        ORDER BY
            (mc.sort_order IS NULL) ASC,
            mc.sort_order ASC,
            mc.id ASC,
            mi.id ASC
    ");
    $stmt->execute([
        'rest_mc' => $currentRestaurant['id'],
        'rest_mi' => $currentRestaurant['id'],
    ]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $itemsByCategory = [];
    foreach ($items as $it) {
        $catName = $it['category_name'] ?: 'Меню';
        $itemsByCategory[$catName][] = $it;
    }
}

// payment_type для UI
$availableTypes = [];
if ($allowCardLater) $availableTypes[] = 'card_later';
if ($allowCash)      $availableTypes[] = 'cash';
if (!$availableTypes) $availableTypes = ['cash'];

$defaultPaymentType = $allowCash ? 'cash' : $availableTypes[0];
$currentPaymentType = $_POST['payment_type'] ?? $defaultPaymentType;
if (!in_array($currentPaymentType, $availableTypes, true)) {
    $currentPaymentType = $defaultPaymentType;
}

$tableIdJs = (int)$tableId;
$orderIdJs = isset($_GET['order_id']) && ctype_digit((string)($_GET['order_id'] ?? '')) ? (int)$_GET['order_id'] : 0;
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title><?= e($currentRestaurant['name']) ?> — меню</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?= brand_head_tags() ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="/assets/css/motion.css" rel="stylesheet">
    <style>
        body { font-family: Inter, system-ui, sans-serif; }
        .no-scrollbar::-webkit-scrollbar{display:none}
        .no-scrollbar{-ms-overflow-style:none;scrollbar-width:none}
        <?php if ($brandAccentColorNorm !== null): ?>
        :root { --brand-accent: <?= e($brandAccentColorNorm) ?>; --primary: <?= e($brandAccentColorNorm) ?>; }
        .qr-brand-price { color: var(--brand-accent); }
        <?php if ($brandAccentTextOnButton !== '#ffffff'): ?>
        .qr-brand-price { text-shadow: 0 1px 2px rgba(0,0,0,0.25); }
        <?php endif; ?>
        .qr-brand-btn { background-color: var(--brand-accent); border-color: var(--brand-accent); color: <?= e($brandAccentTextOnButton) ?>; }
        .qr-brand-btn:hover { filter: brightness(1.08); }
        <?php endif; ?>
        .menu-banner { width: 100%; height: 180px; object-fit: cover; border-radius: 12px; display: block; }
        @supports (padding: max(0px)) {
            .qr-safe-pb { padding-bottom: max(1rem, env(safe-area-inset-bottom)); }
        }
    </style>
</head>
<body class="overflow-x-hidden <?= e($ui['body']) ?><?= is_demo_mode() ? ' demo-mode' : '' ?>">
<div class="max-w-3xl mx-auto px-3 sm:px-4 py-4 pb-36 md:pb-4 space-y-4 qr-safe-pb">

    <!-- Шапка -->
    <header class="space-y-3">
        <div class="flex items-start sm:items-center justify-between gap-3 min-w-0">
            <div class="min-w-0 flex-1">
                <?php if (!empty($brandLogoSrc)): ?>
                    <div class="mb-2">
                        <img src="<?= e($brandLogoSrc) ?>"
                             alt="<?= e($currentRestaurant['name'] ?? 'Logo') ?>"
                             loading="lazy"
                             class="h-12 w-auto object-contain">
                    </div>
                <?php endif; ?>
                <div class="inline-flex items-center gap-2 rounded-full <?= e($ui['header_chip']) ?> px-3 py-1 mb-1">
                    <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                    <span class="text-[11px] text-slate-300">Мы принимаем ваш заказ онлайн</span>
                </div>
                <h1 class="text-2xl font-bold"><?= e($currentRestaurant['name']) ?></h1>
                <div class="text-xs text-slate-400 mt-0.5">
                    Стол: <span class="font-medium text-slate-200"><?= e($table['name']) ?></span>
                    <span class="text-slate-600">·</span> QR-меню
                </div>
            </div>

            <button
                id="mini-cart-button"
                type="button"
                onclick="document.getElementById('cart-block').scrollIntoView({behavior:'smooth'})"
                class="inline-flex flex-col items-end gap-0.5 rounded-2xl <?= e($ui['mini_cart']) ?> px-3 py-2.5 text-right min-h-[44px] min-w-[44px] justify-center <?= $cartTotalQty ? '' : 'hidden' ?>"
            >
                <span class="inline-flex items-center gap-1 text-[11px] text-slate-400">
                    <span id="mini-cart-count" class="inline-flex items-center justify-center w-4 h-4 rounded-full bg-emerald-500 text-slate-950 text-[10px] font-bold">
                        <?= $cartTotalQty ?>
                    </span>
                    в корзине
                </span>
                <span id="mini-cart-total" class="text-sm font-semibold <?= e($ui['price']) ?> <?= $hasBrandAccent ? 'qr-brand-price' : 'text-[#22C55E]' ?> leading-tight">
                    <?= number_format($cartTotalSum, 0, '.', ' ') ?> ₽
                </span>
                <span class="text-[10px] text-slate-500">открыть корзину ↓</span>
            </button>
        </div>

        <?php if ($availableTypes): ?>
            <div class="flex flex-wrap gap-1.5 text-[10px] text-slate-400">
                <?php if ($allowCardLater): ?>
                    <span class="inline-flex items-center gap-1 px-2 py-1 rounded-full <?= e($ui['payment_chip']) ?>">Картой</span>
                <?php endif; ?>
                <?php if ($allowCash): ?>
                    <span class="inline-flex items-center gap-1 px-2 py-1 rounded-full <?= e($ui['payment_chip']) ?>">Наличными</span>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </header>

    <?php if (is_demo_mode()): ?>
        <div class="rounded-xl bg-amber-500/10 border border-amber-500/40 px-4 py-2.5 flex items-center justify-center gap-2 text-sm text-amber-200">
            <span aria-hidden="true">⚠</span>
            <span>Demo — orders are not saved.</span>
        </div>
    <?php endif; ?>
    <?php if (isset($_GET['demo_order']) && (int)$_GET['demo_order'] === 1 && is_demo_mode()): ?>
        <div class="rounded-3xl bg-emerald-500/10 border border-emerald-500/60 px-4 py-3 text-sm text-emerald-100">
            Demo order placed successfully.
        </div>
    <?php endif; ?>
    <?php if ($errors): ?>
        <div class="rounded-3xl bg-red-500/10 border border-red-500/60 px-4 py-3 text-sm text-red-100 space-y-1">
            <?php foreach ($errors as $err): ?>
                <div><?= e($err) ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- Быстрые ссылки -->
    <?php if ($itemsByCategory): ?>
        <nav class="flex flex-wrap gap-2 py-1" aria-label="Категории меню">
            <?php foreach ($itemsByCategory as $catName => $_): ?>
                <a href="#cat-<?= md5($catName) ?>"
                   class="inline-flex items-center min-h-[40px] px-3 py-2 rounded-full <?= e($ui['category_chip']) ?> text-xs sm:text-[11px] text-slate-200 hover:border-emerald-500/60 text-center">
                    <?= e($catName) ?>
                </a>
            <?php endforeach; ?>
        </nav>
    <?php endif; ?>

    <?php if (!empty($brandBannerSrc)): ?>
        <div class="w-full overflow-hidden">
            <img src="<?= e($brandBannerSrc) ?>"
                 alt="<?= e($currentRestaurant['name'] ?? 'Banner') ?>"
                 class="menu-banner"
                 loading="lazy">
        </div>
    <?php endif; ?>

    <!-- Меню -->
    <main class="space-y-5">
        <?php if (!$items): ?>
            <div class="text-sm text-slate-400 bg-slate-900/60 border border-slate-800 rounded-3xl p-6 text-center leading-relaxed">
                Меню пока пусто. Попросите персонал обновить меню.
            </div>
        <?php else: ?>
            <?php foreach ($itemsByCategory as $catName => $list): ?>
                <section class="space-y-3" id="cat-<?= md5($catName) ?>">
                    <div class="flex items-center justify-between">
                        <h2 class="text-lg font-semibold text-slate-100"><?= e($catName) ?></h2>
                        <div class="h-px flex-1 ml-3 bg-gradient-to-r from-slate-700/80 via-slate-800/40 to-transparent"></div>
                    </div>

                    <div class="space-y-2">
                        <?php foreach ($list as $item): ?>
                            <?php
                                $imgUrl = menu_item_image_url($item) ?? '';
                                $desc = (string)($item['description'] ?? '');
                            ?>
                            <!-- Карточка блюда (тап -> модалка) -->
                            <article
                                class="js-item-card flex gap-3 <?= e($ui['item_card']) ?> rounded-xl border border-gray-800 bg-gray-900/60 backdrop-blur p-3 cursor-pointer active:scale-[0.99] transition-all duration-200 ease-out hover:scale-[1.01] hover:border-gray-700"
                                role="button"
                                tabindex="0"
                                data-id="<?= (int)$item['id'] ?>"
                                data-name="<?= e($item['name']) ?>"
                                data-desc="<?= e($desc) ?>"
                                data-price="<?= (float)$item['price'] ?>"
                                data-image="<?= e($imgUrl) ?>"
                            >
                                <?php if ($imgUrl): ?>
                                    <div class="w-20 h-20 rounded-xl overflow-hidden bg-gray-800 flex-shrink-0">
                                        <img src="<?= e($imgUrl) ?>" alt="<?= e($item['name']) ?>" loading="lazy" class="w-full h-full object-cover">
                                    </div>
                                <?php else: ?>
                                    <div class="w-20 h-20 rounded-xl <?= e($ui['placeholder']) ?> flex items-center justify-center text-[10px] text-gray-500 flex-shrink-0">
                                        Без фото
                                    </div>
                                <?php endif; ?>

                                <div class="flex-1 flex flex-col min-w-0">
                                    <div class="flex items-start justify-between gap-2">
                                        <div class="min-w-0">
                                            <h3 class="text-sm font-semibold tracking-tight text-[#F3F4F6] truncate"><?= e($item['name']) ?></h3>

                                            <?php if ($desc !== ''): ?>
                                                <p class="mt-0.5 text-xs text-gray-400 line-clamp-2">
                                                    <?= e($desc) ?>
                                                </p>
                                            <?php endif; ?>
                                        </div>

                                        <div class="text-right whitespace-nowrap">
                                            <div class="text-sm font-semibold <?= e($ui['price']) ?> <?= $hasBrandAccent ? 'qr-brand-price' : 'text-[#22C55E]' ?>">
                                                <?= number_format((float)$item['price'], 0, '.', ' ') ?> ₽
                                            </div>
                                        </div>
                                    </div>

                                    <div class="mt-2 flex justify-end">
                                        <form method="post" class="js-add-to-cart-form" data-table-id="<?= $tableIdJs ?>">
                                            <input type="hidden" name="item_id" value="<?= (int)$item['id'] ?>">
                                            <button type="submit" name="add_item" value="1"
                                                    class="js-add-btn btn-add-cart btn-add-feedback min-h-[44px] px-4 py-2.5 rounded-xl text-sm font-medium transition-all duration-200 ease-out hover:brightness-110 <?= $hasBrandAccent ? 'qr-brand-btn border border-transparent' : 'bg-indigo-600 hover:bg-indigo-500 text-white' ?>">
                                                В корзину
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endforeach; ?>
        <?php endif; ?>
    </main>

    <!-- Корзина -->
    <section id="cart-block"
        class="fixed bottom-0 left-0 right-0 z-40 pt-2 md:static md:z-auto md:pt-4 md:pb-3 bg-gradient-to-t from-slate-950 via-slate-950/98 to-transparent md:from-transparent md:via-transparent md:bg-none border-t border-slate-800/80 md:border-0 shadow-[0_-12px_40px_rgba(0,0,0,0.45)] md:shadow-none md:sticky md:bottom-0">
        <div class="max-w-3xl mx-auto rounded-t-2xl md:rounded-3xl <?= e($ui['cart_shell']) ?> px-3 py-3 md:px-4 md:py-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] md:pb-3">
            <div class="flex items-center justify-between gap-3 mb-2">
                <div>
                    <h2 class="text-sm font-semibold text-slate-50 flex items-center gap-1.5">
                        <span class="inline-flex w-5 h-5 items-center justify-center rounded-full bg-emerald-500/10 border border-emerald-500/40 text-[10px] text-emerald-300">
                            <span id="cart-block-total-qty"><?= $cartTotalQty ?></span>
                        </span>
                        Корзина
                    </h2>
                    <p class="text-[11px] text-slate-500">Проверьте заказ перед отправкой</p>
                </div>

                <div class="text-right">
                    <div class="text-[11px] text-slate-400">Итого</div>
                    <div id="cart-block-total-sum" class="text-lg font-semibold <?= e($ui['price_total']) ?> <?= $hasBrandAccent ? 'qr-brand-price' : '' ?> leading-tight">
                        <?= number_format($cartTotalSum, 0, '.', ' ') ?> ₽
                    </div>
                </div>
            </div>

            <?php if ($upsellGuestOn): ?>
                <div id="recommended-with-order-block"
                     class="mt-2 rounded-2xl border border-amber-500/25 bg-gradient-to-br from-slate-900/95 to-slate-950/90 px-3 py-2.5 shadow-inner <?= !empty($upsellSuggestions) ? '' : 'hidden' ?>"
                     data-base-item-id="<?= (int)$lastAddedItemId ?>"
                     data-table-id="<?= (int)$tableId ?>">
                    <?php if (!empty($upsellSuggestions)): ?>
                        <div class="text-xs font-semibold text-amber-100/95 tracking-tight">Добавьте к заказу</div>
                        <p class="text-[10px] text-slate-500 mt-0.5 mb-2">Рекомендуем к заказу</p>
                        <div class="grid grid-cols-1 min-[380px]:grid-cols-2 gap-3">
                            <?php foreach ($upsellSuggestions as $s): ?>
                                <div class="rounded-2xl bg-slate-950/70 border border-slate-700/80 overflow-hidden flex flex-col text-[11px] shadow-sm min-w-0">
                                    <?php if (!empty($s['image'])): ?>
                                        <div class="w-full h-[4.5rem] bg-slate-800 flex-shrink-0">
                                            <img src="<?= e($s['image']) ?>" alt="" loading="lazy" class="w-full h-full object-cover">
                                        </div>
                                    <?php else: ?>
                                        <div class="w-full h-10 bg-slate-800/80 flex items-center justify-center text-[9px] text-slate-500">Без фото</div>
                                    <?php endif; ?>
                                    <div class="px-2.5 py-2 flex flex-col gap-1 flex-1 min-h-0">
                                        <p class="text-[9px] leading-tight text-amber-200/80 line-clamp-2"><?= e((string)($s['reason'] ?? '')) ?></p>
                                        <div class="font-semibold text-slate-100 text-[11px] leading-snug line-clamp-2"><?= e($s['name'] ?? '') ?></div>
                                        <div class="text-emerald-400/95 font-medium"><?= number_format((float)($s['price'] ?? 0), 0, '.', ' ') ?> ₽</div>
                                        <form method="post" class="mt-auto js-upsell-add-form" data-upsell-item-id="<?= (int)($s['id'] ?? 0) ?>" data-base-item-id="<?= (int)$lastAddedItemId ?>">
                                            <input type="hidden" name="item_id" value="<?= (int)($s['id'] ?? 0) ?>">
                                            <button type="submit" name="add_item" value="1"
                                                class="w-full min-h-[44px] px-3 py-2.5 rounded-xl bg-emerald-500 hover:bg-emerald-400 text-sm text-slate-950 font-semibold">
                                                Добавить
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <form id="cart-form" method="post" class="space-y-3 mt-1">
                <div id="cart-empty-text" class="mt-1 text-xs text-slate-400" <?= $cartItems ? 'style="display:none"' : '' ?>>
                    Добавьте блюда из меню, чтобы оформить заказ.
                </div>

                <div class="max-h-40 overflow-y-auto pr-1 space-y-1.5" id="cart-items-list">
                    <?php foreach ($cartItems as $ci): ?>
                        <div class="flex items-center justify-between gap-2 rounded-2xl <?= e($ui['cart_item']) ?> px-3 py-2 text-xs">
                            <div class="flex-1 min-w-0">
                                <div class="truncate text-slate-100"><?= e($ci['name']) ?></div>
                                <div class="text-[11px] text-slate-500"><?= number_format($ci['price'], 0, '.', ' ') ?> ₽ / шт</div>
                            </div>

                            <div class="flex items-center gap-2">
                                <input type="number" name="qty[<?= (int)$ci['id'] ?>]" value="<?= (int)$ci['qty'] ?>" min="0"
                                    class="w-16 min-h-[44px] rounded-xl bg-slate-950 border border-slate-700 px-2 py-2 text-sm text-center focus:outline-none focus:ring-2 focus:ring-emerald-500 touch-manipulation">

                                <div class="text-right text-sm text-slate-200 min-w-[4.5rem] tabular-nums">
                                    <?= number_format($ci['sum'], 0, '.', ' ') ?> ₽
                                </div>

                                <button type="submit" name="remove_item" value="<?= (int)$ci['id'] ?>"
                                    class="inline-flex items-center justify-center min-w-[44px] min-h-[44px] rounded-xl border border-slate-700 text-lg leading-none text-slate-400 hover:border-red-500 hover:text-red-300 touch-manipulation"
                                    title="Удалить из корзины" aria-label="Удалить из корзины">×</button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="flex flex-col gap-3 border-t border-slate-800 pt-3">
                    <?php if ($loyaltyEnabled): ?>
                        <div class="rounded-2xl bg-slate-950/80 border border-slate-800 px-3 py-2 space-y-1">
                            <div class="text-[11px] text-slate-300 font-medium">Программа лояльности</div>
                            <div class="text-[11px] text-slate-500">Введите номер телефона, чтобы копить бонусы именно в этом ресторане.</div>
                            <input type="tel" name="loyalty_phone"
                                value="<?= isset($_POST['loyalty_phone']) ? e($_POST['loyalty_phone']) : '' ?>"
                                placeholder="+7 9XX XXX-XX-XX"
                                class="mt-1 w-full min-h-[44px] rounded-2xl bg-slate-950 border border-slate-700 px-3 py-3 text-base text-slate-50 placeholder:text-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                        </div>
                    <?php endif; ?>

                    <div class="rounded-2xl bg-slate-950/80 border border-slate-800 px-3 py-2 space-y-2">
                        <div class="text-[11px] text-slate-300 font-medium">Напоминания о визите</div>
                        <div class="text-[11px] text-slate-500">Оставьте телефон — мы сможем напомнить о следующем визите.</div>
                        <input type="tel" name="crm_phone"
                            value="<?= isset($_POST['crm_phone']) ? e($_POST['crm_phone']) : '' ?>"
                            placeholder="+7 9XX XXX-XX-XX"
                            class="w-full min-h-[44px] rounded-2xl bg-slate-950 border border-slate-700 px-3 py-3 text-base text-slate-50 placeholder:text-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                        <label class="flex items-center gap-2 text-[11px] text-slate-400 cursor-pointer">
                            <input type="checkbox" name="crm_consent" value="1" class="rounded bg-slate-950 border-slate-700" <?= isset($_POST['crm_consent']) ? 'checked' : '' ?>>
                            <span>Разрешаю присылать напоминания</span>
                        </label>
                    </div>

                    <div class="flex flex-wrap gap-2 text-sm text-slate-300">
                        <?php if ($allowCardLater): ?>
                            <label class="inline-flex items-center gap-2 min-h-[44px] px-3 py-2 rounded-full bg-slate-950/80 border border-slate-700 cursor-pointer hover:border-emerald-500/60 touch-manipulation">
                                <input type="radio" name="payment_type" value="card_later" class="rounded bg-slate-950 border-slate-700"
                                    <?= $currentPaymentType === 'card_later' ? 'checked' : '' ?>>
                                <span>Картой</span>
                            </label>
                        <?php endif; ?>

                        <?php if ($allowCash): ?>
                            <label class="inline-flex items-center gap-2 min-h-[44px] px-3 py-2 rounded-full bg-slate-950/80 border border-slate-700 cursor-pointer hover:border-emerald-500/60 touch-manipulation">
                                <input type="radio" name="payment_type" value="cash" class="rounded bg-slate-950 border-slate-700"
                                    <?= $currentPaymentType === 'cash' ? 'checked' : '' ?>>
                                <span>Наличными</span>
                            </label>
                        <?php endif; ?>
                    </div>

                    <div class="flex flex-col sm:flex-row gap-2 sm:gap-3">
                        <button type="submit" name="update_cart" value="1"
                            class="js-cart-update min-h-[48px] px-4 py-3 rounded-2xl bg-slate-800 hover:bg-slate-700 text-sm text-slate-100 flex-1 sm:flex-none sm:w-44 touch-manipulation">
                            Обновить
                        </button>
                        <button type="submit" name="checkout" value="1"
                            class="flex-1 min-h-[48px] px-4 py-3 rounded-2xl bg-emerald-500 hover:bg-emerald-400 text-slate-950 text-base font-semibold touch-manipulation">
                            Оформить заказ
                        </button>
                    </div>

                    <div class="flex justify-end">
                        <button type="submit" name="clear_cart" value="1"
                            class="js-cart-clear text-[11px] text-slate-500 hover:text-slate-300 underline decoration-dotted">
                            Очистить корзину
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </section>

    <?php
    $footerConfig = require __DIR__ . '/../app/config.php';
    $footerLandingUrl = ($footerConfig['app']['protocol'] ?? 'http') . '://' . ($footerConfig['app']['main_domain'] ?? 'lvh.me');
    ?>
    <footer class="pt-4 pb-6 text-center">
        <a href="<?= e($footerLandingUrl) ?>" target="_blank" rel="noopener" class="qr-brand-footer text-slate-500 hover:text-emerald-400/90 text-[11px] transition-colors"><?= e(BRAND_NAME_FULL) ?></a>
    </footer>
</div>

<!-- ===== Модалка просмотра блюда (без обрезки текста) ===== -->
<div id="item-backdrop" class="fixed inset-0 z-50 hidden bg-black/70"></div>

<div id="item-modal" class="fixed inset-x-0 bottom-0 z-50 hidden">
    <div class="mx-auto max-w-3xl px-3 pb-[env(safe-area-inset-bottom)]">
        <div class="bg-slate-950/95 border border-slate-800 rounded-t-3xl shadow-2xl backdrop-blur-xl overflow-hidden">
            <div class="p-3 border-b border-slate-800 flex items-center justify-between gap-2">
                <div class="text-sm font-semibold text-slate-50">Просмотр блюда</div>
                <button id="item-close-x" type="button"
                        class="min-w-[44px] min-h-[44px] rounded-2xl bg-slate-800 hover:bg-slate-700 text-slate-200 flex items-center justify-center text-lg touch-manipulation"
                        aria-label="Закрыть">
                    ✕
                </button>
            </div>

            <div class="max-h-[72vh] overflow-y-auto">
                <div class="p-3">
                    <div id="item-image-wrap" class="rounded-3xl overflow-hidden bg-slate-800">
                        <img id="item-image" src="" alt="" class="w-full h-56 object-cover hidden">
                        <div id="item-noimage" class="h-56 flex items-center justify-center text-slate-400 text-sm">
                            Нет фото
                        </div>
                    </div>

                    <div class="mt-3">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <h3 id="item-name" class="text-xl font-bold text-slate-100 leading-snug"></h3>
                                <div id="item-price" class="mt-1 text-emerald-300 font-semibold text-lg"></div>
                            </div>
                        </div>

                        <div id="item-desc" class="mt-3 text-sm text-slate-300 leading-relaxed whitespace-pre-line"></div>
                    </div>
                </div>
            </div>

            <div class="p-3 border-t border-slate-800 flex flex-col-reverse sm:flex-row gap-2 sm:gap-3">
                <button id="item-close"
                        type="button"
                        class="w-full sm:w-36 min-h-[48px] px-4 py-3 rounded-2xl bg-slate-800 hover:bg-slate-700 text-slate-100 text-sm touch-manipulation">
                    Закрыть
                </button>
                <button id="item-add"
                        type="button"
                        class="flex-1 min-h-[48px] px-4 py-3 rounded-2xl bg-emerald-500 hover:bg-emerald-400 text-slate-950 text-base font-semibold btn-add-feedback touch-manipulation">
                    В корзину
                </button>
            </div>
        </div>
    </div>
</div>
<!-- ===== /Модалка просмотра блюда ===== -->

<!-- ===== Спец-предложения перед оформлением (modal) ===== -->
<div id="upsell-backdrop" class="fixed inset-0 z-50 hidden bg-black/70"></div>

<div id="upsell-modal" class="fixed inset-x-0 bottom-0 z-50 hidden">
    <div class="mx-auto max-w-3xl px-3 pb-[env(safe-area-inset-bottom)]">
        <div class="bg-slate-950/95 border border-slate-800 rounded-t-3xl shadow-2xl backdrop-blur-xl overflow-hidden">
            <div class="p-4 border-b border-slate-800">
                <div class="text-sm font-semibold text-slate-50" id="upsell-title">А может добавить к заказу?</div>
                <div class="text-[11px] text-slate-400 mt-1" id="upsell-subtitle">Часто берут вместе — можно добавить одним тапом.</div>
            </div>

            <div id="upsell-list" class="p-3 space-y-2 max-h-[45vh] overflow-y-auto"></div>

            <div class="p-3 border-t border-slate-800 flex flex-col-reverse sm:flex-row gap-2 sm:gap-3">
                <button id="upsell-skip" type="button"
                        class="flex-1 min-h-[48px] px-4 py-3 rounded-2xl bg-slate-800 hover:bg-slate-700 text-sm text-slate-100 touch-manipulation">
                    Оформить без добавлений
                </button>
                <button id="upsell-close" type="button"
                        class="sm:w-44 min-h-[48px] px-4 py-3 rounded-2xl bg-emerald-500 hover:bg-emerald-400 text-sm font-semibold text-slate-950 touch-manipulation">
                    Вернуться
                </button>
            </div>
        </div>
    </div>
</div>
<!-- ===== /Спец-предложения ===== -->

<!-- One-tap upsell bar (after add to cart) -->
<div id="one-tap-upsell-bar" class="hidden max-w-3xl mx-auto mt-2 px-3">
    <div class="rounded-2xl bg-slate-800/90 border border-slate-700 px-3 py-2 flex flex-wrap items-center gap-2">
        <span class="text-[11px] text-slate-400 mr-1">Часто берут вместе:</span>
        <div id="one-tap-upsell-items" class="flex flex-wrap gap-2"></div>
        <button type="button" id="one-tap-upsell-close" class="ml-auto text-slate-500 hover:text-slate-300 text-[11px]">×</button>
    </div>
</div>

<script>
(function() {
    const tableId = <?= (int)$tableIdJs ?>;
    const orderId = <?= (int)$orderIdJs ?>;
    window.upsellEnabled = <?= $upsellEnabled ? 'true' : 'false' ?>;
    window.upsellGuestOn = <?= $upsellGuestOn ? 'true' : 'false' ?>;

    function qrNotifyUpsellBlockShown(bl) {
        if (!window.upsellEnabled || !window.upsellGuestOn || !bl) return;
        if (bl.dataset.tracked) return;
        bl.dataset.tracked = '1';
        var tableIdShown = String(bl.dataset.tableId || tableId);
        var baseIdShown = String(bl.dataset.baseItemId || '');
        var fd = new FormData();
        fd.append('event', 'shown');
        fd.append('table_id', tableIdShown);
        fd.append('base_item_id', baseIdShown);
        fetch('/ajax/upsell_event.php', { method: 'POST', body: fd }).catch(function(){});

        var forms = bl.querySelectorAll('form.js-upsell-add-form');
        forms.forEach(function (f) {
            var upsellId = f && f.dataset ? f.dataset.upsellItemId : '';
            if (!upsellId) return;
            var fd2 = new FormData();
            fd2.append('event', 'upsell_shown');
            fd2.append('table_id', tableIdShown);
            fd2.append('upsell_item_id', String(upsellId));
            fd2.append('base_item_id', baseIdShown);
            fetch('/ajax/upsell_event.php', { method: 'POST', body: fd2 }).catch(function(){});
        });
    }

    (function trackUpsellShownInitial() {
        qrNotifyUpsellBlockShown(document.getElementById('recommended-with-order-block'));
    })();

    (function trackUpsellAddClick() {
        if (!window.upsellEnabled || !window.upsellGuestOn) return;
        var bl = document.getElementById('recommended-with-order-block');
        if (!bl) return;
        bl.addEventListener('submit', function(ev) {
            var form = ev.target && ev.target.closest && ev.target.closest('form.js-upsell-add-form');
            if (!form) return;
            var upsellId = form.dataset.upsellItemId;
            var baseId = form.dataset.baseItemId;
            if (upsellId) {
                var fd = new FormData();
                fd.append('event', 'add_click');
                fd.append('table_id', String(tableId));
                fd.append('upsell_item_id', String(upsellId));
                fd.append('base_item_id', String(baseId || ''));
                fetch('/ajax/upsell_event.php', { method: 'POST', body: fd }).catch(function(){});

                // Click & added-to-cart learning events (same moment as submit on server).
                var fd2 = new FormData();
                fd2.append('event', 'upsell_clicked');
                fd2.append('table_id', String(tableId));
                fd2.append('upsell_item_id', String(upsellId));
                fd2.append('base_item_id', String(baseId || ''));
                fetch('/ajax/upsell_event.php', { method: 'POST', body: fd2 }).catch(function(){});

                var fd3 = new FormData();
                fd3.append('event', 'upsell_added_to_cart');
                fd3.append('table_id', String(tableId));
                fd3.append('upsell_item_id', String(upsellId));
                fd3.append('base_item_id', String(baseId || ''));
                fetch('/ajax/upsell_event.php', { method: 'POST', body: fd3 }).catch(function(){});
            }
        }, true);
    })();

    function getOffersUrl() {
        var u = '/qr_offers.php?table_id=' + tableId;
        if (orderId > 0) u += '&order_id=' + orderId;
        return u;
    }

    function escapeHtml(str) {
        return String(str ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function updateCartUI(data) {
        if (!data || !data.success) return;

        const totalQtyEl  = document.getElementById('cart-block-total-qty');
        const totalSumEl  = document.getElementById('cart-block-total-sum');
        if (totalQtyEl) totalQtyEl.textContent = data.cartTotalQty;
        if (totalSumEl) totalSumEl.textContent = (data.cartTotalSumFormatted || data.cartTotalSum) + ' ₽';

        const miniBtn   = document.getElementById('mini-cart-button');
        const miniCount = document.getElementById('mini-cart-count');
        const miniTotal = document.getElementById('mini-cart-total');

        if (miniBtn) {
            if (data.cartTotalQty > 0) miniBtn.classList.remove('hidden');
            else miniBtn.classList.add('hidden');
        }
        if (miniCount) miniCount.textContent = data.cartTotalQty;
        if (miniTotal) miniTotal.textContent = (data.cartTotalSumFormatted || data.cartTotalSum) + ' ₽';

        const list = document.getElementById('cart-items-list');
        const emptyText = document.getElementById('cart-empty-text');
        if (!list || !emptyText) return;

        if (!Array.isArray(data.items) || data.items.length === 0) {
            list.innerHTML = '';
            emptyText.style.display = '';
            return;
        }

        emptyText.style.display = 'none';

        const itemsHtml = data.items.map(function(item) {
            const id    = parseInt(item.id, 10) || 0;
            const name  = escapeHtml(item.name);
            const price = Number(item.price || 0);
            const qty   = parseInt(item.qty, 10) || 0;
            const sum   = Number(item.sum || 0);
            const priceStr = price.toLocaleString('ru-RU', { maximumFractionDigits: 0 });
            const sumStr   = sum.toLocaleString('ru-RU',   { maximumFractionDigits: 0 });

            return `
<div class="flex items-center justify-between gap-2 rounded-2xl <?= e($ui['cart_item']) ?> px-3 py-2 text-xs">
  <div class="flex-1 min-w-0">
    <div class="truncate text-slate-100">${name}</div>
    <div class="text-[11px] text-slate-500">${priceStr} ₽ / шт</div>
  </div>
  <div class="flex items-center gap-2">
    <input type="number" name="qty[${id}]" value="${qty}" min="0"
      class="w-16 min-h-[44px] rounded-xl bg-slate-950 border border-slate-700 px-2 py-2 text-sm text-center focus:outline-none focus:ring-2 focus:ring-emerald-500 touch-manipulation">
    <div class="text-right text-sm text-slate-200 min-w-[4.5rem] tabular-nums">${sumStr} ₽</div>
    <button type="submit" name="remove_item" value="${id}"
      class="inline-flex items-center justify-center min-w-[44px] min-h-[44px] rounded-xl border border-slate-700 text-lg leading-none text-slate-400 hover:border-red-500 hover:text-red-300 touch-manipulation"
      title="Удалить из корзины" aria-label="Удалить из корзины">×</button>
  </div>
</div>`;
        }).join('');

        list.innerHTML = itemsHtml;

        renderSmartUpsellStrip(data);
    }

    function renderSmartUpsellStrip(data) {
        var bl = document.getElementById('recommended-with-order-block');
        if (!bl) return;
        if (!window.upsellGuestOn) {
            bl.classList.add('hidden');
            bl.innerHTML = '';
            return;
        }
        delete bl.dataset.tracked;
        var items = (data && Array.isArray(data.smart_upsells)) ? data.smart_upsells : [];
        if (!items.length) {
            bl.classList.add('hidden');
            bl.innerHTML = '';
            return;
        }
        bl.classList.remove('hidden');
        bl.dataset.tableId = String(tableId);
        bl.dataset.baseItemId = String((data.last_added_item_id != null ? data.last_added_item_id : 0));
        var maxN = Math.min(3, items.length);
        var head = '<div class="text-xs font-semibold text-amber-100/95 tracking-tight">Добавьте к заказу</div>'
            + '<p class="text-[10px] text-slate-500 mt-0.5 mb-2">Рекомендуем к заказу</p>'
            + '<div class="grid grid-cols-1 min-[380px]:grid-cols-2 gap-3">';
        var body = items.slice(0, maxN).map(function(s) {
            var id = parseInt(s.id, 10) || 0;
            var name = escapeHtml(s.name || '');
            var reason = escapeHtml(s.reason || '');
            var price = (Number(s.price || 0)).toLocaleString('ru-RU', { maximumFractionDigits: 0 });
            var img = (s.image || '').trim();
            var imgBlock = img
                ? '<div class="w-full h-[4.5rem] bg-slate-800 flex-shrink-0"><img src="' + escapeHtml(img) + '" alt="" loading="lazy" class="w-full h-full object-cover"></div>'
                : '<div class="w-full h-10 bg-slate-800/80 flex items-center justify-center text-[9px] text-slate-500">Без фото</div>';
            return '<div class="rounded-2xl bg-slate-950/70 border border-slate-700/80 overflow-hidden flex flex-col text-[11px] shadow-sm min-w-0">'
                + imgBlock
                + '<div class="px-2.5 py-2 flex flex-col gap-1 flex-1 min-h-0">'
                + '<p class="text-[9px] leading-tight text-amber-200/80 line-clamp-2">' + reason + '</p>'
                + '<div class="font-semibold text-slate-100 text-[11px] leading-snug line-clamp-2">' + name + '</div>'
                + '<div class="text-emerald-400/95 font-medium">' + price + ' ₽</div>'
                + '<form method="post" class="mt-auto js-upsell-add-form" data-upsell-item-id="' + id + '" data-base-item-id="' + String(bl.dataset.baseItemId || '') + '">'
                + '<input type="hidden" name="item_id" value="' + id + '">'
                + '<button type="submit" name="add_item" value="1" class="w-full min-h-[44px] px-3 py-2.5 rounded-xl bg-emerald-500 hover:bg-emerald-400 text-sm text-slate-950 font-semibold touch-manipulation">Добавить</button>'
                + '</form></div></div>';
        }).join('');
        bl.innerHTML = head + body + '</div>';
        qrNotifyUpsellBlockShown(bl);
    }

    function sendCartRequest(formData) {
        return fetch(window.location.pathname + '?table_id=' + tableId + '&ajax=1', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => { updateCartUI(data); return data; })
        .catch(err => { console.error('Cart AJAX error', err); return null; });
    }

    var oneTapUpsellBar = document.getElementById('one-tap-upsell-bar');
    var oneTapUpsellItems = document.getElementById('one-tap-upsell-items');
    var oneTapUpsellClose = document.getElementById('one-tap-upsell-close');
    var UPSELL_THROTTLE_MS = 15000;

    function showOneTapUpsell() {
        if (!window.upsellEnabled || !window.upsellGuestOn || !oneTapUpsellBar || !oneTapUpsellItems) return;
        try {
            var last = parseInt(sessionStorage.getItem('qr_upsell_last') || '0', 10);
            if (Date.now() - last < UPSELL_THROTTLE_MS) return;
        } catch (e) {}
        fetch(getOffersUrl(), { cache: 'no-store' })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (!data || !data.success || data.enabled === false || !Array.isArray(data.items) || data.items.length === 0) {
                    oneTapUpsellBar.classList.add('hidden');
                    return;
                }
                var top = data.items.slice(0, 3);
                oneTapUpsellItems.innerHTML = top.map(function(it) {
                    var id = parseInt(it.id, 10) || 0;
                    var name = escapeHtml(it.name || '');
                    var price = (Number(it.price || 0)).toLocaleString('ru-RU', { maximumFractionDigits: 0 });
                    return '<span class="inline-flex flex-col sm:flex-row sm:items-center gap-2 rounded-xl bg-slate-950/80 px-3 py-2 text-xs w-full sm:w-auto">' +
                        '<span class="text-slate-200 min-w-0">' + name + ' (' + price + ' ₽)</span>' +
                        '<button type="button" class="js-one-tap-add min-h-[40px] px-3 py-2 rounded-xl bg-emerald-500 hover:bg-emerald-400 text-slate-950 text-xs font-semibold touch-manipulation" data-item-id="' + id + '">+ Добавить</button>' +
                        '</span>';
                }).join('');
                oneTapUpsellBar.classList.remove('hidden');
                try { sessionStorage.setItem('qr_upsell_last', String(Date.now())); } catch (e) {}
                (window.upsellAddedIds = window.upsellAddedIds || []);
                oneTapUpsellItems.querySelectorAll('.js-one-tap-add').forEach(function(btn) {
                    btn.addEventListener('click', function() {
                        var itemId = btn.getAttribute('data-item-id');
                        if (!itemId) return;
                        window.upsellAddedIds.push(itemId);
                        var fd = new FormData();
                        fd.append('event', 'add_click');
                        fd.append('table_id', String(tableId));
                        fd.append('upsell_item_id', String(itemId));
                        fetch('/ajax/upsell_event.php', { method: 'POST', body: fd }).catch(function() {});

                        // New events for conversion learning.
                        var fd2 = new FormData();
                        fd2.append('event', 'upsell_clicked');
                        fd2.append('table_id', String(tableId));
                        fd2.append('upsell_item_id', String(itemId));
                        fetch('/ajax/upsell_event.php', { method: 'POST', body: fd2 }).catch(function() {});

                        var fd3 = new FormData();
                        fd3.append('event', 'upsell_added_to_cart');
                        fd3.append('table_id', String(tableId));
                        fd3.append('upsell_item_id', String(itemId));
                        fetch('/ajax/upsell_event.php', { method: 'POST', body: fd3 }).catch(function() {});

                        fd = new FormData();
                        fd.append('add_item', '1');
                        fd.append('item_id', itemId);
                        sendCartRequest(fd);
                        btn.textContent = 'Добавлено ✓';
                        btn.disabled = true;
                    });
                });
            })
            .catch(function() { oneTapUpsellBar.classList.add('hidden'); });
    }

    if (oneTapUpsellClose) oneTapUpsellClose.addEventListener('click', function() { oneTapUpsellBar.classList.add('hidden'); });

    // ===== Модалка блюда =====
    const itemBackdrop = document.getElementById('item-backdrop');
    const itemModal    = document.getElementById('item-modal');
    const itemCloseX   = document.getElementById('item-close-x');
    const itemClose    = document.getElementById('item-close');
    const itemAdd      = document.getElementById('item-add');

    const itemNameEl   = document.getElementById('item-name');
    const itemDescEl   = document.getElementById('item-desc');
    const itemPriceEl  = document.getElementById('item-price');
    const itemImg      = document.getElementById('item-image');
    const itemNoImg    = document.getElementById('item-noimage');

    let currentModalItemId = 0;

    function openItemModal(payload) {
        currentModalItemId = payload.id || 0;

        if (itemNameEl)  itemNameEl.textContent = payload.name || '';
        if (itemPriceEl) itemPriceEl.textContent = (payload.price || 0).toLocaleString('ru-RU', {maximumFractionDigits: 0}) + ' ₽';

        // описание: показываем полностью, без clamp
        const desc = (payload.desc || '').trim();
        if (itemDescEl) {
            itemDescEl.textContent = desc ? desc : 'Описание отсутствует.';
        }

        // картинка
        const img = (payload.image || '').trim();
        if (img && itemImg && itemNoImg) {
            itemImg.src = img;
            itemImg.alt = payload.name || '';
            itemImg.classList.remove('hidden');
            itemNoImg.classList.add('hidden');
        } else if (itemImg && itemNoImg) {
            itemImg.src = '';
            itemImg.alt = '';
            itemImg.classList.add('hidden');
            itemNoImg.classList.remove('hidden');
        }

        if (itemBackdrop) itemBackdrop.classList.remove('hidden');
        if (itemModal) itemModal.classList.remove('hidden');
        document.body.style.overflow = 'hidden';
    }

    function closeItemModal() {
        if (itemBackdrop) itemBackdrop.classList.add('hidden');
        if (itemModal) itemModal.classList.add('hidden');
        document.body.style.overflow = '';
        currentModalItemId = 0;
    }

    itemBackdrop && itemBackdrop.addEventListener('click', closeItemModal);
    itemCloseX   && itemCloseX.addEventListener('click', closeItemModal);
    itemClose    && itemClose.addEventListener('click', closeItemModal);

    itemAdd && itemAdd.addEventListener('click', function() {
        if (!currentModalItemId) return;
        const fd = new FormData();
        fd.append('add_item', '1');
        fd.append('item_id', String(currentModalItemId));
        sendCartRequest(fd).then(function() { showOneTapUpsell(); });

        itemAdd.textContent = 'Добавлено ✓';
        itemAdd.disabled = true;
        itemAdd.classList.add('opacity-70');

        setTimeout(() => {
            itemAdd.textContent = 'В корзину';
            itemAdd.disabled = false;
            itemAdd.classList.remove('opacity-70');
        }, 900);

        // не закрываем — пусть гость читает, но можно закрыть:
        // closeItemModal();
    });

    // ESC для десктопа
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeItemModal();
    });

    // ===== Основная логика =====
    document.addEventListener('DOMContentLoaded', function () {
        // add-to-cart по кнопке (как раньше)
        const addForms = document.querySelectorAll('.js-add-to-cart-form');
        addForms.forEach(function(form) {
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                const fd = new FormData(form);
                fd.append('add_item', '1');
                sendCartRequest(fd).then(function() { showOneTapUpsell(); });
            });
        });

        const cartForm = document.getElementById('cart-form');
        const cartList = document.getElementById('cart-items-list');
        if (!cartForm) return;

        window.upsellAddedIds = window.upsellAddedIds || [];
        cartForm.addEventListener('submit', function(e) {
            var isCheckout = e.submitter && e.submitter.name === 'checkout';
            if (isCheckout && window.upsellAddedIds && window.upsellAddedIds.length > 0) {
                for (var i = 0; i < window.upsellAddedIds.length; i++) {
                    var inp = document.createElement('input');
                    inp.type = 'hidden';
                    inp.name = 'upsell_added[]';
                    inp.value = window.upsellAddedIds[i];
                    cartForm.appendChild(inp);
                }
            }
        });

        const updateBtn = cartForm.querySelector('.js-cart-update');
        if (updateBtn) {
            updateBtn.addEventListener('click', function (e) {
                e.preventDefault();
                const fd = new FormData(cartForm);
                fd.set('update_cart', '1');
                sendCartRequest(fd);
            });
        }

        const clearBtn = cartForm.querySelector('.js-cart-clear');
        if (clearBtn) {
            clearBtn.addEventListener('click', function (e) {
                e.preventDefault();
                if (!confirm('Очистить корзину?')) return;
                const fd = new FormData();
                fd.append('clear_cart', '1');
                sendCartRequest(fd);
            });
        }

        if (cartList) {
            cartList.addEventListener('click', function (e) {
                const btn = e.target.closest('button[name="remove_item"]');
                if (!btn) return;
                e.preventDefault();
                const id = btn.value;
                const fd = new FormData();
                fd.append('remove_item', String(id));
                sendCartRequest(fd);
            });
        }

        // Открытие модалки по тапу на карточку блюда
        document.querySelectorAll('.js-item-card').forEach(function(card) {
            // не открывать модалку при тапе на кнопку "В корзину"
            card.addEventListener('click', function(ev) {
                if (ev.target.closest('.js-add-btn')) return;

                const payload = {
                    id: parseInt(card.dataset.id || '0', 10) || 0,
                    name: card.dataset.name || '',
                    desc: card.dataset.desc || '',
                    price: Number(card.dataset.price || 0),
                    image: card.dataset.image || ''
                };
                openItemModal(payload);
            });

            // доступность: enter/space
            card.addEventListener('keydown', function(ev) {
                if (ev.key === 'Enter' || ev.key === ' ') {
                    ev.preventDefault();
                    const payload = {
                        id: parseInt(card.dataset.id || '0', 10) || 0,
                        name: card.dataset.name || '',
                        desc: card.dataset.desc || '',
                        price: Number(card.dataset.price || 0),
                        image: card.dataset.image || ''
                    };
                    openItemModal(payload);
                }
            });
        });

        // ===== Спец-предложения перед оформлением =====
        let upsellShownThisVisit = false;

        const upsellBackdrop = document.getElementById('upsell-backdrop');
        const upsellModal    = document.getElementById('upsell-modal');
        const upsellList     = document.getElementById('upsell-list');
        const upsellTitle    = document.getElementById('upsell-title');
        const upsellSubtitle = document.getElementById('upsell-subtitle');
        const upsellSkip     = document.getElementById('upsell-skip');
        const upsellClose    = document.getElementById('upsell-close');

        function openUpsell() {
            if (upsellBackdrop) upsellBackdrop.classList.remove('hidden');
            if (upsellModal) upsellModal.classList.remove('hidden');
            document.body.style.overflow = 'hidden';
        }
        function closeUpsell() {
            if (upsellBackdrop) upsellBackdrop.classList.add('hidden');
            if (upsellModal) upsellModal.classList.add('hidden');
            document.body.style.overflow = '';
        }

        upsellBackdrop && upsellBackdrop.addEventListener('click', closeUpsell);
        upsellClose    && upsellClose.addEventListener('click', closeUpsell);

        function submitCheckout(checkoutBtn) {
            if (cartForm.requestSubmit && checkoutBtn) {
                cartForm.requestSubmit(checkoutBtn);
                return;
            }
            let hidden = cartForm.querySelector('input[name="checkout"][type="hidden"]');
            if (!hidden) {
                hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = 'checkout';
                hidden.value = '1';
                cartForm.appendChild(hidden);
            } else {
                hidden.value = '1';
            }
            cartForm.submit();
        }

        const checkoutBtn = cartForm.querySelector('button[name="checkout"]');

        upsellSkip && upsellSkip.addEventListener('click', function () {
            upsellShownThisVisit = true;
            closeUpsell();
            submitCheckout(checkoutBtn);
        });

        if (checkoutBtn) {
            checkoutBtn.addEventListener('click', async function (e) {
                if (upsellShownThisVisit) return;
                e.preventDefault();
                if (!window.upsellEnabled) {
                    upsellShownThisVisit = true;
                    submitCheckout(checkoutBtn);
                    return;
                }
                try {
                    const fdSync = new FormData(cartForm);
                    fdSync.set('update_cart', '1');
                    await sendCartRequest(fdSync);
                } catch (err) {}

                try {
                    const res  = await fetch(getOffersUrl(), { cache: 'no-store' });
                    const data = await res.json();

                    if (!data || !data.success || data.enabled === false || !Array.isArray(data.items) || data.items.length === 0) {
                        upsellShownThisVisit = true;
                        submitCheckout(checkoutBtn);
                        return;
                    }

                    if (upsellTitle)    upsellTitle.textContent    = data.title || 'А может добавить к заказу?';
                    if (upsellSubtitle) upsellSubtitle.textContent = data.subtitle || 'Часто берут вместе — можно добавить одним тапом.';

                    upsellList.innerHTML = data.items.map(function (it) {
                        const id = parseInt(it.id, 10) || 0;
                        const name = escapeHtml(it.name || '');
                        const desc = escapeHtml(it.description || '');
                        const price = Number(it.price || 0).toLocaleString('ru-RU', { maximumFractionDigits: 0 });

                        const img = it.image
                            ? `<div class="w-16 h-16 rounded-2xl overflow-hidden bg-slate-800 flex-shrink-0">
                                   <img src="${escapeHtml(it.image)}" loading="lazy" class="w-full h-full object-cover" alt="${name}">
                               </div>`
                            : `<div class="w-16 h-16 rounded-2xl bg-slate-800/60 flex items-center justify-center text-[10px] text-slate-500 flex-shrink-0">
                                   Без фото
                               </div>`;

                        return `
<div class="flex gap-3 rounded-3xl bg-slate-950/80 border border-slate-800 px-3 py-3">
    ${img}
    <div class="flex-1 min-w-0">
        <div class="flex items-start justify-between gap-2">
            <div class="min-w-0">
                <div class="text-sm font-semibold text-slate-100 truncate">${name}</div>
                ${desc ? `<div class="text-[11px] text-slate-400 mt-0.5 line-clamp-2">${desc}</div>` : ``}
            </div>
            <div class="text-sm font-semibold text-emerald-300 whitespace-nowrap">${price} ₽</div>
        </div>
        <div class="mt-2 flex justify-end gap-2">
            <button type="button"
                    class="js-upsell-add px-3 py-2 rounded-2xl bg-emerald-500 hover:bg-emerald-400 text-slate-950 text-xs font-semibold"
                    data-item-id="${id}">
                Добавить
            </button>
        </div>
    </div>
</div>`;
                    }).join('');

                    upsellList.querySelectorAll('.js-upsell-add').forEach(function (btn) {
                        btn.addEventListener('click', function () {
                            const itemId = btn.dataset.itemId;
                            const fd = new FormData();
                            fd.append('add_item', '1');
                            fd.append('item_id', String(itemId));

                            // New events for conversion learning.
                            var fd2 = new FormData();
                            fd2.append('event', 'upsell_clicked');
                            fd2.append('table_id', String(tableId));
                            fd2.append('upsell_item_id', String(itemId));
                            fetch('/ajax/upsell_event.php', { method: 'POST', body: fd2 }).catch(function(){});

                            var fd3 = new FormData();
                            fd3.append('event', 'upsell_added_to_cart');
                            fd3.append('table_id', String(tableId));
                            fd3.append('upsell_item_id', String(itemId));
                            fetch('/ajax/upsell_event.php', { method: 'POST', body: fd3 }).catch(function(){});

                            sendCartRequest(fd);

                            btn.textContent = 'Добавлено ✓';
                            btn.disabled = true;
                            btn.classList.add('opacity-60');
                        });
                    });

                    openUpsell();
                } catch (err) {
                    upsellShownThisVisit = true;
                    submitCheckout(checkoutBtn);
                }
            });
        }
        // ===== /Спец-предложения =====
    });
})();
</script>
<?php if (is_demo_mode()): ?>
<script>(function(){var k='demo_pages_visited';var v=[];try{v=JSON.parse(sessionStorage.getItem(k)||'[]');}catch(e){}var p=location.pathname;if(v.indexOf(p)===-1){v.push(p);try{sessionStorage.setItem(k,JSON.stringify(v));}catch(e){}}})();</script>
<?php endif; ?>
</body>
</html>
