<?php

require_once __DIR__ . '/../../app/bootstrap.php';
require_login();

$user = auth_user();


if (!$user || ($user['global_role'] ?? '') !== 'project_owner') {
    http_response_code(403);
    echo "Forbidden";
    exit;
}

$pdo = db();
$errors = [];
$success = null;



$restaurants = [];
try {
    $stmt = $pdo->query("SELECT id, name, subdomain FROM restaurants ORDER BY name ASC");
    $restaurants = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    
}


$builtinThemes = qr_builtin_themes();


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $slug        = trim(strtolower($_POST['slug'] ?? ''));
    $title       = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $scope       = $_POST['scope'] ?? 'global'; 
    $baseTheme   = $_POST['base_theme'] ?? 'dark_glass';

    $restaurantId = null;
    if ($scope === 'restaurant') {
        $restaurantId = (int)($_POST['restaurant_id'] ?? 0);
        if ($restaurantId <= 0) {
            $errors[] = 'Выберите ресторан для привязки темы.';
        }
    }

    if ($slug === '' || !preg_match('~^[a-z0-9_-]{3,50}$~', $slug)) {
        $errors[] = 'Слаг должен быть от 3 до 50 символов, латиница/цифры/подчёркивание/дефис.';
    }

    if ($title === '') {
        $errors[] = 'Введите название темы.';
    }

    if (!isset($builtinThemes[$baseTheme])) {
        $errors[] = 'Некорректная базовая тема.';
    }

 
    if ($restaurantId !== null && $restaurantId > 0) {
        $stmt = $pdo->prepare("SELECT id FROM restaurants WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $restaurantId]);
        if (!$stmt->fetch()) {
            $errors[] = 'Указанный ресторан не найден.';
        }
    }


if (!$errors) {
    if ($restaurantId === null || $restaurantId <= 0) {

        $stmt = $pdo->prepare("
            SELECT id
            FROM qr_themes
            WHERE slug = :slug
              AND restaurant_id IS NULL
            LIMIT 1
        ");
        $stmt->execute(['slug' => $slug]);
    } else {

        $stmt = $pdo->prepare("
            SELECT id
            FROM qr_themes
            WHERE slug = :slug
              AND restaurant_id = :rest_id
            LIMIT 1
        ");
        $stmt->execute([
            'slug'    => $slug,
            'rest_id' => $restaurantId,
        ]);
    }

    if ($stmt->fetch()) {
        $errors[] = 'Тема с таким слагом уже существует для этого ресторана/глобально.';
    }
}



    if (!$errors) {
        $baseClasses = $builtinThemes[$baseTheme]['classes'];

        $fields = [
            'body',
            'header_chip',
            'payment_chip',
            'category_chip',
            'item_card',
            'placeholder',
            'mini_cart',
            'cart_shell',
            'cart_item',
            'price',
            'price_total',
        ];

        $classes = [];

        foreach ($fields as $f) {
            $formKey = 'css_' . $f;
            $val     = trim($_POST[$formKey] ?? '');
            if ($val === '') {

                $classes[$f] = $baseClasses[$f] ?? '';
            } else {
                $classes[$f] = $val;
            }
        }


        $sql = "
            INSERT INTO qr_themes (
                slug, title, description,
                css_body,
                css_header_chip,
                css_payment_chip,
                css_category_chip,
                css_item_card,
                css_placeholder,
                css_mini_cart,
                css_cart_shell,
                css_cart_item,
                css_price,
                css_price_total,
                is_builtin,
                restaurant_id
            ) VALUES (
                :slug, :title, :description,
                :body,
                :header_chip,
                :payment_chip,
                :category_chip,
                :item_card,
                :placeholder,
                :mini_cart,
                :cart_shell,
                :cart_item,
                :price,
                :price_total,
                0,
                :restaurant_id
            )
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':slug', $slug, PDO::PARAM_STR);
        $stmt->bindValue(':title', $title, PDO::PARAM_STR);
        $stmt->bindValue(':description', $description !== '' ? $description : null, $description !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':body',          $classes['body'],          PDO::PARAM_STR);
        $stmt->bindValue(':header_chip',   $classes['header_chip'],   PDO::PARAM_STR);
        $stmt->bindValue(':payment_chip',  $classes['payment_chip'],  PDO::PARAM_STR);
        $stmt->bindValue(':category_chip', $classes['category_chip'], PDO::PARAM_STR);
        $stmt->bindValue(':item_card',     $classes['item_card'],     PDO::PARAM_STR);
        $stmt->bindValue(':placeholder',   $classes['placeholder'],   PDO::PARAM_STR);
        $stmt->bindValue(':mini_cart',     $classes['mini_cart'],     PDO::PARAM_STR);
        $stmt->bindValue(':cart_shell',    $classes['cart_shell'],    PDO::PARAM_STR);
        $stmt->bindValue(':cart_item',     $classes['cart_item'],     PDO::PARAM_STR);
        $stmt->bindValue(':price',         $classes['price'],         PDO::PARAM_STR);
        $stmt->bindValue(':price_total',   $classes['price_total'],   PDO::PARAM_STR);

        if ($restaurantId === null || $restaurantId <= 0) {
            $stmt->bindValue(':restaurant_id', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':restaurant_id', $restaurantId, PDO::PARAM_INT);
        }

        $stmt->execute();

        $themeId = (int)$pdo->lastInsertId();

        if (function_exists('log_action')) {
            $details = "Создана QR-тема slug={$slug}, title={$title}, base={$baseTheme}, restaurant_id=" . ($restaurantId ?: 'NULL');
            log_action(auth_user()['id'] ?? null, $restaurantId ?: null, 'create_qr_theme', $details);
        }

        $success = 'Тема успешно создана. Теперь её можно выбрать в настройках ресторана.';


        $_POST = [];
    }
}


$themes = [];
$themesError = null;
try {
    $stmt = $pdo->query("
        SELECT qt.*, r.name AS restaurant_name, r.subdomain AS restaurant_subdomain
        FROM qr_themes qt
        LEFT JOIN restaurants r ON r.id = qt.restaurant_id
        ORDER BY 
            (qt.restaurant_id IS NULL) DESC,
            r.name ASC,
            qt.id ASC
    ");
    $themes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $themesError = $e->getMessage();
}

$user = auth_user();

?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>QR-темы — Панель владельца платформы</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?= brand_head_tags() ?>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-950 text-slate-50">
<div class="max-w-6xl mx-auto px-4 py-4 space-y-5">


    <header class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <div class="text-xs text-slate-500 uppercase tracking-wide mb-1">
                Панель владельца платформы
            </div>
            <h1 class="text-2xl font-bold flex items-center gap-2">
                QR-темы меню
                <span class="text-xs font-normal text-slate-500">
                    (кастомизация внешнего вида QR-меню)
                </span>
            </h1>
        </div>
        <div class="flex items-center gap-2 text-xs text-slate-400">
            <span class="px-2 py-1 rounded-full bg-slate-900/80 border border-slate-700">
                <?= e($user['email'] ?? '') ?>
            </span>
            <a href="/project-admin/index.php"
               class="px-3 py-1.5 rounded-full bg-slate-900/80 border border-slate-700 hover:border-emerald-500/70 text-slate-200">
                ← К панели
            </a>
        </div>
    </header>


    <?php if ($success): ?>
        <div class="rounded-3xl bg-emerald-500/10 border border-emerald-500/70 px-4 py-3 text-sm text-emerald-100">
            <?= e($success) ?>
        </div>
    <?php endif; ?>

    <?php if ($errors): ?>
        <div class="rounded-3xl bg-red-500/10 border border-red-500/70 px-4 py-3 text-sm text-red-100 space-y-1">
            <?php foreach ($errors as $err): ?>
                <div><?= e($err) ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>


    <section class="bg-slate-900/80 border border-slate-800 rounded-3xl p-4 space-y-3">
        <div class="flex items-center justify-between gap-3 mb-1">
            <h2 class="text-sm font-semibold text-slate-100">
                Существующие темы
            </h2>
            <div class="text-[11px] text-slate-500">
                Встроенные темы управляются кодом, кастомные — через эту страницу.
            </div>
        </div>

        <?php if ($themesError): ?>
            <div class="text-sm text-red-300">
                Ошибка при загрузке тем: <?= e($themesError) ?>
            </div>
        <?php elseif (!$themes): ?>
            <div class="text-sm text-slate-400">
                Тем пока нет. Создайте первую тему через форму ниже.
            </div>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="min-w-full text-xs">
                    <thead class="text-[11px] uppercase tracking-wide text-slate-500 border-b border-slate-800">
                    <tr>
                        <th class="text-left py-2 pr-3">Название</th>
                        <th class="text-left py-2 pr-3">Слаг</th>
                        <th class="text-left py-2 pr-3">Область</th>
                        <th class="text-left py-2 pr-3">База</th>
                        <th class="text-left py-2 pr-3">Описание</th>
                        <th class="text-right py-2 pl-3">ID</th>
                    </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800/80">
                    <?php foreach ($themes as $t): ?>
                        <tr>
                            <td class="py-2 pr-3 align-top">
                                <div class="text-slate-100 text-[13px] font-semibold">
                                    <?= e($t['title']) ?>
                                </div>
                            </td>
                            <td class="py-2 pr-3 align-top text-slate-400 font-mono">
                                <?= e($t['slug']) ?>
                            </td>
                            <td class="py-2 pr-3 align-top text-slate-300">
                                <?php if ($t['restaurant_id'] === null): ?>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full bg-slate-800 text-[11px]">
                                        Глобальная
                                    </span>
                                <?php else: ?>
                                    <div class="flex flex-col gap-0.5">
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full bg-emerald-900/60 text-[11px] text-emerald-100">
                                            Для ресторана
                                        </span>
                                        <span class="text-[11px] text-slate-400">
                                            <?= e($t['restaurant_name'] ?? ('ID ' . $t['restaurant_id'])) ?>
                                            <?php if (!empty($t['restaurant_subdomain'])): ?>
                                                <span class="text-slate-600">· <?= e($t['restaurant_subdomain']) ?></span>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="py-2 pr-3 align-top text-slate-400 text-[11px]">
                                <?php if ($t['is_builtin']): ?>
                                    встроенная
                                <?php else: ?>
                                    кастомная
                                <?php endif; ?>
                            </td>
                            <td class="py-2 pr-3 align-top text-slate-400 text-[11px] max-w-xs">
                                <?php if (!empty($t['description'])): ?>
                                    <?= e($t['description']) ?>
                                <?php else: ?>
                                    <span class="text-slate-600">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="py-2 pl-3 align-top text-right text-slate-500 text-[11px]">
                                #<?= (int)$t['id'] ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>


    <section class="bg-slate-900/80 border border-slate-800 rounded-3xl p-4 space-y-4">
        <div class="flex items-center justify-between gap-3">
            <div>
                <h2 class="text-sm font-semibold text-slate-100 mb-1">
                    Создать новую тему
                </h2>
                <p class="text-[11px] text-slate-500">
                    Можно сделать глобальную тему для всех ресторанов или персональную — под конкретный ресторан.
                </p>
            </div>
        </div>

        <form method="post" class="space-y-4 text-xs">

            <div class="grid md:grid-cols-2 gap-3">
                <div class="space-y-1">
                    <label class="block text-slate-300 mb-1">Слаг темы</label>
                    <input
                        type="text"
                        name="slug"
                        value="<?= e($_POST['slug'] ?? '') ?>"
                        placeholder="например: brand-coffee-2025"
                        class="w-full rounded-2xl bg-slate-950 border border-slate-700 px-3 py-2 text-sm text-slate-50 focus:outline-none focus:ring-1 focus:ring-emerald-500"
                    >
                    <p class="text-[11px] text-slate-500">
                        Латиница, цифры, тире и подчёркивания. Используется в restaurants.qr_theme.
                    </p>
                </div>

                <div class="space-y-1">
                    <label class="block text-slate-300 mb-1">Название темы</label>
                    <input
                        type="text"
                        name="title"
                        value="<?= e($_POST['title'] ?? '') ?>"
                        placeholder="Например: Брендовая темная / Фирменная кофейня"
                        class="w-full rounded-2xl bg-slate-950 border border-slate-700 px-3 py-2 text-sm text-slate-50 focus:outline-none focus:ring-1 focus:ring-emerald-500"
                    >
                </div>
            </div>

            <div class="space-y-1">
                <label class="block text-slate-300 mb-1">Описание (опционально)</label>
                <textarea
                    name="description"
                    rows="2"
                    class="w-full rounded-2xl bg-slate-950 border border-slate-700 px-3 py-2 text-sm text-slate-50 focus:outline-none focus:ring-1 focus:ring-emerald-500"
                    placeholder="Кратко опиши, для какого ресторана/стиля сделана тема."
                ><?= e($_POST['description'] ?? '') ?></textarea>
            </div>


            <div class="grid md:grid-cols-2 gap-3">
                <div class="space-y-2">
                    <div class="text-slate-300 mb-1">Область действия темы</div>
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input
                            type="radio"
                            name="scope"
                            value="global"
                            class="rounded bg-slate-950 border-slate-700"
                            <?= (($_POST['scope'] ?? 'global') === 'global') ? 'checked' : '' ?>
                        >
                        <span class="text-slate-200">
                            Глобальная тема
                            <span class="block text-[11px] text-slate-500">
                                Доступна всем ресторанам.
                            </span>
                        </span>
                    </label>
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input
                            type="radio"
                            name="scope"
                            value="restaurant"
                            class="rounded bg-slate-950 border-slate-700"
                            <?= (($_POST['scope'] ?? 'global') === 'restaurant') ? 'checked' : '' ?>
                        >
                        <span class="text-slate-200">
                            Только для конкретного ресторана
                            <span class="block text-[11px] text-slate-500">
                                Это фирменная тема под один ресторан.
                            </span>
                        </span>
                    </label>
                </div>

                <div class="space-y-1">
                    <label class="block text-slate-300 mb-1">
                        Ресторан (если тема привязана к ресторану)
                    </label>
                    <select
                        name="restaurant_id"
                        class="w-full rounded-2xl bg-slate-950 border border-slate-700 px-3 py-2 text-sm text-slate-50 focus:outline-none focus:ring-1 focus:ring-emerald-500"
                    >
                        <option value="0">— Не выбрано / Глобальная —</option>
                        <?php foreach ($restaurants as $r): ?>
                            <option value="<?= (int)$r['id'] ?>"
                                <?= (isset($_POST['restaurant_id']) && (int)$_POST['restaurant_id'] === (int)$r['id']) ? 'selected' : '' ?>
                            >
                                <?= e($r['name']) ?><?= $r['subdomain'] ? ' — ' . e($r['subdomain']) : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="text-[11px] text-slate-500">
                        Игнорируется, если выбрана “Глобальная тема”.
                    </p>
                </div>
            </div>


            <div class="space-y-2">
                <div class="text-slate-300 mb-1">Базовая тема (для копирования классов)</div>
                <div class="grid md:grid-cols-3 gap-3 text-[11px]">
                    <?php
                    $baseThemePost = $_POST['base_theme'] ?? 'dark_glass';
                    foreach ($builtinThemes as $key => $t):
                    ?>
                        <label class="relative block cursor-pointer group">
                            <input
                                type="radio"
                                name="base_theme"
                                value="<?= e($key) ?>"
                                class="peer sr-only"
                                <?= $baseThemePost === $key ? 'checked' : '' ?>
                            >
                            <div class="rounded-2xl border px-3 py-3 h-full
                                <?php if ($baseThemePost === $key): ?>
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
                                    <?php if ($baseThemePost === $key): ?>
                                        <span class="inline-flex items-center justify-center rounded-full bg-emerald-500 text-slate-950 text-[10px] px-2 py-0.5">
                                            База
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </label>
                    <?php endforeach; ?>
                </div>
                <p class="text-[11px] text-slate-500">
                    Все CSS-классы новой темы будут скопированы из выбранной базовой. Ниже можно точечно переопределить.
                </p>
            </div>


            <details class="rounded-2xl border border-slate-800 bg-slate-950/70 px-3 py-2">
                <summary class="cursor-pointer text-[12px] text-slate-200 flex items-center justify-between">
                    <span>Расширенные настройки CSS-классов</span>
                    <span class="text-[11px] text-slate-500 ml-2">
                        (опционально)
                    </span>
                </summary>

                <div class="mt-3 grid md:grid-cols-2 gap-3 text-[11px]">
                    <?php
                    $cssFields = [
                        'body'         => 'Тег &lt;body&gt;',
                        'header_chip'  => 'Чип “Мы принимаем заказ онлайн”',
                        'payment_chip' => 'Чипы способов оплаты',
                        'category_chip'=> 'Чипы навигации по категориям',
                        'item_card'    => 'Карточка блюда',
                        'placeholder'  => 'Плейсхолдер “Без фото”',
                        'mini_cart'    => 'Мини-корзина в шапке',
                        'cart_shell'   => 'Обёртка блока корзины',
                        'cart_item'    => 'Строка в корзине',
                        'price'        => 'Цена блюда',
                        'price_total'  => 'Итого в корзине',
                    ];
                    foreach ($cssFields as $key => $label):
                        $formKey = 'css_' . $key;
                        $val = $_POST[$formKey] ?? '';
                    ?>
                        <div class="space-y-1">
                            <label class="block text-slate-300 mb-1">
                                <?= $label ?>
                            </label>
                            <input
                                type="text"
                                name="<?= e($formKey) ?>"
                                value="<?= e($val) ?>"
                                placeholder="Оставь пустым, чтобы взять классы из базовой темы"
                                class="w-full rounded-2xl bg-slate-900 border border-slate-700 px-3 py-1.5 text-[11px] text-slate-50 focus:outline-none focus:ring-1 focus:ring-emerald-500"
                            >
                        </div>
                    <?php endforeach; ?>
                </div>
            </details>

            <div class="pt-1 flex justify-end">
                <button type="submit"
                        class="inline-flex items-center px-4 py-2.5 rounded-2xl bg-emerald-500 hover:bg-emerald-400 text-slate-950 text-sm font-semibold">
                    Создать тему
                </button>
            </div>
        </form>
    </section>
</div>
</body>
</html>
