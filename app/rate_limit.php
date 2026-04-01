<?php

/**
 * Simple wrapper for existing security_rate_limit(), to match Safe Upgrade Pack API.
 *
 * Uses Redis / DB / session fallback according to app/security.php implementation.
 * This file does not change global rate limiting behaviour, it only exposes a
 * convenient function signature.
 */

require_once __DIR__ . '/security.php';

if (!function_exists('rate_limit')) {
    /**
     * @param string $key
     * @param int    $max_attempts
     * @param int    $window_seconds
     * @return bool
     */
    function rate_limit(string $key, int $max_attempts, int $window_seconds): bool
    {
        return security_rate_limit($key, $max_attempts, $window_seconds);
    }
}

