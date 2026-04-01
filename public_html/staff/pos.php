<?php


require_once __DIR__ . '/../../app/bootstrap.php';

if (!function_exists('e')) {
    function e($v): string {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

require_login();

if (!$currentRestaurant) {
    http_response_code(404);
    echo "Restaurant context required";
    exit;
}

require_restaurant_role((int)$currentRestaurant['id'], ['staff','admin','owner']);

$user = auth_user();
$pdo  = db();

$tables = [];
$stmt = $pdo->prepare("SELECT id, name FROM tables WHERE restaurant_id = :rid ORDER BY id ASC");
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

$stmt = $pdo->prepare("
    SELECT id, category_id, name, description, price, image_path, image_url
    FROM menu_items
    WHERE restaurant_id = :rid
      AND available = 1
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
                <a href="/staff/orders.php"
                   class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-slate-900/80 border border-slate-700 text-slate-200 hover:bg-slate-800">
                    ← К списку заказов
                </a>
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
                            <option value="<?= (int)$t['id'] ?>">
                                <?= e($t['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
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
                </div>

                <div class="md:w-1/3 flex md:justify-end">
                    <div class="text-right text-[11px] text-slate-500">
                        Новый заказ создаётся со статусом <span class="text-emerald-300">«Новый»</span> и оплатой
                        <span class="text-slate-300">«Не оплачен»</span>.<br>
                        После фактической оплаты в зале отметьте его как «Оплачен» в панели заказов.
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
                    <button type="button" id="pos-clear-cart"
                            class="text-[11px] text-slate-400 hover:text-rose-300">
                        Очистить
                    </button>
                </div>

                <div id="pos-cart-items" class="space-y-2 max-h-[350px] overflow-y-auto pr-1 text-xs">
                    <div class="text-slate-500 text-xs">
                        Добавьте блюда из меню слева.
                    </div>
                </div>

                <div class="mt-3 border-t border-slate-800 pt-3 space-y-2">
                    <div class="flex items-center justify-between text-sm">
                        <span class="text-slate-400">Итого:</span>
                        <span id="pos-total" class="text-lg font-semibold text-emerald-400">0 ₽</span>
                    </div>
                    <div id="pos-error" class="hidden text-[11px] text-rose-300"></div>
                    <button type="button" id="pos-submit"
                            class="w-full mt-1 inline-flex items-center justify-center px-4 py-2.5 rounded-2xl bg-emerald-500 text-slate-950 text-sm font-semibold shadow-lg shadow-emerald-500/40 disabled:opacity-40 disabled:cursor-not-allowed">
                        Оформить заказ
                    </button>
                    <div class="text-[11px] text-slate-500">
                        Новый заказ появится в списке заказов для кухни и бариста.
                    </div>
                </div>
            </div>
        </section>
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

    const payContainer = document.getElementById('pos-payment-type');
    let paymentType = 'cash';

    let activeCategoryId = categories[0] ? categories[0].id : null;
    const cart = {}; // itemId -> {id, name, price, qty}

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
            const img = item.image
                ? `<div class="w-12 h-12 rounded-2xl overflow-hidden bg-slate-900/80 border border-slate-700">
                        <img src="${escapeHtml(item.image)}" alt="" loading="lazy" class="w-full h-full object-cover">
                   </div>`
                : `<div class="w-12 h-12 rounded-2xl bg-slate-900/80 border border-slate-800 flex items-center justify-center text-[10px] text-slate-500">
                        нет фото
                   </div>`;

            return `
                <div class="rounded-2xl bg-slate-900/80 border border-slate-800 p-3 flex flex-col gap-2 hover:border-emerald-500/60 hover:bg-slate-900 transition group">
                    <div class="flex gap-3">
                        ${img}
                        <div class="flex-1 min-w-0">
                            <div class="text-sm font-medium text-slate-50 truncate">
                                ${item.name}
                            </div>
                            ${desc}
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
                    Добавьте блюда из меню слева.
                </div>
            `;
            totalEl.textContent = '0 ₽';
            submitBtn.disabled = true;
            return;
        }

        let html = '';
        let total = 0;

        keys.forEach(id => {
            const item = cart[id];
            const sum = item.qty * item.price;
            total += sum;

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
        submitBtn.disabled = false;
    }

    function setPaymentType(val) {
        paymentType = val;
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
        if (!btn) return;
        const id = parseInt(btn.dataset.itemId, 10);
        const name = btn.dataset.itemName || '';
        const price = parseFloat(btn.dataset.itemPrice || '0');

        if (!id || !price) return;

        if (!cart[id]) {
            cart[id] = {id, name, price, qty: 0};
        }
        cart[id].qty += 1;
        renderCart();
    });

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
        const tableId = parseInt(tableSelect.value || '0', 10);
        if (!tableId) {
            showError('Выберите стол.');
            return;
        }
        const items = Object.values(cart).map(it => ({
            item_id: it.id,
            quantity: it.qty
        }));
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
                submitBtn.disabled = false;
                showError('');
                showToast('Заказ #' + data.order_id + ' создан');

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
