<?php
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/guest_otp.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'method_not_allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

try {
    $raw = file_get_contents('php://input');
    $body = json_decode((string)$raw, true);
    if (!is_array($body)) {
        $body = $_POST;
    }

    $phoneRaw = trim((string)($body['phone'] ?? ''));
    $phone = function_exists('guest_phone_normalize') ? guest_phone_normalize($phoneRaw) : null;

    if (!$phone) {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'invalid_phone'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $pdo = db();
    $restaurantId = (int)($currentRestaurant['id'] ?? 0);
    $res = guest_otp_send_for_phone($pdo, $restaurantId, $phone);
    if (!$res['ok']) {
        $err = (string)($res['error'] ?? 'service_unavailable');
        if ($err === 'rate_limited') {
            http_response_code(429);
            echo json_encode([
                'success' => false,
                'error' => 'rate_limited',
                'retry_after_sec' => (int)($res['wait_sec'] ?? 60),
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        http_response_code(503);
        echo json_encode(['success' => false, 'error' => $err], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $payload = ['success' => true];
    if (!empty($res['test_code']) && function_exists('guest_otp_test_mode') && guest_otp_test_mode()) {
        $payload['test_mode'] = true;
        $payload['test_code'] = (string)$res['test_code'];
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if (function_exists('error_log')) {
        error_log('guest/send_otp failed uri=' . ($_SERVER['REQUEST_URI'] ?? '')
            . ' host=' . ($_SERVER['HTTP_HOST'] ?? '')
            . ' server_name=' . ($_SERVER['SERVER_NAME'] ?? '')
            . ' x_forwarded_host=' . ($_SERVER['HTTP_X_FORWARDED_HOST'] ?? '')
            . ' ' . $e->getMessage());
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'internal_error'], JSON_UNESCAPED_UNICODE);
}
