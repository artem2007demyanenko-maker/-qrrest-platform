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

    if ($action === 'invite_user') {
        $email = trim($_POST['email'] ?? '');
        $name  = trim($_POST['name'] ?? '');
        $role  = $_POST['role'] ?? 'staff';

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Введите корректный email.';
        }
        if ($name === '') {
            $errors[] = 'Введите имя пользователя.';
        }


        if (!in_array($role, ['admin', 'staff'], true)) {
            $role = 'staff';
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

                    $stmt = $pdo->prepare("
                        UPDATE users_restaurants
                        SET restaurant_role = :role
                        WHERE id = :id
                    ");
                    $stmt->execute([
                        'role' => $role,
                        'id'   => $link['id'],
                    ]);

                    $success = 'Роль пользователя в ресторане обновлена.';
                }
            } else {

                $stmt = $pdo->prepare("
                    INSERT INTO users_restaurants (user_id, restaurant_id, restaurant_role)
                    VALUES (:uid, :rest, :role)
                ");
                $stmt->execute([
                    'uid'  => $userId,
                    'rest' => $currentRestaurant['id'],
                    'role' => $role,
                ]);

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


    if ($action === 'change_role') {
        $linkId  = (int)($_POST['link_id'] ?? 0);
        $newRole = $_POST['role'] ?? '';

        if ($linkId <= 0) {
            $errors[] = 'Некорректный идентификатор связи.';
        }

        if (!in_array($newRole, ['admin', 'staff'], true)) {
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
                        if ($link['restaurant_role'] !== 'staff' || $newRole !== 'staff') {
                            $errors[] = 'У вас нет прав менять эту роль.';
                        }
                    }
                }

                if (!$errors) {
                    $stmt = $pdo->prepare("
                        UPDATE users_restaurants
                        SET restaurant_role = :role
                        WHERE id = :id AND restaurant_id = :rest
                    ");
                    $stmt->execute([
                        'role' => $newRole,
                        'id'   => $linkId,
                        'rest' => $currentRestaurant['id'],
                    ]);

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


    if ($action === 'remove_link') {
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

                    if (!$isOwner && $link['restaurant_role'] !== 'staff') {
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
    ORDER BY FIELD(ur.restaurant_role, 'owner','admin','staff'), u.name
");
$stmt->execute(['rest' => $currentRestaurant['id']]);
$links = $stmt->fetchAll(PDO::FETCH_ASSOC);

function human_rest_role(string $r): string {
    return [
        'owner' => 'Владелец ресторана',
        'admin' => 'Администратор',
        'staff' => 'Сотрудник',
    ][$r] ?? $r;
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
                        Приглашайте сотрудников по email, назначайте роли (администратор / сотрудник)
                        и при необходимости отвязывайте их от ресторана.
                    </p>
                </div>
                <div class="text-xs text-slate-400 flex flex-col items-start sm:items-end gap-1">
                    <div class="px-3 py-1 rounded-full bg-slate-900/80 border border-slate-700 text-slate-200">
                        Всего пользователей: <?= count($links) ?>
                    </div>
                    <div class="text-[11px] text-slate-500">
                        Владелец видит всё, админ управляет только сотрудниками.
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
                <form method="post" class="grid md:grid-cols-4 gap-3 items-end">
                    <input type="hidden" name="action" value="invite_user">
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
                            <?php if ($isOwner): ?>
                                <option value="admin">Администратор</option>
                            <?php endif; ?>
                            <option value="staff" selected>Сотрудник</option>
                        </select>
                    </div>
                    <div class="md:col-span-4">
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
                    <div class="hidden md:grid md:grid-cols-[minmax(0,3fr)_minmax(0,2.4fr)_minmax(0,2fr)_minmax(0,2.4fr)] gap-2 text-[11px] text-slate-400 pb-1 border-b border-slate-800 mb-2">
                        <div>Пользователь</div>
                        <div>E-mail</div>
                        <div>Роль</div>
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
                                if ($link['restaurant_role'] === 'staff') {
                                    $canEditRole = true; // но только staff ↔ staff
                                    $canRemove   = true;
                                }
                            }

                            $badgeClass = match ($link['restaurant_role']) {
                                'owner' => 'bg-amber-500/15 border-amber-500/60 text-amber-100',
                                'admin' => 'bg-sky-500/15 border-sky-500/60 text-sky-100',
                                'staff' => 'bg-emerald-500/15 border-emerald-500/60 text-emerald-100',
                                default => 'bg-slate-900 border-slate-700 text-slate-200',
                            };
                            ?>
                            <div class="flex flex-col md:grid md:grid-cols-[minmax(0,3fr)_minmax(0,2.4fr)_minmax(0,2fr)_minmax(0,2.4fr)] gap-2 px-3 py-2.5 rounded-2xl bg-slate-900/80 border border-slate-800">
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
                                </div>

                                <!-- Действия -->
                                <div class="flex flex-wrap md:justify-end items-center gap-2 text-[11px]">
                                    <?php if ($canEditRole): ?>
                                        <form method="post" class="flex items-center gap-1">
                                            <input type="hidden" name="action" value="change_role">
                                            <input type="hidden" name="link_id" value="<?= (int)$link['id'] ?>">
                                            <select name="role"
                                                    class="rounded-xl bg-slate-950/80 border border-slate-700 px-2 py-1 text-[11px] text-slate-100 focus:outline-none focus:ring-1 focus:ring-emerald-500/70">
                                                <?php if ($isOwner): ?>
                                                    <option value="admin" <?= $link['restaurant_role'] === 'admin' ? 'selected' : '' ?>>Администратор</option>
                                                <?php endif; ?>
                                                <option value="staff" <?= $link['restaurant_role'] === 'staff' ? 'selected' : '' ?>>Сотрудник</option>
                                            </select>
                                            <button type="submit"
                                                    class="px-3 py-1 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-100">
                                                OK
                                            </button>
                                        </form>
                                    <?php endif; ?>

                                    <?php if ($canRemove): ?>
                                        <form method="post"
                                              onsubmit="return confirm('Отключить этого пользователя от ресторана?');">
                                            <input type="hidden" name="action" value="remove_link">
                                            <input type="hidden" name="link_id" value="<?= (int)$link['id'] ?>">
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
