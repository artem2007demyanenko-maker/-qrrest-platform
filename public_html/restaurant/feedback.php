<?php
/**
 * Restaurant panel: guest feedback — average rating, recent feedback, bad reviews first.
 */

require_once __DIR__ . '/../../app/bootstrap.php';

require_login();
require_current_restaurant();
require_restaurant_role((int)($currentRestaurant['id'] ?? 0), ['owner', 'admin']);

$pdo = db();
$restId = (int)$currentRestaurant['id'];

$avgRating = null;
$totalCount = 0;
$feedbackList = [];

$tableExists = function_exists('db_table_exists') && db_table_exists('order_feedback');

if ($tableExists && $restId > 0 && !(function_exists('is_demo_mode') && is_demo_mode())) {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS cnt, AVG(rating) AS avg_rating
        FROM order_feedback
        WHERE restaurant_id = :rest
    ");
    $stmt->execute([':rest' => $restId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $totalCount = (int)($row['cnt'] ?? 0);
    $avgRating = $row['avg_rating'] !== null ? round((float)$row['avg_rating'], 1) : null;

    $stmt = $pdo->prepare("
        SELECT f.id, f.order_id, f.rating, f.comment, f.created_at
        FROM order_feedback f
        WHERE f.restaurant_id = :rest
        ORDER BY f.rating ASC, f.created_at DESC
        LIMIT 100
    ");
    $stmt->execute([':rest' => $restId]);
    $feedbackList = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

if (!function_exists('e')) {
    function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Отзывы гостей — <?= e($currentRestaurant['name']) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?= brand_head_tags() ?>

    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-950 text-slate-50 flex">
<?php
$restaurantSidebarActive = 'feedback';
$restaurantSidebarName = (string)($currentRestaurant['name'] ?? 'Ресторан');
require __DIR__ . '/_sidebar_mobile.php';
?>

<?php require __DIR__ . '/_sidebar.php'; ?>

<main class="flex-1 p-4">
    <div class="max-w-4xl mx-auto space-y-4">
        <h2 class="text-2xl font-bold">Отзывы гостей</h2>
        <p class="text-sm text-slate-400">Отзывы после получения заказа (статус «Забрали»). Сначала показаны низкие оценки.</p>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div class="rounded-2xl bg-slate-900/80 border border-slate-800 p-4">
                <div class="text-xs text-slate-400 uppercase tracking-wide">Средняя оценка</div>
                <div class="mt-1 text-3xl font-bold text-amber-400">
                    <?= $avgRating !== null ? number_format($avgRating, 1) : '—' ?>
                </div>
                <div class="text-xs text-slate-500 mt-0.5">из 5</div>
            </div>
            <div class="rounded-2xl bg-slate-900/80 border border-slate-800 p-4">
                <div class="text-xs text-slate-400 uppercase tracking-wide">Всего отзывов</div>
                <div class="mt-1 text-3xl font-bold text-slate-100"><?= $totalCount ?></div>
            </div>
        </div>

        <div class="rounded-2xl bg-slate-900/80 border border-slate-800 p-4">
            <h3 class="text-lg font-semibold mb-3">Последние отзывы (сначала низкие оценки)</h3>
            <?php if (empty($feedbackList)): ?>
                <div class="rounded-xl border border-slate-800 bg-slate-950/60 p-6 text-center text-slate-500 text-sm">
                    <?= (function_exists('is_demo_mode') && is_demo_mode()) ? 'В демо-режиме отзывы не сохраняются.' : 'Пока нет отзывов. Они появятся после того, как гости получат заказ и оценят его.' ?>
                </div>
            <?php else: ?>
                <div class="space-y-3">
                    <?php foreach ($feedbackList as $f): ?>
                        <?php
                        $rating = (int)$f['rating'];
                        $badgeClass = $rating <= 2 ? 'bg-red-500/20 border-red-500/50 text-red-200' : ($rating <= 3 ? 'bg-amber-500/20 border-amber-500/50 text-amber-200' : 'bg-emerald-500/20 border-emerald-500/50 text-emerald-200');
                        ?>
                        <div class="rounded-xl border border-slate-800 bg-slate-950/60 px-4 py-3">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <div class="flex items-center gap-2">
                                    <span class="text-amber-400" aria-hidden="true"><?= str_repeat('⭐', $rating) ?></span>
                                    <span class="text-xs font-medium px-2 py-0.5 rounded-lg border <?= e($badgeClass) ?>"><?= $rating ?> / 5</span>
                                    <span class="text-xs text-slate-500">Заказ #<?= (int)$f['order_id'] ?></span>
                                </div>
                                <div class="text-xs text-slate-500"><?= e(date('d.m.Y H:i', strtotime($f['created_at'] ?? 'now'))) ?></div>
                            </div>
                            <?php if (!empty(trim($f['comment'] ?? ''))): ?>
                                <div class="mt-2 text-sm text-slate-300"><?= e($f['comment']) ?></div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</main>
</body>
</html>
