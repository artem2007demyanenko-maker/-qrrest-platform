<?php
/**
 * Production shared nav for restaurant panel.
 * Requires:
 *   $restaurantSidebarActive (string key), optional $restaurantSidebarNavClass.
 */
if (!function_exists('restaurant_sidebar_nav_groups')) {
    function restaurant_sidebar_nav_groups(bool $showInvite): array
    {
        $groups = [
            [
                'id' => 'overview',
                'label' => 'Обзор',
                'items' => [
                    ['key' => 'dashboard', 'label' => 'Обзор', 'href' => '/restaurant/dashboard.php'],
                    ['key' => 'revenue', 'label' => 'Доход', 'href' => '/restaurant/revenue.php'],
                ],
            ],
            [
                'id' => 'menu',
                'label' => 'Меню',
                'items' => [
                    ['key' => 'menu_manage', 'label' => 'Редактор меню', 'href' => '/restaurant/menu_manage.php'],
                    ['key' => 'import_menu', 'label' => 'Импорт меню', 'href' => '/restaurant/import_menu.php'],
                ],
            ],
            [
                'id' => 'qr',
                'label' => 'QR',
                'items' => [
                    ['key' => 'qr_codes', 'label' => 'QR-коды', 'href' => '/restaurant/qr_codes.php'],
                    ['key' => 'tables_qr', 'label' => 'QR для столов', 'href' => '/restaurant/tables.php#qr-cards'],
                    ['key' => 'qr_print', 'label' => 'Печать QR', 'href' => '/restaurant/qr_print.php'],
                    ['key' => 'floorplan', 'label' => 'Карта столов', 'href' => '/restaurant/floorplan.php'],
                ],
            ],
            [
                'id' => 'sales',
                'label' => 'Продажи',
                'items' => [
                    ['key' => 'orders', 'label' => 'Заказы', 'href' => '/restaurant/orders.php'],
                    ['key' => 'kitchen', 'label' => 'Кухня / KDS', 'href' => '/staff/kitchen.php'],
                    ['key' => 'transactions', 'label' => 'Транзакции', 'href' => '/restaurant/transactions.php'],
                ],
            ],
            [
                'id' => 'growth',
                'label' => 'Рост',
                'items' => [
                    ['key' => 'upsells', 'label' => 'Допродажи', 'href' => '/restaurant/upsells.php'],
                    ['key' => 'upsell_rules', 'label' => 'Правила допродаж', 'href' => '/restaurant/upsell_rules.php'],
                    ['key' => 'analytics_upsell', 'label' => 'Аналитика допродаж', 'href' => '/restaurant/analytics_upsell.php'],
                    ['key' => 'growth_suggestions', 'label' => 'Рост и рекомендации', 'href' => '/restaurant/growth_suggestions.php'],
                ],
            ],
            [
                'id' => 'crm',
                'label' => 'CRM',
                'items' => [
                    ['key' => 'crm', 'label' => 'CRM', 'href' => '/restaurant/crm.php'],
                    ['key' => 'crm_campaigns', 'label' => 'CRM кампании', 'href' => '/restaurant/crm_campaigns.php'],
                    ['key' => 'loyalty_settings', 'label' => 'Лояльность', 'href' => '/restaurant/loyalty_settings.php'],
                    ['key' => 'feedback', 'label' => 'Отзывы', 'href' => '/restaurant/feedback.php'],
                    ['key' => 'guest_view', 'label' => 'Гости', 'href' => '/restaurant/guests.php'],
                ],
            ],
            [
                'id' => 'team',
                'label' => 'Команда',
                'items' => [
                    ['key' => 'staff', 'label' => 'Сотрудники', 'href' => '/restaurant/staff.php'],
                    ['key' => 'setup', 'label' => 'Setup', 'href' => '/restaurant/setup.php'],
                ],
            ],
            [
                'id' => 'settings',
                'label' => 'Настройки',
                'items' => [
                    ['key' => 'settings', 'label' => 'Настройки', 'href' => '/restaurant/settings.php'],
                ],
            ],
        ];

        if ($showInvite) {
            foreach ($groups as &$group) {
                if (($group['id'] ?? '') === 'team') {
                    $group['items'][] = ['key' => 'invite', 'label' => 'Пригласить ресторан', 'href' => '/restaurant/invite.php'];
                    break;
                }
            }
            unset($group);
        }

        return $groups;
    }
}

