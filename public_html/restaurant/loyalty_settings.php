<?php
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/loyalty_core.php';
if (file_exists(__DIR__ . '/../../app/schema_guard.php')) {
    require_once __DIR__ . '/../../app/schema_guard.php';
}

require_login();
require_current_restaurant();
require_current_restaurant_role(['owner','admin']);

$pdo = db();
$restaurant_id = (int)($currentRestaurant['id'] ?? 0);

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

$s = loyalty_get_settings($pdo, $restaurant_id);
$hasReturnModeCol = function_exists('db_column_exists') && db_column_exists('restaurant_loyalty_settings', 'loyalty_return_mode_enabled');
$returnModeVal = $hasReturnModeCol ? (int)($s['loyalty_return_mode_enabled'] ?? 0) : 0;
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <title>Лояльность</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?= brand_head_tags() ?>

</head>
<body>
  <h2>Лояльность</h2>
  <?php if (!$loyaltyEnabledByPlan): ?>
  <div style="margin:1em 0;padding:1em;border:1px solid #b45309;background:rgba(180,83,9,0.15);border-radius:12px;">
    <p style="font-weight:600;margin:0 0 0.25em 0;">Программа лояльности доступна на тарифе PRO</p>
    <p style="font-size:0.85em;margin:0 0 0.5em 0;opacity:0.9;">Подключите loyalty, чтобы начислять баллы, удерживать гостей и повышать повторные визиты.</p>
    <a href="/owner/billing.php" style="display:inline-block;margin-top:0.5em;padding:0.5em 1em;background:#b45309;color:#fff;border-radius:10px;text-decoration:none;font-weight:500;">Перейти на тариф PRO</a>
  </div>
  <?php endif; ?>
  <?php if (!empty($_GET['saved'])) echo "<p>Сохранено ✅</p>"; ?>

  <form method="post">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
    <label>
      <input type="checkbox" name="enabled" <?= ((int)$s['enabled']===1?'checked':'') ?> <?= $loyaltyEnabledByPlan ? '' : 'disabled' ?>>
      Включено
    </label>

    <br><br>

    <label>
      % бонусов с покупки:
      <input type="number" step="0.01" name="earn_percent" value="<?= htmlspecialchars((string)$s['earn_percent'], ENT_QUOTES, 'UTF-8') ?>" <?= $loyaltyEnabledByPlan ? '' : 'readonly' ?>>
    </label>

    <?php if ($hasReturnModeCol): ?>
    <br><br>
    <label style="display:block;max-width:42rem;">
      <input type="checkbox" name="loyalty_return_mode_enabled" value="1" <?= ($returnModeVal === 1 ? 'checked' : '') ?> <?= ($loyaltyEnabledByPlan && !is_demo_mode()) ? '' : 'disabled' ?>>
      Использовать баллы для возврата гостей
    </label>
    <p style="font-size:0.85em;opacity:0.85;max-width:42rem;margin:0.35em 0 0 0;">
      Если включено, система сможет предлагать бонусные сценарии возврата гостей и компенсации после негативного опыта.
    </p>
    <?php endif; ?>

    <br><br>
    <button type="submit" <?= $loyaltyEnabledByPlan ? '' : 'disabled' ?>>Сохранить</button>
  </form>
</body>
</html>