<?php

require_once __DIR__ . '/../../app/bootstrap.php';

require_current_restaurant_role(['owner','admin']);

if (!function_exists('e')) {
    function e($v): string {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}


$pdo         = db();
$currentUser = auth_user();
$errors      = [];
$success     = null;
$infoPassword = null;
if (empty($_SESSION['staff_manage_csrf']) || !is_string($_SESSION['staff_manage_csrf'])) {
    $_SESSION['staff_manage_csrf'] = bin2hex(random_bytes(32));
}
$staffManageCsrf = (string)($_SESSION['staff_manage_csrf'] ?? '');
$urHasActive = function_exists('db_column_exists') && db_column_exists('users_restaurants', 'is_active');
$urHasStation = function_exists('db_column_exists') && db_column_exists('users_restaurants', 'station');
$uHasActive = function_exists('db_column_exists') && db_column_exists('users', 'is_active');

function normalize_staff_station(string $role, string $stationRaw): ?string
{
    $role = strtolower(trim($role));
    $station = strtolower(trim($stationRaw));
    $stationRoleMap = [
        'cold' => 'cold',
        'dessert' => 'dessert',
        'grill' => 'grill',
        'pizza' => 'pizza',
        'sushi' => 'sushi',
    ];
    if (isset($stationRoleMap[$role])) {
        return $stationRoleMap[$role];
    }
    $allowed = ['hot', 'cold', 'bar', 'dessert', 'grill', 'pizza', 'sushi'];
    if (!in_array($station, $allowed, true)) {
        $station = '';
    }

    if ($role === 'bar') {
        return 'bar';
    }
    if ($role === 'kitchen') {
        return $station !== '' ? $station : 'hot';
    }
    return $station !== '' ? $station : null;
}

function restaurant_staff_role_map(): array
{
    return [
        'admin' => 'Администратор ресторана',
        'waiter' => 'Официант',
        'kitchen' => 'Кухня',
        'cold' => 'Холодный цех',
        'dessert' => 'Десерты',
        'grill' => 'Гриль',
        'pizza' => 'Пицца',
        'sushi' => 'Суши',
        'bar' => 'Бар',
        'staff' => 'Сотрудник',
    ];
}

function restaurant_staff_allowed_roles(bool $isOwner): array
{
    $roles = ['waiter', 'kitchen', 'cold', 'dessert', 'grill', 'pizza', 'sushi', 'bar', 'staff'];
    if ($isOwner) {
        array_unshift($roles, 'admin');
    }
    return $roles;
}

function normalize_restaurant_staff_role(string $roleRaw): string
{
    $role = strtolower(trim($roleRaw));
    if ($role === 'restaurant_admin') {
        $role = 'admin';
    }
    if (!array_key_exists($role, restaurant_staff_role_map())) {
        return '';
    }
    return $role;
}

function restaurant_staff_role_options(bool $isOwner): array
{
    $map = restaurant_staff_role_map();
    $options = [];
    foreach (restaurant_staff_allowed_roles($isOwner) as $role) {
        if (isset($map[$role])) {
            $options[$role] = $map[$role];
        }
    }
    return $options;
}

function restaurant_staff_role_badge_class(string $role): string
{
    return match ($role) {
        'owner' => 'bg-amber-500/15 border-amber-500/60 text-amber-100',
        'admin' => 'bg-sky-500/15 border-sky-500/60 text-sky-100',
        'waiter' => 'bg-emerald-500/15 border-emerald-500/60 text-emerald-100',
        'kitchen', 'cold', 'dessert', 'grill', 'pizza', 'sushi' => 'bg-indigo-500/15 border-indigo-500/60 text-indigo-100',
        'bar' => 'bg-cyan-500/15 border-cyan-500/60 text-cyan-100',
        'staff' => 'bg-emerald-500/15 border-emerald-500/60 text-emerald-100',
        default => 'bg-slate-900 border-slate-700 text-slate-200',
    };
}


$stmt = $pdo->prepare("
    SELECT restaurant_role
    FROM users_restaurants
    WHERE restaurant_id = :rest AND user_id = :uid
    LIMIT 1
");
$stmt->execute([
    'rest' => $currentRestaurant['id'],
    'uid'  => $currentUser['id'],
]);
$myLink = $stmt->fetch();
$myRole = $myLink['restaurant_role'] ?? null;

$isOwner = ($myRole === 'owner');
$isAdmin = ($myRole === 'admin');



if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $postedToken = (string)($_POST['csrf_token'] ?? '');
    $csrfOk = $staffManageCsrf !== '' && $postedToken !== '' && hash_equals($staffManageCsrf, $postedToken);
    if (!$csrfOk) {
        $errors[] = 'Сессия устарела. Обновите страницу и повторите действие.';
    }

    if ($csrfOk && $action === 'invite_user') {
        $email = trim($_POST['email'] ?? '');
        $name  = trim($_POST['name'] ?? '');
        $role  = normalize_restaurant_staff_role((string)($_POST['role'] ?? ''));
        $station = normalize_staff_station($role, (string)($_POST['station'] ?? ''));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Введите корректный email.';
        }
        if ($name === '') {
            $errors[] = 'Введите имя пользователя.';
        }
        if ($role === '' || !in_array($role, restaurant_staff_allowed_roles($isOwner), true)) {
            $errors[] = 'Недопустимая роль.';
        }
        if ($role === 'admin' && !$isOwner) {
            $errors[] = 'Только владелец ресторана может назначать админов.';
        }

        if (!$errors) {

            $stmt = $pdo->prepare("SELECT * FROM users WHERE email = :email LIMIT 1");
            $stmt->execute(['email' => $email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            $plainPassword = null;

            if (!$user) {
   
                $plainPassword = 'Staff' . random_int(100000, 999999);
                $passwordHash  = password_hash($plainPassword, PASSWORD_DEFAULT);

                $stmt = $pdo->prepare("
                    INSERT INTO users (name, email, password_hash, global_role)
                    VALUES (:name, :email, :pass, 'user')
                ");
                $stmt->execute([
                    'name'  => $name,
                    'email' => $email,
                    'pass'  => $passwordHash,
                ]);
                $userId = (int)$pdo->lastInsertId();


                $infoPassword = [
                    'email'    => $email,
                    'password' => $plainPassword,
                ];
            } else {
                $userId = (int)$user['id'];
            }


            $stmt = $pdo->prepare("
                SELECT * FROM users_restaurants
                WHERE user_id = :uid AND restaurant_id = :rest
                LIMIT 1
            ");
            $stmt->execute([
                'uid'  => $userId,
                'rest' => $currentRestaurant['id'],
            ]);
            $link = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($link) {

                if ($link['restaurant_role'] === 'owner') {
                    $errors[] = 'Нельзя менять роль владельца ресторана.';
                } else {

                    if (!$isOwner && $role === 'admin') {
                        $role = 'staff';
                    }

                    $setParts = ['restaurant_role = :role'];
                    $paramsUpdate = [
                        'role' => $role,
                        'id'   => $link['id'],
                    ];
                    if ($urHasActive) {
                        $setParts[] = 'is_active = 1';
                    }
                    if ($urHasStation) {
                        $setParts[] = 'station = :station';
                        $paramsUpdate['station'] = $station;
                    }
                    $stmt = $pdo->prepare("
                        UPDATE users_restaurants
                        SET " . implode(', ', $setParts) . "
                        WHERE id = :id
                    ");
                    $stmt->execute($paramsUpdate);

                    if ($uHasActive) {
                        $stmtUserActive = $pdo->prepare("UPDATE users SET is_active = 1 WHERE id = :uid");
                        $stmtUserActive->execute(['uid' => $userId]);
                    }

                    $success = 'Роль пользователя в ресторане обновлена.';
                }
            } else {

                $insertCols = ['user_id', 'restaurant_id', 'restaurant_role'];
                $insertVals = [':uid', ':rest', ':role'];
                $insertParams = [
                    'uid'  => $userId,
                    'rest' => $currentRestaurant['id'],
                    'role' => $role,
                ];
                if ($urHasActive) {
                    $insertCols[] = 'is_active';
                    $insertVals[] = '1';
                }
                if ($urHasStation) {
                    $insertCols[] = 'station';
                    $insertVals[] = ':station';
                    $insertParams['station'] = $station;
                }
                $stmt = $pdo->prepare("
                    INSERT INTO users_restaurants (" . implode(', ', $insertCols) . ")
                    VALUES (" . implode(', ', $insertVals) . ")
                ");
                $stmt->execute($insertParams);

                $success = 'Пользователь привязан к ресторану.';
            }

            if (function_exists('add_log')) {
                add_log($pdo, [
                    'user_id'       => (int)($currentUser['id'] ?? 0),
                    'restaurant_id' => (int)$currentRestaurant['id'],
                    'level'         => 'info',
                    'action'        => 'invite_user_to_restaurant',
                    'message'       => 'Пользователь ' . $email . ' привязан как ' . $role,
                ]);
            }
        }
    }


    if ($csrfOk && $action === 'change_role') {
        $linkId  = (int)($_POST['link_id'] ?? 0);
        $newRole = normalize_restaurant_staff_role((string)($_POST['role'] ?? ''));
        $newStation = normalize_staff_station($newRole, (string)($_POST['station'] ?? ''));

        if ($linkId <= 0) {
            $errors[] = 'Некорректный идентификатор связи.';
        }

        if ($newRole === '' || !in_array($newRole, restaurant_staff_allowed_roles($isOwner), true)) {
            $errors[] = 'Недопустимая роль.';
        }

        if (!$errors) {
            $stmt = $pdo->prepare("
                SELECT ur.*, u.email
                FROM users_restaurants ur
                JOIN users u ON u.id = ur.user_id
                WHERE ur.id = :id AND ur.restaurant_id = :rest
                LIMIT 1
            ");
            $stmt->execute([
                'id'   => $linkId,
                'rest' => $currentRestaurant['id'],
            ]);
            $link = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$link) {
                $errors[] = 'Связь не найдена.';
            } else {
                if ($link['restaurant_role'] === 'owner') {
                    $errors[] = 'Нельзя менять роль владельца ресторана.';
                } else {

                    if (!$isOwner) {
                        $adminAllowed = ['staff', 'waiter', 'kitchen', 'cold', 'dessert', 'grill', 'pizza', 'sushi', 'bar'];
                        if (!in_array((string)$link['restaurant_role'], $adminAllowed, true)
                            || !in_array($newRole, $adminAllowed, true)) {
                            $errors[] = 'У вас нет прав менять эту роль.';
                        }
                    }
                }

                if (!$errors) {
                    $setParts = ['restaurant_role = :role'];
                    $paramsUpdate = [
                        'role' => $newRole,
                        'id'   => $linkId,
                        'rest' => $currentRestaurant['id'],
                    ];
                    if ($urHasStation) {
                        $setParts[] = 'station = :station';
                        $paramsUpdate['station'] = $newStation;
                    }
                    $stmt = $pdo->prepare("
                        UPDATE users_restaurants
                        SET " . implode(', ', $setParts) . "
                        WHERE id = :id AND restaurant_id = :rest
                    ");
                    $stmt->execute($paramsUpdate);

                    $success = 'Роль пользователя обновлена.';

                    if (function_exists('add_log')) {
                        add_log($pdo, [
                            'user_id'       => (int)($currentUser['id'] ?? 0),
                            'restaurant_id' => (int)$currentRestaurant['id'],
                            'level'         => 'info',
                            'action'        => 'change_user_role_restaurant',
                            'message'       => 'Связь #' . $linkId . ' → ' . $newRole,
                        ]);
                    }
                }
            }
        }
    }


    if ($csrfOk && $action === 'toggle_active' && $urHasActive) {
        $linkId = (int)($_POST['link_id'] ?? 0);
        $target = isset($_POST['target_active']) ? (int)$_POST['target_active'] : 1;
        $target = $target === 0 ? 0 : 1;

        if ($linkId <= 0) {
            $errors[] = 'Некорректный идентификатор связи.';
        }

        if (!$errors) {
            $stmt = $pdo->prepare("
                SELECT ur.*, u.email
                FROM users_restaurants ur
                JOIN users u ON u.id = ur.user_id
                WHERE ur.id = :id AND ur.restaurant_id = :rest
                LIMIT 1
            ");
            $stmt->execute([
                'id' => $linkId,
                'rest' => $currentRestaurant['id'],
            ]);
            $link = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$link) {
                $errors[] = 'Связь не найдена.';
            } elseif ($link['restaurant_role'] === 'owner') {
                $errors[] = 'Нельзя отключать владельца ресторана.';
            } elseif (!$isOwner && (string)$link['restaurant_role'] === 'admin') {
                $errors[] = 'Только владелец может управлять активностью админа.';
            } else {
                $stmt = $pdo->prepare("
                    UPDATE users_restaurants
                    SET is_active = :active
                    WHERE id = :id AND restaurant_id = :rest
                ");
                $stmt->execute([
                    'active' => $target,
                    'id' => $linkId,
                    'rest' => $currentRestaurant['id'],
                ]);
                $success = $target === 1 ? 'Сотрудник снова активен.' : 'Сотрудник отключён для этого ресторана.';
            }
        }
    }

    if ($csrfOk && $action === 'reset_password') {
        $linkId = (int)($_POST['link_id'] ?? 0);
        if ($linkId <= 0) {
            $errors[] = 'Некорректный идентификатор связи.';
        }
        if (!$errors) {
            $stmt = $pdo->prepare("
                SELECT ur.*, u.email, u.id AS uid
                FROM users_restaurants ur
                JOIN users u ON u.id = ur.user_id
                WHERE ur.id = :id AND ur.restaurant_id = :rest
                LIMIT 1
            ");
            $stmt->execute([
                'id' => $linkId,
                'rest' => $currentRestaurant['id'],
            ]);
            $link = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$link) {
                $errors[] = 'Связь не найдена.';
            } elseif ((string)$link['restaurant_role'] === 'owner') {
                $errors[] = 'Нельзя сбрасывать пароль владельцу через этот экран.';
            } elseif (!$isOwner && (string)$link['restaurant_role'] === 'admin') {
                $errors[] = 'Только владелец может сбрасывать пароль админа.';
            } else {
                $plainPassword = 'Staff' . random_int(100000, 999999);
                $passwordHash  = password_hash($plainPassword, PASSWORD_DEFAULT);
                $stmtUpd = $pdo->prepare("UPDATE users SET password_hash = :pass WHERE id = :uid");
                $stmtUpd->execute([
                    'pass' => $passwordHash,
                    'uid' => (int)($link['uid'] ?? 0),
                ]);
                if ($uHasActive) {
                    $stmtAct = $pdo->prepare("UPDATE users SET is_active = 1 WHERE id = :uid");
                    $stmtAct->execute(['uid' => (int)($link['uid'] ?? 0)]);
                }
                if ($urHasActive) {
                    $stmtLink = $pdo->prepare("UPDATE users_restaurants SET is_active = 1 WHERE id = :id");
                    $stmtLink->execute(['id' => $linkId]);
                }
                $infoPassword = [
                    'email' => (string)($link['email'] ?? ''),
                    'password' => $plainPassword,
                ];
                $success = 'Пароль обновлён и доступ восстановлен.';
            }
        }
    }

    if ($csrfOk && $action === 'remove_link') {
        $linkId = (int)($_POST['link_id'] ?? 0);

        if ($linkId <= 0) {
            $errors[] = 'Некорректный идентификатор связи.';
        }

        if (!$errors) {
            $stmt = $pdo->prepare("
                SELECT ur.*, u.email
                FROM users_restaurants ur
                JOIN users u ON u.id = ur.user_id
                WHERE ur.id = :id AND ur.restaurant_id = :rest
                LIMIT 1
            ");
            $stmt->execute([
                'id'   => $linkId,
                'rest' => $currentRestaurant['id'],
            ]);
            $link = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$link) {
                $errors[] = 'Связь не найдена.';
            } else {
                if ($link['restaurant_role'] === 'owner') {
                    $errors[] = 'Нельзя удалить владельца ресторана.';
                } else {

                    if (!$isOwner && !in_array((string)$link['restaurant_role'], ['staff', 'waiter', 'kitchen', 'cold', 'dessert', 'grill', 'pizza', 'sushi', 'bar'], true)) {
                        $errors[] = 'У вас нет прав удалить этого пользователя.';
                    }
                }

                if (!$errors) {
                    $stmt = $pdo->prepare("
                        DELETE FROM users_restaurants
                        WHERE id = :id AND restaurant_id = :rest
                    ");
                    $stmt->execute([
                        'id'   => $linkId,
                        'rest' => $currentRestaurant['id'],
                    ]);

                    $success = 'Пользователь отвязан от ресторана.';

                    if (function_exists('add_log')) {
                        add_log($pdo, [
                            'user_id'       => (int)($currentUser['id'] ?? 0),
                            'restaurant_id' => (int)$currentRestaurant['id'],
                            'level'         => 'info',
                            'action'        => 'remove_user_from_restaurant',
                            'message'       => 'Связь #' . $linkId . ' удалена',
                        ]);
                    }
                }
            }
        }
    }
}



$stmt = $pdo->prepare("
    SELECT ur.*, u.name, u.email
    FROM users_restaurants ur
    JOIN users u ON u.id = ur.user_id
    WHERE ur.restaurant_id = :rest
    ORDER BY FIELD(ur.restaurant_role, 'owner','admin','waiter','kitchen','cold','dessert','grill','pizza','sushi','bar','staff'), u.name
");
$stmt->execute(['rest' => $currentRestaurant['id']]);
$links = $stmt->fetchAll(PDO::FETCH_ASSOC);

function human_rest_role(string $r): string {
    if ($r === 'owner') {
        return 'Владелец ресторана';
    }
    if ($r === 'restaurant_admin') {
        $r = 'admin';
    }
    $map = restaurant_staff_role_map();
    return $map[$r] ?? $r;
}

$restName = $currentRestaurant['name'] ?? 'Ресторан';
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Сотрудники — <?= e($restName) ?></title>
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
<div class="min-h-screen relative overflow-hidden flex">
    <!-- фон -->
    <div class="pointer-events-none absolute inset-0">
        <div class="absolute -top-40 -left-32 w-80 h-80 bg-emerald-500/20 blur-3xl rounded-full float-slow"></div>
        <div class="absolute bottom-[-9rem] right-[-3rem] w-96 h-96 bg-sky-500/20 blur-3xl rounded-full float-slow-2"></div>
        <div class="absolute top-1/3 right-10 w-60 h-60 bg-fuchsia-500/25 blur-3xl rounded-full opacity-80"></div>
    </div>

    <?php
    $restaurantSidebarActive = 'staff';
    $restaurantSidebarName = (string)$restName;
    require __DIR__ . '/_sidebar_mobile.php';
    require __DIR__ . '/_sidebar.php';
    ?>

    <!-- Основной контент -->
    <main class="relative z-10 flex-1 px-3 sm:px-4 py-4">
        <div class="max-w-5xl mx-auto space-y-4">
            <!-- Хедер -->
            <header class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-slate-900/80 border border-slate-700 text-[11px] text-slate-300 mb-2">
                        Управление персоналом
                    </div>
                    <h1 class="text-2xl sm:text-3xl font-semibold text-slate-50 mb-1">
                        Сотрудники и администраторы
                    </h1>
                    <p class="text-sm text-slate-400 max-w-xl">
                        Приглашайте сотрудников по email, назначайте роль (официант / кухня / бар / админ),
                        временно отключайте доступ и при необходимости сбрасывайте пароль.
                    </p>
                </div>
                <div class="text-xs text-slate-400 flex flex-col items-start sm:items-end gap-1">
                    <div class="px-3 py-1 rounded-full bg-slate-900/80 border border-slate-700 text-slate-200">
                        Всего пользователей: <?= count($links) ?>
                    </div>
                    <div class="text-[11px] text-slate-500">
                        Владелец видит всё, админ управляет операционными ролями.
                    </div>
                </div>
            </header>

            <!-- Сообщения -->
            <?php if ($success): ?>
                <div class="rounded-2xl bg-emerald-500/10 border border-emerald-500/60 px-4 py-3 text-sm text-emerald-100">
                    <?= e($success) ?>
                </div>
            <?php endif; ?>

            <?php if ($infoPassword): ?>
                <div class="rounded-2xl bg-amber-500/10 border border-amber-500/70 px-4 py-3 text-sm text-amber-100">
                    Создан новый пользователь <strong><?= e($infoPassword['email']) ?></strong>.<br>
                    Временный пароль:
                    <span class="font-mono inline-flex px-2 py-0.5 rounded bg-slate-950 border border-slate-800">
                        <?= e($infoPassword['password']) ?>
                    </span><br>
                    Передайте его пользователю и попросите сменить пароль после входа.
                </div>
            <?php endif; ?>

            <?php if ($errors): ?>
                <div class="rounded-2xl bg-rose-500/10 border border-rose-500/60 px-4 py-3 text-sm text-rose-100 space-y-1">
                    <?php foreach ($errors as $err): ?>
                        <div><?= e($err) ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- Форма приглашения -->
            <section class="bg-slate-950/90 border border-slate-800 rounded-3xl p-4 sm:p-5 shadow-xl shadow-slate-950/70">
                <h2 class="text-lg font-semibold text-slate-50 mb-2">Пригласить пользователя</h2>
                <p class="text-xs text-slate-400 mb-3">
                    Введите email и имя. Если пользователя ещё нет в системе — он будет создан автоматически.
                    Если уже есть — просто привяжем к ресторану с выбранной ролью.
                </p>
                <form method="post" class="grid md:grid-cols-5 gap-3 items-end">
                    <input type="hidden" name="action" value="invite_user">
                    <input type="hidden" name="csrf_token" value="<?= e($staffManageCsrf) ?>">
                    <div class="md:col-span-2">
                        <label class="block text-[11px] text-slate-300 mb-1">E-mail</label>
                        <input type="email" name="email" required
                               class="w-full rounded-2xl bg-slate-900/80 border border-slate-700 px-3 py-2 text-sm text-slate-50 placeholder:text-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/70 focus:border-emerald-500/70">
                    </div>
                    <div>
                        <label class="block text-[11px] text-slate-300 mb-1">Имя</label>
                        <input type="text" name="name" required
                               class="w-full rounded-2xl bg-slate-900/80 border border-slate-700 px-3 py-2 text-sm text-slate-50 placeholder:text-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/70 focus:border-emerald-500/70">
                    </div>
                    <div>
                        <label class="block text-[11px] text-slate-300 mb-1">Роль в ресторане</label>
                        <select name="role"
                                class="w-full rounded-2xl bg-slate-900/80 border border-slate-700 px-3 py-2 text-sm text-slate-50 focus:outline-none focus:ring-2 focus:ring-emerald-500/70 focus:border-emerald-500/70">
                            <?php foreach (restaurant_staff_role_options($isOwner) as $roleValue => $roleLabel): ?>
                                <option value="<?= e($roleValue) ?>" <?= $roleValue === 'waiter' ? 'selected' : '' ?>><?= e($roleLabel) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-[11px] text-slate-300 mb-1">Станция (опц.)</label>
                        <select name="station"
                                class="w-full rounded-2xl bg-slate-900/80 border border-slate-700 px-3 py-2 text-sm text-slate-50 focus:outline-none focus:ring-2 focus:ring-emerald-500/70 focus:border-emerald-500/70">
                            <option value="">—</option>
                            <option value="hot">Горячий</option>
                            <option value="cold">Холодный</option>
                            <option value="bar">Бар</option>
                            <option value="dessert">Десерты</option>
                            <option value="grill">Гриль</option>
                            <option value="pizza">Пицца</option>
                            <option value="sushi">Суши</option>
                        </select>
                    </div>
                    <div class="md:col-span-5">
                        <button type="submit"
                                class="inline-flex items-center justify-center px-4 py-2.5 rounded-2xl bg-emerald-500 hover:bg-emerald-400 text-sm font-semibold text-slate-950 shadow-lg shadow-emerald-500/40 transition">
                            Пригласить / привязать
                        </button>
                    </div>
                </form>
            </section>

            <!-- Список пользователей -->
            <section class="bg-slate-950/90 border border-slate-800 rounded-3xl p-4 sm:p-5 shadow-xl shadow-slate-950/70">
                <div class="flex items-center justify-between mb-3">
                    <div>
                        <h2 class="text-lg font-semibold text-slate-50">Пользователи ресторана</h2>
                        <p class="text-xs text-slate-400">
                            Владелец видит всех, админ — только управляет сотрудниками.
                        </p>
                    </div>
                </div>

                <?php if (!$links): ?>
                    <div class="text-sm text-slate-400">
                        Пока никого нет. Пригласите первого сотрудника выше.
                    </div>
                <?php else: ?>
                    <div class="hidden md:grid md:grid-cols-[minmax(0,3fr)_minmax(0,2.4fr)_minmax(0,2fr)_minmax(0,1.6fr)_minmax(0,2.4fr)] gap-2 text-[11px] text-slate-400 pb-1 border-b border-slate-800 mb-2">
                        <div>Пользователь</div>
                        <div>E-mail</div>
                        <div>Роль</div>
                        <div>Статус</div>
                        <div class="text-right">Действия</div>
                    </div>

                    <div class="space-y-2 text-xs">
                        <?php foreach ($links as $link): ?>
                            <?php
                            $canEditRole = false;
                            $canRemove   = false;

                            if ($link['restaurant_role'] === 'owner') {
                                $canEditRole = false;
                                $canRemove   = false;
                            } elseif ($isOwner) {
                                $canEditRole = true;
                                $canRemove   = true;
                            } elseif ($isAdmin) {
                                if (in_array((string)$link['restaurant_role'], ['staff', 'waiter', 'kitchen', 'cold', 'dessert', 'grill', 'pizza', 'sushi', 'bar'], true)) {
                                    $canEditRole = true; // но только staff ↔ staff
                                    $canRemove   = true;
                                }
                            }

                            $badgeClass = restaurant_staff_role_badge_class((string)$link['restaurant_role']);
                            $isLinkActive = !$urHasActive || (int)($link['is_active'] ?? 1) === 1;
                            $stationLabel = (string)($link['station'] ?? '');
                            ?>
                            <div class="flex flex-col md:grid md:grid-cols-[minmax(0,3fr)_minmax(0,2.4fr)_minmax(0,2fr)_minmax(0,1.6fr)_minmax(0,2.4fr)] gap-2 px-3 py-2.5 rounded-2xl bg-slate-900/80 border border-slate-800">
                                <!-- Пользователь -->
                                <div>
                                    <div class="flex items-center gap-2">
                                        <div class="text-sm font-semibold text-slate-50 truncate">
                                            <?= e($link['name'] ?: ('User #'.$link['user_id'])) ?>
                                        </div>
                                        <span class="text-[10px] text-slate-500">
                                            #<?= (int)$link['user_id'] ?>
                                        </span>
                                    </div>
                                </div>

                                <!-- Email -->
                                <div class="md:flex md:items-center">
                                    <?php if (!empty($link['email'])): ?>
                                        <div class="text-slate-300 text-[11px] md:text-xs">
                                            <?= e($link['email']) ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="text-slate-500 text-[11px]">
                                            E-mail не указан
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <!-- Роль -->
                                <div class="md:flex md:items-center">
                                    <span class="inline-flex items-center px-2 py-1 rounded-full border text-[10px] <?= $badgeClass ?>">
                                        <?= e(human_rest_role($link['restaurant_role'])) ?>
                                    </span>
                                    <?php if ($urHasStation && $stationLabel !== ''): ?>
                                        <span class="inline-flex items-center ml-1 px-2 py-1 rounded-full border border-slate-700 text-[10px] text-slate-300 bg-slate-950/70">
                                            <?= e($stationLabel) ?>
                                        </span>
                                    <?php endif; ?>
                                </div>

                                <!-- Активность -->
                                <div class="md:flex md:items-center">
                                    <?php if ($isLinkActive): ?>
                                        <span class="inline-flex items-center px-2 py-1 rounded-full border border-emerald-500/50 bg-emerald-500/10 text-[10px] text-emerald-200">
                                            Активен
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center px-2 py-1 rounded-full border border-rose-500/50 bg-rose-500/10 text-[10px] text-rose-200">
                                            Отключён
                                        </span>
                                    <?php endif; ?>
                                </div>

                                <!-- Действия -->
                                <div class="flex flex-wrap md:justify-end items-center gap-2 text-[11px]">
                                    <?php if ($canEditRole): ?>
                                        <form method="post" class="flex items-center gap-1">
                                            <input type="hidden" name="action" value="change_role">
                                            <input type="hidden" name="link_id" value="<?= (int)$link['id'] ?>">
                                            <input type="hidden" name="csrf_token" value="<?= e($staffManageCsrf) ?>">
                                            <select name="role"
                                                    class="rounded-xl bg-slate-950/80 border border-slate-700 px-2 py-1 text-[11px] text-slate-100 focus:outline-none focus:ring-1 focus:ring-emerald-500/70">
                                                <?php foreach (restaurant_staff_role_options($isOwner) as $roleValue => $roleLabel): ?>
                                                    <option value="<?= e($roleValue) ?>" <?= $link['restaurant_role'] === $roleValue ? 'selected' : '' ?>><?= e($roleLabel) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <?php if ($urHasStation): ?>
                                                <select name="station"
                                                        class="rounded-xl bg-slate-950/80 border border-slate-700 px-2 py-1 text-[11px] text-slate-100 focus:outline-none focus:ring-1 focus:ring-emerald-500/70">
                                                    <option value="" <?= $stationLabel === '' ? 'selected' : '' ?>>—</option>
                                                    <option value="hot" <?= $stationLabel === 'hot' ? 'selected' : '' ?>>hot</option>
                                                    <option value="cold" <?= $stationLabel === 'cold' ? 'selected' : '' ?>>cold</option>
                                                    <option value="bar" <?= $stationLabel === 'bar' ? 'selected' : '' ?>>bar</option>
                                                    <option value="dessert" <?= $stationLabel === 'dessert' ? 'selected' : '' ?>>dessert</option>
                                                    <option value="grill" <?= $stationLabel === 'grill' ? 'selected' : '' ?>>grill</option>
                                                    <option value="pizza" <?= $stationLabel === 'pizza' ? 'selected' : '' ?>>pizza</option>
                                                    <option value="sushi" <?= $stationLabel === 'sushi' ? 'selected' : '' ?>>sushi</option>
                                                </select>
                                            <?php endif; ?>
                                            <button type="submit"
                                                    class="px-3 py-1 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-100">
                                                OK
                                            </button>
                                        </form>
                                    <?php endif; ?>

                                    <?php if ($canEditRole): ?>
                                        <form method="post"
                                              onsubmit="return confirm('Сбросить пароль сотруднику?');">
                                            <input type="hidden" name="action" value="reset_password">
                                            <input type="hidden" name="link_id" value="<?= (int)$link['id'] ?>">
                                            <input type="hidden" name="csrf_token" value="<?= e($staffManageCsrf) ?>">
                                            <button type="submit"
                                                    class="px-3 py-1 rounded-xl bg-slate-800/80 border border-slate-700 text-slate-100 hover:bg-slate-700">
                                                Сбросить пароль
                                            </button>
                                        </form>
                                    <?php endif; ?>

                                    <?php if ($canEditRole && $urHasActive): ?>
                                        <form method="post">
                                            <input type="hidden" name="action" value="toggle_active">
                                            <input type="hidden" name="link_id" value="<?= (int)$link['id'] ?>">
                                            <input type="hidden" name="target_active" value="<?= $isLinkActive ? '0' : '1' ?>">
                                            <input type="hidden" name="csrf_token" value="<?= e($staffManageCsrf) ?>">
                                            <button type="submit"
                                                    class="px-3 py-1 rounded-xl border <?= $isLinkActive ? 'border-rose-500/70 text-rose-100 bg-rose-500/10 hover:bg-rose-500/20' : 'border-emerald-500/70 text-emerald-100 bg-emerald-500/10 hover:bg-emerald-500/20' ?>">
                                                <?= $isLinkActive ? 'Отключить' : 'Включить' ?>
                                            </button>
                                        </form>
                                    <?php endif; ?>

                                    <?php if ($canRemove): ?>
                                        <form method="post"
                                              onsubmit="return confirm('Отключить этого пользователя от ресторана?');">
                                            <input type="hidden" name="action" value="remove_link">
                                            <input type="hidden" name="link_id" value="<?= (int)$link['id'] ?>">
                                            <input type="hidden" name="csrf_token" value="<?= e($staffManageCsrf) ?>">
                                            <button type="submit"
                                                    class="px-3 py-1 rounded-xl bg-rose-500/10 border border-rose-500/70 text-rose-100 hover:bg-rose-500/20">
                                                Удалить
                                            </button>
                                        </form>
                                    <?php endif; ?>

                                    <?php if (!$canEditRole && !$canRemove): ?>
                                        <span class="text-slate-500">
                                            Управление недоступно для этой роли.
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </main>
</div>
</body>
</html>
