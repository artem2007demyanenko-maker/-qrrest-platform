<?php
/**
 * Growth Engine Architecture Layer (integration, not a rewrite).
 *
 * Purpose:
 * - Coordinate opportunities across existing modules:
 *   upsell optimization, guest return engine, menu intelligence, loyalty, restaurant health.
 * - Normalize into a unified opportunity schema.
 * - Rank by impact/confidence/ease and return top items.
 * - Optionally publish into growth_engine_suggestions with dedupe/cooldown (no auto-mutation).
 */

if (file_exists(__DIR__ . '/cache.php')) {
    require_once __DIR__ . '/cache.php';
}
if (file_exists(__DIR__ . '/flow_id.php')) {
    require_once __DIR__ . '/flow_id.php';
}

/**
 * Unified opportunity schema:
 * [
 *   {
 *     type, priority, title, description, source_module,
 *     estimated_impact, action_url
 *   }
 * ]
 */

if (!function_exists('growth_engine_arch_get_opportunities')) {
    /**
     * @return array<int, array{
     *   type:string,
     *   priority:string,
     *   title:string,
     *   description:string,
     *   source_module:string,
     *   estimated_impact:string,
     *   action_url:string,
     *   _rank:float
     * }>
     */
    function growth_engine_arch_get_opportunities(int $restaurantId, int $limit = 5): array
    {
        $restaurantId = (int)$restaurantId;
        $limit = max(1, min(20, (int)$limit));

        if (function_exists('is_demo_mode') && is_demo_mode()) {
            return array_slice(_growth_engine_arch_demo_opportunities(), 0, $limit);
        }

        $cacheKey = 'growth_arch_opps:' . $restaurantId . ':' . $limit;
        if (function_exists('cache_get')) {
            $cached = cache_get($cacheKey);
            if (is_array($cached) && isset($cached[0]['type'])) {
                return $cached;
            }
        }

        $opps = [];

        // Upsell optimization (conversion-based, suggestion-only)
        if (file_exists(__DIR__ . '/upsell_optimization.php')) {
            require_once __DIR__ . '/upsell_optimization.php';
            $uo = function_exists('get_upsell_optimization_suggestions') ? get_upsell_optimization_suggestions($restaurantId) : ['suggestions' => []];
            foreach (($uo['suggestions'] ?? []) as $s) {
                $type = (string)($s['type'] ?? '');
                if ($type === 'upsell_priority_change' || $type === 'upsell_disable' || $type === 'upsell_new_pair') {
                    $opps[] = _growth_engine_arch_opp_from_upsell_opt($s);
                }
            }
        }

        // Guest return engine (retention recovery opportunity)
        if (file_exists(__DIR__ . '/guest_return_engine.php')) {
            require_once __DIR__ . '/guest_return_engine.php';
            if (function_exists('get_guest_return_summary')) {
                $sum = get_guest_return_summary($restaurantId);
                $count = (int)($sum['candidates_count'] ?? 0);
                if ($count > 0) {
                    $est = 0.0;
                    if (function_exists('estimate_recovered_revenue')) {
                        $est = (float)estimate_recovered_revenue($restaurantId);
                    }
                    $impact = $est > 0 ? ('Estimated recovered revenue ~$' . number_format($est, 0)) : ('Potential comeback candidates: ' . $count);
                    $opps[] = _growth_engine_arch_make([
                        'type' => 'guest_return',
                        'title' => 'Re-engage inactive guests',
                        'description' => (string)($sum['headline'] ?? 'We found guests who have not returned recently.'),
                        'source_module' => 'guest_return_engine',
                        'estimated_impact' => $impact,
                        'action_url' => '/restaurant/crm.php#comeback-candidates',
                        'revenue_impact' => $est > 0 ? 0.7 : 0.4,
                        'retention_impact' => 0.8,
                        'confidence' => $count >= 20 ? 0.7 : 0.5,
                        'ease' => 0.6,
                    ]);
                }
            }
        }

        // Menu intelligence (read-only opportunities)
        if (file_exists(__DIR__ . '/menu_intelligence.php')) {
            require_once __DIR__ . '/menu_intelligence.php';
            $mi = function_exists('get_menu_intelligence') ? get_menu_intelligence($restaurantId) : ['opportunities' => [], 'warnings' => []];
            $op = $mi['opportunities'] ?? [];
            $warn = $mi['warnings'] ?? [];

            if (!empty($op['add_photo'])) {
                $n = count($op['add_photo']);
                $opps[] = _growth_engine_arch_make([
                    'type' => 'menu_fix',
                    'title' => 'Add photos to menu items',
                    'description' => 'Some items have no photo; photos can improve item selection.',
                    'source_module' => 'menu_intelligence',
                    'estimated_impact' => $n . ' item(s) without photos',
                    'action_url' => '/restaurant/menu_items.php',
                    'revenue_impact' => 0.35,
                    'retention_impact' => 0.10,
                    'confidence' => 0.55,
                    'ease' => 0.7,
                ]);
            }
            if (!empty($warn['no_sales'])) {
                $names = array_slice(array_column($warn['no_sales'], 'name'), 0, 3);
                $opps[] = _growth_engine_arch_make([
                    'type' => 'menu_fix',
                    'title' => 'Review no-sales items',
                    'description' => 'No sales this period for: ' . implode(', ', array_filter($names)) . '. Consider promotion or repositioning.',
                    'source_module' => 'menu_intelligence',
                    'estimated_impact' => 'Reduce dead inventory / improve menu clarity',
                    'action_url' => '/restaurant/revenue.php',
                    'revenue_impact' => 0.25,
                    'retention_impact' => 0.10,
                    'confidence' => 0.6,
                    'ease' => 0.4,
                ]);
            }
            if (!empty($op['consider_combo'])) {
                $c = $op['consider_combo'][0];
                $opps[] = _growth_engine_arch_make([
                    'type' => 'menu_fix',
                    'title' => 'Create a combo for a frequent pair',
                    'description' => (string)($c['item_a_name'] ?? 'Item') . ' + ' . (string)($c['item_b_name'] ?? 'Item') . ' are often ordered together.',
                    'source_module' => 'menu_intelligence',
                    'estimated_impact' => 'Raise average check via bundles',
                    'action_url' => '/restaurant/dashboard.php',
                    'revenue_impact' => 0.5,
                    'retention_impact' => 0.1,
                    'confidence' => ((int)($c['orders_together'] ?? 0) >= 5) ? 0.7 : 0.5,
                    'ease' => 0.4,
                ]);
            }
        }

        // Restaurant health score: surface warnings and missing setup (loyalty, upsell, menu).
        if (file_exists(__DIR__ . '/restaurant_health.php')) {
            require_once __DIR__ . '/restaurant_health.php';
            $hs = get_restaurant_health_score($restaurantId);
            $score = (int)($hs['score'] ?? 0);
            $label = (string)($hs['label'] ?? 'Improving');
            if ($score < 40) {
                $opps[] = _growth_engine_arch_make([
                    'type' => 'health_warning',
                    'title' => 'Health score is low',
                    'description' => 'Several measurable signals are missing or underperforming. Focus on the quickest wins below.',
                    'source_module' => 'restaurant_health',
                    'estimated_impact' => 'Stability & readiness',
                    'action_url' => '/restaurant/dashboard.php',
                    'revenue_impact' => 0.3,
                    'retention_impact' => 0.3,
                    'confidence' => 0.6,
                    'ease' => 0.5,
                ]);
            } elseif ($label === 'Improving') {
                $opps[] = _growth_engine_arch_make([
                    'type' => 'health_warning',
                    'title' => 'Health score can be improved',
                    'description' => 'You have a solid base. Improving retention and upsells tends to have the fastest impact.',
                    'source_module' => 'restaurant_health',
                    'estimated_impact' => 'Quick wins available',
                    'action_url' => '/restaurant/dashboard.php',
                    'revenue_impact' => 0.25,
                    'retention_impact' => 0.25,
                    'confidence' => 0.55,
                    'ease' => 0.6,
                ]);
            }

            $recs = get_restaurant_health_recommendations($restaurantId);
            foreach (array_slice($recs, 0, 2) as $r) {
                $opps[] = _growth_engine_arch_make([
                    'type' => 'health_warning',
                    'title' => (string)($r['title'] ?? 'Recommendation'),
                    'description' => (string)($r['reason'] ?? ''),
                    'source_module' => 'restaurant_health',
                    'estimated_impact' => 'Based on current metrics',
                    'action_url' => (string)($r['link'] ?? '/restaurant/dashboard.php'),
                    'revenue_impact' => 0.3,
                    'retention_impact' => 0.2,
                    'confidence' => 0.55,
                    'ease' => 0.7,
                ]);
            }
        }

        // Loyalty activation (if settings exist and disabled)
        $loyaltyEnabled = null;
        try {
            if (function_exists('db') && function_exists('db_table_exists') && db_table_exists('restaurant_loyalty_settings') && function_exists('db_column_exists') && db_column_exists('restaurant_loyalty_settings', 'enabled')) {
                $pdo = db();
                $st = $pdo->prepare("SELECT enabled FROM restaurant_loyalty_settings WHERE restaurant_id = ? LIMIT 1");
                $st->execute([$restaurantId]);
                $v = $st->fetchColumn();
                if ($v !== false && $v !== null) {
                    $loyaltyEnabled = ((int)$v === 1);
                }
            }
        } catch (Throwable $e) {
            $loyaltyEnabled = null;
        }
        if ($loyaltyEnabled === false) {
            $opps[] = _growth_engine_arch_make([
                'type' => 'loyalty_activation',
                'title' => 'Enable loyalty program',
                'description' => 'Loyalty can help increase repeat visits with measurable incentives.',
                'source_module' => 'loyalty',
                'estimated_impact' => 'Retention uplift',
                'action_url' => '/restaurant/loyalty_settings.php',
                'revenue_impact' => 0.2,
                'retention_impact' => 0.6,
                'confidence' => 0.5,
                'ease' => 0.7,
            ]);
        }

        // Rank + dedupe by title/action_url/type
        $ranked = _growth_engine_arch_rank($opps);
        $result = array_slice($ranked, 0, $limit);
        if (function_exists('cache_set')) {
            cache_set($cacheKey, $result, 300); // 5 min
        }
        return $result;
    }
}

