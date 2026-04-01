<?php

/**
 * Guest Retention Engine helpers.
 *
 * CRM tables are the primary source of truth.
 * guest_visits is kept only as a compatibility mirror for legacy views.
 * Single inactivity threshold used everywhere: retention stats, return candidates, CRM.
 */

if (!defined('INACTIVITY_DAYS')) {
    define('INACTIVITY_DAYS', 14);
}

if (file_exists(__DIR__ . '/schema_guard.php')) {
    require_once __DIR__ . '/schema_guard.php';
}
if (file_exists(__DIR__ . '/crm_repo.php')) {
    require_once __DIR__ . '/crm_repo.php';
}

if (!function_exists('guest_retention_table_exists')) {
    function guest_retention_table_exists(): bool
    {
        if (!function_exists('db_table_exists')) {
            return false;
        }
        return db_table_exists('guest_visits');
    }
}

if (!function_exists('guest_retention_has_crm_source')) {
    function guest_retention_has_crm_source(): bool
    {
        return function_exists('db_table_exists') && db_table_exists('crm_guests');
    }
}

if (!function_exists('guest_retention_inactive_days')) {
    function guest_retention_inactive_days(): int
    {
        return (int) (defined('INACTIVITY_DAYS') ? INACTIVITY_DAYS : 14);
    }
}

/**
 * Record a guest visit mirror row for a completed/paid order.
 *
 * Safety:
 * - No-op in demo mode.
 * - Skips if guest contact is empty.
 * - Skips if guest_visits table is missing.
 * - Skips duplicate rows for same (restaurant_id, order_id).
 */
if (!function_exists('record_guest_visit')) {
    function record_guest_visit(int $restaurant_id, int $order_id, ?string $guest_contact = null, ?string $guest_name = null): void
    {
        $restaurant_id = (int) $restaurant_id;
        $order_id      = (int) $order_id;
        $guest_contact = $guest_contact !== null ? trim($guest_contact) : '';
        $guest_name    = $guest_name !== null ? trim($guest_name) : null;

        if ($restaurant_id <= 0 || $order_id <= 0) {
            return;
        }
        if (function_exists('crm_normalize_phone')) {
            $guest_contact = crm_normalize_phone($guest_contact) ?? '';
        }
        if ($guest_contact === '') {
            return;
        }
        if (function_exists('is_demo_mode') && is_demo_mode()) {
            return;
        }
        if (!function_exists('db') || !guest_retention_table_exists()) {
            return;
        }

        try {
            $pdo = db();

            $stmt = $pdo->prepare('SELECT id FROM guest_visits WHERE restaurant_id = ? AND order_id = ? LIMIT 1');
            $stmt->execute([$restaurant_id, $order_id]);
            if ($stmt->fetchColumn()) {
                return;
            }

            $stmt = $pdo->prepare('INSERT INTO guest_visits (restaurant_id, order_id, guest_contact, guest_name) VALUES (?, ?, ?, ?)');
            $stmt->execute([$restaurant_id, $order_id, $guest_contact, $guest_name]);
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('guest_retention record_guest_visit ' . $e->getMessage());
            }
        }
    }
}

/**
 * Get inactive guests for a restaurant.
 *
 * @return array<int, array{guest_contact: string, last_visit: string, visits_count: int, guest_name: ?string, inactivity_days: int}>
 */
