<?php
/**
 * Upsell Optimization (suggestion-based, safe).
 *
 * - Computes conversion stats for upsell pairs using upsell_events.
 * - Generates optimization suggestions (never auto-mutates rules).
 * - Can be used by dashboard widgets and manual approval UI.
 *
 * Data sources (if present):
 * - menu_upsell_rules (legacy/manual engine table)
 * - menu_item_upsells (restaurant UI table)
 * - upsell_events
 * - orders, order_items (optional for candidate ranking)
 */

if (file_exists(__DIR__ . '/cache.php')) {
    require_once __DIR__ . '/cache.php';
}

if (!function_exists('get_upsell_conversion_stats')) {
    /**
     * Compute conversion stats for upsell pairs from upsell_events.
     *
     * @return array{
     *   has_data: bool,
     *   window_days: int,
     *   pairs: array<int, array{
     *     base_item_id:int,
     *     upsell_item_id:int,
     *     shown:int,
     *     accepted:int,
     *     attach_rate:float,
     *     base_item_name?:string,
     *     upsell_item_name?:string
     *   }>,
     *   best_pair: ?array,
     *   weakest_pair: ?array
     * }
     */
    function get_upsell_conversion_stats(int $restaurantId): array
    {
        $restaurantId = (int) $restaurantId;
        $days = 30;

        if (function_exists('is_demo_mode') && is_demo_mode()) {
            return _upsell_optimization_demo_stats($days);
        }

        $cacheKey = 'upsell_opt_stats:' . $restaurantId . ':' . $days;
        if (function_exists('cache_get')) {
            $cached = cache_get($cacheKey);
            if (is_array($cached) && array_key_exists('pairs', $cached)) {
                return $cached;
            }
        }

        $out = [
            'has_data' => false,
            'window_days' => $days,
            'pairs' => [],
            'best_pair' => null,
            'weakest_pair' => null,
        ];

        if ($restaurantId <= 0 || !function_exists('db')) {
            return $out;
        }
        if (function_exists('db_table_exists') && !db_table_exists('upsell_events')) {
            return $out;
        }

        try {
            $pdo = db();
            $since = date('Y-m-d H:i:s', strtotime("-{$days} days"));

            // Pair-level stats (ignore upsell_events without both ids)
            $stmt = $pdo->prepare("
                SELECT
                    base_item_id,
                    upsell_item_id,
                    SUM(CASE WHEN event = 'shown' THEN 1 ELSE 0 END) AS shown_cnt,
                    SUM(CASE WHEN event = 'accepted_in_order' THEN 1 ELSE 0 END) AS accepted_cnt
                FROM upsell_events
                WHERE restaurant_id = ?
                  AND created_at >= ?
                  AND base_item_id IS NOT NULL
                  AND upsell_item_id IS NOT NULL
                GROUP BY base_item_id, upsell_item_id
                HAVING shown_cnt > 0
                ORDER BY accepted_cnt DESC, shown_cnt DESC
                LIMIT 80
            ");
            $stmt->execute([$restaurantId, $since]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows) {
                return $out;
            }

            $out['has_data'] = true;

            $baseIds = [];
            $upsellIds = [];
            foreach ($rows as $r) {
                $baseIds[] = (int) $r['base_item_id'];
                $upsellIds[] = (int) $r['upsell_item_id'];
            }
            $ids = array_values(array_unique(array_filter(array_merge($baseIds, $upsellIds))));

            $namesById = [];
            if ($ids !== [] && (function_exists('db_table_exists') ? db_table_exists('menu_items') : true)) {
                $ph = implode(',', array_fill(0, count($ids), '?'));
                $stmt2 = $pdo->prepare("SELECT id, name FROM menu_items WHERE restaurant_id = ? AND id IN ($ph)");
                $stmt2->execute(array_merge([$restaurantId], $ids));
                while ($r = $stmt2->fetch(PDO::FETCH_ASSOC)) {
                    $namesById[(int)$r['id']] = (string)$r['name'];
                }
            }

            $pairs = [];
            foreach ($rows as $r) {
                $shown = (int)($r['shown_cnt'] ?? 0);
                $accepted = (int)($r['accepted_cnt'] ?? 0);
                $rate = $shown > 0 ? round($accepted / $shown, 4) : 0.0;
                $baseId = (int)$r['base_item_id'];
                $upsellId = (int)$r['upsell_item_id'];
                $pairs[] = [
                    'base_item_id' => $baseId,
                    'upsell_item_id' => $upsellId,
                    'shown' => $shown,
                    'accepted' => $accepted,
                    'attach_rate' => $rate,
                    'base_item_name' => $namesById[$baseId] ?? ('ID ' . $baseId),
                    'upsell_item_name' => $namesById[$upsellId] ?? ('ID ' . $upsellId),
                ];
            }

            // Choose best/weakest with minimum volume thresholds for honesty
            $best = null;
            $weakest = null;
            foreach ($pairs as $p) {
                if ($p['shown'] >= 20) {
                    if ($best === null || ($p['attach_rate'] > $best['attach_rate'] && $p['accepted'] >= 3)) {
                        $best = $p;
                    }
                    if ($weakest === null || $p['attach_rate'] < $weakest['attach_rate']) {
                        $weakest = $p;
                    }
                }
            }
            $out['pairs'] = $pairs;
            $out['best_pair'] = $best;
            $out['weakest_pair'] = $weakest;
            if (function_exists('cache_set')) {
                cache_set($cacheKey, $out, 600); // 10 min
            }
            return $out;
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('upsell_optimization stats ' . $e->getMessage());
            }
            return $out;
        }
    }
}