if (!function_exists('get_growth_summary')) {
    /**
     * Optional summary: top driver, biggest miss, quickest win.
     * @return array{top_current_growth_driver:string,biggest_missed_opportunity:string,quickest_win:string}
     */
    function get_growth_summary(int $restaurantId): array
    {
        if (function_exists('is_demo_mode') && is_demo_mode()) {
            return [
                'top_current_growth_driver' => 'Upsells are converting well on pizza orders.',
                'biggest_missed_opportunity' => 'Several items have no photos; add photos to lift selection.',
                'quickest_win' => 'Increase priority of the best upsell pair.',
            ];
        }
        $restaurantId = (int)$restaurantId;
        $cacheKey = 'growth_arch_summary:' . $restaurantId;
        if (function_exists('cache_get')) {
            $cached = cache_get($cacheKey);
            if (is_array($cached) && isset($cached['quickest_win'])) {
                return $cached;
            }
        }
        $opps = growth_engine_arch_get_opportunities($restaurantId, 5);
        $top = $opps[0] ?? null;
        $miss = null;
        $quick = null;
        foreach ($opps as $o) {
            if ($miss === null && in_array($o['type'], ['menu_fix','guest_return','upsell_improvement'], true)) {
                $miss = $o;
            }
            if ($quick === null && (string)($o['priority'] ?? '') !== 'high') {
                // prefer high-ease items (we stored _rank only; simplest: pick first non-high)
                $quick = $o;
            }
        }
        $result = [
            'top_current_growth_driver' => $top ? ((string)$top['title'] . ' (' . (string)$top['source_module'] . ')') : 'Collect more data to identify your top driver.',
            'biggest_missed_opportunity' => $miss ? (string)$miss['title'] : 'No clear missed opportunity detected yet.',
            'quickest_win' => $quick ? (string)$quick['title'] : ($top ? (string)$top['title'] : '—'),
        ];
        if (function_exists('cache_set')) {
            cache_set($cacheKey, $result, 300); // 5 min
        }
        return $result;
    }
}

