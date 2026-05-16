<?php
/**
 * OTP for guest phone verification.
 *
 * Scope:
 * - restaurant_id > 0: restaurant-scoped OTP for QR / loyalty flows
 * - restaurant_id = 0: global OTP for wallet / guest cabinet login on main domain
 */

if (file_exists(__DIR__ . '/schema_guard.php')) {
    require_once __DIR__ . '/schema_guard.php';
}
require_once __DIR__ . '/guest_sms.php';

if (!function_exists('guest_otp_private_proxy_addr')) {
    function guest_otp_private_proxy_addr(?string $ip): bool {
        $ip = trim((string)$ip);
        if ($ip === '') {
            return false;
        }
        if ($ip === '127.0.0.1' || $ip === '::1') {
            return true;
        }
        if (str_starts_with($ip, '10.') || str_starts_with($ip, '192.168.')) {
            return true;
        }
        return (bool)preg_match('/^172\.(1[6-9]|2\d|3[0-1])\./', $ip);
    }
}

if (!function_exists('guest_otp_header_host_value')) {
    function guest_otp_header_host_value(string $value): string {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $first = trim(explode(',', $value)[0] ?? '');
        $first = preg_replace('/:\d+$/', '', $first);
        $first = trim((string)$first, " \t\n\r\0\x0B.");
        $first = strtolower((string)$first);
        if ($first === '' || preg_match('/[^a-z0-9\.\-]/', $first)) {
            return '';
        }
        return $first;
    }
}

