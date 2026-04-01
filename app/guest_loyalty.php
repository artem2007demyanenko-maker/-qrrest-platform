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

if (!function_exists('guest_find_by_phone')) {
    function guest_find_by_phone(PDO $pdo, string $phoneNorm): ?array {
        $stmt = $pdo->prepare("SELECT * FROM guests WHERE phone = :p LIMIT 1");
        $stmt->execute([':p' => $phoneNorm]);
        $g = $stmt->fetch(PDO::FETCH_ASSOC);
        return $g ?: null;
    }
}

if (!function_exists('guest_create_by_phone_with_pin')) {
    function guest_create_by_phone_with_pin(PDO $pdo, string $phoneNorm, ?string $name = null): array {
        $pin = guest_generate_pin();
        $hash = password_hash($pin, PASSWORD_DEFAULT);

        $name = $name !== null ? trim($name) : null;
        if ($name === '') $name = null;

        $stmt = $pdo->prepare("INSERT INTO guests (phone, name, pin_hash) VALUES (:p, :n, :h)");
        $stmt->execute([':p' => $phoneNorm, ':n' => $name, ':h' => $hash]);

        $id = (int)$pdo->lastInsertId();
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
                'error' => 'Гость ещё не зарегистрирован. Попросите его зарегистрироваться в Wallet (вход/регистрация по телефону).',
                'need_register' => true,
                'phone' => $phoneNorm,
            ];
        }

        $guestId = (int)$guest['id'];

        try {
            $pdo->beginTransaction();

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
                try {
                    $ins = $pdo->prepare("
                        INSERT INTO guest_cards (guest_id, restaurant_id, public_uid)
                        VALUES (:g, :r, :u)
                    ");
                    $ins->execute([':g' => $guestId, ':r' => $restaurantId, ':u' => $uid]);
                } catch (Throwable $insertEx) {
                    // Duplicate (guest_id, restaurant_id): another request created the card; fetch it
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    $code = $insertEx->getCode();
                    if ($code === '23000' || (is_int($code) && $code === 1062) || strpos($insertEx->getMessage(), 'Duplicate') !== false) {
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
                $stmt = $pdo->prepare("SELECT * FROM guest_cards WHERE public_uid = :u LIMIT 1");
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

            $pdo->commit();

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
            if ($pdo->inTransaction()) $pdo->rollBack();
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
        $st = $pdo->prepare("SELECT balance FROM guest_loyalty_accounts WHERE guest_id = ? AND restaurant_id = ? LIMIT 1");
        $st->execute([$guestId, $restaurantId]);
        return (int)($st->fetchColumn() ?? 0);
    }
}

if (!function_exists('guest_get_card_by_token')) {
    function guest_get_card_by_token(PDO $pdo, string $token): ?array {
        $parsed = guest_card_parse_token($token);
        if (!$parsed) return null;

        $uid = $parsed['public_uid'];
        $stmt = $pdo->prepare("
            SELECT gc.*, g.phone, g.name,
                   gla.balance
            FROM guest_cards gc
            JOIN guests g ON g.id = gc.guest_id
            LEFT JOIN guest_loyalty_accounts gla
              ON gla.guest_id = gc.guest_id AND gla.restaurant_id = gc.restaurant_id
            WHERE gc.public_uid = :u
            LIMIT 1
        ");
        $stmt->execute([':u' => $uid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

if (!function_exists('guest_loyalty_add_points')) {
    function guest_loyalty_add_points(PDO $pdo, int $restaurantId, int $guestId, int $points, ?int $staffUserId = null, ?int $orderId = null, ?string $note = null): array {
        if ($points <= 0) return ['ok' => false, 'error' => 'points must be > 0'];
        $restaurantId = (int)$restaurantId;
        $guestId = (int)$guestId;

        try {
            $pdo->beginTransaction();

            // Idempotency: if order_id provided, avoid double accrual for same order
            if ($orderId !== null && $orderId > 0) {
                $chk = $pdo->prepare("SELECT id FROM guest_loyalty_tx WHERE guest_id = ? AND restaurant_id = ? AND order_id = ? AND type = 'accrual' LIMIT 1");
                $chk->execute([$guestId, $restaurantId, $orderId]);
                if ($chk->fetchColumn()) {
                    $bal = $pdo->prepare("SELECT balance FROM guest_loyalty_accounts WHERE guest_id = ? AND restaurant_id = ? LIMIT 1");
                    $bal->execute([$guestId, $restaurantId]);
                    $current = (int)($bal->fetchColumn() ?? 0);
                    $pdo->commit();
                    return ['ok' => true, 'balance' => $current];
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

            $tx = $pdo->prepare("
                INSERT INTO guest_loyalty_tx (guest_id, restaurant_id, staff_user_id, order_id, type, points, note)
                VALUES (:g, :r, :s, :o, 'accrual', :p, :n)
            ");
            $tx->execute([
                ':g' => $guestId,
                ':r' => $restaurantId,
                ':s' => $staffUserId,
                ':o' => $orderId,
                ':p' => $points,
                ':n' => $note,
            ]);

            $pdo->commit();

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
            if ($pdo->inTransaction()) $pdo->rollBack();
            $code = $e->getCode();
            $msg = $e->getMessage();
            $isDup = ($code === '23000' || (is_int($code) && $code === 1062) || strpos($msg, 'Duplicate') !== false);
            if ($isDup && $orderId !== null && $orderId > 0) {
                $bal = $pdo->prepare("SELECT balance FROM guest_loyalty_accounts WHERE guest_id = ? AND restaurant_id = ? LIMIT 1");
                $bal->execute([$guestId, $restaurantId]);
                $current = (int)($bal->fetchColumn() ?? 0);
                return ['ok' => true, 'balance' => $current];
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

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                SELECT balance FROM guest_loyalty_accounts
                WHERE guest_id = :g AND restaurant_id = :r
                FOR UPDATE
            ");
            $stmt->execute([':g' => $guestId, ':r' => $restaurantId]);
            $balance = $stmt->fetchColumn();

            $balance = (int)($balance ?? 0);
            if ($balance < $points) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'Недостаточно баллов. Доступно: ' . $balance];
            }

            $newBalance = max(0, $balance - $points);

            $upd = $pdo->prepare("
                UPDATE guest_loyalty_accounts
                SET balance = :b
                WHERE guest_id = :g AND restaurant_id = :r
            ");
            $upd->execute([':b' => $newBalance, ':g' => $guestId, ':r' => $restaurantId]);

            $tx = $pdo->prepare("
                INSERT INTO guest_loyalty_tx (guest_id, restaurant_id, staff_user_id, order_id, type, points, note)
                VALUES (:g, :r, :s, :o, 'spend', :p, :n)
            ");
            $tx->execute([
                ':g' => $guestId,
                ':r' => $restaurantId,
                ':s' => $staffUserId,
                ':o' => $orderId,
                ':p' => -abs($points),
                ':n' => $note,
            ]);

            $pdo->commit();

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
            if ($pdo->inTransaction()) $pdo->rollBack();
            if (function_exists('error_log')) {
                error_log('guest_loyalty_spend_points ' . $e->getMessage());
            }
            return ['ok' => false, 'error' => 'Ошибка списания. Попробуйте ещё раз.'];
        }
    }
}

/**
 * Recent loyalty transactions for a guest (tenant-scoped).
 * @return array<array{id: int, type: string, points: int, note: ?string, created_at: ?string}>
 */
if (!function_exists('guest_loyalty_recent_tx')) {
    function guest_loyalty_recent_tx(PDO $pdo, int $restaurantId, int $guestId, int $limit = 10): array {
        $restaurantId = (int)$restaurantId;
        $guestId = (int)$guestId;
        $limit = max(1, min(50, $limit));
        try {
            $stmt = $pdo->prepare("
                SELECT id, type, points, note, created_at
                FROM guest_loyalty_tx
                WHERE guest_id = ? AND restaurant_id = ?
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
