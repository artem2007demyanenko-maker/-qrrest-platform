<?php
/**
 * Product branding helpers around the canonical QRRest PNG logo.
 */

if (!defined('BRAND_NAME')) {
    define('BRAND_NAME', 'QR Rest');
}
if (!defined('BRAND_NAME_FULL')) {
    define('BRAND_NAME_FULL', 'QR Rest Menu');
}
if (!defined('BRAND_TAGLINE')) {
    define('BRAND_TAGLINE', 'Меню · Заказы · Гости');
}

if (!function_exists('brand_config')) {
    function brand_config(): array
    {
        static $cfg = null;
        if (is_array($cfg)) {
            return $cfg;
        }
        $path = __DIR__ . '/config.php';
        $cfg = is_file($path) ? require $path : [];
        return $cfg;
    }
}

if (!function_exists('brand_header_host_value')) {
    function brand_header_host_value(string $value): string
    {
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
    }
}

if (!function_exists('brand_private_proxy_addr')) {
    function brand_private_proxy_addr(?string $ip): bool
    {
        $ip = trim((string)$ip);
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
    }
}

if (!function_exists('brand_request_host')) {
    function brand_request_host(): string
    {
        $candidates = [];
        $remoteAddr = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        if (brand_private_proxy_addr($remoteAddr)) {
            foreach (['HTTP_X_FORWARDED_HOST', 'HTTP_X_ORIGINAL_HOST', 'HTTP_X_FORWARDED_SERVER'] as $key) {
                $host = brand_header_host_value((string)($_SERVER[$key] ?? ''));
                if ($host !== '') {
                    $candidates[] = $host;
                }
            }
        }
        foreach (['HTTP_HOST', 'SERVER_NAME'] as $key) {
            $host = brand_header_host_value((string)($_SERVER[$key] ?? ''));
            if ($host !== '') {
                $candidates[] = $host;
            }
        }
        foreach ($candidates as $candidate) {
            if ($candidate !== '') {
                return $candidate;
            }
        }
        return '';
    }
}

