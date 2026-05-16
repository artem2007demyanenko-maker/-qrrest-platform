<?php
/**
 * SaaS landing page: hero, features, lead form (POST to /api/lead_request.php).
 */

$rid = bin2hex(random_bytes(4));
require_once __DIR__ . '/../app/bootstrap.php';

if (!defined('BRAND_NAME')) {
    define('BRAND_NAME', 'QR Rest');
}
if (!defined('BRAND_NAME_FULL')) {
    define('BRAND_NAME_FULL', 'QR Rest');
}

if (!function_exists('e')) {
    function e($v): string {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

$config = require __DIR__ . '/../app/config.php';
$mainDomain = $config['app']['main_domain'] ?? 'lvh.me';
$protocol   = $config['app']['protocol'] ?? 'http';
$demoUrl    = $protocol . '://demo.' . $mainDomain . qr_public_build_url('/qr.php', []);
$demoDashboardUrl = $protocol . '://demo.' . $mainDomain . '/restaurant/dashboard.php';

$formSuccess = false;
$formError   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['lead_submit'])) {
    $name            = trim((string)($_POST['name'] ?? ''));
    $restaurant_name = trim((string)($_POST['restaurant_name'] ?? ''));
    $phone           = trim((string)($_POST['phone'] ?? ''));
    $city            = trim((string)($_POST['city'] ?? ''));

    if ($name !== '' || $phone !== '' || $restaurant_name !== '' || $city !== '') {
        $ch = curl_init($protocol . '://' . ($_SERVER['HTTP_HOST'] ?? $mainDomain) . '/api/lead_request.php');
        if ($ch) {
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query([
                    'name' => $name,
                    'restaurant_name' => $restaurant_name,
                    'phone' => $phone,
                    'city' => $city,
                ]),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 5,
            ]);
            $response = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($code === 200 && $response && strpos($response, '"success":true') !== false) {
                $formSuccess = true;
            } else {
                $formError = 'Не удалось отправить заявку. Попробуйте ещё раз.';
            }
        } else {
            $formError = 'Не удалось отправить заявку.';
        }
    } else {
        $formError = 'Заполните хотя бы одно поле.';
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(BRAND_NAME_FULL) ?> — цифровые меню и заказы</title>
    <?= brand_head_tags() ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="/assets/css/motion.css">
    <link rel="stylesheet" href="/assets/css/polish.css">
    <link rel="stylesheet" href="/assets/css/atmosphere.css">
    <style>
        body { font-family: Inter, system-ui, sans-serif; }
        .gradient-mesh { background: radial-gradient(ellipse 80% 50% at 50% -20%, rgba(16, 185, 129, 0.12), transparent), radial-gradient(ellipse 60% 40% at 100% 0%, rgba(34, 197, 94, 0.08), transparent), radial-gradient(ellipse 50% 30% at 0% 50%, rgba(56, 189, 248, 0.06), transparent); }
        .hero-gradient { background: linear-gradient(135deg, rgba(16,185,129,0.07) 0%, transparent 40%, rgba(34,197,94,0.06) 70%, transparent 100%); }
        .hero-gradient-slow { background-size: 400% 400%; animation: heroGradientSlow 16s ease-in-out infinite; }
        @keyframes heroGradientSlow { 0%, 100% { background-position: 0% 50%; } 50% { background-position: 100% 50%; } }
        .product-card-gradient { background: linear-gradient(145deg, rgba(16,185,129,0.12) 0%, rgba(34,197,94,0.06) 50%, transparent 100%); animation: productGlow 8s ease-in-out infinite; }
        @keyframes productGlow { 0%, 100% { opacity: 1; } 50% { opacity: 0.85; } }
        @media (prefers-reduced-motion: reduce) {
            .hero-gradient-slow, .product-card-gradient { animation: none !important; }
        }
    </style>
</head>
<body class="min-h-screen text-gray-300 antialiased overflow-x-hidden" style="background-color: #0B0F19;">
<div class="min-h-screen gradient-mesh hero-gradient hero-gradient-slow">

    <!-- Sticky glass header -->
    <header id="saas-header" class="sticky-glass-header">
        <div class="max-w-6xl mx-auto px-4 py-3 flex items-center justify-between">
            <?= brand_header_cluster_html(true, 'w-8 h-8 text-slate-400') ?>
            <nav class="flex flex-wrap items-center justify-end gap-3 sm:gap-4">
                <a href="/" class="text-sm text-gray-400 hover:text-[#F3F4F6] transition-colors">Платформа</a>
                <a href="<?= e($demoDashboardUrl) ?>" target="_blank" rel="noopener" class="text-sm text-gray-400 hover:text-[#F3F4F6] transition-colors">Демо</a>
                <a href="/login.php" class="text-sm text-gray-400 hover:text-[#F3F4F6] transition-colors">Войти</a>
                <a href="/signup.php" class="btn-motion inline-flex items-center px-4 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white font-medium text-sm shadow-md shadow-emerald-900/30">Начать бесплатный период</a>
            </nav>
        </div>
    </header>

    <!-- 1. Hero: two columns -->
    <section class="max-w-6xl mx-auto px-4 pt-16 pb-20 md:pt-24 md:pb-28">
        <div class="grid lg:grid-cols-2 gap-12 lg:gap-16 items-center">
            <div class="reveal">
                <h1 class="text-4xl md:text-5xl lg:text-6xl font-semibold tracking-tight text-[#F3F4F6] mb-6 leading-tight">
                    Управляйте рестораном: QR-меню, допродажи и CRM гостей.
                </h1>
                <p class="text-lg md:text-xl text-gray-400 mb-8 max-w-xl">
                    Растите выручку, возвращайте гостей — всё в одной панели управления.
                </p>
                <div class="flex flex-wrap gap-3">
                    <a href="/signup.php" class="btn-motion inline-flex items-center px-6 py-3 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white font-medium text-sm">Начать бесплатный период</a>
                    <a href="<?= e($demoDashboardUrl) ?>" target="_blank" rel="noopener" class="btn-motion inline-flex items-center px-6 py-3 rounded-xl bg-gray-800 hover:bg-gray-700 text-[#F3F4F6] font-medium text-sm border border-gray-700">Демо-версия</a>
                </div>
            </div>
            <div class="relative reveal">
                <div class="product-frame product-card-gradient rounded-2xl border border-gray-800 bg-[#121826] shadow-2xl shadow-black/30 overflow-hidden card-motion">
                    <div class="product-frame-bar">
                        <span class="product-frame-dot"></span>
                        <span class="product-frame-dot"></span>
                        <span class="product-frame-dot"></span>
                    </div>
                    <div class="p-6">
                    <div class="grid grid-cols-3 gap-3 mb-4">
                        <div class="rounded-xl bg-gray-900/80 border border-gray-800 p-3">
                            <div class="text-[10px] text-gray-500 uppercase tracking-wide mb-1">Выручка за сегодня</div>
                            <div class="text-lg font-bold text-[#22C55E]">1,284 ₽</div>
                        </div>
                        <div class="rounded-xl bg-gray-900/80 border border-gray-800 p-3">
                            <div class="text-[10px] text-gray-500 uppercase tracking-wide mb-1">Заказы</div>
                            <div class="text-lg font-bold text-[#F3F4F6]">36</div>
                        </div>
                        <div class="rounded-xl bg-gray-900/80 border border-gray-800 p-3">
                            <div class="text-[10px] text-gray-500 uppercase tracking-wide mb-1">Гости</div>
                            <div class="text-lg font-bold text-[#F3F4F6]">28</div>
                        </div>
                    </div>
                    <div class="rounded-xl bg-gray-900/60 border border-gray-800 h-28 flex items-center justify-center">
                        <span class="text-sm text-gray-500">Предпросмотр панели</span>
                    </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Built for modern restaurants -->
    <section class="border-t border-gray-800 py-8">
        <div class="max-w-5xl mx-auto px-4 text-center reveal">
            <p class="text-sm text-gray-400 mb-4">Для современных ресторанов</p>
            <div class="flex flex-wrap justify-center gap-3">
                <span class="px-4 py-2 rounded-full bg-[#121826] border border-gray-800 text-sm text-gray-300">Заказы по QR</span>
                <span class="px-4 py-2 rounded-full bg-[#121826] border border-gray-800 text-sm text-gray-300">Режим официанта</span>
                <span class="px-4 py-2 rounded-full bg-[#121826] border border-gray-800 text-sm text-gray-300">Возврат гостей</span>
            </div>
        </div>
    </section>

    <!-- 2. Trust -->
    <section class="border-t border-gray-800 py-16">
        <div class="max-w-5xl mx-auto px-4 text-center reveal">
            <h2 class="text-xl font-semibold tracking-tight text-[#F3F4F6] mb-2">Рестораны уже используют QR Restaurant SaaS</h2>
            <p class="text-sm text-gray-400 max-w-2xl mx-auto mb-8">Рестораны по всему миру управляют цифровыми меню и увеличивают выручку с помощью платформы.</p>
            <div class="flex flex-wrap justify-center gap-3 md:gap-4">
                <span class="px-4 py-2.5 rounded-xl bg-[#121826] border border-gray-800 text-sm text-gray-300 card-motion">Pasta Bistro</span>
                <span class="px-4 py-2.5 rounded-xl bg-[#121826] border border-gray-800 text-sm text-gray-300 card-motion">Sushi Bar Tokyo</span>
                <span class="px-4 py-2.5 rounded-xl bg-[#121826] border border-gray-800 text-sm text-gray-300 card-motion">Coffee & Cake</span>
                <span class="px-4 py-2.5 rounded-xl bg-[#121826] border border-gray-800 text-sm text-gray-300 card-motion">Grill House</span>
                <span class="px-4 py-2.5 rounded-xl bg-[#121826] border border-gray-800 text-sm text-gray-300 card-motion">Pizza Roma</span>
                <span class="px-4 py-2.5 rounded-xl bg-[#121826] border border-gray-800 text-sm text-gray-300 card-motion">Urban Kitchen</span>
            </div>
        </div>
    </section>

    <!-- 3. How it works -->
    <section class="border-t border-gray-800 py-16">
        <div class="max-w-5xl mx-auto px-4 reveal">
            <h2 class="text-2xl font-semibold tracking-tight text-[#F3F4F6] mb-2 text-center">Как это работает</h2>
            <p class="text-sm text-gray-400 text-center mb-12">Запуск за несколько минут.</p>
            <div class="grid md:grid-cols-3 gap-8">
                <div class="rounded-xl border border-gray-800 bg-[#121826] p-6 card-motion text-center">
                    <div class="w-12 h-12 rounded-xl bg-emerald-500/20 flex items-center justify-center mx-auto mb-4 text-emerald-400">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>
                    </div>
                    <h3 class="text-lg font-semibold text-[#F3F4F6] mb-2">Шаг 1</h3>
                    <p class="text-base font-medium text-gray-300 mb-2">Создайте цифровое меню</p>
                    <p class="text-sm text-gray-500">Добавьте категории и блюда с ценами и фото. Обновляйте в любой момент.</p>
                </div>
                <div class="rounded-xl border border-gray-800 bg-[#121826] p-6 card-motion text-center">
                    <div class="w-12 h-12 rounded-xl bg-emerald-500/20 flex items-center justify-center mx-auto mb-4 text-emerald-400">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm12 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z"/></svg>
                    </div>
                    <h3 class="text-lg font-semibold text-[#F3F4F6] mb-2">Шаг 2</h3>
                    <p class="text-base font-medium text-gray-300 mb-2">Напечатайте QR-коды для столов</p>
                    <p class="text-sm text-gray-500">Сгенерируйте и распечатайте QR-коды. Гости сканируют и открывают меню на телефоне.</p>
                </div>
                <div class="rounded-xl border border-gray-800 bg-[#121826] p-6 card-motion text-center">
                    <div class="w-12 h-12 rounded-xl bg-emerald-500/20 flex items-center justify-center mx-auto mb-4 text-emerald-400">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                    </div>
                    <h3 class="text-lg font-semibold text-[#F3F4F6] mb-2">Шаг 3</h3>
                    <p class="text-base font-medium text-gray-300 mb-2">Получайте заказы сразу</p>
                    <p class="text-sm text-gray-500">Заказы отображаются в панели. Статусы и выручка в реальном времени.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- 4. Product features -->
    <section class="border-t border-gray-800 py-16">
        <div class="max-w-5xl mx-auto px-4 reveal">
            <h2 class="text-2xl font-semibold tracking-tight text-[#F3F4F6] mb-2 text-center">Возможности</h2>
            <p class="text-sm text-gray-400 text-center mb-12">Всё необходимое для цифрового ресторана.</p>
            <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-6">
                <div class="rounded-xl border border-gray-800 bg-[#121826] p-6 card-motion feature-card-hover">
                    <div class="w-10 h-10 rounded-lg bg-emerald-500/20 flex items-center justify-center mb-4 text-emerald-400">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm12 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z"/></svg>
                    </div>
                    <h3 class="text-lg font-semibold tracking-tight text-[#F3F4F6] mb-2">QR-меню</h3>
                    <p class="text-sm text-gray-400">Гости сканируют QR-код и заказывают с телефона. Меню всегда актуально.</p>
                </div>
                <div class="rounded-xl border border-gray-800 bg-[#121826] p-6 card-motion feature-card-hover">
                    <div class="w-10 h-10 rounded-lg bg-green-500/20 flex items-center justify-center mb-4 text-[#22C55E]">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>
                    </div>
                    <h3 class="text-lg font-semibold tracking-tight text-[#F3F4F6] mb-2">Допродажи</h3>
                    <p class="text-sm text-gray-400">Умные подсказки при оформлении заказа повышают средний чек.</p>
                </div>
                <div class="rounded-xl border border-gray-800 bg-[#121826] p-6 card-motion feature-card-hover">
                    <div class="w-10 h-10 rounded-lg bg-emerald-500/20 flex items-center justify-center mb-4 text-emerald-400">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                    </div>
                    <h3 class="text-lg font-semibold tracking-tight text-[#F3F4F6] mb-2">CRM и возврат гостей</h3>
                    <p class="text-sm text-gray-400">Собирайте контакты гостей и отправляйте напоминания. SMS и email-кампании.</p>
                </div>
                <div class="rounded-xl border border-gray-800 bg-[#121826] p-6 card-motion feature-card-hover">
                    <div class="w-10 h-10 rounded-lg bg-emerald-500/20 flex items-center justify-center mb-4 text-emerald-400">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                    </div>
                    <h3 class="text-lg font-semibold tracking-tight text-[#F3F4F6] mb-2">Выручка и аналитика меню</h3>
                    <p class="text-sm text-gray-400">Выручка, заказы за день, популярные блюда и возврат гостей в одной панели.</p>
                </div>
                <div class="rounded-xl border border-gray-800 bg-[#121826] p-6 card-motion feature-card-hover">
                    <div class="w-10 h-10 rounded-lg bg-amber-500/20 flex items-center justify-center mb-4 text-amber-400">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                    </div>
                    <h3 class="text-lg font-semibold tracking-tight text-[#F3F4F6] mb-2">Отслеживание заказов</h3>
                    <p class="text-sm text-gray-400">Заказы в реальном времени. Обновляйте статусы — кухня и гости в курсе.</p>
                </div>
                <div class="rounded-xl border border-gray-800 bg-[#121826] p-6 card-motion feature-card-hover">
                    <div class="w-10 h-10 rounded-lg bg-emerald-500/20 flex items-center justify-center mb-4 text-emerald-400">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
                    </div>
                    <h3 class="text-lg font-semibold tracking-tight text-[#F3F4F6] mb-2">QR и режим официанта</h3>
                    <p class="text-sm text-gray-400">Гости заказывают по QR или с планшета с помощью сотрудника. Одна система.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- 5. Product preview -->
    <section class="border-t border-gray-800 py-16">
        <div class="max-w-5xl mx-auto px-4 reveal">
            <h2 class="text-2xl font-semibold tracking-tight text-[#F3F4F6] mb-2 text-center">Как это выглядит</h2>
            <p class="text-sm text-gray-400 text-center mb-10">Одна панель: аналитика, CRM и меню.</p>
            <div class="product-frame rounded-2xl border border-gray-800 bg-[#121826] card-motion max-w-4xl mx-auto product-card-gradient">
                <div class="product-frame-bar">
                    <span class="product-frame-dot"></span>
                    <span class="product-frame-dot"></span>
                    <span class="product-frame-dot"></span>
                </div>
                <div class="p-6 md:p-8">
                <div class="flex items-center gap-2 mb-6">
                    <div class="w-2 h-2 rounded-full bg-gray-500"></div>
                    <div class="w-2 h-2 rounded-full bg-gray-500"></div>
                    <div class="w-2 h-2 rounded-full bg-gray-500"></div>
                </div>
                <div class="flex flex-wrap gap-3 mb-6">
                    <div class="rounded-xl bg-gray-900/80 border border-gray-800 px-4 py-3 flex items-center gap-2">
                        <svg class="w-4 h-4 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                        <span class="text-sm font-medium text-slate-200">Аналитика выручки</span>
                    </div>
                    <div class="rounded-xl bg-gray-900/80 border border-gray-800 px-4 py-3 flex items-center gap-2">
                        <svg class="w-4 h-4 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                        <span class="text-sm font-medium text-slate-200">CRM гостей</span>
                    </div>
                    <div class="rounded-xl bg-gray-900/80 border border-gray-800 px-4 py-3 flex items-center gap-2">
                        <svg class="w-4 h-4 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>
                        <span class="text-sm font-medium text-slate-200">Эффективность допродаж</span>
                    </div>
                </div>
                <div class="grid grid-cols-3 gap-3 mb-4">
                    <div class="rounded-xl bg-gray-900/80 border border-gray-800 p-3">
                        <div class="text-[10px] text-gray-500 uppercase tracking-wide mb-1">Выручка за сегодня</div>
                        <div class="text-lg font-bold text-[#22C55E]">1,284 ₽</div>
                    </div>
                    <div class="rounded-xl bg-gray-900/80 border border-gray-800 p-3">
                        <div class="text-[10px] text-gray-500 uppercase tracking-wide mb-1">Заказы</div>
                        <div class="text-lg font-bold text-[#F3F4F6]">36</div>
                    </div>
                    <div class="rounded-xl bg-gray-900/80 border border-gray-800 p-3">
                        <div class="text-[10px] text-gray-500 uppercase tracking-wide mb-1">Гости</div>
                        <div class="text-lg font-bold text-[#F3F4F6]">28</div>
                    </div>
                </div>
                <div class="rounded-xl bg-gray-900/60 border border-gray-800 h-32 flex items-center justify-center">
                    <span class="text-sm text-gray-500">Предпросмотр панели ресторана</span>
                </div>
                </div>
            </div>
        </div>
    </section>

    <!-- 6. Benefits -->
    <section class="border-t border-gray-800 py-16">
        <div class="max-w-5xl mx-auto px-4 reveal">
            <h2 class="text-2xl font-semibold tracking-tight text-[#F3F4F6] mb-2 text-center">Почему выбирают нас</h2>
            <p class="text-sm text-gray-400 text-center mb-12">Реальные результаты с одной платформы.</p>
            <div class="grid md:grid-cols-2 lg:grid-cols-4 gap-6">
                <div class="rounded-xl border border-gray-800 bg-[#121826] p-6 card-motion text-center">
                    <div class="w-10 h-10 rounded-xl bg-emerald-500/20 flex items-center justify-center mx-auto mb-4 text-emerald-400">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    </div>
                    <h3 class="text-base font-semibold text-[#F3F4F6] mb-2">Рост среднего чека</h3>
                    <p class="text-sm text-gray-500">Допродажи и подсказки при оформлении повышают сумму заказа.</p>
                </div>
                <div class="rounded-xl border border-gray-800 bg-[#121826] p-6 card-motion text-center">
                    <div class="w-10 h-10 rounded-xl bg-emerald-500/20 flex items-center justify-center mx-auto mb-4 text-emerald-400">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                    </div>
                    <h3 class="text-base font-semibold text-[#F3F4F6] mb-2">Возврат гостей</h3>
                    <p class="text-sm text-gray-500">CRM и напоминания возвращают гостей без лишних усилий.</p>
                </div>
                <div class="rounded-xl border border-gray-800 bg-[#121826] p-6 card-motion text-center">
                    <div class="w-10 h-10 rounded-xl bg-amber-500/20 flex items-center justify-center mx-auto mb-4 text-amber-400">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/></svg>
                    </div>
                    <h3 class="text-base font-semibold text-[#F3F4F6] mb-2">Меньше нагрузки на персонал</h3>
                    <p class="text-sm text-gray-500">Гости заказывают по QR — меньше ручных заказов и ошибок.</p>
                </div>
                <div class="rounded-xl border border-gray-800 bg-[#121826] p-6 card-motion text-center">
                    <div class="w-10 h-10 rounded-xl bg-emerald-500/20 flex items-center justify-center mx-auto mb-4 text-emerald-400">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                    </div>
                    <h3 class="text-base font-semibold text-[#F3F4F6] mb-2">Выручка в реальном времени</h3>
                    <p class="text-sm text-gray-500">В панели — выручка, заказы и тренды по мере поступления.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- 7. Demo -->
    <section class="border-t border-gray-800 py-16">
        <div class="max-w-3xl mx-auto px-4 text-center reveal">
            <h2 class="text-2xl font-semibold tracking-tight text-[#F3F4F6] mb-3">Попробуйте демо-версию</h2>
            <p class="text-sm text-gray-400 mb-8">Панель ресторана и заказ через QR — в одном месте.</p>
            <a href="<?= e($demoDashboardUrl) ?>" target="_blank" rel="noopener" class="btn-motion inline-flex items-center px-6 py-3 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white font-medium text-sm">Открыть демо</a>
        </div>
    </section>

    <!-- 8. Pricing preview -->
    <section class="border-t border-gray-800 py-16">
        <div class="max-w-5xl mx-auto px-4 reveal">
            <h2 class="text-2xl font-semibold tracking-tight text-[#F3F4F6] mb-2 text-center">Тарифы</h2>
            <p class="text-sm text-gray-400 text-center mb-12">Выберите план под ваш ресторан.</p>
            <div class="grid md:grid-cols-3 gap-6">
                <div class="rounded-xl border border-gray-800 bg-[#121826] p-6 card-motion text-center">
                    <h3 class="text-lg font-semibold text-[#F3F4F6] mb-1">Старт</h3>
                    <div class="text-2xl font-bold text-[#F3F4F6] mb-4 mt-2">—</div>
                    <ul class="text-sm text-gray-400 space-y-2 mb-6 text-left">
                        <li>1 ресторан</li>
                        <li>QR-меню и заказы</li>
                        <li>Базовая аналитика</li>
                    </ul>
                    <a href="/signup.php" class="btn-motion block w-full py-2.5 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white font-medium text-sm text-center">Начать бесплатный период</a>
                </div>
                <div class="rounded-xl border border-emerald-500/50 bg-[#121826] p-6 card-motion text-center relative">
                    <span class="absolute -top-3 left-1/2 -translate-x-1/2 px-3 py-0.5 rounded-full bg-emerald-600 text-xs font-medium text-white">Популярный</span>
                    <h3 class="text-lg font-semibold text-[#F3F4F6] mb-1">Про</h3>
                    <div class="text-2xl font-bold text-[#F3F4F6] mb-4 mt-2">—</div>
                    <ul class="text-sm text-gray-400 space-y-2 mb-6 text-left">
                        <li>Несколько заведений</li>
                        <li>Допродажи и CRM</li>
                        <li>Аналитика выручки</li>
                    </ul>
                    <a href="/signup.php" class="btn-motion block w-full py-2.5 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white font-medium text-sm text-center">Начать бесплатный период</a>
                </div>
                <div class="rounded-xl border border-gray-800 bg-[#121826] p-6 card-motion text-center">
                    <h3 class="text-lg font-semibold text-[#F3F4F6] mb-1">Бизнес</h3>
                    <div class="text-2xl font-bold text-[#F3F4F6] mb-4 mt-2">—</div>
                    <ul class="text-sm text-gray-400 space-y-2 mb-6 text-left">
                        <li>Безлимит ресторанов</li>
                        <li>API и интеграции</li>
                        <li>Персональная поддержка</li>
                    </ul>
                    <a href="/signup.php" class="btn-motion block w-full py-2.5 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white font-medium text-sm text-center">Начать бесплатный период</a>
                </div>
            </div>
        </div>
    </section>

    <!-- 9. Final CTA -->
    <section class="border-t border-gray-800 py-20">
        <div class="max-w-3xl mx-auto px-4 text-center reveal">
            <h2 class="text-2xl md:text-3xl font-semibold tracking-tight text-[#F3F4F6] mb-3">Запустите цифровой ресторан уже сегодня.</h2>
            <p class="text-sm text-gray-400 mb-8">Бесплатный период. Без привязки карты.</p>
            <div class="flex flex-wrap justify-center gap-4">
                <a href="/signup.php" class="btn-motion inline-flex items-center px-6 py-3 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white font-medium text-sm">Начать бесплатный период</a>
                <a href="<?= e($demoDashboardUrl) ?>" target="_blank" rel="noopener" class="btn-motion inline-flex items-center px-6 py-3 rounded-xl bg-gray-800 hover:bg-gray-700 text-[#F3F4F6] font-medium text-sm border border-gray-700">Демо-версия</a>
            </div>
        </div>
    </section>

    <!-- Lead form -->
    <section id="lead-form" class="section max-w-md mx-auto px-4 py-16 border-t border-gray-800 reveal">
        <h2 class="text-xl font-semibold tracking-tight text-[#F3F4F6] mb-6 text-center">Запросить демо</h2>
        <?php if ($formSuccess): ?>
            <div class="rounded-xl bg-green-500/10 border border-green-500/30 px-4 py-3 text-sm text-green-400 text-center">Заявка принята. Мы свяжемся с вами.</div>
        <?php else: ?>
            <?php if ($formError): ?>
                <div class="rounded-xl bg-red-500/10 border border-red-500/30 px-4 py-2 text-sm text-red-400 mb-4"><?= e($formError) ?></div>
            <?php endif; ?>
            <div class="rounded-xl border border-gray-800 bg-[#121826] shadow-xl shadow-black/20 p-6 card-motion">
                <form method="post" action="#lead-form" class="space-y-4">
                    <input type="hidden" name="lead_submit" value="1">
                    <div>
                        <label for="name" class="block text-xs text-gray-400 mb-1">Имя</label>
                        <input type="text" id="name" name="name" class="w-full rounded-lg bg-gray-900 border border-gray-700 px-3 py-2 text-sm text-[#F3F4F6] focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-transparent" placeholder="Ваше имя">
                    </div>
                    <div>
                        <label for="restaurant_name" class="block text-xs text-gray-400 mb-1">Название ресторана</label>
                        <input type="text" id="restaurant_name" name="restaurant_name" class="w-full rounded-lg bg-gray-900 border border-gray-700 px-3 py-2 text-sm text-[#F3F4F6] focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-transparent" placeholder="Ресторан">
                    </div>
                    <div>
                        <label for="phone" class="block text-xs text-gray-400 mb-1">Телефон</label>
                        <input type="text" id="phone" name="phone" class="w-full rounded-lg bg-gray-900 border border-gray-700 px-3 py-2 text-sm text-[#F3F4F6] focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-transparent" placeholder="+7 ...">
                    </div>
                    <div>
                        <label for="city" class="block text-xs text-gray-400 mb-1">Город</label>
                        <input type="text" id="city" name="city" class="w-full rounded-lg bg-gray-900 border border-gray-700 px-3 py-2 text-sm text-[#F3F4F6] focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-transparent" placeholder="Город">
                    </div>
                    <button type="submit" class="btn-motion w-full py-2.5 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white font-medium text-sm">
                        Отправить
                    </button>
                </form>
            </div>
        <?php endif; ?>
    </section>

    <footer class="py-8 text-center text-xs text-gray-500 border-t border-gray-800">
        <a href="/login.php" class="text-gray-400 hover:text-[#F3F4F6] transition-colors">Войти</a>
        <span class="mx-2">·</span>
        <a href="/signup.php" class="text-gray-400 hover:text-[#F3F4F6] transition-colors">Регистрация</a>
    </footer>

    <!-- Floating CTA - appears after scroll past hero -->
    <div id="floating-cta" class="fixed bottom-6 left-1/2 -translate-x-1/2 z-40 opacity-0 pointer-events-none transition-opacity duration-300 md:bottom-8">
        <a href="/signup.php" class="btn-motion inline-flex items-center px-5 py-2.5 rounded-full bg-emerald-600 hover:bg-emerald-500 text-white font-medium text-sm shadow-lg shadow-emerald-900/25 border border-emerald-500/30">Начать бесплатный период</a>
    </div>
</div>
<script src="/assets/js/motion.js"></script>
<script src="/assets/js/toast.js"></script>
<script>
(function() {
    var header = document.getElementById('saas-header');
    var floatingCta = document.getElementById('floating-cta');
    if (header) {
        window.addEventListener('scroll', function() {
            if (window.scrollY > 320) header.classList.add('scrolled');
            else header.classList.remove('scrolled');
        });
    }
    if (floatingCta) {
        window.addEventListener('scroll', function() {
            if (window.scrollY > 380) {
                floatingCta.classList.remove('opacity-0', 'pointer-events-none');
            } else {
                floatingCta.classList.add('opacity-0', 'pointer-events-none');
            }
        });
    }
    document.querySelectorAll('.js-copy-link').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var targetId = this.getAttribute('data-copy-target');
            var copyText = this.getAttribute('data-copy-text');
            var text = copyText || (targetId ? (document.getElementById(targetId) && document.getElementById(targetId).value) : '');
            var tooltip = this.querySelector('.js-copy-tooltip');
            if (text && window.Toast) {
                navigator.clipboard.writeText(text).then(function() {
                    window.Toast.success('Скопировано');
                    if (tooltip) { tooltip.classList.remove('hidden'); setTimeout(function() { tooltip.classList.add('hidden'); }, 2000); }
                });
            }
        });
    });
})();
</script>
</body>
</html>
