<?php
require_once __DIR__ . '/../../app/bootstrap.php';

$pdo = db();

$redirect = $_GET['redirect'] ?? '/guest/wallet.php';
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $phone = trim($_POST['phone'] ?? '');
    $pin   = trim($_POST['pin'] ?? '');

    if (guest_login($pdo, $phone, $pin)) {
        header('Location: ' . $redirect);
        exit;
    }
    $error = 'Неверный телефон или PIN-код';
}

?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <title>Вход в Wallet — QR-Restaurant</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    .float-slow { animation: float-slow 18s ease-in-out infinite; }
    .float-slow-2 { animation: float-slow-2 26s ease-in-out infinite; }
    @keyframes float-slow { 0%,100%{transform:translate3d(0,0,0)} 50%{transform:translate3d(16px,-22px,0)} }
    @keyframes float-slow-2 { 0%,100%{transform:translate3d(0,0,0)} 50%{transform:translate3d(-20px,28px,0)} }
  </style>
</head>
<body class="min-h-screen bg-slate-950 text-slate-50 flex items-center justify-center">
  <div class="relative w-full max-w-md px-4">
    <div class="pointer-events-none absolute inset-0 -z-10">
      <div class="absolute -top-40 -left-32 w-80 h-80 bg-emerald-500/25 blur-3xl rounded-full float-slow"></div>
      <div class="absolute bottom-[-9rem] right-[-3rem] w-96 h-96 bg-sky-500/20 blur-3xl rounded-full float-slow-2"></div>
      <div class="absolute top-1/3 right-10 w-60 h-60 bg-fuchsia-500/25 blur-3xl rounded-full opacity-80"></div>
    </div>

    <div class="bg-slate-950/85 border border-slate-800 rounded-3xl shadow-2xl shadow-slate-950/80 p-6 backdrop-blur-xl">
      <div class="flex items-center justify-between mb-4">
        <div class="text-xs text-slate-400 uppercase tracking-wide">Guest Wallet</div>
        <div class="text-[11px] px-2 py-1 rounded-full bg-slate-900 border border-slate-700 text-slate-300">Вход</div>
      </div>

      <h1 class="text-2xl font-semibold mb-1">Ваши карты лояльности</h1>
      <p class="text-sm text-slate-400 mb-5">
        Введите телефон и PIN-код. Если карты ещё нет — официант оформит её в ресторане.
      </p>

      <?php if ($error): ?>
        <div class="mb-4 rounded-2xl border border-rose-500/70 bg-rose-500/10 px-3 py-2 text-xs text-rose-100">
          <?= e($error) ?>
        </div>
      <?php endif; ?>

      <form method="post" class="space-y-3">
        <div>
          <label class="block text-[11px] text-slate-300 mb-1">Телефон</label>
          <input name="phone" type="tel" placeholder="+7 9XX XXX-XX-XX" required
                 class="w-full rounded-2xl bg-slate-900 border border-slate-700 px-3 py-2 text-sm text-slate-50 placeholder:text-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500">
        </div>
        <div>
          <label class="block text-[11px] text-slate-300 mb-1">PIN-код</label>
          <input name="pin" type="password" placeholder="6 цифр" required
                 class="w-full rounded-2xl bg-slate-900 border border-slate-700 px-3 py-2 text-sm text-slate-50 placeholder:text-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500">
        </div>

        <button class="w-full inline-flex items-center justify-center px-4 py-2.5 rounded-2xl bg-emerald-500 hover:bg-emerald-400 text-sm font-semibold text-slate-950 shadow-lg shadow-emerald-500/40 transition">
          Войти
        </button>

        <div class="text-[11px] text-slate-500">
          Нет PIN? Попросите официанта оформить карту — он выдаст PIN для входа.
        </div>
      </form>
    </div>
  </div>
</body>
</html>
