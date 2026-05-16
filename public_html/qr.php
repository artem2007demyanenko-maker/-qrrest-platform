<?php

require_once __DIR__ . '/../app/bootstrap.php';
if (!headers_sent()) {
    header('Content-Type: text/html; charset=UTF-8');
}
require_once __DIR__ . '/../app/order_payment_runtime.php';
require_once __DIR__ . '/../app/upsell.php';
if (file_exists(__DIR__ . '/../app/schema_guard.php')) {
    require_once __DIR__ . '/../app/schema_guard.php';
}
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
if (file_exists(__DIR__ . '/../app/runtime_schema_bootstrap.php')) {
    require_once __DIR__ . '/../app/runtime_schema_bootstrap.php';
}
if (function_exists('runtime_schema_ensure_menu_items_food_meta')) {
    runtime_schema_ensure_menu_items_food_meta($pdo);
}
if (function_exists('runtime_schema_ensure_menu_items_availability')) {
    runtime_schema_ensure_menu_items_availability($pdo);
}
if (function_exists('runtime_schema_ensure_production_stations')) {
    runtime_schema_ensure_production_stations($pdo);
}
if (function_exists('runtime_schema_ensure_orders_order_type')) {
    runtime_schema_ensure_orders_order_type($pdo);
}
if (function_exists('runtime_schema_ensure_orders_fulfillment_meta')) {
    runtime_schema_ensure_orders_fulfillment_meta($pdo);
}
if (function_exists('runtime_schema_ensure_orders_courier_meta')) {
    runtime_schema_ensure_orders_courier_meta($pdo);
}
if (function_exists('runtime_schema_ensure_restaurant_promotions')) {
    runtime_schema_ensure_restaurant_promotions($pdo);
}
if (function_exists('runtime_schema_ensure_orders_promo_meta')) {
    runtime_schema_ensure_orders_promo_meta($pdo);
}
if (function_exists('runtime_schema_ensure_guest_profiles')) {
    runtime_schema_ensure_guest_profiles($pdo);
}
if (function_exists('runtime_schema_ensure_table_reservations')) {
    runtime_schema_ensure_table_reservations($pdo);
}
$hasMenuTemporaryUnavailableCol = function_exists('db_column_exists') && db_column_exists('menu_items', 'is_temporarily_unavailable');
$menuTempUnavailableCond = $hasMenuTemporaryUnavailableCol
    ? " AND COALESCE(is_temporarily_unavailable, 0) = 0 "
    : '';
$menuTempUnavailableCondMi = $hasMenuTemporaryUnavailableCol
    ? " AND COALESCE(mi.is_temporarily_unavailable, 0) = 0 "
    : '';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!function_exists('qr_checkout_order_type_normalize')) {
    function qr_checkout_order_type_normalize(?string $rawType, bool $isTableFlow): string
    {
        $raw = strtolower(trim((string)($rawType ?? '')));
        if ($isTableFlow) {
            return 'hall';
        }
        if (in_array($raw, ['delivery', 'pickup', 'preorder'], true)) {
            return $raw;
        }
        return 'delivery';
    }
}

if (!function_exists('qr_checkout_order_type_label')) {
    function qr_checkout_order_type_label(string $type): string
    {
        return [
            'hall' => 'В зале',
            'delivery' => 'Доставка',
            'pickup' => 'Самовывоз',
            'preorder' => 'Предзаказ',
            'manual' => 'Ручной',
        ][$type] ?? 'В зале';
    }
}


if (!$currentRestaurant) {
    http_response_code(404);
    echo "Ресторан не найден (по поддомену).";
    exit;
}

$rawTableId = isset($_GET['table_id']) ? trim((string)$_GET['table_id']) : '';
$tableIdProvidedRaw = ($rawTableId !== '');
$requestTableId = $tableIdProvidedRaw ? (int)$rawTableId : 0;
$openedWithoutTableId = (!$tableIdProvidedRaw || $requestTableId <= 0);
$isDeliveryMode = ($requestTableId <= 0);

// Demo: allow opening menu without table_id by mapping to a reserved virtual table id.
if ($isDeliveryMode && is_demo_mode()) {
    if (!defined('QR_PUBLIC_DEMO_DELIVERY_TABLE_ID')) {
        define('QR_PUBLIC_DEMO_DELIVERY_TABLE_ID', 900001);
    }
    $requestTableId = (int)QR_PUBLIC_DEMO_DELIVERY_TABLE_ID;
    $isDeliveryMode = true;
}

if (is_demo_mode()) {
    $table = demo_table_by_id((int)$requestTableId);
    if ($isDeliveryMode) {
        $table['name'] = qr_public_delivery_table_name();
    }
} elseif ($isDeliveryMode) {
    if (!function_exists('db_column_exists') && file_exists(__DIR__ . '/../app/schema_guard.php')) {
        require_once __DIR__ . '/../app/schema_guard.php';
    }
    try {
        $table = qr_public_ensure_delivery_table($pdo, (int)$currentRestaurant['id']);
    } catch (Throwable $e) {
        http_response_code(500);
        echo 'Не удалось подготовить режим доставки. Попробуйте позже.';
        exit;
    }
} else {
    $stmt = $pdo->prepare("
        SELECT * FROM tables
        WHERE id = :id AND restaurant_id = :rest
        LIMIT 1
    ");
    $stmt->execute([
        'id'   => $requestTableId,
        'rest' => $currentRestaurant['id'],
    ]);
    $table = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$table) {
        http_response_code(404);
        echo "Стол не найден.";
        exit;
    }
    if (qr_public_is_delivery_table_row($table)) {
        http_response_code(404);
        echo "Стол не найден.";
        exit;
    }
}

$tableId = (int)($table['id'] ?? 0);
if ($tableId <= 0) {
    http_response_code(500);
    echo "Некорректный контекст стола.";
    exit;
}

