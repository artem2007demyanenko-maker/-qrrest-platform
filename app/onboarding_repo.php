<?php

if (!function_exists('qr_public_sql_exclude_delivery') && file_exists(__DIR__ . '/qr_public_menu.php')) {
    require_once __DIR__ . '/qr_public_menu.php';
}
/**
 * Restaurant self-registration repo: validate subdomain, create owner+restaurant+link+tables, bootstrap subscription.
 * Single source of truth for onboarding logic. onboarding.php is a compatibility wrapper.
 */

require_once __DIR__ . '/db.php';
if (file_exists(__DIR__ . '/schema_guard.php')) {
    require_once __DIR__ . '/schema_guard.php';
}

const ONBOARDING_REPO_SUBDOMAIN_BLACKLIST = [
    'admin', 'www', 'api', 'owner', 'staff', 'project-admin', 'login', 'demo', 'test', 'app', 'support',
];

/**
 * @return array{ok:bool, error:?string, normalized:string}
 */
function onboarding_validate_subdomain(string $value): array
{
    $normalized = trim(strtolower($value));
    if ($normalized === '') {
        return ['ok' => false, 'error' => 'Поддомен не указан.', 'normalized' => ''];
    }
    if (strlen($normalized) < 3 || strlen($normalized) > 32) {
        return ['ok' => false, 'error' => 'Поддомен должен быть от 3 до 32 символов.', 'normalized' => $normalized];
    }
    if (!preg_match('/^[a-z0-9\-]+$/', $normalized)) {
        return ['ok' => false, 'error' => 'Только латинские буквы, цифры и дефис.', 'normalized' => $normalized];
    }
    if ($normalized[0] === '-' || substr($normalized, -1) === '-') {
        return ['ok' => false, 'error' => 'Поддомен не должен начинаться или заканчиваться дефисом.', 'normalized' => $normalized];
    }
    if (in_array($normalized, ONBOARDING_REPO_SUBDOMAIN_BLACKLIST, true)) {
        return ['ok' => false, 'error' => 'Этот поддомен занят системой.', 'normalized' => $normalized];
    }
    if (onboarding_is_subdomain_taken($normalized)) {
        return ['ok' => false, 'error' => 'Этот поддомен уже используется.', 'normalized' => $normalized];
    }
    return ['ok' => true, 'error' => null, 'normalized' => $normalized];
}

/**
 * Check if subdomain is already taken. Used by onboarding_validate_subdomain.
 */