if (!function_exists('growth_engine_arch_publish_suggestions')) {
    /**
     * Publish top opportunities into growth_engine_suggestions (drafts only), with dedupe/cooldown.
     * No-op in demo mode.
     *
     * @return int created
     */
    function growth_engine_arch_publish_suggestions(int $restaurantId, int $limit = 5): int
    {
        $restaurantId = (int)$restaurantId;
        $limit = max(1, min(10, (int)$limit));
        if ($restaurantId <= 0) return 0;
        if (function_exists('is_demo_mode') && is_demo_mode()) return 0;
        if (!function_exists('db') || (function_exists('db_table_exists') && !db_table_exists('growth_engine_suggestions'))) return 0;
        if (!function_exists('db_column_exists') || !db_column_exists('growth_engine_suggestions', 'status')) return 0;
        if (!function_exists('growth_engine_suggestion_duplicate_exists')) return 0;

        $opps = growth_engine_arch_get_opportunities($restaurantId, $limit);
        if (empty($opps)) return 0;

        try {
            $pdo = db();
            $created = 0;
            foreach ($opps as $o) {
                $type = 'growth_opportunity';
                $title = (string)($o['title'] ?? 'Growth opportunity');
                $payload = json_encode([
                    'arch_type' => (string)($o['type'] ?? ''),
                    'source_module' => (string)($o['source_module'] ?? ''),
                    'action_url' => (string)($o['action_url'] ?? ''),
                    'estimated_impact' => (string)($o['estimated_impact'] ?? ''),
                ], JSON_UNESCAPED_UNICODE);
                if ($payload === false) $payload = '{}';
                if (function_exists('app_flow_id_inject_payload_json')) {
                    $payload = app_flow_id_inject_payload_json($payload, $restaurantId);
                }
                if (growth_engine_suggestion_duplicate_exists($pdo, $restaurantId, $type, $payload, $title)) {
                    continue;
                }
                $priority = (string)($o['priority'] ?? 'low');
                $prio = in_array($priority, ['high','medium','low'], true) ? $priority : 'low';
                $stmt = $pdo->prepare("
                    INSERT INTO growth_engine_suggestions (restaurant_id, type, title, description, payload_json, priority, source, status)
                    VALUES (?, ?, ?, ?, ?, ?, 'growth_engine_arch', 'pending')
                ");
                $stmt->execute([$restaurantId, $type, $title, (string)($o['description'] ?? ''), $payload, $prio]);
                $created++;
            }
            return $created;
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('growth_engine_arch publish ' . $e->getMessage());
            }
            return 0;
        }
    }
}

