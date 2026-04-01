<?php

require_once __DIR__ . '/../../app/bootstrap.php';

require_login();
require_current_restaurant();
require_restaurant_role((int)($currentRestaurant['id'] ?? 0), ['owner', 'admin']);

$pdo = db();
$restId = (int)$currentRestaurant['id'];

$errors  = [];
$success = null;

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

// ---------- ОБРАБОТКА POST ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfOk = isset($_POST['csrf'], $_SESSION['csrf']) && hash_equals((string)$_SESSION['csrf'], (string)$_POST['csrf']);
    if (!$csrfOk) {
        $errors[] = 'Неверный запрос. Обновите страницу и попробуйте снова.';
    } else {
    $action = $_POST['action'] ?? '';


    if ($action === 'add_category') {
        $name      = trim($_POST['name'] ?? '');
        $sortOrder = isset($_POST['sort_order']) ? (int)$_POST['sort_order'] : 0;

        if ($name === '') {
            $errors[] = 'Введите название категории.';
        }

        if (!$errors) {
      
            if ($sortOrder === 0) {
                $stmt = $pdo->prepare("
                    SELECT COALESCE(MAX(sort_order), 0) AS max_sort
                    FROM menu_categories
                    WHERE restaurant_id = :rest
                ");
                $stmt->execute(['rest' => $restId]);
                $row = $stmt->fetch();
                $max = (int)($row['max_sort'] ?? 0);
                $sortOrder = $max + 10;
            }

            $stmt = $pdo->prepare("
                INSERT INTO menu_categories (restaurant_id, name, sort_order)
                VALUES (:rest, :name, :sort_order)
            ");
            $stmt->execute([
                'rest'       => $restId,
                'name'       => $name,
                'sort_order' => $sortOrder,
            ]);

            if (function_exists('log_action')) {
                $msg = "Создана категория меню \"{$name}\" (sort_order={$sortOrder})";
                log_action(auth_user()['id'] ?? null, $restId, 'create_menu_category', $msg);
            }

            $success = 'Категория добавлена.';
        }
    }


    if ($action === 'save_sort') {
        $sorts = $_POST['sort'] ?? [];
        if (!is_array($sorts)) {
            $sorts = [];
        }

        foreach ($sorts as $id => $val) {
            $id  = (int)$id;
            $val = (int)$val;
            if ($id <= 0) continue;

            $stmt = $pdo->prepare("
                UPDATE menu_categories
                SET sort_order = :sort_order
                WHERE id = :id AND restaurant_id = :rest
            ");
            $stmt->execute([
                'sort_order' => $val,
                'id'         => $id,
                'rest'       => $restId,
            ]);
        }

        if (function_exists('log_action')) {
            log_action(auth_user()['id'] ?? null, $restId, 'update_menu_category_sort', 'Обновлена сортировка категорий меню');
        }

        $success = 'Сортировка категорий сохранена.';
    }


    if ($action === 'delete_category') {
        $catId = (int)($_POST['id'] ?? 0);

        if ($catId <= 0) {
            $errors[] = 'Некорректный идентификатор категории.';
        } else {

            $stmt = $pdo->prepare("
                SELECT * 
                FROM menu_categories
                WHERE id = :id AND restaurant_id = :rest
                LIMIT 1
            ");
            $stmt->execute([
                'id'   => $catId,
                'rest' => $restId,
            ]);
            $cat = $stmt->fetch();

            if (!$cat) {
                $errors[] = 'Категория не найдена.';
            } else {

                $stmt = $pdo->prepare("
                    SELECT COUNT(*) AS cnt
                    FROM menu_items
                    WHERE restaurant_id = :rest
                      AND category_id = :cat_id
                ");
                $stmt->execute([
                    'rest'   => $restId,
                    'cat_id' => $catId,
                ]);
                $row = $stmt->fetch();
                $cnt = (int)($row['cnt'] ?? 0);

                if ($cnt > 0) {
                    $errors[] = 'Нельзя удалить категорию, в которой есть блюда. Сначала перенесите или удалите блюда.';
                } else {

                    $stmt = $pdo->prepare("
                        DELETE FROM menu_categories
                        WHERE id = :id AND restaurant_id = :rest
                        LIMIT 1
                    ");
                    $stmt->execute([
                        'id'   => $catId,
                        'rest' => $restId,
                    ]);

                    if (function_exists('log_action')) {
                        $msg = "Удалена категория меню #{$catId} ({$cat['name']})";
                        log_action(auth_user()['id'] ?? null, $restId, 'delete_menu_category', $msg);
                    }

                    $success = 'Категория удалена.';
                }
            }
        }
    }
    }
}


$stmt = $pdo->prepare("
    SELECT *
    FROM menu_categories
    WHERE restaurant_id = :rest
    ORDER BY sort_order ASC, id ASC
");
$stmt->execute(['rest' => $restId]);
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Категории меню — <?= e($currentRestaurant['name']) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?= brand_head_tags() ?>

    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-950 text-slate-50 flex">

<!-- Сайдбар -->
<aside class="w-64 bg-slate-950/80 border-r border-slate-800 p-4 hidden md:block">
    <?= brand_restaurant_sidebar_header_html($currentRestaurant['name']) ?>
    <nav class="space-y-2 text-sm">
       <a href="/restaurant/dashboard.php" class="block px-3 py-2 rounded-xl hover:bg-slate-800/60">
            Обзор
        </a>
        <a href="/restaurant/revenue.php" class="block px-3 py-2 rounded-xl hover:bg-slate-800/60">
            Доход
        </a>
        <a href="/restaurant/menu_categories.php" class="block px-3 py-2 rounded-xl bg-slate-800/70">
            Категории меню
        </a>
        <a href="/restaurant/menu_items.php" class="block px-3 py-2 rounded-xl hover:bg-slate-800/60">
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

<!-- Основной контент -->
<main class="flex-1 p-4">
    <div class="max-w-4xl mx-auto space-y-4">
        <header class="flex flex-wrap items-center justify-between gap-3 mb-2">
            <div>
                <h2 class="text-2xl font-bold mb-1">Категории меню</h2>
                <div class="text-xs text-slate-500">
                    Группируйте блюда по удобным разделам (горячее, закуски, десерты и т.п.).
                </div>
            </div>
        </header>

        <?php if ($success): ?>
            <div class="rounded-3xl bg-emerald-500/10 border border-emerald-500/60 px-4 py-3 text-sm text-emerald-100">
                <?= e($success) ?>
            </div>
        <?php endif; ?>

        <?php if ($errors): ?>
            <div class="rounded-3xl bg-red-500/10 border border-red-500/60 px-4 py-3 text-sm text-red-100 space-y-1">
                <?php foreach ($errors as $err): ?>
                    <div><?= e($err) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Форма добавления категории -->
        <section class="bg-slate-900/80 border border-slate-800 rounded-3xl p-4 space-y-4">
            <h3 class="text-lg font-semibold mb-2">Добавить категорию</h3>
            <form method="post" class="grid md:grid-cols-3 gap-3 text-xs">
                <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                <input type="hidden" name="action" value="add_category">

                <div class="md:col-span-2 space-y-1">
                    <label class="block text-slate-300 mb-1">Название категории</label>
                    <input
                        type="text"
                        name="name"
                        required
                        placeholder="Например: Горячие блюда, Пицца, Напитки"
                        class="w-full rounded-2xl bg-slate-950 border border-slate-700 px-3 py-2 text-sm text-slate-50 focus:outline-none focus:ring-1 focus:ring-emerald-500"
                        value="<?= e($_POST['name'] ?? '') ?>"
                    >
                </div>

                <div class="space-y-1">
                    <label class="block text-slate-300 mb-1">Сортировка (опционально)</label>
                    <input
                        type="number"
                        name="sort_order"
                        placeholder="0 = в конец"
                        class="w-full rounded-2xl bg-slate-950 border border-slate-700 px-3 py-2 text-sm text-slate-50 focus:outline-none focus:ring-1 focus:ring-emerald-500"
                        value="<?= e($_POST['sort_order'] ?? '') ?>"
                    >
                    <p class="text-[11px] text-slate-500">
                        Чем меньше число, тем выше в списке. Если оставить пустым — добавится в конец.
                    </p>
                </div>

                <div class="md:col-span-3 flex justify-end pt-1">
                    <button type="submit"
                            class="px-4 py-2.5 rounded-2xl bg-emerald-500 hover:bg-emerald-400 text-slate-950 text-sm font-semibold">
                        Добавить категорию
                    </button>
                </div>
            </form>
        </section>

        <!-- Список категорий -->
        <section class="bg-slate-900/80 border border-slate-800 rounded-3xl p-4 space-y-3">
            <div class="flex items-center justify-between gap-3">
                <h3 class="text-lg font-semibold">Список категорий</h3>
                <?php if ($categories): ?>
                    <form method="post">
                        <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                        <input type="hidden" name="action" value="save_sort">
                        <button type="submit"
                                class="px-3 py-1.5 rounded-2xl bg-slate-800 hover:bg-slate-700 text-xs text-slate-100">
                            Сохранить сортировку
                        </button>
                <?php endif; ?>
            </div>

            <?php if (!$categories): ?>
                <div class="text-sm text-slate-400">
                    Категорий пока нет. Добавьте первую категорию выше.
                </div>
            <?php else: ?>
                <div class="space-y-2 text-xs">
                    <?php foreach ($categories as $cat): ?>
                        <div class="flex items-center gap-3 rounded-2xl bg-slate-950/70 border border-slate-800 px-3 py-2">
                            <div class="flex-1 min-w-0">
                                <div class="text-[13px] font-semibold text-slate-100">
                                    <?= e($cat['name']) ?>
                                </div>
                                <div class="text-[11px] text-slate-500">
                                    ID: <?= (int)$cat['id'] ?>
                                </div>
                            </div>

                            <!-- сортировка -->
                            <div class="flex items-center gap-2">
                                <div class="text-[11px] text-slate-400">
                                    sort:
                                </div>
                                <input
                                    type="number"
                                    name="sort[<?= (int)$cat['id'] ?>]"
                                    value="<?= (int)$cat['sort_order'] ?>"
                                    class="w-20 rounded-xl bg-slate-950 border border-slate-700 px-2 py-1 text-[11px] text-center focus:outline-none focus:ring-1 focus:ring-emerald-500"
                                >
                            </div>

                            <!-- удалить -->
                            <div>
                                <form method="post"
                                      onsubmit="return confirm('Удалить категорию «<?= e($cat['name']) ?>»? Если в ней есть блюда, удаление будет отклонено.');">
                                    <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                                    <input type="hidden" name="action" value="delete_category">
                                    <input type="hidden" name="id" value="<?= (int)$cat['id'] ?>">
                                    <button type="submit"
                                            class="px-3 py-1.5 rounded-2xl bg-red-500/10 border border-red-500/50 text-[11px] text-red-100 hover:bg-red-500/20">
                                        Удалить
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                </form> <!-- закрываем форму save_sort, если была открыта -->
            <?php endif; ?>
        </section>
    </div>
</main>
</body>
</html>
