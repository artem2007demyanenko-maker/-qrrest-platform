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
$user = auth_user();
$userId = $user ? (int)$user['id'] : 0;

$stripeEnabled = false;
$plans = [];
$subscription = null;
$accessSnapshot = [
    'status_key' => 'base_free',
    'status_label' => 'Базовый доступ',
    'status_heading' => 'Ресторан работает на базовом доступе',
    'status_text' => 'Можно запускать QR-меню и принимать заказы.',
    'cta_label' => 'Выбрать тариф',
    'days_left' => 0,
    'trial_ends_at' => null,
    'restaurant_plan_code' => 'free',
    'restaurant_plan_label' => 'FREE',
    'restaurant_plan_name' => 'Базовый запуск',
    'restaurant_plan_description' => '',
    'restaurant_plan_features' => [],
    'paid_plan_code' => 'growth',
    'paid_plan_label' => 'GROWTH',
    'paid_plan_name' => 'Рост повторной выручки',
    'paid_plan_features' => [],
    'is_trial' => false,
    'is_expired' => false,
    'has_active_paid_plan' => false,
];
$planCatalog = [];
$recommendedPlanCode = strtolower(trim((string)($_GET['plan'] ?? '')));

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
                if ($userId > 0) {
                    $result = billing_change_plan($userId, $planCode, $couponCode, (int)($currentRestaurant['id'] ?? 0));
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
    $subscription = billing_get_subscription($userId);
    $accessSnapshot = function_exists('billing_get_restaurant_access_snapshot')
        ? billing_get_restaurant_access_snapshot($userId, (int)($currentRestaurant['id'] ?? 0))
        : $accessSnapshot;
    $planCatalog = function_exists('billing_plan_catalog') ? billing_plan_catalog() : [];
}

if ($message === '' && isset($_GET['stripe'])) {
    if ($_GET['stripe'] === 'success') {
        $message = 'Оплата подтверждена. Проверьте статус доступа и вернитесь в dashboard.';
    } elseif ($_GET['stripe'] === 'cancel') {
        $error = 'Оплата была отменена. Можно выбрать другой тариф или повторить попытку позже.';
    }
}

