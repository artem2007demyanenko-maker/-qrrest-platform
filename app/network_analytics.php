<?php
/**
 * Network Analytics (internal only). Platform owner (project_admin) can view
 * cross-restaurant metrics. Restaurant users must NEVER call this module.
 * All functions check is_project_admin() before executing queries.
 */

if (!function_exists('is_project_admin')) {
    require_once __DIR__ . '/auth.php';
}

/** In-memory cache for network overview and trends (TTL 5 min). */
function _network_analytics_cache_get(string $key): ?array
{
    $ttl = 300; // 5 minutes
    $store = &$GLOBALS['_network_analytics_cache'];
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

function _network_analytics_cache_set(string $key, array $data): void
{
    $ttl = 300;
    if (!isset($GLOBALS['_network_analytics_cache']) || !is_array($GLOBALS['_network_analytics_cache'])) {
        $GLOBALS['_network_analytics_cache'] = [];
    }
    $GLOBALS['_network_analytics_cache'][$key] = ['data' => $data, 'expires_at' => time() + $ttl];
}

/**
 * Network overview: totals and averages (last 7 days). Admin only. Cached 5 min.
 * @return array{total_restaurants: int, active_restaurants_last_7_days: int, total_orders_7_days: int, total_revenue_7_days: float, average_order_network: float, average_checkout_conversion: float, average_return_rate: float}
 */
function get_network_overview(): array
{
    $default = [
        'total_restaurants' => 0,
        'active_restaurants_last_7_days' => 0,
        'total_orders_7_days' => 0,
        'total_revenue_7_days' => 0.0,
        'average_order_network' => 0.0,
        'average_checkout_conversion' => 0.0,
        'average_return_rate' => 0.0,
    ];

    if (!function_exists('is_project_admin') || !is_project_admin()) {
        return $default;
    }
    if (function_exists('is_demo_mode') && is_demo_mode()) {
        return [
            'total_restaurants' => 12,
            'active_restaurants_last_7_days' => 8,
            'total_orders_7_days' => 340,
            'total_revenue_7_days' => 284500.0,
            'average_order_network' => 837.35,
            'average_checkout_conversion' => 68.5,
            'average_return_rate' => 22.3,
        ];
    }

    $cached = _network_analytics_cache_get('network_overview');
    if ($cached !== null) {
        return $cached;
    }

    if (!function_exists('db')) {
        return $default;
    }
    $pdo = db();
    $deletedSql = function_exists('schema_guard_restaurants_deleted_sql') ? schema_guard_restaurants_deleted_sql('r') : '';
    $since7 = date('Y-m-d 00:00:00', strtotime('-7 days'));

    $total_restaurants = 0;
    if (function_exists('db_table_exists') && db_table_exists('restaurants')) {
        $sql = "SELECT COUNT(*) FROM restaurants r WHERE 1=1 {$deletedSql}";
        try {
            $total_restaurants = (int) $pdo->query($sql)->fetchColumn();
        } catch (Throwable $e) {
            // ignore
        }
    }

    $active_restaurants_last_7_days = 0;
    $total_orders_7_days = 0;
    $total_revenue_7_days = 0.0;
    if (function_exists('db_table_exists') && db_table_exists('orders')) {
        try {
            $stmt = $pdo->query("
                SELECT COUNT(DISTINCT restaurant_id) AS active_rest,
                       COUNT(*) AS orders_cnt,
                       COALESCE(SUM(CASE WHEN payment_status = 'paid' AND (order_status IS NULL OR order_status <> 'canceled') THEN total_price ELSE 0 END), 0) AS revenue
                FROM orders
                WHERE created_at >= " . $pdo->quote($since7) . "
                  AND (order_status IS NULL OR order_status <> 'canceled')
            ");
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $active_restaurants_last_7_days = (int) ($row['active_rest'] ?? 0);
            $total_orders_7_days = (int) ($row['orders_cnt'] ?? 0);
            $total_revenue_7_days = (float) ($row['revenue'] ?? 0);
        } catch (Throwable $e) {
            // ignore
        }
    }

    $average_order_network = $total_orders_7_days > 0 ? round($total_revenue_7_days / $total_orders_7_days, 2) : 0.0;

    $average_checkout_conversion = 0.0;
    if (function_exists('db_table_exists') && db_table_exists('checkout_events')) {
        try {
            $stmt = $pdo->query("
                SELECT
                  SUM(CASE WHEN event_type = 'started_checkout' THEN 1 ELSE 0 END) AS started,
                  SUM(CASE WHEN event_type = 'completed_checkout' THEN 1 ELSE 0 END) AS completed
                FROM checkout_events
                WHERE created_at >= " . $pdo->quote($since7)
            );
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $started = (int) ($row['started'] ?? 0);
            $completed = (int) ($row['completed'] ?? 0);
            $average_checkout_conversion = $started > 0 ? round(100.0 * $completed / $started, 1) : 0.0;
        } catch (Throwable $e) {
            // ignore
        }
    }

    $average_return_rate = 0.0;
    if (function_exists('db_table_exists') && db_table_exists('crm_guests')) {
        try {
            $stmt = $pdo->query("
                SELECT restaurant_id,
                       COUNT(*) AS total,
                       SUM(CASE WHEN visits_count > 1 THEN 1 ELSE 0 END) AS returning
                FROM crm_guests
                GROUP BY restaurant_id
            ");
            $rates = [];
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $t = (int) $r['total'];
                if ($t > 0) {
                    $rates[] = 100.0 * (int) $r['returning'] / $t;
                }
            }
            $average_return_rate = count($rates) > 0 ? round(array_sum($rates) / count($rates), 1) : 0.0;
        } catch (Throwable $e) {
            // ignore
        }
    }

    $result = [
        'total_restaurants' => $total_restaurants,
        'active_restaurants_last_7_days' => $active_restaurants_last_7_days,
        'total_orders_7_days' => $total_orders_7_days,
        'total_revenue_7_days' => $total_revenue_7_days,
        'average_order_network' => $average_order_network,
        'average_checkout_conversion' => $average_checkout_conversion,
        'average_return_rate' => $average_return_rate,
    ];
    _network_analytics_cache_set('network_overview', $result);
    return $result;
}

/**
 * Restaurant performance table: one row per restaurant (last 7 days). Admin only.
 * health_score from get_restaurant_health_score().
 * @return array<int, array{restaurant_id: int, restaurant_name: string, orders_last_7_days: int, revenue_last_7_days: float, average_order_value: float, checkout_conversion: float, return_rate: float, health_score: int}>
 */
function get_restaurant_performance_table(): array
{
    if (!function_exists('is_project_admin') || !is_project_admin()) {
        return [];
    }
    if (function_exists('is_demo_mode') && is_demo_mode()) {
        return [
            ['restaurant_id' => 1, 'restaurant_name' => 'Demo Cafe', 'orders_last_7_days' => 45, 'revenue_last_7_days' => 38200.0, 'average_order_value' => 848.89, 'checkout_conversion' => 72.0, 'return_rate' => 24.0, 'health_score' => 75],
            ['restaurant_id' => 2, 'restaurant_name' => 'Demo Bistro', 'orders_last_7_days' => 28, 'revenue_last_7_days' => 19600.0, 'average_order_value' => 700.0, 'checkout_conversion' => 65.0, 'return_rate' => 18.0, 'health_score' => 62],
        ];
    }

    if (!function_exists('db')) {
        return [];
    }
    $pdo = db();
    $deletedSql = function_exists('schema_guard_restaurants_deleted_sql') ? schema_guard_restaurants_deleted_sql('r') : '';
    $since7 = date('Y-m-d 00:00:00', strtotime('-7 days'));

    $restaurants = [];
    if (!function_exists('db_table_exists') || !db_table_exists('restaurants')) {
        return [];
    }
    try {
        $stmt = $pdo->query("SELECT id, name FROM restaurants r WHERE 1=1 {$deletedSql} ORDER BY name ASC");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $restaurants[(int) $row['id']] = ['id' => (int) $row['id'], 'name' => (string) $row['name']];
        }
    } catch (Throwable $e) {
        return [];
    }

    $ordersAgg = [];
    if (db_table_exists('orders')) {
        try {
            $stmt = $pdo->prepare("
                SELECT restaurant_id,
                       COUNT(*) AS orders_cnt,
                       COALESCE(SUM(CASE WHEN payment_status = 'paid' AND (order_status IS NULL OR order_status <> 'canceled') THEN total_price ELSE 0 END), 0) AS revenue
                FROM orders
                WHERE created_at >= ?
                  AND (order_status IS NULL OR order_status <> 'canceled')
                GROUP BY restaurant_id
            ");
            $stmt->execute([$since7]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $ordersAgg[(int) $row['restaurant_id']] = [
                    'orders' => (int) $row['orders_cnt'],
                    'revenue' => (float) $row['revenue'],
                ];
            }
        } catch (Throwable $e) {
            // ignore
        }
    }

    $conversionByRest = [];
    if (db_table_exists('checkout_events')) {
        try {
            $stmt = $pdo->prepare("
                SELECT restaurant_id,
                       SUM(CASE WHEN event_type = 'started_checkout' THEN 1 ELSE 0 END) AS started,
                       SUM(CASE WHEN event_type = 'completed_checkout' THEN 1 ELSE 0 END) AS completed
                FROM checkout_events
                WHERE created_at >= ?
                GROUP BY restaurant_id
            ");
            $stmt->execute([$since7]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $s = (int) $row['started'];
                $conversionByRest[(int) $row['restaurant_id']] = $s > 0 ? round(100.0 * (int) $row['completed'] / $s, 1) : 0.0;
            }
        } catch (Throwable $e) {
            // ignore
        }
    }

    $returnRateByRest = [];
    if (db_table_exists('crm_guests')) {
        try {
            $stmt = $pdo->query("
                SELECT restaurant_id, COUNT(*) AS total, SUM(CASE WHEN visits_count > 1 THEN 1 ELSE 0 END) AS returning
                FROM crm_guests GROUP BY restaurant_id
            ");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $t = (int) $row['total'];
                $returnRateByRest[(int) $row['restaurant_id']] = $t > 0 ? round(100.0 * (int) $row['returning'] / $t, 1) : 0.0;
            }
        } catch (Throwable $e) {
            // ignore
        }
    }

    $healthScoreFn = null;
    if (file_exists(__DIR__ . '/restaurant_health.php')) {
        require_once __DIR__ . '/restaurant_health.php';
        $healthScoreFn = 'get_restaurant_health_score';
    }

    $out = [];
    foreach ($restaurants as $rid => $r) {
        $agg = $ordersAgg[$rid] ?? ['orders' => 0, 'revenue' => 0.0];
        $orders = $agg['orders'];
        $revenue = $agg['revenue'];
        $aov = $orders > 0 ? round($revenue / $orders, 2) : 0.0;
        $conv = $conversionByRest[$rid] ?? 0.0;
        $retRate = $returnRateByRest[$rid] ?? 0.0;
        $health = 0;
        if ($healthScoreFn && is_callable($healthScoreFn)) {
            $h = $healthScoreFn($rid);
            $health = (int) ($h['score'] ?? 0);
        }
        $out[] = [
            'restaurant_id' => $rid,
            'restaurant_name' => $r['name'],
            'orders_last_7_days' => $orders,
            'revenue_last_7_days' => $revenue,
            'average_order_value' => $aov,
            'checkout_conversion' => $conv,
            'return_rate' => $retRate,
            'health_score' => $health,
        ];
    }
    return $out;
}

/**
 * Top restaurants by revenue (last 30 days). Admin only.
 * @return array<int, array{restaurant_name: string, revenue_last_30_days: float, orders_last_30_days: int, average_order: float}>
 */
function get_top_restaurants_by_revenue(int $limit = 10): array
{
    if (!function_exists('is_project_admin') || !is_project_admin()) {
        return [];
    }
    if (function_exists('is_demo_mode') && is_demo_mode()) {
        return [
            ['restaurant_name' => 'Demo Cafe', 'revenue_last_30_days' => 156000.0, 'orders_last_30_days' => 180, 'average_order' => 866.67],
            ['restaurant_name' => 'Demo Bistro', 'revenue_last_30_days' => 98000.0, 'orders_last_30_days' => 120, 'average_order' => 816.67],
        ];
    }

    if (!function_exists('db') || !function_exists('db_table_exists') || !db_table_exists('orders') || !db_table_exists('restaurants')) {
        return [];
    }
    $pdo = db();
    $deletedSql = function_exists('schema_guard_restaurants_deleted_sql') ? schema_guard_restaurants_deleted_sql('r') : '';
    $since30 = date('Y-m-d 00:00:00', strtotime('-30 days'));
    $limit = max(1, min(100, $limit));

    try {
        $stmt = $pdo->prepare("
            SELECT r.name AS restaurant_name,
                   COALESCE(SUM(CASE WHEN o.payment_status = 'paid' AND (o.order_status IS NULL OR o.order_status <> 'canceled') THEN o.total_price ELSE 0 END), 0) AS revenue_last_30_days,
                   COUNT(o.id) AS orders_last_30_days
            FROM restaurants r
            LEFT JOIN orders o ON o.restaurant_id = r.id AND o.created_at >= ? AND (o.order_status IS NULL OR o.order_status <> 'canceled')
            WHERE 1=1 {$deletedSql}
            GROUP BY r.id, r.name
            ORDER BY revenue_last_30_days DESC
            LIMIT " . (int) $limit
        );
        $stmt->execute([$since30]);
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $rev = (float) $row['revenue_last_30_days'];
            $ord = (int) $row['orders_last_30_days'];
            $out[] = [
                'restaurant_name' => (string) $row['restaurant_name'],
                'revenue_last_30_days' => $rev,
                'orders_last_30_days' => $ord,
                'average_order' => $ord > 0 ? round($rev / $ord, 2) : 0.0,
            ];
        }
        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Network trends for charts: last 30 days by day. Admin only. Cached 5 min.
 * @return array{orders_last_30_days_by_day: array<string, int>, revenue_last_30_days_by_day: array<string, float>, new_restaurants_last_30_days: array<string, int>}
 */
function get_network_trends(): array
{
    $default = [
        'orders_last_30_days_by_day' => [],
        'revenue_last_30_days_by_day' => [],
        'new_restaurants_last_30_days' => [],
    ];

    if (!function_exists('is_project_admin') || !is_project_admin()) {
        return $default;
    }
    if (function_exists('is_demo_mode') && is_demo_mode()) {
        $ordersByDay = [];
        $revenueByDay = [];
        $newByDay = [];
        for ($i = 29; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-{$i} days"));
            $ordersByDay[$d] = rand(8, 25);
            $revenueByDay[$d] = (float) rand(6000, 18000);
            $newByDay[$d] = rand(0, 2);
        }
        ksort($ordersByDay);
        ksort($revenueByDay);
        ksort($newByDay);
        return [
            'orders_last_30_days_by_day' => $ordersByDay,
            'revenue_last_30_days_by_day' => $revenueByDay,
            'new_restaurants_last_30_days' => $newByDay,
        ];
    }

    $cached = _network_analytics_cache_get('network_trends');
    if ($cached !== null) {
        return $cached;
    }

    if (!function_exists('db')) {
        return $default;
    }
    $pdo = db();
    $since30 = date('Y-m-d 00:00:00', strtotime('-30 days'));

    $ordersByDay = [];
    $revenueByDay = [];
    if (function_exists('db_table_exists') && db_table_exists('orders')) {
        try {
            $stmt = $pdo->query("
                SELECT DATE(created_at) AS d,
                       COUNT(*) AS orders_cnt,
                       COALESCE(SUM(CASE WHEN payment_status = 'paid' AND (order_status IS NULL OR order_status <> 'canceled') THEN total_price ELSE 0 END), 0) AS revenue
                FROM orders
                WHERE created_at >= " . $pdo->quote($since30) . " AND (order_status IS NULL OR order_status <> 'canceled')
                GROUP BY DATE(created_at)
            ");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $d = $row['d'];
                $ordersByDay[$d] = (int) $row['orders_cnt'];
                $revenueByDay[$d] = (float) $row['revenue'];
            }
        } catch (Throwable $e) {
            // ignore
        }
    }

    $newRestByDay = [];
    if (function_exists('db_table_exists') && db_table_exists('restaurants')) {
        $deletedSql = function_exists('schema_guard_restaurants_deleted_sql') ? schema_guard_restaurants_deleted_sql('r') : '';
        $createdCol = function_exists('db_column_exists') && db_column_exists('restaurants', 'created_at') ? 'created_at' : null;
        if ($createdCol) {
            try {
                $stmt = $pdo->query("
                    SELECT DATE(r.created_at) AS d, COUNT(*) AS cnt
                    FROM restaurants r
                    WHERE r.created_at >= " . $pdo->quote($since30) . " {$deletedSql}
                    GROUP BY DATE(r.created_at)
                ");
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $newRestByDay[$row['d']] = (int) $row['cnt'];
                }
            } catch (Throwable $e) {
                // ignore
            }
        }
    }

    for ($i = 29; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-{$i} days"));
        if (!isset($ordersByDay[$d])) {
            $ordersByDay[$d] = 0;
        }
        if (!isset($revenueByDay[$d])) {
            $revenueByDay[$d] = 0.0;
        }
        if (!isset($newRestByDay[$d])) {
            $newRestByDay[$d] = 0;
        }
    }
    ksort($ordersByDay);
    ksort($revenueByDay);
    ksort($newRestByDay);

    $result = [
        'orders_last_30_days_by_day' => $ordersByDay,
        'revenue_last_30_days_by_day' => $revenueByDay,
        'new_restaurants_last_30_days' => $newRestByDay,
    ];
    _network_analytics_cache_set('network_trends', $result);
    return $result;
}

/**
 * Network growth summary: text insights for admin dashboard. Admin only.
 * @return array<int, string>
 */
function get_network_growth_summary(): array
{
    if (!function_exists('is_project_admin') || !is_project_admin()) {
        return [];
    }
    if (function_exists('is_demo_mode') && is_demo_mode()) {
        return [
            'Network revenue increased 12% this month.',
            'Top performing category across restaurants: Main courses.',
            'Average checkout conversion across network: 68.5%.',
            'Average return rate across network: 22.3%.',
        ];
    }

    $overview = get_network_overview();
    $insights = [];

    $rev = $overview['total_revenue_7_days'] ?? 0;
    $orders = $overview['total_orders_7_days'] ?? 0;
    $insights[] = sprintf(
        'Network revenue (last 7 days): %s. Orders: %d.',
        number_format($rev, 0, '.', ' '),
        $orders
    );

    $conv = $overview['average_checkout_conversion'] ?? 0;
    $insights[] = sprintf('Average checkout conversion across network: %s%%.', $conv);

    $ret = $overview['average_return_rate'] ?? 0;
    $insights[] = sprintf('Average return rate across network: %s%%.', $ret);

    $aov = $overview['average_order_network'] ?? 0;
    $insights[] = sprintf('Average order value (network): %s.', number_format($aov, 2, '.', ' '));

    $totalRest = $overview['total_restaurants'] ?? 0;
    $activeRest = $overview['active_restaurants_last_7_days'] ?? 0;
    $insights[] = sprintf('Total restaurants: %d. Active in last 7 days: %d.', $totalRest, $activeRest);

    return $insights;
}
