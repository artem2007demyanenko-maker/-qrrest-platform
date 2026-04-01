<?php


ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);


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
if (!$currentUser || ($currentUser['global_role'] ?? null) !== 'project_owner') {
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

// --- ВХОДНЫЕ ПАРАМЕТРЫ --- //
$q       = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$editId  = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$saved   = isset($_GET['saved']) ? (int)$_GET['saved'] : 0;
$errMsg  = '';
$okMsg   = '';
$createOkPassword = null;

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

/**
 * Все рестораны: id => [id, name, subdomain]
 */
function get_all_restaurants_for_edit(PDO $pdo): array {
    $sql = "SELECT id, name, subdomain FROM restaurants ORDER BY id DESC";
    $stmt = $pdo->query($sql);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $byId = [];
    foreach ($rows as $r) {
        $byId[(int)$r['id']] = $r;
    }
    return $byId;
}

/**
 * Генерация случайного пароля
 */
function generate_password(int $length = 10): string {
    $chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $max   = strlen($chars) - 1;
    $res   = '';
    for ($i = 0; $i < $length; $i++) {
        $res .= $chars[random_int(0, $max)];
    }
    return $res;
}

// --- СОЗДАНИЕ ПОЛЬЗОВАТЕЛЯ --- //
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_user') {
    $csrfOk = isset($_POST['csrf'], $_SESSION['csrf']) && hash_equals((string)$_SESSION['csrf'], (string)$_POST['csrf']);
    if (!$csrfOk) {
        $errMsg = 'Неверный запрос. Обновите страницу и попробуйте снова.';
    } else {
    $name        = trim((string)($_POST['create_name'] ?? ''));
    $email       = trim((string)($_POST['create_email'] ?? ''));
    $globalRole  = ($_POST['create_global_role'] ?? 'user') === 'project_owner' ? 'project_owner' : 'user';
    $password    = (string)($_POST['create_password'] ?? '');
    $genPassword = isset($_POST['create_generate_password']) ? (bool)$_POST['create_generate_password'] : false;
    $referralCode = trim((string)($_POST['create_referral_code'] ?? ''));

    if ($name === '') {
        $errMsg = 'Укажите имя пользователя для создания.';
    } else {
        if ($password === '' && $genPassword) {
            $password = generate_password(10);
        }

        if ($password === '') {
            $errMsg = 'Нужно либо указать пароль, либо отметить «Сгенерировать пароль».';
        } else {
            try {
                // ВАЖНО: пишем в password_hash
                $stmt = $pdo->prepare("
                    INSERT INTO users (name, email, password_hash, global_role)
                    VALUES (:name, :email, :password_hash, :role)
                ");
                $stmt->execute([
                    ':name'          => $name,
                    ':email'         => $email !== '' ? $email : null,
                    ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
                    ':role'          => $globalRole,
                ]);
                $newUserId        = (int)$pdo->lastInsertId();
                $createOkPassword = $password;
                $okMsg = 'Пользователь создан (ID: ' . $newUserId . ').';

                if ($referralCode !== '' && function_exists('growth_get_referral_by_code') && function_exists('growth_create_conversion')) {
                    require_once __DIR__ . '/../../app/growth.php';
                    $ref = growth_get_referral_by_code($referralCode);
                    if ($ref !== null) {
                        growth_create_conversion($ref['id'], $newUserId);
                    }
                }
            } catch (PDOException $e) {
                $errMsg = 'Ошибка при создании пользователя. Попробуйте позже.';
                if (function_exists('error_log')) {
                    error_log('users create_user ' . $e->getMessage());
                }
            }
        }
    }
    }
}

// --- ОБНОВЛЕНИЕ ПОЛЬЗОВАТЕЛЯ --- //
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_user') {
    $csrfOk = isset($_POST['csrf'], $_SESSION['csrf']) && hash_equals((string)$_SESSION['csrf'], (string)$_POST['csrf']);
    if (!$csrfOk) {
        $errMsg = 'Неверный запрос. Обновите страницу и попробуйте снова.';
    } else {
    $userId = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;

    if ($userId <= 0) {
        $errMsg = 'Неверный ID пользователя.';
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id");
        $stmt->execute([':id' => $userId]);
        $userRow = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$userRow) {
            $errMsg = 'Пользователь не найден.';
        } else {
            $name  = trim((string)($_POST['name'] ?? ''));
            $email = trim((string)($_POST['email'] ?? ''));
            $role  = ($_POST['global_role'] ?? 'user') === 'project_owner'
                ? 'project_owner'
                : 'user';

            if ($name === '') {
                $name = $userRow['name'] ?: 'User #' . $userRow['id'];
            }
            if ($email === '') {
                $email = null;
            }

            $newPassword     = (string)($_POST['new_password'] ?? '');
            $newPasswordHash = null;
            if ($newPassword !== '') {
                $newPasswordHash = password_hash($newPassword, PASSWORD_DEFAULT);
            }

            // Рестораны, которыми он владеет
            $restaurantsOwner = isset($_POST['restaurants_owner']) && is_array($_POST['restaurants_owner'])
                ? $_POST['restaurants_owner']
                : [];

            $allRestaurants = get_all_restaurants_for_edit($pdo);
            $validOwnerIds  = [];
            foreach ($restaurantsOwner as $rid) {
                $rid = (int)$rid;
                if ($rid > 0 && isset($allRestaurants[$rid])) {
                    $validOwnerIds[$rid] = true;
                }
            }
            $validOwnerIds = array_keys($validOwnerIds);

            try {
                $pdo->beginTransaction();

                $params = [
                    ':name'        => $name,
                    ':email'       => $email,
                    ':global_role' => $role,
                    ':id'          => $userId,
                ];

                $sqlUpdate = "UPDATE users 
                              SET name = :name, email = :email, global_role = :global_role";

                if ($newPasswordHash !== null) {
                    // ВАЖНО: обновляем password_hash, а не password
                    $sqlUpdate .= ", password_hash = :pwd_hash";
                    $params[':pwd_hash'] = $newPasswordHash;
                }

                $sqlUpdate .= " WHERE id = :id";

                $stmt = $pdo->prepare($sqlUpdate);
                $stmt->execute($params);

                // Обновляем owner-связки
                $del = $pdo->prepare("DELETE FROM users_restaurants WHERE user_id = :uid AND restaurant_role = 'owner'");
                $del->execute([':uid' => $userId]);

                if (!empty($validOwnerIds)) {
                    $ins = $pdo->prepare("
                        INSERT INTO users_restaurants (user_id, restaurant_id, restaurant_role)
                        VALUES (:uid, :rid, 'owner')
                    ");
                    foreach ($validOwnerIds as $rid) {
                        $ins->execute([
                            ':uid' => $userId,
                            ':rid' => $rid,
                        ]);
                    }
                }

                $pdo->commit();
                header('Location: /project-admin/users.php?edit=' . $userId . '&saved=1');
                exit;

            } catch (PDOException $e) {
                $pdo->rollBack();
                $errMsg = 'Ошибка при сохранении пользователя. Попробуйте позже.';
                if (function_exists('error_log')) {
                    error_log('users update_user ' . $e->getMessage());
                }
            }
        }
    }
    }
}

