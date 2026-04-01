<?php
/**
 * Feedback-driven growth automation (suggestions only).
 * No auto-send. No hidden writes.
 */

require_once __DIR__ . '/db.php';
if (file_exists(__DIR__ . '/schema_guard.php')) {
    require_once __DIR__ . '/schema_guard.php';
}
if (file_exists(__DIR__ . '/feedback_analytics.php')) {
    require_once __DIR__ . '/feedback_analytics.php';
}
if (file_exists(__DIR__ . '/growth_engine_arch.php')) {
    require_once __DIR__ . '/growth_engine_arch.php';
}
if (file_exists(__DIR__ . '/loyalty_return_mode.php')) {
    require_once __DIR__ . '/loyalty_return_mode.php';
}
if (file_exists(__DIR__ . '/feedback_crm_bridge.php')) {
    require_once __DIR__ . '/feedback_crm_bridge.php';
}

if (!function_exists('feedback_growth_window_dates')) {
    /**
     * @return array{start:string,end:string}
     */
    function feedback_growth_window_dates(int $days): array
    {
        $d = function_exists('feedback_analytics_days_window') ? feedback_analytics_days_window($days) : max(1, min(365, $days));
        $end = date('Y-m-d');
        $start = date('Y-m-d', strtotime('-' . max(0, $d - 1) . ' days'));
        return ['start' => $start, 'end' => $end];
    }
}

if (!function_exists('feedback_growth_make_key')) {
    function feedback_growth_make_key(string $type, string $startDate, string $endDate): string
    {
        return 'feedback-auto:' . $type . ':' . $startDate . ':' . $endDate;
    }
}

if (!function_exists('feedback_growth_type_rank')) {
    /**
     * Higher = more important when capping window suggestions.
     */
    function feedback_growth_type_rank(string $type): int
    {
        switch ($type) {
            case 'feedback_negative_spike':
                return 100;
            case 'feedback_rating_drop':
                return 95;
            case 'feedback_positive_cluster':
                return 85;
            case 'loyalty_recovery_offer':
                return 50;
            case 'loyalty_return_offer':
                return 45;
            default:
                return 10;
        }
    }
}

