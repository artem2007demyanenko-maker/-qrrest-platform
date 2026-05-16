<?php
require_once __DIR__ . '/../../app/bootstrap.php';
if (file_exists(__DIR__ . '/../../app/billing.php')) {
    require_once __DIR__ . '/../../app/billing.php';
}
require_once __DIR__ . '/../../app/guest_loyalty.php';
if (file_exists(__DIR__ . '/../../app/schema_guard.php')) {
    require_once __DIR__ . '/../../app/schema_guard.php';
}

require_login();
require_current_restaurant();
require_current_restaurant_role(['owner','admin']);

$pdo = db();
$restaurant_id = (int)($currentRestaurant['id'] ?? 0);
$loyaltySchemaReady = true;
$loyaltySchemaWarning = null;
if (function_exists('db_table_exists') && !db_table_exists('restaurant_loyalty_settings')) {
    $loyaltySchemaReady = false;
    $loyaltySchemaWarning = 'Таблица настроек лояльности ещё не создана. Раздел открыт только для просмотра — примените миграции, чтобы сохранять изменения.';
}
$authUser = auth_user();
$loyaltyPaywallContext = function_exists('billing_get_feature_paywall_context')
    ? billing_get_feature_paywall_context((int)($authUser['id'] ?? 0), $restaurant_id, 'loyalty')
    : null;