if (!function_exists('growth_engine_suggestion_duplicate_exists')) {
    /**
     * Check for recent duplicate suggestion by (restaurant_id, type, title, payload_json).
     */
    function growth_engine_suggestion_duplicate_exists(PDO $pdo, int $restaurantId, string $type, string $payloadJson, string $title): bool
    {
        $restaurantId = (int)$restaurantId;
        $type = (string)$type;
        $title = (string)$title;
        if ($restaurantId <= 0 || $type === '' || $title === '' || $payloadJson === '') {
            return false;
        }
        if (!function_exists('db_table_exists') || !db_table_exists('growth_engine_suggestions')) {
            return false;
        }
        try {
            $hasStatus = function_exists('db_column_exists') && db_column_exists('growth_engine_suggestions', 'status');
            $sql = "SELECT 1 FROM growth_engine_suggestions WHERE restaurant_id = ? AND type = ? AND title = ? AND payload_json = ?";
            $params = [$restaurantId, $type, $title, $payloadJson];
            if ($hasStatus) {
                $sql .= " AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
            }
            $sql .= " LIMIT 1";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return (bool)$stmt->fetchColumn();
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('growth_engine_suggestion_duplicate_exists ' . $e->getMessage());
            }
            return false;
        }
    }
}

if (!function_exists('growth_engine_pending_count')) {
    /**
     * Count pending growth_engine_suggestions for restaurant (if status column exists).
     */
    function growth_engine_pending_count(int $restaurantId): int
    {
        $restaurantId = (int)$restaurantId;
        if ($restaurantId <= 0) return 0;
        if (!function_exists('db') || (function_exists('db_table_exists') && !db_table_exists('growth_engine_suggestions'))) {
            return 0;
        }
        try {
            $pdo = db();
            if (function_exists('db_column_exists') && !db_column_exists('growth_engine_suggestions', 'status')) {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM growth_engine_suggestions WHERE restaurant_id = ?");
                $stmt->execute([$restaurantId]);
                return (int)$stmt->fetchColumn();
            }
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM growth_engine_suggestions WHERE restaurant_id = ? AND status = 'pending'");
            $stmt->execute([$restaurantId]);
            return (int)$stmt->fetchColumn();
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('growth_engine_pending_count ' . $e->getMessage());
            }
            return 0;
        }
    }
}