function onboarding_is_subdomain_taken(string $value): bool
{
    $value = trim(strtolower($value));
    if ($value === '') {
        return true;
    }
    try {
        $pdo = db();
        $deletedSql = function_exists('schema_guard_restaurants_deleted_sql') ? schema_guard_restaurants_deleted_sql('r') : '';
        $stmt = $pdo->prepare("SELECT 1 FROM restaurants r WHERE r.subdomain = ?{$deletedSql} LIMIT 1");
        $stmt->execute([$value]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        error_log('ONBOARDING_IS_SUBDOMAIN_TAKEN ' . $e->getMessage());
        return true;
    }
}

/**
 * Create owner user. Returns user_id.
 */
function onboarding_create_owner_user(array $data): int
{
    $name     = trim((string)($data['owner_name'] ?? ''));
    $email    = trim((string)($data['email'] ?? ''));
    $password = (string)($data['password'] ?? '');
    if ($name === '' || $email === '' || $password === '') {
        throw new InvalidArgumentException('Обязательные поля владельца не заполнены.');
    }
    $pdo = db();
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    if ($stmt->fetchColumn()) {
        throw new RuntimeException('Пользователь с таким email уже зарегистрирован.');
    }
    $roleCol = 'global_role';
    if (function_exists('db_column_exists') && !db_column_exists('users', 'global_role')) {
        $roleCol = 'role';
    }
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("INSERT INTO users (name, email, password_hash, {$roleCol}) VALUES (?, ?, ?, 'owner')");
    $stmt->execute([$name, $email, $hash]);
    return (int)$pdo->lastInsertId();
}

/**
 * Create restaurant. Returns restaurant_id.
 */
function onboarding_create_restaurant(array $data): int
{
    $name      = trim((string)($data['restaurant_name'] ?? ''));
    $subdomain = trim(strtolower((string)($data['desired_subdomain'] ?? '')));
    $ownerId   = (int)($data['owner_user_id'] ?? 0);
    if ($name === '' || $subdomain === '' || $ownerId < 1) {
        throw new InvalidArgumentException('Данные ресторана неполные.');
    }
    $pdo = db();
    $statusCol = '';
    $statusVal = '';
    if (function_exists('db_column_exists') && db_column_exists('restaurants', 'status')) {
        $statusCol = ', status';
        $statusVal = ", 'active'";
    }
    $ownerCol = '';
    $ownerVal = '';
    if (function_exists('db_column_exists') && db_column_exists('restaurants', 'owner_user_id')) {
        $ownerCol = ', owner_user_id';
        $ownerVal = ', ?';
    }
    $stmt = $pdo->prepare("
        INSERT INTO restaurants (name, subdomain{$ownerCol}{$statusCol})
        VALUES (?, ?{$ownerVal}{$statusVal})
    ");
    $params = [$name, $subdomain];
    if ($ownerCol !== '') {
        $params[] = $ownerId;
    }
    $stmt->execute($params);
    return (int)$pdo->lastInsertId();
}

/**
 * Link owner to restaurant: users_restaurants + owner_user_id if column exists.
 */
function onboarding_link_owner(int $userId, int $restaurantId): void
{
    $pdo = db();
    $stmt = $pdo->prepare("INSERT INTO users_restaurants (user_id, restaurant_id, restaurant_role) VALUES (?, ?, 'owner')");
    $stmt->execute([$userId, $restaurantId]);
    if (function_exists('db_column_exists') && db_column_exists('restaurants', 'owner_user_id')) {
        $upd = $pdo->prepare("UPDATE restaurants SET owner_user_id = ? WHERE id = ? LIMIT 1");
        $upd->execute([$userId, $restaurantId]);
    }
}

/**
 * Create default tables (Стол 1 … Стол N) only if restaurant has no tables.
 */
function onboarding_create_default_tables(int $restaurantId, int $count = 10): void
{
    if ($count < 1 || $count > 100) {
        $count = 10;
    }
    try {
        $pdo = db();
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM tables AS t
            WHERE t.restaurant_id = ?
            " . qr_public_sql_exclude_delivery($pdo, 't') . "
        ");
        $stmt->execute([$restaurantId]);
        if ((int)$stmt->fetchColumn() > 0) {
            return;
        }
        $insert = $pdo->prepare("INSERT INTO tables (restaurant_id, name) VALUES (?, ?)");
        for ($i = 1; $i <= $count; $i++) {
            $insert->execute([$restaurantId, 'Стол ' . $i]);
        }
    } catch (Throwable $e) {
        error_log('ONBOARDING_CREATE_DEFAULT_TABLES rest=' . $restaurantId . ' ' . $e->getMessage());
        throw $e;
    }
}

/**
 * Bootstrap trial subscription (14 days) for new signup if billing exists. Do not break registration on failure.
 */
function onboarding_bootstrap_subscription_if_possible(int $userId, int $restaurantId): void
{
    if (function_exists('billing_ensure_trial_for_new_owner')) {
        try {
            require_once __DIR__ . '/billing.php';
            billing_ensure_trial_for_new_owner($userId);
            if (function_exists('billing_get_trial_info') && function_exists('billing_seed_restaurant_trial')) {
                $trialInfo = billing_get_trial_info($userId, $restaurantId);
                $trialEndsAt = (string)($trialInfo['trial_ends_at'] ?? '');
                billing_seed_restaurant_trial($restaurantId, $trialEndsAt !== '' ? $trialEndsAt : null);
            }
        } catch (Throwable $e) {
            error_log('STABILITY_ERROR onboarding_bootstrap_subscription user_id=' . $userId . ' rest_id=' . $restaurantId . ' ' . $e->getMessage());
        }
    }
}

/**
 * Full self-registration in one transaction.
 * @return array{user_id:int, restaurant_id:int, subdomain:string}
 */
function onboarding_full_register(array $data): array
{
    $restaurantName = trim((string)($data['restaurant_name'] ?? ''));
    $ownerName      = trim((string)($data['owner_name'] ?? ''));
    $email          = trim((string)($data['email'] ?? ''));
    $password       = (string)($data['password'] ?? '');
    $subdomain      = trim(strtolower((string)($data['desired_subdomain'] ?? '')));

    if ($restaurantName === '' || $ownerName === '' || $email === '' || $password === '' || $subdomain === '') {
        throw new InvalidArgumentException('Не заполнены обязательные поля.');
    }

    $valid = onboarding_validate_subdomain($subdomain);
    if (!$valid['ok']) {
        throw new RuntimeException($valid['error'] ?? 'Недопустимый поддомен.');
    }
    $subdomain = $valid['normalized'];

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $userId = onboarding_create_owner_user([
            'owner_name' => $ownerName,
            'email'     => $email,
            'password'  => $password,
            'phone'     => $data['phone'] ?? '',
        ]);
        $restaurantId = onboarding_create_restaurant([
            'restaurant_name'   => $restaurantName,
            'desired_subdomain' => $subdomain,
            'owner_user_id'     => $userId,
        ]);
        onboarding_link_owner($userId, $restaurantId);
        onboarding_create_default_tables($restaurantId, 10);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    onboarding_bootstrap_subscription_if_possible($userId, $restaurantId);

    return [
        'user_id'       => $userId,
        'restaurant_id' => $restaurantId,
        'subdomain'     => $subdomain,
    ];
}
