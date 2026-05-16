<?php

require_once __DIR__ . '/../../app/bootstrap.php';
if (file_exists(__DIR__ . '/../../app/schema_guard.php')) {
    require_once __DIR__ . '/../../app/schema_guard.php';
}
if (file_exists(__DIR__ . '/../../app/runtime_schema_bootstrap.php')) {
    require_once __DIR__ . '/../../app/runtime_schema_bootstrap.php';
}

require_login();
require_current_restaurant();
require_current_restaurant_role(['owner', 'admin']);

if (!function_exists('e')) {
    function e($v): string
    {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

$pdo = db();
if (empty($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = (string)($_SESSION['csrf'] ?? '');
if (function_exists('runtime_schema_ensure_guest_profiles')) {
    runtime_schema_ensure_guest_profiles($pdo);
}
if (function_exists('runtime_schema_ensure_guest_reviews')) {
    runtime_schema_ensure_guest_reviews($pdo);
}
if (function_exists('runtime_schema_ensure_order_tips')) {
    runtime_schema_ensure_order_tips($pdo);
}

$restId = (int)($currentRestaurant['id'] ?? 0);
$queryRaw = trim((string)($_GET['q'] ?? ''));
$queryNorm = function_exists('guest_normalize_phone') ? guest_normalize_phone($queryRaw) : null;

$where = 'WHERE restaurant_id = :rest_id';
$params = [':rest_id' => $restId];
if ($queryRaw !== '') {
    if ($queryNorm !== null) {
        $where .= ' AND (phone_normalized = :phone_norm OR guest_name LIKE :name_like)';
        $params[':phone_norm'] = $queryNorm;
        $params[':name_like'] = '%' . $queryRaw . '%';
    } else {
        $where .= ' AND (guest_name LIKE :name_like OR phone_normalized LIKE :phone_like)';
        $params[':name_like'] = '%' . $queryRaw . '%';
        $params[':phone_like'] = '%' . preg_replace('/\D+/', '', $queryRaw) . '%';
    }
}

$rows = [];
try {
    $sql = "
        SELECT
            id,
            phone_normalized,
            guest_name,
            orders_count,
            total_spent,
            average_check,
            first_order_at,
            last_order_at
        FROM guest_profiles
        {$where}
        ORDER BY last_order_at DESC, id DESC
        LIMIT 300
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    if (function_exists('error_log')) {
        error_log('RESTAURANT_GUESTS_LOAD_FAIL restaurant_id=' . $restId . ' ' . $e->getMessage());
    }
}

$totalGuests = count($rows);
$totalSpent = 0.0;
foreach ($rows as $row) {
    $totalSpent += (float)($row['total_spent'] ?? 0);
}

$historyPreviewByPhone = [];
$historyLoadCap = 40;
$historyPerGuestLimit = 3;
$historyLoadedGuests = 0;
$historyLoadableGuests = 0;
$historySkippedGuests = 0;
$historyRows = array_slice($rows, 0, $historyLoadCap);
$historySeenPhones = [];
if ($historyRows !== [] && function_exists('guest_history_resolve_phone') && function_exists('guest_history_fetch_orders')) {
    foreach ($historyRows as $hRow) {
        $phoneNorm = trim((string)($hRow['phone_normalized'] ?? ''));
        if ($phoneNorm === '') {
            continue;
        }
        if (isset($historySeenPhones[$phoneNorm])) {
            continue;
        }
        $historySeenPhones[$phoneNorm] = true;
        $historyLoadableGuests++;
        try {
            $resolved = guest_history_resolve_phone($pdo, $restId, [
                'guest_profile_id' => (int)($hRow['id'] ?? 0),
                'phone_normalized' => $phoneNorm,
            ]);
            if ($resolved === null) {
                $historySkippedGuests++;
                continue;
            }
            $orders = guest_history_fetch_orders($pdo, $restId, $resolved, $historyPerGuestLimit);
            $historyPreviewByPhone[$phoneNorm] = [
                'resolved' => $resolved,
                'orders' => $orders,
            ];
            $historyLoadedGuests++;
        } catch (Throwable $e) {
            $historySkippedGuests++;
            if (function_exists('error_log')) {
                error_log('RESTAURANT_GUESTS_HISTORY_PREVIEW_FAIL restaurant_id=' . $restId . ' phone=' . $phoneNorm . ' ' . $e->getMessage());
            }
        }
    }
}

$walletPreviewByPhone = [];
$walletLoadCap = 30;
$walletLedgerPerGuestLimit = 4;
$walletLoadedGuests = 0;
$walletLoadableGuests = 0;
$walletSkippedGuests = 0;
if (function_exists('loyalty_guest_balance') && function_exists('loyalty_guest_ledger')) {
    $walletRows = array_slice($rows, 0, $walletLoadCap);
    $walletSeenPhones = [];
    foreach ($walletRows as $wRow) {
        $phoneNorm = trim((string)($wRow['phone_normalized'] ?? ''));
        if ($phoneNorm === '' || isset($walletSeenPhones[$phoneNorm])) {
            continue;
        }
        $walletSeenPhones[$phoneNorm] = true;
        $walletLoadableGuests++;
        try {
            $resolved = null;
            $existingHistoryPack = $historyPreviewByPhone[$phoneNorm] ?? null;
            if (is_array($existingHistoryPack) && isset($existingHistoryPack['resolved']) && is_array($existingHistoryPack['resolved'])) {
                $resolved = $existingHistoryPack['resolved'];
            } elseif (function_exists('guest_history_resolve_phone')) {
                $resolved = guest_history_resolve_phone($pdo, $restId, [
                    'guest_profile_id' => (int)($wRow['id'] ?? 0),
                    'phone_normalized' => $phoneNorm,
                ]);
            }
            if (!is_array($resolved)) {
                $walletSkippedGuests++;
                continue;
            }
            $walletModel = loyalty_guest_balance($pdo, $restId, $resolved);
            $ledgerRows = loyalty_guest_ledger($pdo, $restId, $resolved, $walletLedgerPerGuestLimit);
            $walletPreviewByPhone[$phoneNorm] = [
                'wallet' => $walletModel,
                'ledger' => $ledgerRows,
                'resolved' => $resolved,
            ];
            $walletLoadedGuests++;
        } catch (Throwable $e) {
            $walletSkippedGuests++;
            if (function_exists('error_log')) {
                error_log('RESTAURANT_GUESTS_WALLET_PREVIEW_FAIL restaurant_id=' . $restId . ' phone=' . $phoneNorm . ' ' . $e->getMessage());
            }
        }
    }
}

$rfmPreviewByPhone = [];
$rfmLoadCap = 80;
$rfmLoadedGuests = 0;
$rfmLoadableGuests = 0;
$rfmSkippedGuests = 0;
if (
    function_exists('crm_guest_rfm_metrics')
    && function_exists('crm_guest_rfm_score')
    && function_exists('crm_guest_rfm_segment')
) {
    $rfmRows = array_slice($rows, 0, $rfmLoadCap);
    $rfmSeenPhones = [];
    foreach ($rfmRows as $rRow) {
        $phoneNorm = trim((string)($rRow['phone_normalized'] ?? ''));
        if ($phoneNorm === '' || isset($rfmSeenPhones[$phoneNorm])) {
            continue;
        }
        $rfmSeenPhones[$phoneNorm] = true;
        $rfmLoadableGuests++;

        try {
            $resolved = null;
            $existingWalletPack = $walletPreviewByPhone[$phoneNorm] ?? null;
            if (is_array($existingWalletPack) && isset($existingWalletPack['resolved']) && is_array($existingWalletPack['resolved'])) {
                $resolved = $existingWalletPack['resolved'];
            } else {
                $existingHistoryPack = $historyPreviewByPhone[$phoneNorm] ?? null;
                if (is_array($existingHistoryPack) && isset($existingHistoryPack['resolved']) && is_array($existingHistoryPack['resolved'])) {
                    $resolved = $existingHistoryPack['resolved'];
                }
            }
            if (!is_array($resolved) && function_exists('guest_history_resolve_phone')) {
                $resolved = guest_history_resolve_phone($pdo, $restId, [
                    'guest_profile_id' => (int)($rRow['id'] ?? 0),
                    'phone_normalized' => $phoneNorm,
                ]);
            }
            if (!is_array($resolved)) {
                $rfmSkippedGuests++;
                continue;
            }

            $metrics = crm_guest_rfm_metrics($pdo, $restId, $resolved);
            if (!is_array($metrics)) {
                $rfmSkippedGuests++;
                continue;
            }
            $score = crm_guest_rfm_score($metrics);
            $segment = crm_guest_rfm_segment($metrics, $score);
            $rfmPreviewByPhone[$phoneNorm] = [
                'metrics' => $metrics,
                'score' => $score,
                'segment' => $segment,
            ];
            $rfmLoadedGuests++;
        } catch (Throwable $e) {
            $rfmSkippedGuests++;
            if (function_exists('error_log')) {
                error_log('RESTAURANT_GUESTS_RFM_PREVIEW_FAIL restaurant_id=' . $restId . ' phone=' . $phoneNorm . ' ' . $e->getMessage());
            }
        }
    }
}

$reviewSummary = [
    'average_rating' => null,
    'average_nps' => null,
    'nps_score' => null,
    'total_reviews' => 0,
    'negative_reviews' => 0,
    'latest_reviews' => [],
    'low_rating_alerts' => [],
];
if (function_exists('guest_review_summary')) {
    try {
        $reviewSummary = guest_review_summary($pdo, $restId, 5, 120);
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('RESTAURANT_GUESTS_REVIEW_SUMMARY_FAIL restaurant_id=' . $restId . ' ' . $e->getMessage());
        }
    }
}

$tipsSummary = [
    'total_tips' => 0.0,
    'waiter_tips' => 0.0,
    'courier_tips' => 0.0,
    'average_tip' => 0.0,
    'tips_count' => 0,
    'pending_count' => 0,
    'paid_count' => 0,
    'cancelled_count' => 0,
    'top_tipped_staff' => [],
];
if (function_exists('order_tip_summary')) {
    try {
        $tipsSummary = order_tip_summary($pdo, $restId, [
            'limit' => 5,
            'days' => 120,
        ]);
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('RESTAURANT_GUESTS_TIPS_SUMMARY_FAIL restaurant_id=' . $restId . ' ' . $e->getMessage());
        }
    }
}
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Гости CRM — <?= e((string)($currentRestaurant['name'] ?? 'Ресторан')) ?></title>
    <?= brand_head_tags() ?>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-950 text-slate-100 flex">
<?php
$restaurantSidebarActive = 'guest_view';
require __DIR__ . '/_sidebar_mobile.php';
require __DIR__ . '/_sidebar.php';
?>
<main class="flex-1 p-4 md:p-6">
    <div class="max-w-6xl mx-auto space-y-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold">Гости CRM</h1>
                <p class="text-sm text-slate-400 mt-1">Foundation-профили гостей по телефону для операционной работы.</p>
            </div>
            <a href="/restaurant/crm.php" class="px-3 py-2 rounded-xl bg-slate-800 border border-slate-700 hover:bg-slate-700 text-sm">Открыть CRM</a>
        </div>

        <form method="get" class="rounded-2xl border border-slate-800 bg-slate-900/70 p-3 md:p-4 flex flex-col md:flex-row gap-2">
            <input
                type="text"
                name="q"
                value="<?= e($queryRaw) ?>"
                placeholder="Поиск по телефону или имени"
                class="flex-1 rounded-xl bg-slate-950 border border-slate-700 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
            >
            <button type="submit" class="rounded-xl px-4 py-2 text-sm font-medium bg-indigo-600 hover:bg-indigo-500">Найти</button>
            <?php if ($queryRaw !== ''): ?>
                <a href="/restaurant/guests.php" class="rounded-xl px-4 py-2 text-sm bg-slate-800 border border-slate-700 hover:bg-slate-700 text-center">Сбросить</a>
            <?php endif; ?>
        </form>

        <section class="grid grid-cols-1 sm:grid-cols-3 gap-3">
            <div class="rounded-2xl border border-slate-800 bg-slate-900/70 p-4">
                <div class="text-xs uppercase tracking-wide text-slate-400">Профилей</div>
                <div class="text-2xl font-semibold mt-1"><?= (int)$totalGuests ?></div>
            </div>
            <div class="rounded-2xl border border-slate-800 bg-slate-900/70 p-4">
                <div class="text-xs uppercase tracking-wide text-slate-400">Сумма заказов</div>
                <div class="text-2xl font-semibold mt-1"><?= e(number_format($totalSpent, 0, '.', ' ')) ?> ₽</div>
            </div>
            <div class="rounded-2xl border border-slate-800 bg-slate-900/70 p-4">
                <div class="text-xs uppercase tracking-wide text-slate-400">Режим</div>
                <div class="text-sm font-medium mt-2 text-emerald-300">Phone identity foundation</div>
            </div>
        </section>

        <section class="rounded-2xl border border-slate-800 bg-slate-900/70 p-4 space-y-3">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-sm font-semibold text-slate-100">Отзывы / NPS (операционно)</h2>
                <span class="text-xs text-slate-500">Последние 120 дней</span>
            </div>
            <div class="grid grid-cols-2 md:grid-cols-5 gap-2 text-xs">
                <div class="rounded-xl border border-slate-800 bg-slate-950/60 px-3 py-2">
                    <div class="text-slate-500">Всего отзывов</div>
                    <div class="text-slate-100 text-lg font-semibold mt-1"><?= (int)($reviewSummary['total_reviews'] ?? 0) ?></div>
                </div>
                <div class="rounded-xl border border-slate-800 bg-slate-950/60 px-3 py-2">
                    <div class="text-slate-500">Средний рейтинг</div>
                    <div class="text-slate-100 text-lg font-semibold mt-1">
                        <?= ($reviewSummary['average_rating'] ?? null) !== null ? e(number_format((float)$reviewSummary['average_rating'], 2, '.', ' ')) : '—' ?>
                    </div>
                </div>
                <div class="rounded-xl border border-slate-800 bg-slate-950/60 px-3 py-2">
                    <div class="text-slate-500">Средний NPS score</div>
                    <div class="text-slate-100 text-lg font-semibold mt-1">
                        <?= ($reviewSummary['average_nps'] ?? null) !== null ? e(number_format((float)$reviewSummary['average_nps'], 2, '.', ' ')) : '—' ?>
                    </div>
                </div>
                <div class="rounded-xl border border-slate-800 bg-slate-950/60 px-3 py-2">
                    <div class="text-slate-500">NPS индекс</div>
                    <div class="text-slate-100 text-lg font-semibold mt-1">
                        <?= ($reviewSummary['nps_score'] ?? null) !== null ? (int)$reviewSummary['nps_score'] : '—' ?>
                    </div>
                </div>
                <div class="rounded-xl border border-slate-800 bg-slate-950/60 px-3 py-2">
                    <div class="text-slate-500">Низкий рейтинг (≤2)</div>
                    <div class="text-rose-300 text-lg font-semibold mt-1"><?= (int)($reviewSummary['negative_reviews'] ?? 0) ?></div>
                </div>
            </div>

            <div class="grid grid-cols-1 xl:grid-cols-2 gap-3">
                <div class="rounded-xl border border-slate-800 bg-slate-950/60 p-3">
                    <div class="text-xs text-slate-400 mb-2">Последние отзывы</div>
                    <?php
                    $reviewTagLabels = [
                        'fast_delivery' => 'Быстрая доставка',
                        'tasty_food' => 'Вкусная еда',
                        'cold_food' => 'Еда остыла',
                        'late_delivery' => 'Долгая доставка',
                        'polite_courier' => 'Вежливый курьер',
                        'bad_packaging' => 'Плохая упаковка',
                    ];
                    $latestReviews = is_array($reviewSummary['latest_reviews'] ?? null) ? $reviewSummary['latest_reviews'] : [];
                    ?>
                    <?php if ($latestReviews === []): ?>
                        <div class="text-xs text-slate-500">Отзывов пока нет.</div>
                    <?php else: ?>
                        <div class="space-y-2">
                            <?php foreach ($latestReviews as $rv): ?>
                                <?php
                                $rvTs = !empty($rv['created_at']) ? strtotime((string)$rv['created_at']) : false;
                                $rvDate = $rvTs ? date('d.m H:i', $rvTs) : '—';
                                $rvTags = is_array($rv['review_tags'] ?? null) ? $rv['review_tags'] : [];
                                ?>
                                <div class="rounded-lg border border-slate-800 bg-slate-900/60 px-2.5 py-2">
                                    <div class="flex flex-wrap items-center gap-2 text-[11px]">
                                        <span class="text-slate-400">#<?= (int)($rv['order_id'] ?? 0) ?></span>
                                        <span class="text-amber-300">★ <?= (int)($rv['rating'] ?? 0) ?>/5</span>
                                        <?php if (($rv['nps_score'] ?? null) !== null): ?>
                                            <span class="text-indigo-300">NPS <?= (int)$rv['nps_score'] ?>/10</span>
                                        <?php endif; ?>
                                        <span class="text-slate-500"><?= e($rvDate) ?></span>
                                    </div>
                                    <?php if (trim((string)($rv['review_text'] ?? '')) !== ''): ?>
                                        <div class="text-xs text-slate-300 mt-1"><?= e((string)$rv['review_text']) ?></div>
                                    <?php endif; ?>
                                    <?php if ($rvTags !== []): ?>
                                        <div class="flex flex-wrap gap-1 mt-1.5">
                                            <?php foreach ($rvTags as $tag): ?>
                                                <?php $tagKey = trim((string)$tag); if ($tagKey === '') { continue; } ?>
                                                <span class="inline-flex items-center rounded-full border border-slate-700 bg-slate-800/80 px-2 py-0.5 text-[10px] text-slate-300">
                                                    <?= e($reviewTagLabels[$tagKey] ?? $tagKey) ?>
                                                </span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="rounded-xl border border-rose-900/60 bg-rose-950/20 p-3">
                    <div class="text-xs text-rose-200 mb-2">Алерты: низкие оценки</div>
                    <?php $lowAlerts = is_array($reviewSummary['low_rating_alerts'] ?? null) ? $reviewSummary['low_rating_alerts'] : []; ?>
                    <?php if ($lowAlerts === []): ?>
                        <div class="text-xs text-rose-100/70">Критичных отзывов не найдено.</div>
                    <?php else: ?>
                        <div class="space-y-2">
                            <?php foreach ($lowAlerts as $rv): ?>
                                <?php
                                $rvTs = !empty($rv['created_at']) ? strtotime((string)$rv['created_at']) : false;
                                $rvDate = $rvTs ? date('d.m H:i', $rvTs) : '—';
                                ?>
                                <div class="rounded-lg border border-rose-800/60 bg-rose-900/20 px-2.5 py-2 text-xs">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="text-rose-200 font-medium">#<?= (int)($rv['order_id'] ?? 0) ?></span>
                                        <span class="text-rose-200">★ <?= (int)($rv['rating'] ?? 0) ?>/5</span>
                                        <span class="text-rose-100/80"><?= e($rvDate) ?></span>
                                    </div>
                                    <?php if (trim((string)($rv['review_text'] ?? '')) !== ''): ?>
                                        <div class="text-rose-100/80 mt-1"><?= e((string)$rv['review_text']) ?></div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <section class="rounded-2xl border border-slate-800 bg-slate-900/70 p-4 space-y-3">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-sm font-semibold text-slate-100">Digital Tips (операционно)</h2>
                <span class="text-xs text-slate-500">Последние 120 дней</span>
            </div>
            <div class="grid grid-cols-2 md:grid-cols-5 gap-2 text-xs">
                <div class="rounded-xl border border-slate-800 bg-slate-950/60 px-3 py-2">
                    <div class="text-slate-500">Всего чаевых</div>
                    <div class="text-slate-100 text-lg font-semibold mt-1"><?= e(number_format((float)($tipsSummary['total_tips'] ?? 0), 0, '.', ' ')) ?> ₽</div>
                </div>
                <div class="rounded-xl border border-slate-800 bg-slate-950/60 px-3 py-2">
                    <div class="text-slate-500">Официантам</div>
                    <div class="text-slate-100 text-lg font-semibold mt-1"><?= e(number_format((float)($tipsSummary['waiter_tips'] ?? 0), 0, '.', ' ')) ?> ₽</div>
                </div>
                <div class="rounded-xl border border-slate-800 bg-slate-950/60 px-3 py-2">
                    <div class="text-slate-500">Курьерам</div>
                    <div class="text-slate-100 text-lg font-semibold mt-1"><?= e(number_format((float)($tipsSummary['courier_tips'] ?? 0), 0, '.', ' ')) ?> ₽</div>
                </div>
                <div class="rounded-xl border border-slate-800 bg-slate-950/60 px-3 py-2">
                    <div class="text-slate-500">Средние чаевые</div>
                    <div class="text-slate-100 text-lg font-semibold mt-1"><?= e(number_format((float)($tipsSummary['average_tip'] ?? 0), 0, '.', ' ')) ?> ₽</div>
                </div>
                <div class="rounded-xl border border-slate-800 bg-slate-950/60 px-3 py-2">
                    <div class="text-slate-500">Статусы</div>
                    <div class="text-[11px] text-slate-300 mt-1">
                        paid <?= (int)($tipsSummary['paid_count'] ?? 0) ?> · pending <?= (int)($tipsSummary['pending_count'] ?? 0) ?> · cancelled <?= (int)($tipsSummary['cancelled_count'] ?? 0) ?>
                    </div>
                </div>
            </div>
            <?php $topTips = is_array($tipsSummary['top_tipped_staff'] ?? null) ? $tipsSummary['top_tipped_staff'] : []; ?>
            <div class="rounded-xl border border-slate-800 bg-slate-950/60 p-3">
                <div class="text-xs text-slate-400 mb-2">Top tipped staff</div>
                <?php if ($topTips === []): ?>
                    <div class="text-xs text-slate-500">Пока нет оплаченных чаевых с привязкой к сотруднику.</div>
                <?php else: ?>
                    <div class="space-y-1.5">
                        <?php foreach ($topTips as $tipTop): ?>
                            <div class="flex items-center justify-between gap-2 rounded-lg border border-slate-800 bg-slate-900/60 px-2.5 py-2 text-xs">
                                <div class="text-slate-200">
                                    <?= e((string)($tipTop['name'] ?? 'User')) ?>
                                    <span class="text-slate-500">(<?= e((string)($tipTop['target'] ?? 'waiter')) ?>)</span>
                                </div>
                                <div class="text-emerald-300 font-medium">
                                    <?= e(number_format((float)($tipTop['total_amount'] ?? 0), 0, '.', ' ')) ?> ₽ · <?= (int)($tipTop['tips_count'] ?? 0) ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <section class="rounded-2xl border border-slate-800 bg-slate-900/70 p-4">
            <div class="text-sm text-slate-300">
                История заказов (preview): загружено для <span class="text-slate-100 font-medium"><?= (int)$historyLoadedGuests ?></span>
                из <?= (int)$historyLoadableGuests ?> гостей (лимит на страницу: <?= (int)$historyLoadCap ?>).
                <?php if ($historySkippedGuests > 0): ?>
                    <span class="text-slate-500">Пропущено: <?= (int)$historySkippedGuests ?>.</span>
                <?php endif; ?>
            </div>
            <div class="text-sm text-slate-300 mt-1">
                Wallet preview: загружено для <span class="text-slate-100 font-medium"><?= (int)$walletLoadedGuests ?></span>
                из <?= (int)$walletLoadableGuests ?> гостей (лимит на страницу: <?= (int)$walletLoadCap ?>).
                <?php if ($walletSkippedGuests > 0): ?>
                    <span class="text-slate-500">Пропущено: <?= (int)$walletSkippedGuests ?>.</span>
                <?php endif; ?>
            </div>
            <div class="text-sm text-slate-300 mt-1">
                RFM preview: загружено для <span class="text-slate-100 font-medium"><?= (int)$rfmLoadedGuests ?></span>
                из <?= (int)$rfmLoadableGuests ?> гостей (лимит на страницу: <?= (int)$rfmLoadCap ?>).
                <?php if ($rfmSkippedGuests > 0): ?>
                    <span class="text-slate-500">Пропущено: <?= (int)$rfmSkippedGuests ?>.</span>
                <?php endif; ?>
            </div>
        </section>

        <section class="rounded-2xl border border-slate-800 bg-slate-900/70 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-900/90">
                        <tr class="text-left text-slate-400">
                            <th class="px-4 py-3 font-medium">Гость</th>
                            <th class="px-4 py-3 font-medium">Телефон</th>
                            <th class="px-4 py-3 font-medium">Заказов</th>
                            <th class="px-4 py-3 font-medium">Потратил</th>
                            <th class="px-4 py-3 font-medium">Средний чек</th>
                            <th class="px-4 py-3 font-medium">Последний визит</th>
                            <th class="px-4 py-3 font-medium">RFM / Сегмент</th>
                            <th class="px-4 py-3 font-medium">Последний тип</th>
                            <th class="px-4 py-3 font-medium">История (3)</th>
                            <th class="px-4 py-3 font-medium">Wallet / Loyalty</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($rows === []): ?>
                            <tr>
                                <td colspan="10" class="px-4 py-8 text-center text-slate-500">
                                    <?= $queryRaw !== '' ? 'По вашему запросу гостей не найдено.' : 'Профили гостей пока не сформированы.' ?>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($rows as $row): ?>
                                <?php
                                $name = trim((string)($row['guest_name'] ?? ''));
                                if ($name === '') {
                                    $name = 'Гость';
                                }
                                $lastAt = trim((string)($row['last_order_at'] ?? ''));
                                $lastAtLabel = '—';
                                if ($lastAt !== '') {
                                    $ts = strtotime($lastAt);
                                    $lastAtLabel = $ts ? date('d.m.Y H:i', $ts) : $lastAt;
                                }
                                $phoneNorm = trim((string)($row['phone_normalized'] ?? ''));
                                $historyPack = $phoneNorm !== '' ? ($historyPreviewByPhone[$phoneNorm] ?? null) : null;
                                $previewOrders = is_array($historyPack) ? ($historyPack['orders'] ?? []) : [];
                                $walletPack = $phoneNorm !== '' ? ($walletPreviewByPhone[$phoneNorm] ?? null) : null;
                                $walletModel = is_array($walletPack) ? ($walletPack['wallet'] ?? null) : null;
                                $ledgerPreview = is_array($walletPack) ? ($walletPack['ledger'] ?? []) : [];
                                $rfmPack = $phoneNorm !== '' ? ($rfmPreviewByPhone[$phoneNorm] ?? null) : null;
                                $rfmMetrics = is_array($rfmPack) ? ($rfmPack['metrics'] ?? null) : null;
                                $rfmScore = is_array($rfmPack) ? ($rfmPack['score'] ?? null) : null;
                                $rfmSegment = is_array($rfmPack) ? ($rfmPack['segment'] ?? null) : null;
                                $lastTypeLabel = '—';
                                if (is_array($previewOrders) && $previewOrders !== []) {
                                    $lastTypeLabel = trim((string)($previewOrders[0]['order_type_label'] ?? ''));
                                    if ($lastTypeLabel === '') {
                                        $lastTypeLabel = 'Зал';
                                    }
                                }
                                $segmentKey = is_array($rfmSegment) ? trim((string)($rfmSegment['key'] ?? '')) : '';
                                $segmentLabel = is_array($rfmSegment) ? trim((string)($rfmSegment['label'] ?? '')) : '';
                                if ($segmentLabel === '') {
                                    $segmentLabel = '—';
                                }
                                $segmentBadgeClass = 'border-slate-700 bg-slate-800/70 text-slate-200';
                                if ($segmentKey === 'vip') {
                                    $segmentBadgeClass = 'border-amber-400/70 bg-amber-500/20 text-amber-100';
                                } elseif ($segmentKey === 'loyal') {
                                    $segmentBadgeClass = 'border-emerald-500/70 bg-emerald-500/15 text-emerald-100';
                                } elseif ($segmentKey === 'regular') {
                                    $segmentBadgeClass = 'border-sky-500/70 bg-sky-500/15 text-sky-100';
                                } elseif ($segmentKey === 'new') {
                                    $segmentBadgeClass = 'border-indigo-500/70 bg-indigo-500/15 text-indigo-100';
                                } elseif ($segmentKey === 'sleeping') {
                                    $segmentBadgeClass = 'border-slate-600/80 bg-slate-800/80 text-slate-300';
                                } elseif ($segmentKey === 'at_risk') {
                                    $segmentBadgeClass = 'border-rose-500/70 bg-rose-500/15 text-rose-100';
                                }
                                ?>
                                <tr class="border-t border-slate-800/80">
                                    <td class="px-4 py-3 text-slate-100"><?= e($name) ?></td>
                                    <td class="px-4 py-3 text-slate-300"><?= e((string)($row['phone_normalized'] ?? '')) ?></td>
                                    <td class="px-4 py-3 text-slate-200"><?= (int)($row['orders_count'] ?? 0) ?></td>
                                    <td class="px-4 py-3 text-emerald-300"><?= e(number_format((float)($row['total_spent'] ?? 0), 0, '.', ' ')) ?> ₽</td>
                                    <td class="px-4 py-3 text-slate-200"><?= e(number_format((float)($row['average_check'] ?? 0), 0, '.', ' ')) ?> ₽</td>
                                    <td class="px-4 py-3 text-slate-300"><?= e($lastAtLabel) ?></td>
                                    <td class="px-4 py-3 min-w-[210px] align-top">
                                        <?php if (is_array($rfmMetrics) && is_array($rfmScore)): ?>
                                            <?php
                                            $recencyDays = isset($rfmMetrics['recency_days']) && $rfmMetrics['recency_days'] !== null
                                                ? (int)$rfmMetrics['recency_days']
                                                : null;
                                            $ordersCountRfm = (int)($rfmMetrics['frequency_count'] ?? $rfmMetrics['orders_count'] ?? 0);
                                            $totalSpentRfm = (float)($rfmMetrics['monetary_total'] ?? $rfmMetrics['total_spent'] ?? 0);
                                            $avgCheckRfm = (float)($rfmMetrics['monetary_avg'] ?? $rfmMetrics['average_check'] ?? 0);
                                            ?>
                                            <div class="space-y-1.5">
                                                <div class="flex flex-wrap items-center gap-1.5">
                                                    <span class="inline-flex items-center rounded-full border px-2.5 py-1 text-[11px] <?= e($segmentBadgeClass) ?>">
                                                        <?= e($segmentLabel) ?>
                                                    </span>
                                                    <span class="inline-flex items-center rounded-full border border-slate-700 bg-slate-900/80 px-2 py-0.5 text-[10px] text-slate-300">
                                                        R<?= (int)($rfmScore['r'] ?? 1) ?> F<?= (int)($rfmScore['f'] ?? 1) ?> M<?= (int)($rfmScore['m'] ?? 1) ?>
                                                    </span>
                                                </div>
                                                <div class="text-[11px] text-slate-400">
                                                    <?= $recencyDays === null ? 'Нет заказов' : ('Последний заказ: ' . $recencyDays . ' дн. назад') ?>
                                                </div>
                                                <div class="text-[11px] text-slate-400">
                                                    F: <?= (int)$ordersCountRfm ?> • M: <?= e(number_format($totalSpentRfm, 0, '.', ' ')) ?> ₽ • AVG: <?= e(number_format($avgCheckRfm, 0, '.', ' ')) ?> ₽
                                                </div>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-xs text-slate-500">RFM недоступен</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-3">
                                        <span class="inline-flex items-center rounded-full border border-slate-700 bg-slate-800/70 px-2.5 py-1 text-xs text-slate-200"><?= e($lastTypeLabel) ?></span>
                                    </td>
                                    <td class="px-4 py-3 min-w-[320px]">
                                        <?php if (is_array($previewOrders) && $previewOrders !== []): ?>
                                            <div class="space-y-2">
                                                <?php foreach ($previewOrders as $ord): ?>
                                                    <?php
                                                    $oId = (int)($ord['order_id'] ?? 0);
                                                    $oStatus = trim((string)($ord['status'] ?? ''));
                                                    if ($oStatus === '') {
                                                        $oStatus = '—';
                                                    }
                                                    $oType = trim((string)($ord['order_type_label'] ?? ''));
                                                    if ($oType === '') {
                                                        $oType = 'Зал';
                                                    }
                                                    $oDateRaw = trim((string)($ord['created_at'] ?? ''));
                                                    $oDateLabel = '—';
                                                    if ($oDateRaw !== '') {
                                                        $oTs = strtotime($oDateRaw);
                                                        $oDateLabel = $oTs ? date('d.m H:i', $oTs) : $oDateRaw;
                                                    }
                                                    $oTotal = (float)($ord['total_amount'] ?? 0);
                                                    $oItemsSummary = trim((string)($ord['items_summary'] ?? ''));
                                                    ?>
                                                    <div class="rounded-xl border border-slate-800 bg-slate-950/70 px-3 py-2">
                                                        <div class="flex flex-wrap items-center gap-2 text-xs">
                                                            <span class="text-slate-400">#<?= $oId ?></span>
                                                            <span class="text-slate-300"><?= e($oDateLabel) ?></span>
                                                            <span class="inline-flex items-center rounded-full border border-indigo-700/60 bg-indigo-900/30 px-2 py-0.5 text-[11px] text-indigo-200"><?= e($oType) ?></span>
                                                            <span class="inline-flex items-center rounded-full border border-slate-700 bg-slate-800/70 px-2 py-0.5 text-[11px] text-slate-200"><?= e($oStatus) ?></span>
                                                            <span class="text-emerald-300 font-medium"><?= e(number_format($oTotal, 0, '.', ' ')) ?> ₽</span>
                                                        </div>
                                                        <?php if ($oItemsSummary !== ''): ?>
                                                            <div class="mt-1 text-xs text-slate-400"><?= e($oItemsSummary) ?></div>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-xs text-slate-500">История недоступна или пока пуста</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-3 min-w-[340px] align-top">
                                        <?php if (is_array($walletModel)): ?>
                                            <?php
                                            $walletSource = trim((string)($walletModel['source'] ?? 'none'));
                                            if ($walletSource === '') {
                                                $walletSource = 'none';
                                            }
                                            $walletSourceLabel = [
                                                'guest_loyalty' => 'Canonical guest ledger',
                                                'loyalty_card' => 'Legacy card ledger',
                                                'loyalty_phone_legacy' => 'Legacy phone ledger',
                                                'none' => 'Нет кошелька',
                                            ][$walletSource] ?? $walletSource;
                                            $currentBalance = (int)($walletModel['current_balance'] ?? 0);
                                            $totalEarned = (int)($walletModel['total_earned'] ?? 0);
                                            $totalSpentPoints = (int)($walletModel['total_spent'] ?? 0);
                                            ?>
                                            <div class="space-y-2">
                                                <div class="rounded-xl border border-slate-800 bg-slate-950/70 px-3 py-2">
                                                    <div class="flex flex-wrap items-center gap-2 text-xs">
                                                        <span class="inline-flex items-center rounded-full border border-emerald-700/70 bg-emerald-900/30 px-2 py-0.5 text-emerald-200">Баланс: <?= number_format($currentBalance, 0, '.', ' ') ?></span>
                                                        <span class="inline-flex items-center rounded-full border border-slate-700 bg-slate-800/70 px-2 py-0.5 text-slate-200">+<?= number_format($totalEarned, 0, '.', ' ') ?></span>
                                                        <span class="inline-flex items-center rounded-full border border-slate-700 bg-slate-800/70 px-2 py-0.5 text-slate-200">−<?= number_format($totalSpentPoints, 0, '.', ' ') ?></span>
                                                    </div>
                                                    <div class="mt-1 text-[11px] text-slate-400">Источник: <?= e($walletSourceLabel) ?></div>
                                                </div>

                                                <?php if (is_array($ledgerPreview) && $ledgerPreview !== []): ?>
                                                    <div class="space-y-1.5">
                                                        <?php foreach ($ledgerPreview as $entry): ?>
                                                            <?php
                                                            $opType = trim((string)($entry['operation_label'] ?? $entry['operation_type'] ?? 'Операция'));
                                                            $opPoints = (int)($entry['points'] ?? 0);
                                                            $opDateRaw = trim((string)($entry['created_at'] ?? ''));
                                                            $opDateLabel = '—';
                                                            if ($opDateRaw !== '') {
                                                                $opTs = strtotime($opDateRaw);
                                                                $opDateLabel = $opTs ? date('d.m H:i', $opTs) : $opDateRaw;
                                                            }
                                                            $opOrderId = isset($entry['order_id']) ? (int)$entry['order_id'] : 0;
                                                            $opNote = trim((string)($entry['note'] ?? ''));
                                                            ?>
                                                            <div class="rounded-lg border border-slate-800 bg-slate-950/60 px-2.5 py-2 text-xs">
                                                                <div class="flex flex-wrap items-center gap-2">
                                                                    <span class="text-slate-200"><?= e($opType) ?></span>
                                                                    <span class="<?= $opPoints >= 0 ? 'text-emerald-300' : 'text-rose-300' ?> font-medium">
                                                                        <?= $opPoints > 0 ? '+' : '' ?><?= number_format($opPoints, 0, '.', ' ') ?>
                                                                    </span>
                                                                    <span class="text-slate-400"><?= e($opDateLabel) ?></span>
                                                                    <?php if ($opOrderId > 0): ?>
                                                                        <span class="text-slate-500">#<?= $opOrderId ?></span>
                                                                    <?php endif; ?>
                                                                </div>
                                                                <?php if ($opNote !== ''): ?>
                                                                    <div class="mt-1 text-[11px] text-slate-500"><?= e($opNote) ?></div>
                                                                <?php endif; ?>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                <?php else: ?>
                                                    <div class="text-xs text-slate-500">Операций пока нет</div>
                                                <?php endif; ?>

                                                <div class="flex flex-wrap gap-2 pt-1">
                                                    <?php if ($phoneNorm !== '' && $csrfToken !== ''): ?>
                                                        <form class="js-wallet-adjust-form flex flex-wrap items-center gap-2" method="post" action="/ajax/guest_wallet_adjust.php">
                                                            <input type="hidden" name="csrf" value="<?= e($csrfToken) ?>">
                                                            <input type="hidden" name="guest_profile_id" value="<?= (int)($row['id'] ?? 0) ?>">
                                                            <input type="hidden" name="phone_normalized" value="<?= e($phoneNorm) ?>">
                                                            <select name="operation" class="rounded-lg bg-slate-900 border border-slate-700 px-2 py-1 text-[11px] text-slate-200">
                                                                <option value="earn">Начислить</option>
                                                                <option value="spend">Списать</option>
                                                                <option value="manual_adjustment">Корректировка</option>
                                                                <option value="refund">Возврат</option>
                                                            </select>
                                                            <select name="adjust_direction" class="rounded-lg bg-slate-900 border border-slate-700 px-2 py-1 text-[11px] text-slate-200">
                                                                <option value="plus">+ </option>
                                                                <option value="minus">− </option>
                                                            </select>
                                                            <input type="number" name="points" min="1" max="1000000" value="10" class="w-20 rounded-lg bg-slate-900 border border-slate-700 px-2 py-1 text-[11px] text-slate-100" required>
                                                            <input type="text" name="note" placeholder="Комментарий" maxlength="180" class="w-36 rounded-lg bg-slate-900 border border-slate-700 px-2 py-1 text-[11px] text-slate-200">
                                                            <button type="submit" class="inline-flex items-center rounded-lg border border-indigo-700/70 bg-indigo-900/30 px-2.5 py-1 text-[11px] text-indigo-200 hover:bg-indigo-900/50">Применить</button>
                                                            <a href="/staff/loyalty_scan.php?phone=<?= rawurlencode($phoneNorm) ?>" class="inline-flex items-center rounded-lg border border-slate-700 bg-slate-800/70 px-2.5 py-1 text-[11px] text-slate-200 hover:bg-slate-700">Подробно</a>
                                                        </form>
                                                        <div class="js-wallet-adjust-result text-[11px] text-slate-400 mt-1"></div>
                                                    <?php else: ?>
                                                        <button type="button" class="inline-flex items-center rounded-lg border border-slate-800 bg-slate-900/60 px-2.5 py-1 text-[11px] text-slate-500 cursor-not-allowed" disabled>Начислить</button>
                                                        <button type="button" class="inline-flex items-center rounded-lg border border-slate-800 bg-slate-900/60 px-2.5 py-1 text-[11px] text-slate-500 cursor-not-allowed" disabled>Списать</button>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-xs text-slate-500">Wallet preview недоступен</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</main>
<script>
document.querySelectorAll('.js-wallet-adjust-form').forEach(function(form) {
    form.addEventListener('submit', function(e) {
        e.preventDefault();
        var resultEl = form.parentElement ? form.parentElement.querySelector('.js-wallet-adjust-result') : null;
        var submitBtn = form.querySelector('button[type="submit"]');
        if (submitBtn) submitBtn.disabled = true;
        if (resultEl) {
            resultEl.textContent = 'Сохраняем...';
            resultEl.className = 'js-wallet-adjust-result text-[11px] text-slate-400 mt-1';
        }
        fetch(form.getAttribute('action') || '/ajax/guest_wallet_adjust.php', {
            method: 'POST',
            body: new FormData(form),
            credentials: 'same-origin'
        })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (!resultEl) return;
            if (data && data.success) {
                var balance = data.wallet && typeof data.wallet.current_balance !== 'undefined'
                    ? (' Баланс: ' + data.wallet.current_balance)
                    : '';
                resultEl.textContent = 'Операция выполнена.' + balance + ' Обновляем данные...';
                resultEl.className = 'js-wallet-adjust-result text-[11px] text-emerald-300 mt-1';
                window.setTimeout(function() {
                    window.location.reload();
                }, 700);
            } else {
                var message = (data && data.message) ? String(data.message) : 'Операция не выполнена';
                resultEl.textContent = 'Ошибка: ' + message;
                resultEl.className = 'js-wallet-adjust-result text-[11px] text-rose-300 mt-1';
            }
        })
        .catch(function() {
            if (resultEl) {
                resultEl.textContent = 'Ошибка сети при сохранении';
                resultEl.className = 'js-wallet-adjust-result text-[11px] text-rose-300 mt-1';
            }
        })
        .finally(function() {
            if (submitBtn) submitBtn.disabled = false;
        });
    });
});
</script>
</body>
</html>
