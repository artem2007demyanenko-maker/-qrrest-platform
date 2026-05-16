<?php
/**
 * Read-only guest feedback metrics for restaurant dashboard (order_feedback).
 */

require_once __DIR__ . '/db.php';
if (file_exists(__DIR__ . '/schema_guard.php')) {
    require_once __DIR__ . '/schema_guard.php';
}

/**
 * Aggregated metrics for one restaurant (tenant-scoped).
 *
 * @return array{
 *   total_feedback: int,
 *   average_rating: float|null,
 *   promoters_count: int,
 *   neutral_count: int,
 *   detractors_count: int,
 *   latest_feedback_at: string|null,
 *   feedback_this_month: int
 * }
 */
function get_restaurant_feedback_summary(int $restaurantId): array
{
    $empty = [
        'total_feedback' => 0,
        'average_rating' => null,
        'promoters_count' => 0,
        'neutral_count' => 0,
        'detractors_count' => 0,
        'latest_feedback_at' => null,
        'feedback_this_month' => 0,
    ];
    if ($restaurantId <= 0 || !function_exists('db_table_exists') || !db_table_exists('order_feedback') || !db_table_exists('orders')) {
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
                MAX(f.created_at) AS latest_feedback_at,
                SUM(CASE WHEN f.created_at >= DATE_FORMAT(NOW(), '%Y-%m-01 00:00:00') THEN 1 ELSE 0 END) AS feedback_this_month
            FROM order_feedback f
            INNER JOIN orders o
                ON o.id = f.order_id
               AND o.restaurant_id = f.restaurant_id
            WHERE f.restaurant_id = :rest
        ");
        $stmt->execute(['rest' => $restaurantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || (int)($row['total_feedback'] ?? 0) === 0) {
            return $empty;
        }
        $avg = $row['avg_rating'];
        $averageRating = null;
        if ($avg !== null && $avg !== '') {
            $averageRating = round((float)$avg, 1);
        }

        return [
            'total_feedback' => (int)$row['total_feedback'],
            'average_rating' => $averageRating,
            'promoters_count' => (int)($row['promoters_count'] ?? 0),
            'neutral_count' => (int)($row['neutral_count'] ?? 0),
            'detractors_count' => (int)($row['detractors_count'] ?? 0),
            'latest_feedback_at' => $row['latest_feedback_at'] !== null && $row['latest_feedback_at'] !== ''
                ? (string)$row['latest_feedback_at']
                : null,
            'feedback_this_month' => (int)($row['feedback_this_month'] ?? 0),
        ];
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('get_restaurant_feedback_summary: ' . $e->getMessage());
        }
        return $empty;
    }
}

/**
 * Recent feedback rows with safe order/table context.
 *
 * @return list<array{id:int,rating:int,comment:?string,created_at:string,order_id:int,table_name:?string}>
 */
function get_recent_restaurant_feedback(int $restaurantId, int $limit = 5): array
{
    if ($restaurantId <= 0) {
        return [];
    }
    $limit = max(1, min(50, $limit));
    if (!function_exists('db_table_exists') || !db_table_exists('order_feedback') || !db_table_exists('orders')) {
        return [];
    }
    try {
        $pdo = db();
        $stmt = $pdo->prepare("
            SELECT
                f.id,
                f.rating,
                f.comment,
                f.created_at,
                f.order_id,
                t.name AS table_name
            FROM order_feedback f
            INNER JOIN orders o
                ON o.id = f.order_id
               AND o.restaurant_id = f.restaurant_id
            LEFT JOIN tables t
                ON t.id = o.table_id
               AND t.restaurant_id = o.restaurant_id
            WHERE f.restaurant_id = :rest
            ORDER BY f.created_at DESC
            LIMIT {$limit}
        ");
        $stmt->execute(['rest' => $restaurantId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(static function (array $r): array {
            $tn = isset($r['table_name']) && $r['table_name'] !== '' ? (string)$r['table_name'] : null;
            if ($tn !== null && function_exists('qr_public_owner_order_table_label')) {
                $tn = qr_public_owner_order_table_label($tn);
            }
            return [
                'id' => (int)($r['id'] ?? 0),
                'rating' => (int)($r['rating'] ?? 0),
                'comment' => isset($r['comment']) && $r['comment'] !== '' ? (string)$r['comment'] : null,
                'created_at' => (string)($r['created_at'] ?? ''),
                'order_id' => (int)($r['order_id'] ?? 0),
                'table_name' => ($tn !== null && $tn !== '') ? $tn : null,
            ];
        }, $rows);
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('get_recent_restaurant_feedback: ' . $e->getMessage());
        }
        return [];
    }
}
