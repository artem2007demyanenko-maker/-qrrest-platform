<?php
/**
 * Checkout / order abandonment analytics: started_checkout vs completed_checkout.
 * Uses checkout_events table when present; graceful fallback (no 500).
 */

/**
 * Record a checkout event (started or completed). No-op in demo; safe when table missing.
 */
function checkout_event_record(int $restaurantId, string $eventType, ?int $tableId = null): void
{
    if (!function_exists('db') || (function_exists('is_demo_mode') && is_demo_mode())) {
        return;
    }
    if (function_exists('db_table_exists') && !db_table_exists('checkout_events')) {
        return;
    }
    if (!in_array($eventType, ['started_checkout', 'completed_checkout'], true)) {
        return;
    }
    try {
        $pdo = db();
        $pdo->prepare("INSERT INTO checkout_events (restaurant_id, table_id, event_type) VALUES (?, ?, ?)")
            ->execute([$restaurantId, $tableId, $eventType]);
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('checkout_event_record ' . $e->getMessage());
        }
    }
}

/**
 * Get checkout conversion for last N days.
 * @return array{started: int, completed: int, conversion_pct: ?float, recommendation_text: string}
 */
function get_checkout_conversion(int $restaurantId, int $days = 7): array
{
    $restaurantId = (int) $restaurantId;
    $days = max(1, min(90, $days));
    $result = ['started' => 0, 'completed' => 0, 'conversion_pct' => null, 'recommendation_text' => ''];

    if (!function_exists('db')) {
        return $result;
    }
    if (function_exists('db_table_exists') && !db_table_exists('checkout_events')) {
        $result['recommendation_text'] = 'Checkout tracking will show conversion once enabled.';
        return $result;
    }

    try {
        $pdo = db();
        $since = date('Y-m-d 00:00:00', strtotime("-{$days} days"));
        $stmt = $pdo->prepare("
            SELECT event_type, COUNT(*) AS cnt
            FROM checkout_events
            WHERE restaurant_id = ? AND created_at >= ?
            GROUP BY event_type
        ");
        $stmt->execute([$restaurantId, $since]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if ($row['event_type'] === 'started_checkout') {
                $result['started'] = (int) $row['cnt'];
            } elseif ($row['event_type'] === 'completed_checkout') {
                $result['completed'] = (int) $row['cnt'];
            }
        }
        if ($result['started'] > 0) {
            $result['conversion_pct'] = round(100.0 * $result['completed'] / $result['started'], 1);
            if ($result['conversion_pct'] < 70) {
                $result['recommendation_text'] = 'Simplify checkout or reduce steps to improve conversion.';
            } else {
                $result['recommendation_text'] = 'Conversion looks healthy.';
            }
        } else {
            $result['recommendation_text'] = 'Track checkout starts in QR flow to see conversion.';
        }
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('checkout_conversion ' . $e->getMessage());
        }
    }
    return $result;
}
