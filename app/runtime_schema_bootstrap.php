<?php

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/schema_guard.php';

/**
 * Runtime schema bootstrap for critical cabinet features.
 * Idempotent and best-effort: creates only missing tables/columns.
 */

if (!function_exists('runtime_schema_bootstrap_index_exists')) {
    function runtime_schema_bootstrap_index_exists(PDO $pdo, string $table, string $index): bool
    {
        try {
            $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
            $index = preg_replace('/[^a-zA-Z0-9_]/', '', $index);
            if ($table === '' || $index === '') {
                return false;
            }
            $stmt = $pdo->prepare("
                SELECT 1
                FROM INFORMATION_SCHEMA.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = :table_name
                  AND INDEX_NAME = :index_name
                LIMIT 1
            ");
            $stmt->execute([
                ':table_name' => $table,
                ':index_name' => $index,
            ]);
            return (bool)$stmt->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('runtime_schema_bootstrap_extract_create_index_meta')) {
    function runtime_schema_bootstrap_extract_create_index_meta(string $sql): ?array
    {
        if (!preg_match('/^\s*CREATE\s+(?:UNIQUE\s+)?INDEX\s+`?([a-zA-Z0-9_]+)`?\s+ON\s+`?([a-zA-Z0-9_]+)`?\s*\(/is', $sql, $m)) {
            return null;
        }
        return [
            'index' => (string)($m[1] ?? ''),
            'table' => (string)($m[2] ?? ''),
        ];
    }
}

if (!function_exists('runtime_schema_bootstrap_ignorable_error')) {
    function runtime_schema_bootstrap_ignorable_error(Throwable $e): bool
    {
        $msg = strtolower((string)$e->getMessage());
        if (strpos($msg, 'duplicate key name') !== false) {
            return true;
        }
        if (strpos($msg, 'already exists') !== false && strpos($msg, 'index') !== false) {
            return true;
        }
        if (strpos($msg, 'duplicate column name') !== false) {
            return true;
        }
        return false;
    }
}

function runtime_schema_bootstrap_exec(PDO $pdo, string $sql, string $tag): bool
{
    static $logged = [];
    try {
        $indexMeta = runtime_schema_bootstrap_extract_create_index_meta($sql);
        if (is_array($indexMeta)
            && runtime_schema_bootstrap_index_exists($pdo, (string)$indexMeta['table'], (string)$indexMeta['index'])) {
            return true;
        }
        $pdo->exec($sql);
        return true;
    } catch (Throwable $e) {
        if (runtime_schema_bootstrap_ignorable_error($e)) {
            return true;
        }
        if (!isset($logged[$tag])) {
            $logged[$tag] = true;
            error_log('RUNTIME_SCHEMA_BOOTSTRAP_ERROR tag=' . $tag . ' ' . $e->getMessage());
        }
        return false;
    }
}

function runtime_schema_ensure_usage_metrics_daily(PDO $pdo): void
{
    if (function_exists('db_table_exists') && db_table_exists('usage_metrics_daily')) {
        return;
    }
    runtime_schema_bootstrap_exec($pdo, "
        CREATE TABLE IF NOT EXISTS usage_metrics_daily (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT NOT NULL,
            date DATE NOT NULL,
            restaurants_count INT UNSIGNED NOT NULL DEFAULT 0,
            orders_count INT UNSIGNED NOT NULL DEFAULT 0,
            revenue DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            staff_count INT UNSIGNED NOT NULL DEFAULT 0,
            menu_items_count INT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_usage_user_date (user_id, date),
            KEY idx_usage_user (user_id),
            KEY idx_usage_date (date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ", 'usage_metrics_daily.create');
}

function runtime_schema_ensure_guest_profiles(PDO $pdo): void
{
    runtime_schema_bootstrap_exec($pdo, "
        CREATE TABLE IF NOT EXISTS guest_profiles (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            restaurant_id INT NOT NULL,
            phone_normalized VARCHAR(16) NOT NULL,
            guest_name VARCHAR(190) NULL,
            orders_count INT UNSIGNED NOT NULL DEFAULT 0,
            total_spent DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            average_check DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            first_order_at DATETIME NULL,
            last_order_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_guest_profiles_rest_phone (restaurant_id, phone_normalized),
            KEY idx_guest_profiles_restaurant (restaurant_id),
            KEY idx_guest_profiles_phone (phone_normalized),
            KEY idx_guest_profiles_last_order (last_order_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ", 'guest_profiles.create');

    $columns = [
        'restaurant_id' => "ALTER TABLE guest_profiles ADD COLUMN restaurant_id INT NOT NULL",
        'phone_normalized' => "ALTER TABLE guest_profiles ADD COLUMN phone_normalized VARCHAR(16) NOT NULL",
        'guest_name' => "ALTER TABLE guest_profiles ADD COLUMN guest_name VARCHAR(190) NULL",
        'orders_count' => "ALTER TABLE guest_profiles ADD COLUMN orders_count INT UNSIGNED NOT NULL DEFAULT 0",
        'total_spent' => "ALTER TABLE guest_profiles ADD COLUMN total_spent DECIMAL(12,2) NOT NULL DEFAULT 0.00",
        'average_check' => "ALTER TABLE guest_profiles ADD COLUMN average_check DECIMAL(12,2) NOT NULL DEFAULT 0.00",
        'first_order_at' => "ALTER TABLE guest_profiles ADD COLUMN first_order_at DATETIME NULL",
        'last_order_at' => "ALTER TABLE guest_profiles ADD COLUMN last_order_at DATETIME NULL",
        'created_at' => "ALTER TABLE guest_profiles ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",
        'updated_at' => "ALTER TABLE guest_profiles ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
    ];
    foreach ($columns as $column => $sql) {
        if (function_exists('db_column_exists') && db_column_exists('guest_profiles', $column)) {
            continue;
        }
        runtime_schema_bootstrap_exec($pdo, $sql, 'guest_profiles.' . $column . '.add');
    }

    $hasIndex = static function (PDO $pdo, string $table, string $index): bool {
        try {
            $stmt = $pdo->prepare("
                SELECT 1
                FROM INFORMATION_SCHEMA.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = :table_name
                  AND INDEX_NAME = :index_name
                LIMIT 1
            ");
            $stmt->execute([
                ':table_name' => $table,
                ':index_name' => $index,
            ]);
            return (bool)$stmt->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    };

    if (!$hasIndex($pdo, 'guest_profiles', 'uniq_guest_profiles_rest_phone')) {
        runtime_schema_bootstrap_exec(
            $pdo,
            "CREATE UNIQUE INDEX uniq_guest_profiles_rest_phone ON guest_profiles (restaurant_id, phone_normalized)",
            'guest_profiles.idx.uniq_rest_phone'
        );
    }
    if (!$hasIndex($pdo, 'guest_profiles', 'idx_guest_profiles_restaurant')) {
        runtime_schema_bootstrap_exec(
            $pdo,
            "CREATE INDEX idx_guest_profiles_restaurant ON guest_profiles (restaurant_id)",
            'guest_profiles.idx.restaurant'
        );
    }
    if (!$hasIndex($pdo, 'guest_profiles', 'idx_guest_profiles_phone')) {
        runtime_schema_bootstrap_exec(
            $pdo,
            "CREATE INDEX idx_guest_profiles_phone ON guest_profiles (phone_normalized)",
            'guest_profiles.idx.phone'
        );
    }
    if (!$hasIndex($pdo, 'guest_profiles', 'idx_guest_profiles_last_order')) {
        runtime_schema_bootstrap_exec(
            $pdo,
            "CREATE INDEX idx_guest_profiles_last_order ON guest_profiles (last_order_at)",
            'guest_profiles.idx.last_order'
        );
    }
}

function runtime_schema_ensure_crm_core(PDO $pdo): void
{
    runtime_schema_bootstrap_exec($pdo, "
        CREATE TABLE IF NOT EXISTS crm_visits (
            id INT AUTO_INCREMENT PRIMARY KEY,
            restaurant_id INT NOT NULL,
            guest_id INT NOT NULL,
            order_id INT NULL,
            table_id INT NULL,
            total_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            visited_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_restaurant_id (restaurant_id),
            INDEX idx_guest_id (guest_id),
            INDEX idx_order_id (order_id),
            INDEX idx_visited_at (visited_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ", 'crm_visits.create');

    runtime_schema_bootstrap_exec($pdo, "
        CREATE TABLE IF NOT EXISTS crm_templates (
            id INT AUTO_INCREMENT PRIMARY KEY,
            restaurant_id INT NOT NULL,
            name VARCHAR(128) NOT NULL,
            channel VARCHAR(16) NOT NULL DEFAULT 'stub',
            template_text TEXT NOT NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_restaurant_id (restaurant_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ", 'crm_templates.create');

    runtime_schema_bootstrap_exec($pdo, "
        CREATE TABLE IF NOT EXISTS crm_campaigns (
            id INT AUTO_INCREMENT PRIMARY KEY,
            restaurant_id INT NOT NULL,
            name VARCHAR(128) NOT NULL,
            template_id INT NOT NULL,
            segment_type VARCHAR(32) NOT NULL,
            delay_days INT NOT NULL DEFAULT 0,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_restaurant_id (restaurant_id),
            INDEX idx_template_id (template_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ", 'crm_campaigns.create');

    runtime_schema_bootstrap_exec($pdo, "
        CREATE TABLE IF NOT EXISTS crm_outbox (
            id INT AUTO_INCREMENT PRIMARY KEY,
            restaurant_id INT NOT NULL,
            guest_id INT NOT NULL,
            channel VARCHAR(16) NOT NULL DEFAULT 'stub',
            template VARCHAR(64) NOT NULL DEFAULT 'come_back',
            payload_json TEXT NOT NULL,
            scheduled_at TIMESTAMP NOT NULL,
            status VARCHAR(16) NOT NULL DEFAULT 'pending',
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_restaurant_id (restaurant_id),
            INDEX idx_guest_id (guest_id),
            INDEX idx_scheduled_at (scheduled_at),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ", 'crm_outbox.create');
}

function runtime_schema_ensure_combo_rules(PDO $pdo): void
{
    runtime_schema_bootstrap_exec($pdo, "
        CREATE TABLE IF NOT EXISTS combo_rules (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            restaurant_id INT NOT NULL,
            trigger_item_id INT NOT NULL,
            suggested_item_ids JSON NOT NULL,
            priority INT NOT NULL DEFAULT 100,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ", 'combo_rules.create');
    runtime_schema_bootstrap_exec($pdo, "
        CREATE INDEX idx_combo_rules_restaurant_active_priority
        ON combo_rules (restaurant_id, active, priority)
    ", 'combo_rules.idx_restaurant_active_priority');
    runtime_schema_bootstrap_exec($pdo, "
        CREATE INDEX idx_combo_rules_restaurant_trigger_active
        ON combo_rules (restaurant_id, trigger_item_id, active)
    ", 'combo_rules.idx_restaurant_trigger_active');
}

function runtime_schema_ensure_upsell_rules(PDO $pdo): void
{
    runtime_schema_bootstrap_exec($pdo, "
        CREATE TABLE IF NOT EXISTS upsell_rules (
            id INT AUTO_INCREMENT PRIMARY KEY,
            restaurant_id INT NOT NULL,
            rule_type VARCHAR(32) NOT NULL,
            rule_value VARCHAR(128) NULL,
            priority INT NOT NULL DEFAULT 100,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ", 'upsell_rules.create');
    runtime_schema_bootstrap_exec($pdo, "
        CREATE INDEX idx_upsell_rules_restaurant_priority_active
        ON upsell_rules (restaurant_id, priority, active)
    ", 'upsell_rules.idx_restaurant_priority_active');
    runtime_schema_bootstrap_exec($pdo, "
        CREATE INDEX idx_upsell_rules_restaurant_rule_type
        ON upsell_rules (restaurant_id, rule_type)
    ", 'upsell_rules.idx_restaurant_rule_type');
}

function runtime_schema_ensure_restaurants_owner_user_id(PDO $pdo): void
{
    if (function_exists('db_column_exists') && db_column_exists('restaurants', 'owner_user_id')) {
        return;
    }
    runtime_schema_bootstrap_exec($pdo, "
        ALTER TABLE restaurants
        ADD COLUMN owner_user_id INT NULL
    ", 'restaurants.owner_user_id.add');
    runtime_schema_bootstrap_exec($pdo, "
        CREATE INDEX idx_restaurants_owner
        ON restaurants (owner_user_id)
    ", 'restaurants.owner_user_id.idx_owner');
}

function runtime_schema_ensure_restaurant_crm_settings(PDO $pdo): void
{
    runtime_schema_bootstrap_exec($pdo, "
        CREATE TABLE IF NOT EXISTS restaurant_crm_settings (
            restaurant_id INT NOT NULL,
            inactive_return_days INT NOT NULL DEFAULT 14,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (restaurant_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ", 'restaurant_crm_settings.create');
}

function runtime_schema_ensure_restaurants_guest_upsell_split(PDO $pdo): void
{
    $columns = [
        'guest_menu_upsell_enabled' => "ALTER TABLE restaurants ADD COLUMN guest_menu_upsell_enabled TINYINT(1) NOT NULL DEFAULT 1",
        'guest_menu_upsell_limit' => "ALTER TABLE restaurants ADD COLUMN guest_menu_upsell_limit INT NOT NULL DEFAULT 1",
        'guest_menu_upsell_manual_only' => "ALTER TABLE restaurants ADD COLUMN guest_menu_upsell_manual_only TINYINT(1) NOT NULL DEFAULT 1",
        'guest_cart_upsell_enabled' => "ALTER TABLE restaurants ADD COLUMN guest_cart_upsell_enabled TINYINT(1) NOT NULL DEFAULT 1",
        'guest_cart_upsell_limit' => "ALTER TABLE restaurants ADD COLUMN guest_cart_upsell_limit INT NOT NULL DEFAULT 3",
        'guest_cart_upsell_use_manual' => "ALTER TABLE restaurants ADD COLUMN guest_cart_upsell_use_manual TINYINT(1) NOT NULL DEFAULT 1",
        'guest_cart_upsell_use_contextual' => "ALTER TABLE restaurants ADD COLUMN guest_cart_upsell_use_contextual TINYINT(1) NOT NULL DEFAULT 1",
        'guest_cart_upsell_use_popular' => "ALTER TABLE restaurants ADD COLUMN guest_cart_upsell_use_popular TINYINT(1) NOT NULL DEFAULT 1",
    ];
    foreach ($columns as $column => $sql) {
        if (function_exists('db_column_exists') && db_column_exists('restaurants', $column)) {
            continue;
        }
        runtime_schema_bootstrap_exec($pdo, $sql, 'restaurants.' . $column . '.add');
    }
}

function runtime_schema_ensure_restaurants_combo_split(PDO $pdo): void
{
    $columns = [
        'guest_menu_combo_enabled' => "ALTER TABLE restaurants ADD COLUMN guest_menu_combo_enabled TINYINT(1) NOT NULL DEFAULT 1",
        'guest_menu_combo_limit' => "ALTER TABLE restaurants ADD COLUMN guest_menu_combo_limit INT NOT NULL DEFAULT 1",
        'guest_cart_combo_enabled' => "ALTER TABLE restaurants ADD COLUMN guest_cart_combo_enabled TINYINT(1) NOT NULL DEFAULT 1",
        'guest_cart_combo_limit' => "ALTER TABLE restaurants ADD COLUMN guest_cart_combo_limit INT NOT NULL DEFAULT 2",
    ];
    foreach ($columns as $column => $sql) {
        if (function_exists('db_column_exists') && db_column_exists('restaurants', $column)) {
            continue;
        }
        runtime_schema_bootstrap_exec($pdo, $sql, 'restaurants.' . $column . '.add');
    }
}

function runtime_schema_ensure_menu_items_food_meta(PDO $pdo): void
{
    $columns = [
        'ingredients' => "ALTER TABLE menu_items ADD COLUMN ingredients TEXT NULL",
        'allergens' => "ALTER TABLE menu_items ADD COLUMN allergens TEXT NULL",
        'weight_grams' => "ALTER TABLE menu_items ADD COLUMN weight_grams INT NULL",
        'tags' => "ALTER TABLE menu_items ADD COLUMN tags TEXT NULL",
    ];
    foreach ($columns as $column => $sql) {
        if (function_exists('db_column_exists') && db_column_exists('menu_items', $column)) {
            continue;
        }
        runtime_schema_bootstrap_exec($pdo, $sql, 'menu_items.' . $column . '.add');
    }
}

function runtime_schema_ensure_menu_items_availability(PDO $pdo): void
{
    if (!(function_exists('db_column_exists') && db_column_exists('menu_items', 'available'))) {
        runtime_schema_bootstrap_exec(
            $pdo,
            "ALTER TABLE menu_items ADD COLUMN available TINYINT(1) NOT NULL DEFAULT 1",
            'menu_items.available.add'
        );
    }
    runtime_schema_bootstrap_exec(
        $pdo,
        "UPDATE menu_items SET available = 1 WHERE available IS NULL",
        'menu_items.available.normalize_null'
    );

    if (!(function_exists('db_column_exists') && db_column_exists('menu_items', 'is_temporarily_unavailable'))) {
        runtime_schema_bootstrap_exec(
            $pdo,
            "ALTER TABLE menu_items ADD COLUMN is_temporarily_unavailable TINYINT(1) NOT NULL DEFAULT 0",
            'menu_items.is_temporarily_unavailable.add'
        );
    }
    runtime_schema_bootstrap_exec(
        $pdo,
        "UPDATE menu_items SET is_temporarily_unavailable = 0 WHERE is_temporarily_unavailable IS NULL",
        'menu_items.is_temporarily_unavailable.normalize_null'
    );
}

function runtime_schema_ensure_production_stations(PDO $pdo): void
{
    if (!(function_exists('db_column_exists') && db_column_exists('menu_items', 'station'))) {
        runtime_schema_bootstrap_exec(
            $pdo,
            "ALTER TABLE menu_items ADD COLUMN station VARCHAR(32) NULL DEFAULT 'hot'",
            'menu_items.station.add'
        );
    }
    runtime_schema_bootstrap_exec(
        $pdo,
        "UPDATE menu_items
         SET station = CASE
            WHEN LOWER(COALESCE(TRIM(station), '')) IN ('', 'kitchen') THEN 'hot'
            WHEN LOWER(COALESCE(TRIM(station), '')) = 'desserts' THEN 'dessert'
            ELSE LOWER(TRIM(station))
         END",
        'menu_items.station.normalize'
    );

    if (!(function_exists('db_column_exists') && db_column_exists('menu_items', 'production_station'))) {
        runtime_schema_bootstrap_exec(
            $pdo,
            "ALTER TABLE menu_items ADD COLUMN production_station VARCHAR(32) NOT NULL DEFAULT 'kitchen'",
            'menu_items.production_station.add'
        );
    }
    runtime_schema_bootstrap_exec(
        $pdo,
        "UPDATE menu_items
         SET production_station = CASE
            WHEN LOWER(COALESCE(TRIM(production_station), '')) IN ('', 'hot', 'kitchen') THEN 'kitchen'
            WHEN LOWER(COALESCE(TRIM(production_station), '')) = 'desserts' THEN 'dessert'
            ELSE LOWER(TRIM(production_station))
         END",
        'menu_items.production_station.normalize'
    );

    if (!(function_exists('db_column_exists') && db_column_exists('order_items', 'production_station'))) {
        runtime_schema_bootstrap_exec(
            $pdo,
            "ALTER TABLE order_items ADD COLUMN production_station VARCHAR(32) NOT NULL DEFAULT 'kitchen'",
            'order_items.production_station.add'
        );
    }
    if (!(function_exists('db_column_exists') && db_column_exists('order_items', 'station_completed_at'))) {
        runtime_schema_bootstrap_exec(
            $pdo,
            "ALTER TABLE order_items ADD COLUMN station_completed_at DATETIME NULL",
            'order_items.station_completed_at.add'
        );
    }
    if (!(function_exists('db_column_exists') && db_column_exists('order_items', 'kds_status'))) {
        runtime_schema_bootstrap_exec(
            $pdo,
            "ALTER TABLE order_items ADD COLUMN kds_status VARCHAR(32) NULL DEFAULT 'new'",
            'order_items.kds_status.add'
        );
    }
    if (!(function_exists('db_column_exists') && db_column_exists('order_items', 'kds_started_at'))) {
        runtime_schema_bootstrap_exec(
            $pdo,
            "ALTER TABLE order_items ADD COLUMN kds_started_at DATETIME NULL",
            'order_items.kds_started_at.add'
        );
    }
    if (!(function_exists('db_column_exists') && db_column_exists('order_items', 'kds_ready_at'))) {
        runtime_schema_bootstrap_exec(
            $pdo,
            "ALTER TABLE order_items ADD COLUMN kds_ready_at DATETIME NULL",
            'order_items.kds_ready_at.add'
        );
    }
    $hasMenuProdStation = function_exists('db_column_exists') && db_column_exists('menu_items', 'production_station');
    $hasMenuStation = function_exists('db_column_exists') && db_column_exists('menu_items', 'station');
    $hasMenuKitchenStation = function_exists('db_column_exists') && db_column_exists('menu_items', 'kitchen_station');
    $menuProdExpr = $hasMenuProdStation ? "LOWER(COALESCE(TRIM(mi.production_station), ''))" : "''";
    $menuStationExpr = $hasMenuStation ? "LOWER(COALESCE(TRIM(mi.station), ''))" : "''";
    $menuKitchenExpr = $hasMenuKitchenStation ? "LOWER(COALESCE(TRIM(mi.kitchen_station), ''))" : "''";
    runtime_schema_bootstrap_exec(
        $pdo,
        "UPDATE order_items oi
         LEFT JOIN menu_items mi ON mi.id = oi.menu_item_id
         SET oi.production_station = CASE
            WHEN LOWER(COALESCE(TRIM(oi.production_station), '')) IN ('', 'hot', 'kitchen') THEN
                CASE
                    WHEN {$menuProdExpr} IN ('', 'hot', 'kitchen') THEN
                        CASE
                            WHEN {$menuStationExpr} = 'hot' THEN 'kitchen'
                            WHEN {$menuStationExpr} <> '' THEN {$menuStationExpr}
                            WHEN {$menuKitchenExpr} = 'hot' THEN 'kitchen'
                            WHEN {$menuKitchenExpr} <> '' THEN {$menuKitchenExpr}
                            ELSE 'kitchen'
                        END
                    WHEN {$menuProdExpr} = 'desserts' THEN 'dessert'
                    WHEN {$menuProdExpr} <> '' THEN {$menuProdExpr}
                    WHEN {$menuStationExpr} = 'hot' THEN 'kitchen'
                    WHEN {$menuStationExpr} <> '' THEN {$menuStationExpr}
                    WHEN {$menuKitchenExpr} = 'hot' THEN 'kitchen'
                    WHEN {$menuKitchenExpr} <> '' THEN {$menuKitchenExpr}
                    ELSE 'kitchen'
                END
            WHEN LOWER(COALESCE(TRIM(oi.production_station), '')) = 'desserts' THEN 'dessert'
            ELSE LOWER(TRIM(oi.production_station))
         END",
        'order_items.production_station.backfill'
    );

    if ((function_exists('db_column_exists') && db_column_exists('order_items', 'kds_status'))
        && (function_exists('db_column_exists') && db_column_exists('order_items', 'station_status'))) {
        runtime_schema_bootstrap_exec(
            $pdo,
            "UPDATE order_items SET kds_status = COALESCE(NULLIF(TRIM(kds_status), ''), NULLIF(TRIM(station_status), ''), 'new')",
            'order_items.kds_status.backfill'
        );
    }
    if ((function_exists('db_column_exists') && db_column_exists('order_items', 'kds_started_at'))
        && (function_exists('db_column_exists') && db_column_exists('order_items', 'started_at'))) {
        runtime_schema_bootstrap_exec(
            $pdo,
            "UPDATE order_items SET kds_started_at = COALESCE(kds_started_at, started_at)",
            'order_items.kds_started_at.backfill'
        );
    }
    if ((function_exists('db_column_exists') && db_column_exists('order_items', 'kds_ready_at'))
        && (function_exists('db_column_exists') && db_column_exists('order_items', 'ready_at'))) {
        runtime_schema_bootstrap_exec(
            $pdo,
            "UPDATE order_items SET kds_ready_at = COALESCE(kds_ready_at, ready_at)",
            'order_items.kds_ready_at.backfill'
        );
    }

    runtime_schema_bootstrap_exec(
        $pdo,
        "CREATE INDEX idx_menu_items_rest_prod_station_available ON menu_items (restaurant_id, production_station, available)",
        'menu_items.production_station.idx_rest_station_available'
    );
    runtime_schema_bootstrap_exec(
        $pdo,
        "CREATE INDEX idx_order_items_order_prod_station ON order_items (order_id, production_station, id)",
        'order_items.production_station.idx_order_station'
    );

    if (function_exists('db_table_exists') && db_table_exists('users_restaurants')
        && !(function_exists('db_column_exists') && db_column_exists('users_restaurants', 'station'))) {
        runtime_schema_bootstrap_exec(
            $pdo,
            "ALTER TABLE users_restaurants ADD COLUMN station VARCHAR(32) NULL DEFAULT NULL",
            'users_restaurants.station.add'
        );
    }

    if (function_exists('db_table_exists') && db_table_exists('staff_users')
        && !(function_exists('db_column_exists') && db_column_exists('staff_users', 'station'))) {
        runtime_schema_bootstrap_exec(
            $pdo,
            "ALTER TABLE staff_users ADD COLUMN station VARCHAR(32) NULL DEFAULT 'all'",
            'staff_users.station.add'
        );
    }
}

function runtime_schema_ensure_orders_order_type(PDO $pdo): void
{
    if (function_exists('db_column_exists') && db_column_exists('orders', 'order_type')) {
        return;
    }
    runtime_schema_bootstrap_exec($pdo, "
        ALTER TABLE orders
        ADD COLUMN order_type VARCHAR(16) NULL DEFAULT NULL
    ", 'orders.order_type.add');
    runtime_schema_bootstrap_exec($pdo, "
        CREATE INDEX idx_orders_rest_type_created
        ON orders (restaurant_id, order_type, created_at)
    ", 'orders.order_type.idx_rest_type_created');
}

function runtime_schema_ensure_orders_fulfillment_meta(PDO $pdo): void
{
    $columns = [
        'customer_name' => "ALTER TABLE orders ADD COLUMN customer_name VARCHAR(190) NULL",
        'customer_phone' => "ALTER TABLE orders ADD COLUMN customer_phone VARCHAR(32) NULL",
        'scheduled_for' => "ALTER TABLE orders ADD COLUMN scheduled_for DATETIME NULL",
        'preorder_receive_type' => "ALTER TABLE orders ADD COLUMN preorder_receive_type VARCHAR(16) NULL",
    ];
    foreach ($columns as $column => $sql) {
        if (function_exists('db_column_exists') && db_column_exists('orders', $column)) {
            continue;
        }
        runtime_schema_bootstrap_exec($pdo, $sql, 'orders.' . $column . '.add');
    }
}

function runtime_schema_ensure_orders_courier_meta(PDO $pdo): void
{
    $columns = [
        'courier_status' => "ALTER TABLE orders ADD COLUMN courier_status VARCHAR(24) NULL",
        'courier_user_id' => "ALTER TABLE orders ADD COLUMN courier_user_id INT NULL",
        'courier_taken_at' => "ALTER TABLE orders ADD COLUMN courier_taken_at DATETIME NULL",
        'courier_on_the_way_at' => "ALTER TABLE orders ADD COLUMN courier_on_the_way_at DATETIME NULL",
        'delivered_at' => "ALTER TABLE orders ADD COLUMN delivered_at DATETIME NULL",
    ];
    foreach ($columns as $column => $sql) {
        if (function_exists('db_column_exists') && db_column_exists('orders', $column)) {
            continue;
        }
        runtime_schema_bootstrap_exec($pdo, $sql, 'orders.' . $column . '.add');
    }

    runtime_schema_bootstrap_exec(
        $pdo,
        "CREATE INDEX idx_orders_rest_type_courier_created ON orders (restaurant_id, order_type, courier_status, created_at)",
        'orders.courier.idx_rest_type_status_created'
    );
    runtime_schema_bootstrap_exec(
        $pdo,
        "CREATE INDEX idx_orders_courier_user_created ON orders (courier_user_id, created_at)",
        'orders.courier.idx_user_created'
    );
}

function runtime_schema_ensure_courier_shifts(PDO $pdo): void
{
    runtime_schema_bootstrap_exec($pdo, "
        CREATE TABLE IF NOT EXISTS courier_shifts (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            courier_user_id INT NOT NULL,
            restaurant_id INT NOT NULL,
            started_at DATETIME NOT NULL,
            ended_at DATETIME NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            shift_status VARCHAR(24) NOT NULL DEFAULT 'online',
            deliveries_completed INT NOT NULL DEFAULT 0,
            deliveries_active INT NOT NULL DEFAULT 0,
            total_online_minutes INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_courier_shifts_rest_active (restaurant_id, active, started_at),
            KEY idx_courier_shifts_user_active (courier_user_id, active, started_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ", 'courier_shifts.create');

    $columns = [
        'courier_user_id' => "ALTER TABLE courier_shifts ADD COLUMN courier_user_id INT NOT NULL",
        'restaurant_id' => "ALTER TABLE courier_shifts ADD COLUMN restaurant_id INT NOT NULL",
        'started_at' => "ALTER TABLE courier_shifts ADD COLUMN started_at DATETIME NOT NULL",
        'ended_at' => "ALTER TABLE courier_shifts ADD COLUMN ended_at DATETIME NULL",
        'active' => "ALTER TABLE courier_shifts ADD COLUMN active TINYINT(1) NOT NULL DEFAULT 1",
        'shift_status' => "ALTER TABLE courier_shifts ADD COLUMN shift_status VARCHAR(24) NOT NULL DEFAULT 'online'",
        'deliveries_completed' => "ALTER TABLE courier_shifts ADD COLUMN deliveries_completed INT NOT NULL DEFAULT 0",
        'deliveries_active' => "ALTER TABLE courier_shifts ADD COLUMN deliveries_active INT NOT NULL DEFAULT 0",
        'total_online_minutes' => "ALTER TABLE courier_shifts ADD COLUMN total_online_minutes INT NOT NULL DEFAULT 0",
        'created_at' => "ALTER TABLE courier_shifts ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",
        'updated_at' => "ALTER TABLE courier_shifts ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
    ];
    foreach ($columns as $column => $sql) {
        if (function_exists('db_column_exists') && db_column_exists('courier_shifts', $column)) {
            continue;
        }
        runtime_schema_bootstrap_exec($pdo, $sql, 'courier_shifts.' . $column . '.add');
    }

    runtime_schema_bootstrap_exec(
        $pdo,
        "CREATE INDEX idx_courier_shifts_rest_active ON courier_shifts (restaurant_id, active, started_at)",
        'courier_shifts.idx.rest_active'
    );
    runtime_schema_bootstrap_exec(
        $pdo,
        "CREATE INDEX idx_courier_shifts_user_active ON courier_shifts (courier_user_id, active, started_at)",
        'courier_shifts.idx.user_active'
    );
}

function runtime_schema_ensure_courier_earnings(PDO $pdo): void
{
    runtime_schema_bootstrap_exec($pdo, "
        CREATE TABLE IF NOT EXISTS courier_earnings (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            courier_user_id INT NOT NULL,
            restaurant_id INT NOT NULL,
            order_id INT NULL,
            shift_id INT NULL,
            base_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            bonus_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            total_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            earning_type VARCHAR(32) NOT NULL DEFAULT 'delivery',
            payout_status VARCHAR(32) NOT NULL DEFAULT 'ready_for_payout',
            payout_batch VARCHAR(64) NULL,
            note VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_courier_earnings_rest_order_type (restaurant_id, order_id, earning_type),
            KEY idx_courier_earnings_rest_courier_created (restaurant_id, courier_user_id, created_at),
            KEY idx_courier_earnings_rest_payout_status_created (restaurant_id, payout_status, created_at),
            KEY idx_courier_earnings_rest_shift_created (restaurant_id, shift_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ", 'courier_earnings.create');

    $columns = [
        'courier_user_id' => "ALTER TABLE courier_earnings ADD COLUMN courier_user_id INT NOT NULL",
        'restaurant_id' => "ALTER TABLE courier_earnings ADD COLUMN restaurant_id INT NOT NULL",
        'order_id' => "ALTER TABLE courier_earnings ADD COLUMN order_id INT NULL",
        'shift_id' => "ALTER TABLE courier_earnings ADD COLUMN shift_id INT NULL",
        'base_amount' => "ALTER TABLE courier_earnings ADD COLUMN base_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00",
        'bonus_amount' => "ALTER TABLE courier_earnings ADD COLUMN bonus_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00",
        'total_amount' => "ALTER TABLE courier_earnings ADD COLUMN total_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00",
        'earning_type' => "ALTER TABLE courier_earnings ADD COLUMN earning_type VARCHAR(32) NOT NULL DEFAULT 'delivery'",
        'payout_status' => "ALTER TABLE courier_earnings ADD COLUMN payout_status VARCHAR(32) NOT NULL DEFAULT 'ready_for_payout'",
        'payout_batch' => "ALTER TABLE courier_earnings ADD COLUMN payout_batch VARCHAR(64) NULL",
        'note' => "ALTER TABLE courier_earnings ADD COLUMN note VARCHAR(255) NULL",
        'created_at' => "ALTER TABLE courier_earnings ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",
        'updated_at' => "ALTER TABLE courier_earnings ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
    ];
    foreach ($columns as $column => $sql) {
        if (function_exists('db_column_exists') && db_column_exists('courier_earnings', $column)) {
            continue;
        }
        runtime_schema_bootstrap_exec($pdo, $sql, 'courier_earnings.' . $column . '.add');
    }

    runtime_schema_bootstrap_exec(
        $pdo,
        "CREATE UNIQUE INDEX uniq_courier_earnings_rest_order_type ON courier_earnings (restaurant_id, order_id, earning_type)",
        'courier_earnings.idx.uniq_rest_order_type'
    );
    runtime_schema_bootstrap_exec(
        $pdo,
        "CREATE INDEX idx_courier_earnings_rest_courier_created ON courier_earnings (restaurant_id, courier_user_id, created_at)",
        'courier_earnings.idx.rest_courier_created'
    );
    runtime_schema_bootstrap_exec(
        $pdo,
        "CREATE INDEX idx_courier_earnings_rest_payout_status_created ON courier_earnings (restaurant_id, payout_status, created_at)",
        'courier_earnings.idx.rest_payout_status_created'
    );
    runtime_schema_bootstrap_exec(
        $pdo,
        "CREATE INDEX idx_courier_earnings_rest_shift_created ON courier_earnings (restaurant_id, shift_id, created_at)",
        'courier_earnings.idx.rest_shift_created'
    );
}

function runtime_schema_ensure_delivery_zones(PDO $pdo): void
{
    runtime_schema_bootstrap_exec($pdo, "
        CREATE TABLE IF NOT EXISTS delivery_zones (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            restaurant_id INT NOT NULL,
            zone_key VARCHAR(32) NOT NULL,
            zone_name VARCHAR(96) NOT NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            priority INT NOT NULL DEFAULT 100,
            avg_eta_minutes INT NOT NULL DEFAULT 35,
            delivery_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            free_delivery_from DECIMAL(10,2) NULL,
            color VARCHAR(16) NULL,
            match_keywords TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_delivery_zones_rest_key (restaurant_id, zone_key),
            KEY idx_delivery_zones_rest_active_priority (restaurant_id, active, priority)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ", 'delivery_zones.create');

    $columns = [
        'restaurant_id' => "ALTER TABLE delivery_zones ADD COLUMN restaurant_id INT NOT NULL",
        'zone_key' => "ALTER TABLE delivery_zones ADD COLUMN zone_key VARCHAR(32) NOT NULL",
        'zone_name' => "ALTER TABLE delivery_zones ADD COLUMN zone_name VARCHAR(96) NOT NULL",
        'active' => "ALTER TABLE delivery_zones ADD COLUMN active TINYINT(1) NOT NULL DEFAULT 1",
        'priority' => "ALTER TABLE delivery_zones ADD COLUMN priority INT NOT NULL DEFAULT 100",
        'avg_eta_minutes' => "ALTER TABLE delivery_zones ADD COLUMN avg_eta_minutes INT NOT NULL DEFAULT 35",
        'delivery_fee' => "ALTER TABLE delivery_zones ADD COLUMN delivery_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00",
        'free_delivery_from' => "ALTER TABLE delivery_zones ADD COLUMN free_delivery_from DECIMAL(10,2) NULL",
        'color' => "ALTER TABLE delivery_zones ADD COLUMN color VARCHAR(16) NULL",
        'match_keywords' => "ALTER TABLE delivery_zones ADD COLUMN match_keywords TEXT NULL",
        'created_at' => "ALTER TABLE delivery_zones ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",
        'updated_at' => "ALTER TABLE delivery_zones ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
    ];
    foreach ($columns as $column => $sql) {
        if (function_exists('db_column_exists') && db_column_exists('delivery_zones', $column)) {
            continue;
        }
        runtime_schema_bootstrap_exec($pdo, $sql, 'delivery_zones.' . $column . '.add');
    }

    runtime_schema_bootstrap_exec(
        $pdo,
        "CREATE UNIQUE INDEX uniq_delivery_zones_rest_key ON delivery_zones (restaurant_id, zone_key)",
        'delivery_zones.idx.uniq_rest_key'
    );
    runtime_schema_bootstrap_exec(
        $pdo,
        "CREATE INDEX idx_delivery_zones_rest_active_priority ON delivery_zones (restaurant_id, active, priority)",
        'delivery_zones.idx.rest_active_priority'
    );

    if (!(function_exists('db_column_exists') && db_column_exists('orders', 'delivery_zone_key'))) {
        runtime_schema_bootstrap_exec(
            $pdo,
            "ALTER TABLE orders ADD COLUMN delivery_zone_key VARCHAR(32) NULL",
            'orders.delivery_zone_key.add'
        );
    }
    runtime_schema_bootstrap_exec(
        $pdo,
        "CREATE INDEX idx_orders_rest_zone_created ON orders (restaurant_id, delivery_zone_key, created_at)",
        'orders.delivery_zone_key.idx_rest_zone_created'
    );
}

function runtime_schema_ensure_courier_locations(PDO $pdo): void
{
    runtime_schema_bootstrap_exec($pdo, "
        CREATE TABLE IF NOT EXISTS courier_locations (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            restaurant_id INT NOT NULL,
            order_id INT NOT NULL,
            courier_user_id INT NOT NULL,
            lat DECIMAL(10,7) NOT NULL,
            lng DECIMAL(10,7) NOT NULL,
            accuracy DECIMAL(8,2) NULL,
            speed DECIMAL(8,2) NULL,
            heading DECIMAL(8,2) NULL,
            battery_level TINYINT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_courier_locations_rest_order_courier (restaurant_id, order_id, courier_user_id),
            KEY idx_courier_locations_rest_order (restaurant_id, order_id),
            KEY idx_courier_locations_rest_courier (restaurant_id, courier_user_id),
            KEY idx_courier_locations_updated_at (updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ", 'courier_locations.create');

    $columns = [
        'restaurant_id' => "ALTER TABLE courier_locations ADD COLUMN restaurant_id INT NOT NULL",
        'order_id' => "ALTER TABLE courier_locations ADD COLUMN order_id INT NOT NULL",
        'courier_user_id' => "ALTER TABLE courier_locations ADD COLUMN courier_user_id INT NOT NULL",
        'lat' => "ALTER TABLE courier_locations ADD COLUMN lat DECIMAL(10,7) NOT NULL",
        'lng' => "ALTER TABLE courier_locations ADD COLUMN lng DECIMAL(10,7) NOT NULL",
        'accuracy' => "ALTER TABLE courier_locations ADD COLUMN accuracy DECIMAL(8,2) NULL",
        'speed' => "ALTER TABLE courier_locations ADD COLUMN speed DECIMAL(8,2) NULL",
        'heading' => "ALTER TABLE courier_locations ADD COLUMN heading DECIMAL(8,2) NULL",
        'battery_level' => "ALTER TABLE courier_locations ADD COLUMN battery_level TINYINT UNSIGNED NULL",
        'created_at' => "ALTER TABLE courier_locations ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",
        'updated_at' => "ALTER TABLE courier_locations ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
    ];
    foreach ($columns as $column => $sql) {
        if (function_exists('db_column_exists') && db_column_exists('courier_locations', $column)) {
            continue;
        }
        runtime_schema_bootstrap_exec($pdo, $sql, 'courier_locations.' . $column . '.add');
    }

    runtime_schema_bootstrap_exec(
        $pdo,
        "CREATE UNIQUE INDEX uniq_courier_locations_rest_order_courier ON courier_locations (restaurant_id, order_id, courier_user_id)",
        'courier_locations.idx.uniq_rest_order_courier'
    );
    runtime_schema_bootstrap_exec(
        $pdo,
        "CREATE INDEX idx_courier_locations_rest_order ON courier_locations (restaurant_id, order_id)",
        'courier_locations.idx.rest_order'
    );
    runtime_schema_bootstrap_exec(
        $pdo,
        "CREATE INDEX idx_courier_locations_rest_courier ON courier_locations (restaurant_id, courier_user_id)",
        'courier_locations.idx.rest_courier'
    );
    runtime_schema_bootstrap_exec(
        $pdo,
        "CREATE INDEX idx_courier_locations_updated_at ON courier_locations (updated_at)",
        'courier_locations.idx.updated_at'
    );
}

function runtime_schema_ensure_orders_promo_meta(PDO $pdo): void
{
    $columns = [
        'promo_id' => "ALTER TABLE orders ADD COLUMN promo_id INT UNSIGNED NULL",
        'promo_code' => "ALTER TABLE orders ADD COLUMN promo_code VARCHAR(64) NULL",
        'promo_discount' => "ALTER TABLE orders ADD COLUMN promo_discount DECIMAL(10,2) NOT NULL DEFAULT 0.00",
    ];
    foreach ($columns as $column => $sql) {
        if (function_exists('db_column_exists') && db_column_exists('orders', $column)) {
            continue;
        }
        runtime_schema_bootstrap_exec($pdo, $sql, 'orders.' . $column . '.add');
    }
    runtime_schema_bootstrap_exec(
        $pdo,
        "CREATE INDEX idx_orders_rest_promo_created ON orders (restaurant_id, promo_id, created_at)",
        'orders.promo.idx_rest_promo_created'
    );
}

function runtime_schema_ensure_restaurant_promotions(PDO $pdo): void
{
    runtime_schema_bootstrap_exec($pdo, "
        CREATE TABLE IF NOT EXISTS restaurant_promotions (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            restaurant_id INT NOT NULL,
            code VARCHAR(64) NOT NULL,
            title VARCHAR(190) NULL,
            description TEXT NULL,
            discount_type VARCHAR(16) NOT NULL DEFAULT 'percent',
            discount_value DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            min_order_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            valid_from DATETIME NULL,
            valid_until DATETIME NULL,
            usage_limit_total INT UNSIGNED NULL,
            usage_limit_per_guest INT UNSIGNED NULL,
            used_count INT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_restaurant_promotions_rest_code (restaurant_id, code),
            KEY idx_restaurant_promotions_active_window (restaurant_id, is_active, valid_from, valid_until),
            KEY idx_restaurant_promotions_code (code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ", 'restaurant_promotions.create');

    $columns = [
        'restaurant_id' => "ALTER TABLE restaurant_promotions ADD COLUMN restaurant_id INT NOT NULL",
        'code' => "ALTER TABLE restaurant_promotions ADD COLUMN code VARCHAR(64) NOT NULL",
        'title' => "ALTER TABLE restaurant_promotions ADD COLUMN title VARCHAR(190) NULL",
        'description' => "ALTER TABLE restaurant_promotions ADD COLUMN description TEXT NULL",
        'discount_type' => "ALTER TABLE restaurant_promotions ADD COLUMN discount_type VARCHAR(16) NOT NULL DEFAULT 'percent'",
        'discount_value' => "ALTER TABLE restaurant_promotions ADD COLUMN discount_value DECIMAL(10,2) NOT NULL DEFAULT 0.00",
        'min_order_amount' => "ALTER TABLE restaurant_promotions ADD COLUMN min_order_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00",
        'is_active' => "ALTER TABLE restaurant_promotions ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1",
        'valid_from' => "ALTER TABLE restaurant_promotions ADD COLUMN valid_from DATETIME NULL",
        'valid_until' => "ALTER TABLE restaurant_promotions ADD COLUMN valid_until DATETIME NULL",
        'usage_limit_total' => "ALTER TABLE restaurant_promotions ADD COLUMN usage_limit_total INT UNSIGNED NULL",
        'usage_limit_per_guest' => "ALTER TABLE restaurant_promotions ADD COLUMN usage_limit_per_guest INT UNSIGNED NULL",
        'used_count' => "ALTER TABLE restaurant_promotions ADD COLUMN used_count INT UNSIGNED NOT NULL DEFAULT 0",
        'created_at' => "ALTER TABLE restaurant_promotions ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",
        'updated_at' => "ALTER TABLE restaurant_promotions ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
    ];
    foreach ($columns as $column => $sql) {
        if (function_exists('db_column_exists') && db_column_exists('restaurant_promotions', $column)) {
            continue;
        }
        runtime_schema_bootstrap_exec($pdo, $sql, 'restaurant_promotions.' . $column . '.add');
    }

    runtime_schema_bootstrap_exec(
        $pdo,
        "CREATE UNIQUE INDEX uniq_restaurant_promotions_rest_code ON restaurant_promotions (restaurant_id, code)",
        'restaurant_promotions.idx.uniq_rest_code'
    );
    runtime_schema_bootstrap_exec(
        $pdo,
        "CREATE INDEX idx_restaurant_promotions_active_window ON restaurant_promotions (restaurant_id, is_active, valid_from, valid_until)",
        'restaurant_promotions.idx.active_window'
    );
    runtime_schema_bootstrap_exec(
        $pdo,
        "CREATE INDEX idx_restaurant_promotions_code ON restaurant_promotions (code)",
        'restaurant_promotions.idx.code'
    );

    runtime_schema_bootstrap_exec($pdo, "
        CREATE TABLE IF NOT EXISTS restaurant_promo_usages (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            promotion_id INT UNSIGNED NOT NULL,
            restaurant_id INT NOT NULL,
            order_id INT NULL,
            guest_profile_id INT UNSIGNED NULL,
            phone_normalized VARCHAR(16) NULL,
            used_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_restaurant_promo_usage_order (promotion_id, order_id),
            KEY idx_restaurant_promo_usages_rest_promo (restaurant_id, promotion_id),
            KEY idx_restaurant_promo_usages_phone (restaurant_id, phone_normalized),
            KEY idx_restaurant_promo_usages_profile (restaurant_id, guest_profile_id),
            KEY idx_restaurant_promo_usages_used_at (used_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ", 'restaurant_promo_usages.create');

    $usageColumns = [
        'promotion_id' => "ALTER TABLE restaurant_promo_usages ADD COLUMN promotion_id INT UNSIGNED NOT NULL",
        'restaurant_id' => "ALTER TABLE restaurant_promo_usages ADD COLUMN restaurant_id INT NOT NULL",
        'order_id' => "ALTER TABLE restaurant_promo_usages ADD COLUMN order_id INT NULL",
        'guest_profile_id' => "ALTER TABLE restaurant_promo_usages ADD COLUMN guest_profile_id INT UNSIGNED NULL",
        'phone_normalized' => "ALTER TABLE restaurant_promo_usages ADD COLUMN phone_normalized VARCHAR(16) NULL",
        'used_at' => "ALTER TABLE restaurant_promo_usages ADD COLUMN used_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",
    ];
    foreach ($usageColumns as $column => $sql) {
        if (function_exists('db_column_exists') && db_column_exists('restaurant_promo_usages', $column)) {
            continue;
        }
        runtime_schema_bootstrap_exec($pdo, $sql, 'restaurant_promo_usages.' . $column . '.add');
    }

    runtime_schema_bootstrap_exec(
        $pdo,
        "CREATE UNIQUE INDEX uniq_restaurant_promo_usage_order ON restaurant_promo_usages (promotion_id, order_id)",
        'restaurant_promo_usages.idx.uniq_order'
    );
    runtime_schema_bootstrap_exec(
        $pdo,
        "CREATE INDEX idx_restaurant_promo_usages_rest_promo ON restaurant_promo_usages (restaurant_id, promotion_id)",
        'restaurant_promo_usages.idx.rest_promo'
    );
    runtime_schema_bootstrap_exec(
        $pdo,
        "CREATE INDEX idx_restaurant_promo_usages_phone ON restaurant_promo_usages (restaurant_id, phone_normalized)",
        'restaurant_promo_usages.idx.phone'
    );
    runtime_schema_bootstrap_exec(
        $pdo,
        "CREATE INDEX idx_restaurant_promo_usages_profile ON restaurant_promo_usages (restaurant_id, guest_profile_id)",
        'restaurant_promo_usages.idx.profile'
    );
    runtime_schema_bootstrap_exec(
        $pdo,
        "CREATE INDEX idx_restaurant_promo_usages_used_at ON restaurant_promo_usages (used_at)",
        'restaurant_promo_usages.idx.used_at'
    );
}

function runtime_schema_ensure_guest_reviews(PDO $pdo): void
{
    runtime_schema_bootstrap_exec($pdo, "
        CREATE TABLE IF NOT EXISTS guest_reviews (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            restaurant_id INT NOT NULL,
            order_id INT NOT NULL,
            guest_profile_id INT UNSIGNED NULL,
            phone_normalized VARCHAR(16) NULL,
            rating TINYINT UNSIGNED NOT NULL,
            nps_score TINYINT UNSIGNED NULL,
            review_text TEXT NULL,
            review_tags TEXT NULL,
            source VARCHAR(32) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_guest_reviews_order (order_id),
            KEY idx_guest_reviews_restaurant_created (restaurant_id, created_at),
            KEY idx_guest_reviews_restaurant_rating (restaurant_id, rating),
            KEY idx_guest_reviews_restaurant_phone (restaurant_id, phone_normalized),
            KEY idx_guest_reviews_restaurant_guest_profile (restaurant_id, guest_profile_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ", 'guest_reviews.create');

    $columns = [
        'restaurant_id' => "ALTER TABLE guest_reviews ADD COLUMN restaurant_id INT NOT NULL",
        'order_id' => "ALTER TABLE guest_reviews ADD COLUMN order_id INT NOT NULL",
        'guest_profile_id' => "ALTER TABLE guest_reviews ADD COLUMN guest_profile_id INT UNSIGNED NULL",
        'phone_normalized' => "ALTER TABLE guest_reviews ADD COLUMN phone_normalized VARCHAR(16) NULL",
        'rating' => "ALTER TABLE guest_reviews ADD COLUMN rating TINYINT UNSIGNED NOT NULL",
        'nps_score' => "ALTER TABLE guest_reviews ADD COLUMN nps_score TINYINT UNSIGNED NULL",
        'review_text' => "ALTER TABLE guest_reviews ADD COLUMN review_text TEXT NULL",
        'review_tags' => "ALTER TABLE guest_reviews ADD COLUMN review_tags TEXT NULL",
        'source' => "ALTER TABLE guest_reviews ADD COLUMN source VARCHAR(32) NULL",
        'created_at' => "ALTER TABLE guest_reviews ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",
        'updated_at' => "ALTER TABLE guest_reviews ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
    ];
    foreach ($columns as $column => $sql) {
        if (function_exists('db_column_exists') && db_column_exists('guest_reviews', $column)) {
            continue;
        }
        runtime_schema_bootstrap_exec($pdo, $sql, 'guest_reviews.' . $column . '.add');
    }

    runtime_schema_bootstrap_exec(
        $pdo,
        "CREATE UNIQUE INDEX uniq_guest_reviews_order ON guest_reviews (order_id)",
        'guest_reviews.idx.uniq_order'
    );
    runtime_schema_bootstrap_exec(
        $pdo,
        "CREATE INDEX idx_guest_reviews_restaurant_created ON guest_reviews (restaurant_id, created_at)",
        'guest_reviews.idx.rest_created'
    );
    runtime_schema_bootstrap_exec(
        $pdo,
        "CREATE INDEX idx_guest_reviews_restaurant_rating ON guest_reviews (restaurant_id, rating)",
        'guest_reviews.idx.rest_rating'
    );
    runtime_schema_bootstrap_exec(
        $pdo,
        "CREATE INDEX idx_guest_reviews_restaurant_phone ON guest_reviews (restaurant_id, phone_normalized)",
        'guest_reviews.idx.rest_phone'
    );
    runtime_schema_bootstrap_exec(
        $pdo,
        "CREATE INDEX idx_guest_reviews_restaurant_guest_profile ON guest_reviews (restaurant_id, guest_profile_id)",
        'guest_reviews.idx.rest_guest_profile'
    );
}

function runtime_schema_ensure_order_tips(PDO $pdo): void
{
    runtime_schema_bootstrap_exec($pdo, "
        CREATE TABLE IF NOT EXISTS order_tips (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            restaurant_id INT NOT NULL,
            order_id INT NOT NULL,
            staff_user_id INT NULL,
            courier_user_id INT NULL,
            guest_profile_id INT UNSIGNED NULL,
            phone_normalized VARCHAR(16) NULL,
            amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            currency VARCHAR(8) NOT NULL DEFAULT 'RUB',
            source VARCHAR(32) NULL,
            status VARCHAR(16) NOT NULL DEFAULT 'pending',
            note VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_order_tips_restaurant_created (restaurant_id, created_at),
            KEY idx_order_tips_restaurant_status (restaurant_id, status),
            KEY idx_order_tips_restaurant_order (restaurant_id, order_id),
            KEY idx_order_tips_restaurant_staff (restaurant_id, staff_user_id),
            KEY idx_order_tips_restaurant_courier (restaurant_id, courier_user_id),
            KEY idx_order_tips_restaurant_phone (restaurant_id, phone_normalized)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ", 'order_tips.create');

    $columns = [
        'restaurant_id' => "ALTER TABLE order_tips ADD COLUMN restaurant_id INT NOT NULL",
        'order_id' => "ALTER TABLE order_tips ADD COLUMN order_id INT NOT NULL",
        'staff_user_id' => "ALTER TABLE order_tips ADD COLUMN staff_user_id INT NULL",
        'courier_user_id' => "ALTER TABLE order_tips ADD COLUMN courier_user_id INT NULL",
        'guest_profile_id' => "ALTER TABLE order_tips ADD COLUMN guest_profile_id INT UNSIGNED NULL",
        'phone_normalized' => "ALTER TABLE order_tips ADD COLUMN phone_normalized VARCHAR(16) NULL",
        'amount' => "ALTER TABLE order_tips ADD COLUMN amount DECIMAL(10,2) NOT NULL DEFAULT 0.00",
        'currency' => "ALTER TABLE order_tips ADD COLUMN currency VARCHAR(8) NOT NULL DEFAULT 'RUB'",
        'source' => "ALTER TABLE order_tips ADD COLUMN source VARCHAR(32) NULL",
        'status' => "ALTER TABLE order_tips ADD COLUMN status VARCHAR(16) NOT NULL DEFAULT 'pending'",
        'note' => "ALTER TABLE order_tips ADD COLUMN note VARCHAR(255) NULL",
        'created_at' => "ALTER TABLE order_tips ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",
        'updated_at' => "ALTER TABLE order_tips ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
    ];
    foreach ($columns as $column => $sql) {
        if (function_exists('db_column_exists') && db_column_exists('order_tips', $column)) {
            continue;
        }
        runtime_schema_bootstrap_exec($pdo, $sql, 'order_tips.' . $column . '.add');
    }

    runtime_schema_bootstrap_exec(
        $pdo,
        "CREATE INDEX idx_order_tips_restaurant_created ON order_tips (restaurant_id, created_at)",
        'order_tips.idx.rest_created'
    );
    runtime_schema_bootstrap_exec(
        $pdo,
        "CREATE INDEX idx_order_tips_restaurant_status ON order_tips (restaurant_id, status)",
        'order_tips.idx.rest_status'
    );
    runtime_schema_bootstrap_exec(
        $pdo,
        "CREATE INDEX idx_order_tips_restaurant_order ON order_tips (restaurant_id, order_id)",
        'order_tips.idx.rest_order'
    );
    runtime_schema_bootstrap_exec(
        $pdo,
        "CREATE INDEX idx_order_tips_restaurant_staff ON order_tips (restaurant_id, staff_user_id)",
        'order_tips.idx.rest_staff'
    );
    runtime_schema_bootstrap_exec(
        $pdo,
        "CREATE INDEX idx_order_tips_restaurant_courier ON order_tips (restaurant_id, courier_user_id)",
        'order_tips.idx.rest_courier'
    );
    runtime_schema_bootstrap_exec(
        $pdo,
        "CREATE INDEX idx_order_tips_restaurant_phone ON order_tips (restaurant_id, phone_normalized)",
        'order_tips.idx.rest_phone'
    );
}

function runtime_schema_ensure_table_reservations(PDO $pdo): void
{
    runtime_schema_bootstrap_exec($pdo, "
        CREATE TABLE IF NOT EXISTS table_reservations (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            restaurant_id INT NOT NULL,
            table_id INT NOT NULL,
            guest_profile_id INT UNSIGNED NULL,
            phone_normalized VARCHAR(16) NULL,
            guest_name VARCHAR(190) NULL,
            guest_phone VARCHAR(32) NULL,
            guests_count INT UNSIGNED NOT NULL DEFAULT 1,
            reservation_date DATE NULL,
            reservation_time TIME NULL,
            reservation_datetime DATETIME NOT NULL,
            duration_minutes INT UNSIGNED NOT NULL DEFAULT 120,
            status VARCHAR(24) NOT NULL DEFAULT 'pending',
            source VARCHAR(32) NULL,
            comment VARCHAR(500) NULL,
            created_by_user_id INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_table_reservations_rest_datetime (restaurant_id, reservation_datetime),
            KEY idx_table_reservations_rest_table_datetime (restaurant_id, table_id, reservation_datetime),
            KEY idx_table_reservations_rest_status_datetime (restaurant_id, status, reservation_datetime),
            KEY idx_table_reservations_rest_phone (restaurant_id, phone_normalized)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ", 'table_reservations.create');

    $columns = [
        'restaurant_id' => "ALTER TABLE table_reservations ADD COLUMN restaurant_id INT NOT NULL",
        'table_id' => "ALTER TABLE table_reservations ADD COLUMN table_id INT NOT NULL",
        'guest_profile_id' => "ALTER TABLE table_reservations ADD COLUMN guest_profile_id INT UNSIGNED NULL",
        'phone_normalized' => "ALTER TABLE table_reservations ADD COLUMN phone_normalized VARCHAR(16) NULL",
        'guest_name' => "ALTER TABLE table_reservations ADD COLUMN guest_name VARCHAR(190) NULL",
        'guest_phone' => "ALTER TABLE table_reservations ADD COLUMN guest_phone VARCHAR(32) NULL",
        'guests_count' => "ALTER TABLE table_reservations ADD COLUMN guests_count INT UNSIGNED NOT NULL DEFAULT 1",
        'reservation_date' => "ALTER TABLE table_reservations ADD COLUMN reservation_date DATE NULL",
        'reservation_time' => "ALTER TABLE table_reservations ADD COLUMN reservation_time TIME NULL",
        'reservation_datetime' => "ALTER TABLE table_reservations ADD COLUMN reservation_datetime DATETIME NOT NULL",
        'duration_minutes' => "ALTER TABLE table_reservations ADD COLUMN duration_minutes INT UNSIGNED NOT NULL DEFAULT 120",
        'status' => "ALTER TABLE table_reservations ADD COLUMN status VARCHAR(24) NOT NULL DEFAULT 'pending'",
        'source' => "ALTER TABLE table_reservations ADD COLUMN source VARCHAR(32) NULL",
        'comment' => "ALTER TABLE table_reservations ADD COLUMN comment VARCHAR(500) NULL",
        'created_by_user_id' => "ALTER TABLE table_reservations ADD COLUMN created_by_user_id INT NULL",
        'created_at' => "ALTER TABLE table_reservations ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",
        'updated_at' => "ALTER TABLE table_reservations ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
    ];
    foreach ($columns as $column => $sql) {
        if (function_exists('db_column_exists') && db_column_exists('table_reservations', $column)) {
            continue;
        }
        runtime_schema_bootstrap_exec($pdo, $sql, 'table_reservations.' . $column . '.add');
    }

    runtime_schema_bootstrap_exec(
        $pdo,
        "CREATE INDEX idx_table_reservations_rest_datetime ON table_reservations (restaurant_id, reservation_datetime)",
        'table_reservations.idx.rest_datetime'
    );
    runtime_schema_bootstrap_exec(
        $pdo,
        "CREATE INDEX idx_table_reservations_rest_table_datetime ON table_reservations (restaurant_id, table_id, reservation_datetime)",
        'table_reservations.idx.rest_table_datetime'
    );
    runtime_schema_bootstrap_exec(
        $pdo,
        "CREATE INDEX idx_table_reservations_rest_status_datetime ON table_reservations (restaurant_id, status, reservation_datetime)",
        'table_reservations.idx.rest_status_datetime'
    );
    runtime_schema_bootstrap_exec(
        $pdo,
        "CREATE INDEX idx_table_reservations_rest_phone ON table_reservations (restaurant_id, phone_normalized)",
        'table_reservations.idx.rest_phone'
    );
}
