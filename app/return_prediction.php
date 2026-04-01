<?php
/**
 * Guest return prediction: heuristic High/Medium/Low from visits, last_visit, spend.
 * No ML; simple rules. Graceful fallback.
 */

/**
 * Predict return likelihood for one guest.
 * @param array $guest keys: visits_count, last_seen_at, total_spent (optional)
 * @return array{label: string, score: int, reason: string}
 */
function predict_guest_return(array $guest): array
{
    $visits = (int) ($guest['visits_count'] ?? 0);
    $lastSeen = $guest['last_seen_at'] ?? null;
    $totalSpent = (float) ($guest['total_spent'] ?? 0);
    $daysAgo = $lastSeen ? max(0, (int) round((time() - strtotime($lastSeen)) / 86400)) : 999;

    if ($visits >= 4 && $daysAgo <= 14) {
        return ['label' => 'High', 'score' => 82, 'reason' => 'Visited ' . $visits . ' times in the last month'];
    }
    if ($visits >= 2 && $daysAgo <= 30) {
        return ['label' => 'High', 'score' => 75, 'reason' => 'Regular visitor, last seen ' . $daysAgo . ' days ago'];
    }
    if ($visits >= 1 && $daysAgo <= 14) {
        return ['label' => 'Medium', 'score' => 55, 'reason' => 'Recent visit; one more visit would boost loyalty'];
    }
    if ($visits >= 2 && $daysAgo <= 60) {
        return ['label' => 'Medium', 'score' => 45, 'reason' => 'Visited ' . $visits . ' times; hasn\'t been back in ' . $daysAgo . ' days'];
    }
    if ($visits >= 1 && $daysAgo <= 45) {
        return ['label' => 'Low', 'score' => 30, 'reason' => 'One visit, ' . $daysAgo . ' days ago — good comeback candidate'];
    }
    if ($visits > 0) {
        return ['label' => 'Low', 'score' => 20, 'reason' => 'Inactive ' . $daysAgo . ' days — send a reminder'];
    }
    return ['label' => 'Low', 'score' => 10, 'reason' => 'No visit history yet'];
}

/**
 * Get return predictions for top guests (for CRM list).
 * @param int $restaurantId
 * @param int $limit default 20
 * @return array<array> each: guest fields + label, score, reason
 */
function get_guest_return_predictions(int $restaurantId, int $limit = 20): array
{
    $restaurantId = (int) $restaurantId;
    $limit = max(1, min(50, $limit));

    if (!function_exists('db')) {
        return [];
    }
    if (function_exists('db_table_exists') && !db_table_exists('crm_guests')) {
        return [];
    }

    try {
        $pdo = db();
        $stmt = $pdo->prepare("
            SELECT g.id, g.phone, g.visits_count, g.last_seen_at,
                   (SELECT COALESCE(SUM(v.total_amount), 0) FROM crm_visits v WHERE v.guest_id = g.id AND v.restaurant_id = g.restaurant_id) AS total_spent
            FROM crm_guests g
            WHERE g.restaurant_id = ?
            ORDER BY g.last_seen_at DESC
            LIMIT " . (int) $limit
        );
        $stmt->execute([$restaurantId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $out = [];
        foreach ($rows as $r) {
            $guest = [
                'id' => (int) $r['id'],
                'phone' => (string) $r['phone'],
                'visits_count' => (int) ($r['visits_count'] ?? 0),
                'last_seen_at' => $r['last_seen_at'] ?? null,
                'total_spent' => (float) ($r['total_spent'] ?? 0),
            ];
            $pred = predict_guest_return($guest);
            $guest['return_label'] = $pred['label'];
            $guest['return_score'] = $pred['score'];
            $guest['return_reason'] = $pred['reason'];
            $out[] = $guest;
        }
        return $out;
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('return_prediction ' . $e->getMessage());
        }
        return [];
    }
}
