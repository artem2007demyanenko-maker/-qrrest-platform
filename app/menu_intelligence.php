<?php
/**
 * Dynamic Menu Intelligence: actionable menu insights, read-only / recommendation-based.
 * Uses: orders, order_items, menu_items, menu_categories; optional upsell_events.
 * No auto-edit. Same-restaurant data only.
 */

if (file_exists(__DIR__ . '/cache.php')) {
    require_once __DIR__ . '/cache.php';
}

if (!function_exists('get_menu_intelligence')) {
    /**
     * Aggregated intelligence: insights (strings), opportunities, warnings.
     * @return array{insights: array<string>, opportunities: array, warnings: array}
     */
    function get_menu_intelligence(int $restaurantId): array
    {
        if (function_exists('is_demo_mode') && is_demo_mode()) {
            return _menu_intelligence_demo();
        }
        $restaurantId = (int)$restaurantId;
        $cacheKey = 'menu_intel:' . $restaurantId;
        if (function_exists('cache_get')) {
            $cached = cache_get($cacheKey);
            if (is_array($cached) && isset($cached['insights'], $cached['opportunities'], $cached['warnings'])) {
                return $cached;
            }
        }
        $opportunities = get_menu_opportunities($restaurantId);
        $warnings = get_menu_warnings($restaurantId);
        $insights = _menu_intelligence_build_insights($restaurantId, $opportunities, $warnings);
        $result = [
            'insights'      => array_slice($insights, 0, 10),
            'opportunities' => $opportunities,
            'warnings'      => $warnings,
        ];
        if (function_exists('cache_set')) {
            cache_set($cacheKey, $result, 600); // 10 min
        }
        return $result;
    }
}

if (!function_exists('get_menu_opportunities')) {
    /**
     * Structured recommendations: promote, add_photo, pair_with, move_higher, consider_combo, etc.
     * @return array{promote: array, add_photo: array, pair_with: array, move_higher: array, consider_combo: array}
     */
    function get_menu_opportunities(int $restaurantId): array
    {
        $restaurantId = (int) $restaurantId;
        $out = [
            'promote'       => [],
            'add_photo'     => [],
            'pair_with'     => [],
            'move_higher'   => [],
            'consider_combo' => [],
        ];
        if ($restaurantId <= 0 || !function_exists('db')) {
            return $out;
        }
        $pdo = db();
        $days = 14;
        $since = date('Y-m-d 00:00:00', strtotime("-{$days} days"));

        try {
            if (function_exists('db_table_exists') && (!db_table_exists('orders') || !db_table_exists('order_items') || !db_table_exists('menu_items'))) {
                return $out;
            }

            $rid = $restaurantId;
            $orderCond = "o.restaurant_id = :rid AND o.created_at >= :since AND (o.order_status IS NULL OR o.order_status <> 'canceled') AND o.payment_status = 'paid'";

            $stmt = $pdo->prepare("
                SELECT mi.id, mi.name, COALESCE(SUM(oi.quantity), 0) AS qty
                FROM menu_items mi
                LEFT JOIN order_items oi ON oi.menu_item_id = mi.id
                LEFT JOIN orders o ON o.id = oi.order_id AND o.restaurant_id = mi.restaurant_id AND o.created_at >= :since
                    AND (o.order_status IS NULL OR o.order_status <> 'canceled') AND o.payment_status = 'paid'
                WHERE mi.restaurant_id = :rid AND mi.available = 1
                GROUP BY mi.id, mi.name
                ORDER BY qty DESC
            ");
            $stmt->execute(['rid' => $rid, 'since' => $since]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $withSales = [];
            $noSales = [];
            foreach ($rows as $r) {
                $qty = (int)($r['qty'] ?? 0);
                $item = ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'qty' => $qty];
                if ($qty > 0) {
                    $withSales[] = $item;
                } else {
                    $noSales[] = $item;
                }
            }

            if (count($withSales) > 1) {
                $out['promote'][] = ['item_id' => $withSales[0]['id'], 'item_name' => $withSales[0]['name'], 'reason' => 'top_seller'];
            }
            if (count($withSales) > 2) {
                $low = $withSales[count($withSales) - 1];
                $out['move_higher'][] = ['item_id' => $low['id'], 'item_name' => $low['name'], 'reason' => 'low_visibility'];
            }

            $imgParts = [];
            if (function_exists('db_column_exists')) {
                if (db_column_exists('menu_items', 'image_url')) {
                    $imgParts[] = "TRIM(COALESCE(mi.image_url,'')) = ''";
                }
                if (db_column_exists('menu_items', 'image_path')) {
                    $imgParts[] = "TRIM(COALESCE(mi.image_path,'')) = ''";
                }
            }
            if (!empty($imgParts)) {
                $imgCond = implode(' AND ', $imgParts);
                $stmt = $pdo->prepare("
                    SELECT mi.id, mi.name FROM menu_items mi
                    WHERE mi.restaurant_id = :rid AND mi.available = 1 AND {$imgCond}
                    LIMIT 5
                ");
                $stmt->execute(['rid' => $rid]);
                while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $out['add_photo'][] = ['item_id' => (int)$r['id'], 'item_name' => (string)$r['name']];
                }
            }

            $orderIds = [];
            $stmt = $pdo->prepare("SELECT id FROM orders WHERE restaurant_id = :rid AND created_at >= :since AND (order_status IS NULL OR order_status <> 'canceled') AND payment_status = 'paid' ORDER BY id DESC LIMIT 150");
            $stmt->execute(['rid' => $rid, 'since' => $since]);
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $orderIds[] = (int)$r['id'];
            }
            if (count($orderIds) >= 5) {
                $ph = implode(',', array_fill(0, count($orderIds), '?'));
                $stmt = $pdo->prepare("
                    SELECT oi.order_id, oi.menu_item_id, mi.name
                    FROM order_items oi
                    INNER JOIN menu_items mi ON mi.id = oi.menu_item_id AND mi.restaurant_id = ?
                    WHERE oi.order_id IN ($ph)
                ");
                $stmt->execute(array_merge([$rid], $orderIds));
                $byOrder = [];
                $names = [];
                while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $oid = (int)$r['order_id'];
                    $mid = (int)$r['menu_item_id'];
                    if (!isset($byOrder[$oid])) {
                        $byOrder[$oid] = [];
                    }
                    $byOrder[$oid][$mid] = (string)$r['name'];
                    $names[$mid] = (string)$r['name'];
                }
                $pairCount = [];
                foreach ($byOrder as $ids) {
                    $ids = array_keys($ids);
                    sort($ids);
                    for ($i = 0; $i < count($ids); $i++) {
                        for ($j = $i + 1; $j < count($ids); $j++) {
                            $key = $ids[$i] . '_' . $ids[$j];
                            $pairCount[$key] = ($pairCount[$key] ?? 0) + 1;
                        }
                    }
                }
                arsort($pairCount);
                $seen = [];
                foreach (array_slice($pairCount, 0, 5) as $key => $cnt) {
                    if ($cnt < 2) {
                        break;
                    }
                    list($a, $b) = explode('_', $key, 2);
                    $a = (int)$a;
                    $b = (int)$b;
                    if (isset($names[$a], $names[$b]) && !isset($seen[$a . '-' . $b])) {
                        $seen[$a . '-' . $b] = true;
                        $out['pair_with'][] = ['item_a_id' => $a, 'item_a_name' => $names[$a], 'item_b_id' => $b, 'item_b_name' => $names[$b], 'orders_together' => $cnt];
                        $out['consider_combo'][] = ['item_a_name' => $names[$a], 'item_b_name' => $names[$b], 'orders_together' => $cnt];
                    }
                }
            }

            return $out;
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('menu_intelligence opportunities ' . $e->getMessage());
            }
            return $out;
        }
    }
}

