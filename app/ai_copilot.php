<?php
/**
 * Restaurant AI Copilot: rule-based assistant. Read-only; uses existing analytics.
 * No external AI/LLM. Can be upgraded to LLM API later.
 * Summary cached 5 minutes per restaurant.
 */

const _AI_COPILOT_CACHE_TTL = 300;

function _ai_copilot_cache_get(int $restaurantId): ?array
{
    $key = 'copilot_summary_' . $restaurantId;
    $store = &$GLOBALS['_ai_copilot_cache'];
    if (!isset($store) || !is_array($store) || !isset($store[$key]) || !is_array($store[$key])) {
        return null;
    }
    $entry = $store[$key];
    if (($entry['expires_at'] ?? 0) < time()) {
        unset($store[$key]);
        return null;
    }
    return $entry['data'] ?? null;
}

function _ai_copilot_cache_set(int $restaurantId, array $data): void
{
    if (!isset($GLOBALS['_ai_copilot_cache']) || !is_array($GLOBALS['_ai_copilot_cache'])) {
        $GLOBALS['_ai_copilot_cache'] = [];
    }
    $GLOBALS['_ai_copilot_cache']['copilot_summary_' . $restaurantId] = ['data' => $data, 'expires_at' => time() + _AI_COPILOT_CACHE_TTL];
}

/**
 * Get short weekly summary (paragraph + insight bullets).
 * @param int $restaurantId
 * @return array{summary: string, insights: array<string>}
 */
function get_ai_copilot_summary(int $restaurantId): array
{
    $restaurantId = (int) $restaurantId;
    $result = ['summary' => '', 'insights' => []];

    if (function_exists('is_demo_mode') && is_demo_mode()) {
        return [
            'summary' => 'Выручка за неделю выросла примерно на 12%. Лидер меню — стейк рибай; пик загрузки 18:00–20:00.',
            'insights' => [
                'Стейк рибай и паста карбонара — самые заказываемые позиции.',
                'Пик посещаемости: 18:00–20:00.',
                'Конверсия оформления заказа в демо: 82%.',
            ],
        ];
    }

    $cached = _ai_copilot_cache_get($restaurantId);
    if ($cached !== null) {
        return $cached;
    }

    try {
        $parts = [];
        $insights = [];

        if (file_exists(__DIR__ . '/menu_performance.php')) {
            require_once __DIR__ . '/menu_performance.php';
            $mp = menu_performance_insights($restaurantId, 7);
            $top = $mp['top'] ?? [];
            if (!empty($top)) {
                $parts[] = $top[0]['name'] . ' drives most orders.';
                $insights[] = $top[0]['name'] . ' drives most orders.';
            }
        }

        if (file_exists(__DIR__ . '/peak_hours.php')) {
            require_once __DIR__ . '/peak_hours.php';
            $ph = get_peak_hours_summary($restaurantId, 7);
            if ($ph['peak_hour_range_text'] !== '') {
                $parts[] = 'Peak hours are ' . $ph['peak_hour_range_text'] . '.';
                $insights[] = 'Peak hours ' . $ph['peak_hour_range_text'] . '.';
            }
        }

        if (function_exists('db_table_exists') && db_table_exists('checkout_events') && file_exists(__DIR__ . '/checkout_analytics.php')) {
            require_once __DIR__ . '/checkout_analytics.php';
            $conv = get_checkout_conversion($restaurantId, 7);
            if ($conv['started'] > 0 && $conv['conversion_pct'] !== null) {
                $insights[] = 'Checkout conversion ' . (float)$conv['conversion_pct'] . '%.';
            }
        }

        $guestReturnRate = null;
        if (function_exists('db_table_exists') && db_table_exists('crm_guests')) {
            $pdo = db();
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM crm_guests WHERE restaurant_id = ? AND visits_count >= 1");
            $stmt->execute([$restaurantId]);
            $total = (int) $stmt->fetchColumn();
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM crm_guests WHERE restaurant_id = ? AND visits_count > 1");
            $stmt->execute([$restaurantId]);
            $returning = (int) $stmt->fetchColumn();
            $guestReturnRate = $total > 0 ? round(100.0 * $returning / $total, 1) : null;
        }
        if ($guestReturnRate !== null) {
            if ($guestReturnRate < 20) {
                $parts[] = 'Guest return rate can be improved with comeback campaigns.';
            }
        }

        if (file_exists(__DIR__ . '/network_benchmark.php')) {
            require_once __DIR__ . '/network_benchmark.php';
            $bench = get_restaurant_benchmark($restaurantId);
            if (!empty($bench['available']) && $bench['your_aov'] !== null && $bench['peer_aov'] !== null && $bench['your_aov'] < $bench['peer_aov']) {
                $parts[] = 'Average check is below similar restaurants — upsells can help.';
            }
        }

        $result['summary'] = implode(' ', $parts) ?: 'Add more orders and menu data to get a weekly summary.';
        $result['insights'] = array_slice($insights, 0, 5);
        _ai_copilot_cache_set($restaurantId, $result);
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('ai_copilot summary ' . $e->getMessage());
        }
        $result['summary'] = 'Summary is temporarily unavailable.';
    }
    return $result;
}

