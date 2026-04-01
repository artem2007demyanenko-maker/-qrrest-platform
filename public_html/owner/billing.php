<?php
// public_html/owner/billing.php — Тарифы и подписка (Variant 4)

$config     = require __DIR__ . '/../../app/config.php';
$mainDomain = $config['app']['main_domain'] ?? 'localhost';
$protocol   = $config['app']['protocol'] ?? 'http';
$host = $_SERVER['HTTP_HOST'] ?? '';
$host = preg_replace('/:\d+$/', '', $host);
$isMainHost = (strtolower($host) === strtolower($mainDomain)) || (strtolower($host) === strtolower('www.' . $mainDomain));
if (!$isMainHost) {
    $uri = $_SERVER['REQUEST_URI'] ?? '/owner/billing.php';
    safe_redirect($protocol . '://' . $mainDomain . $uri);
}

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/schema_guard.php';
require_once __DIR__ . '/../../app/billing.php';
if (file_exists(__DIR__ . '/../../app/activation_insights.php')) {
    require_once __DIR__ . '/../../app/activation_insights.php';
}

require_login();
require_role(['owner', 'project_owner']);
$user = auth_user();
$userId = (int)($user['id'] ?? 0);
if ($userId <= 0) {
    safe_redirect('/owner/dashboard.php');
}

if (!schema_guard_billing_ready()) {
    $debugEnabled = isset($_GET['debug']) && (($user['global_role'] ?? '') === 'owner');
    error_log('SCHEMA_MISSING billing user_id=' . $userId . ' uri=' . ($_SERVER['REQUEST_URI'] ?? ''));
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="ru"><head><meta charset="utf-8"><title>Тарифы</title></head><body class="min-h-screen bg-slate-950 text-slate-50 flex items-center justify-center"><div class="max-w-md p-6 text-center"><h1 class="text-xl font-semibold text-amber-200 mb-2">Billing unavailable</h1><p class="text-slate-400">Database migrations for billing have not been applied yet. Please apply migrations and try again.</p>';
    if ($debugEnabled) {
        echo '<p class="mt-4 text-xs text-slate-500 font-mono">Run app migrations (e.g. <code>mysql &lt; app/migrations/2026_03_04_billing.sql</code> and related).</p>';
    }
    echo '<p class="mt-4"><a href="/owner/dashboard.php" class="text-emerald-400 hover:underline">← Back to dashboard</a></p></div></body></html>';
    exit;
}

$flashMessage = (string)($_SESSION['billing_flash'] ?? '');
$flashError   = (string)($_SESSION['billing_flash_error'] ?? '');
unset($_SESSION['billing_flash'], $_SESSION['billing_flash_error']);

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

// POST: смена тарифа (user-level subscription, Stripe or similar)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'choose_plan') {
    $csrfOk = isset($_SESSION['csrf'], $_POST['csrf']) && hash_equals((string)$_SESSION['csrf'], (string)$_POST['csrf']);
    if (!$csrfOk) {
        $_SESSION['billing_flash_error'] = 'Неверный запрос. Обновите страницу.';
    } else {
        $planCode = trim((string)($_POST['plan_code'] ?? ''));
        $couponCode = trim((string)($_POST['coupon_code'] ?? ''));
        $result = billing_change_plan($userId, $planCode, $couponCode);
        if ($result['ok']) {
            $_SESSION['billing_flash'] = $result['message'] ?? 'Тариф изменён.';
        } else {
            $_SESSION['billing_flash_error'] = $result['message'] ?? 'Не удалось сменить тариф.';
        }
    }
    safe_redirect('/owner/billing.php');
}

// POST: отмена в конце периода
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel_at_end') {
    $csrfOk = isset($_SESSION['csrf'], $_POST['csrf']) && hash_equals((string)$_SESSION['csrf'], (string)$_POST['csrf']);
    if ($csrfOk) {
        billing_cancel_subscription($userId, true);
        $_SESSION['billing_flash'] = 'Подписка будет отменена в конце периода.';
    }
    safe_redirect('/owner/billing.php');
}

// POST: отмена немедленно
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel_now') {
    $csrfOk = isset($_SESSION['csrf'], $_POST['csrf']) && hash_equals((string)$_SESSION['csrf'], (string)$_POST['csrf']);
    if ($csrfOk) {
        billing_cancel_subscription($userId, false);
        $_SESSION['billing_flash'] = 'Подписка отменена.';
    }
    safe_redirect('/owner/billing.php');
}

$subscription = billing_ensure_default_subscription($userId);
$plans = billing_get_plans();
if (empty($plans)) {
    error_log('BILLING_PLANS_MISSING no active plans');
}
$history = billing_get_history($userId, 20);
$debugEnabled = isset($_GET['debug']) && (($user['global_role'] ?? '') === 'owner');

