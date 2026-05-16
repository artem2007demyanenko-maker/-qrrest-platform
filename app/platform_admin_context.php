<?php
declare(strict_types=1);

function platform_admin_main_domain_host(): string
{
    static $host = null;
    if ($host !== null) {
        return $host;
    }
    $config = require __DIR__ . '/config.php';
    $host   = preg_replace('/:\d+$/', '', $config['app']['main_domain'] ?? 'localhost');
    return $host;
}

/**
 * Absolute URL of platform admin home on the main domain (for links from tenant subdomains).
 */
function platform_admin_home_url(): string
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $config   = require __DIR__ . '/config.php';
    $protocol = $config['app']['protocol'] ?? 'https';
    $main     = platform_admin_main_domain_host();
    $cached   = rtrim($protocol . '://' . $main, '/') . '/project-admin/';
    return $cached;
}
