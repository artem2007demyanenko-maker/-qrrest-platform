<?php
/**
 * Kitchen Display System (KDS): view and manage orders by column (NEW, COOKING, READY, PICKED UP).
 * Station filter: BAR, HOT, COLD, DESSERT. Polling every 5s. Demo: updates disabled.
 */

require_once __DIR__ . '/../../app/bootstrap.php';

require_login();
require_current_restaurant();
require_restaurant_role((int)($currentRestaurant['id'] ?? 0), ['owner', 'admin', 'staff']);

$demo = function_exists('is_demo_mode') && is_demo_mode();
$restName = $currentRestaurant['name'] ?? 'Restaurant';

if (!function_exists('e')) {
    function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

$ordersForColumns = ['new' => [], 'cooking' => [], 'ready' => [], 'picked_up' => []];
if ($demo && function_exists('demo_kitchen_orders')) {
    $raw = demo_kitchen_orders();
    $now = time();
    foreach ($raw as $o) {
        $st = $o['order_status'] ?? 'new';
        $createdTs = isset($o['created_at']) ? strtotime($o['created_at']) : null;
        $sinceMinutes = $createdTs ? max(0, (int)floor(($now - $createdTs) / 60)) : 0;
        $o['since_minutes'] = $sinceMinutes;
        $o['items_by_station'] = [];
        foreach ($o['items'] ?? [] as $it) {
            $station = $it['station'] ?? 'HOT';
            if (!isset($o['items_by_station'][$station])) $o['items_by_station'][$station] = [];
            $o['items_by_station'][$station][] = $it;
        }
        if ($st === 'new') $ordersForColumns['new'][] = $o;
        elseif ($st === 'delivered') $ordersForColumns['picked_up'][] = $o;
        elseif ($st === 'ready') $ordersForColumns['ready'][] = $o;
        else $ordersForColumns['cooking'][] = $o;
    }
}
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Kitchen — <?= e($restName) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?= brand_head_tags() ?>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-950 text-slate-50">
<div class="min-h-screen flex flex-col">
    <header class="flex-shrink-0 border-b border-slate-800 bg-slate-900/90 px-4 py-3 flex flex-wrap items-center justify-between gap-2">
        <h1 class="text-xl md:text-2xl font-bold text-slate-100">Kitchen Display · <?= e($restName) ?></h1>
        <div class="flex items-center gap-3">
            <label class="text-sm text-slate-400">Station:</label>
            <select id="station-filter" class="rounded-lg bg-slate-800 border border-slate-600 px-3 py-1.5 text-sm text-slate-100">
                <option value="">All</option>
                <option value="HOT">HOT</option>
                <option value="COLD">COLD</option>
                <option value="BAR">BAR</option>
                <option value="DESSERT">DESSERT</option>
            </select>
            <span class="text-sm text-slate-500">Refresh 5s</span>
        </div>
    </header>
    <main class="flex-1 overflow-auto p-4 md:p-6">
        <div id="kitchen-columns" class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div id="col-new" class="rounded-2xl border border-amber-500/40 bg-slate-900/80 p-4">
                <h2 class="text-lg font-bold text-amber-200 mb-3 flex items-center gap-2">
                    <span class="w-3 h-3 rounded-full bg-amber-400 animate-pulse"></span> NEW
                </h2>
            <div class="space-y-3" id="cards-new"><?php
                if ($demo) {
                    foreach ($ordersForColumns['new'] as $o) {
                        echo '<div class="rounded-xl bg-slate-800/90 border border-slate-700 p-4">';
                        echo '<div class="text-xl font-bold text-slate-100">Order #' . (int)$o['id'] . '</div>';
                        echo '<div class="text-slate-300">' . e($o['table_name'] ?? '—') . '</div>';
                        echo '<div class="text-slate-400 text-sm">' . (int)($o['since_minutes'] ?? 0) . ' min ago</div>';
                        foreach ($o['items_by_station'] ?? [] as $station => $items) {
                            echo '<div class="mt-2"><span class="text-xs text-slate-400 uppercase">' . e($station) . '</span><ul class="text-slate-200 text-sm">';
                            foreach ($items as $it) {
                                echo '<li>' . e($it['menu_name'] ?? '') . ((int)($it['quantity'] ?? 1) > 1 ? ' ×' . (int)$it['quantity'] : '') . '</li>';
                            }
                            echo '</ul></div>';
                        }
                        echo '</div>';
                    }
                    if (empty($ordersForColumns['new'])) echo '<div class="text-slate-500 py-4 text-center">—</div>';
                }
            ?></div>
            </div>
            <div id="col-cooking" class="rounded-2xl border border-sky-500/40 bg-slate-900/80 p-4">
                <h2 class="text-lg font-bold text-sky-200 mb-3 flex items-center gap-2">
                    <span class="w-3 h-3 rounded-full bg-sky-400"></span> COOKING
                </h2>
                <div class="space-y-3" id="cards-cooking"><?php
                if ($demo) {
                    foreach ($ordersForColumns['cooking'] as $o) {
                        echo '<div class="rounded-xl bg-slate-800/90 border border-slate-700 p-4">';
                        echo '<div class="text-xl font-bold text-slate-100">Order #' . (int)$o['id'] . '</div>';
                        echo '<div class="text-slate-300">' . e($o['table_name'] ?? '—') . '</div>';
                        echo '<div class="text-slate-400 text-sm">' . (int)($o['since_minutes'] ?? 0) . ' min ago</div>';
                        foreach ($o['items_by_station'] ?? [] as $station => $items) {
                            echo '<div class="mt-2"><span class="text-xs text-slate-400 uppercase">' . e($station) . '</span><ul class="text-slate-200 text-sm">';
                            foreach ($items as $it) {
                                echo '<li>' . e($it['menu_name'] ?? '') . ((int)($it['quantity'] ?? 1) > 1 ? ' ×' . (int)$it['quantity'] : '') . '</li>';
                            }
                            echo '</ul></div>';
                        }
                        echo '</div>';
                    }
                    if (empty($ordersForColumns['cooking'])) echo '<div class="text-slate-500 py-4 text-center">—</div>';
                }
            ?></div>
            </div>
            <div id="col-ready" class="rounded-2xl border border-emerald-500/40 bg-slate-900/80 p-4">
                <h2 class="text-lg font-bold text-emerald-200 mb-3 flex items-center gap-2">
                    <span class="w-3 h-3 rounded-full bg-emerald-400 animate-pulse"></span> READY
                </h2>
                <div class="space-y-3" id="cards-ready"><?php
                if ($demo) {
                    foreach ($ordersForColumns['ready'] as $o) {
                        echo '<div class="rounded-xl bg-slate-800/90 border border-emerald-700/50 p-4">';
                        echo '<div class="text-xl font-bold text-slate-100">Order #' . (int)$o['id'] . '</div>';
                        echo '<div class="text-slate-300">' . e($o['table_name'] ?? '—') . '</div>';
                        echo '<div class="text-slate-400 text-sm">' . (int)($o['since_minutes'] ?? 0) . ' min ago</div>';
                        foreach ($o['items_by_station'] ?? [] as $station => $items) {
                            echo '<div class="mt-2"><span class="text-xs text-slate-400 uppercase">' . e($station) . '</span><ul class="text-slate-200 text-sm">';
                            foreach ($items as $it) {
                                echo '<li>' . e($it['menu_name'] ?? '') . ((int)($it['quantity'] ?? 1) > 1 ? ' ×' . (int)$it['quantity'] : '') . '</li>';
                            }
                            echo '</ul></div>';
                        }
                        echo '</div>';
                    }
                    if (empty($ordersForColumns['ready'])) echo '<div class="text-slate-500 py-4 text-center">—</div>';
                }
            ?></div>
            </div>
            <div id="col-picked_up" class="rounded-2xl border border-slate-500/40 bg-slate-900/80 p-4">
                <h2 class="text-lg font-bold text-slate-300 mb-3 flex items-center gap-2">
                    <span class="w-3 h-3 rounded-full bg-slate-400"></span> PICKED UP
                </h2>
                <div class="space-y-3" id="cards-picked_up"><?php
                if ($demo) {
                    foreach ($ordersForColumns['picked_up'] as $o) {
                        echo '<div class="rounded-xl bg-slate-800/90 border border-slate-600 p-4">';
                        echo '<div class="text-xl font-bold text-slate-100">Order #' . (int)$o['id'] . '</div>';
                        echo '<div class="text-slate-300">' . e($o['table_name'] ?? '—') . '</div>';
                        echo '<div class="text-slate-400 text-sm">' . (int)($o['since_minutes'] ?? 0) . ' min ago</div>';
                        foreach ($o['items_by_station'] ?? [] as $station => $items) {
                            echo '<div class="mt-2"><span class="text-xs text-slate-400 uppercase">' . e($station) . '</span><ul class="text-slate-200 text-sm">';
                            foreach ($items as $it) {
                                echo '<li>' . e($it['menu_name'] ?? '') . ((int)($it['quantity'] ?? 1) > 1 ? ' ×' . (int)$it['quantity'] : '') . '</li>';
                            }
                            echo '</ul></div>';
                        }
                        echo '</div>';
                    }
                    if (empty($ordersForColumns['picked_up'])) echo '<div class="text-slate-500 py-4 text-center">—</div>';
                }
            ?></div>
            </div>
        </div>
        <?php if ($demo): ?>
        <p class="text-slate-500 text-sm mt-4">Demo: status updates disabled.</p>
        <?php endif; ?>
    </main>
</div>
<?php if (!$demo): ?>
<script>
(function () {
    const csrf = <?= json_encode($_SESSION['csrf'] ?? '') ?>;
    const cardsNew = document.getElementById('cards-new');
    const cardsCooking = document.getElementById('cards-cooking');
    const cardsReady = document.getElementById('cards-ready');
    const cardsPickedUp = document.getElementById('cards-picked_up');
    const stationFilter = document.getElementById('station-filter');
    const empty = '<div class="text-slate-500 py-4 text-center">—</div>';

    function itemsByStation(items) {
        const byStation = {};
        (items || []).forEach(function (it) {
            const s = it.station || 'HOT';
            if (!byStation[s]) byStation[s] = [];
            byStation[s].push(it);
        });
        const order = ['HOT', 'COLD', 'BAR', 'DESSERT'];
        const out = [];
        order.forEach(function (s) {
            if (byStation[s]) out.push({ station: s, items: byStation[s] });
        });
        return out;
    }

    function cardHtml(o) {
        const since = typeof o.since_minutes === 'number' ? o.since_minutes + ' min ago' : (o.created_at_short || '');
        const groups = itemsByStation(o.items);
        let body = '';
        groups.forEach(function (g) {
            body += '<div class="mt-2"><span class="text-xs text-slate-400 uppercase">' + g.station + '</span><ul class="text-slate-200 text-sm space-y-0.5">';
            g.items.forEach(function (it) {
                body += '<li>' + (it.menu_name || '') + (it.quantity > 1 ? ' ×' + it.quantity : '') + '</li>';
            });
            body += '</ul></div>';
        });
        if (o.note) body += '<div class="mt-2 text-xs text-amber-200">Note: ' + o.note + '</div>';

        const status = o.order_status;
        let buttons = '';
        if (status === 'new') {
            buttons = '<button type="button" class="kds-btn mt-2 px-3 py-1.5 rounded-lg bg-sky-600 hover:bg-sky-500 text-white text-sm" data-order-id="' + o.id + '" data-next="cooking">→ COOKING</button>';
        } else if (status === 'accepted' || status === 'cooking') {
            buttons = '<button type="button" class="kds-btn mt-2 px-3 py-1.5 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white text-sm" data-order-id="' + o.id + '" data-next="ready">→ READY</button>';
        } else if (status === 'ready') {
            buttons = '<button type="button" class="kds-btn mt-2 px-3 py-1.5 rounded-lg bg-slate-600 hover:bg-slate-500 text-white text-sm" data-order-id="' + o.id + '" data-next="delivered">→ PICKED UP</button>';
        }

        const borderClass = status === 'ready' ? 'border-emerald-700/50' : (status === 'delivered' ? 'border-slate-600' : 'border-slate-700');
        return '<div class="rounded-xl bg-slate-800/90 border ' + borderClass + ' p-4" data-order-id="' + o.id + '">' +
            '<div class="text-xl font-bold text-slate-100">Order #' + o.id + '</div>' +
            '<div class="text-slate-300">' + (o.table_name || '—') + '</div>' +
            '<div class="text-slate-400 text-sm">' + since + '</div>' +
            body + buttons + '</div>';
    }

    function render(orders) {
        const newOrders = orders.filter(function (o) { return o.order_status === 'new'; });
        const cookingOrders = orders.filter(function (o) { return o.order_status === 'accepted' || o.order_status === 'cooking'; });
        const readyOrders = orders.filter(function (o) { return o.order_status === 'ready'; });
        const pickedUpOrders = orders.filter(function (o) { return o.order_status === 'delivered'; });

        cardsNew.innerHTML = newOrders.length ? newOrders.map(cardHtml).join('') : empty;
        cardsCooking.innerHTML = cookingOrders.length ? cookingOrders.map(cardHtml).join('') : empty;
        cardsReady.innerHTML = readyOrders.length ? readyOrders.map(cardHtml).join('') : empty;
        cardsPickedUp.innerHTML = pickedUpOrders.length ? pickedUpOrders.map(cardHtml).join('') : empty;

        document.querySelectorAll('.kds-btn').forEach(function (btn) {
            btn.onclick = function () {
                var orderId = this.getAttribute('data-order-id');
                var next = this.getAttribute('data-next');
                if (!orderId || !next) return;
                var form = new FormData();
                form.append('csrf', csrf);
                form.append('order_id', orderId);
                form.append('order_status', next);
                fetch('/staff/order_update_status.php', { method: 'POST', body: form })
                    .then(function (r) { return r.json(); })
                    .then(function (data) { if (data.success) fetchOrders(); });
            };
        });
    }

    function fetchOrders() {
        var url = '/restaurant/kitchen_api.php';
        if (stationFilter && stationFilter.value) url += '?station=' + encodeURIComponent(stationFilter.value);
        fetch(url)
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.success && data.orders) render(data.orders);
            })
            .catch(function () {});
    }

    if (stationFilter) stationFilter.addEventListener('change', fetchOrders);
    fetchOrders();
    setInterval(fetchOrders, 5000);
})();
</script>
<?php endif; ?>
</body>
</html>
