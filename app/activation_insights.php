<?php
/**
 * Activation & upgrade readiness (read-only).
 *
 * Uses:
 * - restaurant_subscriptions via get_restaurant_plan()
 * - activation milestones via get_restaurant_onboarding_state()
 * - usage metrics via get_monthly_usage_snapshot()
 * - orders truth via paid/non-canceled orders query (when schema allows)
 *
 * No writes, no background jobs.
 */

if (!function_exists('get_restaurant_activation_snapshot')) {
    /**
     * @param int $restaurantId
     * @return array{
     *   restaurant_id:int,
     *   activation_status:string,
     *   activation_score:int,
     *   core_launch_complete:bool,
     *   current_plan:string, // FREE|GROWTH|PRO
     *   orders_this_month:int,
     *   orders_limit:?int,
     *   orders_percent:int,
     *   first_paid_order:bool,
     *   crm_messages:int,
     *   upsell_shown:int,
     *   upsell_accepted:int,
     *   loyalty_transactions:int,
     * }
     */
    function get_restaurant_activation_snapshot(int $restaurantId): array
    {
        $restaurantId = (int)$restaurantId;
        if ($restaurantId <= 0) {
            return [
                'restaurant_id' => 0,
                'activation_status' => 'Старт',
                'activation_score' => 0,
                'core_launch_complete' => false,
                'current_plan' => 'FREE',
                'orders_this_month' => 0,
                'orders_limit' => null,
                'orders_percent' => 0,
                'first_paid_order' => false,
                'crm_messages' => 0,
                'upsell_shown' => 0,
                'upsell_accepted' => 0,
                'loyalty_transactions' => 0,
            ];
        }

        // Ensure dependencies are available (best-effort).
        if (file_exists(__DIR__ . '/subscription_plans.php')) {
            require_once __DIR__ . '/subscription_plans.php';
        }
        if (file_exists(__DIR__ . '/onboarding.php')) {
            require_once __DIR__ . '/onboarding.php';
        }

        $planData = function_exists('get_restaurant_plan') ? get_restaurant_plan($restaurantId) : ['plan' => 'free', 'status' => 'active'];
        $planKey = strtolower((string)($planData['plan'] ?? 'free'));
        if (!in_array($planKey, ['free', 'growth', 'pro'], true)) {
            $planKey = 'free';
        }
        $currentPlan = strtoupper($planKey);

        $state = function_exists('get_restaurant_onboarding_state') ? get_restaurant_onboarding_state($restaurantId) : null;
        $activationStatus = (string)($state['status'] ?? 'Старт');
        $activationScore = (int)($state['score'] ?? 0);
        $completed = is_array($state['completed_steps'] ?? null) ? ($state['completed_steps'] ?? []) : [];

        // Core launch completeness for readiness must not rely on session-only QR page visits.
        // We only consider stable facts: menu + tables + first paid/non-canceled order.
        $core_launch_complete = in_array('menu_created', $completed, true)
            && in_array('first_table_created', $completed, true)
            && in_array('first_order_received', $completed, true);

        // Usage metrics (for premium usage proof).
        $usage = function_exists('get_monthly_usage_snapshot') ? get_monthly_usage_snapshot($restaurantId) : [
            'orders' => 0,
            'crm_messages' => 0,
            'upsell_shown' => 0,
            'upsell_accepted' => 0,
            'loyalty_transactions' => 0,
        ];
        $crmMessages = (int)($usage['crm_messages'] ?? 0);
        $upsellShown = (int)($usage['upsell_shown'] ?? 0);
        $upsellAccepted = (int)($usage['upsell_accepted'] ?? 0);
        $loyaltyTx = (int)($usage['loyalty_transactions'] ?? 0);

        // Paid-order truth for readiness must be computed from orders table, not from usage_metrics.
        $ordersThisMonth = 0;
        if (function_exists('get_paid_orders_this_month')) {
            $ordersThisMonth = (int)get_paid_orders_this_month($restaurantId);
        }

        // Orders limit derived from plan config (for display only).
        $ordersLimit = null;
        $ordersPercent = 0;
        if (function_exists('_subscription_plans_config') && function_exists('get_restaurant_plan')) {
            $cfg = _subscription_plans_config();
            if (isset($cfg[$planKey]['max_orders'])) {
                $limit = $cfg[$planKey]['max_orders'];
                if ($limit === null) {
                    $ordersLimit = null;
                } else {
                    $ordersLimit = (int)$limit;
                }
            }
        }
        if ($ordersLimit !== null && $ordersLimit > 0) {
            $ordersPercent = (int)round($ordersThisMonth * 100.0 / $ordersLimit);
        }

        // Honest first_paid_order check from orders table (schema-safe).
        $firstPaidOrder = false;
        if (function_exists('db') && function_exists('db_table_exists') && db_table_exists('orders')) {
            try {
                $pdo = db();
                if (function_exists('db_column_exists') && db_column_exists('orders', 'payment_status') && db_column_exists('orders', 'order_status')) {
                    $stmt = $pdo->prepare("SELECT 1 FROM orders WHERE restaurant_id = ? AND payment_status = 'paid' AND order_status <> 'canceled' LIMIT 1");
                    $stmt->execute([$restaurantId]);
                    $firstPaidOrder = (bool)$stmt->fetchColumn();
                } elseif (function_exists('db_column_exists') && db_column_exists('orders', 'payment_status')) {
                    $stmt = $pdo->prepare("SELECT 1 FROM orders WHERE restaurant_id = ? AND payment_status = 'paid' LIMIT 1");
                    $stmt->execute([$restaurantId]);
                    $firstPaidOrder = (bool)$stmt->fetchColumn();
                } else {
                    // Fallback: do not assume paid; require at least one order row.
                    $stmt = $pdo->prepare("SELECT 1 FROM orders WHERE restaurant_id = ? LIMIT 1");
                    $stmt->execute([$restaurantId]);
                    $firstPaidOrder = (bool)$stmt->fetchColumn();
                }
            } catch (Throwable $e) {
                $firstPaidOrder = false;
            }
        }

        return [
            'restaurant_id' => $restaurantId,
            'activation_status' => $activationStatus,
            'activation_score' => $activationScore,
            'core_launch_complete' => (bool)$core_launch_complete,
            'current_plan' => $currentPlan,
            'orders_this_month' => $ordersThisMonth,
            'orders_limit' => $ordersLimit,
            'orders_percent' => $ordersPercent,
            'first_paid_order' => (bool)$firstPaidOrder,
            'crm_messages' => $crmMessages,
            'upsell_shown' => $upsellShown,
            'upsell_accepted' => $upsellAccepted,
            'loyalty_transactions' => $loyaltyTx,
        ];
    }
}

