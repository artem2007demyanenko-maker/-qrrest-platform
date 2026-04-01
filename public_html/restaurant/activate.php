<?php
/**
 * Restaurant subscription activation: choose plan, paywall escape. Owner/admin only.
 */

$rid = bin2hex(random_bytes(4));
require_once __DIR__ . '/../../app/bootstrap.php';
set_exception_handler(function (Throwable $e) use ($rid) {
    error_log('STABILITY_ERROR restaurant/activate.php rid=' . $rid . ' ' . $e->getMessage());
    if (!headers_sent()) {
        http_response_code(500);
    }
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Ошибка</title></head><body><p>Что-то пошло не так.</p><p style="font-size:11px;color:#666">RID: ' . htmlspecialchars($rid) . '</p></body></html>';
    exit;
});

require_login();
require_current_restaurant();
require_restaurant_role((int)($currentRestaurant['id'] ?? 0), ['owner', 'admin']);

if (!function_exists('e')) {
    function e($v): string {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

if (file_exists(__DIR__ . '/../../app/schema_guard.php')) {
    require_once __DIR__ . '/../../app/schema_guard.php';
}
$billingReady = function_exists('schema_guard_billing_ready') && schema_guard_billing_ready();
$message = '';
$error = '';

$stripeEnabled = false;
if ($billingReady) {
    require_once __DIR__ . '/../../app/billing.php';
    if (file_exists(__DIR__ . '/../../app/stripe_billing.php')) {
        require_once __DIR__ . '/../../app/stripe_billing.php';
        $stripeEnabled = stripe_billing_enabled();
    }
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $csrfOk = isset($_SESSION['csrf'], $_POST['csrf']) && hash_equals((string)$_SESSION['csrf'], (string)$_POST['csrf']);
        if (!$csrfOk) {
            $error = 'Неверный запрос. Обновите страницу.';
        } else {
            $action = trim((string)($_POST['action'] ?? ''));
            if ($action === 'create_checkout_session' && $stripeEnabled) {
                $planCode = trim((string)($_POST['plan_code'] ?? ''));
                $planId = (int)($_POST['plan_id'] ?? 0);
                $user = auth_user();
                $userId = $user ? (int)$user['id'] : 0;
                if ($userId > 0 && $planCode !== '') {
                    $plans = billing_get_plans();
                    $plan = null;
                    foreach ($plans as $p) {
                        if ((string)$p['code'] === $planCode || (int)$p['id'] === $planId) {
                            $plan = $p;
                            break;
                        }
                    }
                    if ($plan && (float)($plan['price_month'] ?? 0) > 0) {
                        $config = require __DIR__ . '/../../app/config.php';
                        $baseUrl = $config['app']['url'] ?? '';
                        $protocol = $config['app']['protocol'] ?? 'https';
                        $mainDomain = $config['app']['main_domain'] ?? '';
                        $successUrl = $config['stripe']['success_url'] ?? $baseUrl . '/restaurant/dashboard.php?stripe=success';
                        $cancelUrl = $config['stripe']['cancel_url'] ?? $baseUrl . '/restaurant/activate.php?stripe=cancel';
                        $res = stripe_create_checkout_session([
                            'user_id' => $userId,
                            'restaurant_id' => $currentRestaurant ? (int)$currentRestaurant['id'] : null,
                            'plan_id' => (int)$plan['id'],
                            'plan_code' => $plan['code'],
                            'plan_name' => $plan['name'],
                            'success_url' => $successUrl,
                            'cancel_url' => $cancelUrl,
                            'customer_email' => $user['email'] ?? null,
                        ]);
                        if ($res['ok'] && !empty($res['checkout_url'])) {
                            header('Location: ' . $res['checkout_url'], true, 302);
                            exit;
                        }
                        $error = $res['error'] ?? 'Не удалось создать сессию оплаты.';
                    } else {
                        $error = 'Тариф не найден или бесплатный.';
                    }
                } else {
                    $error = 'Сессия истекла или не выбран тариф.';
                }
            } elseif ($action === 'choose_plan') {
                $planCode = trim((string)($_POST['plan_code'] ?? ''));
                $couponCode = trim((string)($_POST['coupon_code'] ?? ''));
                $user = auth_user();
                $userId = $user ? (int)$user['id'] : 0;
                if ($userId > 0) {
                    $result = billing_change_plan($userId, $planCode, $couponCode);
                    if ($result['ok']) {
                        header('Location: /restaurant/dashboard.php', true, 302);
                        exit;
                    }
                    $error = $result['message'] ?? 'Не удалось активировать тариф.';
                } else {
                    $error = 'Сессия истекла. Войдите снова.';
                }
            }
        }
    }

    $plans = billing_get_plans();
    $subscription = billing_get_subscription(auth_user() ? (int)auth_user()['id'] : 0);
} else {
    $plans = [];
    $subscription = null;
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?= brand_head_tags() ?>
    <title>Активировать подписку — <?= e($currentRestaurant['name']) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-950 text-slate-50">
<div class="min-h-screen bg-gradient-to-br from-slate-950 via-slate-900 to-slate-950">
    <div class="max-w-2xl mx-auto px-4 py-8">
        <header class="mb-6">
            <a href="/restaurant/dashboard.php" class="text-sm text-slate-400 hover:text-slate-200">← В панель</a>
            <h1 class="text-2xl font-semibold text-slate-50 mt-2">Активировать подписку</h1>
            <p class="text-slate-400 text-sm mt-1">Выберите тариф для продолжения работы</p>
            <?php if ($billingReady && $subscription): ?>
            <p class="text-slate-500 text-xs mt-2">
                Текущий тариф: <?= e($subscription['plan_name']) ?>
                <?php if (!empty($subscription['current_period_end'])): ?>
                    · Действует до <?= e(date('d.m.Y', strtotime($subscription['current_period_end']))) ?>
                <?php endif; ?>
                <?= $stripeEnabled ? ' · Оплата: Stripe' : ' · Оплата: вручную' ?>
            </p>
            <?php elseif ($billingReady && $stripeEnabled): ?>
            <p class="text-slate-500 text-xs mt-2">Оплата: Stripe</p>
            <?php endif; ?>
        </header>

        <?php if ($message): ?>
            <div class="mb-4 rounded-xl bg-emerald-500/10 border border-emerald-500/50 px-4 py-2 text-sm text-emerald-100"><?= e($message) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="mb-4 rounded-xl bg-red-500/10 border border-red-500/50 px-4 py-2 text-sm text-red-100"><?= e($error) ?></div>
        <?php endif; ?>

        <?php if (!$billingReady): ?>
            <div class="rounded-2xl border border-slate-800 bg-slate-900/80 p-6 text-center">
                <p class="text-slate-400">Billing unavailable. Обратитесь в поддержку.</p>
                <a href="/restaurant/dashboard.php" class="inline-block mt-4 text-emerald-400 hover:underline">← В панель</a>
            </div>
        <?php elseif (empty($plans)): ?>
            <div class="rounded-2xl border border-slate-800 bg-slate-900/80 p-6 text-center">
                <p class="text-slate-400">Тарифы пока не настроены.</p>
                <a href="/restaurant/dashboard.php" class="inline-block mt-4 text-emerald-400 hover:underline">← В панель</a>
            </div>
        <?php else: ?>
            <?php if (!$stripeEnabled): ?>
            <div class="mb-4 rounded-xl bg-amber-500/10 border border-amber-500/50 px-4 py-2 text-sm text-amber-100">
                Онлайн-оплата пока не настроена. Вы можете выбрать тариф вручную (активация без реальной оплаты).
            </div>
            <?php endif; ?>
            <div class="grid gap-4">
                <?php foreach ($plans as $plan): ?>
                    <?php if (($plan['code'] ?? '') === 'free' || (float)($plan['price_month'] ?? 0) <= 0) continue; ?>
                    <?php
                    $hasStripePrice = $stripeEnabled && stripe_price_id_for_plan($plan);
                    ?>
                    <div class="rounded-2xl border border-slate-700 bg-slate-900/80 p-4 flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h2 class="text-lg font-medium text-slate-100"><?= e($plan['name']) ?></h2>
                            <p class="text-slate-400 text-sm"><?= e($plan['description'] ?? '') ?></p>
                            <p class="text-emerald-400 font-semibold mt-1"><?= number_format((float)$plan['price_month'], 0, '.', ' ') ?> <?= e($plan['currency']) ?> / мес</p>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <?php if ($hasStripePrice): ?>
                            <form method="post" class="inline">
                                <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                                <input type="hidden" name="action" value="create_checkout_session">
                                <input type="hidden" name="plan_code" value="<?= e($plan['code']) ?>">
                                <input type="hidden" name="plan_id" value="<?= (int)$plan['id'] ?>">
                                <button type="submit" class="px-4 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-medium">Оплатить через Stripe</button>
                            </form>
                            <?php endif; ?>
                            <form method="post" class="inline">
                                <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                                <input type="hidden" name="action" value="choose_plan">
                                <input type="hidden" name="plan_code" value="<?= e($plan['code']) ?>">
                                <button type="submit" class="px-4 py-2 rounded-xl bg-slate-600 hover:bg-slate-500 text-white text-sm font-medium">Выбрать (без оплаты)</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
