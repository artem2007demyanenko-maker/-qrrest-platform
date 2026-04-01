<?php
/**
 * Bulk lead scoring: пересчитать score всем лидам. Только project_owner.
 */

$rid = bin2hex(random_bytes(4));
set_exception_handler(function (Throwable $e) use ($rid) {
    error_log('LEAD_SCORING_PAGE rid=' . $rid . ' ' . $e->getMessage());
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

$scoringAvailable = function_exists('db_column_exists') && db_column_exists('lead_requests', 'score');
$summary = null;
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_SESSION['csrf'] ?? '';
    if (empty($_POST['csrf']) || !hash_equals($csrf, (string)$_POST['csrf'])) {
        $error = 'Неверный запрос.';
    } elseif (!$scoringAvailable) {
        $error = 'Scoring недоступен: колонки не найдены.';
    } else {
        try {
            $summary = leads_score_refresh_all(500);
            $message = 'Пересчёт выполнен.';
        } catch (Throwable $e) {
            error_log('LEAD_SCORING_PAGE rid=' . $rid . ' ' . $e->getMessage());
            $error = 'Не удалось пересчитать.';
        }
    }
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
    <title>Lead Scoring — <?= e($appName) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?= brand_head_tags() ?>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-950 text-slate-50">
<div class="min-h-screen bg-gradient-to-br from-slate-950 via-slate-950 to-slate-900">
    <div class="max-w-2xl mx-auto px-4 py-6">

        <header class="mb-6 flex items-center justify-between gap-3">
            <div class="flex items-center gap-2">
                <a href="/project-admin/leads.php" class="text-sm text-slate-400 hover:text-slate-200">Лиды</a>
                <a href="/project-admin/diagnostics.php" class="text-sm text-slate-400 hover:text-slate-200">Diagnostics</a>
                <span class="text-slate-600">|</span>
                <span class="text-sm text-amber-300">Scoring</span>
            </div>
            <a href="/project-admin/" class="text-sm text-slate-400 hover:text-slate-200">← В панель</a>
        </header>

        <?php if ($message): ?>
            <div class="mb-4 rounded-xl bg-emerald-500/10 border border-emerald-500/50 px-3 py-2 text-sm text-emerald-100"><?= e($message) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="mb-4 rounded-xl bg-red-500/10 border border-red-500/50 px-3 py-2 text-sm text-red-100"><?= e($error) ?></div>
        <?php endif; ?>

        <section class="rounded-3xl border border-slate-800 bg-slate-900/80 p-4 mb-6">
            <h1 class="text-lg font-semibold text-slate-200 mb-2">Lead Scoring</h1>
            <?php if (!$scoringAvailable): ?>
                <p class="text-slate-400 text-sm">Scoring недоступен. Выполните миграцию (колонки score, priority в lead_requests).</p>
            <?php else: ?>
                <p class="text-slate-400 text-sm mb-4">Пересчитать score и priority для всех лидов (до 500).</p>
                <form method="post">
                    <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                    <button type="submit" class="px-4 py-2 rounded-xl bg-amber-500/20 border border-amber-500/50 text-amber-200 hover:bg-amber-500/30 text-sm">Пересчитать score всем</button>
                </form>
            <?php endif; ?>
        </section>

        <?php if ($summary !== null): ?>
            <section class="rounded-3xl border border-slate-800 bg-slate-900/80 p-4">
                <h2 class="text-sm font-semibold text-slate-300 mb-3">Результат</h2>
                <dl class="grid grid-cols-2 gap-2 text-sm">
                    <div><dt class="text-slate-500">Обновлено</dt><dd class="text-slate-100"><?= (int)$summary['updated_count'] ?></dd></div>
                    <div><dt class="text-slate-500">Hot</dt><dd class="text-red-200"><?= (int)$summary['hot_count'] ?></dd></div>
                    <div><dt class="text-slate-500">Warm</dt><dd class="text-amber-200"><?= (int)$summary['warm_count'] ?></dd></div>
                    <div><dt class="text-slate-500">Cold</dt><dd class="text-slate-300"><?= (int)$summary['cold_count'] ?></dd></div>
                </dl>
            </section>
        <?php endif; ?>

    </div>
</div>
</body>
</html>