if (!function_exists('get_menu_warnings')) {
    /**
     * Warnings: no_sales, low_performer, weak_category.
     * @return array{no_sales: array, low_performer: array, weak_category: array}
     */
    function get_menu_warnings(int $restaurantId): array
    {
        $restaurantId = (int) $restaurantId;
        $out = ['no_sales' => [], 'low_performer' => [], 'weak_category' => []];
        if ($restaurantId <= 0 || !function_exists('db')) {
            return $out;
        }
        $pdo = db();
        $days = 14;
        $since = date('Y-m-d 00:00:00', strtotime("-{$days} days"));

        try {
            if (function_exists('db_table_exists') && (!db_table_exists('orders') || !db_table_exists('order_items') || !db_table_exists('menu_items'))) {
                return $out;
            }

            $stmt = $pdo->prepare("
                SELECT mi.id, mi.name, COALESCE(SUM(oi.quantity), 0) AS qty
                FROM menu_items mi
                LEFT JOIN order_items oi ON oi.menu_item_id = mi.id
                LEFT JOIN orders o ON o.id = oi.order_id AND o.restaurant_id = mi.restaurant_id AND o.created_at >= :since
                    AND (o.order_status IS NULL OR o.order_status <> 'canceled')
                    AND (o.payment_status IS NULL OR o.payment_status = 'paid')
                WHERE mi.restaurant_id = :rid AND mi.available = 1
                GROUP BY mi.id, mi.name
                ORDER BY qty ASC
            ");
            $stmt->execute(['rid' => $restaurantId, 'since' => $since]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $withSales = [];
            foreach ($rows as $r) {
                $qty = (int)($r['qty'] ?? 0);
                $item = ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'qty' => $qty];
                if ($qty === 0) {
                    $out['no_sales'][] = $item;
                } elseif ($qty > 0 && $qty <= 2) {
                    $out['low_performer'][] = $item;
                }
                if ($qty > 0) {
                    $withSales[] = $item;
                }
            }
            $out['no_sales'] = array_slice($out['no_sales'], 0, 10);
            $out['low_performer'] = array_slice($out['low_performer'], 0, 5);

            if (function_exists('db_table_exists') && db_table_exists('menu_categories')) {
                $stmt = $pdo->prepare("
                    SELECT mc.id, mc.name, COALESCE(SUM(oi.quantity), 0) AS qty
                    FROM menu_categories mc
                    LEFT JOIN menu_items mi ON mi.category_id = mc.id AND mi.restaurant_id = mc.restaurant_id AND mi.available = 1
                    LEFT JOIN order_items oi ON oi.menu_item_id = mi.id
                    LEFT JOIN orders o ON o.id = oi.order_id AND o.restaurant_id = mc.restaurant_id AND o.created_at >= :since
                        AND (o.order_status IS NULL OR o.order_status <> 'canceled')
                    WHERE mc.restaurant_id = :rid
                    GROUP BY mc.id, mc.name
                    HAVING qty > 0
                    ORDER BY qty ASC
                ");
                $stmt->execute(['rid' => $restaurantId, 'since' => $since]);
                $catRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if (count($catRows) > 1) {
                    $weak = $catRows[0];
                    if ((int)($weak['qty'] ?? 0) < 5) {
                        $out['weak_category'][] = ['category_id' => (int)$weak['id'], 'category_name' => (string)$weak['name'], 'qty' => (int)$weak['qty']];
                    }
                }
            }

            return $out;
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('menu_intelligence warnings ' . $e->getMessage());
            }
            return $out;
        }
    }
}

function _menu_intelligence_build_insights(int $restaurantId, array $opportunities, array $warnings): array
{
    $insights = [];
    if (!empty($opportunities['promote'])) {
        $first = $opportunities['promote'][0];
        $insights[] = $first['item_name'] . ' drives strong sales — consider promoting it.';
    }
    if (!empty($opportunities['consider_combo'])) {
        $c = $opportunities['consider_combo'][0];
        $insights[] = $c['item_a_name'] . ' and ' . $c['item_b_name'] . ' are often ordered together; consider a combo.';
    }
    if (!empty($opportunities['pair_with'])) {
        $p = $opportunities['pair_with'][0];
        $insights[] = 'Pair ' . $p['item_a_name'] . ' with ' . $p['item_b_name'] . ' (ordered together ' . $p['orders_together'] . '×).';
    }
    if (!empty($warnings['no_sales'])) {
        $names = array_slice(array_column($warnings['no_sales'], 'name'), 0, 3);
        $insights[] = 'No sales this period: ' . implode(', ', $names) . '. Review or promote.';
    }
    if (!empty($warnings['weak_category'])) {
        $c = $warnings['weak_category'][0];
        $insights[] = 'Category «' . $c['category_name'] . '» has low volume — consider promotions.';
    }
    if (!empty($opportunities['add_photo'])) {
        $n = count($opportunities['add_photo']);
        $insights[] = $n . ' item(s) have no photo; adding photos can improve conversion.';
    }
    if (!empty($warnings['low_performer'])) {
        $first = $warnings['low_performer'][0];
        $insights[] = $first['name'] . ' has very low sales — consider moving higher in menu or reviewing.';
    }
    return $insights;
}

function _menu_intelligence_demo(): array
{
    return [
        'insights' => [
            'Стейк рибай — лидер продаж; можно вынести в блок «Хиты недели».',
            'Паста карбонара и лимонад часто заказывают вместе — подходит под комбо.',
            'К бургеру гости добавляют крылья BBQ (частые пары в заказах).',
        ],
        'opportunities' => [
            'promote'       => [['item_id' => 5, 'item_name' => 'Стейк рибай 250 г', 'reason' => 'top_seller']],
            'add_photo'     => [['item_id' => 4, 'item_name' => 'Суп дня (борщ)']],
            'pair_with'     => [['item_a_id' => 7, 'item_a_name' => 'Паста карбонара', 'item_b_id' => 13, 'item_b_name' => 'Лимонад домашний 0,5 л', 'orders_together' => 14]],
            'move_higher'   => [['item_id' => 8, 'item_name' => 'Ризотто с белыми грибами', 'reason' => 'low_visibility']],
            'consider_combo' => [['item_a_name' => 'Паста карбонара', 'item_b_name' => 'Лимонад домашний 0,5 л', 'orders_together' => 14]],
        ],
        'warnings' => [
            'no_sales'      => [],
            'low_performer' => [['id' => 8, 'name' => 'Ризотто с белыми грибами', 'qty' => 4]],
            'weak_category' => [['category_id' => 4, 'category_name' => 'Десерты', 'qty' => 18]],
        ],
    ];
}
