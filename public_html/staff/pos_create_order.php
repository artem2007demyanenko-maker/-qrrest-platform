<?php


require_once __DIR__ . '/../../app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

function json_error(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

require_login();

if (!$currentRestaurant) {
    json_error('Контекст ресторана не найден', 404);
}

require_restaurant_role((int)$currentRestaurant['id'], ['staff','admin','owner']);

$user = auth_user();
$pdo  = db();

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    json_error('Некорректные данные запроса');
}

$tableId     = isset($data['table_id']) ? (int)$data['table_id'] : 0;
$paymentType = isset($data['payment_type']) ? (string)$data['payment_type'] : 'cash';
$items       = isset($data['items']) && is_array($data['items']) ? $data['items'] : [];

if ($tableId <= 0) {
    json_error('Не выбран стол');
}


$allowedPaymentTypes = ['cash', 'card_later'];
if (!in_array($paymentType, $allowedPaymentTypes, true)) {
    json_error('Некорректный тип оплаты');
}
if (empty($items)) {
    json_error('Пустой заказ');
}


$stmt = $pdo->prepare("SELECT id, name FROM tables WHERE id = :id AND restaurant_id = :rid");
$stmt->execute([
    ':id'  => $tableId,
    ':rid' => (int)$currentRestaurant['id'],
]);
$tableRow = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$tableRow) {
    json_error('Стол не найден в этом ресторане');
}


$itemIds = [];
foreach ($items as $row) {
    $iid = isset($row['item_id']) ? (int)$row['item_id'] : 0;
    $qty = isset($row['quantity']) ? (int)$row['quantity'] : 0;
    if ($iid > 0 && $qty > 0) {
        $itemIds[$iid] = $qty;
    }
}
if (empty($itemIds)) {
    json_error('Нет валидных позиций в заказе');
}


$placeholders = implode(',', array_fill(0, count($itemIds), '?'));
$sql = "
    SELECT id, name, price
    FROM menu_items
    WHERE restaurant_id = ?
      AND available = 1
      AND id IN ($placeholders)