if (!function_exists('rank_upsell_candidates')) {
    /**
     * Rank candidate upsell items for a base item using co-order frequency (order_items).
     * Returns pairs base→candidate with a score.
     *
     * @return array<int, array{base_item_id:int, upsell_item_id:int, score:int}>
     */
    function rank_upsell_candidates(int $restaurantId): array
    {
        $restaurantId = (int)$restaurantId;
        if (function_exists('is_demo_mode') && is_demo_mode()) {
            return [
                ['base_item_id' => 1, 'upsell_item_id' => 5, 'score' => 24],
                ['base_item_id' => 4, 'upsell_item_id' => 6, 'score' => 18],
            ];
        }
        if ($restaurantId <= 0 || !function_exists('db')) {
            return [];
        }
        if (function_exists('db_table_exists') && (!db_table_exists('orders') || !db_table_exists('order_items'))) {
            return [];
        }
        try {
            $pdo = db();
            $since = date('Y-m-d 00:00:00', strtotime('-90 days'));
            $stmt = $pdo->prepare("
                SELECT oi1.menu_item_id AS base_item_id, oi2.menu_item_id AS upsell_item_id, COUNT(*) AS score
                FROM order_items oi1
                INNER JOIN order_items oi2 ON oi1.order_id = oi2.order_id AND oi2.menu_item_id <> oi1.menu_item_id
                INNER JOIN orders o ON o.id = oi1.order_id AND o.restaurant_id = ?
                WHERE o.payment_status = 'paid'
                  AND o.order_status <> 'canceled'
                  AND o.created_at >= ?
                GROUP BY oi1.menu_item_id, oi2.menu_item_id
                ORDER BY score DESC
                LIMIT 120
            ");
            $stmt->execute([$restaurantId, $since]);
            $out = [];
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $out[] = [
                    'base_item_id' => (int)$r['base_item_id'],
                    'upsell_item_id' => (int)$r['upsell_item_id'],
                    'score' => (int)$r['score'],
                ];
            }
            return $out;
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('upsell_optimization rank ' . $e->getMessage());
            }
            return [];
        }
    }
}

