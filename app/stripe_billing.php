<?php
/**
 * Stripe Billing integration (v1). No SDK required: HTTP API + webhook signature verification.
 * Graceful fallback when STRIPE_ENABLED=0 or keys missing.
 */

require_once __DIR__ . '/db.php';

function stripe_subscriptions_have_provider_ref(): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    if (!function_exists('db_column_exists')) {
        @require_once __DIR__ . '/schema_guard.php';
    }
    $ok = function_exists('db_column_exists') && db_column_exists('subscriptions', 'provider_ref');
    return $ok;
}

function stripe_billing_enabled(): bool
{
    $config = require __DIR__ . '/config.php';
    return !empty($config['stripe']['enabled']) && !empty($config['stripe']['secret_key']);
}

/**
 * Map internal plan (code) to Stripe Price ID.
 * @param array $plan plan row with 'code' => string
 * @return string|null
 */
function stripe_price_id_for_plan(array $plan): ?string
{
    $config = require __DIR__ . '/config.php';
    $code = strtolower(trim((string)($plan['code'] ?? '')));
    if ($code === '' || $code === 'free' || $code === 'trial') {
        return null;
    }
    $ids = $config['stripe']['price_ids'] ?? [];
    if (isset($ids[$code]) && $ids[$code] !== '') {
        return $ids[$code];
    }
    if ($code === 'starter' && !empty($ids['basic'])) {
        return $ids['basic'];
    }
    return null;
}

/**
 * Create Stripe Checkout Session (mode=subscription). Uses Stripe API v1 over HTTP.
 * @param array $context user_id, restaurant_id?, plan_id, plan_code?, plan_name, success_url, cancel_url, customer_email?
 * @return array{ok:bool, checkout_url?:string, session_id?:string, error?:string}
 */
function stripe_create_checkout_session(array $context): array
{
    $rid = bin2hex(random_bytes(4));
    if (!stripe_billing_enabled()) {
        return ['ok' => false, 'error' => 'Stripe not configured'];
    }
    $config = require __DIR__ . '/config.php';
    $secret = $config['stripe']['secret_key'];
    $priceId = null;
    if (!empty($context['plan_code'])) {
        $plan = ['code' => $context['plan_code']];
        $priceId = stripe_price_id_for_plan($plan);
    }
    if ($priceId === null || $priceId === '') {
        return ['ok' => false, 'error' => 'No Stripe price for this plan'];
    }
    $successUrl = trim((string)($context['success_url'] ?? $config['stripe']['success_url'] ?? ''));
    $cancelUrl = trim((string)($context['cancel_url'] ?? $config['stripe']['cancel_url'] ?? ''));
    if ($successUrl === '' || $cancelUrl === '') {
        return ['ok' => false, 'error' => 'Success/cancel URL not set'];
    }
    $userId = (int)($context['user_id'] ?? 0);
    $restaurantId = isset($context['restaurant_id']) ? (int)$context['restaurant_id'] : 0;
    $planId = (int)($context['plan_id'] ?? 0);
    $planCode = trim((string)($context['plan_code'] ?? ''));
    $customerEmail = trim((string)($context['customer_email'] ?? ''));

    $params = [
        'mode' => 'subscription',
        'success_url' => $successUrl,
        'cancel_url' => $cancelUrl,
        'line_items[0][price]' => $priceId,
        'line_items[0][quantity]' => 1,
        'metadata[user_id]' => (string)$userId,
        'metadata[restaurant_id]' => (string)$restaurantId,
        'metadata[plan_id]' => (string)$planId,
        'metadata[plan_code]' => $planCode,
    ];
    if ($customerEmail !== '') {
        $params['customer_email'] = $customerEmail;
    }

    $ch = curl_init('https://api.stripe.com/v1/checkout/sessions');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($params),
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $secret,
            'Content-Type: application/x-www-form-urlencoded',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($curlErr !== '') {
        error_log('STABILITY_ERROR stripe_create_checkout_session rid=' . $rid . ' curl ' . $curlErr);
        return ['ok' => false, 'error' => 'Stripe request failed'];
    }
    if ($httpCode < 200 || $httpCode >= 300) {
        error_log('STABILITY_ERROR stripe_create_checkout_session rid=' . $rid . ' http=' . $httpCode);
        return ['ok' => false, 'error' => 'Stripe returned error'];
    }
    $parsed = json_decode($response, true);
    if (!is_array($parsed)) {
        error_log('STABILITY_ERROR stripe_create_checkout_session rid=' . $rid . ' invalid json');
        return ['ok' => false, 'error' => 'Invalid response'];
    }
    $url = $parsed['url'] ?? null;
    $sessionId = $parsed['id'] ?? null;
    if ($url === null || $url === '') {
        error_log('STABILITY_ERROR stripe_create_checkout_session rid=' . $rid . ' no url in response');
        return ['ok' => false, 'error' => 'No checkout URL'];
    }
    return ['ok' => true, 'checkout_url' => $url, 'session_id' => $sessionId];
}

