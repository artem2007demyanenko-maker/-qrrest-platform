<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
require_kitchen_access();
$restaurantId = (int)($currentRestaurant['id'] ?? 0);
$staffRole = function_exists('current_user_restaurant_role')
    ? normalize_restaurant_role((string)(current_user_restaurant_role($restaurantId) ?? ''))
    : '';
if (!(function_exists('can_access_station') ? can_access_station('bar', $restaurantId, $staffRole) : user_has_station_access($staffRole, 'bar'))) {
    http_response_code(403);
    echo 'Access denied';
    exit;
}

$_GET['station'] = 'bar';
$_GET['bar_panel'] = '1';
if (!isset($_GET['status']) || trim((string)$_GET['status']) === '') {
    $_GET['status'] = 'pending';
}

require __DIR__ . '/kitchen.php';