if (!function_exists('get_upsell_optimization_suggestions')) {
    /**
     * Generate suggestion objects; does not write to DB.
     *
     * @return array{
     *   best_pair:?array,
     *   weakest_pair:?array,
     *   suggestions: array<int, array{type:string, title:string, description:string, payload:array, priority:string}>
     * }
     */
    function get_upsell_optimization_suggestions(int $restaurantId): array
    {
        $restaurantId = (int)$restaurantId;

        if (function_exists('is_demo_mode') && is_demo_mode()) {
            return _upsell_optimization_demo_suggestions();
        }

        $cacheKey = 'upsell_opt_suggestions:' . $restaurantId;
        if (function_exists('cache_get')) {
            $cached = cache_get($cacheKey);
            if (is_array($cached) && array_key_exists('suggestions', $cached)) {
                return $cached;
            }
        }

        $stats = get_upsell_conversion_stats($restaurantId);
        $pairs = $stats['pairs'] ?? [];

        $best = $stats['best_pair'] ?? null;
        $weak = $stats['weakest_pair'] ?? null;

        $suggestions = [];

        if (is_array($best) && ($best['shown'] ?? 0) >= 20 && ($best['accepted'] ?? 0) >= 3) {
            $suggestions[] = [
                'type' => 'upsell_priority_change',
                'priority' => 'medium',
                'title' => 'Increase priority: ' . ($best['base_item_name'] ?? 'Item') . ' → ' . ($best['upsell_item_name'] ?? 'Item'),
                'description' => 'This pair has a strong attach rate (' . round(100 * (float)$best['attach_rate'], 1) . '%) on ' . (int)$best['shown'] . ' shows. Consider increasing weight/priority.',
                'payload' => [
                    'base_item_id' => (int)$best['base_item_id'],
                    'upsell_item_id' => (int)$best['upsell_item_id'],
                    'recommended_weight_delta' => 50,
                ],
            ];
        }

        if (is_array($weak) && ($weak['shown'] ?? 0) >= 20) {
            $suggestions[] = [
                'type' => 'upsell_disable',
                'priority' => 'low',
                'title' => 'Review / disable: ' . ($weak['base_item_name'] ?? 'Item') . ' → ' . ($weak['upsell_item_name'] ?? 'Item'),
                'description' => 'Weak attach rate (' . round(100 * (float)$weak['attach_rate'], 1) . '%) on ' . (int)$weak['shown'] . ' shows. Consider disabling or replacing the suggestion.',
                'payload' => [
                    'base_item_id' => (int)$weak['base_item_id'],
                    'upsell_item_id' => (int)$weak['upsell_item_id'],
                ],
            ];
        }

        // New pair suggestion: take high-frequency co-order candidate that isn't already present in menu_item_upsells.
        $candidatePairs = rank_upsell_candidates($restaurantId);
        $ruleStates = _upsell_optimization_rule_state_map($restaurantId);
        foreach ($candidatePairs as $c) {
            $b = (int)($c['base_item_id'] ?? 0);
            $u = (int)($c['upsell_item_id'] ?? 0);
            $score = (int)($c['score'] ?? 0);
            if ($b <= 0 || $u <= 0 || $b === $u || $score < 8) {
                continue;
            }
            $key = $b . ':' . $u;
            if (isset($existing[$key])) {
                continue;
            }
            $names = _upsell_optimization_item_names($restaurantId, [$b, $u]);
            $suggestions[] = [
                'type' => 'upsell_new_pair',
                'priority' => 'low',
                'title' => 'Add new upsell pair: ' . ($names[$b] ?? ('ID ' . $b)) . ' → ' . ($names[$u] ?? ('ID ' . $u)),
                'description' => 'These items appear together frequently in paid orders (score ' . $score . '). Consider adding as an upsell rule.',
                'payload' => [
                    'base_item_id' => $b,
                    'upsell_item_id' => $u,
                    'recommended_weight' => 100,
                ],
            ];
            break;
        }

        // Replace weak drink with stronger one (heuristic): if weak upsell item is in drinks category name and there is a better pair for same base.
        if (is_array($weak)) {
            $baseId = (int)($weak['base_item_id'] ?? 0);
            $weakUpsell = (int)($weak['upsell_item_id'] ?? 0);
            $bestForBase = null;
            foreach ($pairs as $p) {
                if ((int)$p['base_item_id'] !== $baseId || (int)$p['upsell_item_id'] === $weakUpsell) {
                    continue;
                }
                if (($p['shown'] ?? 0) < 20 || ($p['accepted'] ?? 0) < 3) {
                    continue;
                }
                if ($bestForBase === null || (float)$p['attach_rate'] > (float)$bestForBase['attach_rate']) {
                    $bestForBase = $p;
                }
            }
            if ($bestForBase) {
                $suggestions[] = [
                    'type' => 'upsell_new_pair',
                    'priority' => 'low',
                    'title' => 'Replace weak suggestion with stronger pair',
                    'description' => 'For ' . ($weak['base_item_name'] ?? 'base item') . ', consider suggesting ' . ($bestForBase['upsell_item_name'] ?? 'item') . ' instead of ' . ($weak['upsell_item_name'] ?? 'item') . '.',
                    'payload' => [
                        'base_item_id' => $baseId,
                        'disable_upsell_item_id' => $weakUpsell,
                        'upsell_item_id' => (int)$bestForBase['upsell_item_id'],
                        'recommended_weight' => 120,
                    ],
                ];
            }
        }

        return [
            'best_pair' => $best,
            'weakest_pair' => $weak,
            'suggestions' => array_slice($suggestions, 0, 6),
        ];
        if (function_exists('cache_set')) {
            cache_set($cacheKey, $result, 600); // 10 min
        }
        return $result;
    }
}

