<?php
require_once __DIR__ . '/../../app/bootstrap.php';

$redirect = function_exists('guest_auth_safe_redirect_path')
    ? guest_auth_safe_redirect_path((string)($_GET['redirect'] ?? '/guest/wallet.php'))
    : '/guest/wallet.php';
$mode = trim((string)($_GET['mode'] ?? 'login'));
$isRegisterMode = $mode === 'register';
$cfg = require __DIR__ . '/../../app/config.php';
$hostNoPort = strtolower((string)preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? '')));
$mainDomain = strtolower((string)($cfg['app']['main_domain'] ?? ''));
$isTestHost = $mainDomain !== '' && $hostNoPort === ('test.' . $mainDomain);

$pdo = db();
$currentGuest = function_exists('guest_current') ? guest_current($pdo) : null;
if ($currentGuest) {
    header('Location: ' . $redirect);
    exit;
}

$pageTitle = $isRegisterMode ? 'Регистрация гостя' : 'Вход для гостя';
$pageLead = $isRegisterMode
    ? 'Подтвердите номер по SMS. Аккаунт создастся автоматически и будет работать для Wallet, бонусов и повторных заказов.'
    : 'Подтвердите номер по SMS, чтобы открыть Wallet, бонусы и историю заказов.';
$restaurantName = (string)($currentRestaurant['name'] ?? 'Guest Wallet');
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <title><?= e($pageTitle) ?> — <?= e($restaurantName) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?= brand_head_tags() ?>
  <?php if ($isTestHost): ?>
    <meta name="robots" content="noindex,nofollow,noarchive">
  <?php endif; ?>
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
        <div class="text-xs text-slate-400 uppercase tracking-wide"><?= e($restaurantName) ?></div>
        <div class="text-[11px] px-2 py-1 rounded-full bg-slate-900 border border-slate-700 text-slate-300">
          <?= $isRegisterMode ? 'Регистрация' : 'Вход' ?>
        </div>
      </div>

      <h1 class="text-2xl font-semibold mb-1"><?= e($pageTitle) ?></h1>
      <p class="text-sm text-slate-400 mb-5"><?= e($pageLead) ?></p>

      <div id="step-phone" class="space-y-3">
        <div>
          <label class="block text-[11px] text-slate-300 mb-1">Телефон</label>
          <input id="otp-phone" type="tel" placeholder="+7 9XX XXX-XX-XX" autocomplete="tel"
                 class="w-full rounded-2xl bg-slate-900 border border-slate-700 px-3 py-2.5 text-sm text-slate-50 placeholder:text-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500">
        </div>
        <div id="err-phone" class="hidden rounded-2xl border border-rose-500/70 bg-rose-500/10 px-3 py-2 text-xs text-rose-100"></div>
        <button id="otp-send" type="button"
                class="w-full inline-flex items-center justify-center px-4 py-2.5 rounded-2xl bg-emerald-500 hover:bg-emerald-400 text-sm font-semibold text-slate-950 shadow-lg shadow-emerald-500/40 transition">
          Получить код
        </button>
      </div>

      <div id="step-code" class="space-y-3 hidden">
        <div id="otp-test-box" class="hidden rounded-2xl border border-sky-500/25 bg-sky-500/[0.08] px-3 py-3 space-y-2">
          <div class="text-[11px] font-semibold text-sky-200/90 tracking-wide">Тестовый код</div>
          <p id="otp-test-msg" class="text-xs text-sky-100/80 leading-relaxed"></p>
          <button type="button" id="otp-test-fill"
                  class="hidden w-full rounded-xl bg-slate-800/90 border border-slate-600/80 px-3 py-2 text-xs font-medium text-slate-200 hover:border-sky-500/40">
            Подставить тестовый код
          </button>
        </div>

        <div>
          <label class="block text-[11px] text-slate-300 mb-1">Код из SMS</label>
          <input id="otp-code" type="text" inputmode="numeric" maxlength="6" autocomplete="one-time-code" placeholder="123456"
                 class="w-full rounded-2xl bg-slate-900 border border-slate-700 px-3 py-2.5 text-lg tracking-[0.35em] text-center text-slate-50 placeholder:text-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500">
        </div>
        <div id="err-code" class="hidden rounded-2xl border border-rose-500/70 bg-rose-500/10 px-3 py-2 text-xs text-rose-100"></div>
        <button id="otp-verify" type="button"
                class="w-full inline-flex items-center justify-center px-4 py-2.5 rounded-2xl bg-emerald-500 hover:bg-emerald-400 text-sm font-semibold text-slate-950 shadow-lg shadow-emerald-500/40 transition">
          Подтвердить
        </button>
        <button id="otp-back" type="button"
                class="w-full inline-flex items-center justify-center px-4 py-2.5 rounded-2xl bg-slate-900 border border-slate-700 text-sm text-slate-200 hover:bg-slate-800">
          Изменить номер
        </button>
      </div>

      <div id="step-done" class="space-y-3 hidden">
        <div class="rounded-2xl border border-emerald-500/40 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-100">
          <div class="font-medium">Готово</div>
          <div id="done-msg" class="mt-1 text-xs text-emerald-100/80"></div>
        </div>
        <button id="done-continue" type="button"
                class="w-full inline-flex items-center justify-center px-4 py-2.5 rounded-2xl bg-emerald-500 hover:bg-emerald-400 text-sm font-semibold text-slate-950 shadow-lg shadow-emerald-500/40 transition">
          Продолжить
        </button>
      </div>

      <div class="mt-4 text-[11px] text-slate-500">
        Старый вход по PIN больше не является основным. Один и тот же номер телефона теперь открывает returning guest flow, Wallet и loyalty.
      </div>
    </div>
  </div>

