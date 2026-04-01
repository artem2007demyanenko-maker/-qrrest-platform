<?php
require_once __DIR__ . '/../app/bootstrap.php';

$config     = require __DIR__ . '/../app/config.php';
$mainDomain = $config['app']['main_domain'];
$protocol   = $config['app']['protocol'] ?? 'https';

$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$ipHash = security_hash_ip($ip);
// Stricter login rate limit: 5 attempts per minute per IP (leverages existing security_rate_limit).
security_rate_limit('login:' . $ipHash, 5, 60);

// Referral: GET ?ref=CODE — rate limit 20/hour/IP, затем клик (только если таблицы growth есть)
if (isset($_GET['ref']) && trim((string)$_GET['ref']) !== '') {
    security_rate_limit('referral_click:' . $ipHash, 20, 3600);
    $refCode = trim((string)$_GET['ref']);
    require_once __DIR__ . '/../app/schema_guard.php';
    if (schema_guard_growth_ready()) {
        try {
            require_once __DIR__ . '/../app/growth.php';
            $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
            growth_record_click($refCode, $ipHash, hash('sha256', substr($ua, 0, 512)));
        } catch (Throwable $e) {
            error_log('GROWTH_REF_CLICK_ERROR uri=' . ($_SERVER['REQUEST_URI'] ?? '') . ' ' . $e->getMessage());
        }
    }
    $_SESSION['referral_code'] = $refCode;
}

$user          = auth_user();
// return_to: строго 1 раз, только безопасный короткий путь; без вложенного redirect и многократного encoding
$redirectParam = isset($_GET['redirect']) ? trim((string)$_GET['redirect']) : null;
if ($redirectParam !== null && $redirectParam !== '') {
    for ($i = 0; $i < 2; $i++) {
        $decoded = rawurldecode($redirectParam);
        if ($decoded === $redirectParam) {
            break;
        }
        $redirectParam = $decoded;
    }
    $safeReturnTo = function_exists('auth_safe_return_to') ? auth_safe_return_to($redirectParam) : '/owner/dashboard.php';
    if ($safeReturnTo !== '/owner/dashboard.php') {
        $_SESSION['return_to'] = $safeReturnTo;
    }
    $redirectParam = $safeReturnTo !== '/owner/dashboard.php' ? $safeReturnTo : null;
}

// Подготовим PDO для логов и запросов
$pdo = function_exists('db') ? db() : ($GLOBALS['pdo'] ?? null);


