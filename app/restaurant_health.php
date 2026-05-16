<?php
/**
 * Restaurant Health Score (transparent, recommendation-based).
 *
 * Requirements:
 * - Read-only (no writes).
 * - Same-restaurant data only.
 * - Avoid misleading claims; use measurable signals and conservative thresholds.
 */

if (file_exists(__DIR__ . '/cache.php')) {
    require_once __DIR__ . '/cache.php';
}
if (file_exists(__DIR__ . '/schema_guard.php')) {
    require_once __DIR__ . '/schema_guard.php';
}

/**
 * Single canonical health score (score + label + explanation).
 *
 * @return array{score:int,label:string,explanation:string}
 */
function get_restaurant_health_score(int $restaurantId): array
{
    if (function_exists('is_demo_mode') && is_demo_mode()) {
        return ['score' => 78, 'label' => 'Стабильно', 'explanation' => 'Демо: заказы, удержание, допродажи и заполненность меню выглядят здоровыми.'];
    }
    $b = get_restaurant_health_breakdown($restaurantId);
    $score = (int)($b['total']['score'] ?? 0);
    $label = (string)($b['total']['label'] ?? 'Improving');
    $explanation = (string)($b['total']['explanation'] ?? 'Based on measurable activity and setup signals.');
    return ['score' => $score, 'label' => $label, 'explanation' => $explanation];
}

/**
 * @return array{
 *   total: array{score:int,label:string,explanation:string},
 *   components: array<string, array{weight:int,score:int,points:int,value:mixed,notes:string}>
 * }
 */
