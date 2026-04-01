<?php
/**
 * Guest visit timeline: visits with date, amount, order items summary.
 * Uses crm_visits + orders + order_items. Graceful fallback.
 */

/**
 * Get guest visit timeline by guest_id or phone.
 * @param int $restaurantId
 * @param int $guestId 0 to resolve by phone
 * @param string|null $phone
 * @return array visits, visits_count, total_spent, last_visit, guest
 */
function get_guest_timeline(int $restaurantId, int $guestId = 0, ?string $phone = null): array
{
    $restaurantId = (int) $restaurantId;
    $result = ['visits' => [], 'visits_count' => 0, 'total_spent' => 0.0, 'last_visit' => null, 'guest' => null];

    if (!function_exists('db')) {
        return $result;
    }
    if (function_exists('db_table_exists') && (!db_table_exists('crm_guests') || !db_table_exists('crm_visits'))) {
        return $result;
    }

    try {
        $pdo = db();
        if ($guestId <= 0 && $phone !== null && $phone !== '') {
            if (file_exists(__DIR__ . '/crm_repo.php')) {
                require_once __DIR__ . '/crm_repo.php';
                if (function_exists('crm_normalize_phone')) {
                    $phone = crm_normalize_phone($phone) ?? trim($phone);
                }
            }
            $stmt = $pdo->prepare("SELECT id, phone, visits_count, last_seen_at FROM crm_guests WHERE restaurant_id = ? AND phone = ? LIMIT 1");
            $stmt->execute([$restaurantId, trim($phone)]);
            $guest = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($guest) {
                $guestId = (int) $guest['id'];
                $result['guest'] = ['id' => $guestId, 'phone' => $guest['phone'], 'visits_count' => (int) ($guest['visits_count'] ?? 0), 'last_seen_at' => $guest['last_seen_at'] ?? null];
            }
        } elseif ($guestId > 0) {
            $stmt = $pdo->prepare("SELECT id, phone, visits_count, last_seen_at FROM crm_guests WHERE restaurant_id = ? AND id = ? LIMIT 1");
            $stmt->execute([$restaurantId, $guestId]);
            $guest = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($guest) {
                $result['guest'] = ['id' => (int) $guest['id'], 'phone' => $guest['phone'], 'visits_count' => (int) ($guest['visits_count'] ?? 0), 'last_seen_at' => $guest['last_seen_at'] ?? null];
            }
        }
        if ($guestId <= 0) {
            return $result;
        }

        $stmt = $pdo->prepare("
            SELECT id, order_id, total_amount, visited_at
            FROM crm_visits
            WHERE restaurant_id = ? AND guest_id = ?
            ORDER BY visited_at DESC
            LIMIT 50
        ");
        $stmt->execute([$restaurantId, $guestId]);
        $visits = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $orderIds = array_filter(array_column($visits, 'order_id'));
        $itemsByOrder = [];
        if ($orderIds !== [] && db_table_exists('order_items') && db_table_exists('menu_items')) {
            $ph = implode(',', array_map('intval', $orderIds));
            $stmt = $pdo->prepare("
                SELECT oi.order_id, mi.name, oi.quantity
                FROM order_items oi
                JOIN orders o ON o.id = oi.order_id AND o.restaurant_id = ?
                JOIN menu_items mi ON mi.id = oi.menu_item_id AND mi.restaurant_id = o.restaurant_id
                WHERE oi.order_id IN ($ph)
            ");
            $stmt->execute([$restaurantId]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $oid = (int) $row['order_id'];
                if (!isset($itemsByOrder[$oid])) {
                    $itemsByOrder[$oid] = [];
                }
                $itemsByOrder[$oid][] = $row['name'] . (($row['quantity'] ?? 1) > 1 ? ' x' . (int) $row['quantity'] : '');
            }
        }

        $totalSpent = 0.0;
        $lastVisit = null;
        foreach ($visits as $v) {
            $oid = $v['order_id'] ? (int) $v['order_id'] : null;
            $itemsPreview = $oid && isset($itemsByOrder[$oid]) ? implode(', ', array_slice($itemsByOrder[$oid], 0, 5)) : '—';
            $amt = (float) ($v['total_amount'] ?? 0);
            $totalSpent += $amt;
            $visitedAt = $v['visited_at'] ?? null;
            if ($visitedAt && ($lastVisit === null || strtotime($visitedAt) > strtotime($lastVisit))) {
                $lastVisit = $visitedAt;
            }
            $result['visits'][] = [
                'date' => $visitedAt,
                'amount' => $amt,
                'order_id' => $oid,
                'items_preview' => $itemsPreview,
            ];
        }
        $result['visits_count'] = count($result['visits']);
        $result['total_spent'] = round($totalSpent, 2);
        $result['last_visit'] = $lastVisit;
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('guest_timeline ' . $e->getMessage());
        }
    }
    return $result;
}
