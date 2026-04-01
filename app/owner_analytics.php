<?php
/**
 * Platform owner / admin analytics: multi-restaurant aggregation.
 * SELECT-only; reuses upsell_analytics + retention_analytics (no duplicated attribution logic).
 */

require_once __DIR__ . '/db.php';
if (file_exists(__DIR__ . '/schema_guard.php')) {
    require_once __DIR__ . '/schema_guard.php';
}
require_once __DIR__ . '/upsell_analytics.php';
require_once __DIR__ . '/retention_analytics.php';
if (file_exists(__DIR__ . '/cache.php')) {
    require_once __DIR__ . '/cache.php';
}

if (!function_exists('owner_analytics_clamp_days')) {
    function owner_analytics_clamp_days(int $days): int
    {
        return max(1, min(365, $days));
    }
}

if (!function_exists('owner_analytics_request_cache')) {
    /**
     * @return array<string,array{u:?array,r:?array}>
     */
    function &owner_analytics_request_cache(): array
    {
        static $cache = [];
        return $cache;
    }
}

if (!function_exists('owner_analytics_get_upsell_cached')) {
    /**
     * @return array<string,mixed>|null
     */
    function owner_analytics_get_upsell_cached(int $restaurantId, int $days): ?array
    {
        $restaurantId = (int)$restaurantId;
        $days = owner_analytics_clamp_days($days);
        $key = $restaurantId . ':' . $days;
        $cache = &owner_analytics_request_cache();
        if (isset($cache[$key]) && array_key_exists('u', $cache[$key])) {
            return $cache[$key]['u'];
        }
        if ($restaurantId <= 0 || (!function_exists('get_upsell_analytics_summary_cached') && !function_exists('get_upsell_analytics_summary'))) {
            return null;
        }
        try {
            $u = function_exists('get_upsell_analytics_summary_cached')
                ? get_upsell_analytics_summary_cached($restaurantId, $days)
                : get_upsell_analytics_summary($restaurantId, $days);
            if (!isset($cache[$key])) {
                $cache[$key] = ['u' => null, 'r' => null];
            }
            $cache[$key]['u'] = $u;
            return $u;
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('owner_analytics_get_upsell_cached ' . $e->getMessage());
            }
            return null;
        }
    }
}

if (!function_exists('owner_analytics_get_retention_cached')) {
    /**
     * @return array<string,mixed>|null
     */
    function owner_analytics_get_retention_cached(int $restaurantId, int $days): ?array
    {
        $restaurantId = (int)$restaurantId;
        $days = owner_analytics_clamp_days($days);
        $key = $restaurantId . ':' . $days;
        $cache = &owner_analytics_request_cache();
        if (isset($cache[$key]) && array_key_exists('r', $cache[$key])) {
            return $cache[$key]['r'];
        }
        if ($restaurantId <= 0 || (!function_exists('get_retention_roi_summary_cached') && !function_exists('get_retention_roi_summary'))) {
            if (!isset($cache[$key])) {
                $cache[$key] = ['u' => null, 'r' => null];
            }
            $cache[$key]['r'] = null;
            return null;
        }
        if (!function_exists('retention_analytics_ready') || !retention_analytics_ready()) {
            if (!isset($cache[$key])) {
                $cache[$key] = ['u' => null, 'r' => null];
            }
            $cache[$key]['r'] = null;
            return null;
        }
        try {
            $r = function_exists('get_retention_roi_summary_cached')
                ? get_retention_roi_summary_cached($restaurantId, $days)
                : get_retention_roi_summary($restaurantId, $days);
            if (!isset($cache[$key])) {
                $cache[$key] = ['u' => null, 'r' => null];
            }
            $cache[$key]['r'] = $r;
            return $r;
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('owner_analytics_get_retention_cached ' . $e->getMessage());
            }
            return null;
        }
    }
}

if (!function_exists('owner_analytics_restaurants')) {
    /**
     * @return array<int,array{id:int,name:string}>
     */
    function owner_analytics_restaurants(): array
    {
        if (!function_exists('db_table_exists') || !db_table_exists('restaurants')) {
            return [];
        }
        try {
            $pdo = db();
            $deletedSql = function_exists('schema_guard_restaurants_deleted_sql') ? schema_guard_restaurants_deleted_sql('r') : '';
            $stmt = $pdo->query("
                SELECT r.id, r.name
                FROM restaurants r
                WHERE 1=1 {$deletedSql}
                ORDER BY r.id ASC
            ");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $out = [];
            foreach ($rows as $row) {
                $id = (int)($row['id'] ?? 0);
                if ($id <= 0) {
                    continue;
                }
                $out[] = ['id' => $id, 'name' => (string)($row['name'] ?? '')];
            }
            return $out;
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('owner_analytics_restaurants ' . $e->getMessage());
            }
            return [];
        }
    }
}

