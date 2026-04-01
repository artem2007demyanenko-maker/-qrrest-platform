<?php

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/stats.php';

/**
 * Сохранение/обновление алертов для владельца по конкретному scope.
 *
 * @param int    $userId
 * @param string $scopeHash
 * @param string $scopeLabel
 * @param string $rangeNorm
 * @param string|null $startMsk
 * @param string|null $endMsk
 * @param array<int,array{
 *     key:string,
 *     severity:string,
 *     title:string,
 *     message:string,
 *     meta:array,
 *     actions?:array<int,array{label:string,url:string}>
 * }> $alerts
 */
function owner_alerts_upsert_from_generated(
    int $userId,
    string $scopeHash,
    string $scopeLabel,
    string $rangeNorm,
    ?string $startMsk,
    ?string $endMsk,
    array $alerts
): void {
    if (empty($alerts)) {
        return;
    }
    if (function_exists('db_table_exists') && !db_table_exists('owner_alerts_log')) {
        return;
    }

    $pdo = db();
    $startMskNorm = ($startMsk === '' || $startMsk === null) ? null : $startMsk;
    $endMskNorm   = ($endMsk === '' || $endMsk === null) ? null : $endMsk;

    $selectSql = "
        SELECT
            severity,
            title,
            message,
            meta_json,
            actions_json,
            last_seen_at
        FROM owner_alerts_log
        WHERE user_id = :uid AND scope_hash = :scope_hash AND alert_key = :alert_key
        LIMIT 1
    ";
    $selectStmt = $pdo->prepare($selectSql);

    $insertSql = "
        INSERT INTO owner_alerts_log (
            user_id,
            scope_hash,
            scope_label,
            range_norm,
            period_start_msk,
            period_end_msk,
            alert_key,
            severity,
            title,
            message,
            meta_json,
            actions_json,
            first_seen_at,
            last_seen_at,
            seen_count
        ) VALUES (
            :user_id,
            :scope_hash,
            :scope_label,
            :range_norm,
            :start_msk,
            :end_msk,
            :alert_key,
            :severity,
            :title,
            :message,
            :meta_json,
            :actions_json,
            UTC_TIMESTAMP(),
            UTC_TIMESTAMP(),
            1
        )
    ";
    $insertStmt = $pdo->prepare($insertSql);

    $updateSql = "
        UPDATE owner_alerts_log
        SET
            severity   = :severity,
            title      = :title,
            message    = :message,
            meta_json  = :meta_json,
            actions_json = :actions_json,
            last_seen_at = UTC_TIMESTAMP(),
            seen_count   = LEAST(seen_count + 1, 2147483647),
            scope_label  = :scope_label,
            range_norm   = :range_norm,
            period_start_msk = :start_msk,
            period_end_msk   = :end_msk
        WHERE user_id = :uid AND scope_hash = :scope_hash AND alert_key = :alert_key
    ";
    $updateStmt = $pdo->prepare($updateSql);

    foreach ($alerts as $alert) {
        $meta    = $alert['meta'] ?? [];
        $actions = $alert['actions'] ?? [];

        $metaJson    = json_encode($meta, JSON_UNESCAPED_UNICODE);
        $actionsJson = json_encode($actions, JSON_UNESCAPED_UNICODE);

        // Пытаемся найти существующую запись
        $selectStmt->execute([
            'uid'        => $userId,
            'scope_hash' => $scopeHash,
            'alert_key'  => (string)$alert['key'],
        ]);
        $existing = $selectStmt->fetch(PDO::FETCH_ASSOC);

        if (!$existing) {
            try {
                $insertStmt->execute([
                    'user_id'      => $userId,
                    'scope_hash'   => $scopeHash,
                    'scope_label'  => $scopeLabel,
                    'range_norm'   => $rangeNorm,
                    'start_msk'    => $startMskNorm,
                    'end_msk'      => $endMskNorm,
                    'alert_key'    => (string)$alert['key'],
                    'severity'     => (string)$alert['severity'],
                    'title'        => (string)$alert['title'],
                    'message'      => (string)$alert['message'],
                    'meta_json'    => $metaJson,
                    'actions_json' => $actionsJson,
                ]);
                continue;
            } catch (PDOException $e) {
                $isDuplicate = ($e->getCode() === '23000'
                    || strpos((string)$e->getMessage(), 'Duplicate') !== false);
                if (!$isDuplicate) {
                    throw $e;
                }
                $selectStmt->execute([
                    'uid'        => $userId,
                    'scope_hash' => $scopeHash,
                    'alert_key'  => (string)$alert['key'],
                ]);
                $existing = $selectStmt->fetch(PDO::FETCH_ASSOC);
                if (!$existing) {
                    throw $e;
                }
            }
        }

        // Сравниваем fingerprint: severity/title/message/meta_json/actions_json
        $samePayload = (
            (string)$existing['severity']     === (string)$alert['severity'] &&
            (string)$existing['title']        === (string)$alert['title'] &&
            (string)$existing['message']      === (string)$alert['message'] &&
            (string)($existing['meta_json'] ?? '')    === (string)$metaJson &&
            (string)($existing['actions_json'] ?? '') === (string)$actionsJson
        );

        $shouldTouch = true;

        if ($samePayload && !empty($existing['last_seen_at'])) {
            $lastSeenTs = strtotime($existing['last_seen_at']);
            if ($lastSeenTs !== false) {
                // если прошло меньше 15 минут и полезная нагрузка не изменилась — не обновляем
                if ($lastSeenTs >= (time() - 15 * 60)) {
                    $shouldTouch = false;
                }
            }
        }

        if (!$shouldTouch) {
            continue;
        }

        // Обновляем только информационные поля и счётчик/last_seen_at, не трогая status/ack/snooze
        $updateStmt->execute([
            'severity'     => (string)$alert['severity'],
            'title'        => (string)$alert['title'],
            'message'      => (string)$alert['message'],
            'meta_json'    => $metaJson,
            'actions_json' => $actionsJson,
            'scope_label'  => $scopeLabel,
            'range_norm'   => $rangeNorm,
            'start_msk'    => $startMskNorm,
            'end_msk'      => $endMskNorm,
            'uid'          => $userId,
            'scope_hash'   => $scopeHash,
            'alert_key'    => (string)$alert['key'],
        ]);
    }
}

