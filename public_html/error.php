<?php


require_once __DIR__ . '/../app/bootstrap.php';

if (!function_exists('e')) {
    function e($v): string {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

// Определяем код ошибки
$code = http_response_code();
if ($code === 200) {

    $code = isset($_SERVER['REDIRECT_STATUS']) ? (int)$_SERVER['REDIRECT_STATUS'] : 404;
    http_response_code($code);
}

$title   = 'Ошибка';
$message = 'Что-то пошло не так.';
$hint    = 'Попробуйте вернуться назад или на главную страницу.';

switch ($code) {
    case 404:
        $title   = 'Страница не найдена';
        $message = 'Мы не смогли найти страницу, которую вы запросили.';
        $hint    = 'Проверьте адрес или вернитесь в панель управления.';
        break;
    case 403:
        $title   = 'Доступ запрещён';
        $message = 'У вас нет прав для просмотра этой страницы.';
        $hint    = 'Если вы считаете, что это ошибка — обратитесь к администратору.';
        break;
    case 500:
        $title   = 'Внутренняя ошибка сервера';
        $message = 'Произошла непредвиденная ошибка на стороне сервера.';
        $hint    = 'Мы уже работаем над этим. Попробуйте повторить попытку чуть позже.';
        break;
    default:
        $title   = 'Ошибка ' . $code;
        $message = 'Произошла непредвиденная ошибка.';
        $hint    = 'Попробуйте обновить страницу или вернуться в панель.';
        break;
}

// Пытаемся понять, куда можно вернуть пользователя
$user              = function_exists('auth_user') ? auth_user() : null;
$currentRestaurant = $currentRestaurant ?? null;

$btnPrimaryHref = '/';
$btnPrimaryText = 'На главную';

$btnSecondaryHref = null;
$btnSecondaryText = null;

// Если есть ресторан — даём быстрый переход в его панель
if ($currentRestaurant) {
    $btnPrimaryHref = '/restaurant/dashboard.php';
    $btnPrimaryText = 'В панель ресторана';

    // Дополнительная кнопка — на экран заказов для персонала
    $btnSecondaryHref = '/staff/orders.php';
    $btnSecondaryText = 'Заказы (Staff)';
} elseif ($user) {
    // Есть авторизация, но мы на основном домене
    if (($user['global_role'] ?? null) === 'project_owner') {
        $btnPrimaryHref = '/project-admin/';
        $btnPrimaryText = 'В панель владельца платформы';
    } else {
        $btnPrimaryHref = '/owner/dashboard.php';
        $btnPrimaryText = 'В панель владельца ресторана';
    }
}
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title><?= e($title) ?> — QR-Restaurant Cloud</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .float-slow { animation: float-slow 18s ease-in-out infinite; }
        .float-slow-2 { animation: float-slow-2 26s ease-in-out infinite; }
        @keyframes float-slow {
            0%, 100% { transform: translate3d(0, 0, 0) scale(1); }
            50%      { transform: translate3d(18px, -26px, 0) scale(1.04); }
        }
        @keyframes float-slow-2 {
            0%, 100% { transform: translate3d(0, 0, 0) scale(1); }
            50%      { transform: translate3d(-22px, 30px, 0) scale(1.05); }
        }
    </style>
</head>
<body class="min-h-screen bg-slate-950 text-slate-50 flex items-center justify-center">
<div class="relative w-full max-w-xl px-4">
    <!-- фоновые пятна -->
    <div class="pointer-events-none absolute inset-0 -z-10">
        <div class="absolute -top-40 -left-32 w-80 h-80 bg-emerald-500/25 blur-3xl rounded-full float-slow"></div>
        <div class="absolute bottom-[-9rem] right-[-3rem] w-96 h-96 bg-sky-500/20 blur-3xl rounded-full float-slow-2"></div>
        <div class="absolute top-1/3 right-10 w-60 h-60 bg-fuchsia-500/25 blur-3xl rounded-full opacity-80"></div>
    </div>

    <div class="bg-slate-950/90 border border-slate-800 rounded-3xl shadow-2xl shadow-slate-950/80 p-6 sm:p-8 backdrop-blur-xl">
        <div class="flex items-center justify-between mb-4">
            <div class="text-xs text-slate-400 uppercase tracking-wide">
                QR-Restaurant Cloud
            </div>
            <div class="text-[11px] px-2 py-1 rounded-full bg-slate-900 border border-slate-700 text-slate-300">
                Код ошибки: <span class="font-mono text-slate-50"><?= (int)$code ?></span>
            </div>
        </div>

        <div class="mb-6">
            <div class="text-5xl sm:text-6xl font-semibold text-slate-50 mb-2 flex items-baseline gap-2">
                <span><?= (int)$code ?></span>
                <span class="text-base text-slate-500 font-normal"><?= e($title) ?></span>
            </div>
            <p class="text-sm text-slate-300 mb-1">
                <?= e($message) ?>
            </p>
            <p class="text-xs text-slate-500">
                <?= e($hint) ?>
            </p>
        </div>

        <div class="flex flex-col sm:flex-row gap-2 sm:items-center sm:justify-between">
            <div class="flex gap-2">
                <a href="<?= e($btnPrimaryHref) ?>"
                   class="inline-flex items-center justify-center px-4 py-2.5 rounded-2xl bg-emerald-500 hover:bg-emerald-400 text-sm font-semibold text-slate-950 shadow-lg shadow-emerald-500/40 transition">
                    <?= e($btnPrimaryText) ?>
                </a>
                <?php if ($btnSecondaryHref && $btnSecondaryText): ?>
                    <a href="<?= e($btnSecondaryHref) ?>"
                       class="inline-flex items-center justify-center px-4 py-2.5 rounded-2xl bg-slate-900/80 border border-slate-700 hover:bg-slate-800 text-sm font-semibold text-slate-100 transition">
                        <?= e($btnSecondaryText) ?>
                    </a>
                <?php endif; ?>
            </div>

            <button type="button"
                    onclick="history.back();"
                    class="mt-1 sm:mt-0 inline-flex items-center gap-1 text-[11px] text-slate-400 hover:text-slate-200">
                ← Назад
            </button>
        </div>
    </div>
</div>
</body>
</html>
