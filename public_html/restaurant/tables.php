<?php

require_once __DIR__ . '/../../app/bootstrap.php';

require_login();
require_current_restaurant();
require_restaurant_role((int)($currentRestaurant['id'] ?? 0), ['owner', 'admin']);

$pdo        = db();
$errors     = [];
$success    = null;
$hasQrCodePathCol = function_exists('db_column_exists') && db_column_exists('tables', 'qr_code_path');
$postAction = null;

if (!empty($_SESSION['tables_flash_success'])) {
    $success = (string)$_SESSION['tables_flash_success'];
    unset($_SESSION['tables_flash_success']);
}
if (!empty($_SESSION['tables_flash_errors']) && is_array($_SESSION['tables_flash_errors'])) {
    $errors = array_values(array_map(static fn($v) => (string)$v, $_SESSION['tables_flash_errors']));
    unset($_SESSION['tables_flash_errors']);
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfOk = isset($_POST['csrf'], $_SESSION['csrf']) && hash_equals((string)$_SESSION['csrf'], (string)$_POST['csrf']);
    if (!$csrfOk) {
        $errors[] = 'Неверный запрос. Обновите страницу и попробуйте снова.';
    } else {
    $action = $_POST['action'] ?? '';
    $postAction = (string)$action;


    if ($action === 'add_table') {
        $name = trim($_POST['name'] ?? '');

        if ($name === '') {
            $errors[] = 'Введите название стола.';
        }
        if (!$errors && function_exists('qr_public_is_delivery_table_row') && qr_public_is_delivery_table_row(['name' => $name])) {
            $errors[] = 'Это название зарезервировано для доставки. Выберите другое.';
        }

        if (!$errors) {

            $stmt = $pdo->prepare("
                INSERT INTO tables (restaurant_id, name, created_at)
                VALUES (:rest, :name, NOW())
            ");
            $stmt->execute([
                'rest' => $currentRestaurant['id'],
                'name' => $name,
            ]);
            $tableId = (int)$pdo->lastInsertId();


            $table = [
                'id'   => $tableId,
                'name' => $name,
            ];
            try {
                $qrPath = generate_table_qr_image($currentRestaurant, $table);
            } catch (Throwable $e) {
                $qrPath = '';
                $errors[] = 'Стол создан, но QR пока не сгенерирован. Попробуйте позже.';
                if (function_exists('error_log')) {
                    error_log('TABLES_QR_GENERATE_ADD_FAIL rid=' . (int)$currentRestaurant['id'] . ' table=' . (int)$tableId . ' ' . $e->getMessage());
                }
            }


            if ($hasQrCodePathCol) {
                $stmt = $pdo->prepare("
                    UPDATE tables
                    SET qr_code_path = :qr
                    WHERE id = :id AND restaurant_id = :rest
                ");
                $stmt->execute([
                    'qr'   => $qrPath,
                    'id'   => $tableId,
                    'rest' => $currentRestaurant['id'],
                ]);
            }

            $success = 'Стол добавлен, QR-код сгенерирован.';
            log_action(auth_user()['id'] ?? null, $currentRestaurant['id'],
                'create_table', "Стол {$name} (#{$tableId})");
        }
    }


    if ($action === 'rename_table') {
        $id   = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');

        if ($id <= 0) {
            $errors[] = 'Некорректный стол.';
        }
        if ($name === '') {
            $errors[] = 'Введите название стола.';
        }

        if (!$errors) {
            $stmt = $pdo->prepare("
                SELECT * FROM tables
                WHERE id = :id AND restaurant_id = :rest
                LIMIT 1
            ");
            $stmt->execute([
                'id'   => $id,
                'rest' => $currentRestaurant['id'],
            ]);
            $rowTbl = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($rowTbl && function_exists('qr_public_is_delivery_table_row') && qr_public_is_delivery_table_row($rowTbl)) {
                $errors[] = 'Служебный стол доставки нельзя переименовать.';
            }
        }

        if (!$errors) {
            $stmt = $pdo->prepare("
                UPDATE tables
                SET name = :name
                WHERE id = :id AND restaurant_id = :rest
            ");
            $stmt->execute([
                'name' => $name,
                'id'   => $id,
                'rest' => $currentRestaurant['id'],
            ]);

            $success = 'Название стола обновлено.';
            log_action(auth_user()['id'] ?? null, $currentRestaurant['id'],
                'rename_table', "Стол #{$id} → {$name}");
        }
    }


    if ($action === 'regen_qr') {
        $id = (int)($_POST['id'] ?? 0);

        if ($id <= 0) {
            $errors[] = 'Некорректный стол.';
        }

        if (!$errors) {
            $stmt = $pdo->prepare("
                SELECT * FROM tables
                WHERE id = :id AND restaurant_id = :rest
                LIMIT 1
            ");
            $stmt->execute([
                'id'   => $id,
                'rest' => $currentRestaurant['id'],
            ]);
            $table = $stmt->fetch();

            if (!$table) {
                $errors[] = 'Стол не найден.';
            } elseif (function_exists('qr_public_is_delivery_table_row') && qr_public_is_delivery_table_row($table)) {
                $errors[] = 'Служебный стол доставки нельзя использовать для QR.';
            } else {
                try {
                    $qrPath = generate_table_qr_image($currentRestaurant, $table);
                } catch (Throwable $e) {
                    $qrPath = '';
                    $errors[] = 'Не удалось сгенерировать QR для выбранного стола.';
                    if (function_exists('error_log')) {
                        error_log('TABLES_QR_GENERATE_REGEN_FAIL rid=' . (int)$currentRestaurant['id'] . ' table=' . (int)$id . ' ' . $e->getMessage());
                    }
                }

                if ($qrPath !== '' && $hasQrCodePathCol) {
                    $stmt = $pdo->prepare("
                        UPDATE tables
                        SET qr_code_path = :qr
                        WHERE id = :id AND restaurant_id = :rest
                    ");
                    $stmt->execute([
                        'qr'   => $qrPath,
                        'id'   => $id,
                        'rest' => $currentRestaurant['id'],
                    ]);
                }

                if ($qrPath !== '') {
                    $success = 'QR-код перегенерирован.';
                    log_action(auth_user()['id'] ?? null, $currentRestaurant['id'],
                        'regen_table_qr', "Стол #{$id}");
                }
            }
        }
    }

    if ($action === 'delete_table') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            $errors[] = 'Некорректный стол.';
        }
        if (!$errors) {
            $stmt = $pdo->prepare("SELECT * FROM tables WHERE id = :id AND restaurant_id = :rest LIMIT 1");
            $stmt->execute(['id' => $id, 'rest' => $currentRestaurant['id']]);
            $delRow = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($delRow && function_exists('qr_public_is_delivery_table_row') && qr_public_is_delivery_table_row($delRow)) {
                $errors[] = 'Служебный стол доставки нельзя удалить.';
            }
        }

        if (!$errors) {
            $stmt = $pdo->prepare("SELECT COUNT(1) FROM orders WHERE table_id = :tid AND restaurant_id = :rest");
            $stmt->execute(['tid' => $id, 'rest' => $currentRestaurant['id']]);
            if ((int)$stmt->fetchColumn() > 0) {
                $errors[] = 'Нельзя удалить стол, по которому есть заказы.';
            } else {
                $stmt = $pdo->prepare("DELETE FROM tables WHERE id = :id AND restaurant_id = :rest");
                $stmt->execute(['id' => $id, 'rest' => $currentRestaurant['id']]);
                if ($stmt->rowCount() > 0) {
                    $success = 'Стол удалён.';
                    log_action(auth_user()['id'] ?? null, $currentRestaurant['id'],
                        'delete_table', "Стол #{$id} удалён");
                } else {
                    $errors[] = 'Стол не найден.';
                }
            }
        }
    }
    }

    // QR actions should return user to QR section (PRG) to avoid losing context and duplicate submissions.
    if (in_array((string)$postAction, ['add_table', 'regen_qr'], true)) {
        if ($success !== null && $success !== '') {
            $_SESSION['tables_flash_success'] = $success;
        }
        if (!empty($errors)) {
            $_SESSION['tables_flash_errors'] = $errors;
        }
        header('Location: /restaurant/tables.php#qr-cards');
        exit;
    }
}



$stmt = $pdo->prepare("
    SELECT * FROM tables AS t
    WHERE t.restaurant_id = :rest
    " . qr_public_sql_exclude_delivery($pdo, 't') . "
    ORDER BY t.id ASC
");
$stmt->execute(['rest' => $currentRestaurant['id']]);
$tables = $stmt->fetchAll();

$config     = require __DIR__ . '/../../app/config.php';
$mainDomain = $config['app']['main_domain'];
$protocol   = $config['app']['protocol'] ?? 'https';


$restaurantBase = $protocol . '://' . $currentRestaurant['subdomain'] . '.' . $mainDomain;

?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Столы и QR-коды — <?= e($currentRestaurant['name']) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?= brand_head_tags() ?>

    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-950 text-slate-50 flex">
<?php
$restaurantSidebarActive = 'tables';
$restaurantSidebarName = (string)($currentRestaurant['name'] ?? 'Ресторан');
require __DIR__ . '/_sidebar_mobile.php';
?>

<?php
require __DIR__ . '/_sidebar.php';
?>

<main class="flex-1 p-4">
    <div class="max-w-5xl mx-auto space-y-4">
        <?php
        $cabinetQuickNavActive = 'tables';
        $operationalNavActive = 'tables';
        require __DIR__ . '/_restaurant_cabinet_context.php';
        require __DIR__ . '/_restaurant_cabinet_quick_nav.php';
        require __DIR__ . '/_restaurant_operational_nav.php';
        ?>
        <div class="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-start sm:justify-between border-b border-slate-800/80 pb-4">
            <header class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-wider text-emerald-500/85 mb-1">Столы</p>
                <h1 class="text-2xl font-bold text-slate-50 mb-1">Столы и QR-коды</h1>
                <div class="text-xs text-slate-500">
                    Гости попадают в меню по адресу:
                    <span class="font-mono text-[11px] text-slate-200 break-all"><?= e($restaurantBase) ?>/qr.php?table_id=ID</span>
                </div>
            </header>
            <a href="/restaurant/tables_qr.php"
               class="inline-flex items-center justify-center shrink-0 min-h-[44px] px-4 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-sm font-medium text-slate-100 border border-slate-700 touch-manipulation">
                QR для столов
            </a>
        </div>

        <?php if ($success): ?>
            <div class="rounded-2xl bg-emerald-500/10 border border-emerald-500/50 px-4 py-3 text-sm text-emerald-100">
                <?= e($success) ?>
            </div>
        <?php endif; ?>

        <?php if ($errors): ?>
            <div class="rounded-2xl bg-red-500/10 border border-red-500/50 px-4 py-3 text-sm text-red-100 space-y-1">
                <?php foreach ($errors as $err): ?>
                    <div><?= e($err) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Добавление стола -->
        <div id="add-table" class="bg-slate-900/80 border border-slate-800 rounded-3xl p-4">
            <h3 class="text-lg font-semibold mb-3">Добавить стол</h3>
            <form method="post" class="flex flex-wrap gap-3 items-end">
                <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                <input type="hidden" name="action" value="add_table">
                <div class="flex-1 min-w-[200px]">
                    <label class="block text-xs text-slate-300 mb-1">Название стола</label>
                    <input type="text" name="name" required
                           placeholder="Например, Стол №1 (зал)"
                           class="w-full rounded-xl bg-slate-950/70 border border-slate-700 px-3 py-2 text-sm">
                </div>
                <button type="submit"
                        class="px-4 py-2.5 rounded-xl bg-emerald-500 hover:bg-emerald-400 text-slate-950 text-sm font-semibold">
                    Создать и сгенерировать QR
                </button>
            </form>
        </div>

        <!-- Список столов -->
        <div id="qr-cards" class="bg-slate-900/80 border border-slate-800 rounded-3xl p-4">
            <h3 class="text-lg font-semibold mb-3">Список столов</h3>

            <?php if (!$tables): ?>
                <div class="rounded-xl border border-slate-800 bg-slate-950/60 p-8 text-center space-y-4">
                    <div class="inline-flex items-center justify-center w-14 h-14 rounded-2xl bg-slate-800 text-slate-400">
                        <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/></svg>
                    </div>
                    <div>
                        <h3 class="text-lg font-semibold text-slate-100">Пока нет гостевых столов</h3>
                        <p class="text-sm text-slate-400 mt-1">Создайте стол — появится ссылка и QR для заказа в зале. Режим доставки использует отдельный сценарий без столов.</p>
                    </div>
                    <a href="#add-table" class="inline-flex items-center px-4 py-2.5 rounded-xl bg-emerald-500 hover:bg-emerald-400 text-slate-950 text-sm font-semibold">Добавить стол</a>
                </div>
            <?php else: ?>
                <div class="space-y-3">
                    <?php foreach ($tables as $t): ?>
                        <?php
                        $guestUrl = $restaurantBase . '/qr.php?table_id=' . urlencode($t['id']);
                        $qrPath = $hasQrCodePathCol ? trim((string)($t['qr_code_path'] ?? '')) : '';
                        $qrPublicPath = '';
                        if ($qrPath !== '') {
                            $qrPath = str_replace("\0", '', $qrPath);
                            $qrPath = ltrim($qrPath, '/');
                            if ($qrPath !== '' && strpos($qrPath, '..') === false && preg_match('~^qr/rest_[0-9]+_table_[0-9]+\\.png$~', $qrPath) === 1) {
                                $candidate = __DIR__ . '/../storage/' . $qrPath;
                                if (is_file($candidate)) {
                                    $qrPublicPath = '/storage/' . $qrPath;
                                }
                            }
                        }
                        if ($qrPublicPath === '') {
                            try {
                                $generated = generate_table_qr_image($currentRestaurant, [
                                    'id' => (int)($t['id'] ?? 0),
                                    'name' => (string)($t['name'] ?? ''),
                                ]);
                                if ($generated !== '') {
                                    $qrPublicPath = '/storage/' . ltrim($generated, '/');
                                }
                            } catch (Throwable $e) {
                                if (function_exists('error_log')) {
                                    error_log('TABLES_QR_GENERATE_RENDER_FAIL rid=' . (int)$currentRestaurant['id'] . ' table=' . (int)($t['id'] ?? 0) . ' ' . $e->getMessage());
                                }
                                $qrPublicPath = '';
                            }
                        }
                        ?>
                        <div class="flex flex-wrap gap-4 items-center justify-between bg-slate-950/80 border border-slate-800 rounded-2xl px-3 py-3">
                            <div class="flex items-center gap-3">
                                <div class="text-xs text-slate-500 w-10">#<?= (int)$t['id'] ?></div>
                                <form method="post" class="flex items-center gap-2">
                                    <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                                    <input type="hidden" name="action" value="rename_table">
                                    <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                                    <input type="text" name="name"
                                           value="<?= e($t['name']) ?>"
                                           class="rounded-xl bg-slate-950/70 border border-slate-700 px-3 py-1.5 text-sm min-w-[180px]">
                                    <button type="submit"
                                            class="px-3 py-1.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-xs text-slate-100">
                                        Сохранить
                                    </button>
                                </form>
                            </div>

                            <div class="flex flex-wrap items-center gap-3">
                                <div class="text-[11px] text-slate-500 max-w-xs break-all">
                                    <div class="text-slate-400 mb-0.5">Ссылка для гостя:</div>
                                    <a href="<?= e($guestUrl) ?>" target="_blank"
                                       class="text-emerald-300 hover:text-emerald-200">
                                        <?= e($guestUrl) ?>
                                    </a>
                                </div>

                                <div class="flex items-center gap-3">
                                    <?php if ($qrPublicPath !== ''): ?>
                                        <a href="<?= e($qrPublicPath) ?>" target="_blank"
                                           title="Открыть QR в новой вкладке">
                                            <img src="<?= e($qrPublicPath) ?>"
                                                 alt="QR"
                                                 class="w-20 h-20 object-contain bg-slate-900 rounded-2xl border border-slate-700">
                                        </a>
                                    <?php else: ?>
                                        <div class="w-20 h-20 flex items-center justify-center rounded-2xl border border-dashed border-slate-700 text-[11px] text-slate-500">
                                            QR не создан
                                        </div>
                                    <?php endif; ?>

                                    <form method="post" class="inline">
                                        <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                                        <input type="hidden" name="action" value="regen_qr">
                                        <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                                        <button type="submit"
                                                class="px-3 py-1.5 rounded-xl bg-emerald-500/10 border border-emerald-500/60 text-xs text-emerald-100 hover:bg-emerald-500/20">
                                            Перегенерировать QR
                                        </button>
                                    </form>
                                    <form method="post" class="inline" onsubmit="return confirm('Удалить этот стол?');">
                                        <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                                        <input type="hidden" name="action" value="delete_table">
                                        <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                                        <button type="submit"
                                                class="px-3 py-1.5 rounded-xl bg-red-500/10 border border-red-500/60 text-xs text-red-200 hover:bg-red-500/20">
                                            Удалить
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

    </div>
</main>
</body>
</html>
