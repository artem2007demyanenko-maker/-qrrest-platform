<?php
/**
 * Upsell Decision Engine: score-based selection (deterministic, tenant-safe, no writes).
 */

require_once __DIR__ . '/db.php';
if (file_exists(__DIR__ . '/schema_guard.php')) {
    require_once __DIR__ . '/schema_guard.php';
}
require_once __DIR__ . '/upsell_repo.php';
if (file_exists(__DIR__ . '/upsell_rules_repo.php')) {
    require_once __DIR__ . '/upsell_rules_repo.php';
}
if (file_exists(__DIR__ . '/combo_builder.php')) {
    require_once __DIR__ . '/combo_builder.php';
}
if (file_exists(__DIR__ . '/helpers.php')) {
    require_once __DIR__ . '/helpers.php';
}

if (!function_exists('upsell_engine_normalize_cart')) {
    /**
     * @param array<int|string,int|float> $cartItems id => qty
     * @return array<int,int>
     */
    function upsell_engine_normalize_cart(array $cartItems): array
    {
        $out = [];
        foreach ($cartItems as $id => $qty) {
            $i = (int)$id;
            if ($i <= 0) {
                continue;
            }
            $q = (int)max(0, (float)$qty);
            if ($q > 0) {
                $out[$i] = ($out[$i] ?? 0) + $q;
            }
        }
        return $out;
    }
}

if (!function_exists('upsell_engine_classify_text')) {
    /**
     * @return array{
     *   is_drink:bool,is_side:bool,is_sauce:bool,is_dessert:bool,
     *   is_meat:bool,is_burger:bool,is_pasta:bool,is_soup:bool,is_bread:bool,is_fries:bool,is_coffee:bool
     * }
     */
    function upsell_engine_classify_text(string $name, ?string $categoryName = null): array
    {
        $nameLc = mb_strtolower($name, 'UTF-8');
        $catLc = mb_strtolower((string)($categoryName ?? ''), 'UTF-8');
        $hay = $catLc !== '' ? $nameLc . ' ' . $catLc : $nameLc;
        $hasIn = function (string $str, array $words): bool {
            foreach ($words as $w) {
                if ($w !== '' && mb_strpos($str, $w, 0, 'UTF-8') !== false) {
                    return true;
                }
            }
            return false;
        };

        // Prefer category-level matches (more stable than item-name fragments).
        $drinkCat = ['напит', 'бар', 'drink', 'beverage', 'coffee', 'tea'];
        $sideCat = ['гарнир', 'закус', 'side', 'starter', 'salad'];
        $sauceCat = ['соус', 'sauce', 'dip'];
        $dessertCat = ['десерт', 'dessert', 'sweet', 'кондитер'];

        $drinkName = ['лимонад', 'cola', 'кола', 'coffee', 'кофе', 'чай', 'tea', 'juice', 'сок', 'вода', 'морс'];
        $sideName = ['гарнир', 'картофель', 'potato', 'рис ', 'рис-', 'rice ', 'салат'];
        $sauceName = ['соус', 'sauce', 'dip'];
        $dessertName = ['десерт', 'dessert', 'морожен', 'ice cream', 'торт', 'cake', 'пирож', 'эклер'];

        $meatWords = ['стейк', 'steak', 'мясо', 'beef', 'говядин', 'свинин', 'баранин', 'котлет', 'шашлык', 'ribs', 'рибай', 't-bone', 'tbone', 'курин', 'chicken', 'утк', 'duck', 'индейк', 'turkey'];
        $burgerWords = ['бургер', 'burger'];
        $pastaWords = ['паста', 'pasta', 'спагетти', 'spaghetti', 'лазанья', 'lasagna', 'пенне', 'penne', 'феттучини', 'ризотто', 'risotto'];
        $soupWords = ['суп', 'soup', 'борщ', 'бульон', 'уха', 'солянк'];
        $breadWords = ['хлеб', 'bread', 'багет', 'baguette', 'лаваш', 'фокачча', 'булк'];
        $friesWords = ['картошка фри', 'фри', 'fries', 'картофель фри', 'наггетс', 'nuggets'];
        $coffeeWords = ['кофе', 'coffee', 'эспрессо', 'espresso', 'капучино', 'cappuccino', 'латте', 'latte', 'американо'];

        $isDrink = ($catLc !== '' && $hasIn($catLc, $drinkCat)) || $hasIn($nameLc, $drinkName);
        $isSide = ($catLc !== '' && $hasIn($catLc, $sideCat)) || $hasIn($nameLc, $sideName);
        $isSauce = ($catLc !== '' && $hasIn($catLc, $sauceCat)) || $hasIn($nameLc, $sauceName);
        $isDessert = ($catLc !== '' && $hasIn($catLc, $dessertCat)) || $hasIn($nameLc, $dessertName);

        $isMeat = $hasIn($hay, $meatWords);
        $isBurger = $hasIn($hay, $burgerWords);
        $isPasta = $hasIn($hay, $pastaWords);
        $isSoup = $hasIn($hay, $soupWords);
        $isBread = $hasIn($hay, $breadWords);
        $isFries = $hasIn($hay, $friesWords);
        $isCoffee = $hasIn($hay, $coffeeWords);

        return [
            'is_drink' => $isDrink,
            'is_side' => $isSide,
            'is_sauce' => $isSauce,
            'is_dessert' => $isDessert,
            'is_meat' => $isMeat,
            'is_burger' => $isBurger,
            'is_pasta' => $isPasta,
            'is_soup' => $isSoup,
            'is_bread' => $isBread,
            'is_fries' => $isFries,
            'is_coffee' => $isCoffee,
        ];
    }
}

if (!function_exists('upsell_engine_load_item_rows')) {
    /**
     * @param array<int> $ids
     * @return array<int,array<string,mixed>>
     */
    function upsell_engine_load_item_rows(PDO $pdo, int $restaurantId, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids === [] || $restaurantId <= 0) {
            return [];
        }
        $hasCat = function_exists('db_table_exists') && db_table_exists('menu_categories');
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $join = $hasCat
            ? 'LEFT JOIN menu_categories c ON c.id = m.category_id AND c.restaurant_id = m.restaurant_id'
            : '';
        $catSel = $hasCat ? ', c.name AS category_name' : ', NULL AS category_name';
        $sql = "SELECT m.id, m.name, m.price, m.image_path, m.description, m.category_id{$catSel}
                FROM menu_items m
                {$join}
                WHERE m.restaurant_id = ? AND m.available = 1 AND m.id IN ($ph)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge([$restaurantId], $ids));
        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $map[(int)$row['id']] = $row;
        }
        return $map;
    }
}

