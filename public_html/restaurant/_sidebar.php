<?php
if (!function_exists('restaurant_sidebar_link_class')) {
    function restaurant_sidebar_link_class(string $current, string $target): string
    {
        return $current === $target
            ? 'block px-3 py-2 rounded-xl bg-slate-800/70 text-slate-100'
            : 'block px-3 py-2 rounded-xl hover:bg-slate-800/60 text-slate-300';
    }
}

$restaurantSidebarActive = isset($restaurantSidebarActive) ? (string)$restaurantSidebarActive : '';
$restaurantSidebarName = isset($restaurantSidebarName) ? (string)$restaurantSidebarName : (string)($currentRestaurant['name'] ?? 'Ресторан');
?>
<aside class="w-64 bg-slate-950/85 border-r border-slate-800 p-4 hidden md:block">
    <?= brand_restaurant_sidebar_header_html($restaurantSidebarName) ?>
    <nav class="space-y-2 text-sm">
        <a href="/restaurant/dashboard.php" class="<?= restaurant_sidebar_link_class($restaurantSidebarActive, 'dashboard') ?>">Обзор</a>
        <a href="/restaurant/revenue.php" class="<?= restaurant_sidebar_link_class($restaurantSidebarActive, 'revenue') ?>">Доход</a>
        <a href="/restaurant/menu_categories.php" class="<?= restaurant_sidebar_link_class($restaurantSidebarActive, 'menu_categories') ?>">Категории меню</a>
        <a href="/restaurant/menu_items.php" class="<?= restaurant_sidebar_link_class($restaurantSidebarActive, 'menu_items') ?>">Блюда</a>
        <a href="/restaurant/tables.php" class="<?= restaurant_sidebar_link_class($restaurantSidebarActive, 'tables') ?>">Столы и QR</a>
        <a href="/restaurant/qr_print.php" class="<?= restaurant_sidebar_link_class($restaurantSidebarActive, 'qr_print') ?>">Печать QR</a>
        <a href="/restaurant/floorplan.php" class="<?= restaurant_sidebar_link_class($restaurantSidebarActive, 'floorplan') ?>">Карта столов</a>
        <a href="/restaurant/orders.php" class="<?= restaurant_sidebar_link_class($restaurantSidebarActive, 'orders') ?>">Заказы</a>
        <a href="/restaurant/upsells.php" class="<?= restaurant_sidebar_link_class($restaurantSidebarActive, 'upsells') ?>">Допродажи</a>
        <a href="/restaurant/upsell_rules.php" class="<?= restaurant_sidebar_link_class($restaurantSidebarActive, 'upsell_rules') ?>">Правила допродаж</a>
        <a href="/restaurant/analytics_upsell.php" class="<?= restaurant_sidebar_link_class($restaurantSidebarActive, 'analytics_upsell') ?>">Аналитика допродаж</a>
        <a href="/restaurant/crm.php" class="<?= restaurant_sidebar_link_class($restaurantSidebarActive, 'crm') ?>">CRM</a>
        <a href="/restaurant/crm_campaigns.php" class="<?= restaurant_sidebar_link_class($restaurantSidebarActive, 'crm_campaigns') ?>">CRM кампании</a>
        <a href="/restaurant/staff.php" class="<?= restaurant_sidebar_link_class($restaurantSidebarActive, 'staff') ?>">Сотрудники</a>
        <a href="/restaurant/setup.php" class="<?= restaurant_sidebar_link_class($restaurantSidebarActive, 'setup') ?>">Setup</a>
        <?php if (!function_exists('is_demo_mode') || !is_demo_mode()): ?>
            <a href="/restaurant/invite.php" class="<?= restaurant_sidebar_link_class($restaurantSidebarActive, 'invite') ?>">Пригласить рестораны</a>
        <?php endif; ?>
        <a href="/restaurant/settings.php" class="<?= restaurant_sidebar_link_class($restaurantSidebarActive, 'settings') ?>">Настройки</a>
        <a href="/logout.php" class="block px-3 py-2 rounded-xl hover:bg-slate-800/60 text-red-300">Выйти</a>
    </nav>
</aside>
