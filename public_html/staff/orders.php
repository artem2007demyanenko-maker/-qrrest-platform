<?php

require_once __DIR__ . '/../../app/bootstrap.php';

$waiterRole = function_exists('require_waiter_access')
    ? require_waiter_access()
    : require_staff_role(['owner', 'admin', 'waiter', 'staff']);
global $currentRestaurant;
$restaurantId = (int)($currentRestaurant['id'] ?? 0);
if ($restaurantId > 0) {
    $_SESSION['current_restaurant_id'] = $restaurantId;
    $_SESSION['restaurant_id'] = $restaurantId;
}

$user = auth_user();

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = (string)($_SESSION['csrf'] ?? '');

$tableId = isset($_GET['table_id']) ? (int)$_GET['table_id'] : 0;
$waiterRole = function_exists('normalize_restaurant_role')
    ? normalize_restaurant_role($waiterRole)
    : strtolower(trim((string)$waiterRole));
$canOpenPos = in_array($waiterRole, ['owner', 'admin', 'waiter', 'staff'], true);
$canUseLoyalty = in_array($waiterRole, ['owner', 'admin', 'waiter', 'staff'], true);
$canOpenCourier = in_array($waiterRole, ['owner', 'admin', 'waiter', 'staff', 'courier'], true);
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
                    <div class="mt-1 text-[11px] text-slate-400 flex flex-wrap items-center gap-2">
                        Вошли как <span class="font-medium text-slate-100"><?= e($user['name']) ?></span>
                        <span class="text-slate-600">•</span>
                        Автообновление: каждые 5 сек.
                    </div>
                </div>

                <div class="flex items-center gap-2 text-slate-300">
                    <a href="/staff/pos.php" class="inline-flex items-center justify-center min-h-[42px] px-4 rounded-xl border border-emerald-500/40 bg-emerald-500/10 text-emerald-200 text-sm font-semibold hover:bg-emerald-500/20">
                        POS
                    </a>
                    <?php if ($canOpenCourier): ?>
                        <a href="/staff/courier.php" class="inline-flex items-center justify-center min-h-[42px] px-4 rounded-xl border border-violet-500/40 bg-violet-500/10 text-violet-200 text-sm font-semibold hover:bg-violet-500/20">
                            Курьер
                        </a>
                    <?php endif; ?>
                    <a href="/staff/floorplan.php" class="inline-flex items-center justify-center min-h-[42px] px-4 rounded-xl border border-slate-700 bg-slate-900/70 text-slate-200 text-sm font-semibold hover:bg-slate-800/80">
                        Зал
                    </a>
                </div>
            </div>
        </header>

        <main class="flex-1">
            <div class="max-w-6xl mx-auto px-4 py-4 pb-24 md:pb-10">
                <!-- Оперативная сводка -->
                <div id="waiter-summary" class="grid grid-cols-2 xl:grid-cols-5 gap-2 mb-3">
                    <div class="rounded-2xl border border-slate-800/80 bg-slate-950/45 px-3 py-2">
                        <div class="text-[11px] text-slate-500">Активные</div>
                        <div id="sum-active" class="text-lg font-bold text-white">0</div>
                    </div>
                    <div class="rounded-2xl border border-amber-500/30 bg-amber-500/10 px-3 py-2">
                        <div class="text-[11px] text-amber-200/80">Новые</div>
                        <div id="sum-new" class="text-lg font-bold text-amber-200">0</div>
                    </div>
                    <div class="rounded-2xl border border-sky-500/30 bg-sky-500/10 px-3 py-2">
                        <div class="text-[11px] text-sky-200/80">В работе</div>
                        <div id="sum-work" class="text-lg font-bold text-sky-200">0</div>
                    </div>
                    <div class="rounded-2xl border border-rose-500/30 bg-rose-500/10 px-3 py-2">
                        <div class="text-[11px] text-rose-200/80">Ждут оплаты</div>
                        <div id="sum-waitpay" class="text-lg font-bold text-rose-200">0</div>
                    </div>
                    <div class="rounded-2xl border border-slate-700/80 bg-slate-900/40 px-3 py-2">
                        <div class="text-[11px] text-slate-400">Закрытые</div>
                        <div id="sum-closed" class="text-lg font-bold text-slate-200">0</div>
                    </div>
                </div>

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
                            Все <span class="tab-count ml-1 text-xs text-slate-400" data-tab-count="all"></span>
                        </button>
                        <button type="button" class="tab-btn inline-flex items-center justify-center min-h-[44px] px-4 rounded-full text-sm font-semibold border border-slate-800/80 bg-[#0B0F19]/20 text-slate-300 hover:text-white hover:border-emerald-500/30 transition"
                                data-tab="new">
                            Новые <span class="tab-count ml-1 text-xs text-slate-500" data-tab-count="new"></span>
                        </button>
                        <button type="button" class="tab-btn inline-flex items-center justify-center min-h-[44px] px-4 rounded-full text-sm font-semibold border border-slate-800/80 bg-[#0B0F19]/20 text-slate-300 hover:text-white hover:border-emerald-500/30 transition"
                                data-tab="work">
                            В работе <span class="tab-count ml-1 text-xs text-slate-500" data-tab-count="work"></span>
                        </button>
                        <button type="button" class="tab-btn inline-flex items-center justify-center min-h-[44px] px-4 rounded-full text-sm font-semibold border border-slate-800/80 bg-[#0B0F19]/20 text-slate-300 hover:text-white hover:border-emerald-500/30 transition"
                                data-tab="wait_pay">
                            Ждут оплаты <span class="tab-count ml-1 text-xs text-slate-500" data-tab-count="wait_pay"></span>
                        </button>
                        <button type="button" class="tab-btn inline-flex items-center justify-center min-h-[44px] px-4 rounded-full text-sm font-semibold border border-slate-800/80 bg-[#0B0F19]/20 text-slate-300 hover:text-white hover:border-emerald-500/30 transition"
                                data-tab="closed">
                            Закрытые <span class="tab-count ml-1 text-xs text-slate-500" data-tab-count="closed"></span>
                        </button>
                    </div>
                    <div class="mt-2 flex gap-2 overflow-x-auto mm-scroll pb-1">
                        <button type="button" class="type-tab-btn inline-flex items-center justify-center min-h-[38px] px-3 rounded-full text-xs font-semibold border border-slate-800/80 bg-cyan-500/20 text-cyan-100 transition"
                                data-type-tab="all">Все типы</button>
                        <button type="button" class="type-tab-btn inline-flex items-center justify-center min-h-[38px] px-3 rounded-full text-xs font-semibold border border-slate-800/80 bg-[#0B0F19]/20 text-slate-300 transition"
                                data-type-tab="hall">Зал</button>
                        <button type="button" class="type-tab-btn inline-flex items-center justify-center min-h-[38px] px-3 rounded-full text-xs font-semibold border border-slate-800/80 bg-[#0B0F19]/20 text-slate-300 transition"
                                data-type-tab="delivery">Доставка</button>
                        <button type="button" class="type-tab-btn inline-flex items-center justify-center min-h-[38px] px-3 rounded-full text-xs font-semibold border border-slate-800/80 bg-[#0B0F19]/20 text-slate-300 transition"
                                data-type-tab="pickup">Самовывоз</button>
                        <button type="button" class="type-tab-btn inline-flex items-center justify-center min-h-[38px] px-3 rounded-full text-xs font-semibold border border-slate-800/80 bg-[#0B0F19]/20 text-slate-300 transition"
                                data-type-tab="preorder">Предзаказ</button>
                        <button type="button" class="type-tab-btn inline-flex items-center justify-center min-h-[38px] px-3 rounded-full text-xs font-semibold border border-slate-800/80 bg-[#0B0F19]/20 text-slate-300 transition"
                                data-type-tab="manual">Ручные</button>
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

