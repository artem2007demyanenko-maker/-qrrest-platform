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

        $_SESSION[guest_session_key()] = (int)$guest['id'];
        return true;
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

        $hash = password_hash($pin, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO guests (phone, name, pin_hash) VALUES (:p, :n, :h)");
        $stmt->execute([':p' => $phone, ':n' => $name, ':h' => $hash]);
        $id = (int)$pdo->lastInsertId();

        $_SESSION[guest_session_key()] = $id;
        return $id;
    }
}

if (!function_exists('guest_logout')) {
    function guest_logout(): void {
        unset($_SESSION[guest_session_key()]);
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
        $st = $pdo->prepare("SELECT balance FROM guest_loyalty_accounts WHERE guest_id = ? AND restaurant_id = ? LIMIT 1");
        $st->execute([$gid, $restaurantId]);
        $balance = (int)($st->fetchColumn() ?? 0);
        $qrToken = '';
        if (function_exists('guest_card_make_token')) {
            $st = $pdo->prepare("SELECT public_uid, card_uid FROM guest_cards WHERE guest_id = ? AND restaurant_id = ? LIMIT 1");
            $st->execute([$gid, $restaurantId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $uid = !empty($row['public_uid']) ? $row['public_uid'] : ($row['card_uid'] ?? '');
                if ($uid !== '') {
                    $qrToken = guest_card_make_token((string)$uid);
                }
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