if (!function_exists('get_upsell_optimization_dashboard')) {
    /**
     * Product-readable optimization layer for owner analytics.
     *
     * @return array{
     *   summary: array{
     *     pairs_analyzed:int,
     *     strong_pairs:int,
     *     weak_pairs:int,
     *     low_data_pairs:int
     *   },
     *   top_performing: array<int,array<string,mixed>>,
     *   needs_attention: array<int,array<string,mixed>>,
     *   low_data: array<int,array<string,mixed>>
     * }
     */
    function get_upsell_optimization_dashboard(int $restaurantId): array
    {
        $restaurantId = (int)$restaurantId;
        $stats = get_upsell_conversion_stats($restaurantId);
        $pairs = is_array($stats['pairs'] ?? null) ? $stats['pairs'] : [];
        $ruleStates = _upsell_optimization_rule_state_map($restaurantId);

        $result = [
            'summary' => [
                'pairs_analyzed' => 0,
                'strong_pairs' => 0,
                'weak_pairs' => 0,
                'low_data_pairs' => 0,
            ],
            'top_performing' => [],
            'needs_attention' => [],
            'low_data' => [],
        ];

        if ($pairs === []) {
            return $result;
        }

        foreach ($pairs as $pair) {
            $baseId = (int)($pair['base_item_id'] ?? 0);
            $upsellId = (int)($pair['upsell_item_id'] ?? 0);
            $shown = (int)($pair['shown'] ?? 0);
            $accepted = (int)($pair['accepted'] ?? 0);
            $rate = (float)($pair['attach_rate'] ?? 0);
            if ($baseId <= 0 || $upsellId <= 0 || $shown <= 0) {
                continue;
            }

            $ruleState = $ruleStates[$baseId . ':' . $upsellId] ?? null;
            $hasRule = is_array($ruleState);
            $ruleActive = $hasRule ? (int)($ruleState['active'] ?? 0) === 1 : false;
            $ruleWeight = $hasRule ? (int)($ruleState['weight'] ?? 0) : null;
            $ruleId = $hasRule ? (int)($ruleState['id'] ?? 0) : 0;
            $ruleStatusLabel = !$hasRule
                ? 'Правила ещё нет'
                : ($ruleActive ? 'Активна' : 'Отключена');
            $ruleSummaryText = !$hasRule
                ? 'Правило для пары ещё не создано'
                : ($ruleActive ? ('Активна · вес ' . $ruleWeight) : ('Отключена · вес ' . $ruleWeight));
            $row = [
                'base_item_id' => $baseId,
                'upsell_item_id' => $upsellId,
                'base_item_name' => (string)($pair['base_item_name'] ?? ('ID ' . $baseId)),
                'upsell_item_name' => (string)($pair['upsell_item_name'] ?? ('ID ' . $upsellId)),
                'shown' => $shown,
                'accepted' => $accepted,
                'attach_rate' => $rate,
                'attach_rate_pct' => round($rate * 100, 1),
                'has_rule' => $hasRule,
                'rule_exists' => $hasRule,
                'rule_id' => $ruleId,
                'rule_active' => $ruleActive,
                'rule_weight' => $ruleWeight,
                'rule_status_label' => $ruleStatusLabel,
                'rule_summary_text' => $ruleSummaryText,
                'status_text' => '',
                'action_text' => '',
                'tone' => 'slate',
            ];

            $result['summary']['pairs_analyzed']++;

            if ($shown < 20) {
                $row['status_text'] = 'Мало данных';
                $row['action_text'] = 'Пара ещё не набрала достаточно показов. Пока оставьте как есть и соберите больше данных.';
                $row['tone'] = 'slate';
                $result['summary']['low_data_pairs']++;
                $result['low_data'][] = $row;
                continue;
            }

            if ($rate >= 0.10 && $accepted >= 3) {
                $row['status_text'] = 'Работает хорошо';
                $row['action_text'] = $hasRule
                    ? 'Можно усилить: поднять вес правила или чаще использовать эту пару в меню.'
                    : 'Пара уже работает хорошо. Стоит закрепить её как явное правило допродажи.';
                $row['tone'] = 'emerald';
                $result['summary']['strong_pairs']++;
                $result['top_performing'][] = $row;
                continue;
            }

            if (($shown >= 40 && $accepted <= 1) || ($shown >= 25 && $rate < 0.04)) {
                $row['status_text'] = 'Часто показывается, редко добавляют';
                $row['action_text'] = $hasRule
                    ? 'Стоит протестировать другую пару или временно отключить это правило.'
                    : 'Пара выглядит слабой. Лучше заменить рекомендацию на более логичную.';
                $row['tone'] = 'amber';
                $result['summary']['weak_pairs']++;
                $result['needs_attention'][] = $row;
                continue;
            }

            if ($rate >= 0.06 && $accepted >= 2) {
                $row['status_text'] = 'Есть потенциал';
                $row['action_text'] = 'Пара уже показывает спрос. Дайте ей ещё трафика и проверьте результат через неделю.';
                $row['tone'] = 'sky';
                $result['top_performing'][] = $row;
                continue;
            }

            $row['status_text'] = 'Пока неубедительно';
            $row['action_text'] = 'Наблюдайте ещё немного. Если конверсия не вырастет, замените или ослабьте эту пару.';
            $row['tone'] = 'amber';
            $result['summary']['weak_pairs']++;
            $result['needs_attention'][] = $row;
        }

        usort($result['top_performing'], static function (array $a, array $b): int {
            $rateCmp = ($b['attach_rate'] <=> $a['attach_rate']);
            if ($rateCmp !== 0) {
                return $rateCmp;
            }
            return ($b['shown'] <=> $a['shown']);
        });
        usort($result['needs_attention'], static function (array $a, array $b): int {
            $shownCmp = ($b['shown'] <=> $a['shown']);
            if ($shownCmp !== 0) {
                return $shownCmp;
            }
            return ($a['attach_rate'] <=> $b['attach_rate']);
        });
        usort($result['low_data'], static function (array $a, array $b): int {
            return ($b['shown'] <=> $a['shown']);
        });

        $result['top_performing'] = array_slice($result['top_performing'], 0, 5);
        $result['needs_attention'] = array_slice($result['needs_attention'], 0, 5);
        $result['low_data'] = array_slice($result['low_data'], 0, 5);

        return $result;
    }
}