function redirect_after_login(
    ?array $user,
    ?array $currentRestaurant,
    string $mainDomain,
    string $protocol,
    ?string $redirectParam,
    ?PDO $pdo = null
): void {
    if (!$user) {
        header("Location: /login.php");
        exit;
    }

    // Владелец платформы всегда идёт на основной домен /project-admin/
    if (($user['global_role'] ?? null) === 'project_owner') {
        if ($pdo instanceof PDO && function_exists('add_log')) {
            add_log($pdo, [
                'user_id'       => (int)$user['id'],
                'restaurant_id' => null,
                'level'         => 'info',
                'action'        => 'login_redirect_project_owner',
                'message'       => 'Владелец платформы отправлен в /project-admin/',
            ]);
        }

        header("Location: {$protocol}://{$mainDomain}/project-admin/");
        exit;
    }


    if (!$currentRestaurant && $pdo instanceof PDO) {
        try {
            if (!function_exists('schema_guard_restaurants_deleted_sql')) {
                require_once __DIR__ . '/../app/schema_guard.php';
            }
            $rStatusCond = (function_exists('db_column_exists') && db_column_exists('restaurants', 'status')) ? ' AND r.status = \'active\'' : '';
            $deletedJoin = function_exists('schema_guard_restaurants_deleted_sql') ? schema_guard_restaurants_deleted_sql('r') : '';
            $stmt = $pdo->prepare("
                SELECT r.id, r.name, r.subdomain, ur.restaurant_role
                FROM users_restaurants ur
                JOIN restaurants r ON r.id = ur.restaurant_id {$deletedJoin}
                WHERE ur.user_id = :uid
                  AND ur.restaurant_role IN ('staff','admin')
                  $rStatusCond
                ORDER BY ur.id ASC
                LIMIT 1
            ");
            $stmt->execute([':uid' => (int)$user['id']]);
            $staffRest = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($staffRest && !empty($staffRest['subdomain'])) {
                if ($pdo instanceof PDO && function_exists('add_log')) {
                    add_log($pdo, [
                        'user_id'       => (int)$user['id'],
                        'restaurant_id' => (int)$staffRest['id'],
                        'level'         => 'info',
                        'action'        => 'login_redirect_staff',
                        'message'       => 'Сотрудник/админ отправлен сразу в /staff/orders.php для ресторана '
                            . '#' . (int)$staffRest['id'] . ' (' . $staffRest['subdomain'] . ')',
                    ]);
                }

                $sub = $staffRest['subdomain'];
                header("Location: {$protocol}://{$sub}.{$mainDomain}/staff/orders.php");
                exit;
            }
        } catch (PDOException $e) {
            if ($pdo instanceof PDO && function_exists('add_log')) {
                add_log($pdo, [
                    'user_id'       => (int)$user['id'],
                    'restaurant_id' => null,
                    'level'         => 'error',
                    'action'        => 'login_staff_redirect_error',
                    'message'       => 'Ошибка при поиске ресторана для staff/admin: ' . $e->getMessage(),
                ]);
            }

        }
    }


    if ($currentRestaurant) {
        $restId = (int)$currentRestaurant['id'];

        if ($pdo instanceof PDO) {
            try {
                $stmt = $pdo->prepare("
                    SELECT restaurant_role
                    FROM users_restaurants
                    WHERE user_id = :uid
                      AND restaurant_id = :rid
                    LIMIT 1
                ");
                $stmt->execute([
                    ':uid' => (int)$user['id'],
                    ':rid' => $restId,
                ]);
                $link = $stmt->fetch(PDO::FETCH_ASSOC);

                $role = $link['restaurant_role'] ?? null;


                if (in_array($role, ['staff', 'admin'], true)) {
                    if (function_exists('add_log')) {
                        add_log($pdo, [
                            'user_id'       => (int)$user['id'],
                            'restaurant_id' => $restId,
                            'level'         => 'info',
                            'action'        => 'login_redirect_staff_subdomain',
                            'message'       => 'Сотрудник/админ вошёл на поддомен ресторана, отправляем в /staff/orders.php',
                        ]);
                    }

                    header("Location: /staff/orders.php");
                    exit;
                }
            } catch (PDOException $e) {
                if ($pdo instanceof PDO && function_exists('add_log')) {
                    add_log($pdo, [
                        'user_id'       => (int)$user['id'],
                        'restaurant_id' => $restId,
                        'level'         => 'error',
                        'action'        => 'login_staff_subdomain_check_error',
                        'message'       => 'Ошибка при проверке роли на поддомене: ' . $e->getMessage(),
                    ]);
                }

            }
        }


        if ($pdo instanceof PDO && function_exists('add_log')) {
            add_log($pdo, [
                'user_id'       => (int)$user['id'],
                'restaurant_id' => $restId,
                'level'         => 'info',
                'action'        => 'login_redirect_restaurant_domain',
                'message'       => 'Пользователь зашёл на поддомен ресторана, отправляем в /restaurant/dashboard.php',
            ]);
        }

        header("Location: /restaurant/dashboard.php");
        exit;
    }


    $target = $_SESSION['return_to'] ?? '/owner/dashboard.php';
    if (isset($_SESSION['return_to'])) {
        unset($_SESSION['return_to']);
    }
    $target = (function_exists('auth_safe_return_to') ? auth_safe_return_to($target) : $target);
    if ($target === '') {
        $target = '/owner/dashboard.php';
    }
    if ($pdo instanceof PDO && function_exists('add_log')) {
        add_log($pdo, [
            'user_id'       => (int)$user['id'],
            'restaurant_id' => null,
            'level'         => 'info',
            'action'        => 'login_redirect_owner_dashboard',
            'message'       => 'Пользователь отправлен: ' . $target,
        ]);
    }
    if (function_exists('safe_redirect')) {
        safe_redirect($target);
    } else {
        header('Location: ' . $target);
    }
    exit;
}

// Если уже залогинен — сразу перекидываем
if ($user) {
    redirect_after_login($user, $currentRestaurant, $mainDomain, $protocol, $redirectParam, $pdo instanceof PDO ? $pdo : null);
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (auth_login($email, $password)) {
        $user = auth_user(); // перечитаем

        // ЛОГ УСПЕШНОГО ВХОДА
        if ($pdo instanceof PDO && function_exists('add_log') && $user) {
            $msgParts = [
                'E-mail: ' . $email,
                'Глобальная роль: ' . ($user['global_role'] ?? '—'),
            ];
            global $currentRestaurant;
            if (!empty($currentRestaurant['id']) && !empty($currentRestaurant['name'])) {
                $msgParts[] = 'Точка входа: поддомен ресторана #' . (int)$currentRestaurant['id'] . ' — ' . $currentRestaurant['name'];
            } else {
                $msgParts[] = 'Точка входа: основной домен ' . $mainDomain;
            }

            add_log($pdo, [
                'user_id'       => (int)$user['id'],
                'restaurant_id' => !empty($currentRestaurant['id']) ? (int)$currentRestaurant['id'] : null,
                'level'         => 'info',
                'action'        => 'login_success',
                'message'       => implode("\n", $msgParts),
            ]);
        }
        if (function_exists('audit_log')) {
            require_once __DIR__ . '/../app/audit.php';
            audit_log('login_success', 'user', (string)$user['id']);
        }

        redirect_after_login($user, $currentRestaurant, $mainDomain, $protocol, $redirectParam, $pdo instanceof PDO ? $pdo : null);
    } else {
        $error = 'Неверный email или пароль';

        // ЛОГ НЕУСПЕШНОГО ВХОДА
        if ($pdo instanceof PDO && function_exists('add_log')) {
            $ip = $_SERVER['REMOTE_ADDR'] ?? null;

            add_log($pdo, [
                'user_id'       => null,
                'restaurant_id' => !empty($currentRestaurant['id']) ? (int)$currentRestaurant['id'] : null,
                'level'         => 'security',
                'action'        => 'login_failed',
                'message'       => 'Неуспешный вход в систему' . "\n"
                    . 'E-mail: ' . $email . "\n"
                    . 'IP: ' . ($ip ?: 'неизвестен') . "\n"
                    . 'Точка входа: ' . ($currentRestaurant ? 'поддомен ресторана' : 'основной домен ' . $mainDomain),
            ]);
        }
    }
}
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Вход — <?= e(BRAND_NAME) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?= brand_head_tags() ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="/assets/css/motion.css">
    <style>
        body { font-family: Inter, system-ui, sans-serif; }
        .login-bg { background: linear-gradient(135deg, #0B0F19 0%, #121826 50%, #0B0F19 100%); }
        .login-panel { background: radial-gradient(ellipse 80% 80% at 70% 50%, rgba(99, 102, 241, 0.12), transparent 50%); }
    </style>
</head>
<body class="min-h-screen text-gray-300 antialiased login-bg overflow-x-hidden">
<div class="min-h-screen flex flex-col md:flex-row">
    <div class="flex-1 flex items-center justify-center p-4 md:p-8">
        <div class="w-full max-w-md section reveal">
            <div class="rounded-xl border border-gray-800 bg-[#121826] shadow-xl shadow-black/20 p-6 sm:p-8 card-motion">
                <div class="flex flex-col items-center gap-3 mb-6">
                    <?= brand_header_cluster_html(false, 'w-10 h-10 text-slate-400') ?>
                    <p class="text-sm text-slate-500">Вход в аккаунт</p>
                </div>

                <?php if ($error): ?>
                    <div class="mb-4 rounded-lg bg-red-500/10 border border-red-500/30 text-red-400 px-4 py-2 text-sm">
                        <?= e($error) ?>
                    </div>
                <?php endif; ?>

                <form method="post" class="space-y-4">
                    <div>
                        <label class="block text-xs text-gray-400 mb-1">E-mail</label>
                        <input type="email" name="email" required autocomplete="email"
                               class="w-full min-h-[48px] rounded-xl bg-gray-900 border border-gray-700 px-4 py-3 text-base text-[#F3F4F6] focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
                    </div>
                    <div>
                        <label class="block text-xs text-gray-400 mb-1">Пароль</label>
                        <input type="password" name="password" required autocomplete="current-password"
                               class="w-full min-h-[48px] rounded-xl bg-gray-900 border border-gray-700 px-4 py-3 text-base text-[#F3F4F6] focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
                    </div>
                    <button type="submit"
                            class="btn-motion w-full mt-2 inline-flex items-center justify-center min-h-[48px] rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white font-semibold py-3 text-base touch-manipulation">
                        Войти
                    </button>
                </form>
                <p class="mt-4 text-center text-sm text-gray-400">
                    Нет аккаунта? <a href="/signup.php" class="text-indigo-400 hover:text-indigo-300 transition-colors">Зарегистрировать ресторан</a>
                </p>
            </div>
        </div>
    </div>
    <div class="hidden lg:flex flex-1 items-center justify-center p-8 login-panel border-l border-gray-800">
        <div class="max-w-sm rounded-xl border border-gray-800 bg-[#121826]/60 backdrop-blur p-6 shadow-xl shadow-black/20 text-center section reveal card-motion">
            <div class="w-12 h-12 rounded-xl bg-indigo-500/20 flex items-center justify-center mx-auto mb-4 text-indigo-400">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
            </div>
            <h3 class="text-lg font-semibold tracking-tight text-[#F3F4F6] mb-2">Единый вход</h3>
            <p class="text-sm text-gray-400">Владельцы, сотрудники и админы — один аккаунт для всех ресторанов.</p>
        </div>
    </div>
</div>
<script src="/assets/js/motion.js"></script>
</body>
</html>
