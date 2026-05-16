<?php
/**
 * Global error handling (Variant 7). Log JSON; user sees HTTP 500 "Internal error" only (no stack trace).
 * For Accept: application/json or /health.php — respond with JSON.
 */

function stability_exception_handler(Throwable $e): void
{
    $userId = null;
    if (function_exists('auth_user')) {
        $u = auth_user();
        $userId = $u ? (int)($u['id'] ?? 0) : null;
    }
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    $payload = [
        'timestamp'   => gmdate('Y-m-d\TH:i:s\Z'),
        'level'       => 'exception',
        'user_id'     => $userId,
        'uri'         => $uri,
        'error_type'  => get_class($e),
        'message'     => $e->getMessage(),
        'file'        => $e->getFile(),
        'line'        => $e->getLine(),
        'rid'         => function_exists('app_rid') ? app_rid() : null,
    ];
    error_log('STABILITY_ERROR ' . json_encode($payload, JSON_UNESCAPED_UNICODE));

    $wantsJson = (function_exists('is_api_request') && is_api_request())
        || (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false)
        || (strpos($uri, '/health.php') !== false);
    if (!headers_sent()) {
        http_response_code(500);
        if ($wantsJson) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'Internal error']);
        } else {
            header('Content-Type: text/html; charset=utf-8');
            echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Ошибка</title></head><body><p>Internal error.</p></body></html>';
        }
    }
    exit;
}

function stability_error_handler(int $severity, string $message, string $file, int $line): bool
{
    if ($severity === E_DEPRECATED || $severity === E_USER_DEPRECATED) {
        return true;
    }
    $userId = null;
    if (function_exists('auth_user')) {
        $u = auth_user();
        $userId = $u ? (int)($u['id'] ?? 0) : null;
    }
    $payload = [
        'timestamp'   => gmdate('Y-m-d\TH:i:s\Z'),
        'level'       => 'error',
        'user_id'    => $userId,
        'uri'        => $_SERVER['REQUEST_URI'] ?? '',
        'error_type' => 'PHP Error',
        'message'    => $message,
        'file'       => $file,
        'line'       => $line,
        'rid'        => function_exists('app_rid') ? app_rid() : null,
    ];
    error_log('STABILITY_ERROR ' . json_encode($payload, JSON_UNESCAPED_UNICODE));
    if (function_exists('is_api_request') && is_api_request()) {
        return true;
    }
    return false;
}
