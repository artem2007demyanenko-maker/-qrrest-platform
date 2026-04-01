<?php
/**
 * Growth Experiments Dashboard. Platform owner (project_owner) only.
 * A/B tests and feature flags; tenant-safe.
 */

$config     = require __DIR__ . '/../../app/config.php';
$mainDomain = $config['app']['main_domain'] ?? 'localhost';
$protocol   = $config['app']['protocol'] ?? 'http';

$host = $_SERVER['HTTP_HOST'] ?? '';
$host = preg_replace('/:\d+$/', '', $host);
$isMainHost = (strtolower($host) === strtolower($mainDomain)) || (strtolower($host) === strtolower('www.' . $mainDomain));
if (!$isMainHost) {
    $uri = $_SERVER['REQUEST_URI'] ?? '/project-admin/experiments_dashboard.php';
    header('Location: ' . $protocol . '://' . $mainDomain . $uri);
    exit;
}

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/schema_guard.php';

require_login();

$currentUser = function_exists('auth_user') ? auth_user() : null;
if (!$currentUser || ($currentUser['global_role'] ?? null) !== 'project_owner') {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>403 Forbidden</title></head><body><h1>403 Forbidden</h1><p>Platform owner only.</p></body></html>';
    exit;
}

require_once __DIR__ . '/../../app/experiments.php';

if (!function_exists('e')) {
    function e($v): string {
        return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    }
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

$message = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfOk = isset($_POST['csrf'], $_SESSION['csrf']) && hash_equals((string) $_SESSION['csrf'], (string) $_POST['csrf']);
    if (!$csrfOk) {
        $error = 'Invalid request. Please try again.';
    } else {
    $action = $_POST['action'] ?? '';
    if ($action === 'create' && !empty($_POST['name'])) {
        $id = create_experiment([
            'name'               => $_POST['name'],
            'description'        => $_POST['description'] ?? '',
            'type'               => $_POST['type'] ?? 'ab_test',
            'target_percentage'  => (int) ($_POST['target_percentage'] ?? 100),
            'flag_key'           => $_POST['flag_key'] ?? null,
        ]);
        if ($id > 0) {
            $message = 'Experiment created.';
        } else {
            $error = 'Failed to create experiment.';
        }
    } elseif ($action === 'start' && !empty($_POST['experiment_id'])) {
        $ok = update_experiment_status((int) $_POST['experiment_id'], 'running');
        $message = $ok ? 'Experiment started.' : 'Failed to start.';
        if (!$ok) {
            $error = 'Failed to start experiment.';
        }
    } elseif ($action === 'pause' && !empty($_POST['experiment_id'])) {
        $ok = update_experiment_status((int) $_POST['experiment_id'], 'paused');
        $message = $ok ? 'Experiment paused.' : 'Failed to pause.';
        if (!$ok) {
            $error = 'Failed to pause experiment.';
        }
    }
    }
}