if (!function_exists('owner_analytics_build_overview_rows')) {
    /**
     * @return array<int,array<string,mixed>>
     */
    function owner_analytics_build_overview_rows(int $days): array
    {
        $days = owner_analytics_clamp_days($days);
        $list = owner_analytics_restaurants();
        $rows = [];
        foreach ($list as $rec) {
            $rid = (int)$rec['id'];
            $u = owner_analytics_get_upsell_cached($rid, $days);
            $r = owner_analytics_get_retention_cached($rid, $days);

            $totalOrders = null;
            $totalRevenue = null;
            $upsellRevenue = null;
            $ordersWithUpsell = null;
            $attachRate = null;
            $avgOrderValue = null;
            if (is_array($u)) {
                $totalOrders = (int)($u['total_orders'] ?? 0);
                $totalRevenue = (float)($u['total_revenue'] ?? 0);
                $upsellRevenue = (float)($u['upsell_revenue'] ?? 0);
                $ordersWithUpsell = (int)($u['orders_with_upsell'] ?? 0);
                $attachRate = ($totalOrders > 0)
                    ? (float)($u['upsell_attach_rate'] ?? 0)
                    : null;
                $avgOrderValue = ($totalOrders > 0)
                    ? (float)($u['avg_order_value'] ?? 0)
                    : null;
            }

            $retentionRevenue = null;
            $returnRate = null;
            $acceptedCampaigns = null;
            $sampleSizeCampaigns = null;
            $confidenceLevel = null;
            if (is_array($r)) {
                $retentionRevenue = (float)($r['total_return_revenue'] ?? 0);
                $acceptedCampaigns = (int)($r['accepted_campaigns'] ?? 0);
                $returnRate = ($acceptedCampaigns > 0)
                    ? (float)($r['return_rate'] ?? 0)
                    : null;
                $sampleSizeCampaigns = (int)($r['sample_size_campaigns'] ?? 0);
                $confidenceLevel = (string)($r['confidence_level'] ?? 'low');
            }

            $rows[] = [
                'restaurant_id' => $rid,
                'name' => (string)($rec['name'] ?? ''),
                'total_orders' => $totalOrders,
                'orders_with_upsell' => $ordersWithUpsell,
                'total_revenue' => $totalRevenue,
                'upsell_revenue' => $upsellRevenue,
                'retention_revenue' => $retentionRevenue,
                'return_rate' => $returnRate,
                'baseline_return_rate' => is_array($r) ? ($r['baseline_return_rate'] ?? null) : null,
                'attach_rate' => $attachRate,
                'avg_order_value' => $avgOrderValue,
                'accepted_campaigns' => is_array($r) ? (int)($r['accepted_campaigns'] ?? 0) : null,
                'returned_guests' => is_array($r) ? (int)($r['returned_guests'] ?? 0) : null,
                'sample_size_campaigns' => is_array($r) ? $sampleSizeCampaigns : null,
                'confidence_level' => is_array($r) ? $confidenceLevel : null,
            ];
        }
        return $rows;
    }
}

if (!function_exists('owner_analytics_overview_rows_for_request')) {
    /**
     * Single overview build per (days) per HTTP request — reused by summary, top, problems, overview.
     *
     * @return array<int,array<string,mixed>>
     */
    function owner_analytics_overview_rows_for_request(int $days): array
    {
        static $cache = [];
        $d = owner_analytics_clamp_days($days);

        // Redis cache for the whole overview (platform owner dashboard).
        $ttl = 600; // 10 min
        $cacheKey = 'owner_analytics_overview:0:' . $d;
        if (function_exists('cache_get')) {
            $cached = cache_get($cacheKey);
            if (is_array($cached)) {
                $cache[$d] = $cached;
                return $cached;
            }
        }

        if (!array_key_exists($d, $cache)) {
            $cache[$d] = owner_analytics_build_overview_rows($d);
            if (function_exists('cache_set')) {
                cache_set($cacheKey, $cache[$d], $ttl);
            }
        }
        return $cache[$d];
    }
}

if (!function_exists('owner_analytics_cmp_top_rows_tiebreak')) {
    /**
     * @param array<string,mixed> $a
     * @param array<string,mixed> $b
     */
    function owner_analytics_cmp_top_rows_tiebreak(array $a, array $b): int
    {
        $ra = $a['total_revenue'] ?? null;
        $rb = $b['total_revenue'] ?? null;
        if ($ra === null && $rb === null) {
            return ((int)($a['restaurant_id'] ?? 0)) <=> ((int)($b['restaurant_id'] ?? 0));
        }
        if ($ra === null) {
            return 1;
        }
        if ($rb === null) {
            return -1;
        }
        $revCmp = $rb <=> $ra;
        if ($revCmp !== 0) {
            return $revCmp;
        }
        return ((int)($a['restaurant_id'] ?? 0)) <=> ((int)($b['restaurant_id'] ?? 0));
    }
}