<audio id="new-order-sound" preload="none" aria-hidden="true"></audio>

<script>
    (function () {
        var csrf = <?= json_encode($csrfToken, JSON_UNESCAPED_UNICODE) ?>;
        var tableId = <?= (int)$tableId ?>;
        var canOpenPos = <?= json_encode($canOpenPos, JSON_UNESCAPED_UNICODE) ?>;
        var canUseLoyalty = <?= json_encode($canUseLoyalty, JSON_UNESCAPED_UNICODE) ?>;
        var canOpenCourier = <?= json_encode($canOpenCourier, JSON_UNESCAPED_UNICODE) ?>;
        var ordersContainer = document.getElementById('orders-container');
        var sound = document.getElementById('new-order-sound');
        var summaryEls = {
            active: document.getElementById('sum-active'),
            fresh: document.getElementById('sum-new'),
            work: document.getElementById('sum-work'),
            waitPay: document.getElementById('sum-waitpay'),
            closed: document.getElementById('sum-closed')
        };

        var lastSeenOrderIds = new Set();
        var currentTab = 'all';
        var currentTypeTab = 'all';
        var lastData = null;
        var pollTimer = null;
        var pollingStopped = false;

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

        function humanOrderType(type) {
            var map = {
                hall: 'Зал',
                delivery: 'Доставка',
                pickup: 'Самовывоз',
                preorder: 'Предзаказ',
                manual: 'Ручной'
            };
            return map[type] || 'Зал';
        }
        function humanReceiveType(type) {
            var map = {
                pickup: 'Самовывоз',
                delivery: 'Доставка'
            };
            return map[type] || '';
        }

        function disabledAction(text, hint) {
            var title = hint ? (' title="' + escapeHtml(hint) + '"') : '';
            return '<div class="w-full min-h-[44px] px-4 py-3 rounded-xl border border-slate-700 bg-slate-900/35 text-slate-500 font-medium text-sm cursor-not-allowed opacity-80 flex items-center justify-center text-center"' + title + '>' + escapeHtml(text) + '</div>';
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

        function isClosedOrder(order) {
            var st = String(order.order_status || '');
            return st === 'delivered' || st === 'canceled';
        }

        function isWorkOrder(order) {
            var st = String(order.order_status || '');
            return st === 'accepted' || st === 'cooking' || st === 'ready';
        }

        function isWaitingPayment(order) {
            var pst = String(order.payment_status || '');
            if (!(pst === 'unpaid' || pst === 'pending')) return false;
            return String(order.order_status || '') !== 'canceled';
        }

        function filterByTab(order, tab) {
            if (tab === 'all') return true;
            if (tab === 'new') return String(order.order_status || '') === 'new';
            if (tab === 'work') return isWorkOrder(order);
            if (tab === 'wait_pay') return isWaitingPayment(order);
            if (tab === 'closed') return isClosedOrder(order);
            return true;
        }

        function filterByType(order, typeTab) {
            if (typeTab === 'all') return true;
            return String(order.order_type || 'hall') === typeTab;
        }

        function updateSummary(orders) {
            var all = Array.isArray(orders) ? orders : [];
            var fresh = 0;
            var work = 0;
            var waitPay = 0;
            var closed = 0;
            all.forEach(function (o) {
                if (String(o.order_status || '') === 'new') fresh++;
                if (isWorkOrder(o)) work++;
                if (isWaitingPayment(o)) waitPay++;
                if (isClosedOrder(o)) closed++;
            });
            var active = Math.max(0, all.length - closed);
            if (summaryEls.active) summaryEls.active.textContent = String(active);
            if (summaryEls.fresh) summaryEls.fresh.textContent = String(fresh);
            if (summaryEls.work) summaryEls.work.textContent = String(work);
            if (summaryEls.waitPay) summaryEls.waitPay.textContent = String(waitPay);
            if (summaryEls.closed) summaryEls.closed.textContent = String(closed);

            var tabCounts = {
                all: all.length,
                new: fresh,
                work: work,
                wait_pay: waitPay,
                closed: closed
            };
            Object.keys(tabCounts).forEach(function (key) {
                var el = document.querySelector('[data-tab-count="' + key + '"]');
                if (el) el.textContent = '(' + tabCounts[key] + ')';
            });
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

        function grossFromItems(items) {
            if (!Array.isArray(items) || items.length === 0) return 0;
            return items.reduce(function (sum, it) {
                var qty = Number(it.quantity || 0);
                var price = Number(it.price || 0);
                return sum + (qty * price);
            }, 0);
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
            var orderType = String(order.order_type || 'hall');
            var orderTypeLabel = String(order.order_type_label || humanOrderType(orderType));
            var sourceLabel = String(order.source_label || '');
            var since = Number(order.since_minutes || 0);
            var createdAtFull = String(order.created_at || '');
            var total = formatMoney(order.total_price);
            var spent = Number(order.loyalty_points_spent || 0);
            var gross = grossFromItems(order.items);
            if (gross <= 0) gross = Number(order.total_price || 0) + spent;

            var countdownSecondsRemaining = order.countdown_seconds_remaining;
            var countdownExpired = !!order.countdown_expired;
            var countdownWarning = !!order.countdown_warning;
            var countdownMmss = order.countdown_mmss || '';
            var readyItemsCount = Number(order.ready_items_count || 0);
            var totalItemsCount = Number(order.total_items_count || 0);
            var partialReady = !!order.partial_ready;
            var hasWaiterCall = !!order.has_waiter_call;
            var loyaltyHasCard = !!order.loyalty_has_card;
            var loyaltyBalance = Number(order.loyalty_balance || 0);
            var loyaltyPhone = String(order.loyalty_phone || '');
            var guestName = String(order.guest_name || '').trim();
            var guestPhone = String(order.guest_phone || '').trim();
            var customerNameDisplay = String(order.customer_name_display || '').trim();
            var customerPhoneDisplay = String(order.customer_phone_display || '').trim();
            var deliveryAddress = String(order.delivery_address || '').trim();
            var scheduledForDisplay = String(order.scheduled_for_display || order.scheduled_for || '').trim();
            var preorderReceiveType = String(order.preorder_receive_type || '').trim();
            var preorderReceiveTypeLabel = String(order.preorder_receive_type_label || humanReceiveType(preorderReceiveType)).trim();
            var fulfillmentSummary = String(order.fulfillment_summary || '').trim();
            var courierStatus = String(order.courier_status || '').trim();
            var courierStatusLabel = String(order.courier_status_label || '').trim();
            var courierUserName = String(order.courier_user_name || '').trim();
            var orderComment = String(order.comment || '').trim();
            var loyaltyGuestId = Number(order.loyalty_guest_id || 0);
            var manualTxCount = Number(order.manual_loyalty_tx_count || 0);
            var manualAccrualCount = Number(order.manual_loyalty_accrual_count || 0);
            var manualSpendCount = Number(order.manual_loyalty_spend_count || 0);
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
            var totalCaption = spent > 0
                ? ('<div class="text-[11px] text-slate-500 mt-1">К оплате · из ' + formatMoney(gross) + ' ₽</div>')
                : '<div class="text-[11px] text-slate-500 mt-1">Итого</div>';
            var loyaltyBadges = [];
            if (loyaltyHasCard) {
                loyaltyBadges.push('<span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full border text-[11px] bg-emerald-500/10 text-emerald-200 border-emerald-400/60">Карта есть</span>');
                loyaltyBadges.push('<span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full border text-[11px] bg-slate-900/80 text-slate-200 border-slate-700">' + formatMoney(loyaltyBalance) + ' бонусов</span>');
            } else if (loyaltyPhone || loyaltyGuestId > 0) {
                loyaltyBadges.push('<span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full border text-[11px] bg-amber-500/10 text-amber-200 border-amber-400/60">Карты нет</span>');
            } else {
                loyaltyBadges.push('<span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full border text-[11px] bg-slate-900/80 text-slate-400 border-slate-700">Лояльность не привязана</span>');
            }
            if (manualTxCount > 0) {
                var manualLabel = manualTxCount + ' ручн. операции';
                if (manualTxCount === 1 && manualAccrualCount === 1 && manualSpendCount === 0) {
                    manualLabel = 'Ручное начисление';
                } else if (manualTxCount === 1 && manualSpendCount === 1 && manualAccrualCount === 0) {
                    manualLabel = 'Ручное списание';
                }
                loyaltyBadges.push('<span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full border text-[11px] bg-fuchsia-500/10 text-fuchsia-200 border-fuchsia-400/60">' + escapeHtml(manualLabel) + '</span>');
            }
            var loyaltySummaryBlock =
                '<div class="mt-3 rounded-2xl border border-slate-800/80 bg-slate-900/30 px-3 py-3">' +
                    '<div class="text-[11px] text-slate-500 font-medium mb-2">Loyalty context</div>' +
                    '<div class="flex flex-wrap items-center gap-2">' + loyaltyBadges.join('') + '</div>' +
                '</div>';

            var tn = String(order.table_name || '').trim();
            var isDeliveryOrderCard = (String(order.order_type || '') === 'delivery');
            var tableLine = tn !== ''
                ? ('<div class="text-sm font-semibold text-white mt-1">Стол: <span class="text-slate-100">' + escapeHtml(tn) + '</span></div>')
                : '';
            var sourceLine = sourceLabel
                ? '<div class="text-[11px] text-slate-500 mt-1">Источник: <span class="text-sky-200">' + escapeHtml(sourceLabel) + '</span></div>'
                : '';
            var courierLine = '';
            if (orderType === 'delivery' && courierStatusLabel !== '') {
                courierLine = '<div class="text-[11px] text-slate-500 mt-1">Курьер: <span class="text-violet-200">' + escapeHtml(courierStatusLabel) + (courierUserName ? (' · ' + escapeHtml(courierUserName)) : '') + '</span></div>';
            }
            var guestLine = '';
            if (guestName !== '' || guestPhone !== '') {
                var guestChunks = [];
                if (guestName !== '') guestChunks.push(escapeHtml(guestName));
                if (guestPhone !== '') guestChunks.push(escapeHtml(guestPhone));
                guestLine = '<div class="text-[11px] text-slate-500 mt-1">Гость: <span class="text-slate-200">' + guestChunks.join(' · ') + '</span></div>';
            }
            var fulfillmentLines = [];
            if (orderType === 'delivery') {
                if (customerNameDisplay !== '' || customerPhoneDisplay !== '') {
                    var deliveryContact = [];
                    if (customerNameDisplay !== '') deliveryContact.push(escapeHtml(customerNameDisplay));
                    if (customerPhoneDisplay !== '') deliveryContact.push(escapeHtml(customerPhoneDisplay));
                    fulfillmentLines.push('<div class="text-[11px] text-slate-500 mt-1">Получатель: <span class="text-slate-200">' + deliveryContact.join(' · ') + '</span></div>');
                }
                if (deliveryAddress !== '') {
                    fulfillmentLines.push('<div class="text-[11px] text-slate-500 mt-1">Адрес: <span class="text-slate-200">' + escapeHtml(deliveryAddress) + '</span></div>');
                }
            } else if (orderType === 'pickup') {
                var pickupContact = [];
                if (customerNameDisplay !== '') pickupContact.push(escapeHtml(customerNameDisplay));
                if (customerPhoneDisplay !== '') pickupContact.push(escapeHtml(customerPhoneDisplay));
                if (pickupContact.length) {
                    fulfillmentLines.push('<div class="text-[11px] text-slate-500 mt-1">Самовывоз: <span class="text-slate-200">' + pickupContact.join(' · ') + '</span></div>');
                }
            } else if (orderType === 'preorder') {
                if (scheduledForDisplay !== '') {
                    fulfillmentLines.push('<div class="text-[11px] text-slate-500 mt-1">Предзаказ на: <span class="text-slate-200">' + escapeHtml(scheduledForDisplay) + '</span></div>');
                }
                if (preorderReceiveTypeLabel !== '') {
                    fulfillmentLines.push('<div class="text-[11px] text-slate-500 mt-1">Формат: <span class="text-slate-200">' + escapeHtml(preorderReceiveTypeLabel) + '</span></div>');
                }
                var preorderContact = [];
                if (customerNameDisplay !== '') preorderContact.push(escapeHtml(customerNameDisplay));
                if (customerPhoneDisplay !== '') preorderContact.push(escapeHtml(customerPhoneDisplay));
                if (preorderContact.length) {
                    fulfillmentLines.push('<div class="text-[11px] text-slate-500 mt-1">Контакт: <span class="text-slate-200">' + preorderContact.join(' · ') + '</span></div>');
                }
                if (preorderReceiveType === 'delivery' && deliveryAddress !== '') {
                    fulfillmentLines.push('<div class="text-[11px] text-slate-500 mt-1">Адрес: <span class="text-slate-200">' + escapeHtml(deliveryAddress) + '</span></div>');
                }
            } else if (orderType === 'manual' && (customerNameDisplay !== '' || customerPhoneDisplay !== '')) {
                var manualContact = [];
                if (customerNameDisplay !== '') manualContact.push(escapeHtml(customerNameDisplay));
                if (customerPhoneDisplay !== '') manualContact.push(escapeHtml(customerPhoneDisplay));
                fulfillmentLines.push('<div class="text-[11px] text-slate-500 mt-1">Контакт: <span class="text-slate-200">' + manualContact.join(' · ') + '</span></div>');
            }
            if (!fulfillmentLines.length && fulfillmentSummary !== '' && orderType !== 'hall') {
                fulfillmentLines.push('<div class="text-[11px] text-slate-500 mt-1">Получение: <span class="text-slate-200">' + escapeHtml(fulfillmentSummary) + '</span></div>');
            }
            var fulfillmentBlock = fulfillmentLines.join('');
            var commentLine = orderComment !== ''
                ? ('<div class="mt-2 rounded-xl border border-slate-800/80 bg-slate-900/40 px-3 py-2 text-[11px] text-slate-300"><span class="text-slate-500">Комментарий:</span> ' + escapeHtml(orderComment) + '</div>')
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
            } else if (status === 'delivered') {
                action = disabledAction('Заказ закрыт', 'Заказ уже отдан гостю');
            } else if (status === 'canceled' || status === 'cancelled') {
                action = disabledAction('Заказ отменён', 'Изменение статуса недоступно');
            }
            var loyaltyAction = canUseLoyalty
                ? ('<a href="/staff/loyalty/scan.php?order_id=' + encodeURIComponent(order.id) + '" class="w-full min-h-[44px] px-4 py-3 rounded-xl border border-amber-400/50 bg-amber-500/10 text-amber-200 font-semibold text-sm hover:bg-amber-500/20 transition flex items-center justify-center">' +
                    'Бонусы' +
                '</a>')
                : '';
            var posLink = '/staff/pos.php' + (order.table_id > 0 ? ('?table_id=' + encodeURIComponent(order.table_id)) : '');
            var posAction = !canOpenPos
                ? ''
                : (isDeliveryOrderCard
                ? disabledAction('POS только для зала', 'POS доступен только для заказов по столам')
                : ('<a href="' + posLink + '" class="w-full min-h-[44px] px-4 py-3 rounded-xl border border-slate-600 bg-slate-900/70 text-slate-100 font-semibold text-sm hover:bg-slate-800 transition flex items-center justify-center">' +
                    'Открыть в POS' +
                '</a>'));
            var courierAction = (canOpenCourier && isDeliveryOrderCard)
                ? ('<a href="/staff/courier.php?order_id=' + encodeURIComponent(order.id) + '" class="w-full min-h-[44px] px-4 py-3 rounded-xl border border-violet-400/50 bg-violet-500/10 text-violet-200 font-semibold text-sm hover:bg-violet-500/20 transition flex items-center justify-center">Курьер</a>')
                : '';
            var paymentAction = '';
            if (deliveredUnpaid || (status === 'ready' && (paymentStatus === 'unpaid' || paymentStatus === 'pending'))) {
                paymentAction =
                    '<button type="button" data-action="payment" data-order-id="' + order.id + '" data-payment-status="paid" class="w-full min-h-[44px] px-4 py-3 rounded-xl border border-emerald-400/50 bg-emerald-500/10 text-emerald-200 font-semibold text-sm hover:bg-emerald-500/20 transition">' +
                        'Отметить оплату' +
                    '</button>';
            } else if (status === 'new' || status === 'accepted' || status === 'cooking') {
                paymentAction = disabledAction('Оплата после готовности', 'Сначала доведите заказ до статуса «Готово»');
            } else if (paymentStatus === 'paid') {
                paymentAction = disabledAction('Уже оплачено', 'Повторная отметка не требуется');
            } else {
                paymentAction = disabledAction('Оплата не требуется', 'Для этого заказа действие недоступно');
            }

            var actions = [];
            if (posAction) actions.push('<div>' + posAction + '</div>');
            if (courierAction) actions.push('<div>' + courierAction + '</div>');
            if (loyaltyAction) actions.push('<div>' + loyaltyAction + '</div>');
            actions.push('<div>' + paymentAction + '</div>');
            actions.push('<div>' + (action || disabledAction('Без действий', 'Нет доступных действий для текущего статуса')) + '</div>');

            return (
                '<article class="rounded-3xl border border-slate-800/80 bg-slate-950/55 shadow-xl shadow-black/20 overflow-hidden hover:border-emerald-500/20 transition-all">' +
                    '<div class="p-4 md:p-5">' +
                        '<div class="flex items-start justify-between gap-3">' +
                            '<div class="min-w-0">' +
                                '<div class="text-[11px] text-slate-500">Заказ <span class="font-mono text-slate-100">' + escapeHtml(order.order_number || ('#' + order.id)) + '</span></div>' +
                                '<div class="text-[11px] text-slate-500 mt-1">Тип: <span class="text-cyan-200">' + escapeHtml(orderTypeLabel) + '</span></div>' +
                                sourceLine +
                                tableLine +
                                guestLine +
                                courierLine +
                                fulfillmentBlock +
                                '<div class="mt-2 text-[11px] text-slate-500">Создан: <span class="text-slate-200">' + escapeHtml(order.created_at_short || '') + '</span>' + (createdAtFull ? (' <span class="text-slate-600">(' + escapeHtml(createdAtFull) + ')</span>') : '') + '</div>' +
                                '<div class="text-[11px] text-slate-500">Минут назад: <span class="text-emerald-300 font-semibold">' + since + '</span></div>' +
                            '</div>' +
                            '<div class="text-right shrink-0">' +
                                '<div class="text-lg md:text-xl font-extrabold text-emerald-400 tabular-nums">' + total + ' ₽</div>' +
                                totalCaption +
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
                            '<span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-cyan-500/10 border border-cyan-400/60 text-[11px] text-cyan-100">' +
                                escapeHtml(orderTypeLabel) +
                            '</span>' +
                            (courierStatusLabel !== ''
                                ? '<span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-violet-500/10 border border-violet-400/60 text-[11px] text-violet-100">' + escapeHtml(courierStatusLabel) + '</span>'
                                : '') +
                            waiterBadge +
                            deliveredUnpaidBadge +
                            progressBadge +
                            (countdownBadge ? countdownBadge : '') +
                        '</div>' +
                        loyaltySummaryBlock +
                        commentLine +

                        '<div class="mt-3 rounded-2xl border border-slate-800/80 bg-[#0f172a]/30 overflow-hidden">' +
                            '<div class="px-4 py-2 flex items-center justify-between bg-slate-950/40 border-b border-slate-800/70">' +
                                '<div class="text-[11px] text-slate-400 font-medium">Позиции</div>' +
                                '<div class="text-[11px] text-slate-500">' + (Number(order.items_count || (order.items ? order.items.length : 0)) || 0) + ' шт.</div>' +
                            '</div>' +
                            '<div>' + itemsHtml(order.items) + '</div>' +
                            (spent > 0
                                ? '<div class="px-4 py-3 border-t border-slate-800/70 bg-slate-900/40 text-xs space-y-1.5">' +
                                    '<div class="flex items-center justify-between gap-3"><span class="text-slate-500">Сумма блюд</span><span class="text-slate-200 font-semibold">' + formatMoney(gross) + ' ₽</span></div>' +
                                    '<div class="flex items-center justify-between gap-3"><span class="text-amber-300">Списано бонусами</span><span class="text-amber-300 font-semibold">-' + formatMoney(spent) + ' ₽</span></div>' +
                                    '<div class="flex items-center justify-between gap-3"><span class="text-slate-400">К оплате</span><span class="text-emerald-300 font-semibold">' + total + ' ₽</span></div>' +
                                '</div>'
                                : '') +
                        '</div>' +

                        '<div class="mt-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-2">' +
                            actions.join('') +
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
            updateSummary(orders);
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
            filtered = filtered.filter(function (o) { return filterByType(o, currentTypeTab); });

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

        function stopPolling(messageHtml) {
            pollingStopped = true;
            if (pollTimer) {
                clearInterval(pollTimer);
                pollTimer = null;
            }
            if (messageHtml) {
                ordersContainer.innerHTML = messageHtml;
            }
        }

        function fetchOrders() {
            if (pollingStopped) return;
            var url = '/staff/orders_api.php';
            if (tableId && tableId > 0) {
                url += '?table_id=' + encodeURIComponent(tableId);
            }
            fetch(url, {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                }
            })
                .then(function (r) {
                    if (r.status === 401 || r.status === 403) {
                        stopPolling(
                            '<div class="sm:col-span-2 xl:col-span-3 rounded-3xl border border-amber-500/50 bg-amber-500/10 p-5 text-sm text-amber-100 shadow-lg shadow-amber-950/20">Сессия завершена или доступ ограничен. Обновите страницу и войдите снова.</div>'
                        );
                        throw new Error('unauthorized');
                    }
                    return r.text().then(function (txt) {
                        try { return JSON.parse(txt); } catch (e) {
                            throw new Error(txt || ('HTTP ' + r.status));
                        }
                    });
                })
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
                    if (pollingStopped) return;
                    ordersContainer.innerHTML =
                        '<div class="sm:col-span-2 xl:col-span-3 rounded-3xl border border-red-500/50 bg-red-500/10 p-5 text-sm text-red-100 shadow-lg shadow-red-950/30">Ошибка соединения с сервером</div>';
                });
        }

            // Обновление статуса/оплаты по кнопкам
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('button[data-action="status"], button[data-action="payment"]');
            if (!btn) return;

            var orderId = btn.dataset.orderId;
            var actionType = btn.dataset.action;
            var newStatus = btn.dataset.status;
            var paymentStatus = btn.dataset.paymentStatus;
            if (!orderId) return;

            btn.disabled = true;
            btn.classList.add('opacity-70', 'cursor-not-allowed');

            var formData = new FormData();
            formData.append('order_id', orderId);
            if (actionType === 'status' && newStatus) {
                formData.append('order_status', newStatus);
            }
            if (actionType === 'payment' && paymentStatus) {
                formData.append('payment_status', paymentStatus);
            }
            if (csrf) formData.append('csrf', csrf);

            fetch('/staff/order_update_status.php', {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                }
            })
                .then(function (r) {
                    return r.text().then(function (txt) {
                        try { return JSON.parse(txt); } catch (e) {
                            throw new Error(txt || ('HTTP ' + r.status));
                        }
                    });
                })
                .then(function (data) {
                    if (data && data.success) {
                        fetchOrders();
                    } else {
                        alert((data && data.message) ? data.message : 'Ошибка обновления заказа');
                    }
                })
                .catch(function (err) {
                    var msg = (err && err.message) ? String(err.message) : '';
                    if (msg && msg.length < 240) {
                        alert('Ошибка обновления заказа: ' + msg);
                    } else {
                        alert('Ошибка сети при обновлении заказа');
                    }
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

        var typeButtons = Array.prototype.slice.call(document.querySelectorAll('.type-tab-btn'));
        typeButtons.forEach(function (b) {
            b.addEventListener('click', function () {
                var tab = b.dataset.typeTab;
                if (!tab) return;
                currentTypeTab = tab;
                typeButtons.forEach(function (x) {
                    x.classList.remove('bg-cyan-500/20', 'text-cyan-100', 'border-cyan-400/50');
                    x.classList.add('bg-[#0B0F19]/20', 'text-slate-300');
                });
                b.classList.remove('bg-[#0B0F19]/20', 'text-slate-300');
                b.classList.add('bg-cyan-500/20', 'text-cyan-100', 'border-cyan-400/50');
                if (lastData) renderOrders(lastData);
            });
        });

        fetchOrders();
        pollTimer = setInterval(fetchOrders, 5000);
    })();
</script>
</body>
</html>
