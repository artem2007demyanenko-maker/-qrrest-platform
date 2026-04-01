<?php

require_once __DIR__ . '/../app/bootstrap.php';

$rid = bin2hex(random_bytes(4));

set_exception_handler(function (Throwable $e) use ($rid) {
    error_log('PUBLIC_PAGE_ERROR rid=' . $rid . ' ' . json_encode([
        'uri' => $_SERVER['REQUEST_URI'] ?? '',
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'time' => gmdate('c'),
    ], JSON_UNESCAPED_UNICODE));
    if (!headers_sent()) {
        http_response_code(200);
    }
    $isJson = strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false;
    if ($isJson) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'internal_error', 'rid' => $rid]);
    } else {
        echo '<h1>Ошибка</h1><p>Попробуйте позже.</p><p style="font-size:11px;color:#666">RID: ' . htmlspecialchars($rid) . '</p>';
    }
    exit;
});

$pdo = db();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../app/order_feedback_guard.php';
require_once __DIR__ . '/../app/yandex_review_url_validate.php';

$tableId  = isset($_GET['table_id']) ? (int)$_GET['table_id'] : 0;
$orderIdRaw = isset($_GET['order_id']) ? trim((string)$_GET['order_id']) : '';
$orderId  = (ctype_digit($orderIdRaw) && $orderIdRaw !== '') ? (int)$orderIdRaw : 0;

$orderIdMode = ($orderId > 0);