/**
 * Пометить алерты, которые больше не генерируются для этого scope, как resolved.
 *
 * @param int    $userId
 * @param string $scopeHash
 * @param string[] $generatedAlertKeys
 */
function owner_alerts_resolve_missing(int $userId, string $scopeHash, array $generatedAlertKeys): void
{
    if (function_exists('db_table_exists') && !db_table_exists('owner_alerts_log')) {
        return;
    }
    $pdo = db();

    // Если нет сгенерированных ключей — резолвим все open для scope
    if (empty($generatedAlertKeys)) {
        $sql = "
            UPDATE owner_alerts_log
            SET status = 'resolved',
                last_seen_at = UTC_TIMESTAMP()
            WHERE user_id = :uid
              AND scope_hash = :scope_hash
              AND status = 'open'
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'uid'        => $userId,
            'scope_hash' => $scopeHash,
        ]);
        return;
    }

    // Резолвим open-алерты, которых нет в текущем списке ключей
    $placeholders = implode(',', array_fill(0, count($generatedAlertKeys), '?'));

    $sql = "
        UPDATE owner_alerts_log
        SET status = 'resolved',
            last_seen_at = UTC_TIMESTAMP()
        WHERE user_id = ?
          AND scope_hash = ?
          AND status = 'open'
          AND alert_key NOT IN ($placeholders)
    ";

    $params = array_merge(
        [$userId, $scopeHash],
        array_map('strval', $generatedAlertKeys)
    );

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
}

/**
 * Получить алерты из лога для scope.
 *
 * @param int    $userId
 * @param string $scopeHash
 * @param bool   $includeHidden
 * @return array<int,array<string,mixed>>
 */
