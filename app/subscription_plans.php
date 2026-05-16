<?php
/**
 * Restaurant subscription helpers (passive: no blocking, no payment logic).
 * Use get_restaurant_plan(), check_feature(), check_limit() for future gating.
 * When tables are missing, defaults to free plan and allows all (no errors).
 */

require_once __DIR__ . '/restaurant_full_access.php';

$GLOBALS['_plans_config'] = null;

function _subscription_plans_config(): array
{
    if ($GLOBALS['_plans_config'] !== null) {
        return $GLOBALS['_plans_config'];
    }
    $path = __DIR__ . '/plans_config.php';
    if (!is_file($path)) {
        $GLOBALS['_plans_config'] = [];
        return [];
    }
    $GLOBALS['_plans_config'] = require $path;
    return $GLOBALS['_plans_config'];
}

/**
 * Get restaurant's effective plan (free|growth|pro) and status.
 * If restaurant_subscriptions table or row missing, returns free/active.
 * Expired trials or inactive rows fall back to free/active.
 *
 * @param int $restaurantId
 * @return array{plan: string, status: string}
 */
function get_restaurant_plan(int $restaurantId): array
{
    $restaurantId = (int) $restaurantId;
    $default = ['plan' => 'free', 'status' => 'active'];

    if (function_exists('restaurant_has_full_access_override') && restaurant_has_full_access_override($restaurantId)) {
        return ['plan' => 'pro', 'status' => 'active'];
    }

    if (!function_exists('db') || !function_exists('db_table_exists')) {
        return $default;
    }
    if (!db_table_exists('restaurant_subscriptions')) {
        return $default;
    }

    try {
        $pdo = db();
        $stmt = $pdo->prepare("SELECT plan, status, expires_at FROM restaurant_subscriptions WHERE restaurant_id = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$restaurantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $plan = strtolower(trim((string) ($row['plan'] ?? 'free')));
            if (!in_array($plan, ['free', 'growth', 'pro'], true)) {
                $plan = 'free';
            }
            $status = (string) ($row['status'] ?? 'active');
            $expiresAt = $row['expires_at'] ?? null;

            // Treat expired trials or inactive rows as free.
            if ($status === 'trial') {
                if ($expiresAt !== null && $expiresAt !== '' && strtotime($expiresAt) !== false && strtotime($expiresAt) < time()) {
                    return $default;
                }
            } elseif ($status !== 'active') {
                return $default;
            }

            return [
                'plan'   => $plan,
                'status' => $status,
            ];
        }
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('subscription_plans get_restaurant_plan ' . $e->getMessage());
        }
    }
    return $default;
}

/**
 * Check if a feature is enabled for the restaurant's plan (passive: no block).
 * Features: crm_enabled, upsell_enabled, loyalty_enabled.
 *
 * @param int $restaurantId
 * @param string $feature e.g. 'crm_enabled', 'upsell_enabled', 'loyalty_enabled'
 * @return bool
 */
function check_feature(int $restaurantId, string $feature): bool
{
    if (function_exists('restaurant_has_full_access_override') && restaurant_has_full_access_override((int)$restaurantId)) {
        return true;
    }
    $planData = get_restaurant_plan($restaurantId);
    $planKey = $planData['plan'];
    $config = _subscription_plans_config();
    if (!isset($config[$planKey][$feature])) {
        return true; // unknown feature or missing config: allow (passive)
    }
    return (bool) $config[$planKey][$feature];
}

/**
 * Check if restaurant is within limit for a metric (passive: no block).
 * Returns true if under limit or unlimited; false if over limit.
 * If usage_metrics table or plan config missing, returns true (allow).
 *
 * @param int $restaurantId
 * @param string $metric e.g. 'orders' (maps to plan max_orders)
 * @return bool true = within limit or unlimited
 */