if (!function_exists('upsell_engine_popularity_ranks')) {
    /**
     * @return array<int,int> menu_item_id => rank (1 = most ordered)
     */
    function upsell_engine_popularity_ranks(PDO $pdo, int $restaurantId, int $limit = 30): array
    {
        if ($restaurantId <= 0) {
            return [];
        }
        if (!function_exists('db_column_exists') || !db_column_exists('order_items', 'menu_item_id')) {
            return [];
        }
        try {
            $stmt = $pdo->prepare("
                SELECT oi.menu_item_id AS mid, COUNT(*) AS c
                FROM order_items oi
                INNER JOIN orders o ON o.id = oi.order_id AND o.restaurant_id = ?
                WHERE oi.menu_item_id IS NOT NULL AND oi.menu_item_id > 0
                GROUP BY oi.menu_item_id
                ORDER BY c DESC
                LIMIT " . (int)$limit . "
            ");
            $stmt->execute([$restaurantId]);
            $rank = 0;
            $out = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $rank++;
                $out[(int)$row['mid']] = $rank;
            }
            return $out;
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('upsell_engine_popularity_ranks ' . $e->getMessage());
            }
            return [];
        }
    }
}

if (!function_exists('extract_cart_context')) {
    /**
     * @param array<int|string,int|float> $cartItems
     * @param array<int> $additionalBaseItemIds order context bases (not necessarily in cart)
     * @return array{
     *   cart_item_ids:array<int>,
     *   cart_qty_by_id:array<int,int>,
     *   cart_total:float,
     *   category_ids:array<int>,
     *   item_line_count:int,
     *   total_qty:int,
     *   has_drink:bool,
     *   has_side:bool,
     *   has_meat:bool,
     *   has_burger:bool,
     *   has_pasta:bool,
     *   has_soup:bool,
     *   has_dessert:bool,
     *   base_item_ids:array<int>
     * }
     */
    function extract_cart_context(int $restaurantId, array $cartItems, array $additionalBaseItemIds = []): array
    {
        $restaurantId = (int)$restaurantId;
        $qtyById = upsell_engine_normalize_cart($cartItems);
        $cartIds = array_keys($qtyById);
        $baseIds = array_values(array_unique(array_filter(array_map('intval', $additionalBaseItemIds))));
        $allIds = array_values(array_unique(array_merge($cartIds, $baseIds)));

        $pdo = db();
        $rows = upsell_engine_load_item_rows($pdo, $restaurantId, $allIds);

        $total = 0.0;
        $cats = [];
        $hasDrink = false;
        $hasSide = false;
        $hasMeat = false;
        $hasBurger = false;
        $hasPasta = false;
        $hasSoup = false;
        $hasDessertCtx = false;
        $tq = 0;

        foreach ($qtyById as $id => $q) {
            $tq += $q;
            if (!isset($rows[$id])) {
                continue;
            }
            $r = $rows[$id];
            $total += (float)($r['price'] ?? 0) * $q;
            $cid = isset($r['category_id']) ? (int)$r['category_id'] : 0;
            if ($cid > 0) {
                $cats[$cid] = true;
            }
            $cls = upsell_engine_classify_text((string)($r['name'] ?? ''), isset($r['category_name']) ? (string)$r['category_name'] : null);
            if (!empty($cls['is_drink'])) {
                $hasDrink = true;
            }
            if (!empty($cls['is_side'])) {
                $hasSide = true;
            }
            if (!empty($cls['is_meat'])) {
                $hasMeat = true;
            }
            if (!empty($cls['is_burger'])) {
                $hasBurger = true;
            }
            if (!empty($cls['is_pasta'])) {
                $hasPasta = true;
            }
            if (!empty($cls['is_soup'])) {
                $hasSoup = true;
            }
            if (!empty($cls['is_dessert'])) {
                $hasDessertCtx = true;
            }
        }

        foreach ($baseIds as $bid) {
            if (isset($rows[$bid])) {
                $r = $rows[$bid];
                $cid = isset($r['category_id']) ? (int)$r['category_id'] : 0;
                if ($cid > 0) {
                    $cats[$cid] = true;
                }
            }
        }

        return [
            'cart_item_ids' => $cartIds,
            'cart_qty_by_id' => $qtyById,
            'cart_total' => round($total, 2),
            'category_ids' => array_keys($cats),
            'item_line_count' => count($qtyById),
            'total_qty' => $tq,
            'has_drink' => $hasDrink,
            'has_side' => $hasSide,
            'has_meat' => $hasMeat,
            'has_burger' => $hasBurger,
            'has_pasta' => $hasPasta,
            'has_soup' => $hasSoup,
            'has_dessert' => $hasDessertCtx,
            'base_item_ids' => array_values(array_unique(array_merge($cartIds, $baseIds))),
        ];
    }
}

if (!function_exists('upsell_engine_row_to_candidate')) {
    /**
     * @param array<string,mixed> $row
     * @param array<string,bool> $flags
     */
    function upsell_engine_row_to_candidate(array $row, array $flags): array
    {
        $name = (string)($row['name'] ?? '');
        $catn = isset($row['category_name']) ? (string)$row['category_name'] : null;
        $cls = upsell_engine_classify_text($name, $catn);
        return array_merge([
            'id' => (int)($row['id'] ?? 0),
            'name' => $name,
            'price' => (float)($row['price'] ?? 0),
            'image' => function_exists('menu_item_image_url') ? menu_item_image_url($row) : null,
            'description' => (string)($row['description'] ?? ''),
            'category_id' => isset($row['category_id']) ? (int)$row['category_id'] : null,
            'category_name' => $catn ?? '',
            'popularity_rank' => null,
        ], $cls, $flags);
    }
}

