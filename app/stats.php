<?php

require_once __DIR__ . '/db.php';

function stats_tz_offset_hours(): int
{
    // Смещение относительно UTC для аналитики (Europe/Moscow)
    return 3;
}

function stats_set_tz(): void
{
    date_default_timezone_set('Europe/Moscow');
}

/**
 * Конвертирует московские границы периода в UTC. Возвращает полуинтервал [startUtc, endUtc) для SQL: created_at >= startUtc AND created_at < endUtc.
 * UI передаёт end как "23:59:59" (включительно) — здесь endUtc = конец дня в UTC + 1 сек, чтобы этот момент попал в полуинтервал.
 *
 * @param string|null $startMsk Начало периода MSK (например "2025-01-01 00:00:00")
 * @param string|null $endMsk   Конец периода MSK включительно (например "2025-01-07 23:59:59")
 * @return array{0:?string,1:?string} [startUtc, endUtcExclusive] или [null, null] при невалидных датах
 */
function stats_period_to_utc_bounds(?string $startMsk, ?string $endMsk): array
{
    if ($startMsk === null || $endMsk === null || trim($startMsk) === '' || trim($endMsk) === '') {
        return [null, null];
    }

    try {
        $tzMsk = new DateTimeZone('Europe/Moscow');
        $tzUtc = new DateTimeZone('UTC');

        $startMskDt = new DateTimeImmutable(trim($startMsk), $tzMsk);
        $endMskDt   = new DateTimeImmutable(trim($endMsk), $tzMsk);

        if ($startMskDt > $endMskDt) {
            return [null, null];
        }

        $startUtcDt = $startMskDt->setTimezone($tzUtc);
        $endUtcDt   = $endMskDt->setTimezone($tzUtc);
        $endUtcExcl = $endUtcDt->modify('+1 second');

        return [$startUtcDt->format('Y-m-d H:i:s'), $endUtcExcl->format('Y-m-d H:i:s')];
    } catch (Throwable $e) {
        error_log('STATS_UTC_BOUNDS_ERROR start=' . (string)$startMsk . ' end=' . (string)$endMsk . ' ' . $e->getMessage());
        return [null, null];
    }
}

/**
 * @return array{0:?string,1:?string,2:string} [$start, $end, $normalizedRange]
 */
function stats_period_range(string $range): array
{
    stats_set_tz();

    $range = strtolower(trim($range));
    if (!in_array($range, ['today', '7d', '30d', 'all'], true)) {
        $range = '7d';
    }

    $start = null;
    $end   = null;

    if ($range === 'today') {
        $start = date('Y-m-d 00:00:00');
        $end   = date('Y-m-d 23:59:59');
    } elseif ($range === '7d') {
        $start = date('Y-m-d 00:00:00', strtotime('-6 days'));
        $end   = date('Y-m-d 23:59:59');
    } elseif ($range === '30d') {
        $start = date('Y-m-d 00:00:00', strtotime('-29 days'));
        $end   = date('Y-m-d 23:59:59');
    } elseif ($range === 'all') {
        $start = null;
        $end   = null;
    }

    return [$start, $end, $range];
}

/**
 * @param int[]       $restaurantIds
 * @param string|null $start
 * @param string|null $end
 * @return array<int,array{revenue:float,orders:int}>
 */
function stats_revenue_by_restaurants(array $restaurantIds, ?string $start, ?string $end): array
{
    $result = [];
    $ids = stats__normalize_restaurant_ids($restaurantIds);
    if (empty($ids)) {
        return $result;
    }

    $whereData = stats__orders_where_sql('o', $ids, $start, $end);
    $sql = "SELECT o.restaurant_id, COALESCE(SUM(o.total_price), 0) AS revenue, COUNT(*) AS orders_count
            FROM orders o WHERE " . $whereData['where'] . " GROUP BY o.restaurant_id";
    $stmt = db()->prepare($sql);
    $stmt->execute($whereData['params']);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rid = (int)$row['restaurant_id'];
        $result[$rid] = [
            'revenue' => (float)($row['revenue'] ?? 0),
            'orders'  => (int)($row['orders_count'] ?? 0),
        ];
    }
    return $result;
}

