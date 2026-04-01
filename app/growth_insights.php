<?php
/**
 * Automated growth insights from orders, order_items, upsell_events, guest_visits.
 * Used by restaurant dashboard widget (top 3). All queries in try/catch for safe fallback.
 */

if (!function_exists('get_growth_insights')) {
    /**
     * Returns array of insight strings for the last $days. Order: most actionable first.
     * @return array<int, string>
     */
    function get_growth_insights(int $restaurantId, int $days = 7): array
    {
        $restaurantId = (int) $restaurantId;
        $days = max(1, min(90, $days));
        $insights = [];

        if ($restaurantId <= 0 || !function_exists('db')) {
            return [];
        }

        $pdo = db();
        $since = date('Y-m-d 00:00:00', strtotime("-{$days} days"));

        $orderFilter = "o.restaurant_id = :rid AND o.created_at >= :since AND o.payment_status = 'paid' AND (o.order_status IS NULL OR o.order_status <> 'canceled')";

        try {
            if (function_exists('db_table_exists') && (!db_table_exists('orders') || !db_table_exists('order_items'))) {
                return [];
            }

            // 1) Most popular item this week
            $stmt = $pdo->prepare("
                SELECT mi.name, COALESCE(SUM(oi.quantity), 0) AS qty
                FROM order_items oi
                INNER JOIN orders o ON o.id = oi.order_id AND o.restaurant_id = :rid
                    AND o.created_at >= :since
                    AND (o.order_status IS NULL OR o.order_status <> 'canceled')
                INNER JOIN menu_items mi ON mi.id = oi.menu_item_id AND mi.restaurant_id = :rid2
                GROUP BY mi.id, mi.name
                ORDER BY qty DESC
                LIMIT 1
            ");
            $stmt->execute(['rid' => $restaurantId, 'rid2' => $restaurantId, 'since' => $since]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && (int)($row['qty'] ?? 0) > 0) {
                $insights[] = 'Most popular item this week: ' . (string) $row['name'] . '.';
            }
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('growth_insights popular_item ' . $e->getMessage());
            }
        }

        try {
            if (function_exists('db_table_exists') && db_table_exists('menu_categories')) {
                $stmt = $pdo->prepare("
                    SELECT mc.name, COALESCE(SUM(oi.quantity), 0) AS qty
                    FROM order_items oi
                    INNER JOIN orders o ON o.id = oi.order_id AND o.restaurant_id = :rid
                        AND o.created_at >= :since
                        AND (o.order_status IS NULL OR o.order_status <> 'canceled')
                    INNER JOIN menu_items mi ON mi.id = oi.menu_item_id AND mi.restaurant_id = :rid2
                    INNER JOIN menu_categories mc ON mc.id = mi.category_id AND mc.restaurant_id = :rid3
                    GROUP BY mc.id, mc.name
                    ORDER BY qty DESC
                    LIMIT 1
                ");
                $stmt->execute(['rid' => $restaurantId, 'rid2' => $restaurantId, 'rid3' => $restaurantId, 'since' => $since]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row && (int)($row['qty'] ?? 0) > 0) {
                    $insights[] = 'Best selling category: ' . (string) $row['name'] . '.';
                }
            }
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('growth_insights best_category ' . $e->getMessage());
            }
        }

        try {
            if (function_exists('db_table_exists') && db_table_exists('upsell_events')) {
                $stmt = $pdo->prepare("
                    SELECT
                        AVG(CASE WHEN u.order_id IS NOT NULL THEN o.total_price END) AS avg_with_upsell,
                        AVG(CASE WHEN u.order_id IS NULL THEN o.total_price END) AS avg_without_upsell
                    FROM orders o
                    LEFT JOIN (
                        SELECT DISTINCT order_id FROM upsell_events
                        WHERE restaurant_id = :rid AND event = 'accepted_in_order' AND order_id IS NOT NULL
                    ) u ON u.order_id = o.id
                    WHERE o.restaurant_id = :rid2 AND o.created_at >= :since
                      AND (o.order_status IS NULL OR o.order_status <> 'canceled')
                      AND o.payment_status = 'paid'
                ");
                $stmt->execute(['rid' => $restaurantId, 'rid2' => $restaurantId, 'since' => $since]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $avgWith = $row ? (float)($row['avg_with_upsell'] ?? 0) : 0;
                $avgWithout = $row ? (float)($row['avg_without_upsell'] ?? 0) : 0;
                if ($avgWithout > 0 && $avgWith > 0 && $avgWith > $avgWithout) {
                    $pct = round(100 * ($avgWith - $avgWithout) / $avgWithout);
                    if ($pct > 0) {
                        $insights[] = 'Upsell adds +' . $pct . '% to average check.';
                    }
                }
            }
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('growth_insights upsell_pct ' . $e->getMessage());
            }
        }

        try {
            if (function_exists('db_table_exists') && db_table_exists('order_items') && db_table_exists('menu_items')) {
                $stmt = $pdo->prepare("
                    SELECT o.id
                    FROM orders o
                    WHERE o.restaurant_id = :rid AND o.created_at >= :since
                      AND (o.order_status IS NULL OR o.order_status <> 'canceled')
                    LIMIT 500
                ");
                $stmt->execute(['rid' => $restaurantId, 'since' => $since]);
                $orderIds = [];
                while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $orderIds[] = (int) $r['id'];
                }
                if (count($orderIds) >= 2) {
                    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
                    $stmt2 = $pdo->prepare("
                        SELECT oi.order_id, oi.menu_item_id, mi.name
                        FROM order_items oi
                        INNER JOIN menu_items mi ON mi.id = oi.menu_item_id AND mi.restaurant_id = ?
                        WHERE oi.order_id IN ($placeholders)
                    ");
                    $stmt2->execute(array_merge([$restaurantId], $orderIds));
                    $orderItems = [];
                    while ($r = $stmt2->fetch(PDO::FETCH_ASSOC)) {
                        $oid = (int) $r['order_id'];
                        if (!isset($orderItems[$oid])) {
                            $orderItems[$oid] = [];
                        }
                        $orderItems[$oid][] = ['id' => (int) $r['menu_item_id'], 'name' => (string) $r['name']];
                    }
                    $pairCount = [];
                    foreach ($orderItems as $items) {
                        $ids = array_unique(array_column($items, 'id'));
                        $names = [];
                        foreach ($items as $it) {
                            $names[$it['id']] = $it['name'];
                        }
                        sort($ids);
                        for ($i = 0; $i < count($ids); $i++) {
                            for ($j = $i + 1; $j < count($ids); $j++) {
                                $key = $ids[$i] . '_' . $ids[$j];
                                if (!isset($pairCount[$key])) {
                                    $pairCount[$key] = ['count' => 0, 'a' => $names[$ids[$i]], 'b' => $names[$ids[$j]]];
                                }
                                $pairCount[$key]['count']++;
                            }
                        }
                    }
                    if (!empty($pairCount)) {
                        uasort($pairCount, function ($a, $b) {
                            return ($b['count'] ?? 0) - ($a['count'] ?? 0);
                        });
                        $top = reset($pairCount);
                        if ($top && (int)($top['count'] ?? 0) >= 2) {
                            $insights[] = 'Guests who ordered ' . $top['a'] . ' often add ' . $top['b'] . '.';
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('growth_insights pairing ' . $e->getMessage());
            }
        }

        try {
            if (function_exists('db_table_exists') && db_table_exists('guest_visits')) {
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) AS cnt FROM guest_visits
                    WHERE restaurant_id = ? AND visited_at >= ?
                ");
                $stmt->execute([$restaurantId, $since]);
                $cnt = (int) $stmt->fetchColumn();
                if ($cnt > 0) {
                    $insights[] = $cnt . ' guest visit(s) recorded this period.';
                }
            }
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('growth_insights guest_visits ' . $e->getMessage());
            }
        }

        return array_slice(array_values($insights), 0, 10);
    }
}
