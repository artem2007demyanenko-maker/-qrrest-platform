<?php
/**
 * App config. Env overrides file defaults.
 * When APP_MAIN_DOMAIN / APP_COOKIE_DOMAIN are unset, production defaults apply (qrrest-menu.ru).
 * For local dev (e.g. lvh.me), set APP_MAIN_DOMAIN (and optionally APP_COOKIE_DOMAIN) in env.
 * Supported env: APP_ENV, APP_URL, APP_PROTOCOL, APP_MAIN_DOMAIN, APP_COOKIE_DOMAIN, APP_DEBUG, APP_HSTS,
 *   APP_HSTS_INCLUDE_SUBDOMAINS (0|1, default 0 — set 1 only after wildcard TLS covers *.MAIN_DOMAIN),
 *   APP_HSTS_PRELOAD (0|1, default 0 — enable only when ready for browser preload lists),
 *   DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS,
 *   REDIS_HOST, REDIS_PORT,
 *   APP_VERSION, GIT_SHA (optional, for health).
 *   GUEST_CARD_HMAC_KEY (required in production for loyalty card token signing; min 32 chars).
 */

$env = function (string $key, string $default = ''): string {
    $v = getenv($key);
    return $v !== false && $v !== '' ? (string)$v : $default;
};

$appEnv   = $env('APP_ENV', 'local');
$protocol = $env('APP_PROTOCOL', '');
if ($protocol === '' && isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
    $protocol = 'https';
}
if ($protocol === '') {
    $protocol = 'http';
}
$mainDomain = $env('APP_MAIN_DOMAIN', 'qrrest-menu.ru');
$cookieDomain = $env('APP_COOKIE_DOMAIN', '.' . $mainDomain);
if ($cookieDomain !== '' && $cookieDomain[0] !== '.' && strpos($cookieDomain, '.') !== false) {
    $cookieDomain = '.' . $cookieDomain;
} elseif ($cookieDomain === '' && $mainDomain !== '') {
    $cookieDomain = '.' . $mainDomain;
}
$appUrl   = $env('APP_URL', $protocol . '://' . $mainDomain);
$appDebug = (int)$env('APP_DEBUG', '0');
// HSTS header in bootstrap: 1 = send when HTTPS; 0 = disable (useful during first TLS rollout).
$hstsEnabled = (int)$env('APP_HSTS', '1') === 1;
// includeSubDomains forces HTTPS on all subdomains — unsafe until wildcard cert works everywhere.
$hstsIncludeSubdomains = (int)$env('APP_HSTS_INCLUDE_SUBDOMAINS', '0') === 1;
// preload is irreversible for users — opt-in only.
$hstsPreload = (int)$env('APP_HSTS_PRELOAD', '0') === 1;

// Docker-aware defaults: in containers use service name "db",
// for local non-docker runs keep localhost-style fallback.
$isDockerRuntime = file_exists('/.dockerenv') || $env('APP_RUNTIME', '') === 'docker';
$defaultDbHost = $isDockerRuntime ? 'db' : '127.0.0.1';

$dbHost = $env('DB_HOST', $defaultDbHost);
$dbPort = $env('DB_PORT', '3306');
$dbName = $env('DB_NAME', 'qr_rest');
$dbUser = $env('DB_USER', 'qr');
$dbPass = $env('DB_PASS', 'qrpass');

$redisHost = $env('REDIS_HOST', '');
$redisPort = $env('REDIS_PORT', '6379');

$stripeEnabled = (int)$env('STRIPE_ENABLED', '0') === 1;
$stripeSecret = $env('STRIPE_SECRET_KEY', '');
$stripePublishable = $env('STRIPE_PUBLISHABLE_KEY', '');
$stripeWebhookSecret = $env('STRIPE_WEBHOOK_SECRET', '');
$stripePriceBasic = $env('STRIPE_PRICE_ID_BASIC', '');
$stripePricePro = $env('STRIPE_PRICE_ID_PRO', '');
$stripeSuccessUrl = $env('STRIPE_SUCCESS_URL', '');
$stripeCancelUrl = $env('STRIPE_CANCEL_URL', '');

return [
    'db' => [
        'host'    => $dbHost,
        'port'    => $dbPort,
        'name'    => $dbName,
        'user'    => $dbUser,
        'pass'    => $dbPass,
        'charset' => 'utf8mb4',
    ],
    'app' => [
        'env'                 => $appEnv,
        'main_domain'         => $mainDomain,
        'protocol'            => $protocol,
        'cookie_domain'       => $cookieDomain,
        'url'                 => rtrim($appUrl, '/'),
        'debug'               => $appDebug,
        'guest_card_hmac_key' => $env('GUEST_CARD_HMAC_KEY', ''),
        'hsts'                    => $hstsEnabled,
        'hsts_include_subdomains' => $hstsIncludeSubdomains,
        'hsts_preload'            => $hstsPreload,
    ],
    'security' => [
        'session_name' => 'qr_restaurant_session',
    ],
    'redis' => [
        'host' => $redisHost,
        'port' => (int)$redisPort,
    ],
    'version' => [
        'app_version' => $env('APP_VERSION', ''),
        'git_sha'     => $env('GIT_SHA', ''),
    ],
    'stripe' => [
        'enabled'         => $stripeEnabled && $stripeSecret !== '',
        'secret_key'      => $stripeSecret,
        'publishable_key' => $stripePublishable,
        'webhook_secret'  => $stripeWebhookSecret,
        'price_ids'       => [
            'starter' => $stripePriceBasic,
            'basic'   => $stripePriceBasic,
            'pro'     => $stripePricePro,
        ],
        'success_url'     => $stripeSuccessUrl,
        'cancel_url'      => $stripeCancelUrl,
    ],
];
