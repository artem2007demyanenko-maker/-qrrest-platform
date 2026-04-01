<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
if (file_exists(__DIR__ . '/schema_guard.php')) {
    require_once __DIR__ . '/schema_guard.php';
}

function kds_allowed_stations(): array
{
    return ['hot', 'cold', 'bar', 'dessert'];
}

function kds_station_label_ru(string $station): string
{
    $map = [
        'hot' => 'Горячий цех',
        'cold' => 'Холодный цех',
        'bar' => 'Бар',
        'dessert' => 'Десерты',
    ];
    $s = kds_normalize_station($station);
    return $map[$s] ?? 'Горячий цех';
}

function kds_normalize_station(string $station): string
{
    $s = strtolower(trim($station));
    $map = [
        'hot' => 'hot',
        'cold' => 'cold',
        'bar' => 'bar',
        'dessert' => 'dessert',
        'desserts' => 'dessert',
        'горячий' => 'hot',
        'холодный' => 'cold',
    ];
    $s = $map[$s] ?? $s;
    return in_array($s, kds_allowed_stations(), true) ? $s : 'hot';
}

function kds_to_legacy_kitchen_station(string $station): string
{
    $s = kds_normalize_station($station);
    return strtoupper($s);
}

function kds_ensure_schema(PDO $pdo): void
{
    try {
        if (!function_exists('db_column_exists') || !db_column_exists('menu_items', 'station')) {
            $pdo->exec("ALTER TABLE menu_items ADD COLUMN station VARCHAR(50) NOT NULL DEFAULT 'hot'");
        }
    } catch (Throwable $e) {
        // best-effort
    }

    try {
        if (!function_exists('db_column_exists') || !db_column_exists('order_items', 'station_status')) {
            $pdo->exec("ALTER TABLE order_items ADD COLUMN station_status VARCHAR(32) NOT NULL DEFAULT 'new'");
        }
    } catch (Throwable $e) {
        // best-effort
    }

    try {
        if (!function_exists('db_column_exists') || !db_column_exists('order_items', 'started_at')) {
            $pdo->exec("ALTER TABLE order_items ADD COLUMN started_at DATETIME NULL");
        }
    } catch (Throwable $e) {
        // best-effort
    }

    try {
        if (!function_exists('db_column_exists') || !db_column_exists('order_items', 'ready_at')) {
            $pdo->exec("ALTER TABLE order_items ADD COLUMN ready_at DATETIME NULL");
        }
    } catch (Throwable $e) {
        // best-effort
    }
}

function kds_menu_station_expr(PDO $pdo, string $menuAlias = 'mi'): string
{
    $hasStation = function_exists('db_column_exists') && db_column_exists('menu_items', 'station');
    $hasLegacy = function_exists('db_column_exists') && db_column_exists('menu_items', 'kitchen_station');

    if ($hasStation && $hasLegacy) {
        return "LOWER(COALESCE(NULLIF(TRIM({$menuAlias}.station), ''), NULLIF(TRIM({$menuAlias}.kitchen_station), ''), 'hot'))";
    }
    if ($hasStation) {
        return "LOWER(COALESCE(NULLIF(TRIM({$menuAlias}.station), ''), 'hot'))";
    }
    if ($hasLegacy) {
        return "LOWER(COALESCE(NULLIF(TRIM({$menuAlias}.kitchen_station), ''), 'hot'))";
    }
    return "'hot'";
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

    $hasStationStatus = function_exists('db_column_exists') && db_column_exists('order_items', 'station_status');
    if (!$hasStationStatus) {
        return;
    }

    $stmtAgg = $pdo->prepare("
        SELECT
            COUNT(*) AS total_cnt,
            SUM(CASE WHEN station_status = 'new' THEN 1 ELSE 0 END) AS new_cnt,
            SUM(CASE WHEN station_status = 'accepted' THEN 1 ELSE 0 END) AS accepted_cnt,
            SUM(CASE WHEN station_status = 'cooking' THEN 1 ELSE 0 END) AS cooking_cnt,
            SUM(CASE WHEN station_status = 'ready' THEN 1 ELSE 0 END) AS ready_cnt
        FROM order_items
        WHERE order_id = :oid
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

    $hasStationStatus = function_exists('db_column_exists') && db_column_exists('order_items', 'station_status');
    if (!$hasStationStatus) {
        return $fallback;
    }

    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total_cnt,
            SUM(CASE WHEN station_status = 'ready' THEN 1 ELSE 0 END) AS ready_cnt
        FROM order_items
        WHERE order_id = :oid
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