/** Валидные статусы для «активных» заказов (без paid/canceled). */
const STATS__ACTIVE_ORDER_STATUSES = ['new', 'accepted', 'cooking', 'ready'];

/**
 * @param int[] $restaurantIds
 * @return array<int,array{new:int,accepted:int,cooking:int,ready:int}>
 */
function stats_active_orders_by_restaurants(array $restaurantIds): array
{
    $result = [];
    $ids = stats__normalize_restaurant_ids($restaurantIds);
    if (empty($ids)) {
        return $result;
    }

    $ph = stats__placeholders(count($ids));
    $sql = "SELECT restaurant_id, order_status, COUNT(*) AS cnt
            FROM orders
            WHERE restaurant_id IN ($ph) AND order_status IN ('new','accepted','cooking','ready')
            GROUP BY restaurant_id, order_status";
    $stmt = db()->prepare($sql);
    $stmt->execute(array_values($ids));

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rid = (int)$row['restaurant_id'];
        $st  = (string)$row['order_status'];

        if (!isset($result[$rid])) {
            $result[$rid] = [
                'new'      => 0,
                'accepted' => 0,
                'cooking'  => 0,
                'ready'    => 0,
            ];
        }
        if (in_array($st, ['new', 'accepted', 'cooking', 'ready'], true)) {
            $result[$rid][$st] = (int)$row['cnt'];
        }
    }

    return $result;
}

function format_money(float $n): string
{
    $rounded = floor($n + 0.00001);
    return number_format($rounded, 0, '.', ' ') . ' ₽';
}

// --- Internal helpers (stats__*) для единой архитектуры запросов ---

/** Максимум ресторанов в одном stats-запросе (защита от огромных IN и лимитов БД). */
const STATS__MAX_RESTAURANT_IDS = 500;

/**
 * Нормализует и дедуплицирует ID ресторанов. Ограничивает список STATS__MAX_RESTAURANT_IDS с логом.
 * @return array<int>
 */
function stats__normalize_restaurant_ids(array $ids): array
{
    $out = [];
    foreach ($ids as $id) {
        $k = (int)$id;
        if ($k > 0) {
            $out[$k] = true;
        }
    }
    $out = array_keys($out);
    sort($out, SORT_NUMERIC);
    if (count($out) > STATS__MAX_RESTAURANT_IDS) {
        error_log('STATS_SCOPE_TRUNCATED rest_count=' . count($out) . ' max=' . STATS__MAX_RESTAURANT_IDS);
        $out = array_slice($out, 0, STATS__MAX_RESTAURANT_IDS);
    }
    return $out;
}

function stats__placeholders(int $n): string
{
    return $n > 0 ? implode(',', array_fill(0, $n, '?')) : '';
}

/** @return array{0:?string,1:?string} [startUtc, endUtc] полуинтервал [start, end) */
function stats__utc_bounds(?string $startMsk, ?string $endMsk): array
{
    return stats_period_to_utc_bounds($startMsk, $endMsk);
}

/**
 * WHERE-фрагмент и params для базового набора заказов (paid, non-canceled, restaurant_id, полуинтервал по created_at).
 * @return array{where:string,params:array}
 */
function stats__orders_where_sql(string $alias, array $restaurantIds, ?string $startMsk, ?string $endMsk): array
{
    $ids = stats__normalize_restaurant_ids($restaurantIds);
    $params = array_values($ids);
    $ph = stats__placeholders(count($ids));
    $where = "{$alias}.restaurant_id IN ($ph) AND {$alias}.payment_status = 'paid' AND {$alias}.order_status <> 'canceled'";
    [$startUtc, $endUtc] = stats__utc_bounds($startMsk, $endMsk);
    if ($startUtc !== null && $endUtc !== null) {
        $where .= " AND {$alias}.created_at >= ? AND {$alias}.created_at < ?";
        $params[] = $startUtc;
        $params[] = $endUtc;
    }
    return ['where' => $where, 'params' => $params];
}

/**
 * Подзапрос базовых заказов. minimal: id, restaurant_id; extended: + created_at, total_price.
 * @return array{sql:string,params:array}
 */
