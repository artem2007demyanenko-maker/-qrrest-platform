<?php
declare(strict_types=1);
/**
 * Верхний контекст кабинета ресторана (все роли).
 * Для project_owner — ссылка «Назад к платформе».
 * Ожидает: bootstrap, $currentRestaurant, e().
 */
if (!function_exists('auth_user')) {
    return;
}
global $currentRestaurant;
if (empty($currentRestaurant['id'])) {
    return;
}

require_once __DIR__ . '/../../app/platform_admin_context.php';

$user  = auth_user();
$isPo  = $user && (($user['global_role'] ?? '') === 'project_owner');
$name  = (string)($currentRestaurant['name'] ?? 'Ресторан');
$sub   = trim((string)($currentRestaurant['subdomain'] ?? ''));
$host  = platform_admin_main_domain_host();
$line  = $sub !== '' ? ($sub . '.' . $host) : '';
$plat  = platform_admin_home_url();
?>
<div class="restaurant-cabinet-context rounded-xl border border-slate-700/80 bg-slate-900/55 px-3 py-3 sm:px-4 mb-3 sm:mb-4 text-[12px] leading-snug shadow-sm shadow-black/20" role="region" aria-label="Контекст ресторана">
    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between sm:gap-4">
        <div class="min-w-0 flex-1">
            <div class="text-base sm:text-lg font-semibold text-slate-50 leading-tight break-words"><?= e($name) ?></div>
            <?php if ($line !== ''): ?>
                <code class="mt-1 block text-[11px] sm:text-xs text-slate-400 break-all font-mono"><?= e($line) ?></code>
            <?php endif; ?>
        </div>
        <?php if ($isPo): ?>
            <a href="<?= e($plat) ?>"
               class="inline-flex items-center justify-center rounded-lg border border-amber-500/45 bg-amber-500/10 px-3 py-2 text-[11px] font-medium text-amber-100 hover:bg-amber-500/18 hover:border-amber-400/60 transition touch-manipulation min-h-[40px] whitespace-nowrap shrink-0">
                ← Назад к платформе
            </a>
        <?php endif; ?>
    </div>
</div>