if (!function_exists('upsell_pair_exists_in_rules')) {
    function upsell_pair_exists_in_rules(int $restaurantId, int $baseItemId, int $upsellItemId): bool
    {
        $restaurantId = (int)$restaurantId;
        $baseItemId = (int)$baseItemId;
        $upsellItemId = (int)$upsellItemId;
        if ($restaurantId <= 0 || $baseItemId <= 0 || $upsellItemId <= 0 || !function_exists('db')) {
            return false;
        }
        if (function_exists('db_table_exists') && !db_table_exists('menu_item_upsells')) {
            return false;
        }
        try {
            $stmt = db()->prepare("SELECT 1 FROM menu_item_upsells WHERE restaurant_id = ? AND base_item_id = ? AND upsell_item_id = ? LIMIT 1");
            $stmt->execute([$restaurantId, $baseItemId, $upsellItemId]);
            return (bool)$stmt->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('_upsell_optimization_rule_state_map')) {
    /**
     * @return array<string,array{id:int,active:int,weight:int}>
     */
    function _upsell_optimization_rule_state_map(int $restaurantId): array
    {
        $restaurantId = (int)$restaurantId;
        $out = [];
        if ($restaurantId <= 0 || !function_exists('db')) {
            return $out;
        }
        if (function_exists('db_table_exists') && !db_table_exists('menu_item_upsells')) {
            return $out;
        }
        try {
            $pdo = db();
            $stmt = $pdo->prepare("SELECT id, base_item_id, upsell_item_id, active, weight FROM menu_item_upsells WHERE restaurant_id = ?");
            $stmt->execute([$restaurantId]);
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $b = (int)($r['base_item_id'] ?? 0);
                $u = (int)($r['upsell_item_id'] ?? 0);
                if ($b <= 0 || $u <= 0) {
                    continue;
                }
                $out[$b . ':' . $u] = [
                    'id' => (int)($r['id'] ?? 0),
                    'active' => (int)($r['active'] ?? 0),
                    'weight' => (int)($r['weight'] ?? 0),
                ];
            }
        } catch (Throwable $e) {
            return $out;
        }
        return $out;
    }
}

if (!function_exists('upsell_quick_boost_pair')) {
    /**
     * @return array{success:bool,message:string}
     */
    function upsell_quick_boost_pair(int $restaurantId, int $baseItemId, int $upsellItemId, int $delta = 50): array
    {
        $restaurantId = (int)$restaurantId;
        $baseItemId = (int)$baseItemId;
        $upsellItemId = (int)$upsellItemId;
        $delta = max(10, min(300, (int)$delta));

        if ($restaurantId <= 0 || $baseItemId <= 0 || $upsellItemId <= 0 || $baseItemId === $upsellItemId) {
            return ['success' => false, 'message' => 'Некорректная пара для усиления.'];
        }
        if (!function_exists('db')) {
            return ['success' => false, 'message' => 'База данных недоступна.'];
        }

        try {
            $pdo = db();
            $stmt = $pdo->prepare("SELECT id FROM menu_items WHERE id IN (?, ?) AND restaurant_id = ? AND available = 1");
            $stmt->execute([$baseItemId, $upsellItemId, $restaurantId]);
            $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
            if (count($ids) < 2) {
                return ['success' => false, 'message' => 'Оба блюда должны принадлежать ресторану и быть доступны.'];
            }

            $exists = $pdo->prepare("SELECT id, weight FROM menu_item_upsells WHERE restaurant_id = ? AND base_item_id = ? AND upsell_item_id = ? LIMIT 1");
            $exists->execute([$restaurantId, $baseItemId, $upsellItemId]);
            $row = $exists->fetch(PDO::FETCH_ASSOC);
            if ($row && isset($row['id'])) {
                $newWeight = max(0, min(1000, (int)($row['weight'] ?? 0) + $delta));
                $upd = $pdo->prepare("UPDATE menu_item_upsells SET weight = ?, active = 1, updated_at = NOW() WHERE id = ? AND restaurant_id = ?");
                $upd->execute([$newWeight, (int)$row['id'], $restaurantId]);
                return ['success' => true, 'message' => 'Пара усилена: вес правила увеличен.'];
            }

            $ins = $pdo->prepare("INSERT INTO menu_item_upsells (restaurant_id, base_item_id, upsell_item_id, weight, active) VALUES (?, ?, ?, ?, 1)");
            $ins->execute([$restaurantId, $baseItemId, $upsellItemId, 150]);
            return ['success' => true, 'message' => 'Создано новое правило допродажи с повышенным весом.'];
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('upsell_quick_boost_pair ' . $e->getMessage());
            }
            return ['success' => false, 'message' => 'Не удалось усилить пару. Попробуйте позже.'];
        }
    }
}

