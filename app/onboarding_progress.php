<?php
if (!function_exists('qr_public_sql_exclude_delivery') && file_exists(__DIR__ . '/qr_public_menu.php')) {
    require_once __DIR__ . '/qr_public_menu.php';
}
/**
 * Onboarding progress for restaurant owners: steps and completion detection.
 * Demo mode: always treated as completed (no banner).
 */

if (!function_exists('get_onboarding_steps')) {
    /**
     * Ordered list of onboarding step slugs.
     * @return list<string>
     */
    function get_onboarding_steps(): array
    {
        return [
            'setup_restaurant',
            'add_menu_items',
            'create_tables',
            'print_qr',
            'first_order',
        ];
    }
}

if (!function_exists('get_onboarding_progress')) {
    /**
     * Get completion state for a restaurant. Demo (restaurant_id 0) returns 100% completed.
     * @return array{completed_steps: list<string>, next_step: string|null, percent_complete: int}
     */
    function get_onboarding_progress(int $restaurantId): array
    {
        if (function_exists('is_demo_mode') && is_demo_mode() && $restaurantId === 0) {
            $steps = get_onboarding_steps();
            return [
                'completed_steps' => $steps,
                'next_step'       => null,
                'percent_complete' => 100,
            ];
        }

        $steps = get_onboarding_steps();
        $completed = [];

        if ($restaurantId <= 0) {
            return [
                'completed_steps'   => [],
                'next_step'        => $steps[0] ?? null,
                'percent_complete' => 0,
            ];
        }

        // setup_restaurant: restaurant has name set (basic setup done)
        $setupDone = onboarding_progress_setup_done($restaurantId);
        if ($setupDone) {
            $completed[] = 'setup_restaurant';
        }

        // add_menu_items: at least 3 menu items
        if (onboarding_progress_menu_items_count($restaurantId) >= 3) {
            $completed[] = 'add_menu_items';
        }

        // create_tables: at least 1 table
        if (onboarding_progress_tables_count($restaurantId) >= 1) {
            $completed[] = 'create_tables';
        }

        // print_qr: user visited qr_print.php (session flag)
        if (onboarding_progress_visited_qr_print($restaurantId)) {
            $completed[] = 'print_qr';
        }

        // first_order: at least 1 order
        if (onboarding_progress_orders_count($restaurantId) >= 1) {
            $completed[] = 'first_order';
        }

        $next_step = null;
        foreach ($steps as $step) {
            if (!in_array($step, $completed, true)) {
                $next_step = $step;
                break;
            }
        }

        $percent = count($steps) > 0 ? (int)round(count($completed) / count($steps) * 100) : 100;

        return [
            'completed_steps'   => $completed,
            'next_step'         => $next_step,
            'percent_complete'  => min(100, $percent),
        ];
    }
}

if (!function_exists('onboarding_progress_setup_done')) {
    function onboarding_progress_setup_done(int $restaurantId): bool
    {
        if (!function_exists('db')) {
            return false;
        }
        try {
            $pdo = db();
            $deletedSql = function_exists('schema_guard_restaurants_deleted_sql') ? schema_guard_restaurants_deleted_sql('') : '';
            $stmt = $pdo->prepare("SELECT name FROM restaurants WHERE id = ? {$deletedSql} LIMIT 1");
            $stmt->execute([$restaurantId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row && trim((string)($row['name'] ?? '')) !== '';
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('onboarding_progress_menu_items_count')) {
    function onboarding_progress_menu_items_count(int $restaurantId): int
    {
        if (!function_exists('db')) {
            return 0;
        }
        try {
            $pdo = db();
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM menu_items WHERE restaurant_id = ?");
            $stmt->execute([$restaurantId]);
            return (int)$stmt->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }
}

if (!function_exists('onboarding_progress_tables_count')) {
    function onboarding_progress_tables_count(int $restaurantId): int
    {
        if (!function_exists('db')) {
            return 0;
        }
        try {
            $pdo = db();
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM tables AS t
                WHERE t.restaurant_id = ?
                " . qr_public_sql_exclude_delivery($pdo, 't') . "
            ");
            $stmt->execute([$restaurantId]);
            return (int)$stmt->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }
}

if (!function_exists('onboarding_progress_visited_qr_print')) {
    function onboarding_progress_visited_qr_print(int $restaurantId): bool
    {
        if ($restaurantId <= 0) {
            return false;
        }
        $key = 'onboarding_qr_print_' . $restaurantId;
        return !empty($_SESSION[$key]);
    }
}

if (!function_exists('onboarding_progress_mark_visited_qr_print')) {
    function onboarding_progress_mark_visited_qr_print(int $restaurantId): void
    {
        if ($restaurantId <= 0) {
            return;
        }
        $_SESSION['onboarding_qr_print_' . $restaurantId] = true;
    }
}

if (!function_exists('onboarding_progress_orders_count')) {
    function onboarding_progress_orders_count(int $restaurantId): int
    {
        if (!function_exists('db')) {
            return 0;
        }
        try {
            $pdo = db();
            if (function_exists('db_column_exists') && db_column_exists('orders', 'payment_status') && db_column_exists('orders', 'order_status')) {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE restaurant_id = ? AND payment_status = 'paid' AND order_status <> 'canceled'");
                $stmt->execute([$restaurantId]);
                return (int)$stmt->fetchColumn();
            }
            // Fallback: schema unknown; keep previous behavior.
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE restaurant_id = ?");
            $stmt->execute([$restaurantId]);
            return (int)$stmt->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }
}

/** Step labels for UI (English). */
if (!function_exists('get_onboarding_step_label')) {
    function get_onboarding_step_label(string $step): string
    {
        $labels = [
            'setup_restaurant' => 'Setup restaurant',
            'add_menu_items'   => 'Add menu items',
            'create_tables'    => 'Create tables',
            'print_qr'         => 'Print QR codes',
            'first_order'      => 'First order',
        ];
        return $labels[$step] ?? $step;
    }
}

/** URL for each step (for "Continue setup" button). */
if (!function_exists('get_onboarding_step_url')) {
    function get_onboarding_step_url(string $step): string
    {
        $urls = [
            'setup_restaurant' => '/restaurant/setup.php',
            'add_menu_items'   => '/restaurant/menu_items.php',
            'create_tables'    => '/restaurant/tables.php',
            'print_qr'         => '/restaurant/qr_print.php',
            'first_order'      => '/restaurant/dashboard.php', // dashboard shows "Open QR Menu" when no orders
        ];
        return $urls[$step] ?? '/restaurant/dashboard.php';
    }
}
