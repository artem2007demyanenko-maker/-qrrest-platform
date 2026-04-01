<?php
/**
 * Guest Return Engine: identify inactive guests and suggest comeback opportunities.
 * Uses CRM-based retention model only (guest_retention.php → crm_guests + crm_visits).
 * Same inactivity threshold as retention stats and return candidates (INACTIVITY_DAYS = 14).
 * Draft/suggestion-based only. No auto-send. No external providers.
 */

if (!function_exists('get_inactive_guests') || !function_exists('guest_retention_inactive_days')) {
    if (file_exists(__DIR__ . '/guest_retention.php')) {
        require_once __DIR__ . '/guest_retention.php';
    }
}

if (!function_exists('get_guest_return_candidates')) {
    /**
     * Return candidates: inactive INACTIVITY_DAYS+ (same as retention), visits_count >= 2, with score and suggested offer/message.
     * Data from CRM via guest_retention only. No separate threshold or query.
     *
     * @return array<int, array{guest_contact: string, guest_name: ?string, last_visit: string, visits_count: int, avg_check: float, score: int, suggested_offer: string, suggested_message: string, segment: string}>
     */
    function get_guest_return_candidates(int $restaurantId, int $limit = 50): array
    {
        $restaurantId = (int) $restaurantId;
        $limit = max(1, min(200, $limit));

        if (function_exists('is_demo_mode') && is_demo_mode()) {
            return _guest_return_engine_demo_candidates();
        }

        if (!function_exists('get_inactive_guests') || !function_exists('guest_retention_inactive_days')) {
            return [];
        }

        $poolLimit = max($limit, 200);
        $inactive = get_inactive_guests($restaurantId, guest_retention_inactive_days(), $poolLimit);
        $candidates = [];
        foreach ($inactive as $row) {
            $visitsCount = (int) ($row['visits_count'] ?? 0);
            if ($visitsCount < 2) {
                continue;
            }
            $inactivityDays = (int) ($row['inactivity_days'] ?? 0);
            $lastVisit = (string) ($row['last_visit'] ?? '');
            $avgCheck = 0.0;

            $score = _guest_return_score($visitsCount, $avgCheck, $inactivityDays);
            $segment = _guest_return_segment($visitsCount, $avgCheck);
            $offer = _guest_return_suggested_offer($segment);
            $message = _guest_return_suggested_message($segment, $offer);

            $candidates[] = [
                'guest_contact'      => (string) ($row['guest_contact'] ?? ''),
                'guest_name'         => isset($row['guest_name']) && trim((string) $row['guest_name']) !== '' ? trim((string) $row['guest_name']) : null,
                'last_visit'         => $lastVisit,
                'visits_count'       => $visitsCount,
                'avg_check'          => $avgCheck,
                'score'              => $score,
                'suggested_offer'    => $offer,
                'suggested_message'  => $message,
                'segment'            => $segment,
            ];
            if (count($candidates) >= $limit) {
                break;
            }
        }

        usort($candidates, function ($a, $b) {
            return ($b['score'] ?? 0) - ($a['score'] ?? 0);
        });

        return array_slice($candidates, 0, $limit);
    }
}

if (!function_exists('get_guest_return_summary')) {
    /**
     * Summary: count of candidates and headline. Uses same threshold as retention (INACTIVITY_DAYS).
     *
     * @return array{candidates_count: int, inactive_days_min: int, headline: string}
     */
    function get_guest_return_summary(int $restaurantId): array
    {
        $inactiveDaysMin = function_exists('guest_retention_inactive_days') ? guest_retention_inactive_days() : (defined('INACTIVITY_DAYS') ? (int) INACTIVITY_DAYS : 14);
        $candidates = get_guest_return_candidates($restaurantId, 500);
        $count = count($candidates);
        $headline = $count > 0
            ? "We found {$count} guest(s) who have not returned in {$inactiveDaysMin}+ days."
            : 'No high-value inactive guests to re-engage right now.';
        return [
            'candidates_count'   => $count,
            'inactive_days_min' => $inactiveDaysMin,
            'headline'           => $headline,
        ];
    }
}

