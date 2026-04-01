<?php

require_once __DIR__ . '/../../app/bootstrap.php';

require_login();

if (!$currentRestaurant) {
    http_response_code(404);
    echo 'Контекст ресторана не найден';
    exit;
}

require_restaurant_role((int)$currentRestaurant['id'], ['staff', 'admin', 'owner']);

$user = auth_user();

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = (string)($_SESSION['csrf'] ?? '');

$tableId = isset($_GET['table_id']) ? (int)$_GET['table_id'] : 0;
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Заказы официанта — <?= e($currentRestaurant['name']) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: Inter, system-ui, sans-serif; }
        .mm-scroll::-webkit-scrollbar { height: 6px; }
        .mm-scroll::-webkit-scrollbar-thumb { background: rgba(100,116,139,.45); border-radius: 9999px; }
    </style>
</head>
<body class="min-h-screen bg-[#0B0F19] text-slate-50 antialiased">
<div class="min-h-screen relative overflow-hidden">
    <!-- Декоративный фон -->
    <div class="pointer-events-none absolute inset-0">
        <div class="absolute -top-28 -left-20 w-72 h-72 bg-emerald-500/10 blur-3xl rounded-full"></div>
        <div class="absolute -bottom-44 right-0 w-80 h-80 bg-sky-500/10 blur-3xl rounded-full"></div>
        <div class="absolute top-1/2 -right-28 w-52 h-52 bg-emerald-400/5 blur-2xl rounded-full"></div>
    </div>

    <div class="relative z-10 flex flex-col min-h-screen">
        <header class="border-b border-slate-800/70 bg-[#0B0F19]/70 backdrop-blur-xl">
            <div class="max-w-6xl mx-auto px-4 py-4 flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                <div class="min-w-0">
                    <div class="inline-flex items-center gap-2 rounded-full bg-emerald-500/10 border border-emerald-500/40 px-3 py-1 text-[11px] mb-2">
                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"></span>
                        Панель официанта
                    </div>
                    <div class="flex items-baseline gap-3">
                        <h1 class="text-xl md:text-2xl font-semibold leading-tight truncate">
                            <?= e($currentRestaurant['name']) ?>
                        </h1>
                        <span class="text-[11px] text-slate-400 uppercase tracking-[0.2em] shrink-0">ЗАКАЗЫ</span>
                    </div>
                    <div class="mt-1 text-[11px] text-slate-400">
                        Вошли как <span class="font-medium text-slate-100"><?= e($user['name']) ?></span>
                        <span class="text-slate-600">·</span>
                        роль: <span class="font-medium text-slate-100"><?= e($user['global_role'] ?? 'staff') ?></span>
                    </div>
                </div>

                <div class="flex items-center gap-3 text-slate-300">
                    <div class="hidden sm:flex items-center gap-2 text-[11px] text-slate-400">
                        <span class="px-2 py-0.5 rounded-full bg-slate-900/80 border border-slate-700 text-slate-100">Новый</span>
                        <span>→</span>
                        <span class="px-2 py-0.5 rounded-full bg-slate-900/80 border border-slate-700 text-slate-100">Принят</span>
                        <span>→</span>
                        <span class="px-2 py-0.5 rounded-full bg-slate-900/80 border border-slate-700 text-slate-100">Готовится</span>
                        <span>→</span>
                        <span class="px-2 py-0.5 rounded-full bg-slate-900/80 border border-slate-700 text-slate-100">Готово</span>
                    </div>
                </div>
            </div>
        </header>

        <main class="flex-1">
            <div class="max-w-6xl mx-auto px-4 py-4 pb-24 md:pb-10">
                <!-- Фильтры -->
                <div class="rounded-3xl border border-slate-800/70 bg-slate-950/40 shadow-xl shadow-black/20 px-3 py-3">
                    <div class="flex items-center justify-between gap-3 mb-2">
                        <div class="min-w-0">
                            <div class="text-sm font-semibold text-white">Фильтр заказов</div>
                            <div class="text-[11px] text-slate-400">Тапните по вкладке</div>
                        </div>
                        <div class="hidden sm:block text-[11px] text-slate-500">
                            Обновление каждые 5 секунд
                        </div>
                    </div>

                    <div class="flex gap-2 overflow-x-auto mm-scroll pb-1">
                        <button type="button" class="tab-btn inline-flex items-center justify-center min-h-[44px] px-4 rounded-full text-sm font-semibold border border-slate-800/80 bg-slate-800/50 text-white transition"
                                data-tab="all">
                            Все
                        </button>
                        <button type="button" class="tab-btn inline-flex items-center justify-center min-h-[44px] px-4 rounded-full text-sm font-semibold border border-slate-800/80 bg-[#0B0F19]/20 text-slate-300 hover:text-white hover:border-emerald-500/30 transition"
                                data-tab="new">
                            Новые
                        </button>
                        <button type="button" class="tab-btn inline-flex items-center justify-center min-h-[44px] px-4 rounded-full text-sm font-semibold border border-slate-800/80 bg-[#0B0F19]/20 text-slate-300 hover:text-white hover:border-emerald-500/30 transition"
                                data-tab="accepted">
                            Приняты
                        </button>
                        <button type="button" class="tab-btn inline-flex items-center justify-center min-h-[44px] px-4 rounded-full text-sm font-semibold border border-slate-800/80 bg-[#0B0F19]/20 text-slate-300 hover:text-white hover:border-emerald-500/30 transition"
                                data-tab="cooking">
                            Готовится
                        </button>
                        <button type="button" class="tab-btn inline-flex items-center justify-center min-h-[44px] px-4 rounded-full text-sm font-semibold border border-slate-800/80 bg-[#0B0F19]/20 text-slate-300 hover:text-white hover:border-emerald-500/30 transition"
                                data-tab="ready">
                            Готово
                        </button>
                        <button type="button" class="tab-btn inline-flex items-center justify-center min-h-[44px] px-4 rounded-full text-sm font-semibold border border-slate-800/80 bg-[#0B0F19]/20 text-slate-300 hover:text-white hover:border-emerald-500/30 transition"
                                data-tab="closed">
                            Закрытые
                        </button>
                    </div>
                </div>

                <!-- Сетка заказов -->
                <div class="mt-4">
                    <div id="orders-container" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                        <div class="rounded-3xl border border-slate-800/80 bg-slate-950/60 p-4 flex items-center gap-3 shadow-lg shadow-black/30">
                            <span class="w-4 h-4 border-2 border-slate-500 rounded-full border-t-transparent animate-spin"></span>
                            <span class="text-sm text-slate-300">Загрузка заказов...</span>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