if (!function_exists('upsell_engine_combo_candidates')) {
    /**
     * Independent split upsell combo-layer (menu/cart): build candidates from combo_rules.
     * Fallback-safe: if combo table/helper is missing or empty, returns [] and legacy engine continues.
     *
     * @param array<int|string,int|float> $cartItems
     * @param array<int,bool> $excludeIds
     * @return array<int,array<string,mixed>> keyed by item id
     */
    function upsell_engine_combo_candidates(
        PDO $pdo,
        int $restaurantId,
        array $cartItems,
        array $excludeIds,
        ?int $triggerItemId = null
    ): array {
        if (!function_exists('get_combos')) {
            return [];
        }
        $comboRules = get_combos($restaurantId, $cartItems);
        if ($comboRules === []) {
            return [];
        }

        $scoreByItem = [];
        foreach ($comboRules as $rule) {
            $rulePriority = (int)($rule['priority'] ?? 100);
            $ruleTrigger = (int)($rule['trigger_item_id'] ?? 0);
            $ids = $rule['suggested_item_ids'] ?? [];
            if (!is_array($ids) || $ids === []) {
                continue;
            }
            foreach ($ids as $sidRaw) {
                $sid = (int)$sidRaw;
                if ($sid <= 0 || isset($excludeIds[$sid])) {
                    continue;
                }
                $score = $rulePriority;
                // For menu one-tap (trigger item is known), boost exact trigger combo.
                if ($triggerItemId !== null && $triggerItemId > 0 && $ruleTrigger === $triggerItemId) {
                    $score += 1000;
                }
                $prev = $scoreByItem[$sid] ?? PHP_INT_MIN;
                if ($score > $prev) {
                    $scoreByItem[$sid] = $score;
                }
            }
        }
        if ($scoreByItem === []) {
            return [];
        }

        arsort($scoreByItem, SORT_NUMERIC);
        $idsOrdered = array_keys($scoreByItem);
        $rows = upsell_engine_load_item_rows($pdo, $restaurantId, $idsOrdered);
        if ($rows === []) {
            return [];
        }

        $out = [];
        foreach ($idsOrdered as $sid) {
            $id = (int)$sid;
            if ($id <= 0 || !isset($rows[$id])) {
                continue;
            }
            $c = upsell_engine_row_to_candidate($rows[$id], ['rule_linked' => true, 'same_category' => false]);
            $c['source'] = 'combo';
            $c['combo_score'] = (int)($scoreByItem[$id] ?? 0);
            $out[$id] = $c;
        }

        return $out;
    }
}

if (!function_exists('score_upsell')) {
    /**
     * score = relevance (0–5) + cart_completion (0–3) + popularity (0–2)
     *
     * @param array<string,mixed> $candidate
     * @param array<string,mixed> $context from extract_cart_context
     */
    function score_upsell(array $candidate, array $context): int
    {
        $rel = 0;
        if (!empty($candidate['rule_linked'])) {
            $rel = 5;
        } elseif (!empty($candidate['same_category'])) {
            $rel = 2;
        }

        // Rule-based meal pairing (v1 heuristics; manual rules still win via rule_linked above).
        if (!empty($context['has_burger']) && !empty($candidate['is_fries'])) {
            $rel = max($rel, 4);
        }
        if (!empty($context['has_burger']) && empty($context['has_drink']) && !empty($candidate['is_drink'])) {
            $rel = max($rel, 4);
        }
        if (!empty($context['has_meat']) && !empty($candidate['is_sauce'])) {
            $rel = max($rel, 4);
        }
        if (!empty($context['has_meat']) && empty($context['has_side']) && !empty($candidate['is_side'])) {
            $rel = max($rel, 3);
        }
        if (!empty($context['has_pasta']) && empty($context['has_drink']) && !empty($candidate['is_drink'])) {
            $rel = max($rel, 3);
        }
        if (!empty($context['has_pasta']) && empty($context['has_dessert']) && !empty($candidate['is_dessert'])) {
            $rel = max($rel, 3);
        }
        if (!empty($context['has_dessert']) && !empty($candidate['is_coffee'])) {
            $rel = max($rel, 4);
        }
        if (!empty($context['has_soup']) && !empty($candidate['is_bread'])) {
            $rel = max($rel, 4);
        }
        if (!empty($context['has_soup']) && empty($context['has_drink']) && !empty($candidate['is_drink'])) {
            $rel = max($rel, 3);
        }

        $cc = 0;
        if (empty($context['has_drink']) && !empty($candidate['is_drink'])) {
            $cc = 3;
        } elseif (empty($context['has_side']) && !empty($candidate['is_side'])) {
            $cc = 2;
        }

        $pop = 0;
        $pr = isset($candidate['popularity_rank']) ? (int)$candidate['popularity_rank'] : 0;
        if ($pr === 1) {
            $pop = 2;
        } elseif ($pr >= 2 && $pr <= 3) {
            $pop = 1;
        } elseif ($pr >= 4 && $pr <= 10) {
            $pop = 1;
        }

        $convAdj = 0;
        $shown = (int)($candidate['conversion_shown'] ?? 0);
        $rate = (float)($candidate['conversion_rate'] ?? 0.0);

        // Don't penalize/boost too early (noise).
        if ($shown >= 10) {
            if ($rate >= 0.25) {
                $convAdj = 2;
            } elseif ($rate >= 0.15) {
                $convAdj = 1;
            } elseif ($rate <= 0.03) {
                $convAdj = -1;
            }
        }

        return max(0, $rel + $cc + $pop + $convAdj);
    }
}

