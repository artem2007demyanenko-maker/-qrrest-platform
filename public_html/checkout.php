<?php
require_once __DIR__ . '/../app/bootstrap.php';

if (!$currentRestaurant) {
    http_response_code(404);
    echo "Restaurant context required";
    exit;
}

$pdo = db();
$errors = [];
$order = null;
$orderItems = [];

// Настройки оплат ресторана
$stmt = $pdo->prepare("
    SELECT * FROM restaurant_payment_settings
    WHERE restaurant_id = :rest
    LIMIT 1
");
$stmt->execute(['rest' => $currentRestaurant['id']]);
$paySettings = $stmt->fetch() ?: [
    'allow_card_now'   => 1,
    'allow_card_later' => 1,
    'allow_cash'       => 1,
];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo "Method not allowed";
    exit;
}

$tableId = (int)($_POST['table_id'] ?? 0);
$itemsJson = $_POST['items'] ?? '';

if ($tableId <= 0 || $itemsJson === '') {
    $errors[] = 'Некорректные данные заказа.';
} else {
    // Проверка стола
    $stmt = $pdo->prepare("
        SELECT * FROM tables
        WHERE id = :id AND restaurant_id = :rest
    ");
    $stmt->execute([
        'id'   => $tableId,
        'rest' => $currentRestaurant['id'],
    ]);
    $table = $stmt->fetch();
    if (!$table) {
        $errors[] = 'Стол не найден.';
    }
}

$cart = [];

if (empty($errors)) {
    $decoded = json_decode($itemsJson, true);
    if (!is_array($decoded)) {
        $errors[] = 'Некорректный формат корзины.';
    } else {
        foreach ($decoded as $item) {
            if (empty($item['id']) || empty($item['qty'])) continue;
            $id = (int)$item['id'];
            $qty = (int)$item['qty'];
            if ($id <= 0 || $qty <= 0) continue;
            $cart[$id] = ($cart[$id] ?? 0) + $qty;
        }
        if (!$cart) {
            $errors[] = 'Корзина пуста.';
        }
    }
}

if (empty($errors)) {
    // Загружаем блюда по ID и пересчитываем сумму
    $ids = implode(',', array_map('intval', array_keys($cart)));
    $stmt = $pdo->prepare("
        SELECT id, name, price
        FROM menu_items
        WHERE restaurant_id = :rest
          AND id IN ($ids)
          AND available = 1
    ");
    $stmt->execute(['rest' => $currentRestaurant['id']]);
    $items = $stmt->fetchAll();

    if (!$items) {
        $errors[] = 'Выбранные блюда недоступны.';
    } else {
        $total = 0;
        foreach ($items as $row) {
            $id = (int)$row['id'];
            if (!isset($cart[$id])) continue;
            $qty = $cart[$id];
            $price = (float)$row['price'];
            $total += $qty * $price;
            $orderItems[] = [
                'menu_item_id' => $id,
                'name'         => $row['name'],
                'price'        => $price,
                'qty'          => $qty,
            ];
        }

        if ($total <= 0) {
            $errors[] = 'Сумма заказа должна быть больше нуля.';
        } else {

            try {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare("
                   INSERT INTO orders (
  restaurant_id, table_id, total_price, total_amount, ...
) VALUES (
  :rest, :table_id, :total_price, :total_amount, ...
)
                ");
                $stmt->execute([
                    'rest'        => $currentRestaurant['id'],
                    'table_id'    => $tableId,
                    'total_price' => $total,
                    'total_amount' => $total,
                ]);
                $orderId = (int)$pdo->lastInsertId();

                $stmtItem = $pdo->prepare("
                    INSERT INTO order_items (order_id, menu_item_id, quantity, price)
                    VALUES (:order_id, :menu_item_id, :quantity, :price)
                ");

                foreach ($orderItems as $oi) {
                    $stmtItem->execute([
                        'order_id'     => $orderId,
                        'menu_item_id' => $oi['menu_item_id'],
                        'quantity'     => $oi['qty'],
                        'price'        => $oi['price'],
                    ]);
                }

                log_action(null, $currentRestaurant['id'], 'create_order_from_qr',
                    "Создан заказ #{$orderId} со стола {$tableId}");

                $pdo->commit();

                // Event logging only: must never affect checkout flow.
                if (function_exists('app_event')) {
                    app_event('order_created', [
                        'restaurant_id' => (int)$currentRestaurant['id'],
                        'order_id' => (int)$orderId,
                        'table_id' => (int)$tableId,
                        'total' => (float)$total,
                    ]);
                }

                // Загружаем заказ обратно
                $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = :id");
                $stmt->execute(['id' => $orderId]);
                $order = $stmt->fetch();

            } catch (Throwable $e) {
                $pdo->rollBack();
                error_log('CHECKOUT_CREATE_ORDER_ERROR rest_id=' . (int)$currentRestaurant['id'] . ' table_id=' . $tableId . ' ' . $e->getMessage());
                $errors[] = 'Не удалось создать заказ. Попробуйте позже.';
            }
        }
    }
}

?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Оформление заказа — <?= e($currentRestaurant['name']) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-950 text-slate-50">
<div class="max-w-xl mx-auto px-3 py-4">
    <header class="mb-4">
        <div class="text-xs text-slate-400 uppercase tracking-wide">Ресторан</div>
        <div class="text-xl font-semibold"><?= e($currentRestaurant['name']) ?></div>
        <?php if (!empty($table)): ?>
            <div class="text-xs text-slate-500 mt-1">
                Стол: <?= e($table['name']) ?>
            </div>
        <?php endif; ?>
    </header>

    <?php if ($errors): ?>
        <div class="mb-4 rounded-2xl bg-red-500/10 border border-red-500/50 px-4 py-3 text-sm text-red-100 space-y-1">
            <?php foreach ($errors as $err): ?>
                <div><?= e($err) ?></div>
            <?php endforeach; ?>
        </div>
        <div class="mt-4">
            <a href="javascript:history.back()"
               class="inline-flex items-center justify-center px-4 py-2.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-sm text-slate-100">
                Вернуться к меню
            </a>
        </div>
    <?php elseif ($order): ?>
        <div class="mb-4 bg-slate-900/80 border border-slate-800 rounded-3xl p-4">
            <div class="flex items-center justify-between mb-2">
                <div class="text-sm text-slate-300">
                    Заказ <span class="font-semibold">#<?= (int)$order['id'] ?></span>
                </div>
                <div class="text-sm font-semibold text-emerald-400">
                    <?= number_format((float)$order['total_price'], 0, '.', ' ') ?> ₽
                </div>
            </div>
            <div class="divide-y divide-slate-800 text-sm">
                <?php foreach ($orderItems as $oi): ?>
                    <div class="py-1 flex items-center justify-between">
                        <div>
                            <div class="text-slate-100"><?= e($oi['name']) ?></div>
                            <div class="text-xs text-slate-500">
                                <?= (int)$oi['qty'] ?> × <?= number_format($oi['price'], 0, '.', ' ') ?> ₽
                            </div>
                        </div>
                        <div class="text-slate-200 font-medium">
                            <?= number_format($oi['qty'] * $oi['price'], 0, '.', ' ') ?> ₽
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="mb-2 text-sm text-slate-300">
            Выберите способ оплаты:
        </div>

        <div class="grid gap-3">
            <?php if ($paySettings['allow_card_now']): ?>
                <form method="post" action="/payment/start.php">
                    <input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>">
                    <input type="hidden" name="payment_type" value="card_now">
                    <button type="submit"
                            class="w-full px-4 py-3 rounded-2xl bg-emerald-500 hover:bg-emerald-400 text-slate-950 text-sm font-semibold shadow-lg shadow-emerald-500/40 transition text-left">
                        Оплатить сейчас (онлайн)
                        <div class="text-xs text-emerald-950/80 mt-1">
                            Вы будете перенаправлены на безопасную страницу оплаты.
                        </div>
                    </button>
                </form>
            <?php endif; ?>

            <?php if ($paySettings['allow_card_later']): ?>
                <form method="post" action="/payment/start.php">
                    <input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>">
                    <input type="hidden" name="payment_type" value="card_later">
                    <button type="submit"
                            class="w-full px-4 py-3 rounded-2xl bg-slate-900 hover:bg-slate-800 border border-slate-700 text-slate-100 text-sm text-left">
                        Оплатить картой позже
                        <div class="text-xs text-slate-400 mt-1">
                            Ссылка на оплату будет доступна официанту или через QR-код.
                        </div>
                    </button>
                </form>
            <?php endif; ?>

            <?php if ($paySettings['allow_cash']): ?>
                <form method="post" action="/payment/start.php">
                    <input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>">
                    <input type="hidden" name="payment_type" value="cash">
                    <button type="submit"
                            class="w-full px-4 py-3 rounded-2xl bg-slate-900 hover:bg-slate-800 border border-slate-700 text-slate-100 text-sm text-left">
                        Оплатить наличными
                        <div class="text-xs text-slate-400 mt-1">
                            Оплата будет произведена при получении заказа.
                        </div>
                    </button>
                </form>
            <?php endif; ?>
        </div>

        <div class="mt-4 text-xs text-slate-500">
            После выбора способа оплаты ваш заказ поступит на обработку в ресторан.
        </div>
    <?php endif; ?>

</div>
</body>
</html>
