<?php
/**
 * Sales pipeline: list + KPI + filters. Link to lead_view for card.
 */

$rid = bin2hex(random_bytes(4));
set_exception_handler(function (Throwable $e) use ($rid) {
    error_log('LEADS_PIPELINE rid=' . $rid . ' ' . $e->getMessage());
    if (!headers_sent()) {
        http_response_code(500);
    }
    echo '<h1>Ошибка</h1><p>Попробуйте позже.</p><p style="font-size:11px;color:#666">RID: ' . htmlspecialchars($rid) . '</p>';
    exit;
});

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/leads_pipeline_repo.php';
require_once __DIR__ . '/../../app/leads_scoring.php';

if (!function_exists('e')) {
    function e($v): string {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

require_login();
require_role(['project_owner']);

$statusFilter = isset($_GET['status']) ? trim((string)$_GET['status']) : 'all';
$priorityFilter = isset($_GET['priority']) ? trim((string)$_GET['priority']) : 'all';
$searchQ = isset($_GET['q']) ? trim((string)$_GET['q']) : '';

$allowedStatuses = leads_get_allowed_statuses();
$statusLabels = [
    'new'             => 'Новая',
    'contacted'       => 'Связались',
    'demo_scheduled'  => 'Демо запланировано',
    'negotiation'     => 'Переговоры',
    'won'             => 'Победили',
    'lost'             => 'Потерян',
    'in_progress'     => 'В работе',
    'done'            => 'Завершена',
];

if (function_exists('is_demo_mode') && is_demo_mode()) {
    $allDemo = demo_leads();
    $counts = array_fill_keys($allowedStatuses, 0);
    foreach ($allDemo as $l) {
        $st = $l['status'] ?? 'new';
        if (isset($counts[$st])) $counts[$st]++;
    }
    $leads = $allDemo;
    if ($statusFilter !== '' && $statusFilter !== 'all') {
        $leads = array_values(array_filter($leads, function ($l) use ($statusFilter) {
            return ($l['status'] ?? '') === $statusFilter;
        }));
    }
    if ($searchQ !== '') {
        $q = strtolower($searchQ);
        $leads = array_values(array_filter($leads, function ($l) use ($q) {
            return strpos(strtolower($l['restaurant_name'] ?? ''), $q) !== false
                || strpos(strtolower($l['contact_name'] ?? ''), $q) !== false
                || strpos(strtolower($l['city'] ?? ''), $q) !== false
                || strpos(strtolower($l['contact_phone'] ?? ''), $q) !== false;
        }));
    }
} else {
    $counts = leads_pipeline_counts();
    $leads = leads_pipeline_list($statusFilter, $searchQ, 200, $priorityFilter);
}

$appName = 'QR-Rest Cloud';
$configPath = __DIR__ . '/../../app/config.php';
if (is_file($configPath)) {
    $cfg = require $configPath;
    if (!empty($cfg['app']['name'])) {
        $appName = $cfg['app']['name'];
    }
}
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Лиды — <?= e($appName) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?= brand_head_tags() ?>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="/assets/css/polish.css">
    <style>
        .badge-new { background: rgba(148,163,184,0.2); border: 1px solid rgba(148,163,184,0.5); color: #E2E8F0; }
        .badge-contacted { background: rgba(59,130,246,0.2); border: 1px solid rgba(59,130,246,0.5); color: #93C5FD; }
        .badge-demo_scheduled { background: rgba(99,102,241,0.2); border: 1px solid rgba(99,102,241,0.5); color: #A5B4FC; }
        .badge-negotiation { background: rgba(234,179,8,0.2); border: 1px solid rgba(234,179,8,0.5); color: #FDE047; }
        .badge-won { background: rgba(34,197,94,0.2); border: 1px solid rgba(34,197,94,0.5); color: #86EFAC; }
        .badge-lost { background: rgba(239,68,68,0.2); border: 1px solid rgba(239,68,68,0.5); color: #FCA5A5; }
        .badge-in_progress { background: rgba(59,130,246,0.2); border: 1px solid rgba(59,130,246,0.5); color: #93C5FD; }
        .badge-done { background: rgba(148,163,184,0.2); border: 1px solid rgba(148,163,184,0.5); color: #E2E8F0; }
    </style>
</head>
<body class="min-h-screen bg-slate-950 text-slate-50">
<div class="min-h-screen bg-gradient-to-br from-slate-950 via-slate-950 to-slate-900">
    <div class="max-w-6xl mx-auto px-4 py-8 space-y-8">
        <?php if (function_exists('is_demo_mode') && is_demo_mode()): ?>
        <div class="rounded-xl bg-amber-500/10 border border-amber-500/40 px-4 py-2.5 flex items-center justify-center gap-2 text-sm text-amber-200">
            <span aria-hidden="true">⚠</span>
            <span>Demo environment — actions are simulated.</span>
        </div>
        <?php endif; ?>
        <header class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-6">
            <div class="space-y-1">
                <div class="text-xs uppercase tracking-wide text-gray-400">Панель владельца платформы</div>
                <h1 class="text-xl font-semibold tracking-tight text-slate-50">Лиды</h1>
                <p class="text-sm text-gray-400">Sales pipeline: заявки с лендинга, статусы, карточки.</p>
            </div>
            <div class="flex items-center gap-2">
                <a href="/project-admin/sales_forecast.php" class="text-sm text-emerald-300 hover:text-emerald-200">Sales Forecast</a>
                <a href="/project-admin/lead_scoring.php" class="text-sm text-amber-300 hover:text-amber-200">Scoring</a>
                <a href="/project-admin/diagnostics.php" class="text-sm text-slate-400 hover:text-slate-200">Diagnostics</a>
                <a href="/project-admin/" class="text-sm text-slate-400 hover:text-slate-200">← В панель</a>
            </div>
        </header>

        <!-- KPI -->
        <div class="flex flex-wrap gap-3">
            <?php foreach ($allowedStatuses as $st): ?>
                <a href="?status=<?= e($st) ?><?= $searchQ !== '' ? '&q=' . e(rawurlencode($searchQ)) : '' ?>"
                   class="px-3 py-1.5 rounded-full border text-[11px] font-medium <?= $statusFilter === $st ? 'ring-2 ring-white/30 ' : '' ?>badge-<?= e($st) ?>">
                    <?= e($statusLabels[$st] ?? $st) ?>: <span class="font-semibold"><?= (int)($counts[$st] ?? 0) ?></span>
                </a>
            <?php endforeach; ?>
            <?php if (!empty($counts['in_progress']) || !empty($counts['done'])): ?>
                <span class="px-3 py-1.5 rounded-full border text-[11px] text-slate-400 border-slate-600">
                    in_progress: <?= (int)($counts['in_progress'] ?? 0) ?> · done: <?= (int)($counts['done'] ?? 0) ?>
                </span>
            <?php endif; ?>
        </div>

        <!-- Filters -->
        <form method="get" class="flex flex-wrap gap-6 items-end">
            <select name="status" class="rounded-xl bg-slate-900 border border-slate-700 px-3 py-2 text-sm text-slate-200">
                <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>Все статусы</option>
                <?php foreach ($allowedStatuses as $st): ?>
                    <option value="<?= e($st) ?>" <?= $statusFilter === $st ? 'selected' : '' ?>><?= e($statusLabels[$st] ?? $st) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="priority" class="rounded-xl bg-slate-900 border border-slate-700 px-3 py-2 text-sm text-slate-200">
                <option value="all" <?= $priorityFilter === 'all' ? 'selected' : '' ?>>Все приоритеты</option>
                <option value="hot" <?= $priorityFilter === 'hot' ? 'selected' : '' ?>>Hot</option>
                <option value="warm" <?= $priorityFilter === 'warm' ? 'selected' : '' ?>>Warm</option>
                <option value="cold" <?= $priorityFilter === 'cold' ? 'selected' : '' ?>>Cold</option>
            </select>
            <input type="text" name="q" value="<?= e($searchQ) ?>" placeholder="Поиск: ресторан, контакт, телефон, email, город"
                   class="rounded-xl bg-slate-900 border border-slate-700 px-3 py-2 text-sm text-slate-200 min-w-[200px]">
            <button type="submit" class="px-4 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-medium">Показать</button>
        </form>

        <section class="space-y-6">
            <h2 class="text-xl font-semibold tracking-tight text-slate-100">Pipeline</h2>
        <?php if (empty($leads)): ?>
            <div class="rounded-xl border border-gray-800 bg-[#121826] shadow-lg px-6 py-16 text-center">
                <svg class="w-14 h-14 mx-auto text-gray-500 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"/></svg>
                <div class="text-lg font-medium text-slate-200 mb-1">Нет лидов</div>
                <div class="text-sm text-gray-400 max-w-sm mx-auto mb-6">По выбранным фильтрам записей не найдено. Заявки с лендинга появятся здесь.</div>
                <a href="/project-admin/" class="inline-block px-4 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-medium">В панель</a>
            </div>
        <?php else: ?>
            <div class="overflow-x-auto rounded-xl border border-gray-800 bg-[#121826] shadow-lg">
                <table class="table-saas w-full text-sm">
                    <thead>
                    <tr class="text-xs uppercase tracking-wide text-gray-400">
                        <th>Дата</th>
                        <th>Ресторан</th>
                        <th>Город</th>
                        <th>Статус</th>
                        <th>Expected MRR</th>
                        <th>Контакт</th>
                        <th>Телефон</th>
                        <th>Score</th>
                        <th>Приоритет</th>
                        <th>Действие</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($leads as $lead): ?>
                        <?php
                        $st = $lead['status'] ?? 'new';
                        $badgeClass = 'badge-' . preg_replace('/[^a-z0-9_]/', '', $st);
                        if (!preg_match('/^badge-[a-z_]+$/', $badgeClass)) {
                            $badgeClass = 'badge-new';
                        }
                        $createdAt = !empty($lead['created_at']) ? date('d.m.Y H:i', strtotime($lead['created_at'])) : '—';
                        $priority = $lead['priority'] ?? 'cold';
                        $priorityClass = $priority === 'hot' ? 'bg-red-500/20 border-red-500/60 text-red-200' : ($priority === 'warm' ? 'bg-amber-500/20 border-amber-500/60 text-amber-200' : 'bg-slate-600/30 border-slate-500/60 text-slate-200');
                        ?>
                        <tr>
                            <td class="text-slate-300"><?= e($createdAt) ?></td>
                            <td class="text-slate-100 font-medium"><?= e($lead['restaurant_name'] ?? '—') ?></td>
                            <td class="text-gray-400"><?= e($lead['city'] ?? '—') ?></td>
                            <td>
                                <span class="px-2 py-1 rounded-lg border text-xs font-medium <?= $badgeClass ?>"><?= e($statusLabels[$st] ?? $st) ?></span>
                            </td>
                            <td class="text-slate-200"><?= isset($lead['expected_mrr']) && $lead['expected_mrr'] !== '' && $lead['expected_mrr'] !== null ? number_format((float)$lead['expected_mrr'], 0, '.', ' ') : '—' ?></td>
                            <td class="text-slate-300"><?= e($lead['contact_name'] ?? '—') ?></td>
                            <td class="text-slate-400"><?= e($lead['contact_phone'] ?? '—') ?></td>
                            <td class="text-slate-200"><?= isset($lead['score']) ? (int)$lead['score'] : '—' ?></td>
                            <td>
                                <span class="px-2 py-0.5 rounded-full border text-[11px] <?= $priorityClass ?>"><?= e($priority) ?></span>
                            </td>
                            <td>
                                <a href="/project-admin/lead_view.php?id=<?= (int)$lead['id'] ?>" class="text-indigo-400 hover:text-indigo-300 text-sm font-medium">Открыть</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        </section>
    </div>
</div>
</body>
</html>
