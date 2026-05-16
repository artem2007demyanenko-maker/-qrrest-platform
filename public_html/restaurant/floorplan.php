<?php

require_once __DIR__ . '/../../app/bootstrap.php';

require_login();
require_current_restaurant();
require_restaurant_role((int)($currentRestaurant['id'] ?? 0), ['owner','admin']);

$pdo = db();
$restId = (int)$currentRestaurant['id'];

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

if (!function_exists('e')) {
    function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

function fp_default_layout(): array {
    return [
        'version' => 1,
        'canvas'  => ['w' => 1200, 'h' => 700, 'grid' => 20],
        'items'   => [], // table placements
        // item: {table_id, x, y, w, h, r, shape}
    ];
}

function fp_decode_layout(?string $json): array {
    if (!$json) return fp_default_layout();
    $d = json_decode($json, true);
    if (!is_array($d)) return fp_default_layout();
    if (empty($d['canvas']) || empty($d['items']) || !is_array($d['items'])) {
        // поддержим старые/битые
        $d = array_merge(fp_default_layout(), $d);
        if (!isset($d['items']) || !is_array($d['items'])) $d['items'] = [];
        if (!isset($d['canvas']) || !is_array($d['canvas'])) $d['canvas'] = fp_default_layout()['canvas'];
    }
    // нормализация
    $d['canvas']['w'] = max(600, (int)($d['canvas']['w'] ?? 1200));
    $d['canvas']['h'] = max(400, (int)($d['canvas']['h'] ?? 700));
    $d['canvas']['grid'] = max(10, min(50, (int)($d['canvas']['grid'] ?? 20)));
    return $d;
}

// ---- данные
$errors = [];
$success = null;

// планы
try {
    $plans = $pdo->prepare("SELECT * FROM restaurant_floorplans WHERE restaurant_id=:r ORDER BY is_active DESC, id DESC");
    $plans->execute([':r' => $restId]);
    $plans = $plans->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    if (function_exists('error_log')) {
        error_log('floorplan load plans ' . $e->getMessage());
    }
    http_response_code(500);
    echo "DB error. Please try again.";
    exit;
}

$activePlan = null;
foreach ($plans as $p) {
    if (!empty($p['is_active'])) { $activePlan = $p; break; }
}
if (!$activePlan && $plans) $activePlan = $plans[0];

// таблица столов
$stmt = $pdo->prepare("
    SELECT t.id, t.name FROM tables AS t
    WHERE t.restaurant_id=:r
    " . qr_public_sql_exclude_delivery($pdo, 't') . "
    ORDER BY t.id ASC
");
$stmt->execute([':r' => $restId]);
$tables = $stmt->fetchAll(PDO::FETCH_ASSOC);

// статусы по столам (активные заказы)
$activeByTable = [];
try {
    $q = $pdo->prepare("
        SELECT o.table_id,
               SUM(CASE WHEN o.order_status IN ('new','accepted','cooking','ready','in_progress') THEN 1 ELSE 0 END) AS active_cnt,
               MAX(
                   CASE
                       WHEN o.order_status = 'ready' THEN 3
                       WHEN o.order_status IN ('accepted','cooking','in_progress') THEN 2
                       WHEN o.order_status = 'new' THEN 1
                       ELSE 0
                   END
               ) AS active_stage
        FROM orders o
        INNER JOIN tables t ON t.id = o.table_id AND t.restaurant_id = o.restaurant_id
        WHERE o.restaurant_id = :r
        " . qr_public_sql_exclude_delivery($pdo, 't') . "
        GROUP BY o.table_id
    ");
    $q->execute([':r' => $restId]);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $tid = (int)$row['table_id'];
        $stage = (int)($row['active_stage'] ?? 0);
        $status = null;
        if ($stage >= 3) {
            $status = 'ready';
        } elseif ($stage === 2) {
            $status = 'cooking';
        } elseif ($stage === 1) {
            $status = 'new';
        }
        $activeByTable[$tid] = [
            'active_cnt' => (int)($row['active_cnt'] ?? 0),
            'active_stage' => $stage,
            'active_status' => $status,
        ];
    }
} catch (Throwable $e) {
    // не критично
}

// ---- POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfOk = isset($_POST['csrf'], $_SESSION['csrf']) && hash_equals((string)$_SESSION['csrf'], (string)$_POST['csrf']);
    if (!$csrfOk) {
        $errors[] = 'Неверный запрос. Обновите страницу и попробуйте снова.';
    } else {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_plan') {
        $name = trim($_POST['name'] ?? '');
        if ($name === '') $name = 'План зала';
        try {
            $layout = json_encode(fp_default_layout(), JSON_UNESCAPED_UNICODE);
            $pdo->beginTransaction();

            // если план первый — делаем активным
            $isFirst = empty($plans) ? 1 : 0;

            $ins = $pdo->prepare("
                INSERT INTO restaurant_floorplans (restaurant_id, name, layout_json, is_active, created_at, updated_at)
                VALUES (:r, :n, :j, :a, NOW(), NOW())
            ");
            $ins->execute([':r'=>$restId, ':n'=>$name, ':j'=>$layout, ':a'=>$isFirst]);

            if ($isFirst) {
                $pdo->prepare("UPDATE restaurant_floorplans SET is_active=0 WHERE restaurant_id=:r AND id <> LAST_INSERT_ID()")
                    ->execute([':r'=>$restId]);
            }

            $pdo->commit();
            header("Location: /restaurant/floorplan.php");
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errors[] = "Ошибка создания плана. Попробуйте позже.";
            if (function_exists('error_log')) {
                error_log('floorplan create_plan ' . $e->getMessage());
            }
        }
    }

    if ($action === 'set_active') {
        $planId = (int)($_POST['plan_id'] ?? 0);
        if ($planId > 0) {
            try {
                $pdo->beginTransaction();
                $pdo->prepare("UPDATE restaurant_floorplans SET is_active=0 WHERE restaurant_id=:r")
                    ->execute([':r'=>$restId]);
                $pdo->prepare("UPDATE restaurant_floorplans SET is_active=1, updated_at=NOW() WHERE id=:id AND restaurant_id=:r")
                    ->execute([':id'=>$planId, ':r'=>$restId]);
                $pdo->commit();
                header("Location: /restaurant/floorplan.php");
                exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $errors[] = "Ошибка активации плана. Попробуйте позже.";
                if (function_exists('error_log')) {
                    error_log('floorplan set_active ' . $e->getMessage());
                }
            }
        }
    }

    if ($action === 'rename_plan') {
        $planId = (int)($_POST['plan_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        if ($planId > 0 && $name !== '') {
            try {
                $pdo->prepare("UPDATE restaurant_floorplans SET name=:n, updated_at=NOW() WHERE id=:id AND restaurant_id=:r")
                    ->execute([':n'=>$name, ':id'=>$planId, ':r'=>$restId]);
                header("Location: /restaurant/floorplan.php");
                exit;
            } catch (Throwable $e) {
                $errors[] = "Ошибка переименования. Попробуйте позже.";
                if (function_exists('error_log')) {
                    error_log('floorplan rename_plan ' . $e->getMessage());
                }
            }
        }
    }

    if ($action === 'delete_plan') {
        $planId = (int)($_POST['plan_id'] ?? 0);
        if ($planId > 0) {
            try {
                $pdo->beginTransaction();
                $pdo->prepare("DELETE FROM restaurant_floorplans WHERE id=:id AND restaurant_id=:r")
                    ->execute([':id'=>$planId, ':r'=>$restId]);


                $check = $pdo->prepare("SELECT id FROM restaurant_floorplans WHERE restaurant_id=:r ORDER BY id DESC LIMIT 1");
                $check->execute([':r'=>$restId]);
                $any = $check->fetch(PDO::FETCH_ASSOC);
                if ($any) {
                    $pdo->prepare("UPDATE restaurant_floorplans SET is_active=0 WHERE restaurant_id=:r")->execute([':r'=>$restId]);
                    $pdo->prepare("UPDATE restaurant_floorplans SET is_active=1 WHERE id=:id AND restaurant_id=:r")->execute([':id'=>(int)$any['id'], ':r'=>$restId]);
                }

                $pdo->commit();
                header("Location: /restaurant/floorplan.php");
                exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $errors[] = "Ошибка удаления плана. Попробуйте позже.";
                if (function_exists('error_log')) {
                    error_log('floorplan delete_plan ' . $e->getMessage());
                }
            }
        }
    }

    if ($action === 'save_layout') {
        $planId = (int)($_POST['plan_id'] ?? 0);
        $layoutJson = (string)($_POST['layout_json'] ?? '');

        if ($planId <= 0) $errors[] = "Не выбран план.";
        if ($layoutJson === '') $errors[] = "Пустой layout_json.";

        if (!$errors) {

            $decoded = fp_decode_layout($layoutJson);
            $allowedIds = [];
            foreach ($tables as $t) {
                $tid = (int)($t['id'] ?? 0);
                if ($tid > 0) {
                    $allowedIds[$tid] = true;
                }
            }
            $items = isset($decoded['items']) && is_array($decoded['items']) ? $decoded['items'] : [];
            $filtered = [];
            foreach ($items as $it) {
                if (!is_array($it)) {
                    continue;
                }
                $tid = (int)($it['table_id'] ?? 0);
                if ($tid > 0 && isset($allowedIds[$tid])) {
                    $filtered[] = $it;
                }
            }
            $decoded['items'] = $filtered;

            $layoutJson = json_encode($decoded, JSON_UNESCAPED_UNICODE);

            try {
                $pdo->prepare("UPDATE restaurant_floorplans SET layout_json=:j, updated_at=NOW() WHERE id=:id AND restaurant_id=:r")
                    ->execute([':j'=>$layoutJson, ':id'=>$planId, ':r'=>$restId]);
                $success = "План зала сохранён.";
  
                foreach ($plans as &$p) {
                    if ((int)$p['id'] === $planId) $p['layout_json'] = $layoutJson;
                }
                if ($activePlan && (int)$activePlan['id']===$planId) $activePlan['layout_json'] = $layoutJson;
            } catch (Throwable $e) {
                $errors[] = "Ошибка сохранения. Попробуйте позже.";
                if (function_exists('error_log')) {
                    error_log('floorplan save_layout ' . $e->getMessage());
                }
            }
        }
    }
    }
}

$activePlanId = $activePlan ? (int)$activePlan['id'] : 0;
$activeLayout = fp_decode_layout($activePlan['layout_json'] ?? null);

$allowedTableIdsView = [];
foreach ($tables as $t) {
    $tid = (int)($t['id'] ?? 0);
    if ($tid > 0) {
        $allowedTableIdsView[$tid] = true;
    }
}
if (!empty($activeLayout['items']) && is_array($activeLayout['items'])) {
    $activeLayout['items'] = array_values(array_filter($activeLayout['items'], static function ($it) use ($allowedTableIdsView) {
        if (!is_array($it)) {
            return false;
        }
        $tid = (int)($it['table_id'] ?? 0);
        return $tid > 0 && isset($allowedTableIdsView[$tid]);
    }));
}

// быстрые словари
$tableNameById = [];
foreach ($tables as $t) $tableNameById[(int)$t['id']] = (string)$t['name'];

?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>План зала — <?= e($currentRestaurant['name']) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?= brand_head_tags() ?>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .glass {
            background: rgba(2,6,23,.72);
            backdrop-filter: blur(18px);
            border: 1px solid rgba(148,163,184,.16);
        }
        .grid-bg {
            background-image:
                linear-gradient(to right, rgba(148,163,184,.10) 1px, transparent 1px),
                linear-gradient(to bottom, rgba(148,163,184,.10) 1px, transparent 1px);
            background-size: var(--grid) var(--grid);
            background-position: 0 0;
        }
        .soft-shadow { box-shadow: 0 20px 80px rgba(0,0,0,.45); }
        .table-node { touch-action: none; user-select: none; }
        .handle {
            width: 10px; height: 10px;
            border-radius: 999px;
            background: rgba(16,185,129,.95);
            border: 2px solid rgba(2,6,23,.9);
            position: absolute;
        }
        .handle.br { right: -6px; bottom: -6px; cursor: nwse-resize; }
        .handle.tr { right: -6px; top: -6px; cursor: nesw-resize; }
        .handle.bl { left: -6px; bottom: -6px; cursor: nesw-resize; }
        .handle.tl { left: -6px; top: -6px; cursor: nwse-resize; }
        .badge-dot { width: 6px; height: 6px; border-radius: 999px; display:inline-block; }
    </style>
</head>
<body class="min-h-screen bg-gradient-to-br from-slate-950 via-slate-950 to-slate-900 text-slate-50 flex flex-col md:flex-row">
<?php
$restaurantSidebarActive = 'floorplan';
$restaurantSidebarName = (string)($currentRestaurant['name'] ?? 'Ресторан');
require __DIR__ . '/_sidebar_mobile.php';
require __DIR__ . '/_sidebar.php';
?>
<div class="flex-1 min-w-0">
<div class="max-w-7xl mx-auto px-4 py-4">
    <?php
    $operationalNavActive = 'floorplan';
    require __DIR__ . '/_restaurant_cabinet_context.php';
    require __DIR__ . '/_restaurant_operational_nav.php';
    ?>

    <!-- header -->
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3 mb-4">
        <div>
            <div class="mb-3"><?= brand_header_cluster_html(false, 'w-8 h-8 text-slate-400') ?></div>
            <h1 class="text-2xl font-bold tracking-tight">План зала</h1>
            <div class="text-xs text-slate-500 mt-1">
                Настройка расположения столов и синхронизация со staff-картой
            </div>
        </div>
        <div class="flex items-center gap-2">
            <a href="/restaurant/dashboard.php" class="px-3 py-2 rounded-2xl glass hover:border-emerald-500/40 text-sm">← Назад</a>
            <a href="/restaurant/tables.php" class="px-3 py-2 rounded-2xl glass hover:border-emerald-500/40 text-sm">Столы и QR</a>
        </div>
    </div>

    <?php if ($success): ?>
        <div class="mb-3 rounded-3xl bg-emerald-500/10 border border-emerald-500/50 px-4 py-3 text-sm text-emerald-100">
            <?= e($success) ?>
        </div>
    <?php endif; ?>

    <?php if ($errors): ?>
        <div class="mb-3 rounded-3xl bg-red-500/10 border border-red-500/60 px-4 py-3 text-sm text-red-100 space-y-1">
            <?php foreach ($errors as $er): ?><div><?= e($er) ?></div><?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="grid lg:grid-cols-12 gap-3">

        <!-- left: plans + tables -->
        <aside class="lg:col-span-4 space-y-3">
            <!-- plans -->
            <div class="glass rounded-3xl soft-shadow p-4">
                <div class="flex items-center justify-between mb-3">
                    <div class="text-sm font-semibold">Планы</div>
                    <span class="text-[11px] text-slate-500">активный видят сотрудники</span>
                </div>

                <form method="post" class="flex gap-2 mb-3">
                    <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                    <input type="hidden" name="action" value="create_plan">
                    <input name="name" placeholder="Название плана (например, Зал 1)" class="flex-1 rounded-2xl bg-slate-950/80 border border-slate-800 px-3 py-2 text-sm">
                    <button class="px-3 py-2 rounded-2xl bg-emerald-500 hover:bg-emerald-400 text-slate-950 text-sm font-semibold">
                        + Создать
                    </button>
                </form>

                <?php if (!$plans): ?>
                    <div class="text-sm text-slate-400">Планов ещё нет. Создайте первый.</div>
                <?php else: ?>
                    <div class="space-y-2">
                        <?php foreach ($plans as $p): ?>
                            <?php $pid = (int)$p['id']; $isActive = !empty($p['is_active']); ?>
                            <div class="rounded-2xl border border-slate-800 bg-slate-950/60 px-3 py-2">
                                <div class="flex items-center justify-between gap-2">
                                    <div class="min-w-0">
                                        <div class="text-sm font-semibold truncate">
                                            <?= e($p['name'] ?? ('План #' . $pid)) ?>
                                        </div>
                                        <div class="text-[11px] text-slate-500">
                                            ID: <?= $pid ?><?= $isActive ? ' · активный' : '' ?>
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-1.5">
                                        <?php if (!$isActive): ?>
                                            <form method="post">
                                                <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                                                <input type="hidden" name="action" value="set_active">
                                                <input type="hidden" name="plan_id" value="<?= $pid ?>">
                                                <button class="px-2.5 py-1.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-[11px]">Сделать активным</button>
                                            </form>
                                        <?php else: ?>
                                            <span class="px-2 py-1 rounded-full bg-emerald-500/12 border border-emerald-500/40 text-[11px] text-emerald-200">
                                                Активный
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="mt-2 flex flex-wrap gap-2">
                                    <form method="post" class="flex gap-2 flex-1 min-w-[220px]">
                                        <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                                        <input type="hidden" name="action" value="rename_plan">
                                        <input type="hidden" name="plan_id" value="<?= $pid ?>">
                                        <input name="name" value="<?= e($p['name'] ?? '') ?>" class="flex-1 rounded-xl bg-slate-950/80 border border-slate-800 px-2.5 py-1.5 text-[12px]">
                                        <button class="px-3 py-1.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-[11px]">OK</button>
                                    </form>

                                    <form method="post" onsubmit="return confirm('Удалить план?');">
                                        <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                                        <input type="hidden" name="action" value="delete_plan">
                                        <input type="hidden" name="plan_id" value="<?= $pid ?>">
                                        <button class="px-3 py-1.5 rounded-xl bg-red-500/10 border border-red-500/50 text-red-100 text-[11px] hover:bg-red-500/15">
                                            Удалить
                                        </button>
                                    </form>

                                    <a href="/restaurant/floorplan.php#editor" class="px-3 py-1.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-[11px]">
                                        Редактировать
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- tables -->
            <div class="glass rounded-3xl soft-shadow p-4">
                <div class="flex items-center justify-between mb-2">
                    <div class="text-sm font-semibold">Столы</div>
                    <div class="text-[11px] text-slate-500">перетащи на план</div>
                </div>
                <div class="text-[11px] text-slate-500 mb-3">
                    Подсказка: включи “Режим редактирования”, затем тяни стол из списка в зал.
                </div>

                <div class="space-y-2 max-h-[420px] overflow-y-auto pr-1">
                    <?php foreach ($tables as $t): ?>
                        <?php
                        $tid = (int)$t['id'];
                        $stat = $activeByTable[$tid] ?? ['active_cnt'=>0,'active_status'=>null];
                        $cnt = (int)$stat['active_cnt'];
                        $st  = $stat['active_status'];
                        $dot = 'bg-slate-500';
                        $txt = 'Нет активных';
                        if ($cnt > 0) {
                            if ($st === 'ready') { $dot = 'bg-emerald-400'; $txt = 'Готово'; }
                            elseif ($st === 'cooking') { $dot = 'bg-sky-400'; $txt = 'Готовится'; }
                            else { $dot = 'bg-amber-400'; $txt = 'Новый'; }
                        }
                        ?>
                        <button
                            type="button"
                            class="w-full text-left rounded-2xl bg-slate-950/60 border border-slate-800 hover:border-emerald-500/35 px-3 py-2 flex items-center justify-between gap-3 fp-palette-item"
                            data-table-id="<?= $tid ?>"
                            data-table-name="<?= e($t['name']) ?>"
                        >
                            <div class="min-w-0">
                                <div class="text-sm font-semibold truncate"><?= e($t['name']) ?></div>
                                <div class="text-[11px] text-slate-500">ID: <?= $tid ?></div>
                            </div>
                            <div class="text-right">
                                <div class="text-[11px] text-slate-400">
                                    <span class="badge-dot <?= $dot ?>"></span>
                                    <?= e($txt) ?>
                                </div>
                                <?php if ($cnt > 0): ?>
                                    <div class="text-[11px] text-slate-500">активных: <?= $cnt ?></div>
                                <?php endif; ?>
                            </div>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>
        </aside>

        <!-- right: editor -->
        <main class="lg:col-span-8 space-y-3" id="editor">
            <div class="glass rounded-3xl soft-shadow p-4">
                <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                    <div>
                        <div class="text-sm font-semibold">Редактор карты</div>
                        <div class="text-[11px] text-slate-500">
                            План: <span class="text-slate-200"><?= e($activePlan['name'] ?? '—') ?></span>
                            <?php if ($activePlanId): ?> · ID <?= $activePlanId ?><?php endif; ?>
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <button id="btn-fit" type="button" class="px-3 py-2 rounded-2xl bg-slate-800 hover:bg-slate-700 text-sm">Вписать</button>
                        <button id="btn-zoom-in" type="button" class="px-3 py-2 rounded-2xl bg-slate-800 hover:bg-slate-700 text-sm">+</button>
                        <button id="btn-zoom-out" type="button" class="px-3 py-2 rounded-2xl bg-slate-800 hover:bg-slate-700 text-sm">−</button>

                        <label class="inline-flex items-center gap-2 px-3 py-2 rounded-2xl bg-slate-950/70 border border-slate-800">
                            <input id="edit-mode" type="checkbox" class="rounded bg-slate-950 border-slate-700" checked>
                            <span class="text-sm">Режим редактирования</span>
                        </label>

                        <form method="post" id="save-form">
                            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                            <input type="hidden" name="action" value="save_layout">
                            <input type="hidden" name="plan_id" value="<?= $activePlanId ?>">
                            <input type="hidden" name="layout_json" id="layout_json">
                            <button type="submit" class="px-4 py-2 rounded-2xl bg-emerald-500 hover:bg-emerald-400 text-slate-950 text-sm font-semibold">
                                Сохранить
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="glass rounded-3xl soft-shadow p-3 md:p-4">
                <div class="flex items-center justify-between mb-3">
                    <div class="text-[11px] text-slate-500">
                        Перетащи стол на карту. Потом можно двигать, менять размер и форму.
                    </div>
                    <div class="text-[11px] text-slate-500">
                        Двойной клик по столу — форма (круг/прямоуг)
                    </div>
                </div>

                <div class="rounded-3xl overflow-hidden border border-slate-800 bg-slate-950/40">
                    <div id="viewport" class="relative w-full h-[520px] overflow-hidden">
                        <div id="canvas"
                             class="absolute left-0 top-0 grid-bg"
                             style="width:<?= (int)$activeLayout['canvas']['w'] ?>px;height:<?= (int)$activeLayout['canvas']['h'] ?>px; --grid: <?= (int)$activeLayout['canvas']['grid'] ?>px;">
                        </div>
                    </div>
                </div>

                <div class="mt-3 flex flex-wrap items-center gap-2 text-[11px] text-slate-500">
                    <span class="inline-flex items-center gap-1 px-2 py-1 rounded-full bg-slate-900 border border-slate-800">
                        <span class="badge-dot bg-amber-400"></span> новый заказ
                    </span>
                    <span class="inline-flex items-center gap-1 px-2 py-1 rounded-full bg-slate-900 border border-slate-800">
                        <span class="badge-dot bg-sky-400"></span> готовится
                    </span>
                    <span class="inline-flex items-center gap-1 px-2 py-1 rounded-full bg-slate-900 border border-slate-800">
                        <span class="badge-dot bg-emerald-400"></span> готово
                    </span>
                    <span class="inline-flex items-center gap-1 px-2 py-1 rounded-full bg-slate-900 border border-slate-800">
                        <span class="badge-dot bg-slate-500"></span> нет активных
                    </span>
                    <span class="ml-auto text-[11px] text-slate-600">
                        Совет: размер меняется за “точки” по углам
                    </span>
                </div>
            </div>
        </main>
    </div>
</div>
</div>

<script>
(function(){
    const TABLES = <?= json_encode($tables, JSON_UNESCAPED_UNICODE) ?>;
    const TABLE_NAME_BY_ID = <?= json_encode($tableNameById, JSON_UNESCAPED_UNICODE) ?>;
    const ACTIVE_BY_TABLE = <?= json_encode($activeByTable, JSON_UNESCAPED_UNICODE) ?>;

    const initialLayout = <?= json_encode($activeLayout, JSON_UNESCAPED_UNICODE) ?>;

    const viewport = document.getElementById('viewport');
    const canvas = document.getElementById('canvas');
    const layoutInput = document.getElementById('layout_json');
    const editModeEl = document.getElementById('edit-mode');

    const btnFit = document.getElementById('btn-fit');
    const btnIn = document.getElementById('btn-zoom-in');
    const btnOut = document.getElementById('btn-zoom-out');

    const grid = Math.max(10, Math.min(50, (initialLayout.canvas?.grid || 20)));
    const state = JSON.parse(JSON.stringify(initialLayout));

    let scale = 1;
    let offsetX = 0;
    let offsetY = 0;

    function clamp(v, a, b){ return Math.max(a, Math.min(b, v)); }
    function snap(v){ return Math.round(v / grid) * grid; }

    function statusMeta(tableId){
        const s = ACTIVE_BY_TABLE[String(tableId)] || ACTIVE_BY_TABLE[tableId] || {active_cnt:0, active_status:null};
        const cnt = Number(s.active_cnt || 0);
        const st = s.active_status;
        if (cnt > 0) {
            if (st === 'ready') return {dot:'bg-emerald-400', ring:'ring-emerald-400/35', label:'Готово'};
            if (st === 'cooking') return {dot:'bg-sky-400', ring:'ring-sky-400/35', label:'Готовится'};
            return {dot:'bg-amber-400', ring:'ring-amber-400/35', label:'Новый'};
        }
        return {dot:'bg-slate-500', ring:'ring-slate-500/25', label:'Нет активных'};
    }

    function applyTransform(){
        canvas.style.transform = `translate(${offsetX}px, ${offsetY}px) scale(${scale})`;
        canvas.style.transformOrigin = '0 0';
    }

    function fit(){
        const vw = viewport.clientWidth;
        const vh = viewport.clientHeight;
        const cw = state.canvas.w;
        const ch = state.canvas.h;

        const s = Math.min(vw / cw, vh / ch);
        scale = clamp(s, 0.35, 1.35);

        offsetX = (vw - cw * scale) / 2;
        offsetY = (vh - ch * scale) / 2;
        applyTransform();
    }

    function render(){
        // очистка предыдущих нод, но оставим сам canvas bg
        canvas.querySelectorAll('.table-node').forEach(n => n.remove());

        (state.items || []).forEach((it) => {
            const tableId = Number(it.table_id || 0);
            const name = TABLE_NAME_BY_ID[String(tableId)] || TABLE_NAME_BY_ID[tableId] || ('Стол #' + tableId);
            const meta = statusMeta(tableId);

            const el = document.createElement('div');
            el.className =
                'table-node absolute rounded-2xl border border-slate-700/70 bg-slate-950/80 ' +
                'ring-2 ' + meta.ring + ' shadow-lg shadow-black/30';

            const w = Math.max(80, Number(it.w||120));
            const h = Math.max(60, Number(it.h||90));
            const x = Number(it.x||0);
            const y = Number(it.y||0);
            const r = Number(it.r||0);
            const shape = it.shape || 'rect';

            el.style.left = x + 'px';
            el.style.top  = y + 'px';
            el.style.width = w + 'px';
            el.style.height = h + 'px';
            el.style.transform = `rotate(${r}deg)`;
            el.dataset.tableId = String(tableId);

            if (shape === 'circle') {
                el.style.borderRadius = '9999px';
            }

            el.innerHTML = `
                <div class="h-full w-full flex flex-col items-center justify-center px-2 text-center">
                    <div class="flex items-center gap-2">
                        <span class="w-2 h-2 rounded-full ${meta.dot}"></span>
                        <div class="text-sm font-semibold text-slate-100 truncate">${escapeHtml(name)}</div>
                    </div>
                    <div class="text-[11px] text-slate-500 mt-0.5">${escapeHtml(meta.label)}</div>
                </div>
            `;

            // handles
            ['tl','tr','bl','br'].forEach(pos=>{
                const h = document.createElement('div');
                h.className = 'handle ' + pos;
                h.dataset.handle = pos;
                el.appendChild(h);
            });

            // dblclick toggle shape
            el.addEventListener('dblclick', (ev)=>{
                ev.preventDefault();
                const idx = state.items.findIndex(a => Number(a.table_id) === tableId);
                if (idx >= 0) {
                    state.items[idx].shape = (state.items[idx].shape === 'circle') ? 'rect' : 'circle';
                    render();
                    syncInput();
                }
            });

            makeInteractive(el);
            canvas.appendChild(el);
        });
    }

    function escapeHtml(s){
        return String(s)
            .replace(/&/g,'&amp;').replace(/</g,'&lt;')
            .replace(/>/g,'&gt;').replace(/"/g,'&quot;')
            .replace(/'/g,'&#039;');
    }

    function findItem(tableId){
        return state.items.find(it => Number(it.table_id) === Number(tableId));
    }

    function syncInput(){
        if (!layoutInput) return;
        layoutInput.value = JSON.stringify(state);
    }

    function addTableToPlan(tableId, name){
        tableId = Number(tableId);
        if (!tableId) return;

        if (findItem(tableId)) return; // уже на плане

        // центр видимой области
        const vw = viewport.clientWidth;
        const vh = viewport.clientHeight;
        const cx = (vw/2 - offsetX) / scale;
        const cy = (vh/2 - offsetY) / scale;

        const w = 140, h = 100;
        const x = snap(clamp(cx - w/2, 0, state.canvas.w - w));
        const y = snap(clamp(cy - h/2, 0, state.canvas.h - h));

        state.items.push({table_id: tableId, x, y, w, h, r: 0, shape: 'rect'});
        render();
        syncInput();
    }

    function makeInteractive(el){
        let drag = null;

        el.addEventListener('pointerdown', (ev)=>{
            if (!editModeEl.checked) return;

            const tableId = Number(el.dataset.tableId || 0);
            const item = findItem(tableId);
            if (!item) return;

            const handle = ev.target && ev.target.dataset ? ev.target.dataset.handle : null;

            const rect = el.getBoundingClientRect();
            const startX = ev.clientX;
            const startY = ev.clientY;

            ev.preventDefault();
            el.setPointerCapture(ev.pointerId);

            drag = {
                type: handle ? 'resize' : 'move',
                handle,
                tableId,
                startX, startY,
                x: Number(item.x), y: Number(item.y),
                w: Number(item.w), h: Number(item.h)
            };
        });

        el.addEventListener('pointermove', (ev)=>{
            if (!drag) return;

            const item = findItem(drag.tableId);
            if (!item) return;

            const dx = (ev.clientX - drag.startX) / scale;
            const dy = (ev.clientY - drag.startY) / scale;

            if (drag.type === 'move') {
                item.x = snap(clamp(drag.x + dx, 0, state.canvas.w - item.w));
                item.y = snap(clamp(drag.y + dy, 0, state.canvas.h - item.h));
            } else {
                const minW = 80, minH = 60;
                let nx = drag.x, ny = drag.y, nw = drag.w, nh = drag.h;

                if (drag.handle === 'br') { nw = drag.w + dx; nh = drag.h + dy; }
                if (drag.handle === 'tr') { nw = drag.w + dx; nh = drag.h - dy; ny = drag.y + dy; }
                if (drag.handle === 'bl') { nw = drag.w - dx; nh = drag.h + dy; nx = drag.x + dx; }
                if (drag.handle === 'tl') { nw = drag.w - dx; nh = drag.h - dy; nx = drag.x + dx; ny = drag.y + dy; }

                nw = Math.max(minW, nw);
                nh = Math.max(minH, nh);

                // границы
                nx = clamp(nx, 0, state.canvas.w - nw);
                ny = clamp(ny, 0, state.canvas.h - nh);

                item.x = snap(nx);
                item.y = snap(ny);
                item.w = snap(nw);
                item.h = snap(nh);
            }

            el.style.left = item.x + 'px';
            el.style.top  = item.y + 'px';
            el.style.width = item.w + 'px';
            el.style.height = item.h + 'px';
        });

        function end(ev){
            if (!drag) return;
            const item = findItem(drag.tableId);
            if (item) {
                // финальная нормализация
                item.x = snap(item.x); item.y = snap(item.y);
                item.w = Math.max(80, snap(item.w));
                item.h = Math.max(60, snap(item.h));
            }
            drag = null;
            syncInput();
        }

        el.addEventListener('pointerup', end);
        el.addEventListener('pointercancel', end);

        // context menu — удалить со схемы (не из БД)
        el.addEventListener('contextmenu', (ev)=>{
            if (!editModeEl.checked) return;
            ev.preventDefault();
            const tableId = Number(el.dataset.tableId || 0);
            if (!tableId) return;
            if (!confirm('Убрать стол с плана? (сам стол в системе останется)')) return;
            state.items = (state.items || []).filter(x => Number(x.table_id) !== tableId);
            render();
            syncInput();
        });
    }

    // palette click: add table
    document.querySelectorAll('.fp-palette-item').forEach(btn=>{
        btn.addEventListener('click', ()=>{
            if (!editModeEl.checked) return;
            addTableToPlan(btn.dataset.tableId, btn.dataset.tableName);
        });
    });

    // zoom controls
    btnIn?.addEventListener('click', ()=>{ scale = clamp(scale * 1.12, 0.35, 2.0); applyTransform(); });
    btnOut?.addEventListener('click', ()=>{ scale = clamp(scale / 1.12, 0.35, 2.0); applyTransform(); });
    btnFit?.addEventListener('click', fit);

    // pan with space + drag
    let pan = null;
    viewport.addEventListener('pointerdown', (ev)=>{
        if (!ev.shiftKey) return; // Shift = панорамирование
        ev.preventDefault();
        viewport.setPointerCapture(ev.pointerId);
        pan = {sx: ev.clientX, sy: ev.clientY, ox: offsetX, oy: offsetY};
    });
    viewport.addEventListener('pointermove', (ev)=>{
        if (!pan) return;
        offsetX = pan.ox + (ev.clientX - pan.sx);
        offsetY = pan.oy + (ev.clientY - pan.sy);
        applyTransform();
    });
    viewport.addEventListener('pointerup', ()=>{ pan = null; });
    viewport.addEventListener('pointercancel', ()=>{ pan = null; });

    // mouse wheel zoom
    viewport.addEventListener('wheel', (ev)=>{
        if (!ev.ctrlKey) return; // Ctrl+Wheel
        ev.preventDefault();
        const delta = ev.deltaY > 0 ? 0.92 : 1.08;
        scale = clamp(scale * delta, 0.35, 2.0);
        applyTransform();
    }, {passive:false});

    // init
    syncInput();
    render();
    fit();
})();
</script>
</body>
</html>
