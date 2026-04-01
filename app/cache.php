<?php

/**
 * Lightweight Redis-backed cache helper.
 *
 * Uses Redis when REDIS_HOST is configured and the Redis extension is available.
 * Otherwise, all operations become no-ops (no caching).
 *
 * Intended for:
 * - analytics cache
 * - dashboard metrics cache
 * - growth engine cache
 *
 * NOT for:
 * - authentication
 * - sessions
 * - billing
 * - order creation
 */

if (!function_exists('cache_redis_client')) {
    /**
     * @return Redis|null
     */
    function cache_redis_client()
    {
        static $client = null;
        static $initialized = false;

        if ($initialized) {
            return $client;
        }
        $initialized = true;

        if (!class_exists('Redis')) {
            return null;
        }

        $config = @require __DIR__ . '/config.php';
        $host = (string)($config['redis']['host'] ?? '');
        $port = (int)($config['redis']['port'] ?? 6379);
        if ($host === '') {
            return null;
        }

        try {
            $r = new Redis();
            $ok = @$r->connect($host, $port, 1.0);
            if (!$ok) {
                return null;
            }
            $client = $r;
        } catch (Throwable $e) {
            $client = null;
        }

        return $client;
    }
}

if (!function_exists('cache_get')) {
    /**
     * @param string $key
     * @return mixed|null
     */
    function cache_get(string $key)
    {
        $redis = cache_redis_client();
        if ($redis === null) {
            return null;
        }
        $val = $redis->get('cache:' . $key);
        if ($val === false) {
            return null;
        }
        $data = json_decode($val, true);
        return $data === null && json_last_error() !== JSON_ERROR_NONE ? null : $data;
    }
}

if (!function_exists('cache_set')) {
    /**
     * @param string $key
     * @param mixed  $value
     * @param int    $ttl   seconds
     * @return void
     */
    function cache_set(string $key, $value, int $ttl): void
    {
        $redis = cache_redis_client();
        if ($redis === null) {
            return;
        }
        $payload = json_encode($value, JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            return;
        }
        if ($ttl > 0) {
            $redis->setex('cache:' . $key, $ttl, $payload);
        } else {
            $redis->set('cache:' . $key, $payload);
        }
    }
}

if (!function_exists('cache_delete')) {
    function cache_delete(string $key): void
    {
        $redis = cache_redis_client();
        if ($redis === null) {
            return;
        }
        $redis->del('cache:' . $key);
    }
}