function stats__orders_base_subquery(string $alias, array $restaurantIds, ?string $startMsk, ?string $endMsk, bool $extended = false): array
{
    $ids = stats__normalize_restaurant_ids($restaurantIds);
    if (empty($ids)) {
        return ['sql' => '', 'params' => []];
    }
    $whereData = stats__orders_where_sql($alias, $ids, $startMsk, $endMsk);
    $cols = $extended
        ? "{$alias}.id, {$alias}.restaurant_id, {$alias}.created_at, {$alias}.total_price"
        : "{$alias}.id, {$alias}.restaurant_id";
    $sql = "( SELECT $cols FROM orders $alias WHERE " . $whereData['where'] . " ) $alias";
    return ['sql' => $sql, 'params' => $whereData['params']];
}

/** Обратная совместимость: возвращает только SQL; params строит вызывающий код через stats__orders_where_sql. */
function stats_orders_base_sql(string $ordersAlias, string $placeholders, string $whereDate): string
{
    return "
        ( SELECT o.id, o.restaurant_id
          FROM orders o
          WHERE o.restaurant_id IN ($placeholders)
            AND o.payment_status = 'paid'
            AND o.order_status <> 'canceled'
            $whereDate
        ) $ordersAlias
    ";
}

/**
 * @param int[] $restaurantIds
 * @param int   $days 7 или 30
 * @return array<string,float> ['Y-m-d' => revenue]
 */
function stats_daily_revenue(array $restaurantIds, int $days): array
{
    stats_set_tz();
    $ids = stats__normalize_restaurant_ids($restaurantIds);
    $days = (int)$days;
    if (empty($ids) || $days <= 0) {
        return [];
    }
    $offset = (int)stats_tz_offset_hours();
    $startTsMsk = strtotime('-' . ($days - 1) . ' days');
    $startMsk   = date('Y-m-d 00:00:00', $startTsMsk);
    try {
        $tzMsk = new DateTimeZone('Europe/Moscow');
        $tzUtc = new DateTimeZone('UTC');
        $startUtc = (new DateTimeImmutable($startMsk, $tzMsk))->setTimezone($tzUtc)->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        $startUtc = $startMsk;
    }
    $ph = stats__placeholders(count($ids));
    $params = array_values($ids);
    $params[] = $startUtc;
    $sql = "SELECT DATE(DATE_ADD(o.created_at, INTERVAL {$offset} HOUR)) AS d, COALESCE(SUM(o.total_price),0) AS revenue
            FROM orders o
            WHERE o.restaurant_id IN ($ph) AND o.created_at >= ? AND o.payment_status = 'paid' AND o.order_status <> 'canceled'
            GROUP BY DATE(DATE_ADD(o.created_at, INTERVAL {$offset} HOUR))";
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $byDate = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $byDate[(string)$row['d']] = (float)($row['revenue'] ?? 0);
    }
    $result = [];
    for ($i = 0; $i < $days; $i++) {
        $date = date('Y-m-d', strtotime('+' . $i . ' days', $startTsMsk));
        $result[$date] = $byDate[$date] ?? 0.0;
    }
    return $result;
}

/**
 * Сводка выручки по списку ресторанов за период.
 *
 * @param int[]       $restaurantIds
 * @param string|null $start
 * @param string|null $end
 * @return array{revenue:float,orders:int,avg:float}
 */
function stats_revenue_summary(array $restaurantIds, ?string $start, ?string $end): array
{
    $ids = stats__normalize_restaurant_ids($restaurantIds);
    if (empty($ids)) {
        return ['revenue' => 0.0, 'orders' => 0, 'avg' => 0.0];
    }
    $whereData = stats__orders_where_sql('o', $ids, $start, $end);
    $sql = "SELECT COALESCE(SUM(o.total_price), 0) AS revenue, COUNT(*) AS orders_count FROM orders o WHERE " . $whereData['where'];
    $stmt = db()->prepare($sql);
    $stmt->execute($whereData['params']);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $revenue = (float)($row['revenue'] ?? 0);
    $orders  = (int)($row['orders_count'] ?? 0);
    $avg     = $orders > 0 ? ($revenue / $orders) : 0.0;
    return ['revenue' => $revenue, 'orders' => $orders, 'avg' => $avg];
}

