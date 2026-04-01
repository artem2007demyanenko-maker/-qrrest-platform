<?php


require_once __DIR__ . '/../app/bootstrap.php';
$pdo  = db();
$user = auth_user();

$ldConfig = require __DIR__ . '/../app/config.php';
$ldBase = rtrim((string)($ldConfig['app']['url'] ?? ''), '/');
if ($ldBase === '') {
    $ldBase = ($ldConfig['app']['protocol'] ?? 'https') . '://' . ($ldConfig['app']['main_domain'] ?? 'qrrest-menu.ru');
} 


if ($currentRestaurant && !$user && !empty($currentRestaurant['public_home_enabled'])) {
    header("Location: /restaurant_public.php");
    exit;
}



$appName = 'QR-Rest Cloud';
$configPath = __DIR__ . '/../config.php';
if (is_file($configPath)) {
    $cfg = require $configPath;
    if (!empty($cfg['app']['name'])) {
        $appName = $cfg['app']['name'];
    }
}

$user = function_exists('auth_user') ? auth_user() : null;


function h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="ru">
<head>
    <script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "Organization",
  "name": <?= json_encode(BRAND_NAME, JSON_UNESCAPED_UNICODE) ?>,
  "url": <?= json_encode($ldBase . '/', JSON_UNESCAPED_SLASHES) ?>,
  "logo": <?= json_encode($ldBase . '/assets/brand/apple-touch-icon.png', JSON_UNESCAPED_SLASHES) ?>
}
</script>
    <meta charset="UTF-8">
    <title><?= h(BRAND_NAME) ?> — облачная QR-система для ресторанов</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?= brand_head_tags() ?>
    <meta name="description"
          content="Облачная QR-платформа для ресторанов: меню, заказы и оплата. Подписка для одиночных ресторанов и сетей.">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .float-slow { animation: float-slow 12s ease-in-out infinite; }
        .float-slow-2 { animation: float-slow-2 18s ease-in-out infinite; }
        .glow-pulse { animation: glow-pulse 3s ease-in-out infinite; }

        @keyframes float-slow {
            0%, 100% { transform: translate3d(0, 0, 0) scale(1); }
            50%      { transform: translate3d(10px, -18px, 0) scale(1.03); }
        }
        @keyframes float-slow-2 {
            0%, 100% { transform: translate3d(0, 0, 0) scale(1); }
            50%      { transform: translate3d(-16px, 22px, 0) scale(1.04); }
        }
        @keyframes glow-pulse {
            0%, 100% { box-shadow: 0 0 0 0 rgba(52, 211, 153, 0.0); }
            50%      { box-shadow: 0 0 42px 0 rgba(52, 211, 153, 0.35); }
        }
    </style>