if (!function_exists('upsell_quick_disable_pair')) {
    /**
     * @return array{success:bool,message:string}
     */
    function upsell_quick_disable_pair(int $restaurantId, int $baseItemId, int $upsellItemId): array
    {
        $restaurantId = (int)$restaurantId;
        $baseItemId = (int)$baseItemId;
        $upsellItemId = (int)$upsellItemId;

        if ($restaurantId <= 0 || $baseItemId <= 0 || $upsellItemId <= 0) {
            return ['success' => false, 'message' => 'Некорректная пара для отключения.'];
        }
        if (!function_exists('db')) {
            return ['success' => false, 'message' => 'База данных недоступна.'];
        }

        try {
            $stmt = db()->prepare("UPDATE menu_item_upsells SET active = 0, updated_at = NOW() WHERE restaurant_id = ? AND base_item_id = ? AND upsell_item_id = ?");
            $stmt->execute([$restaurantId, $baseItemId, $upsellItemId]);
            if ($stmt->rowCount() > 0) {
                return ['success' => true, 'message' => 'Пара отключена в правилах допродаж.'];
            }
            return ['success' => false, 'message' => 'Активное правило для этой пары не найдено.'];
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('upsell_quick_disable_pair ' . $e->getMessage());
            }
            return ['success' => false, 'message' => 'Не удалось отключить пару. Попробуйте позже.'];
        }
    }
}