/**
 * Предыдущий период для сравнения по range.
 *
 * @param string      $range normalized (today|7d|30d|all)
 * @param string|null $currentStart
 * @param string|null $currentEnd
 * @return array{0:?string,1:?string}
 */
function stats_prev_period_range(string $range, ?string $currentStart, ?string $currentEnd): array
{
    stats_set_tz();

    if ($range === 'all' || $currentStart === null || $currentEnd === null) {
        return [null, null];
    }

    $startTs = strtotime($currentStart);

    if ($range === 'today') {
        $prevStart = date('Y-m-d 00:00:00', strtotime('-1 day', $startTs));
        $prevEnd   = date('Y-m-d 23:59:59', strtotime('-1 day', $startTs));
        return [$prevStart, $prevEnd];
    }

    if ($range === '7d') {
        $prevStart = date('Y-m-d 00:00:00', strtotime('-7 days', $startTs));
        $prevEnd   = date('Y-m-d 23:59:59', strtotime('-1 day', $startTs));
        return [$prevStart, $prevEnd];
    }

    if ($range === '30d') {
        $prevStart = date('Y-m-d 00:00:00', strtotime('-30 days', $startTs));
        $prevEnd   = date('Y-m-d 23:59:59', strtotime('-1 day', $startTs));
        return [$prevStart, $prevEnd];
    }

    return [null, null];
}

/**
 * Топ-блюда по выручке за период.
 *
 * @param int[]       $restaurantIds
 * @param string|null $start
 * @param string|null $end
 * @param int         $limit
 * @return array<int,array{name:string,revenue:float,qty:int}>
 */
function stats_top_items(array $restaurantIds, ?string $start, ?string $end, int $limit = 3): array
{
    $limit = (int)$limit;
    if ($limit <= 0) {
        return [];
    }
    $base = stats__orders_base_subquery('o', $restaurantIds, $start, $end, false);
    if ($base['sql'] === '') {
        return [];
    }
    $sql = "SELECT mi.name AS name,
            COALESCE(SUM(oi.price * oi.quantity), 0) AS revenue,
            COALESCE(SUM(oi.quantity), 0) AS qty
            FROM {$base['sql']}
            JOIN order_items oi ON oi.order_id = o.id
            JOIN menu_items mi ON mi.id = oi.menu_item_id AND mi.restaurant_id = o.restaurant_id
            GROUP BY mi.id, mi.name
            ORDER BY revenue DESC
            LIMIT $limit";
    $stmt = db()->prepare($sql);
    $stmt->execute($base['params']);
    $result = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $result[] = [
            'name'    => (string)($row['name'] ?? ''),
            'revenue' => (float)($row['revenue'] ?? 0),
            'qty'     => (int)($row['qty'] ?? 0),
        ];
    }
    return $result;
}

/**
 * Конверсия заказов по ресторанам за период.
 *
 * @param int[]       $restaurantIds
 * @param string|null $start
 * @param string|null $end
 * @return array{total:int,paid:int,canceled:int,conversion_paid_pct:?float,canceled_pct:?float}
 */
function stats_conversion(array $restaurantIds, ?string $start, ?string $end): array
{
    $ids = stats__normalize_restaurant_ids($restaurantIds);
    if (empty($ids)) {
        return [
            'total'               => 0,
            'paid'                => 0,
            'canceled'            => 0,
            'conversion_paid_pct' => null,
            'canceled_pct'        => null,
        ];
    }
    [$startUtc, $endUtc] = stats__utc_bounds($start, $end);
    $params = array_values($ids);
    $whereDate = '';
    if ($startUtc !== null && $endUtc !== null) {
        $whereDate = ' AND created_at >= ? AND created_at < ? ';
        $params[] = $startUtc;
        $params[] = $endUtc;
    }
    $ph = stats__placeholders(count($ids));
    $sql = "SELECT COUNT(*) AS total_orders,
            SUM(CASE WHEN payment_status = 'paid' THEN 1 ELSE 0 END) AS paid_orders,
            SUM(CASE WHEN order_status = 'canceled' THEN 1 ELSE 0 END) AS canceled_orders
            FROM orders WHERE restaurant_id IN ($ph) $whereDate";
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $total    = (int)($row['total_orders'] ?? 0);
    $paid     = (int)($row['paid_orders'] ?? 0);
    $canceled = (int)($row['canceled_orders'] ?? 0);
    $convPaidPct = $total > 0 ? ($paid / $total * 100.0) : null;
    $canceledPct = $total > 0 ? ($canceled / $total * 100.0) : null;
    return [
        'total'               => $total,
        'paid'                => $paid,
        'canceled'            => $canceled,
        'conversion_paid_pct' => $convPaidPct,
        'canceled_pct'        => $canceledPct,
    ];
}