function check_limit(int $restaurantId, string $metric): bool
{
    $restaurantId = (int) $restaurantId;
    if (function_exists('restaurant_has_full_access_override') && restaurant_has_full_access_override($restaurantId)) {
        return true;
    }
    $planData = get_restaurant_plan($restaurantId);
    $config = _subscription_plans_config();
    $planKey = $planData['plan'];
    if (!isset($config[$planKey])) {
        return true;
    }

    $planConfig = $config[$planKey];
    $maxKey = null;
    if ($metric === 'orders') {
        $maxKey = 'max_orders';
    }
    if ($maxKey === null || !array_key_exists($maxKey, $planConfig)) {
        return true;
    }

    $max = $planConfig[$maxKey];
    if ($max === null) {
        return true; // unlimited
    }
    $max = (int) $max;

    if (!function_exists('db') || !function_exists('db_table_exists') || !db_table_exists('usage_metrics')) {
        return true; // no usage data: allow (passive)
    }

    try {
        $pdo = db();
        // Current month period for orders
        $periodStart = date('Y-m-01 00:00:00');
        $periodEnd = date('Y-m-t 23:59:59');
        $stmt = $pdo->prepare("SELECT value FROM usage_metrics WHERE restaurant_id = ? AND metric = ? AND period_start = ? LIMIT 1");
        $stmt->execute([$restaurantId, $metric, $periodStart]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $current = $row ? (int) $row['value'] : 0;
        return $current < $max;
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('subscription_plans check_limit ' . $e->getMessage());
        }
        return true;
    }
}

/**
 * Increment usage for a restaurant/metric in the current month (passive; no blocking).
 * Safe if usage_metrics table is missing. Does not throw.
 *
 * @param int $restaurantId
 * @param string $metric e.g. 'orders'
 * @return void
 */
function increment_usage(int $restaurantId, string $metric): void
{
    $restaurantId = (int) $restaurantId;
    $metric = trim($metric);
    if ($restaurantId <= 0 || $metric === '') {
        return;
    }
    if (!function_exists('db') || !function_exists('db_table_exists') || !db_table_exists('usage_metrics')) {
        return;
    }
    $periodStart = date('Y-m-01 00:00:00');
    $periodEnd = date('Y-m-t 23:59:59');

    try {
        $pdo = db();
        $stmt = $pdo->prepare("
            INSERT INTO usage_metrics (restaurant_id, metric, value, period_start, period_end)
            VALUES (:rest, :metric, 1, :period_start, :period_end)
            ON DUPLICATE KEY UPDATE value = value + 1, updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute([
            'rest'         => $restaurantId,
            'metric'       => $metric,
            'period_start' => $periodStart,
            'period_end'   => $periodEnd,
        ]);
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('subscription_plans increment_usage ' . $e->getMessage());
        }
    }
}

/**
 * Get usage as percentage of plan limit for a metric (0 = unlimited or no data).
 *
 * @param int $restaurantId
 * @param string $metric e.g. 'orders'
 * @return int 0–100+ (0 means unlimited or no limit configured)
 */
function get_usage_percent(int $restaurantId, string $metric): int
{
    $restaurantId = (int) $restaurantId;
    $planData = get_restaurant_plan($restaurantId);
    $config = _subscription_plans_config();
    $planKey = $planData['plan'];
    if (!isset($config[$planKey])) {
        return 0;
    }
    $planConfig = $config[$planKey];
    $maxKey = $metric === 'orders' ? 'max_orders' : null;
    if ($maxKey === null || !array_key_exists($maxKey, $planConfig)) {
        return 0;
    }
    $max = $planConfig[$maxKey];
    if ($max === null) {
        return 0; // unlimited
    }
    $max = (int) $max;
    if ($max <= 0) {
        return 0;
    }
    if (!function_exists('db') || !function_exists('db_table_exists') || !db_table_exists('usage_metrics')) {
        return 0;
    }
    try {
        $pdo = db();
        $periodStart = date('Y-m-01 00:00:00');
        $stmt = $pdo->prepare("SELECT value FROM usage_metrics WHERE restaurant_id = ? AND metric = ? AND period_start = ? LIMIT 1");
        $stmt->execute([$restaurantId, $metric, $periodStart]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $current = $row ? (int) $row['value'] : 0;
        return (int) round($current * 100.0 / $max);
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('subscription_plans get_usage_percent ' . $e->getMessage());
        }
        return 0;
    }
}

/**
 * Get a simple snapshot of this month's usage metrics for a restaurant.
 * Keys: orders, crm_messages, upsell_shown, upsell_accepted, loyalty_transactions.
 * Values are integers (0 if no row or errors).
 *
 * @param int $restaurantId
 * @return array{orders:int,crm_messages:int,upsell_shown:int,upsell_accepted:int,loyalty_transactions:int}
 */