function _upsell_optimization_existing_pairs(int $restaurantId): array
{
    $restaurantId = (int)$restaurantId;
    $out = [];
    if ($restaurantId <= 0 || !function_exists('db')) {
        return $out;
    }
    if (function_exists('db_table_exists') && !db_table_exists('menu_item_upsells')) {
        return $out;
    }
    try {
        $pdo = db();
        $stmt = $pdo->prepare("SELECT base_item_id, upsell_item_id FROM menu_item_upsells WHERE restaurant_id = ?");
        $stmt->execute([$restaurantId]);
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $b = (int)($r['base_item_id'] ?? 0);
            $u = (int)($r['upsell_item_id'] ?? 0);
            if ($b > 0 && $u > 0) {
                $out[$b . ':' . $u] = true;
            }
        }
    } catch (Throwable $e) {
        return $out;
    }
    return $out;
}

function _upsell_optimization_item_names(int $restaurantId, array $itemIds): array
{
    $restaurantId = (int)$restaurantId;
    $itemIds = array_values(array_unique(array_map('intval', array_filter($itemIds))));
    $out = [];
    if ($restaurantId <= 0 || $itemIds === [] || !function_exists('db')) {
        return $out;
    }
    if (function_exists('db_table_exists') && !db_table_exists('menu_items')) {
        return $out;
    }
    try {
        $pdo = db();
        $ph = implode(',', array_fill(0, count($itemIds), '?'));
        $stmt = $pdo->prepare("SELECT id, name FROM menu_items WHERE restaurant_id = ? AND id IN ($ph)");
        $stmt->execute(array_merge([$restaurantId], $itemIds));
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $out[(int)$r['id']] = (string)$r['name'];
        }
    } catch (Throwable $e) {
        return $out;
    }
    return $out;
}

function _upsell_optimization_demo_stats(int $days): array
{
    $best = [
        'base_item_id' => 5,
        'upsell_item_id' => 15,
        'shown' => 120,
        'accepted' => 18,
        'attach_rate' => 0.15,
        'base_item_name' => 'Стейк рибай 250 г',
        'upsell_item_name' => 'Капучино',
    ];
    $weak = [
        'base_item_id' => 7,
        'upsell_item_id' => 13,
        'shown' => 80,
        'accepted' => 1,
        'attach_rate' => 0.0125,
        'base_item_name' => 'Паста карбонара',
        'upsell_item_name' => 'Лимонад домашний 0,5 л',
    ];
    return [
        'has_data' => true,
        'window_days' => $days,
        'pairs' => [$best, $weak],
        'best_pair' => $best,
        'weakest_pair' => $weak,
    ];
}