/**
 * Get 3–5 copilot recommendations.
 * @param int $restaurantId
 * @return array<array{title: string, description: string, link: string}>
 */
function get_ai_copilot_recommendations(int $restaurantId): array
{
    $restaurantId = (int) $restaurantId;

    if (function_exists('is_demo_mode') && is_demo_mode()) {
        return [
            ['title' => 'Собрать комбо', 'description' => 'Паста карбонара и лимонад часто идут в одном чеке — оформите как набор.', 'link' => '/restaurant/dashboard.php'],
            ['title' => 'Настроить допродажи', 'description' => 'Стейк → капучино и бургер → крылья уже работают в демо — добавьте свои пары.', 'link' => '/restaurant/upsell_rules.php'],
            ['title' => 'Кампания возврата', 'description' => 'Отправьте предложение гостям из сегмента «неактивные».', 'link' => '/restaurant/crm_campaigns.php'],
        ];
    }

    $recs = [];
    try {
        if (!function_exists('dashboard_has_upsell_rules') || !dashboard_has_upsell_rules($restaurantId)) {
            $recs[] = ['title' => 'Add upsell rules', 'description' => 'Upsell rules can increase your average check.', 'link' => '/restaurant/upsell_rules.php'];
        }
        if (file_exists(__DIR__ . '/menu_heatmap.php')) {
            require_once __DIR__ . '/menu_heatmap.php';
            $heat = get_menu_heatmap($restaurantId, 7);
            $low = $heat['low'] ?? [];
            if (!empty($low)) {
                $recs[] = ['title' => 'Promote ' . $low[0]['name'], 'description' => 'Low visibility — feature it or pair with upsell.', 'link' => '/restaurant/menu_items.php'];
            }
        }
        if (file_exists(__DIR__ . '/retention_suggestions.php')) {
            require_once __DIR__ . '/retention_suggestions.php';
            if (!empty(retention_suggestions($restaurantId, 14, 1))) {
                $recs[] = ['title' => 'Send comeback offers', 'description' => 'Re-engage guests who haven\'t visited recently.', 'link' => '/restaurant/crm.php'];
            }
        }
        if (file_exists(__DIR__ . '/combo_builder.php')) {
            require_once __DIR__ . '/combo_builder.php';
            $combo = get_combo_suggestions($restaurantId);
            if (!empty($combo['suggestions'])) {
                $recs[] = ['title' => 'Create combo offers', 'description' => 'Frequent pairs can become combos.', 'link' => '/restaurant/dashboard.php'];
            }
        }
        if (count($recs) < 3 && file_exists(__DIR__ . '/restaurant_success.php')) {
            require_once __DIR__ . '/restaurant_success.php';
            $extra = get_restaurant_recommendations($restaurantId);
            foreach ($extra as $e) {
                if (count($recs) >= 5) break;
                $recs[] = $e;
            }
        }
        $recs = array_slice($recs, 0, 5);
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('ai_copilot recommendations ' . $e->getMessage());
        }
    }
    return $recs;
}

/**
 * Answer a copilot question (rule-based). Read-only; no DB writes.
 * @param int $restaurantId
 * @param string $question
 * @return string
 */
