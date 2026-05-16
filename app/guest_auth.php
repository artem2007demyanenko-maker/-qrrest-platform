<?php

if (!function_exists('guest_e')) {
    function guest_e($v): string {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('guest_phone_normalize')) {
    function guest_phone_normalize(string $raw): ?string {
        $raw = trim($raw);
        if ($raw === '') return null;

        // если у тебя уже есть loyalty_normalize_phone — используем её
        if (function_exists('loyalty_normalize_phone')) {
            $n = loyalty_normalize_phone($raw);
            return $n ?: null;
        }

        // запасной нормалайзер: оставляем цифры
        $digits = preg_replace('~\D+~', '', $raw);
        if (!$digits) return null;

        // РФ: 11 цифр, 7xxxxxxxxxx / 8xxxxxxxxxx
        if (strlen($digits) === 11) {
            if ($digits[0] === '8') $digits[0] = '7';
            if ($digits[0] !== '7') return null;
            return '+' . $digits;
        }

        // если 10 цифр — считаем РФ без 7
        if (strlen($digits) === 10) {
            return '+7' . $digits;
        }

        return null;
    }
}

if (!function_exists('guest_pin_is_valid')) {
    function guest_pin_is_valid(string $pin): bool {
        $pin = trim($pin);
        return (bool)preg_match('~^\d{4,8}$~', $pin);
    }
}

if (!function_exists('guest_generate_pin')) {
    function guest_generate_pin(): string {
        return (string)random_int(100000, 999999); // 6 цифр
    }
}

if (!function_exists('guest_session_key')) {
    function guest_session_key(): string { return 'guest_id'; }
}

if (!function_exists('guest_auth_safe_redirect_path')) {
    function guest_auth_safe_redirect_path(string $raw, string $default = '/guest/wallet.php'): string {
        $raw = trim($raw);
        if ($raw === '') {
            return $default;
        }

        $parts = @parse_url($raw);
        if (!is_array($parts)) {
            return $default;
        }
        if (!empty($parts['scheme']) || !empty($parts['host'])) {
            return $default;
        }

        $path = (string)($parts['path'] ?? '');
        if ($path === '' || $path[0] !== '/' || str_starts_with($path, '//')) {
            return $default;
        }

        $query = isset($parts['query']) && $parts['query'] !== '' ? ('?' . $parts['query']) : '';
        return $path . $query;
    }
}

if (!function_exists('guest_user')) {
    function guest_user(PDO $pdo): ?array {
        $gid = $_SESSION[guest_session_key()] ?? null;
        $gid = (int)$gid;
        if ($gid <= 0) return null;

        $stmt = $pdo->prepare("SELECT id, phone, name, created_at FROM guests WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $gid]);
        $g = $stmt->fetch(PDO::FETCH_ASSOC);
        return $g ?: null;
    }
}

if (!function_exists('guest_login')) {
    function guest_login(PDO $pdo, string $phoneRaw, string $pin): bool {
        $phone = guest_phone_normalize($phoneRaw);
        if (!$phone || !guest_pin_is_valid($pin)) return false;

        $stmt = $pdo->prepare("SELECT * FROM guests WHERE phone = :p LIMIT 1");
        $stmt->execute([':p' => $phone]);
        $guest = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$guest) return false;

        $hash = $guest['pin_hash'] ?? '';
        if (!$hash || !password_verify($pin, $hash)) return false;

        guest_session_login((int)$guest['id']);
        return true;
    }
}

if (!function_exists('guest_guests_has_pin_hash')) {
    function guest_guests_has_pin_hash(): bool {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        if (file_exists(__DIR__ . '/schema_guard.php')) {
            require_once __DIR__ . '/schema_guard.php';
        }
        $cached = function_exists('db_column_exists') && db_column_exists('guests', 'pin_hash');
        return $cached;
    }
}

if (!function_exists('guest_insert_new')) {
    /**
     * Create a guests row. Matches current production schema: password_hash is always NOT NULL (no default).
     *
     * - password_hash: always inserted — use $passwordHash when non-empty, else a random bcrypt (OTP / placeholder).
     * - pin_hash: only when $pinHash is non-empty (column omitted otherwise).
     *
     * @param ?string $passwordHash Optional bcrypt for password_hash; if null/empty, a safe random hash is used.
     * @return int New guest id, or 0 on failure (e.g. duplicate phone).
     */
    function guest_insert_new(PDO $pdo, string $phone, ?string $name, ?string $pinHash, ?string $passwordHash = null): int {
        $cols = ['phone', 'name', 'password_hash'];
        $ph = [':p', ':n', ':pwd'];
        $params = [
            ':p' => $phone,
            ':n' => $name,
            ':pwd' => ($passwordHash !== null && $passwordHash !== '')
                ? $passwordHash
                : password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
        ];

        if ($pinHash !== null && $pinHash !== '' && guest_guests_has_pin_hash()) {
            $cols[] = 'pin_hash';
            $ph[] = ':pin';
            $params[':pin'] = $pinHash;
        }

        try {
            $sql = 'INSERT INTO guests (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $ph) . ')';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return (int)$pdo->lastInsertId();
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('guest_insert_new ' . $e->getMessage());
            }
            return 0;
        }
    }
}

