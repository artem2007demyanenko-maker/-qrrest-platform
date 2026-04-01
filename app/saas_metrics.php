<?php
/**
 * SaaS Business Intelligence — platform-level metrics (MRR, churn, growth, feature adoption).
 * Visible only to platform owner (project_admin). Read-only; does not modify billing data.
 * All functions check is_project_admin() and return safe empty data otherwise.
 */

if (!function_exists('is_project_admin')) {
    require_once __DIR__ . '/auth.php';
}

const _SAAS_METRICS_CACHE_TTL = 300; // 5 minutes

function _saas_metrics_cache_get(string $key): ?array
{
    $store = &$GLOBALS['_saas_metrics_cache'];
    if (!isset($store) || !is_array($store) || !isset($store[$key]) || !is_array($store[$key])) {
        return null;
    }
    $entry = $store[$key];
    if (($entry['expires_at'] ?? 0) < time()) {
        unset($store[$key]);
        return null;
    }
    return $entry['data'] ?? null;
}

function _saas_metrics_cache_set(string $key, array $data): void
{
    if (!isset($GLOBALS['_saas_metrics_cache']) || !is_array($GLOBALS['_saas_metrics_cache'])) {
        $GLOBALS['_saas_metrics_cache'] = [];
    }
    $GLOBALS['_saas_metrics_cache'][$key] = ['data' => $data, 'expires_at' => time() + _SAAS_METRICS_CACHE_TTL];
}

/**
 * MRR and subscription metrics. Admin only. Cached 5 min.
 * @return array{mrr: float, arr: float, active_subscriptions: int, trial_users: int, trial_conversion_rate: float}
 */
