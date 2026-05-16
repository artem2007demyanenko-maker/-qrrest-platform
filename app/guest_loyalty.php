<?php
/**
 * Guest-card loyalty: canonical source of truth for production.
 * Ledger: guest_cards, guest_loyalty_accounts, guest_loyalty_tx.
 * QR accrual (when guest exists), staff issue/scan, wallet/cabinet/analytics use this ledger.
 * Legacy phone-based (loyalty_accounts/loyalty_transactions) remains for backward compat and is idempotent by order_id.
 */

if (!function_exists('guest_app_config')) {
    function guest_app_config(): array {
        static $cfg = null;
        if (is_array($cfg)) return $cfg;
        $cfg = require __DIR__ . '/config.php';
        return $cfg;
    }
}

/**
 * HMAC key for card token signing. In production (APP_ENV=production) fails closed if key is missing or default.
 */
if (!function_exists('guest_card_hmac_key')) {
    function guest_card_hmac_key(): string {
        $cfg = guest_app_config();
        $key = $cfg['app']['guest_card_hmac_key'] ?? '';
        $env = $cfg['app']['env'] ?? '';
        $isProduction = (is_string($env) && strtolower($env) === 'production');
        $isDefault = (!is_string($key) || $key === '' || strlen($key) < 32
            || strpos($key, 'CHANGE_ME') !== false);
        if ($isProduction && $isDefault) {
            if (function_exists('error_log')) {
                error_log('guest_card_hmac_key: production requires app.guest_card_hmac_key (min 32 chars, no default).');
            }
            return '';
        }
        if (!is_string($key) || strlen($key) < 32) {
            $key = 'CHANGE_ME_guest_card_hmac_key_please_very_long_secret';
        }
        return $key;
    }
}

