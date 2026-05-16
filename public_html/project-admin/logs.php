<?php

require_once __DIR__ . '/../../app/bootstrap.php';


if (!function_exists('e')) {
    function e($v): string {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}


if (function_exists('require_login')) {
    require_login();
}

$currentUser = function_exists('auth_user') ? auth_user() : null;
if (!function_exists('is_project_owner') || !is_project_owner()) {
    http_response_code(403);
    echo "Доступ запрещён (только владелец платформы).";
    exit;
}


$pdo = function_exists('db') ? db() : ($GLOBALS['pdo'] ?? null);
if (!$pdo instanceof PDO) {
    http_response_code(500);
    echo "Ошибка: нет соединения с базой данных.";
    exit;
}

$q        = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$level    = isset($_GET['level']) ? trim((string)$_GET['level']) : '';
$perPage  = 100;


$levelsList = ['info', 'warning', 'error', 'security', 'payment'];


$levelStats = [];
try {
    $stmt = $pdo->query("SELECT level, COUNT(*) AS cnt FROM logs GROUP BY level");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $lvl = $r['level'] ?: 'info';
        $levelStats[$lvl] = (int)$r['cnt'];
    }
} catch (PDOException $e) {
    $levelStats = [];
}


$logs = [];
try {
    $where  = '1';
    $params = [];

    if ($q !== '') {
        $whereParts = [];
        $whereParts[]         = 'l.action LIKE :q_action';
        $params[':q_action']  = '%' . $q . '%';
        $whereParts[]         = 'l.message LIKE :q_msg';
        $params[':q_msg']     = '%' . $q . '%';

        $where = '(' . implode(' OR ', $whereParts) . ')';
    }

    if ($level !== '' && in_array($level, $levelsList, true)) {
        $where .= ' AND l.level = :lvl';
        $params[':lvl'] = $level;
    }

    $sql = "
        SELECT 
            l.id,
            l.created_at,
            l.level,
            l.action,
            l.message,
            l.ip_address,
            l.user_agent,
            l.user_id,
            u.name  AS user_name,
            u.email AS user_email,
            l.restaurant_id,
            r.name      AS restaurant_name,
            r.subdomain AS restaurant_subdomain
        FROM logs l
        LEFT JOIN users u ON u.id = l.user_id
        LEFT JOIN restaurants r ON r.id = l.restaurant_id
        WHERE $where
        ORDER BY l.id DESC
        LIMIT {$perPage}
    ";

    if (!empty($params)) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    } else {
        $stmt = $pdo->query($sql);
    }

    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $logs = [];
    error_log('PROJECT_ADMIN_LOGS_LOAD_ERROR ' . $e->getMessage());
    $errMsg = 'Ошибка при загрузке логов. Попробуйте позже.';
}


