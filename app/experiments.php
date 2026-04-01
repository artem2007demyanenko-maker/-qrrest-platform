<?php
/**
 * Growth experiments: assignment, metrics, results. Tenant-safe; all queries filter by restaurant_id.
 */

/**
 * Whether this restaurant is in the experiment target (by target_percentage). Deterministic.
 *
 * @param int $restaurantId
 * @param int $experimentId
 * @param int $targetPercentage 0-100
 * @return bool
 */
function experiment_restaurant_in_target(int $restaurantId, int $experimentId, int $targetPercentage): bool
{
    if ($targetPercentage >= 100) {
        return true;
    }
    if ($targetPercentage <= 0) {
        return false;
    }
    $hash = crc32((string) $restaurantId . '_' . (string) $experimentId);
    $bucket = (int) (abs($hash) % 100);
    return $bucket < $targetPercentage;
}

/**
 * Assign a restaurant to an experiment (variant A or B). Assigns only once.
 *
 * @param int $restaurantId
 * @param int $experimentId
 * @return bool true if assigned (or already assigned), false on error
 */
function assign_restaurant_to_experiment(int $restaurantId, int $experimentId): bool
{
    $restaurantId = (int) $restaurantId;
    $experimentId = (int) $experimentId;
    if ($restaurantId <= 0 || $experimentId <= 0) {
        return false;
    }

    if (function_exists('is_demo_mode') && is_demo_mode()) {
        return true;
    }

    if (!function_exists('db') || !function_exists('db_table_exists')) {
        return false;
    }
    if (!db_table_exists('growth_experiments') || !db_table_exists('experiment_assignments')) {
        return false;
    }

    try {
        $pdo = db();
        $stmt = $pdo->prepare("SELECT id FROM experiment_assignments WHERE experiment_id = ? AND restaurant_id = ? LIMIT 1");
        $stmt->execute([$experimentId, $restaurantId]);
        if ($stmt->fetch()) {
            return true;
        }

        $variant = (mt_rand(0, 1) === 0) ? 'A' : 'B';
        $stmt = $pdo->prepare("INSERT INTO experiment_assignments (experiment_id, restaurant_id, variant) VALUES (?, ?, ?)");
        $stmt->execute([$experimentId, $restaurantId, $variant]);
        return true;
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('experiments assign_restaurant_to_experiment ' . $e->getMessage());
        }
        return false;
    }
}

/**
 * Get the variant (A or B) for a restaurant in an experiment.
 *
 * @param int $restaurantId
 * @param int $experimentId
 * @return string 'A' or 'B', or '' if not assigned
 */
