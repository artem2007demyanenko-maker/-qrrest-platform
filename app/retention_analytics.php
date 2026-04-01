<?php
/**
 * Retention ROI / return attribution analytics for feedback->upsell->CRM drafts.
 * Read-only attribution over existing orders, with one explicit write helper used on ACCEPT.
 */

require_once __DIR__ . '/db.php';
if (file_exists(__DIR__ . '/schema_guard.php')) {
    require_once __DIR__ . '/schema_guard.php';
}
if (file_exists(__DIR__ . '/cache.php')) {
    require_once __DIR__ . '/cache.php';
}

if (!function_exists('retention_analytics_ready')) {
    function retention_analytics_ready(): bool
    {
        return function_exists('db_table_exists')
            && db_table_exists('growth_engine_suggestions')
            && db_table_exists('orders');
    }
}

if (!function_exists('retention_analytics_order_total_sql')) {
    function retention_analytics_order_total_sql(): string
    {
        $parts = [];
        if (function_exists('db_column_exists') && db_column_exists('orders', 'total_amount')) {
            $parts[] = 'o.total_amount';
        }
        if (function_exists('db_column_exists') && db_column_exists('orders', 'total_price')) {
            $parts[] = 'o.total_price';
        }
        if ($parts === []) {
            return '0';
        }
        return 'COALESCE(' . implode(', ', $parts) . ', 0)';
    }
}

if (!function_exists('retention_normalize_segment')) {
    /**
     * @return ?string low|neutral|high
     */
    function retention_normalize_segment($segment): ?string
    {
        $s = strtolower(trim((string)$segment));
        if ($s === 'low' || $s === 'neutral' || $s === 'high') {
            return $s;
        }
        return null;
    }
}

if (!function_exists('retention_segment_from_reason')) {
    /**
     * Legacy fallback mapping for old payloads without retention_segment.
     *
     * @return ?string low|neutral|high
     */
    function retention_segment_from_reason($reason): ?string
    {
        $r = trim((string)$reason);
        if ($r === 'low_rating_recovery') return 'low';
        if ($r === 'experience_improve') return 'neutral';
        if ($r === 'loyal_guest_return') return 'high';
        return null;
    }
}

if (!function_exists('retention_segment_bucket')) {
    /**
     * @return ?string LOW|NEUTRAL|HIGH
     */
    function retention_segment_bucket(?string $segment): ?string
    {
        if ($segment === 'low') return 'LOW';
        if ($segment === 'neutral') return 'NEUTRAL';
        if ($segment === 'high') return 'HIGH';
        return null;
    }
}

if (!function_exists('retention_confidence_level_by_sample_size')) {
    /**
     * Confidence is derived from N campaigns (sample size), not from metric formulas.
     *
     * @return 'low'|'medium'|'high'
     */
    function retention_confidence_level_by_sample_size(int $sampleSize): string
    {
        if ($sampleSize < 3) {
            return 'low';
        }
        if ($sampleSize < 10) {
            return 'medium';
        }
        return 'high';
    }
}