if ($recommendedPlanCode === '') {
    $recommendedPlanCode = (string)($accessSnapshot['paid_plan_code'] ?? 'growth');
}
$activateMonetization = function_exists('billing_get_feature_paywall_context')
    ? billing_get_feature_paywall_context(
        $userId,
        (int)($currentRestaurant['id'] ?? 0),
        $recommendedPlanCode === 'pro' ? 'loyalty' : 'crm'
    )
    : null;
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
    <div class="max-w-5xl mx-auto px-4 py-8 space-y-6">
        <header class="mb-6">
            <a href="/restaurant/dashboard.php" class="text-sm text-slate-400 hover:text-slate-200">← В панель</a>
            <h1 class="text-2xl font-semibold text-slate-50 mt-2">Тариф и доступ ресторана</h1>
            <p class="text-slate-400 text-sm mt-1">Здесь видно текущий статус доступа, что уже открыто сейчас и какой следующий тарифный шаг лучше выбрать для роста.</p>
            <?php if ($billingReady && $subscription): ?>
            <p class="text-slate-500 text-xs mt-2">
                Billing-подписка: <?= e($subscription['plan_name']) ?>
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
                <p class="text-slate-400">Billing-слой пока недоступен. Обратитесь в поддержку или завершите миграции.</p>
                <a href="/restaurant/dashboard.php" class="inline-block mt-4 text-emerald-400 hover:underline">← В панель</a>
            </div>
        <?php elseif (empty($plans)): ?>
            <div class="rounded-2xl border border-slate-800 bg-slate-900/80 p-6 text-center">
                <p class="text-slate-400">Тарифы пока не настроены.</p>
                <a href="/restaurant/dashboard.php" class="inline-block mt-4 text-emerald-400 hover:underline">← В панель</a>
            </div>
        <?php else: ?>
            <?php
            $statusTone = 'sky';
            if (($accessSnapshot['status_key'] ?? '') === 'expired') {
                $statusTone = 'rose';
            } elseif (($accessSnapshot['status_key'] ?? '') === 'trial_ending') {
                $statusTone = 'amber';
            } elseif (($accessSnapshot['status_key'] ?? '') === 'active_paid') {
                $statusTone = 'emerald';
            } elseif (($accessSnapshot['status_key'] ?? '') === 'base_free') {
                $statusTone = 'slate';
            }
            $statusToneMap = [
                'sky' => 'border-sky-500/35 bg-sky-500/10 text-sky-200',
                'amber' => 'border-amber-500/35 bg-amber-500/10 text-amber-200',
                'rose' => 'border-rose-500/35 bg-rose-500/10 text-rose-200',
                'emerald' => 'border-emerald-500/35 bg-emerald-500/10 text-emerald-200',
                'slate' => 'border-slate-700 bg-slate-900/70 text-slate-200',
            ];
            $statusCardClass = $statusToneMap[$statusTone] ?? $statusToneMap['slate'];
            ?>
            <section class="rounded-3xl border p-5 md:p-6 <?= e($statusCardClass) ?>">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="max-w-3xl">
                        <div class="inline-flex items-center rounded-full border border-white/10 bg-white/5 px-3 py-1 text-[11px] font-semibold uppercase tracking-wide">
                            <?= e((string)($accessSnapshot['status_label'] ?? 'Статус доступа')) ?>
                        </div>
                        <h2 class="text-xl font-semibold text-white mt-3"><?= e((string)($accessSnapshot['status_heading'] ?? 'Статус доступа')) ?></h2>
                        <p class="text-sm mt-2 text-white/80 leading-relaxed"><?= e((string)($accessSnapshot['status_text'] ?? '')) ?></p>
                        <div class="mt-4 flex flex-wrap gap-2 text-xs text-white/70">
                            <span class="inline-flex items-center rounded-full border border-white/10 bg-white/5 px-3 py-1">
                                Текущий plan ресторана: <?= e((string)($accessSnapshot['restaurant_plan_label'] ?? 'FREE')) ?>
                            </span>
                            <?php if (!empty($accessSnapshot['is_trial']) && empty($accessSnapshot['is_expired'])): ?>
                                <span class="inline-flex items-center rounded-full border border-white/10 bg-white/5 px-3 py-1">
                                    Осталось <?= (int)($accessSnapshot['days_left'] ?? 0) ?> дн.
                                </span>
                            <?php elseif (!empty($accessSnapshot['trial_ends_at'])): ?>
                                <span class="inline-flex items-center rounded-full border border-white/10 bg-white/5 px-3 py-1">
                                    Trial до <?= e(date('d.m.Y', strtotime((string)$accessSnapshot['trial_ends_at']))) ?>
                                </span>
                            <?php endif; ?>
                            <?php if (!empty($accessSnapshot['has_active_paid_plan'])): ?>
                                <span class="inline-flex items-center rounded-full border border-white/10 bg-white/5 px-3 py-1">
                                    Платный тариф уже активен
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <a href="#plans" class="inline-flex items-center px-4 py-2 rounded-xl bg-white/10 hover:bg-white/15 text-white text-sm font-medium border border-white/10">
                        <?= e((string)($accessSnapshot['cta_label'] ?? 'Выбрать тариф')) ?>
                    </a>
                </div>

                <div class="grid gap-4 lg:grid-cols-2 mt-6">
                    <div class="rounded-2xl border border-white/10 bg-slate-950/40 p-4">
                        <div class="text-[11px] font-semibold uppercase tracking-wide text-white/60">Что уже доступно сейчас</div>
                        <div class="text-sm font-semibold text-white mt-2"><?= e((string)($accessSnapshot['restaurant_plan_name'] ?? 'Базовый запуск')) ?></div>
                        <p class="text-xs text-white/60 mt-1"><?= e((string)($accessSnapshot['restaurant_plan_description'] ?? '')) ?></p>
                        <ul class="mt-3 space-y-2 text-sm text-white/80">
                            <?php foreach ((array)($accessSnapshot['restaurant_plan_features'] ?? []) as $feature): ?>
                                <li class="flex items-start gap-2">
                                    <span class="mt-1 text-emerald-300">•</span>
                                    <span><?= e((string)$feature) ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <div class="rounded-2xl border border-white/10 bg-slate-950/40 p-4">
                        <div class="text-[11px] font-semibold uppercase tracking-wide text-white/60">Что откроет следующий тарифный шаг</div>
                        <div class="text-sm font-semibold text-white mt-2"><?= e((string)($accessSnapshot['paid_plan_name'] ?? 'Рост')) ?> · <?= e((string)($accessSnapshot['paid_plan_label'] ?? 'GROWTH')) ?></div>
                        <p class="text-xs text-white/60 mt-1"><?= e((string)($accessSnapshot['paid_plan_growth_outcome'] ?? '')) ?></p>
                        <ul class="mt-3 space-y-2 text-sm text-white/80">
                            <?php foreach ((array)($accessSnapshot['paid_plan_features'] ?? []) as $feature): ?>
                                <li class="flex items-start gap-2">
                                    <span class="mt-1 text-sky-300">•</span>
                                    <span><?= e((string)$feature) ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>

                <div class="mt-4 rounded-2xl border border-white/10 bg-slate-950/30 px-4 py-3 text-sm text-white/75">
                    <?php if (!empty($accessSnapshot['is_trial']) && empty($accessSnapshot['is_expired'])): ?>
                        После окончания trial система честно напомнит активировать тариф. Growth-экраны не будут маскироваться под “ошибку доступа”, а приведут сюда — в понятный owner-экран тарифа.
                    <?php elseif (!empty($accessSnapshot['is_expired'])): ?>
                        Trial уже завершён. После активации тарифа доступ продолжится без хаотичных ограничений: owner увидит тот же dashboard и сможет сразу вернуться в CRM, upsell и loyalty.
                    <?php else: ?>
                        Тариф уже активен. Здесь можно продлить доступ, перейти на следующий уровень growth-возможностей и понять, какой plan сейчас включён у ресторана.
                    <?php endif; ?>
                </div>

                <?php if (is_array($activateMonetization)): ?>
                <div class="mt-4 grid gap-4 lg:grid-cols-2">
                    <div class="rounded-2xl border border-white/10 bg-slate-950/30 p-4">
                        <div class="text-[11px] font-semibold uppercase tracking-wide text-white/60">Почему активировать имеет смысл сейчас</div>
                        <div class="text-sm font-semibold text-white mt-2"><?= e((string)($activateMonetization['why_now'] ?? '')) ?></div>
                        <p class="text-xs text-white/60 mt-2"><?= e((string)($activateMonetization['phase_text'] ?? '')) ?></p>
                    </div>
                    <div class="rounded-2xl border border-white/10 bg-slate-950/30 p-4">
                        <div class="text-[11px] font-semibold uppercase tracking-wide text-white/60">Что уже настроено и сохранится</div>
                        <ul class="mt-3 space-y-2 text-sm text-white/80">
                            <?php foreach ((array)($activateMonetization['proof_items'] ?? []) as $proof): ?>
                                <li class="flex items-start gap-2">
                                    <span class="mt-1 text-emerald-300">•</span>
                                    <span><?= e((string)$proof) ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <p class="text-xs text-white/60 mt-3"><?= e((string)($activateMonetization['preservation_text'] ?? '')) ?></p>
                    </div>
                </div>
                <?php endif; ?>
            </section>

            <?php if (!$stripeEnabled): ?>
            <div class="mb-4 rounded-xl bg-amber-500/10 border border-amber-500/50 px-4 py-2 text-sm text-amber-100">
                Онлайн-оплата пока не настроена. Для MVP можно активировать тариф вручную: это безопасный внутренний flow без реального списания.
            </div>
            <?php endif; ?>
            <section id="plans" class="space-y-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="text-xl font-semibold text-slate-100">Выберите следующий тарифный шаг</h2>
                        <p class="text-sm text-slate-400 mt-1">Цены можно менять позже. Сейчас главное — честно показать owner, что он активирует и какую бизнес-ценность это открывает.</p>
                    </div>
                    <div class="text-xs text-slate-500">
                        <?php if ($stripeEnabled): ?>
                            Оплата через Stripe или ручная активация
                        <?php else: ?>
                            Пока доступна ручная активация тарифа
                        <?php endif; ?>
                    </div>
                </div>

                <div class="grid gap-4 lg:grid-cols-2">
                <?php foreach ($plans as $plan): ?>
                    <?php if (($plan['code'] ?? '') === 'free' || (float)($plan['price_month'] ?? 0) <= 0) continue; ?>
                    <?php
                    $hasStripePrice = $stripeEnabled && stripe_price_id_for_plan($plan);
                    $planCode = strtolower(trim((string)($plan['code'] ?? '')));
                    $normalizedPlanCode = function_exists('billing_normalize_restaurant_plan_code')
                        ? billing_normalize_restaurant_plan_code($planCode)
                        : $planCode;
                    $meta = $planCatalog[$normalizedPlanCode] ?? [];
                    $isRecommended = ($normalizedPlanCode === $recommendedPlanCode);
                    $isCurrentRestaurantPlan = ($normalizedPlanCode === (string)($accessSnapshot['restaurant_plan_code'] ?? 'free'));
                    ?>
                    <div class="rounded-3xl border <?= $isRecommended ? 'border-indigo-500/50 bg-indigo-500/5' : 'border-slate-700 bg-slate-900/80' ?> p-5">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 class="text-lg font-medium text-slate-100"><?= e($meta['code_label'] ?? $plan['name']) ?></h3>
                                    <?php if ($isRecommended): ?>
                                        <span class="inline-flex items-center rounded-full border border-indigo-400/40 bg-indigo-500/10 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide text-indigo-200">Рекомендуем сейчас</span>
                                    <?php endif; ?>
                                    <?php if ($isCurrentRestaurantPlan): ?>
                                        <span class="inline-flex items-center rounded-full border border-emerald-400/40 bg-emerald-500/10 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide text-emerald-200">Текущий plan ресторана</span>
                                    <?php endif; ?>
                                </div>
                                <p class="text-slate-200 text-base mt-2"><?= e((string)($meta['name'] ?? $plan['name'])) ?></p>
                                <p class="text-slate-400 text-sm mt-1"><?= e((string)($meta['description'] ?? ($plan['description'] ?? ''))) ?></p>
                                <p class="text-emerald-400 font-semibold mt-3"><?= number_format((float)$plan['price_month'], 0, '.', ' ') ?> <?= e($plan['currency']) ?> / мес</p>
                                <?php if (!empty($meta['growth_outcome'])): ?>
                                    <p class="text-xs text-slate-500 mt-1"><?= e((string)$meta['growth_outcome']) ?></p>
                                <?php endif; ?>
                            </div>
                            <div class="text-xs text-slate-500">
                                <?= $hasStripePrice ? 'Stripe готов' : 'Ручная активация' ?>
                            </div>
                        </div>

                        <?php if (!empty($meta['features']) && is_array($meta['features'])): ?>
                        <ul class="mt-4 space-y-2 text-sm text-slate-300">
                            <?php foreach ($meta['features'] as $feature): ?>
                                <li class="flex items-start gap-2">
                                    <span class="mt-1 text-emerald-400">•</span>
                                    <span><?= e((string)$feature) ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <?php endif; ?>

                        <div class="mt-5 flex flex-wrap items-center gap-2">
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
                                <button type="submit" class="px-4 py-2 rounded-xl bg-slate-700 hover:bg-slate-600 text-white text-sm font-medium">
                                    <?= $isCurrentRestaurantPlan ? 'Продлить этот plan вручную' : 'Активировать вручную' ?>
                                </button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
