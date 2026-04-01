<?php
/**
 * Menu performance insights: top sellers, low/no sales, recommendations.
 * Used by dashboard and revenue. Graceful fallback if insufficient data.
 */

/**
 * Get menu performance insights for the last N days.
 * @param int $restaurantId
 * @param int $days default 7
 * @return array{top:array, low:array, no_sales:array, insights:array<string>}
 */
function menu_performance_insights(int $restaurantId, int $days = 7): array
{
    $restaurantId = (int) $restaurantId;
    $days = max(1, min(90, $days));

    if (!function_exists('db')) {
        return ['top' => [], 'low' => [], 'no_sales' => [], 'insights' => []];
    }
    $pdo = db();

    $since = date('Y-m-d 00:00:00', strtotime("-{$days} days"));
    $result = ['top' => [], 'low' => [], 'no_sales' => [], 'insights' => []];

    try {
        if (function_exists('db_table_exists') && (!db_table_exists('orders') || !db_table_exists('order_items') || !db_table_exists('menu_items'))) {
            return $result;
        }

        $stmt = $pdo->prepare("
            SELECT mi.id, mi.name, COALESCE(SUM(oi.quantity), 0) AS qty
            FROM menu_items mi
            LEFT JOIN order_items oi ON oi.menu_item_id = mi.id
            LEFT JOIN orders o ON o.id = oi.order_id AND o.restaurant_id = mi.restaurant_id
                AND o.created_at >= :since
                AND o.order_status <> 'canceled'
            WHERE mi.restaurant_id = :rid AND mi.available = 1
            GROUP BY mi.id, mi.name
            ORDER BY qty DESC
        ");
        $stmt->execute(['rid' => $restaurantId, 'since' => $since]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $withSales = [];
        $noSales = [];
        foreach ($rows as $r) {
            $qty = (int) ($r['qty'] ?? 0);
            $item = ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'qty' => $qty];
            if ($qty > 0) {
                $withSales[] = $item;
            } else {
                $noSales[] = $item;
            }
        }

        $result['top'] = array_slice($withSales, 0, 5);
        $result['low'] = array_slice(array_reverse($withSales), 0, 3);
        $result['no_sales'] = array_slice($noSales, 0, 5);

        if (!empty($result['top'])) {
            $topName = $result['top'][0]['name'];
            $result['insights'][] = $topName . ' drives most orders.';
        }
        if (!empty($result['low']) && count($withSales) > 1) {
            $lowName = $result['low'][0]['name'];
            $result['insights'][] = 'Consider promoting ' . $lowName . '.';
        }
        if (!empty($result['no_sales'])) {
            $result['insights'][] = 'These items had no sales this period: ' . implode(', ', array_slice(array_column($result['no_sales'], 'name'), 0, 3)) . '.';
        }
        if (empty($result['insights'])) {
            $result['insights'][] = 'Add menu items and collect orders to see insights.';
        }
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('menu_performance_insights ' . $e->getMessage());
        }
    }

    return $result;
}
