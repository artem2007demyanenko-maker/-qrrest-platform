<?php
require_once __DIR__ . '/../../app/bootstrap.php';
require_staff_restaurant_access();

if (!function_exists('e')) {
    function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Панель сотрудников — <?= e($currentRestaurant['name']) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-950 text-slate-50">
<main class="max-w-4xl mx-auto p-4 md:p-6 space-y-4">
    <header>
        <div class="text-xs text-slate-400">STAFF</div>
        <h1 class="text-2xl font-semibold">Панель сотрудников</h1>
        <p class="text-sm text-slate-400 mt-1"><?= e($currentRestaurant['name']) ?></p>
    </header>

    <section class="grid gap-3 sm:grid-cols-2">
        <a href="/staff/kitchen.php" class="rounded-2xl border border-emerald-500/40 bg-emerald-500/10 px-4 py-4 hover:bg-emerald-500/15">
            <div class="text-base font-semibold text-emerald-100">Кухня (KDS)</div>
            <div class="text-xs text-emerald-200/80 mt-1">Реальное время: новые, в работе, готово</div>
        </a>
        <a href="/staff/orders.php" class="rounded-2xl border border-slate-700 bg-slate-900/70 px-4 py-4 hover:bg-slate-800/70">
            <div class="text-base font-semibold">Заказы</div>
            <div class="text-xs text-slate-400 mt-1">Список заказов и статусы</div>
        </a>
        <a href="/staff/pos.php" class="rounded-2xl border border-slate-700 bg-slate-900/70 px-4 py-4 hover:bg-slate-800/70">
            <div class="text-base font-semibold">POS</div>
            <div class="text-xs text-slate-400 mt-1">Создание заказа сотрудником</div>
        </a>
        <a href="/staff/floorplan.php" class="rounded-2xl border border-slate-700 bg-slate-900/70 px-4 py-4 hover:bg-slate-800/70">
            <div class="text-base font-semibold">Карта зала</div>
            <div class="text-xs text-slate-400 mt-1">Статусы столов и навигация</div>
        </a>
        <a href="/staff/courier.php" class="rounded-2xl border border-violet-500/40 bg-violet-500/10 px-4 py-4 hover:bg-violet-500/15">
            <div class="text-base font-semibold text-violet-100">Курьер</div>
            <div class="text-xs text-violet-200/80 mt-1">Delivery-операции: ожидание, в пути, доставлено</div>
        </a>
        <a href="/staff/system_health.php" class="rounded-2xl border border-amber-500/40 bg-amber-500/10 px-4 py-4 hover:bg-amber-500/15">
            <div class="text-base font-semibold text-amber-100">System health</div>
            <div class="text-xs text-amber-200/80 mt-1">Operational QA: runtime/schema/fallback readiness</div>
        </a>
    </section>

    <section class="rounded-2xl border border-cyan-500/30 bg-cyan-500/10 p-4 space-y-3" id="staff-analytics-foundation">
        <div class="flex flex-wrap items-end justify-between gap-2">
            <div>
                <h2 class="text-sm font-semibold text-cyan-100 uppercase tracking-wide">Analytics foundation</h2>
                <p class="text-xs text-cyan-200/75 mt-1">Оперативная сводка смены: заказы, выручка, доставка, SLA.</p>
            </div>
            <div class="flex gap-1.5">
                <button type="button" data-range="today" class="staff-analytics-range px-2 py-1 rounded-lg border border-cyan-400/40 bg-cyan-500/10 text-xs text-cyan-100">Сегодня</button>
                <button type="button" data-range="week" class="staff-analytics-range px-2 py-1 rounded-lg border border-slate-600 bg-slate-900/50 text-xs text-slate-200">7 дней</button>
            </div>
        </div>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-2 text-xs">
            <div class="rounded-lg border border-slate-700 bg-slate-900/60 px-2 py-2"><div class="text-slate-400">Заказы</div><div id="sa-orders" class="text-slate-100 font-semibold mt-1">—</div></div>
            <div class="rounded-lg border border-slate-700 bg-slate-900/60 px-2 py-2"><div class="text-slate-400">Выручка</div><div id="sa-revenue" class="text-emerald-300 font-semibold mt-1">—</div></div>
            <div class="rounded-lg border border-slate-700 bg-slate-900/60 px-2 py-2"><div class="text-slate-400">Delivery</div><div id="sa-delivery" class="text-sky-300 font-semibold mt-1">—</div></div>
            <div class="rounded-lg border border-slate-700 bg-slate-900/60 px-2 py-2"><div class="text-slate-400">SLA</div><div id="sa-sla" class="text-amber-300 font-semibold mt-1">—</div></div>
        </div>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-2 text-xs">
            <div class="rounded-lg border border-slate-700 bg-slate-900/60 px-2 py-2"><div class="text-slate-400">Hotspots</div><div id="sa-hotspots" class="text-rose-300 font-semibold mt-1">—</div></div>
            <div class="rounded-lg border border-slate-700 bg-slate-900/60 px-2 py-2"><div class="text-slate-400">SLA success</div><div id="sa-sla-success" class="text-emerald-300 font-semibold mt-1">—</div></div>
            <div class="rounded-lg border border-slate-700 bg-slate-900/60 px-2 py-2"><div class="text-slate-400">Delivery margin</div><div id="sa-margin" class="text-violet-300 font-semibold mt-1">—</div></div>
            <div class="rounded-lg border border-slate-700 bg-slate-900/60 px-2 py-2"><div class="text-slate-400">Top zone</div><div id="sa-zone" class="text-cyan-300 font-semibold mt-1">—</div></div>
        </div>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-2 text-xs">
            <div class="rounded-lg border border-slate-700 bg-slate-900/60 px-2 py-2"><div class="text-slate-400">Forecast orders</div><div id="sa-fc-orders" class="text-slate-100 font-semibold mt-1">—</div></div>
            <div class="rounded-lg border border-slate-700 bg-slate-900/60 px-2 py-2"><div class="text-slate-400">Forecast delivery</div><div id="sa-fc-delivery" class="text-sky-300 font-semibold mt-1">—</div></div>
            <div class="rounded-lg border border-slate-700 bg-slate-900/60 px-2 py-2"><div class="text-slate-400">Kitchen pressure</div><div id="sa-fc-kitchen" class="text-amber-300 font-semibold mt-1">—</div></div>
            <div class="rounded-lg border border-slate-700 bg-slate-900/60 px-2 py-2"><div class="text-slate-400">Staffing gap</div><div id="sa-fc-staff" class="text-rose-300 font-semibold mt-1">—</div></div>
        </div>
        <div class="flex flex-wrap items-center justify-between gap-2 text-[11px] text-slate-400">
            <div id="sa-period">Период: —</div>
            <div id="sa-delta">Δ выручки: —</div>
        </div>
        <div id="sa-alerts" class="space-y-1 text-[11px]"></div>
    </section>
</main>
<script>
(function staffAnalyticsFoundation() {
    var block = document.getElementById('staff-analytics-foundation');
    if (!block) return;

    var nodes = {
        orders: document.getElementById('sa-orders'),
        revenue: document.getElementById('sa-revenue'),
        delivery: document.getElementById('sa-delivery'),
        sla: document.getElementById('sa-sla'),
        hotspots: document.getElementById('sa-hotspots'),
        slaSuccess: document.getElementById('sa-sla-success'),
        margin: document.getElementById('sa-margin'),
        zone: document.getElementById('sa-zone'),
        fcOrders: document.getElementById('sa-fc-orders'),
        fcDelivery: document.getElementById('sa-fc-delivery'),
        fcKitchen: document.getElementById('sa-fc-kitchen'),
        fcStaff: document.getElementById('sa-fc-staff'),
        period: document.getElementById('sa-period'),
        delta: document.getElementById('sa-delta'),
        alerts: document.getElementById('sa-alerts')
    };
    var buttons = Array.prototype.slice.call(document.querySelectorAll('.staff-analytics-range'));
    var activeRange = 'today';

    function money(value) {
        var num = Number(value || 0);
        if (!Number.isFinite(num)) return '0 ₽';
        return num.toLocaleString('ru-RU', {maximumFractionDigits: 0}) + ' ₽';
    }

    function setRangeUi(range) {
        buttons.forEach(function(btn) {
            if (!btn || !btn.dataset) return;
            var active = btn.dataset.range === range;
            btn.classList.toggle('border-cyan-400/40', active);
            btn.classList.toggle('bg-cyan-500/10', active);
            btn.classList.toggle('text-cyan-100', active);
            btn.classList.toggle('border-slate-600', !active);
            btn.classList.toggle('bg-slate-900/50', !active);
            btn.classList.toggle('text-slate-200', !active);
        });
    }

    function renderAlerts(items) {
        if (!nodes.alerts) return;
        if (!Array.isArray(items) || items.length === 0) {
            nodes.alerts.innerHTML = '<div class="text-emerald-300">Критичных alert-сигналов нет.</div>';
            return;
        }
        nodes.alerts.innerHTML = items.slice(0, 4).map(function(item) {
            var level = String((item && item.level) || 'warning');
            var tone = level === 'critical'
                ? 'border-red-500/50 bg-red-500/10 text-red-200'
                : 'border-amber-500/50 bg-amber-500/10 text-amber-200';
            var label = String((item && item.label) || 'Alert');
            var message = String((item && item.message) || '');
            return '<div class="rounded-lg border px-2 py-1 ' + tone + '"><span class="font-semibold">' + label + ':</span> ' + message + '</div>';
        }).join('');
    }

    function load(range) {
        activeRange = range || activeRange;
        setRangeUi(activeRange);
        fetch('/ajax/analytics_summary.php?range=' + encodeURIComponent(activeRange), {credentials: 'same-origin'})
            .then(function(resp) { return resp.json(); })
            .then(function(data) {
                if (!data || !data.success) {
                    throw new Error((data && data.message) ? data.message : 'Analytics unavailable');
                }
                var orders = data.orders || {};
                var revenue = data.revenue || {};
                var delivery = data.delivery || {};
                var deliveryOps = data.delivery_ops || {};
                var deliverySla = deliveryOps.sla || {};
                var deliveryProfit = deliveryOps.profitability || {};
                var deliveryHotspots = deliveryOps.hotspots || {};
                var zoneRows = (deliveryOps.zone && Array.isArray(deliveryOps.zone.rows)) ? deliveryOps.zone.rows : [];
                var forecasting = data.forecasting || {};
                var nextShiftForecast = forecasting.next_shift || {};
                var nextShiftOrders = nextShiftForecast.orders || {};
                var nextShiftDelivery = nextShiftForecast.delivery_load || {};
                var nextShiftKitchen = nextShiftForecast.kitchen_workload || {};
                var nextShiftStaffing = nextShiftForecast.staffing_need || {};
                var trends = data.trends || {};
                var period = data.period || {};

                if (nodes.orders) nodes.orders.textContent = String(Number(orders.orders_count || 0));
                if (nodes.revenue) nodes.revenue.textContent = money(revenue.revenue || 0);
                if (nodes.delivery) {
                    nodes.delivery.textContent = String(Number(delivery.delivery_orders_count || 0)) + ' · ' + money(delivery.delivery_revenue || 0);
                }
                if (nodes.sla) {
                    nodes.sla.textContent = 'Просрочено ' + String(Number(delivery.overdue_delivery_count || 0));
                }
                if (nodes.hotspots) {
                    var hsCount = Array.isArray(deliveryHotspots.overloaded_districts) ? deliveryHotspots.overloaded_districts.length : 0;
                    nodes.hotspots.textContent = hsCount > 0 ? String(hsCount) + ' зон риска' : 'Нет';
                }
                if (nodes.slaSuccess) {
                    nodes.slaSuccess.textContent = String(Number(deliverySla.sla_success_percent || 0).toFixed(1)) + '%';
                }
                if (nodes.margin) {
                    nodes.margin.textContent = money(deliveryProfit.estimated_margin || 0);
                }
                if (nodes.zone) {
                    if (zoneRows.length > 0) {
                        var topZone = zoneRows[0] || {};
                        nodes.zone.textContent = String(topZone.zone_label || topZone.zone_key || '—') + ' · ' + String(Number(topZone.orders_count || 0));
                    } else {
                        nodes.zone.textContent = '—';
                    }
                }
                if (nodes.fcOrders) {
                    nodes.fcOrders.textContent = String(Number(nextShiftOrders.expected_orders || 0)) + ' · avg ' + money(nextShiftOrders.expected_avg_check || 0);
                }
                if (nodes.fcDelivery) {
                    var fcRisk = String(nextShiftDelivery.predicted_sla_risk || 'low');
                    nodes.fcDelivery.textContent = String(Number(nextShiftDelivery.expected_delivery_orders || 0)) + ' · risk ' + fcRisk;
                }
                if (nodes.fcKitchen) {
                    var peakStation = nextShiftKitchen.peak_station || {};
                    nodes.fcKitchen.textContent = String(peakStation.station_key || 'kitchen') + ' · ' + String(Number(peakStation.predicted_pressure || 0)) + '%';
                }
                if (nodes.fcStaff) {
                    var gap = Number(nextShiftStaffing.courier_gap || 0) + Number(nextShiftStaffing.kitchen_gap || 0) + Number(nextShiftStaffing.waiter_gap || 0);
                    nodes.fcStaff.textContent = (gap > 0 ? '+' : '') + String(gap);
                }
                if (nodes.period) {
                    nodes.period.textContent = 'Период: ' + String(period.label || '—');
                }
                var revTrend = trends.revenue || null;
                if (nodes.delta && revTrend) {
                    var pct = Number(revTrend.delta_percent || 0);
                    var sign = pct > 0 ? '+' : '';
                    nodes.delta.textContent = 'Δ выручки: ' + sign + pct.toFixed(1) + '%';
                    nodes.delta.className = 'text-[11px] ' + (pct < 0 ? 'text-red-300' : (pct > 0 ? 'text-emerald-300' : 'text-slate-400'));
                }

                renderAlerts(data.alerts || []);
            })
            .catch(function() {
                if (nodes.alerts) {
                    nodes.alerts.innerHTML = '<div class="text-red-300">Не удалось загрузить analytics summary.</div>';
                }
            });
    }

    buttons.forEach(function(btn) {
        btn.addEventListener('click', function() {
            var range = (btn.dataset && btn.dataset.range) ? btn.dataset.range : 'today';
            load(range);
        });
    });

    load(activeRange);
})();
</script>
</body>
</html>