if (!function_exists('retention_campaigns_for_period')) {
    /**
     * @return array<int,array<string,mixed>>
     */
    function retention_campaigns_for_period(int $restaurantId, int $days = 30): array
    {
        $restaurantId = (int)$restaurantId;
        $days = max(1, min(365, (int)$days));
        if ($restaurantId <= 0 || !retention_analytics_ready()) {
            return [];
        }

        try {
            $pdo = db();
            $hasStatus = function_exists('db_column_exists') && db_column_exists('growth_engine_suggestions', 'status');
            $since = date('Y-m-d H:i:s', strtotime('-' . $days . ' days'));
            $sql = "
                SELECT id, status, payload_json, created_at
                FROM growth_engine_suggestions
                WHERE restaurant_id = :rid
                  AND type = 'crm_retention_with_offer'
                  AND created_at >= :since
            ";
            if ($hasStatus) {
                $sql .= " AND status IN ('pending', 'accepted')";
            }
            $sql .= " ORDER BY created_at DESC LIMIT 500";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['rid' => $restaurantId, 'since' => $since]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows) {
                return [];
            }

            $out = [];
            $seenCampaigns = [];
            foreach ($rows as $r) {
                $payload = json_decode((string)($r['payload_json'] ?? '{}'), true);
                if (!is_array($payload)) $payload = [];

                $createdAt = trim((string)($payload['created_at'] ?? ''));
                if ($createdAt === '') {
                    $createdAt = (string)($r['created_at'] ?? '');
                }
                $returnWindowDays = (int)($payload['return_window_days'] ?? 0);
                if ($returnWindowDays <= 0) $returnWindowDays = 7;
                $expiresAt = trim((string)($payload['expires_at'] ?? ''));
                if ($expiresAt === '' && $createdAt !== '') {
                    $expiresAt = date('Y-m-d H:i:s', strtotime($createdAt . ' +' . max(1, $returnWindowDays) . ' days'));
                }

                $recommendedBonusPoints = isset($payload['recommended_bonus_points']) ? (int)$payload['recommended_bonus_points'] : 0;
                if ($recommendedBonusPoints < 0) {
                    $recommendedBonusPoints = 0;
                }
                $campaignId = trim((string)($payload['campaign_id'] ?? ''));
                if ($campaignId === '') {
                    $campaignId = 'sid:' . (int)($r['id'] ?? 0);
                }
                if (isset($seenCampaigns[$campaignId])) {
                    continue;
                }
                $seenCampaigns[$campaignId] = true;
                $out[] = [
                    'suggestion_id' => (int)($r['id'] ?? 0),
                    'status' => (string)($r['status'] ?? 'pending'),
                    'campaign_id' => $campaignId,
                    'guest_id' => (int)($payload['guest_id'] ?? 0),
                    'order_id' => (int)($payload['order_id'] ?? 0),
                    'reason' => (string)($payload['reason'] ?? ''),
                    'retention_segment' => retention_normalize_segment($payload['retention_segment'] ?? null),
                    'recommended_bonus_points' => $recommendedBonusPoints,
                    'created_at' => $createdAt,
                    'expires_at' => $expiresAt,
                    'return_window_days' => $returnWindowDays,
                    'payload_json' => (string)($r['payload_json'] ?? '{}'),
                ];
            }
            return $out;
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('retention_campaigns_for_period ' . $e->getMessage());
            }
            return [];
        }
    }
}

