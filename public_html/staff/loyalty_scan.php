<?php
require_once __DIR__ . '/../../app/bootstrap.php';
if (file_exists(__DIR__ . '/../../app/loyalty.php')) {
    require_once __DIR__ . '/../../app/loyalty.php';
}

require_staff_login();
if (!(function_exists('is_project_owner') && is_project_owner())) {
    $ridRole = (int)($currentRestaurant['id'] ?? 0);
    $staffRole = function_exists('current_user_restaurant_role') ? current_user_restaurant_role($ridRole) : null;
    if (!in_array((string)$staffRole, ['owner', 'admin', 'waiter', 'staff'], true)) {
        http_response_code(403);
        echo 'Access denied';
        exit;
    }
}

$pdo = db();
$me = auth_user();

// Soft loyalty gating: block accrual/spend when feature disabled (demo unchanged).
$loyaltyEnabledByPlan = true;
if (!is_demo_mode() && file_exists(__DIR__ . '/../../app/subscription_plans.php')) {
    require_once __DIR__ . '/../../app/subscription_plans.php';
    $loyaltyEnabledByPlan = function_exists('check_feature') && check_feature((int)$currentRestaurant['id'], 'loyalty_enabled');
}

$hasGuestCardsLedger = function_exists('db_table_exists')
    && db_table_exists('guest_cards')
    && db_table_exists('guest_loyalty_accounts')
    && db_table_exists('guest_loyalty_tx');
$hasLegacyLoyaltyLedger = function_exists('db_table_exists')
    && db_table_exists('loyalty_accounts')
    && db_table_exists('loyalty_transactions');
$loyaltyLegacyMode = (!$hasGuestCardsLedger && $hasLegacyLoyaltyLedger);

$err = null;
$ok  = null;
$card = null;
$lookupLabel = null;
$cardMissingForKnownGuest = false;
$issuePhoneHint = '';
$issueNameHint = '';

$token = trim($_POST['token'] ?? ($_GET['token'] ?? ''));
$phone = trim($_POST['phone'] ?? ($_GET['phone'] ?? ''));
$orderId = (int)($_POST['order_id'] ?? ($_GET['order_id'] ?? 0));
$guestIdParam = isset($_GET['guest_id']) ? (int)$_GET['guest_id'] : 0;
$issuedHandoff = isset($_GET['issued']) && (string)$_GET['issued'] === '1';
$orderContext = null;

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