if (!function_exists('growth_engine_demo_suggestions_list')) {
    /**
     * Demo: fake list of suggestions (no DB).
     */
    function growth_engine_demo_suggestions_list(): array
    {
        return [
            [
                'id' => 1,
                'type' => 'crm_retention_draft',
                'title' => 'Вернитесь на неделе — скидка 10%',
                'description' => 'Черновик кампании для неактивных гостей.',
                'priority' => 'medium',
                'source' => 'crm_retention',
                'status' => 'pending',
                'created_at' => date('Y-m-d H:i:s'),
            ],
            [
                'id' => 2,
                'type' => 'combo_suggestion',
                'title' => 'Паста карбонара + лимонад',
                'description' => 'Часто заказывают в одном чеке.',
                'priority' => 'medium',
                'source' => 'combo_builder',
                'status' => 'pending',
                'created_at' => date('Y-m-d H:i:s', strtotime('-1 hour')),
            ],
            [
                'id' => 3,
                'type' => 'menu_promote',
                'title' => 'Поднять ризотто с грибами',
                'description' => 'Низкая видимость в категории «Основные блюда».',
                'priority' => 'low',
                'source' => 'menu_heatmap',
                'status' => 'pending',
                'created_at' => date('Y-m-d H:i:s', strtotime('-2 hours')),
            ],
        ];
    }
}

if (!function_exists('growth_engine_list_suggestions')) {
    /**
     * List suggestions for restaurant (optional status filter).
     *
     * @param int $restaurantId
     * @param string $statusFilter pending|accepted|dismissed|all
     * @param int $limit
     * @return array<array>
     */
    function growth_engine_list_suggestions(int $restaurantId, string $statusFilter = 'pending', int $limit = 50): array
    {
        $restaurantId = (int)$restaurantId;
        $limit = max(1, min(200, $limit));
        if (!function_exists('db') || (function_exists('db_table_exists') && !db_table_exists('growth_engine_suggestions'))) {
            return [];
        }
        try {
            $pdo = db();
            $hasStatus = function_exists('db_column_exists') && db_column_exists('growth_engine_suggestions', 'status');
            $sql = "SELECT id, restaurant_id, type, title, description, payload_json, created_at";
            if ($hasStatus) {
                $sql .= ", priority, source, status, dismissed_at";
            }
            $sql .= " FROM growth_engine_suggestions WHERE restaurant_id = ?";
            $params = [$restaurantId];
            if ($hasStatus && $statusFilter !== 'all') {
                $sql .= " AND status = ?";
                $params[] = $statusFilter;
            }
            $sql .= " ORDER BY created_at DESC LIMIT " . (int)$limit;
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('growth_engine_list_suggestions ' . $e->getMessage());
            }
            return [];
        }
    }
}

