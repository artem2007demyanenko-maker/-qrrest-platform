<?php
require_once __DIR__ . '/../../app/bootstrap.php';
guest_logout();
header('Location: /guest/login.php');
exit;