if (!function_exists('guest_register')) {
    function guest_register(PDO $pdo, string $phoneRaw, string $pin, ?string $name = null): ?int {
        $phone = guest_phone_normalize($phoneRaw);
        if (!$phone || !guest_pin_is_valid($pin)) return null;

        $name = $name !== null ? trim($name) : null;
        if ($name === '') $name = null;

        // уже есть?
        $stmt = $pdo->prepare("SELECT id FROM guests WHERE phone = :p LIMIT 1");
        $stmt->execute([':p' => $phone]);
        $exists = $stmt->fetchColumn();
        if ($exists) return null;

        if (!guest_guests_has_pin_hash()) {
            return null;
        }

        $hash = password_hash($pin, PASSWORD_DEFAULT);
        $id = guest_insert_new($pdo, $phone, $name, $hash, $hash);
        if ($id <= 0) {
            return null;
        }

        guest_session_login($id);
        return $id;
    }
}

if (!function_exists('guest_logout')) {
    function guest_logout(): void {
        unset($_SESSION[guest_session_key()]);
        unset($_SESSION['guest_account_id']);
        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_regenerate_id(true);
        }
    }
}

if (!function_exists('require_guest_login')) {
    function require_guest_login(): void {
        // тут без PDO: проверка по session
        $gid = (int)($_SESSION[guest_session_key()] ?? 0);
        if ($gid > 0) return;

        $redirect = $_SERVER['REQUEST_URI'] ?? '/guest/wallet.php';
        header('Location: /guest/login.php?redirect=' . urlencode($redirect));
        exit;
    }
}

/**
 * Require guest login in restaurant context; return guest with loyalty_balance and qr_token from canonical ledger.
 * Used by guest cabinet. Redirects to login if not logged in.
 */
if (!function_exists('guest_current')) {
    /** Current session guest row from guests table, or null. */
    function guest_current(PDO $pdo): ?array {
        return guest_user($pdo);
    }
}

if (!function_exists('guest_require')) {
    /**
     * JSON API guard: returns guest row or responds with 401 JSON and exits.
     * @return array<string, mixed>
     */
    function guest_require(PDO $pdo): array {
        $g = guest_current($pdo);
        if (!$g) {
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(401);
            }
            echo json_encode(['success' => false, 'error' => 'unauthorized'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        return $g;
    }
}

if (!function_exists('guest_session_login')) {
    /** Set session after OTP / trusted auth (does not replace PIN-based guest_login). */
    function guest_session_login(int $guestId): void {
        $guestId = (int)$guestId;
        if ($guestId > 0) {
            if (session_status() === PHP_SESSION_ACTIVE) {
                @session_regenerate_id(true);
            }
            $_SESSION[guest_session_key()] = $guestId;
            unset($_SESSION['guest_account_id']);
        }
    }
}

if (!function_exists('guest_require_login')) {
    function guest_require_login(PDO $pdo, int $restaurantId): array {
        $gid = (int)($_SESSION[guest_session_key()] ?? 0);
        if ($gid <= 0) {
            header('Location: /guest/login.php?redirect=' . urlencode($_SERVER['REQUEST_URI'] ?? '/guest/cabinet.php'));
            exit;
        }
        $stmt = $pdo->prepare("SELECT id, phone, name FROM guests WHERE id = ? LIMIT 1");
        $stmt->execute([$gid]);
        $guest = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$guest) {
            unset($_SESSION[guest_session_key()]);
            header('Location: /guest/login.php');
            exit;
        }
        $balance = 0;
        try {
            if (!function_exists('db_table_exists') && file_exists(__DIR__ . '/schema_guard.php')) {
                require_once __DIR__ . '/schema_guard.php';
            }
            if (!function_exists('db_table_exists') || db_table_exists('guest_loyalty_accounts')) {
                $st = $pdo->prepare("SELECT balance FROM guest_loyalty_accounts WHERE guest_id = ? AND restaurant_id = ? LIMIT 1");
                $st->execute([$gid, $restaurantId]);
                $balance = (int)($st->fetchColumn() ?? 0);
            }
        } catch (Throwable $e) {
            $balance = 0;
        }
        $qrToken = '';
        if (function_exists('guest_card_make_token')) {
            if (file_exists(__DIR__ . '/schema_guard.php')) {
                require_once __DIR__ . '/schema_guard.php';
            }
            $dbc = function_exists('guest_cards_uid_db_column') ? guest_cards_uid_db_column() : 'public_uid';
            $cardSql = $dbc === 'public_uid'
                ? 'SELECT public_uid, card_uid FROM guest_cards WHERE guest_id = ? AND restaurant_id = ? LIMIT 1'
                : 'SELECT card_uid AS public_uid, card_uid FROM guest_cards WHERE guest_id = ? AND restaurant_id = ? LIMIT 1';
            try {
                if (!function_exists('db_table_exists') || db_table_exists('guest_cards')) {
                    $st = $pdo->prepare($cardSql);
                    $st->execute([$gid, $restaurantId]);
                    $row = $st->fetch(PDO::FETCH_ASSOC);
                    if ($row) {
                        $uid = !empty($row['public_uid']) ? $row['public_uid'] : ($row['card_uid'] ?? '');
                        if ($uid !== '') {
                            $qrToken = guest_card_make_token((string)$uid);
                        }
                    }
                }
            } catch (Throwable $e) {
                $qrToken = '';
            }
        }
        return [
            'id' => (int)$guest['id'],
            'phone' => (string)$guest['phone'],
            'name' => (string)($guest['name'] ?? ''),
            'loyalty_balance' => $balance,
            'qr_token' => $qrToken,
        ];
    }
}