if (!function_exists('guest_otp_request_host')) {
    function guest_otp_request_host(): string {
        $candidates = [];
        $remoteAddr = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $proxyTrusted = guest_otp_private_proxy_addr($remoteAddr);

        if ($proxyTrusted) {
            foreach (['HTTP_X_FORWARDED_HOST', 'HTTP_X_ORIGINAL_HOST', 'HTTP_X_FORWARDED_SERVER'] as $key) {
                $host = guest_otp_header_host_value((string)($_SERVER[$key] ?? ''));
                if ($host !== '') {
                    $candidates[] = $host;
                }
            }
        }

        foreach (['HTTP_HOST', 'SERVER_NAME'] as $key) {
            $host = guest_otp_header_host_value((string)($_SERVER[$key] ?? ''));
            if ($host !== '') {
                $candidates[] = $host;
            }
        }

        foreach ($candidates as $candidate) {
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return '';
    }
}

if (!function_exists('guest_otp_internal_host_name')) {
    function guest_otp_internal_host_name(string $host): bool {
        $host = guest_otp_header_host_value($host);
        if ($host === '') {
            return true;
        }
        if ($host === 'localhost' || $host === 'php-fpm' || $host === 'nginx') {
            return true;
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return true;
        }
        return strpos($host, '.') === false;
    }
}

if (!function_exists('guest_otp_is_test_host')) {
    function guest_otp_is_test_host(array $cfg): bool {
        $mainDomain = strtolower((string)($cfg['app']['main_domain'] ?? ''));
        if ($mainDomain === '') {
            return false;
        }

        $testHost = 'test.' . $mainDomain;
        $host = guest_otp_request_host();
        if ($host === $testHost) {
            return true;
        }

        $forwardedHost = guest_otp_header_host_value((string)($_SERVER['HTTP_X_FORWARDED_HOST'] ?? ''));
        $httpHost = (string)($_SERVER['HTTP_HOST'] ?? '');
        $serverName = (string)($_SERVER['SERVER_NAME'] ?? '');
        $httpLooksInternal = guest_otp_internal_host_name($httpHost);
        $serverLooksInternal = $serverName === '' ? $httpLooksInternal : guest_otp_internal_host_name($serverName);
        $upstreamLooksInternal = $httpLooksInternal || $serverLooksInternal;

        return $forwardedHost === $testHost && $upstreamLooksInternal;
    }
}

if (!function_exists('guest_otp_test_mode')) {
    function guest_otp_test_mode(): bool {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        $path = __DIR__ . '/config.php';
        $cfg = is_file($path) ? require $path : [];
        $appEnv = strtolower((string)($cfg['app']['env'] ?? 'local'));
        $enabled = !empty($cfg['app']['otp_test_mode']);
        $fixedCode = trim((string)($cfg['app']['otp_test_code'] ?? ''));
        $providerConfigured = function_exists('guest_sms_provider_configured')
            ? guest_sms_provider_configured()
            : false;
        $hostAllowsTestMode = guest_otp_is_test_host($cfg);

        $cached = ($appEnv !== 'production' || $hostAllowsTestMode)
            && ($enabled || $fixedCode !== '' || !$providerConfigured);
        return $cached;
    }
}

if (!function_exists('guest_otp_test_fixed_code_for_phone')) {
    function guest_otp_test_fixed_code_for_phone(string $phoneNorm): ?string {
        if (!guest_otp_test_mode()) {
            return null;
        }

        $path = __DIR__ . '/config.php';
        $cfg = is_file($path) ? require $path : [];
        $isTestHost = guest_otp_is_test_host($cfg);
        $fixedCode = preg_replace('/\D+/', '', (string)($cfg['app']['otp_test_code'] ?? ''));
        if ($fixedCode === '' && function_exists('guest_sms_provider_configured') && !guest_sms_provider_configured()) {
            $fixedCode = '111111';
        }
        if ($fixedCode === '' || strlen($fixedCode) !== 6) {
            return null;
        }

        $rawPhones = trim((string)($cfg['app']['otp_test_phone'] ?? ''));
        if ($rawPhones === '' || $isTestHost) {
            return $fixedCode;
        }

        $allowed = array_filter(array_map('trim', explode(',', $rawPhones)));
        foreach ($allowed as $candidate) {
            $normalized = function_exists('guest_phone_normalize')
                ? guest_phone_normalize($candidate)
                : null;
            if ($normalized !== null && $normalized === $phoneNorm) {
                return $fixedCode;
            }
        }

        return null;
    }
}

if (!function_exists('guest_otp_table_ready')) {
    function guest_otp_table_ready(): bool {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        if (!function_exists('db_table_exists') || !db_table_exists('otp_codes')) {
            $cached = false;
            return false;
        }

        if (!function_exists('db_column_exists')) {
            $cached = true;
            return true;
        }

        foreach (['restaurant_id', 'phone', 'code', 'expires_at', 'attempts', 'consumed_at'] as $column) {
            if (!db_column_exists('otp_codes', $column)) {
                $cached = false;
                return false;
            }
        }

        $cached = true;
        return true;
    }
}

if (!function_exists('guest_otp_scope_restaurant_id')) {
    function guest_otp_scope_restaurant_id(int $restaurantId): int {
        return max(0, $restaurantId);
    }
}

if (!function_exists('guest_otp_ttl_seconds')) {
    function guest_otp_ttl_seconds(): int {
        return 5 * 60;
    }
}

if (!function_exists('guest_otp_resend_interval_seconds')) {
    function guest_otp_resend_interval_seconds(): int {
        return 60;
    }
}

if (!function_exists('guest_otp_send_window_seconds')) {
    function guest_otp_send_window_seconds(): int {
        return 15 * 60;
    }
}

if (!function_exists('guest_otp_send_window_limit')) {
    function guest_otp_send_window_limit(): int {
        return 5;
    }
}

if (!function_exists('guest_otp_max_attempts')) {
    function guest_otp_max_attempts(): int {
        return 5;
    }
}

if (!function_exists('guest_otp_dev_state_key')) {
    function guest_otp_dev_state_key(int $restaurantId, string $phoneNorm): string {
        $digits = preg_replace('/\D+/', '', $phoneNorm);
        return '_guest_otp_dev_' . guest_otp_scope_restaurant_id($restaurantId) . '_' . $digits;
    }
}

if (!function_exists('guest_otp_dev_state_boot')) {
    function guest_otp_dev_state_boot(): void {
        if (session_status() === PHP_SESSION_NONE && function_exists('auth_start_session')) {
            auth_start_session();
        }
        if (!isset($_SESSION['_guest_otp_dev']) || !is_array($_SESSION['_guest_otp_dev'])) {
            $_SESSION['_guest_otp_dev'] = [];
        }
    }
}

if (!function_exists('guest_otp_dev_fallback_send')) {
    /**
     * Session-backed fallback for non-production test mode when otp_codes schema is unavailable.
     * @return array{ok: bool, error?: string, test_code?: string}
     */
    function guest_otp_dev_fallback_send(int $restaurantId, string $phoneNorm): array {
        $code = guest_otp_test_fixed_code_for_phone($phoneNorm);
        if ($code === null) {
            if (function_exists('error_log')) {
                error_log('OTP DEV FALLBACK DISABLED host=' . guest_otp_request_host()
                    . ' http_host=' . (string)($_SERVER['HTTP_HOST'] ?? '')
                    . ' server_name=' . (string)($_SERVER['SERVER_NAME'] ?? '')
                    . ' x_forwarded_host=' . (string)($_SERVER['HTTP_X_FORWARDED_HOST'] ?? '')
                    . ' remote_addr=' . (string)($_SERVER['REMOTE_ADDR'] ?? ''));
            }
            return ['ok' => false, 'error' => 'service_unavailable'];
        }

        guest_otp_dev_state_boot();
        $key = guest_otp_dev_state_key($restaurantId, $phoneNorm);
        $_SESSION['_guest_otp_dev'][$key] = [
            'code' => $code,
            'expires_at' => time() + guest_otp_ttl_seconds(),
            'attempts' => 0,
            'used' => false,
        ];

        if (function_exists('error_log')) {
            error_log('OTP DEV FALLBACK: scope=' . guest_otp_scope_restaurant_id($restaurantId) . ' phone=' . preg_replace('/\D+/', '', $phoneNorm) . ' code=' . $code);
        }

        return ['ok' => true, 'test_code' => $code];
    }
}

if (!function_exists('guest_otp_dev_fallback_verify')) {
    /**
     * @return array{ok: bool, error?: string, attempts_left?: int}
     */
    function guest_otp_dev_fallback_verify(int $restaurantId, string $phoneNorm, string $codeIn): array {
        $fixedCode = guest_otp_test_fixed_code_for_phone($phoneNorm);
        if ($fixedCode === null) {
            return ['ok' => false, 'error' => 'service_unavailable'];
        }
        $allowFixedCodeReuse = true;

        $codeIn = preg_replace('/\D+/', '', trim($codeIn));
        if ($codeIn === '' || strlen($codeIn) !== 6) {
            return ['ok' => false, 'error' => 'invalid_code'];
        }

        guest_otp_dev_state_boot();
        $key = guest_otp_dev_state_key($restaurantId, $phoneNorm);
        $state = $_SESSION['_guest_otp_dev'][$key] ?? null;
        if (!is_array($state)) {
            return ['ok' => false, 'error' => 'no_code'];
        }

        $expiresAt = (int)($state['expires_at'] ?? 0);
        if ($expiresAt <= 0 || $expiresAt < time()) {
            unset($_SESSION['_guest_otp_dev'][$key]);
            return ['ok' => false, 'error' => 'expired'];
        }

        if (!empty($state['used']) && !$allowFixedCodeReuse) {
            return ['ok' => false, 'error' => 'used'];
        }

        $attempts = (int)($state['attempts'] ?? 0);
        if ($attempts >= guest_otp_max_attempts()) {
            $_SESSION['_guest_otp_dev'][$key]['used'] = true;
            return ['ok' => false, 'error' => 'too_many_attempts', 'attempts_left' => 0];
        }

        if (!hash_equals($fixedCode, $codeIn)) {
            $attempts++;
            $_SESSION['_guest_otp_dev'][$key]['attempts'] = $attempts;
            if ($attempts >= guest_otp_max_attempts()) {
                $_SESSION['_guest_otp_dev'][$key]['used'] = true;
            }
            return [
                'ok' => false,
                'error' => 'wrong_code',
                'attempts_left' => max(0, guest_otp_max_attempts() - $attempts),
            ];
        }

        if (!$allowFixedCodeReuse) {
            $_SESSION['_guest_otp_dev'][$key]['used'] = true;
        }
        return ['ok' => true];
    }
}

if (!function_exists('guest_otp_session_rate_limit_ok')) {
    /**
     * @return array{ok: bool, wait_sec?: int}
     */
    function guest_otp_session_rate_limit_ok(string $bucket, int $limit, int $windowSec): array {
        if (session_status() === PHP_SESSION_NONE && function_exists('auth_start_session')) {
            auth_start_session();
        }

        $now = time();
        $cutoff = $now - $windowSec;
        if (!isset($_SESSION['_guest_otp_rl']) || !is_array($_SESSION['_guest_otp_rl'])) {
            $_SESSION['_guest_otp_rl'] = [];
        }
        if (!isset($_SESSION['_guest_otp_rl'][$bucket]) || !is_array($_SESSION['_guest_otp_rl'][$bucket])) {
            $_SESSION['_guest_otp_rl'][$bucket] = [];
        }

        $_SESSION['_guest_otp_rl'][$bucket] = array_values(array_filter(
            $_SESSION['_guest_otp_rl'][$bucket],
            static fn($ts) => is_int($ts) && $ts >= $cutoff
        ));

        if (count($_SESSION['_guest_otp_rl'][$bucket]) >= $limit) {
            $oldest = (int)($_SESSION['_guest_otp_rl'][$bucket][0] ?? $now);
            return ['ok' => false, 'wait_sec' => max(1, ($oldest + $windowSec) - $now)];
        }

        $_SESSION['_guest_otp_rl'][$bucket][] = $now;
        return ['ok' => true];
    }
}

if (!function_exists('guest_otp_ip_bucket')) {
    function guest_otp_ip_bucket(string $action, int $restaurantId): string {
        $ipHash = function_exists('security_hash_ip')
            ? security_hash_ip($_SERVER['REMOTE_ADDR'] ?? '')
            : md5((string)($_SERVER['REMOTE_ADDR'] ?? ''));
        return 'otp_' . $action . '_' . guest_otp_scope_restaurant_id($restaurantId) . '_' . $ipHash;
    }
}

if (!function_exists('guest_otp_generate_code')) {
    function guest_otp_generate_code(): string {
        return str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('guest_otp_hash_code')) {
    function guest_otp_hash_code(string $code): string {
        return password_hash($code, PASSWORD_DEFAULT);
    }
}

if (!function_exists('guest_otp_code_column_max_length')) {
    function guest_otp_code_column_max_length(PDO $pdo): ?int {
        static $cached = null;
        static $loaded = false;
        if ($loaded) {
            return $cached;
        }

        $loaded = true;
        try {
            $stmt = $pdo->query("
                SELECT CHARACTER_MAXIMUM_LENGTH
                FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'otp_codes'
                  AND COLUMN_NAME = 'code'
                LIMIT 1
            ");
            $value = $stmt ? $stmt->fetchColumn() : false;
            if ($value !== false && $value !== null) {
                $cached = (int)$value;
            }
        } catch (Throwable $e) {
            $cached = null;
        }

        return $cached;
    }
}

if (!function_exists('guest_otp_storage_value')) {
    function guest_otp_storage_value(PDO $pdo, string $code): string {
        $hashed = guest_otp_hash_code($code);
        $maxLength = guest_otp_code_column_max_length($pdo);

        // Legacy schemas still use otp_codes.code VARCHAR(10). Keep them working
        // until the widening migration is applied.
        if ($maxLength === null || $maxLength < strlen($hashed)) {
            return $code;
        }

        return $hashed;
    }
}

if (!function_exists('guest_otp_code_matches')) {
    function guest_otp_code_matches(string $stored, string $codeIn): bool {
        $stored = trim($stored);
        if ($stored === '') {
            return false;
        }

        if (str_starts_with($stored, '$2y$') || str_starts_with($stored, '$2a$') || str_starts_with($stored, '$argon2')) {
            return password_verify($codeIn, $stored);
        }

        return hash_equals($stored, $codeIn);
    }
}

if (!function_exists('guest_otp_cleanup_phone_rows')) {
    function guest_otp_cleanup_phone_rows(PDO $pdo, int $restaurantId, string $phoneNorm): void {
        $restaurantId = guest_otp_scope_restaurant_id($restaurantId);
        $stmt = $pdo->prepare("
            DELETE FROM otp_codes
            WHERE restaurant_id = ?
              AND phone = ?
              AND (
                    expires_at < NOW()
                    OR (consumed_at IS NOT NULL AND created_at < DATE_SUB(NOW(), INTERVAL 1 DAY))
                  )
        ");
        $stmt->execute([$restaurantId, $phoneNorm]);
    }
}

if (!function_exists('guest_otp_send_rate_limit_ok')) {
    /**
     * @return array{ok: bool, wait_sec?: int}
     */
    function guest_otp_send_rate_limit_ok(PDO $pdo, int $restaurantId, string $phoneNorm): array {
        $restaurantId = guest_otp_scope_restaurant_id($restaurantId);
        if (guest_otp_test_fixed_code_for_phone($phoneNorm) !== null) {
            return ['ok' => true];
        }

        $ipRate = guest_otp_session_rate_limit_ok(guest_otp_ip_bucket('send', $restaurantId), 12, guest_otp_send_window_seconds());
        if (!$ipRate['ok']) {
            return [
                'ok' => false,
                'error' => 'rate_limited',
                'wait_sec' => (int)($ipRate['wait_sec'] ?? guest_otp_resend_interval_seconds()),
            ];
        }

        guest_otp_cleanup_phone_rows($pdo, $restaurantId, $phoneNorm);

        $latestStmt = $pdo->prepare("
            SELECT created_at
            FROM otp_codes
            WHERE restaurant_id = ?
              AND phone = ?
            ORDER BY id DESC
            LIMIT 1
        ");
        $latestStmt->execute([$restaurantId, $phoneNorm]);
        $latestCreatedAt = (string)($latestStmt->fetchColumn() ?: '');
        $latestTs = $latestCreatedAt !== '' ? strtotime($latestCreatedAt) : false;
        if ($latestTs !== false) {
            $elapsed = time() - $latestTs;
            if ($elapsed < guest_otp_resend_interval_seconds()) {
                return [
                    'ok' => false,
                    'wait_sec' => guest_otp_resend_interval_seconds() - $elapsed,
                ];
            }
        }

        $windowSeconds = guest_otp_send_window_seconds();
        $limit = guest_otp_send_window_limit();
        $since = date('Y-m-d H:i:s', time() - $windowSeconds);
        $countStmt = $pdo->prepare("
            SELECT COUNT(*) AS cnt, MIN(created_at) AS oldest_created_at
            FROM otp_codes
            WHERE restaurant_id = ?
              AND phone = ?
              AND created_at >= ?
        ");
        $countStmt->execute([$restaurantId, $phoneNorm, $since]);
        $row = $countStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $cnt = (int)($row['cnt'] ?? 0);
        if ($cnt >= $limit) {
            $oldestTs = !empty($row['oldest_created_at']) ? strtotime((string)$row['oldest_created_at']) : false;
            $waitSec = $windowSeconds;
            if ($oldestTs !== false) {
                $waitSec = max(1, ($oldestTs + $windowSeconds) - time());
            }
            return ['ok' => false, 'wait_sec' => $waitSec];
        }

        return ['ok' => true];
    }
}

if (!function_exists('guest_otp_send_for_phone')) {
    /**
     * @return array{ok: bool, error?: string, wait_sec?: int, test_code?: string}
     */
    function guest_otp_send_for_phone(PDO $pdo, int $restaurantId, string $phoneNorm): array {
        if (!guest_otp_table_ready()) {
            return guest_otp_dev_fallback_send($restaurantId, $phoneNorm);
        }

        $restaurantId = guest_otp_scope_restaurant_id($restaurantId);
        $rate = guest_otp_send_rate_limit_ok($pdo, $restaurantId, $phoneNorm);
        if (!$rate['ok']) {
            return [
                'ok' => false,
                'error' => 'rate_limited',
                'wait_sec' => (int)($rate['wait_sec'] ?? guest_otp_resend_interval_seconds()),
            ];
        }

        $fixedTestCode = guest_otp_test_fixed_code_for_phone($phoneNorm);
        $code = $fixedTestCode ?? guest_otp_generate_code();
        $codeStored = guest_otp_storage_value($pdo, $code);
        $expires = date('Y-m-d H:i:s', time() + guest_otp_ttl_seconds());

        if ($fixedTestCode !== null) {
            $pdo->prepare('DELETE FROM otp_codes WHERE restaurant_id = ? AND phone = ?')->execute([$restaurantId, $phoneNorm]);
        } else {
            $pdo->prepare("
                UPDATE otp_codes
                SET consumed_at = COALESCE(consumed_at, NOW())
                WHERE restaurant_id = ?
                  AND phone = ?
                  AND consumed_at IS NULL
            ")->execute([$restaurantId, $phoneNorm]);
        }

        $ins = $pdo->prepare('
            INSERT INTO otp_codes (restaurant_id, phone, code, expires_at, attempts)
            VALUES (?, ?, ?, ?, 0)
        ');
        $ins->execute([$restaurantId, $phoneNorm, $codeStored, $expires]);
        $otpId = (int)$pdo->lastInsertId();

        if (!guest_otp_test_mode()) {
            $text = 'Код входа: ' . $code . '. Действует 5 минут.';
            if (!send_sms($phoneNorm, $text)) {
                $pdo->prepare('DELETE FROM otp_codes WHERE id = ?')->execute([$otpId]);
                return ['ok' => false, 'error' => 'service_unavailable'];
            }
            return ['ok' => true];
        }

        if (function_exists('error_log')) {
            error_log('OTP TEST MODE: scope=' . $restaurantId . ' phone=' . preg_replace('/\D+/', '', $phoneNorm) . ' code=' . $code);
        }

        return ['ok' => true, 'test_code' => $code];
    }
}

if (!function_exists('guest_otp_verify')) {
    /**
     * @return array{ok: bool, error?: string, attempts_left?: int}
     */
    function guest_otp_verify(PDO $pdo, int $restaurantId, string $phoneNorm, string $codeIn): array {
        if (!guest_otp_table_ready()) {
            return guest_otp_dev_fallback_verify($restaurantId, $phoneNorm, $codeIn);
        }

        $restaurantId = guest_otp_scope_restaurant_id($restaurantId);
        $codeIn = preg_replace('/\D+/', '', trim($codeIn));
        if ($codeIn === '' || strlen($codeIn) !== 6) {
            return ['ok' => false, 'error' => 'invalid_code'];
        }

        $ipRate = guest_otp_session_rate_limit_ok(guest_otp_ip_bucket('verify', $restaurantId), 25, guest_otp_send_window_seconds());
        if (!$ipRate['ok']) {
            return [
                'ok' => false,
                'error' => 'rate_limited',
                'attempts_left' => null,
            ];
        }

        guest_otp_cleanup_phone_rows($pdo, $restaurantId, $phoneNorm);

        $stmt = $pdo->prepare('
            SELECT id, code, expires_at, attempts, consumed_at
            FROM otp_codes
            WHERE restaurant_id = ? AND phone = ?
            ORDER BY id DESC
            LIMIT 1
        ');
        $stmt->execute([$restaurantId, $phoneNorm]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['ok' => false, 'error' => 'no_code'];
        }

        $otpId = (int)($row['id'] ?? 0);
        if (!empty($row['consumed_at'])) {
            return ['ok' => false, 'error' => 'used'];
        }

        $expiresAt = strtotime((string)($row['expires_at'] ?? ''));
        if ($expiresAt === false || $expiresAt < time()) {
            $pdo->prepare('UPDATE otp_codes SET consumed_at = NOW() WHERE id = ? AND consumed_at IS NULL')->execute([$otpId]);
            return ['ok' => false, 'error' => 'expired'];
        }

        $attempts = (int)($row['attempts'] ?? 0);
        if ($attempts >= guest_otp_max_attempts()) {
            $pdo->prepare('UPDATE otp_codes SET consumed_at = COALESCE(consumed_at, NOW()) WHERE id = ?')->execute([$otpId]);
            return ['ok' => false, 'error' => 'too_many_attempts', 'attempts_left' => 0];
        }

        if (!guest_otp_code_matches((string)($row['code'] ?? ''), $codeIn)) {
            $nextAttempts = $attempts + 1;
            if ($nextAttempts >= guest_otp_max_attempts()) {
                $pdo->prepare('UPDATE otp_codes SET attempts = ?, consumed_at = NOW() WHERE id = ?')->execute([$nextAttempts, $otpId]);
            } else {
                $pdo->prepare('UPDATE otp_codes SET attempts = ? WHERE id = ?')->execute([$nextAttempts, $otpId]);
            }

            return [
                'ok' => false,
                'error' => 'wrong_code',
                'attempts_left' => max(0, guest_otp_max_attempts() - $nextAttempts),
            ];
        }

        $pdo->prepare('UPDATE otp_codes SET consumed_at = NOW() WHERE id = ?')->execute([$otpId]);
        $pdo->prepare('
            UPDATE otp_codes
            SET consumed_at = NOW()
            WHERE restaurant_id = ?
              AND phone = ?
              AND consumed_at IS NULL
              AND id <> ?
        ')->execute([$restaurantId, $phoneNorm, $otpId]);

        return ['ok' => true];
    }
}