if (!function_exists('owner_analytics_cmp_top_rows')) {
    /**
     * Desc by primary metric; tie-break: total_revenue desc, restaurant_id asc.
     *
     * @param array<string,mixed> $a
     * @param array<string,mixed> $b
     */
    function owner_analytics_cmp_top_rows(array $a, array $b, string $field): int
    {
        $va = $a[$field] ?? null;
        $vb = $b[$field] ?? null;
        if ($va === null && $vb === null) {
            return owner_analytics_cmp_top_rows_tiebreak($a, $b);
        }
        if ($va === null) {
            return 1;
        }
        if ($vb === null) {
            return -1;
        }
        $primary = $vb <=> $va;
        if ($primary !== 0) {
            return $primary;
        }
        return owner_analytics_cmp_top_rows_tiebreak($a, $b);
    }
}

if (!function_exists('get_owner_summary')) {
    /**
     * @return array<string,mixed>
     */
    function get_owner_summary(int $days = 30): array
    {
        $days = owner_analytics_clamp_days($days);
        $rows = owner_analytics_overview_rows_for_request($days);

        $totalRestaurants = count($rows);
        $active = 0;
        $sumOrders = 0;
        $sumRev = 0.0;
        $sumUpsell = 0.0;
        $sumRetention = 0.0;
        $sumReturnedGuests = 0;
        $sumAcceptedCampaigns = 0;
        $sumOrdersWithUpsell = 0;
        $sumOrdersAttachDenom = 0;

        foreach ($rows as $row) {
            $to = $row['total_orders'];
            if ($to === null) {
                continue;
            }
            if ((int)$to > 0) {
                $active++;
                $sumOrders += (int)$to;
                $sumRev += (float)($row['total_revenue'] ?? 0);
                $sumUpsell += (float)($row['upsell_revenue'] ?? 0);
                $sumRetention += (float)($row['retention_revenue'] ?? 0);
            }

            $ac = $row['accepted_campaigns'];
            if ($ac !== null && (int)$ac > 0) {
                $sumReturnedGuests += (int)($row['returned_guests'] ?? 0);
                $sumAcceptedCampaigns += (int)$ac;
            }

            if ($to !== null && (int)$to > 0 && isset($row['orders_with_upsell']) && $row['orders_with_upsell'] !== null) {
                $sumOrdersWithUpsell += (int)$row['orders_with_upsell'];
                $sumOrdersAttachDenom += (int)$to;
            }
        }

        $weightedReturn = ($sumAcceptedCampaigns > 0)
            ? round($sumReturnedGuests / $sumAcceptedCampaigns, 4)
            : null;
        $weightedAttach = ($sumOrdersAttachDenom > 0)
            ? round($sumOrdersWithUpsell / $sumOrdersAttachDenom, 4)
            : null;

        return [
            'total_restaurants' => $totalRestaurants,
            'active_restaurants' => $active,
            'total_orders' => $sumOrders,
            'total_revenue' => round($sumRev, 2),
            'total_upsell_revenue' => round($sumUpsell, 2),
            'total_retention_revenue' => round($sumRetention, 2),
            'avg_return_rate' => $weightedReturn,
            'avg_attach_rate' => $weightedAttach,
        ];
    }
}

if (!function_exists('get_restaurants_overview')) {
    /**
     * @return array<int,array<string,mixed>>
     */
    function get_restaurants_overview(int $days = 30): array
    {
        $days = owner_analytics_clamp_days($days);
        return owner_analytics_overview_rows_for_request($days);
    }
}

if (!function_exists('get_top_restaurants')) {
    /**
     * @return array<int,array<string,mixed>>
     */
    function get_top_restaurants(int $days = 30, int $limit = 20, string $sort = 'revenue'): array
    {
        $days = owner_analytics_clamp_days($days);
        $limit = max(1, min(100, $limit));
        $sort = strtolower(trim($sort));
        $allowed = ['revenue', 'upsell_revenue', 'retention_revenue', 'return_rate'];
        if (!in_array($sort, $allowed, true)) {
            $sort = 'revenue';
        }
        $rows = owner_analytics_overview_rows_for_request($days);
        $work = array_values($rows);
        $keyMap = [
            'revenue' => 'total_revenue',
            'upsell_revenue' => 'upsell_revenue',
            'retention_revenue' => 'retention_revenue',
            'return_rate' => 'return_rate',
        ];
        $field = $keyMap[$sort];
        usort($work, static function ($a, $b) use ($field) {
            return owner_analytics_cmp_top_rows($a, $b, $field);
        });
        return array_slice($work, 0, $limit);
    }
}