<script>
(function () {
  const redirectTo = <?= json_encode($redirect, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  const phoneInput = document.getElementById('otp-phone');
  const codeInput = document.getElementById('otp-code');
  const stepPhone = document.getElementById('step-phone');
  const stepCode = document.getElementById('step-code');
  const stepDone = document.getElementById('step-done');
  const errPhone = document.getElementById('err-phone');
  const errCode = document.getElementById('err-code');
  const sendBtn = document.getElementById('otp-send');
  const verifyBtn = document.getElementById('otp-verify');
  const backBtn = document.getElementById('otp-back');
  const doneBtn = document.getElementById('done-continue');
  const doneMsg = document.getElementById('done-msg');
  const testBox = document.getElementById('otp-test-box');
  const testMsg = document.getElementById('otp-test-msg');
  const testFill = document.getElementById('otp-test-fill');

  let lastPhone = '';
  let lastTestCode = '';

  function showErr(el, msg) {
    if (!el) return;
    if (!msg) {
      el.classList.add('hidden');
      el.textContent = '';
      return;
    }
    el.textContent = msg;
    el.classList.remove('hidden');
  }

  function resetTestUi() {
    lastTestCode = '';
    if (testBox) testBox.classList.add('hidden');
    if (testMsg) testMsg.textContent = '';
    if (testFill) testFill.classList.add('hidden');
  }

  function mapErr(code) {
    const map = {
      invalid_phone: 'Проверьте формат номера',
      invalid_input: 'Введите номер и код',
      invalid_code: 'Код должен состоять из 6 цифр',
      rate_limited: 'Подождите немного перед повторной отправкой',
      service_unavailable: 'Сервис временно недоступен',
      internal_error: 'Временная ошибка сервиса. Попробуйте ещё раз',
      no_code: 'Сначала запросите код',
      expired: 'Код истёк — запросите новый',
      wrong_code: 'Неверный код',
      too_many_attempts: 'Слишком много попыток — запросите новый код',
      used: 'Этот код уже использован — запросите новый'
    };
    return map[code] || 'Не получилось. Попробуйте ещё раз.';
  }

  function goStep(step) {
    stepPhone.classList.toggle('hidden', step !== 'phone');
    stepCode.classList.toggle('hidden', step !== 'code');
    stepDone.classList.toggle('hidden', step !== 'done');
  }

  if (sendBtn) sendBtn.addEventListener('click', async function () {
    showErr(errPhone, '');
    showErr(errCode, '');
    lastPhone = phoneInput ? phoneInput.value.trim() : '';
    sendBtn.disabled = true;
    try {
      const res = await fetch('/guest/send_otp.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify({ phone: lastPhone })
      });
      const data = await res.json().catch(function () { return {}; });
      if (!res.ok || !data.success) {
        showErr(errPhone, mapErr(data.error));
        return;
      }
      resetTestUi();
      if (data.test_mode === true && data.test_code) {
        lastTestCode = String(data.test_code);
        if (testMsg) testMsg.textContent = 'Для тестирования используйте код: ' + data.test_code;
        if (testBox) testBox.classList.remove('hidden');
        if (testFill) testFill.classList.remove('hidden');
      }
      goStep('code');
      if (codeInput) codeInput.focus();
    } catch (e) {
      showErr(errPhone, 'Нет соединения. Попробуйте снова.');
    } finally {
      sendBtn.disabled = false;
    }
  });

  if (verifyBtn) verifyBtn.addEventListener('click', async function () {
    showErr(errCode, '');
    verifyBtn.disabled = true;
    try {
      const res = await fetch('/guest/verify_otp.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify({
          phone: lastPhone || (phoneInput ? phoneInput.value.trim() : ''),
          code: codeInput ? codeInput.value.trim() : ''
        })
      });
      const data = await res.json().catch(function () { return {}; });
      if (!res.ok || !data.success) {
        showErr(errCode, mapErr(data.error));
        return;
      }

      resetTestUi();
      if (doneMsg) {
        const bonus = parseInt(data.welcome_bonus || 0, 10) || 0;
        const phone = data.guest && data.guest.phone ? String(data.guest.phone) : '';
        doneMsg.textContent = bonus > 0
          ? ('Номер подтверждён. Начислено ' + bonus + ' бонусов' + (phone ? ' · ' + phone : ''))
          : ('Номер подтверждён' + (phone ? ' · ' + phone : ''));
      }
      goStep('done');
    } catch (e) {
      showErr(errCode, 'Нет соединения. Попробуйте снова.');
    } finally {
      verifyBtn.disabled = false;
    }
  });

  if (backBtn) backBtn.addEventListener('click', function () {
    showErr(errCode, '');
    resetTestUi();
    goStep('phone');
    if (phoneInput) phoneInput.focus();
  });

  if (doneBtn) doneBtn.addEventListener('click', function () {
    window.location.href = redirectTo;
  });

  if (testFill) testFill.addEventListener('click', function () {
    if (!codeInput || !lastTestCode) return;
    codeInput.value = lastTestCode;
    codeInput.focus();
  });
})();
</script>
</body>
</html>
