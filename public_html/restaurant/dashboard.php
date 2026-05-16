<?php

require_once __DIR__ . '/../../app/bootstrap.php';
require_login();
require_current_restaurant();
require_current_restaurant_role(['owner', 'admin']);
require_restaurant_role((int)($currentRestaurant['id'] ?? 0), ['owner', 'admin']);
if (file_exists(__DIR__ . '/../../app/schema_guard.php')) {
    require_once __DIR__ . '/../../app/schema_guard.php';
}
if (file_exists(__DIR__ . '/../../app/runtime_schema_bootstrap.php')) {
    require_once __DIR__ . '/../../app/runtime_schema_bootstrap.php';
}

if (file_exists(__DIR__ . '/../../app/billing.php')) {
    require_once __DIR__ . '/../../app/billing.php';
}
if (file_exists(__DIR__ . '/../../app/trial_guard.php')) {
    require_once __DIR__ . '/../../app/trial_guard.php';
}
$trialInfo = function_exists('trial_guard_trial_info') ? trial_guard_trial_info() : ['is_trial' => false, 'days_left' => 0, 'is_expired' => false, 'has_active_paid_plan' => false];

$restId = (int)$currentRestaurant['id'];
$authUser = auth_user();
$pdo = db();
if ($pdo instanceof PDO && function_exists('runtime_schema_ensure_usage_metrics_daily')) {
    runtime_schema_ensure_usage_metrics_daily($pdo);
}
$billingAccess = function_exists('billing_get_restaurant_access_snapshot')
    ? billing_get_restaurant_access_snapshot((int)($authUser['id'] ?? 0), $restId)
    : [
        'status_key' => 'base_free',
        'status_label' => 'Базовый доступ',
        'status_heading' => 'Ресторан работает на базовом доступе',
        'status_text' => '',
        'cta_label' => 'Выбрать тариф',
        'days_left' => 0,
        'restaurant_plan_label' => 'FREE',
        'restaurant_plan_name' => 'Базовый запуск',
        'restaurant_plan_features' => [],
        'paid_plan_label' => 'GROWTH',
        'paid_plan_name' => 'Рост повторной выручки',
        'paid_plan_features' => [],
        'trial_ends_at' => null,
        'is_trial' => false,
        'is_expired' => false,
        'has_active_paid_plan' => false,
    ];
$dashboardMonetization = function_exists('billing_get_feature_paywall_context')
    ? billing_get_feature_paywall_context(
        (int)($authUser['id'] ?? 0),
        $restId,
        (($billingAccess['paid_plan_code'] ?? 'growth') === 'pro') ? 'loyalty' : 'crm'
    )
    : null;

// Soft plan/feature context for upgrade nudges (demo unchanged).
$crmEnabled = true;
$upsellEnabled = true;
$loyaltyEnabled = true;
$planKey = 'free';
$planLabel = 'FREE';
$ordersUsed = null;
$ordersLimit = null;
$ordersUsagePercent = 0;
$usageCrmMessages = 0;
$usageUpsellShown = 0;
$usageUpsellAccepted = 0;
$usageLoyaltyTx = 0;
$upgradeReadiness = null;
$dashboardSchemaWarnings = [];
$dashboardSchema = [
    'orders' => function_exists('db_table_exists') ? db_table_exists('orders') : true,
    'guests' => function_exists('db_table_exists') ? db_table_exists('guests') : true,
    'loyalty_transactions' => function_exists('db_table_exists') ? db_table_exists('loyalty_transactions') : true,
    'usage_metrics_daily' => function_exists('db_table_exists') ? db_table_exists('usage_metrics_daily') : true,
];
foreach ($dashboardSchema as $tableName => $tableReady) {
    if (!$tableReady) {
        // Keep dashboard working in read-only mode when migrations are partial.
        $dashboardSchemaWarnings[] = 'Данные ограничены: таблица ' . $tableName . ' отсутствует.';
        error_log('DASHBOARD_SCHEMA_MISSING table=' . $tableName . ' restaurant_id=' . $restId);
    }
}

if (!is_demo_mode() && file_exists(__DIR__ . '/../../app/subscription_plans.php')) {
    require_once __DIR__ . '/../../app/subscription_plans.php';
    $crmEnabled = function_exists('check_feature') && check_feature($restId, 'crm_enabled');
    $upsellEnabled = function_exists('check_feature') && check_feature($restId, 'upsell_enabled');
    $loyaltyEnabled = function_exists('check_feature') && check_feature($restId, 'loyalty_enabled');

    if (function_exists('get_restaurant_plan') && function_exists('_subscription_plans_config')) {
        $planData = get_restaurant_plan($restId);
        $planKey = strtolower((string)($planData['plan'] ?? 'free'));
        if ($planKey !== 'growth' && $planKey !== 'pro') {
            $planKey = 'free';
        }
        $planLabel = strtoupper($planKey);

        $cfg = _subscription_plans_config();
        if (isset($cfg[$planKey]['max_orders'])) {
            $max = $cfg[$planKey]['max_orders'];
            if ($max !== null) {
                $ordersLimit = (int)$max;
            }
        }
        if (function_exists('get_monthly_usage_snapshot') && $dashboardSchema['usage_metrics_daily']) {
            $snap = get_monthly_usage_snapshot($restId);
            $ordersUsed = $snap['orders'] ?? null;
            $usageCrmMessages = (int)($snap['crm_messages'] ?? 0);
            $usageUpsellShown = (int)($snap['upsell_shown'] ?? 0);
            $usageUpsellAccepted = (int)($snap['upsell_accepted'] ?? 0);
            $usageLoyaltyTx = (int)($snap['loyalty_transactions'] ?? 0);
            if ($ordersLimit !== null && $ordersLimit > 0 && $ordersUsed !== null) {
                $ordersUsagePercent = (int)round($ordersUsed * 100.0 / $ordersLimit);
            }
        } else {
            // Fallback: keep KPI cards consistent even when usage metrics schema is not ready.
            $ordersUsed = null;
            $usageCrmMessages = 0;
            $usageUpsellShown = 0;
            $usageUpsellAccepted = 0;
            $usageLoyaltyTx = 0;
        }
    }
}

// Canonical upgrade readiness (read-only) for stronger, non-spammy nudges.
// IMPORTANT: require the helper before checking function_exists().
if (!is_demo_mode() && file_exists(__DIR__ . '/../../app/activation_insights.php')) {
    require_once __DIR__ . '/../../app/activation_insights.php';
    $upgradeReadiness = function_exists('get_upgrade_readiness_snapshot') ? get_upgrade_readiness_snapshot($restId) : null;
}

if (file_exists(__DIR__ . '/../../app/guest_retention.php')) {
    require_once __DIR__ . '/../../app/guest_retention.php';
}
if (file_exists(__DIR__ . '/../../app/network_benchmark.php')) {
    require_once __DIR__ . '/../../app/network_benchmark.php';
}

$referralCode = '';
$referralLink = '';
$invitedCount = 0;
if (!is_demo_mode() && $restId > 0 && file_exists(__DIR__ . '/../../app/referral_repo.php')) {
    require_once __DIR__ . '/../../app/referral_repo.php';
    $config = require __DIR__ . '/../../app/config.php';
    $mainDomain = $config['app']['main_domain'] ?? 'lvh.me';
    $protocol   = $config['app']['protocol'] ?? 'http';
    $referralCode = referral_ensure_code_for_restaurant($restId);
    $referralLink = $referralCode !== '' ? $protocol . '://' . $mainDomain . '/signup.php?ref=' . urlencode($referralCode) : ($protocol . '://' . $mainDomain . '/signup.php');
    $invitedCount = referral_count_invited($restId);
}
$demoShareUrl = '';
if (is_demo_mode()) {
    $config = require __DIR__ . '/../../app/config.php';
    $mainDomain = $config['app']['main_domain'] ?? 'lvh.me';
    $protocol   = $config['app']['protocol'] ?? 'http';
    $demoShareUrl = $protocol . '://demo.' . $mainDomain . '/restaurant/dashboard.php';
}

$onboardingProgress = ['completed_steps' => [], 'next_step' => null, 'percent_complete' => 100];
$restaurantBaseUrl = '';
if (!is_demo_mode() && $restId > 0 && file_exists(__DIR__ . '/../../app/onboarding_progress.php')) {
    require_once __DIR__ . '/../../app/onboarding_progress.php';
    $onboardingProgress = get_onboarding_progress($restId);
    $config = require __DIR__ . '/../../app/config.php';
    $mainDomain = $config['app']['main_domain'] ?? 'lvh.me';
    $protocol   = $config['app']['protocol'] ?? 'http';
    $restaurantBaseUrl = $protocol . '://' . ($currentRestaurant['subdomain'] ?? '') . '.' . $mainDomain;
}

// Restaurant activation funnel (usage-aware, read-only widget).
if (!is_demo_mode() && $restId > 0 && file_exists(__DIR__ . '/../../app/onboarding.php')) {
    require_once __DIR__ . '/../../app/onboarding.php';
}

// Growth experiments: assign restaurant to running experiments on first dashboard load (once per experiment); record metrics for assigned experiments.
if (!is_demo_mode() && $restId > 0 && file_exists(__DIR__ . '/../../app/experiments.php')) {
    require_once __DIR__ . '/../../app/experiments.php';
    $running = get_running_experiments();
    foreach ($running as $exp) {
        $expId = (int) $exp['id'];
        if (!experiment_restaurant_in_target($restId, $expId, (int) $exp['target_percentage'])) {
            continue;
        }
        assign_restaurant_to_experiment($restId, $expId);
        if (get_restaurant_variant($restId, $expId) !== '') {
            record_experiment_metric($restId, $expId);
        }
    }
}

$yesterdayStats   = null;
$guestsToday      = null;
$revenueTrend7    = [];
$crmSummary       = null;
$retentionStats   = [];
$loyalGuestsCount = 0;
$upsellSummary    = null;
$dashboardInsights = [];
$recommendedActions = [];
$hasUpsellRules   = false;
$aiUpsellSuggestions = [];
$aiUpsellOrderCount = 0;
$upsellOptimization = ['best_pair' => null, 'weakest_pair' => null, 'suggestions' => []];
$menuPerformanceInsights = [];
$growthInsights = [];
$guestReturnSummary = ['candidates_count' => 0, 'headline' => '', 'estimated_recovered' => 0];
$guestReturnCandidates = [];
$menuIntelligence = ['insights' => [], 'opportunities' => [], 'warnings' => []];
$comboSuggestions = ['suggestions' => [], 'order_count' => 0];
$tableTurnover = ['tables' => [], 'busiest_table' => '', 'has_data' => false, 'recommendation_text' => ''];
$benchmarkData = ['available' => false];
$retentionBusinessSummary = [
    'drafts_created' => 0,
    'unique_guests_targeted' => 0,
    'returned_guests' => 0,
    'paid_orders_after_draft' => 0,
    'returned_revenue' => 0.0,
    'scenarios_active' => 0,
    'last_draft_at' => null,
    'best_scenario' => null,
];
$retentionWorkflowSummary = [
    'total' => 0,
    'pending' => 0,
    'draft' => 0,
    'ready_manual' => 0,
    'processed' => 0,
    'canceled' => 0,
    'failed' => 0,
    'loyalty_rows' => 0,
    'loyalty_pending' => 0,
    'loyalty_draft' => 0,
    'loyalty_ready_manual' => 0,
    'loyalty_processed' => 0,
    'loyalty_in_work' => 0,
    'fallback_rows' => 0,
    'recent' => [],
];
$retentionPriorityQueue = [];
$upsellOptimizationDashboard = [
    'summary' => ['pairs_analyzed' => 0, 'strong_pairs' => 0, 'weak_pairs' => 0, 'low_data_pairs' => 0],
    'top_performing' => [],
    'needs_attention' => [],
    'low_data' => [],
];
$managerBusinessActions = [];

if (is_demo_mode()) {
    $todayStats       = demo_dashboard_today_stats();
    $statusCounts     = demo_dashboard_status_counts();
    $lastOrders       = demo_dashboard_orders();
    $topItems         = demo_dashboard_top_items();
    $guestsToday      = demo_dashboard_guests_today();
    $yesterdayStats   = demo_dashboard_yesterday();
    $revenueTrend7    = demo_dashboard_revenue_trend_7();
    $crmSummary       = demo_dashboard_crm_summary();
    if (function_exists('get_retention_stats')) {
        $retentionStats = get_retention_stats($restId);
        $loyalGuestsCount = (int)($retentionStats['loyal_guests'] ?? 0);
    }
    $upsellSummary    = demo_dashboard_upsell_summary();
    $dashboardInsights = [
        'Средний чек вырос примерно на 8% к прошлому периоду.',
        'Допродажи активны: в демо зафиксировано 52 клика «добавить к заказу» за неделю.',
        '14 возвращающихся гостей — напоминания из CRM отрабатывают.',
    ];
    $growthInsights = [
        'К стейку часто добавляют капучино; к пасте — домашний лимонад.',
        'Допродажи дают ощутимый вклад в средний чек.',
        'Хиты недели: стейк рибай и паста карбонара.',
    ];
    $guestReturnSummary = ['candidates_count' => 3, 'headline' => 'Найдено 3 гостя без визита более 14 дней — готовы к возвратному предложению.', 'estimated_recovered' => 28500];
    $guestReturnCandidates = [];
    if (file_exists(__DIR__ . '/../../app/guest_return_engine.php')) {
        require_once __DIR__ . '/../../app/guest_return_engine.php';
        $guestReturnCandidates = get_guest_return_candidates($restId, 3);
    }
    if (file_exists(__DIR__ . '/../../app/menu_intelligence.php')) {
        require_once __DIR__ . '/../../app/menu_intelligence.php';
        $menuIntelligence = get_menu_intelligence($restId);
    }
    $recommendedActions = [
        ['label' => 'Посмотреть CRM', 'url' => '/restaurant/crm.php'],
        ['label' => 'Посмотреть аналитику выручки', 'url' => '/restaurant/revenue.php'],
        ['label' => 'Попробовать заказ через QR', 'url' => '/qr.php?table_id=1'],
        ['label' => 'Заказ официанта (POS)', 'url' => '/staff/pos.php'],
        ['label' => 'Экран кухни', 'url' => '/staff/kitchen.php'],
    ];
    // Demo: статические подсказки допродаж (согласованы с demo_menu_items)
    $aiUpsellSuggestions = [
        ['base_item' => 'Стейк рибай', 'suggested_item' => 'Капучино', 'confidence' => 0.34, 'base_item_id' => 5, 'suggested_item_id' => 15],
        ['base_item' => 'Паста карбонара', 'suggested_item' => 'Лимонад', 'confidence' => 0.28, 'base_item_id' => 7, 'suggested_item_id' => 13],
        ['base_item' => 'Бургер «Домашний»', 'suggested_item' => 'Крылья BBQ', 'confidence' => 0.25, 'base_item_id' => 9, 'suggested_item_id' => 10],
    ];
    $aiUpsellOrderCount = 36;
    if (file_exists(__DIR__ . '/../../app/upsell_optimization.php')) {
        require_once __DIR__ . '/../../app/upsell_optimization.php';
        $upsellOptimization = get_upsell_optimization_suggestions($restId);
        $upsellOptimizationDashboard = get_upsell_optimization_dashboard($restId);
    }
    $retentionWorkflowSummary = [
        'total' => 8,
        'pending' => 1,
        'draft' => 3,
        'ready_manual' => 2,
        'processed' => 2,
        'canceled' => 0,
        'failed' => 0,
        'loyalty_rows' => 6,
        'loyalty_pending' => 1,
        'loyalty_draft' => 2,
        'loyalty_ready_manual' => 1,
        'loyalty_processed' => 2,
        'loyalty_in_work' => 4,
        'fallback_rows' => 2,
        'recent' => [],
    ];
    $retentionBusinessSummary = [
        'drafts_created' => 9,
        'unique_guests_targeted' => 7,
        'returned_guests' => 4,
        'paid_orders_after_draft' => 4,
        'returned_revenue' => 28400.0,
        'scenarios_active' => 3,
        'last_draft_at' => date('Y-m-d H:i:s', strtotime('-2 hours')),
        'best_scenario' => [
            'label' => 'Есть бонусы, не был 14+ дней',
            'returned_revenue' => 12400.0,
            'returned_guests' => 2,
            'return_rate' => 0.286,
        ],
    ];
    $retentionPriorityQueue = [
        ['phone' => '+7 999 111-22-33', 'segment_label' => 'Напомнить про бонусы'],
        ['phone' => '+7 999 222-33-44', 'segment_label' => 'Один оплаченный визит, не вернулся'],
        ['phone' => '+7 999 333-44-55', 'segment_label' => 'Есть бонусы, не был 14+ дней'],
    ];
} else {
    $pdo = db();
    $todayStart = date('Y-m-d 00:00:00');
    $todayEnd   = date('Y-m-d 23:59:59');

    if ($dashboardSchema['orders']) {
        $stmt = $pdo->prepare("
            SELECT 
                COALESCE(SUM(total_price), 0) AS total_revenue,
                COUNT(*) AS orders_count
            FROM orders
            WHERE restaurant_id = :rest
              AND DATE(created_at) = CURDATE()
              AND payment_status = 'paid'
              AND order_status <> 'canceled'
        ");
        $stmt->execute(['rest' => $restId]);
        $todayStats = $stmt->fetch() ?: ['total_revenue' => 0, 'orders_count' => 0];

        $stmt = $pdo->prepare("
            SELECT 
                order_status,
                COUNT(*) AS cnt
            FROM orders
            WHERE restaurant_id = :rest
              AND order_status IN ('new','accepted','cooking','ready')
            GROUP BY order_status
        ");
        $stmt->execute(['rest' => $restId]);
        $rows = $stmt->fetchAll();
        $statusCounts = [
            'new'      => 0,
            'accepted' => 0,
            'cooking'  => 0,
            'ready'    => 0,
        ];
        foreach ($rows as $r) {
            $statusCounts[$r['order_status']] = (int)$r['cnt'];
        }

        $stmt = $pdo->prepare("
            SELECT 
                o.*,
                t.name AS table_name
            FROM orders o
            LEFT JOIN tables t ON t.id = o.table_id
            WHERE o.restaurant_id = :rest
            ORDER BY o.created_at DESC
            LIMIT 5
        ");
        $stmt->execute(['rest' => $restId]);
        $lastOrders = $stmt->fetchAll();
    } else {
        // Fallback for partial schema: keep cards/charts renderable with empty values.
        $todayStats = ['total_revenue' => 0, 'orders_count' => 0];
        $statusCounts = ['new' => 0, 'accepted' => 0, 'cooking' => 0, 'ready' => 0];
        $lastOrders = [];
    }

    if ($dashboardSchema['orders'] && function_exists('db_table_exists') && db_table_exists('order_items') && db_table_exists('menu_items')) {
        $stmt = $pdo->prepare("
            SELECT 
                mi.id,
                mi.name,
                SUM(oi.quantity) AS total_qty
            FROM order_items oi
            INNER JOIN orders o ON o.id = oi.order_id
            INNER JOIN menu_items mi ON mi.id = oi.menu_item_id
            WHERE o.restaurant_id = :rest
              AND o.created_at BETWEEN :start AND :end
              AND o.order_status <> 'canceled'
            GROUP BY mi.id, mi.name
            ORDER BY total_qty DESC
            LIMIT 5
        ");
        $stmt->execute([
            'rest'  => $restId,
            'start' => $todayStart,
            'end'   => $todayEnd,
        ]);
        $topItems = $stmt->fetchAll();
    } else {
        // Missing dependencies for top-items analytics: show empty list instead of failing queries.
        $topItems = [];
    }

    if (file_exists(__DIR__ . '/../../app/dashboard_intel.php')) {
        require_once __DIR__ . '/../../app/dashboard_intel.php';
        $yesterdayStats = dashboard_yesterday_stats($restId);
        if ($dashboardSchema['guests']) {
            $guestsToday = dashboard_guests_today($restId, $todayStart, $todayEnd);
        } else {
            // Guests table is missing: keep widget in "no data" mode.
            $guestsToday = null;
        }
        $revenueTrend7 = dashboard_revenue_trend($restId, 7);
        $crmSummary = dashboard_crm_summary($restId);
        $upsellSummary = dashboard_upsell_summary($restId, 7);
        $hasUpsellRules = function_exists('dashboard_has_upsell_rules') && dashboard_has_upsell_rules($restId);
        $dashboardInsights = dashboard_insights(
            $todayStats,
            $yesterdayStats,
            $crmSummary,
            $upsellSummary,
            $topItems,
            (int)($onboardingProgress['percent_complete'] ?? 0) >= 100
        );
        if (empty($dashboardInsights)) {
            $dashboardInsights = ['Complete your setup to see personalized insights here.'];
        }
        $recommendedActions = dashboard_recommended_actions(
            false,
            $restId,
            $onboardingProgress,
            $trialInfo,
            $crmSummary,
            $hasUpsellRules
        );
    }
    if (file_exists(__DIR__ . '/../../app/upsell_ai.php')) {
        require_once __DIR__ . '/../../app/upsell_ai.php';
        $aiResult = get_upsell_suggestions($restId);
        $aiUpsellSuggestions = $aiResult['suggestions'] ?? [];
        $aiUpsellOrderCount = (int) ($aiResult['order_count'] ?? 0);
    }
    $growthInsights = [];
    if (file_exists(__DIR__ . '/../../app/growth_insights.php')) {
        require_once __DIR__ . '/../../app/growth_insights.php';
        $growthInsights = get_growth_insights($restId, 7);
    }
    if (file_exists(__DIR__ . '/../../app/guest_return_engine.php')) {
        require_once __DIR__ . '/../../app/guest_return_engine.php';
        $guestReturnSummary = get_guest_return_summary($restId);
        $guestReturnSummary['estimated_recovered'] = estimate_recovered_revenue($restId);
        $guestReturnCandidates = get_guest_return_candidates($restId, 3);
    }
    $menuPerformanceInsights = [];
    if (file_exists(__DIR__ . '/../../app/menu_performance.php')) {
        require_once __DIR__ . '/../../app/menu_performance.php';
        $mp = menu_performance_insights($restId, 7);
        $menuPerformanceInsights = $mp['insights'] ?? [];
    }
    $comboSuggestions = ['suggestions' => [], 'order_count' => 0];
    $tableTurnover = ['tables' => [], 'busiest_table' => '', 'has_data' => false];
    $benchmarkData = ['available' => false];
    if (file_exists(__DIR__ . '/../../app/menu_intelligence.php')) {
        require_once __DIR__ . '/../../app/menu_intelligence.php';
        $menuIntelligence = get_menu_intelligence($restId);
    }
    if (file_exists(__DIR__ . '/../../app/upsell_optimization.php')) {
        require_once __DIR__ . '/../../app/upsell_optimization.php';
        $upsellOptimization = get_upsell_optimization_suggestions($restId);
        $upsellOptimizationDashboard = get_upsell_optimization_dashboard($restId);
    }
    if (file_exists(__DIR__ . '/../../app/combo_builder.php')) {
        require_once __DIR__ . '/../../app/combo_builder.php';
        $comboSuggestions = get_combo_suggestions($restId);
    }
    if (file_exists(__DIR__ . '/../../app/table_turnover.php')) {
        require_once __DIR__ . '/../../app/table_turnover.php';
        $tableTurnover = get_table_turnover($restId, 7);
    }
    $peakHoursSummary = ['peak_hour_range_text' => '', 'recommendation_text' => ''];
    $menuHeatmap = ['top' => [], 'low' => [], 'has_data' => false];
    if (file_exists(__DIR__ . '/../../app/peak_hours.php')) {
        require_once __DIR__ . '/../../app/peak_hours.php';
        $peakHoursSummary = get_peak_hours_summary($restId, 7);
    }
    if (file_exists(__DIR__ . '/../../app/menu_heatmap.php')) {
        require_once __DIR__ . '/../../app/menu_heatmap.php';
        $menuHeatmap = get_menu_heatmap($restId, 7);
    }
    $benchmarkData = get_restaurant_benchmark($restId);
}

// Hero KPI: upsell за сегодня (upsell_events + order_items) и «гости к возврату» (CRM / guest_retention).
$dashboardKpiUpsell = ['revenue' => 0.0, 'added_count' => 0, 'table_ok' => false];
$dashboardInactiveGuests = 0;
if (is_demo_mode()) {
    $dashboardKpiUpsell = ['revenue' => 8420.0, 'added_count' => 9, 'table_ok' => true];
    if (function_exists('get_guest_segments_summary')) {
        $segDemo = get_guest_segments_summary($restId);
        $dashboardInactiveGuests = (int)($segDemo['inactive_guest'] ?? 0);
    } else {
        $dashboardInactiveGuests = 8;
    }
} elseif ($restId > 0) {
    try {
        if (function_exists('get_guest_segments_summary')) {
            $segLive = get_guest_segments_summary($restId);
            $dashboardInactiveGuests = (int)($segLive['inactive_guest'] ?? 0);
        }
        $pdoKpi = db();
        if (function_exists('db_table_exists') && db_table_exists('upsell_events')) {
            $dashboardKpiUpsell['table_ok'] = true;
            $stCnt = $pdoKpi->prepare("
                SELECT COUNT(*)
                FROM upsell_events
                WHERE restaurant_id = ?
                  AND DATE(created_at) = CURDATE()
                  AND event IN ('accepted_in_order', 'upsell_added_to_cart')
            ");
            $stCnt->execute([$restId]);
            $dashboardKpiUpsell['added_count'] = (int)$stCnt->fetchColumn();

            if (function_exists('db_column_exists')) {
                require_once __DIR__ . '/../../app/schema_guard.php';
            }
            $qtyExpr = 'COALESCE(oi.quantity, oi.qty, 0)';
            if (function_exists('db_column_exists')) {
                $hasQ = db_column_exists('order_items', 'quantity');
                $hasAlt = db_column_exists('order_items', 'qty');
                if ($hasQ && !$hasAlt) {
                    $qtyExpr = 'COALESCE(oi.quantity, 0)';
                } elseif (!$hasQ && $hasAlt) {
                    $qtyExpr = 'COALESCE(oi.qty, 0)';
                }
            }
            $stRev = $pdoKpi->prepare("
                SELECT COALESCE(SUM(oi.price * ({$qtyExpr})), 0)
                FROM order_items oi
                INNER JOIN orders o ON o.id = oi.order_id
                INNER JOIN (
                    SELECT DISTINCT order_id, upsell_item_id
                    FROM upsell_events
                    WHERE restaurant_id = :rid_ue
                ) ue ON ue.order_id = oi.order_id AND ue.upsell_item_id = oi.menu_item_id
                WHERE o.restaurant_id = :rid_o
                  AND DATE(o.created_at) = CURDATE()
                  AND o.payment_status = 'paid'
                  AND o.order_status <> 'canceled'
            ");
            $stRev->execute(['rid_ue' => $restId, 'rid_o' => $restId]);
            $dashboardKpiUpsell['revenue'] = (float)$stRev->fetchColumn();
        }
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('dashboard kpi upsell/inactive ' . $e->getMessage());
        }
    }
}

// Guest retention stats (demo: fake data; live: from guest_retention CRM model).
if (!empty($retentionStats)) {
    $retentionEligibleCount = (int)($retentionStats['opportunities_count'] ?? 0);
    $loyalGuestsCount = (int)($retentionStats['loyal_guests'] ?? 0);
} else {
    $retentionEligibleCount = 0;
    if (function_exists('get_retention_stats')) {
        try {
            $retentionStats = get_retention_stats($restId);
            $retentionEligibleCount = (int)($retentionStats['opportunities_count'] ?? 0);
            $loyalGuestsCount = (int)($retentionStats['loyal_guests'] ?? 0);
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('dashboard guest_retention ' . $e->getMessage());
            }
        }
    }
}
// Network benchmark comparison (aggregated, anonymous; may be unavailable if cohort too small).
$networkBenchmark = [
    'available' => false,
    'restaurant' => [],
    'network' => [],
    'comparison' => [],
    'restaurants_count' => 0,
    'reason' => 'Not enough benchmark data yet',
];
if (function_exists('get_benchmark_comparison')) {
    try {
        $networkBenchmark = get_benchmark_comparison($restId);
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('dashboard network_benchmark ' . $e->getMessage());
        }
    }
}
$peakHoursSummary = is_array($peakHoursSummary ?? null)
    ? $peakHoursSummary
    : ['peak_hour_range_text' => '', 'recommendation_text' => ''];
$menuHeatmap = is_array($menuHeatmap ?? null)
    ? $menuHeatmap
    : ['top' => [], 'low' => [], 'has_data' => false];
if (is_demo_mode()) {
    if (empty($comboSuggestions['suggestions'])) {
        $comboSuggestions = [
            'suggestions' => [
                ['label' => 'Паста карбонара + лимонад', 'confidence' => 0.34, 'estimated_uplift_text' => 'потенциал роста среднего чека ~18%', 'items' => [['name' => 'Паста карбонара'], ['name' => 'Лимонад домашний 0,5 л']]],
                ['label' => 'Стейк + капучино + десерт', 'confidence' => 0.28, 'estimated_uplift_text' => 'потенциал роста среднего чека ~15%', 'items' => [['name' => 'Стейк рибай 250 г'], ['name' => 'Капучино'], ['name' => 'Тирамису']]],
            ],
            'order_count' => 36,
        ];
    }
    if (!$tableTurnover['has_data']) {
        $tableTurnover = ['tables' => [['name' => 'Зал · стол 1', 'orders_count' => 24, 'revenue' => 41200], ['name' => 'Терраса · стол 3', 'orders_count' => 18, 'revenue' => 31800]], 'busiest_table' => 'Зал · стол 1 (24 заказа)', 'recommendation_text' => 'На загруженных столах стоит ускорить подачу и расчёт.', 'has_data' => true];
    }
    if (!$benchmarkData['available']) {
        $benchmarkData = ['available' => true, 'your_aov' => 1680, 'peer_aov' => 1820, 'your_orders_per_day' => 3.7, 'peer_orders_per_day' => 4.2, 'recommendation_text' => 'Допродажи и комбо помогут поднять средний чек относительно похожих заведений.'];
    }
    if (empty($peakHoursSummary['peak_hour_range_text'])) {
        $peakHoursSummary = ['peak_hour_range_text' => '18:00–20:00', 'recommendation_text' => 'Пик по демо-данным: усильте смену на этом интервале.'];
    }
    if (!$menuHeatmap['has_data']) {
        $menuHeatmap = ['top' => [['name' => 'Стейк рибай 250 г', 'qty' => 48], ['name' => 'Бургер «Домашний»', 'qty' => 32]], 'low' => [['name' => 'Ризотто с белыми грибами', 'qty' => 8]], 'has_data' => true];
    }
}
if (is_demo_mode() && empty($menuPerformanceInsights)) {
    $menuPerformanceInsights = ['Стейк рибай и паста карбонара — основной объём заказов.', 'Ризотто с грибами можно вынести выше в категории или связать с допродажей.'];
}

$successInsights = [];
$successRecommendations = [];
if (file_exists(__DIR__ . '/../../app/restaurant_success.php')) {
    require_once __DIR__ . '/../../app/restaurant_success.php';
    try {
        // Canonical health score is provided by app/restaurant_health.php.
        // Keep Success Engine only for insights & recommendations.
        $successInsights = get_restaurant_insights($restId);
        $successRecommendations = get_restaurant_recommendations($restId);
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('dashboard restaurant_success ' . $e->getMessage());
        }
    }
}

$healthScore = ['score' => 0, 'label' => 'Improving', 'explanation' => ''];
$healthBreakdown = ['total' => ['score' => 0, 'label' => 'Improving', 'explanation' => ''], 'components' => []];
$healthRecommendations = [];
if (file_exists(__DIR__ . '/../../app/restaurant_health.php')) {
    require_once __DIR__ . '/../../app/restaurant_health.php';
    try {
        $healthScore = get_restaurant_health_score($restId);
        $healthBreakdown = get_restaurant_health_breakdown($restId);
        $healthRecommendations = get_restaurant_health_recommendations($restId);
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('dashboard restaurant_health ' . $e->getMessage());
        }
    }
}

$copilotSummary = ['summary' => '', 'insights' => []];
$copilotRecommendations = [];
if (file_exists(__DIR__ . '/../../app/ai_copilot.php')) {
    require_once __DIR__ . '/../../app/ai_copilot.php';
    try {
        $copilotSummary = get_ai_copilot_summary($restId);
        $copilotRecommendations = get_ai_copilot_recommendations($restId);
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('dashboard ai_copilot ' . $e->getMessage());
        }
    }
}

