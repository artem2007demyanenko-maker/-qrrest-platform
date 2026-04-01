<?php
/**
 * Kitchen display: fullscreen view — New / Cooking / Ready.
 * Tablet-friendly, big cards, auto-refresh. Demo: static orders.
 */

require_once __DIR__ . '/../../app/bootstrap.php';
require_login();
if (!$currentRestaurant) {
    http_response_code(404);
    echo 'Restaurant context required';
    exit;
}
require_restaurant_role((int) $currentRestaurant['id'], ['staff', 'admin', 'owner']);

$demo = is_demo_mode();
$restName = $currentRestaurant['name'] ?? 'Restaurant';
if (!function_exists('e')) {
    function e($v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
}

require_once __DIR__ . '/../../app/kds_helpers.php';
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

$initialStation = '';
if (isset($_GET['station'])) {
    $s = strtolower(trim((string)$_GET['station']));
    if (in_array($s, kds_allowed_stations(), true)) {
        $initialStation = $s;
    }
}
$initialStatus = 'all';
if (isset($_GET['status'])) {
    $st = strtolower(trim((string)$_GET['status']));
    if (in_array($st, ['all', 'new', 'accepted', 'cooking', 'ready'], true)) {
        $initialStatus = $st;
    }
}
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>KDS по станциям — <?= e($restName) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-950 text-slate-50">
<div class="min-h-screen flex flex-col">
    <header class="border-b border-slate-800 bg-slate-900/95 px-4 py-3 flex flex-wrap items-center justify-between gap-3">
        <div class="min-w-0">
            <div class="text-xs text-slate-400">Кухня · live</div>
            <h1 class="text-xl md:text-2xl font-bold text-slate-100">KDS по станциям</h1>
            <div class="text-xs text-slate-500 mt-0.5"><?= e($restName) ?></div>
        </div>
        <div class="flex items-center gap-2">
            <a href="/staff/orders.php" class="px-3 py-2 rounded-xl bg-slate-800 border border-slate-700 text-xs text-slate-200 hover:bg-slate-700">
                К заказам
            </a>
            <button id="btn-fullscreen" type="button" class="px-3 py-2 rounded-xl bg-slate-800 border border-slate-700 text-xs text-slate-200 hover:bg-slate-700">
                Во весь экран
            </button>
        </div>
    </header>

    <main class="flex-1 p-3 md:p-5">
        <div class="rounded-2xl border border-slate-800 bg-slate-900/70 p-3 mb-3 space-y-3">
            <div class="flex flex-wrap items-center gap-2">
                <span class="text-xs text-slate-400 mr-1">Станция:</span>
                <button type="button" class="kds-station-tab px-3 py-2 rounded-xl border border-slate-700 text-sm font-semibold" data-station="all">Все</button>
                <button type="button" class="kds-station-tab px-3 py-2 rounded-xl border border-slate-700 text-sm font-semibold" data-station="hot">Горячий</button>
                <button type="button" class="kds-station-tab px-3 py-2 rounded-xl border border-slate-700 text-sm font-semibold" data-station="cold">Холодный</button>
                <button type="button" class="kds-station-tab px-3 py-2 rounded-xl border border-slate-700 text-sm font-semibold" data-station="bar">Бар</button>
                <button type="button" class="kds-station-tab px-3 py-2 rounded-xl border border-slate-700 text-sm font-semibold" data-station="dessert">Десерты</button>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <span class="text-xs text-slate-400 mr-1">Статус:</span>
                <button type="button" class="kds-status-tab px-3 py-2 rounded-xl border border-slate-700 text-sm font-semibold" data-status="all">Все</button>
                <button type="button" class="kds-status-tab px-3 py-2 rounded-xl border border-slate-700 text-sm font-semibold" data-status="new">Новые</button>
                <button type="button" class="kds-status-tab px-3 py-2 rounded-xl border border-slate-700 text-sm font-semibold" data-status="accepted">Приняты</button>
                <button type="button" class="kds-status-tab px-3 py-2 rounded-xl border border-slate-700 text-sm font-semibold" data-status="cooking">Готовятся</button>
                <button type="button" class="kds-status-tab px-3 py-2 rounded-xl border border-slate-700 text-sm font-semibold" data-status="ready">Готово</button>
            </div>
            <div class="text-xs text-slate-500">Обновление каждые 5 секунд · крупные кнопки для планшета</div>
        </div>

        <audio id="kds-sound" src="/assets/new-order.mp3" preload="auto"></audio>

        <div class="grid grid-cols-1 xl:grid-cols-4 gap-3 md:gap-4">
            <section id="wrap-hot" class="rounded-2xl border border-amber-500/40 bg-slate-900/80 p-3">
                <h2 class="text-sm font-bold text-amber-200 mb-2">Горячий цех</h2>
                <div id="sec-hot" class="space-y-3"></div>
            </section>
            <section id="wrap-cold" class="rounded-2xl border border-sky-500/40 bg-slate-900/80 p-3">
                <h2 class="text-sm font-bold text-sky-200 mb-2">Холодный цех</h2>
                <div id="sec-cold" class="space-y-3"></div>
            </section>
            <section id="wrap-bar" class="rounded-2xl border border-emerald-500/40 bg-slate-900/80 p-3">
                <h2 class="text-sm font-bold text-emerald-200 mb-2">Бар</h2>
                <div id="sec-bar" class="space-y-3"></div>
            </section>
            <section id="wrap-dessert" class="rounded-2xl border border-violet-500/40 bg-slate-900/80 p-3">
                <h2 class="text-sm font-bold text-violet-200 mb-2">Десерты</h2>
                <div id="sec-dessert" class="space-y-3"></div>
            </section>
        </div>

        <div id="kds-empty" class="hidden mt-4 rounded-2xl border border-slate-800/80 bg-slate-900/50 px-4 py-4 text-sm text-slate-400">
            Нет активных тикетов.
        </div>
    </main>
</div>

<script>
(function(){
    const demo = <?= $demo ? 'true' : 'false' ?>;
    const csrf = <?= json_encode((string)($_SESSION['csrf'] ?? ''), JSON_UNESCAPED_UNICODE) ?>;
    let stationFilter = <?= json_encode($initialStation !== '' ? $initialStation : 'all', JSON_UNESCAPED_UNICODE) ?>;
    let statusFilter = <?= json_encode($initialStatus, JSON_UNESCAPED_UNICODE) ?>;

    const stationTabs = Array.prototype.slice.call(document.querySelectorAll('.kds-station-tab'));
    const statusTabs = Array.prototype.slice.call(document.querySelectorAll('.kds-status-tab'));

    const sections = {
        hot: document.getElementById('sec-hot'),
        cold: document.getElementById('sec-cold'),
        bar: document.getElementById('sec-bar'),
        dessert: document.getElementById('sec-dessert')
    };
    const wraps = {
        hot: document.getElementById('wrap-hot'),
        cold: document.getElementById('wrap-cold'),
        bar: document.getElementById('wrap-bar'),
        dessert: document.getElementById('wrap-dessert')
    };
    const emptyEl = document.getElementById('kds-empty');
    const soundEl = document.getElementById('kds-sound');

    function esc(s){
        return String(s)
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    }

    function setActiveButtons(){
        stationTabs.forEach(function(btn){
            const active = (btn.dataset.station || 'all') === stationFilter;
            btn.classList.toggle('bg-emerald-500/15', active);
            btn.classList.toggle('text-emerald-200', active);
            btn.classList.toggle('border-emerald-500/40', active);
            btn.classList.toggle('bg-[#0B0F19]/20', !active);
            btn.classList.toggle('text-slate-300', !active);
        });
        statusTabs.forEach(function(btn){
            const active = (btn.dataset.status || 'all') === statusFilter;
            btn.classList.toggle('bg-emerald-500/15', active);
            btn.classList.toggle('text-emerald-200', active);
            btn.classList.toggle('border-emerald-500/40', active);
            btn.classList.toggle('bg-[#0B0F19]/20', !active);
            btn.classList.toggle('text-slate-300', !active);
        });
    }

    function applyStationVisibility(){
        Object.keys(wraps).forEach(function(k){
            wraps[k].classList.toggle('hidden', stationFilter !== 'all' && stationFilter !== k);
        });
    }

    function timerClass(min){
        if (min >= 15) return 'text-red-200 animate-pulse';
        if (min >= 10) return 'text-red-300';
        if (min >= 5) return 'text-amber-300';
        return 'text-emerald-300';
    }

    function statusBadgeClass(st){
        const map = {
            new: 'bg-amber-500/10 text-amber-200 border-amber-400/60',
            accepted: 'bg-sky-500/10 text-sky-200 border-sky-400/60',
            cooking: 'bg-sky-500/10 text-sky-200 border-sky-400/60',
            ready: 'bg-emerald-500/10 text-emerald-200 border-emerald-400/60'
        };
        return map[st] || 'bg-slate-800 text-slate-200 border-slate-700';
    }

    function actionButton(ticket){
        if (demo) return '';
        const st = String(ticket.station_status || 'new');
        if (st === 'new') {
            return '<button type="button" data-action="accept" class="w-full min-h-[52px] px-4 py-3 rounded-xl bg-sky-500 hover:bg-sky-400 text-[#0B0F19] text-sm font-bold">Принять станцию</button>';
        }
        if (st === 'accepted') {
            return '<button type="button" data-action="start" class="w-full min-h-[52px] px-4 py-3 rounded-xl bg-amber-500 hover:bg-amber-400 text-[#0B0F19] text-sm font-bold">Начать готовку</button>';
        }
        if (st === 'cooking') {
            return '<button type="button" data-action="ready" class="w-full min-h-[52px] px-4 py-3 rounded-xl bg-emerald-500 hover:bg-emerald-400 text-[#0B0F19] text-sm font-bold">Отметить готовой</button>';
        }
        return '<div class="w-full min-h-[52px] px-4 py-3 rounded-xl border border-emerald-500/40 bg-emerald-500/10 text-emerald-200 text-sm font-bold flex items-center justify-center">Станция готова</div>';
    }

    function renderCard(t){
        const createdMin = Number(t.minutes_since_created || 0);
        const items = Array.isArray(t.items) ? t.items : [];
        const countdown = t.countdown_mmss ? (' · Оплата: ' + t.countdown_mmss) : '';
        const waiterBadge = t.has_waiter_call ? '<span class="inline-flex items-center px-2 py-0.5 rounded-full bg-orange-500/10 border border-orange-400/60 text-[10px] text-orange-200">Вызов</span>' : '';
        const partial = t.partial_ready ? '<span class="inline-flex items-center px-2 py-0.5 rounded-full bg-indigo-500/10 border border-indigo-400/60 text-[10px] text-indigo-200">Частично готов</span>' : '';
        const dangerPay = (t.countdown_expired || (t.order_status === 'delivered' && t.payment_status === 'unpaid'))
            ? '<span class="inline-flex items-center px-2 py-0.5 rounded-full bg-red-500/10 border border-red-400/60 text-[10px] text-red-200">Критично</span>'
            : '';

        return '' +
        '<article data-order-id="' + esc(t.order_id) + '" data-station="' + esc(t.station) + '" class="rounded-2xl border border-slate-700 bg-slate-800/70 p-3">' +
            '<div class="flex items-start justify-between gap-3">' +
                '<div class="min-w-0">' +
                    '<div class="text-sm text-slate-200">Заказ <span class="font-mono text-white">#' + esc(t.order_id) + '</span> · ' + esc(t.table_name || '—') + '</div>' +
                    '<div class="text-xs mt-1 ' + timerClass(createdMin) + '">' + createdMin + ' мин в работе' + esc(countdown) + '</div>' +
                '</div>' +
                '<span class="inline-flex items-center px-2 py-0.5 rounded-full border text-[10px] ' + statusBadgeClass(String(t.station_status || 'new')) + '">' + esc(String(t.station_status || 'new')) + '</span>' +
            '</div>' +
            '<div class="mt-2 flex flex-wrap gap-1.5">' +
                waiterBadge + partial + dangerPay +
                '<span class="inline-flex items-center px-2 py-0.5 rounded-full border border-slate-700 bg-slate-900/50 text-[10px] text-slate-300">' + esc((t.ready_items_count || 0) + '/' + (t.total_items_count || 0)) + '</span>' +
            '</div>' +
            '<ul class="mt-3 space-y-1 text-sm">' +
                items.map(function(it){
                    const qty = Number(it.quantity || 1);
                    return '<li class="flex items-start justify-between gap-3"><span class="text-slate-100">' + esc(it.item_name || it.menu_name || 'Позиция') + '</span><span class="text-slate-300 font-semibold">×' + qty + '</span></li>';
                }).join('') +
            '</ul>' +
            '<div class="mt-3 flex flex-col gap-2">' +
                actionButton(t) +
                '<button type="button" data-action="notify_waiter" class="w-full min-h-[46px] px-4 py-2 rounded-xl bg-slate-900/80 hover:bg-slate-800 border border-slate-600 text-slate-100 text-xs font-semibold">Позвать официанта</button>' +
            '</div>' +
        '</article>';
    }

    let inFlight = false;
    let seen = new Set();

    async function fetchTickets(){
        if (inFlight) return;
        inFlight = true;
        try {
            let url = '/staff/kitchen_api.php?status=' + encodeURIComponent(statusFilter);
            if (stationFilter !== 'all') {
                url += '&station=' + encodeURIComponent(stationFilter);
            }
            const r = await fetch(url, { cache: 'no-store', credentials: 'same-origin' });
            const data = await r.json();
            if (!data || !data.success) return;
            const tickets = Array.isArray(data.tickets) ? data.tickets : [];

            const current = new Set();
            let hasNew = false;
            tickets.forEach(function(t){
                const id = String(t.ticket_id || '');
                if (!id) return;
                current.add(id);
                if (!seen.has(id)) hasNew = true;
            });
            seen = current;
            if (hasNew && soundEl) {
                soundEl.currentTime = 0;
                soundEl.play().catch(function(){});
            }

            Object.keys(sections).forEach(function(s){ sections[s].innerHTML = ''; });
            let total = 0;
            tickets.forEach(function(t){
                const s = String(t.station || '').toLowerCase();
                if (!sections[s]) return;
                if (stationFilter !== 'all' && stationFilter !== s) return;
                sections[s].insertAdjacentHTML('beforeend', renderCard(t));
                total++;
            });
            emptyEl.classList.toggle('hidden', total > 0);
        } catch (e) {
            // ignore
        } finally {
            inFlight = false;
        }
    }

    async function postAction(orderId, station, action){
        const fd = new FormData();
        fd.append('csrf', csrf);
        fd.append('order_id', String(orderId));
        fd.append('station', String(station));
        fd.append('action', String(action));
        const r = await fetch('/staff/kitchen_item_update.php', {
            method: 'POST',
            body: fd,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const data = await r.json();
        if (!data || !data.success) {
            throw new Error((data && data.message) ? data.message : 'Ошибка действия кухни');
        }
        return data;
    }

    document.addEventListener('click', async function(ev){
        const btn = ev.target.closest('button[data-action]');
        if (!btn) return;
        const card = btn.closest('article[data-order-id][data-station]');
        if (!card) return;
        if (demo) return;

        const action = String(btn.dataset.action || '');
        const orderId = Number(card.dataset.orderId || '0');
        const station = String(card.dataset.station || '');
        if (!action || !orderId || !station) return;

        const old = btn.textContent;
        btn.disabled = true;
        btn.classList.add('opacity-60', 'cursor-not-allowed');
        btn.textContent = 'Обновляем...';
        try {
            await postAction(orderId, station, action);
            await fetchTickets();
        } catch (e) {
            alert(e.message || 'Ошибка действия кухни');
        } finally {
            btn.disabled = false;
            btn.classList.remove('opacity-60', 'cursor-not-allowed');
            btn.textContent = old;
        }
    });

    stationTabs.forEach(function(btn){
        btn.addEventListener('click', function(){
            stationFilter = btn.dataset.station || 'all';
            setActiveButtons();
            applyStationVisibility();
            fetchTickets();
        });
    });
    statusTabs.forEach(function(btn){
        btn.addEventListener('click', function(){
            statusFilter = btn.dataset.status || 'all';
            setActiveButtons();
            fetchTickets();
        });
    });

    const fsBtn = document.getElementById('btn-fullscreen');
    fsBtn?.addEventListener('click', async function(){
        try {
            if (!document.fullscreenElement) {
                await document.documentElement.requestFullscreen();
            } else {
                await document.exitFullscreen();
            }
        } catch (e) {}
    });

    setActiveButtons();
    applyStationVisibility();
    fetchTickets();
    setInterval(fetchTickets, 5000);
})();
</script>
</body>
</html>
<?php exit; ?>

<?php
$ordersForColumns = ['new' => [], 'cooking' => [], 'ready' => []];
if ($demo) {
    $raw = demo_kitchen_orders();
    $now = time();
    foreach ($raw as $o) {
        $st = $o['order_status'] ?? 'new';
        $createdTs = isset($o['created_at']) ? strtotime($o['created_at']) : null;
        $sinceMinutes = $createdTs ? max(0, (int)floor(($now - $createdTs) / 60)) : 0;
        $o['since_minutes'] = $sinceMinutes;
        if ($st === 'new') {
            $ordersForColumns['new'][] = $o;
        } elseif ($st === 'ready') {
            $ordersForColumns['ready'][] = $o;
        } else {
            $ordersForColumns['cooking'][] = $o;
        }
    }
}
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Kitchen — <?= e($restName) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-950 text-slate-50">
<div class="min-h-screen flex flex-col">
    <header class="flex-shrink-0 border-b border-slate-800 bg-slate-900/90 px-4 py-3 flex items-center justify-between">
        <h1 class="text-xl md:text-2xl font-bold text-slate-100">Kitchen Display · <?= e($restName) ?></h1>
        <div class="text-sm text-slate-400">Auto-refresh 10s</div>
    </header>
    <main class="flex-1 overflow-auto p-4 md:p-6">
        <div id="kitchen-columns" class="grid grid-cols-1 md:grid-cols-3 gap-4 md:gap-6">
            <?php if ($demo): ?>
            <!-- Demo: server-rendered columns -->
            <div class="rounded-2xl border border-amber-500/40 bg-slate-900/80 p-4">
                <h2 class="text-lg font-bold text-amber-200 mb-3 flex items-center gap-2">
                    <span class="w-3 h-3 rounded-full bg-amber-400 animate-pulse"></span> New
                </h2>
                <div class="space-y-3">
                    <?php foreach ($ordersForColumns['new'] as $o): ?>
                    <div class="rounded-xl bg-slate-800/90 border border-slate-700 p-4">
                        <div class="text-2xl font-bold text-slate-100">#<?= (int)$o['id'] ?> · <?= e($o['table_name']) ?></div>
                        <div class="text-slate-400 text-sm mt-1">
                            <?= isset($o['since_minutes']) ? (int)$o['since_minutes'] . ' мин назад' : e($o['created_at_short']) ?>
                        </div>
                        <ul class="mt-2 text-lg text-slate-200 space-y-1">
                            <?php foreach ($o['items'] as $it): ?>
                            <li><?= e($it['menu_name']) ?> <?= (int)($it['quantity'] ?? 1) > 1 ? '×' . (int)$it['quantity'] : '' ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php endforeach; ?>
                    <?php if (empty($ordersForColumns['new'])): ?>
                    <div class="text-slate-500 py-4 text-center">—</div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="rounded-2xl border border-sky-500/40 bg-slate-900/80 p-4">
                <h2 class="text-lg font-bold text-sky-200 mb-3 flex items-center gap-2">
                    <span class="w-3 h-3 rounded-full bg-sky-400"></span> Cooking
                </h2>
                <div class="space-y-3">
                    <?php foreach ($ordersForColumns['cooking'] as $o): ?>
                    <div class="rounded-xl bg-slate-800/90 border border-slate-700 p-4">
                        <div class="text-2xl font-bold text-slate-100">#<?= (int)$o['id'] ?> · <?= e($o['table_name']) ?></div>
                        <div class="text-slate-400 text-sm mt-1">
                            <?= isset($o['since_minutes']) ? (int)$o['since_minutes'] . ' мин назад' : e($o['created_at_short']) ?>
                        </div>
                        <ul class="mt-2 text-lg text-slate-200 space-y-1">
                            <?php foreach ($o['items'] as $it): ?>
                            <li><?= e($it['menu_name']) ?> <?= (int)($it['quantity'] ?? 1) > 1 ? '×' . (int)$it['quantity'] : '' ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php endforeach; ?>
                    <?php if (empty($ordersForColumns['cooking'])): ?>
                    <div class="text-slate-500 py-4 text-center">—</div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="rounded-2xl border border-emerald-500/40 bg-slate-900/80 p-4">
                <h2 class="text-lg font-bold text-emerald-200 mb-3 flex items-center gap-2">
                    <span class="w-3 h-3 rounded-full bg-emerald-400 animate-pulse"></span> Ready
                </h2>
                <div class="space-y-3">
                    <?php foreach ($ordersForColumns['ready'] as $o): ?>
                    <div class="rounded-xl bg-slate-800/90 border border-emerald-700/50 p-4">
                        <div class="text-2xl font-bold text-slate-100">#<?= (int)$o['id'] ?> · <?= e($o['table_name']) ?></div>
                        <div class="text-slate-400 text-sm mt-1">
                            <?= isset($o['since_minutes']) ? (int)$o['since_minutes'] . ' мин назад' : e($o['created_at_short']) ?>
                        </div>
                        <ul class="mt-2 text-lg text-slate-200 space-y-1">
                            <?php foreach ($o['items'] as $it): ?>
                            <li><?= e($it['menu_name']) ?> <?= (int)($it['quantity'] ?? 1) > 1 ? '×' . (int)$it['quantity'] : '' ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php endforeach; ?>
                    <?php if (empty($ordersForColumns['ready'])): ?>
                    <div class="text-slate-500 py-4 text-center">—</div>
                    <?php endif; ?>
                </div>
            </div>
            <?php else: ?>
            <div id="col-new" class="rounded-2xl border border-amber-500/40 bg-slate-900/80 p-4">
                <h2 class="text-lg font-bold text-amber-200 mb-3 flex items-center gap-2">
                    <span class="w-3 h-3 rounded-full bg-amber-400 animate-pulse"></span> New
                </h2>
                <div class="space-y-3" id="cards-new"></div>
            </div>
            <div id="col-cooking" class="rounded-2xl border border-sky-500/40 bg-slate-900/80 p-4">
                <h2 class="text-lg font-bold text-sky-200 mb-3 flex items-center gap-2">
                    <span class="w-3 h-3 rounded-full bg-sky-400"></span> Cooking
                </h2>
                <div class="space-y-3" id="cards-cooking"></div>
            </div>
            <div id="col-ready" class="rounded-2xl border border-emerald-500/40 bg-slate-900/80 p-4">
                <h2 class="text-lg font-bold text-emerald-200 mb-3 flex items-center gap-2">
                    <span class="w-3 h-3 rounded-full bg-emerald-400 animate-pulse"></span> Ready
                </h2>
                <div class="space-y-3" id="cards-ready"></div>
            </div>
            <?php endif; ?>
        </div>
    </main>
</div>
<?php if (!$demo): ?>
<script>
(function () {
    const cardsNew = document.getElementById('cards-new');
    const cardsCooking = document.getElementById('cards-cooking');
    const cardsReady = document.getElementById('cards-ready');
    const empty = '<div class="text-slate-500 py-4 text-center text-lg">—</div>';

    function cardHtml(o) {
        const items = (o.items || []).map(it => it.quantity > 1 ? it.menu_name + ' ×' + it.quantity : it.menu_name).join(', ');
        const borderClass = o.order_status === 'ready' ? 'border-emerald-700/50' : 'border-slate-700';
        return '<div class="rounded-xl bg-slate-800/90 border ' + borderClass + ' p-4">' +
            '<div class="text-2xl font-bold text-slate-100">#' + o.id + ' · ' + (o.table_name || '—') + '</div>' +
            '<div class="text-slate-400 text-sm mt-1">' +
                (typeof o.since_minutes === 'number'
                    ? o.since_minutes + ' мин назад'
                    : (o.created_at_short || '')
                ) +
            '</div>' +
            '<ul class="mt-2 text-lg text-slate-200 space-y-1">' + (o.items || []).map(it => '<li>' + it.menu_name + (it.quantity > 1 ? ' ×' + it.quantity : '') + '</li>').join('') + '</ul></div>';
    }

    function render(orders) {
        const active = orders.filter(o => o.order_status !== 'canceled' && o.order_status !== 'delivered');
        const newOrders = active.filter(o => o.order_status === 'new');
        const cookingOrders = active.filter(o => o.order_status === 'accepted' || o.order_status === 'cooking');
        const readyOrders = active.filter(o => o.order_status === 'ready');
        cardsNew.innerHTML = newOrders.length ? newOrders.map(cardHtml).join('') : empty;
        cardsCooking.innerHTML = cookingOrders.length ? cookingOrders.map(cardHtml).join('') : empty;
        cardsReady.innerHTML = readyOrders.length ? readyOrders.map(cardHtml).join('') : empty;
    }

    function fetchOrders() {
        fetch('/staff/orders_api.php')
            .then(r => r.json())
            .then(data => {
                if (data.success && data.orders) render(data.orders);
            })
            .catch(() => {});
    }
    fetchOrders();
    setInterval(fetchOrders, 10000);
})();
</script>
<?php endif; ?>
</body>
</html>