// Internal restaurant-level plan switching/testing guard
$config = require __DIR__ . '/../../app/config.php';
$isProdEnv = (($config['app']['env'] ?? 'local') === 'production');
$allowInternalPlanSwitch = !$isProdEnv;

// Resolve primary restaurant for this owner (for restaurant_subscriptions)
$primaryRestaurantId = 0;
if (function_exists('db')) {
    try {
        $pdo = db();
        $st = $pdo->prepare("SELECT id FROM restaurants WHERE owner_user_id = ? ORDER BY id ASC LIMIT 1");
        $st->execute([$userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $primaryRestaurantId = (int)$row['id'];
        }
    } catch (Throwable $e) {
        $primaryRestaurantId = 0;
    }
}

// POST: restaurant-level internal plan switch / trial (FREE/GROWTH/PRO)
if ($allowInternalPlanSwitch && $primaryRestaurantId > 0 && $_SERVER['REQUEST_METHOD'] === 'POST' && in_array(($_POST['action'] ?? ''), ['restaurant_plan_set', 'restaurant_plan_trial'], true)) {
    $csrfOk = isset($_SESSION['csrf'], $_POST['csrf']) && hash_equals((string)$_SESSION['csrf'], (string)$_POST['csrf']);
    if (!$csrfOk) {
        $_SESSION['billing_flash_error'] = 'Неверный запрос. Обновите страницу.';
        safe_redirect('/owner/billing.php');
    }
    if (!function_exists('db') || !function_exists('db_table_exists') || !db_table_exists('restaurant_subscriptions')) {
        $_SESSION['billing_flash_error'] = 'Внутренние планы ещё не доступны (нет таблицы restaurant_subscriptions).';
        safe_redirect('/owner/billing.php');
    }

    $planCode = strtolower(trim((string)($_POST['plan_code'] ?? '')));
    if (!in_array($planCode, ['free', 'growth', 'pro'], true)) {
        $_SESSION['billing_flash_error'] = 'Неизвестный тариф для ресторана.';
        safe_redirect('/owner/billing.php');
    }

    $isTrial = (($_POST['action'] ?? '') === 'restaurant_plan_trial') && ($planCode === 'growth' || $planCode === 'pro');
    $status = $isTrial ? 'trial' : 'active';
    $expiresAt = null;
    if ($isTrial) {
        $expiresAt = date('Y-m-d H:i:s', strtotime('+14 days'));
    }

    try {
        $pdo = db();
        // Upsert on unique restaurant_id, preserve created_at when row exists.
        $sql = "
            INSERT INTO restaurant_subscriptions (restaurant_id, plan, status, expires_at)
            VALUES (:rid, :plan, :status, :expires)
            ON DUPLICATE KEY UPDATE
                plan = VALUES(plan),
                status = VALUES(status),
                expires_at = VALUES(expires_at)
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':rid'    => $primaryRestaurantId,
            ':plan'   => $planCode,
            ':status' => $status,
            ':expires'=> $expiresAt,
        ]);
        if ($isTrial) {
            $_SESSION['billing_flash'] = 'Запущен trial тарифа ' . strtoupper($planCode) . ' для ресторана (14 дней).';
        } else {
            $_SESSION['billing_flash'] = 'Внутренний тариф ресторана переключён на ' . strtoupper($planCode) . '.';
        }
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('billing restaurant_plan_switch error user_id=' . $userId . ' ' . $e->getMessage());
        }
        $_SESSION['billing_flash_error'] = 'Не удалось изменить внутренний тариф ресторана.';
    }
    safe_redirect('/owner/billing.php');
}

// Plan comparison: use plans_config (FREE/GROWTH/PRO) for features and positioning; align with CRM/Upsell/Loyalty gates.
$plansConfig = [];
if (file_exists(__DIR__ . '/../../app/subscription_plans.php')) {
    require_once __DIR__ . '/../../app/subscription_plans.php';
    $plansConfig = function_exists('_subscription_plans_config') ? _subscription_plans_config() : [];
}
if (empty($plansConfig)) {
    $plansConfig = [
        'free'   => ['name' => 'Free', 'max_orders' => 50, 'crm_enabled' => false, 'upsell_enabled' => false, 'loyalty_enabled' => false],
        'growth' => ['name' => 'Growth', 'max_orders' => 500, 'crm_enabled' => true, 'upsell_enabled' => true, 'loyalty_enabled' => false],
        'pro'    => ['name' => 'Pro', 'max_orders' => null, 'crm_enabled' => true, 'upsell_enabled' => true, 'loyalty_enabled' => true],
    ];
}
$currentPlanCode = strtolower(trim((string)($subscription['plan_code'] ?? 'free')));
if ($currentPlanCode === 'trial') {
    $currentPlanCode = 'free';
}
if (!isset($plansConfig[$currentPlanCode])) {
    $currentPlanCode = 'free';
}

