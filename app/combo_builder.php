<?php

require_once __DIR__ . '/db.php';
if (file_exists(__DIR__ . '/schema_guard.php')) {
    require_once __DIR__ . '/schema_guard.php';
}

if (!function_exists('get_combos')) {
    /**
     * Возвращает доступные combo upsell-правила для ресторана и текущей корзины.
     *
     * Логика:
     * - учитываются только active=1 из combo_rules;
     * - в результат попадают правила, чей trigger_item_id уже есть в корзине (qty > 0);
     * - сортировка по priority DESC (дополнительно id DESC для стабильности).
     *
     * @param int $restaurantId ID ресторана.
     * @param array<int|string,int|float|string> $cartItems Корзина в формате [menu_item_id => qty].
     *
     * @return array<int,array{
     *   trigger_item_id:int,
     *   suggested_item_ids:array<int,int>,
     *   priority:int
     * }>
     *
     * @throws \Throwable Исключения БД/декодирования перехватываются внутри; при ошибке возвращается пустой массив.
     */
    function get_combos(int $restaurantId, array $cartItems): array
    {
        $restaurantId = (int)$restaurantId;
        if ($restaurantId <= 0) {
            return [];
        }

        $triggerIds = [];
        foreach ($cartItems as $itemId => $qty) {
            $id = (int)$itemId;
            if ($id <= 0) {
                continue;
            }
            if ((float)$qty <= 0) {
                continue;
            }
            $triggerIds[$id] = true;
        }
        if ($triggerIds === []) {
            return [];
        }

        if (function_exists('db_table_exists') && !db_table_exists('combo_rules')) {
            return [];
        }

        try {
            $pdo = db();
            $ids = array_keys($triggerIds);
            $ph = implode(',', array_fill(0, count($ids), '?'));

            $sql = "
                SELECT id, trigger_item_id, suggested_item_ids, priority
                FROM combo_rules
                WHERE restaurant_id = ?
                  AND active = 1
                  AND trigger_item_id IN ($ph)
                ORDER BY priority DESC, id DESC
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_merge([$restaurantId], $ids));

            $out = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $triggerItemId = (int)($row['trigger_item_id'] ?? 0);
                if ($triggerItemId <= 0) {
                    continue;
                }

                $rawSuggested = $row['suggested_item_ids'] ?? '[]';
                $decoded = json_decode((string)$rawSuggested, true);
                if (!is_array($decoded)) {
                    continue;
                }

                $suggestedIds = [];
                foreach ($decoded as $v) {
                    $sid = (int)$v;
                    if ($sid <= 0) {
                        continue;
                    }
                    if (!isset($suggestedIds[$sid])) {
                        $suggestedIds[$sid] = $sid;
                    }
                }

                if ($suggestedIds === []) {
                    continue;
                }

                $out[] = [
                    'trigger_item_id' => $triggerItemId,
                    'suggested_item_ids' => array_values($suggestedIds),
                    'priority' => (int)($row['priority'] ?? 100),
                ];
            }

            return $out;
        } catch (Throwable $e) {
            error_log('get_combos ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('get_combo_suggestions')) {
    /**
     * Build combo suggestions from already paid/completed order history.
     * Returns stable payload and never throws.
     *
     * @return array{
     *   suggestions: array<int,array{
     *     label:string,
     *     confidence:float,
     *     estimated_uplift_text:string,
     *     items:array<int,array{name:string}>
     *   }>,
     *   order_count:int
     * }
     */
    function get_combo_suggestions(int $restaurantId, int $limit = 5): array
    {
        $restaurantId = (int)$restaurantId;
        $limit = max(1, min(20, (int)$limit));
        $empty = ['suggestions' => [], 'order_count' => 0];
        if ($restaurantId <= 0) {
            return $empty;
        }

        if (function_exists('db_table_exists')) {
            if (!db_table_exists('orders') || !db_table_exists('order_items') || !db_table_exists('menu_items')) {
                return $empty;
            }
        }

        try {
            $pdo = db();

            $statusFilterSql = '';
            if (function_exists('db_column_exists') && db_column_exists('orders', 'order_status')) {
                $statusFilterSql .= " AND LOWER(COALESCE(o.order_status, '')) IN ('delivered','completed') ";
            }
            if (function_exists('db_column_exists') && db_column_exists('orders', 'payment_status')) {
                $statusFilterSql .= " AND LOWER(COALESCE(o.payment_status, '')) IN ('paid','completed') ";
            }

            $stmtOrders = $pdo->prepare("
                SELECT COUNT(DISTINCT o.id)
                FROM orders o
                WHERE o.restaurant_id = :rid
                {$statusFilterSql}
            ");
            $stmtOrders->execute([':rid' => $restaurantId]);
            $ordersCount = (int)$stmtOrders->fetchColumn();
            if ($ordersCount <= 0) {
                return $empty;
            }

            $pairSql = "
                SELECT
                    oi1.menu_item_id AS item_a,
                    oi2.menu_item_id AS item_b,
                    COUNT(DISTINCT oi1.order_id) AS pair_orders
                FROM order_items oi1
                INNER JOIN order_items oi2
                    ON oi2.order_id = oi1.order_id
                   AND oi2.menu_item_id > oi1.menu_item_id
                INNER JOIN orders o
                    ON o.id = oi1.order_id
                WHERE o.restaurant_id = :rid
                  AND oi1.menu_item_id IS NOT NULL
                  AND oi2.menu_item_id IS NOT NULL
                  {$statusFilterSql}
                GROUP BY oi1.menu_item_id, oi2.menu_item_id
                HAVING pair_orders >= 2
                ORDER BY pair_orders DESC
                LIMIT {$limit}
            ";
            $stmtPairs = $pdo->prepare($pairSql);
            $stmtPairs->execute([':rid' => $restaurantId]);
            $pairs = $stmtPairs->fetchAll(PDO::FETCH_ASSOC) ?: [];
            if ($pairs === []) {
                return ['suggestions' => [], 'order_count' => $ordersCount];
            }

            $itemIds = [];
            foreach ($pairs as $pair) {
                $a = (int)($pair['item_a'] ?? 0);
                $b = (int)($pair['item_b'] ?? 0);
                if ($a > 0) {
                    $itemIds[$a] = true;
                }
                if ($b > 0) {
                    $itemIds[$b] = true;
                }
            }
            if ($itemIds === []) {
                return ['suggestions' => [], 'order_count' => $ordersCount];
            }

            $ids = array_keys($itemIds);
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $stmtItems = $pdo->prepare("
                SELECT id, name
                FROM menu_items
                WHERE restaurant_id = ?
                  AND id IN ({$ph})
            ");
            $stmtItems->execute(array_merge([$restaurantId], $ids));
            $nameById = [];
            foreach ($stmtItems->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $id = (int)($row['id'] ?? 0);
                if ($id > 0) {
                    $nameById[$id] = trim((string)($row['name'] ?? ''));
                }
            }

            $suggestions = [];
            foreach ($pairs as $pair) {
                $a = (int)($pair['item_a'] ?? 0);
                $b = (int)($pair['item_b'] ?? 0);
                $pairOrders = (int)($pair['pair_orders'] ?? 0);
                if ($a <= 0 || $b <= 0 || $pairOrders <= 0) {
                    continue;
                }
                $nameA = trim((string)($nameById[$a] ?? ''));
                $nameB = trim((string)($nameById[$b] ?? ''));
                if ($nameA === '' || $nameB === '') {
                    continue;
                }

                $confidence = $ordersCount > 0 ? round(min(0.99, $pairOrders / max(1, $ordersCount)), 2) : 0.0;
                $upliftPct = max(5, min(25, (int)round($confidence * 100)));
                $suggestions[] = [
                    'label' => $nameA . ' + ' . $nameB,
                    'confidence' => $confidence,
                    'estimated_uplift_text' => 'потенциал роста среднего чека ~' . $upliftPct . '%',
                    'items' => [
                        ['name' => $nameA],
                        ['name' => $nameB],
                    ],
                ];
            }

            return [
                'suggestions' => $suggestions,
                'order_count' => $ordersCount,
            ];
        } catch (Throwable $e) {
            error_log('get_combo_suggestions ' . $e->getMessage());
            return $empty;
        }
    }
}
