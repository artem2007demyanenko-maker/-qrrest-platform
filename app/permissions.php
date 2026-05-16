<?php

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';

if (!function_exists('render_restaurant_context_required')) {
    function render_restaurant_context_required(): void
    {
        $isJson = function_exists('is_api_request')
            ? is_api_request()
            : (
                stripos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false
                || stripos((string)($_SERVER['REQUEST_URI'] ?? ''), '/ajax/') !== false
                || stripos((string)($_SERVER['REQUEST_URI'] ?? ''), '/api') !== false
                || preg_match('~_api\.php(?:$|\?)~i', (string)($_SERVER['REQUEST_URI'] ?? '')) === 1
            );
        if ($isJson) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'message' => 'restaurant_context_required',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $user = function_exists('auth_user') ? auth_user() : null;
        $isPlatformOwner = function_exists('is_project_owner') && is_project_owner();
        $homeUrl = $isPlatformOwner ? '/project-admin/restaurants.php' : '/restaurant/dashboard.php';
        $homeText = $isPlatformOwner ? 'Выбрать ресторан' : 'К панели';
        if (!$user) {
            $homeUrl = '/login.php';
            $homeText = 'Войти';
        }

        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="ru"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Ресторан не выбран</title><script src="https://cdn.tailwindcss.com"></script></head>';
        echo '<body class="min-h-screen bg-slate-950 text-slate-100 flex items-center justify-center p-4">';
        echo '<div class="w-full max-w-md rounded-3xl border border-slate-800 bg-slate-900/80 p-6 shadow-2xl">';
        echo '<h1 class="text-xl font-semibold">Ресторан не выбран</h1>';
        echo '<p class="mt-2 text-sm text-slate-400">Для этой страницы нужен контекст ресторана. Выберите ресторан и попробуйте снова.</p>';
        echo '<div class="mt-5 flex flex-wrap gap-2">';
        echo '<a href="' . htmlspecialchars($homeUrl, ENT_QUOTES, 'UTF-8') . '" class="px-4 py-2 rounded-xl bg-emerald-500 hover:bg-emerald-400 text-slate-950 text-sm font-semibold">' . htmlspecialchars($homeText, ENT_QUOTES, 'UTF-8') . '</a>';
        echo '<a href="/login.php" class="px-4 py-2 rounded-xl border border-slate-700 bg-slate-950/60 hover:bg-slate-800 text-sm">Логин</a>';
        echo '</div></div></body></html>';
        exit;
    }
}

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
    require_login();
    global $currentRestaurant;
    if (empty($currentRestaurant) || empty($currentRestaurant['id'])) {
        if (function_exists('get_current_restaurant_or_null')) {
            $resolved = get_current_restaurant_or_null();
            if (!empty($resolved['id'])) {
                $currentRestaurant = $resolved;
                return;
            }
        }
        if (function_exists('resolve_staff_restaurant_context')) {
            $ctx = resolve_staff_restaurant_context();
            if (!empty($ctx['ok']) && !empty($ctx['restaurant']['id'])) {
                $currentRestaurant = $ctx['restaurant'];
                return;
            }
        }
        http_response_code(400);
        render_restaurant_context_required();
    }
}

function user_has_restaurant_role(int $restaurantId, array $roles): bool
{
    $user = auth_user();
    if (!$user) {
        return false;
    }

    // Platform owner roles (owner/project_owner/platform_owner/super_admin) have access everywhere.
    if (function_exists('is_project_owner') && is_project_owner()) {
        return true;
    }

    if (empty($roles)) {
        return false;
    }
    $rolesNorm = [];
    foreach ($roles as $roleRaw) {
        $roleNorm = strtolower(trim((string)$roleRaw));
        if ($roleNorm !== '') {
            $rolesNorm[] = $roleNorm;
        }
    }
    $rolesNorm = array_values(array_unique($rolesNorm));
    if ($rolesNorm === []) {
        return false;
    }

    $pdo = db();
    $inRoles = implode(',', array_fill(0, count($rolesNorm), '?'));
    $params  = array_merge([(int)$user['id'], (int)$restaurantId], $rolesNorm);

    $activeCond = '';
    if (function_exists('db_column_exists') && db_column_exists('users_restaurants', 'is_active')) {
        $activeCond = " AND COALESCE(is_active, 1) = 1";
    }

    $sql = "SELECT 1
            FROM users_restaurants
            WHERE user_id = ?
              AND restaurant_id = ?
              AND LOWER(TRIM(COALESCE(restaurant_role, ''))) IN ($inRoles)
              {$activeCond}
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
        if (function_exists('auth_deny')) {
            auth_deny('access_denied', 403);
        }
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
        if (function_exists('get_current_restaurant_or_null')) {
            $resolved = get_current_restaurant_or_null();
            if (!empty($resolved['id'])) {
                $currentRestaurant = $resolved;
            }
        }
    }

    if (empty($currentRestaurant) || empty($currentRestaurant['id'])) {
        http_response_code(400);
        render_restaurant_context_required();
    }

    require_restaurant_role((int)$currentRestaurant['id'], $roles);
}

