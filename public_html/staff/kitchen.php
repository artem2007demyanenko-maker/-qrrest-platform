<?php
/**
 * Kitchen display: fullscreen view — New / Cooking / Ready.
 * Tablet-friendly, big cards, auto-refresh. Demo: static orders.
 */

require_once __DIR__ . '/../../app/bootstrap.php';
require_kitchen_access();

$demo = is_demo_mode();
$restName = $currentRestaurant['name'] ?? 'Restaurant';
if (!function_exists('e')) {
    function e($v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
}

require_once __DIR__ . '/../../app/kds_helpers.php';
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

$kitchenRole = function_exists('current_user_restaurant_role')
    ? normalize_restaurant_role((string)(current_user_restaurant_role((int)($currentRestaurant['id'] ?? 0)) ?? ''))
    : '';
$staffStation = function_exists('current_staff_station')
    ? (string)current_staff_station((int)($currentRestaurant['id'] ?? 0))
    : ($kitchenRole === 'bar' ? 'bar' : 'hot');
$staffStationKds = function_exists('station_to_kds_key')
    ? station_to_kds_key($staffStation)
    : ($kitchenRole === 'bar' ? 'bar' : 'kitchen');
$requestedStation = isset($_GET['station']) ? kds_normalize_station((string)$_GET['station']) : '';
if ($kitchenRole === 'bar' && (string)($_GET['bar_panel'] ?? '') !== '1') {
    header('Location: /staff/bar.php', true, 302);
    exit;
}
$isStationRole = function_exists('restaurant_role_is_station_role')
    ? restaurant_role_is_station_role($kitchenRole)
    : in_array($kitchenRole, ['bar', 'kitchen'], true);
$isFixedStationRole = function_exists('restaurant_role_is_fixed_station_role')
    ? restaurant_role_is_fixed_station_role($kitchenRole)
    : ($kitchenRole === 'bar');
if ($isStationRole && $requestedStation === '') {
    $requestedStation = $staffStationKds;
}
$stationAccessAllowed = $requestedStation === '' || (function_exists('can_access_station')
    ? can_access_station($requestedStation, (int)($currentRestaurant['id'] ?? 0), $kitchenRole)
    : user_has_station_access($kitchenRole, $requestedStation));
if (function_exists('staff_context_debug_log')) {
    $debugUser = function_exists('auth_user') ? auth_user() : null;
    staff_context_debug_log('kds_station_guard', [
        'user_id' => (int)($debugUser['id'] ?? 0),
        'restaurant_id' => (int)($currentRestaurant['id'] ?? 0),
        'role' => $kitchenRole,
        'requested_station' => $requestedStation,
        'resolved_station' => $staffStationKds,
        'station_access_allowed' => $stationAccessAllowed,
    ]);
}
if (!$stationAccessAllowed) {
    http_response_code(403);
    echo 'Access denied';
    exit;
}
$restId = (int)($currentRestaurant['id'] ?? 0);
$pdo = db();
$kitchenForecast = ($pdo instanceof PDO && function_exists('forecast_operational_foundation'))
    ? forecast_operational_foundation($pdo, $restId, ['target' => 'next_shift'])
    : ['orders' => [], 'kitchen_workload' => ['station_load' => []], 'staffing_need' => [], 'alerts' => []];
$stationList = ($pdo instanceof PDO && function_exists('kitchen_station_list'))
    ? kitchen_station_list($pdo, $restId)
    : [];
if ($stationList === []) {
    if (function_exists('qa_runtime_warn_once')) {
        qa_runtime_warn_once(
            'kds_station_fallback_active',
            'Kitchen station list fallback activated',
            ['restaurant_id' => $restId]
        );
    }
    $stationList = [];
    foreach (kds_allowed_stations() as $stationKey) {
        $stationList[$stationKey] = [
            'station_key' => $stationKey,
            'station_name' => kds_station_label_ru($stationKey),
            'active' => 1,
            'priority' => 100,
            'color' => '#94A3B8',
            'prep_time_avg' => 15,
        ];
    }
}
$stationKeys = array_values(array_keys($stationList));
$stationLockedByRole = '';
if ($isFixedStationRole && $staffStationKds !== 'all') {
    $stationLockedByRole = $staffStationKds;
}
$initialStation = '';
if ($stationLockedByRole !== '') {
    $initialStation = $stationLockedByRole;
} elseif ($requestedStation !== '') {
    $s = $requestedStation;
    if (in_array($s, $stationKeys, true)) {
        $initialStation = $s;
    }
}
$initialStatus = 'all';
if (isset($_GET['status'])) {
    $st = strtolower(trim((string)$_GET['status']));
    if (in_array($st, ['all', 'new', 'pending', 'accepted', 'preparing', 'cooking', 'ready'], true)) {
        if ($st === 'accepted') {
            $st = 'pending';
        } elseif ($st === 'cooking') {
            $st = 'preparing';
        }
        $initialStatus = $st;
    }
}
$roleStationHint = '';
if ($kitchenRole === 'bar') {
    $roleStationHint = 'Станция по роли: Бар';
} elseif ($isStationRole) {
    $roleStationHint = 'Станция по роли: ' . kds_station_label_ru($staffStationKds);
}
$kdsBackUrl = in_array($kitchenRole, ['owner', 'admin'], true)
    ? '/restaurant/orders.php'
    : '/staff/orders.php';
$isBarPanel = ($stationLockedByRole === 'bar') || (isset($_GET['station']) && strtolower(trim((string)$_GET['station'])) === 'bar');
$kdsPanelTitle = $isBarPanel ? 'Бар' : 'KDS по станциям';
$kdsPanelSubTitle = $isBarPanel ? 'Станция: бар' : 'Кухня · live';
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title><?= e($kdsPanelTitle) ?> — <?= e($restName) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen <?= $isBarPanel ? 'bg-violet-950' : 'bg-slate-950' ?> text-slate-50">
<div class="min-h-screen flex flex-col">
    <header class="border-b <?= $isBarPanel ? 'border-violet-800 bg-violet-900/85' : 'border-slate-800 bg-slate-900/95' ?> px-4 py-3 flex flex-wrap items-center justify-between gap-3">
        <div class="min-w-0">
            <div class="text-xs text-slate-400"><?= e($kdsPanelSubTitle) ?></div>
            <h1 class="text-xl md:text-2xl font-bold text-slate-100"><?= e($kdsPanelTitle) ?></h1>
            <div class="text-xs text-slate-500 mt-0.5"><?= e($restName) ?></div>
        </div>
        <div class="flex items-center gap-2">
            <a href="<?= e($kdsBackUrl) ?>"
               class="px-3 py-2 rounded-xl bg-slate-800 border border-slate-700 text-xs text-slate-200 hover:bg-slate-700">
                ← К заказам
            </a>
            <?php if ($roleStationHint !== ''): ?>
                <span class="hidden md:inline-flex items-center px-3 py-2 rounded-xl border border-emerald-500/30 bg-emerald-500/10 text-[11px] text-emerald-200">
                    <?= e($roleStationHint) ?>
                </span>
            <?php endif; ?>
            <button id="btn-fullscreen" type="button" class="px-3 py-2 rounded-xl bg-slate-800 border border-slate-700 text-xs text-slate-200 hover:bg-slate-700">
                Во весь экран
            </button>
        </div>
    </header>

    <main class="flex-1 p-3 md:p-5">
        <div class="grid grid-cols-2 lg:grid-cols-6 gap-2 mb-3">
            <div class="rounded-2xl border border-slate-800 bg-slate-900/50 px-3 py-2">
                <div class="text-[11px] text-slate-500">Тикеты</div>
                <div id="sum-total" class="text-lg font-bold text-slate-100">0</div>
            </div>
            <div class="rounded-2xl border border-amber-500/30 bg-amber-500/10 px-3 py-2">
                <div class="text-[11px] text-amber-200/80">Новые</div>
                <div id="sum-new" class="text-lg font-bold text-amber-200">0</div>
            </div>
            <div class="rounded-2xl border border-sky-500/30 bg-sky-500/10 px-3 py-2">
                <div class="text-[11px] text-sky-200/80">Приняты</div>
                <div id="sum-accepted" class="text-lg font-bold text-sky-200">0</div>
            </div>
            <div class="rounded-2xl border border-indigo-500/30 bg-indigo-500/10 px-3 py-2">
                <div class="text-[11px] text-indigo-200/80">Готовятся</div>
                <div id="sum-cooking" class="text-lg font-bold text-indigo-200">0</div>
            </div>
            <div class="rounded-2xl border border-emerald-500/30 bg-emerald-500/10 px-3 py-2">
                <div class="text-[11px] text-emerald-200/80">Готово</div>
                <div id="sum-ready" class="text-lg font-bold text-emerald-200">0</div>
            </div>
            <div class="rounded-2xl border border-orange-500/30 bg-orange-500/10 px-3 py-2">
                <div class="text-[11px] text-orange-200/80">Вызовы</div>
                <div id="sum-calls" class="text-lg font-bold text-orange-200">0</div>
            </div>
        </div>

        <?php
        $kfcOrders = is_array($kitchenForecast['orders'] ?? null) ? $kitchenForecast['orders'] : [];
        $kfcKitchen = is_array($kitchenForecast['kitchen_workload'] ?? null) ? $kitchenForecast['kitchen_workload'] : ['station_load' => []];
        $kfcStaff = is_array($kitchenForecast['staffing_need'] ?? null) ? $kitchenForecast['staffing_need'] : [];
        $kfcAlerts = is_array($kitchenForecast['alerts'] ?? null) ? $kitchenForecast['alerts'] : [];
        $kfcPeakStation = is_array($kfcKitchen['peak_station'] ?? null) ? $kfcKitchen['peak_station'] : [];
        ?>
        <div class="rounded-2xl border border-amber-500/40 bg-amber-500/10 p-3 mb-3 space-y-2">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <div class="text-xs uppercase tracking-wide text-amber-200">Forecast next shift</div>
                    <div class="text-xs text-amber-100/85">Оценка station load по historical паттернам (AI-ready foundation).</div>
                </div>
                <div class="text-xs text-amber-100/80">
                    orders: <span class="font-semibold"><?= (int)($kfcOrders['expected_orders'] ?? 0) ?></span> · tph: <span class="font-semibold"><?= number_format((float)($kfcKitchen['expected_tickets_per_hour'] ?? 0), 2, '.', ' ') ?></span>
                </div>
            </div>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-2 text-xs">
                <div class="rounded-xl border border-slate-700 bg-slate-900/70 px-2 py-2"><div class="text-slate-400">Peak station</div><div class="text-slate-100 font-semibold mt-1"><?= e((string)($kfcPeakStation['station_key'] ?? 'kitchen')) ?></div></div>
                <div class="rounded-xl border border-rose-500/40 bg-rose-500/10 px-2 py-2"><div class="text-rose-200/80">Pressure</div><div class="text-rose-100 font-semibold mt-1"><?= (int)($kfcPeakStation['predicted_pressure'] ?? 0) ?>%</div></div>
                <div class="rounded-xl border border-indigo-500/40 bg-indigo-500/10 px-2 py-2"><div class="text-indigo-200/80">Kitchen need</div><div class="text-indigo-100 font-semibold mt-1"><?= (int)($kfcStaff['recommended_kitchen_staff'] ?? 0) ?></div></div>
                <div class="rounded-xl border border-cyan-500/40 bg-cyan-500/10 px-2 py-2"><div class="text-cyan-200/80">Kitchen gap</div><div class="text-cyan-100 font-semibold mt-1"><?= (int)($kfcStaff['kitchen_gap'] ?? 0) ?></div></div>
            </div>
            <?php if ($kfcAlerts !== []): ?>
                <div class="space-y-1">
                    <?php foreach (array_slice($kfcAlerts, 0, 3) as $alert): ?>
                        <?php $lvl = strtolower(trim((string)($alert['level'] ?? 'warning'))); ?>
                        <div class="rounded-lg border px-2 py-1 text-[11px] <?= $lvl === 'critical' ? 'border-red-500/50 bg-red-500/10 text-red-200' : 'border-amber-500/50 bg-amber-500/10 text-amber-200' ?>">
                            <span class="font-semibold"><?= e((string)($alert['label'] ?? 'Forecast alert')) ?>:</span>
                            <?= e((string)($alert['message'] ?? '')) ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="rounded-2xl border border-slate-800 bg-slate-900/70 p-3 mb-3 space-y-3">
            <div class="flex flex-wrap items-center gap-2">
                <span class="text-xs text-slate-400 mr-1">Станция:</span>
                <?php if ($stationLockedByRole === 'bar'): ?>
                    <button type="button" class="kds-station-tab px-3 py-2 rounded-xl border border-emerald-500/40 bg-emerald-500/15 text-sm font-semibold text-emerald-200" data-station="bar">Бар <span class="text-[11px] text-emerald-300/80" data-station-count="bar"></span></button>
                <?php else: ?>
                    <button type="button" class="kds-station-tab px-3 py-2 rounded-xl border border-slate-700 text-sm font-semibold" data-station="all">Все <span class="text-[11px] text-slate-500" data-station-count="all"></span></button>
                    <?php foreach ($stationList as $stationMeta): ?>
                        <?php
                        $stKey = (string)($stationMeta['station_key'] ?? 'kitchen');
                        if ($stKey === '') {
                            continue;
                        }
                        $stName = (string)($stationMeta['station_name'] ?? kds_station_label_ru($stKey));
                        ?>
                        <button type="button" class="kds-station-tab px-3 py-2 rounded-xl border border-slate-700 text-sm font-semibold" data-station="<?= e($stKey) ?>">
                            <?= e($stName) ?> <span class="text-[11px] text-slate-500" data-station-count="<?= e($stKey) ?>"></span>
                        </button>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <span class="text-xs text-slate-400 mr-1">Статус:</span>
                <button type="button" class="kds-status-tab px-3 py-2 rounded-xl border border-slate-700 text-sm font-semibold" data-status="all">Все <span class="text-[11px] text-slate-500" data-status-count="all"></span></button>
                <button type="button" class="kds-status-tab px-3 py-2 rounded-xl border border-slate-700 text-sm font-semibold" data-status="new">Новые <span class="text-[11px] text-slate-500" data-status-count="new"></span></button>
                <button type="button" class="kds-status-tab px-3 py-2 rounded-xl border border-slate-700 text-sm font-semibold" data-status="pending">Ожидают <span class="text-[11px] text-slate-500" data-status-count="pending"></span></button>
                <button type="button" class="kds-status-tab px-3 py-2 rounded-xl border border-slate-700 text-sm font-semibold" data-status="preparing">Готовятся <span class="text-[11px] text-slate-500" data-status-count="preparing"></span></button>
                <button type="button" class="kds-status-tab px-3 py-2 rounded-xl border border-slate-700 text-sm font-semibold" data-status="ready">Готово <span class="text-[11px] text-slate-500" data-status-count="ready"></span></button>
            </div>
            <div class="text-xs text-slate-500"><?= $isBarPanel ? 'Показываются только барные позиции · обновление каждые 5 секунд' : 'Показываются только активные кухонные тикеты · обновление каждые 5 секунд' ?></div>
            <div id="kds-polling-error" class="hidden mt-2 inline-flex items-center rounded-lg border border-amber-500/50 bg-amber-500/10 px-2.5 py-1 text-[11px] text-amber-200">
                Проблема обновления KDS. Проверяем подключение…
            </div>
        </div>
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-2 mb-3" id="station-summary">
            <div class="rounded-2xl border border-slate-800 bg-slate-900/50 px-3 py-2">
                <div class="text-[11px] text-slate-500">Тикеты станции</div>
                <div id="station-sum-total" class="text-lg font-bold text-slate-100">0</div>
            </div>
            <div class="rounded-2xl border border-amber-500/30 bg-amber-500/10 px-3 py-2">
                <div class="text-[11px] text-amber-200/80">Новые</div>
                <div id="station-sum-new" class="text-lg font-bold text-amber-200">0</div>
            </div>
            <div class="rounded-2xl border border-red-500/30 bg-red-500/10 px-3 py-2">
                <div class="text-[11px] text-red-200/80">Просрочено 10+ мин</div>
                <div id="station-sum-overdue" class="text-lg font-bold text-red-200">0</div>
            </div>
            <div class="rounded-2xl border border-emerald-500/30 bg-emerald-500/10 px-3 py-2">
                <div class="text-[11px] text-emerald-200/80">Готово по станции</div>
                <div id="station-sum-ready" class="text-lg font-bold text-emerald-200">0</div>
            </div>
        </div>
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-2 mb-3" id="station-workload">
            <div class="rounded-2xl border border-slate-800 bg-slate-900/50 px-3 py-2">
                <div class="text-[11px] text-slate-500">Нагрузка станции</div>
                <div id="station-workload-load" class="text-lg font-bold text-slate-100">0%</div>
            </div>
            <div class="rounded-2xl border border-indigo-500/30 bg-indigo-500/10 px-3 py-2">
                <div class="text-[11px] text-indigo-200/80">Pressure</div>
                <div id="station-workload-pressure" class="text-lg font-bold text-indigo-200">0%</div>
            </div>
            <div class="rounded-2xl border border-cyan-500/30 bg-cyan-500/10 px-3 py-2">
                <div class="text-[11px] text-cyan-200/80">Avg prep</div>
                <div id="station-workload-prep" class="text-lg font-bold text-cyan-200">0 мин</div>
            </div>
            <div class="rounded-2xl border border-rose-500/30 bg-rose-500/10 px-3 py-2">
                <div class="text-[11px] text-rose-200/80">SLA breach</div>
                <div id="station-workload-breach" class="text-lg font-bold text-rose-200">0</div>
            </div>
        </div>
        <div id="station-alerts" class="space-y-1 mb-3"></div>

        <audio id="kds-sound" preload="none" aria-hidden="true"></audio>

        <?php if ($stationLockedByRole === 'bar'): ?>
            <div class="grid grid-cols-1 gap-3 md:gap-4">
                <section id="wrap-bar" class="rounded-2xl border border-emerald-500/40 bg-slate-900/80 p-3">
                    <h2 class="text-sm font-bold text-emerald-200 mb-2">Бар</h2>
                    <div id="sec-bar" class="space-y-3"></div>
                </section>
            </div>
        <?php else: ?>
            <div class="grid grid-cols-1 xl:grid-cols-4 gap-3 md:gap-4">
                <?php
                $stationTone = [
                    'hot' => 'border-amber-500/40 text-amber-200',
                    'cold' => 'border-sky-500/40 text-sky-200',
                    'bar' => 'border-emerald-500/40 text-emerald-200',
                    'hookah' => 'border-green-500/40 text-green-200',
                    'dessert' => 'border-violet-500/40 text-violet-200',
                    'grill' => 'border-rose-500/40 text-rose-200',
                    'pizza' => 'border-orange-500/40 text-orange-200',
                    'sushi' => 'border-cyan-500/40 text-cyan-200',
                    'custom' => 'border-indigo-500/40 text-indigo-200',
                    'kitchen' => 'border-slate-500/40 text-slate-200',
                ];
                ?>
                <?php foreach ($stationList as $stationMeta): ?>
                    <?php
                    $stKey = (string)($stationMeta['station_key'] ?? 'kitchen');
                    if ($stKey === '') {
                        continue;
                    }
                    $tone = $stationTone[$stKey] ?? 'border-slate-500/40 text-slate-200';
                    $title = (string)($stationMeta['station_name'] ?? kds_station_label_ru($stKey));
                    [$borderTone, $textTone] = array_pad(explode(' ', $tone, 2), 2, '');
                    ?>
                    <section id="wrap-<?= e($stKey) ?>" class="rounded-2xl border <?= e($borderTone) ?> bg-slate-900/80 p-3">
                        <h2 class="text-sm font-bold <?= e($textTone) ?> mb-2"><?= e($title) ?></h2>
                        <div id="sec-<?= e($stKey) ?>" class="space-y-3"></div>
                    </section>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div id="kds-empty" class="hidden mt-4 rounded-2xl border border-slate-800/80 bg-slate-900/50 px-4 py-4 text-sm text-slate-400">
            Нет активных тикетов.
        </div>
    </main>
</div>

<script>
(function(){
    const demo = <?= $demo ? 'true' : 'false' ?>;
    const csrf = <?= json_encode((string)($_SESSION['csrf'] ?? ''), JSON_UNESCAPED_UNICODE) ?>;
    const stationLockedByRole = <?= json_encode($stationLockedByRole, JSON_UNESCAPED_UNICODE) ?>;
    const isBarPanel = <?= $isBarPanel ? 'true' : 'false' ?>;
    const stationKeys = <?= json_encode(array_values(array_keys($stationList)), JSON_UNESCAPED_UNICODE) ?>;
    let stationFilter = <?= json_encode($initialStation !== '' ? $initialStation : 'all', JSON_UNESCAPED_UNICODE) ?>;
    let statusFilter = <?= json_encode($initialStatus, JSON_UNESCAPED_UNICODE) ?>;
    if (stationLockedByRole) {
        stationFilter = stationLockedByRole;
    }

    const stationTabs = Array.prototype.slice.call(document.querySelectorAll('.kds-station-tab'));
    const statusTabs = Array.prototype.slice.call(document.querySelectorAll('.kds-status-tab'));

    const sections = {};
    const wraps = {};
    stationKeys.forEach(function(key){
        sections[key] = document.getElementById('sec-' + key);
        wraps[key] = document.getElementById('wrap-' + key);
    });
    const emptyEl = document.getElementById('kds-empty');
    const pollErrorEl = document.getElementById('kds-polling-error');
    const soundEl = document.getElementById('kds-sound');
    const summaryEls = {
        total: document.getElementById('sum-total'),
        fresh: document.getElementById('sum-new'),
        accepted: document.getElementById('sum-accepted'),
        cooking: document.getElementById('sum-cooking'),
        ready: document.getElementById('sum-ready'),
        calls: document.getElementById('sum-calls')
    };
    const stationSummaryEls = {
        total: document.getElementById('station-sum-total'),
        fresh: document.getElementById('station-sum-new'),
        overdue: document.getElementById('station-sum-overdue'),
        ready: document.getElementById('station-sum-ready')
    };
    const stationWorkloadEls = {
        load: document.getElementById('station-workload-load'),
        pressure: document.getElementById('station-workload-pressure'),
        prep: document.getElementById('station-workload-prep'),
        breach: document.getElementById('station-workload-breach'),
        alerts: document.getElementById('station-alerts')
    };

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

    function persistFilters(){
        try {
            const url = new URL(window.location.href);
            const stationForUrl = stationLockedByRole || stationFilter;
            if (stationForUrl && stationForUrl !== 'all') {
                url.searchParams.set('station', stationForUrl);
            } else {
                url.searchParams.delete('station');
            }
            if (statusFilter && statusFilter !== 'all') {
                url.searchParams.set('status', statusFilter);
            } else {
                url.searchParams.delete('status');
            }
            history.replaceState({}, '', url.toString());
        } catch (e) {}
    }

    function applyStationVisibility(){
        if (stationLockedByRole) {
            stationFilter = stationLockedByRole;
        }
        Object.keys(wraps).forEach(function(k){
            if (!wraps[k]) return;
            wraps[k].classList.toggle('hidden', stationFilter !== 'all' && stationFilter !== k);
        });
    }

    function timerClass(min){
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
    function statusLabel(st){
        const map = {
            new: 'new',
            accepted: 'pending',
            cooking: 'preparing',
            ready: 'ready'
        };
        return map[String(st || 'new')] || String(st || 'new');
    }

    function matchesStatusFilter(ticket){
        if (statusFilter === 'all') return true;
        var st = String(ticket.station_status || 'new');
        if (statusFilter === 'pending') return st === 'accepted';
        if (statusFilter === 'preparing') return st === 'cooking';
        return st === statusFilter;
    }
    function matchesStationFilter(ticket){
        if (stationFilter === 'all') return true;
        return String(ticket.station || '') === stationFilter;
    }
    function renderStationSummary(baseTickets){
        const tickets = (Array.isArray(baseTickets) ? baseTickets : [])
            .filter(matchesStationFilter)
            .filter(matchesStatusFilter);
        const total = tickets.length;
        const fresh = tickets.filter(function(t){ return String(t.station_status || 'new') === 'new'; }).length;
        const overdue = tickets.filter(function(t){ return Number(t.minutes_since_created || 0) >= 10; }).length;
        const ready = tickets.filter(function(t){
            return Number(t.station_ready_items_count || 0) >= Number(t.station_total_items_count || 0) && Number(t.station_total_items_count || 0) > 0;
        }).length;
        if (stationSummaryEls.total) stationSummaryEls.total.textContent = String(total);
        if (stationSummaryEls.fresh) stationSummaryEls.fresh.textContent = String(fresh);
        if (stationSummaryEls.overdue) stationSummaryEls.overdue.textContent = String(overdue);
        if (stationSummaryEls.ready) stationSummaryEls.ready.textContent = String(ready);
    }

    function renderWorkload(summaryMap, alerts){
        const map = (summaryMap && typeof summaryMap === 'object') ? summaryMap : {};
        const targetStation = (stationFilter && stationFilter !== 'all')
            ? stationFilter
            : (stationKeys.length > 0 ? stationKeys[0] : 'kitchen');
        const summary = map[targetStation] || map.kitchen || null;
        const workload = summary && typeof summary.workload === 'object' ? summary.workload : {};

        if (stationWorkloadEls.load) stationWorkloadEls.load.textContent = String(Number(workload.station_load_percent || 0)) + '%';
        if (stationWorkloadEls.pressure) stationWorkloadEls.pressure.textContent = String(Number(workload.queue_pressure || 0)) + '%';
        if (stationWorkloadEls.prep) stationWorkloadEls.prep.textContent = String(Number(workload.avg_prep_time_minutes || 0)) + ' мин';
        if (stationWorkloadEls.breach) stationWorkloadEls.breach.textContent = String(Number(workload.sla_breaches || 0));

        if (!stationWorkloadEls.alerts) return;
        const list = Array.isArray(alerts) ? alerts : [];
        if (list.length === 0) {
            stationWorkloadEls.alerts.innerHTML = '<div class="text-[11px] text-emerald-300">Station alerts: критичных сигналов нет.</div>';
            return;
        }
        stationWorkloadEls.alerts.innerHTML = list.slice(0, 4).map(function(alert){
            const level = String((alert && alert.level) || 'warning');
            const tone = level === 'critical'
                ? 'border-red-500/50 bg-red-500/10 text-red-200'
                : 'border-amber-500/50 bg-amber-500/10 text-amber-200';
            const label = String((alert && alert.label) || 'Alert');
            const message = String((alert && alert.message) || '');
            return '<div class="rounded-lg border px-2 py-1 text-[11px] ' + tone + '"><span class="font-semibold">' + esc(label) + ':</span> ' + esc(message) + '</div>';
        }).join('');
    }

    function updateCounters(tickets){
        const base = Array.isArray(tickets) ? tickets : [];
        const byStatus = { all: base.length, new: 0, accepted: 0, cooking: 0, ready: 0, pending: 0, preparing: 0 };
        const byStation = { all: base.length };
        stationKeys.forEach(function(key){ byStation[key] = 0; });
        let calls = 0;
        base.forEach(function(t){
            const st = String(t.station_status || 'new');
            if (Object.prototype.hasOwnProperty.call(byStatus, st)) byStatus[st] += 1;
            if (st === 'accepted') byStatus.pending += 1;
            if (st === 'cooking') byStatus.preparing += 1;
            const station = String(t.station || '');
            if (Object.prototype.hasOwnProperty.call(byStation, station)) byStation[station] += 1;
            if (t.has_waiter_call) calls += 1;
        });
        if (summaryEls.total) summaryEls.total.textContent = String(byStatus.all);
        if (summaryEls.fresh) summaryEls.fresh.textContent = String(byStatus.new);
        if (summaryEls.accepted) summaryEls.accepted.textContent = String(byStatus.accepted);
        if (summaryEls.cooking) summaryEls.cooking.textContent = String(byStatus.cooking);
        if (summaryEls.ready) summaryEls.ready.textContent = String(byStatus.ready);
        if (summaryEls.calls) summaryEls.calls.textContent = String(calls);

        Object.keys(byStatus).forEach(function(key){
            const el = document.querySelector('[data-status-count="' + key + '"]');
            if (el) el.textContent = '(' + byStatus[key] + ')';
        });
        Object.keys(byStation).forEach(function(key){
            const el = document.querySelector('[data-station-count="' + key + '"]');
            if (el) el.textContent = '(' + byStation[key] + ')';
        });
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
        const orderTypeLabel = String(t.order_type_label || '');
        const sourceLabel = String(t.source_label || '');
        const orderComment = String(t.comment || '').trim();
        const waiterBadge = t.has_waiter_call ? '<span class="inline-flex items-center px-2 py-0.5 rounded-full bg-orange-500/10 border border-orange-400/60 text-[10px] text-orange-200">Вызов</span>' : '';
        const partial = t.partial_ready ? '<span class="inline-flex items-center px-2 py-0.5 rounded-full bg-indigo-500/10 border border-indigo-400/60 text-[10px] text-indigo-200">Частично готов</span>' : '';
        const isOverdue = createdMin >= 10;
        const isNew = String(t.station_status || 'new') === 'new';
        const isStationReady = Number(t.station_ready_items_count || 0) >= Number(t.station_total_items_count || 0) && Number(t.station_total_items_count || 0) > 0;
        const prepTimer = (t && typeof t.prep_timer === 'object' && t.prep_timer) ? t.prep_timer : {};
        const prepProgress = Math.max(0, Math.min(100, Number(prepTimer.progress_percent || 0)));
        const prepExpected = Number(prepTimer.expected_minutes || 0);
        const prepElapsed = Number(prepTimer.elapsed_minutes || 0);
        const prepOverdue = !!prepTimer.is_overdue;
        const cardTone = isOverdue
            ? 'border-red-500/60 bg-red-950/15'
            : (isNew
                ? 'border-amber-500/50 bg-amber-950/10'
                : (isStationReady ? 'border-emerald-500/50 bg-emerald-950/10' : 'border-slate-700 bg-slate-800/70'));
        const stationProgress = Number(t.station_ready_items_count || 0) + '/' + Number(t.station_total_items_count || 0);
        const orderProgress = Number(t.ready_items_count || 0) + '/' + Number(t.total_items_count || 0);
        const splitBadge = t.split_ticket
            ? '<span class="inline-flex items-center px-2 py-0.5 rounded-full bg-violet-500/10 border border-violet-400/60 text-[10px] text-violet-200">Split ×' + Number(t.split_station_count || 1) + '</span>'
            : '';
        const stationBadge = '<span class="inline-flex items-center px-2 py-0.5 rounded-full bg-slate-800/80 border border-slate-600 text-[10px] text-slate-200">' + esc(String(t.station_label || t.station || 'station')) + '</span>';
        const stationLinesBadge = '<span class="inline-flex items-center px-2 py-0.5 rounded-full bg-slate-800/60 border border-slate-700 text-[10px] text-slate-300">Позиций: ' + Number(t.station_item_lines || 0) + '</span>';

        return '' +
        '<article data-order-id="' + esc(t.order_id) + '" data-station="' + esc(t.station) + '" class="rounded-2xl border p-3 ' + cardTone + '">' +
            '<div class="flex items-start justify-between gap-3">' +
                '<div class="min-w-0">' +
                    '<div class="text-sm text-slate-200">Заказ <span class="font-mono text-white">#' + esc(t.order_id) + '</span> · ' + esc(t.table_name || '—') + '</div>' +
                    (orderTypeLabel ? '<div class="text-[11px] text-cyan-200 mt-1">Тип: ' + esc(orderTypeLabel) + '</div>' : '') +
                    (sourceLabel ? '<div class="text-[11px] text-sky-200 mt-1">Источник: ' + esc(sourceLabel) + '</div>' : '') +
                    '<div class="text-xs mt-1 ' + timerClass(createdMin) + '">' + createdMin + ' мин в работе</div>' +
                    (prepExpected > 0 ? '<div class="text-[11px] mt-1 ' + (prepOverdue ? 'text-red-300' : 'text-slate-400') + '">Prep: ' + prepElapsed + '/' + prepExpected + ' мин</div>' : '') +
                '</div>' +
                '<span class="inline-flex items-center px-2 py-0.5 rounded-full border text-[10px] ' + statusBadgeClass(String(t.station_status || 'new')) + '">' + esc(statusLabel(String(t.station_status || 'new'))) + '</span>' +
            '</div>' +
            (orderComment ? '<div class="mt-2 rounded-lg border border-slate-700/80 bg-slate-900/40 px-2 py-1.5 text-[11px] text-slate-300"><span class="text-slate-500">Комментарий:</span> ' + esc(orderComment) + '</div>' : '') +
            '<div class="mt-2 flex flex-wrap gap-1.5">' +
                waiterBadge + partial + splitBadge + stationBadge + stationLinesBadge +
                '<span class="inline-flex items-center px-2 py-0.5 rounded-full border border-emerald-500/40 bg-emerald-500/10 text-[10px] text-emerald-200">Станция: ' + esc(stationProgress) + '</span>' +
                '<span class="inline-flex items-center px-2 py-0.5 rounded-full border border-slate-700 bg-slate-900/50 text-[10px] text-slate-300">Заказ: ' + esc(orderProgress) + '</span>' +
            '</div>' +
            '<ul class="mt-3 space-y-1 text-sm">' +
                items.map(function(it){
                    const qty = Number(it.quantity || 1);
                    return '<li class="flex items-start justify-between gap-3"><span class="text-slate-100">' + esc(it.item_name || it.menu_name || 'Позиция') + '</span><span class="text-slate-300 font-semibold">×' + qty + '</span></li>';
                }).join('') +
            '</ul>' +
            '<div class="mt-2 h-1.5 w-full rounded bg-slate-800"><div class="h-1.5 rounded ' + (prepOverdue ? 'bg-red-400' : 'bg-cyan-400') + '" style="width:' + prepProgress + '%"></div></div>' +
            '<div class="mt-3 flex flex-col gap-2">' +
                actionButton(t) +
                '<button type="button" data-action="notify_waiter" class="w-full min-h-[46px] px-4 py-2 rounded-xl bg-slate-900/80 hover:bg-slate-800 border border-slate-600 text-slate-100 text-xs font-semibold">' + (isBarPanel ? 'Позвать официанта к бару' : 'Позвать официанта') + '</button>' +
            '</div>' +
        '</article>';
    }

    let inFlight = false;
    let seen = new Set();
    let pollTimer = null;
    let pollingStopped = false;
    let pollingFailureCount = 0;

    function stopPolling(messageHtml){
        pollingStopped = true;
        if (pollTimer) {
            clearInterval(pollTimer);
            pollTimer = null;
        }
        if (messageHtml && emptyEl) {
            emptyEl.classList.remove('hidden');
            emptyEl.innerHTML = messageHtml;
        }
    }

    async function fetchTickets(){
        if (pollingStopped) return;
        if (inFlight) return;
        inFlight = true;
        try {
            let url = '/staff/kitchen_api.php?status=all&_ts=' + Date.now();
            const stationForRequest = stationLockedByRole || stationFilter;
            if (stationForRequest !== 'all') {
                url += '&station=' + encodeURIComponent(stationForRequest);
            }
            const r = await fetch(url, { cache: 'no-store', credentials: 'same-origin' });
            if (r.status === 401 || r.status === 403) {
                stopPolling('Сессия завершена или доступ ограничен. Обновите страницу и войдите снова.');
                return;
            }
            if (!r.ok) {
                throw new Error('kds_poll_http_' + r.status);
            }
            const data = await r.json();
            if (!data || !data.success) {
                throw new Error('kds_poll_payload_invalid');
            }
            pollingFailureCount = 0;
            if (pollErrorEl) {
                pollErrorEl.classList.add('hidden');
            }
            const allTicketsRaw = Array.isArray(data.tickets) ? data.tickets : [];
            const seenTicketIds = new Set();
            const allTickets = allTicketsRaw.filter(function(ticket){
                const tid = String((ticket && ticket.ticket_id) || '');
                if (tid === '') return false;
                if (seenTicketIds.has(tid)) return false;
                seenTicketIds.add(tid);
                return true;
            });
            updateCounters(allTickets);
            const tickets = allTickets.filter(matchesStatusFilter).filter(matchesStationFilter);
            renderStationSummary(allTickets);
            renderWorkload(data.station_summary || {}, data.station_alerts || []);

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

            Object.keys(sections).forEach(function(s){
                if (!sections[s]) return;
                sections[s].innerHTML = '';
            });
            let total = 0;
            tickets.forEach(function(t){
                const s = String(t.station || '').toLowerCase();
                if (!sections[s]) return;
                sections[s].insertAdjacentHTML('beforeend', renderCard(t));
                total++;
            });
            emptyEl.classList.toggle('hidden', total > 0);
        } catch (e) {
            pollingFailureCount += 1;
            console.error('[KDS] polling failed', e);
            if (pollErrorEl && pollingFailureCount >= 1) {
                pollErrorEl.classList.remove('hidden');
            }
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
            const requestedStation = btn.dataset.station || 'all';
            if (stationLockedByRole && requestedStation !== stationLockedByRole) {
                return;
            }
            stationFilter = requestedStation;
            setActiveButtons();
            applyStationVisibility();
            persistFilters();
            fetchTickets();
        });
    });
    statusTabs.forEach(function(btn){
        btn.addEventListener('click', function(){
            statusFilter = btn.dataset.status || 'all';
            setActiveButtons();
            persistFilters();
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
    pollTimer = setInterval(fetchTickets, 5000);
})();
</script>
</body>
</html>