if (!function_exists('upsell_engine_conversion_rates')) {
    /**
     * conversion_rate = upsell_added_to_cart / upsell_shown
     *
     * @return array<int,array{shown:int,added:int,conversion_rate:float}>
     */
    function upsell_engine_conversion_rates(PDO $pdo, int $restaurantId, array $upsellItemIds, int $days = 30): array
    {
        $restaurantId = (int)$restaurantId;
        $upsellItemIds = array_values(array_unique(array_filter(array_map('intval', $upsellItemIds))));
        $days = max(1, min(365, (int)$days));

        $out = [];
        if ($restaurantId <= 0 || $upsellItemIds === []) {
            return $out;
        }

        if (!function_exists('db_table_exists') || !db_table_exists('upsell_events')) {
            return $out;
        }
        if (function_exists('db_column_exists')) {
            $needs = ['upsell_item_id', 'event', 'created_at', 'restaurant_id'];
            foreach ($needs as $col) {
                if (!db_column_exists('upsell_events', $col)) {
                    return $out;
                }
            }
        }

        $since = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        $ph = implode(',', array_fill(0, count($upsellItemIds), '?'));
        $stmt = $pdo->prepare("
            SELECT
                upsell_item_id AS item_id,
                SUM(CASE WHEN event = 'upsell_shown' THEN 1 ELSE 0 END) AS shown_cnt,
                SUM(CASE WHEN event = 'upsell_added_to_cart' THEN 1 ELSE 0 END) AS added_cnt
            FROM upsell_events
            WHERE restaurant_id = ?
              AND created_at >= ?
              AND upsell_item_id IS NOT NULL
              AND upsell_item_id IN ($ph)
              AND event IN ('upsell_shown', 'upsell_added_to_cart')
            GROUP BY upsell_item_id
        ");
        $params = array_merge([$restaurantId, $since], $upsellItemIds);
        $stmt->execute($params);

        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $iid = (int)($r['item_id'] ?? 0);
            if ($iid <= 0) continue;
            $shown = (int)($r['shown_cnt'] ?? 0);
            $added = (int)($r['added_cnt'] ?? 0);
            $rate = ($shown > 0) ? ($added / $shown) : 0.0;
            $out[$iid] = [
                'shown' => $shown,
                'added' => $added,
                'conversion_rate' => (float)$rate,
            ];
        }

        return $out;
    }
}