function get_restaurant_health_breakdown(int $restaurantId): array
{
    $restaurantId = (int)$restaurantId;
    if (function_exists('is_demo_mode') && is_demo_mode()) {
        return _restaurant_health_demo_breakdown();
    }

    $cacheKey = 'health_breakdown:' . $restaurantId;
        if (function_exists('cache_get')) {
            $cached = cache_get($cacheKey);
            if (is_array($cached) && isset($cached['total'], $cached['components'])) {
                return $cached;
            }
        }

        $components = [];
        $weights = [
            'aov' => 20,
            'repeat_guest_rate' => 20,
            'inactive_guests' => 10,
            'upsell_attach' => 15,
            'menu_completeness' => 15,
            'loyalty_enabled' => 5,
            'feedback_rating' => 10,
            'order_trend' => 5,
        ];

        // AOV (avg_check) from last 30 days paid orders.
        $avgCheck = 0.0;
        $orders30 = 0;
        if (function_exists('revenue_stats_get') && file_exists(__DIR__ . '/revenue_stats.php')) {
            require_once __DIR__ . '/revenue_stats.php';
            $rev = revenue_stats_get($restaurantId, 30);
            $avgCheck = (float)($rev['avg_check'] ?? 0);
            $orders30 = (int)($rev['total_orders'] ?? 0);
        } else {
            $avgCheck = _restaurant_health_avg_check($restaurantId, 30);
            $orders30 = _restaurant_health_orders_count($restaurantId, 30);
        }
        // Conservative scoring by bands to avoid pretending an absolute “good AOV”.
        // Uses only restaurant’s own AOV magnitude as a readiness signal.
        $aovScore = 0;
        if ($orders30 >= 10) {
            if ($avgCheck >= 35) $aovScore = 100;
            elseif ($avgCheck >= 25) $aovScore = 75;
            elseif ($avgCheck >= 15) $aovScore = 50;
            else $aovScore = 25;
        } elseif ($orders30 > 0) {
            $aovScore = 40; // limited data
        } else {
            $aovScore = 0;
        }
        $components['aov'] = _restaurant_health_component($weights['aov'], $aovScore, $avgCheck, $orders30 >= 10 ? 'Avg order value from last 30 days.' : 'Not enough orders for a stable AOV.');

        // Retention: repeat guest rate and inactive guest count (from guest_retention).
        $ret = [];
        if (function_exists('get_retention_stats') && file_exists(__DIR__ . '/guest_retention.php')) {
            require_once __DIR__ . '/guest_retention.php';
            $ret = get_retention_stats($restaurantId);
        }
        $repeatRate = (float)($ret['repeat_rate_pct'] ?? 0);
        $inactiveCount = (int)($ret['inactive_count'] ?? 0);
        $totalGuests = (int)($ret['total_guests'] ?? 0);

        $repeatScore = 0;
        if ($totalGuests >= 20) {
            if ($repeatRate >= 35) $repeatScore = 100;
            elseif ($repeatRate >= 20) $repeatScore = 75;
            elseif ($repeatRate >= 10) $repeatScore = 50;
            else $repeatScore = 25;
        } elseif ($totalGuests > 0) {
            $repeatScore = 40; // limited data
        } else {
            $repeatScore = 0;
        }
        $components['repeat_guest_rate'] = _restaurant_health_component($weights['repeat_guest_rate'], $repeatScore, $repeatRate, $totalGuests >= 20 ? 'Repeat guest rate from tracked guests.' : 'Not enough guest history for a stable rate.');

        // Inactive guests: lower is better. Only meaningful when there is guest history.
        $inactiveScore = 0;
        if ($totalGuests >= 20) {
            if ($inactiveCount <= 5) $inactiveScore = 100;
            elseif ($inactiveCount <= 15) $inactiveScore = 75;
            elseif ($inactiveCount <= 40) $inactiveScore = 50;
            else $inactiveScore = 25;
        } elseif ($totalGuests > 0) {
            $inactiveScore = 50; // neutral under limited data
        } else {
            $inactiveScore = 0;
        }
        $components['inactive_guests'] = _restaurant_health_component($weights['inactive_guests'], $inactiveScore, $inactiveCount, $totalGuests > 0 ? 'Count of guests considered inactive by retention engine.' : 'No guest tracking data yet.');

        // Upsell attach rate (conversion_pct) from upsell analytics, last 30 days.
        $upsellShown = 0;
        $upsellAttachPct = null;
        if (function_exists('dashboard_upsell_summary') && file_exists(__DIR__ . '/dashboard_intel.php')) {
            require_once __DIR__ . '/dashboard_intel.php';
            $us = dashboard_upsell_summary($restaurantId, 30);
            if ($us !== null) {
                $upsellShown = (int)($us['shown'] ?? 0);
                $upsellAttachPct = (float)($us['conversion_pct'] ?? 0);
            }
        } elseif (function_exists('upsell_stats') && file_exists(__DIR__ . '/upsell_analytics.php')) {
            require_once __DIR__ . '/upsell_analytics.php';
            $s = upsell_stats($restaurantId, 30);
            $upsellShown = (int)($s['by_event']['shown'] ?? 0);
            $upsellAttachPct = (float)($s['conversion_pct'] ?? 0);
        }
        $upsellScore = 0;
        $upsellNotes = 'No upsell events yet.';
        if ($upsellShown >= 50 && $upsellAttachPct !== null) {
            if ($upsellAttachPct >= 20) $upsellScore = 100;
            elseif ($upsellAttachPct >= 12) $upsellScore = 75;
            elseif ($upsellAttachPct >= 6) $upsellScore = 50;
            else $upsellScore = 25;
            $upsellNotes = 'Upsell attach rate based on shown vs accepted events (30 days).';
        } elseif ($upsellShown > 0) {
            $upsellScore = 40;
            $upsellNotes = 'Upsell data exists but volume is low for stable scoring.';
        }
        $components['upsell_attach'] = _restaurant_health_component($weights['upsell_attach'], $upsellScore, $upsellAttachPct, $upsellNotes);

        // Menu completeness: items count + photos coverage (if image columns exist).
        $menu = _restaurant_health_menu_completeness($restaurantId);
        $menuScore = 0;
        if ($menu['items'] >= 20 && $menu['photo_coverage_pct'] !== null && $menu['photo_coverage_pct'] >= 70) $menuScore = 100;
        elseif ($menu['items'] >= 10) $menuScore = 75;
        elseif ($menu['items'] >= 5) $menuScore = 50;
        elseif ($menu['items'] >= 1) $menuScore = 25;
        else $menuScore = 0;
        $menuNotes = 'Menu items count' . ($menu['photo_coverage_pct'] !== null ? ' and photo coverage.' : '.');
        $components['menu_completeness'] = _restaurant_health_component($weights['menu_completeness'], $menuScore, $menu, $menuNotes);

        // Loyalty enabled (best-effort): restaurants.loyalty_enabled OR restaurant_loyalty_settings.enabled.
        $loyaltyEnabled = _restaurant_health_loyalty_enabled($restaurantId);
        $components['loyalty_enabled'] = _restaurant_health_component($weights['loyalty_enabled'], $loyaltyEnabled ? 100 : 0, $loyaltyEnabled, $loyaltyEnabled ? 'Loyalty is enabled.' : 'Loyalty is not enabled.');

        // Feedback rating if table exists.
        $feedback = _restaurant_health_feedback($restaurantId);
        $fbScore = 0;
        $fbNotes = 'No feedback data yet.';
        if ($feedback['count'] >= 10 && $feedback['avg_rating'] !== null) {
            if ($feedback['avg_rating'] >= 4.6) $fbScore = 100;
            elseif ($feedback['avg_rating'] >= 4.2) $fbScore = 75;
            elseif ($feedback['avg_rating'] >= 3.8) $fbScore = 50;
            else $fbScore = 25;
            $fbNotes = 'Average guest rating from feedback.';
        } elseif ($feedback['count'] > 0) {
            $fbScore = 50;
            $fbNotes = 'Feedback exists but volume is low.';
        }
        $components['feedback_rating'] = _restaurant_health_component($weights['feedback_rating'], $fbScore, $feedback, $fbNotes);

        // Order volume trend: last 7 days vs previous 7 days (paid orders).
        $trend = _restaurant_health_orders_trend($restaurantId);
        $trendScore = 0;
        if ($trend['last7'] >= 20) {
            if ($trend['delta_pct'] !== null && $trend['delta_pct'] >= 10) $trendScore = 100;
            elseif ($trend['delta_pct'] !== null && $trend['delta_pct'] >= -10) $trendScore = 75;
            elseif ($trend['delta_pct'] !== null && $trend['delta_pct'] >= -30) $trendScore = 50;
            else $trendScore = 25;
        } elseif ($trend['last7'] > 0) {
            $trendScore = 50; // neutral with low volume
        }
        $components['order_trend'] = _restaurant_health_component($weights['order_trend'], $trendScore, $trend, 'Paid order volume trend (last 7 days vs previous 7).');

        // Total points
        $totalPoints = 0;
        foreach ($components as $c) {
            $totalPoints += (int)($c['points'] ?? 0);
        }
        $score = max(0, min(100, (int)round($totalPoints)));
        $label = _restaurant_health_label($score);
        $explanation = 'Score is a weighted summary of measurable signals (orders, retention, upsell usage, menu setup, loyalty, and feedback when available).';

        $result = [
            'total' => ['score' => $score, 'label' => $label, 'explanation' => $explanation],
            'components' => $components,
        ];
        if (function_exists('cache_set')) {
            cache_set($cacheKey, $result, 300); // 5 min
        }
        return $result;
}

