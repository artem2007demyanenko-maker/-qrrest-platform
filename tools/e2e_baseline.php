<?php

declare(strict_types=1);

/**
 * QRRest Playwright E2E baseline provisioner.
 *
 * Purpose: create the smallest real test tenant needed by smoke tests.
 * Safety:
 * - CLI only.
 * - Requires explicit --apply or QRREST_E2E_BASELINE_APPLY=1.
 * - Requires passwords from environment; no credentials are hardcoded.
 * - Idempotent: updates/reuses dedicated E2E rows by stable names/emails.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/schema_guard.php';
if (file_exists(__DIR__ . '/../app/kds_helpers.php')) {
    require_once __DIR__ . '/../app/kds_helpers.php';
}

function e2e_line(string $message): void
{
    fwrite(STDOUT, $message . PHP_EOL);
}

function e2e_env(string $name, string $default = ''): string
{
    $value = getenv($name);
    if ($value === false) {
        return $default;
    }
    return trim((string)$value);
}

function e2e_bool_env(string $name, bool $default = false): bool
{
    $value = e2e_env($name, $default ? '1' : '0');
    return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
}

function e2e_ident(string $name): string
{
    $safe = preg_replace('/[^a-zA-Z0-9_]/', '', $name);
    if ($safe === '') {
        throw new InvalidArgumentException('Unsafe identifier');
    }
    return '`' . $safe . '`';
}

function e2e_has_col(string $table, string $column): bool
{
    static $cache = [];
    $safeTable = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $safeColumn = preg_replace('/[^a-zA-Z0-9_]/', '', $column);
    if ($safeTable === '' || $safeColumn === '') {
        return false;
    }
    $key = $safeTable . '.' . $safeColumn;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $pdo = db();
    $stmt = $pdo->prepare("
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :table_name
          AND COLUMN_NAME = :column_name
        LIMIT 1
    ");
    $stmt->execute([
        ':table_name' => $safeTable,
        ':column_name' => $safeColumn,
    ]);
    $cache[$key] = (bool)$stmt->fetchColumn();
    return $cache[$key];
}

function e2e_sql_expr(string $sql): array
{
    return ['__expr' => $sql];
}

function e2e_insert_row(PDO $pdo, string $table, array $values): int
{
    $cols = [];
    $vals = [];
    $params = [];
    foreach ($values as $column => $value) {
        if (!e2e_has_col($table, (string)$column)) {
            continue;
        }
        $cols[] = e2e_ident((string)$column);
        if (is_array($value) && array_key_exists('__expr', $value)) {
            $vals[] = (string)$value['__expr'];
            continue;
        }
        $param = ':p' . count($params);
        $vals[] = $param;
        $params[$param] = $value;
    }
    if ($cols === []) {
        throw new RuntimeException("No insertable columns for {$table}");
    }
    $sql = 'INSERT INTO ' . e2e_ident($table) . ' (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $vals) . ')';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)$pdo->lastInsertId();
}

function e2e_update_row(PDO $pdo, string $table, array $values, string $whereSql, array $whereParams): void
{
    $sets = [];
    $params = [];
    foreach ($values as $column => $value) {
        if (!e2e_has_col($table, (string)$column)) {
            continue;
        }
        if (is_array($value) && array_key_exists('__expr', $value)) {
            $sets[] = e2e_ident((string)$column) . ' = ' . (string)$value['__expr'];
            continue;
        }
        $param = ':u' . count($params);
        $sets[] = e2e_ident((string)$column) . ' = ' . $param;
        $params[$param] = $value;
    }
    if ($sets === []) {
        return;
    }
    $stmt = $pdo->prepare('UPDATE ' . e2e_ident($table) . ' SET ' . implode(', ', $sets) . ' WHERE ' . $whereSql);
    $stmt->execute(array_merge($params, $whereParams));
}

function e2e_first_id(PDO $pdo, string $sql, array $params): int
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)($stmt->fetchColumn() ?: 0);
}

function e2e_require_tables(array $tables): void
{
    foreach ($tables as $table) {
        if (!function_exists('db_table_exists') || !db_table_exists($table)) {
            throw new RuntimeException("Required table missing: {$table}");
        }
    }
}

function e2e_password_for(string $role): string
{
    $roleKey = strtoupper($role);
    $specific = e2e_env("QRREST_{$roleKey}_PASSWORD");
    if ($specific !== '') {
        return $specific;
    }
    $common = e2e_env('QRREST_E2E_PASSWORD');
    if ($common !== '') {
        return $common;
    }
    throw new RuntimeException("Missing password env for {$role}. Set QRREST_{$roleKey}_PASSWORD or QRREST_E2E_PASSWORD.");
}

function e2e_email_for(string $role, string $default): string
{
    $roleKey = strtoupper($role);
    $email = e2e_env("QRREST_{$roleKey}_EMAIL", $default);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException("Invalid email for {$role}: {$email}");
    }
    return strtolower($email);
}

function e2e_upsert_restaurant(PDO $pdo): int
{
    $subdomain = strtolower(e2e_env('QRREST_E2E_RESTAURANT_SUBDOMAIN', 'test'));
    $name = e2e_env('QRREST_E2E_RESTAURANT_NAME', 'QRRest E2E Smoke Restaurant');

    $restaurantId = e2e_first_id($pdo, 'SELECT id FROM restaurants WHERE subdomain = :sub LIMIT 1', [':sub' => $subdomain]);
    if ($restaurantId <= 0) {
        $restaurantId = e2e_insert_row($pdo, 'restaurants', [
            'name' => $name,
            'subdomain' => $subdomain,
            'status' => 'active',
            'created_at' => e2e_sql_expr('NOW()'),
            'updated_at' => e2e_sql_expr('NOW()'),
        ]);
    } else {
        e2e_update_row($pdo, 'restaurants', [
            'name' => $name,
            'status' => 'active',
            'updated_at' => e2e_sql_expr('NOW()'),
        ], 'id = :id', [':id' => $restaurantId]);
    }

    return $restaurantId;
}

function e2e_upsert_user(PDO $pdo, string $email, string $name, string $password): int
{
    $userId = e2e_first_id($pdo, 'SELECT id FROM users WHERE email = :email LIMIT 1', [':email' => $email]);
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $roleCol = e2e_has_col('users', 'global_role') ? 'global_role' : (e2e_has_col('users', 'role') ? 'role' : '');
    $resetPasswords = e2e_bool_env('QRREST_E2E_RESET_PASSWORDS', true);

    if ($userId <= 0) {
        $values = [
            'name' => $name,
            'email' => $email,
            'password_hash' => $hash,
            'is_active' => 1,
            'created_at' => e2e_sql_expr('NOW()'),
            'updated_at' => e2e_sql_expr('NOW()'),
        ];
        if ($roleCol !== '') {
            $values[$roleCol] = 'user';
        }
        return e2e_insert_row($pdo, 'users', $values);
    }

    $values = [
        'name' => $name,
        'is_active' => 1,
        'updated_at' => e2e_sql_expr('NOW()'),
    ];
    if ($resetPasswords) {
        $values['password_hash'] = $hash;
    }
    if ($roleCol !== '') {
        $values[$roleCol] = 'user';
    }
    e2e_update_row($pdo, 'users', $values, 'id = :id', [':id' => $userId]);
    return $userId;
}

function e2e_upsert_user_restaurant(PDO $pdo, int $userId, int $restaurantId, string $role, ?string $station): void
{
    $linkId = e2e_first_id(
        $pdo,
        'SELECT id FROM users_restaurants WHERE user_id = :uid AND restaurant_id = :rid LIMIT 1',
        [':uid' => $userId, ':rid' => $restaurantId]
    );

    $values = [
        'user_id' => $userId,
        'restaurant_id' => $restaurantId,
        'restaurant_role' => $role,
        'is_active' => 1,
        'station' => $station,
        'created_at' => e2e_sql_expr('NOW()'),
        'updated_at' => e2e_sql_expr('NOW()'),
    ];

    if ($linkId <= 0) {
        e2e_insert_row($pdo, 'users_restaurants', $values);
        return;
    }

    unset($values['user_id'], $values['restaurant_id'], $values['created_at']);
    e2e_update_row($pdo, 'users_restaurants', $values, 'id = :id', [':id' => $linkId]);
}

function e2e_upsert_table(PDO $pdo, int $restaurantId): int
{
    $name = e2e_env('QRREST_E2E_TABLE_NAME', 'E2E Smoke Table 1');
    $tableId = e2e_first_id(
        $pdo,
        'SELECT id FROM `tables` WHERE restaurant_id = :rid AND name = :name LIMIT 1',
        [':rid' => $restaurantId, ':name' => $name]
    );
    if ($tableId <= 0) {
        $tableId = e2e_insert_row($pdo, 'tables', [
            'restaurant_id' => $restaurantId,
            'name' => $name,
            'created_at' => e2e_sql_expr('NOW()'),
            'updated_at' => e2e_sql_expr('NOW()'),
        ]);
    }
    return $tableId;
}

function e2e_upsert_category(PDO $pdo, int $restaurantId, string $name, int $sortOrder): int
{
    $categoryId = e2e_first_id(
        $pdo,
        'SELECT id FROM menu_categories WHERE restaurant_id = :rid AND name = :name LIMIT 1',
        [':rid' => $restaurantId, ':name' => $name]
    );
    if ($categoryId <= 0) {
        return e2e_insert_row($pdo, 'menu_categories', [
            'restaurant_id' => $restaurantId,
            'name' => $name,
            'sort_order' => $sortOrder,
            'created_at' => e2e_sql_expr('NOW()'),
            'updated_at' => e2e_sql_expr('NOW()'),
        ]);
    }
    e2e_update_row($pdo, 'menu_categories', [
        'sort_order' => $sortOrder,
        'updated_at' => e2e_sql_expr('NOW()'),
    ], 'id = :id', [':id' => $categoryId]);
    return $categoryId;
}

function e2e_upsert_menu_item(PDO $pdo, int $restaurantId, int $categoryId, array $item): int
{
    $name = (string)$item['name'];
    $itemId = e2e_first_id(
        $pdo,
        'SELECT id FROM menu_items WHERE restaurant_id = :rid AND name = :name LIMIT 1',
        [':rid' => $restaurantId, ':name' => $name]
    );

    $kdsStation = (string)$item['production_station'];
    $legacyStation = $kdsStation === 'kitchen' ? 'hot' : $kdsStation;
    $values = [
        'restaurant_id' => $restaurantId,
        'category_id' => $categoryId,
        'name' => $name,
        'description' => (string)$item['description'],
        'price' => (float)$item['price'],
        'available' => 1,
        'is_temporarily_unavailable' => 0,
        'station' => $legacyStation,
        'production_station' => $kdsStation,
        'kitchen_station' => $kdsStation === 'kitchen' ? 'HOT' : strtoupper($kdsStation),
        'sort_order' => (int)($item['sort_order'] ?? 100),
        'created_at' => e2e_sql_expr('NOW()'),
        'updated_at' => e2e_sql_expr('NOW()'),
    ];

    if ($itemId <= 0) {
        return e2e_insert_row($pdo, 'menu_items', $values);
    }

    unset($values['restaurant_id'], $values['created_at']);
    e2e_update_row($pdo, 'menu_items', $values, 'id = :id AND restaurant_id = :rid', [
        ':id' => $itemId,
        ':rid' => $restaurantId,
    ]);
    return $itemId;
}

function e2e_active_status_column(): string
{
    if (e2e_has_col('orders', 'order_status')) {
        return 'order_status';
    }
    if (e2e_has_col('orders', 'status')) {
        return 'status';
    }
    return '';
}

function e2e_upsert_kds_order(PDO $pdo, int $restaurantId, int $tableId, array $menuItems): int
{
    if (!e2e_bool_env('QRREST_E2E_BASELINE_KDS_ORDER', true)) {
        return 0;
    }
    if (!function_exists('db_table_exists') || !db_table_exists('orders') || !db_table_exists('order_items')) {
        return 0;
    }

    $statusCol = e2e_active_status_column();
    $statusFilter = $statusCol !== '' ? "AND LOWER(COALESCE(o." . e2e_ident($statusCol) . ", '')) IN ('new','pending','accepted','preparing','cooking','ready')" : '';
    $menuItemPlaceholders = [];
    $params = [':rid' => $restaurantId];
    $idx = 0;
    foreach (array_keys($menuItems) as $itemId) {
        $param = ':mid' . $idx;
        $menuItemPlaceholders[] = $param;
        $params[$param] = (int)$itemId;
        $idx++;
    }
    $existingSql = "
        SELECT o.id
        FROM orders o
        JOIN order_items oi ON oi.order_id = o.id
        WHERE o.restaurant_id = :rid
          {$statusFilter}
          AND (
              " . (e2e_has_col('order_items', 'item_name') ? "oi.item_name LIKE 'E2E Smoke %'" : '0=1') . "
              OR oi.menu_item_id IN (" . implode(',', $menuItemPlaceholders) . ")
          )
        ORDER BY o.id DESC
        LIMIT 1
    ";
    $stmtExisting = $pdo->prepare($existingSql);
    $stmtExisting->execute($params);
    $orderId = (int)($stmtExisting->fetchColumn() ?: 0);

    $total = 0.0;
    foreach ($menuItems as $item) {
        $total += (float)$item['price'];
    }

    if ($orderId <= 0) {
        $values = [
            'restaurant_id' => $restaurantId,
            'table_id' => $tableId,
            'total_price' => $total,
            'total_amount' => $total,
            'total' => $total,
            'subtotal' => $total,
            'payment_status' => 'unpaid',
            'payment_type' => 'cash',
            'payment_method' => 'cash',
            'order_type' => 'hall',
            'comment' => 'E2E baseline KDS order',
            'notes' => 'E2E baseline KDS order',
            'customer_name' => 'E2E Smoke Guest',
            'customer_phone' => '79000000000',
            'created_at' => e2e_sql_expr('NOW()'),
            'updated_at' => e2e_sql_expr('NOW()'),
        ];
        if ($statusCol !== '') {
            $values[$statusCol] = 'new';
        }
        $orderId = e2e_insert_row($pdo, 'orders', $values);
    } else {
        $values = [
            'total_price' => $total,
            'total_amount' => $total,
            'total' => $total,
            'subtotal' => $total,
            'payment_status' => 'unpaid',
            'updated_at' => e2e_sql_expr('NOW()'),
            'created_at' => e2e_sql_expr('NOW()'),
        ];
        if ($statusCol !== '') {
            $values[$statusCol] = 'new';
        }
        e2e_update_row($pdo, 'orders', $values, 'id = :id AND restaurant_id = :rid', [
            ':id' => $orderId,
            ':rid' => $restaurantId,
        ]);
    }

    foreach ($menuItems as $itemId => $item) {
        $existingItemId = e2e_first_id(
            $pdo,
            'SELECT id FROM order_items WHERE order_id = :oid AND menu_item_id = :mid LIMIT 1',
            [':oid' => $orderId, ':mid' => (int)$itemId]
        );
        $itemValues = [
            'order_id' => $orderId,
            'menu_item_id' => (int)$itemId,
            'item_name' => (string)$item['name'],
            'qty' => 1,
            'quantity' => 1,
            'price' => (float)$item['price'],
            'production_station' => (string)$item['production_station'],
            'station' => (string)$item['production_station'],
            'station_status' => 'new',
            'kds_status' => 'new',
            'started_at' => null,
            'ready_at' => null,
            'kds_started_at' => null,
            'kds_ready_at' => null,
            'created_at' => e2e_sql_expr('NOW()'),
            'updated_at' => e2e_sql_expr('NOW()'),
        ];
        if ($existingItemId <= 0) {
            e2e_insert_row($pdo, 'order_items', $itemValues);
            continue;
        }
        unset($itemValues['order_id'], $itemValues['menu_item_id'], $itemValues['created_at']);
        e2e_update_row($pdo, 'order_items', $itemValues, 'id = :id', [':id' => $existingItemId]);
    }

    return $orderId;
}

$apply = in_array('--apply', $argv, true) || e2e_bool_env('QRREST_E2E_BASELINE_APPLY', false);
if (!$apply) {
    e2e_line('Dry run only. Re-run with --apply or QRREST_E2E_BASELINE_APPLY=1.');
    e2e_line('Required secret: QRREST_E2E_PASSWORD or per-role QRREST_*_PASSWORD values.');
    exit(0);
}

$pdo = db();
e2e_require_tables(['restaurants', 'users', 'users_restaurants', 'tables', 'menu_categories', 'menu_items']);
if (function_exists('kds_ensure_schema')) {
    kds_ensure_schema($pdo);
}

$roles = [
    'OWNER' => ['role' => 'owner', 'station' => 'all', 'name' => 'E2E Owner', 'email' => 'e2e.owner@qrrest-e2e.local'],
    'WAITER' => ['role' => 'waiter', 'station' => null, 'name' => 'E2E Waiter', 'email' => 'e2e.waiter@qrrest-e2e.local'],
    'KITCHEN' => ['role' => 'kitchen', 'station' => 'hot', 'name' => 'E2E Kitchen', 'email' => 'e2e.kitchen@qrrest-e2e.local'],
    'BAR' => ['role' => 'bar', 'station' => 'bar', 'name' => 'E2E Bar', 'email' => 'e2e.bar@qrrest-e2e.local'],
    'COLD' => ['role' => 'cold', 'station' => 'cold', 'name' => 'E2E Cold Station', 'email' => 'e2e.cold@qrrest-e2e.local'],
    'DESSERT' => ['role' => 'dessert', 'station' => 'dessert', 'name' => 'E2E Dessert Station', 'email' => 'e2e.dessert@qrrest-e2e.local'],
];

$pdo->beginTransaction();
try {
    $restaurantId = e2e_upsert_restaurant($pdo);
    $tableId = e2e_upsert_table($pdo, $restaurantId);

    $userIds = [];
    foreach ($roles as $envRole => $meta) {
        $email = e2e_email_for($envRole, (string)$meta['email']);
        $password = e2e_password_for($envRole);
        $userId = e2e_upsert_user($pdo, $email, (string)$meta['name'], $password);
        e2e_upsert_user_restaurant($pdo, $userId, $restaurantId, (string)$meta['role'], $meta['station'] !== null ? (string)$meta['station'] : null);
        $userIds[$envRole] = ['id' => $userId, 'email' => $email];
    }

    if (e2e_has_col('restaurants', 'owner_user_id') && isset($userIds['OWNER'])) {
        e2e_update_row($pdo, 'restaurants', [
            'owner_user_id' => (int)$userIds['OWNER']['id'],
        ], 'id = :id', [':id' => $restaurantId]);
    }

    $categoryId = e2e_upsert_category($pdo, $restaurantId, 'E2E Smoke Menu', 1);
    $menuSeed = [
        ['key' => 'hot', 'name' => 'E2E Smoke Hot Dish', 'description' => 'Stable hot station smoke item.', 'price' => 410.00, 'production_station' => 'kitchen', 'sort_order' => 10],
        ['key' => 'cold', 'name' => 'E2E Smoke Cold Salad', 'description' => 'Stable cold station smoke item.', 'price' => 320.00, 'production_station' => 'cold', 'sort_order' => 20],
        ['key' => 'bar', 'name' => 'E2E Smoke Bar Drink', 'description' => 'Stable bar station smoke item.', 'price' => 180.00, 'production_station' => 'bar', 'sort_order' => 30],
        ['key' => 'dessert', 'name' => 'E2E Smoke Dessert', 'description' => 'Stable dessert station smoke item.', 'price' => 260.00, 'production_station' => 'dessert', 'sort_order' => 40],
    ];
    $menuItems = [];
    foreach ($menuSeed as $item) {
        $itemId = e2e_upsert_menu_item($pdo, $restaurantId, $categoryId, $item);
        $menuItems[$itemId] = $item;
    }

    $kdsOrderId = e2e_upsert_kds_order($pdo, $restaurantId, $tableId, $menuItems);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'E2E baseline failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

$subdomain = strtolower(e2e_env('QRREST_E2E_RESTAURANT_SUBDOMAIN', 'test'));
e2e_line('QRRest E2E baseline ready.');
e2e_line('restaurant_id=' . $restaurantId);
e2e_line('restaurant_subdomain=' . $subdomain);
e2e_line('table_id=' . $tableId);
if ($kdsOrderId > 0) {
    e2e_line('kds_order_id=' . $kdsOrderId);
}
e2e_line('');
e2e_line('Suggested .env.playwright values:');
e2e_line('PLAYWRIGHT_BASE_URL=https://' . $subdomain . '.qrrest-menu.ru');
e2e_line('QRREST_E2E_TABLE_ID=' . $tableId);
e2e_line('QRREST_E2E_MUTATION=1');
e2e_line('QRREST_E2E_CREATE_ORDER=1');
e2e_line('QRREST_E2E_STRICT=1');
foreach ($roles as $envRole => $_meta) {
    e2e_line('QRREST_' . $envRole . '_EMAIL=' . $userIds[$envRole]['email']);
}
