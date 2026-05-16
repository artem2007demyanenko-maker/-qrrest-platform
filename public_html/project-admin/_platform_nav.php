<?php
/**
 * Shared platform admin primary navigation.
 * Expects: $platformNavActive — one of: home, restaurants, users, transactions, logs
 * Expects: function e() for escaping (provided by each page).
 */
$platformNavActive = $platformNavActive ?? 'home';
$platformNavItems = [
    'home'          => ['label' => 'Главная',       'href' => '/project-admin/index.php'],
    'restaurants'   => ['label' => 'Рестораны',     'href' => '/project-admin/restaurants.php'],
    'users'         => ['label' => 'Пользователи',  'href' => '/project-admin/users.php'],
    'transactions'  => ['label' => 'Транзакции',    'href' => '/project-admin/transactions.php'],
    'logs'          => ['label' => 'Логи',          'href' => '/project-admin/logs.php'],
];
?>
<nav class="mb-5 rounded-2xl border border-slate-800/90 bg-slate-900/50 px-3 py-3 sm:px-4" aria-label="Платформенная навигация">
    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <div class="text-[10px] uppercase tracking-wider text-slate-500">
            Платформа
        </div>
        <div class="flex flex-wrap gap-1.5 sm:justify-end">
            <?php foreach ($platformNavItems as $id => $item): ?>
                <?php
                $isActive = ($platformNavActive === $id);
                $cls = $isActive
                    ? 'border-emerald-500/80 bg-emerald-500/15 text-emerald-100'
                    : 'border-slate-700 bg-slate-900/80 text-slate-300 hover:border-slate-500 hover:text-slate-100';
                ?>
                <a href="<?= e($item['href']) ?>"
                   class="inline-flex items-center rounded-xl border px-3 py-1.5 text-[12px] font-medium transition <?= $cls ?>">
                    <?= e($item['label']) ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</nav>
