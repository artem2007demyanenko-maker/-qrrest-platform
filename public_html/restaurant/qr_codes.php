<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';

require_login();
require_current_restaurant();

$restaurantId = (int)($currentRestaurant['id'] ?? 0);
require_restaurant_role($restaurantId, ['owner', 'admin']);

$pdo = db();
$errors = [];
$success = null;

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfOk = isset($_POST['csrf'], $_SESSION['csrf'])
        && hash_equals((string)$_SESSION['csrf'], (string)$_POST['csrf']);
    if (!$csrfOk) {
        $errors[] = 'Неверный запрос. Обновите страницу и попробуйте снова.';
    } else {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'add_table') {
            $name = trim((string)($_POST['name'] ?? ''));
            if ($name === '') {
                $errors[] = 'Введите название стола.';
            }
            if (!$errors) {
                $stmt = $pdo->prepare('
                    INSERT INTO tables (restaurant_id, name, created_at)
                    VALUES (:rest, :name, NOW())
                ');
                $stmt->execute([
                    'rest' => $restaurantId,
                    'name' => $name,
                ]);
                if (function_exists('log_action')) {
                    $uid = null;
                    if (function_exists('auth_user')) {
                        $u = auth_user();
                        $uid = isset($u['id']) ? (int)$u['id'] : null;
                    }
                    log_action($uid, $restaurantId, 'create_table', 'Стол «' . $name . '» (qr_codes.php)');
                }
                $success = 'Стол создан.';
            }
        }
    }
}

$cfgMenu = [];
$cfgFile = __DIR__ . '/../../app/config.php';
if (is_file($cfgFile)) {
    $cfgMenu = require $cfgFile;
}
$guestProto = isset($cfgMenu['app']['protocol']) && $cfgMenu['app']['protocol'] !== ''
    ? (string)$cfgMenu['app']['protocol']
    : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http');
$guestMain = isset($cfgMenu['app']['main_domain']) ? (string)$cfgMenu['app']['main_domain'] : '';
$guestSub = isset($currentRestaurant['subdomain']) ? trim((string)$currentRestaurant['subdomain']) : '';
$canBuildGuestUrl = ($guestMain !== '' && $guestSub !== '');