if (!function_exists('feedback_growth_suggestion_exists')) {
    function feedback_growth_suggestion_exists(PDO $pdo, int $restaurantId, string $type, string $bridgeKey): bool
    {
        if ($restaurantId <= 0 || $type === '' || $bridgeKey === '') {
            return false;
        }
        if (!function_exists('db_table_exists') || !db_table_exists('growth_engine_suggestions')) {
            return false;
        }
        try {
            if (function_exists('db_column_exists') && db_column_exists('growth_engine_suggestions', 'bridge_key')) {
                $stmt = $pdo->prepare("
                    SELECT 1
                    FROM growth_engine_suggestions
                    WHERE restaurant_id = :rid
                      AND type = :type
                      AND bridge_key = :bkey
                    LIMIT 1
                ");
                $stmt->execute(['rid' => $restaurantId, 'type' => $type, 'bkey' => $bridgeKey]);
            } else {
                $needle = '"bridge_key":"' . $bridgeKey . '"';
                $stmt = $pdo->prepare("
                    SELECT 1
                    FROM growth_engine_suggestions
                    WHERE restaurant_id = :rid
                      AND type = :type
                      AND payload_json LIKE :needle
                    LIMIT 1
                ");
                $stmt->execute(['rid' => $restaurantId, 'type' => $type, 'needle' => '%' . $needle . '%']);
            }
            return (bool)$stmt->fetchColumn();
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('feedback_growth_suggestion_exists ' . $e->getMessage());
            }
            return false;
        }
    }
}

if (!function_exists('get_feedback_growth_opportunities')) {
    /**
     * @return array<int,array{
     *  type:string,
     *  priority:string,
     *  title:string,
     *  description:string,
     *  payload_json:string,
     *  bridge_key:string,
     *  source:string
     * }>
     */
    function get_feedback_growth_opportunities(int $restaurantId, int $days = 30, int $limit = 10): array
    {
        $restaurantId = (int)$restaurantId;
        $limit = max(1, min(20, (int)$limit));
        if ($restaurantId <= 0 || !function_exists('feedback_analytics_is_ready') || !feedback_analytics_is_ready()) {
            return [];
        }

        $window = feedback_growth_window_dates($days);
        $summary = get_feedback_analytics_summary($restaurantId, $days);
        $breakdown = get_feedback_rating_breakdown($restaurantId, $days);
        $trend = get_feedback_trend_points($restaurantId, $days);
        $total = (int)($summary['total_feedback'] ?? 0);
        if ($total <= 0) {
            return [];
        }

        $out = [];
        $lowCount = (int)($breakdown[1] ?? 0) + (int)($breakdown[2] ?? 0);
        $fiveCount = (int)($breakdown[5] ?? 0);
        $avg = $summary['average_rating'] !== null ? (float)$summary['average_rating'] : 0.0;

        // Rule A: negative spike (conservative threshold).
        if ($total >= 6 && $lowCount >= 4 && ($lowCount / $total) >= 0.35) {
            $type = 'feedback_negative_spike';
            $bridgeKey = feedback_growth_make_key($type, $window['start'], $window['end']);
            $payload = [
                'bridge_key' => $bridgeKey,
                'window_start' => $window['start'],
                'window_end' => $window['end'],
                'days' => (int)$summary['response_window_days'],
                'low_ratings_count' => $lowCount,
                'total_feedback' => $total,
                'average_rating' => $avg,
            ];
            $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);
            if (is_string($payloadJson)) {
                $out[] = [
                    'type' => $type,
                    'priority' => 'high',
                    'title' => 'Low-rating spike detected',
                    'description' => $lowCount . ' low ratings (1–2★) in the last ' . (int)$summary['response_window_days'] . ' days. Review service issues and consider a recovery campaign.',
                    'payload_json' => $payloadJson,
                    'bridge_key' => $bridgeKey,
                    'source' => 'feedback_growth_automation',
                ];
                if (function_exists('loyalty_return_mode_enabled') && loyalty_return_mode_enabled($restaurantId)
                    && function_exists('build_loyalty_recovery_window_suggestion')) {
                    $loy = build_loyalty_recovery_window_suggestion([
                        'window_start' => $window['start'],
                        'window_end' => $window['end'],
                        'days' => (int)$summary['response_window_days'],
                        'low_ratings_count' => $lowCount,
                    ]);
                    if ($loy !== null) {
                        $out[] = $loy;
                    }
                }
            }
        }

        // Rule B: strong positive signal.
        if ($total >= 10 && $fiveCount >= 6 && ($fiveCount / $total) >= 0.55) {
            $type = 'feedback_positive_cluster';
            $bridgeKey = feedback_growth_make_key($type, $window['start'], $window['end']);
            $payload = [
                'bridge_key' => $bridgeKey,
                'window_start' => $window['start'],
                'window_end' => $window['end'],
                'days' => (int)$summary['response_window_days'],
                'five_star_count' => $fiveCount,
                'total_feedback' => $total,
                'average_rating' => $avg,
            ];
            $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);
            if (is_string($payloadJson)) {
                $out[] = [
                    'type' => $type,
                    'priority' => 'medium',
                    'title' => 'Strong positive guest signal',
                    'description' => $fiveCount . ' guests left 5★ feedback in the last ' . (int)$summary['response_window_days'] . ' days. Consider a comeback campaign for happy guests.',
                    'payload_json' => $payloadJson,
                    'bridge_key' => $bridgeKey,
                    'source' => 'feedback_growth_automation',
                ];
                if (function_exists('loyalty_return_mode_enabled') && loyalty_return_mode_enabled($restaurantId)
                    && function_exists('build_loyalty_return_window_suggestion')) {
                    $loy = build_loyalty_return_window_suggestion([
                        'window_start' => $window['start'],
                        'window_end' => $window['end'],
                        'days' => (int)$summary['response_window_days'],
                    ]);
                    if ($loy !== null) {
                        $out[] = $loy;
                    }
                }
            }
        }

        // Rule C: rating drop between early and recent halves.
        if (count($trend) >= 6) {
            $half = (int)floor(count($trend) / 2);
            $early = array_slice($trend, 0, $half);
            $recent = array_slice($trend, $half);

            $earlyWeightedSum = 0.0;
            $earlyWeight = 0;
            $recentWeightedSum = 0.0;
            $recentWeight = 0;

            foreach ($early as $p) {
                if ($p['average_rating'] !== null) {
                    $dayAvg = (float)$p['average_rating'];
                    $dayTotal = (int)($p['total_feedback'] ?? 0);
                    if ($dayTotal > 0) {
                        $earlyWeightedSum += ($dayAvg * $dayTotal);
                        $earlyWeight += $dayTotal;
                    }
                }
            }
            foreach ($recent as $p) {
                if ($p['average_rating'] !== null) {
                    $dayAvg = (float)$p['average_rating'];
                    $dayTotal = (int)($p['total_feedback'] ?? 0);
                    if ($dayTotal > 0) {
                        $recentWeightedSum += ($dayAvg * $dayTotal);
                        $recentWeight += $dayTotal;
                    }
                }
            }

            if ($earlyWeight > 0 && $recentWeight > 0) {
                $earlyAvg = $earlyWeightedSum / $earlyWeight;
                $recentAvg = $recentWeightedSum / $recentWeight;
                $drop = $earlyAvg - $recentAvg;
                if ($drop >= 0.5) {
                    $type = 'feedback_rating_drop';
                    $bridgeKey = feedback_growth_make_key($type, $window['start'], $window['end']);
                    $payload = [
                        'bridge_key' => $bridgeKey,
                        'window_start' => $window['start'],
                        'window_end' => $window['end'],
                        'days' => (int)$summary['response_window_days'],
                        'early_avg' => round($earlyAvg, 2),
                        'recent_avg' => round($recentAvg, 2),
                        'drop' => round($drop, 2),
                    ];
                    $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);
                    if (is_string($payloadJson)) {
                        $out[] = [
                            'type' => $type,
                            'priority' => 'high',
                            'title' => 'Average rating dropped',
                            'description' => 'Average rating fell from ' . round($earlyAvg, 1) . ' to ' . round($recentAvg, 1) . ' in the selected window. Review recent service changes.',
                            'payload_json' => $payloadJson,
                            'bridge_key' => $bridgeKey,
                            'source' => 'feedback_growth_automation',
                        ];
                    }
                }
            }
        }

        usort($out, function ($a, $b) {
            $ta = (string)($a['type'] ?? '');
            $tb = (string)($b['type'] ?? '');
            return feedback_growth_type_rank($tb) <=> feedback_growth_type_rank($ta);
        });

        $maxWindow = 2;
        $out = array_slice($out, 0, min($limit, $maxWindow));

        return $out;
    }
}