function get_mrr_metrics(): array
{
    $default = [
        'mrr' => 0.0,
        'arr' => 0.0,
        'active_subscriptions' => 0,
        'trial_users' => 0,
        'trial_conversion_rate' => 0.0,
    ];

    if (!function_exists('is_project_admin') || !is_project_admin()) {
        return $default;
    }
    if (function_exists('is_demo_mode') && is_demo_mode()) {
        return [
            'mrr' => 45800.0,
            'arr' => 549600.0,
            'active_subscriptions' => 18,
            'trial_users' => 6,
            'trial_conversion_rate' => 72.5,
        ];
    }

    $cached = _saas_metrics_cache_get('saas_mrr');
    if ($cached !== null) {
        return $cached;
    }

    if (!function_exists('db') || !function_exists('db_table_exists')) {
        return $default;
    }
    $pdo = db();

    $mrr = 0.0;
    $activeSubscriptions = 0;
    if (db_table_exists('subscriptions') && db_table_exists('plans') && db_table_exists('restaurants')) {
        $deletedSqlR = function_exists('schema_guard_restaurants_deleted_sql') ? schema_guard_restaurants_deleted_sql('r') : '';
        try {
            $stmt = $pdo->query("
                SELECT COALESCE(SUM(p.price_month), 0) AS mrr
                FROM subscriptions s
                INNER JOIN plans p ON p.id = s.plan_id AND p.is_active = 1 AND p.price_month > 0
                WHERE s.status = 'active'
            ");
            $mrr = (float) ($stmt->fetchColumn() ?: 0);
            $stmt = $pdo->query("
                SELECT COUNT(DISTINCT r.id) AS cnt
                FROM restaurants r
                INNER JOIN subscriptions s ON s.user_id = r.owner_user_id AND s.status = 'active'
                INNER JOIN plans p ON p.id = s.plan_id AND p.price_month > 0
                WHERE 1=1 {$deletedSqlR}
            ");
            $activeSubscriptions = (int) ($stmt->fetchColumn() ?: 0);
        } catch (Throwable $e) {
            // ignore
        }
    }

    $trialUsers = 0;
    if (db_table_exists('restaurants')) {
        $deletedSql = function_exists('schema_guard_restaurants_deleted_sql') ? schema_guard_restaurants_deleted_sql('r') : '';
        if (db_table_exists('subscriptions') && db_table_exists('plans')) {
            try {
                $stmt = $pdo->query("
                    SELECT COUNT(DISTINCT r.id) AS cnt
                    FROM restaurants r
                    WHERE r.owner_user_id IS NOT NULL {$deletedSql}
                    AND NOT EXISTS (
                        SELECT 1 FROM subscriptions s
                        INNER JOIN plans p ON p.id = s.plan_id AND p.price_month > 0
                        WHERE s.user_id = r.owner_user_id AND s.status = 'active'
                    )
                ");
                $trialUsers = (int) ($stmt->fetchColumn() ?: 0);
            } catch (Throwable $e) {
                $trialUsers = 0;
            }
        }
    }

    $totalWithSub = $activeSubscriptions + $trialUsers;
    $trialConversionRate = $totalWithSub > 0 ? round(100.0 * $activeSubscriptions / $totalWithSub, 1) : 0.0;

    $result = [
        'mrr' => round($mrr, 2),
        'arr' => round($mrr * 12, 2),
        'active_subscriptions' => $activeSubscriptions,
        'trial_users' => $trialUsers,
        'trial_conversion_rate' => $trialConversionRate,
    ];
    _saas_metrics_cache_set('saas_mrr', $result);
    return $result;
}

/**
 * Platform growth metrics. Admin only. Cached 5 min.
 * @return array{restaurants_total: int, restaurants_last_30_days: int, restaurants_growth_rate: float, orders_last_30_days: int, revenue_last_30_days: float}
 */
function get_platform_growth_metrics(): array
{
    $default = [
        'restaurants_total' => 0,
        'restaurants_last_30_days' => 0,
        'restaurants_growth_rate' => 0.0,
        'orders_last_30_days' => 0,
        'revenue_last_30_days' => 0.0,
    ];

    if (!function_exists('is_project_admin') || !is_project_admin()) {
        return $default;
    }
    if (function_exists('is_demo_mode') && is_demo_mode()) {
        return [
            'restaurants_total' => 24,
            'restaurants_last_30_days' => 3,
            'restaurants_growth_rate' => 12.5,
            'orders_last_30_days' => 1240,
            'revenue_last_30_days' => 892000.0,
        ];
    }

    $cached = _saas_metrics_cache_get('saas_growth');
    if ($cached !== null) {
        return $cached;
    }

    if (!function_exists('db') || !function_exists('db_table_exists')) {
        return $default;
    }
    $pdo = db();
    $since30 = date('Y-m-d 00:00:00', strtotime('-30 days'));
    $deletedSql = function_exists('schema_guard_restaurants_deleted_sql') ? schema_guard_restaurants_deleted_sql('r') : '';

    $restaurantsTotal = 0;
    $restaurantsLast30 = 0;
    if (db_table_exists('restaurants')) {
        try {
            $stmt = $pdo->query("SELECT COUNT(*) FROM restaurants r WHERE 1=1 {$deletedSql}");
            $restaurantsTotal = (int) $stmt->fetchColumn();
            $createdCol = function_exists('db_column_exists') && db_column_exists('restaurants', 'created_at');
            if ($createdCol) {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM restaurants r WHERE r.created_at >= ? {$deletedSql}");
                $stmt->execute([$since30]);
                $restaurantsLast30 = (int) $stmt->fetchColumn();
            }
        } catch (Throwable $e) {
            // ignore
        }
    }

    $growthRate = $restaurantsTotal > 0 ? round(100.0 * $restaurantsLast30 / $restaurantsTotal, 1) : 0.0;

    $ordersLast30 = 0;
    $revenueLast30 = 0.0;
    $revenueCol = (function_exists('db_column_exists') && db_column_exists('orders', 'total_amount')) ? 'total_amount' : 'total_price';
    if (db_table_exists('orders')) {
        try {
            $stmt = $pdo->query("
                SELECT COUNT(*) AS cnt, COALESCE(SUM(CASE WHEN payment_status = 'paid' AND (order_status IS NULL OR order_status <> 'canceled') THEN {$revenueCol} ELSE 0 END), 0) AS rev
                FROM orders WHERE created_at >= " . $pdo->quote($since30) . " AND (order_status IS NULL OR order_status <> 'canceled')
            ");
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $ordersLast30 = (int) ($row['cnt'] ?? 0);
            $revenueLast30 = (float) ($row['rev'] ?? 0);
        } catch (Throwable $e) {
            // ignore
        }
    }

    $result = [
        'restaurants_total' => $restaurantsTotal,
        'restaurants_last_30_days' => $restaurantsLast30,
        'restaurants_growth_rate' => $growthRate,
        'orders_last_30_days' => $ordersLast30,
        'revenue_last_30_days' => $revenueLast30,
    ];
    _saas_metrics_cache_set('saas_growth', $result);
    return $result;
}

/**
 * Churn risk metrics. Admin only. Cached 5 min.
 * @return array{at_risk_restaurants: int, inactive_restaurants_14_days: int, low_health_restaurants: int}
 */
function get_saas_churn_metrics(): array
{
    $default = [
        'at_risk_restaurants' => 0,
        'inactive_restaurants_14_days' => 0,
        'low_health_restaurants' => 0,
    ];

    if (!function_exists('is_project_admin') || !is_project_admin()) {
        return $default;
    }
    if (function_exists('is_demo_mode') && is_demo_mode()) {
        return [
            'at_risk_restaurants' => 4,
            'inactive_restaurants_14_days' => 3,
            'low_health_restaurants' => 2,
        ];
    }

    $cached = _saas_metrics_cache_get('saas_churn');
    if ($cached !== null) {
        return $cached;
    }

    if (!function_exists('db') || !function_exists('db_table_exists')) {
        return $default;
    }
    $pdo = db();
    $since14 = date('Y-m-d 00:00:00', strtotime('-14 days'));
    $deletedSql = function_exists('schema_guard_restaurants_deleted_sql') ? schema_guard_restaurants_deleted_sql('r') : '';

    $inactiveIds = [];
    if (db_table_exists('restaurants') && db_table_exists('orders')) {
        try {
            $stmt = $pdo->query("
                SELECT r.id FROM restaurants r
                WHERE 1=1 {$deletedSql}
                AND NOT EXISTS (
                    SELECT 1 FROM orders o WHERE o.restaurant_id = r.id AND o.created_at >= " . $pdo->quote($since14) . "
                )
            ");
            $inactiveIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable $e) {
            // ignore
        }
    }
    $inactive14 = count($inactiveIds);

    $lowHealthIds = [];
    if (db_table_exists('restaurants') && file_exists(__DIR__ . '/restaurant_health.php')) {
        require_once __DIR__ . '/restaurant_health.php';
        try {
            $stmt = $pdo->query("SELECT id FROM restaurants r WHERE 1=1 {$deletedSql}");
            $restIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
            foreach ($restIds as $rid) {
                $h = get_restaurant_health_score((int) $rid);
                if ((int) ($h['score'] ?? 0) < 40) {
                    $lowHealthIds[] = (int) $rid;
                }
            }
        } catch (Throwable $e) {
            // ignore
        }
    }
    $lowHealth = count($lowHealthIds);

    $atRisk = count(array_unique(array_merge($inactiveIds, $lowHealthIds)));

    $result = [
        'at_risk_restaurants' => $atRisk,
        'inactive_restaurants_14_days' => $inactive14,
        'low_health_restaurants' => $lowHealth,
    ];
    _saas_metrics_cache_set('saas_churn', $result);
    return $result;
}

/**
 * Feature adoption counts (distinct restaurants). Admin only.
 * @return array{qr_orders_enabled: int, upsell_rules_active: int, crm_campaigns_created: int, combos_created: int}
 */
function get_feature_adoption_metrics(): array
{
    $default = [
        'qr_orders_enabled' => 0,
        'upsell_rules_active' => 0,
        'crm_campaigns_created' => 0,
        'combos_created' => 0,
    ];

    if (!function_exists('is_project_admin') || !is_project_admin()) {
        return $default;
    }
    if (function_exists('is_demo_mode') && is_demo_mode()) {
        return [
            'qr_orders_enabled' => 20,
            'upsell_rules_active' => 14,
            'crm_campaigns_created' => 8,
            'combos_created' => 5,
        ];
    }

    if (!function_exists('db') || !function_exists('db_table_exists')) {
        return $default;
    }
    $pdo = db();

    $qrOrders = 0;
    if (db_table_exists('orders')) {
        try {
            $stmt = $pdo->query("SELECT COUNT(DISTINCT restaurant_id) FROM orders");
            $qrOrders = (int) $stmt->fetchColumn();
        } catch (Throwable $e) {
            // ignore
        }
    }

    $upsellActive = 0;
    if (db_table_exists('upsell_rules')) {
        try {
            $stmt = $pdo->query("SELECT COUNT(DISTINCT restaurant_id) FROM upsell_rules WHERE active = 1");
            $upsellActive = (int) $stmt->fetchColumn();
        } catch (Throwable $e) {
            // ignore
        }
    }

    $crmCampaigns = 0;
    if (db_table_exists('crm_campaigns')) {
        try {
            $stmt = $pdo->query("SELECT COUNT(DISTINCT restaurant_id) FROM crm_campaigns");
            $crmCampaigns = (int) $stmt->fetchColumn();
        } catch (Throwable $e) {
            // ignore
        }
    }

    $combosCreated = 0;
    if (db_table_exists('growth_engine_suggestions')) {
        try {
            $stmt = $pdo->query("SELECT COUNT(DISTINCT restaurant_id) FROM growth_engine_suggestions WHERE type = 'combo_suggestion'");
            $combosCreated = (int) $stmt->fetchColumn();
        } catch (Throwable $e) {
            // ignore
        }
    }

    return [
        'qr_orders_enabled' => $qrOrders,
        'upsell_rules_active' => $upsellActive,
        'crm_campaigns_created' => $crmCampaigns,
        'combos_created' => $combosCreated,
    ];
}

/**
 * Platform insights (up to 5 text lines). Admin only.
 * @return array<int, string>
 */
function get_platform_insights(): array
{
    if (!function_exists('is_project_admin') || !is_project_admin()) {
        return [];
    }
    if (function_exists('is_demo_mode') && is_demo_mode()) {
        return [
            'MRR растёт стабильно.',
            'Конверсия из триала улучшается.',
            'Несколько ресторанов в зоне риска оттока.',
            'Внедрение допродаж растёт.',
            'Новых ресторанов за 30 дней: 3.',
        ];
    }

    $insights = [];
    try {
        $mrr = get_mrr_metrics();
        if (($mrr['mrr'] ?? 0) > 0) {
            $insights[] = 'MRR учитывает активные платные подписки.';
        }
        if (($mrr['trial_conversion_rate'] ?? 0) >= 50) {
            $insights[] = 'Конверсия из триала на хорошем уровне.';
        } elseif (($mrr['trial_users'] ?? 0) > 0) {
            $insights[] = 'Есть потенциал роста конверсии из триала.';
        }

        $churn = get_saas_churn_metrics();
        if (($churn['at_risk_restaurants'] ?? 0) > 0) {
            $insights[] = 'Часть ресторанов в зоне риска оттока — стоит обратить внимание.';
        }

        $growth = get_platform_growth_metrics();
        if (($growth['restaurants_last_30_days'] ?? 0) > 0) {
            $insights[] = 'За последние 30 дней подключились новые рестораны.';
        }

        $adopt = get_feature_adoption_metrics();
        if (($adopt['upsell_rules_active'] ?? 0) > 0) {
            $insights[] = 'Допродажи активно используются ресторанами.';
        }

        $insights = array_slice($insights, 0, 5);
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('saas_metrics insights ' . $e->getMessage());
        }
    }
    return $insights;
}

/**
 * Revenue by day (last 30 days) for chart. Admin only.
 * @return array<string, float>
 */
function get_saas_revenue_by_day_30(): array
{
    if (!function_exists('is_project_admin') || !is_project_admin()) {
        return [];
    }
    if (function_exists('is_demo_mode') && is_demo_mode()) {
        $out = [];
        for ($i = 29; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-{$i} days"));
            $out[$d] = (float) rand(25000, 45000);
        }
        ksort($out);
        return $out;
    }

    if (!function_exists('db') || !function_exists('db_table_exists') || !db_table_exists('orders')) {
        return [];
    }
    $pdo = db();
    $since30 = date('Y-m-d 00:00:00', strtotime('-30 days'));
    $revenueCol = (function_exists('db_column_exists') && db_column_exists('orders', 'total_amount')) ? 'total_amount' : 'total_price';
    $out = [];
    try {
        $stmt = $pdo->query("
            SELECT DATE(created_at) AS d, COALESCE(SUM(CASE WHEN payment_status = 'paid' AND (order_status IS NULL OR order_status <> 'canceled') THEN {$revenueCol} ELSE 0 END), 0) AS rev
            FROM orders WHERE created_at >= " . $pdo->quote($since30) . " AND (order_status IS NULL OR order_status <> 'canceled')
            GROUP BY DATE(created_at)
        ");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $out[$row['d']] = (float) $row['rev'];
        }
        for ($i = 29; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-{$i} days"));
            if (!isset($out[$d])) {
                $out[$d] = 0.0;
            }
        }
        ksort($out);
    } catch (Throwable $e) {
        // ignore
    }
    return $out;
}

/**
 * New restaurants per day (last 30 days) for chart. Admin only.
 * @return array<string, int>
 */
function get_saas_new_restaurants_by_day_30(): array
{
    if (!function_exists('is_project_admin') || !is_project_admin()) {
        return [];
    }
    if (function_exists('is_demo_mode') && is_demo_mode()) {
        $out = [];
        for ($i = 29; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-{$i} days"));
            $out[$d] = $i === 5 || $i === 12 ? 1 : 0;
        }
        ksort($out);
        return $out;
    }

    if (!function_exists('db') || !function_exists('db_table_exists') || !db_table_exists('restaurants')) {
        return [];
    }
    $pdo = db();
    if (!function_exists('db_column_exists') || !db_column_exists('restaurants', 'created_at')) {
        return [];
    }
    $since30 = date('Y-m-d 00:00:00', strtotime('-30 days'));
    $deletedSql = function_exists('schema_guard_restaurants_deleted_sql') ? schema_guard_restaurants_deleted_sql('r') : '';
    $out = [];
    try {
        $stmt = $pdo->query("
            SELECT DATE(r.created_at) AS d, COUNT(*) AS cnt
            FROM restaurants r
            WHERE r.created_at >= " . $pdo->quote($since30) . " {$deletedSql}
            GROUP BY DATE(r.created_at)
        ");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $out[$row['d']] = (int) $row['cnt'];
        }
        for ($i = 29; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-{$i} days"));
            if (!isset($out[$d])) {
                $out[$d] = 0;
            }
        }
        ksort($out);
    } catch (Throwable $e) {
        // ignore
    }
    return $out;
}
