<?php

/**
 * Centralized error log: error_log + app_error_logs table. No fatal if DB/table missing.
 */
function app_error_log(string $source, string $message, array $context = [], string $level = 'error', ?string $rid = null): void
{
    if (($rid === null || $rid === '') && function_exists('app_rid')) {
        $rid = app_rid();
    }
    $line = '[' . $level . '] ' . $source . ' ' . $message . ($rid ? ' rid=' . $rid : '');
    error_log($line);
    if (!empty($context)) {
        error_log('context: ' . json_encode($context, JSON_UNESCAPED_UNICODE));
    }
    try {
        if (!function_exists('db')) {
            require_once __DIR__ . '/db.php';
        }
        $pdo = db();
        $stmt = $pdo->prepare("
            INSERT INTO app_error_logs (level, source, message, context_json, rid)
            VALUES (:level, :source, :message, :context_json, :rid)
        ");
        $stmt->execute([
            'level' => substr($level, 0, 32),
            'source' => substr($source, 0, 64),
            'message' => $message,
            'context_json' => $context !== [] ? json_encode($context, JSON_UNESCAPED_UNICODE) : null,
            'rid' => $rid !== null && $rid !== '' ? substr($rid, 0, 32) : null,
        ]);
    } catch (Throwable $e) {
        error_log('app_error_log DB write failed: ' . $e->getMessage());
    }
}

/**
 * Write a line to storage/logs/app.log (and error_log). Safe if dir missing.
 */
function app_log(string $message, string $level = 'info'): void
{
    $line = gmdate('Y-m-d\TH:i:s\Z') . ' [' . $level . '] ' . $message . "\n";
    error_log(trim($line));
    $base = dirname(__DIR__) . '/storage/logs';
    if (!is_dir($base)) {
        @mkdir($base, 0775, true);
    }
    $file = $base . '/app.log';
    if (is_dir($base) && (is_file($file) ? is_writable($file) : is_writable($base))) {
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }
}

if (!function_exists('add_log')) {

    function add_log(PDO $pdo, array $data): void
    {
        try {
            $fields = [
                'user_id'       => null,
                'restaurant_id' => null,
                'level'         => 'info',
                'action'        => '',
                'message'       => '',
                'ip_address'    => null,
                'user_agent'    => null,
            ];

            foreach ($fields as $key => $default) {
                if (array_key_exists($key, $data)) {
                    $fields[$key] = $data[$key];
                }
            }
            if ($fields['ip_address'] === null && isset($_SERVER['REMOTE_ADDR'])) {
                $fields['ip_address'] = $_SERVER['REMOTE_ADDR'];
            }
            if ($fields['user_agent'] === null && isset($_SERVER['HTTP_USER_AGENT'])) {
                $fields['user_agent'] = $_SERVER['HTTP_USER_AGENT'];
            }

            // подстраховка по типам
            $fields['user_id']       = $fields['user_id'] !== null ? (int)$fields['user_id'] : null;
            $fields['restaurant_id'] = $fields['restaurant_id'] !== null ? (int)$fields['restaurant_id'] : null;
            $fields['level']         = (string)($fields['level'] ?: 'info');
            $fields['action']        = (string)($fields['action'] ?: '');
            $fields['message']       = (string)($fields['message'] ?: '');
            $fields['ip_address']    = $fields['ip_address'] !== null ? (string)$fields['ip_address'] : null;
            $fields['user_agent']    = $fields['user_agent'] !== null ? (string)$fields['user_agent'] : null;

            $sql = "
                INSERT INTO logs (user_id, restaurant_id, level, action, message, ip_address, user_agent, created_at)
                VALUES (:user_id, :restaurant_id, :level, :action, :message, :ip_address, :user_agent, NOW())
            ";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':user_id'       => $fields['user_id'],
                ':restaurant_id' => $fields['restaurant_id'],
                ':level'         => $fields['level'],
                ':action'        => $fields['action'],
                ':message'       => $fields['message'],
                ':ip_address'    => $fields['ip_address'],
                ':user_agent'    => $fields['user_agent'],
            ]);
        } catch (Throwable $e) {

        }
    }
}

if (!function_exists('app_event')) {
    /**
     * Simple, safe event logging.
     * - Writes to error_log + storage/logs/app.log (via app_log)
     * - Never throws to the caller.
     */
    function app_event(string $event, array $context = []): void
    {
        try {
            $rid = function_exists('app_rid') ? app_rid() : null;
            $payload = [
                'event' => $event,
                'context' => $context,
            ];
            if ($rid !== null && $rid !== '') {
                $payload['rid'] = $rid;
            }
            app_log('EVENT ' . $event . ' ' . json_encode($payload, JSON_UNESCAPED_UNICODE));

            // Lightweight bridge aggregation (Redis only; no new tables).
            // Track only the few high-signal events requested.
            $tracked = ['order_created', 'upsell_accepted', 'crm_created'];
            if (in_array($event, $tracked, true)) {
                try {
                    if (!function_exists('cache_redis_client')) {
                        require_once __DIR__ . '/cache.php';
                    }
                    $redis = cache_redis_client();
                    if ($redis !== null) {
                        $cntKey = 'bridge:events_count:' . $event;
                        $lastKey = 'bridge:last_event_time:' . $event;
                        $redis->incr($cntKey);
                        $redis->set($lastKey, (string)time());
                    }
                } catch (Throwable $ignore) {
                    // noop
                }
            }
        } catch (Throwable $e) {
            // Never break business logic.
            try {
                error_log('app_event failed: ' . $e->getMessage());
            } catch (Throwable $ignore) {
                // noop
            }
        }
    }
}


if (!function_exists('app_event_bridge_snapshot')) {
    /**
     * Lightweight analytics bridge snapshot from Redis counters.
     * @return array<string,mixed>
     */
    function app_event_bridge_snapshot(): array
    {
        $tracked = ['order_created', 'upsell_accepted', 'crm_created'];
        $out = ['events_count' => [], 'last_event_time' => []];
        try {
            if (!function_exists('cache_redis_client')) {
                require_once __DIR__ . '/cache.php';
            }
            $redis = cache_redis_client();
            if ($redis === null) {
                foreach ($tracked as $ev) {
                    $out['events_count'][$ev] = 0;
                    $out['last_event_time'][$ev] = null;
                }
                return $out;
            }
            foreach ($tracked as $ev) {
                $cnt = $redis->get('bridge:events_count:' . $ev);
                $last = $redis->get('bridge:last_event_time:' . $ev);
                $out['events_count'][$ev] = $cnt !== false ? (int)$cnt : 0;
                $out['last_event_time'][$ev] = $last !== false ? (int)$last : null;
            }
        } catch (Throwable $e) {
            foreach ($tracked as $ev) {
                if (!isset($out['events_count'][$ev])) $out['events_count'][$ev] = 0;
                if (!isset($out['last_event_time'][$ev])) $out['last_event_time'][$ev] = null;
            }
        }
        return $out;
    }
}