if (!function_exists('get_upgrade_readiness_snapshot')) {
    /**
     * @param int $restaurantId
     * @return array{readiness:string, cta_plan_code:?string, recommendation:string}
     */
    function get_upgrade_readiness_snapshot(int $restaurantId): array
    {
        $snap = get_restaurant_activation_snapshot($restaurantId);
        $plan = strtoupper($snap['current_plan'] ?? 'FREE');

        // Ensure feature flags helpers exist.
        $crmEnabled = function_exists('check_feature') ? check_feature($restaurantId, 'crm_enabled') : false;
        $upsellEnabled = function_exists('check_feature') ? check_feature($restaurantId, 'upsell_enabled') : false;
        $loyaltyEnabled = function_exists('check_feature') ? check_feature($restaurantId, 'loyalty_enabled') : false;

        // Readiness must rely on stable paid-order truth for traction, not on session-scoped milestones.
        $meaningfulOrders = (bool)($snap['first_paid_order'] ?? false) && (int)($snap['orders_this_month'] ?? 0) >= 1;
        $hasPremiumUsage = ((int)($snap['crm_messages'] ?? 0) > 0) || ((int)($snap['upsell_shown'] ?? 0) > 0) || ((int)($snap['upsell_accepted'] ?? 0) > 0);
        $coreLaunch = (bool)($snap['core_launch_complete'] ?? false);

        $result = [
            'readiness' => 'not_ready',
            'cta_plan_code' => null,
            'reason' => 'Пока рано для уверенного апгрейда.',
        ];

        // FREE -> ready for GROWTH
        if ($plan === 'FREE') {
            // For honesty: only recommend when CRM/Upsell are currently unavailable on effective plan.
            $crmUpsellLocked = (!$crmEnabled && !$upsellEnabled);
            if ($meaningfulOrders && $coreLaunch && $crmUpsellLocked) {
                $result['readiness'] = 'ready_for_growth';
                $result['cta_plan_code'] = 'growth';
                $result['reason'] = 'Есть первый оплаченный заказ и активность по заказам; CRM и upsell сейчас недоступны — логично перейти на GROWTH.';
            } else {
                // keep it short and grounded
                if (empty($snap['first_paid_order'])) {
                    $result['reason'] = 'Пока нет первого оплаченного заказа.';
                } elseif (!$coreLaunch) {
                    $result['reason'] = 'Завершите базовую активацию ресторана (меню, столы, первый заказ).';
                } elseif (!$crmUpsellLocked) {
                    $result['reason'] = 'CRM или upsell уже доступны на текущем тарифе.';
                } else {
                    $result['reason'] = 'Когда будет больше активности по заказам, GROWTH станет ещё уместнее.';
                }
            }
        }

        // GROWTH -> ready for PRO
        if ($plan === 'GROWTH') {
            if ($coreLaunch && $meaningfulOrders && $hasPremiumUsage && !$loyaltyEnabled) {
                $result['readiness'] = 'ready_for_pro';
                $result['cta_plan_code'] = 'pro';
                $result['reason'] = 'CRM/upsell уже показывают активность; loyalty сейчас недоступна — PRO логично усилит удержание.';
            } else {
                if ($loyaltyEnabled) {
                    $result['reason'] = 'Loyalty уже доступна на текущем тарифе.';
                } elseif (!$meaningfulOrders) {
                    $result['reason'] = 'Нужна активность по оплаченных заказам.';
                } elseif (!$coreLaunch) {
                    $result['reason'] = 'Завершите базовую активацию ресторана, чтобы PRO был наиболее уместен.';
                } elseif (!$hasPremiumUsage) {
                    $result['reason'] = 'Сначала включите/наберите активность CRM или upsell.';
                } else {
                    $result['reason'] = 'PRO станет логичным следующим шагом после накопления активности.';
                }
            }
        }

        // PRO -> no upgrade push
        if ($plan === 'PRO') {
            $result['readiness'] = 'not_ready';
            $result['cta_plan_code'] = null;
            $result['reason'] = 'Полный набор инструментов уже доступен на PRO.';
        }

        return $result;
    }
}

