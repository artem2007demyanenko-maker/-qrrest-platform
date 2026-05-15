<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
if (file_exists(__DIR__ . '/schema_guard.php')) {
    require_once __DIR__ . '/schema_guard.php';
}

function kds_allowed_stations(): array
{
    return ['kitchen', 'bar', 'cold', 'dessert', 'hookah', 'grill', 'pizza', 'sushi', 'custom'];
}

function kds_allowed_item_statuses(): array
{
    return ['new', 'accepted', 'cooking', 'ready', 'served'];
}

function kds_normalize_item_status(?string $status): string
{
    $raw = strtolower(trim((string)$status));
    if ($raw === 'preparing') {
        $raw = 'cooking';
    }
    return in_array($raw, kds_allowed_item_statuses(), true) ? $raw : 'new';
}

function kds_station_label_ru(string $station): string
{
    $map = [
        'cold' => 'Холодный цех',
        'bar' => 'Бар',
        'dessert' => 'Десерты',
        'hookah' => 'Кальян',
        'grill' => 'Гриль',
        'pizza' => 'Пицца',
        'sushi' => 'Суши',
        'custom' => 'Станция',
        'kitchen' => 'Кухня',
    ];
    $s = kds_normalize_station($station);
    return $map[$s] ?? 'Кухня';
}

function kds_normalize_station(string $station): string
{
    $s = strtolower(trim($station));
    $map = [
        'hot' => 'kitchen',
        'cold' => 'cold',
        'bar' => 'bar',
        'dessert' => 'dessert',
        'desserts' => 'dessert',
        'hookah' => 'hookah',
        'grill' => 'grill',
        'pizza' => 'pizza',
        'sushi' => 'sushi',
        'custom' => 'custom',
        'kitchen' => 'kitchen',
        'general' => 'kitchen',
        'main' => 'kitchen',
        'горячий' => 'hot',
        'холодный' => 'cold',
        'гриль' => 'grill',
        'пицца' => 'pizza',
        'суши' => 'sushi',
        'кальян' => 'hookah',
        'hooka' => 'hookah',
        'станция' => 'custom',
        'кухня' => 'kitchen',
        'all' => 'all',
    ];
    if (!isset($map[$s]) && preg_match('/^custom(?:[_-][a-z0-9]+)?$/', $s)) {
        return 'custom';
    }
    $s = $map[$s] ?? $s;
    if ($s === 'all') {
        return 'all';
    }
    return in_array($s, kds_allowed_stations(), true) ? $s : 'kitchen';
}

function kds_to_legacy_kitchen_station(string $station): string
{
    $s = kds_normalize_station($station);
    return $s === 'kitchen' ? 'HOT' : strtoupper($s);
}

function kds_station_sql_normalize_expr(string $rawExpr): string
{
    return "CASE
        WHEN {$rawExpr} IN ('', 'hot', 'kitchen', 'general', 'main') THEN 'kitchen'
        WHEN {$rawExpr} IN ('desserts') THEN 'dessert'
        ELSE {$rawExpr}
    END";
}

function kds_station_sql_prefer_explicit_expr(array $sourceExprs): string
{
    $explicitParts = [];
    $fallbackParts = [];
    foreach ($sourceExprs as $expr) {
        $expr = trim((string)$expr);
        if ($expr === '') {
            continue;
        }
        $explicitParts[] = "NULLIF(CASE WHEN {$expr} NOT IN ('', 'hot', 'kitchen', 'general', 'main') THEN {$expr} ELSE '' END, '')";
        $fallbackParts[] = "NULLIF({$expr}, '')";
    }

    return "LOWER(COALESCE(" . implode(",\n        ", array_merge($explicitParts, $fallbackParts, ["'kitchen'"])) . '))';
}

function kds_item_station_sql_expr(PDO $pdo, string $itemAlias = 'oi', string $menuAlias = 'mi'): string
{
    $hasOrderProd = function_exists('db_column_exists') && db_column_exists('order_items', 'production_station');
    $hasOrderStation = function_exists('db_column_exists') && db_column_exists('order_items', 'station');
    $hasMenuProd = function_exists('db_column_exists') && db_column_exists('menu_items', 'production_station');
    $hasMenuStation = function_exists('db_column_exists') && db_column_exists('menu_items', 'station');
    $hasMenuLegacy = function_exists('db_column_exists') && db_column_exists('menu_items', 'kitchen_station');

    $sources = [];
    if ($hasOrderProd) {
        $sources[] = "LOWER(COALESCE(TRIM({$itemAlias}.production_station), ''))";
    }
    if ($hasOrderStation) {
        $sources[] = "LOWER(COALESCE(TRIM({$itemAlias}.station), ''))";
    }
    if ($hasMenuProd) {
        $sources[] = "LOWER(COALESCE(TRIM({$menuAlias}.production_station), ''))";
    }
    if ($hasMenuStation) {
        $sources[] = "LOWER(COALESCE(TRIM({$menuAlias}.station), ''))";
    }
    if ($hasMenuLegacy) {
        $sources[] = "LOWER(COALESCE(TRIM({$menuAlias}.kitchen_station), ''))";
    }

    return kds_station_sql_normalize_expr(kds_station_sql_prefer_explicit_expr($sources));
}

