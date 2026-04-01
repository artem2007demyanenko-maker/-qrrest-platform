<?php
/**
 * Billing / Subscriptions (Variant 4).
 * Все запросы через prepared statements; multi-tenant по user_id.
 */

require_once __DIR__ . '/db.php';

/**
 * @return array<int,array{id:int,code:string,name:string,description:?string,price_month:float,currency:string,limits_json:?array,is_active:int,sort_order:int}>
 */
function billing_get_plans(): array
{
    $pdo = db();
    try {
        $stmt = $pdo->query("
            SELECT id, code, name, description, price_month, currency, limits_json, is_active, sort_order
            FROM plans
            WHERE is_active = 1
            ORDER BY sort_order ASC, id ASC
        ");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('BILLING_GET_PLANS_ERROR ' . $e->getMessage());
        return [];
    }
    $out = [];
    foreach ($rows as $row) {
        $id = (int)$row['id'];
        $limits = $row['limits_json'];
        if (is_string($limits)) {
            $decoded = json_decode($limits, true);
            $limits = is_array($decoded) ? $decoded : null;
        }
        $out[$id] = [
            'id'           => $id,
            'code'         => (string)$row['code'],
            'name'         => (string)$row['name'],
            'description'  => $row['description'] !== null ? (string)$row['description'] : null,
            'price_month'  => (float)$row['price_month'],
            'currency'     => (string)$row['currency'],
            'limits_json'  => $limits,
            'is_active'    => (int)$row['is_active'],
            'sort_order'   => (int)$row['sort_order'],
        ];
    }
    return $out;
}

/**
 * Текущая подписка владельца (активная или trial; при нескольких — по current_period_end DESC).
 * @return array|null
 */
function billing_get_subscription(int $userId): ?array
{
    $pdo = db();
    try {
        $stmt = $pdo->prepare("
            SELECT s.id, s.user_id, s.plan_id, s.status, s.current_period_start, s.current_period_end,
                   s.cancel_at_period_end, s.canceled_at, s.meta_json, s.created_at, s.updated_at,
                   p.code AS plan_code, p.name AS plan_name, p.price_month, p.currency, p.limits_json
            FROM subscriptions s
            JOIN plans p ON p.id = s.plan_id
            WHERE s.user_id = :uid
              AND s.status IN ('active', 'trial')
              AND s.current_period_end >= UTC_TIMESTAMP()
            ORDER BY s.current_period_end DESC
            LIMIT 1
        ");
        $stmt->execute(['uid' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('BILLING_GET_SUBSCRIPTION_ERROR user_id=' . $userId . ' ' . $e->getMessage());
        return null;
    }
    if (!$row) {
        return null;
    }
    $limits = $row['limits_json'] ?? null;
    if (is_string($limits)) {
        $decoded = json_decode($limits, true);
        $limits = is_array($decoded) ? $decoded : null;
    }
    return [
        'id'                     => (int)$row['id'],
        'user_id'                => (int)$row['user_id'],
        'plan_id'                => (int)$row['plan_id'],
        'plan_code'              => (string)$row['plan_code'],
        'plan_name'              => (string)$row['plan_name'],
        'price_month'            => (float)$row['price_month'],
        'currency'               => (string)$row['currency'],
        'limits_json'            => $limits,
        'status'                 => (string)$row['status'],
        'current_period_start'   => (string)$row['current_period_start'],
        'current_period_end'     => (string)$row['current_period_end'],
        'cancel_at_period_end'   => (int)$row['cancel_at_period_end'],
        'canceled_at'            => $row['canceled_at'] !== null ? (string)$row['canceled_at'] : null,
        'created_at'             => (string)$row['created_at'],
        'updated_at'             => (string)$row['updated_at'],
    ];
}

/**
 * Создаёт trial-подписку на 14 дней для нового владельца. Вызывать только при signup.
 * Если у пользователя уже есть активная/не истёкшая подписка — ничего не делает.
 */
function billing_ensure_trial_for_new_owner(int $userId): void
{
    if (!function_exists('schema_guard_billing_ready') || !schema_guard_billing_ready()) {
        return;
    }
    try {
        $existing = billing_get_subscription($userId);
        if ($existing !== null) {
            return;
        }
        $pdo = db();
        $stmt = $pdo->prepare("SELECT id FROM plans WHERE (code = 'trial' OR code = 'free') AND is_active = 1 ORDER BY code = 'trial' DESC LIMIT 1");
        $stmt->execute();
        $planRow = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$planRow) {
            $stmt = $pdo->prepare("SELECT id FROM plans WHERE is_active = 1 ORDER BY sort_order ASC LIMIT 1");
            $stmt->execute();
            $planRow = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        if (!$planRow) {
            error_log('BILLING_TRIAL no plan for user_id=' . $userId);
            return;
        }
        $planId = (int)$planRow['id'];
        $start = gmdate('Y-m-d H:i:s');
        $end   = gmdate('Y-m-d H:i:s', strtotime('+14 days'));
        $ins = $pdo->prepare("
            INSERT INTO subscriptions (user_id, plan_id, status, current_period_start, current_period_end, cancel_at_period_end)
            VALUES (:uid, :plan_id, 'trial', :start, :end, 0)
        ");
        $ins->execute(['uid' => $userId, 'plan_id' => $planId, 'start' => $start, 'end' => $end]);
    } catch (Throwable $e) {
        error_log('STABILITY_ERROR billing_ensure_trial_for_new_owner user_id=' . $userId . ' ' . $e->getMessage());
    }
}

/**
 * Информация о trial для UI.
 * @return array{is_trial:bool, trial_ends_at:?string, days_left:int, is_expired:bool, has_active_paid_plan:bool}
 */
function billing_get_trial_info(int $userId, ?int $restaurantId = null): array
{
    $out = [
        'is_trial'             => false,
        'trial_ends_at'        => null,
        'days_left'            => 0,
        'is_expired'           => false,
        'has_active_paid_plan' => false,
    ];
    if (!function_exists('schema_guard_billing_ready') || !schema_guard_billing_ready()) {
        return $out;
    }
    try {
        $sub = billing_get_subscription($userId);
        if ($sub === null) {
            $out['is_expired'] = true;
            return $out;
        }
        $out['has_active_paid_plan'] = ((float)($sub['price_month'] ?? 0)) > 0 && (($sub['status'] ?? '') === 'active');
        if (($sub['status'] ?? '') === 'trial') {
            $out['is_trial'] = true;
            $out['trial_ends_at'] = $sub['current_period_end'] ?? null;
            $endTs = $out['trial_ends_at'] ? strtotime($out['trial_ends_at']) : 0;
            $now = time();
            if ($endTs <= $now) {
                $out['is_expired'] = true;
                $out['days_left'] = 0;
            } else {
                $out['days_left'] = (int)max(0, ceil(($endTs - $now) / 86400));
            }
        } else {
            $out['has_active_paid_plan'] = true;
        }
    } catch (Throwable $e) {
        error_log('STABILITY_ERROR billing_get_trial_info user_id=' . $userId . ' ' . $e->getMessage());
    }
    return $out;
}

/**
 * true, если trial истёк и нет платной подписки — нужен upgrade.
 */
function billing_trial_required_to_continue(int $userId): bool
{
    $info = billing_get_trial_info($userId, null);
    return $info['is_expired'] && !$info['has_active_paid_plan'];
}

/**
 * Гарантирует наличие подписки у владельца (free по умолчанию). Идемпотентно.
 * @return array подписка (как billing_get_subscription)
 */
function billing_ensure_default_subscription(int $userId): array
{
    $existing = billing_get_subscription($userId);
    if ($existing !== null) {
        return $existing;
    }
    $pdo = db();
    try {
        $stmt = $pdo->prepare("SELECT id FROM plans WHERE code = 'free' AND is_active = 1 LIMIT 1");
        $stmt->execute();
        $planRow = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$planRow) {
            error_log('BILLING_ENSURE_DEFAULT no free plan user_id=' . $userId);
            return [
                'id' => 0, 'user_id' => $userId, 'plan_id' => 0, 'plan_code' => 'free', 'plan_name' => 'Бесплатный',
                'price_month' => 0.0, 'currency' => 'RUB', 'limits_json' => ['restaurants_max' => 1],
                'status' => 'active', 'current_period_start' => date('Y-m-d H:i:s'), 'current_period_end' => date('Y-m-d H:i:s', strtotime('+100 years')),
                'cancel_at_period_end' => 0, 'canceled_at' => null, 'created_at' => '', 'updated_at' => '',
            ];
        }
        $planId = (int)$planRow['id'];
        $start = gmdate('Y-m-d H:i:s');
        $end   = gmdate('Y-m-d H:i:s', strtotime('+100 years'));
        $ins = $pdo->prepare("
            INSERT INTO subscriptions (user_id, plan_id, status, current_period_start, current_period_end, cancel_at_period_end)
            VALUES (:uid, :plan_id, 'active', :start, :end, 0)
        ");
        $ins->execute(['uid' => $userId, 'plan_id' => $planId, 'start' => $start, 'end' => $end]);
    } catch (Throwable $e) {
        error_log('BILLING_ENSURE_DEFAULT_ERROR user_id=' . $userId . ' ' . $e->getMessage());
        return [
            'id' => 0, 'user_id' => $userId, 'plan_id' => 0, 'plan_code' => 'free', 'plan_name' => 'Бесплатный',
            'price_month' => 0.0, 'currency' => 'RUB', 'limits_json' => ['restaurants_max' => 1],
            'status' => 'active', 'current_period_start' => $start ?? gmdate('Y-m-d H:i:s'), 'current_period_end' => $end ?? gmdate('Y-m-d H:i:s', strtotime('+100 years')),
            'cancel_at_period_end' => 0, 'canceled_at' => null, 'created_at' => '', 'updated_at' => '',
        ];
    }
    $sub = billing_get_subscription($userId);
    return $sub ?? [
        'id' => 0, 'user_id' => $userId, 'plan_id' => $planId, 'plan_code' => 'free', 'plan_name' => 'Бесплатный',
        'price_month' => 0.0, 'currency' => 'RUB', 'limits_json' => ['restaurants_max' => 1],
        'status' => 'active', 'current_period_start' => $start, 'current_period_end' => $end,
        'cancel_at_period_end' => 0, 'canceled_at' => null, 'created_at' => '', 'updated_at' => '',
    ];
}

/**
 * Проверка лимита ресторанов для владельца (только COUNT по restaurants.owner_user_id).
 * @return array{ok:bool,reason?:string,limit?:int,current?:int}
 */
function billing_can_create_restaurant(int $userId): array
{
    $sub = billing_ensure_default_subscription($userId);
    $limits = $sub['limits_json'] ?? [];
    $max = isset($limits['restaurants_max']) ? (int)$limits['restaurants_max'] : 1;
    $pdo = db();
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM restaurants
            WHERE owner_user_id = :uid AND (deleted_at IS NULL)
        ");
        $stmt->execute(['uid' => $userId]);
        $current = (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        error_log('BILLING_CAN_CREATE_RESTAURANT_ERROR user_id=' . $userId . ' ' . $e->getMessage());
        return ['ok' => false, 'reason' => 'Ошибка проверки лимита.'];
    }
    if ($current >= $max) {
        return [
            'ok'      => false,
            'reason'  => 'Достигнут лимит ресторанов по вашему тарифу (' . $max . '). Смените тариф в разделе «Тарифы».',
            'limit'   => $max,
            'current' => $current,
        ];
    }
    return ['ok' => true, 'limit' => $max, 'current' => $current];
}

/**
 * Проверка лимита внутри транзакции с блокировкой (защита от гонок). Вызывать из project-admin в BEGIN перед INSERT ресторана.
 * Блокирует строку users.id = ownerId (FOR UPDATE), считает рестораны по owner_user_id.
 * @return array{ok:bool,reason?:string,limit?:int,current?:int}
 */
function billing_assert_can_create_restaurant(int $ownerId, PDO $pdo): array
{
    if (!$pdo->inTransaction()) {
        error_log('BILLING_ASSERT_CAN_CREATE called without transaction');
        return ['ok' => false, 'reason' => 'Ошибка проверки лимита.'];
    }
    $sub = billing_ensure_default_subscription($ownerId);
    $limits = $sub['limits_json'] ?? [];
    $max = isset($limits['restaurants_max']) ? (int)$limits['restaurants_max'] : 1;
    try {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE id = :uid FOR UPDATE");
        $stmt->execute(['uid' => $ownerId]);
        if (!$stmt->fetchColumn()) {
            return ['ok' => false, 'reason' => 'Владелец не найден.'];
        }
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM restaurants WHERE owner_user_id = :uid AND (deleted_at IS NULL)");
        $stmt->execute(['uid' => $ownerId]);
        $current = (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        error_log('BILLING_ASSERT_CAN_CREATE_ERROR owner_id=' . $ownerId . ' ' . $e->getMessage());
        return ['ok' => false, 'reason' => 'Ошибка проверки лимита.'];
    }
    if ($current >= $max) {
        return [
            'ok'      => false,
            'reason'  => 'Достигнут лимит ресторанов по тарифу владельца (' . $max . '). Владелец может сменить тариф в разделе «Тарифы» (/owner/billing.php).',
            'limit'   => $max,
            'current' => $current,
        ];
    }
    return ['ok' => true, 'limit' => $max, 'current' => $current];
}

/**
 * Смена тарифа (MVP: без реальной оплаты). В транзакции: подписка, invoice + payment при платном тарифе, опционально купон.
 * @param string $couponCode опциональный промокод
 * @return array{ok:bool,message?:string}
 */
function billing_change_plan(int $userId, string $planCode, string $couponCode = ''): array
{
    $planCode = trim($planCode);
    $couponCode = trim($couponCode);
    if ($planCode === '') {
        return ['ok' => false, 'message' => 'Укажите тариф.'];
    }
    $pdo = db();
    try {
        $stmt = $pdo->prepare("SELECT id, code, name, price_month, currency FROM plans WHERE code = :code AND is_active = 1 LIMIT 1");
        $stmt->execute(['code' => $planCode]);
        $plan = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$plan) {
            return ['ok' => false, 'message' => 'Тариф не найден.'];
        }
        $planId = (int)$plan['id'];
        $price  = (float)$plan['price_month'];
        $pdo->beginTransaction();

        $finalAmount = $price;
        $couponId = null;
        if ($couponCode !== '') {
            if (!function_exists('security_rate_limit')) {
                require_once __DIR__ . '/security.php';
            }
            security_rate_limit('coupon_usage:' . $userId, 5, 3600);
            if (!function_exists('growth_validate_coupon')) {
                require_once __DIR__ . '/growth.php';
            }
            $couponCheck = growth_validate_coupon($couponCode, $userId, $pdo, $price);
            if (!$couponCheck['ok']) {
                $pdo->rollBack();
                return ['ok' => false, 'message' => $couponCheck['message'] ?? 'Купон недействителен.'];
            }
            $finalAmount = growth_apply_discount($price, $couponCheck['type'], $couponCheck['value']);
            $couponId = $couponCheck['coupon_id'];
        }

        billing_ensure_default_subscription($userId);
        $sub = billing_get_subscription($userId);
        $subId = $sub && isset($sub['id']) ? (int)$sub['id'] : 0;

        $start = gmdate('Y-m-d H:i:s');
        $end   = gmdate('Y-m-d H:i:s', strtotime('+30 days'));
        $idempotencyKey = hash('sha256', $userId . '|' . $planCode . '|' . $start . '|' . (string)$price . '|' . $couponCode);

        $existingInv = null;
        if ($price > 0) {
            $chk = $pdo->prepare("SELECT id FROM invoices WHERE idempotency_key = :key AND user_id = :uid LIMIT 1");
            $chk->execute(['key' => $idempotencyKey, 'uid' => $userId]);
            $existingInv = $chk->fetchColumn();
        }

        if ($subId > 0) {
            $pdo->prepare("SELECT id FROM subscriptions WHERE id = :id AND user_id = :uid FOR UPDATE")->execute(['id' => $subId, 'uid' => $userId]);
            $upd = $pdo->prepare("
                UPDATE subscriptions
                SET plan_id = :plan_id, status = 'active',
                    current_period_start = :start, current_period_end = :end,
                    cancel_at_period_end = 0, canceled_at = NULL, updated_at = CURRENT_TIMESTAMP
                WHERE id = :id AND user_id = :uid
            ");
            $upd->execute(['plan_id' => $planId, 'start' => $start, 'end' => $end, 'id' => $subId, 'uid' => $userId]);
        } else {
            $ins = $pdo->prepare("
                INSERT INTO subscriptions (user_id, plan_id, status, current_period_start, current_period_end, cancel_at_period_end)
                VALUES (:uid, :plan_id, 'active', :start, :end, 0)
            ");
            $ins->execute(['uid' => $userId, 'plan_id' => $planId, 'start' => $start, 'end' => $end]);
            $subId = (int)$pdo->lastInsertId();
        }

        $invoiceId = null;
        if ($price > 0 && !$existingInv) {
            $insInv = $pdo->prepare("
                INSERT INTO invoices (user_id, subscription_id, amount, currency, status, period_start, period_end, provider, issued_at, paid_at, idempotency_key, coupon_id)
                VALUES (:uid, :sub_id, :amount, :currency, 'issued', :start, :end, 'manual', UTC_TIMESTAMP(), UTC_TIMESTAMP(), :idem_key, :coupon_id)
            ");
            $insInv->execute([
                'uid' => $userId, 'sub_id' => $subId, 'amount' => $finalAmount, 'currency' => (string)$plan['currency'],
                'start' => $start, 'end' => $end, 'idem_key' => $idempotencyKey, 'coupon_id' => $couponId,
            ]);
            $invoiceId = (int)$pdo->lastInsertId();
            $providerRef = 'manual:inv:' . $invoiceId;
            $insPay = $pdo->prepare("
                INSERT INTO payments (user_id, invoice_id, amount, currency, status, provider, idempotency_key, provider_ref)
                VALUES (:uid, :inv_id, :amount, :currency, 'succeeded', 'manual', :idem_key, :provider_ref)
            ");
            $insPay->execute(['uid' => $userId, 'inv_id' => $invoiceId, 'amount' => $finalAmount, 'currency' => (string)$plan['currency'], 'idem_key' => $idempotencyKey, 'provider_ref' => $providerRef]);
            $updInv = $pdo->prepare("UPDATE invoices SET status = 'paid', paid_at = UTC_TIMESTAMP() WHERE id = :id");
            $updInv->execute(['id' => $invoiceId]);
            if (function_exists('audit_log')) {
                require_once __DIR__ . '/audit.php';
                audit_log('invoice_paid', 'invoice', (string)$invoiceId);
            }
            if ($couponId !== null && function_exists('growth_apply_coupon_usage')) {
                growth_apply_coupon_usage($couponId, $userId, $pdo);
            }
        }

        $pdo->commit();
        if (function_exists('audit_log')) {
            require_once __DIR__ . '/audit.php';
            audit_log('plan_change', 'subscription', (string)$subId);
            if ($couponId !== null) {
                audit_log('coupon_usage', 'coupon', (string)$couponId);
            }
        }
        return ['ok' => true, 'message' => 'Тариф изменён на «' . $plan['name'] . '».'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('BILLING_CHANGE_PLAN_ERROR user_id=' . $userId . ' plan=' . $planCode . ' ' . $e->getMessage());
        return ['ok' => false, 'message' => 'Не удалось сменить тариф. Попробуйте позже.'];
    }
}

/**
 * Отмена подписки: по окончании периода или немедленно.
 */
function billing_cancel_subscription(int $userId, bool $atPeriodEnd): void
{
    $pdo = db();
    try {
        if ($atPeriodEnd) {
            $stmt = $pdo->prepare("
                UPDATE subscriptions
                SET cancel_at_period_end = 1, canceled_at = NULL, updated_at = CURRENT_TIMESTAMP
                WHERE user_id = :uid AND status IN ('active', 'trial')
            ");
            $stmt->execute(['uid' => $userId]);
        } else {
            $stmt = $pdo->prepare("
                UPDATE subscriptions
                SET status = 'canceled', canceled_at = UTC_TIMESTAMP(), cancel_at_period_end = 0, updated_at = CURRENT_TIMESTAMP
                WHERE user_id = :uid AND status IN ('active', 'trial')
            ");
            $stmt->execute(['uid' => $userId]);
        }
        if (function_exists('audit_log')) {
            require_once __DIR__ . '/audit.php';
            audit_log('subscription_cancel', 'subscription', $atPeriodEnd ? 'at_period_end' : 'now');
        }
    } catch (Throwable $e) {
        error_log('BILLING_CANCEL_SUBSCRIPTION_ERROR user_id=' . $userId . ' ' . $e->getMessage());
    }
}

/**
 * Продление подписки при истечении периода (cron-like). Активные с current_period_end < now и не canceled — продлеваем на 30 дней, создаём invoice.
 */
function billing_renew_if_needed_cronlike(): void
{
    $pdo = db();
    try {
        $stmt = $pdo->query("
            SELECT s.id, s.user_id, s.plan_id, p.price_month, p.currency
            FROM subscriptions s
            JOIN plans p ON p.id = s.plan_id
            WHERE s.status = 'active'
              AND s.current_period_end < UTC_TIMESTAMP()
              AND (s.canceled_at IS NULL AND s.cancel_at_period_end = 0)
        ");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('BILLING_RENEW_CRON_ERROR ' . $e->getMessage());
        return;
    }
    foreach ($rows as $row) {
        try {
            $pdo->beginTransaction();
            $subId   = (int)$row['id'];
            $userId  = (int)$row['user_id'];
            $planId  = (int)$row['plan_id'];
            $price   = (float)$row['price_month'];
            $currency = (string)$row['currency'];
            $start   = gmdate('Y-m-d H:i:s');
            $end     = gmdate('Y-m-d H:i:s', strtotime('+30 days'));
            $pdo->prepare("
                UPDATE subscriptions SET current_period_start = :start, current_period_end = :end, updated_at = CURRENT_TIMESTAMP WHERE id = :id
            ")->execute(['start' => $start, 'end' => $end, 'id' => $subId]);
            if ($price > 0) {
                $pdo->prepare("
                    INSERT INTO invoices (user_id, subscription_id, amount, currency, status, period_start, period_end, provider, issued_at, paid_at)
                    VALUES (:uid, :sub_id, :amount, :currency, 'issued', :start, :end, 'manual', UTC_TIMESTAMP(), UTC_TIMESTAMP())
                ")->execute(['uid' => $userId, 'sub_id' => $subId, 'amount' => $price, 'currency' => $currency, 'start' => $start, 'end' => $end]);
                $invId = (int)$pdo->lastInsertId();
                $pdo->prepare("
                    INSERT INTO payments (user_id, invoice_id, amount, currency, status, provider)
                    VALUES (:uid, :inv_id, :amount, :currency, 'succeeded', 'manual')
                ")->execute(['uid' => $userId, 'inv_id' => $invId, 'amount' => $price, 'currency' => $currency]);
                $pdo->prepare("UPDATE invoices SET status = 'paid', paid_at = UTC_TIMESTAMP() WHERE id = :id")->execute(['id' => $invId]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('BILLING_RENEW_ONE_ERROR sub_id=' . ($row['id'] ?? 0) . ' ' . $e->getMessage());
        }
    }
}

/**
 * Последние счета и платежи для владельца.
 * @return array{invoices:array,payments:array}
 */
function billing_get_history(int $userId, int $limit = 20): array
{
    $limit = max(1, min(100, (int)$limit));
    $pdo = db();
    $out = ['invoices' => [], 'payments' => []];
    try {
        $stmt = $pdo->prepare("
            SELECT i.id, i.subscription_id, i.amount, i.currency, i.status, i.period_start, i.period_end, i.issued_at, i.paid_at, i.created_at
            FROM invoices i
            WHERE i.user_id = :uid
            ORDER BY i.created_at DESC
            LIMIT " . (int)$limit . "
        ");
        $stmt->execute(['uid' => $userId]);
        $out['invoices'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $stmt = $pdo->prepare("
            SELECT p.id, p.invoice_id, p.amount, p.currency, p.status, p.provider, p.created_at
            FROM payments p
            WHERE p.user_id = :uid
            ORDER BY p.created_at DESC
            LIMIT " . (int)$limit . "
        ");
        $stmt->execute(['uid' => $userId]);
        $out['payments'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('BILLING_GET_HISTORY_ERROR user_id=' . $userId . ' ' . $e->getMessage());
    }
    return $out;
}
