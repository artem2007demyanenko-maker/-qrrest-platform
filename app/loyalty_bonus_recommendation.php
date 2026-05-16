<?php
/**
 * Value layer for loyalty manual-action recommendations (no auto-credit).
 * Deterministic, explainable bounds from order context when available.
 */

require_once __DIR__ . '/db.php';
if (file_exists(__DIR__ . '/schema_guard.php')) {
    require_once __DIR__ . '/schema_guard.php';
}
if (file_exists(__DIR__ . '/crm_repo.php')) {
    require_once __DIR__ . '/crm_repo.php';
}

if (!function_exists('loyalty_bonus_fetch_order_total')) {
    /**
     * @return float Order total in same currency units as stored (e.g. RUB).
     */
    function loyalty_bonus_fetch_order_total(PDO $pdo, int $restaurantId, int $orderId): float
    {
        if ($restaurantId <= 0 || $orderId <= 0) {
            return 0.0;
        }
        try {
            $amountCol = (function_exists('db_column_exists') && db_column_exists('orders', 'total_amount'))
                ? 'total_amount'
                : 'total_price';
            $stmt = $pdo->prepare("SELECT COALESCE({$amountCol}, 0) AS amt FROM orders WHERE id = ? AND restaurant_id = ? LIMIT 1");
            $stmt->execute([$orderId, $restaurantId]);
            return (float)($stmt->fetchColumn() ?: 0.0);
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('loyalty_bonus_fetch_order_total ' . $e->getMessage());
            }
            return 0.0;
        }
    }
}

if (!function_exists('loyalty_bonus_guest_visits_count')) {
    function loyalty_bonus_guest_visits_count(int $restaurantId, ?int $guestId): int
    {
        if ($restaurantId <= 0 || $guestId === null || $guestId <= 0) {
            return 0;
        }
        try {
            if (!function_exists('crm_confirmed_guest_metrics_row') && file_exists(__DIR__ . '/crm_repo.php')) {
                require_once __DIR__ . '/crm_repo.php';
            }
            if (!function_exists('crm_confirmed_guest_metrics_row')) {
                return 0;
            }
            $g = crm_confirmed_guest_metrics_row($restaurantId, $guestId);
            if (!$g) {
                return 0;
            }
            return (int)($g['visits_count'] ?? 0);
        } catch (Throwable $e) {
            return 0;
        }
    }
}

if (!function_exists('loyalty_bonus_value_layer_active')) {
    function loyalty_bonus_value_layer_active(int $restaurantId): bool
    {
        if (!function_exists('loyalty_return_mode_enabled') && file_exists(__DIR__ . '/loyalty_return_mode.php')) {
            require_once __DIR__ . '/loyalty_return_mode.php';
        }
        return function_exists('loyalty_return_mode_enabled') && loyalty_return_mode_enabled($restaurantId);
    }
}

if (!function_exists('loyalty_bonus_clamp_int')) {
    function loyalty_bonus_clamp_int(float $x, int $min, int $max): int
    {
        $v = (int)round($x);
        if ($v < $min) {
            return $min;
        }
        if ($v > $max) {
            return $max;
        }
        return $v;
    }
}