function staff_loyalty_lookup_card(PDO $pdo, int $restaurantId, string $token, string $phoneRaw, ?string &$lookupLabel = null): ?array {
    $token = trim($token);
    if ($token !== '' && function_exists('db_table_exists') && db_table_exists('guest_cards')) {
        $lookupLabel = 'QR / код карты';
        return guest_get_card_by_token($pdo, $token);
    }

    $phoneRaw = trim($phoneRaw);
    if ($phoneRaw !== '' && function_exists('guest_get_card_by_phone') && function_exists('db_table_exists') && db_table_exists('guest_cards') && db_table_exists('guest_loyalty_accounts')) {
        $lookupLabel = 'Телефон гостя';
        return guest_get_card_by_phone($pdo, $restaurantId, $phoneRaw);
    }

    if ($phoneRaw !== '' && function_exists('db_table_exists')
        && db_table_exists('loyalty_accounts')
        && db_table_exists('guest_cards')
        && db_table_exists('guests')
    ) {
        $candidates = staff_loyalty_phone_candidates($phoneRaw);
        if ($candidates !== []) {
            $in = implode(',', array_fill(0, count($candidates), '?'));
            try {
                $stmt = $pdo->prepare("
                    SELECT
                        la.id AS account_id,
                        la.balance AS account_balance,
                        gc.id AS card_id,
                        gc.guest_id,
                        gc.restaurant_id,
                        gc.card_uid,
                        g.phone,
                        g.name
                    FROM loyalty_accounts la
                    INNER JOIN guest_cards gc ON gc.id = la.card_id
                    INNER JOIN guests g ON g.id = gc.guest_id
                    WHERE gc.restaurant_id = ?
                      AND g.phone IN ($in)
                    ORDER BY gc.id DESC
                    LIMIT 1
                ");
                $stmt->execute(array_merge([$restaurantId], $candidates));
                $acc = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($acc) {
                    $lookupLabel = 'Телефон гостя';
                    return [
                        'id' => (int)($acc['card_id'] ?? 0),
                        'guest_id' => (int)($acc['guest_id'] ?? 0),
                        'restaurant_id' => (int)($acc['restaurant_id'] ?? $restaurantId),
                        'phone' => (string)($acc['phone'] ?? ''),
                        'name' => (string)($acc['name'] ?? ''),
                        'balance' => (int)($acc['account_balance'] ?? 0),
                        'card_uid' => (string)($acc['card_uid'] ?? ''),
                        '_legacy_account_id' => (int)($acc['account_id'] ?? 0),
                        '_legacy_card_id' => (int)($acc['card_id'] ?? 0),
                        '_legacy_mode' => true,
                    ];
                }
            } catch (Throwable $e) {
                error_log('STAFF_LOYALTY_LOOKUP_FAIL ' . $e->getMessage());
            }
        }
    }

    return null;
}

function staff_loyalty_phone_candidates(string $raw): array {
    $digits = preg_replace('/\D+/u', '', trim($raw));
    if (!is_string($digits) || $digits === '') {
        return [];
    }
    if (strlen($digits) === 11 && $digits[0] === '8') {
        $digits = '7' . substr($digits, 1);
    }
    $base = $digits;
    if (strlen($base) === 10 && $base[0] === '9') {
        $base = '7' . $base;
    }
    $out = [];
    if ($base !== '') {
        $out[] = $base;
    }
    if (strlen($base) === 11 && $base[0] === '7') {
        $out[] = '+' . $base;
        $out[] = '8' . substr($base, 1);
    }
    return array_values(array_unique(array_filter($out, static function ($v) {
        return is_string($v) && $v !== '';
    })));
}

function staff_loyalty_legacy_recent_tx(PDO $pdo, int $cardId, int $limit = 8): array {
    if ($cardId <= 0 || !function_exists('db_table_exists') || !db_table_exists('loyalty_transactions')) {
        return [];
    }
    try {
        $stmt = $pdo->prepare("
            SELECT lt.id, lt.type, lt.points, lt.meta AS note, lt.created_at, lt.order_id
            FROM loyalty_transactions lt
            WHERE lt.card_id = :card_id
            ORDER BY lt.created_at DESC, lt.id DESC
            LIMIT {$limit}
        ");
        $stmt->execute([
            ':card_id' => $cardId,
        ]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function staff_loyalty_legacy_add_points(PDO $pdo, int $accountId, int $cardId, int $points, ?int $orderId = null, ?int $staffUserId = null, ?string $note = null): array {
    if ($accountId <= 0 || $cardId <= 0 || $points <= 0) {
        return ['ok' => false, 'error' => 'Некорректные данные'];
    }
    try {
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $ownsTx = true;
        } else {
            $ownsTx = false;
        }
        $st = $pdo->prepare("SELECT balance FROM loyalty_accounts WHERE id = ? FOR UPDATE");
        $st->execute([$accountId]);
        $balance = $st->fetchColumn();
        if ($balance === false) {
            if ($ownsTx && $pdo->inTransaction()) $pdo->rollBack();
            return ['ok' => false, 'error' => 'Loyalty account not found'];
        }
        $newBalance = (int)$balance + $points;
        $upd = $pdo->prepare("UPDATE loyalty_accounts SET balance = :b, updated_at = NOW() WHERE id = :id");
        $upd->execute([':b' => $newBalance, ':id' => $accountId]);
        $meta = json_encode([
            'source' => 'staff_manual',
            'staff_user_id' => ($staffUserId && $staffUserId > 0) ? $staffUserId : null,
            'note' => ($note !== null && trim($note) !== '') ? trim($note) : null,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $ins = $pdo->prepare("INSERT INTO loyalty_transactions(card_id, order_id, type, points, meta, created_at) VALUES (:cid, :oid, 'earn', :pts, :meta, NOW())");
        $ins->execute([
            ':cid' => $cardId,
            ':oid' => ($orderId && $orderId > 0) ? $orderId : null,
            ':pts' => $points,
            ':meta' => ($meta !== false ? $meta : '{"source":"staff_manual"}'),
        ]);
        if ($ownsTx && $pdo->inTransaction()) $pdo->commit();
        return ['ok' => true, 'balance' => $newBalance];
    } catch (Throwable $e) {
        if (isset($ownsTx) && $ownsTx && $pdo->inTransaction()) $pdo->rollBack();
        error_log('STAFF_LOYALTY_EARN_FAIL ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Ошибка начисления'];
    }
}

function staff_loyalty_legacy_spend_points(PDO $pdo, int $accountId, int $cardId, int $points, ?int $orderId = null, ?int $staffUserId = null, ?string $note = null): array {
    if ($accountId <= 0 || $cardId <= 0 || $points <= 0) {
        return ['ok' => false, 'error' => 'Некорректные данные'];
    }
    try {
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $ownsTx = true;
        } else {
            $ownsTx = false;
        }
        $st = $pdo->prepare("SELECT balance FROM loyalty_accounts WHERE id = ? FOR UPDATE");
        $st->execute([$accountId]);
        $balance = $st->fetchColumn();
        if ($balance === false) {
            if ($ownsTx && $pdo->inTransaction()) $pdo->rollBack();
            return ['ok' => false, 'error' => 'Loyalty account not found'];
        }
        $balanceInt = (int)$balance;
        if ($balanceInt < $points) {
            if ($ownsTx && $pdo->inTransaction()) $pdo->rollBack();
            return ['ok' => false, 'error' => 'Недостаточно бонусов'];
        }
        $newBalance = $balanceInt - $points;
        $upd = $pdo->prepare("UPDATE loyalty_accounts SET balance = :b, updated_at = NOW() WHERE id = :id");
        $upd->execute([':b' => $newBalance, ':id' => $accountId]);
        $meta = json_encode([
            'source' => 'staff_manual',
            'staff_user_id' => ($staffUserId && $staffUserId > 0) ? $staffUserId : null,
            'note' => ($note !== null && trim($note) !== '') ? trim($note) : null,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $ins = $pdo->prepare("INSERT INTO loyalty_transactions(card_id, order_id, type, points, meta, created_at) VALUES (:cid, :oid, 'spend', :pts, :meta, NOW())");
        $ins->execute([
            ':cid' => $cardId,
            ':oid' => ($orderId && $orderId > 0) ? $orderId : null,
            ':pts' => $points,
            ':meta' => ($meta !== false ? $meta : '{"source":"staff_manual"}'),
        ]);
        if ($ownsTx && $pdo->inTransaction()) $pdo->commit();
        return ['ok' => true, 'balance' => $newBalance, 'spent' => $points];
    } catch (Throwable $e) {
        if (isset($ownsTx) && $ownsTx && $pdo->inTransaction()) $pdo->rollBack();
        error_log('STAFF_LOYALTY_SPEND_FAIL ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Ошибка списания'];
    }
}

function staff_loyalty_order_id(PDO $pdo, int $restaurantId, int $orderId): int {
    if ($orderId <= 0) {
        return 0;
    }
    try {
        $stmt = $pdo->prepare("SELECT id FROM orders WHERE id = :oid AND restaurant_id = :rid LIMIT 1");
        $stmt->execute([
            ':oid' => $orderId,
            ':rid' => $restaurantId,
        ]);
        return $stmt->fetchColumn() ? $orderId : 0;
    } catch (Throwable $e) {
        return 0;
    }
}

function staff_loyalty_order_context(PDO $pdo, int $restaurantId, int $orderId): ?array {
    if ($orderId <= 0) {
        return null;
    }

    if (file_exists(__DIR__ . '/../../app/schema_guard.php')) {
        require_once __DIR__ . '/../../app/schema_guard.php';
    }

    $hasGuestId = function_exists('db_column_exists') ? db_column_exists('orders', 'guest_id') : false;
    $hasGuestCardId = function_exists('db_column_exists') ? db_column_exists('orders', 'guest_card_id') : false;
    $hasLoyaltyPhone = function_exists('db_column_exists') ? db_column_exists('orders', 'loyalty_phone') : false;
    $hasLoyaltySpent = function_exists('db_column_exists') ? db_column_exists('orders', 'loyalty_points_spent') : false;
    $hasPaymentType = function_exists('db_column_exists') ? db_column_exists('orders', 'payment_type') : false;
    $hasGuestPhone = $hasGuestId;

    $guestIdSql = $hasGuestId ? 'o.guest_id AS guest_id,' : 'NULL AS guest_id,';
    $guestCardIdSql = $hasGuestCardId ? 'o.guest_card_id AS guest_card_id,' : 'NULL AS guest_card_id,';
    $loyaltyPhoneSql = $hasLoyaltyPhone ? 'o.loyalty_phone AS loyalty_phone,' : 'NULL AS loyalty_phone,';
    $loyaltySpentSql = $hasLoyaltySpent ? 'o.loyalty_points_spent AS loyalty_points_spent,' : '0 AS loyalty_points_spent,';
    $paymentTypeSql = $hasPaymentType ? 'o.payment_type AS payment_type,' : "'cash' AS payment_type,";
    $guestPhoneSql = $hasGuestPhone ? 'g.phone AS guest_phone, g.name AS guest_name,' : 'NULL AS guest_phone, NULL AS guest_name,';
    $guestJoinSql = $hasGuestPhone ? 'LEFT JOIN guests g ON g.id = o.guest_id' : '';

    try {
        $stmt = $pdo->prepare("
            SELECT
                o.id,
                o.total_price,
                {$paymentTypeSql}
                o.payment_status,
                o.order_status,
                o.created_at,
                {$guestIdSql}
                {$guestCardIdSql}
                {$loyaltyPhoneSql}
                {$loyaltySpentSql}
                {$guestPhoneSql}
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

function staff_loyalty_payment_type_label(string $type): string {
    $map = [
        'card_later' => 'Картой',
        'cash' => 'Наличными',
        'pay_later' => 'Позже',
    ];
    return $map[$type] ?? $type;
}

function staff_loyalty_payment_status_label(string $status): string {
    $map = [
        'pending' => 'Ожидание',
        'paid' => 'Оплачен',
        'unpaid' => 'Не оплачен',
        'canceled' => 'Отмена',
    ];
    return $map[$status] ?? $status;
}

function staff_loyalty_order_status_label(string $status): string {
    $map = [
        'new' => 'Новый',
        'accepted' => 'Принят',
        'cooking' => 'Готовится',
        'ready' => 'Готово',
        'delivered' => 'Отдан',
        'canceled' => 'Отменён',
    ];
    return $map[$status] ?? $status;
}

$orderId = staff_loyalty_order_id($pdo, (int)$currentRestaurant['id'], $orderId);
if ($orderId > 0) {
    $orderContext = staff_loyalty_order_context($pdo, (int)$currentRestaurant['id'], $orderId);
    if (!$orderContext) {
        $err = 'Заказ для loyalty-контекста не найден в этом ресторане.';
        $orderId = 0;
    } else {
        if ($phone === '' && !empty($orderContext['loyalty_phone'])) {
            $phone = trim((string)$orderContext['loyalty_phone']);
        }
        if ($phone === '' && !empty($orderContext['guest_phone'])) {
            $phone = trim((string)$orderContext['guest_phone']);
        }
        if ($token === '' && !$card) {
            $guestIdHint = (int)($orderContext['guest_id'] ?? 0);
            if ($guestIdHint > 0 && function_exists('guest_get_card_by_guest_rest')) {
                $card = guest_get_card_by_guest_rest($pdo, (int)$currentRestaurant['id'], $guestIdHint);
                if ($card) {
                    $lookupLabel = 'Контекст заказа';
                }
            }
        }
    }
}

if (!$card && $guestIdParam > 0 && function_exists('guest_get_card_by_guest_rest')) {
    $card = guest_get_card_by_guest_rest($pdo, (int)$currentRestaurant['id'], $guestIdParam);
    if ($card) {
        $lookupLabel = 'Оформленная карта';
    }
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
        $phone  = trim($_POST['phone'] ?? '');

        $card = staff_loyalty_lookup_card($pdo, (int)$currentRestaurant['id'], $token, $phone, $lookupLabel);
        if (!$card) {
            $guest = null;
            if ($phone !== '' && function_exists('guest_phone_normalize') && function_exists('guest_find_by_phone')) {
                $phoneNorm = guest_phone_normalize($phone);
                if ($phoneNorm) {
                    $guest = guest_find_by_phone($pdo, $phoneNorm);
                }
            }
            if ($guest) {
                $cardMissingForKnownGuest = true;
                $issuePhoneHint = (string)($guest['phone'] ?? $phone);
                $issueNameHint = (string)($guest['name'] ?? '');
                $err = 'Гость найден, но карта для этого ресторана ещё не оформлена. Откройте карту на экране “Оформить карту гостя”.';
            } else {
                $err = $phone !== ''
                    ? 'Не удалось найти гостя или карту по этому номеру.'
                    : 'Не удалось прочитать QR/код карты.';
            }
        } else {

            if ((int)$card['restaurant_id'] !== (int)$currentRestaurant['id']) {
                $err = 'Эта карта относится к другому ресторану. Списание/начисление запрещено.';
            } else {
                $guestId = (int)$card['guest_id'];
                $points = (int)($_POST['points'] ?? 0);
                $note = trim($_POST['note'] ?? '');
                $note = $note !== '' ? $note : null;
                $orderIdInput = (int)($_POST['order_id'] ?? 0);
                $orderId = staff_loyalty_order_id($pdo, (int)$currentRestaurant['id'], $orderIdInput);

                $maxPoints = 10000;
                if ($points <= 0) {
                    $err = 'Введите количество баллов (> 0).';
                } elseif ($orderIdInput > 0 && $orderId <= 0) {
                    $err = 'Заказ для привязки не найден в этом ресторане.';
                } elseif ($points > $maxPoints) {
                    $err = 'Слишком большое значение. Максимум ' . $maxPoints . ' баллов за операцию.';
                } else {
                    $points = min($points, $maxPoints);
                    $useLegacy = !empty($card['_legacy_mode']) || $guestId <= 0;
                    if ($action === 'accrual') {
                        if ($useLegacy) {
                            $legacyAccountId = (int)($card['_legacy_account_id'] ?? 0);
                            $legacyCardId = (int)($card['_legacy_card_id'] ?? 0);
                            $res = staff_loyalty_legacy_add_points($pdo, $legacyAccountId, $legacyCardId, $points, $orderId > 0 ? $orderId : null, (int)($me['id'] ?? 0), $note);
                            if (empty($res['ok'])) {
                                $err = (string)($res['error'] ?? 'Ошибка начисления');
                            } else {
                                $ok = 'Начислено +' . $points . ' бонусов. Новый баланс: ' . (int)($res['balance'] ?? 0) . ($orderId > 0 ? ' · заказ #' . $orderId : '') . ' · операция записана в журнал';
                            }
                        } else {
                            $res = guest_loyalty_add_points($pdo, (int)$currentRestaurant['id'], $guestId, $points, (int)($me['id'] ?? null), $orderId > 0 ? $orderId : null, $note);
                            if (!$res['ok']) $err = $res['error'] ?? 'Ошибка начисления';
                            else $ok = 'Начислено +' . $points . ' бонусов. Новый баланс: ' . (int)$res['balance'] . ($orderId > 0 ? ' · заказ #' . $orderId : '') . ' · операция записана в журнал';
                        }
                    } elseif ($action === 'spend') {
                        if ($useLegacy) {
                            $legacyAccountId = (int)($card['_legacy_account_id'] ?? 0);
                            $legacyCardId = (int)($card['_legacy_card_id'] ?? 0);
                            $res = staff_loyalty_legacy_spend_points($pdo, $legacyAccountId, $legacyCardId, $points, $orderId > 0 ? $orderId : null, (int)($me['id'] ?? 0), $note);
                            if (empty($res['ok'])) {
                                $err = (string)($res['error'] ?? 'Ошибка списания');
                            } else {
                                $spent = (int)($res['spent'] ?? $points);
                                $ok = 'Списано -' . $spent . ' бонусов. Новый баланс: ' . (int)($res['balance'] ?? 0) . ($orderId > 0 ? ' · заказ #' . $orderId : '') . ' · операция записана в журнал';
                            }
                        } else {
                            $res = guest_loyalty_spend_points($pdo, (int)$currentRestaurant['id'], $guestId, $points, (int)($me['id'] ?? null), $orderId > 0 ? $orderId : null, $note);
                            if (!$res['ok']) $err = $res['error'] ?? 'Ошибка списания';
                            else $ok = 'Списано -' . $points . ' бонусов. Новый баланс: ' . (int)$res['balance'] . ($orderId > 0 ? ' · заказ #' . $orderId : '') . ' · операция записана в журнал';
                        }
                    } else {
                        $err = 'Неизвестное действие';
                    }
                }

                $card = staff_loyalty_lookup_card($pdo, (int)$currentRestaurant['id'], $token, $phone, $lookupLabel);
                if (!$card && $orderId > 0 && $orderContext && (int)($orderContext['guest_id'] ?? 0) > 0 && function_exists('guest_get_card_by_guest_rest')) {
                    $card = guest_get_card_by_guest_rest($pdo, (int)$currentRestaurant['id'], (int)$orderContext['guest_id']);
                    if ($card) {
                        $lookupLabel = 'Контекст заказа';
                    }
                }
            }
        }
    }
    }
} elseif ($token !== '' || $phone !== '') {
    $card = staff_loyalty_lookup_card($pdo, (int)$currentRestaurant['id'], $token, $phone, $lookupLabel);
    if ($card && (int)$card['restaurant_id'] !== (int)$currentRestaurant['id']) {
        $err = 'Эта карта относится к другому ресторану.';
    } elseif (!$card && $phone !== '') {
        $guest = null;
        if (function_exists('guest_phone_normalize') && function_exists('guest_find_by_phone')) {
            $phoneNorm = guest_phone_normalize($phone);
            if ($phoneNorm) {
                $guest = guest_find_by_phone($pdo, $phoneNorm);
            }
        }
        if ($guest) {
            $cardMissingForKnownGuest = true;
            $issuePhoneHint = (string)($guest['phone'] ?? $phone);
            $issueNameHint = (string)($guest['name'] ?? '');
            $err = 'Гость найден, но карта для этого ресторана ещё не оформлена. Откройте карту на экране “Оформить карту гостя”.';
        } else {
            $err = 'Не удалось найти гостя или карту по этому номеру.';
        }
    }
}

if ($issuePhoneHint === '' && $orderContext) {
    $issuePhoneHint = trim((string)($orderContext['loyalty_phone'] ?? $orderContext['guest_phone'] ?? ''));
    $issueNameHint = trim((string)($orderContext['guest_name'] ?? ''));
    if ($issuePhoneHint !== '' && !$card && (int)($orderContext['guest_card_id'] ?? 0) <= 0) {
        $cardMissingForKnownGuest = true;
    }
}

$issueReturnTo = '/staff/loyalty_scan.php' . ($orderId > 0 ? ('?order_id=' . $orderId) : '');
$issueUrl = '/staff/loyalty_issue.php';
$issueParams = ['return_to' => $issueReturnTo];
if ($orderId > 0) {
    $issueParams['order_id'] = (string)$orderId;
}
if ($issuePhoneHint !== '') {
    $issueParams['phone'] = $issuePhoneHint;
}
if ($issueNameHint !== '') {
    $issueParams['name'] = $issueNameHint;
}
$issueUrl .= '?' . http_build_query($issueParams);

?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <title>QR-карта и ручные операции — <?= e($currentRestaurant['name']) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://unpkg.com/html5-qrcode"></script>
</head>
<body class="min-h-screen bg-slate-950 text-slate-50">
  <div class="max-w-4xl mx-auto p-4 space-y-4">
    <div class="flex items-center justify-between">
      <div>
        <div class="text-xs text-slate-400">STAFF</div>
        <h1 class="text-2xl font-semibold">QR-карта и ручные операции</h1>
        <div class="text-sm text-slate-400"><?= e($currentRestaurant['name']) ?></div>
      </div>
      <a href="/staff/loyalty.php" class="px-3 py-2 rounded-2xl bg-slate-900 border border-slate-800 text-xs hover:bg-slate-800">← Лояльность</a>
    </div>

    <?php if ($ok): ?>
      <div class="rounded-3xl bg-emerald-500/10 border border-emerald-500/60 px-4 py-3 text-sm text-emerald-100">
        <?= e($ok) ?>
      </div>
    <?php endif; ?>

    <?php if ($issuedHandoff && !$err): ?>
      <div class="rounded-3xl bg-emerald-500/10 border border-emerald-500/60 px-4 py-3 text-sm text-emerald-100">
        Карта оформлена. Гость и заказ уже подхвачены — теперь можно сразу начислить или списать бонусы по этому заказу.
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

    <?php if ($orderContext): ?>
      <?php
        $orderTotal = (float)($orderContext['total_price'] ?? 0);
        $orderSpent = (int)($orderContext['loyalty_points_spent'] ?? 0);
        $orderGross = $orderTotal + max(0, $orderSpent);
      ?>
      <?php
        $lsTbl = function_exists('qr_public_owner_order_table_label')
            ? qr_public_owner_order_table_label((string)($orderContext['table_name'] ?? ''))
            : (string)($orderContext['table_name'] ?? '');
        if ($lsTbl === '') {
            $lsTbl = '—';
        }
        $lsTblIsDel = ($lsTbl === 'Доставка');
      ?>
      <div class="rounded-3xl bg-sky-500/10 border border-sky-500/30 p-4 space-y-3">
        <div class="flex items-start justify-between gap-3">
          <div>
            <div class="text-sm font-semibold text-sky-100">Loyalty в контексте заказа #<?= (int)$orderContext['id'] ?></div>
            <div class="mt-1 text-xs text-sky-100/80">
              <?= $lsTblIsDel ? 'Доставка' : ('Стол: ' . e($lsTbl)) ?>
              · <?= e(staff_loyalty_order_status_label((string)($orderContext['order_status'] ?? ''))) ?>
              · <?= e(staff_loyalty_payment_status_label((string)($orderContext['payment_status'] ?? ''))) ?>
              · <?= e(staff_loyalty_payment_type_label((string)($orderContext['payment_type'] ?? ''))) ?>
            </div>
          </div>
          <a href="/staff/orders.php" class="px-3 py-2 rounded-2xl bg-slate-950/60 border border-slate-700 text-xs hover:bg-slate-900">
            ← К заказам
          </a>
        </div>
        <div class="grid sm:grid-cols-3 gap-3 text-sm">
          <div class="rounded-2xl bg-slate-950/40 border border-slate-800 px-3 py-2">
            <div class="text-[11px] text-slate-400">Сумма блюд</div>
            <div class="font-semibold text-slate-50"><?= (int)round($orderGross) ?> ₽</div>
          </div>
          <div class="rounded-2xl bg-slate-950/40 border border-slate-800 px-3 py-2">
            <div class="text-[11px] text-slate-400">Списано бонусами</div>
            <div class="font-semibold text-amber-300"><?= max(0, $orderSpent) ?> ₽</div>
          </div>
          <div class="rounded-2xl bg-slate-950/40 border border-slate-800 px-3 py-2">
            <div class="text-[11px] text-slate-400">К оплате</div>
            <div class="font-semibold text-emerald-300"><?= (int)round($orderTotal) ?> ₽</div>
          </div>
        </div>
        <div class="text-[11px] text-slate-400">
          <?php if (!empty($orderContext['loyalty_phone'])): ?>
            Телефон из заказа: <span class="text-slate-200"><?= e((string)$orderContext['loyalty_phone']) ?></span>
          <?php elseif (!empty($orderContext['guest_phone'])): ?>
            Телефон гостя: <span class="text-slate-200"><?= e((string)$orderContext['guest_phone']) ?></span>
          <?php elseif ((int)($orderContext['guest_id'] ?? 0) > 0): ?>
            Заказ уже связан с guest profile. Loyalty tool попробует найти карту автоматически.
          <?php else: ?>
            В заказе пока нет loyalty-привязки. Можно найти гостя по QR-карте или по номеру.
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>

    <?php if ($cardMissingForKnownGuest && $issuePhoneHint !== ''): ?>
      <div class="rounded-3xl bg-amber-500/10 border border-amber-500/40 p-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <div class="text-sm font-semibold text-amber-100">Для этого гостя ещё нет карты ресторана</div>
          <div class="text-xs text-amber-100/80 mt-1">
            Можно подтвердить номер по OTP и сразу оформить карту, не выходя из контекста заказа.
          </div>
        </div>
        <a href="<?= e($issueUrl) ?>" class="inline-flex items-center justify-center px-4 py-2.5 rounded-2xl bg-amber-500 hover:bg-amber-400 text-sm font-semibold text-slate-950">
          Оформить карту для этого гостя
        </a>
      </div>
    <?php endif; ?>

    <div class="rounded-3xl bg-slate-900/70 border border-slate-800 p-4 text-sm text-slate-300">
      QR-карта теперь нужна прежде всего для офлайн-работы персонала: официант или кассир может быстро найти гостя, увидеть баланс и провести ручное начисление или списание. Если QR нет под рукой, ниже можно найти гостя по номеру телефона.
    </div>

    <div class="grid md:grid-cols-2 gap-3">
      <div class="rounded-3xl bg-slate-900/70 border border-slate-800 p-4">
        <div class="text-sm font-semibold mb-2">Камера</div>
        <div id="reader" class="rounded-2xl overflow-hidden border border-slate-800"></div>
        <div class="text-[11px] text-slate-400 mt-2">
          Наведите камеру на QR из кабинета или кошелька гостя. Код автоматически вставится в staff lookup.
        </div>
      </div>

      <div class="rounded-3xl bg-slate-900/70 border border-slate-800 p-4 space-y-3">
        <div class="grid sm:grid-cols-2 gap-3">
          <form method="get" class="space-y-2 rounded-3xl bg-slate-950/60 border border-slate-800 p-3">
            <?php if ($orderId > 0): ?><input type="hidden" name="order_id" value="<?= $orderId ?>"><?php endif; ?>
            <div>
              <div class="text-sm font-semibold">Код / Token</div>
              <div class="text-xs text-slate-400">Если гость показывает QR-карту</div>
            </div>
            <textarea name="token" class="w-full h-24 rounded-2xl bg-slate-950 border border-slate-700 p-3 text-[11px] font-mono"
                      placeholder="GC1:..."><?= e($token) ?></textarea>
            <button class="w-full px-4 py-2.5 rounded-2xl bg-emerald-500 hover:bg-emerald-400 text-sm font-semibold text-slate-950">
              Открыть по QR-коду
            </button>
          </form>

          <form method="get" class="space-y-2 rounded-3xl bg-slate-950/60 border border-slate-800 p-3">
            <?php if ($orderId > 0): ?><input type="hidden" name="order_id" value="<?= $orderId ?>"><?php endif; ?>
            <div>
              <div class="text-sm font-semibold">Телефон гостя</div>
              <div class="text-xs text-slate-400">Fallback для заказа через официанта или кассу</div>
            </div>
            <input name="phone" type="tel" value="<?= e($phone) ?>" placeholder="+7 9XX XXX-XX-XX"
                   class="w-full rounded-2xl bg-slate-950 border border-slate-700 px-3 py-2 text-sm text-slate-50">
            <button class="w-full px-4 py-2.5 rounded-2xl bg-slate-900 border border-slate-700 hover:bg-slate-800 text-sm font-semibold text-slate-100">
              Найти по номеру
            </button>
          </form>
        </div>

        <?php if ($card && !$err): ?>
          <div class="rounded-3xl bg-slate-950/70 border border-slate-800 p-4">
            <div class="text-sm font-semibold mb-1">Гость</div>
            <div class="text-xs text-slate-400">
              <?= e($card['phone']) ?><?= !empty($card['name']) ? ' · ' . e($card['name']) : '' ?>
            </div>
            <?php if ($lookupLabel): ?>
              <div class="mt-1 text-[11px] text-slate-500">Найдено через: <?= e($lookupLabel) ?></div>
            <?php endif; ?>
            <div class="mt-2 text-sm">
              Баланс: <span class="font-semibold text-slate-50"><?= (int)$card['balance'] ?></span> бонусов
            </div>

            <?php
            $recentTx = [];
            $legacyCardMode = !empty($card['_legacy_mode']);
            if (!$legacyCardMode && function_exists('guest_loyalty_recent_tx')) {
                $recentTx = guest_loyalty_recent_tx($pdo, (int)$currentRestaurant['id'], (int)$card['guest_id'], 8);
            } elseif ($legacyCardMode) {
                $recentTx = staff_loyalty_legacy_recent_tx($pdo, (int)($card['_legacy_card_id'] ?? 0), 8);
            }
            ?>
            <?php if (!empty($recentTx)): ?>
            <div class="mt-3 pt-3 border-t border-slate-800">
              <div class="text-[11px] text-slate-400 mb-2">Последние операции</div>
              <ul class="space-y-2 max-h-48 overflow-y-auto text-xs">
                <?php foreach ($recentTx as $tx): ?>
                  <?php
                    $txType = (string)($tx['type'] ?? '');
                    $txPoints = (int)($tx['points'] ?? 0);
                    $txOrderId = (int)($tx['order_id'] ?? 0);
                    $txActor = trim((string)($tx['actor_name'] ?? ''));
                    $txNote = trim((string)($tx['note'] ?? ''));
                    $txCreated = !empty($tx['created_at']) ? date('d.m H:i', strtotime((string)$tx['created_at'])) : '—';
                    $txLabel = $txType === 'spend' ? 'Списание' : 'Начисление';
                    $txValue = ($txType === 'accrual' ? '+' : '') . $txPoints;
                    $txValueClass = $txType === 'spend' ? 'text-rose-300' : 'text-emerald-300';
                    $txActorLabel = $txActor !== '' ? $txActor : (((int)($tx['staff_user_id'] ?? 0) > 0) ? ('staff #' . (int)$tx['staff_user_id']) : 'авто/система');
                  ?>
                  <li class="rounded-2xl bg-slate-900/40 border border-slate-800 px-3 py-2">
                    <div class="flex items-center justify-between gap-3">
                      <div class="min-w-0">
                        <div class="text-slate-100 font-medium"><?= e($txLabel) ?></div>
                        <div class="text-[11px] text-slate-500 mt-0.5">
                          <?= e($txActorLabel) ?>
                          <?php if ($txOrderId > 0): ?> · заказ #<?= $txOrderId ?><?php endif; ?>
                          · <?= e($txCreated) ?>
                        </div>
                      </div>
                      <div class="font-semibold <?= e($txValueClass) ?> whitespace-nowrap"><?= e($txValue) ?></div>
                    </div>
                    <?php if ($txNote !== ''): ?>
                      <div class="mt-1 text-[11px] text-slate-400"><?= e($txNote) ?></div>
                    <?php endif; ?>
                  </li>
                <?php endforeach; ?>
              </ul>
            </div>
            <?php endif; ?>

            <div class="mt-3 grid grid-cols-2 gap-2">
              <form method="post" class="space-y-2">
                <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf'] ?? '') ?>">
                <input type="hidden" name="token" value="<?= e($token) ?>">
                <input type="hidden" name="phone" value="<?= e($phone) ?>">
                <input type="hidden" name="action" value="accrual">
                <label class="block text-[11px] text-slate-400">Начислить</label>
                <input name="points" type="number" min="1" class="w-full rounded-2xl bg-slate-950 border border-slate-700 px-3 py-2 text-sm" placeholder="бонусы" <?= $loyaltyEnabledByPlan ? '' : 'readonly' ?>>
                <input name="order_id" type="number" min="1" value="<?= $orderId > 0 ? $orderId : '' ?>" class="w-full rounded-2xl bg-slate-950 border border-slate-700 px-3 py-2 text-sm" placeholder="заказ # (опц.)" <?= $loyaltyEnabledByPlan ? '' : 'readonly' ?>>
                <input name="note" type="text" class="w-full rounded-2xl bg-slate-950 border border-slate-700 px-3 py-2 text-sm" placeholder="комментарий (опц.)" <?= $loyaltyEnabledByPlan ? '' : 'readonly' ?>>
                <button class="w-full px-4 py-2.5 rounded-2xl bg-emerald-500 hover:bg-emerald-400 text-sm font-semibold text-slate-950" <?= $loyaltyEnabledByPlan ? '' : 'disabled' ?>>
                  Начислить
                </button>
              </form>

              <form method="post" class="space-y-2">
                <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf'] ?? '') ?>">
                <input type="hidden" name="token" value="<?= e($token) ?>">
                <input type="hidden" name="phone" value="<?= e($phone) ?>">
                <input type="hidden" name="action" value="spend">
                <label class="block text-[11px] text-slate-400">Списать</label>
                <input name="points" type="number" min="1" class="w-full rounded-2xl bg-slate-950 border border-slate-700 px-3 py-2 text-sm" placeholder="бонусы" <?= $loyaltyEnabledByPlan ? '' : 'readonly' ?>>
                <input name="order_id" type="number" min="1" value="<?= $orderId > 0 ? $orderId : '' ?>" class="w-full rounded-2xl bg-slate-950 border border-slate-700 px-3 py-2 text-sm" placeholder="заказ # (опц.)" <?= $loyaltyEnabledByPlan ? '' : 'readonly' ?>>
                <input name="note" type="text" class="w-full rounded-2xl bg-slate-950 border border-slate-700 px-3 py-2 text-sm" placeholder="комментарий (опц.)" <?= $loyaltyEnabledByPlan ? '' : 'readonly' ?>>
                <button class="w-full px-4 py-2.5 rounded-2xl bg-rose-500 hover:bg-rose-400 text-sm font-semibold text-slate-950" <?= $loyaltyEnabledByPlan ? '' : 'disabled' ?>>
                  Списать
                </button>
              </form>
            </div>
            <div class="mt-3 text-[11px] text-slate-500">
              <?php if ($orderId > 0): ?>
                Операция будет привязана к заказу #<?= $orderId ?> по умолчанию. При необходимости номер можно изменить вручную.
              <?php else: ?>
                Если операция относится к конкретному заказу, укажите его номер. Это поможет связать ручную loyalty-операцию с офлайн-заказом.
              <?php endif; ?>
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
