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
$me = auth_user();

// Soft loyalty gating: block accrual/spend when feature disabled (demo unchanged).
$loyaltyEnabledByPlan = true;
if (!is_demo_mode() && file_exists(__DIR__ . '/../../app/subscription_plans.php')) {
    require_once __DIR__ . '/../../app/subscription_plans.php';
    $loyaltyEnabledByPlan = function_exists('check_feature') && check_feature((int)$currentRestaurant['id'], 'loyalty_enabled');
}

$err = null;
$ok  = null;
$card = null;

$token = trim($_POST['token'] ?? ($_GET['token'] ?? ''));

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (function_exists('is_demo_mode') && is_demo_mode()) {
        $err = 'В демо-режиме начисление и списание отключены.';
    } elseif (!$loyaltyEnabledByPlan) {
        $err = 'Программа лояльности доступна на тарифе PRO. Подключите loyalty в разделе тарифов.';
    } else {
    $csrfOk = isset($_POST['csrf'], $_SESSION['csrf']) && hash_equals((string)$_SESSION['csrf'], (string)$_POST['csrf']);
    if (!$csrfOk) {
        $err = 'Неверный запрос. Обновите страницу и попробуйте снова.';
    } else {
        $action = $_POST['action'];
        $token  = trim($_POST['token'] ?? '');

        $card = guest_get_card_by_token($pdo, $token);
        if (!$card) {
            $err = 'Не удалось прочитать QR/код карты.';
        } else {

            if ((int)$card['restaurant_id'] !== (int)$currentRestaurant['id']) {
                $err = 'Эта карта относится к другому ресторану. Списание/начисление запрещено.';
            } else {
                $guestId = (int)$card['guest_id'];
                $points = (int)($_POST['points'] ?? 0);
                $note = trim($_POST['note'] ?? '');
                $note = $note !== '' ? $note : null;

                $maxPoints = 10000;
                if ($points <= 0) {
                    $err = 'Введите количество баллов (> 0).';
                } elseif ($points > $maxPoints) {
                    $err = 'Слишком большое значение. Максимум ' . $maxPoints . ' баллов за операцию.';
                } else {
                    $points = min($points, $maxPoints);
                    if ($action === 'accrual') {
                        $res = guest_loyalty_add_points($pdo, (int)$currentRestaurant['id'], $guestId, $points, (int)($me['id'] ?? null), null, $note);
                        if (!$res['ok']) $err = $res['error'] ?? 'Ошибка начисления';
                        else $ok = 'Начислено +' . $points . ' баллов. Новый баланс: ' . (int)$res['balance'];
                    } elseif ($action === 'spend') {
                        $res = guest_loyalty_spend_points($pdo, (int)$currentRestaurant['id'], $guestId, $points, (int)($me['id'] ?? null), null, $note);
                        if (!$res['ok']) $err = $res['error'] ?? 'Ошибка списания';
                        else $ok = 'Списано -' . $points . ' баллов. Новый баланс: ' . (int)$res['balance'];
                    } else {
                        $err = 'Неизвестное действие';
                    }
                }

                $card = guest_get_card_by_token($pdo, $token);
            }
        }
    }
    }
} elseif ($token !== '') {
    $card = guest_get_card_by_token($pdo, $token);
    if ($card && (int)$card['restaurant_id'] !== (int)$currentRestaurant['id']) {
        $err = 'Эта карта относится к другому ресторану.';
    }
}

