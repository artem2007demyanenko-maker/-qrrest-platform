<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Политика конфиденциальности и Cookies — QR REST</title>
    <?= brand_head_tags() ?>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-950 text-slate-100">
<main class="max-w-3xl mx-auto px-4 py-10">
    <div class="mb-8">
        <a href="/" class="inline-flex items-center text-sm text-emerald-300 hover:text-emerald-200">← На главную</a>
    </div>

    <section class="rounded-2xl border border-slate-700 bg-slate-900/80 shadow-xl shadow-slate-950/40 p-6 md:p-8">
        <h1 class="text-2xl md:text-3xl font-semibold mb-4">Политика конфиденциальности и Cookies</h1>
        <p class="text-slate-300 leading-relaxed mb-6">
            Это временная страница-шаблон. Здесь будет размещена полная версия политики обработки персональных данных и cookies для QR REST.
        </p>

        <div class="space-y-5 text-sm md:text-base text-slate-300 leading-relaxed">
            <section>
                <h2 class="text-lg font-medium text-slate-100 mb-2">1. Какие cookies мы используем</h2>
                <p>
                    На сайте могут использоваться необходимые, аналитические, персонализационные и маркетинговые cookies. Необходимые cookies требуются для работы меню, корзины, оформления и отслеживания заказа.
                </p>
            </section>
            <section>
                <h2 class="text-lg font-medium text-slate-100 mb-2">2. Цели использования</h2>
                <p>
                    Cookies помогают обеспечить стабильную работу сервиса, улучшать интерфейс, сохранять пользовательские настройки и, при наличии согласия, подключать внешние аналитические и маркетинговые инструменты.
                </p>
            </section>
            <section>
                <h2 class="text-lg font-medium text-slate-100 mb-2">3. Управление согласием</h2>
                <p>
                    Вы можете принять все cookies, оставить только необходимые или выбрать категории в настройках. Параметры можно изменить в любой момент через ссылку «Настройки cookies» внизу сайта.
                </p>
            </section>
            <section>
                <h2 class="text-lg font-medium text-slate-100 mb-2">4. Контакты</h2>
                <p>
                    Для запросов по конфиденциальности используйте контактные данные компании/владельца сервиса. Этот блок будет уточнён в финальной версии политики.
                </p>
            </section>
        </div>
    </section>
</main>
</body>
</html>
