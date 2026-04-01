<?php
/**
 * Upsell analytics v1: track events, no 500 if table missing.
 * No writes in demo mode. Safe when upsell_events table is missing.
 */

if (file_exists(__DIR__ . '/schema_guard.php')) {
    require_once __DIR__ . '/schema_guard.php';
}
if (file_exists(__DIR__ . '/cache.php')) {
    require_once __DIR__ . '/cache.php';
}
if (file_exists(__DIR__ . '/flow_id.php')) {
    require_once __DIR__ . '/flow_id.php';
}


if (!function_exists('upsell_track_should_skip_duplicate')) {
    /**
     * Anti-noise dedupe:
     * - upsell_shown: same session+item within 5 seconds -> skip
     * - upsell_clicked / upsell_added_to_cart: same session+item+order (or session+item) -> skip
     */
    function upsell_track_should_skip_duplicate(
        int $restaurantId,
        string $event,
        ?int $orderId,
        ?string $sessionKey,
        ?int $upsellItemId
    ): bool {
        if (!in_array($event, ['upsell_shown', 'upsell_clicked', 'upsell_added_to_cart'], true)) {
            return false;
        }
        if ($upsellItemId === null || $upsellItemId <= 0) {
            return false;
        }
        if ($sessionKey === null || trim($sessionKey) === '') {
            return false;
        }

        try {
            $pdo = db();
            if ($event === 'upsell_shown') {
                $stmt = $pdo->prepare("
                    SELECT 1
                    FROM upsell_events
                    WHERE restaurant_id = ?
                      AND event = 'upsell_shown'
                      AND session_key = ?
                      AND upsell_item_id = ?
                      AND created_at >= DATE_SUB(NOW(), INTERVAL 5 SECOND)
                    LIMIT 1
                ");
                $stmt->execute([$restaurantId, $sessionKey, $upsellItemId]);
                return (bool)$stmt->fetchColumn();
            }

            if ($orderId !== null && $orderId > 0) {
                $stmt = $pdo->prepare("
                    SELECT 1
                    FROM upsell_events
                    WHERE restaurant_id = ?
                      AND event = ?
                      AND session_key = ?
                      AND order_id = ?
                      AND upsell_item_id = ?
                    LIMIT 1
                ");
                $stmt->execute([$restaurantId, $event, $sessionKey, $orderId, $upsellItemId]);
                return (bool)$stmt->fetchColumn();
            }

            $stmt = $pdo->prepare("
                SELECT 1
                FROM upsell_events
                WHERE restaurant_id = ?
                  AND event = ?
                  AND session_key = ?
                  AND upsell_item_id = ?
                LIMIT 1
            ");
            $stmt->execute([$restaurantId, $event, $sessionKey, $upsellItemId]);
            return (bool)$stmt->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }
}

function upsell_track(
    int $restaurantId,
    string $event,
    ?int $tableId = null,
    ?int $orderId = null,
    ?string $sessionKey = null,
    ?int $baseItemId = null,
    ?int $upsellItemId = null,
    array $meta = []
): void {
    // Backward-compatible events (used by existing analytics):
    // - shown/add_click/accepted_in_order
    //
    // Extended events (used by conversion-based self-learning):
    // - upsell_shown / upsell_clicked / upsell_added_to_cart
    $allowed = [
        'shown',
        'add_click',
        'accepted_in_order',
        'upsell_shown',
        'upsell_clicked',
        'upsell_added_to_cart',
    ];
    if (!in_array($event, $allowed, true)) {
        return;
    }
    if (function_exists('is_demo_mode') && is_demo_mode()) {
        return;
    }
    if (function_exists('db_table_exists') && !db_table_exists('upsell_events')) {
        return;
    }

    // Ensure session_id + flow_id in event payload/meta.
    if (($sessionKey === null || trim((string)$sessionKey) === '') && function_exists('app_upsell_session_key_get')) {
        $sessionKey = app_upsell_session_key_get();
    }
    if (function_exists('app_flow_id_inject_meta')) {
        $meta = app_flow_id_inject_meta($meta, $restaurantId);
    }

    // Noise protection for key conversion events.
    if (function_exists('upsell_track_should_skip_duplicate')
        && upsell_track_should_skip_duplicate($restaurantId, $event, $orderId, $sessionKey, $upsellItemId)
    ) {
        return;
    }

    try {
        $pdo = db();
        $metaJson = $meta === [] ? null : json_encode($meta, JSON_UNESCAPED_UNICODE);
        $stmt = $pdo->prepare("
            INSERT INTO upsell_events (restaurant_id, table_id, order_id, session_key, event, base_item_id, upsell_item_id, meta_json)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $restaurantId,
            $tableId,
            $orderId,
            $sessionKey,
            $event,
            $baseItemId,
            $upsellItemId,
            $metaJson,
        ]);
    } catch (Throwable $e) {
        error_log('upsell_track ' . $event . ' ' . $e->getMessage());
    }
}

/**
 * Aggregates and conversion for last N days.
 * @return array{by_event: array<string,int>, top_upsell: array, conversion_pct: float}
 */
function upsell_stats(int $restaurantId, int $rangeDays = 7): array
{
    $result = [
        'by_event' => ['shown' => 0, 'add_click' => 0, 'accepted_in_order' => 0],
        'top_upsell' => [],
        'conversion_pct' => 0.0,
    ];
    if (function_exists('db_table_exists') && !db_table_exists('upsell_events')) {
        return $result;
    }
    try {
        $pdo = db();
        $since = date('Y-m-d H:i:s', strtotime("-{$rangeDays} days"));

        $stmt = $pdo->prepare("
            SELECT event, COUNT(*) AS cnt
            FROM upsell_events
            WHERE restaurant_id = ? AND created_at >= ?
            GROUP BY event
        ");
        $stmt->execute([$restaurantId, $since]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $result['by_event'][$row['event']] = (int)$row['cnt'];
        }

        $stmt = $pdo->prepare("
            SELECT upsell_item_id, COUNT(*) AS cnt
            FROM upsell_events
            WHERE restaurant_id = ? AND created_at >= ? AND event = 'add_click' AND upsell_item_id IS NOT NULL
            GROUP BY upsell_item_id
            ORDER BY cnt DESC
            LIMIT 5
        ");
        $stmt->execute([$restaurantId, $since]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $result['top_upsell'][] = ['upsell_item_id' => (int)$row['upsell_item_id'], 'count' => (int)$row['cnt']];
        }

        $shown = $result['by_event']['shown'];
        $accepted = $result['by_event']['accepted_in_order'];
        if ($shown > 0) {
            $result['conversion_pct'] = round(100.0 * $accepted / $shown, 1);
        }
    } catch (Throwable $e) {
        error_log('upsell_stats ' . $e->getMessage());
    }
    return $result;
}

/**
 * ---- Upsell Revenue Attribution & Analytics (read-only) ----
 *
 * Attribution truth model (conservative + explainable):
 * An order is counted as "with upsell" if it has at least one attributable upsell line item:
 * 1) Direct evidence: there exists an upsell_events row (event='accepted_in_order') for the same (restaurant_id, order_id)
 *    where ue.upsell_item_id equals that order_items.menu_item_id.
 * OR
 * 2) Safe inference: menu_item_upsells mapping indicates that menu_item_id is an active upsell_item_id,
 *    AND the same order also contains the corresponding base_item_id (base+upsell present in the same order).
 *
 * This avoids inflated metrics when inference is weak.
 */

if (!function_exists('upsell_analytics_classify_item_name')) {
    /**
     * Lightweight, deterministic keyword classification.
     * Uses only menu_items.name (no ML, no category names).
     *
     * @return array{is_drink:bool,is_side:bool}
     */
    function upsell_analytics_classify_item_name(string $name): array
    {
        $lc = mb_strtolower($name, 'UTF-8');
        $has = function (string $needle) use ($lc): bool {
            return $needle !== '' && mb_strpos($lc, $needle, 0, 'UTF-8') !== false;
        };

        // Tight tokens to reduce misclassification.
        $drinkTokens = ['лимонад', 'cola', 'кола', 'сок', 'чай', 'кофе', 'вода', 'морс', 'juice', 'tea', 'coffee'];
        $sideTokens  = ['гарнир', 'закуска', 'салат', 'картоф', 'картофель', 'potato', 'рис', 'rice', 'гриль'];

        $isDrink = false;
        foreach ($drinkTokens as $t) {
            if ($has($t)) { $isDrink = true; break; }
        }
        $isSide = false;
        foreach ($sideTokens as $t) {
            if ($has($t)) { $isSide = true; break; }
        }

        return ['is_drink' => $isDrink, 'is_side' => $isSide];
    }
}

if (!function_exists('upsell_analytics_build_context')) {
    /**
     * Build attributed upsell line context once per (restaurantId, days).
     * Returns SELECT-only computed aggregates + cached classification maps.
     *
     * @return array{
     *   since:string,
     *   total_orders:int,
     *   total_revenue:float,
     *   upsell_revenue:float,
     *   orders_with_upsell:array<int,bool>,
     *   orders_with_upsell_count:int,
     *   upsell_lines:array<int,array{
     *      order_id:int,
     *      order_date:string,
     *      item_id:int,
     *      qty:int,
     *      revenue:float,
     *      item_name:string,
     *      item_category_id:int|null,
     *      is_drink:bool,
     *      is_side:bool,
     *      has_rule_linked:bool,
     *      has_same_category:bool
     *   }>,
     *   order_has_drink:array<int,bool>,
     *   order_has_side:array<int,bool>,
     *   with_orders_total_revenue:float,
     *   with_orders_total_count:int
     * }
     */
    function upsell_analytics_build_context(int $restaurantId, int $days = 30): array
    {
        $restaurantId = (int)$restaurantId;
        $days = max(1, min(180, (int)$days));
        $cacheKey = $restaurantId . ':' . $days;
        static $cache = [];
        if (isset($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }

        $since = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        $out = [
            'since' => $since,
            'total_orders' => 0,
            'total_revenue' => 0.0,
            'upsell_revenue' => 0.0,
            'orders_with_upsell' => [],
            'orders_with_upsell_count' => 0,
            'upsell_lines' => [],
            'order_has_drink' => [],
            'order_has_side' => [],
            'with_orders_total_revenue' => 0.0,
            'with_orders_total_count' => 0,
        ];

        if ($restaurantId <= 0) {
            $cache[$cacheKey] = $out;
            return $out;
        }

        $pdo = db();

        // Total orders & revenue (read-only).
        $orderTotalCol = (function_exists('db_column_exists') && db_column_exists('orders', 'total_amount')) ? 'o.total_amount' : 'o.total_price';
        $stmt = $pdo->prepare("
            SELECT
                COUNT(*) AS total_orders,
                COALESCE(SUM({$orderTotalCol}), 0) AS total_revenue
            FROM orders o
            WHERE o.restaurant_id = :rest
              AND o.created_at >= :since
              AND o.payment_status = 'paid'
              AND o.order_status <> 'canceled'
        ");
        $stmt->execute([':rest' => $restaurantId, ':since' => $since]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $out['total_orders'] = (int)($row['total_orders'] ?? 0);
        $out['total_revenue'] = (float)($row['total_revenue'] ?? 0);

        // Prepare safe, evidence-based attribution.
        $hasEvents = function_exists('db_table_exists') && db_table_exists('upsell_events');
        $hasMapping = function_exists('db_table_exists') && db_table_exists('menu_item_upsells');

        $hasEvents = $hasEvents
            && function_exists('db_column_exists')
            && db_column_exists('upsell_events', 'event')
            && db_column_exists('upsell_events', 'order_id')
            && db_column_exists('upsell_events', 'upsell_item_id');

        $hasMapping = $hasMapping
            && function_exists('db_column_exists')
            && db_column_exists('menu_item_upsells', 'upsell_item_id')
            && db_column_exists('menu_item_upsells', 'base_item_id')
            && db_column_exists('menu_item_upsells', 'active');

        $hasOrderItems = function_exists('db_column_exists') && db_column_exists('order_items', 'menu_item_id') && db_column_exists('order_items', 'price');
        if (!$hasOrderItems || (!$hasEvents && !$hasMapping)) {
            $cache[$cacheKey] = $out;
            return $out;
        }

        $qtyExpr = '1';
        if (function_exists('db_column_exists') && db_column_exists('order_items', 'quantity')) {
            $qtyExpr = 'COALESCE(oi.quantity, 0)';
        } elseif (function_exists('db_column_exists') && db_column_exists('order_items', 'qty')) {
            $qtyExpr = 'COALESCE(oi.qty, 0)';
        }

        $eventExistsSql = $hasEvents
            ? "EXISTS (
                SELECT 1 FROM upsell_events ue
                WHERE ue.restaurant_id = :rest
                  AND ue.order_id = oi.order_id
                  AND ue.event = 'accepted_in_order'
                  AND ue.created_at >= :since
                  AND ue.upsell_item_id = oi.menu_item_id
            )"
            : '0';

        $ruleLinkedExistsSql = $hasMapping
            ? "EXISTS (
                SELECT 1
                FROM menu_item_upsells u
                WHERE u.restaurant_id = :rest
                  AND u.active = 1
                  AND u.upsell_item_id = oi.menu_item_id
                  AND EXISTS (
                      SELECT 1 FROM order_items ob
                      WHERE ob.order_id = oi.order_id
                        AND ob.menu_item_id = u.base_item_id
                  )
            )"
            : '0';

        $sameCategoryExistsSql = $hasMapping
            ? "EXISTS (
                SELECT 1
                FROM menu_item_upsells u
                WHERE u.restaurant_id = :rest
                  AND u.active = 1
                  AND u.upsell_item_id = oi.menu_item_id
                  AND EXISTS (
                      SELECT 1 FROM order_items ob
                      WHERE ob.order_id = oi.order_id
                        AND ob.menu_item_id = u.base_item_id
                  )
                  AND EXISTS (
                      SELECT 1 FROM menu_items mb
                      WHERE mb.restaurant_id = :rest
                        AND mb.id = u.base_item_id
                        AND mb.category_id = mi.category_id
                  )
            )"
            : '0';

        $lineAttrSql = '(' . $eventExistsSql . ' OR ' . $ruleLinkedExistsSql . ')';

        // Fetch attributable upsell line items.
        $stmt = $pdo->prepare("
            SELECT
                oi.order_id AS order_id,
                DATE(o.created_at) AS order_date,
                oi.menu_item_id AS item_id,
                mi.name AS item_name,
                mi.category_id AS item_category_id,
                CAST({$qtyExpr} AS UNSIGNED) AS qty,
                (oi.price * {$qtyExpr}) AS revenue,
                CAST({$ruleLinkedExistsSql} AS UNSIGNED) AS has_rule_linked,
                CAST({$sameCategoryExistsSql} AS UNSIGNED) AS has_same_category
            FROM order_items oi
            INNER JOIN orders o ON o.id = oi.order_id AND o.restaurant_id = :rest
            INNER JOIN menu_items mi ON mi.id = oi.menu_item_id AND mi.restaurant_id = :rest
            WHERE o.created_at >= :since
              AND o.payment_status = 'paid'
              AND o.order_status <> 'canceled'
              AND oi.menu_item_id IS NOT NULL
              AND oi.menu_item_id > 0
              AND {$lineAttrSql}
        ");
        $stmt->execute([':rest' => $restaurantId, ':since' => $since]);
        $lines = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$lines) {
            $cache[$cacheKey] = $out;
            return $out;
        }

        $orderHasDrink = [];
        $orderHasSide = [];
        $ordersWithUpsell = [];

        $upsellLines = [];
        $upsellRevenue = 0.0;
        foreach ($lines as $l) {
            $oid = (int)($l['order_id'] ?? 0);
            $iid = (int)($l['item_id'] ?? 0);
            if ($oid <= 0 || $iid <= 0) {
                continue;
            }
            $qty = (int)($l['qty'] ?? 0);
            $rev = (float)($l['revenue'] ?? 0);
            if ($qty <= 0 || $rev <= 0) {
                // Keep only meaningful lines.
                continue;
            }
            $nm = (string)($l['item_name'] ?? '');
            $cls = upsell_analytics_classify_item_name($nm);
            $rid = (int)($l['has_rule_linked'] ?? 0);
            $sc = (int)($l['has_same_category'] ?? 0);
            $ordersWithUpsell[$oid] = true;
            $upsellRevenue += $rev;
            $upsellLines[] = [
                'order_id' => $oid,
                'order_date' => (string)($l['order_date'] ?? ''),
                'item_id' => $iid,
                'qty' => $qty,
                'revenue' => $rev,
                'item_name' => $nm,
                'item_category_id' => isset($l['item_category_id']) ? (int)$l['item_category_id'] : null,
                'is_drink' => (bool)$cls['is_drink'],
                'is_side' => (bool)$cls['is_side'],
                'has_rule_linked' => $rid === 1,
                'has_same_category' => $sc === 1,
            ];
        }

        if ($upsellLines === []) {
            $cache[$cacheKey] = $out;
            return $out;
        }

        $out['upsell_lines'] = $upsellLines;
        $out['upsell_revenue'] = $upsellRevenue;
        $out['orders_with_upsell'] = $ordersWithUpsell;
        $out['orders_with_upsell_count'] = count($ordersWithUpsell);

        $orderIds = array_keys($ordersWithUpsell);
        if ($orderIds !== []) {
            // Compute total order revenue for with-upsell orders.
            $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
            $stmt = $pdo->prepare("
                SELECT
                    COUNT(*) AS cnt,
                    COALESCE(SUM({$orderTotalCol}), 0) AS sum_rev
                FROM orders o
                WHERE o.restaurant_id = ?
                  AND o.created_at >= ?
                  AND o.payment_status = 'paid'
                  AND o.order_status <> 'canceled'
                  AND o.id IN ($placeholders)
            ");
            $params = array_merge([$restaurantId, $since], $orderIds);
            $stmt->execute($params);
            $r2 = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $out['with_orders_total_count'] = (int)($r2['cnt'] ?? 0);
            $out['with_orders_total_revenue'] = (float)($r2['sum_rev'] ?? 0);

            // Order-level completion signals (drink/side presence).
            if (count($orderIds) <= 500) {
                $ph2 = implode(',', array_fill(0, count($orderIds), '?'));
                $stmt = $pdo->prepare("
                    SELECT
                        oi.order_id AS order_id,
                        mi.name AS item_name
                    FROM order_items oi
                    INNER JOIN menu_items mi ON mi.id = oi.menu_item_id AND mi.restaurant_id = ?
                    INNER JOIN orders o ON o.id = oi.order_id AND o.restaurant_id = ?
                    WHERE o.created_at >= ?
                      AND o.payment_status = 'paid'
                      AND o.order_status <> 'canceled'
                      AND oi.menu_item_id IS NOT NULL
                      AND oi.menu_item_id > 0
                      AND oi.order_id IN ($ph2)
                ");
                $params2 = array_merge([$restaurantId, $restaurantId, $since], $orderIds);
                $stmt->execute($params2);
                while ($r3 = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $oid = (int)($r3['order_id'] ?? 0);
                    if ($oid <= 0) continue;
                    $nm = (string)($r3['item_name'] ?? '');
                    $cls = upsell_analytics_classify_item_name($nm);
                    if (!isset($orderHasDrink[$oid])) $orderHasDrink[$oid] = false;
                    if (!isset($orderHasSide[$oid]))  $orderHasSide[$oid] = false;
                    if (!empty($cls['is_drink'])) $orderHasDrink[$oid] = true;
                    if (!empty($cls['is_side']))  $orderHasSide[$oid] = true;
                }
            } else {
                // Conservative default: disable completion-based types to avoid inflated classification.
                foreach ($orderIds as $oid) {
                    $orderHasDrink[(int)$oid] = true;
                    $orderHasSide[(int)$oid] = true;
                }
            }
        }

        $out['order_has_drink'] = $orderHasDrink;
        $out['order_has_side'] = $orderHasSide;

        $cache[$cacheKey] = $out;
        return $out;
    }
}

if (!function_exists('get_upsell_analytics_summary')) {
    /**
     * @return array{
     *   total_orders:int,
     *   orders_with_upsell:int,
     *   upsell_attach_rate:float, // 0..1
     *   upsell_revenue:float,
     *   total_revenue:float,
     *   upsell_revenue_share:float, // 0..1
     *   avg_order_value:float,
     *   avg_order_with_upsell:float,
     *   avg_order_without_upsell:float,
     *   aov_lift_value:float,
     *   aov_lift_percent:float|null
     * }
     */
    function get_upsell_analytics_summary(int $restaurantId, int $days = 30): array
    {
        $restaurantId = (int)$restaurantId;
        $days = max(1, (int)$days);

        $ctx = upsell_analytics_build_context($restaurantId, $days);
        $totalOrders = (int)($ctx['total_orders'] ?? 0);
        $totalRevenue = (float)($ctx['total_revenue'] ?? 0);
        $upsellRevenue = (float)($ctx['upsell_revenue'] ?? 0);
        $ordersWithUpsell = (int)($ctx['orders_with_upsell_count'] ?? 0);

        $out = [
            'total_orders' => $totalOrders,
            'orders_with_upsell' => $ordersWithUpsell,
            'upsell_attach_rate' => 0.0,
            'upsell_revenue' => $upsellRevenue,
            'total_revenue' => $totalRevenue,
            'upsell_revenue_share' => ($totalRevenue > 0 ? ($upsellRevenue / $totalRevenue) : 0.0),
            'avg_order_value' => ($totalOrders > 0 ? ($totalRevenue / $totalOrders) : 0.0),
            'avg_order_with_upsell' => 0.0,
            'avg_order_without_upsell' => 0.0,
            'aov_lift_value' => 0.0,
            'aov_lift_percent' => null,
        ];

        if ($ordersWithUpsell <= 0 || $totalOrders <= 0) {
            // attach rate / lift remain zeros.
            return $out;
        }

        $avgWith = ($ctx['with_orders_total_count'] > 0)
            ? ((float)$ctx['with_orders_total_revenue'] / (int)$ctx['with_orders_total_count'])
            : 0.0;
        $avgAll = $out['avg_order_value'];

        $withoutCount = $totalOrders - (int)$ctx['with_orders_total_count'];
        $withoutRevenue = $totalRevenue - (float)$ctx['with_orders_total_revenue'];
        $avgWithout = ($withoutCount > 0)
            ? max(0.0, (float)$withoutRevenue / (int)$withoutCount)
            : 0.0;

        $liftVal = $avgWith - $avgWithout;
        $liftPct = null;
        if ($avgWithout > 0) {
            $liftPct = ($liftVal / $avgWithout);
        }

        $out['avg_order_with_upsell'] = $avgWith;
        $out['avg_order_without_upsell'] = $avgWithout;
        $out['aov_lift_value'] = $liftVal;
        $out['aov_lift_percent'] = $liftPct;

        // Opportunity denominator for attach-rate.
        $hasMapping = function_exists('db_table_exists') && db_table_exists('menu_item_upsells');
        $hasMapping = $hasMapping
            && function_exists('db_column_exists')
            && db_column_exists('menu_item_upsells', 'base_item_id')
            && db_column_exists('menu_item_upsells', 'active');

        if ($hasMapping) {
            $since = $ctx['since'] ?? date('Y-m-d H:i:s');
            $stmt = db()->prepare("
                SELECT COUNT(DISTINCT o.id) AS opp_orders
                FROM orders o
                INNER JOIN order_items ob ON ob.order_id = o.id
                INNER JOIN menu_item_upsells u
                    ON u.restaurant_id = o.restaurant_id
                   AND u.active = 1
                   AND u.base_item_id = ob.menu_item_id
                WHERE o.restaurant_id = ?
                  AND o.created_at >= ?
                  AND o.payment_status = 'paid'
                  AND o.order_status <> 'canceled'
            ");
            $stmt->execute([$restaurantId, $since]);
            $opp = (int)($stmt->fetchColumn() ?: 0);
        } else {
            $opp = $totalOrders; // safest available denominator when mapping isn't usable.
        }

        $out['upsell_attach_rate'] = ($opp > 0 ? ($ordersWithUpsell / $opp) : 0.0);
        return $out;
    }
}

if (!function_exists('get_upsell_analytics_summary_cached')) {
    /**
     * Redis cache wrapper for upsell analytics summary.
     * key = metric + restaurant_id + days
     */
    function get_upsell_analytics_summary_cached(int $restaurantId, int $days = 30): array
    {
        $restaurantId = (int)$restaurantId;
        $days = max(1, min(180, (int)$days));
        $ttl = 600; // 10 min

        $cacheKey = 'upsell_analytics_summary:' . $restaurantId . ':' . $days;
        if (function_exists('cache_get')) {
            $cached = cache_get($cacheKey);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $data = get_upsell_analytics_summary($restaurantId, $days);
        if (function_exists('cache_set')) {
            cache_set($cacheKey, $data, $ttl);
        }
        return $data;
    }
}

if (!function_exists('get_top_upsell_items')) {
    /**
     * @return array<int,array{item_id:int,name:string,times_added:int,revenue_generated:float,attach_rate:float}>
     */
    function get_top_upsell_items(int $restaurantId, int $days = 30, int $limit = 5): array
    {
        $restaurantId = (int)$restaurantId;
        $days = max(1, (int)$days);
        $limit = max(1, min(20, (int)$limit));

        $ctx = upsell_analytics_build_context($restaurantId, $days);
        $lines = $ctx['upsell_lines'] ?? [];
        if (!$lines) {
            return [];
        }

        $byItem = [];
        $ordersWithItem = [];
        foreach ($lines as $l) {
            $iid = (int)($l['item_id'] ?? 0);
            if ($iid <= 0) continue;
            if (!isset($byItem[$iid])) {
                $byItem[$iid] = [
                    'item_id' => $iid,
                    'name' => (string)($l['item_name'] ?? ''),
                    'times_added' => 0,
                    'revenue_generated' => 0.0,
                    'orders_with_item' => [],
                ];
            }
            $qty = (int)($l['qty'] ?? 0);
            $rev = (float)($l['revenue'] ?? 0);
            $byItem[$iid]['times_added'] += $qty;
            $byItem[$iid]['revenue_generated'] += $rev;
            $byItem[$iid]['orders_with_item'][(int)($l['order_id'] ?? 0)] = true;
        }

        // Denominator for attach-rate: number of orders containing any base_item mapped to this upsell item.
        $items = array_keys($byItem);
        $hasMapping = function_exists('db_table_exists') && db_table_exists('menu_item_upsells');
        $hasMapping = $hasMapping
            && function_exists('db_column_exists')
            && db_column_exists('menu_item_upsells', 'base_item_id')
            && db_column_exists('menu_item_upsells', 'upsell_item_id')
            && db_column_exists('menu_item_upsells', 'active');

        $oppByItem = [];
        if ($hasMapping) {
            $since = $ctx['since'] ?? date('Y-m-d H:i:s');
            $ph = implode(',', array_fill(0, count($items), '?'));
            $stmt = db()->prepare("
                SELECT
                    u.upsell_item_id AS item_id,
                    COUNT(DISTINCT o.id) AS opp_orders
                FROM menu_item_upsells u
                INNER JOIN orders o ON o.restaurant_id = u.restaurant_id
                INNER JOIN order_items ob ON ob.order_id = o.id AND ob.menu_item_id = u.base_item_id
                WHERE u.restaurant_id = ?
                  AND u.active = 1
                  AND u.upsell_item_id IN ($ph)
                  AND o.created_at >= ?
                  AND o.payment_status = 'paid'
                  AND o.order_status <> 'canceled'
                GROUP BY u.upsell_item_id
            ");
            $params = array_merge([$restaurantId], $items, [$since]);
            $stmt->execute($params);
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $oppByItem[(int)($r['item_id'] ?? 0)] = (int)($r['opp_orders'] ?? 0);
            }
        }

        // Sort by revenue desc, deterministic.
        usort($byItem, function ($a, $b) {
            $ra = (float)($a['revenue_generated'] ?? 0);
            $rb = (float)($b['revenue_generated'] ?? 0);
            if ($ra !== $rb) return $rb <=> $ra;
            return ((int)($a['item_id'] ?? 0)) <=> ((int)($b['item_id'] ?? 0));
        });

        $top = array_slice($byItem, 0, $limit);
        $out = [];
        foreach ($top as $it) {
            $iid = (int)($it['item_id'] ?? 0);
            $ordersWithItemCnt = is_array($it['orders_with_item'] ?? null) ? count($it['orders_with_item']) : 0;
            $den = ($hasMapping && isset($oppByItem[$iid]) ? (int)$oppByItem[$iid] : (int)($ctx['total_orders'] ?? 0));
            $attach = ($den > 0 ? ($ordersWithItemCnt / $den) : 0.0);
            $out[] = [
                'item_id' => $iid,
                'name' => (string)($it['name'] ?? ''),
                'times_added' => (int)($it['times_added'] ?? 0),
                'revenue_generated' => (float)($it['revenue_generated'] ?? 0),
                'attach_rate' => $attach,
            ];
        }
        return $out;
    }
}

if (!function_exists('get_top_upsell_items_cached')) {
    /**
     * Redis cache wrapper for top upsell items (limit affects output).
     * key = metric + restaurant_id + days
     */
    function get_top_upsell_items_cached(int $restaurantId, int $days = 30, int $limit = 5): array
    {
        $restaurantId = (int)$restaurantId;
        $days = max(1, min(180, (int)$days));
        $limit = max(1, min(20, (int)$limit));
        $ttl = 600; // 10 min

        // Important: include limit in metric name to keep cache correct.
        $cacheKey = 'upsell_top_items_' . $limit . ':' . $restaurantId . ':' . $days;
        if (function_exists('cache_get')) {
            $cached = cache_get($cacheKey);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $data = get_top_upsell_items($restaurantId, $days, $limit);
        if (function_exists('cache_set')) {
            cache_set($cacheKey, $data, $ttl);
        }
        return $data;
    }
}

if (!function_exists('get_upsell_performance_breakdown')) {
    /**
     * @return array<string,array{count:int,revenue:float,share_percent:float}>
     */
    function get_upsell_performance_breakdown(int $restaurantId, int $days = 30): array
    {
        $ctx = upsell_analytics_build_context($restaurantId, $days);
        $lines = $ctx['upsell_lines'] ?? [];
        $totalRev = (float)($ctx['upsell_revenue'] ?? 0);
        if (!$lines || $totalRev <= 0) {
            return [];
        }

        $orderHasDrink = $ctx['order_has_drink'] ?? [];
        $orderHasSide  = $ctx['order_has_side'] ?? [];

        $types = [
            'rule_linked' => ['count' => 0, 'revenue' => 0.0],
            'same_category' => ['count' => 0, 'revenue' => 0.0],
            'drink_completion' => ['count' => 0, 'revenue' => 0.0],
            'side_completion' => ['count' => 0, 'revenue' => 0.0],
            'fallback' => ['count' => 0, 'revenue' => 0.0],
        ];

        foreach ($lines as $l) {
            $oid = (int)($l['order_id'] ?? 0);
            $qty = (int)($l['qty'] ?? 0);
            $rev = (float)($l['revenue'] ?? 0);
            if ($oid <= 0 || $qty <= 0 || $rev <= 0) continue;

            $type = 'fallback';
            if (!empty($l['has_same_category'])) {
                $type = 'same_category';
            } elseif (!empty($l['has_rule_linked'])) {
                $type = 'rule_linked';
            } else {
                $isDrink = !empty($l['is_drink']);
                $isSide  = !empty($l['is_side']);
                $hasDrink = !empty($orderHasDrink[$oid]);
                $hasSide  = !empty($orderHasSide[$oid]);

                if ($isDrink && !$hasDrink) {
                    $type = 'drink_completion';
                } elseif ($isSide && !$hasSide) {
                    $type = 'side_completion';
                } else {
                    $type = 'fallback';
                }
            }

            $types[$type]['count'] += $qty;
            $types[$type]['revenue'] += $rev;
        }

        $out = [];
        foreach ($types as $t => $v) {
            $share = $totalRev > 0 ? ($v['revenue'] / $totalRev) * 100.0 : 0.0;
            $out[$t] = [
                'count' => (int)$v['count'],
                'revenue' => (float)$v['revenue'],
                'share_percent' => (float)$share,
            ];
        }
        return $out;
    }
}

if (!function_exists('get_upsell_performance_breakdown_cached')) {
    /**
     * Redis cache wrapper for upsell breakdown.
     * key = metric + restaurant_id + days
     */
    function get_upsell_performance_breakdown_cached(int $restaurantId, int $days = 30): array
    {
        $restaurantId = (int)$restaurantId;
        $days = max(1, min(180, (int)$days));
        $ttl = 600; // 10 min

        $cacheKey = 'upsell_breakdown:' . $restaurantId . ':' . $days;
        if (function_exists('cache_get')) {
            $cached = cache_get($cacheKey);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $data = get_upsell_performance_breakdown($restaurantId, $days);
        if (function_exists('cache_set')) {
            cache_set($cacheKey, $data, $ttl);
        }
        return $data;
    }
}

if (!function_exists('get_upsell_revenue_trend')) {
    /**
     * @return array<int,array{date:string,upsell_revenue:float,orders_with_upsell:int}>
     */
    function get_upsell_revenue_trend(int $restaurantId, int $days = 30): array
    {
        $ctx = upsell_analytics_build_context($restaurantId, $days);
        $lines = $ctx['upsell_lines'] ?? [];
        if (!$lines) {
            return [];
        }
        $byDate = [];
        $ordersByDate = [];
        foreach ($lines as $l) {
            $d = (string)($l['order_date'] ?? '');
            $oid = (int)($l['order_id'] ?? 0);
            $rev = (float)($l['revenue'] ?? 0);
            if ($d === '' || $oid <= 0 || $rev <= 0) continue;
            if (!isset($byDate[$d])) $byDate[$d] = 0.0;
            $byDate[$d] += $rev;
            if (!isset($ordersByDate[$d])) $ordersByDate[$d] = [];
            $ordersByDate[$d][$oid] = true;
        }
        ksort($byDate);
        $out = [];
        foreach ($byDate as $d => $rev) {
            $out[] = [
                'date' => $d,
                'upsell_revenue' => (float)$rev,
                'orders_with_upsell' => isset($ordersByDate[$d]) ? count($ordersByDate[$d]) : 0,
            ];
        }
        return $out;
    }
}

if (!function_exists('get_upsell_revenue_trend_cached')) {
    /**
     * Redis cache wrapper for upsell revenue trend.
     * key = metric + restaurant_id + days
     */
    function get_upsell_revenue_trend_cached(int $restaurantId, int $days = 30): array
    {
        $restaurantId = (int)$restaurantId;
        $days = max(1, min(180, (int)$days));
        $ttl = 600; // 10 min

        $cacheKey = 'upsell_trend:' . $restaurantId . ':' . $days;
        if (function_exists('cache_get')) {
            $cached = cache_get($cacheKey);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $data = get_upsell_revenue_trend($restaurantId, $days);
        if (function_exists('cache_set')) {
            cache_set($cacheKey, $data, $ttl);
        }
        return $data;
    }
}
