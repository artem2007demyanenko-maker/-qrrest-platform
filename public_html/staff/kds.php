<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
require_kitchen_access();

$target = '/staff/kitchen.php';
$role = function_exists('current_user_restaurant_role')
    ? normalize_restaurant_role((string)(current_user_restaurant_role((int)($currentRestaurant['id'] ?? 0)) ?? ''))
    : '';
$staffStation = function_exists('current_staff_station')
    ? (string)current_staff_station((int)($currentRestaurant['id'] ?? 0))
    : ($role === 'bar' ? 'bar' : 'hot');
$stationKds = function_exists('station_to_kds_key')
    ? station_to_kds_key($staffStation)
    : ($role === 'bar' ? 'bar' : 'kitchen');
$isStationRole = function_exists('restaurant_role_is_station_role')
    ? restaurant_role_is_station_role($role)
    : in_array($role, ['bar', 'kitchen'], true);
if ($role === 'bar' && !isset($_GET['station'])) {
    $target = '/staff/bar.php';
} elseif ($isStationRole && !isset($_GET['station'])) {
    $target = '/staff/kitchen.php?station=' . rawurlencode($stationKds);
}
$query = $_SERVER['QUERY_STRING'] ?? '';
if ($query !== '') {
    $target .= (str_contains($target, '?') ? '&' : '?') . $query;
}

header('Location: ' . $target, true, 302);
exit;
