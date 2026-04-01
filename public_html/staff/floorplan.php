<?php

require_once __DIR__ . '/../../app/bootstrap.php';

require_login();

if (!$currentRestaurant) {
    http_response_code(404);
    echo "Restaurant context required";
    exit;
}


require_restaurant_role((int)$currentRestaurant['id'], ['owner','admin','staff']);

$pdo = db();
$restId = (int)$currentRestaurant['id'];

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = (string)($_SESSION['csrf'] ?? '');

if (!function_exists('e')) {
    function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

function fp_default_layout(): array {
    return [
        'version' => 1,
        'canvas'  => ['w' => 1200, 'h' => 700, 'grid' => 20],
        'items'   => [],
    ];
}
function fp_decode_layout(?string $json): array {
    if (!$json) return fp_default_layout();
    $d = json_decode($json, true);
    if (!is_array($d)) return fp_default_layout();
    if (!isset($d['canvas']) || !is_array($d['canvas'])) $d['canvas'] = fp_default_layout()['canvas'];
    if (!isset($d['items']) || !is_array($d['items'])) $d['items'] = [];
    $d['canvas']['w'] = max(600, (int)($d['canvas']['w'] ?? 1200));
    $d['canvas']['h'] = max(400, (int)($d['canvas']['h'] ?? 700));
    $d['canvas']['grid'] = max(10, min(50, (int)($d['canvas']['grid'] ?? 20)));
    return $d;
}

function get_active_by_table(PDO $pdo, int $restId): array {
    $activeByTable = [];
    $q = $pdo->prepare("
        SELECT table_id,
               SUM(CASE WHEN order_status IN ('new','in_progress') THEN 1 ELSE 0 END) AS active_cnt,
               MAX(CASE WHEN order_status IN ('new','in_progress') THEN order_status ELSE NULL END) AS active_status
        FROM orders
        WHERE restaurant_id = :r
        GROUP BY table_id
    ");
    $q->execute([':r' => $restId]);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $tid = (int)$row['table_id'];
        $activeByTable[$tid] = [
            'active_cnt' => (int)($row['active_cnt'] ?? 0),
            'active_status' => $row['active_status'] ?? null,
        ];
    }
    return $activeByTable;
}

if (isset($_GET['status']) && $_GET['status'] === '1') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $data = get_active_by_table($pdo, $restId);
        echo json_encode(['success' => true, 'by_table' => $data], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        error_log('STAFF_FLOORPLAN_STATUS_ERROR rest_id=' . $restId . ' ' . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'db', 'message' => 'Ошибка при загрузке статуса столов'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM restaurant_floorplans WHERE restaurant_id=:r AND is_active=1 LIMIT 1");
$stmt->execute([':r' => $restId]);
$plan = $stmt->fetch(PDO::FETCH_ASSOC);

$layout = fp_decode_layout($plan['layout_json'] ?? null);

$stmt = $pdo->prepare("SELECT id, name FROM tables WHERE restaurant_id=:r ORDER BY id ASC");
$stmt->execute([':r' => $restId]);
$tables = $stmt->fetchAll(PDO::FETCH_ASSOC);
$tableNameById = [];
foreach ($tables as $t) $tableNameById[(int)$t['id']] = (string)$t['name'];


$activeByTable = [];
try {
    $activeByTable = get_active_by_table($pdo, $restId);
} catch (Throwable $e) { $activeByTable = []; }

?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Карта зала — <?= e($currentRestaurant['name']) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .glass{
            background: rgba(2,6,23,.74);
            backdrop-filter: blur(18px);
            border: 1px solid rgba(148,163,184,.16);
        }
        .grid-bg{
            background-image:
                linear-gradient(to right, rgba(148,163,184,.10) 1px, transparent 1px),
                linear-gradient(to bottom, rgba(148,163,184,.10) 1px, transparent 1px);
            background-size: var(--grid) var(--grid);
        }
        .soft-shadow{ box-shadow: 0 20px 80px rgba(0,0,0,.45); }
        .badge-dot{ width: 8px; height: 8px; border-radius: 999px; display:inline-block; }
        .table-node{ user-select:none; touch-action:none; }
        .no-tap-highlight{ -webkit-tap-highlight-color: transparent; }
    </style>
</head>
<body class="min-h-screen bg-gradient-to-br from-slate-950 via-slate-950 to-slate-900 text-slate-50">
<div class="min-h-screen flex flex-col">

    <!-- Top bar -->
    <header class="sticky top-0 z-40 px-4 pt-[env(safe-area-inset-top)]">
        <div class="mt-3 glass rounded-3xl soft-shadow px-4 py-3 flex items-center justify-between gap-3">
            <div class="min-w-0">
                <div class="text-[11px] text-slate-400">Сотрудники</div>
                <div class="text-base font-semibold truncate">Карта зала</div>
                <div class="text-[11px] text-slate-500 truncate"><?= e($currentRestaurant['name']) ?></div>
            </div>
            <a href="/staff/orders.php"
               class="shrink-0 px-3 py-2 rounded-2xl bg-emerald-500 hover:bg-emerald-400 text-slate-950 text-sm font-semibold">
                Заказы →
            </a>
        </div>

        <!-- Tabs (mobile) -->
        <div class="mt-3 lg:hidden glass rounded-3xl px-2 py-2 flex gap-2">
            <button id="tab-map" class="flex-1 px-3 py-2 rounded-2xl text-sm font-semibold bg-slate-900 border border-slate-800">
                Карта
            </button>
            <button id="tab-list" class="flex-1 px-3 py-2 rounded-2xl text-sm font-semibold bg-transparent border border-transparent text-slate-300">
                Список
            </button>
        </div>
    </header>

    <!-- Content -->
    <main class="flex-1 px-4 pb-[env(safe-area-inset-bottom)] pt-3">
        <div class="grid lg:grid-cols-12 gap-3">

            <!-- Left panel (desktop) -->
            <aside class="hidden lg:block lg:col-span-4 space-y-3">
                <div class="glass rounded-3xl soft-shadow p-4">
                    <div class="flex items-center justify-between mb-2">
                        <div class="text-sm font-semibold">Активный план</div>
                        <div class="text-[11px] text-slate-500">обновляется авто</div>
                    </div>
                    <div class="rounded-2xl border border-slate-800 bg-slate-950/60 px-3 py-3">
                        <div class="text-sm font-semibold"><?= e($plan['name'] ?? 'План не настроен') ?></div>
                        <div class="text-[11px] text-slate-500 mt-1">
                            <?php if ($plan): ?>ID: <?= (int)$plan['id'] ?><?php else: ?>
                                Попросите админа создать план в /restaurant/floorplan.php
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="mt-3 flex flex-wrap items-center gap-2 text-[11px] text-slate-400">
                        <span class="inline-flex items-center gap-2 px-2 py-1 rounded-full bg-slate-900 border border-slate-800">
                            <span class="badge-dot bg-amber-400"></span> новый
                        </span>
                        <span class="inline-flex items-center gap-2 px-2 py-1 rounded-full bg-slate-900 border border-slate-800">
                            <span class="badge-dot bg-sky-400"></span> готовится
                        </span>
                        <span class="inline-flex items-center gap-2 px-2 py-1 rounded-full bg-slate-900 border border-slate-800">
                            <span class="badge-dot bg-slate-500"></span> свободно
                        </span>
                    </div>
                </div>

                <div class="glass rounded-3xl soft-shadow p-4">
                    <div class="text-sm font-semibold mb-2">Список столов</div>
                    <div id="desktop-list" class="space-y-2 max-h-[640px] overflow-y-auto pr-1"></div>
                </div>
            </aside>

            <!-- Map panel -->
            <section id="panel-map" class="lg:col-span-8 space-y-3">
                <div class="glass rounded-3xl soft-shadow p-4 flex flex-wrap items-center justify-between gap-2">
                    <div class="text-sm font-semibold">
                        Зал
                        <span class="text-[11px] text-slate-500 font-normal ml-2">
                            (пан одним пальцем · пинч-зум двумя)
                        </span>
                    </div>

                    <div class="flex items-center gap-2">
                        <button id="btn-fit" type="button" class="px-3 py-2 rounded-2xl bg-slate-800 hover:bg-slate-700 text-sm">Вписать</button>
                        <button id="btn-zoom-in" type="button" class="px-3 py-2 rounded-2xl bg-slate-800 hover:bg-slate-700 text-sm">+</button>
                        <button id="btn-zoom-out" type="button" class="px-3 py-2 rounded-2xl bg-slate-800 hover:bg-slate-700 text-sm">−</button>
                    </div>
                </div>

                <div class="glass rounded-3xl soft-shadow p-3">
                    <!-- Full-height on mobile -->
                    <div class="rounded-3xl overflow-hidden border border-slate-800 bg-slate-950/40">
                        <div id="viewport" class="relative w-full overflow-hidden">
                            <div id="canvas"
                                 class="absolute left-0 top-0 grid-bg"
                                 style="width:<?= (int)$layout['canvas']['w'] ?>px;height:<?= (int)$layout['canvas']['h'] ?>px; --grid: <?= (int)$layout['canvas']['grid'] ?>px;">
                            </div>
                        </div>
                    </div>

                    <?php if (!$plan): ?>
                        <div class="mt-3 rounded-3xl bg-amber-500/10 border border-amber-500/50 px-4 py-3 text-sm text-amber-100">
                            План зала не настроен. Владельцу/админу: /restaurant/floorplan.php → создать план → сделать активным.
                        </div>
                    <?php endif; ?>

                    <div class="mt-3 lg:hidden flex flex-wrap items-center gap-2 text-[11px] text-slate-400">
                        <span class="inline-flex items-center gap-2 px-2 py-1 rounded-full bg-slate-900 border border-slate-800">
                            <span class="badge-dot bg-amber-400"></span> новый
                        </span>
                        <span class="inline-flex items-center gap-2 px-2 py-1 rounded-full bg-slate-900 border border-slate-800">
                            <span class="badge-dot bg-sky-400"></span> готовится
                        </span>
                        <span class="inline-flex items-center gap-2 px-2 py-1 rounded-full bg-slate-900 border border-slate-800">
                            <span class="badge-dot bg-slate-500"></span> свободно
                        </span>
                    </div>
                </div>
            </section>

            <!-- List panel (mobile) -->
            <section id="panel-list" class="lg:hidden hidden space-y-3">
                <div class="glass rounded-3xl soft-shadow p-4">
                    <div class="text-sm font-semibold mb-2">Список столов</div>
                    <div id="mobile-list" class="space-y-2"></div>
                </div>
            </section>

        </div>
    </main>
</div>

<!-- Bottom Sheet -->
<div id="sheet-backdrop" class="fixed inset-0 z-50 bg-black/60 hidden"></div>
<div id="sheet" class="fixed left-0 right-0 bottom-0 z-50 hidden">
    <div class="mx-auto max-w-xl px-4 pb-[env(safe-area-inset-bottom)]">
        <div class="glass rounded-t-3xl border-t border-slate-800 soft-shadow p-4 no-tap-highlight">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <div class="text-[11px] text-slate-400">Стол</div>
                    <div id="sheet-title" class="text-lg font-semibold truncate">—</div>
                    <div id="sheet-sub" class="text-[12px] text-slate-500 mt-1">—</div>
                </div>
                <button id="sheet-close" class="px-3 py-2 rounded-2xl bg-slate-800 hover:bg-slate-700 text-sm">Закрыть</button>
            </div>

            <div class="mt-3 flex gap-2">
                <a id="sheet-orders"
                   href="#"
                   class="flex-1 text-center px-4 py-2.5 rounded-2xl bg-emerald-500 hover:bg-emerald-400 text-slate-950 text-sm font-semibold">
                    Открыть заказы
                </a>
                <button id="sheet-refresh" class="px-4 py-2.5 rounded-2xl bg-slate-800 hover:bg-slate-700 text-sm">
                    Обновить
                </button>
            </div>

            <div class="mt-3 text-[11px] text-slate-500">
                Подсказка: если сложно попадать — увеличь масштаб (пинч двумя пальцами).
            </div>

                <div id="sheet-order-card" class="mt-4 hidden"></div>

                <div id="sheet-call-card" class="mt-3 hidden"></div>

                <button id="sheet-resolve-call" class="mt-3 hidden w-full min-h-[44px] px-4 py-3 rounded-2xl bg-red-600 hover:bg-red-500 text-white text-sm font-semibold">
                    Вызов обработан
                </button>
        </div>
    </div>
</div>

<script>
(function(){
    const tableNames = <?= json_encode($tableNameById, JSON_UNESCAPED_UNICODE) ?>;
    let activeByTable = <?= json_encode($activeByTable, JSON_UNESCAPED_UNICODE) ?>;
    const csrf = <?= json_encode($csrfToken, JSON_UNESCAPED_UNICODE) ?>;
    let currentSheetTableId = null;
    const state = <?= json_encode($layout, JSON_UNESCAPED_UNICODE) ?>;

    const viewport = document.getElementById('viewport');
    const canvas = document.getElementById('canvas');

    const btnFit = document.getElementById('btn-fit');
    const btnIn  = document.getElementById('btn-zoom-in');
    const btnOut = document.getElementById('btn-zoom-out');

    // tabs
    const tabMap  = document.getElementById('tab-map');
    const tabList = document.getElementById('tab-list');
    const panelMap  = document.getElementById('panel-map');
    const panelList = document.getElementById('panel-list');

    // lists
    const mobileList  = document.getElementById('mobile-list');
    const desktopList = document.getElementById('desktop-list');

    // sheet
    const sheet = document.getElementById('sheet');
    const sheetBackdrop = document.getElementById('sheet-backdrop');
    const sheetClose = document.getElementById('sheet-close');
    const sheetRefresh = document.getElementById('sheet-refresh');
    const sheetTitle = document.getElementById('sheet-title');
    const sheetSub = document.getElementById('sheet-sub');
    const sheetOrders = document.getElementById('sheet-orders');
    const sheetOrderCard = document.getElementById('sheet-order-card');
    const sheetCallCard = document.getElementById('sheet-call-card');
    const sheetResolveCallBtn = document.getElementById('sheet-resolve-call');

    // dynamic viewport height (mobile)
    function setViewportHeight() {
        const header = document.querySelector('header');
        const headerH = header ? header.getBoundingClientRect().height : 110;
        const h = Math.max(420, window.innerHeight - headerH - 28);
        viewport.style.height = h + 'px';
    }

    function clamp(v,a,b){ return Math.max(a, Math.min(b,v)); }

    function statusMeta(tableId){
        const entry = activeByTable[String(tableId)] || activeByTable[tableId] || null;
        if (!entry) {
            return {
                dot: 'bg-slate-500',
                ring: 'ring-slate-500/25',
                label: 'Свободно',
                cnt: 0,
                callActive: false,
                pulseClass: '',
                countdownMmss: null,
                countdownExpired: false,
                countdownWarning: false,
                paymentShort: null,
                readyItemsCount: 0,
                totalItemsCount: 0,
                partialReady: false,
            };
        }

        // New format from /staff/floorplan_api.php
        if (entry.order) {
            const order = entry.order || null;
            const call = entry.waiter_call || null;
            const callActive = !!(call && (call.id || call.order_id !== undefined));

            const orderStatus = String(order.order_status || '');
            const paymentStatus = String(order.payment_status || '');
            const paymentType = String(order.payment_type || '');

            const countdownSecondsRemaining = order.countdown_seconds_remaining !== null
                && typeof order.countdown_seconds_remaining !== 'undefined'
                ? Number(order.countdown_seconds_remaining)
                : null;
            const countdownExpired = !!order.countdown_expired;
            const countdownWarning = !!order.countdown_warning;
            function formatMMSS(sec){
                sec = Math.max(0, Number(sec || 0));
                const mm = String(Math.floor(sec / 60)).padStart(2, '0');
                const ss = String(sec % 60).padStart(2, '0');
                return mm + ':' + ss;
            }
            const countdownMmss = order.countdown_mmss || (countdownSecondsRemaining !== null ? formatMMSS(countdownSecondsRemaining) : null);

            function paymentTypeShort(pt){
                const map = { cash: 'Наличные', card_later: 'Карта позже', pay_later: 'Позже' };
                return map[pt] || pt || '';
            }

            function paymentStatusShort(ps){
                const map = { paid: 'Оплачен', unpaid: 'Не оплачен' };
                return map[ps] || ps || '';
            }

            const paymentShort = paymentStatusShort(paymentStatus)
                ? (paymentStatusShort(paymentStatus) + (paymentTypeShort(paymentType) ? ' · ' + paymentTypeShort(paymentType) : ''))
                : (paymentTypeShort(paymentType) ? paymentTypeShort(paymentType) : null);
            const readyItemsCount = Number(order.ready_items_count || 0);
            const totalItemsCount = Number(order.total_items_count || 0);
            const partialReady = !!order.partial_ready;

            let dot = 'bg-slate-500';
            let ring = 'ring-slate-500/25';
            let label = 'Свободно';
            let pulseClass = '';

            // Priority: delivered but unpaid + countdown expired are critical.
            if (orderStatus === 'delivered' && paymentStatus === 'unpaid') {
                dot = 'bg-red-400';
                ring = 'ring-red-500/45';
                label = 'Отдано · не оплачен';
            } else if (countdownExpired) {
                dot = 'bg-red-400';
                ring = 'ring-red-500/45';
                label = 'Ожидание истекло';
            } else if (orderStatus === 'new') {
                dot = 'bg-amber-400';
                ring = 'ring-amber-400/35';
                label = 'Новый';
            } else if (orderStatus === 'accepted' || orderStatus === 'cooking') {
                dot = 'bg-sky-400';
                ring = 'ring-sky-400/35';
                label = 'Готовится';
            } else if (orderStatus === 'ready') {
                dot = 'bg-emerald-400';
                ring = 'ring-emerald-400/35';
                label = 'Готово';
            } else {
                dot = 'bg-slate-500';
                ring = 'ring-slate-500/25';
                label = 'Свободно';
            }

            // Countdown warning overrides but stays below critical red.
            if (!countdownExpired && countdownWarning) {
                if (orderStatus !== 'delivered') {
                    dot = 'bg-amber-400';
                    ring = 'ring-amber-500/40';
                    label = 'Оплата: скоро';
                }
            }

            if (partialReady && totalItemsCount > 0 && orderStatus !== 'ready' && orderStatus !== 'delivered') {
                dot = 'bg-indigo-400';
                ring = 'ring-indigo-400/35';
                label = 'Частично готово';
            }

            // Waiter call override (pulse)
            if (callActive) {
                // If already critical red, keep red.
                if (ring.indexOf('red') === -1) {
                    dot = 'bg-orange-400';
                    ring = 'ring-orange-500/40';
                    label = label + ' · Вызов';
                }
                pulseClass = 'animate-pulse';
            }

            return {
                dot,
                ring,
                label,
                cnt: 0,
                callActive,
                pulseClass,
                countdownMmss,
                countdownExpired,
                countdownWarning,
                paymentShort,
                readyItemsCount,
                totalItemsCount,
                partialReady,
            };
        }

        // Если заказа нет, но есть активный вызов официанта.
        if (entry.waiter_call) {
            return {
                dot: 'bg-orange-400',
                ring: 'ring-orange-500/40',
                label: 'Вызов официанта',
                cnt: 0,
                callActive: true,
                pulseClass: 'animate-pulse',
                countdownMmss: null,
                countdownExpired: false,
                countdownWarning: false,
                paymentShort: null,
                readyItemsCount: 0,
                totalItemsCount: 0,
                partialReady: false,
            };
        }

        // Backward-compatibility: old format from ?status=1
        const cnt = Number(entry.active_cnt || 0);
        const st = entry.active_status;
        if (cnt > 0) {
            if (st === 'in_progress') return {dot:'bg-sky-400', ring:'ring-sky-400/35', label:'Готовится', cnt, callActive:false, pulseClass:'', countdownMmss:null, countdownExpired:false, countdownWarning:false, paymentShort:null, readyItemsCount:0, totalItemsCount:0, partialReady:false};
            return {dot:'bg-amber-400', ring:'ring-amber-400/35', label:'Новый', cnt, callActive:false, pulseClass:'', countdownMmss:null, countdownExpired:false, countdownWarning:false, paymentShort:null, readyItemsCount:0, totalItemsCount:0, partialReady:false};
        }
        return {dot:'bg-slate-500', ring:'ring-slate-500/25', label:'Свободно', cnt:0, callActive:false, pulseClass:'', countdownMmss:null, countdownExpired:false, countdownWarning:false, paymentShort:null, readyItemsCount:0, totalItemsCount:0, partialReady:false};
    }

    function escapeHtml(s){
        return String(s)
            .replace(/&/g,'&amp;').replace(/</g,'&lt;')
            .replace(/>/g,'&gt;').replace(/"/g,'&quot;')
            .replace(/'/g,'&#039;');
    }

    // ----- transforms
    let scale = 1;
    let offsetX = 0;
    let offsetY = 0;

    function applyTransform(){
        canvas.style.transform = `translate(${offsetX}px, ${offsetY}px) scale(${scale})`;
        canvas.style.transformOrigin = '0 0';
    }

    function fit(){
        const vw = viewport.clientWidth;
        const vh = viewport.clientHeight;
        const cw = state.canvas.w;
        const ch = state.canvas.h;

        const s = Math.min(vw / cw, vh / ch);
        scale = clamp(s, 0.35, 1.6);

        offsetX = (vw - cw * scale) / 2;
        offsetY = (vh - ch * scale) / 2;
        applyTransform();
    }

    // ----- rendering
    function renderMap(){
        canvas.querySelectorAll('.table-node').forEach(n => n.remove());

        (state.items || []).forEach((it)=>{
            const tableId = Number(it.table_id || 0);
            const name = tableNames[String(tableId)] || tableNames[tableId] || ('Стол #' + tableId);
            const meta = statusMeta(tableId);

            const el = document.createElement('div');
            el.className =
                'table-node absolute rounded-3xl border border-slate-700/70 bg-slate-950/88 ' +
                'ring-2 ' + meta.ring + ' shadow-lg shadow-black/30 ' +
                meta.pulseClass + ' active:scale-[0.985] transition no-tap-highlight';

            const w = Math.max(96, Number(it.w||140));
            const h = Math.max(78, Number(it.h||110));
            const x = Number(it.x||0);
            const y = Number(it.y||0);
            const r = Number(it.r||0);
            const shape = it.shape || 'rect';

            el.style.left = x + 'px';
            el.style.top  = y + 'px';
            el.style.width = w + 'px';
            el.style.height = h + 'px';
            el.style.transform = `rotate(${r}deg)`;
            if (shape === 'circle') el.style.borderRadius = '9999px';

            el.dataset.tableId = String(tableId);

            el.innerHTML = `
                <div class="h-full w-full flex flex-col items-center justify-center px-2 text-center">
                    <div class="flex items-center gap-2">
                        <span class="badge-dot ${meta.dot}"></span>
                        <div class="text-[15px] font-semibold text-slate-100 truncate">${escapeHtml(name)}</div>
                        ${meta.callActive ? '<span class="inline-flex items-center px-2 py-0.5 rounded-full bg-red-500/12 border border-red-500/40 text-[11px] text-red-200 animate-pulse">Вызов</span>' : ''}
                    </div>
                    <div class="text-[12px] text-slate-500 mt-1">
                        ${escapeHtml(meta.label)}
                    </div>
                    ${meta.countdownMmss !== null && typeof meta.countdownMmss !== 'undefined' ? `
                        <div class="text-[11px] mt-1 ${meta.countdownExpired ? 'text-red-300' : (meta.countdownWarning ? 'text-amber-300' : 'text-emerald-300')}">
                            Ожидание: ${escapeHtml(meta.countdownMmss)}
                        </div>
                    ` : ''}
                    ${meta.paymentShort ? `
                        <div class="text-[11px] text-slate-500 mt-0.5 truncate">
                            ${escapeHtml(meta.paymentShort)}
                        </div>
                    ` : ''}
                    ${meta.totalItemsCount > 0 ? `
                        <div class="text-[11px] text-slate-500 mt-0.5">
                            Готовность: ${meta.readyItemsCount}/${meta.totalItemsCount}
                        </div>
                    ` : ''}
                </div>
            `;

            // tap -> open sheet (click without drag)
            attachTap(el, () => openSheet(tableId));

            canvas.appendChild(el);
        });
    }

    function renderLists(){
        const allTables = Object.keys(tableNames).map(id => ({
            id: Number(id),
            name: tableNames[id]
        })).sort((a,b)=>a.id-b.id);

        function cardHtml(t){
            const meta = statusMeta(t.id);
            const badge = `<span class="badge-dot ${meta.dot}"></span>`;
            const right = meta.cnt ? `<div class="text-[11px] text-slate-500">активных: ${meta.cnt}</div>` : '';
            return `
<a href="/staff/orders.php?table_id=${encodeURIComponent(t.id)}"
   class="block rounded-3xl bg-slate-950/60 border border-slate-800 hover:border-emerald-500/35 px-4 py-3">
    <div class="flex items-center justify-between gap-3">
        <div class="min-w-0">
            <div class="text-sm font-semibold truncate">${escapeHtml(t.name)}</div>
            <div class="text-[11px] text-slate-500">ID: ${t.id}</div>
        </div>
        <div class="text-right">
            <div class="text-[11px] text-slate-400 flex items-center justify-end gap-2">${badge}<span>${escapeHtml(meta.label)}</span></div>
            ${right}
        </div>
    </div>
</a>`;
        }

        const html = allTables.map(cardHtml).join('');
        if (mobileList) mobileList.innerHTML = html;
        if (desktopList) desktopList.innerHTML = html;
    }

    // ----- sheet
    function openSheet(tableId){
        currentSheetTableId = tableId;
        const name = tableNames[String(tableId)] || ('Стол #' + tableId);
        const meta = statusMeta(tableId);
        const entry = activeByTable[String(tableId)] || activeByTable[tableId] || null;
        const order = entry && entry.order ? entry.order : null;
        const call = entry && entry.waiter_call ? entry.waiter_call : null;

        sheetTitle.textContent = name;
        const subParts = [];
        if (order && meta.paymentShort) subParts.push(meta.paymentShort);
        if (meta.totalItemsCount > 0) subParts.push('Готовность: ' + meta.readyItemsCount + '/' + meta.totalItemsCount);
        if (meta.countdownMmss !== null && typeof meta.countdownMmss !== 'undefined') {
            subParts.push('Ожидание: ' + meta.countdownMmss);
        }
        sheetSub.textContent = [meta.label].concat(subParts).join(' · ');
        sheetOrders.href = '/staff/orders.php?table_id=' + encodeURIComponent(tableId);

        // Order card
        if (sheetOrderCard) {
            if (order) {
                const orderTotal = typeof order.total_price !== 'undefined' && order.total_price !== null
                    ? Math.round(Number(order.total_price || 0)).toLocaleString('ru-RU')
                    : '—';
                const orderSince = typeof order.since_minutes !== 'undefined' ? Number(order.since_minutes || 0) : 0;

                let countdownHtml = '';
                if (order.countdown_seconds_remaining !== null && typeof order.countdown_seconds_remaining !== 'undefined') {
                    if (order.countdown_expired) {
                        countdownHtml = `
                            <div class="mt-3 rounded-2xl border border-red-500/40 bg-red-500/10 px-3 py-2">
                                <div class="text-sm font-semibold text-red-200">Время ожидания оплаты истекло</div>
                                <div class="text-[11px] text-red-200/80 mt-1">Заказ всё ещё не оплачен</div>
                            </div>
                        `;
                    } else {
                        const warnClass = order.countdown_warning ? 'border-amber-500/40 bg-amber-500/10' : 'border-emerald-500/30 bg-emerald-500/10';
                        const labelClass = order.countdown_warning ? 'text-amber-200' : 'text-emerald-200';
                        countdownHtml = `
                            <div class="mt-3 rounded-2xl ${warnClass} px-3 py-2 border">
                                <div class="text-sm font-semibold ${labelClass}">Официант должен подойти в течение 10 минут</div>
                                <div class="text-[11px] ${labelClass}/80 mt-1">
                                    Осталось: ${escapeHtml(order.countdown_mmss || '')}
                                </div>
                            </div>
                        `;
                    }
                }

                sheetOrderCard.innerHTML = `
                    <div class="rounded-2xl border border-slate-800 bg-slate-950/35 px-3 py-3">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="text-sm font-semibold text-white">Заказ #${escapeHtml(order.id || '')}</div>
                                <div class="text-[11px] text-slate-400 mt-1">Стол: ${escapeHtml(String(tableId))}</div>
                                <div class="text-[11px] text-slate-500 mt-1">Создан: ${escapeHtml(order.created_at_short || '')} · ${orderSince} мин назад</div>
                            </div>
                            <div class="text-right">
                                <div class="text-base font-extrabold text-emerald-400 tabular-nums">${orderTotal} ₽</div>
                                <div class="text-[11px] text-slate-500 mt-1">Сумма заказа</div>
                            </div>
                        </div>
                        <div class="mt-3 flex flex-wrap gap-2 items-center">
                            <span class="inline-flex items-center px-2.5 py-1 rounded-full border text-[11px] ${meta.ring.includes('red') ? 'bg-red-500/10 text-red-200 border-red-400/70' : 'bg-slate-900/80 text-slate-200 border-slate-700'}">
                                ${escapeHtml(meta.label)}
                            </span>
                            ${meta.paymentShort ? `<span class="inline-flex items-center px-2.5 py-1 rounded-full border text-[11px] bg-slate-900/80 text-slate-200 border-slate-700">${escapeHtml(meta.paymentShort)}</span>` : ''}
                            ${meta.totalItemsCount > 0 ? `<span class="inline-flex items-center px-2.5 py-1 rounded-full border text-[11px] ${meta.partialReady ? 'bg-indigo-500/10 text-indigo-200 border-indigo-400/70' : 'bg-slate-900/80 text-slate-200 border-slate-700'}">Готовность: ${meta.readyItemsCount}/${meta.totalItemsCount}</span>` : ''}
                        </div>
                        ${countdownHtml}
                    </div>
                `;
                sheetOrderCard.classList.remove('hidden');
            } else {
                sheetOrderCard.classList.add('hidden');
                sheetOrderCard.innerHTML = '';
            }
        }

        // Call card
        if (sheetCallCard) {
            if (call && call.id) {
                const callOrderId = call.order_id ? call.order_id : null;
                sheetCallCard.innerHTML = `
                    <div class="rounded-2xl border border-red-500/40 bg-red-500/10 px-3 py-3">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="text-sm font-semibold text-red-200">Вызов официанта</div>
                                <div class="text-[11px] text-red-200/80 mt-1">
                                    Активен · ${call.since_minutes || 0} мин назад
                                    ${callOrderId ? `· Заказ #${escapeHtml(callOrderId)}` : ''}
                                </div>
                            </div>
                            <div class="flex-shrink-0">
                                <div class="inline-flex items-center px-2.5 py-1 rounded-full border border-red-400/70 bg-red-500/15 text-red-100 text-[11px] animate-pulse">
                                    В обработке
                                </div>
                            </div>
                        </div>
                    </div>
                `;
                sheetCallCard.classList.remove('hidden');
                if (sheetResolveCallBtn) {
                    sheetResolveCallBtn.classList.remove('hidden');
                    sheetResolveCallBtn.dataset.waiter_call_id = String(call.id);
                }
            } else {
                sheetCallCard.classList.add('hidden');
                sheetCallCard.innerHTML = '';
                if (sheetResolveCallBtn) {
                    sheetResolveCallBtn.classList.add('hidden');
                    sheetResolveCallBtn.dataset.waiter_call_id = '';
                }
            }
        }

        sheetBackdrop.classList.remove('hidden');
        sheet.classList.remove('hidden');
    }

    sheetResolveCallBtn?.addEventListener('click', async () => {
        const callId = sheetResolveCallBtn.dataset.waiter_call_id || '';
        if (!callId) return;
        sheetResolveCallBtn.disabled = true;
        try {
            const form = new FormData();
            form.append('waiter_call_id', callId);
            form.append('csrf', csrf);
            const r = await fetch('/staff/waiter_call_resolve.php', {
                method: 'POST',
                body: form,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            const data = await r.json();
            if (data && data.success) {
                await refreshStatuses();
                // переоткроем шит если он был открыт
                if (currentSheetTableId !== null) openSheet(currentSheetTableId);
            } else {
                alert((data && data.message) ? data.message : 'Ошибка обработки вызова');
            }
        } catch (e) {
            alert('Ошибка сети при обработке вызова');
        } finally {
            sheetResolveCallBtn.disabled = false;
        }
    });
    function closeSheet(){
        sheetBackdrop.classList.add('hidden');
        sheet.classList.add('hidden');
    }
    sheetClose?.addEventListener('click', closeSheet);
    sheetBackdrop?.addEventListener('click', closeSheet);

    // ----- tap vs drag helper
    function attachTap(el, onTap){
        let startX=0, startY=0, moved=false;
        el.addEventListener('pointerdown', (ev)=>{
            startX = ev.clientX; startY = ev.clientY; moved=false;
        });
        el.addEventListener('pointermove', (ev)=>{
            if (Math.abs(ev.clientX-startX) > 8 || Math.abs(ev.clientY-startY) > 8) moved=true;
        });
        el.addEventListener('pointerup', (ev)=>{
            if (!moved) onTap();
        });
    }

    // ----- pan / zoom (1 finger pan, 2 finger pinch)
    let pan = null;
    let pinch = null;

    viewport.addEventListener('touchstart', (ev)=>{
        if (ev.touches.length === 1) {
            const t = ev.touches[0];
            pan = {sx:t.clientX, sy:t.clientY, ox:offsetX, oy:offsetY};
        } else if (ev.touches.length === 2) {
            const a = ev.touches[0], b = ev.touches[1];
            const dx = a.clientX - b.clientX;
            const dy = a.clientY - b.clientY;
            const dist = Math.hypot(dx,dy);
            pinch = {dist, scale0:scale};
        }
    }, {passive:true});

    viewport.addEventListener('touchmove', (ev)=>{
        if (ev.touches.length === 1 && pan) {
            // чтобы не скроллило страницу при панораме
            ev.preventDefault();
            const t = ev.touches[0];
            offsetX = pan.ox + (t.clientX - pan.sx);
            offsetY = pan.oy + (t.clientY - pan.sy);
            applyTransform();
        } else if (ev.touches.length === 2 && pinch) {
            ev.preventDefault();
            const a = ev.touches[0], b = ev.touches[1];
            const dx = a.clientX - b.clientX;
            const dy = a.clientY - b.clientY;
            const dist = Math.hypot(dx,dy);
            const ratio = dist / pinch.dist;
            scale = clamp(pinch.scale0 * ratio, 0.35, 2.1);
            applyTransform();
        }
    }, {passive:false});

    viewport.addEventListener('touchend', ()=>{
        if (pan && (!viewport.touches || viewport.touches?.length === 0)) pan = null;
        if (pinch && (!viewport.touches || viewport.touches?.length < 2)) pinch = null;
        pan = null; pinch = null;
    }, {passive:true});

    // Desktop wheel zoom (Ctrl)
    viewport.addEventListener('wheel', (ev)=>{
        if (!ev.ctrlKey) return;
        ev.preventDefault();
        const delta = ev.deltaY > 0 ? 0.92 : 1.08;
        scale = clamp(scale * delta, 0.35, 2.1);
        applyTransform();
    }, {passive:false});

    btnIn?.addEventListener('click', ()=>{ scale = clamp(scale * 1.12, 0.35, 2.1); applyTransform(); });
    btnOut?.addEventListener('click', ()=>{ scale = clamp(scale / 1.12, 0.35, 2.1); applyTransform(); });
    btnFit?.addEventListener('click', fit);

    // ----- Tabs behavior (mobile)
    function setTab(which){
        if (!tabMap || !tabList) return;
        if (which === 'list') {
            panelMap.classList.add('hidden');
            panelList.classList.remove('hidden');
            tabList.className = 'flex-1 px-3 py-2 rounded-2xl text-sm font-semibold bg-slate-900 border border-slate-800';
            tabMap.className  = 'flex-1 px-3 py-2 rounded-2xl text-sm font-semibold bg-transparent border border-transparent text-slate-300';
        } else {
            panelList.classList.add('hidden');
            panelMap.classList.remove('hidden');
            tabMap.className  = 'flex-1 px-3 py-2 rounded-2xl text-sm font-semibold bg-slate-900 border border-slate-800';
            tabList.className = 'flex-1 px-3 py-2 rounded-2xl text-sm font-semibold bg-transparent border border-transparent text-slate-300';
        }
    }
    tabMap?.addEventListener('click', ()=>setTab('map'));
    tabList?.addEventListener('click', ()=>setTab('list'));

    // ----- statuses refresh
    async function refreshStatuses(){
        try {
            const res = await fetch('/staff/floorplan_api.php', {cache:'no-store'});
            const data = await res.json();
            if (!data || !data.success) return;
            activeByTable = data.by_table || {};
            renderMap();
            renderLists();

            // если шит открыт — обновим текст
            if (!sheet.classList.contains('hidden')) {
                const currentHref = sheetOrders.getAttribute('href') || '';
                const m = currentHref.match(/table_id=([0-9]+)/);
                if (m && m[1]) openSheet(Number(m[1]));
            }
        } catch (e) {}
    }

    sheetRefresh?.addEventListener('click', refreshStatuses);

    // init
    setViewportHeight();
    window.addEventListener('resize', setViewportHeight);

    renderLists();
    renderMap();
    fit();

    // авто-обновление
    refreshStatuses();
    setInterval(refreshStatuses, 5000);
})();
</script>
</body>
</html>
