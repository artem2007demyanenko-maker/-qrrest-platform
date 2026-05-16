<?php
/**
 * Smart dashboard: extra data for KPIs, insights, trend, CRM, upsell, recommended actions.
 * All functions use try/catch and return safe defaults so dashboard never 500s.
 */

if (!function_exists('dashboard_revenue_trend')) {
    /**
     * Revenue per day for last 7 days (for chart). Keys = date Y-m-d, values = total revenue.
     * @return array<string, float>
     */
    function dashboard_revenue_trend(int $restaurantId, int $days = 7): array
    {
        if ($restaurantId <= 0) {
            return [];
        }
        try {
            $pdo = db();
            $since = date('Y-m-d 00:00:00', strtotime("-{$days} days"));
            $stmt = $pdo->prepare("
                SELECT DATE(created_at) AS d, COALESCE(SUM(total_price), 0) AS rev
                FROM orders
                WHERE restaurant_id = ? AND created_at >= ?
                  AND payment_status = 'paid' AND order_status <> 'canceled'
                GROUP BY DATE(created_at)
                ORDER BY d
            ");
            $stmt->execute([$restaurantId, $since]);
            $out = [];
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $out[$r['d']] = (float)$r['rev'];
            }
            for ($i = $days - 1; $i >= 0; $i--) {
                $d = date('Y-m-d', strtotime("-{$i} days"));
                if (!isset($out[$d])) {
                    $out[$d] = 0.0;
                }
            }
            ksort($out);
            return array_slice($out, -$days, $days, true);
        } catch (Throwable $e) {
            error_log('dashboard_revenue_trend ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('dashboard_yesterday_stats')) {
    /**
     * @return array{revenue: float, orders_count: int}|null
     */
    function dashboard_yesterday_stats(int $restaurantId): ?array
    {
        if ($restaurantId <= 0) {
            return null;
        }
        try {
            $pdo = db();
            $start = date('Y-m-d 00:00:00', strtotime('-1 day'));
            $end   = date('Y-m-d 23:59:59', strtotime('-1 day'));
            $stmt = $pdo->prepare("
                SELECT COALESCE(SUM(total_price), 0) AS revenue, COUNT(*) AS orders_count
                FROM orders
                WHERE restaurant_id = ? AND created_at BETWEEN ? AND ?
                  AND payment_status = 'paid' AND order_status <> 'canceled'
            ");
            $stmt->execute([$restaurantId, $start, $end]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ? ['revenue' => (float)$row['revenue'], 'orders_count' => (int)$row['orders_count']] : null;
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('dashboard_guests_today')) {
    /**
     * Unique guests today. Uses guest_id if column exists, else returns null (caller can show "—" or orders count).
     */
    function dashboard_guests_today(int $restaurantId, string $todayStart, string $todayEnd): ?int
    {
        if ($restaurantId <= 0) {
            return null;
        }
        try {
            $pdo = db();
            if (function_exists('db_column_exists') && !db_column_exists('orders', 'guest_id')) {
                return null;
            }
            $stmt = $pdo->prepare("
                SELECT COUNT(DISTINCT guest_id) AS cnt
                FROM orders
                WHERE restaurant_id = ? AND created_at BETWEEN ? AND ?
                  AND guest_id IS NOT NULL AND guest_id > 0
            ");
            $stmt->execute([$restaurantId, $todayStart, $todayEnd]);
            return (int)$stmt->fetchColumn();
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('dashboard_crm_summary')) {
    /**
     * @return array{returning_count: int, pending_messages: int, has_campaigns: bool}|null null if CRM unavailable
     */
    function dashboard_crm_summary(int $restaurantId): ?array
    {
        if ($restaurantId <= 0) {
            return null;
        }
        try {
            if (!function_exists('db') || !function_exists('db_table_exists') || !db_table_exists('crm_guests')) {
                return null;
            }
            if (!function_exists('get_retention_stats') && file_exists(__DIR__ . '/guest_retention.php')) {
                require_once __DIR__ . '/guest_retention.php';
            }
            $retention = function_exists('get_retention_stats') ? get_retention_stats($restaurantId) : null;
            $returning = (int)($retention['repeat_guests'] ?? 0);

            // Read path must be pure: do NOT process CRM outbox here.
            $pdo = db();
            $pending = 0;
            if (function_exists('db_table_exists') && db_table_exists('crm_outbox')) {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM crm_outbox WHERE restaurant_id = ? AND status = 'pending'");
                $stmt->execute([$restaurantId]);
                $pending = (int)$stmt->fetchColumn();
            }

            $hasCampaigns = false;
            if (db_table_exists('crm_campaigns')) {
                $stmt = $pdo->prepare("SELECT 1 FROM crm_campaigns WHERE restaurant_id = ? AND active = 1 LIMIT 1");
                $stmt->execute([$restaurantId]);
                $hasCampaigns = (bool)$stmt->fetchColumn();
            }

            return ['returning_count' => $returning, 'pending_messages' => $pending, 'has_campaigns' => $hasCampaigns];
        } catch (Throwable $e) {
            error_log('dashboard_crm_summary ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('dashboard_upsell_summary')) {
    /**
     * @return array{shown: int, add_clicks: int, accepted: int, conversion_pct: float}|null
     */
    function dashboard_upsell_summary(int $restaurantId, int $days = 7): ?array
    {
        if ($restaurantId <= 0) {
            return null;
        }
        try {
            require_once __DIR__ . '/upsell_analytics.php';
            if (!function_exists('upsell_stats')) {
                return null;
            }
            $s = upsell_stats($restaurantId, $days);
            $be = $s['by_event'] ?? [];
            return [
                'shown'          => (int)($be['shown'] ?? 0),
                'add_clicks'     => (int)($be['add_click'] ?? 0),
                'accepted'       => (int)($be['accepted_in_order'] ?? 0),
                'conversion_pct' => (float)($s['conversion_pct'] ?? 0),
            ];
        } catch (Throwable $e) {
            error_log('dashboard_upsell_summary ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('dashboard_insights')) {
    /**
     * Up to 3 insight strings from available data. Empty array if nothing.
     * @param array $todayStats { total_revenue, orders_count }
     * @param array|null $yesterdayStats { revenue, orders_count }
     * @param array|null $crmSummary
     * @param array|null $upsellSummary
     * @param array $topItems
     * @param bool $obComplete onboarding complete
     */
    function dashboard_insights(
        array $todayStats,
        ?array $yesterdayStats,
        ?array $crmSummary,
        ?array $upsellSummary,
        array $topItems,
        bool $obComplete
    ): array {
        $insights = [];
        $ordersToday = (int)($todayStats['orders_count'] ?? 0);
        $revenueToday = (float)($todayStats['total_revenue'] ?? 0);
        $avgToday = $ordersToday > 0 ? $revenueToday / $ordersToday : 0;

        if ($yesterdayStats !== null) {
            $revYesterday = (float)($yesterdayStats['revenue'] ?? 0);
            $ordYesterday = (int)($yesterdayStats['orders_count'] ?? 0);
            $avgYesterday = $ordYesterday > 0 ? $revYesterday / $ordYesterday : 0;
            if ($avgYesterday > 0 && $avgToday > 0) {
                $pct = round(100 * ($avgToday - $avgYesterday) / $avgYesterday);
                if ($pct > 0) {
                    $insights[] = "Average check increased by {$pct}% compared to yesterday.";
                } elseif ($pct < 0) {
                    $insights[] = "Average check is " . abs($pct) . "% lower than yesterday.";
                }
            }
        }

        if ($crmSummary !== null) {
            $ret = (int)($crmSummary['returning_count'] ?? 0);
            if ($ret === 0 && $obComplete) {
                $insights[] = "You have no returning guests yet — enable CRM reminders to bring them back.";
            }
            $pending = (int)($crmSummary['pending_messages'] ?? 0);
            if ($pending > 0) {
                $insights[] = "{$pending} CRM message(s) pending to be sent.";
            }
        }

        if ($upsellSummary !== null) {
            $clicks = (int)($upsellSummary['add_clicks'] ?? 0);
            if ($clicks > 0) {
                $insights[] = "Upsell suggestions generated {$clicks} add clicks this week.";
            }
        }

        if (empty($topItems) && $obComplete) {
            $insights[] = "No menu items in top yet — consider adding more dishes or promoting bestsellers.";
        }

        return array_slice($insights, 0, 3);
    }
}

if (!function_exists('dashboard_has_upsell_rules')) {
    function dashboard_has_upsell_rules(int $restaurantId): bool
    {
        if ($restaurantId <= 0) {
            return false;
        }
        try {
            if (function_exists('db_table_exists') && db_table_exists('upsell_rules')) {
                $pdo = db();
                $stmt = $pdo->prepare("SELECT 1 FROM upsell_rules WHERE restaurant_id = ? LIMIT 1");
                $stmt->execute([$restaurantId]);
                return (bool)$stmt->fetchColumn();
            }
            if (function_exists('db_table_exists') && db_table_exists('menu_item_upsells')) {
                $pdo = db();
                $stmt = $pdo->prepare("SELECT 1 FROM menu_item_upsells WHERE restaurant_id = ? LIMIT 1");
                $stmt->execute([$restaurantId]);
                return (bool)$stmt->fetchColumn();
            }
        } catch (Throwable $e) {
            return false;
        }
        return false;
    }
}

if (!function_exists('dashboard_recommended_actions')) {
    /**
     * 2–4 recommended actions. Demo mode returns explore-style actions.
     * @return list<array{label: string, url: string}>
     */
    function dashboard_recommended_actions(
        bool $isDemo,
        int $restId,
        array $onboardingProgress,
        array $trialInfo,
        ?array $crmSummary,
        bool $hasUpsellRules
    ): array {
        if ($isDemo) {
            return [
                ['label' => 'Explore CRM', 'url' => '/restaurant/crm.php'],
                ['label' => 'Explore revenue', 'url' => '/restaurant/revenue.php'],
                ['label' => 'Explore QR menu', 'url' => '/qr.php?table_id=1'],
                ['label' => 'Waiter order (POS)', 'url' => '/staff/pos.php'],
                ['label' => 'Kitchen display', 'url' => '/staff/kitchen.php'],
            ];
        }

        $actions = [];
        $completed = $onboardingProgress['completed_steps'] ?? [];
        $nextStep = $onboardingProgress['next_step'] ?? null;

        if (!in_array('setup_restaurant', $completed, true)) {
            $actions[] = ['label' => 'Finish restaurant setup', 'url' => '/restaurant/setup.php'];
        }
        if (!in_array('add_menu_items', $completed, true)) {
            $actions[] = ['label' => 'Add more menu items', 'url' => '/restaurant/menu_items.php'];
        }
        if (!in_array('create_tables', $completed, true)) {
            $actions[] = ['label' => 'Create tables', 'url' => '/restaurant/tables.php'];
        }
        if (!in_array('print_qr', $completed, true)) {
            $actions[] = ['label' => 'Print QR codes for tables', 'url' => '/restaurant/qr_print.php'];
        }
        if ($crmSummary !== null && !($crmSummary['has_campaigns'] ?? false)) {
            $actions[] = ['label' => 'Create your first CRM campaign', 'url' => '/restaurant/crm_campaigns.php'];
        }
        if ($nextStep === null && !empty($completed)) {
            $actions[] = ['label' => 'Review your top dishes', 'url' => '/restaurant/dashboard.php#top-dishes'];
        }
        if (!$hasUpsellRules) {
            $actions[] = ['label' => 'Set up upsell rules', 'url' => '/restaurant/upsell_rules.php'];
        }
        if ($trialInfo['is_expired'] ?? false) {
            $actions[] = ['label' => 'Activate subscription', 'url' => '/restaurant/activate.php'];
        }
        $actions[] = ['label' => 'Waiter order (POS)', 'url' => '/staff/pos.php'];
        $actions[] = ['label' => 'Kitchen display', 'url' => '/staff/kitchen.php'];

        return array_slice(array_values($actions), 0, 6);
    }
}