function kds_ensure_schema(PDO $pdo): void
{
    static $logged = false;

    if (!function_exists('db_column_exists')) {
        return;
    }

    kitchen_station_storage_ensure($pdo);
    kds_runtime_schema_ensure_columns($pdo);

    if ($logged) {
        return;
    }

    $missing = [];
    if (!db_column_exists('menu_items', 'station')) {
        $missing[] = 'menu_items.station';
    }
    if (function_exists('db_table_exists') && db_table_exists('staff_users') && !db_column_exists('staff_users', 'station')) {
        $missing[] = 'staff_users.station';
    }
    if (function_exists('db_table_exists') && db_table_exists('users_restaurants') && !db_column_exists('users_restaurants', 'station')) {
        $missing[] = 'users_restaurants.station';
    }
    if (!db_column_exists('menu_items', 'production_station')) {
        $missing[] = 'menu_items.production_station';
    }
    if (!db_column_exists('order_items', 'station_status')) {
        $missing[] = 'order_items.station_status';
    }
    if (!db_column_exists('order_items', 'kds_status')) {
        $missing[] = 'order_items.kds_status';
    }
    if (!db_column_exists('order_items', 'production_station')) {
        $missing[] = 'order_items.production_station';
    }
    if (!db_column_exists('order_items', 'started_at')) {
        $missing[] = 'order_items.started_at';
    }
    if (!db_column_exists('order_items', 'ready_at')) {
        $missing[] = 'order_items.ready_at';
    }
    if (!db_column_exists('order_items', 'kds_started_at')) {
        $missing[] = 'order_items.kds_started_at';
    }
    if (!db_column_exists('order_items', 'kds_ready_at')) {
        $missing[] = 'order_items.kds_ready_at';
    }
    if (!db_column_exists('order_items', 'station_completed_at')) {
        $missing[] = 'order_items.station_completed_at';
    }
    if (db_table_exists('menu_items') && !db_column_exists('menu_items', 'is_temporarily_unavailable')) {
        $missing[] = 'menu_items.is_temporarily_unavailable';
    }

    if ($missing !== []) {
        $logged = true;
        error_log('KDS_SCHEMA_MISSING apply migrations for: ' . implode(', ', $missing));
    }
}