function owner_alerts_get_for_scope(int $userId, string $scopeHash, bool $includeHidden): array
{
    if (function_exists('db_table_exists') && !db_table_exists('owner_alerts_log')) {
        return [];
    }
    $pdo = db();

    // Авто-переход snoozed → open при истекшем snooze_until
    $pdo->prepare("
        UPDATE owner_alerts_log
        SET status = 'open', snooze_until = NULL
        WHERE user_id = :uid AND scope_hash = :scope_hash
          AND status = 'snoozed'
          AND snooze_until IS NOT NULL
          AND snooze_until <= UTC_TIMESTAMP()
    ")->execute(['uid' => $userId, 'scope_hash' => $scopeHash]);

    $where = "user_id = :uid AND scope_hash = :scope_hash";

    if (!$includeHidden) {
        // показываем только открытые и просроченные snoozed
        $where .= " AND (
            status = 'open'
            OR (status = 'snoozed' AND (snooze_until IS NULL OR snooze_until <= UTC_TIMESTAMP()))
        )";
    }

    $sql = "
        SELECT
            alert_key,
            severity,
            title,
            message,
            meta_json,
            actions_json,
            status,
            ack_at,
            snooze_until,
            first_seen_at,
            last_seen_at,
            seen_count
        FROM owner_alerts_log
        WHERE {$where}
        ORDER BY
            CASE severity
                WHEN 'critical' THEN 3
                WHEN 'warning' THEN 2
                ELSE 1
            END DESC,
            last_seen_at DESC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'uid'        => $userId,
        'scope_hash' => $scopeHash,
    ]);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $result = [];
    foreach ($rows as $row) {
        $meta    = [];
        $actions = [];

        if (!empty($row['meta_json'])) {
            $decoded = json_decode($row['meta_json'], true);
            if (is_array($decoded)) {
                $meta = $decoded;
            }
        }
        if (!empty($row['actions_json'])) {
            $decoded = json_decode($row['actions_json'], true);
            if (is_array($decoded)) {
                $actions = $decoded;
            }
        }

        $result[] = [
            'key'          => (string)$row['alert_key'],
            'severity'     => (string)$row['severity'],
            'title'        => (string)$row['title'],
            'message'      => (string)$row['message'],
            'meta'         => $meta,
            'actions'      => $actions,
            'status'       => (string)$row['status'],
            'ack_at'       => $row['ack_at'],
            'snooze_until' => $row['snooze_until'],
            'first_seen_at'=> $row['first_seen_at'],
            'last_seen_at' => $row['last_seen_at'],
            'seen_count'   => (int)$row['seen_count'],
        ];
    }

    return $result;
}

/**
 * Обновить статус алерта (ack/snooze/reset).
 *
 * @param int    $userId
 * @param string $scopeHash
 * @param string $alertKey
 * @param string $action 'ack'|'snooze_24h'|'snooze_7d'|'reset'
 */
function owner_alerts_set_status(int $userId, string $scopeHash, string $alertKey, string $action): void
{
    if (function_exists('db_table_exists') && !db_table_exists('owner_alerts_log')) {
        return;
    }
    $pdo = db();

    if ($alertKey === '') {
        return;
    }

    if ($action === 'ack') {
        $sql = "
            UPDATE owner_alerts_log
            SET status = 'ack',
                ack_at = UTC_TIMESTAMP(),
                snooze_until = NULL
            WHERE user_id = :uid AND scope_hash = :scope_hash AND alert_key = :alert_key
        ";
    } elseif ($action === 'snooze_24h') {
        $sql = "
            UPDATE owner_alerts_log
            SET status = 'snoozed',
                snooze_until = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 24 HOUR)
            WHERE user_id = :uid AND scope_hash = :scope_hash AND alert_key = :alert_key
        ";
    } elseif ($action === 'snooze_7d') {
        $sql = "
            UPDATE owner_alerts_log
            SET status = 'snoozed',
                snooze_until = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 7 DAY)
            WHERE user_id = :uid AND scope_hash = :scope_hash AND alert_key = :alert_key
        ";
    } elseif ($action === 'reset') {
        $sql = "
            UPDATE owner_alerts_log
            SET status = 'open',
                ack_at = NULL,
                snooze_until = NULL
            WHERE user_id = :uid AND scope_hash = :scope_hash AND alert_key = :alert_key
        ";
    } else {
        return;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'uid'        => $userId,
        'scope_hash' => $scopeHash,
        'alert_key'  => $alertKey,
    ]);
}