// Mode 1: order_id only — load order, derive restaurant and table from it.
if ($orderIdMode && $orderId > 0) {
    $stmt = $pdo->prepare("SELECT id, restaurant_id, table_id, total_price, payment_type, payment_status, order_status,
        loyalty_phone, loyalty_points_accrued, loyalty_points_spent, loyalty_points_balance_after, created_at, updated_at
        FROM orders WHERE id = :oid LIMIT 1");
    $stmt->execute([':oid' => $orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'order_not_found'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $restaurantId = (int)$order['restaurant_id'];
    $tableId      = (int)$order['table_id'];
    if ($restaurantId <= 0 || $tableId <= 0) {
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'order_invalid'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!function_exists('schema_guard_restaurants_deleted_sql')) {
        require_once __DIR__ . '/../app/schema_guard.php';
    }
    $deletedSql = schema_guard_restaurants_deleted_sql('r');
    $statusSql  = (function_exists('db_column_exists') && db_column_exists('restaurants', 'status')) ? " AND r.status = 'active'" : '';
    $stmt = $pdo->prepare("SELECT r.* FROM restaurants r WHERE r.id = :rid" . $statusSql . $deletedSql . " LIMIT 1");
    $stmt->execute([':rid' => $restaurantId]);
    $currentRestaurant = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$currentRestaurant) {
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'restaurant_not_found'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $table = null;
    $tStmt = $pdo->prepare("SELECT * FROM tables WHERE id = :id AND restaurant_id = :rest LIMIT 1");
    $tStmt->execute([':id' => $tableId, ':rest' => $restaurantId]);
    $table = $tStmt->fetch(PDO::FETCH_ASSOC);
    if (!$table) {
        http_response_code(404);
        echo "Стол не найден.";
        exit;
    }
    // Continue to order items and HTML (same order row already loaded).
} else {
    // Mode 2: require subdomain restaurant + table_id + order_id + session.
    if (!$currentRestaurant) {
        http_response_code(404);
        echo "Ресторан не найден (по поддомену).";
        exit;
    }
    if ($tableId <= 0 || $orderId <= 0) {
        http_response_code(400);
        echo "Некорректные параметры.";
        exit;
    }
    $trackKey = 'last_order_' . $currentRestaurant['id'] . '_' . $tableId;
    if (empty($_SESSION[$trackKey]) || (int)$_SESSION[$trackKey] !== $orderId) {
        http_response_code(404);
        echo "Заказ не найден.";
        exit;
    }
    $stmt = $pdo->prepare("SELECT * FROM tables WHERE id = :id AND restaurant_id = :rest LIMIT 1");
    $stmt->execute([':id' => $tableId, ':rest' => $currentRestaurant['id']]);
    $table = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$table) {
        http_response_code(404);
        echo "Стол не найден.";
        exit;
    }
    $stmt = $pdo->prepare("
        SELECT id, restaurant_id, total_price, payment_type, payment_status, order_status,
               loyalty_phone, loyalty_points_accrued, loyalty_points_spent, loyalty_points_balance_after,
               created_at, updated_at
        FROM orders
        WHERE id = :oid AND restaurant_id = :rest AND table_id = :table
        LIMIT 1
    ");
    $stmt->execute([
        ':oid'   => $orderId,
        ':rest'  => $currentRestaurant['id'],
        ':table' => $tableId,
    ]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        http_response_code(404);
        echo "Заказ не найден.";
        exit;
    }
}

$feedbackRestId = (int)($order['restaurant_id'] ?? 0);
if ($feedbackRestId <= 0) {
    $feedbackRestId = (int)($currentRestaurant['id'] ?? 0);
}
$feedbackTrackTrusted = order_feedback_track_context_valid($feedbackRestId, $tableId, $orderId);

$stmt = $pdo->prepare("
    SELECT oi.quantity, oi.price, mi.name
    FROM order_items oi
    JOIN menu_items mi ON mi.id = oi.menu_item_id
    WHERE oi.order_id = :oid
    ORDER BY oi.id ASC
");
$stmt->execute([':oid' => $orderId]);
$orderItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

$hasOrderFeedback = false;
$orderFeedbackRating = 0;
try {
    if (file_exists(__DIR__ . '/../app/schema_guard.php')) {
        require_once __DIR__ . '/../app/schema_guard.php';
    }
    if (function_exists('db_table_exists') && db_table_exists('order_feedback')) {
        $fbStmt = $pdo->prepare("SELECT id, rating FROM order_feedback WHERE order_id = :oid LIMIT 1");
        $fbStmt->execute([':oid' => $orderId]);
        $fbRow = $fbStmt->fetch(PDO::FETCH_ASSOC);
        $hasOrderFeedback = (bool)$fbRow;
        $orderFeedbackRating = (int)($fbRow['rating'] ?? 0);
    }
} catch (Throwable $e) {
    error_log('ORDER_TRACK_FEEDBACK_CHECK_FAIL rid=' . $rid . ' order_id=' . (int)$orderId . ' ' . $e->getMessage());
    $hasOrderFeedback = false;
}

$yandexReviewUrl = trim((string)($currentRestaurant['yandex_review_url'] ?? ''));
if ($yandexReviewUrl !== '') {
    $yandexReviewUrl = normalize_yandex_maps_review_url($yandexReviewUrl);
    if (!yandex_maps_review_url_is_valid($yandexReviewUrl)) {
        $yandexReviewUrl = '';
    }
}
$showYandexReviewPrompt = !is_demo_mode()
    && $yandexReviewUrl !== ''
    && ($order['order_status'] ?? '') === 'delivered'
    && $hasOrderFeedback
    && $orderFeedbackRating >= 5;

function map_order_status_for_guest(string $status): array {
    $status = $status ?: 'new';

    $label    = 'Заказ принят';
    $desc     = 'Мы приняли ваш заказ и передали его на кухню.';
    $progress = 25;

    switch ($status) {
        case 'in_progress':
            $label    = 'Готовится';
            $desc     = 'Кухня уже готовит ваш заказ.';
            $progress = 60;
            break;
        case 'done':
        case 'ready':
            $label    = 'Готов';
            $desc     = 'Ваш заказ готов, скоро его принесут к столу.';
            $progress = 100;
            break;
        case 'delivered':
            $label    = 'Заказ получен';
            $desc     = 'Спасибо! Оцените, пожалуйста, ваш заказ.';
            $progress = 100;
            break;
        case 'canceled':
            $label    = 'Отменён';
            $desc     = 'Заказ отменён. Уточните детали у персонала.';
            $progress = 0;
            break;
        case 'new':
        default:
            break;
    }

    return [$label, $desc, $progress];
}

[$label, $desc, $progress] = map_order_status_for_guest($order['order_status'] ?? 'new');

// Таймер ожидания оплаты для офлайн-способов (cash/card_later/pay_later) — 10 минут после создания заказа.
$offlinePayTypes = ['cash', 'card_later', 'pay_later'];
$orderPaymentStatus = (string)($order['payment_status'] ?? 'unpaid');
$orderPaymentType = (string)($order['payment_type'] ?? 'cash');
$orderStatusRaw = (string)($order['order_status'] ?? 'new');
$orderCreatedAtTs = strtotime((string)($order['created_at'] ?? ''));

$countdownSecondsRemaining = null;
$countdownExpired = false;
$countdownWarning = false;
$countdownMmss = null;

if ($orderCreatedAtTs && $orderPaymentStatus === 'unpaid' && in_array($orderPaymentType, $offlinePayTypes, true)) {
    $elapsedSeconds = max(0, time() - $orderCreatedAtTs);
    $remaining = (10 * 60) - $elapsedSeconds;
    $countdownSecondsRemaining = max(0, (int)$remaining);
    $countdownExpired = $remaining <= 0;
    $countdownWarning = !$countdownExpired && $countdownSecondsRemaining <= (3 * 60);
    $countdownMmss = gmdate('i:s', $countdownSecondsRemaining);
}

$finalOrderStatuses = ['delivered', 'completed', 'cancelled', 'canceled'];
$isActiveOrderForGuest = !in_array($orderStatusRaw, $finalOrderStatuses, true);

// Soft limit / upgrade prompts (non-blocking; for display only)
$planLimitExceeded = false;
$planLimitWarning = false;
$restaurantIdForPlan = (int)($currentRestaurant['id'] ?? 0);
if ($restaurantIdForPlan > 0 && !is_demo_mode() && file_exists(__DIR__ . '/../app/subscription_plans.php')) {
    require_once __DIR__ . '/../app/subscription_plans.php';
    if (function_exists('check_limit') && function_exists('get_usage_percent')) {
        $planLimitExceeded = !check_limit($restaurantIdForPlan, 'orders');
        $usagePercent = get_usage_percent($restaurantIdForPlan, 'orders');
        $planLimitWarning = ($usagePercent >= 80 && $usagePercent < 100);
    }
}

if (!function_exists('e')) {
    function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Статус заказа #<?= (int)$orderId ?> — <?= e($currentRestaurant['name']) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-950 text-slate-50">
<div class="max-w-2xl mx-auto px-4 py-6 space-y-4">

    <div class="flex items-start justify-between gap-3">
        <div>
            <div class="text-xs text-slate-400">Ресторан</div>
            <div class="text-lg font-semibold"><?= e($currentRestaurant['name']) ?></div>
            <div class="text-xs text-slate-500 mt-0.5">
                Стол: <span class="text-slate-200 font-medium"><?= e($table['name']) ?></span>
            </div>
        </div>

        <a href="/qr.php?table_id=<?= (int)$tableId ?>"
           class="px-3 py-2 rounded-2xl bg-slate-900 border border-slate-800 text-xs text-slate-200 hover:border-emerald-500/60">
            ← Вернуться в меню
        </a>
    </div>

    <?php if ($planLimitExceeded): ?>
    <div class="rounded-2xl border border-amber-500/50 bg-amber-500/10 px-4 py-3 text-sm text-amber-200" role="alert">
        <p class="font-medium">Вы превысили лимит заказов на текущем тарифе</p>
        <p class="text-xs text-amber-200/80 mt-1">Заказы по-прежнему принимаются. Рекомендуем перейти на тариф GROWTH для больших лимитов.</p>
        <a href="/owner/billing.php" class="inline-flex items-center mt-3 px-4 py-2 rounded-xl bg-amber-600 hover:bg-amber-500 text-white text-sm font-medium">Перейти на тариф GROWTH</a>
    </div>
    <?php elseif ($planLimitWarning): ?>
    <div class="rounded-2xl border border-sky-500/40 bg-sky-500/10 px-4 py-3 text-sm text-sky-200" role="status">
        <p class="font-medium">Вы приближаетесь к лимиту заказов</p>
        <p class="text-xs text-sky-200/80 mt-1">Рекомендуем перейти на тариф GROWTH, чтобы не ограничивать приём заказов.</p>
        <a href="/owner/billing.php" class="inline-flex items-center mt-2 px-3 py-1.5 rounded-xl bg-sky-600/80 hover:bg-sky-500 text-white text-xs font-medium">Перейти на тариф GROWTH</a>
    </div>
    <?php endif; ?>

    <div class="rounded-3xl bg-slate-900/80 border border-slate-800 p-4">
        <div class="flex items-center justify-between gap-2">
            <div>
                <div class="text-xs text-slate-400">Ваш заказ</div>
                <div class="text-sm font-semibold">#<?= (int)$orderId ?></div>
            </div>
            <div class="text-xs text-slate-500">обновляется автоматически</div>
        </div>

        <div class="mt-3">
            <div class="text-sm font-semibold">
                Статус: <span id="st_label" class="text-emerald-300"><?= e($label) ?></span>
            </div>
            <div id="st_desc" class="text-xs text-slate-400 mt-1"><?= e($desc) ?></div>

            <div class="mt-3 w-full h-2 rounded-full bg-slate-800 overflow-hidden">
                <div id="st_bar"
                     class="h-full bg-emerald-500 transition-all duration-700"
                     style="width: <?= (int)$progress ?>%"></div>
            </div>

            <div id="order-progress-items" class="mt-2 hidden text-xs text-slate-400">
                Готовность по позициям: <span id="order-progress-items-value" class="text-slate-200 font-semibold">0/0</span>
                <span id="order-progress-items-note" class="ml-2 text-indigo-300 hidden">Частично готово</span>
            </div>
        </div>

        <div class="mt-3 text-xs text-slate-500">
            Сумма: <span class="text-slate-200 font-semibold"><?= number_format((float)$order['total_price'], 0, '.', ' ') ?> ₽</span>
            <span class="text-slate-600">·</span>
            Оплата: <span class="text-slate-200"><?= e($order['payment_type'] ?: 'cash') ?></span>
            <span class="text-slate-600">·</span>
            Статус оплаты: <span class="text-slate-200"><?= e($order['payment_status'] ?: 'unpaid') ?></span>
        </div>

        <?php if ($countdownSecondsRemaining !== null): ?>
            <?php if ($countdownExpired): ?>
                <div id="payment-timer-block" class="mt-3 rounded-2xl border border-red-500/40 bg-red-500/10 px-3 py-2">
                    <div class="text-sm font-semibold text-red-200">Время ожидания оплаты истекло</div>
                    <div class="text-[11px] text-red-200/80 mt-1">
                        Осталось: <span id="payment-timer-mmss"><?= e($countdownMmss ?: '00:00') ?></span>
                    </div>
                </div>
            <?php else: ?>
                <?php
                $timerIsWarning = $countdownWarning;
                $timerBorder = $timerIsWarning ? 'border-amber-500/40 bg-amber-500/10' : 'border-emerald-500/30 bg-emerald-500/10';
                $timerText = $timerIsWarning ? 'text-amber-200' : 'text-emerald-200';
                ?>
                <div id="payment-timer-block" class="mt-3 rounded-2xl border <?= e($timerBorder) ?> px-3 py-2">
                    <div class="text-sm font-semibold <?= e($timerText) ?>">Официант должен подойти в течение 10 минут</div>
                    <div class="text-[11px] <?= e($timerText) ?>/80 mt-1">
                        Осталось: <span id="payment-timer-mmss"><?= e($countdownMmss ?: '10:00') ?></span>
                    </div>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div id="payment-timer-block" class="mt-3 hidden"></div>
        <?php endif; ?>

        <?php if ($isActiveOrderForGuest): ?>
            <div id="waiter-call-block" class="mt-4 rounded-2xl border border-slate-800 bg-slate-900/50 p-3">
                <button id="btn-waiter-call"
                        type="button"
                        class="w-full min-h-[48px] px-4 py-3 rounded-xl bg-gradient-to-r from-emerald-500 to-teal-500 hover:from-emerald-400 hover:to-teal-400 text-[#0B0F19] text-sm font-bold transition touch-manipulation">
                    Позвать официанта
                </button>
                <div id="waiter-call-message" class="mt-2 hidden text-sm text-emerald-200 font-semibold"></div>
            </div>
        <?php else: ?>
            <div id="waiter-call-block" class="mt-4 hidden"></div>
            <div id="waiter-call-message" class="hidden"></div>
        <?php endif; ?>
    </div>

    <div class="rounded-3xl bg-slate-900/80 border border-slate-800 p-4">
        <div class="text-sm font-semibold mb-2">Состав заказа</div>
        <?php if (!$orderItems): ?>
            <div class="text-xs text-slate-500">Позиции не найдены.</div>
        <?php else: ?>
            <div class="space-y-2">
                <?php foreach ($orderItems as $oi): ?>
                    <?php
                    $qty = (int)$oi['quantity'];
                    $price = (float)$oi['price'];
                    $sum = $qty * $price;
                    ?>
                    <div class="flex items-center justify-between gap-3 bg-slate-950/60 border border-slate-800 rounded-2xl px-3 py-2">
                        <div class="min-w-0">
                            <div class="text-sm truncate"><?= e($oi['name']) ?></div>
                            <div class="text-[11px] text-slate-500"><?= number_format($price, 0, '.', ' ') ?> ₽ × <?= $qty ?></div>
                        </div>
                        <div class="text-sm font-semibold text-slate-200 whitespace-nowrap">
                            <?= number_format($sum, 0, '.', ' ') ?> ₽
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div id="order-track-upsell" class="rounded-3xl bg-slate-900/80 border border-slate-800 p-4 hidden">
        <div class="text-sm font-semibold mb-2">Добавить к заказу?</div>
        <div class="text-[11px] text-slate-500 mb-2">Часто берут вместе — можно добавить в меню.</div>
        <div id="order-track-upsell-items" class="flex flex-wrap gap-2"></div>
    </div>

    <?php
    $showFeedbackForm = (($order['order_status'] ?? '') === 'delivered' && !$hasOrderFeedback && $feedbackTrackTrusted);
    $feedbackToken = '';
    if ($showFeedbackForm) {
        $feedbackToken = order_feedback_mint_token((int)$orderId);
    }
    $feedbackPendingPoll = ($feedbackTrackTrusted && !$hasOrderFeedback && ($order['order_status'] ?? '') !== 'delivered');
    ?>
    <?php if ($feedbackTrackTrusted && !$hasOrderFeedback): ?>
    <div id="order-track-feedback"
         class="rounded-3xl bg-slate-900/80 border border-slate-800 p-4 <?= $showFeedbackForm ? '' : 'hidden' ?>"
         data-pending-poll="<?= $feedbackPendingPoll ? '1' : '0' ?>">
        <div class="text-sm font-semibold mb-2">Оцените ваш заказ</div>
        <p id="feedback-loading" class="hidden text-xs text-slate-400 mb-2">Подготавливаем форму отзыва...</p>
        <form id="feedback-form" class="space-y-3 <?= $showFeedbackForm ? '' : 'hidden' ?>">
            <input type="hidden" name="order_id" value="<?= (int)$orderId ?>">
            <input type="hidden" name="table_id" value="<?= (int)$tableId ?>">
            <input type="hidden" name="feedback_token" id="feedback-token" value="<?= e($feedbackToken) ?>">
            <div>
                <label class="block text-xs text-slate-400 mb-1">Оценка</label>
                <div class="flex gap-2" role="group" aria-label="Оценка от 1 до 5">
                    <?php for ($i = 1; $i <= 5; $i++): ?>
                        <button type="button" class="feedback-star w-10 h-10 rounded-xl border border-slate-600 bg-slate-800/80 text-slate-400 hover:border-amber-500/60 hover:text-amber-400 transition-colors" data-rating="<?= $i ?>" aria-pressed="false" title="<?= $i ?>">⭐</button>
                    <?php endfor; ?>
                </div>
                <input type="hidden" name="rating" id="feedback-rating" value="">
            </div>
            <div>
                <label class="block text-xs text-slate-400 mb-1">Комментарий (необязательно)</label>
                <textarea name="comment" id="feedback-comment" rows="2" maxlength="2000" placeholder="Напишите отзыв..."
                    class="w-full rounded-xl bg-slate-950/70 border border-slate-700 px-3 py-2 text-sm text-slate-100 placeholder-slate-500"></textarea>
            </div>
            <button type="submit" id="feedback-submit" disabled class="px-4 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white text-sm font-medium disabled:opacity-50">
                Отправить
            </button>
        </form>
        <p id="feedback-thanks" class="hidden mt-2 text-sm text-emerald-300">Спасибо за отзыв!</p>
        <p id="feedback-error" class="hidden mt-2 text-sm text-red-300">Не удалось сохранить отзыв. Попробуйте позже.</p>
        <p id="feedback-handshake-error" class="hidden mt-2 text-sm text-red-300">Не удалось подготовить форму отзыва. Обновите страницу.</p>
    </div>
    <?php endif; ?>
    <div id="yandex-review-prompt" class="rounded-3xl bg-slate-900/80 border border-slate-800 p-4 <?= $showYandexReviewPrompt ? '' : 'hidden' ?>">
        <div class="text-sm font-semibold text-slate-100 mb-1">Спасибо за высокую оценку!</div>
        <p class="text-xs text-slate-400 mb-3">
            Если вам всё понравилось, нам будет очень приятно, если вы оставите отзыв в Яндекс.Картах.
        </p>
        <a id="yandex-review-link" href="<?= e($yandexReviewUrl) ?>" target="_blank" rel="noopener"
           class="inline-flex items-center px-4 py-2 rounded-xl bg-amber-500 hover:bg-amber-400 text-slate-950 text-sm font-semibold">
            Оставить отзыв в Яндексе
        </a>
        <p class="text-[11px] text-slate-500 mt-2">Это поможет другим гостям найти нас.</p>
    </div>

</div>

<script>
(function(){
    const tableId = <?= (int)$tableId ?>;
    const orderId = <?= (int)$orderId ?>;
    const BASE_INTERVAL = 10000;
    const BACKOFFS = [15000, 30000, 60000];
    const FINAL_STATUSES = ['delivered', 'completed', 'cancelled', 'canceled'];
    const yandexReviewUrl = <?= json_encode((!is_demo_mode() ? $yandexReviewUrl : ''), JSON_UNESCAPED_UNICODE) ?>;

    const elLabel = document.getElementById('st_label');
    const elDesc  = document.getElementById('st_desc');
    const elBar   = document.getElementById('st_bar');
    const orderProgressItems = document.getElementById('order-progress-items');
    const orderProgressItemsValue = document.getElementById('order-progress-items-value');
    const orderProgressItemsNote = document.getElementById('order-progress-items-note');

    const restaurantId = <?= (int)($currentRestaurant['id'] ?? 0) ?>;
    const paymentTimerBlock = document.getElementById('payment-timer-block');
    const waiterCallBlock = document.getElementById('waiter-call-block');
    const waiterCallBtn = document.getElementById('btn-waiter-call');
    const waiterCallMessage = document.getElementById('waiter-call-message');
    const initialPaymentTimerSeconds = <?= $countdownSecondsRemaining !== null ? (int)$countdownSecondsRemaining : 'null' ?>;
    let paymentTimerSeconds = initialPaymentTimerSeconds;
    let paymentTimerTick = null;

    function formatMMSS(sec) {
        sec = Math.max(0, Number(sec || 0));
        const mm = String(Math.floor(sec / 60)).padStart(2, '0');
        const ss = String(sec % 60).padStart(2, '0');
        return mm + ':' + ss;
    }

    function applyPaymentTimerState(seconds) {
        if (!paymentTimerBlock) return;
        if (seconds === null || typeof seconds === 'undefined') {
            paymentTimerBlock.classList.add('hidden');
            return;
        }
        paymentTimerBlock.classList.remove('hidden');
        const s = Math.max(0, Number(seconds || 0));
        const expired = s <= 0;
        const warning = !expired && s <= (3 * 60);

        const state = expired ? 'expired' : (warning ? 'warning' : 'ok');
        if (paymentTimerBlock.dataset.state === state && document.getElementById('payment-timer-mmss')) {
            // Только обновим цифры при стабильном состоянии.
            const mmss = document.getElementById('payment-timer-mmss');
            if (mmss) mmss.textContent = formatMMSS(s);
            return;
        }

        paymentTimerBlock.dataset.state = state;
        if (expired) {
            paymentTimerBlock.className = 'mt-3 rounded-2xl border border-red-500/40 bg-red-500/10 px-3 py-2';
            paymentTimerBlock.innerHTML = `
                <div class="text-sm font-semibold text-red-200">Время ожидания оплаты истекло</div>
                <div class="text-[11px] text-red-200/80 mt-1">Осталось: <span id="payment-timer-mmss">${formatMMSS(s)}</span></div>
            `;
        } else {
            const border = warning ? 'border-amber-500/40 bg-amber-500/10' : 'border-emerald-500/30 bg-emerald-500/10';
            const text = warning ? 'text-amber-200' : 'text-emerald-200';
            paymentTimerBlock.className = 'mt-3 rounded-2xl border ' + border + ' px-3 py-2';
            paymentTimerBlock.innerHTML = `
                <div class="text-sm font-semibold ${text}">Официант должен подойти в течение 10 минут</div>
                <div class="text-[11px] ${text}/80 mt-1">Осталось: <span id="payment-timer-mmss">${formatMMSS(s)}</span></div>
            `;
        }
    }

    function startPaymentTimerTick() {
        if (paymentTimerTick) clearInterval(paymentTimerTick);
        if (paymentTimerSeconds === null || typeof paymentTimerSeconds === 'undefined') return;
        paymentTimerTick = setInterval(function () {
            if (paymentTimerSeconds === null || typeof paymentTimerSeconds === 'undefined') return;
            paymentTimerSeconds = Math.max(0, paymentTimerSeconds - 1);
            applyPaymentTimerState(paymentTimerSeconds);
        }, 1000);
    }

    async function syncPaymentTimer() {
        if (!paymentTimerBlock) return;
        try {
            const r = await fetch('/guest/order_timer_status.php?table_id=' + tableId + '&order_id=' + orderId, {
                method: 'GET',
                cache: 'no-store',
                headers: { 'Accept': 'application/json' }
            });
            const data = await r.json();
            if (!data || !data.success) return;

            if (!data.countdown_active) {
                paymentTimerSeconds = null;
                applyPaymentTimerState(null);
                if (paymentTimerTick) clearInterval(paymentTimerTick);
                paymentTimerTick = null;
                return;
            }

            paymentTimerSeconds = typeof data.countdown_seconds_remaining !== 'undefined'
                ? Number(data.countdown_seconds_remaining || 0)
                : 0;
            applyPaymentTimerState(paymentTimerSeconds);
        } catch (e) {}
    }

    if (paymentTimerSeconds !== null && typeof paymentTimerSeconds !== 'undefined') {
        applyPaymentTimerState(paymentTimerSeconds);
        startPaymentTimerTick();
    }

    // Кнопка "Позвать официанта"
    if (waiterCallBtn) {
        const cooldownKey = 'waiter_call_last_' + restaurantId + '_' + tableId + '_' + orderId;
        const COOLDOWN_MS = 60 * 1000;
        waiterCallBtn.addEventListener('click', async function () {
            const now = Date.now();
            const last = parseInt(localStorage.getItem(cooldownKey) || '0', 10);
            if (now - last < COOLDOWN_MS) {
                if (waiterCallMessage) {
                    waiterCallMessage.textContent = 'Запрос уже отправлен. Ожидайте.';
                    waiterCallMessage.classList.remove('hidden');
                }
                return;
            }

            waiterCallBtn.disabled = true;
            waiterCallBtn.classList.add('opacity-70', 'cursor-not-allowed');
            localStorage.setItem(cooldownKey, String(now));

            try {
                const form = new FormData();
                form.append('table_id', tableId);
                form.append('order_id', orderId);

                const r = await fetch('/guest/waiter_call.php', {
                    method: 'POST',
                    body: form,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });
                const data = await r.json();
                if (waiterCallMessage) {
                    waiterCallMessage.classList.remove('hidden');
                    waiterCallMessage.textContent = (data && data.success)
                        ? 'Официант уведомлён'
                        : ((data && data.message) ? data.message : 'Не удалось вызвать официанта');
                }
            } catch (e) {
                if (waiterCallMessage) {
                    waiterCallMessage.classList.remove('hidden');
                    waiterCallMessage.textContent = 'Ошибка сети при вызове официанта';
                }
            } finally {
                setTimeout(function () {
                    waiterCallBtn.disabled = false;
                    waiterCallBtn.classList.remove('opacity-70', 'cursor-not-allowed');
                }, COOLDOWN_MS);
            }
        });
    }

    let timer = null;
    let inFlight = false;
    let backoffIndex = -1;
    let nextDelay = BASE_INTERVAL;

    var FEEDBACK_STATE_MS = 2500;
    var FEEDBACK_STATE_MAX = 24;
    var feedbackHandshakeDone = false;
    var feedbackHandshakeStarted = false;
    var feedbackStateInFlight = false;
    var feedbackHandshakeAttempts = 0;
    var feedbackStateTimer = null;

    function hideFeedbackLoading() {
        var ld = document.getElementById('feedback-loading');
        if (ld) ld.classList.add('hidden');
    }
    function showFeedbackLoading() {
        var ld = document.getElementById('feedback-loading');
        if (ld) ld.classList.remove('hidden');
    }
    function markFeedbackHandshakeResolved() {
        feedbackHandshakeDone = true;
        if (feedbackStateTimer) clearTimeout(feedbackStateTimer);
        feedbackStateTimer = null;
        hideFeedbackLoading();
    }
    function hideFeedbackBlockQuietly() {
        var fb = document.getElementById('order-track-feedback');
        var he = document.getElementById('feedback-handshake-error');
        hideFeedbackLoading();
        if (fb) fb.classList.add('hidden');
        if (he) he.classList.add('hidden');
    }
    function failFeedbackHandshake() {
        markFeedbackHandshakeResolved();
        var he = document.getElementById('feedback-handshake-error');
        var fb = document.getElementById('order-track-feedback');
        hideFeedbackLoading();
        if (fb) {
            var fm = document.getElementById('feedback-form');
            if (fm) fm.classList.add('hidden');
            fb.classList.remove('hidden');
        }
        if (he) he.classList.remove('hidden');
    }
    function scheduleFeedbackStateRetry() {
        if (feedbackHandshakeDone) return;
        if (feedbackHandshakeAttempts >= FEEDBACK_STATE_MAX) {
            failFeedbackHandshake();
            return;
        }
        if (feedbackStateTimer) clearTimeout(feedbackStateTimer);
        feedbackStateTimer = setTimeout(function() {
            feedbackStateTimer = null;
            runFeedbackStateAttempt();
        }, FEEDBACK_STATE_MS);
    }
    function runFeedbackStateAttempt() {
        if (feedbackHandshakeDone) return;
        var fb = document.getElementById('order-track-feedback');
        if (!fb || fb.dataset.submitted === '1') {
            markFeedbackHandshakeResolved();
            return;
        }
        var tok = document.getElementById('feedback-token');
        if (tok && tok.value) {
            var fm = document.getElementById('feedback-form');
            if (fm) fm.classList.remove('hidden');
            fb.classList.remove('hidden');
            markFeedbackHandshakeResolved();
            return;
        }
        if (feedbackStateInFlight) return;
        if (feedbackHandshakeAttempts >= FEEDBACK_STATE_MAX) {
            failFeedbackHandshake();
            return;
        }
        feedbackHandshakeAttempts++;
        feedbackStateInFlight = true;
        showFeedbackLoading();
        fb.classList.remove('hidden');
        var formEl = document.getElementById('feedback-form');
        if (formEl) formEl.classList.add('hidden');
        var he = document.getElementById('feedback-handshake-error');
        if (he) he.classList.add('hidden');

        fetch('/ajax/order_feedback_state.php?table_id=' + tableId + '&order_id=' + orderId, {
            method: 'GET',
            cache: 'no-store',
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        })
        .then(function(r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        })
        .then(function(st) {
            feedbackStateInFlight = false;
            if (feedbackHandshakeDone) return;
            if (!st) {
                scheduleFeedbackStateRetry();
                return;
            }
            if (st.success === false) {
                if (st.stop_feedback_retry) {
                    hideFeedbackBlockQuietly();
                    markFeedbackHandshakeResolved();
                    return;
                }
                scheduleFeedbackStateRetry();
                return;
            }
            if (st.feedback_exists) {
                fb.dataset.submitted = '1';
                var th = document.getElementById('feedback-thanks');
                var fm = document.getElementById('feedback-form');
                if (fm) fm.classList.add('hidden');
                if (th) { th.textContent = 'Спасибо за отзыв!'; th.classList.remove('hidden'); }
                fb.classList.remove('hidden');
                markFeedbackHandshakeResolved();
                return;
            }
            if (st.eligible && st.feedback_token && tok) {
                tok.value = st.feedback_token;
                if (formEl) formEl.classList.remove('hidden');
                hideFeedbackLoading();
                fb.classList.remove('hidden');
                markFeedbackHandshakeResolved();
                return;
            }
            if (st.stop_feedback_retry) {
                hideFeedbackBlockQuietly();
                markFeedbackHandshakeResolved();
                return;
            }
            scheduleFeedbackStateRetry();
        })
        .catch(function() {
            feedbackStateInFlight = false;
            if (feedbackHandshakeDone) return;
            scheduleFeedbackStateRetry();
        });
    }
    function startFeedbackHandshake() {
        if (feedbackHandshakeDone) return;
        var fb = document.getElementById('order-track-feedback');
        if (!fb || fb.dataset.submitted === '1') {
            markFeedbackHandshakeResolved();
            return;
        }
        var tok = document.getElementById('feedback-token');
        if (tok && tok.value) {
            var fm = document.getElementById('feedback-form');
            if (fm) fm.classList.remove('hidden');
            fb.classList.remove('hidden');
            markFeedbackHandshakeResolved();
            return;
        }
        if (feedbackHandshakeStarted) return;
        feedbackHandshakeStarted = true;
        feedbackHandshakeAttempts = 0;
        runFeedbackStateAttempt();
    }

    function isFinal(status) {
        if (!status) return false;
        const s = String(status).toLowerCase();
        return FINAL_STATUSES.some(function(f){ return f === s; });
    }

    function scheduleNext() {
        if (timer) clearTimeout(timer);
        timer = setTimeout(refresh, nextDelay);
    }

    function refresh() {
        if (inFlight) return;
        inFlight = true;
        fetch('/ajax/order_status.php?table_id=' + tableId + '&order_id=' + orderId, {
            method: 'GET',
            cache: 'no-store',
            headers: { 'Accept': 'application/json' }
        })
        .then(function(r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        })
        .then(function(data) {
            inFlight = false;
            if (!data || !data.success) {
                backoffIndex = Math.min(backoffIndex + 1, BACKOFFS.length - 1);
                nextDelay = BACKOFFS[backoffIndex];
                scheduleNext();
                return;
            }
            backoffIndex = -1;
            nextDelay = BASE_INTERVAL;

            if (elLabel) elLabel.textContent = data.order_status_label || '';
            if (elDesc)  elDesc.textContent  = data.order_status_description || '';

            if (elBar && typeof data.progress !== 'undefined') {
                var p = Number(data.progress) || 0;
                if (p < 0) p = 0;
                if (p > 100) p = 100;
                elBar.style.width = p + '%';
            }

            if (orderProgressItems && orderProgressItemsValue) {
                var totalItemsCount = Number(data.total_items_count || 0);
                var readyItemsCount = Number(data.ready_items_count || 0);
                var partialReady = !!data.partial_ready;
                if (totalItemsCount > 0) {
                    orderProgressItems.classList.remove('hidden');
                    orderProgressItemsValue.textContent = readyItemsCount + '/' + totalItemsCount;
                    if (orderProgressItemsNote) {
                        orderProgressItemsNote.classList.toggle('hidden', !partialReady);
                    }
                } else {
                    orderProgressItems.classList.add('hidden');
                    if (orderProgressItemsNote) orderProgressItemsNote.classList.add('hidden');
                }
            }

            // Синхронизируем 10-минутный таймер ожидания оплаты (офлайн и unpaid)
            syncPaymentTimer().catch(function () {});

            if (data.order_status === 'delivered') {
                var fb = document.getElementById('order-track-feedback');
                if (fb && !fb.dataset.submitted) {
                    var tok = document.getElementById('feedback-token');
                    if (tok && tok.value) {
                        fb.classList.remove('hidden');
                    } else {
                        startFeedbackHandshake();
                    }
                }
            }
            if (isFinal(data.order_status)) {
                if (timer) clearTimeout(timer);
                timer = null;
                return;
            }
            scheduleNext();
        })
        .catch(function() {
            inFlight = false;
            backoffIndex = Math.min(backoffIndex + 1, BACKOFFS.length - 1);
            nextDelay = BACKOFFS[backoffIndex];
            scheduleNext();
        });
    }

    refresh();

    // Offers by order context (order_id passed to qr_offers)
    (function(){
        var container = document.getElementById('order-track-upsell');
        var itemsEl = document.getElementById('order-track-upsell-items');
        if (!container || !itemsEl) return;
        fetch('/qr_offers.php?table_id=' + tableId + '&order_id=' + orderId, { cache: 'no-store' })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data || !data.success || !Array.isArray(data.items) || data.items.length === 0) return;
                var qrUrl = '/qr.php?table_id=' + tableId;
                itemsEl.innerHTML = data.items.slice(0, 3).map(function(it) {
                    var name = (it.name || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
                    var price = (Number(it.price || 0)).toLocaleString('ru-RU', { maximumFractionDigits: 0 });
                    return '<a href="' + qrUrl + '" class="inline-flex items-center gap-1.5 rounded-xl bg-slate-950/80 border border-slate-700 px-3 py-2 text-xs text-slate-200 hover:border-emerald-500/60">' +
                        '<span>' + name + ' — ' + price + ' ₽</span>' +
                        '<span class="text-emerald-400">+</span></a>';
                }).join('');
                container.classList.remove('hidden');
            })
            .catch(function() {});
    })();

    // Feedback: stars + submit
    (function(){
        var form = document.getElementById('feedback-form');
        var block = document.getElementById('order-track-feedback');
        var yandexPrompt = document.getElementById('yandex-review-prompt');
        var yandexLink = document.getElementById('yandex-review-link');
        var ratingInput = document.getElementById('feedback-rating');
        var submitBtn = document.getElementById('feedback-submit');
        var thanksEl = document.getElementById('feedback-thanks');
        var errorEl = document.getElementById('feedback-error');
        if (!form || !block) return;

        var stars = block.querySelectorAll('.feedback-star');
        var selectedRating = 0;
        stars.forEach(function(btn) {
            btn.addEventListener('click', function() {
                var r = parseInt(btn.getAttribute('data-rating'), 10);
                selectedRating = r;
                if (ratingInput) ratingInput.value = r;
                if (submitBtn) submitBtn.disabled = false;
                stars.forEach(function(s) {
                    var sr = parseInt(s.getAttribute('data-rating'), 10);
                    s.setAttribute('aria-pressed', sr <= r ? 'true' : 'false');
                    s.classList.toggle('border-amber-500', sr <= r);
                    s.classList.toggle('text-amber-400', sr <= r);
                    s.classList.toggle('bg-amber-500/20', sr <= r);
                });
            });
        });

        form.addEventListener('submit', function(e) {
            e.preventDefault();
            if (errorEl) errorEl.classList.add('hidden');
            if (!selectedRating || selectedRating < 1 || selectedRating > 5) {
                return;
            }
            var tokEl = document.getElementById('feedback-token');
            if (!tokEl || !tokEl.value) {
                if (errorEl) {
                    errorEl.textContent = 'Обновите страницу, чтобы отправить отзыв.';
                    errorEl.classList.remove('hidden');
                }
                return;
            }
            if (submitBtn) submitBtn.disabled = true;
            var fd = new FormData(form);
            fd.append('rating', String(selectedRating));
            fetch('/ajax/order_feedback.php', { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data && data.success) {
                        block.dataset.submitted = '1';
                        if (thanksEl) thanksEl.classList.remove('hidden');
                        form.classList.add('hidden');
                        if (selectedRating >= 5 && yandexPrompt && yandexReviewUrl) {
                            if (yandexLink) yandexLink.href = yandexReviewUrl;
                            yandexPrompt.classList.remove('hidden');
                        }
                        return;
                    }
                    if (submitBtn) submitBtn.disabled = false;
                    if (errorEl) {
                        errorEl.textContent = (data && data.message) ? data.message : 'Не удалось сохранить отзыв. Попробуйте позже.';
                        errorEl.classList.remove('hidden');
                    }
                })
                .catch(function() {
                    if (submitBtn) submitBtn.disabled = false;
                    if (errorEl) {
                        errorEl.textContent = 'Не удалось сохранить отзыв. Попробуйте позже.';
                        errorEl.classList.remove('hidden');
                    }
                });
        });
    })();
})();
</script>
</body>
</html>