$growthPendingCount = 0;
$archGrowthOpportunities = [];
$growthSummaryArch = ['top_current_growth_driver' => '', 'biggest_missed_opportunity' => '', 'quickest_win' => ''];
if (file_exists(__DIR__ . '/../../app/growth_engine_arch.php')) {
    require_once __DIR__ . '/../../app/growth_engine_arch.php';
    try {
        $archGrowthOpportunities = growth_engine_arch_get_opportunities($restId, 5);
        $growthSummaryArch = get_growth_summary($restId);
        if (!is_demo_mode() && $restId > 0 && function_exists('growth_engine_pending_count')) {
            $growthPendingCount = growth_engine_pending_count($restId);
        }
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('dashboard growth_engine_arch ' . $e->getMessage());
        }
    }
}

$feedbackSummary = [
    'total_feedback' => 0,
    'average_rating' => null,
    'promoters_count' => 0,
    'neutral_count' => 0,
    'detractors_count' => 0,
    'latest_feedback_at' => null,
    'feedback_this_month' => 0,
];
$recentFeedback = [];
$feedbackAnalyticsSummary = [
    'total_feedback' => 0,
    'average_rating' => null,
    'promoters_count' => 0,
    'neutral_count' => 0,
    'detractors_count' => 0,
    'response_window_days' => 30,
    'latest_feedback_at' => null,
];
$feedbackRatingBreakdown = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
$feedbackTrendPoints = [];
$retentionRoiSummary = [
    'total_campaigns' => 0,
    'accepted_campaigns' => 0,
    'returned_guests' => 0,
    'return_rate' => 0.0,
    'total_return_revenue' => 0.0,
    'avg_return_revenue' => 0.0,
    'avg_days_to_return' => null,
    'revenue_per_campaign' => 0.0,
    'estimated_bonus_cost' => 0.0,
    'estimated_net_return_revenue' => 0.0,
    'estimated_net_revenue_per_campaign' => 0.0,
    'failed_campaigns' => 0,
    'pending_campaigns' => 0,
    'expired_without_return' => 0,
    'baseline_return_rate' => null,
    'baseline_window_days' => 7,
    'uplift_return_rate' => null,
    'uplift_percent' => null,
];
$retentionSegments = ['LOW' => [], 'NEUTRAL' => [], 'HIGH' => []];
if (!is_demo_mode() && $restId > 0 && file_exists(__DIR__ . '/../../app/feedback_insights.php')) {
    require_once __DIR__ . '/../../app/feedback_insights.php';
    try {
        $feedbackSummary = get_restaurant_feedback_summary($restId);
        $recentFeedback = get_recent_restaurant_feedback($restId, 5);
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('dashboard feedback_insights ' . $e->getMessage());
        }
    }
}
if (!is_demo_mode() && $restId > 0 && file_exists(__DIR__ . '/../../app/retention_analytics.php')) {
    require_once __DIR__ . '/../../app/retention_analytics.php';
    try {
        if (function_exists('get_retention_roi_summary')) {
            $retentionRoiSummary = function_exists('get_retention_roi_summary_cached')
                ? get_retention_roi_summary_cached($restId, 30)
                : get_retention_roi_summary($restId, 30);
        }
        if (function_exists('get_retention_segments')) {
            $retentionSegments = function_exists('get_retention_segments_cached')
                ? get_retention_segments_cached($restId, 30)
                : get_retention_segments($restId, 30);
        }
        if (function_exists('get_loyalty_retention_business_summary_cached')) {
            $retentionBusinessSummary = get_loyalty_retention_business_summary_cached($restId, 30);
        }
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('dashboard retention_analytics ' . $e->getMessage());
        }
    }
}
if (!is_demo_mode() && $restId > 0 && file_exists(__DIR__ . '/../../app/crm_repo.php')) {
    require_once __DIR__ . '/../../app/crm_repo.php';
    try {
        if (function_exists('crm_manual_return_outbox_summary')) {
            $retentionWorkflowSummary = crm_manual_return_outbox_summary($restId, 30, 4);
        }
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('dashboard crm_manual_return_outbox_summary ' . $e->getMessage());
        }
    }
}
if (!is_demo_mode() && $restId > 0 && file_exists(__DIR__ . '/../../app/crm_campaign_repo.php')) {
    require_once __DIR__ . '/../../app/crm_campaign_repo.php';
    try {
        if (function_exists('crm_loyalty_retention_priority_queue')) {
            $retentionPriorityQueue = crm_loyalty_retention_priority_queue($restId, 3);
        }
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('dashboard crm_loyalty_retention_priority_queue ' . $e->getMessage());
        }
    }
}
if (!is_demo_mode() && $restId > 0 && file_exists(__DIR__ . '/../../app/feedback_analytics.php')) {
    require_once __DIR__ . '/../../app/feedback_analytics.php';
    try {
        $feedbackAnalyticsSummary = get_feedback_analytics_summary($restId, 30);
        $feedbackRatingBreakdown = get_feedback_rating_breakdown($restId, 30);
        $feedbackTrendPoints = get_feedback_trend_points($restId, 30);
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('dashboard feedback_analytics ' . $e->getMessage());
        }
    }
}

// Safety normalization: avoid warnings/notices in template on partial data.
$todayStats = is_array($todayStats ?? null) ? $todayStats : ['total_revenue' => 0, 'orders_count' => 0];
$statusCounts = is_array($statusCounts ?? null) ? $statusCounts : ['new' => 0, 'accepted' => 0, 'cooking' => 0, 'ready' => 0];
$lastOrders = is_array($lastOrders ?? null) ? $lastOrders : [];
$topItems = is_array($topItems ?? null) ? $topItems : [];
$revenueTrend7 = is_array($revenueTrend7 ?? null) ? $revenueTrend7 : [];
$dashboardInsights = is_array($dashboardInsights ?? null) ? $dashboardInsights : [];
$recommendedActions = is_array($recommendedActions ?? null) ? $recommendedActions : [];
$menuPerformanceInsights = is_array($menuPerformanceInsights ?? null) ? $menuPerformanceInsights : [];
$growthInsights = is_array($growthInsights ?? null) ? $growthInsights : [];
$menuHeatmap = is_array($menuHeatmap ?? null) ? $menuHeatmap : ['top' => [], 'low' => [], 'has_data' => false];
$menuHeatmap['top'] = is_array($menuHeatmap['top'] ?? null) ? $menuHeatmap['top'] : [];
$menuHeatmap['low'] = is_array($menuHeatmap['low'] ?? null) ? $menuHeatmap['low'] : [];
$menuHeatmap['has_data'] = !empty($menuHeatmap['has_data']);
$peakHoursSummary = is_array($peakHoursSummary ?? null) ? $peakHoursSummary : ['peak_hour_range_text' => '', 'recommendation_text' => ''];
$benchmarkData = is_array($benchmarkData ?? null) ? $benchmarkData : ['available' => false];
$upgradeReadiness = is_array($upgradeReadiness ?? null) ? $upgradeReadiness : null;
$feedbackSummary = is_array($feedbackSummary ?? null) ? $feedbackSummary : [];
$retentionRoiSummary = is_array($retentionRoiSummary ?? null) ? $retentionRoiSummary : [];
$retentionBusinessSummary = is_array($retentionBusinessSummary ?? null) ? $retentionBusinessSummary : [];
$retentionWorkflowSummary = is_array($retentionWorkflowSummary ?? null) ? $retentionWorkflowSummary : [];
$retentionPriorityQueue = is_array($retentionPriorityQueue ?? null) ? $retentionPriorityQueue : [];
$networkBenchmark = is_array($networkBenchmark ?? null) ? $networkBenchmark : ['available' => false];
$comboSuggestions = is_array($comboSuggestions ?? null) ? $comboSuggestions : ['suggestions' => [], 'order_count' => 0];
$tableTurnover = is_array($tableTurnover ?? null) ? $tableTurnover : ['tables' => [], 'busiest_table' => '', 'has_data' => false, 'recommendation_text' => ''];
$upsellOptimizationDashboard = is_array($upsellOptimizationDashboard ?? null) ? $upsellOptimizationDashboard : ['summary' => ['pairs_analyzed' => 0, 'strong_pairs' => 0, 'weak_pairs' => 0, 'low_data_pairs' => 0], 'top_performing' => [], 'needs_attention' => [], 'low_data' => []];

$bestRetentionScenario = is_array($retentionBusinessSummary['best_scenario'] ?? null) ? $retentionBusinessSummary['best_scenario'] : null;
$bestUpsellPair = null;
if (!empty($upsellOptimizationDashboard['top_performing'][0]) && is_array($upsellOptimizationDashboard['top_performing'][0])) {
    $bestUpsellPair = $upsellOptimizationDashboard['top_performing'][0];
} elseif (!empty($upsellOptimizationDashboard['needs_attention'][0]) && is_array($upsellOptimizationDashboard['needs_attention'][0])) {
    $bestUpsellPair = $upsellOptimizationDashboard['needs_attention'][0];
}

if ($crmEnabled) {
    $loyaltyInWorkNow = (int)($retentionWorkflowSummary['loyalty_in_work'] ?? 0);
    $readyNow = (int)($retentionWorkflowSummary['loyalty_ready_manual'] ?? 0) + (int)($retentionWorkflowSummary['loyalty_pending'] ?? 0);
    if ($readyNow > 0) {
        $managerBusinessActions[] = [
            'title' => 'Отправить retention-сообщения',
            'text' => 'В send-board уже готовы к ручной отправке ' . $readyNow . ' сообщений.',
            'url' => '/restaurant/crm.php#crm-send-board',
            'tone' => 'emerald',
        ];
    } elseif (!empty($retentionPriorityQueue)) {
        $topCandidate = $retentionPriorityQueue[0];
        $managerBusinessActions[] = [
            'title' => 'Запустить следующий retention touch',
            'text' => 'В приоритетной очереди сейчас ' . count($retentionPriorityQueue) . ' гостя. Начните со сценария «' . (string)($topCandidate['segment_label'] ?? $topCandidate['segment_type'] ?? 'retention') . '».',
            'url' => '/restaurant/crm.php#crm-priority-queue',
            'tone' => 'indigo',
        ];
    } elseif ($loyaltyInWorkNow > 0) {
        $managerBusinessActions[] = [
            'title' => 'Проверить гостей в работе',
            'text' => 'Сейчас в retention-workflow ' . $loyaltyInWorkNow . ' гостя. Проверьте send-board и outcome по активным сценариям.',
            'url' => '/restaurant/crm.php#crm-send-board',
            'tone' => 'sky',
        ];
    }
}

if ($upsellEnabled) {
    if (!empty($upsellOptimizationDashboard['needs_attention'][0])) {
        $weakPair = $upsellOptimizationDashboard['needs_attention'][0];
        $managerBusinessActions[] = [
            'title' => 'Пересобрать слабую допродажу',
            'text' => 'Пара «' . (string)($weakPair['base_item_name'] ?? 'Блюдо') . ' → ' . (string)($weakPair['upsell_item_name'] ?? 'дополнение') . '» часто показывается, но слабо добавляется.',
            'url' => '/restaurant/analytics_upsell.php',
            'tone' => 'amber',
        ];
    } elseif (!empty($upsellOptimizationDashboard['top_performing'][0])) {
        $strongPair = $upsellOptimizationDashboard['top_performing'][0];
        $managerBusinessActions[] = [
            'title' => 'Усилить сильную upsell-пару',
            'text' => 'Пара «' . (string)($strongPair['base_item_name'] ?? 'Блюдо') . ' → ' . (string)($strongPair['upsell_item_name'] ?? 'дополнение') . '» уже работает лучше остальных.',
            'url' => '/restaurant/analytics_upsell.php',
            'tone' => 'emerald',
        ];
    } elseif (!$hasUpsellRules) {
        $managerBusinessActions[] = [
            'title' => 'Включить первые допродажи',
            'text' => 'Правила upsell ещё не настроены. Добавьте хотя бы 1–2 пары, чтобы поднять средний чек.',
            'url' => '/restaurant/upsells.php',
            'tone' => 'sky',
        ];
    }
}

if ($managerBusinessActions === []) {
    $managerBusinessActions[] = [
        'title' => 'Открыть CRM command-center',
        'text' => 'Проверьте приоритетную очередь и send-board, чтобы держать возвраты гостей под контролем.',
        'url' => '/restaurant/crm.php',
        'tone' => 'slate',
    ];
}

function human_order_status_short(string $status): string {
    return [
        'new'       => 'Новый',
        'accepted'  => 'Принят',
        'cooking'   => 'Готовится',
        'ready'     => 'Готово',
        'delivered' => 'Отдано',
        'canceled'  => 'Отменён',
    ][$status] ?? $status;
}

?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Обзор — <?= e($currentRestaurant['name']) ?></title>
    <?= brand_head_tags() ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <?php if (!empty($revenueTrend7)): ?><script src="https://cdn.jsdelivr.net/npm/chart.js"></script><?php endif; ?>
    <link rel="stylesheet" href="/assets/css/motion.css">
    <link rel="stylesheet" href="/assets/css/polish.css">
    <style> body { font-family: Inter, system-ui, sans-serif; } </style>
</head>
<body class="min-h-screen text-gray-300 antialiased flex flex-col md:flex-row <?= is_demo_mode() ? 'demo-mode' : '' ?>" style="background-color: #0B0F19;">

<!-- Mobile: верхняя панель + выезжающее меню (на md+ только сайдбар) -->
<div class="md:hidden sticky top-0 z-40 flex items-center justify-between gap-2 px-3 py-3 border-b border-gray-800 bg-[#0B0F19]/95 backdrop-blur-md" style="padding-top:max(0.75rem, env(safe-area-inset-top))">
    <span class="text-sm font-semibold text-[#F3F4F6] truncate min-w-0 flex-1"><?= e($currentRestaurant['name'] ?? '') ?></span>
    <button type="button" id="dash-nav-open" class="shrink-0 min-h-[44px] min-w-[44px] rounded-xl border border-gray-700 bg-[#121826] text-sm font-medium text-[#F3F4F6] touch-manipulation" aria-expanded="false" aria-controls="dash-nav-panel">Меню</button>
</div>
<div id="dash-nav-overlay" class="fixed inset-0 z-50 hidden md:hidden" aria-hidden="true">
    <button type="button" id="dash-nav-backdrop" class="absolute inset-0 bg-black/60" aria-label="Закрыть меню"></button>
    <div id="dash-nav-panel" class="absolute right-0 top-0 bottom-0 w-[min(100%,18rem)] max-w-full bg-gray-950 border-l border-gray-800 shadow-2xl overflow-y-auto overscroll-contain" style="padding:max(1rem, env(safe-area-inset-top)) max(1rem, env(safe-area-inset-right)) max(1rem, env(safe-area-inset-bottom)) max(1rem, env(safe-area-inset-left))">
        <div class="flex justify-between items-center gap-2 mb-4">
            <span class="font-semibold text-[#F3F4F6] text-sm">Разделы</span>
            <button type="button" id="dash-nav-close" class="min-h-[44px] min-w-[44px] rounded-xl border border-gray-700 text-[#F3F4F6] text-lg leading-none touch-manipulation" aria-label="Закрыть">×</button>
        </div>
        <nav class="sidebar-nav space-y-1 text-sm">
            <a href="/restaurant/dashboard.php" class="block px-3 py-3 rounded-lg sidebar-active min-h-[44px] flex items-center">Обзор</a>
            <a href="/restaurant/revenue.php" class="block px-3 py-3 rounded-lg hover:bg-gray-800 text-gray-300 min-h-[44px] flex items-center">Доход</a>
            <a href="/restaurant/menu_manage.php#categories" class="block px-3 py-3 rounded-lg hover:bg-gray-800 text-gray-300 min-h-[44px] flex items-center">Категории меню</a>
            <a href="/restaurant/menu_manage.php#dishes" class="block px-3 py-3 rounded-lg hover:bg-gray-800 text-gray-300 min-h-[44px] flex items-center">Блюда</a>
            <a href="/restaurant/tables.php" class="block px-3 py-3 rounded-lg hover:bg-gray-800 text-gray-300 min-h-[44px] flex items-center">Столы и QR</a>
            <a href="/restaurant/orders.php" class="block px-3 py-3 rounded-lg hover:bg-gray-800 text-gray-300 min-h-[44px] flex items-center">Заказы</a>
            <a href="/restaurant/upsells.php" class="block px-3 py-3 rounded-lg hover:bg-gray-800 text-gray-300 min-h-[44px] flex items-center">Допродажи</a>
            <a href="/restaurant/crm.php" class="block px-3 py-3 rounded-lg hover:bg-gray-800 text-gray-300 min-h-[44px] flex items-center">CRM</a>
            <a href="/restaurant/settings.php" class="block px-3 py-3 rounded-lg hover:bg-gray-800 text-gray-300 min-h-[44px] flex items-center">Настройки</a>
            <a href="/logout.php" class="block px-3 py-3 rounded-lg hover:bg-gray-800 text-red-400 min-h-[44px] flex items-center">Выйти</a>
        </nav>
    </div>
</div>

<?php
$restaurantSidebarActive = 'dashboard';
$restaurantSidebarName = (string)($currentRestaurant['name'] ?? 'Ресторан');
require __DIR__ . '/_sidebar.php';
?>

