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

    auth_start_session();
    $_SESSION['user_id'] = $user['id'];

    return true;
}

function auth_logout(): void
{
    auth_start_session();
    $_SESSION = [];
    session_destroy();
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

    $cache = $user;
    return $user;
}

function require_login(): void
{
    if (function_exists('is_demo_mode') && is_demo_mode()) {
        return;
    }
    if (!auth_user()) {
        $currentUrl = $_SERVER['REQUEST_URI'] ?? '/';
        $redirect   = '/login.php?redirect=' . urlencode($currentUrl);
        header("Location: " . $redirect);
        exit;
    }
}


function is_project_owner(): bool
{
    $user = auth_user();
    return $user && $user['global_role'] === 'project_owner';
}

/** Platform admin (project owner) — for network analytics and admin-only features. */
function is_project_admin(): bool
{
    return is_project_owner();
}