if (!function_exists('guest_uuid_v4')) {
    function guest_uuid_v4(): string {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}

if (!function_exists('guest_b64url')) {
    function guest_b64url(string $bin): string {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }
}

if (!function_exists('guest_card_sign')) {
    function guest_card_sign(string $publicUid): string {
        $key = guest_card_hmac_key();
        if ($key === '') {
            return '';
        }
        $mac = hash_hmac('sha256', $publicUid, $key, true);
        return guest_b64url($mac);
    }
}

if (!function_exists('guest_card_make_token')) {
    function guest_card_make_token(string $publicUid): string {
        $sig = guest_card_sign($publicUid);
        if ($sig === '') {
            return '';
        }
        return 'GC1:' . $publicUid . ':' . $sig;
    }
}

if (!function_exists('guest_card_parse_token')) {
    function guest_card_parse_token(string $token): ?array {
        $token = trim($token);
        if ($token === '') return null;

        // допускаем, что QR может содержать URL с ?token=...
        if (strpos($token, 'token=') !== false) {
            $parts = parse_url($token);
            if (!empty($parts['query'])) {
                parse_str($parts['query'], $q);
                if (!empty($q['token'])) $token = (string)$q['token'];
            }
        }

        if (!str_starts_with($token, 'GC1:')) return null;
        $parts = explode(':', $token);
        if (count($parts) !== 3) return null;

        [$prefix, $uid, $sig] = $parts;
        if (!$uid || !$sig) return null;

        $expected = guest_card_sign($uid);
        if (!hash_equals($expected, $sig)) return null;

        return ['public_uid' => $uid];
    }
}

if (!function_exists('restaurant_loyalty_enabled')) {
    function restaurant_loyalty_enabled(array $restaurantRow): bool {

        if (function_exists('loyalty_is_enabled_for_restaurant')) {
            return (bool)loyalty_is_enabled_for_restaurant($restaurantRow);
        }
        // запасной флаг
        return !empty($restaurantRow['loyalty_enabled']);
    }
}

if (!function_exists('loyalty_get_settings')) {
    function loyalty_get_settings(PDO $pdo, int $restaurant_id): array
    {
        $st = $pdo->prepare("SELECT * FROM restaurant_loyalty_settings WHERE restaurant_id=? LIMIT 1");
        $st->execute([$restaurant_id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            $row = [
                'restaurant_id' => $restaurant_id,
                'earn_percent' => '0.00',
                'enabled' => 0,
                'loyalty_return_mode_enabled' => 0,
            ];
        }

        return $row;
    }
}

if (!function_exists('guest_find_by_phone')) {
    function guest_find_by_phone(PDO $pdo, string $phoneNorm): ?array {
        $stmt = $pdo->prepare("SELECT * FROM guests WHERE phone = :p LIMIT 1");
        $stmt->execute([':p' => $phoneNorm]);
        $g = $stmt->fetch(PDO::FETCH_ASSOC);
        return $g ?: null;
    }
}

if (!function_exists('guest_loyalty_find_or_create_guest_by_phone')) {
    /**
     * Resolve canonical guest identity by phone. Creates a guests row when the phone is known
     * but the guest has not logged in yet, so first paid loyalty accrual can still succeed.
     */
    function guest_loyalty_find_or_create_guest_by_phone(PDO $pdo, string $phoneNorm, ?string $name = null): ?array {
        $phoneNorm = trim($phoneNorm);
        if ($phoneNorm === '') {
            return null;
        }

        $guest = guest_find_by_phone($pdo, $phoneNorm);
        if ($guest) {
            return $guest;
        }

        if (!function_exists('guest_insert_new')) {
            require_once __DIR__ . '/guest_auth.php';
        }

        $legacyPinHash = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
        $guestId = guest_insert_new($pdo, $phoneNorm, $name, $legacyPinHash);
        if ($guestId <= 0) {
            return guest_find_by_phone($pdo, $phoneNorm);
        }

        return guest_find_by_phone($pdo, $phoneNorm);
    }
}

if (!function_exists('guest_create_by_phone_with_pin')) {
    function guest_create_by_phone_with_pin(PDO $pdo, string $phoneNorm, ?string $name = null): array {
        $pin = guest_generate_pin();
        $hash = password_hash($pin, PASSWORD_DEFAULT);

        $name = $name !== null ? trim($name) : null;
        if ($name === '') $name = null;

        if (!function_exists('guest_insert_new')) {
            require_once __DIR__ . '/guest_auth.php';
        }
        $id = guest_insert_new($pdo, $phoneNorm, $name, $hash);
        return ['guest_id' => $id, 'pin' => $pin];
    }
}

if (!function_exists('guest_issue_card_for_restaurant')) {
    function guest_issue_card_for_restaurant(PDO $pdo, int $restaurantId, string $phoneRaw, ?string $name = null): array {
        $phoneNorm = guest_phone_normalize($phoneRaw);
        if (!$phoneNorm) {
            return ['ok' => false, 'error' => 'Некорректный телефон'];
        }


        $guest = guest_find_by_phone($pdo, $phoneNorm);
        if (!$guest) {
            return [
                'ok' => false,
                'error' => 'Гость ещё не вошёл. Попросите его подтвердить номер по SMS в Wallet или на QR-странице.',
                'need_register' => true,
                'phone' => $phoneNorm,
            ];
        }

        $guestId = (int)$guest['id'];

        if (file_exists(__DIR__ . '/schema_guard.php')) {
            require_once __DIR__ . '/schema_guard.php';
        }

        $tx = null;
        try {
            $tx = guest_loyalty_tx_begin($pdo);

            $stmt = $pdo->prepare("
                INSERT INTO guest_loyalty_accounts (guest_id, restaurant_id, balance)
                VALUES (:g, :r, 0)
                ON DUPLICATE KEY UPDATE guest_id = guest_id
            ");
            $stmt->execute([':g' => $guestId, ':r' => $restaurantId]);

            $stmt = $pdo->prepare("
                SELECT * FROM guest_cards
                WHERE guest_id = :g AND restaurant_id = :r
                LIMIT 1
            ");
            $stmt->execute([':g' => $guestId, ':r' => $restaurantId]);
            $card = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$card) {
                $uid = guest_uuid_v4();
                $uidColInsert = function_exists('guest_cards_uid_db_column') ? guest_cards_uid_db_column() : 'public_uid';
                try {
                    $ins = $pdo->prepare("
                        INSERT INTO guest_cards (guest_id, restaurant_id, {$uidColInsert})
                        VALUES (:g, :r, :u)
                    ");
                    $ins->execute([':g' => $guestId, ':r' => $restaurantId, ':u' => $uid]);
                } catch (Throwable $insertEx) {
                    guest_loyalty_tx_undo($pdo, $tx);
                    $code = $insertEx->getCode();
                    if ($code === '23000' || (is_int($code) && $code === 1062) || strpos($insertEx->getMessage(), 'Duplicate') !== false) {
                        try {
                            $stmt = $pdo->prepare("
                                INSERT INTO guest_loyalty_accounts (guest_id, restaurant_id, balance)
                                VALUES (?, ?, 0)
                                ON DUPLICATE KEY UPDATE guest_id = guest_id
                            ");
                            $stmt->execute([$guestId, $restaurantId]);
                        } catch (Throwable $e2) {
                            // ignore
                        }
                        $stmt = $pdo->prepare("SELECT * FROM guest_cards WHERE guest_id = ? AND restaurant_id = ? LIMIT 1");
                        $stmt->execute([$guestId, $restaurantId]);
                        $card = $stmt->fetch(PDO::FETCH_ASSOC);
                        if ($card) {
                            $balStmt = $pdo->prepare("SELECT balance FROM guest_loyalty_accounts WHERE guest_id = ? AND restaurant_id = ? LIMIT 1");
                            $balStmt->execute([$guestId, $restaurantId]);
                            $balance = (int)($balStmt->fetchColumn() ?? 0);
                            $uidCol = array_key_exists('public_uid', $card) ? 'public_uid' : 'card_uid';
                            $token = guest_card_make_token((string)($card[$uidCol] ?? $card['public_uid'] ?? ''));

                            return [
                                'ok' => true,
                                'guest' => ['id' => $guestId, 'phone' => (string)$phoneNorm, 'name' => $guest['name'] ?? null],
                                'card' => ['id' => (int)$card['id'], 'public_uid' => (string)($card[$uidCol] ?? ''), 'token' => $token],
                                'balance' => $balance,
                            ];
                        }
                    }
                    if (function_exists('error_log')) {
                        error_log('guest_issue_card_for_restaurant insert ' . $insertEx->getMessage());
                    }

                    return ['ok' => false, 'error' => 'Не удалось оформить карту. Попробуйте ещё раз.'];
                }
                $uidColLookup = function_exists('guest_cards_uid_db_column') ? guest_cards_uid_db_column() : 'public_uid';
                $stmt = $pdo->prepare("SELECT * FROM guest_cards WHERE {$uidColLookup} = :u LIMIT 1");
                $stmt->execute([':u' => $uid]);
                $card = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$card) {
                    $stmt = $pdo->prepare("SELECT * FROM guest_cards WHERE guest_id = :g AND restaurant_id = :r LIMIT 1");
                    $stmt->execute([':g' => $guestId, ':r' => $restaurantId]);
                    $card = $stmt->fetch(PDO::FETCH_ASSOC);
                }
            }

            $balStmt = $pdo->prepare("
                SELECT balance FROM guest_loyalty_accounts
                WHERE guest_id = :g AND restaurant_id = :r
                LIMIT 1
            ");
            $balStmt->execute([':g' => $guestId, ':r' => $restaurantId]);
            $balance = (int)($balStmt->fetchColumn() ?? 0);

            guest_loyalty_tx_release($pdo, $tx);

            $uidCol = array_key_exists('public_uid', $card) ? 'public_uid' : 'card_uid';
            $token = guest_card_make_token((string)($card[$uidCol] ?? $card['public_uid'] ?? ''));

            return [
                'ok' => true,
                'guest' => [
                    'id' => $guestId,
                    'phone' => (string)$phoneNorm,
                    'name' => $guest['name'] ?? null,
                ],
                'card' => [
                    'id' => (int)$card['id'],
                    'public_uid' => (string)($card[$uidCol] ?? ''),
                    'token' => $token,
                ],
                'balance' => $balance,
            ];
        } catch (Throwable $e) {
            if ($tx !== null) {
                guest_loyalty_tx_undo($pdo, $tx);
            }
            if (function_exists('error_log')) {
                error_log('guest_issue_card_for_restaurant ' . $e->getMessage());
            }

            return ['ok' => false, 'error' => 'Не удалось оформить карту. Попробуйте ещё раз.'];
        }
    }
}

/**
 * Balance from canonical ledger (guest_loyalty_accounts) for guest_id + restaurant_id.
 */
if (!function_exists('guest_loyalty_balance_by_guest_rest')) {
    function guest_loyalty_balance_by_guest_rest(PDO $pdo, int $restaurantId, int $guestId): int {
        try {
            if (!function_exists('db_table_exists') && file_exists(__DIR__ . '/schema_guard.php')) {
                require_once __DIR__ . '/schema_guard.php';
            }
            if (function_exists('db_table_exists') && !db_table_exists('guest_loyalty_accounts')) {
                return 0;
            }
            $st = $pdo->prepare("SELECT balance FROM guest_loyalty_accounts WHERE guest_id = ? AND restaurant_id = ? LIMIT 1");
            $st->execute([$guestId, $restaurantId]);

            return (int)($st->fetchColumn() ?? 0);
        } catch (Throwable $e) {
            return 0;
        }
    }
}

if (!function_exists('guest_get_card_by_token')) {
    function guest_get_card_by_token(PDO $pdo, string $token): ?array {
        $parsed = guest_card_parse_token($token);
        if (!$parsed) return null;

        $uid = $parsed['public_uid'];
        if (file_exists(__DIR__ . '/schema_guard.php')) {
            require_once __DIR__ . '/schema_guard.php';
        }
        $dbc = function_exists('guest_cards_uid_db_column') ? guest_cards_uid_db_column() : 'public_uid';
        $uidCol = 'gc.' . $dbc;
        $stmt = $pdo->prepare("
            SELECT gc.*, g.phone, g.name,
                   gla.balance
            FROM guest_cards gc
            JOIN guests g ON g.id = gc.guest_id
            LEFT JOIN guest_loyalty_accounts gla
              ON gla.guest_id = gc.guest_id AND gla.restaurant_id = gc.restaurant_id
            WHERE {$uidCol} = :u
            LIMIT 1
        ");
        $stmt->execute([':u' => $uid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

if (!function_exists('guest_get_card_by_phone')) {
    function guest_get_card_by_phone(PDO $pdo, int $restaurantId, string $phoneRaw): ?array {
        $phoneNorm = function_exists('guest_phone_normalize') ? guest_phone_normalize($phoneRaw) : null;
        if (!$phoneNorm) {
            return null;
        }

        $guest = guest_find_by_phone($pdo, $phoneNorm);
        if (!$guest) {
            return null;
        }

        $stmt = $pdo->prepare("
            SELECT gc.*, g.phone, g.name,
                   gla.balance
            FROM guest_cards gc
            JOIN guests g ON g.id = gc.guest_id
            LEFT JOIN guest_loyalty_accounts gla
              ON gla.guest_id = gc.guest_id AND gla.restaurant_id = gc.restaurant_id
            WHERE gc.restaurant_id = :r
              AND gc.guest_id = :g
            LIMIT 1
        ");
        $stmt->execute([
            ':r' => $restaurantId,
            ':g' => (int)$guest['id'],
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }
}

if (!function_exists('guest_get_card_by_guest_rest')) {
    function guest_get_card_by_guest_rest(PDO $pdo, int $restaurantId, int $guestId): ?array {
        if ($restaurantId <= 0 || $guestId <= 0) {
            return null;
        }

        $stmt = $pdo->prepare("
            SELECT gc.*, g.phone, g.name,
                   gla.balance
            FROM guest_cards gc
            JOIN guests g ON g.id = gc.guest_id
            LEFT JOIN guest_loyalty_accounts gla
              ON gla.guest_id = gc.guest_id AND gla.restaurant_id = gc.restaurant_id
            WHERE gc.restaurant_id = :r
              AND gc.guest_id = :g
            LIMIT 1
        ");
        $stmt->execute([
            ':r' => $restaurantId,
            ':g' => $guestId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }
}

if (!function_exists('guest_loyalty_card_by_guest_rest')) {
    function guest_loyalty_card_by_guest_rest(PDO $pdo, int $restaurantId, int $guestId): ?array {
        if ($restaurantId <= 0 || $guestId <= 0) {
            return null;
        }
        try {
            $stmt = $pdo->prepare("
                SELECT *
                FROM guest_cards
                WHERE guest_id = :g
                  AND restaurant_id = :r
                LIMIT 1
            ");
            $stmt->execute([
                ':g' => $guestId,
                ':r' => $restaurantId,
            ]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('guest_loyalty_tx_begin')) {
    /**
     * @return array{own: bool, sp: ?string}
     */
    function guest_loyalty_tx_begin(PDO $pdo): array {
        if (!$pdo->inTransaction()) {
            try {
                $pdo->beginTransaction();

                return ['own' => true, 'sp' => null];
            } catch (PDOException $e) {
                if (stripos($e->getMessage(), 'active transaction') !== false) {
                    $sp = 'gloy_' . bin2hex(random_bytes(5));
                    $pdo->exec('SAVEPOINT ' . $sp);

                    return ['own' => false, 'sp' => $sp];
                }
                throw $e;
            }
        }
        $sp = 'gloy_' . bin2hex(random_bytes(5));
        $pdo->exec('SAVEPOINT ' . $sp);

        return ['own' => false, 'sp' => $sp];
    }
}

if (!function_exists('guest_loyalty_tx_release')) {
    /** Commit if we opened the top-level transaction; otherwise release savepoint. */
    function guest_loyalty_tx_release(PDO $pdo, array $tx): void {
        try {
            if ($tx['own']) {
                if ($pdo->inTransaction()) {
                    $pdo->commit();
                }
            } elseif ($tx['sp'] !== null && $pdo->inTransaction()) {
                $pdo->exec('RELEASE SAVEPOINT ' . $tx['sp']);
            }
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('guest_loyalty_tx_release ' . $e->getMessage());
            }
        }
    }
}

if (!function_exists('guest_loyalty_tx_undo')) {
    /** Roll back only this layer (nested: ROLLBACK TO SAVEPOINT). */
    function guest_loyalty_tx_undo(PDO $pdo, array $tx): void {
        if ($tx['own']) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        } elseif ($tx['sp'] !== null && $pdo->inTransaction()) {
            try {
                $pdo->exec('ROLLBACK TO SAVEPOINT ' . $tx['sp']);
            } catch (Throwable $e) {
                // savepoint may already be gone
            }
        }
    }
}

if (!function_exists('guest_loyalty_add_points')) {
    function guest_loyalty_add_points(PDO $pdo, int $restaurantId, int $guestId, int $points, ?int $staffUserId = null, ?int $orderId = null, ?string $note = null): array {
        if ($points <= 0) return ['ok' => false, 'error' => 'points must be > 0'];
        $restaurantId = (int)$restaurantId;
        $guestId = (int)$guestId;
        $staffUserId = $staffUserId !== null ? (int)$staffUserId : null;
        $orderId = $orderId !== null ? (int)$orderId : null;
        $note = $note !== null ? trim($note) : null;
        if ($note === '') {
            $note = null;
        }
        if ($note === null && $staffUserId !== null && $staffUserId > 0) {
            $note = $orderId !== null && $orderId > 0
                ? ('Ручное начисление по заказу #' . $orderId)
                : 'Ручное начисление staff';
        }

        $tx = null;
        try {
            $tx = guest_loyalty_tx_begin($pdo);

            // Idempotency: if order_id provided, avoid double accrual for same order
            if ($orderId !== null && $orderId > 0) {
                $chk = $pdo->prepare("SELECT id FROM guest_loyalty_tx WHERE guest_id = ? AND restaurant_id = ? AND order_id = ? AND type = 'accrual' LIMIT 1");
                $chk->execute([$guestId, $restaurantId, $orderId]);
                if ($chk->fetchColumn()) {
                    $bal = $pdo->prepare("SELECT balance FROM guest_loyalty_accounts WHERE guest_id = ? AND restaurant_id = ? LIMIT 1");
                    $bal->execute([$guestId, $restaurantId]);
                    $current = (int)($bal->fetchColumn() ?? 0);
                    guest_loyalty_tx_release($pdo, $tx);
                    return ['ok' => true, 'balance' => $current, 'duplicate_order' => true];
                }
            }

            // Lock balance row
            $stmt = $pdo->prepare("
                SELECT balance FROM guest_loyalty_accounts
                WHERE guest_id = :g AND restaurant_id = :r
                FOR UPDATE
            ");
            $stmt->execute([':g' => $guestId, ':r' => $restaurantId]);
            $balance = $stmt->fetchColumn();

            if ($balance === false) {
                $ins = $pdo->prepare("
                    INSERT INTO guest_loyalty_accounts (guest_id, restaurant_id, balance)
                    VALUES (:g, :r, 0)
                ");
                $ins->execute([':g' => $guestId, ':r' => $restaurantId]);
                $balance = 0;
            }

            $balance = (int)$balance;
            $newBalance = $balance + $points;

            $upd = $pdo->prepare("
                UPDATE guest_loyalty_accounts
                SET balance = :b
                WHERE guest_id = :g AND restaurant_id = :r
            ");
            $upd->execute([':b' => $newBalance, ':g' => $guestId, ':r' => $restaurantId]);

            $txIns = $pdo->prepare("
                INSERT INTO guest_loyalty_tx (guest_id, restaurant_id, staff_user_id, order_id, type, points, note)
                VALUES (:g, :r, :s, :o, 'accrual', :p, :n)
            ");
            $txIns->execute([
                ':g' => $guestId,
                ':r' => $restaurantId,
                ':s' => $staffUserId,
                ':o' => $orderId,
                ':p' => $points,
                ':n' => $note,
            ]);

            guest_loyalty_tx_release($pdo, $tx);

            // Usage metrics: count successful loyalty accrual in canonical ledger.
            if (function_exists('increment_usage') && function_exists('is_demo_mode') && !is_demo_mode()) {
                try {
                    increment_usage($restaurantId, 'loyalty_transactions');
                } catch (Throwable $eUsage) {
                    // never break loyalty flow
                }
            }

            return ['ok' => true, 'balance' => $newBalance];
        } catch (Throwable $e) {
            if ($tx !== null) {
                guest_loyalty_tx_undo($pdo, $tx);
            }
            $code = $e->getCode();
            $msg = $e->getMessage();
            $isDup = ($code === '23000' || (is_int($code) && $code === 1062) || strpos($msg, 'Duplicate') !== false);
            if ($isDup && $orderId !== null && $orderId > 0) {
                $bal = $pdo->prepare("SELECT balance FROM guest_loyalty_accounts WHERE guest_id = ? AND restaurant_id = ? LIMIT 1");
                $bal->execute([$guestId, $restaurantId]);
                $current = (int)($bal->fetchColumn() ?? 0);
                return ['ok' => true, 'balance' => $current, 'duplicate_order' => true];
            }
            if (function_exists('error_log')) {
                error_log('guest_loyalty_add_points ' . $msg);
            }
            return ['ok' => false, 'error' => 'Ошибка начисления. Попробуйте ещё раз.'];
        }
    }
}

if (!function_exists('guest_loyalty_spend_points')) {
    function guest_loyalty_spend_points(PDO $pdo, int $restaurantId, int $guestId, int $points, ?int $staffUserId = null, ?int $orderId = null, ?string $note = null): array {
        if ($points <= 0) return ['ok' => false, 'error' => 'points must be > 0'];
        $staffUserId = $staffUserId !== null ? (int)$staffUserId : null;
        $orderId = $orderId !== null ? (int)$orderId : null;
        $note = $note !== null ? trim($note) : null;
        if ($note === '') {
            $note = null;
        }
        if ($note === null && $staffUserId !== null && $staffUserId > 0) {
            $note = $orderId !== null && $orderId > 0
                ? ('Ручное списание по заказу #' . $orderId)
                : 'Ручное списание staff';
        }

        $tx = null;
        try {
            $tx = guest_loyalty_tx_begin($pdo);

            $stmt = $pdo->prepare("
                SELECT balance FROM guest_loyalty_accounts
                WHERE guest_id = :g AND restaurant_id = :r
                FOR UPDATE
            ");
            $stmt->execute([':g' => $guestId, ':r' => $restaurantId]);
            $balance = $stmt->fetchColumn();

            $balance = (int)($balance ?? 0);
            if ($balance < $points) {
                guest_loyalty_tx_undo($pdo, $tx);
                return ['ok' => false, 'error' => 'Недостаточно баллов. Доступно: ' . $balance];
            }

            $newBalance = max(0, $balance - $points);

            $upd = $pdo->prepare("
                UPDATE guest_loyalty_accounts
                SET balance = :b
                WHERE guest_id = :g AND restaurant_id = :r
            ");
            $upd->execute([':b' => $newBalance, ':g' => $guestId, ':r' => $restaurantId]);

            $txIns = $pdo->prepare("
                INSERT INTO guest_loyalty_tx (guest_id, restaurant_id, staff_user_id, order_id, type, points, note)
                VALUES (:g, :r, :s, :o, 'spend', :p, :n)
            ");
            $txIns->execute([
                ':g' => $guestId,
                ':r' => $restaurantId,
                ':s' => $staffUserId,
                ':o' => $orderId,
                ':p' => -abs($points),
                ':n' => $note,
            ]);

            guest_loyalty_tx_release($pdo, $tx);

            // Usage metrics: count successful loyalty spend in canonical ledger.
            if (function_exists('increment_usage') && function_exists('is_demo_mode') && !is_demo_mode()) {
                try {
                    increment_usage($restaurantId, 'loyalty_transactions');
                } catch (Throwable $eUsage) {
                    // never break loyalty flow
                }
            }

            return ['ok' => true, 'balance' => $newBalance];
        } catch (Throwable $e) {
            if ($tx !== null) {
                guest_loyalty_tx_undo($pdo, $tx);
            }
            if (function_exists('error_log')) {
                error_log('guest_loyalty_spend_points ' . $e->getMessage());
            }
            return ['ok' => false, 'error' => 'Ошибка списания. Попробуйте ещё раз.'];
        }
    }
}

/**
 * Recent loyalty transactions for a guest (tenant-scoped).
 * @return array<array{id: int, type: string, points: int, note: ?string, created_at: ?string, order_id?: ?int, staff_user_id?: ?int, actor_name?: ?string}>
 */
if (!function_exists('guest_loyalty_recent_tx')) {
    function guest_loyalty_recent_tx(PDO $pdo, int $restaurantId, int $guestId, int $limit = 10): array {
        $restaurantId = (int)$restaurantId;
        $guestId = (int)$guestId;
        $limit = max(1, min(50, $limit));
        if (!function_exists('db_table_exists') && file_exists(__DIR__ . '/schema_guard.php')) {
            require_once __DIR__ . '/schema_guard.php';
        }
        if (function_exists('db_table_exists') && !db_table_exists('guest_loyalty_tx')) {
            return [];
        }
        try {
            $stmt = $pdo->prepare("
                SELECT
                    t.id,
                    t.type,
                    t.points,
                    t.note,
                    t.created_at,
                    t.order_id,
                    t.staff_user_id,
                    u.name AS actor_name
                FROM guest_loyalty_tx t
                LEFT JOIN users u ON u.id = t.staff_user_id
                WHERE t.guest_id = ? AND t.restaurant_id = ?
                ORDER BY created_at DESC
                LIMIT ?
            ");
            $stmt->execute([$guestId, $restaurantId, $limit]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }
}

/**
 * Basic loyalty analytics for a restaurant (tenant-scoped).
 * @return array{cards_issued: int, total_points_issued: int, total_points_spent: int, active_guests: int, recent_activity: array}
 */
if (!function_exists('guest_loyalty_analytics')) {
    function guest_loyalty_analytics(PDO $pdo, int $restaurantId): array {
        $restaurantId = (int)$restaurantId;
        $out = [
            'cards_issued' => 0,
            'total_points_issued' => 0,
            'total_points_spent' => 0,
            'active_guests' => 0,
            'recent_activity' => [],
        ];
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM guest_cards WHERE restaurant_id = ?");
            $stmt->execute([$restaurantId]);
            $out['cards_issued'] = (int)$stmt->fetchColumn();

            $stmt = $pdo->prepare("SELECT COALESCE(SUM(points), 0) FROM guest_loyalty_tx WHERE restaurant_id = ? AND type = 'accrual' AND points > 0");
            $stmt->execute([$restaurantId]);
            $out['total_points_issued'] = (int)$stmt->fetchColumn();

            $stmt = $pdo->prepare("SELECT COALESCE(SUM(ABS(points)), 0) FROM guest_loyalty_tx WHERE restaurant_id = ? AND type = 'spend'");
            $stmt->execute([$restaurantId]);
            $out['total_points_spent'] = (int)$stmt->fetchColumn();

            $stmt = $pdo->prepare("SELECT COUNT(DISTINCT guest_id) FROM guest_loyalty_accounts WHERE restaurant_id = ? AND balance > 0");
            $stmt->execute([$restaurantId]);
            $out['active_guests'] = (int)$stmt->fetchColumn();

            $stmt = $pdo->prepare("
                SELECT t.id, t.guest_id, t.type, t.points, t.note, t.created_at
                FROM guest_loyalty_tx t
                WHERE t.restaurant_id = ?
                ORDER BY t.created_at DESC
                LIMIT 20
            ");
            $stmt->execute([$restaurantId]);
            $out['recent_activity'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            // leave defaults
        }
        return $out;
    }
}

if (!function_exists('guest_loyalty_manual_activity_feed')) {
    /**
     * Recent manual staff loyalty operations for restaurant management views.
     * @return array<array{
     *   id:int,
     *   guest_id:int,
     *   type:string,
     *   points:int,
     *   note:?string,
     *   created_at:?string,
     *   order_id:?int,
     *   staff_user_id:?int,
     *   actor_name:?string,
     *   guest_phone:?string,
     *   guest_name:?string
     * }>
     */
    function guest_loyalty_manual_activity_feed(PDO $pdo, int $restaurantId, int $limit = 20): array {
        $restaurantId = (int)$restaurantId;
        $limit = max(1, min(100, $limit));
        try {
            $stmt = $pdo->prepare("
                SELECT
                    t.id,
                    t.guest_id,
                    t.type,
                    t.points,
                    t.note,
                    t.created_at,
                    t.order_id,
                    t.staff_user_id,
                    u.name AS actor_name,
                    g.phone AS guest_phone,
                    g.name AS guest_name
                FROM guest_loyalty_tx t
                JOIN guests g ON g.id = t.guest_id
                LEFT JOIN users u ON u.id = t.staff_user_id
                WHERE t.restaurant_id = :rid
                  AND t.staff_user_id IS NOT NULL
                ORDER BY t.created_at DESC
                LIMIT {$limit}
            ");
            $stmt->execute([':rid' => $restaurantId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('guest_loyalty_manual_activity_summary')) {
    /**
     * @return array{window_days:int, accrual_count:int, spend_count:int, accrual_points:int, spend_points:int}
     */
    function guest_loyalty_manual_activity_summary(PDO $pdo, int $restaurantId, int $days = 7): array {
        $restaurantId = (int)$restaurantId;
        $days = max(1, min(90, $days));
        $out = [
            'window_days' => $days,
            'accrual_count' => 0,
            'spend_count' => 0,
            'accrual_points' => 0,
            'spend_points' => 0,
        ];
        try {
            $stmt = $pdo->prepare("
                SELECT
                    SUM(CASE WHEN type = 'accrual' THEN 1 ELSE 0 END) AS accrual_count,
                    SUM(CASE WHEN type = 'spend' THEN 1 ELSE 0 END) AS spend_count,
                    COALESCE(SUM(CASE WHEN type = 'accrual' THEN points ELSE 0 END), 0) AS accrual_points,
                    COALESCE(SUM(CASE WHEN type = 'spend' THEN ABS(points) ELSE 0 END), 0) AS spend_points
                FROM guest_loyalty_tx
                WHERE restaurant_id = :rid
                  AND staff_user_id IS NOT NULL
                  AND created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
            ");
            $stmt->bindValue(':rid', $restaurantId, PDO::PARAM_INT);
            $stmt->bindValue(':days', $days, PDO::PARAM_INT);
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $out['accrual_count'] = (int)($row['accrual_count'] ?? 0);
            $out['spend_count'] = (int)($row['spend_count'] ?? 0);
            $out['accrual_points'] = (int)($row['accrual_points'] ?? 0);
            $out['spend_points'] = (int)($row['spend_points'] ?? 0);
        } catch (Throwable $e) {
            // keep defaults
        }
        return $out;
    }
}

if (!function_exists('guest_loyalty_order_context_map')) {
    /**
     * Compact loyalty context for order cards/lists in staff/manager views.
     *
     * @param array<int,array<string,mixed>> $orders
     * @return array<int,array{
     *   guest_id:int,
     *   guest_card_id:int,
     *   loyalty_phone:string,
     *   has_link:bool,
     *   has_card:bool,
     *   balance:int,
     *   manual_tx_count:int,
     *   manual_accrual_count:int,
     *   manual_spend_count:int
     * }>
     */
    function guest_loyalty_order_context_map(PDO $pdo, int $restaurantId, array $orders): array {
        $restaurantId = (int)$restaurantId;
        if ($restaurantId <= 0 || $orders === []) {
            return [];
        }

        $hasGuestIdCol = function_exists('db_column_exists') ? db_column_exists('orders', 'guest_id') : false;
        $hasGuestCardIdCol = function_exists('db_column_exists') ? db_column_exists('orders', 'guest_card_id') : false;
        $hasLoyaltyPhoneCol = function_exists('db_column_exists') ? db_column_exists('orders', 'loyalty_phone') : false;

        $orderIds = [];
        $guestIds = [];
        foreach ($orders as $order) {
            $orderId = (int)($order['id'] ?? 0);
            if ($orderId > 0) {
                $orderIds[] = $orderId;
            }
            $guestId = $hasGuestIdCol ? (int)($order['guest_id'] ?? 0) : 0;
            if ($guestId > 0) {
                $guestIds[] = $guestId;
            }
        }

        $orderIds = array_values(array_unique($orderIds));
        $guestIds = array_values(array_unique($guestIds));

        $balanceByGuest = [];
        if (
            $guestIds !== []
            && function_exists('db_table_exists')
            && db_table_exists('guest_loyalty_accounts')
        ) {
            $phGuest = implode(',', array_fill(0, count($guestIds), '?'));
            $stmt = $pdo->prepare("
                SELECT guest_id, balance
                FROM guest_loyalty_accounts
                WHERE restaurant_id = ?
                  AND guest_id IN ($phGuest)
            ");
            $stmt->execute(array_merge([$restaurantId], $guestIds));
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $balanceByGuest[(int)($row['guest_id'] ?? 0)] = (int)($row['balance'] ?? 0);
            }
        }

        $cardGuestIds = [];
        if (
            $guestIds !== []
            && function_exists('db_table_exists')
            && db_table_exists('guest_cards')
        ) {
            $phGuest = implode(',', array_fill(0, count($guestIds), '?'));
            $stmt = $pdo->prepare("
                SELECT DISTINCT guest_id
                FROM guest_cards
                WHERE restaurant_id = ?
                  AND guest_id IN ($phGuest)
            ");
            $stmt->execute(array_merge([$restaurantId], $guestIds));
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $cardGuestIds[(int)($row['guest_id'] ?? 0)] = true;
            }
        }

        $manualTxByOrder = [];
        if (
            $orderIds !== []
            && function_exists('db_table_exists')
            && db_table_exists('guest_loyalty_tx')
        ) {
            $phOrder = implode(',', array_fill(0, count($orderIds), '?'));
            $stmt = $pdo->prepare("
                SELECT
                    order_id,
                    COUNT(*) AS tx_count,
                    SUM(CASE WHEN type = 'accrual' THEN 1 ELSE 0 END) AS accrual_count,
                    SUM(CASE WHEN type = 'spend' THEN 1 ELSE 0 END) AS spend_count
                FROM guest_loyalty_tx
                WHERE restaurant_id = ?
                  AND staff_user_id IS NOT NULL
                  AND order_id IN ($phOrder)
                GROUP BY order_id
            ");
            $stmt->execute(array_merge([$restaurantId], $orderIds));
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $manualTxByOrder[(int)($row['order_id'] ?? 0)] = [
                    'count' => (int)($row['tx_count'] ?? 0),
                    'accrual_count' => (int)($row['accrual_count'] ?? 0),
                    'spend_count' => (int)($row['spend_count'] ?? 0),
                ];
            }
        }

        $out = [];
        foreach ($orders as $order) {
            $orderId = (int)($order['id'] ?? 0);
            if ($orderId <= 0) {
                continue;
            }

            $guestId = $hasGuestIdCol ? (int)($order['guest_id'] ?? 0) : 0;
            $guestCardId = $hasGuestCardIdCol ? (int)($order['guest_card_id'] ?? 0) : 0;
            $loyaltyPhone = $hasLoyaltyPhoneCol ? trim((string)($order['loyalty_phone'] ?? '')) : '';
            $hasCard = $guestCardId > 0 || !empty($cardGuestIds[$guestId]);
            $manualTx = $manualTxByOrder[$orderId] ?? ['count' => 0, 'accrual_count' => 0, 'spend_count' => 0];

            $out[$orderId] = [
                'guest_id' => $guestId,
                'guest_card_id' => $guestCardId,
                'loyalty_phone' => $loyaltyPhone,
                'has_link' => $guestId > 0 || $guestCardId > 0 || $loyaltyPhone !== '',
                'has_card' => $hasCard,
                'balance' => $guestId > 0 ? (int)($balanceByGuest[$guestId] ?? 0) : 0,
                'manual_tx_count' => (int)$manualTx['count'],
                'manual_accrual_count' => (int)$manualTx['accrual_count'],
                'manual_spend_count' => (int)$manualTx['spend_count'],
            ];
        }

        return $out;
    }
}
