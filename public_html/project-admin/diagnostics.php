<?php
/**
 * Admin diagnostics: health summary, recent errors, cron runs. Project owner only.
 */

$rid = bin2hex(random_bytes(4));
set_exception_handler(function (Throwable $e) use ($rid) {
    error_log('STABILITY_ERROR project-admin/diagnostics.php rid=' . $rid . ' ' . $e->getMessage());
    if (!headers_sent()) {
        http_response_code(500);
    }
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Ошибка</title></head><body><p>Что-то пошло не так.</p><p style="font-size:11px;color:#666">RID: ' . htmlspecialchars($rid) . '</p></body></html>';
    exit;
});

$config = require __DIR__ . '/../../app/config.php';
$mainDomain = $config['app']['main_domain'] ?? 'localhost';
$protocol = $config['app']['protocol'] ?? 'http';
$host = $_SERVER['HTTP_HOST'] ?? '';
$host = preg_replace('/:\d+$/', '', $host);
$isMainHost = (strtolower($host) === strtolower($mainDomain)) || (strtolower($host) === strtolower('www.' . $mainDomain));
if (!$isMainHost) {
    header('Location: ' . $protocol . '://' . $mainDomain . ($_SERVER['REQUEST_URI'] ?? '/project-admin/diagnostics.php'));
    exit;
}

require_once __DIR__ . '/../../app/bootstrap.php';
if (function_exists('admin_ip_guard')) {
    admin_ip_guard();
}
require_login();
require_role(['project_owner']);

