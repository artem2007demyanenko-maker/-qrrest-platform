<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
if (file_exists(__DIR__ . '/../../app/schema_guard.php')) {
    require_once __DIR__ . '/../../app/schema_guard.php';
}

require_login();
require_current_restaurant();

$restaurantId = (int)($currentRestaurant['id'] ?? 0);
require_restaurant_role($restaurantId, ['owner', 'admin']);

$pdo = db();
if (!$pdo instanceof PDO) {
    http_response_code(500);
    echo 'DB connection error';
    exit;
}

$user = auth_user();
$errors = [];
$warnings = [];
$success = null;

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

const MENU_MANAGE_IMAGE_MAX_BYTES = 2 * 1024 * 1024;
const MENU_MANAGE_IMAGE_ALLOWED_EXT = ['jpg', 'jpeg', 'png', 'webp'];

/**
 * @return array{description:bool,image_path:bool,image_url:bool,available:bool,station:bool,kitchen_station:bool}
 */
function menu_manage_item_schema(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    if (!function_exists('db_column_exists')) {
        $cache = [
            'description' => false,
            'image_path' => false,
            'image_url' => false,
            'available' => false,
            'station' => false,
            'kitchen_station' => false,
        ];
        return $cache;
    }
    $cache = [
        'description' => db_column_exists('menu_items', 'description'),
        'image_path' => db_column_exists('menu_items', 'image_path'),
        'image_url' => db_column_exists('menu_items', 'image_url'),
        'available' => db_column_exists('menu_items', 'available'),
        'station' => db_column_exists('menu_items', 'station'),
        'kitchen_station' => db_column_exists('menu_items', 'kitchen_station'),
    ];
    return $cache;
}

if (!function_exists('menu_manage_ensure_uploads_menu_dir')) {
    function menu_manage_ensure_uploads_menu_dir(int $restaurantId): string
    {
        $base = __DIR__ . '/../uploads/menu';
        if (!is_dir($base)) {
            @mkdir($base, 0775, true);
        }
        $dir = $base . '/' . (int)$restaurantId;
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }
}

if (!function_exists('menu_manage_upload_image')) {
    function menu_manage_upload_image(array $file, int $restaurantId): ?string
    {
        if (empty($file['tmp_name']) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return null;
        }
        $size = isset($file['size']) ? (int)$file['size'] : (int)@filesize((string)$file['tmp_name']);
        if ($size <= 0 || $size > MENU_MANAGE_IMAGE_MAX_BYTES) {
            return null;
        }
        $allowedMime = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeRaw = $finfo->file((string)$file['tmp_name']);
        $mime = is_string($mimeRaw) ? strtolower(trim($mimeRaw)) : '';
        $extFromName = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
        if ($extFromName === '' || !in_array($extFromName, MENU_MANAGE_IMAGE_ALLOWED_EXT, true)) {
            return null;
        }
        if (isset($allowedMime[$mime])) {
            $expectedExt = $allowedMime[$mime];
            if ($expectedExt !== $extFromName) {
                return null;
            }
            $ext = $expectedExt;
        } else {
            $ext = $extFromName;
        }
        $baseName = preg_replace('/[^a-zA-Z0-9_-]/', '', pathinfo($file['name'], PATHINFO_FILENAME));
        if ($baseName === '') {
            $baseName = 'img';
        }
        $name = substr($baseName, 0, 80) . '_' . time() . '_' . mt_rand(1000, 9999) . '.' . $ext;
        $dir = menu_manage_ensure_uploads_menu_dir($restaurantId);
        $full = $dir . '/' . $name;
        if (!@move_uploaded_file($file['tmp_name'], $full)) {
            return null;
        }
        return 'menu/' . (int)$restaurantId . '/' . $name;
    }
}

function menu_manage_log(?int $userId, int $restaurantId, string $action, string $message): void
{
    if (function_exists('log_action')) {
        log_action($userId, $restaurantId, $action, $message);
    }
}

$schema = menu_manage_item_schema();
// KDS station column rollout (best-effort, backward compatible).
if (!$schema['station']) {
    try {
        $pdo->exec("ALTER TABLE menu_items ADD COLUMN station VARCHAR(50) NOT NULL DEFAULT 'hot'");
    } catch (Throwable $e) {
        // ignore - no permissions or column already exists
    }
    $schema = menu_manage_item_schema();
}