/**
 * Топ категории по выручке за период.
 *
 * @param int[]       $restaurantIds
 * @param string|null $start
 * @param string|null $end
 * @param int         $limit
 * @return array<int,array{name:string,revenue:float,qty:int}>
 */
function stats_top_categories(array $restaurantIds, ?string $start, ?string $end, int $limit = 3): array
{
    $limit = (int)$limit;
    if ($limit <= 0) {
        return [];
    }
    $base = stats__orders_base_subquery('o', $restaurantIds, $start, $end, false);
    if ($base['sql'] === '') {
        return [];
    }
    $sql = "SELECT mc.name AS name,
            COALESCE(SUM(oi.price * oi.quantity), 0) AS revenue,
            COALESCE(SUM(oi.quantity), 0) AS qty
            FROM {$base['sql']}
            JOIN order_items oi ON oi.order_id = o.id
            JOIN menu_items mi ON mi.id = oi.menu_item_id AND mi.restaurant_id = o.restaurant_id
            JOIN menu_categories mc ON mc.id = mi.category_id AND mc.restaurant_id = mi.restaurant_id
            GROUP BY mc.id, mc.name
            ORDER BY revenue DESC
            LIMIT $limit";
    $stmt = db()->prepare($sql);
    $stmt->execute($base['params']);
    $result = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $result[] = [
            'name'    => (string)($row['name'] ?? ''),
            'revenue' => (float)($row['revenue'] ?? 0),
            'qty'     => (int)($row['qty'] ?? 0),
        ];
    }
    return $result;
}

/**
 * Маржинальность по всем ресторанам за период.
 *
 * @param int[]       $restaurantIds
 * @param string|null $start
 * @param string|null $end
 * @return array{revenue:float,profit:float,margin_pct:?float}
 */
function stats_margin(array $restaurantIds, ?string $start, ?string $end): array
{
    $base = stats__orders_base_subquery('o', $restaurantIds, $start, $end, false);
    if ($base['sql'] === '') {
        return ['revenue' => 0.0, 'profit' => 0.0, 'margin_pct' => null];
    }
    $sqlRevenue = "SELECT COALESCE(SUM(oi.price * oi.quantity), 0) AS revenue FROM {$base['sql']} JOIN order_items oi ON oi.order_id = o.id";
    $stmt = db()->prepare($sqlRevenue);
    $stmt->execute($base['params']);
    $revenue = (float)($stmt->fetchColumn() ?: 0.0);

    static $hasCostPrice = null;
    if ($hasCostPrice === null) {
        try {
            $chk = db()->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'menu_items' AND COLUMN_NAME = 'cost_price'");
            $hasCostPrice = ((int)($chk->fetchColumn() ?: 0)) > 0;
        } catch (Throwable $e) {
            $hasCostPrice = false;
        }
    }
    if (!$hasCostPrice) {
        return ['revenue' => $revenue, 'profit' => 0.0, 'margin_pct' => null];
    }
    try {
        $sqlProfit = "SELECT COALESCE(SUM((oi.price - COALESCE(mi.cost_price,0)) * oi.quantity), 0) AS profit
            FROM {$base['sql']} JOIN order_items oi ON oi.order_id = o.id
            JOIN menu_items mi ON mi.id = oi.menu_item_id AND mi.restaurant_id = o.restaurant_id";
        $stmt = db()->prepare($sqlProfit);
        $stmt->execute($base['params']);
        $profit = (float)($stmt->fetchColumn() ?: 0.0);
    } catch (PDOException $e) {
        $hasCostPrice = false;
        return ['revenue' => $revenue, 'profit' => 0.0, 'margin_pct' => null];
    }
    $margin = $revenue > 0 ? ($profit / $revenue * 100.0) : null;
    return ['revenue' => $revenue, 'profit' => $profit, 'margin_pct' => $margin];
}

