<?php
declare(strict_types=1);

if (!function_exists('db_column_exists') && file_exists(__DIR__ . '/schema_guard.php')) {
    require_once __DIR__ . '/schema_guard.php';
}

/**
 * Helpers for public guest menu page `public_html/qr.php`:
 * - delivery mode (platform entry without QR/table_id)
 * - dine-in mode (QR with real table_id)
 */

function qr_public_delivery_table_name(): string
{
    return 'Платформа · доставка';
}

function qr_public_is_delivery_table_row(?array $tableRow): bool
{
    if (!$tableRow) {
        return false;
    }
    if (isset($tableRow['is_delivery'])) {
        return (int)($tableRow['is_delivery'] ?? 0) === 1;
    }
    // Fallback if schema not migrated yet.
    $name = trim((string)($tableRow['name'] ?? ''));
    if ($name === '') {
        return false;
    }
    $prefix = qr_public_delivery_table_name();
    if (function_exists('mb_stripos')) {
        return mb_stripos($name, $prefix, 0, 'UTF-8') === 0;
    }
    return stripos($name, $prefix) === 0;
}

function qr_public_ensure_delivery_table(PDO $pdo, int $restaurantId): array
{
    if ($restaurantId <= 0) {
        throw new InvalidArgumentException('restaurant_id');
    }

    $hasIsDelivery = function_exists('db_column_exists') && db_column_exists('tables', 'is_delivery');
    $name = qr_public_delivery_table_name();

    if ($hasIsDelivery) {
        $stmt = $pdo->prepare('SELECT * FROM tables WHERE restaurant_id = ? AND is_delivery = 1 ORDER BY id ASC LIMIT 1');
        $stmt->execute([$restaurantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return $row;
        }
    } else {
        $stmt = $pdo->prepare('SELECT * FROM tables WHERE restaurant_id = ? AND name = ? ORDER BY id ASC LIMIT 1');
        $stmt->execute([$restaurantId, $name]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return $row;
        }
    }

    // Create stable service table row for FK integrity (race-safe).
    try {
        if ($hasIsDelivery) {
            $ins = $pdo->prepare('INSERT INTO tables (restaurant_id, name, is_delivery, created_at) VALUES (?, ?, 1, NOW())');
            $ins->execute([$restaurantId, $name]);
        } else {
            $ins = $pdo->prepare('INSERT INTO tables (restaurant_id, name, created_at) VALUES (?, ?, NOW())');
            $ins->execute([$restaurantId, $name]);
        }
    } catch (Throwable $eIns) {
        // Concurrent request may have created the row; fall back to lookup.
        if ($hasIsDelivery) {
            $stmt = $pdo->prepare('SELECT * FROM tables WHERE restaurant_id = ? AND is_delivery = 1 ORDER BY id ASC LIMIT 1');
            $stmt->execute([$restaurantId]);
        } else {
            $stmt = $pdo->prepare('SELECT * FROM tables WHERE restaurant_id = ? AND name = ? ORDER BY id ASC LIMIT 1');
            $stmt->execute([$restaurantId, $name]);
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return $row;
        }
        throw $eIns;
    }

    $id = (int)$pdo->lastInsertId();
    $stmt = $pdo->prepare('SELECT * FROM tables WHERE id = ? AND restaurant_id = ? LIMIT 1');
    $stmt->execute([$id, $restaurantId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new RuntimeException('delivery_table_create_failed');
    }
    return $row;
}

function qr_public_build_url(string $path, array $query): string
{
    $path = $path === '' ? '/' : $path;
    $q = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    if ($q === '') {
        return $path;
    }
    return $path . (str_contains($path, '?') ? '&' : '?') . $q;
}

/**
 * Extra AND … fragment to hide the service “delivery” table row in owner/staff listings.
 * Works before/after migration: uses is_delivery when present, otherwise exact name match.
 */
function qr_public_sql_exclude_delivery(PDO $pdo, ?string $alias = null): string
{
    $prefix = ($alias !== null && $alias !== '') ? ($alias . '.') : '';
    if (function_exists('db_column_exists') && db_column_exists('tables', 'is_delivery')) {
        return ' AND (' . $prefix . 'is_delivery IS NULL OR ' . $prefix . 'is_delivery = 0) ';
    }
    $q = $pdo->quote(qr_public_delivery_table_name());
    return ' AND (' . $prefix . 'name IS NULL OR ' . $prefix . 'name <> ' . $q . ') ';
}

/**
 * Owner/staff-facing label for order source (never show raw service delivery table name).
 */
function qr_public_owner_order_table_label(?string $tableName): string
{
    $name = trim((string)($tableName ?? ''));
    if ($name === '') {
        return '';
    }
    if (qr_public_is_delivery_table_row(['name' => $name])) {
        return 'Доставка';
    }
    return $name;
}