$stmt = $pdo->prepare('
    SELECT * FROM tables AS t
    WHERE t.restaurant_id = :rest
    ' . qr_public_sql_exclude_delivery($pdo, 't') . '
    ORDER BY t.id ASC
');
$stmt->execute(['rest' => $restaurantId]);
$tables = $stmt->fetchAll(PDO::FETCH_ASSOC);

$restaurantName = (string)($currentRestaurant['name'] ?? '');
$appName = 'QR-Rest Cloud';

/**
 * @return array{url: string, qr_display: string, qr_download: string}|null
 */
function qr_codes_urls_for_table(string $proto, string $main, string $sub, int $tableId): ?array
{
    if ($main === '' || $sub === '') {
        return null;
    }
    $url = $proto . '://' . $sub . '.' . $main . '/qr.php?table_id=' . $tableId;
    $enc = rawurlencode($url);
    return [
        'url' => $url,
        'qr_display' => 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' . $enc,
        'qr_download' => 'https://api.qrserver.com/v1/create-qr-code/?size=800x800&data=' . $enc,
    ];
}

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?= brand_head_tags() ?>
    <title>QR-коды столов — <?= e($restaurantName) ?> — <?= e($appName) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-950 text-slate-100 antialiased flex flex-col md:flex-row overflow-x-hidden">
<?php
$restaurantSidebarActive = 'qr_codes';
$restaurantSidebarName = (string)($restaurantName ?? ($currentRestaurant['name'] ?? 'Ресторан'));
require __DIR__ . '/_sidebar_mobile.php';
require __DIR__ . '/_sidebar.php';
?>

<main class="flex-1 min-w-0 overflow-x-hidden">
    <div class="max-w-6xl mx-auto px-4 py-6 md:px-8 md:py-10 space-y-8">
        <?php
        $operationalNavActive = 'qr_codes';
        require __DIR__ . '/_restaurant_cabinet_context.php';
        require __DIR__ . '/_restaurant_operational_nav.php';
        ?>

        <header class="space-y-1 border-b border-slate-800/80 pb-4">
            <p class="text-[11px] font-semibold uppercase tracking-wider text-emerald-500/85">Операции · QR</p>
            <h1 class="text-2xl md:text-3xl font-bold text-white tracking-tight">QR-коды столов</h1>
            <p class="text-slate-400 text-sm md:text-base">Генерация, печать и быстрый доступ к QR-меню по столам</p>
        </header>

        <?php if ($success): ?>
            <div class="rounded-xl bg-emerald-500/10 border border-emerald-500/40 px-4 py-3.5 text-sm text-emerald-100" role="status"><?= e($success) ?></div>
        <?php endif; ?>
        <?php if ($errors): ?>
            <div class="rounded-xl bg-red-500/10 border border-red-500/40 px-4 py-3.5 text-sm text-red-100 space-y-1" role="alert">
                <?php foreach ($errors as $err): ?>
                    <div><?= e($err) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php if (!$canBuildGuestUrl): ?>
            <div class="rounded-xl bg-amber-500/10 border border-amber-500/40 px-4 py-3.5 text-sm text-amber-100">
                Не заданы поддомен или основной домен — ссылки для QR недоступны. Проверьте настройки ресторана и <code class="text-amber-200/90">APP_MAIN_DOMAIN</code>.
            </div>
        <?php endif; ?>

        <?php if ($tables === []): ?>
            <div class="rounded-2xl border border-dashed border-slate-600/50 bg-[#111827]/50 px-6 py-16 text-center space-y-5">
                <h2 class="text-xl font-bold text-white">Нет столов</h2>
                <p class="text-sm text-slate-400 max-w-md mx-auto">Добавьте стол, чтобы сгенерировать QR для гостевого меню.</p>
                <div class="flex flex-col sm:flex-row gap-3 justify-center items-stretch sm:items-center">
                    <a href="/restaurant/tables.php"
                       class="inline-flex items-center justify-center px-6 py-3.5 rounded-xl text-sm font-bold text-white bg-gradient-to-r from-indigo-600 to-violet-600 hover:from-indigo-500 hover:to-violet-500 shadow-lg shadow-indigo-900/35 transition-all">
                        Создать стол
                    </a>
                </div>
                <div class="max-w-sm mx-auto pt-4 border-t border-slate-800/80">
                    <p class="text-xs text-slate-500 mb-3">Или быстро добавить:</p>
                    <form method="post" class="flex flex-col sm:flex-row gap-2">
                        <input type="hidden" name="csrf" value="<?= e((string)$_SESSION['csrf']) ?>">
                        <input type="hidden" name="action" value="add_table">
                        <input type="text" name="name" required placeholder="Например: Стол 1"
                               class="flex-1 rounded-xl bg-[#111827] border border-slate-700/90 px-4 py-3 text-sm text-slate-100 placeholder:text-slate-600 focus:outline-none focus:ring-2 focus:ring-indigo-500/45">
                        <button type="submit" class="px-5 py-3 rounded-xl text-sm font-bold text-white bg-slate-800 hover:bg-slate-700 border border-slate-600/50 transition-colors">
                            Добавить
                        </button>
                    </form>
                </div>
            </div>
        <?php else: ?>
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <p class="text-sm text-slate-500">Каждый код ведёт на меню с привязкой к столу.</p>
                <a href="/restaurant/tables.php" class="inline-flex items-center justify-center px-4 py-2.5 rounded-xl text-xs font-semibold text-slate-200 bg-slate-800/80 border border-slate-600/50 hover:bg-slate-700 transition-colors">
                    Управление столами
                </a>
            </div>
            <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                <?php foreach ($tables as $t):
                    $tid = (int)$t['id'];
                    $tname = trim((string)($t['name'] ?? ''));
                    if ($tname === '') {
                        $tname = 'Стол #' . $tid;
                    }
                    $linkPack = $canBuildGuestUrl ? qr_codes_urls_for_table($guestProto, $guestMain, $guestSub, $tid) : null;
                    ?>
                    <article class="rounded-xl border border-slate-700/80 bg-[#111827] p-5 shadow-sm hover:border-indigo-500/30 hover:shadow-lg hover:shadow-indigo-950/15 transition-all duration-200 flex flex-col items-center text-center gap-4">
                        <?php if ($linkPack !== null): ?>
                            <div class="rounded-lg bg-white p-3 shadow-inner border border-slate-200/10 max-w-full">
                                <img src="<?= e($linkPack['qr_display']) ?>"
                                     alt="QR стол <?= e((string)$tid) ?>"
                                     width="200"
                                     height="200"
                                     class="w-full max-w-[200px] h-auto aspect-square object-contain mx-auto"
                                     loading="lazy"
                                     decoding="async">
                            </div>
                            <h2 class="text-base font-bold text-white"><?= e($tname) ?></h2>
                            <p class="text-[11px] text-slate-500 break-all w-full line-clamp-2" title="<?= e($linkPack['url']) ?>"><?= e($linkPack['url']) ?></p>
                            <div class="flex flex-col w-full gap-2 mt-1">
                                <a href="<?= e($linkPack['qr_download']) ?>"
                                   download="qr-table-<?= $tid ?>.png"
                                   class="inline-flex items-center justify-center gap-2 w-full px-4 py-3 rounded-xl text-sm font-bold text-white bg-gradient-to-r from-indigo-600 to-violet-600 hover:from-indigo-500 hover:to-violet-500 shadow-md shadow-indigo-900/25 transition-all">
                                    Скачать
                                </a>
                                <a href="<?= e($linkPack['url']) ?>"
                                   target="_blank"
                                   rel="noopener noreferrer"
                                   class="inline-flex items-center justify-center w-full px-4 py-3 rounded-xl text-sm font-semibold text-slate-100 bg-slate-800 hover:bg-slate-700 border border-slate-600/50 transition-colors">
                                    Открыть
                                </a>
                                <button type="button"
                                        class="qr-print-btn inline-flex items-center justify-center w-full px-4 py-3 rounded-xl text-sm font-semibold text-slate-200 bg-slate-900 hover:bg-slate-800 border border-slate-600/40 transition-colors"
                                        data-qr="<?= e($linkPack['qr_download']) ?>"
                                        data-title="<?= e($tname) ?>">
                                    Печать
                                </button>
                            </div>
                        <?php else: ?>
                            <div class="w-[200px] h-[200px] rounded-lg bg-slate-900 border border-dashed border-slate-600 flex items-center justify-center text-xs text-slate-500 px-2">
                                Нет домена для ссылки
                            </div>
                            <h2 class="text-base font-bold text-white"><?= e($tname) ?></h2>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</main>

<script>
(function () {
  document.querySelectorAll('.qr-print-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var src = btn.getAttribute('data-qr');
      var title = btn.getAttribute('data-title') || 'QR';
      if (!src) return;
      var w = window.open('', '_blank');
      if (!w) { alert('Разрешите всплывающие окна для печати'); return; }
      w.document.open();
      w.document.write('<!DOCTYPE html><html><head><meta charset="UTF-8"><title>' +
        String(title).replace(/</g, '&lt;') + '</title></head><body style="margin:0;padding:24px;text-align:center;font-family:system-ui,sans-serif;">' +
        '<img src="' + src.replace(/"/g, '&quot;') + '" alt="" style="width:280px;height:280px;max-width:90vw;"/>' +
        '<p style="margin-top:16px;font-size:14px;color:#333;">' + String(title).replace(/</g, '&lt;') + '</p>' +
        '<script>window.onload=function(){window.print();}<\/script></body></html>');
      w.document.close();
    });
  });
})();
</script>
</body>
</html>
