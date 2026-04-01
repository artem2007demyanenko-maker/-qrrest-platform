<?php
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/guest_auth.php';

$pdo = db();

if (!$currentRestaurant) {
    http_response_code(404);
    echo "Ресторан не найден (по поддомену).";
    exit;
}

$restId = (int)$currentRestaurant['id'];
$guest  = guest_require_login($pdo, $restId);

// Soft loyalty gating: when plan has loyalty disabled, show safe message and do not expose balance/history.
$loyaltyDisabledByPlan = false;
if (file_exists(__DIR__ . '/../../app/subscription_plans.php')) {
    require_once __DIR__ . '/../../app/subscription_plans.php';
    if (function_exists('check_feature') && !check_feature($restId, 'loyalty_enabled')) {
        $loyaltyDisabledByPlan = true;
    }
}

$balance = $loyaltyDisabledByPlan ? 0 : (int)($guest['loyalty_balance'] ?? 0);

// Canonical ledger: guest_loyalty_tx (empty when loyalty disabled by plan)
$txs = [];
if (!$loyaltyDisabledByPlan && function_exists('guest_loyalty_recent_tx')) {
    $txs = guest_loyalty_recent_tx($pdo, $restId, (int)$guest['id'], 20);
} elseif (!$loyaltyDisabledByPlan) {
    $stmt = $pdo->prepare("
        SELECT id, type, points, note, created_at
        FROM guest_loyalty_tx
        WHERE restaurant_id = ? AND guest_id = ?
        ORDER BY created_at DESC
        LIMIT 20
    ");
    $stmt->execute([$restId, (int)$guest['id']]);
    $txs = $stmt->fetchAll(PDO::FETCH_ASSOC);
}


$qrUrl = "/staff/loyalty/scan.php?token=" . urlencode((string)$guest['qr_token']);

function mask_phone(string $p): string {

    $digits = preg_replace('/\D+/', '', $p);
    if (strlen($digits) === 11 && $digits[0] === '7') {
        return '+7 (' . substr($digits,1,3) . ') ***-**-' . substr($digits,9,2);
    }
    return $p;
}

?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Кабинет гостя — <?= guest_e($currentRestaurant['name']) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
    <style>
        .float-a{animation:fa 18s ease-in-out infinite}
        .float-b{animation:fb 26s ease-in-out infinite}
        @keyframes fa{0%,100%{transform:translate3d(0,0,0) scale(1)}50%{transform:translate3d(18px,-22px,0) scale(1.05)}}
        @keyframes fb{0%,100%{transform:translate3d(0,0,0) scale(1)}50%{transform:translate3d(-22px,26px,0) scale(1.06)}}
    </style>
</head>
<body class="min-h-screen bg-slate-950 text-slate-50">
<div class="relative max-w-4xl mx-auto px-4 py-6">
    <div class="pointer-events-none absolute inset-0 -z-10">
        <div class="absolute -top-44 -left-28 w-96 h-96 bg-emerald-500/20 blur-3xl rounded-full float-a"></div>
        <div class="absolute bottom-[-12rem] right-[-4rem] w-[34rem] h-[34rem] bg-sky-500/16 blur-3xl rounded-full float-b"></div>
        <div class="absolute top-1/3 right-16 w-72 h-72 bg-fuchsia-500/14 blur-3xl rounded-full"></div>
    </div>

    <header class="flex flex-wrap items-center justify-between gap-3 mb-5">
        <div>
            <div class="text-xs text-slate-400 uppercase tracking-wide"><?= guest_e($currentRestaurant['name']) ?></div>
            <h1 class="text-2xl sm:text-3xl font-bold leading-tight">Кабинет гостя</h1>
            <div class="text-xs text-slate-400 mt-1">
                <?= guest_e($guest['name']) ?> · <?= guest_e(mask_phone((string)$guest['phone'])) ?>
            </div>
        </div>

        <div class="flex items-center gap-2">
            <a href="/qr.php" class="px-3 py-2 rounded-2xl bg-slate-900/70 border border-slate-800 text-xs text-slate-200 hover:border-emerald-500/50">
                Открыть меню
            </a>
            <a href="/guest/logout.php" class="px-3 py-2 rounded-2xl bg-red-500/10 border border-red-500/40 text-xs text-red-100 hover:border-red-400/70">
                Выйти
            </a>
        </div>
    </header>

    <div class="grid lg:grid-cols-3 gap-4">

        <?php if ($loyaltyDisabledByPlan): ?>
        <section class="lg:col-span-2 bg-slate-950/85 border border-slate-800 rounded-3xl p-5 backdrop-blur-xl shadow-2xl shadow-slate-950/70">
            <div class="rounded-2xl border border-amber-500/40 bg-amber-500/10 px-4 py-3 text-sm text-amber-200">
                <p class="font-medium">Программа лояльности сейчас недоступна для этого ресторана</p>
                <p class="text-xs text-amber-200/80 mt-1">Начисления и списание баллов временно отключены. Обратитесь к администрации ресторана.</p>
            </div>
        </section>
        <?php else: ?>
        <section class="lg:col-span-2 bg-slate-950/85 border border-slate-800 rounded-3xl p-5 backdrop-blur-xl shadow-2xl shadow-slate-950/70">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <div class="text-xs text-slate-400">Ваши бонусы в этом ресторане</div>
                    <div class="mt-1 text-4xl font-extrabold tracking-tight">
                        <?= number_format($balance, 0, '.', ' ') ?>
                        <span class="text-base font-semibold text-slate-300">баллов</span>
                    </div>
                    <div class="mt-2 text-sm text-slate-300">
                        1 балл = 1 ₽ (можно списывать у персонала при оплате).
                    </div>
                </div>

                <div class="text-[11px] px-2 py-1 rounded-full bg-slate-900 border border-slate-700 text-slate-300">
                    Только для этого ресторана
                </div>
            </div>

            <div class="mt-5 grid sm:grid-cols-2 gap-3">
                <div class="rounded-2xl bg-slate-900/60 border border-slate-800 p-4">
                    <div class="text-xs text-slate-400">Как начисляют</div>
                    <div class="mt-1 text-sm text-slate-200">
                        Сообщите официанту ваш QR — он начислит бонусы за заказ.
                    </div>
                </div>

                <div class="rounded-2xl bg-slate-900/60 border border-slate-800 p-4">
                    <div class="text-xs text-slate-400">Как списать</div>
                    <div class="mt-1 text-sm text-slate-200">
                        Перед оплатой покажите QR и скажите сколько баллов списать.
                    </div>
                </div>
            </div>
        </section>
        <?php endif; ?>

        <aside class="bg-slate-950/85 border border-slate-800 rounded-3xl p-5 backdrop-blur-xl shadow-2xl shadow-slate-950/70">
            <div class="flex items-center justify-between mb-3">
                <div>
                    <div class="text-xs text-slate-400">Ваш QR-код</div>
                    <div class="text-sm font-semibold text-slate-100">Покажите персоналу</div>
                </div>
                <span class="text-[10px] px-2 py-1 rounded-full bg-emerald-500/10 border border-emerald-500/35 text-emerald-200">
                    Loyalty
                </span>
            </div>

            <div class="rounded-2xl bg-white p-3 flex items-center justify-center">
                <div id="qr-box"></div>
            </div>

            <div class="mt-3 text-[11px] text-slate-400">
                QR ведёт на страницу сканирования для сотрудников и работает только на поддомене ресторана.
            </div>

            <div class="mt-3">
                <button type="button"
                        onclick="navigator.clipboard?.writeText(location.origin + '<?= guest_e($qrUrl) ?>')"
                        class="w-full px-3 py-2 rounded-2xl bg-slate-900/70 border border-slate-800 text-xs text-slate-200 hover:border-emerald-500/50">
                    Скопировать ссылку QR
                </button>
            </div>
        </aside>
    </div>


    <?php if (!$loyaltyDisabledByPlan): ?>
    <section class="mt-4 bg-slate-950/85 border border-slate-800 rounded-3xl p-5 backdrop-blur-xl shadow-2xl shadow-slate-950/70">
        <div class="flex items-center justify-between gap-3 mb-3">
            <h2 class="text-lg font-semibold">История операций</h2>
            <div class="text-xs text-slate-500">Последние 20</div>
        </div>

        <?php if (empty($txs)): ?>
            <div class="text-sm text-slate-400">
                Пока нет начислений и списаний.
            </div>
        <?php else: ?>
            <div class="space-y-2">
                <?php foreach ($txs as $tx): ?>
                    <?php
                        $type = $tx['type'];
                        $pts  = (int)$tx['points'];
                        $isPlus = ($type === 'accrual' || ($type === 'adjust' && $pts > 0));
                        $label = $type === 'accrual' ? 'Начисление' : ($type === 'spend' ? 'Списание' : 'Корректировка');
                    ?>
                    <div class="flex items-center justify-between gap-3 rounded-2xl bg-slate-900/60 border border-slate-800 px-4 py-3">
                        <div class="min-w-0">
                            <div class="text-sm font-semibold text-slate-100">
                                <?= guest_e($label) ?>
                            </div>
                            <div class="text-[11px] text-slate-500">
                                <?= guest_e((string)$tx['created_at']) ?>
                                <?php if (!empty($tx['note']) || !empty($tx['comment'])): ?>
                                    · <?= guest_e((string)($tx['note'] ?? $tx['comment'] ?? '')) ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="text-right">
                            <div class="text-sm font-bold <?= $isPlus ? 'text-emerald-300' : 'text-red-300' ?>">
                                <?= $isPlus ? '+' : '' ?><?= number_format($pts, 0, '.', ' ') ?>
                            </div>
                            <div class="text-[11px] text-slate-500">баллов</div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
    <?php endif; ?>
</div>

<script>
(function(){
    const url = location.origin + <?= json_encode($qrUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    new QRCode(document.getElementById("qr-box"), {
        text: url,
        width: 200,
        height: 200,
        correctLevel: QRCode.CorrectLevel.M
    });
})();
</script>
</body>
</html>
