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

require_waiter_access((int)$currentRestaurant['id']);

$user = auth_user();
$pdo  = db();
if (file_exists(__DIR__ . '/../../app/runtime_schema_bootstrap.php')) {
    require_once __DIR__ . '/../../app/runtime_schema_bootstrap.php';
    if (function_exists('runtime_schema_ensure_guest_profiles')) {
        runtime_schema_ensure_guest_profiles($pdo);
    }
}

$hasOrdersPaymentType = function_exists('db_column_exists') && db_column_exists('orders', 'payment_type');
$hasOrdersTotalAmount = function_exists('db_column_exists') && db_column_exists('orders', 'total_amount');
$hasOrdersOrderType = function_exists('db_column_exists') && db_column_exists('orders', 'order_type');
$hasOrdersComment = function_exists('db_column_exists') && db_column_exists('orders', 'comment');
$hasOrdersNotes = function_exists('db_column_exists') && db_column_exists('orders', 'notes');
$hasOrdersCustomerName = function_exists('db_column_exists') && db_column_exists('orders', 'customer_name');
$hasOrdersCustomerPhone = function_exists('db_column_exists') && db_column_exists('orders', 'customer_phone');
$hasOrderItemsMenuItemId = function_exists('db_column_exists') && db_column_exists('order_items', 'menu_item_id');
$hasOrderItemsItemName = function_exists('db_column_exists') && db_column_exists('order_items', 'item_name');
$hasOrderItemsQty = function_exists('db_column_exists') && db_column_exists('order_items', 'qty');
$hasOrderItemsQuantity = function_exists('db_column_exists') && db_column_exists('order_items', 'quantity');
$hasOrderItemsPrice = function_exists('db_column_exists') && db_column_exists('order_items', 'price');

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    json_error('Некорректные данные запроса');
}

$tableId     = isset($data['table_id']) ? (int)$data['table_id'] : 0;
$paymentType = isset($data['payment_type']) ? (string)$data['payment_type'] : 'cash';
$orderComment = trim((string)($data['comment'] ?? ''));
$customerNameRaw = trim((string)($data['customer_name'] ?? ''));
$customerNameRaw = function_exists('mb_substr') ? mb_substr($customerNameRaw, 0, 190) : substr($customerNameRaw, 0, 190);
$customerPhoneRaw = trim((string)($data['customer_phone'] ?? ''));
$customerPhoneNorm = function_exists('guest_normalize_phone') ? guest_normalize_phone($customerPhoneRaw) : null;
$orderComment = function_exists('mb_substr')
    ? mb_substr($orderComment, 0, 500)
    : substr($orderComment, 0, 500);
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

