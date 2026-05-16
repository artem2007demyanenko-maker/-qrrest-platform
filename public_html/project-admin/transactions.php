<?php

require_once __DIR__ . '/../../app/bootstrap.php';


if (!function_exists('e')) {
    function e($v): string {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

// Авторизация
if (function_exists('require_login')) {
    require_login();
}

$currentUser = function_exists('auth_user') ? auth_user() : null;
if (!function_exists('is_project_owner') || !is_project_owner()) {
    http_response_code(403);
    echo "Доступ запрещён (только владелец платформы).";
    exit;
}

// PDO
$pdo = function_exists('db') ? db() : ($GLOBALS['pdo'] ?? null);
if (!$pdo instanceof PDO) {
    http_response_code(500);
    echo "Ошибка: нет соединения с базой данных.";
    exit;
}

// ---------------- ВХОДНЫЕ ПАРАМЕТРЫ ---------------- //

$q          = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$restId     = isset($_GET['restaurant_id']) ? (int)$_GET['restaurant_id'] : 0;
$page       = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage    = 100;
$offset     = ($page - 1) * $perPage;
$exportCsv  = (isset($_GET['export']) && $_GET['export'] === 'csv');

$errMsg     = '';
$rows       = [];
$totalCount = 0;
$amountSum  = 0.0;

// ---------------- ЗАГРУЗКА СПИСКА РЕСТОРАНОВ ДЛЯ ФИЛЬТРА ---------------- //

$restaurantsFilter = [];
try {
    $rs = $pdo->query("
        SELECT id, name, subdomain
        FROM restaurants
        ORDER BY name ASC
    ");
    $restaurantsFilter = $rs->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $restaurantsFilter = [];
}



$whereParts = ['1'];
$params     = [];

// Фильтр по ресторану
if ($restId > 0) {
    $whereParts[]       = 'o.restaurant_id = :rest_id';
    $params[':rest_id'] = $restId;
}

// Поиск по запросу q
if ($q !== '') {
    $or   = [];
    $or[] = 'r.name LIKE :q';
    $or[] = 'r.subdomain LIKE :q';
    $or[] = 'o.customer_phone LIKE :q';
    $or[] = 'o.loyalty_phone LIKE :q';

    $params[':q'] = '%' . $q . '%';


    if (ctype_digit($q)) {
        $or[]            = 'o.id = :qid';
        $or[]            = 'o.table_id = :qid';
        $params[':qid']  = (int)$q;
    }

    $whereParts[] = '(' . implode(' OR ', $or) . ')';
}

$where = implode(' AND ', $whereParts);



try {
    $sqlCount = "
        SELECT COUNT(*) AS cnt
        FROM orders o
        LEFT JOIN restaurants r ON r.id = o.restaurant_id
        WHERE $where
    ";
    $stCount = $pdo->prepare($sqlCount);
    foreach ($params as $k => $v) {
        $stCount->bindValue($k, $v);
    }
    $stCount->execute();
    $totalCount = (int)$stCount->fetchColumn();
} catch (PDOException $e) {
    error_log('PROJECT_ADMIN_TRANSACTIONS_COUNT_ERROR ' . $e->getMessage());
    $errMsg = 'Ошибка при загрузке заказов. Попробуйте позже.';
}



if (!$errMsg && $totalCount > 0) {
    try {
        $sql = "
            SELECT 
                o.*,
                r.name      AS restaurant_name,
                r.subdomain AS restaurant_subdomain,
                t.name      AS table_name
            FROM orders o
            LEFT JOIN restaurants r ON r.id = o.restaurant_id
            LEFT JOIN tables t      ON t.id = o.table_id
            WHERE $where
            ORDER BY o.id DESC
            LIMIT :limit OFFSET :offset
        ";

        $st = $pdo->prepare($sql);

        foreach ($params as $k => $v) {
            $st->bindValue($k, $v);
        }

        $st->bindValue(':limit',  $perPage, PDO::PARAM_INT);
        $st->bindValue(':offset', $offset,  PDO::PARAM_INT);

        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$r) {
            if (isset($r['table_name']) && function_exists('qr_public_owner_order_table_label')) {
                $r['table_name'] = qr_public_owner_order_table_label((string)$r['table_name']);
            }
        }
        unset($r);

        foreach ($rows as $r) {
            $amountSum += (float)($r['total_price'] ?? 0);
        }

    } catch (PDOException $e) {
        error_log('PROJECT_ADMIN_TRANSACTIONS_LIST_ERROR ' . $e->getMessage());
        $errMsg = 'Ошибка при загрузке заказов. Попробуйте позже.';
        $rows   = [];
    }
}



if ($exportCsv && !$errMsg) {
    if (function_exists('add_log')) {
        try {
            add_log($pdo, [
                'user_id'       => (int)$currentUser['id'],
                'restaurant_id' => null,
                'level'         => 'info',
                'action'        => 'export_orders_csv',
                'message'       => 'Экспорт заказов платформы в CSV (строк: ' . count($rows) . ')',
            ]);
        } catch (Throwable $e) {

        }
    }

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="orders-' . date('Ymd-His') . '.csv"');

    $out = fopen('php://output', 'w');

    fwrite($out, "\xEF\xBB\xBF");

    if (!$rows) {
        fputcsv($out, ['no_data']);
    } else {
        $headers = array_keys($rows[0]);
        fputcsv($out, $headers);

        foreach ($rows as $row) {
            $line = [];
            foreach ($headers as $h) {
                $line[] = isset($row[$h]) ? (string)$row[$h] : '';
            }
            fputcsv($out, $line);
        }
    }

    fclose($out);
    exit;
}

// ---------------- НАЗВАНИЕ ПРИЛОЖЕНИЯ ---------------- //

$appName    = 'QR-Rest Cloud';
$configPath = __DIR__ . '/../../config.php';
$cfg        = [];
if (is_file($configPath)) {
    $cfg = require $configPath;
    if (!empty($cfg['app']['name'])) {
        $appName = $cfg['app']['name'];
    }
}

$mainDomain = $cfg['app']['main_domain'] ?? 'qr-rest.site';
$mainHost   = parse_url($mainDomain, PHP_URL_HOST) ?: $mainDomain;

$totalPages = $totalCount > 0 ? (int)ceil($totalCount / $perPage) : 1;
if ($totalPages < 1) $totalPages = 1;

?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Заказы платформы — <?= e($appName) ?></title>
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
    </style>
</head>
<body class="min-h-screen bg-slate-950 text-slate-50">
<div class="min-h-screen relative overflow-hidden">
    <!-- фон -->
    <div class="pointer-events-none absolute inset-0">
        <div class="absolute -top-40 -left-32 w-80 h-80 bg-sky-500/20 blur-3xl rounded-full float-slow"></div>
        <div class="absolute bottom-[-9rem] right-[-3rem] w-96 h-96 bg-emerald-500/20 blur-3xl rounded-full float-slow-2"></div>
        <div class="absolute top-1/3 right-12 w-60 h-60 bg-fuchsia-500/25 blur-3xl rounded-full opacity-80"></div>
    </div>

    <div class="relative z-10 max-w-6xl mx-auto px-4 py-6 sm:py-8">
        <?php $platformNavActive = 'transactions'; require __DIR__ . '/_platform_nav.php'; ?>
        <!-- Хедер -->
        <header class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <div class="flex flex-wrap items-center gap-x-3 gap-y-1 mb-2 text-[11px] text-slate-500">
                    <a href="/project-admin/leads.php" class="hover:text-sky-300">Лиды</a>
                    <a href="/project-admin/sales_forecast.php" class="hover:text-emerald-300">Sales Forecast</a>
                    <a href="/project-admin/diagnostics.php" class="hover:text-slate-200">Diagnostics</a>
                </div>
                <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-slate-900/80 border border-slate-700 text-[11px] text-slate-300 mb-2">
                    Финансы и заказы
                </div>
                <h1 class="text-2xl sm:text-3xl font-semibold text-slate-50 mb-1">
                    Все заказы платформы
                </h1>
                <p class="text-sm text-slate-400 max-w-xl">
                    Сводка заказов по всем ресторанам. Фильтрация по ресторану, поиск по ID, столу и телефону гостя,
                    сумма по текущей странице и экспорт в CSV.
                </p>
            </div>
            <div class="flex flex-col items-start sm:items-end gap-2 text-xs">
                <div class="px-3 py-1 rounded-full bg-slate-900/80 border border-slate-700 text-slate-300">
                    Всего заказов: <span class="text-slate-100 font-semibold"><?= (int)$totalCount ?></span>
                </div>
                <div class="px-3 py-1 rounded-full bg-emerald-500/15 border border-emerald-500/60 text-[11px] text-emerald-100">
                    Сумма на этой странице: <?= number_format($amountSum, 0, '.', ' ') ?> ₽
                </div>
            </div>
        </header>

        <!-- Сообщения -->
        <?php if ($errMsg): ?>
            <div class="mb-4 rounded-2xl border border-rose-500/70 bg-rose-500/10 px-3 py-2 text-xs text-rose-100">
                <?= e($errMsg) ?>
            </div>
        <?php endif; ?>

        <!-- Фильтры -->
        <section class="mb-4 rounded-3xl bg-slate-950/90 border border-slate-800/80 p-4 shadow-lg shadow-slate-950/70">
            <form method="get" class="flex flex-col md:flex-row gap-3 md:items-end md:justify-between">
                <div class="flex-1 grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-[11px] text-slate-400 mb-1">
                            Поиск (ресторан, ID заказа, ID стола, телефон гостя)
                        </label>
                        <input
                            type="text"
                            name="q"
                            value="<?= e($q) ?>"
                            placeholder="Например: 152, 7, +7999..."
                            class="w-full rounded-2xl bg-slate-900 border border-slate-700 px-3 py-2.5 text-sm text-slate-50 placeholder:text-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500"
                        >
                    </div>
                    <div>
                        <label class="block text-[11px] text-slate-400 mb-1">
                            Ресторан
                        </label>
                        <select
                            name="restaurant_id"
                            class="w-full rounded-2xl bg-slate-900 border border-slate-700 px-3 py-2.5 text-sm text-slate-50 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500"
                        >
                            <option value="0">Все рестораны</option>
                            <?php foreach ($restaurantsFilter as $r): ?>
                                <option
                                    value="<?= (int)$r['id'] ?>"
                                    <?= $restId === (int)$r['id'] ? 'selected' : '' ?>
                                >
                                    <?= e($r['name']) ?> (<?= e($r['subdomain']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="flex flex-col sm:flex-row gap-2">
                    <button type="submit"
                            class="inline-flex items-center justify-center px-4 py-2.5 rounded-2xl bg-emerald-500 hover:bg-emerald-400 text-sm font-semibold text-slate-950 shadow-lg shadow-emerald-500/40 transition">
                        Применить фильтры
                    </button>

                    <a href="/project-admin/transactions.php"
                       class="inline-flex items-center justify-center px-3 py-2.5 rounded-2xl bg-slate-900 border border-slate-700 text-sm text-slate-200 hover:bg-slate-800">
                        Сбросить
                    </a>

                    <?php if ($totalCount > 0): ?>
                        <a href="/project-admin/transactions.php?<?= http_build_query(array_merge($_GET, ['export' => 'csv', 'page' => $page])) ?>"
                           class="inline-flex items-center justify-center px-3 py-2.5 rounded-2xl bg-slate-900 border border-emerald-600/70 text-sm text-emerald-200 hover:bg-slate-800">
                            Экспорт CSV (текущая страница)
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </section>

        <!-- Список заказов -->
        <section class="rounded-3xl bg-slate-950/90 border border-slate-800/80 p-4 shadow-xl shadow-slate-950/70">
            <div class="flex items-center justify-between mb-3">
                <div>
                    <div class="text-xs text-slate-400 mb-0.5">
                        Заказы
                        <?php if ($q !== ''): ?>
                            • поиск: «<?= e($q) ?>»
                        <?php endif; ?>
                        <?php if ($restId > 0): ?>
                            • фильтр по ресторану ID <?= (int)$restId ?>
                        <?php endif; ?>
                    </div>
                    <h2 class="text-sm font-semibold text-slate-50">
                        Показано: <?= count($rows) ?> из <?= (int)$totalCount ?>
                    </h2>
                </div>
            </div>

            <?php if (!$rows): ?>
                <div class="text-sm text-slate-500">
                    Заказы не найдены.
                </div>
            <?php else: ?>
                <div class="hidden md:grid md:grid-cols-[minmax(0,2.2fr)_minmax(0,1.4fr)_minmax(0,1.2fr)_minmax(0,1.5fr)_minmax(0,1.7fr)] gap-2 text-[11px] text-slate-400 pb-1 border-b border-slate-800 mb-2">
                    <div>Ресторан</div>
                    <div>Стол / гость</div>
                    <div>Сумма / статус</div>
                    <div>Оплата</div>
                    <div>Лояльность / дата</div>
                </div>

                <div class="space-y-2 text-xs">
                    <?php foreach ($rows as $o): ?>
                        <?php
                        $restName = $o['restaurant_name'] ?? '—';
                        $sub      = $o['restaurant_subdomain'] ?? null;

                        $tableName = $o['table_name'] ?? null;
                        $tableId   = $o['table_id'] ?? null;
                        $tableDisplay = '';
                        if ($tableName !== null && $tableName !== '') {
                            $tableDisplay = function_exists('qr_public_owner_order_table_label')
                                ? qr_public_owner_order_table_label((string)$tableName)
                                : (string)$tableName;
                        }

                        $amount   = (float)($o['total_price'] ?? 0);
                        $amountText = number_format($amount, 0, '.', ' ');

                        $orderId = (int)$o['id'];

                        $payType   = $o['payment_type'] ?? '—';
                        $payStatus = $o['payment_status'] ?? 'unpaid';
                        $orderStatus = $o['order_status'] ?? 'new';

                        $customerName  = $o['customer_name'] ?? '';
                        $customerPhone = $o['customer_phone'] ?? '';

                        $loyPhone   = $o['loyalty_phone'] ?? '';
                        $loyAccr    = (int)($o['loyalty_points_accrued'] ?? 0);
                        $loySpent   = (int)($o['loyalty_points_spent'] ?? 0);
                        $loyBalance = (int)($o['loyalty_points_balance_after'] ?? 0);

                        $created = $o['created_at'] ?? null;

                        // цвет статуса оплаты
                        $payStatusLabel = $payStatus;
                        $payStatusClass = 'bg-slate-900 border-slate-700 text-slate-200';
                        if ($payStatus === 'paid') {
                            $payStatusLabel = 'оплачен';
                            $payStatusClass = 'bg-emerald-500/15 border-emerald-500/60 text-emerald-100';
                        } elseif ($payStatus === 'unpaid') {
                            $payStatusLabel = 'не оплачен';
                            $payStatusClass = 'bg-amber-500/15 border-amber-500/60 text-amber-100';
                        }

                        // статус заказа
                        $orderStatusLabel = $orderStatus;
                        $orderStatusClass = 'bg-slate-900 border-slate-700 text-slate-200';
                        if ($orderStatus === 'new') {
                            $orderStatusLabel = 'новый';
                            $orderStatusClass = 'bg-sky-500/15 border-sky-500/60 text-sky-100';
                        } elseif ($orderStatus === 'in_progress') {
                            $orderStatusLabel = 'готовится';
                            $orderStatusClass = 'bg-amber-500/15 border-amber-500/60 text-amber-100';
                        } elseif ($orderStatus === 'done') {
                            $orderStatusLabel = 'готов';
                            $orderStatusClass = 'bg-emerald-500/15 border-emerald-500/60 text-emerald-100';
                        } elseif ($orderStatus === 'canceled') {
                            $orderStatusLabel = 'отменён';
                            $orderStatusClass = 'bg-rose-500/15 border-rose-500/60 text-rose-100';
                        }
                        ?>
                        <div class="rounded-2xl bg-slate-900/80 border border-slate-800 px-3 py-2.5 flex flex-col md:grid md:grid-cols-[minmax(0,2.2fr)_minmax(0,1.4fr)_minmax(0,1.2fr)_minmax(0,1.5fr)_minmax(0,1.7fr)] gap-2">
                            <!-- Ресторан -->
                            <div class="flex flex-col">
                                <div class="text-slate-100 text-sm">
                                    <?= e($restName) ?>
                                </div>
                                <?php if ($sub): ?>
                                    <div class="text-[11px] text-slate-500">
                                        <?= e($sub) ?>.<?= e($mainHost) ?>
                                    </div>
                                <?php endif; ?>
                                <div class="text-[11px] text-slate-500 mt-0.5">
                                    Заказ #<?= $orderId ?>
                                </div>
                            </div>

                            <!-- Стол / гость -->
                            <div class="flex flex-col">
                                <div class="text-[11px] text-slate-300">
                                    <?php if ($tableDisplay !== ''): ?>
                                        <?= $tableDisplay === 'Доставка' ? 'Доставка' : ('Стол: ' . e($tableDisplay)) ?>
                                    <?php elseif ($tableId): ?>
                                        Стол ID: <?= (int)$tableId ?>
                                    <?php else: ?>
                                        Стол не указан
                                    <?php endif; ?>
                                </div>
                                <?php if ($customerName || $customerPhone): ?>
                                    <div class="text-[11px] text-slate-400 mt-0.5">
                                        <?= e($customerName ?: 'Гость') ?>
                                        <?php if ($customerPhone): ?>
                                            • <?= e($customerPhone) ?>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Сумма / статус заказа -->
                            <div class="flex flex-col gap-1">
                                <div class="text-sm font-semibold text-slate-50">
                                    <?= e($amountText) ?> ₽
                                </div>
                                <?php if ($loySpent > 0): ?>
                                    <div class="text-[11px] text-slate-500">
                                        из <?= number_format($amount + $loySpent, 0, '.', ' ') ?> ₽ · −<?= number_format($loySpent, 0, '.', ' ') ?> бонусов
                                    </div>
                                <?php endif; ?>
                                <span class="inline-flex w-fit px-2 py-1 rounded-full border text-[10px] <?= $orderStatusClass ?>">
                                    <?= e($orderStatusLabel) ?>
                                </span>
                            </div>

                            <!-- Оплата -->
                            <div class="flex flex-col gap-1">
                                <div class="text-[11px] text-slate-300">
                                    Оплата: <?= e($payType) ?>
                                </div>
                                <span class="inline-flex w-fit px-2 py-1 rounded-full border text-[10px] <?= $payStatusClass ?>">
                                    <?= e($payStatusLabel) ?>
                                </span>
                            </div>

                            <!-- Лояльность / дата -->
                            <div class="flex flex-col">
                                <?php if ($loyPhone): ?>
                                    <div class="text-[11px] text-slate-300">
                                        Лояльность: <?= e($loyPhone) ?>
                                    </div>
                                    <div class="text-[11px] text-slate-400">
                                        +<?= $loyAccr ?> / −<?= $loySpent ?> • баланс: <?= $loyBalance ?>
                                    </div>
                                <?php else: ?>
                                    <div class="text-[11px] text-slate-500">
                                        Лояльность не использовалась
                                    </div>
                                <?php endif; ?>
                                <div class="text-[11px] text-slate-400 mt-0.5">
                                    <?= e($created ?: '—') ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Пагинация -->
                <?php if ($totalPages > 1): ?>
                    <div class="mt-4 flex items-center justify-between text-[11px] text-slate-400">
                        <div>
                            Страница <?= (int)$page ?> из <?= (int)$totalPages ?>
                        </div>
                        <div class="flex gap-1">
                            <?php
                            $baseParams = $_GET;
                            unset($baseParams['page']);
                            $baseQuery = http_build_query($baseParams);
                            if ($baseQuery !== '') $baseQuery .= '&';
                            ?>
                            <?php if ($page > 1): ?>
                                <a href="/project-admin/transactions.php?<?= $baseQuery ?>page=<?= $page - 1 ?>"
                                   class="px-3 py-1.5 rounded-full bg-slate-900 border border-slate-700 hover:bg-slate-800">
                                    ← Назад
                                </a>
                            <?php endif; ?>
                            <?php if ($page < $totalPages): ?>
                                <a href="/project-admin/transactions.php?<?= $baseQuery ?>page=<?= $page + 1 ?>"
                                   class="px-3 py-1.5 rounded-full bg-slate-900 border border-slate-700 hover:bg-slate-800">
                                    Вперёд →
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    </div>
</div>
</body>
</html>
