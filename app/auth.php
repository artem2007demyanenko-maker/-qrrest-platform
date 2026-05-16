<?php
// app/auth.php

require_once __DIR__ . '/db.php';

function auth_start_session(): void
{
    $config = require __DIR__ . '/config.php';

    if (session_status() === PHP_SESSION_NONE) {
        session_name($config['security']['session_name']);
        $cookieDomain = (string)($config['app']['cookie_domain'] ?? '');
        $isSecure = (strtolower((string)($config['app']['protocol'] ?? 'http')) === 'https');
        $appEnv = $config['app']['env'] ?? 'local';
        $host = strtolower((string)preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? '')));
        if ($cookieDomain !== '') {
            $domainCheck = ltrim(strtolower($cookieDomain), '.');
            $hostMatches = $host !== '' && ($host === $domainCheck || str_ends_with($host, '.' . $domainCheck));
            if (!$hostMatches && $appEnv !== 'production') {
                $derived = '';
                if ($host !== '' && filter_var($host, FILTER_VALIDATE_IP) === false && $host !== 'localhost') {
                    $parts = array_values(array_filter(explode('.', $host), static fn($part) => $part !== ''));
                    if (count($parts) >= 2) {
                        $derived = '.' . implode('.', array_slice($parts, -2));
                    }
                }
                $cookieDomain = $derived;
            }
        }
        if ($appEnv === 'production') {
            $isSecure = true;
        }
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => $cookieDomain,
            'secure'   => $isSecure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

function auth_login(string $email, string $password): bool
{
    $pdo = db();
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = :email LIMIT 1");
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch();

    if (!$user) {
        return false;
    }

    if (!password_verify($password, $user['password_hash'])) {
        return false;
    }

    if (array_key_exists('is_active', (array)$user) && (int)($user['is_active'] ?? 1) !== 1) {
        return false;
    }

    auth_start_session();
    if (session_status() === PHP_SESSION_ACTIVE) {
        @session_regenerate_id(true);
    }
    $_SESSION['user_id'] = $user['id'];
    // Drop stale per-user/per-restaurant context after fresh login.
    unset(
        $_SESSION['current_restaurant_id'],
        $_SESSION['return_to'],
        $_SESSION['checkout_idem'],
        $_SESSION['pos_order_idem']
    );

    return true;
}

function auth_logout(): void
{
    auth_start_session();
    $_SESSION = [];
    session_destroy();
}

if (!function_exists('is_api_request')) {
    function is_api_request(): bool
    {
        $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
        $path = (string)(parse_url($uri, PHP_URL_PATH) ?: '');
        $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
        $contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
        $requestedWith = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));

        if (str_contains($accept, 'application/json') || str_contains($contentType, 'application/json')) {
            return true;
        }
        if ($requestedWith === 'xmlhttprequest') {
            return true;
        }
        if (str_contains($path, '/ajax/')) {
            return true;
        }
        if (preg_match('~/(?:staff|restaurant)/[^/]*_api\.php$~i', $path) === 1) {
            return true;
        }
        if (preg_match('~/(?:staff)/(?:kitchen_item_update|order_update_status|waiter_call_resolve|pos_create_order)\.php$~i', $path) === 1) {
            return true;
        }
        if (preg_match('~/(?:restaurant)/(?:copilot_action|copilot_answer)\.php$~i', $path) === 1) {
            return true;
        }
        if (preg_match('~/(?:staff|ajax)/(?:kds_|order_|floorplan_|waiter_|courier_|analytics_)~i', $path) === 1) {
            return true;
        }

        return false;
    }
}

if (!function_exists('auth_request_expects_json')) {
    function auth_request_expects_json(): bool
    {
        return function_exists('is_api_request') && is_api_request();
    }
}

if (!function_exists('auth_json_error')) {
    function auth_json_error(string $message, int $status = 403, array $extra = []): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        }

        echo json_encode(array_merge([
            'success' => false,
            'message' => $message,
        ], $extra), JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if (!function_exists('auth_deny')) {
    function auth_deny(string $message = 'access_denied', int $status = 403): void
    {
        if (function_exists('is_api_request') && is_api_request()) {
            auth_json_error($message, $status);
        }

        http_response_code($status);
        echo 'Access denied';
        exit;
    }
}

function auth_user(): ?array
{
    auth_start_session();
    if (empty($_SESSION['user_id'])) {
        return null;
    }

    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $pdo = db();
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $_SESSION['user_id']]);
    $user = $stmt->fetch();

    if (!$user) {
        return null;
    }

    if (array_key_exists('is_active', (array)$user) && (int)($user['is_active'] ?? 1) !== 1) {
        $_SESSION = [];
        session_destroy();
        return null;
    }

    $cache = $user;
    return $user;
}

function require_login(): void
{
    if (function_exists('is_demo_mode') && is_demo_mode()) {
        return;
    }
    if (!auth_user()) {
        if (function_exists('is_api_request') && is_api_request()) {
            auth_json_error('auth_required', 401);
        }
        $currentUrl = $_SERVER['REQUEST_URI'] ?? '/';
        $redirect   = '/login.php?redirect=' . urlencode($currentUrl);
        header("Location: " . $redirect);
        exit;
    }
}


function is_project_owner(): bool
{
    $user = auth_user();
    if (!$user) {
        return false;
    }
    $role = strtolower(trim((string)($user['global_role'] ?? '')));
    return in_array($role, ['owner', 'project_owner', 'platform_owner', 'super_admin'], true);
}

/** Platform admin (project owner) — for network analytics and admin-only features. */
function is_project_admin(): bool
{
    return is_project_owner();
}