function get_monthly_usage_snapshot(int $restaurantId): array
{
    $restaurantId = (int) $restaurantId;
    $out = [
        'orders'              => 0,
        'crm_messages'        => 0,
        'upsell_shown'        => 0,
        'upsell_accepted'     => 0,
        'loyalty_transactions'=> 0,
    ];
    if ($restaurantId <= 0 || !function_exists('db') || !function_exists('db_table_exists') || !db_table_exists('usage_metrics')) {
        return $out;
    }

    try {
        $pdo = db();
        $periodStart = date('Y-m-01 00:00:00');
        $metrics = array_keys($out);
        $in = "'" . implode("','", array_map('addslashes', $metrics)) . "'";
        $sql = "SELECT metric, value FROM usage_metrics WHERE restaurant_id = ? AND metric IN ($in) AND period_start = ?"; // phpcs:ignore
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$restaurantId, $periodStart]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $m = (string)($row['metric'] ?? '');
            $v = (int)($row['value'] ?? 0);
            if (array_key_exists($m, $out)) {
                $out[$m] = $v;
            }
        }
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('subscription_plans get_monthly_usage_snapshot ' . $e->getMessage());
        }
    }
    return $out;
}

/**
 * Describe current limit state for a metric (e.g. 'orders') for this month.
 * Read-only helper; does not enforce anything by itself.
 *
 * @param int $restaurantId
 * @param string $metric
 * @return array{allowed:bool,usage:int,limit:?int,percent:int,state:string}
 */
function get_limit_state(int $restaurantId, string $metric): array
{
    $restaurantId = (int) $restaurantId;
    $metric = trim($metric);
    $result = [
        'allowed' => true,
        'usage'   => 0,
        'limit'   => null,
        'percent' => 0,
        'state'   => 'unlimited',
    ];
    if ($restaurantId <= 0 || $metric === '') {
        return $result;
    }
    if (function_exists('restaurant_has_full_access_override') && restaurant_has_full_access_override($restaurantId)) {
        return $result;
    }

    $config = _subscription_plans_config();
    $planData = get_restaurant_plan($restaurantId);
    $planKey = $planData['plan'] ?? 'free';
    if (!isset($config[$planKey])) {
        return $result;
    }
    $planConfig = $config[$planKey];

    // Currently we only support explicit numeric limit for 'orders' metric via max_orders.
    $maxKey = $metric === 'orders' ? 'max_orders' : null;
    if ($maxKey === null || !array_key_exists($maxKey, $planConfig)) {
        return $result;
    }

    $limit = $planConfig[$maxKey];
    if ($limit === null) {
        // Unlimited for this plan.
        return $result;
    }

    $limit = (int) $limit;
    $result['limit'] = $limit;
    if ($limit <= 0) {
        $result['state'] = 'ok';
        return $result;
    }

    // Read usage from metrics table (safe defaults).
    $usage = 0;
    if (function_exists('db') && function_exists('db_table_exists') && db_table_exists('usage_metrics')) {
        try {
            $pdo = db();
            $periodStart = date('Y-m-01 00:00:00');
            $stmt = $pdo->prepare("SELECT value FROM usage_metrics WHERE restaurant_id = ? AND metric = ? AND period_start = ? LIMIT 1");
            $stmt->execute([$restaurantId, $metric, $periodStart]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $usage = $row ? (int)($row['value'] ?? 0) : 0;
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('subscription_plans get_limit_state ' . $e->getMessage());
            }
        }
    }

    $result['usage'] = $usage;
    $percent = $limit > 0 ? (int)round($usage * 100.0 / $limit) : 0;
    $result['percent'] = $percent;

    if ($usage === 0) {
        $result['state'] = 'ok';
    } elseif ($percent < 80) {
        $result['state'] = 'ok';
    } elseif ($percent < 100) {
        $result['state'] = 'warning';
    } else {
        $result['state'] = 'exceeded';
    }

    // Optional future hard enforcement: APP_HARD_ORDER_LIMIT=1 enables hard blocking for orders.
    $hardOrders = false;
    if ($metric === 'orders') {
        $hardOrders = (getenv('APP_HARD_ORDER_LIMIT') === '1');
    }
    $result['allowed'] = !$hardOrders || $result['state'] !== 'exceeded';

    return $result;
}
