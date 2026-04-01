<?php

require_once __DIR__ . '/../../app/bootstrap.php';
if (file_exists(__DIR__ . '/../../app/schema_guard.php')) {
    require_once __DIR__ . '/../../app/schema_guard.php';
}
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
if (!function_exists('e')) {
    function e($v): string {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

require_login();
require_current_restaurant();

$restaurantId = (int)($currentRestaurant['id'] ?? 0);

require_restaurant_role($restaurantId, ['owner', 'admin']);

$pdo = function_exists('db') ? db() : ($GLOBALS['pdo'] ?? null);
if (!$pdo instanceof PDO) {
    http_response_code(500);
    echo "DB connection error";
    exit;
}
$hasStationCol = function_exists('db_column_exists') && db_column_exists('menu_items', 'station');
$hasKitchenStationCol = function_exists('db_column_exists') && db_column_exists('menu_items', 'kitchen_station');

$user = auth_user();
$errors  = [];
$warnings = [];
$success = null;

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}


const MENU_IMAGE_MAX_BYTES = 2 * 1024 * 1024; // 2MB
const MENU_IMAGE_ALLOWED_EXT = ['jpg', 'jpeg', 'png', 'webp'];

if (!function_exists('ensure_uploads_menu_dir')) {
    function ensure_uploads_menu_dir(int $restaurantId): string
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

if (!function_exists('upload_menu_image')) {
    function upload_menu_image(array $file, int $restaurantId = 0): ?string
    {
        if (empty($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) {
            return null;
        }
        $size = isset($file['size']) ? (int)$file['size'] : (int)@filesize((string)$file['tmp_name']);
        if ($size <= 0 || $size > MENU_IMAGE_MAX_BYTES) {
            return null;
        }
        $allowedMime = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeRaw = $finfo->file((string)$file['tmp_name']);
        $mime = is_string($mimeRaw) ? strtolower(trim($mimeRaw)) : '';

        $extFromName = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
        if ($extFromName === '' || !in_array($extFromName, MENU_IMAGE_ALLOWED_EXT, true)) {
            return null;
        }

        // If MIME is recognized, ensure extension matches MIME mapping.
        if (isset($allowedMime[$mime])) {
            $expectedExt = $allowedMime[$mime];
            if ($expectedExt !== $extFromName) {
                return null;
            }
            $ext = $expectedExt;
        } else {
            // Unknown MIME: fall back to extension-only (still validated above).
            $ext = $extFromName;
        }
        $baseName = preg_replace('/[^a-zA-Z0-9_-]/', '', pathinfo($file['name'], PATHINFO_FILENAME));
        if ($baseName === '') {
            $baseName = 'img';
        }
        $name = substr($baseName, 0, 80) . '_' . time() . '_' . mt_rand(1000, 9999) . '.' . $ext;
        $dir  = ensure_uploads_menu_dir($restaurantId);
        $full = $dir . '/' . $name;
        if (!@move_uploaded_file($file['tmp_name'], $full)) {
            return null;
        }
        return 'menu/' . (int)$restaurantId . '/' . $name;
    }
}

if (!function_exists('menu_item_image_url')) {
    function menu_item_image_url(array $item): ?string
    {
        $url = !empty($item['image_path']) ? $item['image_path'] : (!empty($item['image_url']) ? $item['image_url'] : null);
        if ($url === null || $url === '') {
            return null;
        }
        if (is_string($url) && preg_match('#^https?://#i', $url) === 1) {
            return $url;
        }
        $publicRoot = dirname(__DIR__);
        if (strpos($url, 'menu/') === 0) {
            $abs = $publicRoot . '/uploads/' . $url;
            return file_exists($abs) ? ('/uploads/' . $url) : null;
        }
        $abs = $publicRoot . '/storage/' . $url;
        return file_exists($abs) ? ('/storage/' . $url) : null;
    }
}

if (!function_exists('log_action')) {
    function log_action($userId, $restaurantId, string $action, string $message, string $level = 'info'): void
    {
        $pdo = function_exists('db') ? db() : ($GLOBALS['pdo'] ?? null);

        if (!$pdo instanceof PDO) {
            return;
        }
        if (!function_exists('add_log')) {
            return;
        }

        add_log($pdo, [
            'user_id'       => $userId !== null ? (int)$userId : null,
            'restaurant_id' => $restaurantId !== null ? (int)$restaurantId : null,
            'level'         => $level,
            'action'        => $action,
            'message'       => $message,
        ]);
    }
}


$stmt = $pdo->prepare("
    SELECT * FROM menu_categories
    WHERE restaurant_id = :rest
    ORDER BY sort_order ASC, id ASC
");
$stmt->execute(['rest' => $restaurantId]);
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

$catsById = [];
foreach ($categories as $c) {
    $catsById[(int)$c['id']] = $c;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfOk = isset($_POST['csrf'], $_SESSION['csrf']) && hash_equals((string)$_SESSION['csrf'], (string)$_POST['csrf']);
    if (!$csrfOk) {
        $errors[] = 'Неверный запрос. Обновите страницу и попробуйте снова.';
    } else {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_item' || $action === 'edit_item') {
        $id          = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $name        = trim($_POST['name'] ?? '');
        $category_id = (int)($_POST['category_id'] ?? 0);
        $description = trim($_POST['description'] ?? '');
        $station     = strtolower(trim((string)($_POST['station'] ?? 'hot')));
        if (!in_array($station, ['hot', 'cold', 'bar', 'dessert'], true)) {
            $station = 'hot';
        }
        $price       = (float)str_replace(',', '.', $_POST['price'] ?? '0');
        $available   = isset($_POST['available']) ? 1 : 0;
        $removeImage = $action === 'edit_item' ? ((int)($_POST['remove_image'] ?? 0) === 1) : false;

        if ($name === '') {
            $errors[] = 'Введите название блюда';
        }
        if ($category_id <= 0 || !isset($catsById[$category_id])) {
            $errors[] = 'Выберите категорию';
        }
        if ($price <= 0) {
            $errors[] = 'Укажите корректную цену';
        }

        $imagePath = null;

        if (!empty($_FILES['image']['name'])) {
            if (function_exists('is_demo_mode') && is_demo_mode()) {
                $warnings[] = 'В демо-режиме загрузка изображений отключена.';
            } else {
                $uploadedPath = upload_menu_image($_FILES['image'], $restaurantId);
                if ($uploadedPath === null) {
                    $warnings[] = 'Не удалось загрузить изображение (макс 2 МБ, только jpg, png, webp). Блюдо сохранено без фото.';
                } else {
                    $imagePath = $uploadedPath;
                }
            }
        }

        if (!$errors) {
            if ($action === 'add_item') {
                $cols = ['restaurant_id', 'category_id', 'name', 'description', 'price', 'image_path', 'image_url', 'available'];
                $paramsIns = [
                    'rest'   => $restaurantId,
                    'cat'    => $category_id,
                    'name'   => $name,
                    'descr'  => $description,
                    'price'  => $price,
                    'img'    => $imagePath,
                    'imgurl' => null,
                    'avail'  => $available,
                ];
                if ($hasStationCol) {
                    $cols[] = 'station';
                    $paramsIns['station'] = $station;
                }
                if ($hasKitchenStationCol) {
                    $cols[] = 'kitchen_station';
                    $paramsIns['kitchen_station'] = strtoupper($station);
                }
                $stmt = $pdo->prepare("
                    INSERT INTO menu_items (" . implode(', ', $cols) . ")
                    VALUES (:" . implode(', :', array_keys($paramsIns)) . ")
                ");
                $stmt->execute($paramsIns);

                log_action($user['id'] ?? null, $restaurantId,
                    'create_menu_item', "Создано блюдо «{$name}» (категория #{$category_id})");

                $success = 'Блюдо добавлено.';
            } elseif ($action === 'edit_item' && $id > 0) {

                $stmt = $pdo->prepare("
                    SELECT * FROM menu_items
                    WHERE id = :id AND restaurant_id = :rest
                ");
                $stmt->execute([
                    'id'   => $id,
                    'rest' => $restaurantId,
                ]);
                $item = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$item) {
                    $errors[] = 'Блюдо не найдено.';
                } else {
                    $fields = [
                        'category_id' => $category_id,
                        'name'        => $name,
                        'description' => $description,
                        'price'       => $price,
                        'available'   => $available,
                    ];
                    if ($hasStationCol) {
                        $fields['station'] = $station;
                    }
                    if ($hasKitchenStationCol) {
                        $fields['kitchen_station'] = strtoupper($station);
                    }
                    if ($imagePath !== null) {
                        $fields['image_path'] = $imagePath;
                        $fields['image_url'] = null;
                    } elseif ($removeImage) {
                        $fields['image_path'] = null;
                        $fields['image_url'] = null;
                    }

                    $sets   = [];
                    $params = [];
                    foreach ($fields as $k => $v) {
                        $sets[]          = "{$k} = :{$k}";
                        $params[":{$k}"] = $v;
                    }

                    $params[':id']   = $id;
                    $params[':rest'] = $restaurantId;

                    $sql = "UPDATE menu_items SET " . implode(', ', $sets) . " WHERE id = :id AND restaurant_id = :rest";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($params);

                    log_action($user['id'] ?? null, $restaurantId,
                        'update_menu_item', "Обновлено блюдо #{$id} «{$name}»");

                    $success = 'Блюдо обновлено.';
                }
            }
        }
    }


    if (($action = $_POST['action'] ?? '') === 'toggle_available') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("
            UPDATE menu_items
            SET available = 1 - available
            WHERE id = :id AND restaurant_id = :rest
        ");
        $stmt->execute([
            'id'   => $id,
            'rest' => $restaurantId,
        ]);

        log_action($user['id'] ?? null, $restaurantId,
            'toggle_menu_item', "Переключена доступность блюда #{$id}");

        $success = 'Статус доступности изменён.';
    }
    if (($action = $_POST['action'] ?? '') === 'add_upsell_rule') {
        $triggerId  = (int)($_POST['trigger_item_id'] ?? 0);
        $suggestId  = (int)($_POST['suggest_item_id'] ?? 0);
        $priority   = (int)($_POST['priority'] ?? 0);

        if ($triggerId <= 0 || $suggestId <= 0) {
            $errors[] = 'Выберите блюда для правила допродажи.';
        } elseif ($triggerId === $suggestId) {
            $errors[] = 'Нельзя рекомендовать блюдо само к себе.';
        } elseif (!isset($itemsById[$triggerId]) || !isset($itemsById[$suggestId])) {
            $errors[] = 'Выбранные блюда не найдены.';
        }

        if (!$errors) {
            try {
                $stmt = $pdo->prepare("
                    SELECT id FROM menu_upsell_rules
                    WHERE restaurant_id = :rest
                      AND trigger_item_id = :tr
                      AND suggest_item_id = :sg
                    LIMIT 1
                ");
                $stmt->execute([
                    'rest' => $restaurantId,
                    'tr'   => $triggerId,
                    'sg'   => $suggestId,
                ]);
                $existingId = (int)$stmt->fetchColumn();
                if ($existingId > 0) {
                    $upd = $pdo->prepare("
                        UPDATE menu_upsell_rules
                        SET priority = :pr
                        WHERE id = :id AND restaurant_id = :rest
                    ");
                    $upd->execute([
                        'pr'   => $priority,
                        'id'   => $existingId,
                        'rest' => $restaurantId,
                    ]);
                } else {
                    $ins = $pdo->prepare("
                        INSERT INTO menu_upsell_rules (restaurant_id, trigger_item_id, suggest_item_id, priority)
                        VALUES (:rest, :tr, :sg, :pr)
                    ");
                    $ins->execute([
                        'rest' => $restaurantId,
                        'tr'   => $triggerId,
                        'sg'   => $suggestId,
                        'pr'   => $priority,
                    ]);
                }
                $success = 'Правило допродажи сохранено.';
            } catch (Throwable $e) {
                $errors[] = 'Не удалось сохранить правило допродажи.';
            }
        }
    }
    if (($action = $_POST['action'] ?? '') === 'delete_upsell_rule') {
        $ruleId = (int)($_POST['rule_id'] ?? 0);
        if ($ruleId > 0) {
            try {
                $stmt = $pdo->prepare("
                    DELETE FROM menu_upsell_rules
                    WHERE id = :id AND restaurant_id = :rest
                ");
                $stmt->execute([
                    'id'   => $ruleId,
                    'rest' => $restaurantId,
                ]);
                $success = 'Правило допродажи удалено.';
            } catch (Throwable $e) {
                $errors[] = 'Не удалось удалить правило допродажи.';
            }
        }
    }
    if (($action = $_POST['action'] ?? '') === 'toggle_upsell_rule' && function_exists('db_column_exists') && db_column_exists('menu_upsell_rules', 'enabled')) {
        $ruleId = (int)($_POST['rule_id'] ?? 0);
        $enabled = isset($_POST['enabled']) ? (int)$_POST['enabled'] : 0;
        if ($ruleId > 0 && in_array($enabled, [0, 1], true)) {
            try {
                $stmt = $pdo->prepare("UPDATE menu_upsell_rules SET enabled = :en WHERE id = :id AND restaurant_id = :rest");
                $stmt->execute(['en' => $enabled, 'id' => $ruleId, 'rest' => $restaurantId]);
                $success = $enabled ? 'Правило включено.' : 'Правило отключено.';
            } catch (Throwable $e) {
                $errors[] = 'Не удалось изменить правило.';
            }
        }
    }
    }
}


// Фильтр "без категории"
$filter       = $_GET['filter'] ?? '';
$filterUncat  = ($filter === 'uncategorized');

$sqlItems = "
    SELECT mi.*, mc.name AS category_name
    FROM menu_items mi
    LEFT JOIN menu_categories mc ON mc.id = mi.category_id AND mc.restaurant_id = mi.restaurant_id
    WHERE mi.restaurant_id = :rest
";
if ($filterUncat) {
    $sqlItems .= "
      AND (mi.category_id IS NULL OR mc.id IS NULL)
";
}
$sqlItems .= "
    ORDER BY mc.sort_order ASC, mc.id ASC, mi.id ASC
";

$stmt = $pdo->prepare($sqlItems);
$stmt->execute(['rest' => $restaurantId]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

$itemsById = [];
foreach ($items as $it) {
    $itemsById[(int)$it['id']] = $it;
}

$upsellRules = [];
try {
    $pdoRules = $pdo;
    $rulesSql = "
        SELECT r.*, t.name AS trigger_name, s.name AS suggest_name
        FROM menu_upsell_rules r
        JOIN menu_items t ON t.id = r.trigger_item_id AND t.restaurant_id = r.restaurant_id
        JOIN menu_items s ON s.id = r.suggest_item_id AND s.restaurant_id = r.restaurant_id
        WHERE r.restaurant_id = :rest
        ORDER BY r.priority DESC, r.id DESC
    ";
    $stmtRu = $pdoRules->prepare($rulesSql);
    $stmtRu->execute(['rest' => $restaurantId]);
    $upsellRules = $stmtRu->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $upsellRules = [];
}


$editItem = null;
if (isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    foreach ($items as $it) {
        if ((int)$it['id'] === $editId) {
            $editItem = $it;
            break;
        }
    }
}

$smartSort = ['items' => [], 'has_data' => false];
if (file_exists(__DIR__ . '/../../app/menu_sorting.php')) {
    require_once __DIR__ . '/../../app/menu_sorting.php';
    $smartSort = get_smart_menu_sorting($restaurantId);
}
if (is_demo_mode() && !$smartSort['has_data']) {
    $smartSort = ['items' => [['id' => 5, 'name' => 'Стейк рибай 250 г', 'position' => 1], ['id' => 7, 'name' => 'Паста карбонара', 'position' => 2], ['id' => 13, 'name' => 'Лимонад домашний 0,5 л', 'position' => 3]], 'has_data' => true];
}

$appName    = 'QR-Rest Cloud';
$configPath = __DIR__ . '/../../config.php';
if (is_file($configPath)) {
    $cfg = require $configPath;
    if (!empty($cfg['app']['name'])) {
        $appName = $cfg['app']['name'];
    }
}
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Блюда — <?= e($currentRestaurant['name']) ?> — <?= e($appName) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?= brand_head_tags() ?>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-950 text-slate-50 flex">
<aside class="w-64 bg-slate-950/80 border-r border-slate-800 p-4 hidden md:block">
    <?= brand_restaurant_sidebar_header_html($currentRestaurant['name']) ?>
    <nav class="space-y-2 text-sm">
         <a href="/restaurant/dashboard.php" class="block px-3 py-2 rounded-xl hover:bg-slate-800/60">
            Обзор
        </a>
        <a href="/restaurant/revenue.php" class="block px-3 py-2 rounded-xl hover:bg-slate-800/60">
            Доход
        </a>
        <a href="/restaurant/menu_categories.php" class="block px-3 py-2 rounded-xl hover:bg-slate-800/60">
            Категории меню
        </a>
        <a href="/restaurant/menu_items.php" class="block px-3 py-2 rounded-xl bg-slate-800/70">
            Блюда
        </a>
        <a href="/restaurant/tables.php" class="block px-3 py-2 rounded-xl hover:bg-slate-800/60">
            Столы и QR
        </a>
        <a href="/restaurant/qr_print.php" class="block px-3 py-2 rounded-xl hover:bg-slate-800/60">QR Print</a>
        <a href="/restaurant/floorplan.php" class="block px-3 py-2 rounded-xl hover:bg-slate-800/60">
            Карта столов
        </a>
        <a href="/restaurant/orders.php" class="block px-3 py-2 rounded-xl hover:bg-slate-800/60">
            Заказы
        </a>
        <a href="/restaurant/upsells.php" class="block px-3 py-2 rounded-xl hover:bg-slate-800/60">
            Допродажи
        </a>
        <a href="/restaurant/upsell_rules.php" class="block px-3 py-2 rounded-xl hover:bg-slate-800/60">
            Правила допродаж
        </a>
        <a href="/restaurant/analytics_upsell.php" class="block px-3 py-2 rounded-xl hover:bg-slate-800/60">
            Аналитика допродаж
        </a>
        <a href="/restaurant/crm.php" class="block px-3 py-2 rounded-xl hover:bg-slate-800/60">
            CRM
        </a>
        <a href="/restaurant/crm_campaigns.php" class="block px-3 py-2 rounded-xl hover:bg-slate-800/60">
            CRM кампании
        </a>
        <a href="/restaurant/staff.php" class="block px-3 py-2 rounded-xl hover:bg-slate-800/60">
            Сотрудники
        </a>
        <a href="/restaurant/setup.php" class="block px-3 py-2 rounded-xl hover:bg-slate-800/60">Setup</a>
        <a href="/restaurant/settings.php" class="block px-3 py-2 rounded-xl hover:bg-slate-800/60">
            Настройки
        </a>
        <a href="/logout.php" class="block px-3 py-2 rounded-xl hover:bg-slate-800/60 text-red-300">
            Выйти
        </a>
    </nav>
</aside>

<main class="flex-1 p-4">
    <div class="max-w-5xl mx-auto space-y-4">
        <div class="flex items-center justify-between gap-3">
            <h2 class="text-2xl font-bold mb-2">Блюда</h2>
            <?php if (!$categories): ?>
                <div class="text-xs text-red-300">
                    Сначала создайте категории меню.
                </div>
            <?php endif; ?>
        </div>

        <?php if ($success): ?>
            <div class="rounded-2xl bg-emerald-500/10 border border-emerald-500/50 px-4 py-3 text-sm text-emerald-100">
                <?= e($success) ?>
            </div>
        <?php endif; ?>

        <?php if ($errors): ?>
            <div class="rounded-2xl bg-red-500/10 border border-red-500/50 px-4 py-3 text-sm text-red-100 space-y-1">
                <?php foreach ($errors as $err): ?>
                    <div><?= e($err) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Форма добавления / редактирования -->
        <div class="bg-slate-900/80 border border-slate-800 rounded-3xl p-4">
            <h3 class="text-lg font-semibold mb-3">
                <?= $editItem ? 'Редактировать блюдо' : 'Добавить блюдо' ?>
            </h3>
            <form method="post" enctype="multipart/form-data" class="grid md:grid-cols-2 gap-4">
                <input type="hidden" name="action" value="<?= $editItem ? 'edit_item' : 'add_item' ?>">
                <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                <?php if ($editItem): ?>
                    <input type="hidden" name="id" value="<?= (int)$editItem['id'] ?>">
                <?php endif; ?>

                <div class="md:col-span-2">
                    <label class="block text-xs text-slate-300 mb-1">Название</label>
                    <input type="text" name="name" required
                           value="<?= e($editItem['name'] ?? '') ?>"
                           class="w-full rounded-xl bg-slate-950/70 border border-slate-700 px-3 py-2 text-sm">
                </div>

                <div>
                    <label class="block text-xs text-slate-300 mb-1">Категория</label>
                    <select name="category_id" required
                            class="w-full rounded-xl bg-slate-950/70 border border-slate-700 px-3 py-2 text-sm">
                        <option value="">— выберите —</option>
                        <?php foreach ($categories as $c): ?>
                            <option value="<?= (int)$c['id'] ?>"
                                <?= isset($editItem['category_id']) && (int)$editItem['category_id'] === (int)$c['id'] ? 'selected' : '' ?>>
                                <?= e($c['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-xs text-slate-300 mb-1">Цена (₽)</label>
                    <input type="text" name="price" required
                           value="<?= isset($editItem['price']) ? e($editItem['price']) : '' ?>"
                           class="w-full rounded-xl bg-slate-950/70 border border-slate-700 px-3 py-2 text-sm">
                </div>

                <div>
                    <label class="block text-xs text-slate-300 mb-1">Станция кухни</label>
                    <?php
                    $currentStation = strtolower((string)($editItem['station'] ?? ''));
                    if (!in_array($currentStation, ['hot', 'cold', 'bar', 'dessert'], true)) {
                        $legacyStation = strtoupper((string)($editItem['kitchen_station'] ?? ''));
                        $legacyMap = ['HOT' => 'hot', 'COLD' => 'cold', 'BAR' => 'bar', 'DESSERT' => 'dessert'];
                        $currentStation = $legacyMap[$legacyStation] ?? 'hot';
                    }
                    ?>
                    <select name="station"
                            class="w-full rounded-xl bg-slate-950/70 border border-slate-700 px-3 py-2 text-sm">
                        <option value="hot" <?= $currentStation === 'hot' ? 'selected' : '' ?>>Горячий цех</option>
                        <option value="cold" <?= $currentStation === 'cold' ? 'selected' : '' ?>>Холодный цех</option>
                        <option value="bar" <?= $currentStation === 'bar' ? 'selected' : '' ?>>Бар</option>
                        <option value="dessert" <?= $currentStation === 'dessert' ? 'selected' : '' ?>>Десерты</option>
                    </select>
                </div>

                <div class="md:col-span-2">
                    <label class="block text-xs text-slate-300 mb-1">Описание</label>
                    <textarea name="description" rows="3"
                              class="w-full rounded-xl bg-slate-950/70 border border-slate-700 px-3 py-2 text-sm"><?= e($editItem['description'] ?? '') ?></textarea>
                </div>

                <div>
                    <label class="block text-xs text-slate-300 mb-1">Изображение (опционально, макс 2 МБ, jpg/png/webp)</label>
                    <input type="file" name="image" accept="image/jpeg,image/png,image/webp"
                           class="w-full text-xs text-slate-300">
                    <?php
                    $currentImg = menu_item_image_url($editItem ?? []);
                    if ($currentImg): ?>
                        <div class="mt-2 text-xs text-slate-400">
                            Текущее:
                            <img src="<?= e($currentImg) ?>" alt="" loading="lazy"
                                 class="mt-1 h-16 w-16 object-cover rounded-xl border border-slate-700">
                        </div>
                        <?php if ($editItem): ?>
                            <label class="mt-2 inline-flex items-center gap-2 text-xs text-slate-300">
                                <input type="checkbox" name="remove_image" value="1"
                                       class="rounded bg-slate-950 border-slate-700">
                                Удалить текущее изображение
                            </label>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <div class="flex items-center gap-2 mt-4">
                    <label class="inline-flex items-center gap-2 text-xs text-slate-200">
                        <input type="checkbox" name="available" value="1"
                               <?= (!isset($editItem['available']) || $editItem['available']) ? 'checked' : '' ?>
                               class="rounded bg-slate-950 border-slate-700">
                        Доступно для заказа
                    </label>
                </div>

                <div class="md:col-span-2 flex gap-2 mt-2">
                    <button type="submit"
                            class="px-4 py-2.5 rounded-xl bg-emerald-500 hover:bg-emerald-400 text-slate-950 text-sm font-semibold">
                        <?= $editItem ? 'Сохранить' : 'Добавить блюдо' ?>
                    </button>
                    <?php if ($editItem): ?>
                        <a href="/restaurant/menu_items.php"
                           class="px-3 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-xs text-slate-100">
                            Отмена
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- Список блюд -->
        <div class="bg-slate-900/80 border border-slate-800 rounded-3xl p-4">
            <div class="flex items-center justify-between mb-3">
                <h3 class="text-lg font-semibold">Список блюд</h3>
                <?php if ($filterUncat): ?>
                    <div class="text-[11px] text-amber-200 flex items-center gap-2">
                        <span class="px-2 py-0.5 rounded-full border border-amber-400/60 bg-amber-500/10">
                            Фильтр: без категории
                        </span>
                        <a href="/restaurant/menu_items.php"
                           class="text-emerald-300 hover:text-emerald-200">
                            Сбросить фильтр
                        </a>
                    </div>
                <?php endif; ?>
            </div>
            <?php if (!$items): ?>
                <div class="rounded-xl border border-gray-800 bg-[#121826] p-8 text-center space-y-4">
                    <div class="inline-flex items-center justify-center w-14 h-14 rounded-2xl bg-slate-800 text-slate-400">
                        <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>
                    </div>
                    <div>
                        <h3 class="text-lg font-semibold text-[#F3F4F6]">No menu items yet</h3>
                        <p class="text-sm text-gray-400 mt-1">Add your first dish to start taking orders.</p>
                    </div>
                    <a href="/restaurant/menu_items.php?add=1" class="inline-flex items-center px-4 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-medium">Add Menu Item</a>
                </div>
            <?php else: ?>
                <div class="space-y-4">
                    <?php
                    $itemsByCat = [];
                    foreach ($items as $it) {
                        $catName = $it['category_name'] ?: 'Без категории';
                        $itemsByCat[$catName][] = $it;
                    }
                    ?>
                    <?php foreach ($itemsByCat as $catName => $list): ?>
                        <div>
                            <div class="text-sm font-semibold mb-2"><?= e($catName) ?></div>
                            <div class="grid md:grid-cols-2 gap-3">
                                <?php foreach ($list as $it): ?>
                                    <div class="bg-slate-950/80 border border-slate-800 rounded-3xl p-3 flex gap-3">
                                        <?php
                                        $itImg = menu_item_image_url($it);
                                        if ($itImg): ?>
                                            <div class="w-16 h-16 rounded-2xl overflow-hidden bg-slate-800 flex-shrink-0">
                                                <img src="<?= e($itImg) ?>" alt="<?= e($it['name']) ?>" loading="lazy"
                                                     class="w-full h-full object-cover">
                                            </div>
                                        <?php endif; ?>
                                        <div class="flex-1 flex flex-col">
                                            <div class="flex items-center justify-between gap-2">
                                                <div class="text-sm font-semibold"><?= e($it['name']) ?></div>
                                                <div class="text-sm text-emerald-400 font-semibold">
                                                    <?= number_format($it['price'], 0, '.', ' ') ?> ₽
                                                </div>
                                            </div>
                                            <?php if (!empty($it['description'])): ?>
                                                <div class="mt-1 text-xs text-slate-400 line-clamp-2">
                                                    <?= e($it['description']) ?>
                                                </div>
                                            <?php endif; ?>
                                            <div class="mt-2 flex items-center justify-between gap-2 text-xs">
                                                <form method="post" class="inline-block">
                                                    <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                                                    <input type="hidden" name="action" value="toggle_available">
                                                    <input type="hidden" name="id" value="<?= (int)$it['id'] ?>">
                                                    <button type="submit"
                                                            class="px-3 py-1.5 rounded-xl
                                                            <?= $it['available']
                                                                ? 'bg-emerald-500/10 border border-emerald-500/50 text-emerald-200'
                                                                : 'bg-slate-800 border border-slate-700 text-slate-300' ?>">
                                                        <?= $it['available'] ? 'Включено' : 'Выключено' ?>
                                                    </button>
                                                </form>
                                                <a href="/restaurant/menu_items.php?edit=<?= (int)$it['id'] ?>"
                                                   class="px-3 py-1.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-100">
                                                    Редактировать
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Upsell Rules tab (Smart Upsell Engine V2) -->
        <div class="mt-6 bg-slate-900/80 border border-slate-800 rounded-3xl p-4">
            <h3 class="text-lg font-semibold mb-3">Upsell Rules</h3>
            <p class="text-xs text-slate-500 mb-3">
                Create rules: when a guest adds one item, suggest another. Set priority (higher first). You can disable a rule without deleting it.
            </p>

            <form method="post" class="grid md:grid-cols-4 gap-3 items-end mb-4">
                <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                <input type="hidden" name="action" value="add_upsell_rule">
                <div>
                    <label class="block text-xs text-slate-300 mb-1">Когда выбирают блюдо</label>
                    <select name="trigger_item_id" class="w-full rounded-xl bg-slate-950 border border-slate-700 px-3 py-2 text-xs text-slate-50">
                        <option value="">— выбрать —</option>
                        <?php foreach ($items as $it): ?>
                            <option value="<?= (int)$it['id'] ?>"><?= e($it['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs text-slate-300 mb-1">Предлагать блюдо</label>
                    <select name="suggest_item_id" class="w-full rounded-xl bg-slate-950 border border-slate-700 px-3 py-2 text-xs text-slate-50">
                        <option value="">— выбрать —</option>
                        <?php foreach ($items as $it): ?>
                            <option value="<?= (int)$it['id'] ?>"><?= e($it['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs text-slate-300 mb-1">Приоритет</label>
                    <input type="number" name="priority" value="0"
                           class="w-full rounded-xl bg-slate-950 border border-slate-700 px-3 py-2 text-xs text-slate-50">
                </div>
                <div>
                    <button type="submit"
                            class="w-full px-4 py-2.5 rounded-xl bg-emerald-500 hover:bg-emerald-400 text-sm font-semibold text-slate-950">
                        Сохранить правило
                    </button>
                </div>
            </form>

            <?php
            $hasEnabledColumn = function_exists('db_column_exists') && db_column_exists('menu_upsell_rules', 'enabled');
            ?>
            <?php if ($upsellRules): ?>
                <div class="rounded-2xl border border-slate-800 bg-slate-950/60 overflow-x-auto">
                    <table class="min-w-full text-xs">
                        <thead>
                        <tr class="text-slate-400 border-b border-slate-800">
                            <th class="px-3 py-2 text-left">When guest chooses</th>
                            <th class="px-3 py-2 text-left">Suggest</th>
                            <th class="px-3 py-2 text-left">Priority</th>
                            <?php if ($hasEnabledColumn): ?><th class="px-3 py-2 text-left">Status</th><?php endif; ?>
                            <th class="px-3 py-2 text-left">Actions</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($upsellRules as $r): ?>
                            <tr class="border-b border-slate-800/70">
                                <td class="px-3 py-2 text-slate-200"><?= e($r['trigger_name'] ?? '') ?></td>
                                <td class="px-3 py-2 text-slate-200"><?= e($r['suggest_name'] ?? '') ?></td>
                                <td class="px-3 py-2 text-slate-300"><?= (int)($r['priority'] ?? 0) ?></td>
                                <?php if ($hasEnabledColumn): ?>
                                <td class="px-3 py-2">
                                    <?php $ruleEnabled = (int)($r['enabled'] ?? 1); ?>
                                    <?php if ($ruleEnabled): ?>
                                        <form method="post" class="inline">
                                            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                                            <input type="hidden" name="action" value="toggle_upsell_rule">
                                            <input type="hidden" name="rule_id" value="<?= (int)($r['id'] ?? 0) ?>">
                                            <input type="hidden" name="enabled" value="0">
                                            <button type="submit" class="px-2 py-1 rounded-lg bg-amber-800/60 hover:bg-amber-700/60 text-[11px] text-amber-200">Disable</button>
                                        </form>
                                    <?php else: ?>
                                        <form method="post" class="inline">
                                            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                                            <input type="hidden" name="action" value="toggle_upsell_rule">
                                            <input type="hidden" name="rule_id" value="<?= (int)($r['id'] ?? 0) ?>">
                                            <input type="hidden" name="enabled" value="1">
                                            <button type="submit" class="px-2 py-1 rounded-lg bg-emerald-800/60 hover:bg-emerald-700/60 text-[11px] text-emerald-200">Enable</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                                <?php endif; ?>
                                <td class="px-3 py-2">
                                    <form method="post" class="inline">
                                        <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                                        <input type="hidden" name="action" value="delete_upsell_rule">
                                        <input type="hidden" name="rule_id" value="<?= (int)($r['id'] ?? 0) ?>">
                                        <button type="submit"
                                                class="px-2 py-1 rounded-lg bg-slate-800 hover:bg-slate-700 text-[11px] text-slate-200">
                                            Delete
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p class="text-xs text-slate-500">Правила допродаж пока не настроены.</p>
            <?php endif; ?>
        </div>

        <?php if ($smartSort['has_data'] && !empty($smartSort['items'])): ?>
        <div class="rounded-xl border border-gray-800 bg-[#121826] shadow-lg p-5 space-y-3">
            <h3 class="text-sm font-semibold text-slate-100">Suggested menu order</h3>
            <p class="text-xs text-slate-500">Based on popularity (last 30 days). Apply order in menu settings when ready.</p>
            <ol class="list-decimal list-inside space-y-1 text-sm text-slate-200">
                <?php foreach (array_slice($smartSort['items'], 0, 10) as $s): ?>
                <li><?= e($s['name']) ?></li>
                <?php endforeach; ?>
            </ol>
            <button type="button" class="inline-flex items-center px-4 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-200 text-sm font-medium border border-slate-700">Apply suggested order (coming soon)</button>
        </div>
        <?php endif; ?>

    </div>
</main>
</body>
</html>