if (!function_exists('estimate_recovered_revenue')) {
    /**
     * Conservative estimate: candidates × assumed avg_check × assumed return rate (10%).
     * Candidates from same CRM/retention source; no cross-tenant data.
     */
    function estimate_recovered_revenue(int $restaurantId): float
    {
        $candidates = get_guest_return_candidates($restaurantId, 200);
        if (empty($candidates)) {
            return 0.0;
        }
        $totalAvg = 0.0;
        foreach ($candidates as $c) {
            $totalAvg += (float) ($c['avg_check'] ?? 0);
        }
        $avgPerGuest = count($candidates) > 0 ? $totalAvg / count($candidates) : 0;
        $assumedReturnRate = 0.10;
        return round($avgPerGuest * count($candidates) * $assumedReturnRate, 2);
    }
}

function _guest_return_score(int $visitsCount, float $avgCheck, int $inactivityDays): int
{
    $score = 30;
    $score += min(30, $visitsCount * 5);
    $score += min(25, (int) ($avgCheck / 5));
    $score -= min(20, (int) ($inactivityDays / 7) * 2);
    return max(1, min(100, $score));
}

function _guest_return_segment(int $visitsCount, float $avgCheck): string
{
    $highSpend = $avgCheck >= 25;
    $loyal = $visitsCount >= 5;
    if ($highSpend && $loyal) {
        return 'high_value_loyal';
    }
    if ($highSpend) {
        return 'high_value';
    }
    if ($loyal) {
        return 'loyal';
    }
    return 'inactive_repeat';
}

function _guest_return_suggested_offer(string $segment): string
{
    switch ($segment) {
        case 'high_value_loyal':
            return 'Free dessert on your next visit';
        case 'high_value':
            return '10% off next visit';
        case 'loyal':
            return 'Free drink on your next visit';
        default:
            return '10% off next visit';
    }
}

function _guest_return_suggested_message(string $segment, string $offer): string
{
    $base = "We miss you! ";
    switch ($segment) {
        case 'high_value_loyal':
            return $base . "As a valued guest, enjoy {$offer}. We'd love to see you again.";
        case 'high_value':
            return $base . "Bring this offer back: {$offer}.";
        case 'loyal':
            return $base . "Thank you for visiting us often. {$offer} when you return.";
        default:
            return $base . "Come back this week and enjoy {$offer}.";
    }
}

function _guest_return_engine_demo_candidates(): array
{
    $inactiveDays = defined('INACTIVITY_DAYS') ? (int) INACTIVITY_DAYS : 14;
    return [
        [
            'guest_contact'     => '+7 916 100-42-18',
            'guest_name'        => 'Ирина В.',
            'last_visit'        => date('Y-m-d H:i:s', strtotime('-' . ($inactiveDays + 2) . ' days')),
            'visits_count'      => 5,
            'avg_check'         => 3200.00,
            'score'             => 78,
            'suggested_offer'   => 'Десерт в подарок при следующем визите',
            'suggested_message' => 'Скучаем по вам в «Севере»! Как постоянному гостю — десерт в подарок при брони на этой неделе.',
            'segment'           => 'high_value_loyal',
        ],
        [
            'guest_contact'     => '+7 903 221-55-90',
            'guest_name'        => null,
            'last_visit'        => date('Y-m-d H:i:s', strtotime('-' . ($inactiveDays + 1) . ' days')),
            'visits_count'      => 3,
            'avg_check'         => 2850.00,
            'score'             => 65,
            'suggested_offer'   => 'Скидка 10% на следующий визит',
            'suggested_message' => 'Давно вас не было! Покажите это сообщение — скидка 10% на заказ.',
            'segment'           => 'high_value',
        ],
        [
            'guest_contact'     => '+7 912 555-01-22',
            'guest_name'        => 'Елена',
            'last_visit'        => date('Y-m-d H:i:s', strtotime('-' . $inactiveDays . ' days')),
            'visits_count'      => 6,
            'avg_check'         => 1680.00,
            'score'             => 62,
            'suggested_offer'   => 'Напиток в подарок к основному блюду',
            'suggested_message' => 'Спасибо, что выбирали нас раньше. Приходите снова — напиток к основному в подарок.',
            'segment'           => 'loyal',
        ],
    ];
}