if (!function_exists('get_retention_attribution')) {
    /**
     * One campaign -> first valid return order only.
     *
     * @return array<int,array{
     *   suggestion_id:int,campaign_id:string,guest_id:int,order_id:int,created_at:string,expires_at:string,status:string,
     *   returned:bool,return_order_id:int,return_revenue:float,days_to_return:?int
     * }>
     */
    function get_retention_attribution(int $restaurantId, int $days = 30): array
    {
        $restaurantId = (int)$restaurantId;
        $days = max(1, min(365, (int)$days));
        if ($restaurantId <= 0 || !retention_analytics_ready()) {
            return [];
        }

        // Prevent duplicate heavy SQL within the same HTTP request.
        static $cache = [];
        $cacheKey = $restaurantId . ':' . $days . ':v2';
        if (isset($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }

        $campaigns = retention_campaigns_for_period($restaurantId, $days);
        if ($campaigns === []) {
            return [];
        }

        try {
            $pdo = db();
            $totalSql = retention_analytics_order_total_sql();
            $out = [];

            $validGuestIds = [];
            $minCreatedTs = null;
            $maxExpiresTs = null;
            foreach ($campaigns as $c) {
                $gid = (int)($c['guest_id'] ?? 0);
                $ct = strtotime((string)($c['created_at'] ?? ''));
                $et = strtotime((string)($c['expires_at'] ?? ''));
                if ($gid > 0 && $ct !== false && $et !== false) {
                    $validGuestIds[$gid] = true;
                    $minCreatedTs = ($minCreatedTs === null) ? $ct : min($minCreatedTs, $ct);
                    $maxExpiresTs = ($maxExpiresTs === null) ? $et : max($maxExpiresTs, $et);
                }
            }

            $ordersByGuest = [];
            if ($validGuestIds !== [] && $minCreatedTs !== null && $maxExpiresTs !== null) {
                $guestIds = array_keys($validGuestIds);
                $ph = implode(',', array_fill(0, count($guestIds), '?'));
                $sql = "
                    SELECT
                        o.id,
                        o.guest_id,
                        {$totalSql} AS return_total,
                        o.created_at
                    FROM orders o
                    WHERE o.restaurant_id = ?
                      AND o.guest_id IN ($ph)
                      AND o.created_at > ?
                      AND o.created_at <= ?
                      AND o.payment_status = 'paid'
                      AND o.order_status <> 'canceled'
                    ORDER BY o.guest_id ASC, o.created_at ASC
                ";
                $params = array_merge(
                    [$restaurantId],
                    $guestIds,
                    [date('Y-m-d H:i:s', $minCreatedTs), date('Y-m-d H:i:s', $maxExpiresTs)]
                );
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $o) {
                    $gid = (int)($o['guest_id'] ?? 0);
                    if ($gid <= 0) continue;
                    if (!isset($ordersByGuest[$gid])) $ordersByGuest[$gid] = [];
                    $ordersByGuest[$gid][] = $o;
                }
            }

            // no cross-attribution: an order can be attributed only once.
            $usedOrderIds = [];
            usort($campaigns, static function ($a, $b) {
                return strtotime((string)($a['created_at'] ?? '')) <=> strtotime((string)($b['created_at'] ?? ''));
            });

            foreach ($campaigns as $c) {
                $guestId = (int)($c['guest_id'] ?? 0);
                $createdAt = (string)($c['created_at'] ?? '');
                $expiresAt = (string)($c['expires_at'] ?? '');
                $reason = (string)($c['reason'] ?? '');
                $segment = retention_normalize_segment($c['retention_segment'] ?? null);
                if ($segment === null) {
                    $segment = retention_segment_from_reason($reason);
                }
                $bonusPts = (int)($c['recommended_bonus_points'] ?? 0);
                if ($bonusPts < 0) $bonusPts = 0;

                $row = [
                    'suggestion_id' => (int)($c['suggestion_id'] ?? 0),
                    'campaign_id' => (string)($c['campaign_id'] ?? ''),
                    'guest_id' => $guestId,
                    'order_id' => (int)($c['order_id'] ?? 0),
                    'reason' => $reason,
                    'retention_segment' => $segment,
                    'recommended_bonus_points' => $bonusPts,
                    'created_at' => $createdAt,
                    'expires_at' => $expiresAt,
                    'status' => (string)($c['status'] ?? 'pending'),
                    'returned' => false,
                    'return_order_id' => 0,
                    'return_revenue' => 0.0,
                    'days_to_return' => null,
                    'attribution_confidence' => 'low',
                ];

                $createdTs = strtotime($createdAt);
                $expiresTs = strtotime($expiresAt);
                if ($guestId <= 0 || $createdTs === false || $expiresTs === false) {
                    $out[] = $row;
                    continue;
                }

                $guestOrders = $ordersByGuest[$guestId] ?? [];
                foreach ($guestOrders as $ret) {
                    $oid = (int)($ret['id'] ?? 0);
                    if ($oid <= 0 || isset($usedOrderIds[$oid])) continue;
                    $retTs = strtotime((string)($ret['created_at'] ?? ''));
                    if ($retTs === false) continue;
                    if ($retTs <= $createdTs || $retTs > $expiresTs) continue;

                    // Honest attribution: ignore orders that happen too soon (same visit).
                    if (($retTs - $createdTs) < 3600) continue;

                    $daysToReturn = (int)floor(($retTs - $createdTs) / 86400);
                    if ($daysToReturn < 0) continue;

                    $row['returned'] = true;
                    $row['attribution_confidence'] = ((int)($row['order_id'] ?? 0) > 0 && (int)($row['return_window_days'] ?? 0) > 0) ? 'high' : 'low';
                    $row['return_order_id'] = $oid;
                    $row['return_revenue'] = round((float)($ret['return_total'] ?? 0), 2);
                    $row['days_to_return'] = $daysToReturn;
                    $usedOrderIds[$oid] = true;
                    break;
                }
                $out[] = $row;
            }

            $cache[$cacheKey] = $out;
            return $out;
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('get_retention_attribution ' . $e->getMessage());
            }
            $empty = [];
            $cache[$cacheKey] = $empty;
            return $empty;
        }
    }
}