// --- СТАТИСТИКА ПО РОЛЯМ --- //
$stats = [
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
        $stats['total'] += $cnt;
        if (isset($stats[$role])) {
            $stats[$role] += $cnt;
        }
    }
} catch (PDOException $e) {}


$usersList = [];
try {
    $where  = '1';
    $params = [];

    if ($q !== '') {
        $whereParts = [];
        $whereParts[]       = 'u.name LIKE :q_name';
        $params[':q_name']  = '%' . $q . '%';
        $whereParts[]       = 'u.email LIKE :q_email';
        $params[':q_email'] = '%' . $q . '%';

        if (ctype_digit($q)) {
            $whereParts[]    = 'u.id = :q_id';
            $params[':q_id'] = (int)$q;
        }

        $where = '(' . implode(' OR ', $whereParts) . ')';
    }

    $sql = "
        SELECT 
            u.id,
            u.name,
            u.email,
            u.global_role,
            COUNT(DISTINCT r.id) AS restaurants_count,
            SUM(CASE WHEN ur.restaurant_role = 'owner' THEN 1 ELSE 0 END) AS restaurants_owner_count,
            SUM(CASE WHEN ur.restaurant_role = 'admin' THEN 1 ELSE 0 END) AS restaurants_admin_count,
            SUM(CASE WHEN ur.restaurant_role = 'staff' THEN 1 ELSE 0 END) AS restaurants_staff_count
        FROM users u
        LEFT JOIN users_restaurants ur ON ur.user_id = u.id
        LEFT JOIN restaurants r ON r.id = ur.restaurant_id
        WHERE $where
        GROUP BY u.id, u.name, u.email, u.global_role
        ORDER BY u.id DESC
        LIMIT 500
    ";

    if (!empty($params)) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    } else {
        $stmt = $pdo->query($sql);
    }

    $usersList = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $errMsg = 'Ошибка при загрузке списка пользователей. Попробуйте позже.';
    if (function_exists('error_log')) {
        error_log('users list ' . $e->getMessage());
    }
    $usersList = [];
}

