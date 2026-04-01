<?php
/**
 * View single app error by rid or id. Project owner only.
 */

$rid = bin2hex(random_bytes(4));
set_exception_handler(function (Throwable $e) use ($rid) {
    error_log('STABILITY_ERROR project-admin/error_view.php rid=' . $rid . ' ' . $e->getMessage());
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
    header('Location: ' . $protocol . '://' . $mainDomain . ($_SERVER['REQUEST_URI'] ?? '/project-admin/error_view.php'));
    exit;
}

require_once __DIR__ . '/../../app/bootstrap.php';
require_login();
require_role(['project_owner']);

if (!function_exists('e')) {
    function e($v): string {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

$viewRid = trim((string)($_GET['rid'] ?? ''));
$viewId = (int)($_GET['id'] ?? 0);
$row = null;

try {
    $pdo = db();
    if ($viewId > 0) {
        $stmt = $pdo->prepare("SELECT id, level, source, message, context_json, rid, created_at FROM app_error_logs WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $viewId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } elseif ($viewRid !== '') {
        $stmt = $pdo->prepare("SELECT id, level, source, message, context_json, rid, created_at FROM app_error_logs WHERE rid = :rid ORDER BY id DESC LIMIT 1");
        $stmt->execute(['rid' => $viewRid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    $row = null;
}

$contextPretty = '';
if ($row && !empty($row['context_json'])) {
    $decoded = json_decode($row['context_json'], true);
    $contextPretty = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?= brand_head_tags() ?>
    <title><?= $row ? 'Ошибка ' . e($row['rid'] ?? $row['id']) : 'Ошибка не найдена' ?> — Diagnostics</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-950 text-slate-50">
<div class="max-w-4xl mx-auto px-4 py-6">
    <header class="mb-6">
        <a href="/project-admin/diagnostics.php" class="text-sm text-slate-400 hover:text-emerald-300">← Диагностика</a>
    </header>

    <?php if (!$row): ?>
    <div class="rounded-2xl border border-slate-800 bg-slate-900/80 p-6 text-center">
        <p class="text-slate-400">Запись не найдена.</p>
        <p class="text-sm text-slate-500 mt-2">Укажите rid или id в запросе.</p>
        <a href="/project-admin/diagnostics.php" class="inline-block mt-4 text-emerald-400 hover:underline">Вернуться к диагностике</a>
    </div>
    <?php else: ?>
    <h1 class="text-xl font-semibold mb-4">Ошибка <?= e($row['rid'] ?? '#' . $row['id']) ?></h1>
    <div class="rounded-2xl border border-slate-800 bg-slate-900/80 p-4 space-y-3 text-sm">
        <div><span class="text-slate-500">created_at</span><br><span class="text-slate-200"><?= e($row['created_at'] ?? '') ?></span></div>
        <div><span class="text-slate-500">level</span><br><span class="text-slate-200"><?= e($row['level'] ?? '') ?></span></div>
        <div><span class="text-slate-500">source</span><br><span class="text-slate-200"><?= e($row['source'] ?? '') ?></span></div>
        <div><span class="text-slate-500">rid</span><br><span class="text-slate-200"><?= e($row['rid'] ?? '—') ?></span></div>
        <div><span class="text-slate-500">message</span><br><pre class="mt-1 p-3 rounded-lg bg-slate-950 text-slate-200 whitespace-pre-wrap break-words"><?= e($row['message'] ?? '') ?></pre></div>
        <?php if ($contextPretty !== ''): ?>
        <div><span class="text-slate-500">context_json</span><br><pre class="mt-1 p-3 rounded-lg bg-slate-950 text-slate-300 text-xs whitespace-pre-wrap break-words font-mono"><?= e($contextPretty) ?></pre></div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>
</body>
</html>
