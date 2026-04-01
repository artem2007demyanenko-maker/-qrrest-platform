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

function get_current_restaurant_or_404(): ?array
{
    $slug = get_subdomain_slug();

    if ($slug === null) {
        return null;
    }

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
        http_response_code(404);
        echo "Restaurant not found";
        exit;
    }

    // Single source of truth: loyalty enabled/percent from restaurant_loyalty_settings
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