/**
 * Verify Stripe webhook signature and return parsed event.
 * @param string $payload raw POST body
 * @param string $sigHeader Stripe-Signature header
 * @return array{ok:bool, event?:array, error?:string}
 */
function stripe_verify_webhook(string $payload, string $sigHeader): array
{
    $config = require __DIR__ . '/config.php';
    $secret = $config['stripe']['webhook_secret'] ?? '';
    if ($secret === '') {
        return ['ok' => false, 'error' => 'Webhook secret not set'];
    }
    $parts = [];
    foreach (explode(',', $sigHeader) as $part) {
        $part = trim($part);
        if (strpos($part, '=') !== false) {
            list($k, $v) = explode('=', $part, 2);
            $parts[trim($k)] = trim($v);
        }
    }
    $t = $parts['t'] ?? '';
    $v1 = $parts['v1'] ?? '';
    if ($t === '' || $v1 === '') {
        return ['ok' => false, 'error' => 'Invalid signature header'];
    }
    $signed = $t . '.' . $payload;
    $expected = hash_hmac('sha256', $signed, $secret);
    if (!hash_equals($expected, $v1)) {
        return ['ok' => false, 'error' => 'Signature mismatch'];
    }
    $timestamp = (int)$t;
    if (abs(time() - $timestamp) > 300) {
        return ['ok' => false, 'error' => 'Timestamp too old'];
    }
    $event = json_decode($payload, true);
    if (!is_array($event)) {
        return ['ok' => false, 'error' => 'Invalid JSON'];
    }
    return ['ok' => true, 'event' => $event];
}

/**
 * Sync local subscriptions / invoices / payments from Stripe webhook event. Idempotent.
 */
function stripe_sync_subscription_from_webhook(array $event): void
{
    $rid = bin2hex(random_bytes(4));
    $type = $event['type'] ?? '';
    $obj = $event['data']['object'] ?? [];

    if (!function_exists('schema_guard_billing_ready') || !schema_guard_billing_ready()) {
        return;
    }
    require_once __DIR__ . '/billing.php';

    try {
        if ($type === 'checkout.session.completed') {
            stripe_handle_checkout_completed($obj, $rid);
        } elseif ($type === 'customer.subscription.created' || $type === 'customer.subscription.updated') {
            stripe_handle_subscription_updated($obj, $rid);
        } elseif ($type === 'customer.subscription.deleted') {
            stripe_handle_subscription_deleted($obj, $rid);
        } elseif ($type === 'invoice.paid') {
            stripe_handle_invoice_paid($obj, $rid);
        } elseif ($type === 'invoice.payment_failed') {
            stripe_handle_invoice_payment_failed($obj, $rid);
        }
    } catch (Throwable $e) {
        error_log('STABILITY_ERROR stripe_sync_subscription_from_webhook rid=' . $rid . ' type=' . $type . ' ' . $e->getMessage());
    }
}

