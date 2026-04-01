<?php
/**
 * Kitchen Display System (polling/AJAX, no websockets).
 * Read model: /ajax/kds_orders.php
 * Write model: /ajax/kds_update_status.php
 */

require_once __DIR__ . '/../../app/bootstrap.php';
require_login();

if (!$currentRestaurant) {
    http_response_code(404);
    echo 'Restaurant context required';
    exit;
}

require_restaurant_role((int)$currentRestaurant['id'], ['staff', 'admin', 'owner']);

if (!function_exists('e')) {
    function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

$demo = function_exists('is_demo_mode') && is_demo_mode();
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>KDS — <?= e($currentRestaurant['name'] ?? 'Restaurant') ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-950 text-slate-50">
<div class="min-h-screen flex flex-col">
    <header class="border-b border-slate-800 bg-slate-900/95 px-4 py-3 flex items-center justify-between gap-3">
        <div>
            <div class="text-xs text-slate-400">КУХНЯ · LIVE</div>
            <h1 class="text-xl md:text-2xl font-semibold">Kitchen Display (KDS)</h1>
            <div class="text-xs text-slate-400 mt-0.5"><?= e($currentRestaurant['name'] ?? '') ?></div>
        </div>
        <div class="flex items-center gap-4 text-right text-xs text-slate-400">
            <div class="flex items-center gap-2">
                <span>Станция:</span>
                <select id="station-filter" class="rounded-lg bg-slate-800 border border-slate-600 px-2 py-1.5 text-sm text-slate-100">
                    <option value="">Все</option>
                    <option value="HOT">HOT</option>
                    <option value="COLD">COLD</option>
                    <option value="BAR">BAR</option>
                    <option value="DESSERT">DESSERT</option>
                </select>
            </div>
            <div>
                <div>Обновление каждые 4 сек</div>
                <a href="/staff/orders.php" class="inline-flex mt-1 text-sky-300 hover:text-sky-200">← Заказы</a>
            </div>
        </div>
    </header>

    <main class="flex-1 p-3 md:p-5">
        <?php if ($demo): ?>
            <div class="mb-3 rounded-2xl border border-amber-500/40 bg-amber-500/10 px-4 py-2 text-xs text-amber-200">
                Демо-режим: изменение статусов отключено.
            </div>
        <?php endif; ?>
        <div class="grid grid-cols-1 xl:grid-cols-3 gap-3 md:gap-4">
            <section class="rounded-2xl border border-red-500/40 bg-slate-900/80 p-3">
                <h2 class="text-base font-bold text-red-200 mb-2 flex items-center gap-2"><span class="w-2.5 h-2.5 rounded-full bg-red-400"></span> NEW</h2>
                <div id="col-new" class="space-y-3"></div>
            </section>
            <section class="rounded-2xl border border-amber-500/40 bg-slate-900/80 p-3">
                <h2 class="text-base font-bold text-amber-200 mb-2 flex items-center gap-2"><span class="w-2.5 h-2.5 rounded-full bg-amber-400"></span> IN PROGRESS</h2>
                <div id="col-progress" class="space-y-3"></div>
            </section>
            <section class="rounded-2xl border border-emerald-500/40 bg-slate-900/80 p-3">
                <h2 class="text-base font-bold text-emerald-200 mb-2 flex items-center gap-2"><span class="w-2.5 h-2.5 rounded-full bg-emerald-400"></span> READY</h2>
                <div class="text-xs text-slate-400 mb-2">После «Завершить» заказ исчезает с доски</div>
                <div id="col-ready" class="space-y-3"></div>
            </section>
        </div>
    </main>
</div>

<script>
(function () {
    const isDemo = <?= $demo ? 'true' : 'false' ?>;
    const colNew = document.getElementById('col-new');
    const colProgress = document.getElementById('col-progress');
    const colReady = document.getElementById('col-ready');
    const stationFilterEl = document.getElementById('station-filter');
    const emptyHtml = '<div class="rounded-xl border border-slate-800 bg-slate-950/70 p-3 text-center text-slate-500 text-sm">Нет заказов</div>';

    let lastSeen = new Set();
    const ding = () => {
        try {
            const ctx = new (window.AudioContext || window.webkitAudioContext)();
            const o = ctx.createOscillator();
            const g = ctx.createGain();
            o.frequency.value = 880;
            o.connect(g);
            g.connect(ctx.destination);
            g.gain.value = 0.03;
            o.start();
            setTimeout(() => { o.stop(); ctx.close(); }, 120);
        } catch (_) {}
    };

    function statusLabel(st) {
        const map = {
            new: 'new',
            accepted: 'accepted',
            cooking: 'cooking',
            ready: 'ready',
            completed: 'completed',
            delivered: 'completed',
        };
        return map[st] || st;
    }

    function renderButtons(order) {
        if (isDemo) return '';
        const st = String(order.status || '');
        const btn = (to, text, cls) => `<button data-order-id="${order.id}" data-status="${to}" class="${cls} px-3 py-2 rounded-xl text-sm font-semibold">${text}</button>`;
        if (st === 'new') {
            return btn('accepted', 'Принять', 'bg-sky-500 hover:bg-sky-400 text-slate-950');
        }
        if (st === 'accepted') {
            return btn('cooking', 'Готовится', 'bg-amber-500 hover:bg-amber-400 text-slate-950');
        }
        if (st === 'cooking') {
            return btn('ready', 'Готово', 'bg-emerald-500 hover:bg-emerald-400 text-slate-950');
        }
        if (st === 'ready') {
            // "completed" is mapped to DB "delivered" in API; once updated it disappears from KDS.
            return btn('completed', 'Завершить (исчезнет)', 'bg-violet-500 hover:bg-violet-400 text-white');
        }
        return '';
    }

    const stationOrder = ['HOT', 'COLD', 'BAR', 'DESSERT'];
    function groupItemsByStation(order) {
        const itemsByStation = order.items_by_station;
        if (itemsByStation && typeof itemsByStation === 'object') return itemsByStation;
        const by = {};
        (order.items || []).forEach((it) => {
            const s = it.station || 'HOT';
            if (!by[s]) by[s] = [];
            by[s].push(it);
        });
        return by;
    }

    function cardHtml(order) {
        const st = String(order.status || '');
        const border = st === 'ready' ? 'border-emerald-500/50' : (st === 'new' ? 'border-red-500/50' : 'border-amber-500/40');

        const grouped = groupItemsByStation(order);
        const stations = stationOrder.filter(s => grouped[s] && grouped[s].length).concat(Object.keys(grouped).filter(s => stationOrder.indexOf(s) === -1));
        let itemsHtml = '';
        stations.forEach((s) => {
            itemsHtml += '<div class="mt-2"><span class="text-xs text-slate-400 uppercase">' + s + '</span>' +
                '<ul class="text-slate-200 text-sm space-y-0.5 mt-1">';
            (grouped[s] || []).forEach((i) => {
                itemsHtml += '<li class="flex justify-between gap-3">' +
                    '<span class="text-slate-100">' + (i.menu_name || 'Позиция') + '</span>' +
                    '<span class="text-slate-300 font-semibold">×' + Number(i.quantity || 1) + '</span>' +
                    '</li>';
            });
            itemsHtml += '</ul></div>';
        });
        return `
            <article class="rounded-2xl border ${border} bg-slate-800/90 p-3 md:p-4">
                <div class="flex items-start justify-between gap-2">
                    <div class="text-lg md:text-xl font-bold">#${order.id}</div>
                    <div class="text-xs px-2 py-1 rounded-full border border-slate-600 text-slate-200">${st}</div>
                </div>
                <div class="text-sm md:text-base text-slate-100 mt-1">Стол: <span class="font-semibold">${order.table_name || '—'}</span></div>
                <div class="text-xs text-slate-400 mt-1">${Number(order.since_minutes || 0)} мин</div>
                ${itemsHtml || '<div class="mt-2 text-slate-500 text-sm">—</div>'}
                ${order.note ? `<div class="mt-2 text-xs text-amber-200 border border-amber-500/30 bg-amber-500/10 rounded-lg px-2 py-1">${order.note}</div>` : ''}
                <div class="mt-3 flex flex-wrap gap-2">${renderButtons(order)}</div>
            </article>
        `;
    }

    function splitOrders(all) {
        const mapped = (all || []).map(o => ({ ...o, status: statusLabel(o.status) }));
        return {
            new: mapped.filter(o => o.status === 'new'),
            progress: mapped.filter(o => o.status === 'accepted' || o.status === 'cooking'),
            ready: mapped.filter(o => o.status === 'ready'),
        };
    }

    function render(all) {
        const grouped = splitOrders(all);
        colNew.innerHTML = grouped.new.length ? grouped.new.map(cardHtml).join('') : emptyHtml;
        colProgress.innerHTML = grouped.progress.length ? grouped.progress.map(cardHtml).join('') : emptyHtml;
        colReady.innerHTML = grouped.ready.length ? grouped.ready.map(cardHtml).join('') : emptyHtml;
    }

    async function fetchOrders() {
        try {
            const url = '/ajax/kds_orders.php' + (stationFilterEl && stationFilterEl.value ? ('?station=' + encodeURIComponent(stationFilterEl.value)) : '');
            const r = await fetch(url, { credentials: 'same-origin' });
            const data = await r.json();
            if (!data.success) return;
            const orders = data.orders || [];
            const newIds = orders.filter(o => statusLabel(o.status) === 'new').map(o => Number(o.id));
            const hasBrandNew = newIds.some(id => !lastSeen.has(id));
            newIds.forEach(id => lastSeen.add(id));
            if (hasBrandNew) ding();
            render(orders);
        } catch (_) {}
    }

    async function updateStatus(orderId, status) {
        try {
            const r = await fetch('/ajax/kds_update_status.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({ order_id: orderId, status }),
            });
            const data = await r.json();
            if (data.success) {
                if (!inFlight && !document.hidden) fetchOrders();
            } else if (data.message) {
                alert(data.message);
            }
        } catch (_) {}
    }

    document.body.addEventListener('click', (e) => {
        const t = e.target;
        if (!(t instanceof HTMLElement)) return;
        const btn = t.closest('button[data-order-id][data-status]');
        if (!btn) return;
        const orderId = Number(btn.getAttribute('data-order-id') || '0');
        const status = String(btn.getAttribute('data-status') || '');
        if (orderId > 0 && status) {
            updateStatus(orderId, status);
        }
    });

    if (stationFilterEl) {
        stationFilterEl.addEventListener('change', () => {
            // When station filter changes, treat the first poll in that view as "new" for sound.
            lastSeen = new Set();
            if (!inFlight && !document.hidden) fetchOrders();
        });
    }

    // Polling: avoid overlapping requests + pause when tab is hidden.
    let inFlight = false;
    const intervalMs = 4000;
    async function tick() {
        if (!document.hidden) {
            if (!inFlight) {
                inFlight = true;
                try { await fetchOrders(); } finally { inFlight = false; }
            }
        }
        setTimeout(tick, intervalMs);
    }
    tick();
})();
</script>
</body>
</html>