if (!function_exists('upsell_engine_collect_candidates')) {
    /**
     * @param array<string,mixed> $ctx
     * @return array<int,array<string,mixed>> keyed by id
     */
    function upsell_engine_collect_candidates(PDO $pdo, int $restaurantId, array $ctx, array $popRanks, array $modes = []): array
    {
        $baseIds = $ctx['base_item_ids'] ?? [];
        $cartIds = $ctx['cart_item_ids'] ?? [];
        $exclude = array_unique(array_merge($cartIds, $baseIds));
        $candidates = [];
        $allowManual = !isset($modes['allow_manual']) || (bool)$modes['allow_manual'];
        $allowContextual = !isset($modes['allow_contextual']) || (bool)$modes['allow_contextual'];
        $allowPopular = !isset($modes['allow_popular_fallback']) || (bool)$modes['allow_popular_fallback'];

        if ($allowManual && $baseIds !== [] && upsell_table_exists()) {
            $linked = upsell_get_for_base_items($restaurantId, $baseIds, 40);
            foreach ($linked as $row) {
                $id = (int)$row['id'];
                if ($id <= 0 || in_array($id, $exclude, true)) {
                    continue;
                }
                $full = upsell_engine_load_item_rows($pdo, $restaurantId, [$id]);
                $r = $full[$id] ?? $row;
                $c = upsell_engine_row_to_candidate($r, ['rule_linked' => true, 'same_category' => false]);
                $c['source'] = 'manual';
                $c['popularity_rank'] = $popRanks[$id] ?? null;
                $candidates[$id] = $c;
            }
        }

        $baseCats = [];
        if ($baseIds !== []) {
            $rows = upsell_engine_load_item_rows($pdo, $restaurantId, $baseIds);
            foreach ($rows as $r) {
                $cid = isset($r['category_id']) ? (int)$r['category_id'] : 0;
                if ($cid > 0) {
                    $baseCats[$cid] = true;
                }
            }
        }
        $catList = array_keys($baseCats);
        if ($allowContextual && $catList !== [] && function_exists('db_table_exists') && db_table_exists('menu_categories')) {
            $ph = implode(',', array_fill(0, count($catList), '?'));
            $stmt = $pdo->prepare("
                SELECT m.id, m.name, m.price, m.image_path, m.description, m.category_id,
                       c.name AS category_name
                FROM menu_items m
                LEFT JOIN menu_categories c ON c.id = m.category_id AND c.restaurant_id = m.restaurant_id
                WHERE m.restaurant_id = ? AND m.available = 1 AND m.category_id IN ($ph)
                ORDER BY m.id DESC
                LIMIT 40
            ");
            $stmt->execute(array_merge([$restaurantId], $catList));
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $id = (int)$r['id'];
                if ($id <= 0 || in_array($id, $exclude, true) || isset($candidates[$id])) {
                    continue;
                }
                $c = upsell_engine_row_to_candidate($r, ['rule_linked' => false, 'same_category' => true]);
                $c['source'] = 'contextual';
                $c['popularity_rank'] = $popRanks[$id] ?? null;
                $candidates[$id] = $c;
            }
        }

        $hasMenuCat = function_exists('db_table_exists') && db_table_exists('menu_categories');
        $sqlFb = $hasMenuCat
            ? "SELECT m.id, m.name, m.price, m.image_path, m.description, m.category_id,
                      c.name AS category_name
               FROM menu_items m
               LEFT JOIN menu_categories c ON c.id = m.category_id AND c.restaurant_id = m.restaurant_id
               WHERE m.restaurant_id = ? AND m.available = 1
               ORDER BY m.id DESC
               LIMIT 120"
            : "SELECT m.id, m.name, m.price, m.image_path, m.description, m.category_id,
                      NULL AS category_name
               FROM menu_items m
               WHERE m.restaurant_id = ? AND m.available = 1
               ORDER BY m.id DESC
               LIMIT 120";
        if ($allowContextual) {
            $stmt = $pdo->prepare($sqlFb);
            $stmt->execute([$restaurantId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $id = (int)$r['id'];
                if ($id <= 0 || in_array($id, $exclude, true) || isset($candidates[$id])) {
                    continue;
                }
                $cand = upsell_engine_row_to_candidate($r, ['rule_linked' => false, 'same_category' => false]);
                $cls = [
                    'is_drink' => $cand['is_drink'],
                    'is_side' => $cand['is_side'],
                    'is_sauce' => $cand['is_sauce'],
                    'is_dessert' => $cand['is_dessert'],
                ];
                $want = false;
                if (empty($ctx['has_drink']) && !empty($cls['is_drink'])) {
                    $want = true;
                }
                if (!empty($cls['is_sauce'])) {
                    $want = true;
                }
                if (!empty($cls['is_dessert'])) {
                    $want = true;
                }
                if (!$want) {
                    continue;
                }
                $cand['source'] = 'contextual';
                $cand['popularity_rank'] = $popRanks[$id] ?? null;
                $candidates[$id] = $cand;
            }
        }

        if ($allowPopular && $candidates === []) {
            $fb = upsell_fallback_popular($restaurantId, 20);
            foreach ($fb as $row) {
                $id = (int)$row['id'];
                if ($id <= 0 || in_array($id, $exclude, true)) {
                    continue;
                }
                $full = upsell_engine_load_item_rows($pdo, $restaurantId, [$id]);
                $r = $full[$id] ?? $row;
                $c = upsell_engine_row_to_candidate($r, ['rule_linked' => false, 'same_category' => false]);
                $c['source'] = 'popular';
                $c['popularity_rank'] = $popRanks[$id] ?? null;
                $candidates[$id] = $c;
            }
        }

        return $candidates;
    }
}

if (!function_exists('upsell_engine_reason_text')) {
    function upsell_engine_reason_text(array $candidate, array $context): string
    {
        if (($candidate['source'] ?? '') === 'combo') {
            return 'Комбо к вашему заказу';
        }
        if (!empty($candidate['rule_linked'])) {
            return 'Часто берут вместе';
        }
        if (!empty($candidate['same_category'])) {
            return 'Подходит к вашему заказу';
        }
        if (!empty($context['has_meat']) && (!empty($candidate['is_sauce']) || (!empty($candidate['is_side']) && empty($context['has_side'])))) {
            return 'Хорошо сочетается с основным блюдом';
        }
        if (!empty($context['has_burger']) && !empty($candidate['is_fries'])) {
            return 'Фри к бургеру';
        }
        if (!empty($context['has_burger']) && !empty($candidate['is_drink']) && empty($context['has_drink'])) {
            return 'Напиток к заказу';
        }
        if (!empty($context['has_pasta']) && !empty($candidate['is_drink']) && empty($context['has_drink'])) {
            return 'Напиток к основному';
        }
        if (!empty($context['has_pasta']) && !empty($candidate['is_dessert']) && empty($context['has_dessert'])) {
            return 'Десерт после основного';
        }
        if (!empty($context['has_soup']) && !empty($candidate['is_bread'])) {
            return 'Хлеб к супу';
        }
        if (!empty($context['has_dessert']) && !empty($candidate['is_coffee'])) {
            return 'К десерту часто берут кофе';
        }
        if (!empty($candidate['is_side']) && empty($context['has_side'])) {
            return 'Гарнир к основному';
        }
        if (!empty($candidate['is_drink']) && empty($context['has_drink'])) {
            return 'Напиток к заказу';
        }
        if (!empty($candidate['is_sauce'])) {
            return 'Соус к блюду';
        }
        if (!empty($candidate['is_dessert'])) {
            return 'Десерт после основного';
        }
        $pr = isset($candidate['popularity_rank']) ? (int)$candidate['popularity_rank'] : 0;
        if ($pr > 0) {
            return 'Популярное дополнение';
        }
        return 'Подходит к вашему заказу';
    }
}

if (!function_exists('upsell_engine_is_contextual_candidate')) {
    /**
     * Keep guest upsell contextual and non-intrusive.
     * Rejects overly generic or too-expensive suggestions unless they come from explicit manual rules.
     *
     * @param array<string,mixed> $candidate
     * @param array<string,mixed> $context
     */
    function upsell_engine_is_contextual_candidate(array $candidate, array $context): bool
    {
        $price = (float)($candidate['price'] ?? 0);
        if ($price <= 0) {
            return false;
        }

        if (!empty($candidate['rule_linked'])) {
            return true;
        }

        $contextual = false;

        if (!empty($candidate['same_category'])) {
            $contextual = true;
        }
        if (!empty($candidate['is_drink']) && empty($context['has_drink'])) {
            $contextual = true;
        }
        if (!empty($candidate['is_side']) && empty($context['has_side']) && (!empty($context['has_meat']) || !empty($context['has_burger']))) {
            $contextual = true;
        }
        if (!empty($candidate['is_sauce']) && !empty($context['has_meat'])) {
            $contextual = true;
        }
        if (!empty($candidate['is_dessert']) && (!empty($context['has_meat']) || !empty($context['has_burger']) || !empty($context['has_pasta']) || !empty($context['has_soup']))) {
            $contextual = true;
        }
        if (!empty($candidate['is_coffee']) && !empty($context['has_dessert'])) {
            $contextual = true;
        }
        if (!empty($candidate['is_bread']) && !empty($context['has_soup'])) {
            $contextual = true;
        }

        if (!$contextual) {
            return false;
        }

        $cartTotal = (float)($context['cart_total'] ?? 0);
        if ($cartTotal > 0) {
            $softMax = max(350.0, round($cartTotal * 0.80, 2));
            if ($price > $softMax) {
                return false;
            }
        }

        return true;
    }
}

if (!function_exists('upsell_engine_finalize')) {
    /**
     * @param array<int,array{item:array<string,mixed>,score:int}> $scored
     * @return array<int,array<string,mixed>>
     */
    function upsell_engine_finalize(
        int $restaurantId,
        array $context,
        array $scored,
        int $sessionViews,
        int $orderUpsellCount,
        int $maxOut = 3
    ): array {
        uasort($scored, function ($a, $b) {
            $sa = (int)($a['score'] ?? 0);
            $sb = (int)($b['score'] ?? 0);
            if ($sa !== $sb) {
                return $sb <=> $sa;
            }
            return ((int)($a['item']['id'] ?? 0)) <=> ((int)($b['item']['id'] ?? 0));
        });

        $list = [];
        foreach ($scored as $pack) {
            $list[] = $pack['item'];
        }

        $baseIds = $context['base_item_ids'] ?? [];
        $cartIds = $context['cart_item_ids'] ?? [];

        if (function_exists('upsell_rules_apply')) {
            $list = upsell_rules_apply(
                $restaurantId,
                $baseIds,
                $cartIds,
                $list,
                ['session_views' => $sessionViews, 'order_upsell_count' => $orderUpsellCount]
            );
        }

        // Re-rank post-rule pool (rules may alter ordering and truncation).
        $post = [];
        foreach ($list as $row) {
            $id = (int)($row['id'] ?? 0);
            if ($id <= 0 || !isset($scored[$id])) {
                continue;
            }
            $post[$id] = ['item' => $scored[$id]['item'], 'score' => (int)$scored[$id]['score']];
        }
        uasort($post, function ($a, $b) {
            $sa = (int)($a['score'] ?? 0);
            $sb = (int)($b['score'] ?? 0);
            if ($sa !== $sb) {
                return $sb <=> $sa;
            }
            return ((int)($a['item']['id'] ?? 0)) <=> ((int)($b['item']['id'] ?? 0));
        });
        $list = [];
        foreach ($post as $pack) {
            $list[] = $pack['item'];
        }

        $out = [];
        foreach ($list as $row) {
            $id = (int)($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $cand = isset($scored[$id]['item']) ? $scored[$id]['item'] : $row;
            $reason = upsell_engine_reason_text($cand, $context);
            $img = $row['image'] ?? ($cand['image'] ?? null);
            $out[] = [
                'id' => $id,
                'item_id' => $id,
                'name' => (string)($row['name'] ?? $cand['name'] ?? ''),
                'price' => (float)($row['price'] ?? $cand['price'] ?? 0),
                'image' => $img,
                'description' => (string)($row['description'] ?? $cand['description'] ?? ''),
                'reason' => $reason,
            ];
            if (count($out) >= $maxOut) {
                break;
            }
        }

        return $out;
    }
}

if (!function_exists('get_contextual_upsells')) {
    /**
     * After add-to-cart / QR offers: best upsells for current cart (+ optional order bases).
     *
     * @param array<int|string,int|float> $cartItems
     * @param array<int> $additionalBaseItemIds
     * @return array<int,array{id:int,name:string,price:float,image:?string,description:string,reason:string}>
     */
    function get_contextual_upsells(
        int $restaurantId,
        array $cartItems,
        array $additionalBaseItemIds = [],
        int $sessionViews = 0,
        int $orderUpsellCount = 0,
        int $maxOut = 3,
        array $modes = []
    ): array {
        $restaurantId = (int)$restaurantId;
        $maxOut = max(1, min(6, $maxOut));
        if ($restaurantId <= 0) {
            return [];
        }
        $pdo = db();
        $ctx = extract_cart_context($restaurantId, $cartItems, $additionalBaseItemIds);
        if (($ctx['base_item_ids'] ?? []) === []) {
            return [];
        }

        $allowContextual = !isset($modes['allow_contextual']) || (bool)$modes['allow_contextual'];
        $allowPopular = !isset($modes['allow_popular_fallback']) || (bool)$modes['allow_popular_fallback'];
        $pop = upsell_engine_popularity_ranks($pdo, $restaurantId);
        $cands = upsell_engine_collect_candidates($pdo, $restaurantId, $ctx, $pop, $modes);
        foreach ($cands as $id => $candidate) {
            $src = (string)($candidate['source'] ?? '');
            if ($src === 'manual') {
                continue;
            }
            if ($src === 'popular') {
                if (!$allowPopular || (float)($candidate['price'] ?? 0) <= 0) {
                    unset($cands[$id]);
                }
                continue;
            }
            if (!$allowContextual || !upsell_engine_is_contextual_candidate($candidate, $ctx)) {
                unset($cands[$id]);
            }
        }
        if ($cands === []) {
            return [];
        }
        $convMap = upsell_engine_conversion_rates(
            $pdo,
            $restaurantId,
            array_keys($cands),
            30
        );
        $scored = [];
        foreach ($cands as $id => $c) {
            if (isset($convMap[$id])) {
                $c['conversion_shown'] = (int)($convMap[$id]['shown'] ?? 0);
                $c['conversion_rate'] = (float)($convMap[$id]['conversion_rate'] ?? 0.0);
            } else {
                $c['conversion_shown'] = 0;
                $c['conversion_rate'] = 0.0;
            }
            $sc = score_upsell($c, $ctx);
            $scored[$id] = ['item' => $c, 'score' => $sc];
        }

        $out = upsell_engine_finalize($restaurantId, $ctx, $scored, $sessionViews, $orderUpsellCount, $maxOut);
        return $out;
    }
}

if (!function_exists('get_cart_upsells')) {
    /**
     * Before checkout: only when cart_total is below threshold.
     *
     * @param array<int|string,int|float> $cartItems
     * @return array<int,array{id:int,name:string,price:float,image:?string,description:string,reason:string}>
     */
    function get_cart_upsells(
        int $restaurantId,
        float $cartTotal,
        array $cartItems,
        float $threshold = 1000.0,
        int $sessionViews = 0,
        int $orderUpsellCount = 0,
        int $maxOut = 3
    ): array {
        if ($cartTotal >= $threshold) {
            return [];
        }
        return get_contextual_upsells($restaurantId, $cartItems, [], $sessionViews, $orderUpsellCount, $maxOut);
    }
}

if (!function_exists('upsell_engine_demo_smart_suggestions')) {
    /**
     * @param array<int|string,int|float> $cartItems
     * @return list<array{id:int,name:string,price:float,image:?string,description:string,reason:string}>
     */
    function upsell_engine_demo_smart_suggestions(array $cartItems, int $limit): array
    {
        $limit = max(1, min(3, $limit));
        $inCart = [];
        foreach ($cartItems as $k => $q) {
            if ((int)$q > 0) {
                $inCart[(int)$k] = true;
            }
        }
        if (!function_exists('demo_menu_items')) {
            require_once __DIR__ . '/demo.php';
        }
        $out = [];
        foreach (demo_menu_items() as $row) {
            $id = (int)($row['id'] ?? 0);
            if ($id <= 0 || isset($inCart[$id])) {
                continue;
            }
            $out[] = [
                'id' => $id,
                'item_id' => $id,
                'name' => (string)($row['name'] ?? ''),
                'price' => (float)($row['price'] ?? 0),
                'image' => function_exists('menu_item_image_url') ? menu_item_image_url($row) : null,
                'description' => (string)($row['description'] ?? ''),
                'reason' => 'Популярное дополнение',
            ];
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }
}

if (!function_exists('get_smart_upsell')) {
    /**
     * Production smart upsell for QR: menu_upsell_rules (trigger) + engine (menu_item_upsells, rules, fallbacks).
     *
     * @param array<int|string,int|float> $cartItems id => qty
     * @return list<array{id:int,name:string,price:float,image:?string,description:string,reason:string}>
     */
    function get_smart_upsell(
        int $restaurantId,
        array $cartItems,
        int $limit = 3,
        ?int $triggerItemId = null,
        int $sessionViews = 0,
        int $orderUpsellCount = 0,
        array $modes = []
    ): array {
        $restaurantId = (int)$restaurantId;
        $limit = max(1, min(3, $limit));
        if ($restaurantId <= 0) {
            return [];
        }
        if (function_exists('is_demo_mode') && is_demo_mode()) {
            return upsell_engine_demo_smart_suggestions($cartItems, $limit);
        }

        $cartQty = [];
        foreach ($cartItems as $k => $q) {
            $i = (int)$k;
            if ($i > 0 && (float)$q > 0) {
                $cartQty[$i] = (int)$q;
            }
        }
        if ($cartQty === []) {
            return [];
        }

        $outById = [];
        $pdo = db();

        $allowManual = !isset($modes['allow_manual']) || (bool)$modes['allow_manual'];
        $allowCombo = !isset($modes['allow_combo']) || (bool)$modes['allow_combo'];
        $comboLimit = isset($modes['combo_limit']) ? (int)$modes['combo_limit'] : $limit;
        $comboLimit = max(0, min($limit, $comboLimit));
        // Split upsell independent combo-layer for menu/cart.
        // Combo is controlled by dedicated allow_combo/combo_limit, independent from allow_manual.
        if ($allowCombo && $comboLimit > 0) {
            $exclude = [];
            foreach ($cartQty as $cid => $_q) {
                $exclude[(int)$cid] = true;
            }
            foreach ($outById as $oid => $_row) {
                $exclude[(int)$oid] = true;
            }
            $comboCandidates = upsell_engine_combo_candidates($pdo, $restaurantId, $cartItems, $exclude, $triggerItemId);
            $comboAdded = 0;
            foreach ($comboCandidates as $id => $cand) {
                $iid = (int)$id;
                if ($iid <= 0 || isset($cartQty[$iid]) || isset($outById[$iid])) {
                    continue;
                }
                $outById[$iid] = [
                    'id' => $iid,
                    'item_id' => $iid,
                    'name' => (string)($cand['name'] ?? ''),
                    'price' => (float)($cand['price'] ?? 0),
                    'image' => $cand['image'] ?? null,
                    'description' => (string)($cand['description'] ?? ''),
                    'reason' => 'Комбо к вашему заказу',
                ];
                $comboAdded++;
                if ($comboAdded >= $comboLimit) {
                    break;
                }
                if (count($outById) >= $limit) {
                    return array_values($outById);
                }
            }
        }

        if ($allowManual && $triggerItemId !== null && $triggerItemId > 0 && function_exists('get_upsell_suggestions')) {
            foreach (get_upsell_suggestions($restaurantId, $triggerItemId, $limit) as $row) {
                $id = (int)($row['id'] ?? 0);
                if ($id <= 0 || isset($cartQty[$id]) || isset($outById[$id])) {
                    continue;
                }
                $outById[$id] = [
                    'id' => $id,
                    'item_id' => $id,
                    'name' => (string)($row['name'] ?? ''),
                    'price' => (float)($row['price'] ?? 0),
                    'image' => $row['image'] ?? null,
                    'description' => (string)($row['description'] ?? ''),
                    'reason' => 'Часто берут вместе',
                ];
                if (count($outById) >= $limit) {
                    return array_values($outById);
                }
            }
        }

        $need = $limit - count($outById);
        if ($need <= 0) {
            return array_values($outById);
        }

        $extra = [];
        if ($triggerItemId !== null && $triggerItemId > 0) {
            $extra[] = $triggerItemId;
        }

        $ctxList = get_contextual_upsells(
            $restaurantId,
            $cartItems,
            $extra,
            $sessionViews,
            $orderUpsellCount,
            max($need, $limit),
            $modes
        );
        foreach ($ctxList as $row) {
            $id = (int)($row['id'] ?? 0);
            if ($id <= 0 || isset($cartQty[$id]) || isset($outById[$id])) {
                continue;
            }
            $outById[$id] = $row;
            if (count($outById) >= $limit) {
                break;
            }
        }

        return array_slice(array_values($outById), 0, $limit);
    }
}

if (!function_exists('upsell_get_suggestions')) {
    /**
     * Public entry for guest/menu upsell (v1).
     *
     * Layers (in order of influence inside get_smart_upsell / engine):
     *  1) Manual restaurant rules — menu_upsell_rules, menu_item_upsells (highest priority when present)
     *  2) Association + cart heuristics — category/name taxonomy, score_upsell, popularity & upsell_events conversion
     *  3) TODO: AI recommendations — personalize from order history, live basket, category affinity; plug in here
     *     without changing the JSON shape (item_id, name, price, image, reason).
     *
     * @param array<int|string,int|float> $cartItems menu_item_id => qty
     * @param array<string,mixed> $options limit (1–3), trigger_item_id, session_views, order_upsell_count,
     *  allow_manual, allow_contextual, allow_popular_fallback, allow_combo, combo_limit
     * @return list<array{item_id:int,id:int,name:string,price:float,image:?string,description:string,reason:string}>
     */
    function upsell_get_suggestions(int $restaurantId, array $cartItems, array $options = []): array {
        $limit = max(1, min(3, (int)($options['limit'] ?? 3)));
        $tr = isset($options['trigger_item_id']) ? (int)$options['trigger_item_id'] : 0;
        $trigger = $tr > 0 ? $tr : null;
        $sess = (int)($options['session_views'] ?? 0);
        $ord = (int)($options['order_upsell_count'] ?? 0);

        $modes = [
            'allow_manual' => !isset($options['allow_manual']) || (bool)$options['allow_manual'],
            'allow_contextual' => !isset($options['allow_contextual']) || (bool)$options['allow_contextual'],
            'allow_popular_fallback' => !isset($options['allow_popular_fallback']) || (bool)$options['allow_popular_fallback'],
            'allow_combo' => !isset($options['allow_combo']) || (bool)$options['allow_combo'],
            'combo_limit' => isset($options['combo_limit']) ? (int)$options['combo_limit'] : $limit,
        ];
        $rows = get_smart_upsell($restaurantId, $cartItems, $limit, $trigger, $sess, $ord, $modes);
        foreach ($rows as $k => $row) {
            $id = (int)($row['id'] ?? 0);
            $rows[$k]['item_id'] = $id;
        }

        return $rows;
    }
}

if (!function_exists('upsell_context_items')) {
    /**
     * Unified cart/context builder for guest upsell entrypoints.
     *
     * @param array<int|string,int|float> $cartItems
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    function upsell_context_items(int $restaurantId, array $cartItems, array $options = []): array
    {
        $restaurantId = (int)$restaurantId;
        $baseIds = [];
        if (!empty($options['additional_base_item_ids']) && is_array($options['additional_base_item_ids'])) {
            $baseIds = array_values(array_unique(array_filter(array_map('intval', $options['additional_base_item_ids']))));
        }

        $ctx = extract_cart_context($restaurantId, $cartItems, $baseIds);

        $orderType = strtolower(trim((string)($options['order_type'] ?? 'hall')));
        if (!in_array($orderType, ['hall', 'delivery', 'pickup', 'preorder', 'manual'], true)) {
            $orderType = 'hall';
        }
        $ctx['order_type'] = $orderType;
        $ctx['cart_total'] = (float)($options['cart_total'] ?? ($ctx['cart_total'] ?? 0.0));
        $ctx['table_id'] = isset($options['table_id']) ? (int)$options['table_id'] : 0;

        return $ctx;
    }
}

if (!function_exists('upsell_available_items')) {
    /**
     * Returns available menu items only (tenant-safe, available=1).
     *
     * @param array<int> $itemIds
     * @return array<int,array<string,mixed>> keyed by menu_item_id
     */
    function upsell_available_items(int $restaurantId, array $itemIds): array
    {
        $restaurantId = (int)$restaurantId;
        if ($restaurantId <= 0) {
            return [];
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $itemIds))));
        if ($ids === []) {
            return [];
        }
        return upsell_engine_load_item_rows(db(), $restaurantId, $ids);
    }
}