/**
 * Доля топ-3 блюд в общей выручке.
 *
 * @param int[]       $restaurantIds
 * @param string|null $start
 * @param string|null $end
 * @return array{top3_revenue:float,total_revenue:float,share_pct:?float}
 */
function stats_top_share(array $restaurantIds, ?string $start, ?string $end): array
{
    $ids = stats__normalize_restaurant_ids($restaurantIds);
    if (empty($ids)) {
        return ['top3_revenue' => 0.0, 'total_revenue' => 0.0, 'share_pct' => null];
    }
    $summary   = stats_revenue_summary($ids, $start, $end);
    $total     = (float)$summary['revenue'];
    $topItems  = stats_top_items($ids, $start, $end, 3);
    $top3Rev   = 0.0;

    foreach ($topItems as $it) {
        $top3Rev += (float)($it['revenue'] ?? 0);
    }

    $share = $total > 0 ? ($top3Rev / $total * 100.0) : null;

    return [
        'top3_revenue'  => $top3Rev,
        'total_revenue' => $total,
        'share_pct'     => $share,
    ];
}

/**
 * Почасовая загрузка: количество оплаченных заказов по часам (0-23).
 *
 * @param int[]       $restaurantIds
 * @param string|null $start
 * @param string|null $end
 * @return array<int,int> индекс 0..23 => count
 */
function stats_hourly_heatmap(array $restaurantIds, ?string $start, ?string $end): array
{
    stats_set_tz();
    $result = array_fill(0, 24, 0);
    $ids = stats__normalize_restaurant_ids($restaurantIds);
    if (empty($ids)) {
        return $result;
    }
    $whereData = stats__orders_where_sql('o', $ids, $start, $end);
    $offset = (int)stats_tz_offset_hours();
    $sql = "SELECT HOUR(DATE_ADD(o.created_at, INTERVAL {$offset} HOUR)) AS h, COUNT(*) AS cnt
            FROM orders o WHERE " . $whereData['where'] . "
            GROUP BY HOUR(DATE_ADD(o.created_at, INTERVAL {$offset} HOUR))";
    $stmt = db()->prepare($sql);
    $stmt->execute($whereData['params']);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $h = (int)($row['h'] ?? 0);
        if ($h >= 0 && $h <= 23) {
            $result[$h] = (int)($row['cnt'] ?? 0);
        }
    }
    return $result;
}

/**
 * Хэш для скоупа алертов/дашборда (рестораны + период + выбранный rest_id).
 *
 * @param int[]       $restaurantIds
 * @param string      $rangeNorm
 * @param string|null $startMsk
 * @param string|null $endMsk
 * @param int|string  $selectedRest 'all' или int
 */
function stats_scope_hash(array $restaurantIds, string $rangeNorm, ?string $startMsk, ?string $endMsk, $selectedRest): string
{
    $ids = array_map('intval', $restaurantIds);
    sort($ids, SORT_NUMERIC);
    $restIdsStr = implode(',', $ids);

    $restStr = ($selectedRest === 'all') ? 'all' : (string)(int)$selectedRest;

    $parts = [
        'rest_ids=' . $restIdsStr,
        'range=' . $rangeNorm,
        'start=' . ($startMsk ?? ''),
        'end=' . ($endMsk ?? ''),
        'rest_id=' . $restStr,
    ];

    $base = implode('|', $parts);
    return hash('sha256', $base);
}

/**
 * Умные уведомления/алерты для дашборда владельца.
 *
 * @param int[]       $restaurantIds
 * @param string      $rangeNormalized normalized range (today|7d|30d|all)
 * @param string|null $startMsk
 * @param string|null $endMsk
 * @return array<int,array{
 *     key:string,
 *     severity:string,
 *     title:string,
 *     message:string,
 *     meta:array,
 *     actions?:array<int,array{label:string,url:string}>
 * }>
 */