if (empty($_SESSION['reservation_csrf'])) {
    $_SESSION['reservation_csrf'] = bin2hex(random_bytes(32));
}
$reservationCsrfToken = (string)($_SESSION['reservation_csrf'] ?? '');
$guestBookingTables = [];
$guestBookingEnabled = function_exists('db_table_exists') && db_table_exists('table_reservations');
if ($guestBookingEnabled && !is_demo_mode()) {
    try {
        $stmtGuestTables = $pdo->prepare("
            SELECT t.id, t.name
            FROM tables t
            WHERE t.restaurant_id = :restaurant_id
            " . qr_public_sql_exclude_delivery($pdo, 't') . "
            ORDER BY t.name ASC, t.id ASC
        ");
        $stmtGuestTables->execute([
            ':restaurant_id' => (int)$currentRestaurant['id'],
        ]);
        $guestBookingTables = $stmtGuestTables->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $guestBookingTables = [];
    }
}

$_SESSION['guest_last_menu_context'] = $isDeliveryMode ? 'delivery' : 'table';
$_SESSION['guest_last_table_id'] = $isDeliveryMode ? 0 : (int)$requestTableId;
$guestSessionUser = null;
$guestSessionBalance = 0;
if (function_exists('guest_current')) {
    $guestSessionUser = guest_current($pdo);
}
if ($guestSessionUser && !empty($currentRestaurant['id']) && function_exists('guest_loyalty_balance_by_guest_rest')) {
    $guestSessionBalance = guest_loyalty_balance_by_guest_rest($pdo, (int)$currentRestaurant['id'], (int)$guestSessionUser['id']);
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

// Production fallback: if flags/plan checks left loyalty off but DB row says enabled
if (!$loyaltyEnabled && !empty($currentRestaurant['id'])) {
    try {
        $ls = $pdo->prepare('SELECT enabled FROM restaurant_loyalty_settings WHERE restaurant_id = ? LIMIT 1');
        $ls->execute([(int)$currentRestaurant['id']]);
        $lsRow = $ls->fetch(PDO::FETCH_ASSOC);
        if ($lsRow && (int)($lsRow['enabled'] ?? 0) === 1) {
            $loyaltyEnabled = true;
        }
    } catch (Throwable $e) {
        // table/column missing on older schemas — ignore
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
            $canAdd = false;
            if (is_demo_mode()) {
                $canAdd = true;
            } else {
                try {
                    $stmtCanAdd = $pdo->prepare("
                        SELECT id
                        FROM menu_items
                        WHERE id = :id
                          AND restaurant_id = :rest
                          AND available = 1
                          {$menuTempUnavailableCond}
                        LIMIT 1
                    ");
                    $stmtCanAdd->execute([
                        ':id' => $itemId,
                        ':rest' => (int)$currentRestaurant['id'],
                    ]);
                    $canAdd = (bool)$stmtCanAdd->fetchColumn();
                } catch (Throwable $e) {
                    error_log('QR_ADD_ITEM_AVAILABILITY_CHECK_FAIL item_id=' . $itemId . ' ' . $e->getMessage());
                    $canAdd = false;
                }
            }
            if ($canAdd) {
                $qty = isset($cart[$itemId]) ? (int)$cart[$itemId] : 0;
                $cart[$itemId] = $qty + 1;
                $lastAddedItemId = $itemId;
            } else {
                $errors[] = 'Блюдо временно недоступно и не добавлено в корзину.';
            }
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
                $basePath = isset($_SERVER['REQUEST_URI']) ? explode('?', $_SERVER['REQUEST_URI'])[0] : '/qr.php';
                $demoLoc = qr_public_build_url($basePath, array_filter([
                    'table_id' => $isDeliveryMode ? null : (int)$tableId,
                    'demo_order' => 1,
                ], static fn($v) => $v !== null && $v !== ''));
                header('Location: ' . $demoLoc);
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

            $checkoutOrderType = qr_checkout_order_type_normalize(
                (string)($_POST['order_type'] ?? ''),
                !$isDeliveryMode
            );
            $preorderReceiveType = strtolower(trim((string)($_POST['preorder_receive_type'] ?? 'pickup')));
            if (!in_array($preorderReceiveType, ['pickup', 'delivery'], true)) {
                $preorderReceiveType = 'pickup';
            }
            $preorderDateRaw = trim((string)($_POST['preorder_date'] ?? ''));
            $preorderTimeRaw = trim((string)($_POST['preorder_time'] ?? ''));
            $preorderScheduledAt = null;
            $preorderScheduledUi = '';

            $deliveryFullName = trim((string)($_POST['delivery_full_name'] ?? ''));
            $deliveryPhoneRaw = trim((string)($_POST['delivery_phone'] ?? ''));
            $deliveryAddress  = trim((string)($_POST['delivery_address'] ?? ''));
            $deliveryPhoneNormalized = null;

            if ($isDeliveryMode) {
                if ($deliveryFullName === '' || mb_strlen($deliveryFullName) < 3) {
                    $errors[] = 'Укажите ФИО полностью.';
                }
                if ($deliveryPhoneRaw === '') {
                    $errors[] = 'Укажите контактный телефон.';
                } elseif (function_exists('loyalty_normalize_phone')) {
                    $deliveryPhoneNormalized = loyalty_normalize_phone($deliveryPhoneRaw);
                    if (!$deliveryPhoneNormalized) {
                        $errors[] = 'Пожалуйста, введите корректный номер телефона.';
                    }
                } else {
                    $deliveryPhoneNormalized = $deliveryPhoneRaw;
                }
                $requiresAddress = ($checkoutOrderType === 'delivery')
                    || ($checkoutOrderType === 'preorder' && $preorderReceiveType === 'delivery');
                if ($requiresAddress && ($deliveryAddress === '' || mb_strlen($deliveryAddress) < 8)) {
                    $errors[] = 'Укажите полный адрес доставки.';
                }

                if ($checkoutOrderType === 'preorder') {
                    if ($preorderDateRaw === '' || $preorderTimeRaw === '') {
                        $errors[] = 'Для предзаказа укажите дату и время.';
                    } else {
                        $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i', $preorderDateRaw . ' ' . $preorderTimeRaw);
                        if (!$dt) {
                            $errors[] = 'Неверный формат даты или времени предзаказа.';
                        } else {
                            $preorderScheduledAt = $dt->format('Y-m-d H:i:s');
                            $preorderScheduledUi = $dt->format('d.m.Y H:i');
                            if ($dt->getTimestamp() < (time() + 300)) {
                                $errors[] = 'Время предзаказа должно быть минимум через 5 минут.';
                            }
                        }
                    }
                }
            }

            // Поля лояльности с формы
            $loyaltyPhoneRaw        = trim($_POST['loyalty_phone'] ?? '');
            $loyaltyPhoneNormalized = null;
            $loyaltyPointsAccrued   = 0;
            $loyaltyPointsSpent     = 0;
            $loyaltyBalanceAfter    = 0;
            $orderGuestId           = $guestSessionUser ? (int)($guestSessionUser['id'] ?? 0) : 0;
            $orderGuestCardId       = 0;
            $redeemRequestedPoints  = max(0, (int)($_POST['loyalty_points_spend'] ?? 0));
            $promoCodeSubmitted     = trim((string)($_POST['promo_code'] ?? ''));
            $promoPreview           = null;
            $promoResolvedGuest     = [];
            $promoAppliedId         = 0;
            $promoAppliedCode       = '';
            $promoDiscountApplied   = 0.0;

            if ($loyaltyEnabled && $loyaltyPhoneRaw === '' && $guestSessionUser && !empty($guestSessionUser['phone'])) {
                $loyaltyPhoneRaw = (string)$guestSessionUser['phone'];
            }
            if ($isDeliveryMode && $deliveryPhoneRaw !== '') {
                // Non-table phone is the canonical contact for this flow.
                $loyaltyPhoneRaw = $deliveryPhoneRaw;
            }

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

            if ($redeemRequestedPoints > 0) {
                if (!$loyaltyEnabled) {
                    $errors[] = 'Списание бонусов сейчас недоступно.';
                } elseif (!$guestSessionUser || empty($guestSessionUser['id'])) {
                    $errors[] = 'Чтобы списать бонусы, сначала войдите по SMS.';
                }
            }

            if ($promoCodeSubmitted !== '') {
                if (!function_exists('promo_order_apply_preview')) {
                    $errors[] = 'Промокоды сейчас недоступны.';
                } else {
                    if (function_exists('guest_history_resolve_phone')) {
                        $promoResolvedGuest = guest_history_resolve_phone($pdo, (int)$currentRestaurant['id'], [
                            'guest_profile_id' => (int)($_POST['guest_profile_id'] ?? 0),
                            'phone' => (string)($loyaltyPhoneNormalized ?: $deliveryPhoneNormalized ?: ($guestSessionUser['phone'] ?? '')),
                            'phone_normalized' => (string)($loyaltyPhoneNormalized ?: $deliveryPhoneNormalized ?: ''),
                            'guest_phone' => (string)($guestSessionUser['phone'] ?? ''),
                            'customer_phone' => (string)($deliveryPhoneNormalized ?: ''),
                            'delivery_phone' => (string)($deliveryPhoneNormalized ?: ''),
                            'loyalty_phone' => (string)($loyaltyPhoneNormalized ?: ''),
                        ]) ?: [];
                    }
                }
            }

            if (!$errors) {
                $checkoutFingerprint = hash('sha256', json_encode([
                    'rid' => (int)$currentRestaurant['id'],
                    'tid' => (int)$tableId,
                    'mode' => $isDeliveryMode ? 'remote' : 'table',
                    'order_type' => $checkoutOrderType,
                    'cart' => $cart,
                    'payment_type' => (string)$paymentType,
                    'loyalty_points_spend' => (int)$redeemRequestedPoints,
                    'delivery_full_name' => $isDeliveryMode ? $deliveryFullName : '',
                    'delivery_phone' => $isDeliveryMode ? (string)$deliveryPhoneNormalized : '',
                    'delivery_address' => $isDeliveryMode ? $deliveryAddress : '',
                    'preorder_date' => $isDeliveryMode ? $preorderDateRaw : '',
                    'preorder_time' => $isDeliveryMode ? $preorderTimeRaw : '',
                    'preorder_receive_type' => $isDeliveryMode ? $preorderReceiveType : '',
                    'promo_code' => function_exists('promo_normalize_code') ? promo_normalize_code($promoCodeSubmitted) : strtoupper($promoCodeSubmitted),
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
                        header('Location: /order_track.php?order_id=' . (int)$idemOrderId);
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
                    $hasMenuProdStationCol = (function_exists('db_column_exists') && db_column_exists('menu_items', 'production_station'));
                    $hasMenuStationCol = (function_exists('db_column_exists') && db_column_exists('menu_items', 'station'));
                    $hasMenuKitchenStationCol = (function_exists('db_column_exists') && db_column_exists('menu_items', 'kitchen_station'));
                    $menuProdStationExpr = "LOWER(COALESCE("
                        . "NULLIF(TRIM(" . ($hasMenuProdStationCol ? "production_station" : "''") . "), ''),"
                        . "NULLIF(TRIM(" . ($hasMenuStationCol ? "station" : "''") . "), ''),"
                        . "NULLIF(TRIM(" . ($hasMenuKitchenStationCol ? "kitchen_station" : "''") . "), ''),"
                        . "'kitchen')) AS production_station";
                    $sql = "
                        SELECT id, name, price, {$menuProdStationExpr}
                        FROM menu_items
                        WHERE restaurant_id = ?
                          AND id IN ($placeholders)
                          AND available = 1
                          {$menuTempUnavailableCond}
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

                        // If some cart positions became unavailable, drop them and continue with valid items only.
                        // This avoids hard checkout failures after owner hides dishes.
                        $missingItemIds = [];
                        foreach (array_keys($cart) as $cartItemId) {
                            $cartItemId = (int)$cartItemId;
                            if (!isset($itemsById[$cartItemId])) {
                                $missingItemIds[] = $cartItemId;
                                unset($cart[$cartItemId]);
                            }
                        }
                        if (!empty($missingItemIds)) {
                            $errors[] = 'Некоторые блюда больше недоступны и были удалены из корзины. Проверьте заказ ещё раз.';
                        }

                        if ($total <= 0) {
                            $errors[] = 'Сумма заказа должна быть больше нуля.';
                        } elseif (!$errors) {
                            $grossTotal = (float)$total;
                            $payableTotal = $grossTotal;
                            $redeemAppliedPoints = 0;

                            if ($orderGuestId > 0 && function_exists('guest_loyalty_card_by_guest_rest')) {
                                $orderGuestCard = guest_loyalty_card_by_guest_rest($pdo, (int)$currentRestaurant['id'], $orderGuestId);
                                if ($orderGuestCard) {
                                    $orderGuestCardId = (int)($orderGuestCard['id'] ?? 0);
                                }
                            }

                            if ($loyaltyEnabled && $guestSessionUser && !empty($guestSessionUser['id']) && $redeemRequestedPoints > 0) {
                                $balanceNow = function_exists('guest_loyalty_balance_by_guest_rest')
                                    ? guest_loyalty_balance_by_guest_rest($pdo, (int)$currentRestaurant['id'], (int)$guestSessionUser['id'])
                                    : 0;
                                $redeemMaxForOrder = min(max(0, (int)$balanceNow), (int)floor($grossTotal * 0.20));
                                $redeemAppliedPoints = min(max(0, $redeemRequestedPoints), $redeemMaxForOrder);
                                $payableTotal = max(0.0, $grossTotal - $redeemAppliedPoints);
                            }

                            if ($promoCodeSubmitted !== '') {
                                $promoRes = promo_order_apply_preview($pdo, (int)$currentRestaurant['id'], $promoCodeSubmitted, [
                                    'order_total' => $payableTotal,
                                    'resolved_guest' => is_array($promoResolvedGuest) ? $promoResolvedGuest : [],
                                ]);
                                if (!is_array($promoRes) || empty($promoRes['ok'])) {
                                    $errors[] = (string)($promoRes['message'] ?? 'Промокод недоступен для этого заказа.');
                                } else {
                                    $promoPreview = is_array($promoRes['preview'] ?? null) ? $promoRes['preview'] : null;
                                    $promoPromotion = is_array($promoRes['promotion'] ?? null) ? $promoRes['promotion'] : null;
                                    $promoAppliedId = (int)($promoPromotion['id'] ?? 0);
                                    $promoAppliedCode = function_exists('promo_normalize_code')
                                        ? promo_normalize_code((string)($promoPromotion['code'] ?? $promoCodeSubmitted))
                                        : strtoupper(trim((string)$promoCodeSubmitted));
                                    $promoDiscountApplied = (float)($promoPreview['discount_amount'] ?? 0.0);
                                    $payableTotal = (float)($promoPreview['final_total'] ?? $payableTotal);
                                }
                            }

                            if (!empty($errors)) {
                                throw new RuntimeException('promo_validation_failed');
                            }

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

                            $qrCheckoutOwnsTransaction = false;
                            try {
                                if (!$pdo->inTransaction()) {
                                    try {
                                        $pdo->beginTransaction();
                                        $qrCheckoutOwnsTransaction = true;
                                    } catch (PDOException $be) {
                                        if (stripos($be->getMessage(), 'active transaction') === false) {
                                            throw $be;
                                        }
                                    }
                                }

                                // Создаём заказ schema-safe: optional columns may be missing on legacy DB.
                                $hasOrdersPaymentTypeCol = (function_exists('db_column_exists') && db_column_exists('orders', 'payment_type'));
                                $hasOrdersTotalAmountCol = (function_exists('db_column_exists') && db_column_exists('orders', 'total_amount'));
                                $hasOrdersOrderTypeCol = (function_exists('db_column_exists') && db_column_exists('orders', 'order_type'));
                                $hasOrdersCourierStatusCol = (function_exists('db_column_exists') && db_column_exists('orders', 'courier_status'));
                                $hasOrdersPromoIdCol = (function_exists('db_column_exists') && db_column_exists('orders', 'promo_id'));
                                $hasOrdersPromoCodeCol = (function_exists('db_column_exists') && db_column_exists('orders', 'promo_code'));
                                $hasOrdersPromoDiscountCol = (function_exists('db_column_exists') && db_column_exists('orders', 'promo_discount'));

                                $orderCols = [
                                    'restaurant_id',
                                    'table_id',
                                    'order_status',
                                    'payment_status',
                                    'total_price',
                                ];
                                $orderVals = [
                                    ':rest',
                                    ':table_id',
                                    ':order_status',
                                    ':payment_status',
                                    ':total_price',
                                ];
                                $orderParams = [
                                    ':rest' => (int)$currentRestaurant['id'],
                                    ':table_id' => (int)$tableId,
                                    ':order_status' => 'new',
                                    ':payment_status' => $paymentStatus,
                                    ':total_price' => $payableTotal,
                                ];

                                if ($hasOrdersPaymentTypeCol) {
                                    $orderCols[] = 'payment_type';
                                    $orderVals[] = ':payment_type';
                                    $orderParams[':payment_type'] = $paymentTypeVal;
                                }
                                if ($hasOrdersTotalAmountCol) {
                                    $orderCols[] = 'total_amount';
                                    $orderVals[] = ':total_amount';
                                    $orderParams[':total_amount'] = $payableTotal;
                                }
                                if ($hasOrdersOrderTypeCol) {
                                    $orderCols[] = 'order_type';
                                    $orderVals[] = ':order_type';
                                    $orderParams[':order_type'] = $checkoutOrderType;
                                }
                                if ($hasOrdersPromoIdCol) {
                                    $orderCols[] = 'promo_id';
                                    $orderVals[] = ':promo_id';
                                    $orderParams[':promo_id'] = $promoAppliedId > 0 ? $promoAppliedId : null;
                                }
                                if ($hasOrdersPromoCodeCol) {
                                    $orderCols[] = 'promo_code';
                                    $orderVals[] = ':promo_code';
                                    $orderParams[':promo_code'] = $promoAppliedCode !== '' ? $promoAppliedCode : null;
                                }
                                if ($hasOrdersPromoDiscountCol) {
                                    $orderCols[] = 'promo_discount';
                                    $orderVals[] = ':promo_discount';
                                    $orderParams[':promo_discount'] = max(0.0, (float)$promoDiscountApplied);
                                }
                                if ($hasOrdersCourierStatusCol && $checkoutOrderType === 'delivery') {
                                    $orderCols[] = 'courier_status';
                                    $orderVals[] = ':courier_status';
                                    $orderParams[':courier_status'] = 'waiting_courier';
                                }
                                if ($hasOrdersFlowIdCol && $flowId !== null) {
                                    $orderCols[] = 'flow_id';
                                    $orderVals[] = ':flow_id';
                                    $orderParams[':flow_id'] = (string)$flowId;
                                }

                                $insertOrderSql = "INSERT INTO orders (" . implode(', ', $orderCols) . ")
                                                   VALUES (" . implode(', ', $orderVals) . ")";
                                $stmt = $pdo->prepare($insertOrderSql);
                                $stmt->execute($orderParams);
                                $orderId = (int)$pdo->lastInsertId();

                                // Позиции заказа (legacy-safe + production_station routing)
                                $hasOrderItemProdStationCol = (function_exists('db_column_exists') && db_column_exists('order_items', 'production_station'));
                                $orderItemCols = [
                                    'order_id',
                                    'menu_item_id',
                                    'item_name',
                                    'qty',
                                    'quantity',
                                    'price',
                                ];
                                $orderItemVals = [
                                    ':order_id',
                                    ':menu_item_id',
                                    ':item_name',
                                    ':qty',
                                    ':quantity',
                                    ':price',
                                ];
                                if ($hasOrderItemProdStationCol) {
                                    $orderItemCols[] = 'production_station';
                                    $orderItemVals[] = ':production_station';
                                }
                                $stmt = $pdo->prepare("
                                    INSERT INTO order_items (
                                        " . implode(",\n                                        ", $orderItemCols) . "
                                    ) VALUES (
                                        " . implode(",\n                                        ", $orderItemVals) . "
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

                                    $itemStationRaw = strtolower(trim((string)($itemsById[$itemId]['production_station'] ?? 'kitchen')));
                                    $itemStation = in_array($itemStationRaw, ['kitchen', 'bar', 'cold', 'dessert', 'hookah', 'grill', 'pizza', 'sushi'], true)
                                        ? $itemStationRaw
                                        : (($itemStationRaw === 'hot') ? 'kitchen' : 'kitchen');

                                    $itemParams = [
                                        'order_id'     => $orderId,
                                        'menu_item_id' => $itemId,
                                        'item_name'    => $itemName,
                                        'qty'          => $qty,
                                        'quantity'     => $qty,
                                        'price'        => $itemPrice,
                                    ];
                                    if ($hasOrderItemProdStationCol) {
                                        $itemParams['production_station'] = $itemStation;
                                    }

                                    $stmt->execute($itemParams);
                                }

                                if ($redeemAppliedPoints > 0 && function_exists('guest_loyalty_spend_points')) {
                                    $spend = guest_loyalty_spend_points(
                                        $pdo,
                                        (int)$currentRestaurant['id'],
                                        (int)$guestSessionUser['id'],
                                        $redeemAppliedPoints,
                                        null,
                                        $orderId,
                                        'Списание при QR-заказе'
                                    );
                                    if (!is_array($spend) || empty($spend['ok'])) {
                                        throw new RuntimeException('loyalty_spend_failed');
                                    }
                                    $loyaltyPointsSpent = $redeemAppliedPoints;
                                    $loyaltyBalanceAfter = max(0, (int)($spend['balance'] ?? 0));
                                }

                                // ЛОЯЛЬНОСТЬ: на checkout только сохраняем номер телефона гостя.
                                // Реальное начисление произойдёт позже, когда заказ станет оплаченной loyalty-eligible покупкой.
                                $hasOrderGuestIdCol = function_exists('db_column_exists') && db_column_exists('orders', 'guest_id');
                                $hasOrderGuestCardIdCol = function_exists('db_column_exists') && db_column_exists('orders', 'guest_card_id');
                                $hasOrderLoyaltyPhoneCol = function_exists('db_column_exists') && db_column_exists('orders', 'loyalty_phone');
                                $hasOrderLoyaltyPointsAccruedCol = function_exists('db_column_exists') && db_column_exists('orders', 'loyalty_points_accrued');
                                $hasOrderLoyaltyPointsSpentCol = function_exists('db_column_exists') && db_column_exists('orders', 'loyalty_points_spent');
                                $hasOrderLoyaltyPointsBalanceAfterCol = function_exists('db_column_exists') && db_column_exists('orders', 'loyalty_points_balance_after');
                                if ($loyaltyEnabled || $orderGuestId > 0 || $orderGuestCardId > 0) {
                                    try {
                                        $set = [];
                                        $updParams = [':id' => $orderId];
                                        if ($hasOrderLoyaltyPointsAccruedCol) {
                                            $set[] = 'loyalty_points_accrued = :accr';
                                            $updParams[':accr'] = $loyaltyPointsAccrued;
                                        }
                                        if ($hasOrderLoyaltyPointsSpentCol) {
                                            $set[] = 'loyalty_points_spent = :spent';
                                            $updParams[':spent'] = $loyaltyPointsSpent;
                                        }
                                        if ($hasOrderLoyaltyPointsBalanceAfterCol) {
                                            $set[] = 'loyalty_points_balance_after = :bal';
                                            $updParams[':bal'] = $loyaltyBalanceAfter;
                                        }
                                        if ($hasOrderLoyaltyPhoneCol) {
                                            $set[] = 'loyalty_phone = :phone';
                                            $updParams[':phone'] = $loyaltyPhoneNormalized;
                                        }
                                        if ($hasOrderGuestIdCol && $orderGuestId > 0) {
                                            $set[] = 'guest_id = :guest_id';
                                            $updParams[':guest_id'] = $orderGuestId;
                                        }
                                        if ($hasOrderGuestCardIdCol && $orderGuestCardId > 0) {
                                            $set[] = 'guest_card_id = :guest_card_id';
                                            $updParams[':guest_card_id'] = $orderGuestCardId;
                                        }
                                        if ($set !== []) {
                                            $upd = $pdo->prepare("
                                                UPDATE orders
                                                SET " . implode(",\n                                                ", $set) . "
                                                WHERE id = :id
                                            ");
                                            $upd->execute($updParams);
                                        }
                                    } catch (Throwable $eLoyOrd) {
                                        if ($loyaltyPointsSpent > 0) {
                                            throw $eLoyOrd;
                                        }
                                        if (function_exists('error_log')) {
                                            error_log('QR_CHECKOUT_LOYALTY_ORDER_UPDATE rid=' . (int)$currentRestaurant['id'] . ' oid=' . (int)$orderId . ' ' . $eLoyOrd->getMessage());
                                        }
                                    }
                                }

                                if ($promoAppliedId > 0 && function_exists('promo_register_usage')) {
                                    try {
                                        promo_register_usage(
                                            $pdo,
                                            (int)$currentRestaurant['id'],
                                            [
                                                'id' => $promoAppliedId,
                                            ],
                                            (int)$orderId,
                                            is_array($promoResolvedGuest) ? $promoResolvedGuest : []
                                        );
                                    } catch (Throwable $ePromoUse) {
                                        if (function_exists('error_log')) {
                                            error_log('QR_CHECKOUT_PROMO_USAGE_FAIL rid=' . (int)$currentRestaurant['id'] . ' oid=' . (int)$orderId . ' ' . $ePromoUse->getMessage());
                                        }
                                    }
                                }
                                try {
                                    // CRM: сохраняем контакт на заказе в рамках той же транзакции.
                                    $crmPhoneRaw = trim($_POST['crm_phone'] ?? '');
                                    if ($isDeliveryMode && $deliveryPhoneNormalized) {
                                        $crmPhoneRaw = (string)$deliveryPhoneNormalized;
                                    }
                                    if ($crmPhoneRaw === '' && $guestSessionUser && !empty($guestSessionUser['phone'])) {
                                        $crmPhoneRaw = (string)$guestSessionUser['phone'];
                                    }
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

                                if ($isDeliveryMode) {
                                    try {
                                        $isDeliveryOrder = ($checkoutOrderType === 'delivery');
                                        $isPickupOrder = ($checkoutOrderType === 'pickup');
                                        $isPreorderOrder = ($checkoutOrderType === 'preorder');
                                        $preorderIsDelivery = ($isPreorderOrder && $preorderReceiveType === 'delivery');

                                        $noteParts = [];
                                        $noteParts[] = 'Тип получения: ' . qr_checkout_order_type_label($checkoutOrderType);
                                        if ($isPreorderOrder) {
                                            $noteParts[] = 'Предзаказ на: ' . ($preorderScheduledUi !== '' ? $preorderScheduledUi : $preorderDateRaw . ' ' . $preorderTimeRaw);
                                            $noteParts[] = 'Формат предзаказа: ' . ($preorderReceiveType === 'delivery' ? 'Доставка' : 'Самовывоз');
                                        }
                                        if ($deliveryFullName !== '') {
                                            $noteParts[] = 'ФИО: ' . $deliveryFullName;
                                        }
                                        if ($deliveryPhoneNormalized) {
                                            $noteParts[] = 'Тел: ' . (string)$deliveryPhoneNormalized;
                                        }
                                        if (($isDeliveryOrder || $preorderIsDelivery) && $deliveryAddress !== '') {
                                            $noteParts[] = 'Адрес: ' . $deliveryAddress;
                                        }
                                        $noteText = implode("\n", $noteParts);

                                        $deliveryCols = [];
                                        $deliveryParams = [':oid' => (int)$orderId];
                                        if (function_exists('db_column_exists') && db_column_exists('orders', 'delivery_full_name') && $deliveryFullName !== '') {
                                            $deliveryCols[] = 'delivery_full_name = :dfn';
                                            $deliveryParams[':dfn'] = $deliveryFullName;
                                        }
                                        if (function_exists('db_column_exists') && db_column_exists('orders', 'delivery_phone') && $deliveryPhoneNormalized) {
                                            $deliveryCols[] = 'delivery_phone = :dph';
                                            $deliveryParams[':dph'] = (string)$deliveryPhoneNormalized;
                                        }
                                        if (function_exists('db_column_exists') && db_column_exists('orders', 'delivery_address')) {
                                            $deliveryCols[] = 'delivery_address = :daddr';
                                            $deliveryParams[':daddr'] = ($isDeliveryOrder || $preorderIsDelivery) ? $deliveryAddress : null;
                                        }
                                        if (function_exists('db_column_exists') && db_column_exists('orders', 'customer_name') && $deliveryFullName !== '') {
                                            $deliveryCols[] = 'customer_name = :cust_name';
                                            $deliveryParams[':cust_name'] = $deliveryFullName;
                                        }
                                        if (function_exists('db_column_exists') && db_column_exists('orders', 'customer_phone') && $deliveryPhoneNormalized) {
                                            $deliveryCols[] = 'customer_phone = :cust_phone';
                                            $deliveryParams[':cust_phone'] = (string)$deliveryPhoneNormalized;
                                        }
                                        if (function_exists('db_column_exists') && db_column_exists('orders', 'scheduled_for')) {
                                            $deliveryCols[] = 'scheduled_for = :scheduled_for';
                                            $deliveryParams[':scheduled_for'] = $isPreorderOrder ? $preorderScheduledAt : null;
                                        }
                                        if (function_exists('db_column_exists') && db_column_exists('orders', 'preorder_receive_type')) {
                                            $deliveryCols[] = 'preorder_receive_type = :preorder_receive_type';
                                            $deliveryParams[':preorder_receive_type'] = $isPreorderOrder ? $preorderReceiveType : null;
                                        }
                                        if ($deliveryCols !== []) {
                                            $updDel = $pdo->prepare('UPDATE orders SET ' . implode(', ', $deliveryCols) . ' WHERE id = :oid LIMIT 1');
                                            $updDel->execute($deliveryParams);
                                        }

                                        $noteCol = null;
                                        if (function_exists('db_column_exists')) {
                                            if (db_column_exists('orders', 'note')) {
                                                $noteCol = 'note';
                                            } elseif (db_column_exists('orders', 'comment')) {
                                                $noteCol = 'comment';
                                            } elseif (db_column_exists('orders', 'customer_note')) {
                                                $noteCol = 'customer_note';
                                            }
                                        }
                                        if ($noteCol !== null && $noteText !== '') {
                                            $updN = $pdo->prepare("UPDATE orders SET {$noteCol} = :n WHERE id = :oid LIMIT 1");
                                            $updN->execute([':n' => $noteText, ':oid' => (int)$orderId]);
                                        }
                                    } catch (Throwable $eDel) {
                                        if (function_exists('error_log')) {
                                            error_log('QR_CHECKOUT_FULFILLMENT_META_FAIL rid=' . (int)$currentRestaurant['id'] . ' oid=' . (int)$orderId . ' ' . $eDel->getMessage());
                                        }
                                    }
                                }

                                if ($qrCheckoutOwnsTransaction) {
                                    try {
                                        if ($pdo->inTransaction()) {
                                            $pdo->commit();
                                        }
                                    } catch (Throwable $ec) {
                                        if (function_exists('error_log')) {
                                            error_log('QR_CHECKOUT_COMMIT_FAIL rid=' . (int)$currentRestaurant['id'] . ' oid=' . (int)$orderId . ' ' . $ec->getMessage());
                                        }
                                        throw $ec;
                                    }
                                }

                                // Event logging only: must never affect checkout flow.
                                if (function_exists('app_event')) {
                                    app_event('order_created', [
                                        'restaurant_id' => (int)$currentRestaurant['id'],
                                        'order_id' => (int)$orderId,
                                        'table_id' => (int)$tableId,
                                        'order_type' => (string)$checkoutOrderType,
                                        'total' => (float)$payableTotal,
                                        'payment_status' => (string)$paymentStatus,
                                        'payment_type' => (string)$paymentTypeVal,
                                        'loyalty_points_spent' => (int)$loyaltyPointsSpent,
                                        'promo_id' => (int)$promoAppliedId,
                                        'promo_code' => (string)$promoAppliedCode,
                                        'promo_discount' => (float)$promoDiscountApplied,
                                    ]);
                                }
                            } catch (Throwable $e) {
                                if (!empty($qrCheckoutOwnsTransaction)) {
                                    try {
                                        if ($pdo->inTransaction()) {
                                            $pdo->rollBack();
                                        }
                                    } catch (Throwable $er) {
                                        if (function_exists('error_log')) {
                                            error_log('QR_CHECKOUT_ROLLBACK_FAIL rid=' . (int)$currentRestaurant['id'] . ' table=' . (int)$tableId . ' ' . $er->getMessage());
                                        }
                                    }
                                }
                                $orderItemsOk = false;
                                $errMsg = 'Не удалось оформить заказ. Попробуйте снова.';
                                if ($e instanceof RuntimeException && $e->getMessage() === 'loyalty_spend_failed') {
                                    $errMsg = 'Не удалось списать бонусы. Попробуйте снова.';
                                } elseif ($e instanceof RuntimeException && $e->getMessage() === 'promo_validation_failed') {
                                    if (!empty($errors)) {
                                        $errMsg = (string)$errors[0];
                                    } else {
                                        $errMsg = 'Промокод не удалось применить. Проверьте условия.';
                                    }
                                } elseif ($e instanceof PDOException && strpos((string)$e->getMessage(), 'SQLSTATE[42S22]') !== false) {
                                    $errMsg = 'Система обновляется. Пожалуйста, повторите заказ через минуту.';
                                }
                                $errors[] = $errMsg;
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
                                if (!is_demo_mode() && file_exists(__DIR__ . '/../app/crm_repo.php')) {
                                    require_once __DIR__ . '/../app/crm_repo.php';
                                    $visitPhone = null;
                                    if (!empty($loyaltyPhoneNormalized)) {
                                        $visitPhone = $loyaltyPhoneNormalized;
                                    } elseif ($guestSessionUser && !empty($guestSessionUser['phone'])) {
                                        $visitPhone = function_exists('crm_normalize_phone')
                                            ? (crm_normalize_phone((string)$guestSessionUser['phone']) ?? (string)$guestSessionUser['phone'])
                                            : (string)$guestSessionUser['phone'];
                                    }
                                    if ($visitPhone && function_exists('crm_touch_visit_after_qr_order')) {
                                        $crmConsentPost = isset($_POST['crm_consent']) && $_POST['crm_consent'] === '1';
                                        try {
                                            crm_touch_visit_after_qr_order(
                                                (int)$currentRestaurant['id'],
                                                (int)$orderId,
                                                $visitPhone,
                                                (float)$payableTotal,
                                                (int)$tableId,
                                                $crmConsentPost
                                            );
                                        } catch (Throwable $eCrmTouch) {
                                            if (function_exists('error_log')) {
                                                error_log('qr CRM previsit touch ' . $eCrmTouch->getMessage());
                                            }
                                        }
                                    }
                                }
                                if (!is_demo_mode() && function_exists('crm_guest_profile_touch')) {
                                    try {
                                        crm_guest_profile_touch($pdo, (int)$currentRestaurant['id'], [
                                            'order_id' => (int)$orderId,
                                            'order_type' => (string)$checkoutOrderType,
                                            'total_price' => (float)$payableTotal,
                                            'created_at' => date('Y-m-d H:i:s'),
                                            'customer_name' => $isDeliveryMode ? (string)$deliveryFullName : '',
                                            'delivery_full_name' => (string)$deliveryFullName,
                                            'customer_phone' => $deliveryPhoneNormalized ? (string)$deliveryPhoneNormalized : '',
                                            'delivery_phone' => $deliveryPhoneNormalized ? (string)$deliveryPhoneNormalized : '',
                                            'guest_phone' => (string)($guestSessionUser['phone'] ?? ''),
                                            'guest_name' => (string)($guestSessionUser['name'] ?? ''),
                                            'loyalty_phone' => (string)($loyaltyPhoneNormalized ?? ''),
                                        ]);
                                    } catch (Throwable $eCrmProfile) {
                                        if (function_exists('error_log')) {
                                            error_log('CRM_GUEST_PROFILE_TOUCH_FAIL order_id=' . (int)$orderId . ' ' . $eCrmProfile->getMessage());
                                        }
                                    }
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

                                header('Location: /order_track.php?order_id=' . (int)$orderId);
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

// Убрать из сессии позиции, которых больше нет в меню или которые скрыты (available=0/temporary unavailable).
if (!is_demo_mode() && $cart && isset($currentRestaurant['id'])) {
    $rawIds = [];
    foreach ($cart as $cid => $q) {
        if ((int)$q > 0) {
            $rawIds[] = (int)$cid;
        }
    }
    $rawIds = array_values(array_unique(array_filter($rawIds)));
    if ($rawIds) {
        $placeholders = implode(',', array_fill(0, count($rawIds), '?'));
        $chk = $pdo->prepare(
            "SELECT id FROM menu_items
             WHERE restaurant_id = ?
               AND available = 1
               {$menuTempUnavailableCond}
               AND id IN ($placeholders)"
        );
        $chk->execute(array_merge([(int)$currentRestaurant['id']], $rawIds));
        $allowed = [];
        while ($row = $chk->fetch(PDO::FETCH_ASSOC)) {
            $allowed[(int)$row['id']] = true;
        }
        foreach (array_keys($cart) as $cid) {
            $cid = (int)$cid;
            if (!isset($allowed[$cid])) {
                unset($cart[$cid]);
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
                      AND available = 1
                      {$menuTempUnavailableCond}
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

$cartRedeemRequested = isset($_POST['loyalty_points_spend']) ? max(0, (int)$_POST['loyalty_points_spend']) : 0;
$cartRedeemBalance = ($loyaltyEnabled && $guestSessionUser) ? max(0, (int)$guestSessionBalance) : 0;
$cartRedeemMax = ($loyaltyEnabled && $guestSessionUser && $cartTotalSum > 0)
    ? min($cartRedeemBalance, (int)floor($cartTotalSum * 0.20))
    : 0;
$cartRedeemSelected = min($cartRedeemRequested, $cartRedeemMax);
$cartPayableSum = max(0.0, $cartTotalSum - $cartRedeemSelected);
$promoCodeField = isset($_POST['promo_code']) ? trim((string)$_POST['promo_code']) : '';
$promoCodeNormalizedField = function_exists('promo_normalize_code')
    ? promo_normalize_code($promoCodeField)
    : strtoupper($promoCodeField);
$cartPromoPreview = null;
$cartPromoPromotion = null;
$cartPromoMessage = '';
$cartPromoOk = false;
$cartFinalPayableSum = $cartPayableSum;
if ($promoCodeField !== '' && function_exists('promo_order_apply_preview')) {
    $cartPromoResolvedGuest = [];
    if (function_exists('guest_history_resolve_phone')) {
        $cartPromoResolvedGuest = guest_history_resolve_phone($pdo, (int)$currentRestaurant['id'], [
            'guest_profile_id' => (int)($_POST['guest_profile_id'] ?? 0),
            'phone' => (string)($loyaltyPhoneField ?: $deliveryPhoneField ?: ($guestSessionUser['phone'] ?? '')),
            'phone_normalized' => (string)($loyaltyPhoneField ?: $deliveryPhoneField ?: ''),
            'guest_phone' => (string)($guestSessionUser['phone'] ?? ''),
            'customer_phone' => (string)$deliveryPhoneField,
            'delivery_phone' => (string)$deliveryPhoneField,
            'loyalty_phone' => (string)$loyaltyPhoneField,
        ]) ?: [];
    }
    try {
        $cartPromoResult = promo_order_apply_preview($pdo, (int)$currentRestaurant['id'], $promoCodeField, [
            'order_total' => $cartPayableSum,
            'resolved_guest' => is_array($cartPromoResolvedGuest) ? $cartPromoResolvedGuest : [],
        ]);
        if (is_array($cartPromoResult) && !empty($cartPromoResult['ok'])) {
            $cartPromoOk = true;
            $cartPromoPreview = is_array($cartPromoResult['preview'] ?? null) ? $cartPromoResult['preview'] : null;
            $cartPromoPromotion = is_array($cartPromoResult['promotion'] ?? null) ? $cartPromoResult['promotion'] : null;
            $cartPromoMessage = trim((string)($cartPromoResult['message'] ?? 'Промокод применим.'));
            $cartFinalPayableSum = is_array($cartPromoPreview)
                ? (float)($cartPromoPreview['final_total'] ?? $cartPayableSum)
                : $cartPayableSum;
        } else {
            $cartPromoMessage = trim((string)($cartPromoResult['message'] ?? 'Промокод недоступен.'));
        }
    } catch (Throwable $ePromoPreview) {
        $cartPromoMessage = 'Не удалось проверить промокод.';
        if (function_exists('error_log')) {
            error_log('QR_PROMO_PREVIEW_FAIL rid=' . (int)$currentRestaurant['id'] . ' ' . $ePromoPreview->getMessage());
        }
    }
}
$cartStateSignatureData = $cart;
if (is_array($cartStateSignatureData)) {
    ksort($cartStateSignatureData);
}
$cartStateSignature = hash('sha256', json_encode($cartStateSignatureData, JSON_UNESCAPED_UNICODE));

// Smart upsell split: menu layer (one-tap after add) + cart layer (block in cart).
$menuUpsellOn = upsell_guest_mode_enabled($currentRestaurant, $upsellEnabled, 'menu');
$menuUpsellMaxItems = upsell_guest_mode_max_items($currentRestaurant, 'menu');
$menuUpsellFlags = upsell_guest_mode_flags($currentRestaurant, 'menu');

$cartUpsellOn = upsell_guest_mode_enabled($currentRestaurant, $upsellEnabled, 'cart');
$cartUpsellMaxItems = upsell_guest_mode_max_items($currentRestaurant, 'cart');
$cartUpsellFlags = upsell_guest_mode_flags($currentRestaurant, 'cart');

$menuUpsellSuggestions = [];
$cartUpsellSuggestions = [];
if ($cartTotalQty > 0) {
    $triggerUpsell = $lastAddedItemId > 0 ? $lastAddedItemId : null;
    $upsellSessionViews = (int)($_SESSION['upsell_views'] ?? 0);

    if ($menuUpsellOn) {
        $menuUpsellOpts = [
            'limit' => $menuUpsellMaxItems,
            'trigger_item_id' => $triggerUpsell,
            'session_views' => $upsellSessionViews,
            'order_upsell_count' => 0,
            'allow_manual' => (bool)($menuUpsellFlags['allow_manual'] ?? true),
            'allow_contextual' => (bool)($menuUpsellFlags['allow_contextual'] ?? true),
            'allow_popular_fallback' => (bool)($menuUpsellFlags['allow_popular_fallback'] ?? true),
            'allow_combo' => (bool)($menuUpsellFlags['allow_combo'] ?? true),
            'combo_limit' => (int)($menuUpsellFlags['combo_limit'] ?? $menuUpsellMaxItems),
        ];
        if (function_exists('upsell_get_suggestions')) {
            $menuUpsellSuggestions = upsell_get_suggestions((int)$currentRestaurant['id'], $cart, $menuUpsellOpts);
        } elseif (function_exists('get_smart_upsell')) {
            $menuUpsellSuggestions = get_smart_upsell(
                (int)$currentRestaurant['id'],
                $cart,
                $menuUpsellMaxItems,
                $triggerUpsell,
                $upsellSessionViews,
                0,
                [
                    'allow_manual' => (bool)($menuUpsellFlags['allow_manual'] ?? true),
                    'allow_contextual' => (bool)($menuUpsellFlags['allow_contextual'] ?? true),
                    'allow_popular_fallback' => (bool)($menuUpsellFlags['allow_popular_fallback'] ?? true),
                    'allow_combo' => (bool)($menuUpsellFlags['allow_combo'] ?? true),
                    'combo_limit' => (int)($menuUpsellFlags['combo_limit'] ?? $menuUpsellMaxItems),
                ]
            );
        }
    }

    if ($cartUpsellOn) {
        $cartUpsellOpts = [
            'limit' => $cartUpsellMaxItems,
            'trigger_item_id' => null,
            'session_views' => $upsellSessionViews,
            'order_upsell_count' => 0,
            'allow_manual' => (bool)($cartUpsellFlags['allow_manual'] ?? true),
            'allow_contextual' => (bool)($cartUpsellFlags['allow_contextual'] ?? true),
            'allow_popular_fallback' => (bool)($cartUpsellFlags['allow_popular_fallback'] ?? true),
            'allow_combo' => (bool)($cartUpsellFlags['allow_combo'] ?? true),
            'combo_limit' => (int)($cartUpsellFlags['combo_limit'] ?? $cartUpsellMaxItems),
        ];
        if (function_exists('upsell_get_suggestions')) {
            $cartUpsellSuggestions = upsell_get_suggestions((int)$currentRestaurant['id'], $cart, $cartUpsellOpts);
        } elseif (function_exists('get_smart_upsell')) {
            $cartUpsellSuggestions = get_smart_upsell(
                (int)$currentRestaurant['id'],
                $cart,
                $cartUpsellMaxItems,
                null,
                $upsellSessionViews,
                0,
                [
                    'allow_manual' => (bool)($cartUpsellFlags['allow_manual'] ?? true),
                    'allow_contextual' => (bool)($cartUpsellFlags['allow_contextual'] ?? true),
                    'allow_popular_fallback' => (bool)($cartUpsellFlags['allow_popular_fallback'] ?? true),
                    'allow_combo' => (bool)($cartUpsellFlags['allow_combo'] ?? true),
                    'combo_limit' => (int)($cartUpsellFlags['combo_limit'] ?? $cartUpsellMaxItems),
                ]
            );
        }
    }
}

$menuComboOn = $menuUpsellOn && (bool)($menuUpsellFlags['allow_combo'] ?? true);
$cartComboOn = $cartUpsellOn && (bool)($cartUpsellFlags['allow_combo'] ?? true);

$menuComboSuggestions = [];
$cartComboSuggestions = [];
foreach ($menuUpsellSuggestions as $s) {
    if (trim((string)($s['reason'] ?? '')) === 'Комбо к вашему заказу') {
        $menuComboSuggestions[] = $s;
    }
}
foreach ($cartUpsellSuggestions as $s) {
    if (trim((string)($s['reason'] ?? '')) === 'Комбо к вашему заказу') {
        $cartComboSuggestions[] = $s;
    }
}
$allComboSuggestions = array_values(array_merge($menuComboSuggestions, $cartComboSuggestions));

// Если это AJAX-запрос для корзины (?ajax=1)
if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success'               => true,
        'cartTotalQty'          => $cartTotalQty,
        'cartTotalSum'          => $cartTotalSum,
        'cartTotalSumFormatted' => number_format($cartTotalSum, 0, '.', ' '),
        'items'                 => $cartItems,
        'smart_upsells'         => $cartUpsellSuggestions, // legacy key for cart strip
        'menu_upsells'          => $menuUpsellSuggestions,
        'cart_upsells'          => $cartUpsellSuggestions,
        'combo_upsells'         => $allComboSuggestions,
        'menu_combo_upsells'    => $menuComboSuggestions,
        'cart_combo_upsells'    => $cartComboSuggestions,
        'upsell_guest_on'       => ($menuUpsellOn || $cartUpsellOn),
        'menu_upsell_guest_on'  => $menuUpsellOn,
        'cart_upsell_guest_on'  => $cartUpsellOn,
        'combo_guest_on'        => ($menuComboOn || $cartComboOn),
        'menu_combo_guest_on'   => $menuComboOn,
        'cart_combo_guest_on'   => $cartComboOn,
        'last_added_item_id'    => $lastAddedItemId,
    ]);
    exit;
}

if (!function_exists('qr_guest_order_status_meta')) {
    function qr_guest_order_status_meta(string $status): array
    {
        $s = trim(mb_strtolower($status ?: 'new'));
        if (in_array($s, ['new', 'created', 'accepted', 'confirmed', 'pending'], true)) {
            return ['Заказ принят', 'border-sky-400/35 bg-sky-500/10 text-sky-200'];
        }
        if (in_array($s, ['in_progress', 'progress', 'cooking', 'preparing', 'kitchen', 'processing'], true)) {
            return ['Готовится', 'border-indigo-400/35 bg-indigo-500/10 text-indigo-200'];
        }
        if (in_array($s, ['done', 'ready', 'completed', 'served', 'finish', 'finished'], true)) {
            return ['Готов', 'border-emerald-400/35 bg-emerald-500/10 text-emerald-200'];
        }
        if ($s === 'delivered') {
            return ['Заказ получен', 'border-slate-500/35 bg-slate-500/10 text-slate-200'];
        }
        if (in_array($s, ['canceled', 'cancelled', 'rejected', 'declined'], true)) {
            return ['Отменён', 'border-rose-400/35 bg-rose-500/10 text-rose-200'];
        }
        return ['Заказ принят', 'border-sky-400/35 bg-sky-500/10 text-sky-200'];
    }
}

$activeOrderCard = null;
if (!is_demo_mode() && !empty($currentRestaurant['id']) && $tableId > 0) {
    if (function_exists('db_table_exists') && db_table_exists('orders')) {
        order_expire_due_orders($pdo, (int)$currentRestaurant['id'], null, (int)$tableId);
    }

    $trackKey = 'last_order_' . (int)$currentRestaurant['id'] . '_' . (int)$tableId;
    $guestPhoneNormalized = '';
    if ($guestSessionUser && !empty($guestSessionUser['phone'])) {
        $guestPhoneRaw = (string)$guestSessionUser['phone'];
        if (function_exists('loyalty_normalize_phone')) {
            $guestPhoneNormalized = (string)(loyalty_normalize_phone($guestPhoneRaw) ?? '');
        }
        if ($guestPhoneNormalized === '') {
            $guestPhoneNormalized = preg_replace('/\D+/', '', $guestPhoneRaw) ?: '';
        }
    }

    $hasOrderGuestIdCol = function_exists('db_column_exists') && db_column_exists('orders', 'guest_id');
    $hasOrderLoyaltyPhoneCol = function_exists('db_column_exists') && db_column_exists('orders', 'loyalty_phone');

    $baseSql = "
        SELECT id, table_id, order_status, payment_status, created_at
        FROM orders
        WHERE restaurant_id = :rest
          AND table_id = :table
          AND LOWER(COALESCE(order_status, 'new')) NOT IN ('delivered', 'done', 'completed', 'served', 'finished', 'canceled', 'cancelled')
          AND LOWER(COALESCE(payment_status, 'unpaid')) <> 'canceled'
    ";

    $loadActiveOrder = static function (string $sql, array $params) use ($pdo): ?array {
        $stmt = $pdo->prepare($sql . ' ORDER BY id DESC LIMIT 1');
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    };

    $baseParams = [
        ':rest' => (int)$currentRestaurant['id'],
        ':table' => (int)$tableId,
    ];

    if (!empty($_SESSION[$trackKey]) && ctype_digit((string)$_SESSION[$trackKey])) {
        $activeOrderCard = $loadActiveOrder($baseSql . ' AND id = :order_id', $baseParams + [
            ':order_id' => (int)$_SESSION[$trackKey],
        ]);
        // Заказ завершён/отменён или сессия устарела — убираем ключ, чтобы сработали fallbacks (guest/phone) и не блокировался UI после кабинета.
        if ($activeOrderCard === null) {
            unset($_SESSION[$trackKey]);
        }
    }

    if ($activeOrderCard === null && $guestSessionUser && $hasOrderGuestIdCol) {
        $activeOrderCard = $loadActiveOrder($baseSql . ' AND guest_id = :guest_id', $baseParams + [
            ':guest_id' => (int)$guestSessionUser['id'],
        ]);
    }

    if ($activeOrderCard === null && $hasOrderLoyaltyPhoneCol && $guestPhoneNormalized !== '') {
        $activeOrderCard = $loadActiveOrder($baseSql . ' AND loyalty_phone = :loyalty_phone', $baseParams + [
            ':loyalty_phone' => $guestPhoneNormalized,
        ]);
    }

    // Fallback: allow opening the latest non-canceled order even if it is already completed.
    // This keeps "Открыть заказ" predictable after partial payment or quick status transitions.
    if ($activeOrderCard === null) {
        $fallbackSql = "
            SELECT id, table_id, order_status, payment_status, created_at
            FROM orders
            WHERE restaurant_id = :rest
              AND table_id = :table
              AND LOWER(COALESCE(order_status, 'new')) NOT IN ('canceled', 'cancelled')
              AND LOWER(COALESCE(payment_status, 'unpaid')) <> 'canceled'
        ";
        if (!empty($_SESSION[$trackKey]) && ctype_digit((string)$_SESSION[$trackKey])) {
            $activeOrderCard = $loadActiveOrder($fallbackSql . ' AND id = :order_id', $baseParams + [
                ':order_id' => (int)$_SESSION[$trackKey],
            ]);
        }
        if ($activeOrderCard === null && $guestSessionUser && $hasOrderGuestIdCol) {
            $activeOrderCard = $loadActiveOrder($fallbackSql . ' AND guest_id = :guest_id', $baseParams + [
                ':guest_id' => (int)$guestSessionUser['id'],
            ]);
        }
        if ($activeOrderCard === null && $hasOrderLoyaltyPhoneCol && $guestPhoneNormalized !== '') {
            $activeOrderCard = $loadActiveOrder($fallbackSql . ' AND loyalty_phone = :loyalty_phone', $baseParams + [
                ':loyalty_phone' => $guestPhoneNormalized,
            ]);
        }
    }

    if ($activeOrderCard) {
        [$activeOrderLabel, $activeOrderBadgeClass] = qr_guest_order_status_meta((string)($activeOrderCard['order_status'] ?? 'new'));
        $paymentStatusRaw = trim(mb_strtolower((string)($activeOrderCard['payment_status'] ?? '')));
        if (in_array($paymentStatusRaw, ['partial', 'partially_paid', 'part_paid', 'pending_capture'], true)) {
            $activeOrderLabel = 'Частично оплачен';
            $activeOrderBadgeClass = 'border-amber-400/35 bg-amber-500/10 text-amber-200';
        }
        $activeOrderCard['status_label'] = $activeOrderLabel;
        $activeOrderCard['status_class'] = $activeOrderBadgeClass;
        $activeOrderCard['track_url'] = '/order_track.php?order_id=' . (int)$activeOrderCard['id'];
        $activeOrderCard['is_delivery_session'] = $isDeliveryMode;
    }
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
          {$menuTempUnavailableCondMi}
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
$loyaltyPhoneField = isset($_POST['loyalty_phone']) ? (string)$_POST['loyalty_phone'] : (string)($guestSessionUser['phone'] ?? '');
$crmPhoneField = isset($_POST['crm_phone']) ? (string)$_POST['crm_phone'] : (string)($guestSessionUser['phone'] ?? '');
$qrView = (string)($_GET['view'] ?? 'menu');
if ($qrView !== 'cart') {
    $qrView = 'menu';
}

$checkoutOrderTypeField = qr_checkout_order_type_normalize((string)($_POST['order_type'] ?? ''), !$isDeliveryMode);
$preorderReceiveTypeField = strtolower(trim((string)($_POST['preorder_receive_type'] ?? 'pickup')));
if (!in_array($preorderReceiveTypeField, ['pickup', 'delivery'], true)) {
    $preorderReceiveTypeField = 'pickup';
}
$preorderDateField = isset($_POST['preorder_date']) ? (string)$_POST['preorder_date'] : '';
$preorderTimeField = isset($_POST['preorder_time']) ? (string)$_POST['preorder_time'] : '';
$checkoutOrderTypeLabel = qr_checkout_order_type_label($checkoutOrderTypeField);

$deliveryFullNameField = isset($_POST['delivery_full_name']) ? (string)$_POST['delivery_full_name'] : '';
$deliveryPhoneField = isset($_POST['delivery_phone']) ? (string)$_POST['delivery_phone'] : '';
$deliveryAddressField = isset($_POST['delivery_address']) ? (string)$_POST['delivery_address'] : '';
if ($isDeliveryMode) {
    if ($deliveryPhoneField === '' && $loyaltyPhoneField !== '') {
        $deliveryPhoneField = $loyaltyPhoneField;
    }
    if ($deliveryPhoneField === '' && $crmPhoneField !== '') {
        $deliveryPhoneField = $crmPhoneField;
    }
    if ($crmPhoneField === '' && $deliveryPhoneField !== '') {
        $crmPhoneField = $deliveryPhoneField;
    }
    if ($loyaltyPhoneField === '' && $deliveryPhoneField !== '') {
        $loyaltyPhoneField = $deliveryPhoneField;
    }
}
$checkoutTitle = 'Корзина и оформление';
$checkoutSubtitle = 'Заказ уйдёт на кухню и в зал для этого стола.';
$checkoutBackText = '← Назад в меню этого стола';
$cartStageCaption = null;
$cartStageHint = 'Проверьте заказ перед отправкой на кухню';
if ($isDeliveryMode) {
    $checkoutTitle = 'Оформление заказа';
    $checkoutSubtitle = 'Выберите формат получения и заполните контакты.';
    $checkoutBackText = '← Назад в меню';
    $cartStageCaption = 'Checkout · ' . (function_exists('mb_strtolower') ? mb_strtolower($checkoutOrderTypeLabel) : strtolower($checkoutOrderTypeLabel));
    $cartStageHint = 'Проверьте данные и отправьте заказ в ресторан';
}

$qrMenuLink = qr_public_build_url('/qr.php', $isDeliveryMode ? [] : ['table_id' => (int)$tableId]);
$qrCartLink = qr_public_build_url('/qr.php', $isDeliveryMode ? ['view' => 'cart'] : ['table_id' => (int)$tableId, 'view' => 'cart']);
$platformHomePath = '/index.html';
$platformMainDomain = strtolower((string)($config['app']['main_domain'] ?? ''));
$platformProtocol = (string)($config['app']['protocol'] ?? 'https');
$platformBackUrl = $platformMainDomain !== ''
    ? ($platformProtocol . '://' . $platformMainDomain . $platformHomePath)
    : $platformHomePath;

$menuCategoryCount = count($itemsByCategory);
$menuItemsCount = count($items);
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title><?= e($currentRestaurant['name']) ?> — <?= $isDeliveryMode ? 'доставка' : 'заказ к столу' ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?= brand_head_tags() ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="/assets/css/motion.css" rel="stylesheet">
    <style>
        :root {
            --qr-bg-top: rgba(30, 41, 59, 0.78);
            --qr-bg-bottom: rgba(2, 6, 23, 0.98);
            --qr-surface: rgba(15, 23, 42, 0.78);
            --qr-surface-strong: rgba(2, 6, 23, 0.92);
            --qr-stroke: rgba(148, 163, 184, 0.16);
            --qr-stroke-strong: rgba(148, 163, 184, 0.24);
            --qr-shadow: 0 24px 80px rgba(2, 6, 23, 0.5);
            --qr-glow: rgba(16, 185, 129, 0.18);
        }
        body {
            font-family: Manrope, system-ui, sans-serif;
            background:
                radial-gradient(circle at top left, rgba(251, 191, 36, 0.14), transparent 28%),
                radial-gradient(circle at top right, rgba(16, 185, 129, 0.14), transparent 34%),
                linear-gradient(180deg, var(--qr-bg-top) 0%, var(--qr-bg-bottom) 36%, #020617 100%);
        }
        body::before {
            content: '';
            position: fixed;
            inset: 0;
            pointer-events: none;
            background:
                linear-gradient(120deg, rgba(255,255,255,0.04), transparent 24%),
                radial-gradient(circle at 50% -10%, rgba(255,255,255,0.06), transparent 38%);
            opacity: 0.85;
            z-index: 0;
        }
        .no-scrollbar::-webkit-scrollbar{display:none}
        .no-scrollbar{-ms-overflow-style:none;scrollbar-width:none}
        .sticky-cats { position: sticky; top: 0; z-index: 30; backdrop-filter: blur(18px); }
        .floating-cart { position: fixed; right: 14px; bottom: 14px; z-index: 45; }
        .floating-cart:hover { transform: translateY(-2px) scale(1.01); }
        .floating-cart:active { transform: translateY(0) scale(0.99); }
        @media (min-width: 768px) { .floating-cart { right: 22px; bottom: 22px; } }
        @media (prefers-reduced-motion: no-preference) {
            .petal {
                position: fixed;
                top: -10vh;
                width: 8px;
                height: 8px;
                border-radius: 999px;
                background: rgba(52, 211, 153, 0.16);
                filter: blur(0.3px);
                animation: petal-fall linear infinite;
                pointer-events: none;
                z-index: 1;
            }
            @keyframes petal-fall {
                0% { transform: translate3d(0, -5vh, 0) rotate(0deg); opacity: 0; }
                8% { opacity: 0.75; }
                100% { transform: translate3d(12vw, 110vh, 0) rotate(260deg); opacity: 0; }
            }
        }
        <?php if ($brandAccentColorNorm !== null): ?>
        :root { --brand-accent: <?= e($brandAccentColorNorm) ?>; --primary: <?= e($brandAccentColorNorm) ?>; }
        .qr-brand-price { color: var(--brand-accent); }
        <?php if ($brandAccentTextOnButton !== '#ffffff'): ?>
        .qr-brand-price { text-shadow: 0 1px 2px rgba(0,0,0,0.25); }
        <?php endif; ?>
        .qr-brand-btn { background-color: var(--brand-accent); border-color: var(--brand-accent); color: <?= e($brandAccentTextOnButton) ?>; }
        .qr-brand-btn:hover { filter: brightness(1.08); }
        <?php endif; ?>
        .qr-page-shell {
            position: relative;
            border-radius: 2rem;
        }
        .qr-hero {
            position: relative;
            overflow: hidden;
            border-radius: 1.7rem;
            border: 1px solid rgba(148, 163, 184, 0.18);
            background:
                linear-gradient(138deg, rgba(15, 23, 42, 0.92), rgba(15, 23, 42, 0.68)),
                radial-gradient(circle at top right, rgba(16, 185, 129, 0.12), transparent 28%);
            box-shadow: 0 14px 30px rgba(2, 6, 23, 0.22);
        }
        .qr-hero::after {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(180deg, rgba(2, 6, 23, 0.08), rgba(2, 6, 23, 0.76));
            pointer-events: none;
        }
        .qr-hero-banner {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
            opacity: 0.23;
        }
        .qr-hero-content {
            position: relative;
            z-index: 1;
            padding: 0.76rem 0.88rem 0.74rem;
        }
        .qr-hero-badge,
        .qr-info-chip {
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            border-radius: 999px;
            border: 1px solid rgba(148, 163, 184, 0.18);
            background: rgba(15, 23, 42, 0.48);
            backdrop-filter: blur(14px);
        }
        .qr-hero-badge {
            padding: 0.44rem 0.72rem;
            font-size: 0.68rem;
            line-height: 1;
            color: rgb(203 213 225);
            letter-spacing: 0.02em;
        }
        .qr-hero-title {
            font-size: clamp(1.8rem, 4vw, 2.8rem);
            line-height: 1.02;
            font-weight: 800;
            letter-spacing: -0.04em;
            color: #f8fafc;
            text-wrap: balance;
        }
        .qr-hero-subtitle {
            color: rgba(226, 232, 240, 0.72);
            font-size: 0.8rem;
            line-height: 1.36;
            max-width: 31rem;
        }
        .qr-info-chip {
            padding: 0.48rem 0.76rem;
            font-size: 0.69rem;
            color: rgb(226 232 240);
        }
        .qr-loyalty-pill,
        .qr-mini-cart {
            border-radius: 1.05rem;
            border: 1px solid rgba(148, 163, 184, 0.18);
            background: rgba(2, 6, 23, 0.58);
            box-shadow: 0 10px 24px rgba(2, 6, 23, 0.2);
            backdrop-filter: blur(18px);
        }
        .qr-loyalty-pill:hover,
        .qr-mini-cart:hover {
            border-color: rgba(16, 185, 129, 0.35);
            transform: translateY(-1px);
        }
        .qr-meta-row {
            display: flex;
            flex-wrap: wrap;
            gap: 0.42rem;
            margin-top: 0.52rem;
        }
        .qr-status-strip {
            display: flex;
            flex-wrap: wrap;
            gap: 0.42rem;
        }
        .qr-status-chip {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            min-height: 1.78rem;
            padding: 0.36rem 0.68rem;
            border-radius: 999px;
            border: 1px solid rgba(148, 163, 184, 0.16);
            background: rgba(15, 23, 42, 0.5);
            color: rgb(203 213 225);
            font-size: 0.67rem;
            font-weight: 600;
        }
        .qr-nav-shell { }
        .qr-nav-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.6rem;
        }
        .qr-nav-caption {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            color: rgba(148, 163, 184, 0.82);
            font-size: 0.64rem;
            font-weight: 700;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            white-space: nowrap;
        }
        .qr-nav-caption::before {
            content: '';
            width: 0.45rem;
            height: 0.45rem;
            border-radius: 999px;
            background: rgba(16, 185, 129, 0.85);
            box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.14);
        }
        .qr-nav-hint {
            color: rgba(148, 163, 184, 0.76);
            font-size: 0.61rem;
            font-weight: 600;
            white-space: nowrap;
        }
        .qr-sticky-nav {
            border: 0;
            background: transparent;
            box-shadow: none;
        }
        .qr-category-chip {
            min-height: 2.02rem;
            padding: 0.4rem 0.7rem;
            border-radius: 999px;
            border: 1px solid rgba(148, 163, 184, 0.12);
            background: rgba(15, 23, 42, 0.76);
            color: #e2e8f0;
            font-size: 0.66rem;
            font-weight: 600;
            transition: 180ms ease;
        }
        .qr-category-chip:hover {
            border-color: rgba(16, 185, 129, 0.42);
            background: rgba(15, 23, 42, 0.92);
            color: #fff;
        }
        .qr-category-count {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 1.15rem;
            height: 1.15rem;
            padding: 0 0.3rem;
            border-radius: 999px;
            background: rgba(248, 250, 252, 0.08);
            color: rgba(226, 232, 240, 0.84);
            font-size: 0.62rem;
            font-weight: 800;
        }
        .qr-menu-section {
            padding-top: 0;
        }
        .qr-section-heading {
            display: flex;
            align-items: center;
            gap: 0.9rem;
            margin-bottom: 0.1rem;
        }
        .qr-section-kicker {
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            color: rgba(148, 163, 184, 0.72);
            font-size: 0.64rem;
            font-weight: 700;
            letter-spacing: 0.14em;
            text-transform: uppercase;
        }
        .qr-section-title {
            color: #f8fafc;
            font-size: 1.08rem;
            font-weight: 800;
            letter-spacing: -0.025em;
        }
        .qr-section-line {
            height: 1px;
            flex: 1;
            background: linear-gradient(90deg, rgba(148, 163, 184, 0.28), rgba(148, 163, 184, 0.08), transparent);
        }
        .qr-item-card {
            position: relative;
            overflow: hidden;
            border-radius: 1.42rem;
            border: 1px solid rgba(148, 163, 184, 0.12);
            background:
                linear-gradient(180deg, rgba(15, 23, 42, 0.82), rgba(2, 6, 23, 0.92));
            box-shadow: 0 14px 30px rgba(2, 6, 23, 0.2);
            transition: transform 180ms ease, border-color 180ms ease, box-shadow 180ms ease;
        }
        .qr-item-card::before {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(135deg, rgba(255,255,255,0.05), transparent 35%);
            opacity: 0.8;
            pointer-events: none;
        }
        .qr-item-card:hover {
            transform: translateY(-2px);
            border-color: rgba(148, 163, 184, 0.24);
            box-shadow: 0 18px 38px rgba(2, 6, 23, 0.24);
        }
        .qr-item-layout {
            display: grid;
            grid-template-columns: auto minmax(0, 1fr);
            gap: 0.8rem;
            align-items: stretch;
        }
        .qr-item-thumb {
            width: 5.2rem;
            height: 5.2rem;
            border-radius: 1.05rem;
            overflow: hidden;
            background: linear-gradient(135deg, rgba(30, 41, 59, 0.92), rgba(15, 23, 42, 0.85));
            border: 1px solid rgba(148, 163, 184, 0.08);
            flex-shrink: 0;
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.05);
        }
        .qr-item-placeholder {
            position: relative;
            color: rgba(226, 232, 240, 0.76);
            background:
                radial-gradient(circle at top, rgba(16, 185, 129, 0.18), transparent 52%),
                linear-gradient(180deg, rgba(30, 41, 59, 0.98), rgba(15, 23, 42, 0.96));
        }
        .qr-item-placeholder::before {
            content: '';
            position: absolute;
            inset: 15% 20%;
            border-radius: 999px;
            border: 1px solid rgba(226, 232, 240, 0.08);
            background: radial-gradient(circle at center, rgba(248,250,252,0.08), transparent 62%);
            filter: blur(0.3px);
        }
        .qr-item-placeholder::after {
            content: '';
            position: absolute;
            inset: 30% 24%;
            border-radius: 999px;
            border: 1px dashed rgba(148, 163, 184, 0.16);
            opacity: 0.7;
        }
        .qr-item-placeholder-mark {
            position: relative;
            z-index: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.16rem;
            text-align: center;
        }
        .qr-item-placeholder-title {
            font-size: 0.66rem;
            line-height: 1;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.12em;
        }
        .qr-item-placeholder-sub {
            font-size: 0.56rem;
            line-height: 1.1;
            color: rgba(148, 163, 184, 0.84);
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }
        .qr-item-title {
            color: #f8fafc;
            font-size: 1rem;
            font-weight: 800;
            letter-spacing: -0.03em;
            line-height: 1.2;
            overflow-wrap: anywhere;
            word-break: break-word;
        }
        .qr-item-desc {
            color: rgba(203, 213, 225, 0.7);
            font-size: 0.75rem;
            line-height: 1.45;
            overflow-wrap: anywhere;
            word-break: break-word;
        }
        .qr-item-meta-top {
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            color: rgba(148, 163, 184, 0.76);
            font-size: 0.62rem;
            font-weight: 700;
            letter-spacing: 0.12em;
            text-transform: uppercase;
        }
        .qr-item-meta-dot {
            width: 0.22rem;
            height: 0.22rem;
            border-radius: 999px;
            background: rgba(16, 185, 129, 0.8);
        }
        .qr-meta-pill {
            display: inline-flex;
            align-items: center;
            min-height: 1.65rem;
            padding: 0.22rem 0.55rem;
            border-radius: 999px;
            border: 1px solid rgba(148, 163, 184, 0.14);
            background: rgba(15, 23, 42, 0.62);
            color: rgb(203 213 225);
            font-size: 0.64rem;
            font-weight: 700;
            letter-spacing: 0.03em;
        }
        .qr-item-footer {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            grid-template-areas: "bottom price";
            align-items: end;
            gap: 0.7rem;
            margin-top: auto;
            padding-top: 0.8rem;
        }
        .qr-item-price-row {
            grid-area: price;
            display: flex;
            align-items: center;
            justify-content: flex-end;
            flex-shrink: 0;
        }
        .qr-item-bottom-row {
            grid-area: bottom;
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            align-items: end;
            gap: 0.65rem;
            min-width: 0;
        }
        .qr-item-tags {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 0.35rem;
            min-width: 0;
        }
        .qr-item-form {
            display: flex;
            align-items: center;
            flex-shrink: 0;
        }
        .qr-price-pill {
            display: inline-flex;
            flex-direction: column;
            align-items: flex-start;
            justify-content: center;
            min-height: 2.35rem;
            padding: 0.42rem 0.76rem;
            border-radius: 0.95rem;
            background: rgba(16, 185, 129, 0.1);
            border: 1px solid rgba(16, 185, 129, 0.22);
            color: #ecfdf5;
            line-height: 1;
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.05);
        }
        .qr-price-kicker {
            font-size: 0.54rem;
            font-weight: 800;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            color: rgba(167, 243, 208, 0.9);
            margin-bottom: 0.15rem;
        }
        .qr-price-value {
            font-size: 0.96rem;
            font-weight: 800;
            letter-spacing: -0.03em;
        }
        .qr-cta-btn,
        .qr-secondary-btn {
            transition: transform 160ms ease, box-shadow 160ms ease, filter 160ms ease, border-color 160ms ease;
        }
        .qr-cta-btn:hover,
        .qr-secondary-btn:hover {
            transform: translateY(-1px);
        }
        .qr-cta-btn:active,
        .qr-secondary-btn:active {
            transform: translateY(0) scale(0.99);
        }
        .qr-cta-btn {
            box-shadow: 0 12px 24px rgba(5, 150, 105, 0.22);
        }
        .qr-cta-btn:hover {
            box-shadow: 0 16px 28px rgba(5, 150, 105, 0.28);
            filter: saturate(1.04);
        }
        .qr-secondary-btn {
            background: rgba(15, 23, 42, 0.84);
            border: 1px solid rgba(148, 163, 184, 0.16);
            color: #f8fafc;
        }
        .qr-cart-panel,
        .qr-panel-soft {
            border: 1px solid var(--qr-stroke);
            background:
                linear-gradient(180deg, rgba(15, 23, 42, 0.9), rgba(2, 6, 23, 0.92));
            box-shadow: var(--qr-shadow);
        }
        .qr-cart-item {
            border: 1px solid rgba(148, 163, 184, 0.12);
            background: rgba(15, 23, 42, 0.72);
        }
        .qr-upsell-card {
            border-radius: 1.25rem;
            border: 1px solid rgba(148, 163, 184, 0.14);
            background: linear-gradient(180deg, rgba(15, 23, 42, 0.8), rgba(2, 6, 23, 0.92));
            box-shadow: 0 12px 28px rgba(2, 6, 23, 0.18);
        }
        .qr-upsell-reason {
            color: rgba(253, 230, 138, 0.88);
            font-size: 0.65rem;
            font-weight: 700;
            letter-spacing: 0.02em;
            text-transform: uppercase;
        }
        .qr-floating-cart-shell {
            border: 1px solid rgba(16, 185, 129, 0.28);
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.96), rgba(5, 150, 105, 0.94));
            box-shadow: 0 16px 34px rgba(6, 78, 59, 0.28);
            backdrop-filter: blur(16px);
            transition: transform 180ms ease, box-shadow 180ms ease, filter 180ms ease;
        }
        .qr-floating-cart-shell:hover {
            box-shadow: 0 20px 40px rgba(6, 78, 59, 0.34);
            filter: saturate(1.05);
        }
        .qr-floating-cart-copy {
            display: flex;
            flex-direction: column;
            gap: 0.08rem;
            line-height: 1;
        }
        .qr-floating-cart-kicker {
            font-size: 0.56rem;
            text-transform: uppercase;
            letter-spacing: 0.16em;
            color: rgba(236, 253, 245, 0.78);
        }
        .qr-floating-cart-title {
            font-size: 0.88rem;
            font-weight: 800;
            letter-spacing: -0.02em;
            color: #f8fafc;
        }
        .qr-floating-cart-total {
            display: inline-flex;
            align-items: center;
            min-height: 1.5rem;
            padding: 0 0.5rem;
            border-radius: 999px;
            background: rgba(2, 6, 23, 0.2);
            color: rgba(236, 253, 245, 0.95);
            font-size: 0.68rem;
            font-weight: 800;
            letter-spacing: -0.02em;
        }
        .qr-login-card {
            border-radius: 1.3rem;
            border: 1px solid rgba(16, 185, 129, 0.14);
            background: linear-gradient(180deg, rgba(2, 6, 23, 0.68), rgba(15, 23, 42, 0.56));
            box-shadow: 0 8px 16px rgba(2, 6, 23, 0.1);
        }
        .qr-login-kicker {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            color: rgba(167, 243, 208, 0.88);
            font-size: 0.62rem;
            font-weight: 800;
            letter-spacing: 0.14em;
            text-transform: uppercase;
        }
        .qr-login-copy {
            color: rgba(203, 213, 225, 0.72);
            font-size: 0.72rem;
            line-height: 1.35;
        }
        .qr-login-btn {
            box-shadow: 0 10px 22px rgba(5, 150, 105, 0.16);
        }
        .qr-active-order-card {
            border-radius: 1.3rem;
            border: 1px solid rgba(59, 130, 246, 0.18);
            background: linear-gradient(180deg, rgba(2, 6, 23, 0.7), rgba(15, 23, 42, 0.62));
            box-shadow: 0 10px 22px rgba(2, 6, 23, 0.12);
            overflow-wrap: anywhere;
        }
        .qr-active-order-kicker {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            color: rgba(191, 219, 254, 0.9);
            font-size: 0.62rem;
            font-weight: 800;
            letter-spacing: 0.14em;
            text-transform: uppercase;
        }
        .qr-active-order-copy {
            color: rgba(203, 213, 225, 0.74);
            font-size: 0.74rem;
            line-height: 1.35;
        }
        .qr-active-order-btn {
            box-shadow: 0 10px 22px rgba(15, 23, 42, 0.2);
            white-space: normal;
            overflow-wrap: anywhere;
            word-break: break-word;
        }
        .qr-empty-card {
            border: 1px solid rgba(148, 163, 184, 0.12);
            background: linear-gradient(180deg, rgba(15, 23, 42, 0.78), rgba(2, 6, 23, 0.92));
            box-shadow: 0 18px 44px rgba(2, 6, 23, 0.24);
        }
        @media (max-width: 640px) {
            .qr-hero-content { padding: 0.9rem; }
            .qr-hero-title { font-size: 1.48rem; }
            .qr-nav-head { align-items: flex-start; }
            .qr-item-layout { gap: 0.72rem; }
            .qr-item-thumb { width: 4.9rem; height: 4.9rem; }
            .qr-active-order-card { padding-bottom: max(0.75rem, env(safe-area-inset-bottom)); }
        }
        @media (max-width: 767px) {
            .qr-item-footer {
                grid-template-columns: 1fr;
                grid-template-areas:
                    "price"
                    "bottom";
                align-items: stretch;
                gap: 0.65rem;
                padding-top: 0.7rem;
            }
            .qr-item-price-row {
                justify-content: flex-start;
            }
            .qr-item-bottom-row {
                grid-template-columns: minmax(0, 1fr) auto;
                align-items: end;
                gap: 0.6rem;
            }
            .qr-item-tags {
                min-width: 0;
            }
            .qr-item-form {
                align-self: end;
            }
            .qr-item-form .qr-cta-btn {
                min-width: 8.35rem;
                min-height: 42px;
                padding-left: 1rem;
                padding-right: 1rem;
                white-space: nowrap;
            }
            .qr-price-pill {
                width: auto;
                min-width: 7rem;
                max-width: max-content;
            }
            .qr-item-title {
                font-size: 0.96rem;
            }
            .qr-item-desc {
                font-size: 0.72rem;
                line-height: 1.38;
            }
        }
        .menu-banner { width: 100%; height: 180px; object-fit: cover; border-radius: 12px; display: block; }
        @supports (padding: max(0px)) {
            .qr-safe-pb { padding-bottom: max(1rem, env(safe-area-inset-bottom)); }
        }
        .qr-context-strip {
            border-radius: 1rem;
            padding: 0.625rem 1rem;
            font-size: 0.75rem;
            line-height: 1.4;
        }
        .qr-context-strip-table {
            border: 1px solid rgba(52, 211, 153, 0.38);
            background: rgba(6, 78, 59, 0.22);
            color: rgb(226, 232, 240);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        .qr-context-strip-delivery {
            border: 1px solid rgba(56, 189, 248, 0.38);
            background: rgba(12, 74, 110, 0.28);
            color: rgb(224, 242, 254);
        }
        .qr-mode-delivery .qr-hero {
            border-bottom: 1px solid rgba(56, 189, 248, 0.25);
        }
        .qr-mode-table .qr-hero {
            border-bottom: 1px solid rgba(52, 211, 153, 0.22);
        }
        .qr-mode-delivery .qr-cart-panel {
            box-shadow: 0 -12px 48px rgba(14, 165, 233, 0.12);
        }
    </style>
</head>
<body class="overflow-x-hidden <?= e($ui['body']) ?><?= $isDeliveryMode ? ' qr-mode-delivery' : ' qr-mode-table' ?><?= is_demo_mode() ? ' demo-mode' : '' ?>">
<div aria-hidden="true">
    <span class="petal" style="left:6%;animation-duration:19s;animation-delay:-2s"></span>
    <span class="petal" style="left:22%;animation-duration:24s;animation-delay:-6s"></span>
    <span class="petal" style="left:44%;animation-duration:22s;animation-delay:-10s"></span>
    <span class="petal" style="left:63%;animation-duration:20s;animation-delay:-4s"></span>
    <span class="petal" style="left:81%;animation-duration:26s;animation-delay:-12s"></span>
</div>
<div class="relative z-10 max-w-3xl mx-auto px-3 sm:px-4 py-4 pb-36 md:pb-4 space-y-2 sm:space-y-3 qr-safe-pb qr-page-shell">
    <?php if ($openedWithoutTableId): ?>
        <div class="mb-1">
            <a href="<?= e($platformBackUrl) ?>"
               class="inline-flex items-center gap-1.5 rounded-full border border-slate-700/80 bg-slate-900/55 px-3 py-1.5 text-[12px] font-medium text-slate-300 hover:text-slate-100 hover:border-slate-500/90 transition-colors">
                <span aria-hidden="true">←</span>
                <span>К выбору ресторанов</span>
            </a>
        </div>
    <?php endif; ?>

    <!-- Шапка -->
    <header class="qr-hero">
        <?php if (!empty($brandBannerSrc)): ?>
            <img src="<?= e($brandBannerSrc) ?>"
                 alt="<?= e($currentRestaurant['name'] ?? 'Banner') ?>"
                 class="qr-hero-banner"
                 loading="lazy">
        <?php endif; ?>
        <div class="qr-hero-content space-y-2 sm:space-y-2.5">
            <div class="flex items-start sm:items-center justify-between gap-3 min-w-0">
                <div class="min-w-0 flex-1">
                <?php if (!empty($brandLogoSrc)): ?>
                    <div class="mb-2.5">
                        <img src="<?= e($brandLogoSrc) ?>"
                             alt="<?= e($currentRestaurant['name'] ?? 'Logo') ?>"
                             loading="lazy"
                             class="h-12 w-auto object-contain drop-shadow-[0_6px_20px_rgba(2,6,23,0.32)]">
                    </div>
                <?php endif; ?>
                <div class="qr-hero-badge mb-2.5">
                    <span class="w-2 h-2 rounded-full <?= $isDeliveryMode ? 'bg-sky-400' : 'bg-emerald-400' ?> animate-pulse"></span>
                    <span><?= $isDeliveryMode ? 'Заказ с доставкой' : 'Заказ в зале по QR' ?></span>
                </div>
                <h1 class="qr-hero-title"><?= e($currentRestaurant['name']) ?></h1>
                <div class="qr-hero-subtitle mt-1.5">
                    <?php if ($isDeliveryMode): ?>
                        Меню доставки: соберите корзину и перейдите к оформлению — укажите ФИО, телефон и полный адрес для курьера.
                    <?php else: ?>
                        Меню вашего стола: выберите блюда и оформите заказ — персонал увидит его в панели зала.
                    <?php endif; ?>
                </div>
                <div class="qr-meta-row">
                    <span class="qr-info-chip <?= $isDeliveryMode ? 'border-sky-500/35 bg-sky-500/5' : '' ?>">
                        <span class="inline-flex h-2 w-2 rounded-full <?= $isDeliveryMode ? 'bg-sky-400' : 'bg-emerald-400' ?>"></span>
                        <?php if ($isDeliveryMode): ?>
                            <span class="text-sky-100/95">Доставка курьером</span>
                        <?php else: ?>
                            <span class="text-slate-200">Стол <strong class="text-white font-semibold"><?= e($table['name']) ?></strong></span>
                        <?php endif; ?>
                    </span>
                    <span class="qr-info-chip <?= $isDeliveryMode ? 'border-sky-500/30' : '' ?>">
                        <?php if ($isDeliveryMode): ?>
                            <span class="text-[11px] uppercase tracking-[0.18em] text-sky-300/90">DELIVERY</span>
                            <span class="text-slate-200">Без привязки к столу</span>
                        <?php else: ?>
                            <span class="text-[11px] uppercase tracking-[0.18em] text-slate-400">ЗАЛ</span>
                            <span class="text-slate-200">Один QR — одно меню</span>
                        <?php endif; ?>
                    </span>
                </div>
                </div>

                <div class="flex flex-col items-end gap-2 shrink-0">
                <?php if ($guestSessionUser): ?>
                    <a href="/guest/cabinet.php"
                       class="qr-loyalty-pill inline-flex items-center gap-2 px-3 py-2 min-h-[44px] transition-all">
                        <span class="inline-flex h-8 w-8 items-center justify-center rounded-full bg-emerald-500/15 text-emerald-300 text-sm font-semibold" aria-hidden="true">◉</span>
                        <span class="text-left">
                            <span class="block text-[10px] text-slate-500 leading-tight">Бонусы</span>
                            <span class="block text-sm font-semibold text-slate-100 tabular-nums"><?= number_format((int)$guestSessionBalance, 0, '.', ' ') ?></span>
                        </span>
                    </a>
                <?php endif; ?>
            <a
                id="mini-cart-button"
                href="<?= e($qrCartLink) ?>"
                class="qr-mini-cart inline-flex flex-col items-end gap-0.5 px-3 py-2.5 text-right min-h-[44px] min-w-[44px] justify-center transition-all <?= $cartTotalQty ? '' : 'hidden' ?>"
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
                <span class="text-[10px] <?= $isDeliveryMode ? 'text-sky-400/90' : 'text-slate-500' ?>"><?= $isDeliveryMode ? 'доставка' : 'к столу' ?></span>
            </a>
                </div>
            </div>
            <?php if ($availableTypes): ?>
                <div class="qr-status-strip relative z-10">
                <?php if ($allowCardLater): ?>
                    <span class="qr-status-chip">Картой</span>
                <?php endif; ?>
                <?php if ($allowCash): ?>
                    <span class="qr-status-chip">Наличными</span>
                <?php endif; ?>
                    <?php if ($loyaltyEnabled): ?>
                        <span class="qr-status-chip">Бонусы <?= (int)$loyaltyPercent ?>%</span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </header>

    <?php if (!$isDeliveryMode && !empty($table['name']) && !qr_public_is_delivery_table_row($table)): ?>
        <div class="qr-context-strip qr-context-strip-table" role="status">
            <span class="font-semibold text-emerald-100/95">Заказ в зале</span>
            <span class="text-slate-200/95">Стол <span class="font-semibold text-white"><?= e($table['name']) ?></span></span>
        </div>
    <?php elseif ($isDeliveryMode): ?>
        <div class="qr-context-strip qr-context-strip-delivery" role="status">
            <span><span class="font-semibold text-sky-100">Доставка</span> — оформление и адрес заполняются в корзине перед отправкой.</span>
        </div>
    <?php endif; ?>

    <?php if (!$guestSessionUser && $loyaltyEnabled && $qrView !== 'cart'): ?>
        <div class="qr-login-card px-4 py-2.5 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2.5">
            <div class="min-w-0">
                <div class="qr-login-kicker">Бонусы и быстрый вход</div>
                <div class="text-sm font-semibold text-slate-100 mt-1">Войдите по номеру телефона, чтобы сохранить бонусы</div>
                <div class="qr-login-copy mt-1">SMS-код и личный кабинет без лишних шагов при оплате.</div>
            </div>
            <button type="button" id="guest-otp-open" class="qr-login-btn shrink-0 min-h-[46px] px-5 rounded-2xl bg-emerald-500 hover:bg-emerald-400 text-slate-950 text-sm font-semibold touch-manipulation">
                Ввести номер
            </button>
        </div>
    <?php endif; ?>

    <?php if ($activeOrderCard): ?>
        <?php $activeIsDel = !empty($activeOrderCard['is_delivery_session']); ?>
        <div class="qr-active-order-card px-4 py-3 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 <?= $activeIsDel ? 'border-sky-500/25' : '' ?>">
            <div class="min-w-0">
                <div class="qr-active-order-kicker"><?= $activeIsDel ? 'Заказ на доставке' : 'Активный заказ в зале' ?></div>
                <div class="mt-1 flex flex-wrap items-center gap-2">
                    <div class="text-sm font-semibold text-slate-100">Заказ #<?= (int)$activeOrderCard['id'] ?></div>
                    <span class="inline-flex items-center rounded-full border px-2.5 py-1 text-[11px] font-semibold <?= e((string)$activeOrderCard['status_class']) ?>">
                        <?= e((string)$activeOrderCard['status_label']) ?>
                    </span>
                </div>
                <div class="qr-active-order-copy mt-1"><?= $activeIsDel
                    ? 'Статус доставки и детали — в трекинге заказа.'
                    : 'Заказ уходит на кухню для вашего стола. Статус можно посмотреть в трекинге.' ?></div>
            </div>
            <a href="<?= e((string)$activeOrderCard['track_url']) ?>" class="qr-active-order-btn inline-flex items-center justify-center shrink-0 min-h-[46px] px-5 rounded-2xl bg-slate-900/80 border <?= $activeIsDel ? 'border-sky-500/40 hover:border-sky-400/70' : 'border-slate-700 hover:border-emerald-500/50' ?> text-sm font-semibold text-slate-100 touch-manipulation">
                <?= $activeIsDel ? 'Статус доставки' : 'Открыть заказ' ?>
            </a>
        </div>
    <?php endif; ?>

    <?php if ($guestBookingEnabled && $qrView === 'menu' && $guestBookingTables): ?>
        <section class="rounded-3xl border border-indigo-500/25 bg-indigo-500/10 px-4 py-3">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <div class="text-[11px] uppercase tracking-[0.16em] text-indigo-200/80 font-semibold">Бронирование стола</div>
                    <div class="text-sm font-semibold text-indigo-100 mt-1">Забронируйте стол заранее</div>
                    <div class="text-[12px] text-indigo-100/75 mt-1">Оставьте контакты, дату и время. Ресторан увидит бронь в карте зала.</div>
                </div>
            </div>
            <form id="guest-booking-form" class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-2.5">
                <input type="hidden" name="csrf" value="<?= e($reservationCsrfToken) ?>">
                <input type="hidden" name="source" value="guest_qr">
                <label class="text-[12px] text-indigo-100/90">
                    <span class="block mb-1">Имя</span>
                    <input type="text" name="guest_name" required maxlength="190" class="w-full rounded-xl border border-indigo-300/30 bg-slate-950/55 px-3 py-2 text-sm text-slate-100 placeholder:text-slate-500" placeholder="Ваше имя">
                </label>
                <label class="text-[12px] text-indigo-100/90">
                    <span class="block mb-1">Телефон</span>
                    <input type="tel" name="guest_phone" required maxlength="32" class="w-full rounded-xl border border-indigo-300/30 bg-slate-950/55 px-3 py-2 text-sm text-slate-100 placeholder:text-slate-500" placeholder="+7 900 000 00 00">
                </label>
                <label class="text-[12px] text-indigo-100/90">
                    <span class="block mb-1">Дата</span>
                    <input type="date" name="reservation_date" required class="w-full rounded-xl border border-indigo-300/30 bg-slate-950/55 px-3 py-2 text-sm text-slate-100">
                </label>
                <label class="text-[12px] text-indigo-100/90">
                    <span class="block mb-1">Время</span>
                    <input type="time" name="reservation_time" required class="w-full rounded-xl border border-indigo-300/30 bg-slate-950/55 px-3 py-2 text-sm text-slate-100">
                </label>
                <label class="text-[12px] text-indigo-100/90">
                    <span class="block mb-1">Стол</span>
                    <select name="table_id" required class="w-full rounded-xl border border-indigo-300/30 bg-slate-950/55 px-3 py-2 text-sm text-slate-100">
                        <?php foreach ($guestBookingTables as $guestBookingTable): ?>
                            <?php
                            $guestBookingTableId = (int)($guestBookingTable['id'] ?? 0);
                            $guestBookingTableName = trim((string)($guestBookingTable['name'] ?? ('Стол #' . $guestBookingTableId)));
                            ?>
                            <option value="<?= $guestBookingTableId ?>" <?= $guestBookingTableId === (int)$tableId ? 'selected' : '' ?>>
                                <?= e($guestBookingTableName) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="text-[12px] text-indigo-100/90">
                    <span class="block mb-1">Гостей</span>
                    <input type="number" name="guests_count" min="1" max="30" value="2" class="w-full rounded-xl border border-indigo-300/30 bg-slate-950/55 px-3 py-2 text-sm text-slate-100">
                </label>
                <label class="text-[12px] text-indigo-100/90 sm:col-span-2">
                    <span class="block mb-1">Комментарий (опционально)</span>
                    <textarea name="comment" rows="2" maxlength="500" class="w-full rounded-xl border border-indigo-300/30 bg-slate-950/55 px-3 py-2 text-sm text-slate-100 placeholder:text-slate-500" placeholder="Пожелания по месту или времени"></textarea>
                </label>
                <div class="sm:col-span-2 flex items-center gap-2.5">
                    <button type="submit" class="qr-cta-btn inline-flex items-center justify-center min-h-[44px] px-4 rounded-2xl bg-indigo-500 hover:bg-indigo-400 text-slate-950 text-sm font-semibold">
                        Забронировать
                    </button>
                    <div id="guest-booking-feedback" class="text-[12px] text-indigo-100/90"></div>
                </div>
            </form>
        </section>
    <?php endif; ?>

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
    <?php if ($itemsByCategory && $qrView === 'menu'): ?>
        <nav class="sticky-cats qr-sticky-nav py-1" aria-label="Категории меню">
            <div class="px-0 py-0">
                <div class="qr-nav-head mb-1">
                    <div class="qr-nav-caption"><?= $isDeliveryMode ? 'Меню доставки' : 'Меню стола' ?></div>
                    <div class="qr-nav-hint"><?= $menuCategoryCount ?> разделов · <?= $menuItemsCount ?> позиций</div>
                </div>
                <div class="flex gap-2 overflow-x-auto no-scrollbar pr-1">
            <?php foreach ($itemsByCategory as $catName => $_): ?>
                <a href="#cat-<?= md5($catName) ?>"
                   class="qr-category-chip inline-flex items-center gap-2 whitespace-nowrap text-center">
                    <?= e($catName) ?>
                    <span class="qr-category-count"><?= isset($itemsByCategory[$catName]) ? count($itemsByCategory[$catName]) : 0 ?></span>
                </a>
            <?php endforeach; ?>
                </div>
            </div>
        </nav>
    <?php endif; ?>

    <?php if ($qrView === 'menu'): ?>
    <!-- Меню -->
    <main class="space-y-3">
        <?php if (!$items): ?>
            <div class="qr-empty-card text-sm text-slate-400 rounded-3xl p-6 text-center leading-relaxed">
                Меню пока пусто. Попросите персонал обновить меню.
            </div>
        <?php else: ?>
            <?php foreach ($itemsByCategory as $catName => $list): ?>
                <section class="qr-menu-section space-y-2" id="cat-<?= md5($catName) ?>">
                    <div>
                        <div class="qr-section-kicker">
                            <span>Категория</span>
                            <span class="qr-item-meta-dot" aria-hidden="true"></span>
                            <span><?= count($list) ?> <?= count($list) === 1 ? 'позиция' : (count($list) < 5 ? 'позиции' : 'позиций') ?></span>
                        </div>
                        <div class="qr-section-heading mt-1">
                            <h2 class="qr-section-title"><?= e($catName) ?></h2>
                            <div class="qr-section-line"></div>
                        </div>
                    </div>

                    <div class="space-y-2.5">
                        <?php foreach ($list as $item): ?>
                            <?php
                                $imgUrl = menu_item_image_url($item) ?? '';
                                $desc = (string)($item['description'] ?? '');
                                $calories = (int)($item['calories'] ?? 0);
                                $proteins = (float)($item['proteins'] ?? 0);
                                $fats = (float)($item['fats'] ?? 0);
                                $carbs = (float)($item['carbs'] ?? 0);
                                $ingredients = trim((string)($item['ingredients'] ?? ($item['composition'] ?? '')));
                                $allergens = trim((string)($item['allergens'] ?? ''));
                                $weightRaw = trim((string)($item['weight_grams'] ?? ($item['weight'] ?? '')));
                                $weightText = '';
                                if ($weightRaw !== '') {
                                    if (is_numeric($weightRaw)) {
                                        $weightText = ((float)$weightRaw == (int)$weightRaw ? (string)(int)$weightRaw : (string)$weightRaw) . ' г';
                                    } else {
                                        $weightText = $weightRaw;
                                    }
                                }
                                $tagLabels = [];
                                $rawTags = (string)($item['dietary_tags'] ?? '');
                                if ($rawTags !== '') {
                                    $decodedTags = json_decode($rawTags, true);
                                    if (is_array($decodedTags)) {
                                        foreach ($decodedTags as $tg) {
                                            $tg = strtolower(trim((string)$tg));
                                            if ($tg === 'spicy') $tagLabels[] = 'Острое';
                                            if ($tg === 'vegan') $tagLabels[] = 'Веган';
                                            if ($tg === 'bestseller') $tagLabels[] = 'Хит';
                                        }
                                    }
                                }
                                $rawGenericTags = trim((string)($item['tags'] ?? ''));
                                if ($rawGenericTags !== '') {
                                    $decodedGeneric = json_decode($rawGenericTags, true);
                                    if (is_array($decodedGeneric)) {
                                        foreach ($decodedGeneric as $tg) {
                                            $tgText = trim((string)$tg);
                                            if ($tgText !== '' && !in_array($tgText, $tagLabels, true)) {
                                                $tagLabels[] = $tgText;
                                            }
                                        }
                                    } else {
                                        foreach (preg_split('/[,;]+/u', $rawGenericTags) as $tg) {
                                            $tgText = trim((string)$tg);
                                            if ($tgText !== '' && !in_array($tgText, $tagLabels, true)) {
                                                $tagLabels[] = $tgText;
                                            }
                                        }
                                    }
                                }
                                $compactFoodMeta = [];
                                if ($allergens !== '') {
                                    $compactFoodMeta[] = 'Аллергены: ' . $allergens;
                                }
                                if ($weightText !== '') {
                                    $compactFoodMeta[] = 'Вес: ' . $weightText;
                                }
                            ?>
                            <!-- Карточка блюда (тап -> модалка) -->
                            <article
                                class="js-item-card qr-item-card p-3 cursor-pointer active:scale-[0.99]"
                                role="button"
                                tabindex="0"
                                data-id="<?= (int)$item['id'] ?>"
                                data-name="<?= e($item['name']) ?>"
                                data-desc="<?= e($desc) ?>"
                                data-price="<?= (float)$item['price'] ?>"
                                data-image="<?= e($imgUrl) ?>"
                                data-calories="<?= (int)$calories ?>"
                                data-proteins="<?= (float)$proteins ?>"
                                data-fats="<?= (float)$fats ?>"
                                data-carbs="<?= (float)$carbs ?>"
                                data-ingredients="<?= e($ingredients) ?>"
                                data-allergens="<?= e($allergens) ?>"
                                data-weight="<?= e($weightText) ?>"
                                data-tags="<?= e(implode(', ', $tagLabels)) ?>"
                            >
                                <div class="qr-item-layout">
                                    <?php if ($imgUrl): ?>
                                        <div class="qr-item-thumb">
                                            <img src="<?= e($imgUrl) ?>" alt="<?= e($item['name']) ?>" loading="lazy" class="w-full h-full object-cover">
                                        </div>
                                    <?php else: ?>
                                        <div class="qr-item-thumb qr-item-placeholder flex items-center justify-center flex-shrink-0">
                                            <div class="qr-item-placeholder-mark">
                                                <span class="qr-item-placeholder-title">Signature</span>
                                                <span class="qr-item-placeholder-sub">Фото скоро</span>
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                    <div class="flex-1 flex flex-col min-w-0">
                                        <div class="min-w-0">
                                            <div class="qr-item-meta-top mb-1">
                                                <span>Блюдо</span>
                                                <span class="qr-item-meta-dot" aria-hidden="true"></span>
                                                <span><?= $calories > 0 ? ((int)$calories . ' ккал') : 'Свежая подача' ?></span>
                                            </div>
                                            <h3 class="qr-item-title line-clamp-2"><?= e($item['name']) ?></h3>

                                            <?php if ($desc !== ''): ?>
                                                <p class="qr-item-desc mt-1 line-clamp-2">
                                                    <?= e($desc) ?>
                                                </p>
                                            <?php endif; ?>
                                        </div>

                                        <div class="qr-item-footer">
                                            <div class="qr-item-price-row">
                                                <div class="qr-price-pill <?= $hasBrandAccent ? 'qr-brand-price' : '' ?>">
                                                    <span class="qr-price-kicker">Цена</span>
                                                    <span class="qr-price-value"><?= number_format((float)$item['price'], 0, '.', ' ') ?> ₽</span>
                                                </div>
                                            </div>
                                            <div class="qr-item-bottom-row">
                                                <div class="qr-item-tags">
                                                    <span class="qr-meta-pill">КБЖУ</span>
                                                    <?php foreach (array_slice($tagLabels, 0, 2) as $lbl): ?>
                                                        <span class="qr-meta-pill border-emerald-500/35 text-emerald-300"><?= e($lbl) ?></span>
                                                    <?php endforeach; ?>
                                                    <?php foreach (array_slice($compactFoodMeta, 0, 1) as $metaLbl): ?>
                                                        <span class="qr-meta-pill border-amber-500/35 text-amber-200"><?= e($metaLbl) ?></span>
                                                    <?php endforeach; ?>
                                                </div>
                                                <form method="post" class="js-add-to-cart-form qr-item-form" data-table-id="<?= $tableIdJs ?>">
                                                    <input type="hidden" name="item_id" value="<?= (int)$item['id'] ?>">
                                                    <button type="submit" name="add_item" value="1"
                                                            class="js-add-btn btn-add-cart btn-add-feedback qr-cta-btn min-h-[44px] px-4 py-2.5 rounded-2xl text-sm font-semibold tracking-tight <?= $hasBrandAccent ? 'qr-brand-btn border border-transparent' : 'bg-emerald-500 hover:bg-emerald-400 text-slate-950' ?>">
                                                        В корзину
                                                    </button>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endforeach; ?>
        <?php endif; ?>
    </main>
    <?php endif; ?>

    <?php if ($qrView === 'cart'): ?>
    <section class="pt-2">
        <div class="mb-2 flex flex-col gap-1.5 sm:flex-row sm:items-start sm:justify-between sm:gap-2">
            <div class="min-w-0">
                <h2 class="text-lg font-semibold <?= $isDeliveryMode ? 'text-sky-100' : 'text-slate-100' ?>"><?= e($checkoutTitle) ?></h2>
                <p class="text-[11px] <?= $isDeliveryMode ? 'text-sky-200/80' : 'text-slate-500' ?> mt-0.5 leading-snug"><?= e($checkoutSubtitle) ?></p>
            </div>
            <a href="<?= e($qrMenuLink) ?>" class="text-xs shrink-0 <?= $isDeliveryMode ? 'text-sky-300 hover:text-sky-200' : 'text-emerald-300 hover:text-emerald-200' ?>"><?= e($checkoutBackText) ?></a>
        </div>
    </section>
    <?php endif; ?>
    <!-- Корзина -->
    <section id="cart-block"
        class="<?= $qrView === 'cart'
            ? 'relative z-10 pt-2'
            : 'hidden md:hidden' ?>">
        <div class="max-w-3xl mx-auto rounded-t-2xl md:rounded-3xl qr-cart-panel <?= e($ui['cart_shell']) ?> px-3 py-3 md:px-4 md:py-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] md:pb-3">
            <div class="flex items-center justify-between gap-3 mb-2">
                <div>
                    <?php if ($isDeliveryMode && $qrView === 'cart' && $cartStageCaption !== null): ?>
                        <div class="text-[10px] font-semibold uppercase tracking-[0.14em] text-sky-300/90 mb-0.5"><?= e($cartStageCaption) ?></div>
                    <?php endif; ?>
                    <h2 class="text-sm font-semibold text-slate-50 flex items-center gap-1.5">
                        <span class="inline-flex w-5 h-5 items-center justify-center rounded-full <?= $isDeliveryMode ? 'bg-sky-500/15 border border-sky-500/45 text-[10px] text-sky-200' : 'bg-emerald-500/10 border border-emerald-500/40 text-[10px] text-emerald-300' ?>">
                            <span id="cart-block-total-qty"><?= $cartTotalQty ?></span>
                        </span>
                        <?= $isDeliveryMode ? 'Состав заказа' : 'Корзина' ?>
                    </h2>
                    <p class="text-[11px] <?= $isDeliveryMode ? 'text-sky-200/75' : 'text-slate-500' ?>"><?= e($cartStageHint) ?></p>
                </div>

                <div class="text-right">
                    <div class="text-[11px] text-slate-400">Итоговая сумма:</div>
                    <div id="cart-block-total-sum" class="text-lg font-semibold <?= e($ui['price_total']) ?> <?= $hasBrandAccent ? 'qr-brand-price' : '' ?> leading-tight">
                        <?= number_format($cartTotalSum, 0, '.', ' ') ?> ₽
                    </div>
                </div>
            </div>

            <form id="cart-form" method="post" class="space-y-3 mt-1">
                <div id="cart-empty-text" class="mt-1 text-xs text-slate-400" <?= $cartItems ? 'style="display:none"' : '' ?>>
                    Добавьте блюда из меню, чтобы оформить заказ.
                </div>

                <div class="max-h-40 overflow-y-auto pr-1 space-y-1.5" id="cart-items-list">
                    <?php foreach ($cartItems as $ci): ?>
                        <div class="flex items-center justify-between gap-2 rounded-2xl qr-cart-item <?= e($ui['cart_item']) ?> px-3 py-2 text-xs">
                            <div class="flex-1 min-w-0">
                                <div class="text-slate-100 break-words [overflow-wrap:anywhere]"><?= e($ci['name']) ?></div>
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
                    <?php if ($isDeliveryMode && $qrView === 'cart'): ?>
                        <div class="rounded-2xl border border-sky-500/25 bg-slate-950/70 px-3 py-3 space-y-3">
                            <div>
                                <div class="text-[11px] text-sky-200 font-semibold uppercase tracking-wide">Тип заказа</div>
                                <div class="text-sm font-semibold text-slate-50 mt-1">Выберите формат получения</div>
                                <div class="text-[11px] text-slate-500 mt-1">Foundation-режим: доставка, самовывоз или предзаказ.</div>
                            </div>
                            <div class="flex flex-wrap gap-2 text-sm text-slate-300">
                                <?php foreach (['delivery' => 'Доставка', 'pickup' => 'Самовывоз', 'preorder' => 'Предзаказ'] as $otValue => $otLabel): ?>
                                    <label class="inline-flex items-center gap-2 min-h-[44px] px-3 py-2 rounded-full bg-slate-950/80 border border-slate-700 cursor-pointer hover:border-sky-500/60 touch-manipulation">
                                        <input type="radio" name="order_type" value="<?= e($otValue) ?>" class="rounded bg-slate-950 border-slate-700"
                                            <?= $checkoutOrderTypeField === $otValue ? 'checked' : '' ?>>
                                        <span><?= e($otLabel) ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>

                            <label class="block">
                                <span class="text-[11px] text-slate-300">ФИО <span class="text-sky-400" aria-hidden="true">*</span></span>
                                <input type="text" name="delivery_full_name" autocomplete="name" required
                                       value="<?= e($deliveryFullNameField) ?>"
                                       class="mt-1 w-full min-h-[44px] rounded-2xl bg-slate-950 border border-slate-700 px-3 py-3 text-base text-slate-50 placeholder:text-slate-500 focus:outline-none focus:ring-2 focus:ring-sky-500 focus:border-sky-500"
                                       placeholder="Иванов Иван Иванович">
                            </label>
                            <label class="block">
                                <span class="text-[11px] text-slate-300">Телефон <span class="text-sky-400" aria-hidden="true">*</span></span>
                                <input type="tel" name="delivery_phone" autocomplete="tel" inputmode="tel" required
                                       value="<?= e($deliveryPhoneField) ?>"
                                       class="mt-1 w-full min-h-[44px] rounded-2xl bg-slate-950 border border-slate-700 px-3 py-3 text-base text-slate-50 placeholder:text-slate-500 focus:outline-none focus:ring-2 focus:ring-sky-500 focus:border-sky-500"
                                       placeholder="+7 9XX XXX-XX-XX">
                            </label>

                            <label class="block js-order-address-field" data-order-types="delivery,preorder_delivery">
                                <span class="text-[11px] text-slate-300">Адрес доставки <span class="text-sky-400" aria-hidden="true">*</span></span>
                                <textarea name="delivery_address" rows="3"
                                          class="mt-1 w-full rounded-2xl bg-slate-950 border border-slate-700 px-3 py-3 text-base text-slate-50 placeholder:text-slate-500 focus:outline-none focus:ring-2 focus:ring-sky-500 focus:border-sky-500"
                                          placeholder="Город, улица, дом, подъезд, домофон, этаж, квартира"><?= e($deliveryAddressField) ?></textarea>
                            </label>

                            <div class="js-preorder-fields rounded-2xl border border-slate-800 bg-slate-950/60 px-3 py-3 space-y-3">
                                <div class="text-[11px] text-slate-300 font-medium uppercase tracking-wide">Параметры предзаказа</div>
                                <div class="grid gap-2 sm:grid-cols-2">
                                    <label class="block">
                                        <span class="text-[11px] text-slate-300">Дата <span class="text-sky-400" aria-hidden="true">*</span></span>
                                        <input type="date" name="preorder_date"
                                               value="<?= e($preorderDateField) ?>"
                                               class="mt-1 w-full min-h-[44px] rounded-2xl bg-slate-950 border border-slate-700 px-3 py-3 text-base text-slate-50 focus:outline-none focus:ring-2 focus:ring-sky-500 focus:border-sky-500">
                                    </label>
                                    <label class="block">
                                        <span class="text-[11px] text-slate-300">Время <span class="text-sky-400" aria-hidden="true">*</span></span>
                                        <input type="time" name="preorder_time"
                                               value="<?= e($preorderTimeField) ?>"
                                               class="mt-1 w-full min-h-[44px] rounded-2xl bg-slate-950 border border-slate-700 px-3 py-3 text-base text-slate-50 focus:outline-none focus:ring-2 focus:ring-sky-500 focus:border-sky-500">
                                    </label>
                                </div>
                                <label class="block">
                                    <span class="text-[11px] text-slate-300">Как получить предзаказ</span>
                                    <select name="preorder_receive_type"
                                            class="mt-1 w-full min-h-[44px] rounded-2xl bg-slate-950 border border-slate-700 px-3 py-3 text-base text-slate-50 focus:outline-none focus:ring-2 focus:ring-sky-500 focus:border-sky-500">
                                        <option value="pickup" <?= $preorderReceiveTypeField === 'pickup' ? 'selected' : '' ?>>Самовывоз</option>
                                        <option value="delivery" <?= $preorderReceiveTypeField === 'delivery' ? 'selected' : '' ?>>Доставка</option>
                                    </select>
                                </label>
                            </div>
                        </div>
                    <?php else: ?>
                        <input type="hidden" name="order_type" value="hall">
                    <?php endif; ?>

                    <?php if ($loyaltyEnabled): ?>
                        <div class="rounded-2xl qr-panel-soft px-3 py-2 space-y-1">
                            <div class="text-[11px] text-slate-300 font-medium">Программа лояльности</div>
                            <?php
                            $lph = $guestSessionUser ? (string)($guestSessionUser['phone'] ?? '') : '';
                            $lpDigits = preg_replace('/\D+/', '', $lph);
                            $lpTail = strlen($lpDigits) >= 4 ? substr($lpDigits, -4) : '';
                            ?>
                            <?php if ($guestSessionUser): ?>
                                <div class="text-[11px] text-slate-400">Начислим бонусы на номер •••<?= e($lpTail) ?> после подтверждённой оплаты</div>
                                <input type="hidden" name="loyalty_phone" value="<?= e($loyaltyPhoneField) ?>">
                            <?php elseif ($isDeliveryMode): ?>
                                <div class="text-[11px] text-slate-400">Чтобы сохранить бонусы, войдите по SMS в шапке. Телефон для доставки указывается отдельно ниже.</div>
                            <?php elseif ($qrView === 'cart'): ?>
                                <div class="text-[11px] text-slate-400">Начислим бонусы после входа по SMS и подтверждённой оплаты</div>
                            <?php else: ?>
                                <div class="text-[11px] text-slate-500">Введите номер телефона, чтобы сохранить бонусы за этот заказ в этом ресторане.</div>
                                <input type="tel" name="loyalty_phone"
                                    value="<?= e($loyaltyPhoneField) ?>"
                                    placeholder="+7 9XX XXX-XX-XX"
                                    class="mt-1 w-full min-h-[44px] rounded-2xl bg-slate-950 border border-slate-700 px-3 py-3 text-base text-slate-50 placeholder:text-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                                <div class="mt-2 rounded-xl border border-slate-800/80 bg-slate-900/40 px-3 py-2 text-[11px] text-slate-500">
                                    Войдите по SMS в меню — номер подставится автоматически, а бонусы начислим после подтверждённой оплаты.
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!$isDeliveryMode && !($qrView === 'cart' && !$guestSessionUser)): ?>
                    <div class="rounded-2xl qr-panel-soft px-3 py-2 space-y-2">
                        <div class="text-[11px] text-slate-300 font-medium">Напоминания о визите</div>
                        <?php if ($qrView === 'cart' && $guestSessionUser): ?>
                            <input type="hidden" name="crm_phone" value="<?= e($crmPhoneField) ?>">
                            <label class="flex items-center gap-2 text-[11px] text-slate-400 cursor-pointer">
                                <input type="checkbox" name="crm_consent" value="1" class="rounded bg-slate-950 border-slate-700" <?= isset($_POST['crm_consent']) ? 'checked' : '' ?>>
                                <span>Разрешаю присылать напоминания</span>
                            </label>
                        <?php else: ?>
                            <div class="text-[11px] text-slate-500">Оставьте телефон — мы сможем напомнить о следующем визите.</div>
                            <input type="tel" name="crm_phone"
                                value="<?= e($crmPhoneField) ?>"
                                placeholder="+7 9XX XXX-XX-XX"
                                class="w-full min-h-[44px] rounded-2xl bg-slate-950 border border-slate-700 px-3 py-3 text-base text-slate-50 placeholder:text-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                            <label class="flex items-center gap-2 text-[11px] text-slate-400 cursor-pointer">
                                <input type="checkbox" name="crm_consent" value="1" class="rounded bg-slate-950 border-slate-700" <?= isset($_POST['crm_consent']) ? 'checked' : '' ?>>
                                <span>Разрешаю присылать напоминания</span>
                            </label>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

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

                    <?php if ($qrView === 'cart' && $loyaltyEnabled): ?>
                        <?php if ($guestSessionUser): ?>
                            <div class="rounded-3xl border border-emerald-500/35 bg-gradient-to-br from-emerald-500/12 via-slate-900/90 to-slate-950/95 px-4 py-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 shadow-lg shadow-emerald-950/30">
                                <div>
                                    <div class="text-xs font-semibold text-emerald-200/90 uppercase tracking-wide">Бонусы</div>
                                    <div class="text-lg font-bold text-slate-50 tabular-nums">Ваш бонусный счёт: <?= number_format((int)$guestSessionBalance, 0, '.', ' ') ?></div>
                                    <div class="text-[11px] text-slate-500 mt-0.5">Начислим за этот заказ после подтверждённой оплаты.</div>
                                </div>
                                <a href="/guest/cabinet.php" class="inline-flex items-center justify-center min-h-[48px] px-5 rounded-2xl bg-slate-900/80 border border-slate-700 text-sm font-medium text-slate-100 hover:border-emerald-500/50">
                                    Личный кабинет
                                </a>
                            </div>
                            <div class="rounded-3xl border border-slate-800 bg-slate-950/80 px-4 py-4 space-y-3">
                                <div>
                                    <div class="text-xs font-semibold text-slate-300 uppercase tracking-wide">Списать бонусы</div>
                                    <div class="mt-1 text-sm text-slate-400">1 бонус = 1 ₽. Сейчас можно списать до <?= number_format((int)$cartRedeemMax, 0, '.', ' ') ?> бонусов.</div>
                                </div>
                                <?php if ($cartRedeemMax > 0): ?>
                                    <div class="grid gap-3 sm:grid-cols-2">
                                        <label class="block">
                                            <span class="text-[11px] text-slate-400">Списать сейчас</span>
                                            <input type="number"
                                                name="loyalty_points_spend"
                                                min="0"
                                                max="<?= (int)$cartRedeemMax ?>"
                                                step="1"
                                                value="<?= (int)$cartRedeemSelected ?>"
                                                class="mt-1 w-full min-h-[44px] rounded-2xl bg-slate-950 border border-slate-700 px-3 py-3 text-base text-slate-50 placeholder:text-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                                        </label>
                                        <div class="rounded-2xl bg-slate-900/60 border border-slate-800 px-3 py-3">
                                            <div class="text-[11px] text-slate-400">К оплате сейчас</div>
                                            <div class="mt-1 text-2xl font-bold text-slate-50 tabular-nums"><?= number_format($cartFinalPayableSum, 0, '.', ' ') ?> ₽</div>
                                            <?php if ($cartRedeemSelected > 0): ?>
                                                <div class="mt-1 text-[11px] text-emerald-300">Списываем <?= number_format((int)$cartRedeemSelected, 0, '.', ' ') ?> бонусов</div>
                                            <?php endif; ?>
                                            <?php if ($cartPromoOk && is_array($cartPromoPreview) && (float)($cartPromoPreview['discount_amount'] ?? 0) > 0): ?>
                                                <div class="mt-1 text-[11px] text-indigo-300">Скидка по промокоду: −<?= number_format((float)($cartPromoPreview['discount_amount'] ?? 0), 0, '.', ' ') ?> ₽</div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <p class="text-[11px] text-slate-500">Максимум к списанию: 20% от суммы заказа и не больше вашего текущего баланса.</p>
                                <?php else: ?>
                                    <div class="text-[11px] text-slate-500">Списывать пока нечего: либо баланс нулевой, либо сумма заказа слишком маленькая для лимита 20%.</div>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="rounded-3xl border border-emerald-400/40 bg-gradient-to-br from-emerald-500/18 via-slate-900/95 to-slate-950 p-4 sm:p-5 shadow-xl shadow-emerald-950/40 ring-1 ring-emerald-500/20">
                                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                    <div class="min-w-0">
                                        <div class="inline-flex items-center gap-2 rounded-full bg-emerald-500/15 border border-emerald-500/35 px-2.5 py-2 text-[11px] font-semibold text-emerald-200 uppercase tracking-wide">
                                            Бонусы за заказ
                                        </div>
                                        <h3 class="mt-2 text-lg sm:text-xl font-bold text-slate-50 leading-snug">Сохраните бонусы за этот заказ</h3>
                                        <p class="mt-1 text-sm text-slate-400 max-w-md">Введите номер телефона, подтвердите его по SMS и привяжите заказ к своему счёту. Бонусы начислим после подтверждённой оплаты.</p>
                                    </div>
                                    <button type="button" id="guest-otp-open-cart" class="shrink-0 min-h-[52px] px-6 rounded-2xl bg-emerald-500 hover:bg-emerald-400 text-slate-950 text-base font-bold shadow-lg shadow-emerald-900/40 touch-manipulation">
                                        Войти и сохранить бонусы
                                    </button>
                                </div>
                                <p class="mt-3 text-[11px] text-slate-500">Заказ можно оформить и без входа — номер можно привязать позже, а бонусы начислим после подтверждённой оплаты.</p>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php if ($qrView === 'cart'): ?>
                        <div class="rounded-3xl border border-indigo-500/30 bg-gradient-to-br from-indigo-500/10 via-slate-900/90 to-slate-950/95 px-4 py-4 space-y-3">
                            <div>
                                <div class="text-xs font-semibold text-indigo-200/90 uppercase tracking-wide">Промокод</div>
                                <div class="text-[11px] text-slate-400 mt-1">Проверим промокод и пересчитаем итог перед оформлением.</div>
                            </div>
                            <div class="grid gap-3 sm:grid-cols-[1fr_auto] sm:items-end">
                                <label class="block">
                                    <span class="text-[11px] text-slate-400">Код акции</span>
                                    <input
                                        id="promo-code-input"
                                        type="text"
                                        name="promo_code"
                                        value="<?= e($promoCodeField) ?>"
                                        placeholder="Например: WELCOME10"
                                        class="mt-1 w-full min-h-[44px] rounded-2xl bg-slate-950 border border-slate-700 px-3 py-3 text-base text-slate-50 placeholder:text-slate-500 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                                    >
                                </label>
                                <div class="flex flex-col gap-2">
                                    <button type="button" id="promo-validate-button" class="min-h-[44px] px-4 rounded-2xl bg-indigo-600 hover:bg-indigo-500 text-slate-50 text-sm font-semibold">
                                        Проверить
                                    </button>
                                    <button type="submit" name="update_cart" value="1" class="min-h-[44px] px-4 rounded-2xl bg-slate-800 hover:bg-slate-700 border border-slate-700 text-slate-100 text-xs font-semibold">
                                        Применить через форму
                                    </button>
                                </div>
                            </div>
                            <div id="promo-validate-result" class="text-[11px] text-slate-500"></div>
                            <?php if ($promoCodeField !== ''): ?>
                                <div class="rounded-2xl border <?= $cartPromoOk ? 'border-emerald-500/50 bg-emerald-500/10' : 'border-rose-500/40 bg-rose-500/10' ?> px-3 py-3 text-sm">
                                    <div class="<?= $cartPromoOk ? 'text-emerald-100' : 'text-rose-100' ?>">
                                        <?= e($cartPromoMessage !== '' ? $cartPromoMessage : ($cartPromoOk ? 'Промокод применён.' : 'Промокод недоступен.')) ?>
                                    </div>
                                    <?php if ($cartPromoOk && is_array($cartPromoPreview)): ?>
                                        <div class="mt-2 text-[12px] text-slate-300 space-y-1">
                                            <div>Сумма до скидки: <span class="font-medium text-slate-100"><?= number_format((float)($cartPromoPreview['subtotal'] ?? $cartPayableSum), 0, '.', ' ') ?> ₽</span></div>
                                            <div>Скидка: <span class="font-medium text-emerald-200">−<?= number_format((float)($cartPromoPreview['discount_amount'] ?? 0), 0, '.', ' ') ?> ₽</span></div>
                                            <div>К оплате: <span class="font-semibold text-slate-50"><?= number_format((float)($cartPromoPreview['final_total'] ?? $cartPayableSum), 0, '.', ' ') ?> ₽</span></div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($cartUpsellOn): ?>
                        <div id="recommended-with-order-block"
                             class="rounded-2xl border border-amber-500/20 bg-gradient-to-br from-slate-900/95 to-slate-950/90 px-3 py-3 shadow-inner <?= !empty($cartUpsellSuggestions) ? '' : 'hidden' ?>"
                             data-base-item-id="<?= (int)$lastAddedItemId ?>"
                             data-table-id="<?= (int)$tableId ?>">
                            <?php if (!empty($cartUpsellSuggestions)): ?>
                                <div class="text-sm font-semibold text-slate-100 tracking-tight">Рекомендуем добавить</div>
                                <p class="text-[11px] text-slate-500 mt-1 mb-3">Часто берут вместе с текущей корзиной.</p>
                                <div class="flex gap-3 overflow-x-auto pb-1 snap-x snap-mandatory">
                                    <?php foreach ($cartUpsellSuggestions as $s): ?>
                                        <?php $sid = (int)($s['item_id'] ?? $s['id'] ?? 0); ?>
                                        <div class="qr-upsell-card snap-start shrink-0 w-[14rem] overflow-hidden flex flex-col text-[11px] min-w-0">
                                            <?php if (!empty($s['image'])): ?>
                                                <div class="w-full h-[4.5rem] bg-slate-800 flex-shrink-0">
                                                    <img src="<?= e($s['image']) ?>" alt="" loading="lazy" class="w-full h-full object-cover">
                                                </div>
                                            <?php else: ?>
                                                <div class="w-full h-10 bg-slate-800/80 flex items-center justify-center text-[9px] text-slate-500">Без фото</div>
                                            <?php endif; ?>
                                            <div class="px-2.5 py-2 flex flex-col gap-1 flex-1 min-h-0">
                                                <p class="qr-upsell-reason leading-tight line-clamp-2"><?= e((string)($s['reason'] ?? 'Подходит к вашему заказу')) ?></p>
                                                <div class="font-semibold text-slate-100 text-[11px] leading-snug line-clamp-2"><?= e($s['name'] ?? '') ?></div>
                                                <div class="text-emerald-400/95 font-medium"><?= number_format((float)($s['price'] ?? 0), 0, '.', ' ') ?> ₽</div>
                                                <form method="post" class="mt-auto js-upsell-add-form" data-upsell-item-id="<?= $sid ?>" data-base-item-id="<?= (int)$lastAddedItemId ?>">
                                                    <input type="hidden" name="item_id" value="<?= $sid ?>">
                                                    <button type="submit" name="add_item" value="1"
                                                        class="qr-cta-btn w-full min-h-[44px] px-3 py-2.5 rounded-xl bg-emerald-500 hover:bg-emerald-400 text-sm text-slate-950 font-semibold touch-manipulation">
                                                        Добавить к заказу
                                                    </button>
                                                </form>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <div class="flex flex-col sm:flex-row gap-2 sm:gap-3">
                        <button type="submit" name="update_cart" value="1"
                            class="js-cart-update qr-secondary-btn min-h-[48px] px-4 py-3 rounded-2xl text-sm flex-1 sm:flex-none sm:w-44 touch-manipulation">
                            Обновить
                        </button>
                        <button type="submit" name="checkout" value="1"
                            class="qr-cta-btn flex-1 min-h-[48px] px-4 py-3 rounded-2xl bg-emerald-500 hover:bg-emerald-400 text-slate-950 text-base font-semibold touch-manipulation">
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
    <?php if ($qrView === 'menu'): ?>
        <a id="floating-cart-button" class="floating-cart qr-floating-cart-shell inline-flex items-center gap-2 rounded-full font-semibold px-3.5 py-2.5 min-h-[46px]"
           href="<?= e($qrCartLink) ?>">
            <span class="qr-floating-cart-copy">
                <span class="qr-floating-cart-kicker">Ваш заказ</span>
                <span class="qr-floating-cart-title">Корзина</span>
            </span>
            <span id="floating-cart-total" class="qr-floating-cart-total"><?= number_format($cartTotalSum, 0, '.', ' ') ?> ₽</span>
            <span id="floating-cart-count" class="inline-flex items-center justify-center min-w-[22px] h-[22px] px-1 rounded-full bg-slate-950 text-emerald-300 text-xs"><?= (int)$cartTotalQty ?></span>
        </a>
    <?php endif; ?>

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
                        <div id="item-composition-wrap" class="mt-3 hidden">
                            <div class="text-[10px] text-slate-500 uppercase tracking-wide">Состав</div>
                            <div id="item-composition" class="mt-1 rounded-xl bg-slate-900 border border-slate-800 px-3 py-2 text-sm text-slate-200 whitespace-pre-line"></div>
                        </div>
                        <div id="item-allergens-wrap" class="mt-3 hidden">
                            <div class="text-[10px] text-slate-500 uppercase tracking-wide">Аллергены и теги</div>
                            <div id="item-allergens" class="mt-1 rounded-xl bg-slate-900 border border-slate-800 px-3 py-2 text-sm text-amber-200 whitespace-pre-line"></div>
                        </div>
                        <div id="item-kbju" class="mt-3 grid grid-cols-2 sm:grid-cols-4 gap-2">
                            <div class="rounded-xl bg-slate-900 border border-slate-800 px-3 py-2"><div class="text-[10px] text-slate-500">Калории</div><div id="item-calories" class="text-sm text-slate-200 font-semibold">0 ккал</div></div>
                            <div class="rounded-xl bg-slate-900 border border-slate-800 px-3 py-2"><div class="text-[10px] text-slate-500">Белки</div><div id="item-proteins" class="text-sm text-slate-200 font-semibold">0 г</div></div>
                            <div class="rounded-xl bg-slate-900 border border-slate-800 px-3 py-2"><div class="text-[10px] text-slate-500">Жиры</div><div id="item-fats" class="text-sm text-slate-200 font-semibold">0 г</div></div>
                            <div class="rounded-xl bg-slate-900 border border-slate-800 px-3 py-2"><div class="text-[10px] text-slate-500">Углеводы</div><div id="item-carbs" class="text-sm text-slate-200 font-semibold">0 г</div></div>
                        </div>
                        <div id="item-weight-wrap" class="mt-2 hidden">
                            <div class="rounded-xl bg-slate-900 border border-slate-800 px-3 py-2">
                                <div class="text-[10px] text-slate-500 uppercase tracking-wide">Вес</div>
                                <div id="item-weight" class="text-sm text-slate-200 font-semibold">—</div>
                            </div>
                        </div>
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

<!-- Compact upsell after add to cart (menu_upsells from same AJAX response) -->
<div id="one-tap-upsell-bar" class="hidden max-w-3xl mx-auto mt-2 px-3">
    <div class="rounded-3xl border border-amber-500/20 bg-gradient-to-br from-slate-900/95 to-slate-950/95 px-3 py-3 shadow-[0_20px_40px_rgba(2,6,23,0.28)]">
        <div class="flex items-start justify-between gap-2 mb-2">
            <div>
                <div class="text-sm font-bold tracking-tight text-slate-100">Подходит к вашему заказу</div>
                <div class="text-[11px] text-slate-500 mt-0.5">До трёх релевантных идей без лишнего шума.</div>
            </div>
            <button type="button" id="one-tap-upsell-close" class="min-w-[36px] min-h-[36px] rounded-xl bg-slate-800 text-slate-400 hover:text-slate-200 text-sm" aria-label="Закрыть">×</button>
        </div>
        <div id="one-tap-upsell-items" class="grid grid-cols-1 sm:grid-cols-3 gap-2"></div>
    </div>
</div>

<!-- Pre-cart upsell modal: shown before переход в корзину when relevant offers exist -->
<div id="pre-cart-upsell-modal" class="hidden fixed inset-0 z-[70]">
    <div id="pre-cart-upsell-backdrop" class="absolute inset-0 bg-slate-950/75 backdrop-blur-[2px]"></div>
    <div class="relative min-h-full flex items-end sm:items-center justify-center p-3 sm:p-5">
        <div class="w-full max-w-xl rounded-3xl border border-amber-500/35 bg-slate-900/95 shadow-2xl shadow-black/70 overflow-hidden">
            <div class="px-4 py-3 border-b border-slate-700/80 flex items-start justify-between gap-3">
                <div>
                    <div class="text-[11px] uppercase tracking-wide text-amber-300/90">Перед оформлением</div>
                    <h3 class="text-base font-semibold text-slate-100 mt-0.5">Подходит к вашему заказу</h3>
                </div>
                <button type="button" id="pre-cart-upsell-close" class="min-w-[36px] min-h-[36px] rounded-xl bg-slate-800 text-slate-400 hover:text-slate-100">×</button>
            </div>
            <div id="pre-cart-upsell-items" class="p-3 grid grid-cols-1 sm:grid-cols-2 gap-3"></div>
            <div class="px-4 py-3 border-t border-slate-700/80 flex flex-wrap items-center justify-end gap-2">
                <button type="button" id="pre-cart-upsell-skip" class="min-h-[42px] px-4 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-200 text-sm">Не сейчас</button>
                <button type="button" id="pre-cart-upsell-go-cart" class="min-h-[42px] px-4 rounded-xl bg-emerald-500 hover:bg-emerald-400 text-slate-950 font-semibold text-sm">Перейти в корзину</button>
            </div>
        </div>
    </div>
</div>

<script>
(function() {
    const tableId = <?= (int)$tableIdJs ?>;
    const orderId = <?= (int)$orderIdJs ?>;
    const initialCartQty = <?= (int)$cartTotalQty ?>;
    const cartRedirectUrl = <?= json_encode($qrCartLink, JSON_UNESCAPED_UNICODE) ?>;
    const initialCartStateSignature = <?= json_encode($cartStateSignature, JSON_UNESCAPED_UNICODE) ?>;
    const PRE_CART_UPSELL_SEEN_KEY = 'qr_pre_cart_seen_' + String(tableId);
    window.upsellEnabled = <?= $upsellEnabled ? 'true' : 'false' ?>;
    window.menuUpsellGuestOn = <?= $menuUpsellOn ? 'true' : 'false' ?>;
    window.cartUpsellGuestOn = <?= $cartUpsellOn ? 'true' : 'false' ?>;
    window.menuComboGuestOn = <?= $menuComboOn ? 'true' : 'false' ?>;
    window.cartComboGuestOn = <?= $cartComboOn ? 'true' : 'false' ?>;
    window.comboGuestOn = (window.menuComboGuestOn || window.cartComboGuestOn);
    window.upsellGuestOn = (window.menuUpsellGuestOn || window.cartUpsellGuestOn);
    // Split upsell debug: verify independent menu/cart flags passed from backend.
    console.log('[upsell split init]', {
        upsellEnabled: window.upsellEnabled,
        menuUpsellGuestOn: window.menuUpsellGuestOn,
        cartUpsellGuestOn: window.cartUpsellGuestOn,
        menuComboGuestOn: window.menuComboGuestOn,
        cartComboGuestOn: window.cartComboGuestOn
    });
    const UPSELL_ADDED_STORAGE_KEY = 'qr_upsell_added_' + String(tableId);

    function getStoredUpsellAddedIds() {
        try {
            var raw = sessionStorage.getItem(UPSELL_ADDED_STORAGE_KEY) || '[]';
            var parsed = JSON.parse(raw);
            if (!Array.isArray(parsed)) return [];
            return parsed.map(function(v) { return String(parseInt(v, 10) || 0); }).filter(function(v) { return v !== '0'; });
        } catch (e) {
            return [];
        }
    }

    function setStoredUpsellAddedIds(list) {
        var uniq = [];
        var seen = {};
        (Array.isArray(list) ? list : []).forEach(function(v) {
            var id = String(parseInt(v, 10) || 0);
            if (id === '0' || seen[id]) return;
            seen[id] = true;
            uniq.push(id);
        });
        try { sessionStorage.setItem(UPSELL_ADDED_STORAGE_KEY, JSON.stringify(uniq)); } catch (e) {}
        window.upsellAddedIds = uniq;
        return uniq;
    }

    function rememberUpsellAdded(itemId) {
        var id = String(parseInt(itemId, 10) || 0);
        if (!id || id === '0') return;
        var next = getStoredUpsellAddedIds();
        next.push(id);
        setStoredUpsellAddedIds(next);
    }

    function clearRememberedUpsells() {
        try { sessionStorage.removeItem(UPSELL_ADDED_STORAGE_KEY); } catch (e) {}
        window.upsellAddedIds = [];
    }

    function getSeenPreCartSignature() {
        try { return sessionStorage.getItem(PRE_CART_UPSELL_SEEN_KEY) || ''; } catch (e) { return ''; }
    }

    function setSeenPreCartSignature(signature) {
        try { sessionStorage.setItem(PRE_CART_UPSELL_SEEN_KEY, String(signature || '')); } catch (e) {}
    }

    function cartStateSignatureFromItems(items) {
        if (!Array.isArray(items) || !items.length) return '';
        var parts = items.map(function(it) {
            var id = parseInt((it && it.id) || 0, 10) || 0;
            var qty = parseInt((it && it.qty) || 0, 10) || 0;
            if (id <= 0 || qty <= 0) return '';
            return String(id) + ':' + String(qty);
        }).filter(Boolean).sort();
        return parts.join('|');
    }

    window.upsellAddedIds = getStoredUpsellAddedIds();
    window.qrCartStateSignature = initialCartStateSignature;
    if (initialCartQty <= 0) {
        clearRememberedUpsells();
    }

    function qrNotifyUpsellBlockShown(bl) {
        if (!window.upsellEnabled || !window.cartUpsellGuestOn || !bl) return;
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
        if (!window.upsellEnabled || !window.cartUpsellGuestOn) return;
        var bl = document.getElementById('recommended-with-order-block');
        if (!bl) return;
        bl.addEventListener('submit', function(ev) {
            var form = ev.target && ev.target.closest && ev.target.closest('form.js-upsell-add-form');
            if (!form) return;
            ev.preventDefault();
            var upsellId = form.dataset.upsellItemId;
            var baseId = form.dataset.baseItemId;
            if (upsellId) {
                rememberUpsellAdded(upsellId);
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

                var btn = form.querySelector('button[type="submit"]');
                if (btn) {
                    btn.disabled = true;
                    btn.textContent = 'Добавляем...';
                }

                var addFd = new FormData();
                addFd.append('add_item', '1');
                addFd.append('item_id', String(upsellId));
                sendCartRequest(addFd).then(function(d) {
                    if (d) {
                        showOneTapUpsell(d);
                    }
                }).finally(function() {
                    if (btn) {
                        btn.disabled = false;
                        btn.textContent = 'Добавить к заказу';
                    }
                });
            }
        }, true);
    })();

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
        var nextSignature = cartStateSignatureFromItems(Array.isArray(data.items) ? data.items : []);
        if (window.qrCartStateSignature !== nextSignature) {
            setSeenPreCartSignature('');
            window.qrCartStateSignature = nextSignature;
        }
        if (Object.prototype.hasOwnProperty.call(data, 'menu_upsell_guest_on')) {
            window.menuUpsellGuestOn = !!data.menu_upsell_guest_on;
        }
        if (Object.prototype.hasOwnProperty.call(data, 'cart_upsell_guest_on')) {
            window.cartUpsellGuestOn = !!data.cart_upsell_guest_on;
        }
        if (Object.prototype.hasOwnProperty.call(data, 'menu_combo_guest_on')) {
            window.menuComboGuestOn = !!data.menu_combo_guest_on;
        }
        if (Object.prototype.hasOwnProperty.call(data, 'cart_combo_guest_on')) {
            window.cartComboGuestOn = !!data.cart_combo_guest_on;
        }
        window.comboGuestOn = (window.menuComboGuestOn || window.cartComboGuestOn);
        window.upsellGuestOn = (window.menuUpsellGuestOn || window.cartUpsellGuestOn);
        console.log('[upsell split ajax]', {
            menuUpsellGuestOn: window.menuUpsellGuestOn,
            cartUpsellGuestOn: window.cartUpsellGuestOn,
            menuComboGuestOn: window.menuComboGuestOn,
            cartComboGuestOn: window.cartComboGuestOn
        });

        var safeQty = Number.isFinite(Number(data.cartTotalQty)) ? Math.max(0, parseInt(data.cartTotalQty, 10) || 0) : 0;
        var safeSum = Number.isFinite(Number(data.cartTotalSum)) ? Number(data.cartTotalSum) : 0;
        var safeSumFormatted = (typeof data.cartTotalSumFormatted === 'string' && data.cartTotalSumFormatted !== '')
            ? data.cartTotalSumFormatted
            : safeSum.toLocaleString('ru-RU', { maximumFractionDigits: 0 });

        const totalQtyEl  = document.getElementById('cart-block-total-qty');
        const totalSumEl  = document.getElementById('cart-block-total-sum');
        if (totalQtyEl) totalQtyEl.textContent = String(safeQty);
        if (totalSumEl) totalSumEl.textContent = safeSumFormatted + ' ₽';

        const miniBtn   = document.getElementById('mini-cart-button');
        const miniCount = document.getElementById('mini-cart-count');
        const miniTotal = document.getElementById('mini-cart-total');
        const floatingCount = document.getElementById('floating-cart-count');
        const floatingTotal = document.getElementById('floating-cart-total');

        if (miniBtn) {
            if (safeQty > 0) miniBtn.classList.remove('hidden');
            else miniBtn.classList.add('hidden');
        }
        if (miniCount) miniCount.textContent = String(safeQty);
        if (miniTotal) miniTotal.textContent = safeSumFormatted + ' ₽';
        if (floatingCount) floatingCount.textContent = String(safeQty);
        if (floatingTotal) floatingTotal.textContent = safeSumFormatted + ' ₽';

        const list = document.getElementById('cart-items-list');
        const emptyText = document.getElementById('cart-empty-text');
        if (!list || !emptyText) return;

        if (!Array.isArray(data.items) || data.items.length === 0) {
            list.innerHTML = '';
            emptyText.style.display = '';
            clearRememberedUpsells();
            return;
        }

        emptyText.style.display = 'none';

        const itemsHtml = data.items.map(function(item) {
            const id    = parseInt(item.id, 10) || 0;
            const name  = escapeHtml(item.name);
            const price = Number(item.price || 0);
            const qty   = parseInt(item.qty, 10) || 0;
            const sum   = Number(item.sum || 0);
            if (id <= 0 || qty <= 0) {
                return '';
            }
            const priceStr = price.toLocaleString('ru-RU', { maximumFractionDigits: 0 });
            const sumStr   = sum.toLocaleString('ru-RU',   { maximumFractionDigits: 0 });

            return `
<div class="flex items-center justify-between gap-2 rounded-2xl qr-cart-item <?= e($ui['cart_item']) ?> px-3 py-2 text-xs">
  <div class="flex-1 min-w-0">
    <div class="text-slate-100 break-words [overflow-wrap:anywhere]">${name}</div>
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

        if (!itemsHtml.trim()) {
            list.innerHTML = '';
            emptyText.style.display = '';
            clearRememberedUpsells();
            renderSmartUpsellStrip({ cart_upsells: [] });
            return;
        }

        list.innerHTML = itemsHtml;

        renderSmartUpsellStrip(data);
        fetchCartUpsellSuggestionsFromEndpoint(cartItemIdsFromPayload(data)).then(function(remoteItems) {
            if (Array.isArray(remoteItems) && remoteItems.length > 0) {
                renderSmartUpsellStrip(data, remoteItems);
            }
        });
    }

    function cartItemIdsFromPayload(data) {
        if (!data || !Array.isArray(data.items)) return [];
        var ids = [];
        data.items.forEach(function(item) {
            var id = parseInt(item.id, 10) || 0;
            var qty = parseInt(item.qty, 10) || 0;
            if (id > 0 && qty > 0) ids.push(id);
        });
        return ids;
    }

    function selectedOrderTypeForUpsell() {
        var cartForm = document.getElementById('cart-form');
        if (!cartForm) return 'hall';
        var checked = cartForm.querySelector('input[name="order_type"]:checked');
        var raw = checked ? String(checked.value || '') : 'hall';
        var allowed = ['hall', 'delivery', 'pickup', 'preorder', 'manual'];
        return allowed.indexOf(raw) >= 0 ? raw : 'hall';
    }

    function fetchCartUpsellSuggestionsFromEndpoint(currentCartIds) {
        if (!window.upsellEnabled || !window.cartUpsellGuestOn || !tableId) {
            return Promise.resolve([]);
        }
        var orderType = selectedOrderTypeForUpsell();
        var cartTotalEl = document.getElementById('cart-block-total-sum');
        var cartTotalValue = 0;
        if (cartTotalEl) {
            var cleaned = String(cartTotalEl.textContent || '').replace(/[^\d.,-]/g, '').replace(',', '.');
            var parsed = Number(cleaned);
            cartTotalValue = Number.isFinite(parsed) ? parsed : 0;
        }
        var url = '/ajax/upsell_suggestions.php'
            + '?table_id=' + encodeURIComponent(String(tableId))
            + '&mode=cart'
            + '&order_type=' + encodeURIComponent(orderType)
            + '&cart_total=' + encodeURIComponent(String(cartTotalValue))
            + '&upsell_added_count=' + encodeURIComponent(String((window.upsellAddedIds || []).length));
        return fetch(url, { credentials: 'same-origin' })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                var list = (data && data.success && Array.isArray(data.suggestions)) ? data.suggestions : [];
                if (!Array.isArray(currentCartIds) || !currentCartIds.length) return list;
                var inCart = {};
                currentCartIds.forEach(function(id) {
                    var nid = parseInt(id, 10) || 0;
                    if (nid > 0) inCart[nid] = true;
                });
                return list.filter(function(row) {
                    var rid = parseInt(row.item_id || row.id, 10) || 0;
                    return rid > 0 && !inCart[rid];
                });
            })
            .catch(function() {
                return [];
            });
    }

    function renderSmartUpsellStrip(data, fallbackItems) {
        var bl = document.getElementById('recommended-with-order-block');
        if (!bl) return;
        if (!window.cartUpsellGuestOn) {
            bl.classList.add('hidden');
            bl.innerHTML = '';
            return;
        }
        delete bl.dataset.tracked;
        var items = [];
        var cartIds = cartItemIdsFromPayload(data);
        if (data && Array.isArray(data.cart_upsells)) {
            items = data.cart_upsells;
        }
        if ((!items || !items.length) && Array.isArray(fallbackItems)) {
            items = fallbackItems;
        }
        if (Array.isArray(cartIds) && cartIds.length > 0 && Array.isArray(items) && items.length > 0) {
            var inCart = {};
            cartIds.forEach(function(id) {
                var nid = parseInt(id, 10) || 0;
                if (nid > 0) inCart[nid] = true;
            });
            items = items.filter(function(s) {
                var sid = parseInt(s.item_id || s.id, 10) || 0;
                return sid > 0 && !inCart[sid];
            });
        }
        if (!items.length) {
            bl.classList.add('hidden');
            bl.innerHTML = '';
            return;
        }
        bl.classList.remove('hidden');
        bl.dataset.tableId = String(tableId);
        bl.dataset.baseItemId = String((data.last_added_item_id != null ? data.last_added_item_id : 0));
        var maxN = Math.min(3, items.length);
        var head = '<div class="text-sm font-bold text-slate-100 tracking-tight">Рекомендуем добавить</div>'
            + '<p class="text-[11px] text-slate-500 mt-1 mb-3">Часто берут вместе с текущей корзиной.</p>'
            + '<div class="flex gap-3 overflow-x-auto pb-1 snap-x snap-mandatory">';
        var body = items.slice(0, maxN).map(function(s) {
            var id = parseInt(s.item_id || s.id, 10) || 0;
            var name = escapeHtml(s.name || '');
            var reason = escapeHtml(s.reason || 'Подходит к вашему заказу');
            var price = (Number(s.price || 0)).toLocaleString('ru-RU', { maximumFractionDigits: 0 });
            var img = (s.image || '').trim();
            var imgBlock = img
                ? '<div class="w-full h-[4.5rem] bg-slate-800 flex-shrink-0"><img src="' + escapeHtml(img) + '" alt="" loading="lazy" class="w-full h-full object-cover"></div>'
                : '<div class="w-full h-10 bg-slate-800/80 flex items-center justify-center text-[9px] text-slate-500">Без фото</div>';
            return '<div class="qr-upsell-card snap-start shrink-0 w-[14rem] overflow-hidden flex flex-col text-[11px] min-w-0">'
                + imgBlock
                + '<div class="px-2.5 py-2 flex flex-col gap-1 flex-1 min-h-0">'
                + '<p class="qr-upsell-reason leading-tight line-clamp-2">' + reason + '</p>'
                + '<div class="font-semibold text-slate-100 text-[11px] leading-snug line-clamp-2">' + name + '</div>'
                + '<div class="text-emerald-400/95 font-medium">' + price + ' ₽</div>'
                + '<form method="post" class="mt-auto js-upsell-add-form" data-upsell-item-id="' + id + '" data-base-item-id="' + String(bl.dataset.baseItemId || '') + '">'
                + '<input type="hidden" name="item_id" value="' + id + '">'
                + '<button type="submit" name="add_item" value="1" class="qr-cta-btn w-full min-h-[44px] px-3 py-2.5 rounded-xl bg-emerald-500 hover:bg-emerald-400 text-sm text-slate-950 font-semibold touch-manipulation">Добавить к заказу</button>'
                + '</form></div></div>';
        }).join('');
        bl.innerHTML = head + body + '</div>';
        qrNotifyUpsellBlockShown(bl);
    }

    function cartAjaxUrl() {
        try {
            var qs = window.location.search && window.location.search.length > 1
                ? window.location.search.substring(1)
                : '';
            var params = new URLSearchParams(qs);
            params.set('ajax', '1');
            return window.location.pathname + '?' + params.toString();
        } catch (e) {
            return window.location.pathname + '?ajax=1';
        }
    }

    function sendCartRequest(formData) {
        return fetch(cartAjaxUrl(), {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => { updateCartUI(data); return data; })
        .catch(err => { console.error('Cart AJAX error', err); return null; });
    }

    function fetchCartSnapshot() {
        return fetch(cartAjaxUrl(), { method: 'GET', credentials: 'same-origin' })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                updateCartUI(data);
                return data;
            })
            .catch(function(err) {
                console.error('Cart snapshot error', err);
                return null;
            });
    }

    function trackUpsellEvent(eventName, upsellItemId, baseItemId) {
        if (!window.upsellEnabled) return;
        var fd = new FormData();
        fd.append('event', eventName);
        fd.append('table_id', String(tableId));
        if (upsellItemId) fd.append('upsell_item_id', String(upsellItemId));
        if (baseItemId) fd.append('base_item_id', String(baseItemId));
        fetch('/ajax/upsell_event.php', { method: 'POST', body: fd }).catch(function(){});
    }

    var preCartModal = document.getElementById('pre-cart-upsell-modal');
    var preCartBackdrop = document.getElementById('pre-cart-upsell-backdrop');
    var preCartClose = document.getElementById('pre-cart-upsell-close');
    var preCartSkip = document.getElementById('pre-cart-upsell-skip');
    var preCartGoCart = document.getElementById('pre-cart-upsell-go-cart');
    var preCartItems = document.getElementById('pre-cart-upsell-items');
    var preCartPendingUrl = cartRedirectUrl;

    function closePreCartModal() {
        if (!preCartModal) return;
        preCartModal.classList.add('hidden');
        document.body.style.overflow = '';
    }

    function goToCartNow() {
        window.location.href = preCartPendingUrl || cartRedirectUrl;
    }

    function renderPreCartUpsellModal(items, baseItemId) {
        if (!preCartModal || !preCartItems) return;
        var top = items.slice(0, 3);
        preCartItems.innerHTML = top.map(function(s) {
            var id = parseInt(s.item_id || s.id, 10) || 0;
            var name = escapeHtml(s.name || '');
            var reason = escapeHtml(s.reason || 'Рекомендуем к вашему заказу');
            var desc = escapeHtml(s.description || '');
            var price = (Number(s.price || 0)).toLocaleString('ru-RU', { maximumFractionDigits: 0 });
            var img = (s.image || '').trim();
            var imgHtml = img
                ? '<div class="h-16 rounded-xl overflow-hidden bg-slate-800 mb-2"><img src="' + escapeHtml(img) + '" alt="" class="w-full h-full object-cover" loading="lazy"></div>'
                : '<div class="h-16 rounded-xl bg-slate-800/80 mb-2 flex items-center justify-center text-[10px] text-slate-500">Без фото</div>';
            return '<div class="rounded-2xl border border-slate-700/80 bg-slate-950/80 p-2.5 flex flex-col min-h-0">'
                + imgHtml
                + '<div class="text-[11px] text-amber-300/90 line-clamp-2">' + reason + '</div>'
                + '<div class="text-sm font-semibold text-slate-100 line-clamp-2 mt-0.5">' + name + '</div>'
                + (desc ? '<div class="text-[11px] text-slate-500 line-clamp-2 mt-1">' + desc + '</div>' : '')
                + '<div class="text-sm font-semibold text-emerald-400 mt-1">' + price + ' ₽</div>'
                + '<button type="button" class="js-pre-cart-upsell-add mt-2 min-h-[42px] rounded-xl bg-emerald-500 hover:bg-emerald-400 text-slate-950 text-sm font-semibold" data-item-id="' + id + '" data-base-item-id="' + String(baseItemId || '') + '">Добавить</button>'
                + '</div>';
        }).join('');
        preCartModal.classList.remove('hidden');
        document.body.style.overflow = 'hidden';
        trackUpsellEvent('popup_shown');

        preCartItems.querySelectorAll('.js-pre-cart-upsell-add').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var itemId = parseInt(btn.getAttribute('data-item-id') || '0', 10) || 0;
                var baseId = parseInt(btn.getAttribute('data-base-item-id') || '0', 10) || 0;
                if (itemId <= 0) return;
                rememberUpsellAdded(itemId);
                trackUpsellEvent('popup_add_click', itemId, baseId);
                trackUpsellEvent('upsell_clicked', itemId, baseId);
                trackUpsellEvent('upsell_added_to_cart', itemId, baseId);
                var fd = new FormData();
                fd.append('add_item', '1');
                fd.append('item_id', String(itemId));
                sendCartRequest(fd).then(function() {
                    btn.textContent = 'Добавлено ✓';
                    btn.disabled = true;
                    closePreCartModal();
                    goToCartNow();
                });
            });
        });
    }

    function maybeOpenPreCartUpsell(nextUrl) {
        if (!preCartModal || !preCartItems) {
            window.location.href = nextUrl;
            return;
        }
        if (!window.upsellEnabled || !window.cartUpsellGuestOn || !tableId) {
            window.location.href = nextUrl;
            return;
        }
        var sig = window.qrCartStateSignature || '';
        if (!sig) {
            window.location.href = nextUrl;
            return;
        }
        if (getSeenPreCartSignature() === sig) {
            window.location.href = nextUrl;
            return;
        }
        preCartPendingUrl = nextUrl;
        fetchCartSnapshot().then(function(data) {
            var list = (data && Array.isArray(data.cart_upsells)) ? data.cart_upsells : [];
            if (!list.length) {
                setSeenPreCartSignature(sig);
                window.location.href = nextUrl;
                return;
            }
            setSeenPreCartSignature(sig);
            renderPreCartUpsellModal(list, (data && data.last_added_item_id) ? data.last_added_item_id : 0);
        });
    }

    var oneTapUpsellBar = document.getElementById('one-tap-upsell-bar');
    var oneTapUpsellItems = document.getElementById('one-tap-upsell-items');
    var oneTapUpsellClose = document.getElementById('one-tap-upsell-close');
    var UPSELL_THROTTLE_MS = 15000;

    function bindOneTapAddButtons() {
        if (!oneTapUpsellItems) return;
        oneTapUpsellItems.querySelectorAll('.js-one-tap-add').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var itemId = btn.getAttribute('data-item-id');
                if (!itemId) return;
                rememberUpsellAdded(itemId);
                var fd = new FormData();
                fd.append('event', 'add_click');
                fd.append('table_id', String(tableId));
                fd.append('upsell_item_id', String(itemId));
                fetch('/ajax/upsell_event.php', { method: 'POST', body: fd }).catch(function() {});

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
                sendCartRequest(fd).then(function() { oneTapUpsellBar.classList.add('hidden'); });
                btn.textContent = 'Добавлено ✓';
                btn.disabled = true;
            });
        });
    }

    /** Independent split upsell menu-layer: use menu_upsells from current cart state. */
    function showOneTapUpsell(cartData) {
        if (!window.upsellEnabled || !window.menuUpsellGuestOn || !oneTapUpsellBar || !oneTapUpsellItems) return;
        try {
            var last = parseInt(sessionStorage.getItem('qr_upsell_last') || '0', 10);
            if (Date.now() - last < UPSELL_THROTTLE_MS) return;
        } catch (e) {}

        var smart = [];
        if (cartData && Array.isArray(cartData.menu_upsells)) {
            smart = cartData.menu_upsells;
        }
        if (smart.length > 0) {
            var top = smart.slice(0, 3);
            oneTapUpsellItems.innerHTML = top.map(function(s) {
                var id = parseInt(s.item_id || s.id, 10) || 0;
                var name = escapeHtml(s.name || '');
                var reason = escapeHtml(s.reason || '');
                var price = (Number(s.price || 0)).toLocaleString('ru-RU', { maximumFractionDigits: 0 });
                var img = (s.image || '').trim();
                var imgHtml = img
                    ? '<div class="h-16 rounded-xl overflow-hidden bg-slate-800 mb-2"><img src="' + escapeHtml(img) + '" alt="" class="w-full h-full object-cover" loading="lazy"></div>'
                    : '';
                return '<div class="qr-upsell-card p-2 flex flex-col min-h-0">' +
                    imgHtml +
                    '<p class="qr-upsell-reason line-clamp-2 mb-1">' + reason + '</p>' +
                    '<div class="text-[11px] font-semibold text-slate-100 line-clamp-2 flex-1">' + name + '</div>' +
                    '<div class="text-xs text-emerald-400 mt-1">' + price + ' ₽</div>' +
                    '<button type="button" class="js-one-tap-add qr-cta-btn mt-2 min-h-[40px] w-full rounded-xl bg-emerald-500 hover:bg-emerald-400 text-slate-950 text-xs font-semibold touch-manipulation" data-item-id="' + id + '">Добавить к заказу</button>' +
                    '</div>';
            }).join('');
            oneTapUpsellBar.classList.remove('hidden');
            try { sessionStorage.setItem('qr_upsell_last', String(Date.now())); } catch (e2) {}
            bindOneTapAddButtons();
            return;
        }
        oneTapUpsellBar.classList.add('hidden');
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
    const itemCaloriesEl = document.getElementById('item-calories');
    const itemProteinsEl = document.getElementById('item-proteins');
    const itemFatsEl = document.getElementById('item-fats');
    const itemCarbsEl = document.getElementById('item-carbs');
    const itemCompositionWrapEl = document.getElementById('item-composition-wrap');
    const itemCompositionEl = document.getElementById('item-composition');
    const itemAllergensWrapEl = document.getElementById('item-allergens-wrap');
    const itemAllergensEl = document.getElementById('item-allergens');
    const itemWeightWrapEl = document.getElementById('item-weight-wrap');
    const itemWeightEl = document.getElementById('item-weight');
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
        if (itemCaloriesEl) itemCaloriesEl.textContent = String(parseInt(payload.calories || 0, 10) || 0) + ' ккал';
        if (itemProteinsEl) itemProteinsEl.textContent = (Number(payload.proteins || 0).toLocaleString('ru-RU', { maximumFractionDigits: 1 })) + ' г';
        if (itemFatsEl) itemFatsEl.textContent = (Number(payload.fats || 0).toLocaleString('ru-RU', { maximumFractionDigits: 1 })) + ' г';
        if (itemCarbsEl) itemCarbsEl.textContent = (Number(payload.carbs || 0).toLocaleString('ru-RU', { maximumFractionDigits: 1 })) + ' г';
        const comp = (payload.ingredients || '').trim();
        if (itemCompositionWrapEl && itemCompositionEl) {
            if (comp !== '') {
                itemCompositionEl.textContent = comp;
                itemCompositionWrapEl.classList.remove('hidden');
            } else {
                itemCompositionEl.textContent = '';
                itemCompositionWrapEl.classList.add('hidden');
            }
        }

        const al = (payload.allergens || '').trim();
        const tg = (payload.tags || '').trim();
        const allergenLine = [al, tg].filter(Boolean).join(' · ');
        if (itemAllergensWrapEl && itemAllergensEl) {
            if (allergenLine !== '') {
                itemAllergensEl.textContent = allergenLine;
                itemAllergensWrapEl.classList.remove('hidden');
            } else {
                itemAllergensEl.textContent = '';
                itemAllergensWrapEl.classList.add('hidden');
            }
        }

        const w = (payload.weight || '').trim();
        if (itemWeightWrapEl && itemWeightEl) {
            if (w !== '') {
                itemWeightEl.textContent = w;
                itemWeightWrapEl.classList.remove('hidden');
            } else {
                itemWeightEl.textContent = '—';
                itemWeightWrapEl.classList.add('hidden');
            }
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
        sendCartRequest(fd).then(function(d) { showOneTapUpsell(d); });

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
        if (preCartBackdrop) preCartBackdrop.addEventListener('click', closePreCartModal);
        if (preCartClose) preCartClose.addEventListener('click', closePreCartModal);
        if (preCartSkip) preCartSkip.addEventListener('click', function() {
            trackUpsellEvent('popup_dismiss');
            closePreCartModal();
            goToCartNow();
        });
        if (preCartGoCart) preCartGoCart.addEventListener('click', function() {
            trackUpsellEvent('popup_continue_to_cart');
            closePreCartModal();
            goToCartNow();
        });

        if (<?= $qrView === 'menu' ? 'true' : 'false' ?>) {
            var cartLinks = [document.getElementById('mini-cart-button'), document.getElementById('floating-cart-button')];
            cartLinks.forEach(function(link) {
                if (!link) return;
                link.addEventListener('click', function(e) {
                    var href = link.getAttribute('href') || cartRedirectUrl;
                    e.preventDefault();
                    maybeOpenPreCartUpsell(href);
                });
            });
        }

        // add-to-cart по кнопке (как раньше)
        const addForms = document.querySelectorAll('.js-add-to-cart-form');
        addForms.forEach(function(form) {
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                const fd = new FormData(form);
                fd.append('add_item', '1');
                sendCartRequest(fd).then(function(d) { showOneTapUpsell(d); });
            });
        });

        const bookingForm = document.getElementById('guest-booking-form');
        const bookingFeedback = document.getElementById('guest-booking-feedback');
        if (bookingForm) {
            const dateInput = bookingForm.querySelector('input[name="reservation_date"]');
            const timeInput = bookingForm.querySelector('input[name="reservation_time"]');
            if (dateInput && !dateInput.value) {
                const dt = new Date();
                dt.setDate(dt.getDate() + 1);
                dateInput.value = dt.toISOString().slice(0, 10);
            }
            if (timeInput && !timeInput.value) {
                timeInput.value = '19:00';
            }
            bookingForm.addEventListener('submit', function(e) {
                e.preventDefault();
                const submitBtn = bookingForm.querySelector('button[type="submit"]');
                const fd = new FormData(bookingForm);
                if (bookingFeedback) {
                    bookingFeedback.textContent = 'Сохраняем бронь...';
                    bookingFeedback.classList.remove('text-emerald-300', 'text-red-300');
                }
                if (submitBtn) {
                    submitBtn.disabled = true;
                }
                fetch('/ajax/reservation_create.php', {
                    method: 'POST',
                    body: fd,
                    credentials: 'same-origin',
                })
                .then(function(res) { return res.json(); })
                .then(function(data) {
                    if (data && data.success) {
                        if (bookingFeedback) {
                            bookingFeedback.textContent = data.message || 'Бронь создана.';
                            bookingFeedback.classList.remove('text-red-300');
                            bookingFeedback.classList.add('text-emerald-300');
                        }
                        bookingForm.reset();
                        if (dateInput) {
                            const dt = new Date();
                            dt.setDate(dt.getDate() + 1);
                            dateInput.value = dt.toISOString().slice(0, 10);
                        }
                        if (timeInput) {
                            timeInput.value = '19:00';
                        }
                    } else {
                        if (bookingFeedback) {
                            bookingFeedback.textContent = (data && data.message) ? data.message : 'Не удалось создать бронь.';
                            bookingFeedback.classList.remove('text-emerald-300');
                            bookingFeedback.classList.add('text-red-300');
                        }
                    }
                })
                .catch(function() {
                    if (bookingFeedback) {
                        bookingFeedback.textContent = 'Ошибка сети. Попробуйте позже.';
                        bookingFeedback.classList.remove('text-emerald-300');
                        bookingFeedback.classList.add('text-red-300');
                    }
                })
                .finally(function() {
                    if (submitBtn) {
                        submitBtn.disabled = false;
                    }
                });
            });
        }

        const cartForm = document.getElementById('cart-form');
        const cartList = document.getElementById('cart-items-list');
        if (!cartForm) return;
        const promoInput = document.getElementById('promo-code-input');
        const promoValidateBtn = document.getElementById('promo-validate-button');
        const promoValidateResult = document.getElementById('promo-validate-result');

        const orderTypeInputs = cartForm.querySelectorAll('input[name="order_type"]');
        const preorderFields = cartForm.querySelector('.js-preorder-fields');
        const addressFieldWrap = cartForm.querySelector('.js-order-address-field');
        const addressTextarea = addressFieldWrap ? addressFieldWrap.querySelector('textarea[name="delivery_address"]') : null;
        const preorderReceiveSelect = cartForm.querySelector('select[name="preorder_receive_type"]');

        function cartPayloadFromForm() {
            var items = [];
            var qtyInputs = cartForm.querySelectorAll('input[name^="qty["]');
            qtyInputs.forEach(function(inp) {
                var m = String(inp.name || '').match(/^qty\[(\d+)\]$/);
                if (!m) return;
                var id = parseInt(m[1], 10) || 0;
                var qty = parseInt(String(inp.value || '0'), 10) || 0;
                if (id > 0 && qty > 0) {
                    items.push({ id: id, qty: qty });
                }
            });
            return {
                success: true,
                items: items,
                last_added_item_id: 0
            };
        }

        function refreshCartUpsellByEndpoint() {
            if (!window.upsellEnabled || !window.cartUpsellGuestOn) return;
            var payload = cartPayloadFromForm();
            fetchCartUpsellSuggestionsFromEndpoint(cartItemIdsFromPayload(payload)).then(function(remoteItems) {
                renderSmartUpsellStrip(payload, remoteItems);
            });
        }

        function getSelectedOrderType() {
            const checked = cartForm.querySelector('input[name="order_type"]:checked');
            return checked ? String(checked.value || '') : '';
        }

        function updateOrderTypeUi() {
            const selectedType = getSelectedOrderType();
            const receiveType = preorderReceiveSelect ? String(preorderReceiveSelect.value || 'pickup') : 'pickup';
            const isPreorder = selectedType === 'preorder';
            const needsAddress = selectedType === 'delivery' || (isPreorder && receiveType === 'delivery');

            if (preorderFields) {
                preorderFields.classList.toggle('hidden', !isPreorder);
            }
            if (addressFieldWrap) {
                addressFieldWrap.classList.toggle('hidden', !needsAddress);
            }
            if (addressTextarea) {
                addressTextarea.required = needsAddress;
            }
        }

        if (orderTypeInputs.length) {
            orderTypeInputs.forEach(function(inp) {
                inp.addEventListener('change', function() {
                    updateOrderTypeUi();
                    refreshCartUpsellByEndpoint();
                });
            });
        }
        if (preorderReceiveSelect) {
            preorderReceiveSelect.addEventListener('change', function() {
                updateOrderTypeUi();
                refreshCartUpsellByEndpoint();
            });
        }
        updateOrderTypeUi();
        refreshCartUpsellByEndpoint();

        function parseRubFromText(text) {
            var cleaned = String(text || '').replace(/[^\d.,-]/g, '').replace(',', '.');
            var n = Number(cleaned);
            return Number.isFinite(n) ? n : 0;
        }

        function computePromoBaseTotal() {
            var grossEl = document.getElementById('cart-block-total-sum');
            var gross = grossEl ? parseRubFromText(grossEl.textContent || '') : 0;
            if (!Number.isFinite(gross) || gross < 0) gross = 0;

            var spendInput = cartForm.querySelector('input[name="loyalty_points_spend"]');
            if (!spendInput) return gross;

            var spendRequested = Math.max(0, parseInt(String(spendInput.value || '0'), 10) || 0);
            var spendMax = Math.max(0, parseInt(String(spendInput.getAttribute('max') || '0'), 10) || 0);
            var spendApplied = Math.min(spendRequested, spendMax);
            return Math.max(0, gross - spendApplied);
        }

        if (promoValidateBtn && promoInput && promoValidateResult) {
            promoValidateBtn.addEventListener('click', function() {
                var code = String(promoInput.value || '').trim();
                if (!code) {
                    promoValidateResult.textContent = 'Введите промокод для проверки.';
                    promoValidateResult.className = 'text-[11px] text-slate-400';
                    return;
                }

                var fdPromo = new FormData();
                fdPromo.append('code', code);
                fdPromo.append('order_total', String(computePromoBaseTotal()));

                var loyaltyPhone = cartForm.querySelector('input[name="loyalty_phone"]');
                if (loyaltyPhone && String(loyaltyPhone.value || '').trim() !== '') {
                    fdPromo.append('loyalty_phone', String(loyaltyPhone.value || '').trim());
                }
                var deliveryPhone = cartForm.querySelector('input[name="delivery_phone"]');
                if (deliveryPhone && String(deliveryPhone.value || '').trim() !== '') {
                    fdPromo.append('delivery_phone', String(deliveryPhone.value || '').trim());
                }

                promoValidateBtn.disabled = true;
                promoValidateResult.textContent = 'Проверяем промокод...';
                promoValidateResult.className = 'text-[11px] text-slate-400';

                fetch('/ajax/promo_validate.php', {
                    method: 'POST',
                    body: fdPromo,
                    credentials: 'same-origin'
                })
                .then(function(res) { return res.json(); })
                .then(function(data) {
                    if (!data || !data.success) {
                        var failMsg = data && data.message ? String(data.message) : 'Промокод недоступен.';
                        promoValidateResult.textContent = failMsg;
                        promoValidateResult.className = 'text-[11px] text-rose-300';
                        return;
                    }
                    var p = data.preview || {};
                    var discount = Number(p.discount_amount || 0);
                    var finalTotal = Number(p.final_total || 0);
                    promoValidateResult.textContent = 'Скидка: ' + discount.toLocaleString('ru-RU', {maximumFractionDigits: 0}) + ' ₽ · К оплате: ' + finalTotal.toLocaleString('ru-RU', {maximumFractionDigits: 0}) + ' ₽';
                    promoValidateResult.className = 'text-[11px] text-emerald-300';
                })
                .catch(function() {
                    promoValidateResult.textContent = 'Не удалось проверить промокод.';
                    promoValidateResult.className = 'text-[11px] text-rose-300';
                })
                .finally(function() {
                    promoValidateBtn.disabled = false;
                });
            });
        }

        window.upsellAddedIds = window.upsellAddedIds || [];
        cartForm.addEventListener('submit', function(e) {
            var isCheckout = e.submitter && e.submitter.name === 'checkout';
            if (isCheckout) {
                window.upsellAddedIds = getStoredUpsellAddedIds();
            }
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
                    image: card.dataset.image || '',
                    calories: Number(card.dataset.calories || 0),
                    proteins: Number(card.dataset.proteins || 0),
                    fats: Number(card.dataset.fats || 0),
                    carbs: Number(card.dataset.carbs || 0),
                    ingredients: card.dataset.ingredients || '',
                    allergens: card.dataset.allergens || '',
                    weight: card.dataset.weight || '',
                    tags: card.dataset.tags || ''
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
                        image: card.dataset.image || '',
                        calories: Number(card.dataset.calories || 0),
                        proteins: Number(card.dataset.proteins || 0),
                        fats: Number(card.dataset.fats || 0),
                        carbs: Number(card.dataset.carbs || 0),
                        ingredients: card.dataset.ingredients || '',
                        allergens: card.dataset.allergens || '',
                        weight: card.dataset.weight || '',
                        tags: card.dataset.tags || ''
                    };
                    openItemModal(payload);
                }
            });
        });

        // Checkout no longer pauses for a separate upsell modal.
        // Canonical guest flow: one-tap upsell after add-to-cart + contextual block in cart.
    });
})();
</script>
<?php if ($loyaltyEnabled): ?>
<div id="guest-otp-backdrop" class="fixed inset-0 z-[60] hidden bg-black/65 backdrop-blur-[2px]" aria-hidden="true"></div>
<div id="guest-otp-modal" class="fixed inset-x-0 bottom-0 z-[60] hidden px-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] pt-6" role="dialog" aria-modal="true" aria-labelledby="guest-otp-title">
    <div class="mx-auto max-w-md rounded-t-3xl border border-slate-800 bg-slate-950/95 shadow-2xl shadow-black/50 backdrop-blur-xl overflow-hidden">
        <div class="flex items-center justify-between gap-2 px-4 py-3 border-b border-slate-800">
            <div id="guest-otp-title" class="text-sm font-semibold text-slate-100">Вход по номеру</div>
            <button type="button" id="guest-otp-close" class="min-w-[44px] min-h-[44px] rounded-2xl bg-slate-800 text-slate-200 text-lg leading-none" aria-label="Закрыть">×</button>
        </div>
        <div class="p-4 space-y-4">
            <div id="guest-otp-step-phone" class="space-y-2">
                <p class="text-xs text-slate-500">Отправим SMS с кодом (действует 5 минут).</p>
                <label class="block text-[11px] text-slate-400">Телефон</label>
                <input type="tel" id="guest-otp-phone" autocomplete="tel" placeholder="+7 900 000-00-00"
                       class="w-full min-h-[48px] rounded-2xl bg-slate-900 border border-slate-700 px-3 text-base text-slate-50 placeholder:text-slate-600 focus:outline-none focus:ring-2 focus:ring-emerald-500">
                <p id="guest-otp-err-phone" class="text-xs text-red-300 hidden"></p>
                <button type="button" id="guest-otp-send" class="w-full min-h-[48px] rounded-2xl bg-emerald-500 hover:bg-emerald-400 text-slate-950 text-sm font-semibold">Получить код</button>
            </div>
            <div id="guest-otp-step-code" class="space-y-2 hidden">
                <div id="guest-otp-test-box" class="hidden rounded-2xl border border-sky-500/25 bg-sky-500/[0.08] px-3 py-3 space-y-2">
                    <div class="text-[11px] font-semibold text-sky-200/90 tracking-wide">Тестовый код</div>
                    <p id="guest-otp-test-msg" class="text-xs text-sky-100/80 leading-relaxed"></p>
                    <button type="button" id="guest-otp-test-fill" class="hidden w-full min-h-[44px] rounded-xl bg-slate-800/90 border border-slate-600/80 text-xs font-medium text-slate-200 hover:border-sky-500/40">Подставить тестовый код</button>
                </div>
                <p class="text-xs text-slate-500">Введите код из SMS</p>
                <label class="block text-[11px] text-slate-400">Код</label>
                <input type="text" inputmode="numeric" id="guest-otp-code" maxlength="8" autocomplete="one-time-code" placeholder="••••••"
                       class="w-full min-h-[48px] rounded-2xl bg-slate-900 border border-slate-700 px-3 text-lg tracking-widest text-slate-50 text-center focus:outline-none focus:ring-2 focus:ring-emerald-500">
                <p id="guest-otp-err-code" class="text-xs text-red-300 hidden"></p>
                <button type="button" id="guest-otp-verify" class="w-full min-h-[48px] rounded-2xl bg-emerald-500 hover:bg-emerald-400 text-slate-950 text-sm font-semibold">Подтвердить</button>
                <button type="button" id="guest-otp-back" class="w-full text-xs text-slate-500 hover:text-slate-300 py-2">Изменить номер</button>
            </div>
            <div id="guest-otp-step-done" class="hidden text-center py-2 space-y-3">
                <div class="text-2xl" aria-hidden="true">🎉</div>
                <p id="guest-otp-done-msg" class="text-sm text-slate-200"></p>
                <button type="button" id="guest-otp-reload" class="w-full min-h-[48px] rounded-2xl bg-slate-800 border border-slate-700 text-slate-100 text-sm font-medium">Продолжить</button>
            </div>
        </div>
    </div>
</div>
<script>
(function () {
    const openBtn = document.getElementById('guest-otp-open');
    const backdrop = document.getElementById('guest-otp-backdrop');
    const modal = document.getElementById('guest-otp-modal');
    const closeBtn = document.getElementById('guest-otp-close');
    if (!backdrop || !modal) return;

    const stepPhone = document.getElementById('guest-otp-step-phone');
    const stepCode = document.getElementById('guest-otp-step-code');
    const stepDone = document.getElementById('guest-otp-step-done');
    const inpPhone = document.getElementById('guest-otp-phone');
    const inpCode = document.getElementById('guest-otp-code');
    const errPhone = document.getElementById('guest-otp-err-phone');
    const errCode = document.getElementById('guest-otp-err-code');
    const btnSend = document.getElementById('guest-otp-send');
    const btnVerify = document.getElementById('guest-otp-verify');
    const btnBack = document.getElementById('guest-otp-back');
    const doneMsg = document.getElementById('guest-otp-done-msg');
    const btnReload = document.getElementById('guest-otp-reload');
    const testBox = document.getElementById('guest-otp-test-box');
    const testMsg = document.getElementById('guest-otp-test-msg');
    const testFill = document.getElementById('guest-otp-test-fill');

    let lastPhone = '';
    let lastTestCode = '';

    function resetOtpTestUi() {
        lastTestCode = '';
        if (testBox) testBox.classList.add('hidden');
        if (testMsg) testMsg.textContent = '';
        if (testFill) testFill.classList.add('hidden');
    }

    function showErr(el, msg) {
        if (!el) return;
        if (!msg) {
            el.classList.add('hidden');
            el.textContent = '';
            return;
        }
        el.textContent = msg;
        el.classList.remove('hidden');
    }

    function openM() {
        backdrop.classList.remove('hidden');
        modal.classList.remove('hidden');
        document.body.style.overflow = 'hidden';
        stepPhone.classList.remove('hidden');
        stepCode.classList.add('hidden');
        stepDone.classList.add('hidden');
        showErr(errPhone, '');
        showErr(errCode, '');
        resetOtpTestUi();
        if (inpPhone) inpPhone.focus();
    }

    function closeM() {
        backdrop.classList.add('hidden');
        modal.classList.add('hidden');
        document.body.style.overflow = '';
    }

    function mapErr(code) {
        const m = {
            invalid_phone: 'Проверьте формат номера',
            invalid_input: 'Введите номер и код',
            invalid_code: 'Код должен состоять из 6 цифр',
            rate_limited: 'Подождите минуту перед повторной отправкой',
            wrong_code: 'Неверный код',
            expired: 'Код истёк — запросите новый',
            too_many_attempts: 'Слишком много попыток — запросите новый код',
            service_unavailable: 'Сервис временно недоступен',
            internal_error: 'Временная ошибка сервиса. Попробуйте ещё раз',
            no_code: 'Сначала запросите код',
            used: 'Код уже использован — запросите новый'
        };
        return m[code] || 'Не получилось — попробуйте ещё раз';
    }

    if (openBtn) openBtn.addEventListener('click', openM);
    var openCartBtn = document.getElementById('guest-otp-open-cart');
    if (openCartBtn) openCartBtn.addEventListener('click', openM);
    closeBtn && closeBtn.addEventListener('click', closeM);
    backdrop.addEventListener('click', closeM);

    btnSend && btnSend.addEventListener('click', async function () {
        showErr(errPhone, '');
        const phone = inpPhone ? inpPhone.value.trim() : '';
        lastPhone = phone;
        btnSend.disabled = true;
        try {
            const res = await fetch('/guest/send_otp.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({ phone: phone })
            });
            const data = await res.json().catch(function () { return {}; });
            if (!res.ok || !data.success) {
                showErr(errPhone, mapErr(data.error) || ('Ошибка ' + res.status));
                return;
            }
            resetOtpTestUi();
            if (data.test_mode === true && data.test_code) {
                lastTestCode = String(data.test_code);
                if (testMsg) testMsg.textContent = 'Для тестирования используйте код: ' + data.test_code;
                if (testBox) testBox.classList.remove('hidden');
                if (testFill) testFill.classList.remove('hidden');
            }
            stepPhone.classList.add('hidden');
            stepCode.classList.remove('hidden');
            if (inpCode) inpCode.focus();
        } catch (e) {
            showErr(errPhone, 'Нет соединения — попробуйте снова');
        } finally {
            btnSend.disabled = false;
        }
    });

    btnVerify && btnVerify.addEventListener('click', async function () {
        showErr(errCode, '');
        const code = inpCode ? inpCode.value.trim() : '';
        btnVerify.disabled = true;
        try {
            const res = await fetch('/guest/verify_otp.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({ phone: lastPhone || (inpPhone ? inpPhone.value.trim() : ''), code: code })
            });
            const data = await res.json().catch(function () { return {}; });
            if (!res.ok || !data.success) {
                showErr(errCode, mapErr(data.error) || 'Проверьте код');
                return;
            }
            resetOtpTestUi();
            stepPhone.classList.add('hidden');
            stepCode.classList.add('hidden');
            stepDone.classList.remove('hidden');
            const wb = parseInt(data.welcome_bonus || 0, 10) || 0;
            const bal = data.guest && typeof data.guest.loyalty_balance === 'number' ? data.guest.loyalty_balance : null;
            if (wb > 0 && doneMsg) {
                doneMsg.textContent = 'Готово! Вам начислено ' + wb + ' бонусов' + (bal !== null ? ' · Ваш баланс: ' + bal : '');
            } else if (doneMsg) {
                doneMsg.textContent = bal !== null ? ('Готово! Ваш баланс: ' + bal) : 'Готово! Вы вошли — бонусы будут копиться с заказов';
            }
        } catch (e) {
            showErr(errCode, 'Нет соединения');
        } finally {
            btnVerify.disabled = false;
        }
    });

    testFill && testFill.addEventListener('click', function () {
        if (!inpCode || !lastTestCode) return;
        inpCode.value = lastTestCode;
        inpCode.focus();
    });

    btnBack && btnBack.addEventListener('click', function () {
        stepCode.classList.add('hidden');
        stepPhone.classList.remove('hidden');
        showErr(errCode, '');
        resetOtpTestUi();
    });

    btnReload && btnReload.addEventListener('click', function () {
        location.reload();
    });
})();
</script>
<?php endif; ?>
<?php if (is_demo_mode()): ?>
<script>(function(){var k='demo_pages_visited';var v=[];try{v=JSON.parse(sessionStorage.getItem(k)||'[]');}catch(e){}var p=location.pathname;if(v.indexOf(p)===-1){v.push(p);try{sessionStorage.setItem(k,JSON.stringify(v));}catch(e){}}})();</script>
<?php endif; ?>
<!-- loyaltyEnabled=<?= $loyaltyEnabled ? '1' : '0' ?> qrView=<?= htmlspecialchars((string)($qrView ?? ''), ENT_QUOTES, 'UTF-8') ?> guest=<?= $guestSessionUser ? (int)($guestSessionUser['id'] ?? 0) : 0 ?> restaurant_id=<?= !empty($currentRestaurant['id']) ? (int)$currentRestaurant['id'] : 0 ?> -->
</body>
</html>
