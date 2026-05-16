<?php
/**
 * Shared mobile drawer for restaurant-panel pages.
 * Requires:
 *   $restaurantSidebarActive (string)
 *   $restaurantSidebarName (string optional)
 *
 * This include uses the same dash-nav ids/logic as restaurant/dashboard.php
 * to keep the mobile UX consistent across the panel.
 */
$restaurantSidebarActive = isset($restaurantSidebarActive) ? (string)$restaurantSidebarActive : '';
$restaurantSidebarName = isset($restaurantSidebarName) ? (string)$restaurantSidebarName : (string)($currentRestaurant['name'] ?? 'Ресторан');
?>

<!-- Mobile: top bar + drawer -->
<div class="md:hidden sticky top-0 z-40 flex items-center justify-between gap-2 px-3 py-3 border-b border-gray-800 bg-[#0B0F19]/95 backdrop-blur-md no-print" style="padding-top:max(0.75rem, env(safe-area-inset-top))">
    <span class="text-sm font-semibold text-[#F3F4F6] truncate min-w-0 flex-1"><?= htmlspecialchars($restaurantSidebarName, ENT_QUOTES, 'UTF-8') ?></span>
    <button type="button"
            id="dash-nav-open"
            class="shrink-0 min-h-[44px] min-w-[44px] rounded-xl border border-gray-700 bg-[#121826] text-sm font-medium text-[#F3F4F6] touch-manipulation"
            aria-expanded="false"
            aria-controls="dash-nav-panel">Меню</button>
</div>

<div id="dash-nav-overlay" class="fixed inset-0 z-50 hidden md:hidden no-print" aria-hidden="true">
    <button type="button" id="dash-nav-backdrop" class="absolute inset-0 bg-black/60" aria-label="Закрыть меню"></button>
    <div id="dash-nav-panel"
         class="absolute right-0 top-0 bottom-0 w-[min(100%,18rem)] max-w-full bg-gray-950 border-l border-gray-800 shadow-2xl overflow-y-auto overscroll-contain"
         style="padding:max(1rem, env(safe-area-inset-top)) max(1rem, env(safe-area-inset-right)) max(1rem, env(safe-area-inset-bottom)) max(1rem, env(safe-area-inset-left))">
        <div class="flex justify-between items-center gap-2 mb-4">
            <span class="font-semibold text-[#F3F4F6] text-sm">Разделы</span>
            <button type="button"
                    id="dash-nav-close"
                    class="min-h-[44px] min-w-[44px] rounded-xl border border-gray-700 text-[#F3F4F6] text-lg leading-none touch-manipulation"
                    aria-label="Закрыть">×</button>
        </div>
        <div class="mb-4">
            <?= brand_restaurant_sidebar_header_html($restaurantSidebarName) ?>
        </div>

        <?php
        $restaurantSidebarNavClass = 'space-y-1 text-sm';
        require __DIR__ . '/_sidebar_nav.php';
        ?>
    </div>
</div>

<script>
(function () {
    var overlay = document.getElementById('dash-nav-overlay');
    var openBtn = document.getElementById('dash-nav-open');
    var closeBtn = document.getElementById('dash-nav-close');
    var backdrop = document.getElementById('dash-nav-backdrop');

    function openNav() {
        if (!overlay) return;
        overlay.classList.remove('hidden');
        document.body.style.overflow = 'hidden';
        if (openBtn) openBtn.setAttribute('aria-expanded', 'true');
    }

    function closeNav() {
        if (!overlay) return;
        overlay.classList.add('hidden');
        document.body.style.overflow = '';
        if (openBtn) openBtn.setAttribute('aria-expanded', 'false');
    }

    if (openBtn) openBtn.addEventListener('click', openNav);
    if (closeBtn) closeBtn.addEventListener('click', closeNav);
    if (backdrop) backdrop.addEventListener('click', closeNav);
    if (overlay) {
        overlay.querySelectorAll('a').forEach(function (a) {
            a.addEventListener('click', closeNav);
        });
    }
})();
</script>

