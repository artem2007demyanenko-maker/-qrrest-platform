<?php

if (file_exists(__DIR__ . '/schema_guard.php')) {
    require_once __DIR__ . '/schema_guard.php';
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function gw_e($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function gw_normalize_phone(string $s): ?string {
    $s = trim($s);
    if ($s === '') return null;

    $s = preg_replace('/[^\d+]/u', '', $s);

    if (preg_match('/^8\d{10}$/', $s)) $s = '+7' . substr($s, 1);
    if (preg_match('/^7\d{10}$/', $s)) $s = '+' . $s;
    if (!preg_match('/^\+7\d{10}$/', $s)) return null;

    return $s;
}

function gw_session_key(): string {
    return 'guest_account_id';
}

function gw_guest_logout(): void {
    unset($_SESSION[gw_session_key()]);
}

function gw_guest_current(PDO $pdo): ?array {
    if (!gw_guest_accounts_table_ready()) {
        return null;
    }
    $k = gw_session_key();
    if (empty($_SESSION[$k])) return null;

    $gid = (int)$_SESSION[$k];
    if ($gid <= 0) return null;

    $stmt = $pdo->prepare("SELECT * FROM guest_accounts WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $gid]);
    $g = $stmt->fetch(PDO::FETCH_ASSOC);

    return $g ?: null;
}

function gw_guest_set_session(int $guestId): void {
    $_SESSION[gw_session_key()] = $guestId;
}

function gw_guest_accounts_table_ready(): bool {
    return function_exists('db_table_exists') ? db_table_exists('guest_accounts') : true;
}

function gw_guest_accounts_has_pin_hash(): bool {
    return function_exists('db_column_exists') ? db_column_exists('guest_accounts', 'pin_hash') : true;
}

function gw_guest_accounts_has_password_hash(): bool {
    return function_exists('db_column_exists') ? db_column_exists('guest_accounts', 'password_hash') : false;
}

function gw_guest_register(PDO $pdo, string $phoneRaw, string $name, string $pin): array {
    $errors = [];

    $phone = gw_normalize_phone($phoneRaw);
    if (!$phone) $errors[] = 'Введите корректный номер телефона (+7...).';

    $name = trim($name);
    if ($name === '') $errors[] = 'Введите имя.';
    if (mb_strlen($name) > 120) $errors[] = 'Имя слишком длинное (макс 120).';

    $pin = trim($pin);
    if (!preg_match('/^\d{4,6}$/', $pin)) $errors[] = 'PIN должен быть 4–6 цифр.';

    if ($errors) return ['ok' => false, 'errors' => $errors];

    if (!gw_guest_accounts_table_ready()) {
        return ['ok' => false, 'errors' => ['Вход через Wallet временно недоступен для этой схемы базы.']];
    }

    $stmt = $pdo->prepare("SELECT id FROM guest_accounts WHERE phone = :p LIMIT 1");
    $stmt->execute([':p' => $phone]);
    if ($stmt->fetch()) {
        return ['ok' => false, 'errors' => ['Этот телефон уже зарегистрирован. Войдите.']];
    }

    $pinHash = password_hash($pin, PASSWORD_BCRYPT);
    $cols = ['phone', 'name', 'created_at', 'updated_at'];
    $vals = [':p', ':n', 'NOW()', 'NOW()'];
    $params = [':p' => $phone, ':n' => $name];
    if (gw_guest_accounts_has_pin_hash()) {
        $cols[] = 'pin_hash';
        $vals[] = ':pin_hash';
        $params[':pin_hash'] = $pinHash;
    }
    if (gw_guest_accounts_has_password_hash()) {
        $cols[] = 'password_hash';
        $vals[] = ':password_hash';
        $params[':password_hash'] = $pinHash;
    }
    if (count($cols) <= 4) {
        return ['ok' => false, 'errors' => ['Wallet-схема устарела: нет поля для хранения PIN.']];
    }
    $stmt = $pdo->prepare("
        INSERT INTO guest_accounts (" . implode(', ', $cols) . ")
        VALUES (" . implode(', ', $vals) . ")
    ");
    $stmt->execute($params);

    return ['ok' => true, 'guest_id' => (int)$pdo->lastInsertId()];
}

function gw_guest_login(PDO $pdo, string $phoneRaw, string $pin): array {
    $errors = [];

    $phone = gw_normalize_phone($phoneRaw);
    if (!$phone) $errors[] = 'Введите корректный номер телефона (+7...).';

    $pin = trim($pin);
    if (!preg_match('/^\d{4,6}$/', $pin)) $errors[] = 'PIN должен быть 4–6 цифр.';

    if ($errors) return ['ok' => false, 'errors' => $errors];

    if (!gw_guest_accounts_table_ready()) {
        return ['ok' => false, 'errors' => ['Вход через Wallet временно недоступен для этой схемы базы.']];
    }

    $stmt = $pdo->prepare("SELECT * FROM guest_accounts WHERE phone = :p LIMIT 1");
    $stmt->execute([':p' => $phone]);
    $g = $stmt->fetch(PDO::FETCH_ASSOC);
    $authHash = '';
    if ($g) {
        if (gw_guest_accounts_has_pin_hash()) {
            $authHash = (string)($g['pin_hash'] ?? '');
        }
        if ($authHash === '' && gw_guest_accounts_has_password_hash()) {
            $authHash = (string)($g['password_hash'] ?? '');
        }
    }
    if (!$g || $authHash === '' || !password_verify($pin, $authHash)) {
        return ['ok' => false, 'errors' => ['Неверный телефон или PIN.']];
    }

    gw_guest_set_session((int)$g['id']);
    return ['ok' => true, 'guest_id' => (int)$g['id']];
}

function gw_guest_require_login(PDO $pdo): array {
    $g = gw_guest_current($pdo);
    if ($g) return $g;

    $redirect = $_SERVER['REQUEST_URI'] ?? '/guest/wallet.php';
    header("Location: /guest/login.php?redirect=" . urlencode($redirect));
    exit;
}

function gw_get_or_create_card(PDO $pdo, int $guestId, int $restaurantId): array {
    $stmt = $pdo->prepare("
        SELECT gc.*, r.name AS restaurant_name, r.subdomain
        FROM guest_cards gc
        JOIN restaurants r ON r.id = gc.restaurant_id
        WHERE gc.guest_id = :gid AND gc.restaurant_id = :rid
        LIMIT 1
    ");
    $stmt->execute([':gid' => $guestId, ':rid' => $restaurantId]);
    $card = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($card) return $card;

    $token = bin2hex(random_bytes(24));

    $ins = $pdo->prepare("
        INSERT INTO guest_cards (guest_id, restaurant_id, card_token, balance, created_at, updated_at)
        VALUES (:gid, :rid, :t, 0, NOW(), NOW())
    ");
    $ins->execute([':gid' => $guestId, ':rid' => $restaurantId, ':t' => $token]);

    $stmt->execute([':gid' => $guestId, ':rid' => $restaurantId]);
    $card = $stmt->fetch(PDO::FETCH_ASSOC);

    return $card ?: [
        'id' => (int)$pdo->lastInsertId(),
        'guest_id' => $guestId,
        'restaurant_id' => $restaurantId,
        'card_token' => $token,
        'balance' => 0,
    ];
}

function gw_list_cards(PDO $pdo, int $guestId): array {
    $stmt = $pdo->prepare("
        SELECT gc.*, r.name AS restaurant_name, r.subdomain
        FROM guest_cards gc
        JOIN restaurants r ON r.id = gc.restaurant_id
        WHERE gc.guest_id = :gid
        ORDER BY gc.updated_at DESC, gc.id DESC
    ");
    $stmt->execute([':gid' => $guestId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
