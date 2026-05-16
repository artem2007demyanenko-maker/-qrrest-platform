<?php

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/menu_import.php';

$rid = bin2hex(random_bytes(4));
set_exception_handler(function (Throwable $e) use ($rid) {
    error_log('MENU_IMPORT_ERROR rid=' . $rid . ' ' . $e->getMessage());
    if (!headers_sent()) {
        http_response_code(500);
        echo '<h1>Ошибка импорта меню</h1><p>Попробуйте позже.</p><p style="font-size:11px;color:#666">RID: ' . htmlspecialchars($rid) . '</p>';
    }
    exit;
});

require_login();
require_current_restaurant();
require_current_restaurant_role(['owner', 'admin']);

$restId = (int)$currentRestaurant['id'];

if (!function_exists('e')) {
    function e($v): string {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

$errors = [];
$info   = [];
$previewRows = [];
$summary = null;
$mode = 'preview';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfOk = isset($_SESSION['csrf'], $_POST['csrf']) && hash_equals((string)$_SESSION['csrf'], (string)$_POST['csrf']);
    if (!$csrfOk) {
        $errors[] = 'Неверный токен. Обновите страницу.';
    } else {
        $mode = ($_POST['mode'] ?? 'preview') === 'import' ? 'import' : 'preview';

        $createCats   = !empty($_POST['create_missing_categories']);
        $updateItems  = !empty($_POST['update_existing_items']);
        $markMissing  = !empty($_POST['mark_missing_items_unavailable']);
        $options = [
            'create_missing_categories'       => $createCats,
            'update_existing_items'           => $updateItems,
            'mark_missing_items_unavailable'  => $markMissing,
        ];

        if (empty($_FILES['csv_file']['tmp_name']) || !is_uploaded_file($_FILES['csv_file']['tmp_name'])) {
            $errors[] = 'Выберите CSV‑файл для загрузки.';
        } else {
            $file = $_FILES['csv_file'];
            if (!empty($file['error']) && $file['error'] !== UPLOAD_ERR_OK) {
                $errors[] = 'Ошибка загрузки файла (код ' . (int)$file['error'] . ').';
            }
            if (($file['size'] ?? 0) > 2 * 1024 * 1024) {
                $errors[] = 'Файл слишком большой. Максимум 2 MB.';
            }
            $ext = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
            if ($ext !== 'csv') {
                if ($ext === 'xlsx') {
                    $errors[] = 'В версии v1 поддерживается только CSV UTF‑8. Экспортируйте Excel в CSV.';
                } else {
                    $errors[] = 'Поддерживается только CSV UTF‑8.';
                }
            }

            if (!$errors) {
                $parse = menu_import_parse_csv($file['tmp_name']);
                if (!$parse['ok'] && $parse['errors']) {
                    $errors = array_merge($errors, $parse['errors']);
                }
                $rows = $parse['rows'];
                if (!$rows) {
                    $errors[] = 'В файле не найдено ни одной строки.';
                } else {
                    $previewRows = menu_import_preview($restId, $rows, $options);
                    if ($mode === 'import' && !$errors) {
                        $summary = menu_import_apply($restId, $rows, $options);
                        if (!empty($summary['errors'])) {
                            $errors = array_merge($errors, $summary['errors']);
                        } else {
                            $info[] = 'Импорт выполнен.';
                        }
                    }
                }
            }
        }
    }
}

?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Импорт меню — <?= e($currentRestaurant['name']) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?= brand_head_tags() ?>

    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-950 text-slate-50 flex">
<?php
$restaurantSidebarActive = 'import_menu';
$restaurantSidebarName = (string)($currentRestaurant['name'] ?? 'Ресторан');
require __DIR__ . '/_sidebar_mobile.php';
?>

<?php require __DIR__ . '/_sidebar.php'; ?>

<main class="flex-1 p-4">
    <div class="max-w-5xl mx-auto space-y-4">
        <header>
            <h2 class="text-2xl font-bold mb-1">Импорт меню</h2>
            <p class="text-xs text-slate-500">
                Загрузите CSV UTF‑8 с колонками: category_name,item_name,price,description,available.
                В версии v1 поддерживается только CSV. Excel сначала экспортируйте в CSV.
            </p>
            <p class="text-xs text-slate-500 mt-1">
                <a href="/restaurant/import_menu_template.csv" class="text-emerald-400 hover:underline">Скачать шаблон CSV</a>
            </p>
        </header>

        <?php if ($info): ?>
            <div class="rounded-3xl bg-emerald-500/10 border border-emerald-500/60 px-4 py-3 text-sm text-emerald-100">
                <?php foreach ($info as $m): ?><div><?= e($m) ?></div><?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($errors): ?>
            <div class="rounded-3xl bg-red-500/10 border border-red-500/60 px-4 py-3 text-sm text-red-100">
                <?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?>
            </div>
        <?php endif; ?>

        <section class="bg-slate-900/80 border border-slate-800 rounded-3xl p-4">
            <form method="post" enctype="multipart/form-data" class="space-y-4">
                <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                <div>
                    <label class="block text-[11px] text-slate-400 mb-1">CSV‑файл</label>
                    <input type="file" name="csv_file" accept=".csv,text/csv"
                           class="block w-full text-sm text-slate-200 file:mr-3 file:py-2 file:px-4 file:rounded-2xl file:border-0 file:text-sm file:font-semibold file:bg-emerald-500 file:text-slate-950 hover:file:bg-emerald-400">
                </div>
                <div class="flex flex-wrap gap-4 text-sm">
                    <div>
                        <div class="text-[11px] text-slate-400 mb-1 uppercase tracking-wide">Режим</div>
                        <label class="flex items-center gap-2 text-xs text-slate-200">
                            <input type="radio" name="mode" value="preview" <?= $mode === 'preview' ? 'checked' : '' ?>>
                            Только предварительный просмотр
                        </label>
                        <label class="flex items-center gap-2 text-xs text-slate-200 mt-1">
                            <input type="radio" name="mode" value="import" <?= $mode === 'import' ? 'checked' : '' ?>>
                            Импортировать (создать/обновить)
                        </label>
                    </div>
                    <div>
                        <div class="text-[11px] text-slate-400 mb-1 uppercase tracking-wide">Опции</div>
                        <label class="flex items-center gap-2 text-xs text-slate-200">
                            <input type="checkbox" name="create_missing_categories" value="1" checked>
                            Создавать отсутствующие категории
                        </label>
                        <label class="flex items-center gap-2 text-xs text-slate-200 mt-1">
                            <input type="checkbox" name="update_existing_items" value="1">
                            Обновлять существующие блюда по имени
                        </label>
                        <label class="flex items-center gap-2 text-xs text-slate-200 mt-1">
                            <input type="checkbox" name="mark_missing_items_unavailable" value="1">
                            Пометить отсутствующие в файле блюда как недоступные
                        </label>
                    </div>
                </div>
                <button type="submit"
                        class="px-4 py-2 rounded-2xl bg-emerald-500 hover:bg-emerald-400 text-slate-950 text-sm font-semibold">
                    Загрузить и показать предпросмотр
                </button>
            </form>
        </section>

        <?php if ($summary): ?>
            <section class="bg-slate-900/80 border border-slate-800 rounded-3xl p-4">
                <h3 class="text-sm font-semibold mb-2">Результат импорта</h3>
                <div class="grid grid-cols-2 md:grid-cols-5 gap-2 text-xs text-slate-200">
                    <div>Категорий создано: <span class="font-semibold text-emerald-300"><?= (int)$summary['created_categories'] ?></span></div>
                    <div>Блюд создано: <span class="font-semibold text-emerald-300"><?= (int)$summary['created_items'] ?></span></div>
                    <div>Блюд обновлено: <span class="font-semibold text-sky-300"><?= (int)$summary['updated_items'] ?></span></div>
                    <div>Помечено недоступными: <span class="font-semibold text-amber-300"><?= (int)$summary['marked_unavailable'] ?></span></div>
                    <div>Пропущено: <span class="font-semibold text-slate-300"><?= (int)$summary['skipped'] ?></span></div>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($previewRows): ?>
            <section class="bg-slate-900/80 border border-slate-800 rounded-3xl p-4">
                <h3 class="text-sm font-semibold mb-3">Предпросмотр</h3>
                <div class="overflow-x-auto">
                    <table class="w-full text-xs">
                        <thead>
                        <tr class="text-left text-slate-400 border-b border-slate-700">
                            <th class="pb-2 pr-2">#</th>
                            <th class="pb-2 pr-2">Категория</th>
                            <th class="pb-2 pr-2">Блюдо</th>
                            <th class="pb-2 pr-2">Цена</th>
                            <th class="pb-2 pr-2">Описание</th>
                            <th class="pb-2 pr-2">Доступно</th>
                            <th class="pb-2 pr-2">Действие</th>
                            <th class="pb-2 pr-2">Ошибка</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($previewRows as $p): ?>
                            <?php $row = $p['row']; ?>
                            <tr class="border-b border-slate-800/70">
                                <td class="py-1 pr-2 text-slate-500"><?= (int)($row['row_num'] ?? 0) ?></td>
                                <td class="py-1 pr-2"><?= e($row['category_name'] ?? '') ?></td>
                                <td class="py-1 pr-2"><?= e($row['item_name'] ?? '') ?></td>
                                <td class="py-1 pr-2"><?= number_format((float)($row['price'] ?? 0), 0, '.', ' ') ?> ₽</td>
                                <td class="py-1 pr-2"><?= e($row['description'] ?? '') ?></td>
                                <td class="py-1 pr-2">
                                    <?= !empty($row['available']) ? '1' : '0' ?>
                                </td>
                                <td class="py-1 pr-2">
                                    <?php
                                    $a = (string)($p['action'] ?? 'skip');
                                    $label = [
                                        'create' => 'Создать',
                                        'update' => 'Обновить',
                                        'skip'   => 'Пропустить',
                                        'error'  => 'Ошибка',
                                    ][$a] ?? $a;
                                    ?>
                                    <span class="px-2 py-0.5 rounded-full text-[10px]
                                        <?= $a === 'create' ? 'bg-emerald-500/20 text-emerald-200' : '' ?>
                                        <?= $a === 'update' ? 'bg-sky-500/20 text-sky-200' : '' ?>
                                        <?= $a === 'skip' ? 'bg-slate-600/30 text-slate-200' : '' ?>
                                        <?= $a === 'error' ? 'bg-red-500/20 text-red-200' : '' ?>
                                    "><?= e($label) ?></span>
                                </td>
                                <td class="py-1 pr-2 text-red-200">
                                    <?= e($p['error'] ?? '') ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endif; ?>
    </div>
</main>
</body>
</html>

