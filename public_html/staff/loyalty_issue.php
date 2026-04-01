<?php
require_once __DIR__ . '/../../app/bootstrap.php';

require_login();

if (!$currentRestaurant) {
    http_response_code(404);
    echo "Restaurant context required";
    exit;
}

require_restaurant_role((int)$currentRestaurant['id'], ['owner','admin','staff']);

$pdo = db();

// Soft loyalty gating: block issue when feature disabled (demo unchanged).
$loyaltyEnabledByPlan = true;
if (!is_demo_mode() && file_exists(__DIR__ . '/../../app/subscription_plans.php')) {
    require_once __DIR__ . '/../../app/subscription_plans.php';
    $loyaltyEnabledByPlan = function_exists('check_feature') && check_feature((int)$currentRestaurant['id'], 'loyalty_enabled');
}

$result = null;
$error  = null;

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}


$registerUrl = '/guest/register.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (function_exists('is_demo_mode') && is_demo_mode()) {
        $error = 'В демо-режиме оформление карт отключено.';
    } elseif (!$loyaltyEnabledByPlan) {
        $error = 'Программа лояльности доступна на тарифе PRO. Подключите loyalty в разделе тарифов.';
    } elseif (!(isset($_POST['csrf'], $_SESSION['csrf']) && hash_equals((string)$_SESSION['csrf'], (string)$_POST['csrf']))) {
        $error = 'Неверный запрос. Обновите страницу и попробуйте снова.';
    } else {
        $phone = trim($_POST['phone'] ?? '');
        $name  = trim($_POST['name'] ?? '');
        $name  = $name !== '' ? $name : null;

        $result = guest_issue_card_for_restaurant($pdo, (int)$currentRestaurant['id'], $phone, $name);

        if (!$result || empty($result['ok'])) {
            if (!empty($result['need_register'])) {
                $error = ($result['error'] ?? 'Гость не зарегистрирован.')
                       . ' Попросите гостя отсканировать QR и зарегистрироваться.';
            } else {
                $error = $result['error'] ?? 'Ошибка';
            }
        }
    }
}