$stmt = $pdo->prepare('
    SELECT * FROM menu_categories
    WHERE restaurant_id = :rest
    ORDER BY sort_order ASC, id ASC
');
$stmt->execute(['rest' => $restaurantId]);
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
$catsById = [];
foreach ($categories as $c) {
    $catsById[(int)$c['id']] = $c;
}

$postAction = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfOk = isset($_POST['csrf'], $_SESSION['csrf'])
        && hash_equals((string)$_SESSION['csrf'], (string)$_POST['csrf']);
    if (!$csrfOk) {
        $errors[] = 'Неверный запрос. Обновите страницу и попробуйте снова.';
    } else {
        $action = (string)($_POST['action'] ?? '');
        $postAction = $action;

        if ($action === 'add_category') {
            $name = trim((string)($_POST['name'] ?? ''));
            $sortOrder = isset($_POST['sort_order']) ? (int)$_POST['sort_order'] : 0;
            if ($name === '') {
                $errors[] = 'Введите название категории.';
            }
            if (!$errors) {
                if ($sortOrder === 0) {
                    $st = $pdo->prepare('
                        SELECT COALESCE(MAX(sort_order), 0) AS max_sort
                        FROM menu_categories
                        WHERE restaurant_id = :rest
                    ');
                    $st->execute(['rest' => $restaurantId]);
                    $row = $st->fetch(PDO::FETCH_ASSOC);
                    $sortOrder = (int)($row['max_sort'] ?? 0) + 10;
                }
                $ins = $pdo->prepare('
                    INSERT INTO menu_categories (restaurant_id, name, sort_order)
                    VALUES (:rest, :name, :sort_order)
                ');
                $ins->execute([
                    'rest' => $restaurantId,
                    'name' => $name,
                    'sort_order' => $sortOrder,
                ]);
                menu_manage_log(isset($user['id']) ? (int)$user['id'] : null, $restaurantId, 'create_menu_category', "Создана категория «{$name}»");
                $success = 'Категория добавлена.';
            }
        }

        if ($action === 'edit_category') {
            $catId = (int)($_POST['id'] ?? 0);
            $name = trim((string)($_POST['name'] ?? ''));
            if ($catId <= 0 || !isset($catsById[$catId])) {
                $errors[] = 'Категория не найдена.';
            } elseif ($name === '') {
                $errors[] = 'Введите название категории.';
            }
            if (!$errors) {
                $upd = $pdo->prepare('
                    UPDATE menu_categories
                    SET name = :name
                    WHERE id = :id AND restaurant_id = :rest
                    LIMIT 1
                ');
                $upd->execute(['name' => $name, 'id' => $catId, 'rest' => $restaurantId]);
                menu_manage_log(isset($user['id']) ? (int)$user['id'] : null, $restaurantId, 'update_menu_category', "Категория #{$catId} переименована в «{$name}»");
                $success = 'Категория обновлена.';
            }
        }

        if ($action === 'save_category_sort') {
            $sorts = $_POST['sort'] ?? [];
            if (!is_array($sorts)) {
                $sorts = [];
            }
            foreach ($sorts as $id => $val) {
                $id = (int)$id;
                $val = (int)$val;
                if ($id <= 0 || !isset($catsById[$id])) {
                    continue;
                }
                $st = $pdo->prepare('
                    UPDATE menu_categories
                    SET sort_order = :sort_order
                    WHERE id = :id AND restaurant_id = :rest
                ');
                $st->execute(['sort_order' => $val, 'id' => $id, 'rest' => $restaurantId]);
            }
            menu_manage_log(isset($user['id']) ? (int)$user['id'] : null, $restaurantId, 'update_menu_category_sort', 'Обновлена сортировка категорий');
            $success = 'Сортировка категорий сохранена.';
        }

        if ($action === 'delete_category') {
            $catId = (int)($_POST['id'] ?? 0);
            if ($catId <= 0 || !isset($catsById[$catId])) {
                $errors[] = 'Категория не найдена.';
            } else {
                $st = $pdo->prepare('
                    SELECT COUNT(*) AS cnt
                    FROM menu_items
                    WHERE restaurant_id = :rest AND category_id = :cat_id
                ');
                $st->execute(['rest' => $restaurantId, 'cat_id' => $catId]);
                $cnt = (int)($st->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0);
                if ($cnt > 0) {
                    $errors[] = 'Нельзя удалить категорию, в которой есть блюда. Сначала удалите или перенесите блюда в другую категорию.';
                } else {
                    $del = $pdo->prepare('DELETE FROM menu_categories WHERE id = :id AND restaurant_id = :rest LIMIT 1');
                    $del->execute(['id' => $catId, 'rest' => $restaurantId]);
                    menu_manage_log(isset($user['id']) ? (int)$user['id'] : null, $restaurantId, 'delete_menu_category', "Удалена категория #{$catId}");
                    $success = 'Категория удалена.';
                }
            }
        }

        if ($action === 'add_item' || $action === 'edit_item') {
            $id = (int)($_POST['id'] ?? 0);
            $name = trim((string)($_POST['name'] ?? ''));
            $categoryId = (int)($_POST['category_id'] ?? 0);
            $description = trim((string)($_POST['description'] ?? ''));
            $station = strtolower(trim((string)($_POST['station'] ?? 'hot')));
            if (!in_array($station, ['hot', 'cold', 'bar', 'dessert'], true)) {
                $station = 'hot';
            }
            $price = (float)str_replace(',', '.', (string)($_POST['price'] ?? '0'));
            $available = isset($_POST['available']) ? 1 : 0;
            $removeImage = $action === 'edit_item' && (int)($_POST['remove_image'] ?? 0) === 1;
            $imageUrlInput = trim((string)($_POST['image_url'] ?? ''));

            if ($name === '') {
                $errors[] = 'Введите название блюда.';
            }
            if ($categoryId <= 0 || !isset($catsById[$categoryId])) {
                $errors[] = 'Выберите категорию из списка.';
            }
            if ($price <= 0) {
                $errors[] = 'Укажите цену больше нуля.';
            }

            $imagePath = null;
            $uploadTried = !empty($_FILES['image']['name']);
            if ($schema['image_path'] && $uploadTried) {
                if (function_exists('is_demo_mode') && is_demo_mode()) {
                    $warnings[] = 'В демо-режиме загрузка изображений отключена.';
                } else {
                    $uploaded = menu_manage_upload_image($_FILES['image'], $restaurantId);
                    if ($uploaded === null) {
                        $warnings[] = 'Не удалось загрузить файл (до 2 МБ, jpg/png/webp). Блюдо можно сохранить без фото.';
                    } else {
                        $imagePath = $uploaded;
                    }
                }
            }

            if (!$errors) {
                if ($action === 'add_item') {
                    $cols = ['restaurant_id', 'category_id', 'name', 'price'];
                    $params = [
                        'restaurant_id' => $restaurantId,
                        'category_id' => $categoryId,
                        'name' => $name,
                        'price' => $price,
                    ];
                    if ($schema['description']) {
                        $cols[] = 'description';
                        $params['description'] = $description;
                    }
                    if ($schema['image_path']) {
                        $cols[] = 'image_path';
                        $params['image_path'] = $imagePath;
                    }
                    if ($schema['image_url']) {
                        $cols[] = 'image_url';
                        $params['image_url'] = ($imagePath !== null) ? null : ($imageUrlInput !== '' ? $imageUrlInput : null);
                    }
                    if ($schema['available']) {
                        $cols[] = 'available';
                        $params['available'] = $available;
                    }
                    if ($schema['station']) {
                        $cols[] = 'station';
                        $params['station'] = $station;
                    }
                    if ($schema['kitchen_station']) {
                        $cols[] = 'kitchen_station';
                        $params['kitchen_station'] = strtoupper($station);
                    }
                    $ph = array_map(static fn (string $c): string => ':' . $c, $cols);
                    $sql = 'INSERT INTO menu_items (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $ph) . ')';
                    $pdo->prepare($sql)->execute($params);
                    menu_manage_log(isset($user['id']) ? (int)$user['id'] : null, $restaurantId, 'create_menu_item', "Создано блюдо «{$name}»");
                    $success = 'Блюдо добавлено.';
                } elseif ($action === 'edit_item' && $id > 0) {
                    $st = $pdo->prepare('SELECT * FROM menu_items WHERE id = :id AND restaurant_id = :rest LIMIT 1');
                    $st->execute(['id' => $id, 'rest' => $restaurantId]);
                    $existing = $st->fetch(PDO::FETCH_ASSOC);
                    if (!$existing) {
                        $errors[] = 'Блюдо не найдено.';
                    } else {
                        $params = [
                            'category_id' => $categoryId,
                            'name' => $name,
                            'price' => $price,
                            'id' => $id,
                            'rest' => $restaurantId,
                        ];
                        $sets = ['category_id = :category_id', 'name = :name', 'price = :price'];
                        if ($schema['description']) {
                            $sets[] = 'description = :description';
                            $params['description'] = $description;
                        }
                        if ($schema['image_path']) {
                            if ($imagePath !== null) {
                                $sets[] = 'image_path = :image_path';
                                $params['image_path'] = $imagePath;
                                if ($schema['image_url']) {
                                    $sets[] = 'image_url = :image_url';
                                    $params['image_url'] = null;
                                }
                            } elseif ($removeImage) {
                                $sets[] = 'image_path = :image_path';
                                $params['image_path'] = null;
                                if ($schema['image_url']) {
                                    $sets[] = 'image_url = :image_url';
                                    $params['image_url'] = null;
                                }
                            }
                        } elseif ($schema['image_url'] && $imagePath === null && !$removeImage) {
                            $sets[] = 'image_url = :image_url';
                            $params['image_url'] = $imageUrlInput !== '' ? $imageUrlInput : null;
                        }
                        if ($schema['available']) {
                            $sets[] = 'available = :available';
                            $params['available'] = $available;
                        }
                        if ($schema['station']) {
                            $sets[] = 'station = :station';
                            $params['station'] = $station;
                        }
                        if ($schema['kitchen_station']) {
                            $sets[] = 'kitchen_station = :kitchen_station';
                            $params['kitchen_station'] = strtoupper($station);
                        }
                        $sql = 'UPDATE menu_items SET ' . implode(', ', $sets) . ' WHERE id = :id AND restaurant_id = :rest';
                        $pdo->prepare($sql)->execute($params);
                        menu_manage_log(isset($user['id']) ? (int)$user['id'] : null, $restaurantId, 'update_menu_item', "Обновлено блюдо #{$id} «{$name}»");
                        $success = 'Блюдо обновлено.';
                    }
                }
            }
        }

        if ($action === 'delete_item') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                $errors[] = 'Некорректный идентификатор блюда.';
            } else {
                $st = $pdo->prepare('DELETE FROM menu_items WHERE id = :id AND restaurant_id = :rest LIMIT 1');
                $st->execute(['id' => $id, 'rest' => $restaurantId]);
                if ($st->rowCount() < 1) {
                    $errors[] = 'Блюдо не найдено.';
                } else {
                    menu_manage_log(isset($user['id']) ? (int)$user['id'] : null, $restaurantId, 'delete_menu_item', "Удалено блюдо #{$id}");
                    $success = 'Блюдо удалено.';
                }
            }
        }

        if ($action === 'toggle_available' && $schema['available']) {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                $pdo->prepare('
                    UPDATE menu_items SET available = 1 - available
                    WHERE id = :id AND restaurant_id = :rest
                ')->execute(['id' => $id, 'rest' => $restaurantId]);
                menu_manage_log(isset($user['id']) ? (int)$user['id'] : null, $restaurantId, 'toggle_menu_item', "Переключена доступность блюда #{$id}");
                $success = 'Статус доступности изменён.';
            }
        }
    }

    if ($success && !$errors) {
        $q = isset($_POST['ret_q']) ? trim((string)$_POST['ret_q']) : '';
        $retCat = (int)($_POST['ret_cat'] ?? 0);
        $qs = [];
        if ($q !== '') {
            $qs['q'] = $q;
        }
        if ($retCat > 0) {
            $qs['cat'] = $retCat;
        }
        $loc = '/restaurant/menu_manage.php';
        if ($qs !== []) {
            $loc .= '?' . http_build_query($qs);
        }
        if (in_array($postAction, [
            'add_category', 'edit_category', 'delete_category', 'save_category_sort',
            'add_item', 'edit_item', 'delete_item', 'toggle_available',
        ], true)) {
            $_SESSION['menu_manage_flash_ok'] = $success;
            header('Location: ' . $loc, true, 302);
            exit;
        }
    }
}

if (!empty($_SESSION['menu_manage_flash_ok'])) {
    $success = (string)$_SESSION['menu_manage_flash_ok'];
    unset($_SESSION['menu_manage_flash_ok']);
}

$stmt = $pdo->prepare('
    SELECT * FROM menu_categories
    WHERE restaurant_id = :rest
    ORDER BY sort_order ASC, id ASC
');
$stmt->execute(['rest' => $restaurantId]);
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
$catsById = [];
foreach ($categories as $c) {
    $catsById[(int)$c['id']] = $c;
}

$sqlItems = '
    SELECT mi.*, mc.name AS category_name, mc.sort_order AS cat_sort
    FROM menu_items mi
    LEFT JOIN menu_categories mc ON mc.id = mi.category_id AND mc.restaurant_id = mi.restaurant_id
    WHERE mi.restaurant_id = :rest
';
$sqlItems .= ' ORDER BY mc.sort_order ASC, mc.id ASC, mi.name ASC';
$stmt = $pdo->prepare($sqlItems);
$stmt->execute(['rest' => $restaurantId]);
$allItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

$searchQ = trim((string)($_GET['q'] ?? ''));
$filterCat = (int)($_GET['cat'] ?? 0);
$items = [];
foreach ($allItems as $it) {
    if ($searchQ !== '' && mb_stripos((string)$it['name'], $searchQ, 0, 'UTF-8') === false) {
        continue;
    }
    if ($filterCat > 0 && (int)($it['category_id'] ?? 0) !== $filterCat) {
        continue;
    }
    $items[] = $it;
}

$editCatId = (int)($_GET['edit_cat'] ?? 0);
$editCategory = ($editCatId > 0 && isset($catsById[$editCatId])) ? $catsById[$editCatId] : null;

$editItemId = (int)($_GET['edit'] ?? 0);
$editItem = null;
if ($editItemId > 0) {
    foreach ($allItems as $it) {
        if ((int)$it['id'] === $editItemId) {
            $editItem = $it;
            break;
        }
    }
}

$itemsEmpty = $allItems === [];
$itemsFilteredEmpty = $items === [] && !$itemsEmpty;

$appName = 'QR-Rest Cloud';
$configPath = __DIR__ . '/../../config.php';
if (is_file($configPath)) {
    $cfg = require $configPath;
    if (!empty($cfg['app']['name'])) {
        $appName = (string)$cfg['app']['name'];
    }
}

$restaurantName = (string)($currentRestaurant['name'] ?? '');

$cfgMenu = [];
$cfgFile = __DIR__ . '/../../app/config.php';
if (is_file($cfgFile)) {
    $cfgMenu = require $cfgFile;
}
$guestProto = isset($cfgMenu['app']['protocol']) && $cfgMenu['app']['protocol'] !== ''
    ? (string)$cfgMenu['app']['protocol']
    : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http');
$guestMain = isset($cfgMenu['app']['main_domain']) ? (string)$cfgMenu['app']['main_domain'] : '';
$guestSub = isset($currentRestaurant['subdomain']) ? trim((string)$currentRestaurant['subdomain']) : '';
$guestMenuUrl = '';
if ($guestMain !== '' && $guestSub !== '') {
    $guestMenuUrl = $guestProto . '://' . $guestSub . '.' . $guestMain . '/qr.php?table_id=1';
}

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?= brand_head_tags() ?>
    <title>Menu Management — <?= e($restaurantName) ?> — <?= e($appName) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { font-family: Inter, system-ui, sans-serif; }
        .mm-scroll::-webkit-scrollbar { height: 6px; }
        .mm-scroll::-webkit-scrollbar-thumb { background: rgba(100,116,139,.45); border-radius: 9999px; }
    </style>
</head>
<body class="min-h-screen text-slate-100 antialiased flex flex-col md:flex-row overflow-x-hidden bg-[#0B0F19]">

<!-- Mobile top bar -->
<div class="md:hidden sticky top-0 z-40 flex items-center justify-between gap-3 px-4 py-3 border-b border-slate-800/80 bg-[#0B0F19]/95 backdrop-blur-md" style="padding-top:max(0.75rem, env(safe-area-inset-top))">
    <span class="text-sm font-semibold text-white truncate min-w-0"><?= e($restaurantName) ?></span>
    <button type="button" id="mm-nav-open" class="shrink-0 min-h-[44px] min-w-[44px] rounded-xl border border-slate-700 bg-slate-900/80 text-sm font-medium text-slate-200 touch-manipulation" aria-expanded="false" aria-controls="mm-nav-panel">Menu</button>
</div>
<div id="mm-nav-overlay" class="fixed inset-0 z-50 hidden md:hidden" aria-hidden="true">
    <button type="button" id="mm-nav-backdrop" class="absolute inset-0 bg-black/70" aria-label="Close menu"></button>
    <div id="mm-nav-panel" class="absolute right-0 top-0 bottom-0 w-[min(100%,19rem)] bg-[#0c1222] border-l border-slate-800 shadow-2xl flex flex-col" style="padding:max(1rem, env(safe-area-inset-top)) max(1rem, env(safe-area-inset-right)) max(1rem, env(safe-area-inset-bottom)) max(1rem, env(safe-area-inset-left))">
        <div class="flex justify-between items-center gap-2 mb-6">
            <?= brand_restaurant_sidebar_header_html($restaurantName) ?>
            <button type="button" id="mm-nav-close" class="min-h-[44px] min-w-[44px] rounded-xl border border-slate-700 text-slate-200 text-xl leading-none touch-manipulation" aria-label="Close">×</button>
        </div>
        <nav class="flex flex-col gap-1 text-[15px] font-medium flex-1">
            <a href="/restaurant/dashboard.php" class="px-3 py-3 rounded-xl text-slate-400 hover:text-white hover:bg-white/5 min-h-[44px] flex items-center">Dashboard</a>
            <a href="/restaurant/menu_manage.php" class="px-3 py-3 rounded-xl text-emerald-300 bg-emerald-500/10 border border-emerald-500/25 min-h-[44px] flex items-center">Menu</a>
            <a href="/restaurant/qr_codes.php" class="px-3 py-3 rounded-xl text-slate-400 hover:text-white hover:bg-white/5 min-h-[44px] flex items-center">QR Codes</a>
            <a href="/restaurant/crm.php" class="px-3 py-3 rounded-xl text-slate-400 hover:text-white hover:bg-white/5 min-h-[44px] flex items-center">CRM</a>
            <a href="/restaurant/settings.php" class="px-3 py-3 rounded-xl text-slate-400 hover:text-white hover:bg-white/5 min-h-[44px] flex items-center">Settings</a>
        </nav>
        <a href="/logout.php" class="mt-4 px-3 py-3 rounded-xl text-sm text-slate-500 hover:text-red-300 hover:bg-red-500/10 border border-transparent min-h-[44px] flex items-center">Logout</a>
    </div>
</div>

<!-- Desktop sidebar -->
<aside class="hidden md:flex w-[272px] shrink-0 flex-col bg-[#0c1222] border-r border-slate-800/80 md:min-h-screen">
    <div class="p-6 md:sticky md:top-0 md:max-h-screen md:flex md:flex-col md:min-h-screen">
        <?= brand_restaurant_sidebar_header_html($restaurantName) ?>
        <nav class="mt-8 flex flex-col gap-1 text-[15px] font-medium">
            <a href="/restaurant/dashboard.php" class="px-3 py-2.5 rounded-xl text-slate-400 hover:text-white hover:bg-white/5 transition-colors min-h-[44px] flex items-center">Dashboard</a>
            <a href="/restaurant/menu_manage.php" class="px-3 py-2.5 rounded-xl text-emerald-300 bg-emerald-500/10 border border-emerald-500/25 shadow-sm min-h-[44px] flex items-center">Menu</a>
            <a href="/restaurant/qr_codes.php" class="px-3 py-2.5 rounded-xl text-slate-400 hover:text-white hover:bg-white/5 transition-colors min-h-[44px] flex items-center">QR Codes</a>
            <a href="/restaurant/crm.php" class="px-3 py-2.5 rounded-xl text-slate-400 hover:text-white hover:bg-white/5 transition-colors min-h-[44px] flex items-center">CRM</a>
            <a href="/restaurant/settings.php" class="px-3 py-2.5 rounded-xl text-slate-400 hover:text-white hover:bg-white/5 transition-colors min-h-[44px] flex items-center">Settings</a>
        </nav>
        <div class="mt-auto pt-8 border-t border-slate-800/80">
            <a href="/logout.php" class="block px-3 py-2.5 rounded-xl text-sm text-slate-500 hover:text-red-300 hover:bg-red-500/10 transition-colors min-h-[44px] flex items-center">Logout</a>
        </div>
    </div>
</aside>

<main class="flex-1 min-w-0 overflow-x-hidden">
    <div class="max-w-6xl mx-auto px-4 py-6 md:px-10 md:py-10 space-y-8 pb-24 md:pb-10">

        <header class="rounded-2xl border border-slate-800/60 bg-gradient-to-br from-slate-900/80 to-[#121826]/90 p-6 md:p-8 shadow-xl shadow-black/20">
            <div class="flex flex-col gap-6 lg:flex-row lg:items-start lg:justify-between">
                <div class="space-y-2 min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-emerald-500/90">Menu</p>
                    <h1 class="text-2xl md:text-3xl font-bold text-white tracking-tight">Menu Management</h1>
                    <p class="text-slate-400 text-sm md:text-base"><?= e($restaurantName) ?></p>
                </div>
                <div class="flex flex-col sm:flex-row flex-wrap gap-3 shrink-0 w-full sm:w-auto">
                    <?php if ($guestMenuUrl !== ''): ?>
                        <a href="<?= e($guestMenuUrl) ?>" target="_blank" rel="noopener noreferrer"
                           class="inline-flex items-center justify-center gap-2 min-h-[48px] px-5 py-3 rounded-xl text-sm font-semibold bg-slate-800/90 border border-slate-600/50 text-slate-100 hover:bg-slate-700/90 hover:border-slate-500 transition-all touch-manipulation">
                            <span aria-hidden="true" class="text-emerald-400">↗</span> Open Guest Menu
                        </a>
                    <?php endif; ?>
                    <a href="#dish-form"
                       class="inline-flex items-center justify-center min-h-[48px] px-6 py-3 rounded-xl text-sm font-semibold text-[#0B0F19] bg-gradient-to-r from-emerald-400 to-teal-500 hover:from-emerald-300 hover:to-teal-400 shadow-lg shadow-emerald-900/30 transition-all touch-manipulation">
                        Add Dish
                    </a>
                </div>
            </div>
        </header>

        <?php if ($success): ?>
            <div class="rounded-xl bg-emerald-500/10 border border-emerald-500/40 px-4 py-3.5 text-sm text-emerald-100" role="status">
                <?= e($success) ?>
            </div>
        <?php endif; ?>

        <?php if ($warnings): ?>
            <div class="rounded-xl bg-amber-500/10 border border-amber-500/40 px-4 py-3.5 text-sm text-amber-100 space-y-1">
                <?php foreach ($warnings as $w): ?>
                    <div><?= e($w) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($errors): ?>
            <div class="rounded-xl bg-red-500/10 border border-red-500/40 px-4 py-3.5 text-sm text-red-100 space-y-1" role="alert">
                <?php foreach ($errors as $err): ?>
                    <div><?= e($err) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php
        $tabQsBase = [];
        if ($searchQ !== '') {
            $tabQsBase['q'] = $searchQ;
        }
        ?>

        <section id="categories" class="scroll-mt-24 space-y-6">
            <div class="flex flex-col lg:flex-row lg:items-end lg:justify-between gap-5">
                <div>
                    <h2 class="text-lg font-semibold text-white tracking-tight">Categories</h2>
                    <p class="text-xs text-slate-500 mt-1">Filter dishes and organize your guest menu</p>
                </div>
                <form method="get" class="flex flex-col sm:flex-row gap-2 sm:items-center w-full lg:max-w-md">
                    <input type="hidden" name="cat" value="<?= (string)$filterCat ?>">
                    <input type="search" name="q" value="<?= e($searchQ) ?>" placeholder="Search dishes…"
                           class="flex-1 min-h-[48px] rounded-xl bg-[#0f172a]/90 border border-slate-700/80 px-4 py-3 text-sm text-slate-100 placeholder:text-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/35">
                    <button type="submit" class="min-h-[48px] px-5 py-3 rounded-xl text-sm font-semibold bg-slate-800 hover:bg-slate-700 border border-slate-600/50 transition-colors touch-manipulation">Search</button>
                </form>
            </div>

            <div class="-mx-1 px-1">
                <div class="flex gap-2 overflow-x-auto mm-scroll pb-1 items-center snap-x snap-mandatory">
                    <?php
                    $allTabQs = $tabQsBase;
                    $allHref = '/restaurant/menu_manage.php' . ($allTabQs !== [] ? '?' . http_build_query($allTabQs) : '') . '#dishes';
                    ?>
                    <a href="<?= e($allHref) ?>"
                       class="snap-start shrink-0 inline-flex items-center justify-center min-h-[44px] px-4 py-2 rounded-full text-sm font-semibold transition-all border <?= $filterCat === 0 ? 'bg-emerald-500/15 text-emerald-200 border-emerald-500/40 shadow-[0_0_20px_rgba(16,185,129,0.15)]' : 'bg-[#111827]/90 text-slate-400 border-slate-700/80 hover:text-white hover:border-slate-600' ?>">
                        All
                    </a>
                    <?php foreach ($categories as $c):
                        $tid = (int)$c['id'];
                        $tabQs = $tabQsBase;
                        $tabQs['cat'] = $tid;
                        $tabHref = '/restaurant/menu_manage.php?' . http_build_query($tabQs) . '#dishes';
                        $isActive = $filterCat === $tid;
                        ?>
                        <a href="<?= e($tabHref) ?>"
                           class="snap-start shrink-0 inline-flex items-center justify-center min-h-[44px] max-w-[min(240px,75vw)] px-4 py-2 rounded-full text-sm font-semibold transition-all border truncate <?= $isActive ? 'bg-emerald-500/15 text-emerald-200 border-emerald-500/40 shadow-[0_0_20px_rgba(16,185,129,0.15)]' : 'bg-[#111827]/90 text-slate-400 border-slate-700/80 hover:text-white hover:border-slate-600' ?>">
                            <?= e((string)$c['name']) ?>
                        </a>
                    <?php endforeach; ?>
                    <a href="#add-category-form" title="Add category"
                       class="snap-start shrink-0 inline-flex items-center justify-center min-h-[44px] px-4 py-2 rounded-full text-sm font-bold bg-slate-900/80 border border-dashed border-emerald-500/35 text-emerald-400/90 hover:bg-emerald-500/10 hover:border-emerald-500/60 transition-colors">
                        + Category
                    </a>
                </div>
            </div>

            <div id="add-category-form" class="scroll-mt-28 rounded-2xl border border-slate-800/80 bg-gradient-to-br from-slate-900/90 to-[#0f172a]/80 p-6 md:p-8 shadow-xl shadow-black/30">
                <div class="flex items-start gap-3 mb-6">
                    <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-emerald-500/15 border border-emerald-500/25 text-emerald-400 text-lg font-bold">+</div>
                    <div>
                        <h3 class="text-base font-semibold text-white">New category</h3>
                        <p class="text-xs text-slate-500 mt-0.5">Groups dishes in the guest menu (e.g. Mains, Drinks)</p>
                    </div>
                </div>
                <form method="post" class="grid gap-5 sm:grid-cols-2 lg:grid-cols-12 lg:items-end">
                    <input type="hidden" name="csrf" value="<?= e((string)$_SESSION['csrf']) ?>">
                    <input type="hidden" name="action" value="add_category">
                    <input type="hidden" name="ret_q" value="<?= e($searchQ) ?>">
                    <input type="hidden" name="ret_cat" value="<?= (string)$filterCat ?>">
                    <div class="sm:col-span-2 lg:col-span-6">
                        <label class="block text-xs font-medium text-slate-400 mb-2">Name</label>
                        <input type="text" name="name" required placeholder="e.g. Main courses"
                               class="w-full min-h-[48px] rounded-xl bg-[#0f172a] border border-slate-700/80 px-4 py-3 text-sm text-slate-100 placeholder:text-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/35">
                    </div>
                    <div class="sm:col-span-2 lg:col-span-3">
                        <label class="block text-xs font-medium text-slate-400 mb-2">Sort order <span class="text-slate-600 font-normal">(optional)</span></label>
                        <input type="number" name="sort_order" placeholder="Auto"
                               class="w-full min-h-[48px] rounded-xl bg-[#0f172a] border border-slate-700/80 px-4 py-3 text-sm text-slate-100 focus:outline-none focus:ring-2 focus:ring-emerald-500/35">
                    </div>
                    <div class="sm:col-span-2 lg:col-span-3">
                        <button type="submit" class="w-full min-h-[48px] px-4 py-3 rounded-xl text-sm font-bold text-[#0B0F19] bg-gradient-to-r from-emerald-400 to-teal-500 hover:from-emerald-300 hover:to-teal-400 shadow-lg shadow-emerald-900/25 transition-all touch-manipulation">
                            Create category
                        </button>
                    </div>
                </form>
            </div>

            <?php if ($editCategory): ?>
                <div class="rounded-2xl border border-emerald-500/25 bg-emerald-500/[0.06] p-6 md:p-7">
                    <h3 class="text-sm font-semibold text-emerald-200 mb-4">Edit category</h3>
                    <form method="post" class="flex flex-col sm:flex-row gap-3 sm:items-end">
                        <input type="hidden" name="csrf" value="<?= e((string)$_SESSION['csrf']) ?>">
                        <input type="hidden" name="action" value="edit_category">
                        <input type="hidden" name="id" value="<?= (int)$editCategory['id'] ?>">
                        <input type="hidden" name="ret_q" value="<?= e($searchQ) ?>">
                        <input type="hidden" name="ret_cat" value="<?= (string)$filterCat ?>">
                        <div class="flex-1 min-w-0">
                            <label class="block text-xs text-slate-500 mb-1.5">Name</label>
                            <input type="text" name="name" required value="<?= e((string)$editCategory['name']) ?>"
                                   class="w-full min-h-[48px] rounded-xl bg-[#0f172a] border border-slate-700 px-4 py-3 text-sm text-slate-100 focus:outline-none focus:ring-2 focus:ring-emerald-500/40">
                        </div>
                        <button type="submit" class="min-h-[48px] px-6 py-3 rounded-xl text-sm font-bold text-white bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-500 hover:to-teal-500 transition-all touch-manipulation">Save</button>
                        <a href="<?= e('/restaurant/menu_manage.php' . ($searchQ !== '' || $filterCat > 0 ? '?' . http_build_query(array_filter(['q' => $searchQ ?: null, 'cat' => $filterCat ?: null])) : '')) ?>#categories"
                           class="min-h-[48px] px-6 py-3 rounded-xl text-sm font-medium text-slate-400 hover:text-white bg-slate-800/90 border border-slate-700 text-center inline-flex items-center justify-center">Cancel</a>
                    </form>
                </div>
            <?php endif; ?>

            <?php if (!$categories): ?>
                <div class="rounded-2xl border border-dashed border-slate-600/60 bg-[#111827]/30 px-8 py-14 text-center space-y-4">
                    <div class="inline-flex h-16 w-16 items-center justify-center rounded-2xl bg-slate-800/80 border border-slate-700 text-2xl">📂</div>
                    <h3 class="text-lg font-semibold text-white">No categories yet</h3>
                    <p class="text-sm text-slate-400 max-w-md mx-auto">Create your first category — it’s required before you can add dishes to the menu.</p>
                    <a href="#add-category-form" class="inline-flex items-center justify-center min-h-[48px] px-6 py-3 rounded-xl text-sm font-bold text-[#0B0F19] bg-gradient-to-r from-emerald-400 to-teal-500 hover:from-emerald-300 hover:to-teal-400 shadow-lg shadow-emerald-900/30 transition-all">Add first category</a>
                </div>
            <?php else: ?>
                <details class="group rounded-2xl border border-slate-800/80 bg-slate-900/40 overflow-hidden">
                    <summary class="px-5 py-4 text-sm font-medium text-slate-300 cursor-pointer hover:text-white flex items-center justify-between gap-3 [&::-webkit-details-marker]:hidden list-none select-none">
                        <span>Reorder, rename & delete categories</span>
                        <span class="text-slate-500 group-open:rotate-180 transition-transform">▼</span>
                    </summary>
                    <div class="px-5 pb-6 pt-0 border-t border-slate-800/80">
                        <form id="frm-cat-sort" method="post" class="hidden" aria-hidden="true">
                            <input type="hidden" name="csrf" value="<?= e((string)$_SESSION['csrf']) ?>">
                            <input type="hidden" name="action" value="save_category_sort">
                            <input type="hidden" name="ret_q" value="<?= e($searchQ) ?>">
                            <input type="hidden" name="ret_cat" value="<?= (string)$filterCat ?>">
                        </form>
                        <div class="flex justify-end pt-4 pb-4">
                            <button type="submit" form="frm-cat-sort" class="min-h-[44px] px-5 py-2.5 rounded-xl text-sm font-semibold bg-slate-800 hover:bg-slate-700 border border-slate-600/50 transition-colors touch-manipulation">
                                Save order
                            </button>
                        </div>
                        <div class="space-y-3">
                            <?php foreach ($categories as $cat): ?>
                                <div class="flex flex-col sm:flex-row sm:items-center gap-4 rounded-xl border border-slate-800/90 bg-[#0f172a]/50 px-4 py-4">
                                    <div class="flex-1 min-w-0">
                                        <div class="font-medium text-slate-100 truncate"><?= e((string)$cat['name']) ?></div>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <label class="text-xs text-slate-500 whitespace-nowrap" for="sort-cat-<?= (int)$cat['id'] ?>">Order</label>
                                        <input id="sort-cat-<?= (int)$cat['id'] ?>" type="number" form="frm-cat-sort"
                                               name="sort[<?= (int)$cat['id'] ?>]" value="<?= (int)$cat['sort_order'] ?>"
                                               class="w-24 min-h-[44px] rounded-lg bg-[#0B0F19] border border-slate-700 px-2 py-2 text-sm text-center focus:ring-2 focus:ring-emerald-500/40">
                                    </div>
                                    <div class="flex flex-wrap gap-2">
                                        <a href="<?= e('/restaurant/menu_manage.php?' . http_build_query(array_filter(['edit_cat' => (int)$cat['id'], 'q' => $searchQ ?: null, 'cat' => $filterCat ?: null]))) ?>#categories"
                                           class="min-h-[44px] inline-flex items-center px-4 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-xs font-semibold text-slate-200 border border-slate-700/80">Edit</a>
                                        <form method="post" class="inline"
                                              onsubmit="return confirm('Удалить категорию «<?= e((string)$cat['name']) ?>»? Если в ней есть блюда, удаление будет отклонено.');">
                                            <input type="hidden" name="csrf" value="<?= e((string)$_SESSION['csrf']) ?>">
                                            <input type="hidden" name="action" value="delete_category">
                                            <input type="hidden" name="id" value="<?= (int)$cat['id'] ?>">
                                            <input type="hidden" name="ret_q" value="<?= e($searchQ) ?>">
                                            <input type="hidden" name="ret_cat" value="<?= (string)$filterCat ?>">
                                            <button type="submit" class="min-h-[44px] px-4 py-2 rounded-xl bg-red-500/10 border border-red-500/30 text-xs font-semibold text-red-200 hover:bg-red-500/20 touch-manipulation">Delete</button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </details>
            <?php endif; ?>
        </section>

        <section id="dishes" class="scroll-mt-24 space-y-8">
            <div>
                <h2 class="text-lg font-semibold text-white tracking-tight">Dishes</h2>
                <p class="text-xs text-slate-500 mt-1">Items in the current search &amp; category filter</p>
            </div>

            <div id="dish-form" class="scroll-mt-28 rounded-2xl border border-slate-800/80 bg-gradient-to-br from-slate-900/90 to-[#0f172a]/80 p-6 md:p-8 shadow-xl shadow-black/30">
                <div class="flex flex-col sm:flex-row sm:items-start gap-4 mb-8">
                    <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-emerald-500/15 border border-emerald-500/25 text-emerald-400">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>
                    </div>
                    <div class="min-w-0">
                        <h3 class="text-lg font-semibold text-white"><?= $editItem ? 'Edit dish' : 'Add dish' ?></h3>
                        <p class="text-xs text-slate-500 mt-1"><?= $editItem ? 'Update fields and save changes.' : 'Name, price, and category are required.' ?></p>
                    </div>
                </div>
                <?php if (!$categories): ?>
                    <div class="rounded-xl border border-amber-500/30 bg-amber-500/5 px-4 py-4 text-sm text-amber-100">
                        Create at least one category before adding dishes.
                    </div>
                <?php else: ?>
                    <form method="post" enctype="multipart/form-data" class="grid gap-6 md:grid-cols-12 md:gap-x-5 md:gap-y-6">
                        <input type="hidden" name="csrf" value="<?= e((string)$_SESSION['csrf']) ?>">
                        <input type="hidden" name="action" value="<?= $editItem ? 'edit_item' : 'add_item' ?>">
                        <input type="hidden" name="ret_q" value="<?= e($searchQ) ?>">
                        <input type="hidden" name="ret_cat" value="<?= (string)$filterCat ?>">
                        <?php if ($editItem): ?>
                            <input type="hidden" name="id" value="<?= (int)$editItem['id'] ?>">
                        <?php endif; ?>

                        <div class="md:col-span-12">
                            <label class="block text-xs font-medium text-slate-400 mb-2">Name</label>
                            <input type="text" name="name" required value="<?= e((string)($editItem['name'] ?? '')) ?>"
                                   class="w-full min-h-[48px] rounded-xl bg-[#0f172a] border border-slate-700/90 px-4 py-3 text-sm text-slate-100 placeholder:text-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/40">
                        </div>
                        <div class="md:col-span-12 lg:col-span-4">
                            <label class="block text-xs font-medium text-slate-400 mb-2">Category</label>
                            <div class="relative">
                                <select name="category_id" required class="w-full min-h-[48px] rounded-xl bg-[#0f172a] border border-slate-700/90 pl-4 pr-11 py-3 text-sm text-slate-100 focus:outline-none focus:ring-2 focus:ring-emerald-500/40 appearance-none">
                                    <option value="">— Select —</option>
                                    <?php foreach ($categories as $c): ?>
                                        <option value="<?= (int)$c['id'] ?>"
                                            <?= isset($editItem['category_id']) && (int)$editItem['category_id'] === (int)$c['id'] ? 'selected' : '' ?>>
                                            <?= e((string)$c['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <span class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-slate-500" aria-hidden="true">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                                </span>
                            </div>
                        </div>
                        <div class="md:col-span-12 lg:col-span-4">
                            <label class="block text-xs font-medium text-slate-400 mb-2">Станция кухни</label>
                            <?php
                            $currentStation = strtolower((string)($editItem['station'] ?? ($editItem['kitchen_station'] ?? 'hot')));
                            if (!in_array($currentStation, ['hot', 'cold', 'bar', 'dessert'], true)) {
                                $currentStation = 'hot';
                            }
                            ?>
                            <div class="relative">
                                <select name="station" class="w-full min-h-[48px] rounded-xl bg-[#0f172a] border border-slate-700/90 pl-4 pr-11 py-3 text-sm text-slate-100 focus:outline-none focus:ring-2 focus:ring-emerald-500/40 appearance-none">
                                    <option value="hot" <?= $currentStation === 'hot' ? 'selected' : '' ?>>Горячий цех</option>
                                    <option value="cold" <?= $currentStation === 'cold' ? 'selected' : '' ?>>Холодный цех</option>
                                    <option value="bar" <?= $currentStation === 'bar' ? 'selected' : '' ?>>Бар</option>
                                    <option value="dessert" <?= $currentStation === 'dessert' ? 'selected' : '' ?>>Десерты</option>
                                </select>
                                <span class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-slate-500" aria-hidden="true">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                                </span>
                            </div>
                        </div>
                        <div class="md:col-span-12 lg:col-span-4">
                            <label class="block text-xs font-medium text-slate-400 mb-2">Price (₽)</label>
                            <input type="text" name="price" required inputmode="decimal"
                                   value="<?= e(isset($editItem['price']) ? (string)$editItem['price'] : '') ?>"
                                   class="w-full min-h-[48px] rounded-xl bg-[#0f172a] border border-slate-700/90 px-4 py-3 text-sm text-slate-100 focus:outline-none focus:ring-2 focus:ring-emerald-500/40">
                        </div>
                        <?php if ($schema['description']): ?>
                            <div class="md:col-span-12">
                                <label class="block text-xs font-medium text-slate-400 mb-2">Description</label>
                                <textarea name="description" rows="3"
                                          class="w-full rounded-xl bg-[#0f172a] border border-slate-700/90 px-4 py-3 text-sm text-slate-100 focus:outline-none focus:ring-2 focus:ring-emerald-500/40"><?= e((string)($editItem['description'] ?? '')) ?></textarea>
                            </div>
                        <?php endif; ?>
                        <?php if ($schema['image_path']): ?>
                            <div class="md:col-span-12">
                                <label class="block text-xs font-medium text-slate-400 mb-2">Photo (JPG, PNG, WebP, max 2 MB)</label>
                                <input type="file" name="image" accept="image/jpeg,image/png,image/webp"
                                       class="block w-full min-h-[48px] text-sm text-slate-400 file:mr-3 file:py-2.5 file:px-4 file:rounded-xl file:border-0 file:bg-slate-800 file:text-slate-200 file:font-semibold file:cursor-pointer hover:file:bg-slate-700">
                                <?php
                                $curImg = ($editItem && function_exists('menu_item_image_url')) ? menu_item_image_url($editItem) : null;
                                if ($curImg): ?>
                                    <div class="mt-3 flex flex-wrap items-center gap-4">
                                        <img src="<?= e($curImg) ?>" alt="" class="h-20 w-20 rounded-xl object-cover border border-slate-700 shadow-md" loading="lazy">
                                        <?php if ($editItem): ?>
                                            <label class="inline-flex items-center gap-2.5 text-xs text-slate-400 cursor-pointer">
                                                <input type="checkbox" name="remove_image" value="1" class="rounded border-slate-600 bg-[#0f172a] w-4 h-4 accent-emerald-500">
                                                Remove current photo
                                            </label>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        <?php if ($schema['image_url'] && !$schema['image_path']): ?>
                            <div class="md:col-span-12">
                                <label class="block text-xs font-medium text-slate-400 mb-2">Image URL</label>
                                <input type="url" name="image_url" placeholder="https://…"
                                       value="<?= e((string)($editItem['image_url'] ?? '')) ?>"
                                       class="w-full min-h-[48px] rounded-xl bg-[#0f172a] border border-slate-700/90 px-4 py-3 text-sm text-slate-100 focus:outline-none focus:ring-2 focus:ring-emerald-500/40">
                            </div>
                        <?php elseif ($schema['image_url'] && $schema['image_path']): ?>
                            <div class="md:col-span-12">
                                <label class="block text-xs font-medium text-slate-400 mb-2">External image URL (if no file upload)</label>
                                <input type="url" name="image_url" placeholder="https://…"
                                       value="<?= e((string)($editItem['image_url'] ?? '')) ?>"
                                       class="w-full min-h-[48px] rounded-xl bg-[#0f172a] border border-slate-700/90 px-4 py-3 text-sm text-slate-100 focus:outline-none focus:ring-2 focus:ring-emerald-500/40">
                                <p class="text-[11px] text-slate-500 mt-2">Uploading a file will replace the URL.</p>
                            </div>
                        <?php endif; ?>
                        <?php if ($schema['available']): ?>
                            <div class="md:col-span-12 flex items-center gap-2">
                                <label class="inline-flex items-center gap-3 text-sm text-slate-300 cursor-pointer select-none">
                                    <input type="checkbox" name="available" value="1" class="rounded border-slate-600 bg-[#0f172a] w-5 h-5 accent-emerald-500"
                                        <?= (!$editItem || !empty($editItem['available'])) ? 'checked' : '' ?>>
                                    Available for ordering
                                </label>
                            </div>
                        <?php endif; ?>
                        <div class="md:col-span-12 flex flex-col sm:flex-row flex-wrap gap-3 pt-2">
                            <button type="submit" class="min-h-[48px] px-8 py-3 rounded-xl text-sm font-bold text-[#0B0F19] bg-gradient-to-r from-emerald-400 to-teal-500 hover:from-emerald-300 hover:to-teal-400 shadow-lg shadow-emerald-900/25 transition-all touch-manipulation sm:min-w-[200px]">
                                <?= $editItem ? 'Save' : 'Add dish' ?>
                            </button>
                            <?php if ($editItem): ?>
                                <a href="<?= e('/restaurant/menu_manage.php?' . http_build_query(array_filter(['q' => $searchQ ?: null, 'cat' => $filterCat ?: null]))) ?>#dishes"
                                   class="inline-flex items-center justify-center min-h-[48px] px-6 py-3 rounded-xl text-sm font-semibold bg-slate-800 hover:bg-slate-700 text-slate-200 border border-slate-600/50 transition-colors touch-manipulation">Cancel editing</a>
                            <?php endif; ?>
                        </div>
                    </form>
                <?php endif; ?>
            </div>

            <?php if ($itemsEmpty): ?>
                <div class="rounded-2xl border border-dashed border-slate-600/50 bg-[#111827]/40 px-8 py-16 text-center space-y-5">
                    <div class="inline-flex h-20 w-20 items-center justify-center rounded-2xl bg-gradient-to-br from-slate-800 to-[#0f172a] text-emerald-500/40 border border-slate-700/80">
                        <svg class="w-10 h-10" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>
                    </div>
                    <h4 class="text-xl font-bold text-white tracking-tight">No dishes yet</h4>
                    <p class="text-sm text-slate-400 max-w-md mx-auto">Add your first dish — it will appear on the guest menu as soon as you publish.</p>
                    <a href="#dish-form" class="inline-flex items-center justify-center min-h-[48px] px-8 py-3 rounded-xl text-sm font-bold text-[#0B0F19] bg-gradient-to-r from-emerald-400 to-teal-500 hover:from-emerald-300 hover:to-teal-400 shadow-lg shadow-emerald-900/30 transition-all touch-manipulation">Add first dish</a>
                </div>
            <?php elseif ($itemsFilteredEmpty): ?>
                <div class="rounded-2xl border border-slate-800/80 bg-[#0f172a]/40 px-8 py-12 text-center space-y-3">
                    <p class="text-slate-300 text-sm">Nothing matches the current filter.</p>
                    <a href="/restaurant/menu_manage.php#dishes" class="inline-flex items-center justify-center min-h-[44px] px-5 py-2 rounded-xl text-sm font-semibold text-emerald-300 bg-emerald-500/10 border border-emerald-500/30 hover:bg-emerald-500/15 transition-colors">Clear filter</a>
                </div>
            <?php else: ?>
                <div class="space-y-12">
                    <?php
                    $byCat = [];
                    foreach ($items as $it) {
                        $cn = (string)($it['category_name'] ?? '');
                        if ($cn === '') {
                            $cn = 'Без категории';
                        }
                        $byCat[$cn][] = $it;
                    }
                    ksort($byCat, SORT_NATURAL | SORT_FLAG_CASE);
                    foreach ($byCat as $catLabel => $list):
                        ?>
                        <div>
                            <h5 class="text-[11px] font-bold uppercase tracking-[0.15em] text-slate-500 mb-4 pl-0.5"><?= e($catLabel) ?></h5>
                            <div class="grid gap-5 sm:grid-cols-2 xl:grid-cols-3">
                                <?php foreach ($list as $it):
                                    $img = function_exists('menu_item_image_url') ? menu_item_image_url($it) : null;
                                    $avail = $schema['available'] ? (int)($it['available'] ?? 0) === 1 : null;
                                    $catLine = (string)($it['category_name'] ?? '');
                                    if ($catLine === '') {
                                        $catLine = 'Без категории';
                                    }
                                    ?>
                                    <article class="group rounded-2xl border border-slate-800/90 bg-[#0f172a]/60 p-0 overflow-hidden shadow-lg shadow-black/20 hover:border-emerald-500/25 hover:shadow-emerald-950/20 transition-all duration-200 flex flex-col">
                                        <div class="aspect-[16/10] w-full overflow-hidden bg-gradient-to-br from-slate-800/90 to-[#0B0F19] border-b border-slate-800/80">
                                            <?php if ($img): ?>
                                                <img src="<?= e($img) ?>" alt="" class="h-full w-full object-cover group-hover:scale-[1.03] transition-transform duration-300" loading="lazy">
                                            <?php else: ?>
                                                <div class="h-full w-full flex items-center justify-center text-slate-600">
                                                    <svg class="w-14 h-14 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.25" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="p-5 flex flex-col gap-3 flex-1">
                                            <div class="space-y-1 min-w-0">
                                                <p class="text-[11px] text-emerald-500/80 font-semibold uppercase tracking-wide truncate"><?= e($catLine) ?></p>
                                                <h6 class="text-base font-bold text-white leading-snug"><?= e((string)$it['name']) ?></h6>
                                                <p class="text-xl font-extrabold text-emerald-400 tabular-nums pt-0.5">
                                                    <?= e(number_format((float)$it['price'], 0, '.', ' ')) ?> <span class="text-base font-bold text-emerald-400/90">₽</span>
                                                </p>
                                            </div>
                                            <?php if ($schema['description'] && !empty($it['description'])): ?>
                                                <p class="text-xs text-slate-500 line-clamp-3 leading-relaxed"><?= e((string)$it['description']) ?></p>
                                            <?php endif; ?>
                                            <div class="flex flex-wrap items-stretch gap-2 pt-3 mt-auto border-t border-slate-800/80">
                                                <?php if ($avail !== null): ?>
                                                    <form method="post" class="inline flex-1 min-w-[7rem]">
                                                        <input type="hidden" name="csrf" value="<?= e((string)$_SESSION['csrf']) ?>">
                                                        <input type="hidden" name="action" value="toggle_available">
                                                        <input type="hidden" name="id" value="<?= (int)$it['id'] ?>">
                                                        <input type="hidden" name="ret_q" value="<?= e($searchQ) ?>">
                                                        <input type="hidden" name="ret_cat" value="<?= (string)$filterCat ?>">
                                                        <button type="submit" class="w-full min-h-[44px] px-3 py-2 rounded-xl text-xs font-bold border transition-colors touch-manipulation <?= $avail ? 'border-emerald-500/45 bg-emerald-500/10 text-emerald-200 hover:bg-emerald-500/15' : 'border-slate-600 bg-[#0B0F19] text-slate-400 hover:text-slate-200' ?>">
                                                            <?= $avail ? 'Available' : 'Hidden' ?>
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                                <a href="<?= e('/restaurant/menu_manage.php?' . http_build_query(array_filter(['edit' => (int)$it['id'], 'q' => $searchQ ?: null, 'cat' => $filterCat ?: null]))) ?>#dish-form"
                                                   class="inline-flex flex-1 min-w-[6rem] items-center justify-center min-h-[44px] px-4 py-2 rounded-xl text-xs font-bold text-white bg-slate-800 hover:bg-emerald-600/90 border border-slate-600/60 hover:border-emerald-500/40 transition-all touch-manipulation">
                                                    Edit
                                                </a>
                                                <form method="post" class="inline flex-1 min-w-[6rem]" onsubmit="return confirm('Удалить блюдо «<?= e((string)$it['name']) ?>»?');">
                                                    <input type="hidden" name="csrf" value="<?= e((string)$_SESSION['csrf']) ?>">
                                                    <input type="hidden" name="action" value="delete_item">
                                                    <input type="hidden" name="id" value="<?= (int)$it['id'] ?>">
                                                    <input type="hidden" name="ret_q" value="<?= e($searchQ) ?>">
                                                    <input type="hidden" name="ret_cat" value="<?= (string)$filterCat ?>">
                                                    <button type="submit" class="w-full min-h-[44px] inline-flex items-center justify-center px-4 py-2 rounded-xl text-xs font-bold text-red-200 bg-red-500/10 border border-red-500/30 hover:bg-red-500/20 transition-colors touch-manipulation">
                                                        Delete
                                                    </button>
                                                </form>
                                            </div>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>
</main>
<script>
(function () {
    var overlay = document.getElementById('mm-nav-overlay');
    var openBtn = document.getElementById('mm-nav-open');
    var closeBtn = document.getElementById('mm-nav-close');
    var backdrop = document.getElementById('mm-nav-backdrop');
    function openNav() {
        if (!overlay) return;
        overlay.classList.remove('hidden');
        document.body.style.overflow = 'hidden';
        if (openBtn) openBtn.setAttribute('aria-expanded', 'true');
    }
    function closeNav() {
        if (!overlay) return;
        overlay.classList.add('hidden');
        document.body.style.overflow = '';
        if (openBtn) openBtn.setAttribute('aria-expanded', 'false');
    }
    if (openBtn) openBtn.addEventListener('click', openNav);
    if (closeBtn) closeBtn.addEventListener('click', closeNav);
    if (backdrop) backdrop.addEventListener('click', closeNav);
    if (overlay) {
        overlay.querySelectorAll('a').forEach(function (a) {
            a.addEventListener('click', closeNav);
        });
    }
})();
</script>
</body>
</html>