function answer_copilot_question(int $restaurantId, string $question): string
{
    $restaurantId = (int) $restaurantId;
    $q = strtolower(trim($question));
    if ($q === '') {
        return 'Ask me about your best sellers, peak hours, average check, or how to get more orders.';
    }

    if (function_exists('is_demo_mode') && is_demo_mode()) {
        if (strpos($q, 'best') !== false || strpos($q, 'selling') !== false || strpos($q, 'seller') !== false) {
            return 'В демо чаще всего заказывают стейк рибай и пасту карбонару.';
        }
        if (strpos($q, 'peak') !== false || strpos($q, 'hour') !== false || strpos($q, 'busy') !== false) {
            return 'Пик загрузки в демо: 18:00–20:00.';
        }
        if (strpos($q, 'average') !== false || strpos($q, 'check') !== false || strpos($q, 'increase') !== false) {
            return 'Средний чек в демо сопоставим с «типичным» гастропабом. Допродажи и комбо помогут его поднять.';
        }
        if (strpos($q, 'order') !== false && (strpos($q, 'low') !== false || strpos($q, 'why') !== false)) {
            return 'Расширьте меню, продвигайте QR-заказ и запускайте возвратные кампании для неактивных гостей.';
        }
        return 'В демо-режиме: хиты — стейк и паста, пик 18:00–20:00. Спросите про чек, гостей или допродажи.';
    }

    try {
        if (strpos($q, 'best') !== false || strpos($q, 'selling') !== false || strpos($q, 'seller') !== false || strpos($q, 'top') !== false) {
            if (file_exists(__DIR__ . '/menu_performance.php')) {
                require_once __DIR__ . '/menu_performance.php';
                $mp = menu_performance_insights($restaurantId, 7);
                $top = $mp['top'] ?? [];
                if (!empty($top)) {
                    return $top[0]['name'] . ' is your best selling item.';
                }
            }
            return 'Not enough order data yet. Add menu items and collect orders to see top sellers.';
        }

        if (strpos($q, 'peak') !== false || strpos($q, 'hour') !== false || strpos($q, 'busy') !== false || strpos($q, 'when') !== false) {
            if (file_exists(__DIR__ . '/peak_hours.php')) {
                require_once __DIR__ . '/peak_hours.php';
                $ph = get_peak_hours_summary($restaurantId, 7);
                if ($ph['peak_hour_range_text'] !== '') {
                    return $ph['peak_hour_range_text'] . ' ' . ($ph['recommendation_text'] ?? '');
                }
            }
            return 'Not enough order data to show peak hours. Keep collecting orders.';
        }

        if (strpos($q, 'average') !== false || strpos($q, 'check') !== false || strpos($q, 'increase') !== false || strpos($q, 'aov') !== false) {
            if (file_exists(__DIR__ . '/network_benchmark.php')) {
                require_once __DIR__ . '/network_benchmark.php';
                $bench = get_restaurant_benchmark($restaurantId);
                if ($bench['your_aov'] !== null) {
                    $msg = 'Your average check is ' . number_format($bench['your_aov'], 0) . '.';
                    if ($bench['peer_aov'] !== null) {
                        $msg .= ' Similar restaurants average ' . number_format($bench['peer_aov'], 0) . '.';
                    }
                    $msg .= ' Add upsell rules and combo offers to increase it.';
                    return $msg;
                }
            }
            return 'Add more paid orders to see average check. Upsells and combos help raise it.';
        }

        if ((strpos($q, 'order') !== false && (strpos($q, 'low') !== false || strpos($q, 'why') !== false)) || strpos($q, 'improve') !== false) {
            $tips = [];
            if (!function_exists('dashboard_has_upsell_rules') || !dashboard_has_upsell_rules($restaurantId)) {
                $tips[] = 'Add upsell rules to increase average check.';
            }
            if (file_exists(__DIR__ . '/retention_suggestions.php') && !empty(retention_suggestions($restaurantId, 14, 1))) {
                $tips[] = 'Send comeback offers to inactive guests.';
            }
            $tips[] = 'Promote your QR menu and add more menu variety.';
            return 'To get more orders: ' . implode(' ', $tips);
        }

        if (strpos($q, 'checkout') !== false || strpos($q, 'conversion') !== false) {
            if (function_exists('db_table_exists') && db_table_exists('checkout_events') && file_exists(__DIR__ . '/checkout_analytics.php')) {
                require_once __DIR__ . '/checkout_analytics.php';
                $conv = get_checkout_conversion($restaurantId, 7);
                if ($conv['started'] > 0) {
                    return 'Checkout conversion is ' . ($conv['conversion_pct'] !== null ? (float)$conv['conversion_pct'] . '%' : 'N/A') . '. ' . ($conv['recommendation_text'] ?? '');
                }
            }
            return 'Checkout tracking will show conversion once you have enough data.';
        }

        if (strpos($q, 'menu') !== false && (strpos($q, 'performance') !== false || strpos($q, 'selling') !== false || strpos($q, 'top') !== false || strpos($q, 'low') !== false)) {
            if (file_exists(__DIR__ . '/menu_heatmap.php')) {
                require_once __DIR__ . '/menu_heatmap.php';
                $heat = get_menu_heatmap($restaurantId, 7);
                if (!empty($heat['top'])) {
                    $topNames = array_column(array_slice($heat['top'], 0, 3), 'name');
                    $msg = 'Top selling: ' . implode(', ', $topNames) . '.';
                    if (!empty($heat['low'])) {
                        $lowNames = array_column(array_slice($heat['low'], 0, 2), 'name');
                        $msg .= ' Lower visibility: ' . implode(', ', $lowNames) . ' — consider promoting.';
                    }
                    return $msg;
                }
            }
            return 'Not enough order data for menu performance. Add more orders.';
        }

        if (strpos($q, 'return') !== false || strpos($q, 'guest') !== false || strpos($q, 'retention') !== false) {
            if (function_exists('dashboard_crm_summary')) {
                $crm = dashboard_crm_summary($restaurantId);
                if ($crm !== null) {
                    $returning = (int)($crm['returning_count'] ?? 0);
                    return "You have {$returning} returning guests. Send comeback campaigns from CRM to improve retention.";
                }
            }
            return 'Use CRM to track guests and send comeback offers.';
        }

        return 'I can answer questions about best selling items, peak hours, checkout conversion, average check, guest retention, and menu performance. Try asking one of those.';
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('ai_copilot answer ' . $e->getMessage());
        }
        return 'Something went wrong. Try asking about your best sellers or peak hours.';
    }
}