<!-- Основной контент -->
<main class="flex-1 min-w-0 p-4 md:p-4 overflow-x-hidden">
    <div class="page-enter max-w-6xl mx-auto space-y-4">
        <?php
        $cabinetQuickNavActive = 'dashboard';
        require __DIR__ . '/_restaurant_cabinet_context.php';
        require __DIR__ . '/_restaurant_cabinet_quick_nav.php';
        ?>
        <header class="border-b border-gray-800/80 pb-4 mb-1">
            <p class="text-[11px] font-semibold uppercase tracking-wider text-emerald-500/85 mb-1">Главная</p>
            <h1 class="text-2xl sm:text-3xl font-bold text-[#F3F4F6] tracking-tight">Обзор ресторана</h1>
            <p class="text-sm text-gray-500 mt-1 max-w-2xl">Ключевые показатели дня и быстрый доступ к разделам кабинета.</p>
        </header>
        <?php if (!empty($dashboardSchemaWarnings)): ?>
        <section class="rounded-xl border border-amber-500/40 bg-amber-500/10 px-4 py-3 text-xs text-amber-100 space-y-1" role="status">
            <?php foreach ($dashboardSchemaWarnings as $schemaWarning): ?>
                <div><?= e((string)$schemaWarning) ?> Показаны безопасные fallback-значения.</div>
            <?php endforeach; ?>
        </section>
        <?php endif; ?>
        <?php
        $heroAvg = ((int)($todayStats['orders_count'] ?? 0) > 0)
            ? (float)$todayStats['total_revenue'] / (int)$todayStats['orders_count']
            : 0.0;
        ?>
        <!-- Hero: ключевые метрики дня -->
        <section class="space-y-4" aria-labelledby="dash-hero-title">
            <div class="flex flex-wrap items-end justify-between gap-2">
                <div>
                    <h2 id="dash-hero-title" class="text-lg font-semibold text-[#F3F4F6]">Сегодня</h2>
                    <p class="text-[11px] text-gray-500 mt-0.5">Выручка и заказы по оплаченным заказам за текущие сутки</p>
                </div>
                <span class="text-[10px] text-gray-600"><?= e(date('d.m.Y')) ?></span>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <div class="dashboard-card rounded-xl border border-gray-800 bg-[#121826] shadow-lg shadow-black/20 p-5 card-motion hover:border-gray-700 flex items-start gap-3">
                    <div class="w-10 h-10 rounded-lg bg-emerald-500/20 flex items-center justify-center flex-shrink-0 text-emerald-400">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    </div>
                    <div class="min-w-0">
                        <div class="text-2xl font-bold text-[#22C55E]"><?= number_format((float)($todayStats['total_revenue'] ?? 0), 0, '.', ' ') ?> ₽</div>
                        <div class="text-xs text-gray-400 uppercase tracking-wide">Выручка сегодня</div>
                    </div>
                </div>
                <div class="dashboard-card rounded-xl border border-gray-800 bg-[#121826] shadow-lg shadow-black/20 p-5 card-motion hover:border-gray-700 flex items-start gap-3">
                    <div class="w-10 h-10 rounded-lg bg-sky-500/20 flex items-center justify-center flex-shrink-0 text-sky-400">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                    </div>
                    <div class="min-w-0">
                        <div class="text-2xl font-bold text-[#F3F4F6]"><?= (int)($todayStats['orders_count'] ?? 0) ?></div>
                        <div class="text-xs text-gray-400 uppercase tracking-wide">Заказы сегодня</div>
                    </div>
                </div>
                <div class="dashboard-card rounded-xl border border-gray-800 bg-[#121826] shadow-lg shadow-black/20 p-5 card-motion hover:border-gray-700 flex items-start gap-3">
                    <div class="w-10 h-10 rounded-lg bg-violet-500/20 flex items-center justify-center flex-shrink-0 text-violet-400">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
                    </div>
                    <div class="min-w-0">
                        <div class="text-2xl font-bold text-[#F3F4F6]"><?= number_format($heroAvg, 0, '.', ' ') ?> ₽</div>
                        <div class="text-xs text-gray-400 uppercase tracking-wide">Средний чек</div>
                    </div>
                </div>
                <div class="dashboard-card rounded-xl border border-gray-800 bg-[#121826] shadow-lg shadow-black/20 p-5 card-motion hover:border-gray-700 flex items-start gap-3">
                    <div class="w-10 h-10 rounded-lg bg-amber-500/20 flex items-center justify-center flex-shrink-0 text-amber-400">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>
                    </div>
                    <div class="min-w-0">
                        <?php if (!$upsellEnabled): ?>
                            <div class="text-sm font-semibold text-gray-500">—</div>
                            <div class="text-xs text-gray-400 uppercase tracking-wide">Upsell доход</div>
                            <div class="text-[11px] text-amber-500/90 mt-1">Доступно на тарифе с допродажами</div>
                        <?php elseif (!$dashboardKpiUpsell['table_ok']): ?>
                            <div class="text-sm font-semibold text-gray-500">—</div>
                            <div class="text-xs text-gray-400 uppercase tracking-wide">Upsell доход</div>
                            <div class="text-[11px] text-gray-500 mt-1">Таблица событий не развёрнута</div>
                        <?php else: ?>
                            <div class="text-2xl font-bold text-amber-300"><?= number_format((float)$dashboardKpiUpsell['revenue'], 0, '.', ' ') ?> ₽</div>
                            <div class="text-xs text-gray-400 uppercase tracking-wide">Upsell доход</div>
                            <div class="text-[11px] text-gray-500 mt-1"><?= (int)$dashboardKpiUpsell['added_count'] ?> добавлений сегодня</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
                <div class="lg:col-span-1 rounded-xl border border-indigo-500/25 bg-[#121826] p-5 card-motion">
                    <div class="text-xs font-semibold text-indigo-300 uppercase tracking-wide">Гостей нужно вернуть</div>
                    <p class="text-2xl font-bold text-[#F3F4F6] mt-2"><?= (int)$dashboardInactiveGuests ?></p>
                    <p class="text-[11px] text-gray-500 mt-1">Неактивные гости (сегмент CRM)</p>
                    <a href="/restaurant/crm.php" class="mt-4 inline-flex items-center gap-1 text-sm font-medium text-indigo-400 hover:text-indigo-300">
                        Перейти в CRM <span aria-hidden="true">→</span>
                    </a>
                </div>
                <div class="lg:col-span-2 rounded-xl border border-gray-800 bg-[#121826] p-5 card-motion">
                    <div class="flex items-center justify-between mb-3">
                        <h3 class="text-sm font-semibold text-[#F3F4F6]">Последние заказы</h3>
                        <a href="/restaurant/orders.php" class="text-[11px] text-gray-400 hover:text-indigo-400">Все заказы →</a>
                    </div>
                    <?php if (empty($lastOrders)): ?>
                        <p class="text-sm text-gray-500">Пока нет заказов</p>
                    <?php else: ?>
                        <ul class="space-y-2 text-xs">
                            <?php foreach ($lastOrders as $o): ?>
                                <li class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-gray-800 bg-gray-900/50 px-3 py-2">
                                    <div>
                                        <span class="text-[#F3F4F6] font-medium">#<?= (int)$o['id'] ?></span>
                                        <span class="text-gray-500"> · <?= e(human_order_status_short($o['order_status'] ?? '')) ?></span>
                                        <span class="text-gray-600"> · <?= date('d.m H:i', strtotime((string)($o['created_at'] ?? 'now'))) ?></span>
                                    </div>
                                    <span class="text-sm font-semibold text-[#22C55E]"><?= number_format((float)($o['total_price'] ?? 0), 0, '.', ' ') ?> ₽</span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <section class="rounded-xl border border-cyan-500/25 bg-[#121826] shadow-lg shadow-black/20 p-5 card-motion space-y-3" id="analytics-foundation-block">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h3 class="text-sm font-semibold text-cyan-200 uppercase tracking-wide">Analytics foundation</h3>
                    <p class="text-xs text-gray-500 mt-1">Unified operational summary: заказы, выручка, доставка, гости, loyalty, promo, tips, отзывы.</p>
                </div>
                <div class="flex flex-wrap gap-1.5" id="analytics-range-switch">
                    <button type="button" data-range="today" class="analytics-range-btn px-2.5 py-1 rounded-lg border border-slate-700 bg-slate-900/70 text-xs text-slate-200">Сегодня</button>
                    <button type="button" data-range="yesterday" class="analytics-range-btn px-2.5 py-1 rounded-lg border border-slate-700 bg-slate-900/70 text-xs text-slate-200">Вчера</button>
                    <button type="button" data-range="week" class="analytics-range-btn px-2.5 py-1 rounded-lg border border-slate-700 bg-slate-900/70 text-xs text-slate-200">7 дней</button>
                    <button type="button" data-range="month" class="analytics-range-btn px-2.5 py-1 rounded-lg border border-slate-700 bg-slate-900/70 text-xs text-slate-200">Месяц</button>
                </div>
            </div>
            <div class="grid grid-cols-2 md:grid-cols-4 xl:grid-cols-8 gap-2 text-xs">
                <div class="rounded-lg border border-slate-800 bg-slate-900/60 px-2 py-2"><div class="text-gray-500">Заказы</div><div id="af-orders" class="text-slate-100 font-semibold mt-1">—</div></div>
                <div class="rounded-lg border border-slate-800 bg-slate-900/60 px-2 py-2"><div class="text-gray-500">Выручка</div><div id="af-revenue" class="text-emerald-300 font-semibold mt-1">—</div></div>
                <div class="rounded-lg border border-slate-800 bg-slate-900/60 px-2 py-2"><div class="text-gray-500">Средний чек</div><div id="af-avg" class="text-slate-100 font-semibold mt-1">—</div></div>
                <div class="rounded-lg border border-slate-800 bg-slate-900/60 px-2 py-2"><div class="text-gray-500">Delivery</div><div id="af-delivery" class="text-sky-300 font-semibold mt-1">—</div></div>
                <div class="rounded-lg border border-slate-800 bg-slate-900/60 px-2 py-2"><div class="text-gray-500">Брони</div><div id="af-res" class="text-indigo-300 font-semibold mt-1">—</div></div>
                <div class="rounded-lg border border-slate-800 bg-slate-900/60 px-2 py-2"><div class="text-gray-500">Repeat guests</div><div id="af-repeat" class="text-violet-300 font-semibold mt-1">—</div></div>
                <div class="rounded-lg border border-slate-800 bg-slate-900/60 px-2 py-2"><div class="text-gray-500">Loyalty/Promo</div><div id="af-loyalty" class="text-amber-300 font-semibold mt-1">—</div></div>
                <div class="rounded-lg border border-slate-800 bg-slate-900/60 px-2 py-2"><div class="text-gray-500">Tips/NPS</div><div id="af-tips" class="text-rose-300 font-semibold mt-1">—</div></div>
            </div>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-2 text-xs">
                <div class="rounded-lg border border-slate-800 bg-slate-900/60 px-2 py-2"><div class="text-gray-500">Delivery hotspots</div><div id="af-hotspots" class="text-rose-300 font-semibold mt-1">—</div></div>
                <div class="rounded-lg border border-slate-800 bg-slate-900/60 px-2 py-2"><div class="text-gray-500">SLA success</div><div id="af-sla-success" class="text-emerald-300 font-semibold mt-1">—</div></div>
                <div class="rounded-lg border border-slate-800 bg-slate-900/60 px-2 py-2"><div class="text-gray-500">Delivery margin</div><div id="af-delivery-margin" class="text-violet-300 font-semibold mt-1">—</div></div>
                <div class="rounded-lg border border-slate-800 bg-slate-900/60 px-2 py-2"><div class="text-gray-500">Top courier</div><div id="af-top-courier" class="text-cyan-300 font-semibold mt-1">—</div></div>
            </div>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-2 text-xs">
                <div class="rounded-lg border border-slate-800 bg-slate-900/60 px-2 py-2"><div class="text-gray-500">Forecast orders</div><div id="af-fc-orders" class="text-slate-100 font-semibold mt-1">—</div></div>
                <div class="rounded-lg border border-slate-800 bg-slate-900/60 px-2 py-2"><div class="text-gray-500">Forecast revenue</div><div id="af-fc-revenue" class="text-emerald-300 font-semibold mt-1">—</div></div>
                <div class="rounded-lg border border-slate-800 bg-slate-900/60 px-2 py-2"><div class="text-gray-500">Staffing need</div><div id="af-fc-staff" class="text-amber-300 font-semibold mt-1">—</div></div>
                <div class="rounded-lg border border-slate-800 bg-slate-900/60 px-2 py-2"><div class="text-gray-500">Stock pressure</div><div id="af-fc-stock" class="text-rose-300 font-semibold mt-1">—</div></div>
            </div>
            <div class="flex flex-wrap items-center justify-between gap-2 text-[11px]">
                <div id="af-period-label" class="text-gray-500">Период: —</div>
                <div id="af-delta-label" class="text-gray-500">Δ выручки: —</div>
            </div>
            <div id="af-alerts" class="space-y-1"></div>
        </section>

        <?php
        $retentionInWorkNow = (int)($retentionWorkflowSummary['loyalty_in_work'] ?? 0);
        $retentionReadyNow = (int)($retentionWorkflowSummary['loyalty_ready_manual'] ?? 0) + (int)($retentionWorkflowSummary['loyalty_pending'] ?? 0);
        $retentionReturnedGuests = (int)($retentionBusinessSummary['returned_guests'] ?? 0);
        $retentionReturnedRevenue = (float)($retentionBusinessSummary['returned_revenue'] ?? 0);
        $bestRetentionScenarioLabel = (string)($bestRetentionScenario['label'] ?? '');
        $bestRetentionScenarioRevenue = (float)($bestRetentionScenario['returned_revenue'] ?? 0);
        $bestRetentionScenarioRate = (float)($bestRetentionScenario['return_rate'] ?? 0);
        $bestUpsellLabel = '';
        if (is_array($bestUpsellPair)) {
            $bestUpsellLabel = (string)($bestUpsellPair['base_item_name'] ?? '') . ' → ' . (string)($bestUpsellPair['upsell_item_name'] ?? '');
        }
        ?>
        <section class="rounded-xl border border-gray-800 bg-[#121826] shadow-xl p-5 card-motion space-y-4" aria-labelledby="business-summary-title">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h3 id="business-summary-title" class="text-sm font-semibold text-[#F3F4F6]">Что система уже приносит бизнесу</h3>
                    <p class="text-xs text-gray-500 mt-1">Retention и upsell в одном коротком manager summary, без переходов между CRM и аналитикой.</p>
                </div>
                <div class="text-right text-[11px] text-gray-500">
                    <?php if (!empty($retentionBusinessSummary['last_draft_at'])): ?>
                        <div>Последний retention touch: <?= e(date('d.m H:i', strtotime((string)$retentionBusinessSummary['last_draft_at']))) ?></div>
                    <?php else: ?>
                        <div>Сводка за последние 30 дней</div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-3">
                <div class="rounded-xl border border-indigo-500/20 bg-indigo-500/5 px-4 py-4">
                    <div class="text-[11px] font-semibold uppercase tracking-wide text-indigo-300">Гостей в retention-workflow</div>
                    <?php if (!$crmEnabled): ?>
                        <div class="mt-2 text-sm font-semibold text-gray-500">CRM выключен в тарифе</div>
                        <div class="mt-1 text-[11px] text-gray-500">Когда CRM включён, здесь видно гостей в drafts и ready-to-send.</div>
                    <?php else: ?>
                        <div class="mt-2 text-3xl font-bold text-[#F3F4F6]"><?= $retentionInWorkNow ?></div>
                        <div class="mt-1 text-[11px] text-gray-400">
                            <?php if ($retentionReadyNow > 0): ?>
                                <?= $retentionReadyNow ?> готовы к ручной отправке
                            <?php elseif ($retentionInWorkNow > 0): ?>
                                drafts и pending уже в работе
                            <?php elseif (!empty($retentionPriorityQueue)): ?>
                                В очереди сейчас <?= count($retentionPriorityQueue) ?> приоритетных гостей
                            <?php else: ?>
                                Сейчас активных retention-touch нет
                            <?php endif; ?>
                        </div>
                        <a href="/restaurant/crm.php#crm-send-board" class="mt-3 inline-flex items-center gap-1 text-xs font-medium text-indigo-300 hover:text-indigo-200">Открыть send-board <span aria-hidden="true">→</span></a>
                    <?php endif; ?>
                </div>

                <div class="rounded-xl border border-emerald-500/20 bg-emerald-500/5 px-4 py-4">
                    <div class="text-[11px] font-semibold uppercase tracking-wide text-emerald-300">Возвратов после retention</div>
                    <?php if (!$crmEnabled): ?>
                        <div class="mt-2 text-sm font-semibold text-gray-500">CRM выключен в тарифе</div>
                        <div class="mt-1 text-[11px] text-gray-500">После включения CRM здесь появится результат по loyalty-сценариям.</div>
                    <?php else: ?>
                        <div class="mt-2 text-3xl font-bold text-[#F3F4F6]"><?= $retentionReturnedGuests ?></div>
                        <div class="mt-1 text-[11px] text-gray-400">
                            <?php if ($bestRetentionScenarioLabel !== ''): ?>
                                Лучший сценарий: <?= e($bestRetentionScenarioLabel) ?>
                            <?php else: ?>
                                Пока смотрим на loyalty drafts и confirmed paid returns
                            <?php endif; ?>
                        </div>
                        <a href="/restaurant/crm.php#crm-loyalty-analytics" class="mt-3 inline-flex items-center gap-1 text-xs font-medium text-emerald-300 hover:text-emerald-200">Смотреть retention analytics <span aria-hidden="true">→</span></a>
                    <?php endif; ?>
                </div>

                <div class="rounded-xl border border-sky-500/20 bg-sky-500/5 px-4 py-4">
                    <div class="text-[11px] font-semibold uppercase tracking-wide text-sky-300">Выручка, возвращённая CRM</div>
                    <?php if (!$crmEnabled): ?>
                        <div class="mt-2 text-sm font-semibold text-gray-500">CRM выключен в тарифе</div>
                        <div class="mt-1 text-[11px] text-gray-500">Когда retention активен, здесь видно revenue по confirmed paid returns.</div>
                    <?php else: ?>
                        <div class="mt-2 text-3xl font-bold text-[#F3F4F6]"><?= number_format($retentionReturnedRevenue, 0, '.', ' ') ?> ₽</div>
                        <div class="mt-1 text-[11px] text-gray-400">
                            <?php if ($bestRetentionScenarioRevenue > 0 && $bestRetentionScenarioLabel !== ''): ?>
                                <?= e($bestRetentionScenarioLabel) ?> дал <?= number_format($bestRetentionScenarioRevenue, 0, '.', ' ') ?> ₽
                                <?php if ($bestRetentionScenarioRate > 0): ?>
                                    · return rate <?= number_format($bestRetentionScenarioRate * 100, 1, '.', ' ') ?>%
                                <?php endif; ?>
                            <?php else: ?>
                                Считаем только confirmed paid orders после retention draft
                            <?php endif; ?>
                        </div>
                        <a href="/restaurant/crm.php#crm-loyalty-analytics" class="mt-3 inline-flex items-center gap-1 text-xs font-medium text-sky-300 hover:text-sky-200">Перейти к CRM revenue <span aria-hidden="true">→</span></a>
                    <?php endif; ?>
                </div>

                <div class="rounded-xl border border-amber-500/20 bg-amber-500/5 px-4 py-4">
                    <div class="text-[11px] font-semibold uppercase tracking-wide text-amber-300">Лучшая upsell-пара сейчас</div>
                    <?php if (!$upsellEnabled): ?>
                        <div class="mt-2 text-sm font-semibold text-gray-500">Upsell выключен в тарифе</div>
                        <div class="mt-1 text-[11px] text-gray-500">После включения покажем сильнейшую пару и точки роста среднего чека.</div>
                    <?php elseif ($bestUpsellLabel === ''): ?>
                        <div class="mt-2 text-sm font-semibold text-gray-500">Пока мало данных</div>
                        <div class="mt-1 text-[11px] text-gray-500">Сначала соберите показы и accepted events по парам.</div>
                    <?php else: ?>
                        <div class="mt-2 text-base font-semibold leading-snug text-[#F3F4F6]"><?= e($bestUpsellLabel) ?></div>
                        <div class="mt-1 text-[11px] text-gray-400">
                            <?= (string)($bestUpsellPair['status_text'] ?? 'Работает') ?>
                            · attach rate <?= number_format((float)($bestUpsellPair['attach_rate_pct'] ?? 0), 1, '.', ' ') ?>%
                        </div>
                        <div class="mt-1 text-[11px] text-gray-500">
                            <?= e((string)($bestUpsellPair['rule_summary_text'] ?? '')) ?>
                        </div>
                        <a href="/restaurant/analytics_upsell.php" class="mt-3 inline-flex items-center gap-1 text-xs font-medium text-amber-300 hover:text-amber-200">Открыть upsell optimization <span aria-hidden="true">→</span></a>
                    <?php endif; ?>
                </div>
            </div>

            <div class="grid grid-cols-1 xl:grid-cols-3 gap-3">
                <div class="xl:col-span-2 rounded-xl border border-gray-800 bg-gray-900/35 px-4 py-4">
                    <div class="text-[11px] font-semibold uppercase tracking-wide text-gray-400">Что сделать дальше</div>
                    <div class="mt-3 grid gap-3 md:grid-cols-3">
                        <?php foreach (array_slice($managerBusinessActions, 0, 3) as $action): ?>
                            <?php
                            $tone = (string)($action['tone'] ?? 'slate');
                            $toneClasses = [
                                'emerald' => 'border-emerald-500/25 bg-emerald-500/5 text-emerald-300',
                                'indigo' => 'border-indigo-500/25 bg-indigo-500/5 text-indigo-300',
                                'sky' => 'border-sky-500/25 bg-sky-500/5 text-sky-300',
                                'amber' => 'border-amber-500/25 bg-amber-500/5 text-amber-300',
                                'slate' => 'border-gray-700 bg-gray-900/50 text-gray-300',
                            ];
                            $toneClass = $toneClasses[$tone] ?? $toneClasses['slate'];
                            ?>
                            <a href="<?= e((string)($action['url'] ?? '/restaurant/dashboard.php')) ?>" class="rounded-xl border px-3 py-3 transition-colors hover:border-gray-600 <?= e($toneClass) ?>">
                                <div class="text-sm font-semibold text-[#F3F4F6]"><?= e((string)($action['title'] ?? 'Открыть раздел')) ?></div>
                                <div class="mt-1 text-[11px] text-gray-400 leading-relaxed"><?= e((string)($action['text'] ?? '')) ?></div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="rounded-xl border border-gray-800 bg-gray-900/35 px-4 py-4">
                    <div class="text-[11px] font-semibold uppercase tracking-wide text-gray-400">Что прямо сейчас в фокусе</div>
                    <ul class="mt-3 space-y-2 text-sm text-gray-300">
                        <li class="flex items-start gap-2">
                            <span class="mt-1 text-emerald-400">•</span>
                            <span>В CRM приоритетная очередь: <span class="font-semibold text-[#F3F4F6]"><?= count($retentionPriorityQueue) ?></span> гостей.</span>
                        </li>
                        <li class="flex items-start gap-2">
                            <span class="mt-1 text-indigo-400">•</span>
                            <span>В send-board готово к работе: <span class="font-semibold text-[#F3F4F6]"><?= $retentionReadyNow ?></span> retention-сообщений.</span>
                        </li>
                        <li class="flex items-start gap-2">
                            <span class="mt-1 text-amber-400">•</span>
                            <span>
                                <?php if ($bestUpsellLabel !== ''): ?>
                                    Upsell-фокус: <span class="font-semibold text-[#F3F4F6]"><?= e($bestUpsellLabel) ?></span>.
                                <?php else: ?>
                                    Upsell-фокус: собрать больше данных по парам и accepted events.
                                <?php endif; ?>
                            </span>
                        </li>
                    </ul>
                </div>
            </div>
        </section>

        <?php if (is_demo_mode()): ?>
        <div class="rounded-xl bg-amber-500/10 border border-amber-500/40 px-4 py-2.5 flex flex-wrap items-center justify-center gap-3 text-sm text-amber-200">
            <span aria-hidden="true">⚠</span>
            <span>Демо-версия — действия имитируются.</span>
            <?php if ($demoShareUrl !== ''): ?>
            <button type="button" class="js-copy-link px-3 py-1.5 rounded-lg bg-amber-500/20 hover:bg-amber-500/30 text-amber-100 text-sm font-medium border border-amber-500/40 relative" data-copy-text="<?= e($demoShareUrl) ?>">
                Поделиться демо
                <span class="js-copy-tooltip hidden absolute left-1/2 -translate-x-1/2 -top-8 px-2 py-1 rounded bg-emerald-600 text-white text-xs whitespace-nowrap">Скопировано!</span>
            </button>
            <?php endif; ?>
        </div>

        <!-- Demo Welcome Panel -->
        <div class="rounded-xl border border-indigo-500/30 bg-indigo-500/5 p-6 card-motion space-y-5">
            <h2 class="text-xl font-semibold text-[#F3F4F6]">Добро пожаловать в демо-версию</h2>
            <p class="text-sm text-gray-400">Познакомьтесь с платформой QR Restaurant. Регистрация не нужна — попробуйте заказ через QR.</p>
            <div class="flex flex-wrap gap-3">
                <a href="/restaurant/crm.php" class="btn-motion inline-flex items-center px-4 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-medium">Посмотреть CRM</a>
                <a href="/restaurant/revenue.php" class="btn-motion inline-flex items-center px-4 py-2.5 rounded-xl bg-gray-800 hover:bg-gray-700 text-[#F3F4F6] text-sm font-medium border border-gray-700">Посмотреть аналитику выручки</a>
                <a href="/qr.php?table_id=1" class="btn-motion inline-flex items-center px-4 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white text-sm font-medium">Попробовать заказ через QR</a>
            </div>
            <div class="flex flex-wrap gap-2 pt-2">
                <span class="px-2.5 py-1 rounded-lg bg-indigo-500/20 text-indigo-300 text-xs font-medium">ИИ-подсказки</span>
                <span class="px-2.5 py-1 rounded-lg bg-emerald-500/20 text-emerald-300 text-xs font-medium">Возврат гостей</span>
                <span class="px-2.5 py-1 rounded-lg bg-amber-500/20 text-amber-300 text-xs font-medium">Допродажи</span>
            </div>
        </div>

        <!-- Guided Demo Steps -->
        <div class="rounded-xl border border-gray-800 bg-[#121826] p-5 card-motion">
            <h3 class="text-sm font-semibold text-[#F3F4F6] mb-4">Шаги демо</h3>
            <ol class="space-y-3 text-sm">
                <li class="flex items-center gap-3">
                    <span class="flex-shrink-0 w-6 h-6 rounded-full bg-indigo-500/30 text-indigo-300 flex items-center justify-center text-xs font-semibold">1</span>
                    <span class="text-gray-400">Аналитика панели</span>
                    <a href="/restaurant/dashboard.php" class="ml-auto text-indigo-400 hover:text-indigo-300 text-xs">Вы здесь</a>
                </li>
                <li class="flex items-center gap-3">
                    <span class="flex-shrink-0 w-6 h-6 rounded-full bg-gray-700 text-gray-400 flex items-center justify-center text-xs font-semibold">2</span>
                    <span class="text-gray-400">Работа с гостями (CRM)</span>
                    <a href="/restaurant/crm.php" class="ml-auto px-2 py-1 rounded-lg bg-gray-800 hover:bg-gray-700 text-indigo-400 text-xs">Перейти →</a>
                </li>
                <li class="flex items-center gap-3">
                    <span class="flex-shrink-0 w-6 h-6 rounded-full bg-gray-700 text-gray-400 flex items-center justify-center text-xs font-semibold">3</span>
                    <span class="text-gray-400">Подсказки допродаж</span>
                    <a href="/restaurant/upsells.php" class="ml-auto px-2 py-1 rounded-lg bg-gray-800 hover:bg-gray-700 text-indigo-400 text-xs">Перейти →</a>
                </li>
                <li class="flex items-center gap-3">
                    <span class="flex-shrink-0 w-6 h-6 rounded-full bg-gray-700 text-gray-400 flex items-center justify-center text-xs font-semibold">4</span>
                    <span class="text-gray-400">Заказ через QR</span>
                    <a href="/qr.php?table_id=1" class="ml-auto px-2 py-1 rounded-lg bg-emerald-600/20 hover:bg-emerald-500/30 text-emerald-300 text-xs">Сделать демо-заказ</a>
                </li>
            </ol>
            <a href="/qr.php?table_id=1" class="mt-4 btn-motion inline-flex items-center px-4 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white text-sm font-medium">Place demo order</a>
        </div>

        <!-- Demo success banner (shown after 3 pages visited) -->
        <div id="demo-success-banner" class="hidden rounded-xl border border-emerald-500/40 bg-emerald-500/10 p-5 card-motion">
            <p class="text-base font-medium text-emerald-100 mb-3">Like what you see? Start your own restaurant in minutes.</p>
            <a href="/signup.php" class="btn-motion inline-flex items-center px-5 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white text-sm font-semibold">Start Free Trial</a>
        </div>
        <?php endif; ?>

        <?php
        // Lightweight restaurant activation checklist (usage/read-only; no blocking).
        $actState = function_exists('get_restaurant_onboarding_state') ? get_restaurant_onboarding_state($restId) : null;
        $actSteps = function_exists('get_restaurant_onboarding_steps') ? get_restaurant_onboarding_steps($restId) : [];

        $actPercent = (int)($actState['percent_complete'] ?? 0);
        $actNext = (string)($actState['next_step'] ?? '');
        $actCompleted = $actState['completed_steps'] ?? [];

        $firstOrderDone = in_array('first_order_received', $actCompleted, true);
        $anyPremiumDone = in_array('crm_opened', $actCompleted, true) || in_array('upsell_rule_created', $actCompleted, true) || in_array('loyalty_opened', $actCompleted, true);
        $coreDone =
            in_array('menu_created', $actCompleted, true) &&
            in_array('first_table_created', $actCompleted, true) &&
            in_array('qr_ready', $actCompleted, true) &&
            in_array('first_order_received', $actCompleted, true);
        $activationStepMeta = [
            'menu_created' => [
                'title' => 'Соберите меню для первого заказа',
                'short' => 'Меню готово',
                'why' => 'Без базового меню гость не сможет оформить нормальный QR-заказ.',
                'outcome' => 'Гости увидят рабочее меню и смогут положить блюда в корзину.',
                'phase' => 'launch',
                'cta' => 'Открыть меню',
            ],
            'first_table_created' => [
                'title' => 'Добавьте хотя бы один стол',
                'short' => 'Столы и QR',
                'why' => 'Столы создают реальные точки входа в QR-заказ.',
                'outcome' => 'Команда сможет открыть первое рабочее QR-меню на конкретном столе.',
                'phase' => 'launch',
                'cta' => 'Добавить стол',
            ],
            'qr_ready' => [
                'title' => 'Подготовьте QR к реальному использованию',
                'short' => 'QR готов',
                'why' => 'Печать и проверка QR подтверждают, что гость реально сможет открыть меню.',
                'outcome' => 'Ресторан будет готов к первому живому тесту через QR.',
                'phase' => 'launch',
                'cta' => 'Открыть QR-печать',
            ],
            'first_order_received' => [
                'title' => 'Получите первый оплаченный заказ',
                'short' => 'Первый paid order',
                'why' => 'Именно первый оплаченный заказ подтверждает, что запуск состоялся по-настоящему.',
                'outcome' => 'На dashboard появятся реальные заказы, выручка и операционные метрики.',
                'phase' => 'launch',
                'cta' => 'Открыть QR-меню',
            ],
            'crm_opened' => [
                'title' => 'Запустите первый retention-сценарий',
                'short' => 'CRM и возвраты',
                'why' => 'После запуска CRM начинает возвращать гостей, а не просто хранить контакты.',
                'outcome' => 'Появятся drafts, send-board и первые возвраты после retention touch.',
                'phase' => 'growth',
                'cta' => 'Открыть CRM',
            ],
            'upsell_rule_created' => [
                'title' => 'Включите первую допродажу',
                'short' => 'Upsell',
                'why' => 'Одна рабочая upsell-пара уже помогает поднять средний чек.',
                'outcome' => 'Начнут собираться accepted events, лучшие пары и точки роста по upsell.',
                'phase' => 'growth',
                'cta' => 'Открыть upsell',
            ],
            'loyalty_opened' => [
                'title' => 'Включите loyalty для повторных визитов',
                'short' => 'Loyalty',
                'why' => 'Loyalty удерживает гостей и усиливает CRM-сценарии возврата.',
                'outcome' => 'Откроются бонусы, redemption и loyalty-driven retention.',
                'phase' => 'growth',
                'cta' => 'Открыть loyalty',
            ],
        ];
        $launchStepSlugs = ['menu_created', 'first_table_created', 'qr_ready', 'first_order_received'];
        $growthStepSlugs = ['crm_opened', 'upsell_rule_created', 'loyalty_opened'];

        // Show checklist only while core launch milestones are not fully completed.
        $shouldShowActivation = !is_demo_mode() && $restId > 0 && $actSteps && !$coreDone;
        // Completion banner uses the same activation model (legacy $ob* variables were removed).
        $shouldShowActivationComplete = !is_demo_mode() && $restId > 0 && $actSteps && $coreDone;

        // Avoid duplicating the existing "Try placing a test order" CTA.
        if ($actNext === 'first_order_received' && !$lastOrders && in_array('qr_ready', $actCompleted, true)) {
            $shouldShowActivation = false;
        }

        if ($shouldShowActivation): ?>
        <div class="rounded-xl border border-gray-800 bg-[#121826] shadow-xl p-5 card-motion space-y-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <h3 class="text-lg font-semibold text-[#F3F4F6]">🚀 Активация ресторана</h3>
                        <span class="px-2 py-1 rounded-full border border-indigo-500/20 bg-indigo-500/10 text-[11px] text-indigo-200">До первого value</span>
                    </div>
                    <div class="text-xs text-gray-400 mt-1">Доведите ресторан до первого оплаченного заказа, чтобы система начала приносить реальную выручку, а потом подключайте CRM, upsell и loyalty как следующий слой роста.</div>
                </div>
                <div class="text-right">
                    <div class="text-xs text-gray-500">Общая готовность</div>
                    <div class="text-sm font-medium text-[#F3F4F6]"><?= (int)$actPercent ?>% · <?= e($actState['status'] ?? 'Старт') ?></div>
                </div>
            </div>

            <?php
            $totalSteps = count($actSteps);
            $doneCount = 0;
            $nextUrl = null;
            $nextLabel = null;
            $nextMeta = null;
            $launchDoneCount = 0;
            $growthDoneCount = 0;
            $launchSteps = [];
            $growthSteps = [];
            foreach ($actSteps as $st) {
                if (!empty($st['done'])) {
                    $doneCount++;
                }
                if (!empty($st['slug']) && $st['slug'] === $actNext) {
                    $nextUrl = $st['url'] ?? null;
                    $nextLabel = $st['label'] ?? null;
                    $nextMeta = $activationStepMeta[(string)$st['slug']] ?? null;
                }
                $slug = (string)($st['slug'] ?? '');
                $meta = $activationStepMeta[$slug] ?? ['short' => (string)($st['label'] ?? $slug), 'phase' => 'growth'];
                $st['_meta'] = $meta;
                if (in_array($slug, $launchStepSlugs, true)) {
                    $launchSteps[] = $st;
                    if (!empty($st['done'])) {
                        $launchDoneCount++;
                    }
                } else {
                    $growthSteps[] = $st;
                    if (!empty($st['done'])) {
                        $growthDoneCount++;
                    }
                }
            }
            $nextWhy = (string)($nextMeta['why'] ?? 'Это следующий шаг, который приблизит ресторан к рабочему запуску.');
            $nextOutcome = (string)($nextMeta['outcome'] ?? 'После этого шага система станет полезнее в ежедневной работе.');
            $nextCta = (string)($nextMeta['cta'] ?? ($nextLabel ?: 'Открыть следующий шаг'));
            $launchPercent = (int)round(($launchDoneCount / max(1, count($launchStepSlugs))) * 100);
            $growthPercent = (int)round(($growthDoneCount / max(1, count($growthStepSlugs))) * 100);
            $launchRemaining = max(0, count($launchStepSlugs) - $launchDoneCount);
            $growthRemaining = max(0, count($growthStepSlugs) - $growthDoneCount);
            $launchSummary = $launchRemaining <= 1
                ? 'Остался последний шаг до первого оплаченного заказа.'
                : 'До первого оплаченного заказа осталось ' . $launchRemaining . ' шага.';
            $growthSummary = $growthDoneCount > 0
                ? 'Часть growth-инструментов уже включена и сможет заработать сразу после запуска.'
                : 'CRM, upsell и loyalty можно включать сразу после базового запуска — это второй слой роста.';
            ?>
            <div class="flex items-center gap-3">
                <div class="flex-1 h-2.5 rounded-full bg-gray-800 overflow-hidden">
                    <div class="h-full rounded-full bg-gradient-to-r from-indigo-500 via-sky-500 to-emerald-500 transition-all duration-300" style="width: <?= (int)$actPercent ?>%"></div>
                </div>
                <span class="text-xs text-gray-400 whitespace-nowrap"><?= (int)$doneCount ?> / <?= (int)$totalSteps ?> шагов</span>
            </div>

            <div class="grid grid-cols-1 xl:grid-cols-3 gap-4">
                <div class="xl:col-span-1 rounded-xl border border-indigo-500/20 bg-indigo-500/5 p-4 space-y-3">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="text-[11px] font-semibold uppercase tracking-wide text-indigo-300">Следующий лучший шаг</span>
                        <span class="px-2 py-1 rounded-full bg-gray-900/80 border border-gray-800 text-[11px] text-gray-300"><?= e($launchSummary) ?></span>
                    </div>
                    <div>
                        <div class="text-base font-semibold text-[#F3F4F6]"><?= e((string)($nextMeta['title'] ?? $nextLabel ?? 'Продолжить запуск')) ?></div>
                        <div class="text-xs text-gray-400 mt-1">Сейчас это самый короткий путь к живому запуску ресторана.</div>
                    </div>
                    <div class="rounded-lg border border-gray-800 bg-[#0B0F19]/70 px-3 py-3 space-y-3">
                        <div>
                            <div class="text-[11px] text-gray-500 uppercase tracking-wide">Почему это важно</div>
                            <div class="text-sm text-gray-300 mt-1"><?= e($nextWhy) ?></div>
                        </div>
                        <div class="border-t border-gray-800 pt-3">
                            <div class="text-[11px] text-gray-500 uppercase tracking-wide">Что откроется после шага</div>
                            <div class="text-sm text-gray-300 mt-1"><?= e($nextOutcome) ?></div>
                        </div>
                    </div>
                    <?php if (!empty($nextUrl)): ?>
                        <a href="<?= e($nextUrl) ?>" class="btn-motion inline-flex items-center justify-center px-4 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-medium w-full">
                            <?= e($nextCta) ?>
                        </a>
                    <?php endif; ?>
                    <div class="rounded-lg border border-indigo-500/10 bg-indigo-500/5 px-3 py-2 text-[11px] text-indigo-100/90">
                        Сначала запуститесь по-настоящему: меню, столы, QR и первый оплаченный заказ. CRM, upsell и loyalty останутся следующим слоем роста после этого момента.
                    </div>
                </div>

                <div class="xl:col-span-2 space-y-3">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <div class="rounded-xl border border-emerald-500/20 bg-emerald-500/5 p-4">
                            <div class="flex items-center justify-between gap-2">
                                <div>
                                    <div class="text-[11px] font-semibold uppercase tracking-wide text-emerald-300">Базовый запуск</div>
                                    <div class="text-sm text-[#F3F4F6] mt-1">Меню, столы, QR и первый оплаченный заказ</div>
                                </div>
                                <div class="text-right">
                                    <div class="text-sm font-semibold text-[#F3F4F6]"><?= $launchDoneCount ?> / <?= count($launchStepSlugs) ?></div>
                                    <div class="text-[11px] text-gray-500"><?= $launchPercent ?>%</div>
                                </div>
                            </div>
                            <div class="mt-2 text-xs text-gray-400"><?= e($launchSummary) ?></div>
                            <div class="mt-3 flex-1 h-2 rounded-full bg-gray-800 overflow-hidden">
                                <div class="h-full rounded-full bg-emerald-500 transition-all duration-300" style="width: <?= $launchPercent ?>%"></div>
                            </div>
                            <ul class="mt-3 space-y-2 text-sm">
                                <?php foreach ($launchSteps as $st): ?>
                                    <?php
                                    $done = !empty($st['done']);
                                    $current = !empty($st['slug']) && $st['slug'] === $actNext;
                                    $meta = $st['_meta'] ?? [];
                                    ?>
                                    <li class="flex items-start gap-3 rounded-lg border <?= $done ? 'border-emerald-500/15 bg-emerald-500/5' : ($current ? 'border-indigo-500/20 bg-indigo-500/5' : 'border-gray-800 bg-gray-900/20') ?> px-3 py-2">
                                        <span class="mt-0.5 <?= $done ? 'text-emerald-400' : ($current ? 'text-indigo-400' : 'text-gray-500') ?>" aria-hidden="true">
                                            <?= $done ? '✔' : ($current ? '→' : '○') ?>
                                        </span>
                                        <div class="min-w-0">
                                            <div class="<?= $done ? 'text-gray-200' : ($current ? 'text-indigo-200' : 'text-gray-400') ?>">
                                                <?= e((string)($meta['short'] ?? $st['label'] ?? $st['slug'])) ?>
                                            </div>
                                            <?php if ($current): ?>
                                                <div class="text-[11px] text-gray-500 mt-1"><?= e((string)($meta['why'] ?? 'Это следующий шаг в запуске.')) ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>

                        <div class="rounded-xl border border-sky-500/20 bg-sky-500/5 p-4">
                            <div class="flex items-center justify-between gap-2">
                                <div>
                                    <div class="text-[11px] font-semibold uppercase tracking-wide text-sky-300">Рост после запуска</div>
                                    <div class="text-sm text-[#F3F4F6] mt-1">CRM, upsell и loyalty для повторной выручки</div>
                                </div>
                                <div class="text-right">
                                    <div class="text-sm font-semibold text-[#F3F4F6]"><?= $growthDoneCount ?> / <?= count($growthStepSlugs) ?></div>
                                    <div class="text-[11px] text-gray-500"><?= $growthPercent ?>%</div>
                                </div>
                            </div>
                            <div class="mt-2 text-xs text-gray-400"><?= e($growthSummary) ?></div>
                            <div class="mt-3 flex-1 h-2 rounded-full bg-gray-800 overflow-hidden">
                                <div class="h-full rounded-full bg-sky-500 transition-all duration-300" style="width: <?= $growthPercent ?>%"></div>
                            </div>
                            <ul class="mt-3 space-y-2 text-sm">
                                <?php foreach ($growthSteps as $st): ?>
                                    <?php
                                    $done = !empty($st['done']);
                                    $current = !empty($st['slug']) && $st['slug'] === $actNext;
                                    $meta = $st['_meta'] ?? [];
                                    ?>
                                    <li class="flex items-start gap-3 rounded-lg border <?= $done ? 'border-sky-500/15 bg-sky-500/5' : 'border-gray-800 bg-gray-900/20' ?> px-3 py-2">
                                        <span class="mt-0.5 <?= $done ? 'text-emerald-400' : 'text-gray-500' ?>" aria-hidden="true">
                                            <?= $done ? '✔' : '○' ?>
                                        </span>
                                        <div class="min-w-0">
                                            <div class="<?= $done ? 'text-gray-200' : 'text-gray-400' ?>">
                                                <?= e((string)($meta['short'] ?? $st['label'] ?? $st['slug'])) ?>
                                            </div>
                                            <?php if (!$done): ?>
                                                <div class="text-[11px] text-gray-500 mt-1"><?= e((string)($meta['outcome'] ?? 'Этот шаг усилит рост после запуска.')) ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>

                    <div class="rounded-xl border border-gray-800 bg-gray-900/40 px-4 py-3">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div class="text-[11px] font-semibold uppercase tracking-wide text-gray-500">Что уже откроется после базового запуска</div>
                            <div class="text-[11px] text-gray-500"><?= $growthRemaining > 0 ? 'Следом будут доступны growth-инструменты' : 'Growth-слой уже тоже готов' ?></div>
                        </div>
                        <div class="mt-2 text-sm text-gray-300">
                            <?php if (!$firstOrderDone): ?>
                                После первого оплаченного заказа dashboard начнёт показывать реальные заказы, выручку и операционную картину дня. Это точка, после которой CRM, upsell и loyalty становятся по-настоящему полезными.
                            <?php elseif (!$anyPremiumDone): ?>
                                Базовый запуск уже даёт вам реальные заказы. Следующий слой ценности — возврат гостей, рост среднего чека и loyalty.
                            <?php else: ?>
                                У вас уже есть базовый запуск и первые growth-инструменты. Теперь можно системно усиливать retention, loyalty и upsell.
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <?php
            // Gentle monetization nudges based on real activation progress (subtle; no blocking).
            $crmDone = in_array('crm_opened', $actCompleted, true);
            $upsellDone = in_array('upsell_rule_created', $actCompleted, true);
            $loyaltyDone = in_array('loyalty_opened', $actCompleted, true);

            $meaningful = $firstOrderDone && $actPercent >= 50;

            // Anti-clutter: do not show onboarding monetization hints if a stronger top-level upgrade nudge is already likely.
            // (We reuse the same priority order as the dashboard $topNudge logic.)
            $hasTopLevelNudge = false;
            if ($ordersLimit !== null && $ordersUsed !== null && $ordersLimit > 0 && $ordersUsagePercent >= 80) {
                $hasTopLevelNudge = true;
            } elseif (is_array($upgradeReadiness) && !empty($upgradeReadiness['readiness'])) {
                // Canonical readiness-based top nudge would show.
                if (in_array($upgradeReadiness['readiness'], ['ready_for_growth', 'ready_for_pro'], true)) {
                    $hasTopLevelNudge = true;
                }
            }
            ?>
            <?php
            $showGrowthHint = (!$hasTopLevelNudge && is_array($upgradeReadiness) && ($upgradeReadiness['readiness'] ?? '') === 'ready_for_growth');
            $showProHint = (!$hasTopLevelNudge && is_array($upgradeReadiness) && ($upgradeReadiness['readiness'] ?? '') === 'ready_for_pro');
            ?>
            <?php if ($showGrowthHint): ?>
                <div class="rounded-lg border border-amber-500/30 bg-amber-500/10 px-3 py-2 text-xs text-amber-100">
                    <?= htmlspecialchars((string)($upgradeReadiness['reason'] ?? 'Перейдите на GROWTH'), ENT_QUOTES, 'UTF-8') ?>
                    <a href="/restaurant/activate.php?plan=growth" class="ml-2 text-amber-200 hover:text-amber-100 font-medium inline-flex items-center">Открыть тариф</a>
                </div>
            <?php elseif ($showProHint): ?>
                <div class="rounded-lg border border-indigo-500/30 bg-indigo-500/10 px-3 py-2 text-xs text-indigo-100">
                    <?= htmlspecialchars((string)($upgradeReadiness['reason'] ?? 'Перейдите на PRO'), ENT_QUOTES, 'UTF-8') ?>
                    <a href="/restaurant/activate.php?plan=pro" class="ml-2 text-indigo-200 hover:text-indigo-100 font-medium inline-flex items-center">Открыть тариф</a>
                </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($shouldShowActivationComplete): ?>
        <?php
        $crmDone = in_array('crm_opened', $actCompleted, true);
        $upsellDone = in_array('upsell_rule_created', $actCompleted, true);
        $loyaltyDone = in_array('loyalty_opened', $actCompleted, true);
        $menuCompletenessValue = is_array($healthBreakdown['components']['menu_completeness']['value'] ?? null)
            ? $healthBreakdown['components']['menu_completeness']['value']
            : [];
        $menuItemsCount = (int)($menuCompletenessValue['items'] ?? 0);
        $menuPhotoCoverage = isset($menuCompletenessValue['photo_coverage_pct']) ? (float)$menuCompletenessValue['photo_coverage_pct'] : null;
        $growthBridgeCandidates = [];

        if ($crmEnabled && $retentionReadyNow > 0) {
            $growthBridgeCandidates[] = [
                'key' => 'crm_send_now',
                'priority' => 120,
                'tone' => 'emerald',
                'label' => 'Следующий лучший шаг для роста',
                'title' => 'Отправить готовые retention-сообщения',
                'why' => 'У вас уже есть гости, для которых сценарий возврата собран и готов к ручной отправке.',
                'outcome' => 'Это самый быстрый путь превратить CRM из настройки в реальные повторные визиты и выручку.',
                'cta' => 'Открыть send-board',
                'url' => '/restaurant/crm.php#crm-send-board',
            ];
        }

        if ($crmEnabled && !empty($retentionPriorityQueue)) {
            $topCandidate = is_array($retentionPriorityQueue[0] ?? null) ? $retentionPriorityQueue[0] : [];
            $growthBridgeCandidates[] = [
                'key' => 'crm_priority_queue',
                'priority' => 110,
                'tone' => 'indigo',
                'label' => 'Следующий лучший шаг для роста',
                'title' => $crmDone ? 'Запустить следующий retention touch' : 'Запустить первый retention-сценарий',
                'why' => 'После первого оплаченного заказа CRM уже может начать возвращать гостей, а не только собирать историю.',
                'outcome' => 'В приоритетной очереди уже есть гости, которых можно вернуть сценарием «' . (string)($topCandidate['segment_label'] ?? $topCandidate['segment_type'] ?? 'retention') . '».',
                'cta' => 'Открыть приоритетную очередь',
                'url' => '/restaurant/crm.php#crm-priority-queue',
            ];
        }

        if ($loyaltyEnabled && !$loyaltyDone) {
            $growthBridgeCandidates[] = [
                'key' => 'loyalty_enable',
                'priority' => 100,
                'tone' => 'sky',
                'label' => 'Следующий лучший шаг для роста',
                'title' => 'Включить loyalty для повторных визитов',
                'why' => 'Бонусный баланс усиливает возвращаемость и делает retention-сценарии убедительнее.',
                'outcome' => 'После включения loyalty откроются бонусы, redemption и loyalty-driven CRM.',
                'cta' => 'Открыть loyalty',
                'url' => '/restaurant/loyalty_settings.php',
            ];
        }

        if ($upsellEnabled && !$upsellDone) {
            $growthBridgeCandidates[] = [
                'key' => 'upsell_first_rule',
                'priority' => 95,
                'tone' => 'amber',
                'label' => 'Следующий лучший шаг для роста',
                'title' => 'Добавить первую upsell-пару',
                'why' => 'Даже 1–2 релевантные допродажи могут начать поднимать средний чек без нового трафика.',
                'outcome' => 'Начнут собираться working pairs, accepted events и optimization insights по допродажам.',
                'cta' => 'Открыть upsell',
                'url' => '/restaurant/upsells.php',
            ];
        }

        if ($menuItemsCount > 0 && $menuPhotoCoverage !== null && $menuPhotoCoverage < 60) {
            $growthBridgeCandidates[] = [
                'key' => 'menu_photos',
                'priority' => 90,
                'tone' => 'slate',
                'label' => 'Быстрый рост конверсии',
                'title' => 'Добавить фото к блюдам',
                'why' => 'Сейчас фото есть только у ' . number_format($menuPhotoCoverage, 0, '.', ' ') . '% меню, а изображения заметно помогают выбору блюда.',
                'outcome' => 'Лучше собранное меню повышает конверсию, усиливает upsell и делает guest QR flow вкуснее.',
                'cta' => 'Открыть меню',
                'url' => '/restaurant/menu_manage.php',
            ];
        }

        if ($upsellEnabled && !empty($upsellOptimizationDashboard['needs_attention'][0])) {
            $weakPair = $upsellOptimizationDashboard['needs_attention'][0];
            $growthBridgeCandidates[] = [
                'key' => 'upsell_optimize',
                'priority' => 80,
                'tone' => 'amber',
                'label' => 'Точка роста по среднему чеку',
                'title' => 'Пересобрать слабую допродажу',
                'why' => 'Пара «' . (string)($weakPair['base_item_name'] ?? 'Блюдо') . ' → ' . (string)($weakPair['upsell_item_name'] ?? 'дополнение') . '» часто показывается, но редко добавляется.',
                'outcome' => 'После корректировки можно быстрее получить более сильную working pair без большого редизайна меню.',
                'cta' => 'Открыть upsell optimization',
                'url' => '/restaurant/analytics_upsell.php',
            ];
        }

        if ($growthBridgeCandidates === []) {
            $growthBridgeCandidates[] = [
                'key' => 'crm_overview',
                'priority' => 10,
                'tone' => 'indigo',
                'label' => 'Следующий лучший шаг для роста',
                'title' => 'Открыть CRM command-center',
                'why' => 'Базовый запуск уже завершён, и теперь главный источник роста — работа с возвратом гостей.',
                'outcome' => 'Вы увидите priority queue, send-board и лучшие loyalty-сценарии на одном экране.',
                'cta' => 'Открыть CRM',
                'url' => '/restaurant/crm.php',
            ];
        }

        usort($growthBridgeCandidates, static function (array $a, array $b): int {
            return ((int)($b['priority'] ?? 0) <=> (int)($a['priority'] ?? 0))
                ?: strcmp((string)($a['key'] ?? ''), (string)($b['key'] ?? ''));
        });
        $growthBridgePrimary = $growthBridgeCandidates[0];
        $growthBridgeSecondary = array_slice($growthBridgeCandidates, 1, 2);
        $growthToneClasses = [
            'emerald' => ['chip' => 'text-emerald-300 bg-emerald-500/10 border-emerald-500/20', 'button' => 'bg-emerald-600 hover:bg-emerald-500 text-white', 'card' => 'border-emerald-500/20 bg-emerald-500/5'],
            'indigo' => ['chip' => 'text-indigo-300 bg-indigo-500/10 border-indigo-500/20', 'button' => 'bg-indigo-600 hover:bg-indigo-500 text-white', 'card' => 'border-indigo-500/20 bg-indigo-500/5'],
            'sky' => ['chip' => 'text-sky-300 bg-sky-500/10 border-sky-500/20', 'button' => 'bg-sky-600 hover:bg-sky-500 text-white', 'card' => 'border-sky-500/20 bg-sky-500/5'],
            'amber' => ['chip' => 'text-amber-300 bg-amber-500/10 border-amber-500/20', 'button' => 'bg-amber-600 hover:bg-amber-500 text-white', 'card' => 'border-amber-500/20 bg-amber-500/5'],
            'slate' => ['chip' => 'text-gray-300 bg-gray-900/80 border-gray-700', 'button' => 'bg-gray-800 hover:bg-gray-700 text-white', 'card' => 'border-gray-700 bg-gray-900/40'],
        ];
        $bridgeTone = $growthToneClasses[(string)($growthBridgePrimary['tone'] ?? 'indigo')] ?? $growthToneClasses['indigo'];
        ?>
        <div class="rounded-xl border border-gray-800 bg-[#121826] shadow-xl p-5 card-motion space-y-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <h3 class="text-lg font-semibold text-[#F3F4F6]">🎉 Ресторан уже запущен</h3>
                        <span class="px-2 py-1 rounded-full border <?= e($bridgeTone['chip']) ?> text-[11px]">Теперь главный фокус — рост</span>
                    </div>
                    <div class="text-xs text-gray-400 mt-1">Базовый запуск уже состоялся: меню, столы, QR и первый оплаченный заказ есть. Следующий шаг — включить самый сильный growth-инструмент именно сейчас.</div>
                </div>
                <div class="text-right text-[11px] text-gray-500">
                    <div>Core launch завершён</div>
                    <div><?= $anyPremiumDone ? 'Часть growth-слоя уже включена' : 'Growth-слой ещё можно быстро усилить' ?></div>
                </div>
            </div>

            <div class="grid grid-cols-1 xl:grid-cols-3 gap-4">
                <div class="xl:col-span-2 rounded-xl border <?= e($bridgeTone['card']) ?> p-4 space-y-3">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="text-[11px] font-semibold uppercase tracking-wide text-gray-300"><?= e((string)($growthBridgePrimary['label'] ?? 'Следующий лучший шаг для роста')) ?></span>
                        <span class="px-2 py-1 rounded-full border border-gray-800 bg-gray-900/70 text-[11px] text-gray-300">1 главный action</span>
                    </div>
                    <div>
                        <div class="text-base font-semibold text-[#F3F4F6]"><?= e((string)($growthBridgePrimary['title'] ?? 'Продолжить рост ресторана')) ?></div>
                        <div class="text-sm text-gray-400 mt-1"><?= e((string)($growthBridgePrimary['why'] ?? 'Это лучший следующий шаг после базового запуска.')) ?></div>
                    </div>
                    <div class="rounded-lg border border-gray-800 bg-[#0B0F19]/70 px-3 py-3">
                        <div class="text-[11px] text-gray-500 uppercase tracking-wide">Что это даст бизнесу</div>
                        <div class="text-sm text-gray-300 mt-1"><?= e((string)($growthBridgePrimary['outcome'] ?? 'Откроет следующий слой роста после базового запуска.')) ?></div>
                    </div>
                    <a href="<?= e((string)($growthBridgePrimary['url'] ?? '/restaurant/dashboard.php')) ?>" class="btn-motion inline-flex items-center justify-center px-4 py-2.5 rounded-xl text-sm font-medium w-full md:w-auto <?= e($bridgeTone['button']) ?>">
                        <?= e((string)($growthBridgePrimary['cta'] ?? 'Открыть следующий шаг')) ?>
                    </a>
                </div>

                <div class="rounded-xl border border-gray-800 bg-gray-900/35 p-4">
                    <div class="text-[11px] font-semibold uppercase tracking-wide text-gray-400">Что уже открыто после запуска</div>
                    <ul class="mt-3 space-y-2 text-sm text-gray-300">
                        <li class="flex items-start gap-2">
                            <span class="text-emerald-400 mt-0.5">✔</span>
                            <span>На dashboard уже есть реальные заказы, выручка и операционная картина дня.</span>
                        </li>
                        <li class="flex items-start gap-2">
                            <span class="text-emerald-400 mt-0.5">✔</span>
                            <span>Guest QR flow уже можно усиливать через growth-инструменты, а не только поддерживать в рабочем состоянии.</span>
                        </li>
                        <li class="flex items-start gap-2">
                            <span class="<?= $anyPremiumDone ? 'text-emerald-400' : 'text-gray-500' ?> mt-0.5"><?= $anyPremiumDone ? '✔' : '○' ?></span>
                            <span><?= $anyPremiumDone ? 'Часть CRM / upsell / loyalty уже включена и может давать повторную выручку.' : 'CRM, upsell и loyalty можно подключать по очереди как следующий слой роста.' ?></span>
                        </li>
                    </ul>
                </div>
            </div>

            <?php if ($growthBridgeSecondary !== []): ?>
                <div class="rounded-xl border border-gray-800 bg-gray-900/35 px-4 py-4">
                    <div class="text-[11px] font-semibold uppercase tracking-wide text-gray-400">Ещё 1–2 полезных шага после этого</div>
                    <div class="mt-3 grid gap-3 md:grid-cols-2">
                        <?php foreach ($growthBridgeSecondary as $bridgeAction): ?>
                            <?php
                            $secondaryTone = $growthToneClasses[(string)($bridgeAction['tone'] ?? 'slate')] ?? $growthToneClasses['slate'];
                            ?>
                            <a href="<?= e((string)($bridgeAction['url'] ?? '/restaurant/dashboard.php')) ?>" class="rounded-xl border px-3 py-3 transition-colors hover:border-gray-600 <?= e($secondaryTone['card']) ?>">
                                <div class="text-sm font-semibold text-[#F3F4F6]"><?= e((string)($bridgeAction['title'] ?? 'Открыть следующий шаг')) ?></div>
                                <div class="mt-1 text-[11px] text-gray-400 leading-relaxed"><?= e((string)($bridgeAction['outcome'] ?? $bridgeAction['why'] ?? '')) ?></div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if (!is_demo_mode() && $restId > 0 && !$lastOrders && $restaurantBaseUrl !== '' && isset($actCompleted) && in_array('qr_ready', $actCompleted, true)): ?>
        <div class="rounded-xl border border-gray-800 bg-[#121826] shadow-xl p-4 card-motion flex flex-wrap items-center justify-between gap-3">
            <div>
                <div class="text-sm font-medium text-[#F3F4F6]">Try placing a test order</div>
                <div class="text-xs text-gray-400 mt-0.5">Open the QR menu and submit an order to see it in the dashboard.</div>
            </div>
            <a href="<?= e($restaurantBaseUrl) ?>/qr.php?table_id=1" target="_blank" rel="noopener" class="btn-motion inline-flex items-center px-4 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-medium">
                Открыть QR-меню
            </a>
        </div>
        <?php endif; ?>

        <?php
        $billingCardTone = 'slate';
        if (($billingAccess['status_key'] ?? '') === 'trial') {
            $billingCardTone = 'sky';
        } elseif (($billingAccess['status_key'] ?? '') === 'trial_ending') {
            $billingCardTone = 'amber';
        } elseif (($billingAccess['status_key'] ?? '') === 'active_paid') {
            $billingCardTone = 'emerald';
        } elseif (($billingAccess['status_key'] ?? '') === 'expired') {
            $billingCardTone = 'rose';
        }
        $billingCardClasses = [
            'slate' => 'border-slate-800 bg-[#121826]',
            'sky' => 'border-sky-500/30 bg-sky-500/10',
            'amber' => 'border-amber-500/30 bg-amber-500/10',
            'emerald' => 'border-emerald-500/30 bg-emerald-500/10',
            'rose' => 'border-rose-500/30 bg-rose-500/10',
        ];
        $billingCardClass = $billingCardClasses[$billingCardTone] ?? $billingCardClasses['slate'];
        ?>
        <section class="rounded-xl border shadow-xl p-5 card-motion space-y-4 <?= e($billingCardClass) ?>" aria-labelledby="billing-card-title">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <div class="inline-flex items-center rounded-full border border-white/10 bg-white/5 px-3 py-1 text-[11px] font-semibold uppercase tracking-wide text-slate-200">
                        <?= e((string)($billingAccess['status_label'] ?? 'Статус доступа')) ?>
                    </div>
                    <h3 id="billing-card-title" class="text-sm font-semibold text-[#F3F4F6] mt-3"><?= e((string)($billingAccess['status_heading'] ?? 'Статус доступа ресторана')) ?></h3>
                    <p class="text-xs text-gray-400 mt-1 max-w-3xl"><?= e((string)($billingAccess['status_text'] ?? '')) ?></p>
                </div>
                <a href="/restaurant/activate.php?plan=<?= e(strtolower((string)($billingAccess['paid_plan_code'] ?? 'growth'))) ?>" class="inline-flex items-center px-4 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-medium whitespace-nowrap">
                    <?= e((string)($billingAccess['cta_label'] ?? 'Выбрать тариф')) ?>
                </a>
            </div>

            <div class="flex flex-wrap gap-2 text-[11px] text-gray-300">
                <span class="inline-flex items-center rounded-full border border-white/10 bg-white/5 px-3 py-1">
                    Plan ресторана: <?= e((string)($billingAccess['restaurant_plan_label'] ?? 'FREE')) ?>
                </span>
                <?php if (!empty($billingAccess['is_trial']) && empty($billingAccess['is_expired'])): ?>
                    <span class="inline-flex items-center rounded-full border border-white/10 bg-white/5 px-3 py-1">
                        Осталось <?= (int)($billingAccess['days_left'] ?? 0) ?> дн.
                    </span>
                <?php elseif (!empty($billingAccess['trial_ends_at'])): ?>
                    <span class="inline-flex items-center rounded-full border border-white/10 bg-white/5 px-3 py-1">
                        Trial до <?= e(date('d.m.Y', strtotime((string)$billingAccess['trial_ends_at']))) ?>
                    </span>
                <?php endif; ?>
                <?php if (!empty($billingAccess['has_active_paid_plan'])): ?>
                    <span class="inline-flex items-center rounded-full border border-white/10 bg-white/5 px-3 py-1">
                        Платный доступ активен
                    </span>
                <?php endif; ?>
            </div>

            <div class="grid gap-3 lg:grid-cols-2">
                <div class="rounded-xl border border-white/10 bg-slate-950/30 px-4 py-4">
                    <div class="text-[11px] font-semibold uppercase tracking-wide text-gray-400">Что уже доступно сейчас</div>
                    <div class="text-sm font-semibold text-[#F3F4F6] mt-2"><?= e((string)($billingAccess['restaurant_plan_name'] ?? 'Базовый запуск')) ?></div>
                    <ul class="mt-3 space-y-2 text-sm text-gray-300">
                        <?php foreach ((array)($billingAccess['restaurant_plan_features'] ?? []) as $feature): ?>
                            <li class="flex items-start gap-2">
                                <span class="mt-1 text-emerald-400">•</span>
                                <span><?= e((string)$feature) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <div class="rounded-xl border border-white/10 bg-slate-950/30 px-4 py-4">
                    <div class="text-[11px] font-semibold uppercase tracking-wide text-gray-400">Следующий лучший шаг для тарифа</div>
                    <div class="text-sm font-semibold text-[#F3F4F6] mt-2"><?= e((string)($billingAccess['paid_plan_name'] ?? 'Рост')) ?> · <?= e((string)($billingAccess['paid_plan_label'] ?? 'GROWTH')) ?></div>
                    <ul class="mt-3 space-y-2 text-sm text-gray-300">
                        <?php foreach ((array)($billingAccess['paid_plan_features'] ?? []) as $feature): ?>
                            <li class="flex items-start gap-2">
                                <span class="mt-1 text-sky-400">•</span>
                                <span><?= e((string)$feature) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>

            <?php if (is_array($dashboardMonetization)): ?>
            <div class="rounded-xl border border-white/10 bg-slate-950/30 px-4 py-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <div class="text-[11px] font-semibold uppercase tracking-wide text-gray-400"><?= e((string)($dashboardMonetization['phase_label'] ?? 'Следующий шаг')) ?></div>
                        <div class="text-sm font-semibold text-[#F3F4F6] mt-2"><?= e((string)($dashboardMonetization['why_now'] ?? '')) ?></div>
                        <p class="text-xs text-gray-400 mt-1 max-w-3xl"><?= e((string)($dashboardMonetization['phase_text'] ?? '')) ?></p>
                    </div>
                    <a href="<?= e((string)($dashboardMonetization['cta_url'] ?? '/restaurant/activate.php')) ?>" class="inline-flex items-center px-4 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-medium whitespace-nowrap">
                        <?= e((string)($dashboardMonetization['cta_label'] ?? 'Открыть тариф')) ?>
                    </a>
                </div>
                <ul class="mt-3 grid gap-2 md:grid-cols-2 text-xs text-gray-300">
                    <?php foreach (array_slice((array)($dashboardMonetization['proof_items'] ?? []), 0, 4) as $proof): ?>
                        <li class="flex items-start gap-2">
                            <span class="mt-1 text-emerald-400">•</span>
                            <span><?= e((string)$proof) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>
        </section>

        <header class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="text-2xl font-semibold tracking-tight text-[#F3F4F6] mb-1">Обзор ресторана</h2>
                <div class="text-xs text-gray-500">
                    Главный owner-экран: что происходит сегодня и где точки роста дальше
                </div>
            </div>
            <div class="text-right space-y-0.5 text-xs text-gray-500">
                <div>Сегодня: <?= date('d.m.Y') ?></div>
                <?php if (!is_demo_mode()): ?>
                    <div class="text-[11px] text-gray-400">
                        Тариф: <span class="font-medium text-gray-200"><?= htmlspecialchars($planLabel, ENT_QUOTES, 'UTF-8') ?></span>
                        <?php if ($ordersLimit !== null && $ordersUsed !== null): ?>
                            · Заказы: <?= (int)$ordersUsed ?> / <?= (int)$ordersLimit ?>
                        <?php elseif ($ordersLimit === null): ?>
                            · Заказы: безлимитно
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </header>

        <?php
        // Single top-level upgrade nudge (non-blocking, prioritized).
        $topNudge = null;
        if (!is_demo_mode()) {
            // Priority 1: order limit usage
            if ($ordersLimit !== null && $ordersUsed !== null && $ordersLimit > 0 && $ordersUsagePercent >= 80) {
                $nextPlanLabel = $planKey === 'free' ? 'GROWTH' : ($planKey === 'growth' ? 'PRO' : 'PRO');
                $title = $ordersUsagePercent >= 100
                    ? 'Лимит заказов по тарифу превышен'
                    : 'Вы приближаетесь к лимиту заказов по тарифу';
                $text = 'Вы использовали ' . (int)$ordersUsed . ' из ' . (int)$ordersLimit . ' заказов в этом месяце. Перейдите на ' . $nextPlanLabel . ', чтобы продолжить рост без ограничений.';
                $topNudge = [
                    'kind' => 'orders',
                    'title' => $title,
                    'text' => $text,
                ];
            }
            // Priority 2 (canonical): readiness for next tier based on paid-order truth.
            if ($topNudge === null && is_array($upgradeReadiness) && !empty($upgradeReadiness['readiness'])) {
                if ($upgradeReadiness['readiness'] === 'ready_for_growth') {
                    $topNudge = [
                        'kind' => 'readiness_growth',
                        'title' => 'Вы уже готовы к GROWTH',
                        'text' => (string)($upgradeReadiness['reason'] ?? ''),
                    ];
                } elseif ($upgradeReadiness['readiness'] === 'ready_for_pro') {
                    $topNudge = [
                        'kind' => 'readiness_pro',
                        'title' => 'Вы уже готовы к PRO',
                        'text' => (string)($upgradeReadiness['reason'] ?? ''),
                    ];
                }
            }
        }
        ?>
        <?php if ($topNudge): ?>
        <div class="mt-4 rounded-xl border border-amber-500/40 bg-amber-500/10 px-4 py-3 flex flex-wrap items-center justify-between gap-3 card-motion">
            <div class="space-y-0.5">
                <div class="text-sm font-medium text-amber-200"><?= htmlspecialchars($topNudge['title'], ENT_QUOTES, 'UTF-8') ?></div>
                <div class="text-xs text-amber-200/80"><?= htmlspecialchars($topNudge['text'], ENT_QUOTES, 'UTF-8') ?></div>
            </div>
            <a href="/restaurant/activate.php?plan=<?= e(strtolower($planKey === 'growth' ? 'pro' : 'growth')) ?>" class="inline-flex items-center px-4 py-2 rounded-xl bg-amber-600 hover:bg-amber-500 text-white text-xs font-medium whitespace-nowrap">
                Открыть тариф и доступ
            </a>
        </div>
        <?php endif; ?>
        <?php $hasStrongTopUpgradeNudge = $topNudge !== null; ?>

        <?php
        // Compact growth tools activity section (usage-aware, non-spammy).
        $hasGrowthUsage = ($usageCrmMessages > 0 || $usageUpsellShown > 0 || $usageUpsellAccepted > 0 || $usageLoyaltyTx > 0);
        ?>
        <?php if (!is_demo_mode() && $hasGrowthUsage): ?>
        <section class="mt-4 rounded-xl border border-gray-800 bg-[#121826] shadow-lg shadow-black/20 p-4 card-motion space-y-1.5">
            <div class="flex items-center justify-between gap-2">
                <h3 class="text-sm font-semibold text-[#F3F4F6]">Активность growth-инструментов</h3>
                <span class="text-[11px] text-gray-500">текущий месяц</span>
            </div>
            <div class="space-y-1 text-xs text-gray-300">
                <?php if ($usageCrmMessages > 0): ?>
                    <div>CRM: <?= (int)$usageCrmMessages ?> сообщений в этом месяце</div>
                <?php endif; ?>
                <?php if ($usageUpsellShown > 0 || $usageUpsellAccepted > 0): ?>
                    <div>
                        Upsell:
                        <?php if ($usageUpsellShown > 0): ?>
                            <?= (int)$usageUpsellShown ?> показа<?= $usageUpsellAccepted > 0 ? ',' : '' ?>
                        <?php endif; ?>
                        <?php if ($usageUpsellAccepted > 0): ?>
                            <?= (int)$usageUpsellAccepted ?> добавлений
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                <?php if ($usageLoyaltyTx > 0): ?>
                    <div>Loyalty: <?= (int)$usageLoyaltyTx ?> операций с баллами</div>
                <?php endif; ?>
            </div>
            <?php
            // Prevent duplicate “ready to upgrade” messaging:
            // if the top-level upgrade nudge is already shown, keep this section purely informational.
            $showReadinessHint = ($topNudge === null && is_array($upgradeReadiness) && !empty($upgradeReadiness['readiness']));
            ?>
            <?php if ($showReadinessHint && $upgradeReadiness['readiness'] === 'ready_for_growth'): ?>
                <p class="mt-1 text-[11px] text-amber-200">
                    <?= htmlspecialchars((string)($upgradeReadiness['reason'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                </p>
            <?php elseif ($showReadinessHint && $upgradeReadiness['readiness'] === 'ready_for_pro'): ?>
                <p class="mt-1 text-[11px] text-amber-200">
                    <?= htmlspecialchars((string)($upgradeReadiness['reason'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                </p>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <!-- Restaurant Health Score -->
        <?php
        $hs = (int)($healthScore['score'] ?? 0);
        $hl = (string)($healthScore['label'] ?? 'Improving');
        $hex = (string)($healthScore['explanation'] ?? '');
        $hrec = is_array($healthRecommendations) ? $healthRecommendations : [];
        $labelClass = $hl === 'Strong' ? 'text-emerald-300' : ($hl === 'Healthy' ? 'text-sky-300' : ($hl === 'Improving' ? 'text-amber-300' : 'text-red-300'));
        ?>
        <section class="rounded-xl border border-gray-800 bg-[#121826] shadow-xl p-5 card-motion space-y-4">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h3 class="text-sm font-semibold text-[#F3F4F6]">Restaurant Health Score</h3>
                    <p class="text-xs text-gray-400 mt-1">A transparent, weighted summary of measurable performance and growth readiness.</p>
                </div>
                <div class="text-right">
                    <div class="flex items-baseline justify-end gap-2">
                        <span class="text-3xl font-bold text-[#F3F4F6]"><?= $hs ?></span>
                        <span class="text-gray-500">/ 100</span>
                    </div>
                    <div class="text-sm font-semibold <?= e($labelClass) ?>"><?= e($hl) ?></div>
                </div>
            </div>
            <?php if ($hex !== ''): ?>
                <p class="text-xs text-gray-500"><?= e($hex) ?></p>
            <?php endif; ?>
            <?php if (!empty($hrec)): ?>
                <div class="border-t border-gray-800 pt-3">
                    <div class="text-xs text-gray-500 uppercase tracking-wide mb-2">Top recommendations</div>
                    <ul class="space-y-2 text-sm text-gray-300">
                        <?php foreach (array_slice($hrec, 0, 3) as $r): ?>
                            <li class="flex items-start gap-2">
                                <span class="text-indigo-400 mt-0.5">•</span>
                                <span class="min-w-0">
                                    <a href="<?= e($r['link'] ?? '#') ?>" class="text-slate-100 hover:text-indigo-300"><?= e($r['title'] ?? '') ?></a>
                                    <span class="text-gray-400"> — <?= e($r['reason'] ?? '') ?></span>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
        </section>

        <!-- Restaurant Insights (Success Engine) -->
        <section class="rounded-xl border border-gray-800 bg-[#121826] shadow-xl p-5 card-motion space-y-6">
            <h3 class="text-sm font-semibold text-[#F3F4F6] flex items-center gap-2">
                <span class="w-8 h-8 rounded-lg bg-indigo-500/20 flex items-center justify-center text-indigo-400">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                </span>
                Restaurant Insights
            </h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div class="space-y-2">
                    <div class="text-xs text-gray-500 uppercase tracking-wide flex items-center gap-1">
                        <svg class="w-3.5 h-3.5 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                        Insights
                    </div>
                    <ul class="space-y-1.5 text-sm text-gray-300">
                        <?php foreach (array_slice($successInsights, 0, 5) as $ins): ?>
                            <li class="flex items-start gap-2">
                                <span class="text-indigo-400 mt-0.5">•</span>
                                <span><?= e($ins['text'] ?? '') ?></span>
                            </li>
                        <?php endforeach; ?>
                        <?php if (empty($successInsights)): ?>
                            <li class="text-gray-500">Пока нет инсайтов. Добавьте заказы и данные меню.</li>
                        <?php endif; ?>
                    </ul>
                </div>
                <div class="space-y-2">
                    <div class="text-xs text-gray-500 uppercase tracking-wide flex items-center gap-1">
                        <svg class="w-3.5 h-3.5 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/></svg>
                        Рекомендуемые действия
                    </div>
                    <ul class="space-y-2 text-sm">
                        <?php foreach (array_slice($successRecommendations, 0, 5) as $rec): ?>
                            <li>
                                <a href="<?= e($rec['link'] ?? '#') ?>" class="text-indigo-400 hover:text-indigo-300 font-medium"><?= e($rec['title'] ?? '') ?></a>
                                <span class="text-gray-500 block text-xs mt-0.5"><?= e($rec['description'] ?? '') ?></span>
                            </li>
                        <?php endforeach; ?>
                        <?php if (empty($successRecommendations)): ?>
                            <li class="text-gray-500">Завершите настройку, чтобы видеть рекомендации.</li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>
        </section>

        <!-- Restaurant Copilot -->
        <section class="rounded-xl border border-gray-800 bg-[#121826] shadow-xl p-5 card-motion space-y-6">
            <h3 class="text-sm font-semibold text-[#F3F4F6] flex items-center gap-2">
                <span class="w-8 h-8 rounded-lg bg-violet-500/20 flex items-center justify-center text-violet-400">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/></svg>
                </span>
                AI-помощник ресторана
            </h3>
            <p class="text-sm text-gray-300"><?= e($copilotSummary['summary'] ?? '') ?></p>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div class="space-y-2">
                    <div class="text-xs text-gray-500 uppercase tracking-wide">Инсайты</div>
                    <ul class="space-y-1 text-sm text-gray-300">
                        <?php foreach (array_slice($copilotSummary['insights'] ?? [], 0, 5) as $ins): ?>
                            <li class="flex items-start gap-2"><span class="text-violet-400 mt-0.5">•</span><?= e($ins) ?></li>
                        <?php endforeach; ?>
                        <?php if (empty($copilotSummary['insights'])): ?>
                            <li class="text-gray-500">Добавьте заказы, чтобы видеть инсайты.</li>
                        <?php endif; ?>
                    </ul>
                </div>
                <div class="space-y-2">
                    <div class="text-xs text-gray-500 uppercase tracking-wide">Рекомендации</div>
                    <ul class="space-y-1.5 text-sm">
                        <?php foreach (array_slice($copilotRecommendations, 0, 5) as $rec): ?>
                            <li><a href="<?= e($rec['link'] ?? '#') ?>" class="text-indigo-400 hover:text-indigo-300 font-medium"><?= e($rec['title'] ?? '') ?></a><span class="text-gray-500 block text-xs"><?= e($rec['description'] ?? '') ?></span></li>
                        <?php endforeach; ?>
                        <?php if (empty($copilotRecommendations)): ?>
                            <li class="text-gray-500">Завершите настройку для рекомендаций.</li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>
            <div class="pt-3 border-t border-gray-800">
                <label for="copilot-question" class="text-xs text-gray-500 block mb-1">Задать вопрос помощнику</label>
                <div class="flex gap-2">
                    <input type="text" id="copilot-question" placeholder="Напр.: Самое продаваемое блюдо? Пиковые часы?" class="flex-1 min-w-0 rounded-xl bg-gray-900 border border-gray-700 px-3 py-2 text-sm text-slate-100 placeholder-gray-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
                    <button type="button" id="copilot-ask-btn" class="px-4 py-2 rounded-xl bg-violet-600 hover:bg-violet-500 text-white text-sm font-medium">Спросить</button>
                </div>
                <div id="copilot-answer" class="mt-2 p-3 rounded-lg bg-gray-900/60 border border-gray-800 text-sm text-slate-200 hidden"></div>
                <div id="copilot-action-buttons" class="mt-2 flex flex-wrap gap-2 hidden"></div>
                <div id="copilot-loading" class="mt-2 text-sm text-gray-500 hidden">Помощник анализирует данные…</div>
            </div>
        </section>

        <!-- Growth Engine (Unified opportunities) -->
        <section class="rounded-xl border border-gray-800 bg-[#121826] shadow-xl p-5 card-motion space-y-6">
            <h3 class="text-sm font-semibold text-[#F3F4F6] flex items-center gap-2">
                <span class="w-8 h-8 rounded-lg bg-amber-500/20 flex items-center justify-center text-amber-400">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>
                </span>
                Growth Engine
                <?php if ($growthPendingCount > 0): ?>
                <a href="/restaurant/growth_suggestions.php" class="inline-flex items-center justify-center min-w-[1.5rem] h-6 px-1.5 rounded-full bg-amber-500/30 text-amber-200 text-xs font-medium"><?= (int)$growthPendingCount ?></a>
                <?php endif; ?>
                <a href="/restaurant/growth_suggestions.php" class="ml-auto text-xs text-slate-400 hover:text-indigo-400">Управление предложениями</a>
            </h3>
            <?php if (!empty($growthSummaryArch['top_current_growth_driver']) || !empty($growthSummaryArch['quickest_win']) || !empty($growthSummaryArch['biggest_missed_opportunity'])): ?>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                <div class="rounded-xl border border-gray-800 bg-gray-900/40 px-3 py-2">
                    <div class="text-[11px] text-gray-500 uppercase tracking-wide">Top driver</div>
                    <div class="text-sm text-slate-200 mt-1"><?= e($growthSummaryArch['top_current_growth_driver'] ?? '') ?></div>
                </div>
                <div class="rounded-xl border border-gray-800 bg-gray-900/40 px-3 py-2">
                    <div class="text-[11px] text-gray-500 uppercase tracking-wide">Biggest miss</div>
                    <div class="text-sm text-slate-200 mt-1"><?= e($growthSummaryArch['biggest_missed_opportunity'] ?? '') ?></div>
                </div>
                <div class="rounded-xl border border-gray-800 bg-gray-900/40 px-3 py-2">
                    <div class="text-[11px] text-gray-500 uppercase tracking-wide">Quickest win</div>
                    <div class="text-sm text-slate-200 mt-1"><?= e($growthSummaryArch['quickest_win'] ?? '') ?></div>
                </div>
            </div>
            <?php endif; ?>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div class="space-y-3">
                    <div class="text-xs text-gray-500 uppercase tracking-wide flex items-center gap-1">
                        <svg class="w-3.5 h-3.5 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                        Top opportunities (unified)
                    </div>
                    <?php if (!empty($archGrowthOpportunities)): ?>
                        <ul class="space-y-2">
                            <?php foreach ($archGrowthOpportunities as $opp): ?>
                                <?php
                                $p = (string)($opp['priority'] ?? 'low');
                                $pClass = $p === 'high' ? 'text-amber-300' : ($p === 'medium' ? 'text-sky-300' : 'text-slate-400');
                                ?>
                                <li class="rounded-lg border border-gray-800 bg-gray-900/40 px-3 py-2 text-sm">
                                    <div class="flex items-start justify-between gap-3">
                                        <div class="min-w-0">
                                            <div class="font-medium text-[#F3F4F6] truncate"><?= e($opp['title'] ?? '') ?></div>
                                            <p class="text-xs text-gray-400 mt-0.5"><?= e($opp['description'] ?? '') ?></p>
                                            <div class="text-[11px] text-gray-500 mt-1">
                                                <span class="<?= e($pClass) ?> font-medium"><?= e(strtoupper($p)) ?></span>
                                                · <?= e($opp['source_module'] ?? '') ?>
                                                · <?= e($opp['estimated_impact'] ?? '') ?>
                                            </div>
                                        </div>
                                        <div class="flex-shrink-0">
                                            <a href="<?= e($opp['action_url'] ?? '#') ?>" class="inline-flex items-center px-3 py-1.5 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-medium">Action</a>
                                        </div>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p class="text-sm text-gray-500">No unified opportunities yet. Keep collecting orders and upsell events.</p>
                    <?php endif; ?>

                    <div class="pt-2 text-[11px] text-gray-500">Suggestions only. No auto-changes.</div>
                </div>
        </section>

        <?php if (!is_demo_mode() && $restId > 0): ?>
        <section class="rounded-xl border border-gray-800 bg-[#121826] shadow-xl p-5 card-motion space-y-4" aria-labelledby="feedback-widget-title">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <h3 id="feedback-widget-title" class="text-sm font-semibold text-[#F3F4F6]">Отзывы гостей</h3>
                <?php if ((int)($feedbackSummary['total_feedback'] ?? 0) > 0 && !empty($feedbackSummary['latest_feedback_at'])): ?>
                    <span class="text-[11px] text-gray-500">Последний: <?= e(date('d.m.Y H:i', strtotime((string)$feedbackSummary['latest_feedback_at']))) ?></span>
                <?php endif; ?>
            </div>

            <?php if ((int)($feedbackSummary['total_feedback'] ?? 0) === 0): ?>
                <p class="text-sm text-gray-500">Пока нет отзывов от гостей</p>
            <?php else: ?>
                <div class="flex flex-wrap gap-4 text-sm">
                    <div>
                        <span class="text-gray-500">Средняя оценка:</span>
                        <span class="ml-1 font-semibold text-[#F3F4F6]"><?= $feedbackSummary['average_rating'] !== null ? e((string)$feedbackSummary['average_rating']) : '—' ?></span>
                    </div>
                    <div>
                        <span class="text-gray-500">Всего отзывов:</span>
                        <span class="ml-1 font-semibold text-[#F3F4F6]"><?= (int)$feedbackSummary['total_feedback'] ?></span>
                    </div>
                    <?php if ((int)($feedbackSummary['feedback_this_month'] ?? 0) > 0): ?>
                        <div>
                            <span class="text-gray-500">За этот месяц:</span>
                            <span class="ml-1 font-semibold text-[#F3F4F6]"><?= (int)$feedbackSummary['feedback_this_month'] ?></span>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="flex flex-wrap gap-3 text-[11px] pt-1">
                    <span class="inline-flex items-center gap-1 rounded-lg border border-emerald-500/30 bg-emerald-500/10 px-2 py-0.5 text-emerald-300">
                        <span aria-hidden="true">★</span> 5: <?= (int)($feedbackSummary['promoters_count'] ?? 0) ?>
                    </span>
                    <span class="inline-flex items-center gap-1 rounded-lg border border-sky-500/30 bg-sky-500/10 px-2 py-0.5 text-sky-300">
                        3–4: <?= (int)($feedbackSummary['neutral_count'] ?? 0) ?>
                    </span>
                    <span class="inline-flex items-center gap-1 rounded-lg border border-red-500/30 bg-red-500/10 px-2 py-0.5 text-red-300">
                        1–2: <?= (int)($feedbackSummary['detractors_count'] ?? 0) ?>
                    </span>
                </div>

                <?php if (!empty($recentFeedback)): ?>
                    <ul class="space-y-3 pt-2 border-t border-gray-800/80">
                        <?php foreach ($recentFeedback as $fb): ?>
                            <?php
                            $r = (int)($fb['rating'] ?? 0);
                            $tone = $r >= 5 ? 'border-emerald-500/30 bg-emerald-500/5' : ($r >= 3 ? 'border-sky-500/30 bg-sky-500/5' : 'border-red-500/30 bg-red-500/5');
                            $stars = str_repeat('★', max(0, min(5, $r)));
                            $comment = $fb['comment'] ?? null;
                            $preview = $comment !== null ? mb_substr(trim($comment), 0, 120) : '';
                            if ($comment !== null && mb_strlen(trim($comment)) > 120) {
                                $preview .= '…';
                            }
                            $ts = !empty($fb['created_at']) ? strtotime((string)$fb['created_at']) : false;
                            $when = $ts ? date('d.m.Y H:i', $ts) : '';
                            ?>
                            <li class="rounded-lg border <?= e($tone) ?> px-3 py-2">
                                <div class="flex flex-wrap items-center justify-between gap-2 text-xs">
                                    <span class="text-amber-300/90" aria-hidden="true"><?= e($stars) ?></span>
                                    <span class="text-gray-400"><?= number_format($r, 0, '.', ' ') ?>/5</span>
                                    <?php if ($when !== ''): ?>
                                        <span class="text-gray-500 ml-auto"><?= e($when) ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="text-[11px] text-gray-500 mt-1">
                                    <?php if (!empty($fb['order_id'])): ?>Заказ #<?= (int)$fb['order_id'] ?><?php endif; ?>
                                    <?php if (!empty($fb['table_name'])): ?>
                                        <?php
                                        $fbTbl = function_exists('qr_public_owner_order_table_label')
                                            ? qr_public_owner_order_table_label((string)$fb['table_name'])
                                            : (string)$fb['table_name'];
                                        ?>
                                        <?= !empty($fb['order_id']) ? ' · ' : '' ?><?= $fbTbl === 'Доставка' ? 'Доставка' : ('Стол: ' . e($fbTbl)) ?>
                                    <?php endif; ?>
                                </div>
                                <?php if ($preview !== ''): ?>
                                    <p class="text-sm text-gray-300 mt-1.5 line-clamp-2"><?= e($preview) ?></p>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <?php if (!is_demo_mode() && $restId > 0): ?>
        <section class="rounded-xl border border-gray-800 bg-[#121826] shadow-xl p-5 card-motion space-y-4" aria-labelledby="feedback-analytics-title">
            <h3 id="feedback-analytics-title" class="text-sm font-semibold text-[#F3F4F6]">Feedback analytics</h3>
            <?php if ((int)($feedbackAnalyticsSummary['total_feedback'] ?? 0) === 0): ?>
                <p class="text-sm text-gray-500">Недостаточно данных для аналитики отзывов</p>
            <?php else: ?>
                <div class="flex flex-wrap gap-4 text-sm">
                    <div>
                        <span class="text-gray-500">Средняя оценка за 30 дней:</span>
                        <span class="ml-1 font-semibold text-[#F3F4F6]"><?= $feedbackAnalyticsSummary['average_rating'] !== null ? e((string)$feedbackAnalyticsSummary['average_rating']) : '—' ?></span>
                    </div>
                    <div>
                        <span class="text-gray-500">Отзывов за 30 дней:</span>
                        <span class="ml-1 font-semibold text-[#F3F4F6]"><?= (int)($feedbackAnalyticsSummary['total_feedback'] ?? 0) ?></span>
                    </div>
                </div>
                <div class="grid grid-cols-2 sm:grid-cols-5 gap-2 text-xs">
                    <?php for ($star = 1; $star <= 5; $star++): ?>
                        <?php
                        $count = (int)($feedbackRatingBreakdown[$star] ?? 0);
                        $tone = $star === 5 ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-300' : (($star >= 3) ? 'border-sky-500/30 bg-sky-500/10 text-sky-300' : 'border-red-500/30 bg-red-500/10 text-red-300');
                        ?>
                        <div class="rounded-lg border px-2 py-1 <?= e($tone) ?>">
                            <span><?= $star ?>★</span>
                            <span class="ml-1 font-semibold"><?= $count ?></span>
                        </div>
                    <?php endfor; ?>
                </div>
                <?php if (!empty($feedbackTrendPoints)): ?>
                    <?php
                    $last = $feedbackTrendPoints[count($feedbackTrendPoints) - 1] ?? null;
                    $first = $feedbackTrendPoints[0] ?? null;
                    $trendText = '';
                    if ($first && $last && isset($first['average_rating'], $last['average_rating']) && $first['average_rating'] !== null && $last['average_rating'] !== null) {
                        $delta = round((float)$last['average_rating'] - (float)$first['average_rating'], 2);
                        $trendText = $delta === 0.0 ? 'без изменений' : (($delta > 0 ? '+' : '') . $delta . ' к началу периода');
                    }
                    ?>
                    <p class="text-[11px] text-gray-500">
                        Тренд за 30 дней: <?= $trendText !== '' ? e($trendText) : 'данных пока недостаточно' ?>.
                    </p>
                <?php endif; ?>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <section class="rounded-xl border border-gray-800 bg-[#121826] shadow-xl p-5 card-motion space-y-4" aria-labelledby="retention-roi-title">
            <h3 id="retention-roi-title" class="text-sm font-semibold text-[#F3F4F6]">Guest Return Performance</h3>
            <?php
            $retentionSampleN = (int)($retentionRoiSummary['sample_size_campaigns'] ?? 0);
            $retentionConfidence = (string)($retentionRoiSummary['confidence_level'] ?? 'low');
            ?>
            <p class="text-[11px] text-gray-500">
                Confidence по выборке: low (N &lt; 3), medium (N 3–9), high (N &gt;= 10), где N = число accepted-кампаний, участвующих в расчете return rate.
            </p>
            <?php if (is_demo_mode()): ?>
                <p class="text-sm text-gray-500">Недоступно в демо</p>
            <?php elseif ((int)($retentionRoiSummary['accepted_campaigns'] ?? 0) <= 0): ?>
                <p class="text-sm text-gray-500">
                    Недостаточно данных по возврату гостей.
                    Кампаний: <?= (int)$retentionSampleN ?>.
                    Достоверность: <?= e($retentionConfidence) ?><?= $retentionConfidence === 'low' ? ' (мало данных)' : '' ?>.
                </p>
            <?php else: ?>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm">
                    <div>
                        <span class="text-gray-500">Возврат гостей:</span>
                        <span class="ml-1 font-semibold text-[#F3F4F6]"><?= number_format(((float)($retentionRoiSummary['return_rate'] ?? 0) * 100), 1, '.', ' ') ?>%</span>
                        <div class="text-[11px] text-gray-500 mt-1">
                            N: <?= (int)$retentionSampleN ?> · confidence: <?= e($retentionConfidence) ?>
                            <?= $retentionConfidence === 'low' ? ' · мало данных' : '' ?>
                        </div>
                    </div>
                    <div>
                        <span class="text-gray-500">Доход от возвратов:</span>
                        <span class="ml-1 font-semibold text-[#F3F4F6]"><?= number_format((float)($retentionRoiSummary['total_return_revenue'] ?? 0), 0, '.', ' ') ?> ₽</span>
                    </div>
                    <div>
                        <span class="text-gray-500">Средний возврат:</span>
                        <span class="ml-1 font-semibold text-[#F3F4F6]"><?= number_format((float)($retentionRoiSummary['avg_return_revenue'] ?? 0), 0, '.', ' ') ?> ₽</span>
                    </div>
                    <div>
                        <span class="text-gray-500">Среднее время возврата:</span>
                        <span class="ml-1 font-semibold text-[#F3F4F6]">
                            <?= $retentionRoiSummary['avg_days_to_return'] !== null
                                ? number_format((float)$retentionRoiSummary['avg_days_to_return'], 1, '.', ' ') . ' дней'
                                : '—' ?>
                        </span>
                    </div>
                    <div>
                        <span class="text-gray-500">Базовый возврат:</span>
                        <span class="ml-1 font-semibold text-[#F3F4F6]">
                            <?= $retentionRoiSummary['baseline_return_rate'] !== null
                                ? number_format(((float)$retentionRoiSummary['baseline_return_rate'] * 100), 1, '.', ' ') . '%'
                                : '—' ?>
                        </span>
                    </div>
                    <div>
                        <span class="text-gray-500">Окно baseline:</span>
                        <span class="ml-1 font-semibold text-[#F3F4F6]"><?= (int)($retentionRoiSummary['baseline_window_days'] ?? 7) ?> дн.</span>
                    </div>
                    <div>
                        <span class="text-gray-500">Прирост (uplift):</span>
                        <span class="ml-1 font-semibold text-[#F3F4F6]">
                            <?= isset($retentionRoiSummary['uplift_percent']) && $retentionRoiSummary['uplift_percent'] !== null
                                ? (($retentionRoiSummary['uplift_percent'] > 0 ? '+' : '') . number_format(((float)$retentionRoiSummary['uplift_percent'] * 100), 1, '.', ' ') . '%')
                                : '—' ?>
                        </span>
                    </div>
                    <div>
                        <span class="text-gray-500">Оценочная стоимость бонусов:</span>
                        <span class="ml-1 font-semibold text-[#F3F4F6]"><?= number_format((float)($retentionRoiSummary['estimated_bonus_cost'] ?? 0), 0, '.', ' ') ?> ₽</span>
                    </div>
                    <div>
                        <span class="text-gray-500">Оценочный чистый доход:</span>
                        <span class="ml-1 font-semibold text-[#F3F4F6]"><?= number_format((float)($retentionRoiSummary['estimated_net_return_revenue'] ?? 0), 0, '.', ' ') ?> ₽</span>
                    </div>
                    <div>
                        <span class="text-gray-500">Доход на кампанию:</span>
                        <span class="ml-1 font-semibold text-[#F3F4F6]"><?= number_format((float)($retentionRoiSummary['revenue_per_campaign'] ?? 0), 0, '.', ' ') ?> ₽</span>
                    </div>
                    <div>
                        <span class="text-gray-500">Оценочный чистый доход на кампанию:</span>
                        <span class="ml-1 font-semibold text-[#F3F4F6]"><?= number_format((float)($retentionRoiSummary['estimated_net_revenue_per_campaign'] ?? 0), 0, '.', ' ') ?> ₽</span>
                    </div>
                    <div>
                        <span class="text-gray-500">Неуспешные кампании:</span>
                        <span class="ml-1 font-semibold text-[#F3F4F6]"><?= (int)($retentionRoiSummary['failed_campaigns'] ?? 0) ?></span>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-xs">
                        <thead>
                            <tr class="text-left text-gray-500 border-b border-gray-800">
                                <th class="py-1 pr-2">Сегмент</th>
                                <th class="py-1 pr-2">Accepted</th>
                                <th class="py-1 pr-2">Returned</th>
                                <th class="py-1 pr-2">Return rate</th>
                                <th class="py-1 pr-2">Revenue</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (['LOW', 'NEUTRAL', 'HIGH'] as $segKey): ?>
                                <?php $seg = $retentionSegments[$segKey] ?? []; ?>
                                <tr class="border-b border-gray-800/60">
                                    <td class="py-1 pr-2 text-[#F3F4F6]"><?= e($segKey) ?></td>
                                    <td class="py-1 pr-2 text-[#F3F4F6]"><?= (int)($seg['accepted_campaigns'] ?? 0) ?></td>
                                    <td class="py-1 pr-2 text-[#F3F4F6]"><?= (int)($seg['returned_guests'] ?? 0) ?></td>
                                    <td class="py-1 pr-2 text-[#F3F4F6]">
                                        <div><?= number_format(((float)($seg['return_rate'] ?? 0) * 100), 1, '.', ' ') ?>%</div>
                                        <div class="text-[10px] text-gray-500 mt-0.5">
                                            N: <?= (int)($seg['sample_size_campaigns'] ?? $seg['total_campaigns'] ?? 0) ?>
                                            · confidence: <?= e((string)($seg['confidence_level'] ?? 'low')) ?>
                                            <?php if (($seg['confidence_level'] ?? 'low') === 'low'): ?>
                                                <span class="text-amber-300"> · мало данных</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="py-1 pr-2 text-[#F3F4F6]"><?= number_format((float)($seg['total_return_revenue'] ?? 0), 0, '.', ' ') ?> ₽</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <!-- Quick insights -->
        <section class="rounded-xl border border-gray-800 bg-[#121826] shadow-xl p-5 card-motion space-y-4">
            <h3 class="text-sm font-semibold text-[#F3F4F6]">Инсайты за сегодня</h3>
            <?php if (!empty($dashboardInsights)): ?>
                <ul class="space-y-2 text-sm text-gray-300">
                    <?php foreach (array_slice($dashboardInsights, 0, 3) as $insight): ?>
                        <li class="flex items-start gap-2">
                            <span class="text-indigo-400 mt-0.5">•</span>
                            <span><?= e($insight) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p class="text-sm text-gray-500">Завершите настройку, чтобы видеть персональные инсайты.</p>
            <?php endif; ?>
        </section>

        <!-- Growth insights (orders, upsell, categories, pairings) -->
        <?php if (!empty($growthInsights)): ?>
        <section class="rounded-xl border border-gray-800 bg-[#121826] shadow-xl p-5 card-motion space-y-4">
            <h3 class="text-sm font-semibold text-[#F3F4F6]">Growth insights</h3>
            <p class="text-[11px] text-gray-500">По заказам, допродажам и данным меню за последние 7 дней.</p>
            <ul class="space-y-2 text-sm text-gray-300">
                <?php foreach (array_slice($growthInsights, 0, 3) as $insight): ?>
                    <li class="flex items-start gap-2">
                        <span class="text-emerald-400 mt-0.5">•</span>
                        <span><?= e($insight) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
        <?php endif; ?>

        <!-- Guest Return Opportunities (actionable subset of inactive guests from CRM) -->
        <?php
        $greCount = (int)($guestReturnSummary['candidates_count'] ?? 0);
        $greEst = (float)($guestReturnSummary['estimated_recovered'] ?? 0);
        ?>
        <?php if ($greCount > 0 || !empty($guestReturnCandidates)): ?>
        <section class="rounded-xl border border-gray-800 bg-[#121826] shadow-xl p-5 card-motion space-y-4">
            <h3 class="text-sm font-semibold text-[#F3F4F6]">Возможности для возврата гостей</h3>
            <p class="text-xs text-gray-500"><?= e($guestReturnSummary['headline'] ?? '') ?></p>
            <?php if ($greEst > 0): ?>
            <p class="text-sm text-emerald-300">Estimated recovered revenue: <span class="font-semibold">$<?= number_format($greEst, 0) ?></span></p>
            <?php endif; ?>
            <?php if (!empty($guestReturnCandidates)): ?>
            <ul class="space-y-2 text-sm text-gray-300">
                <?php foreach (array_slice($guestReturnCandidates, 0, 3) as $c): ?>
                    <li class="flex items-start gap-2">
                        <span class="text-amber-400 mt-0.5">•</span>
                        <span><?= e($c['guest_contact']) ?><?= !empty($c['guest_name']) ? ' (' . e($c['guest_name']) . ')' : '' ?> — <?= e($c['suggested_offer']) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
            <a href="/restaurant/crm.php#comeback-candidates" class="inline-block text-xs text-indigo-400 hover:text-indigo-300">View in CRM →</a>
        </section>
        <?php endif; ?>

        <!-- Menu Intelligence -->
        <?php
        $menuIntelInsights = $menuIntelligence['insights'] ?? [];
        ?>
        <?php if (!empty($menuIntelInsights)): ?>
        <section class="rounded-xl border border-gray-800 bg-[#121826] shadow-xl p-5 card-motion space-y-4">
            <h3 class="text-sm font-semibold text-[#F3F4F6]">Menu Intelligence</h3>
            <p class="text-[11px] text-gray-500">Actionable menu insights (read-only recommendations).</p>
            <ul class="space-y-2 text-sm text-gray-300">
                <?php foreach (array_slice($menuIntelInsights, 0, 3) as $insight): ?>
                    <li class="flex items-start gap-2">
                        <span class="text-emerald-400 mt-0.5">•</span>
                        <span><?= e($insight) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
        <?php endif; ?>

        <!-- B. Revenue trend -->
        <section class="space-y-4">
            <h3 class="text-sm font-semibold text-gray-400 uppercase tracking-wide">Выручка и заказы</h3>
            <div class="rounded-xl border border-gray-800 bg-[#121826] shadow-lg shadow-black/20 p-5 card-motion">
                <h4 class="text-sm font-semibold text-[#F3F4F6] mb-3">Динамика выручки <?php if (is_demo_mode()): ?><span class="inline-flex items-center justify-center w-4 h-4 rounded-full bg-gray-600 text-gray-400 text-[10px] align-middle ml-1" title="Динамика выручки за период.">?</span><?php endif; ?></h4>
                <?php if (!empty($revenueTrend7)): ?>
                    <div class="h-48">
                        <canvas id="dashboardRevenueChart"></canvas>
                    </div>
                    <p class="text-[11px] text-gray-500 mt-2">За последние 7 дней — оплаченные заказы</p>
                <?php else: ?>
                    <div class="rounded-xl border border-gray-800 bg-gray-900/40 p-8 text-center space-y-3">
                        <div class="inline-flex items-center justify-center w-12 h-12 rounded-2xl bg-gray-800 text-gray-500">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 12l3-3 3 3 4-4M8 21l4-4 4 4M3 4h18M4 4h16v12a1 1 0 01-1 1H5a1 1 0 01-1-1V4z"/></svg>
                        </div>
                        <div>
                            <div class="text-sm font-medium text-[#F3F4F6]">Пока нет данных по выручке</div>
                            <p class="text-xs text-gray-500 mt-1">Динамика за 7 дней появится после первых заказов.</p>
                        </div>
                        <a href="/restaurant/revenue.php" class="inline-flex items-center px-4 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-medium">Смотреть выручку</a>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <!-- Top dishes -->
        <section id="top-dishes" class="rounded-xl border border-gray-800 bg-[#121826] shadow-lg shadow-black/20 p-5 card-motion">
            <div class="flex items-center justify-between mb-3">
                <h3 class="text-sm font-semibold text-[#F3F4F6]">Популярные блюда</h3>
                <span class="text-[11px] text-gray-500">По количеству проданных сегодня</span>
            </div>
            <?php if (empty($topItems)): ?>
                <div class="rounded-xl border border-gray-800 bg-gray-900/40 p-6 text-center space-y-3">
                    <div class="inline-flex items-center justify-center w-12 h-12 rounded-2xl bg-gray-800 text-gray-500">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>
                    </div>
                    <div class="text-sm font-medium text-[#F3F4F6]">No top dishes yet</div>
                    <p class="text-xs text-gray-500">Данные по продажам появятся после заказов за сегодня.</p>
                    <a href="/restaurant/menu_manage.php#dish-form" class="inline-flex items-center px-4 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-medium">Add menu items</a>
                </div>
            <?php else: ?>
                <div class="space-y-2 text-xs">
                    <?php foreach ($topItems as $i => $row): ?>
                        <div class="table-row-motion flex items-center justify-between gap-2 rounded-xl border border-gray-800 bg-gray-900/60 px-3 py-2">
                            <div class="flex items-center gap-2">
                                <span class="inline-flex items-center justify-center w-5 h-5 rounded-full bg-gray-800 text-[10px] text-gray-200"><?= $i + 1 ?></span>
                                <span class="text-[#F3F4F6]"><?= e($row['name']) ?></span>
                            </div>
                            <div class="text-[11px] text-[#22C55E] font-semibold">× <?= (int)$row['total_qty'] ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <!-- C. Guest retention / CRM and D. Upsell - two columns -->
        <section class="grid md:grid-cols-2 gap-6">
            <!-- Guest retention -->
            <div class="rounded-xl border border-gray-800 bg-[#121826] shadow-lg shadow-black/20 p-5 card-motion">
                <h3 class="text-sm font-semibold text-[#F3F4F6] mb-3">Guest retention</h3>
                <?php if (!empty($retentionStats)): ?>
                    <div class="space-y-3 text-sm">
                        <div class="flex justify-between items-center rounded-lg bg-gray-900/60 px-3 py-2">
                            <span class="text-gray-400">Returning guests</span>
                            <span class="font-semibold text-[#F3F4F6]"><?= (int)($retentionStats['repeat_guests'] ?? 0) ?></span>
                        </div>
                        <div class="flex justify-between items-center rounded-lg bg-gray-900/60 px-3 py-2">
                            <span class="text-gray-400">Inactive guests</span>
                            <span class="font-semibold text-[#F3F4F6]"><?= (int)($retentionStats['inactive_count'] ?? 0) ?></span>
                        </div>
                        <div class="flex justify-between items-center rounded-lg bg-gray-900/60 px-3 py-2">
                            <span class="text-gray-400">Retention opportunities</span>
                            <span class="font-semibold text-[#F3F4F6]"><?= (int)($retentionStats['opportunities_count'] ?? 0) ?></span>
                        </div>
                        <div class="flex justify-between items-center rounded-lg bg-gray-900/60 px-3 py-2">
                            <span class="text-gray-400">Loyal guests</span>
                            <span class="font-semibold text-[#F3F4F6]"><?= (int)$loyalGuestsCount ?></span>
                        </div>
                        <div class="flex justify-between items-center rounded-lg bg-gray-900/60 px-3 py-2">
                            <span class="text-gray-400">Repeat guest rate</span>
                            <span class="font-semibold text-[#F3F4F6]"><?= (float)($retentionStats['repeat_rate_pct'] ?? 0) ?>%</span>
                        </div>
                    </div>
                    <?php if ($crmSummary !== null): ?>
                    <div class="mt-3 space-y-2 text-xs text-gray-400 border-t border-gray-800 pt-3">
                        <div class="flex justify-between"><span>Messages pending</span><span><?= (int)($crmSummary['pending_messages'] ?? 0) ?></span></div>
                        <div class="flex justify-between"><span>Campaigns</span><span><?= ($crmSummary['has_campaigns'] ?? false) ? 'Есть' : 'Нет' ?></span></div>
                    </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="rounded-xl border border-gray-800 bg-gray-900/40 p-6 text-center space-y-3">
                        <div class="inline-flex items-center justify-center w-12 h-12 rounded-2xl bg-gray-800 text-gray-500">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                        </div>
                        <div class="text-sm font-medium text-[#F3F4F6]">No retention data yet</div>
                        <p class="text-xs text-gray-500">Guest visit tracking will populate returning and inactive guest stats.</p>
                    </div>
                <?php endif; ?>

                <div class="mt-4">
                    <?php if (!$crmEnabled): ?>
                    <div class="rounded-lg border border-amber-500/40 bg-amber-500/10 px-3 py-2 text-xs text-amber-200 mb-2">
                        <span class="font-medium">CRM недоступен на текущем тарифе</span>
                        <?php if ($hasStrongTopUpgradeNudge): ?>
                            <span class="block mt-1.5 text-amber-300/80">Подробнее в тарифах</span>
                        <?php else: ?>
                            <a href="/restaurant/activate.php?plan=growth" class="block mt-1.5 text-amber-300 hover:text-amber-200 font-medium">Открыть тариф GROWTH →</a>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    <a href="/restaurant/crm.php" class="inline-flex items-center px-3 py-1.5 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-medium">
                        Open CRM
                    </a>
                </div>
            </div>

            <!-- Upsell performance -->
            <div class="rounded-xl border border-gray-800 bg-[#121826] shadow-lg shadow-black/20 p-5 card-motion">
                <h3 class="text-sm font-semibold text-[#F3F4F6] mb-3">Upsell performance</h3>
                <?php if (!$upsellEnabled): ?>
                <div class="rounded-lg border border-amber-500/40 bg-amber-500/10 px-3 py-2 text-xs text-amber-200 mb-3">
                    <span class="font-medium">Upsell недоступен на текущем тарифе</span>
                    <?php if ($hasStrongTopUpgradeNudge): ?>
                        <span class="block mt-1.5 text-amber-300/80">Подробнее в тарифах</span>
                    <?php else: ?>
                        <a href="/restaurant/activate.php?plan=growth" class="block mt-1.5 text-amber-300 hover:text-amber-200 font-medium">Открыть тариф GROWTH →</a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                <?php
                    $upsellPerfSummary = null;
                    $upsellTop3 = [];
                    if (!is_demo_mode() && $upsellEnabled) {
                        require_once __DIR__ . '/../../app/upsell_analytics.php';
                        $upsellPerfSummary = function_exists('get_upsell_analytics_summary_cached')
                            ? get_upsell_analytics_summary_cached($restId, 30)
                            : get_upsell_analytics_summary($restId, 30);
                        $upsellTop3 = function_exists('get_top_upsell_items_cached')
                            ? get_top_upsell_items_cached($restId, 30, 3)
                            : get_top_upsell_items($restId, 30, 3);
                    }
                ?>
                <?php if (is_demo_mode()): ?>
                    <div class="rounded-xl border border-gray-800 bg-gray-900/40 p-6 text-center space-y-3">
                        <div class="inline-flex items-center justify-center w-12 h-12 rounded-2xl bg-gray-800 text-gray-500">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>
                        </div>
                        <div class="text-sm font-medium text-[#F3F4F6]">Demo data unavailable</div>
                    </div>
                <?php elseif ($upsellPerfSummary !== null && (int)($upsellPerfSummary['orders_with_upsell'] ?? 0) > 0): ?>
                    <div class="space-y-3 text-sm">
                        <div class="flex justify-between items-center rounded-lg bg-gray-900/60 px-3 py-2">
                            <span class="text-gray-400">Выручка от допродаж</span>
                            <span class="font-semibold text-emerald-300">
                                <?= number_format((float)($upsellPerfSummary['upsell_revenue'] ?? 0), 0, '.', ' ') ?> ₽
                            </span>
                        </div>
                        <div class="flex justify-between items-center rounded-lg bg-gray-900/60 px-3 py-2">
                            <span class="text-gray-400">Доля допродаж</span>
                            <span class="font-semibold text-[#22C55E]">
                                <?= number_format(100 * (float)($upsellPerfSummary['upsell_revenue_share'] ?? 0), 1) ?>%
                            </span>
                        </div>
                        <div class="flex justify-between items-center rounded-lg bg-gray-900/60 px-3 py-2">
                            <span class="text-gray-400">Attach rate</span>
                            <span class="font-semibold text-[#22C55E]">
                                <?= number_format(100 * (float)($upsellPerfSummary['upsell_attach_rate'] ?? 0), 1) ?>%
                            </span>
                        </div>
                        <?php
                            $liftVal = (float)($upsellPerfSummary['aov_lift_value'] ?? 0);
                            $liftPct = $upsellPerfSummary['aov_lift_percent'] ?? null;
                        ?>
                        <div class="flex justify-between items-center rounded-lg bg-gray-900/60 px-3 py-2">
                            <span class="text-gray-400">Средний чек</span>
                            <span class="font-semibold text-[#F3F4F6]">
                                <?= ($liftVal >= 0 ? '+' : '-') . number_format(abs($liftVal), 0, '.', ' ') ?> ₽
                                <?php if ($liftPct !== null): ?>
                                    (<?= number_format(100 * (float)$liftPct, 1) ?>%)
                                <?php endif; ?>
                            </span>
                        </div>

                        <?php if (!empty($upsellTop3)): ?>
                            <div class="mt-2">
                                <div class="text-xs text-gray-500 uppercase tracking-wide mb-2">Top допродажи</div>
                                <ul class="space-y-2">
                                    <?php foreach ($upsellTop3 as $t): ?>
                                        <li class="flex items-center justify-between gap-2">
                                            <span class="text-sm text-slate-100 truncate"><?= e($t['name'] ?? '') ?></span>
                                            <span class="text-sm font-semibold text-amber-300">
                                                <?= number_format((float)($t['revenue_generated'] ?? 0), 0, '.', ' ') ?> ₽
                                            </span>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>
                    </div>
                    <a href="/restaurant/analytics_upsell.php" class="mt-4 inline-flex items-center px-4 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-medium">Открыть upsell аналитику</a>
                <?php else: ?>
                    <div class="rounded-xl border border-gray-800 bg-gray-900/40 p-6 text-center space-y-3">
                        <div class="inline-flex items-center justify-center w-12 h-12 rounded-2xl bg-gray-800 text-gray-500">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>
                        </div>
                        <div class="text-sm font-medium text-[#F3F4F6]">Недостаточно данных по допродажам</div>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <!-- AI Upsell Suggestions -->
        <section class="rounded-xl border border-gray-800 bg-[#121826] shadow-lg shadow-black/20 p-5 card-motion">
            <h3 class="text-sm font-semibold text-[#F3F4F6] mb-2">AI Upsell Suggestions <?php if (is_demo_mode()): ?><span class="inline-flex items-center justify-center w-4 h-4 rounded-full bg-gray-600 text-gray-400 text-[10px] align-middle ml-1" title="AI suggests dishes to increase average check.">?</span><?php endif; ?></h3>
            <p class="text-xs text-gray-400 mb-4">Recommendations based on items frequently ordered together. Add them as upsell rules to increase average check.</p>
            <?php if ($aiUpsellOrderCount < 20 && !is_demo_mode()): ?>
                <p class="text-sm text-gray-500 rounded-lg bg-gray-900/60 border border-gray-800 px-4 py-3">Для подсказок ИИ нужно больше заказов. (Минимум 20 оплаченных.)</p>
            <?php elseif (empty($aiUpsellSuggestions)): ?>
                <p class="text-sm text-gray-500 rounded-lg bg-gray-900/60 border border-gray-800 px-4 py-3">Пока нет выраженных пар. Собирайте заказы для подсказок.</p>
            <?php else: ?>
                <ul class="space-y-2 mb-4">
                    <?php foreach ($aiUpsellSuggestions as $s): ?>
                        <li class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-gray-800 bg-gray-900/60 px-3 py-2 text-sm">
                            <span class="text-[#F3F4F6]"><?= e($s['base_item']) ?> → <?= e($s['suggested_item']) ?></span>
                            <span class="text-gray-400"><?= (int)round($s['confidence'] * 100) ?>%</span>
                            <?php if ($upsellEnabled): ?><a href="/restaurant/upsells.php?create_from_ai=1&amp;base_item_id=<?= (int)$s['base_item_id'] ?>&amp;upsell_item_id=<?= (int)$s['suggested_item_id'] ?>" class="btn-motion inline-flex items-center px-3 py-1.5 rounded-lg bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-medium">Создать upsell-правило</a><?php else: ?><a href="/restaurant/activate.php?plan=growth" class="inline-flex items-center px-3 py-1.5 rounded-lg bg-amber-600/80 hover:bg-amber-500 text-white text-xs font-medium">Upsell на тарифе GROWTH</a><?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <a href="/restaurant/upsells.php" class="text-xs text-gray-400 hover:text-indigo-400 transition-colors">Manage upsells →</a>
        </section>

        <!-- Upsell Optimization -->
        <?php
        $uoBest = $upsellOptimization['best_pair'] ?? null;
        $uoWeak = $upsellOptimization['weakest_pair'] ?? null;
        $uoList = $upsellOptimization['suggestions'] ?? [];
        ?>
        <?php if (!empty($uoList) || is_array($uoBest) || is_array($uoWeak)): ?>
        <section class="rounded-xl border border-gray-800 bg-[#121826] shadow-lg shadow-black/20 p-5 card-motion space-y-3">
            <h3 class="text-sm font-semibold text-[#F3F4F6]">Upsell Optimization</h3>
            <p class="text-xs text-gray-400">Suggestions based on real upsell conversion events. Nothing changes without your approval.</p>
            <div class="grid md:grid-cols-2 gap-3 text-sm">
                <div class="rounded-xl bg-gray-900/60 border border-gray-800 px-3 py-2">
                    <div class="text-[11px] text-gray-500 uppercase tracking-wide mb-1">Best-performing pair</div>
                    <?php if (is_array($uoBest)): ?>
                        <div class="text-slate-100"><?= e($uoBest['base_item_name'] ?? 'Item') ?> → <?= e($uoBest['upsell_item_name'] ?? 'Item') ?></div>
                        <div class="text-[11px] text-emerald-300">Attach rate <?= number_format(100 * (float)($uoBest['attach_rate'] ?? 0), 1) ?>% · shows <?= (int)($uoBest['shown'] ?? 0) ?></div>
                    <?php else: ?>
                        <div class="text-gray-500">Not enough data yet (need more upsell shows).</div>
                    <?php endif; ?>
                </div>
                <div class="rounded-xl bg-gray-900/60 border border-gray-800 px-3 py-2">
                    <div class="text-[11px] text-gray-500 uppercase tracking-wide mb-1">Weakest pair</div>
                    <?php if (is_array($uoWeak)): ?>
                        <div class="text-slate-100"><?= e($uoWeak['base_item_name'] ?? 'Item') ?> → <?= e($uoWeak['upsell_item_name'] ?? 'Item') ?></div>
                        <div class="text-[11px] text-amber-300">Attach rate <?= number_format(100 * (float)($uoWeak['attach_rate'] ?? 0), 1) ?>% · shows <?= (int)($uoWeak['shown'] ?? 0) ?></div>
                    <?php else: ?>
                        <div class="text-gray-500">No weak pair detected yet.</div>
                    <?php endif; ?>
                </div>
            </div>
            <?php if (!empty($uoList)): ?>
            <ul class="space-y-2 text-sm text-gray-300">
                <?php foreach (array_slice($uoList, 0, 3) as $s): ?>
                    <li class="flex items-start gap-2">
                        <span class="text-indigo-400 mt-0.5">•</span>
                        <span><?= e($s['title'] ?? '') ?> — <span class="text-gray-400"><?= e($s['description'] ?? '') ?></span></span>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
            <?php if ($upsellEnabled): ?><a href="/restaurant/upsells.php#optimization" class="text-xs text-gray-400 hover:text-indigo-400 transition-colors">Открыть и применить подсказки →</a><?php else: ?><a href="/restaurant/activate.php?plan=growth" class="text-xs text-amber-400 hover:text-amber-300 transition-colors">Upsell на тарифе GROWTH →</a><?php endif; ?>
        </section>
        <?php endif; ?>

        <?php if (!$loyaltyEnabled): ?>
        <section class="rounded-xl border border-gray-800 bg-[#121826] shadow-lg shadow-black/20 p-5 card-motion">
            <h3 class="text-sm font-semibold text-[#F3F4F6] mb-2">Лояльность</h3>
            <div class="rounded-lg border border-amber-500/40 bg-amber-500/10 px-3 py-2 text-xs text-amber-200">
                <span class="font-medium">Лояльность недоступна на текущем тарифе</span>
                <?php if ($hasStrongTopUpgradeNudge): ?>
                    <span class="block mt-1.5 text-amber-300/80">Подробнее в тарифах</span>
                <?php else: ?>
                    <a href="/restaurant/activate.php?plan=pro" class="block mt-1.5 text-amber-300 hover:text-amber-200 font-medium">Открыть тариф PRO →</a>
                <?php endif; ?>
            </div>
        </section>
        <?php endif; ?>

        <?php if (!empty($menuPerformanceInsights)): ?>
        <section class="rounded-xl border border-gray-800 bg-[#121826] shadow-lg shadow-black/20 p-5 card-motion">
            <h3 class="text-sm font-semibold text-[#F3F4F6] mb-2">Аналитика меню</h3>
            <p class="text-xs text-gray-400 mb-3">Рекомендации по заказам за последние 7 дней.</p>
            <ul class="space-y-1.5 text-sm text-gray-300">
                <?php foreach (array_slice($menuPerformanceInsights, 0, 3) as $insight): ?>
                    <li class="flex items-start gap-2"><span class="text-indigo-400 mt-0.5">•</span><?= e($insight) ?></li>
                <?php endforeach; ?>
            </ul>
            <a href="/restaurant/revenue.php" class="mt-3 inline-block text-xs text-gray-400 hover:text-indigo-400 transition-colors">Подробная аналитика выручки →</a>
        </section>
        <?php endif; ?>

        <!-- Suggested combos -->
        <section class="rounded-xl border border-gray-800 bg-[#121826] shadow-xl p-5 card-motion space-y-4">
            <h3 class="text-sm font-semibold text-[#F3F4F6]">Рекомендуемые комбо</h3>
            <?php if (!empty($comboSuggestions['suggestions'])): ?>
                <p class="text-xs text-gray-400">Частые пары блюд за последние 100 заказов. Создайте комбо для продвижения.</p>
                <ul class="space-y-2">
                    <?php foreach (array_slice($comboSuggestions['suggestions'], 0, 5) as $c): ?>
                        <li class="flex items-center justify-between gap-2 rounded-lg bg-gray-900/60 px-3 py-2">
                            <span class="text-sm text-[#F3F4F6]"><?= e($c['label']) ?></span>
                            <span class="text-xs text-gray-500"><?= e($c['estimated_uplift_text']) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <button type="button" class="inline-flex items-center px-4 py-2 rounded-xl bg-gray-800 hover:bg-gray-700 text-sm font-medium border border-gray-700 text-[#F3F4F6]">Создать комбо (скоро)</button>
            <?php else: ?>
                <div class="rounded-xl border border-gray-800 bg-gray-900/40 p-6 text-center space-y-3">
                    <div class="inline-flex items-center justify-center w-12 h-12 rounded-2xl bg-gray-800 text-gray-500"><svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg></div>
                    <div class="text-sm font-medium text-[#F3F4F6]">Нужно больше заказов</div>
                    <p class="text-xs text-gray-500">Для предложения комбо нужно минимум 20 оплаченных заказов. У вас <?= (int)($comboSuggestions['order_count'] ?? 0) ?>.</p>
                </div>
            <?php endif; ?>
        </section>

        <!-- Table performance -->
        <section class="rounded-xl border border-gray-800 bg-[#121826] shadow-xl p-5 card-motion space-y-4">
            <h3 class="text-sm font-semibold text-[#F3F4F6]">Эффективность столов</h3>
            <?php if (!empty($tableTurnover['has_data']) && !empty($tableTurnover['tables'])): ?>
                <p class="text-xs text-gray-400"><?= e($tableTurnover['busiest_table']) ?></p>
                <ul class="space-y-1 text-sm text-gray-300">
                    <?php foreach (array_slice($tableTurnover['tables'], 0, 5) as $t): ?>
                        <li><?= e($t['name']) ?> — <?= (int)$t['orders_count'] ?> заказов, <?= number_format($t['revenue'], 0) ?> ₽</li>
                    <?php endforeach; ?>
                </ul>
                <p class="text-xs text-gray-500"><?= e($tableTurnover['recommendation_text']) ?></p>
            <?php else: ?>
                <div class="rounded-xl border border-gray-800 bg-gray-900/40 p-6 text-center space-y-2">
                    <div class="text-sm text-gray-400">Заказы по столам появятся после первых заказов.</div>
                </div>
            <?php endif; ?>
        </section>

        <?php if (!empty($networkBenchmark['available'])): ?>
        <section class="rounded-xl border border-gray-800 bg-[#121826] shadow-xl p-5 card-motion space-y-4">
            <h3 class="text-sm font-semibold text-[#F3F4F6]">Benchmark vs network</h3>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
                <div>
                    <div class="text-xs text-gray-500 uppercase tracking-wide mb-1">Average order value</div>
                    <div class="flex items-baseline gap-2">
                        <div class="text-lg font-semibold text-[#F3F4F6]">
                            <?= $networkBenchmark['restaurant']['average_order_value'] !== null ? number_format($networkBenchmark['restaurant']['average_order_value'], 0) . ' ₽' : '—' ?>
                        </div>
                        <div class="text-xs text-gray-500">
                            vs <?= $networkBenchmark['network']['average_order_value'] !== null ? number_format($networkBenchmark['network']['average_order_value'], 0) . ' ₽' : '—' ?>
                        </div>
                    </div>
                </div>
                <div>
                    <div class="text-xs text-gray-500 uppercase tracking-wide mb-1">Repeat guest rate</div>
                    <div class="flex items-baseline gap-2">
                        <div class="text-lg font-semibold text-[#F3F4F6]">
                            <?= $networkBenchmark['restaurant']['repeat_guest_rate'] !== null ? (float)$networkBenchmark['restaurant']['repeat_guest_rate'] . '%' : '—' ?>
                        </div>
                        <div class="text-xs text-gray-500">
                            vs <?= $networkBenchmark['network']['repeat_guest_rate'] !== null ? (float)$networkBenchmark['network']['repeat_guest_rate'] . '%' : '—' ?>
                        </div>
                    </div>
                </div>
                <div>
                    <div class="text-xs text-gray-500 uppercase tracking-wide mb-1">Orders per day</div>
                    <div class="flex items-baseline gap-2">
                        <div class="text-lg font-semibold text-[#F3F4F6]">
                            <?= $networkBenchmark['restaurant']['orders_per_day'] !== null ? (float)$networkBenchmark['restaurant']['orders_per_day'] : '—' ?>
                        </div>
                        <div class="text-xs text-gray-500">
                            vs <?= $networkBenchmark['network']['orders_per_day'] !== null ? (float)$networkBenchmark['network']['orders_per_day'] : '—' ?>
                        </div>
                    </div>
                </div>
            </div>
        </section>
        <?php elseif (!empty($networkBenchmark['reason'])): ?>
        <section class="rounded-xl border border-gray-800 bg-[#121826] shadow-xl p-5 card-motion space-y-2">
            <h3 class="text-sm font-semibold text-[#F3F4F6]">Benchmark vs network</h3>
            <p class="text-xs text-gray-500"><?= e($networkBenchmark['reason']) ?></p>
        </section>
        <?php endif; ?>

        <!-- Peak hours & Menu heatmap -->
        <section class="rounded-xl border border-gray-800 bg-[#121826] shadow-xl p-5 card-motion">
            <h3 class="text-sm font-semibold text-[#F3F4F6] mb-4">Peak hours & Menu performance</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div class="space-y-2">
                    <div class="text-xs text-gray-500 uppercase tracking-wide">Пиковые часы за 7 дней</div>
                    <?php if (!empty($peakHoursSummary['peak_hour_range_text'])): ?>
                        <div class="text-lg font-semibold text-sky-300"><?= e($peakHoursSummary['peak_hour_range_text']) ?></div>
                        <?php if (!empty($peakHoursSummary['recommendation_text'])): ?><p class="text-xs text-gray-400"><?= e($peakHoursSummary['recommendation_text']) ?></p><?php endif; ?>
                    <?php else: ?>
                        <p class="text-sm text-gray-500">Add orders to see peak hours.</p>
                    <?php endif; ?>
                </div>
                <div class="space-y-2">
                    <div class="text-xs text-gray-500 uppercase tracking-wide">Menu heatmap</div>
                    <?php if (!empty($menuHeatmap['has_data'])): ?>
                        <div class="flex flex-wrap gap-4 text-sm">
                            <?php if (!empty($menuHeatmap['top'])): ?>
                                <div>
                                    <span class="text-gray-500">Top:</span>
                                    <span class="text-emerald-400"><?= e(implode(', ', array_column(array_slice($menuHeatmap['top'], 0, 3), 'name'))) ?></span>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($menuHeatmap['low'])): ?>
                                <div>
                                    <span class="text-gray-500">Low visibility:</span>
                                    <span class="text-amber-400"><?= e(implode(', ', array_column(array_slice($menuHeatmap['low'], 0, 3), 'name'))) ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <p class="text-sm text-gray-500">Add orders to see menu performance.</p>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <!-- Recommended next actions -->
        <?php if (!empty($recommendedActions)): ?>
        <section class="rounded-xl border border-gray-800 bg-[#121826] shadow-xl p-5 card-motion space-y-4">
            <h3 class="text-sm font-semibold text-[#F3F4F6]">Recommended next actions</h3>
            <div class="flex flex-wrap gap-3">
                <?php foreach ($recommendedActions as $act): ?>
                    <a href="<?= e($act['url']) ?>" class="btn-motion inline-flex items-center px-4 py-2.5 rounded-xl bg-gray-800 hover:bg-gray-700 text-[#F3F4F6] text-sm font-medium border border-gray-700"><?= e($act['label']) ?></a>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

        <?php if (!is_demo_mode() && $restId > 0 && $referralLink !== ''): ?>
        <section class="dashboard-card rounded-xl border border-gray-800 bg-[#121826] shadow-lg shadow-black/20 p-5 card-motion">
            <h3 class="text-sm font-semibold text-[#F3F4F6] mb-2">Invite restaurants</h3>
            <p class="text-xs text-gray-400 mb-3">Поделитесь ссылкой или пригласите по email — при регистрации мы засчитаем приглашение.</p>
            <div class="flex flex-wrap gap-2 items-center">
                <input type="text" id="dashboard-referral-link" readonly value="<?= e($referralLink) ?>" class="flex-1 min-w-0 max-w-md rounded-xl bg-slate-950 border border-slate-700 px-3 py-2 text-xs text-slate-300">
                <button type="button" class="js-copy-link px-3 py-2 rounded-xl bg-slate-700 hover:bg-slate-600 text-sm font-medium relative" data-copy-target="dashboard-referral-link">
                    Copy link
                    <span class="js-copy-tooltip hidden absolute left-1/2 -translate-x-1/2 -top-8 px-2 py-1 rounded bg-emerald-600 text-white text-xs whitespace-nowrap">Copied!</span>
                </button>
                <a href="/restaurant/invite.php" class="px-3 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-medium">Invite by email</a>
            </div>
            <p class="text-xs text-gray-500 mt-2">Приглашено ресторанов: <strong><?= (int)$invitedCount ?></strong></p>
        </section>
        <?php endif; ?>
    </div>
</main>

<?php if (!empty($revenueTrend7)): ?>
<script>
(function() {
    const labels = <?= json_encode(array_keys($revenueTrend7), JSON_UNESCAPED_UNICODE) ?>;
    const data = <?= json_encode(array_values($revenueTrend7), JSON_UNESCAPED_UNICODE) ?>;
    const ctx = document.getElementById('dashboardRevenueChart');
    if (ctx && typeof Chart !== 'undefined') {
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Выручка (₽)',
                    data: data,
                    borderColor: 'rgb(34, 197, 94)',
                    backgroundColor: 'rgba(34, 197, 94, 0.1)',
                    fill: true,
                    tension: 0.2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: { y: { beginAtZero: true } }
            }
        });
    }
})();
</script>
<?php endif; ?>
<script src="/assets/js/motion.js"></script>
<script src="/assets/js/toast.js"></script>
<script src="/assets/js/command-palette.js"></script>
<script type="application/json" id="command-palette-data">
<?= json_encode([
    ['label' => 'Панель управления', 'url' => '/restaurant/dashboard.php'],
    ['label' => 'Работа с гостями', 'url' => '/restaurant/crm.php'],
    ['label' => 'Выручка', 'url' => '/restaurant/revenue.php'],
    ['label' => 'Допродажи', 'url' => '/restaurant/upsells.php'],
    ['label' => 'Меню', 'url' => '/restaurant/menu_manage.php'],
    ['label' => 'Заказы', 'url' => '/restaurant/orders.php'],
    ['label' => 'Заказ официанта (POS)', 'url' => '/staff/pos.php'],
    ['label' => 'Экран кухни', 'url' => '/staff/kitchen.php'],
    ['label' => 'Настройки', 'url' => '/restaurant/settings.php'],
], JSON_UNESCAPED_UNICODE) ?>
</script>
<script>
(function copilotAsk() {
    var btn = document.getElementById('copilot-ask-btn');
    var input = document.getElementById('copilot-question');
    var answerEl = document.getElementById('copilot-answer');
    var actionBtnsEl = document.getElementById('copilot-action-buttons');
    var loadingEl = document.getElementById('copilot-loading');
    if (!btn || !input || !answerEl) return;
    var actionLabels = {
        create_combo: 'Создать комбо',
        create_campaign: 'Создать кампанию',
        promote_menu_item: 'Продвинуть блюдо',
        create_upsell: 'Добавить допродажу'
    };
    function showAnswer(text, suggestedActions) {
        loadingEl.classList.add('hidden');
        answerEl.classList.remove('hidden');
        answerEl.textContent = text || '';
        if (actionBtnsEl) {
            actionBtnsEl.classList.add('hidden');
            actionBtnsEl.innerHTML = '';
            if (suggestedActions && suggestedActions.length) {
                suggestedActions.forEach(function(actionKey) {
                    var label = actionLabels[actionKey] || actionKey;
                    var b = document.createElement('button');
                    b.type = 'button';
                    b.className = 'px-3 py-1.5 rounded-lg bg-violet-600/80 hover:bg-violet-500 text-white text-xs font-medium';
                    b.textContent = label;
                    b.dataset.action = actionKey;
                    actionBtnsEl.appendChild(b);
                });
                actionBtnsEl.classList.remove('hidden');
            }
        }
    }
    function showLoading() {
        answerEl.classList.add('hidden');
        if (actionBtnsEl) actionBtnsEl.classList.add('hidden');
        if (loadingEl) loadingEl.classList.remove('hidden');
    }
    function runCopilotAction(actionKey) {
        var form = new FormData();
        form.append('action', actionKey);
        fetch('/restaurant/copilot_action.php', { method: 'POST', body: form, credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success && window.Toast) window.Toast.success(data.message || 'Черновик создан.');
                else if (!data.success && window.Toast) window.Toast.error(data.message || 'Ошибка.');
            })
            .catch(function() {
                if (window.Toast) window.Toast.error('Не удалось создать предложение.');
            });
    }
    btn.addEventListener('click', function() {
        var q = (input.value || '').trim();
        if (!q) return;
        showLoading();
        var form = new FormData();
        form.append('question', q);
        fetch('/restaurant/copilot_answer.php', { method: 'POST', body: form, credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                showAnswer(data.answer || 'Нет ответа.', data.suggested_actions);
            })
            .catch(function() {
                showAnswer('Не удалось получить ответ. Попробуйте снова.');
            });
    });
    input.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') btn.click();
    });
    if (actionBtnsEl) {
        actionBtnsEl.addEventListener('click', function(e) {
            var b = e.target.closest('button[data-action]');
            if (b && b.dataset.action) runCopilotAction(b.dataset.action);
        });
    }
})();
(function () {
    var overlay = document.getElementById('dash-nav-overlay');
    var openBtn = document.getElementById('dash-nav-open');
    var closeBtn = document.getElementById('dash-nav-close');
    var backdrop = document.getElementById('dash-nav-backdrop');
    function openNav() {
        if (!overlay) return;
        overlay.classList.remove('hidden');
        document.body.style.overflow = 'hidden';
        if (openBtn) openBtn.setAttribute('aria-expanded', 'true');
    }
    function closeNav() {
        if (!overlay) return;
        overlay.classList.add('hidden');
        document.body.style.overflow = '';
        if (openBtn) openBtn.setAttribute('aria-expanded', 'false');
    }
    if (openBtn) openBtn.addEventListener('click', openNav);
    if (closeBtn) closeBtn.addEventListener('click', closeNav);
    if (backdrop) backdrop.addEventListener('click', closeNav);
    if (overlay) {
        overlay.querySelectorAll('a').forEach(function (a) {
            a.addEventListener('click', closeNav);
        });
    }
})();
document.querySelectorAll('.js-copy-link').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var targetId = this.getAttribute('data-copy-target');
        var copyText = this.getAttribute('data-copy-text');
        var text = copyText || (targetId ? (document.getElementById(targetId) && document.getElementById(targetId).value) : '');
        var tooltip = this.querySelector('.js-copy-tooltip');
        if (text) {
            navigator.clipboard.writeText(text).then(function() {
                if (window.Toast) window.Toast.success('Скопировано');
                if (tooltip) { tooltip.classList.remove('hidden'); setTimeout(function() { tooltip.classList.add('hidden'); }, 2000); }
            });
        }
    });
});

