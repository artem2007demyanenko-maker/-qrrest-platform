<?php
/**
 * Read-only feedback analytics (validated by real orders).
 */

require_once __DIR__ . '/db.php';
if (file_exists(__DIR__ . '/schema_guard.php')) {
    require_once __DIR__ . '/schema_guard.php';
}

if (!function_exists('feedback_analytics_days_window')) {
    function feedback_analytics_days_window(int $days): int
    {
        return max(1, min(365, (int)$days));
    }
}

if (!function_exists('feedback_analytics_is_ready')) {
    function feedback_analytics_is_ready(): bool
    {
        return function_exists('db_table_exists')
            && db_table_exists('order_feedback')
            && db_table_exists('orders');
    }
}

if (!function_exists('get_feedback_analytics_summary')) {
    /**
     * @return array{
     *   total_feedback:int,
     *   average_rating:float|null,
     *   promoters_count:int,
     *   neutral_count:int,
     *   detractors_count:int,
     *   response_window_days:int,
     *   latest_feedback_at:string|null
     * }
     */
    function get_feedback_analytics_summary(int $restaurantId, int $days = 30): array
    {
        $days = feedback_analytics_days_window($days);
        $empty = [
            'total_feedback' => 0,
            'average_rating' => null,
            'promoters_count' => 0,
            'neutral_count' => 0,
            'detractors_count' => 0,
            'response_window_days' => $days,
            'latest_feedback_at' => null,
        ];
        if ($restaurantId <= 0 || !feedback_analytics_is_ready()) {
            return $empty;
        }
        try {
            $pdo = db();
            $stmt = $pdo->prepare("
                SELECT
                    COUNT(*) AS total_feedback,
                    AVG(f.rating) AS avg_rating,
                    SUM(CASE WHEN f.rating = 5 THEN 1 ELSE 0 END) AS promoters_count,
                    SUM(CASE WHEN f.rating IN (3, 4) THEN 1 ELSE 0 END) AS neutral_count,
                    SUM(CASE WHEN f.rating IN (1, 2) THEN 1 ELSE 0 END) AS detractors_count,
                    MAX(f.created_at) AS latest_feedback_at
                FROM order_feedback f
                INNER JOIN orders o
                    ON o.id = f.order_id
                   AND o.restaurant_id = f.restaurant_id
                WHERE f.restaurant_id = :rid
                  AND f.created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
            ");
            $stmt->bindValue(':rid', $restaurantId, PDO::PARAM_INT);
            $stmt->bindValue(':days', $days, PDO::PARAM_INT);
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row || (int)($row['total_feedback'] ?? 0) === 0) {
                return $empty;
            }
            return [
                'total_feedback' => (int)$row['total_feedback'],
                'average_rating' => $row['avg_rating'] !== null ? round((float)$row['avg_rating'], 1) : null,
                'promoters_count' => (int)($row['promoters_count'] ?? 0),
                'neutral_count' => (int)($row['neutral_count'] ?? 0),
                'detractors_count' => (int)($row['detractors_count'] ?? 0),
                'response_window_days' => $days,
                'latest_feedback_at' => !empty($row['latest_feedback_at']) ? (string)$row['latest_feedback_at'] : null,
            ];
        } catch (Throwable $e) {
            error_log('get_feedback_analytics_summary ' . $e->getMessage());
            return $empty;
        }
    }
}

if (!function_exists('get_feedback_rating_breakdown')) {
    /**
     * @return array{1:int,2:int,3:int,4:int,5:int}
     */
    function get_feedback_rating_breakdown(int $restaurantId, int $days = 30): array
    {
        $days = feedback_analytics_days_window($days);
        $empty = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
        if ($restaurantId <= 0 || !feedback_analytics_is_ready()) {
            return $empty;
        }
        try {
            $pdo = db();
            $stmt = $pdo->prepare("
                SELECT f.rating, COUNT(*) AS cnt
                FROM order_feedback f
                INNER JOIN orders o
                    ON o.id = f.order_id
                   AND o.restaurant_id = f.restaurant_id
                WHERE f.restaurant_id = :rid
                  AND f.created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
                GROUP BY f.rating
            ");
            $stmt->bindValue(':rid', $restaurantId, PDO::PARAM_INT);
            $stmt->bindValue(':days', $days, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $rating = (int)($row['rating'] ?? 0);
                if ($rating >= 1 && $rating <= 5) {
                    $empty[$rating] = (int)($row['cnt'] ?? 0);
                }
            }
            return $empty;
        } catch (Throwable $e) {
            error_log('get_feedback_rating_breakdown ' . $e->getMessage());
            return $empty;
        }
    }
}

if (!function_exists('get_feedback_trend_points')) {
    /**
     * @return array<int, array{date:string,total_feedback:int,average_rating:float|null}>
     */
    function get_feedback_trend_points(int $restaurantId, int $days = 30): array
    {
        $days = feedback_analytics_days_window($days);
        if ($restaurantId <= 0 || !feedback_analytics_is_ready()) {
            return [];
        }
        try {
            $pdo = db();
            $stmt = $pdo->prepare("
                SELECT
                    DATE(f.created_at) AS d,
                    COUNT(*) AS total_feedback,
                    AVG(f.rating) AS avg_rating
                FROM order_feedback f
                INNER JOIN orders o
                    ON o.id = f.order_id
                   AND o.restaurant_id = f.restaurant_id
                WHERE f.restaurant_id = :rid
                  AND f.created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
                GROUP BY DATE(f.created_at)
                ORDER BY DATE(f.created_at) ASC
            ");
            $stmt->bindValue(':rid', $restaurantId, PDO::PARAM_INT);
            $stmt->bindValue(':days', $days, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $out = [];
            foreach ($rows as $row) {
                $out[] = [
                    'date' => (string)($row['d'] ?? ''),
                    'total_feedback' => (int)($row['total_feedback'] ?? 0),
                    'average_rating' => $row['avg_rating'] !== null ? round((float)$row['avg_rating'], 2) : null,
                ];
            }
            return $out;
        } catch (Throwable $e) {
            error_log('get_feedback_trend_points ' . $e->getMessage());
            return [];
        }
    }
}

