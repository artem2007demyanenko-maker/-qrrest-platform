<?php
/**
 * Smart combo builder: suggest 2–3 item combos from order history.
 * Does not create combos; suggestions only. Graceful fallback.
 */

/**
 * Get combo suggestions (frequent 2–3 item bundles) from last 100 paid orders.
 * @param int $restaurantId
 * @return array{suggestions: array, order_count: int}
 */
function get_combo_suggestions(int $restaurantId): array
{
    $restaurantId = (int) $restaurantId;
    $minOrders = 20;
    $lastN = 100;
    $topN = 5;

    if (!function_exists('db')) {
        return ['suggestions' => [], 'order_count' => 0];
    }
    if (function_exists('db_table_exists') && (!db_table_exists('orders') || !db_table_exists('order_items') || !db_table_exists('menu_items'))) {
        return ['suggestions' => [], 'order_count' => 0];
    }

    try {
        $pdo = db();
        $stmt = $pdo->prepare("
            SELECT id FROM orders
            WHERE restaurant_id = :rest AND payment_status = 'paid' AND order_status <> 'canceled'
            ORDER BY created_at DESC
            LIMIT " . (int) $lastN
        );
        $stmt->execute(['rest' => $restaurantId]);
        $orderIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $orderCount = count($orderIds);
        if ($orderCount < $minOrders || $orderIds === []) {
            return ['suggestions' => [], 'order_count' => $orderCount];
        }

        $ph = implode(',', array_fill(0, count($orderIds), '?'));
        $stmt = $pdo->prepare("
            SELECT oi.order_id, oi.menu_item_id, mi.name
            FROM order_items oi
            INNER JOIN menu_items mi ON mi.id = oi.menu_item_id AND mi.restaurant_id = :rest
            WHERE oi.order_id IN ($ph) AND oi.menu_item_id IS NOT NULL AND oi.menu_item_id > 0
        ");
        $stmt->execute(array_merge(['rest' => $restaurantId], $orderIds));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $byOrder = [];
        $names = [];
        foreach ($rows as $r) {
            $oid = (int) $r['order_id'];
            $mid = (int) $r['menu_item_id'];
            if (!isset($byOrder[$oid])) {
                $byOrder[$oid] = [];
            }
            $byOrder[$oid][$mid] = (string) $r['name'];
            $names[$mid] = (string) $r['name'];
        }

        $bundleCount = [];
        foreach ($byOrder as $itemIds) {
            $ids = array_keys($itemIds);
            if (count($ids) < 2) {
                continue;
            }
            sort($ids);
            $key2 = count($ids) >= 2 ? implode(',', array_slice($ids, 0, 2)) : '';
            $key3 = count($ids) >= 3 ? implode(',', array_slice($ids, 0, 3)) : '';
            if ($key2) {
                $bundleCount[$key2] = ($bundleCount[$key2] ?? 0) + 1;
            }
            if ($key3) {
                $bundleCount[$key3] = ($bundleCount[$key3] ?? 0) + 1;
            }
        }

        arsort($bundleCount);
        $suggestions = [];
        $seen = [];
        foreach (array_slice($bundleCount, 0, 15) as $key => $cnt) {
            if (count($suggestions) >= $topN) {
                break;
            }
            $ids = array_map('intval', explode(',', $key));
            $sortKey = implode(',', $ids);
            if (isset($seen[$sortKey])) {
                continue;
            }
            $seen[$sortKey] = true;
            $items = [];
            foreach ($ids as $id) {
                $items[] = ['id' => $id, 'name' => $names[$id] ?? ('ID ' . $id)];
            }
            $label = implode(' + ', array_column($items, 'name'));
            $confidence = round($cnt / $orderCount, 2);
            $uplift = min(25, (int) round($confidence * 50));
            $suggestions[] = [
                'items' => $items,
                'label' => $label,
                'confidence' => $confidence,
                'estimated_uplift_text' => '+' . $uplift . '% average check potential',
            ];
        }
        return ['suggestions' => $suggestions, 'order_count' => $orderCount];
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('combo_builder ' . $e->getMessage());
        }
        return ['suggestions' => [], 'order_count' => 0];
    }
}