if (!function_exists('get_retention_roi_summary')) {
    /**
     * @return array<string,mixed>
     */
    function get_retention_roi_summary(int $restaurantId, int $days = 30): array
    {
        $base = [
            'total_campaigns' => 0,
            // Presentation-only: sample size and confidence derived from N campaigns.
            'sample_size_campaigns' => 0,
            'confidence_level' => 'low',
            'accepted_campaigns' => 0,
            'returned_guests' => 0,
            'return_rate' => 0.0,
            'total_return_revenue' => 0.0,
            'avg_return_revenue' => 0.0,
            'avg_days_to_return' => null,
            'revenue_per_campaign' => 0.0,
            'estimated_bonus_cost' => 0.0,
            'estimated_net_return_revenue' => 0.0,
            'estimated_net_revenue_per_campaign' => 0.0,
            'failed_campaigns' => 0,
            'pending_campaigns' => 0,
            'expired_without_return' => 0,
            'baseline_return_rate' => null,
            'baseline_window_days' => 7,
            'uplift_return_rate' => null,
            'uplift_percent' => null,
        ];
        $rows = get_retention_attribution($restaurantId, $days);
        $baselineRow = function_exists('get_baseline_return_rate')
            ? get_baseline_return_rate($restaurantId, $days)
            : ['baseline_return_rate' => null, 'baseline_window_days' => 7];
        $baselineRate = (isset($baselineRow['baseline_return_rate']) && $baselineRow['baseline_return_rate'] !== null)
            ? (float)$baselineRow['baseline_return_rate']
            : null;
        $baselineWindow = (int)($baselineRow['baseline_window_days'] ?? 7);
        if ($baselineWindow < 3 || $baselineWindow > 14) {
            $baselineWindow = 7;
        }
        $base['baseline_return_rate'] = $baselineRate;
        $base['baseline_window_days'] = $baselineWindow;
        if ($rows === []) {
            if ($baselineRate !== null) {
                $uplift = 0.0 - (float)$baselineRate;
                $base['uplift_return_rate'] = round($uplift, 4);
                $base['uplift_percent'] = ((float)$baselineRate > 0.0)
                    ? round($uplift / (float)$baselineRate, 4)
                    : null;
            }
            return $base;
        }
        $base['total_campaigns'] = count($rows);
        $accepted = array_values(array_filter($rows, static fn($r) => (string)($r['status'] ?? 'pending') === 'accepted'));
        $base['accepted_campaigns'] = count($accepted);
        // Presentation-only: confidence is based on N campaigns used in the return_rate denominator.
        $base['sample_size_campaigns'] = (int)$base['accepted_campaigns'];
        $base['confidence_level'] = retention_confidence_level_by_sample_size((int)$base['sample_size_campaigns']);
        if ($accepted === []) {
            if ($baselineRate !== null) {
                $uplift = 0.0 - (float)$baselineRate;
                $base['uplift_return_rate'] = round($uplift, 4);
                $base['uplift_percent'] = ((float)$baselineRate > 0.0)
                    ? round($uplift / (float)$baselineRate, 4)
                    : null;
            }
            return $base;
        }

        $returned = array_values(array_filter($accepted, static fn($r) => !empty($r['returned'])));
        $base['returned_guests'] = count($returned);
        $base['return_rate'] = $base['accepted_campaigns'] > 0
            ? round($base['returned_guests'] / $base['accepted_campaigns'], 4)
            : 0.0;

        $sumRevenue = 0.0;
        $sumDays = 0;
        $daysCount = 0;
        foreach ($returned as $r) {
            $sumRevenue += (float)($r['return_revenue'] ?? 0);
            if (isset($r['days_to_return']) && $r['days_to_return'] !== null) {
                $sumDays += (int)$r['days_to_return'];
                $daysCount++;
            }
        }
        $base['total_return_revenue'] = round($sumRevenue, 2);
        $base['avg_return_revenue'] = $base['returned_guests'] > 0 ? round($sumRevenue / $base['returned_guests'], 2) : 0.0;
        $base['avg_days_to_return'] = $daysCount > 0 ? round($sumDays / $daysCount, 2) : null;
        $sumBonus = 0.0;
        foreach ($accepted as $a) {
            $sumBonus += max(0, (float)($a['recommended_bonus_points'] ?? 0));
        }
        $base['estimated_bonus_cost'] = round($sumBonus, 2);
        $base['estimated_net_return_revenue'] = round($sumRevenue - $sumBonus, 2);
        $base['revenue_per_campaign'] = $base['accepted_campaigns'] > 0 ? round($sumRevenue / $base['accepted_campaigns'], 2) : 0.0;
        $base['estimated_net_revenue_per_campaign'] = $base['accepted_campaigns'] > 0 ? round($base['estimated_net_return_revenue'] / $base['accepted_campaigns'], 2) : 0.0;
        $base['failed_campaigns'] = max(0, (int)$base['accepted_campaigns'] - (int)$base['returned_guests']);
        $nowTs = time();
        $pending = 0;
        $expiredNoReturn = 0;
        foreach ($accepted as $a) {
            if (!empty($a['returned'])) {
                continue;
            }
            $expiresTs = strtotime((string)($a['expires_at'] ?? ''));
            if ($expiresTs !== false && $expiresTs >= $nowTs) {
                $pending++;
            } else {
                $expiredNoReturn++;
            }
        }
        $base['pending_campaigns'] = $pending;
        $base['expired_without_return'] = $expiredNoReturn;

        if ($baselineRate !== null) {
            $uplift = (float)$base['return_rate'] - (float)$baselineRate;
            $base['uplift_return_rate'] = round($uplift, 4);
            $base['uplift_percent'] = ((float)$baselineRate > 0.0)
                ? round($uplift / (float)$baselineRate, 4)
                : null;
        }

        return $base;
    }
}

