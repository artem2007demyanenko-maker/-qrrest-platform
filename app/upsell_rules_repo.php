<?php
/**
 * Smart Upsell Rules v2: filter and limit candidate upsell items.
 */

/**
 * @return array<array{id:int, rule_type:string, rule_value:?string, priority:int, active:int}>
 */
function upsell_rules_get(int $restaurantId): array
{
    try {
        $pdo = db();
        $stmt = $pdo->prepare("
            SELECT id, restaurant_id, rule_type, rule_value, priority, active, created_at
            FROM upsell_rules
            WHERE restaurant_id = ? AND active = 1
            ORDER BY priority ASC, id ASC
        ");
        $stmt->execute([$restaurantId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('upsell_rules_get ' . $e->getMessage());
        return [];
    }
}

/**
 * Apply rules to candidate items. Candidates must have 'id' and 'category_id' (int|null).
 * Options: session_views (int), order_upsell_count (int).
 * @param array $baseItemIds
 * @param array $cartItemIds
 * @param array $candidateUpsellItems list of arrays with at least id, category_id
 * @param array $options ['session_views' => int, 'order_upsell_count' => int]
 * @return array
 */
function upsell_rules_apply(
    int $restaurantId,
    array $baseItemIds,
    array $cartItemIds,
    array $candidateUpsellItems,
    array $options = []
): array {
    $baseItemIds = array_map('intval', array_filter($baseItemIds));
    $cartItemIds = array_map('intval', array_filter($cartItemIds));
    $excludeIds = array_unique(array_merge($baseItemIds, $cartItemIds));
    $out = [];
    foreach ($candidateUpsellItems as $item) {
        $id = isset($item['id']) ? (int)$item['id'] : 0;
        if ($id <= 0 || in_array($id, $excludeIds, true)) {
            continue;
        }
        $out[] = $item;
    }

    try {
        $rules = upsell_rules_get($restaurantId);
        if ($rules === []) {
            return $out;
        }

        $pdo = db();
        $baseCategories = [];
        if ($baseItemIds !== []) {
            $ph = implode(',', array_fill(0, count($baseItemIds), '?'));
            $stmt = $pdo->prepare("SELECT id, category_id FROM menu_items WHERE id IN ($ph) AND restaurant_id = ?");
            $stmt->execute(array_merge($baseItemIds, [$restaurantId]));
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $baseCategories[(int)$row['id']] = isset($row['category_id']) ? (int)$row['category_id'] : null;
            }
        }

        foreach ($rules as $rule) {
            $type = $rule['rule_type'] ?? '';
            $value = $rule['rule_value'] ?? '';

            if ($type === 'exclude_same_category' && $baseCategories !== []) {
                $baseCats = array_unique(array_filter(array_values($baseCategories)));
                $out = array_filter($out, function ($item) use ($baseCats) {
                    $cat = isset($item['category_id']) ? (int)$item['category_id'] : null;
                    return $cat === null || !in_array($cat, $baseCats, true);
                });
                $out = array_values($out);
            }

            if ($type === 'limit_per_session') {
                $n = (int)$value;
                if ($n > 0) {
                    $views = (int)($options['session_views'] ?? 0);
                    if ($views >= $n) {
                        return [];
                    }
                }
            }

            if ($type === 'limit_per_order') {
                $n = (int)$value;
                if ($n > 0) {
                    $added = (int)($options['order_upsell_count'] ?? 0);
                    if ($added >= $n) {
                        return [];
                    }
                    $out = array_slice($out, 0, $n - $added);
                }
            }

            if ($type === 'max_items_to_show') {
                $n = (int)$value;
                if ($n > 0) {
                    $out = array_slice($out, 0, $n);
                }
            }
        }
    } catch (Throwable $e) {
        error_log('upsell_rules_apply ' . $e->getMessage());
    }

    return $out;
}