(function analyticsFoundationWidget() {
    var block = document.getElementById('analytics-foundation-block');
    if (!block) return;

    var nodes = {
        orders: document.getElementById('af-orders'),
        revenue: document.getElementById('af-revenue'),
        avg: document.getElementById('af-avg'),
        delivery: document.getElementById('af-delivery'),
        reservations: document.getElementById('af-res'),
        repeat: document.getElementById('af-repeat'),
        loyalty: document.getElementById('af-loyalty'),
        tips: document.getElementById('af-tips'),
        hotspots: document.getElementById('af-hotspots'),
        slaSuccess: document.getElementById('af-sla-success'),
        deliveryMargin: document.getElementById('af-delivery-margin'),
        topCourier: document.getElementById('af-top-courier'),
        fcOrders: document.getElementById('af-fc-orders'),
        fcRevenue: document.getElementById('af-fc-revenue'),
        fcStaff: document.getElementById('af-fc-staff'),
        fcStock: document.getElementById('af-fc-stock'),
        period: document.getElementById('af-period-label'),
        delta: document.getElementById('af-delta-label'),
        alerts: document.getElementById('af-alerts')
    };
    var rangeButtons = Array.prototype.slice.call(document.querySelectorAll('.analytics-range-btn'));
    var activeRange = 'today';

    function fmtMoney(v) {
        var n = Number(v || 0);
        if (!Number.isFinite(n)) return '0 ₽';
        return n.toLocaleString('ru-RU', { maximumFractionDigits: 0 }) + ' ₽';
    }

    function setRangeUi(range) {
        rangeButtons.forEach(function(btn) {
            if (!btn || !btn.dataset) return;
            var isActive = btn.dataset.range === range;
            btn.classList.toggle('border-cyan-500', isActive);
            btn.classList.toggle('text-cyan-200', isActive);
            btn.classList.toggle('bg-cyan-500/10', isActive);
        });
    }

    function renderAlerts(alerts) {
        if (!nodes.alerts) return;
        if (!Array.isArray(alerts) || alerts.length === 0) {
            nodes.alerts.innerHTML = '<div class="text-[11px] text-emerald-300">Operational alerts не обнаружены.</div>';
            return;
        }
        var html = alerts.map(function(a) {
            var level = String((a && a.level) || 'warning');
            var tone = level === 'critical'
                ? 'border-red-500/50 bg-red-500/10 text-red-200'
                : 'border-amber-500/50 bg-amber-500/10 text-amber-200';
            var label = String((a && a.label) || 'Alert');
            var msg = String((a && a.message) || '');
            return '<div class="rounded-lg border px-2 py-1 ' + tone + '">' +
                '<span class="font-semibold">' + label + ':</span> ' + msg +
                '</div>';
        }).join('');
        nodes.alerts.innerHTML = html;
    }

    function load(range) {
        activeRange = range || activeRange;
        setRangeUi(activeRange);
        fetch('/ajax/analytics_summary.php?range=' + encodeURIComponent(activeRange), {
            credentials: 'same-origin'
        })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (!data || !data.success) {
                    throw new Error((data && data.message) ? data.message : 'Analytics unavailable');
                }
                var orders = data.orders || {};
                var revenue = data.revenue || {};
                var delivery = data.delivery || {};
                var reservations = data.reservations || {};
                var guests = data.guests || {};
                var loyalty = data.loyalty || {};
                var promo = data.promo || {};
                var tips = data.tips || {};
                var reviews = data.reviews || {};
                var deliveryOps = data.delivery_ops || {};
                var deliverySla = deliveryOps.sla || {};
                var deliveryProfit = deliveryOps.profitability || {};
                var deliveryHotspots = deliveryOps.hotspots || {};
                var deliveryCourier = deliveryOps.courier || {};
                var forecasting = data.forecasting || {};
                var todayForecast = forecasting.today || {};
                var todayOrdersForecast = todayForecast.orders || {};
                var todayRevenueForecast = todayForecast.revenue || {};
                var todayStaffingForecast = todayForecast.staffing_need || {};
                var todayInventoryForecast = todayForecast.inventory_pressure || {};
                var trends = data.trends || {};
                var period = data.period || {};

                if (nodes.orders) nodes.orders.textContent = String(Number(orders.orders_count || 0));
                if (nodes.revenue) nodes.revenue.textContent = fmtMoney(revenue.revenue || 0);
                if (nodes.avg) nodes.avg.textContent = fmtMoney(revenue.average_check || 0);
                if (nodes.delivery) nodes.delivery.textContent = String(Number(delivery.delivery_orders_count || 0)) + ' / ' + fmtMoney(delivery.delivery_revenue || 0);
                if (nodes.reservations) nodes.reservations.textContent = String(Number(reservations.upcoming_count || 0)) + ' upcoming · ' + String(Number(reservations.current_count || 0)) + ' now';
                if (nodes.repeat) nodes.repeat.textContent = String(Number(guests.repeat_guests_period || 0)) + ' (' + String(Number(guests.repeat_rate_percent || 0)) + '%)';
                if (nodes.loyalty) nodes.loyalty.textContent = String(Number(loyalty.usage_orders_count || 0)) + ' / promo ' + String(Number(promo.usage_count || 0));
                if (nodes.tips) nodes.tips.textContent = fmtMoney(tips.tips_paid_total || 0) + ' · NPS ' + (reviews.nps_score === null || typeof reviews.nps_score === 'undefined' ? '—' : String(reviews.nps_score));
                if (nodes.hotspots) {
                    var hs = Array.isArray(deliveryHotspots.alerts) ? deliveryHotspots.alerts.length : 0;
                    nodes.hotspots.textContent = hs > 0 ? String(hs) + ' alert(s)' : 'Нет';
                }
                if (nodes.slaSuccess) {
                    nodes.slaSuccess.textContent = String(Number(deliverySla.sla_success_percent || 0).toFixed(1)) + '%';
                }
                if (nodes.deliveryMargin) {
                    nodes.deliveryMargin.textContent = fmtMoney(deliveryProfit.estimated_margin || 0);
                }
                if (nodes.topCourier) {
                    var topCouriers = Array.isArray(deliveryCourier.top_couriers) ? deliveryCourier.top_couriers : [];
                    if (topCouriers.length > 0) {
                        var c = topCouriers[0] || {};
                        nodes.topCourier.textContent = String(c.name || '—') + ' · ' + String(Number(c.delivered_count || 0));
                    } else {
                        nodes.topCourier.textContent = '—';
                    }
                }
                if (nodes.fcOrders) {
                    nodes.fcOrders.textContent = String(Number(todayOrdersForecast.expected_orders || 0)) + ' · share ' + String(Number(todayOrdersForecast.expected_delivery_share_percent || 0).toFixed(1)) + '%';
                }
                if (nodes.fcRevenue) {
                    nodes.fcRevenue.textContent = fmtMoney(todayRevenueForecast.expected_revenue || 0);
                }
                if (nodes.fcStaff) {
                    var gapCourier = Number(todayStaffingForecast.courier_gap || 0);
                    var gapKitchen = Number(todayStaffingForecast.kitchen_gap || 0);
                    var gapWaiter = Number(todayStaffingForecast.waiter_gap || 0);
                    nodes.fcStaff.textContent = 'C ' + gapCourier + ' · K ' + gapKitchen + ' · W ' + gapWaiter;
                }
                if (nodes.fcStock) {
                    var highRisk = Array.isArray(todayInventoryForecast.high_risk_items) ? todayInventoryForecast.high_risk_items : [];
                    nodes.fcStock.textContent = highRisk.length > 0 ? String(highRisk.length) + ' risky items' : 'Низкий риск';
                }
                if (nodes.period) nodes.period.textContent = 'Период: ' + String(period.label || '—') + ' (' + String(period.start_at || '') + ' → ' + String(period.end_at || '') + ')';

                var revTrend = trends.revenue || null;
                if (nodes.delta && revTrend) {
                    var deltaPercent = Number(revTrend.delta_percent || 0);
                    var sign = deltaPercent > 0 ? '+' : '';
                    nodes.delta.textContent = 'Δ выручки: ' + sign + deltaPercent.toFixed(1) + '%';
                    nodes.delta.className = 'text-[11px] ' + (deltaPercent < 0 ? 'text-red-300' : (deltaPercent > 0 ? 'text-emerald-300' : 'text-gray-500'));
                }

                renderAlerts(data.alerts || []);
            })
            .catch(function() {
                if (nodes.alerts) {
                    nodes.alerts.innerHTML = '<div class="text-[11px] text-red-300">Не удалось загрузить analytics summary.</div>';
                }
            });
    }

    rangeButtons.forEach(function(btn) {
        btn.addEventListener('click', function() {
            var range = (btn.dataset && btn.dataset.range) ? btn.dataset.range : 'today';
            load(range);
        });
    });

    load(activeRange);
})();

(function demoExperience() {
    if (!document.body.classList.contains('demo-mode')) return;
    var key = 'demo_pages_visited';
    var visited = [];
    try { visited = JSON.parse(sessionStorage.getItem(key) || '[]'); } catch (e) {}
    var path = window.location.pathname;
    if (visited.indexOf(path) === -1) {
        visited.push(path);
        try { sessionStorage.setItem(key, JSON.stringify(visited)); } catch (e) {}
    }
    if (visited.length >= 3) {
        var banner = document.getElementById('demo-success-banner');
        if (banner) banner.classList.remove('hidden');
    }
    var tables = ['Table 1', 'Table 2', 'Table 3', 'Table 4', 'Table 5'];
    function showFakeOrder() {
        if (!window.Toast) return;
        var t = tables[Math.floor(Math.random() * tables.length)];
        window.Toast.info('Новый заказ со стола ' + t);
    }
    var delay = 20000 + Math.random() * 20000;
    setTimeout(function run() {
        showFakeOrder();
        setTimeout(run, 20000 + Math.random() * 20000);
    }, delay);
})();
</script>
</body>
</html> 