if (!function_exists('get_problem_restaurants')) {
    /**
     * @return array<int,array<string,mixed>>
     */
    function get_problem_restaurants(int $days = 30): array
    {
        $days = owner_analytics_clamp_days($days);
        $rows = owner_analytics_overview_rows_for_request($days);
        $out = [];
        foreach ($rows as $row) {
            $flags = [
                'no_upsell' => false,
                'no_crm' => false,
                'low_return_rate' => false,
                'no_retention' => false,
                'low_attach_rate' => false,
            ];
            $to = $row['total_orders'];
            $ur = $row['upsell_revenue'];
            $ac = $row['accepted_campaigns'];
            $rg = $row['returned_guests'];
            $rr = $row['return_rate'];
            $br = $row['baseline_return_rate'];
            $ar = $row['attach_rate'];

            if ($to !== null && $ur !== null && (float)$ur === 0.0 && (int)$to > 20) {
                $flags['no_upsell'] = true;
            }
            if ($to !== null && (int)$to >= 20 && $ac !== null && (int)$ac === 0) {
                $flags['no_crm'] = true;
            }
            if ($ac !== null && $rg !== null && (int)$ac >= 3 && (int)$rg === 0) {
                $flags['no_retention'] = true;
            }
            if ($ac !== null && (int)$ac >= 5 && $br !== null && $rr !== null && (float)$rr < (float)$br) {
                $flags['low_return_rate'] = true;
            }
            if ($to !== null && (int)$to >= 30 && $ar !== null && (float)$ar < 0.05) {
                $flags['low_attach_rate'] = true;
            }

            $any = false;
            foreach ($flags as $v) {
                if ($v) {
                    $any = true;
                    break;
                }
            }
            if (!$any) {
                continue;
            }
            $out[] = [
                'restaurant_id' => (int)$row['restaurant_id'],
                'name' => (string)($row['name'] ?? ''),
                'flags' => $flags,
            ];
        }
        return $out;
    }
}

if (!function_exists('get_restaurant_full_metrics')) {
    /**
     * @return array<string,mixed>
     */
    function get_restaurant_full_metrics(int $restaurantId, int $days = 30): array
    {
        $restaurantId = (int)$restaurantId;
        $days = owner_analytics_clamp_days($days);
        if ($restaurantId <= 0) {
            return ['ok' => false];
        }
        $list = owner_analytics_restaurants();
        $name = null;
        foreach ($list as $rec) {
            if ((int)$rec['id'] === $restaurantId) {
                $name = (string)($rec['name'] ?? '');
                break;
            }
        }
        if ($name === null) {
            return ['ok' => false];
        }

        $u = owner_analytics_get_upsell_cached($restaurantId, $days);
        $r = owner_analytics_get_retention_cached($restaurantId, $days);

        return [
            'ok' => true,
            'restaurant_id' => $restaurantId,
            'name' => $name,
            'days' => $days,
            'summary' => [
                'total_orders' => is_array($u) ? (int)($u['total_orders'] ?? 0) : null,
                'total_revenue' => is_array($u) ? (float)($u['total_revenue'] ?? 0) : null,
            ],
            'upsell' => [
                'revenue' => is_array($u) ? (float)($u['upsell_revenue'] ?? 0) : null,
                'attach_rate' => is_array($u) && (int)($u['total_orders'] ?? 0) > 0
                    ? (float)($u['upsell_attach_rate'] ?? 0)
                    : null,
            ],
            'retention' => [
                'return_rate' => is_array($r) && (int)($r['accepted_campaigns'] ?? 0) > 0
                    ? (float)($r['return_rate'] ?? 0)
                    : null,
                'uplift_return_rate' => is_array($r) ? ($r['uplift_return_rate'] ?? null) : null,
                'uplift_percent' => is_array($r) ? ($r['uplift_percent'] ?? null) : null,
                'total_return_revenue' => is_array($r) ? (float)($r['total_return_revenue'] ?? 0) : null,
                'sample_size_campaigns' => is_array($r) ? (int)($r['sample_size_campaigns'] ?? 0) : null,
                'confidence_level' => is_array($r) ? ($r['confidence_level'] ?? null) : null,
            ],
            'crm' => [
                'total_campaigns' => is_array($r) ? (int)($r['total_campaigns'] ?? 0) : null,
                'accepted_campaigns' => is_array($r) ? (int)($r['accepted_campaigns'] ?? 0) : null,
                'failed_campaigns' => is_array($r) ? (int)($r['failed_campaigns'] ?? 0) : null,
            ],
        ];
    }
}
