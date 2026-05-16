<?php

require_once __DIR__ . '/../../app/bootstrap.php';
if (file_exists(__DIR__ . '/../../app/schema_guard.php')) {
    require_once __DIR__ . '/../../app/schema_guard.php';
}
if (file_exists(__DIR__ . '/../../app/runtime_schema_bootstrap.php')) {
    require_once __DIR__ . '/../../app/runtime_schema_bootstrap.php';
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    echo json_encode([
        'success' => false,
        'message' => 'Некорректный метод запроса.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = db();
if (!$pdo instanceof PDO) {
    echo json_encode([
        'success' => false,
        'message' => 'Нет соединения с БД.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (function_exists('runtime_schema_ensure_table_reservations')) {
    runtime_schema_ensure_table_reservations($pdo);
}
if (function_exists('runtime_schema_ensure_guest_profiles')) {
    runtime_schema_ensure_guest_profiles($pdo);
}

$restaurantId = (int)($currentRestaurant['id'] ?? 0);
if ($restaurantId <= 0) {
    echo json_encode([
        'success' => false,
        'message' => 'Ресторан не найден.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$csrf = trim((string)($_POST['csrf'] ?? ''));
$sessionCsrf = trim((string)($_SESSION['reservation_csrf'] ?? ''));
if ($csrf === '' || $sessionCsrf === '' || !hash_equals($sessionCsrf, $csrf)) {
    echo json_encode([
        'success' => false,
        'message' => 'Сессия бронирования устарела. Обновите страницу.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$actor = auth_user();
$createdByUserId = (int)($actor['id'] ?? 0);

$payload = [
    'table_id' => (int)($_POST['table_id'] ?? 0),
    'guest_name' => trim((string)($_POST['guest_name'] ?? '')),
    'guest_phone' => trim((string)($_POST['guest_phone'] ?? '')),
    'guests_count' => (int)($_POST['guests_count'] ?? 1),
    'reservation_date' => trim((string)($_POST['reservation_date'] ?? '')),
    'reservation_time' => trim((string)($_POST['reservation_time'] ?? '')),
    'reservation_datetime' => trim((string)($_POST['reservation_datetime'] ?? '')),
    'duration_minutes' => (int)($_POST['duration_minutes'] ?? 120),
    'comment' => trim((string)($_POST['comment'] ?? '')),
    'status' => trim((string)($_POST['status'] ?? 'pending')),
    'source' => trim((string)($_POST['source'] ?? 'guest_web')),
    'created_by_user_id' => $createdByUserId > 0 ? $createdByUserId : 0,
];

if (!function_exists('reservation_create')) {
    echo json_encode([
        'success' => false,
        'message' => 'Сервис бронирования недоступен.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $create = reservation_create($pdo, $restaurantId, $payload);
    if (empty($create['ok'])) {
        $errorCode = (string)($create['error'] ?? 'unknown');
        $messageMap = [
            'invalid_restaurant' => 'Некорректный ресторан.',
            'reservations_unavailable' => 'Сервис бронирования временно недоступен.',
            'invalid_table' => 'Выберите стол.',
            'phone_required' => 'Укажите номер телефона.',
            'invalid_phone' => 'Укажите корректный номер телефона.',
            'invalid_datetime' => 'Укажите корректные дату и время.',
            'datetime_in_past' => 'Нельзя создать бронь в прошлом времени.',
            'table_not_found' => 'Стол не найден.',
            'table_overlap' => 'Этот стол уже занят на выбранное время.',
            'write_failed' => 'Не удалось сохранить бронь. Попробуйте ещё раз.',
        ];
        echo json_encode([
            'success' => false,
            'error' => $errorCode,
            'message' => $messageMap[$errorCode] ?? 'Не удалось создать бронь.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode([
        'success' => true,
        'reservation_id' => (int)($create['reservation_id'] ?? 0),
        'status' => (string)($create['status'] ?? 'pending'),
        'status_label' => function_exists('reservation_status_label')
            ? reservation_status_label((string)($create['status'] ?? 'pending'))
            : 'Ожидает подтверждения',
        'message' => 'Бронь создана. Ресторан подтвердит её в рабочей панели.',
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('RESERVATION_CREATE_FAIL rest_id=' . $restaurantId . ' ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Не удалось создать бронь. Попробуйте позже.',
    ], JSON_UNESCAPED_UNICODE);
}
