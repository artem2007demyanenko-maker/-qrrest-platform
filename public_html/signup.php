<?php
/**
 * Restaurant self-registration: form, validation, create owner+restaurant, auto-login, redirect to setup.
 */

$rid = bin2hex(random_bytes(4));
require_once __DIR__ . '/../app/bootstrap.php';
set_exception_handler(function (Throwable $e) use ($rid) {
    error_log('STABILITY_ERROR signup.php rid=' . $rid . ' ' . $e->getMessage());
    if (!headers_sent()) {
        http_response_code(500);
    }
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Ошибка</title></head><body><p>Что-то пошло не так. Попробуйте позже.</p><p style="font-size:11px;color:#666">RID: ' . htmlspecialchars($rid) . '</p></body></html>';
    exit;
});

require_once __DIR__ . '/../app/onboarding_repo.php';
require_once __DIR__ . '/../app/referral_repo.php';

if (!function_exists('e')) {
    function e($v): string {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

$config    = require __DIR__ . '/../app/config.php';
$mainDomain = $config['app']['main_domain'] ?? 'lvh.me';
$protocol   = $config['app']['protocol'] ?? 'http';

// Lightweight rate limit: 3 signup attempts per minute per IP (reuses security_rate_limit backend).
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
if (function_exists('security_hash_ip') && function_exists('security_rate_limit')) {
    $ipHash = security_hash_ip($ip);
    security_rate_limit('signup:' . $ipHash, 3, 60);
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

if (isset($_GET['ref']) && is_string($_GET['ref']) && trim($_GET['ref']) !== '') {
    $_SESSION['signup_ref'] = trim($_GET['ref']);
}

$errors  = [];
$success = false;
$redirectUrl = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfOk = isset($_SESSION['csrf'], $_POST['csrf']) && hash_equals((string)$_SESSION['csrf'], (string)$_POST['csrf']);
    if (!$csrfOk) {
        $errors[] = 'Неверный запрос. Обновите страницу и попробуйте снова.';
    } else {
        $restaurant_name   = trim((string)($_POST['restaurant_name'] ?? ''));
        $owner_name        = trim((string)($_POST['owner_name'] ?? ''));
        $email             = trim((string)($_POST['email'] ?? ''));
        $password          = (string)($_POST['password'] ?? '');
        $phone             = trim((string)($_POST['phone'] ?? ''));
        $desired_subdomain = trim((string)($_POST['desired_subdomain'] ?? ''));
        $consent           = isset($_POST['consent']) && $_POST['consent'] === '1';

        if ($restaurant_name === '') {
            $errors[] = 'Укажите название ресторана.';
        }
        if ($owner_name === '') {
            $errors[] = 'Укажите ваше имя.';
        }
        if ($email === '') {
            $errors[] = 'Укажите email.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Некорректный email.';
        }
        if (strlen($password) < 6) {
            $errors[] = 'Пароль должен быть не короче 6 символов.';
        }
        if ($desired_subdomain === '') {
            $errors[] = 'Укажите поддомен.';
        } else {
            $valid = onboarding_validate_subdomain($desired_subdomain);
            if (!$valid['ok']) {
                $errors[] = $valid['error'] ?? 'Недопустимый поддомен.';
            }
        }
        if (!$consent) {
            $errors[] = 'Необходимо согласие с условиями.';
        }

        if (empty($errors)) {
            try {
                $result = onboarding_full_register([
                    'restaurant_name'   => $restaurant_name,
                    'owner_name'        => $owner_name,
                    'email'             => $email,
                    'password'          => $password,
                    'phone'             => $phone,
                    'desired_subdomain' => $desired_subdomain,
                ]);
                auth_start_session();
                $_SESSION['user_id'] = $result['user_id'];
                if (!empty($_SESSION['signup_ref'])) {
                    referral_mark_signed_up_by_code($_SESSION['signup_ref'], $email);
                    unset($_SESSION['signup_ref']);
                }
                $redirectUrl = $protocol . '://' . $result['subdomain'] . '.' . $mainDomain . '/restaurant/setup.php';
                $success = true;
            } catch (RuntimeException $e) {
                error_log('STABILITY_ERROR signup.php runtime rid=' . $rid . ' ' . $e->getMessage());
                $errors[] = 'Не удалось завершить регистрацию. Проверьте данные и попробуйте снова.';
            } catch (InvalidArgumentException $e) {
                error_log('STABILITY_ERROR signup.php invalid rid=' . $rid . ' ' . $e->getMessage());
                $errors[] = 'Некорректные данные регистрации. Исправьте поля и попробуйте снова.';
            } catch (Throwable $e) {
                error_log('STABILITY_ERROR signup.php register rid=' . $rid . ' ' . $e->getMessage());
                $errors[] = 'Не удалось завершить регистрацию. Попробуйте позже или другой поддомен.';
            }
        }
    }
}

if ($success && $redirectUrl !== null) {
    header('Location: ' . $redirectUrl, true, 302);
    exit;
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Регистрация ресторана — <?= e(BRAND_NAME) ?></title>
    <?= brand_head_tags() ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="/assets/css/motion.css">
    <style>
        body { font-family: Inter, system-ui, sans-serif; }
        .signup-gradient { background: linear-gradient(135deg, #0B0F19 0%, #121826 50%, #0B0F19 100%); }
        .panel-gradient { background: radial-gradient(ellipse 80% 80% at 70% 50%, rgba(99, 102, 241, 0.12), transparent 50%), radial-gradient(ellipse 50% 50% at 20% 80%, rgba(34, 197, 94, 0.08), transparent 50%); }
    </style>
</head>
<body class="min-h-screen text-gray-300 antialiased signup-gradient">
<div class="min-h-screen flex flex-col md:flex-row">
    <!-- Left: Form -->
    <div class="flex-1 flex items-center justify-center p-4 md:p-8">
        <div class="w-full max-w-md section reveal">
            <div class="rounded-xl border border-gray-800 bg-[#121826] shadow-xl shadow-black/20 p-6 md:p-8 card-motion">
                <div class="flex flex-col items-center gap-3 mb-5">
                    <?= brand_header_cluster_html(false, 'w-10 h-10 text-slate-400') ?>
                    <h1 class="text-lg font-medium text-slate-400">Регистрация ресторана</h1>
                </div>
                <p class="text-gray-400 text-sm text-center mb-6">Создайте аккаунт и получите поддомен для заказов по QR</p>

                <?php if ($errors): ?>
                    <div class="mb-4 rounded-lg bg-red-500/10 border border-red-500/30 px-4 py-3 text-sm text-red-400 space-y-1">
                        <?php foreach ($errors as $err): ?>
                            <div><?= e($err) ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <form method="post" class="space-y-4">
                    <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                    <div>
                        <label for="restaurant_name" class="block text-xs text-gray-400 mb-1">Название ресторана *</label>
                        <input type="text" id="restaurant_name" name="restaurant_name" required
                               value="<?= e($_POST['restaurant_name'] ?? '') ?>"
                               class="w-full rounded-lg bg-gray-900 border border-gray-700 px-3 py-2 text-sm text-[#F3F4F6] focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
                    </div>
                    <div>
                        <label for="owner_name" class="block text-xs text-gray-400 mb-1">Ваше имя *</label>
                        <input type="text" id="owner_name" name="owner_name" required
                               value="<?= e($_POST['owner_name'] ?? '') ?>"
                               class="w-full rounded-lg bg-gray-900 border border-gray-700 px-3 py-2 text-sm text-[#F3F4F6] focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
                    </div>
                    <div>
                        <label for="email" class="block text-xs text-gray-400 mb-1">Email *</label>
                        <input type="email" id="email" name="email" required
                               value="<?= e($_POST['email'] ?? '') ?>"
                               class="w-full rounded-lg bg-gray-900 border border-gray-700 px-3 py-2 text-sm text-[#F3F4F6] focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
                    </div>
                    <div>
                        <label for="password" class="block text-xs text-gray-400 mb-1">Пароль (мин. 6 символов) *</label>
                        <input type="password" id="password" name="password" required minlength="6"
                               class="w-full rounded-lg bg-gray-900 border border-gray-700 px-3 py-2 text-sm text-[#F3F4F6] focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
                    </div>
                    <div>
                        <label for="phone" class="block text-xs text-gray-400 mb-1">Телефон (необязательно)</label>
                        <input type="text" id="phone" name="phone"
                               value="<?= e($_POST['phone'] ?? '') ?>"
                               class="w-full rounded-lg bg-gray-900 border border-gray-700 px-3 py-2 text-sm text-[#F3F4F6] focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
                    </div>
                    <div>
                        <label for="desired_subdomain" class="block text-xs text-gray-400 mb-1">Поддомен (латиница, цифры, дефис, 3–32 символа) *</label>
                        <input type="text" id="desired_subdomain" name="desired_subdomain" required
                               value="<?= e($_POST['desired_subdomain'] ?? '') ?>"
                               placeholder="мой-ресторан"
                               class="w-full rounded-lg bg-gray-900 border border-gray-700 px-3 py-2 text-sm text-[#F3F4F6] focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
                        <p class="text-[11px] text-gray-500 mt-0.5">Ваш адрес: <span id="subdomain-preview" class="text-gray-400">поддомен.<?= e($mainDomain) ?></span></p>
                    </div>
                    <div class="flex items-start gap-2">
                        <input type="checkbox" id="consent" name="consent" value="1" class="mt-1 rounded border-gray-600 text-indigo-600 focus:ring-indigo-500"
                            <?= (isset($_POST['consent']) && $_POST['consent'] === '1') ? 'checked' : '' ?>>
                        <label for="consent" class="text-xs text-gray-400">Согласен с условиями использования сервиса *</label>
                    </div>
                    <button type="submit" class="btn-motion w-full py-2.5 rounded-lg bg-indigo-600 hover:bg-indigo-500 text-white font-medium text-sm">
                        Зарегистрировать ресторан
                    </button>
                </form>

                <p class="mt-6 text-center text-sm text-gray-400">
                    Уже есть аккаунт? <a href="/login.php" class="text-indigo-400 hover:text-indigo-300 transition-colors">Войти</a>
                </p>
            </div>
        </div>
    </div>

    <!-- Right: Product preview -->
    <div class="hidden lg:flex flex-1 items-center justify-center p-8 panel-gradient border-l border-gray-800">
        <div class="max-w-sm space-y-6 text-center section reveal">
            <div class="rounded-xl border border-gray-800 bg-[#121826]/60 backdrop-blur p-6 shadow-xl shadow-black/20 card-motion">
                <div class="w-12 h-12 rounded-xl bg-indigo-500/20 flex items-center justify-center mx-auto mb-4 text-indigo-400">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm12 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1z"/></svg>
                </div>
                <h3 class="text-lg font-semibold tracking-tight text-[#F3F4F6] mb-2">QR-меню и заказы</h3>
                <p class="text-sm text-gray-400">Меню по QR, заказы с телефона гостя, допродажи и аналитика в одной платформе.</p>
            </div>
            <p class="text-xs text-gray-500">Бесплатный пробный период · Без привязки карты</p>
        </div>
    </div>
</div>
<script>
(function(){
    var main = '<?= e($mainDomain) ?>';
    var el = document.getElementById('desired_subdomain');
    var preview = document.getElementById('subdomain-preview');
    if (el && preview) {
        function up() {
            var v = (el.value || '').trim().toLowerCase().replace(/[^a-z0-9-]/g, '');
            preview.textContent = (v || 'поддомен') + '.' + main;
        }
        el.addEventListener('input', up);
        el.addEventListener('change', up);
        up();
    }
})();
</script>
<script src="/assets/js/motion.js"></script>
</body>
</html>
