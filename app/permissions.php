<?php

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';

// -----------------------------
// Restaurant-scoped permissions
// -----------------------------

/**
 * Requires current restaurant context (subdomain). In demo mode, skips validation.
 * Use before require_restaurant_role() when a page needs $currentRestaurant.
 */
function require_current_restaurant(): void
{
    if (function_exists('is_demo_mode') && is_demo_mode()) {
        return;
    }
    global $currentRestaurant;
    if (empty($currentRestaurant) || empty($currentRestaurant['id'])) {
        http_response_code(400);
        echo 'Restaurant context required';
        exit;
    }
}

function user_has_restaurant_role(int $restaurantId, array $roles): bool
{
    $user = auth_user();
    if (!$user) {
        return false;
    }

    // project_owner has access everywhere
    if (($user['global_role'] ?? null) === 'project_owner') {
        return true;
    }

    if (empty($roles)) {
        return false;
    }

    $pdo = db();
    $inRoles = implode(',', array_fill(0, count($roles), '?'));
    $params  = array_merge([(int)$user['id'], (int)$restaurantId], $roles);

    $sql = "SELECT 1
            FROM users_restaurants
            WHERE user_id = ?
              AND restaurant_id = ?
              AND restaurant_role IN ($inRoles)
            LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return (bool)$stmt->fetchColumn();
}

function require_restaurant_role(int $restaurantId, array $roles): void
{
    require_login();
    if (function_exists('is_demo_mode') && is_demo_mode() && $restaurantId === 0) {
        return;
    }
    if (!user_has_restaurant_role($restaurantId, $roles)) {
        http_response_code(403);
        echo 'Access denied';
        exit;
    }
}
/**
 * Требует контекст текущего ресторана (поддомен) и проверяет роль пользователя в этом ресторане.
 * Использование: require_current_restaurant_role(['owner','admin']);
 */
function require_current_restaurant_role(array $roles): void
{
    if (function_exists('is_demo_mode') && is_demo_mode()) {
        return;
    }
    // bootstrap.php задаёт $currentRestaurant
    global $currentRestaurant;

    if (empty($currentRestaurant) || empty($currentRestaurant['id'])) {
        http_response_code(400);
        echo "Restaurant context required";
        exit;
    }

    require_restaurant_role((int)$currentRestaurant['id'], $roles);
}
// -----------------------------
// Global (platform) permissions
// -----------------------------

function user_has_role(array $roles): bool
{
    $user = auth_user();
    if (!$user) {
        return false;
    }

    // project_owner has access everywhere
    if (($user['global_role'] ?? null) === 'project_owner') {
        return true;
    }

    if (empty($roles)) {
        return false;
    }

    return in_array(($user['global_role'] ?? null), $roles, true);
}

function require_role(array $roles): void
{
    require_login();
    if (function_exists('is_demo_mode') && is_demo_mode()) {
        return;
    }
    if (!user_has_role($roles)) {
        http_response_code(403);
        echo 'Access denied';
        exit;
    }
}