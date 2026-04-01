<?php
/**
 * Legacy phone-based loyalty (loyalty_accounts, loyalty_transactions).
 * Accrual is idempotent by order_id. Production canonical ledger is guest_loyalty (guest_cards, guest_loyalty_accounts, guest_loyalty_tx).
 */

if (!function_exists('loyalty_normalize_phone')) {

    function loyalty_normalize_phone(?string $phone): ?string {
        if (!$phone) return null;
        $digits = preg_replace('/\D+/', '', $phone);
        if ($digits === '') return null;

        // Если начинается с 8 и длина 11 → заменим 8 на 7
        if (strlen($digits) === 11 && $digits[0] === '8') {
            $digits[0] = '7';
        }
        return $digits;
    }
}

if (!function_exists('loyalty_is_enabled_for_restaurant')) {
    function loyalty_is_enabled_for_restaurant(array $restaurant): bool {
        return !empty($restaurant['loyalty_enabled']);
    }
}

if (!function_exists('loyalty_find_or_create_account')) {

    function loyalty_find_or_create_account(PDO $pdo, int $restaurantId, string $phone): array {
        $phone = loyalty_normalize_phone($phone);
        if (!$phone) {
            throw new InvalidArgumentException('Некорректный телефон для лояльности');
        }

        $stmt = $pdo->prepare("
            SELECT *
            FROM loyalty_accounts
            WHERE restaurant_id = :rid AND phone = :phone
            LIMIT 1
        ");
        $stmt->execute([
            ':rid'   => $restaurantId,
            ':phone' => $phone,
        ]);
        $acc = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($acc) {
            return $acc;
        }

        $stmt = $pdo->prepare("
            INSERT INTO loyalty_accounts (restaurant_id, phone)
            VALUES (:rid, :phone)
        ");
        $stmt->execute([
            ':rid'   => $restaurantId,
            ':phone' => $phone,
        ]);
        $id = (int)$pdo->lastInsertId();

        return [
            'id'             => $id,
            'restaurant_id'  => $restaurantId,
            'phone'          => $phone,
            'points_balance' => 0,
        ];
    }
}

if (!function_exists('loyalty_get_balance')) {
    function loyalty_get_balance(PDO $pdo, int $restaurantId, string $phone): int {
        $phone = loyalty_normalize_phone($phone);
        if (!$phone) return 0;

        $stmt = $pdo->prepare("
            SELECT points_balance
            FROM loyalty_accounts
            WHERE restaurant_id = :rid AND phone = :phone
            LIMIT 1
        ");
        $stmt->execute([
            ':rid'   => $restaurantId,
            ':phone' => $phone,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (int)$row['points_balance'] : 0;
    }
}

if (!function_exists('loyalty_add_points')) {

    /**
     * Legacy phone-based accrual. Idempotent when order_id is set: one order credits at most once.
     * Production should prefer guest_loyalty_add_points (canonical ledger) when guest is known.
     */
    function loyalty_add_points(PDO $pdo, int $restaurantId, string $phone, int $points, ?int $orderId = null): ?array {
        $points = (int)$points;
        if ($points <= 0) return null;

        $acc = loyalty_find_or_create_account($pdo, $restaurantId, $phone);
        $accountId = (int)$acc['id'];

        if ($orderId !== null && $orderId > 0) {
            $chk = $pdo->prepare("SELECT id FROM loyalty_transactions WHERE account_id = ? AND order_id = ? AND type = 'accrual' LIMIT 1");
            $chk->execute([$accountId, $orderId]);
            if ($chk->fetchColumn()) {
                $newBalance = loyalty_get_balance($pdo, $restaurantId, $phone);
                return ['account_id' => $accountId, 'points' => $points, 'balance' => $newBalance];
            }
        }

        $stmt = $pdo->prepare("
            UPDATE loyalty_accounts
            SET points_balance = points_balance + :p
            WHERE id = :id
        ");
        $stmt->execute([
            ':p'  => $points,
            ':id' => $accountId,
        ]);

        $stmt = $pdo->prepare("
            INSERT INTO loyalty_transactions (account_id, type, points, order_id, comment)
            VALUES (:aid, 'accrual', :p, :oid, :c)
        ");
        $stmt->execute([
            ':aid' => $accountId,
            ':p'   => $points,
            ':oid' => $orderId,
            ':c'   => 'Начисление бонусов за заказ',
        ]);

        $newBalance = loyalty_get_balance($pdo, $restaurantId, $phone);

        return [
            'account_id' => $accountId,
            'points'     => $points,
            'balance'    => $newBalance,
        ];
    }
}

if (!function_exists('loyalty_spend_points')) {

    function loyalty_spend_points(PDO $pdo, int $restaurantId, string $phone, int $points, ?int $orderId = null): array {
        $points = max(0, (int)$points);
        if ($points <= 0) {
            return ['spent' => 0, 'balance' => loyalty_get_balance($pdo, $restaurantId, $phone)];
        }

        $phoneNorm = loyalty_normalize_phone($phone);
        if (!$phoneNorm) {
            return ['spent' => 0, 'balance' => 0];
        }

        $stmt = $pdo->prepare("
            SELECT *
            FROM loyalty_accounts
            WHERE restaurant_id = :rid AND phone = :phone
            LIMIT 1
        ");
        $stmt->execute([
            ':rid'   => $restaurantId,
            ':phone' => $phoneNorm,
        ]);
        $acc = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$acc || (int)$acc['points_balance'] <= 0) {
            return ['spent' => 0, 'balance' => 0];
        }

        $balance = (int)$acc['points_balance'];
        $toSpend = min($points, $balance);

        if ($toSpend <= 0) {
            return ['spent' => 0, 'balance' => $balance];
        }

        $stmt = $pdo->prepare("
            UPDATE loyalty_accounts
            SET points_balance = points_balance - :p
            WHERE id = :id
        ");
        $stmt->execute([
            ':p'  => $toSpend,
            ':id' => $acc['id'],
        ]);

        $stmt = $pdo->prepare("
            INSERT INTO loyalty_transactions (account_id, type, points, order_id, comment)
            VALUES (:aid, 'spend', :p, :oid, :c)
        ");
        $stmt->execute([
            ':aid' => $acc['id'],
            ':p'   => $toSpend,
            ':oid' => $orderId,
            ':c'   => 'Списание бонусов при заказе',
        ]);

        $newBalance = $balance - $toSpend;

        return [
            'spent'   => $toSpend,
            'balance' => $newBalance,
        ];
    }
}