/**
 * Suggested copilot actions for the current question/answer (for UI buttons).
 * @param int $restaurantId
 * @param string $question
 * @param string $answer
 * @return array<string> e.g. ['create_combo', 'create_campaign']
 */
function get_copilot_suggested_actions(int $restaurantId, string $question, string $answer): array
{
    $restaurantId = (int) $restaurantId;
    $q = strtolower($question . ' ' . $answer);
    $actions = [];

    if (preg_match('/\b(combo|pair|together|bundle)\b/', $q)) {
        if (file_exists(__DIR__ . '/combo_builder.php')) {
            require_once __DIR__ . '/combo_builder.php';
            $combo = get_combo_suggestions($restaurantId);
            if (!empty($combo['suggestions'])) {
                $actions[] = 'create_combo';
            }
        }
    }
    if (preg_match('/\b(campaign|comeback|inactive|retention|guest|re-?engage)\b/', $q)) {
        if (function_exists('db_table_exists') && db_table_exists('crm_guests')) {
            $pdo = function_exists('db') ? db() : null;
            if ($pdo) {
                $stmt = $pdo->prepare('SELECT COUNT(*) FROM crm_guests WHERE restaurant_id = ?');
                $stmt->execute([$restaurantId]);
                if ((int) $stmt->fetchColumn() > 0) {
                    $actions[] = 'create_campaign';
                }
            }
        }
    }
    if (preg_match('/\b(promote|low|visibility|feature|selling)\b/', $q)) {
        if (file_exists(__DIR__ . '/menu_heatmap.php')) {
            require_once __DIR__ . '/menu_heatmap.php';
            $heat = get_menu_heatmap($restaurantId, 7);
            $low = $heat['low'] ?? [];
            if (!empty($low)) {
                $actions[] = 'promote_menu_item';
            }
        }
    }
    if (preg_match('/\b(upsell|add-?on|average check|aov)\b/', $q)) {
        if (file_exists(__DIR__ . '/upsell_ai.php')) {
            require_once __DIR__ . '/upsell_ai.php';
            $ups = get_upsell_suggestions($restaurantId);
            if (!empty($ups['suggestions'])) {
                $actions[] = 'create_upsell';
            }
        }
    }

    return array_values(array_unique($actions));
}

