<?php
/**
 * Guest return opportunities: suggest guests who haven't visited in X days.
 * Used by CRM "Guest return opportunities" block. Same threshold as retention (INACTIVITY_DAYS).
 */

if (!function_exists('guest_retention_inactive_days') && file_exists(__DIR__ . '/guest_retention.php')) {
    require_once __DIR__ . '/guest_retention.php';
}

/**
 * Get guests to suggest for "comeback" offer (not seen in last $daysInactive days).
 * Default uses guest_retention_inactive_days() so retention and return stay aligned.
 *
 * @param int $restaurantId
 * @param int|null $daysInactive default INACTIVITY_DAYS (14) when null
 * @param int $limit max 10
 * @return array<array{id:int, phone:string, last_seen_at:?string, visits_count:int, suggestion:string}>
 */
function retention_suggestions(int $restaurantId, ?int $daysInactive = null, int $limit = 10): array
{
    $restaurantId = (int) $restaurantId;
    $daysInactive = max(7, min(90, $daysInactive ?? (function_exists('guest_retention_inactive_days') ? guest_retention_inactive_days() : 14)));
    $limit = max(1, min(20, $limit));

    if (!function_exists('db')) {
        return [];
    }
    $pdo = db();
    if (function_exists('db_table_exists') && !db_table_exists('crm_guests')) {
        return [];
    }

    try {
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$daysInactive} days"));
        $stmt = $pdo->prepare("
            SELECT id, phone, last_seen_at, visits_count
            FROM crm_guests
            WHERE restaurant_id = ?
              AND consent = 1
              AND (last_seen_at IS NULL OR last_seen_at < ?)
              AND visits_count > 0
            ORDER BY last_seen_at ASC
            LIMIT " . (int) $limit
        );
        $stmt->execute([$restaurantId, $cutoff]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id' => (int) $r['id'],
                'phone' => (string) $r['phone'],
                'last_seen_at' => $r['last_seen_at'] ? (string) $r['last_seen_at'] : null,
                'visits_count' => (int) ($r['visits_count'] ?? 0),
                'suggestion' => 'Invite back with a special offer',
            ];
        }
        return $out;
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('retention_suggestions ' . $e->getMessage());
        }
        return [];
    }
}