$appName    = 'QR-Rest Cloud';
$configPath = __DIR__ . '/../../config.php';
if (is_file($configPath)) {
    $cfg = require $configPath;
    if (!empty($cfg['app']['name'])) {
        $appName = $cfg['app']['name'];
    }
}
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Логи платформы — <?= e($appName) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?= brand_head_tags() ?>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .float-slow { animation: float-slow 18s ease-in-out infinite; }
        .float-slow-2 { animation: float-slow-2 26s ease-in-out infinite; }
        @keyframes float-slow {
            0%, 100% { transform: translate3d(0, 0, 0) scale(1); }
            50%      { transform: translate3d(16px, -24px, 0) scale(1.03); }
        }
        @keyframes float-slow-2 {
            0%, 100% { transform: translate3d(0, 0, 0) scale(1); }
            50%      { transform: translate3d(-20px, 28px, 0) scale(1.04); }
        }
        .log-level-badge {
            font-size: 10px;
            border-radius: 9999px;
            padding: 2px 8px;
            border-width: 1px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
    </style>
</head>
<body class="min-h-screen bg-slate-950 text-slate-50">
<div class="min-h-screen relative overflow-hidden">

    <div class="pointer-events-none absolute inset-0">
        <div class="absolute -top-40 -left-32 w-80 h-80 bg-sky-500/20 blur-3xl rounded-full float-slow"></div>
        <div class="absolute bottom-[-9rem] right-[-3rem] w-96 h-96 bg-emerald-500/20 blur-3xl rounded-full float-slow-2"></div>
        <div class="absolute top-1/3 right-12 w-60 h-60 bg-fuchsia-500/25 blur-3xl rounded-full opacity-80"></div>
    </div>

    <div class="relative z-10 max-w-6xl mx-auto px-4 py-6 sm:py-8">
        <?php $platformNavActive = 'logs'; require __DIR__ . '/_platform_nav.php'; ?>

        <header class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <div class="flex flex-wrap items-center gap-x-3 gap-y-1 mb-2 text-[11px] text-slate-500">
                    <a href="/project-admin/leads.php" class="hover:text-sky-300">Лиды</a>
                    <a href="/project-admin/sales_forecast.php" class="hover:text-emerald-300">Sales Forecast</a>
                    <a href="/project-admin/diagnostics.php" class="hover:text-slate-200">Diagnostics</a>
                </div>
                <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-slate-900/80 border border-slate-700 text-[11px] text-slate-300 mb-2">
                    Логи действий
                </div>
                <h1 class="text-2xl sm:text-3xl font-semibold text-slate-50 mb-1">
                    Логи платформы
                </h1>
                <p class="text-sm text-slate-400 max-w-xl">
                    Последние события на платформе: авторизации, заказы, платежи, безопасность. Фильтруй по уровню и по тексту.
                </p>
            </div>
            <div class="flex flex-col items-start sm:items-end gap-2 text-xs">
                <div class="px-3 py-1 rounded-full bg-slate-900/80 border border-slate-700 text-slate-300">
                    Показано до <?= (int)$perPage ?> последних записей
                </div>
                <div class="flex gap-2 flex-wrap">
                    <?php foreach ($levelsList as $lvl): ?>
                        <div class="px-2 py-1 rounded-full bg-slate-900 border border-slate-700 text-[11px] text-slate-300">
                            <?= e($lvl) ?>: <span class="text-slate-50"><?= (int)($levelStats[$lvl] ?? 0) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </header>


        <?php if (!empty($errMsg)): ?>
            <div class="mb-4 rounded-2xl border border-rose-500/70 bg-rose-500/10 px-3 py-2 text-xs text-rose-100">
                <?= e($errMsg) ?>
            </div>
        <?php endif; ?>


        <section class="mb-4 rounded-3xl bg-slate-950/90 border border-slate-800/80 p-4 shadow-lg shadow-slate-950/70">
            <form method="get" action="/project-admin/logs.php"
                  class="flex flex-col sm:flex-row gap-3 items-stretch sm:items-end justify-between">
                <div class="flex-1">
                    <label class="block text-[11px] text-slate-400 mb-1">
                        Поиск по действию или тексту сообщения
                    </label>
                    <div class="relative">
                        <input
                            type="text"
                            name="q"
                            value="<?= e($q) ?>"
                            placeholder="Например: login, order_create, payment_succeeded"
                            class="w-full rounded-2xl bg-slate-900 border border-slate-700 px-3 py-2.5 text-sm text-slate-50 placeholder:text-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500"
                        >
                        <?php if ($q !== '' || $level !== ''): ?>
                            <a href="/project-admin/logs.php"
                               class="absolute inset-y-0 right-2 flex items-center text-[11px] text-slate-500 hover:text-slate-200">
                                сброс
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="w-full sm:w-48">
                    <label class="block text-[11px] text-slate-400 mb-1">
                        Уровень
                    </label>
                    <select name="level"
                            class="w-full rounded-2xl bg-slate-900 border border-slate-700 px-3 py-2.5 text-sm text-slate-50 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                        <option value="">Все уровни</option>
                        <?php foreach ($levelsList as $lvl): ?>
                            <option value="<?= e($lvl) ?>" <?= $lvl === $level ? 'selected' : '' ?>>
                                <?= e($lvl) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit"
                        class="inline-flex items-center justify-center px-4 py-2.5 rounded-2xl bg-emerald-500 hover:bg-emerald-400 text-sm font-semibold text-slate-950 shadow-lg shadow-emerald-500/40 transition">
                    Применить фильтры
                </button>
            </form>
        </section>


        <section class="rounded-3xl bg-slate-950/90 border border-slate-800/80 p-4 shadow-xl shadow-slate-950/70">
            <div class="flex items-center justify-between mb-3">
                <div>
                    <div class="text-xs text-slate-400 mb-0.5">
                        Последние события
                        <?php if ($q !== ''): ?>
                            • поиск: «<?= e($q) ?>»
                        <?php endif; ?>
                        <?php if ($level !== ''): ?>
                            • уровень: <?= e($level) ?>
                        <?php endif; ?>
                    </div>
                    <h2 class="text-sm font-semibold text-slate-50">
                        Записей: <?= count($logs) ?>
                    </h2>
                </div>
            </div>

            <?php if (!$logs): ?>
                <div class="text-sm text-slate-500">
                    Логи не найдены.
                </div>
            <?php else: ?>
                <div class="hidden md:grid md:grid-cols-[minmax(0,1.3fr)_minmax(0,1.6fr)_minmax(0,1.5fr)_minmax(0,3fr)] gap-2 text-[11px] text-slate-400 pb-1 border-b border-slate-800 mb-2">
                    <div>Время / уровень</div>
                    <div>Пользователь</div>
                    <div>Ресторан</div>
                    <div>Действие / сообщение</div>
                </div>

                <div class="space-y-2 text-xs">
                    <?php foreach ($logs as $log): ?>
                        <?php
                        $dt = $log['created_at']
                            ? date('d.m.Y H:i:s', strtotime($log['created_at']))
                            : '';

                        $lvl = $log['level'] ?? 'info';
                        $levelLabel = $lvl;
                        $levelStyle = 'border-slate-600 bg-slate-900 text-slate-100';

                        if ($lvl === 'info') {
                            $levelStyle = 'border-sky-500/70 bg-sky-500/10 text-sky-100';
                        } elseif ($lvl === 'warning') {
                            $levelStyle = 'border-amber-500/70 bg-amber-500/10 text-amber-100';
                        } elseif ($lvl === 'error') {
                            $levelStyle = 'border-rose-500/70 bg-rose-500/10 text-rose-100';
                        } elseif ($lvl === 'security') {
                            $levelStyle = 'border-fuchsia-500/70 bg-fuchsia-500/10 text-fuchsia-100';
                        } elseif ($lvl === 'payment') {
                            $levelStyle = 'border-emerald-500/70 bg-emerald-500/10 text-emerald-100';
                        }

                        $userDisplay = '—';
                        if (!empty($log['user_id'])) {
                            $uname = $log['user_name'] ?: ('User #' . $log['user_id']);
                            if (!empty($log['user_email'])) {
                                $userDisplay = $uname . ' (' . $log['user_email'] . ')';
                            } else {
                                $userDisplay = $uname;
                            }
                        }

                        $restDisplay = '—';
                        if (!empty($log['restaurant_id'])) {
                            $rname = $log['restaurant_name'] ?: ('ID ' . $log['restaurant_id']);
                            if (!empty($log['restaurant_subdomain'])) {
                                $restDisplay = $rname . ' [' . $log['restaurant_subdomain'] . ']';
                            } else {
                                $restDisplay = $rname;
                            }
                        }

                        $ip = $log['ip_address'] ?: '';
                        ?>
                        <div class="rounded-2xl bg-slate-900/80 border border-slate-800 px-3 py-2.5 flex flex-col md:grid md:grid-cols-[minmax(0,1.3fr)_minmax(0,1.6fr)_minmax(0,1.5fr)_minmax(0,3fr)] gap-2">
                        
                            <div>
                                <div class="flex items-center gap-2 mb-0.5">
                                    <div class="text-[11px] text-slate-300">
                                        <?= e($dt) ?>
                                    </div>
                                </div>
                                <div>
                                    <span class="log-level-badge border <?= $levelStyle ?>">
                                        <span class="w-1.5 h-1.5 rounded-full bg-current"></span>
                                        <?= e($levelLabel) ?>
                                    </span>
                                </div>
                                <?php if ($ip): ?>
                                    <div class="mt-1 text-[10px] text-slate-500">
                                        IP: <?= e($ip) ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                     
                            <div class="md:flex md:flex-col md:justify-center">
                                <div class="text-slate-100 text-[11px] md:text-xs">
                                    <?= e($userDisplay) ?>
                                </div>
                                <?php if (!empty($log['user_id'])): ?>
                                    <div class="text-[10px] text-slate-500">
                                        ID: <?= (int)$log['user_id'] ?>
                                    </div>
                                <?php endif; ?>
                            </div>

               
                            <div class="md:flex md:flex-col md:justify-center">
                                <div class="text-slate-100 text-[11px] md:text-xs">
                                    <?= e($restDisplay) ?>
                                </div>
                                <?php if (!empty($log['restaurant_id'])): ?>
                                    <div class="text-[10px] text-slate-500">
                                        ID: <?= (int)$log['restaurant_id'] ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                        
                            <div class="md:flex md:flex-col md:justify-center">
                                <div class="text-slate-100 text-[11px] md:text-xs mb-1">
                                    <span class="font-semibold text-slate-50">
                                        <?= e($log['action'] ?: '-') ?>
                                    </span>
                                </div>
                                <?php if (!empty($log['message'])): ?>
                                    <div class="text-[11px] text-slate-400 whitespace-pre-line max-h-32 overflow-y-auto">
                                        <?= nl2br(e($log['message'])) ?>
                                    </div>
                                <?php else: ?>
                                    <div class="text-[11px] text-slate-500">
                                        Нет текста сообщения.
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>
</div>
</body>
</html>