/**
 * Generate a growth draft/suggestion from Copilot (no direct changes to menu, CRM, or upsell rules).
 * Supported actions: create_combo, create_campaign, promote_menu_item, create_upsell.
 * @param int $restaurantId
 * @param string $action one of create_combo, create_campaign, promote_menu_item, create_upsell
 * @return array{success: bool, message: string}
 */
function generate_copilot_action(int $restaurantId, string $action): array
{
    $restaurantId = (int) $restaurantId;
    $action = trim($action);
    $allowed = ['create_combo', 'create_campaign', 'promote_menu_item', 'create_upsell'];
    if (!in_array($action, $allowed, true)) {
        return ['success' => false, 'message' => 'Unknown action.'];
    }

    if (function_exists('is_demo_mode') && is_demo_mode()) {
        return ['success' => true, 'message' => 'Draft suggestion created (demo — not saved).'];
    }

    if (!function_exists('db') || (function_exists('db_table_exists') && !db_table_exists('growth_engine_suggestions'))) {
        return ['success' => false, 'message' => 'Suggestions not available.'];
    }
    if (file_exists(__DIR__ . '/growth_engine_arch.php')) {
        require_once __DIR__ . '/growth_engine_arch.php';
    }

    $pdo = db();
    $hasNewColumns = function_exists('db_column_exists') && db_column_exists('growth_engine_suggestions', 'status');

    try {
        if ($action === 'create_campaign') {
            $title = 'Come back this week and get 10% off';
            $payload = json_encode(['type' => 'crm_campaign_draft', 'message' => $title, 'segment_type' => 'inactive_guests'], JSON_UNESCAPED_UNICODE);
            if (function_exists('growth_engine_suggestion_duplicate_exists') && growth_engine_suggestion_duplicate_exists($pdo, $restaurantId, 'crm_campaign_draft', $payload, $title)) {
                return ['success' => true, 'message' => 'Similar draft already exists.'];
            }
            if ($hasNewColumns) {
                $stmt = $pdo->prepare("INSERT INTO growth_engine_suggestions (restaurant_id, type, title, description, payload_json, priority, source, status) VALUES (?, 'crm_campaign_draft', ?, ?, ?, 'medium', 'ai_copilot', 'pending')");
            } else {
                $stmt = $pdo->prepare("INSERT INTO growth_engine_suggestions (restaurant_id, type, title, description, payload_json) VALUES (?, 'crm_campaign_draft', ?, ?, ?)");
            }
            $stmt->execute([$restaurantId, $title, 'Draft campaign for inactive guests. Confirm in CRM before sending.', $payload]);
            return ['success' => true, 'message' => 'CRM campaign draft created.'];
        }

        if ($action === 'create_combo') {
            if (!file_exists(__DIR__ . '/combo_builder.php')) {
                return ['success' => false, 'message' => 'Combo data not available.'];
            }
            require_once __DIR__ . '/combo_builder.php';
            $combo = get_combo_suggestions($restaurantId);
            if (empty($combo['suggestions'])) {
                return ['success' => false, 'message' => 'No combo suggestions from order data yet. Need more orders.'];
            }
            $first = $combo['suggestions'][0];
            $label = $first['label'] ?? 'Combo offer';
            $items = array_column($first['items'] ?? [], 'name');
            $payload = json_encode(['label' => $label, 'items' => $items], JSON_UNESCAPED_UNICODE);
            if (function_exists('growth_engine_suggestion_duplicate_exists') && growth_engine_suggestion_duplicate_exists($pdo, $restaurantId, 'combo_suggestion', $payload, $label)) {
                return ['success' => true, 'message' => 'Similar combo suggestion already exists.'];
            }
            if ($hasNewColumns) {
                $stmt = $pdo->prepare("INSERT INTO growth_engine_suggestions (restaurant_id, type, title, description, payload_json, priority, source, status) VALUES (?, 'combo_suggestion', ?, ?, ?, 'medium', 'ai_copilot', 'pending')");
            } else {
                $stmt = $pdo->prepare("INSERT INTO growth_engine_suggestions (restaurant_id, type, title, description, payload_json) VALUES (?, 'combo_suggestion', ?, ?, ?)");
            }
            $stmt->execute([$restaurantId, $label, 'Frequently ordered together. Create combo in dashboard.', $payload]);
            return ['success' => true, 'message' => 'Combo draft suggestion created.'];
        }

        if ($action === 'promote_menu_item') {
            if (!file_exists(__DIR__ . '/menu_heatmap.php')) {
                return ['success' => false, 'message' => 'Menu heatmap not available.'];
            }
            require_once __DIR__ . '/menu_heatmap.php';
            $heat = get_menu_heatmap($restaurantId, 7);
            $low = $heat['low'] ?? [];
            if (empty($low)) {
                return ['success' => false, 'message' => 'No low-selling item to promote. Need more order data.'];
            }
            $item = $low[0];
            $menuItemId = (int) ($item['id'] ?? 0);
            $name = $item['name'] ?? 'item';
            $title = 'Promote ' . $name;
            $payload = json_encode(['menu_item_id' => $menuItemId, 'label' => 'Promote this item'], JSON_UNESCAPED_UNICODE);
            if (function_exists('growth_engine_suggestion_duplicate_exists') && growth_engine_suggestion_duplicate_exists($pdo, $restaurantId, 'menu_promote', $payload, $title)) {
                return ['success' => true, 'message' => 'Similar promote suggestion already exists.'];
            }
            if ($hasNewColumns) {
                $stmt = $pdo->prepare("INSERT INTO growth_engine_suggestions (restaurant_id, type, title, description, payload_json, priority, source, status) VALUES (?, 'menu_promote', ?, ?, ?, 'low', 'ai_copilot', 'pending')");
            } else {
                $stmt = $pdo->prepare("INSERT INTO growth_engine_suggestions (restaurant_id, type, title, description, payload_json) VALUES (?, 'menu_promote', ?, ?, ?)");
            }
            $stmt->execute([$restaurantId, $title, 'Move higher in menu or create upsell pair.', $payload]);
            return ['success' => true, 'message' => 'Menu promote suggestion created.'];
        }

        if ($action === 'create_upsell') {
            if (!file_exists(__DIR__ . '/upsell_ai.php')) {
                return ['success' => false, 'message' => 'Upsell suggestions not available.'];
            }
            require_once __DIR__ . '/upsell_ai.php';
            $ups = get_upsell_suggestions($restaurantId);
            if (empty($ups['suggestions'])) {
                return ['success' => false, 'message' => 'No upsell pairs from order data yet. Need more orders.'];
            }
            $first = $ups['suggestions'][0];
            $baseId = (int) ($first['base_item_id'] ?? 0);
            $upsellId = (int) ($first['suggested_item_id'] ?? 0);
            $payload = json_encode(['base_item_id' => $baseId, 'upsell_item_id' => $upsellId], JSON_UNESCAPED_UNICODE);
            $title = ($first['base_item'] ?? 'Item') . ' → ' . ($first['suggested_item'] ?? 'Upsell');
            if (function_exists('growth_engine_suggestion_duplicate_exists') && growth_engine_suggestion_duplicate_exists($pdo, $restaurantId, 'upsell_rule_suggestion', $payload, $title)) {
                return ['success' => true, 'message' => 'Similar upsell suggestion already exists.'];
            }
            if ($hasNewColumns) {
                $stmt = $pdo->prepare("INSERT INTO growth_engine_suggestions (restaurant_id, type, title, description, payload_json, priority, source, status) VALUES (?, 'upsell_rule_suggestion', ?, ?, ?, 'medium', 'ai_copilot', 'pending')");
            } else {
                $stmt = $pdo->prepare("INSERT INTO growth_engine_suggestions (restaurant_id, type, title, description, payload_json) VALUES (?, 'upsell_rule_suggestion', ?, ?, ?)");
            }
            $stmt->execute([$restaurantId, $title, 'Suggested upsell pair from order history. Add rule in Upsell settings.', $payload]);
            return ['success' => true, 'message' => 'Upsell rule suggestion created.'];
        }
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('generate_copilot_action ' . $e->getMessage());
        }
        return ['success' => false, 'message' => 'Could not create suggestion. Try again.'];
    }

    return ['success' => false, 'message' => 'Action not handled.'];
}
