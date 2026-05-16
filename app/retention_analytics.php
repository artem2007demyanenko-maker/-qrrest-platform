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

if (!function_exists('retention_payload_crm_guest_id')) {
    function retention_payload_crm_guest_id(array $payload): int
    {
        $crmGuestId = (int)($payload['crm_guest_id'] ?? 0);
        if ($crmGuestId > 0) {
            return $crmGuestId;
        }
        return (int)($payload['guest_id'] ?? 0);
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
                    'guest_id' => retention_payload_crm_guest_id($payload),
                    'crm_guest_id' => retention_payload_crm_guest_id($payload),
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
                        " . ((function_exists('db_column_exists') && db_column_exists('orders', 'crm_guest_id')) ? "o.crm_guest_id" : "o.guest_id") . " AS crm_guest_id,
                        {$totalSql} AS return_total,
                        o.created_at
                    FROM orders o
                    WHERE o.restaurant_id = ?
                      AND " . ((function_exists('db_column_exists') && db_column_exists('orders', 'crm_guest_id')) ? "o.crm_guest_id" : "o.guest_id") . " IN ($ph)
                      AND o.created_at > ?
                      AND o.created_at <= ?
                      AND o.payment_status = 'paid'
                      AND o.order_status <> 'canceled'
                    ORDER BY " . ((function_exists('db_column_exists') && db_column_exists('orders', 'crm_guest_id')) ? "o.crm_guest_id" : "o.guest_id") . " ASC, o.created_at ASC
                ";
                $params = array_merge(
                    [$restaurantId],
                    $guestIds,
                    [date('Y-m-d H:i:s', $minCreatedTs), date('Y-m-d H:i:s', $maxExpiresTs)]
                );
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $o) {
                    $gid = (int)($o['crm_guest_id'] ?? 0);
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
                    'crm_guest_id' => $guestId,
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

if (!function_exists('loyalty_retention_scenario_catalog_for_analytics')) {
    function loyalty_retention_scenario_catalog_for_analytics(): array
    {
        if (function_exists('crm_loyalty_retention_segment_catalog')) {
            $catalog = crm_loyalty_retention_segment_catalog();
            if (is_array($catalog) && $catalog !== []) {
                return $catalog;
            }
        }

        return [
            'loyalty_balance_inactive_14d' => ['label' => 'Есть бонусы, не был 14+ дней'],
            'single_paid_order_no_return_14d' => ['label' => 'Один оплаченный визит, не вернулся'],
            'loyalty_balance_no_spend_30d' => ['label' => 'Давно не тратил бонусы'],
            'high_value_loyal_guest' => ['label' => 'Ценный гость'],
            'loyalty_points_reminder_7d' => ['label' => 'Напомнить про бонусы'],
        ];
    }
}

if (!function_exists('loyalty_retention_outbox_rows_for_period')) {
    /**
     * @return list<array<string,mixed>>
     */
    function loyalty_retention_outbox_rows_for_period(int $restaurantId, int $days = 30): array
    {
        $restaurantId = (int)$restaurantId;
        $days = max(1, min(365, (int)$days));
        if ($restaurantId <= 0 || !function_exists('db') || !function_exists('db_table_exists') || !db_table_exists('crm_outbox')) {
            return [];
        }

        $catalog = loyalty_retention_scenario_catalog_for_analytics();
        if ($catalog === []) {
            return [];
        }

        try {
            $pdo = db();
            $since = date('Y-m-d H:i:s', strtotime('-' . $days . ' days'));
            $stmt = $pdo->prepare("
                SELECT id, guest_id, payload_json, created_at, status
                FROM crm_outbox
                WHERE restaurant_id = :rid
                  AND channel = 'manual'
                  AND template = 'manual_return'
                  AND created_at >= :since
                ORDER BY created_at ASC, id ASC
                LIMIT 2000
            ");
            $stmt->execute(['rid' => $restaurantId, 'since' => $since]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            if ($rows === []) {
                return [];
            }

            $out = [];
            foreach ($rows as $row) {
                $payload = json_decode((string)($row['payload_json'] ?? '{}'), true);
                if (!is_array($payload)) {
                    $payload = [];
                }

                $segmentType = trim((string)($payload['segment_type'] ?? ''));
                if ($segmentType === '') {
                    $reason = trim((string)($payload['reason'] ?? ''));
                    if (str_starts_with($reason, 'loyalty_retention:')) {
                        $segmentType = substr($reason, strlen('loyalty_retention:'));
                    }
                }
                if ($segmentType === '' || !isset($catalog[$segmentType])) {
                    continue;
                }

                $crmGuestId = (int)($payload['crm_guest_id'] ?? 0);
                if ($crmGuestId <= 0) {
                    $crmGuestId = (int)($row['guest_id'] ?? 0);
                }
                if ($crmGuestId <= 0) {
                    continue;
                }

                $out[] = [
                    'outbox_id' => (int)($row['id'] ?? 0),
                    'crm_guest_id' => $crmGuestId,
                    'loyalty_guest_id' => (int)($payload['loyalty_guest_id'] ?? 0),
                    'segment_type' => $segmentType,
                    'segment_label' => (string)($payload['segment_label'] ?? ($catalog[$segmentType]['label'] ?? $segmentType)),
                    'created_at' => (string)($row['created_at'] ?? ''),
                    'status' => (string)($row['status'] ?? 'draft'),
                    'payload_json' => (string)($row['payload_json'] ?? '{}'),
                ];
            }

            return $out;
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('loyalty_retention_outbox_rows_for_period ' . $e->getMessage());
            }
            return [];
        }
    }
}

if (!function_exists('get_loyalty_retention_guest_feedback_map')) {
    /**
     * Best-effort per-guest feedback loop for priority queue rows.
     * For each guest+segment pair we take the latest loyalty draft row of the same segment
     * and look for the first confirmed paid order after that draft.
     *
     * @param list<array<string,mixed>> $queueRows
     * @return array<string,array<string,mixed>>
     */
    function get_loyalty_retention_guest_feedback_map(int $restaurantId, array $queueRows, int $days = 90): array
    {
        $restaurantId = (int)$restaurantId;
        $days = max(1, min(365, (int)$days));
        if ($restaurantId <= 0 || $queueRows === []) {
            return [];
        }

        $wantedPairs = [];
        foreach ($queueRows as $row) {
            $crmGuestId = (int)($row['crm_guest_id'] ?? 0);
            $segmentType = trim((string)($row['segment_type'] ?? ''));
            if ($crmGuestId <= 0 || $segmentType === '') {
                continue;
            }
            $wantedPairs[$crmGuestId . ':' . $segmentType] = [
                'crm_guest_id' => $crmGuestId,
                'segment_type' => $segmentType,
            ];
        }
        if ($wantedPairs === []) {
            return [];
        }

        $draftRows = loyalty_retention_outbox_rows_for_period($restaurantId, $days);
        if ($draftRows === []) {
            return [];
        }

        $latestByPair = [];
        $minCreatedTs = null;
        $guestIds = [];
        foreach ($draftRows as $draft) {
            $crmGuestId = (int)($draft['crm_guest_id'] ?? 0);
            $segmentType = trim((string)($draft['segment_type'] ?? ''));
            $pairKey = $crmGuestId . ':' . $segmentType;
            if (!isset($wantedPairs[$pairKey])) {
                continue;
            }
            $createdAt = (string)($draft['created_at'] ?? '');
            $createdTs = strtotime($createdAt);
            if ($createdTs === false) {
                continue;
            }
            $existing = $latestByPair[$pairKey] ?? null;
            $existingTs = $existing ? strtotime((string)($existing['created_at'] ?? '')) : false;
            if ($existing === null || $existingTs === false || $createdTs > $existingTs) {
                $latestByPair[$pairKey] = $draft;
            }
            $guestIds[$crmGuestId] = true;
            $minCreatedTs = $minCreatedTs === null ? $createdTs : min($minCreatedTs, $createdTs);
        }

        if ($latestByPair === [] || $guestIds === [] || $minCreatedTs === null) {
            return [];
        }

        $ordersByGuest = [];
        try {
            $pdo = db();
            $guestIdList = array_keys($guestIds);
            $ph = implode(',', array_fill(0, count($guestIdList), '?'));
            $crmGuestExpr = ((function_exists('db_column_exists') && db_column_exists('orders', 'crm_guest_id')) ? 'o.crm_guest_id' : 'o.guest_id');
            $totalSql = retention_analytics_order_total_sql();
            $stmt = $pdo->prepare("
                SELECT
                    o.id,
                    {$crmGuestExpr} AS crm_guest_id,
                    {$totalSql} AS order_total,
                    o.created_at
                FROM orders o
                WHERE o.restaurant_id = ?
                  AND {$crmGuestExpr} IN ({$ph})
                  AND o.created_at > ?
                  AND o.payment_status = 'paid'
                  AND o.order_status <> 'canceled'
                ORDER BY {$crmGuestExpr} ASC, o.created_at ASC
            ");
            $params = array_merge([$restaurantId], $guestIdList, [date('Y-m-d H:i:s', $minCreatedTs)]);
            $stmt->execute($params);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $orderRow) {
                $gid = (int)($orderRow['crm_guest_id'] ?? 0);
                if ($gid <= 0) {
                    continue;
                }
                $ordersByGuest[$gid][] = $orderRow;
            }
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('get_loyalty_retention_guest_feedback_map orders ' . $e->getMessage());
            }
            $ordersByGuest = [];
        }

        $out = [];
        foreach ($latestByPair as $pairKey => $draft) {
            $crmGuestId = (int)($draft['crm_guest_id'] ?? 0);
            $createdAt = (string)($draft['created_at'] ?? '');
            $createdTs = strtotime($createdAt);
            if ($crmGuestId <= 0 || $createdTs === false) {
                continue;
            }

            $state = [
                'has_same_segment_draft' => true,
                'outbox_id' => (int)($draft['outbox_id'] ?? 0),
                'outbox_status' => (string)($draft['status'] ?? ''),
                'outbox_created_at' => $createdAt,
                'segment_type' => (string)($draft['segment_type'] ?? ''),
                'segment_label' => (string)($draft['segment_label'] ?? ''),
                'template_name' => '',
                'returned' => false,
                'return_order_id' => 0,
                'return_order_created_at' => '',
                'return_order_total' => 0.0,
                'days_to_return' => null,
            ];

            $payload = json_decode((string)($draft['payload_json'] ?? '{}'), true);
            if (is_array($payload)) {
                $state['template_name'] = (string)($payload['template_name'] ?? '');
            }

            foreach (($ordersByGuest[$crmGuestId] ?? []) as $orderRow) {
                $orderTs = strtotime((string)($orderRow['created_at'] ?? ''));
                if ($orderTs === false || $orderTs <= $createdTs) {
                    continue;
                }
                if (($orderTs - $createdTs) < 3600) {
                    continue;
                }
                $state['returned'] = true;
                $state['return_order_id'] = (int)($orderRow['id'] ?? 0);
                $state['return_order_created_at'] = (string)($orderRow['created_at'] ?? '');
                $state['return_order_total'] = (float)($orderRow['order_total'] ?? 0);
                $state['days_to_return'] = max(0, (int)floor(($orderTs - $createdTs) / 86400));
                break;
            }

            $out[$pairKey] = $state;
        }

        return $out;
    }
}

if (!function_exists('get_manual_return_outbox_outcome_map')) {
    /**
     * Compact per-row outcome map for CRM send-board.
     * Uses the first confirmed paid order after a manual_return row.
     *
     * @param list<array<string,mixed>> $outboxRows
     * @return array<int,array<string,mixed>>
     */
    function get_manual_return_outbox_outcome_map(int $restaurantId, array $outboxRows, int $days = 90): array
    {
        $restaurantId = (int)$restaurantId;
        $days = max(1, min(365, (int)$days));
        if ($restaurantId <= 0 || $outboxRows === []) {
            return [];
        }

        $candidateRows = [];
        $guestIds = [];
        $minCreatedTs = null;
        foreach ($outboxRows as $row) {
            $outboxId = (int)($row['id'] ?? 0);
            if ($outboxId <= 0) {
                continue;
            }
            $payload = json_decode((string)($row['payload_json'] ?? '{}'), true);
            if (!is_array($payload)) {
                $payload = [];
            }
            $crmGuestId = (int)($payload['crm_guest_id'] ?? 0);
            if ($crmGuestId <= 0) {
                $crmGuestId = (int)($row['guest_id'] ?? 0);
            }
            $createdAt = (string)($row['created_at'] ?? '');
            $createdTs = strtotime($createdAt);
            if ($crmGuestId <= 0 || $createdTs === false) {
                continue;
            }
            $candidateRows[$outboxId] = [
                'crm_guest_id' => $crmGuestId,
                'created_at' => $createdAt,
                'status' => (string)($row['status'] ?? ''),
            ];
            $guestIds[$crmGuestId] = true;
            $minCreatedTs = $minCreatedTs === null ? $createdTs : min($minCreatedTs, $createdTs);
        }
        if ($candidateRows === [] || $guestIds === [] || $minCreatedTs === null) {
            return [];
        }

        $ordersByGuest = [];
        try {
            $pdo = db();
            $guestIdList = array_keys($guestIds);
            $ph = implode(',', array_fill(0, count($guestIdList), '?'));
            $crmGuestExpr = ((function_exists('db_column_exists') && db_column_exists('orders', 'crm_guest_id')) ? 'o.crm_guest_id' : 'o.guest_id');
            $totalSql = retention_analytics_order_total_sql();
            $stmt = $pdo->prepare("
                SELECT
                    o.id,
                    {$crmGuestExpr} AS crm_guest_id,
                    {$totalSql} AS order_total,
                    o.created_at
                FROM orders o
                WHERE o.restaurant_id = ?
                  AND {$crmGuestExpr} IN ({$ph})
                  AND o.created_at > ?
                  AND o.payment_status = 'paid'
                  AND o.order_status <> 'canceled'
                ORDER BY {$crmGuestExpr} ASC, o.created_at ASC
            ");
            $params = array_merge([$restaurantId], $guestIdList, [date('Y-m-d H:i:s', $minCreatedTs)]);
            $stmt->execute($params);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $orderRow) {
                $gid = (int)($orderRow['crm_guest_id'] ?? 0);
                if ($gid <= 0) {
                    continue;
                }
                $ordersByGuest[$gid][] = $orderRow;
            }
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('get_manual_return_outbox_outcome_map orders ' . $e->getMessage());
            }
            return [];
        }

        $out = [];
        foreach ($candidateRows as $outboxId => $row) {
            $createdTs = strtotime((string)$row['created_at']);
            if ($createdTs === false) {
                continue;
            }
            $result = [
                'returned' => false,
                'return_order_id' => 0,
                'return_order_total' => 0.0,
                'return_order_created_at' => '',
                'days_to_return' => null,
            ];
            foreach (($ordersByGuest[(int)$row['crm_guest_id']] ?? []) as $orderRow) {
                $orderTs = strtotime((string)($orderRow['created_at'] ?? ''));
                if ($orderTs === false || $orderTs <= $createdTs) {
                    continue;
                }
                if (($orderTs - $createdTs) < 3600) {
                    continue;
                }
                $result['returned'] = true;
                $result['return_order_id'] = (int)($orderRow['id'] ?? 0);
                $result['return_order_total'] = (float)($orderRow['order_total'] ?? 0);
                $result['return_order_created_at'] = (string)($orderRow['created_at'] ?? '');
                $result['days_to_return'] = max(0, (int)floor(($orderTs - $createdTs) / 86400));
                break;
            }
            $out[$outboxId] = $result;
        }

        return $out;
    }
}

if (!function_exists('get_loyalty_retention_scenario_analytics')) {
    /**
     * Return analytics by loyalty scenario from crm_outbox drafts/manual rows.
     * A return is the first confirmed paid order after draft creation.
     *
     * @return array<string,array<string,mixed>>
     */
    function get_loyalty_retention_scenario_analytics(int $restaurantId, int $days = 30): array
    {
        $restaurantId = (int)$restaurantId;
        $days = max(1, min(365, (int)$days));
        $catalog = loyalty_retention_scenario_catalog_for_analytics();

        $base = [];
        foreach ($catalog as $segmentType => $cfg) {
            $base[$segmentType] = [
                'segment_type' => $segmentType,
                'label' => (string)($cfg['label'] ?? $segmentType),
                'drafts_created' => 0,
                'unique_guests_targeted' => 0,
                'returned_guests' => 0,
                'paid_orders_after_draft' => 0,
                'returned_revenue' => 0.0,
                'return_rate' => 0.0,
                'avg_days_to_return' => null,
                'last_draft_at' => null,
            ];
        }
        if ($restaurantId <= 0 || $base === []) {
            return $base;
        }

        $drafts = loyalty_retention_outbox_rows_for_period($restaurantId, $days);
        if ($drafts === []) {
            return $base;
        }

        $guestIds = [];
        $minCreatedTs = null;
        foreach ($drafts as $draft) {
            $guestId = (int)($draft['crm_guest_id'] ?? 0);
            $createdTs = strtotime((string)($draft['created_at'] ?? ''));
            if ($guestId > 0) {
                $guestIds[$guestId] = true;
            }
            if ($createdTs !== false) {
                $minCreatedTs = ($minCreatedTs === null) ? $createdTs : min($minCreatedTs, $createdTs);
            }
        }

        $ordersByGuest = [];
        if ($guestIds !== [] && $minCreatedTs !== null) {
            try {
                $pdo = db();
                $guestIdList = array_keys($guestIds);
                $ph = implode(',', array_fill(0, count($guestIdList), '?'));
                $crmGuestExpr = ((function_exists('db_column_exists') && db_column_exists('orders', 'crm_guest_id')) ? 'o.crm_guest_id' : 'o.guest_id');
                $totalSql = retention_analytics_order_total_sql();
                $stmt = $pdo->prepare("
                    SELECT
                        o.id,
                        {$crmGuestExpr} AS crm_guest_id,
                        {$totalSql} AS order_total,
                        o.created_at
                    FROM orders o
                    WHERE o.restaurant_id = ?
                      AND {$crmGuestExpr} IN ({$ph})
                      AND o.created_at > ?
                      AND o.payment_status = 'paid'
                      AND o.order_status <> 'canceled'
                    ORDER BY {$crmGuestExpr} ASC, o.created_at ASC
                ");
                $params = array_merge([$restaurantId], $guestIdList, [date('Y-m-d H:i:s', $minCreatedTs)]);
                $stmt->execute($params);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $orderRow) {
                    $gid = (int)($orderRow['crm_guest_id'] ?? 0);
                    if ($gid <= 0) {
                        continue;
                    }
                    if (!isset($ordersByGuest[$gid])) {
                        $ordersByGuest[$gid] = [];
                    }
                    $ordersByGuest[$gid][] = $orderRow;
                }
            } catch (Throwable $e) {
                if (function_exists('error_log')) {
                    error_log('get_loyalty_retention_scenario_analytics orders ' . $e->getMessage());
                }
            }
        }

        $targetedGuests = [];
        $returnedGuests = [];
        $sumDays = [];
        $cntDays = [];
        $usedOrderIds = [];

        foreach ($drafts as $draft) {
            $segmentType = (string)($draft['segment_type'] ?? '');
            if (!isset($base[$segmentType])) {
                continue;
            }
            $crmGuestId = (int)($draft['crm_guest_id'] ?? 0);
            $createdAt = (string)($draft['created_at'] ?? '');
            $createdTs = strtotime($createdAt);

            $base[$segmentType]['drafts_created']++;
            $base[$segmentType]['last_draft_at'] = $createdAt !== ''
                ? (string)$createdAt
                : ($base[$segmentType]['last_draft_at'] ?? null);
            if ($crmGuestId > 0) {
                $targetedGuests[$segmentType][$crmGuestId] = true;
            }

            if ($crmGuestId <= 0 || $createdTs === false) {
                continue;
            }

            foreach (($ordersByGuest[$crmGuestId] ?? []) as $orderRow) {
                $orderId = (int)($orderRow['id'] ?? 0);
                if ($orderId <= 0 || isset($usedOrderIds[$orderId])) {
                    continue;
                }
                $orderTs = strtotime((string)($orderRow['created_at'] ?? ''));
                if ($orderTs === false || $orderTs <= $createdTs) {
                    continue;
                }
                if (($orderTs - $createdTs) < 3600) {
                    continue;
                }

                $usedOrderIds[$orderId] = true;
                $returnedGuests[$segmentType][$crmGuestId] = true;
                $base[$segmentType]['paid_orders_after_draft']++;
                $base[$segmentType]['returned_revenue'] += (float)($orderRow['order_total'] ?? 0);
                $daysToReturn = (int)floor(($orderTs - $createdTs) / 86400);
                if ($daysToReturn >= 0) {
                    $sumDays[$segmentType] = ($sumDays[$segmentType] ?? 0) + $daysToReturn;
                    $cntDays[$segmentType] = ($cntDays[$segmentType] ?? 0) + 1;
                }
                break;
            }
        }

        foreach ($base as $segmentType => &$row) {
            $row['unique_guests_targeted'] = isset($targetedGuests[$segmentType]) ? count($targetedGuests[$segmentType]) : 0;
            $row['returned_guests'] = isset($returnedGuests[$segmentType]) ? count($returnedGuests[$segmentType]) : 0;
            $row['returned_revenue'] = round((float)$row['returned_revenue'], 2);
            $row['return_rate'] = $row['unique_guests_targeted'] > 0
                ? round($row['returned_guests'] / $row['unique_guests_targeted'], 4)
                : 0.0;
            $row['avg_days_to_return'] = !empty($cntDays[$segmentType])
                ? round(((float)($sumDays[$segmentType] ?? 0)) / (float)$cntDays[$segmentType], 2)
                : null;
        }
        unset($row);

        return $base;
    }
}

if (!function_exists('get_loyalty_retention_scenario_analytics_cached')) {
    function get_loyalty_retention_scenario_analytics_cached(int $restaurantId, int $days = 30): array
    {
        $restaurantId = (int)$restaurantId;
        $days = max(1, min(365, (int)$days));
        $ttl = 600;
        $cacheKey = 'loyalty_retention_scenarios_v1:' . $restaurantId . ':' . $days;
        if (function_exists('cache_get')) {
            $cached = cache_get($cacheKey);
            if (is_array($cached)) {
                return $cached;
            }
        }
        $data = get_loyalty_retention_scenario_analytics($restaurantId, $days);
        if (function_exists('cache_set')) {
            cache_set($cacheKey, $data, $ttl);
        }
        return $data;
    }
}

if (!function_exists('get_loyalty_retention_business_summary')) {
    /**
     * Manager-facing CRM/retention summary for dashboard cards.
     * Uses loyalty retention outbox rows and confirmed paid orders after draft.
     *
     * @return array{
     *   drafts_created:int,
     *   unique_guests_targeted:int,
     *   returned_guests:int,
     *   paid_orders_after_draft:int,
     *   returned_revenue:float,
     *   scenarios_active:int,
     *   last_draft_at:?string,
     *   best_scenario:?array<string,mixed>
     * }
     */
    function get_loyalty_retention_business_summary(int $restaurantId, int $days = 30): array
    {
        $restaurantId = (int)$restaurantId;
        $days = max(1, min(365, (int)$days));
        $empty = [
            'drafts_created' => 0,
            'unique_guests_targeted' => 0,
            'returned_guests' => 0,
            'paid_orders_after_draft' => 0,
            'returned_revenue' => 0.0,
            'scenarios_active' => 0,
            'last_draft_at' => null,
            'best_scenario' => null,
        ];
        if ($restaurantId <= 0) {
            return $empty;
        }

        $scenarioAnalytics = function_exists('get_loyalty_retention_scenario_analytics_cached')
            ? get_loyalty_retention_scenario_analytics_cached($restaurantId, $days)
            : get_loyalty_retention_scenario_analytics($restaurantId, $days);

        $bestScenario = null;
        $scenariosActive = 0;
        foreach ($scenarioAnalytics as $row) {
            if ((int)($row['drafts_created'] ?? 0) > 0) {
                $scenariosActive++;
            }
            if ($bestScenario === null) {
                $bestScenario = $row;
                continue;
            }
            $revenueCmp = ((float)($row['returned_revenue'] ?? 0) <=> (float)($bestScenario['returned_revenue'] ?? 0));
            if ($revenueCmp > 0) {
                $bestScenario = $row;
                continue;
            }
            if ($revenueCmp === 0) {
                $returnCmp = ((int)($row['returned_guests'] ?? 0) <=> (int)($bestScenario['returned_guests'] ?? 0));
                if ($returnCmp > 0) {
                    $bestScenario = $row;
                    continue;
                }
                if ($returnCmp === 0 && (float)($row['return_rate'] ?? 0) > (float)($bestScenario['return_rate'] ?? 0)) {
                    $bestScenario = $row;
                }
            }
        }

        $draftRows = loyalty_retention_outbox_rows_for_period($restaurantId, $days);
        if ($draftRows === []) {
            $empty['scenarios_active'] = $scenariosActive;
            if ($bestScenario !== null && ((int)($bestScenario['drafts_created'] ?? 0) > 0 || (float)($bestScenario['returned_revenue'] ?? 0) > 0)) {
                $empty['best_scenario'] = $bestScenario;
            }
            return $empty;
        }

        usort($draftRows, static function (array $a, array $b): int {
            $aTs = strtotime((string)($a['created_at'] ?? '')) ?: 0;
            $bTs = strtotime((string)($b['created_at'] ?? '')) ?: 0;
            return $aTs <=> $bTs;
        });

        $guestIds = [];
        $uniqueGuests = [];
        $minCreatedTs = null;
        $lastDraftAt = null;
        foreach ($draftRows as $draft) {
            $crmGuestId = (int)($draft['crm_guest_id'] ?? 0);
            $createdAt = (string)($draft['created_at'] ?? '');
            $createdTs = strtotime($createdAt);
            if ($crmGuestId > 0) {
                $guestIds[$crmGuestId] = true;
                $uniqueGuests[$crmGuestId] = true;
            }
            if ($createdTs !== false) {
                $minCreatedTs = $minCreatedTs === null ? $createdTs : min($minCreatedTs, $createdTs);
                if ($lastDraftAt === null || $createdAt > $lastDraftAt) {
                    $lastDraftAt = $createdAt;
                }
            }
        }

        $ordersByGuest = [];
        if ($guestIds !== [] && $minCreatedTs !== null) {
            try {
                $pdo = db();
                $guestIdList = array_keys($guestIds);
                $ph = implode(',', array_fill(0, count($guestIdList), '?'));
                $crmGuestExpr = ((function_exists('db_column_exists') && db_column_exists('orders', 'crm_guest_id')) ? 'o.crm_guest_id' : 'o.guest_id');
                $totalSql = retention_analytics_order_total_sql();
                $stmt = $pdo->prepare("
                    SELECT
                        o.id,
                        {$crmGuestExpr} AS crm_guest_id,
                        {$totalSql} AS order_total,
                        o.created_at
                    FROM orders o
                    WHERE o.restaurant_id = ?
                      AND {$crmGuestExpr} IN ({$ph})
                      AND o.created_at > ?
                      AND o.payment_status = 'paid'
                      AND o.order_status <> 'canceled'
                    ORDER BY {$crmGuestExpr} ASC, o.created_at ASC
                ");
                $params = array_merge([$restaurantId], $guestIdList, [date('Y-m-d H:i:s', $minCreatedTs)]);
                $stmt->execute($params);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $orderRow) {
                    $gid = (int)($orderRow['crm_guest_id'] ?? 0);
                    if ($gid <= 0) {
                        continue;
                    }
                    if (!isset($ordersByGuest[$gid])) {
                        $ordersByGuest[$gid] = [];
                    }
                    $ordersByGuest[$gid][] = $orderRow;
                }
            } catch (Throwable $e) {
                if (function_exists('error_log')) {
                    error_log('get_loyalty_retention_business_summary orders ' . $e->getMessage());
                }
            }
        }

        $returnedGuests = [];
        $usedOrderIds = [];
        $paidOrdersAfterDraft = 0;
        $returnedRevenue = 0.0;
        foreach ($draftRows as $draft) {
            $crmGuestId = (int)($draft['crm_guest_id'] ?? 0);
            $createdTs = strtotime((string)($draft['created_at'] ?? ''));
            if ($crmGuestId <= 0 || $createdTs === false) {
                continue;
            }
            foreach (($ordersByGuest[$crmGuestId] ?? []) as $orderRow) {
                $orderId = (int)($orderRow['id'] ?? 0);
                if ($orderId <= 0 || isset($usedOrderIds[$orderId])) {
                    continue;
                }
                $orderTs = strtotime((string)($orderRow['created_at'] ?? ''));
                if ($orderTs === false || $orderTs <= $createdTs) {
                    continue;
                }
                if (($orderTs - $createdTs) < 3600) {
                    continue;
                }
                $usedOrderIds[$orderId] = true;
                $returnedGuests[$crmGuestId] = true;
                $paidOrdersAfterDraft++;
                $returnedRevenue += (float)($orderRow['order_total'] ?? 0);
                break;
            }
        }

        return [
            'drafts_created' => count($draftRows),
            'unique_guests_targeted' => count($uniqueGuests),
            'returned_guests' => count($returnedGuests),
            'paid_orders_after_draft' => $paidOrdersAfterDraft,
            'returned_revenue' => round($returnedRevenue, 2),
            'scenarios_active' => $scenariosActive,
            'last_draft_at' => $lastDraftAt,
            'best_scenario' => ($bestScenario !== null && (((int)($bestScenario['drafts_created'] ?? 0) > 0) || ((float)($bestScenario['returned_revenue'] ?? 0) > 0)))
                ? $bestScenario
                : null,
        ];
    }
}

if (!function_exists('get_loyalty_retention_business_summary_cached')) {
    function get_loyalty_retention_business_summary_cached(int $restaurantId, int $days = 30): array
    {
        $restaurantId = (int)$restaurantId;
        $days = max(1, min(365, (int)$days));
        $cacheKey = 'loyalty_retention_business_summary_v1:' . $restaurantId . ':' . $days;
        if (function_exists('cache_get')) {
            $cached = cache_get($cacheKey);
            if (is_array($cached)) {
                return $cached;
            }
        }
        $data = get_loyalty_retention_business_summary($restaurantId, $days);
        if (function_exists('cache_set')) {
            cache_set($cacheKey, $data, 600);
        }
        return $data;
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
                $gid = retention_payload_crm_guest_id($pl);
                if ($gid > 0) $excludedGuests[$gid] = true;
            }

            $stmt = $pdo->prepare("
                SELECT id, " . ((function_exists('db_column_exists') && db_column_exists('orders', 'crm_guest_id')) ? "crm_guest_id" : "guest_id") . " AS crm_guest_id, created_at
                FROM orders
                WHERE restaurant_id = :rid
                  AND " . ((function_exists('db_column_exists') && db_column_exists('orders', 'crm_guest_id')) ? "crm_guest_id" : "guest_id") . " IS NOT NULL
                  AND " . ((function_exists('db_column_exists') && db_column_exists('orders', 'crm_guest_id')) ? "crm_guest_id" : "guest_id") . " > 0
                  AND created_at >= :since
                ORDER BY " . ((function_exists('db_column_exists') && db_column_exists('orders', 'crm_guest_id')) ? "crm_guest_id" : "guest_id") . " ASC, created_at ASC
                LIMIT 10000
            ");
            $stmt->execute(['rid' => $restaurantId, 'since' => $since]);
            $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!$orders) return ['baseline_return_rate' => null, 'baseline_window_days' => $baselineWindowDays];

            $byGuest = [];
            foreach ($orders as $o) {
                $gid = (int)($o['crm_guest_id'] ?? 0);
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
            if (!array_key_exists('crm_guest_id', $payload)) {
                $payload['crm_guest_id'] = (int)($payload['guest_id'] ?? 0);
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