";
$params = [(int)$currentRestaurant['id']];
foreach (array_keys($itemIds) as $id) {
    $params[] = (int)$id;
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$dbItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (!$dbItems) {
    json_error('Все блюда в заказе недоступны');
}


$total = 0.0;
$orderItems = [];
foreach ($dbItems as $row) {
    $id  = (int)$row['id'];
    $qty = $itemIds[$id] ?? 0;
    if ($qty <= 0) continue;
    $price = (float)$row['price'];
    $total += $qty * $price;
    $orderItems[] = [
        'id'    => $id,
        'name'  => $row['name'],
        'qty'   => $qty,
        'price' => $price,
    ];
}
if (empty($orderItems)) {
    json_error('Не удалось сопоставить блюда заказа');
}


$paymentStatus = 'unpaid';

try {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $idemKey = hash('sha256', json_encode([
        'rid' => (int)$currentRestaurant['id'],
        'table_id' => $tableId,
        'payment_type' => $paymentType,
        'items' => $itemIds,
    ], JSON_UNESCAPED_UNICODE));
    if (!isset($_SESSION['pos_order_idem']) || !is_array($_SESSION['pos_order_idem'])) {
        $_SESSION['pos_order_idem'] = [];
    }
    $idem = $_SESSION['pos_order_idem'][$idemKey] ?? null;
    if (is_array($idem)) {
        $state = (string)($idem['state'] ?? '');
        $ts = (int)($idem['ts'] ?? 0);
        $existingOrderId = (int)($idem['order_id'] ?? 0);
        if ($state === 'done' && $existingOrderId > 0 && (time() - $ts) <= 300) {
            echo json_encode([
                'success'     => true,
                'order_id'    => $existingOrderId,
                'total_price' => $total,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($state === 'processing' && (time() - $ts) <= 30) {
            json_error('Заказ уже обрабатывается. Подождите несколько секунд.', 409);
        }
    }
    $_SESSION['pos_order_idem'][$idemKey] = ['state' => 'processing', 'order_id' => 0, 'ts' => time()];

    // System Consistency: stable flow_id for this checkout/order flow.
    if (file_exists(__DIR__ . '/../../app/flow_id.php')) {
        require_once __DIR__ . '/../../app/flow_id.php';
        $flowId = app_flow_id_ensure_current((int)$currentRestaurant['id']);
    } else {
        $flowId = null;
    }
    if (!function_exists('db_column_exists') && file_exists(__DIR__ . '/../../app/schema_guard.php')) {
        require_once __DIR__ . '/../../app/schema_guard.php';
    }
    $hasOrdersFlowIdCol = (function_exists('db_column_exists') && db_column_exists('orders', 'flow_id'));

    $pdo->beginTransaction();


    if ($hasOrdersFlowIdCol && $flowId !== null) {
        $stmt = $pdo->prepare("
            INSERT INTO orders
                (restaurant_id, table_id, total_price, total_amount,
                 payment_type, payment_status, order_status, created_at, flow_id)
            VALUES
                (:rid, :table_id, :total_price, :total_amount,
                 :payment_type, :payment_status, :order_status, NOW(), :flow_id)
        ");
        $stmt->execute([
            ':rid'            => (int)$currentRestaurant['id'],
            ':table_id'       => $tableId,
            ':total_price'    => $total,
            ':total_amount'   => $total,
            ':payment_type'   => $paymentType,
            ':payment_status' => $paymentStatus,
            ':order_status'   => 'new',
            ':flow_id'        => (string)$flowId,
        ]);
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO orders
                (restaurant_id, table_id, total_price, total_amount,
                 payment_type, payment_status, order_status, created_at)
            VALUES
                (:rid, :table_id, :total_price, :total_amount,
                 :payment_type, :payment_status, :order_status, NOW())
        ");
        $stmt->execute([
            ':rid'            => (int)$currentRestaurant['id'],
            ':table_id'       => $tableId,
            ':total_price'    => $total,
            ':total_amount'   => $total,
            ':payment_type'   => $paymentType,
            ':payment_status' => $paymentStatus,
            ':order_status'   => 'new',
        ]);
    }
    $orderId = (int)$pdo->lastInsertId();

    // Позиции заказа
    $stmtItem = $pdo->prepare("
        INSERT INTO order_items (order_id, menu_item_id, quantity, price)
        VALUES (:order_id, :menu_item_id, :quantity, :price)
    ");

    foreach ($orderItems as $oi) {
        $stmtItem->execute([
            ':order_id'    => $orderId,
            ':menu_item_id'=> $oi['id'],
            ':quantity'    => $oi['qty'],
            ':price'       => $oi['price'],
        ]);
    }

    $pdo->commit();
    $_SESSION['pos_order_idem'][$idemKey] = ['state' => 'done', 'order_id' => $orderId, 'ts' => time()];

    // Логирование
    if (function_exists('add_log')) {
        $lines = [];
        $lines[] = 'POS-заказ #' . $orderId . ' создан сотрудником #' . (int)$user['id'];
        $lines[] = 'Стол: ' . $tableRow['name'] . ' (ID ' . (int)$tableRow['id'] . ')';
        $lines[] = 'Оплата: ' . $paymentType . ' / статус оплаты: ' . $paymentStatus;
        $lines[] = 'Сумма: ' . round($total) . ' руб.';
        $lines[] = 'Позиций: ' . count($orderItems);

        add_log($pdo, [
            'user_id'       => (int)$user['id'],
            'restaurant_id' => (int)$currentRestaurant['id'],
            'level'         => 'info',
            'action'        => 'pos_create_order',
            'message'       => implode("\n", $lines),
        ]);

        // Event logging only: must never affect checkout flow.
        if (function_exists('app_event')) {
            app_event('order_created', [
                'restaurant_id' => (int)$currentRestaurant['id'],
                'order_id' => (int)$orderId,
                'table_id' => (int)$tableId,
                'total' => (float)$total,
                'payment_type' => (string)$paymentType,
                'payment_status' => (string)$paymentStatus,
            ]);
        }
    }

    echo json_encode([
        'success'     => true,
        'order_id'    => $orderId,
        'total_price' => $total,
    ], JSON_UNESCAPED_UNICODE);
    exit;

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if (isset($idemKey)) {
        $_SESSION['pos_order_idem'][$idemKey] = ['state' => 'failed', 'order_id' => 0, 'ts' => time()];
    }

    if (function_exists('add_log')) {
        add_log($pdo, [
            'user_id'       => (int)$user['id'],
            'restaurant_id' => (int)$currentRestaurant['id'],
            'level'         => 'error',
            'action'        => 'pos_create_order_failed',
            'message'       => 'Ошибка при создании POS-заказа: ' . $e->getMessage(),
        ]);
    }

    json_error('Ошибка при создании заказа. Попробуйте позже.', 500);
}