if (!function_exists('brand_request_scheme')) {
    function brand_request_scheme(?array $cfg = null): string
    {
        if ((isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
            || (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
            || ((string)($_SERVER['REQUEST_SCHEME'] ?? '') === 'https')
            || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443)) {
            return 'https';
        }
        $cfg = $cfg ?? brand_config();
        return strtolower((string)($cfg['app']['protocol'] ?? 'https')) === 'http' ? 'http' : 'https';
    }
}

if (!function_exists('brand_is_test_host')) {
    function brand_is_test_host(?array $cfg = null): bool
    {
        $cfg = $cfg ?? brand_config();
        $mainDomain = strtolower((string)($cfg['app']['main_domain'] ?? ''));
        if ($mainDomain === '') {
            return false;
        }
        return brand_request_host() === ('test.' . $mainDomain);
    }
}

if (!function_exists('brand_current_origin')) {
    function brand_current_origin(?array $cfg = null): string
    {
        $cfg = $cfg ?? brand_config();
        $host = brand_request_host();
        if ($host === '') {
            $fallback = trim((string)($cfg['app']['url'] ?? ''));
            return rtrim($fallback, '/');
        }
        return brand_request_scheme($cfg) . '://' . $host;
    }
}

if (!function_exists('brand_public_html_path')) {
    function brand_public_html_path(): string
    {
        return dirname(__DIR__) . '/public_html';
    }
}

if (!function_exists('brand_logo_path_for_schema')) {
    function brand_logo_path_for_schema(): string
    {
        return '/assets/img/logo-qrrest.png';
    }
}

if (!function_exists('brand_head_meta_tags')) {
    function brand_head_meta_tags(?array $cfg = null): string
    {
        $cfg = $cfg ?? brand_config();
        if (!brand_is_test_host($cfg)) {
            return '';
        }
        $origin = brand_current_origin($cfg);
        $requestUri = (string)($_SERVER['REQUEST_URI'] ?? '/');
        if ($requestUri === '') {
            $requestUri = '/';
        }
        $canonical = htmlspecialchars($origin . $requestUri, ENT_QUOTES, 'UTF-8');
        return '<meta name="robots" content="noindex,nofollow,noarchive">' . "\n"
            . '<link rel="canonical" href="' . $canonical . '">';
    }
}

if (!function_exists('brand_head_tags')) {
    function brand_head_tags(): string
    {
        $meta = brand_head_meta_tags();
        $parts = [
            '<link rel="icon" href="/assets/img/logo-qrrest.png">',
            '<link rel="icon" type="image/png" sizes="32x32" href="/assets/img/logo-qrrest.png">',
            '<link rel="icon" type="image/png" sizes="16x16" href="/assets/img/logo-qrrest.png">',
            '<link rel="apple-touch-icon" href="/assets/img/logo-qrrest.png">',
        ];
        $parts[] = '<meta name="theme-color" content="#0f172a">';
        $parts[] = '<link rel="stylesheet" href="/assets/css/brand.css">';
        $parts[] = '<script defer src="/assets/js/motion.js"></script>';

        return $meta . implode("\n", $parts);
    }
}

if (!function_exists('brand_logo_svg_inline')) {
    function brand_logo_svg_inline(string $class = 'w-8 h-8'): string
    {
        $c = htmlspecialchars($class, ENT_QUOTES, 'UTF-8');
        return '<span class="brand-mark inline-flex shrink-0 ' . $c . '" aria-hidden="true">'
            . '<img class="w-full h-full object-contain" src="/assets/img/logo-qrrest.png" alt="QRRest" width="120" height="40">'
            . '</span>';
    }
}

if (!function_exists('brand_wordmark_html')) {
    function brand_wordmark_html(string $nameClass = 'text-slate-100', string $subClass = 'text-slate-500'): string
    {
        $n = htmlspecialchars(BRAND_NAME, ENT_QUOTES, 'UTF-8');
        $t = htmlspecialchars(BRAND_TAGLINE, ENT_QUOTES, 'UTF-8');
        $nc = htmlspecialchars($nameClass, ENT_QUOTES, 'UTF-8');
        $sc = htmlspecialchars($subClass, ENT_QUOTES, 'UTF-8');

        return '<span class="brand-wordmark flex flex-col leading-tight">'
            . '<span class="font-semibold tracking-tight ' . $nc . '">' . $n . '</span>'
            . '<span class="text-[10px] uppercase tracking-[0.14em] ' . $sc . '">' . $t . '</span>'
            . '</span>';
    }
}

if (!function_exists('brand_header_cluster_html')) {
    function brand_header_cluster_html(bool $linkHome = true, string $markClass = 'w-9 h-9 text-slate-400'): string
    {
        $inner = brand_logo_svg_inline($markClass) . '<div class="ml-2.5">' . brand_wordmark_html('text-slate-100', 'text-slate-500') . '</div>';
        if ($linkHome) {
            return '<a href="/" class="brand-cluster inline-flex items-center no-underline hover:opacity-95 transition-opacity focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500/50 rounded-lg">' . $inner . '</a>';
        }

        return '<div class="brand-cluster inline-flex items-center">' . $inner . '</div>';
    }
}

if (!function_exists('brand_restaurant_sidebar_header_html')) {
    function brand_restaurant_sidebar_header_html(string $restaurantName): string
    {
        $name = htmlspecialchars($restaurantName, ENT_QUOTES, 'UTF-8');
        $bn = htmlspecialchars(BRAND_NAME, ENT_QUOTES, 'UTF-8');

        return '<div class="brand-sidebar-head mb-5 space-y-3">'
            . '<div class="flex items-center gap-2 opacity-90">'
            . brand_logo_svg_inline('w-7 h-7 text-slate-400')
            . '<span class="text-[11px] font-semibold tracking-wide text-slate-400 uppercase">' . $bn . '</span>'
            . '</div>'
            . '<h1 class="text-base font-semibold tracking-tight text-slate-100 leading-snug break-words">' . $name . '</h1>'
            . '</div>';
    }
}

if (!function_exists('brand_platform_admin_row_html')) {
    function brand_platform_admin_row_html(): string
    {
        $bn = htmlspecialchars(BRAND_NAME, ENT_QUOTES, 'UTF-8');

        return '<div class="brand-platform-admin flex items-center gap-2.5 mb-3">'
            . brand_logo_svg_inline('w-7 h-7 text-slate-400')
            . '<span class="text-sm font-semibold tracking-tight text-slate-100">' . $bn . '</span>'
            . '</div>';
    }
}
