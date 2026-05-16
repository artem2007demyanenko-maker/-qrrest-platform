<?php


require_once __DIR__ . '/db.php';

function get_main_domain(): string
{
    $config = require __DIR__ . '/config.php';
    return $config['app']['main_domain'];
}

function get_subdomain_slug(): ?string
{
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $mainDomain = get_main_domain();


    $host = explode(':', $host)[0];

    if ($host === $mainDomain) {
        return null;
    }

    if ($host === 'www.' . $mainDomain) {
        return null;
    }


    if (substr($host, -strlen('.' . $mainDomain)) === '.' . $mainDomain) {
        $sub = substr($host, 0, -strlen('.' . $mainDomain));
        return $sub ?: null;
    }


    return null;
}

if (!function_exists('get_restaurant_by_id_active')) {
    function get_restaurant_by_id_active(int $restaurantId): ?array
    {
        if ($restaurantId <= 0) {
            return null;
        }
        $pdo = db();
        if (!function_exists('schema_guard_restaurants_deleted_sql')) {
            require_once __DIR__ . '/schema_guard.php';
        }
        $deletedSql = schema_guard_restaurants_deleted_sql('');
        $statusSql = (function_exists('db_column_exists') && db_column_exists('restaurants', 'status')) ? " AND status = 'active'" : '';
        $stmt = $pdo->prepare("SELECT * FROM restaurants WHERE id = :id" . $statusSql . $deletedSql . " LIMIT 1");
        $stmt->execute(['id' => $restaurantId]);
        $restaurant = $stmt->fetch(PDO::FETCH_ASSOC);
        return $restaurant ?: null;
    }
}

if (!function_exists('hydrate_restaurant_loyalty_fields')) {
    function hydrate_restaurant_loyalty_fields(PDO $pdo, array $restaurant): array
    {
        try {
            $st = $pdo->prepare("SELECT enabled, earn_percent FROM restaurant_loyalty_settings WHERE restaurant_id = ? LIMIT 1");
            $st->execute([(int)$restaurant['id']]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row !== false) {
                $restaurant['loyalty_enabled'] = (int)($row['enabled'] ?? 0);
                $restaurant['loyalty_percent'] = (float)($row['earn_percent'] ?? 0);
            } else {
                $restaurant['loyalty_enabled'] = 0;
                $restaurant['loyalty_percent'] = 0;
            }
        } catch (Throwable $e) {
            $restaurant['loyalty_enabled'] = 0;
            $restaurant['loyalty_percent'] = 0;
        }
        return $restaurant;
    }
}

