<?php
require_once __DIR__ . '/../../app/bootstrap.php';

$redirect = function_exists('guest_auth_safe_redirect_path')
    ? guest_auth_safe_redirect_path((string)($_GET['redirect'] ?? '/guest/wallet.php'))
    : '/guest/wallet.php';

$target = '/guest/login.php?mode=register&redirect=' . urlencode($redirect);
header('Location: ' . $target, true, $_SERVER['REQUEST_METHOD'] === 'POST' ? 303 : 302);
exit;