/**
 * @return array<int, array{title:string,reason:string,link:string}>
 */
function get_restaurant_health_recommendations(int $restaurantId): array
    {
        if (function_exists('is_demo_mode') && is_demo_mode()) {
            return [
                ['title' => 'Add menu photos to increase conversion', 'reason' => 'Several items have no photos.', 'link' => '/restaurant/menu_items.php'],
                ['title' => 'Enable loyalty to improve retention', 'reason' => 'Loyalty is disabled.', 'link' => '/restaurant/loyalty_settings.php'],
                ['title' => 'Improve upsell rules', 'reason' => 'Upsell attach rate can be improved with better pairs.', 'link' => '/restaurant/upsells.php#optimization'],
            ];
        }

        $b = get_restaurant_health_breakdown($restaurantId);
        $c = $b['components'] ?? [];

        $recs = [];

        $menu = $c['menu_completeness']['value'] ?? null;
        if (is_array($menu)) {
            $items = (int)($menu['items'] ?? 0);
            $cov = $menu['photo_coverage_pct'] ?? null;
            if ($items > 0 && $cov !== null && (float)$cov < 60) {
                $recs[] = ['title' => 'Add menu photos to increase conversion', 'reason' => 'Photo coverage is ' . number_format((float)$cov, 0) . '%.', 'link' => '/restaurant/menu_items.php'];
            } elseif ($items < 5) {
                $recs[] = ['title' => 'Complete your menu', 'reason' => 'Add more available menu items to give guests choice.', 'link' => '/restaurant/menu_items.php'];
            }
        }

        $loyaltyEnabled = (bool)($c['loyalty_enabled']['value'] ?? false);
        if (!$loyaltyEnabled) {
            $recs[] = ['title' => 'Enable loyalty to improve retention', 'reason' => 'Loyalty is currently disabled.', 'link' => '/restaurant/loyalty_settings.php'];
        }

        $upsellShown = 0;
        $upsellAttach = $c['upsell_attach']['value'] ?? null;
        if (is_array($upsellAttach)) {
            // not used
        }
        // We stored attach pct in value; handle null
        $attachPct = $c['upsell_attach']['value'] ?? null;
        $notes = (string)($c['upsell_attach']['notes'] ?? '');
        if (is_numeric($attachPct) && strpos($notes, 'No upsell events') === false) {
            if ((float)$attachPct < 10.0) {
                $recs[] = ['title' => 'Improve upsell rules', 'reason' => 'Upsell attach rate is ' . number_format((float)$attachPct, 1) . '%.', 'link' => '/restaurant/upsells.php#optimization'];
            }
        } else {
            // If no upsell data, recommend setup only if they have orders/menu
            $recs[] = ['title' => 'Set up upsells to raise average check', 'reason' => 'No upsell events tracked yet.', 'link' => '/restaurant/upsells.php'];
        }

        $repeatRate = (float)($c['repeat_guest_rate']['value'] ?? 0);
        if ($repeatRate > 0 && $repeatRate < 15) {
            $recs[] = ['title' => 'Re-engage inactive guests', 'reason' => 'Repeat guest rate is ' . number_format($repeatRate, 1) . '%.', 'link' => '/restaurant/crm.php#comeback-candidates'];
        }
        $inactive = (int)($c['inactive_guests']['value'] ?? 0);
        if ($inactive >= 20) {
            $recs[] = ['title' => 'Re-engage inactive guests', 'reason' => $inactive . ' guests are inactive by your retention settings.', 'link' => '/restaurant/crm.php#comeback-candidates'];
        }

        $trend = $c['order_trend']['value'] ?? null;
        if (is_array($trend) && ($trend['delta_pct'] ?? null) !== null && (float)$trend['delta_pct'] <= -20) {
            $recs[] = ['title' => 'Focus on order volume this week', 'reason' => 'Paid orders are down ' . number_format(abs((float)$trend['delta_pct']), 0) . '% vs previous week.', 'link' => '/restaurant/revenue.php'];
        }

        $fb = $c['feedback_rating']['value'] ?? null;
        if (is_array($fb) && ($fb['avg_rating'] ?? null) !== null && (float)$fb['avg_rating'] < 4.0 && (int)($fb['count'] ?? 0) >= 10) {
            $recs[] = ['title' => 'Review guest feedback', 'reason' => 'Average rating is ' . number_format((float)$fb['avg_rating'], 1) . ' / 5.', 'link' => '/restaurant/feedback.php'];
        }

        // Dedupe by title
        $seen = [];
        $out = [];
        foreach ($recs as $r) {
            $t = (string)($r['title'] ?? '');
            if ($t === '' || isset($seen[$t])) continue;
            $seen[$t] = true;
            $out[] = $r;
        }
        return array_slice($out, 0, 6);
}

