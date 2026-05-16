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
    $code = trim((string)($body['code'] ?? ''));
    $phone = function_exists('guest_phone_normalize') ? guest_phone_normalize($phoneRaw) : null;

    if (!$phone || $code === '') {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'invalid_input'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $pdo = db();
    $restaurantId = (int)($currentRestaurant['id'] ?? 0);

    $v = guest_otp_verify($pdo, $restaurantId, $phone, $code);
    if (!$v['ok']) {
        $err = (string)($v['error'] ?? 'verify_failed');
        $codeHttp = 400;
        if ($err === 'too_many_attempts' || $err === 'rate_limited') {
            $codeHttp = 429;
        }
        http_response_code($codeHttp);
        echo json_encode([
            'success' => false,
            'error' => $err,
            'attempts_left' => isset($v['attempts_left']) ? (int)$v['attempts_left'] : null,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $guest = function_exists('guest_find_by_phone') ? guest_find_by_phone($pdo, $phone) : null;
    $isNew = false;
    if (!$guest) {
        $isNew = true;
        $legacyPinHash = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
        $gid = guest_insert_new($pdo, $phone, null, $legacyPinHash);
        if ($gid > 0) {
            $guest = ['id' => $gid, 'phone' => $phone, 'name' => null];
        } else {
            $isNew = false;
            $guest = function_exists('guest_find_by_phone') ? guest_find_by_phone($pdo, $phone) : null;
        }
    }

    if (!$guest || empty($guest['id'])) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'guest_create_failed'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $guestId = (int)$guest['id'];
    guest_session_login($guestId);

    $welcomeBonus = 0;
    if ($restaurantId > 0 && $isNew) {
        $welcomeBonus = 50;
        $loyaltyOk = true;
        if (file_exists(__DIR__ . '/../../app/subscription_plans.php')) {
            require_once __DIR__ . '/../../app/subscription_plans.php';
            if (function_exists('check_feature') && !check_feature($restaurantId, 'loyalty_enabled')) {
                $loyaltyOk = false;
            }
        }
        if ($loyaltyOk && $currentRestaurant && function_exists('restaurant_loyalty_enabled')) {
            $loyaltyOk = restaurant_loyalty_enabled($currentRestaurant);
        }
        if ($welcomeBonus > 0 && $loyaltyOk && function_exists('guest_issue_card_for_restaurant')) {
            try {
                guest_issue_card_for_restaurant($pdo, $restaurantId, $phone, null);
            } catch (Throwable $e) {
                if (function_exists('error_log')) {
                    error_log('verify_otp guest_issue_card ' . $e->getMessage());
                }
            }
        }
        if ($welcomeBonus > 0 && $loyaltyOk && function_exists('guest_loyalty_add_points')) {
            try {
                guest_loyalty_add_points($pdo, $restaurantId, $guestId, $welcomeBonus, null, null, 'Приветственный бонус');
            } catch (Throwable $e) {
                if (function_exists('error_log')) {
                    error_log('verify_otp welcome_bonus ' . $e->getMessage());
                }
                $welcomeBonus = 0;
            }
        } else {
            $welcomeBonus = 0;
        }
    } elseif ($restaurantId > 0) {
        if (function_exists('guest_issue_card_for_restaurant')) {
            try {
                guest_issue_card_for_restaurant($pdo, $restaurantId, $phone, null);
            } catch (Throwable $e) {
                if (function_exists('error_log')) {
                    error_log('verify_otp guest_issue_card ' . $e->getMessage());
                }
            }
        }
    }

    $balance = 0;
    if ($restaurantId > 0 && function_exists('guest_loyalty_balance_by_guest_rest')) {
        $balance = guest_loyalty_balance_by_guest_rest($pdo, $restaurantId, $guestId);
    }

    echo json_encode([
        'success' => true,
        'guest' => [
            'id' => $guestId,
            'phone' => (string)($guest['phone'] ?? $phone),
            'name' => $guest['name'] ?? null,
            'loyalty_balance' => $balance,
        ],
        'welcome_bonus' => $welcomeBonus,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if (function_exists('error_log')) {
        error_log('guest/verify_otp failed uri=' . ($_SERVER['REQUEST_URI'] ?? '') . ' host=' . ($_SERVER['HTTP_HOST'] ?? '') . ' ' . $e->getMessage());
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'internal_error'], JSON_UNESCAPED_UNICODE);
}