function current_user_restaurant_role(int $restaurantId): ?string
{
    if ($restaurantId <= 0) {
        return null;
    }

    $user = auth_user();
    if (!$user) {
        return null;
    }

    $pdo = db();
    $activeCond = '';
    if (function_exists('db_column_exists') && db_column_exists('users_restaurants', 'is_active')) {
        $activeCond = " AND COALESCE(is_active, 1) = 1";
    }

    $stmt = $pdo->prepare("
        SELECT LOWER(TRIM(COALESCE(restaurant_role, ''))) AS restaurant_role
        FROM users_restaurants
        WHERE user_id = :uid
          AND restaurant_id = :rid
          {$activeCond}
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->execute([
        ':uid' => (int)$user['id'],
        ':rid' => $restaurantId,
    ]);

    $role = $stmt->fetchColumn();
    if (!is_string($role) || trim($role) === '') {
        return null;
    }

    return function_exists('normalize_restaurant_role')
        ? normalize_restaurant_role($role)
        : strtolower(trim((string)$role));
}

if (!function_exists('normalize_restaurant_role')) {
    function normalize_restaurant_role($role): string
    {
        return strtolower(trim((string)$role));
    }
}

if (!function_exists('staff_context_debug_enabled')) {
    function staff_context_debug_enabled(): bool
    {
        $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
        if ((string)getenv('QRREST_AUTH_DEBUG') === '1') {
            return true;
        }

        foreach (['/staff/kitchen', '/staff/kds', '/staff/bar', '/ajax/kds_'] as $needle) {
            if ($needle !== '' && str_contains($uri, $needle)) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('staff_context_debug_log')) {
    function staff_context_debug_log(string $event, array $context = []): void
    {
        if (!staff_context_debug_enabled()) {
            return;
        }

        $payload = array_merge([
            'event' => $event,
            'rid' => function_exists('app_rid') ? app_rid() : '',
            'uri' => (string)($_SERVER['REQUEST_URI'] ?? ''),
            'host' => (string)($_SERVER['HTTP_HOST'] ?? ''),
        ], $context);

        error_log('STAFF_CONTEXT_DEBUG ' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}

if (!function_exists('restaurant_station_role_map')) {
    function restaurant_station_role_map(): array
    {
        return [
            'kitchen' => 'hot',
            'cold' => 'cold',
            'dessert' => 'dessert',
            'grill' => 'grill',
            'pizza' => 'pizza',
            'sushi' => 'sushi',
            'bar' => 'bar',
        ];
    }
}

if (!function_exists('restaurant_station_roles')) {
    function restaurant_station_roles(): array
    {
        return array_keys(restaurant_station_role_map());
    }
}

if (!function_exists('restaurant_staff_known_roles')) {
    function restaurant_staff_known_roles(): array
    {
        return array_values(array_unique(array_merge(
            ['owner', 'admin', 'staff', 'waiter', 'courier'],
            restaurant_station_roles()
        )));
    }
}

if (!function_exists('restaurant_role_is_station_role')) {
    function restaurant_role_is_station_role($role): bool
    {
        return array_key_exists(normalize_restaurant_role($role), restaurant_station_role_map());
    }
}

if (!function_exists('restaurant_role_is_fixed_station_role')) {
    function restaurant_role_is_fixed_station_role($role): bool
    {
        $roleNorm = normalize_restaurant_role($role);
        return $roleNorm !== 'kitchen' && restaurant_role_is_station_role($roleNorm);
    }
}

if (!function_exists('restaurant_role_station_key')) {
    function restaurant_role_station_key($role): ?string
    {
        $roleNorm = normalize_restaurant_role($role);
        $map = restaurant_station_role_map();
        return $map[$roleNorm] ?? null;
    }
}

if (!function_exists('normalize_station_key')) {
    function normalize_station_key($station): string
    {
        $normalized = strtolower(trim((string)$station));
        $map = [
            'kitchen' => 'hot',
            'hot' => 'hot',
            'cold' => 'cold',
            'bar' => 'bar',
            'dessert' => 'dessert',
            'desserts' => 'dessert',
            'grill' => 'grill',
            'pizza' => 'pizza',
            'sushi' => 'sushi',
            'hookah' => 'hookah',
            'custom' => 'custom',
            'all' => 'all',
        ];
        return $map[$normalized] ?? $normalized;
    }
}

if (!function_exists('station_to_kds_key')) {
    function station_to_kds_key($station): string
    {
        $normalized = normalize_station_key($station);
        return match ($normalized) {
            'hot' => 'kitchen',
            'cold' => 'cold',
            'bar' => 'bar',
            'dessert' => 'dessert',
            'grill' => 'grill',
            'pizza' => 'pizza',
            'sushi' => 'sushi',
            'hookah' => 'hookah',
            'custom' => 'custom',
            'all' => 'all',
            default => 'kitchen',
        };
    }
}

if (!function_exists('kds_key_to_staff_station')) {
    function kds_key_to_staff_station($station): string
    {
        $normalized = strtolower(trim((string)$station));
        return match ($normalized) {
            'kitchen', 'hot' => 'hot',
            'cold' => 'cold',
            'bar' => 'bar',
            'dessert', 'desserts' => 'dessert',
            'grill' => 'grill',
            'pizza' => 'pizza',
            'sushi' => 'sushi',
            'hookah' => 'hookah',
            'custom' => 'custom',
            'all' => 'all',
            default => normalize_station_key($normalized),
        };
    }
}

if (!function_exists('current_user_restaurant_station')) {
    function current_user_restaurant_station(?int $restaurantId = null): ?string
    {
        $user = auth_user();
        if (!$user) {
            return null;
        }
        $uid = (int)($user['id'] ?? 0);
        if ($uid <= 0) {
            return null;
        }

        global $currentRestaurant;
        $rid = $restaurantId ?? (int)($currentRestaurant['id'] ?? 0);
        if ($rid <= 0) {
            return null;
        }

        $pdo = db();

        if (function_exists('db_column_exists') && db_column_exists('users_restaurants', 'station')) {
            $activeCond = '';
            if (function_exists('db_column_exists') && db_column_exists('users_restaurants', 'is_active')) {
                $activeCond = " AND COALESCE(is_active, 1) = 1";
            }
            $stmtUr = $pdo->prepare("
                SELECT station
                FROM users_restaurants
                WHERE user_id = :uid
                  AND restaurant_id = :rid
                  {$activeCond}
                ORDER BY id DESC
                LIMIT 1
            ");
            $stmtUr->execute([':uid' => $uid, ':rid' => $rid]);
            $stationRaw = $stmtUr->fetchColumn();
            if (is_string($stationRaw) && trim($stationRaw) !== '') {
                return normalize_station_key($stationRaw);
            }
        }

        if (function_exists('db_table_exists') && db_table_exists('staff_users')
            && function_exists('db_column_exists')
            && db_column_exists('staff_users', 'station')
            && db_column_exists('staff_users', 'user_id')
            && db_column_exists('staff_users', 'restaurant_id')) {
            $stmtStaff = $pdo->prepare("
                SELECT station
                FROM staff_users
                WHERE user_id = :uid
                  AND restaurant_id = :rid
                ORDER BY id DESC
                LIMIT 1
            ");
            $stmtStaff->execute([':uid' => $uid, ':rid' => $rid]);
            $stationRaw = $stmtStaff->fetchColumn();
            if (is_string($stationRaw) && trim($stationRaw) !== '') {
                return normalize_station_key($stationRaw);
            }
        }

        return null;
    }
}

if (!function_exists('current_staff_station')) {
    function current_staff_station(?int $restaurantId = null): string
    {
        global $currentRestaurant;
        $rid = $restaurantId ?? (int)($currentRestaurant['id'] ?? 0);
        $role = normalize_restaurant_role((string)(current_user_restaurant_role($rid) ?? ''));

        if (in_array($role, ['owner', 'admin'], true)) {
            return 'all';
        }
        if ($role === 'bar') {
            return 'bar';
        }
        if ($role !== 'kitchen' && restaurant_role_is_station_role($role)) {
            return (string)restaurant_role_station_key($role);
        }
        if ($role === 'kitchen') {
            $station = current_user_restaurant_station($rid);
            if (in_array((string)$station, ['hot', 'cold', 'bar', 'dessert', 'grill', 'pizza', 'sushi'], true)) {
                return (string)$station;
            }
            return 'hot';
        }

        $station = current_user_restaurant_station($rid);
        if ($station !== null && $station !== '') {
            return $station;
        }
        return 'all';
    }
}

if (!function_exists('can_access_station')) {
    function can_access_station($station, ?int $restaurantId = null, ?string $roleOverride = null): bool
    {
        global $currentRestaurant;
        $rid = $restaurantId ?? (int)($currentRestaurant['id'] ?? 0);
        $role = normalize_restaurant_role($roleOverride ?? (string)(current_user_restaurant_role($rid) ?? ''));
        $targetStation = kds_key_to_staff_station($station);

        if ($targetStation === 'all') {
            return in_array($role, ['owner', 'admin'], true);
        }
        if (in_array($role, ['owner', 'admin'], true)) {
            return true;
        }
        if (!restaurant_role_is_station_role($role)) {
            return false;
        }

        $staffStation = current_staff_station($rid);
        if ($staffStation === 'all') {
            return true;
        }
        if ($role === 'kitchen' && in_array($staffStation, ['hot', 'kitchen'], true)) {
            return in_array($targetStation, ['hot', 'grill'], true);
        }
        return $staffStation === $targetStation;
    }
}

if (!function_exists('user_has_station_access')) {
    function user_has_station_access($restaurantRole, $station): bool
    {
        $role = normalize_restaurant_role((string)$restaurantRole);
        $targetStation = kds_key_to_staff_station($station);
        if ($targetStation === 'all') {
            return in_array($role, ['owner', 'admin'], true);
        }
        if (in_array($role, ['owner', 'admin'], true)) {
            return true;
        }
        if ($role === 'bar') {
            return $targetStation === 'bar';
        }
        if ($role === 'kitchen') {
            $mapped = kds_key_to_staff_station(station_to_kds_key(current_staff_station()));
            if (in_array($mapped, ['hot', 'kitchen'], true)) {
                return in_array($targetStation, ['hot', 'grill'], true);
            }
            return $mapped === $targetStation;
        }
        if (restaurant_role_is_station_role($role)) {
            return restaurant_role_station_key($role) === $targetStation;
        }
        if (function_exists('can_access_station')) {
            return can_access_station($targetStation, null, $role);
        }
        return false;
    }
}

if (!function_exists('ensure_staff_station_schema')) {
    function ensure_staff_station_schema(): void
    {
        static $ensured = false;
        if ($ensured) {
            return;
        }
        $ensured = true;
        if (!function_exists('db_table_exists') || !function_exists('db_column_exists')) {
            return;
        }
        $pdo = db();
        if (!$pdo instanceof PDO) {
            return;
        }

        try {
            if (db_table_exists('staff_users') && !db_column_exists('staff_users', 'station')) {
                $pdo->exec("ALTER TABLE staff_users ADD COLUMN station VARCHAR(32) NULL DEFAULT 'all'");
            }
        } catch (Throwable $e) {
            error_log('STAFF_STATION_SCHEMA_ENSURE_FAIL staff_users.station ' . $e->getMessage());
        }

        try {
            if (db_table_exists('users_restaurants') && !db_column_exists('users_restaurants', 'station')) {
                $pdo->exec("ALTER TABLE users_restaurants ADD COLUMN station VARCHAR(32) NULL DEFAULT NULL");
            }
        } catch (Throwable $e) {
            error_log('STAFF_STATION_SCHEMA_ENSURE_FAIL users_restaurants.station ' . $e->getMessage());
        }
    }
}

function staff_account_is_active(int $restaurantId, ?int $userId = null): bool
{
    if ($restaurantId <= 0) {
        return false;
    }

    $user = auth_user();
    if (!$user) {
        return false;
    }
    $uid = $userId ?? (int)($user['id'] ?? 0);
    if ($uid <= 0) {
        return false;
    }

    $pdo = db();
    $activeCond = '';
    if (function_exists('db_column_exists') && db_column_exists('users_restaurants', 'is_active')) {
        $activeCond = " AND COALESCE(is_active, 1) = 1";
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
        ':uid' => $uid,
        ':rid' => $restaurantId,
    ]);
    return (bool)$stmt->fetchColumn();
}

function require_staff_login(): void
{
    require_login();
    require_current_restaurant();
}

if (!function_exists('resolve_staff_restaurant_context')) {
    /**
     * @return array{
     *   ok:bool,
     *   reason:string,
     *   source:string,
     *   user_id:int,
     *   restaurant_id:int,
     *   role:?string,
     *   restaurant:?array
     * }
     */
    function resolve_staff_restaurant_context(?int $preferredRestaurantId = null, array $allowedRoles = []): array
    {
        require_login();
        auth_start_session();

        $user = auth_user();
        $uid = (int)($user['id'] ?? 0);
        if ($uid <= 0) {
            return [
                'ok' => false,
                'reason' => 'missing_user',
                'source' => 'auth',
                'user_id' => 0,
                'restaurant_id' => 0,
                'role' => null,
                'restaurant' => null,
            ];
        }

        $knownRoles = restaurant_staff_known_roles();
        $allowedRolesNorm = [];
        foreach ($allowedRoles as $allowedRoleRaw) {
            $allowedRoleNorm = normalize_restaurant_role($allowedRoleRaw);
            if ($allowedRoleNorm !== '' && in_array($allowedRoleNorm, $knownRoles, true)) {
                $allowedRolesNorm[] = $allowedRoleNorm;
            }
        }
        $allowedRolesNorm = array_values(array_unique($allowedRolesNorm));
        global $currentRestaurant;
        $currentRestaurantId = (int)($currentRestaurant['id'] ?? 0);
        $sessionRestaurantId = (int)($_SESSION['current_restaurant_id'] ?? 0);
        $queryRestaurantId = isset($_GET['restaurant_id']) ? (int)$_GET['restaurant_id'] : 0;
        $requestedStationRaw = (string)($_GET['station'] ?? '');
        $requestedStation = $requestedStationRaw !== '' ? station_to_kds_key(normalize_station_key($requestedStationRaw)) : '';
        staff_context_debug_log('resolve_start', [
            'user_id' => $uid,
            'preferred_restaurant_id' => $preferredRestaurantId,
            'current_restaurant_id' => $currentRestaurantId,
            'session_restaurant_id' => $sessionRestaurantId,
            'query_restaurant_id' => $queryRestaurantId,
            'requested_station_raw' => $requestedStationRaw,
            'requested_station' => $requestedStation,
            'allowed_roles' => $allowedRolesNorm,
            'known_roles' => $knownRoles,
            'current_restaurant' => [
                'id' => $currentRestaurantId,
                'name' => (string)($currentRestaurant['name'] ?? ''),
                'subdomain' => (string)($currentRestaurant['subdomain'] ?? ''),
            ],
        ]);

        $candidates = [];
        if ($preferredRestaurantId !== null && $preferredRestaurantId > 0) {
            $candidates[] = (int)$preferredRestaurantId;
        }
        if ($currentRestaurantId > 0) {
            $candidates[] = $currentRestaurantId;
        }
        if ($sessionRestaurantId > 0) {
            $candidates[] = $sessionRestaurantId;
        }
        if ($queryRestaurantId > 0) {
            $candidates[] = $queryRestaurantId;
        }
        $candidates = array_values(array_unique(array_filter($candidates, static fn($v) => (int)$v > 0)));

        foreach ($candidates as $rid) {
            $role = current_user_restaurant_role((int)$rid);
            $roleNorm = normalize_restaurant_role($role);
            $knownMatch = $roleNorm !== '' && in_array($roleNorm, $knownRoles, true);
            $allowedMatch = $allowedRolesNorm === [] || in_array($roleNorm, $allowedRolesNorm, true);
            $restaurant = null;
            $restaurantFound = false;
            if ($knownMatch && $allowedMatch) {
                $restaurant = function_exists('get_restaurant_by_id_active') ? get_restaurant_by_id_active((int)$rid) : null;
                $restaurantFound = is_array($restaurant) && (int)($restaurant['id'] ?? 0) > 0;
            }
            staff_context_debug_log('candidate_check', [
                'user_id' => $uid,
                'candidate_restaurant_id' => (int)$rid,
                'role_raw' => $role,
                'role' => $roleNorm,
                'known_roles_match' => $knownMatch,
                'allowed_roles_match' => $allowedMatch,
                'restaurant_found' => $restaurantFound,
            ]);

            if (!$knownMatch) {
                continue;
            }
            if (!$allowedMatch) {
                continue;
            }
            if (!$restaurantFound) {
                continue;
            }
            $_SESSION['current_restaurant_id'] = (int)$rid;
            $_SESSION['restaurant_id'] = (int)$rid;
            $currentRestaurant = $restaurant;
            staff_context_debug_log('resolve_ok', [
                'source' => 'candidate',
                'user_id' => $uid,
                'restaurant_id' => (int)$rid,
                'role' => $roleNorm,
                'requested_station' => $requestedStation,
                'resolved_station' => function_exists('current_staff_station') ? current_staff_station((int)$rid) : '',
            ]);
            return [
                'ok' => true,
                'reason' => 'ok',
                'source' => 'candidate',
                'user_id' => $uid,
                'restaurant_id' => (int)$rid,
                'role' => $roleNorm,
                'restaurant' => $restaurant,
            ];
        }

        $pdo = db();
        $activeCond = '';
        if (function_exists('db_column_exists') && db_column_exists('users_restaurants', 'is_active')) {
            $activeCond = " AND COALESCE(ur.is_active, 1) = 1";
        }
        if (!function_exists('schema_guard_restaurants_deleted_sql')) {
            require_once __DIR__ . '/schema_guard.php';
        }
        $deletedSql = schema_guard_restaurants_deleted_sql('r');
        $statusSql = (function_exists('db_column_exists') && db_column_exists('restaurants', 'status'))
            ? " AND r.status = 'active'"
            : '';

        $roleFilterSql = '';
        $roleFilterParams = [];
        if ($allowedRolesNorm !== []) {
            $rolePlaceholders = [];
            foreach ($allowedRolesNorm as $idx => $allowedRole) {
                $ph = ':ar' . $idx;
                $rolePlaceholders[] = $ph;
                $roleFilterParams[$ph] = $allowedRole;
            }
            $roleFilterSql = " AND LOWER(TRIM(COALESCE(ur.restaurant_role, ''))) IN (" . implode(',', $rolePlaceholders) . ") ";
        }

        $stmt = $pdo->prepare("
            SELECT
                ur.restaurant_id,
                LOWER(TRIM(COALESCE(ur.restaurant_role, ''))) AS restaurant_role
            FROM users_restaurants ur
            INNER JOIN restaurants r ON r.id = ur.restaurant_id
            WHERE ur.user_id = :uid
              {$activeCond}
              {$roleFilterSql}
              {$statusSql}
              {$deletedSql}
            ORDER BY
              CASE LOWER(TRIM(COALESCE(ur.restaurant_role, '')))
                WHEN 'owner' THEN 1
                WHEN 'admin' THEN 2
                WHEN 'staff' THEN 3
                WHEN 'waiter' THEN 4
                WHEN 'kitchen' THEN 5
                WHEN 'cold' THEN 6
                WHEN 'dessert' THEN 7
                WHEN 'grill' THEN 8
                WHEN 'pizza' THEN 9
                WHEN 'sushi' THEN 10
                WHEN 'bar' THEN 11
                WHEN 'courier' THEN 12
                ELSE 99
              END ASC,
              ur.id DESC
            LIMIT 1
        ");
        $params = array_merge([':uid' => $uid], $roleFilterParams);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $rid = (int)($row['restaurant_id'] ?? 0);
        $roleNorm = normalize_restaurant_role((string)($row['restaurant_role'] ?? ''));
        $rowKnownMatch = $roleNorm !== '' && in_array($roleNorm, $knownRoles, true);
        $rowAllowedMatch = $allowedRolesNorm === [] || in_array($roleNorm, $allowedRolesNorm, true);
        staff_context_debug_log('binding_query_result', [
            'user_id' => $uid,
            'row' => is_array($row) ? $row : null,
            'restaurant_id' => $rid,
            'role' => $roleNorm,
            'known_roles_match' => $rowKnownMatch,
            'allowed_roles_match' => $rowAllowedMatch,
            'allowed_roles' => $allowedRolesNorm,
            'requested_station' => $requestedStation,
        ]);

        if ($rid <= 0 || $roleNorm === '' || !$rowKnownMatch) {
            $denyReason = 'missing_binding';
            $diagRows = [];
            if (staff_context_debug_enabled()) {
                $diagSelect = [
                    'ur.id',
                    'ur.restaurant_id',
                    "LOWER(TRIM(COALESCE(ur.restaurant_role, ''))) AS restaurant_role",
                    'r.subdomain',
                ];
                if (function_exists('db_column_exists') && db_column_exists('users_restaurants', 'is_active')) {
                    $diagSelect[] = 'ur.is_active';
                }
                if (function_exists('db_column_exists') && db_column_exists('users_restaurants', 'station')) {
                    $diagSelect[] = 'ur.station';
                }
                if (function_exists('db_column_exists') && db_column_exists('restaurants', 'status')) {
                    $diagSelect[] = 'r.status';
                }
                if (function_exists('db_column_exists') && db_column_exists('restaurants', 'deleted_at')) {
                    $diagSelect[] = 'r.deleted_at';
                }
                try {
                    $diagStmt = $pdo->prepare("
                        SELECT " . implode(', ', $diagSelect) . "
                        FROM users_restaurants ur
                        LEFT JOIN restaurants r ON r.id = ur.restaurant_id
                        WHERE ur.user_id = :uid
                        ORDER BY ur.id DESC
                        LIMIT 10
                    ");
                    $diagStmt->execute([':uid' => $uid]);
                    $diagRows = $diagStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                    staff_context_debug_log('binding_query_diagnostic', [
                        'user_id' => $uid,
                        'rows' => $diagRows,
                    ]);
                } catch (Throwable $e) {
                    staff_context_debug_log('binding_query_diagnostic_error', [
                        'user_id' => $uid,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            if (staff_context_debug_enabled()) {
                if ($diagRows === []) {
                    $denyReason = 'no_binding_rows';
                } else {
                    $allowedDiagRows = [];
                    foreach ($diagRows as $diagRow) {
                        $diagRole = normalize_restaurant_role((string)($diagRow['restaurant_role'] ?? ''));
                        if ($allowedRolesNorm === [] || in_array($diagRole, $allowedRolesNorm, true)) {
                            $allowedDiagRows[] = $diagRow;
                        }
                    }
                    if ($allowedDiagRows === []) {
                        $denyReason = 'binding_role_not_allowed';
                    } else {
                        $inactiveOnly = array_key_exists('is_active', $allowedDiagRows[0])
                            && array_filter($allowedDiagRows, static fn($r) => (int)($r['is_active'] ?? 1) === 1) === [];
                        $inactiveRestaurantOnly = array_key_exists('status', $allowedDiagRows[0])
                            && array_filter($allowedDiagRows, static fn($r) => strtolower(trim((string)($r['status'] ?? ''))) === 'active') === [];
                        $deletedRestaurantOnly = array_key_exists('deleted_at', $allowedDiagRows[0])
                            && array_filter($allowedDiagRows, static fn($r) => empty($r['deleted_at'])) === [];

                        if ($inactiveOnly) {
                            $denyReason = 'binding_inactive';
                        } elseif ($inactiveRestaurantOnly) {
                            $denyReason = 'restaurant_inactive';
                        } elseif ($deletedRestaurantOnly) {
                            $denyReason = 'restaurant_deleted';
                        } else {
                            $denyReason = 'binding_filtered_by_context';
                        }
                    }
                }
            }

            error_log('STAFF_CONTEXT_DENY reason=' . $denyReason . ' rid=' . app_rid() . ' user_id=' . $uid);
            staff_context_debug_log('final_deny', [
                'reason' => $denyReason,
                'user_id' => $uid,
                'requested_station' => $requestedStation,
                'current_restaurant_id' => $currentRestaurantId,
                'session_restaurant_id' => $sessionRestaurantId,
                'query_restaurant_id' => $queryRestaurantId,
            ]);
            return [
                'ok' => false,
                'reason' => $denyReason,
                'source' => 'bindings',
                'user_id' => $uid,
                'restaurant_id' => 0,
                'role' => null,
                'restaurant' => null,
            ];
        }

        $restaurant = function_exists('get_restaurant_by_id_active') ? get_restaurant_by_id_active($rid) : null;
        if (!is_array($restaurant) || (int)($restaurant['id'] ?? 0) <= 0) {
            error_log('STAFF_CONTEXT_DENY reason=restaurant_not_found rid=' . app_rid() . ' user_id=' . $uid . ' restaurant_id=' . $rid);
            staff_context_debug_log('final_deny', [
                'reason' => 'restaurant_not_found',
                'user_id' => $uid,
                'restaurant_id' => $rid,
                'role' => $roleNorm,
                'requested_station' => $requestedStation,
            ]);
            return [
                'ok' => false,
                'reason' => 'restaurant_not_found',
                'source' => 'bindings',
                'user_id' => $uid,
                'restaurant_id' => $rid,
                'role' => $roleNorm,
                'restaurant' => null,
            ];
        }

        $_SESSION['current_restaurant_id'] = $rid;
        $_SESSION['restaurant_id'] = $rid;
        $currentRestaurant = $restaurant;
        staff_context_debug_log('resolve_ok', [
            'source' => 'bindings',
            'user_id' => $uid,
            'restaurant_id' => $rid,
            'role' => $roleNorm,
            'requested_station' => $requestedStation,
            'resolved_station' => function_exists('current_staff_station') ? current_staff_station($rid) : '',
        ]);

        return [
            'ok' => true,
            'reason' => 'ok',
            'source' => 'bindings',
            'user_id' => $uid,
            'restaurant_id' => $rid,
            'role' => $roleNorm,
            'restaurant' => $restaurant,
        ];
    }
}

function require_staff_restaurant_access(?int $restaurantId = null, array $allowedRoles = []): string
{
    require_login();
    if (function_exists('ensure_staff_station_schema')) {
        ensure_staff_station_schema();
    }
    $ctx = resolve_staff_restaurant_context($restaurantId, $allowedRoles);
    $rid = (int)($ctx['restaurant_id'] ?? 0);
    $role = is_string($ctx['role'] ?? null) ? normalize_restaurant_role((string)$ctx['role']) : null;

    if (empty($ctx['ok']) || $role === null || $rid <= 0) {
        error_log('STAFF_CONTEXT_DENY reason=' . (string)($ctx['reason'] ?? 'unknown') . ' rid=' . app_rid() . ' user_id=' . (int)($ctx['user_id'] ?? 0));
        staff_context_debug_log('access_guard_deny', [
            'reason' => (string)($ctx['reason'] ?? 'unknown'),
            'user_id' => (int)($ctx['user_id'] ?? 0),
            'restaurant_id' => $rid,
            'role' => $role,
            'allowed_roles' => $allowedRoles,
            'requested_station' => (string)($_GET['station'] ?? ''),
        ]);
        if (function_exists('auth_deny')) {
            auth_deny((string)($ctx['reason'] ?? 'access_denied'), 403);
        }
        http_response_code(403);
        echo 'Access denied';
        exit;
    }

    $knownRoles = restaurant_staff_known_roles();
    if (!in_array($role, $knownRoles, true)) {
        error_log('STAFF_CONTEXT_DENY reason=invalid_role rid=' . app_rid() . ' user_id=' . (int)($ctx['user_id'] ?? 0) . ' role=' . $role);
        staff_context_debug_log('access_guard_deny', [
            'reason' => 'invalid_role',
            'user_id' => (int)($ctx['user_id'] ?? 0),
            'restaurant_id' => $rid,
            'role' => $role,
            'known_roles' => $knownRoles,
            'allowed_roles' => $allowedRoles,
            'requested_station' => (string)($_GET['station'] ?? ''),
        ]);
        if (function_exists('auth_deny')) {
            auth_deny('invalid_role', 403);
        }
        http_response_code(403);
        echo 'Access denied';
        exit;
    }

    return $role;
}

function require_staff_role(array $roles, ?int $restaurantId = null): string
{
    $rolesNorm = [];
    foreach ($roles as $roleRaw) {
        $roleNorm = normalize_restaurant_role($roleRaw);
        if ($roleNorm !== '') {
            $rolesNorm[] = $roleNorm;
        }
    }
    $rolesNorm = array_values(array_unique($rolesNorm));
    $role = require_staff_restaurant_access($restaurantId, $rolesNorm);
    if (!in_array($role, $rolesNorm, true)) {
        if (function_exists('auth_deny')) {
            auth_deny('access_denied', 403);
        }
        http_response_code(403);
        echo 'Access denied';
        exit;
    }
    return $role;
}

function require_waiter_access(?int $restaurantId = null): string
{
    return require_staff_role(['owner', 'admin', 'waiter', 'staff'], $restaurantId);
}

function require_kitchen_access(?int $restaurantId = null): string
{
    return require_staff_role(array_merge(['owner', 'admin'], restaurant_station_roles()), $restaurantId);
}

function require_courier_access(?int $restaurantId = null): string
{
    require_staff_login();
    global $currentRestaurant;
    $rid = $restaurantId ?? (int)($currentRestaurant['id'] ?? 0);
    $role = current_user_restaurant_role($rid);
    if (!in_array((string)$role, ['owner', 'admin', 'staff', 'courier'], true)) {
        if (function_exists('auth_deny')) {
            auth_deny('access_denied', 403);
        }
        http_response_code(403);
        echo 'Access denied';
        exit;
    }
    return (string)$role;
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

    // Platform owner roles have access everywhere in project-admin flows.
    if (function_exists('is_project_owner') && is_project_owner()) {
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
        if (function_exists('auth_deny')) {
            auth_deny('access_denied', 403);
        }
        http_response_code(403);
        echo 'Access denied';
        exit;
    }
}
