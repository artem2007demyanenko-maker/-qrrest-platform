<?php
/**
 * Compatibility wrapper: all onboarding logic lives in onboarding_repo.php.
 * This file prevents "Cannot redeclare" when both were required; use require_once __DIR__ . '/onboarding_repo.php' only.
 */

require_once __DIR__ . '/onboarding_repo.php';

if (!function_exists('onboarding_create_restaurant_owner')) {
    /**
     * Backward compatibility: single-step create (returns user + restaurant arrays).
     * @param array{restaurant_name:string, owner_name:string, email:string, password:string, phone?:string, desired_subdomain:string} $data
     * @return array{user_id:int, restaurant_id:int, user:array, restaurant:array}
     */
    function onboarding_create_restaurant_owner(array $data): array
    {
        $result = onboarding_full_register($data);
        $userId = (int)$result['user_id'];
        $restaurantId = (int)$result['restaurant_id'];
        $pdo = db();
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt = $pdo->prepare("SELECT * FROM restaurants WHERE id = ? LIMIT 1");
        $stmt->execute([$restaurantId]);
        $restaurant = $stmt->fetch(PDO::FETCH_ASSOC);
        return [
            'user_id'       => $userId,
            'restaurant_id' => $restaurantId,
            'user'          => $user ?: [],
            'restaurant'    => $restaurant ?: [],
        ];
    }
}

// ------------------------------
// Restaurant activation / onboarding funnel (read-only)
// ------------------------------

if (!function_exists('get_restaurant_onboarding_state')) {
    /**
     * Activation funnel milestone set.
     * Read-only: derives completion from existing restaurant data.
     *
     * @param int $restaurantId
     * @return array{
     *   completed_steps: array<string>,
     *   next_step: string|null,
     *   percent_complete: int,
     *   score: int,
     *   status: string
     * }
     */
    function get_restaurant_onboarding_state(int $restaurantId): array
    {
        $steps = get_restaurant_onboarding_steps($restaurantId);
        $completed = [];
        foreach ($steps as $st) {
            if (!empty($st['done'])) {
                $completed[] = (string)$st['slug'];
            }
        }
        // Weighted scoring: core launch steps weigh more than premium steps.
        $weights = [
            // Core launch milestones
            'menu_created'          => 3,
            'first_table_created'   => 3,
            'qr_ready'              => 3,
            'first_order_received' => 3,
            // Secondary / premium milestones
            'crm_opened'            => 1,
            'upsell_rule_created'  => 1,
            'loyalty_opened'       => 1,
        ];

        $totalWeight = 0;
        $doneWeight = 0;
        foreach ($steps as $st) {
            $slug = (string)($st['slug'] ?? '');
            $w = $weights[$slug] ?? 0;
            $totalWeight += $w;
            if (!empty($st['done']) && $w > 0) {
                $doneWeight += $w;
            }
        }
        if ($totalWeight <= 0) {
            $totalWeight = 1;
        }

        $percent = (int)round($doneWeight * 100.0 / $totalWeight);
        $percent = max(0, min(100, $percent));

        $score = $percent;
        $status = 'Старт';
        if ($score >= 70) {
            $status = 'Активен';
        } elseif ($score >= 40) {
            $status = 'Настройка';
        }

        $next = null;
        foreach ($steps as $st) {
            if (empty($st['done'])) {
                $next = (string)$st['slug'];
                break;
            }
        }

        return [
            'completed_steps' => $completed,
            'next_step' => $next,
            'percent_complete' => $percent,
            'score' => $score,
            'status' => $status,
        ];
    }
}