// Soft loyalty gating: allow read-only; block save when feature disabled (demo unchanged).
$loyaltyEnabledByPlan = true;
if (!is_demo_mode() && file_exists(__DIR__ . '/../../app/subscription_plans.php')) {
    require_once __DIR__ . '/../../app/subscription_plans.php';
    $loyaltyEnabledByPlan = function_exists('check_feature') && check_feature($restaurant_id, 'loyalty_enabled');
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (function_exists('is_demo_mode') && is_demo_mode()) {
        http_response_code(403);
        echo 'В демо-режиме сохранение настроек отключено.';
        exit;
    }
    if (!$loyaltyEnabledByPlan) {
        http_response_code(403);
        echo 'Программа лояльности доступна на тарифе PRO. Подключите loyalty в разделе тарифов.';
        exit;
    }
    if (!$loyaltySchemaReady) {
        http_response_code(409);
        echo 'Невозможно сохранить: схема БД лояльности не готова.';
        exit;
    }
    $csrfOk = isset($_POST['csrf'], $_SESSION['csrf']) && hash_equals((string)$_SESSION['csrf'], (string)$_POST['csrf']);
    if (!$csrfOk) {
        http_response_code(400);
        echo 'Неверный запрос. Обновите страницу и попробуйте снова.';
        exit;
    }

    $enabled = isset($_POST['enabled']) ? 1 : 0;
    $earn = (float)($_POST['earn_percent'] ?? 0);
    $returnModeEnabled = isset($_POST['loyalty_return_mode_enabled']) ? 1 : 0;

    $hasReturnModeCol = function_exists('db_column_exists') && db_column_exists('restaurant_loyalty_settings', 'loyalty_return_mode_enabled');
    if ($hasReturnModeCol) {
        $st = $pdo->prepare("
            INSERT INTO restaurant_loyalty_settings (restaurant_id, earn_percent, enabled, loyalty_return_mode_enabled)
            VALUES (?,?,?,?)
            ON DUPLICATE KEY UPDATE
                earn_percent = VALUES(earn_percent),
                enabled = VALUES(enabled),
                loyalty_return_mode_enabled = VALUES(loyalty_return_mode_enabled)
        ");
        $st->execute([$restaurant_id, $earn, $enabled, $returnModeEnabled]);
    } else {
        $st = $pdo->prepare("
            INSERT INTO restaurant_loyalty_settings (restaurant_id, earn_percent, enabled)
            VALUES (?,?,?)
            ON DUPLICATE KEY UPDATE earn_percent=VALUES(earn_percent), enabled=VALUES(enabled)
        ");
        $st->execute([$restaurant_id, $earn, $enabled]);
    }

    header("Location: loyalty_settings.php?saved=1");
    exit;
}

$s = $loyaltySchemaReady
    ? loyalty_get_settings($pdo, $restaurant_id)
    : ['enabled' => 0, 'earn_percent' => 0, 'loyalty_return_mode_enabled' => 0];
$hasReturnModeCol = function_exists('db_column_exists') && db_column_exists('restaurant_loyalty_settings', 'loyalty_return_mode_enabled');
$returnModeVal = $hasReturnModeCol ? (int)($s['loyalty_return_mode_enabled'] ?? 0) : 0;
$manualActivity = function_exists('guest_loyalty_manual_activity_feed')
    ? guest_loyalty_manual_activity_feed($pdo, $restaurant_id, 12)
    : [];
$manualSummary = function_exists('guest_loyalty_manual_activity_summary')
    ? guest_loyalty_manual_activity_summary($pdo, $restaurant_id, 7)
    : ['window_days' => 7, 'accrual_count' => 0, 'spend_count' => 0, 'accrual_points' => 0, 'spend_points' => 0];
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <title>Лояльность — <?= htmlspecialchars((string)($currentRestaurant['name'] ?? 'Ресторан'), ENT_QUOTES, 'UTF-8') ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?= brand_head_tags() ?>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-950 text-slate-50 flex overflow-x-hidden">
<?php
$restaurantSidebarActive = 'loyalty_settings';
$restaurantSidebarName = (string)($currentRestaurant['name'] ?? 'Ресторан');
require __DIR__ . '/_sidebar_mobile.php';
?>
<?php require __DIR__ . '/_sidebar.php'; ?>

<main class="flex-1 min-w-0 p-4 md:p-6 overflow-x-hidden">
  <div class="max-w-6xl mx-auto space-y-4">
    <?php
    $businessNavActive = 'loyalty_settings';
    require __DIR__ . '/_restaurant_cabinet_context.php';
    require __DIR__ . '/_restaurant_business_nav.php';
    ?>
  <div class="max-w-2xl space-y-4">
  <header>
    <h2 class="text-2xl font-bold text-slate-50">Лояльность и бонусы</h2>
    <p class="text-xs text-slate-500 mt-1">Настройки бонусной программы и короткий owner-журнал ручных операций staff.</p>
  </header>
  <?php if ($loyaltySchemaWarning !== null): ?>
  <div class="rounded-2xl border border-amber-500/50 bg-amber-500/10 px-4 py-4 text-sm text-amber-200" role="status">
    <?= e($loyaltySchemaWarning) ?>
  </div>
  <?php endif; ?>
  <?php if (!$loyaltyEnabledByPlan): ?>
  <?php $ctx = is_array($loyaltyPaywallContext) ? $loyaltyPaywallContext : []; ?>
  <div class="rounded-2xl border border-amber-500/50 bg-amber-500/10 px-4 py-4 text-sm text-amber-200" role="status">
    <div class="inline-flex items-center rounded-full border border-white/10 bg-white/5 px-3 py-1 text-[11px] font-semibold uppercase tracking-wide"><?= e((string)($ctx['phase_label'] ?? 'Следующий шаг')) ?></div>
    <p class="font-medium mt-3"><?= e((string)($ctx['title'] ?? 'Лояльность и бонусы')) ?></p>
    <p class="text-xs text-amber-200/80 mt-1"><?= e((string)($ctx['subtitle'] ?? 'Подключите loyalty, чтобы бонусы и retention работали как единая система.')) ?></p>
    <ul class="mt-3 space-y-2 text-xs text-amber-100/90">
      <?php foreach (array_slice((array)($ctx['benefits'] ?? []), 0, 3) as $benefit): ?>
        <li class="flex items-start gap-2"><span class="mt-1">•</span><span><?= e((string)$benefit) ?></span></li>
      <?php endforeach; ?>
    </ul>
    <p class="text-[11px] text-amber-100/70 mt-3"><?= e((string)($ctx['preservation_text'] ?? '')) ?></p>
    <a href="<?= e((string)($ctx['cta_url'] ?? '/restaurant/activate.php?plan=pro')) ?>" class="inline-flex items-center mt-3 px-4 py-2 rounded-xl bg-amber-600 hover:bg-amber-500 text-white text-sm font-medium"><?= e((string)($ctx['cta_label'] ?? 'Открыть тариф PRO')) ?></a>
  </div>
  <?php endif; ?>
  <?php if (!empty($_GET['saved'])): ?>
  <div class="rounded-xl bg-emerald-500/10 border border-emerald-500/40 px-4 py-3 text-sm text-emerald-200">Сохранено</div>
  <?php endif; ?>

  <form method="post" class="rounded-2xl border border-slate-800 bg-slate-900/70 p-5 space-y-4">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
    <label class="flex items-center gap-2 text-sm text-slate-200">
      <input type="checkbox" name="enabled" <?= ((int)$s['enabled']===1?'checked':'') ?> <?= ($loyaltyEnabledByPlan && $loyaltySchemaReady) ? '' : 'disabled' ?>>
      Включено
    </label>
    <label class="block text-sm text-slate-300">
      % бонусов с покупки:
      <input type="number" step="0.01" name="earn_percent" value="<?= htmlspecialchars((string)$s['earn_percent'], ENT_QUOTES, 'UTF-8') ?>" <?= ($loyaltyEnabledByPlan && $loyaltySchemaReady) ? '' : 'readonly' ?> class="mt-1 w-full max-w-[240px] rounded-xl bg-slate-950 border border-slate-700 px-3 py-2 text-sm text-slate-100">
    </label>

    <?php if ($hasReturnModeCol): ?>
    <label class="block max-w-[42rem] text-sm text-slate-200">
      <input type="checkbox" name="loyalty_return_mode_enabled" value="1" <?= ($returnModeVal === 1 ? 'checked' : '') ?> <?= ($loyaltyEnabledByPlan && $loyaltySchemaReady && !is_demo_mode()) ? '' : 'disabled' ?>>
      Использовать баллы для возврата гостей
    </label>
    <p class="text-xs text-slate-500 max-w-[42rem]">
      Если включено, система сможет предлагать бонусные сценарии возврата гостей и компенсации после негативного опыта.
    </p>
    <?php endif; ?>
    <button type="submit" <?= ($loyaltyEnabledByPlan && $loyaltySchemaReady) ? '' : 'disabled' ?> class="inline-flex items-center px-4 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-sm disabled:opacity-40 disabled:cursor-not-allowed">Сохранить</button>
  </form>

  <section class="rounded-2xl border border-slate-800 bg-slate-900/70 p-5 space-y-4">
    <div class="flex items-start justify-between gap-3">
      <div>
        <h3 class="text-lg font-semibold text-slate-100">Журнал ручных loyalty-операций</h3>
        <p class="text-xs text-slate-500 mt-1">Показывает только ручные начисления и списания, которые staff провёл через loyalty tool. Автоматические guest QR-операции сюда не попадают.</p>
      </div>
      <div class="text-[11px] px-2 py-1 rounded-full bg-slate-950 border border-slate-700 text-slate-300">
        последние <?= (int)$manualSummary['window_days'] ?> дней
      </div>
    </div>

    <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-3">
      <div class="rounded-xl bg-slate-950 border border-slate-800 px-3 py-3">
        <div class="text-[11px] text-slate-500">Ручных начислений</div>
        <div class="text-xl font-semibold text-emerald-300 mt-1"><?= (int)$manualSummary['accrual_count'] ?></div>
        <div class="text-[11px] text-slate-500 mt-1">+<?= (int)$manualSummary['accrual_points'] ?> бонусов</div>
      </div>
      <div class="rounded-xl bg-slate-950 border border-slate-800 px-3 py-3">
        <div class="text-[11px] text-slate-500">Ручных списаний</div>
        <div class="text-xl font-semibold text-rose-300 mt-1"><?= (int)$manualSummary['spend_count'] ?></div>
        <div class="text-[11px] text-slate-500 mt-1">−<?= (int)$manualSummary['spend_points'] ?> бонусов</div>
      </div>
      <div class="rounded-xl bg-slate-950 border border-slate-800 px-3 py-3">
        <div class="text-[11px] text-slate-500">Последних записей</div>
        <div class="text-xl font-semibold text-slate-100 mt-1"><?= count($manualActivity) ?></div>
        <div class="text-[11px] text-slate-500 mt-1">в журнале ниже</div>
      </div>
      <div class="rounded-xl bg-slate-950 border border-slate-800 px-3 py-3">
        <div class="text-[11px] text-slate-500">Фокус журнала</div>
        <div class="text-sm font-semibold text-slate-100 mt-1">Кто, когда и по какому заказу</div>
        <div class="text-[11px] text-slate-500 mt-1">без тяжёлой аналитики</div>
      </div>
    </div>

    <?php if ($manualActivity === []): ?>
      <div class="rounded-xl bg-slate-950 border border-dashed border-slate-700 px-4 py-4 text-sm text-slate-400">
        Пока нет ручных loyalty-операций staff. Когда официанты или администраторы начнут вручную начислять или списывать бонусы, последние действия появятся здесь.
      </div>
    <?php else: ?>
      <div class="space-y-2">
        <?php foreach ($manualActivity as $row): ?>
          <?php
            $type = (string)($row['type'] ?? '');
            $points = (int)($row['points'] ?? 0);
            $actor = trim((string)($row['actor_name'] ?? ''));
            $actorLabel = $actor !== '' ? $actor : (((int)($row['staff_user_id'] ?? 0) > 0) ? ('staff #' . (int)$row['staff_user_id']) : 'staff');
            $guestPhone = trim((string)($row['guest_phone'] ?? ''));
            $guestName = trim((string)($row['guest_name'] ?? ''));
            $note = trim((string)($row['note'] ?? ''));
            $createdAt = !empty($row['created_at']) ? date('d.m.Y H:i', strtotime((string)$row['created_at'])) : '—';
            $orderId = (int)($row['order_id'] ?? 0);
            $label = $type === 'spend' ? 'Списание' : 'Начисление';
            $valueClass = $type === 'spend' ? 'text-rose-300' : 'text-emerald-300';
            $valueText = ($type === 'accrual' ? '+' : '') . $points;
          ?>
          <div class="rounded-xl bg-slate-950 border border-slate-800 px-4 py-3">
            <div class="flex items-start justify-between gap-4">
              <div class="min-w-0">
                <div class="text-sm font-medium text-slate-100">
                  <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                  <?php if ($guestPhone !== ''): ?>
                    <span class="text-slate-500 font-normal">· <?= htmlspecialchars($guestPhone, ENT_QUOTES, 'UTF-8') ?><?php if ($guestName !== ''): ?> · <?= htmlspecialchars($guestName, ENT_QUOTES, 'UTF-8') ?><?php endif; ?></span>
                  <?php endif; ?>
                </div>
                <div class="text-[11px] text-slate-500 mt-1">
                  <?= htmlspecialchars($actorLabel, ENT_QUOTES, 'UTF-8') ?>
                  <?php if ($orderId > 0): ?> · заказ #<?= $orderId ?><?php endif; ?>
                  · <?= htmlspecialchars($createdAt, ENT_QUOTES, 'UTF-8') ?>
                </div>
                <?php if ($note !== ''): ?>
                  <div class="text-xs text-slate-400 mt-2"><?= htmlspecialchars($note, ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
              </div>
              <div class="text-sm font-semibold whitespace-nowrap <?= htmlspecialchars($valueClass, ENT_QUOTES, 'UTF-8') ?>">
                <?= htmlspecialchars($valueText, ENT_QUOTES, 'UTF-8') ?>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>
  </div>
  </div>
</main>
</body>
</html>
