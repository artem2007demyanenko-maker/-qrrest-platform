<?php
/**
 * Restaurant Success Engine: insights and actionable recommendations.
 * Health score is defined only in app/restaurant_health.php.
 * Uses existing analytics (combo_builder, peak_hours, menu_heatmap, table_turnover,
 * checkout_analytics, benchmark, return_prediction, CRM, revenue, upsell).
 * Safe fallbacks; demo mode supported.
 */

/**
 * Get 3–5 short insights from existing analytics.
 * @param int $restaurantId
 * @return array<array{type: string, text: string}>
 */
function get_restaurant_insights(int $restaurantId): array
{
    $restaurantId = (int) $restaurantId;
    $insights = [];

    if (function_exists('is_demo_mode') && is_demo_mode()) {
        return [
            ['type' => 'revenue', 'text' => 'Пик загрузки: 18:00–20:00.'],
            ['type' => 'menu', 'text' => 'Основной объём выручки дают стейки и паста.'],
            ['type' => 'revenue', 'text' => 'Конверсия допродаж в демо ~7,4%.'],
        ];
    }
    try {
        if (file_exists(__DIR__ . '/menu_performance.php')) {
            require_once __DIR__ . '/menu_performance.php';
            $mp = menu_performance_insights($restaurantId, 7);
            $top = $mp['top'] ?? [];
            if (!empty($top)) {
                $insights[] = ['type' => 'menu', 'text' => $top[0]['name'] . ' drives most orders.'];
            }
        }

        if (file_exists(__DIR__ . '/peak_hours.php')) {
            require_once __DIR__ . '/peak_hours.php';
            $ph = get_peak_hours_summary($restaurantId, 7);
            if ($ph['peak_hour_range_text'] !== '') {
                $insights[] = ['type' => 'revenue', 'text' => 'Peak hours are ' . $ph['peak_hour_range_text'] . '.'];
            }
        }

        if (function_exists('db_table_exists') && db_table_exists('checkout_events') && file_exists(__DIR__ . '/checkout_analytics.php')) {
            require_once __DIR__ . '/checkout_analytics.php';
            $conv = get_checkout_conversion($restaurantId, 7);
            if ($conv['started'] > 0 && $conv['conversion_pct'] !== null) {
                $insights[] = ['type' => 'revenue', 'text' => 'Checkout conversion is ' . (float) $conv['conversion_pct'] . '%.'];
            }
        }

        if (function_exists('dashboard_upsell_summary')) {
            $us = dashboard_upsell_summary($restaurantId, 7);
            if ($us !== null && (int) ($us['shown'] ?? 0) > 0 && (float) ($us['conversion_pct'] ?? 0) > 0) {
                $insights[] = ['type' => 'revenue', 'text' => 'Upsell conversion ' . round((float) $us['conversion_pct'], 1) . '%.'];
            }
        }

        if (function_exists('dashboard_crm_summary')) {
            $crm = dashboard_crm_summary($restaurantId);
            if ($crm !== null && (int) ($crm['returning_count'] ?? 0) > 0) {
                $insights[] = ['type' => 'crm', 'text' => (int) $crm['returning_count'] . ' returning guests this period.'];
            }
        }

        if (file_exists(__DIR__ . '/network_benchmark.php')) {
            require_once __DIR__ . '/network_benchmark.php';
            $bench = get_restaurant_benchmark($restaurantId);
            if (!empty($bench['available']) && $bench['your_aov'] !== null && $bench['peer_aov'] !== null) {
                if ($bench['your_aov'] >= $bench['peer_aov']) {
                    $insights[] = ['type' => 'revenue', 'text' => 'Your average check is on par with similar restaurants.'];
                } else {
                    $insights[] = ['type' => 'revenue', 'text' => 'Upsell suggestions could increase average check.'];
                }
            }
        }

        $insights = array_slice($insights, 0, 5);
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('restaurant_success insights ' . $e->getMessage());
        }
    }
    return $insights;
}

/**
 * Get actionable recommendations with title, description, link.
 * @param int $restaurantId
 * @return array<array{title: string, description: string, link: string}>
 */
function get_restaurant_recommendations(int $restaurantId): array
{
    $restaurantId = (int) $restaurantId;
    $recs = [];

    if (function_exists('is_demo_mode') && is_demo_mode()) {
        return [
            ['title' => 'Открыть CRM', 'description' => 'Сегменты гостей и возвратные кампании.', 'link' => '/restaurant/crm.php'],
            ['title' => 'QR-меню со стола', 'description' => 'Как гость добавляет блюда и видит допродажи.', 'link' => '/qr.php?table_id=1'],
            ['title' => 'Аналитика выручки', 'description' => 'Пик часов, heatmap меню, оборот столов.', 'link' => '/restaurant/revenue.php'],
        ];
    }
    try {
        $hasUpsellRules = function_exists('dashboard_has_upsell_rules') && dashboard_has_upsell_rules($restaurantId);
        if (!$hasUpsellRules) {
            $recs[] = [
                'title'   => 'Add upsell rules',
                'description' => 'Upsell rules can increase your average check.',
                'link'    => '/restaurant/upsell_rules.php',
            ];
        }

        if (file_exists(__DIR__ . '/menu_heatmap.php')) {
            require_once __DIR__ . '/menu_heatmap.php';
            $heat = get_menu_heatmap($restaurantId, 7);
            $low = $heat['low'] ?? [];
            if (!empty($low)) {
                $recs[] = [
                    'title'   => 'Promote ' . $low[0]['name'],
                    'description' => 'This item has low visibility — consider featuring it.',
                    'link'    => '/restaurant/menu_items.php',
                ];
            }
        }

        $crmSummary = function_exists('dashboard_crm_summary') ? dashboard_crm_summary($restaurantId) : null;
        $retentionSuggestions = [];
        if (file_exists(__DIR__ . '/retention_suggestions.php')) {
            require_once __DIR__ . '/retention_suggestions.php';
            $retentionSuggestions = retention_suggestions($restaurantId, 14, 5);
        }
        if (!empty($retentionSuggestions)) {
            $recs[] = [
                'title'   => 'Send comeback offers',
                'description' => 'Send offers to inactive guests to bring them back.',
                'link'    => '/restaurant/crm.php',
            ];
        }

        if (function_exists('db_table_exists') && db_table_exists('menu_items')) {
            $pdo = db();
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM menu_items WHERE restaurant_id = ? AND available = 1");
            $stmt->execute([$restaurantId]);
            $menuCount = (int) $stmt->fetchColumn();
            if ($menuCount < 5) {
                $recs[] = [
                    'title'   => 'Add more menu items',
                    'description' => 'Increase menu variety to attract more orders.',
                    'link'    => '/restaurant/menu_items.php',
                ];
            }
        }

        if (file_exists(__DIR__ . '/combo_builder.php')) {
            require_once __DIR__ . '/combo_builder.php';
            $combo = get_combo_suggestions($restaurantId);
            if (!empty($combo['suggestions'])) {
                $recs[] = [
                    'title'   => 'Create combo offers',
                    'description' => 'Frequent order pairs can be turned into combos.',
                    'link'    => '/restaurant/dashboard.php',
                ];
            }
        }

        $recs = array_slice($recs, 0, 5);
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('restaurant_success recommendations ' . $e->getMessage());
        }
    }
    return $recs;
}