if (!function_exists('get_restaurant_onboarding_steps')) {
    /**
     * Returns ordered onboarding steps with done flag and UI metadata.
     *
     * @param int $restaurantId
     * @return array<int, array{slug:string,label:string,url:string,done:bool}>
     */
    function get_restaurant_onboarding_steps(int $restaurantId): array
    {
        $restaurantId = (int)$restaurantId;
        $doneMap = [
            'menu_created' => false,
            'first_table_created' => false,
            'qr_ready' => false,
            'first_order_received' => false,
            'crm_opened' => false,
            'upsell_rule_created' => false,
            'loyalty_opened' => false,
        ];

        if ($restaurantId <= 0 || !function_exists('db') || !function_exists('db_table_exists')) {
            return array_map(static function ($st) {
                $st['done'] = false;
                return $st;
            }, get_restaurant_onboarding_steps_def());
        }

        // menu_created: at least 3 menu items
        if (db_table_exists('menu_items')) {
            try {
                $pdo = db();
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM menu_items WHERE restaurant_id = ?");
                $stmt->execute([$restaurantId]);
                $doneMap['menu_created'] = ((int)$stmt->fetchColumn() >= 3);
            } catch (Throwable $e) {
                $doneMap['menu_created'] = false;
            }
        }

        // first_table_created: at least 1 table
        if (db_table_exists('tables')) {
            try {
                $pdo = db();
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM tables WHERE restaurant_id = ?");
                $stmt->execute([$restaurantId]);
                $doneMap['first_table_created'] = ((int)$stmt->fetchColumn() >= 1);
            } catch (Throwable $e) {
                $doneMap['first_table_created'] = false;
            }
        }

        // qr_ready: honest signal = user opened the QR print page (session flag).
        if (function_exists('onboarding_progress_visited_qr_print')) {
            try {
                $doneMap['qr_ready'] = onboarding_progress_visited_qr_print($restaurantId);
            } catch (Throwable $e) {
                $doneMap['qr_ready'] = false;
            }
        }

        // first_order_received: honest signal = at least 1 paid, non-canceled order.
        if (db_table_exists('orders')) {
            try {
                $pdo = db();
                if (function_exists('db_column_exists') && db_column_exists('orders', 'payment_status') && db_column_exists('orders', 'order_status')) {
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE restaurant_id = ? AND payment_status = 'paid' AND order_status <> 'canceled'");
                    $stmt->execute([$restaurantId]);
                    $doneMap['first_order_received'] = ((int)$stmt->fetchColumn() >= 1);
                } else {
                    // Fallback (schema unknown): do not claim "paid" precisely, but still require >= 1 order row.
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE restaurant_id = ? LIMIT 1");
                    $stmt->execute([$restaurantId]);
                    $doneMap['first_order_received'] = ((int)$stmt->fetchColumn() >= 1);
                }
            } catch (Throwable $e) {
                $doneMap['first_order_received'] = false;
            }
        }

        // crm_opened: honest signal = at least one CRM outbox message queued.
        if (db_table_exists('crm_outbox')) {
            try {
                $pdo = db();
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM crm_outbox WHERE restaurant_id = ?");
                $stmt->execute([$restaurantId]);
                $doneMap['crm_opened'] = ((int)$stmt->fetchColumn() >= 1);
            } catch (Throwable $e) {
                $doneMap['crm_opened'] = false;
            }
        }

        // upsell_rule_created: at least one active upsell rule (if 'active' column exists; else any rule)
        if (db_table_exists('upsell_rules')) {
            try {
                $pdo = db();
                if (function_exists('db_column_exists') && db_column_exists('upsell_rules', 'active')) {
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM upsell_rules WHERE restaurant_id = ? AND active = 1");
                } else {
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM upsell_rules WHERE restaurant_id = ?");
                }
                $stmt->execute([$restaurantId]);
                $doneMap['upsell_rule_created'] = ((int)$stmt->fetchColumn() >= 1);
            } catch (Throwable $e) {
                $doneMap['upsell_rule_created'] = false;
            }
        }

        // loyalty_opened: honest signal = loyalty is enabled in restaurant settings.
        // (The settings page writes to restaurant_loyalty_settings.enabled.)
        $loyaltyOk = false;
        if (db_table_exists('restaurant_loyalty_settings')) {
            try {
                $pdo = db();
                if (function_exists('db_column_exists') && db_column_exists('restaurant_loyalty_settings', 'enabled')) {
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM restaurant_loyalty_settings WHERE restaurant_id = ? AND enabled = 1");
                    $stmt->execute([$restaurantId]);
                    $loyaltyOk = ((int)$stmt->fetchColumn() >= 1);
                } else {
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM restaurant_loyalty_settings WHERE restaurant_id = ? LIMIT 1");
                    $stmt->execute([$restaurantId]);
                    $loyaltyOk = ((int)$stmt->fetchColumn() >= 1);
                }
            } catch (Throwable $e) {
                $loyaltyOk = false;
            }
        }
        $doneMap['loyalty_opened'] = $loyaltyOk;

        $stepsDef = get_restaurant_onboarding_steps_def();
        $out = [];
        foreach ($stepsDef as $st) {
            $slug = (string)$st['slug'];
            $st['done'] = !empty($doneMap[$slug]);
            $out[] = $st;
        }
        return $out;
    }
}

if (!function_exists('get_restaurant_activation_score')) {
    /**
     * Convenience wrapper around state.
     *
     * @param int $restaurantId
     * @return int 0-100
     */
    function get_restaurant_activation_score(int $restaurantId): int
    {
        $s = get_restaurant_onboarding_state($restaurantId);
        return (int)($s['score'] ?? 0);
    }
}

if (!function_exists('get_restaurant_onboarding_steps_def')) {
    /**
     * Step definitions only (no DB).
     *
     * @return array<int, array{slug:string,label:string,url:string}>
     */
    function get_restaurant_onboarding_steps_def(): array
    {
        return [
            ['slug' => 'menu_created', 'label' => 'Заполните меню', 'url' => '/restaurant/menu_items.php'],
            ['slug' => 'first_table_created', 'label' => 'Добавьте столы', 'url' => '/restaurant/tables.php'],
            ['slug' => 'qr_ready', 'label' => 'Откройте страницу QR-печати', 'url' => '/restaurant/qr_print.php'],
            ['slug' => 'first_order_received', 'label' => 'Получите первый оплаченный заказ', 'url' => '/qr.php?table_id=1'],
            ['slug' => 'crm_opened', 'label' => 'Подготовьте CRM-кампанию', 'url' => '/restaurant/crm_campaigns.php'],
            ['slug' => 'upsell_rule_created', 'label' => 'Добавьте правило upsell', 'url' => '/restaurant/upsell_rules.php'],
            ['slug' => 'loyalty_opened', 'label' => 'Включите loyalty в настройках', 'url' => '/restaurant/loyalty_settings.php'],
        ];
    }
}

// Ensure onboarding_progress helpers exist for qr_ready milestone.
// We only include it if the file is present to keep this module standalone.
if (!function_exists('onboarding_progress_visited_qr_print')) {
    $p = __DIR__ . '/onboarding_progress.php';
    if (is_file($p)) {
        require_once $p;
    }
}
