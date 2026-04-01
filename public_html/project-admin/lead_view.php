<?php
/**
 * Lead card: info, status change, notes, tasks, history.
 */

$rid = bin2hex(random_bytes(4));
set_exception_handler(function (Throwable $e) use ($rid) {
    error_log('LEAD_VIEW rid=' . $rid . ' ' . $e->getMessage());
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

$leadId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($leadId <= 0) {
    header('Location: /project-admin/leads.php');
    exit;
}

$lead = lead_get($leadId);
if (!$lead) {
    header('Location: /project-admin/leads.php');
    exit;
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

$statusLabels = [
    'new'             => 'Новая',
    'contacted'       => 'Связались',
    'demo_scheduled'  => 'Демо запланировано',
    'negotiation'     => 'Переговоры',
    'won'             => 'Победили',
    'lost'            => 'Потерян',
    'in_progress'     => 'В работе',
    'done'            => 'Завершена',
];

$allowedStatuses = leads_get_allowed_statuses();
$user = auth_user();
$userId = $user ? (int)$user['id'] : null;

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfOk = isset($_SESSION['csrf'], $_POST['csrf']) && hash_equals((string)$_SESSION['csrf'], (string)$_POST['csrf']);
    if (!$csrfOk) {
        $error = 'Неверный токен. Обновите страницу.';
    } else {
        $action = trim($_POST['action'] ?? '');
        if ($action === 'status') {
            $newStatus = trim($_POST['status'] ?? '');
            if (in_array($newStatus, $allowedStatuses, true) && lead_update_status($leadId, $newStatus, $userId)) {
                $lead = lead_get($leadId);
                $message = 'Статус обновлён.';
            } else {
                $error = 'Не удалось обновить статус.';
            }
        } elseif ($action === 'note') {
            $text = trim($_POST['note_text'] ?? '');
            if ($text !== '' && lead_note_add($leadId, $userId, $text)) {
                $message = 'Заметка добавлена.';
            } else {
                $error = 'Не удалось добавить заметку.';
            }
        } elseif ($action === 'task') {
            $title = trim($_POST['task_title'] ?? '');
            $dueAt = trim($_POST['task_due_at'] ?? '');
            if ($title !== '' && lead_task_add($leadId, $userId, $title, $dueAt ?: null)) {
                $message = 'Задача добавлена.';
            } else {
                $error = 'Не удалось добавить задачу.';
            }
        } elseif ($action === 'task_status') {
            $taskId = (int)($_POST['task_id'] ?? 0);
            $status = trim($_POST['task_status'] ?? '');
            if ($taskId > 0 && in_array($status, ['done', 'canceled'], true) && lead_task_update_status($taskId, $status, $userId)) {
                $message = 'Статус задачи обновлён.';
            } else {
                $error = 'Не удалось обновить задачу.';
            }
        } elseif ($action === 'refresh_score') {
            if (function_exists('lead_score_refresh') && lead_score_refresh($leadId)) {
                $message = 'Score пересчитан.';
                $lead = lead_get($leadId);
            } else {
                $error = 'Не удалось пересчитать score.';
            }
        } elseif ($action === 'save_mrr') {
            $mrrRaw = isset($_POST['expected_mrr']) ? trim((string)$_POST['expected_mrr']) : '';
            $mrr = $mrrRaw === '' ? null : (float)str_replace(',', '.', $mrrRaw);
            if ($mrr !== null && $mrr < 0) {
                $mrr = null;
            }
            if (function_exists('lead_update_expected_mrr') && lead_update_expected_mrr($leadId, $mrr)) {
                $message = 'Expected MRR сохранён.';
                $lead = lead_get($leadId);
            } else {
                $error = 'Не удалось сохранить Expected MRR.';
            }
        }
    }
}

$notes = lead_notes_list($leadId);
$tasks = lead_tasks_list($leadId);
$history = lead_history_list($leadId);
$scoreResult = lead_score_calculate($lead, $notes, $tasks, $history);

$appName = 'QR-Rest Cloud';
$configPath = __DIR__ . '/../../app/config.php';
if (is_file($configPath)) {
    $cfg = require $configPath;
    if (!empty($cfg['app']['name'])) {
        $appName = $cfg['app']['name'];
    }
}

$st = $lead['status'] ?? 'new';
$badgeClass = 'badge-' . preg_replace('/[^a-z0-9_]/', '', $st);
$createdAt = !empty($lead['created_at']) ? date('d.m.Y H:i', strtotime($lead['created_at'])) : '—';
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Лид #<?= $leadId ?> — <?= e($appName) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?= brand_head_tags() ?>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .badge-new { background: rgba(52,211,153,0.2); border-color: rgba(52,211,153,0.6); color: #A7F3D0; }
        .badge-contacted { background: rgba(56,189,248,0.2); border-color: rgba(56,189,248,0.6); color: #BFDBFE; }
        .badge-demo_scheduled { background: rgba(167,139,250,0.2); border-color: rgba(167,139,250,0.6); color: #DDD6FE; }
        .badge-negotiation { background: rgba(251,191,36,0.2); border-color: rgba(251,191,36,0.6); color: #FEF3C7; }
        .badge-won { background: rgba(52,211,153,0.3); border-color: rgba(52,211,153,0.8); color: #D1FAE5; }
        .badge-lost { background: rgba(248,113,113,0.2); border-color: rgba(248,113,113,0.6); color: #FECACA; }
        .badge-in_progress { background: rgba(56,189,248,0.2); border-color: rgba(56,189,248,0.6); color: #BFDBFE; }
        .badge-done { background: rgba(148,163,184,0.2); border-color: rgba(148,163,184,0.6); color: #E2E8F0; }
        .task-overdue { background: rgba(248,113,113,0.15); border-color: rgba(248,113,113,0.5); }
    </style>
</head>
<body class="min-h-screen bg-slate-950 text-slate-50">
<div class="min-h-screen bg-gradient-to-br from-slate-950 via-slate-950 to-slate-900">
    <div class="max-w-4xl mx-auto px-4 py-6">

        <header class="mb-6 flex items-center justify-between gap-3">
            <a href="/project-admin/leads.php" class="text-sm text-slate-400 hover:text-slate-200">← Назад к лидам</a>
        </header>

        <?php if ($message): ?>
            <div class="mb-4 rounded-xl bg-emerald-500/10 border border-emerald-500/50 px-3 py-2 text-sm text-emerald-100"><?= e($message) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="mb-4 rounded-xl bg-red-500/10 border border-red-500/50 px-3 py-2 text-sm text-red-100"><?= e($error) ?></div>
        <?php endif; ?>

        <!-- Score & Priority -->
        <section class="mb-6 rounded-3xl border border-slate-800 bg-slate-900/80 p-4">
            <div class="flex flex-wrap items-center justify-between gap-3 mb-2">
                <div class="flex flex-wrap items-center gap-3">
                    <span class="text-slate-400 text-sm">Score:</span>
                    <span class="text-xl font-bold text-slate-100"><?= (int)$scoreResult['score'] ?></span>
                    <span class="px-3 py-1 rounded-full border text-sm font-medium
                        <?= $scoreResult['priority'] === 'hot' ? 'bg-red-500/20 border-red-500/60 text-red-200' : '' ?>
                        <?= $scoreResult['priority'] === 'warm' ? 'bg-amber-500/20 border-amber-500/60 text-amber-200' : '' ?>
                        <?= $scoreResult['priority'] === 'cold' ? 'bg-slate-600/30 border-slate-500/60 text-slate-200' : '' ?>
                    "><?= strtoupper($scoreResult['priority']) ?></span>
                </div>
                <form method="post">
                    <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                    <input type="hidden" name="action" value="refresh_score">
                    <button type="submit" class="px-3 py-1.5 rounded-xl bg-slate-700 hover:bg-slate-600 text-sm text-slate-200">Пересчитать score</button>
                </form>
            </div>
            <?php if (!empty($scoreResult['reasons'])): ?>
                <div class="text-xs text-slate-400 mt-2">Причины: <?= e(implode(' · ', $scoreResult['reasons'])) ?></div>
            <?php endif; ?>
        </section>

        <!-- Block 1: Main info -->
        <section class="mb-6 rounded-3xl border border-slate-800 bg-slate-900/80 p-4">
            <h2 class="text-sm font-semibold text-slate-300 mb-3">Основная информация</h2>
            <dl class="grid grid-cols-1 sm:grid-cols-2 gap-2 text-sm">
                <div><dt class="text-slate-500">Ресторан</dt><dd class="text-slate-100"><?= e($lead['restaurant_name'] ?? '—') ?></dd></div>
                <div><dt class="text-slate-500">Контакт</dt><dd class="text-slate-100"><?= e($lead['contact_name'] ?? '—') ?></dd></div>
                <div><dt class="text-slate-500">Телефон</dt><dd class="text-slate-100"><?= e($lead['contact_phone'] ?? '—') ?></dd></div>
                <div><dt class="text-slate-500">Email</dt><dd class="text-slate-100"><?= e($lead['contact_email'] ?? '—') ?></dd></div>
                <div><dt class="text-slate-500">Город</dt><dd class="text-slate-100"><?= e($lead['city'] ?? '—') ?></dd></div>
                <div><dt class="text-slate-500">Статус</dt><dd><span class="px-2 py-0.5 rounded-full border text-xs <?= $badgeClass ?>"><?= e($statusLabels[$st] ?? $st) ?></span></dd></div>
                <div><dt class="text-slate-500">Создан</dt><dd class="text-slate-300"><?= e($createdAt) ?></dd></div>
                <?php if (array_key_exists('expected_mrr', $lead)): ?>
                <div class="sm:col-span-2">
                    <dt class="text-slate-500 mb-1">Expected MRR</dt>
                    <dd>
                        <form method="post" class="inline-flex items-center gap-2">
                            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                            <input type="hidden" name="action" value="save_mrr">
                            <input type="number" name="expected_mrr" step="0.01" min="0" placeholder="0"
                                   value="<?= $lead['expected_mrr'] !== null && $lead['expected_mrr'] !== '' ? e((string)$lead['expected_mrr']) : '' ?>"
                                   class="w-28 rounded-lg bg-slate-950 border border-slate-700 px-2 py-1 text-sm text-slate-100">
                            <button type="submit" class="px-2 py-1 rounded-lg bg-slate-700 hover:bg-slate-600 text-xs text-slate-200">Сохранить</button>
                        </form>
                    </dd>
                </div>
                <?php endif; ?>
            </dl>
            <?php if (!empty($lead['message'])): ?>
                <div class="mt-3 pt-3 border-t border-slate-700">
                    <dt class="text-slate-500 text-xs mb-1">Сообщение</dt>
                    <dd class="text-slate-200 text-sm whitespace-pre-line"><?= nl2br(e($lead['message'])) ?></dd>
                </div>
            <?php endif; ?>
        </section>

        <!-- Block 2: Quick status -->
        <section class="mb-6 rounded-3xl border border-slate-800 bg-slate-900/80 p-4">
            <h2 class="text-sm font-semibold text-slate-300 mb-3">Сменить статус</h2>
            <form method="post" class="flex flex-wrap gap-2">
                <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                <input type="hidden" name="action" value="status">
                <?php foreach ($allowedStatuses as $s): ?>
                    <?php if (($lead['status'] ?? '') !== $s): ?>
                        <button type="submit" name="status" value="<?= e($s) ?>" class="px-3 py-1.5 rounded-xl bg-slate-800 border border-slate-600 text-xs text-slate-200 hover:bg-slate-700"><?= e($statusLabels[$s] ?? $s) ?></button>
                    <?php endif; ?>
                <?php endforeach; ?>
            </form>
        </section>

        <!-- Block 3: Notes -->
        <section class="mb-6 rounded-3xl border border-slate-800 bg-slate-900/80 p-4">
            <h2 class="text-sm font-semibold text-slate-300 mb-3">Заметки</h2>
            <form method="post" class="mb-4">
                <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                <input type="hidden" name="action" value="note">
                <textarea name="note_text" rows="2" class="w-full rounded-xl bg-slate-950 border border-slate-700 px-3 py-2 text-sm text-slate-200 mb-2" placeholder="Текст заметки..."></textarea>
                <button type="submit" class="px-3 py-1.5 rounded-xl bg-emerald-500/20 border border-emerald-500/50 text-emerald-200 text-sm">Добавить</button>
            </form>
            <div class="space-y-2">
                <?php foreach ($notes as $n): ?>
                    <div class="rounded-xl bg-slate-950/80 border border-slate-700 px-3 py-2 text-sm text-slate-200">
                        <div class="text-slate-500 text-xs mb-1"><?= e(!empty($n['created_at']) ? date('d.m.Y H:i', strtotime($n['created_at'])) : '') ?></div>
                        <?= nl2br(e($n['note_text'] ?? '')) ?>
                    </div>
                <?php endforeach; ?>
                <?php if (empty($notes)): ?>
                    <p class="text-slate-500 text-sm">Нет заметок.</p>
                <?php endif; ?>
            </div>
        </section>

        <!-- Block 4: Tasks -->
        <section class="mb-6 rounded-3xl border border-slate-800 bg-slate-900/80 p-4">
            <h2 class="text-sm font-semibold text-slate-300 mb-3">Задачи / Follow-up</h2>
            <form method="post" class="mb-4 flex flex-wrap gap-2 items-end">
                <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                <input type="hidden" name="action" value="task">
                <div>
                    <label class="block text-[11px] text-slate-500 mb-1">Название</label>
                    <input type="text" name="task_title" class="rounded-xl bg-slate-950 border border-slate-700 px-3 py-2 text-sm text-slate-200 w-64" placeholder="Например: Позвонить">
                </div>
                <div>
                    <label class="block text-[11px] text-slate-500 mb-1">Срок (YYYY-MM-DD)</label>
                    <input type="date" name="task_due_at" class="rounded-xl bg-slate-950 border border-slate-700 px-3 py-2 text-sm text-slate-200">
                </div>
                <button type="submit" class="px-3 py-2 rounded-xl bg-sky-500/20 border border-sky-500/50 text-sky-200 text-sm">Добавить задачу</button>
            </form>
            <div class="space-y-2">
                <?php foreach ($tasks as $t): ?>
                    <?php
                    $taskStatus = $t['status'] ?? 'open';
                    $dueAt = !empty($t['due_at']) ? $t['due_at'] : null;
                    $isOverdue = $dueAt && $taskStatus === 'open' && strtotime($dueAt) < time();
                    ?>
                    <div class="rounded-xl border px-3 py-2 text-sm flex flex-wrap items-center justify-between gap-2 <?= $isOverdue ? 'task-overdue border-red-500/50' : 'bg-slate-950/80 border-slate-700' ?>">
                        <div>
                            <span class="text-slate-200"><?= e($t['title'] ?? '') ?></span>
                            <?php if ($dueAt): ?>
                                <span class="text-slate-500 text-xs ml-2">до <?= e(date('d.m.Y', strtotime($dueAt))) ?></span>
                            <?php endif; ?>
                            <span class="ml-2 text-[11px] text-slate-500"><?= $taskStatus === 'open' ? 'Открыта' : ($taskStatus === 'done' ? 'Выполнена' : 'Отменена') ?></span>
                        </div>
                        <?php if ($taskStatus === 'open'): ?>
                            <form method="post" class="inline">
                                <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                                <input type="hidden" name="action" value="task_status">
                                <input type="hidden" name="task_id" value="<?= (int)$t['id'] ?>">
                                <button type="submit" name="task_status" value="done" class="px-2 py-1 rounded-lg bg-emerald-500/20 text-emerald-200 text-xs">Готово</button>
                                <button type="submit" name="task_status" value="canceled" class="px-2 py-1 rounded-lg bg-slate-600 text-slate-300 text-xs">Отмена</button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <?php if (empty($tasks)): ?>
                    <p class="text-slate-500 text-sm">Нет задач.</p>
                <?php endif; ?>
            </div>
        </section>

        <!-- Block 5: History -->
        <section class="mb-6 rounded-3xl border border-slate-800 bg-slate-900/80 p-4">
            <h2 class="text-sm font-semibold text-slate-300 mb-3">История</h2>
            <div class="space-y-2 text-sm">
                <?php
                $eventLabels = [
                    'status_changed' => 'Статус изменён',
                    'note_added'     => 'Заметка добавлена',
                    'task_added'     => 'Задача добавлена',
                    'task_done'      => 'Задача выполнена',
                    'task_canceled'  => 'Задача отменена',
                ];
                foreach ($history as $h):
                    $ev = $h['event_type'] ?? '';
                    $label = $eventLabels[$ev] ?? $ev;
                    $detail = '';
                    if ($ev === 'status_changed' && ($h['old_value'] !== null || $h['new_value'] !== null)) {
                        $detail = ($h['old_value'] ?? '') . ' → ' . ($h['new_value'] ?? '');
                    } elseif (!empty($h['new_value'])) {
                        $detail = substr($h['new_value'], 0, 80);
                    }
                    $created = !empty($h['created_at']) ? date('d.m.Y H:i', strtotime($h['created_at'])) : '';
                ?>
                    <div class="flex gap-2 text-slate-400">
                        <span class="text-slate-500 shrink-0"><?= e($created) ?></span>
                        <span class="text-slate-300"><?= e($label) ?></span>
                        <?php if ($detail !== ''): ?>
                            <span class="text-slate-500"><?= e($detail) ?></span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <?php if (empty($history)): ?>
                    <p class="text-slate-500">Нет событий.</p>
                <?php endif; ?>
            </div>
        </section>
    </div>
</div>
</body>
</html>
