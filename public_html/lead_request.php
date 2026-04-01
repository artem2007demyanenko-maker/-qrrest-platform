<?php


require_once __DIR__ . '/../app/bootstrap.php';

// Экранирование
if (!function_exists('h')) {
    function h($v): string {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

$errors  = [];
$success = false;

$data = [
    'contact_name'    => '',
    'contact_phone'   => '',
    'contact_email'   => '',
    'restaurant_name' => '',
    'message'         => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Собираем входные данные
    foreach ($data as $key => $_) {
        $val = isset($_POST[$key]) ? trim((string)$_POST[$key]) : '';

        if ($key !== 'message') {
            if (strlen($val) > 255) {
                $val = substr($val, 0, 255);
            }
        } else {
            if (strlen($val) > 2000) {
                $val = substr($val, 0, 2000);
            }
        }
        $data[$key] = $val;
    }

    // Валидация
    if ($data['contact_name'] === '') {
        $errors[] = 'Укажите, пожалуйста, ваше имя и должность.';
    }
    if ($data['contact_phone'] === '') {
        $errors[] = 'Укажите телефон или WhatsApp для связи.';
    }

    if (!$errors) {
        // Получаем PDO из твоей системы
        if (!function_exists('db')) {
            // Если вдруг в bootstrap нет db() — явно падаем с понятным текстом
            die('Ошибка конфигурации: не найдена функция db().');
        }

        /** @var PDO $pdo */
        $pdo = db();

        $user   = function_exists('auth_user') ? auth_user() : null;
        $userId = $user['id'] ?? null;
        $now    = date('Y-m-d H:i:s');

        $sql = "INSERT INTO lead_requests 
                (contact_name, contact_phone, contact_email, restaurant_name, message, user_id, status, created_at)
                VALUES 
                (:contact_name, :contact_phone, :contact_email, :restaurant_name, :message, :user_id, 'new', :created_at)";

        $stmt = $pdo->prepare($sql);

        try {
            $stmt->execute([
                ':contact_name'    => $data['contact_name'],
                ':contact_phone'   => $data['contact_phone'],
                ':contact_email'   => $data['contact_email'] ?: null,
                ':restaurant_name' => $data['restaurant_name'] ?: null,
                ':message'         => $data['message'] ?: null,
                ':user_id'         => $userId,
                ':created_at'      => $now,
            ]);

            $success = true;
            // очищаем форму
            foreach ($data as $k => $_) {
                $data[$k] = '';
            }

        } catch (PDOException $e) {
            $errors[] = 'Ошибка при сохранении заявки. Попробуйте ещё раз.';
            if (function_exists('app_log_error')) {
                app_log_error('Lead insert error: ' . $e->getMessage());
            }
        }
    }
}


$appName = 'QR-Rest Cloud';
$configPath = __DIR__ . '/../config.php';
if (is_file($configPath)) {
    $cfg = require $configPath;
    if (!empty($cfg['app']['name'])) {
        $appName = $cfg['app']['name'];
    }
}
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Заявка на подключение — <?= h($appName) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .float-slow { animation: float-slow 12s ease-in-out infinite; }
        .float-slow-2 { animation: float-slow-2 18s ease-in-out infinite; }
        @keyframes float-slow {
            0%, 100% { transform: translate3d(0, 0, 0) scale(1); }
            50%      { transform: translate3d(10px, -18px, 0) scale(1.03); }
        }
        @keyframes float-slow-2 {
            0%, 100% { transform: translate3d(0, 0, 0) scale(1); }
            50%      { transform: translate3d(-16px, 22px, 0) scale(1.04); }
        }
    </style>
</head>
<body class="min-h-screen bg-slate-950 text-slate-50">
<div class="min-h-screen relative overflow-hidden">
    <!-- фон -->
    <div class="pointer-events-none absolute inset-0">
        <div class="absolute -top-32 -left-24 w-72 h-72 bg-emerald-500/20 blur-3xl rounded-full float-slow"></div>
        <div class="absolute -bottom-40 right-0 w-96 h-96 bg-sky-500/20 blur-3xl rounded-full float-slow-2"></div>
        <div class="absolute top-1/3 -right-16 w-56 h-56 bg-fuchsia-500/20 blur-3xl rounded-full opacity-80"></div>
    </div>

    <div class="relative z-10 max-w-xl mx-auto px-4 py-8">
        <div class="mb-6">
            <a href="/" class="inline-flex items-center gap-2 text-xs text-slate-400 hover:text-emerald-300 transition">
                ← На главную
            </a>
        </div>

        <div class="rounded-3xl bg-slate-950/90 border border-slate-800/80 p-5 sm:p-6 shadow-2xl shadow-slate-950/80 backdrop-blur-xl">
            <?php if ($success): ?>
                <div class="mb-4">
                    <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-emerald-500/15 border border-emerald-500/50 text-[11px] text-emerald-100">
                        <span class="w-4 h-4 rounded-full bg-emerald-400 flex items-center justify-center text-[10px] text-slate-950 font-bold">✓</span>
                        <span>Заявка успешно отправлена</span>
                    </div>
                </div>
                <h1 class="text-xl sm:text-2xl font-semibold mb-2">
                    Спасибо! Мы получили вашу заявку 🙌
                </h1>
                <p class="text-sm text-slate-300 mb-4">
                    Мы свяжемся с вами по указанным контактам, чтобы обсудить подключение ресторана к платформе.
                </p>
                <div class="flex flex-col sm:flex-row gap-3 mt-4">
                    <a href="/"
                       class="inline-flex items-center justify-center flex-1 px-4 py-2.5 rounded-2xl bg-emerald-500 hover:bg-emerald-400 text-sm font-semibold text-slate-950 shadow-lg shadow-emerald-500/50 transition">
                        Вернуться на главную
                    </a>
                    <a href="/#pricing"
                       class="inline-flex items-center justify-center flex-1 px-4 py-2.5 rounded-2xl bg-slate-900 border border-slate-700 text-sm text-slate-100 hover:bg-slate-800 transition">
                        Посмотреть тарифы
                    </a>
                </div>
            <?php else: ?>
                <div class="mb-4">
                    <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-slate-900/80 border border-slate-700 text-[11px] text-slate-300">
                        Оставьте заявку на подключение к платформе
                    </div>
                </div>
                <h1 class="text-xl sm:text-2xl font-semibold mb-2">
                    Заявка на подключение ресторана
                </h1>
                <p class="text-sm text-slate-300 mb-4">
                    Заполните форму — мы свяжемся с вами, уточним детали и предложим варианты запуска QR-меню и системы заказов.
                </p>

                <?php if ($errors): ?>
                    <div class="mb-4 rounded-2xl border border-red-500/60 bg-red-500/10 px-3 py-2 text-xs text-red-100">
                        <?php foreach ($errors as $err): ?>
                            <div>• <?= h($err) ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <form method="post" action="/lead_request.php" class="space-y-3 text-sm">
                    <div>
                        <label class="block text-[11px] text-slate-300 mb-1">Имя и должность</label>
                        <input type="text" name="contact_name" required
                               value="<?= h($data['contact_name']) ?>"
                               placeholder="Например: Илья, владелец сети"
                               class="w-full rounded-xl bg-slate-900 border border-slate-700 px-3 py-2 text-sm text-slate-50 placeholder:text-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-[11px] text-slate-300 mb-1">Телефон или WhatsApp</label>
                            <input type="text" name="contact_phone" required
                                   value="<?= h($data['contact_phone']) ?>"
                                   placeholder="+7…"
                                   class="w-full rounded-xl bg-slate-900 border border-slate-700 px-3 py-2 text-sm text-slate-50 placeholder:text-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                        </div>
                        <div>
                            <label class="block text-[11px] text-slate-300 mb-1">E-mail</label>
                            <input type="email" name="contact_email"
                                   value="<?= h($data['contact_email']) ?>"
                                   placeholder="email@restaurant.ru"
                                   class="w-full rounded-xl bg-slate-900 border border-slate-700 px-3 py-2 text-sm text-slate-50 placeholder:text-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                        </div>
                    </div>
                    <div>
                        <label class="block text-[11px] text-slate-300 mb-1">Ресторан или сеть</label>
                        <input type="text" name="restaurant_name"
                               value="<?= h($data['restaurant_name']) ?>"
                               placeholder="Название ресторана / сети"
                               class="w-full rounded-xl bg-slate-900 border border-slate-700 px-3 py-2 text-sm text-slate-50 placeholder:text-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                    </div>
                    <div>
                        <label class="block text-[11px] text-slate-300 mb-1">Комментарий</label>
                        <textarea name="message" rows="3"
                                  placeholder="Сколько у вас ресторанов, как сейчас принимаете заказы и оплату, когда планируете запуск…"
                                  class="w-full rounded-xl bg-slate-900 border border-slate-700 px-3 py-2 text-sm text-slate-50 placeholder:text-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500"><?= h($data['message']) ?></textarea>
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
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>