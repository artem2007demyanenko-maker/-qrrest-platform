<?php

require_once __DIR__ . '/db.php';

if (!defined('STATS_CACHE_VERSION')) {
    define('STATS_CACHE_VERSION', 1);
}

/**
 * Сформировать ключ кэша для stats-функции.
 * Гарантия: длина ключа <= 128 символов (normalizedName до 32 + ':' + sha256 hex 64).
 * Коллизии между функциями исключены: в payload входят name и params.
 *
 * @param int    $userId
 * @param string $scopeHash
 * @param string $name
 * @param array  $params
 */
function stats_cache_make_key(int $userId, string $scopeHash, string $name, array $params): string
{
    $normalizedName = strtolower(preg_replace('/[^a-z0-9_]/', '_', $name));
    if ($normalizedName === '') {
        $normalizedName = 'stats';
    }
    $normalizedName = substr($normalizedName, 0, 32);

    $payload = [
        'u' => $userId,
        's' => $scopeHash,
        'n' => $normalizedName,
        'p' => $params,
        'v' => STATS_CACHE_VERSION,
    ];

    $raw  = json_encode($payload, JSON_UNESCAPED_UNICODE);
    $hash = hash('sha256', (string)$raw);
    $key  = $normalizedName . ':' . $hash;
    return substr($key, 0, 128);
}

/**
 * Упаковать payload в стабильный формат хранения.
 * При ошибке json_encode возвращает пустую строку (set не запишет).
 * @param mixed $payload
 */
function stats_cache_envelope_payload($payload): string
{
    $envelope = [
        '__v'    => STATS_CACHE_VERSION,
        '__type' => 'payload',
        'data'   => $payload,
    ];
    $json = json_encode($envelope, JSON_UNESCAPED_UNICODE);
    if ($json === false || json_last_error() !== JSON_ERROR_NONE) {
        return '';
    }
    return $json;
}

/**
 * Упаковать placeholder для lock.
 */
function stats_cache_envelope_placeholder(): string
{
    $envelope = [
        '__v'    => 0,
        '__type' => 'placeholder',
        'data'   => null,
    ];
    return json_encode($envelope, JSON_UNESCAPED_UNICODE);
}

/**
 * Получить значение из кэша по ключу.
 * Возвращает null (miss) для placeholder, невалидных записей, устаревшей версии или активного lock.
 *
 * @return array{payload:mixed,created_at:string,expires_at:string,version:int}|null
 */
