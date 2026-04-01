<?php
/**
 * Network benchmark helpers: compare a restaurant to anonymized network averages.
 *
 * Privacy model:
 * - Only aggregated metrics across restaurants.
 * - No restaurant names, IDs, or per-restaurant rows exposed.
 * - Minimum cohort size: at least 5 restaurants with orders in the window.
 * - No writes.
 */

/**
 * Compute basic metrics for a single restaurant over the last 30 days.
 *
 * @return array{
 *   average_order_value: ?float,
 *   orders_per_day: ?float,
 *   revenue_per_table: ?float,
 *   repeat_guest_rate: ?float,
 *   average_items_per_order: ?float
 * }
 */
function _nb_restaurant_metrics(int $restaurantId): array
{
    $restaurantId = (int) $restaurantId;
    $metrics = [
        'average_order_value'    => null,
        'orders_per_day'         => null,
        'revenue_per_table'      => null,
        'repeat_guest_rate'      => null,
        'average_items_per_order'=> null,
    ];

    if ($restaurantId <= 0) {
        return $metrics;
    }
    if (!function_exists('db')) {
        return $metrics;
    }
    if (function_exists('db_table_exists') && !db_table_exists('orders')) {
        return $metrics;
    }

    try {
        $pdo = db();
        $since = date('Y-m-d 00:00:00', strtotime('-30 days'));

        // Orders and revenue for this restaurant.
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) AS orders_count,
                COALESCE(SUM(total_price), 0) AS total_revenue,
                COUNT(DISTINCT table_id) AS tables_count
            FROM orders
            WHERE restaurant_id = :rid
              AND order_status <> 'canceled'
              AND payment_status = 'paid'
              AND created_at >= :since
        ");
        $stmt->execute(['rid' => $restaurantId, 'since' => $since]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $ordersCount   = (int)($row['orders_count'] ?? 0);
        $totalRevenue  = (float)($row['total_revenue'] ?? 0.0);
        $tablesCount   = (int)($row['tables_count'] ?? 0);
        $days = 30.0;

        if ($ordersCount > 0) {
            $metrics['average_order_value'] = round($totalRevenue / $ordersCount, 2);
            $metrics['orders_per_day']      = round($ordersCount / $days, 2);
        }
        if ($tablesCount > 0 && $totalRevenue > 0) {
            $metrics['revenue_per_table'] = round($totalRevenue / $tablesCount, 2);
        }

        // Repeat guest rate from crm_guests, if available.
        if (function_exists('db_table_exists') && db_table_exists('crm_guests')) {
            $stmt = $pdo->prepare("
                SELECT 
                    SUM(CASE WHEN visits_count >= 1 THEN 1 ELSE 0 END) AS total_guests,
                    SUM(CASE WHEN visits_count > 1 THEN 1 ELSE 0 END) AS returning_guests
                FROM crm_guests
                WHERE restaurant_id = ?
            ");
            $stmt->execute([$restaurantId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $totalGuests    = (int)($row['total_guests'] ?? 0);
            $returningGuests= (int)($row['returning_guests'] ?? 0);
            if ($totalGuests > 0) {
                $metrics['repeat_guest_rate'] = round(100.0 * $returningGuests / $totalGuests, 1);
            }
        }

        // Average items per order from order_items, if available.
        if (function_exists('db_table_exists') && db_table_exists('order_items')) {
            $stmt = $pdo->prepare("
                SELECT 
                    COALESCE(SUM(oi.quantity), 0) AS total_items,
                    COUNT(DISTINCT o.id) AS orders_count
                FROM order_items oi
                INNER JOIN orders o ON o.id = oi.order_id
                WHERE o.restaurant_id = :rid
                  AND o.order_status <> 'canceled'
                  AND o.payment_status = 'paid'
                  AND o.created_at >= :since
            ");
            $stmt->execute(['rid' => $restaurantId, 'since' => $since]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $itemsTotal   = (float)($row['total_items'] ?? 0.0);
            $itemsOrders  = (int)($row['orders_count'] ?? 0);
            if ($itemsOrders > 0 && $itemsTotal > 0) {
                $metrics['average_items_per_order'] = round($itemsTotal / $itemsOrders, 2);
            }
        }
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('network_benchmark restaurant_metrics ' . $e->getMessage());
        }
    }

    return $metrics;
}

/**
 * Get network-wide average metrics (aggregated, anonymous).
 *
 * Privacy: aggregates across all restaurants. No per-restaurant rows or identifiers.
 *
 * @return array{
 *   available: bool,
 *   restaurants_count: int,
 *   average_order_value: ?float,
 *   orders_per_day: ?float,
 *   revenue_per_table: ?float,
 *   repeat_guest_rate: ?float,
 *   average_items_per_order: ?float,
 *   reason: string
 * }
 */
function get_network_average_metrics(): array
{
    if (function_exists('is_demo_mode') && is_demo_mode()) {
        return [
            'available'              => true,
            'restaurants_count'      => 20,
            'average_order_value'    => 790.0,
            'orders_per_day'         => 14.0,
            'revenue_per_table'      => 5200.0,
            'repeat_guest_rate'      => 19.0,
            'average_items_per_order'=> 2.3,
            'reason'                 => '',
        ];
    }

    $result = [
        'available'              => false,
        'restaurants_count'      => 0,
        'average_order_value'    => null,
        'orders_per_day'         => null,
        'revenue_per_table'      => null,
        'repeat_guest_rate'      => null,
        'average_items_per_order'=> null,
        'reason'                 => 'Not enough benchmark data yet',
    ];

    if (!function_exists('db')) {
        return $result;
    }
    if (function_exists('db_table_exists') && !db_table_exists('orders')) {
        return $result;
    }

    try {
        $pdo = db();
        $since = date('Y-m-d 00:00:00', strtotime('-30 days'));

        // Count restaurants with activity and compute AOV / orders per day and revenue per table.
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(DISTINCT restaurant_id) AS restaurants_count,
                COALESCE(SUM(total_price), 0) AS total_revenue,
                COUNT(*) AS total_orders,
                COUNT(DISTINCT CONCAT(restaurant_id, ':', COALESCE(table_id, 0))) AS tables_slots
            FROM orders
            WHERE order_status <> 'canceled'
              AND payment_status = 'paid'
              AND created_at >= :since
        ");
        $stmt->execute(['since' => $since]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $restaurantsCount = (int)($row['restaurants_count'] ?? 0);
        $totalRevenue     = (float)($row['total_revenue'] ?? 0.0);
        $totalOrders      = (int)($row['total_orders'] ?? 0);
        $tablesSlots      = (int)($row['tables_slots'] ?? 0);

        $result['restaurants_count'] = $restaurantsCount;

        // Enforce minimum cohort size.
        if ($restaurantsCount < 5) {
            return $result;
        }

        $days = 30.0;
        if ($totalOrders > 0) {
            $result['average_order_value'] = round($totalRevenue / $totalOrders, 2);
            $result['orders_per_day']      = round($totalOrders / $days, 2);
        }
        if ($tablesSlots > 0 && $totalRevenue > 0) {
            $result['revenue_per_table'] = round($totalRevenue / $tablesSlots, 2);
        }

        // Network repeat guest rate from crm_guests, if available.
        if (function_exists('db_table_exists') && db_table_exists('crm_guests')) {
            $stmt = $pdo->query("
                SELECT 
                    SUM(CASE WHEN visits_count >= 1 THEN 1 ELSE 0 END) AS total_guests,
                    SUM(CASE WHEN visits_count > 1 THEN 1 ELSE 0 END) AS returning_guests
                FROM crm_guests
            ");
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $totalGuests    = (int)($row['total_guests'] ?? 0);
            $returningGuests= (int)($row['returning_guests'] ?? 0);
            if ($totalGuests > 0) {
                $result['repeat_guest_rate'] = round(100.0 * $returningGuests / $totalGuests, 1);
            }
        }

        // Network average items per order from order_items, if available.
        if (function_exists('db_table_exists') && db_table_exists('order_items')) {
            $stmt = $pdo->prepare("
                SELECT 
                    COALESCE(SUM(oi.quantity), 0) AS total_items,
                    COUNT(DISTINCT o.id) AS orders_count
                FROM order_items oi
                INNER JOIN orders o ON o.id = oi.order_id
                WHERE o.order_status <> 'canceled'
                  AND o.payment_status = 'paid'
                  AND o.created_at >= :since
            ");
            $stmt->execute(['since' => $since]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $itemsTotal  = (float)($row['total_items'] ?? 0.0);
            $itemsOrders = (int)($row['orders_count'] ?? 0);
            if ($itemsOrders > 0 && $itemsTotal > 0) {
                $result['average_items_per_order'] = round($itemsTotal / $itemsOrders, 2);
            }
        }

        $result['available'] = true;
        $result['reason'] = '';
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('network_benchmark network_metrics ' . $e->getMessage());
        }
    }

    return $result;
}

/**
 * Combined restaurant vs network benchmark with comparison deltas.
 *
 * @return array{
 *   available: bool,
 *   restaurant: array,
 *   network: array,
 *   comparison: array,
 *   restaurants_count: int,
 *   reason: string
 * }
 */
function get_benchmark_comparison(int $restaurantId): array
{
    $restaurantId = (int) $restaurantId;

    if (function_exists('is_demo_mode') && is_demo_mode()) {
        return [
            'available' => true,
            'restaurant' => [
                'average_order_value'    => 850.0,
                'orders_per_day'         => 17.0,
                'revenue_per_table'      => 5400.0,
                'repeat_guest_rate'      => 24.0,
                'average_items_per_order'=> 2.4,
            ],
            'network' => [
                'average_order_value'    => 790.0,
                'orders_per_day'         => 14.0,
                'revenue_per_table'      => 5200.0,
                'repeat_guest_rate'      => 19.0,
                'average_items_per_order'=> 2.3,
            ],
            'comparison' => [
                'aov_delta'              => 60.0,
                'orders_delta'           => 3.0,
                'repeat_guest_delta'     => 5.0,
                'revenue_per_table_delta'=> 200.0,
                'items_per_order_delta'  => 0.1,
            ],
            'restaurants_count' => 20,
            'reason' => '',
        ];
    }

    $restaurantMetrics = _nb_restaurant_metrics($restaurantId);
    $networkMetrics    = get_network_average_metrics();

    $available = !empty($networkMetrics['available']);
    $reason    = $available ? '' : ($networkMetrics['reason'] ?? 'Not enough benchmark data yet');

    $comparison = [
        'aov_delta'               => null,
        'orders_delta'            => null,
        'repeat_guest_delta'      => null,
        'revenue_per_table_delta' => null,
        'items_per_order_delta'   => null,
    ];

    if ($available) {
        if ($restaurantMetrics['average_order_value'] !== null && $networkMetrics['average_order_value'] !== null) {
            $comparison['aov_delta'] = round($restaurantMetrics['average_order_value'] - $networkMetrics['average_order_value'], 2);
        }
        if ($restaurantMetrics['orders_per_day'] !== null && $networkMetrics['orders_per_day'] !== null) {
            $comparison['orders_delta'] = round($restaurantMetrics['orders_per_day'] - $networkMetrics['orders_per_day'], 2);
        }
        if ($restaurantMetrics['repeat_guest_rate'] !== null && $networkMetrics['repeat_guest_rate'] !== null) {
            $comparison['repeat_guest_delta'] = round($restaurantMetrics['repeat_guest_rate'] - $networkMetrics['repeat_guest_rate'], 1);
        }
        if ($restaurantMetrics['revenue_per_table'] !== null && $networkMetrics['revenue_per_table'] !== null) {
            $comparison['revenue_per_table_delta'] = round($restaurantMetrics['revenue_per_table'] - $networkMetrics['revenue_per_table'], 2);
        }
        if ($restaurantMetrics['average_items_per_order'] !== null && $networkMetrics['average_items_per_order'] !== null) {
            $comparison['items_per_order_delta'] = round($restaurantMetrics['average_items_per_order'] - $networkMetrics['average_items_per_order'], 2);
        }
    }

    return [
        'available'         => $available,
        'restaurant'        => $restaurantMetrics,
        'network'           => [
            'average_order_value'    => $networkMetrics['average_order_value'],
            'orders_per_day'         => $networkMetrics['orders_per_day'],
            'revenue_per_table'      => $networkMetrics['revenue_per_table'],
            'repeat_guest_rate'      => $networkMetrics['repeat_guest_rate'],
            'average_items_per_order'=> $networkMetrics['average_items_per_order'],
        ],
        'comparison'        => $comparison,
        'restaurants_count' => (int)($networkMetrics['restaurants_count'] ?? 0),
        'reason'            => $reason,
    ];
}

/**
 * Single canonical benchmark entry: flat shape for dashboard/revenue/copilot/success.
 *
 * @param int $restaurantId
 * @return array{available: bool, your_aov: ?float, peer_aov: ?float, your_orders_per_day: ?float, peer_orders_per_day: ?float, recommendation_text: string}
 */
function get_restaurant_benchmark(int $restaurantId): array
{
    $data = get_benchmark_comparison($restaurantId);
    $rest = $data['restaurant'] ?? [];
    $net  = $data['network'] ?? [];
    $comp = $data['comparison'] ?? [];
    $yourAov = isset($rest['average_order_value']) ? (float) $rest['average_order_value'] : null;
    $peerAov = isset($net['average_order_value']) ? (float) $net['average_order_value'] : null;
    $yourOrders = isset($rest['orders_per_day']) ? (float) $rest['orders_per_day'] : null;
    $peerOrders = isset($net['orders_per_day']) ? (float) $net['orders_per_day'] : null;
    $recommendation = '';
    if (!empty($data['available']) && $yourAov !== null && $peerAov !== null) {
        $recommendation = $yourAov < $peerAov
            ? 'Upsells and combos can help raise average check.'
            : 'You\'re in line with typical performance.';
    }
    return [
        'available'            => (bool) ($data['available'] ?? false),
        'your_aov'             => $yourAov,
        'peer_aov'             => $peerAov,
        'your_orders_per_day'  => $yourOrders,
        'peer_orders_per_day'  => $peerOrders,
        'recommendation_text'  => $recommendation,
    ];
}

