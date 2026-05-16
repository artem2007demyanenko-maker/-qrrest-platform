<?php

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/themes.php';
require_once __DIR__ . '/../../app/yandex_review_url_validate.php';
require_once __DIR__ . '/../../app/schema_guard.php';
require_once __DIR__ . '/../../app/crm_repo.php';
if (file_exists(__DIR__ . '/../../app/runtime_schema_bootstrap.php')) {
    require_once __DIR__ . '/../../app/runtime_schema_bootstrap.php';
}

require_login();
require_current_restaurant();
require_restaurant_role((int)($currentRestaurant['id'] ?? 0), ['owner', 'admin']);

$pdo    = db();
$restId = (int)$currentRestaurant['id'];
if (function_exists('runtime_schema_ensure_upsell_rules')) {
    runtime_schema_ensure_upsell_rules($pdo);
}
if (function_exists('runtime_schema_ensure_combo_rules')) {
    runtime_schema_ensure_combo_rules($pdo);
}
if (function_exists('runtime_schema_ensure_crm_core')) {
    runtime_schema_ensure_crm_core($pdo);
}
if (function_exists('runtime_schema_ensure_restaurant_crm_settings')) {
    runtime_schema_ensure_restaurant_crm_settings($pdo);
}
if (function_exists('runtime_schema_ensure_restaurants_guest_upsell_split')) {
    runtime_schema_ensure_restaurants_guest_upsell_split($pdo);
}
if (function_exists('runtime_schema_ensure_restaurants_combo_split')) {
    runtime_schema_ensure_restaurants_combo_split($pdo);
}
$restaurantsNotDeletedSql = '';
if (function_exists('schema_guard_restaurants_deleted_sql')) {
    $restaurantsNotDeletedSql = schema_guard_restaurants_deleted_sql('');
} elseif (function_exists('db_column_exists') && db_column_exists('restaurants', 'deleted_at')) {
    $restaurantsNotDeletedSql = ' AND (deleted_at IS NULL)';
}

if (!function_exists('e')) {
    function e($v): string {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}


function normalize_phone_simple(string $s): string {
    $s = trim($s);
    if ($s === '') return '';
    $s = preg_replace('/[^\d+]/u', '', $s);
    if (preg_match('/^8\d{10}$/', $s)) {
        $s = '+7' . substr($s, 1);
    }
    return $s;
}
function normalize_url_simple(string $s): string {
    $s = trim($s);
    if ($s === '') return '';
    if (!preg_match('~^https?://~i', $s)) {
        $s = 'https://' . $s;
    }
    return $s;
}

$errors  = [];
$success = false;


$qrThemes = qr_get_themes_for_restaurant($pdo, $restId);


$stmt = $pdo->prepare("SELECT * FROM restaurants WHERE id = :id{$restaurantsNotDeletedSql} LIMIT 1");
$stmt->execute(['id' => $restId]);
$restaurantRow = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$restaurantRow) {
    http_response_code(404);
    echo "Restaurant not found";
    exit;
}


$currentName        = (string)$restaurantRow['name'];
$allowCardLaterCur  = isset($restaurantRow['allow_payment_card_later']) ? (int)$restaurantRow['allow_payment_card_later'] : 1;
$allowCashCur       = isset($restaurantRow['allow_payment_cash'])       ? (int)$restaurantRow['allow_payment_cash']       : 1;
$currentTheme       = $restaurantRow['qr_theme'] ?? 'dark_glass';
if (!isset($qrThemes[$currentTheme])) {
    $currentTheme = 'dark_glass';
}
$currentThemeLabel = $qrThemes[$currentTheme]['label'] ?? $currentTheme;

$currentBrandLogoUrl = isset($restaurantRow['brand_logo_url']) ? (string)$restaurantRow['brand_logo_url'] : '';
$currentBrandBannerUrl = isset($restaurantRow['brand_banner_url']) ? (string)$restaurantRow['brand_banner_url'] : '';
$currentBrandAccentColor = isset($restaurantRow['brand_accent_color']) ? (string)$restaurantRow['brand_accent_color'] : '';

if (!function_exists('brand_normalize_hex_color')) {
    function brand_normalize_hex_color(string $v): ?string
    {
        $s = trim($v);
        if ($s === '') return null;
        if (preg_match('~^#[0-9a-fA-F]{6}$~', $s) === 1) return strtoupper($s);
        return null;
    }
}

if (!function_exists('brand_normalize_asset_url')) {
    /**
     * Accept only /storage/brand_logos/* or /storage/brand_banners/* (no remote URLs, no ../).
     * @return ?string path part under /storage/ (no leading slash), e.g. brand_logos/xxx.png
     */
    function brand_normalize_asset_url(string $v): ?string
    {
        $s = trim($v);
        if ($s === '') return null;
        $s = str_replace("\0", '', $s);
        if (strpos($s, '..') !== false) return null;
        if (preg_match('~^https?://~i', $s) === 1) return null;

        $inner = null;
        if (preg_match('~^/storage/(.+)$~', $s, $m) === 1) {
            $inner = $m[1];
        } elseif (preg_match('~^storage/(.+)$~', $s, $m) === 1) {
            $inner = $m[1];
        } elseif (preg_match('~^(brand_logos|brand_banners)/.+$~', $s) === 1) {
            $inner = $s;
        }
        if ($inner === null) return null;
        if (strpos($inner, '..') !== false) return null;
        if (preg_match('~^(brand_logos|brand_banners)/.+$~', $inner) !== 1) return null;
        return $inner;
    }
}

if (!function_exists('brand_delete_storage_file_if_allowed')) {
    /**
     * Delete a file under public storage only for brand_logos / brand_banners paths.
     */
    function brand_delete_storage_file_if_allowed(?string $relativePath): void
    {
        if ($relativePath === null || $relativePath === '') return;
        if (strpos($relativePath, '..') !== false) return;
        if (preg_match('~^(brand_logos|brand_banners)/.+$~', $relativePath) !== 1) return;

        $base = dirname(__DIR__) . '/storage';
        $realBase = realpath($base);
        if ($realBase === false || !is_dir($realBase)) return;

        $full = $base . '/' . str_replace('\\', '/', $relativePath);
        $realFile = realpath($full);
        if ($realFile === false || !is_file($realFile)) return;
        if (strpos($realFile, $realBase) !== 0) return;

        @unlink($realFile);
    }
}

$currentBrandAccentColorNorm = brand_normalize_hex_color($currentBrandAccentColor);

$currentBrandLogoUrlNorm = brand_normalize_asset_url($currentBrandLogoUrl);
$currentBrandBannerUrlNorm = brand_normalize_asset_url($currentBrandBannerUrl);
$brandLogoSrc = $currentBrandLogoUrlNorm ? ('/storage/' . $currentBrandLogoUrlNorm) : '';
$brandBannerSrc = $currentBrandBannerUrlNorm ? ('/storage/' . $currentBrandBannerUrlNorm) : '';
$brandAccentPickerValue = $currentBrandAccentColorNorm !== null ? $currentBrandAccentColorNorm : '#22C55E';
$brandAccentColorSet = $currentBrandAccentColorNorm !== null ? 1 : 0;