if (!function_exists('get_inactive_guests')) {
    function get_inactive_guests(int $restaurant_id, ?int $days = null, int $limit = 20): array
    {
        $restaurant_id = (int) $restaurant_id;
        $days = max(1, $days ?? guest_retention_inactive_days());
        $limit = max(1, min(200, $limit));

        if ($restaurant_id <= 0 || !function_exists('db')) {
            return [];
        }

        try {
            $pdo = db();

            if (guest_retention_has_crm_source()) {
                $sql = "
                    SELECT
                        phone AS guest_contact,
                        last_seen_at AS last_visit,
                        visits_count,
                        NULL AS guest_name,
                        DATEDIFF(NOW(), last_seen_at) AS inactivity_days
                    FROM crm_guests
                    WHERE restaurant_id = :rest
                      AND consent = 1
                      AND visits_count > 0
                      AND (last_seen_at IS NULL OR last_seen_at < DATE_SUB(NOW(), INTERVAL :days DAY))
                    ORDER BY COALESCE(last_seen_at, '1970-01-01 00:00:00') ASC
                    LIMIT :limit
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->bindValue(':rest', $restaurant_id, PDO::PARAM_INT);
                $stmt->bindValue(':days', $days, PDO::PARAM_INT);
                $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
                $stmt->execute();
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } elseif (guest_retention_table_exists()) {
                $sql = "
                    SELECT 
                        guest_contact,
                        MAX(visited_at) AS last_visit,
                        COUNT(*) AS visits_count,
                        MAX(guest_name) AS guest_name,
                        DATEDIFF(NOW(), MAX(visited_at)) AS inactivity_days
                    FROM guest_visits
                    WHERE restaurant_id = :rest
                      AND guest_contact IS NOT NULL
                      AND guest_contact <> ''
                    GROUP BY guest_contact
                    HAVING last_visit < DATE_SUB(NOW(), INTERVAL :days DAY)
                    ORDER BY last_visit ASC
                    LIMIT :limit
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->bindValue(':rest', $restaurant_id, PDO::PARAM_INT);
                $stmt->bindValue(':days', $days, PDO::PARAM_INT);
                $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
                $stmt->execute();
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                return [];
            }

            foreach ($rows as &$row) {
                $row['visits_count'] = (int)($row['visits_count'] ?? 0);
                $row['guest_contact'] = (string)($row['guest_contact'] ?? '');
                $row['guest_name'] = $row['guest_name'] !== null ? (string)$row['guest_name'] : null;
                $row['last_visit'] = (string)($row['last_visit'] ?? '');
                $row['inactivity_days'] = (int)($row['inactivity_days'] ?? 0);
            }
            unset($row);
            return $rows;
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('guest_retention get_inactive_guests ' . $e->getMessage());
            }
            return [];
        }
    }
}

/**
 * Get total visit count for a given guest contact.
 */
if (!function_exists('get_guest_visit_count')) {
    function get_guest_visit_count(int $restaurant_id, string $guest_contact): int
    {
        $restaurant_id = (int) $restaurant_id;
        $guest_contact = trim($guest_contact);
        if (function_exists('crm_normalize_phone')) {
            $guest_contact = crm_normalize_phone($guest_contact) ?? '';
        }
        if ($restaurant_id <= 0 || $guest_contact === '' || !function_exists('db')) {
            return 0;
        }
        try {
            $pdo = db();
            if (guest_retention_has_crm_source()) {
                $stmt = $pdo->prepare('SELECT visits_count FROM crm_guests WHERE restaurant_id = ? AND phone = ? LIMIT 1');
                $stmt->execute([$restaurant_id, $guest_contact]);
                return (int)($stmt->fetchColumn() ?: 0);
            }
            if (!guest_retention_table_exists()) {
                return 0;
            }
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM guest_visits WHERE restaurant_id = ? AND guest_contact = ?');
            $stmt->execute([$restaurant_id, $guest_contact]);
            return (int) $stmt->fetchColumn();
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('guest_retention get_guest_visit_count ' . $e->getMessage());
            }
            return 0;
        }
    }
}

/**
 * High-level retention opportunities for CRM / dashboard.
 *
 * @return array<int, array{
 *   guest_contact: string,
 *   guest_name: ?string,
 *   last_visit: string,
 *   visits_count: int,
 *   inactivity_days: int,
 *   message: string,
 *   segment: string
 * }>
 */