if (!function_exists('get_loyalty_recovery_bonus_recommendation')) {
    /**
     * Recovery (low rating): 5% of order, min 50, max 200; rating 1 slightly stronger; high visit count small bump.
     * Returns null when loyalty return mode is OFF (no recommendation layer).
     *
     * @return ?array{
     *   recommended_bonus_points:int,
     *   reason_code:string,
     *   reason_text:string,
     *   operator_summary:string,
     *   order_total:float,
     *   bonus_percent_equivalent:float|null,
     *   visits_count:int
     * }
     */
    function get_loyalty_recovery_bonus_recommendation(int $restaurantId, int $orderId, ?int $guestId = null, ?int $rating = null): ?array
    {
        $restaurantId = (int)$restaurantId;
        $orderId = (int)$orderId;
        $guestId = $guestId !== null ? (int)$guestId : null;
        $rating = $rating !== null ? max(1, min(2, (int)$rating)) : null;

        if (!loyalty_bonus_value_layer_active($restaurantId)) {
            return null;
        }

        $visits = loyalty_bonus_guest_visits_count($restaurantId, $guestId);
        $orderTotal = 0.0;
        try {
            $pdo = db();
            $orderTotal = loyalty_bonus_fetch_order_total($pdo, $restaurantId, $orderId);
        } catch (Throwable $e) {
            $orderTotal = 0.0;
        }

        $effectiveTotal = $orderTotal > 0 ? $orderTotal : 1000.0;
        $basePct = 0.05;
        $ratingMult = ($rating === 1) ? 1.1 : 1.0;
        $visitMult = ($visits >= 5) ? 1.05 : 1.0;
        $raw = $effectiveTotal * $basePct * $ratingMult * $visitMult;
        $points = loyalty_bonus_clamp_int($raw, 50, 200);

        $pctEquiv = $orderTotal > 0 ? round(($points / $orderTotal) * 100, 2) : round(($points / $effectiveTotal) * 100, 2);

        $reasonText = 'Низкая оценка';
        if ($orderTotal > 0) {
            $reasonText .= '; сумма заказа ' . number_format($orderTotal, 0, '.', ' ') . ' ₽';
        } else {
            $reasonText .= '; сумма заказа неизвестна — оценка по безопасной базе';
        }
        if ($rating === 1) {
            $reasonText .= '; оценка 1★ (усиленный коэффициент)';
        }
        if ($visits >= 5) {
            $reasonText .= '; много визитов — слегка выше базы';
        }
        $reasonText .= '; консервативный бонус для компенсации.';

        $opSum = $orderTotal > 0
            ? sprintf(
                'Рекомендуем %d бонусных баллов (~%s%% от заказа %s ₽) для восстановления после низкой оценки.',
                $points,
                number_format($pctEquiv, 1, '.', ''),
                number_format($orderTotal, 0, '.', ' ')
            )
            : sprintf(
                'Рекомендуем %d бонусных баллов (оценка от суммы заказа недоступна; использована безопасная база).',
                $points
            );

        return [
            'recommended_bonus_points' => $points,
            'reason_code' => 'low_rating_recovery',
            'reason_text' => $reasonText,
            'operator_summary' => $opSum,
            'order_total' => round($orderTotal, 2),
            'bonus_percent_equivalent' => $pctEquiv,
            'visits_count' => $visits,
        ];
    }
}

if (!function_exists('get_loyalty_return_bonus_recommendation')) {
    /**
     * Positive return: 2% of order, min 20, max 80; repeat guests slightly more conservative.
     * Returns null when loyalty return mode is OFF (no recommendation layer).
     *
     * @return ?array{
     *   recommended_bonus_points:int,
     *   reason_code:string,
     *   reason_text:string,
     *   operator_summary:string,
     *   order_total:float,
     *   bonus_percent_equivalent:float|null,
     *   visits_count:int
     * }
     */
    function get_loyalty_return_bonus_recommendation(int $restaurantId, int $orderId, ?int $guestId = null): ?array
    {
        $restaurantId = (int)$restaurantId;
        $orderId = (int)$orderId;
        $guestId = $guestId !== null ? (int)$guestId : null;

        if (!loyalty_bonus_value_layer_active($restaurantId)) {
            return null;
        }

        $visits = loyalty_bonus_guest_visits_count($restaurantId, $guestId);
        $orderTotal = 0.0;
        try {
            $pdo = db();
            $orderTotal = loyalty_bonus_fetch_order_total($pdo, $restaurantId, $orderId);
        } catch (Throwable $e) {
            $orderTotal = 0.0;
        }

        $effectiveTotal = $orderTotal > 0 ? $orderTotal : 1000.0;
        $basePct = 0.02;
        $visitMult = ($visits >= 3) ? 0.95 : 1.0;
        $raw = $effectiveTotal * $basePct * $visitMult;
        $points = loyalty_bonus_clamp_int($raw, 20, 80);

        $pctEquiv = $orderTotal > 0 ? round(($points / $orderTotal) * 100, 2) : round(($points / $effectiveTotal) * 100, 2);

        $reasonText = 'Высокая оценка; приглашение вернуться';
        if ($orderTotal > 0) {
            $reasonText .= '; сумма заказа ' . number_format($orderTotal, 0, '.', ' ') . ' ₽';
        } else {
            $reasonText .= '; сумма заказа неизвестна — оценка по безопасной базе';
        }
        if ($visits >= 3) {
            $reasonText .= '; повторный гость — консервативный бонус';
        }
        $reasonText .= '.';

        $opSum = $orderTotal > 0
            ? sprintf(
                'Рекомендуем %d бонусных баллов (~%s%% от заказа %s ₽), чтобы пригласить довольного гостя снова.',
                $points,
                number_format($pctEquiv, 1, '.', ''),
                number_format($orderTotal, 0, '.', ' ')
            )
            : sprintf(
                'Рекомендуем %d бонусных баллов (оценка от суммы заказа недоступна; использована безопасная база).',
                $points
            );

        return [
            'recommended_bonus_points' => $points,
            'reason_code' => 'positive_feedback',
            'reason_text' => $reasonText,
            'operator_summary' => $opSum,
            'order_total' => round($orderTotal, 2),
            'bonus_percent_equivalent' => $pctEquiv,
            'visits_count' => $visits,
        ];
    }
}
