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
    if (function_exists('db_table_exists') && !db_table_exists('crm_guests')) {
        return [];
    }

    try {
        $out = [];
        foreach (crm_confirmed_guest_metrics_rows($restaurantId) as $r) {
            if ((int)($r['consent'] ?? 0) !== 1) {
                continue;
            }
            $visits = (int)($r['visits_count'] ?? 0);
            $lastSeen = $r['last_seen_at'] ? (string)$r['last_seen_at'] : null;
            if ($visits <= 0) {
                continue;
            }
            if ($lastSeen !== null && strtotime($lastSeen) >= strtotime("-{$daysInactive} days")) {
                continue;
            }
            $out[] = [
                'id' => (int) $r['id'],
                'phone' => (string) $r['phone'],
                'last_seen_at' => $lastSeen,
                'visits_count' => $visits,
                'suggestion' => 'Invite back with a special offer',
            ];
        }
        usort($out, static function (array $a, array $b): int {
            $aTs = !empty($a['last_seen_at']) ? strtotime((string)$a['last_seen_at']) : 0;
            $bTs = !empty($b['last_seen_at']) ? strtotime((string)$b['last_seen_at']) : 0;
            return $aTs <=> $bTs;
        });
        $out = array_slice($out, 0, $limit);
        return $out;
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('retention_suggestions ' . $e->getMessage());
        }
        return [];
    }
}