function stats_cache_get(string $key)
{
    $pdo = db();

    try {
        $sql = "
            SELECT payload_json, created_at, expires_at, locked_until
            FROM stats_cache
            WHERE cache_key = :key
              AND expires_at > UTC_TIMESTAMP()
            LIMIT 1
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['key' => $key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        // Активный lock — не отдаём как hit (может быть placeholder)
        $lockedUntil = $row['locked_until'] ?? null;
        if ($lockedUntil !== null && $lockedUntil !== '') {
            $lockTs = strtotime($lockedUntil);
            if ($lockTs !== false && $lockTs > time()) {
                return null;
            }
        }

        $decoded = json_decode($row['payload_json'], true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        // Строгий формат envelope: { "__v": int, "__type": "payload"|"placeholder", "data": any }
        if (is_array($decoded) && isset($decoded['__type']) && array_key_exists('__v', $decoded)) {
            $t = $decoded['__type'];
            if ($t === 'placeholder') {
                return null;
            }
            if ($t === 'payload') {
                $version = (int)$decoded['__v'];
                if ($version !== STATS_CACHE_VERSION) {
                    return null;
                }
                $data = array_key_exists('data', $decoded) ? $decoded['data'] : null;
                // JSON null / отсутствующий data не считаем hit (как и placeholder)
                if ($data === null) {
                    return null;
                }
                return [
                    'payload'    => $data,
                    'created_at' => (string)$row['created_at'],
                    'expires_at' => (string)$row['expires_at'],
                    'version'    => $version,
                ];
            }
            return null;
        }

        // Legacy: запись без __type — payload валиден только если не null (null = старый placeholder → MISS)
        if ($decoded === null) {
            return null;
        }
        return [
            'payload'    => $decoded,
            'created_at' => (string)$row['created_at'],
            'expires_at' => (string)$row['expires_at'],
            'version'    => 0,
        ];
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Сохранить значение в кэш. Всегда пишет __type:payload и data (включая null).
 */
function stats_cache_set(string $key, int $userId, string $scopeHash, int $ttlSeconds, $payload): void
{
    $pdo = db();

    try {
        $envelope = stats_cache_envelope_payload($payload);
        if (json_last_error() !== JSON_ERROR_NONE || $envelope === false || $envelope === '') {
            return;
        }
        $envelope = (string)$envelope;
        if (strlen($envelope) > 1024 * 1024) {
            return;
        }

        $ttl = max(1, (int)$ttlSeconds);

        $sql = "
            INSERT INTO stats_cache (cache_key, user_id, scope_hash, created_at, expires_at, payload_json, locked_until)
            VALUES (
                :key,
                :user_id,
                :scope_hash,
                UTC_TIMESTAMP(),
                DATE_ADD(UTC_TIMESTAMP(), INTERVAL :ttl SECOND),
                :payload_json,
                NULL
            )
            ON DUPLICATE KEY UPDATE
                user_id      = VALUES(user_id),
                scope_hash   = VALUES(scope_hash),
                created_at   = UTC_TIMESTAMP(),
                expires_at   = DATE_ADD(UTC_TIMESTAMP(), INTERVAL :ttl SECOND),
                payload_json = VALUES(payload_json),
                locked_until = NULL
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'key'          => $key,
            'user_id'      => $userId,
            'scope_hash'   => $scopeHash,
            'ttl'          => $ttl,
            'payload_json' => $envelope,
        ]);
    } catch (Throwable $e) {
        // игнорим ошибки кэша
    }
}

/**
 * Очистить просроченные записи кэша (лениво).
 * Не удаляет записи с активным lock.
 */
function stats_cache_cleanup_expired(): void
{
    $pdo = db();

    try {
        $stmt = $pdo->prepare("
            DELETE FROM stats_cache
            WHERE expires_at <= UTC_TIMESTAMP()
              AND (locked_until IS NULL OR locked_until <= UTC_TIMESTAMP())
            ORDER BY expires_at ASC
            LIMIT 200
        ");
        $stmt->execute();
    } catch (Throwable $e) {
        // игнорим ошибки кэша
    }
}

/**
 * Попытаться взять lock на cache_key. Placeholder в формате __type:placeholder.
 */
function stats_cache_acquire_lock(string $key, int $lockSeconds = 10): bool
{
    $pdo = db();

    try {
        $placeholderJson = stats_cache_envelope_placeholder();

        $insertSql = "
            INSERT IGNORE INTO stats_cache (cache_key, user_id, scope_hash, created_at, expires_at, payload_json, locked_until)
            VALUES (
                :key,
                0,
                '',
                UTC_TIMESTAMP(),
                UTC_TIMESTAMP(),
                :payload_json,
                DATE_ADD(UTC_TIMESTAMP(), INTERVAL :lock_sec SECOND)
            )
        ";
        $stmt = $pdo->prepare($insertSql);
        $stmt->execute([
            'key'         => $key,
            'payload_json'=> $placeholderJson,
            'lock_sec'    => max(1, (int)$lockSeconds),
        ]);

        if ($stmt->rowCount() > 0) {
            return true;
        }

        // Разрешить overwrite при истёкшем expires_at (locked_until не может зависнуть навсегда)
        $updateSql = "
            UPDATE stats_cache
            SET locked_until = DATE_ADD(UTC_TIMESTAMP(), INTERVAL :lock_sec SECOND),
                payload_json = :payload_json
            WHERE cache_key = :key
              AND (
                  locked_until IS NULL
                  OR locked_until < UTC_TIMESTAMP()
                  OR expires_at <= UTC_TIMESTAMP()
              )
        ";
        $stmt = $pdo->prepare($updateSql);
        $stmt->execute([
            'key'         => $key,
            'payload_json'=> $placeholderJson,
            'lock_sec'    => max(1, (int)$lockSeconds),
        ]);

        return $stmt->rowCount() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Ждать появления валидного значения в кэше (anti-stampede).
 * Максимум maxWaitMs (600), 3–4 попытки (шаг stepMs 150). Без бесконечного цикла.
 *
 * @return array{payload:mixed,created_at:string,expires_at:string,version:int}|null
 */
function stats_cache_wait_for_value(string $key, int $maxWaitMs = 600, int $stepMs = 150)
{
    $maxWaitMs = min(600, max(0, (int)$maxWaitMs));
    $stepMs    = min(200, max(50, (int)$stepMs));
    $deadline  = (int)(microtime(true) * 1000) + $maxWaitMs;

    while ((int)(microtime(true) * 1000) < $deadline) {
        $meta = stats_cache_get($key);
        if ($meta !== null) {
            return $meta;
        }
        usleep($stepMs * 1000);
    }

    return null;
}

/**
 * Удалить все записи кэша для пользователя и scope.
 * При большом объёме (>5000) удаление батчами по 1000.
 *
 * @return int количество удалённых строк
 */
function stats_cache_purge_scope(int $userId, string $scopeHash): int
{
    $pdo = db();

    try {
        $total = 0;
        $batchSize = 1000;
        while (true) {
            $stmt = $pdo->prepare("
                DELETE FROM stats_cache
                WHERE user_id = :uid AND scope_hash = :scope_hash
                LIMIT " . (int)$batchSize . "
            ");
            $stmt->execute([
                'uid'        => $userId,
                'scope_hash' => $scopeHash,
            ]);
            $n = $stmt->rowCount();
            $total += $n;
            if ($n < $batchSize) {
                break;
            }
        }
        return $total;
    } catch (Throwable $e) {
        return 0;
    }
}
