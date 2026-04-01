<?php


ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);


require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/billing.php';

if (!function_exists('e')) {
    function e($v): string {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}


if (function_exists('require_login')) {
    require_login();
}

$currentUser = function_exists('auth_user') ? auth_user() : null;
if (!$currentUser || ($currentUser['global_role'] ?? null) !== 'project_owner') {
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

$appConfigPath = __DIR__ . '/../../config.php';
$appName       = 'QR-Rest Cloud';
$mainDomain    = 'domain.ru';

if (is_file($appConfigPath)) {
    $cfg = require $appConfigPath;
    if (!empty($cfg['app']['name'])) {
        $appName = $cfg['app']['name'];
    }
    if (!empty($cfg['app']['main_domain'])) {
        $mainDomain = $cfg['app']['main_domain'];
    }
}


if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

$q        = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$status   = isset($_GET['status']) ? trim((string)$_GET['status']) : '';
$editId   = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$saved    = isset($_GET['saved']) ? (int)$_GET['saved'] : 0;
$errMsg   = '';
$okMsg    = '';


$restaurantStatuses = [
    'active'  => 'Активен',
    'blocked' => 'Заблокирован',
];


$ownersList = [];
try {
    $stmt = $pdo->query("
        SELECT id, name, email, global_role
        FROM users
        ORDER BY id DESC
        LIMIT 500
    ");
    $ownersList = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $ownersList = [];
}


function owner_label(?array $row): string {
    if (!$row) return '—';
    $name = $row['name'] ?: ('User #' . $row['id']);
    if (!empty($row['email'])) {
        $name .= ' (' . $row['email'] . ')';
    }
    return $name;
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_restaurant') {
    $csrfOk = isset($_POST['csrf'], $_SESSION['csrf']) && hash_equals((string)$_SESSION['csrf'], (string)$_POST['csrf']);
    if (!$csrfOk) {
        $errMsg = 'Неверный запрос. Обновите страницу и попробуйте снова.';
    } else {
    $name       = trim((string)($_POST['name'] ?? ''));
    $subdomain  = trim((string)($_POST['subdomain'] ?? ''));
    $ownerId    = isset($_POST['owner_user_id']) ? (int)$_POST['owner_user_id'] : 0;
    $statusPost = ($_POST['status'] ?? 'active');
    $statusVal  = array_key_exists($statusPost, $restaurantStatuses) ? $statusPost : 'active';

    if ($name === '') {
        $errMsg = 'Укажите название ресторана.';
    } elseif ($subdomain === '') {
        $errMsg = 'Укажите поддомен (slug).';
    } elseif (!preg_match('~^[a-z0-9\-]+$~', $subdomain)) {
        $errMsg = 'Поддомен может содержать только латиницу, цифры и дефис.';
    } else {

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM restaurants WHERE subdomain = :slug AND (deleted_at IS NULL)");
        $stmt->execute([':slug' => $subdomain]);
        $exists = (int)$stmt->fetchColumn();

        if ($exists > 0) {
            $errMsg = 'Такой поддомен уже используется другим рестораном.';
        } else {

            if ($ownerId > 0) {
                $stmt = $pdo->prepare("SELECT id FROM users WHERE id = :id LIMIT 1");
                $stmt->execute([':id' => $ownerId]);
                if (!$stmt->fetchColumn()) {
                    $ownerId = 0;
                }
            }

            if ($errMsg === '') {
                try {
                    $pdo->beginTransaction();
                    if ($ownerId > 0) {
                        $assert = billing_assert_can_create_restaurant($ownerId, $pdo);
                        if (!($assert['ok'] ?? false)) {
                            $pdo->rollBack();
                            $errMsg = $assert['reason'] ?? 'Достигнут лимит ресторанов по тарифу владельца. Владелец может сменить тариф в разделе «Тарифы» (/owner/billing.php).';
                        }
                    }
                    if ($errMsg === '') {
                        $stmt = $pdo->prepare("
                            INSERT INTO restaurants (name, subdomain, owner_user_id, status, created_at)
                            VALUES (:name, :subdomain, :owner, :status, NOW())
                        ");
                        $stmt->execute([
                            ':name'      => $name,
                            ':subdomain' => $subdomain,
                            ':owner'     => $ownerId ?: null,
                            ':status'    => $statusVal,
                        ]);
                        $newId = (int)$pdo->lastInsertId();
                        $pdo->commit();
                        if (function_exists('audit_log')) {
                            require_once __DIR__ . '/../../app/audit.php';
                            audit_log('restaurant_create', 'restaurant', (string)$newId);
                        }
                        header('Location: /project-admin/restaurants.php?edit=' . $newId . '&saved=1');
                        exit;
                    }
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $errMsg = 'Ошибка при создании ресторана. Попробуйте позже.';
                }
            }
        }
    }
    }
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_restaurant') {
    $csrfOk = isset($_POST['csrf'], $_SESSION['csrf']) && hash_equals((string)$_SESSION['csrf'], (string)$_POST['csrf']);
    if (!$csrfOk) {
        $errMsg = 'Неверный запрос. Обновите страницу и попробуйте снова.';
    } else {
    $id         = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $name       = trim((string)($_POST['name'] ?? ''));
    $subdomain  = trim((string)($_POST['subdomain'] ?? ''));
    $ownerId    = isset($_POST['owner_user_id']) ? (int)$_POST['owner_user_id'] : 0;
    $statusPost = ($_POST['status'] ?? 'active');
    $statusVal  = array_key_exists($statusPost, $restaurantStatuses) ? $statusPost : 'active';

    if ($id <= 0) {
        $errMsg = 'Неверный ID ресторана.';
    } else {
        $stmt = $pdo->prepare("SELECT * FROM restaurants WHERE id = :id AND (deleted_at IS NULL)");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            $errMsg = 'Ресторан не найден.';
        } else {
            if ($name === '') {
                $name = $row['name'] ?: 'Restaurant #' . $row['id'];
            }
            if ($subdomain === '') {
                $subdomain = $row['subdomain'];
            }

            if (!preg_match('~^[a-z0-9\-]+$~', $subdomain)) {
                $errMsg = 'Поддомен может содержать только латиницу, цифры и дефис.';
            } else {

                $stmt = $pdo->prepare("
                    SELECT COUNT(*) FROM restaurants
                    WHERE subdomain = :slug AND id <> :id AND (deleted_at IS NULL)
                ");
                $stmt->execute([
                    ':slug' => $subdomain,
                    ':id'   => $id,
                ]);
                $exists = (int)$stmt->fetchColumn();

                if ($exists > 0) {
                    $errMsg = 'Такой поддомен уже используется другим рестораном.';
                } else {

                    if ($ownerId > 0) {
                        $stmt = $pdo->prepare("SELECT id FROM users WHERE id = :id LIMIT 1");
                        $stmt->execute([':id' => $ownerId]);
                        if (!$stmt->fetchColumn()) {
                            $ownerId = 0;
                        }
                    }

                    try {
                        $stmt = $pdo->prepare("
                            UPDATE restaurants
                            SET name = :name,
                                subdomain = :subdomain,
                                owner_user_id = :owner,
                                status = :status
                            WHERE id = :id
                        ");
                        $stmt->execute([
                            ':name'      => $name,
                            ':subdomain' => $subdomain,
                            ':owner'     => $ownerId ?: null,
                            ':status'    => $statusVal,
                            ':id'        => $id,
                        ]);

                        $okMsg = 'Изменения сохранены.';
                        header('Location: /project-admin/restaurants.php?edit=' . $id . '&saved=1');
                        exit;

                    } catch (PDOException $e) {
                        $errMsg = 'Ошибка при сохранении ресторана. Попробуйте позже.';
                        if (function_exists('error_log')) {
                            error_log('restaurants update_restaurant ' . $e->getMessage());
                        }
                    }
                }
            }
        }
    }
    }
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
        $st  = $r['status'] ?? 'active';
        $cnt = (int)$r['cnt'];
        $restaurantsStats['total'] += $cnt;
        if ($st === 'active') {
            $restaurantsStats['active'] += $cnt;
        } elseif ($st === 'blocked') {
            $restaurantsStats['blocked'] += $cnt;
        }
    }
} catch (PDOException $e) {

}

$restaurants = [];
try {
    $where  = '1';
    $params = [];

    if ($q !== '') {
        $whereParts = [];
        $whereParts[]         = 'r.name LIKE :q_name';
        $params[':q_name']    = '%' . $q . '%';
        $whereParts[]         = 'r.subdomain LIKE :q_sub';
        $params[':q_sub']     = '%' . $q . '%';
        $whereParts[]         = 'u.email LIKE :q_email';
        $params[':q_email']   = '%' . $q . '%';

        if (ctype_digit($q)) {
            $whereParts[]     = 'r.id = :q_id';
            $params[':q_id']  = (int)$q;
        }

        $where = '(' . implode(' OR ', $whereParts) . ')';
    }

    if ($status === 'active' || $status === 'blocked') {
        $where .= ' AND r.status = :st';
        $params[':st'] = $status;
    }

    $sql = "
        SELECT
            r.id,
            r.name,
            r.subdomain,
            r.status,
            r.created_at,
            r.owner_user_id,
            u.name  AS owner_name,
            u.email AS owner_email
        FROM restaurants r
        LEFT JOIN users u ON u.id = r.owner_user_id
        WHERE $where
        ORDER BY r.id DESC
        LIMIT 500
    ";

    if ($params) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    } else {
        $stmt = $pdo->query($sql);
    }

    $restaurants = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $restaurants = [];
    $errMsg      = 'Ошибка при загрузке списка ресторанов. Попробуйте позже.';
    if (function_exists('error_log')) {
        error_log('restaurants list ' . $e->getMessage());
    }
}

$editRestaurant = null;
if ($editId > 0) {
    foreach ($restaurants as $r) {
        if ((int)$r['id'] === $editId) {
            $editRestaurant = $r;
            break;
        }
    }
}
if ($saved && !$errMsg && !$okMsg) {
    $okMsg = 'Изменения сохранены.';
}


?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Рестораны — Панель владельца платформы — <?= e($appName) ?></title>
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
        .glass-card {
            background: radial-gradient(circle at top left, rgba(45,212,191,0.08), transparent 50%),
                        radial-gradient(circle at bottom right, rgba(59,130,246,0.06), transparent 55%),
                        rgba(15,23,42,0.94);
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
        <!-- Хедер -->
        <header class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <div class="flex flex-wrap items-center gap-2 mb-2">
                    <a href="/project-admin/index.php" class="text-[11px] text-slate-500 hover:text-emerald-300">← Панель</a>
                    <span class="text-slate-600">|</span>
                    <a href="/project-admin/leads.php" class="text-[11px] text-sky-400 hover:text-sky-300">Лиды</a>
                    <a href="/project-admin/sales_forecast.php" class="text-[11px] text-emerald-400 hover:text-emerald-300">Sales Forecast</a>
                    <a href="/project-admin/diagnostics.php" class="text-[11px] text-slate-400 hover:text-slate-200">Diagnostics</a>
                </div>
                <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-slate-900/80 border border-slate-700 text-[11px] text-slate-300 mb-2">
                    Управление ресторанами
                </div>
                <h1 class="text-2xl sm:text-3xl font-semibold text-slate-50 mb-1">
                    Рестораны платформы
                </h1>
                <p class="text-sm text-slate-400 max-w-xl">
                    Создавай рестораны, привязывай владельцев и управляй статусом подключения. Все рестораны работают через поддомены одного домена.
                </p>
            </div>
            <div class="flex flex-col items-start sm:items-end gap-2 text-xs">
                <div class="px-3 py-1 rounded-full bg-slate-900/80 border border-slate-700 text-slate-300">
                    Всего ресторанов: <span class="text-slate-50 font-semibold"><?= (int)$restaurantsStats['total'] ?></span>
                </div>
                <div class="flex gap-2 flex-wrap">
                    <div class="px-2 py-1 rounded-full bg-emerald-500/15 border border-emerald-500/60 text-[11px] text-emerald-100">
                        Активных: <?= (int)$restaurantsStats['active'] ?>
                    </div>
                    <div class="px-2 py-1 rounded-full bg-rose-500/15 border border-rose-500/60 text-[11px] text-rose-100">
                        Заблокировано: <?= (int)$restaurantsStats['blocked'] ?>
                    </div>
                </div>
            </div>
        </header>

        <!-- Сообщения -->
        <?php if ($errMsg): ?>
            <div class="mb-4 rounded-2xl border border-rose-500/70 bg-rose-500/10 px-3 py-2 text-xs text-rose-100">
                <?= e($errMsg) ?>
            </div>
        <?php elseif ($okMsg): ?>
            <div class="mb-3 rounded-2xl border border-emerald-500/70 bg-emerald-500/10 px-3 py-2 text-xs text-emerald-100">
                <?= e($okMsg) ?>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-[minmax(0,3fr)_minmax(0,2.4fr)] gap-4">
            <!-- ЛЕВО: поиск + список ресторанов -->
            <div class="space-y-4">
                <!-- Поиск и фильтры -->
                <section class="glass-card rounded-3xl border border-slate-800/80 p-4 shadow-lg shadow-slate-950/70">
                    <form method="get" action="/project-admin/restaurants.php"
                          class="flex flex-col sm:flex-row gap-3 items-stretch sm:items-end justify-between">
                        <div class="flex-1">
                            <label class="block text-[11px] text-slate-400 mb-1">
                                Поиск по названию, поддомену, e-mail владельца или ID
                            </label>
                            <div class="relative">
                                <input
                                    type="text"
                                    name="q"
                                    value="<?= e($q) ?>"
                                    placeholder="Например: Pizza Town, pizza-town, owner@mail.ru или 12"
                                    class="w-full rounded-2xl bg-slate-900 border border-slate-700 px-3 py-2.5 text-sm text-slate-50 placeholder:text-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500"
                                >
                                <?php if ($q !== '' || $status !== ''): ?>
                                    <a href="/project-admin/restaurants.php"
                                       class="absolute inset-y-0 right-2 flex items-center text-[11px] text-slate-500 hover:text-slate-200">
                                        сброс
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="w-full sm:w-48">
                            <label class="block text-[11px] text-slate-400 mb-1">
                                Статус
                            </label>
                            <select name="status"
                                    class="w-full rounded-2xl bg-slate-900 border border-slate-700 px-3 py-2.5 text-sm text-slate-50 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                                <option value="">Все</option>
                                <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Активен</option>
                                <option value="blocked" <?= $status === 'blocked' ? 'selected' : '' ?>>Заблокирован</option>
                            </select>
                        </div>
                        <button type="submit"
                                class="inline-flex items-center justify-center px-4 py-2.5 rounded-2xl bg-emerald-500 hover:bg-emerald-400 text-sm font-semibold text-slate-950 shadow-lg shadow-emerald-500/40 transition">
                            Найти
                        </button>
                    </form>
                </section>

                <!-- Список ресторанов -->
                <section class="glass-card rounded-3xl border border-slate-800/80 p-4 shadow-xl shadow-slate-950/70">
                    <div class="flex items-center justify-between mb-3">
                        <div>
                            <div class="text-xs text-slate-400 mb-0.5">
                                Рестораны
                                <?php if ($q !== ''): ?>
                                    • поиск: «<?= e($q) ?>»
                                <?php endif; ?>
                                <?php if ($status === 'active'): ?>
                                    • только активные
                                <?php elseif ($status === 'blocked'): ?>
                                    • только заблокированные
                                <?php endif; ?>
                            </div>
                            <h2 class="text-sm font-semibold text-slate-50">
                                Найдено: <?= count($restaurants) ?>
                            </h2>
                        </div>
                        <div class="text-[11px] text-slate-500">
                            Один код → много поддоменов
                        </div>
                    </div>

                    <?php if (!$restaurants): ?>
                        <div class="text-sm text-slate-500">
                            Рестораны не найдены.
                        </div>
                    <?php else: ?>
                        <div class="hidden md:grid md:grid-cols-[minmax(0,2.2fr)_minmax(0,2.3fr)_minmax(0,2.3fr)_minmax(0,1.2fr)] gap-2 text-[11px] text-slate-400 pb-1 border-b border-slate-800 mb-2">
                            <div>Ресторан</div>
                            <div>Поддомен</div>
                            <div>Владелец</div>
                            <div>Статус</div>
                        </div>

                        <div class="space-y-2 text-xs">
                            <?php foreach ($restaurants as $r): ?>
                                <?php
                                $rid         = (int)$r['id'];
                                $isActiveRow = ($editId === $rid);

                                $statusKey   = $r['status'] ?? 'active';
                                $statusLabel = $restaurantStatuses[$statusKey] ?? $statusKey;
                                $statusClass = $statusKey === 'blocked'
                                    ? 'border-rose-500/70 bg-rose-500/10 text-rose-100'
                                    : 'border-emerald-500/70 bg-emerald-500/10 text-emerald-100';

                                $createdAt = $r['created_at']
                                    ? date('d.m.Y H:i', strtotime($r['created_at']))
                                    : '';

                                $ownerText = '—';
                                if (!empty($r['owner_user_id'])) {
                                    $oname = $r['owner_name'] ?: ('User #' . $r['owner_user_id']);
                                    if (!empty($r['owner_email'])) {
                                        $ownerText = $oname . ' (' . $r['owner_email'] . ')';
                                    } else {
                                        $ownerText = $oname;
                                    }
                                }
                                $billingBadge = '';
                                if (!empty($r['owner_user_id']) && function_exists('billing_get_trial_info')) {
                                    try {
                                        $ti = billing_get_trial_info((int)$r['owner_user_id'], (int)$r['id']);
                                        if ($ti['has_active_paid_plan']) {
                                            $billingBadge = '<span class="inline-flex items-center px-2 py-0.5 rounded-full border border-emerald-500/70 bg-emerald-500/15 text-[10px] text-emerald-100">PAID</span>';
                                        } elseif ($ti['is_trial'] && !$ti['is_expired']) {
                                            $billingBadge = '<span class="inline-flex items-center px-2 py-0.5 rounded-full border border-sky-500/70 bg-sky-500/15 text-[10px] text-sky-100">TRIAL</span>';
                                        } elseif ($ti['is_expired']) {
                                            $billingBadge = '<span class="inline-flex items-center px-2 py-0.5 rounded-full border border-rose-500/70 bg-rose-500/15 text-[10px] text-rose-100">EXPIRED</span>';
                                        }
                                    } catch (Throwable $e) {
                                        $billingBadge = '';
                                    }
                                }
                                ?>
                                <div class="rounded-2xl bg-slate-900/80 border <?= $isActiveRow ? 'border-emerald-500/70' : 'border-slate-800' ?> px-3 py-2.5 flex flex-col md:grid md:grid-cols-[minmax(0,2.2fr)_minmax(0,2.3fr)_minmax(0,2.3fr)_minmax(0,1.2fr)] gap-2">
                                    <!-- Название -->
                                    <div>
                                        <div class="flex items-center gap-2">
                                            <div class="text-slate-50 font-medium">
                                                <?= e($r['name']) ?>
                                            </div>
                                            <span class="text-[10px] text-slate-500">#<?= $rid ?></span>
                                        </div>
                                        <?php if ($createdAt): ?>
                                            <div class="text-[10px] text-slate-500 mt-0.5">
                                                Создан: <?= e($createdAt) ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Поддомен -->
                                    <div class="md:flex md:flex-col md:justify-center">
                                        <div class="text-[11px] text-sky-300">
                                            <?= e($r['subdomain']) ?>.<?= e($mainDomain) ?>
                                        </div>
                                        <div class="text-[10px] text-slate-500">
                                            QR-меню и кабинет по этому адресу
                                        </div>
                                    </div>

                                    <!-- Владелец -->
                                    <div class="md:flex md:flex-col md:justify-center">
                                        <div class="text-[11px] text-slate-100">
                                            <?= e($ownerText) ?>
                                        </div>
                                        <?php if (!empty($r['owner_user_id'])): ?>
                                            <div class="text-[10px] text-slate-500">
                                                ID: <?= (int)$r['owner_user_id'] ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Статус + кнопки -->
                                    <div class="flex flex-col md:items-end gap-1">
                                        <div class="flex flex-wrap items-center justify-end gap-1.5">
                                            <span class="inline-flex items-center px-2 py-1 rounded-full border text-[10px] <?= $statusClass ?>">
                                                <?= e($statusLabel) ?>
                                            </span>
                                            <?php if ($billingBadge !== ''): ?><?= $billingBadge ?><?php endif; ?>
                                        </div>
                                        <div class="flex flex-wrap gap-1.5 mt-1">
                                            <a href="/project-admin/restaurants.php?edit=<?= $rid ?><?= $q !== '' ? '&q='.urlencode($q) : '' ?><?= $status !== '' ? '&status='.urlencode($status) : '' ?>#edit-panel"
                                               class="inline-flex items-center px-2.5 py-1.5 rounded-2xl bg-slate-800 hover:bg-slate-700 text-[11px] text-slate-100 border border-slate-700 transition">
                                                Настроить
                                            </a>
                                            <a href="/owner/dashboard.php?restaurant_id=<?= $rid ?>"
                                               class="inline-flex items-center px-2.5 py-1.5 rounded-2xl bg-slate-900 hover:bg-slate-800 text-[11px] text-slate-200 border border-slate-700 transition">
                                                В панель ресторана
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>
            </div>

            <!-- ПРАВО: создание / редактирование -->
            <div id="edit-panel" class="space-y-4">
                <!-- Создание ресторана -->
                <section class="glass-card rounded-3xl border border-slate-800/80 p-4 shadow-2xl shadow-slate-950/80">
                    <div class="flex items-center justify-between mb-2">
                        <h2 class="text-sm font-semibold text-slate-50">
                            Создать новый ресторан
                        </h2>
                        <span class="text-[11px] text-slate-500">
                            Wildcard поддомены на <?= e($mainDomain) ?>
                        </span>
                    </div>
                    <form method="post" class="space-y-3 text-sm">
                        <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                        <input type="hidden" name="action" value="create_restaurant">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="block text-[11px] text-slate-300 mb-1">Название ресторана</label>
                                <input type="text" name="name"
                                       placeholder="Например, Pizza Town"
                                       class="w-full rounded-2xl bg-slate-900 border border-slate-700 px-3 py-2 text-sm text-slate-50 placeholder:text-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                            </div>
                            <div>
                                <label class="block text-[11px] text-slate-300 mb-1">Поддомен (slug)</label>
                                <div class="flex items-center gap-1">
                                    <input type="text" name="subdomain"
                                           placeholder="pizza-town"
                                           class="w-full rounded-2xl bg-slate-900 border border-slate-700 px-3 py-2 text-sm text-slate-50 placeholder:text-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                                    <span class="text-[11px] text-slate-500 whitespace-nowrap">.<?= e($mainDomain) ?></span>
                                </div>
                                <div class="text-[10px] text-slate-500 mt-1">
                                    Только латиница, цифры и дефис.
                                </div>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="block text-[11px] text-slate-300 mb-1">Владелец ресторана</label>
                                <select name="owner_user_id"
                                        class="w-full rounded-2xl bg-slate-900 border border-slate-700 px-3 py-2 text-sm text-slate-50 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                                    <option value="0">— без владельца —</option>
                                    <?php foreach ($ownersList as $u): ?>
                                        <?php
                                        $uname = $u['name'] ?: ('User #'.$u['id']);
                                        $label = $uname . (!empty($u['email']) ? ' ('.$u['email'].')' : '');
                                        ?>
                                        <option value="<?= (int)$u['id'] ?>">
                                            <?= e($label) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="text-[10px] text-slate-500 mt-1">
                                    Для нового владельца сначала создай пользователя в разделе «Пользователи».
                                </div>
                            </div>
                            <div>
                                <label class="block text-[11px] text-slate-300 mb-1">Статус</label>
                                <select name="status"
                                        class="w-full rounded-2xl bg-slate-900 border border-slate-700 px-3 py-2 text-sm text-slate-50 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                                    <option value="active">Активен</option>
                                    <option value="blocked">Заблокирован</option>
                                </select>
                            </div>
                        </div>

                        <div class="flex flex-col sm:flex-row gap-2 sm:items-center sm:justify-between pt-1">
                            <button type="submit"
                                    class="inline-flex items-center justify-center px-4 py-2.5 rounded-2xl bg-emerald-500 hover:bg-emerald-400 text-sm font-semibold text-slate-950 shadow-lg shadow-emerald-500/40 transition">
                                Создать ресторан
                            </button>
                            <div class="text-[11px] text-slate-500">
                                После создания владелец сможет войти через свой поддомен или через общий домен.
                            </div>
                        </div>
                    </form>
                </section>

                <!-- Редактирование выбранного ресторана -->
                <section class="glass-card rounded-3xl border border-slate-800/80 p-4 shadow-2xl shadow-slate-950/80">
                    <h2 class="text-sm font-semibold text-slate-50 mb-1">
                        Настройки выбранного ресторана
                    </h2>
                    <p class="text-xs text-slate-400 mb-3">
                        Нажми «Настроить» у ресторана слева, чтобы изменить название, поддомен, владельца и статус.
                    </p>

                    <?php if (!$editRestaurant): ?>
                        <div class="rounded-2xl border border-dashed border-slate-700 bg-slate-900/60 px-3 py-4 text-xs text-slate-400">
                            Ресторан не выбран. Нажми «Настроить» в списке слева.
                        </div>
                    <?php else: ?>
                        <form method="post" class="space-y-3 text-sm">
                            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                            <input type="hidden" name="action" value="update_restaurant">
                            <input type="hidden" name="id" value="<?= (int)$editRestaurant['id'] ?>">

                            <div class="flex items-center justify-between gap-2 mb-1">
                                <div>
                                    <div class="text-xs text-slate-400">
                                        Ресторан #<?= (int)$editRestaurant['id'] ?>
                                    </div>
                                    <div class="text-base font-semibold text-slate-50">
                                        <?= e($editRestaurant['name']) ?>
                                    </div>
                                </div>
                                <div class="text-[11px] text-slate-500 text-right">
                                    Поддомен:
                                    <span class="text-sky-300">
                                        <?= e($editRestaurant['subdomain']) ?>.<?= e($mainDomain) ?>
                                    </span>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div>
                                    <label class="block text-[11px] text-slate-300 mb-1">Название</label>
                                    <input type="text" name="name"
                                           value="<?= e($editRestaurant['name']) ?>"
                                           class="w-full rounded-2xl bg-slate-900 border border-slate-700 px-3 py-2 text-sm text-slate-50 placeholder:text-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                                </div>
                                <div>
                                    <label class="block text-[11px] text-slate-300 mb-1">Поддомен</label>
                                    <div class="flex items-center gap-1">
                                        <input type="text" name="subdomain"
                                               value="<?= e($editRestaurant['subdomain']) ?>"
                                               class="w-full rounded-2xl bg-slate-900 border border-slate-700 px-3 py-2 text-sm text-slate-50 placeholder:text-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                                        <span class="text-[11px] text-slate-500 whitespace-nowrap">.<?= e($mainDomain) ?></span>
                                    </div>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div>
                                    <label class="block text-[11px] text-slate-300 mb-1">Владелец ресторана</label>
                                    <select name="owner_user_id"
                                            class="w-full rounded-2xl bg-slate-900 border border-slate-700 px-3 py-2 text-sm text-slate-50 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                                        <option value="0">— без владельца —</option>
                                        <?php foreach ($ownersList as $u): ?>
                                            <?php
                                            $uname = $u['name'] ?: ('User #'.$u['id']);
                                            $label = $uname . (!empty($u['email']) ? ' ('.$u['email'].')' : '');
                                            ?>
                                            <option value="<?= (int)$u['id'] ?>"
                                                <?= (int)$editRestaurant['owner_user_id'] === (int)$u['id'] ? 'selected' : '' ?>>
                                                <?= e($label) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-[11px] text-slate-300 mb-1">Статус</label>
                                    <select name="status"
                                            class="w-full rounded-2xl bg-slate-900 border border-slate-700 px-3 py-2 text-sm text-slate-50 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                                        <option value="active" <?= $editRestaurant['status'] === 'active' ? 'selected' : '' ?>>Активен</option>
                                        <option value="blocked" <?= $editRestaurant['status'] === 'blocked' ? 'selected' : '' ?>>Заблокирован</option>
                                    </select>
                                </div>
                            </div>

                            <div class="flex flex-col sm:flex-row gap-2 sm:items-center sm:justify-between pt-2">
                                <button type="submit"
                                        class="inline-flex items-center justify-center px-4 py-2.5 rounded-2xl bg-emerald-500 hover:bg-emerald-400 text-sm font-semibold text-slate-950 shadow-lg shadow-emerald-500/40 transition">
                                    Сохранить изменения
                                </button>
                                <div class="text-[11px] text-slate-500">
                                    Статус «Заблокирован» отключит работу ресторана на поддомене.
                                </div>
                            </div>
                        </form>
                    <?php endif; ?>
                </section>
            </div>
        </div>
    </div>
</div>
</body>
</html>