function _upsell_optimization_demo_suggestions(): array
{
    $stats = _upsell_optimization_demo_stats(30);
    return [
        'best_pair' => $stats['best_pair'],
        'weakest_pair' => $stats['weakest_pair'],
        'suggestions' => [
            [
                'type' => 'upsell_priority_change',
                'priority' => 'medium',
                'title' => 'Повысить приоритет: стейк → капучино',
                'description' => 'Сильная конверсия допродажи (15%) при 120 показах — можно поднять вес правила.',
                'payload' => ['base_item_id' => 5, 'upsell_item_id' => 15, 'recommended_weight_delta' => 50],
            ],
            [
                'type' => 'upsell_disable',
                'priority' => 'low',
                'title' => 'Пересмотреть: паста карбонара → лимонад',
                'description' => 'Низкая конверсия (~1,3%) при 80 показах — заменить предложение или отключить.',
                'payload' => ['base_item_id' => 7, 'upsell_item_id' => 13],
            ],
            [
                'type' => 'upsell_new_pair',
                'priority' => 'low',
                'title' => 'Новая пара: бургер → крылья BBQ',
                'description' => 'Часто заказывают вместе в оплаченных чеках — стоит добавить как правило.',
                'payload' => ['base_item_id' => 9, 'upsell_item_id' => 10, 'recommended_weight' => 100],
            ],
        ],
    ];
}

/**
 * Publish upsell optimization suggestions into growth_engine_suggestions (safe; suggestions only).
 * Uses growth_engine_suggestion_duplicate_exists() for dedupe (7 days).
 *
 * @return int created suggestions count
 */
function upsell_optimization_publish_growth_suggestions(int $restaurantId): int
{
    $restaurantId = (int)$restaurantId;
    if ($restaurantId <= 0) {
        return 0;
    }
    if (function_exists('is_demo_mode') && is_demo_mode()) {
        return 0;
    }
    if (!function_exists('db') || (function_exists('db_table_exists') && !db_table_exists('growth_engine_suggestions'))) {
        return 0;
    }
    if (!function_exists('db_column_exists') || !db_column_exists('growth_engine_suggestions', 'status')) {
        return 0;
    }

    $payloads = get_upsell_optimization_suggestions($restaurantId);
    $list = $payloads['suggestions'] ?? [];
    if (empty($list)) {
        return 0;
    }
    try {
        $pdo = db();
        $created = 0;
        foreach (array_slice($list, 0, 6) as $s) {
            $type = (string)($s['type'] ?? '');
            if (!in_array($type, ['upsell_priority_change', 'upsell_disable', 'upsell_new_pair'], true)) {
                continue;
            }
            $title = (string)($s['title'] ?? 'Upsell suggestion');
            $desc = (string)($s['description'] ?? '');
            $payloadJson = json_encode(($s['payload'] ?? []), JSON_UNESCAPED_UNICODE);
            if ($payloadJson === false) {
                $payloadJson = '{}';
            }
            if (function_exists('growth_engine_suggestion_duplicate_exists')) {
                $skip = growth_engine_suggestion_duplicate_exists($pdo, $restaurantId, $type, $payloadJson, $title);
                if ($skip) {
                    continue;
                }
            }
            $priority = (string)($s['priority'] ?? 'low');
            $prio = in_array($priority, ['high','medium','low'], true) ? $priority : 'low';
            $stmt = $pdo->prepare("
                INSERT INTO growth_engine_suggestions (restaurant_id, type, title, description, payload_json, priority, source, status)
                VALUES (?, ?, ?, ?, ?, ?, 'upsell_optimization', 'pending')
            ");
            $stmt->execute([$restaurantId, $type, $title, $desc, $payloadJson, $prio]);
            $created++;
        }
        return $created;
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('upsell_optimization publish ' . $e->getMessage());
        }
        return 0;
    }
}
