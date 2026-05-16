<?php
/**
 * Desktop sidebar for restaurant panel. Set before include:
 *   $restaurantSidebarActive (string), $restaurantSidebarName (string, optional).
 */
$restaurantSidebarActive = isset($restaurantSidebarActive) ? (string)$restaurantSidebarActive : '';
$restaurantSidebarName = isset($restaurantSidebarName) ? (string)$restaurantSidebarName : (string)($currentRestaurant['name'] ?? 'Ресторан');
?>
<aside class="w-64 bg-slate-950/85 border-r border-slate-800 p-4 hidden md:block flex-shrink-0<?= !empty($restaurantSidebarNoPrint) ? ' no-print' : '' ?>">
    <?= brand_restaurant_sidebar_header_html($restaurantSidebarName) ?>
    <?php require __DIR__ . '/_sidebar_nav.php'; ?>
</aside>
