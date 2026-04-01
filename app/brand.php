<?php
/**
 * Product branding: favicon links, wordmark, inline mark (no heavy JS).
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

if (!function_exists('brand_head_tags')) {
    /**
     * Echo inside <head> after charset / viewport.
     */
    function brand_head_tags(): string
    {
        return <<<HTML
<link rel="icon" type="image/svg+xml" href="/assets/brand/favicon.svg">
<link rel="icon" type="image/png" sizes="32x32" href="/favicon.png">
<link rel="icon" href="/favicon.ico" sizes="32x32">
<link rel="apple-touch-icon" sizes="180x180" href="/assets/brand/apple-touch-icon.png">
<meta name="theme-color" content="#0f172a">
<link rel="stylesheet" href="/assets/css/brand.css">
HTML;
    }
}

if (!function_exists('brand_logo_svg_inline')) {
    /**
     * QR + plate + fork mark (32×32 viewBox).
     */
    function brand_logo_svg_inline(string $class = 'w-8 h-8'): string
    {
        $c = htmlspecialchars($class, ENT_QUOTES, 'UTF-8');

        return <<<SVG
<span class="brand-mark inline-flex shrink-0 {$c}" aria-hidden="true">
<svg class="w-full h-full" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
  <defs>
    <linearGradient id="br-bg" x1="0" y1="0" x2="32" y2="32">
      <stop offset="0%" stop-color="#0f172a"/>
      <stop offset="100%" stop-color="#1e293b"/>
    </linearGradient>
  </defs>
  <rect width="32" height="32" rx="8" fill="url(#br-bg)"/>
  <rect x="1.5" y="1.5" width="29" height="29" rx="6.5" stroke="#475569" stroke-width="1" fill="none"/>
  <!-- QR finders -->
  <path fill="#34d399" d="M7 7h8v8H7V7zm2 2v4h4V9H9zM17 7h6v6h-6V7zm1.5 1.5v1h1v-1h-1zm2 0v1h1v-1h-1zM7 17h6v6H7v-6zm1.5 1.5v1h1v-1h-1zm2 0v1h1v-1h-1z"/>
  <path fill="#64748b" d="M17 17h2v2h-2v-2zm3 0h2v2h-2v-2zm0 2.5h2v2h-2v-2zm3-2.5h2v2h-2v-2zm0 2.5h2v2h-2v-2zM17 22h2v2h-2v-2zm3 0h2v2h-2v-2z"/>
  <!-- plate -->
  <ellipse cx="16" cy="26.5" rx="9" ry="2.2" fill="none" stroke="#94a3b8" stroke-width="1.2"/>
  <ellipse cx="16" cy="26" rx="7" ry="1.5" fill="#334155" opacity="0.85"/>
  <!-- fork -->
  <path stroke="#cbd5e1" stroke-width="1.2" stroke-linecap="round" d="M22 18v6M21 18v6M23 18v6" opacity="0.95"/>
  <path stroke="#cbd5e1" stroke-width="1" stroke-linecap="round" d="M22 18.5h0" opacity="0.9"/>
</svg>
</span>
SVG;
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