if (!function_exists('kds_runtime_schema_ensure_columns')) {
    function kds_runtime_schema_ensure_columns(PDO $pdo): void
    {
        static $ensured = false;
        if ($ensured) {
            return;
        }
        $ensured = true;

        if (!function_exists('db_table_exists') || !function_exists('db_column_exists')) {
            return;
        }

        try {
            if (db_table_exists('menu_items') && !db_column_exists('menu_items', 'station')) {
                $pdo->exec("ALTER TABLE menu_items ADD COLUMN station VARCHAR(32) NULL DEFAULT 'hot'");
            }
        } catch (Throwable $e) {
            error_log('KDS_SCHEMA_ENSURE_FAIL menu_items.station ' . $e->getMessage());
        }

        try {
            if (db_table_exists('menu_items') && db_column_exists('menu_items', 'station')) {
                $pdo->exec("
                    UPDATE menu_items
                    SET station = CASE
                        WHEN LOWER(COALESCE(TRIM(station), '')) IN ('', 'kitchen') THEN 'hot'
                        WHEN LOWER(COALESCE(TRIM(station), '')) IN ('desserts') THEN 'dessert'
                        ELSE LOWER(TRIM(station))
                    END
                ");
            }
        } catch (Throwable $e) {
            error_log('KDS_SCHEMA_ENSURE_FAIL menu_items.station.normalize ' . $e->getMessage());
        }

        try {
            if (db_table_exists('staff_users') && !db_column_exists('staff_users', 'station')) {
                $pdo->exec("ALTER TABLE staff_users ADD COLUMN station VARCHAR(32) NULL DEFAULT 'all'");
            }
        } catch (Throwable $e) {
            error_log('KDS_SCHEMA_ENSURE_FAIL staff_users.station ' . $e->getMessage());
        }

        try {
            if (db_table_exists('users_restaurants') && !db_column_exists('users_restaurants', 'station')) {
                $pdo->exec("ALTER TABLE users_restaurants ADD COLUMN station VARCHAR(32) NULL DEFAULT NULL");
            }
        } catch (Throwable $e) {
            error_log('KDS_SCHEMA_ENSURE_FAIL users_restaurants.station ' . $e->getMessage());
        }

        try {
            if (db_table_exists('menu_items') && !db_column_exists('menu_items', 'production_station')) {
                $pdo->exec("ALTER TABLE menu_items ADD COLUMN production_station VARCHAR(32) NOT NULL DEFAULT 'kitchen'");
            }
        } catch (Throwable $e) {
            error_log('KDS_SCHEMA_ENSURE_FAIL menu_items.production_station ' . $e->getMessage());
        }

        try {
            if (db_table_exists('order_items') && !db_column_exists('order_items', 'station_status')) {
                $pdo->exec("ALTER TABLE order_items ADD COLUMN station_status VARCHAR(24) NOT NULL DEFAULT 'new'");
            }
        } catch (Throwable $e) {
            error_log('KDS_SCHEMA_ENSURE_FAIL order_items.station_status ' . $e->getMessage());
        }

        try {
            if (db_table_exists('order_items') && !db_column_exists('order_items', 'kds_status')) {
                $pdo->exec("ALTER TABLE order_items ADD COLUMN kds_status VARCHAR(32) NULL DEFAULT 'new'");
            }
        } catch (Throwable $e) {
            error_log('KDS_SCHEMA_ENSURE_FAIL order_items.kds_status ' . $e->getMessage());
        }

        try {
            if (db_table_exists('order_items') && !db_column_exists('order_items', 'production_station')) {
                $pdo->exec("ALTER TABLE order_items ADD COLUMN production_station VARCHAR(32) NOT NULL DEFAULT 'kitchen'");
            }
        } catch (Throwable $e) {
            error_log('KDS_SCHEMA_ENSURE_FAIL order_items.production_station ' . $e->getMessage());
        }

        try {
            if (db_table_exists('order_items') && !db_column_exists('order_items', 'started_at')) {
                $pdo->exec("ALTER TABLE order_items ADD COLUMN started_at DATETIME NULL");
            }
        } catch (Throwable $e) {
            error_log('KDS_SCHEMA_ENSURE_FAIL order_items.started_at ' . $e->getMessage());
        }

        try {
            if (db_table_exists('order_items') && !db_column_exists('order_items', 'ready_at')) {
                $pdo->exec("ALTER TABLE order_items ADD COLUMN ready_at DATETIME NULL");
            }
        } catch (Throwable $e) {
            error_log('KDS_SCHEMA_ENSURE_FAIL order_items.ready_at ' . $e->getMessage());
        }

        try {
            if (db_table_exists('order_items') && !db_column_exists('order_items', 'station_completed_at')) {
                $pdo->exec("ALTER TABLE order_items ADD COLUMN station_completed_at DATETIME NULL");
            }
        } catch (Throwable $e) {
            error_log('KDS_SCHEMA_ENSURE_FAIL order_items.station_completed_at ' . $e->getMessage());
        }

        try {
            if (db_table_exists('order_items') && !db_column_exists('order_items', 'kds_started_at')) {
                $pdo->exec("ALTER TABLE order_items ADD COLUMN kds_started_at DATETIME NULL");
            }
        } catch (Throwable $e) {
            error_log('KDS_SCHEMA_ENSURE_FAIL order_items.kds_started_at ' . $e->getMessage());
        }

        try {
            if (db_table_exists('order_items') && !db_column_exists('order_items', 'kds_ready_at')) {
                $pdo->exec("ALTER TABLE order_items ADD COLUMN kds_ready_at DATETIME NULL");
            }
        } catch (Throwable $e) {
            error_log('KDS_SCHEMA_ENSURE_FAIL order_items.kds_ready_at ' . $e->getMessage());
        }

        try {
            if (db_table_exists('menu_items') && !db_column_exists('menu_items', 'is_temporarily_unavailable')) {
                $pdo->exec("ALTER TABLE menu_items ADD COLUMN is_temporarily_unavailable TINYINT(1) NOT NULL DEFAULT 0");
            }
        } catch (Throwable $e) {
            error_log('KDS_SCHEMA_ENSURE_FAIL menu_items.is_temporarily_unavailable ' . $e->getMessage());
        }

        try {
            if (db_table_exists('menu_items') && db_column_exists('menu_items', 'is_temporarily_unavailable')) {
                $pdo->exec("UPDATE menu_items SET is_temporarily_unavailable = 0 WHERE is_temporarily_unavailable IS NULL");
            }
        } catch (Throwable $e) {
            error_log('KDS_SCHEMA_ENSURE_FAIL menu_items.is_temporarily_unavailable.normalize ' . $e->getMessage());
        }

        try {
            if (db_table_exists('order_items') && db_column_exists('order_items', 'kds_status') && db_column_exists('order_items', 'station_status')) {
                $pdo->exec("UPDATE order_items SET kds_status = COALESCE(NULLIF(TRIM(kds_status), ''), NULLIF(TRIM(station_status), ''), 'new')");
            }
        } catch (Throwable $e) {
            error_log('KDS_SCHEMA_ENSURE_FAIL order_items.kds_status.backfill ' . $e->getMessage());
        }

        try {
            if (db_table_exists('order_items') && db_column_exists('order_items', 'kds_started_at') && db_column_exists('order_items', 'started_at')) {
                $pdo->exec("UPDATE order_items SET kds_started_at = COALESCE(kds_started_at, started_at)");
            }
        } catch (Throwable $e) {
            error_log('KDS_SCHEMA_ENSURE_FAIL order_items.kds_started_at.backfill ' . $e->getMessage());
        }

        try {
            if (db_table_exists('order_items') && db_column_exists('order_items', 'kds_ready_at') && db_column_exists('order_items', 'ready_at')) {
                $pdo->exec("UPDATE order_items SET kds_ready_at = COALESCE(kds_ready_at, ready_at)");
            }
        } catch (Throwable $e) {
            error_log('KDS_SCHEMA_ENSURE_FAIL order_items.kds_ready_at.backfill ' . $e->getMessage());
        }
    }
}

function kds_item_status_sql_expr(string $alias = 'oi'): string
{
    $hasKdsStatus = function_exists('db_column_exists') && db_column_exists('order_items', 'kds_status');
    $hasStationStatus = function_exists('db_column_exists') && db_column_exists('order_items', 'station_status');
    if ($hasKdsStatus && $hasStationStatus) {
        return "LOWER(COALESCE(NULLIF(TRIM({$alias}.kds_status), ''), NULLIF(TRIM({$alias}.station_status), ''), 'new'))";
    }
    if ($hasKdsStatus) {
        return "LOWER(COALESCE(NULLIF(TRIM({$alias}.kds_status), ''), 'new'))";
    }
    if ($hasStationStatus) {
        return "LOWER(COALESCE(NULLIF(TRIM({$alias}.station_status), ''), 'new'))";
    }
    return "'new'";
}

function kds_item_started_at_sql_expr(string $alias = 'oi'): string
{
    $hasKdsStartedAt = function_exists('db_column_exists') && db_column_exists('order_items', 'kds_started_at');
    $hasStartedAt = function_exists('db_column_exists') && db_column_exists('order_items', 'started_at');
    if ($hasKdsStartedAt && $hasStartedAt) {
        return "COALESCE({$alias}.kds_started_at, {$alias}.started_at)";
    }
    if ($hasKdsStartedAt) {
        return "{$alias}.kds_started_at";
    }
    if ($hasStartedAt) {
        return "{$alias}.started_at";
    }
    return "NULL";
}

function kds_item_ready_at_sql_expr(string $alias = 'oi'): string
{
    $hasKdsReadyAt = function_exists('db_column_exists') && db_column_exists('order_items', 'kds_ready_at');
    $hasReadyAt = function_exists('db_column_exists') && db_column_exists('order_items', 'ready_at');
    if ($hasKdsReadyAt && $hasReadyAt) {
        return "COALESCE({$alias}.kds_ready_at, {$alias}.ready_at)";
    }
    if ($hasKdsReadyAt) {
        return "{$alias}.kds_ready_at";
    }
    if ($hasReadyAt) {
        return "{$alias}.ready_at";
    }
    return "NULL";
}

/**
 * @return array<string,array{station_key:string,station_name:string,active:int,priority:int,color:string,prep_time_avg:int}>
 */
function kitchen_station_defaults(): array
{
    return [
        'kitchen' => ['station_key' => 'kitchen', 'station_name' => 'Кухня', 'active' => 1, 'priority' => 20, 'color' => '#F59E0B', 'prep_time_avg' => 18],
        'cold' => ['station_key' => 'cold', 'station_name' => 'Холодный цех', 'active' => 1, 'priority' => 25, 'color' => '#38BDF8', 'prep_time_avg' => 12],
        'bar' => ['station_key' => 'bar', 'station_name' => 'Бар', 'active' => 1, 'priority' => 30, 'color' => '#34D399', 'prep_time_avg' => 8],
        'dessert' => ['station_key' => 'dessert', 'station_name' => 'Десерты', 'active' => 1, 'priority' => 35, 'color' => '#A78BFA', 'prep_time_avg' => 10],
        'hookah' => ['station_key' => 'hookah', 'station_name' => 'Кальян', 'active' => 1, 'priority' => 37, 'color' => '#22C55E', 'prep_time_avg' => 9],
        'grill' => ['station_key' => 'grill', 'station_name' => 'Гриль', 'active' => 1, 'priority' => 40, 'color' => '#FB7185', 'prep_time_avg' => 20],
        'pizza' => ['station_key' => 'pizza', 'station_name' => 'Пицца', 'active' => 1, 'priority' => 45, 'color' => '#F97316', 'prep_time_avg' => 16],
        'sushi' => ['station_key' => 'sushi', 'station_name' => 'Суши', 'active' => 1, 'priority' => 50, 'color' => '#22D3EE', 'prep_time_avg' => 14],
        'custom' => ['station_key' => 'custom', 'station_name' => 'Станция', 'active' => 1, 'priority' => 90, 'color' => '#94A3B8', 'prep_time_avg' => 15],
    ];
}

function kitchen_station_storage_ensure(PDO $pdo): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }
    $ensured = true;

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS kitchen_stations (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                restaurant_id INT NOT NULL,
                station_key VARCHAR(32) NOT NULL,
                station_name VARCHAR(96) NOT NULL,
                active TINYINT(1) NOT NULL DEFAULT 1,
                priority INT NOT NULL DEFAULT 100,
                color VARCHAR(16) NULL,
                prep_time_avg INT NOT NULL DEFAULT 15,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_kitchen_stations_rest_key (restaurant_id, station_key),
                KEY idx_kitchen_stations_rest_active_priority (restaurant_id, active, priority)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Throwable $e) {
        error_log('KITCHEN_STATIONS_ENSURE_FAIL ' . $e->getMessage());
    }
}

