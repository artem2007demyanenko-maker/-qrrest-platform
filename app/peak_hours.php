<?php
/**
 * Peak hours analytics: when do orders happen (by hour).
 * Uses orders.created_at. Graceful fallback.
 */

/**
 * Get peak hours summary for the last N days.
 * @param int $restaurantId
 * @param int $days 7 or 30
 * @return array{hourly_counts: array<int,int>, peak_hour_range_text: string, recommendation_text: string}
 */
function get_peak_hours_summary(int $restaurantId, int $days = 7): array
{
    $restaurantId = (int) $restaurantId;
    $days = max(1, min(90, $days));

    $result = [
        'hourly_counts' => array_fill(0, 24, 0),
        'peak_hour_range_text' => '',
        'recommendation_text' => '',
    ];

    if (!function_exists('db')) {
        return $result;
    }
    if (function_exists('db_table_exists') && !db_table_exists('orders')) {
        return $result;
    }

    try {
        $pdo = db();
        $since = date('Y-m-d 00:00:00', strtotime("-{$days} days"));
        $stmt = $pdo->prepare("
            SELECT HOUR(created_at) AS h, COUNT(*) AS cnt
            FROM orders
            WHERE restaurant_id = :rest AND order_status <> 'canceled'
              AND created_at >= :since
            GROUP BY HOUR(created_at)
        ");
        $stmt->execute(['rest' => $restaurantId, 'since' => $since]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $h = (int) $row['h'];
            if ($h >= 0 && $h <= 23) {
                $result['hourly_counts'][$h] = (int) $row['cnt'];
            }
        }

        $total = array_sum($result['hourly_counts']);
        if ($total === 0) {
            $result['recommendation_text'] = 'Add orders to see peak hours.';
            return $result;
        }

        $maxCnt = max($result['hourly_counts']);
        $peakHours = [];
        for ($i = 0; $i < 24; $i++) {
            if ($result['hourly_counts'][$i] === $maxCnt && $maxCnt > 0) {
                $peakHours[] = $i;
            }
        }
        if ($peakHours !== []) {
            $start = min($peakHours);
            $end = max($peakHours);
            $result['peak_hour_range_text'] = sprintf('Most orders between %02d:00–%02d:00', $start, $end + 1);
            $result['recommendation_text'] = 'Consider extra staff or promotions during peak hours.';
        } else {
            $result['recommendation_text'] = 'Spread orders across more hours to see patterns.';
        }
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('peak_hours ' . $e->getMessage());
        }
    }
    return $result;
}