$experiments = get_experiments_list();
$appName = $config['app']['name'] ?? 'QR-Rest Cloud';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Growth Experiments — <?= e($appName) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?= brand_head_tags() ?>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-950 text-slate-50">
<div class="max-w-6xl mx-auto px-4 py-6 sm:py-8">

    <header class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-slate-900/80 border border-slate-700 text-[11px] text-slate-300 mb-2">
                Platform owner • Experiments
            </div>
            <h1 class="text-2xl sm:text-3xl font-semibold text-slate-50 mb-1">Growth Experiments</h1>
            <p class="text-sm text-slate-400">A/B tests and feature flags. Tenant-isolated.</p>
        </div>
        <div class="flex items-center gap-2">
            <a href="/project-admin/" class="inline-flex items-center px-3 py-1.5 rounded-xl bg-slate-800 border border-slate-700 text-sm text-slate-200 hover:border-sky-500 hover:text-sky-200 transition">← Dashboard</a>
        </div>
    </header>

    <?php if ($message): ?>
        <div class="mb-4 rounded-lg border border-emerald-700 bg-emerald-900/30 px-4 py-2 text-sm text-emerald-200"><?= e($message) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="mb-4 rounded-lg border border-rose-700 bg-rose-900/30 px-4 py-2 text-sm text-rose-200"><?= e($error) ?></div>
    <?php endif; ?>

    <section class="rounded-xl border border-gray-800 bg-[#121826] shadow-xl p-5 mb-6">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-4">
            <h2 class="text-lg font-semibold text-slate-50">Experiments list</h2>
            <button type="button" onclick="document.getElementById('createModal').classList.remove('hidden')" class="inline-flex items-center px-3 py-1.5 rounded-xl bg-sky-600 hover:bg-sky-500 text-sm text-white transition">
                Create experiment
            </button>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm text-left">
                <thead class="text-slate-400 border-b border-slate-700">
                    <tr>
                        <th class="pb-2 pr-4">Name</th>
                        <th class="pb-2 pr-4">Type</th>
                        <th class="pb-2 pr-4">Status</th>
                        <th class="pb-2 pr-4">Target %</th>
                        <th class="pb-2 pr-4">Started</th>
                        <th class="pb-2">Actions</th>
                    </tr>
                </thead>
                <tbody class="text-slate-200">
                    <?php if (empty($experiments)): ?>
                        <tr><td colspan="6" class="py-4 text-slate-500">No experiments yet. Create one to start.</td></tr>
                    <?php else: ?>
                        <?php foreach ($experiments as $exp): ?>
                            <tr class="border-b border-slate-800/80">
                                <td class="py-3 pr-4 font-medium text-slate-50"><?= e($exp['name']) ?></td>
                                <td class="py-3 pr-4"><?= e($exp['type']) ?></td>
                                <td class="py-3 pr-4">
                                    <span class="inline-flex px-2 py-0.5 rounded text-xs <?=
                                        $exp['status'] === 'running' ? 'bg-emerald-900/50 text-emerald-300 border border-emerald-700' :
                                        ($exp['status'] === 'paused' ? 'bg-amber-900/50 text-amber-300 border border-amber-700' :
                                        ($exp['status'] === 'completed' ? 'bg-slate-700 text-slate-300' : 'bg-slate-800 text-slate-400'))
                                    ?>"><?= e($exp['status']) ?></span>
                                </td>
                                <td class="py-3 pr-4"><?= (int) $exp['target_percentage'] ?>%</td>
                                <td class="py-3 pr-4 text-slate-400"><?= $exp['started_at'] ? date('Y-m-d H:i', strtotime($exp['started_at'])) : '—' ?></td>
                                <td class="py-3 flex flex-wrap gap-1">
                                    <?php if ($exp['status'] === 'draft' || $exp['status'] === 'paused'): ?>
                                        <form method="post" class="inline" onsubmit="return confirm('Start this experiment?');">
                                            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf'] ?? '') ?>">
                                            <input type="hidden" name="action" value="start">
                                            <input type="hidden" name="experiment_id" value="<?= (int) $exp['id'] ?>">
                                            <button type="submit" class="px-2 py-1 rounded bg-emerald-700 hover:bg-emerald-600 text-xs text-white">Start</button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ($exp['status'] === 'running'): ?>
                                        <form method="post" class="inline" onsubmit="return confirm('Pause this experiment?');">
                                            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf'] ?? '') ?>">
                                            <input type="hidden" name="action" value="pause">
                                            <input type="hidden" name="experiment_id" value="<?= (int) $exp['id'] ?>">
                                            <button type="submit" class="px-2 py-1 rounded bg-amber-700 hover:bg-amber-600 text-xs text-white">Pause</button>
                                        </form>
                                    <?php endif; ?>
                                    <a href="/project-admin/experiment_results.php?id=<?= (int) $exp['id'] ?>" class="inline-flex px-2 py-1 rounded bg-slate-700 hover:bg-slate-600 text-xs text-slate-200">View results</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>

<!-- Create experiment modal -->
<div id="createModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4">
    <div class="rounded-xl border border-slate-700 bg-[#121826] shadow-2xl w-full max-w-md p-6">
        <h3 class="text-lg font-semibold text-slate-50 mb-4">Create experiment</h3>
        <form method="post" action="">
            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf'] ?? '') ?>">
            <input type="hidden" name="action" value="create">
            <div class="space-y-3">
                <div>
                    <label class="block text-xs text-slate-400 mb-1">Name</label>
                    <input type="text" name="name" required class="w-full rounded-lg border border-slate-600 bg-slate-900 text-slate-100 px-3 py-2 text-sm" placeholder="e.g. New upsell engine">
                </div>
                <div>
                    <label class="block text-xs text-slate-400 mb-1">Description</label>
                    <textarea name="description" rows="2" class="w-full rounded-lg border border-slate-600 bg-slate-900 text-slate-100 px-3 py-2 text-sm" placeholder="Optional"></textarea>
                </div>
                <div>
                    <label class="block text-xs text-slate-400 mb-1">Type</label>
                    <select name="type" class="w-full rounded-lg border border-slate-600 bg-slate-900 text-slate-100 px-3 py-2 text-sm">
                        <option value="ab_test">A/B test</option>
                        <option value="feature_flag">Feature flag</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs text-slate-400 mb-1">Target % (restaurants to include)</label>
                    <input type="number" name="target_percentage" value="100" min="1" max="100" class="w-full rounded-lg border border-slate-600 bg-slate-900 text-slate-100 px-3 py-2 text-sm">
                </div>
                <div id="flagKeyRow" class="hidden">
                    <label class="block text-xs text-slate-400 mb-1">Flag key (for is_feature_enabled)</label>
                    <input type="text" name="flag_key" class="w-full rounded-lg border border-slate-600 bg-slate-900 text-slate-100 px-3 py-2 text-sm" placeholder="e.g. new_upsell_engine">
                </div>
            </div>
            <div class="mt-4 flex gap-2">
                <button type="submit" class="px-3 py-1.5 rounded-lg bg-sky-600 hover:bg-sky-500 text-sm text-white">Create</button>
                <button type="button" onclick="document.getElementById('createModal').classList.add('hidden')" class="px-3 py-1.5 rounded-lg bg-slate-700 hover:bg-slate-600 text-sm text-slate-200">Cancel</button>
            </div>
        </form>
    </div>
</div>
<script>
document.querySelector('select[name="type"]').addEventListener('change', function() {
    document.getElementById('flagKeyRow').classList.toggle('hidden', this.value !== 'feature_flag');
});
</script>
</body>
</html>
