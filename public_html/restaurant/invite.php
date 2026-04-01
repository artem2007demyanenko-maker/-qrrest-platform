<?php
/**
 * Invite another restaurant: send invitation email with demo + signup link (with ref code).
 */

$rid = bin2hex(random_bytes(4));
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/referral_repo.php';

set_exception_handler(function (Throwable $e) use ($rid) {
    error_log('INVITE_PAGE rid=' . $rid . ' ' . $e->getMessage());
    if (!headers_sent()) {
        http_response_code(500);
    }
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Ошибка</title></head><body><p>Что-то пошло не так.</p></body></html>';
    exit;
});

require_login();
require_current_restaurant();
require_restaurant_role((int)($currentRestaurant['id'] ?? 0), ['owner', 'admin']);

if (!function_exists('e')) {
    function e($v): string {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

$config = require __DIR__ . '/../../app/config.php';
$mainDomain = $config['app']['main_domain'] ?? 'lvh.me';
$protocol   = $config['app']['protocol'] ?? 'http';
$signupUrl   = $protocol . '://' . $mainDomain . '/signup.php';
$demoUrl     = $protocol . '://demo.' . $mainDomain . '/restaurant/dashboard.php';

$restId = (int)$currentRestaurant['id'];
$errors = [];
$success = false;

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfOk = isset($_SESSION['csrf'], $_POST['csrf']) && hash_equals((string)$_SESSION['csrf'], (string)$_POST['csrf']);
    if (!$csrfOk) {
        $errors[] = 'Неверный запрос. Обновите страницу.';
    } else {
        $restaurant_name = trim((string)($_POST['restaurant_name'] ?? ''));
        $owner_email     = trim((string)($_POST['owner_email'] ?? ''));
        if ($owner_email === '') {
            $errors[] = 'Укажите email приглашаемого.';
        } elseif (!filter_var($owner_email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Некорректный email.';
        }
        if (empty($errors)) {
            list($ok, $errMsg) = referral_create_invite($restId, $owner_email, $signupUrl, $demoUrl);
            if ($ok) {
                $success = true;
            } else {
                $errors[] = $errMsg;
            }
        }
    }
}

$invitedCount = referral_count_invited($restId);
$invitesToday = referral_count_invites_today($restId);
$referralCode = referral_ensure_code_for_restaurant($restId);
$referralLink = $referralCode !== '' ? $signupUrl . '?ref=' . urlencode($referralCode) : $signupUrl;
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?= brand_head_tags() ?>
    <title>Пригласить ресторан — <?= e($currentRestaurant['name']) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="/assets/css/motion.css">
</head>
<body class="min-h-screen bg-slate-950 text-slate-50 flex">
<aside class="w-64 bg-slate-950/80 border-r border-slate-800 p-4 hidden md:block">
    <?= brand_restaurant_sidebar_header_html($currentRestaurant['name']) ?>
    <nav class="space-y-2 text-sm">
        <a href="/restaurant/dashboard.php" class="block px-3 py-2 rounded-lg hover:bg-slate-800/60">Обзор</a>
        <a href="/restaurant/invite.php" class="block px-3 py-2 rounded-lg bg-slate-800/70">Пригласить ресторан</a>
        <a href="/restaurant/revenue.php" class="block px-3 py-2 rounded-lg hover:bg-slate-800/60">Доход</a>
        <a href="/restaurant/settings.php" class="block px-3 py-2 rounded-lg hover:bg-slate-800/60">Настройки</a>
        <a href="/logout.php" class="block px-3 py-2 rounded-lg hover:bg-slate-800/60 text-red-300">Выйти</a>
    </nav>
</aside>
<main class="flex-1 p-4">
    <div class="max-w-xl mx-auto space-y-6">
        <header>
            <h2 class="text-xl font-semibold tracking-tight text-slate-50">Пригласить ресторан</h2>
            <p class="text-sm text-gray-400 mt-1">Отправьте приглашение по email. При регистрации по вашей ссылке мы засчитаем приглашение.</p>
        </header>

        <?php if ($success): ?>
            <div class="rounded-xl bg-emerald-500/10 border border-emerald-500/40 px-4 py-3 text-sm text-emerald-200">
                Приглашение отправлено на указанный email.
            </div>
        <?php endif; ?>

        <?php if ($errors): ?>
            <div class="rounded-xl bg-red-500/10 border border-red-500/40 px-4 py-3 text-sm text-red-200">
                <?php foreach ($errors as $e): ?>
                    <div><?= e($e) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <section class="rounded-xl border border-slate-800 bg-slate-900/80 p-5">
            <h3 class="text-sm font-semibold text-slate-200 mb-3">Отправить приглашение</h3>
            <form method="post" class="space-y-4">
                <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                <div>
                    <label for="restaurant_name" class="block text-xs text-gray-400 mb-1">Название ресторана (необязательно)</label>
                    <input type="text" id="restaurant_name" name="restaurant_name" class="w-full rounded-xl bg-slate-950 border border-slate-700 px-3 py-2 text-sm text-slate-100" placeholder="Название ресторана">
                </div>
                <div>
                    <label for="owner_email" class="block text-xs text-gray-400 mb-1">Email владельца *</label>
                    <input type="email" id="owner_email" name="owner_email" required class="w-full rounded-xl bg-slate-950 border border-slate-700 px-3 py-2 text-sm text-slate-100" placeholder="email@example.com">
                </div>
                <p class="text-xs text-gray-500">Лимит: <?= REFERRAL_MAX_INVITES_PER_DAY ?> приглашений в день. Сегодня отправлено: <?= $invitesToday ?>.</p>
                <button type="submit" class="btn-motion px-4 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-medium">
                    Отправить приглашение
                </button>
            </form>
        </section>

        <section class="rounded-xl border border-slate-800 bg-slate-900/80 p-5">
            <h3 class="text-sm font-semibold text-slate-200 mb-2">Ваша реферальная ссылка</h3>
            <p class="text-xs text-gray-400 mb-2">Поделитесь ссылкой — при регистрации по ней мы засчитаем приглашение.</p>
            <div class="flex flex-wrap gap-2 items-center">
                <input type="text" id="referral-link" readonly value="<?= e($referralLink) ?>" class="flex-1 min-w-0 rounded-xl bg-slate-950 border border-slate-700 px-3 py-2 text-sm text-slate-300">
                <button type="button" class="js-copy-link px-3 py-2 rounded-xl bg-slate-700 hover:bg-slate-600 text-sm font-medium relative" data-copy-target="referral-link">
                    Копировать
                    <span class="js-copy-tooltip hidden absolute -top-8 left-1/2 -translate-x-1/2 px-2 py-1 rounded bg-emerald-600 text-white text-xs whitespace-nowrap">Copied!</span>
                </button>
            </div>
            <p class="text-xs text-gray-500 mt-2">Приглашено ресторанов: <strong><?= $invitedCount ?></strong></p>
        </section>
    </div>
</main>
<script>
document.querySelectorAll('.js-copy-link').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var id = this.getAttribute('data-copy-target');
        var el = id ? document.getElementById(id) : null;
        var tooltip = this.querySelector('.js-copy-tooltip');
        if (!el) return;
        navigator.clipboard.writeText(el.value).then(function() {
            if (tooltip) {
                tooltip.classList.remove('hidden');
                setTimeout(function() { tooltip.classList.add('hidden'); }, 2000);
            }
        });
    });
});
</script>
</body>
</html>