if (!function_exists('growth_engine_dismiss_suggestion')) {
    function growth_engine_dismiss_suggestion(int $restaurantId, int $suggestionId): bool
    {
        $restaurantId = (int)$restaurantId;
        $suggestionId = (int)$suggestionId;
        if ($restaurantId <= 0 || $suggestionId <= 0) {
            return false;
        }
        if (!function_exists('db') || (function_exists('db_table_exists') && !db_table_exists('growth_engine_suggestions'))) {
            return false;
        }
        if (!function_exists('db_column_exists') || !db_column_exists('growth_engine_suggestions', 'status')) {
            return false;
        }
        try {
            $pdo = db();
            $startedTx = false;
            if (!$pdo->inTransaction()) {
                $pdo->beginTransaction();
                $startedTx = true;
            }
            $stmt = $pdo->prepare("UPDATE growth_engine_suggestions SET status = 'dismissed', dismissed_at = NOW() WHERE id = ? AND restaurant_id = ? AND status = 'pending'");
            $stmt->execute([$suggestionId, $restaurantId]);
            if ($startedTx && $pdo->inTransaction()) {
                $pdo->commit();
            }
            return $stmt->rowCount() > 0;
        } catch (Throwable $e) {
            if (isset($startedTx) && $startedTx && isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (function_exists('error_log')) {
                error_log('growth_engine_dismiss_suggestion ' . $e->getMessage());
            }
            return false;
        }
    }
}

if (!function_exists('growth_engine_accept_suggestion')) {
    function growth_engine_accept_suggestion(int $restaurantId, int $suggestionId): bool
    {
        $restaurantId = (int)$restaurantId;
        $suggestionId = (int)$suggestionId;
        if ($restaurantId <= 0 || $suggestionId <= 0) {
            return false;
        }
        if (!function_exists('db') || (function_exists('db_table_exists') && !db_table_exists('growth_engine_suggestions'))) {
            return false;
        }
        if (!function_exists('db_column_exists') || !db_column_exists('growth_engine_suggestions', 'status')) {
            return false;
        }
        try {
            $pdo = db();
            $startedTx = false;
            if (!$pdo->inTransaction()) {
                $pdo->beginTransaction();
                $startedTx = true;
            }
            $stmt = $pdo->prepare("UPDATE growth_engine_suggestions SET status = 'accepted' WHERE id = ? AND restaurant_id = ? AND status = 'pending'");
            $stmt->execute([$suggestionId, $restaurantId]);
            if ($startedTx && $pdo->inTransaction()) {
                $pdo->commit();
            }
            return $stmt->rowCount() > 0;
        } catch (Throwable $e) {
            if (isset($startedTx) && $startedTx && isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (function_exists('error_log')) {
                error_log('growth_engine_accept_suggestion ' . $e->getMessage());
            }
            return false;
        }
    }
}

function _growth_engine_arch_opp_from_upsell_opt(array $s): array
{
    $type = (string)($s['type'] ?? '');
    $prio = (string)($s['priority'] ?? 'low');
    $mappedType = 'upsell_improvement';
    $impact = 'Improve upsell attach rate';
    if ($type === 'upsell_disable') {
        $impact = 'Remove weak offers';
    } elseif ($type === 'upsell_new_pair') {
        $impact = 'Add a new converting pair';
    } elseif ($type === 'upsell_priority_change') {
        $impact = 'Increase weight for best pair';
    }
    return _growth_engine_arch_make([
        'type' => $mappedType,
        'title' => (string)($s['title'] ?? 'Upsell improvement'),
        'description' => (string)($s['description'] ?? ''),
        'source_module' => 'upsell_optimization',
        'estimated_impact' => $impact,
        'action_url' => '/restaurant/upsells.php#optimization',
        'revenue_impact' => $type === 'upsell_priority_change' ? 0.6 : 0.4,
        'retention_impact' => 0.1,
        'confidence' => $prio === 'medium' ? 0.65 : 0.5,
        'ease' => 0.7,
    ]);
}

function _growth_engine_arch_make(array $in): array
{
    $revenue = (float)($in['revenue_impact'] ?? 0);
    $ret = (float)($in['retention_impact'] ?? 0);
    $conf = (float)($in['confidence'] ?? 0.5);
    $ease = (float)($in['ease'] ?? 0.5);
    $rank = (0.40 * $revenue) + (0.25 * $ret) + (0.20 * $conf) + (0.15 * $ease);

    $priority = 'low';
    if ($rank >= 0.65) $priority = 'high';
    elseif ($rank >= 0.45) $priority = 'medium';

    return [
        'type' => (string)($in['type'] ?? 'health_warning'),
        'priority' => $priority,
        'title' => (string)($in['title'] ?? ''),
        'description' => (string)($in['description'] ?? ''),
        'source_module' => (string)($in['source_module'] ?? 'unknown'),
        'estimated_impact' => (string)($in['estimated_impact'] ?? ''),
        'action_url' => (string)($in['action_url'] ?? '/restaurant/dashboard.php'),
        '_rank' => round($rank, 4),
    ];
}

function _growth_engine_arch_rank(array $items): array
{
    // Dedupe by (type,title,action_url)
    $seen = [];
    $out = [];
    foreach ($items as $it) {
        $k = (string)($it['type'] ?? '') . '|' . (string)($it['title'] ?? '') . '|' . (string)($it['action_url'] ?? '');
        if ($k === '||' || isset($seen[$k])) continue;
        $seen[$k] = true;
        $out[] = $it;
    }
    usort($out, function ($a, $b) {
        return ((float)($b['_rank'] ?? 0)) <=> ((float)($a['_rank'] ?? 0));
    });
    return $out;
}

function _growth_engine_arch_demo_opportunities(): array
{
    return [
        _growth_engine_arch_make([
            'type' => 'upsell_improvement',
            'title' => 'Повысить приоритет: стейк → капучино',
            'description' => 'Высокая конверсия допродажи при большом числе показов.',
            'source_module' => 'upsell_optimization',
            'estimated_impact' => 'Рост среднего чека',
            'action_url' => '/restaurant/upsells.php#optimization',
            'revenue_impact' => 0.7, 'retention_impact' => 0.1, 'confidence' => 0.7, 'ease' => 0.7,
        ]),
        _growth_engine_arch_make([
            'type' => 'guest_return',
            'title' => 'Вернуть неактивных гостей',
            'description' => 'В демо найдено несколько гостей без визита более 14 дней.',
            'source_module' => 'guest_return_engine',
            'estimated_impact' => 'Оценка восстановленной выручки ~28 500 ₽',
            'action_url' => '/restaurant/crm.php#comeback-candidates',
            'revenue_impact' => 0.6, 'retention_impact' => 0.8, 'confidence' => 0.6, 'ease' => 0.6,
        ]),
        _growth_engine_arch_make([
            'type' => 'menu_fix',
            'title' => 'Усилить ризотто в меню',
            'description' => 'Низкие продажи при наличии фото — проверьте порядок и описание.',
            'source_module' => 'menu_intelligence',
            'estimated_impact' => 'Ризотто — кандидат на продвижение',
            'action_url' => '/restaurant/menu_items.php',
            'revenue_impact' => 0.3, 'retention_impact' => 0.1, 'confidence' => 0.5, 'ease' => 0.8,
        ]),
        _growth_engine_arch_make([
            'type' => 'loyalty_activation',
            'title' => 'Enable loyalty program',
            'description' => 'Loyalty can help increase repeat visits with measurable incentives.',
            'source_module' => 'loyalty',
            'estimated_impact' => 'Retention uplift',
            'action_url' => '/restaurant/loyalty_settings.php',
            'revenue_impact' => 0.2, 'retention_impact' => 0.6, 'confidence' => 0.5, 'ease' => 0.7,
        ]),
        _growth_engine_arch_make([
            'type' => 'health_warning',
            'title' => 'Health score can be improved',
            'description' => 'Focus on the quickest wins to improve readiness.',
            'source_module' => 'restaurant_health',
            'estimated_impact' => 'Quick wins available',
            'action_url' => '/restaurant/dashboard.php',
            'revenue_impact' => 0.2, 'retention_impact' => 0.2, 'confidence' => 0.5, 'ease' => 0.7,
        ]),
    ];
}