function kitchen_station_seed_defaults(PDO $pdo, int $restaurantId): void
{
    if ($restaurantId <= 0 || !function_exists('db_table_exists') || !db_table_exists('kitchen_stations')) {
        return;
    }
    static $seeded = [];
    if (isset($seeded[$restaurantId])) {
        return;
    }
    $seeded[$restaurantId] = true;

    $defaults = kitchen_station_defaults();
    $stmt = $pdo->prepare("
        INSERT INTO kitchen_stations
            (restaurant_id, station_key, station_name, active, priority, color, prep_time_avg)
        VALUES
            (:restaurant_id, :station_key, :station_name, :active, :priority, :color, :prep_time_avg)
        ON DUPLICATE KEY UPDATE
            station_name = VALUES(station_name),
            active = VALUES(active),
            priority = VALUES(priority),
            color = VALUES(color),
            prep_time_avg = VALUES(prep_time_avg)
    ");

    try {
        foreach ($defaults as $station) {
            $stmt->execute([
                ':restaurant_id' => $restaurantId,
                ':station_key' => (string)$station['station_key'],
                ':station_name' => (string)$station['station_name'],
                ':active' => (int)$station['active'],
                ':priority' => (int)$station['priority'],
                ':color' => (string)$station['color'],
                ':prep_time_avg' => max(1, (int)$station['prep_time_avg']),
            ]);
        }
    } catch (Throwable $e) {
        error_log('KITCHEN_STATIONS_SEED_FAIL rest_id=' . $restaurantId . ' ' . $e->getMessage());
    }
}

/**
 * @return array<string,array{station_key:string,station_name:string,active:int,priority:int,color:string,prep_time_avg:int}>
 */
function kitchen_station_list(PDO $pdo, int $restaurantId): array
{
    kitchen_station_storage_ensure($pdo);
    kitchen_station_seed_defaults($pdo, $restaurantId);

    $defaults = kitchen_station_defaults();
    if ($restaurantId <= 0 || !function_exists('db_table_exists') || !db_table_exists('kitchen_stations')) {
        return $defaults;
    }

    $rows = [];
    try {
        $stmt = $pdo->prepare("
            SELECT station_key, station_name, active, priority, color, prep_time_avg
            FROM kitchen_stations
            WHERE restaurant_id = :rid
            ORDER BY priority ASC, station_name ASC
        ");
        $stmt->execute([':rid' => $restaurantId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('KITCHEN_STATIONS_LIST_FAIL rest_id=' . $restaurantId . ' ' . $e->getMessage());
    }

    if ($rows === []) {
        return $defaults;
    }

    $result = [];
    foreach ($rows as $row) {
        $key = kds_normalize_station((string)($row['station_key'] ?? 'kitchen'));
        if (!in_array($key, kds_allowed_stations(), true)) {
            $key = 'kitchen';
        }
        $default = $defaults[$key] ?? $defaults['kitchen'];
        $result[$key] = [
            'station_key' => $key,
            'station_name' => trim((string)($row['station_name'] ?? '')) !== '' ? (string)$row['station_name'] : (string)$default['station_name'],
            'active' => (int)($row['active'] ?? 1),
            'priority' => (int)($row['priority'] ?? (int)$default['priority']),
            'color' => trim((string)($row['color'] ?? '')) !== '' ? (string)$row['color'] : (string)$default['color'],
            'prep_time_avg' => max(1, (int)($row['prep_time_avg'] ?? (int)$default['prep_time_avg'])),
        ];
    }

    foreach ($defaults as $key => $default) {
        if (!isset($result[$key])) {
            $result[$key] = $default;
        }
    }

    uasort($result, static function (array $a, array $b): int {
        $pa = (int)($a['priority'] ?? 100);
        $pb = (int)($b['priority'] ?? 100);
        if ($pa !== $pb) {
            return $pa <=> $pb;
        }
        return strcmp((string)($a['station_name'] ?? ''), (string)($b['station_name'] ?? ''));
    });

    return $result;
}

/**
 * @param array<string,mixed> $item
 * @param array<string,array{station_key:string,station_name:string,active:int,priority:int,color:string,prep_time_avg:int}> $stationList
 * @return array{station_key:string,station_label:string,fallback_used:bool,route_reason:string}
 */
function kitchen_station_resolve(PDO $pdo, int $restaurantId, array $item, array $stationList = []): array
{
    if ($stationList === []) {
        $stationList = kitchen_station_list($pdo, $restaurantId);
    }
    $active = [];
    foreach ($stationList as $key => $station) {
        if ((int)($station['active'] ?? 1) === 1) {
            $active[$key] = $station;
        }
    }
    if ($active === []) {
        $active = ['kitchen' => kitchen_station_defaults()['kitchen']];
    }

    $directRaw = trim((string)($item['station_key'] ?? $item['station'] ?? $item['kitchen_station'] ?? ''));
    $resolved = kds_resolve_station_for_item($item);
    if ($directRaw !== '') {
        $resolved = kds_normalize_station($directRaw);
    }

    $fallbackUsed = false;
    $reason = 'direct';
    if (!isset($active[$resolved])) {
        $fallbackUsed = true;
        $reason = 'inactive_station';
        if (isset($active['kitchen'])) {
            $resolved = 'kitchen';
        } else {
            $first = array_key_first($active);
            $resolved = is_string($first) ? $first : 'kitchen';
            $reason = 'fallback_first_active';
        }
    }

    $label = (string)($active[$resolved]['station_name'] ?? kds_station_label_ru($resolved));
    return [
        'station_key' => $resolved,
        'station_label' => $label,
        'fallback_used' => $fallbackUsed,
        'route_reason' => $reason,
    ];
}

/**
 * @param array<string,mixed> $item
 * @param array<string,array{station_key:string,station_name:string,active:int,priority:int,color:string,prep_time_avg:int}> $stationList
 * @return array{station_key:string,station_label:string,fallback_used:bool,route_reason:string}
 */
function kitchen_station_item_route(PDO $pdo, int $restaurantId, array $item, array $stationList = []): array
{
    return kitchen_station_resolve($pdo, $restaurantId, $item, $stationList);
}

/**
 * @param list<array<string,mixed>> $tickets
 * @param array{station_key:string,station_name:string,active:int,priority:int,color:string,prep_time_avg:int} $stationMeta
 * @return array{active_tickets:int,items_total:int,items_waiting:int,items_cooking:int,items_ready:int,overdue_items:int,avg_prep_time_minutes:int,queue_pressure:int,station_load_percent:int,sla_breaches:int}
 */
function kitchen_station_workload(array $tickets, array $stationMeta): array
{
    $prepDefault = max(1, (int)($stationMeta['prep_time_avg'] ?? 15));
    $activeTickets = count($tickets);
    $itemsTotal = 0;
    $itemsWaiting = 0;
    $itemsCooking = 0;
    $itemsReady = 0;
    $overdueItems = 0;
    $slaBreaches = 0;
    $prepDurations = [];
    foreach ($tickets as $ticket) {
        $items = is_array($ticket['items'] ?? null) ? $ticket['items'] : [];
        $age = max(0, (int)($ticket['minutes_since_created'] ?? 0));
        if ($age > $prepDefault) {
            $slaBreaches++;
        }
        foreach ($items as $item) {
            $qty = max(1, (int)($item['quantity'] ?? 1));
            $itemsTotal += $qty;
            $status = kds_normalize_item_status((string)($item['kds_status'] ?? $item['station_status'] ?? 'new'));
            if ($status === 'ready') {
                $itemsReady += $qty;
            } elseif ($status === 'cooking') {
                $itemsCooking += $qty;
            } else {
                $itemsWaiting += $qty;
            }
            if ($age > $prepDefault) {
                $overdueItems += $qty;
            }

            $started = trim((string)($item['kds_started_at'] ?? $item['started_at'] ?? ''));
            $ready = trim((string)($item['kds_ready_at'] ?? $item['ready_at'] ?? ''));
            if ($started !== '' && $ready !== '') {
                $startTs = strtotime($started);
                $readyTs = strtotime($ready);
                if ($startTs && $readyTs && $readyTs >= $startTs) {
                    $prepDurations[] = max(1, (int)floor(($readyTs - $startTs) / 60));
                }
            }
        }
    }
    $avgPrep = $prepDurations !== [] ? (int)round(array_sum($prepDurations) / count($prepDurations)) : $prepDefault;
    $pressureRaw = ($itemsWaiting * 2) + $itemsCooking + ($overdueItems * 2);
    $queuePressure = min(100, $pressureRaw > 0 ? (int)round($pressureRaw / max(1, $activeTickets)) : 0);
    $loadBase = max(1, $activeTickets * max(1, $prepDefault));
    $loadCurrent = ($itemsWaiting * $prepDefault) + ($itemsCooking * max(1, (int)floor($prepDefault * 0.5)));
    $stationLoad = min(100, (int)round(($loadCurrent / max(1, $loadBase)) * 100));

    return [
        'active_tickets' => $activeTickets,
        'items_total' => $itemsTotal,
        'items_waiting' => $itemsWaiting,
        'items_cooking' => $itemsCooking,
        'items_ready' => $itemsReady,
        'overdue_items' => $overdueItems,
        'avg_prep_time_minutes' => $avgPrep,
        'queue_pressure' => $queuePressure,
        'station_load_percent' => $stationLoad,
        'sla_breaches' => $slaBreaches,
    ];
}

/**
 * @param list<array<string,mixed>> $tickets
 * @param array<string,array{station_key:string,station_name:string,active:int,priority:int,color:string,prep_time_avg:int}> $stationList
 * @return array<string,array<string,mixed>>
 */
function kitchen_station_summary(array $tickets, array $stationList): array
{
    $bucket = [];
    foreach ($stationList as $key => $station) {
        $bucket[$key] = [
            'station_key' => $key,
            'station_name' => (string)($station['station_name'] ?? kds_station_label_ru($key)),
            'active' => (int)($station['active'] ?? 1),
            'priority' => (int)($station['priority'] ?? 100),
            'color' => (string)($station['color'] ?? ''),
            'prep_time_avg' => max(1, (int)($station['prep_time_avg'] ?? 15)),
            'workload' => kitchen_station_workload([], $station),
        ];
    }

    $ticketsByStation = [];
    foreach ($tickets as $ticket) {
        $station = kds_normalize_station((string)($ticket['station'] ?? 'kitchen'));
        $ticketsByStation[$station][] = $ticket;
    }

    $maxTickets = 0;
    foreach ($bucket as $key => &$row) {
        $stationTickets = $ticketsByStation[$key] ?? [];
        $row['workload'] = kitchen_station_workload($stationTickets, $stationList[$key] ?? kitchen_station_defaults()['kitchen']);
        $maxTickets = max($maxTickets, (int)($row['workload']['active_tickets'] ?? 0));
    }
    unset($row);

    foreach ($bucket as &$row) {
        $ticketsCount = (int)($row['workload']['active_tickets'] ?? 0);
        $row['workload']['station_load_percent'] = $maxTickets > 0
            ? (int)round(($ticketsCount / $maxTickets) * 100)
            : 0;
    }
    unset($row);

    return $bucket;
}

/**
 * @param array<string,array<string,mixed>> $stationSummary
 * @return list<array{level:string,label:string,message:string,station_key:string}>
 */
function kitchen_station_alerts(array $stationSummary): array
{
    $alerts = [];
    foreach ($stationSummary as $stationKey => $summary) {
        $name = (string)($summary['station_name'] ?? kds_station_label_ru((string)$stationKey));
        $active = (int)($summary['active'] ?? 1);
        $workload = (array)($summary['workload'] ?? []);
        $load = (int)($workload['station_load_percent'] ?? 0);
        $pressure = (int)($workload['queue_pressure'] ?? 0);
        $overdue = (int)($workload['overdue_items'] ?? 0);
        if ($active !== 1 && ((int)($workload['active_tickets'] ?? 0) > 0)) {
            $alerts[] = [
                'level' => 'critical',
                'label' => 'Inactive station',
                'message' => $name . ': станция выключена, но тикеты продолжают приходить.',
                'station_key' => (string)$stationKey,
            ];
        }
        if ($overdue > 0) {
            $alerts[] = [
                'level' => 'critical',
                'label' => 'Overdue queue',
                'message' => $name . ': просроченных позиций ' . $overdue . '.',
                'station_key' => (string)$stationKey,
            ];
        } elseif ($load >= 80 || $pressure >= 70) {
            $alerts[] = [
                'level' => 'warning',
                'label' => 'Overloaded station',
                'message' => $name . ': нагрузка ' . $load . '%, давление очереди ' . $pressure . '%.',
                'station_key' => (string)$stationKey,
            ];
        }
    }
    return $alerts;
}

function kds_menu_station_expr(PDO $pdo, string $menuAlias = 'mi'): string
{
    $hasProd = function_exists('db_column_exists') && db_column_exists('menu_items', 'production_station');
    $hasStation = function_exists('db_column_exists') && db_column_exists('menu_items', 'station');
    $hasLegacy = function_exists('db_column_exists') && db_column_exists('menu_items', 'kitchen_station');

    $sources = [];
    if ($hasProd) {
        $sources[] = "LOWER(COALESCE(TRIM({$menuAlias}.production_station), ''))";
    }
    if ($hasStation) {
        $sources[] = "LOWER(COALESCE(TRIM({$menuAlias}.station), ''))";
    }
    if ($hasLegacy) {
        $sources[] = "LOWER(COALESCE(TRIM({$menuAlias}.kitchen_station), ''))";
    }

    if ($sources !== []) {
        return kds_station_sql_normalize_expr(kds_station_sql_prefer_explicit_expr($sources));
    }
    return "'kitchen'";
}

function kds_resolve_station_for_item(array $item): string
{
    $sources = [
        (string)($item['production_station'] ?? ''),
        (string)($item['station_key'] ?? ''),
        (string)($item['station'] ?? ''),
        (string)($item['kitchen_station'] ?? ''),
    ];
    foreach ($sources as $sourceRaw) {
        $raw = strtolower(trim($sourceRaw));
        if ($raw !== '' && !in_array($raw, ['hot', 'kitchen', 'general', 'main'], true)) {
            return kds_normalize_station($raw);
        }
    }
    foreach ($sources as $sourceRaw) {
        if (trim($sourceRaw) !== '') {
            return kds_normalize_station($sourceRaw);
        }
    }

    $category = mb_strtolower(trim((string)($item['category_name'] ?? '')), 'UTF-8');
    $name = mb_strtolower(trim((string)($item['item_name'] ?? $item['menu_name'] ?? '')), 'UTF-8');
    $haystack = trim($category . ' ' . $name);
    if ($haystack === '') {
        return 'kitchen';
    }

    $patterns = [
        'bar' => ['бар', 'напит', 'кофе', 'чай', 'лимонад', 'морс', 'коктейл', 'сок', 'вино', 'пиво'],
        'hookah' => ['кальян', 'hookah', 'shisha'],
        'dessert' => ['десерт', 'слад', 'чизкейк', 'тирамису', 'морожен', 'торт'],
        'sushi' => ['суши', 'ролл', 'roll', 'maki', 'nigiri'],
        'pizza' => ['пицц', 'pizza'],
        'grill' => ['гриль', 'bbq', 'барбекю', 'шашлык'],
        'cold' => ['салат', 'холод', 'закуск', 'карпаччо', 'тартар'],
        'hot' => ['суп', 'паста', 'стейк', 'горяч', 'мясо', 'бургер', 'ризотто'],
    ];

    foreach ($patterns as $station => $words) {
        foreach ($words as $w) {
            if ($w !== '' && mb_strpos($haystack, $w, 0, 'UTF-8') !== false) {
                return $station;
            }
        }
    }

    return 'kitchen';
}

function kds_recalculate_order_status(PDO $pdo, int $orderId): void
{
    if ($orderId <= 0) {
        return;
    }

    $stmtOrder = $pdo->prepare("SELECT id, order_status FROM orders WHERE id = :id LIMIT 1");
    $stmtOrder->execute([':id' => $orderId]);
    $order = $stmtOrder->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        return;
    }

    $current = strtolower((string)($order['order_status'] ?? 'new'));
    if (in_array($current, ['delivered', 'canceled', 'cancelled'], true)) {
        return;
    }

    $hasStatus = (function_exists('db_column_exists') && db_column_exists('order_items', 'kds_status'))
        || (function_exists('db_column_exists') && db_column_exists('order_items', 'station_status'));
    if (!$hasStatus) {
        return;
    }
    $statusExpr = kds_item_status_sql_expr('oi');

    $stmtAgg = $pdo->prepare("
        SELECT
            COUNT(*) AS total_cnt,
            SUM(CASE WHEN {$statusExpr} = 'new' THEN 1 ELSE 0 END) AS new_cnt,
            SUM(CASE WHEN {$statusExpr} = 'accepted' THEN 1 ELSE 0 END) AS accepted_cnt,
            SUM(CASE WHEN {$statusExpr} = 'cooking' THEN 1 ELSE 0 END) AS cooking_cnt,
            SUM(CASE WHEN {$statusExpr} = 'ready' THEN 1 ELSE 0 END) AS ready_cnt
        FROM order_items oi
        WHERE oi.order_id = :oid
    ");
    $stmtAgg->execute([':oid' => $orderId]);
    $agg = $stmtAgg->fetch(PDO::FETCH_ASSOC) ?: [];

    $total = (int)($agg['total_cnt'] ?? 0);
    if ($total <= 0) {
        return;
    }

    $newCnt = (int)($agg['new_cnt'] ?? 0);
    $acceptedCnt = (int)($agg['accepted_cnt'] ?? 0);
    $cookingCnt = (int)($agg['cooking_cnt'] ?? 0);
    $readyCnt = (int)($agg['ready_cnt'] ?? 0);

    $next = 'new';
    if ($readyCnt === $total) {
        $next = 'ready';
    } elseif ($cookingCnt > 0) {
        $next = 'cooking';
    } elseif ($acceptedCnt > 0 || ($readyCnt > 0 && $readyCnt < $total)) {
        $next = 'accepted';
    } elseif ($newCnt === $total) {
        $next = 'new';
    }

    if ($next !== $current) {
        $upd = $pdo->prepare("UPDATE orders SET order_status = :st WHERE id = :id");
        $upd->execute([':st' => $next, ':id' => $orderId]);
    }
}

function kds_ready_auto_hide_minutes(): int
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $defaultMinutes = 240; // 4 hours
    $envRaw = getenv('KDS_READY_HIDE_MINUTES');
    $value = is_string($envRaw) ? (int)trim($envRaw) : 0;
    if ($value < 10 || $value > 10080) {
        $value = $defaultMinutes;
    }
    $cache = $value;
    return $cache;
}

function kds_active_auto_hide_minutes(): int
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $defaultMinutes = 1440; // 24 hours
    $envRaw = getenv('KDS_ACTIVE_HIDE_MINUTES');
    $value = is_string($envRaw) ? (int)trim($envRaw) : 0;
    if ($value < 60 || $value > 43200) {
        $value = $defaultMinutes;
    }
    $cache = $value;
    return $cache;
}

function kds_order_progress(PDO $pdo, int $orderId): array
{
    $fallback = [
        'total_items_count' => 0,
        'ready_items_count' => 0,
        'progress_percent' => 0,
        'partial_ready' => false,
    ];
    if ($orderId <= 0) {
        return $fallback;
    }

    $hasStatus = (function_exists('db_column_exists') && db_column_exists('order_items', 'kds_status'))
        || (function_exists('db_column_exists') && db_column_exists('order_items', 'station_status'));
    if (!$hasStatus) {
        return $fallback;
    }
    $statusExpr = kds_item_status_sql_expr('oi');

    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total_cnt,
            SUM(CASE WHEN {$statusExpr} = 'ready' THEN 1 ELSE 0 END) AS ready_cnt
        FROM order_items oi
        WHERE oi.order_id = :oid
    ");
    $stmt->execute([':oid' => $orderId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $total = (int)($row['total_cnt'] ?? 0);
    $ready = (int)($row['ready_cnt'] ?? 0);
    if ($total <= 0) {
        return $fallback;
    }

    $percent = (int)floor(($ready / $total) * 100);
    return [
        'total_items_count' => $total,
        'ready_items_count' => $ready,
        'progress_percent' => $percent,
        'partial_ready' => $ready > 0 && $ready < $total,
    ];
}

/**
 * @return array{code:string,label:string,total_items:int,ready_items:int,in_progress_items:int}
 */
function calculate_order_kds_state(PDO $pdo, int $orderId, ?string $station = null): array
{
    $fallback = [
        'code' => 'new',
        'label' => 'Новый',
        'total_items' => 0,
        'ready_items' => 0,
        'in_progress_items' => 0,
    ];
    if ($orderId <= 0) {
        return $fallback;
    }

    $statusExpr = kds_item_status_sql_expr('oi');
    $stationExpr = kds_menu_station_expr($pdo, 'mi');
    $params = [':oid' => $orderId];
    $whereStation = '';
    if ($station !== null && trim($station) !== '') {
        $whereStation = " AND " . $stationExpr . " = :station ";
        $params[':station'] = kds_normalize_station($station);
    }

    $sql = "
        SELECT
            COUNT(*) AS total_cnt,
            SUM(CASE WHEN {$statusExpr} = 'ready' THEN 1 ELSE 0 END) AS ready_cnt,
            SUM(CASE WHEN {$statusExpr} IN ('accepted','cooking') THEN 1 ELSE 0 END) AS in_progress_cnt,
            SUM(CASE WHEN {$statusExpr} = 'new' THEN 1 ELSE 0 END) AS new_cnt
        FROM order_items oi
        LEFT JOIN menu_items mi ON mi.id = oi.menu_item_id
        WHERE oi.order_id = :oid
        {$whereStation}
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $total = (int)($row['total_cnt'] ?? 0);
    $ready = (int)($row['ready_cnt'] ?? 0);
    $inProgress = (int)($row['in_progress_cnt'] ?? 0);
    $new = (int)($row['new_cnt'] ?? 0);

    $code = 'new';
    $label = 'Новый';
    if ($total > 0 && $ready >= $total) {
        $code = 'ready_for_serve';
        $label = 'Готов к выдаче';
    } elseif ($inProgress > 0 || $ready > 0) {
        $code = 'in_progress';
        $label = 'В работе';
    } elseif ($new === $total && $total > 0) {
        $code = 'new';
        $label = 'Новый';
    }

    return [
        'code' => $code,
        'label' => $label,
        'total_items' => $total,
        'ready_items' => $ready,
        'in_progress_items' => $inProgress,
    ];
}

function is_order_ready_for_serve(PDO $pdo, int $orderId): bool
{
    $meta = calculate_order_kds_state($pdo, $orderId);
    return ($meta['code'] ?? '') === 'ready_for_serve';
}

/**
 * @return array{station:string,active_items:int,threshold:int,overloaded:bool}
 */
function is_station_overloaded(PDO $pdo, int $restaurantId, string $station): array
{
    $threshold = max(1, (int)(getenv('KDS_STATION_OVERLOAD_THRESHOLD') ?: 20));
    $statusExpr = kds_item_status_sql_expr('oi');
    $stationExpr = kds_menu_station_expr($pdo, 'mi');

    $sql = "
        SELECT COUNT(*) AS active_cnt
        FROM order_items oi
        INNER JOIN orders o ON o.id = oi.order_id
        LEFT JOIN menu_items mi ON mi.id = oi.menu_item_id
        WHERE o.restaurant_id = :rid
          AND {$stationExpr} = :station
          AND {$statusExpr} IN ('new','accepted','cooking')
          AND LOWER(COALESCE(TRIM(o.order_status), '')) IN ('new','pending','accepted','preparing','cooking','ready')
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':rid' => $restaurantId,
        ':station' => kds_normalize_station($station),
    ]);
    $active = (int)($stmt->fetchColumn() ?: 0);

    return [
        'station' => kds_normalize_station($station),
        'active_items' => $active,
        'threshold' => $threshold,
        'overloaded' => $active > $threshold,
    ];
}

/**
 * @return array<string,array{items_total:int,items_ready:int,avg_cook_minutes:int,station_load:int}>
 */
function get_station_performance_stats(PDO $pdo, int $restaurantId): array
{
    if ($restaurantId <= 0) {
        return [];
    }

    $stationExpr = kds_menu_station_expr($pdo, 'mi');
    $statusExpr = kds_item_status_sql_expr('oi');
    $startedExpr = kds_item_started_at_sql_expr('oi');
    $readyExpr = kds_item_ready_at_sql_expr('oi');

    $sql = "
        SELECT
            {$stationExpr} AS station_key,
            COUNT(*) AS items_total,
            SUM(CASE WHEN {$statusExpr} = 'ready' THEN 1 ELSE 0 END) AS items_ready,
            AVG(
                CASE
                    WHEN {$startedExpr} IS NOT NULL AND {$readyExpr} IS NOT NULL AND {$readyExpr} >= {$startedExpr}
                    THEN TIMESTAMPDIFF(MINUTE, {$startedExpr}, {$readyExpr})
                    ELSE NULL
                END
            ) AS avg_cook_minutes,
            SUM(CASE WHEN {$statusExpr} IN ('new','accepted','cooking') THEN 1 ELSE 0 END) AS active_items
        FROM order_items oi
        INNER JOIN orders o ON o.id = oi.order_id
        LEFT JOIN menu_items mi ON mi.id = oi.menu_item_id
        WHERE o.restaurant_id = :rid
          AND o.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        GROUP BY station_key
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':rid' => $restaurantId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $stats = [];
    foreach ($rows as $row) {
        $station = kds_normalize_station((string)($row['station_key'] ?? 'kitchen'));
        $activeItems = (int)($row['active_items'] ?? 0);
        $threshold = max(1, (int)(getenv('KDS_STATION_OVERLOAD_THRESHOLD') ?: 20));
        $stationLoad = min(100, (int)round(($activeItems / $threshold) * 100));
        $stats[$station] = [
            'items_total' => (int)($row['items_total'] ?? 0),
            'items_ready' => (int)($row['items_ready'] ?? 0),
            'avg_cook_minutes' => max(0, (int)round((float)($row['avg_cook_minutes'] ?? 0))),
            'station_load' => $stationLoad,
        ];
    }

    return $stats;
}
