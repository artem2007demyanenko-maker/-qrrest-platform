<?php
// public/logout.php
require_once __DIR__ . '/../app/bootstrap.php';

auth_logout();

// После выхода отправим на страницу логина
header('Location: /login.php');
exit;