if (!function_exists('get_retention_opportunities')) {
    function get_retention_opportunities(int $restaurant_id): array
    {
        if (function_exists('is_demo_mode') && is_demo_mode()) {
            return _guest_retention_demo_opportunities();
        }
        $inactive = get_inactive_guests($restaurant_id, guest_retention_inactive_days(), 50);
        if (empty($inactive)) {
            return [];
        }
        $message = 'We miss you! Come back this week and enjoy a special offer.';
        foreach ($inactive as &$row) {
            $row['message'] = $message;
            $row['segment'] = 'inactive_guest';
            if (!isset($row['inactivity_days'])) {
                $row['inactivity_days'] = 0;
            }
        }
        unset($row);
        return $inactive;
    }
}

/**
 * Retention analytics: counts and repeat rate for dashboard.
 *
 * @return array{
 *   total_guests: int,
 *   repeat_guests: int,
 *   repeat_rate_pct: float,
 *   inactive_count: int,
 *   opportunities_count: int,
 *   loyal_guests: int
 * }
 */
if (!function_exists('get_retention_stats')) {
    function get_retention_stats(int $restaurant_id): array
    {
        $restaurant_id = (int) $restaurant_id;
        $default = [
            'total_guests'        => 0,
            'repeat_guests'       => 0,
            'repeat_rate_pct'     => 0.0,
            'inactive_count'      => 0,
            'opportunities_count' => 0,
            'loyal_guests'        => 0,
        ];
        if (function_exists('is_demo_mode') && is_demo_mode()) {
            return [
                'total_guests'        => 24,
                'repeat_guests'       => 12,
                'repeat_rate_pct'     => 50.0,
                'inactive_count'      => 8,
                'opportunities_count' => 8,
                'loyal_guests'        => 4,
            ];
        }
        if ($restaurant_id <= 0 || !function_exists('db')) {
            return $default;
        }
        try {
            $pdo = db();
            if (guest_retention_has_crm_source()) {
                $stmt = $pdo->prepare("
                    SELECT visits_count, last_seen_at
                    FROM crm_guests
                    WHERE restaurant_id = ?
                      AND visits_count > 0
                ");
                $stmt->execute([$restaurant_id]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } elseif (guest_retention_table_exists()) {
                $stmt = $pdo->prepare("
                    SELECT 
                        guest_contact,
                        COUNT(*) AS visits_count,
                        MAX(visited_at) AS last_visit
                    FROM guest_visits
                    WHERE restaurant_id = ?
                      AND guest_contact IS NOT NULL AND guest_contact <> ''
                    GROUP BY guest_contact
                ");
                $stmt->execute([$restaurant_id]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                return $default;
            }

            $total = 0;
            $repeat = 0;
            $loyal = 0;
            $inactive = 0;
            $cutoff14 = date('Y-m-d H:i:s', strtotime('-' . guest_retention_inactive_days() . ' days'));
            $cutoff30 = date('Y-m-d H:i:s', strtotime('-30 days'));
            foreach ($rows as $r) {
                $cnt = (int)($r['visits_count'] ?? 0);
                $last = $r['last_seen_at'] ?? $r['last_visit'] ?? null;
                if ($cnt <= 0) {
                    continue;
                }
                $total++;
                if ($cnt > 1) {
                    $repeat++;
                }
                if ($last === null || $last < $cutoff14) {
                    $inactive++;
                }
                if ($cnt >= 5 || ($cnt >= 3 && $last !== null && $last >= $cutoff30)) {
                    $loyal++;
                }
            }
            $rate = $total > 0 ? round(100.0 * $repeat / $total, 1) : 0.0;
            return [
                'total_guests'        => $total,
                'repeat_guests'       => $repeat,
                'repeat_rate_pct'     => $rate,
                'inactive_count'      => $inactive,
                'opportunities_count' => count(get_retention_opportunities($restaurant_id)),
                'loyal_guests'        => $loyal,
            ];
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('guest_retention get_retention_stats ' . $e->getMessage());
            }
            return $default;
        }
    }
}

/**
 * Guest segmentation summary: new_guest, returning_guest, inactive_guest, loyal_guest (counts only).
 *
 * @return array{new_guest: int, returning_guest: int, inactive_guest: int, loyal_guest: int}
 */
if (!function_exists('get_guest_segments_summary')) {
    function get_guest_segments_summary(int $restaurant_id): array
    {
        $restaurant_id = (int) $restaurant_id;
        $default = [
            'new_guest'       => 0,
            'returning_guest' => 0,
            'inactive_guest'  => 0,
            'loyal_guest'     => 0,
        ];
        if (function_exists('is_demo_mode') && is_demo_mode()) {
            return [
                'new_guest'       => 6,
                'returning_guest' => 10,
                'inactive_guest'  => 8,
                'loyal_guest'     => 4,
            ];
        }
        if ($restaurant_id <= 0 || !function_exists('db')) {
            return $default;
        }
        try {
            $pdo = db();
            if (guest_retention_has_crm_source()) {
                $stmt = $pdo->prepare("
                    SELECT visits_count, last_seen_at
                    FROM crm_guests
                    WHERE restaurant_id = ?
                      AND visits_count > 0
                ");
                $stmt->execute([$restaurant_id]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } elseif (guest_retention_table_exists()) {
                $stmt = $pdo->prepare("
                    SELECT 
                        guest_contact,
                        COUNT(*) AS visits_count,
                        MAX(visited_at) AS last_visit
                    FROM guest_visits
                    WHERE restaurant_id = ?
                      AND guest_contact IS NOT NULL AND guest_contact <> ''
                    GROUP BY guest_contact
                ");
                $stmt->execute([$restaurant_id]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                return $default;
            }

            $new = 0;
            $returning = 0;
            $inactive = 0;
            $loyal = 0;
            $cutoff14 = date('Y-m-d H:i:s', strtotime('-' . guest_retention_inactive_days() . ' days'));
            $cutoff30 = date('Y-m-d H:i:s', strtotime('-30 days'));
            foreach ($rows as $r) {
                $cnt = (int)($r['visits_count'] ?? 0);
                $last = $r['last_seen_at'] ?? $r['last_visit'] ?? null;
                if ($cnt <= 0) {
                    continue;
                }

                if ($cnt === 1) {
                    $new++;
                } elseif ($last === null || $last < $cutoff14) {
                    $inactive++;
                } else {
                    $returning++;
                }

                if ($cnt >= 5 || ($cnt >= 3 && $last !== null && $last >= $cutoff30)) {
                    $loyal++;
                }
            }
            return [
                'new_guest'       => $new,
                'returning_guest' => $returning,
                'inactive_guest'  => $inactive,
                'loyal_guest'     => $loyal,
            ];
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('guest_retention get_guest_segments_summary ' . $e->getMessage());
            }
            return $default;
        }
    }
}

/**
 * Demo: fake retention opportunities (no DB).
 */
if (!function_exists('_guest_retention_demo_opportunities')) {
    function _guest_retention_demo_opportunities(): array
    {
        return [
            [
                'guest_contact'   => '+7 916 100-42-18',
                'guest_name'      => null,
                'last_visit'      => date('Y-m-d', strtotime('-18 days')),
                'visits_count'    => 4,
                'inactivity_days' => 18,
                'message'         => 'Давно не видели вас в «Севере». Вернитесь на неделе — подготовим стол у окна.',
                'segment'         => 'inactive_guest',
            ],
            [
                'guest_contact'   => '+7 903 221-55-90',
                'guest_name'      => null,
                'last_visit'      => date('Y-m-d', strtotime('-21 days')),
                'visits_count'    => 7,
                'inactivity_days' => 21,
                'message'         => 'Скучаем! Забронируйте визит — расскажем о новых позициях в меню.',
                'segment'         => 'inactive_guest',
            ],
            [
                'guest_contact'   => '+7 926 008-77-31',
                'guest_name'      => null,
                'last_visit'      => date('Y-m-d', strtotime('-25 days')),
                'visits_count'    => 2,
                'inactivity_days' => 25,
                'message'         => 'Вы давно не заходили. Подарим лимонад к первому заказу при возврате.',
                'segment'         => 'inactive_guest',
            ],
        ];
    }
}
