<?php

if (!function_exists('order_offline_payment_types')) {
    function order_offline_payment_types(): array
    {
        return ['cash', 'card_later', 'pay_later'];
    }
}

if (!function_exists('order_payment_timeout_seconds')) {
    function order_payment_timeout_seconds(): int
    {
        return 10 * 60;
    }
}

if (!function_exists('order_payment_timeout_warning_seconds')) {
    function order_payment_timeout_warning_seconds(): int
    {
        return 3 * 60;
    }
}

if (!function_exists('order_runtime_normalize_order_status')) {
    function order_runtime_normalize_order_status(string $status): string
    {
        $status = strtolower(trim($status));
        $map = [
            'created' => 'new',
            'pending' => 'new',
            'cancelled' => 'canceled',
            'expired' => 'canceled',
        ];

        return $map[$status] ?? $status;
    }
}

if (!function_exists('order_runtime_normalize_payment_status')) {
    function order_runtime_normalize_payment_status(string $status): string
    {
        $status = strtolower(trim($status));
        $map = [
            'pending_payment' => 'pending',
            'cancelled' => 'canceled',
        ];

        return $map[$status] ?? $status;
    }
}

if (!function_exists('order_payment_timeout_applies')) {
    function order_payment_timeout_applies(array $order): bool
    {
        $orderStatus = order_runtime_normalize_order_status((string)($order['order_status'] ?? 'new'));
        if ($orderStatus !== 'new') {
            return false;
        }

        $paymentStatus = order_runtime_normalize_payment_status((string)($order['payment_status'] ?? 'unpaid'));
        if (!in_array($paymentStatus, ['unpaid', 'pending'], true)) {
            return false;
        }

        $paymentType = strtolower(trim((string)($order['payment_type'] ?? '')));
        return in_array($paymentType, order_offline_payment_types(), true);
    }
}

if (!function_exists('order_payment_timer_meta')) {
    function order_payment_timer_meta(array $order, ?int $nowTs = null): array
    {
        $nowTs = $nowTs ?? time();
        $createdTs = strtotime((string)($order['created_at'] ?? ''));
        if (!$createdTs || !order_payment_timeout_applies($order)) {
            return [
                'active' => false,
                'expired' => false,
                'warning' => false,
                'seconds_remaining' => null,
                'mmss' => null,
            ];
        }

        $remaining = order_payment_timeout_seconds() - max(0, $nowTs - $createdTs);
        $secondsRemaining = max(0, (int)$remaining);
        $expired = $remaining <= 0;

        return [
            'active' => !$expired,
            'expired' => $expired,
            'warning' => !$expired && $secondsRemaining <= order_payment_timeout_warning_seconds(),
            'seconds_remaining' => $secondsRemaining,
            'mmss' => gmdate('i:s', $secondsRemaining),
        ];
    }
}

if (!function_exists('order_expire_due_orders')) {
    function order_expire_due_orders(PDO $pdo, int $restaurantId, ?int $orderId = null, ?int $tableId = null): int
    {
        if ($restaurantId <= 0) {
            return 0;
        }

        if (!function_exists('db_column_exists') && file_exists(__DIR__ . '/schema_guard.php')) {
            require_once __DIR__ . '/schema_guard.php';
        }
        $hasPaymentTypeCol = function_exists('db_column_exists') && db_column_exists('orders', 'payment_type');
        $paymentTypeCond = $hasPaymentTypeCol
            ? " AND LOWER(COALESCE(payment_type, '')) IN ('cash', 'card_later', 'pay_later')"
            : '';

        $sql = "
            UPDATE orders
            SET order_status = 'canceled',
                payment_status = 'canceled'
            WHERE restaurant_id = :restaurant_id
              AND LOWER(COALESCE(order_status, 'new')) IN ('new', 'created', 'pending')
              AND LOWER(COALESCE(payment_status, 'unpaid')) IN ('unpaid', 'pending', 'pending_payment')
              {$paymentTypeCond}
              AND created_at <= :expires_before
        ";
        $params = [
            ':restaurant_id' => $restaurantId,
            ':expires_before' => date('Y-m-d H:i:s', time() - order_payment_timeout_seconds()),
        ];

        if ($orderId !== null && $orderId > 0) {
            $sql .= " AND id = :order_id";
            $params[':order_id'] = $orderId;
        }
        if ($tableId !== null && $tableId > 0) {
            $sql .= " AND table_id = :table_id";
            $params[':table_id'] = $tableId;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return (int)$stmt->rowCount();
    }
}
