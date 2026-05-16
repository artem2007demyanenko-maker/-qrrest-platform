<?php
/**
 * Security utilities (Variant 7): rate limiting, IP/UA hashing.
 * Rate limit: Redis if available, else MySQL security_rate_limits.
 */

require_once __DIR__ . '/db.php';

/** Returns SHA256 hex of IP (or empty string). */
function security_hash_ip(?string $ip): string
{
    if ($ip === null || $ip === '') {
        return '';
    }
    return hash('sha256', $ip);
}

/** Returns SHA256 hex of User-Agent (or empty string), truncated to 512 chars before hash. */
function security_hash_ua(?string $ua): string
{
    if ($ua === null || $ua === '') {
        return '';
    }
    return hash('sha256', substr($ua, 0, 512));
}

/**
 * Session-based rate limit fallback when security_rate_limits table is missing (e.g. production).
 * Uses $_SESSION['_rl'][$key] = array of timestamps in current window; enforces $limit per $window_sec.
 */
function security_rate_limit_session_fallback(string $key, int $limit, int $window_sec): bool
{
    if (session_status() === PHP_SESSION_NONE && function_exists('auth_start_session')) {
        auth_start_session();
    }
    $now = time();
    $windowStart = $now - ($now % $window_sec);
    $k = substr($key, 0, 64);
    if (!isset($_SESSION['_rl'][$k]) || !is_array($_SESSION['_rl'][$k])) {
        $_SESSION['_rl'][$k] = [];
    }
    $_SESSION['_rl'][$k][] = $now;
    $_SESSION['_rl'][$k] = array_values(array_filter($_SESSION['_rl'][$k], function ($t) use ($windowStart, $window_sec) {
        return $t >= $windowStart && $t < $windowStart + $window_sec;
    }));
    if (count($_SESSION['_rl'][$k]) > $limit) {
        security_rate_limit_deny($key);
    }
    return true;
}

/**
 * Rate limit check. Returns true if allowed, false if over limit.
 * On over limit: sends HTTP 429, sleep(0.3–0.8s), exits.
 * @param string $key e.g. "login:".$ipHash or "referral_click:".$ipHash
 * @param int $limit max hits per window
 * @param int $window_sec window in seconds
 */
function security_rate_limit(string $key, int $limit, int $window_sec): bool
{
    $now = time();
    $windowStart = $now - ($now % $window_sec);
    $keyHash = hash('sha256', $key);

    $redis = null;
    $config = @require __DIR__ . '/config.php';
    if (!empty($config['redis']['host']) && class_exists('Redis')) {
        try {
            $redis = new Redis();
            $connected = @$redis->connect(
                $config['redis']['host'],
                $config['redis']['port'] ?? 6379,
                1.0
            );
            if (!$connected) {
                $redis = null;
            }
        } catch (Throwable $e) {
            $redis = null;
        }
    }

    if ($redis !== null) {
        $redisKey = 'rl:' . hash('sha256', $key . '|' . $windowStart);
        $count = $redis->incr($redisKey);
        if ($count === 1) {
            $redis->expire($redisKey, $window_sec + 60);
        }
        if ($count > $limit) {
            security_rate_limit_deny($key);
        }
        return true;
    }

    if (!function_exists('schema_guard_rate_limit_ready')) {
        require_once __DIR__ . '/schema_guard.php';
    }
    if (!schema_guard_rate_limit_ready()) {
        $appEnv = getenv('APP_ENV') ?: (isset($config['app']['env']) ? $config['app']['env'] : 'dev');
        static $schemaMissingRlLogged = false;
        if (!$schemaMissingRlLogged) {
            $schemaMissingRlLogged = true;
            if (session_status() === PHP_SESSION_NONE && function_exists('auth_start_session')) {
                auth_start_session();
            }
            if (!isset($_SESSION) || !is_array($_SESSION)) {
                $_SESSION = [];
            }
            if (!isset($_SESSION['_schema_missing_log']) || !is_array($_SESSION['_schema_missing_log'])) {
                $_SESSION['_schema_missing_log'] = [];
            }
            $now = time();
            $ttl = 3600;
            $lastTs = (int)($_SESSION['_schema_missing_log']['security_rate_limits'] ?? 0);
            $canLog = ($lastTs <= 0 || ($now - $lastTs) >= $ttl);
            if ($canLog) {
                $_SESSION['_schema_missing_log']['security_rate_limits'] = $now;
            }
            if ($canLog) {
            error_log('SCHEMA_MISSING security_rate_limits uri=' . ($_SERVER['REQUEST_URI'] ?? '') . ' env=' . $appEnv . ' rate_limit_fallback');
            }
        }
        if ($appEnv === 'production') {
            return security_rate_limit_session_fallback($key, $limit, $window_sec);
        }
        return true;
    }

    $pdo = db();
    try {
        $updated = gmdate('Y-m-d H:i:s');
        $ins = $pdo->prepare("
            INSERT INTO security_rate_limits (key_hash, hits, window_start, updated_at)
            VALUES (:kh, 1, :ws, :upd)
            ON DUPLICATE KEY UPDATE
                hits = IF(window_start = VALUES(window_start), hits + 1, 1),
                window_start = VALUES(window_start),
                updated_at = VALUES(updated_at)
        ");
        $ins->execute(['kh' => $keyHash, 'ws' => $windowStart, 'upd' => $updated]);
        $sel = $pdo->prepare("SELECT hits, window_start FROM security_rate_limits WHERE key_hash = :kh LIMIT 1");
        $sel->execute(['kh' => $keyHash]);
        $row = $sel->fetch(PDO::FETCH_ASSOC);
        $hits = $row && (int)$row['window_start'] === $windowStart ? (int)$row['hits'] : 1;
        if ($hits > $limit) {
            security_rate_limit_deny($key);
        }
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('SECURITY_RATE_LIMIT_ERROR ' . $e->getMessage());
        return true;
    }
}

function security_rate_limit_deny(string $key = ''): void
{
    if (function_exists('session_write_close')) {
        @session_write_close();
    }
    if (function_exists('abuse_signal') && $key !== '') {
        require_once __DIR__ . '/audit.php';
        abuse_signal(null, 'rate_limit_triggered', 1, ['key_prefix' => substr($key, 0, 64)]);
    }
    $sleep = 0.3 + (mt_rand(1, 50) / 100.0);
    usleep((int)($sleep * 1000000));
    if (!headers_sent()) {
        http_response_code(429);
        header('Retry-After: 60');
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['error' => 'Too Many Requests', 'retry_after' => 60]);
    exit;
}

/**
 * Optional IP allow-list for project-admin area.
 * ADMIN_ALLOWED_IPS env var: comma-separated list (e.g. "1.2.3.4,5.6.7.8").
 * If empty, no restriction is applied.
 */
if (!function_exists('admin_ip_guard')) {
    function admin_ip_guard(): void
    {
        $allowed = getenv('ADMIN_ALLOWED_IPS') ?: '';
        if ($allowed === '') {
            return;
        }
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        if ($ip === '') {
            http_response_code(403);
            echo 'Forbidden';
            exit;
        }
        $list = array_filter(array_map('trim', explode(',', $allowed)), static function ($v) {
            return $v !== '';
        });
        if ($list === []) {
            return;
        }
        if (!in_array($ip, $list, true)) {
            http_response_code(403);
            echo 'Forbidden';
            exit;
        }
    }
}
