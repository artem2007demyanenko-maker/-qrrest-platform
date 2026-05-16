<?php

require_once __DIR__ . '/../../app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache');

$role = function_exists('require_staff_restaurant_access')
    ? require_staff_restaurant_access()
    : null;
if (!is_string($role) || !in_array($role, ['owner', 'admin', 'waiter', 'staff'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied'], JSON_UNESCAPED_UNICODE);
    exit;
}

$restaurantId = (int)($currentRestaurant['id'] ?? 0);
if ($restaurantId <= 0) {
    if (function_exists('qa_runtime_warn_once')) {
        qa_runtime_warn_once('analytics_missing_restaurant_context', 'Analytics summary requested without restaurant context');
    }
    echo json_encode(['success' => false, 'message' => 'Restaurant context required'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = db();
if (!$pdo instanceof PDO) {
    echo json_encode(['success' => false, 'message' => 'DB unavailable'], JSON_UNESCAPED_UNICODE);
    exit;
}

$range = trim((string)($_GET['range'] ?? 'today'));
$from = trim((string)($_GET['from'] ?? ''));
$to = trim((string)($_GET['to'] ?? ''));
$period = function_exists('analytics_period_bounds')
    ? analytics_period_bounds($range, $from, $to)
    : [
        'range_key' => 'today',
        'label' => 'Сегодня',
        'start_at' => date('Y-m-d 00:00:00'),
        'end_at' => date('Y-m-d H:i:s'),
        'prev_start_at' => date('Y-m-d 00:00:00', strtotime('-1 day')),
        'prev_end_at' => date('Y-m-d 23:59:59', strtotime('-1 day')),
        'duration_seconds' => 86400,
    ];

$prevPeriod = $period;
$prevPeriod['start_at'] = (string)($period['prev_start_at'] ?? $period['start_at']);
$prevPeriod['end_at'] = (string)($period['prev_end_at'] ?? $period['end_at']);

try {
    if (function_exists('qa_runtime_warn_once')) {
        if (!function_exists('delivery_operational_analytics')) {
            qa_runtime_warn_once('analytics_delivery_ops_helper_missing', 'delivery_operational_analytics helper missing', ['restaurant_id' => $restaurantId]);
        }
        if (!function_exists('forecast_operational_foundation')) {
            qa_runtime_warn_once('analytics_forecasting_helper_missing', 'forecast_operational_foundation helper missing', ['restaurant_id' => $restaurantId]);
        }
    }

    $orders = function_exists('analytics_orders_summary')
        ? analytics_orders_summary($pdo, $restaurantId, $period)
        : ['orders_count' => 0, 'paid_orders_count' => 0, 'revenue' => 0.0, 'average_check' => 0.0, 'types' => []];
    $ordersPrev = function_exists('analytics_orders_summary')
        ? analytics_orders_summary($pdo, $restaurantId, $prevPeriod)
        : ['orders_count' => 0, 'paid_orders_count' => 0, 'revenue' => 0.0, 'average_check' => 0.0, 'types' => []];

    $revenue = function_exists('analytics_revenue_summary')
        ? analytics_revenue_summary($pdo, $restaurantId, $period)
        : ['revenue' => 0.0, 'average_check' => 0.0, 'paid_orders_count' => 0, 'delivery_revenue' => 0.0, 'hall_revenue' => 0.0, 'pickup_revenue' => 0.0, 'preorder_revenue' => 0.0, 'manual_revenue' => 0.0];
    $revenuePrev = function_exists('analytics_revenue_summary')
        ? analytics_revenue_summary($pdo, $restaurantId, $prevPeriod)
        : ['revenue' => 0.0, 'average_check' => 0.0, 'paid_orders_count' => 0, 'delivery_revenue' => 0.0, 'hall_revenue' => 0.0, 'pickup_revenue' => 0.0, 'preorder_revenue' => 0.0, 'manual_revenue' => 0.0];

    $delivery = function_exists('analytics_delivery_summary')
        ? analytics_delivery_summary($pdo, $restaurantId, $period)
        : ['delivery_orders_count' => 0, 'delivery_revenue' => 0.0, 'active_delivery_count' => 0, 'overdue_delivery_count' => 0, 'waiting_courier_count' => 0, 'on_the_way_count' => 0, 'delivered_count' => 0, 'avg_delivery_minutes' => 0, 'avg_pickup_minutes' => 0];
    $deliveryPrev = function_exists('analytics_delivery_summary')
        ? analytics_delivery_summary($pdo, $restaurantId, $prevPeriod)
        : ['delivery_orders_count' => 0, 'delivery_revenue' => 0.0, 'active_delivery_count' => 0, 'overdue_delivery_count' => 0, 'waiting_courier_count' => 0, 'on_the_way_count' => 0, 'delivered_count' => 0, 'avg_delivery_minutes' => 0, 'avg_pickup_minutes' => 0];

    $deliveryOps = function_exists('delivery_operational_analytics')
        ? delivery_operational_analytics($pdo, $restaurantId, $period, ['delivery_sla_target_minutes' => 35])
        : ['heatmap' => [], 'zone' => ['rows' => [], 'totals' => []], 'sla' => [], 'courier' => [], 'profitability' => [], 'hotspots' => ['alerts' => []], 'alerts' => []];
    $deliveryOpsPrev = function_exists('delivery_operational_analytics')
        ? delivery_operational_analytics($pdo, $restaurantId, $prevPeriod, ['delivery_sla_target_minutes' => 35])
        : ['heatmap' => [], 'zone' => ['rows' => [], 'totals' => []], 'sla' => [], 'courier' => [], 'profitability' => [], 'hotspots' => ['alerts' => []], 'alerts' => []];

    $loyalty = function_exists('analytics_loyalty_summary')
        ? analytics_loyalty_summary($pdo, $restaurantId, $period)
        : ['usage_orders_count' => 0, 'tx_count' => 0, 'earn_points' => 0, 'spend_points' => 0, 'adjustment_points' => 0, 'refund_points' => 0];
    $loyaltyPrev = function_exists('analytics_loyalty_summary')
        ? analytics_loyalty_summary($pdo, $restaurantId, $prevPeriod)
        : ['usage_orders_count' => 0, 'tx_count' => 0, 'earn_points' => 0, 'spend_points' => 0, 'adjustment_points' => 0, 'refund_points' => 0];

    $reservations = function_exists('analytics_reservation_summary')
        ? analytics_reservation_summary($pdo, $restaurantId, $period)
        : ['created_count' => 0, 'confirmed_count' => 0, 'seated_count' => 0, 'completed_count' => 0, 'cancelled_count' => 0, 'no_show_count' => 0, 'upcoming_count' => 0, 'current_count' => 0, 'occupancy_estimate' => 0];

    $guests = function_exists('analytics_guest_summary')
        ? analytics_guest_summary($pdo, $restaurantId, $period)
        : ['known_guests' => 0, 'active_guests_period' => 0, 'repeat_guests_period' => 0, 'new_guests_period' => 0, 'repeat_rate_percent' => 0.0];
    $guestsPrev = function_exists('analytics_guest_summary')
        ? analytics_guest_summary($pdo, $restaurantId, $prevPeriod)
        : ['known_guests' => 0, 'active_guests_period' => 0, 'repeat_guests_period' => 0, 'new_guests_period' => 0, 'repeat_rate_percent' => 0.0];

    $promo = function_exists('analytics_promo_summary')
        ? analytics_promo_summary($pdo, $restaurantId, $period)
        : ['usage_count' => 0, 'orders_with_promo' => 0];
    $promoPrev = function_exists('analytics_promo_summary')
        ? analytics_promo_summary($pdo, $restaurantId, $prevPeriod)
        : ['usage_count' => 0, 'orders_with_promo' => 0];

    $tips = function_exists('analytics_tips_summary')
        ? analytics_tips_summary($pdo, $restaurantId, $period)
        : ['tips_paid_total' => 0.0, 'tips_paid_count' => 0, 'tips_pending_count' => 0, 'tips_avg' => 0.0];
    $tipsPrev = function_exists('analytics_tips_summary')
        ? analytics_tips_summary($pdo, $restaurantId, $prevPeriod)
        : ['tips_paid_total' => 0.0, 'tips_paid_count' => 0, 'tips_pending_count' => 0, 'tips_avg' => 0.0];

    $reviews = function_exists('analytics_reviews_summary')
        ? analytics_reviews_summary($pdo, $restaurantId, $period)
        : ['reviews_count' => 0, 'avg_rating' => 0.0, 'nps_score' => null, 'low_rating_count' => 0];
    $reviewsPrev = function_exists('analytics_reviews_summary')
        ? analytics_reviews_summary($pdo, $restaurantId, $prevPeriod)
        : ['reviews_count' => 0, 'avg_rating' => 0.0, 'nps_score' => null, 'low_rating_count' => 0];

    $trends = [
        'revenue' => function_exists('analytics_trend_delta') ? analytics_trend_delta((float)($revenue['revenue'] ?? 0), (float)($revenuePrev['revenue'] ?? 0)) : null,
        'orders' => function_exists('analytics_trend_delta') ? analytics_trend_delta((float)($orders['orders_count'] ?? 0), (float)($ordersPrev['orders_count'] ?? 0)) : null,
        'average_check' => function_exists('analytics_trend_delta') ? analytics_trend_delta((float)($revenue['average_check'] ?? 0), (float)($revenuePrev['average_check'] ?? 0)) : null,
        'delivery_revenue' => function_exists('analytics_trend_delta') ? analytics_trend_delta((float)($delivery['delivery_revenue'] ?? 0), (float)($deliveryPrev['delivery_revenue'] ?? 0)) : null,
        'repeat_guests' => function_exists('analytics_trend_delta') ? analytics_trend_delta((float)($guests['repeat_guests_period'] ?? 0), (float)($guestsPrev['repeat_guests_period'] ?? 0)) : null,
        'loyalty_usage' => function_exists('analytics_trend_delta') ? analytics_trend_delta((float)($loyalty['usage_orders_count'] ?? 0), (float)($loyaltyPrev['usage_orders_count'] ?? 0)) : null,
        'promo_usage' => function_exists('analytics_trend_delta') ? analytics_trend_delta((float)($promo['usage_count'] ?? 0), (float)($promoPrev['usage_count'] ?? 0)) : null,
        'tips_total' => function_exists('analytics_trend_delta') ? analytics_trend_delta((float)($tips['tips_paid_total'] ?? 0), (float)($tipsPrev['tips_paid_total'] ?? 0)) : null,
        'reviews_count' => function_exists('analytics_trend_delta') ? analytics_trend_delta((float)($reviews['reviews_count'] ?? 0), (float)($reviewsPrev['reviews_count'] ?? 0)) : null,
        'delivery_sla_success' => function_exists('analytics_trend_delta')
            ? analytics_trend_delta(
                (float)($deliveryOps['sla']['sla_success_percent'] ?? 0),
                (float)($deliveryOpsPrev['sla']['sla_success_percent'] ?? 0)
            )
            : null,
        'delivery_margin' => function_exists('analytics_trend_delta')
            ? analytics_trend_delta(
                (float)($deliveryOps['profitability']['estimated_margin'] ?? 0),
                (float)($deliveryOpsPrev['profitability']['estimated_margin'] ?? 0)
            )
            : null,
    ];

    $forecasting = [
        'today' => function_exists('forecast_operational_foundation')
            ? forecast_operational_foundation($pdo, $restaurantId, ['target' => 'today'])
            : [],
        'tomorrow' => function_exists('forecast_operational_foundation')
            ? forecast_operational_foundation($pdo, $restaurantId, ['target' => 'tomorrow'])
            : [],
        'next_shift' => function_exists('forecast_operational_foundation')
            ? forecast_operational_foundation($pdo, $restaurantId, ['target' => 'next_shift'])
            : [],
        'next_daypart' => function_exists('forecast_operational_foundation')
            ? forecast_operational_foundation($pdo, $restaurantId, ['target' => 'next_daypart'])
            : [],
    ];

    $alertsPayload = [
        'trends' => $trends,
        'delivery' => $delivery,
        'delivery_ops' => $deliveryOps,
        'reviews' => $reviews,
        'reservations' => $reservations,
    ];
    $alerts = function_exists('analytics_operational_alerts')
        ? analytics_operational_alerts($alertsPayload)
        : [];
    $forecastAlertsToday = is_array($forecasting['today']['alerts'] ?? null) ? $forecasting['today']['alerts'] : [];
    if ($forecastAlertsToday !== []) {
        foreach ($forecastAlertsToday as $fa) {
            if (!is_array($fa)) {
                continue;
            }
            $alerts[] = [
                'key' => (string)($fa['key'] ?? 'forecast_alert'),
                'level' => (string)($fa['level'] ?? 'warning'),
                'label' => (string)($fa['label'] ?? 'Forecast alert'),
                'message' => (string)($fa['message'] ?? ''),
            ];
        }
    }

    $hourlyOrders = function_exists('analytics_hourly_orders')
        ? analytics_hourly_orders($pdo, $restaurantId, $period)
        : [];
    $revenueTrend = function_exists('analytics_revenue_trend')
        ? analytics_revenue_trend($pdo, $restaurantId, $period)
        : [];

    $orderTypesSplit = [];
    foreach ((array)($orders['types'] ?? []) as $typeKey => $typeData) {
        $orderTypesSplit[] = [
            'type' => (string)$typeKey,
            'label' => (string)($typeData['label'] ?? order_type_label((string)$typeKey, null)),
            'count' => (int)($typeData['count'] ?? 0),
            'revenue' => (float)($typeData['revenue'] ?? 0),
        ];
    }

    $deliveryVsDineIn = [
        'delivery' => [
            'orders_count' => (int)($orders['types']['delivery']['count'] ?? 0),
            'revenue' => (float)($orders['types']['delivery']['revenue'] ?? 0),
        ],
        'dine_in' => [
            'orders_count' => (int)($orders['types']['hall']['count'] ?? 0),
            'revenue' => (float)($orders['types']['hall']['revenue'] ?? 0),
        ],
    ];

    echo json_encode([
        'success' => true,
        'period' => [
            'range_key' => (string)($period['range_key'] ?? 'today'),
            'label' => (string)($period['label'] ?? 'Сегодня'),
            'start_at' => (string)($period['start_at'] ?? ''),
            'end_at' => (string)($period['end_at'] ?? ''),
            'prev_start_at' => (string)($period['prev_start_at'] ?? ''),
            'prev_end_at' => (string)($period['prev_end_at'] ?? ''),
        ],
        'orders' => $orders,
        'revenue' => $revenue,
        'delivery' => $delivery,
        'delivery_ops' => $deliveryOps,
        'forecasting' => $forecasting,
        'loyalty' => $loyalty,
        'reservations' => $reservations,
        'guests' => $guests,
        'promo' => $promo,
        'tips' => $tips,
        'reviews' => $reviews,
        'trends' => $trends,
        'alerts' => $alerts,
        'charts' => [
            'hourly_orders' => $hourlyOrders,
            'revenue_trend' => $revenueTrend,
            'order_types_split' => $orderTypesSplit,
            'delivery_vs_dine_in' => $deliveryVsDineIn,
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('ANALYTICS_SUMMARY_FAIL rest_id=' . $restaurantId . ' ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Analytics unavailable',
    ], JSON_UNESCAPED_UNICODE);
}