if (!function_exists('publish_feedback_growth_suggestions')) {
    /**
     * Explicit-only publish to growth_engine_suggestions.
     */
    function publish_feedback_growth_suggestions(int $restaurantId, int $days = 30): int
    {
        $restaurantId = (int)$restaurantId;
        if ($restaurantId <= 0) {
            return 0;
        }
        if (function_exists('is_demo_mode') && is_demo_mode()) {
            return 0;
        }
        if (!function_exists('db_table_exists') || !db_table_exists('growth_engine_suggestions')) {
            return 0;
        }

        $opps = get_feedback_growth_opportunities($restaurantId, $days, 10);
        $created = 0;
        try {
            if ($opps !== []) {
                $pdo = db();
                $hasStatus = function_exists('db_column_exists') && db_column_exists('growth_engine_suggestions', 'status');
                $hasBridgeKey = function_exists('db_column_exists') && db_column_exists('growth_engine_suggestions', 'bridge_key');
                foreach ($opps as $o) {
                    $type = (string)($o['type'] ?? '');
                    $title = (string)($o['title'] ?? '');
                    $desc = (string)($o['description'] ?? '');
                    $payloadJson = (string)($o['payload_json'] ?? '');
                    $bridgeKey = (string)($o['bridge_key'] ?? '');
                    $priority = (string)($o['priority'] ?? 'medium');
                    $source = (string)($o['source'] ?? 'feedback_growth_automation');
                    if ($type === '' || $title === '' || $payloadJson === '' || $bridgeKey === '') {
                        continue;
                    }

                    if (feedback_growth_suggestion_exists($pdo, $restaurantId, $type, $bridgeKey)) {
                        continue;
                    }

                    if (function_exists('growth_engine_suggestion_duplicate_exists')
                        && growth_engine_suggestion_duplicate_exists($pdo, $restaurantId, $type, $payloadJson, $title)
                    ) {
                        continue;
                    }

                    if ($hasStatus) {
                        if ($hasBridgeKey) {
                            $stmt = $pdo->prepare("
                                INSERT INTO growth_engine_suggestions
                                    (restaurant_id, type, title, description, payload_json, bridge_key, priority, source, status)
                                VALUES
                                    (?, ?, ?, ?, ?, ?, ?, ?, 'pending')
                            ");
                            $stmt->execute([$restaurantId, $type, $title, $desc, $payloadJson, $bridgeKey, $priority, $source]);
                        } else {
                            $stmt = $pdo->prepare("
                                INSERT INTO growth_engine_suggestions
                                    (restaurant_id, type, title, description, payload_json, priority, source, status)
                                VALUES
                                    (?, ?, ?, ?, ?, ?, ?, 'pending')
                            ");
                            $stmt->execute([$restaurantId, $type, $title, $desc, $payloadJson, $priority, $source]);
                        }
                    } else {
                        if ($hasBridgeKey) {
                            $stmt = $pdo->prepare("
                                INSERT INTO growth_engine_suggestions
                                    (restaurant_id, type, title, description, payload_json, bridge_key)
                                VALUES
                                    (?, ?, ?, ?, ?, ?)
                            ");
                            $stmt->execute([$restaurantId, $type, $title, $desc, $payloadJson, $bridgeKey]);
                        } else {
                            $stmt = $pdo->prepare("
                                INSERT INTO growth_engine_suggestions
                                    (restaurant_id, type, title, description, payload_json)
                                VALUES
                                    (?, ?, ?, ?, ?)
                            ");
                            $stmt->execute([$restaurantId, $type, $title, $desc, $payloadJson]);
                        }
                    }
                    $created++;
                }
            }
        } catch (Throwable $e) {
            error_log('publish_feedback_growth_suggestions ' . $e->getMessage());
        }

        // Additionally publish actionable feedback->upsell return scenarios (explicit-only, suggestion-only).
        if (function_exists('publish_feedback_based_suggestions')) {
            try {
                $created += publish_feedback_based_suggestions($restaurantId, 6);
            } catch (Throwable $e) {
                // never break growth publish
            }
        }

        return $created;
    }
}