?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <title>Оформить карту — <?= e($currentRestaurant['name']) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
</head>
<body class="min-h-screen bg-slate-950 text-slate-50">
  <div class="max-w-3xl mx-auto p-4 space-y-4">

    <div class="flex items-center justify-between">
      <div>
        <div class="text-xs text-slate-400">STAFF</div>
        <h1 class="text-2xl font-semibold">Оформить карту гостя</h1>
        <div class="text-sm text-slate-400"><?= e($currentRestaurant['name']) ?></div>
      </div>
      <div class="flex gap-2">
        <a href="/staff/loyalty.php" class="px-3 py-2 rounded-2xl bg-slate-900 border border-slate-800 text-xs hover:bg-slate-800">← Лояльность</a>
      </div>
    </div>

    <?php if (!$loyaltyEnabledByPlan): ?>
    <div class="rounded-2xl border border-amber-500/50 bg-amber-500/10 px-4 py-3 text-sm text-amber-200 mb-4" role="status">
      <p class="font-medium">Программа лояльности доступна на тарифе PRO</p>
      <p class="text-xs text-amber-200/80 mt-1">Подключите loyalty, чтобы начислять баллы, удерживать гостей и повышать повторные визиты.</p>
      <a href="/owner/billing.php" class="inline-flex items-center mt-3 px-4 py-2 rounded-xl bg-amber-600 hover:bg-amber-500 text-white text-sm font-medium">Перейти на тариф PRO</a>
    </div>
    <?php endif; ?>

    <?php if ($error): ?>
      <div class="rounded-3xl bg-rose-500/10 border border-rose-500/60 px-4 py-3 text-sm text-rose-100">
        <?= e($error) ?>
      </div>
    <?php endif; ?>

    <div class="rounded-3xl bg-slate-900/70 border border-slate-800 p-4">
      <form method="post" class="grid sm:grid-cols-2 gap-3 items-end">
        <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf'] ?? '') ?>">
        <div>
          <label class="block text-xs text-slate-300 mb-1">Телефон гостя</label>
          <input name="phone" type="tel" required placeholder="+7 9XX XXX-XX-XX"
                 value="<?= isset($_POST['phone']) ? e($_POST['phone']) : '' ?>"
                 class="w-full rounded-2xl bg-slate-950 border border-slate-700 px-3 py-2 text-sm text-slate-50" <?= $loyaltyEnabledByPlan ? '' : 'readonly' ?>>
        </div>
        <div>
          <label class="block text-xs text-slate-300 mb-1">Имя (опционально)</label>
          <input name="name" type="text" placeholder="Например: Анна"
                 value="<?= isset($_POST['name']) ? e($_POST['name']) : '' ?>"
                 class="w-full rounded-2xl bg-slate-950 border border-slate-700 px-3 py-2 text-sm text-slate-50" <?= $loyaltyEnabledByPlan ? '' : 'readonly' ?>>
        </div>

        <div class="sm:col-span-2 flex flex-col sm:flex-row gap-2 sm:items-center sm:justify-between">
          <button class="px-4 py-2.5 rounded-2xl bg-emerald-500 hover:bg-emerald-400 text-sm font-semibold text-slate-950" <?= $loyaltyEnabledByPlan ? '' : 'disabled' ?>>
            Оформить / открыть карту
          </button>

          <a href="<?= e($registerUrl) ?>"
             class="text-[11px] text-slate-400 hover:text-slate-200 underline decoration-dotted">
            Гостю нужна регистрация? Открыть /guest/register.php
          </a>
        </div>
      </form>
    </div>

    <?php if ($result && !empty($result['need_register'])): ?>
      <div class="rounded-3xl bg-amber-500/10 border border-amber-500/60 p-4 space-y-3">
        <div class="flex items-start justify-between gap-3">
          <div>
            <div class="text-sm font-semibold">Гость ещё не зарегистрирован</div>
            <div class="text-xs text-slate-400 mt-1">
              Пусть гость отсканирует QR и зарегистрируется в Wallet (PIN придумывает сам).
            </div>
          </div>
          <div class="text-[11px] px-2 py-1 rounded-full bg-slate-950 border border-slate-700 text-slate-200">
            REG
          </div>
        </div>

        <div class="grid sm:grid-cols-2 gap-3">
          <div class="rounded-3xl bg-white p-4 flex items-center justify-center">
            <div id="regQrBox"></div>
          </div>

          <div class="rounded-3xl bg-slate-950/70 border border-slate-800 p-4 space-y-2">
            <div class="text-xs text-slate-400">Ссылка на регистрацию</div>
            <textarea id="regLinkText" readonly class="w-full h-24 rounded-2xl bg-slate-950 border border-slate-700 p-3 text-[11px] font-mono"><?= e($registerUrl) ?></textarea>
            <button type="button" id="copyRegBtn"
                    class="w-full px-4 py-2.5 rounded-2xl bg-slate-900 border border-slate-800 text-xs text-slate-200 hover:bg-slate-800">
              Скопировать ссылку
            </button>
            <div class="text-[11px] text-slate-400">
              После регистрации повторите оформление — карта создастся за 1 секунду.
            </div>
          </div>
        </div>
      </div>

      <script>
        (function(){
          const link = <?= json_encode($registerUrl, JSON_UNESCAPED_UNICODE) ?>;
          const box = document.getElementById('regQrBox');
          if (box) new QRCode(box, { text: link, width: 220, height: 220 });

          const btn = document.getElementById('copyRegBtn');
          if (btn) btn.onclick = async () => {
            try { await navigator.clipboard.writeText(link); btn.textContent = 'Скопировано ✅'; }
            catch(e){ btn.textContent = 'Не удалось скопировать'; }
            setTimeout(()=>btn.textContent='Скопировать ссылку', 1200);
          };
        })();
      </script>
    <?php endif; ?>

    <?php if ($result && !empty($result['ok'])): ?>
      <div class="rounded-3xl bg-emerald-500/10 border border-emerald-500/60 p-4 space-y-3">
        <div class="flex items-start justify-between gap-3">
          <div>
            <div class="text-sm font-semibold">
              Карта готова ✅
            </div>
            <div class="text-xs text-slate-400 mt-1">
              Гость: <?= e($result['guest']['phone']) ?>
              <?php if (!empty($result['guest']['name'])): ?> · <?= e($result['guest']['name']) ?><?php endif; ?>
              · Баланс: <span class="text-slate-50 font-semibold"><?= (int)$result['balance'] ?></span>
            </div>
          </div>
          <div class="text-[11px] font-mono px-2 py-1 rounded-full bg-slate-950 border border-slate-700 text-slate-200">
            <?= e(substr($result['card']['public_uid'], 0, 8)) ?>…
          </div>
        </div>

        <div class="grid sm:grid-cols-2 gap-3">
          <div class="rounded-3xl bg-white p-4 flex items-center justify-center">
            <div id="qrBox"></div>
          </div>

          <div class="rounded-3xl bg-slate-950/70 border border-slate-800 p-4 space-y-2">
            <div class="text-xs text-slate-400">Код карты (можно копировать)</div>
            <textarea id="tokenText" readonly class="w-full h-24 rounded-2xl bg-slate-950 border border-slate-700 p-3 text-[11px] font-mono"><?= e($result['card']['token']) ?></textarea>
            <button type="button" id="copyBtn"
                    class="w-full px-4 py-2.5 rounded-2xl bg-slate-900 border border-slate-800 text-xs text-slate-200 hover:bg-slate-800">
              Скопировать
            </button>
            <div class="text-[11px] text-slate-400">
              Гость покажет QR в Wallet — вы сможете его сканировать в “Сканировать QR”.
            </div>
          </div>
        </div>
      </div>

      <script>
        (function(){
          const token = <?= json_encode($result['card']['token'], JSON_UNESCAPED_UNICODE) ?>;
          const qrBox = document.getElementById('qrBox');
          if (qrBox) new QRCode(qrBox, { text: token, width: 220, height: 220 });

          const btn = document.getElementById('copyBtn');
          if (btn) btn.onclick = async () => {
            try { await navigator.clipboard.writeText(token); btn.textContent = 'Скопировано ✅'; }
            catch(e){ btn.textContent = 'Не удалось скопировать'; }
            setTimeout(()=>btn.textContent='Скопировать', 1200);
          };
        })();
      </script>
    <?php endif; ?>

  </div>
</body>
</html>
