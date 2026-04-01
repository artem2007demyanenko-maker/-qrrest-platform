<?php
/**
 * Schema guard: preflight checks for tables/columns. Cached per request.
 * Use to avoid 500 when DB migrations are not yet applied.
 * No business logic changes when schema is present.
 */

require_once __DIR__ . '/db.php';

/** Allowed table/column names: alphanumeric + underscore only (safe for INFORMATION_SCHEMA). */
function schema_guard_sanitize_identifier(string $name): string
{
    return preg_replace('/[^a-zA-Z0-9_]/', '', $name);
}

/**
 * Check if a table exists. Results cached per request.
 */
function db_table_exists(string $table): bool
{
    static $cache = [];
    $key = 't:' . $table;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    $safe = schema_guard_sanitize_identifier($table);
    if ($safe === '') {
        $cache[$key] = false;
        return false;
    }
    try {
        $pdo = db();
        $stmt = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t LIMIT 1");
        $stmt->execute(['t' => $safe]);
        $cache[$key] = (bool)$stmt->fetchColumn();
        return $cache[$key];
    } catch (Throwable $e) {
        error_log('SCHEMA_GUARD_TABLE_ERROR table=' . $safe . ' user_id=' . (function_exists('auth_user') ? (string)(auth_user()['id'] ?? '') : '') . ' uri=' . ($_SERVER['REQUEST_URI'] ?? '') . ' ' . $e->getMessage());
        $cache[$key] = false;
        return false;
    }
}

/**
 * Check if a column exists in a table. Results cached per request.
 */
function db_column_exists(string $table, string $column): bool
{
    static $cache = [];
    $key = 'c:' . $table . '.' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    $safeTable  = schema_guard_sanitize_identifier($table);
    $safeColumn = schema_guard_sanitize_identifier($column);
    if ($safeTable === '' || $safeColumn === '') {
        $cache[$key] = false;
        return false;
    }
    try {
        $pdo = db();
        $stmt = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :col LIMIT 1");
        $stmt->execute(['t' => $safeTable, 'col' => $safeColumn]);
        $cache[$key] = (bool)$stmt->fetchColumn();
        return $cache[$key];
    } catch (Throwable $e) {
        error_log('SCHEMA_GUARD_COLUMN_ERROR table=' . $safeTable . ' column=' . $safeColumn . ' user_id=' . (function_exists('auth_user') ? (string)(auth_user()['id'] ?? '') : '') . ' uri=' . ($_SERVER['REQUEST_URI'] ?? '') . ' ' . $e->getMessage());
        $cache[$key] = false;
        return false;
    }
}

/** Billing feature: plans, subscriptions, invoices, payments tables exist. */
function schema_guard_billing_ready(): bool
{
    return db_table_exists('plans') && db_table_exists('subscriptions') && db_table_exists('invoices') && db_table_exists('payments');
}

/** Growth feature: referral_codes (and core growth tables) exist. */
function schema_guard_growth_ready(): bool
{
    return db_table_exists('referral_codes');
}

/** Usage metrics: usage_metrics_daily table exists. */
function schema_guard_usage_metrics_ready(): bool
{
    return db_table_exists('usage_metrics_daily');
}

/** Rate limit: security_rate_limits table exists. */
function schema_guard_rate_limit_ready(): bool
{
    return db_table_exists('security_rate_limits');
}

/**
 * SQL fragment for filtering out soft-deleted restaurants.
 * @param string $alias Table alias (e.g. 'r'); use '' for no alias (bare column name "deleted_at").
 * Returns empty string if column deleted_at does not exist.
 */
function schema_guard_restaurants_deleted_sql(string $alias = 'r'): string
{
    if (!db_column_exists('restaurants', 'deleted_at')) {
        return '';
    }
    $a = schema_guard_sanitize_identifier($alias);
    if ($a === '') {
        return " AND (deleted_at IS NULL)";
    }
    return " AND ({$a}.deleted_at IS NULL)";
}

/**
 * Preflight report for debug: missing_tables, missing_columns, features_disabled.
 * Call only when debug=1 and owner; do not expose to non-owners.
 */
function schema_guard_preflight_report(): array
{
    $tables = ['plans', 'subscriptions', 'invoices', 'payments', 'referral_codes', 'usage_metrics_daily', 'security_rate_limits'];
    $missingTables = [];
    foreach ($tables as $t) {
        if (!db_table_exists($t)) {
            $missingTables[] = $t;
        }
    }
    $missingColumns = [];
    if (db_table_exists('restaurants') && !db_column_exists('restaurants', 'deleted_at')) {
        $missingColumns[] = 'restaurants.deleted_at';
    }
    $featuresDisabled = [];
    if (!schema_guard_billing_ready()) {
        $featuresDisabled[] = 'billing';
    }
    if (!schema_guard_growth_ready()) {
        $featuresDisabled[] = 'growth';
    }
    if (!schema_guard_usage_metrics_ready()) {
        $featuresDisabled[] = 'usage_metrics';
    }
    if (!schema_guard_rate_limit_ready()) {
        $featuresDisabled[] = 'rate_limit_mysql';
    }
    $migrationHints = [
        'billing'          => 'app/migrations/2026_03_04_billing.sql, 2026_03_05_billing_hardened.sql',
        'growth'           => 'app/migrations/2026_03_06_growth.sql',
        'usage_metrics'    => 'app/migrations/2026_03_06_growth.sql',
        'rate_limit_mysql'  => 'app/migrations/2026_03_07_stability.sql',
        'restaurants.deleted_at' => 'app/migrations/2026_03_07_stability.sql',
    ];
    $hints = [];
    foreach ($featuresDisabled as $f) {
        if (isset($migrationHints[$f])) {
            $hints[$f] = $migrationHints[$f];
        }
    }
    if (!empty($missingColumns)) {
        $hints['restaurants.deleted_at'] = $migrationHints['restaurants.deleted_at'] ?? 'app/migrations/2026_03_07_stability.sql';
    }

    return [
        'missing_tables'     => $missingTables,
        'missing_columns'   => $missingColumns,
        'features_disabled' => $featuresDisabled,
        'migration_hints'   => $hints,
    ];
}
