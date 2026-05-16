<?php
// public_html/project-admin/index.php

// ===== Domain guard: project-admin только на основном домене =====
$config     = require __DIR__ . '/../../app/config.php';
$mainDomain = $config['app']['main_domain'] ?? 'localhost';
$protocol   = $config['app']['protocol'] ?? 'http';

$host = $_SERVER['HTTP_HOST'] ?? '';
$host = preg_replace('/:\d+$/', '', $host);

$isMainHost = (strtolower($host) === strtolower($mainDomain))
    || (strtolower($host) === strtolower('www.' . $mainDomain));

if (!$isMainHost) {
    $uri = $_SERVER['REQUEST_URI'] ?? '/project-admin/';
    header('Location: ' . $protocol . '://' . $mainDomain . $uri);
    exit;
}

require_once __DIR__ . '/../../app/bootstrap.php';
if (function_exists('admin_ip_guard')) {
    admin_ip_guard();
}
require_once __DIR__ . '/../../app/schema_guard.php';

require_login();
require_role(['project_owner']);
$currentUser = function_exists('auth_user') ? auth_user() : null;

if (!function_exists('e')) {
    function e($v): string {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

/**
 * Базовый URL арендатора https://{sub}.{mainDomain} или null, если поддомен пустой/некорректный.
 */
function project_admin_tenant_site_base(string $subdomain, string $mainDomain, string $protocol): ?string {
    $sub = trim($subdomain);
    if ($sub === '' || !preg_match('~^[a-z0-9\-]+$~', $sub)) {
        return null;
    }
    $host = preg_replace('/:\d+$/', '', $mainDomain);
    return $protocol . '://' . $sub . '.' . $host;
}

// PDO
$pdo = function_exists('db') ? db() : ($GLOBALS['pdo'] ?? null);
if (!$pdo instanceof PDO) {
    http_response_code(500);
    echo "Ошибка: нет соединения с базой данных.";
    exit;
}


$restaurantsStats = [
    'total'   => 0,
    'active'  => 0,
    'blocked' => 0,
];
try {
    $stmt = $pdo->query("SELECT status, COUNT(*) AS cnt FROM restaurants GROUP BY status");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $status = $r['status'] ?? 'active';
        $cnt    = (int)$r['cnt'];
        $restaurantsStats['total'] += $cnt;
        if ($status === 'active') {
            $restaurantsStats['active'] += $cnt;
        } elseif ($status === 'blocked') {
            $restaurantsStats['blocked'] += $cnt;
        }
    }
} catch (PDOException $e) {

}


$allRestaurants = [];
$allRestaurantsTruncated = false;
try {
    $deletedSql = schema_guard_restaurants_deleted_sql('r');
    $stmt = $pdo->query("
        SELECT r.id, r.name, r.subdomain, r.status, r.created_at, r.owner_user_id,
               u.name AS owner_name, u.email AS owner_email
        FROM restaurants r
        LEFT JOIN users u ON u.id = r.owner_user_id
        WHERE 1=1 {$deletedSql}
        ORDER BY r.id DESC
        LIMIT 501
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows) > 500) {
        $allRestaurantsTruncated = true;
        array_pop($rows);
    }
    $allRestaurants = $rows;
} catch (PDOException $e) {
    $allRestaurants = [];
}

$restaurantStatusLabels = [
    'active'  => 'Активен',
    'blocked' => 'Заблокирован',
];


$userStats = [
    'total'         => 0,
    'project_owner' => 0,
    'user'          => 0,
];
try {
    $stmt = $pdo->query("SELECT global_role, COUNT(*) AS cnt FROM users GROUP BY global_role");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $role = $r['global_role'] ?? 'user';
        $cnt  = (int)$r['cnt'];
        $userStats['total'] += $cnt;
        if (isset($userStats[$role])) {
            $userStats[$role] += $cnt;
        }
    }
} catch (PDOException $e) {

}


$leadsStats = [
    'total'   => 0,
    'today'   => 0,
];
try {

    $stmt = $pdo->query("SELECT COUNT(*) AS cnt FROM lead_requests");
    $leadsStats['total'] = (int)($stmt->fetchColumn() ?: 0);


    $stmt = $pdo->query("SELECT COUNT(*) AS cnt FROM lead_requests WHERE DATE(created_at) = CURDATE()");
    $leadsStats['today'] = (int)($stmt->fetchColumn() ?: 0);
} catch (PDOException $e) {
    $leadsStats = ['total' => 0, 'today' => 0];
}