function stripe_handle_checkout_completed(array $session, string $rid): void
{
    $pdo = db();
    $userId = (int)($session['metadata']['user_id'] ?? 0);
    $restaurantId = (int)($session['metadata']['restaurant_id'] ?? 0);
    $planId = (int)($session['metadata']['plan_id'] ?? 0);
    $planCode = trim((string)($session['metadata']['plan_code'] ?? ''));
    $subscriptionId = $session['subscription'] ?? null;
    if ($userId < 1 || $planId < 1) {
        return;
    }
    $plan = null;
    $stmt = $pdo->prepare("SELECT id, code, name, price_month, currency FROM plans WHERE id = :id AND is_active = 1 LIMIT 1");
    $stmt->execute(['id' => $planId]);
    $plan = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$plan) {
        $stmt = $pdo->prepare("SELECT id, code, name, price_month, currency FROM plans WHERE code = :code AND is_active = 1 LIMIT 1");
        $stmt->execute(['code' => $planCode]);
        $plan = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$plan) {
        return;
    }
    $planId = (int)$plan['id'];
    $providerRef = ($subscriptionId && stripe_subscriptions_have_provider_ref()) ? 'stripe:sub:' . $subscriptionId : null;
    $subId = null;
    if ($providerRef) {
        $chk = $pdo->prepare("SELECT id FROM subscriptions WHERE provider_ref = :ref LIMIT 1");
        $chk->execute(['ref' => $providerRef]);
        $subId = $chk->fetchColumn();
    }
    if (!$subId) {
        $start = gmdate('Y-m-d H:i:s');
        $end = gmdate('Y-m-d H:i:s', strtotime('+30 days'));
        if (stripe_subscriptions_have_provider_ref() && $providerRef) {
            $ins = $pdo->prepare("
                INSERT INTO subscriptions (user_id, plan_id, status, current_period_start, current_period_end, cancel_at_period_end, provider_ref)
                VALUES (:uid, :plan_id, 'active', :start, :end, 0, :provider_ref)
            ");
            $ins->execute([
                'uid' => $userId, 'plan_id' => $planId, 'start' => $start, 'end' => $end,
                'provider_ref' => $providerRef,
            ]);
        } else {
            $ins = $pdo->prepare("
                INSERT INTO subscriptions (user_id, plan_id, status, current_period_start, current_period_end, cancel_at_period_end)
                VALUES (:uid, :plan_id, 'active', :start, :end, 0)
            ");
            $ins->execute(['uid' => $userId, 'plan_id' => $planId, 'start' => $start, 'end' => $end]);
        }
        $subId = (int)$pdo->lastInsertId();
    }
    if (function_exists('audit_log')) {
        require_once __DIR__ . '/audit.php';
        audit_log('stripe_checkout_completed', 'subscription', (string)$subId);
    }
    if ($restaurantId > 0 && function_exists('billing_sync_restaurant_subscription')) {
        billing_sync_restaurant_subscription($restaurantId, (string)($plan['code'] ?? ''), 'active', $end ?? null);
    }
}

function stripe_handle_subscription_updated(array $sub, string $rid): void
{
    if (!stripe_subscriptions_have_provider_ref()) {
        return;
    }
    $pdo = db();
    $stripeSubId = $sub['id'] ?? '';
    if ($stripeSubId === '') {
        return;
    }
    $providerRef = 'stripe:sub:' . $stripeSubId;
    $status = $sub['status'] ?? '';
    $currentPeriodStart = $sub['current_period_start'] ?? null;
    $currentPeriodEnd = $sub['current_period_end'] ?? null;
    $cancelAtPeriodEnd = !empty($sub['cancel_at_period_end']);
    $canceledAt = isset($sub['canceled_at']) ? (int)$sub['canceled_at'] : null;

    $stmt = $pdo->prepare("SELECT id, user_id FROM subscriptions WHERE provider_ref = :ref LIMIT 1");
    $stmt->execute(['ref' => $providerRef]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return;
    }
    $start = $currentPeriodStart ? gmdate('Y-m-d H:i:s', $currentPeriodStart) : null;
    $end = $currentPeriodEnd ? gmdate('Y-m-d H:i:s', $currentPeriodEnd) : null;
    $localStatus = ($status === 'active' || $status === 'trialing') ? 'active' : $status;
    $upd = $pdo->prepare("
        UPDATE subscriptions
        SET status = :status, current_period_start = COALESCE(:start, current_period_start), current_period_end = COALESCE(:end, current_period_end),
            cancel_at_period_end = :cancel_at_end, canceled_at = :canceled_at, updated_at = CURRENT_TIMESTAMP
        WHERE id = :id
    ");
    $upd->execute([
        'status' => $localStatus,
        'start' => $start,
        'end' => $end,
        'cancel_at_end' => $cancelAtPeriodEnd ? 1 : 0,
        'canceled_at' => $canceledAt ? gmdate('Y-m-d H:i:s', $canceledAt) : null,
        'id' => $row['id'],
    ]);
}

function stripe_handle_subscription_deleted(array $sub, string $rid): void
{
    if (!stripe_subscriptions_have_provider_ref()) {
        return;
    }
    $pdo = db();
    $providerRef = 'stripe:sub:' . ($sub['id'] ?? '');
    if ($providerRef === 'stripe:sub:') {
        return;
    }
    $stmt = $pdo->prepare("UPDATE subscriptions SET status = 'canceled', canceled_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE provider_ref = :ref");
    $stmt->execute(['ref' => $providerRef]);
}

function stripe_handle_invoice_paid(array $invoice, string $rid): void
{
    $pdo = db();
    $stripeInvId = $invoice['id'] ?? '';
    $stripeSubId = $invoice['subscription'] ?? null;
    $amountPaid = isset($invoice['amount_paid']) ? ((int)$invoice['amount_paid']) / 100.0 : 0;
    $currency = strtoupper((string)($invoice['currency'] ?? 'rub'));
    $paymentIntent = $invoice['payment_intent'] ?? null;

    $chkInv = $pdo->prepare("SELECT id, user_id, subscription_id FROM invoices WHERE provider_invoice_id = :ref LIMIT 1");
    $chkInv->execute(['ref' => $stripeInvId]);
    $invRow = $chkInv->fetch(PDO::FETCH_ASSOC);
    $subId = null;
    $userId = null;
    if ($stripeSubId && stripe_subscriptions_have_provider_ref()) {
        $subChk = $pdo->prepare("SELECT id, user_id FROM subscriptions WHERE provider_ref = :ref LIMIT 1");
        $subChk->execute(['ref' => 'stripe:sub:' . $stripeSubId]);
        $subRow = $subChk->fetch(PDO::FETCH_ASSOC);
        if ($subRow) {
            $subId = (int)$subRow['id'];
            $userId = (int)$subRow['user_id'];
        }
    }
    if (!$invRow && $userId) {
        $periodStart = isset($invoice['period_start']) ? gmdate('Y-m-d H:i:s', $invoice['period_start']) : gmdate('Y-m-d H:i:s');
        $periodEnd = isset($invoice['period_end']) ? gmdate('Y-m-d H:i:s', $invoice['period_end']) : gmdate('Y-m-d H:i:s', strtotime('+30 days'));
        $insInv = $pdo->prepare("
            INSERT INTO invoices (user_id, subscription_id, amount, currency, status, period_start, period_end, provider, provider_invoice_id, issued_at, paid_at)
            VALUES (:uid, :sub_id, :amount, :currency, 'paid', :start, :end, 'stripe', :prov_inv_id, UTC_TIMESTAMP(), UTC_TIMESTAMP())
        ");
        $insInv->execute([
            'uid' => $userId, 'sub_id' => $subId, 'amount' => $amountPaid, 'currency' => $currency,
            'start' => $periodStart, 'end' => $periodEnd, 'prov_inv_id' => $stripeInvId,
        ]);
        $invoiceId = (int)$pdo->lastInsertId();
        $paymentRef = $paymentIntent ? 'stripe:pi:' . $paymentIntent : 'stripe:inv:' . $stripeInvId;
        $chkPay = $pdo->prepare("SELECT id FROM payments WHERE provider_ref = :ref LIMIT 1");
        $chkPay->execute(['ref' => $paymentRef]);
        if (!$chkPay->fetchColumn()) {
            $insPay = $pdo->prepare("
                INSERT INTO payments (user_id, invoice_id, amount, currency, status, provider, provider_ref)
                VALUES (:uid, :inv_id, :amount, :currency, 'succeeded', 'stripe', :provider_ref)
            ");
            $insPay->execute(['uid' => $userId, 'inv_id' => $invoiceId, 'amount' => $amountPaid, 'currency' => $currency, 'provider_ref' => $paymentRef]);
        }
        if (function_exists('audit_log')) {
            require_once __DIR__ . '/audit.php';
            audit_log('invoice_paid', 'invoice', (string)$invoiceId);
        }
        return;
    }
    if ($invRow) {
        $updInv = $pdo->prepare("UPDATE invoices SET status = 'paid', paid_at = UTC_TIMESTAMP() WHERE id = :id");
        $updInv->execute(['id' => $invRow['id']]);
        $paymentRef = $paymentIntent ? 'stripe:pi:' . $paymentIntent : 'stripe:inv:' . $stripeInvId;
        $chkPay = $pdo->prepare("SELECT id FROM payments WHERE provider_ref = :ref LIMIT 1");
        $chkPay->execute(['ref' => $paymentRef]);
        if (!$chkPay->fetchColumn()) {
            $insPay = $pdo->prepare("
                INSERT INTO payments (user_id, invoice_id, amount, currency, status, provider, provider_ref)
                VALUES (:uid, :inv_id, :amount, :currency, 'succeeded', 'stripe', :provider_ref)
            ");
            $insPay->execute([
                'uid' => $invRow['user_id'], 'inv_id' => $invRow['id'], 'amount' => $amountPaid, 'currency' => $currency,
                'provider_ref' => $paymentRef,
            ]);
        }
        if (function_exists('audit_log')) {
            require_once __DIR__ . '/audit.php';
            audit_log('invoice_paid', 'invoice', (string)$invRow['id']);
        }
    }
}

function stripe_handle_invoice_payment_failed(array $invoice, string $rid): void
{
    $pdo = db();
    $stripeInvId = $invoice['id'] ?? '';
    $paymentIntent = $invoice['payment_intent'] ?? null;
    $paymentRef = $paymentIntent ? 'stripe:pi:' . $paymentIntent : 'stripe:inv:fail:' . $stripeInvId;
    $chkPay = $pdo->prepare("SELECT id FROM payments WHERE provider_ref = :ref LIMIT 1");
    $chkPay->execute(['ref' => $paymentRef]);
    if ($chkPay->fetchColumn()) {
        return;
    }
    $subId = $invoice['subscription'] ?? null;
    $userId = null;
    if ($subId && stripe_subscriptions_have_provider_ref()) {
        $subChk = $pdo->prepare("SELECT user_id FROM subscriptions WHERE provider_ref = :ref LIMIT 1");
        $subChk->execute(['ref' => 'stripe:sub:' . $subId]);
        $row = $subChk->fetch(PDO::FETCH_ASSOC);
        $userId = $row ? (int)$row['user_id'] : 0;
    }
    if ($userId > 0) {
        $amount = isset($invoice['amount_due']) ? ((int)$invoice['amount_due']) / 100.0 : 0;
        $currency = strtoupper((string)($invoice['currency'] ?? 'rub'));
        $insPay = $pdo->prepare("
            INSERT INTO payments (user_id, invoice_id, amount, currency, status, provider, provider_ref)
            VALUES (:uid, NULL, :amount, :currency, 'failed', 'stripe', :provider_ref)
        ");
        $insPay->execute(['uid' => $userId, 'amount' => $amount, 'currency' => $currency, 'provider_ref' => $paymentRef]);
    }
    if (function_exists('audit_log')) {
        require_once __DIR__ . '/audit.php';
        audit_log('invoice_payment_failed', 'invoice', $stripeInvId);
    }
}
