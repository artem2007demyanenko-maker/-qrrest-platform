<?php
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/guest_otp.php';

require_staff_login();
if (!(function_exists('is_project_owner') && is_project_owner())) {
    $rid = (int)($currentRestaurant['id'] ?? 0);
    $role = function_exists('current_user_restaurant_role') ? current_user_restaurant_role($rid) : null;
    if (!in_array((string)$role, ['owner', 'admin', 'waiter', 'staff'], true)) {
        http_response_code(403);
        echo 'Access denied';
        exit;
    }
}

$pdo = db();

$loyaltyEnabledByPlan = true;
if (!is_demo_mode() && file_exists(__DIR__ . '/../../app/subscription_plans.php')) {
    require_once __DIR__ . '/../../app/subscription_plans.php';
    $loyaltyEnabledByPlan = function_exists('check_feature') && check_feature((int)$currentRestaurant['id'], 'loyalty_enabled');
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

function staff_loyalty_issue_order_context(PDO $pdo, int $restaurantId, int $orderId): ?array {
    if ($orderId <= 0) {
        return null;
    }
    if (file_exists(__DIR__ . '/../../app/schema_guard.php')) {
        require_once __DIR__ . '/../../app/schema_guard.php';
    }

    $hasGuestId = function_exists('db_column_exists') ? db_column_exists('orders', 'guest_id') : false;
    $hasGuestCardId = function_exists('db_column_exists') ? db_column_exists('orders', 'guest_card_id') : false;
    $hasLoyaltyPhone = function_exists('db_column_exists') ? db_column_exists('orders', 'loyalty_phone') : false;

    $guestIdSql = $hasGuestId ? 'o.guest_id AS guest_id,' : 'NULL AS guest_id,';
    $guestCardIdSql = $hasGuestCardId ? 'o.guest_card_id AS guest_card_id,' : 'NULL AS guest_card_id,';
    $loyaltyPhoneSql = $hasLoyaltyPhone ? 'o.loyalty_phone AS loyalty_phone,' : 'NULL AS loyalty_phone,';
    $guestJoinSql = $hasGuestId ? 'LEFT JOIN guests g ON g.id = o.guest_id' : '';

    try {
        $stmt = $pdo->prepare("
            SELECT
                o.id,
                o.total_price,
                o.payment_status,
                o.order_status,
                {$guestIdSql}
                {$guestCardIdSql}
                {$loyaltyPhoneSql}
                g.phone AS guest_phone,
                g.name AS guest_name,
                t.name AS table_name
            FROM orders o
            LEFT JOIN tables t ON t.id = o.table_id
            {$guestJoinSql}
            WHERE o.id = :oid
              AND o.restaurant_id = :rid
            LIMIT 1
        ");
        $stmt->execute([
            ':oid' => $orderId,
            ':rid' => $restaurantId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function staff_loyalty_issue_update_guest_name(PDO $pdo, int $guestId, ?string $name): void {
    $name = $name !== null ? trim($name) : null;
    if ($guestId <= 0 || $name === null || $name === '') {
        return;
    }

    try {
        $stmt = $pdo->prepare("UPDATE guests SET name = CASE WHEN name IS NULL OR name = '' THEN :name ELSE name END WHERE id = :id");
        $stmt->execute([
            ':id' => $guestId,
            ':name' => $name,
        ]);
    } catch (Throwable $e) {
        // best effort only
    }
}

function staff_loyalty_issue_attach_order_context(PDO $pdo, int $restaurantId, int $orderId, array $issueResult): void {
    if ($restaurantId <= 0 || $orderId <= 0) {
        return;
    }
    if (file_exists(__DIR__ . '/../../app/schema_guard.php')) {
        require_once __DIR__ . '/../../app/schema_guard.php';
    }
    $sets = [];
    $params = [
        ':oid' => $orderId,
        ':rid' => $restaurantId,
    ];

    if (function_exists('db_column_exists') && db_column_exists('orders', 'guest_id') && !empty($issueResult['guest']['id'])) {
        $sets[] = 'guest_id = :guest_id';
        $params[':guest_id'] = (int)$issueResult['guest']['id'];
    }
    if (function_exists('db_column_exists') && db_column_exists('orders', 'guest_card_id') && !empty($issueResult['card']['id'])) {
        $sets[] = 'guest_card_id = :guest_card_id';
        $params[':guest_card_id'] = (int)$issueResult['card']['id'];
    }
    if (function_exists('db_column_exists') && db_column_exists('orders', 'loyalty_phone') && !empty($issueResult['guest']['phone'])) {
        $sets[] = 'loyalty_phone = :loyalty_phone';
        $params[':loyalty_phone'] = (string)$issueResult['guest']['phone'];
    }

    if ($sets === []) {
        return;
    }

    try {
        $stmt = $pdo->prepare('UPDATE orders SET ' . implode(', ', $sets) . ' WHERE id = :oid AND restaurant_id = :rid');
        $stmt->execute($params);
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('staff_loyalty_issue_attach_order_context ' . $e->getMessage());
        }
    }
}

function staff_loyalty_issue_after_verified_phone(PDO $pdo, array $restaurant, string $phoneNorm, ?string $name = null): array {
    $restaurantId = (int)($restaurant['id'] ?? 0);
    $guest = function_exists('guest_find_by_phone') ? guest_find_by_phone($pdo, $phoneNorm) : null;
    $isNew = !$guest;

    if (!$guest && function_exists('guest_loyalty_find_or_create_guest_by_phone')) {
        $guest = guest_loyalty_find_or_create_guest_by_phone($pdo, $phoneNorm, $name);
    }

    if (!$guest || empty($guest['id'])) {
        return ['ok' => false, 'error' => 'guest_create_failed'];
    }

    $guestId = (int)$guest['id'];
    staff_loyalty_issue_update_guest_name($pdo, $guestId, $name);
    $guest = function_exists('guest_find_by_phone') ? guest_find_by_phone($pdo, $phoneNorm) : $guest;

    $issue = staff_loyalty_issue_issue_card_live($pdo, $restaurantId, $phoneNorm, $name);
    if (!$issue || empty($issue['ok'])) {
        return $issue ?: ['ok' => false, 'error' => 'issue_failed'];
    }

    $welcomeBonus = 0;
    $loyaltyOk = true;
    if ($restaurantId > 0 && $isNew) {
        $welcomeBonus = 50;
        if (file_exists(__DIR__ . '/../../app/subscription_plans.php')) {
            require_once __DIR__ . '/../../app/subscription_plans.php';
            if (function_exists('check_feature') && !check_feature($restaurantId, 'loyalty_enabled')) {
                $loyaltyOk = false;
            }
        }
        if (function_exists('restaurant_loyalty_enabled')) {
            $loyaltyOk = $loyaltyOk && restaurant_loyalty_enabled($restaurant);
        }
    }

    if ($welcomeBonus > 0 && $loyaltyOk && function_exists('guest_loyalty_add_points')) {
        try {
            guest_loyalty_add_points($pdo, $restaurantId, $guestId, $welcomeBonus, null, null, 'Приветственный бонус');
            $issue['balance'] = (int)($issue['balance'] ?? 0) + $welcomeBonus;
            $issue['welcome_bonus'] = $welcomeBonus;
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('staff_loyalty_issue welcome_bonus ' . $e->getMessage());
            }
        }
    }

    $issue['verified_phone'] = true;
    return $issue;
}

function staff_loyalty_issue_issue_card_live(PDO $pdo, int $restaurantId, string $phoneRaw, ?string $name = null): array {
    $phoneNorm = function_exists('guest_phone_normalize') ? guest_phone_normalize($phoneRaw) : null;
    if (!$phoneNorm) {
        return ['ok' => false, 'error' => 'Некорректный телефон'];
    }

    $guest = function_exists('guest_find_by_phone') ? guest_find_by_phone($pdo, $phoneNorm) : null;
    if (!$guest || empty($guest['id'])) {
        return [
            'ok' => false,
            'error' => 'Гость ещё не зарегистрирован. Отправьте OTP и подтвердите номер.',
            'need_register' => true,
            'phone' => $phoneNorm,
        ];
    }

    $guestId = (int)$guest['id'];
    staff_loyalty_issue_update_guest_name($pdo, $guestId, $name);

    try {
        if ($pdo->inTransaction()) {
            $ownsTx = false;
        } else {
            $pdo->beginTransaction();
            $ownsTx = true;
        }

        $cardStmt = $pdo->prepare("
            SELECT id, guest_id, restaurant_id, card_uid
            FROM guest_cards
            WHERE guest_id = :gid AND restaurant_id = :rid
            LIMIT 1
        ");
        $cardStmt->execute([
            ':gid' => $guestId,
            ':rid' => $restaurantId,
        ]);
        $card = $cardStmt->fetch(PDO::FETCH_ASSOC);

        if (!$card) {
            $uid = bin2hex(random_bytes(16));
            $insCard = $pdo->prepare("
                INSERT INTO guest_cards (guest_id, restaurant_id, card_uid)
                VALUES (:gid, :rid, :uid)
            ");
            $insCard->execute([
                ':gid' => $guestId,
                ':rid' => $restaurantId,
                ':uid' => $uid,
            ]);
            $cardId = (int)$pdo->lastInsertId();
            $card = [
                'id' => $cardId,
                'guest_id' => $guestId,
                'restaurant_id' => $restaurantId,
                'card_uid' => $uid,
            ];
        }

        $cardId = (int)($card['id'] ?? 0);
        if ($cardId <= 0) {
            if ($ownsTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['ok' => false, 'error' => 'Не удалось оформить карту. Попробуйте ещё раз.'];
        }

        $accSel = $pdo->prepare("SELECT id, balance FROM loyalty_accounts WHERE card_id = :cid LIMIT 1");
        $accSel->execute([':cid' => $cardId]);
        $acc = $accSel->fetch(PDO::FETCH_ASSOC);
        if (!$acc) {
            $insAcc = $pdo->prepare("INSERT INTO loyalty_accounts (card_id, balance, updated_at) VALUES (:cid, 0, NOW())");
            $insAcc->execute([':cid' => $cardId]);
            $balance = 0;
        } else {
            $balance = (int)($acc['balance'] ?? 0);
        }

        if ($ownsTx && $pdo->inTransaction()) {
            $pdo->commit();
        }

        $freshGuest = function_exists('guest_find_by_phone') ? guest_find_by_phone($pdo, $phoneNorm) : $guest;
        $guestName = (string)($freshGuest['name'] ?? $guest['name'] ?? '');
        $token = function_exists('guest_card_make_token')
            ? guest_card_make_token((string)($card['card_uid'] ?? ''))
            : ('GC1:' . (string)($card['card_uid'] ?? ''));

        return [
            'ok' => true,
            'guest' => [
                'id' => $guestId,
                'phone' => (string)$phoneNorm,
                'name' => $guestName !== '' ? $guestName : null,
            ],
            'card' => [
                'id' => $cardId,
                'public_uid' => (string)($card['card_uid'] ?? ''),
                'token' => $token,
            ],
            'balance' => $balance,
        ];
    } catch (Throwable $e) {
        if (isset($ownsTx) && $ownsTx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (function_exists('error_log')) {
            error_log('staff_loyalty_issue_issue_card_live ' . $e->getMessage());
        }
        return ['ok' => false, 'error' => 'Не удалось оформить карту. Попробуйте ещё раз.'];
    }
}

$result = null;
$error = null;
$otpInfo = null;
$otpSent = false;
$verifiedByOtp = false;

$orderId = isset($_POST['order_id']) ? (int)$_POST['order_id'] : (isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0);
$orderContext = $orderId > 0 ? staff_loyalty_issue_order_context($pdo, (int)$currentRestaurant['id'], $orderId) : null;

$returnDefault = $orderId > 0 ? ('/staff/loyalty_scan.php?order_id=' . $orderId) : '/staff/loyalty.php';
$returnRaw = (string)($_POST['return_to'] ?? ($_GET['return_to'] ?? $returnDefault));
$returnTo = function_exists('guest_auth_safe_redirect_path')
    ? guest_auth_safe_redirect_path($returnRaw, $returnDefault)
    : $returnDefault;

$phoneInput = trim((string)($_POST['phone'] ?? ($_GET['phone'] ?? '')));
$nameInput = trim((string)($_POST['name'] ?? ($_GET['name'] ?? '')));
$otpCodeInput = trim((string)($_POST['otp_code'] ?? ''));

if ($orderContext) {
    if ($phoneInput === '') {
        $phoneInput = trim((string)($orderContext['loyalty_phone'] ?? $orderContext['guest_phone'] ?? ''));
    }
    if ($nameInput === '') {
        $nameInput = trim((string)($orderContext['guest_name'] ?? ''));
    }
}

$registerUrl = '/guest/login.php?mode=register&redirect=' . urlencode($returnTo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (function_exists('is_demo_mode') && is_demo_mode()) {
        $error = 'В демо-режиме оформление карт отключено.';
    } elseif (!$loyaltyEnabledByPlan) {
        $error = 'Программа лояльности доступна на тарифе PRO. Подключите loyalty в разделе тарифов.';
    } elseif (!(isset($_POST['csrf'], $_SESSION['csrf']) && hash_equals((string)$_SESSION['csrf'], (string)$_POST['csrf']))) {
        $error = 'Неверный запрос. Обновите страницу и попробуйте снова.';
    } else {
        $action = (string)($_POST['action'] ?? 'issue');
        $phoneNorm = function_exists('guest_phone_normalize') ? guest_phone_normalize($phoneInput) : null;
        $name = $nameInput !== '' ? $nameInput : null;

        if ($action === 'send_otp') {
            if (!$phoneNorm) {
                $error = 'Некорректный телефон';
            } else {
                $send = guest_otp_send_for_phone($pdo, (int)$currentRestaurant['id'], $phoneNorm);
                if (!$send['ok']) {
                    $error = $send['error'] ?? 'Ошибка отправки кода';
                } else {
                    $otpSent = true;
                    $otpInfo = [];
                    if (!empty($send['test_code']) && function_exists('guest_otp_test_mode') && guest_otp_test_mode()) {
                        $otpInfo['test_code'] = (string)$send['test_code'];
                    }
                }
            }
        } elseif ($action === 'verify_otp') {
            if (!$phoneNorm || $otpCodeInput === '') {
                $error = 'Введите телефон и код подтверждения.';
            } else {
                $verify = guest_otp_verify($pdo, (int)$currentRestaurant['id'], $phoneNorm, $otpCodeInput);
                if (!$verify['ok']) {
                    $error = $verify['error'] ?? 'Ошибка подтверждения номера';
                } else {
                    $verifiedByOtp = true;
                    $result = staff_loyalty_issue_after_verified_phone($pdo, $currentRestaurant, $phoneNorm, $name);
                    if (!$result || empty($result['ok'])) {
                        $error = $result['error'] ?? 'Не удалось оформить карту после подтверждения номера.';
                    }
                }
            }
        } else {
            $result = staff_loyalty_issue_issue_card_live($pdo, (int)$currentRestaurant['id'], $phoneInput, $name);
            if (!$result || empty($result['ok'])) {
                if (!empty($result['need_register'])) {
                    $error = ($result['error'] ?? 'Гость не зарегистрирован.') . ' Можно отправить OTP прямо с этого экрана и подтвердить номер рядом с гостем.';
                } else {
                    $error = $result['error'] ?? 'Ошибка';
                }
            }
        }
    }
}

if ($result && !empty($result['ok']) && $orderId > 0) {
    staff_loyalty_issue_attach_order_context($pdo, (int)$currentRestaurant['id'], $orderId, $result);
    $handoffParams = [
        'order_id' => (string)$orderId,
        'issued' => '1',
    ];
    if (!empty($result['guest']['id'])) {
        $handoffParams['guest_id'] = (string)((int)$result['guest']['id']);
    }
    if (!empty($result['guest']['phone'])) {
        $handoffParams['phone'] = (string)$result['guest']['phone'];
    }
    if (!empty($result['card']['token'])) {
        $handoffParams['token'] = (string)$result['card']['token'];
    }
    header('Location: /staff/loyalty_scan.php?' . http_build_query($handoffParams));
    exit;
}
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <title>Оформить карту гостя — <?= e($currentRestaurant['name']) ?></title>
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
      <a href="<?= e($returnTo) ?>" class="px-3 py-2 rounded-2xl bg-slate-900 border border-slate-800 text-xs hover:bg-slate-800">← Назад</a>
    </div>

    <?php if ($orderContext): ?>
      <?php
        $liTbl = function_exists('qr_public_owner_order_table_label')
            ? qr_public_owner_order_table_label((string)($orderContext['table_name'] ?? ''))
            : (string)($orderContext['table_name'] ?? '');
        if ($liTbl === '') {
            $liTbl = '—';
        }
        $liTblIsDel = ($liTbl === 'Доставка');
      ?>
      <div class="rounded-3xl bg-sky-500/10 border border-sky-500/30 p-4">
        <div class="text-sm font-semibold text-sky-100">Оформление карты в контексте заказа #<?= (int)$orderContext['id'] ?></div>
        <div class="mt-1 text-xs text-sky-100/80">
          <?= $liTblIsDel ? 'Доставка' : ('Стол: ' . e($liTbl)) ?>
          <?php if (!empty($orderContext['loyalty_phone']) || !empty($orderContext['guest_phone'])): ?>
            · телефон: <?= e((string)($orderContext['loyalty_phone'] ?? $orderContext['guest_phone'])) ?>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>

    <?php if (!$loyaltyEnabledByPlan): ?>
      <div class="rounded-2xl border border-amber-500/50 bg-amber-500/10 px-4 py-3 text-sm text-amber-200" role="status">
        <p class="font-medium">Программа лояльности доступна на тарифе PRO</p>
        <p class="text-xs text-amber-200/80 mt-1">Подключите loyalty, чтобы начислять бонусы, удерживать гостей и повышать повторные визиты.</p>
        <a href="/owner/billing.php" class="inline-flex items-center mt-3 px-4 py-2 rounded-xl bg-amber-600 hover:bg-amber-500 text-white text-sm font-medium">Перейти на тариф PRO</a>
      </div>
    <?php endif; ?>

    <?php if ($error): ?>
      <div class="rounded-3xl bg-rose-500/10 border border-rose-500/60 px-4 py-3 text-sm text-rose-100">
        <?= e($error) ?>
      </div>
    <?php endif; ?>

    <?php if ($otpSent): ?>
      <div class="rounded-3xl bg-emerald-500/10 border border-emerald-500/50 px-4 py-3 text-sm text-emerald-100">
        Код отправлен. Подтвердите номер гостя ниже.
        <?php if (!empty($otpInfo['test_code'])): ?>
          <div class="mt-1 text-xs text-emerald-200/80">Тестовый код: <span class="font-mono"><?= e((string)$otpInfo['test_code']) ?></span></div>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <div class="rounded-3xl bg-slate-900/70 border border-slate-800 p-4">
      <form method="post" class="grid sm:grid-cols-2 gap-3 items-end">
        <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf'] ?? '') ?>">
        <input type="hidden" name="order_id" value="<?= $orderId ?>">
        <input type="hidden" name="return_to" value="<?= e($returnTo) ?>">
        <div>
          <label class="block text-xs text-slate-300 mb-1">Телефон гостя</label>
          <input name="phone" type="tel" required placeholder="+7 9XX XXX-XX-XX"
                 value="<?= e($phoneInput) ?>"
                 class="w-full rounded-2xl bg-slate-950 border border-slate-700 px-3 py-2 text-sm text-slate-50" <?= $loyaltyEnabledByPlan ? '' : 'readonly' ?>>
        </div>
        <div>
          <label class="block text-xs text-slate-300 mb-1">Имя (опционально)</label>
          <input name="name" type="text" placeholder="Например: Анна"
                 value="<?= e($nameInput) ?>"
                 class="w-full rounded-2xl bg-slate-950 border border-slate-700 px-3 py-2 text-sm text-slate-50" <?= $loyaltyEnabledByPlan ? '' : 'readonly' ?>>
        </div>

        <div class="sm:col-span-2 flex flex-col sm:flex-row gap-2 sm:items-center sm:justify-between">
          <button name="action" value="issue" class="px-4 py-2.5 rounded-2xl bg-emerald-500 hover:bg-emerald-400 text-sm font-semibold text-slate-950" <?= $loyaltyEnabledByPlan ? '' : 'disabled' ?>>
            Оформить / открыть карту
          </button>

          <a href="<?= e($registerUrl) ?>"
             class="text-[11px] text-slate-400 hover:text-slate-200 underline decoration-dotted">
            Нужен обычный guest login? Открыть ссылку для гостя
          </a>
        </div>
      </form>
    </div>

    <div class="rounded-3xl bg-slate-900/70 border border-slate-800 p-4 space-y-3">
      <div class="flex items-start justify-between gap-3">
        <div>
          <div class="text-sm font-semibold">Staff-assisted подтверждение номера</div>
          <div class="text-xs text-slate-400 mt-1">
            Если карты ещё нет, можно отправить OTP гостю прямо здесь, подтвердить номер рядом с гостем и сразу оформить карту без отдельного guest login.
          </div>
        </div>
        <div class="text-[11px] px-2 py-1 rounded-full bg-slate-950 border border-slate-700 text-slate-200">OTP</div>
      </div>

      <div class="grid sm:grid-cols-2 gap-3">
        <form method="post" class="rounded-3xl bg-slate-950/50 border border-slate-800 p-4 space-y-2">
          <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf'] ?? '') ?>">
          <input type="hidden" name="order_id" value="<?= $orderId ?>">
          <input type="hidden" name="return_to" value="<?= e($returnTo) ?>">
          <input type="hidden" name="phone" value="<?= e($phoneInput) ?>">
          <input type="hidden" name="name" value="<?= e($nameInput) ?>">
          <div class="text-sm font-semibold">1. Отправить код</div>
          <div class="text-xs text-slate-400">Номер уже подставлен из заказа, если он был сохранён.</div>
          <button name="action" value="send_otp" class="w-full px-4 py-2.5 rounded-2xl bg-sky-500 hover:bg-sky-400 text-sm font-semibold text-slate-950" <?= $loyaltyEnabledByPlan ? '' : 'disabled' ?>>
            Отправить OTP
          </button>
        </form>

        <form method="post" class="rounded-3xl bg-slate-950/50 border border-slate-800 p-4 space-y-2">
          <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf'] ?? '') ?>">
          <input type="hidden" name="order_id" value="<?= $orderId ?>">
          <input type="hidden" name="return_to" value="<?= e($returnTo) ?>">
          <input type="hidden" name="phone" value="<?= e($phoneInput) ?>">
          <input type="hidden" name="name" value="<?= e($nameInput) ?>">
          <div class="text-sm font-semibold">2. Подтвердить номер и создать карту</div>
          <input name="otp_code" type="text" inputmode="numeric" pattern="[0-9]*" placeholder="Код из SMS"
                 value="<?= e($otpCodeInput) ?>"
                 class="w-full rounded-2xl bg-slate-950 border border-slate-700 px-3 py-2 text-sm text-slate-50">
          <button name="action" value="verify_otp" class="w-full px-4 py-2.5 rounded-2xl bg-emerald-500 hover:bg-emerald-400 text-sm font-semibold text-slate-950" <?= $loyaltyEnabledByPlan ? '' : 'disabled' ?>>
            Подтвердить и создать карту
          </button>
        </form>
      </div>
    </div>

    <?php if ($result && !empty($result['ok'])): ?>
      <div class="rounded-3xl bg-emerald-500/10 border border-emerald-500/60 p-4 space-y-3">
        <div class="flex items-start justify-between gap-3">
          <div>
            <div class="text-sm font-semibold">Карта готова ✅</div>
            <div class="text-xs text-slate-400 mt-1">
              Гость: <?= e($result['guest']['phone']) ?>
              <?php if (!empty($result['guest']['name'])): ?> · <?= e($result['guest']['name']) ?><?php endif; ?>
              · Баланс: <span class="text-slate-50 font-semibold"><?= (int)$result['balance'] ?></span>
              <?php if (!empty($result['welcome_bonus'])): ?> · приветственный бонус: <span class="text-emerald-300 font-semibold">+<?= (int)$result['welcome_bonus'] ?></span><?php endif; ?>
              <?php if ($verifiedByOtp): ?> · номер подтверждён по OTP<?php endif; ?>
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
            <a href="<?= e($returnTo . (str_contains($returnTo, '?') ? '&' : '?') . 'phone=' . urlencode((string)$result['guest']['phone'])) ?>"
               class="w-full inline-flex items-center justify-center px-4 py-2.5 rounded-2xl bg-emerald-500 hover:bg-emerald-400 text-sm font-semibold text-slate-950">
              Вернуться в loyalty заказа
            </a>
            <div class="text-[11px] text-slate-400">
              Гость сможет показать QR из кабинета или кошелька, а персонал сможет быстро найти карту на экране ручных операций.
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