if (!function_exists('user_has_restaurant_access_fallback')) {
    function user_has_restaurant_access_fallback(int $userId, int $restaurantId): bool
    {
        if ($userId <= 0 || $restaurantId <= 0) {
            return false;
        }
        $pdo = db();
        $activeCond = '';
        if (function_exists('db_column_exists') && db_column_exists('users_restaurants', 'is_active')) {
            $activeCond = ' AND COALESCE(is_active, 1) = 1';
        }
        $stmt = $pdo->prepare("
            SELECT 1
            FROM users_restaurants
            WHERE user_id = :uid
              AND restaurant_id = :rid
              {$activeCond}
            LIMIT 1
        ");
        $stmt->execute([
            ':uid' => $userId,
            ':rid' => $restaurantId,
        ]);
        return (bool)$stmt->fetchColumn();
    }
}

if (!function_exists('resolve_restaurant_for_authenticated_user')) {
    function resolve_restaurant_for_authenticated_user(): ?array
    {
        if (!function_exists('auth_user') || !function_exists('auth_start_session')) {
            return null;
        }

        auth_start_session();
        $user = auth_user();
        if (!$user || empty($user['id'])) {
            return null;
        }
        $userId = (int)$user['id'];
        $isProjectOwner = function_exists('is_project_owner') && is_project_owner();

        $pdo = db();
        $preferredIds = [];

        $queryRestaurantId = isset($_GET['restaurant_id']) ? (int)$_GET['restaurant_id'] : 0;
        if ($queryRestaurantId > 0) {
            $preferredIds[] = $queryRestaurantId;
        }
        $sessionRestaurantId = isset($_SESSION['current_restaurant_id']) ? (int)$_SESSION['current_restaurant_id'] : 0;
        if ($sessionRestaurantId > 0) {
            $preferredIds[] = $sessionRestaurantId;
        }
        $preferredIds = array_values(array_unique(array_filter($preferredIds)));

        foreach ($preferredIds as $candidateId) {
            if ($isProjectOwner || user_has_restaurant_access_fallback($userId, $candidateId)) {
                $restaurant = get_restaurant_by_id_active($candidateId);
                if ($restaurant) {
                    $_SESSION['current_restaurant_id'] = (int)$restaurant['id'];
                    return hydrate_restaurant_loyalty_fields($pdo, $restaurant);
                }
            }
        }

        if ($isProjectOwner) {
            if (!function_exists('schema_guard_restaurants_deleted_sql')) {
                require_once __DIR__ . '/schema_guard.php';
            }
            $deletedSql = schema_guard_restaurants_deleted_sql('r');
            $statusSql = (function_exists('db_column_exists') && db_column_exists('restaurants', 'status')) ? " AND r.status = 'active'" : '';
            $stmt = $pdo->prepare("
                SELECT r.*
                FROM restaurants r
                WHERE 1=1 {$statusSql} {$deletedSql}
                ORDER BY r.id ASC
                LIMIT 1
            ");
            $stmt->execute();
            $restaurant = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($restaurant) {
                $_SESSION['current_restaurant_id'] = (int)$restaurant['id'];
                return hydrate_restaurant_loyalty_fields($pdo, $restaurant);
            }
            return null;
        }

        $activeCond = '';
        if (function_exists('db_column_exists') && db_column_exists('users_restaurants', 'is_active')) {
            $activeCond = ' AND COALESCE(ur.is_active, 1) = 1';
        }
        if (!function_exists('schema_guard_restaurants_deleted_sql')) {
            require_once __DIR__ . '/schema_guard.php';
        }
        $deletedSql = schema_guard_restaurants_deleted_sql('r');
        $statusSql = (function_exists('db_column_exists') && db_column_exists('restaurants', 'status')) ? " AND r.status = 'active'" : '';
        $stmt = $pdo->prepare("
            SELECT r.*
            FROM users_restaurants ur
            INNER JOIN restaurants r ON r.id = ur.restaurant_id
            WHERE ur.user_id = :uid
              {$activeCond}
              {$statusSql}
              {$deletedSql}
            ORDER BY
              CASE ur.restaurant_role
                WHEN 'owner' THEN 1
                WHEN 'admin' THEN 2
                WHEN 'staff' THEN 3
                WHEN 'waiter' THEN 4
                WHEN 'kitchen' THEN 5
                WHEN 'bar' THEN 6
                WHEN 'courier' THEN 7
                ELSE 9
              END,
              ur.restaurant_id ASC
            LIMIT 1
        ");
        $stmt->execute([':uid' => $userId]);
        $restaurant = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($restaurant) {
            $_SESSION['current_restaurant_id'] = (int)$restaurant['id'];
            return hydrate_restaurant_loyalty_fields($pdo, $restaurant);
        }

        return null;
    }
}

if (!function_exists('get_current_restaurant_or_null')) {
    function get_current_restaurant_or_null(): ?array
    {
        $slug = get_subdomain_slug();
        if ($slug !== null) {
            if ($slug === 'demo') {
                require_once __DIR__ . '/demo.php';
                return demo_get_restaurant();
            }

            $pdo = db();
            if (!function_exists('schema_guard_restaurants_deleted_sql')) {
                require_once __DIR__ . '/schema_guard.php';
            }
            $deletedSql = schema_guard_restaurants_deleted_sql('');
            $statusSql = (function_exists('db_column_exists') && db_column_exists('restaurants', 'status')) ? " AND status = 'active'" : '';
            $stmt = $pdo->prepare("SELECT * FROM restaurants WHERE subdomain = :slug" . $statusSql . $deletedSql . " LIMIT 1");
            $stmt->execute(['slug' => $slug]);
            $restaurant = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$restaurant) {
                return null;
            }
            if (function_exists('auth_start_session')) {
                auth_start_session();
                $_SESSION['current_restaurant_id'] = (int)$restaurant['id'];
            }
            return hydrate_restaurant_loyalty_fields($pdo, $restaurant);
        }

        return resolve_restaurant_for_authenticated_user();
    }
}

function get_current_restaurant_or_404(): ?array
{
    $restaurant = get_current_restaurant_or_null();
    if ($restaurant) {
        return $restaurant;
    }
    http_response_code(404);
    echo "Restaurant not found";
    exit;
}