if (!function_exists('get_paid_orders_this_month')) {
    /**
     * Canonical paid-order truth for this month.
     * IMPORTANT: readiness uses this, not usage_metrics.orders.
     */
    function get_paid_orders_this_month(int $restaurantId): int
    {
        $restaurantId = (int)$restaurantId;
        if ($restaurantId <= 0) {
            return 0;
        }
        if (!function_exists('db') || !function_exists('db_table_exists') || !db_table_exists('orders')) {
            return 0;
        }

        try {
            $pdo = db();
            $periodStart = date('Y-m-01 00:00:00');
            $periodEnd = date('Y-m-t 23:59:59');

            $hasPayment = function_exists('db_column_exists') && db_column_exists('orders', 'payment_status');
            $hasOrderStatus = function_exists('db_column_exists') && db_column_exists('orders', 'order_status');
            $hasCreatedAt = function_exists('db_column_exists') && db_column_exists('orders', 'created_at');

            if (!$hasPayment || !$hasOrderStatus) {
                // If we can't reliably detect paid/non-canceled state, keep it honest and return 0.
                return 0;
            }

            $sql = "SELECT COUNT(*) FROM orders WHERE restaurant_id = ? AND payment_status = 'paid' AND order_status <> 'canceled'";
            $params = [$restaurantId];
            if ($hasCreatedAt) {
                $sql .= " AND created_at BETWEEN ? AND ?";
                $params[] = $periodStart;
                $params[] = $periodEnd;
            }

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return (int)($stmt->fetchColumn() ?? 0);
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('activation_insights get_paid_orders_this_month ' . $e->getMessage());
            }
            return 0;
        }
    }
}

if (!function_exists('get_owner_restaurants_activation_summary')) {
    /**
     * @param int $ownerUserId
     * @return array{restaurants: array<int, array{name:string, restaurant_id:int, activation_status:string, current_plan:string, readiness:string, orders_this_month:int}>}
     */
    function get_owner_restaurants_activation_summary(int $ownerUserId): array
    {
        $ownerUserId = (int)$ownerUserId;
        $out = ['restaurants' => []];
        if ($ownerUserId <= 0 || !function_exists('db') || !function_exists('db_table_exists') || !db_table_exists('restaurants')) {
            return $out;
        }

        try {
            if (function_exists('db_column_exists') && !db_column_exists('restaurants', 'owner_user_id')) {
                return $out;
            }
            $pdo = db();
            $stmt = $pdo->prepare("SELECT id, name FROM restaurants WHERE owner_user_id = ? ORDER BY id ASC LIMIT 20");
            $stmt->execute([$ownerUserId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $rid = (int)($r['id'] ?? 0);
                if ($rid <= 0) {
                    continue;
                }
                $snap = get_restaurant_activation_snapshot($rid);
                $ready = get_upgrade_readiness_snapshot($rid);
                $out['restaurants'][] = [
                    'restaurant_id' => $rid,
                    'name' => (string)($r['name'] ?? ('Restaurant #' . $rid)),
                    'activation_status' => (string)($snap['activation_status'] ?? 'Старт'),
                    'current_plan' => (string)($snap['current_plan'] ?? 'FREE'),
                    'readiness' => (string)($ready['readiness'] ?? 'not_ready'),
                    'orders_this_month' => (int)($snap['orders_this_month'] ?? 0),
                    'reason' => (string)($ready['reason'] ?? ''),
                ];
            }
        } catch (Throwable $e) {
            // no-op
        }

        return $out;
    }
}

