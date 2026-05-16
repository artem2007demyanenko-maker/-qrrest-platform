<?php


require_once __DIR__ . '/../../app/bootstrap.php';
if (!headers_sent()) {
    header('Content-Type: text/html; charset=UTF-8');
}

if (!function_exists('e')) {
    function e($v): string {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

require_waiter_access();

$user = auth_user();
$pdo  = db();
$restId = (int)($currentRestaurant['id'] ?? 0);
if (file_exists(__DIR__ . '/../../app/runtime_schema_bootstrap.php')) {
    require_once __DIR__ . '/../../app/runtime_schema_bootstrap.php';
}
if (function_exists('runtime_schema_ensure_menu_items_food_meta')) {
    runtime_schema_ensure_menu_items_food_meta($pdo);
}
if (function_exists('runtime_schema_ensure_menu_items_availability')) {
    runtime_schema_ensure_menu_items_availability($pdo);
}
$hasMenuTemporaryUnavailableCol = function_exists('db_column_exists') && db_column_exists('menu_items', 'is_temporarily_unavailable');
$menuTempUnavailableCond = $hasMenuTemporaryUnavailableCol
    ? " AND COALESCE(is_temporarily_unavailable, 0) = 0 "
    : '';
$preselectedTableId = isset($_GET['table_id']) ? (int)$_GET['table_id'] : 0;

$tables = [];
$stmt = $pdo->prepare("
    SELECT t.id, t.name FROM tables AS t
    WHERE t.restaurant_id = :rid
    " . qr_public_sql_exclude_delivery($pdo, 't') . "
    ORDER BY t.id ASC
");
$stmt->execute([':rid' => (int)$currentRestaurant['id']]);
$tables = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("
    SELECT id, name
    FROM menu_categories
    WHERE restaurant_id = :rid
    ORDER BY sort_order ASC, id ASC
");
$stmt->execute([':rid' => (int)$currentRestaurant['id']]);
$cats = $stmt->fetchAll(PDO::FETCH_ASSOC);

$menuHasImageUrlCol = function_exists('db_column_exists') && db_column_exists('menu_items', 'image_url');
$menuImageUrlSql = $menuHasImageUrlCol ? 'image_url' : 'NULL AS image_url';

$menuHasIngredientsCol = function_exists('db_column_exists') && db_column_exists('menu_items', 'ingredients');
$menuHasWeightCol = function_exists('db_column_exists') && db_column_exists('menu_items', 'weight');
$menuHasWeightGramsCol = function_exists('db_column_exists') && db_column_exists('menu_items', 'weight_grams');
$menuHasAllergensCol = function_exists('db_column_exists') && db_column_exists('menu_items', 'allergens');
$menuHasTagsCol = function_exists('db_column_exists') && db_column_exists('menu_items', 'tags');
$menuIngredientsSql = $menuHasIngredientsCol ? 'ingredients' : 'NULL AS ingredients';
$menuWeightSql = $menuHasWeightCol
    ? 'weight'
    : ($menuHasWeightGramsCol ? 'weight_grams AS weight' : 'NULL AS weight');
$menuAllergensSql = $menuHasAllergensCol ? 'allergens' : 'NULL AS allergens';
$menuTagsSql = $menuHasTagsCol ? 'tags' : 'NULL AS tags';

$stmt = $pdo->prepare("
    SELECT id, category_id, name, description, price, available, image_path, {$menuImageUrlSql}, {$menuIngredientsSql}, {$menuWeightSql}, {$menuAllergensSql}, {$menuTagsSql}
    FROM menu_items
    WHERE restaurant_id = :rid
      AND available = 1
      {$menuTempUnavailableCond}
    ORDER BY category_id ASC, id ASC
");
$stmt->execute([':rid' => (int)$currentRestaurant['id']]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);


$categories = [];
foreach ($cats as $c) {
    $categories[(int)$c['id']] = [
        'id'   => (int)$c['id'],
        'name' => $c['name'],
        'items'=> [],
    ];
}
foreach ($items as $it) {
    $cid = (int)$it['category_id'];
    if (!isset($categories[$cid])) {
        continue;
    }
    $categories[$cid]['items'][] = [
        'id'          => (int)$it['id'],
        'name'        => $it['name'],
        'description' => $it['description'],
        'price'       => (float)$it['price'],
        'category'    => (string)($categories[$cid]['name'] ?? ''),
        'ingredients' => (string)($it['ingredients'] ?? ''),
        'weight'      => (string)($it['weight'] ?? ''),
        'allergens'   => (string)($it['allergens'] ?? ''),
        'tags'        => (string)($it['tags'] ?? ''),
        'available'   => (int)($it['available'] ?? 1) === 1,
        'image'       => menu_item_image_url($it),
    ];
}

$categoriesList = array_values($categories);

$posData = [
    'restaurant' => [
        'id'   => (int)$currentRestaurant['id'],
        'name' => $currentRestaurant['name'],
    ],
    'tables'     => array_map(function($t) {
        return [
            'id'   => (int)$t['id'],
            'name' => $t['name'],
        ];
    }, $tables),
    'categories' => $categoriesList,
];
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>POS — <?= e($currentRestaurant['name']) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-950 text-slate-50">
<div class="min-h-screen relative overflow-hidden">
    <!-- фоновые пятна -->
    <div class="pointer-events-none absolute inset-0">
        <div class="absolute -top-40 -left-32 w-72 h-72 bg-emerald-500/20 blur-3xl rounded-full"></div>
        <div class="absolute bottom-[-9rem] right-[-3rem] w-96 h-96 bg-sky-500/20 blur-3xl rounded-full"></div>
        <div class="absolute top-1/3 right-12 w-64 h-64 bg-fuchsia-500/20 blur-3xl rounded-full"></div>
    </div>

    <div class="relative z-10 max-w-6xl mx-auto px-3 sm:px-4 py-4 sm:py-6">
        <?php
        $operationalNavMode = 'staff';
        $operationalNavActive = 'staff_pos';
        require __DIR__ . '/../restaurant/_restaurant_cabinet_context.php';
        require __DIR__ . '/../restaurant/_restaurant_operational_nav.php';
        ?>
        <!-- Хедер -->
        <header class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-slate-900/80 border border-slate-700 text-[11px] text-slate-300 mb-2">
                    POS-терминал • режим сотрудников
                </div>
                <h1 class="text-2xl sm:text-3xl font-semibold text-slate-50 mb-1">
                    <?= e($currentRestaurant['name']) ?>
                </h1>
                <p class="text-xs text-slate-400">
                    Официант: <span class="text-slate-100"><?= e($user['name']) ?></span>
                </p>
            </div>
            <div class="flex flex-col items-start sm:items-end gap-1 text-xs">
                <div class="flex items-center gap-2">
                    <a href="/staff/orders.php"
                       class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-slate-900/80 border border-slate-700 text-slate-200 hover:bg-slate-800">
                        ← К списку заказов
                    </a>
                    <a href="/staff/loyalty.php"
                       class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-amber-500/10 border border-amber-500/40 text-amber-200 hover:bg-amber-500/20">
                        Лояльность
                    </a>
                </div>
                <div class="text-[11px] text-slate-500">
                    Быстрое оформление заказов из зала
                </div>
            </div>
        </header>

        <!-- Верхняя панель: стол + тип оплаты -->
        <section class="mb-4 rounded-3xl bg-slate-950/90 border border-slate-800/80 px-4 py-3 shadow-lg shadow-slate-950/80">
            <div class="flex flex-col md:flex-row md:items-end gap-3">
                <div class="md:w-1/3">
                    <label class="block text-[11px] text-slate-300 mb-1">Стол</label>
                    <select id="pos-table"
                            class="w-full rounded-2xl bg-slate-900 border border-slate-700 px-3 py-2 text-sm text-slate-50 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                        <option value="">Выберите стол...</option>
                        <?php foreach ($tables as $t): ?>
                            <option value="<?= (int)$t['id'] ?>" <?= ((int)$t['id'] === $preselectedTableId) ? 'selected' : '' ?>>
                                <?= e($t['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div id="pos-no-tables" class="hidden mt-2 text-[11px] text-amber-300">
                        Нет доступных столов для оформления заказа в POS.
                    </div>
                    <div id="pos-selected-table" class="mt-2 text-[11px] text-slate-400">
                        Стол не выбран
                    </div>
                    <div class="mt-2 flex flex-wrap gap-2">
                        <a id="pos-link-table-orders"
                           href="/staff/orders.php"
                           class="inline-flex items-center gap-1 px-2.5 py-1 rounded-xl border border-slate-700 bg-slate-900/70 text-[11px] text-slate-500 pointer-events-none"
                           aria-disabled="true">
                            Заказы стола
                        </a>
                        <a id="pos-link-floorplan"
                           href="/staff/floorplan.php"
                           class="inline-flex items-center gap-1 px-2.5 py-1 rounded-xl border border-slate-700 bg-slate-900/70 text-[11px] text-slate-300 hover:bg-slate-800/70">
                            Карта столов
                        </a>
                    </div>
                </div>

                <div class="md:flex-1">
                    <label class="block text-[11px] text-slate-300 mb-1">Тип оплаты</label>
                    <div id="pos-payment-type" class="inline-flex flex-wrap gap-2 text-xs">
                        <button type="button"
                                data-value="cash"
                                class="pos-pay-btn px-3 py-1.5 rounded-2xl bg-emerald-500 text-slate-950 font-semibold border border-emerald-400 shadow shadow-emerald-500/40">
                            Наличные
                        </button>
                        <button type="button"
                                data-value="card_later"
                                class="pos-pay-btn px-3 py-1.5 rounded-2xl bg-slate-900 border border-slate-700 text-slate-200">
                            Картой
                        </button>
                    </div>
                    <div class="mt-1 text-[11px] text-slate-500">
                        Онлайн-оплаты в системе нет — оплата фиксируется вручную как «Оплачено» в списке заказов.
                    </div>
                    <div id="pos-selected-payment" class="mt-2 text-[11px] text-slate-400">
                        Способ оплаты: Наличные
                    </div>
                </div>

                <div class="md:w-1/3 flex md:justify-end">
                    <div class="text-right text-[11px] text-slate-500">
                        POS Lite: новый заказ создаётся как <span class="text-emerald-300">ручной (manual)</span> со статусом <span class="text-emerald-300">«Новый»</span> и оплатой
                        <span class="text-slate-300">«Не оплачен»</span>.<br>
                        После фактической оплаты в зале отметьте его как «Оплачен» в панели заказов.
                        <div id="pos-operator-summary"
                             class="mt-2 inline-flex items-center justify-end rounded-xl border border-emerald-500/30 bg-emerald-500/10 px-2 py-1 text-[11px] text-emerald-100">
                            Ручной заказ · Наличные · Стол не выбран
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Основная сетка: меню + корзина -->
        <section class="grid grid-cols-1 lg:grid-cols-[minmax(0,2.2fr)_minmax(0,1.4fr)] gap-4 items-start">
            <!-- МЕНЮ -->
            <div class="rounded-3xl bg-slate-950/90 border border-slate-800/80 p-3 sm:p-4 shadow-xl shadow-slate-950/80">
                <!-- Категории -->
                <div id="pos-cats" class="flex gap-2 overflow-x-auto pb-2 mb-2 text-[11px] hide-scrollbar">
                    <!-- Заполняется JS -->
                </div>

                <!-- Список блюд -->
                <div id="pos-items" class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-3">
                    <!-- Заполняется JS -->
                </div>

                <div id="pos-empty-menu" class="hidden text-xs text-slate-500 mt-4">
                    В этом ресторане пока нет доступных блюд.
                </div>
            </div>

            <!-- КОРЗИНА -->
            <div class="rounded-3xl bg-slate-950/95 border border-slate-800/80 p-3 sm:p-4 shadow-2xl shadow-slate-950/80">
                <div class="flex items-center justify-between mb-2">
                    <h2 class="text-sm font-semibold text-slate-50">
                        Корзина
                    </h2>
                    <div class="flex items-center gap-2">
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full border border-slate-700 bg-slate-900 text-[11px] text-slate-300">
                            Позиций: <span id="pos-cart-count" class="ml-1 text-slate-100">0</span>
                        </span>
                        <button type="button" id="pos-clear-cart"
                                class="text-[11px] text-slate-400 hover:text-rose-300">
                            Очистить
                        </button>
                    </div>
                </div>

                <div id="pos-cart-items" class="space-y-2 max-h-[350px] overflow-y-auto pr-1 text-xs">
                    <div class="text-slate-500 text-xs">
                        Корзина пуста. Добавьте блюда из меню.
                    </div>
                </div>

                <div class="mt-3 border-t border-slate-800 pt-3 space-y-2">
                    <div class="flex items-center justify-between text-xs">
                        <span class="text-slate-500">Режим:</span>
                        <span class="text-slate-200">Ручной заказ (Manual)</span>
                    </div>
                    <div class="flex items-center justify-between text-sm">
                        <span class="text-slate-400">Итого:</span>
                        <span id="pos-total" class="text-lg font-semibold text-emerald-400">0 ₽</span>
                    </div>
                    <div id="pos-error" class="hidden text-[11px] text-rose-300"></div>
                    <div>
                        <label for="pos-comment" class="block text-[11px] text-slate-400 mb-1">Комментарий к заказу (опционально)</label>
                        <textarea id="pos-comment" rows="2" maxlength="500"
                                  placeholder="Например: без лука, гость у окна, подать вместе..."
                                  class="w-full rounded-2xl bg-slate-900 border border-slate-700 px-3 py-2 text-xs text-slate-100 placeholder:text-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/40"></textarea>
                    </div>
                    <button type="button" id="pos-submit"
                            class="w-full mt-1 inline-flex items-center justify-center px-4 py-2.5 rounded-2xl bg-emerald-500 text-slate-950 text-sm font-semibold shadow-lg shadow-emerald-500/40 disabled:opacity-40 disabled:cursor-not-allowed">
                        Оформить заказ
                    </button>
                    <div id="pos-submit-hint" class="text-[11px] text-slate-500">
                        Новый заказ появится в списке заказов для кухни и бариста.
                    </div>
                    <div id="pos-success" class="hidden rounded-2xl border border-emerald-500/30 bg-emerald-500/10 px-3 py-2">
                        <div id="pos-success-text" class="text-[12px] text-emerald-100"></div>
                        <div class="mt-2 flex flex-wrap gap-2">
                            <a id="pos-success-orders-link"
                               href="/staff/orders.php"
                               class="inline-flex items-center px-2.5 py-1 rounded-xl border border-emerald-500/30 bg-slate-900/40 text-[11px] text-emerald-100 hover:bg-slate-900/70">
                                К заказам
                            </a>
                            <a id="pos-success-floorplan-link"
                               href="/staff/floorplan.php"
                               class="inline-flex items-center px-2.5 py-1 rounded-xl border border-slate-700 bg-slate-900/70 text-[11px] text-slate-200 hover:bg-slate-800/70">
                                К карте столов
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </div>
</div>

<div id="dish-modal" class="hidden fixed inset-0 z-50">
    <div class="absolute inset-0 bg-slate-950/80 backdrop-blur-sm" data-close-dish-modal="1"></div>
    <div class="relative min-h-screen flex items-center justify-center p-3 sm:p-5">
        <div class="w-full max-w-lg rounded-3xl border border-slate-700 bg-slate-900/95 shadow-2xl shadow-slate-950/90">
            <div class="px-4 py-3 border-b border-slate-800 flex items-center justify-between gap-3">
                <h3 id="dish-modal-title" class="text-base font-semibold text-slate-50">Блюдо</h3>
                <button type="button" class="text-slate-400 hover:text-slate-100" data-close-dish-modal="1">✕</button>
            </div>
            <div class="p-4 space-y-4">
                <div id="dish-modal-image-wrap" class="hidden rounded-2xl overflow-hidden border border-slate-800 bg-slate-950/70">
                    <img id="dish-modal-image" src="" alt="" class="w-full h-48 object-cover">
                </div>
                <div id="dish-modal-description" class="text-sm text-slate-300 hidden"></div>
                <div class="grid grid-cols-2 gap-2 text-xs">
                    <div class="rounded-xl border border-slate-800 bg-slate-950/70 p-2">
                        <div class="text-slate-500">Цена</div>
                        <div id="dish-modal-price" class="text-slate-100 font-semibold mt-0.5">—</div>
                    </div>
                    <div class="rounded-xl border border-slate-800 bg-slate-950/70 p-2">
                        <div class="text-slate-500">Категория</div>
                        <div id="dish-modal-category" class="text-slate-100 font-semibold mt-0.5">—</div>
                    </div>
                    <div class="rounded-xl border border-slate-800 bg-slate-950/70 p-2">
                        <div class="text-slate-500">Граммовка</div>
                        <div id="dish-modal-weight" class="text-slate-100 font-semibold mt-0.5">—</div>
                    </div>
                    <div class="rounded-xl border border-slate-800 bg-slate-950/70 p-2">
                        <div class="text-slate-500">Доступность</div>
                        <div id="dish-modal-available" class="text-slate-100 font-semibold mt-0.5">—</div>
                    </div>
                </div>
                <div id="dish-modal-ingredients-wrap" class="hidden">
                    <div class="text-xs text-slate-500 mb-1">Состав</div>
                    <div id="dish-modal-ingredients" class="text-sm text-slate-200 rounded-xl border border-slate-800 bg-slate-950/70 p-2"></div>
                </div>
                <div id="dish-modal-allergens-wrap" class="hidden">
                    <div class="text-xs text-slate-500 mb-1">Аллергены / теги</div>
                    <div id="dish-modal-allergens" class="text-sm text-slate-200 rounded-xl border border-slate-800 bg-slate-950/70 p-2"></div>
                </div>
                <div class="flex justify-end">
                    <button type="button" id="dish-modal-add" class="inline-flex items-center gap-2 px-4 py-2 rounded-2xl bg-emerald-500 text-slate-950 font-semibold hover:bg-emerald-400">
                        Добавить в корзину
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    // Данные из PHP
    window.POS_DATA = <?= json_encode($posData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>

<script>
(function () {
    const data = window.POS_DATA || {};
    const categories = data.categories || [];
    const tables = data.tables || [];

    const catsEl = document.getElementById('pos-cats');
    const itemsEl = document.getElementById('pos-items');
    const emptyMenuEl = document.getElementById('pos-empty-menu');

    const cartItemsEl = document.getElementById('pos-cart-items');
    const totalEl = document.getElementById('pos-total');
    const errorEl = document.getElementById('pos-error');
    const submitBtn = document.getElementById('pos-submit');
    const clearBtn = document.getElementById('pos-clear-cart');
    const tableSelect = document.getElementById('pos-table');
    const noTablesEl = document.getElementById('pos-no-tables');
    const selectedTableEl = document.getElementById('pos-selected-table');
    const selectedPaymentEl = document.getElementById('pos-selected-payment');
    const operatorSummaryEl = document.getElementById('pos-operator-summary');
    const commentEl = document.getElementById('pos-comment');
    const submitHintEl = document.getElementById('pos-submit-hint');
    const tableOrdersLinkEl = document.getElementById('pos-link-table-orders');
    const floorplanLinkEl = document.getElementById('pos-link-floorplan');
    const cartCountEl = document.getElementById('pos-cart-count');
    const successBoxEl = document.getElementById('pos-success');
    const successTextEl = document.getElementById('pos-success-text');
    const successOrdersLinkEl = document.getElementById('pos-success-orders-link');
    const successFloorplanLinkEl = document.getElementById('pos-success-floorplan-link');
    const dishModalEl = document.getElementById('dish-modal');
    const dishModalTitleEl = document.getElementById('dish-modal-title');
    const dishModalImageWrapEl = document.getElementById('dish-modal-image-wrap');
    const dishModalImageEl = document.getElementById('dish-modal-image');
    const dishModalDescriptionEl = document.getElementById('dish-modal-description');
    const dishModalPriceEl = document.getElementById('dish-modal-price');
    const dishModalCategoryEl = document.getElementById('dish-modal-category');
    const dishModalWeightEl = document.getElementById('dish-modal-weight');
    const dishModalAvailableEl = document.getElementById('dish-modal-available');
    const dishModalIngredientsWrapEl = document.getElementById('dish-modal-ingredients-wrap');
    const dishModalIngredientsEl = document.getElementById('dish-modal-ingredients');
    const dishModalAllergensWrapEl = document.getElementById('dish-modal-allergens-wrap');
    const dishModalAllergensEl = document.getElementById('dish-modal-allergens');
    const dishModalAddBtn = document.getElementById('dish-modal-add');

    const payContainer = document.getElementById('pos-payment-type');
    let paymentType = 'cash';
    const paymentLabelMap = { cash: 'Наличные', card_later: 'Картой позже' };

    let activeCategoryId = categories[0] ? categories[0].id : null;
    const cart = {}; // itemId -> {id, name, price, qty}
    const hasTables = Array.isArray(tables) && tables.length > 0;
    let dishModalCurrent = null;

    function formatPrice(v) {
        const n = Math.round(v);
        return n.toLocaleString('ru-RU') + ' ₽';
    }

    function escapeHtml(s) {
        if (s == null) return '';
        const div = document.createElement('div');
        div.textContent = s;
        return div.innerHTML;
    }

    function addToCart(itemId, itemName, itemPrice) {
        const id = parseInt(itemId, 10);
        const price = parseFloat(itemPrice || '0');
        if (!id || !price) return;
        hideSuccess();
        if (!cart[id]) {
            cart[id] = {id, name: itemName || '', price, qty: 0};
        }
        cart[id].qty += 1;
        renderCart();
    }

    function openDishModal(item) {
        if (!dishModalEl || !item) return;
        dishModalCurrent = item;
        dishModalTitleEl.textContent = item.name || 'Блюдо';

        if (item.image) {
            dishModalImageEl.src = item.image;
            dishModalImageWrapEl.classList.remove('hidden');
        } else {
            dishModalImageEl.removeAttribute('src');
            dishModalImageWrapEl.classList.add('hidden');
        }

        if (item.description) {
            dishModalDescriptionEl.textContent = item.description;
            dishModalDescriptionEl.classList.remove('hidden');
        } else {
            dishModalDescriptionEl.textContent = '';
            dishModalDescriptionEl.classList.add('hidden');
        }

        dishModalPriceEl.textContent = formatPrice(Number(item.price || 0));
        dishModalCategoryEl.textContent = item.category || '—';
        dishModalWeightEl.textContent = item.weight ? String(item.weight) : '—';
        dishModalAvailableEl.textContent = item.available ? 'Доступно' : 'Недоступно';

        if (item.ingredients) {
            dishModalIngredientsEl.textContent = item.ingredients;
            dishModalIngredientsWrapEl.classList.remove('hidden');
        } else {
            dishModalIngredientsEl.textContent = '';
            dishModalIngredientsWrapEl.classList.add('hidden');
        }

        const allergensText = [item.allergens, item.tags].filter(Boolean).join(' · ');
        if (allergensText) {
            dishModalAllergensEl.textContent = allergensText;
            dishModalAllergensWrapEl.classList.remove('hidden');
        } else {
            dishModalAllergensEl.textContent = '';
            dishModalAllergensWrapEl.classList.add('hidden');
        }

        dishModalEl.classList.remove('hidden');
    }

    function closeDishModal() {
        if (!dishModalEl) return;
        dishModalEl.classList.add('hidden');
        dishModalCurrent = null;
    }

    function selectedTableName() {
        if (!tableSelect || !tableSelect.value) return '';
        const option = tableSelect.options[tableSelect.selectedIndex];
        return option ? String(option.text || '') : '';
    }

    function updateTableActions(tableId) {
        if (!tableOrdersLinkEl) return;
        if (tableId > 0) {
            tableOrdersLinkEl.href = '/staff/orders.php?table_id=' + encodeURIComponent(tableId);
            tableOrdersLinkEl.className = 'inline-flex items-center gap-1 px-2.5 py-1 rounded-xl border border-emerald-500/40 bg-emerald-500/10 text-[11px] text-emerald-200 hover:bg-emerald-500/20';
            tableOrdersLinkEl.setAttribute('aria-disabled', 'false');
        } else {
            tableOrdersLinkEl.href = '/staff/orders.php';
            tableOrdersLinkEl.className = 'inline-flex items-center gap-1 px-2.5 py-1 rounded-xl border border-slate-700 bg-slate-900/70 text-[11px] text-slate-500 pointer-events-none';
            tableOrdersLinkEl.setAttribute('aria-disabled', 'true');
        }
    }

    function updateOperatorSummary() {
        const tableName = selectedTableName() || 'Стол не выбран';
        const paymentText = paymentLabelMap[paymentType] || paymentType;
        if (selectedTableEl) {
            selectedTableEl.textContent = tableSelect && tableSelect.value
                ? ('Выбран стол: ' + tableName)
                : 'Стол не выбран';
        }
        if (selectedPaymentEl) {
            selectedPaymentEl.textContent = 'Способ оплаты: ' + paymentText;
        }
        if (operatorSummaryEl) {
            operatorSummaryEl.textContent = 'Ручной заказ · ' + paymentText + ' · ' + tableName;
        }
        const tableId = tableSelect ? parseInt(tableSelect.value || '0', 10) : 0;
        updateTableActions(tableId);
    }

    function hideSuccess() {
        if (!successBoxEl) return;
        successBoxEl.classList.add('hidden');
        if (successTextEl) successTextEl.textContent = '';
    }

    function showSuccess(orderId, tableName) {
        if (!successBoxEl) return;
        const safeOrderId = parseInt(orderId || '0', 10);
        const safeTableName = tableName || 'без стола';
        if (successTextEl) {
            successTextEl.textContent = 'Заказ #' + safeOrderId + ' создан для стола «' + safeTableName + '».';
        }
        if (successOrdersLinkEl) {
            const tableId = tableSelect ? parseInt(tableSelect.value || '0', 10) : 0;
            successOrdersLinkEl.href = tableId > 0
                ? ('/staff/orders.php?table_id=' + encodeURIComponent(tableId))
                : '/staff/orders.php';
        }
        if (successFloorplanLinkEl && floorplanLinkEl) {
            successFloorplanLinkEl.href = floorplanLinkEl.href || '/staff/floorplan.php';
        }
        successBoxEl.classList.remove('hidden');
    }

    // Рендер категорий
    function renderCategories() {
        if (!categories.length) {
            catsEl.innerHTML = '';
            itemsEl.innerHTML = '';
            emptyMenuEl.classList.remove('hidden');
            return;
        }
        emptyMenuEl.classList.add('hidden');

        catsEl.innerHTML = categories.map(cat => `
            <button type="button"
                    data-cat-id="${cat.id}"
                    class="cat-btn px-3 py-1.5 rounded-2xl border text-[11px] ${
                        cat.id === activeCategoryId
                            ? 'bg-emerald-500 text-slate-950 border-emerald-400 font-semibold'
                            : 'bg-slate-900 border-slate-700 text-slate-200'
                    }">
                ${cat.name}
            </button>
        `).join('');
    }

    function getActiveItems() {
        const cat = categories.find(c => c.id === activeCategoryId);
        if (!cat) return [];
        return Array.isArray(cat.items) ? cat.items : [];
    }

    // Рендер блюд
    function renderItems() {
        const items = getActiveItems();
        if (!items.length) {
            itemsEl.innerHTML = `
                <div class="col-span-full text-xs text-slate-500">
                    В этой категории пока нет блюд.
                </div>
            `;
            return;
        }

        itemsEl.innerHTML = items.map(item => {
            const desc = item.description ? `<div class="mt-0.5 text-[11px] text-slate-500 line-clamp-2">${item.description}</div>` : '';
            const allergenShort = (item.allergens || '').trim();
            const tagsShort = (item.tags || '').trim();
            const weightShort = (item.weight || '').trim();
            const compactMeta = [allergenShort, tagsShort, weightShort ? ('Вес: ' + weightShort) : ''].filter(Boolean).slice(0, 2);
            const compactMetaHtml = compactMeta.length
                ? `<div class="mt-1 flex flex-wrap gap-1">${compactMeta.map(function (m) {
                    return '<span class="inline-flex items-center px-2 py-0.5 rounded-full border border-slate-700 bg-slate-950/80 text-[10px] text-slate-300">' + escapeHtml(m) + '</span>';
                }).join('')}</div>`
                : '';
            const img = item.image
                ? `<div class="w-12 h-12 rounded-2xl overflow-hidden bg-slate-900/80 border border-slate-700">
                        <img src="${escapeHtml(item.image)}" alt="" loading="lazy" class="w-full h-full object-cover">
                   </div>`
                : `<div class="w-12 h-12 rounded-2xl bg-slate-900/80 border border-slate-800 flex items-center justify-center text-[10px] text-slate-500">
                        нет фото
                   </div>`;

            return `
                <div class="dish-card rounded-2xl bg-slate-900/80 border border-slate-800 p-3 flex flex-col gap-2 hover:border-emerald-500/60 hover:bg-slate-900 transition group cursor-pointer"
                     data-item-id="${item.id}">
                    <div class="flex gap-3">
                        ${img}
                        <div class="flex-1 min-w-0">
                            <div class="text-sm font-medium text-slate-50 truncate">
                                ${item.name}
                            </div>
                            ${desc}
                            ${compactMetaHtml}
                        </div>
                    </div>
                    <div class="flex items-center justify-between mt-1">
                        <div class="text-sm font-semibold text-emerald-400">
                            ${formatPrice(item.price)}
                        </div>
                        <button type="button"
                                class="add-btn inline-flex items-center gap-1 px-3 py-1.5 rounded-2xl bg-emerald-500 text-slate-950 text-[11px] font-semibold shadow shadow-emerald-500/40 hover:bg-emerald-400"
                                data-item-id="${item.id}"
                                data-item-name="${item.name.replace(/"/g, '&quot;')}"
                                data-item-price="${item.price}">
                            <span>Добавить</span>
                            <span class="text-xs">＋</span>
                        </button>
                    </div>
                </div>
            `;
        }).join('');
    }

    function renderCart() {
        const keys = Object.keys(cart);
        if (!keys.length) {
            cartItemsEl.innerHTML = `
                <div class="text-slate-500 text-xs">
                    Корзина пуста. Добавьте блюда из меню.
                </div>
            `;
            totalEl.textContent = '0 ₽';
            if (cartCountEl) cartCountEl.textContent = '0';
            submitBtn.disabled = true;
            return;
        }

        let html = '';
        let total = 0;
        let totalQty = 0;

        keys.forEach(id => {
            const item = cart[id];
            const sum = item.qty * item.price;
            total += sum;
            totalQty += item.qty;

            html += `
                <div class="rounded-2xl bg-slate-900/80 border border-slate-800 px-3 py-2 flex items-center justify-between gap-2">
                    <div class="min-w-0">
                        <div class="text-xs font-medium text-slate-50 truncate">
                            ${item.name}
                        </div>
                        <div class="text-[11px] text-slate-500">
                            ${item.qty} × ${formatPrice(item.price)}
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <div class="inline-flex items-center rounded-full bg-slate-950 border border-slate-700 overflow-hidden">
                            <button type="button"
                                    class="cart-dec px-2 py-1 text-[11px] text-slate-300 hover:bg-slate-800"
                                    data-id="${item.id}">
                                −
                            </button>
                            <div class="px-2 text-[11px] text-slate-100">
                                ${item.qty}
                            </div>
                            <button type="button"
                                    class="cart-inc px-2 py-1 text-[11px] text-slate-300 hover:bg-slate-800"
                                    data-id="${item.id}">
                                +
                            </button>
                        </div>
                        <div class="text-sm font-semibold text-emerald-300 min-w-[70px] text-right">
                            ${formatPrice(sum)}
                        </div>
                        <button type="button"
                                class="cart-remove text-[11px] text-slate-500 hover:text-rose-300"
                                data-id="${item.id}">
                            ✕
                        </button>
                    </div>
                </div>
            `;
        });

        cartItemsEl.innerHTML = html;
        totalEl.textContent = formatPrice(total);
        if (cartCountEl) cartCountEl.textContent = String(totalQty);
        submitBtn.disabled = false;
    }

    function setPaymentType(val) {
        paymentType = val;
        updateOperatorSummary();
        document.querySelectorAll('.pos-pay-btn').forEach(btn => {
            if (btn.dataset.value === val) {
                btn.className = 'pos-pay-btn px-3 py-1.5 rounded-2xl bg-emerald-500 text-slate-950 font-semibold border border-emerald-400 shadow shadow-emerald-500/40';
            } else {
                btn.className = 'pos-pay-btn px-3 py-1.5 rounded-2xl bg-slate-900 border border-slate-700 text-slate-200';
            }
        });
    }

    // Инициализация
    renderCategories();
    renderItems();
    renderCart();
    setPaymentType('cash');
    updateOperatorSummary();
    if (!hasTables) {
        tableSelect.disabled = true;
        submitBtn.disabled = true;
        if (noTablesEl) noTablesEl.classList.remove('hidden');
        if (submitHintEl) submitHintEl.textContent = 'Добавьте столы в админке, чтобы оформлять заказы через POS.';
    }
    tableSelect?.addEventListener('change', function () {
        hideSuccess();
        updateOperatorSummary();
    });

    // Смена категории
    catsEl.addEventListener('click', function (e) {
        const btn = e.target.closest('.cat-btn');
        if (!btn) return;
        const cid = parseInt(btn.getAttribute('data-cat-id'), 10);
        if (!cid || cid === activeCategoryId) return;
        activeCategoryId = cid;
        renderCategories();
        renderItems();
    });

    // Добавление блюда
    itemsEl.addEventListener('click', function (e) {
        const btn = e.target.closest('.add-btn');
        if (btn) {
            addToCart(btn.dataset.itemId, btn.dataset.itemName || '', btn.dataset.itemPrice || '0');
            return;
        }

        const card = e.target.closest('.dish-card');
        if (!card) return;
        const cardItemId = parseInt(card.dataset.itemId || '0', 10);
        if (!cardItemId) return;
        try {
            const item = getActiveItems().find(it => Number(it.id) === cardItemId);
            if (!item) return;
            openDishModal(item);
        } catch (_err) {}
    });

    if (dishModalEl) {
        dishModalEl.addEventListener('click', function (e) {
            if (e.target.closest('[data-close-dish-modal="1"]')) {
                closeDishModal();
            }
        });
    }
    if (dishModalAddBtn) {
        dishModalAddBtn.addEventListener('click', function () {
            if (!dishModalCurrent) return;
            addToCart(dishModalCurrent.id, dishModalCurrent.name || '', dishModalCurrent.price || '0');
            closeDishModal();
        });
    }

    // Управление корзиной
    cartItemsEl.addEventListener('click', function (e) {
        const dec = e.target.closest('.cart-dec');
        const inc = e.target.closest('.cart-inc');
        const rem = e.target.closest('.cart-remove');

        if (dec) {
            const id = parseInt(dec.dataset.id, 10);
            if (!cart[id]) return;
            cart[id].qty -= 1;
            if (cart[id].qty <= 0) {
                delete cart[id];
            }
            renderCart();
        } else if (inc) {
            const id = parseInt(inc.dataset.id, 10);
            if (!cart[id]) return;
            cart[id].qty += 1;
            renderCart();
        } else if (rem) {
            const id = parseInt(rem.dataset.id, 10);
            if (!cart[id]) return;
            delete cart[id];
            renderCart();
        }
    });

    clearBtn.addEventListener('click', function () {
        hideSuccess();
        Object.keys(cart).forEach(k => delete cart[k]);
        renderCart();
    });

    payContainer.addEventListener('click', function (e) {
        const btn = e.target.closest('.pos-pay-btn');
        if (!btn) return;
        const val = btn.dataset.value;
        setPaymentType(val);
    });

    function showError(msg) {
        errorEl.textContent = msg || '';
        if (msg) {
            errorEl.classList.remove('hidden');
            hideSuccess();
        } else {
            errorEl.classList.add('hidden');
        }
    }

    function showToast(message) {
        const div = document.createElement('div');
        div.className = 'fixed z-50 bottom-4 inset-x-0 flex justify-center pointer-events-none';
        div.innerHTML = `
            <div class="pointer-events-auto inline-flex items-center gap-2 px-4 py-2 rounded-2xl bg-emerald-500 text-slate-950 text-sm font-medium shadow-lg shadow-emerald-500/40">
                ${message}
            </div>
        `;
        document.body.appendChild(div);
        setTimeout(() => {
            div.classList.add('opacity-0', 'translate-y-2', 'transition');
            setTimeout(() => div.remove(), 200);
        }, 2200);
    }

    // Отправка заказа
        submitBtn.addEventListener('click', function () {
        showError('');
        if (!hasTables) {
            showError('Нет доступных столов для POS.');
            return;
        }
        const tableId = parseInt(tableSelect.value || '0', 10);
        if (!tableId) {
            showError('Выберите стол.');
            return;
        }
        const items = Object.values(cart).map(it => ({
            item_id: it.id,
            quantity: it.qty
        }));
        const orderComment = commentEl ? String(commentEl.value || '').trim() : '';
        if (!items.length) {
            showError('Добавьте хотя бы одно блюдо.');
            return;
        }

        submitBtn.disabled = true;

        fetch('/staff/pos_create_order.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json;charset=utf-8',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({
                table_id: tableId,
                payment_type: paymentType,
                comment: orderComment,
                items: items
            })
        })
            .then(r => r.json())
            .then(data => {
                if (!data || !data.success) {
                    showError(data && data.message ? data.message : 'Ошибка при создании заказа');
                    submitBtn.disabled = false;
                    return;
                }

                // очистка корзины
                Object.keys(cart).forEach(k => delete cart[k]);
                renderCart();
                if (commentEl) commentEl.value = '';
                submitBtn.disabled = false;
                showError('');
                showToast('Заказ #' + data.order_id + ' создан');
                showSuccess(data.order_id, selectedTableName());

            })
            .catch(() => {
                showError('Ошибка сети при создании заказа');
                submitBtn.disabled = false;
            });
    });
})();
</script>
</body>
</html>