function _restaurant_health_component(int $weight, int $score0to100, $value, string $notes): array
{
    $weight = max(0, (int)$weight);
    $score0to100 = max(0, min(100, (int)$score0to100));
    $points = (int)round($weight * ($score0to100 / 100));
    return ['weight' => $weight, 'score' => $score0to100, 'points' => $points, 'value' => $value, 'notes' => $notes];
}

function _restaurant_health_label(int $score): string
{
    if ($score >= 80) return 'Strong';
    if ($score >= 60) return 'Healthy';
    if ($score >= 40) return 'Improving';
    return 'Poor';
}

function _restaurant_health_orders_count(int $restaurantId, int $days): int
{
    if ($restaurantId <= 0 || !function_exists('db')) return 0;
    try {
        $pdo = db();
        $since = date('Y-m-d 00:00:00', strtotime("-{$days} days"));
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE restaurant_id = ? AND created_at >= ? AND payment_status = 'paid' AND order_status <> 'canceled'");
        $stmt->execute([$restaurantId, $since]);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function _restaurant_health_avg_check(int $restaurantId, int $days): float
{
    if ($restaurantId <= 0 || !function_exists('db')) return 0.0;
    try {
        $pdo = db();
        $since = date('Y-m-d 00:00:00', strtotime("-{$days} days"));
        $stmt = $pdo->prepare("SELECT COUNT(*) AS c, COALESCE(SUM(total_price),0) AS s FROM orders WHERE restaurant_id = ? AND created_at >= ? AND payment_status = 'paid' AND order_status <> 'canceled'");
        $stmt->execute([$restaurantId, $since]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $c = (int)($r['c'] ?? 0);
        $s = (float)($r['s'] ?? 0);
        return $c > 0 ? $s / $c : 0.0;
    } catch (Throwable $e) {
        return 0.0;
    }
}

function _restaurant_health_menu_completeness(int $restaurantId): array
{
    $out = ['items' => 0, 'items_with_photo' => null, 'photo_coverage_pct' => null];
    if ($restaurantId <= 0 || !function_exists('db')) return $out;
    if (function_exists('db_table_exists') && !db_table_exists('menu_items')) return $out;
    try {
        $pdo = db();
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM menu_items WHERE restaurant_id = ? AND available = 1");
        $stmt->execute([$restaurantId]);
        $items = (int)$stmt->fetchColumn();
        $out['items'] = $items;

        $hasUrl = function_exists('db_column_exists') && db_column_exists('menu_items', 'image_url');
        $hasPath = function_exists('db_column_exists') && db_column_exists('menu_items', 'image_path');
        if ($items > 0 && ($hasUrl || $hasPath)) {
            $conds = [];
            if ($hasUrl) $conds[] = "TRIM(COALESCE(image_url,'')) <> ''";
            if ($hasPath) $conds[] = "TRIM(COALESCE(image_path,'')) <> ''";
            $cond = '(' . implode(' OR ', $conds) . ')';
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM menu_items WHERE restaurant_id = ? AND available = 1 AND {$cond}");
            $stmt->execute([$restaurantId]);
            $with = (int)$stmt->fetchColumn();
            $out['items_with_photo'] = $with;
            $out['photo_coverage_pct'] = round(100.0 * $with / max(1, $items), 1);
        }
    } catch (Throwable $e) {
        return $out;
    }
    return $out;
}

function _restaurant_health_loyalty_enabled(int $restaurantId): bool
{
    if ($restaurantId <= 0 || !function_exists('db')) return false;
    try {
        $pdo = db();
        // Prefer restaurant_loyalty_settings.enabled if exists
        if (function_exists('db_table_exists') && db_table_exists('restaurant_loyalty_settings')) {
            $hasEnabled = function_exists('db_column_exists') && db_column_exists('restaurant_loyalty_settings', 'enabled');
            if ($hasEnabled) {
                $stmt = $pdo->prepare("SELECT enabled FROM restaurant_loyalty_settings WHERE restaurant_id = ? LIMIT 1");
                $stmt->execute([$restaurantId]);
                $v = $stmt->fetchColumn();
                if ($v !== false && $v !== null) {
                    return (int)$v === 1;
                }
            }
        }
        // Fallback to restaurants.loyalty_enabled if column exists
        if (function_exists('db_table_exists') && db_table_exists('restaurants') && function_exists('db_column_exists') && db_column_exists('restaurants', 'loyalty_enabled')) {
            $stmt = $pdo->prepare("SELECT loyalty_enabled FROM restaurants WHERE id = ? LIMIT 1");
            $stmt->execute([$restaurantId]);
            return (int)($stmt->fetchColumn() ?: 0) === 1;
        }
    } catch (Throwable $e) {
        return false;
    }
    return false;
}

function _restaurant_health_feedback(int $restaurantId): array
{
    $out = ['avg_rating' => null, 'count' => 0];
    if ($restaurantId <= 0 || !function_exists('db')) return $out;
    if (function_exists('db_table_exists') && !db_table_exists('order_feedback')) return $out;
    try {
        $pdo = db();
        $stmt = $pdo->prepare("SELECT COUNT(*) AS cnt, AVG(rating) AS avg_rating FROM order_feedback WHERE restaurant_id = ?");
        $stmt->execute([$restaurantId]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $out['count'] = (int)($r['cnt'] ?? 0);
        $out['avg_rating'] = ($r['avg_rating'] ?? null) !== null ? round((float)$r['avg_rating'], 2) : null;
        return $out;
    } catch (Throwable $e) {
        return $out;
    }
}

function _restaurant_health_orders_trend(int $restaurantId): array
{
    $out = ['last7' => 0, 'prev7' => 0, 'delta_pct' => null];
    if ($restaurantId <= 0 || !function_exists('db')) return $out;
    try {
        $pdo = db();
        $since14 = date('Y-m-d 00:00:00', strtotime('-14 days'));
        $stmt = $pdo->prepare("
            SELECT DATE(created_at) AS d, COUNT(*) AS cnt
            FROM orders
            WHERE restaurant_id = ? AND created_at >= ?
              AND payment_status = 'paid' AND order_status <> 'canceled'
            GROUP BY DATE(created_at)
        ");
        $stmt->execute([$restaurantId, $since14]);
        $byDay = [];
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $byDay[(string)$r['d']] = (int)$r['cnt'];
        }
        $last7 = 0;
        $prev7 = 0;
        for ($i = 0; $i < 14; $i++) {
            $d = date('Y-m-d', strtotime("-{$i} days"));
            $cnt = (int)($byDay[$d] ?? 0);
            if ($i < 7) $last7 += $cnt; else $prev7 += $cnt;
        }
        $out['last7'] = $last7;
        $out['prev7'] = $prev7;
        if ($prev7 > 0) {
            $out['delta_pct'] = round(100.0 * ($last7 - $prev7) / $prev7, 1);
        }
        return $out;
    } catch (Throwable $e) {
        return $out;
    }
}

function _restaurant_health_demo_breakdown(): array
{
    return [
        'total' => ['score' => 78, 'label' => 'Стабильно', 'explanation' => 'Сводка по заказам, удержанию, допродажам и меню (демо-данные).'],
        'components' => [
            'aov' => ['weight' => 20, 'score' => 75, 'points' => 15, 'value' => 1680.0, 'notes' => 'Средний чек за 30 дней (демо).'],
            'repeat_guest_rate' => ['weight' => 20, 'score' => 75, 'points' => 15, 'value' => 22.5, 'notes' => 'Доля возвращающихся гостей (демо).'],
            'inactive_guests' => ['weight' => 10, 'score' => 50, 'points' => 5, 'value' => 8, 'notes' => 'Неактивные гости по порогу 14 дней.'],
            'upsell_attach' => ['weight' => 15, 'score' => 75, 'points' => 11, 'value' => 14.2, 'notes' => 'Доля заказов с допродажей (30 дней, демо).'],
            'menu_completeness' => ['weight' => 15, 'score' => 85, 'points' => 13, 'value' => ['items' => 15, 'items_with_photo' => 15, 'photo_coverage_pct' => 100.0], 'notes' => 'Позиции в меню и фото (в демо все блюда с изображениями).'],
            'loyalty_enabled' => ['weight' => 5, 'score' => 0, 'points' => 0, 'value' => false, 'notes' => 'Программа лояльности не включена.'],
            'feedback_rating' => ['weight' => 10, 'score' => 75, 'points' => 8, 'value' => ['avg_rating' => 4.6, 'count' => 24], 'notes' => 'Средняя оценка из отзывов (демо).'],
            'order_trend' => ['weight' => 5, 'score' => 75, 'points' => 4, 'value' => ['last7' => 186, 'prev7' => 168, 'delta_pct' => 10.7], 'notes' => 'Динамика оплаченных заказов.'],
        ],
    ];
}

