<?php
// app/bootstrap.php

if (file_exists(__DIR__ . '/../maintenance.flag')) {
    require __DIR__ . '/../public_html/maintenance.php';
    exit;
}

// Подтягиваем конфиг как можно раньше (для cookie_domain / main_domain / protocol)
$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/brand.php';

// Per-request correlation id (RID) — generated once per request.
if (!defined('APP_REQUEST_ID')) {
    try {
        $rid = bin2hex(random_bytes(8));
    } catch (Throwable $e) {
        $rid = bin2hex(random_bytes(8));
    }
    define('APP_REQUEST_ID', $rid);
}
if (!function_exists('app_rid')) {
    function app_rid(): string
    {
        return defined('APP_REQUEST_ID') ? APP_REQUEST_ID : '';
    }
}

// Production env guard: fail fast on unsafe APP_ENV=production configuration.
require_once __DIR__ . '/env_guard.php';
env_guard_check($config);

// Security headers (Variant 7: stricter)
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.tailwindcss.com https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://cdn.tailwindcss.com; img-src 'self' data:;");
if (!empty($config['app']['protocol']) && strtolower($config['app']['protocol']) === 'https'
    && !empty($config['app']['hsts'])) {
    $hstsParts = ['max-age=31536000'];
    if (!empty($config['app']['hsts_include_subdomains'])) {
        $hstsParts[] = 'includeSubDomains';
    }
    if (!empty($config['app']['hsts_preload'])) {
        $hstsParts[] = 'preload';
    }
    header('Strict-Transport-Security: ' . implode('; ', $hstsParts));
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/error_handler.php';
require_once __DIR__ . '/subdomain.php';
require_once __DIR__ . '/demo.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/qr_helpers.php';
require_once __DIR__ . '/upload_helpers.php';
require_once __DIR__ . '/logging.php';
require_once __DIR__ . '/themes.php';
require_once __DIR__ . '/loyalty.php';
require_once __DIR__ . '/guest_auth.php';
require_once __DIR__ . '/guest_loyalty.php';

set_exception_handler('stability_exception_handler');
set_error_handler('stability_error_handler');
auth_start_session();


if (function_exists('get_current_restaurant_or_null')) {

    $currentRestaurant = get_current_restaurant_or_null();
} else {


    $host = $_SERVER['HTTP_HOST'] ?? '';
    $host = preg_replace('/:\d+$/', '', $host);

    $mainDomain = $config['app']['main_domain'] ?? '';

    $isMainDomain = ($mainDomain && strtolower($host) === strtolower($mainDomain));
    $isWwwMain   = ($mainDomain && strtolower($host) === strtolower('www.' . $mainDomain));

    if ($isMainDomain || $isWwwMain) {
        $currentRestaurant = null;
    } else {
        $currentRestaurant = get_current_restaurant_or_404();
    }
}
