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
 *   OTP_TEST_MODE (0|1|false|true): when enabled, OTP is not sent via SMS integration; code is logged
 *     and may be returned in JSON from send_otp.php for local/dev testing only. Never enable in production.
 *   OTP_TEST_PHONE: optional test phone (or comma-separated list of phones) that may use a fixed OTP in non-production.
 *   OTP_TEST_CODE: optional fixed OTP code for OTP_TEST_PHONE in non-production.
 */

$env = function (string $key, string $default = ''): string {
    $v = getenv($key);
    return $v !== false && $v !== '' ? (string)$v : $default;
};

$normalizeHost = function (string $value): string {
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    $first = trim(explode(',', $value)[0] ?? '');
    $first = preg_replace('/:\d+$/', '', $first);
    $first = trim((string)$first, " \t\n\r\0\x0B.");
    $first = strtolower((string)$first);
    if ($first === '' || preg_match('/[^a-z0-9\.\-]/', $first)) {
        return '';
    }
    return $first;
};

$isPrivateProxyAddr = function (string $ip): bool {
    $ip = trim($ip);
    if ($ip === '') {
        return false;
    }
    if ($ip === '127.0.0.1' || $ip === '::1') {
        return true;
    }
    if (str_starts_with($ip, '10.') || str_starts_with($ip, '192.168.')) {
        return true;
    }
    return (bool)preg_match('/^172\.(1[6-9]|2\d|3[0-1])\./', $ip);
};

$appEnv   = $env('APP_ENV', 'local');
$requestHost = '';
$remoteAddr = (string)($_SERVER['REMOTE_ADDR'] ?? '');
if ($isPrivateProxyAddr($remoteAddr)) {
    foreach (['HTTP_X_FORWARDED_HOST', 'HTTP_X_ORIGINAL_HOST', 'HTTP_X_FORWARDED_SERVER'] as $key) {
        $host = $normalizeHost((string)($_SERVER[$key] ?? ''));
        if ($host !== '') {
            $requestHost = $host;
            break;
        }
    }
}
if ($requestHost === '') {
    foreach (['HTTP_HOST', 'SERVER_NAME'] as $key) {
        $host = $normalizeHost((string)($_SERVER[$key] ?? ''));
        if ($host !== '') {
            $requestHost = $host;
            break;
        }
    }
}
$httpsDetected =
    (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
    || (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
    || ((string)($_SERVER['REQUEST_SCHEME'] ?? '') === 'https')
    || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);
$protocol = $env('APP_PROTOCOL', $httpsDetected ? 'https' : 'http');
$mainDomain = $env('APP_MAIN_DOMAIN', 'qrrest-menu.ru');
$cookieDomain = $env('APP_COOKIE_DOMAIN', '.' . $mainDomain);
if ($cookieDomain !== '' && $cookieDomain[0] !== '.' && strpos($cookieDomain, '.') !== false) {
    $cookieDomain = '.' . $cookieDomain;
} elseif ($cookieDomain === '' && $mainDomain !== '') {
    $cookieDomain = '.' . $mainDomain;
}
$defaultAppHost = $requestHost !== '' ? $requestHost : $mainDomain;
$appUrl   = $env('APP_URL', $protocol . '://' . $defaultAppHost);
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

$otpTestModeRaw = strtolower(trim($env('OTP_TEST_MODE', '0')));
$otpTestMode = in_array($otpTestModeRaw, ['1', 'true', 'yes', 'on'], true);
$otpTestPhone = trim($env('OTP_TEST_PHONE', ''));
$otpTestCode = trim($env('OTP_TEST_CODE', ''));

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
        'otp_test_mode'           => $otpTestMode,
        'otp_test_phone'          => $otpTestPhone,
        'otp_test_code'           => $otpTestCode,
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
