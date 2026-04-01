<?php
/**
 * Revenue stats for restaurant: orders, revenue, upsell, CRM return visits.
 */

/**
 * @param int $restaurantId
 * @param int $days Number of days to look back (created_at >= now - days)
 * @return array{total_orders:int, total_revenue:float, avg_check:float, upsell_revenue:float, crm_return_visits:int, orders_per_day:array<string,int>}
 */
function revenue_stats_get(int $restaurantId, int $days = 30): array
{
    $pdo = db();
    $since = date('Y-m-d 00:00:00', strtotime("-{$days} days"));

    // Read path must be pure: do NOT process CRM outbox on page load.

    $orderFilter = "
        o.restaurant_id = ?
        AND o.created_at >= ?
        AND o.payment_status = 'paid'
        AND o.order_status <> 'canceled'
    ";

    // total_orders, total_revenue
    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total_orders,
            COALESCE(SUM(o.total_price), 0) AS total_revenue
        FROM orders o
        WHERE {$orderFilter}
    ");
    $stmt->execute([$restaurantId, $since]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $totalOrders = (int)($row['total_orders'] ?? 0);
    $totalRevenue = (float)($row['total_revenue'] ?? 0);
    $avgCheck = $totalOrders > 0 ? $totalRevenue / $totalOrders : 0.0;

    // upsell_revenue: optional (table upsell_events may not exist)
    $upsellRevenue = 0.0;
    try {
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(x.price * x.qty), 0) AS upsell_revenue
            FROM (
                SELECT DISTINCT
                    oi.order_id,
                    oi.menu_item_id,
                    oi.price,
                    COALESCE(oi.quantity, oi.qty, 0) AS qty
                FROM order_items oi
                INNER JOIN orders o ON o.id = oi.order_id
                INNER JOIN (
                    SELECT DISTINCT order_id, upsell_item_id
                    FROM upsell_events
                ) ue ON ue.order_id = oi.order_id AND ue.upsell_item_id = oi.menu_item_id
                WHERE o.restaurant_id = ? AND o.created_at >= ?
                  AND o.payment_status = 'paid'
                  AND o.order_status <> 'canceled'
            ) x
            WHERE x.qty > 0
        ");
        $stmt->execute([$restaurantId, $since]);
        $upsellRevenue = (float)($stmt->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        $upsellRevenue = 0.0;
    }

    // crm_return_visits: guest returned after at least one processed CRM message.
    $crmReturnVisits = 0;
    try {
        $sentExpr = 'o.updated_at';
        if (function_exists('db_column_exists')) {
            $parts = [];
            if (db_column_exists('crm_outbox', 'sent_at')) {
                $parts[] = 'o.sent_at';
            }
            if (db_column_exists('crm_outbox', 'processed_at')) {
                $parts[] = 'o.processed_at';
            }
            $parts[] = 'o.updated_at';
            $parts[] = 'o.created_at';
            $sentExpr = 'COALESCE(' . implode(', ', $parts) . ')';
        }
        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT v.id) AS cnt
            FROM crm_visits v
            WHERE v.restaurant_id = ?
              AND v.visited_at >= ?
              AND EXISTS (
                  SELECT 1
                  FROM crm_outbox o
                  WHERE o.restaurant_id = v.restaurant_id
                    AND o.guest_id = v.guest_id
                    AND o.status = 'processed'
                    AND {$sentExpr} <= v.visited_at
              )
        ");
        $stmt->execute([$restaurantId, $since]);
        $crmReturnVisits = (int)($stmt->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        $crmReturnVisits = 0;
    }

    // orders_per_day for last 7 days (for chart)
    $chartSince = date('Y-m-d 00:00:00', strtotime('-6 days'));
    $stmt = $pdo->prepare("
        SELECT DATE(o.created_at) AS d, COUNT(*) AS cnt
        FROM orders o
        WHERE o.restaurant_id = ? AND o.created_at >= ?
          AND o.payment_status = 'paid'
          AND o.order_status <> 'canceled'
        GROUP BY DATE(o.created_at)
        ORDER BY d
    ");
    $stmt->execute([$restaurantId, $chartSince]);
    $ordersPerDay = [];
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $ordersPerDay[$r['d']] = (int)$r['cnt'];
    }
    for ($i = 6; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-{$i} days"));
        if (!isset($ordersPerDay[$d])) {
            $ordersPerDay[$d] = 0;
        }
    }
    ksort($ordersPerDay);
    $ordersPerDay = array_slice($ordersPerDay, -7, 7, true);

    return [
        'total_orders'       => $totalOrders,
        'total_revenue'     => $totalRevenue,
        'avg_check'          => $avgCheck,
        'upsell_revenue'     => $upsellRevenue,
        'crm_return_visits'  => $crmReturnVisits,
        'orders_per_day'    => $ordersPerDay,
    ];
}
