<?php
/**
 * Self-test для stats_* (формат данных, пустой scope, лимиты).
 * Не публичный, только для app/; не модифицирует БД.
 */
if (!function_exists('stats_revenue_summary')) {
    require_once __DIR__ . '/db.php';
    require_once __DIR__ . '/stats.php';
}

/**
 * @return array<int,array{name:string,passed:bool,message?:string}>
 */
function stats_selftest_run(): array
{
    $results = [];
    $push = function (string $name, bool $passed, ?string $message = null) use (&$results) {
        $results[] = array_filter(['name' => $name, 'passed' => $passed, 'message' => $message], fn($v) => $v !== null);
    };

    // Пустой scope — везде безопасные пустые/нулевые структуры, без SQL
    $emptyIds = [];
    $push('empty_scope_revenue_by_restaurants', is_array(stats_revenue_by_restaurants($emptyIds, null, null)) && count(stats_revenue_by_restaurants($emptyIds, null, null)) === 0);
    $push('empty_scope_revenue_summary', is_array($s = stats_revenue_summary($emptyIds, null, null)) && isset($s['revenue'], $s['orders'], $s['avg']) && $s['orders'] === 0);
    $push('empty_scope_conversion', is_array($c = stats_conversion($emptyIds, null, null)) && isset($c['total'], $c['paid']) && $c['total'] === 0);
    $push('empty_scope_top_items', is_array(stats_top_items($emptyIds, null, null, 3)) && count(stats_top_items($emptyIds, null, null, 3)) === 0);
    $push('empty_scope_top_categories', is_array(stats_top_categories($emptyIds, null, null, 3)) && count(stats_top_categories($emptyIds, null, null, 3)) === 0);
    $push('empty_scope_margin', is_array($m = stats_margin($emptyIds, null, null)) && isset($m['revenue'], $m['profit']) && $m['revenue'] === 0.0);
    $push('empty_scope_top_share', is_array($ts = stats_top_share($emptyIds, null, null)) && isset($ts['top3_revenue'], $ts['total_revenue']) && $ts['total_revenue'] === 0.0);
    $hm = stats_hourly_heatmap($emptyIds, null, null);
    $push('empty_scope_heatmap', is_array($hm) && count($hm) === 24 && array_sum($hm) === 0);
    $push('empty_scope_daily_revenue', is_array(stats_daily_revenue($emptyIds, 7)) && count(stats_daily_revenue($emptyIds, 7)) === 0);
    $push('empty_scope_alerts', is_array(stats_alerts($emptyIds, '7d', null, null)));

    // limit=0 — топы возвращают []
    $push('limit_zero_top_items', is_array(stats_top_items([1], null, null, 0)) && count(stats_top_items([1], null, null, 0)) === 0);
    $push('limit_zero_top_categories', is_array(stats_top_categories([1], null, null, 0)) && count(stats_top_categories([1], null, null, 0)) === 0);

    // limit=3 — формат списка с name, revenue, qty (или пустой массив)
    $top3 = stats_top_items([1], null, null, 3);
    $ok = is_array($top3);
    if ($ok && count($top3) > 0) {
        $row = $top3[0];
        $ok = is_array($row) && array_key_exists('name', $row) && array_key_exists('revenue', $row) && array_key_exists('qty', $row);
    }
    $push('top_items_shape_limit3', $ok);

    // Невалидные даты — не падать
    $convBad = stats_conversion([1], 'invalid', 'invalid');
    $push('invalid_dates_conversion', is_array($convBad) && array_key_exists('total', $convBad));

    // range=all — prev period null
    [$prevStart, $prevEnd] = stats_prev_period_range('all', '2025-01-01 00:00:00', '2025-01-31 23:59:59');
    $push('range_all_prev_period_null', $prevStart === null && $prevEnd === null);

    // Consistency (sanity): revenue >= 0, orders >= 0, top_share <= 100, heatmap sum <= paid (read-only SELECT)
    $conv = stats_conversion([1], null, null);
    $summary = stats_revenue_summary([1], null, null);
    $push('consistency_revenue_non_neg', isset($summary['revenue']) && (float)$summary['revenue'] >= 0);
    $push('consistency_orders_non_neg', isset($summary['orders']) && (int)$summary['orders'] >= 0);
    $ts = stats_top_share([1], null, null);
    $shareOk = !isset($ts['share_pct']) || $ts['share_pct'] === null || ((float)$ts['share_pct'] >= 0 && (float)$ts['share_pct'] <= 100.0);
    $push('consistency_top_share_pct_range', $shareOk);
    $hm = stats_hourly_heatmap([1], null, null);
    $paid = isset($conv['paid']) ? (int)$conv['paid'] : 0;
    $heatmapSum = is_array($hm) ? array_sum($hm) : 0;
    $push('consistency_heatmap_sum_lte_paid', $heatmapSum <= $paid, $heatmapSum > $paid ? "heatmap_sum=$heatmapSum paid=$paid" : null);

    return $results;
}