if (function_exists('qr_public_is_delivery_table_row') && qr_public_is_delivery_table_row($tableRow)) {
    json_error('Служебный стол доставки недоступен для POS');
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
        'comment' => $orderComment,
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


    $orderCols = ['restaurant_id', 'table_id', 'total_price'];
    $orderVals = [':rid', ':table_id', ':total_price'];
    $orderParams = [
        ':rid' => (int)$currentRestaurant['id'],
        ':table_id' => $tableId,
        ':total_price' => $total,
    ];
    if ($hasOrdersTotalAmount) {
        $orderCols[] = 'total_amount';
        $orderVals[] = ':total_amount';
        $orderParams[':total_amount'] = $total;
    }
    if ($hasOrdersPaymentType) {
        $orderCols[] = 'payment_type';
        $orderVals[] = ':payment_type';
        $orderParams[':payment_type'] = $paymentType;
    }
    $orderCols[] = 'payment_status';
    $orderVals[] = ':payment_status';
    $orderParams[':payment_status'] = $paymentStatus;
    $orderCols[] = 'order_status';
    $orderVals[] = ':order_status';
    $orderParams[':order_status'] = 'new';
    if ($hasOrdersOrderType) {
        $orderCols[] = 'order_type';
        $orderVals[] = ':order_type';
        $orderParams[':order_type'] = 'manual';
    }
    if ($orderComment !== '') {
        if ($hasOrdersComment) {
            $orderCols[] = 'comment';
            $orderVals[] = ':comment';
            $orderParams[':comment'] = $orderComment;
        } elseif ($hasOrdersNotes) {
            $orderCols[] = 'notes';
            $orderVals[] = ':notes';
            $orderParams[':notes'] = $orderComment;
        }
    }
    if ($hasOrdersCustomerName && $customerNameRaw !== '') {
        $orderCols[] = 'customer_name';
        $orderVals[] = ':customer_name';
        $orderParams[':customer_name'] = $customerNameRaw;
    }
    if ($hasOrdersCustomerPhone && $customerPhoneNorm !== null) {
        $orderCols[] = 'customer_phone';
        $orderVals[] = ':customer_phone';
        $orderParams[':customer_phone'] = $customerPhoneNorm;
    }
    $orderCols[] = 'created_at';
    $orderVals[] = 'NOW()';

    if ($hasOrdersFlowIdCol && $flowId !== null) {
        $orderCols[] = 'flow_id';
        $orderVals[] = ':flow_id';
        $orderParams[':flow_id'] = (string)$flowId;
    }

    $insertSql = "INSERT INTO orders (" . implode(', ', $orderCols) . ") VALUES (" . implode(', ', $orderVals) . ")";
    $stmt = $pdo->prepare($insertSql);
    $stmt->execute($orderParams);
    $orderId = (int)$pdo->lastInsertId();

    // Позиции заказа (schema-safe for legacy order_items variants).
    $itemCols = ['order_id'];
    $itemVals = [':order_id'];
    if ($hasOrderItemsMenuItemId) {
        $itemCols[] = 'menu_item_id';
        $itemVals[] = ':menu_item_id';
    }
    if ($hasOrderItemsItemName) {
        $itemCols[] = 'item_name';
        $itemVals[] = ':item_name';
    }
    if ($hasOrderItemsQty) {
        $itemCols[] = 'qty';
        $itemVals[] = ':qty';
    }
    if ($hasOrderItemsQuantity) {
        $itemCols[] = 'quantity';
        $itemVals[] = ':quantity';
    }
    if ($hasOrderItemsPrice) {
        $itemCols[] = 'price';
        $itemVals[] = ':price';
    }
    if (count($itemCols) < 2) {
        throw new RuntimeException('order_items schema unsupported for POS create');
    }
    $stmtItem = $pdo->prepare(
        "INSERT INTO order_items (" . implode(', ', $itemCols) . ") VALUES (" . implode(', ', $itemVals) . ")"
    );

    foreach ($orderItems as $oi) {
        $itemParams = [
            ':order_id' => $orderId,
        ];
        if ($hasOrderItemsMenuItemId) {
            $itemParams[':menu_item_id'] = $oi['id'];
        }
        if ($hasOrderItemsItemName) {
            $itemParams[':item_name'] = (string)$oi['name'];
        }
        if ($hasOrderItemsQty) {
            $itemParams[':qty'] = $oi['qty'];
        }
        if ($hasOrderItemsQuantity) {
            $itemParams[':quantity'] = $oi['qty'];
        }
        if ($hasOrderItemsPrice) {
            $itemParams[':price'] = $oi['price'];
        }
        $stmtItem->execute($itemParams);
    }

    $pdo->commit();
    $_SESSION['pos_order_idem'][$idemKey] = ['state' => 'done', 'order_id' => $orderId, 'ts' => time()];

    if (function_exists('crm_guest_profile_touch')) {
        try {
            crm_guest_profile_touch($pdo, (int)$currentRestaurant['id'], [
                'order_id' => (int)$orderId,
                'order_type' => 'manual',
                'total_price' => (float)$total,
                'created_at' => date('Y-m-d H:i:s'),
                'customer_name' => $customerNameRaw,
                'customer_phone' => $customerPhoneNorm ?? $customerPhoneRaw,
                'delivery_phone' => '',
                'guest_phone' => '',
                'guest_name' => '',
                'loyalty_phone' => '',
            ]);
        } catch (Throwable $eCrmProfile) {
            if (function_exists('error_log')) {
                error_log('POS_CRM_GUEST_PROFILE_TOUCH_FAIL order_id=' . (int)$orderId . ' ' . $eCrmProfile->getMessage());
            }
        }
    }

    // Логирование
    if (function_exists('add_log')) {
        $lines = [];
        $lines[] = 'POS-заказ #' . $orderId . ' создан сотрудником #' . (int)$user['id'];
        $lines[] = 'Стол: ' . $tableRow['name'] . ' (ID ' . (int)$tableRow['id'] . ')';
        $lines[] = 'Оплата: ' . $paymentType . ' / статус оплаты: ' . $paymentStatus;
        if ($hasOrdersOrderType) {
            $lines[] = 'Тип заказа: manual';
        }
        if ($orderComment !== '') {
            $lines[] = 'Комментарий: ' . $orderComment;
        }
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

} catch (Throwable $e) {
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