if (!function_exists('restaurant_sidebar_item_class')) {
    function restaurant_sidebar_item_class(string $active, string $key): string
    {
        if ($active === $key) {
            return 'block px-3 py-2 rounded-xl bg-slate-800/80 text-slate-100 border border-slate-700/70';
        }
        return 'block px-3 py-2 rounded-xl text-slate-300 hover:text-slate-100 hover:bg-slate-800/60 border border-transparent';
    }
}

$restaurantSidebarActive = isset($restaurantSidebarActive) ? (string)$restaurantSidebarActive : '';
$restaurantSidebarNavClass = isset($restaurantSidebarNavClass) ? (string)$restaurantSidebarNavClass : 'space-y-2 text-sm';
$showInviteLink = !function_exists('is_demo_mode') || !is_demo_mode();
$sidebarGroups = restaurant_sidebar_nav_groups($showInviteLink);
$platformBackUrl = '/project-admin/restaurants.php';
$authUser = function_exists('auth_user') ? auth_user() : null;
$authGlobalRole = is_array($authUser) ? (string)($authUser['global_role'] ?? '') : '';
$canReturnToPlatform = in_array($authGlobalRole, ['owner', 'project_owner'], true);
?>
<nav class="<?= htmlspecialchars($restaurantSidebarNavClass, ENT_QUOTES, 'UTF-8') ?>">
    <?php if ($canReturnToPlatform): ?>
        <a href="<?= htmlspecialchars($platformBackUrl, ENT_QUOTES, 'UTF-8') ?>"
           class="mb-2 block px-3 py-2 rounded-xl border border-amber-500/45 bg-amber-500/10 text-amber-100 hover:bg-amber-500/20 hover:border-amber-400/60">
            ← К выбору ресторанов
        </a>
    <?php endif; ?>

    <?php foreach ($sidebarGroups as $group):
        $items = is_array($group['items'] ?? null) ? $group['items'] : [];
        if ($items === []) {
            continue;
        }
        $groupActive = false;
        foreach ($items as $item) {
            if (($item['key'] ?? '') === $restaurantSidebarActive) {
                $groupActive = true;
                break;
            }
        }
        ?>
        <details class="group rounded-xl bg-slate-900/40 border border-slate-800/80 overflow-hidden"<?= $groupActive ? ' open' : '' ?>>
            <summary class="list-none cursor-pointer select-none flex items-center justify-between px-3 py-2.5 text-xs font-semibold uppercase tracking-wide <?= $groupActive ? 'text-slate-100 bg-slate-800/70' : 'text-slate-400 hover:text-slate-200 hover:bg-slate-800/50' ?>">
                <span><?= htmlspecialchars((string)($group['label'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                <span class="text-[11px] transition-transform group-open:rotate-90">›</span>
            </summary>
            <div class="px-2 py-2 space-y-1 border-t border-slate-800/70">
                <?php foreach ($items as $item):
                    $href = (string)($item['href'] ?? '#');
                    $key = (string)($item['key'] ?? '');
                    $label = (string)($item['label'] ?? '');
                    ?>
                    <a href="<?= htmlspecialchars($href, ENT_QUOTES, 'UTF-8') ?>" class="<?= restaurant_sidebar_item_class($restaurantSidebarActive, $key) ?>">
                        <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </details>
    <?php endforeach; ?>

    <a href="/logout.php" class="block px-3 py-2 rounded-xl hover:bg-slate-800/60 text-red-300 border border-transparent">Выйти</a>
</nav>