function stats_alerts(array $restaurantIds, string $rangeNormalized, ?string $startMsk, ?string $endMsk): array
{
    $alerts = [];
    $ids = stats__normalize_restaurant_ids($restaurantIds);
    if (empty($ids)) {
        return $alerts;
    }

    try {
    // A1, A2, A6 — на основе конверсии
    $conv = stats_conversion($ids, $startMsk, $endMsk);
    $total    = (int)$conv['total'];
    $paid     = (int)$conv['paid'];
    $canceled = (int)$conv['canceled'];

    if ($total > 0) {
        $paidPct = $total > 0 ? ($paid / $total * 100.0) : 0.0;
        $cancPct = $total > 0 ? ($canceled / $total * 100.0) : 0.0;

        // A1: Низкая конверсия
        if ($total >= 20 && $paidPct < 30.0) {
            $alerts[] = [
                'key'      => 'low_conversion',
                'severity' => 'warning',
                'title'    => 'Низкая конверсия',
                'message'  => sprintf('Оплачено %.1f%% из %d заказов', round($paidPct, 1), $total),
                'meta'     => [
                    'paid_orders'   => $paid,
                    'total_orders'  => $total,
                    'threshold_pct' => '<30%',
                    'threshold_min' => 20,
                ],
                'actions'  => [
                    [
                        'label' => 'Открыть заказы',
                        'url'   => '/restaurant/orders.php',
                    ],
                ],
            ];
        }

        // A2: Высокая доля отмен
        if ($total >= 20 && $cancPct > 10.0) {
            $alerts[] = [
                'key'      => 'high_cancellations',
                'severity' => 'warning',
                'title'    => 'Высокая доля отмен',
                'message'  => sprintf('Отменено %.1f%% (%d из %d заказов)', round($cancPct, 1), $canceled, $total),
                'meta'     => [
                    'canceled_orders' => $canceled,
                    'total_orders'    => $total,
                    'threshold_pct'   => '>10%',
                    'threshold_min'   => 20,
                ],
                'actions'  => [
                    [
                        'label' => 'Открыть заказы',
                        'url'   => '/restaurant/orders.php',
                    ],
                ],
            ];
        }

        // A6 (опционально): Нет оплаченных заказов, но есть заказы всего
        if ($paid === 0 && $total > 0) {
            $alerts[] = [
                'key'      => 'no_paid_orders',
                'severity' => 'critical',
                'title'    => 'Нет оплаченных заказов',
                'message'  => sprintf('За период нет оплаченных заказов, всего создано %d заказов', $total),
                'meta'     => [
                    'total_orders' => $total,
                ],
                'actions'  => [
                    [
                        'label' => 'Открыть заказы',
                        'url'   => '/restaurant/orders.php',
                    ],
                ],
            ];
        }
    }

    // A3: Выручка упала vs прошлый период
    if ($startMsk !== null && $endMsk !== null && $rangeNormalized !== 'all') {
        [$prevStart, $prevEnd] = stats_prev_period_range($rangeNormalized, $startMsk, $endMsk);
        if ($prevStart !== null && $prevEnd !== null) {
            $currSummary = stats_revenue_summary($ids, $startMsk, $endMsk);
            $prevSummary = stats_revenue_summary($ids, $prevStart, $prevEnd);

            $currRev = (float)$currSummary['revenue'];
            $prevRev = (float)$prevSummary['revenue'];

            if ($prevRev > 0 && $currRev < $prevRev * 0.8) {
                $dropPct = ($prevRev - $currRev) / $prevRev * 100.0;
                $alerts[] = [
                    'key'      => 'revenue_drop',
                    'severity' => 'warning',
                    'title'    => 'Падение выручки к прошлому периоду',
                    'message'  => sprintf(
                        'Выручка упала на %.1f%% (с %s до %s)',
                        round($dropPct, 1),
                        format_money($prevRev),
                        format_money($currRev)
                    ),
                    'meta'     => [
                        'prev_revenue'    => $prevRev,
                        'current_revenue' => $currRev,
                        'threshold_pct'   => '>20%',
                        'prev_start'      => $prevStart,
                        'prev_end'        => $prevEnd,
                    ],
                ];
            }
        }
    }

    // A4: Топ-3 блюда дают слишком большую долю
    $topShare = stats_top_share($ids, $startMsk, $endMsk);
    $sharePct = $topShare['share_pct'] ?? null;
    $totalRev = (float)($topShare['total_revenue'] ?? 0.0);
    if ($sharePct !== null && $totalRev > 0.0 && $sharePct > 70.0) {
        $alerts[] = [
            'key'      => 'top3_share_high',
            'severity' => 'warning',
            'title'    => 'Слишком высокая доля топ‑3 блюд',
            'message'  => sprintf(
                'Топ‑3 блюда дают %.1f%% выручки (%s из %s)',
                round($sharePct, 1),
                format_money((float)$topShare['top3_revenue']),
                format_money($totalRev)
            ),
            'meta'     => [
                'share_pct'     => $sharePct,
                'threshold_pct' => '>70%',
            ],
            'actions'  => [
                [
                    'label' => 'Открыть меню',
                    'url'   => '/restaurant/menu_items.php',
                ],
            ],
        ];
    }

    // A5: Проблемы меню — блюда без корректной категории
    $pdo = db();
    $placeholdersMi = stats__placeholders(count($ids));
    $paramsMi       = array_values($ids);

    $sqlMi = "
        SELECT COUNT(*) AS cnt
        FROM menu_items mi
        LEFT JOIN menu_categories mc
          ON mc.id = mi.category_id
         AND mc.restaurant_id = mi.restaurant_id
        WHERE mi.restaurant_id IN ($placeholdersMi)
          AND (
               mi.category_id IS NULL
            OR mc.id IS NULL
          )
    ";

    $stmtMi = $pdo->prepare($sqlMi);
    $stmtMi->execute($paramsMi);
    $badMenuCnt = (int)($stmtMi->fetchColumn() ?: 0);

    if ($badMenuCnt > 0) {
        $alerts[] = [
            'key'      => 'menu_without_category',
            'severity' => 'warning',
            'title'    => 'Проблемы в структуре меню',
            'message'  => sprintf('Найдено %d блюд без категории или с некорректной категорией', $badMenuCnt),
            'meta'     => [
                'items_without_category' => $badMenuCnt,
                'note'                   => 'Порог: >0 блюд без категории в выбранных ресторанах',
            ],
            'actions'  => [
                [
                    'label' => 'Открыть блюда без категории',
                    'url'   => '/restaurant/menu_items.php?filter=uncategorized',
                ],
            ],
        ];
    }

    // A7 (опционально): Низкая маржинальность, если есть cost_price
    $margin = stats_margin($ids, $startMsk, $endMsk);
    $marginPct = $margin['margin_pct'];
    if ($marginPct !== null && $marginPct < 15.0) {
        $alerts[] = [
            'key'      => 'low_margin',
            'severity' => 'warning',
            'title'    => 'Низкая маржинальность',
            'message'  => sprintf('Маржа по выручке всего %.1f%%', round($marginPct, 1)),
            'meta'     => [
                'margin_pct'   => $marginPct,
                'threshold_pct'=> '<15%',
            ],
            'actions'  => [
                [
                    'label' => 'Проверить себестоимость',
                    'url'   => '/restaurant/menu_items.php',
                ],
            ],
        ];
    }

    // Сортируем по severity: critical > warning > info
    $priority = ['critical' => 3, 'warning' => 2, 'info' => 1];
    usort($alerts, function (array $a, array $b) use ($priority): int {
        $pa = $priority[$a['severity']] ?? 0;
        $pb = $priority[$b['severity']] ?? 0;
        if ($pa === $pb) {
            return 0;
        }
        return $pb <=> $pa;
    });

    // Ограничиваем до 7 алертов
    if (count($alerts) > 7) {
        $alerts = array_slice($alerts, 0, 7);
    }

    } catch (Throwable $e) {
        error_log('STATS_ALERTS_ERROR ' . $e->getMessage());
        return [];
    }

    return $alerts;
}