if (!function_exists('brand_upload_asset_file')) {
    /**
     * @return ?string path under storage (no leading slash), e.g. brand_logos/xxx.png
     */
    function brand_upload_asset_file(array $file, string $subdir): ?string
    {
        if (!isset($file['tmp_name'], $file['error']) || !isset($file['size'])) {
            return null;
        }
        if ((int)$file['error'] !== UPLOAD_ERR_OK) {
            // UPLOAD_ERR_NO_FILE is common when input is present but user didn't choose.
            return null;
        }

        $tmpFile = (string)$file['tmp_name'];
        if ($tmpFile === '') return null;

        $size = (int)$file['size'];
        if ($size <= 0 || $size > 2 * 1024 * 1024) {
            return null;
        }

        $allowedMime = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
        ];

        // Strong MIME detection: finfo(FILEINFO_MIME_TYPE) primary.
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo ? (string)$finfo->file($tmpFile) : '';
        if (!isset($allowedMime[$mime])) {
            return null;
        }

        // Second validation layer: must be a real image.
        $imgInfo = @getimagesize($tmpFile);
        if ($imgInfo === false) {
            return null;
        }
        $w = (int)($imgInfo[0] ?? 0);
        $h = (int)($imgInfo[1] ?? 0);
        if ($w <= 0 || $h <= 0 || $w > 2000 || $h > 2000) {
            return null;
        }

        // Safe output bounds (no upscaling).
        $maxW = $subdir === 'brand_logos' ? 400 : 1200;
        $maxH = $subdir === 'brand_logos' ? 200 : 400;

        $ext = $allowedMime[$mime];
        $dir = function_exists('ensure_storage_subdir') ? ensure_storage_subdir($subdir) : null;
        if (!$dir) return null;

        // Decode image with GD.
        switch ($mime) {
            case 'image/jpeg':
                $im = @imagecreatefromjpeg($tmpFile);
                break;
            case 'image/png':
                $im = @imagecreatefrompng($tmpFile);
                break;
            case 'image/webp':
                $im = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($tmpFile) : false;
                break;
            default:
                $im = false;
        }
        if (!$im) return null;

        // Normalize orientation for JPEG using EXIF Orientation (best-effort, fail-safe).
        if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
            $orientation = null;
            try {
                $exif = @exif_read_data($tmpFile);
                if (is_array($exif) && isset($exif['Orientation'])) {
                    $orientation = (int)$exif['Orientation'];
                }
            } catch (Throwable $e) {
                $orientation = null;
            }

            if ($orientation !== null && $orientation > 1) {
                // Handle common rotations; flip operations only when supported.
                switch ($orientation) {
                    case 2: // Mirror horizontal
                        if (function_exists('imageflip') && defined('IMG_FLIP_HORIZONTAL')) @imageflip($im, IMG_FLIP_HORIZONTAL);
                        break;
                    case 3: // Rotate 180
                        $rot = @imagerotate($im, 180, 0);
                        if ($rot !== false) {
                            imagedestroy($im);
                            $im = $rot;
                        }
                        break;
                    case 4: // Mirror vertical
                        if (function_exists('imageflip') && defined('IMG_FLIP_VERTICAL')) @imageflip($im, IMG_FLIP_VERTICAL);
                        break;
                    case 5: // Mirror horizontal then rotate 270
                        if (function_exists('imageflip') && defined('IMG_FLIP_HORIZONTAL')) @imageflip($im, IMG_FLIP_HORIZONTAL);
                        $rot = @imagerotate($im, -90, 0);
                        if ($rot !== false) {
                            imagedestroy($im);
                            $im = $rot;
                        }
                        break;
                    case 6: // Rotate 270 CW (iPhone common)
                        $rot = @imagerotate($im, -90, 0);
                        if ($rot !== false) {
                            imagedestroy($im);
                            $im = $rot;
                        }
                        break;
                    case 7: // Mirror horizontal then rotate 90
                        if (function_exists('imageflip') && defined('IMG_FLIP_HORIZONTAL')) @imageflip($im, IMG_FLIP_HORIZONTAL);
                        $rot = @imagerotate($im, 90, 0);
                        if ($rot !== false) {
                            imagedestroy($im);
                            $im = $rot;
                        }
                        break;
                    case 8: // Rotate 90 CCW
                        $rot = @imagerotate($im, 90, 0);
                        if ($rot !== false) {
                            imagedestroy($im);
                            $im = $rot;
                        }
                        break;
                }
            }
        }

        $srcW = (int)@imagesx($im);
        $srcH = (int)@imagesy($im);
        if ($srcW <= 0 || $srcH <= 0) {
            imagedestroy($im);
            return null;
        }

        // Resize (preserve aspect ratio) with no upscaling.
        $scaleW = $maxW / $srcW;
        $scaleH = $maxH / $srcH;
        $scale = min($scaleW, $scaleH, 1.0);
        if (!is_finite($scale) || $scale <= 0) {
            imagedestroy($im);
            return null;
        }

        $outW = (int)max(1, round($srcW * $scale));
        $outH = (int)max(1, round($srcH * $scale));
        if ($outW <= 0 || $outH <= 0) {
            imagedestroy($im);
            return null;
        }

        $dst = $im;
        $resized = false;
        if ($outW !== $srcW || $outH !== $srcH) {
            $dst = @imagecreatetruecolor($outW, $outH);
            if (!$dst) {
                imagedestroy($im);
                return null;
            }

            // Preserve transparency for PNG/WebP.
            if ($mime === 'image/png' || $mime === 'image/webp') {
                imagealphablending($dst, false);
                imagesavealpha($dst, true);
                $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
                imagefilledrectangle($dst, 0, 0, $outW, $outH, $transparent);
            }

            $ok = @imagecopyresampled($dst, $im, 0, 0, 0, 0, $outW, $outH, $srcW, $srcH);
            if (!$ok) {
                imagedestroy($dst);
                imagedestroy($im);
                return null;
            }
            $resized = true;
        }

        $name = bin2hex(random_bytes(8)) . '.' . $ext;
        $fullPath = rtrim($dir, '/') . '/' . $name;

        // Encode back to a safe, compressed image.
        $saved = false;
        if ($mime === 'image/jpeg') {
            $saved = @imagejpeg($dst, $fullPath, 86);
        } elseif ($mime === 'image/png') {
            $saved = @imagepng($dst, $fullPath, 7);
        } elseif ($mime === 'image/webp') {
            $saved = function_exists('imagewebp') ? @imagewebp($dst, $fullPath, 84) : false;
        }

        if (!$saved || !is_file($fullPath)) {
            if ($resized) @imagedestroy($dst);
            @imagedestroy($im);
            return null;
        }

        if ($resized) @imagedestroy($dst);
        @imagedestroy($im);

        return $subdir . '/' . $name;
    }
}