if (!function_exists('e')) {
    function e($v): string {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

$healthStatus = 'unknown';
$readyStatus = 'unknown';
$dbOk = false;
$appEnv = (string)($config['app']['env'] ?? 'local');
$version = (string)($config['version']['app_version'] ?? '') ?: (string)($config['version']['git_sha'] ?? '');

try {
    $pdo = db();
    $pdo->query("SELECT 1");
    $dbOk = true;
} catch (Throwable $e) {
    $healthStatus = 'error';
    $readyStatus = 'error';
}

if ($dbOk) {
    $keyTables = ['plans', 'subscriptions', 'restaurants', 'users', 'orders'];
    $missing = [];
    foreach ($keyTables as $t) {
        $stmt = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t LIMIT 1");
        $stmt->execute(['t' => $t]);
        if (!$stmt->fetchColumn()) {
            $missing[] = $t;
        }
    }
    $healthStatus = empty($missing) ? 'ok' : 'degraded';
    $readyStatus = (!in_array('users', $missing) && !in_array('restaurants', $missing) && !in_array('orders', $missing)) ? 'ok' : 'error';
}

$recentErrors = [];
$errorsTableExists = false;
$recentCronRuns = [];
$cronTableExists = false;

if ($dbOk) {
    try {
        $stmt = $pdo->query("SELECT id, level, source, message, rid, created_at FROM app_error_logs ORDER BY id DESC LIMIT 50");
        $recentErrors = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $errorsTableExists = true;
    } catch (Throwable $e) {
        $recentErrors = [];
    }
    try {
        $stmt = $pdo->query("SELECT id, job_name, status, started_at, finished_at FROM cron_runs ORDER BY id DESC LIMIT 20");
        $recentCronRuns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $cronTableExists = true;
    } catch (Throwable $e) {
        $recentCronRuns = [];
    }
}

$appName = $config['app']['name'] ?? 'QR-Rest Cloud';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?= brand_head_tags() ?>
    <title>Диагностика — <?= e($appName) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="/assets/css/polish.css">
</head>
<body class="min-h-screen bg-slate-950 text-slate-50">
<div class="max-w-5xl mx-auto px-4 py-8 space-y-8">
    <header class="flex flex-wrap items-center gap-4">
        <a href="/project-admin/index.php" class="text-sm text-gray-400 hover:text-emerald-300">← Панель</a>
        <a href="/project-admin/leads.php" class="text-sm text-gray-400 hover:text-slate-200">Лиды</a>
        <a href="/project-admin/sales_forecast.php" class="text-sm text-gray-400 hover:text-slate-200">Sales Forecast</a>
        <a href="/project-admin/diagnostics.php" class="text-sm text-emerald-400 font-medium">Diagnostics</a>
    </header>
    <div class="space-y-1">
        <h1 class="text-xl font-semibold tracking-tight text-slate-50">Диагностика</h1>
        <p class="text-sm text-gray-400">Состояние приложения, БД, cron и окружения.</p>
    </div>

    <?php if (!$errorsTableExists || !$cronTableExists): ?>
    <div class="rounded-xl bg-amber-500/10 border border-amber-500/50 px-4 py-3 text-sm text-amber-100">
        Примените миграции <code class="code-detail inline px-1.5 py-0.5 text-xs">2026_04_01_app_error_logs.sql</code> и <code class="code-detail inline px-1.5 py-0.5 text-xs">2026_04_01_cron_runs.sql</code> для полной диагностики.
    </div>
    <?php endif; ?>

    <!-- Status cards -->
    <section class="space-y-6">
        <h2 class="text-xl font-semibold tracking-tight text-slate-100">Status</h2>
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-6">
            <?php
            $healthIcon = $healthStatus === 'ok' ? '✔' : ($healthStatus === 'degraded' ? '⚠' : '✖');
            $healthColor = $healthStatus === 'ok' ? 'text-emerald-400' : ($healthStatus === 'degraded' ? 'text-amber-400' : 'text-red-400');
            $dbIcon = $dbOk ? '✔' : '✖';
            $dbColor = $dbOk ? 'text-emerald-400' : 'text-red-400';
            $readyIcon = $readyStatus === 'ok' ? '✔' : '✖';
            $readyColor = $readyStatus === 'ok' ? 'text-emerald-400' : 'text-red-400';
            $envIcon = $appEnv === 'production' ? '✔' : (in_array($appEnv, ['staging', 'stage'], true) ? '⚠' : '—');
            $envColor = $appEnv === 'production' ? 'text-emerald-400' : (in_array($appEnv, ['staging', 'stage'], true) ? 'text-amber-400' : 'text-gray-400');
            ?>
            <div class="rounded-xl border border-gray-800 bg-[#121826] shadow-lg p-5">
                <div class="text-xs uppercase tracking-wide text-gray-400 mb-2">Health</div>
                <div class="flex items-center gap-2">
                    <span class="text-2xl <?= $healthColor ?>" aria-hidden="true"><?= $healthIcon ?></span>
                    <span class="font-semibold text-slate-100"><?= e($healthStatus) ?></span>
                </div>
            </div>
            <div class="rounded-xl border border-gray-800 bg-[#121826] shadow-lg p-5">
                <div class="text-xs uppercase tracking-wide text-gray-400 mb-2">Database</div>
                <div class="flex items-center gap-2">
                    <span class="text-2xl <?= $dbColor ?>" aria-hidden="true"><?= $dbIcon ?></span>
                    <span class="font-semibold text-slate-100"><?= $dbOk ? 'ok' : 'error' ?></span>
                </div>
            </div>
            <div class="rounded-xl border border-gray-800 bg-[#121826] shadow-lg p-5">
                <div class="text-xs uppercase tracking-wide text-gray-400 mb-2">Cron</div>
                <div class="flex items-center gap-2">
                    <span class="text-2xl <?= $cronTableExists ? 'text-emerald-400' : 'text-amber-400' ?>" aria-hidden="true"><?= $cronTableExists ? '✔' : '⚠' ?></span>
                    <span class="font-semibold text-slate-100"><?= $cronTableExists ? 'ok' : 'n/a' ?></span>
                </div>
            </div>
            <div class="rounded-xl border border-gray-800 bg-[#121826] shadow-lg p-5">
                <div class="text-xs uppercase tracking-wide text-gray-400 mb-2">Env</div>
                <div class="flex items-center gap-2">
                    <span class="text-2xl <?= $envColor ?>" aria-hidden="true"><?= $envIcon ?></span>
                    <span class="font-semibold text-slate-100"><?= e($appEnv) ?></span>
                </div>
            </div>
        </div>
        <?php if ($version !== ''): ?>
        <div class="text-xs text-gray-500">version: <?= e(substr($version, 0, 24)) ?></div>
        <?php endif; ?>
    </section>

    <section class="mb-6 rounded-2xl border border-slate-800 bg-slate-900/80 p-4">
        <h2 class="text-lg font-medium mb-3">Quick links</h2>
        <div class="flex flex-wrap gap-2">
            <a href="/health.php" target="_blank" class="px-3 py-1.5 rounded-xl bg-slate-700 hover:bg-slate-600 text-sm">/health.php</a>
            <a href="/ready.php" target="_blank" class="px-3 py-1.5 rounded-xl bg-slate-700 hover:bg-slate-600 text-sm">/ready.php</a>
            <a href="/project-admin/leads.php" class="px-3 py-1.5 rounded-xl bg-slate-700 hover:bg-slate-600 text-sm">Лиды</a>
            <a href="/project-admin/sales_forecast.php" class="px-3 py-1.5 rounded-xl bg-slate-700 hover:bg-slate-600 text-sm">Sales Forecast</a>
        </div>
    </section>

    <section class="mb-6 space-y-6 rounded-2xl border border-gray-800 bg-[#121826] shadow-lg p-6">
        <h2 class="text-xl font-semibold tracking-tight text-slate-100">Recent app errors (last 50)</h2>
        <?php if (!$errorsTableExists): ?>
        <div class="empty-state-box">
            <div class="empty-state-title">Table app_error_logs missing</div>
            <div class="empty-state-text">Apply migration 2026_04_01_app_error_logs.sql for error logging.</div>
        </div>
        <?php elseif (empty($recentErrors)): ?>
        <div class="empty-state-box">
            <svg class="empty-state-icon mx-auto text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <div class="empty-state-title">No recent errors</div>
            <div class="empty-state-text">Application error log is empty. Good sign.</div>
        </div>
        <?php else: ?>
        <div class="overflow-x-auto rounded-xl border border-gray-800 max-h-[400px] overflow-y-auto">
            <table class="table-saas table-sticky-head w-full text-sm">
                <thead>
                    <tr class="text-xs uppercase tracking-wide text-gray-400">
                        <th>time</th>
                        <th>level</th>
                        <th>source</th>
                        <th>message</th>
                        <th>rid</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentErrors as $r): ?>
                    <tr class="border-b border-slate-800 hover:bg-gray-800">
                        <td class="py-3 px-4 text-slate-300"><?= e($r['created_at'] ?? '') ?></td>
                        <td class="py-3 px-4"><span class="px-2 py-0.5 rounded text-xs <?= ($r['level'] ?? '') === 'error' ? 'bg-red-500/20 text-red-200' : 'bg-slate-600 text-slate-200' ?>"><?= e($r['level'] ?? '') ?></span></td>
                        <td class="py-3 px-4 text-slate-300"><?= e($r['source'] ?? '') ?></td>
                        <td class="py-3 px-4">
                            <div class="max-w-md">
                                <span class="text-slate-200 truncate block" title="<?= e($r['message'] ?? '') ?>"><?= e(mb_substr($r['message'] ?? '', 0, 80)) ?><?= mb_strlen($r['message'] ?? '') > 80 ? '…' : '' ?></span>
                                <?php if (mb_strlen($r['message'] ?? '') > 80): ?>
                                <details class="mt-1">
                                    <summary class="text-xs text-gray-500 cursor-pointer hover:text-gray-400">Подробнее</summary>
                                    <div class="code-block mt-1"><?= e($r['message'] ?? '') ?></div>
                                </details>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td class="py-3 px-4"><?php if (!empty($r['rid'])): ?><a href="/project-admin/error_view.php?rid=<?= e(urlencode($r['rid'])) ?>" class="text-sky-400 hover:underline"><?= e($r['rid']) ?></a><?php else: ?>—<?php endif; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </section>

    <section class="mb-6 space-y-6 rounded-2xl border border-gray-800 bg-[#121826] shadow-lg p-6">
        <h2 class="text-xl font-semibold tracking-tight text-slate-100">Recent cron runs (last 20)</h2>
        <?php if (!$cronTableExists): ?>
        <div class="empty-state-box">
            <div class="empty-state-title">Table cron_runs missing</div>
            <div class="empty-state-text">Apply migration 2026_04_01_cron_runs.sql for cron monitoring.</div>
        </div>
        <?php elseif (empty($recentCronRuns)): ?>
        <div class="empty-state-box">
            <svg class="empty-state-icon mx-auto text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <div class="empty-state-title">No cron runs yet</div>
            <div class="empty-state-text">Cron jobs will appear here once they run.</div>
        </div>
        <?php else: ?>
        <div class="overflow-x-auto rounded-xl border border-gray-800 max-h-[300px] overflow-y-auto">
            <table class="table-saas table-sticky-head w-full text-sm">
                <thead>
                    <tr class="text-xs uppercase tracking-wide text-gray-400">
                        <th>job_name</th>
                        <th>status</th>
                        <th>started_at</th>
                        <th>finished_at</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentCronRuns as $r): ?>
                    <tr class="border-b border-slate-800 hover:bg-gray-800">
                        <td class="py-3 px-4 text-slate-200"><?= e($r['job_name'] ?? '') ?></td>
                        <td class="py-3 px-4"><span class="px-2 py-0.5 rounded text-xs <?= ($r['status'] ?? '') === 'success' ? 'bg-emerald-500/20 text-emerald-200' : (($r['status'] ?? '') === 'failed' ? 'bg-red-500/20 text-red-200' : 'bg-sky-500/20 text-sky-200') ?>"><?= e($r['status'] ?? '') ?></span></td>
                        <td class="py-3 px-4 text-slate-300"><?= e($r['started_at'] ?? '') ?></td>
                        <td class="py-3 px-4 text-slate-300"><?= e($r['finished_at'] ?? '—') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </section>
</div>
</body>
</html>
