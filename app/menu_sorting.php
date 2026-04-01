<?php
/**
 * Smart menu sorting: suggested order by popularity/performance.
 * Does not change menu; suggestion only. Respects sort_order if present.
 */

/**
 * Get suggested menu item order (by quantity sold, then name).
 * @param int $restaurantId
 * @return array{items: array<array{id:int, name:string, position:int}>, has_data: bool}
 */
function get_smart_menu_sorting(int $restaurantId): array
{
    $restaurantId = (int) $restaurantId;
    $result = ['items' => [], 'has_data' => false];

    if (!function_exists('db')) {
        return $result;
    }
    if (function_exists('db_table_exists') && (!db_table_exists('menu_items') || !db_table_exists('order_items') || !db_table_exists('orders'))) {
        return $result;
    }

    try {
        $pdo = db();
        $since = date('Y-m-d 00:00:00', strtotime('-30 days'));
        $stmt = $pdo->prepare("
            SELECT mi.id, mi.name, COALESCE(SUM(oi.quantity), 0) AS qty
            FROM menu_items mi
            LEFT JOIN order_items oi ON oi.menu_item_id = mi.id
            LEFT JOIN orders o ON o.id = oi.order_id AND o.restaurant_id = mi.restaurant_id
                AND o.created_at >= :since AND o.order_status <> 'canceled'
            WHERE mi.restaurant_id = :rid AND mi.available = 1
            GROUP BY mi.id, mi.name
            ORDER BY qty DESC, mi.name ASC
        ");
        $stmt->execute(['rid' => $restaurantId, 'since' => $since]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $position = 1;
        foreach ($rows as $r) {
            $result['items'][] = [
                'id' => (int) $r['id'],
                'name' => (string) $r['name'],
                'position' => $position++,
            ];
        }
        $result['has_data'] = $result['items'] !== [];
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('menu_sorting ' . $e->getMessage());
        }
    }
    return $result;
}