if (!function_exists('get_retention_roi_summary_cached')) {
    /**
     * Redis cache wrapper for retention summary.
     * key = metric + restaurant_id + days
     */
    function get_retention_roi_summary_cached(int $restaurantId, int $days = 30): array
    {
        $restaurantId = (int)$restaurantId;
        $days = max(1, min(365, (int)$days));
        $ttl = 600; // 10 min

        $cacheKey = 'retention_summary_v2:' . $restaurantId . ':' . $days;
        if (function_exists('cache_get')) {
            $cached = cache_get($cacheKey);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $data = get_retention_roi_summary($restaurantId, $days);
        if (function_exists('cache_set')) {
            cache_set($cacheKey, $data, $ttl);
        }
        return $data;
    }
}

if (!function_exists('get_retention_segments_cached')) {
    /**
     * Redis cache wrapper for retention segments.
     * key = metric + restaurant_id + days
     */
    function get_retention_segments_cached(int $restaurantId, int $days = 30): array
    {
        $restaurantId = (int)$restaurantId;
        $days = max(1, min(365, (int)$days));
        $ttl = 600; // 10 min

        $cacheKey = 'retention_segments_v2:' . $restaurantId . ':' . $days;
        if (function_exists('cache_get')) {
            $cached = cache_get($cacheKey);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $data = get_retention_segments($restaurantId, $days);
        if (function_exists('cache_set')) {
            cache_set($cacheKey, $data, $ttl);
        }
        return $data;
    }
}

if (!function_exists('get_retention_segments')) {
    /**
     * Segment metrics based on payload retention_segment with legacy reason fallback.
     *
     * @return array<string,array<string,mixed>>
     */
    function get_retention_segments(int $restaurantId, int $days = 30): array
    {
        $rows = get_retention_attribution($restaurantId, $days);
        $seg = [
            'LOW' => [
                'total_campaigns' => 0,
                // Presentation-only:
                'sample_size_campaigns' => 0,
                'confidence_level' => 'low',
                'accepted_campaigns' => 0,
                'returned_guests' => 0,
                'return_rate' => 0.0,
                'total_return_revenue' => 0.0,
                'avg_return_revenue' => 0.0,
                'avg_days_to_return' => null,
            ],
            'NEUTRAL' => [
                'total_campaigns' => 0,
                // Presentation-only:
                'sample_size_campaigns' => 0,
                'confidence_level' => 'low',
                'accepted_campaigns' => 0,
                'returned_guests' => 0,
                'return_rate' => 0.0,
                'total_return_revenue' => 0.0,
                'avg_return_revenue' => 0.0,
                'avg_days_to_return' => null,
            ],
            'HIGH' => [
                'total_campaigns' => 0,
                // Presentation-only:
                'sample_size_campaigns' => 0,
                'confidence_level' => 'low',
                'accepted_campaigns' => 0,
                'returned_guests' => 0,
                'return_rate' => 0.0,
                'total_return_revenue' => 0.0,
                'avg_return_revenue' => 0.0,
                'avg_days_to_return' => null,
            ],
        ];
        if ($rows === []) return $seg;

        $sumDays = ['LOW' => 0.0, 'NEUTRAL' => 0.0, 'HIGH' => 0.0];
        $cntDays = ['LOW' => 0, 'NEUTRAL' => 0, 'HIGH' => 0];

        foreach ($rows as $r) {
            $segment = retention_normalize_segment($r['retention_segment'] ?? null);
            if ($segment === null) {
                $segment = retention_segment_from_reason($r['reason'] ?? '');
            }
            $bucket = retention_segment_bucket($segment);
            if ($bucket === null) continue;

            $seg[$bucket]['total_campaigns']++;
            if ((string)($r['status'] ?? '') !== 'accepted') {
                continue;
            }
            $seg[$bucket]['accepted_campaigns']++;
            if (!empty($r['returned'])) {
                $seg[$bucket]['returned_guests']++;
                $seg[$bucket]['total_return_revenue'] += (float)($r['return_revenue'] ?? 0);
                if (isset($r['days_to_return']) && $r['days_to_return'] !== null) {
                    $sumDays[$bucket] += (float)$r['days_to_return'];
                    $cntDays[$bucket]++;
                }
            }
        }

        foreach (['LOW', 'NEUTRAL', 'HIGH'] as $k) {
            $accepted = (int)$seg[$k]['accepted_campaigns'];
            $returned = (int)$seg[$k]['returned_guests'];
            $sumRev = (float)$seg[$k]['total_return_revenue'];
            $seg[$k]['return_rate'] = $accepted > 0 ? round($returned / $accepted, 4) : 0.0;
            $seg[$k]['total_return_revenue'] = round($sumRev, 2);
            $seg[$k]['avg_return_revenue'] = $returned > 0 ? round($sumRev / $returned, 2) : 0.0;
            $seg[$k]['avg_days_to_return'] = $cntDays[$k] > 0 ? round($sumDays[$k] / $cntDays[$k], 2) : null;

            // Presentation-only: confidence from N campaigns in this segment.
            $sampleSize = (int)($seg[$k]['accepted_campaigns'] ?? 0);
            $seg[$k]['sample_size_campaigns'] = $sampleSize;
            $seg[$k]['confidence_level'] = retention_confidence_level_by_sample_size($sampleSize);
        }

        return $seg;
    }
}

if (!function_exists('get_baseline_return_rate')) {
    /**
     * Baseline control: guests with orders in period, excluding guests with crm_retention_with_offer campaigns.
     * Return rate is measured by second order within baseline_window_days from first order in period.
     */
    function get_baseline_return_rate(int $restaurantId, int $days = 30): array
    {
        $restaurantId = (int)$restaurantId;
        $days = max(1, min(365, (int)$days));
        if ($restaurantId <= 0 || !retention_analytics_ready()) {
            return ['baseline_return_rate' => null, 'baseline_window_days' => 7];
        }

        try {
            $pdo = db();
            $since = date('Y-m-d H:i:s', strtotime('-' . $days . ' days'));
            $baselineWindowDays = 7;

            $hasStatus = function_exists('db_column_exists') && db_column_exists('growth_engine_suggestions', 'status');
            $winSql = "
                SELECT payload_json
                FROM growth_engine_suggestions
                WHERE restaurant_id = :rid
                  AND type = 'crm_retention_with_offer'
                  AND created_at >= :since
            ";
            if ($hasStatus) {
                $winSql .= " AND status = 'accepted'";
            }
            $winSql .= " LIMIT 2000";
            $winStmt = $pdo->prepare($winSql);
            $winStmt->execute(['rid' => $restaurantId, 'since' => $since]);
            $campaignWindows = [];
            foreach ($winStmt->fetchAll(PDO::FETCH_ASSOC) as $cw) {
                $pl = json_decode((string)($cw['payload_json'] ?? '{}'), true);
                if (!is_array($pl)) continue;
                $wd = (int)($pl['return_window_days'] ?? 0);
                if ($wd > 0) {
                    $campaignWindows[] = max(3, min(14, $wd));
                }
            }
            if (count($campaignWindows) >= 5) {
                sort($campaignWindows, SORT_NUMERIC);
                $mid = (int)floor(count($campaignWindows) / 2);
                if (count($campaignWindows) % 2 === 1) {
                    $baselineWindowDays = (int)$campaignWindows[$mid];
                } else {
                    $baselineWindowDays = (int)round((((int)$campaignWindows[$mid - 1]) + ((int)$campaignWindows[$mid])) / 2);
                }
            }

            // campaign guests in period (exclude for control).
            $campStmt = $pdo->prepare("
                SELECT payload_json
                FROM growth_engine_suggestions
                WHERE restaurant_id = :rid
                  AND type = 'crm_retention_with_offer'
                  AND created_at >= :since
                LIMIT 2000
            ");
            $campStmt->execute(['rid' => $restaurantId, 'since' => $since]);
            $excludedGuests = [];
            foreach ($campStmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
                $pl = json_decode((string)($c['payload_json'] ?? '{}'), true);
                if (!is_array($pl)) continue;
                $gid = (int)($pl['guest_id'] ?? 0);
                if ($gid > 0) $excludedGuests[$gid] = true;
            }

            $stmt = $pdo->prepare("
                SELECT id, guest_id, created_at
                FROM orders
                WHERE restaurant_id = :rid
                  AND guest_id IS NOT NULL
                  AND guest_id > 0
                  AND created_at >= :since
                ORDER BY guest_id ASC, created_at ASC
                LIMIT 10000
            ");
            $stmt->execute(['rid' => $restaurantId, 'since' => $since]);
            $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!$orders) return ['baseline_return_rate' => null, 'baseline_window_days' => $baselineWindowDays];

            $byGuest = [];
            foreach ($orders as $o) {
                $gid = (int)($o['guest_id'] ?? 0);
                if ($gid <= 0 || isset($excludedGuests[$gid])) continue;
                $ts = strtotime((string)($o['created_at'] ?? ''));
                if ($ts === false) continue;
                if (!isset($byGuest[$gid])) $byGuest[$gid] = [];
                $byGuest[$gid][] = $ts;
            }

            // conservative: remove uncertain guests with malformed series.
            if (count($byGuest) < 20) return ['baseline_return_rate' => null, 'baseline_window_days' => $baselineWindowDays];

            $totalGuests = 0;
            $returnedGuests = 0;
            foreach ($byGuest as $series) {
                if ($series === []) continue;
                $first = $series[0];
                $totalGuests++;
                $returned = false;
                for ($i = 1; $i < count($series); $i++) {
                    $delta = $series[$i] - $first;
                    if ($delta > 0 && $delta <= $baselineWindowDays * 86400) {
                        $returned = true;
                        break;
                    }
                    if ($delta > $baselineWindowDays * 86400) {
                        break;
                    }
                }
                if ($returned) $returnedGuests++;
            }
            if ($totalGuests < 20) return ['baseline_return_rate' => null, 'baseline_window_days' => $baselineWindowDays];
            return [
                'baseline_return_rate' => ($totalGuests > 0 ? round($returnedGuests / $totalGuests, 4) : null),
                'baseline_window_days' => $baselineWindowDays,
            ];
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('get_baseline_return_rate ' . $e->getMessage());
            }
            return ['baseline_return_rate' => null, 'baseline_window_days' => 7];
        }
    }
}

if (!function_exists('retention_ensure_campaign_payload_on_accept')) {
    /**
     * Explicit tracking write on ACCEPT only.
     * Ensures campaign_id/created_at/expires_at/guest_id/order_id exist in payload_json.
     */
    function retention_ensure_campaign_payload_on_accept(int $restaurantId, int $suggestionId): bool
    {
        $restaurantId = (int)$restaurantId;
        $suggestionId = (int)$suggestionId;
        if ($restaurantId <= 0 || $suggestionId <= 0 || !retention_analytics_ready()) {
            return false;
        }
        try {
            $pdo = db();
            $stmt = $pdo->prepare("
                SELECT id, type, status, payload_json, created_at
                FROM growth_engine_suggestions
                WHERE id = :id
                  AND restaurant_id = :rid
                  AND type = 'crm_retention_with_offer'
                LIMIT 1
            ");
            $stmt->execute(['id' => $suggestionId, 'rid' => $restaurantId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row || (string)($row['status'] ?? 'pending') !== 'pending') {
                return false;
            }

            $payload = json_decode((string)($row['payload_json'] ?? '{}'), true);
            if (!is_array($payload)) $payload = [];
            $changed = false;

            if (empty($payload['campaign_id'])) {
                $payload['campaign_id'] = uniqid('ret_', true);
                $changed = true;
            }
            if (empty($payload['created_at'])) {
                $payload['created_at'] = (string)($row['created_at'] ?? date('Y-m-d H:i:s'));
                $changed = true;
            }
            $windowDays = (int)($payload['return_window_days'] ?? 0);
            if ($windowDays <= 0) {
                $windowDays = 7;
                $payload['return_window_days'] = $windowDays;
                $changed = true;
            }
            if (empty($payload['expires_at']) && !empty($payload['created_at'])) {
                $payload['expires_at'] = date('Y-m-d H:i:s', strtotime((string)$payload['created_at'] . ' +' . $windowDays . ' days'));
                $changed = true;
            }
            if (!array_key_exists('guest_id', $payload)) {
                $payload['guest_id'] = 0;
                $changed = true;
            }
            if (!array_key_exists('order_id', $payload)) {
                $payload['order_id'] = 0;
                $changed = true;
            }
            if (!array_key_exists('retention_segment', $payload) || retention_normalize_segment($payload['retention_segment']) === null) {
                $legacy = retention_segment_from_reason($payload['reason'] ?? '');
                if ($legacy !== null) {
                    $payload['retention_segment'] = $legacy;
                    $changed = true;
                }
            }

            if (!$changed) {
                return true;
            }
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
            if (!is_string($json)) {
                return false;
            }
            $upd = $pdo->prepare("UPDATE growth_engine_suggestions SET payload_json = :payload WHERE id = :id AND restaurant_id = :rid");
            $upd->execute(['payload' => $json, 'id' => $suggestionId, 'rid' => $restaurantId]);
            return true;
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('retention_ensure_campaign_payload_on_accept ' . $e->getMessage());
            }
            return false;
        }
    }
}