?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <title>Сканировать QR — <?= e($currentRestaurant['name']) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://unpkg.com/html5-qrcode"></script>
</head>
<body class="min-h-screen bg-slate-950 text-slate-50">
  <div class="max-w-4xl mx-auto p-4 space-y-4">
    <div class="flex items-center justify-between">
      <div>
        <div class="text-xs text-slate-400">STAFF</div>
        <h1 class="text-2xl font-semibold">Сканировать QR</h1>
        <div class="text-sm text-slate-400"><?= e($currentRestaurant['name']) ?></div>
      </div>
      <a href="/staff/loyalty.php" class="px-3 py-2 rounded-2xl bg-slate-900 border border-slate-800 text-xs hover:bg-slate-800">← Лояльность</a>
    </div>

    <?php if ($ok): ?>
      <div class="rounded-3xl bg-emerald-500/10 border border-emerald-500/60 px-4 py-3 text-sm text-emerald-100">
        <?= e($ok) ?>
      </div>
    <?php endif; ?>

    <?php if (!$loyaltyEnabledByPlan): ?>
    <div class="rounded-2xl border border-amber-500/50 bg-amber-500/10 px-4 py-3 text-sm text-amber-200 mb-4" role="status">
      <p class="font-medium">Программа лояльности доступна на тарифе PRO</p>
      <p class="text-xs text-amber-200/80 mt-1">Подключите loyalty, чтобы начислять баллы, удерживать гостей и повышать повторные визиты.</p>
      <a href="/owner/billing.php" class="inline-flex items-center mt-3 px-4 py-2 rounded-xl bg-amber-600 hover:bg-amber-500 text-white text-sm font-medium">Перейти на тариф PRO</a>
    </div>
    <?php endif; ?>

    <?php if ($err): ?>
      <div class="rounded-3xl bg-rose-500/10 border border-rose-500/60 px-4 py-3 text-sm text-rose-100">
        <?= e($err) ?>
      </div>
    <?php endif; ?>

    <div class="grid md:grid-cols-2 gap-3">
      <div class="rounded-3xl bg-slate-900/70 border border-slate-800 p-4">
        <div class="text-sm font-semibold mb-2">Камера</div>
        <div id="reader" class="rounded-2xl overflow-hidden border border-slate-800"></div>
        <div class="text-[11px] text-slate-400 mt-2">
          Наведите камеру на QR из Wallet гостя. Код автоматически вставится.
        </div>
      </div>

      <div class="rounded-3xl bg-slate-900/70 border border-slate-800 p-4 space-y-3">
        <div>
          <div class="text-sm font-semibold">Код / Token</div>
          <div class="text-xs text-slate-400">Можно и вручную вставить</div>
        </div>

        <form method="get" class="space-y-2">
          <textarea name="token" class="w-full h-24 rounded-2xl bg-slate-950 border border-slate-700 p-3 text-[11px] font-mono"
                    placeholder="GC1:..."><?= e($token) ?></textarea>
          <button class="w-full px-4 py-2.5 rounded-2xl bg-emerald-500 hover:bg-emerald-400 text-sm font-semibold text-slate-950">
            Открыть карту
          </button>
        </form>

        <?php if ($card && !$err): ?>
          <div class="rounded-3xl bg-slate-950/70 border border-slate-800 p-4">
            <div class="text-sm font-semibold mb-1">Гость</div>
            <div class="text-xs text-slate-400">
              <?= e($card['phone']) ?><?= !empty($card['name']) ? ' · ' . e($card['name']) : '' ?>
            </div>
            <div class="mt-2 text-sm">
              Баланс: <span class="font-semibold text-slate-50"><?= (int)$card['balance'] ?></span> баллов
            </div>

            <?php
            $recentTx = [];
            if (function_exists('guest_loyalty_recent_tx')) {
                $recentTx = guest_loyalty_recent_tx($pdo, (int)$currentRestaurant['id'], (int)$card['guest_id'], 8);
            }
            ?>
            <?php if (!empty($recentTx)): ?>
            <div class="mt-3 pt-3 border-t border-slate-800">
              <div class="text-[11px] text-slate-400 mb-1">Последние операции</div>
              <ul class="space-y-1 max-h-32 overflow-y-auto text-xs">
                <?php foreach ($recentTx as $tx): ?>
                  <li class="flex justify-between gap-2">
                    <span class="text-slate-400"><?= $tx['type'] === 'accrual' ? '+' : '' ?><?= (int)$tx['points'] ?></span>
                    <span class="text-slate-500 truncate"><?= !empty($tx['created_at']) ? e(date('d.m H:i', strtotime($tx['created_at']))) : '—' ?></span>
                  </li>
                <?php endforeach; ?>
              </ul>
            </div>
            <?php endif; ?>

            <div class="mt-3 grid grid-cols-2 gap-2">
              <form method="post" class="space-y-2">
                <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf'] ?? '') ?>">
                <input type="hidden" name="token" value="<?= e($token) ?>">
                <input type="hidden" name="action" value="accrual">
                <label class="block text-[11px] text-slate-400">Начислить</label>
                <input name="points" type="number" min="1" class="w-full rounded-2xl bg-slate-950 border border-slate-700 px-3 py-2 text-sm" placeholder="баллы" <?= $loyaltyEnabledByPlan ? '' : 'readonly' ?>>
                <input name="note" type="text" class="w-full rounded-2xl bg-slate-950 border border-slate-700 px-3 py-2 text-sm" placeholder="комментарий (опц.)" <?= $loyaltyEnabledByPlan ? '' : 'readonly' ?>>
                <button class="w-full px-4 py-2.5 rounded-2xl bg-emerald-500 hover:bg-emerald-400 text-sm font-semibold text-slate-950" <?= $loyaltyEnabledByPlan ? '' : 'disabled' ?>>
                  Начислить
                </button>
              </form>

              <form method="post" class="space-y-2">
                <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf'] ?? '') ?>">
                <input type="hidden" name="token" value="<?= e($token) ?>">
                <input type="hidden" name="action" value="spend">
                <label class="block text-[11px] text-slate-400">Списать</label>
                <input name="points" type="number" min="1" class="w-full rounded-2xl bg-slate-950 border border-slate-700 px-3 py-2 text-sm" placeholder="баллы" <?= $loyaltyEnabledByPlan ? '' : 'readonly' ?>>
                <input name="note" type="text" class="w-full rounded-2xl bg-slate-950 border border-slate-700 px-3 py-2 text-sm" placeholder="комментарий (опц.)" <?= $loyaltyEnabledByPlan ? '' : 'readonly' ?>>
                <button class="w-full px-4 py-2.5 rounded-2xl bg-rose-500 hover:bg-rose-400 text-sm font-semibold text-slate-950" <?= $loyaltyEnabledByPlan ? '' : 'disabled' ?>>
                  Списать
                </button>
              </form>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </div>

  </div>

<script>
  const tokenArea = document.querySelector('textarea[name="token"]');

  function setToken(value){
    if (!value) return;
    if (tokenArea) tokenArea.value = value;
  }

  // html5-qrcode
  const reader = new Html5Qrcode("reader");

  Html5Qrcode.getCameras().then(cameras => {
    if (!cameras || cameras.length === 0) return;
    const camId = cameras[0].id;

    reader.start(
      camId,
      { fps: 12, qrbox: 220 },
      (decodedText) => {
        setToken(decodedText);
        // авто-открытие (по желанию)
        // location.href = '/staff/loyalty_scan.php?token=' + encodeURIComponent(decodedText);
      },
      () => {}
    ).catch(() => {});
  }).catch(() => {});
</script>

</body>
</html>
