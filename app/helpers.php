<?php


require_once __DIR__ . '/db.php';

function e(?string $value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

if (!function_exists('guest_normalize_phone')) {
    function guest_normalize_phone(?string $raw): ?string
    {
        $digits = preg_replace('/\D+/', '', (string)($raw ?? ''));
        if (!is_string($digits) || $digits === '') {
            return null;
        }
        if (strlen($digits) === 10) {
            $digits = '7' . $digits;
        } elseif (strlen($digits) === 11 && $digits[0] === '8') {
            $digits[0] = '7';
        }
        if (strlen($digits) !== 11 || $digits[0] !== '7') {
            return null;
        }
        return $digits;
    }
}

if (!function_exists('guest_phone_candidates')) {
    /**
     * @return list<string>
     */
    function guest_phone_candidates(string $phoneNormalized): array
    {
        $normalized = guest_normalize_phone($phoneNormalized);
        if ($normalized === null) {
            return [];
        }
        $candidates = [
            $normalized,
            '+' . $normalized,
            '8' . substr($normalized, 1),
        ];
        $uniq = [];
        foreach ($candidates as $candidate) {
            $candidate = trim((string)$candidate);
            if ($candidate === '' || isset($uniq[$candidate])) {
                continue;
            }
            $uniq[$candidate] = true;
        }
        return array_keys($uniq);
    }
}

if (!function_exists('guest_display_name')) {
    /**
     * @param array<string,mixed> $context
     */
    function guest_display_name(array $context): string
    {
        $candidates = [
            $context['customer_name'] ?? null,
            $context['delivery_full_name'] ?? null,
            $context['guest_name'] ?? null,
            $context['name'] ?? null,
        ];
        foreach ($candidates as $candidate) {
            $value = trim((string)$candidate);
            if ($value !== '') {
                return $value;
            }
        }
        return 'Гость';
    }
}

if (!function_exists('crm_guest_profile_collect_stats')) {
    /**
     * @return array{orders_count:int,total_spent:float,average_check:float,first_order_at:?string,last_order_at:?string}|null
     */
    function crm_guest_profile_collect_stats(PDO $pdo, int $restaurantId, string $phoneNormalized): ?array
    {
        if ($restaurantId <= 0 || !function_exists('db_table_exists') || !db_table_exists('orders')) {
            return null;
        }
        $phoneNormalized = guest_normalize_phone($phoneNormalized) ?? '';
        if ($phoneNormalized === '') {
            return null;
        }

        $candidates = guest_phone_candidates($phoneNormalized);
        if ($candidates === []) {
            return null;
        }

        $whereParts = [];
        $params = [':rest_id' => $restaurantId];
        $idx = 0;
        $phoneColumns = ['customer_phone', 'delivery_phone', 'loyalty_phone', 'guest_phone'];
        foreach ($phoneColumns as $column) {
            if (!function_exists('db_column_exists') || !db_column_exists('orders', $column)) {
                continue;
            }
            $colParts = [];
            foreach ($candidates as $candidate) {
                $ph = ':p' . $idx++;
                $params[$ph] = $candidate;
                $colParts[] = "o.{$column} = {$ph}";
            }
            if ($colParts !== []) {
                $whereParts[] = '(' . implode(' OR ', $colParts) . ')';
            }
        }

        $guestIds = [];
        $hasOrderGuestId = function_exists('db_column_exists') && db_column_exists('orders', 'guest_id');
        if ($hasOrderGuestId && function_exists('db_table_exists') && db_table_exists('guests') && function_exists('db_column_exists') && db_column_exists('guests', 'phone')) {
            $guestPhonePh = implode(',', array_fill(0, count($candidates), '?'));
            if ($guestPhonePh !== '') {
                $stmtGuests = $pdo->prepare('SELECT id FROM guests WHERE phone IN (' . $guestPhonePh . ')');
                $stmtGuests->execute($candidates);
                $guestIds = array_values(array_unique(array_map('intval', $stmtGuests->fetchAll(PDO::FETCH_COLUMN) ?: [])));
            }
            if ($guestIds !== []) {
                $gidPh = [];
                foreach ($guestIds as $guestId) {
                    $ph = ':gid' . $idx++;
                    $params[$ph] = $guestId;
                    $gidPh[] = $ph;
                }
                if ($gidPh !== []) {
                    $whereParts[] = '(o.guest_id IN (' . implode(', ', $gidPh) . '))';
                }
            }
        }

        if ($whereParts === []) {
            return null;
        }

        $amountExpr = 'COALESCE(o.total_price, 0)';
        $hasTotalAmount = function_exists('db_column_exists') && db_column_exists('orders', 'total_amount');
        $hasTotalPrice = function_exists('db_column_exists') && db_column_exists('orders', 'total_price');
        if ($hasTotalAmount && $hasTotalPrice) {
            $amountExpr = 'COALESCE(o.total_amount, o.total_price, 0)';
        } elseif ($hasTotalAmount) {
            $amountExpr = 'COALESCE(o.total_amount, 0)';
        }

        $statusFilter = '';
        if (function_exists('db_column_exists') && db_column_exists('orders', 'order_status')) {
            $statusFilter = " AND (o.order_status IS NULL OR (o.order_status <> 'canceled' AND o.order_status <> 'cancelled'))";
        }

        $sql = "
            SELECT
                COUNT(*) AS orders_count,
                COALESCE(SUM({$amountExpr}), 0) AS total_spent,
                MIN(o.created_at) AS first_order_at,
                MAX(o.created_at) AS last_order_at
            FROM orders o
            WHERE o.restaurant_id = :rest_id
              {$statusFilter}
              AND (" . implode(' OR ', $whereParts) . ")
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        $ordersCount = max(0, (int)($row['orders_count'] ?? 0));
        $totalSpent = (float)($row['total_spent'] ?? 0);
        $averageCheck = $ordersCount > 0 ? round($totalSpent / $ordersCount, 2) : 0.0;

        return [
            'orders_count' => $ordersCount,
            'total_spent' => round($totalSpent, 2),
            'average_check' => $averageCheck,
            'first_order_at' => !empty($row['first_order_at']) ? (string)$row['first_order_at'] : null,
            'last_order_at' => !empty($row['last_order_at']) ? (string)$row['last_order_at'] : null,
        ];
    }
}

if (!function_exists('crm_guest_profile_touch')) {
    /**
     * Touch guest profile by order context (phone-first identity).
     *
     * @param array<string,mixed> $orderContext
     */
    function crm_guest_profile_touch(PDO $pdo, int $restaurantId, array $orderContext): bool
    {
        if ($restaurantId <= 0 || !function_exists('db_table_exists') || !db_table_exists('guest_profiles')) {
            return false;
        }

        $rawPhoneCandidates = [
            $orderContext['customer_phone'] ?? null,
            $orderContext['delivery_phone'] ?? null,
            $orderContext['guest_phone'] ?? null,
            $orderContext['loyalty_phone'] ?? null,
            $orderContext['phone'] ?? null,
        ];
        $phoneNormalized = null;
        foreach ($rawPhoneCandidates as $rawPhone) {
            $normalized = guest_normalize_phone((string)$rawPhone);
            if ($normalized !== null) {
                $phoneNormalized = $normalized;
                break;
            }
        }
        if ($phoneNormalized === null) {
            return false;
        }

        $stats = crm_guest_profile_collect_stats($pdo, $restaurantId, $phoneNormalized);
        if ($stats === null) {
            return false;
        }
        $guestName = guest_display_name($orderContext);

        $stmt = $pdo->prepare("
            INSERT INTO guest_profiles (
                restaurant_id,
                phone_normalized,
                guest_name,
                orders_count,
                total_spent,
                average_check,
                first_order_at,
                last_order_at,
                created_at,
                updated_at
            ) VALUES (
                :restaurant_id,
                :phone_normalized,
                :guest_name,
                :orders_count,
                :total_spent,
                :average_check,
                :first_order_at,
                :last_order_at,
                NOW(),
                NOW()
            )
            ON DUPLICATE KEY UPDATE
                guest_name = CASE
                    WHEN VALUES(guest_name) IS NOT NULL AND VALUES(guest_name) <> '' AND VALUES(guest_name) <> 'Гость'
                        THEN VALUES(guest_name)
                    ELSE guest_name
                END,
                orders_count = VALUES(orders_count),
                total_spent = VALUES(total_spent),
                average_check = VALUES(average_check),
                first_order_at = CASE
                    WHEN first_order_at IS NULL THEN VALUES(first_order_at)
                    WHEN VALUES(first_order_at) IS NULL THEN first_order_at
                    ELSE LEAST(first_order_at, VALUES(first_order_at))
                END,
                last_order_at = CASE
                    WHEN last_order_at IS NULL THEN VALUES(last_order_at)
                    WHEN VALUES(last_order_at) IS NULL THEN last_order_at
                    ELSE GREATEST(last_order_at, VALUES(last_order_at))
                END,
                updated_at = NOW()
        ");

        $stmt->execute([
            ':restaurant_id' => $restaurantId,
            ':phone_normalized' => $phoneNormalized,
            ':guest_name' => $guestName,
            ':orders_count' => (int)$stats['orders_count'],
            ':total_spent' => (float)$stats['total_spent'],
            ':average_check' => (float)$stats['average_check'],
            ':first_order_at' => $stats['first_order_at'],
            ':last_order_at' => $stats['last_order_at'],
        ]);

        return true;
    }
}

if (!function_exists('guest_history_has_table')) {
    function guest_history_has_table(PDO $pdo, string $table): bool
    {
        if (function_exists('db_table_exists')) {
            return db_table_exists($table);
        }
        static $cache = [];
        $key = strtolower($table);
        if (array_key_exists($key, $cache)) {
            return (bool)$cache[$key];
        }
        try {
            $stmt = $pdo->prepare("
                SELECT 1
                FROM INFORMATION_SCHEMA.TABLES
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = :table
                LIMIT 1
            ");
            $stmt->execute([':table' => $table]);
            $cache[$key] = (bool)$stmt->fetchColumn();
        } catch (Throwable $e) {
            $cache[$key] = false;
        }
        return (bool)$cache[$key];
    }
}

if (!function_exists('guest_history_has_column')) {
    function guest_history_has_column(PDO $pdo, string $table, string $column): bool
    {
        if (function_exists('db_column_exists')) {
            return db_column_exists($table, $column);
        }
        static $cache = [];
        $key = strtolower($table . '.' . $column);
        if (array_key_exists($key, $cache)) {
            return (bool)$cache[$key];
        }
        try {
            $stmt = $pdo->prepare("
                SELECT 1
                FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = :table
                  AND COLUMN_NAME = :column
                LIMIT 1
            ");
            $stmt->execute([
                ':table' => $table,
                ':column' => $column,
            ]);
            $cache[$key] = (bool)$stmt->fetchColumn();
        } catch (Throwable $e) {
            $cache[$key] = false;
        }
        return (bool)$cache[$key];
    }
}

if (!function_exists('guest_history_resolve_phone')) {
    /**
     * @param array<string,mixed> $payload
     * @return array{
     *   restaurant_id:int,
     *   phone_normalized:string,
     *   phone_candidates:list<string>,
     *   guest_profile_id:int,
     *   guest_name:string,
     *   orders_count:int,
     *   total_spent:float,
     *   average_check:float,
     *   first_order_at:?string,
     *   last_order_at:?string,
     *   guest_ids:list<int>
     * }|null
     */
    function guest_history_resolve_phone(PDO $pdo, int $restaurantId, array $payload): ?array
    {
        if ($restaurantId <= 0) {
            return null;
        }

        $profileRow = null;
        $guestProfileId = (int)($payload['guest_profile_id'] ?? 0);
        if ($guestProfileId > 0 && guest_history_has_table($pdo, 'guest_profiles')) {
            $stmtProfile = $pdo->prepare("
                SELECT
                    id,
                    phone_normalized,
                    guest_name,
                    orders_count,
                    total_spent,
                    average_check,
                    first_order_at,
                    last_order_at
                FROM guest_profiles
                WHERE id = :id
                  AND restaurant_id = :restaurant_id
                LIMIT 1
            ");
            $stmtProfile->execute([
                ':id' => $guestProfileId,
                ':restaurant_id' => $restaurantId,
            ]);
            $profileRow = $stmtProfile->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        $phoneNormalized = null;
        if (is_array($profileRow)) {
            $phoneNormalized = guest_normalize_phone((string)($profileRow['phone_normalized'] ?? ''));
        }

        if ($phoneNormalized === null) {
            $rawCandidates = [
                $payload['phone_normalized'] ?? null,
                $payload['phone'] ?? null,
                $payload['guest_phone'] ?? null,
                $payload['customer_phone'] ?? null,
                $payload['delivery_phone'] ?? null,
                $payload['loyalty_phone'] ?? null,
            ];
            foreach ($rawCandidates as $rawPhone) {
                $normalized = guest_normalize_phone((string)($rawPhone ?? ''));
                if ($normalized !== null) {
                    $phoneNormalized = $normalized;
                    break;
                }
            }
        }

        if ($phoneNormalized === null) {
            return null;
        }

        $phoneCandidates = guest_phone_candidates($phoneNormalized);
        if ($phoneCandidates === []) {
            return null;
        }

        if ($profileRow === null && guest_history_has_table($pdo, 'guest_profiles')) {
            $stmtProfile = $pdo->prepare("
                SELECT
                    id,
                    phone_normalized,
                    guest_name,
                    orders_count,
                    total_spent,
                    average_check,
                    first_order_at,
                    last_order_at
                FROM guest_profiles
                WHERE restaurant_id = :restaurant_id
                  AND phone_normalized = :phone_normalized
                LIMIT 1
            ");
            $stmtProfile->execute([
                ':restaurant_id' => $restaurantId,
                ':phone_normalized' => $phoneNormalized,
            ]);
            $profileRow = $stmtProfile->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        $guestIds = [];
        $guestNameFromGuests = '';
        if (guest_history_has_table($pdo, 'guests') && guest_history_has_column($pdo, 'guests', 'phone')) {
            $ph = implode(',', array_fill(0, count($phoneCandidates), '?'));
            $sqlGuest = "SELECT id";
            if (guest_history_has_column($pdo, 'guests', 'name')) {
                $sqlGuest .= ", name";
            }
            $sqlGuest .= " FROM guests WHERE phone IN ($ph)";
            $stmtGuests = $pdo->prepare($sqlGuest);
            $stmtGuests->execute($phoneCandidates);
            foreach (($stmtGuests->fetchAll(PDO::FETCH_ASSOC) ?: []) as $gRow) {
                $guestId = (int)($gRow['id'] ?? 0);
                if ($guestId > 0) {
                    $guestIds[] = $guestId;
                }
                if ($guestNameFromGuests === '' && array_key_exists('name', $gRow)) {
                    $candidateName = trim((string)($gRow['name'] ?? ''));
                    if ($candidateName !== '') {
                        $guestNameFromGuests = $candidateName;
                    }
                }
            }
            $guestIds = array_values(array_unique($guestIds));
        }

        $guestName = trim((string)($profileRow['guest_name'] ?? ''));
        if ($guestName === '') {
            $guestName = $guestNameFromGuests;
        }
        if ($guestName === '') {
            $guestName = 'Гость';
        }

        return [
            'restaurant_id' => $restaurantId,
            'phone_normalized' => $phoneNormalized,
            'phone_candidates' => $phoneCandidates,
            'guest_profile_id' => (int)($profileRow['id'] ?? 0),
            'guest_name' => $guestName,
            'orders_count' => (int)($profileRow['orders_count'] ?? 0),
            'total_spent' => (float)($profileRow['total_spent'] ?? 0),
            'average_check' => (float)($profileRow['average_check'] ?? 0),
            'first_order_at' => !empty($profileRow['first_order_at']) ? (string)$profileRow['first_order_at'] : null,
            'last_order_at' => !empty($profileRow['last_order_at']) ? (string)$profileRow['last_order_at'] : null,
            'guest_ids' => $guestIds,
        ];
    }
}

if (!function_exists('guest_history_fetch_orders')) {
    /**
     * @param array{
     *   restaurant_id:int,
     *   phone_normalized:string,
     *   phone_candidates?:list<string>,
     *   guest_ids?:list<int>
     * } $resolved
     * @return list<array{
     *   order_id:int,
     *   created_at:?string,
     *   order_type:string,
     *   order_type_label:string,
     *   status:string,
     *   total_amount:float,
     *   fulfillment_type:string,
     *   restaurant_id:int,
     *   items_summary:string,
     *   items:list<array{name:string,qty:int}>
     * }>
     */
    function guest_history_fetch_orders(PDO $pdo, int $restaurantId, array $resolved, int $limit = 20): array
    {
        if ($restaurantId <= 0 || !guest_history_has_table($pdo, 'orders')) {
            return [];
        }

        $limit = max(1, min(100, $limit));
        $phoneCandidates = array_values(array_filter(array_map(static function ($value): string {
            return trim((string)$value);
        }, $resolved['phone_candidates'] ?? [])));
        $guestIds = array_values(array_filter(array_map('intval', $resolved['guest_ids'] ?? []), static function (int $id): bool {
            return $id > 0;
        }));

        $whereParts = [];
        $params = [':rest_id' => $restaurantId];
        $paramCounter = 0;

        $phoneColumns = ['customer_phone', 'delivery_phone', 'guest_phone', 'loyalty_phone'];
        if ($phoneCandidates !== []) {
            foreach ($phoneColumns as $column) {
                if (!guest_history_has_column($pdo, 'orders', $column)) {
                    continue;
                }
                $colParts = [];
                foreach ($phoneCandidates as $candidate) {
                    $ph = ':p' . $paramCounter++;
                    $params[$ph] = $candidate;
                    $colParts[] = "o.{$column} = {$ph}";
                }
                if ($colParts !== []) {
                    $whereParts[] = '(' . implode(' OR ', $colParts) . ')';
                }
            }
        }

        if ($guestIds !== [] && guest_history_has_column($pdo, 'orders', 'guest_id')) {
            $guestPh = [];
            foreach ($guestIds as $guestId) {
                $ph = ':g' . $paramCounter++;
                $params[$ph] = $guestId;
                $guestPh[] = $ph;
            }
            if ($guestPh !== []) {
                $whereParts[] = '(o.guest_id IN (' . implode(', ', $guestPh) . '))';
            }
        }

        if ($whereParts === []) {
            return [];
        }

        $totalExprParts = [];
        if (guest_history_has_column($pdo, 'orders', 'total_amount')) {
            $totalExprParts[] = 'o.total_amount';
        }
        if (guest_history_has_column($pdo, 'orders', 'total_price')) {
            $totalExprParts[] = 'o.total_price';
        }
        if (guest_history_has_column($pdo, 'orders', 'total')) {
            $totalExprParts[] = 'o.total';
        }
        $totalExpr = $totalExprParts === [] ? '0' : ('COALESCE(' . implode(', ', $totalExprParts) . ', 0)');

        $orderTypeExpr = guest_history_has_column($pdo, 'orders', 'order_type')
            ? 'o.order_type AS order_type'
            : "NULL AS order_type";
        $statusExpr = guest_history_has_column($pdo, 'orders', 'order_status')
            ? 'o.order_status AS order_status'
            : "NULL AS order_status";
        $createdAtExpr = guest_history_has_column($pdo, 'orders', 'created_at')
            ? 'o.created_at AS created_at'
            : "NULL AS created_at";
        $tableIdExpr = guest_history_has_column($pdo, 'orders', 'table_id')
            ? 'o.table_id AS table_id'
            : "NULL AS table_id";
        $orderBy = guest_history_has_column($pdo, 'orders', 'created_at')
            ? 'o.created_at DESC, o.id DESC'
            : 'o.id DESC';

        $sql = "
            SELECT
                o.id AS order_id,
                {$createdAtExpr},
                {$orderTypeExpr},
                {$statusExpr},
                {$tableIdExpr},
                {$totalExpr} AS total_amount,
                o.restaurant_id AS restaurant_id
            FROM orders o
            WHERE o.restaurant_id = :rest_id
              AND (" . implode(' OR ', $whereParts) . ")
            ORDER BY {$orderBy}
            LIMIT {$limit}
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($orders === []) {
            return [];
        }

        $orderIds = array_values(array_unique(array_map(static function (array $row): int {
            return (int)($row['order_id'] ?? 0);
        }, $orders)));
        $orderIds = array_values(array_filter($orderIds, static function (int $id): bool {
            return $id > 0;
        }));

        $itemsByOrder = [];
        if ($orderIds !== [] && guest_history_has_table($pdo, 'order_items')) {
            $hasQty = guest_history_has_column($pdo, 'order_items', 'qty');
            $hasQuantity = guest_history_has_column($pdo, 'order_items', 'quantity');
            if ($hasQuantity && $hasQty) {
                $qtyExpr = 'COALESCE(NULLIF(oi.quantity, 0), oi.qty, 1)';
            } elseif ($hasQuantity) {
                $qtyExpr = 'COALESCE(oi.quantity, 1)';
            } elseif ($hasQty) {
                $qtyExpr = 'COALESCE(oi.qty, 1)';
            } else {
                $qtyExpr = '1';
            }

            $canJoinMenu = guest_history_has_table($pdo, 'menu_items')
                && guest_history_has_column($pdo, 'order_items', 'menu_item_id')
                && guest_history_has_column($pdo, 'menu_items', 'id')
                && guest_history_has_column($pdo, 'menu_items', 'name');
            $nameExpr = guest_history_has_column($pdo, 'order_items', 'item_name')
                ? "COALESCE(NULLIF(TRIM(oi.item_name), ''), " . ($canJoinMenu ? "mi.name, " : '') . "'Позиция')"
                : ($canJoinMenu ? "COALESCE(mi.name, 'Позиция')" : "'Позиция'");
            $joinMenu = $canJoinMenu ? 'LEFT JOIN menu_items mi ON mi.id = oi.menu_item_id' : '';

            $ph = implode(',', array_fill(0, count($orderIds), '?'));
            $sqlItems = "
                SELECT
                    oi.order_id AS order_id,
                    {$nameExpr} AS item_name,
                    {$qtyExpr} AS item_qty
                FROM order_items oi
                {$joinMenu}
                WHERE oi.order_id IN ({$ph})
                ORDER BY oi.order_id ASC
            ";
            $stmtItems = $pdo->prepare($sqlItems);
            $stmtItems->execute($orderIds);
            foreach (($stmtItems->fetchAll(PDO::FETCH_ASSOC) ?: []) as $iRow) {
                $oid = (int)($iRow['order_id'] ?? 0);
                if ($oid <= 0) {
                    continue;
                }
                $name = trim((string)($iRow['item_name'] ?? ''));
                if ($name === '') {
                    $name = 'Позиция';
                }
                $qty = max(1, (int)($iRow['item_qty'] ?? 1));
                $itemsByOrder[$oid][] = [
                    'name' => $name,
                    'qty' => $qty,
                ];
            }
        }

        $result = [];
        foreach ($orders as $row) {
            $orderId = (int)($row['order_id'] ?? 0);
            if ($orderId <= 0) {
                continue;
            }
            $tableId = isset($row['table_id']) ? (int)$row['table_id'] : null;
            $orderTypeRaw = (string)($row['order_type'] ?? '');
            $orderType = function_exists('order_type_normalize')
                ? order_type_normalize($orderTypeRaw, $tableId)
                : (($tableId ?? 0) > 0 ? 'hall' : 'hall');
            $orderTypeLabel = function_exists('order_type_label')
                ? order_type_label($orderTypeRaw, $tableId)
                : 'Зал';
            $fulfillmentType = function_exists('order_source_label')
                ? order_source_label($orderTypeRaw, $tableId, null)
                : $orderTypeLabel;
            $items = $itemsByOrder[$orderId] ?? [];
            $summaryPieces = [];
            foreach ($items as $item) {
                $name = trim((string)($item['name'] ?? ''));
                if ($name === '') {
                    continue;
                }
                $qty = max(1, (int)($item['qty'] ?? 1));
                $summaryPieces[] = $name . ($qty > 1 ? (' ×' . $qty) : '');
            }
            $itemsSummary = '';
            if ($summaryPieces !== []) {
                $head = array_slice($summaryPieces, 0, 4);
                $itemsSummary = implode(', ', $head);
                $restCount = count($summaryPieces) - count($head);
                if ($restCount > 0) {
                    $itemsSummary .= ' +' . $restCount;
                }
            }

            $result[] = [
                'order_id' => $orderId,
                'created_at' => !empty($row['created_at']) ? (string)$row['created_at'] : null,
                'order_type' => $orderType,
                'order_type_label' => $orderTypeLabel,
                'status' => (string)($row['order_status'] ?? ''),
                'total_amount' => (float)($row['total_amount'] ?? 0),
                'items_summary' => $itemsSummary,
                'fulfillment_type' => $fulfillmentType,
                'restaurant_id' => (int)($row['restaurant_id'] ?? $restaurantId),
                'items' => $items,
            ];
        }

        return $result;
    }
}

if (!function_exists('guest_order_extract_items')) {
    /**
     * Extract order items in a reorder-friendly shape.
     *
     * @return list<array{
     *   order_item_id:int,
     *   menu_item_id:int,
     *   item_name:string,
     *   quantity:int,
     *   unit_price:float,
     *   options:mixed,
     *   available:bool,
     *   unavailable:bool,
     *   unavailable_reason:string
     * }>
     */
    function guest_order_extract_items(PDO $pdo, int $restaurantId, int $orderId): array
    {
        if ($restaurantId <= 0 || $orderId <= 0) {
            return [];
        }
        if (!guest_history_has_table($pdo, 'order_items')) {
            return [];
        }

        $hasMenuItemId = guest_history_has_column($pdo, 'order_items', 'menu_item_id');
        $hasItemName = guest_history_has_column($pdo, 'order_items', 'item_name');
        $hasPrice = guest_history_has_column($pdo, 'order_items', 'price');
        $hasQty = guest_history_has_column($pdo, 'order_items', 'qty');
        $hasQuantity = guest_history_has_column($pdo, 'order_items', 'quantity');

        if ($hasQuantity && $hasQty) {
            $qtyExpr = 'COALESCE(NULLIF(oi.quantity, 0), oi.qty, 1)';
        } elseif ($hasQuantity) {
            $qtyExpr = 'COALESCE(oi.quantity, 1)';
        } elseif ($hasQty) {
            $qtyExpr = 'COALESCE(oi.qty, 1)';
        } else {
            $qtyExpr = '1';
        }

        $canJoinMenu = $hasMenuItemId
            && guest_history_has_table($pdo, 'menu_items')
            && guest_history_has_column($pdo, 'menu_items', 'id')
            && guest_history_has_column($pdo, 'menu_items', 'name');
        $joinMenu = $canJoinMenu
            ? 'LEFT JOIN menu_items mi ON mi.id = oi.menu_item_id AND mi.restaurant_id = :rest_id'
            : '';

        if ($hasItemName && $canJoinMenu) {
            $nameExpr = "COALESCE(NULLIF(TRIM(oi.item_name), ''), mi.name, 'Позиция')";
        } elseif ($hasItemName) {
            $nameExpr = "COALESCE(NULLIF(TRIM(oi.item_name), ''), 'Позиция')";
        } elseif ($canJoinMenu) {
            $nameExpr = "COALESCE(mi.name, 'Позиция')";
        } else {
            $nameExpr = "'Позиция'";
        }

        $priceExpr = $hasPrice ? 'COALESCE(oi.price, 0)' : '0';
        $menuItemExpr = $hasMenuItemId ? 'COALESCE(oi.menu_item_id, 0)' : '0';

        $optionsCol = null;
        foreach (['modifiers', 'options', 'item_options', 'meta'] as $candidate) {
            if (guest_history_has_column($pdo, 'order_items', $candidate)) {
                $optionsCol = $candidate;
                break;
            }
        }
        $optionsExpr = $optionsCol !== null ? "oi.{$optionsCol}" : "NULL";

        $availabilityExpr = '1';
        $unavailableReasonExpr = "''";
        if ($canJoinMenu) {
            $availableCheckParts = ['mi.id IS NULL'];
            $reasons = [];
            if (guest_history_has_column($pdo, 'menu_items', 'available')) {
                $availableCheckParts[] = "COALESCE(mi.available, 1) = 0";
                $reasons[] = "WHEN COALESCE(mi.available, 1) = 0 THEN 'not_available'";
            }
            if (guest_history_has_column($pdo, 'menu_items', 'deleted_at')) {
                $availableCheckParts[] = "mi.deleted_at IS NOT NULL";
                $reasons[] = "WHEN mi.deleted_at IS NOT NULL THEN 'deleted'";
            }
            if (guest_history_has_column($pdo, 'menu_items', 'status')) {
                $availableCheckParts[] = "LOWER(TRIM(COALESCE(mi.status, 'active'))) IN ('disabled','inactive','hidden','archived')";
                $reasons[] = "WHEN LOWER(TRIM(COALESCE(mi.status, 'active'))) IN ('disabled','inactive','hidden','archived') THEN 'hidden'";
            }
            $availabilityExpr = '(CASE WHEN ' . implode(' OR ', $availableCheckParts) . ' THEN 0 ELSE 1 END)';
            $unavailableReasonExpr = "(CASE WHEN mi.id IS NULL THEN 'missing' " . implode(' ', $reasons) . " ELSE '' END)";
        }

        $sql = "
            SELECT
                oi.id AS order_item_id,
                {$menuItemExpr} AS menu_item_id,
                {$nameExpr} AS item_name,
                {$qtyExpr} AS item_qty,
                {$priceExpr} AS unit_price,
                {$optionsExpr} AS item_options_raw,
                {$availabilityExpr} AS is_available,
                {$unavailableReasonExpr} AS unavailable_reason
            FROM order_items oi
            {$joinMenu}
            WHERE oi.order_id = :order_id
            ORDER BY oi.id ASC
        ";
        $stmt = $pdo->prepare($sql);
        $params = [
            ':order_id' => $orderId,
        ];
        if ($canJoinMenu) {
            $params[':rest_id'] = $restaurantId;
        }
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $items = [];
        foreach ($rows as $row) {
            $rawOptions = $row['item_options_raw'] ?? null;
            $options = null;
            if ($rawOptions !== null && $rawOptions !== '') {
                if (is_string($rawOptions)) {
                    $decoded = json_decode($rawOptions, true);
                    $options = (json_last_error() === JSON_ERROR_NONE) ? $decoded : $rawOptions;
                } else {
                    $options = $rawOptions;
                }
            }

            $available = (int)($row['is_available'] ?? 1) === 1;
            $items[] = [
                'order_item_id' => (int)($row['order_item_id'] ?? 0),
                'menu_item_id' => max(0, (int)($row['menu_item_id'] ?? 0)),
                'item_name' => trim((string)($row['item_name'] ?? '')) ?: 'Позиция',
                'quantity' => max(1, (int)($row['item_qty'] ?? 1)),
                'unit_price' => (float)($row['unit_price'] ?? 0),
                'options' => $options,
                'available' => $available,
                'unavailable' => !$available,
                'unavailable_reason' => trim((string)($row['unavailable_reason'] ?? '')),
            ];
        }

        return $items;
    }
}

if (!function_exists('guest_order_reorder_payload')) {
    /**
     * Build tenant-safe reorder preview payload for one historical order.
     *
     * @param array{
     *   phone_normalized:string,
     *   phone_candidates?:list<string>,
     *   guest_ids?:list<int>,
     *   guest_name?:string
     * } $resolvedGuest
     * @return array{
     *   order_id:int,
     *   restaurant_id:int,
     *   created_at:?string,
     *   order_status:string,
     *   order_type:string,
     *   order_type_label:string,
     *   fulfillment_type:string,
     *   total_amount:float,
     *   guest_phone_normalized:string,
     *   guest_name:string,
     *   items_total:int,
     *   items_available:int,
     *   items_unavailable:int,
     *   quantity_total:int,
     *   quantity_available:int,
     *   can_reorder:bool,
     *   items:list<array{
     *     order_item_id:int,
     *     menu_item_id:int,
     *     item_name:string,
     *     quantity:int,
     *     unit_price:float,
     *     options:mixed,
     *     available:bool,
     *     unavailable:bool,
     *     unavailable_reason:string
     *   }>
     * }|null
     */
    function guest_order_reorder_payload(PDO $pdo, int $restaurantId, array $resolvedGuest, int $orderId): ?array
    {
        if ($restaurantId <= 0 || $orderId <= 0) {
            return null;
        }
        if (!guest_history_has_table($pdo, 'orders')) {
            return null;
        }

        $phoneCandidates = array_values(array_filter(array_map(static function ($value): string {
            return trim((string)$value);
        }, $resolvedGuest['phone_candidates'] ?? [])));
        $guestIds = array_values(array_filter(array_map('intval', $resolvedGuest['guest_ids'] ?? []), static function (int $id): bool {
            return $id > 0;
        }));

        $whereParts = [];
        $params = [
            ':rest_id' => $restaurantId,
            ':order_id' => $orderId,
        ];
        $idx = 0;

        if ($phoneCandidates !== []) {
            foreach (['customer_phone', 'delivery_phone', 'guest_phone', 'loyalty_phone'] as $column) {
                if (!guest_history_has_column($pdo, 'orders', $column)) {
                    continue;
                }
                $colParts = [];
                foreach ($phoneCandidates as $candidate) {
                    $ph = ':p' . $idx++;
                    $params[$ph] = $candidate;
                    $colParts[] = "o.{$column} = {$ph}";
                }
                if ($colParts !== []) {
                    $whereParts[] = '(' . implode(' OR ', $colParts) . ')';
                }
            }
        }

        if ($guestIds !== [] && guest_history_has_column($pdo, 'orders', 'guest_id')) {
            $gidPh = [];
            foreach ($guestIds as $guestId) {
                $ph = ':g' . $idx++;
                $params[$ph] = $guestId;
                $gidPh[] = $ph;
            }
            if ($gidPh !== []) {
                $whereParts[] = '(o.guest_id IN (' . implode(', ', $gidPh) . '))';
            }
        }

        if ($whereParts === []) {
            return null;
        }

        $totalExprParts = [];
        if (guest_history_has_column($pdo, 'orders', 'total_amount')) {
            $totalExprParts[] = 'o.total_amount';
        }
        if (guest_history_has_column($pdo, 'orders', 'total_price')) {
            $totalExprParts[] = 'o.total_price';
        }
        if (guest_history_has_column($pdo, 'orders', 'total')) {
            $totalExprParts[] = 'o.total';
        }
        $totalExpr = $totalExprParts === [] ? '0' : ('COALESCE(' . implode(', ', $totalExprParts) . ', 0)');

        $createdAtExpr = guest_history_has_column($pdo, 'orders', 'created_at') ? 'o.created_at' : 'NULL';
        $statusExpr = guest_history_has_column($pdo, 'orders', 'order_status') ? 'o.order_status' : "''";
        $orderTypeExpr = guest_history_has_column($pdo, 'orders', 'order_type') ? 'o.order_type' : "NULL";
        $tableIdExpr = guest_history_has_column($pdo, 'orders', 'table_id') ? 'o.table_id' : 'NULL';

        $sql = "
            SELECT
                o.id AS order_id,
                {$createdAtExpr} AS created_at,
                {$statusExpr} AS order_status,
                {$orderTypeExpr} AS order_type,
                {$tableIdExpr} AS table_id,
                {$totalExpr} AS total_amount
            FROM orders o
            WHERE o.restaurant_id = :rest_id
              AND o.id = :order_id
              AND (" . implode(' OR ', $whereParts) . ")
            LIMIT 1
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            return null;
        }

        $items = guest_order_extract_items($pdo, $restaurantId, $orderId);
        $itemsTotal = count($items);
        $itemsAvailable = 0;
        $quantityTotal = 0;
        $quantityAvailable = 0;
        foreach ($items as $item) {
            $qty = max(1, (int)($item['quantity'] ?? 1));
            $quantityTotal += $qty;
            if (!empty($item['available'])) {
                $itemsAvailable++;
                $quantityAvailable += $qty;
            }
        }
        $itemsUnavailable = max(0, $itemsTotal - $itemsAvailable);

        $tableId = isset($order['table_id']) ? (int)$order['table_id'] : null;
        $orderTypeRaw = (string)($order['order_type'] ?? '');
        $orderType = function_exists('order_type_normalize')
            ? order_type_normalize($orderTypeRaw, $tableId)
            : (($tableId ?? 0) > 0 ? 'hall' : 'hall');
        $orderTypeLabel = function_exists('order_type_label')
            ? order_type_label($orderTypeRaw, $tableId)
            : 'Зал';
        $fulfillmentType = function_exists('order_source_label')
            ? order_source_label($orderTypeRaw, $tableId, null)
            : $orderTypeLabel;

        return [
            'order_id' => (int)($order['order_id'] ?? $orderId),
            'restaurant_id' => $restaurantId,
            'created_at' => !empty($order['created_at']) ? (string)$order['created_at'] : null,
            'order_status' => trim((string)($order['order_status'] ?? '')),
            'order_type' => $orderType,
            'order_type_label' => $orderTypeLabel,
            'fulfillment_type' => $fulfillmentType,
            'total_amount' => (float)($order['total_amount'] ?? 0),
            'guest_phone_normalized' => (string)($resolvedGuest['phone_normalized'] ?? ''),
            'guest_name' => trim((string)($resolvedGuest['guest_name'] ?? '')) ?: 'Гость',
            'items_total' => $itemsTotal,
            'items_available' => $itemsAvailable,
            'items_unavailable' => $itemsUnavailable,
            'quantity_total' => $quantityTotal,
            'quantity_available' => $quantityAvailable,
            'can_reorder' => ($itemsAvailable > 0),
            'items' => $items,
        ];
    }
}

if (!function_exists('crm_guest_rfm_metrics')) {
    /**
     * Build compact RFM metrics for one resolved guest.
     *
     * @param array{
     *   phone_normalized?:string,
     *   guest_name?:string,
     *   orders_count?:int,
     *   total_spent?:float,
     *   average_check?:float,
     *   first_order_at?:?string,
     *   last_order_at?:?string
     * } $resolvedGuest
     * @return array{
     *   restaurant_id:int,
     *   phone_normalized:string,
     *   guest_name:string,
     *   orders_count:int,
     *   total_spent:float,
     *   average_check:float,
     *   first_order_at:?string,
     *   last_order_at:?string,
     *   recency_days:?int,
     *   frequency_count:int,
     *   monetary_total:float,
     *   monetary_avg:float
     * }|null
     */
    function crm_guest_rfm_metrics(PDO $pdo, int $restaurantId, array $resolvedGuest): ?array
    {
        if ($restaurantId <= 0) {
            return null;
        }

        $phoneNormalized = guest_normalize_phone((string)($resolvedGuest['phone_normalized'] ?? ''));
        if ($phoneNormalized === null) {
            return null;
        }

        $ordersCount = max(0, (int)($resolvedGuest['orders_count'] ?? 0));
        $totalSpent = max(0.0, (float)($resolvedGuest['total_spent'] ?? 0.0));
        $averageCheck = max(0.0, (float)($resolvedGuest['average_check'] ?? 0.0));
        $firstOrderAt = !empty($resolvedGuest['first_order_at']) ? (string)$resolvedGuest['first_order_at'] : null;
        $lastOrderAt = !empty($resolvedGuest['last_order_at']) ? (string)$resolvedGuest['last_order_at'] : null;

        // If guest_profile snapshot is missing/partial, refresh compact stats for this phone only.
        if (($ordersCount <= 0 || $lastOrderAt === null) && function_exists('crm_guest_profile_collect_stats')) {
            $stats = crm_guest_profile_collect_stats($pdo, $restaurantId, $phoneNormalized);
            if (is_array($stats)) {
                $ordersCount = max(0, (int)($stats['orders_count'] ?? $ordersCount));
                $totalSpent = max(0.0, (float)($stats['total_spent'] ?? $totalSpent));
                $averageCheck = max(0.0, (float)($stats['average_check'] ?? $averageCheck));
                $firstOrderAt = !empty($stats['first_order_at']) ? (string)$stats['first_order_at'] : $firstOrderAt;
                $lastOrderAt = !empty($stats['last_order_at']) ? (string)$stats['last_order_at'] : $lastOrderAt;
            }
        }

        $recencyDays = null;
        if ($lastOrderAt !== null) {
            $lastTs = strtotime($lastOrderAt);
            if ($lastTs !== false && $lastTs > 0) {
                $recencyDays = max(0, (int)floor((time() - $lastTs) / 86400));
            }
        }

        if ($averageCheck <= 0.0 && $ordersCount > 0) {
            $averageCheck = round($totalSpent / $ordersCount, 2);
        }

        return [
            'restaurant_id' => $restaurantId,
            'phone_normalized' => $phoneNormalized,
            'guest_name' => trim((string)($resolvedGuest['guest_name'] ?? '')) ?: 'Гость',
            'orders_count' => $ordersCount,
            'total_spent' => round($totalSpent, 2),
            'average_check' => round($averageCheck, 2),
            'first_order_at' => $firstOrderAt,
            'last_order_at' => $lastOrderAt,
            'recency_days' => $recencyDays,
            'frequency_count' => $ordersCount,
            'monetary_total' => round($totalSpent, 2),
            'monetary_avg' => round($averageCheck, 2),
        ];
    }
}

if (!function_exists('crm_guest_rfm_score')) {
    /**
     * @param array{
     *   recency_days:?int,
     *   frequency_count:int,
     *   monetary_total:float,
     *   monetary_avg:float
     * } $metrics
     * @return array{
     *   r:int,
     *   f:int,
     *   m:int,
     *   combined:int,
     *   code:string
     * }
     */
    function crm_guest_rfm_score(array $metrics): array
    {
        $recencyDays = isset($metrics['recency_days']) && $metrics['recency_days'] !== null
            ? max(0, (int)$metrics['recency_days'])
            : null;
        $frequency = max(0, (int)($metrics['frequency_count'] ?? 0));
        $monetaryTotal = max(0.0, (float)($metrics['monetary_total'] ?? 0.0));
        $monetaryAvg = max(0.0, (float)($metrics['monetary_avg'] ?? 0.0));

        $r = 1;
        if ($recencyDays === null) {
            $r = 1;
        } elseif ($recencyDays <= 7) {
            $r = 5;
        } elseif ($recencyDays <= 14) {
            $r = 4;
        } elseif ($recencyDays <= 30) {
            $r = 3;
        } elseif ($recencyDays <= 60) {
            $r = 2;
        }

        $f = 1;
        if ($frequency >= 10) {
            $f = 5;
        } elseif ($frequency >= 6) {
            $f = 4;
        } elseif ($frequency >= 3) {
            $f = 3;
        } elseif ($frequency >= 2) {
            $f = 2;
        } elseif ($frequency <= 0) {
            $f = 1;
        }

        $mByTotal = 1;
        if ($monetaryTotal >= 20000) {
            $mByTotal = 5;
        } elseif ($monetaryTotal >= 10000) {
            $mByTotal = 4;
        } elseif ($monetaryTotal >= 5000) {
            $mByTotal = 3;
        } elseif ($monetaryTotal >= 2000) {
            $mByTotal = 2;
        }

        $mByAvg = 1;
        if ($monetaryAvg >= 2500) {
            $mByAvg = 5;
        } elseif ($monetaryAvg >= 1500) {
            $mByAvg = 4;
        } elseif ($monetaryAvg >= 900) {
            $mByAvg = 3;
        } elseif ($monetaryAvg >= 500) {
            $mByAvg = 2;
        }

        $m = max($mByTotal, $mByAvg);
        $combined = ($r * 100) + ($f * 10) + $m;

        return [
            'r' => $r,
            'f' => $f,
            'm' => $m,
            'combined' => $combined,
            'code' => (string)$r . (string)$f . (string)$m,
        ];
    }
}

if (!function_exists('crm_guest_rfm_segment')) {
    /**
     * @param array{
     *   recency_days:?int,
     *   frequency_count:int
     * } $metrics
     * @param array{
     *   r:int,
     *   f:int,
     *   m:int,
     *   combined:int,
     *   code:string
     * } $score
     * @return array{
     *   key:string,
     *   label:string,
     *   description:string
     * }
     */
    function crm_guest_rfm_segment(array $metrics, array $score): array
    {
        $r = max(1, min(5, (int)($score['r'] ?? 1)));
        $f = max(1, min(5, (int)($score['f'] ?? 1)));
        $m = max(1, min(5, (int)($score['m'] ?? 1)));
        $frequency = max(0, (int)($metrics['frequency_count'] ?? 0));

        if ($r >= 4 && $f >= 4 && $m >= 4) {
            return ['key' => 'vip', 'label' => 'VIP', 'description' => 'Часто покупает, высокий чек, недавние заказы.'];
        }
        if ($r >= 3 && $f >= 4) {
            return ['key' => 'loyal', 'label' => 'Loyal', 'description' => 'Стабильно возвращается и активно заказывает.'];
        }
        if ($frequency <= 1 && $r >= 4) {
            return ['key' => 'new', 'label' => 'New', 'description' => 'Новый гость, нужен мягкий сценарий повторного визита.'];
        }
        if ($r <= 2 && $f >= 3) {
            return ['key' => 'at_risk', 'label' => 'At risk', 'description' => 'Ранее был активен, но давно не заказывал.'];
        }
        if ($r <= 2) {
            return ['key' => 'sleeping', 'label' => 'Sleeping', 'description' => 'Гость давно не проявлял активность.'];
        }
        return ['key' => 'regular', 'label' => 'Regular', 'description' => 'Регулярный гость со средним паттерном заказов.'];
    }
}

if (!function_exists('promo_normalize_code')) {
    function promo_normalize_code(?string $raw): string
    {
        $code = strtoupper(trim((string)($raw ?? '')));
        if ($code === '') {
            return '';
        }
        return preg_replace('/[^A-Z0-9_\-]/', '', $code) ?: '';
    }
}

if (!function_exists('promo_calculate_discount')) {
    /**
     * @param array{discount_type?:string,discount_value?:float|int|string} $promo
     * @return array{subtotal:float,discount_amount:float,final_total:float,discount_type:string,discount_value:float}
     */
    function promo_calculate_discount(float $subtotal, array $promo): array
    {
        $subtotal = max(0.0, round($subtotal, 2));
        $discountType = strtolower(trim((string)($promo['discount_type'] ?? 'percent')));
        $discountValue = (float)($promo['discount_value'] ?? 0);
        if ($discountValue < 0) {
            $discountValue = 0.0;
        }

        $discountAmount = 0.0;
        if ($discountType === 'fixed') {
            $discountAmount = $discountValue;
        } else {
            $discountType = 'percent';
            $discountValue = min(100.0, $discountValue);
            $discountAmount = ($subtotal * $discountValue) / 100.0;
        }

        $discountAmount = min($subtotal, max(0.0, $discountAmount));
        $discountAmount = round($discountAmount, 2);
        $finalTotal = round(max(0.0, $subtotal - $discountAmount), 2);

        return [
            'subtotal' => $subtotal,
            'discount_amount' => $discountAmount,
            'final_total' => $finalTotal,
            'discount_type' => $discountType,
            'discount_value' => round($discountValue, 2),
        ];
    }
}

if (!function_exists('promo_guest_can_use')) {
    /**
     * @param array<string,mixed> $promotion
     * @param array<string,mixed> $resolvedGuest
     * @return array{ok:bool,reason:string,usage_count:int,usage_limit:int}
     */
    function promo_guest_can_use(PDO $pdo, int $restaurantId, array $promotion, array $resolvedGuest = []): array
    {
        $limit = max(0, (int)($promotion['usage_limit_per_guest'] ?? 0));
        if ($limit <= 0) {
            return ['ok' => true, 'reason' => 'ok', 'usage_count' => 0, 'usage_limit' => 0];
        }
        if (!guest_history_has_table($pdo, 'restaurant_promo_usages')) {
            return ['ok' => true, 'reason' => 'usage_table_missing', 'usage_count' => 0, 'usage_limit' => $limit];
        }

        $promotionId = (int)($promotion['id'] ?? 0);
        if ($promotionId <= 0 || $restaurantId <= 0) {
            return ['ok' => false, 'reason' => 'invalid_promotion', 'usage_count' => 0, 'usage_limit' => $limit];
        }

        $where = ['promotion_id = :promotion_id', 'restaurant_id = :restaurant_id'];
        $params = [
            ':promotion_id' => $promotionId,
            ':restaurant_id' => $restaurantId,
        ];
        $identityParts = [];
        $counter = 0;

        if (guest_history_has_column($pdo, 'restaurant_promo_usages', 'guest_profile_id')) {
            $guestProfileId = max(0, (int)($resolvedGuest['guest_profile_id'] ?? 0));
            if ($guestProfileId > 0) {
                $identityParts[] = 'guest_profile_id = :guest_profile_id';
                $params[':guest_profile_id'] = $guestProfileId;
            }
        }

        if (guest_history_has_column($pdo, 'restaurant_promo_usages', 'phone_normalized')) {
            $phoneCandidates = [];
            if (!empty($resolvedGuest['phone_normalized'])) {
                $normalized = guest_normalize_phone((string)$resolvedGuest['phone_normalized']);
                if ($normalized !== null) {
                    $phoneCandidates = guest_phone_candidates($normalized);
                }
            }
            if ($phoneCandidates === [] && isset($resolvedGuest['phone_candidates']) && is_array($resolvedGuest['phone_candidates'])) {
                foreach ($resolvedGuest['phone_candidates'] as $candidate) {
                    $normalized = guest_normalize_phone((string)$candidate);
                    if ($normalized !== null) {
                        $phoneCandidates = array_values(array_unique(array_merge($phoneCandidates, guest_phone_candidates($normalized))));
                    }
                }
            }
            foreach ($phoneCandidates as $candidate) {
                $ph = ':phone_' . $counter++;
                $identityParts[] = 'phone_normalized = ' . $ph;
                $params[$ph] = $candidate;
            }
        }

        if ($identityParts === []) {
            return ['ok' => true, 'reason' => 'guest_identity_missing', 'usage_count' => 0, 'usage_limit' => $limit];
        }

        $where[] = '(' . implode(' OR ', $identityParts) . ')';
        $sql = 'SELECT COUNT(*) FROM restaurant_promo_usages WHERE ' . implode(' AND ', $where);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $usageCount = max(0, (int)$stmt->fetchColumn());

        return [
            'ok' => ($usageCount < $limit),
            'reason' => ($usageCount < $limit) ? 'ok' : 'guest_limit_reached',
            'usage_count' => $usageCount,
            'usage_limit' => $limit,
        ];
    }
}

if (!function_exists('promo_validate_code')) {
    /**
     * @param array<string,mixed> $context
     * @return array{
     *   ok:bool,
     *   reason:string,
     *   message:string,
     *   promotion:?array<string,mixed>,
     *   preview:?array<string,mixed>
     * }
     */
    function promo_validate_code(PDO $pdo, int $restaurantId, string $code, array $context = []): array
    {
        $codeNorm = promo_normalize_code($code);
        if ($restaurantId <= 0 || $codeNorm === '') {
            return ['ok' => false, 'reason' => 'invalid_input', 'message' => 'Некорректный промокод.', 'promotion' => null, 'preview' => null];
        }
        if (!guest_history_has_table($pdo, 'restaurant_promotions')) {
            return ['ok' => false, 'reason' => 'promo_storage_missing', 'message' => 'Промокоды пока недоступны.', 'promotion' => null, 'preview' => null];
        }

        $subtotal = (float)($context['order_total'] ?? $context['subtotal'] ?? $context['total'] ?? 0);
        $subtotal = max(0.0, round($subtotal, 2));
        $resolvedGuest = isset($context['resolved_guest']) && is_array($context['resolved_guest'])
            ? $context['resolved_guest']
            : [];

        $fields = [
            'id',
            'restaurant_id',
            'code',
            'title',
            'description',
            'discount_type',
            'discount_value',
            'min_order_amount',
            'is_active',
            'valid_from',
            'valid_until',
            'usage_limit_total',
            'usage_limit_per_guest',
            'used_count',
        ];
        $selectParts = [];
        foreach ($fields as $field) {
            if (guest_history_has_column($pdo, 'restaurant_promotions', $field)) {
                $selectParts[] = $field;
            }
        }
        if ($selectParts === []) {
            return ['ok' => false, 'reason' => 'promo_schema_invalid', 'message' => 'Промокоды пока недоступны.', 'promotion' => null, 'preview' => null];
        }

        $sql = 'SELECT ' . implode(', ', $selectParts) . ' FROM restaurant_promotions WHERE restaurant_id = :restaurant_id AND UPPER(code) = :code LIMIT 1';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':restaurant_id' => $restaurantId,
            ':code' => $codeNorm,
        ]);
        $promo = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!is_array($promo)) {
            return ['ok' => false, 'reason' => 'not_found', 'message' => 'Промокод не найден.', 'promotion' => null, 'preview' => null];
        }

        $isActive = (int)($promo['is_active'] ?? 1) === 1;
        if (!$isActive) {
            return ['ok' => false, 'reason' => 'inactive', 'message' => 'Промокод неактивен.', 'promotion' => $promo, 'preview' => null];
        }

        $nowTs = time();
        $validFrom = !empty($promo['valid_from']) ? strtotime((string)$promo['valid_from']) : false;
        if ($validFrom !== false && $validFrom > $nowTs) {
            return ['ok' => false, 'reason' => 'not_started', 'message' => 'Промокод ещё не действует.', 'promotion' => $promo, 'preview' => null];
        }
        $validUntil = !empty($promo['valid_until']) ? strtotime((string)$promo['valid_until']) : false;
        if ($validUntil !== false && $validUntil < $nowTs) {
            return ['ok' => false, 'reason' => 'expired', 'message' => 'Срок действия промокода истёк.', 'promotion' => $promo, 'preview' => null];
        }

        $limitTotal = max(0, (int)($promo['usage_limit_total'] ?? 0));
        $usedCount = max(0, (int)($promo['used_count'] ?? 0));
        if ($limitTotal > 0 && $usedCount >= $limitTotal) {
            return ['ok' => false, 'reason' => 'total_limit_reached', 'message' => 'Лимит использований промокода исчерпан.', 'promotion' => $promo, 'preview' => null];
        }

        $minOrderAmount = max(0.0, (float)($promo['min_order_amount'] ?? 0));
        if ($subtotal > 0 && $subtotal < $minOrderAmount) {
            return [
                'ok' => false,
                'reason' => 'min_order_amount',
                'message' => 'Минимальная сумма заказа для промокода: ' . number_format($minOrderAmount, 0, '.', ' ') . ' ₽.',
                'promotion' => $promo,
                'preview' => null,
            ];
        }

        $guestCheck = promo_guest_can_use($pdo, $restaurantId, $promo, $resolvedGuest);
        if (!$guestCheck['ok']) {
            return [
                'ok' => false,
                'reason' => (string)($guestCheck['reason'] ?? 'guest_limit_reached'),
                'message' => 'Для этого гостя лимит использований промокода исчерпан.',
                'promotion' => $promo,
                'preview' => null,
            ];
        }

        $discountPreview = promo_calculate_discount($subtotal, [
            'discount_type' => $promo['discount_type'] ?? 'percent',
            'discount_value' => $promo['discount_value'] ?? 0,
        ]);
        $preview = array_merge($discountPreview, [
            'min_order_amount' => $minOrderAmount,
            'guest_usage_count' => (int)($guestCheck['usage_count'] ?? 0),
            'guest_usage_limit' => (int)($guestCheck['usage_limit'] ?? 0),
            'total_used_count' => $usedCount,
            'total_usage_limit' => $limitTotal,
        ]);

        return [
            'ok' => true,
            'reason' => 'ok',
            'message' => 'Промокод применим.',
            'promotion' => $promo,
            'preview' => $preview,
        ];
    }
}

if (!function_exists('promo_order_apply_preview')) {
    /**
     * @param array<string,mixed> $context
     * @return array{
     *   ok:bool,
     *   reason:string,
     *   message:string,
     *   promotion:?array<string,mixed>,
     *   preview:?array<string,mixed>
     * }
     */
    function promo_order_apply_preview(PDO $pdo, int $restaurantId, string $code, array $context = []): array
    {
        return promo_validate_code($pdo, $restaurantId, $code, $context);
    }
}

if (!function_exists('promo_register_usage')) {
    /**
     * Idempotent usage tracking: one usage row per (promotion_id, order_id).
     *
     * @param array<string,mixed> $promotion
     * @param array<string,mixed> $resolvedGuest
     * @return array{ok:bool,inserted:bool,reason:string}
     */
    function promo_register_usage(PDO $pdo, int $restaurantId, array $promotion, int $orderId, array $resolvedGuest = []): array
    {
        $promotionId = (int)($promotion['id'] ?? 0);
        if ($restaurantId <= 0 || $promotionId <= 0 || $orderId <= 0) {
            return ['ok' => false, 'inserted' => false, 'reason' => 'invalid_input'];
        }
        if (!guest_history_has_table($pdo, 'restaurant_promo_usages')) {
            return ['ok' => false, 'inserted' => false, 'reason' => 'usage_table_missing'];
        }

        $phoneNormalized = '';
        if (!empty($resolvedGuest['phone_normalized'])) {
            $phoneNormalized = (string)($resolvedGuest['phone_normalized']);
            $norm = guest_normalize_phone($phoneNormalized);
            $phoneNormalized = $norm !== null ? $norm : '';
        }
        $guestProfileId = max(0, (int)($resolvedGuest['guest_profile_id'] ?? 0));

        $insertCols = ['promotion_id', 'restaurant_id', 'order_id'];
        $insertVals = [':promotion_id', ':restaurant_id', ':order_id'];
        $params = [
            ':promotion_id' => $promotionId,
            ':restaurant_id' => $restaurantId,
            ':order_id' => $orderId,
        ];

        if (guest_history_has_column($pdo, 'restaurant_promo_usages', 'guest_profile_id')) {
            $insertCols[] = 'guest_profile_id';
            $insertVals[] = ':guest_profile_id';
            $params[':guest_profile_id'] = $guestProfileId > 0 ? $guestProfileId : null;
        }
        if (guest_history_has_column($pdo, 'restaurant_promo_usages', 'phone_normalized')) {
            $insertCols[] = 'phone_normalized';
            $insertVals[] = ':phone_normalized';
            $params[':phone_normalized'] = $phoneNormalized !== '' ? $phoneNormalized : null;
        }

        $sql = 'INSERT IGNORE INTO restaurant_promo_usages (' . implode(', ', $insertCols) . ') VALUES (' . implode(', ', $insertVals) . ')';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $inserted = $stmt->rowCount() > 0;

        if ($inserted) {
            $upd = $pdo->prepare("
                UPDATE restaurant_promotions
                SET used_count = COALESCE(used_count, 0) + 1
                WHERE id = :promotion_id
                  AND restaurant_id = :restaurant_id
            ");
            $upd->execute([
                ':promotion_id' => $promotionId,
                ':restaurant_id' => $restaurantId,
            ]);
        }

        return ['ok' => true, 'inserted' => $inserted, 'reason' => $inserted ? 'created' : 'duplicate_order'];
    }
}

if (!function_exists('loyalty_wallet_primary_guest_id')) {
    function loyalty_wallet_primary_guest_id(array $resolvedGuest): int
    {
        $guestIds = $resolvedGuest['guest_ids'] ?? [];
        if (!is_array($guestIds) || $guestIds === []) {
            return 0;
        }
        foreach ($guestIds as $guestId) {
            $id = (int)$guestId;
            if ($id > 0) {
                return $id;
            }
        }
        return 0;
    }
}

if (!function_exists('loyalty_wallet_primary_phone')) {
    function loyalty_wallet_primary_phone(array $resolvedGuest): string
    {
        $phone = trim((string)($resolvedGuest['phone_normalized'] ?? ''));
        if ($phone !== '') {
            return $phone;
        }
        $candidates = $resolvedGuest['phone_candidates'] ?? [];
        if (!is_array($candidates)) {
            return '';
        }
        foreach ($candidates as $candidate) {
            $normalized = function_exists('guest_normalize_phone')
                ? guest_normalize_phone((string)$candidate)
                : null;
            if ($normalized !== null) {
                return $normalized;
            }
        }
        return '';
    }
}

if (!function_exists('loyalty_wallet_operation_label')) {
    function loyalty_wallet_operation_label(string $type): string
    {
        return [
            'earn' => 'Начисление',
            'spend' => 'Списание',
            'manual_adjustment' => 'Ручная корректировка',
            'refund' => 'Возврат',
        ][$type] ?? 'Операция';
    }
}

if (!function_exists('loyalty_wallet_operation_normalize')) {
    function loyalty_wallet_operation_normalize(?string $rawType, int $points = 0): string
    {
        $type = strtolower(trim((string)($rawType ?? '')));
        if ($type === 'accrual' || $type === 'earn') {
            return 'earn';
        }
        if ($type === 'spend') {
            return 'spend';
        }
        if ($type === 'refund') {
            return 'refund';
        }
        if ($type === 'adjust' || $type === 'manual_adjustment') {
            return 'manual_adjustment';
        }
        if ($points < 0) {
            return 'spend';
        }
        return 'manual_adjustment';
    }
}

if (!function_exists('loyalty_wallet_model_empty')) {
    /**
     * @return array{
     *   source:string,
     *   guest_id:int,
     *   card_id:int,
     *   phone_normalized:string,
     *   guest_name:string,
     *   current_balance:int,
     *   total_earned:int,
     *   total_spent:int,
     *   total_refund:int,
     *   total_adjusted:int
     * }
     */
    function loyalty_wallet_model_empty(array $resolvedGuest): array
    {
        return [
            'source' => 'none',
            'guest_id' => loyalty_wallet_primary_guest_id($resolvedGuest),
            'card_id' => 0,
            'phone_normalized' => loyalty_wallet_primary_phone($resolvedGuest),
            'guest_name' => trim((string)($resolvedGuest['guest_name'] ?? '')) ?: 'Гость',
            'current_balance' => 0,
            'total_earned' => 0,
            'total_spent' => 0,
            'total_refund' => 0,
            'total_adjusted' => 0,
        ];
    }
}

if (!function_exists('loyalty_wallet_can_spend')) {
    function loyalty_wallet_can_spend(array $walletModel, int $points): bool
    {
        $points = max(0, $points);
        if ($points <= 0) {
            return false;
        }
        return (int)($walletModel['current_balance'] ?? 0) >= $points;
    }
}

if (!function_exists('loyalty_wallet_resolve_card_row')) {
    /**
     * @return array{id:int,guest_id:int,restaurant_id:int}|null
     */
    function loyalty_wallet_resolve_card_row(PDO $pdo, int $restaurantId, array $resolvedGuest): ?array
    {
        if (!guest_history_has_table($pdo, 'guest_cards')) {
            return null;
        }

        $guestId = loyalty_wallet_primary_guest_id($resolvedGuest);
        if ($guestId > 0 && guest_history_has_column($pdo, 'guest_cards', 'guest_id') && guest_history_has_column($pdo, 'guest_cards', 'restaurant_id')) {
            $stmt = $pdo->prepare("
                SELECT id, guest_id, restaurant_id
                FROM guest_cards
                WHERE guest_id = :guest_id
                  AND restaurant_id = :restaurant_id
                ORDER BY id DESC
                LIMIT 1
            ");
            $stmt->execute([
                ':guest_id' => $guestId,
                ':restaurant_id' => $restaurantId,
            ]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return [
                    'id' => (int)($row['id'] ?? 0),
                    'guest_id' => (int)($row['guest_id'] ?? 0),
                    'restaurant_id' => (int)($row['restaurant_id'] ?? $restaurantId),
                ];
            }
        }

        if (
            guest_history_has_table($pdo, 'guests')
            && guest_history_has_column($pdo, 'guests', 'phone')
            && guest_history_has_column($pdo, 'guest_cards', 'guest_id')
            && guest_history_has_column($pdo, 'guest_cards', 'restaurant_id')
        ) {
            $phoneCandidates = $resolvedGuest['phone_candidates'] ?? [];
            if (is_array($phoneCandidates) && $phoneCandidates !== []) {
                $in = implode(',', array_fill(0, count($phoneCandidates), '?'));
                $stmt = $pdo->prepare("
                    SELECT gc.id, gc.guest_id, gc.restaurant_id
                    FROM guest_cards gc
                    INNER JOIN guests g ON g.id = gc.guest_id
                    WHERE gc.restaurant_id = ?
                      AND g.phone IN ($in)
                    ORDER BY gc.id DESC
                    LIMIT 1
                ");
                $stmt->execute(array_merge([$restaurantId], $phoneCandidates));
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    return [
                        'id' => (int)($row['id'] ?? 0),
                        'guest_id' => (int)($row['guest_id'] ?? 0),
                        'restaurant_id' => (int)($row['restaurant_id'] ?? $restaurantId),
                    ];
                }
            }
        }

        return null;
    }
}

if (!function_exists('loyalty_guest_balance')) {
    /**
     * Unified wallet read-model (balance + aggregates).
     *
     * @return array{
     *   source:string,
     *   guest_id:int,
     *   card_id:int,
     *   phone_normalized:string,
     *   guest_name:string,
     *   current_balance:int,
     *   total_earned:int,
     *   total_spent:int,
     *   total_refund:int,
     *   total_adjusted:int
     * }
     */
    function loyalty_guest_balance(PDO $pdo, int $restaurantId, array $resolvedGuest): array
    {
        $wallet = loyalty_wallet_model_empty($resolvedGuest);
        if ($restaurantId <= 0) {
            return $wallet;
        }

        $guestId = loyalty_wallet_primary_guest_id($resolvedGuest);

        if (
            $guestId > 0
            && guest_history_has_table($pdo, 'guest_loyalty_accounts')
            && guest_history_has_table($pdo, 'guest_loyalty_tx')
        ) {
            $stmtBal = $pdo->prepare("
                SELECT balance
                FROM guest_loyalty_accounts
                WHERE guest_id = :guest_id
                  AND restaurant_id = :restaurant_id
                LIMIT 1
            ");
            $stmtBal->execute([
                ':guest_id' => $guestId,
                ':restaurant_id' => $restaurantId,
            ]);
            $wallet['current_balance'] = (int)($stmtBal->fetchColumn() ?? 0);
            $wallet['source'] = 'guest_loyalty';

            $stmtAgg = $pdo->prepare("
                SELECT
                    COALESCE(SUM(CASE WHEN type IN ('accrual','earn') AND points > 0 THEN points ELSE 0 END), 0) AS total_earned,
                    COALESCE(SUM(CASE WHEN type = 'spend' THEN ABS(points) ELSE 0 END), 0) AS total_spent,
                    COALESCE(SUM(CASE WHEN type = 'refund' THEN ABS(points) ELSE 0 END), 0) AS total_refund,
                    COALESCE(SUM(CASE WHEN type IN ('adjust','manual_adjustment') THEN points ELSE 0 END), 0) AS total_adjusted
                FROM guest_loyalty_tx
                WHERE guest_id = :guest_id
                  AND restaurant_id = :restaurant_id
            ");
            $stmtAgg->execute([
                ':guest_id' => $guestId,
                ':restaurant_id' => $restaurantId,
            ]);
            $agg = $stmtAgg->fetch(PDO::FETCH_ASSOC) ?: [];
            $wallet['total_earned'] = (int)($agg['total_earned'] ?? 0);
            $wallet['total_spent'] = (int)($agg['total_spent'] ?? 0);
            $wallet['total_refund'] = (int)($agg['total_refund'] ?? 0);
            $wallet['total_adjusted'] = (int)($agg['total_adjusted'] ?? 0);

            return $wallet;
        }

        if (guest_history_has_table($pdo, 'loyalty_accounts') && guest_history_has_table($pdo, 'loyalty_transactions')) {
            $hasCardIdShape = guest_history_has_column($pdo, 'loyalty_accounts', 'card_id');
            if ($hasCardIdShape) {
                $card = loyalty_wallet_resolve_card_row($pdo, $restaurantId, $resolvedGuest);
                if ($card !== null && (int)$card['id'] > 0) {
                    $wallet['source'] = 'loyalty_card';
                    $wallet['card_id'] = (int)$card['id'];
                    if ($wallet['guest_id'] <= 0) {
                        $wallet['guest_id'] = (int)$card['guest_id'];
                    }
                    $cardBalanceCol = guest_history_has_column($pdo, 'loyalty_accounts', 'balance')
                        ? 'balance'
                        : (guest_history_has_column($pdo, 'loyalty_accounts', 'points_balance') ? 'points_balance' : null);
                    if ($cardBalanceCol === null) {
                        return $wallet;
                    }
                    $stmtBal = $pdo->prepare("
                        SELECT COALESCE({$cardBalanceCol}, 0) AS balance
                        FROM loyalty_accounts
                        WHERE card_id = :card_id
                        LIMIT 1
                    ");
                    $stmtBal->execute([':card_id' => (int)$card['id']]);
                    $wallet['current_balance'] = (int)($stmtBal->fetchColumn() ?? 0);

                    $stmtAgg = $pdo->prepare("
                        SELECT
                            COALESCE(SUM(CASE WHEN type IN ('earn','accrual') AND points > 0 THEN points ELSE 0 END), 0) AS total_earned,
                            COALESCE(SUM(CASE WHEN type = 'spend' THEN ABS(points) ELSE 0 END), 0) AS total_spent,
                            COALESCE(SUM(CASE WHEN type = 'refund' THEN ABS(points) ELSE 0 END), 0) AS total_refund,
                            COALESCE(SUM(CASE WHEN type = 'adjust' THEN points ELSE 0 END), 0) AS total_adjusted
                        FROM loyalty_transactions
                        WHERE card_id = :card_id
                    ");
                    $stmtAgg->execute([':card_id' => (int)$card['id']]);
                    $agg = $stmtAgg->fetch(PDO::FETCH_ASSOC) ?: [];
                    $wallet['total_earned'] = (int)($agg['total_earned'] ?? 0);
                    $wallet['total_spent'] = (int)($agg['total_spent'] ?? 0);
                    $wallet['total_refund'] = (int)($agg['total_refund'] ?? 0);
                    $wallet['total_adjusted'] = (int)($agg['total_adjusted'] ?? 0);

                    return $wallet;
                }
            }

            $hasPhoneShape = guest_history_has_column($pdo, 'loyalty_accounts', 'restaurant_id')
                && guest_history_has_column($pdo, 'loyalty_accounts', 'phone');
            if ($hasPhoneShape) {
                $phoneCandidates = $resolvedGuest['phone_candidates'] ?? [];
                if (is_array($phoneCandidates) && $phoneCandidates !== []) {
                    $ph = implode(',', array_fill(0, count($phoneCandidates), '?'));
                    $balanceCol = guest_history_has_column($pdo, 'loyalty_accounts', 'points_balance') ? 'points_balance' : 'balance';
                    $stmtAcc = $pdo->prepare("
                        SELECT id, {$balanceCol} AS balance
                        FROM loyalty_accounts
                        WHERE restaurant_id = ?
                          AND phone IN ($ph)
                        ORDER BY id DESC
                        LIMIT 1
                    ");
                    $stmtAcc->execute(array_merge([$restaurantId], $phoneCandidates));
                    $account = $stmtAcc->fetch(PDO::FETCH_ASSOC);
                    if ($account) {
                        $wallet['source'] = 'loyalty_phone_legacy';
                        $wallet['current_balance'] = (int)($account['balance'] ?? 0);
                        $accountId = (int)($account['id'] ?? 0);

                        if ($accountId > 0 && guest_history_has_column($pdo, 'loyalty_transactions', 'account_id')) {
                            $stmtAgg = $pdo->prepare("
                                SELECT
                                    COALESCE(SUM(CASE WHEN type IN ('accrual','earn') AND points > 0 THEN points ELSE 0 END), 0) AS total_earned,
                                    COALESCE(SUM(CASE WHEN type = 'spend' THEN ABS(points) ELSE 0 END), 0) AS total_spent,
                                    COALESCE(SUM(CASE WHEN type = 'refund' THEN ABS(points) ELSE 0 END), 0) AS total_refund,
                                    COALESCE(SUM(CASE WHEN type IN ('adjust','manual_adjustment') THEN points ELSE 0 END), 0) AS total_adjusted
                                FROM loyalty_transactions
                                WHERE account_id = :account_id
                            ");
                            $stmtAgg->execute([':account_id' => $accountId]);
                            $agg = $stmtAgg->fetch(PDO::FETCH_ASSOC) ?: [];
                            $wallet['total_earned'] = (int)($agg['total_earned'] ?? 0);
                            $wallet['total_spent'] = (int)($agg['total_spent'] ?? 0);
                            $wallet['total_refund'] = (int)($agg['total_refund'] ?? 0);
                            $wallet['total_adjusted'] = (int)($agg['total_adjusted'] ?? 0);
                        }

                        return $wallet;
                    }
                }
            }
        }

        return $wallet;
    }
}

if (!function_exists('loyalty_guest_ledger')) {
    /**
     * @return list<array{
     *   id:int,
     *   operation_type:string,
     *   operation_label:string,
     *   points:int,
     *   note:string,
     *   created_at:?string,
     *   order_id:?int,
     *   staff_user_id:?int,
     *   source:string
     * }>
     */
    function loyalty_guest_ledger(PDO $pdo, int $restaurantId, array $resolvedGuest, int $limit = 20): array
    {
        $restaurantId = (int)$restaurantId;
        $limit = max(1, min(100, $limit));
        if ($restaurantId <= 0) {
            return [];
        }

        $guestId = loyalty_wallet_primary_guest_id($resolvedGuest);
        if (
            $guestId > 0
            && guest_history_has_table($pdo, 'guest_loyalty_tx')
        ) {
            $stmt = $pdo->prepare("
                SELECT
                    id,
                    type,
                    points,
                    note,
                    created_at,
                    order_id,
                    staff_user_id
                FROM guest_loyalty_tx
                WHERE guest_id = :guest_id
                  AND restaurant_id = :restaurant_id
                ORDER BY created_at DESC, id DESC
                LIMIT {$limit}
            ");
            $stmt->execute([
                ':guest_id' => $guestId,
                ':restaurant_id' => $restaurantId,
            ]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $out = [];
            foreach ($rows as $row) {
                $points = (int)($row['points'] ?? 0);
                $type = loyalty_wallet_operation_normalize((string)($row['type'] ?? ''), $points);
                $out[] = [
                    'id' => (int)($row['id'] ?? 0),
                    'operation_type' => $type,
                    'operation_label' => loyalty_wallet_operation_label($type),
                    'points' => $points,
                    'note' => trim((string)($row['note'] ?? '')),
                    'created_at' => !empty($row['created_at']) ? (string)$row['created_at'] : null,
                    'order_id' => isset($row['order_id']) ? (int)$row['order_id'] : null,
                    'staff_user_id' => isset($row['staff_user_id']) ? (int)$row['staff_user_id'] : null,
                    'source' => 'guest_loyalty',
                ];
            }
            return $out;
        }

        if (guest_history_has_table($pdo, 'loyalty_accounts') && guest_history_has_table($pdo, 'loyalty_transactions')) {
            if (guest_history_has_column($pdo, 'loyalty_accounts', 'card_id')) {
                $card = loyalty_wallet_resolve_card_row($pdo, $restaurantId, $resolvedGuest);
                if ($card !== null && (int)$card['id'] > 0 && guest_history_has_column($pdo, 'loyalty_transactions', 'card_id')) {
                    $metaExpr = guest_history_has_column($pdo, 'loyalty_transactions', 'meta')
                        ? 'meta'
                        : (guest_history_has_column($pdo, 'loyalty_transactions', 'comment') ? 'comment' : "''");
                    $stmt = $pdo->prepare("
                        SELECT
                            id,
                            type,
                            points,
                            {$metaExpr} AS note,
                            created_at,
                            order_id
                        FROM loyalty_transactions
                        WHERE card_id = :card_id
                        ORDER BY created_at DESC, id DESC
                        LIMIT {$limit}
                    ");
                    $stmt->execute([':card_id' => (int)$card['id']]);
                    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                    $out = [];
                    foreach ($rows as $row) {
                        $points = (int)($row['points'] ?? 0);
                        $type = loyalty_wallet_operation_normalize((string)($row['type'] ?? ''), $points);
                        $out[] = [
                            'id' => (int)($row['id'] ?? 0),
                            'operation_type' => $type,
                            'operation_label' => loyalty_wallet_operation_label($type),
                            'points' => $points,
                            'note' => trim((string)($row['note'] ?? '')),
                            'created_at' => !empty($row['created_at']) ? (string)$row['created_at'] : null,
                            'order_id' => isset($row['order_id']) ? (int)$row['order_id'] : null,
                            'staff_user_id' => null,
                            'source' => 'loyalty_card',
                        ];
                    }
                    return $out;
                }
            }

            if (guest_history_has_column($pdo, 'loyalty_accounts', 'restaurant_id') && guest_history_has_column($pdo, 'loyalty_accounts', 'phone')) {
                $phoneCandidates = $resolvedGuest['phone_candidates'] ?? [];
                if (is_array($phoneCandidates) && $phoneCandidates !== []) {
                    $ph = implode(',', array_fill(0, count($phoneCandidates), '?'));
                    $stmtAcc = $pdo->prepare("
                        SELECT id
                        FROM loyalty_accounts
                        WHERE restaurant_id = ?
                          AND phone IN ($ph)
                        ORDER BY id DESC
                        LIMIT 1
                    ");
                    $stmtAcc->execute(array_merge([$restaurantId], $phoneCandidates));
                    $accountId = (int)($stmtAcc->fetchColumn() ?: 0);
                    if ($accountId > 0 && guest_history_has_column($pdo, 'loyalty_transactions', 'account_id')) {
                        $noteExpr = guest_history_has_column($pdo, 'loyalty_transactions', 'comment')
                            ? 'comment'
                            : (guest_history_has_column($pdo, 'loyalty_transactions', 'meta') ? 'meta' : "''");
                        $stmt = $pdo->prepare("
                            SELECT
                                id,
                                type,
                                points,
                                {$noteExpr} AS note,
                                created_at,
                                order_id
                            FROM loyalty_transactions
                            WHERE account_id = :account_id
                            ORDER BY created_at DESC, id DESC
                            LIMIT {$limit}
                        ");
                        $stmt->execute([':account_id' => $accountId]);
                        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                        $out = [];
                        foreach ($rows as $row) {
                            $points = (int)($row['points'] ?? 0);
                            $type = loyalty_wallet_operation_normalize((string)($row['type'] ?? ''), $points);
                            $out[] = [
                                'id' => (int)($row['id'] ?? 0),
                                'operation_type' => $type,
                                'operation_label' => loyalty_wallet_operation_label($type),
                                'points' => $points,
                                'note' => trim((string)($row['note'] ?? '')),
                                'created_at' => !empty($row['created_at']) ? (string)$row['created_at'] : null,
                                'order_id' => isset($row['order_id']) ? (int)$row['order_id'] : null,
                                'staff_user_id' => null,
                                'source' => 'loyalty_phone_legacy',
                            ];
                        }
                        return $out;
                    }
                }
            }
        }

        return [];
    }
}

if (!function_exists('loyalty_wallet_adjust')) {
    /**
     * Transaction-safe wallet adjustment helper.
     *
     * @param array<string,mixed> $resolvedGuest
     * @param array{order_id?:int,staff_user_id?:int,note?:string} $options
     * @return array{ok:bool,balance?:int,error?:string,source?:string}
     */
    function loyalty_wallet_adjust(PDO $pdo, int $restaurantId, array $resolvedGuest, string $operationType, int $points, array $options = []): array
    {
        $restaurantId = (int)$restaurantId;
        $operationType = loyalty_wallet_operation_normalize($operationType, $points);
        $orderId = isset($options['order_id']) ? (int)$options['order_id'] : null;
        $staffUserId = isset($options['staff_user_id']) ? (int)$options['staff_user_id'] : null;
        $note = isset($options['note']) ? trim((string)$options['note']) : null;
        if ($note === '') {
            $note = null;
        }

        $absPoints = abs((int)$points);
        if ($restaurantId <= 0 || $absPoints <= 0) {
            return ['ok' => false, 'error' => 'invalid_arguments'];
        }

        $guestId = loyalty_wallet_primary_guest_id($resolvedGuest);
        if (
            $guestId > 0
            && guest_history_has_table($pdo, 'guest_loyalty_accounts')
            && guest_history_has_table($pdo, 'guest_loyalty_tx')
            && function_exists('guest_loyalty_add_points')
            && function_exists('guest_loyalty_spend_points')
        ) {
            if ($operationType === 'spend') {
                $res = guest_loyalty_spend_points($pdo, $restaurantId, $guestId, $absPoints, $staffUserId, $orderId, $note);
                return [
                    'ok' => !empty($res['ok']),
                    'balance' => isset($res['balance']) ? (int)$res['balance'] : 0,
                    'error' => !empty($res['ok']) ? null : ((string)($res['error'] ?? 'wallet_spend_failed')),
                    'source' => 'guest_loyalty',
                ];
            }
            if ($operationType === 'earn') {
                $res = guest_loyalty_add_points($pdo, $restaurantId, $guestId, $absPoints, $staffUserId, $orderId, $note);
                return [
                    'ok' => !empty($res['ok']),
                    'balance' => isset($res['balance']) ? (int)$res['balance'] : 0,
                    'error' => !empty($res['ok']) ? null : ((string)($res['error'] ?? 'wallet_earn_failed')),
                    'source' => 'guest_loyalty',
                ];
            }

            // refund / manual_adjustment: write explicit operation type into canonical ledger.
            $delta = ($operationType === 'manual_adjustment') ? (int)$points : $absPoints;
            if ($delta === 0) {
                return ['ok' => false, 'error' => 'wallet_adjust_zero_delta', 'source' => 'guest_loyalty'];
            }

            $tx = null;
            try {
                if (function_exists('guest_loyalty_tx_begin')) {
                    $tx = guest_loyalty_tx_begin($pdo);
                } else {
                    if (!$pdo->inTransaction()) {
                        $pdo->beginTransaction();
                        $tx = ['own' => true, 'sp' => null];
                    } else {
                        $tx = ['own' => false, 'sp' => null];
                    }
                }

                $stmt = $pdo->prepare("
                    SELECT balance
                    FROM guest_loyalty_accounts
                    WHERE guest_id = :guest_id AND restaurant_id = :restaurant_id
                    FOR UPDATE
                ");
                $stmt->execute([
                    ':guest_id' => $guestId,
                    ':restaurant_id' => $restaurantId,
                ]);
                $balance = $stmt->fetchColumn();

                if ($balance === false) {
                    $ins = $pdo->prepare("
                        INSERT INTO guest_loyalty_accounts (guest_id, restaurant_id, balance)
                        VALUES (:guest_id, :restaurant_id, 0)
                    ");
                    $ins->execute([
                        ':guest_id' => $guestId,
                        ':restaurant_id' => $restaurantId,
                    ]);
                    $balance = 0;
                }

                $balance = (int)$balance;
                $newBalance = $balance + $delta;
                if ($newBalance < 0) {
                    if ($tx !== null) {
                        if (function_exists('guest_loyalty_tx_undo')) {
                            guest_loyalty_tx_undo($pdo, $tx);
                        } elseif (!empty($tx['own']) && $pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                    }
                    return ['ok' => false, 'error' => 'insufficient_balance', 'source' => 'guest_loyalty'];
                }

                $upd = $pdo->prepare("
                    UPDATE guest_loyalty_accounts
                    SET balance = :balance
                    WHERE guest_id = :guest_id AND restaurant_id = :restaurant_id
                ");
                $upd->execute([
                    ':balance' => $newBalance,
                    ':guest_id' => $guestId,
                    ':restaurant_id' => $restaurantId,
                ]);

                $opTypeForLedger = $operationType;
                $txIns = $pdo->prepare("
                    INSERT INTO guest_loyalty_tx (guest_id, restaurant_id, staff_user_id, order_id, type, points, note)
                    VALUES (:guest_id, :restaurant_id, :staff_user_id, :order_id, :type, :points, :note)
                ");
                $txIns->execute([
                    ':guest_id' => $guestId,
                    ':restaurant_id' => $restaurantId,
                    ':staff_user_id' => $staffUserId > 0 ? $staffUserId : null,
                    ':order_id' => ($orderId !== null && $orderId > 0) ? $orderId : null,
                    ':type' => $opTypeForLedger,
                    ':points' => $delta,
                    ':note' => $note ?? loyalty_wallet_operation_label($operationType),
                ]);

                if ($tx !== null) {
                    if (function_exists('guest_loyalty_tx_release')) {
                        guest_loyalty_tx_release($pdo, $tx);
                    } elseif (!empty($tx['own']) && $pdo->inTransaction()) {
                        $pdo->commit();
                    }
                }

                if (function_exists('increment_usage') && function_exists('is_demo_mode') && !is_demo_mode()) {
                    try {
                        increment_usage($restaurantId, 'loyalty_transactions');
                    } catch (Throwable $eUsage) {
                        // no-op
                    }
                }

                return ['ok' => true, 'balance' => $newBalance, 'source' => 'guest_loyalty'];
            } catch (Throwable $e) {
                if ($tx !== null) {
                    if (function_exists('guest_loyalty_tx_undo')) {
                        guest_loyalty_tx_undo($pdo, $tx);
                    } elseif (!empty($tx['own']) && $pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                }
                if (function_exists('error_log')) {
                    error_log('LOYALTY_WALLET_ADJUST_FAIL guest_loyalty ' . $e->getMessage());
                }
                return ['ok' => false, 'error' => 'wallet_adjust_failed', 'source' => 'guest_loyalty'];
            }
        }

        if (guest_history_has_table($pdo, 'loyalty_accounts') && guest_history_has_table($pdo, 'loyalty_transactions')) {
            $hasCardLedger = guest_history_has_column($pdo, 'loyalty_accounts', 'card_id');
            if ($hasCardLedger) {
                $card = loyalty_wallet_resolve_card_row($pdo, $restaurantId, $resolvedGuest);
                if ($card === null || (int)$card['id'] <= 0) {
                    return ['ok' => false, 'error' => 'wallet_card_not_found'];
                }
                $cardId = (int)$card['id'];
                $txOwn = false;
                try {
                    if (!$pdo->inTransaction()) {
                        $pdo->beginTransaction();
                        $txOwn = true;
                    }
                    $cardBalanceCol = guest_history_has_column($pdo, 'loyalty_accounts', 'balance')
                        ? 'balance'
                        : (guest_history_has_column($pdo, 'loyalty_accounts', 'points_balance') ? 'points_balance' : null);
                    if ($cardBalanceCol === null) {
                        if ($txOwn && $pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        return ['ok' => false, 'error' => 'wallet_balance_column_missing'];
                    }

                    $stmt = $pdo->prepare("SELECT id, COALESCE({$cardBalanceCol},0) AS balance FROM loyalty_accounts WHERE card_id = :card_id LIMIT 1 FOR UPDATE");
                    $stmt->execute([':card_id' => $cardId]);
                    $account = $stmt->fetch(PDO::FETCH_ASSOC);
                    if (!$account) {
                        $accCols = ['card_id', $cardBalanceCol];
                        $accVals = [':card_id', '0'];
                        if (guest_history_has_column($pdo, 'loyalty_accounts', 'updated_at')) {
                            $accCols[] = 'updated_at';
                            $accVals[] = 'NOW()';
                        }
                        $insAcc = $pdo->prepare("INSERT INTO loyalty_accounts (" . implode(', ', $accCols) . ") VALUES (" . implode(', ', $accVals) . ")");
                        $insAcc->execute([':card_id' => $cardId]);
                        $account = ['id' => (int)$pdo->lastInsertId(), 'balance' => 0];
                    }
                    $balance = (int)($account['balance'] ?? 0);
                    $delta = 0;
                    if ($operationType === 'spend') {
                        $delta = -$absPoints;
                    } elseif ($operationType === 'manual_adjustment') {
                        $delta = (int)$points;
                    } else {
                        $delta = $absPoints;
                    }
                    if (($balance + $delta) < 0) {
                        if ($txOwn && $pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        return ['ok' => false, 'error' => 'insufficient_balance'];
                    }
                    $newBalance = $balance + $delta;
                    $setParts = ["{$cardBalanceCol} = :balance"];
                    if (guest_history_has_column($pdo, 'loyalty_accounts', 'updated_at')) {
                        $setParts[] = 'updated_at = NOW()';
                    }
                    $upd = $pdo->prepare("UPDATE loyalty_accounts SET " . implode(', ', $setParts) . " WHERE id = :id");
                    $upd->execute([
                        ':balance' => $newBalance,
                        ':id' => (int)($account['id'] ?? 0),
                    ]);

                    $rawTxType = $operationType;
                    if ($operationType === 'earn') {
                        $rawTxType = 'earn';
                    } elseif ($operationType === 'spend') {
                        $rawTxType = 'spend';
                    } elseif ($operationType === 'refund') {
                        $rawTxType = 'adjust';
                    } elseif ($operationType === 'manual_adjustment') {
                        $rawTxType = 'adjust';
                    }
                    $meta = json_encode([
                        'wallet_operation_type' => $operationType,
                        'staff_user_id' => $staffUserId,
                        'note' => $note,
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    $txCols = ['card_id', 'type', 'points'];
                    $txVals = [':card_id', ':type', ':points'];
                    $txParams = [
                        ':card_id' => $cardId,
                        ':type' => $rawTxType,
                        ':points' => ($operationType === 'spend') ? $absPoints : (($operationType === 'manual_adjustment') ? (int)$points : $absPoints),
                    ];
                    if (guest_history_has_column($pdo, 'loyalty_transactions', 'order_id')) {
                        $txCols[] = 'order_id';
                        $txVals[] = ':order_id';
                        $txParams[':order_id'] = ($orderId && $orderId > 0) ? $orderId : null;
                    }
                    if (guest_history_has_column($pdo, 'loyalty_transactions', 'meta')) {
                        $txCols[] = 'meta';
                        $txVals[] = ':meta';
                        $txParams[':meta'] = ($meta !== false ? $meta : '{}');
                    } elseif (guest_history_has_column($pdo, 'loyalty_transactions', 'comment')) {
                        $txCols[] = 'comment';
                        $txVals[] = ':comment';
                        $txParams[':comment'] = $note ?? loyalty_wallet_operation_label($operationType);
                    }
                    if (guest_history_has_column($pdo, 'loyalty_transactions', 'created_at')) {
                        $txCols[] = 'created_at';
                        $txVals[] = 'NOW()';
                    }
                    $ins = $pdo->prepare("INSERT INTO loyalty_transactions (" . implode(', ', $txCols) . ") VALUES (" . implode(', ', $txVals) . ")");
                    $ins->execute($txParams);
                    if ($txOwn && $pdo->inTransaction()) {
                        $pdo->commit();
                    }
                    return ['ok' => true, 'balance' => $newBalance, 'source' => 'loyalty_card'];
                } catch (Throwable $e) {
                    if ($txOwn && $pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    if (function_exists('error_log')) {
                        error_log('LOYALTY_WALLET_ADJUST_FAIL card ' . $e->getMessage());
                    }
                    return ['ok' => false, 'error' => 'wallet_adjust_failed', 'source' => 'loyalty_card'];
                }
            }

            $hasPhoneLedger = guest_history_has_column($pdo, 'loyalty_accounts', 'restaurant_id')
                && guest_history_has_column($pdo, 'loyalty_accounts', 'phone');
            if ($hasPhoneLedger) {
                $phone = loyalty_wallet_primary_phone($resolvedGuest);
                if ($phone === '') {
                    return ['ok' => false, 'error' => 'wallet_phone_not_found'];
                }
                $txOwn = false;
                try {
                    if (!$pdo->inTransaction()) {
                        $pdo->beginTransaction();
                        $txOwn = true;
                    }
                    $balanceCol = guest_history_has_column($pdo, 'loyalty_accounts', 'points_balance') ? 'points_balance' : 'balance';
                    $stmt = $pdo->prepare("
                        SELECT id, COALESCE({$balanceCol},0) AS balance
                        FROM loyalty_accounts
                        WHERE restaurant_id = :restaurant_id
                          AND phone = :phone
                        LIMIT 1
                        FOR UPDATE
                    ");
                    $stmt->execute([
                        ':restaurant_id' => $restaurantId,
                        ':phone' => $phone,
                    ]);
                    $account = $stmt->fetch(PDO::FETCH_ASSOC);
                    if (!$account) {
                        $insAcc = $pdo->prepare("
                            INSERT INTO loyalty_accounts (restaurant_id, phone, {$balanceCol})
                            VALUES (:restaurant_id, :phone, 0)
                        ");
                        $insAcc->execute([
                            ':restaurant_id' => $restaurantId,
                            ':phone' => $phone,
                        ]);
                        $account = ['id' => (int)$pdo->lastInsertId(), 'balance' => 0];
                    }

                    $balance = (int)($account['balance'] ?? 0);
                    $delta = 0;
                    if ($operationType === 'spend') {
                        $delta = -$absPoints;
                    } elseif ($operationType === 'manual_adjustment') {
                        $delta = (int)$points;
                    } else {
                        $delta = $absPoints;
                    }
                    if (($balance + $delta) < 0) {
                        if ($txOwn && $pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        return ['ok' => false, 'error' => 'insufficient_balance'];
                    }
                    $newBalance = $balance + $delta;
                    $upd = $pdo->prepare("UPDATE loyalty_accounts SET {$balanceCol} = :balance WHERE id = :id");
                    $upd->execute([
                        ':balance' => $newBalance,
                        ':id' => (int)($account['id'] ?? 0),
                    ]);

                    if (guest_history_has_column($pdo, 'loyalty_transactions', 'account_id')) {
                        $txType = ($operationType === 'earn') ? 'accrual'
                            : (($operationType === 'spend') ? 'spend'
                            : (($operationType === 'refund') ? 'accrual' : 'adjust'));
                        $noteCol = guest_history_has_column($pdo, 'loyalty_transactions', 'comment') ? 'comment'
                            : (guest_history_has_column($pdo, 'loyalty_transactions', 'meta') ? 'meta' : null);

                        $txCols = ['account_id', 'type', 'points'];
                        $txVals = [':account_id', ':type', ':points'];
                        $txParams = [
                            ':account_id' => (int)($account['id'] ?? 0),
                            ':type' => $txType,
                            ':points' => ($operationType === 'spend') ? $absPoints : (($operationType === 'manual_adjustment') ? (int)$points : $absPoints),
                        ];
                        if (guest_history_has_column($pdo, 'loyalty_transactions', 'order_id')) {
                            $txCols[] = 'order_id';
                            $txVals[] = ':order_id';
                            $txParams[':order_id'] = ($orderId && $orderId > 0) ? $orderId : null;
                        }
                        if ($noteCol !== null) {
                            $txCols[] = $noteCol;
                            $txVals[] = ':note';
                            $txParams[':note'] = $note ?? loyalty_wallet_operation_label($operationType);
                        }
                        if (guest_history_has_column($pdo, 'loyalty_transactions', 'created_at')) {
                            $txCols[] = 'created_at';
                            $txVals[] = 'NOW()';
                        }
                        $insTx = $pdo->prepare("INSERT INTO loyalty_transactions (" . implode(', ', $txCols) . ") VALUES (" . implode(', ', $txVals) . ")");
                        $insTx->execute($txParams);
                    }

                    if ($txOwn && $pdo->inTransaction()) {
                        $pdo->commit();
                    }
                    return ['ok' => true, 'balance' => $newBalance, 'source' => 'loyalty_phone_legacy'];
                } catch (Throwable $e) {
                    if ($txOwn && $pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    if (function_exists('error_log')) {
                        error_log('LOYALTY_WALLET_ADJUST_FAIL phone ' . $e->getMessage());
                    }
                    return ['ok' => false, 'error' => 'wallet_adjust_failed', 'source' => 'loyalty_phone_legacy'];
                }
            }
        }

        return ['ok' => false, 'error' => 'wallet_storage_unavailable'];
    }
}

if (!function_exists('order_type_normalize')) {
    function order_type_normalize(?string $type, ?int $tableId = null): string
    {
        $raw = strtolower(trim((string)($type ?? '')));
        $allowed = ['hall', 'delivery', 'pickup', 'preorder', 'manual'];
        if (in_array($raw, $allowed, true)) {
            return $raw;
        }
        if ((int)($tableId ?? 0) > 0) {
            return 'hall';
        }
        return 'hall';
    }
}

if (!function_exists('order_type_label')) {
    function order_type_label(?string $type, ?int $tableId = null): string
    {
        $normalized = order_type_normalize($type, $tableId);
        return [
            'hall' => 'Зал',
            'delivery' => 'Доставка',
            'pickup' => 'Самовывоз',
            'preorder' => 'Предзаказ',
            'manual' => 'Ручной',
        ][$normalized] ?? 'Зал';
    }
}

if (!function_exists('order_source_label')) {
    function order_source_label(?string $orderType, ?int $tableId = null, ?string $tableName = null): string
    {
        $type = order_type_normalize($orderType, $tableId);
        if ($type === 'delivery') {
            return 'Доставка';
        }
        if ($type === 'pickup') {
            return 'Самовывоз';
        }
        if ($type === 'preorder') {
            return 'Предзаказ';
        }
        if ($type === 'manual') {
            return 'Ручной';
        }
        return 'QR / Зал';
    }
}

if (!function_exists('courier_status_normalize')) {
    function courier_status_normalize(?string $status, ?string $orderType = null): string
    {
        $type = order_type_normalize($orderType, null);
        if ($type !== 'delivery') {
            return '';
        }

        $raw = strtolower(trim((string)($status ?? '')));
        $aliases = [
            'waiting' => 'waiting_courier',
            'awaiting' => 'waiting_courier',
            'await_courier' => 'waiting_courier',
            'waiting_dispatch' => 'waiting_courier',
            'assigned' => 'handed_to_courier',
            'picked' => 'handed_to_courier',
            'picked_up' => 'handed_to_courier',
            'courier_arriving' => 'handed_to_courier',
            'in_transit' => 'on_the_way',
            'transit' => 'on_the_way',
            'ontheway' => 'on_the_way',
            'completed' => 'delivered',
            'done' => 'delivered',
        ];
        $normalized = $aliases[$raw] ?? $raw;
        $allowed = ['waiting_courier', 'handed_to_courier', 'on_the_way', 'delivered'];
        if (in_array($normalized, $allowed, true)) {
            return $normalized;
        }
        return 'waiting_courier';
    }
}

if (!function_exists('courier_status_label')) {
    function courier_status_label(?string $status, ?string $orderType = null): string
    {
        $normalized = courier_status_normalize($status, $orderType);
        if ($normalized === '') {
            return '';
        }
        return [
            'waiting_courier' => 'Ожидает курьера',
            'handed_to_courier' => 'Передан курьеру',
            'on_the_way' => 'В пути',
            'delivered' => 'Доставлен',
        ][$normalized] ?? 'Ожидает курьера';
    }
}

if (!function_exists('courier_shift_status_normalize')) {
    function courier_shift_status_normalize(?string $status): string
    {
        $raw = strtolower(trim((string)($status ?? '')));
        $aliases = [
            'break' => 'paused',
            'pause' => 'paused',
            'ready' => 'available',
            'work' => 'busy',
        ];
        $normalized = $aliases[$raw] ?? $raw;
        $allowed = ['offline', 'online', 'available', 'busy', 'overloaded', 'paused'];
        return in_array($normalized, $allowed, true) ? $normalized : 'online';
    }
}

if (!function_exists('courier_shift_storage_ensure')) {
    function courier_shift_storage_ensure(PDO $pdo): void
    {
        static $ensured = false;
        if ($ensured) {
            return;
        }
        $ensured = true;

        try {
            if (function_exists('runtime_schema_ensure_courier_shifts')) {
                runtime_schema_ensure_courier_shifts($pdo);
                return;
            }
            $pdo->exec("
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (Throwable $e) {
            error_log('COURIER_SHIFT_SCHEMA_FAIL ' . $e->getMessage());
        }
    }
}

if (!function_exists('courier_shift_active')) {
    /**
     * @return array<string,mixed>|null
     */
    function courier_shift_active(PDO $pdo, int $restaurantId, int $courierUserId, bool $forUpdate = false): ?array
    {
        if ($restaurantId <= 0 || $courierUserId <= 0) {
            return null;
        }
        courier_shift_storage_ensure($pdo);
        if (!function_exists('db_table_exists') || !db_table_exists('courier_shifts')) {
            return null;
        }
        $sql = "
            SELECT *
            FROM courier_shifts
            WHERE restaurant_id = :rest
              AND courier_user_id = :uid
              AND active = 1
            ORDER BY started_at DESC, id DESC
            LIMIT 1
        ";
        if ($forUpdate) {
            $sql .= " FOR UPDATE";
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':rest' => $restaurantId,
            ':uid' => $courierUserId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        return is_array($row) ? $row : null;
    }
}

if (!function_exists('courier_shift_status')) {
    /**
     * @param array<string,mixed>|null $activeShift
     * @return array{
     *   key:string,
     *   label:string,
     *   is_available:bool,
     *   is_active_shift:bool,
     *   is_paused:bool,
     *   is_overloaded:bool,
     *   reason:string
     * }
     */
    function courier_shift_status(?array $activeShift, int $activeDeliveries = 0, string $locationState = 'offline', int $overloadThreshold = 4): array
    {
        $overloadThreshold = max(2, $overloadThreshold);
        $hasShift = is_array($activeShift) && (int)($activeShift['active'] ?? 0) === 1;
        if (!$hasShift) {
            return [
                'key' => 'offline',
                'label' => 'Оффлайн',
                'is_available' => false,
                'is_active_shift' => false,
                'is_paused' => false,
                'is_overloaded' => false,
                'reason' => 'no_active_shift',
            ];
        }

        $raw = courier_shift_status_normalize((string)($activeShift['shift_status'] ?? 'online'));
        if ($raw === 'paused') {
            return [
                'key' => 'paused',
                'label' => 'Пауза',
                'is_available' => false,
                'is_active_shift' => true,
                'is_paused' => true,
                'is_overloaded' => false,
                'reason' => 'shift_paused',
            ];
        }
        if ($locationState === 'offline') {
            return [
                'key' => 'offline',
                'label' => 'Оффлайн',
                'is_available' => false,
                'is_active_shift' => true,
                'is_paused' => false,
                'is_overloaded' => false,
                'reason' => 'gps_offline',
            ];
        }
        if ($locationState === 'stale' && $activeDeliveries <= 0) {
            return [
                'key' => 'online',
                'label' => 'Онлайн',
                'is_available' => true,
                'is_active_shift' => true,
                'is_paused' => false,
                'is_overloaded' => false,
                'reason' => 'gps_stale',
            ];
        }
        if ($activeDeliveries >= $overloadThreshold) {
            return [
                'key' => 'overloaded',
                'label' => 'Перегружен',
                'is_available' => false,
                'is_active_shift' => true,
                'is_paused' => false,
                'is_overloaded' => true,
                'reason' => 'active_limit_reached',
            ];
        }
        if ($activeDeliveries > 0) {
            return [
                'key' => 'busy',
                'label' => 'Занят',
                'is_available' => true,
                'is_active_shift' => true,
                'is_paused' => false,
                'is_overloaded' => false,
                'reason' => 'active_orders',
            ];
        }
        return [
            'key' => 'available',
            'label' => 'Доступен',
            'is_available' => true,
            'is_active_shift' => true,
            'is_paused' => false,
            'is_overloaded' => false,
            'reason' => 'ready',
        ];
    }
}

if (!function_exists('courier_shift_start')) {
    /**
     * @return array{ok:bool,error?:string,shift?:array<string,mixed>}
     */
    function courier_shift_start(PDO $pdo, int $restaurantId, int $courierUserId): array
    {
        if ($restaurantId <= 0 || $courierUserId <= 0) {
            return ['ok' => false, 'error' => 'invalid_params'];
        }
        courier_shift_storage_ensure($pdo);
        if (!function_exists('db_table_exists') || !db_table_exists('courier_shifts')) {
            return ['ok' => false, 'error' => 'schema_missing'];
        }

        $ownTx = false;
        try {
            if (!$pdo->inTransaction()) {
                $pdo->beginTransaction();
                $ownTx = true;
            }
            $active = courier_shift_active($pdo, $restaurantId, $courierUserId, true);
            if ($active) {
                $status = courier_shift_status_normalize((string)($active['shift_status'] ?? 'online'));
                if ($status === 'paused') {
                    $stmtResume = $pdo->prepare("
                        UPDATE courier_shifts
                        SET shift_status = 'online', updated_at = NOW()
                        WHERE id = :id AND restaurant_id = :rest
                    ");
                    $stmtResume->execute([
                        ':id' => (int)$active['id'],
                        ':rest' => $restaurantId,
                    ]);
                    $active['shift_status'] = 'online';
                    $active['updated_at'] = date('Y-m-d H:i:s');
                }
                if ($ownTx && $pdo->inTransaction()) {
                    $pdo->commit();
                }
                return ['ok' => true, 'shift' => $active];
            }

            $stmt = $pdo->prepare("
                INSERT INTO courier_shifts (
                    courier_user_id, restaurant_id, started_at, ended_at, active, shift_status,
                    deliveries_completed, deliveries_active, total_online_minutes, created_at, updated_at
                )
                VALUES (
                    :uid, :rest, NOW(), NULL, 1, 'online', 0, 0, 0, NOW(), NOW()
                )
            ");
            $stmt->execute([
                ':uid' => $courierUserId,
                ':rest' => $restaurantId,
            ]);
            $shiftId = (int)$pdo->lastInsertId();
            $stmtGet = $pdo->prepare("SELECT * FROM courier_shifts WHERE id = :id LIMIT 1");
            $stmtGet->execute([':id' => $shiftId]);
            $shift = $stmtGet->fetch(PDO::FETCH_ASSOC) ?: null;

            if ($ownTx && $pdo->inTransaction()) {
                $pdo->commit();
            }
            return ['ok' => true, 'shift' => is_array($shift) ? $shift : []];
        } catch (Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}

if (!function_exists('courier_shift_end')) {
    /**
     * @return array{ok:bool,error?:string}
     */
    function courier_shift_end(PDO $pdo, int $restaurantId, int $courierUserId): array
    {
        if ($restaurantId <= 0 || $courierUserId <= 0) {
            return ['ok' => false, 'error' => 'invalid_params'];
        }
        courier_shift_storage_ensure($pdo);
        if (!function_exists('db_table_exists') || !db_table_exists('courier_shifts')) {
            return ['ok' => false, 'error' => 'schema_missing'];
        }

        $ownTx = false;
        try {
            if (!$pdo->inTransaction()) {
                $pdo->beginTransaction();
                $ownTx = true;
            }
            $active = courier_shift_active($pdo, $restaurantId, $courierUserId, true);
            if (!$active) {
                if ($ownTx && $pdo->inTransaction()) {
                    $pdo->commit();
                }
                return ['ok' => true];
            }

            $startedTs = strtotime((string)($active['started_at'] ?? '')) ?: time();
            $onlineMinutes = max(0, (int)floor((time() - $startedTs) / 60));
            $stmt = $pdo->prepare("
                UPDATE courier_shifts
                SET
                    active = 0,
                    shift_status = 'offline',
                    ended_at = NOW(),
                    deliveries_active = 0,
                    total_online_minutes = GREATEST(COALESCE(total_online_minutes, 0), :online_minutes),
                    updated_at = NOW()
                WHERE id = :id AND restaurant_id = :rest
            ");
            $stmt->execute([
                ':online_minutes' => $onlineMinutes,
                ':id' => (int)$active['id'],
                ':rest' => $restaurantId,
            ]);

            if ($ownTx && $pdo->inTransaction()) {
                $pdo->commit();
            }
            return ['ok' => true];
        } catch (Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}

if (!function_exists('courier_shift_pause')) {
    /**
     * @return array{ok:bool,error?:string}
     */
    function courier_shift_pause(PDO $pdo, int $restaurantId, int $courierUserId): array
    {
        if ($restaurantId <= 0 || $courierUserId <= 0) {
            return ['ok' => false, 'error' => 'invalid_params'];
        }
        courier_shift_storage_ensure($pdo);
        $active = courier_shift_active($pdo, $restaurantId, $courierUserId);
        if (!$active) {
            return ['ok' => false, 'error' => 'no_active_shift'];
        }
        try {
            $stmt = $pdo->prepare("
                UPDATE courier_shifts
                SET shift_status = 'paused', updated_at = NOW()
                WHERE id = :id AND restaurant_id = :rest
            ");
            $stmt->execute([
                ':id' => (int)$active['id'],
                ':rest' => $restaurantId,
            ]);
            return ['ok' => true];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}

if (!function_exists('courier_shift_resume')) {
    /**
     * @return array{ok:bool,error?:string}
     */
    function courier_shift_resume(PDO $pdo, int $restaurantId, int $courierUserId): array
    {
        if ($restaurantId <= 0 || $courierUserId <= 0) {
            return ['ok' => false, 'error' => 'invalid_params'];
        }
        courier_shift_storage_ensure($pdo);
        $active = courier_shift_active($pdo, $restaurantId, $courierUserId);
        if (!$active) {
            return ['ok' => false, 'error' => 'no_active_shift'];
        }
        try {
            $stmt = $pdo->prepare("
                UPDATE courier_shifts
                SET shift_status = 'online', updated_at = NOW()
                WHERE id = :id AND restaurant_id = :rest
            ");
            $stmt->execute([
                ':id' => (int)$active['id'],
                ':rest' => $restaurantId,
            ]);
            return ['ok' => true];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}

if (!function_exists('courier_shift_workload')) {
    /**
     * @return array{
     *   shift_active:bool,
     *   shift_status:string,
     *   active_hours:float,
     *   total_online_minutes:int,
     *   active_deliveries:int,
     *   completed_deliveries:int,
     *   avg_delivery_duration:?int,
     *   deliveries_per_hour:float,
     *   idle_minutes:?int,
     *   overload_periods:int,
     *   active_queue_minutes:int
     * }
     */
    function courier_shift_workload(PDO $pdo, int $restaurantId, int $courierUserId, array $options = []): array
    {
        $out = [
            'shift_active' => false,
            'shift_status' => 'offline',
            'active_hours' => 0.0,
            'total_online_minutes' => 0,
            'active_deliveries' => 0,
            'completed_deliveries' => 0,
            'avg_delivery_duration' => null,
            'deliveries_per_hour' => 0.0,
            'idle_minutes' => null,
            'overload_periods' => 0,
            'active_queue_minutes' => 0,
        ];
        if ($restaurantId <= 0 || $courierUserId <= 0) {
            return $out;
        }

        $activeShift = courier_shift_active($pdo, $restaurantId, $courierUserId);
        if ($activeShift) {
            $out['shift_active'] = true;
            $out['shift_status'] = courier_shift_status_normalize((string)($activeShift['shift_status'] ?? 'online'));
            $startedTs = strtotime((string)($activeShift['started_at'] ?? '')) ?: time();
            $onlineMinutes = max(0, (int)floor((time() - $startedTs) / 60));
            $out['total_online_minutes'] = max((int)($activeShift['total_online_minutes'] ?? 0), $onlineMinutes);
            $out['active_hours'] = round($out['total_online_minutes'] / 60, 2);
        }

        if (!function_exists('db_table_exists') || !db_table_exists('orders')) {
            return $out;
        }
        $statusExpr = (function_exists('db_column_exists') && db_column_exists('orders', 'courier_status'))
            ? "LOWER(COALESCE(courier_status,''))"
            : "''";
        $sql = "
            SELECT
                SUM(CASE WHEN {$statusExpr} IN ('waiting_courier','handed_to_courier','on_the_way') THEN 1 ELSE 0 END) AS active_deliveries,
                SUM(CASE WHEN {$statusExpr} = 'delivered' AND DATE(COALESCE(delivered_at, updated_at, created_at)) = CURRENT_DATE THEN 1 ELSE 0 END) AS completed_today,
                AVG(CASE WHEN {$statusExpr} = 'delivered' AND DATE(COALESCE(delivered_at, updated_at, created_at)) = CURRENT_DATE THEN TIMESTAMPDIFF(MINUTE, COALESCE(courier_taken_at, created_at), COALESCE(delivered_at, updated_at, created_at)) END) AS avg_delivery_minutes,
                SUM(CASE WHEN {$statusExpr} IN ('waiting_courier','handed_to_courier','on_the_way') AND TIMESTAMPDIFF(MINUTE, COALESCE(courier_taken_at, created_at), NOW()) > 35 THEN 1 ELSE 0 END) AS overload_periods,
                AVG(CASE WHEN {$statusExpr} IN ('waiting_courier','handed_to_courier','on_the_way') THEN TIMESTAMPDIFF(MINUTE, COALESCE(courier_taken_at, created_at), NOW()) END) AS active_queue_minutes,
                MAX(COALESCE(courier_taken_at, created_at)) AS last_activity_at
            FROM orders
            WHERE restaurant_id = :rest
              AND courier_user_id = :uid
              AND LOWER(TRIM(COALESCE(order_type,''))) = 'delivery'
              AND LOWER(COALESCE(order_status,'new')) NOT IN ('canceled','cancelled')
        ";
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':rest' => $restaurantId,
                ':uid' => $courierUserId,
            ]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $out['active_deliveries'] = (int)($row['active_deliveries'] ?? 0);
            $out['completed_deliveries'] = (int)($row['completed_today'] ?? 0);
            $out['avg_delivery_duration'] = isset($row['avg_delivery_minutes']) && $row['avg_delivery_minutes'] !== null
                ? (int)round((float)$row['avg_delivery_minutes'])
                : null;
            $out['overload_periods'] = (int)($row['overload_periods'] ?? 0);
            $out['active_queue_minutes'] = isset($row['active_queue_minutes']) && $row['active_queue_minutes'] !== null
                ? (int)round((float)$row['active_queue_minutes'])
                : 0;
            $lastTs = strtotime((string)($row['last_activity_at'] ?? '')) ?: 0;
            $out['idle_minutes'] = $lastTs > 0 ? max(0, (int)floor((time() - $lastTs) / 60)) : null;
        } catch (Throwable $e) {
            error_log('COURIER_SHIFT_WORKLOAD_FAIL rest=' . $restaurantId . ' courier=' . $courierUserId . ' ' . $e->getMessage());
        }

        if ($out['active_hours'] > 0) {
            $out['deliveries_per_hour'] = round($out['completed_deliveries'] / max(0.1, $out['active_hours']), 2);
        }

        if ($activeShift && courier_shift_status_normalize((string)($activeShift['shift_status'] ?? 'online')) !== 'paused' && function_exists('db_table_exists') && db_table_exists('courier_shifts')) {
            try {
                $stmtUpdShift = $pdo->prepare("
                    UPDATE courier_shifts
                    SET
                        deliveries_completed = :completed,
                        deliveries_active = :active,
                        total_online_minutes = :online_minutes,
                        updated_at = NOW()
                    WHERE id = :id
                      AND restaurant_id = :rest
                ");
                $stmtUpdShift->execute([
                    ':completed' => (int)$out['completed_deliveries'],
                    ':active' => (int)$out['active_deliveries'],
                    ':online_minutes' => (int)$out['total_online_minutes'],
                    ':id' => (int)$activeShift['id'],
                    ':rest' => $restaurantId,
                ]);
            } catch (Throwable $e) {
                error_log('COURIER_SHIFT_WORKLOAD_UPDATE_FAIL rest=' . $restaurantId . ' courier=' . $courierUserId . ' ' . $e->getMessage());
            }
        }

        return $out;
    }
}

if (!function_exists('courier_shift_summary')) {
    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    function courier_shift_summary(PDO $pdo, int $restaurantId, array $options = []): array
    {
        $summary = [
            'active_couriers' => 0,
            'online_couriers' => 0,
            'available_couriers' => 0,
            'paused_couriers' => 0,
            'busy_couriers' => 0,
            'overloaded_couriers' => 0,
            'avg_online_minutes' => 0,
            'avg_online_hours' => 0.0,
            'shift_efficiency' => 0.0,
            'deliveries_per_hour' => 0.0,
            'courier_utilization' => 0.0,
            'alerts' => [],
            'states' => [],
        ];
        if ($restaurantId <= 0) {
            return $summary;
        }

        courier_shift_storage_ensure($pdo);
        if (!function_exists('db_table_exists') || !db_table_exists('courier_shifts')) {
            return $summary;
        }

        $rows = [];
        try {
            $stmt = $pdo->prepare("
                SELECT *
                FROM courier_shifts
                WHERE restaurant_id = :rest
                  AND active = 1
                ORDER BY started_at DESC
            ");
            $stmt->execute([':rest' => $restaurantId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('COURIER_SHIFT_SUMMARY_FAIL rest=' . $restaurantId . ' ' . $e->getMessage());
            return $summary;
        }

        if ($rows === []) {
            $summary['alerts'][] = ['level' => 'critical', 'label' => 'No active courier', 'message' => 'Нет активных смен курьеров.'];
            return $summary;
        }

        $overloadThreshold = max(2, (int)($options['overload_threshold'] ?? 4));
        $pausedLimitMinutes = max(10, (int)($options['paused_limit_minutes'] ?? 30));
        $staleShiftMinutes = max(180, (int)($options['stale_shift_minutes'] ?? 12 * 60));
        $sumOnline = 0;
        $sumDph = 0.0;
        $dphCount = 0;
        $activeLoadSum = 0;
        foreach ($rows as $row) {
            $uid = (int)($row['courier_user_id'] ?? 0);
            if ($uid <= 0) {
                continue;
            }
            $summary['active_couriers']++;
            $workload = courier_shift_workload($pdo, $restaurantId, $uid, $options);

            $loc = courier_location_is_fresh((string)($row['updated_at'] ?? ''), 20, 90);
            $stateMeta = courier_shift_status($row, (int)($workload['active_deliveries'] ?? 0), (string)($loc['state'] ?? 'offline'), $overloadThreshold);
            $stateKey = (string)($stateMeta['key'] ?? 'offline');
            $summary['states'][$stateKey] = (int)($summary['states'][$stateKey] ?? 0) + 1;

            if (in_array($stateKey, ['available', 'busy'], true)) {
                $summary['available_couriers']++;
            }
            if (in_array($stateKey, ['available', 'busy', 'overloaded', 'paused'], true)) {
                $summary['online_couriers']++;
            }
            if ($stateKey === 'paused') {
                $summary['paused_couriers']++;
                $updatedTs = strtotime((string)($row['updated_at'] ?? '')) ?: 0;
                if ($updatedTs > 0 && (time() - $updatedTs) > ($pausedLimitMinutes * 60)) {
                    $summary['alerts'][] = [
                        'level' => 'warning',
                        'label' => 'Paused too long',
                        'message' => 'Курьер #' . $uid . ' на паузе дольше ' . $pausedLimitMinutes . ' мин.',
                    ];
                }
            }
            if ($stateKey === 'busy') {
                $summary['busy_couriers']++;
            }
            if ($stateKey === 'overloaded') {
                $summary['overloaded_couriers']++;
                $summary['alerts'][] = [
                    'level' => 'warning',
                    'label' => 'Courier overloaded',
                    'message' => 'Курьер #' . $uid . ' перегружен активными доставками.',
                ];
            }

            $startedTs = strtotime((string)($row['started_at'] ?? '')) ?: 0;
            if ($startedTs > 0 && (time() - $startedTs) > ($staleShiftMinutes * 60)) {
                $summary['alerts'][] = [
                    'level' => 'warning',
                    'label' => 'Stale shift',
                    'message' => 'Смена курьера #' . $uid . ' длится дольше нормы.',
                ];
            }

            $sumOnline += (int)($workload['total_online_minutes'] ?? 0);
            $activeLoadSum += (int)($workload['active_deliveries'] ?? 0);
            $dph = (float)($workload['deliveries_per_hour'] ?? 0.0);
            if ($dph > 0) {
                $sumDph += $dph;
                $dphCount++;
            }
        }

        if ($summary['active_couriers'] > 0) {
            $summary['avg_online_minutes'] = (int)round($sumOnline / $summary['active_couriers']);
            $summary['avg_online_hours'] = round($summary['avg_online_minutes'] / 60, 2);
            $summary['courier_utilization'] = round(min(100, ($activeLoadSum / max(1, $summary['active_couriers'])) * 100 / max(1, $overloadThreshold)), 1);
        }
        if ($dphCount > 0) {
            $summary['deliveries_per_hour'] = round($sumDph / $dphCount, 2);
        }
        $summary['shift_efficiency'] = round(($summary['deliveries_per_hour'] * 10) + max(0, 100 - ($summary['overloaded_couriers'] * 12)), 1);
        if ($summary['available_couriers'] <= 0) {
            $summary['alerts'][] = ['level' => 'critical', 'label' => 'No active courier', 'message' => 'Нет доступных курьеров в активной смене.'];
        }

        return $summary;
    }
}

if (!function_exists('courier_earnings_storage_ensure')) {
    function courier_earnings_storage_ensure(PDO $pdo): void
    {
        static $ensured = false;
        if ($ensured) {
            return;
        }
        $ensured = true;
        try {
            if (function_exists('runtime_schema_ensure_courier_earnings')) {
                runtime_schema_ensure_courier_earnings($pdo);
                return;
            }
            $pdo->exec("
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
                    KEY idx_courier_earnings_rest_courier_created (restaurant_id, courier_user_id, created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (Throwable $e) {
            error_log('COURIER_EARNINGS_SCHEMA_FAIL ' . $e->getMessage());
        }
    }
}

if (!function_exists('courier_payout_status_normalize')) {
    function courier_payout_status_normalize(?string $status): string
    {
        $raw = strtolower(trim((string)($status ?? '')));
        $aliases = [
            'ready' => 'ready_for_payout',
            'ready_for_pay' => 'ready_for_payout',
            'review' => 'pending_review',
            'hold' => 'payout_hold',
            'estimated' => 'payout_estimated',
            'paid' => 'paid',
        ];
        $normalized = $aliases[$raw] ?? $raw;
        $allowed = ['ready_for_payout', 'pending_review', 'payout_hold', 'payout_estimated', 'paid', 'cancelled'];
        return in_array($normalized, $allowed, true) ? $normalized : 'pending_review';
    }
}

if (!function_exists('courier_bonus_calculate')) {
    /**
     * @param array<string,mixed> $order
     * @param array<string,mixed> $options
     * @return array{
     *   zone_bonus:float,
     *   sla_bonus:float,
     *   batching_bonus:float,
     *   overload_bonus:float,
     *   manual_adjustment:float,
     *   total_bonus:float,
     *   reasons:list<string>,
     *   risk_flags:list<string>
     * }
     */
    function courier_bonus_calculate(array $order, array $options = []): array
    {
        $zoneBonus = 0.0;
        $slaBonus = 0.0;
        $batchingBonus = 0.0;
        $overloadBonus = 0.0;
        $manualAdjustment = isset($options['manual_adjustment']) ? (float)$options['manual_adjustment'] : 0.0;
        $reasons = [];
        $risk = [];

        $zoneMeta = is_array($order['zone_meta'] ?? null) ? (array)$order['zone_meta'] : [];
        $zoneKey = strtolower(trim((string)($zoneMeta['zone_key'] ?? ($order['delivery_zone_key'] ?? 'unknown'))));
        $zonePriority = (int)($zoneMeta['priority'] ?? 100);
        if ($zoneKey === 'remote') {
            $zoneBonus += 70.0;
            $reasons[] = 'zone_remote';
        } elseif ($zonePriority >= 80) {
            $zoneBonus += 40.0;
            $reasons[] = 'zone_far_priority';
        } elseif ($zonePriority <= 20 && $zoneKey !== 'unknown') {
            $zoneBonus += 15.0;
            $reasons[] = 'zone_fast';
        }

        $createdTs = strtotime((string)($order['created_at'] ?? '')) ?: 0;
        $deliveredTs = strtotime((string)($order['delivered_at'] ?? '')) ?: 0;
        $durationMin = null;
        if ($createdTs > 0 && $deliveredTs > 0 && $deliveredTs >= $createdTs) {
            $durationMin = max(0, (int)floor(($deliveredTs - $createdTs) / 60));
        }
        if ($durationMin !== null) {
            if ($durationMin <= 30) {
                $slaBonus += 35.0;
                $reasons[] = 'sla_fast';
            } elseif ($durationMin <= 40) {
                $slaBonus += 20.0;
                $reasons[] = 'sla_ok';
            } elseif ($durationMin >= 90) {
                $risk[] = 'suspicious_duration';
            }
        } else {
            $risk[] = 'duration_unknown';
        }

        $batchDeliveredNearby = max(0, (int)($options['batch_delivered_nearby'] ?? 0));
        if ($batchDeliveredNearby >= 2) {
            $batchingBonus += 25.0;
            $reasons[] = 'batching_high';
        } elseif ($batchDeliveredNearby >= 1) {
            $batchingBonus += 12.0;
            $reasons[] = 'batching_basic';
        }

        $activeLoadAtDelivery = max(0, (int)($options['active_load_at_delivery'] ?? 0));
        if ($activeLoadAtDelivery >= 3) {
            $overloadBonus += 20.0;
            $reasons[] = 'overload_compensation';
        } elseif ($activeLoadAtDelivery === 2) {
            $overloadBonus += 10.0;
            $reasons[] = 'load_compensation';
        }

        $totalBonus = round($zoneBonus + $slaBonus + $batchingBonus + $overloadBonus + $manualAdjustment, 2);

        return [
            'zone_bonus' => round($zoneBonus, 2),
            'sla_bonus' => round($slaBonus, 2),
            'batching_bonus' => round($batchingBonus, 2),
            'overload_bonus' => round($overloadBonus, 2),
            'manual_adjustment' => round($manualAdjustment, 2),
            'total_bonus' => $totalBonus,
            'reasons' => array_values(array_unique($reasons)),
            'risk_flags' => array_values(array_unique($risk)),
        ];
    }
}

if (!function_exists('courier_payout_readiness')) {
    /**
     * @param array<string,mixed> $earning
     * @return array{status:string,label:string,is_ready:bool,is_hold:bool,is_review:bool,estimated_amount:float,reasons:list<string>}
     */
    function courier_payout_readiness(array $earning): array
    {
        $status = courier_payout_status_normalize((string)($earning['payout_status'] ?? 'pending_review'));
        $reasons = [];
        $isHold = false;
        $isReview = false;

        $total = (float)($earning['total_amount'] ?? 0);
        $riskRaw = trim((string)($earning['risk_flags'] ?? ''));
        $riskFlags = $riskRaw !== '' ? preg_split('/\s*,\s*/', $riskRaw) : [];
        $riskFlags = is_array($riskFlags) ? array_values(array_filter(array_map('trim', $riskFlags))) : [];
        if ($total <= 0) {
            $isReview = true;
            $reasons[] = 'zero_amount';
        }
        if ($riskFlags !== []) {
            $isHold = true;
            $isReview = true;
            $reasons[] = 'risk_flags';
        }

        if ($status === 'paid') {
            return [
                'status' => 'paid',
                'label' => 'Выплачено',
                'is_ready' => false,
                'is_hold' => false,
                'is_review' => false,
                'estimated_amount' => round($total, 2),
                'reasons' => [],
            ];
        }

        if ($isHold || $status === 'payout_hold') {
            return [
                'status' => 'payout_hold',
                'label' => 'Hold',
                'is_ready' => false,
                'is_hold' => true,
                'is_review' => true,
                'estimated_amount' => round($total, 2),
                'reasons' => $reasons !== [] ? $reasons : ['manual_hold'],
            ];
        }
        if ($isReview || $status === 'pending_review') {
            return [
                'status' => 'pending_review',
                'label' => 'На проверке',
                'is_ready' => false,
                'is_hold' => false,
                'is_review' => true,
                'estimated_amount' => round($total, 2),
                'reasons' => $reasons !== [] ? $reasons : ['review_required'],
            ];
        }

        if ($status === 'payout_estimated') {
            return [
                'status' => 'payout_estimated',
                'label' => 'Оценка',
                'is_ready' => false,
                'is_hold' => false,
                'is_review' => false,
                'estimated_amount' => round($total, 2),
                'reasons' => [],
            ];
        }

        return [
            'status' => 'ready_for_payout',
            'label' => 'Готово к выплате',
            'is_ready' => true,
            'is_hold' => false,
            'is_review' => false,
            'estimated_amount' => round($total, 2),
            'reasons' => [],
        ];
    }
}

if (!function_exists('courier_earnings_order')) {
    /**
     * @param array<string,mixed> $options
     * @return array{ok:bool,error?:string,earning?:array<string,mixed>}
     */
    function courier_earnings_order(PDO $pdo, int $restaurantId, int $orderId, array $options = []): array
    {
        if ($restaurantId <= 0 || $orderId <= 0) {
            return ['ok' => false, 'error' => 'invalid_params'];
        }
        courier_earnings_storage_ensure($pdo);
        if (!function_exists('db_table_exists') || !db_table_exists('courier_earnings') || !db_table_exists('orders')) {
            return ['ok' => false, 'error' => 'schema_missing'];
        }

        $ownTx = false;
        try {
            if (!$pdo->inTransaction()) {
                $pdo->beginTransaction();
                $ownTx = true;
            }

            $stmtOrder = $pdo->prepare("
                SELECT *
                FROM orders
                WHERE id = :id
                  AND restaurant_id = :rest
                LIMIT 1
                FOR UPDATE
            ");
            $stmtOrder->execute([
                ':id' => $orderId,
                ':rest' => $restaurantId,
            ]);
            $order = $stmtOrder->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!$order) {
                throw new RuntimeException('order_not_found');
            }
            $orderType = function_exists('order_type_normalize')
                ? order_type_normalize((string)($order['order_type'] ?? ''), isset($order['table_id']) ? (int)$order['table_id'] : null)
                : 'hall';
            if ($orderType !== 'delivery') {
                throw new RuntimeException('not_delivery');
            }
            $courierStatus = function_exists('courier_status_normalize')
                ? courier_status_normalize((string)($order['courier_status'] ?? ''), 'delivery')
                : strtolower(trim((string)($order['courier_status'] ?? '')));
            $orderStatus = strtolower(trim((string)($order['order_status'] ?? '')));
            if ($courierStatus !== 'delivered' && !in_array($orderStatus, ['delivered', 'completed'], true)) {
                throw new RuntimeException('not_delivered');
            }

            $courierUserId = (int)($order['courier_user_id'] ?? 0);
            if ($courierUserId <= 0) {
                throw new RuntimeException('courier_missing');
            }

            $total = 0.0;
            foreach (['total_amount', 'total_price', 'total'] as $col) {
                if (array_key_exists($col, $order) && $order[$col] !== null && $order[$col] !== '') {
                    $total = (float)$order[$col];
                    if ($total > 0) {
                        break;
                    }
                }
            }
            $baseRate = isset($options['base_rate']) ? (float)$options['base_rate'] : 0.06;
            $baseFloor = isset($options['base_floor']) ? (float)$options['base_floor'] : 90.0;
            $baseAmount = round(max($baseFloor, $total * $baseRate), 2);

            $shift = function_exists('courier_shift_active')
                ? courier_shift_active($pdo, $restaurantId, $courierUserId)
                : null;
            $shiftId = is_array($shift) ? (int)($shift['id'] ?? 0) : 0;

            $zones = function_exists('delivery_zone_list') ? delivery_zone_list($pdo, $restaurantId) : [];
            $zoneMeta = function_exists('delivery_zone_resolve') ? delivery_zone_resolve($order, $zones) : [];
            $order['zone_meta'] = $zoneMeta;

            $activeLoadAtDelivery = 0;
            try {
                $statusExpr = (function_exists('db_column_exists') && db_column_exists('orders', 'courier_status'))
                    ? "LOWER(COALESCE(courier_status,''))"
                    : "''";
                $stmtLoad = $pdo->prepare("
                    SELECT COUNT(*) AS cnt
                    FROM orders
                    WHERE restaurant_id = :rest
                      AND courier_user_id = :uid
                      AND id <> :order_id
                      AND LOWER(TRIM(COALESCE(order_type,''))) = 'delivery'
                      AND {$statusExpr} IN ('waiting_courier','handed_to_courier','on_the_way')
                      AND LOWER(COALESCE(order_status,'new')) NOT IN ('canceled','cancelled')
                ");
                $stmtLoad->execute([
                    ':rest' => $restaurantId,
                    ':uid' => $courierUserId,
                    ':order_id' => $orderId,
                ]);
                $activeLoadAtDelivery = (int)($stmtLoad->fetchColumn() ?: 0);
            } catch (Throwable $e) {
                $activeLoadAtDelivery = 0;
            }

            $batchDeliveredNearby = 0;
            try {
                $deliveredAt = !empty($order['delivered_at']) ? (string)$order['delivered_at'] : null;
                if ($deliveredAt !== null) {
                    $zoneKeyResolved = strtolower(trim((string)($zoneMeta['zone_key'] ?? ($order['delivery_zone_key'] ?? 'unknown'))));
                    $stmtBatch = $pdo->prepare("
                        SELECT COUNT(*) AS cnt
                        FROM orders
                        WHERE restaurant_id = :rest
                          AND courier_user_id = :uid
                          AND id <> :order_id
                          AND LOWER(TRIM(COALESCE(order_type,''))) = 'delivery'
                          AND COALESCE(delivered_at, updated_at, created_at) BETWEEN DATE_SUB(:delivered_at, INTERVAL 20 MINUTE) AND DATE_ADD(:delivered_at, INTERVAL 20 MINUTE)
                          AND (
                            (delivery_zone_key IS NOT NULL AND LOWER(TRIM(delivery_zone_key)) = :zone_key)
                            OR (:zone_key = 'unknown')
                          )
                    ");
                    $stmtBatch->execute([
                        ':rest' => $restaurantId,
                        ':uid' => $courierUserId,
                        ':order_id' => $orderId,
                        ':delivered_at' => $deliveredAt,
                        ':zone_key' => $zoneKeyResolved !== '' ? $zoneKeyResolved : 'unknown',
                    ]);
                    $batchDeliveredNearby = (int)($stmtBatch->fetchColumn() ?: 0);
                }
            } catch (Throwable $e) {
                $batchDeliveredNearby = 0;
            }

            $bonusMeta = courier_bonus_calculate($order, [
                'manual_adjustment' => isset($options['manual_adjustment']) ? (float)$options['manual_adjustment'] : 0.0,
                'active_load_at_delivery' => $activeLoadAtDelivery,
                'batch_delivered_nearby' => $batchDeliveredNearby,
            ]);
            $bonusAmount = (float)($bonusMeta['total_bonus'] ?? 0);
            $totalAmount = round(max(0.0, $baseAmount + $bonusAmount), 2);

            $earningType = strtolower(trim((string)($options['earning_type'] ?? 'delivery')));
            if ($earningType === '') {
                $earningType = 'delivery';
            }
            $riskFlags = is_array($bonusMeta['risk_flags'] ?? null) ? $bonusMeta['risk_flags'] : [];
            $payoutStatus = courier_payout_status_normalize((string)($options['payout_status'] ?? (($riskFlags !== []) ? 'payout_hold' : 'ready_for_payout')));
            $noteParts = [];
            if (is_array($bonusMeta['reasons'] ?? null) && $bonusMeta['reasons'] !== []) {
                $noteParts[] = 'bonus:' . implode('|', $bonusMeta['reasons']);
            }
            if ($riskFlags !== []) {
                $noteParts[] = 'risk:' . implode('|', $riskFlags);
            }
            $note = $noteParts !== [] ? implode('; ', $noteParts) : null;

            $stmtUpsert = $pdo->prepare("
                INSERT INTO courier_earnings
                    (courier_user_id, restaurant_id, order_id, shift_id, base_amount, bonus_amount, total_amount, earning_type, payout_status, payout_batch, note, created_at, updated_at)
                VALUES
                    (:courier_user_id, :restaurant_id, :order_id, :shift_id, :base_amount, :bonus_amount, :total_amount, :earning_type, :payout_status, :payout_batch, :note, NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    courier_user_id = VALUES(courier_user_id),
                    shift_id = VALUES(shift_id),
                    base_amount = VALUES(base_amount),
                    bonus_amount = VALUES(bonus_amount),
                    total_amount = VALUES(total_amount),
                    payout_status = VALUES(payout_status),
                    payout_batch = VALUES(payout_batch),
                    note = VALUES(note),
                    updated_at = NOW()
            ");
            $stmtUpsert->execute([
                ':courier_user_id' => $courierUserId,
                ':restaurant_id' => $restaurantId,
                ':order_id' => $orderId,
                ':shift_id' => $shiftId > 0 ? $shiftId : null,
                ':base_amount' => $baseAmount,
                ':bonus_amount' => $bonusAmount,
                ':total_amount' => $totalAmount,
                ':earning_type' => $earningType,
                ':payout_status' => $payoutStatus,
                ':payout_batch' => $options['payout_batch'] ?? null,
                ':note' => $note,
            ]);

            $stmtGet = $pdo->prepare("
                SELECT *
                FROM courier_earnings
                WHERE restaurant_id = :rest
                  AND order_id = :order_id
                  AND earning_type = :earning_type
                LIMIT 1
            ");
            $stmtGet->execute([
                ':rest' => $restaurantId,
                ':order_id' => $orderId,
                ':earning_type' => $earningType,
            ]);
            $earning = $stmtGet->fetch(PDO::FETCH_ASSOC) ?: [];
            $earning['bonus_breakdown'] = $bonusMeta;

            if ($ownTx && $pdo->inTransaction()) {
                $pdo->commit();
            }

            return ['ok' => true, 'earning' => $earning];
        } catch (Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}

if (!function_exists('courier_earnings_shift')) {
    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    function courier_earnings_shift(PDO $pdo, int $restaurantId, int $courierUserId, int $shiftId = 0, array $options = []): array
    {
        $out = [
            'courier_user_id' => $courierUserId,
            'shift_id' => $shiftId,
            'deliveries_completed' => 0,
            'base_amount' => 0.0,
            'bonus_amount' => 0.0,
            'total_amount' => 0.0,
            'bonus_earned' => 0.0,
            'tips_earned' => 0.0,
            'earnings_per_hour' => 0.0,
            'payout_ready_amount' => 0.0,
            'payout_hold_amount' => 0.0,
            'pending_review_amount' => 0.0,
            'payout_estimated_amount' => 0.0,
            'records_count' => 0,
        ];
        if ($restaurantId <= 0 || $courierUserId <= 0) {
            return $out;
        }

        courier_earnings_storage_ensure($pdo);
        if (!function_exists('db_table_exists') || !db_table_exists('courier_earnings')) {
            return $out;
        }

        $where = [
            'restaurant_id = :rest',
            'courier_user_id = :uid',
        ];
        $params = [
            ':rest' => $restaurantId,
            ':uid' => $courierUserId,
        ];
        if ($shiftId > 0) {
            $where[] = 'shift_id = :shift_id';
            $params[':shift_id'] = $shiftId;
        } else {
            $where[] = 'DATE(created_at) = CURRENT_DATE';
        }

        try {
            $stmt = $pdo->prepare("
                SELECT
                    COUNT(*) AS records_count,
                    SUM(CASE WHEN earning_type = 'delivery' THEN 1 ELSE 0 END) AS deliveries_completed,
                    COALESCE(SUM(base_amount), 0) AS base_amount,
                    COALESCE(SUM(bonus_amount), 0) AS bonus_amount,
                    COALESCE(SUM(total_amount), 0) AS total_amount,
                    COALESCE(SUM(CASE WHEN payout_status = 'ready_for_payout' THEN total_amount ELSE 0 END), 0) AS payout_ready_amount,
                    COALESCE(SUM(CASE WHEN payout_status = 'payout_hold' THEN total_amount ELSE 0 END), 0) AS payout_hold_amount,
                    COALESCE(SUM(CASE WHEN payout_status = 'pending_review' THEN total_amount ELSE 0 END), 0) AS pending_review_amount,
                    COALESCE(SUM(CASE WHEN payout_status = 'payout_estimated' THEN total_amount ELSE 0 END), 0) AS payout_estimated_amount
                FROM courier_earnings
                WHERE " . implode(' AND ', $where)
            );
            $stmt->execute($params);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            foreach (['records_count', 'deliveries_completed'] as $k) {
                $out[$k] = (int)($row[$k] ?? 0);
            }
            foreach (['base_amount', 'bonus_amount', 'total_amount', 'payout_ready_amount', 'payout_hold_amount', 'pending_review_amount', 'payout_estimated_amount'] as $k) {
                $out[$k] = round((float)($row[$k] ?? 0), 2);
            }
            $out['bonus_earned'] = $out['bonus_amount'];
        } catch (Throwable $e) {
            error_log('COURIER_EARNINGS_SHIFT_FAIL rest=' . $restaurantId . ' courier=' . $courierUserId . ' ' . $e->getMessage());
            return $out;
        }

        if (function_exists('order_tip_summary')) {
            try {
                $tips = order_tip_summary($pdo, $restaurantId, [
                    'scope' => 'courier',
                    'courier_user_id' => $courierUserId,
                    'days' => 2,
                    'limit' => 10,
                ]);
                $out['tips_earned'] = round((float)($tips['earned_tips'] ?? 0), 2);
            } catch (Throwable $e) {
                $out['tips_earned'] = 0.0;
            }
        }

        $workload = function_exists('courier_shift_workload')
            ? courier_shift_workload($pdo, $restaurantId, $courierUserId, $options)
            : ['active_hours' => 0.0];
        $activeHours = (float)($workload['active_hours'] ?? 0.0);
        if ($activeHours > 0) {
            $out['earnings_per_hour'] = round($out['total_amount'] / max(0.1, $activeHours), 2);
        }

        return $out;
    }
}

if (!function_exists('courier_earnings_period')) {
    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    function courier_earnings_period(PDO $pdo, int $restaurantId, array $options = []): array
    {
        $days = max(1, min(180, (int)($options['days'] ?? 30)));
        $from = trim((string)($options['from'] ?? ''));
        $to = trim((string)($options['to'] ?? ''));
        if ($from === '' || $to === '') {
            $to = date('Y-m-d');
            $from = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
        }

        $out = [
            'from' => $from,
            'to' => $to,
            'records_count' => 0,
            'deliveries_count' => 0,
            'base_amount' => 0.0,
            'bonus_amount' => 0.0,
            'total_amount' => 0.0,
            'payout_ready_amount' => 0.0,
            'payout_hold_amount' => 0.0,
            'pending_review_amount' => 0.0,
            'payout_estimated_amount' => 0.0,
            'top_couriers' => [],
        ];
        if ($restaurantId <= 0) {
            return $out;
        }

        courier_earnings_storage_ensure($pdo);
        if (!function_exists('db_table_exists') || !db_table_exists('courier_earnings')) {
            return $out;
        }

        try {
            $stmt = $pdo->prepare("
                SELECT
                    COUNT(*) AS records_count,
                    SUM(CASE WHEN earning_type = 'delivery' THEN 1 ELSE 0 END) AS deliveries_count,
                    COALESCE(SUM(base_amount), 0) AS base_amount,
                    COALESCE(SUM(bonus_amount), 0) AS bonus_amount,
                    COALESCE(SUM(total_amount), 0) AS total_amount,
                    COALESCE(SUM(CASE WHEN payout_status = 'ready_for_payout' THEN total_amount ELSE 0 END), 0) AS payout_ready_amount,
                    COALESCE(SUM(CASE WHEN payout_status = 'payout_hold' THEN total_amount ELSE 0 END), 0) AS payout_hold_amount,
                    COALESCE(SUM(CASE WHEN payout_status = 'pending_review' THEN total_amount ELSE 0 END), 0) AS pending_review_amount,
                    COALESCE(SUM(CASE WHEN payout_status = 'payout_estimated' THEN total_amount ELSE 0 END), 0) AS payout_estimated_amount
                FROM courier_earnings
                WHERE restaurant_id = :rest
                  AND DATE(created_at) BETWEEN :from AND :to
            ");
            $stmt->execute([
                ':rest' => $restaurantId,
                ':from' => $from,
                ':to' => $to,
            ]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            foreach (['records_count', 'deliveries_count'] as $k) {
                $out[$k] = (int)($row[$k] ?? 0);
            }
            foreach (['base_amount', 'bonus_amount', 'total_amount', 'payout_ready_amount', 'payout_hold_amount', 'pending_review_amount', 'payout_estimated_amount'] as $k) {
                $out[$k] = round((float)($row[$k] ?? 0), 2);
            }

            $stmtTop = $pdo->prepare("
                SELECT courier_user_id, COUNT(*) AS deliveries_count, COALESCE(SUM(total_amount), 0) AS total_amount, COALESCE(SUM(bonus_amount), 0) AS bonus_amount
                FROM courier_earnings
                WHERE restaurant_id = :rest
                  AND DATE(created_at) BETWEEN :from AND :to
                GROUP BY courier_user_id
                ORDER BY total_amount DESC
                LIMIT 5
            ");
            $stmtTop->execute([
                ':rest' => $restaurantId,
                ':from' => $from,
                ':to' => $to,
            ]);
            $out['top_couriers'] = $stmtTop->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('COURIER_EARNINGS_PERIOD_FAIL rest=' . $restaurantId . ' ' . $e->getMessage());
        }

        return $out;
    }
}

if (!function_exists('courier_earnings_summary')) {
    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    function courier_earnings_summary(PDO $pdo, int $restaurantId, array $options = []): array
    {
        $out = [
            'today' => [
                'records_count' => 0,
                'deliveries_count' => 0,
                'base_amount' => 0.0,
                'bonus_amount' => 0.0,
                'total_amount' => 0.0,
                'payout_ready_amount' => 0.0,
                'payout_hold_amount' => 0.0,
                'pending_review_amount' => 0.0,
                'payout_estimated_amount' => 0.0,
            ],
            'period' => [],
            'alerts' => [],
        ];
        if ($restaurantId <= 0) {
            return $out;
        }

        $out['today'] = courier_earnings_period($pdo, $restaurantId, [
            'from' => date('Y-m-d'),
            'to' => date('Y-m-d'),
        ]);
        $out['period'] = courier_earnings_period($pdo, $restaurantId, [
            'days' => (int)($options['days'] ?? 30),
        ]);

        $deliveries = (int)($out['today']['deliveries_count'] ?? 0);
        $bonus = (float)($out['today']['bonus_amount'] ?? 0);
        $base = (float)($out['today']['base_amount'] ?? 0);
        $hold = (float)($out['today']['payout_hold_amount'] ?? 0);
        if ($deliveries > 0 && $bonus <= 0) {
            $out['alerts'][] = ['level' => 'warning', 'label' => 'SLA bonus risk', 'message' => 'Есть доставки без бонусной части.'];
        }
        if ($hold > 0) {
            $out['alerts'][] = ['level' => 'critical', 'label' => 'Payout hold', 'message' => 'Часть начислений удержана до проверки.'];
        }
        if ($base > 0 && ($bonus / max(1.0, $base)) < 0.12) {
            $out['alerts'][] = ['level' => 'warning', 'label' => 'Low efficiency', 'message' => 'Низкая бонусная эффективность по доставкам.'];
        }

        return $out;
    }
}

if (!function_exists('courier_location_store')) {
    /**
     * @param array<string,mixed> $location
     * @return array{ok:bool,reason:string,stored:bool,throttled:bool,updated_at:?string}
     */
    function courier_location_store(PDO $pdo, int $restaurantId, int $orderId, int $courierUserId, array $location): array
    {
        if ($restaurantId <= 0 || $orderId <= 0 || $courierUserId <= 0) {
            return ['ok' => false, 'reason' => 'invalid_identity', 'stored' => false, 'throttled' => false, 'updated_at' => null];
        }
        if (!function_exists('db_table_exists') || !db_table_exists('courier_locations')) {
            return ['ok' => false, 'reason' => 'table_missing', 'stored' => false, 'throttled' => false, 'updated_at' => null];
        }

        $lat = isset($location['lat']) ? (float)$location['lat'] : null;
        $lng = isset($location['lng']) ? (float)$location['lng'] : null;
        if ($lat === null || $lng === null || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            return ['ok' => false, 'reason' => 'invalid_coordinates', 'stored' => false, 'throttled' => false, 'updated_at' => null];
        }

        $accuracy = isset($location['accuracy']) && $location['accuracy'] !== '' ? (float)$location['accuracy'] : null;
        $speed = isset($location['speed']) && $location['speed'] !== '' ? (float)$location['speed'] : null;
        $heading = isset($location['heading']) && $location['heading'] !== '' ? (float)$location['heading'] : null;
        $batteryLevel = isset($location['battery_level']) && $location['battery_level'] !== '' ? (int)$location['battery_level'] : null;
        if ($batteryLevel !== null) {
            $batteryLevel = max(0, min(100, $batteryLevel));
        }

        $minIntervalSec = max(3, min(30, (int)($location['min_interval_sec'] ?? 5)));
        try {
            $stmtPrev = $pdo->prepare("
                SELECT updated_at
                FROM courier_locations
                WHERE restaurant_id = :rest
                  AND order_id = :oid
                  AND courier_user_id = :uid
                LIMIT 1
            ");
            $stmtPrev->execute([
                ':rest' => $restaurantId,
                ':oid' => $orderId,
                ':uid' => $courierUserId,
            ]);
            $prev = $stmtPrev->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($prev && !empty($prev['updated_at'])) {
                $prevTs = strtotime((string)$prev['updated_at']);
                if ($prevTs !== false && (time() - $prevTs) < $minIntervalSec) {
                    return [
                        'ok' => true,
                        'reason' => 'throttled',
                        'stored' => false,
                        'throttled' => true,
                        'updated_at' => (string)$prev['updated_at'],
                    ];
                }
            }

            $stmt = $pdo->prepare("
                INSERT INTO courier_locations (
                    restaurant_id, order_id, courier_user_id, lat, lng, accuracy, speed, heading, battery_level, created_at, updated_at
                ) VALUES (
                    :rest, :oid, :uid, :lat, :lng, :accuracy, :speed, :heading, :battery, NOW(), NOW()
                )
                ON DUPLICATE KEY UPDATE
                    lat = VALUES(lat),
                    lng = VALUES(lng),
                    accuracy = VALUES(accuracy),
                    speed = VALUES(speed),
                    heading = VALUES(heading),
                    battery_level = VALUES(battery_level),
                    updated_at = NOW()
            ");
            $stmt->execute([
                ':rest' => $restaurantId,
                ':oid' => $orderId,
                ':uid' => $courierUserId,
                ':lat' => $lat,
                ':lng' => $lng,
                ':accuracy' => $accuracy,
                ':speed' => $speed,
                ':heading' => $heading,
                ':battery' => $batteryLevel,
            ]);

            return ['ok' => true, 'reason' => 'stored', 'stored' => true, 'throttled' => false, 'updated_at' => gmdate('Y-m-d H:i:s')];
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('COURIER_LOCATION_STORE_FAIL rest=' . $restaurantId . ' order=' . $orderId . ' courier=' . $courierUserId . ' ' . $e->getMessage());
            }
            return ['ok' => false, 'reason' => 'store_failed', 'stored' => false, 'throttled' => false, 'updated_at' => null];
        }
    }
}

if (!function_exists('courier_location_latest')) {
    /**
     * @return array<string,mixed>|null
     */
    function courier_location_latest(PDO $pdo, int $restaurantId, int $orderId, ?int $courierUserId = null): ?array
    {
        if ($restaurantId <= 0 || $orderId <= 0) {
            return null;
        }
        if (!function_exists('db_table_exists') || !db_table_exists('courier_locations')) {
            return null;
        }

        $hasOrderIdCol = function_exists('db_column_exists') ? db_column_exists('courier_locations', 'order_id') : true;
        if (!$hasOrderIdCol) {
            return null;
        }

        $sql = "
            SELECT id, restaurant_id, order_id, courier_user_id, lat, lng, accuracy, speed, heading, battery_level, created_at, updated_at
            FROM courier_locations
            WHERE restaurant_id = :rest
              AND order_id = :oid
        ";
        $params = [
            ':rest' => $restaurantId,
            ':oid' => $orderId,
        ];
        if ($courierUserId !== null && $courierUserId > 0) {
            $sql .= " AND courier_user_id = :uid";
            $params[':uid'] = $courierUserId;
        }
        $sql .= " ORDER BY updated_at DESC, id DESC LIMIT 1";

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('COURIER_LOCATION_LATEST_FAIL rest=' . $restaurantId . ' order=' . $orderId . ' ' . $e->getMessage());
            }
            return null;
        }
    }
}

if (!function_exists('courier_location_is_fresh')) {
    /**
     * @return array{state:string,label:string,age_seconds:?int,is_fresh:bool}
     */
    function courier_location_is_fresh(?string $updatedAt, int $liveSec = 20, int $staleSec = 90): array
    {
        $liveSec = max(5, $liveSec);
        $staleSec = max($liveSec + 1, $staleSec);
        $ts = $updatedAt ? strtotime($updatedAt) : false;
        if ($ts === false || $ts <= 0) {
            return ['state' => 'offline', 'label' => 'Позиция недоступна', 'age_seconds' => null, 'is_fresh' => false];
        }
        $age = max(0, time() - $ts);
        if ($age < $liveSec) {
            return ['state' => 'live', 'label' => 'Геопозиция обновляется', 'age_seconds' => $age, 'is_fresh' => true];
        }
        if ($age < $staleSec) {
            return ['state' => 'stale', 'label' => 'Позиция обновлялась недавно', 'age_seconds' => $age, 'is_fresh' => false];
        }
        return ['state' => 'offline', 'label' => 'Позиция устарела', 'age_seconds' => $age, 'is_fresh' => false];
    }
}

if (!function_exists('courier_location_public_payload')) {
    /**
     * @param array<string,mixed>|null $row
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    function courier_location_public_payload(?array $row, array $options = []): array
    {
        $liveSec = isset($options['live_sec']) ? (int)$options['live_sec'] : 20;
        $staleSec = isset($options['stale_sec']) ? (int)$options['stale_sec'] : 90;
        $fresh = courier_location_is_fresh($row['updated_at'] ?? null, $liveSec, $staleSec);

        $ageSec = $fresh['age_seconds'];
        $ageHuman = '';
        if ($ageSec !== null) {
            if ($ageSec < 60) {
                $ageHuman = $ageSec . ' сек назад';
            } else {
                $ageHuman = (int)floor($ageSec / 60) . ' мин назад';
            }
        }

        return [
            'has_location' => is_array($row) && !empty($row['updated_at']),
            'state' => (string)$fresh['state'],
            'label' => (string)$fresh['label'],
            'last_update_seconds' => $ageSec,
            'last_update_human' => $ageHuman,
            'updated_at' => $row['updated_at'] ?? null,
            // Coordinates kept for future map layer; current UX may ignore them.
            'lat' => isset($row['lat']) ? (float)$row['lat'] : null,
            'lng' => isset($row['lng']) ? (float)$row['lng'] : null,
            'accuracy' => isset($row['accuracy']) ? (float)$row['accuracy'] : null,
            'speed' => isset($row['speed']) ? (float)$row['speed'] : null,
            'heading' => isset($row['heading']) ? (float)$row['heading'] : null,
            'battery_level' => isset($row['battery_level']) ? (int)$row['battery_level'] : null,
            'courier_user_id' => isset($row['courier_user_id']) ? (int)$row['courier_user_id'] : 0,
        ];
    }
}

if (!function_exists('courier_order_sla_meta')) {
    /**
     * @param array<string,mixed> $order
     * @return array{level:string,label:string,elapsed_minutes:int}
     */
    function courier_order_sla_meta(array $order): array
    {
        $startRaw = (string)($order['courier_taken_at'] ?? '');
        if ($startRaw === '') {
            $startRaw = (string)($order['created_at'] ?? '');
        }
        $startTs = strtotime($startRaw);
        $elapsed = 0;
        if ($startTs !== false && $startTs > 0) {
            $elapsed = max(0, (int)floor((time() - $startTs) / 60));
        }

        if ($elapsed < 15) {
            return [
                'level' => 'normal',
                'label' => 'В норме',
                'elapsed_minutes' => $elapsed,
            ];
        }
        if ($elapsed <= 35) {
            return [
                'level' => 'warning',
                'label' => 'Риск SLA',
                'elapsed_minutes' => $elapsed,
            ];
        }
        return [
            'level' => 'critical',
            'label' => 'Просрочка',
            'elapsed_minutes' => $elapsed,
        ];
    }
}

if (!function_exists('courier_eta_estimate')) {
    /**
     * Lightweight ETA estimate without maps/GPS.
     *
     * @param array<string,mixed> $order
     * @param array<string,mixed> $options
     */
    function courier_eta_estimate(array $order, array $options = []): int
    {
        $nowTs = isset($options['now_ts']) ? (int)$options['now_ts'] : time();
        if ($nowTs <= 0) {
            $nowTs = time();
        }

        $avgTotal = max(20, (int)($options['avg_delivery_minutes'] ?? 35));
        $avgPickup = max(5, (int)($options['avg_pickup_minutes'] ?? 10));
        $avgOnWay = max(8, (int)($options['avg_on_the_way_minutes'] ?? 18));

        $createdTs = strtotime((string)($order['created_at'] ?? '')) ?: 0;
        $takenTs = strtotime((string)($order['courier_taken_at'] ?? '')) ?: 0;
        $onWayTs = strtotime((string)($order['courier_on_the_way_at'] ?? '')) ?: 0;
        $deliveredTs = strtotime((string)($order['delivered_at'] ?? '')) ?: 0;

        if ($deliveredTs > 0) {
            return 0;
        }

        $orderType = order_type_normalize((string)($order['order_type'] ?? ''), isset($order['table_id']) ? (int)$order['table_id'] : null);
        if ($orderType !== 'delivery') {
            return 0;
        }
        $courierStatus = courier_status_normalize((string)($order['courier_status'] ?? ''), $orderType);

        if ($courierStatus === 'on_the_way' && $onWayTs > 0) {
            $elapsedOnWay = max(0, (int)floor(($nowTs - $onWayTs) / 60));
            return max(1, $avgOnWay - $elapsedOnWay);
        }

        if ($courierStatus === 'handed_to_courier' && $takenTs > 0) {
            $elapsedSinceTake = max(0, (int)floor(($nowTs - $takenTs) / 60));
            $handoffResidual = max(1, (int)round($avgOnWay * 0.85));
            return max(1, $handoffResidual - $elapsedSinceTake);
        }

        if ($courierStatus === 'waiting_courier' && $createdTs > 0) {
            $elapsed = max(0, (int)floor(($nowTs - $createdTs) / 60));
            if ($elapsed <= $avgPickup) {
                return max(1, $avgTotal - $elapsed);
            }
            $residualAfterPickup = max(1, $avgOnWay + (int)round($avgPickup * 0.35));
            return max(1, $residualAfterPickup - max(0, $elapsed - $avgPickup));
        }

        if ($createdTs > 0) {
            $elapsed = max(0, (int)floor(($nowTs - $createdTs) / 60));
            return max(1, $avgTotal - $elapsed);
        }

        return max(1, $avgTotal);
    }
}

if (!function_exists('courier_eta_label')) {
    function courier_eta_label(?int $etaMinutes, ?string $timingState = null): string
    {
        $eta = max(0, (int)($etaMinutes ?? 0));
        $state = strtolower(trim((string)($timingState ?? '')));

        if ($state === 'delivered' || $eta === 0) {
            return 'Доставлено';
        }
        if ($state === 'almost_arrived' || $eta <= 5) {
            return 'Курьер уже рядом';
        }
        if ($state === 'on_the_way') {
            return '~' . $eta . ' мин';
        }
        if ($state === 'courier_arriving') {
            return 'Курьер забрал заказ';
        }
        if ($state === 'searching_courier') {
            return 'Ищем курьера';
        }
        if ($state === 'preparing') {
            return 'Готовим заказ';
        }

        return '~' . max(1, $eta) . ' мин';
    }
}

if (!function_exists('courier_delivery_timing')) {
    /**
     * Unified delivery timing state for guest/staff/courier UX.
     *
     * @param array<string,mixed> $order
     * @param array<string,mixed> $options
     * @return array{
     *   eta_minutes:int,
     *   eta_label:string,
     *   timing_state:string,
     *   timing_progress_percent:int,
     *   elapsed_minutes:int
     * }
     */
    function courier_delivery_timing(array $order, array $options = []): array
    {
        $nowTs = isset($options['now_ts']) ? (int)$options['now_ts'] : time();
        if ($nowTs <= 0) {
            $nowTs = time();
        }

        $orderType = order_type_normalize((string)($order['order_type'] ?? ''), isset($order['table_id']) ? (int)$order['table_id'] : null);
        $orderStatus = strtolower(trim((string)($order['order_status'] ?? 'new')));
        $orderStatusNorm = [
            'created' => 'new',
            'pending' => 'new',
            'confirmed' => 'accepted',
            'in_progress' => 'cooking',
            'preparing' => 'cooking',
            'processing' => 'cooking',
            'completed' => 'delivered',
            'served' => 'delivered',
            'cancelled' => 'canceled',
        ][$orderStatus] ?? $orderStatus;

        $courierStatus = courier_status_normalize((string)($order['courier_status'] ?? ''), $orderType);
        $createdTs = strtotime((string)($order['created_at'] ?? '')) ?: 0;
        $deliveredTs = strtotime((string)($order['delivered_at'] ?? '')) ?: 0;
        $elapsed = ($createdTs > 0) ? max(0, (int)floor(($nowTs - $createdTs) / 60)) : 0;

        if ($orderType !== 'delivery') {
            return [
                'eta_minutes' => 0,
                'eta_label' => '',
                'timing_state' => 'preparing',
                'timing_progress_percent' => 0,
                'elapsed_minutes' => $elapsed,
            ];
        }

        $eta = courier_eta_estimate($order, $options);
        $state = 'preparing';
        $progress = 20;

        if ($deliveredTs > 0 || in_array($orderStatusNorm, ['delivered', 'completed'], true) || $courierStatus === 'delivered') {
            $state = 'delivered';
            $progress = 100;
            $eta = 0;
        } elseif ($courierStatus === 'on_the_way') {
            $state = ($eta <= 5) ? 'almost_arrived' : 'on_the_way';
            $progress = ($eta <= 5) ? 95 : 88;
        } elseif ($courierStatus === 'handed_to_courier') {
            $state = 'courier_arriving';
            $progress = 76;
        } elseif ($courierStatus === 'waiting_courier') {
            if (in_array($orderStatusNorm, ['ready', 'accepted', 'new', 'cooking'], true)) {
                $state = 'searching_courier';
                $progress = 62;
            }
        } elseif (in_array($orderStatusNorm, ['cooking', 'accepted', 'new'], true)) {
            $state = 'preparing';
            $progress = ($orderStatusNorm === 'cooking') ? 45 : 28;
        }

        return [
            'eta_minutes' => max(0, (int)$eta),
            'eta_label' => courier_eta_label($eta, $state),
            'timing_state' => $state,
            'timing_progress_percent' => max(0, min(100, (int)$progress)),
            'elapsed_minutes' => $elapsed,
        ];
    }
}

if (!function_exists('delivery_dispatch_status_normalize')) {
    function delivery_dispatch_status_normalize(?string $status): string
    {
        $raw = strtolower(trim((string)($status ?? '')));
        $aliases = [
            'waiting' => 'waiting_dispatch',
            'waiting_courier' => 'waiting_dispatch',
            'waiting_dispatch' => 'waiting_dispatch',
            'assigned' => 'assigned',
            'handed_to_courier' => 'assigned',
            'courier_arriving' => 'courier_arriving',
            'picked' => 'picked_up',
            'picked_up' => 'picked_up',
            'pickup' => 'picked_up',
            'on_the_way' => 'on_the_way',
            'in_transit' => 'on_the_way',
            'transit' => 'on_the_way',
            'delivered' => 'delivered',
            'completed' => 'delivered',
            'failed' => 'failed',
            'cancelled' => 'cancelled',
            'canceled' => 'cancelled',
        ];
        $normalized = $aliases[$raw] ?? $raw;
        $allowed = ['waiting_dispatch', 'assigned', 'courier_arriving', 'picked_up', 'on_the_way', 'delivered', 'failed', 'cancelled'];
        return in_array($normalized, $allowed, true) ? $normalized : 'waiting_dispatch';
    }
}

if (!function_exists('delivery_dispatch_status_label')) {
    function delivery_dispatch_status_label(?string $status): string
    {
        $normalized = delivery_dispatch_status_normalize($status);
        return [
            'waiting_dispatch' => 'Ожидает диспетчера',
            'assigned' => 'Назначен',
            'courier_arriving' => 'Курьер направляется',
            'picked_up' => 'Забран с кухни',
            'on_the_way' => 'В пути',
            'delivered' => 'Доставлен',
            'failed' => 'Сбой доставки',
            'cancelled' => 'Отменён',
        ][$normalized] ?? 'Ожидает диспетчера';
    }
}

if (!function_exists('delivery_dispatch_state_from_order')) {
    /**
     * @param array<string,mixed> $order
     */
    function delivery_dispatch_state_from_order(array $order): string
    {
        $orderStatus = strtolower(trim((string)($order['order_status'] ?? 'new')));
        if (in_array($orderStatus, ['cancelled', 'canceled'], true)) {
            return 'cancelled';
        }
        if (in_array($orderStatus, ['failed'], true)) {
            return 'failed';
        }

        $courierStatus = courier_status_normalize((string)($order['courier_status'] ?? ''), 'delivery');
        if ($courierStatus === 'delivered' || in_array($orderStatus, ['delivered', 'completed'], true)) {
            return 'delivered';
        }
        if ($courierStatus === 'on_the_way') {
            return 'on_the_way';
        }
        if ($courierStatus === 'handed_to_courier') {
            if (!empty($order['courier_on_the_way_at'])) {
                return 'picked_up';
            }
            if (!empty($order['courier_taken_at'])) {
                return 'courier_arriving';
            }
            return 'assigned';
        }
        if (!empty($order['courier_user_id']) && (int)$order['courier_user_id'] > 0) {
            return 'assigned';
        }
        return 'waiting_dispatch';
    }
}

if (!function_exists('delivery_dispatch_address_bucket')) {
    function delivery_dispatch_address_bucket(?string $address): string
    {
        $value = mb_strtolower(trim((string)($address ?? '')), 'UTF-8');
        if ($value === '') {
            return 'unknown';
        }
        if (preg_match('/(центр|централь|цao|цво|center)/u', $value)) {
            return 'near';
        }
        if (preg_match('/(км|д\.\s*|корп|мкр|район|district|ул\.|улица)/u', $value)) {
            return 'mid';
        }
        return 'far';
    }
}

if (!function_exists('delivery_zone_defaults')) {
    /**
     * @return array<string,array{zone_key:string,zone_name:string,active:int,priority:int,avg_eta_minutes:int,delivery_fee:float,free_delivery_from:?float,color:string,match_keywords:string}>
     */
    function delivery_zone_defaults(): array
    {
        return [
            'central' => [
                'zone_key' => 'central',
                'zone_name' => 'Центр',
                'active' => 1,
                'priority' => 10,
                'avg_eta_minutes' => 25,
                'delivery_fee' => 0.0,
                'free_delivery_from' => 1200.0,
                'color' => '#34D399',
                'match_keywords' => 'центр,центральная,central,цao,цво',
            ],
            'north' => [
                'zone_key' => 'north',
                'zone_name' => 'Север',
                'active' => 1,
                'priority' => 20,
                'avg_eta_minutes' => 35,
                'delivery_fee' => 120.0,
                'free_delivery_from' => 1800.0,
                'color' => '#38BDF8',
                'match_keywords' => 'север,north',
            ],
            'south' => [
                'zone_key' => 'south',
                'zone_name' => 'Юг',
                'active' => 1,
                'priority' => 30,
                'avg_eta_minutes' => 38,
                'delivery_fee' => 140.0,
                'free_delivery_from' => 2000.0,
                'color' => '#F59E0B',
                'match_keywords' => 'юг,south',
            ],
            'east' => [
                'zone_key' => 'east',
                'zone_name' => 'Восток',
                'active' => 1,
                'priority' => 40,
                'avg_eta_minutes' => 36,
                'delivery_fee' => 130.0,
                'free_delivery_from' => 1800.0,
                'color' => '#A78BFA',
                'match_keywords' => 'восток,east',
            ],
            'west' => [
                'zone_key' => 'west',
                'zone_name' => 'Запад',
                'active' => 1,
                'priority' => 50,
                'avg_eta_minutes' => 36,
                'delivery_fee' => 130.0,
                'free_delivery_from' => 1800.0,
                'color' => '#F472B6',
                'match_keywords' => 'запад,west',
            ],
            'remote' => [
                'zone_key' => 'remote',
                'zone_name' => 'Удалённая зона',
                'active' => 1,
                'priority' => 90,
                'avg_eta_minutes' => 50,
                'delivery_fee' => 220.0,
                'free_delivery_from' => null,
                'color' => '#FB7185',
                'match_keywords' => 'область,remote,пригород',
            ],
            'unknown' => [
                'zone_key' => 'unknown',
                'zone_name' => 'Не определена',
                'active' => 1,
                'priority' => 100,
                'avg_eta_minutes' => 40,
                'delivery_fee' => 0.0,
                'free_delivery_from' => null,
                'color' => '#94A3B8',
                'match_keywords' => '',
            ],
        ];
    }
}

if (!function_exists('delivery_zone_storage_ensure')) {
    function delivery_zone_storage_ensure(PDO $pdo): void
    {
        static $ensured = false;
        if ($ensured) {
            return;
        }
        $ensured = true;

        try {
            $pdo->exec("
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (Throwable $e) {
            error_log('DELIVERY_ZONES_ENSURE_FAIL ' . $e->getMessage());
        }
    }
}

if (!function_exists('delivery_zone_seed_defaults')) {
    function delivery_zone_seed_defaults(PDO $pdo, int $restaurantId): void
    {
        if ($restaurantId <= 0 || !function_exists('db_table_exists') || !db_table_exists('delivery_zones')) {
            return;
        }
        static $seeded = [];
        if (isset($seeded[$restaurantId])) {
            return;
        }
        $seeded[$restaurantId] = true;

        $defaults = delivery_zone_defaults();
        $stmt = $pdo->prepare("
            INSERT INTO delivery_zones
                (restaurant_id, zone_key, zone_name, active, priority, avg_eta_minutes, delivery_fee, free_delivery_from, color, match_keywords)
            VALUES
                (:restaurant_id, :zone_key, :zone_name, :active, :priority, :avg_eta_minutes, :delivery_fee, :free_delivery_from, :color, :match_keywords)
            ON DUPLICATE KEY UPDATE
                zone_name = VALUES(zone_name),
                active = VALUES(active),
                priority = VALUES(priority),
                avg_eta_minutes = VALUES(avg_eta_minutes),
                delivery_fee = VALUES(delivery_fee),
                free_delivery_from = VALUES(free_delivery_from),
                color = VALUES(color),
                match_keywords = VALUES(match_keywords)
        ");

        try {
            foreach ($defaults as $zone) {
                $stmt->execute([
                    ':restaurant_id' => $restaurantId,
                    ':zone_key' => (string)$zone['zone_key'],
                    ':zone_name' => (string)$zone['zone_name'],
                    ':active' => (int)$zone['active'],
                    ':priority' => (int)$zone['priority'],
                    ':avg_eta_minutes' => max(5, (int)$zone['avg_eta_minutes']),
                    ':delivery_fee' => (float)$zone['delivery_fee'],
                    ':free_delivery_from' => $zone['free_delivery_from'],
                    ':color' => (string)$zone['color'],
                    ':match_keywords' => (string)$zone['match_keywords'],
                ]);
            }
        } catch (Throwable $e) {
            error_log('DELIVERY_ZONES_SEED_FAIL rest_id=' . $restaurantId . ' ' . $e->getMessage());
        }
    }
}

if (!function_exists('delivery_zone_list')) {
    /**
     * @return array<string,array{zone_key:string,zone_name:string,active:int,priority:int,avg_eta_minutes:int,delivery_fee:float,free_delivery_from:?float,color:string,match_keywords:string}>
     */
    function delivery_zone_list(PDO $pdo, int $restaurantId): array
    {
        delivery_zone_storage_ensure($pdo);
        delivery_zone_seed_defaults($pdo, $restaurantId);

        $defaults = delivery_zone_defaults();
        if ($restaurantId <= 0 || !function_exists('db_table_exists') || !db_table_exists('delivery_zones')) {
            return $defaults;
        }

        $rows = [];
        try {
            $stmt = $pdo->prepare("
                SELECT zone_key, zone_name, active, priority, avg_eta_minutes, delivery_fee, free_delivery_from, color, match_keywords
                FROM delivery_zones
                WHERE restaurant_id = :rid
                ORDER BY priority ASC, zone_name ASC
            ");
            $stmt->execute([':rid' => $restaurantId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('DELIVERY_ZONES_LIST_FAIL rest_id=' . $restaurantId . ' ' . $e->getMessage());
        }

        if ($rows === []) {
            return $defaults;
        }

        $result = [];
        foreach ($rows as $row) {
            $key = strtolower(trim((string)($row['zone_key'] ?? 'unknown')));
            if ($key === '') {
                $key = 'unknown';
            }
            $default = $defaults[$key] ?? $defaults['unknown'];
            $result[$key] = [
                'zone_key' => $key,
                'zone_name' => trim((string)($row['zone_name'] ?? '')) !== '' ? (string)$row['zone_name'] : (string)$default['zone_name'],
                'active' => (int)($row['active'] ?? 1),
                'priority' => (int)($row['priority'] ?? (int)$default['priority']),
                'avg_eta_minutes' => max(5, (int)($row['avg_eta_minutes'] ?? (int)$default['avg_eta_minutes'])),
                'delivery_fee' => (float)($row['delivery_fee'] ?? (float)$default['delivery_fee']),
                'free_delivery_from' => $row['free_delivery_from'] !== null ? (float)$row['free_delivery_from'] : $default['free_delivery_from'],
                'color' => trim((string)($row['color'] ?? '')) !== '' ? (string)$row['color'] : (string)$default['color'],
                'match_keywords' => (string)($row['match_keywords'] ?? (string)$default['match_keywords']),
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
            return strcmp((string)($a['zone_name'] ?? ''), (string)($b['zone_name'] ?? ''));
        });
        return $result;
    }
}

if (!function_exists('delivery_zone_match')) {
    /**
     * @param array{zone_key:string,zone_name:string,active:int,priority:int,avg_eta_minutes:int,delivery_fee:float,free_delivery_from:?float,color:string,match_keywords:string} $zone
     * @return array{matched:bool,score:int,reason:string}
     */
    function delivery_zone_match(?string $address, array $zone): array
    {
        $addressValue = mb_strtolower(trim((string)($address ?? '')), 'UTF-8');
        if ($addressValue === '') {
            return ['matched' => false, 'score' => 0, 'reason' => 'empty_address'];
        }
        $zoneKey = strtolower(trim((string)($zone['zone_key'] ?? 'unknown')));
        $keywordsRaw = trim((string)($zone['match_keywords'] ?? ''));
        $keywords = $keywordsRaw !== '' ? preg_split('/\s*,\s*/u', $keywordsRaw) : [];
        $keywords = is_array($keywords) ? array_filter(array_map('trim', $keywords), static fn(string $v): bool => $v !== '') : [];

        foreach ($keywords as $keyword) {
            if (mb_stripos($addressValue, mb_strtolower($keyword, 'UTF-8'), 0, 'UTF-8') !== false) {
                return ['matched' => true, 'score' => 90, 'reason' => 'keyword:' . $keyword];
            }
        }

        $bucket = delivery_dispatch_address_bucket($addressValue);
        if ($zoneKey === 'central' && $bucket === 'near') {
            return ['matched' => true, 'score' => 55, 'reason' => 'bucket_near'];
        }
        if (in_array($zoneKey, ['north', 'south', 'east', 'west'], true) && $bucket === 'mid') {
            return ['matched' => true, 'score' => 45, 'reason' => 'bucket_mid'];
        }
        if ($zoneKey === 'remote' && $bucket === 'far') {
            return ['matched' => true, 'score' => 50, 'reason' => 'bucket_far'];
        }
        if ($zoneKey === 'unknown') {
            return ['matched' => true, 'score' => 10, 'reason' => 'fallback_unknown'];
        }
        return ['matched' => false, 'score' => 0, 'reason' => 'no_match'];
    }
}

if (!function_exists('delivery_zone_resolve')) {
    /**
     * @param array<string,mixed> $order
     * @param array<string,array{zone_key:string,zone_name:string,active:int,priority:int,avg_eta_minutes:int,delivery_fee:float,free_delivery_from:?float,color:string,match_keywords:string}> $zones
     * @return array{zone_key:string,zone_name:string,active:int,priority:int,avg_eta_minutes:int,delivery_fee:float,free_delivery_from:?float,color:string,match_keywords:string,match_score:int,match_reason:string,manual_override:bool}
     */
    function delivery_zone_resolve(array $order, array $zones): array
    {
        $defaults = delivery_zone_defaults();
        $unknown = $zones['unknown'] ?? $defaults['unknown'];
        if ($zones === []) {
            return $unknown + ['match_score' => 0, 'match_reason' => 'zones_empty', 'manual_override' => false];
        }

        $manualZone = strtolower(trim((string)($order['delivery_zone_key'] ?? '')));
        if ($manualZone !== '' && isset($zones[$manualZone])) {
            $selected = $zones[$manualZone];
            return $selected + ['match_score' => 100, 'match_reason' => 'manual_override', 'manual_override' => true];
        }

        $address = trim((string)($order['delivery_address'] ?? ''));
        $best = null;
        $bestScore = -1;
        $bestReason = 'no_match';
        foreach ($zones as $zoneKey => $zone) {
            if ((int)($zone['active'] ?? 1) !== 1) {
                continue;
            }
            $match = delivery_zone_match($address, $zone);
            if (!empty($match['matched']) && (int)$match['score'] > $bestScore) {
                $best = $zone;
                $bestScore = (int)$match['score'];
                $bestReason = (string)$match['reason'];
            } elseif ((int)$match['score'] > $bestScore && $zoneKey === 'unknown') {
                $best = $zone;
                $bestScore = (int)$match['score'];
                $bestReason = (string)$match['reason'];
            }
        }

        if (!is_array($best)) {
            $best = $unknown;
            $bestScore = 0;
            $bestReason = 'fallback_unknown';
        }

        return $best + [
            'match_score' => max(0, $bestScore),
            'match_reason' => $bestReason,
            'manual_override' => false,
        ];
    }
}

if (!function_exists('delivery_dispatch_order_priority')) {
    /**
     * @param array<string,mixed> $order
     * @param array<string,mixed> $options
     * @return array{
     *   score:int,
     *   level:string,
     *   label:string,
     *   reasons:list<string>,
     *   distance_bucket:string,
     *   waiting_minutes:int,
     *   overdue_risk:int,
     *   zone_key:string,
     *   zone_pressure:int
     * }
     */
    function delivery_dispatch_order_priority(array $order, array $options = []): array
    {
        $nowTs = (int)($options['now_ts'] ?? time());
        if ($nowTs <= 0) {
            $nowTs = time();
        }
        $createdTs = strtotime((string)($order['created_at'] ?? '')) ?: $nowTs;
        $waitingMinutes = max(0, (int)floor(($nowTs - $createdTs) / 60));

        $timing = courier_delivery_timing([
            'order_type' => 'delivery',
            'order_status' => (string)($order['order_status'] ?? 'new'),
            'courier_status' => (string)($order['courier_status'] ?? ''),
            'created_at' => $order['created_at'] ?? null,
            'courier_taken_at' => $order['courier_taken_at'] ?? null,
            'courier_on_the_way_at' => $order['courier_on_the_way_at'] ?? null,
            'delivered_at' => $order['delivered_at'] ?? null,
        ], [
            'avg_delivery_minutes' => (int)($options['avg_delivery_minutes'] ?? 35),
            'avg_pickup_minutes' => (int)($options['avg_pickup_minutes'] ?? 10),
            'avg_on_the_way_minutes' => (int)($options['avg_on_the_way_minutes'] ?? 18),
        ]);
        $etaMinutes = max(0, (int)($timing['eta_minutes'] ?? 0));
        $slaMeta = courier_order_sla_meta([
            'created_at' => $order['created_at'] ?? null,
            'courier_taken_at' => $order['courier_taken_at'] ?? null,
        ]);

        $score = 0;
        $reasons = [];

        $score += min(45, (int)floor($waitingMinutes * 1.4));
        if ($waitingMinutes >= 15) {
            $reasons[] = 'долгое ожидание';
        }

        $overdueRisk = 0;
        if ((string)($slaMeta['level'] ?? 'normal') === 'critical') {
            $score += 28;
            $overdueRisk = 2;
            $reasons[] = 'риск SLA';
        } elseif ((string)($slaMeta['level'] ?? 'normal') === 'warning') {
            $score += 15;
            $overdueRisk = 1;
        }

        $orderStatus = strtolower(trim((string)($order['order_status'] ?? 'new')));
        if ($orderStatus === 'ready') {
            $score += 18;
            $reasons[] = 'кухня готова';
        } elseif ($orderStatus === 'cooking') {
            $score += 8;
        }

        $total = (float)($order['total_price'] ?? $order['total_amount'] ?? 0);
        if ($total >= 2500) {
            $score += 8;
            $reasons[] = 'высокий чек';
        }

        $guestOrders = (int)($order['guest_orders_count'] ?? 0);
        if ($guestOrders >= 5) {
            $score += 10;
            $reasons[] = 'repeat guest';
        } elseif ($guestOrders >= 2) {
            $score += 5;
        }

        $distanceBucket = delivery_dispatch_address_bucket((string)($order['delivery_address'] ?? ''));
        if ($distanceBucket === 'far') {
            $score += 6;
        } elseif ($distanceBucket === 'near') {
            $score += 2;
        }

        $zoneMeta = is_array($order['zone_meta'] ?? null)
            ? (array)$order['zone_meta']
            : (is_array($options['zone_meta'] ?? null) ? (array)$options['zone_meta'] : []);
        $zoneKey = strtolower(trim((string)($zoneMeta['zone_key'] ?? 'unknown')));
        $zonePriority = (int)($zoneMeta['priority'] ?? 100);
        $zoneEtaTarget = max(5, (int)($zoneMeta['avg_eta_minutes'] ?? 35));
        $zonePressure = 0;
        if ($zoneKey === 'unknown') {
            $score += 7;
            $zonePressure += 10;
            $reasons[] = 'зона не определена';
        }
        if ($zonePriority >= 80) {
            $score += 5;
            $zonePressure += 8;
        } elseif ($zonePriority <= 20) {
            $score += 2;
            $zonePressure += 3;
        }
        if ($etaMinutes > $zoneEtaTarget) {
            $score += 6;
            $zonePressure += 8;
        }

        if ($etaMinutes > 35) {
            $score += 12;
            $reasons[] = 'ETA риск';
        }

        $level = 'normal';
        if ($score >= 75) {
            $level = 'critical';
        } elseif ($score >= 45) {
            $level = 'warning';
        }

        return [
            'score' => $score,
            'level' => $level,
            'label' => $level === 'critical' ? 'Срочно' : ($level === 'warning' ? 'Приоритет' : 'Планово'),
            'reasons' => array_values(array_unique($reasons)),
            'distance_bucket' => $distanceBucket,
            'waiting_minutes' => $waitingMinutes,
            'overdue_risk' => $overdueRisk,
            'zone_key' => $zoneKey !== '' ? $zoneKey : 'unknown',
            'zone_pressure' => min(100, $zonePressure),
        ];
    }
}

if (!function_exists('delivery_dispatch_queue')) {
    /**
     * @param array<string,mixed> $options
     * @return list<array<string,mixed>>
     */
    function delivery_dispatch_queue(PDO $pdo, int $restaurantId, array $options = []): array
    {
        if ($restaurantId <= 0 || !function_exists('db_table_exists') || !db_table_exists('orders')) {
            return [];
        }

        $hasOrderType = function_exists('db_column_exists') && db_column_exists('orders', 'order_type');
        if (!$hasOrderType) {
            return [];
        }

        $hasCourierStatus = function_exists('db_column_exists') && db_column_exists('orders', 'courier_status');
        $hasCourierUserId = function_exists('db_column_exists') && db_column_exists('orders', 'courier_user_id');
        $hasCourierTakenAt = function_exists('db_column_exists') && db_column_exists('orders', 'courier_taken_at');
        $hasCourierOnTheWayAt = function_exists('db_column_exists') && db_column_exists('orders', 'courier_on_the_way_at');
        $hasDeliveredAt = function_exists('db_column_exists') && db_column_exists('orders', 'delivered_at');
        $hasCustomerName = function_exists('db_column_exists') && db_column_exists('orders', 'customer_name');
        $hasCustomerPhone = function_exists('db_column_exists') && db_column_exists('orders', 'customer_phone');
        $hasDeliveryName = function_exists('db_column_exists') && db_column_exists('orders', 'delivery_full_name');
        $hasDeliveryPhone = function_exists('db_column_exists') && db_column_exists('orders', 'delivery_phone');
        $hasDeliveryAddress = function_exists('db_column_exists') && db_column_exists('orders', 'delivery_address');
        $hasDeliveryZoneKey = function_exists('db_column_exists') && db_column_exists('orders', 'delivery_zone_key');
        $hasComment = function_exists('db_column_exists') && db_column_exists('orders', 'comment');
        $hasNotes = function_exists('db_column_exists') && db_column_exists('orders', 'notes');
        $hasTotalAmount = function_exists('db_column_exists') && db_column_exists('orders', 'total_amount');
        $hasTotalPrice = function_exists('db_column_exists') && db_column_exists('orders', 'total_price');

        $includeDelivered = !empty($options['include_delivered']);
        $scope = strtolower(trim((string)($options['scope'] ?? 'active')));
        $viewerUserId = (int)($options['viewer_user_id'] ?? 0);
        $viewerRole = strtolower(trim((string)($options['viewer_role'] ?? '')));
        $limit = max(20, min(400, (int)($options['limit'] ?? 150)));

        $cols = [
            'o.id',
            'o.restaurant_id',
            'o.order_status',
            'o.created_at',
            $hasOrderType ? 'o.order_type' : "NULL AS order_type",
            $hasCourierStatus ? 'o.courier_status' : "NULL AS courier_status",
            $hasCourierUserId ? 'o.courier_user_id' : "NULL AS courier_user_id",
            $hasCourierTakenAt ? 'o.courier_taken_at' : "NULL AS courier_taken_at",
            $hasCourierOnTheWayAt ? 'o.courier_on_the_way_at' : "NULL AS courier_on_the_way_at",
            $hasDeliveredAt ? 'o.delivered_at' : "NULL AS delivered_at",
            $hasCustomerName ? 'o.customer_name' : "NULL AS customer_name",
            $hasCustomerPhone ? 'o.customer_phone' : "NULL AS customer_phone",
            $hasDeliveryName ? 'o.delivery_full_name' : "NULL AS delivery_full_name",
            $hasDeliveryPhone ? 'o.delivery_phone' : "NULL AS delivery_phone",
            $hasDeliveryAddress ? 'o.delivery_address' : "NULL AS delivery_address",
            $hasDeliveryZoneKey ? 'o.delivery_zone_key' : "NULL AS delivery_zone_key",
            $hasComment ? 'o.comment' : ($hasNotes ? 'o.notes AS comment' : "NULL AS comment"),
            $hasTotalAmount ? 'o.total_amount' : ($hasTotalPrice ? 'o.total_price AS total_amount' : "NULL AS total_amount"),
            $hasTotalPrice ? 'o.total_price' : ($hasTotalAmount ? 'o.total_amount AS total_price' : "NULL AS total_price"),
            'u.name AS courier_user_name',
        ];

        $where = [
            'o.restaurant_id = :rest',
            "LOWER(TRIM(COALESCE(o.order_type, ''))) = 'delivery'",
            "LOWER(COALESCE(o.order_status, 'new')) NOT IN ('canceled', 'cancelled')",
        ];
        if (!$includeDelivered) {
            $where[] = "LOWER(COALESCE(o.order_status, 'new')) NOT IN ('delivered', 'completed')";
        }
        if ($scope === 'mine' && $viewerUserId > 0) {
            if ($viewerRole === 'courier') {
                $where[] = '(o.courier_user_id = :uid OR (o.courier_user_id IS NULL AND LOWER(COALESCE(o.courier_status, \'\')) IN (\'\', \'waiting_courier\')))';
            } else {
                $where[] = 'o.courier_user_id = :uid';
            }
        }

        $sql = "
            SELECT " . implode(",\n                ", $cols) . "
            FROM orders o
            LEFT JOIN users u ON u.id = o.courier_user_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY o.created_at ASC
            LIMIT {$limit}
        ";
        $params = [':rest' => $restaurantId];
        if ($scope === 'mine' && $viewerUserId > 0) {
            $params[':uid'] = $viewerUserId;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($rows === []) {
            return [];
        }

        $zones = function_exists('delivery_zone_list') ? delivery_zone_list($pdo, $restaurantId) : delivery_zone_defaults();
        $defaults = delivery_zone_defaults();
        $zoneUnknown = is_array($zones['unknown'] ?? null) ? $zones['unknown'] : $defaults['unknown'];

        $guestOrdersByPhone = [];
        if (function_exists('db_table_exists') && db_table_exists('guest_profiles')) {
            $phones = [];
            foreach ($rows as $row) {
                $candidatePhone = trim((string)($row['customer_phone'] ?? ''));
                if ($candidatePhone === '') {
                    $candidatePhone = trim((string)($row['delivery_phone'] ?? ''));
                }
                $phoneNormalized = function_exists('guest_normalize_phone') ? guest_normalize_phone($candidatePhone) : null;
                if ($phoneNormalized) {
                    $phones[$phoneNormalized] = true;
                }
            }
            $phones = array_keys($phones);
            if ($phones !== []) {
                $ph = implode(',', array_fill(0, count($phones), '?'));
                $stmtGuest = $pdo->prepare("
                    SELECT phone_normalized, MAX(orders_count) AS orders_count
                    FROM guest_profiles
                    WHERE restaurant_id = ?
                      AND phone_normalized IN ({$ph})
                    GROUP BY phone_normalized
                ");
                $stmtGuest->execute(array_merge([$restaurantId], $phones));
                foreach (($stmtGuest->fetchAll(PDO::FETCH_ASSOC) ?: []) as $guestRow) {
                    $pn = trim((string)($guestRow['phone_normalized'] ?? ''));
                    if ($pn !== '') {
                        $guestOrdersByPhone[$pn] = (int)($guestRow['orders_count'] ?? 0);
                    }
                }
            }
        }

        $result = [];
        foreach ($rows as $row) {
            $order = $row;
            $candidatePhone = trim((string)($row['customer_phone'] ?? ''));
            if ($candidatePhone === '') {
                $candidatePhone = trim((string)($row['delivery_phone'] ?? ''));
            }
            $phoneNormalized = function_exists('guest_normalize_phone') ? guest_normalize_phone($candidatePhone) : null;
            $order['guest_orders_count'] = $phoneNormalized !== null ? (int)($guestOrdersByPhone[$phoneNormalized] ?? 0) : 0;
            $order['dispatch_state'] = delivery_dispatch_state_from_order($row);
            $order['dispatch_state_label'] = delivery_dispatch_status_label((string)$order['dispatch_state']);
            $zoneMeta = function_exists('delivery_zone_resolve')
                ? delivery_zone_resolve($order, $zones)
                : ($zoneUnknown + ['match_score' => 0, 'match_reason' => 'fallback_unknown', 'manual_override' => false]);
            $order['zone_meta'] = $zoneMeta;
            $order['delivery_zone_key_resolved'] = (string)($zoneMeta['zone_key'] ?? 'unknown');
            $order['delivery_zone_label'] = (string)($zoneMeta['zone_name'] ?? 'Не определена');
            $order['delivery_zone_color'] = (string)($zoneMeta['color'] ?? '#94A3B8');
            $order['delivery_zone_match_reason'] = (string)($zoneMeta['match_reason'] ?? '');
            $order['delivery_zone_match_score'] = (int)($zoneMeta['match_score'] ?? 0);
            $order['priority_meta'] = delivery_dispatch_order_priority($order, $options);
            $order['timing_meta'] = courier_delivery_timing([
                'order_type' => 'delivery',
                'order_status' => (string)($row['order_status'] ?? 'new'),
                'courier_status' => (string)($row['courier_status'] ?? ''),
                'created_at' => $row['created_at'] ?? null,
                'courier_taken_at' => $row['courier_taken_at'] ?? null,
                'courier_on_the_way_at' => $row['courier_on_the_way_at'] ?? null,
                'delivered_at' => $row['delivered_at'] ?? null,
            ], $options);
            $order['sla_meta'] = courier_order_sla_meta([
                'created_at' => $row['created_at'] ?? null,
                'courier_taken_at' => $row['courier_taken_at'] ?? null,
            ]);
            $order['delivery_bucket'] = delivery_dispatch_address_bucket((string)($row['delivery_address'] ?? ''));
            $order['prepared_ready'] = strtolower(trim((string)($row['order_status'] ?? ''))) === 'ready';
            $batchScore = 0;
            if (in_array((string)$order['dispatch_state'], ['waiting_dispatch', 'assigned', 'courier_arriving'], true)) {
                $batchScore += 25;
                if ($order['prepared_ready']) {
                    $batchScore += 25;
                }
                $batchScore += max(0, min(25, (int)floor(((int)($order['priority_meta']['waiting_minutes'] ?? 0)) / 2)));
                if ((string)$order['delivery_zone_key_resolved'] !== 'unknown') {
                    $batchScore += 15;
                }
                if ((int)($order['priority_meta']['zone_pressure'] ?? 0) >= 10) {
                    $batchScore += 10;
                }
            }
            $order['batch_readiness_score'] = max(0, min(100, $batchScore));
            $order['batch_ready'] = $order['batch_readiness_score'] >= 60;

            $loc = courier_location_latest(
                $pdo,
                $restaurantId,
                (int)($row['id'] ?? 0),
                (int)($row['courier_user_id'] ?? 0) > 0 ? (int)$row['courier_user_id'] : null
            );
            $order['location_meta'] = courier_location_public_payload($loc, ['live_sec' => 20, 'stale_sec' => 90]);

            $result[] = $order;
        }

        usort($result, static function (array $a, array $b): int {
            $as = (int)($a['priority_meta']['score'] ?? 0);
            $bs = (int)($b['priority_meta']['score'] ?? 0);
            if ($as !== $bs) {
                return $bs <=> $as;
            }
            $ac = strtotime((string)($a['created_at'] ?? '')) ?: 0;
            $bc = strtotime((string)($b['created_at'] ?? '')) ?: 0;
            return $ac <=> $bc;
        });

        return $result;
    }
}

if (!function_exists('delivery_dispatch_courier_load')) {
    /**
     * @param array<string,mixed> $options
     * @return list<array<string,mixed>>
     */
    function delivery_dispatch_courier_load(PDO $pdo, int $restaurantId, array $options = []): array
    {
        if ($restaurantId <= 0 || !function_exists('db_table_exists') || !db_table_exists('users_restaurants')) {
            return [];
        }

        $activeCond = (function_exists('db_column_exists') && db_column_exists('users_restaurants', 'is_active'))
            ? ' AND COALESCE(ur.is_active,1)=1'
            : '';

        $sql = "
            SELECT ur.user_id, LOWER(TRIM(COALESCE(ur.restaurant_role,''))) AS restaurant_role, u.name
            FROM users_restaurants ur
            LEFT JOIN users u ON u.id = ur.user_id
            WHERE ur.restaurant_id = :rest
              {$activeCond}
              AND LOWER(TRIM(COALESCE(ur.restaurant_role,''))) IN ('courier','staff','admin','owner')
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':rest' => $restaurantId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($rows === []) {
            return [];
        }

        $courierIds = array_values(array_unique(array_map(static fn(array $r): int => (int)($r['user_id'] ?? 0), $rows)));
        $metricsByUser = [];
        if (function_exists('db_table_exists') && db_table_exists('orders') && $courierIds !== []) {
            $ph = implode(',', array_fill(0, count($courierIds), '?'));
            $statusExpr = (function_exists('db_column_exists') && db_column_exists('orders', 'courier_status'))
                ? "LOWER(COALESCE(courier_status,''))"
                : "''";
            $sqlMetrics = "
                SELECT
                    courier_user_id,
                    SUM(CASE WHEN {$statusExpr} IN ('waiting_courier','handed_to_courier','on_the_way') THEN 1 ELSE 0 END) AS active_deliveries,
                    SUM(CASE WHEN {$statusExpr} = 'delivered' AND DATE(COALESCE(delivered_at, updated_at, created_at)) = CURRENT_DATE THEN 1 ELSE 0 END) AS completed_today,
                    AVG(CASE WHEN {$statusExpr} = 'delivered' AND DATE(COALESCE(delivered_at, updated_at, created_at)) = CURRENT_DATE THEN TIMESTAMPDIFF(MINUTE, COALESCE(courier_taken_at, created_at), COALESCE(delivered_at, updated_at, created_at)) END) AS avg_delivery_minutes,
                    SUM(CASE WHEN {$statusExpr} IN ('waiting_courier','handed_to_courier','on_the_way') AND TIMESTAMPDIFF(MINUTE, COALESCE(courier_taken_at, created_at), NOW()) > 35 THEN 1 ELSE 0 END) AS sla_breaches,
                    MAX(COALESCE(courier_taken_at, created_at)) AS last_activity_at
                FROM orders
                WHERE restaurant_id = ?
                  AND LOWER(TRIM(COALESCE(order_type,''))) = 'delivery'
                  AND courier_user_id IN ({$ph})
                GROUP BY courier_user_id
            ";
            $stmtM = $pdo->prepare($sqlMetrics);
            $stmtM->execute(array_merge([$restaurantId], $courierIds));
            foreach (($stmtM->fetchAll(PDO::FETCH_ASSOC) ?: []) as $metric) {
                $uid = (int)($metric['courier_user_id'] ?? 0);
                if ($uid > 0) {
                    $metricsByUser[$uid] = $metric;
                }
            }
        }

        $locationByUser = [];
        if (function_exists('db_table_exists') && db_table_exists('courier_locations') && $courierIds !== []) {
            $ph = implode(',', array_fill(0, count($courierIds), '?'));
            $sqlLoc = "
                SELECT courier_user_id, MAX(updated_at) AS last_location_at
                FROM courier_locations
                WHERE restaurant_id = ?
                  AND courier_user_id IN ({$ph})
                GROUP BY courier_user_id
            ";
            $stmtLoc = $pdo->prepare($sqlLoc);
            $stmtLoc->execute(array_merge([$restaurantId], $courierIds));
            foreach (($stmtLoc->fetchAll(PDO::FETCH_ASSOC) ?: []) as $locRow) {
                $uid = (int)($locRow['courier_user_id'] ?? 0);
                if ($uid > 0) {
                    $locationByUser[$uid] = courier_location_is_fresh((string)($locRow['last_location_at'] ?? ''), 20, 90);
                }
            }
        }

        $shiftByUser = [];
        if (function_exists('db_table_exists') && db_table_exists('courier_shifts') && $courierIds !== []) {
            $ph = implode(',', array_fill(0, count($courierIds), '?'));
            $sqlShift = "
                SELECT *
                FROM courier_shifts
                WHERE restaurant_id = ?
                  AND active = 1
                  AND courier_user_id IN ({$ph})
                ORDER BY started_at DESC, id DESC
            ";
            $stmtShift = $pdo->prepare($sqlShift);
            $stmtShift->execute(array_merge([$restaurantId], $courierIds));
            foreach (($stmtShift->fetchAll(PDO::FETCH_ASSOC) ?: []) as $shiftRow) {
                $uid = (int)($shiftRow['courier_user_id'] ?? 0);
                if ($uid <= 0 || isset($shiftByUser[$uid])) {
                    continue;
                }
                $shiftByUser[$uid] = $shiftRow;
            }
        }

        $overloadThreshold = max(2, (int)($options['overload_threshold'] ?? 4));
        $result = [];
        foreach ($rows as $row) {
            $uid = (int)($row['user_id'] ?? 0);
            if ($uid <= 0) {
                continue;
            }
            $metric = $metricsByUser[$uid] ?? [];
            $active = (int)($metric['active_deliveries'] ?? 0);
            $lastActivityRaw = trim((string)($metric['last_activity_at'] ?? ''));
            $lastActivityTs = $lastActivityRaw !== '' ? (strtotime($lastActivityRaw) ?: 0) : 0;
            $idleMinutes = $lastActivityTs > 0 ? max(0, (int)floor((time() - $lastActivityTs) / 60)) : null;
            $loc = $locationByUser[$uid] ?? ['state' => 'offline', 'label' => 'Позиция недоступна', 'age_seconds' => null, 'is_fresh' => false];
            $shift = $shiftByUser[$uid] ?? null;
            $shiftStartedRaw = is_array($shift) ? trim((string)($shift['started_at'] ?? '')) : '';
            $shiftStartedTs = $shiftStartedRaw !== '' ? (strtotime($shiftStartedRaw) ?: 0) : 0;
            $shiftOnlineMinutes = $shiftStartedTs > 0 ? max(0, (int)floor((time() - $shiftStartedTs) / 60)) : 0;
            if (is_array($shift) && isset($shift['total_online_minutes'])) {
                $shiftOnlineMinutes = max($shiftOnlineMinutes, (int)$shift['total_online_minutes']);
            }
            $shiftStatusMeta = function_exists('courier_shift_status')
                ? courier_shift_status($shift, $active, (string)($loc['state'] ?? 'offline'), $overloadThreshold)
                : [
                    'key' => $active >= $overloadThreshold ? 'overloaded' : ($active > 0 ? 'busy' : 'available'),
                    'label' => $active >= $overloadThreshold ? 'Перегружен' : ($active > 0 ? 'Занят' : 'Доступен'),
                    'is_available' => true,
                    'is_active_shift' => true,
                    'is_paused' => false,
                    'is_overloaded' => $active >= $overloadThreshold,
                    'reason' => 'fallback',
                ];
            $isAvailable = !empty($shiftStatusMeta['is_available']);
            $shiftKey = (string)($shiftStatusMeta['key'] ?? 'offline');
            $earningsShift = function_exists('courier_earnings_shift')
                ? courier_earnings_shift(
                    $pdo,
                    $restaurantId,
                    $uid,
                    is_array($shift) ? (int)($shift['id'] ?? 0) : 0,
                    $options
                )
                : [
                    'total_amount' => 0.0,
                    'bonus_earned' => 0.0,
                    'tips_earned' => 0.0,
                    'earnings_per_hour' => 0.0,
                    'payout_ready_amount' => 0.0,
                    'payout_hold_amount' => 0.0,
                ];

            $result[] = [
                'user_id' => $uid,
                'name' => trim((string)($row['name'] ?? '')) !== '' ? (string)$row['name'] : ('User #' . $uid),
                'restaurant_role' => strtolower(trim((string)($row['restaurant_role'] ?? 'courier'))),
                'active_deliveries' => $active,
                'completed_today' => (int)($metric['completed_today'] ?? 0),
                'avg_delivery_minutes' => isset($metric['avg_delivery_minutes']) && $metric['avg_delivery_minutes'] !== null ? (int)round((float)$metric['avg_delivery_minutes']) : null,
                'sla_breaches' => (int)($metric['sla_breaches'] ?? 0),
                'last_activity_at' => $lastActivityRaw !== '' ? $lastActivityRaw : null,
                'idle_minutes' => $idleMinutes,
                'location_state' => (string)($loc['state'] ?? 'offline'),
                'location_freshness' => $loc,
                'is_overloaded' => $active >= $overloadThreshold || !empty($shiftStatusMeta['is_overloaded']),
                'shift_active' => is_array($shift),
                'shift_status' => $shiftKey,
                'shift_status_label' => (string)($shiftStatusMeta['label'] ?? 'Оффлайн'),
                'shift_reason' => (string)($shiftStatusMeta['reason'] ?? ''),
                'shift_started_at' => $shiftStartedRaw !== '' ? $shiftStartedRaw : null,
                'shift_online_minutes' => $shiftOnlineMinutes,
                'shift_online_hours' => round($shiftOnlineMinutes / 60, 2),
                'is_available' => $isAvailable,
                'earnings_today' => round((float)($earningsShift['total_amount'] ?? 0), 2),
                'bonus_today' => round((float)($earningsShift['bonus_earned'] ?? 0), 2),
                'tips_today' => round((float)($earningsShift['tips_earned'] ?? 0), 2),
                'earnings_per_hour' => round((float)($earningsShift['earnings_per_hour'] ?? 0), 2),
                'payout_ready_amount' => round((float)($earningsShift['payout_ready_amount'] ?? 0), 2),
                'payout_hold_amount' => round((float)($earningsShift['payout_hold_amount'] ?? 0), 2),
            ];
        }

        usort($result, static function (array $a, array $b): int {
            $ac = (int)($a['active_deliveries'] ?? 0);
            $bc = (int)($b['active_deliveries'] ?? 0);
            if ($ac !== $bc) {
                return $bc <=> $ac;
            }
            return strcmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
        });

        return $result;
    }
}

if (!function_exists('delivery_dispatch_batch_candidates')) {
    /**
     * @param list<array<string,mixed>> $queue
     * @param array<string,mixed> $options
     * @return array<int,list<array{
     *   order_id:int,
     *   address_bucket:string,
     *   zone_key:string,
     *   same_zone:bool,
     *   window_overlap:bool,
     *   courier_compatible:bool,
     *   minutes_diff:int,
     *   batch_score:int
     * }>>
     */
    function delivery_dispatch_batch_candidates(array $queue, array $options = []): array
    {
        $windowMinutes = max(10, min(90, (int)($options['window_minutes'] ?? 30)));
        $maxCandidates = max(1, min(6, (int)($options['max_candidates'] ?? 3)));
        $maxBatchSize = max(2, min(8, (int)($options['max_batch_size'] ?? 3)));
        $result = [];

        foreach ($queue as $order) {
            $orderId = (int)($order['id'] ?? 0);
            if ($orderId <= 0) {
                continue;
            }
            $state = delivery_dispatch_status_normalize((string)($order['dispatch_state'] ?? 'waiting_dispatch'));
            if (!in_array($state, ['waiting_dispatch', 'assigned', 'courier_arriving'], true)) {
                continue;
            }
            $bucket = (string)($order['delivery_bucket'] ?? 'unknown');
            $zoneKey = strtolower(trim((string)($order['delivery_zone_key_resolved'] ?? 'unknown')));
            $createdTs = strtotime((string)($order['created_at'] ?? '')) ?: 0;
            $baseCourierId = (int)($order['courier_user_id'] ?? 0);
            $pool = [];
            foreach ($queue as $candidate) {
                $candidateId = (int)($candidate['id'] ?? 0);
                if ($candidateId <= 0 || $candidateId === $orderId) {
                    continue;
                }
                $candidateState = delivery_dispatch_status_normalize((string)($candidate['dispatch_state'] ?? 'waiting_dispatch'));
                if (!in_array($candidateState, ['waiting_dispatch', 'assigned', 'courier_arriving'], true)) {
                    continue;
                }
                $candidateBucket = (string)($candidate['delivery_bucket'] ?? 'unknown');
                $candidateZoneKey = strtolower(trim((string)($candidate['delivery_zone_key_resolved'] ?? 'unknown')));
                if ($bucket !== 'unknown' && $candidateBucket !== $bucket) {
                    continue;
                }
                if ($zoneKey !== '' && $zoneKey !== 'unknown' && $candidateZoneKey !== $zoneKey) {
                    continue;
                }
                $candidateTs = strtotime((string)($candidate['created_at'] ?? '')) ?: 0;
                $minutesDiff = abs((int)floor(($candidateTs - $createdTs) / 60));
                if ($minutesDiff > $windowMinutes) {
                    continue;
                }
                $candidateCourierId = (int)($candidate['courier_user_id'] ?? 0);
                $courierCompatible = ($baseCourierId <= 0 || $candidateCourierId <= 0 || $baseCourierId === $candidateCourierId);
                if (!$courierCompatible) {
                    continue;
                }
                $score = 100;
                $score -= min(45, $minutesDiff * 2);
                if ($zoneKey !== 'unknown' && $candidateZoneKey === $zoneKey) {
                    $score += 20;
                }
                if ((bool)($candidate['prepared_ready'] ?? false)) {
                    $score += 8;
                }
                if ((bool)($order['prepared_ready'] ?? false)) {
                    $score += 6;
                }
                $pool[] = [
                    'order_id' => $candidateId,
                    'address_bucket' => $candidateBucket,
                    'zone_key' => $candidateZoneKey !== '' ? $candidateZoneKey : 'unknown',
                    'same_zone' => ($candidateZoneKey === $zoneKey),
                    'window_overlap' => true,
                    'courier_compatible' => $courierCompatible,
                    'minutes_diff' => $minutesDiff,
                    'batch_score' => max(0, min(100, $score)),
                ];
            }
            usort($pool, static function (array $a, array $b): int {
                $as = (int)($a['batch_score'] ?? 0);
                $bs = (int)($b['batch_score'] ?? 0);
                if ($as !== $bs) {
                    return $bs <=> $as;
                }
                return (int)($a['minutes_diff'] ?? 0) <=> (int)($b['minutes_diff'] ?? 0);
            });
            $result[$orderId] = array_slice($pool, 0, min($maxCandidates, $maxBatchSize - 1));
        }

        return $result;
    }
}

if (!function_exists('delivery_zone_workload')) {
    /**
     * @param list<array<string,mixed>> $zoneOrders
     * @return array{
     *   total:int,
     *   waiting_dispatch:int,
     *   assigned:int,
     *   courier_arriving:int,
     *   picked_up:int,
     *   on_the_way:int,
     *   delivered:int,
     *   active:int,
     *   prepared_ready:int,
     *   batching_ready:int,
     *   active_couriers:int,
     *   queue_pressure:int,
     *   avg_wait_minutes:int,
     *   total_amount:float
     * }
     */
    function delivery_zone_workload(array $zoneOrders): array
    {
        $workload = [
            'total' => 0,
            'waiting_dispatch' => 0,
            'assigned' => 0,
            'courier_arriving' => 0,
            'picked_up' => 0,
            'on_the_way' => 0,
            'delivered' => 0,
            'active' => 0,
            'prepared_ready' => 0,
            'batching_ready' => 0,
            'active_couriers' => 0,
            'queue_pressure' => 0,
            'avg_wait_minutes' => 0,
            'total_amount' => 0.0,
        ];
        $waitSum = 0;
        $waitCnt = 0;
        $couriers = [];
        foreach ($zoneOrders as $order) {
            $workload['total']++;
            $state = delivery_dispatch_status_normalize((string)($order['dispatch_state'] ?? 'waiting_dispatch'));
            if (isset($workload[$state])) {
                $workload[$state]++;
            }
            $isActive = in_array($state, ['waiting_dispatch', 'assigned', 'courier_arriving', 'picked_up', 'on_the_way'], true);
            if ($isActive) {
                $workload['active']++;
            }
            if (!empty($order['prepared_ready'])) {
                $workload['prepared_ready']++;
            }
            if ((int)($order['batch_readiness_score'] ?? 0) >= 60) {
                $workload['batching_ready']++;
            }
            $cid = (int)($order['courier_user_id'] ?? 0);
            if ($cid > 0 && $isActive) {
                $couriers[$cid] = true;
            }
            $wait = (int)($order['priority_meta']['waiting_minutes'] ?? 0);
            if ($wait > 0) {
                $waitSum += $wait;
                $waitCnt++;
            }
            $amount = (float)($order['total_amount'] ?? ($order['total_price'] ?? 0));
            $workload['total_amount'] += max(0.0, $amount);
        }

        $workload['active_couriers'] = count($couriers);
        $workload['avg_wait_minutes'] = $waitCnt > 0 ? (int)round($waitSum / $waitCnt) : 0;
        $workload['queue_pressure'] = min(
            100,
            ($workload['waiting_dispatch'] * 14)
            + ($workload['on_the_way'] * 6)
            + (max(0, $workload['active'] - 5) * 4)
            + ($workload['prepared_ready'] * 2)
        );
        $workload['total_amount'] = round($workload['total_amount'], 2);

        return $workload;
    }
}

if (!function_exists('delivery_zone_sla')) {
    /**
     * @param list<array<string,mixed>> $zoneOrders
     * @param array<string,mixed> $options
     * @return array{avg_eta_minutes:?int,overdue_count:int,sla_percent:float,throughput:int,avg_wait_minutes:int}
     */
    function delivery_zone_sla(array $zoneOrders, array $options = []): array
    {
        $etaTarget = max(10, (int)($options['avg_eta_minutes'] ?? 35));
        $waitThreshold = max(12, (int)($options['waiting_sla_minutes'] ?? 20));
        $etaSum = 0;
        $etaCnt = 0;
        $overdue = 0;
        $throughput = 0;
        $waitSum = 0;
        $waitCnt = 0;

        foreach ($zoneOrders as $order) {
            $eta = (int)($order['timing_meta']['eta_minutes'] ?? 0);
            if ($eta > 0) {
                $etaSum += $eta;
                $etaCnt++;
            }
            $state = delivery_dispatch_status_normalize((string)($order['dispatch_state'] ?? 'waiting_dispatch'));
            if ($state === 'delivered') {
                $throughput++;
            }
            $wait = (int)($order['priority_meta']['waiting_minutes'] ?? 0);
            if ($wait > 0) {
                $waitSum += $wait;
                $waitCnt++;
            }
            $critical = ((string)($order['sla_meta']['level'] ?? 'normal') === 'critical');
            if ($critical || $wait > $waitThreshold || ($eta > $etaTarget && $state !== 'delivered')) {
                $overdue++;
            }
        }

        $total = count($zoneOrders);
        $avgEta = $etaCnt > 0 ? (int)round($etaSum / $etaCnt) : null;
        $avgWait = $waitCnt > 0 ? (int)round($waitSum / $waitCnt) : 0;
        $slaPercent = $total > 0 ? round(max(0, 100 - (($overdue / $total) * 100)), 1) : 100.0;

        return [
            'avg_eta_minutes' => $avgEta,
            'overdue_count' => $overdue,
            'sla_percent' => $slaPercent,
            'throughput' => $throughput,
            'avg_wait_minutes' => $avgWait,
        ];
    }
}

if (!function_exists('delivery_zone_summary')) {
    /**
     * @param list<array<string,mixed>> $queue
     * @param array<string,array{zone_key:string,zone_name:string,active:int,priority:int,avg_eta_minutes:int,delivery_fee:float,free_delivery_from:?float,color:string,match_keywords:string}> $zones
     * @param array<string,mixed> $options
     * @return array{zones:list<array<string,mixed>>,alerts:list<array{level:string,label:string,message:string}>,totals:array<string,int|float>}
     */
    function delivery_zone_summary(array $queue, array $zones = [], array $options = []): array
    {
        $defaults = delivery_zone_defaults();
        if ($zones === []) {
            $zones = $defaults;
        }

        $ordersByZone = [];
        foreach ($queue as $order) {
            $zoneMeta = is_array($order['zone_meta'] ?? null) ? (array)$order['zone_meta'] : [];
            $zoneKey = strtolower(trim((string)($order['delivery_zone_key_resolved'] ?? ($zoneMeta['zone_key'] ?? 'unknown'))));
            if ($zoneKey === '' || !isset($zones[$zoneKey])) {
                $zoneKey = 'unknown';
            }
            $ordersByZone[$zoneKey][] = $order;
        }

        $zoneRows = [];
        $alerts = [];
        $totals = [
            'zones_total' => 0,
            'zones_overloaded' => 0,
            'zones_hotspot' => 0,
            'zones_batching_opportunity' => 0,
        ];
        foreach ($ordersByZone as $zoneKey => $zoneOrders) {
            $zone = $zones[$zoneKey] ?? ($defaults[$zoneKey] ?? $defaults['unknown']);
            $workload = delivery_zone_workload($zoneOrders);
            $sla = delivery_zone_sla($zoneOrders, [
                'avg_eta_minutes' => (int)($zone['avg_eta_minutes'] ?? 35),
                'waiting_sla_minutes' => (int)($options['waiting_sla_minutes'] ?? 20),
            ]);

            $isOverloaded = $workload['queue_pressure'] >= 70;
            $isHotspot = $workload['active'] >= 4;
            $hasBatchingOpportunity = $workload['batching_ready'] >= 2 && $workload['waiting_dispatch'] >= 2;

            if ($isOverloaded) {
                $totals['zones_overloaded']++;
                $alerts[] = [
                    'level' => 'warning',
                    'label' => 'Zone overloaded',
                    'message' => 'Зона "' . (string)($zone['zone_name'] ?? $zoneKey) . '" перегружена (' . $workload['queue_pressure'] . '%).',
                ];
            }
            if ($isHotspot) {
                $totals['zones_hotspot']++;
                $alerts[] = [
                    'level' => 'warning',
                    'label' => 'Delivery hotspot',
                    'message' => 'Зона "' . (string)($zone['zone_name'] ?? $zoneKey) . '" имеет высокий активный поток.',
                ];
            }
            if ($hasBatchingOpportunity) {
                $totals['zones_batching_opportunity']++;
                $alerts[] = [
                    'level' => 'normal',
                    'label' => 'Batching opportunity',
                    'message' => 'Зона "' . (string)($zone['zone_name'] ?? $zoneKey) . '" готова к batching.',
                ];
            }
            if ($workload['active'] > 0 && $workload['active_couriers'] <= 0) {
                $alerts[] = [
                    'level' => 'critical',
                    'label' => 'No courier in zone',
                    'message' => 'В зоне "' . (string)($zone['zone_name'] ?? $zoneKey) . '" нет активного курьера.',
                ];
            }
            if ((int)$sla['overdue_count'] > 0) {
                $alerts[] = [
                    'level' => 'warning',
                    'label' => 'Overdue zone SLA',
                    'message' => 'В зоне "' . (string)($zone['zone_name'] ?? $zoneKey) . '" SLA-overdue: ' . (int)$sla['overdue_count'],
                ];
            }

            $zoneRows[] = [
                'zone_key' => (string)($zone['zone_key'] ?? $zoneKey),
                'zone_name' => (string)($zone['zone_name'] ?? $zoneKey),
                'color' => (string)($zone['color'] ?? '#94A3B8'),
                'active' => (int)($zone['active'] ?? 1),
                'priority' => (int)($zone['priority'] ?? 100),
                'avg_eta_minutes_target' => (int)($zone['avg_eta_minutes'] ?? 35),
                'delivery_fee' => (float)($zone['delivery_fee'] ?? 0),
                'free_delivery_from' => $zone['free_delivery_from'] ?? null,
                'workload' => $workload,
                'sla' => $sla,
                'is_overloaded' => $isOverloaded,
                'is_hotspot' => $isHotspot,
                'has_batching_opportunity' => $hasBatchingOpportunity,
            ];
        }

        usort($zoneRows, static function (array $a, array $b): int {
            $ap = (int)($a['workload']['queue_pressure'] ?? 0);
            $bp = (int)($b['workload']['queue_pressure'] ?? 0);
            if ($ap !== $bp) {
                return $bp <=> $ap;
            }
            $aa = (int)($a['workload']['active'] ?? 0);
            $ba = (int)($b['workload']['active'] ?? 0);
            if ($aa !== $ba) {
                return $ba <=> $aa;
            }
            return strcmp((string)($a['zone_name'] ?? ''), (string)($b['zone_name'] ?? ''));
        });

        $totals['zones_total'] = count($zoneRows);

        return [
            'zones' => $zoneRows,
            'alerts' => $alerts,
            'totals' => $totals,
        ];
    }
}

if (!function_exists('delivery_dispatch_summary')) {
    /**
     * @param list<array<string,mixed>> $queue
     * @param list<array<string,mixed>> $courierLoad
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    function delivery_dispatch_summary(array $queue, array $courierLoad = [], array $options = []): array
    {
        $summary = [
            'total' => 0,
            'waiting_dispatch' => 0,
            'assigned' => 0,
            'courier_arriving' => 0,
            'picked_up' => 0,
            'on_the_way' => 0,
            'delivered' => 0,
            'failed' => 0,
            'cancelled' => 0,
            'avg_dispatch_minutes' => null,
            'avg_courier_load' => 0.0,
            'dispatch_sla_percent' => 100.0,
            'assignment_efficiency_percent' => 0.0,
            'delivery_throughput' => 0,
            'queue_pressure' => 0,
            'zone_summary' => [],
            'zone_alerts' => [],
            'zones_overloaded' => 0,
            'zones_hotspot' => 0,
            'zones_batching_opportunity' => 0,
            'alerts' => [],
        ];

        $dispatchMinutes = [];
        $overdueWaiting = 0;
        foreach ($queue as $order) {
            $state = delivery_dispatch_status_normalize((string)($order['dispatch_state'] ?? 'waiting_dispatch'));
            $summary['total']++;
            if (isset($summary[$state])) {
                $summary[$state]++;
            }
            $createdTs = strtotime((string)($order['created_at'] ?? '')) ?: 0;
            $takenTs = strtotime((string)($order['courier_taken_at'] ?? '')) ?: 0;
            if ($createdTs > 0 && $takenTs > 0 && $takenTs >= $createdTs) {
                $dispatchMinutes[] = max(0, (int)floor(($takenTs - $createdTs) / 60));
            }
            $waitMin = (int)($order['priority_meta']['waiting_minutes'] ?? 0);
            if ($state === 'waiting_dispatch' && $waitMin > 15) {
                $overdueWaiting++;
            }
        }

        if ($dispatchMinutes !== []) {
            $summary['avg_dispatch_minutes'] = (int)round(array_sum($dispatchMinutes) / count($dispatchMinutes));
        }
        $active = $summary['waiting_dispatch'] + $summary['assigned'] + $summary['courier_arriving'] + $summary['picked_up'] + $summary['on_the_way'];
        $assignedActive = $summary['assigned'] + $summary['courier_arriving'] + $summary['picked_up'] + $summary['on_the_way'];
        $summary['assignment_efficiency_percent'] = $active > 0 ? round(($assignedActive / $active) * 100, 1) : 0.0;
        $summary['delivery_throughput'] = $summary['delivered'];
        $summary['queue_pressure'] = min(100, ($summary['waiting_dispatch'] * 8) + ($summary['on_the_way'] * 4) + ($overdueWaiting * 6));

        $avgLoad = 0.0;
        $overloaded = 0;
        $staleGps = 0;
        $activeCouriers = 0;
        $availableCouriers = 0;
        $offlineDuringActive = 0;
        foreach ($courierLoad as $courier) {
            $load = (int)($courier['active_deliveries'] ?? 0);
            $avgLoad += $load;
            if (!empty($courier['is_overloaded'])) {
                $overloaded++;
            }
            $state = strtolower(trim((string)($courier['location_state'] ?? 'offline')));
            if (in_array($state, ['stale', 'offline'], true)) {
                $staleGps++;
                if ($load > 0 && $state === 'offline') {
                    $offlineDuringActive++;
                }
            }
            if ($load > 0) {
                $activeCouriers++;
            }
            if (!empty($courier['is_available'])) {
                $availableCouriers++;
            }
        }
        if ($courierLoad !== []) {
            $summary['avg_courier_load'] = round($avgLoad / count($courierLoad), 2);
        }

        $slaBreach = 0;
        foreach ($queue as $order) {
            $level = (string)($order['priority_meta']['level'] ?? 'normal');
            if ($level === 'critical') {
                $slaBreach++;
            }
        }
        $summary['dispatch_sla_percent'] = $summary['total'] > 0
            ? round(max(0, 100 - (($slaBreach / $summary['total']) * 100)), 1)
            : 100.0;

        $alerts = [];
        if ($overdueWaiting > 0) {
            $alerts[] = ['level' => 'critical', 'label' => 'Overdue dispatch', 'message' => 'Ожидают назначения дольше SLA: ' . $overdueWaiting];
        }
        if ($overloaded > 0) {
            $alerts[] = ['level' => 'warning', 'label' => 'Courier overloaded', 'message' => 'Перегруженных курьеров: ' . $overloaded];
        }
        if ($summary['waiting_dispatch'] > 0 && $availableCouriers <= 0) {
            $alerts[] = ['level' => 'critical', 'label' => 'No available courier', 'message' => 'Есть очередь, но нет активных курьеров.'];
        }
        if ($offlineDuringActive > 0) {
            $alerts[] = ['level' => 'critical', 'label' => 'Courier offline during active delivery', 'message' => 'Оффлайн-курьеров с активными доставками: ' . $offlineDuringActive];
        }
        if ($staleGps > 0) {
            $alerts[] = ['level' => 'warning', 'label' => 'Stale courier GPS', 'message' => 'Курьеров с stale/offline GPS: ' . $staleGps];
        }
        if ($summary['queue_pressure'] >= 70) {
            $alerts[] = ['level' => 'warning', 'label' => 'Delivery queue pressure', 'message' => 'Нагрузка dispatch очереди: ' . $summary['queue_pressure'] . '%.'];
        }

        $zoneSummaryPayload = function_exists('delivery_zone_summary')
            ? delivery_zone_summary($queue, is_array($options['delivery_zones'] ?? null) ? $options['delivery_zones'] : [], $options)
            : ['zones' => [], 'alerts' => [], 'totals' => []];
        $summary['zone_summary'] = is_array($zoneSummaryPayload['zones'] ?? null) ? $zoneSummaryPayload['zones'] : [];
        $summary['zone_alerts'] = is_array($zoneSummaryPayload['alerts'] ?? null) ? $zoneSummaryPayload['alerts'] : [];
        $zoneTotals = is_array($zoneSummaryPayload['totals'] ?? null) ? $zoneSummaryPayload['totals'] : [];
        $summary['zones_overloaded'] = (int)($zoneTotals['zones_overloaded'] ?? 0);
        $summary['zones_hotspot'] = (int)($zoneTotals['zones_hotspot'] ?? 0);
        $summary['zones_batching_opportunity'] = (int)($zoneTotals['zones_batching_opportunity'] ?? 0);
        if ($summary['zone_alerts'] !== []) {
            foreach ($summary['zone_alerts'] as $zoneAlert) {
                if (!is_array($zoneAlert)) {
                    continue;
                }
                $alerts[] = [
                    'level' => strtolower(trim((string)($zoneAlert['level'] ?? 'warning'))),
                    'label' => (string)($zoneAlert['label'] ?? 'Zone'),
                    'message' => (string)($zoneAlert['message'] ?? ''),
                ];
            }
        }
        $summary['alerts'] = $alerts;

        return $summary;
    }
}

if (!function_exists('delivery_dispatch_assign')) {
    /**
     * @param array<string,mixed> $options
     * @return array{ok:bool,error?:string,status?:string,courier_user_id?:int}
     */
    function delivery_dispatch_assign(PDO $pdo, int $restaurantId, int $orderId, int $courierUserId, int $actorUserId = 0, array $options = []): array
    {
        if ($restaurantId <= 0 || $orderId <= 0 || $courierUserId <= 0) {
            return ['ok' => false, 'error' => 'invalid_params'];
        }
        $hasOrderType = function_exists('db_column_exists') && db_column_exists('orders', 'order_type');
        $hasCourierStatus = function_exists('db_column_exists') && db_column_exists('orders', 'courier_status');
        $hasCourierUserId = function_exists('db_column_exists') && db_column_exists('orders', 'courier_user_id');
        if (!$hasOrderType || !$hasCourierStatus || !$hasCourierUserId) {
            return ['ok' => false, 'error' => 'schema_missing'];
        }

        $activeCond = (function_exists('db_column_exists') && db_column_exists('users_restaurants', 'is_active'))
            ? ' AND COALESCE(is_active,1)=1'
            : '';
        $stmtRole = $pdo->prepare("
            SELECT 1
            FROM users_restaurants
            WHERE user_id = :uid
              AND restaurant_id = :rid
              {$activeCond}
            LIMIT 1
        ");
        $stmtRole->execute([':uid' => $courierUserId, ':rid' => $restaurantId]);
        if (!(bool)$stmtRole->fetchColumn()) {
            return ['ok' => false, 'error' => 'courier_not_in_restaurant'];
        }

        $canOverride = !empty($options['allow_override']);
        if (function_exists('courier_shift_workload') && function_exists('courier_shift_active') && function_exists('courier_shift_status')) {
            $workload = courier_shift_workload($pdo, $restaurantId, $courierUserId, $options);
            $activeShift = courier_shift_active($pdo, $restaurantId, $courierUserId);
            $locationState = 'offline';
            if (function_exists('db_table_exists') && db_table_exists('courier_locations')) {
                try {
                    $stmtLoc = $pdo->prepare("
                        SELECT MAX(updated_at) AS last_location_at
                        FROM courier_locations
                        WHERE restaurant_id = :rest
                          AND courier_user_id = :uid
                    ");
                    $stmtLoc->execute([
                        ':rest' => $restaurantId,
                        ':uid' => $courierUserId,
                    ]);
                    $lastLocAt = (string)($stmtLoc->fetchColumn() ?: '');
                    $locationMeta = courier_location_is_fresh($lastLocAt, 20, 90);
                    $locationState = (string)($locationMeta['state'] ?? 'offline');
                } catch (Throwable $e) {
                    $locationState = 'offline';
                }
            }
            $shiftState = courier_shift_status(
                $activeShift,
                (int)($workload['active_deliveries'] ?? 0),
                $locationState,
                max(2, (int)($options['overload_threshold'] ?? 4))
            );
            if (empty($shiftState['is_available']) && !$canOverride) {
                return ['ok' => false, 'error' => 'courier_unavailable_shift'];
            }
        }

        $ownTx = false;
        try {
            if (!$pdo->inTransaction()) {
                $pdo->beginTransaction();
                $ownTx = true;
            }
            $stmt = $pdo->prepare("
                SELECT id, order_status, order_type, courier_status, courier_user_id
                FROM orders
                WHERE id = :id AND restaurant_id = :rid
                LIMIT 1
                FOR UPDATE
            ");
            $stmt->execute([':id' => $orderId, ':rid' => $restaurantId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!$row) {
                throw new RuntimeException('order_not_found');
            }
            if (order_type_normalize((string)($row['order_type'] ?? ''), null) !== 'delivery') {
                throw new RuntimeException('not_delivery');
            }
            $status = strtolower(trim((string)($row['order_status'] ?? 'new')));
            if (in_array($status, ['cancelled', 'canceled', 'delivered', 'completed'], true)) {
                throw new RuntimeException('order_closed');
            }

            $existingCourier = (int)($row['courier_user_id'] ?? 0);
            if ($existingCourier > 0 && $existingCourier !== $courierUserId && !$canOverride) {
                throw new RuntimeException('assigned_to_other');
            }

            $set = [
                'courier_user_id = :courier_user_id',
                "courier_status = 'handed_to_courier'",
            ];
            if (function_exists('db_column_exists') && db_column_exists('orders', 'courier_taken_at')) {
                $set[] = 'courier_taken_at = COALESCE(courier_taken_at, NOW())';
            }
            $upd = $pdo->prepare("
                UPDATE orders
                SET " . implode(', ', $set) . "
                WHERE id = :id AND restaurant_id = :rid
            ");
            $upd->execute([
                ':courier_user_id' => $courierUserId,
                ':id' => $orderId,
                ':rid' => $restaurantId,
            ]);

            if ($ownTx && $pdo->inTransaction()) {
                $pdo->commit();
            }

            return ['ok' => true, 'status' => 'assigned', 'courier_user_id' => $courierUserId];
        } catch (Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}

if (!function_exists('delivery_dispatch_unassign')) {
    /**
     * @param array<string,mixed> $options
     * @return array{ok:bool,error?:string,status?:string}
     */
    function delivery_dispatch_unassign(PDO $pdo, int $restaurantId, int $orderId, int $actorUserId = 0, array $options = []): array
    {
        if ($restaurantId <= 0 || $orderId <= 0) {
            return ['ok' => false, 'error' => 'invalid_params'];
        }
        $hasOrderType = function_exists('db_column_exists') && db_column_exists('orders', 'order_type');
        $hasCourierStatus = function_exists('db_column_exists') && db_column_exists('orders', 'courier_status');
        $hasCourierUserId = function_exists('db_column_exists') && db_column_exists('orders', 'courier_user_id');
        if (!$hasOrderType || !$hasCourierStatus || !$hasCourierUserId) {
            return ['ok' => false, 'error' => 'schema_missing'];
        }

        $ownTx = false;
        try {
            if (!$pdo->inTransaction()) {
                $pdo->beginTransaction();
                $ownTx = true;
            }
            $stmt = $pdo->prepare("
                SELECT id, order_status, order_type
                FROM orders
                WHERE id = :id AND restaurant_id = :rid
                LIMIT 1
                FOR UPDATE
            ");
            $stmt->execute([':id' => $orderId, ':rid' => $restaurantId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!$row) {
                throw new RuntimeException('order_not_found');
            }
            if (order_type_normalize((string)($row['order_type'] ?? ''), null) !== 'delivery') {
                throw new RuntimeException('not_delivery');
            }
            $status = strtolower(trim((string)($row['order_status'] ?? 'new')));
            if (in_array($status, ['cancelled', 'canceled', 'delivered', 'completed'], true)) {
                throw new RuntimeException('order_closed');
            }

            $set = [
                'courier_user_id = NULL',
                "courier_status = 'waiting_courier'",
            ];
            if (function_exists('db_column_exists') && db_column_exists('orders', 'courier_taken_at')) {
                $set[] = 'courier_taken_at = NULL';
            }
            if (function_exists('db_column_exists') && db_column_exists('orders', 'courier_on_the_way_at')) {
                $set[] = 'courier_on_the_way_at = NULL';
            }
            $upd = $pdo->prepare("
                UPDATE orders
                SET " . implode(', ', $set) . "
                WHERE id = :id AND restaurant_id = :rid
            ");
            $upd->execute([':id' => $orderId, ':rid' => $restaurantId]);

            if ($ownTx && $pdo->inTransaction()) {
                $pdo->commit();
            }
            return ['ok' => true, 'status' => 'waiting_dispatch'];
        } catch (Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}

if (!function_exists('order_guest_status_meta')) {
    function order_guest_status_meta(?string $orderStatus, ?string $orderType = null, ?string $paymentStatus = null, array $options = []): array
    {
        $statusRaw = strtolower(trim((string)($orderStatus ?? 'new')));
        $typeRaw = strtolower(trim((string)($orderType ?? '')));
        $paymentRaw = strtolower(trim((string)($paymentStatus ?? 'unpaid')));

        $type = order_type_normalize($typeRaw, null);
        $deliveryReadyAwaitCourier = !empty($options['delivery_ready_await_courier']);
        $courierStatus = ($type === 'delivery')
            ? courier_status_normalize((string)($options['courier_status'] ?? ''), $type)
            : '';

        $aliases = [
            'created' => 'new',
            'pending' => 'new',
            'confirmed' => 'accepted',
            'in_progress' => 'cooking',
            'progress' => 'cooking',
            'preparing' => 'cooking',
            'kitchen' => 'cooking',
            'processing' => 'cooking',
            'done' => 'ready',
            'finish' => 'ready',
            'finished' => 'ready',
            'served' => 'delivered',
            'completed' => 'delivered',
            'cancelled' => 'canceled',
            'rejected' => 'canceled',
            'declined' => 'canceled',
        ];
        $status = $aliases[$statusRaw] ?? ($statusRaw !== '' ? $statusRaw : 'new');

        $meta = [
            'code' => 'accepted',
            'label' => 'Заказ принят',
            'description' => ($type === 'delivery')
                ? 'Мы приняли заказ на доставку и передали его на кухню.'
                : 'Мы приняли ваш заказ и передали его на кухню.',
            'progress_percent' => 20,
            'step_key' => 'accepted',
            'is_final' => false,
            'tone' => 'info',
        ];

        if (in_array($status, ['new', 'accepted'], true)) {
            $meta['code'] = 'accepted';
            $meta['label'] = 'Заказ принят';
            $meta['description'] = ($type === 'delivery')
                ? 'Мы приняли заказ на доставку и передали его на кухню.'
                : 'Мы приняли ваш заказ и передали его на кухню.';
            $meta['progress_percent'] = 25;
            $meta['step_key'] = 'accepted';
            $meta['tone'] = 'info';
        } elseif ($status === 'cooking') {
            $meta['code'] = 'cooking';
            $meta['label'] = 'Готовим';
            $meta['description'] = ($type === 'delivery')
                ? 'Кухня готовит заказ к отправке.'
                : 'Кухня уже готовит ваш заказ.';
            $meta['progress_percent'] = 60;
            $meta['step_key'] = 'cooking';
            $meta['tone'] = 'warning';
        } elseif ($status === 'ready') {
            $meta['code'] = 'ready';
            if ($type === 'delivery' && ($courierStatus === 'waiting_courier' || $deliveryReadyAwaitCourier)) {
                $meta['label'] = 'Ожидает курьера';
                $meta['description'] = 'Заказ готов и ожидает передачу курьеру.';
            } elseif ($type === 'delivery' && $courierStatus === 'handed_to_courier') {
                $meta['label'] = 'Передан курьеру';
                $meta['description'] = 'Курьер уже забрал заказ.';
                $meta['progress_percent'] = 90;
                $meta['step_key'] = 'handoff';
                $meta['tone'] = 'info';
            } elseif ($type === 'delivery' && $courierStatus === 'on_the_way') {
                $meta['label'] = 'В пути';
                $meta['description'] = 'Курьер везёт заказ к вам.';
                $meta['progress_percent'] = 95;
                $meta['step_key'] = 'delivery';
                $meta['tone'] = 'warning';
            } elseif ($type === 'delivery' && $courierStatus === 'delivered') {
                $meta['code'] = 'completed';
                $meta['label'] = 'Доставлен';
                $meta['description'] = 'Спасибо! Ваш заказ доставлен.';
                $meta['progress_percent'] = 100;
                $meta['step_key'] = 'completed';
                $meta['is_final'] = true;
                $meta['tone'] = 'success';
            } else {
                $meta['label'] = 'Готов';
                $meta['description'] = ($type === 'delivery')
                    ? 'Заказ готов к выдаче или передаче курьеру.'
                    : 'Ваш заказ готов, скоро его принесут к столу.';
            }
            $meta['progress_percent'] = 85;
            $meta['step_key'] = 'ready';
            $meta['tone'] = 'success';
        } elseif ($status === 'delivered') {
            $meta['code'] = 'completed';
            $meta['label'] = ($type === 'delivery') ? 'Доставлен' : 'Завершён';
            $meta['description'] = 'Спасибо! Ваш заказ завершён.';
            $meta['progress_percent'] = 100;
            $meta['step_key'] = 'completed';
            $meta['is_final'] = true;
            $meta['tone'] = 'success';
        } elseif ($status === 'canceled') {
            $meta['code'] = 'canceled';
            $meta['label'] = 'Отменён';
            $meta['description'] = 'Заказ отменён. Уточните детали у персонала.';
            $meta['progress_percent'] = 0;
            $meta['step_key'] = 'canceled';
            $meta['is_final'] = true;
            $meta['tone'] = 'danger';
        } else {
            $meta['code'] = 'processing';
            $meta['label'] = 'В обработке';
            $meta['description'] = 'Мы обрабатываем ваш заказ.';
            $meta['progress_percent'] = 30;
            $meta['step_key'] = 'accepted';
            $meta['tone'] = 'info';
        }

        if ($type === 'delivery' && $status !== 'canceled') {
            if ($courierStatus === 'waiting_courier' && in_array($status, ['ready', 'cooking', 'accepted', 'new'], true)) {
                $meta['code'] = 'delivery_waiting_courier';
                $meta['label'] = 'Ищем курьера';
                $meta['description'] = 'Подбираем свободного курьера для вашего заказа.';
                $meta['progress_percent'] = max((int)($meta['progress_percent'] ?? 0), 75);
                $meta['step_key'] = 'courier_waiting';
                $meta['tone'] = 'info';
            } elseif ($courierStatus === 'handed_to_courier') {
                $meta['code'] = 'delivery_handed_to_courier';
                $meta['label'] = 'Курьер забрал заказ';
                $meta['description'] = 'Заказ передан курьеру и скоро будет доставлен.';
                $meta['progress_percent'] = max((int)($meta['progress_percent'] ?? 0), 88);
                $meta['step_key'] = 'courier_handed';
                $meta['tone'] = 'info';
            } elseif ($courierStatus === 'on_the_way') {
                $meta['code'] = 'delivery_on_the_way';
                $meta['label'] = 'Курьер уже в пути';
                $meta['description'] = 'Курьер направляется к вам.';
                $meta['progress_percent'] = max((int)($meta['progress_percent'] ?? 0), 94);
                $meta['step_key'] = 'courier_on_the_way';
                $meta['tone'] = 'warning';
            } elseif ($courierStatus === 'delivered') {
                $meta['code'] = 'completed';
                $meta['label'] = 'Заказ доставлен';
                $meta['description'] = 'Спасибо! Заказ доставлен.';
                $meta['progress_percent'] = 100;
                $meta['step_key'] = 'completed';
                $meta['is_final'] = true;
                $meta['tone'] = 'success';
            }
        }

        if ($meta['is_final'] && $paymentRaw === 'canceled' && $status !== 'canceled') {
            $meta['tone'] = 'neutral';
        }

        return $meta;
    }
}

if (!function_exists('floorplan_table_status_meta')) {
    /**
     * Build live table status for floorplan cards from real order/call signals.
     *
     * @param array<string,mixed>|null $order
     * @param array<string,mixed>|null $waiterCall
     * @return array{
     *   status_key:string,
     *   status_label:string,
     *   color_key:string,
     *   priority:int,
     *   is_attention:bool
     * }
     */
    function floorplan_table_status_meta(?array $order, ?array $waiterCall = null): array
    {
        $hasOrder = is_array($order) && !empty($order['id']);
        $hasWaiterCall = is_array($waiterCall) && (!empty($waiterCall['id']) || array_key_exists('order_id', $waiterCall));

        $status = [
            'status_key' => 'free',
            'status_label' => 'Свободно',
            'color_key' => 'slate',
            'priority' => 0,
            'is_attention' => false,
        ];

        if ($hasOrder) {
            $orderStatus = strtolower(trim((string)($order['order_status'] ?? '')));
            $paymentStatus = strtolower(trim((string)($order['payment_status'] ?? 'unpaid')));
            $countdownExpired = !empty($order['countdown_expired']);
            $countdownWarning = !empty($order['countdown_warning']);
            $readyItems = (int)($order['ready_items_count'] ?? 0);
            $totalItems = (int)($order['total_items_count'] ?? 0);
            $partialReady = !empty($order['partial_ready']);

            if (($orderStatus === 'delivered' && $paymentStatus === 'unpaid') || $countdownExpired) {
                $status = [
                    'status_key' => 'waiting_payment',
                    'status_label' => 'Ожидает оплату',
                    'color_key' => 'red',
                    'priority' => 95,
                    'is_attention' => true,
                ];
            } elseif ($countdownWarning && $paymentStatus !== 'paid') {
                $status = [
                    'status_key' => 'waiting_payment',
                    'status_label' => 'Оплата скоро',
                    'color_key' => 'amber',
                    'priority' => 85,
                    'is_attention' => true,
                ];
            } elseif ($orderStatus === 'ready' || ($totalItems > 0 && $readyItems >= $totalItems) || $partialReady) {
                $status = [
                    'status_key' => 'ready_to_serve',
                    'status_label' => $partialReady ? 'Частично готово' : 'Готов к подаче',
                    'color_key' => $partialReady ? 'indigo' : 'emerald',
                    'priority' => 80,
                    'is_attention' => true,
                ];
            } elseif (in_array($orderStatus, ['new', 'accepted', 'cooking', 'in_progress', 'preparing', 'processing'], true)) {
                $status = [
                    'status_key' => 'active_order',
                    'status_label' => 'Активный заказ',
                    'color_key' => in_array($orderStatus, ['new'], true) ? 'amber' : 'sky',
                    'priority' => 60,
                    'is_attention' => false,
                ];
            } elseif ($orderStatus === 'delivered' && $paymentStatus === 'paid') {
                $status = [
                    'status_key' => 'closed_cleanup',
                    'status_label' => 'Закрыт / уборка',
                    'color_key' => 'slate',
                    'priority' => 10,
                    'is_attention' => false,
                ];
            }
        }

        if ($hasWaiterCall) {
            $status = [
                'status_key' => 'waiting_waiter',
                'status_label' => 'Вызов официанта',
                'color_key' => 'orange',
                'priority' => 100,
                'is_attention' => true,
            ];
        }

        return $status;
    }
}

/**
 * Public URL for a menu item image (image_url or legacy image_path).
 * image_url paths like "menu/123/file.jpg" → /uploads/...; else → /storage/...
 * Normalizes accidental prefixes: "/uploads/…", "uploads/…", "/storage/…", leading slashes.
 */
if (!function_exists('menu_item_image_url')) {
    function menu_item_image_url(array $item): ?string
    {
        // Prefer local `image_path` (uploads), but keep legacy compatibility with `image_url`.
        $raw = !empty($item['image_path'])
            ? $item['image_path']
            : (!empty($item['image_url']) ? $item['image_url'] : null);
        if ($raw === null || $raw === '') {
            return null;
        }

        $url = trim((string)$raw);

        // Allow full external URLs to be used as-is (if configured).
        if (preg_match('#^https?://#i', $url) === 1) {
            return $url;
        }

        $publicRoot = dirname(__DIR__) . '/public_html';

        $url = ltrim($url, '/');
        if (str_starts_with($url, 'uploads/')) {
            $url = substr($url, strlen('uploads/'));
        }
        if (str_starts_with($url, 'storage/')) {
            $url = substr($url, strlen('storage/'));
        }

        if (str_starts_with($url, 'menu/')) {
            $abs = $publicRoot . '/uploads/' . $url;
            return is_file($abs) ? ('/uploads/' . $url) : null;
        }

        $abs = $publicRoot . '/storage/' . $url;
        return is_file($abs) ? ('/storage/' . $url) : null;
    }
}

function redirect(string $url): void
{
    header("Location: {$url}");
    exit;
}

/**
 * Безопасный редирект: только относительные пути (начинаются с /) или текущий main_domain.
 * После header('Location: ...') всегда вызывается exit.
 *
 * Запрещённые примеры (unit-level self-check):
 *   //evil.com           — protocol-relative
 *   javascript:alert(1)  — схема не http/https
 *   http://user:pass@host — embedded credentials
 *   http://good.com%0d%0aLocation:evil — encoded CRLF
 *   http://evil.com      — внешний домен
 *   http://main.com.    — trailing dot в host
 */
function safe_redirect(string $url): void
{
    $url = trim($url);
    if ($url === '') {
        header('Location: /');
        exit;
    }
    // CRLF, NUL — запрет injection в заголовок
    if (strpbrk($url, "\r\n\0") !== false) {
        header('Location: /');
        exit;
    }
    // Закодированные CRLF
    $decoded = rawurldecode($url);
    if (strpbrk($decoded, "\r\n\0") !== false) {
        header('Location: /');
        exit;
    }
    // Сначала разбираем через parse_url для единообразия
    $parsed = parse_url($url);
    if ($parsed === false) {
        header('Location: /');
        exit;
    }
    // Относительный путь: только если начинается с одного /
    if (!isset($parsed['host']) && !isset($parsed['scheme'])) {
        $path = $parsed['path'] ?? '';
        if ($path !== '' && $path[0] === '/' && strpos($path, '//') !== 0) {
            $out = $path;
            if (!empty($parsed['query'])) {
                $out .= '?' . $parsed['query'];
            }
            if (!empty($parsed['fragment'])) {
                $out .= '#' . $parsed['fragment'];
            }
            header('Location: ' . $out);
            exit;
        }
    }
    // Protocol-relative (//...) — запретить
    if (isset($parsed['host']) && (!isset($parsed['scheme']) || $parsed['scheme'] === '')) {
        header('Location: /');
        exit;
    }
    // Только http/https
    $scheme = strtolower((string)($parsed['scheme'] ?? ''));
    if (!in_array($scheme, ['http', 'https'], true)) {
        header('Location: /');
        exit;
    }
    // Embedded credentials — запретить
    if (!empty($parsed['user']) || !empty($parsed['pass'])) {
        header('Location: /');
        exit;
    }
    $host = isset($parsed['host']) ? strtolower($parsed['host']) : '';
    $host = rtrim($host, '.');
    if ($host === '') {
        header('Location: /');
        exit;
    }
    $config = require __DIR__ . '/config.php';
    $main   = strtolower((string)($config['app']['main_domain'] ?? ''));
    $main   = rtrim($main, '.');
    $allowed = ($host === $main || $host === 'www.' . $main);
    if (!$allowed) {
        header('Location: /');
        exit;
    }
    header('Location: ' . $url);
    exit;
}

if (!function_exists('guest_review_tags_normalize')) {
    /**
     * @param mixed $rawTags
     * @return list<string>
     */
    function guest_review_tags_normalize($rawTags): array
    {
        $allowed = [
            'fast_delivery',
            'tasty_food',
            'cold_food',
            'late_delivery',
            'polite_courier',
            'bad_packaging',
        ];
        $allowedMap = array_fill_keys($allowed, true);
        $aliasMap = [
            'fast-delivery' => 'fast_delivery',
            'быстрая_доставка' => 'fast_delivery',
            'быстрая-доставка' => 'fast_delivery',
            'вкусная_еда' => 'tasty_food',
            'вкусная-еда' => 'tasty_food',
            'холодная_еда' => 'cold_food',
            'холодная-еда' => 'cold_food',
            'долгая_доставка' => 'late_delivery',
            'долгая-доставка' => 'late_delivery',
            'вежливый_курьер' => 'polite_courier',
            'вежливый-курьер' => 'polite_courier',
            'плохая_упаковка' => 'bad_packaging',
            'плохая-упаковка' => 'bad_packaging',
        ];

        $tokens = [];
        if (is_array($rawTags)) {
            foreach ($rawTags as $tag) {
                $tokens[] = (string)$tag;
            }
        } else {
            $raw = trim((string)$rawTags);
            if ($raw !== '') {
                $tokens = preg_split('/[\s,;|]+/u', $raw) ?: [];
            }
        }
        if ($tokens === []) {
            return [];
        }

        $out = [];
        foreach ($tokens as $token) {
            $token = mb_strtolower(trim((string)$token), 'UTF-8');
            if ($token === '') {
                continue;
            }
            $token = str_replace([' ', '.'], '_', $token);
            $token = preg_replace('/[^\p{L}\p{N}_-]+/u', '', $token) ?: '';
            if ($token === '') {
                continue;
            }
            if (isset($aliasMap[$token])) {
                $token = $aliasMap[$token];
            }
            if (!isset($allowedMap[$token])) {
                continue;
            }
            $out[$token] = true;
        }

        return array_keys($out);
    }
}

if (!function_exists('guest_review_exists')) {
    function guest_review_exists(PDO $pdo, int $restaurantId, int $orderId): bool
    {
        if ($restaurantId <= 0 || $orderId <= 0) {
            return false;
        }
        if (!function_exists('guest_history_has_table') || !guest_history_has_table($pdo, 'guest_reviews')) {
            return false;
        }
        try {
            $stmt = $pdo->prepare("
                SELECT 1
                FROM guest_reviews
                WHERE restaurant_id = :restaurant_id
                  AND order_id = :order_id
                LIMIT 1
            ");
            $stmt->execute([
                ':restaurant_id' => $restaurantId,
                ':order_id' => $orderId,
            ]);
            return (bool)$stmt->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('guest_review_create')) {
    /**
     * @param array<string,mixed> $payload
     * @return array{ok:bool,created:bool,updated:bool,review_id:int,error:?string}
     */
    function guest_review_create(PDO $pdo, int $restaurantId, array $payload): array
    {
        if ($restaurantId <= 0) {
            return ['ok' => false, 'created' => false, 'updated' => false, 'review_id' => 0, 'error' => 'invalid_restaurant'];
        }
        if (!function_exists('guest_history_has_table') || !guest_history_has_table($pdo, 'guest_reviews')) {
            return ['ok' => false, 'created' => false, 'updated' => false, 'review_id' => 0, 'error' => 'guest_reviews_unavailable'];
        }

        $orderId = (int)($payload['order_id'] ?? 0);
        $rating = (int)($payload['rating'] ?? 0);
        $npsScoreRaw = $payload['nps_score'] ?? null;
        $npsScore = null;
        if ($npsScoreRaw !== null && $npsScoreRaw !== '') {
            $npsScore = (int)$npsScoreRaw;
        }
        $reviewText = trim((string)($payload['review_text'] ?? $payload['comment'] ?? ''));
        $reviewText = mb_substr($reviewText, 0, 2000);
        $source = trim((string)($payload['source'] ?? 'order_track'));
        if ($source === '') {
            $source = 'order_track';
        }
        $source = mb_substr(mb_strtolower($source, 'UTF-8'), 0, 32);
        if (!preg_match('/^[a-z0-9._-]{2,32}$/', $source)) {
            $source = 'order_track';
        }
        $reviewTags = guest_review_tags_normalize($payload['review_tags'] ?? []);
        $reviewTagsJson = $reviewTags === [] ? null : json_encode($reviewTags, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $guestProfileId = isset($payload['guest_profile_id']) ? (int)$payload['guest_profile_id'] : 0;

        if ($orderId <= 0) {
            return ['ok' => false, 'created' => false, 'updated' => false, 'review_id' => 0, 'error' => 'invalid_order'];
        }
        if ($rating < 1 || $rating > 5) {
            return ['ok' => false, 'created' => false, 'updated' => false, 'review_id' => 0, 'error' => 'invalid_rating'];
        }
        if ($npsScore !== null && ($npsScore < 0 || $npsScore > 10)) {
            return ['ok' => false, 'created' => false, 'updated' => false, 'review_id' => 0, 'error' => 'invalid_nps'];
        }

        $phoneNormalized = guest_normalize_phone((string)($payload['phone_normalized'] ?? ''));
        if ($phoneNormalized === null) {
            $phoneNormalized = guest_normalize_phone((string)($payload['customer_phone'] ?? ''));
        }
        if ($phoneNormalized === null) {
            $phoneNormalized = guest_normalize_phone((string)($payload['delivery_phone'] ?? ''));
        }
        if ($phoneNormalized === null) {
            $phoneNormalized = guest_normalize_phone((string)($payload['guest_phone'] ?? ''));
        }
        if ($phoneNormalized === null) {
            $phoneNormalized = guest_normalize_phone((string)($payload['loyalty_phone'] ?? ''));
        }

        if (
            function_exists('guest_history_resolve_phone')
            && ($phoneNormalized !== null || $guestProfileId > 0)
        ) {
            try {
                $resolved = guest_history_resolve_phone($pdo, $restaurantId, [
                    'guest_profile_id' => $guestProfileId,
                    'phone_normalized' => $phoneNormalized ?? '',
                    'phone' => $phoneNormalized ?? '',
                ]);
                if (is_array($resolved)) {
                    $resolvedProfileId = (int)($resolved['guest_profile_id'] ?? 0);
                    if ($resolvedProfileId > 0) {
                        $guestProfileId = $resolvedProfileId;
                    }
                    $resolvedPhone = guest_normalize_phone((string)($resolved['phone_normalized'] ?? ''));
                    if ($resolvedPhone !== null) {
                        $phoneNormalized = $resolvedPhone;
                    }
                }
            } catch (Throwable $e) {
                // Keep review flow working even if resolver lookup fails.
            }
        }

        $reviewId = 0;
        $created = false;
        $updated = false;
        $ownsTx = false;
        try {
            if (!$pdo->inTransaction()) {
                $pdo->beginTransaction();
                $ownsTx = true;
            }

            $stmtExisting = $pdo->prepare("
                SELECT id
                FROM guest_reviews
                WHERE order_id = :order_id
                LIMIT 1
                FOR UPDATE
            ");
            $stmtExisting->execute([':order_id' => $orderId]);
            $existingId = (int)($stmtExisting->fetchColumn() ?: 0);

            if ($existingId > 0) {
                $stmtUpdate = $pdo->prepare("
                    UPDATE guest_reviews
                    SET restaurant_id = :restaurant_id,
                        guest_profile_id = :guest_profile_id,
                        phone_normalized = :phone_normalized,
                        rating = :rating,
                        nps_score = :nps_score,
                        review_text = :review_text,
                        review_tags = :review_tags,
                        source = :source,
                        updated_at = NOW()
                    WHERE id = :id
                    LIMIT 1
                ");
                $stmtUpdate->execute([
                    ':restaurant_id' => $restaurantId,
                    ':guest_profile_id' => $guestProfileId > 0 ? $guestProfileId : null,
                    ':phone_normalized' => $phoneNormalized,
                    ':rating' => $rating,
                    ':nps_score' => $npsScore,
                    ':review_text' => $reviewText !== '' ? $reviewText : null,
                    ':review_tags' => $reviewTagsJson,
                    ':source' => $source,
                    ':id' => $existingId,
                ]);
                $reviewId = $existingId;
                $updated = true;
            } else {
                $stmtInsert = $pdo->prepare("
                    INSERT INTO guest_reviews (
                        restaurant_id,
                        order_id,
                        guest_profile_id,
                        phone_normalized,
                        rating,
                        nps_score,
                        review_text,
                        review_tags,
                        source,
                        created_at,
                        updated_at
                    ) VALUES (
                        :restaurant_id,
                        :order_id,
                        :guest_profile_id,
                        :phone_normalized,
                        :rating,
                        :nps_score,
                        :review_text,
                        :review_tags,
                        :source,
                        NOW(),
                        NOW()
                    )
                ");
                $stmtInsert->execute([
                    ':restaurant_id' => $restaurantId,
                    ':order_id' => $orderId,
                    ':guest_profile_id' => $guestProfileId > 0 ? $guestProfileId : null,
                    ':phone_normalized' => $phoneNormalized,
                    ':rating' => $rating,
                    ':nps_score' => $npsScore,
                    ':review_text' => $reviewText !== '' ? $reviewText : null,
                    ':review_tags' => $reviewTagsJson,
                    ':source' => $source,
                ]);
                $reviewId = (int)$pdo->lastInsertId();
                $created = true;
            }

            if ($ownsTx && $pdo->inTransaction()) {
                $pdo->commit();
            }

            return ['ok' => true, 'created' => $created, 'updated' => $updated, 'review_id' => $reviewId, 'error' => null];
        } catch (Throwable $e) {
            if ($ownsTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $message = $e->getMessage();
            $isDuplicate = false;
            if ($e instanceof PDOException) {
                $sqlState = (string)($e->getCode() ?? '');
                $isDuplicate = ($sqlState === '23000') || strpos($message, 'Duplicate entry') !== false;
            } else {
                $isDuplicate = strpos($message, 'Duplicate entry') !== false;
            }
            if ($isDuplicate) {
                try {
                    $stmtUpdate = $pdo->prepare("
                        UPDATE guest_reviews
                        SET restaurant_id = :restaurant_id,
                            guest_profile_id = :guest_profile_id,
                            phone_normalized = :phone_normalized,
                            rating = :rating,
                            nps_score = :nps_score,
                            review_text = :review_text,
                            review_tags = :review_tags,
                            source = :source,
                            updated_at = NOW()
                        WHERE order_id = :order_id
                        LIMIT 1
                    ");
                    $stmtUpdate->execute([
                        ':restaurant_id' => $restaurantId,
                        ':guest_profile_id' => $guestProfileId > 0 ? $guestProfileId : null,
                        ':phone_normalized' => $phoneNormalized,
                        ':rating' => $rating,
                        ':nps_score' => $npsScore,
                        ':review_text' => $reviewText !== '' ? $reviewText : null,
                        ':review_tags' => $reviewTagsJson,
                        ':source' => $source,
                        ':order_id' => $orderId,
                    ]);
                    $stmtId = $pdo->prepare("SELECT id FROM guest_reviews WHERE order_id = :order_id LIMIT 1");
                    $stmtId->execute([':order_id' => $orderId]);
                    $reviewId = (int)($stmtId->fetchColumn() ?: 0);
                    return ['ok' => true, 'created' => false, 'updated' => true, 'review_id' => $reviewId, 'error' => null];
                } catch (Throwable $e2) {
                    return ['ok' => false, 'created' => false, 'updated' => false, 'review_id' => 0, 'error' => 'write_failed'];
                }
            }

            return ['ok' => false, 'created' => false, 'updated' => false, 'review_id' => 0, 'error' => 'write_failed'];
        }
    }
}

if (!function_exists('guest_review_nps')) {
    /**
     * @return array{score:?int,total:int,promoters:int,passives:int,detractors:int}
     */
    function guest_review_nps(PDO $pdo, int $restaurantId, int $days = 90): array
    {
        $out = [
            'score' => null,
            'total' => 0,
            'promoters' => 0,
            'passives' => 0,
            'detractors' => 0,
        ];
        if ($restaurantId <= 0 || !function_exists('guest_history_has_table') || !guest_history_has_table($pdo, 'guest_reviews')) {
            return $out;
        }

        $days = max(1, min(3650, $days));
        try {
            $stmt = $pdo->prepare("
                SELECT
                    COUNT(*) AS total,
                    SUM(CASE WHEN nps_score >= 9 THEN 1 ELSE 0 END) AS promoters,
                    SUM(CASE WHEN nps_score IN (7, 8) THEN 1 ELSE 0 END) AS passives,
                    SUM(CASE WHEN nps_score BETWEEN 0 AND 6 THEN 1 ELSE 0 END) AS detractors
                FROM guest_reviews
                WHERE restaurant_id = :restaurant_id
                  AND nps_score IS NOT NULL
                  AND created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
            ");
            $stmt->bindValue(':restaurant_id', $restaurantId, PDO::PARAM_INT);
            $stmt->bindValue(':days', $days, PDO::PARAM_INT);
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $total = (int)($row['total'] ?? 0);
            $promoters = (int)($row['promoters'] ?? 0);
            $passives = (int)($row['passives'] ?? 0);
            $detractors = (int)($row['detractors'] ?? 0);
            $score = null;
            if ($total > 0) {
                $score = (int)round((($promoters / $total) - ($detractors / $total)) * 100);
            }
            $out = [
                'score' => $score,
                'total' => $total,
                'promoters' => $promoters,
                'passives' => $passives,
                'detractors' => $detractors,
            ];
        } catch (Throwable $e) {
            // keep fallback defaults
        }

        return $out;
    }
}

if (!function_exists('guest_review_summary')) {
    /**
     * @return array{
     *   average_rating:?float,
     *   average_nps:?float,
     *   nps_score:?int,
     *   total_reviews:int,
     *   negative_reviews:int,
     *   latest_reviews:list<array{id:int,order_id:int,rating:int,nps_score:?int,review_text:string,review_tags:list<string>,phone_normalized:string,guest_profile_id:int,created_at:?string}>,
     *   low_rating_alerts:list<array{id:int,order_id:int,rating:int,nps_score:?int,review_text:string,review_tags:list<string>,phone_normalized:string,guest_profile_id:int,created_at:?string}>
     * }
     */
    function guest_review_summary(PDO $pdo, int $restaurantId, int $latestLimit = 5, int $days = 90): array
    {
        $out = [
            'average_rating' => null,
            'average_nps' => null,
            'nps_score' => null,
            'total_reviews' => 0,
            'negative_reviews' => 0,
            'latest_reviews' => [],
            'low_rating_alerts' => [],
        ];
        if ($restaurantId <= 0 || !function_exists('guest_history_has_table') || !guest_history_has_table($pdo, 'guest_reviews')) {
            return $out;
        }

        $latestLimit = max(1, min(20, $latestLimit));
        $days = max(1, min(3650, $days));

        try {
            $stmtAgg = $pdo->prepare("
                SELECT
                    COUNT(*) AS total_reviews,
                    AVG(rating) AS average_rating,
                    AVG(nps_score) AS average_nps,
                    SUM(CASE WHEN rating <= 2 THEN 1 ELSE 0 END) AS negative_reviews
                FROM guest_reviews
                WHERE restaurant_id = :restaurant_id
                  AND created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
            ");
            $stmtAgg->bindValue(':restaurant_id', $restaurantId, PDO::PARAM_INT);
            $stmtAgg->bindValue(':days', $days, PDO::PARAM_INT);
            $stmtAgg->execute();
            $agg = $stmtAgg->fetch(PDO::FETCH_ASSOC) ?: [];

            $out['total_reviews'] = (int)($agg['total_reviews'] ?? 0);
            $out['average_rating'] = ($agg['average_rating'] ?? null) !== null ? round((float)$agg['average_rating'], 2) : null;
            $out['average_nps'] = ($agg['average_nps'] ?? null) !== null ? round((float)$agg['average_nps'], 2) : null;
            $out['negative_reviews'] = (int)($agg['negative_reviews'] ?? 0);

            $nps = guest_review_nps($pdo, $restaurantId, $days);
            $out['nps_score'] = $nps['score'];

            $loadList = static function (PDO $pdo, int $restaurantId, int $limit, bool $onlyLowRating): array {
                $whereLow = $onlyLowRating ? 'AND rating <= 2' : '';
                $stmt = $pdo->prepare("
                    SELECT
                        id,
                        order_id,
                        guest_profile_id,
                        phone_normalized,
                        rating,
                        nps_score,
                        review_text,
                        review_tags,
                        created_at
                    FROM guest_reviews
                    WHERE restaurant_id = :restaurant_id
                      {$whereLow}
                    ORDER BY created_at DESC, id DESC
                    LIMIT {$limit}
                ");
                $stmt->execute([':restaurant_id' => $restaurantId]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                $list = [];
                foreach ($rows as $row) {
                    $tagsRaw = (string)($row['review_tags'] ?? '');
                    $tags = [];
                    if ($tagsRaw !== '') {
                        $decoded = json_decode($tagsRaw, true);
                        if (is_array($decoded)) {
                            $tags = guest_review_tags_normalize($decoded);
                        } else {
                            $tags = guest_review_tags_normalize($tagsRaw);
                        }
                    }
                    $list[] = [
                        'id' => (int)($row['id'] ?? 0),
                        'order_id' => (int)($row['order_id'] ?? 0),
                        'guest_profile_id' => (int)($row['guest_profile_id'] ?? 0),
                        'phone_normalized' => trim((string)($row['phone_normalized'] ?? '')),
                        'rating' => (int)($row['rating'] ?? 0),
                        'nps_score' => ($row['nps_score'] ?? null) !== null ? (int)$row['nps_score'] : null,
                        'review_text' => trim((string)($row['review_text'] ?? '')),
                        'review_tags' => $tags,
                        'created_at' => !empty($row['created_at']) ? (string)$row['created_at'] : null,
                    ];
                }
                return $list;
            };

            $out['latest_reviews'] = $loadList($pdo, $restaurantId, $latestLimit, false);
            $out['low_rating_alerts'] = $loadList($pdo, $restaurantId, $latestLimit, true);
        } catch (Throwable $e) {
            // keep fallback
        }

        return $out;
    }
}

if (!function_exists('order_tip_target')) {
    /**
     * @param array<string,mixed> $orderContext
     * @return array{target:string,staff_user_id:?int,courier_user_id:?int}
     */
    function order_tip_target(array $orderContext): array
    {
        $orderType = function_exists('order_type_normalize')
            ? order_type_normalize((string)($orderContext['order_type'] ?? ''), (int)($orderContext['table_id'] ?? 0))
            : 'hall';

        $courierUserId = isset($orderContext['courier_user_id']) ? (int)$orderContext['courier_user_id'] : 0;
        if ($orderType === 'delivery' && $courierUserId > 0) {
            return [
                'target' => 'courier',
                'staff_user_id' => null,
                'courier_user_id' => $courierUserId,
            ];
        }

        foreach (['staff_user_id', 'waiter_user_id', 'assigned_waiter_id', 'user_id', 'created_by_user_id'] as $candidate) {
            $value = isset($orderContext[$candidate]) ? (int)$orderContext[$candidate] : 0;
            if ($value > 0) {
                return [
                    'target' => 'waiter',
                    'staff_user_id' => $value,
                    'courier_user_id' => null,
                ];
            }
        }

        return [
            'target' => ($orderType === 'delivery' ? 'courier' : 'waiter'),
            'staff_user_id' => null,
            'courier_user_id' => null,
        ];
    }
}

if (!function_exists('order_tip_validate')) {
    /**
     * @param array<string,mixed> $payload
     * @return array{
     *   ok:bool,
     *   error:?string,
     *   amount:float,
     *   currency:string,
     *   status:string,
     *   source:string,
     *   note:?string,
     *   target:string,
     *   staff_user_id:?int,
     *   courier_user_id:?int,
     *   guest_profile_id:?int,
     *   phone_normalized:?string
     * }
     */
    function order_tip_validate(array $payload): array
    {
        $amountRaw = (float)($payload['amount'] ?? 0);
        $amount = round($amountRaw, 2);
        if (!is_finite($amount) || $amount <= 0) {
            return ['ok' => false, 'error' => 'invalid_amount', 'amount' => 0, 'currency' => 'RUB', 'status' => 'pending', 'source' => 'order_track', 'note' => null, 'target' => 'waiter', 'staff_user_id' => null, 'courier_user_id' => null, 'guest_profile_id' => null, 'phone_normalized' => null];
        }
        if ($amount > 1000000) {
            return ['ok' => false, 'error' => 'amount_too_large', 'amount' => 0, 'currency' => 'RUB', 'status' => 'pending', 'source' => 'order_track', 'note' => null, 'target' => 'waiter', 'staff_user_id' => null, 'courier_user_id' => null, 'guest_profile_id' => null, 'phone_normalized' => null];
        }

        $currency = strtoupper(trim((string)($payload['currency'] ?? 'RUB')));
        if (!preg_match('/^[A-Z]{3,8}$/', $currency)) {
            $currency = 'RUB';
        }

        $status = strtolower(trim((string)($payload['status'] ?? 'pending')));
        if (!in_array($status, ['pending', 'paid', 'cancelled'], true)) {
            $status = 'pending';
        }

        $source = strtolower(trim((string)($payload['source'] ?? 'order_track')));
        if (!preg_match('/^[a-z0-9._-]{2,32}$/', $source)) {
            $source = 'order_track';
        }

        $note = trim((string)($payload['note'] ?? ''));
        if ($note === '') {
            $note = null;
        } else {
            $note = mb_substr($note, 0, 255);
        }

        $target = strtolower(trim((string)($payload['target'] ?? '')));
        $staffUserId = isset($payload['staff_user_id']) ? (int)$payload['staff_user_id'] : 0;
        $courierUserId = isset($payload['courier_user_id']) ? (int)$payload['courier_user_id'] : 0;

        if (!in_array($target, ['waiter', 'courier'], true)) {
            $resolvedTarget = order_tip_target($payload);
            $target = (string)($resolvedTarget['target'] ?? 'waiter');
            $staffUserId = (int)($resolvedTarget['staff_user_id'] ?? 0);
            $courierUserId = (int)($resolvedTarget['courier_user_id'] ?? 0);
        }

        if ($target === 'courier') {
            $staffUserId = 0;
        } else {
            $courierUserId = 0;
        }

        $phoneNormalized = guest_normalize_phone((string)($payload['phone_normalized'] ?? ''));
        if ($phoneNormalized === null) {
            $phoneNormalized = guest_normalize_phone((string)($payload['customer_phone'] ?? ''));
        }
        if ($phoneNormalized === null) {
            $phoneNormalized = guest_normalize_phone((string)($payload['delivery_phone'] ?? ''));
        }
        if ($phoneNormalized === null) {
            $phoneNormalized = guest_normalize_phone((string)($payload['guest_phone'] ?? ''));
        }
        if ($phoneNormalized === null) {
            $phoneNormalized = guest_normalize_phone((string)($payload['loyalty_phone'] ?? ''));
        }

        $guestProfileId = isset($payload['guest_profile_id']) ? (int)$payload['guest_profile_id'] : 0;

        return [
            'ok' => true,
            'error' => null,
            'amount' => $amount,
            'currency' => $currency,
            'status' => $status,
            'source' => $source,
            'note' => $note,
            'target' => $target,
            'staff_user_id' => $staffUserId > 0 ? $staffUserId : null,
            'courier_user_id' => $courierUserId > 0 ? $courierUserId : null,
            'guest_profile_id' => $guestProfileId > 0 ? $guestProfileId : null,
            'phone_normalized' => $phoneNormalized,
        ];
    }
}

if (!function_exists('order_tip_exists')) {
    /**
     * @return array<string,mixed>|null
     */
    function order_tip_exists(PDO $pdo, int $restaurantId, int $orderId, string $target = '', ?int $staffUserId = null, ?int $courierUserId = null): ?array
    {
        if ($restaurantId <= 0 || $orderId <= 0) {
            return null;
        }
        if (!function_exists('guest_history_has_table') || !guest_history_has_table($pdo, 'order_tips')) {
            return null;
        }
        $target = strtolower(trim($target));
        $where = [
            'restaurant_id = :restaurant_id',
            'order_id = :order_id',
        ];
        $params = [
            ':restaurant_id' => $restaurantId,
            ':order_id' => $orderId,
        ];
        if ($target === 'courier') {
            if ($courierUserId !== null && $courierUserId > 0) {
                $where[] = 'courier_user_id = :courier_user_id';
                $params[':courier_user_id'] = $courierUserId;
            } else {
                $where[] = 'courier_user_id IS NULL';
            }
        } elseif ($target === 'waiter') {
            if ($staffUserId !== null && $staffUserId > 0) {
                $where[] = 'staff_user_id = :staff_user_id';
                $params[':staff_user_id'] = $staffUserId;
            } else {
                $where[] = 'staff_user_id IS NULL';
            }
        }

        $stmt = $pdo->prepare("
            SELECT *
            FROM order_tips
            WHERE " . implode(' AND ', $where) . "
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

if (!function_exists('order_tip_create')) {
    /**
     * @param array<string,mixed> $payload
     * @return array{ok:bool,created:bool,updated:bool,tip_id:int,error:?string,status:?string}
     */
    function order_tip_create(PDO $pdo, int $restaurantId, int $orderId, array $payload): array
    {
        if ($restaurantId <= 0 || $orderId <= 0) {
            return ['ok' => false, 'created' => false, 'updated' => false, 'tip_id' => 0, 'error' => 'invalid_arguments', 'status' => null];
        }
        if (!function_exists('guest_history_has_table') || !guest_history_has_table($pdo, 'order_tips')) {
            return ['ok' => false, 'created' => false, 'updated' => false, 'tip_id' => 0, 'error' => 'order_tips_unavailable', 'status' => null];
        }

        $validated = order_tip_validate($payload);
        if (empty($validated['ok'])) {
            return ['ok' => false, 'created' => false, 'updated' => false, 'tip_id' => 0, 'error' => (string)($validated['error'] ?? 'invalid_tip'), 'status' => null];
        }

        $target = (string)($validated['target'] ?? 'waiter');
        $staffUserId = isset($validated['staff_user_id']) ? (int)$validated['staff_user_id'] : 0;
        $courierUserId = isset($validated['courier_user_id']) ? (int)$validated['courier_user_id'] : 0;
        $tipId = 0;
        $created = false;
        $updated = false;
        $ownsTx = false;

        try {
            if (!$pdo->inTransaction()) {
                $pdo->beginTransaction();
                $ownsTx = true;
            }

            $existing = order_tip_exists(
                $pdo,
                $restaurantId,
                $orderId,
                $target,
                $staffUserId > 0 ? $staffUserId : null,
                $courierUserId > 0 ? $courierUserId : null
            );
            if (is_array($existing) && (int)($existing['id'] ?? 0) > 0) {
                $tipId = (int)$existing['id'];
                $stmtUpd = $pdo->prepare("
                    UPDATE order_tips
                    SET amount = :amount,
                        currency = :currency,
                        status = :status,
                        source = :source,
                        note = :note,
                        guest_profile_id = :guest_profile_id,
                        phone_normalized = :phone_normalized,
                        updated_at = NOW()
                    WHERE id = :id
                    LIMIT 1
                ");
                $stmtUpd->execute([
                    ':amount' => (float)$validated['amount'],
                    ':currency' => (string)$validated['currency'],
                    ':status' => (string)$validated['status'],
                    ':source' => (string)$validated['source'],
                    ':note' => $validated['note'],
                    ':guest_profile_id' => $validated['guest_profile_id'],
                    ':phone_normalized' => $validated['phone_normalized'],
                    ':id' => $tipId,
                ]);
                $updated = true;
            } else {
                $stmtIns = $pdo->prepare("
                    INSERT INTO order_tips (
                        restaurant_id,
                        order_id,
                        staff_user_id,
                        courier_user_id,
                        guest_profile_id,
                        phone_normalized,
                        amount,
                        currency,
                        source,
                        status,
                        note,
                        created_at,
                        updated_at
                    ) VALUES (
                        :restaurant_id,
                        :order_id,
                        :staff_user_id,
                        :courier_user_id,
                        :guest_profile_id,
                        :phone_normalized,
                        :amount,
                        :currency,
                        :source,
                        :status,
                        :note,
                        NOW(),
                        NOW()
                    )
                ");
                $stmtIns->execute([
                    ':restaurant_id' => $restaurantId,
                    ':order_id' => $orderId,
                    ':staff_user_id' => $staffUserId > 0 ? $staffUserId : null,
                    ':courier_user_id' => $courierUserId > 0 ? $courierUserId : null,
                    ':guest_profile_id' => $validated['guest_profile_id'],
                    ':phone_normalized' => $validated['phone_normalized'],
                    ':amount' => (float)$validated['amount'],
                    ':currency' => (string)$validated['currency'],
                    ':source' => (string)$validated['source'],
                    ':status' => (string)$validated['status'],
                    ':note' => $validated['note'],
                ]);
                $tipId = (int)$pdo->lastInsertId();
                $created = true;
            }

            if ($ownsTx && $pdo->inTransaction()) {
                $pdo->commit();
            }
            return ['ok' => true, 'created' => $created, 'updated' => $updated, 'tip_id' => $tipId, 'error' => null, 'status' => (string)$validated['status']];
        } catch (Throwable $e) {
            if ($ownsTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['ok' => false, 'created' => false, 'updated' => false, 'tip_id' => 0, 'error' => 'write_failed', 'status' => null];
        }
    }
}

if (!function_exists('order_tip_summary')) {
    /**
     * @param array<string,mixed> $options
     * @return array{
     *   total_tips:float,
     *   waiter_tips:float,
     *   courier_tips:float,
     *   average_tip:float,
     *   tips_count:int,
     *   pending_count:int,
     *   paid_count:int,
     *   cancelled_count:int,
     *   active_tips_count:int,
     *   earned_tips:float,
     *   recent_tips:list<array{id:int,order_id:int,amount:float,currency:string,status:string,target:string,staff_user_id:?int,courier_user_id:?int,note:string,created_at:?string}>,
     *   top_tipped_staff:list<array{user_id:int,name:string,target:string,total_amount:float,tips_count:int}>
     * }
     */
    function order_tip_summary(PDO $pdo, int $restaurantId, array $options = []): array
    {
        $out = [
            'total_tips' => 0.0,
            'waiter_tips' => 0.0,
            'courier_tips' => 0.0,
            'average_tip' => 0.0,
            'tips_count' => 0,
            'pending_count' => 0,
            'paid_count' => 0,
            'cancelled_count' => 0,
            'active_tips_count' => 0,
            'earned_tips' => 0.0,
            'recent_tips' => [],
            'top_tipped_staff' => [],
        ];
        if ($restaurantId <= 0 || !function_exists('guest_history_has_table') || !guest_history_has_table($pdo, 'order_tips')) {
            return $out;
        }

        $limit = max(1, min(20, (int)($options['limit'] ?? 5)));
        $days = max(1, min(3650, (int)($options['days'] ?? 90)));
        $cutoffAt = date('Y-m-d H:i:s', time() - ($days * 86400));
        $scope = strtolower(trim((string)($options['scope'] ?? 'all')));
        $forCourierUserId = isset($options['courier_user_id']) ? (int)$options['courier_user_id'] : 0;
        $forStaffUserId = isset($options['staff_user_id']) ? (int)$options['staff_user_id'] : 0;

        $where = [
            'restaurant_id = :restaurant_id',
            'created_at >= :cutoff_at',
        ];
        $params = [
            ':restaurant_id' => $restaurantId,
            ':cutoff_at' => $cutoffAt,
        ];
        if ($scope === 'courier') {
            $where[] = 'courier_user_id IS NOT NULL';
        } elseif ($scope === 'waiter') {
            $where[] = 'staff_user_id IS NOT NULL';
        }
        if ($forCourierUserId > 0) {
            $where[] = 'courier_user_id = :courier_user_id';
            $params[':courier_user_id'] = $forCourierUserId;
        }
        if ($forStaffUserId > 0) {
            $where[] = 'staff_user_id = :staff_user_id';
            $params[':staff_user_id'] = $forStaffUserId;
        }

        try {
            $stmtAgg = $pdo->prepare("
                SELECT
                    COUNT(*) AS tips_count,
                    COALESCE(SUM(CASE WHEN status = 'paid' THEN amount ELSE 0 END), 0) AS total_paid,
                    COALESCE(SUM(CASE WHEN status = 'paid' AND courier_user_id IS NOT NULL THEN amount ELSE 0 END), 0) AS courier_paid,
                    COALESCE(SUM(CASE WHEN status = 'paid' AND staff_user_id IS NOT NULL THEN amount ELSE 0 END), 0) AS waiter_paid,
                    COALESCE(AVG(CASE WHEN status = 'paid' THEN amount END), 0) AS average_tip,
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_count,
                    SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) AS paid_count,
                    SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_count
                FROM order_tips
                WHERE " . implode(' AND ', $where)
            );
            foreach ($params as $key => $value) {
                if (is_int($value)) {
                    $stmtAgg->bindValue($key, $value, PDO::PARAM_INT);
                } else {
                    $stmtAgg->bindValue($key, $value);
                }
            }
            $stmtAgg->execute();
            $agg = $stmtAgg->fetch(PDO::FETCH_ASSOC) ?: [];

            $out['tips_count'] = (int)($agg['tips_count'] ?? 0);
            $out['total_tips'] = round((float)($agg['total_paid'] ?? 0), 2);
            $out['courier_tips'] = round((float)($agg['courier_paid'] ?? 0), 2);
            $out['waiter_tips'] = round((float)($agg['waiter_paid'] ?? 0), 2);
            $out['average_tip'] = round((float)($agg['average_tip'] ?? 0), 2);
            $out['pending_count'] = (int)($agg['pending_count'] ?? 0);
            $out['paid_count'] = (int)($agg['paid_count'] ?? 0);
            $out['cancelled_count'] = (int)($agg['cancelled_count'] ?? 0);
            $out['active_tips_count'] = $out['pending_count'];
            $out['earned_tips'] = $out['total_tips'];

            $stmtRecent = $pdo->prepare("
                SELECT
                    id,
                    order_id,
                    amount,
                    currency,
                    status,
                    staff_user_id,
                    courier_user_id,
                    note,
                    created_at
                FROM order_tips
                WHERE " . implode(' AND ', $where) . "
                ORDER BY created_at DESC, id DESC
                LIMIT {$limit}
            ");
            foreach ($params as $key => $value) {
                if (is_int($value)) {
                    $stmtRecent->bindValue($key, $value, PDO::PARAM_INT);
                } else {
                    $stmtRecent->bindValue($key, $value);
                }
            }
            $stmtRecent->execute();
            $recentRows = $stmtRecent->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $recent = [];
            foreach ($recentRows as $row) {
                $recent[] = [
                    'id' => (int)($row['id'] ?? 0),
                    'order_id' => (int)($row['order_id'] ?? 0),
                    'amount' => round((float)($row['amount'] ?? 0), 2),
                    'currency' => trim((string)($row['currency'] ?? 'RUB')),
                    'status' => trim((string)($row['status'] ?? 'pending')),
                    'target' => ((int)($row['courier_user_id'] ?? 0) > 0 ? 'courier' : 'waiter'),
                    'staff_user_id' => ((int)($row['staff_user_id'] ?? 0) > 0 ? (int)$row['staff_user_id'] : null),
                    'courier_user_id' => ((int)($row['courier_user_id'] ?? 0) > 0 ? (int)$row['courier_user_id'] : null),
                    'note' => trim((string)($row['note'] ?? '')),
                    'created_at' => !empty($row['created_at']) ? (string)$row['created_at'] : null,
                ];
            }
            $out['recent_tips'] = $recent;

            $stmtTop = $pdo->prepare("
                SELECT
                    CASE WHEN courier_user_id IS NOT NULL THEN courier_user_id ELSE staff_user_id END AS user_id,
                    CASE WHEN courier_user_id IS NOT NULL THEN 'courier' ELSE 'waiter' END AS target,
                    SUM(amount) AS total_amount,
                    COUNT(*) AS tips_count
                FROM order_tips
                WHERE " . implode(' AND ', $where) . "
                  AND status = 'paid'
                  AND (courier_user_id IS NOT NULL OR staff_user_id IS NOT NULL)
                GROUP BY user_id, target
                ORDER BY total_amount DESC
                LIMIT 10
            ");
            foreach ($params as $key => $value) {
                if (is_int($value)) {
                    $stmtTop->bindValue($key, $value, PDO::PARAM_INT);
                } else {
                    $stmtTop->bindValue($key, $value);
                }
            }
            $stmtTop->execute();
            $topRows = $stmtTop->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $userIds = [];
            foreach ($topRows as $row) {
                $uid = (int)($row['user_id'] ?? 0);
                if ($uid > 0) {
                    $userIds[] = $uid;
                }
            }
            $userNames = [];
            if ($userIds !== [] && function_exists('guest_history_has_table') && guest_history_has_table($pdo, 'users')) {
                $ph = implode(',', array_fill(0, count($userIds), '?'));
                $stmtUsers = $pdo->prepare("SELECT id, name FROM users WHERE id IN ({$ph})");
                $stmtUsers->execute($userIds);
                foreach (($stmtUsers->fetchAll(PDO::FETCH_ASSOC) ?: []) as $uRow) {
                    $uid = (int)($uRow['id'] ?? 0);
                    if ($uid > 0) {
                        $userNames[$uid] = trim((string)($uRow['name'] ?? ''));
                    }
                }
            }

            $top = [];
            foreach ($topRows as $row) {
                $uid = (int)($row['user_id'] ?? 0);
                if ($uid <= 0) {
                    continue;
                }
                $targetType = trim((string)($row['target'] ?? 'waiter'));
                $top[] = [
                    'user_id' => $uid,
                    'name' => ($userNames[$uid] ?? '') !== '' ? $userNames[$uid] : ('User #' . $uid),
                    'target' => $targetType,
                    'total_amount' => round((float)($row['total_amount'] ?? 0), 2),
                    'tips_count' => (int)($row['tips_count'] ?? 0),
                ];
            }
            $out['top_tipped_staff'] = $top;
        } catch (Throwable $e) {
            // keep fallback output
        }

        return $out;
    }
}


if (!function_exists('reservation_status_label')) {
    function reservation_status_label(?string $status): string
    {
        $key = strtolower(trim((string)$status));
        return [
            'pending' => 'Ожидает подтверждения',
            'confirmed' => 'Подтверждена',
            'seated' => 'Гости посажены',
            'completed' => 'Завершена',
            'cancelled' => 'Отменена',
            'canceled' => 'Отменена',
            'no_show' => 'Не пришли',
        ][$key] ?? 'Бронь';
    }
}

if (!function_exists('reservation_overlap_exists')) {
    function reservation_overlap_exists(PDO $pdo, int $restaurantId, int $tableId, string $reservationDateTime, int $durationMinutes = 120, int $excludeReservationId = 0): bool
    {
        if ($restaurantId <= 0 || $tableId <= 0 || $reservationDateTime === '') {
            return false;
        }
        if (!function_exists('guest_history_has_table') || !guest_history_has_table($pdo, 'table_reservations')) {
            return false;
        }

        $durationMinutes = max(15, min(600, $durationMinutes));
        $startTs = strtotime($reservationDateTime);
        if ($startTs === false) {
            return false;
        }
        $endAt = date('Y-m-d H:i:s', $startTs + ($durationMinutes * 60));
        $activeStatuses = ['pending', 'confirmed', 'seated'];
        $statusPlaceholders = implode(',', array_fill(0, count($activeStatuses), '?'));
        $sql = "
            SELECT id
            FROM table_reservations
            WHERE restaurant_id = ?
              AND table_id = ?
              AND status IN ({$statusPlaceholders})
              AND reservation_datetime < ?
              AND DATE_ADD(reservation_datetime, INTERVAL COALESCE(NULLIF(duration_minutes, 0), 120) MINUTE) > ?
        ";
        $params = array_merge([$restaurantId, $tableId], $activeStatuses, [$endAt, date('Y-m-d H:i:s', $startTs)]);
        if ($excludeReservationId > 0) {
            $sql .= " AND id <> ?";
            $params[] = $excludeReservationId;
        }
        $sql .= " LIMIT 1";

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return (bool)$stmt->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('reservation_table_availability')) {
    function reservation_table_availability(PDO $pdo, int $restaurantId, int $tableId, string $reservationDateTime, int $durationMinutes = 120, int $excludeReservationId = 0): array
    {
        $hasConflict = reservation_overlap_exists(
            $pdo,
            $restaurantId,
            $tableId,
            $reservationDateTime,
            $durationMinutes,
            $excludeReservationId
        );
        return [
            'available' => !$hasConflict,
            'conflict' => $hasConflict,
        ];
    }
}

if (!function_exists('reservation_create')) {
    /**
     * @param array<string,mixed> $payload
     * @return array{ok:bool,created:bool,reservation_id:int,error:?string,status:string}
     */
    function reservation_create(PDO $pdo, int $restaurantId, array $payload): array
    {
        if ($restaurantId <= 0) {
            return ['ok' => false, 'created' => false, 'reservation_id' => 0, 'error' => 'invalid_restaurant', 'status' => 'pending'];
        }
        if (!function_exists('guest_history_has_table') || !guest_history_has_table($pdo, 'table_reservations')) {
            return ['ok' => false, 'created' => false, 'reservation_id' => 0, 'error' => 'reservations_unavailable', 'status' => 'pending'];
        }

        $tableId = (int)($payload['table_id'] ?? 0);
        $guestName = trim((string)($payload['guest_name'] ?? ''));
        $guestPhone = trim((string)($payload['guest_phone'] ?? ''));
        $guestsCount = (int)($payload['guests_count'] ?? 1);
        $dateRaw = trim((string)($payload['reservation_date'] ?? ''));
        $timeRaw = trim((string)($payload['reservation_time'] ?? ''));
        $dateTimeRaw = trim((string)($payload['reservation_datetime'] ?? ''));
        $durationMinutes = (int)($payload['duration_minutes'] ?? 120);
        $comment = trim((string)($payload['comment'] ?? ''));
        $source = strtolower(trim((string)($payload['source'] ?? 'guest_web')));
        $status = strtolower(trim((string)($payload['status'] ?? 'pending')));
        $createdByUserId = (int)($payload['created_by_user_id'] ?? 0);
        $guestProfileId = (int)($payload['guest_profile_id'] ?? 0);

        if ($tableId <= 0) {
            return ['ok' => false, 'created' => false, 'reservation_id' => 0, 'error' => 'invalid_table', 'status' => 'pending'];
        }
        if ($guestName === '') {
            $guestName = 'Гость';
        } else {
            $guestName = mb_substr($guestName, 0, 190);
        }
        if ($guestPhone === '') {
            return ['ok' => false, 'created' => false, 'reservation_id' => 0, 'error' => 'phone_required', 'status' => 'pending'];
        }
        $guestsCount = max(1, min(30, $guestsCount));
        $durationMinutes = max(30, min(480, $durationMinutes));
        if (!preg_match('/^[a-z0-9._-]{2,32}$/', $source)) {
            $source = 'guest_web';
        }
        if (!in_array($status, ['pending', 'confirmed', 'seated', 'completed', 'cancelled', 'no_show'], true)) {
            $status = 'pending';
        }
        if ($comment !== '') {
            $comment = mb_substr($comment, 0, 500);
        } else {
            $comment = '';
        }

        $reservationDateTime = '';
        if ($dateTimeRaw !== '') {
            $dt = strtotime($dateTimeRaw);
            if ($dt !== false) {
                $reservationDateTime = date('Y-m-d H:i:s', $dt);
            }
        }
        if ($reservationDateTime === '' && $dateRaw !== '' && $timeRaw !== '') {
            $dt = strtotime($dateRaw . ' ' . $timeRaw);
            if ($dt !== false) {
                $reservationDateTime = date('Y-m-d H:i:s', $dt);
            }
        }
        if ($reservationDateTime === '') {
            return ['ok' => false, 'created' => false, 'reservation_id' => 0, 'error' => 'invalid_datetime', 'status' => $status];
        }
        $reservationTs = strtotime($reservationDateTime);
        if ($reservationTs === false || $reservationTs < (time() - 3600)) {
            return ['ok' => false, 'created' => false, 'reservation_id' => 0, 'error' => 'datetime_in_past', 'status' => $status];
        }
        $reservationDate = date('Y-m-d', $reservationTs);
        $reservationTime = date('H:i:s', $reservationTs);

        $phoneNormalized = guest_normalize_phone($guestPhone);
        if ($phoneNormalized === null) {
            return ['ok' => false, 'created' => false, 'reservation_id' => 0, 'error' => 'invalid_phone', 'status' => $status];
        }

        try {
            $tableSql = "
                SELECT t.id
                FROM tables t
                WHERE t.id = :table_id
                  AND t.restaurant_id = :restaurant_id
            ";
            if (function_exists('qr_public_sql_exclude_delivery')) {
                $tableSql .= ' ' . qr_public_sql_exclude_delivery($pdo, 't');
            }
            $tableSql .= ' LIMIT 1';
            $stmtTable = $pdo->prepare($tableSql);
            $stmtTable->execute([
                ':table_id' => $tableId,
                ':restaurant_id' => $restaurantId,
            ]);
            if (!$stmtTable->fetch(PDO::FETCH_ASSOC)) {
                return ['ok' => false, 'created' => false, 'reservation_id' => 0, 'error' => 'table_not_found', 'status' => $status];
            }
        } catch (Throwable $e) {
            return ['ok' => false, 'created' => false, 'reservation_id' => 0, 'error' => 'table_check_failed', 'status' => $status];
        }

        if ($guestProfileId <= 0 && function_exists('guest_history_resolve_phone')) {
            try {
                $resolved = guest_history_resolve_phone($pdo, $restaurantId, [
                    'phone' => $guestPhone,
                    'phone_normalized' => $phoneNormalized,
                    'guest_phone' => $guestPhone,
                    'customer_phone' => $guestPhone,
                    'delivery_phone' => $guestPhone,
                ]);
                if (is_array($resolved)) {
                    $resolvedProfileId = (int)($resolved['guest_profile_id'] ?? 0);
                    if ($resolvedProfileId > 0) {
                        $guestProfileId = $resolvedProfileId;
                    }
                    $resolvedPhone = guest_normalize_phone((string)($resolved['phone_normalized'] ?? ''));
                    if ($resolvedPhone !== null) {
                        $phoneNormalized = $resolvedPhone;
                    }
                }
            } catch (Throwable $e) {
                // keep reservation creation working
            }
        }

        if (reservation_overlap_exists($pdo, $restaurantId, $tableId, $reservationDateTime, $durationMinutes)) {
            return ['ok' => false, 'created' => false, 'reservation_id' => 0, 'error' => 'table_overlap', 'status' => $status];
        }

        $ownsTx = false;
        try {
            if (!$pdo->inTransaction()) {
                $pdo->beginTransaction();
                $ownsTx = true;
            }
            // second check under transaction to prevent race
            if (reservation_overlap_exists($pdo, $restaurantId, $tableId, $reservationDateTime, $durationMinutes)) {
                if ($ownsTx && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                return ['ok' => false, 'created' => false, 'reservation_id' => 0, 'error' => 'table_overlap', 'status' => $status];
            }

            $stmt = $pdo->prepare("
                INSERT INTO table_reservations (
                    restaurant_id,
                    table_id,
                    guest_profile_id,
                    phone_normalized,
                    guest_name,
                    guest_phone,
                    guests_count,
                    reservation_date,
                    reservation_time,
                    reservation_datetime,
                    duration_minutes,
                    status,
                    source,
                    comment,
                    created_by_user_id,
                    created_at,
                    updated_at
                ) VALUES (
                    :restaurant_id,
                    :table_id,
                    :guest_profile_id,
                    :phone_normalized,
                    :guest_name,
                    :guest_phone,
                    :guests_count,
                    :reservation_date,
                    :reservation_time,
                    :reservation_datetime,
                    :duration_minutes,
                    :status,
                    :source,
                    :comment,
                    :created_by_user_id,
                    NOW(),
                    NOW()
                )
            ");
            $stmt->execute([
                ':restaurant_id' => $restaurantId,
                ':table_id' => $tableId,
                ':guest_profile_id' => $guestProfileId > 0 ? $guestProfileId : null,
                ':phone_normalized' => $phoneNormalized,
                ':guest_name' => $guestName,
                ':guest_phone' => $guestPhone,
                ':guests_count' => $guestsCount,
                ':reservation_date' => $reservationDate,
                ':reservation_time' => $reservationTime,
                ':reservation_datetime' => $reservationDateTime,
                ':duration_minutes' => $durationMinutes,
                ':status' => $status,
                ':source' => $source,
                ':comment' => $comment !== '' ? $comment : null,
                ':created_by_user_id' => $createdByUserId > 0 ? $createdByUserId : null,
            ]);
            $reservationId = (int)$pdo->lastInsertId();
            if ($ownsTx && $pdo->inTransaction()) {
                $pdo->commit();
            }
            return ['ok' => true, 'created' => true, 'reservation_id' => $reservationId, 'error' => null, 'status' => $status];
        } catch (Throwable $e) {
            if ($ownsTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['ok' => false, 'created' => false, 'reservation_id' => 0, 'error' => 'write_failed', 'status' => $status];
        }
    }
}

if (!function_exists('reservation_summary')) {
    /**
     * @param array<string,mixed> $options
     * @return array{
     *   upcoming_count:int,
     *   current_count:int,
     *   no_show_count:int,
     *   occupancy_estimate:int,
     *   upcoming:list<array{id:int,table_id:int,guest_name:string,guest_phone:string,guests_count:int,reservation_datetime:string,status:string,status_label:string,minutes_until:int}>
     * }
     */
    function reservation_summary(PDO $pdo, int $restaurantId, array $options = []): array
    {
        $out = [
            'upcoming_count' => 0,
            'current_count' => 0,
            'no_show_count' => 0,
            'occupancy_estimate' => 0,
            'upcoming' => [],
        ];
        if ($restaurantId <= 0 || !function_exists('guest_history_has_table') || !guest_history_has_table($pdo, 'table_reservations')) {
            return $out;
        }

        $nowTs = time();
        $nowSql = date('Y-m-d H:i:s', $nowTs);
        $horizonMinutes = max(30, min(1440, (int)($options['horizon_minutes'] ?? 240)));
        $horizonSql = date('Y-m-d H:i:s', $nowTs + ($horizonMinutes * 60));
        $previewLimit = max(1, min(20, (int)($options['limit'] ?? 5)));

        try {
            $tableCount = 0;
            if (function_exists('guest_history_has_table') && guest_history_has_table($pdo, 'tables')) {
                $tableSql = "
                    SELECT COUNT(*) FROM tables t
                    WHERE t.restaurant_id = :restaurant_id
                ";
                if (function_exists('qr_public_sql_exclude_delivery')) {
                    $tableSql .= ' ' . qr_public_sql_exclude_delivery($pdo, 't');
                }
                $stmtTables = $pdo->prepare($tableSql);
                $stmtTables->execute([':restaurant_id' => $restaurantId]);
                $tableCount = (int)($stmtTables->fetchColumn() ?: 0);
            }

            $stmtAgg = $pdo->prepare("
                SELECT
                    SUM(CASE WHEN status IN ('pending','confirmed')
                        AND reservation_datetime > :now_upcoming
                        AND reservation_datetime <= :horizon_upcoming
                        THEN 1 ELSE 0 END) AS upcoming_count,
                    SUM(CASE WHEN status IN ('pending','confirmed','seated')
                        AND reservation_datetime <= :now_current
                        AND DATE_ADD(reservation_datetime, INTERVAL COALESCE(NULLIF(duration_minutes, 0), 120) MINUTE) >= :now_current
                        THEN 1 ELSE 0 END) AS current_count,
                    SUM(CASE WHEN status = 'no_show'
                        AND DATE(reservation_datetime) = CURRENT_DATE()
                        THEN 1 ELSE 0 END) AS no_show_count,
                    COUNT(DISTINCT CASE WHEN status IN ('pending','confirmed','seated')
                        AND reservation_datetime <= :horizon_busy
                        AND DATE_ADD(reservation_datetime, INTERVAL COALESCE(NULLIF(duration_minutes, 0), 120) MINUTE) >= :now_busy
                        THEN table_id ELSE NULL END) AS busy_tables
                FROM table_reservations
                WHERE restaurant_id = :restaurant_id
            ");
            $stmtAgg->execute([
                ':restaurant_id' => $restaurantId,
                ':now_upcoming' => $nowSql,
                ':horizon_upcoming' => $horizonSql,
                ':now_current' => $nowSql,
                ':horizon_busy' => $horizonSql,
                ':now_busy' => $nowSql,
            ]);
            $agg = $stmtAgg->fetch(PDO::FETCH_ASSOC) ?: [];
            $out['upcoming_count'] = (int)($agg['upcoming_count'] ?? 0);
            $out['current_count'] = (int)($agg['current_count'] ?? 0);
            $out['no_show_count'] = (int)($agg['no_show_count'] ?? 0);
            $busyTables = (int)($agg['busy_tables'] ?? 0);
            $out['occupancy_estimate'] = ($tableCount > 0) ? (int)round(($busyTables / $tableCount) * 100) : 0;

            $stmtUpcoming = $pdo->prepare("
                SELECT
                    id,
                    table_id,
                    guest_name,
                    guest_phone,
                    guests_count,
                    reservation_datetime,
                    status
                FROM table_reservations
                WHERE restaurant_id = :restaurant_id
                  AND status IN ('pending','confirmed','seated')
                  AND reservation_datetime >= :now_at
                ORDER BY reservation_datetime ASC, id ASC
                LIMIT {$previewLimit}
            ");
            $stmtUpcoming->execute([
                ':restaurant_id' => $restaurantId,
                ':now_at' => $nowSql,
            ]);
            $rows = $stmtUpcoming->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $upcoming = [];
            foreach ($rows as $row) {
                $dtRaw = (string)($row['reservation_datetime'] ?? '');
                $dtTs = strtotime($dtRaw);
                $minutesUntil = ($dtTs !== false) ? (int)floor(($dtTs - $nowTs) / 60) : 0;
                $status = (string)($row['status'] ?? 'pending');
                $upcoming[] = [
                    'id' => (int)($row['id'] ?? 0),
                    'table_id' => (int)($row['table_id'] ?? 0),
                    'guest_name' => trim((string)($row['guest_name'] ?? '')),
                    'guest_phone' => trim((string)($row['guest_phone'] ?? '')),
                    'guests_count' => (int)($row['guests_count'] ?? 1),
                    'reservation_datetime' => $dtRaw,
                    'status' => $status,
                    'status_label' => reservation_status_label($status),
                    'minutes_until' => $minutesUntil,
                ];
            }
            $out['upcoming'] = $upcoming;
        } catch (Throwable $e) {
            return $out;
        }

        return $out;
    }
}

if (!function_exists('analytics_period_bounds')) {
    /**
     * @return array{
     *   range_key:string,
     *   label:string,
     *   start_at:string,
     *   end_at:string,
     *   prev_start_at:string,
     *   prev_end_at:string,
     *   duration_seconds:int
     * }
     */
    function analytics_period_bounds(?string $range, ?string $from = null, ?string $to = null): array
    {
        $key = strtolower(trim((string)$range));
        $now = new DateTimeImmutable('now');
        $label = 'Сегодня';
        $start = $now->setTime(0, 0, 0);
        $end = $now;

        if ($key === 'yesterday') {
            $label = 'Вчера';
            $y = $now->modify('-1 day');
            $start = $y->setTime(0, 0, 0);
            $end = $y->setTime(23, 59, 59);
        } elseif ($key === 'week') {
            $label = '7 дней';
            $start = $now->modify('-6 days')->setTime(0, 0, 0);
            $end = $now;
        } elseif ($key === 'month') {
            $label = 'Текущий месяц';
            $start = $now->modify('first day of this month')->setTime(0, 0, 0);
            $end = $now;
        } elseif ($key === 'custom') {
            $label = 'Кастомный период';
            $fromRaw = trim((string)$from);
            $toRaw = trim((string)$to);
            $fromDt = null;
            $toDt = null;

            if ($fromRaw !== '') {
                $fromTs = strtotime($fromRaw);
                if ($fromTs !== false) {
                    $fromDt = (new DateTimeImmutable())->setTimestamp($fromTs);
                }
            }
            if ($toRaw !== '') {
                $toTs = strtotime($toRaw);
                if ($toTs !== false) {
                    $toDt = (new DateTimeImmutable())->setTimestamp($toTs);
                }
            }
            if ($fromDt instanceof DateTimeImmutable && $toDt instanceof DateTimeImmutable) {
                $start = $fromDt->setTime(0, 0, 0);
                $end = $toDt->setTime(23, 59, 59);
                if ($end < $start) {
                    $tmp = $start;
                    $start = $end;
                    $end = $tmp;
                }
                $maxWindowSeconds = 180 * 86400;
                if (($end->getTimestamp() - $start->getTimestamp()) > $maxWindowSeconds) {
                    $start = $end->modify('-180 days')->setTime(0, 0, 0);
                }
            } else {
                $key = 'today';
                $label = 'Сегодня';
                $start = $now->setTime(0, 0, 0);
                $end = $now;
            }
        } else {
            $key = 'today';
            $label = 'Сегодня';
        }

        $durationSeconds = max(1, ($end->getTimestamp() - $start->getTimestamp()) + 1);
        $prevEndTs = $start->getTimestamp() - 1;
        $prevStartTs = $prevEndTs - $durationSeconds + 1;
        $prevStart = (new DateTimeImmutable())->setTimestamp($prevStartTs);
        $prevEnd = (new DateTimeImmutable())->setTimestamp($prevEndTs);

        return [
            'range_key' => $key,
            'label' => $label,
            'start_at' => $start->format('Y-m-d H:i:s'),
            'end_at' => $end->format('Y-m-d H:i:s'),
            'prev_start_at' => $prevStart->format('Y-m-d H:i:s'),
            'prev_end_at' => $prevEnd->format('Y-m-d H:i:s'),
            'duration_seconds' => $durationSeconds,
        ];
    }
}

if (!function_exists('analytics_trend_delta')) {
    /**
     * @return array{current:float,previous:float,delta:float,delta_percent:float,direction:string}
     */
    function analytics_trend_delta(float $current, float $previous): array
    {
        $delta = $current - $previous;
        $deltaPercent = 0.0;
        if (abs($previous) > 0.00001) {
            $deltaPercent = ($delta / $previous) * 100.0;
        } elseif (abs($current) > 0.00001) {
            $deltaPercent = 100.0;
        }
        $direction = 'flat';
        if ($delta > 0.00001) {
            $direction = 'up';
        } elseif ($delta < -0.00001) {
            $direction = 'down';
        }
        return [
            'current' => round($current, 2),
            'previous' => round($previous, 2),
            'delta' => round($delta, 2),
            'delta_percent' => round($deltaPercent, 2),
            'direction' => $direction,
        ];
    }
}

if (!function_exists('analytics_order_amount_expr')) {
    function analytics_order_amount_expr(PDO $pdo, string $alias = 'o'): string
    {
        $parts = [];
        if (function_exists('db_column_exists') && db_column_exists('orders', 'total_amount')) {
            $parts[] = "{$alias}.total_amount";
        }
        if (function_exists('db_column_exists') && db_column_exists('orders', 'total_price')) {
            $parts[] = "{$alias}.total_price";
        }
        if (function_exists('db_column_exists') && db_column_exists('orders', 'total')) {
            $parts[] = "{$alias}.total";
        }
        if (function_exists('db_column_exists') && db_column_exists('orders', 'subtotal')) {
            $parts[] = "{$alias}.subtotal";
        }
        if ($parts === []) {
            return '0';
        }
        return 'COALESCE(' . implode(', ', $parts) . ', 0)';
    }
}

if (!function_exists('analytics_orders_summary')) {
    /**
     * @param array<string,mixed> $period
     * @return array{
     *   orders_count:int,
     *   paid_orders_count:int,
     *   revenue:float,
     *   average_check:float,
     *   types:array<string,array{count:int,revenue:float,label:string}>,
     *   canceled_count:int
     * }
     */
    function analytics_orders_summary(PDO $pdo, int $restaurantId, array $period): array
    {
        $out = [
            'orders_count' => 0,
            'paid_orders_count' => 0,
            'revenue' => 0.0,
            'average_check' => 0.0,
            'types' => [
                'hall' => ['count' => 0, 'revenue' => 0.0, 'label' => order_type_label('hall', 0)],
                'delivery' => ['count' => 0, 'revenue' => 0.0, 'label' => order_type_label('delivery', 0)],
                'pickup' => ['count' => 0, 'revenue' => 0.0, 'label' => order_type_label('pickup', 0)],
                'preorder' => ['count' => 0, 'revenue' => 0.0, 'label' => order_type_label('preorder', 0)],
                'manual' => ['count' => 0, 'revenue' => 0.0, 'label' => order_type_label('manual', 0)],
            ],
            'canceled_count' => 0,
        ];
        if ($restaurantId <= 0 || !function_exists('db_table_exists') || !db_table_exists('orders')) {
            return $out;
        }

        $hasOrderType = function_exists('db_column_exists') && db_column_exists('orders', 'order_type');
        $hasTableId = function_exists('db_column_exists') && db_column_exists('orders', 'table_id');
        $hasPaymentStatus = function_exists('db_column_exists') && db_column_exists('orders', 'payment_status');
        $hasOrderStatus = function_exists('db_column_exists') && db_column_exists('orders', 'order_status');
        $amountExpr = analytics_order_amount_expr($pdo, 'o');

        $orderTypeSql = $hasOrderType ? 'o.order_type AS order_type' : 'NULL AS order_type';
        $tableIdSql = $hasTableId ? 'o.table_id AS table_id' : 'NULL AS table_id';
        $paymentStatusSql = $hasPaymentStatus ? 'o.payment_status AS payment_status' : "'paid' AS payment_status";
        $orderStatusSql = $hasOrderStatus ? 'o.order_status AS order_status' : "'new' AS order_status";
        $statusFilter = $hasOrderStatus ? "AND (o.order_status IS NULL OR (o.order_status <> 'canceled' AND o.order_status <> 'cancelled'))" : '';

        try {
            $stmt = $pdo->prepare("
                SELECT
                    o.id,
                    {$orderTypeSql},
                    {$tableIdSql},
                    {$paymentStatusSql},
                    {$orderStatusSql},
                    {$amountExpr} AS amount_value
                FROM orders o
                WHERE o.restaurant_id = :restaurant_id
                  AND o.created_at >= :start_at
                  AND o.created_at <= :end_at
                  {$statusFilter}
            ");
            $stmt->execute([
                ':restaurant_id' => $restaurantId,
                ':start_at' => (string)($period['start_at'] ?? ''),
                ':end_at' => (string)($period['end_at'] ?? ''),
            ]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            foreach ($rows as $row) {
                $out['orders_count']++;
                $amount = (float)($row['amount_value'] ?? 0);
                $paymentStatus = strtolower(trim((string)($row['payment_status'] ?? 'paid')));
                $orderStatus = strtolower(trim((string)($row['order_status'] ?? 'new')));
                if ($orderStatus === 'canceled' || $orderStatus === 'cancelled') {
                    $out['canceled_count']++;
                    continue;
                }
                $tableId = isset($row['table_id']) ? (int)$row['table_id'] : null;
                $type = order_type_normalize((string)($row['order_type'] ?? ''), $tableId);
                if (!isset($out['types'][$type])) {
                    $out['types'][$type] = ['count' => 0, 'revenue' => 0.0, 'label' => order_type_label($type, $tableId)];
                }
                $out['types'][$type]['count']++;

                $isPaid = ($paymentStatus === 'paid') || !$hasPaymentStatus;
                if ($isPaid) {
                    $out['paid_orders_count']++;
                    $out['revenue'] += $amount;
                    $out['types'][$type]['revenue'] += $amount;
                }
            }

            $out['revenue'] = round($out['revenue'], 2);
            foreach ($out['types'] as $typeKey => $typeRow) {
                $out['types'][$typeKey]['revenue'] = round((float)$typeRow['revenue'], 2);
            }
            $paidCount = max(0, (int)$out['paid_orders_count']);
            $out['average_check'] = $paidCount > 0 ? round($out['revenue'] / $paidCount, 2) : 0.0;
        } catch (Throwable $e) {
            return $out;
        }

        return $out;
    }
}

if (!function_exists('analytics_revenue_summary')) {
    /**
     * @param array<string,mixed> $period
     * @return array{
     *   revenue:float,
     *   average_check:float,
     *   paid_orders_count:int,
     *   delivery_revenue:float,
     *   hall_revenue:float,
     *   pickup_revenue:float,
     *   preorder_revenue:float,
     *   manual_revenue:float
     * }
     */
    function analytics_revenue_summary(PDO $pdo, int $restaurantId, array $period): array
    {
        $orders = analytics_orders_summary($pdo, $restaurantId, $period);
        return [
            'revenue' => (float)($orders['revenue'] ?? 0),
            'average_check' => (float)($orders['average_check'] ?? 0),
            'paid_orders_count' => (int)($orders['paid_orders_count'] ?? 0),
            'delivery_revenue' => (float)($orders['types']['delivery']['revenue'] ?? 0),
            'hall_revenue' => (float)($orders['types']['hall']['revenue'] ?? 0),
            'pickup_revenue' => (float)($orders['types']['pickup']['revenue'] ?? 0),
            'preorder_revenue' => (float)($orders['types']['preorder']['revenue'] ?? 0),
            'manual_revenue' => (float)($orders['types']['manual']['revenue'] ?? 0),
        ];
    }
}

if (!function_exists('analytics_delivery_summary')) {
    /**
     * @param array<string,mixed> $period
     * @return array{
     *   delivery_orders_count:int,
     *   delivery_revenue:float,
     *   active_delivery_count:int,
     *   overdue_delivery_count:int,
     *   waiting_courier_count:int,
     *   on_the_way_count:int,
     *   delivered_count:int,
     *   avg_delivery_minutes:int,
     *   avg_pickup_minutes:int
     * }
     */
    function analytics_delivery_summary(PDO $pdo, int $restaurantId, array $period): array
    {
        $out = [
            'delivery_orders_count' => 0,
            'delivery_revenue' => 0.0,
            'active_delivery_count' => 0,
            'overdue_delivery_count' => 0,
            'waiting_courier_count' => 0,
            'on_the_way_count' => 0,
            'delivered_count' => 0,
            'avg_delivery_minutes' => 0,
            'avg_pickup_minutes' => 0,
        ];
        if ($restaurantId <= 0 || !function_exists('db_table_exists') || !db_table_exists('orders')) {
            return $out;
        }

        $hasOrderType = function_exists('db_column_exists') && db_column_exists('orders', 'order_type');
        $hasTableId = function_exists('db_column_exists') && db_column_exists('orders', 'table_id');
        $hasPaymentStatus = function_exists('db_column_exists') && db_column_exists('orders', 'payment_status');
        $hasOrderStatus = function_exists('db_column_exists') && db_column_exists('orders', 'order_status');
        $hasCourierStatus = function_exists('db_column_exists') && db_column_exists('orders', 'courier_status');
        $hasTakenAt = function_exists('db_column_exists') && db_column_exists('orders', 'courier_taken_at');
        $hasOnTheWayAt = function_exists('db_column_exists') && db_column_exists('orders', 'courier_on_the_way_at');
        $hasDeliveredAt = function_exists('db_column_exists') && db_column_exists('orders', 'delivered_at');
        $amountExpr = analytics_order_amount_expr($pdo, 'o');

        $orderTypeSql = $hasOrderType ? 'o.order_type AS order_type' : 'NULL AS order_type';
        $tableIdSql = $hasTableId ? 'o.table_id AS table_id' : 'NULL AS table_id';
        $paymentStatusSql = $hasPaymentStatus ? 'o.payment_status AS payment_status' : "'paid' AS payment_status";
        $orderStatusSql = $hasOrderStatus ? 'o.order_status AS order_status' : "'new' AS order_status";
        $courierStatusSql = $hasCourierStatus ? 'o.courier_status AS courier_status' : 'NULL AS courier_status';
        $takenAtSql = $hasTakenAt ? 'o.courier_taken_at AS courier_taken_at' : 'NULL AS courier_taken_at';
        $onWayAtSql = $hasOnTheWayAt ? 'o.courier_on_the_way_at AS courier_on_the_way_at' : 'NULL AS courier_on_the_way_at';
        $deliveredAtSql = $hasDeliveredAt ? 'o.delivered_at AS delivered_at' : 'NULL AS delivered_at';
        $statusFilter = $hasOrderStatus ? "AND (o.order_status IS NULL OR (o.order_status <> 'canceled' AND o.order_status <> 'cancelled'))" : '';
        $deliveryFilter = $hasOrderType ? "AND o.order_type = 'delivery'" : '';

        try {
            $stmt = $pdo->prepare("
                SELECT
                    o.id,
                    {$orderTypeSql},
                    {$tableIdSql},
                    {$paymentStatusSql},
                    {$orderStatusSql},
                    {$courierStatusSql},
                    {$takenAtSql},
                    {$onWayAtSql},
                    {$deliveredAtSql},
                    o.created_at,
                    {$amountExpr} AS amount_value
                FROM orders o
                WHERE o.restaurant_id = :restaurant_id
                  AND o.created_at >= :start_at
                  AND o.created_at <= :end_at
                  {$statusFilter}
                  {$deliveryFilter}
            ");
            $stmt->execute([
                ':restaurant_id' => $restaurantId,
                ':start_at' => (string)($period['start_at'] ?? ''),
                ':end_at' => (string)($period['end_at'] ?? ''),
            ]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $totalDeliveryMinutes = 0;
            $totalDeliveryRows = 0;
            $totalPickupMinutes = 0;
            $totalPickupRows = 0;

            foreach ($rows as $row) {
                $tableId = isset($row['table_id']) ? (int)$row['table_id'] : null;
                $type = order_type_normalize((string)($row['order_type'] ?? ''), $tableId);
                if ($type !== 'delivery') {
                    continue;
                }
                $out['delivery_orders_count']++;
                $paymentStatus = strtolower(trim((string)($row['payment_status'] ?? 'paid')));
                if ($paymentStatus === 'paid' || !$hasPaymentStatus) {
                    $out['delivery_revenue'] += (float)($row['amount_value'] ?? 0);
                }

                $courierStatus = function_exists('courier_status_normalize')
                    ? courier_status_normalize((string)($row['courier_status'] ?? ''), $type)
                    : strtolower(trim((string)($row['courier_status'] ?? '')));
                $courierStatus = $courierStatus !== '' ? $courierStatus : 'waiting_courier';
                if (in_array($courierStatus, ['waiting_courier', 'handed_to_courier', 'on_the_way'], true)) {
                    $out['active_delivery_count']++;
                }
                if ($courierStatus === 'waiting_courier') {
                    $out['waiting_courier_count']++;
                }
                if ($courierStatus === 'on_the_way') {
                    $out['on_the_way_count']++;
                }
                if ($courierStatus === 'delivered') {
                    $out['delivered_count']++;
                }

                if (function_exists('courier_order_sla_meta')) {
                    $sla = courier_order_sla_meta($row);
                    if (in_array($courierStatus, ['waiting_courier', 'handed_to_courier', 'on_the_way'], true) && (($sla['level'] ?? '') === 'critical')) {
                        $out['overdue_delivery_count']++;
                    }
                }

                $createdTs = strtotime((string)($row['created_at'] ?? ''));
                $takenTs = strtotime((string)($row['courier_taken_at'] ?? ''));
                $deliveredTs = strtotime((string)($row['delivered_at'] ?? ''));
                if ($createdTs !== false && $takenTs !== false && $createdTs > 0 && $takenTs >= $createdTs) {
                    $totalPickupMinutes += max(0, (int)floor(($takenTs - $createdTs) / 60));
                    $totalPickupRows++;
                }
                if ($createdTs !== false && $deliveredTs !== false && $createdTs > 0 && $deliveredTs >= $createdTs) {
                    $totalDeliveryMinutes += max(0, (int)floor(($deliveredTs - $createdTs) / 60));
                    $totalDeliveryRows++;
                }
            }

            $out['delivery_revenue'] = round($out['delivery_revenue'], 2);
            $out['avg_delivery_minutes'] = $totalDeliveryRows > 0 ? (int)round($totalDeliveryMinutes / $totalDeliveryRows) : 0;
            $out['avg_pickup_minutes'] = $totalPickupRows > 0 ? (int)round($totalPickupMinutes / $totalPickupRows) : 0;
        } catch (Throwable $e) {
            return $out;
        }

        return $out;
    }
}

if (!function_exists('delivery_analytics_safe_time_diff_minutes')) {
    function delivery_analytics_safe_time_diff_minutes(?string $from, ?string $to): ?int
    {
        $fromRaw = trim((string)($from ?? ''));
        $toRaw = trim((string)($to ?? ''));
        if ($fromRaw === '' || $toRaw === '') {
            return null;
        }
        $fromTs = strtotime($fromRaw);
        $toTs = strtotime($toRaw);
        if ($fromTs === false || $toTs === false || $toTs < $fromTs) {
            return null;
        }
        return max(0, (int)floor(($toTs - $fromTs) / 60));
    }
}

if (!function_exists('delivery_analytics_dataset')) {
    /**
     * @param array<string,mixed> $period
     * @return array{
     *   rows:list<array<string,mixed>>,
     *   totals:array<string,mixed>,
     *   period:array<string,mixed>
     * }
     */
    function delivery_analytics_dataset(PDO $pdo, int $restaurantId, array $period): array
    {
        static $cache = [];
        $cacheKey = $restaurantId . ':' . md5(json_encode([
            'start_at' => (string)($period['start_at'] ?? ''),
            'end_at' => (string)($period['end_at'] ?? ''),
        ]));
        if (isset($cache[$cacheKey]) && is_array($cache[$cacheKey])) {
            /** @var array{rows:list<array<string,mixed>>,totals:array<string,mixed>,period:array<string,mixed>} */
            return $cache[$cacheKey];
        }

        $empty = [
            'rows' => [],
            'totals' => [
                'orders_count' => 0,
                'paid_revenue' => 0.0,
                'delivered_count' => 0,
                'active_count' => 0,
            ],
            'period' => $period,
        ];
        if (
            $restaurantId <= 0
            || !function_exists('db_table_exists')
            || !db_table_exists('orders')
        ) {
            $cache[$cacheKey] = $empty;
            return $empty;
        }

        $hasOrderType = function_exists('db_column_exists') && db_column_exists('orders', 'order_type');
        $hasTableId = function_exists('db_column_exists') && db_column_exists('orders', 'table_id');
        $hasPaymentStatus = function_exists('db_column_exists') && db_column_exists('orders', 'payment_status');
        $hasOrderStatus = function_exists('db_column_exists') && db_column_exists('orders', 'order_status');
        $hasCourierStatus = function_exists('db_column_exists') && db_column_exists('orders', 'courier_status');
        $hasCourierUserId = function_exists('db_column_exists') && db_column_exists('orders', 'courier_user_id');
        $hasTakenAt = function_exists('db_column_exists') && db_column_exists('orders', 'courier_taken_at');
        $hasOnWayAt = function_exists('db_column_exists') && db_column_exists('orders', 'courier_on_the_way_at');
        $hasDeliveredAt = function_exists('db_column_exists') && db_column_exists('orders', 'delivered_at');
        $hasDeliveryAddress = function_exists('db_column_exists') && db_column_exists('orders', 'delivery_address');
        $hasDeliveryZoneKey = function_exists('db_column_exists') && db_column_exists('orders', 'delivery_zone_key');
        $amountExpr = analytics_order_amount_expr($pdo, 'o');

        $orderTypeSql = $hasOrderType ? 'o.order_type AS order_type' : 'NULL AS order_type';
        $tableIdSql = $hasTableId ? 'o.table_id AS table_id' : 'NULL AS table_id';
        $paymentStatusSql = $hasPaymentStatus ? 'o.payment_status AS payment_status' : "'paid' AS payment_status";
        $orderStatusSql = $hasOrderStatus ? 'o.order_status AS order_status' : "'new' AS order_status";
        $courierStatusSql = $hasCourierStatus ? 'o.courier_status AS courier_status' : 'NULL AS courier_status';
        $courierUserIdSql = $hasCourierUserId ? 'o.courier_user_id AS courier_user_id' : 'NULL AS courier_user_id';
        $takenAtSql = $hasTakenAt ? 'o.courier_taken_at AS courier_taken_at' : 'NULL AS courier_taken_at';
        $onWayAtSql = $hasOnWayAt ? 'o.courier_on_the_way_at AS courier_on_the_way_at' : 'NULL AS courier_on_the_way_at';
        $deliveredAtSql = $hasDeliveredAt ? 'o.delivered_at AS delivered_at' : 'NULL AS delivered_at';
        $addressSql = $hasDeliveryAddress ? 'o.delivery_address AS delivery_address' : "NULL AS delivery_address";
        $zoneKeySql = $hasDeliveryZoneKey ? 'o.delivery_zone_key AS delivery_zone_key' : "NULL AS delivery_zone_key";
        $statusFilter = $hasOrderStatus ? "AND (o.order_status IS NULL OR (o.order_status <> 'canceled' AND o.order_status <> 'cancelled'))" : '';

        $rows = [];
        $totals = [
            'orders_count' => 0,
            'paid_revenue' => 0.0,
            'delivered_count' => 0,
            'active_count' => 0,
        ];
        try {
            $stmt = $pdo->prepare("
                SELECT
                    o.id,
                    {$orderTypeSql},
                    {$tableIdSql},
                    {$paymentStatusSql},
                    {$orderStatusSql},
                    {$courierStatusSql},
                    {$courierUserIdSql},
                    {$takenAtSql},
                    {$onWayAtSql},
                    {$deliveredAtSql},
                    {$addressSql},
                    {$zoneKeySql},
                    o.created_at,
                    {$amountExpr} AS amount_value
                FROM orders o
                WHERE o.restaurant_id = :restaurant_id
                  AND o.created_at >= :start_at
                  AND o.created_at <= :end_at
                  {$statusFilter}
                ORDER BY o.created_at DESC
                LIMIT 5000
            ");
            $stmt->execute([
                ':restaurant_id' => $restaurantId,
                ':start_at' => (string)($period['start_at'] ?? ''),
                ':end_at' => (string)($period['end_at'] ?? ''),
            ]);
            $rawRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            foreach ($rawRows as $row) {
                $tableId = isset($row['table_id']) ? (int)$row['table_id'] : null;
                $type = order_type_normalize((string)($row['order_type'] ?? ''), $tableId);
                if ($type !== 'delivery') {
                    continue;
                }
                $courierStatus = function_exists('courier_status_normalize')
                    ? courier_status_normalize((string)($row['courier_status'] ?? ''), 'delivery')
                    : strtolower(trim((string)($row['courier_status'] ?? '')));
                $courierStatus = $courierStatus !== '' ? $courierStatus : 'waiting_courier';

                $amount = (float)($row['amount_value'] ?? 0);
                $paymentStatus = strtolower(trim((string)($row['payment_status'] ?? 'paid')));
                $isPaid = !$hasPaymentStatus || $paymentStatus === 'paid';
                $zoneKeyRaw = strtolower(trim((string)($row['delivery_zone_key'] ?? '')));
                $zoneKey = $zoneKeyRaw !== '' ? $zoneKeyRaw : 'unknown';
                $address = trim((string)($row['delivery_address'] ?? ''));
                $bucket = function_exists('delivery_dispatch_address_bucket')
                    ? delivery_dispatch_address_bucket($address)
                    : ($address !== '' ? mb_strtolower($address, 'UTF-8') : 'unknown');

                $rowPrepared = [
                    'id' => (int)($row['id'] ?? 0),
                    'order_type' => $type,
                    'order_status' => strtolower(trim((string)($row['order_status'] ?? 'new'))),
                    'payment_status' => $paymentStatus,
                    'courier_status' => $courierStatus,
                    'courier_user_id' => (int)($row['courier_user_id'] ?? 0),
                    'created_at' => (string)($row['created_at'] ?? ''),
                    'courier_taken_at' => (string)($row['courier_taken_at'] ?? ''),
                    'courier_on_the_way_at' => (string)($row['courier_on_the_way_at'] ?? ''),
                    'delivered_at' => (string)($row['delivered_at'] ?? ''),
                    'delivery_address' => $address,
                    'delivery_zone_key' => $zoneKey,
                    'delivery_bucket' => $bucket !== '' ? $bucket : 'unknown',
                    'amount_value' => $amount,
                    'is_paid' => $isPaid,
                ];
                $rowPrepared['dispatch_minutes'] = delivery_analytics_safe_time_diff_minutes(
                    $rowPrepared['created_at'],
                    $rowPrepared['courier_taken_at'] !== '' ? $rowPrepared['courier_taken_at'] : null
                );
                $rowPrepared['pickup_minutes'] = delivery_analytics_safe_time_diff_minutes(
                    $rowPrepared['courier_taken_at'] !== '' ? $rowPrepared['courier_taken_at'] : $rowPrepared['created_at'],
                    $rowPrepared['courier_on_the_way_at'] !== '' ? $rowPrepared['courier_on_the_way_at'] : null
                );
                $rowPrepared['delivery_minutes'] = delivery_analytics_safe_time_diff_minutes(
                    $rowPrepared['created_at'],
                    $rowPrepared['delivered_at'] !== '' ? $rowPrepared['delivered_at'] : null
                );

                $rows[] = $rowPrepared;
                $totals['orders_count']++;
                if ($isPaid) {
                    $totals['paid_revenue'] += $amount;
                }
                if ($courierStatus === 'delivered') {
                    $totals['delivered_count']++;
                }
                if (in_array($courierStatus, ['waiting_courier', 'handed_to_courier', 'on_the_way'], true)) {
                    $totals['active_count']++;
                }
            }
        } catch (Throwable $e) {
            $cache[$cacheKey] = $empty;
            return $empty;
        }

        $totals['paid_revenue'] = round((float)$totals['paid_revenue'], 2);
        $payload = [
            'rows' => $rows,
            'totals' => $totals,
            'period' => $period,
        ];
        $cache[$cacheKey] = $payload;
        return $payload;
    }
}

if (!function_exists('delivery_heatmap_summary')) {
    /**
     * @param array<string,mixed> $period
     * @param array<string,mixed> $options
     * @return array{
     *   total_delivery_orders:int,
     *   district_density:list<array{bucket:string,count:int,share_percent:float}>,
     *   zone_density:list<array{zone_key:string,count:int,share_percent:float}>,
     *   delivery_buckets:list<array{bucket:string,count:int,share_percent:float}>,
     *   time_concentration:list<array{hour:int,count:int,share_percent:float}>,
     *   top_hours:list<int>
     * }
     */
    function delivery_heatmap_summary(PDO $pdo, int $restaurantId, array $period, array $options = []): array
    {
        $out = [
            'total_delivery_orders' => 0,
            'district_density' => [],
            'zone_density' => [],
            'delivery_buckets' => [],
            'time_concentration' => [],
            'top_hours' => [],
        ];
        $dataset = delivery_analytics_dataset($pdo, $restaurantId, $period);
        $rows = is_array($dataset['rows'] ?? null) ? $dataset['rows'] : [];
        if ($rows === []) {
            return $out;
        }
        $out['total_delivery_orders'] = (int)($dataset['totals']['orders_count'] ?? count($rows));
        $total = max(1, $out['total_delivery_orders']);

        $bucketMap = [];
        $zoneMap = [];
        $hours = array_fill(0, 24, 0);
        foreach ($rows as $row) {
            $bucket = trim((string)($row['delivery_bucket'] ?? 'unknown'));
            $bucket = $bucket !== '' ? $bucket : 'unknown';
            $zone = strtolower(trim((string)($row['delivery_zone_key'] ?? 'unknown')));
            $zone = $zone !== '' ? $zone : 'unknown';
            $bucketMap[$bucket] = ($bucketMap[$bucket] ?? 0) + 1;
            $zoneMap[$zone] = ($zoneMap[$zone] ?? 0) + 1;
            $h = (int)date('G', strtotime((string)($row['created_at'] ?? 'now')));
            if ($h >= 0 && $h <= 23) {
                $hours[$h]++;
            }
        }
        arsort($bucketMap);
        arsort($zoneMap);

        foreach ($bucketMap as $bucket => $cnt) {
            $out['district_density'][] = [
                'bucket' => (string)$bucket,
                'count' => (int)$cnt,
                'share_percent' => round(((float)$cnt * 100.0) / $total, 2),
            ];
        }
        foreach ($zoneMap as $zone => $cnt) {
            $out['zone_density'][] = [
                'zone_key' => (string)$zone,
                'count' => (int)$cnt,
                'share_percent' => round(((float)$cnt * 100.0) / $total, 2),
            ];
        }
        $out['delivery_buckets'] = $out['district_density'];

        $timeRows = [];
        foreach ($hours as $hour => $cnt) {
            $timeRows[] = [
                'hour' => (int)$hour,
                'count' => (int)$cnt,
                'share_percent' => round(((float)$cnt * 100.0) / $total, 2),
            ];
        }
        usort($timeRows, static function (array $a, array $b): int {
            $ac = (int)($a['count'] ?? 0);
            $bc = (int)($b['count'] ?? 0);
            if ($ac !== $bc) {
                return $bc <=> $ac;
            }
            return (int)($a['hour'] ?? 0) <=> (int)($b['hour'] ?? 0);
        });
        $out['time_concentration'] = $timeRows;
        $topHours = [];
        foreach (array_slice($timeRows, 0, 3) as $row) {
            $topHours[] = (int)($row['hour'] ?? 0);
        }
        $out['top_hours'] = $topHours;
        return $out;
    }
}

if (!function_exists('delivery_zone_analytics')) {
    /**
     * @param array<string,mixed> $period
     * @param array<string,mixed> $options
     * @return array{
     *   rows:list<array<string,mixed>>,
     *   totals:array<string,mixed>
     * }
     */
    function delivery_zone_analytics(PDO $pdo, int $restaurantId, array $period, array $options = []): array
    {
        $out = ['rows' => [], 'totals' => ['zones_count' => 0, 'orders_count' => 0, 'revenue' => 0.0, 'overloaded_zones' => 0]];
        $dataset = delivery_analytics_dataset($pdo, $restaurantId, $period);
        $rows = is_array($dataset['rows'] ?? null) ? $dataset['rows'] : [];
        if ($rows === []) {
            return $out;
        }
        $zonesList = function_exists('delivery_zone_list') ? delivery_zone_list($pdo, $restaurantId) : [];
        $zoneMetaByKey = [];
        foreach ($zonesList as $z) {
            $k = strtolower(trim((string)($z['zone_key'] ?? '')));
            if ($k !== '') {
                $zoneMetaByKey[$k] = $z;
            }
        }

        $zoneGroups = [];
        foreach ($rows as $row) {
            $zone = strtolower(trim((string)($row['delivery_zone_key'] ?? 'unknown')));
            $zone = $zone !== '' ? $zone : 'unknown';
            if (!isset($zoneGroups[$zone])) {
                $zoneGroups[$zone] = [];
            }
            $zoneGroups[$zone][] = $row;
        }

        $zoneRows = [];
        $overloaded = 0;
        foreach ($zoneGroups as $zoneKey => $zoneOrders) {
            $ordersCount = count($zoneOrders);
            $deliveredCount = 0;
            $activeCount = 0;
            $overdueCount = 0;
            $revenue = 0.0;
            $deliveryMinSum = 0;
            $deliveryMinRows = 0;
            $dispatchMinSum = 0;
            $dispatchMinRows = 0;
            $batchingReady = 0;
            $couriers = [];

            usort($zoneOrders, static function (array $a, array $b): int {
                $ad = strtotime((string)($a['delivered_at'] ?? '')) ?: 0;
                $bd = strtotime((string)($b['delivered_at'] ?? '')) ?: 0;
                return $ad <=> $bd;
            });
            $prevDeliveredTs = 0;
            foreach ($zoneOrders as $row) {
                $status = (string)($row['courier_status'] ?? 'waiting_courier');
                if ($row['is_paid'] ?? false) {
                    $revenue += (float)($row['amount_value'] ?? 0);
                }
                if (in_array($status, ['waiting_courier', 'handed_to_courier', 'on_the_way'], true)) {
                    $activeCount++;
                }
                if ($status === 'delivered') {
                    $deliveredCount++;
                    $deliveryMin = $row['delivery_minutes'] ?? null;
                    if (is_int($deliveryMin)) {
                        $deliveryMinSum += $deliveryMin;
                        $deliveryMinRows++;
                        if ($deliveryMin > 35) {
                            $overdueCount++;
                        }
                    }
                    $deliveredTs = strtotime((string)($row['delivered_at'] ?? '')) ?: 0;
                    if ($deliveredTs > 0 && $prevDeliveredTs > 0 && (($deliveredTs - $prevDeliveredTs) <= 1800)) {
                        $batchingReady++;
                    }
                    if ($deliveredTs > 0) {
                        $prevDeliveredTs = $deliveredTs;
                    }
                } elseif (function_exists('courier_order_sla_meta')) {
                    $sla = courier_order_sla_meta($row);
                    if (($sla['level'] ?? '') === 'critical') {
                        $overdueCount++;
                    }
                }
                $dispatchMin = $row['dispatch_minutes'] ?? null;
                if (is_int($dispatchMin)) {
                    $dispatchMinSum += $dispatchMin;
                    $dispatchMinRows++;
                }
                $courierId = (int)($row['courier_user_id'] ?? 0);
                if ($courierId > 0) {
                    $couriers[$courierId] = true;
                }
            }

            $avgDelivery = $deliveryMinRows > 0 ? (int)round($deliveryMinSum / $deliveryMinRows) : null;
            $avgDispatch = $dispatchMinRows > 0 ? (int)round($dispatchMinSum / $dispatchMinRows) : null;
            $slaPercent = $deliveredCount > 0 ? round(((float)max(0, $deliveredCount - $overdueCount) * 100.0) / $deliveredCount, 2) : 100.0;
            $coverage = count($couriers);
            $isOverloaded = ($activeCount >= 4) || ($overdueCount >= 2);
            if ($isOverloaded) {
                $overloaded++;
            }
            $meta = $zoneMetaByKey[$zoneKey] ?? [];
            $zoneRows[] = [
                'zone_key' => $zoneKey,
                'zone_label' => (string)($meta['zone_name'] ?? $zoneKey),
                'zone_color' => (string)($meta['color'] ?? '#94A3B8'),
                'orders_count' => $ordersCount,
                'delivered_count' => $deliveredCount,
                'active_count' => $activeCount,
                'revenue' => round($revenue, 2),
                'avg_delivery_minutes' => $avgDelivery,
                'avg_dispatch_minutes' => $avgDispatch,
                'overdue_count' => $overdueCount,
                'sla_success_percent' => $slaPercent,
                'batching_opportunity_count' => $batchingReady,
                'courier_coverage_count' => $coverage,
                'is_overloaded' => $isOverloaded,
            ];
        }
        usort($zoneRows, static function (array $a, array $b): int {
            $ao = !empty($a['is_overloaded']) ? 1 : 0;
            $bo = !empty($b['is_overloaded']) ? 1 : 0;
            if ($ao !== $bo) {
                return $bo <=> $ao;
            }
            $ac = (int)($a['orders_count'] ?? 0);
            $bc = (int)($b['orders_count'] ?? 0);
            if ($ac !== $bc) {
                return $bc <=> $ac;
            }
            return strcmp((string)($a['zone_key'] ?? ''), (string)($b['zone_key'] ?? ''));
        });

        $out['rows'] = $zoneRows;
        $out['totals'] = [
            'zones_count' => count($zoneRows),
            'orders_count' => (int)($dataset['totals']['orders_count'] ?? 0),
            'revenue' => round((float)($dataset['totals']['paid_revenue'] ?? 0), 2),
            'overloaded_zones' => $overloaded,
        ];
        return $out;
    }
}

if (!function_exists('delivery_sla_analytics')) {
    /**
     * @param array<string,mixed> $period
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    function delivery_sla_analytics(PDO $pdo, int $restaurantId, array $period, array $options = []): array
    {
        $out = [
            'avg_dispatch_minutes' => 0,
            'avg_pickup_minutes' => 0,
            'avg_delivery_minutes' => 0,
            'courier_response_minutes' => 0,
            'sla_success_percent' => 100.0,
            'overdue_delivery_percent' => 0.0,
            'delivered_count' => 0,
            'overdue_delivery_count' => 0,
            'active_overdue_count' => 0,
        ];
        $dataset = delivery_analytics_dataset($pdo, $restaurantId, $period);
        $rows = is_array($dataset['rows'] ?? null) ? $dataset['rows'] : [];
        if ($rows === []) {
            return $out;
        }
        $dispatchSum = 0;
        $dispatchCnt = 0;
        $pickupSum = 0;
        $pickupCnt = 0;
        $deliverySum = 0;
        $deliveryCnt = 0;
        $overdueDelivered = 0;
        $activeOverdue = 0;
        $slaTarget = max(15, (int)($options['delivery_sla_target_minutes'] ?? 35));

        foreach ($rows as $row) {
            $status = (string)($row['courier_status'] ?? 'waiting_courier');
            $dispatch = $row['dispatch_minutes'] ?? null;
            if (is_int($dispatch)) {
                $dispatchSum += $dispatch;
                $dispatchCnt++;
            }
            $pickup = $row['pickup_minutes'] ?? null;
            if (is_int($pickup)) {
                $pickupSum += $pickup;
                $pickupCnt++;
            }
            if ($status === 'delivered') {
                $delivery = $row['delivery_minutes'] ?? null;
                if (is_int($delivery)) {
                    $deliverySum += $delivery;
                    $deliveryCnt++;
                    if ($delivery > $slaTarget) {
                        $overdueDelivered++;
                    }
                }
            } elseif (function_exists('courier_order_sla_meta')) {
                $sla = courier_order_sla_meta($row, ['warning_minutes' => 15, 'critical_minutes' => $slaTarget]);
                if (($sla['level'] ?? '') === 'critical') {
                    $activeOverdue++;
                }
            }
        }

        $deliveredCount = max(0, $deliveryCnt);
        $slaSuccess = $deliveredCount > 0 ? round(((float)max(0, $deliveredCount - $overdueDelivered) * 100.0) / $deliveredCount, 2) : 100.0;
        $overduePercent = $deliveredCount > 0 ? round(((float)$overdueDelivered * 100.0) / $deliveredCount, 2) : 0.0;

        $out['avg_dispatch_minutes'] = $dispatchCnt > 0 ? (int)round($dispatchSum / $dispatchCnt) : 0;
        $out['avg_pickup_minutes'] = $pickupCnt > 0 ? (int)round($pickupSum / $pickupCnt) : 0;
        $out['avg_delivery_minutes'] = $deliveredCount > 0 ? (int)round($deliverySum / $deliveredCount) : 0;
        $out['courier_response_minutes'] = $out['avg_dispatch_minutes'];
        $out['sla_success_percent'] = $slaSuccess;
        $out['overdue_delivery_percent'] = $overduePercent;
        $out['delivered_count'] = $deliveredCount;
        $out['overdue_delivery_count'] = $overdueDelivered;
        $out['active_overdue_count'] = $activeOverdue;
        return $out;
    }
}

if (!function_exists('delivery_courier_analytics')) {
    /**
     * @param array<string,mixed> $period
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    function delivery_courier_analytics(PDO $pdo, int $restaurantId, array $period, array $options = []): array
    {
        $out = [
            'top_couriers' => [],
            'best_sla_couriers' => [],
            'best_batching_couriers' => [],
            'highest_earnings_per_hour' => [],
            'courier_utilization_avg' => 0.0,
            'delivery_efficiency_avg' => 0.0,
            'active_couriers' => 0,
        ];
        $dataset = delivery_analytics_dataset($pdo, $restaurantId, $period);
        $rows = is_array($dataset['rows'] ?? null) ? $dataset['rows'] : [];
        if ($rows === []) {
            return $out;
        }

        $byCourier = [];
        foreach ($rows as $row) {
            $courierId = (int)($row['courier_user_id'] ?? 0);
            if ($courierId <= 0) {
                continue;
            }
            if (!isset($byCourier[$courierId])) {
                $byCourier[$courierId] = [
                    'courier_user_id' => $courierId,
                    'name' => 'Courier #' . $courierId,
                    'orders_count' => 0,
                    'delivered_count' => 0,
                    'sla_success_count' => 0,
                    'delivery_minutes_sum' => 0,
                    'delivery_minutes_rows' => 0,
                    'batch_hits' => 0,
                    'zone_last_delivered_ts' => [],
                    'earnings_total' => 0.0,
                    'earnings_per_hour' => 0.0,
                    'utilization' => 0.0,
                    'efficiency' => 0.0,
                ];
            }
            $byCourier[$courierId]['orders_count']++;
            $status = (string)($row['courier_status'] ?? 'waiting_courier');
            if ($status === 'delivered') {
                $byCourier[$courierId]['delivered_count']++;
                $deliveryMin = $row['delivery_minutes'] ?? null;
                if (is_int($deliveryMin)) {
                    $byCourier[$courierId]['delivery_minutes_sum'] += $deliveryMin;
                    $byCourier[$courierId]['delivery_minutes_rows']++;
                    if ($deliveryMin <= 35) {
                        $byCourier[$courierId]['sla_success_count']++;
                    }
                }
                $zoneKey = strtolower(trim((string)($row['delivery_zone_key'] ?? 'unknown')));
                $deliveredTs = strtotime((string)($row['delivered_at'] ?? '')) ?: 0;
                if ($zoneKey !== '' && $deliveredTs > 0) {
                    $prev = (int)($byCourier[$courierId]['zone_last_delivered_ts'][$zoneKey] ?? 0);
                    if ($prev > 0 && ($deliveredTs - $prev) <= 1800) {
                        $byCourier[$courierId]['batch_hits']++;
                    }
                    $byCourier[$courierId]['zone_last_delivered_ts'][$zoneKey] = $deliveredTs;
                }
            }
        }
        if ($byCourier === []) {
            return $out;
        }

        $courierNames = [];
        try {
            $ids = array_keys($byCourier);
            if ($ids !== []) {
                $ph = implode(',', array_fill(0, count($ids), '?'));
                $stmtUsers = $pdo->prepare("SELECT id, name FROM users WHERE id IN ({$ph})");
                $stmtUsers->execute(array_values($ids));
                foreach (($stmtUsers->fetchAll(PDO::FETCH_ASSOC) ?: []) as $urow) {
                    $uid = (int)($urow['id'] ?? 0);
                    if ($uid > 0) {
                        $courierNames[$uid] = trim((string)($urow['name'] ?? ''));
                    }
                }
            }
        } catch (Throwable $e) {
            // keep default fallback names
        }

        if (function_exists('db_table_exists') && db_table_exists('courier_earnings')) {
            try {
                $ids = array_keys($byCourier);
                if ($ids !== []) {
                    $ph = implode(',', array_fill(0, count($ids), '?'));
                    $stmtEarn = $pdo->prepare("
                        SELECT
                            courier_user_id,
                            COALESCE(SUM(total_amount), 0) AS total_amount
                        FROM courier_earnings
                        WHERE restaurant_id = ?
                          AND created_at >= ?
                          AND created_at <= ?
                          AND courier_user_id IN ({$ph})
                        GROUP BY courier_user_id
                    ");
                    $stmtEarn->execute(array_merge(
                        [
                            $restaurantId,
                            (string)($period['start_at'] ?? ''),
                            (string)($period['end_at'] ?? ''),
                        ],
                        array_values($ids)
                    ));
                    foreach (($stmtEarn->fetchAll(PDO::FETCH_ASSOC) ?: []) as $erow) {
                        $uid = (int)($erow['courier_user_id'] ?? 0);
                        if ($uid > 0 && isset($byCourier[$uid])) {
                            $byCourier[$uid]['earnings_total'] = round((float)($erow['total_amount'] ?? 0), 2);
                        }
                    }
                }
            } catch (Throwable $e) {
                // ignore earnings fallback
            }
        }

        $periodSeconds = max(1, (int)($period['duration_seconds'] ?? 86400));
        $periodHours = max(1.0, $periodSeconds / 3600.0);
        $periodDays = max(1.0, $periodSeconds / 86400.0);
        $top = [];
        foreach ($byCourier as $uid => $c) {
            $name = trim((string)($courierNames[$uid] ?? ''));
            $c['name'] = $name !== '' ? $name : ('Courier #' . $uid);
            $delivered = (int)$c['delivered_count'];
            $avgDelivery = $c['delivery_minutes_rows'] > 0
                ? (int)round(((float)$c['delivery_minutes_sum']) / max(1, (int)$c['delivery_minutes_rows']))
                : 0;
            $slaSuccess = $delivered > 0
                ? round(((float)$c['sla_success_count'] * 100.0) / $delivered, 2)
                : 100.0;
            $batchEfficiency = $delivered > 0
                ? round(((float)$c['batch_hits'] * 100.0) / $delivered, 2)
                : 0.0;
            $deliveryHours = max(0.5, ((float)$c['delivery_minutes_sum']) / 60.0);
            $earnPerHour = $c['earnings_total'] > 0 ? round(((float)$c['earnings_total']) / $deliveryHours, 2) : 0.0;
            $utilization = round($delivered / $periodDays, 2);
            $efficiency = round($slaSuccess - max(0.0, (float)$avgDelivery / 2.0), 2);
            $top[] = [
                'courier_user_id' => (int)$uid,
                'name' => (string)$c['name'],
                'orders_count' => (int)$c['orders_count'],
                'delivered_count' => $delivered,
                'avg_delivery_minutes' => $avgDelivery,
                'sla_success_percent' => $slaSuccess,
                'batching_efficiency_percent' => $batchEfficiency,
                'earnings_total' => round((float)$c['earnings_total'], 2),
                'earnings_per_hour' => $earnPerHour,
                'utilization' => $utilization,
                'delivery_efficiency' => $efficiency,
            ];
        }

        $sortedTop = $top;
        usort($sortedTop, static function (array $a, array $b): int {
            $ad = (int)($a['delivered_count'] ?? 0);
            $bd = (int)($b['delivered_count'] ?? 0);
            if ($ad !== $bd) {
                return $bd <=> $ad;
            }
            return strcmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
        });
        $sortedSla = $top;
        usort($sortedSla, static function (array $a, array $b): int {
            $as = (float)($a['sla_success_percent'] ?? 0);
            $bs = (float)($b['sla_success_percent'] ?? 0);
            if ($as !== $bs) {
                return $bs <=> $as;
            }
            return (int)($b['delivered_count'] ?? 0) <=> (int)($a['delivered_count'] ?? 0);
        });
        $sortedBatch = $top;
        usort($sortedBatch, static function (array $a, array $b): int {
            $ab = (float)($a['batching_efficiency_percent'] ?? 0);
            $bb = (float)($b['batching_efficiency_percent'] ?? 0);
            if ($ab !== $bb) {
                return $bb <=> $ab;
            }
            return (int)($b['delivered_count'] ?? 0) <=> (int)($a['delivered_count'] ?? 0);
        });
        $sortedEph = $top;
        usort($sortedEph, static function (array $a, array $b): int {
            $ae = (float)($a['earnings_per_hour'] ?? 0);
            $be = (float)($b['earnings_per_hour'] ?? 0);
            if ($ae !== $be) {
                return $be <=> $ae;
            }
            return (float)($b['earnings_total'] ?? 0) <=> (float)($a['earnings_total'] ?? 0);
        });

        $utilSum = 0.0;
        $effSum = 0.0;
        foreach ($top as $row) {
            $utilSum += (float)($row['utilization'] ?? 0);
            $effSum += (float)($row['delivery_efficiency'] ?? 0);
        }
        $count = max(1, count($top));
        $out['courier_utilization_avg'] = round($utilSum / $count, 2);
        $out['delivery_efficiency_avg'] = round($effSum / $count, 2);
        $out['active_couriers'] = count($top);
        $out['top_couriers'] = array_slice($sortedTop, 0, 5);
        $out['best_sla_couriers'] = array_slice($sortedSla, 0, 5);
        $out['best_batching_couriers'] = array_slice($sortedBatch, 0, 5);
        $out['highest_earnings_per_hour'] = array_slice($sortedEph, 0, 5);

        return $out;
    }
}

if (!function_exists('delivery_profitability_foundation')) {
    /**
     * @param array<string,mixed> $period
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    function delivery_profitability_foundation(PDO $pdo, int $restaurantId, array $period, array $options = []): array
    {
        $out = [
            'delivery_revenue' => 0.0,
            'delivery_orders_count' => 0,
            'avg_delivery_revenue' => 0.0,
            'avg_courier_cost' => 0.0,
            'avg_tips' => 0.0,
            'courier_cost_total' => 0.0,
            'tips_paid_total' => 0.0,
            'estimated_margin' => 0.0,
            'estimated_margin_percent' => 0.0,
            'zone_revenue' => [],
            'zone_margin_risk' => [],
        ];
        $dataset = delivery_analytics_dataset($pdo, $restaurantId, $period);
        $rows = is_array($dataset['rows'] ?? null) ? $dataset['rows'] : [];
        if ($rows === []) {
            return $out;
        }

        $deliveryOrdersCount = (int)($dataset['totals']['orders_count'] ?? count($rows));
        $deliveryRevenue = (float)($dataset['totals']['paid_revenue'] ?? 0);
        $zoneRevenueMap = [];
        foreach ($rows as $row) {
            $zone = strtolower(trim((string)($row['delivery_zone_key'] ?? 'unknown')));
            $zone = $zone !== '' ? $zone : 'unknown';
            if (!isset($zoneRevenueMap[$zone])) {
                $zoneRevenueMap[$zone] = 0.0;
            }
            if (!empty($row['is_paid'])) {
                $zoneRevenueMap[$zone] += (float)($row['amount_value'] ?? 0);
            }
        }

        $courierCostTotal = 0.0;
        $zoneCostMap = [];
        if (function_exists('db_table_exists') && db_table_exists('courier_earnings')) {
            try {
                $hasDeliveryZoneKey = function_exists('db_column_exists') && db_column_exists('orders', 'delivery_zone_key');
                $zoneCostSql = $hasDeliveryZoneKey ? 'LOWER(TRIM(COALESCE(o.delivery_zone_key,\'unknown\')))' : "'unknown'";
                $stmtCost = $pdo->prepare("
                    SELECT
                        {$zoneCostSql} AS zone_key,
                        COALESCE(SUM(ce.total_amount), 0) AS courier_cost
                    FROM courier_earnings ce
                    INNER JOIN orders o ON o.id = ce.order_id
                    WHERE ce.restaurant_id = :restaurant_id
                      AND ce.created_at >= :start_at
                      AND ce.created_at <= :end_at
                      AND LOWER(TRIM(COALESCE(o.order_type, ''))) = 'delivery'
                    GROUP BY {$zoneCostSql}
                ");
                $stmtCost->execute([
                    ':restaurant_id' => $restaurantId,
                    ':start_at' => (string)($period['start_at'] ?? ''),
                    ':end_at' => (string)($period['end_at'] ?? ''),
                ]);
                foreach (($stmtCost->fetchAll(PDO::FETCH_ASSOC) ?: []) as $crow) {
                    $zone = strtolower(trim((string)($crow['zone_key'] ?? 'unknown')));
                    $zone = $zone !== '' ? $zone : 'unknown';
                    $cost = round((float)($crow['courier_cost'] ?? 0), 2);
                    $zoneCostMap[$zone] = $cost;
                    $courierCostTotal += $cost;
                }
            } catch (Throwable $e) {
                $courierCostTotal = 0.0;
                $zoneCostMap = [];
            }
        }

        $tipsPaidTotal = 0.0;
        if (function_exists('db_table_exists') && db_table_exists('order_tips')) {
            try {
                $stmtTips = $pdo->prepare("
                    SELECT COALESCE(SUM(amount), 0) AS tips_paid_total
                    FROM order_tips
                    WHERE restaurant_id = :restaurant_id
                      AND status = 'paid'
                      AND created_at >= :start_at
                      AND created_at <= :end_at
                      AND source = 'courier'
                ");
                $stmtTips->execute([
                    ':restaurant_id' => $restaurantId,
                    ':start_at' => (string)($period['start_at'] ?? ''),
                    ':end_at' => (string)($period['end_at'] ?? ''),
                ]);
                $tipsPaidTotal = round((float)($stmtTips->fetchColumn() ?: 0), 2);
            } catch (Throwable $e) {
                $tipsPaidTotal = 0.0;
            }
        }

        $zoneRows = [];
        $zoneMarginRisk = [];
        foreach ($zoneRevenueMap as $zone => $rev) {
            $cost = (float)($zoneCostMap[$zone] ?? 0.0);
            $margin = $rev - $cost;
            $marginPercent = $rev > 0 ? round(($margin * 100.0) / $rev, 2) : 0.0;
            $zoneRows[] = [
                'zone_key' => (string)$zone,
                'revenue' => round((float)$rev, 2),
                'courier_cost' => round($cost, 2),
                'estimated_margin' => round($margin, 2),
                'estimated_margin_percent' => $marginPercent,
            ];
            if ($rev > 0 && $marginPercent < 15.0) {
                $zoneMarginRisk[] = [
                    'zone_key' => (string)$zone,
                    'estimated_margin_percent' => $marginPercent,
                ];
            }
        }
        usort($zoneRows, static function (array $a, array $b): int {
            return (float)($b['revenue'] ?? 0) <=> (float)($a['revenue'] ?? 0);
        });

        $avgRevenue = $deliveryOrdersCount > 0 ? round($deliveryRevenue / $deliveryOrdersCount, 2) : 0.0;
        $avgCost = $deliveryOrdersCount > 0 ? round($courierCostTotal / $deliveryOrdersCount, 2) : 0.0;
        $avgTips = $deliveryOrdersCount > 0 ? round($tipsPaidTotal / $deliveryOrdersCount, 2) : 0.0;
        $margin = $deliveryRevenue - $courierCostTotal;
        $marginPercent = $deliveryRevenue > 0 ? round(($margin * 100.0) / $deliveryRevenue, 2) : 0.0;

        $out['delivery_revenue'] = round($deliveryRevenue, 2);
        $out['delivery_orders_count'] = $deliveryOrdersCount;
        $out['avg_delivery_revenue'] = $avgRevenue;
        $out['avg_courier_cost'] = $avgCost;
        $out['avg_tips'] = $avgTips;
        $out['courier_cost_total'] = round($courierCostTotal, 2);
        $out['tips_paid_total'] = round($tipsPaidTotal, 2);
        $out['estimated_margin'] = round($margin, 2);
        $out['estimated_margin_percent'] = $marginPercent;
        $out['zone_revenue'] = $zoneRows;
        $out['zone_margin_risk'] = $zoneMarginRisk;

        return $out;
    }
}

if (!function_exists('delivery_hotspots')) {
    /**
     * @param array<string,mixed> $period
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    function delivery_hotspots(PDO $pdo, int $restaurantId, array $period, array $options = []): array
    {
        $out = [
            'overloaded_districts' => [],
            'peak_delivery_zones' => [],
            'high_sla_breach_areas' => [],
            'batching_hotspots' => [],
            'low_courier_coverage' => [],
            'alerts' => [],
        ];
        $heatmap = delivery_heatmap_summary($pdo, $restaurantId, $period, $options);
        $zoneAnalytics = delivery_zone_analytics($pdo, $restaurantId, $period, $options);
        $sla = delivery_sla_analytics($pdo, $restaurantId, $period, $options);
        $courier = delivery_courier_analytics($pdo, $restaurantId, $period, $options);
        $profitability = delivery_profitability_foundation($pdo, $restaurantId, $period, $options);

        $totalOrders = max(1, (int)($heatmap['total_delivery_orders'] ?? 0));
        foreach ((array)($heatmap['district_density'] ?? []) as $row) {
            $count = (int)($row['count'] ?? 0);
            if ($count >= max(4, (int)ceil($totalOrders * 0.22))) {
                $out['overloaded_districts'][] = $row;
            }
        }

        foreach ((array)($zoneAnalytics['rows'] ?? []) as $zrow) {
            $ordersCount = (int)($zrow['orders_count'] ?? 0);
            if ($ordersCount >= max(4, (int)ceil($totalOrders * 0.2))) {
                $out['peak_delivery_zones'][] = $zrow;
            }
            if (((float)($zrow['sla_success_percent'] ?? 100)) < 85.0 || (int)($zrow['overdue_count'] ?? 0) >= 2) {
                $out['high_sla_breach_areas'][] = $zrow;
            }
            if ((int)($zrow['batching_opportunity_count'] ?? 0) >= 2) {
                $out['batching_hotspots'][] = $zrow;
            }
            if ((int)($zrow['orders_count'] ?? 0) >= 3 && (int)($zrow['courier_coverage_count'] ?? 0) <= 1) {
                $out['low_courier_coverage'][] = $zrow;
            }
        }

        if ($out['overloaded_districts'] !== []) {
            $top = $out['overloaded_districts'][0];
            $out['alerts'][] = [
                'key' => 'delivery_hotspot',
                'level' => 'warning',
                'label' => 'Delivery hotspot',
                'message' => 'Перегруженный район: ' . (string)($top['bucket'] ?? 'unknown') . ' (' . (int)($top['count'] ?? 0) . ' заказов).',
            ];
        }
        if (((float)($sla['sla_success_percent'] ?? 100.0)) < 80.0 || (int)($sla['active_overdue_count'] ?? 0) >= 3) {
            $out['alerts'][] = [
                'key' => 'sla_collapse_risk',
                'level' => 'critical',
                'label' => 'SLA collapse risk',
                'message' => 'SLA success ниже 80% или накоплены критичные активные доставки.',
            ];
        }
        if ((int)($courier['active_couriers'] ?? 0) <= 1 && (int)($heatmap['total_delivery_orders'] ?? 0) >= 5) {
            $out['alerts'][] = [
                'key' => 'courier_shortage',
                'level' => 'warning',
                'label' => 'Courier shortage',
                'message' => 'Низкое покрытие курьерами при растущем delivery-потоке.',
            ];
        }
        $bestBatch = (array)($courier['best_batching_couriers'][0] ?? []);
        if ($bestBatch !== [] && ((float)($bestBatch['batching_efficiency_percent'] ?? 0)) < 20.0) {
            $out['alerts'][] = [
                'key' => 'low_batching_efficiency',
                'level' => 'warning',
                'label' => 'Low batching efficiency',
                'message' => 'Batching эффективность остаётся ниже 20%.',
            ];
        }
        if (is_array($profitability['zone_margin_risk'] ?? null) && $profitability['zone_margin_risk'] !== []) {
            $riskZone = $profitability['zone_margin_risk'][0];
            $out['alerts'][] = [
                'key' => 'unprofitable_zone',
                'level' => 'warning',
                'label' => 'Unprofitable delivery zone',
                'message' => 'Риск низкой маржи в зоне ' . (string)($riskZone['zone_key'] ?? 'unknown') . '.',
            ];
        }

        $out['overloaded_districts'] = array_slice($out['overloaded_districts'], 0, 5);
        $out['peak_delivery_zones'] = array_slice($out['peak_delivery_zones'], 0, 5);
        $out['high_sla_breach_areas'] = array_slice($out['high_sla_breach_areas'], 0, 5);
        $out['batching_hotspots'] = array_slice($out['batching_hotspots'], 0, 5);
        $out['low_courier_coverage'] = array_slice($out['low_courier_coverage'], 0, 5);

        return $out;
    }
}

if (!function_exists('delivery_operational_analytics')) {
    /**
     * @param array<string,mixed> $period
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    function delivery_operational_analytics(PDO $pdo, int $restaurantId, array $period, array $options = []): array
    {
        $heatmap = delivery_heatmap_summary($pdo, $restaurantId, $period, $options);
        $zone = delivery_zone_analytics($pdo, $restaurantId, $period, $options);
        $sla = delivery_sla_analytics($pdo, $restaurantId, $period, $options);
        $courier = delivery_courier_analytics($pdo, $restaurantId, $period, $options);
        $profitability = delivery_profitability_foundation($pdo, $restaurantId, $period, $options);
        $hotspots = delivery_hotspots($pdo, $restaurantId, $period, $options);

        return [
            'heatmap' => $heatmap,
            'zone' => $zone,
            'sla' => $sla,
            'courier' => $courier,
            'profitability' => $profitability,
            'hotspots' => $hotspots,
            'alerts' => is_array($hotspots['alerts'] ?? null) ? $hotspots['alerts'] : [],
        ];
    }
}

if (!function_exists('forecast_historical_daily_orders')) {
    /**
     * @return list<array{order_date:string,orders_count:int,delivery_count:int,revenue:float}>
     */
    function forecast_historical_daily_orders(PDO $pdo, int $restaurantId, int $days = 35): array
    {
        if (
            $restaurantId <= 0
            || !function_exists('db_table_exists')
            || !db_table_exists('orders')
        ) {
            return [];
        }
        $days = max(7, min(120, $days));
        $startAt = date('Y-m-d 00:00:00', strtotime('-' . ($days - 1) . ' days'));
        $endAt = date('Y-m-d 23:59:59');
        $hasOrderStatus = function_exists('db_column_exists') && db_column_exists('orders', 'order_status');
        $statusFilter = $hasOrderStatus ? "AND (o.order_status IS NULL OR (o.order_status <> 'canceled' AND o.order_status <> 'cancelled'))" : '';
        $amountExpr = analytics_order_amount_expr($pdo, 'o');
        $hasOrderType = function_exists('db_column_exists') && db_column_exists('orders', 'order_type');
        $hasTableId = function_exists('db_column_exists') && db_column_exists('orders', 'table_id');
        $tableIdSql = $hasTableId ? 'o.table_id AS table_id' : 'NULL AS table_id';
        $typeSql = $hasOrderType ? 'o.order_type AS order_type' : 'NULL AS order_type';

        try {
            $stmt = $pdo->prepare("
                SELECT
                    DATE(o.created_at) AS order_date,
                    {$typeSql},
                    {$tableIdSql},
                    {$amountExpr} AS amount_value
                FROM orders o
                WHERE o.restaurant_id = :restaurant_id
                  AND o.created_at >= :start_at
                  AND o.created_at <= :end_at
                  {$statusFilter}
            ");
            $stmt->execute([
                ':restaurant_id' => $restaurantId,
                ':start_at' => $startAt,
                ':end_at' => $endAt,
            ]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }

        $byDate = [];
        foreach ($rows as $row) {
            $date = (string)($row['order_date'] ?? '');
            if ($date === '') {
                continue;
            }
            if (!isset($byDate[$date])) {
                $byDate[$date] = [
                    'order_date' => $date,
                    'orders_count' => 0,
                    'delivery_count' => 0,
                    'revenue' => 0.0,
                ];
            }
            $tableId = isset($row['table_id']) ? (int)$row['table_id'] : null;
            $type = order_type_normalize((string)($row['order_type'] ?? ''), $tableId);
            $byDate[$date]['orders_count']++;
            if ($type === 'delivery') {
                $byDate[$date]['delivery_count']++;
            }
            $byDate[$date]['revenue'] += (float)($row['amount_value'] ?? 0);
        }
        ksort($byDate);
        $out = array_values($byDate);
        foreach ($out as &$r) {
            $r['revenue'] = round((float)$r['revenue'], 2);
        }
        unset($r);
        return $out;
    }
}

if (!function_exists('forecast_historical_hourly_orders')) {
    /**
     * @return list<array{hour:int,count:int}>
     */
    function forecast_historical_hourly_orders(PDO $pdo, int $restaurantId, int $days = 14): array
    {
        $result = [];
        for ($h = 0; $h < 24; $h++) {
            $result[] = ['hour' => $h, 'count' => 0];
        }
        if (
            $restaurantId <= 0
            || !function_exists('db_table_exists')
            || !db_table_exists('orders')
        ) {
            return $result;
        }
        $days = max(3, min(90, $days));
        $startAt = date('Y-m-d 00:00:00', strtotime('-' . ($days - 1) . ' days'));
        $endAt = date('Y-m-d 23:59:59');
        $hasOrderStatus = function_exists('db_column_exists') && db_column_exists('orders', 'order_status');
        $statusFilter = $hasOrderStatus ? "AND (order_status IS NULL OR (order_status <> 'canceled' AND order_status <> 'cancelled'))" : '';
        try {
            $stmt = $pdo->prepare("
                SELECT HOUR(created_at) AS hh, COUNT(*) AS cnt
                FROM orders
                WHERE restaurant_id = :restaurant_id
                  AND created_at >= :start_at
                  AND created_at <= :end_at
                  {$statusFilter}
                GROUP BY HOUR(created_at)
            ");
            $stmt->execute([
                ':restaurant_id' => $restaurantId,
                ':start_at' => $startAt,
                ':end_at' => $endAt,
            ]);
            foreach (($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
                $h = (int)($row['hh'] ?? -1);
                if ($h >= 0 && $h <= 23) {
                    $result[$h]['count'] = (int)($row['cnt'] ?? 0);
                }
            }
        } catch (Throwable $e) {
            return $result;
        }
        return $result;
    }
}

if (!function_exists('forecast_weighted_expected_value')) {
    function forecast_weighted_expected_value(float $sameWeekdayAvg, float $last7Avg, float $recentTrendAvg): float
    {
        $value = ($sameWeekdayAvg * 0.5) + ($last7Avg * 0.35) + ($recentTrendAvg * 0.15);
        return max(0.0, $value);
    }
}

if (!function_exists('forecast_orders')) {
    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    function forecast_orders(PDO $pdo, int $restaurantId, array $options = []): array
    {
        $target = strtolower(trim((string)($options['target'] ?? 'today')));
        if (!in_array($target, ['today', 'tomorrow', 'next_shift', 'next_daypart'], true)) {
            $target = 'today';
        }
        $daily = forecast_historical_daily_orders($pdo, $restaurantId, (int)($options['history_days'] ?? 35));
        $hourly = forecast_historical_hourly_orders($pdo, $restaurantId, (int)($options['hourly_days'] ?? 14));
        $out = [
            'target' => $target,
            'expected_orders' => 0,
            'expected_avg_check' => 0.0,
            'expected_delivery_share_percent' => 0.0,
            'expected_reservations' => 0,
            'peak_hours' => [],
            'weekday_pattern' => [],
            'hourly_pattern' => $hourly,
            'trend_coefficient' => 1.0,
            'confidence' => 'low',
        ];
        if ($daily === []) {
            return $out;
        }

        $todayDow = (int)date('N');
        if ($target === 'tomorrow') {
            $todayDow = (int)date('N', strtotime('+1 day'));
        }

        $weekdayBuckets = [];
        foreach ($daily as $row) {
            $dow = (int)date('N', strtotime((string)$row['order_date']));
            if (!isset($weekdayBuckets[$dow])) {
                $weekdayBuckets[$dow] = ['orders' => 0.0, 'count' => 0];
            }
            $weekdayBuckets[$dow]['orders'] += (float)($row['orders_count'] ?? 0);
            $weekdayBuckets[$dow]['count']++;
        }
        $sameWeekdayAvg = 0.0;
        if (!empty($weekdayBuckets[$todayDow]['count'])) {
            $sameWeekdayAvg = ((float)$weekdayBuckets[$todayDow]['orders']) / max(1, (int)$weekdayBuckets[$todayDow]['count']);
        }
        $last7 = array_slice($daily, -7);
        $last7Avg = 0.0;
        if ($last7 !== []) {
            $sum = 0.0;
            foreach ($last7 as $row) {
                $sum += (float)($row['orders_count'] ?? 0);
            }
            $last7Avg = $sum / count($last7);
        }
        $last3 = array_slice($daily, -3);
        $prev3 = array_slice($daily, -6, 3);
        $recentTrendAvg = $last7Avg;
        $trendCoef = 1.0;
        if ($last3 !== []) {
            $s3 = 0.0;
            foreach ($last3 as $row) { $s3 += (float)($row['orders_count'] ?? 0); }
            $avg3 = $s3 / count($last3);
            $recentTrendAvg = $avg3;
            if ($prev3 !== []) {
                $sp3 = 0.0;
                foreach ($prev3 as $row) { $sp3 += (float)($row['orders_count'] ?? 0); }
                $avgPrev3 = $sp3 / max(1, count($prev3));
                if ($avgPrev3 > 0.0001) {
                    $trendCoef = max(0.8, min(1.25, $avg3 / $avgPrev3));
                }
            }
        }

        $expectedDayOrders = forecast_weighted_expected_value($sameWeekdayAvg, $last7Avg, $recentTrendAvg) * $trendCoef;

        $dayPartHours = match ($target) {
            'next_shift' => 8,
            'next_daypart' => 6,
            default => 24,
        };
        $hourlyTotal = 0;
        foreach ($hourly as $hrow) {
            $hourlyTotal += (int)($hrow['count'] ?? 0);
        }
        $peakHours = [];
        $hourlySorted = $hourly;
        usort($hourlySorted, static function (array $a, array $b): int {
            $ac = (int)($a['count'] ?? 0);
            $bc = (int)($b['count'] ?? 0);
            if ($ac !== $bc) {
                return $bc <=> $ac;
            }
            return (int)($a['hour'] ?? 0) <=> (int)($b['hour'] ?? 0);
        });
        foreach (array_slice($hourlySorted, 0, 4) as $hrow) {
            $peakHours[] = (int)($hrow['hour'] ?? 0);
        }

        $expectedOrders = $expectedDayOrders;
        if (in_array($target, ['next_shift', 'next_daypart'], true)) {
            $expectedOrders = $expectedDayOrders * ($dayPartHours / 24.0);
        }
        $expectedOrders = (int)max(0, round($expectedOrders));

        $sumRevenue = 0.0;
        $sumOrders = 0;
        $sumDelivery = 0;
        foreach (array_slice($daily, -14) as $row) {
            $sumRevenue += (float)($row['revenue'] ?? 0);
            $sumOrders += (int)($row['orders_count'] ?? 0);
            $sumDelivery += (int)($row['delivery_count'] ?? 0);
        }
        $avgCheck = $sumOrders > 0 ? round($sumRevenue / $sumOrders, 2) : 0.0;
        $deliveryShare = $sumOrders > 0 ? round(($sumDelivery * 100.0) / $sumOrders, 2) : 0.0;

        $expectedReservations = 0;
        if (function_exists('analytics_period_bounds') && function_exists('analytics_reservation_summary')) {
            $p = $target === 'tomorrow'
                ? analytics_period_bounds('custom', date('Y-m-d', strtotime('+1 day')), date('Y-m-d', strtotime('+1 day')))
                : analytics_period_bounds('today');
            $r = analytics_reservation_summary($pdo, $restaurantId, $p);
            $expectedReservations = (int)($r['upcoming_count'] ?? 0);
        }

        $confidence = 'low';
        $historyCount = count($daily);
        if ($historyCount >= 21) {
            $confidence = 'high';
        } elseif ($historyCount >= 10) {
            $confidence = 'medium';
        }

        $weekdayPattern = [];
        foreach ($weekdayBuckets as $dow => $payload) {
            $weekdayPattern[] = [
                'weekday' => (int)$dow,
                'avg_orders' => round(((float)$payload['orders']) / max(1, (int)$payload['count']), 2),
                'samples' => (int)$payload['count'],
            ];
        }
        usort($weekdayPattern, static function (array $a, array $b): int {
            return (int)($a['weekday'] ?? 0) <=> (int)($b['weekday'] ?? 0);
        });

        $out['expected_orders'] = $expectedOrders;
        $out['expected_avg_check'] = $avgCheck;
        $out['expected_delivery_share_percent'] = $deliveryShare;
        $out['expected_reservations'] = $expectedReservations;
        $out['peak_hours'] = $peakHours;
        $out['weekday_pattern'] = $weekdayPattern;
        $out['trend_coefficient'] = round($trendCoef, 3);
        $out['confidence'] = $confidence;
        return $out;
    }
}

if (!function_exists('forecast_revenue')) {
    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    function forecast_revenue(PDO $pdo, int $restaurantId, array $options = []): array
    {
        $orderForecast = forecast_orders($pdo, $restaurantId, $options);
        $expectedOrders = (int)($orderForecast['expected_orders'] ?? 0);
        $avgCheck = (float)($orderForecast['expected_avg_check'] ?? 0);
        $deliveryShare = (float)($orderForecast['expected_delivery_share_percent'] ?? 0);
        $expectedRevenue = round($expectedOrders * $avgCheck, 2);
        $deliveryRevenue = round($expectedRevenue * ($deliveryShare / 100.0), 2);
        $dineInRevenue = round(max(0.0, $expectedRevenue - $deliveryRevenue), 2);
        return [
            'target' => (string)($orderForecast['target'] ?? 'today'),
            'expected_revenue' => $expectedRevenue,
            'expected_delivery_revenue' => $deliveryRevenue,
            'expected_dine_in_revenue' => $dineInRevenue,
            'expected_avg_check' => $avgCheck,
            'growth_decline_coefficient' => (float)($orderForecast['trend_coefficient'] ?? 1.0),
            'confidence' => (string)($orderForecast['confidence'] ?? 'low'),
        ];
    }
}

if (!function_exists('forecast_zone_pressure')) {
    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    function forecast_zone_pressure(PDO $pdo, int $restaurantId, array $options = []): array
    {
        $period = analytics_period_bounds('week');
        $zoneAnalytics = delivery_zone_analytics($pdo, $restaurantId, $period, $options);
        $orderForecast = forecast_orders($pdo, $restaurantId, $options);
        $expectedOrders = max(0, (int)($orderForecast['expected_orders'] ?? 0));
        $expectedDelivery = (int)round($expectedOrders * (((float)($orderForecast['expected_delivery_share_percent'] ?? 0)) / 100.0));

        $rows = is_array($zoneAnalytics['rows'] ?? null) ? $zoneAnalytics['rows'] : [];
        $zoneTotal = max(1, (int)($zoneAnalytics['totals']['orders_count'] ?? 0));
        $forecastRows = [];
        foreach ($rows as $row) {
            $count = (int)($row['orders_count'] ?? 0);
            $share = $zoneTotal > 0 ? ((float)$count / $zoneTotal) : 0.0;
            $predicted = (int)round($expectedDelivery * $share);
            $pressure = min(100, max(0, (int)round((float)($row['active_count'] ?? 0) * 14 + (float)($row['overdue_count'] ?? 0) * 12 + $predicted * 4)));
            $forecastRows[] = [
                'zone_key' => (string)($row['zone_key'] ?? 'unknown'),
                'zone_label' => (string)($row['zone_label'] ?? ($row['zone_key'] ?? 'unknown')),
                'predicted_orders' => $predicted,
                'predicted_pressure' => $pressure,
                'overload_risk' => $pressure >= 70,
            ];
        }
        usort($forecastRows, static function (array $a, array $b): int {
            return (int)($b['predicted_pressure'] ?? 0) <=> (int)($a['predicted_pressure'] ?? 0);
        });
        return [
            'target' => (string)($options['target'] ?? 'today'),
            'expected_delivery_orders' => $expectedDelivery,
            'zones' => $forecastRows,
            'peak_zone' => $forecastRows[0] ?? null,
        ];
    }
}

if (!function_exists('forecast_delivery_load')) {
    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    function forecast_delivery_load(PDO $pdo, int $restaurantId, array $options = []): array
    {
        $orderForecast = forecast_orders($pdo, $restaurantId, $options);
        $deliveryShare = ((float)($orderForecast['expected_delivery_share_percent'] ?? 0)) / 100.0;
        $expectedDeliveryOrders = (int)round(((int)($orderForecast['expected_orders'] ?? 0)) * $deliveryShare);
        $hourWindow = in_array((string)($orderForecast['target'] ?? 'today'), ['next_shift', 'next_daypart'], true) ? 8.0 : 12.0;
        $deliveriesPerCourierWindow = max(3.0, (float)($options['deliveries_per_courier_window'] ?? 6.0));
        $recommendedCouriers = $expectedDeliveryOrders > 0
            ? (int)max(1, ceil($expectedDeliveryOrders / $deliveriesPerCourierWindow))
            : 0;

        $courierAnalytics = delivery_courier_analytics($pdo, $restaurantId, analytics_period_bounds('week'), $options);
        $activeCouriers = (int)($courierAnalytics['active_couriers'] ?? 0);
        $shortageRisk = ($recommendedCouriers > 0 && $activeCouriers < $recommendedCouriers);
        $queuePressure = min(100, max(0, (int)round(($expectedDeliveryOrders * 8) - ($activeCouriers * 10))));
        $slaRisk = $queuePressure >= 60 ? 'high' : ($queuePressure >= 35 ? 'medium' : 'low');
        $batchingOpportunity = min(100, max(0, (int)round($expectedDeliveryOrders * max(0.2, min(0.8, $deliveryShare)))));

        return [
            'target' => (string)($orderForecast['target'] ?? 'today'),
            'expected_delivery_orders' => $expectedDeliveryOrders,
            'expected_active_couriers' => $recommendedCouriers,
            'current_active_couriers' => $activeCouriers,
            'predicted_queue_pressure' => $queuePressure,
            'predicted_sla_risk' => $slaRisk,
            'predicted_batching_opportunity' => $batchingOpportunity,
            'courier_shortage_risk' => $shortageRisk,
        ];
    }
}

if (!function_exists('forecast_kitchen_workload')) {
    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    function forecast_kitchen_workload(PDO $pdo, int $restaurantId, array $options = []): array
    {
        $orderForecast = forecast_orders($pdo, $restaurantId, $options);
        $expectedOrders = max(0, (int)($orderForecast['expected_orders'] ?? 0));
        $periodHours = in_array((string)($orderForecast['target'] ?? 'today'), ['next_shift', 'next_daypart'], true) ? 8.0 : 12.0;
        $expectedTicketsPerHour = $periodHours > 0 ? round($expectedOrders / $periodHours, 2) : 0.0;

        $stationKeys = ['hot', 'cold', 'bar', 'dessert', 'kitchen'];
        if (function_exists('kds_allowed_stations')) {
            try {
                $stationKeys = array_values(array_unique(array_map('strval', (array)kds_allowed_stations())));
            } catch (Throwable $e) {
                $stationKeys = ['hot', 'cold', 'bar', 'dessert', 'kitchen'];
            }
        }
        if ($stationKeys === []) {
            $stationKeys = ['hot', 'cold', 'bar', 'dessert', 'kitchen'];
        }
        $weights = [
            'hot' => 0.34,
            'cold' => 0.18,
            'bar' => 0.18,
            'dessert' => 0.12,
            'grill' => 0.08,
            'pizza' => 0.06,
            'sushi' => 0.06,
            'kitchen' => 0.18,
            'custom' => 0.1,
        ];
        $stationLoad = [];
        $avgPrep = max(10, (int)($options['avg_prep_minutes'] ?? 16));
        foreach ($stationKeys as $key) {
            $w = (float)($weights[$key] ?? $weights['kitchen']);
            $tickets = (int)round($expectedOrders * $w);
            $pressure = min(100, max(0, (int)round(($tickets * 6) + (($avgPrep - 12) * 2))));
            $congestion = $pressure >= 70 ? 'high' : ($pressure >= 40 ? 'medium' : 'low');
            $slaBreachProbability = min(95, max(5, (int)round($pressure * 0.9)));
            $stationLoad[] = [
                'station_key' => (string)$key,
                'predicted_tickets' => $tickets,
                'predicted_tickets_per_hour' => round($tickets / max(1.0, $periodHours), 2),
                'predicted_pressure' => $pressure,
                'congestion' => $congestion,
                'sla_breach_probability_percent' => $slaBreachProbability,
            ];
        }
        usort($stationLoad, static function (array $a, array $b): int {
            return (int)($b['predicted_pressure'] ?? 0) <=> (int)($a['predicted_pressure'] ?? 0);
        });
        return [
            'target' => (string)($orderForecast['target'] ?? 'today'),
            'expected_orders' => $expectedOrders,
            'expected_tickets_per_hour' => $expectedTicketsPerHour,
            'station_load' => $stationLoad,
            'prep_overload_risk' => !empty($stationLoad) && (int)($stationLoad[0]['predicted_pressure'] ?? 0) >= 70,
            'peak_station' => $stationLoad[0] ?? null,
        ];
    }
}

if (!function_exists('forecast_staffing_need')) {
    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    function forecast_staffing_need(PDO $pdo, int $restaurantId, array $options = []): array
    {
        $orderForecast = forecast_orders($pdo, $restaurantId, $options);
        $deliveryForecast = forecast_delivery_load($pdo, $restaurantId, $options);
        $kitchenForecast = forecast_kitchen_workload($pdo, $restaurantId, $options);
        $expectedOrders = max(0, (int)($orderForecast['expected_orders'] ?? 0));
        $expectedDelivery = max(0, (int)($deliveryForecast['expected_delivery_orders'] ?? 0));
        $expectedHall = max(0, $expectedOrders - $expectedDelivery);

        $recommendedCouriers = max(0, (int)($deliveryForecast['expected_active_couriers'] ?? 0));
        $recommendedKitchen = max(1, (int)ceil($expectedOrders / max(8.0, (float)($options['orders_per_kitchen_staff'] ?? 16.0))));
        $recommendedWaiters = max(1, (int)ceil($expectedHall / max(8.0, (float)($options['orders_per_waiter'] ?? 14.0))));

        $activeCounts = ['courier' => 0, 'kitchen' => 0, 'waiter' => 0];
        if (function_exists('db_table_exists') && db_table_exists('users_restaurants')) {
            $activeCond = (function_exists('db_column_exists') && db_column_exists('users_restaurants', 'is_active'))
                ? ' AND COALESCE(is_active,1)=1'
                : '';
            try {
                $stmt = $pdo->prepare("
                    SELECT LOWER(TRIM(COALESCE(restaurant_role,''))) AS rr, COUNT(*) AS cnt
                    FROM users_restaurants
                    WHERE restaurant_id = :rest
                      {$activeCond}
                    GROUP BY LOWER(TRIM(COALESCE(restaurant_role,'')))
                ");
                $stmt->execute([':rest' => $restaurantId]);
                foreach (($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
                    $role = (string)($row['rr'] ?? '');
                    $cnt = (int)($row['cnt'] ?? 0);
                    if ($role === 'courier') {
                        $activeCounts['courier'] += $cnt;
                    } elseif (in_array($role, ['kitchen', 'bar', 'chef'], true)) {
                        $activeCounts['kitchen'] += $cnt;
                    } elseif (in_array($role, ['waiter', 'staff'], true)) {
                        $activeCounts['waiter'] += $cnt;
                    }
                }
            } catch (Throwable $e) {
                // keep fallback zeros
            }
        }

        $courierGap = $recommendedCouriers - $activeCounts['courier'];
        $kitchenGap = $recommendedKitchen - $activeCounts['kitchen'];
        $waiterGap = $recommendedWaiters - $activeCounts['waiter'];

        return [
            'target' => (string)($orderForecast['target'] ?? 'today'),
            'recommended_couriers' => $recommendedCouriers,
            'recommended_kitchen_staff' => $recommendedKitchen,
            'recommended_waiters' => $recommendedWaiters,
            'active_couriers' => $activeCounts['courier'],
            'active_kitchen_staff' => $activeCounts['kitchen'],
            'active_waiters' => $activeCounts['waiter'],
            'courier_gap' => $courierGap,
            'kitchen_gap' => $kitchenGap,
            'waiter_gap' => $waiterGap,
            'overload_risk' => ($courierGap > 0) || ($kitchenGap > 0),
            'understaff_risk' => ($courierGap > 1) || ($kitchenGap > 1) || ($waiterGap > 1),
        ];
    }
}

if (!function_exists('forecast_inventory_pressure')) {
    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    function forecast_inventory_pressure(PDO $pdo, int $restaurantId, array $options = []): array
    {
        $out = [
            'most_ordered_items' => [],
            'fast_moving_items' => [],
            'high_risk_items' => [],
            'ingredient_consumption_estimation' => [],
        ];
        if (
            $restaurantId <= 0
            || !function_exists('db_table_exists')
            || !db_table_exists('orders')
            || !db_table_exists('order_items')
        ) {
            return $out;
        }

        $hasOrderStatus = function_exists('db_column_exists') && db_column_exists('orders', 'order_status');
        $statusFilter = $hasOrderStatus ? "AND (o.order_status IS NULL OR (o.order_status <> 'canceled' AND o.order_status <> 'cancelled'))" : '';
        $qtyExpr = '1';
        if (function_exists('db_column_exists') && db_column_exists('order_items', 'quantity') && function_exists('db_column_exists') && db_column_exists('order_items', 'qty')) {
            $qtyExpr = 'COALESCE(NULLIF(oi.quantity,0), oi.qty, 1)';
        } elseif (function_exists('db_column_exists') && db_column_exists('order_items', 'quantity')) {
            $qtyExpr = 'COALESCE(oi.quantity,1)';
        } elseif (function_exists('db_column_exists') && db_column_exists('order_items', 'qty')) {
            $qtyExpr = 'COALESCE(oi.qty,1)';
        }
        $hasOrderItemsName = function_exists('db_column_exists') && db_column_exists('order_items', 'name');
        $hasOrderItemsMenuId = function_exists('db_column_exists') && db_column_exists('order_items', 'menu_item_id');
        $itemNameExpr = $hasOrderItemsName
            ? 'COALESCE(NULLIF(TRIM(oi.name), \'\'), mi.name, CONCAT(\'Item #\', oi.menu_item_id))'
            : 'COALESCE(mi.name, CONCAT(\'Item #\', oi.menu_item_id))';
        $joinMenu = $hasOrderItemsMenuId ? 'LEFT JOIN menu_items mi ON mi.id = oi.menu_item_id' : 'LEFT JOIN menu_items mi ON 1=0';
        $hasIngredients = function_exists('db_column_exists') && db_column_exists('menu_items', 'ingredients');
        $hasWeight = function_exists('db_column_exists') && db_column_exists('menu_items', 'weight_grams');
        $ingredientsExpr = $hasIngredients ? 'mi.ingredients' : 'NULL';
        $weightExpr = $hasWeight ? 'mi.weight_grams' : 'NULL';
        $days = max(7, min(60, (int)($options['history_days'] ?? 21)));
        $startAt = date('Y-m-d 00:00:00', strtotime('-' . ($days - 1) . ' days'));
        $endAt = date('Y-m-d 23:59:59');

        try {
            $stmt = $pdo->prepare("
                SELECT
                    oi.menu_item_id,
                    {$itemNameExpr} AS item_name,
                    {$ingredientsExpr} AS ingredients,
                    {$weightExpr} AS weight_grams,
                    SUM({$qtyExpr}) AS qty_total,
                    COUNT(DISTINCT o.id) AS orders_count
                FROM order_items oi
                INNER JOIN orders o ON o.id = oi.order_id
                {$joinMenu}
                WHERE o.restaurant_id = :rest
                  AND o.created_at >= :start_at
                  AND o.created_at <= :end_at
                  {$statusFilter}
                GROUP BY oi.menu_item_id, {$itemNameExpr}, {$ingredientsExpr}, {$weightExpr}
                ORDER BY qty_total DESC
                LIMIT 40
            ");
            $stmt->execute([
                ':rest' => $restaurantId,
                ':start_at' => $startAt,
                ':end_at' => $endAt,
            ]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return $out;
        }

        $most = [];
        $fast = [];
        $risk = [];
        $ingredientsEst = [];
        foreach ($rows as $row) {
            $qty = (float)($row['qty_total'] ?? 0);
            $orders = (int)($row['orders_count'] ?? 0);
            $name = trim((string)($row['item_name'] ?? ''));
            $name = $name !== '' ? $name : ('Item #' . (int)($row['menu_item_id'] ?? 0));
            $perDay = round($qty / max(1, $days), 2);
            $weight = (float)($row['weight_grams'] ?? 0);
            $consumptionGrams = $weight > 0 ? round($qty * $weight, 0) : 0.0;
            $pressureScore = min(100, (int)round(($perDay * 8) + ($orders * 1.2) + ($consumptionGrams > 0 ? min(30, $consumptionGrams / 1000) : 0)));

            $item = [
                'menu_item_id' => (int)($row['menu_item_id'] ?? 0),
                'item_name' => $name,
                'qty_total' => round($qty, 2),
                'orders_count' => $orders,
                'per_day' => $perDay,
                'weight_grams' => $weight > 0 ? (int)round($weight) : null,
                'consumption_grams_est' => $consumptionGrams,
                'pressure_score' => $pressureScore,
            ];
            $most[] = $item;
            if ($perDay >= 3.0) {
                $fast[] = $item;
            }
            if ($pressureScore >= 60) {
                $risk[] = $item;
            }
            $ingredients = trim((string)($row['ingredients'] ?? ''));
            if ($ingredients !== '') {
                $ingredientsEst[] = [
                    'item_name' => $name,
                    'ingredients' => $ingredients,
                    'consumption_grams_est' => $consumptionGrams,
                ];
            }
        }

        $out['most_ordered_items'] = array_slice($most, 0, 10);
        $out['fast_moving_items'] = array_slice($fast, 0, 10);
        $out['high_risk_items'] = array_slice($risk, 0, 10);
        $out['ingredient_consumption_estimation'] = array_slice($ingredientsEst, 0, 10);
        return $out;
    }
}

if (!function_exists('forecast_alerts')) {
    /**
     * @param array<string,mixed> $forecastPayload
     * @return list<array{key:string,level:string,label:string,message:string}>
     */
    function forecast_alerts(array $forecastPayload): array
    {
        $alerts = [];
        $orders = is_array($forecastPayload['orders'] ?? null) ? $forecastPayload['orders'] : [];
        $delivery = is_array($forecastPayload['delivery_load'] ?? null) ? $forecastPayload['delivery_load'] : [];
        $kitchen = is_array($forecastPayload['kitchen_workload'] ?? null) ? $forecastPayload['kitchen_workload'] : [];
        $staffing = is_array($forecastPayload['staffing_need'] ?? null) ? $forecastPayload['staffing_need'] : [];
        $inventory = is_array($forecastPayload['inventory_pressure'] ?? null) ? $forecastPayload['inventory_pressure'] : [];

        if ((int)($orders['expected_orders'] ?? 0) >= 40 || count((array)($orders['peak_hours'] ?? [])) >= 3) {
            $alerts[] = [
                'key' => 'peak_expected',
                'level' => 'warning',
                'label' => 'Peak expected',
                'message' => 'Ожидается пиковая загрузка по заказам, проверьте смену и станции.',
            ];
        }
        if ((string)($delivery['predicted_sla_risk'] ?? 'low') === 'high') {
            $alerts[] = [
                'key' => 'courier_shortage_forecast',
                'level' => 'critical',
                'label' => 'Courier shortage forecast',
                'message' => 'Высокий риск SLA в доставке, нужно усилить courier coverage.',
            ];
        }
        if (!empty($kitchen['prep_overload_risk'])) {
            $alerts[] = [
                'key' => 'kitchen_congestion_risk',
                'level' => 'warning',
                'label' => 'Kitchen congestion risk',
                'message' => 'Ожидается перегрузка станции, заранее перераспределите поток.',
            ];
        }
        if (!empty($staffing['understaff_risk'])) {
            $alerts[] = [
                'key' => 'understaff_risk',
                'level' => 'warning',
                'label' => 'Understaff risk',
                'message' => 'Текущей команды может не хватить для следующего окна нагрузки.',
            ];
        }
        $highRiskItems = is_array($inventory['high_risk_items'] ?? null) ? $inventory['high_risk_items'] : [];
        if ($highRiskItems !== []) {
            $top = (array)$highRiskItems[0];
            $alerts[] = [
                'key' => 'stock_pressure_warning',
                'level' => 'warning',
                'label' => 'Stock pressure warning',
                'message' => 'Быстро растёт спрос на "' . (string)($top['item_name'] ?? 'item') . '".',
            ];
        }

        return $alerts;
    }
}

if (!function_exists('forecast_operational_foundation')) {
    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    function forecast_operational_foundation(PDO $pdo, int $restaurantId, array $options = []): array
    {
        $target = strtolower(trim((string)($options['target'] ?? 'today')));
        if (!in_array($target, ['today', 'tomorrow', 'next_shift', 'next_daypart'], true)) {
            $target = 'today';
        }
        $ctx = ['target' => $target] + $options;
        $orders = forecast_orders($pdo, $restaurantId, $ctx);
        $revenue = forecast_revenue($pdo, $restaurantId, $ctx);
        $delivery = forecast_delivery_load($pdo, $restaurantId, $ctx);
        $zones = forecast_zone_pressure($pdo, $restaurantId, $ctx);
        $kitchen = forecast_kitchen_workload($pdo, $restaurantId, $ctx);
        $staffing = forecast_staffing_need($pdo, $restaurantId, $ctx);
        $inventory = forecast_inventory_pressure($pdo, $restaurantId, $ctx);

        $payload = [
            'target' => $target,
            'orders' => $orders,
            'revenue' => $revenue,
            'delivery_load' => $delivery,
            'zone_pressure' => $zones,
            'kitchen_workload' => $kitchen,
            'staffing_need' => $staffing,
            'inventory_pressure' => $inventory,
            'ai_ready' => [
                'version' => 'forecast-foundation-v1',
                'hooks' => [
                    'orders_model_input' => ['weekday_pattern', 'hourly_pattern', 'trend_coefficient'],
                    'delivery_model_input' => ['expected_delivery_orders', 'zone_pressure', 'sla_risk'],
                    'kitchen_model_input' => ['station_load', 'expected_tickets_per_hour'],
                    'staffing_model_input' => ['recommended_counts', 'current_active_counts', 'gaps'],
                    'inventory_model_input' => ['fast_moving_items', 'pressure_score'],
                ],
                'extensible' => true,
            ],
        ];
        $payload['alerts'] = forecast_alerts($payload);
        return $payload;
    }
}

if (!function_exists('qa_runtime_warn_once')) {
    /**
     * Lightweight throttled QA warning logger.
     * One key per minute per host to avoid noisy spam.
     *
     * @param array<string,mixed> $context
     */
    function qa_runtime_warn_once(string $key, string $message, array $context = []): void
    {
        static $requestSeen = [];
        $normalized = strtoupper(preg_replace('/[^A-Z0-9_]+/', '_', trim($key)) ?: 'QA_WARN');
        if (isset($requestSeen[$normalized])) {
            return;
        }
        $requestSeen[$normalized] = true;

        $minuteKey = date('YmdHi');
        $lockFile = rtrim(sys_get_temp_dir(), '/\\') . '/qrrest_' . strtolower($normalized) . '_' . $minuteKey . '.lock';
        if (is_file($lockFile)) {
            return;
        }
        @touch($lockFile);

        $ctx = '';
        if ($context !== []) {
            $json = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $ctx = is_string($json) ? (' ' . $json) : '';
        }
        error_log('QA_WARN[' . $normalized . '] ' . $message . $ctx);
    }
}

if (!function_exists('operational_schema_presence')) {
    /**
     * @param list<string> $requiredTables
     * @param array<string,list<string>> $requiredColumns
     * @return array{missing_tables:list<string>,missing_columns:list<string>}
     */
    function operational_schema_presence(PDO $pdo, array $requiredTables, array $requiredColumns = []): array
    {
        $missingTables = [];
        $missingColumns = [];

        foreach ($requiredTables as $table) {
            $table = trim((string)$table);
            if ($table === '') {
                continue;
            }
            if (!(function_exists('db_table_exists') && db_table_exists($table))) {
                $missingTables[] = $table;
            }
        }

        foreach ($requiredColumns as $table => $columns) {
            $tableName = trim((string)$table);
            if ($tableName === '' || !(function_exists('db_table_exists') && db_table_exists($tableName))) {
                continue;
            }
            foreach ($columns as $column) {
                $col = trim((string)$column);
                if ($col === '') {
                    continue;
                }
                if (!(function_exists('db_column_exists') && db_column_exists($tableName, $col))) {
                    $missingColumns[] = $tableName . '.' . $col;
                }
            }
        }

        return [
            'missing_tables' => array_values(array_unique($missingTables)),
            'missing_columns' => array_values(array_unique($missingColumns)),
        ];
    }
}

if (!function_exists('operational_health_summary')) {
    /**
     * Unified runtime smoke-test/readiness summary for staff internal QA.
     *
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    function operational_health_summary(PDO $pdo, int $restaurantId, array $options = []): array
    {
        $summary = [
            'generated_at' => date('Y-m-d H:i:s'),
            'restaurant_id' => $restaurantId,
            'runtime_integrity' => [
                'db_ready' => $pdo instanceof PDO,
                'tenant_safe_context' => $restaurantId > 0,
                'runtime_schema_bootstrap' => function_exists('runtime_schema_ensure_orders_order_type'),
                'schema_helpers' => function_exists('db_table_exists') && function_exists('db_column_exists'),
                'forecasting_helpers' => function_exists('forecast_operational_foundation'),
                'delivery_analytics_helpers' => function_exists('delivery_operational_analytics'),
                'runtime_ensure_functions' => [
                    'orders_order_type' => function_exists('runtime_schema_ensure_orders_order_type'),
                    'courier_meta' => function_exists('runtime_schema_ensure_orders_courier_meta'),
                    'courier_locations' => function_exists('runtime_schema_ensure_courier_locations'),
                    'courier_shifts' => function_exists('runtime_schema_ensure_courier_shifts'),
                    'courier_earnings' => function_exists('runtime_schema_ensure_courier_earnings'),
                    'delivery_zones' => function_exists('runtime_schema_ensure_delivery_zones'),
                    'order_tips' => function_exists('runtime_schema_ensure_order_tips'),
                    'table_reservations' => function_exists('runtime_schema_ensure_table_reservations'),
                    'guest_reviews' => function_exists('runtime_schema_ensure_guest_reviews'),
                    'guest_profiles' => function_exists('runtime_schema_ensure_guest_profiles'),
                    'promotions' => function_exists('runtime_schema_ensure_promotions'),
                ],
            ],
            'modules' => [],
            'flows' => [],
            'warnings' => [],
            'degraded_modules' => [],
            'degraded_count' => 0,
        ];

        $moduleDefs = [
            'dashboards' => [
                'label' => 'Dashboards + Analytics',
                'required_tables' => ['orders'],
                'required_columns' => ['orders' => ['id', 'restaurant_id', 'created_at']],
                'optional_tables' => ['guest_profiles', 'order_tips', 'guest_reviews', 'restaurant_promo_usages'],
            ],
            'courier' => [
                'label' => 'Courier / Dispatch / ETA / GPS',
                'required_tables' => ['orders'],
                'required_columns' => ['orders' => ['order_type', 'courier_status']],
                'optional_tables' => ['courier_shifts', 'courier_locations', 'delivery_zones', 'courier_earnings', 'order_tips'],
            ],
            'kds' => [
                'label' => 'Kitchen / KDS stations',
                'required_tables' => ['orders', 'order_items'],
                'required_columns' => ['orders' => ['order_status', 'created_at']],
                'optional_tables' => ['menu_items', 'kitchen_stations'],
                'optional_columns' => ['order_items' => ['quantity', 'qty', 'station_status', 'started_at', 'ready_at'], 'menu_items' => ['station']],
            ],
            'guest' => [
                'label' => 'Guest flow (QR / checkout / tracking)',
                'required_tables' => ['menu_items', 'orders', 'order_items'],
                'required_columns' => ['orders' => ['created_at']],
                'optional_columns' => ['orders' => ['order_type', 'customer_phone', 'delivery_address', 'scheduled_for']],
                'optional_tables' => ['restaurant_promotions', 'restaurant_promo_usages', 'guest_loyalty_tx', 'guest_reviews', 'table_reservations'],
            ],
            'loyalty_crm' => [
                'label' => 'Loyalty + CRM/RFM',
                'required_tables' => ['guests', 'guest_cards', 'loyalty_accounts', 'loyalty_transactions', 'guest_profiles'],
                'required_columns' => ['guests' => ['phone'], 'guest_cards' => ['guest_id', 'restaurant_id'], 'loyalty_accounts' => ['card_id', 'balance']],
                'optional_tables' => ['guest_loyalty_accounts', 'guest_loyalty_tx'],
            ],
            'promotions_upsell' => [
                'label' => 'Promotions + Upsell',
                'required_tables' => ['menu_items'],
                'optional_tables' => ['restaurant_promotions', 'restaurant_promo_usages'],
            ],
            'reservations' => [
                'label' => 'Reservations + Floorplan',
                'required_tables' => ['tables'],
                'optional_tables' => ['table_reservations'],
            ],
            'forecasting' => [
                'label' => 'Forecasting + AI-ready foundation',
                'required_tables' => ['orders'],
                'required_columns' => ['orders' => ['created_at']],
                'optional_tables' => ['order_items', 'courier_earnings', 'delivery_zones'],
            ],
        ];

        foreach ($moduleDefs as $moduleKey => $def) {
            $requiredTables = is_array($def['required_tables'] ?? null) ? $def['required_tables'] : [];
            $requiredColumns = is_array($def['required_columns'] ?? null) ? $def['required_columns'] : [];
            $presence = operational_schema_presence($pdo, $requiredTables, $requiredColumns);

            $optionalTables = is_array($def['optional_tables'] ?? null) ? $def['optional_tables'] : [];
            $optionalMissingTables = [];
            foreach ($optionalTables as $optTable) {
                $t = trim((string)$optTable);
                if ($t === '') {
                    continue;
                }
                if (!(function_exists('db_table_exists') && db_table_exists($t))) {
                    $optionalMissingTables[] = $t;
                }
            }
            $optionalColumns = is_array($def['optional_columns'] ?? null) ? $def['optional_columns'] : [];
            $optionalMissingColumns = [];
            foreach ($optionalColumns as $table => $cols) {
                if (!(function_exists('db_table_exists') && db_table_exists((string)$table))) {
                    continue;
                }
                foreach ((array)$cols as $col) {
                    $c = trim((string)$col);
                    if ($c === '') {
                        continue;
                    }
                    if (!(function_exists('db_column_exists') && db_column_exists((string)$table, $c))) {
                        $optionalMissingColumns[] = (string)$table . '.' . $c;
                    }
                }
            }

            $isOk = ($presence['missing_tables'] === [] && $presence['missing_columns'] === []);
            $status = $isOk ? 'ok' : 'degraded';
            $summary['modules'][$moduleKey] = [
                'label' => (string)($def['label'] ?? $moduleKey),
                'status' => $status,
                'missing_tables' => $presence['missing_tables'],
                'missing_columns' => $presence['missing_columns'],
                'optional_missing_tables' => $optionalMissingTables,
                'optional_missing_columns' => $optionalMissingColumns,
            ];

            if (!$isOk) {
                $summary['degraded_modules'][] = $moduleKey;
                $summary['warnings'][] = [
                    'key' => 'module_degraded_' . $moduleKey,
                    'level' => 'warning',
                    'label' => (string)($def['label'] ?? $moduleKey) . ' degraded',
                    'message' => 'Missing schema: '
                        . implode(', ', array_merge($presence['missing_tables'], $presence['missing_columns'])),
                ];
                qa_runtime_warn_once(
                    'module_degraded_' . $moduleKey,
                    'Operational module degraded: ' . (string)($def['label'] ?? $moduleKey),
                    [
                        'restaurant_id' => $restaurantId,
                        'missing_tables' => $presence['missing_tables'],
                        'missing_columns' => $presence['missing_columns'],
                    ]
                );
            } elseif (($optionalMissingTables !== [] || $optionalMissingColumns !== []) && !empty($options['include_optional_warnings'])) {
                $summary['warnings'][] = [
                    'key' => 'module_optional_' . $moduleKey,
                    'level' => 'info',
                    'label' => (string)($def['label'] ?? $moduleKey) . ' optional fallback',
                    'message' => 'Optional schema missing: '
                        . implode(', ', array_merge($optionalMissingTables, $optionalMissingColumns)),
                ];
            }
        }

        $summary['degraded_count'] = count($summary['degraded_modules']);

        $summary['flows'] = [
            'dashboards' => (($summary['modules']['dashboards']['status'] ?? 'degraded') === 'ok'),
            'courier_flow' => (($summary['modules']['courier']['status'] ?? 'degraded') === 'ok'),
            'kitchen_flow' => (($summary['modules']['kds']['status'] ?? 'degraded') === 'ok'),
            'guest_flow' => (($summary['modules']['guest']['status'] ?? 'degraded') === 'ok'),
            'loyalty_crm_flow' => (($summary['modules']['loyalty_crm']['status'] ?? 'degraded') === 'ok'),
            'promo_upsell_flow' => (($summary['modules']['promotions_upsell']['status'] ?? 'degraded') === 'ok'),
            'reservation_flow' => (($summary['modules']['reservations']['status'] ?? 'degraded') === 'ok'),
            'forecasting_flow' => (($summary['modules']['forecasting']['status'] ?? 'degraded') === 'ok')
                && !empty($summary['runtime_integrity']['forecasting_helpers']),
        ];

        // Run lightweight, guarded readiness probes.
        if (function_exists('forecast_operational_foundation') && $restaurantId > 0) {
            try {
                $forecastProbe = forecast_operational_foundation($pdo, $restaurantId, ['target' => 'next_shift']);
                $summary['forecast_probe'] = [
                    'ok' => true,
                    'expected_orders' => (int)($forecastProbe['orders']['expected_orders'] ?? 0),
                    'delivery_orders' => (int)($forecastProbe['delivery_load']['expected_delivery_orders'] ?? 0),
                    'alerts' => is_array($forecastProbe['alerts'] ?? null) ? count($forecastProbe['alerts']) : 0,
                ];
            } catch (Throwable $e) {
                $summary['forecast_probe'] = [
                    'ok' => false,
                    'error' => 'forecast_probe_failed',
                ];
                $summary['warnings'][] = [
                    'key' => 'forecast_probe_failed',
                    'level' => 'warning',
                    'label' => 'Forecast probe failed',
                    'message' => 'Forecast layer degraded, fallback mode enabled.',
                ];
                qa_runtime_warn_once('forecast_probe_failed', 'Forecast probe failed', ['restaurant_id' => $restaurantId]);
            }
        }

        if (function_exists('delivery_operational_analytics') && $restaurantId > 0) {
            try {
                $p = function_exists('analytics_period_bounds') ? analytics_period_bounds('today') : ['start_at' => date('Y-m-d 00:00:00'), 'end_at' => date('Y-m-d H:i:s')];
                $deliveryProbe = delivery_operational_analytics($pdo, $restaurantId, $p);
                $summary['delivery_probe'] = [
                    'ok' => true,
                    'sla_success_percent' => (float)($deliveryProbe['sla']['sla_success_percent'] ?? 0),
                    'hotspot_alerts' => is_array($deliveryProbe['alerts'] ?? null) ? count($deliveryProbe['alerts']) : 0,
                ];
            } catch (Throwable $e) {
                $summary['delivery_probe'] = ['ok' => false, 'error' => 'delivery_probe_failed'];
                qa_runtime_warn_once('delivery_probe_failed', 'Delivery analytics probe failed', ['restaurant_id' => $restaurantId]);
            }
        }

        return $summary;
    }
}

if (!function_exists('analytics_loyalty_summary')) {
    /**
     * @param array<string,mixed> $period
     * @return array{
     *   usage_orders_count:int,
     *   tx_count:int,
     *   earn_points:int,
     *   spend_points:int,
     *   adjustment_points:int,
     *   refund_points:int
     * }
     */
    function analytics_loyalty_summary(PDO $pdo, int $restaurantId, array $period): array
    {
        $out = [
            'usage_orders_count' => 0,
            'tx_count' => 0,
            'earn_points' => 0,
            'spend_points' => 0,
            'adjustment_points' => 0,
            'refund_points' => 0,
        ];
        if ($restaurantId <= 0) {
            return $out;
        }

        if (
            function_exists('db_table_exists') && db_table_exists('orders')
            && function_exists('db_column_exists') && db_column_exists('orders', 'loyalty_points_spent')
        ) {
            try {
                $stmtUsage = $pdo->prepare("
                    SELECT COUNT(*) AS cnt
                    FROM orders o
                    WHERE o.restaurant_id = :restaurant_id
                      AND o.created_at >= :start_at
                      AND o.created_at <= :end_at
                      AND o.loyalty_points_spent > 0
                ");
                $stmtUsage->execute([
                    ':restaurant_id' => $restaurantId,
                    ':start_at' => (string)($period['start_at'] ?? ''),
                    ':end_at' => (string)($period['end_at'] ?? ''),
                ]);
                $out['usage_orders_count'] = (int)($stmtUsage->fetchColumn() ?: 0);
            } catch (Throwable $e) {
                $out['usage_orders_count'] = 0;
            }
        }

        if (function_exists('db_table_exists') && db_table_exists('guest_loyalty_tx')) {
            try {
                $stmtTx = $pdo->prepare("
                    SELECT
                        COUNT(*) AS cnt,
                        COALESCE(SUM(CASE WHEN type IN ('accrual', 'earn') THEN points ELSE 0 END), 0) AS earn_points,
                        COALESCE(SUM(CASE WHEN type IN ('spend', 'redeem') THEN points ELSE 0 END), 0) AS spend_points,
                        COALESCE(SUM(CASE WHEN type IN ('adjust', 'manual_adjustment') THEN points ELSE 0 END), 0) AS adjustment_points,
                        COALESCE(SUM(CASE WHEN type IN ('refund') THEN points ELSE 0 END), 0) AS refund_points
                    FROM guest_loyalty_tx
                    WHERE restaurant_id = :restaurant_id
                      AND created_at >= :start_at
                      AND created_at <= :end_at
                ");
                $stmtTx->execute([
                    ':restaurant_id' => $restaurantId,
                    ':start_at' => (string)($period['start_at'] ?? ''),
                    ':end_at' => (string)($period['end_at'] ?? ''),
                ]);
                $row = $stmtTx->fetch(PDO::FETCH_ASSOC) ?: [];
                $out['tx_count'] = (int)($row['cnt'] ?? 0);
                $out['earn_points'] = (int)($row['earn_points'] ?? 0);
                $out['spend_points'] = (int)($row['spend_points'] ?? 0);
                $out['adjustment_points'] = (int)($row['adjustment_points'] ?? 0);
                $out['refund_points'] = (int)($row['refund_points'] ?? 0);
                return $out;
            } catch (Throwable $e) {
                // fallback to legacy table below
            }
        }

        if (function_exists('db_table_exists') && db_table_exists('loyalty_transactions')) {
            try {
                $stmtTx = $pdo->prepare("
                    SELECT
                        COUNT(*) AS cnt,
                        COALESCE(SUM(CASE WHEN type IN ('accrual', 'earn') THEN points ELSE 0 END), 0) AS earn_points,
                        COALESCE(SUM(CASE WHEN type IN ('spend', 'redeem') THEN points ELSE 0 END), 0) AS spend_points,
                        COALESCE(SUM(CASE WHEN type IN ('adjust', 'manual_adjustment') THEN points ELSE 0 END), 0) AS adjustment_points,
                        COALESCE(SUM(CASE WHEN type IN ('refund') THEN points ELSE 0 END), 0) AS refund_points
                    FROM loyalty_transactions lt
                    INNER JOIN guest_cards gc ON gc.id = lt.card_id
                    WHERE gc.restaurant_id = :restaurant_id
                      AND lt.created_at >= :start_at
                      AND lt.created_at <= :end_at
                ");
                $stmtTx->execute([
                    ':restaurant_id' => $restaurantId,
                    ':start_at' => (string)($period['start_at'] ?? ''),
                    ':end_at' => (string)($period['end_at'] ?? ''),
                ]);
                $row = $stmtTx->fetch(PDO::FETCH_ASSOC) ?: [];
                $out['tx_count'] = (int)($row['cnt'] ?? 0);
                $out['earn_points'] = (int)($row['earn_points'] ?? 0);
                $out['spend_points'] = (int)($row['spend_points'] ?? 0);
                $out['adjustment_points'] = (int)($row['adjustment_points'] ?? 0);
                $out['refund_points'] = (int)($row['refund_points'] ?? 0);
            } catch (Throwable $e) {
                return $out;
            }
        }

        return $out;
    }
}

if (!function_exists('analytics_reservation_summary')) {
    /**
     * @param array<string,mixed> $period
     * @return array{
     *   created_count:int,
     *   confirmed_count:int,
     *   seated_count:int,
     *   completed_count:int,
     *   cancelled_count:int,
     *   no_show_count:int,
     *   upcoming_count:int,
     *   current_count:int,
     *   occupancy_estimate:int
     * }
     */
    function analytics_reservation_summary(PDO $pdo, int $restaurantId, array $period): array
    {
        $out = [
            'created_count' => 0,
            'confirmed_count' => 0,
            'seated_count' => 0,
            'completed_count' => 0,
            'cancelled_count' => 0,
            'no_show_count' => 0,
            'upcoming_count' => 0,
            'current_count' => 0,
            'occupancy_estimate' => 0,
        ];
        if ($restaurantId <= 0 || !function_exists('guest_history_has_table') || !guest_history_has_table($pdo, 'table_reservations')) {
            return $out;
        }

        try {
            $stmt = $pdo->prepare("
                SELECT
                    COUNT(*) AS created_count,
                    SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) AS confirmed_count,
                    SUM(CASE WHEN status = 'seated' THEN 1 ELSE 0 END) AS seated_count,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed_count,
                    SUM(CASE WHEN status IN ('cancelled', 'canceled') THEN 1 ELSE 0 END) AS cancelled_count,
                    SUM(CASE WHEN status = 'no_show' THEN 1 ELSE 0 END) AS no_show_count
                FROM table_reservations
                WHERE restaurant_id = :restaurant_id
                  AND created_at >= :start_at
                  AND created_at <= :end_at
            ");
            $stmt->execute([
                ':restaurant_id' => $restaurantId,
                ':start_at' => (string)($period['start_at'] ?? ''),
                ':end_at' => (string)($period['end_at'] ?? ''),
            ]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $out['created_count'] = (int)($row['created_count'] ?? 0);
            $out['confirmed_count'] = (int)($row['confirmed_count'] ?? 0);
            $out['seated_count'] = (int)($row['seated_count'] ?? 0);
            $out['completed_count'] = (int)($row['completed_count'] ?? 0);
            $out['cancelled_count'] = (int)($row['cancelled_count'] ?? 0);
            $out['no_show_count'] = (int)($row['no_show_count'] ?? 0);
        } catch (Throwable $e) {
            return $out;
        }

        if (function_exists('reservation_summary')) {
            try {
                $live = reservation_summary($pdo, $restaurantId, ['horizon_minutes' => 240, 'limit' => 5]);
                $out['upcoming_count'] = (int)($live['upcoming_count'] ?? 0);
                $out['current_count'] = (int)($live['current_count'] ?? 0);
                $out['occupancy_estimate'] = (int)($live['occupancy_estimate'] ?? 0);
            } catch (Throwable $e) {
                // keep fallback values
            }
        }

        return $out;
    }
}

if (!function_exists('analytics_guest_summary')) {
    /**
     * @param array<string,mixed> $period
     * @return array{
     *   known_guests:int,
     *   active_guests_period:int,
     *   repeat_guests_period:int,
     *   new_guests_period:int,
     *   repeat_rate_percent:float
     * }
     */
    function analytics_guest_summary(PDO $pdo, int $restaurantId, array $period): array
    {
        $out = [
            'known_guests' => 0,
            'active_guests_period' => 0,
            'repeat_guests_period' => 0,
            'new_guests_period' => 0,
            'repeat_rate_percent' => 0.0,
        ];
        if ($restaurantId <= 0 || !function_exists('guest_history_has_table') || !guest_history_has_table($pdo, 'guest_profiles')) {
            return $out;
        }

        try {
            $stmt = $pdo->prepare("
                SELECT
                    COUNT(*) AS known_guests,
                    SUM(CASE WHEN last_order_at >= :start_at AND last_order_at <= :end_at THEN 1 ELSE 0 END) AS active_guests_period,
                    SUM(CASE WHEN orders_count > 1 AND last_order_at >= :start_at AND last_order_at <= :end_at THEN 1 ELSE 0 END) AS repeat_guests_period,
                    SUM(CASE WHEN orders_count <= 1 AND first_order_at >= :start_at AND first_order_at <= :end_at THEN 1 ELSE 0 END) AS new_guests_period
                FROM guest_profiles
                WHERE restaurant_id = :restaurant_id
            ");
            $stmt->execute([
                ':restaurant_id' => $restaurantId,
                ':start_at' => (string)($period['start_at'] ?? ''),
                ':end_at' => (string)($period['end_at'] ?? ''),
            ]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $out['known_guests'] = (int)($row['known_guests'] ?? 0);
            $out['active_guests_period'] = (int)($row['active_guests_period'] ?? 0);
            $out['repeat_guests_period'] = (int)($row['repeat_guests_period'] ?? 0);
            $out['new_guests_period'] = (int)($row['new_guests_period'] ?? 0);
            $active = max(0, $out['active_guests_period']);
            $out['repeat_rate_percent'] = $active > 0
                ? round(((float)$out['repeat_guests_period'] * 100.0) / $active, 2)
                : 0.0;
        } catch (Throwable $e) {
            return $out;
        }

        return $out;
    }
}

if (!function_exists('analytics_promo_summary')) {
    /**
     * @param array<string,mixed> $period
     * @return array{usage_count:int,orders_with_promo:int}
     */
    function analytics_promo_summary(PDO $pdo, int $restaurantId, array $period): array
    {
        $out = ['usage_count' => 0, 'orders_with_promo' => 0];
        if ($restaurantId <= 0 || !function_exists('db_table_exists') || !db_table_exists('restaurant_promo_usages')) {
            return $out;
        }
        try {
            $stmt = $pdo->prepare("
                SELECT
                    COUNT(*) AS usage_count,
                    COUNT(DISTINCT order_id) AS orders_with_promo
                FROM restaurant_promo_usages
                WHERE restaurant_id = :restaurant_id
                  AND used_at >= :start_at
                  AND used_at <= :end_at
            ");
            $stmt->execute([
                ':restaurant_id' => $restaurantId,
                ':start_at' => (string)($period['start_at'] ?? ''),
                ':end_at' => (string)($period['end_at'] ?? ''),
            ]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $out['usage_count'] = (int)($row['usage_count'] ?? 0);
            $out['orders_with_promo'] = (int)($row['orders_with_promo'] ?? 0);
        } catch (Throwable $e) {
            return $out;
        }
        return $out;
    }
}

if (!function_exists('analytics_tips_summary')) {
    /**
     * @param array<string,mixed> $period
     * @return array{tips_paid_total:float,tips_paid_count:int,tips_pending_count:int,tips_avg:float}
     */
    function analytics_tips_summary(PDO $pdo, int $restaurantId, array $period): array
    {
        $out = [
            'tips_paid_total' => 0.0,
            'tips_paid_count' => 0,
            'tips_pending_count' => 0,
            'tips_avg' => 0.0,
        ];
        if ($restaurantId <= 0 || !function_exists('db_table_exists') || !db_table_exists('order_tips')) {
            return $out;
        }
        try {
            $stmt = $pdo->prepare("
                SELECT
                    COALESCE(SUM(CASE WHEN status = 'paid' THEN amount ELSE 0 END), 0) AS tips_paid_total,
                    SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) AS tips_paid_count,
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS tips_pending_count,
                    COALESCE(AVG(CASE WHEN status = 'paid' THEN amount END), 0) AS tips_avg
                FROM order_tips
                WHERE restaurant_id = :restaurant_id
                  AND created_at >= :start_at
                  AND created_at <= :end_at
            ");
            $stmt->execute([
                ':restaurant_id' => $restaurantId,
                ':start_at' => (string)($period['start_at'] ?? ''),
                ':end_at' => (string)($period['end_at'] ?? ''),
            ]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $out['tips_paid_total'] = round((float)($row['tips_paid_total'] ?? 0), 2);
            $out['tips_paid_count'] = (int)($row['tips_paid_count'] ?? 0);
            $out['tips_pending_count'] = (int)($row['tips_pending_count'] ?? 0);
            $out['tips_avg'] = round((float)($row['tips_avg'] ?? 0), 2);
        } catch (Throwable $e) {
            return $out;
        }
        return $out;
    }
}

if (!function_exists('analytics_reviews_summary')) {
    /**
     * @param array<string,mixed> $period
     * @return array{reviews_count:int,avg_rating:float,nps_score:?int,low_rating_count:int}
     */
    function analytics_reviews_summary(PDO $pdo, int $restaurantId, array $period): array
    {
        $out = [
            'reviews_count' => 0,
            'avg_rating' => 0.0,
            'nps_score' => null,
            'low_rating_count' => 0,
        ];
        if ($restaurantId <= 0 || !function_exists('db_table_exists') || !db_table_exists('guest_reviews')) {
            return $out;
        }
        try {
            $stmt = $pdo->prepare("
                SELECT
                    COUNT(*) AS reviews_count,
                    COALESCE(AVG(rating), 0) AS avg_rating,
                    SUM(CASE WHEN rating <= 2 THEN 1 ELSE 0 END) AS low_rating_count
                FROM guest_reviews
                WHERE restaurant_id = :restaurant_id
                  AND created_at >= :start_at
                  AND created_at <= :end_at
            ");
            $stmt->execute([
                ':restaurant_id' => $restaurantId,
                ':start_at' => (string)($period['start_at'] ?? ''),
                ':end_at' => (string)($period['end_at'] ?? ''),
            ]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $out['reviews_count'] = (int)($row['reviews_count'] ?? 0);
            $out['avg_rating'] = round((float)($row['avg_rating'] ?? 0), 2);
            $out['low_rating_count'] = (int)($row['low_rating_count'] ?? 0);
        } catch (Throwable $e) {
            return $out;
        }

        if (function_exists('guest_review_nps')) {
            $days = max(1, (int)ceil(((int)($period['duration_seconds'] ?? 86400)) / 86400));
            $nps = guest_review_nps($pdo, $restaurantId, $days);
            $out['nps_score'] = isset($nps['score']) ? ($nps['score'] !== null ? (int)$nps['score'] : null) : null;
        }

        return $out;
    }
}

if (!function_exists('analytics_hourly_orders')) {
    /**
     * @param array<string,mixed> $period
     * @return list<array{hour:int,count:int}>
     */
    function analytics_hourly_orders(PDO $pdo, int $restaurantId, array $period): array
    {
        $result = [];
        for ($h = 0; $h < 24; $h++) {
            $result[] = ['hour' => $h, 'count' => 0];
        }
        if ($restaurantId <= 0 || !function_exists('db_table_exists') || !db_table_exists('orders')) {
            return $result;
        }
        $hasOrderStatus = function_exists('db_column_exists') && db_column_exists('orders', 'order_status');
        $statusFilter = $hasOrderStatus ? "AND (order_status IS NULL OR (order_status <> 'canceled' AND order_status <> 'cancelled'))" : '';
        try {
            $stmt = $pdo->prepare("
                SELECT HOUR(created_at) AS h, COUNT(*) AS cnt
                FROM orders
                WHERE restaurant_id = :restaurant_id
                  AND created_at >= :start_at
                  AND created_at <= :end_at
                  {$statusFilter}
                GROUP BY HOUR(created_at)
            ");
            $stmt->execute([
                ':restaurant_id' => $restaurantId,
                ':start_at' => (string)($period['start_at'] ?? ''),
                ':end_at' => (string)($period['end_at'] ?? ''),
            ]);
            foreach (($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
                $h = (int)($row['h'] ?? -1);
                if ($h >= 0 && $h <= 23) {
                    $result[$h]['count'] = (int)($row['cnt'] ?? 0);
                }
            }
        } catch (Throwable $e) {
            return $result;
        }
        return $result;
    }
}

if (!function_exists('analytics_revenue_trend')) {
    /**
     * @param array<string,mixed> $period
     * @return list<array{date:string,revenue:float,orders_count:int}>
     */
    function analytics_revenue_trend(PDO $pdo, int $restaurantId, array $period): array
    {
        $out = [];
        if ($restaurantId <= 0 || !function_exists('db_table_exists') || !db_table_exists('orders')) {
            return $out;
        }
        $amountExpr = analytics_order_amount_expr($pdo, 'o');
        $hasOrderStatus = function_exists('db_column_exists') && db_column_exists('orders', 'order_status');
        $hasPaymentStatus = function_exists('db_column_exists') && db_column_exists('orders', 'payment_status');
        $statusFilter = $hasOrderStatus ? "AND (o.order_status IS NULL OR (o.order_status <> 'canceled' AND o.order_status <> 'cancelled'))" : '';
        $paidCase = $hasPaymentStatus
            ? "CASE WHEN o.payment_status = 'paid' THEN {$amountExpr} ELSE 0 END"
            : $amountExpr;
        try {
            $stmt = $pdo->prepare("
                SELECT
                    DATE(o.created_at) AS d,
                    COUNT(*) AS cnt,
                    COALESCE(SUM({$paidCase}), 0) AS rev
                FROM orders o
                WHERE o.restaurant_id = :restaurant_id
                  AND o.created_at >= :start_at
                  AND o.created_at <= :end_at
                  {$statusFilter}
                GROUP BY DATE(o.created_at)
                ORDER BY DATE(o.created_at) ASC
            ");
            $stmt->execute([
                ':restaurant_id' => $restaurantId,
                ':start_at' => (string)($period['start_at'] ?? ''),
                ':end_at' => (string)($period['end_at'] ?? ''),
            ]);
            foreach (($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
                $out[] = [
                    'date' => (string)($row['d'] ?? ''),
                    'revenue' => round((float)($row['rev'] ?? 0), 2),
                    'orders_count' => (int)($row['cnt'] ?? 0),
                ];
            }
        } catch (Throwable $e) {
            return $out;
        }
        return $out;
    }
}

if (!function_exists('analytics_operational_alerts')) {
    /**
     * @param array<string,mixed> $current
     * @return list<array{key:string,level:string,label:string,message:string}>
     */
    function analytics_operational_alerts(array $current): array
    {
        $alerts = [];
        $revenueTrend = $current['trends']['revenue'] ?? null;
        if (is_array($revenueTrend) && (float)($revenueTrend['delta_percent'] ?? 0) <= -20.0) {
            $alerts[] = [
                'key' => 'revenue_drop',
                'level' => 'warning',
                'label' => 'Падение выручки',
                'message' => 'Выручка снизилась более чем на 20% к предыдущему периоду.',
            ];
        }

        $overdue = (int)($current['delivery']['overdue_delivery_count'] ?? 0);
        if ($overdue > 0) {
            $alerts[] = [
                'key' => 'overdue_delivery',
                'level' => 'critical',
                'label' => 'Просроченные доставки',
                'message' => 'Есть ' . $overdue . ' заказ(ов) с риском SLA/просрочкой.',
            ];
        }

        $lowRating = (int)($current['reviews']['low_rating_count'] ?? 0);
        if ($lowRating > 0) {
            $alerts[] = [
                'key' => 'low_rating',
                'level' => 'warning',
                'label' => 'Низкие оценки',
                'message' => 'Обнаружено ' . $lowRating . ' отзыв(ов) с оценкой 1-2.',
            ];
        }

        $occupancy = (int)($current['reservations']['occupancy_estimate'] ?? 0);
        $upcoming = (int)($current['reservations']['upcoming_count'] ?? 0);
        if ($occupancy >= 80 && $upcoming > 0) {
            $alerts[] = [
                'key' => 'reservation_overload',
                'level' => 'warning',
                'label' => 'Перегрузка броней',
                'message' => 'Высокая загрузка по броням (' . $occupancy . '%). Проверьте рассадку.',
            ];
        }

        $waitingCourier = (int)($current['delivery']['waiting_courier_count'] ?? 0);
        if ($waitingCourier >= 3) {
            $alerts[] = [
                'key' => 'courier_queue',
                'level' => 'warning',
                'label' => 'Очередь на курьеров',
                'message' => 'Много заказов ожидают курьера: ' . $waitingCourier . '.',
            ];
        }

        $deliveryOpsAlerts = is_array($current['delivery_ops']['alerts'] ?? null)
            ? $current['delivery_ops']['alerts']
            : [];
        foreach ($deliveryOpsAlerts as $dAlert) {
            if (!is_array($dAlert)) {
                continue;
            }
            $alerts[] = [
                'key' => (string)($dAlert['key'] ?? 'delivery_ops'),
                'level' => (string)($dAlert['level'] ?? 'warning'),
                'label' => (string)($dAlert['label'] ?? 'Delivery alert'),
                'message' => (string)($dAlert['message'] ?? ''),
            ];
        }

        return $alerts;
    }
}

function generate_random_password(int $length = 10): string
{
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $out = '';
    $max = strlen($chars) - 1;
    for ($i = 0; $i < $length; $i++) {
        $out .= $chars[random_int(0, $max)];
    }
    return $out;
}


function slugify_restaurant(string $name): string
{
    $name = mb_strtolower($name, 'UTF-8');

    $map = [
        'а'=>'a','б'=>'b','в'=>'v','г'=>'g','д'=>'d','е'=>'e','ё'=>'e','ж'=>'zh','з'=>'z',
        'и'=>'i','й'=>'y','к'=>'k','л'=>'l','м'=>'m','н'=>'n','о'=>'o','п'=>'p','р'=>'r',
        'с'=>'s','т'=>'t','у'=>'u','ф'=>'f','х'=>'h','ц'=>'c','ч'=>'ch','ш'=>'sh','щ'=>'sch',
        'ъ'=>'','ы'=>'y','ь'=>'','э'=>'e','ю'=>'yu','я'=>'ya',
    ];
    $name = strtr($name, $map);
    $name = preg_replace('~[^a-z0-9]+~', '-', $name);
    $name = trim($name, '-');
    if ($name === '') {
        $name = 'rest-' . substr(md5((string)microtime(true)), 0, 6);
    }
    return $name;
}

/**
 * Лог действий.
 */
function log_action($userId, $restaurantId, string $action, string $message, string $level = 'info'): void
{
    // Берём PDO так же, как в остальном проекте
    $pdo = function_exists('db') ? db() : ($GLOBALS['pdo'] ?? null);
    if (!$pdo instanceof PDO) {
        return;
    }

    // Если есть наш новый хелпер add_log — используем его
    if (function_exists('add_log')) {
        try {
            add_log($pdo, [
                'user_id'       => $userId !== null ? (int)$userId : null,
                'restaurant_id' => $restaurantId !== null ? (int)$restaurantId : null,
                'level'         => $level,          // info / error / security и т.д.
                'action'        => $action,
                'message'       => $message,
            ]);
        } catch (Throwable $e) {
            // Лог не должен ломать приложение
        }
        return;
    }

    // Фолбэк, если add_log по какой-то причине не подключен
    try {
        $stmt = $pdo->prepare("
            INSERT INTO logs (user_id, restaurant_id, level, action, message, created_at)
            VALUES (:user_id, :restaurant_id, :level, :action, :message, NOW())
        ");
        $stmt->execute([
            ':user_id'       => $userId !== null ? (int)$userId : null,
            ':restaurant_id' => $restaurantId !== null ? (int)$restaurantId : null,
            ':level'         => $level,
            ':action'        => $action,
            ':message'       => $message,
        ]);
    } catch (Throwable $e) {
        // Тихо глушим
    }
}