function get_restaurant_variant(int $restaurantId, int $experimentId): string
{
    $restaurantId = (int) $restaurantId;
    $experimentId = (int) $experimentId;
    if ($restaurantId <= 0 || $experimentId <= 0) {
        return '';
    }

    if (function_exists('is_demo_mode') && is_demo_mode()) {
        return 'A';
    }

    if (!function_exists('db') || !function_exists('db_table_exists') || !db_table_exists('experiment_assignments')) {
        return '';
    }

    try {
        $pdo = db();
        $stmt = $pdo->prepare("SELECT variant FROM experiment_assignments WHERE experiment_id = ? AND restaurant_id = ? LIMIT 1");
        $stmt->execute([$experimentId, $restaurantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? strtoupper((string) $row['variant']) : '';
    } catch (Throwable $e) {
        return '';
    }
}

/**
 * Record experiment metrics for a restaurant (orders, revenue, conversion) for today.
 * Sources: orders table, checkout_events. One row per (experiment, restaurant, date).
 *
 * @param int $restaurantId
 * @param int $experimentId
 * @return bool
 */
function record_experiment_metric(int $restaurantId, int $experimentId): bool
{
    $restaurantId = (int) $restaurantId;
    $experimentId = (int) $experimentId;
    if ($restaurantId <= 0 || $experimentId <= 0) {
        return false;
    }

    if (function_exists('is_demo_mode') && is_demo_mode()) {
        return true;
    }

    if (!function_exists('db') || !function_exists('db_table_exists')) {
        return false;
    }
    if (!db_table_exists('experiment_metrics') || !db_table_exists('experiment_assignments')) {
        return false;
    }

    try {
        $pdo = db();
        $today = date('Y-m-d');

        $orders = 0;
        $revenue = 0.0;
        $revenueCol = (function_exists('db_column_exists') && db_column_exists('orders', 'total_amount')) ? 'total_amount' : 'total_price';
        if (db_table_exists('orders')) {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) AS cnt, COALESCE(SUM(CASE WHEN payment_status = 'paid' AND (order_status IS NULL OR order_status <> 'canceled') THEN {$revenueCol} ELSE 0 END), 0) AS rev
                FROM orders
                WHERE restaurant_id = ? AND DATE(created_at) = ?
            ");
            $stmt->execute([$restaurantId, $today]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $orders = (int) ($row['cnt'] ?? 0);
            $revenue = (float) ($row['rev'] ?? 0);
        }

        $conversion = 0.0;
        if (db_table_exists('checkout_events')) {
            $stmt = $pdo->prepare("
                SELECT
                  SUM(CASE WHEN event_type = 'started_checkout' THEN 1 ELSE 0 END) AS started,
                  SUM(CASE WHEN event_type = 'completed_checkout' THEN 1 ELSE 0 END) AS completed
                FROM checkout_events
                WHERE restaurant_id = ? AND DATE(created_at) = ?
            ");
            $stmt->execute([$restaurantId, $today]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $started = (int) ($row['started'] ?? 0);
            $completed = (int) ($row['completed'] ?? 0);
            $conversion = $started > 0 ? round(100.0 * $completed / $started, 2) : 0.0;
        }

        $stmt = $pdo->prepare("
            INSERT INTO experiment_metrics (experiment_id, restaurant_id, orders, revenue, conversion, recorded_at)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE orders = VALUES(orders), revenue = VALUES(revenue), conversion = VALUES(conversion)
        ");
        $stmt->execute([$experimentId, $restaurantId, $orders, $revenue, $conversion, $today]);
        return true;
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('experiments record_experiment_metric ' . $e->getMessage());
        }
        return false;
    }
}

/**
 * Get aggregated results for an experiment. Admin-only semantics: call from project-admin only.
 *
 * @param int $experimentId
 * @return array{variant_a_restaurants: int, variant_b_restaurants: int, variant_a_revenue: float, variant_b_revenue: float, variant_a_conversion: float, variant_b_conversion: float, winner: string}
 */
function get_experiment_results(int $experimentId): array
{
    $default = [
        'variant_a_restaurants' => 0,
        'variant_b_restaurants' => 0,
        'variant_a_revenue' => 0.0,
        'variant_b_revenue' => 0.0,
        'variant_a_conversion' => 0.0,
        'variant_b_conversion' => 0.0,
        'winner' => '',
    ];

    if (function_exists('is_demo_mode') && is_demo_mode()) {
        return [
            'variant_a_restaurants' => 5,
            'variant_b_restaurants' => 5,
            'variant_a_revenue' => 125000.0,
            'variant_b_revenue' => 142000.0,
            'variant_a_conversion' => 62.0,
            'variant_b_conversion' => 71.0,
            'winner' => 'B',
        ];
    }

    if (!function_exists('db') || !function_exists('db_table_exists')) {
        return $default;
    }
    if (!db_table_exists('experiment_assignments') || !db_table_exists('experiment_metrics')) {
        return $default;
    }

    $experimentId = (int) $experimentId;
    if ($experimentId <= 0) {
        return $default;
    }

    try {
        $pdo = db();

        $stmt = $pdo->prepare("
            SELECT variant, COUNT(DISTINCT restaurant_id) AS cnt
            FROM experiment_assignments
            WHERE experiment_id = ?
            GROUP BY variant
        ");
        $stmt->execute([$experimentId]);
        $variantA = 0;
        $variantB = 0;
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $v = strtoupper((string) $row['variant']);
            if ($v === 'A') {
                $variantA = (int) $row['cnt'];
            } elseif ($v === 'B') {
                $variantB = (int) $row['cnt'];
            }
        }

        $stmt = $pdo->prepare("
            SELECT ea.variant,
                   COALESCE(SUM(em.revenue), 0) AS total_revenue,
                   COALESCE(AVG(em.conversion), 0) AS avg_conversion
            FROM experiment_assignments ea
            LEFT JOIN experiment_metrics em ON em.experiment_id = ea.experiment_id AND em.restaurant_id = ea.restaurant_id
            WHERE ea.experiment_id = ?
            GROUP BY ea.variant
        ");
        $stmt->execute([$experimentId]);
        $revA = 0.0;
        $revB = 0.0;
        $convA = 0.0;
        $convB = 0.0;
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $v = strtoupper((string) $row['variant']);
            if ($v === 'A') {
                $revA = (float) $row['total_revenue'];
                $convA = (float) $row['avg_conversion'];
            } elseif ($v === 'B') {
                $revB = (float) $row['total_revenue'];
                $convB = (float) $row['avg_conversion'];
            }
        }

        $winner = '';
        $diff = abs($convB - $convA);
        if ($diff > 5.0) {
            $winner = $convB > $convA ? 'B' : 'A';
        }

        return [
            'variant_a_restaurants' => $variantA,
            'variant_b_restaurants' => $variantB,
            'variant_a_revenue' => round($revA, 2),
            'variant_b_revenue' => round($revB, 2),
            'variant_a_conversion' => round($convA, 2),
            'variant_b_conversion' => round($convB, 2),
            'winner' => $winner,
        ];
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('experiments get_experiment_results ' . $e->getMessage());
        }
        return $default;
    }
}

/**
 * List running experiments (for assignment trigger). Admin/context may call from dashboard.
 *
 * @return array<int, array{id: int, name: string, type: string, target_percentage: int}>
 */
function get_running_experiments(): array
{
    if (function_exists('is_demo_mode') && is_demo_mode()) {
        return [];
    }

    if (!function_exists('db') || !function_exists('db_table_exists') || !db_table_exists('growth_experiments')) {
        return [];
    }

    try {
        $pdo = db();
        $stmt = $pdo->query("SELECT id, name, type, target_percentage FROM growth_experiments WHERE status = 'running' ORDER BY id ASC");
        $list = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $list[] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'type' => (string) $row['type'],
                'target_percentage' => (int) $row['target_percentage'],
            ];
        }
        return $list;
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Create a new experiment. Admin only.
 *
 * @param array{name: string, description?: string, type: string, target_percentage?: int, flag_key?: string} $data
 * @return int experiment id or 0 on failure
 */
function create_experiment(array $data): int
{
    if (function_exists('is_demo_mode') && is_demo_mode()) {
        return 0;
    }
    if (!function_exists('db') || !function_exists('db_table_exists') || !db_table_exists('growth_experiments')) {
        return 0;
    }
    $name = trim((string) ($data['name'] ?? ''));
    $type = in_array($data['type'] ?? '', ['ab_test', 'feature_flag'], true) ? $data['type'] : 'ab_test';
    if ($name === '') {
        return 0;
    }
    try {
        $pdo = db();
        $stmt = $pdo->prepare("
            INSERT INTO growth_experiments (name, description, flag_key, type, status, target_percentage)
            VALUES (?, ?, ?, ?, 'draft', ?)
        ");
        $desc = trim((string) ($data['description'] ?? ''));
        $flagKey = $type === 'feature_flag' ? trim((string) ($data['flag_key'] ?? $name)) : null;
        $target = (int) ($data['target_percentage'] ?? 100);
        $target = max(1, min(100, $target));
        $stmt->execute([$name, $desc, $flagKey, $type, $target]);
        return (int) $pdo->lastInsertId();
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('experiments create_experiment ' . $e->getMessage());
        }
        return 0;
    }
}

/**
 * Update experiment status. Admin only.
 *
 * @param int    $experimentId
 * @param string $status draft | running | paused | completed
 * @return bool
 */
function update_experiment_status(int $experimentId, string $status): bool
{
    if (function_exists('is_demo_mode') && is_demo_mode()) {
        return true;
    }
    if (!in_array($status, ['draft', 'running', 'paused', 'completed'], true)) {
        return false;
    }
    if (!function_exists('db') || !function_exists('db_table_exists') || !db_table_exists('growth_experiments')) {
        return false;
    }
    try {
        $pdo = db();
        $startedAt = $status === 'running' ? date('Y-m-d H:i:s') : null;
        if ($startedAt !== null) {
            $stmt = $pdo->prepare("UPDATE growth_experiments SET status = ?, started_at = COALESCE(started_at, ?) WHERE id = ?");
            $stmt->execute([$status, $startedAt, $experimentId]);
        } else {
            $stmt = $pdo->prepare("UPDATE growth_experiments SET status = ? WHERE id = ?");
            $stmt->execute([$status, $experimentId]);
        }
        return $stmt->rowCount() > 0;
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('experiments update_experiment_status ' . $e->getMessage());
        }
        return false;
    }
}

/**
 * List all experiments for admin dashboard.
 *
 * @return array<int, array{id: int, name: string, description: string, type: string, status: string, target_percentage: int, created_at: string, started_at: string|null}>
 */
function get_experiments_list(): array
{
    if (function_exists('is_demo_mode') && is_demo_mode()) {
        return [
            ['id' => 1, 'name' => 'New upsell engine', 'description' => 'Test new algorithm', 'type' => 'feature_flag', 'status' => 'running', 'target_percentage' => 50, 'created_at' => date('Y-m-d H:i:s'), 'started_at' => date('Y-m-d H:i:s')],
        ];
    }

    if (!function_exists('db') || !function_exists('db_table_exists') || !db_table_exists('growth_experiments')) {
        return [];
    }

    try {
        $pdo = db();
        $stmt = $pdo->query("SELECT id, name, description, type, status, target_percentage, created_at, started_at FROM growth_experiments ORDER BY id DESC");
        $list = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $list[] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'description' => (string) ($row['description'] ?? ''),
                'type' => (string) $row['type'],
                'status' => (string) $row['status'],
                'target_percentage' => (int) $row['target_percentage'],
                'created_at' => (string) ($row['created_at'] ?? ''),
                'started_at' => isset($row['started_at']) && $row['started_at'] !== null ? (string) $row['started_at'] : null,
            ];
        }
        return $list;
    } catch (Throwable $e) {
        return [];
    }
}