<audio id="new-order-sound" src="/assets/new-order.mp3" preload="auto"></audio>

<script>
    (function () {
        var csrf = <?= json_encode($csrfToken, JSON_UNESCAPED_UNICODE) ?>;
        var tableId = <?= (int)$tableId ?>;
        var ordersContainer = document.getElementById('orders-container');
        var sound = document.getElementById('new-order-sound');

        var lastSeenOrderIds = new Set();
        var currentTab = 'all';
        var lastData = null;

        function humanOrderStatus(status) {
            var map = {
                new: 'Новый',
                accepted: 'Принят',
                cooking: 'Готовится',
                ready: 'Готово',
                delivered: 'Отдано',
                canceled: 'Отменён'
            };
            return map[status] || status;
        }

        function humanPaymentStatus(status) {
            var map = {
                pending: 'Ожидание',
                paid: 'Оплачен',
                unpaid: 'Не оплачен',
                canceled: 'Отмена'
            };
            return map[status] || status;
        }

        function paymentTypeText(type) {
            var map = {
                card_later: 'Картой',
                cash: 'Наличными',
                pay_later: 'Позже'
            };
            return map[type] || type;
        }

        function statusBadgeClass(status) {
            var map = {
                new: 'bg-amber-500/10 text-amber-200 border-amber-400/70',
                accepted: 'bg-sky-500/10 text-sky-200 border-sky-400/70',
                cooking: 'bg-indigo-500/10 text-indigo-200 border-indigo-400/70',
                ready: 'bg-emerald-500/10 text-emerald-200 border-emerald-400/80',
                delivered: 'bg-slate-700/40 text-slate-100 border-slate-400/70',
                canceled: 'bg-red-500/10 text-red-200 border-red-400/70'
            };
            return map[status] || 'bg-slate-800 text-slate-100 border-slate-700';
        }

        function paymentBadgeClass(status) {
            var map = {
                pending: 'bg-amber-500/10 text-amber-200 border-amber-400/70',
                unpaid: 'bg-red-500/10 text-red-200 border-red-400/70',
                paid: 'bg-emerald-500/10 text-emerald-200 border-emerald-400/80',
                canceled: 'bg-slate-800/80 text-slate-200 border-slate-600/80'
            };
            return map[status] || 'bg-slate-800 text-slate-100 border-slate-700';
        }

        function formatMoney(amount) {
            var n = Number(amount || 0);
            return Math.round(n).toLocaleString('ru-RU');
        }

        function filterByTab(order, tab) {
            if (tab === 'all') return true;
            if (tab === 'closed') return order.order_status === 'delivered' || order.order_status === 'canceled';
            return order.order_status === tab;
        }

        function itemsHtml(items) {
            if (!Array.isArray(items) || items.length === 0) {
                return '<div class="px-4 py-3 text-[11px] text-slate-500">Нет позиций</div>';
            }

            return items.map(function (it) {
                var qty = Number(it.quantity || 0);
                var price = Number(it.price || 0);
                var lineTotal = qty * price;
                var name = it.menu_name || 'Позиция';
                return (
                    '<div class="px-4 py-2 flex items-start justify-between gap-3 border-t border-slate-800/70 first:border-t-0">' +
                        '<div class="min-w-0">' +
                            '<div class="text-sm text-slate-100 font-medium truncate">' + escapeHtml(name) + '</div>' +
                            '<div class="text-[11px] text-slate-500 mt-0.5">' + qty + ' × ' + Math.round(price).toLocaleString('ru-RU') + ' ₽</div>' +
                        '</div>' +
                        '<div class="text-sm font-semibold text-emerald-300 whitespace-nowrap">' + Math.round(lineTotal).toLocaleString('ru-RU') + ' ₽</div>' +
                    '</div>'
                );
            }).join('');
        }

        function escapeHtml(str) {
            return String(str)
                .replaceAll('&', '&amp;')
                .replaceAll('<', '&lt;')
                .replaceAll('>', '&gt;')
                .replaceAll('"', '&quot;')
                .replaceAll("'", '&#039;');
        }

        function renderOrderCard(order) {
            var status = order.order_status;
            var paymentStatus = order.payment_status;
            var paymentType = order.payment_type;
            var since = Number(order.since_minutes || 0);
            var total = formatMoney(order.total_price);

            var countdownSecondsRemaining = order.countdown_seconds_remaining;
            var countdownExpired = !!order.countdown_expired;
            var countdownWarning = !!order.countdown_warning;
            var countdownMmss = order.countdown_mmss || '';
            var readyItemsCount = Number(order.ready_items_count || 0);
            var totalItemsCount = Number(order.total_items_count || 0);
            var partialReady = !!order.partial_ready;
            var hasWaiterCall = !!order.has_waiter_call;
            var deliveredUnpaid = (status === 'delivered' && paymentStatus === 'unpaid');
            var countdownBadge = '';
            if (countdownSecondsRemaining !== null && typeof countdownSecondsRemaining !== 'undefined') {
                var timerText = countdownExpired ? 'Время ожидания оплаты истекло' : ('Ожидание оплаты: ' + countdownMmss);
                var timerClass = countdownExpired
                    ? 'bg-red-500/10 text-red-200 border-red-400/70 animate-pulse'
                    : (countdownWarning ? 'bg-amber-500/10 text-amber-200 border-amber-400/70' : 'bg-emerald-500/10 text-emerald-200 border-emerald-400/70');
                countdownBadge = '<span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full border text-[11px] ' + timerClass + '">' + escapeHtml(timerText) + '</span>';
            }
            var progressBadge = '';
            if (totalItemsCount > 0) {
                var progressClass = partialReady
                    ? 'bg-indigo-500/10 text-indigo-200 border-indigo-400/70'
                    : (readyItemsCount === totalItemsCount
                        ? 'bg-emerald-500/10 text-emerald-200 border-emerald-400/70'
                        : 'bg-slate-900/80 text-slate-200 border-slate-700');
                progressBadge = '<span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full border text-[11px] ' + progressClass + '">Готовность: ' + readyItemsCount + '/' + totalItemsCount + '</span>';
            }
            var waiterBadge = hasWaiterCall
                ? '<span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full border text-[11px] bg-orange-500/10 text-orange-200 border-orange-400/70 animate-pulse">Вызов официанта</span>'
                : '';
            var deliveredUnpaidBadge = deliveredUnpaid
                ? '<span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full border text-[11px] bg-red-500/10 text-red-200 border-red-400/70 animate-pulse">Отдан, но не оплачен</span>'
                : '';

            var action = '';
            if (status === 'new') {
                action = '<button type="button" data-action="status" data-order-id="' + order.id + '" data-status="accepted" class="w-full min-h-[48px] px-4 py-3 rounded-xl bg-emerald-500 text-[#0B0F19] font-bold text-sm hover:bg-emerald-400 transition touch-manipulation">Принять</button>';
            } else if (status === 'accepted') {
                action = '<button type="button" data-action="status" data-order-id="' + order.id + '" data-status="cooking" class="w-full min-h-[48px] px-4 py-3 rounded-xl bg-sky-500 text-[#0B0F19] font-bold text-sm hover:bg-sky-400 transition touch-manipulation">Начать готовку</button>';
            } else if (status === 'cooking') {
                action = '<button type="button" data-action="status" data-order-id="' + order.id + '" data-status="ready" class="w-full min-h-[48px] px-4 py-3 rounded-xl bg-indigo-500 text-[#0B0F19] font-bold text-sm hover:bg-indigo-400 transition touch-manipulation">Готово</button>';
            } else if (status === 'ready') {
                action = '<button type="button" data-action="status" data-order-id="' + order.id + '" data-status="delivered" class="w-full min-h-[48px] px-4 py-3 rounded-xl bg-emerald-400 text-[#0B0F19] font-bold text-sm hover:bg-emerald-300 transition touch-manipulation">Закрыть</button>';
            }

            return (
                '<article class="rounded-3xl border border-slate-800/80 bg-slate-950/55 shadow-xl shadow-black/20 overflow-hidden hover:border-emerald-500/20 transition-all">' +
                    '<div class="p-4 md:p-5">' +
                        '<div class="flex items-start justify-between gap-3">' +
                            '<div class="min-w-0">' +
                                '<div class="text-[11px] text-slate-500">Заказ <span class="font-mono text-slate-100">#' + order.id + '</span></div>' +
                                '<div class="text-sm font-semibold text-white mt-1">Стол: <span class="text-slate-100">' + escapeHtml(order.table_name || '—') + '</span></div>' +
                                '<div class="mt-2 text-[11px] text-slate-500">Создан: <span class="text-slate-200">' + escapeHtml(order.created_at_short || '') + '</span></div>' +
                                '<div class="text-[11px] text-slate-500">Минут назад: <span class="text-emerald-300 font-semibold">' + since + '</span></div>' +
                            '</div>' +
                            '<div class="text-right shrink-0">' +
                                '<div class="text-lg md:text-xl font-extrabold text-emerald-400 tabular-nums">' + total + ' ₽</div>' +
                                '<div class="text-[11px] text-slate-500 mt-1">Итого</div>' +
                            '</div>' +
                        '</div>' +

                        '<div class="mt-3 flex flex-wrap items-center gap-2">' +
                            '<span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full border text-[11px] ' + statusBadgeClass(status) + '">' +
                                humanOrderStatus(status) +
                            '</span>' +
                            '<span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full border text-[11px] ' + paymentBadgeClass(paymentStatus) + '">' +
                                humanPaymentStatus(paymentStatus) +
                            '</span>' +
                            '<span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-slate-950/90 border border-slate-700 text-[11px] text-slate-200">' +
                                escapeHtml(paymentTypeText(paymentType)) +
                            '</span>' +
                            waiterBadge +
                            deliveredUnpaidBadge +
                            progressBadge +
                            (countdownBadge ? countdownBadge : '') +
                        '</div>' +

                        '<div class="mt-3 rounded-2xl border border-slate-800/80 bg-[#0f172a]/30 overflow-hidden">' +
                            '<div class="px-4 py-2 flex items-center justify-between bg-slate-950/40 border-b border-slate-800/70">' +
                                '<div class="text-[11px] text-slate-400 font-medium">Позиции</div>' +
                                '<div class="text-[11px] text-slate-500">' + ((order.items && order.items.length) ? order.items.length : 0) + ' шт.</div>' +
                            '</div>' +
                            '<div>' + itemsHtml(order.items) + '</div>' +
                        '</div>' +

                        '<div class="mt-4 flex items-center gap-3">' +
                            '<div class="flex-1"></div>' +
                            '<div class="w-full sm:w-[180px]' + (action ? '' : '') + '">' +
                                (action || '<div class="min-h-[48px] w-full rounded-xl border border-slate-700 bg-slate-900/40 flex items-center justify-center text-[11px] text-slate-400">Без действий</div>') +
                            '</div>' +
                        '</div>' +
                    '</div>' +
                '</article>'
            );
        }

        function renderEmptyState() {
            ordersContainer.innerHTML =
                '<div class="sm:col-span-2 xl:col-span-3 rounded-3xl border border-slate-800/80 bg-slate-950/40 p-6 shadow-xl shadow-black/20">' +
                    '<div class="flex items-start gap-4">' +
                        '<div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-300 text-2xl">🧾</div>' +
                        '<div class="min-w-0">' +
                            '<h3 class="text-lg font-bold text-white tracking-tight">Пока нет заказов</h3>' +
                            '<p class="text-sm text-slate-400 mt-1">Как только гость сделает заказ через QR-меню — он появится здесь.</p>' +
                        '</div>' +
                    '</div>' +
                '</div>';
        }

        function renderOrders(data) {
            if (!data || !Array.isArray(data.orders)) {
                ordersContainer.innerHTML =
                    '<div class="sm:col-span-2 xl:col-span-3 rounded-3xl border border-red-500/50 bg-red-500/10 p-5 text-sm text-red-100 shadow-lg shadow-red-950/30">Ошибка загрузки заказов</div>';
                return;
            }

            var orders = data.orders;
            if (!orders.length) {
                renderEmptyState();
                lastSeenOrderIds = new Set();
                return;
            }

            var existingIds = new Set();
            orders.forEach(function (o) { existingIds.add(o.id); });

            // Появились новые заказы?
            var newOrderAppeared = false;
            orders.forEach(function (o) {
                if (!lastSeenOrderIds.has(o.id)) newOrderAppeared = true;
            });

            var filtered = orders.filter(function (o) { return filterByTab(o, currentTab); });

            if (!filtered.length) {
                ordersContainer.innerHTML =
                    '<div class="sm:col-span-2 xl:col-span-3 rounded-3xl border border-slate-800/80 bg-slate-950/40 p-6 shadow-xl shadow-black/20 text-center">' +
                        '<div class="inline-flex h-14 w-14 items-center justify-center rounded-2xl bg-slate-900/50 border border-slate-800 text-slate-400 mb-4">📭</div>' +
                        '<h3 class="text-lg font-bold text-white tracking-tight">Нет заказов в этом фильтре</h3>' +
                        '<p class="text-sm text-slate-400 mt-1">Попробуйте другую вкладку.</p>' +
                    '</div>';
                lastSeenOrderIds = existingIds;
                return;
            }

            ordersContainer.innerHTML = filtered.map(function (o) { return renderOrderCard(o); }).join('');

            // Звук при появлении нового заказа
            if (newOrderAppeared && lastSeenOrderIds.size > 0) {
                try {
                    if (sound) {
                        sound.currentTime = 0;
                        sound.play().catch(function () {});
                    }
                } catch (e) {}
            }

            lastSeenOrderIds = existingIds;
        }

        function fetchOrders() {
            var url = '/staff/orders_api.php';
            if (tableId && tableId > 0) {
                url += '?table_id=' + encodeURIComponent(tableId);
            }
            fetch(url, { headers: {'X-Requested-With': 'XMLHttpRequest'} })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data && data.success) {
                        lastData = data;
                        renderOrders(data);
                    } else {
                        ordersContainer.innerHTML =
                            '<div class="sm:col-span-2 xl:col-span-3 rounded-3xl border border-red-500/50 bg-red-500/10 p-5 text-sm text-red-100 shadow-lg shadow-red-950/30">Ошибка загрузки заказов</div>';
                    }
                })
                .catch(function () {
                    ordersContainer.innerHTML =
                        '<div class="sm:col-span-2 xl:col-span-3 rounded-3xl border border-red-500/50 bg-red-500/10 p-5 text-sm text-red-100 shadow-lg shadow-red-950/30">Ошибка соединения с сервером</div>';
                });
        }

        // Обновление статуса по кнопкам
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('button[data-action="status"]');
            if (!btn) return;

            var orderId = btn.dataset.orderId;
            var newStatus = btn.dataset.status;
            if (!orderId || !newStatus) return;

            btn.disabled = true;
            btn.classList.add('opacity-70', 'cursor-not-allowed');

            var formData = new FormData();
            formData.append('order_id', orderId);
            formData.append('order_status', newStatus);
            if (csrf) formData.append('csrf', csrf);

            fetch('/staff/order_update_status.php', {
                method: 'POST',
                body: formData,
                headers: {'X-Requested-With': 'XMLHttpRequest'}
            })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data && data.success) {
                        fetchOrders();
                    } else {
                        alert((data && data.message) ? data.message : 'Ошибка обновления заказа');
                    }
                })
                .catch(function () {
                    alert('Ошибка сети при обновлении заказа');
                })
                .finally(function () {
                    btn.disabled = false;
                    btn.classList.remove('opacity-70', 'cursor-not-allowed');
                });
        });

        // Переключение вкладок
        var tabButtons = Array.prototype.slice.call(document.querySelectorAll('.tab-btn'));
        tabButtons.forEach(function (b) {
            b.addEventListener('click', function () {
                var tab = b.dataset.tab;
                if (!tab) return;
                currentTab = tab;
                tabButtons.forEach(function (x) {
                    x.classList.remove('bg-slate-800/50', 'text-white', 'border-emerald-500/30');
                    x.classList.add('bg-[#0B0F19]/20', 'text-slate-300');
                });

                b.classList.remove('bg-[#0B0F19]/20', 'text-slate-300');
                b.classList.add('bg-slate-800/50', 'text-white', 'border-emerald-500/30');

                if (lastData) renderOrders(lastData);
            });
        });

        fetchOrders();
        setInterval(fetchOrders, 5000);
    })();
</script>
</body>
</html>