// --- ДАННЫЕ ДЛЯ РЕДАКТОРА --- //
$editUser       = null;
$editOwnerIds   = [];
$allRestaurants = [];
$restaurantsForJs = [];

if ($editId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id");
    $stmt->execute([':id' => $editId]);
    $editUser = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($editUser) {
        $stmt = $pdo->prepare("
            SELECT restaurant_id
            FROM users_restaurants
            WHERE user_id = :uid AND restaurant_role = 'owner'
        ");
        $stmt->execute([':uid' => $editId]);
        $editOwnerIds = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
        $editOwnerIds = array_map('intval', $editOwnerIds);
    }

    $allRestaurants = get_all_restaurants_for_edit($pdo);
    foreach ($allRestaurants as $rid => $rest) {
        $restaurantsForJs[] = [
            'id'        => (int)$rid,
            'name'      => $rest['name'],
            'subdomain' => $rest['subdomain'],
        ];
    }
}

if ($saved && !$errMsg) {
    $okMsg = 'Изменения сохранены.';
}

// Название приложения
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
    <title>Пользователи платформы — <?= e($appName) ?></title>
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
        <div class="absolute топ-1/3 right-12 w-60 h-60 bg-fuchsia-500/25 blur-3xl rounded-full opacity-80"></div>
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
                    Управление пользователями
                </div>
                <h1 class="text-2xl sm:text-3xl font-semibold text-slate-50 mb-1">
                    Пользователи платформы
                </h1>
                <p class="text-sm text-slate-400 max-w-xl">
                    Список всех пользователей, их роли и привязка к ресторанам. Справа — создание и настройка выбранного пользователя.
                </p>
            </div>
            <div class="flex flex-col items-start sm:items-end gap-2 text-xs">
                <div class="px-3 py-1 rounded-full bg-slate-900/80 border border-slate-700 text-slate-300">
                    Всего пользователей: <span class="text-slate-100 font-semibold"><?= (int)$stats['total'] ?></span>
                </div>
                <div class="flex gap-2 flex-wrap">
                    <div class="px-2 py-1 rounded-full bg-amber-500/15 border border-amber-500/60 text-[11px] text-amber-100">
                        Владельцев платформы: <?= (int)$stats['project_owner'] ?>
                    </div>
                    <div class="px-2 py-1 rounded-full bg-slate-900 border border-slate-700 text-[11px] text-slate-200">
                        Обычных пользователей: <?= (int)$stats['user'] ?>
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
                <?php if ($createOkPassword): ?>
                    <div class="mt-1 text-[11px]">
                        Пароль нового пользователя:
                        <span class="font-mono px-2 py-0.5 rounded bg-slate-900 border border-slate-700 text-slate-50">
                            <?= e($createOkPassword) ?>
                        </span>
                        <span class="text-slate-400">(скопируй и передай владельцу)</span>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-[minmax(0,3fr)_minmax(0,2.4fr)] gap-4">
            <!-- ЛЕВО: поиск + фильтры + список -->
            <div class="space-y-4">
                <!-- Поиск -->
                <section class="rounded-3xl bg-slate-950/90 border border-slate-800/80 p-4 shadow-lg shadow-slate-950/70">
                    <form method="get" action="/project-admin/users.php"
                          class="flex flex-col sm:flex-row gap-3 items-stretch sm:items-end justify-between">
                        <div class="flex-1">
                            <label class="block text-[11px] text-slate-400 mb-1">
                                Поиск по имени, e-mail или ID
                            </label>
                            <div class="relative">
                                <input
                                    type="text"
                                    name="q"
                                    value="<?= e($q) ?>"
                                    placeholder="Например: Илья, mail@domain.ru или 15"
                                    class="w-full rounded-2xl bg-slate-900 border border-slate-700 px-3 py-2.5 text-sm text-slate-50 placeholder:text-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500"
                                >
                                <?php if ($q !== '' || $editId): ?>
                                    <a href="/project-admin/users.php"
                                       class="absolute inset-y-0 right-2 flex items-center text-[11px] text-slate-500 hover:text-slate-200">
                                        сброс
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                        <button type="submit"
                                class="inline-flex items-center justify-center px-4 py-2.5 rounded-2xl bg-emerald-500 hover:bg-emerald-400 text-sm font-semibold text-slate-950 shadow-lg shadow-emerald-500/40 transition">
                            Найти
                        </button>
                    </form>

                    <!-- Фильтры по ролям -->
                    <div class="mt-3 flex flex-wrap gap-2 text-[11px]">
                        <button type="button"
                                class="user-filter px-3 py-1.5 rounded-full bg-slate-900 border border-slate-700 text-slate-100 font-medium"
                                data-filter="all">
                            Все
                        </button>
                        <button type="button"
                                class="user-filter px-3 py-1.5 rounded-full bg-slate-900/80 border border-slate-700 text-slate-300"
                                data-filter="platform">
                            Владельцы платформы
                        </button>
                        <button type="button"
                                class="user-filter px-3 py-1.5 rounded-full bg-slate-900/80 border border-slate-700 text-slate-300"
                                data-filter="owner">
                            Владельцы ресторанов
                        </button>
                        <button type="button"
                                class="user-filter px-3 py-1.5 rounded-full bg-slate-900/80 border border-slate-700 text-slate-300"
                                data-filter="admin">
                            Админы
                        </button>
                        <button type="button"
                                class="user-filter px-3 py-1.5 rounded-full bg-slate-900/80 border border-slate-700 text-slate-300"
                                data-filter="staff">
                            Сотрудники
                        </button>
                    </div>
                </section>

                <!-- Список пользователей -->
                <section class="rounded-3xl bg-slate-950/90 border border-slate-800/80 p-4 shadow-xl shadow-slate-950/70">
                    <div class="flex items-center justify-between mb-3">
                        <div>
                            <div class="text-xs text-slate-400 mb-0.5">
                                Пользователи
                                <?php if ($q !== ''): ?>
                                    • поиск: «<?= e($q) ?>»
                                <?php endif; ?>
                            </div>
                            <h2 class="text-sm font-semibold text-slate-50">
                                Найдено: <?= count($usersList) ?>
                            </h2>
                        </div>
                    </div>

                    <?php if (!$usersList): ?>
                        <div class="text-sm text-slate-500">
                            Пользователи не найдены.
                        </div>
                    <?php else: ?>
                        <div class="hidden md:grid md:grid-cols-[minmax(0,3fr)_minmax(0,2.3fr)_minmax(0,2.2fr)_minmax(0,2.4fr)] gap-2 text-[11px] text-slate-400 pb-1 border-b border-slate-800 mb-2">
                            <div>Пользователь</div>
                            <div>E-mail</div>
                            <div>Роль платформы</div>
                            <div>Роли по ресторанам</div>
                        </div>

                        <div class="space-y-2 text-xs">
                            <?php foreach ($usersList as $u): ?>
                                <?php
                                $role      = $u['global_role'] ?? 'user';
                                $roleLabel = $role === 'project_owner' ? 'Владелец платформы' : 'Пользователь';
                                $roleClass = $role === 'project_owner'
                                    ? 'bg-amber-500/15 border-amber-500/60 text-amber-100'
                                    : 'bg-slate-900 border-slate-700 text-slate-200';

                                $restaurantsCount       = (int)($u['restaurants_count'] ?? 0);
                                $restaurantsOwnerCount  = (int)($u['restaurants_owner_count'] ?? 0);
                                $restaurantsAdminCount  = (int)($u['restaurants_admin_count'] ?? 0);
                                $restaurantsStaffCount  = (int)($u['restaurants_staff_count'] ?? 0);

                                $displayName  = $u['name'] ?: ('User #' . $u['id']);
                                $isActiveEdit = ($editId === (int)$u['id']);

                                $hasOwner = $restaurantsOwnerCount > 0;
                                $hasAdmin = $restaurantsAdminCount > 0;
                                $hasStaff = $restaurantsStaffCount > 0;
                                ?>
                                <div class="user-row rounded-2xl bg-slate-900/80 border <?= $isActiveEdit ? 'border-emerald-500/70' : 'border-slate-800' ?> px-3 py-2.5 flex flex-col md:grid md:grid-cols-[minmax(0,3fr)_minmax(0,2.3fr)_minmax(0,2.2fr)_minmax(0,2.4fr)] gap-2"
                                     data-global-role="<?= e($role) ?>"
                                     data-has-owner="<?= $hasOwner ? '1' : '0' ?>"
                                     data-has-admin="<?= $hasAdmin ? '1' : '0' ?>"
                                     data-has-staff="<?= $hasStaff ? '1' : '0' ?>">
                                    <!-- Пользователь -->
                                    <div>
                                        <div class="flex items-center gap-2">
                                            <div class="text-slate-50 font-medium">
                                                <?= e($displayName) ?>
                                            </div>
                                            <span class="text-[10px] text-slate-500">
                                                #<?= (int)$u['id'] ?>
                                            </span>
                                        </div>
                                    </div>

                                    <!-- Email -->
                                    <div class="md:flex md:flex-col md:justify-center">
                                        <?php if (!empty($u['email'])): ?>
                                            <div class="text-slate-300 text-[11px] md:text-xs">
                                                <?= e($u['email']) ?>
                                            </div>
                                        <?php else: ?>
                                            <div class="text-slate-500 text-[11px]">
                                                E-mail не указан
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Роль платформы -->
                                    <div class="md:flex md:items-center">
                                        <span class="inline-flex items-center px-2 py-1 rounded-full border text-[10px] <?= $roleClass ?>">
                                            <?= e($roleLabel) ?>
                                        </span>
                                    </div>

                                    <!-- Роли по ресторанам + кнопка настроек -->
                                    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-2 text-[11px] text-slate-400">
                                        <div class="space-y-0.5">
                                            <?php if ($restaurantsCount === 0): ?>
                                                <div>Не привязан к ресторанам</div>
                                            <?php else: ?>
                                                <div>Всего ресторанов: <span class="text-slate-200"><?= $restaurantsCount ?></span></div>
                                                <div class="flex flex-wrap gap-x-2 gap-y-0.5">
                                                    <?php if ($restaurantsOwnerCount > 0): ?>
                                                        <span>владелец: <span class="text-emerald-300"><?= $restaurantsOwnerCount ?></span></span>
                                                    <?php endif; ?>
                                                    <?php if ($restaurantsAdminCount > 0): ?>
                                                        <span>админ: <span class="text-sky-300"><?= $restaurantsAdminCount ?></span></span>
                                                    <?php endif; ?>
                                                    <?php if ($restaurantsStaffCount > 0): ?>
                                                        <span>сотрудник: <span class="text-fuchsia-300"><?= $restaurantsStaffCount ?></span></span>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="flex justify-end">
                                            <a href="/project-admin/users.php?edit=<?= (int)$u['id'] ?><?= $q !== '' ? '&q='.urlencode($q) : '' ?>#edit-panel"
                                               class="inline-flex items-center px-2.5 py-1.5 rounded-2xl bg-slate-800 hover:bg-slate-700 text-[11px] text-slate-100 border border-slate-700 transition">
                                                Настроить
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>
            </div>

            <!-- ПРАВО: создание + редактирование -->
            <div id="edit-panel" class="space-y-4">
                <!-- СОЗДАНИЕ ПОЛЬЗОВАТЕЛЯ -->
                <section class="rounded-3xl bg-slate-950/95 border border-slate-800/80 p-4 shadow-2xl shadow-slate-950/80 backdrop-blur-xl">
                    <div class="flex items-center justify-between mb-2">
                        <h2 class="text-sm font-semibold text-slate-50">
                            Создать нового пользователя
                        </h2>
                        <span class="text-[11px] text-slate-500">
                            Для нового владельца ресторана
                        </span>
                    </div>
                    <form method="post" class="space-y-3 text-sm">
                        <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                        <input type="hidden" name="action" value="create_user">

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="block text-[11px] text-slate-300 mb-1">Имя</label>
                                <input type="text" name="create_name"
                                       placeholder="Иван Петров"
                                       class="w-full rounded-2xl bg-slate-900 border border-slate-700 px-3 py-2 text-sm text-slate-50 placeholder:text-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                            </div>
                            <div>
                                <label class="block text-[11px] text-slate-300 mb-1">E-mail (опционально)</label>
                                <input type="email" name="create_email"
                                       placeholder="email@example.com"
                                       class="w-full rounded-2xl bg-slate-900 border border-slate-700 px-3 py-2 text-sm text-slate-50 placeholder:text-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="block text-[11px] text-slate-300 mb-1">Роль в системе</label>
                                <select name="create_global_role"
                                        class="w-full rounded-2xl bg-slate-900 border border-slate-700 px-3 py-2 text-sm text-slate-50 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                                    <option value="user">Пользователь / владелец ресторанов</option>
                                    <option value="project_owner">Владелец платформы</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-[11px] text-slate-300 mb-1">Пароль</label>
                                <input type="text" name="create_password"
                                       placeholder="Можно оставить пустым и сгенерировать"
                                       class="w-full rounded-2xl bg-slate-900 border border-slate-700 px-3 py-2 text-sm text-slate-50 placeholder:text-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                                <label class="mt-1 inline-flex items-center gap-2 text-[11px] text-slate-400 cursor-pointer">
                                    <input type="checkbox" name="create_generate_password" value="1"
                                           class="rounded border-slate-600 bg-slate-900 text-emerald-500 focus:ring-emerald-500">
                                    <span>Сгенерировать случайный пароль, если поле выше пустое</span>
                                </label>
                            </div>
                        </div>

                        <div>
                            <label class="block text-[11px] text-slate-300 mb-1">Реферальный код (если новый владелец пришёл по реферальной ссылке)</label>
                            <input type="text" name="create_referral_code"
                                   placeholder="Необязательно"
                                   class="w-full rounded-2xl bg-slate-900 border border-slate-700 px-3 py-2 text-sm text-slate-50 placeholder:text-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                        </div>

                        <div class="flex flex-col sm:flex-row gap-2 sm:items-center sm:justify-between pt-1">
                            <button type="submit"
                                    class="inline-flex items-center justify-center px-4 py-2.5 rounded-2xl bg-emerald-500 hover:bg-emerald-400 text-sm font-semibold text-slate-950 shadow-lg shadow-emerald-500/40 transition">
                                Создать пользователя
                            </button>
                            <div class="text-[11px] text-slate-500">
                                После создания можно привязать рестораны через список слева и редактирование.
                            </div>
                        </div>
                    </form>
                </section>

                <!-- РЕДАКТИРОВАНИЕ ПОЛЬЗОВАТЕЛЯ -->
                <section class="rounded-3xl bg-slate-950/95 border border-slate-800/80 p-4 shadow-2xl shadow-slate-950/80 backdrop-blur-xl">
                    <h2 class="text-sm font-semibold text-slate-50 mb-1">
                        Настройки выбранного пользователя
                    </h2>
                    <p class="text-xs text-slate-400 mb-3">
                        Нажми «Настроить» у пользователя слева, чтобы изменить его профиль, роль и владение ресторанами.
                    </p>

                    <?php if (!$editUser): ?>
                        <div class="rounded-2xl border border-dashed border-slate-700 bg-slate-900/60 px-3 py-4 text-xs text-slate-400">
                            Пользователь не выбран. Нажми «Настроить» в списке.
                        </div>
                    <?php else: ?>
                        <form method="post" class="space-y-4 text-sm">
                            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                            <input type="hidden" name="action" value="update_user">
                            <input type="hidden" name="user_id" value="<?= (int)$editUser['id'] ?>">

                            <div class="flex items-center justify-between gap-2 mb-1">
                                <div>
                                    <div class="text-xs text-slate-400">Пользователь #<?= (int)$editUser['id'] ?></div>
                                    <div class="text-base font-semibold text-slate-50">
                                        <?= e($editUser['name'] ?: ('User #'.$editUser['id'])) ?>
                                    </div>
                                </div>
                                <div class="px-2 py-1 rounded-full bg-slate-900 border border-slate-700 text-[11px] text-slate-300">
                                    Роль: <?= e($editUser['global_role'] === 'project_owner' ? 'Владелец платформы' : 'Пользователь') ?>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div>
                                    <label class="block text-[11px] text-slate-300 mb-1">Имя</label>
                                    <input type="text" name="name"
                                           value="<?= e($editUser['name']) ?>"
                                           placeholder="Имя пользователя"
                                           class="w-full rounded-2xl bg-slate-900 border border-slate-700 px-3 py-2 text-sm text-slate-50 placeholder:text-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                                </div>
                                <div>
                                    <label class="block text-[11px] text-slate-300 mb-1">E-mail</label>
                                    <input type="email" name="email"
                                           value="<?= e($editUser['email']) ?>"
                                           placeholder="email@example.com"
                                           class="w-full rounded-2xl bg-slate-900 border border-slate-700 px-3 py-2 text-sm text-slate-50 placeholder:text-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                                </div>
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div>
                                    <label class="block text-[11px] text-slate-300 mb-1">Роль в системе</label>
                                    <select name="global_role"
                                            class="w-full rounded-2xl bg-slate-900 border border-slate-700 px-3 py-2 text-sm text-slate-50 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                                        <option value="user" <?= $editUser['global_role'] === 'user' ? 'selected' : '' ?>>
                                            Пользователь (владелец ресторанов и т.п.)
                                        </option>
                                        <option value="project_owner" <?= $editUser['global_role'] === 'project_owner' ? 'selected' : '' ?>>
                                            Владелец платформы
                                        </option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-[11px] text-slate-300 mb-1">Новый пароль</label>
                                    <input type="password" name="new_password"
                                           placeholder="Оставь пустым, чтобы не менять"
                                           class="w-full rounded-2xl bg-slate-900 border border-slate-700 px-3 py-2 text-sm text-slate-50 placeholder:text-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                                </div>
                            </div>

                            <div>
                                <label class="block text-[11px] text-slate-300 mb-1">
                                    Рестораны, которыми он владеет (owner)
                                </label>

                                <?php if (!$allRestaurants): ?>
                                    <div class="rounded-2xl bg-slate-900/70 border border-slate-800 px-3 py-2 text-xs text-slate-500">
                                        Пока нет ресторанов в системе.
                                    </div>
                                <?php else: ?>
                                    <!-- Поле поиска ресторана -->
                                    <div class="relative mb-2">
                                        <input type="text"
                                               id="rest-search-input"
                                               placeholder="Начните вводить название или поддомен ресторана..."
                                               autocomplete="off"
                                               class="w-full rounded-2xl bg-slate-900 border border-slate-700 px-3 py-2 text-sm text-slate-50 placeholder:text-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                                        <div id="rest-search-suggestions"
                                             class="absolute z-20 mt-1 w-full max-h-44 overflow-y-auto rounded-2xl bg-slate-950 border border-slate-800 shadow-xl hidden">
                                        </div>
                                    </div>

                                    <!-- Выбранные рестораны в виде "чипсов" -->
                                    <div id="rest-selected"
                                         class="rounded-2xl bg-slate-900/70 border border-slate-800 px-3 py-2 flex flex-wrap gap-2 text-xs text-slate-200">
                                        <?php foreach ($editOwnerIds as $rid): ?>
                                            <?php if (!empty($allRestaurants[$rid])): ?>
                                                <span class="rest-chip inline-flex items-center gap-1 px-2 py-1 rounded-full bg-slate-800 border border-emerald-500/60 text-emerald-100"
                                                      data-rid="<?= (int)$rid ?>">
                                                    <span><?= e($allRestaurants[$rid]['name']) ?></span>
                                                    <span class="text-[9px] text-slate-400">
                                                        (<?= e($allRestaurants[$rid]['subdomain']) ?>)
                                                    </span>
                                                    <button type="button"
                                                            class="rest-chip-remove ml-1 text-[11px] text-slate-400 hover:text-slate-100"
                                                            aria-label="Удалить ресторан">
                                                        ✕
                                                    </button>
                                                    <input type="hidden" name="restaurants_owner[]" value="<?= (int)$rid ?>">
                                                </span>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </div>

                                    <div class="text-[11px] text-slate-500 mt-1">
                                        Здесь управляется только роль <span class="text-slate-300">owner</span> в
                                        <code class="px-1 py-0.5 bg-slate-900 rounded">users_restaurants</code>. Роли admin/staff
                                        настраиваются из панели конкретного ресторана.
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="flex flex-col sm:flex-row gap-2 sm:items-center sm:justify-between pt-2">
                                <button type="submit"
                                        class="inline-flex items-center justify-center px-4 py-2.5 rounded-2xl bg-emerald-500 hover:bg-emerald-400 text-sm font-semibold text-slate-950 shadow-lg shadow-emerald-500/40 transition">
                                    Сохранить изменения
                                </button>
                                <div class="text-[11px] text-slate-500">
                                    Пароль изменится только если заполнить поле «Новый пароль».
                                </div>
                            </div>
                        </form>
                    <?php endif; ?>
                </section>
            </div>
        </div>
    </div>
</div>

<script>
// Фильтрация по ролям
document.addEventListener('DOMContentLoaded', function () {
    const buttons = document.querySelectorAll('.user-filter');
    const rows    = document.querySelectorAll('.user-row');

    function applyFilter(type) {
        rows.forEach(row => {
            const globalRole = row.getAttribute('data-global-role');
            const hasOwner   = row.getAttribute('data-has-owner') === '1';
            const hasAdmin   = row.getAttribute('data-has-admin') === '1';
            const hasStaff   = row.getAttribute('data-has-staff') === '1';

            let show = true;

            if (type === 'platform') {
                show = (globalRole === 'project_owner');
            } else if (type === 'owner') {
                show = hasOwner;
            } else if (type === 'admin') {
                show = hasAdmin;
            } else if (type === 'staff') {
                show = hasStaff;
            }

            row.style.display = show ? '' : 'none';
        });

        buttons.forEach(btn => {
            if (btn.getAttribute('data-filter') === type) {
                btn.classList.remove('bg-slate-900/80', 'text-slate-300');
                btn.classList.add('bg-slate-900', 'text-slate-100', 'font-medium');
            } else {
                btn.classList.add('bg-slate-900/80', 'text-slate-300');
                btn.classList.remove('bg-slate-900', 'text-slate-100', 'font-medium');
            }
        });
    }

    buttons.forEach(btn => {
        btn.addEventListener('click', () => {
            applyFilter(btn.getAttribute('data-filter'));
        });
    });

    applyFilter('all');
});

// Поиск ресторанов для owner (в правой панели)
(function () {
    const restData = <?= json_encode($restaurantsForJs, JSON_UNESCAPED_UNICODE) ?>;
    if (!restData || !Array.isArray(restData) || restData.length === 0) return;

    const input       = document.getElementById('rest-search-input');
    const suggestions = document.getElementById('rest-search-suggestions');
    const selectedBox = document.getElementById('rest-selected');

    if (!input || !suggestions || !selectedBox) return;

    function getSelectedIds() {
        const ids = [];
        selectedBox.querySelectorAll('.rest-chip').forEach(chip => {
            ids.push(parseInt(chip.getAttribute('data-rid'), 10));
        });
        return ids;
    }

    function renderSuggestions(list) {
        if (!list.length) {
            suggestions.classList.add('hidden');
            suggestions.innerHTML = '';
            return;
        }

        const html = list.map(r => `
            <button type="button"
                    class="w-full text-left px-3 py-2 text-xs text-slate-100 hover:bg-slate-800/80 flex flex-col"
                    data-rid="${r.id}">
                <span class="font-medium">${r.name}</span>
                <span class="text-[10px] text-slate-400">subdomain: ${r.subdomain}</span>
            </button>
        `).join('');

        suggestions.innerHTML = html;
        suggestions.classList.remove('hidden');
    }

    function filterSuggestions(term) {
        const t = term.trim().toLowerCase();
        const selectedIds = new Set(getSelectedIds());
        if (!t) {
            suggestions.classList.add('hidden');
            suggestions.innerHTML = '';
            return;
        }

        const list = restData.filter(r => {
            if (selectedIds.has(r.id)) return false;
            const s = (r.name + ' ' + r.subdomain).toLowerCase();
            return s.includes(t);
        }).slice(0, 15);

        renderSuggestions(list);
    }

    function addRestaurantChip(rest) {
        const existing = selectedBox.querySelector(`.rest-chip[data-rid="${rest.id}"]`);
        if (existing) return;

        const span = document.createElement('span');
        span.className = 'rest-chip inline-flex items-center gap-1 px-2 py-1 rounded-full bg-slate-800 border border-emerald-500/60 text-emerald-100';
        span.setAttribute('data-rid', rest.id);

        span.innerHTML = `
            <span>${rest.name}</span>
            <span class="text-[9px] text-slate-400">(${rest.subdomain})</span>
            <button type="button"
                    class="rest-chip-remove ml-1 text-[11px] text-slate-400 hover:text-slate-100"
                    aria-label="Удалить ресторан">✕</button>
            <input type="hidden" name="restaurants_owner[]" value="${rest.id}">
        `;
        selectedBox.appendChild(span);
    }

    input.addEventListener('input', function () {
        filterSuggestions(this.value);
    });

    input.addEventListener('focus', function () {
        if (this.value.trim() !== '') {
            filterSuggestions(this.value);
        }
    });

    document.addEventListener('click', function (e) {
        if (!suggestions.contains(e.target) && e.target !== input) {
            suggestions.classList.add('hidden');
        }
    });

    suggestions.addEventListener('click', function (e) {
        const btn = e.target.closest('button[data-rid]');
        if (!btn) return;
        const rid = parseInt(btn.getAttribute('data-rid'), 10);
        const rest = restData.find(r => r.id === rid);
        if (rest) {
            addRestaurantChip(rest);
        }
        input.value = '';
        suggestions.classList.add('hidden');
        suggestions.innerHTML = '';
    });

    selectedBox.addEventListener('click', function (e) {
        const removeBtn = e.target.closest('.rest-chip-remove');
        if (!removeBtn) return;
        const chip = removeBtn.closest('.rest-chip');
        if (chip) chip.remove();
    });
})();
</script>
</body>
</html>
