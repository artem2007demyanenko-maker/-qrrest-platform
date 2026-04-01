<?php
/**
 * Feature flags for growth experiments. Tenant-safe: always scoped by restaurant_id.
 * For feature_flag experiments, variant B = enabled, variant A = control.
 */

/**
 * Check if a feature flag is enabled for a restaurant.
 * 1. Find running feature_flag experiment with matching flag_key (or name).
 * 2. If restaurant is assigned to variant B, return true; otherwise false.
 *
 * @param string $flag     Flag key (e.g. 'new_upsell_engine')
 * @param int    $restaurantId
 * @return bool
 */
function is_feature_enabled(string $flag, int $restaurantId): bool
{
    $restaurantId = (int) $restaurantId;
    if ($restaurantId <= 0) {
        return false;
    }

    if (function_exists('is_demo_mode') && is_demo_mode()) {
        return false;
    }

    if (!function_exists('db') || !function_exists('db_table_exists')) {
        return false;
    }
    if (!db_table_exists('growth_experiments') || !db_table_exists('experiment_assignments')) {
        return false;
    }

    try {
        $pdo = db();
        $stmt = $pdo->prepare("
            SELECT e.id
            FROM growth_experiments e
            WHERE e.type = 'feature_flag'
              AND e.status = 'running'
              AND (e.flag_key = :flag OR e.name = :flag2)
            LIMIT 1
        ");
        $stmt->execute(['flag' => $flag, 'flag2' => $flag]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return false;
        }
        $experimentId = (int) $row['id'];

        $stmt = $pdo->prepare("
            SELECT variant FROM experiment_assignments
            WHERE experiment_id = :eid AND restaurant_id = :rid
            LIMIT 1
        ");
        $stmt->execute(['eid' => $experimentId, 'rid' => $restaurantId]);
        $ass = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$ass) {
            return false;
        }
        return strtoupper((string) $ass['variant']) === 'B';
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('feature_flags is_feature_enabled ' . $e->getMessage());
        }
        return false;
    }
}