</head>
<body class="min-h-screen bg-slate-950 text-slate-50">
<div class="min-h-screen relative overflow-hidden">

    <!-- Градиенты фона -->
    <div class="pointer-events-none absolute inset-0">
        <div class="absolute -top-32 -left-24 w-72 h-72 bg-emerald-500/20 blur-3xl rounded-full float-slow"></div>
        <div class="absolute -bottom-40 right-0 w-96 h-96 bg-sky-500/20 blur-3xl rounded-full float-slow-2"></div>
        <div class="absolute top-1/3 -right-16 w-56 h-56 bg-fuchsia-500/20 blur-3xl rounded-full opacity-80"></div>
        <div class="absolute top-1/2 -left-10 w-40 h-40 bg-emerald-400/10 blur-2xl rounded-full opacity-70"></div>
    </div>

    <!-- Шапка -->
    <header class="relative z-20 border-b border-slate-800/70 bg-slate-950/85 backdrop-blur-xl">
        <div class="max-w-6xl mx-auto px-4 py-4 flex items-center justify-between gap-4">
            <div class="flex items-center gap-2">
                <?= brand_header_cluster_html(true, 'w-9 h-9 text-slate-400') ?>
            </div>

            <nav class="hidden md:flex items-center gap-6 text-xs text-slate-300">
                <a href="#how" class="hover:text-emerald-300 transition">Как это работает</a>
                <a href="#pricing" class="hover:text-emerald-300 transition">Тарифы</a>
                <a href="#roles" class="hover:text-emerald-300 transition">Для кого</a>
                <a href="#features" class="hover:text-emerald-300 transition">Функции</a>
                <a href="#contact" class="hover:text-emerald-300 transition">Заявка</a>
            </nav>

            <div class="flex items-center gap-2">
                <?php if ($user): ?>
                    <a href="/owner/dashboard.php"
                       class="hidden sm:inline-flex items-center gap-2 px-3 py-1.5 rounded-xl bg-slate-900/80 border border-slate-700 text-xs text-slate-100 hover:bg-slate-800 transition">
                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"></span>
                        Кабинет
                    </a>
                <?php endif; ?>

                <a href="/login.php"
                   class="inline-flex items-center gap-2 px-3 py-1.5 rounded-xl bg-emerald-500 hover:bg-emerald-400 text-xs font-semibold text-slate-950 shadow-md shadow-emerald-500/40 transition">
                    Войти
                </a>
            </div>
        </div>
    </header>

    <!-- Контент -->
    <main class="relative z-10">
        <!-- HERO -->
        <section class="max-w-6xl mx-auto px-4 pt-8 pb-10 lg:pt-12 lg:pb-16">
            <div class="grid lg:grid-cols-[minmax(0,1.3fr)_minmax(0,1fr)] gap-8 items-center">
                <!-- текст -->
                <div class="space-y-6">
                    <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-emerald-500/15 border border-emerald-500/40 text-[11px] text-emerald-100">
                        <span class="w-4 h-4 rounded-full bg-emerald-400 text-[10px] flex items-center justify-center text-slate-950 font-bold">QR</span>
                        <span>Облачная система заказов по подписке для ресторанов</span>
                    </div>

                    <h1 class="text-3xl sm:text-4xl lg:text-5xl font-semibold leading-tight">
                        Современное QR-меню
                        <span class="block text-transparent bg-clip-text bg-gradient-to-r from-emerald-300 via-sky-300 to-emerald-100">
                            с оплатой и панелями для команды
                        </span>
                    </h1>

                    <p class="text-sm sm:text-base text-slate-300 max-w-xl">
                        Гости сканируют QR-код, собирают заказ в красивом мобильном меню и оплачивают.
                        Кухня и зал работают в живой панели с уведомлениями. Ресторан платит по удобной подписке
                        за подключение к платформе.
                    </p>

                    <div class="flex flex-wrap gap-3">
                        <a href="#contact"
                           class="inline-flex items-center gap-2 px-4 py-2.5 rounded-2xl bg-emerald-500 hover:bg-emerald-400 text-sm font-semibold text-slate-950 shadow-lg shadow-emerald-500/40 transition">
                            ✨ Оставить заявку на подключение
                        </a>
                        <a href="#pricing"
                           class="inline-flex items-center gap-2 px-4 py-2.5 rounded-2xl bg-slate-900/80 border border-slate-700 text-sm text-slate-100 hover:bg-slate-800 transition">
                            💳 Посмотреть тарифы
                        </a>
                    </div>

                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 text-[11px] text-slate-400 pt-2">
                        <div class="flex items-center gap-2">
                            <span class="w-6 h-6 rounded-xl bg-slate-900/80 border border-slate-700 flex items-center justify-center text-emerald-300">№1</span>
                            <span>Подходит и для одного ресторана, и для сети</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="w-6 h-6 rounded-xl bg-slate-900/80 border border-slate-700 flex items-center justify-center text-sky-300">QR</span>
                            <span>Отдельный поддомен и QR-коды для каждого заведения</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="w-6 h-6 rounded-xl bg-slate-900/80 border border-slate-700 flex items-center justify-center text-fuchsia-300">₽</span>
                            <span>Оплата онлайн / позже / наличными</span>
                        </div>
                    </div>
                </div>

                <!-- визуал панелей -->
                <div class="relative">
                    <div class="absolute -inset-4 bg-gradient-to-br from-emerald-500/30 via-sky-500/10 to-fuchsia-500/20 blur-3xl opacity-70"></div>
                    <div class="relative space-y-4">
                        <!-- владелец ресторана / менеджер -->
                        <div class="rounded-3xl bg-slate-950/95 border border-slate-800/80 shadow-2xl shadow-slate-950/90 p-4 backdrop-blur-xl">
                            <div class="flex items-center justify-between gap-3 mb-3">
                                <div>
                                    <div class="text-[11px] text-slate-400 uppercase tracking-[0.18em]">
                                        РЕСТОРАН · ДАШБОРД
                                    </div>
                                    <div class="text-sm text-slate-100">
                                        Выручка сегодня
                                    </div>
                                </div>
                                <div class="text-right">
                                    <div class="text-xl font-semibold text-emerald-400">
                                        128 400 ₽
                                    </div>
                                    <div class="text-[11px] text-emerald-300/80">
                                        +18% к вчера
                                    </div>
                                </div>
                            </div>

                            <div class="grid grid-cols-3 gap-2 text-[11px]">
                                <div class="rounded-2xl bg-slate-900/80 border border-slate-700 px-2.5 py-2">
                                    <div class="text-slate-400">Заказы</div>
                                    <div class="text-slate-50 font-semibold mt-0.5">73</div>
                                </div>
                                <div class="rounded-2xl bg-slate-900/80 border border-emerald-500/60 px-2.5 py-2">
                                    <div class="text-slate-400">Онлайн-оплата</div>
                                    <div class="text-emerald-300 font-semibold mt-0.5">58</div>
                                </div>
                                <div class="rounded-2xl bg-slate-900/80 border border-slate-700 px-2.5 py-2">
                                    <div class="text-slate-400">Средний чек</div>
                                    <div class="text-slate-50 font-semibold mt-0.5">1 740 ₽</div>
                                </div>
                            </div>
                        </div>

                        <!-- сотрудники -->
                        <div class="rounded-3xl bg-slate-950/95 border border-slate-800/80 shadow-2xl shadow-slate-950/90 p-4 backdrop-blur-xl flex flex-col gap-3">
                            <div class="flex items-center justify-between">
                                <div class="text-[11px] text-slate-400 uppercase tracking-[0.18em]">
                                    КУХНЯ И ЗАЛ · ЗАКАЗЫ
                                </div>
                                <div class="inline-flex items-center gap-1 px-2 py-1 rounded-full bg-emerald-500/10 border border-emerald-400/60 text-[10px] text-emerald-100">
                                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"></span>
                                    Новый заказ
                                </div>
                            </div>

                            <div class="space-y-2 text-[11px]">
                                <div class="flex items-center justify-between rounded-2xl bg-slate-900/80 border border-slate-700/80 px-3 py-2">
                                    <div>
                                        <div class="text-slate-50 text-xs font-medium">Стол 12 · 3 блюда</div>
                                        <div class="text-slate-400">Статус: Новый</div>
                                    </div>
                                    <div class="text-right">
                                        <div class="text-emerald-300 font-semibold text-sm">2 350 ₽</div>
                                        <div class="text-[10px] text-slate-500">Оплата: онлайн</div>
                                    </div>
                                </div>
                                <div class="flex items-center justify-between rounded-2xl bg-slate-900/80 border border-slate-700/80 px-3 py-2">
                                    <div>
                                        <div class="text-slate-50 text-xs font-medium">Стол 4 · 2 блюда</div>
                                        <div class="text-slate-400">Статус: Готово · ожидание выдачи</div>
                                    </div>
                                    <div class="text-right">
                                        <div class="text-emerald-300 font-semibold text-sm">980 ₽</div>
                                        <div class="text-[10px] text-slate-500">Оплата: наличные</div>
                                    </div>
                                </div>
                            </div>

                            <div class="flex items-center justify-between text-[10px] text-slate-500">
                                <div>Живое обновление · звук при новом заказе · контроль оплаты</div>
                                <div class="inline-flex items-center gap-1 px-2 py-1 rounded-full bg-slate-900 border border-slate-700">
                                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-400"></span> online
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Тарифы / Подписка -->
        <section id="pricing" class="max-w-6xl mx-auto px-4 py-6 sm:py-8">
            <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3 mb-5">
                <div>
                    <h2 class="text-lg sm:text-xl font-semibold">Подписка на платформу</h2>
                    <p class="text-sm text-slate-400 mt-1 max-w-xl">
                        Рестораны подключаются к платформе по модели подписки: фиксированная стоимость за каждый ресторан,
                        без скрытых комиссий с оборота.
                    </p>
                </div>
                <div class="inline-flex items-center gap-2 rounded-full bg-slate-900/90 border border-slate-700 px-3 py-1 text-[11px] text-slate-300">
                    <span class="w-5 h-5 rounded-full bg-emerald-500/20 flex items-center justify-center text-emerald-300">₽</span>
                    Стоимость можно обсудить индивидуально для сети
                </div>
            </div>

            <div class="grid md:grid-cols-3 gap-4 text-sm">
                <!-- Старт -->
                <div class="relative rounded-3xl bg-slate-950/90 border border-slate-800 p-4 flex flex-col gap-3 shadow-xl shadow-slate-950/70">
                    <div class="text-xs font-semibold text-slate-200 uppercase tracking-[0.18em]">START</div>
                    <div class="text-lg font-semibold text-slate-50">Для одного ресторана</div>
                    <div class="flex items-baseline gap-1">
                        <div class="text-2xl font-bold text-emerald-300">от 2 900 ₽</div>
                        <div class="text-[11px] text-slate-400">в месяц за ресторан</div>
                    </div>
                    <ul class="text-[13px] text-slate-300 space-y-1.5">
                        <li>• 1 ресторан</li>
                        <li>• QR-меню и корзина</li>
                        <li>• Панель сотрудников кухни/зала</li>
                        <li>• Панель владельца ресторана</li>
                        <li>• Базовая статистика и отчёты</li>
                    </ul>
                    <div class="mt-2">
                        <a href="#contact"
                           class="inline-flex items-center justify-center w-full px-3 py-2 rounded-2xl bg-slate-900 border border-slate-700 text-xs font-semibold text-slate-100 hover:bg-slate-800 transition">
                            Оставить заявку на тариф START
                        </a>
                    </div>
                </div>

                <!-- PRO -->
                <div class="relative rounded-3xl bg-gradient-to-br from-emerald-500/15 via-slate-950 to-sky-500/15 border border-emerald-500/70 p-4 flex flex-col gap-3 shadow-2xl shadow-emerald-900/50">
                    <div class="flex items-center justify-between">
                        <div class="text-xs font-semibold text-emerald-200 uppercase tracking-[0.18em]">PRO</div>
                        <div class="inline-flex items-center gap-1 px-2 py-1 rounded-full bg-emerald-500/10 border border-emerald-400/70 text-[10px] text-emerald-100">
                            Популярный выбор
                        </div>
                    </div>
                    <div class="text-lg font-semibold text-slate-50">Для сети ресторанов</div>
                    <div class="flex items-baseline gap-1">
                        <div class="text-2xl font-bold text-emerald-300">от 2 400 ₽</div>
                        <div class="text-[11px] text-slate-200">в месяц за ресторан при подключении от 3 точек</div>
                    </div>
                    <ul class="text-[13px] text-emerald-50/90 space-y-1.5">
                        <li>• От 3 ресторанов</li>
                        <li>• Общий центр управления сетью</li>
                        <li>• Отчёты по выручке и заказам по каждой точке</li>
                        <li>• Экспорт в CSV для бухгалтерии</li>
                        <li>• Приоритетная поддержка при запуске</li>
                    </ul>
                    <div class="mt-2">
                        <a href="#contact"
                           class="inline-flex items-center justify-center w-full px-3 py-2 rounded-2xl bg-emerald-400 hover:bg-emerald-300 text-xs font-semibold text-slate-950 shadow-md shadow-emerald-500/80 transition">
                            Оставить заявку на тариф PRO
                        </a>
                    </div>
                </div>

                <!-- Enterprise -->
                <div class="relative rounded-3xl bg-slate-950/90 border border-slate-800 p-4 flex flex-col gap-3 shadow-xl shadow-slate-950/70">
                    <div class="text-xs font-semibold text-slate-200 uppercase tracking-[0.18em]">ENTERPRISE</div>
                    <div class="text-lg font-semibold text-slate-50">Индивидуальное решение</div>
                    <div class="flex items-baseline gap-1">
                        <div class="text-2xl font-bold text-slate-100">по запросу</div>
                    </div>
                    <ul class="text-[13px] text-slate-300 space-y-1.5">
                        <li>• Крупные сети и сложные процессы</li>
                        <li>• Индивидуальные права и роли</li>
                        <li>• Специальные отчёты и доработки</li>
                        <li>• При необходимости — интеграции по API</li>
                        <li>• Отдельные условия по оплате</li>
                    </ul>
                    <div class="mt-2">
                        <a href="#contact"
                           class="inline-flex items-center justify-center w-full px-3 py-2 rounded-2xl bg-slate-900 border border-slate-700 text-xs font-semibold text-slate-100 hover:bg-slate-800 transition">
                            Обсудить индивидуальный проект
                        </a>
                    </div>
                </div>
            </div>
        </section>

        <!-- Как это работает -->
        <section id="how" class="max-w-6xl mx-auto px-4 py-6 sm:py-8">
            <div class="rounded-3xl bg-slate-950/85 border border-slate-800/80 p-4 sm:p-6 shadow-xl shadow-slate-950/70">
                <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3 mb-4">
                    <div>
                        <h2 class="text-lg sm:text-xl font-semibold">Как это работает</h2>
                        <p class="text-sm text-slate-400 mt-1 max-w-xl">
                            Простой сценарий: ресторан подключается к платформе, размещает QR-коды на столах — гость заказывает,
                            команда видит заказы в панелях, руководство контролирует выручку и оплату.
                        </p>
                    </div>
                    <div class="inline-flex items-center gap-2 rounded-full bg-slate-900/90 border border-slate-700 px-3 py-1 text-[11px] text-slate-300">
                        <span class="w-5 h-5 rounded-full bg-emerald-500/20 flex items-center justify-center text-emerald-300">⚙</span>
                        PHP 8 · MySQL · TailwindCSS
                    </div>
                </div>

                <div class="grid md:grid-cols-3 gap-4 text-sm">
                    <div class="rounded-2xl bg-slate-900/80 border border-slate-700/80 p-4 flex flex-col gap-2">
                        <div class="inline-flex items-center justify-center w-8 h-8 rounded-xl bg-emerald-500/20 text-emerald-300 text-lg">1</div>
                        <div class="font-semibold text-slate-50">Подключение ресторана</div>
                        <p class="text-slate-400 text-xs sm:text-[13px]">
                            Ресторан получает доступ в свою панель, отдельный поддомен и роли для владельца, админов и сотрудников.
                            Дальше команда сама управляет меню и персоналом.
                        </p>
                    </div>

                    <div class="rounded-2xl bg-slate-900/80 border border-slate-700/80 p-4 flex flex-col gap-2">
                        <div class="inline-flex items-center justify-center w-8 h-8 rounded-xl bg-sky-500/20 text-sky-300 text-lg">2</div>
                        <div class="font-semibold text-slate-50">Меню, столы и QR-коды</div>
                        <p class="text-slate-400 text-xs sm:text-[13px]">
                            Владелец/админ добавляет категории и блюда, загружает фото, создаёт столы
                            — система генерирует QR-коды для каждого стола с ссылкой на мобильное меню.
                        </p>
                    </div>

                    <div class="rounded-2xl bg-slate-900/80 border border-slate-700/80 p-4 flex flex-col gap-2">
                        <div class="inline-flex items-center justify-center w-8 h-8 rounded-xl bg-fuchsia-500/20 text-fuchsia-300 text-lg">3</div>
                        <div class="font-semibold text-slate-50">Гости и команда работают</div>
                        <p class="text-slate-400 text-xs sm:text-[13px]">
                            Гости сканируют QR, собирают заказ и выбирают способ оплаты. Кухня и зал видят ленту заказов и статусы,
                            руководство — статистику и выручку по заведениям.
                        </p>
                    </div>
                </div>
            </div>
        </section>

        <!-- Роли (клиенты — владельцы ресторанов и их команда) -->
        <section id="roles" class="max-w-6xl mx-auto px-4 py-6 sm:py-8">
            <div class="flex items-center justify-between gap-2 mb-4">
                <h2 class="text-lg sm:text-xl font-semibold">Кому это нужно</h2>
                <div class="hidden sm:inline-flex items-center gap-2 text-[11px] text-slate-400">
                    Для рестораторов, управляющих, админов и линейного персонала
                </div>
            </div>

            <div class="grid md:grid-cols-4 gap-3 text-[13px]">
                <div class="rounded-2xl bg-slate-950/90 border border-emerald-500/70 p-3 flex flex-col gap-2">
                    <div class="inline-flex items-center gap-2 text-xs text-slate-100">
                        <span class="w-6 h-6 rounded-xl bg-slate-800 flex items-center justify-center text-base">🏠</span>
                        Руководитель ресторана
                    </div>
                    <p class="text-slate-300 text-xs">
                        Управляет меню, столами, сотрудниками, смотрит статистику по конкретному заведению и следит за оплатами.
                    </p>
                </div>
                <div class="rounded-2xl bg-slate-950/90 border border-slate-700 p-3 flex flex-col gap-2">
                    <div class="inline-flex items-center gap-2 text-xs text-slate-100">
                        <span class="w-6 h-6 rounded-xl bg-slate-800 flex items-center justify-center text-base">⚙️</span>
                        Администраторы
                    </div>
                    <p class="text-slate-300 text-xs">
                        Настраивают меню и столы, контролируют заказы и оплату, не вмешиваясь в настройки подписки и других ресторанов.
                    </p>
                </div>
                <div class="rounded-2xl bg-slate-950/90 border border-slate-700 p-3 flex flex-col gap-2">
                    <div class="inline-flex items-center gap-2 text-xs text-slate-100">
                        <span class="w-6 h-6 rounded-xl bg-slate-800 flex items-center justify-center text-base">👨‍🍳</span>
                        Кухня и зал
                    </div>
                    <p class="text-slate-300 text-xs">
                        Работают с лентой заказов, меняют статусы, отмечают факт оплаты наличными или картой при госте.
                    </p>
                </div>
                  <div class="rounded-2xl bg-slate-950/90 border border-slate-700 p-3 flex flex-col gap-2">
                    <div class="inline-flex items-center gap-2 text-xs text-emerald-300">
                        <span class="w-6 h-6 rounded-xl bg-emerald-500/20 flex items-center justify-center text-base">👨‍👨‍👦‍👦</span>
                        Гости
                    </div>
                    <p class="text-slate-300 text-xs">
                        Заказывают еду максимально быстро, не нужно ожидать оффицианта. Завлечены уникальными функциями вашего ресторана.
                    </p>
                </div>
            </div>
        </section>

        <!-- Функции -->
        <section id="features" class="max-w-6xl mx-auto px-4 py-6 sm:py-8">
            <div class="flex items-center justify-between gap-2 mb-4">
                <h2 class="text-lg sm:text-xl font-semibold">Что уже есть в системе</h2>
                <span class="hidden sm:inline-flex items-center gap-2 text-[11px] text-slate-400">
                    Готовый фундамент — дальше можно наращивать под конкретный ресторан или сеть
                </span>
            </div>

            <div class="grid md:grid-cols-3 gap-4 text-[13px]">
                <div class="rounded-2xl bg-slate-950/90 border border-slate-800 p-4 flex flex-col gap-2">
                    <div class="inline-flex items-center gap-2 text-xs text-slate-100">
                        <span class="w-7 h-7 rounded-xl bg-slate-900 flex items-center justify-center text-lg">📱</span>
                        Мобильное QR-меню
                    </div>
                    <ul class="text-slate-300 text-xs space-y-1.5 mt-1">
                        <li>— Карточки блюд с фото и описанием</li>
                        <li>— Корзина без перезагрузки страницы</li>
                        <li>— Привязка к конкретному столу по QR</li>
                        <li>— Выбор способа оплаты: онлайн / позже / наличными</li>
                    </ul>
                </div>

                <div class="rounded-2xl bg-slate-950/90 border border-slate-800 p-4 flex flex-col gap-2">
                    <div class="inline-flex items-center gap-2 text-xs text-slate-100">
                        <span class="w-7 h-7 rounded-xl bg-slate-900 flex items-center justify-center text-lg">🧾</span>
                        Панель сотрудников
                    </div>
                    <ul class="text-slate-300 text-xs space-y-1.5 mt-1">
                        <li>— Лента активных заказов с разделением по статусам</li>
                        <li>— Цепочка: Новый → Принят → Готовится → Готово → Отдано</li>
                        <li>— Отдельная вкладка по оплате заказов</li>
                        <li>— Звуковое уведомление при новых заказах</li>
                    </ul>
                </div>

                <div class="rounded-2xl bg-slate-950/90 border border-slate-800 p-4 flex flex-col gap-2">
                    <div class="inline-flex items-center gap-2 text-xs text-slate-100">
                        <span class="w-7 h-7 rounded-xl bg-slate-900 flex items-center justify-center text-lg">📊</span>
                        Статистика и отчёты
                    </div>
                    <ul class="text-slate-300 text-xs space-y-1.5 mt-1">
                        <li>— Заказы и выручка по ресторанам</li>
                        <li>— Средний чек и типы оплаты</li>
                        <li>— Отдельный список неоплаченных заказов</li>
                        <li>— Экспорт транзакций в CSV</li>
                    </ul>
                </div>
            </div>
        </section>

        <!-- Заявка на подключение -->
        <section id="contact" class="max-w-6xl mx-auto px-4 py-8 sm:py-10">
            <div class="rounded-3xl bg-gradient-to-br from-emerald-500/18 via-slate-950 to-sky-500/15 border border-emerald-500/60 p-5 sm:p-6 lg:p-7 shadow-2xl shadow-emerald-900/40">
                <div class="grid lg:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)] gap-6 items-start">
                    <div class="space-y-4">
                        <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-slate-950/80 border border-emerald-500/60 text-[11px] text-emerald-100">
                            <span class="w-4 h-4 rounded-full bg-emerald-400/90 flex items-center justify-center text-[10px] text-slate-950 font-bold">★</span>
                            Оставьте заявку — поможем запустить QR-меню и заказы
                        </div>
                        <h2 class="text-xl sm:text-2xl font-semibold">
                            Готовы обсудить запуск QR-меню в вашем ресторане?
                        </h2>
                        <p class="text-sm text-emerald-50/90 max-w-xl">
                            Оставьте контакты — расскажите, сколько у вас ресторанов и как сейчас устроены заказы.
                            Мы предложим вариант подключения к платформе и поможем настроить процесс под ваши задачи.
                        </p>
                        <ul class="text-[12px] text-emerald-50/80 space-y-1.5">
                            <li>• Помощь с первичной настройкой и обучением персонала</li>
                            <li>• Возможный тестовый период по договорённости</li>
                            <li>• Возможность развития системы под ваши процессы</li>
                        </ul>
                    </div>

                    <!-- Форма заявки (фронт) -->
                    <div class="rounded-2xl bg-slate-950/90 border border-emerald-500/40 p-4 sm:p-5 shadow-xl shadow-emerald-900/40">
                        <form method="post" action="/lead_request.php" class="space-y-3 text-sm">
                            <div>
                                <label class="block text-[11px] text-slate-300 mb-1">Имя и должность</label>
                                <input type="text" name="contact_name" required
                                       placeholder="Например: Илья, владелец сети"
                                       class="w-full rounded-xl bg-slate-900 border border-slate-700 px-3 py-2 text-sm text-slate-50 placeholder:text-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                            </div>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div>
                                    <label class="block text-[11px] text-slate-300 mb-1">Телефон или WhatsApp</label>
                                    <input type="text" name="contact_phone" required
                                           placeholder="+7…"
                                           class="w-full rounded-xl bg-slate-900 border border-slate-700 px-3 py-2 text-sm text-slate-50 placeholder:text-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                                </div>
                                <div>
                                    <label class="block text-[11px] text-slate-300 mb-1">E-mail</label>
                                    <input type="email" name="contact_email"
                                           placeholder="email@restaurant.ru"
                                           class="w-full rounded-xl bg-slate-900 border border-slate-700 px-3 py-2 text-sm text-slate-50 placeholder:text-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                                </div>
                            </div>
                            <div>
                                <label class="block text-[11px] text-slate-300 mb-1">Ресторан или сеть</label>
                                <input type="text" name="restaurant_name"
                                       placeholder="Название ресторана / сети"
                                       class="w-full rounded-xl bg-slate-900 border border-slate-700 px-3 py-2 text-sm text-slate-50 placeholder:text-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                            </div>
                            <div>
                                <label class="block text-[11px] text-slate-300 mb-1">Комментарий</label>
                                <textarea name="message" rows="3"
                                          placeholder="Сколько у вас ресторанов, как сейчас принимаете заказы и оплату, когда планируете запуск…"
                                          class="w-full rounded-xl bg-slate-900 border border-slate-700 px-3 py-2 text-sm text-slate-50 placeholder:text-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500"></textarea>
                            </div>
                            <div class="flex flex-col gap-2">
                                <button type="submit"
                                        class="inline-flex items-center justify-center w-full px-4 py-2.5 rounded-2xl bg-emerald-400 hover:bg-emerald-300 text-sm font-semibold text-slate-950 shadow-lg shadow-emerald-500/60 transition">
                                    Отправить заявку
                                </button>
                                <div class="text-[11px] text-slate-400">
                                    Нажимая на кнопку, вы соглашаетесь на обработку контактных данных для связи по вопросу подключения сервиса.
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </section>

        <!-- Футер -->
        <footer class="max-w-6xl mx-auto px-4 pb-6 pt-2 text-[11px] text-slate-500 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 border-t border-slate-900/80">
            <div>
                <?= h($appName) ?> · Облачная QR-платформа для ресторанов
            </div>
            <div class="flex flex-wrap gap-3">
                <span>QR-меню · Заказы · Оплата</span>
                <span class="hidden sm:inline-block text-slate-700">·</span>
                <span>PHP 8 · MySQL · TailwindCSS</span>
            </div>
        </footer>
    </main>
</div>
</body>
</html>