// For QR menu preview. Uses first available table to avoid 404s.
$previewTableId = 0;
if (!is_demo_mode()) {
    try {
        $tstmt = $pdo->prepare("
            SELECT t.id FROM tables AS t
            WHERE t.restaurant_id = :rest
            " . qr_public_sql_exclude_delivery($pdo, 't') . "
            ORDER BY t.id ASC LIMIT 1
        ");
        $tstmt->execute(['rest' => $restId]);
        $previewTableId = (int)($tstmt->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        $previewTableId = 0;
    }
}


$currentPhone       = (string)($restaurantRow['phone'] ?? '');
$currentAddress     = (string)($restaurantRow['address'] ?? '');
$currentWebsite     = (string)($restaurantRow['website'] ?? '');
$currentInstagram   = (string)($restaurantRow['instagram'] ?? '');
$currentGoogleReviewLink = (string)($restaurantRow['google_review_link'] ?? '');
$currentYandexReviewUrl = (string)($restaurantRow['yandex_review_url'] ?? '');
$currentWorkHours   = (string)($restaurantRow['work_hours'] ?? '');
$currentDescription = (string)($restaurantRow['description'] ?? '');

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfOk = isset($_POST['csrf'], $_SESSION['csrf']) && hash_equals((string)$_SESSION['csrf'], (string)$_POST['csrf']);

    if (!empty($_POST['save_crm_return'])) {
        if (!$csrfOk) {
            $errors[] = 'Неверный запрос. Обновите страницу и попробуйте снова.';
        } elseif (function_exists('is_demo_mode') && is_demo_mode()) {
            $errors[] = 'В демо-режиме сохранение настроек CRM отключено.';
        } else {
            $crmDays = (int)($_POST['inactive_return_days'] ?? 14);
            $crmEn = isset($_POST['crm_return_enabled']);
            if ($crmDays < 7 || $crmDays > 90) {
                $errors[] = 'Укажите период от 7 до 90 дней.';
            } elseif (!function_exists('db_table_exists') || !db_table_exists('restaurant_crm_settings')) {
                $errors[] = 'Расширенные настройки CRM пока недоступны. Обновите модуль CRM и повторите попытку.';
            } elseif (!function_exists('crm_upsert_restaurant_crm_settings') || !crm_upsert_restaurant_crm_settings($restId, $crmEn, $crmDays)) {
                $errors[] = 'Не удалось сохранить настройки CRM.';
            } else {
                header('Location: /restaurant/settings.php?crm_saved=1');
                exit;
            }
        }
    } elseif (!empty($_POST['save_smart_upsell_layers'])) {
        if (!$csrfOk) {
            $errors[] = 'Неверный запрос. Обновите страницу и попробуйте снова.';
        } elseif (function_exists('is_demo_mode') && is_demo_mode()) {
            $errors[] = 'В демо-режиме сохранение отключено.';
        } else {
            $menuEn = isset($_POST['guest_menu_upsell_enabled']) ? 1 : 0;
            $menuMx = max(1, min(2, (int)($_POST['guest_menu_upsell_limit'] ?? 1)));
            $menuManualOnly = isset($_POST['guest_menu_upsell_manual_only']) ? 1 : 0;
            $menuComboEn = isset($_POST['guest_menu_combo_enabled']) ? 1 : 0;
            $menuComboMx = max(1, min(2, (int)($_POST['guest_menu_combo_limit'] ?? 1)));

            $cartEn = isset($_POST['guest_cart_upsell_enabled']) ? 1 : 0;
            $cartMx = max(1, min(3, (int)($_POST['guest_cart_upsell_limit'] ?? 3)));
            $cartUseManual = isset($_POST['guest_cart_upsell_use_manual']) ? 1 : 0;
            $cartUseContextual = isset($_POST['guest_cart_upsell_use_contextual']) ? 1 : 0;
            $cartUsePopular = isset($_POST['guest_cart_upsell_use_popular']) ? 1 : 0;
            $cartComboEn = isset($_POST['guest_cart_combo_enabled']) ? 1 : 0;
            $cartComboMx = max(1, min(3, (int)($_POST['guest_cart_combo_limit'] ?? 2)));

            $hasLegacyEn = function_exists('db_column_exists') && db_column_exists('restaurants', 'smart_upsell_guest_enabled');
            $hasLegacyMax = function_exists('db_column_exists') && db_column_exists('restaurants', 'smart_upsell_guest_max');

            $hasMenuEn = function_exists('db_column_exists') && db_column_exists('restaurants', 'guest_menu_upsell_enabled');
            $hasMenuMax = function_exists('db_column_exists') && db_column_exists('restaurants', 'guest_menu_upsell_limit');
            $hasMenuManualOnly = function_exists('db_column_exists') && db_column_exists('restaurants', 'guest_menu_upsell_manual_only');
            $hasMenuComboEn = function_exists('db_column_exists') && db_column_exists('restaurants', 'guest_menu_combo_enabled');
            $hasMenuComboMax = function_exists('db_column_exists') && db_column_exists('restaurants', 'guest_menu_combo_limit');

            $hasCartEn = function_exists('db_column_exists') && db_column_exists('restaurants', 'guest_cart_upsell_enabled');
            $hasCartMax = function_exists('db_column_exists') && db_column_exists('restaurants', 'guest_cart_upsell_limit');
            $hasCartUseManual = function_exists('db_column_exists') && db_column_exists('restaurants', 'guest_cart_upsell_use_manual');
            $hasCartUseContextual = function_exists('db_column_exists') && db_column_exists('restaurants', 'guest_cart_upsell_use_contextual');
            $hasCartUsePopular = function_exists('db_column_exists') && db_column_exists('restaurants', 'guest_cart_upsell_use_popular');
            $hasCartComboEn = function_exists('db_column_exists') && db_column_exists('restaurants', 'guest_cart_combo_enabled');
            $hasCartComboMax = function_exists('db_column_exists') && db_column_exists('restaurants', 'guest_cart_combo_limit');

            $hasSplitSmartMenuEn = function_exists('db_column_exists') && db_column_exists('restaurants', 'smart_upsell_menu_enabled');
            $hasSplitSmartMenuMax = function_exists('db_column_exists') && db_column_exists('restaurants', 'smart_upsell_menu_max');
            $hasSplitSmartMenuManualOnly = function_exists('db_column_exists') && db_column_exists('restaurants', 'smart_upsell_menu_manual_only');
            $hasSplitSmartCartEn = function_exists('db_column_exists') && db_column_exists('restaurants', 'smart_upsell_cart_enabled');
            $hasSplitSmartCartMax = function_exists('db_column_exists') && db_column_exists('restaurants', 'smart_upsell_cart_max');
            $hasSplitSmartCartManual = function_exists('db_column_exists') && db_column_exists('restaurants', 'smart_upsell_cart_use_manual');
            $hasSplitSmartCartContextual = function_exists('db_column_exists') && db_column_exists('restaurants', 'smart_upsell_cart_use_contextual');
            $hasSplitSmartCartPopular = function_exists('db_column_exists') && db_column_exists('restaurants', 'smart_upsell_cart_use_popular');

            $setSql = [];
            $params = ['id' => $restId];
            if ($hasMenuEn) {
                $setSql[] = 'guest_menu_upsell_enabled = :menu_en';
                $params['menu_en'] = $menuEn;
            }
            if ($hasMenuMax) {
                $setSql[] = 'guest_menu_upsell_limit = :menu_mx';
                $params['menu_mx'] = $menuMx;
            }
            if ($hasMenuManualOnly) {
                $setSql[] = 'guest_menu_upsell_manual_only = :menu_manual_only';
                $params['menu_manual_only'] = $menuManualOnly;
            }
            if ($hasMenuComboEn) {
                $setSql[] = 'guest_menu_combo_enabled = :menu_combo_en';
                $params['menu_combo_en'] = $menuComboEn;
            }
            if ($hasMenuComboMax) {
                $setSql[] = 'guest_menu_combo_limit = :menu_combo_mx';
                $params['menu_combo_mx'] = $menuComboMx;
            }
            if ($hasCartEn) {
                $setSql[] = 'guest_cart_upsell_enabled = :cart_en';
                $params['cart_en'] = $cartEn;
            }
            if ($hasCartMax) {
                $setSql[] = 'guest_cart_upsell_limit = :cart_mx';
                $params['cart_mx'] = $cartMx;
            }
            if ($hasCartUseManual) {
                $setSql[] = 'guest_cart_upsell_use_manual = :cart_manual';
                $params['cart_manual'] = $cartUseManual;
            }
            if ($hasCartUseContextual) {
                $setSql[] = 'guest_cart_upsell_use_contextual = :cart_contextual';
                $params['cart_contextual'] = $cartUseContextual;
            }
            if ($hasCartUsePopular) {
                $setSql[] = 'guest_cart_upsell_use_popular = :cart_popular';
                $params['cart_popular'] = $cartUsePopular;
            }
            if ($hasCartComboEn) {
                $setSql[] = 'guest_cart_combo_enabled = :cart_combo_en';
                $params['cart_combo_en'] = $cartComboEn;
            }
            if ($hasCartComboMax) {
                $setSql[] = 'guest_cart_combo_limit = :cart_combo_mx';
                $params['cart_combo_mx'] = $cartComboMx;
            }

            // Keep previously introduced split smart_* columns in sync if present.
            if ($hasSplitSmartMenuEn) {
                $setSql[] = 'smart_upsell_menu_enabled = :split_smart_menu_en';
                $params['split_smart_menu_en'] = $menuEn;
            }
            if ($hasSplitSmartMenuMax) {
                $setSql[] = 'smart_upsell_menu_max = :split_smart_menu_mx';
                $params['split_smart_menu_mx'] = $menuMx;
            }
            if ($hasSplitSmartMenuManualOnly) {
                $setSql[] = 'smart_upsell_menu_manual_only = :split_smart_menu_manual';
                $params['split_smart_menu_manual'] = $menuManualOnly;
            }
            if ($hasSplitSmartCartEn) {
                $setSql[] = 'smart_upsell_cart_enabled = :split_smart_cart_en';
                $params['split_smart_cart_en'] = $cartEn;
            }
            if ($hasSplitSmartCartMax) {
                $setSql[] = 'smart_upsell_cart_max = :split_smart_cart_mx';
                $params['split_smart_cart_mx'] = $cartMx;
            }
            if ($hasSplitSmartCartManual) {
                $setSql[] = 'smart_upsell_cart_use_manual = :split_smart_cart_manual';
                $params['split_smart_cart_manual'] = $cartUseManual;
            }
            if ($hasSplitSmartCartContextual) {
                $setSql[] = 'smart_upsell_cart_use_contextual = :split_smart_cart_contextual';
                $params['split_smart_cart_contextual'] = $cartUseContextual;
            }
            if ($hasSplitSmartCartPopular) {
                $setSql[] = 'smart_upsell_cart_use_popular = :split_smart_cart_popular';
                $params['split_smart_cart_popular'] = $cartUsePopular;
            }

            // Keep legacy single-layer fields in sync for old screens/fallback runtime.
            if ($hasLegacyEn) {
                $setSql[] = 'smart_upsell_guest_enabled = :legacy_en';
                $params['legacy_en'] = (($menuEn === 1 || $cartEn === 1) ? 1 : 0);
            }
            if ($hasLegacyMax) {
                $setSql[] = 'smart_upsell_guest_max = :legacy_mx';
                $params['legacy_mx'] = max($menuMx, $cartMx);
            }

            if ($setSql === []) {
                $errors[] = 'Для управления допродажами требуется обновление структуры данных.';
            }

            if ($errors === []) {
            try {
                $u = $pdo->prepare("UPDATE restaurants SET " . implode(', ', $setSql) . ", updated_at = NOW() WHERE id = :id{$restaurantsNotDeletedSql} LIMIT 1");
                $u->execute($params);
                header('Location: /restaurant/settings.php?smart_upsell_saved=1#smart-upsell-layers');
                exit;
            } catch (Throwable $e) {
                $errors[] = 'Не удалось сохранить настройки умных допродаж.';
            }
            }
        }
    } elseif (!$csrfOk) {
        $errors[] = 'Неверный запрос. Обновите страницу и попробуйте снова.';
    } else {
    $name = trim($_POST['name'] ?? $currentName);

    $allowCardLater = isset($_POST['allow_card_later']) ? 1 : 0;
    $allowCash      = isset($_POST['allow_cash'])       ? 1 : 0;

    $qrThemePost    = $_POST['qr_theme'] ?? $currentTheme;

    // Branding fields (visual only): optional logo/banner and accent color.
    $brandLogoUrlPost = $currentBrandLogoUrl;
    $brandBannerUrlPost = $currentBrandBannerUrl;

    $brandLogoUrlPostNorm = brand_normalize_asset_url((string)$brandLogoUrlPost);
    $brandBannerUrlPostNorm = brand_normalize_asset_url((string)$brandBannerUrlPost);
    $brandLogoUrlPostToSave = $brandLogoUrlPostNorm;
    $brandBannerUrlPostToSave = $brandBannerUrlPostNorm;
    $brandLogoFileUploaded = false;
    $brandBannerFileUploaded = false;

    // Lightweight upload-abuse protection: per restaurant + per asset type.
    $brandUploadRateLimitWindowSec = 10;
    if (!isset($_SESSION['brand_upload_ts']) || !is_array($_SESSION['brand_upload_ts'])) {
        $_SESSION['brand_upload_ts'] = [];
    }
    if (!isset($_SESSION['brand_upload_ts'][$restId]) || !is_array($_SESSION['brand_upload_ts'][$restId])) {
        $_SESSION['brand_upload_ts'][$restId] = ['logo' => 0, 'banner' => 0];
    }

    if (!empty($_FILES['brand_logo_file']) && is_array($_FILES['brand_logo_file'])) {
        $logoErr = isset($_FILES['brand_logo_file']['error']) ? (int)$_FILES['brand_logo_file']['error'] : UPLOAD_ERR_NO_FILE;
        $logoOkAttempt = $logoErr === UPLOAD_ERR_OK;
        if ($logoOkAttempt) {
            $lastTs = (int)($_SESSION['brand_upload_ts'][$restId]['logo'] ?? 0);
            if (time() - $lastTs < $brandUploadRateLimitWindowSec) {
                $errors[] = 'Слишком часто загружаете логотип. Подождите несколько секунд и попробуйте снова.';
            } else {
                $uploaded = brand_upload_asset_file($_FILES['brand_logo_file'], 'brand_logos');
                if ($uploaded !== null) {
                    $brandLogoUrlPostToSave = $uploaded;
                    $brandLogoFileUploaded = true;
                    $_SESSION['brand_upload_ts'][$restId]['logo'] = time();
                } else {
                    $errors[] = 'Некорректный файл логотипа меню (формат, размер файла, размеры изображения или не изображение).';
                }
            }
        } else {
            $uploaded = brand_upload_asset_file($_FILES['brand_logo_file'], 'brand_logos');
            if ($uploaded !== null) {
                $brandLogoUrlPostToSave = $uploaded;
                $brandLogoFileUploaded = true;
            } else {
                if (isset($_FILES['brand_logo_file']['error']) && (int)$_FILES['brand_logo_file']['error'] !== UPLOAD_ERR_NO_FILE) {
                    $errors[] = 'Некорректный файл логотипа меню (формат, размер файла, размеры изображения или не изображение).';
                }
            }
        }
    }

    if (!empty($_FILES['brand_banner_file']) && is_array($_FILES['brand_banner_file'])) {
        $bannerErr = isset($_FILES['brand_banner_file']['error']) ? (int)$_FILES['brand_banner_file']['error'] : UPLOAD_ERR_NO_FILE;
        $bannerOkAttempt = $bannerErr === UPLOAD_ERR_OK;
        if ($bannerOkAttempt) {
            $lastTs = (int)($_SESSION['brand_upload_ts'][$restId]['banner'] ?? 0);
            if (time() - $lastTs < $brandUploadRateLimitWindowSec) {
                $errors[] = 'Слишком часто загружаете баннер. Подождите несколько секунд и попробуйте снова.';
            } else {
                $uploaded = brand_upload_asset_file($_FILES['brand_banner_file'], 'brand_banners');
                if ($uploaded !== null) {
                    $brandBannerUrlPostToSave = $uploaded;
                    $brandBannerFileUploaded = true;
                    $_SESSION['brand_upload_ts'][$restId]['banner'] = time();
                } else {
                    $errors[] = 'Некорректный файл баннера меню (формат, размер файла, размеры изображения или не изображение).';
                }
            }
        } else {
            $uploaded = brand_upload_asset_file($_FILES['brand_banner_file'], 'brand_banners');
            if ($uploaded !== null) {
                $brandBannerUrlPostToSave = $uploaded;
                $brandBannerFileUploaded = true;
            } else {
                if (isset($_FILES['brand_banner_file']['error']) && (int)$_FILES['brand_banner_file']['error'] !== UPLOAD_ERR_NO_FILE) {
                    $errors[] = 'Некорректный файл баннера меню (формат, размер файла, размеры изображения или не изображение).';
                }
            }
        }
    }

    $brandLogoUrlInPost = isset($_POST['brand_logo_url']) ? (string)$_POST['brand_logo_url'] : '';
    $brandBannerUrlInPost = isset($_POST['brand_banner_url']) ? (string)$_POST['brand_banner_url'] : '';
    if (!$brandLogoFileUploaded && isset($_POST['brand_logo_url'])) {
        $norm = brand_normalize_asset_url($brandLogoUrlInPost);
        if ($brandLogoUrlInPost !== '' && $norm === null) {
            $errors[] = 'Некорректный URL логотипа (используйте /storage/... или внутренний путь).';
        } else {
            $brandLogoUrlPostToSave = $norm;
        }
    }
    if (!$brandBannerFileUploaded && isset($_POST['brand_banner_url'])) {
        $norm = brand_normalize_asset_url($brandBannerUrlInPost);
        if ($brandBannerUrlInPost !== '' && $norm === null) {
            $errors[] = 'Некорректный URL баннера (используйте /storage/... или внутренний путь).';
        } else {
            $brandBannerUrlPostToSave = $norm;
        }
    }

    $brandAccentColorSet = isset($_POST['brand_accent_color_set']) ? (string)$_POST['brand_accent_color_set'] : '0';
    $brandAccentColorToSave = null;
    if ($brandAccentColorSet === '1') {
        $brandAccentColorPost = isset($_POST['brand_accent_color']) ? (string)$_POST['brand_accent_color'] : '';
        $brandAccentColorPostNorm = brand_normalize_hex_color($brandAccentColorPost);
        if ($brandAccentColorPost !== '' && $brandAccentColorPostNorm === null) {
            $errors[] = 'Некорректный акцентный цвет (ожидается #RRGGBB).';
        } else {
            $brandAccentColorToSave = $brandAccentColorPostNorm;
        }
    }

    // контакты
    $phone       = normalize_phone_simple((string)($_POST['phone'] ?? $currentPhone));
    $address     = trim((string)($_POST['address'] ?? $currentAddress));
    $website     = trim((string)($_POST['website'] ?? $currentWebsite));
    $instagram   = trim((string)($_POST['instagram'] ?? $currentInstagram));
    $googleReviewLink = trim((string)($_POST['google_review_link'] ?? $currentGoogleReviewLink));
    $yandexReviewUrl = trim((string)($_POST['yandex_review_url'] ?? $currentYandexReviewUrl));
    $workHours   = trim((string)($_POST['work_hours'] ?? $currentWorkHours));
    $description = trim((string)($_POST['description'] ?? $currentDescription));

    if ($name === '') {
        $errors[] = 'Название ресторана не может быть пустым.';
    }


    if (!$allowCardLater && !$allowCash) {
        $errors[] = 'Нужно включить хотя бы один способ оплаты.';
    }

    if (!isset($qrThemes[$qrThemePost])) {
        $errors[] = 'Выбрана некорректная тема меню.';
    }


    if ($website !== '') {
        $website = normalize_url_simple($website);
        if (!filter_var($website, FILTER_VALIDATE_URL)) {
            $errors[] = 'Некорректный URL сайта.';
        }
    }

    if ($instagram !== '') {
        if ($instagram[0] === '@') {
            $username  = ltrim($instagram, '@');
            $instagram = 'https://instagram.com/' . $username;
        } else {
            $instagram = normalize_url_simple($instagram);
        }
        if (!filter_var($instagram, FILTER_VALIDATE_URL)) {
            $errors[] = 'Некорректная ссылка на соцсеть (Instagram).';
        }
    }

    if ($googleReviewLink !== '') {
        $googleReviewLink = normalize_url_simple($googleReviewLink);
        if (!filter_var($googleReviewLink, FILTER_VALIDATE_URL)) {
            $errors[] = 'Некорректная ссылка на отзывы Google.';
        }
    }
    if ($yandexReviewUrl !== '') {
        $yandexReviewUrl = normalize_yandex_maps_review_url($yandexReviewUrl);
        if (!yandex_maps_review_url_is_valid($yandexReviewUrl)) {
            $errors[] = 'Укажите корректную ссылку на Яндекс.Карты';
        }
    }
    if (mb_strlen($address) > 255)     $errors[] = 'Адрес слишком длинный (макс 255).';
    if (mb_strlen($phone) > 64)        $errors[] = 'Телефон слишком длинный (макс 64).';
    if (mb_strlen($googleReviewLink) > 512) $errors[] = 'Ссылка Google слишком длинная (макс 512).';
    if (mb_strlen($yandexReviewUrl) > 512) $errors[] = 'Ссылка Яндекс слишком длинная (макс 512).';
    if (mb_strlen($workHours) > 120)   $errors[] = 'График слишком длинный (макс 120).';
    if (mb_strlen($description) > 1000)$errors[] = 'Описание слишком длинное (макс 1000).';

            if (!$errors) {
                $stmt = $pdo->prepare("
                UPDATE restaurants
                SET
                    name                      = :name,
                    allow_payment_card_later  = :card_later,
                    allow_payment_cash        = :cash,
                    qr_theme                  = :qr_theme,

                    brand_logo_url           = :brand_logo_url,
                    brand_banner_url         = :brand_banner_url,
                    brand_accent_color       = :brand_accent_color,

                    phone                     = :phone,
                    address                   = :address,
                    website                   = :website,
                    instagram                 = :instagram,
                    google_review_link        = :google_review_link,
                    yandex_review_url         = :yandex_review_url,
                    work_hours                = :work_hours,
                    description               = :description,

                    updated_at                = NOW()
                WHERE id = :id
                LIMIT 1
            ");

            $executeOk = false;
            try {
                $executeOk = $stmt->execute([
                    'name'       => $name,
                    'card_later' => $allowCardLater,
                    'cash'       => $allowCash,
                    'qr_theme'   => $qrThemePost,

                    'brand_logo_url'     => ($brandLogoUrlPostToSave !== null && $brandLogoUrlPostToSave !== '' ? $brandLogoUrlPostToSave : null),
                    'brand_banner_url'   => ($brandBannerUrlPostToSave !== null && $brandBannerUrlPostToSave !== '' ? $brandBannerUrlPostToSave : null),
                    'brand_accent_color' => ($brandAccentColorToSave !== null && $brandAccentColorToSave !== '' ? $brandAccentColorToSave : null),

                    'phone'       => ($phone !== '' ? $phone : null),
                    'address'     => ($address !== '' ? $address : null),
                    'website'     => ($website !== '' ? $website : null),
                    'instagram'   => ($instagram !== '' ? $instagram : null),
                    'google_review_link' => ($googleReviewLink !== '' ? $googleReviewLink : null),
                    'yandex_review_url' => ($yandexReviewUrl !== '' ? $yandexReviewUrl : null),
                    'work_hours'  => ($workHours !== '' ? $workHours : null),
                    'description' => ($description !== '' ? $description : null),

                    'id' => $restId,
                ]);
            } catch (Throwable $e) {
                $executeOk = false;
                $errors[] = 'Ошибка сохранения настроек ресторана. Попробуйте еще раз.';
            }

            if ($executeOk && !$errors) {
                // Safe replace cleanup: delete old files only if they are different from new ones.
                if ($brandLogoFileUploaded && $currentBrandLogoUrlNorm !== null && $currentBrandLogoUrlNorm !== ''
                    && is_string($brandLogoUrlPostToSave) && $brandLogoUrlPostToSave !== ''
                    && $currentBrandLogoUrlNorm !== $brandLogoUrlPostToSave
                ) {
                    brand_delete_storage_file_if_allowed($currentBrandLogoUrlNorm);
                }
                if ($brandBannerFileUploaded && $currentBrandBannerUrlNorm !== null && $currentBrandBannerUrlNorm !== ''
                    && is_string($brandBannerUrlPostToSave) && $brandBannerUrlPostToSave !== ''
                    && $currentBrandBannerUrlNorm !== $brandBannerUrlPostToSave
                ) {
                    brand_delete_storage_file_if_allowed($currentBrandBannerUrlNorm);
                }

                if (function_exists('log_action')) {
                    $msg = "Обновлены настройки ресторана: "
                        . "name=\"{$name}\", "
                        . "card_later={$allowCardLater}, "
                        . "cash={$allowCash}, "
                        . "qr_theme={$qrThemePost}, "
                        . "phone=\"{$phone}\", "
                        . "address=\"{$address}\"";
                    log_action(auth_user()['id'] ?? null, $restId, 'update_restaurant_settings', $msg);
                }
                $success = true;
            }

        $stmt = $pdo->prepare("SELECT * FROM restaurants WHERE id = :id{$restaurantsNotDeletedSql} LIMIT 1");
        $stmt->execute(['id' => $restId]);
        $restaurantRow = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$restaurantRow) {
            http_response_code(404);
            echo 'Restaurant not found';
            exit;
        }

        $currentName        = (string)$restaurantRow['name'];
        $allowCardLaterCur  = isset($restaurantRow['allow_payment_card_later']) ? (int)$restaurantRow['allow_payment_card_later'] : 1;
        $allowCashCur       = isset($restaurantRow['allow_payment_cash'])       ? (int)$restaurantRow['allow_payment_cash']       : 1;
        $currentTheme       = $restaurantRow['qr_theme'] ?? 'dark_glass';

        $currentPhone       = (string)($restaurantRow['phone'] ?? '');
        $currentAddress     = (string)($restaurantRow['address'] ?? '');
        $currentWebsite     = (string)($restaurantRow['website'] ?? '');
        $currentInstagram   = (string)($restaurantRow['instagram'] ?? '');
        $currentGoogleReviewLink = (string)($restaurantRow['google_review_link'] ?? '');
        $currentYandexReviewUrl = (string)($restaurantRow['yandex_review_url'] ?? '');
        $currentWorkHours   = (string)($restaurantRow['work_hours'] ?? '');
        $currentDescription = (string)($restaurantRow['description'] ?? '');

        $currentBrandLogoUrl = isset($restaurantRow['brand_logo_url']) ? (string)$restaurantRow['brand_logo_url'] : '';
        $currentBrandBannerUrl = isset($restaurantRow['brand_banner_url']) ? (string)$restaurantRow['brand_banner_url'] : '';
        $currentBrandAccentColor = isset($restaurantRow['brand_accent_color']) ? (string)$restaurantRow['brand_accent_color'] : '';
        $currentBrandAccentColorNorm = brand_normalize_hex_color($currentBrandAccentColor);
        $currentBrandLogoUrlNorm = brand_normalize_asset_url($currentBrandLogoUrl);
        $currentBrandBannerUrlNorm = brand_normalize_asset_url($currentBrandBannerUrl);
        $brandLogoSrc = $currentBrandLogoUrlNorm ? ('/storage/' . $currentBrandLogoUrlNorm) : '';
        $brandBannerSrc = $currentBrandBannerUrlNorm ? ('/storage/' . $currentBrandBannerUrlNorm) : '';
        $brandAccentPickerValue = $currentBrandAccentColorNorm !== null ? $currentBrandAccentColorNorm : '#22C55E';
        $brandAccentColorSet = $currentBrandAccentColorNorm !== null ? 1 : 0;
    } else {

        $currentName        = $name;
        $allowCardLaterCur  = $allowCardLater;
        $allowCashCur       = $allowCash;
        $currentTheme       = $qrThemePost;

        $currentPhone       = $phone;
        $currentAddress     = $address;
        $currentWebsite     = $website;
        $currentInstagram   = $instagram;
        $currentGoogleReviewLink = $googleReviewLink;
        $currentYandexReviewUrl = $yandexReviewUrl;
        $currentWorkHours   = $workHours;
        $currentDescription = $description;
    }
    }
}

$crmFormDays = 14;
$crmFormEnabled = true;
$crmSettingsTableOk = function_exists('db_table_exists') && db_table_exists('restaurant_crm_settings');
if ($crmSettingsTableOk) {
    try {
        $crmStmt = $pdo->prepare('SELECT inactive_return_days, enabled FROM restaurant_crm_settings WHERE restaurant_id = ? LIMIT 1');
        $crmStmt->execute([$restId]);
        $crmRow = $crmStmt->fetch(PDO::FETCH_ASSOC);
        if ($crmRow) {
            $crmFormDays = max(7, min(90, (int)($crmRow['inactive_return_days'] ?? 14)));
            $crmFormEnabled = ((int)($crmRow['enabled'] ?? 1) === 1);
        }
    } catch (Throwable $e) {
        // keep defaults
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['save_crm_return'])) {
    $crmFormDays = max(7, min(90, (int)($_POST['inactive_return_days'] ?? $crmFormDays)));
    $crmFormEnabled = isset($_POST['crm_return_enabled']);
}

$smartUpsellLegacyEnCol = function_exists('db_column_exists') && db_column_exists('restaurants', 'smart_upsell_guest_enabled');
$smartUpsellLegacyMaxCol = function_exists('db_column_exists') && db_column_exists('restaurants', 'smart_upsell_guest_max');

$smartUpsellMenuEnCol = function_exists('db_column_exists') && db_column_exists('restaurants', 'guest_menu_upsell_enabled');
$smartUpsellMenuMaxCol = function_exists('db_column_exists') && db_column_exists('restaurants', 'guest_menu_upsell_limit');
$smartUpsellMenuManualOnlyCol = function_exists('db_column_exists') && db_column_exists('restaurants', 'guest_menu_upsell_manual_only');
$smartUpsellMenuComboEnCol = function_exists('db_column_exists') && db_column_exists('restaurants', 'guest_menu_combo_enabled');
$smartUpsellMenuComboMaxCol = function_exists('db_column_exists') && db_column_exists('restaurants', 'guest_menu_combo_limit');

$smartUpsellCartEnCol = function_exists('db_column_exists') && db_column_exists('restaurants', 'guest_cart_upsell_enabled');
$smartUpsellCartMaxCol = function_exists('db_column_exists') && db_column_exists('restaurants', 'guest_cart_upsell_limit');
$smartUpsellCartUseManualCol = function_exists('db_column_exists') && db_column_exists('restaurants', 'guest_cart_upsell_use_manual');
$smartUpsellCartUseContextualCol = function_exists('db_column_exists') && db_column_exists('restaurants', 'guest_cart_upsell_use_contextual');
$smartUpsellCartUsePopularCol = function_exists('db_column_exists') && db_column_exists('restaurants', 'guest_cart_upsell_use_popular');
$smartUpsellCartComboEnCol = function_exists('db_column_exists') && db_column_exists('restaurants', 'guest_cart_combo_enabled');
$smartUpsellCartComboMaxCol = function_exists('db_column_exists') && db_column_exists('restaurants', 'guest_cart_combo_limit');

$smartUpsellSplitSmartMenuEnCol = function_exists('db_column_exists') && db_column_exists('restaurants', 'smart_upsell_menu_enabled');
$smartUpsellSplitSmartMenuMaxCol = function_exists('db_column_exists') && db_column_exists('restaurants', 'smart_upsell_menu_max');
$smartUpsellSplitSmartMenuManualOnlyCol = function_exists('db_column_exists') && db_column_exists('restaurants', 'smart_upsell_menu_manual_only');
$smartUpsellSplitSmartCartEnCol = function_exists('db_column_exists') && db_column_exists('restaurants', 'smart_upsell_cart_enabled');
$smartUpsellSplitSmartCartMaxCol = function_exists('db_column_exists') && db_column_exists('restaurants', 'smart_upsell_cart_max');
$smartUpsellSplitSmartCartUseManualCol = function_exists('db_column_exists') && db_column_exists('restaurants', 'smart_upsell_cart_use_manual');
$smartUpsellSplitSmartCartUseContextualCol = function_exists('db_column_exists') && db_column_exists('restaurants', 'smart_upsell_cart_use_contextual');
$smartUpsellSplitSmartCartUsePopularCol = function_exists('db_column_exists') && db_column_exists('restaurants', 'smart_upsell_cart_use_popular');

$smartUpsellMenuEnabled = $smartUpsellMenuEnCol
    ? ((int)($restaurantRow['guest_menu_upsell_enabled'] ?? 1) === 1)
    : ($smartUpsellSplitSmartMenuEnCol
        ? ((int)($restaurantRow['smart_upsell_menu_enabled'] ?? 1) === 1)
        : ($smartUpsellLegacyEnCol ? ((int)($restaurantRow['smart_upsell_guest_enabled'] ?? 1) === 1) : true));
$smartUpsellMenuMax = $smartUpsellMenuMaxCol
    ? max(1, min(2, (int)($restaurantRow['guest_menu_upsell_limit'] ?? 1)))
    : ($smartUpsellSplitSmartMenuMaxCol
        ? max(1, min(2, (int)($restaurantRow['smart_upsell_menu_max'] ?? 1)))
        : ($smartUpsellLegacyMaxCol ? max(1, min(2, (int)($restaurantRow['smart_upsell_guest_max'] ?? 1))) : 1));
$smartUpsellMenuManualOnly = $smartUpsellMenuManualOnlyCol
    ? ((int)($restaurantRow['guest_menu_upsell_manual_only'] ?? 1) === 1)
    : ($smartUpsellSplitSmartMenuManualOnlyCol
        ? ((int)($restaurantRow['smart_upsell_menu_manual_only'] ?? 1) === 1)
        : true);
$smartUpsellMenuComboEnabled = $smartUpsellMenuComboEnCol
    ? ((int)($restaurantRow['guest_menu_combo_enabled'] ?? 1) === 1)
    : true;
$smartUpsellMenuComboMax = $smartUpsellMenuComboMaxCol
    ? max(1, min(2, (int)($restaurantRow['guest_menu_combo_limit'] ?? 1)))
    : 1;

$smartUpsellCartEnabled = $smartUpsellCartEnCol
    ? ((int)($restaurantRow['guest_cart_upsell_enabled'] ?? 1) === 1)
    : ($smartUpsellSplitSmartCartEnCol
        ? ((int)($restaurantRow['smart_upsell_cart_enabled'] ?? 1) === 1)
        : ($smartUpsellLegacyEnCol ? ((int)($restaurantRow['smart_upsell_guest_enabled'] ?? 1) === 1) : true));
$smartUpsellCartMax = $smartUpsellCartMaxCol
    ? max(1, min(3, (int)($restaurantRow['guest_cart_upsell_limit'] ?? 3)))
    : ($smartUpsellSplitSmartCartMaxCol
        ? max(1, min(3, (int)($restaurantRow['smart_upsell_cart_max'] ?? 3)))
        : ($smartUpsellLegacyMaxCol ? max(1, min(3, (int)($restaurantRow['smart_upsell_guest_max'] ?? 3))) : 3));
$smartUpsellCartUseManual = $smartUpsellCartUseManualCol
    ? ((int)($restaurantRow['guest_cart_upsell_use_manual'] ?? 1) === 1)
    : ($smartUpsellSplitSmartCartUseManualCol
        ? ((int)($restaurantRow['smart_upsell_cart_use_manual'] ?? 1) === 1)
        : true);
$smartUpsellCartUseContextual = $smartUpsellCartUseContextualCol
    ? ((int)($restaurantRow['guest_cart_upsell_use_contextual'] ?? 1) === 1)
    : ($smartUpsellSplitSmartCartUseContextualCol
        ? ((int)($restaurantRow['smart_upsell_cart_use_contextual'] ?? 1) === 1)
        : true);
$smartUpsellCartUsePopular = $smartUpsellCartUsePopularCol
    ? ((int)($restaurantRow['guest_cart_upsell_use_popular'] ?? 1) === 1)
    : ($smartUpsellSplitSmartCartUsePopularCol
        ? ((int)($restaurantRow['smart_upsell_cart_use_popular'] ?? 1) === 1)
        : true);
$smartUpsellCartComboEnabled = $smartUpsellCartComboEnCol
    ? ((int)($restaurantRow['guest_cart_combo_enabled'] ?? 1) === 1)
    : true;
$smartUpsellCartComboMax = $smartUpsellCartComboMaxCol
    ? max(1, min(3, (int)($restaurantRow['guest_cart_combo_limit'] ?? 2)))
    : 2;

$smartUpsellSplitSupported = $smartUpsellMenuEnCol && $smartUpsellMenuMaxCol && $smartUpsellCartEnCol && $smartUpsellCartMaxCol;
$smartUpsellSaveAvailable = $smartUpsellLegacyEnCol
    || $smartUpsellMenuEnCol || $smartUpsellCartEnCol
    || $smartUpsellSplitSmartMenuEnCol || $smartUpsellSplitSmartCartEnCol;

?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Настройки ресторана — <?= e($currentRestaurant['name']) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?= brand_head_tags() ?>

    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-950 text-slate-50 flex overflow-x-hidden">

<?php
$restaurantSidebarActive = 'settings';
$restaurantSidebarName = (string)($currentRestaurant['name'] ?? 'Ресторан');
require __DIR__ . '/_sidebar_mobile.php';
require __DIR__ . '/_sidebar.php';
?>

<!-- Основной контент -->
<main class="flex-1 p-4">
    <div class="max-w-3xl mx-auto space-y-4">
        <?php
        $cabinetQuickNavActive = 'settings';
        require __DIR__ . '/_restaurant_cabinet_context.php';
        require __DIR__ . '/_restaurant_cabinet_quick_nav.php';
        ?>
        <header class="flex flex-wrap items-center justify-between gap-3 mb-2 border-b border-slate-800/80 pb-4">
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-wider text-emerald-500/85 mb-1">Настройки</p>
                <h1 class="text-2xl font-bold text-slate-50 mb-1">Настройки ресторана</h1>
                <div class="text-xs text-slate-500">
                    Основные параметры, тема QR-меню, оплата и контакты
                </div>
            </div>
        </header>

        <?php if (!empty($_GET['crm_saved'])): ?>
            <div class="rounded-3xl bg-emerald-500/10 border border-emerald-500/60 px-4 py-3 text-sm text-emerald-100">
                Настройки CRM сохранены.
            </div>
        <?php endif; ?>

        <?php if (!empty($_GET['smart_upsell_saved'])): ?>
            <div class="rounded-3xl bg-emerald-500/10 border border-emerald-500/60 px-4 py-3 text-sm text-emerald-100">
                Настройки умных допродаж сохранены.
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="rounded-3xl bg-emerald-500/10 border border-emerald-500/60 px-4 py-3 text-sm text-emerald-100">
                Настройки успешно сохранены.
            </div>
        <?php endif; ?>

        <?php if ($errors): ?>
            <div class="rounded-3xl bg-red-500/10 border border-red-500/60 px-4 py-3 text-sm text-red-100 space-y-1">
                <?php foreach ($errors as $err): ?>
                    <div><?= e($err) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <section id="crm-return-settings" class="space-y-4 bg-slate-900/80 border border-indigo-500/25 rounded-3xl p-4 md:p-5">
            <div>
                <h3 class="text-base font-semibold text-white">Настройки CRM</h3>
                <p class="text-[11px] text-slate-500 mt-1">Возврат гостей: порог «давно не был» и включение сценариев подготовки сообщений (без авто-отправки).</p>
            </div>
            <?php if (!$crmSettingsTableOk): ?>
                <div class="rounded-2xl bg-amber-500/10 border border-amber-500/40 px-4 py-3 text-sm text-amber-100">
                    Расширенные настройки CRM пока недоступны. Обновите модуль CRM и повторите сохранение.
                </div>
            <?php endif; ?>
            <form method="post" class="space-y-4">
                <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                <input type="hidden" name="save_crm_return" value="1">
                <div class="rounded-2xl border border-slate-800 bg-slate-950/40 p-4 space-y-4">
                    <h4 class="text-sm font-semibold text-slate-100">Возврат гостей</h4>
                    <label class="flex items-start gap-3 cursor-pointer text-sm text-slate-200">
                        <input type="checkbox" name="crm_return_enabled" value="1" class="mt-1 rounded bg-slate-950 border-slate-600"
                            <?= $crmFormEnabled ? 'checked' : '' ?>
                            <?= ($crmSettingsTableOk && (!function_exists('is_demo_mode') || !is_demo_mode())) ? '' : 'disabled' ?>>
                        <span>
                            <span class="font-medium">Включить возврат гостей</span>
                            <span class="block text-[11px] text-slate-500 mt-0.5">Блок «Готовы к возврату» и автоматические черновики в CRM; ручная подготовка сообщений остаётся доступна.</span>
                        </span>
                    </label>
                    <div class="space-y-1">
                        <label class="block text-xs text-slate-400" for="inactive_return_days">Через сколько дней считать гостя неактивным</label>
                        <input type="number" name="inactive_return_days" id="inactive_return_days" min="7" max="90" step="1"
                               value="<?= (int)$crmFormDays ?>"
                               class="w-full max-w-[200px] rounded-2xl bg-slate-950 border border-slate-700 px-3 py-2 text-sm text-slate-50 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                            <?= ($crmSettingsTableOk && (!function_exists('is_demo_mode') || !is_demo_mode())) ? '' : 'disabled' ?>>
                        <p class="text-[11px] text-slate-600">Допустимо 7–90. Используется в CRM для сегментов и списка к возврату.</p>
                    </div>
                    <div>
                        <button type="submit"
                                class="inline-flex items-center px-4 py-2.5 rounded-2xl bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-semibold disabled:opacity-40 disabled:cursor-not-allowed"
                            <?= ($crmSettingsTableOk && (!function_exists('is_demo_mode') || !is_demo_mode())) ? '' : 'disabled' ?>>
                            Сохранить
                        </button>
                    </div>
                </div>
            </form>
        </section>

        <section id="smart-upsell-layers" class="space-y-4 bg-slate-900/80 border border-amber-500/20 rounded-3xl p-4 md:p-5">
            <div>
                <h3 class="text-base font-semibold text-white">Умные допродажи в QR</h3>
                <p class="text-[11px] text-slate-500 mt-1">Раздельное управление для мягких рекомендаций в меню и более продающих рекомендаций в корзине. Ручные связки настраиваются в разделе <a href="/restaurant/upsells.php" class="text-amber-400/90 hover:text-amber-300 underline decoration-dotted">Допродажи</a>.</p>
            </div>
            <?php if (!$smartUpsellSaveAvailable): ?>
                <div class="rounded-2xl bg-amber-500/10 border border-amber-500/40 px-4 py-3 text-sm text-amber-100">
                    Функция умных допродаж в QR пока недоступна. После обновления структуры данных повторите сохранение.
                </div>
            <?php endif; ?>
            <?php if (!$smartUpsellSplitSupported): ?>
                <div class="rounded-2xl bg-sky-500/10 border border-sky-500/30 px-4 py-3 text-xs text-sky-100">
                    Сейчас работает legacy-режим совместимости: меню и корзина используют общий слой настроек. После миграции split-колонок они будут полностью независимы.
                </div>
            <?php endif; ?>
            <form method="post" class="space-y-4">
                <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                <input type="hidden" name="save_smart_upsell_layers" value="1">
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                    <div class="rounded-2xl border border-slate-800 bg-slate-950/40 p-4 space-y-4">
                        <div>
                            <h4 class="text-sm font-semibold text-slate-100">Upsell в меню</h4>
                            <p class="text-[11px] text-slate-500 mt-1">Мягкие контекстные рекомендации после добавления блюда.</p>
                        </div>
                        <label class="flex items-start gap-3 cursor-pointer text-sm text-slate-200">
                            <input type="checkbox" name="guest_menu_upsell_enabled" value="1" class="mt-1 rounded bg-slate-950 border-slate-600"
                                <?= $smartUpsellMenuEnabled ? 'checked' : '' ?>
                                <?= ((!function_exists('is_demo_mode') || !is_demo_mode()) && $smartUpsellSaveAvailable) ? '' : 'disabled' ?>>
                            <span>
                                <span class="font-medium">Включить рекомендации в меню</span>
                                <span class="block text-[11px] text-slate-500 mt-0.5">Показываются только после добавления блюда, без блокирующих модалок.</span>
                            </span>
                        </label>
                        <div class="space-y-1">
                            <label class="block text-xs text-slate-400" for="guest_menu_upsell_limit">Максимум рекомендаций</label>
                            <select name="guest_menu_upsell_limit" id="guest_menu_upsell_limit"
                                class="w-full max-w-[200px] rounded-2xl bg-slate-950 border border-slate-700 px-3 py-2 text-sm text-slate-50 focus:outline-none focus:ring-1 focus:ring-amber-500"
                                <?= ((!function_exists('is_demo_mode') || !is_demo_mode()) && $smartUpsellSaveAvailable) ? '' : 'disabled' ?>>
                                <?php for ($i = 1; $i <= 2; $i++): ?>
                                    <option value="<?= $i ?>" <?= ((int)$smartUpsellMenuMax === $i) ? 'selected' : '' ?>><?= $i ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <label class="flex items-start gap-3 cursor-pointer text-sm text-slate-200">
                            <input type="checkbox" name="guest_menu_upsell_manual_only" value="1" class="mt-1 rounded bg-slate-950 border-slate-600"
                                <?= $smartUpsellMenuManualOnly ? 'checked' : '' ?>
                                <?= ((!function_exists('is_demo_mode') || !is_demo_mode()) && $smartUpsellSaveAvailable) ? '' : 'disabled' ?>>
                            <span>
                                <span class="font-medium">Только ручные связки</span>
                                <span class="block text-[11px] text-slate-500 mt-0.5">Показывать предложения только из настроенных пар, без авто-подбора.</span>
                            </span>
                        </label>
                        <label class="flex items-start gap-3 cursor-pointer text-sm text-slate-200">
                            <input type="checkbox" name="guest_menu_combo_enabled" value="1" class="mt-1 rounded bg-slate-950 border-slate-600"
                                <?= $smartUpsellMenuComboEnabled ? 'checked' : '' ?>
                                <?= ((!function_exists('is_demo_mode') || !is_demo_mode()) && $smartUpsellSaveAvailable) ? '' : 'disabled' ?>>
                            <span>
                                <span class="font-medium">Включить combo в menu upsell</span>
                                <span class="block text-[11px] text-slate-500 mt-0.5">Подмешивать предложения из combo rules в one-tap слой.</span>
                            </span>
                        </label>
                        <div class="space-y-1">
                            <label class="block text-xs text-slate-400" for="guest_menu_combo_limit">Лимит combo-карточек</label>
                            <select name="guest_menu_combo_limit" id="guest_menu_combo_limit"
                                class="w-full max-w-[200px] rounded-2xl bg-slate-950 border border-slate-700 px-3 py-2 text-sm text-slate-50 focus:outline-none focus:ring-1 focus:ring-amber-500"
                                <?= ((!function_exists('is_demo_mode') || !is_demo_mode()) && $smartUpsellSaveAvailable) ? '' : 'disabled' ?>>
                                <?php for ($i = 1; $i <= 2; $i++): ?>
                                    <option value="<?= $i ?>" <?= ((int)$smartUpsellMenuComboMax === $i) ? 'selected' : '' ?>><?= $i ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>

                    <div class="rounded-2xl border border-slate-800 bg-slate-950/40 p-4 space-y-4">
                        <div>
                            <h4 class="text-sm font-semibold text-slate-100">Upsell в корзине</h4>
                            <p class="text-[11px] text-slate-500 mt-1">Более сильные предложения перед checkout для роста среднего чека.</p>
                        </div>
                        <label class="flex items-start gap-3 cursor-pointer text-sm text-slate-200">
                            <input type="checkbox" name="guest_cart_upsell_enabled" value="1" class="mt-1 rounded bg-slate-950 border-slate-600"
                                <?= $smartUpsellCartEnabled ? 'checked' : '' ?>
                                <?= ((!function_exists('is_demo_mode') || !is_demo_mode()) && $smartUpsellSaveAvailable) ? '' : 'disabled' ?>>
                            <span>
                                <span class="font-medium">Включить рекомендации в корзине</span>
                                <span class="block text-[11px] text-slate-500 mt-0.5">Отдельный блок «Подходит к вашему заказу» рядом с суммой и CTA оформления.</span>
                            </span>
                        </label>
                        <div class="space-y-1">
                            <label class="block text-xs text-slate-400" for="guest_cart_upsell_limit">Максимум рекомендаций</label>
                            <select name="guest_cart_upsell_limit" id="guest_cart_upsell_limit"
                                class="w-full max-w-[200px] rounded-2xl bg-slate-950 border border-slate-700 px-3 py-2 text-sm text-slate-50 focus:outline-none focus:ring-1 focus:ring-amber-500"
                                <?= ((!function_exists('is_demo_mode') || !is_demo_mode()) && $smartUpsellSaveAvailable) ? '' : 'disabled' ?>>
                                <?php for ($i = 1; $i <= 3; $i++): ?>
                                    <option value="<?= $i ?>" <?= ((int)$smartUpsellCartMax === $i) ? 'selected' : '' ?>><?= $i ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="space-y-2 text-sm">
                            <label class="flex items-start gap-3 cursor-pointer text-slate-200">
                                <input type="checkbox" name="guest_cart_upsell_use_manual" value="1" class="mt-1 rounded bg-slate-950 border-slate-600"
                                    <?= $smartUpsellCartUseManual ? 'checked' : '' ?>
                                    <?= ((!function_exists('is_demo_mode') || !is_demo_mode()) && $smartUpsellSaveAvailable) ? '' : 'disabled' ?>>
                                <span>
                                    <span class="font-medium">Использовать ручные связки</span>
                                    <span class="block text-[11px] text-slate-500 mt-0.5">Пары из раздела «Допродажи» (блюдо → рекомендуемое).</span>
                                </span>
                            </label>
                            <label class="flex items-start gap-3 cursor-pointer text-slate-200">
                                <input type="checkbox" name="guest_cart_upsell_use_contextual" value="1" class="mt-1 rounded bg-slate-950 border-slate-600"
                                    <?= $smartUpsellCartUseContextual ? 'checked' : '' ?>
                                    <?= ((!function_exists('is_demo_mode') || !is_demo_mode()) && $smartUpsellSaveAvailable) ? '' : 'disabled' ?>>
                                <span>
                                    <span class="font-medium">Использовать contextual/category рекомендации</span>
                                    <span class="block text-[11px] text-slate-500 mt-0.5">Автоподбор по категории, типу блюда и составу корзины.</span>
                                </span>
                            </label>
                            <label class="flex items-start gap-3 cursor-pointer text-slate-200">
                                <input type="checkbox" name="guest_cart_upsell_use_popular" value="1" class="mt-1 rounded bg-slate-950 border-slate-600"
                                    <?= $smartUpsellCartUsePopular ? 'checked' : '' ?>
                                    <?= ((!function_exists('is_demo_mode') || !is_demo_mode()) && $smartUpsellSaveAvailable) ? '' : 'disabled' ?>>
                                <span>
                                    <span class="font-medium">Использовать popular fallback</span>
                                    <span class="block text-[11px] text-slate-500 mt-0.5">Показывать популярные дополнения, если явных кандидатов мало.</span>
                                </span>
                            </label>
                            <label class="flex items-start gap-3 cursor-pointer text-slate-200">
                                <input type="checkbox" name="guest_cart_combo_enabled" value="1" class="mt-1 rounded bg-slate-950 border-slate-600"
                                    <?= $smartUpsellCartComboEnabled ? 'checked' : '' ?>
                                    <?= ((!function_exists('is_demo_mode') || !is_demo_mode()) && $smartUpsellSaveAvailable) ? '' : 'disabled' ?>>
                                <span>
                                    <span class="font-medium">Включить combo в cart upsell</span>
                                    <span class="block text-[11px] text-slate-500 mt-0.5">Отдельный источник рекомендаций из combo rules в корзине.</span>
                                </span>
                            </label>
                            <div class="space-y-1">
                                <label class="block text-xs text-slate-400" for="guest_cart_combo_limit">Лимит combo-карточек</label>
                                <select name="guest_cart_combo_limit" id="guest_cart_combo_limit"
                                    class="w-full max-w-[200px] rounded-2xl bg-slate-950 border border-slate-700 px-3 py-2 text-sm text-slate-50 focus:outline-none focus:ring-1 focus:ring-amber-500"
                                    <?= ((!function_exists('is_demo_mode') || !is_demo_mode()) && $smartUpsellSaveAvailable) ? '' : 'disabled' ?>>
                                    <?php for ($i = 1; $i <= 3; $i++): ?>
                                        <option value="<?= $i ?>" <?= ((int)$smartUpsellCartComboMax === $i) ? 'selected' : '' ?>><?= $i ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
                <div>
                    <button type="submit"
                        class="inline-flex items-center px-4 py-2.5 rounded-2xl bg-amber-600 hover:bg-amber-500 text-white text-sm font-semibold disabled:opacity-40 disabled:cursor-not-allowed"
                        <?= ((!function_exists('is_demo_mode') || !is_demo_mode()) && $smartUpsellSaveAvailable) ? '' : 'disabled' ?>>
                        Сохранить настройки допродаж
                    </button>
                </div>
            </form>
        </section>

<form method="post" enctype="multipart/form-data" class="space-y-6 bg-slate-900/80 border border-slate-800 rounded-3xl p-4">
            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">

            <!-- Основная информация -->
            <section class="space-y-3">
                <h3 class="text-sm font-semibold text-slate-100">Общие данные</h3>
                <div class="space-y-1 text-xs">
                    <label class="block text-slate-300 mb-1" for="name">Название ресторана</label>
                    <input
                        type="text"
                        id="name"
                        name="name"
                        value="<?= e($currentName) ?>"
                        class="w-full rounded-2xl bg-slate-950 border border-slate-700 px-3 py-2 text-sm text-slate-50 focus:outline-none focus:ring-1 focus:ring-emerald-500"
                    >
                    <p class="text-[11px] text-slate-500 mt-1">
                        Это название будет отображаться в панели и в QR-меню.
                    </p>
                </div>
            </section>

            <!-- Контакты и соцсети -->
            <section class="space-y-3">
                <h3 class="text-sm font-semibold text-slate-100">Контакты и соцсети</h3>
                <p class="text-[11px] text-slate-500">
                    Эти данные показываются на витрине ресторана (главной странице на поддомене).
                </p>

                <div class="grid md:grid-cols-2 gap-3 text-xs">
                    <div>
                        <label class="block text-slate-300 mb-1" for="phone">Телефон</label>
                        <input
                            type="text"
                            id="phone"
                            name="phone"
                            value="<?= e($currentPhone) ?>"
                            placeholder="+7 999 123-45-67"
                            class="w-full rounded-2xl bg-slate-950 border border-slate-700 px-3 py-2 text-sm text-slate-50 focus:outline-none focus:ring-1 focus:ring-emerald-500"
                        >
                    </div>

                    <div>
                        <label class="block text-slate-300 mb-1" for="work_hours">График работы</label>
                        <input
                            type="text"
                            id="work_hours"
                            name="work_hours"
                            value="<?= e($currentWorkHours) ?>"
                            placeholder="Ежедневно 10:00–23:00"
                            class="w-full rounded-2xl bg-slate-950 border border-slate-700 px-3 py-2 text-sm text-slate-50 focus:outline-none focus:ring-1 focus:ring-emerald-500"
                        >
                    </div>

                    <div class="md:col-span-2">
                        <label class="block text-slate-300 mb-1" for="address">Адрес</label>
                        <input
                            type="text"
                            id="address"
                            name="address"
                            value="<?= e($currentAddress) ?>"
                            placeholder="Город, улица, дом"
                            class="w-full rounded-2xl bg-slate-950 border border-slate-700 px-3 py-2 text-sm text-slate-50 focus:outline-none focus:ring-1 focus:ring-emerald-500"
                        >
                    </div>

                    <div>
                        <label class="block text-slate-300 mb-1" for="website">Сайт</label>
                        <input
                            type="text"
                            id="website"
                            name="website"
                            value="<?= e($currentWebsite) ?>"
                            placeholder="example.com"
                            class="w-full rounded-2xl bg-slate-950 border border-slate-700 px-3 py-2 text-sm text-slate-50 focus:outline-none focus:ring-1 focus:ring-emerald-500"
                        >
                        <p class="text-[11px] text-slate-500 mt-1">Можно без https:// — добавим автоматически.</p>
                    </div>

                    <div>
                        <label class="block text-slate-300 mb-1" for="instagram">Instagram / соцсеть</label>
                        <input
                            type="text"
                            id="instagram"
                            name="instagram"
                            value="<?= e($currentInstagram) ?>"
                            placeholder="@yourname или ссылка"
                            class="w-full rounded-2xl bg-slate-950 border border-slate-700 px-3 py-2 text-sm text-slate-50 focus:outline-none focus:ring-1 focus:ring-emerald-500"
                        >
                    </div>

                    <div class="md:col-span-2">
                        <label class="block text-slate-300 mb-1" for="google_review_link">Ссылка на отзывы Google</label>
                        <input
                            type="url"
                            id="google_review_link"
                            name="google_review_link"
                            value="<?= e($currentGoogleReviewLink) ?>"
                            placeholder="https://g.page/... или ссылка на отзывы в Google"
                            class="w-full rounded-2xl bg-slate-950 border border-slate-700 px-3 py-2 text-sm text-slate-50 focus:outline-none focus:ring-1 focus:ring-emerald-500"
                        >
                        <p class="text-[11px] text-slate-500 mt-1">Если указана, гостям с оценкой 4–5 после отзыва будет предложено оставить отзыв в Google.</p>
                    </div>

                    <div class="md:col-span-2">
                        <label class="block text-slate-300 mb-1" for="yandex_review_url">Ссылка на Яндекс.Карты для отзывов</label>
                        <input
                            type="url"
                            id="yandex_review_url"
                            name="yandex_review_url"
                            value="<?= e($currentYandexReviewUrl) ?>"
                            placeholder="https://yandex.ru/maps/.../reviews"
                            class="w-full rounded-2xl bg-slate-950 border border-slate-700 px-3 py-2 text-sm text-slate-50 focus:outline-none focus:ring-1 focus:ring-emerald-500"
                        >
                        <p class="text-[11px] text-slate-500 mt-1">Укажите ссылку на страницу отзывов вашего заведения в Яндекс.Картах.</p>
                    </div>

                    <div class="md:col-span-2">
                        <label class="block text-slate-300 mb-1" for="description">Описание (для витрины)</label>
                        <textarea
                            id="description"
                            name="description"
                            rows="4"
                            maxlength="1000"
                            class="w-full rounded-2xl bg-slate-950 border border-slate-700 px-3 py-2 text-sm text-slate-50 focus:outline-none focus:ring-1 focus:ring-emerald-500"
                            placeholder="Кухня, атмосфера, фишки, почему к вам приходят..."
                        ><?= e($currentDescription) ?></textarea>
                        <p class="text-[11px] text-slate-500 mt-1">До 1000 символов.</p>
                    </div>
                </div>
            </section>

            <!-- Тема QR-меню -->
            <section class="space-y-3">
                <h3 class="text-sm font-semibold text-slate-100">Тема QR-меню для гостей</h3>
                <p class="text-[11px] text-slate-500">
                    Выберите визуальный стиль, в котором гости будут видеть меню по QR-коду.
                </p>

                <div class="grid md:grid-cols-3 gap-3 text-xs">
                    <?php foreach ($qrThemes as $key => $t): ?>
                        <label class="relative block cursor-pointer group">
                            <input
                                type="radio"
                                name="qr_theme"
                                value="<?= e($key) ?>"
                                data-theme-label="<?= e($t['label'] ?? $key) ?>"
                                class="peer sr-only"
                                <?= $currentTheme === $key ? 'checked' : '' ?>
                            >
                            <div class="rounded-2xl border px-3 py-3 h-full
                                <?php if ($currentTheme === $key): ?>
                                    border-emerald-500/80 bg-emerald-500/10
                                <?php else: ?>
                                    border-slate-700 bg-slate-900/70 group-hover:border-emerald-400/70 group-hover:bg-slate-900
                                <?php endif; ?>
                            ">
                                <div class="flex items-start justify-between gap-2 mb-2">
                                    <div>
                                        <div class="text-[13px] font-semibold text-slate-50">
                                            <?= e($t['label']) ?>
                                        </div>
                                        <?php if (!empty($t['desc'])): ?>
                                            <div class="text-[11px] text-slate-400 mt-0.5">
                                                <?= e($t['desc']) ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($currentTheme === $key): ?>
                                        <span class="inline-flex items-center justify-center rounded-full bg-emerald-500 text-slate-950 text-[10px] px-2 py-0.5">
                                            Выбрано
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center justify-center rounded-full bg-slate-800 text-slate-300 text-[10px] px-2 py-0.5">
                                            Выбрать
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </label>
                    <?php endforeach; ?>
                </div>
                <div id="qr-theme-preview-status" class="text-[11px] text-slate-500 pt-1">
                    Предпросмотр: <?= e($currentThemeLabel) ?>
                </div>
                <div class="pt-2">
                    <button type="submit"
                            class="inline-flex items-center px-4 py-2 rounded-2xl bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs font-semibold border border-slate-700">
                        Сохранить тему
                    </button>
                </div>
            </section>

            <script>
                (function () {
                    var tableId = <?= (int)$previewTableId ?>;
                    var statusEl = document.getElementById('qr-theme-preview-status');
                    var radios = document.querySelectorAll('input[name="qr_theme"]');
                    if (!radios || radios.length === 0) return;

                    function setStatusFromRadio(radio) {
                        if (!statusEl) return;
                        var label = radio.getAttribute('data-theme-label') || radio.value;
                        statusEl.textContent = 'Предпросмотр: ' + label;
                    }

                    radios.forEach(function (r) {
                        r.addEventListener('change', function (e) {
                            var slug = e && e.target ? e.target.value : '';
                            if (!slug) return;
                            if (tableId && tableId > 0 && window && typeof window.open === 'function') {
                                var url = '/qr.php?table_id=' + encodeURIComponent(tableId) +
                                    '&theme_preview=' + encodeURIComponent(slug);
                                window.open(url, '_blank', 'noopener,noreferrer');
                            }
                            setStatusFromRadio(r);
                        });
                    });
                })();
            </script>

            <!-- Брендирование меню -->
            <section class="space-y-3">
                <h3 class="text-sm font-semibold text-slate-100">Брендирование меню</h3>
                <p class="text-[11px] text-slate-500">
                    Логотип, баннер и акцентный цвет для QR-меню.
                </p>

                <div class="grid md:grid-cols-2 gap-4 text-xs">
                    <div class="space-y-2">
                        <label class="block text-slate-300 mb-1">Логотип</label>
                        <?php if (!empty($brandLogoSrc)): ?>
                            <div class="rounded-xl overflow-hidden border border-slate-700 bg-slate-950/40 w-20 h-20 flex items-center justify-center">
                                <img src="<?= e($brandLogoSrc) ?>" alt="Logo" class="w-full h-full object-contain">
                            </div>
                        <?php else: ?>
                            <div class="rounded-xl border border-dashed border-slate-700 bg-slate-950/30 w-20 h-20 flex items-center justify-center text-[10px] text-slate-500">
                                Нет
                            </div>
                        <?php endif; ?>
                        <div class="space-y-2">
                            <input
                                type="file"
                                name="brand_logo_file"
                                accept="image/png,image/jpeg,image/webp"
                                class="w-full text-[11px] text-slate-200"
                            >
                            <input
                                type="text"
                                name="brand_logo_url"
                                value="<?= e($currentBrandLogoUrlNorm ?? '') ?>"
                                placeholder="brand_logos/xxx.png"
                                class="w-full rounded-2xl bg-slate-950 border border-slate-700 px-3 py-2 text-[11px] text-slate-50 focus:outline-none focus:ring-1 focus:ring-emerald-500"
                            >
                        </div>
                    </div>

                    <div class="space-y-2">
                        <label class="block text-slate-300 mb-1">Баннер</label>
                        <?php if (!empty($brandBannerSrc)): ?>
                            <div class="rounded-xl overflow-hidden border border-slate-700 bg-slate-950/40 w-full aspect-[16/6]">
                                <img src="<?= e($brandBannerSrc) ?>" alt="Banner" class="w-full h-full object-cover">
                            </div>
                        <?php else: ?>
                            <div class="rounded-xl border border-dashed border-slate-700 bg-slate-950/30 w-full aspect-[16/6] flex items-center justify-center text-[10px] text-slate-500">
                                Нет
                            </div>
                        <?php endif; ?>
                        <div class="space-y-2">
                            <input
                                type="file"
                                name="brand_banner_file"
                                accept="image/png,image/jpeg,image/webp"
                                class="w-full text-[11px] text-slate-200"
                            >
                            <input
                                type="text"
                                name="brand_banner_url"
                                value="<?= e($currentBrandBannerUrlNorm ?? '') ?>"
                                placeholder="brand_banners/xxx.jpg"
                                class="w-full rounded-2xl bg-slate-950 border border-slate-700 px-3 py-2 text-[11px] text-slate-50 focus:outline-none focus:ring-1 focus:ring-emerald-500"
                            >
                        </div>
                    </div>
                </div>

                <div class="md:col-span-2 pt-2">
                    <label class="block text-slate-300 mb-1">Акцентный цвет</label>
                    <input type="hidden" id="brand-accent-color-set" name="brand_accent_color_set" value="<?= (int)$brandAccentColorSet ?>">
                    <input
                        type="color"
                        name="brand_accent_color"
                        id="brand-accent-color"
                        value="<?= e($brandAccentPickerValue) ?>"
                        class="w-full h-10 rounded-2xl bg-slate-950 border border-slate-700 px-3 py-2 text-[11px] focus:outline-none"
                        onchange="var el=document.getElementById('brand-accent-color-set'); if(el) el.value='1';"
                    >
                </div>
            </section>

            <!-- Способы оплаты -->
            <section class="space-y-3">
                <h3 class="text-sm font-semibold text-slate-100">Доступные способы оплаты</h3>
                <p class="text-[11px] text-slate-500">
                    Отметьте варианты, которые доступны гостям при оформлении заказа через QR-меню.
                </p>

                <div class="space-y-2 text-xs text-slate-200">
                    <label class="flex items-start gap-2 cursor-pointer">
                        <input
                            type="checkbox"
                            name="allow_card_later"
                            value="1"
                            class="mt-0.5 rounded bg-slate-950 border-slate-700"
                            <?= $allowCardLaterCur ? 'checked' : '' ?>
                        >
                        <span>
                            <span class="font-semibold">Оплатить картой</span><br>
                            <span class="text-[11px] text-slate-500">
                                Гость оформляет заказ, оплачивает с помощью вашего терминала.
                            </span>
                        </span>
                    </label>

                    <label class="flex items-start gap-2 cursor-pointer">
                        <input
                            type="checkbox"
                            name="allow_cash"
                            value="1"
                            class="mt-0.5 rounded bg-slate-950 border-slate-700"
                            <?= $allowCashCur ? 'checked' : '' ?>
                        >
                        <span>
                            <span class="font-semibold">Оплатить наличными</span><br>
                            <span class="text-[11px] text-slate-500">
                                Гость оплачивает официанту при получении заказа.
                            </span>
                        </span>
                    </label>
                </div>
            </section>

            <div class="pt-2 flex justify-end">
                <button type="submit"
                        class="inline-flex items-center px-4 py-2.5 rounded-2xl bg-emerald-500 hover:bg-emerald-400 text-slate-950 text-sm font-semibold">
                    Сохранить настройки
                </button>
            </div>
        </form>
    </div>
</main>

</body>
</html>
