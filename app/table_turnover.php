<?php
if (!function_exists('qr_public_sql_exclude_delivery') && file_exists(__DIR__ . '/qr_public_menu.php')) {
    require_once __DIR__ . '/qr_public_menu.php';
}
/**
 * Table turnover / performance: orders and revenue per table (proxy when no seated duration).
 */

/**
 * Get table performance (orders count, revenue per table) for last N days.
 * @param int $restaurantId
 * @param int $days default 7
 * @return array{tables: array, busiest_table: string, recommendation_text: string, has_data: bool}
 */
function get_table_turnover(int $restaurantId, int $days = 7): array
{
    $restaurantId = (int) $restaurantId;
    $days = max(1, min(90, $days));

    $result = ['tables' => [], 'busiest_table' => '', 'recommendation_text' => '', 'has_data' => false];

    if (!function_exists('db')) {
        return $result;
    }
    if (function_exists('db_table_exists') && (!db_table_exists('orders') || !db_table_exists('tables'))) {
        return $result;
    }

    try {
        $pdo = db();
        $since = date('Y-m-d 00:00:00', strtotime("-{$days} days"));
        $stmt = $pdo->prepare("
            SELECT t.id, t.name,
                   COUNT(o.id) AS orders_count,
                   COALESCE(SUM(o.total_price), 0) AS revenue
            FROM tables t
            LEFT JOIN orders o ON o.table_id = t.id AND o.restaurant_id = t.restaurant_id
                AND o.created_at >= :since AND o.order_status <> 'canceled'
            WHERE t.restaurant_id = :rid
            " . qr_public_sql_exclude_delivery($pdo, 't') . "
            GROUP BY t.id, t.name
            ORDER BY orders_count DESC, revenue DESC
        ");
        $stmt->execute(['rid' => $restaurantId, 'since' => $since]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $tables = [];
        foreach ($rows as $r) {
            $tables[] = [
                'id' => (int) $r['id'],
                'name' => (string) $r['name'],
                'orders_count' => (int) $r['orders_count'],
                'revenue' => (float) $r['revenue'],
            ];
        }
        $result['tables'] = $tables;
        $result['has_data'] = $tables !== [];

        if ($tables !== []) {
            $top = $tables[0];
            $result['busiest_table'] = $top['name'] . ' (' . $top['orders_count'] . ' orders)';
            $result['recommendation_text'] = 'High-traffic tables may need faster turnover or additional QR visibility.';
        } else {
            $result['recommendation_text'] = 'Orders per table will appear once you have orders.';
        }
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('table_turnover ' . $e->getMessage());
        }
    }
    return $result;
}
