<?php
/**
 * AI upsell suggestions: analyze order history and suggest base → upsell item pairs.
 * Uses last 100 orders, item co-occurrence, and a 20% confidence threshold.
 * Does not modify existing upsell logic.
 */

/**
 * Get AI-driven upsell suggestions from order data.
 * If fewer than 20 orders: returns ['suggestions' => [], 'order_count' => N] so caller can show "More order data needed".
 *
 * @param int $restaurantId
 * @return array{suggestions: array<int, array{base_item: string, suggested_item: string, confidence: float, base_item_id: int, suggested_item_id: int}>, order_count: int}
 */
function get_upsell_suggestions(int $restaurantId): array
{
    $restaurantId = (int) $restaurantId;
    $minOrders = 20;
    $lastN = 100;
    $minConfidence = 0.20;
    $topN = 5;

    $pdo = db();

    // 1. Last N paid, non-canceled orders
    $stmt = $pdo->prepare("
        SELECT id
        FROM orders
        WHERE restaurant_id = :rest
          AND payment_status = 'paid'
          AND order_status <> 'canceled'
        ORDER BY created_at DESC
        LIMIT :limit
    ");
    $stmt->bindValue('rest', $restaurantId, PDO::PARAM_INT);
    $stmt->bindValue('limit', $lastN, PDO::PARAM_INT);
    $stmt->execute();
    $orderIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $orderCount = count($orderIds);

    if ($orderCount < $minOrders) {
        return ['suggestions' => [], 'order_count' => $orderCount];
    }

    if ($orderIds === []) {
        return ['suggestions' => [], 'order_count' => 0];
    }

    // 2. Order items: order_id => list of distinct menu_item_id (only valid items)
    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
    $stmt = $pdo->prepare("
        SELECT order_id, menu_item_id
        FROM order_items
        WHERE order_id IN ($placeholders)
          AND menu_item_id IS NOT NULL
          AND menu_item_id > 0
    ");
    $stmt->execute(array_values($orderIds));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $itemsByOrder = [];
    foreach ($rows as $r) {
        $oid = (int) $r['order_id'];
        $mid = (int) $r['menu_item_id'];
        if (!isset($itemsByOrder[$oid])) {
            $itemsByOrder[$oid] = [];
        }
        $itemsByOrder[$oid][$mid] = true;
    }

    // 3. Build unordered pairs per order; count each pair once per order
    $pairCounts = [];
    foreach ($itemsByOrder as $itemIds) {
        $ids = array_values(array_map('intval', array_keys($itemIds)));
        if (count($ids) < 2) {
            continue;
        }
        $seen = [];
        for ($i = 0; $i < count($ids); $i++) {
            for ($j = $i + 1; $j < count($ids); $j++) {
                $a = $ids[$i];
                $b = $ids[$j];
                $key = $a < $b ? "{$a}_{$b}" : "{$b}_{$a}";
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $base = $a < $b ? $a : $b;
                $suggested = $a < $b ? $b : $a;
                if (!isset($pairCounts[$key])) {
                    $pairCounts[$key] = ['base_item_id' => $base, 'suggested_item_id' => $suggested, 'orders_with_pair' => 0];
                }
                $pairCounts[$key]['orders_with_pair']++;
            }
        }
    }

    // 4. Confidence = (orders containing this pair) / orderCount; keep >= 20%
    $candidates = [];
    foreach ($pairCounts as $p) {
        $confidence = $p['orders_with_pair'] / $orderCount;
        if ($confidence >= $minConfidence) {
            $candidates[] = [
                'base_item_id' => $p['base_item_id'],
                'suggested_item_id' => $p['suggested_item_id'],
                'confidence' => round($confidence, 2),
            ];
        }
    }

    // Sort by confidence desc, take top 5
    usort($candidates, function ($x, $y) {
        return $y['confidence'] <=> $x['confidence'];
    });
    $candidates = array_slice($candidates, 0, $topN);

    // 5. Resolve names from menu_items (restaurant_id filter)
    $allIds = [];
    foreach ($candidates as $c) {
        $allIds[$c['base_item_id']] = true;
        $allIds[$c['suggested_item_id']] = true;
    }
    $idList = array_keys($allIds);
    if ($idList === []) {
        return ['suggestions' => [], 'order_count' => $orderCount];
    }

    $ph = implode(',', array_map('intval', $idList));
    $stmt = $pdo->prepare("
        SELECT id, name
        FROM menu_items
        WHERE id IN ($ph) AND restaurant_id = :rest
    ");
    $stmt->execute(['rest' => $restaurantId]);
    $names = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $names[(int) $row['id']] = (string) $row['name'];
    }

    $suggestions = [];
    foreach ($candidates as $c) {
        $baseName = $names[$c['base_item_id']] ?? ('ID ' . $c['base_item_id']);
        $suggestName = $names[$c['suggested_item_id']] ?? ('ID ' . $c['suggested_item_id']);
        $suggestions[] = [
            'base_item' => $baseName,
            'suggested_item' => $suggestName,
            'confidence' => $c['confidence'],
            'base_item_id' => $c['base_item_id'],
            'suggested_item_id' => $c['suggested_item_id'],
        ];
    }

    return ['suggestions' => $suggestions, 'order_count' => $orderCount];
}
