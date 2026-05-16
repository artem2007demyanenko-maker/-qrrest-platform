<?php

$configPath = __DIR__ . '/../app/config.php';
$appName    = 'QR-Restaurant Cloud';
$message    = 'Сервис временно недоступен: ведутся технические работы.';

if (is_file($configPath)) {
    $cfg = require $configPath;
    if (!empty($cfg['app']['name'])) {
        $appName = $cfg['app']['name'];
    }
    if (!empty($cfg['app']['maintenance_message'])) {
        $message = $cfg['app']['maintenance_message'];
    }
}

if (!function_exists('e')) {
    function e($v): string {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}


http_response_code(503);
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Технические работы — <?= e($appName) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="Retry-After" content="3600">
    <link rel="icon" href="/assets/img/logo-qrrest.png">
    <link rel="icon" type="image/png" sizes="32x32" href="/assets/img/logo-qrrest.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/assets/img/logo-qrrest.png">
    <link rel="apple-touch-icon" href="/assets/img/logo-qrrest.png">
    <meta name="theme-color" content="#0f172a">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .float-slow { animation: float-slow 20s ease-in-out infinite; }
        .float-slow-2 { animation: float-slow-2 26s ease-in-out infinite; }
        @keyframes float-slow {
            0%, 100% { transform: translate3d(0, 0, 0) scale(1); }
            50%      { transform: translate3d(18px, -26px, 0) scale(1.04); }
        }
        @keyframes float-slow-2 {
            0%, 100% { transform: translate3d(0, 0, 0) scale(1); }
            50%      { transform: translate3d(-18px, 24px, 0) scale(1.05); }
        }

        .card-appear {
            animation: card-appear .45s ease-out;
        }
        @keyframes card-appear {
            0%   { opacity: 0; transform: translateY(10px) scale(.98); }
            100% { opacity: 1; transform: translateY(0) scale(1); }
        }

        .pulse-soft {
            animation: pulse-soft 1.8s ease-in-out infinite;
        }
        @keyframes pulse-soft {
            0%, 100% { transform: scale(1); opacity: .9; }
            50%      { transform: scale(1.06); opacity: 1; }
        }
    </style>
</head>
<body class="min-h-screen bg-slate-950 text-slate-50 flex items-center justify-center">
<div class="relative w-full max-w-3xl px-4 py-10">
    <!-- фоновые пятна -->
    <div class="pointer-events-none absolute inset-0 -z-10">
        <div class="absolute -top-40 -left-32 w-80 h-80 bg-emerald-500/25 blur-3xl rounded-full float-slow"></div>
        <div class="absolute bottom-[-9rem] right-[-3rem] w-96 h-96 bg-sky-500/20 blur-3xl rounded-full float-slow-2"></div>
        <div class="absolute top-1/3 right-10 w-60 h-60 bg-fuchsia-500/25 blur-3xl rounded-full opacity-80"></div>
    </div>

    <div class="card-appear bg-slate-950/90 border border-slate-800 rounded-3xl shadow-2xl shadow-slate-950/80 p-6 sm:p-8 backdrop-blur-xl">
        <!-- верхний бар -->
        <div class="flex items-start justify-between gap-3 mb-6">
            <div>
                <div class="text-[11px] tracking-wide uppercase text-slate-400 mb-1">
                    <?= e($appName) ?>
                </div>
                <div class="inline-flex items-center gap-2 px-2.5 py-1 rounded-full bg-slate-900/80 border border-slate-700 text-[11px] text-slate-300">
                    <span class="w-1.5 h-1.5 rounded-full bg-amber-400 animate-pulse"></span>
                    <span>Ведутся технические работы</span>
                </div>
            </div>
            <div class="hidden sm:flex items-center gap-2 text-[11px] text-slate-400">
                <span class="text-slate-500">Статус системы</span>
                <span class="px-2 py-1 rounded-full bg-slate-900 border border-slate-700 text-amber-200">
                    Временно недоступна
                </span>
            </div>
        </div>

        <!-- основной текст -->
        <div class="mb-6">
            <div class="flex items-center gap-3 mb-3">
                <div class="w-10 h-10 rounded-2xl bg-emerald-500/15 border border-emerald-500/50 flex items-center justify-center pulse-soft">
                    <span class="text-xl">🛠️</span>
                </div>
                <div>
                    <h1 class="text-2xl sm:text-3xl font-semibold text-slate-50">
                        Мы обновляем платформу
                    </h1>
                    <p class="text-xs text-slate-400 mt-1">
                        На это время интерфейс и API могут быть недоступны.
                    </p>
                </div>
            </div>

            <p class="text-sm text-slate-300 mb-3 leading-relaxed">
                <?= e($message) ?>
            </p>

            <div class="text-xs text-slate-500 space-y-1">
                <p>
                    • Все <span class="text-slate-300">заказы, столы и меню</span> сохранены и будут
                    доступны сразу после завершения работ.
                </p>
                <p>
                    • Если вы владелец ресторана, просто зайдите позже в свою панель — ничего
                    дополнительно делать не нужно.
                </p>
            </div>
        </div>

        <!-- прогресс-бар + таймлайн -->
        <div class="mb-6 space-y-4">
            <div>
                <div class="flex items-center justify-between mb-1 text-[11px] text-slate-400">
                    <span>Прогресс технических работ</span>
                    <span>Оценочно</span>
                </div>
                <div class="w-full h-1.5 rounded-full bg-slate-900 overflow-hidden">
                    <div class="h-full bg-gradient-to-r from-emerald-400 via-sky-400 to-fuchsia-400 animate-[progress-move_2.4s_ease-in-out_infinite]"></div>
                </div>
            </div>

            <style>
                @keyframes progress-move {
                    0%   { transform: translateX(-40%); }
                    50%  { transform: translateX(0); }
                    100% { transform: translateX(40%); }
                }
            </style>

            <div class="grid gap-3 sm:grid-cols-3 text-[11px] text-slate-400">
                <div class="flex items-start gap-2">
                    <div class="mt-1 w-1.5 h-1.5 rounded-full bg-emerald-400"></div>
                    <div>
                        <div class="text-slate-200 mb-0.5">Подготовка</div>
                        <div>Резервные копии и проверка конфигурации.</div>
                    </div>
                </div>
                <div class="flex items-start gap-2">
                    <div class="mt-1 w-1.5 h-1.5 rounded-full bg-sky-400"></div>
                    <div>
                        <div class="text-slate-200 mb-0.5">Обновление</div>
                        <div>Обновление кода и миграции базы данных.</div>
                    </div>
                </div>
                <div class="flex items-start gap-2">
                    <div class="mt-1 w-1.5 h-1.5 rounded-full bg-fuchsia-400"></div>
                    <div>
                        <div class="text-slate-200 mb-0.5">Проверка</div>
                        <div>Тестирование панелей владельцев и сотрудников.</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- кнопки -->
        <div class="flex flex-col sm:flex-row gap-2 sm:items-center sm:justify-between">
            <div class="flex flex-wrap gap-2">
                <button type="button"
                        onclick="location.reload();"
                        class="inline-flex items-center justify-center px-4 py-2.5 rounded-2xl bg-emerald-500 hover:bg-emerald-400 text-sm font-semibold text-slate-950 shadow-lg shadow-emerald-500/40 transition">
                    Обновить страницу
                </button>

            </div>
            <div class="flex flex-col sm:items-end text-[11px] text-slate-400 gap-0.5 mt-1 sm:mt-0">
                <button type="button"
                        onclick="history.back();"
                        class="inline-flex items-center gap-1 hover:text-slate-200">
                    ← Вернуться назад
                </button>
                <span class="text-slate-500">
                    Если работы затянулись, свяжитесь с владельцем платформы.
                </span>
            </div>
        </div>
    </div>
</div>
</body>
</html>