// Effective restaurant-level plan (FREE/GROWTH/PRO) and trial info
$restaurantPlan = ['plan' => 'free', 'status' => 'active'];
$restaurantPlanLabel = 'FREE';
$restaurantTrialUntil = null;
if ($primaryRestaurantId > 0 && function_exists('get_restaurant_plan')) {
    try {
        $restaurantPlan = get_restaurant_plan($primaryRestaurantId);
        $restaurantPlanLabel = strtoupper((string)($restaurantPlan['plan'] ?? 'free'));
        if (!in_array($restaurantPlanLabel, ['FREE', 'GROWTH', 'PRO'], true)) {
            $restaurantPlanLabel = 'FREE';
        }
        if (!function_exists('db') || !function_exists('db_table_exists') || !db_table_exists('restaurant_subscriptions')) {
            $restaurantTrialUntil = null;
        } else {
            $pdo = db();
            $st = $pdo->prepare("SELECT expires_at FROM restaurant_subscriptions WHERE restaurant_id = ? ORDER BY id DESC LIMIT 1");
            $st->execute([$primaryRestaurantId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row && !empty($row['expires_at'])) {
                $restaurantTrialUntil = $row['expires_at'];
            }
        }
    } catch (Throwable $e) {
        $restaurantPlan = ['plan' => 'free', 'status' => 'active'];
        $restaurantPlanLabel = 'FREE';
        $restaurantTrialUntil = null;
    }
}

// First restaurant of owner for usage display (safe if missing)
$usageRestaurantId = 0;
$usageOrders = null;
$usageMax = null;
$usagePercent = 0;
// Feature usage metrics for this month (per-restaurant, optional)
$usageCrmMessages = null;
$usageUpsellShown = null;
$usageUpsellAccepted = null;
$usageLoyaltyTx = null;
if (function_exists('db')) {
    try {
        $pdo = db();
        $stmt = $pdo->prepare("SELECT id FROM restaurants WHERE owner_user_id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $usageRestaurantId = (int)$row['id'];
        }
    } catch (Throwable $e) {
        // owner_user_id may not exist
    }
}
if ($usageRestaurantId > 0 && isset($plansConfig[$currentPlanCode]['max_orders'])) {
    $usageMax = $plansConfig[$currentPlanCode]['max_orders'];
    if ($usageMax === null) {
        $usageOrders = null;
        $usageMax = null;
    } else {
        $usageMax = (int)$usageMax;
        if (function_exists('db_table_exists') && function_exists('db') && db_table_exists('usage_metrics')) {
            try {
                $pdo = db();
                $periodStart = date('Y-m-01 00:00:00');
                $stmt = $pdo->prepare("SELECT value FROM usage_metrics WHERE restaurant_id = ? AND metric = 'orders' AND period_start = ? LIMIT 1");
                $stmt->execute([$usageRestaurantId, $periodStart]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $usageOrders = $row ? (int)$row['value'] : 0;
                $usagePercent = $usageMax > 0 ? (int)round($usageOrders * 100.0 / $usageMax) : 0;
            } catch (Throwable $e) {
                $usageOrders = 0;
            }
        } else {
            $usageOrders = 0;
        }
    }
}

// Feature usage metrics (crm_messages, upsell_shown, upsell_accepted, loyalty_transactions)
if ($usageRestaurantId > 0 && function_exists('get_monthly_usage_snapshot')) {
    $snap = get_monthly_usage_snapshot($usageRestaurantId);
    $usageCrmMessages = $snap['crm_messages'] ?? null;
    $usageUpsellShown = $snap['upsell_shown'] ?? null;
    $usageUpsellAccepted = $snap['upsell_accepted'] ?? null;
    $usageLoyaltyTx = $snap['loyalty_transactions'] ?? null;
}

// Limit state for orders (owner visibility; read-only)
$ordersLimitState = null;
if ($usageRestaurantId > 0 && function_exists('get_limit_state')) {
    $ordersLimitState = get_limit_state($usageRestaurantId, 'orders');
}

$planPositioning = [
    'free'   => 'Для старта и первых заказов',
    'growth' => 'Для роста среднего чека и возврата гостей',
    'pro'    => 'Для системной работы с удержанием и лояльностью',
];
$planKeysOrder = ['free', 'growth', 'pro'];

if (!function_exists('e')) {
    function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Тарифы и подписка — QR-Rest</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <style> body { font-family: Inter, system-ui, sans-serif; } </style>
</head>
<body class="min-h-screen text-gray-300 antialiased" style="background-color: #0B0F19;">
<div class="max-w-4xl mx-auto p-4">
    <header class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-semibold tracking-tight text-[#F3F4F6]">Тарифы и подписка</h1>
        <a href="/owner/dashboard.php" class="text-sm text-gray-400 hover:text-indigo-400 transition-colors">← Назад в кабинет</a>
    </header>

    <?php if ($flashMessage): ?>
        <div class="mb-4 p-3 rounded-xl bg-green-500/10 border border-green-500/30 text-green-400"><?= e($flashMessage) ?></div>
    <?php endif; ?>
    <?php if ($flashError): ?>
        <div class="mb-4 p-3 rounded-xl bg-red-500/10 border border-red-500/30 text-red-400"><?= e($flashError) ?></div>
    <?php endif; ?>

    <!-- A. Current plan -->
    <section class="mb-8 p-5 rounded-xl border border-gray-800 bg-[#121826] shadow-lg shadow-black/20">
        <h2 class="text-lg font-semibold tracking-tight text-[#F3F4F6] mb-2">Текущий тариф</h2>
        <p class="text-gray-300">
            <strong><?= e($subscription['plan_name'] ?? 'Бесплатный') ?></strong>
            <?php if (!empty($subscription['current_period_end'])): ?>
                • до <?= e(date('d.m.Y', strtotime($subscription['current_period_end']))) ?>
            <?php endif; ?>
        </p>
        <?php if (!empty($subscription['cancel_at_period_end']) && $subscription['cancel_at_period_end']): ?>
            <p class="text-amber-400 text-sm mt-1">Подписка будет отменена в конце периода.</p>
        <?php endif; ?>
        <?php if (!empty($subscription['status']) && $subscription['status'] === 'canceled'): ?>
            <p class="text-amber-400 text-sm mt-1">Подписка отменена. Выберите тариф ниже.</p>
        <?php endif; ?>
        <?php if ($usageMax !== null && $usageOrders !== null): ?>
        <div class="mt-4 pt-4 border-t border-gray-800">
            <div class="text-sm text-gray-400">Заказы в этом месяце</div>
            <p class="text-[#F3F4F6] font-medium mt-0.5"><?= (int)$usageOrders ?> / <?= (int)$usageMax ?> заказов использовано</p>
            <div class="mt-2 h-2 rounded-full bg-gray-800 overflow-hidden max-w-xs">
                <div class="h-full rounded-full bg-emerald-500 transition-all" style="width: <?= min(100, (int)$usagePercent) ?>%"></div>
            </div>
            <?php if ($ordersLimitState): ?>
                <p class="mt-1 text-xs text-gray-400">
                    <?php if ($ordersLimitState['state'] === 'ok'): ?>
                        Состояние лимита: в пределах тарифа.
                    <?php elseif ($ordersLimitState['state'] === 'warning'): ?>
                        Состояние лимита: вы приближаетесь к лимиту заказов.
                    <?php elseif ($ordersLimitState['state'] === 'exceeded'): ?>
                        Лимит заказов превышен: новые заказы пока продолжают приниматься (мягкий режим).
                        <?php if (!empty($ordersLimitState['allowed'])): ?>
                            Жёсткое ограничение не включено.
                        <?php endif; ?>
                    <?php endif; ?>
                </p>
            <?php endif; ?>
        </div>
        <?php elseif ($usageRestaurantId > 0 && isset($plansConfig[$currentPlanCode]['max_orders']) && $plansConfig[$currentPlanCode]['max_orders'] === null): ?>
        <div class="mt-4 pt-4 border-t border-gray-800">
            <div class="text-sm text-gray-400">Заказы</div>
            <p class="text-[#F3F4F6] font-medium mt-0.5">Безлимитные заказы</p>
        </div>
        <?php endif; ?>
        <div class="mt-4 pt-4 border-t border-gray-800 text-sm text-gray-400">
            <?php if ($primaryRestaurantId > 0): ?>
                Текущий тариф ресторана: <span class="font-medium text-gray-100"><?= e($restaurantPlanLabel) ?></span>
                <?php if (($restaurantPlan['status'] ?? '') === 'trial' && $restaurantTrialUntil): ?>
                    (trial до <?= e(date('d.m.Y', strtotime($restaurantTrialUntil))) ?>)
                <?php endif; ?>
            <?php else: ?>
                Текущий тариф ресторана: <span class="font-medium text-gray-100">FREE</span>
            <?php endif; ?>
            <?php if ($allowInternalPlanSwitch): ?>
                <span class="ml-2 text-xs text-amber-300">(внутренний режим тестирования тарифов ресторана)</span>
            <?php endif; ?>
        </div>
    </section>

    <!-- Activation & upgrade readiness (owner insights) -->
    <?php
    $activationInsights = null;
    $activationRestaurants = [];
    if (!is_demo_mode() && function_exists('get_owner_restaurants_activation_summary')) {
        $activationInsights = get_owner_restaurants_activation_summary($userId);
        $activationRestaurants = $activationInsights['restaurants'] ?? [];
    }
    $singleRestaurant = is_array($activationRestaurants) && count($activationRestaurants) === 1;
    ?>
    <?php if (!is_demo_mode() && !empty($activationRestaurants)): ?>
    <section class="mb-8 p-5 rounded-xl border border-gray-800 bg-[#121826] shadow-lg shadow-black/20">
        <h2 class="text-lg font-semibold tracking-tight text-[#F3F4F6] mb-2">Активация и готовность к апгрейду</h2>

        <?php if ($singleRestaurant):
            $r = $activationRestaurants[0];
            $ready = (string)($r['readiness'] ?? 'not_ready');
        ?>
            <div class="space-y-1.5 text-sm">
                <div class="text-gray-400">Ресторан: <span class="text-gray-200 font-medium"><?= e($r['name'] ?? '') ?></span></div>
                <div class="text-gray-300">Статус активации: <span class="text-gray-200 font-medium"><?= e($r['activation_status'] ?? '') ?></span></div>
                <div class="text-gray-300">Тариф ресторана: <span class="text-gray-200 font-medium"><?= e($r['current_plan'] ?? 'FREE') ?></span></div>
                <div class="text-gray-300">Готовность: <span class="text-gray-200 font-medium">
                    <?php if ($ready === 'ready_for_growth'): ?>
                        к GROWTH
                    <?php elseif ($ready === 'ready_for_pro'): ?>
                        к PRO
                    <?php else: ?>
                        пока рано
                    <?php endif; ?>
                </span></div>
                <div class="text-gray-400 text-xs">Оплаченные заказы (этот месяц): <?= (int)($r['orders_this_month'] ?? 0) ?></div>
                <?php if (!empty($r['reason'])): ?>
                    <div class="text-gray-400 text-xs pt-2">
                        Причина: <span class="text-gray-200"><?= e($r['reason']) ?></span>
                    </div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full text-sm text-left border border-gray-800 rounded-xl overflow-hidden">
                    <thead class="bg-gray-900/80">
                        <tr>
                            <th class="py-2 px-3 text-gray-400 font-medium">Ресторан</th>
                            <th class="py-2 px-3 text-gray-400 font-medium text-center">Статус</th>
                            <th class="py-2 px-3 text-gray-400 font-medium text-center">Тариф</th>
                            <th class="py-2 px-3 text-gray-400 font-medium text-center">Готовность</th>
                            <th class="py-2 px-3 text-gray-400 font-medium">Причина</th>
                            <th class="py-2 px-3 text-gray-400 font-medium text-right">Оплаченные заказы</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-800">
                        <?php foreach ($activationRestaurants as $r):
                            $ready = (string)($r['readiness'] ?? 'not_ready');
                        ?>
                        <tr>
                            <td class="py-2 px-3 text-gray-200"><?= e($r['name'] ?? '') ?></td>
                            <td class="py-2 px-3 text-center text-gray-300"><?= e($r['activation_status'] ?? '') ?></td>
                            <td class="py-2 px-3 text-center text-gray-300"><?= e($r['current_plan'] ?? 'FREE') ?></td>
                            <td class="py-2 px-3 text-center text-gray-300">
                                <?php if ($ready === 'ready_for_growth'): ?>
                                    к GROWTH
                                <?php elseif ($ready === 'ready_for_pro'): ?>
                                    к PRO
                                <?php else: ?>
                                    пока рано
                                <?php endif; ?>
                            </td>
                            <td class="py-2 px-3 text-gray-400 text-xs max-w-[280px] truncate" title="<?= e($r['reason'] ?? '') ?>">
                                <?= e($r['reason'] ?? '—') ?>
                            </td>
                            <td class="py-2 px-3 text-right text-gray-300"><?= (int)($r['orders_this_month'] ?? 0) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <!-- B. Feature activity this month -->
    <?php
    $hasFeatureUsage = ($usageCrmMessages !== null || $usageUpsellShown !== null || $usageUpsellAccepted !== null || $usageLoyaltyTx !== null);
    $isFree = ($currentPlanCode === 'free');
    $isGrowth = ($currentPlanCode === 'growth');
    $isPro = ($currentPlanCode === 'pro');
    ?>
    <?php if ($usageRestaurantId > 0 && ($hasFeatureUsage || $isFree || $isGrowth)): ?>
    <section class="mb-8 p-5 rounded-xl border border-gray-800 bg-[#121826] shadow-lg shadow-black/20">
        <h2 class="text-lg font-semibold tracking-tight text-[#F3F4F6] mb-2">Активность функций за этот месяц</h2>
        <div class="space-y-1.5 text-sm">
            <?php if ($usageCrmMessages !== null && $usageCrmMessages > 0): ?>
                <p class="text-gray-300">CRM: <?= (int)$usageCrmMessages ?> сообщений подготовлено в этом месяце.</p>
            <?php elseif ($isFree): ?>
                <p class="text-gray-400">CRM: недоступно на тарифе FREE. На GROWTH вы сможете готовить и отправлять кампании возврата гостей.</p>
            <?php endif; ?>

            <?php if ($usageUpsellShown !== null || $usageUpsellAccepted !== null): ?>
                <p class="text-gray-300">
                    Upsell:
                    <?php if ($usageUpsellShown !== null && $usageUpsellShown > 0): ?>
                        <?= (int)$usageUpsellShown ?> показа<?= ($usageUpsellAccepted !== null && $usageUpsellAccepted > 0) ? ',' : '' ?>
                    <?php endif; ?>
                    <?php if ($usageUpsellAccepted !== null && $usageUpsellAccepted > 0): ?>
                        <?= (int)$usageUpsellAccepted ?> добавлений
                    <?php endif; ?>
                </p>
            <?php elseif ($isFree): ?>
                <p class="text-gray-400">Upsell: недоступен на тарифе FREE. На GROWTH вы сможете увеличивать средний чек за счёт рекомендаций к заказу.</p>
            <?php endif; ?>

            <?php if ($usageLoyaltyTx !== null && $usageLoyaltyTx > 0): ?>
                <p class="text-gray-300">Loyalty: <?= (int)$usageLoyaltyTx ?> операций с баллами за этот месяц.</p>
            <?php elseif ($isGrowth): ?>
                <p class="text-gray-400">Loyalty: доступна на тарифе PRO. Подключите программу лояльности, чтобы начислять баллы и усиливать повторные визиты.</p>
            <?php endif; ?>
        </div>

        <?php
        $nearLimit = ($usageMax !== null && $usageOrders !== null && $usageMax > 0 && $usageOrders >= (int)round($usageMax * 0.6));
        ?>
        <?php if ($isFree && $nearLimit): ?>
            <p class="mt-3 text-xs text-amber-200">
                Вы уже активно используете заказы — CRM и upsell на тарифе GROWTH помогут вам расти дальше.
            </p>
        <?php elseif ($isGrowth && (($usageCrmMessages ?? 0) > 0 || ($usageUpsellShown ?? 0) > 0 || ($usageUpsellAccepted ?? 0) > 0)): ?>
            <p class="mt-3 text-xs text-amber-200">
                Вы уже используете growth-функции — loyalty на PRO поможет усилить повторные визиты.
            </p>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <!-- B. Plan comparison cards -->
    <section class="mb-8">
        <h2 class="text-lg font-semibold tracking-tight text-[#F3F4F6] mb-4">Сравнение тарифов</h2>
        <div class="grid gap-4 md:grid-cols-3">
            <?php
            $dbPlansByCode = [];
            foreach ($plans as $p) {
                $dbPlansByCode[strtolower((string)$p['code'])] = $p;
            }
            foreach ($planKeysOrder as $key):
                if (!isset($plansConfig[$key])) continue;
                $cfg = $plansConfig[$key];
                $name = (string)($cfg['name'] ?? $key);
                $isCurrent = ($key === $currentPlanCode);
                $dbPlan = $dbPlansByCode[$key] ?? null;
                $priceMonth = $dbPlan !== null ? (float)($dbPlan['price_month'] ?? 0) : 0;
            ?>
            <div class="p-5 rounded-xl border bg-[#121826] shadow-lg shadow-black/20 transition-all duration-200 <?= $isCurrent ? 'border-emerald-500/60 ring-2 ring-emerald-500/20' : 'border-gray-800 hover:border-gray-700' ?>">
                <?php if ($isCurrent): ?>
                <div class="inline-block px-2 py-0.5 rounded-md bg-emerald-500/20 text-emerald-300 text-xs font-medium mb-2">Текущий тариф</div>
                <?php endif; ?>
                <h3 class="font-semibold text-lg tracking-tight text-[#F3F4F6]"><?= e($name) ?></h3>
                <p class="text-sm text-gray-400 mt-1"><?= e($planPositioning[$key] ?? '') ?></p>
                <p class="mt-2 text-[#22C55E] font-medium">
                    <?php if ($priceMonth > 0): ?>
                        <?= number_format($priceMonth, 0, '.', ' ') ?> ₽/мес
                    <?php else: ?>
                        Бесплатно
                    <?php endif; ?>
                </p>
                <ul class="mt-3 space-y-1.5 text-sm text-gray-300">
                    <li>• QR-заказы</li>
                    <li>• <?php
                        $max = $cfg['max_orders'] ?? null;
                        if ($max === null) echo 'Безлимит заказов';
                        else echo (int)$max . ' заказов / мес';
                    ?></li>
                    <li>• CRM: <?= !empty($cfg['crm_enabled']) ? 'да' : 'нет' ?></li>
                    <li>• Умные допродажи: <?= !empty($cfg['upsell_enabled']) ? 'да' : 'нет' ?></li>
                    <li>• Лояльность: <?= !empty($cfg['loyalty_enabled']) ? 'да' : 'нет' ?></li>
                </ul>
                <div class="mt-4 space-y-2">
                    <?php if ($isCurrent): ?>
                        <p class="text-sm text-gray-500">Активный тариф подписки</p>
                    <?php else: ?>
                        <form method="post" class="space-y-2">
                            <input type="hidden" name="action" value="choose_plan">
                            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf'] ?? '') ?>">
                            <input type="hidden" name="plan_code" value="<?= e($key) ?>">
                            <input type="text" name="coupon_code" value="" placeholder="Промокод (необяз.)" class="w-full py-2 px-3 rounded-lg bg-gray-900 border border-gray-700 text-sm text-[#F3F4F6] placeholder:text-gray-500 focus:outline-none focus:ring-2 focus:ring-indigo-500">
                            <button type="submit" class="w-full py-2.5 px-3 rounded-lg bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-medium">
                                Выбрать <?= e($name) ?>
                            </button>
                        </form>
                    <?php endif; ?>

                    <?php if ($allowInternalPlanSwitch && $primaryRestaurantId > 0): ?>
                        <div class="pt-2 border-t border-gray-800">
                            <div class="text-xs text-gray-500 mb-1">Внутренний тариф ресторана</div>
                            <div class="flex flex-col gap-1">
                                <form method="post" class="flex gap-2">
                                    <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf'] ?? '') ?>">
                                    <input type="hidden" name="plan_code" value="<?= e($key) ?>">
                                    <input type="hidden" name="action" value="restaurant_plan_set">
                                    <button type="submit" class="flex-1 py-1.5 px-3 rounded-lg bg-slate-800 hover:bg-slate-700 text-[11px] text-slate-100">
                                        Установить <?= e(strtoupper($key)) ?> для ресторана
                                    </button>
                                </form>
                                <?php if (in_array($key, ['growth','pro'], true)): ?>
                                <form method="post" class="flex gap-2">
                                    <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf'] ?? '') ?>">
                                    <input type="hidden" name="plan_code" value="<?= e($key) ?>">
                                    <input type="hidden" name="action" value="restaurant_plan_trial">
                                    <button type="submit" class="flex-1 py-1.5 px-3 rounded-lg bg-amber-700/60 hover:bg-amber-600/70 text-[11px] text-amber-50">
                                        Запустить trial <?= e(strtoupper($key)) ?> (14 дней)
                                    </button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
                <?php if ($key === 'free'): ?>
                <div class="mt-3 p-2 rounded-lg bg-amber-500/10 border border-amber-500/30 text-xs text-amber-200">
                    Начните принимать заказы и отслеживать рост. Откройте CRM и upsell на тарифе GROWTH.
                </div>
                <?php elseif ($key === 'growth'): ?>
                <div class="mt-3 p-2 rounded-lg bg-sky-500/10 border border-sky-500/30 text-xs text-sky-200">
                    Вы уже используете CRM и upsell для роста выручки. Добавьте loyalty на PRO, чтобы усилить удержание.
                </div>
                <?php elseif ($key === 'pro'): ?>
                <div class="mt-3 p-2 rounded-lg bg-violet-500/10 border border-violet-500/30 text-xs text-violet-200">
                    Полный набор инструментов роста для ресторана.
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- Feature comparison table -->
    <section class="mb-8 overflow-x-auto">
        <h2 class="text-lg font-semibold tracking-tight text-[#F3F4F6] mb-4">Возможности по тарифам</h2>
        <table class="w-full text-sm text-left border border-gray-800 rounded-xl overflow-hidden">
            <thead>
                <tr class="bg-gray-900/80">
                    <th class="py-3 px-4 text-gray-400 font-medium">Возможность</th>
                    <th class="py-3 px-4 text-center text-gray-400 font-medium">FREE</th>
                    <th class="py-3 px-4 text-center text-gray-400 font-medium">GROWTH</th>
                    <th class="py-3 px-4 text-center text-gray-400 font-medium">PRO</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-800">
                <tr><td class="py-2 px-4 text-gray-300">QR-заказы</td><td class="py-2 px-4 text-center text-emerald-400">✓</td><td class="py-2 px-4 text-center text-emerald-400">✓</td><td class="py-2 px-4 text-center text-emerald-400">✓</td></tr>
                <tr><td class="py-2 px-4 text-gray-300">Заказов в месяц</td><td class="py-2 px-4 text-center text-gray-300">50</td><td class="py-2 px-4 text-center text-gray-300">500</td><td class="py-2 px-4 text-center text-gray-300">Безлимит</td></tr>
                <tr><td class="py-2 px-4 text-gray-300">CRM — возврат гостей</td><td class="py-2 px-4 text-center text-gray-500">—</td><td class="py-2 px-4 text-center text-emerald-400">✓</td><td class="py-2 px-4 text-center text-emerald-400">✓</td></tr>
                <tr><td class="py-2 px-4 text-gray-300">Умные допродажи</td><td class="py-2 px-4 text-center text-gray-500">—</td><td class="py-2 px-4 text-center text-emerald-400">✓</td><td class="py-2 px-4 text-center text-emerald-400">✓</td></tr>
                <tr><td class="py-2 px-4 text-gray-300">Программа лояльности</td><td class="py-2 px-4 text-center text-gray-500">—</td><td class="py-2 px-4 text-center text-gray-500">—</td><td class="py-2 px-4 text-center text-emerald-400">✓</td></tr>
                <tr><td class="py-2 px-4 text-gray-300">Аналитика и дашборд</td><td class="py-2 px-4 text-center text-emerald-400">✓</td><td class="py-2 px-4 text-center text-emerald-400">✓</td><td class="py-2 px-4 text-center text-emerald-400">✓</td></tr>
            </tbody>
        </table>
    </section>

    <?php if (!empty($subscription['status']) && in_array($subscription['status'], ['active', 'trial'], true) && empty($subscription['cancel_at_period_end'])): ?>
    <section class="mb-8">
        <h2 class="text-lg font-semibold mb-2">Отмена подписки</h2>
        <div class="flex gap-2">
            <form method="post">
                <input type="hidden" name="action" value="cancel_at_end">
                <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf'] ?? '') ?>">
                <button type="submit" class="py-2 px-4 rounded-lg bg-amber-900/50 border border-amber-600/50 hover:bg-amber-800/50 text-sm">Отменить в конце периода</button>
            </form>
            <form method="post" onsubmit="return confirm('Отменить подписку сейчас?');">
                <input type="hidden" name="action" value="cancel_now">
                <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf'] ?? '') ?>">
                <button type="submit" class="py-2 px-4 rounded-lg bg-red-900/50 border border-red-600/50 hover:bg-red-800/50 text-sm">Отменить сейчас</button>
            </form>
        </div>
    </section>
    <?php endif; ?>

    <section class="mb-8">
        <h2 class="text-lg font-semibold mb-2">История счетов и платежей</h2>
        <div class="overflow-x-auto">
            <table class="w-full text-sm text-left">
                <thead>
                    <tr class="border-b border-slate-700">
                        <th class="py-2 pr-2">Дата</th>
                        <th class="py-2 pr-2">Сумма</th>
                        <th class="py-2 pr-2">Статус</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $shown = 0;
                    foreach (array_slice($history['invoices'] ?? [], 0, 20) as $inv):
                        $shown++;
                    ?>
                        <tr class="border-b border-slate-800">
                            <td class="py-1 pr-2"><?= e(date('d.m.Y H:i', strtotime($inv['created_at'] ?? $inv['issued_at'] ?? 'now'))) ?></td>
                            <td class="py-1 pr-2"><?= number_format((float)($inv['amount'] ?? 0), 0, '.', ' ') ?> ₽</td>
                            <td class="py-1 pr-2"><?= e($inv['status'] ?? '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($shown === 0): ?>
                        <tr><td colspan="3" class="py-2 text-slate-500">Нет счетов</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <?php if ($debugEnabled): ?>
    <section class="mt-6 p-4 rounded-xl bg-slate-900/80 border border-amber-500/50 text-xs font-mono">
        <h3 class="text-amber-200 mb-2">Debug (owner)</h3>
        <pre class="whitespace-pre-wrap overflow-x-auto"><?= e(json_encode([
            'subscription' => $subscription,
            'invoices_count' => count($history['invoices'] ?? []),
            'payments_count' => count($history['payments'] ?? []),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
    </section>
    <?php endif; ?>
</div>
</body>
</html>