$recentLeads = [];
try {
    $stmt = $pdo->query("
        SELECT id, name, phone, restaurant_name, persons, comment, created_at
        FROM lead_requests
        ORDER BY id DESC
        LIMIT 5
    ");
    $recentLeads = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $recentLeads = [];
}

$revenueToday      = 0.0;
$revenueTotal      = 0.0;
$ordersToday       = 0;
$ordersPaidToday   = 0;

try {

    $stmt = $pdo->query("
        SELECT COALESCE(SUM(amount),0) AS sum_total
        FROM transactions
        WHERE status = 'succeeded'
    ");
    $revenueTotal = (float)($stmt->fetchColumn() ?: 0.0);
} catch (PDOException $e) {
    $revenueTotal = 0.0;
}

try {

    $stmt = $pdo->query("
        SELECT COALESCE(SUM(amount),0) AS sum_today
        FROM transactions
        WHERE status = 'succeeded'
          AND DATE(created_at) = CURDATE()
    ");
    $revenueToday = (float)($stmt->fetchColumn() ?: 0.0);
} catch (PDOException $e) {
    $revenueToday = 0.0;
}

try {

    $stmt = $pdo->query("
        SELECT COUNT(*) AS cnt FROM orders
        WHERE DATE(created_at) = CURDATE()
    ");
    $ordersToday = (int)($stmt->fetchColumn() ?: 0);
} catch (PDOException $e) {
    $ordersToday = 0;
}

try {
 
    $stmt = $pdo->query("
        SELECT COUNT(*) AS cnt FROM orders
        WHERE DATE(created_at) = CURDATE()
          AND payment_status = 'paid'
    ");
    $ordersPaidToday = (int)($stmt->fetchColumn() ?: 0);
} catch (PDOException $e) {
    $ordersPaidToday = 0;
}


$recentLogs = [];
try {
    $stmt = $pdo->query("
        SELECT l.id, l.created_at, l.level, l.action, l.message,
               l.user_id, u.name AS user_name,
               l.restaurant_id, r.name AS restaurant_name
        FROM logs l
        LEFT JOIN users u ON u.id = l.user_id
        LEFT JOIN restaurants r ON r.id = l.restaurant_id
        ORDER BY l.id DESC
        LIMIT 5
    ");
    $recentLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $recentLogs = [];
}


$appName    = 'QR-Rest Cloud';
$configPath = __DIR__ . '/../../config.php';
if (is_file($configPath)) {
    $cfg = require $configPath;
    if (!empty($cfg['app']['name'])) {
        $appName = $cfg['app']['name'];
    }
}
$ownerName = $currentUser['name'] ?? 'Владелец платформы';
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Главная платформы — <?= e($appName) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?= brand_head_tags() ?>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .float-slow { animation: float-slow 18s ease-in-out infinite; }
        .float-slow-2 { animation: float-slow-2 26s ease-in-out infinite; }
        @keyframes float-slow {
            0%, 100% { transform: translate3d(0, 0, 0) scale(1); }
            50%      { transform: translate3d(18px, -26px, 0) scale(1.03); }
        }
        @keyframes float-slow-2 {
            0%, 100% { transform: translate3d(0, 0, 0) scale(1); }
            50%      { transform: translate3d(-20px, 30px, 0) scale(1.04); }
        }
        .glass-card {
            background: radial-gradient(circle at top left, rgba(45,212,191,0.08), transparent 50%),
                        radial-gradient(circle at bottom right, rgba(59,130,246,0.06), transparent 55%),
                        rgba(15,23,42,0.94);
        }
    </style>
</head>
<body class="min-h-screen bg-slate-950 text-slate-50 overflow-x-hidden">
<div class="min-h-screen relative overflow-hidden">

    <div class="pointer-events-none absolute inset-0">
        <div class="absolute -top-40 -left-32 w-80 h-80 bg-sky-500/25 blur-3xl rounded-full float-slow"></div>
        <div class="absolute bottom-[-9rem] right-[-3rem] w-96 h-96 bg-emerald-500/25 blur-3xl rounded-full float-slow-2"></div>
        <div class="absolute top-1/3 right-12 w-60 h-60 bg-fuchsia-500/30 blur-3xl rounded-full opacity-80"></div>
    </div>

    <div class="relative z-10 max-w-6xl mx-auto px-4 py-6 sm:py-8">

        <?php $platformNavActive = 'home'; require __DIR__ . '/_platform_nav.php'; ?>

        <header class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <?= brand_platform_admin_row_html() ?>
                <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-slate-900/80 border border-slate-700 text-[11px] text-slate-300 mb-2">
                    Главная платформы
                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"></span>
                </div>
                <h1 class="text-2xl sm:text-3xl font-semibold text-slate-50 mb-1">
                    Привет, <?= e($ownerName) ?>
                </h1>
                <p class="text-sm text-slate-400 max-w-xl">
                    Сводка по SaaS, список всех ресторанов с быстрым входом в кабинет по поддомену, заявки и логи.
                </p>
            </div>
            <div class="flex flex-col gap-2 w-full sm:w-auto sm:items-end">
                <div class="px-3 py-2 rounded-2xl bg-slate-900/80 border border-slate-700 text-[11px] text-slate-300 inline-flex items-center">
                    <span class="text-slate-500 mr-1">Платформа</span>
                    <span class="text-slate-50 font-semibold"><?= e($appName) ?></span>
                </div>
                <div class="flex flex-wrap gap-2 justify-start sm:justify-end">
                    <a href="/owner/dashboard.php"
                       class="inline-flex items-center gap-1.5 min-h-[36px] px-3 py-1.5 rounded-xl bg-slate-900/80 border border-slate-700 text-[11px] text-slate-200 hover:border-emerald-500 hover:text-emerald-200 transition touch-manipulation">
                        Панель владельца ресторанов
                    </a>
                    <a href="/project-admin/diagnostics.php"
                       class="inline-flex items-center gap-1.5 min-h-[36px] px-3 py-1.5 rounded-xl bg-slate-900/80 border border-slate-700 text-[11px] text-slate-400 hover:border-sky-500 hover:text-sky-200 transition touch-manipulation">
                        Diagnostics
                    </a>
                    <a href="/project-admin/network_dashboard.php"
                       class="inline-flex items-center gap-1.5 min-h-[36px] px-3 py-1.5 rounded-xl bg-slate-900/80 border border-slate-700 text-[11px] text-slate-400 hover:border-emerald-500 hover:text-emerald-200 transition touch-manipulation">
                        Network
                    </a>
                    <a href="/project-admin/saas_dashboard.php"
                       class="inline-flex items-center gap-1.5 min-h-[36px] px-3 py-1.5 rounded-xl bg-slate-900/80 border border-slate-700 text-[11px] text-slate-400 hover:border-amber-500 hover:text-amber-200 transition touch-manipulation">
                        SaaS Metrics
                    </a>
                    <a href="/project-admin/experiments_dashboard.php"
                       class="inline-flex items-center gap-1.5 min-h-[36px] px-3 py-1.5 rounded-xl bg-slate-900/80 border border-slate-700 text-[11px] text-slate-400 hover:border-emerald-500 hover:text-emerald-200 transition touch-manipulation">
                        Experiments
                    </a>
                </div>
            </div>
        </header>


        <section class="mb-6 grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">

            <div class="glass-card rounded-3xl border border-slate-800/80 p-4 shadow-2xl shadow-slate-950/80">
                <div class="flex items-center justify-between mb-3">
                    <div class="text-xs text-slate-400">Рестораны</div>
                    <a href="/project-admin/restaurants.php"
                       class="text-[11px] text-sky-300 hover:text-sky-100">
                        Управление →
                    </a>
                </div>
                <div class="flex items-end justify-between">
                    <div>
                        <div class="text-2xl font-semibold text-slate-50 mb-1">
                            <?= (int)$restaurantsStats['total'] ?>
                        </div>
                        <div class="text-[11px] text-slate-400">
                            Активных: <span class="text-emerald-300"><?= (int)$restaurantsStats['active'] ?></span>
                            • Заблокировано: <span class="text-rose-300"><?= (int)$restaurantsStats['blocked'] ?></span>
                        </div>
                    </div>
                    <div class="text-right text-[11px] text-slate-500">
                        Мультиаренда<br>через поддомены
                    </div>
                </div>
            </div>


            <div class="glass-card rounded-3xl border border-slate-800/80 p-4 shadow-2xl shadow-slate-950/80">
                <div class="flex items-center justify-between mb-3">
                    <div class="text-xs text-slate-400">Пользователи платформы</div>
                    <a href="/project-admin/users.php"
                       class="text-[11px] text-sky-300 hover:text-sky-100">
                        Открыть список →
                    </a>
                </div>
                <div class="flex items-end justify-between">
                    <div>
                        <div class="text-2xl font-semibold text-slate-50 mb-1">
                            <?= (int)$userStats['total'] ?>
                        </div>
                        <div class="text-[11px] text-slate-400 space-y-0.5">
                            <div>Владельцев платформы: <span class="text-amber-300"><?= (int)$userStats['project_owner'] ?></span></div>
                            <div>Обычных пользователей: <span class="text-slate-200"><?= (int)$userStats['user'] ?></span></div>
                        </div>
                    </div>
                    <div class="text-right text-[11px] text-slate-500">
                        Владельцы, админы,<br>сотрудники
                    </div>
                </div>
            </div>


            <div class="glass-card rounded-3xl border border-slate-800/80 p-4 shadow-2xl shadow-slate-950/80">
                <div class="flex items-center justify-between mb-3">
                    <div class="text-xs text-slate-400">Выручка (по транзакциям)</div>
                    <a href="/project-admin/transactions.php"
                       class="text-[11px] text-sky-300 hover:text-sky-100">
                        Транзакции →
                    </a>
                </div>
                <div class="text-2xl font-semibold text-emerald-300 mb-1">
                    <?= number_format($revenueToday, 0, ',', ' ') ?> ₽
                </div>
                <div class="flex items-center justify-between text-[11px] text-slate-400">
                    <div>Сегодня оплаченных заказов: <span class="text-slate-100"><?= (int)$ordersPaidToday ?></span></div>
                    <div class="text-slate-500">
                        Всего: <span class="text-slate-200"><?= number_format($revenueTotal, 0, ',', ' ') ?> ₽</span>
                    </div>
                </div>
            </div>


            <div class="glass-card rounded-3xl border border-slate-800/80 p-4 shadow-2xl shadow-slate-950/80">
                <div class="flex items-center justify-between mb-3">
                    <div class="text-xs text-slate-400">Заявки с сайта</div>
                    <div class="flex items-center gap-2">
                        <a href="/project-admin/sales_forecast.php" class="text-[11px] text-emerald-300 hover:text-emerald-100">Sales Forecast</a>
                        <a href="/project-admin/lead_scoring.php" class="text-[11px] text-amber-300 hover:text-amber-100">Scoring</a>
                        <a href="/project-admin/leads.php" class="text-[11px] text-sky-300 hover:text-sky-100">Все заявки →</a>
                    </div>
                </div>
                <div class="text-2xl font-semibold text-slate-50 mb-1">
                    <?= (int)$leadsStats['today'] ?>
                </div>
                <div class="flex items-center justify-between text-[11px] text-slate-400">
                    <div>За сегодня</div>
                    <div class="text-slate-500">
                        Всего заявок: <span class="text-slate-200"><?= (int)$leadsStats['total'] ?></span>
                    </div>
                </div>
            </div>
        </section>


        <section class="glass-card rounded-3xl border border-slate-800/80 p-4 sm:p-5 shadow-2xl shadow-slate-950/80 mb-6">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between mb-4">
                <div>
                    <div class="text-xs text-slate-400 mb-0.5">Мультиаренда</div>
                    <h2 class="text-lg font-semibold text-slate-50">
                        Все рестораны
                    </h2>
                    <p class="text-[12px] text-slate-500 mt-1 max-w-2xl">
                        Кабинет, staff и публичное меню открываются на поддомене тенанта.
                        Ссылки активны только при заполненном поддомене.
                    </p>
                </div>
                <div class="flex flex-wrap gap-2 shrink-0">
                    <a href="/project-admin/restaurants.php"
                       class="inline-flex items-center justify-center rounded-xl border border-slate-600 bg-slate-900 px-3 py-2 text-[12px] font-medium text-slate-100 hover:border-emerald-500/80 hover:text-emerald-100 transition">
                        Управление и создание
                    </a>
                </div>
            </div>

            <?php if (!$allRestaurants): ?>
                <div class="rounded-2xl border border-dashed border-slate-700 bg-slate-900/50 px-6 py-10 text-center">
                    <p class="text-sm text-slate-300 mb-1">Ресторанов пока нет</p>
                    <p class="text-[12px] text-slate-500 mb-4">Создай первый ресторан в разделе управления.</p>
                    <a href="/project-admin/restaurants.php"
                       class="inline-flex items-center rounded-xl bg-emerald-500 px-4 py-2 text-sm font-semibold text-slate-950 hover:bg-emerald-400 transition">
                        Перейти к ресторанам
                    </a>
                </div>
            <?php else: ?>
                <?php if ($allRestaurantsTruncated): ?>
                    <p class="text-[11px] text-amber-200/90 mb-3 rounded-xl border border-amber-500/40 bg-amber-500/10 px-3 py-2">
                        Показаны последние 500 ресторанов по ID. Полный список и поиск — в разделе «Рестораны».
                    </p>
                <?php endif; ?>

                <div class="mb-4 space-y-3">
                    <p class="text-[11px] text-slate-500 flex flex-wrap items-center gap-x-1.5 gap-y-1">
                        <span class="font-medium text-slate-400">Основной сценарий:</span>
                        <span class="text-slate-300">платформа</span>
                        <span class="text-slate-600" aria-hidden="true">→</span>
                        <span class="text-slate-300">поддомен</span>
                        <span class="text-slate-600" aria-hidden="true">→</span>
                        <span class="text-emerald-300 font-medium">кабинет ресторана</span>
                        <span class="text-slate-600 hidden sm:inline">(кнопка ниже)</span>
                    </p>
                    <div class="flex flex-col gap-3 lg:flex-row lg:flex-wrap lg:items-end">
                        <div class="flex-1 min-w-[min(100%,220px)]">
                            <label for="rest-admin-q" class="block text-[11px] text-slate-400 mb-1">Поиск по списку</label>
                            <input type="search" id="rest-admin-q" name="rest_admin_q" autocomplete="off"
                                   placeholder="Название, поддомен, владелец, email, ID…"
                                   class="w-full rounded-xl bg-slate-900 border border-slate-700 px-3 py-2 text-sm text-slate-100 placeholder:text-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/40 focus:border-emerald-500/50">
                        </div>
                        <div class="w-full sm:w-44">
                            <label for="rest-admin-status" class="block text-[11px] text-slate-400 mb-1">Статус</label>
                            <select id="rest-admin-status"
                                    class="w-full rounded-xl bg-slate-900 border border-slate-700 px-3 py-2 text-sm text-slate-100 focus:outline-none focus:ring-2 focus:ring-emerald-500/40">
                                <option value="">Все</option>
                                <option value="active">Активен</option>
                                <option value="blocked">Заблокирован</option>
                            </select>
                        </div>
                        <p class="text-[11px] text-slate-500 lg:pb-2" id="rest-admin-count-wrap" aria-live="polite">
                            Показано: <span id="rest-admin-visible" class="text-slate-200 font-medium"><?= count($allRestaurants) ?></span>
                            из <?= count($allRestaurants) ?>
                        </p>
                    </div>
                </div>

                <div class="overflow-auto max-h-[min(70vh,560px)] rounded-2xl border border-slate-800/80 touch-pan-x">
                    <table id="rest-admin-table" class="min-w-[920px] w-full text-left text-[12px] text-slate-300">
                        <thead>
                        <tr class="border-b border-slate-800 bg-slate-950/95 text-[11px] uppercase tracking-wide text-slate-500 sticky top-0 z-20 backdrop-blur-sm shadow-[0_1px_0_0_rgba(30,41,59,0.9)]">
                            <th class="px-3 py-2.5 font-medium">Ресторан</th>
                            <th class="px-3 py-2.5 font-medium">Поддомен</th>
                            <th class="px-3 py-2.5 font-medium">Владелец</th>
                            <th class="px-3 py-2.5 font-medium">Статус</th>
                            <th class="px-3 py-2.5 font-medium">Создан</th>
                            <th class="px-3 py-2.5 font-medium text-right">Действия</th>
                        </tr>
                        </thead>
                        <tbody id="rest-admin-tbody" class="divide-y divide-slate-800/90">
                        <?php foreach ($allRestaurants as $rest): ?>
                            <?php
                            $rid = (int)$rest['id'];
                            $statusKey = $rest['status'] ?? 'active';
                            $statusLabel = $restaurantStatusLabels[$statusKey] ?? $statusKey;
                            $statusClass = $statusKey === 'blocked'
                                ? 'border-rose-500/70 bg-rose-500/10 text-rose-100'
                                : 'border-emerald-500/70 bg-emerald-500/10 text-emerald-100';

                            $createdAt = !empty($rest['created_at'])
                                ? date('d.m.Y H:i', strtotime($rest['created_at']))
                                : '—';

                            $ownerNameDisp = '';
                            $ownerEmailDisp = '';
                            if (!empty($rest['owner_user_id'])) {
                                $ownerNameDisp = $rest['owner_name'] ?: ('User #' . (int)$rest['owner_user_id']);
                                $ownerEmailDisp = (string)($rest['owner_email'] ?? '');
                            }

                            $siteBase = project_admin_tenant_site_base((string)($rest['subdomain'] ?? ''), $mainDomain, $protocol);
                            $cabinetUrl = $siteBase ? $siteBase . '/restaurant/dashboard.php' : '';
                            $staffUrl   = $siteBase ? $siteBase . '/staff/orders.php' : '';
                            $menuUrl    = $siteBase ? $siteBase . '/qr.php' : '';

                            $subDisplay = trim((string)($rest['subdomain'] ?? ''));
                            $subHost    = $subDisplay !== '' ? $subDisplay . '.' . preg_replace('/:\d+$/', '', $mainDomain) : '—';

                            $filterRaw = implode(' ', array_filter([
                                (string)($rest['name'] ?? ''),
                                $subDisplay,
                                $subHost,
                                (string)$rid,
                                $statusKey,
                                $statusLabel,
                                $ownerNameDisp,
                                $ownerEmailDisp,
                            ]));
                            $filterHaystack = function_exists('mb_strtolower')
                                ? mb_strtolower($filterRaw, 'UTF-8')
                                : strtolower($filterRaw);
                            ?>
                            <tr class="js-rest-admin-row bg-slate-950/40 hover:bg-slate-900/70 align-top"
                                data-status="<?= e($statusKey) ?>"
                                data-search="<?= e($filterHaystack) ?>">
                                <td class="px-3 py-3">
                                    <div class="font-medium text-slate-100"><?= e($rest['name']) ?></div>
                                    <div class="text-[11px] text-slate-500">#<?= $rid ?></div>
                                </td>
                                <td class="px-3 py-3 max-w-[12rem] sm:max-w-[16rem]">
                                    <?php if ($subDisplay !== ''): ?>
                                        <code class="text-[11px] text-sky-200 break-all"><?= e($subHost) ?></code>
                                    <?php else: ?>
                                        <span class="text-slate-500">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-3 py-3 text-[11px] text-slate-300 max-w-[14rem] sm:max-w-none">
                                    <?php if ($ownerNameDisp !== ''): ?>
                                        <div class="break-words"><?= e($ownerNameDisp) ?></div>
                                        <?php if ($ownerEmailDisp !== ''): ?>
                                            <div class="text-slate-400 break-all"><?= e($ownerEmailDisp) ?></div>
                                        <?php endif; ?>
                                        <div class="text-[10px] text-slate-500 mt-0.5">user_id <?= (int)$rest['owner_user_id'] ?></div>
                                    <?php else: ?>
                                        <span class="text-slate-500">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-3 py-3">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full border text-[10px] <?= $statusClass ?>">
                                        <?= e($statusLabel) ?>
                                    </span>
                                </td>
                                <td class="px-3 py-3 text-[11px] text-slate-400 whitespace-nowrap"><?= e($createdAt) ?></td>
                                <td class="px-3 py-3 text-right align-middle">
                                    <?php if ($siteBase): ?>
                                        <div class="flex flex-col items-stretch sm:items-end gap-2 min-w-[9.5rem]">
                                            <a href="<?= e($cabinetUrl) ?>" target="_blank" rel="noopener noreferrer"
                                               title="Основной вход в админку ресторана на поддомене"
                                               class="inline-flex justify-center items-center gap-1.5 rounded-xl border border-emerald-400/50 bg-emerald-500/15 px-3 py-2 text-[11px] sm:text-xs font-semibold text-emerald-100 shadow-md shadow-emerald-950/40 hover:bg-emerald-500/25 hover:border-emerald-300/70 transition touch-manipulation min-h-[44px]">
                                                Открыть кабинет
                                                <span class="opacity-80" aria-hidden="true">↗</span>
                                            </a>
                                            <div class="flex flex-wrap justify-end gap-x-2 gap-y-1 text-[10px] text-slate-400">
                                                <a href="<?= e($staffUrl) ?>" target="_blank" rel="noopener noreferrer"
                                                   class="underline decoration-slate-600 underline-offset-2 hover:text-sky-200 touch-manipulation py-1">
                                                    Staff
                                                </a>
                                                <span class="text-slate-600" aria-hidden="true">·</span>
                                                <a href="<?= e($menuUrl) ?>" target="_blank" rel="noopener noreferrer"
                                                   class="underline decoration-slate-600 underline-offset-2 hover:text-fuchsia-200 touch-manipulation py-1">
                                                    Меню
                                                </a>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-[11px] text-slate-500">Задайте поддомен в «Рестораны»</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <tr id="rest-admin-filter-empty" class="hidden">
                            <td colspan="6" class="px-3 py-10 text-center text-sm text-slate-500">
                                Нет ресторанов по фильтру. Измените поиск или статус.
                            </td>
                        </tr>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <section class="grid grid-cols-1 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)] gap-4 mb-6">

            <div class="glass-card rounded-3xl border border-slate-800/80 p-4 shadow-2xl shadow-slate-950/80">
                <div class="flex items-center justify-between mb-3">
                    <div>
                        <div class="text-xs text-slate-400 mb-0.5">
                            Заявки на подключение ресторана
                        </div>
                        <h2 class="text-sm font-semibold text-slate-50">
                            Последние 5 заявок
                        </h2>
                    </div>
                    <a href="/project-admin/leads.php"
                       class="text-[11px] text-sky-300 hover:text-sky-100">
                        Все заявки →
                    </a>
                </div>

                <?php if (!$recentLeads): ?>
                    <div class="text-sm text-slate-500">
                        Заявок пока нет.
                    </div>
                <?php else: ?>
                    <div class="space-y-2 text-xs">
                        <?php foreach ($recentLeads as $lead): ?>
                            <?php
                            $createdAt = $lead['created_at']
                                ? date('d.m.Y H:i', strtotime($lead['created_at']))
                                : '';
                            ?>
                            <div class="rounded-2xl bg-slate-900/80 border border-slate-800 px-3 py-2.5 flex flex-col gap-1.5">
                                <div class="flex items-center justify-between gap-2">
                                    <div class="flex items-center gap-2">
                                        <div class="text-slate-50 font-medium">
                                            <?= e($lead['name']) ?>
                                        </div>
                                        <span class="text-[10px] text-slate-500">
                                            #<?= (int)$lead['id'] ?>
                                        </span>
                                    </div>
                                    <?php if ($createdAt): ?>
                                        <div class="text-[10px] text-slate-500">
                                            <?= e($createdAt) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="text-[11px] text-slate-400">
                                    Телефон: <span class="text-slate-100"><?= e($lead['phone']) ?></span>
                                </div>
                                <?php if (!empty($lead['restaurant_name'])): ?>
                                    <div class="text-[11px] text-slate-400">
                                        Ресторан: <span class="text-slate-100"><?= e($lead['restaurant_name']) ?></span>
                                        <?php if (!empty($lead['persons'])): ?>
                                            • гостей: <span class="text-slate-200"><?= (int)$lead['persons'] ?></span>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($lead['comment'])): ?>
                                    <div class="text-[11px] text-slate-400 line-clamp-2">
                                        Комментарий: <span class="text-slate-200"><?= e($lead['comment']) ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="glass-card rounded-3xl border border-slate-800/80 p-4 shadow-2xl shadow-slate-950/80">
                <div class="flex items-center justify-between mb-3">
                    <div>
                        <div class="text-xs text-slate-400 mb-0.5">
                            Последние события на платформе
                        </div>
                        <h2 class="text-sm font-semibold text-slate-50">
                            Логи (последние 5 записей)
                        </h2>
                    </div>
                    <a href="/project-admin/logs.php"
                       class="text-[11px] text-sky-300 hover:text-sky-100">
                        Все логи →
                    </a>
                </div>

                <?php if (!$recentLogs): ?>
                    <div class="text-sm text-slate-500">
                        Логи ещё не записывались.
                    </div>
                <?php else: ?>
                    <div class="space-y-2 text-xs">
                        <?php foreach ($recentLogs as $log): ?>
                            <?php
                            $dt = $log['created_at']
                                ? date('d.m.Y H:i:s', strtotime($log['created_at']))
                                : '';
                            $lvl = $log['level'] ?? 'info';

                            $badgeClass = 'border-slate-600 bg-slate-900 text-slate-100';
                            if ($lvl === 'info') {
                                $badgeClass = 'border-sky-500/70 bg-sky-500/10 text-sky-100';
                            } elseif ($lvl === 'warning') {
                                $badgeClass = 'border-amber-500/70 bg-amber-500/10 text-amber-100';
                            } elseif ($lvl === 'error') {
                                $badgeClass = 'border-rose-500/70 bg-rose-500/10 text-rose-100';
                            } elseif ($lvl === 'security') {
                                $badgeClass = 'border-fuchsia-500/70 bg-fuchsia-500/10 text-fuchsia-100';
                            } elseif ($lvl === 'payment') {
                                $badgeClass = 'border-emerald-500/70 bg-emerald-500/10 text-emerald-100';
                            }

                            $userLabel = '—';
                            if (!empty($log['user_id'])) {
                                $uname = $log['user_name'] ?: ('User #' . $log['user_id']);
                                $userLabel = $uname . ' (#' . (int)$log['user_id'] . ')';
                            }

                            $restLabel = '—';
                            if (!empty($log['restaurant_id'])) {
                                $rname = $log['restaurant_name'] ?: ('Restaurant #' . $log['restaurant_id']);
                                $restLabel = $rname . ' (#' . (int)$log['restaurant_id'] . ')';
                            }
                            ?>
                            <div class="rounded-2xl bg-slate-900/80 border border-slate-800 px-3 py-2.5 flex flex-col sm:flex-row sm:items-start sm:justify-between gap-2">
                                <div class="flex-1">
                                    <div class="flex flex-wrap items-center gap-2 mb-1">
                                        <span class="text-[11px] text-slate-300">
                                            <?= e($dt) ?>
                                        </span>
                                        <span class="inline-flex items-center gap-1 px-2 py-1 rounded-full border text-[10px] <?= $badgeClass ?>">
                                            <span class="w-1.5 h-1.5 rounded-full bg-current"></span>
                                            <?= e($lvl) ?>
                                        </span>
                                        <span class="text-[11px] text-slate-400">
                                            <?php if ($userLabel !== '—'): ?>
                                                • <?= e($userLabel) ?>
                                            <?php endif; ?>
                                            <?php if ($restLabel !== '—'): ?>
                                                • <?= e($restLabel) ?>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                    <div class="text-[11px] text-slate-100 font-semibold mb-0.5">
                                        <?= e($log['action'] ?: '-') ?>
                                    </div>
                                    <?php if (!empty($log['message'])): ?>
                                        <div class="text-[11px] text-slate-400 whitespace-pre-line line-clamp-2">
                                            <?= nl2br(e($log['message'])) ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="text-[11px] text-slate-500">
                                            Без текста сообщения.
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <a href="/project-admin/qr_themes.php"
           class="block rounded-3xl bg-slate-900/80 border border-slate-800 hover:border-emerald-500/70 hover:bg-slate-900/90 p-4 transition mb-4">
            <div class="text-xs uppercase tracking-wide text-slate-500 mb-1">
                Оформление
            </div>
            <div class="text-sm font-semibold text-slate-50 mb-1">
                QR-темы меню
            </div>
            <div class="text-[11px] text-slate-400">
                Управление базовыми и кастомными темами для QR-меню ресторанов.
            </div>
        </a>

        <footer class="py-3 text-[11px] text-slate-500 flex flex-wrap items-center justify-between gap-2">
            <div>
                <?= e($appName) ?> • Панель владельца платформы
            </div>
            <div>
                Управление ресторанами, пользователями, заявками и логами в одном месте.
            </div>
        </footer>
    </div>
</div>
<script>
(function () {
    var tbody = document.getElementById('rest-admin-tbody');
    var q = document.getElementById('rest-admin-q');
    var st = document.getElementById('rest-admin-status');
    var vis = document.getElementById('rest-admin-visible');
    var empty = document.getElementById('rest-admin-filter-empty');
    if (!tbody || !q || !st) {
        return;
    }

    function norm(s) {
        return String(s || '').toLowerCase().trim();
    }

    function apply() {
        var query = norm(q.value);
        var status = st.value;
        var rows = tbody.querySelectorAll('tr.js-rest-admin-row');
        var n = 0;
        for (var i = 0; i < rows.length; i++) {
            var row = rows[i];
            var okStatus = !status || row.getAttribute('data-status') === status;
            var hay = norm(row.getAttribute('data-search') || '');
            var okSearch = !query || hay.indexOf(query) !== -1;
            var show = okStatus && okSearch;
            row.classList.toggle('hidden', !show);
            if (show) {
                n++;
            }
        }
        if (vis) {
            vis.textContent = String(n);
        }
        if (empty) {
            empty.classList.toggle('hidden', n !== 0);
        }
    }

    q.addEventListener('input', apply);
    q.addEventListener('search', apply);
    st.addEventListener('change', apply);
    apply();
})();
</script>
</body>
</html>