if (!function_exists('upsell_score_item')) {
    /**
     * Public score wrapper for deterministic rule-based ranking.
     *
     * @param array<string,mixed> $candidate
     * @param array<string,mixed> $context
     */
    function upsell_score_item(array $candidate, array $context): int
    {
        $score = score_upsell($candidate, $context);
        $orderType = strtolower(trim((string)($context['order_type'] ?? 'hall')));

        // Lightweight contextual bias only; keep existing core engine semantics intact.
        if ($orderType === 'delivery' && !empty($candidate['is_drink'])) {
            $score += 1;
        }
        if ($orderType === 'pickup' && !empty($candidate['is_dessert'])) {
            $score += 1;
        }

        return max(0, (int)$score);
    }
}

if (!function_exists('upsell_recommendations')) {
    /**
     * Unified smart-rule recommendation entrypoint for guest flows.
     *
     * @param array<int|string,int|float> $cartItems
     * @param array<string,mixed> $options
     * @return list<array{id:int,item_id:int,name:string,price:float,image:?string,description:string,reason:string}>
     */
    function upsell_recommendations(int $restaurantId, array $cartItems, array $options = []): array
    {
        $restaurantId = (int)$restaurantId;
        if ($restaurantId <= 0) {
            return [];
        }

        $limit = max(1, min(3, (int)($options['limit'] ?? 3)));
        $sessionViews = (int)($options['session_views'] ?? 0);
        $orderUpsellCount = (int)($options['order_upsell_count'] ?? 0);
        $mode = strtolower(trim((string)($options['mode'] ?? 'context')));

        $ctx = upsell_context_items($restaurantId, $cartItems, $options);
        $baseIds = isset($ctx['base_item_ids']) && is_array($ctx['base_item_ids']) ? $ctx['base_item_ids'] : [];
        if ($baseIds === []) {
            return [];
        }

        $modes = [
            'allow_manual' => !isset($options['allow_manual']) || (bool)$options['allow_manual'],
            'allow_contextual' => !isset($options['allow_contextual']) || (bool)$options['allow_contextual'],
            'allow_popular_fallback' => !isset($options['allow_popular_fallback']) || (bool)$options['allow_popular_fallback'],
            'allow_combo' => !isset($options['allow_combo']) || (bool)$options['allow_combo'],
            'combo_limit' => isset($options['combo_limit']) ? (int)$options['combo_limit'] : $limit,
        ];

        if ($mode === 'cart') {
            $cartTotal = (float)($ctx['cart_total'] ?? 0.0);
            $threshold = isset($options['cart_threshold']) ? (float)$options['cart_threshold'] : 1000.0;
            $items = get_cart_upsells(
                $restaurantId,
                $cartTotal,
                $cartItems,
                $threshold,
                $sessionViews,
                $orderUpsellCount,
                $limit
            );
        } else {
            $items = get_smart_upsell(
                $restaurantId,
                $cartItems,
                $limit,
                isset($options['trigger_item_id']) ? (int)$options['trigger_item_id'] : null,
                $sessionViews,
                $orderUpsellCount,
                $modes
            );
        }

        if ($items === []) {
            return [];
        }

        // Availability hardening: only items currently available in menu_items.
        $ids = [];
        foreach ($items as $it) {
            $iid = (int)($it['id'] ?? $it['item_id'] ?? 0);
            if ($iid > 0) {
                $ids[] = $iid;
            }
        }
        $availableMap = upsell_available_items($restaurantId, $ids);
        if ($availableMap === []) {
            return [];
        }

        $out = [];
        foreach ($items as $it) {
            $iid = (int)($it['id'] ?? $it['item_id'] ?? 0);
            if ($iid <= 0 || !isset($availableMap[$iid])) {
                continue;
            }
            $menuRow = $availableMap[$iid];
            $out[] = [
                'id' => $iid,
                'item_id' => $iid,
                'name' => (string)($it['name'] ?? $menuRow['name'] ?? ''),
                'price' => (float)($it['price'] ?? $menuRow['price'] ?? 0),
                'image' => $it['image'] ?? (function_exists('menu_item_image_url') ? menu_item_image_url($menuRow) : null),
                'description' => (string)($it['description'] ?? $menuRow['description'] ?? ''),
                'reason' => (string)($it['reason'] ?? 'Подходит к вашему заказу'),
            ];
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }
}
