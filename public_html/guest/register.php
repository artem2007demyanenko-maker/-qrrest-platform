<?php
require_once __DIR__ . '/../../app/bootstrap.php';

$pdo = db();

$redirect = $_GET['redirect'] ?? '/guest/wallet.php';
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $phone = trim($_POST['phone'] ?? '');
    $pin   = trim($_POST['pin'] ?? '');
    $name  = trim($_POST['name'] ?? '');
    $name  = $name !== '' ? $name : null;

    if (!guest_phone_normalize($phone)) {
        $error = 'Введите корректный номер телефона.';
    } elseif (!guest_pin_is_valid($pin)) {
        $error = 'PIN должен быть 4–8 цифр.';
    } else {
        $id = guest_register($pdo, $phone, $pin, $name);
        if ($id) {
            header('Location: ' . $redirect);
            exit;
        }
        $error = 'Аккаунт с таким телефоном уже существует. Попробуйте войти.';
    }
}
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <title>Регистрация — Guest Wallet</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-950 text-slate-50 flex items-center justify-center p-4">
  <div class="w-full max-w-md rounded-3xl bg-slate-950/85 border border-slate-800 p-6">
    <div class="text-xs text-slate-400 uppercase tracking-wide">Guest Wallet</div>
    <h1 class="text-2xl font-semibold mt-1">Регистрация</h1>
    <p class="text-sm text-slate-400 mt-1 mb-4">Создайте один аккаунт — и храните все карты, как в Wallet.</p>

    <?php if ($error): ?>
      <div class="mb-4 rounded-2xl border border-rose-500/70 bg-rose-500/10 px-3 py-2 text-xs text-rose-100">
        <?= e($error) ?>
      </div>
    <?php endif; ?>

    <form method="post" class="space-y-3">
      <div>
        <label class="block text-[11px] text-slate-300 mb-1">Телефон</label>
        <input name="phone" type="tel" required placeholder="+7 9XX XXX-XX-XX"
               class="w-full rounded-2xl bg-slate-900 border border-slate-700 px-3 py-2 text-sm">
      </div>
      <div>
        <label class="block text-[11px] text-slate-300 mb-1">Имя (опционально)</label>
        <input name="name" type="text" placeholder="Например: Анна"
               class="w-full rounded-2xl bg-slate-900 border border-slate-700 px-3 py-2 text-sm">
      </div>
      <div>
        <label class="block text-[11px] text-slate-300 mb-1">PIN-код (4–8 цифр)</label>
        <input name="pin" type="password" required placeholder="например 123456"
               class="w-full rounded-2xl bg-slate-900 border border-slate-700 px-3 py-2 text-sm">
      </div>

      <button class="w-full px-4 py-2.5 rounded-2xl bg-emerald-500 hover:bg-emerald-400 text-sm font-semibold text-slate-950">
        Создать аккаунт
      </button>

      <a href="/guest/login.php?redirect=<?= e(urlencode($redirect)) ?>"
         class="block text-center text-[11px] text-slate-400 hover:text-slate-200">
        Уже есть аккаунт? Войти
      </a>
    </form>
  </div>
</body>
</html>
