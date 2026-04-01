<?php
/**
 * Stripe webhook endpoint. No auth. Verifies signature, syncs subscription/invoice data. Safe JSON response.
 */

$rid = bin2hex(random_bytes(4));
header('Content-Type: application/json; charset=utf-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['received' => false, 'error' => 'Method not allowed']);
        exit;
    }
    $payload = file_get_contents('php://input');
    $sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
    if ($sigHeader === '') {
        error_log('STRIPE_WEBHOOK_ERROR rid=' . $rid . ' missing Stripe-Signature');
        if (file_exists(__DIR__ . '/../app/logging.php')) {
            require_once __DIR__ . '/../app/logging.php';
            if (function_exists('app_error_log')) {
                app_error_log('stripe_webhook', 'Missing Stripe-Signature', [], 'error', $rid);
            }
        }
        http_response_code(400);
        echo json_encode(['received' => false, 'error' => 'Missing signature']);
        exit;
    }
    if (!file_exists(__DIR__ . '/../app/stripe_billing.php')) {
        http_response_code(200);
        echo json_encode(['received' => true]);
        exit;
    }
    require_once __DIR__ . '/../app/stripe_billing.php';
    if (!stripe_billing_enabled()) {
        http_response_code(200);
        echo json_encode(['received' => true]);
        exit;
    }
    $result = stripe_verify_webhook($payload, $sigHeader);
    if (!$result['ok']) {
        $errMsg = $result['error'] ?? 'verify failed';
        error_log('STRIPE_WEBHOOK_ERROR rid=' . $rid . ' ' . $errMsg);
        if (file_exists(__DIR__ . '/../app/logging.php')) {
            require_once __DIR__ . '/../app/logging.php';
            if (function_exists('app_error_log')) {
                app_error_log('stripe_webhook', $errMsg, [], 'error', $rid);
            }
        }
        http_response_code(400);
        echo json_encode(['received' => false, 'error' => $result['error'] ?? 'Invalid signature']);
        exit;
    }
    $event = $result['event'] ?? [];
    if (!empty($event)) {
        stripe_sync_subscription_from_webhook($event);
    }
    http_response_code(200);
    echo json_encode(['received' => true]);
} catch (Throwable $e) {
    error_log('STABILITY_ERROR stripe_webhook rid=' . $rid . ' ' . $e->getMessage());
    if (file_exists(__DIR__ . '/../app/logging.php')) {
        require_once __DIR__ . '/../app/logging.php';
        if (function_exists('app_error_log')) {
            app_error_log('stripe_webhook', $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()], 'error', $rid);
        }
    }
    if (!headers_sent()) {
        http_response_code(200);
    }
    echo json_encode(['received' => false, 'error' => 'Internal error']);
}
