<?php
/**
 * CRM/Retention: guests, visits, outbox (stub delivery with safe processing).
 */

if (file_exists(__DIR__ . '/schema_guard.php')) {
    require_once __DIR__ . '/schema_guard.php';
}
if (file_exists(__DIR__ . '/flow_id.php')) {
    require_once __DIR__ . '/flow_id.php';
}

function crm_normalize_phone(string $raw): ?string
{
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }
    $hadPlus = (strpos($raw, '+') === 0);
    $digits = preg_replace('/\D/', '', $raw);
    $len = strlen($digits);
    if ($len < 10 || $len > 15) {
        return null;
    }
    return $hadPlus ? ('+' . $digits) : $digits;
}

function crm_writes_allowed(): bool
{
    return !(function_exists('is_demo_mode') && is_demo_mode());
}

function crm_outbox_table_ready(): bool
{
    return function_exists('db_table_exists') ? db_table_exists('crm_outbox') : true;
}

function crm_guest_lookup(int $restaurantId, int $guestId): ?array
{
    if ($restaurantId <= 0 || $guestId <= 0 || !function_exists('db')) {
        return null;
    }

    $pdo = db();
    $stmt = $pdo->prepare("
        SELECT id, restaurant_id, phone, consent, first_seen_at, last_seen_at, visits_count, created_at, updated_at
        FROM crm_guests
        WHERE restaurant_id = ? AND id = ?
        LIMIT 1
    ");
    $stmt->execute([$restaurantId, $guestId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function crm_order_lookup(int $restaurantId, int $orderId): ?array
{
    if ($restaurantId <= 0 || $orderId <= 0 || !function_exists('db')) {
        return null;
    }

    $pdo = db();
    $amountCol = (function_exists('db_column_exists') && db_column_exists('orders', 'total_amount')) ? 'total_amount' : 'total_price';
    $crmPhoneSelect = (function_exists('db_column_exists') && db_column_exists('orders', 'crm_phone')) ? ', crm_phone' : ", NULL AS crm_phone";
    $crmConsentSelect = (function_exists('db_column_exists') && db_column_exists('orders', 'crm_consent')) ? ', crm_consent' : ", 0 AS crm_consent";
    $customerPhoneSelect = (function_exists('db_column_exists') && db_column_exists('orders', 'customer_phone')) ? ', customer_phone' : ", NULL AS customer_phone";
    $flowIdSelect = (function_exists('db_column_exists') && db_column_exists('orders', 'flow_id')) ? ', flow_id' : ", NULL AS flow_id";
    $stmt = $pdo->prepare("
        SELECT id, restaurant_id, table_id, order_status, payment_status, {$amountCol} AS total_amount
               {$crmPhoneSelect}
               {$crmConsentSelect}
               {$customerPhoneSelect}
               {$flowIdSelect}
        FROM orders
        WHERE restaurant_id = ? AND id = ?
        LIMIT 1
    ");
    $stmt->execute([$restaurantId, $orderId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function crm_log_cron_run(string $jobName, string $status, string $startedAt, array $details = [], ?int $runId = null): ?int
{
    if (!function_exists('db_table_exists') || !db_table_exists('cron_runs') || !function_exists('db')) {
        return null;
    }

    $pdo = db();
    $detailsJson = json_encode($details, JSON_UNESCAPED_UNICODE);

    if ($runId === null) {
        $stmt = $pdo->prepare("
            INSERT INTO cron_runs (job_name, status, started_at, details_json)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$jobName, $status, $startedAt, $detailsJson]);
        return (int)$pdo->lastInsertId();
    }

    $stmt = $pdo->prepare("
        UPDATE cron_runs
        SET status = ?, finished_at = NOW(), details_json = ?
        WHERE id = ?
    ");
    $stmt->execute([$status, $detailsJson, $runId]);
    return $runId;
}

/**
 * Upsert guest by restaurant + phone. If consent=1 sets consent=1.
 * @return array|null guest row or null on invalid phone
 */
function crm_upsert_guest(int $restaurantId, string $phone, bool $consent): ?array
{
    $normalized = crm_normalize_phone($phone);
    if ($normalized === null || !function_exists('db')) {
        return null;
    }
    $pdo = db();

    $startedTx = false;
    try {
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $startedTx = true;
        }

        $stmt = $pdo->prepare("
            SELECT id, restaurant_id, phone, consent, first_seen_at, last_seen_at, visits_count, created_at, updated_at
            FROM crm_guests
            WHERE restaurant_id = ? AND phone = ?
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->execute([$restaurantId, $normalized]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $now = date('Y-m-d H:i:s');
        if ($row) {
            $consentVal = $consent ? 1 : (int)($row['consent'] ?? 0);
            $upd = $pdo->prepare("
                UPDATE crm_guests
                SET consent = ?, updated_at = ?
                WHERE id = ?
            ");
            $upd->execute([$consentVal, $now, (int)$row['id']]);
            $row['consent'] = (string)$consentVal;
            if ($startedTx) {
                $pdo->commit();
            }
            return $row;
        }

        $ins = $pdo->prepare("
            INSERT INTO crm_guests (restaurant_id, phone, consent, first_seen_at, last_seen_at, visits_count)
            VALUES (?, ?, ?, ?, ?, 0)
        ");
        $consentVal = $consent ? 1 : 0;
        $ins->execute([$restaurantId, $normalized, $consentVal, $now, $now]);
        $id = (int)$pdo->lastInsertId();

        if ($startedTx) {
            $pdo->commit();
        }
        return [
            'id' => $id,
            'restaurant_id' => $restaurantId,
            'phone' => $normalized,
            'consent' => (string)$consentVal,
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'visits_count' => '0',
            'created_at' => $now,
            'updated_at' => $now,
        ];
    } catch (Throwable $e) {
        if ($startedTx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (function_exists('error_log')) {
            error_log('crm_upsert_guest rid=' . $restaurantId . ' ' . $e->getMessage());
        }
        return null;
    }
}

function crm_store_order_contact(int $restaurantId, int $orderId, string $phone, bool $consent): bool
{
    if (!crm_writes_allowed() || $restaurantId <= 0 || $orderId <= 0 || !function_exists('db')) {
        return false;
    }

    $phone = crm_normalize_phone($phone) ?? '';
    if ($phone === '') {
        return false;
    }

    $columns = [];
    $params = [':id' => $orderId, ':rid' => $restaurantId];

    if (function_exists('db_column_exists') && db_column_exists('orders', 'crm_phone')) {
        $columns[] = 'crm_phone = :crm_phone';
        $params[':crm_phone'] = $phone;
    }
    if (function_exists('db_column_exists') && db_column_exists('orders', 'crm_consent')) {
        $columns[] = 'crm_consent = :crm_consent';
        $params[':crm_consent'] = $consent ? 1 : 0;
    }
    if (function_exists('db_column_exists') && db_column_exists('orders', 'customer_phone')) {
        $columns[] = 'customer_phone = :customer_phone';
        $params[':customer_phone'] = $phone;
    }
    if (function_exists('db_column_exists') && db_column_exists('orders', 'guest_id')) {
        $guest = crm_upsert_guest($restaurantId, $phone, $consent);
        $guestId = (int)($guest['id'] ?? 0);
        if ($guestId > 0) {
            $columns[] = 'guest_id = :guest_id';
            $params[':guest_id'] = $guestId;
        }
    }

    if ($columns === []) {
        return false;
    }

    $pdo = db();
    $sql = "UPDATE orders SET " . implode(', ', $columns) . " WHERE id = :id AND restaurant_id = :rid";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->rowCount() > 0;
}

/**
 * Record a visit and update guest last_seen_at + visits_count.
 * Returns true only when a new visit row was inserted.
 */
function crm_record_visit(int $restaurantId, int $guestId, ?int $orderId, ?int $tableId, float $totalAmount): bool
{
    if (!crm_writes_allowed() || $restaurantId <= 0 || $guestId <= 0 || !function_exists('db')) {
        return false;
    }

    $pdo = db();
    $guest = crm_guest_lookup($restaurantId, $guestId);
    if (!$guest) {
        return false;
    }

    $startedTx = false;
    try {
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $startedTx = true;
        }

        $visitedAt = date('Y-m-d H:i:s');
        if ($orderId !== null && $orderId > 0) {
            $order = crm_order_lookup($restaurantId, $orderId);
            if (!$order) {
                if ($startedTx) $pdo->rollBack();
                return false;
            }
            if (($order['payment_status'] ?? '') !== 'paid' || ($order['order_status'] ?? '') === 'canceled') {
                if ($startedTx) $pdo->rollBack();
                return false;
            }
            $tableId = $tableId ?: (int)($order['table_id'] ?? 0);
            if ($totalAmount <= 0) {
                $totalAmount = (float)($order['total_amount'] ?? 0);
            }
            $check = $pdo->prepare("SELECT 1 FROM crm_visits WHERE restaurant_id = ? AND order_id = ? LIMIT 1 FOR UPDATE");
            $check->execute([$restaurantId, $orderId]);
            if ($check->fetchColumn()) {
                if ($startedTx) $pdo->rollBack();
                return false;
            }
        }

        $stmt = $pdo->prepare("
            INSERT INTO crm_visits (restaurant_id, guest_id, order_id, table_id, total_amount)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$restaurantId, $guestId, $orderId, $tableId, round(max(0, $totalAmount), 2)]);
        if ($stmt->rowCount() <= 0) {
            if ($startedTx) $pdo->rollBack();
            return false;
        }

        $pdo->prepare("
            UPDATE crm_guests
            SET last_seen_at = ?, visits_count = visits_count + 1
            WHERE id = ? AND restaurant_id = ?
        ")->execute([$visitedAt, $guestId, $restaurantId]);

        if ($startedTx) {
            $pdo->commit();
        }
        return true;
    } catch (Throwable $e) {
        if ($startedTx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (function_exists('error_log')) {
            error_log('crm_record_visit rid=' . $restaurantId . ' gid=' . $guestId . ' oid=' . (int)$orderId . ' ' . $e->getMessage());
        }
        return false;
    }
}

function crm_has_recent_outbox(int $restaurantId, int $guestId, string $template, int $days = 7, ?int $campaignId = null): bool
{
    if ($restaurantId <= 0 || $guestId <= 0 || $template === '' || !function_exists('db') || !crm_outbox_table_ready()) {
        return false;
    }

    $pdo = db();
    $since = date('Y-m-d H:i:s', strtotime('-' . max(1, $days) . ' days'));
    $hasCampaignId = function_exists('db_column_exists') && db_column_exists('crm_outbox', 'campaign_id');
    $hasSentAt = function_exists('db_column_exists') && db_column_exists('crm_outbox', 'processed_at');

    $where = "
        restaurant_id = :rid
        AND guest_id = :gid
        AND template = :template
        AND status IN ('pending', 'processed')
        AND (
            scheduled_at >= :since
    ";
    if ($hasSentAt) {
        $where .= " OR processed_at >= :since";
    }
    $where .= ")";
    if ($hasCampaignId && $campaignId !== null) {
        $where .= " AND campaign_id = :campaign_id";
    }

    $sql = "SELECT 1 FROM crm_outbox WHERE {$where} LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $params = [
        ':rid' => $restaurantId,
        ':gid' => $guestId,
        ':template' => $template,
        ':since' => $since,
    ];
    if ($hasCampaignId && $campaignId !== null) {
        $params[':campaign_id'] = $campaignId;
    }
    $stmt->execute($params);
    return (bool)$stmt->fetchColumn();
}

function crm_build_outbox_dedupe_key(int $restaurantId, int $guestId, string $template, string $scheduledAt, ?int $campaignId = null): string
{
    $parts = [
        $restaurantId,
        $guestId,
        $campaignId ?? 0,
        $template,
        date('Y-m-d', strtotime($scheduledAt)),
    ];
    return hash('sha256', implode('|', $parts));
}

function crm_queue_outbox_message(
    int $restaurantId,
    int $guestId,
    string $channel,
    string $template,
    array $payload,
    string $scheduledAt,
    ?int $campaignId = null,
    int $cooldownDays = 7
): bool {
    if (!crm_writes_allowed() || $restaurantId <= 0 || $guestId <= 0 || $template === '' || !function_exists('db') || !crm_outbox_table_ready()) {
        return false;
    }

    $guest = crm_guest_lookup($restaurantId, $guestId);
    if (!$guest || (int)($guest['consent'] ?? 0) !== 1) {
        return false;
    }

    if (crm_has_recent_outbox($restaurantId, $guestId, $template, $cooldownDays, $campaignId)) {
        return false;
    }

    $pdo = db();
    if (function_exists('app_flow_id_inject_payload_array')) {
        $payload = app_flow_id_inject_payload_array($payload, (int)$restaurantId);
    }
    $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if (!is_string($payloadJson)) {
        return false;
    }

    $fields = ['restaurant_id', 'guest_id', 'channel', 'template', 'payload_json', 'scheduled_at', 'status'];
    $placeholders = [':rid', ':gid', ':channel', ':template', ':payload', ':scheduled_at', "'pending'"];
    $params = [
        ':rid' => $restaurantId,
        ':gid' => $guestId,
        ':channel' => $channel !== '' ? $channel : 'stub',
        ':template' => $template,
        ':payload' => $payloadJson,
        ':scheduled_at' => $scheduledAt,
    ];

    if (function_exists('db_column_exists') && db_column_exists('crm_outbox', 'campaign_id')) {
        $fields[] = 'campaign_id';
        $placeholders[] = ':campaign_id';
        $params[':campaign_id'] = $campaignId;
    }

    if (function_exists('db_column_exists') && db_column_exists('crm_outbox', 'dedupe_key')) {
        $fields[] = 'dedupe_key';
        $placeholders[] = ':dedupe_key';
        $params[':dedupe_key'] = crm_build_outbox_dedupe_key($restaurantId, $guestId, $template, $scheduledAt, $campaignId);
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO crm_outbox (" . implode(', ', $fields) . ")
            VALUES (" . implode(', ', $placeholders) . ")
        ");
        $stmt->execute($params);
        $ok = $stmt->rowCount() > 0;

        // Usage metrics: count actual CRM messages queued (one per outbox row).
        if ($ok && function_exists('increment_usage') && function_exists('is_demo_mode') && !is_demo_mode()) {
            try {
                increment_usage($restaurantId, 'crm_messages');
            } catch (Throwable $eUsage) {
                // never break CRM flow
            }
        }

        return $ok;
    } catch (PDOException $e) {
        if ((string)$e->getCode() === '23000') {
            return false;
        }
        throw $e;
    }
}

/**
 * Schedule a "come back" message in outbox (stub channel).
 */
function crm_schedule_comeback(int $restaurantId, int $guestId, int $days, ?string $flowId = null): bool
{
    if (!crm_writes_allowed()) {
        return false;
    }

    $scheduledAt = date('Y-m-d H:i:s', strtotime('+' . max(0, $days) . ' days'));
    return crm_queue_outbox_message(
        $restaurantId,
        $guestId,
        'stub',
        'come_back_' . max(0, $days) . 'd',
        [
            'text' => "Напоминание: ждём вас снова через {$days} дн.",
            'days' => $days,
            // Used by CRM UI (optional enrichment).
            'reason' => "Напоминание о возвращении через {$days} дней.",
            'suggested_items' => [],
            'flow_id' => ($flowId !== null && $flowId !== '') ? $flowId : null,
        ],
        $scheduledAt,
        null,
        7
    );
}

function crm_update_outbox_status(int $outboxId, string $status, ?string $error = null): void
{
    if ($outboxId <= 0 || !function_exists('db') || !crm_outbox_table_ready()) {
        return;
    }

    $pdo = db();
    $parts = ["status = :status", "updated_at = NOW()", "processed_at = NOW()"];
    $params = [':status' => $status, ':id' => $outboxId];

    // "sent" implies real delivery; this project uses a stub processor.
    // Keep semantics honest: we use "processed" for successful stub handling.
    if ($status === 'sent') {
        $status = 'processed';
        $params[':status'] = $status;
    }
    if ($status === 'failed' && function_exists('db_column_exists') && db_column_exists('crm_outbox', 'failed_at')) {
        $parts[] = "failed_at = NOW()";
    }
    if (function_exists('db_column_exists') && db_column_exists('crm_outbox', 'last_error')) {
        $parts[] = "last_error = :last_error";
        $params[':last_error'] = $error;
    }
    if (!(function_exists('db_column_exists') && db_column_exists('crm_outbox', 'processed_at'))) {
        $parts = array_values(array_filter($parts, static function (string $part): bool {
            return $part !== "processed_at = NOW()";
        }));
    }

    $stmt = $pdo->prepare("UPDATE crm_outbox SET " . implode(', ', $parts) . " WHERE id = :id");
    $stmt->execute($params);
}

/**
 * Process due stub outbox rows by switching them from pending to processed/failed.
 * @return array{processed:int, sent:int, failed:int}
 */
function crm_process_outbox_due(int $limit = 200, ?int $restaurantId = null): array
{
    $result = ['processed' => 0, 'sent' => 0, 'failed' => 0];
    if (!crm_writes_allowed() || !function_exists('db') || !crm_outbox_table_ready()) {
        return $result;
    }

    $pdo = db();
    $limit = max(1, min(500, $limit));
    $startedAt = date('Y-m-d H:i:s');
    $runId = PHP_SAPI === 'cli'
        ? crm_log_cron_run('crm_outbox_stub', 'running', $startedAt, ['restaurant_id' => $restaurantId, 'limit' => $limit])
        : null;

    try {
        $sql = "
            SELECT o.id, o.restaurant_id, o.guest_id, g.consent
            FROM crm_outbox o
            LEFT JOIN crm_guests g ON g.id = o.guest_id AND g.restaurant_id = o.restaurant_id
            WHERE o.status = 'pending'
              AND o.scheduled_at <= NOW()
        ";
        $params = [];
        if ($restaurantId !== null && $restaurantId > 0) {
            $sql .= " AND o.restaurant_id = ?";
            $params[] = $restaurantId;
        }
        $sql .= " ORDER BY o.scheduled_at ASC LIMIT " . (int)$limit;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            $result['processed']++;
            $guestConsent = isset($row['consent']) ? (int)$row['consent'] : null;
            if ($guestConsent !== 1) {
                crm_update_outbox_status((int)$row['id'], 'failed', 'guest_missing_or_no_consent');
                $result['failed']++;
                continue;
            }

            crm_update_outbox_status((int)$row['id'], 'processed');
            // Keep legacy key for compatibility; do not imply delivery.
            $result['sent']++;
        }

        if ($runId !== null) {
            crm_log_cron_run('crm_outbox_stub', 'success', $startedAt, $result, $runId);
        }
    } catch (Throwable $e) {
        $result['failed'] = max($result['failed'], 1);
        if ($runId !== null) {
            crm_log_cron_run('crm_outbox_stub', 'failed', $startedAt, ['error' => $e->getMessage()] + $result, $runId);
        }
        if (function_exists('error_log')) {
            error_log('crm_process_outbox_due ' . $e->getMessage());
        }
    }

    return $result;
}

/**
 * Finalize CRM/retention visit from an eligible paid order.
 */
function crm_finalize_order_visit(int $restaurantId, int $orderId): bool
{
    if (!crm_writes_allowed() || $restaurantId <= 0 || $orderId <= 0 || !function_exists('db')) {
        return false;
    }

    $order = crm_order_lookup($restaurantId, $orderId);
    if (!$order) {
        return false;
    }
    if (($order['payment_status'] ?? '') !== 'paid' || ($order['order_status'] ?? '') === 'canceled') {
        return false;
    }

    $phone = (string)($order['crm_phone'] ?? $order['customer_phone'] ?? '');
    $phone = crm_normalize_phone($phone ?? '') ?? '';
    if ($phone === '') {
        return false;
    }

    $consent = (int)($order['crm_consent'] ?? 0) === 1;
    $guest = crm_upsert_guest($restaurantId, $phone, $consent);
    if (!$guest || empty($guest['id'])) {
        return false;
    }

    $recorded = crm_record_visit(
        $restaurantId,
        (int)$guest['id'],
        $orderId,
        (int)($order['table_id'] ?? 0),
        (float)($order['total_amount'] ?? 0)
    );

    if (!$recorded) {
        return false;
    }

    if (file_exists(__DIR__ . '/guest_retention.php')) {
        require_once __DIR__ . '/guest_retention.php';
        if (function_exists('record_guest_visit')) {
            record_guest_visit($restaurantId, $orderId, $phone, null);
        }
    }

    if ($consent) {
        $flowIdFromOrder = (string)($order['flow_id'] ?? '');
        $flowIdFromOrder = trim($flowIdFromOrder);
        $flowIdFromOrder = $flowIdFromOrder !== '' ? $flowIdFromOrder : null;
        crm_schedule_comeback($restaurantId, (int)$guest['id'], 3, $flowIdFromOrder);
        crm_schedule_comeback($restaurantId, (int)$guest['id'], 14, $flowIdFromOrder);
    }

    return true;
}

/**
 * List outbox rows for restaurant (for UI).
 * @return array<array>
 */
function crm_list_outbox(int $restaurantId, string $status = 'pending', int $limit = 200): array
{
    if ($restaurantId <= 0 || !function_exists('db') || !crm_outbox_table_ready()) {
        return [];
    }

    $pdo = db();
    $stmt = $pdo->prepare("
        SELECT o.id, o.restaurant_id, o.guest_id, o.channel, o.template, o.payload_json, o.scheduled_at, o.status, o.created_at,
               g.phone
        FROM crm_outbox o
        JOIN crm_guests g ON g.id = o.guest_id AND g.restaurant_id = o.restaurant_id
        WHERE o.restaurant_id = ?
          AND o.status = ?
        ORDER BY o.scheduled_at ASC
        LIMIT " . (int)$limit
    );
    $stmt->execute([$restaurantId, $status]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * One outbox row for restaurant (tenant-scoped).
 *
 * @return array<string, mixed>|null
 */
function crm_outbox_row(int $restaurantId, int $outboxId): ?array
{
    if ($restaurantId <= 0 || $outboxId <= 0 || !function_exists('db') || !crm_outbox_table_ready()) {
        return null;
    }
    $pdo = db();
    $stmt = $pdo->prepare('SELECT id, restaurant_id, guest_id, channel, template, payload_json, scheduled_at, status, created_at FROM crm_outbox WHERE id = ? AND restaurant_id = ? LIMIT 1');
    $stmt->execute([$outboxId, $restaurantId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Allow cancel/send_manual without full CRM feature when row is manual_prepare from restaurant UI.
 */
function crm_outbox_manual_prepare_action_allowed(int $restaurantId, string $action, array $post): bool
{
    if (!in_array($action, ['cancel', 'send_manual'], true)) {
        return false;
    }
    $outboxId = (int)($post['id'] ?? 0);
    if ($outboxId <= 0) {
        return false;
    }
    $row = crm_outbox_row($restaurantId, $outboxId);
    if (!$row) {
        return false;
    }
    if (($row['template'] ?? '') !== 'manual_return') {
        return false;
    }
    $st = (string)($row['status'] ?? '');
    if ($action === 'cancel') {
        return in_array($st, ['pending', 'draft', 'ready_manual'], true);
    }
    if ($action === 'send_manual') {
        return in_array($st, ['pending', 'draft', 'ready_manual'], true);
    }

    return false;
}

/**
 * Cancel one outbox row (status = canceled). Returns true if updated.
 */
function crm_cancel_outbox(int $restaurantId, int $outboxId): bool
{
    if (!crm_writes_allowed() || $restaurantId <= 0 || $outboxId <= 0 || !function_exists('db') || !crm_outbox_table_ready()) {
        return false;
    }

    $pdo = db();
    $stmt = $pdo->prepare("UPDATE crm_outbox SET status = 'canceled', updated_at = NOW() WHERE id = ? AND restaurant_id = ? AND status IN ('pending','draft','ready_manual')");
    $stmt->execute([$outboxId, $restaurantId]);
    return $stmt->rowCount() > 0;
}

/**
 * Mark pending outbox as "sent" by manual action (stub, no real delivery).
 * Maps UI: pending -> "ожидается", processed -> "отправлено (manual)".
 */
function crm_mark_outbox_sent_manual(int $restaurantId, int $outboxId): bool
{
    if (!crm_writes_allowed() || $restaurantId <= 0 || $outboxId <= 0 || !function_exists('db') || !crm_outbox_table_ready()) {
        return false;
    }

    $pdo = db();

    $setParts = [
        "status = 'processed'",
        "updated_at = NOW()",
    ];

    if (function_exists('db_column_exists') && db_column_exists('crm_outbox', 'processed_at')) {
        $setParts[] = "processed_at = NOW()";
    }
    if (function_exists('db_column_exists') && db_column_exists('crm_outbox', 'sent_at')) {
        $setParts[] = "sent_at = NOW()";
    }

    $sql = "UPDATE crm_outbox SET " . implode(', ', $setParts) . " WHERE id = ? AND restaurant_id = ? AND status IN ('pending','draft','ready_manual')";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$outboxId, $restaurantId]);
    return $stmt->rowCount() > 0;
}

/**
 * Orders total column name (schema-safe).
 */
function crm_orders_amount_column(): string
{
    return (function_exists('db_column_exists') && db_column_exists('orders', 'total_amount')) ? 'total_amount' : 'total_price';
}

/**
 * Forget cached CRM return settings for one restaurant (after UI save).
 */
function crm_crm_settings_cache_forget(int $restaurantId): void
{
    if ($restaurantId <= 0) {
        return;
    }
    if (isset($GLOBALS['_crm_rest_effective']) && is_array($GLOBALS['_crm_rest_effective'])) {
        unset($GLOBALS['_crm_rest_effective'][$restaurantId]);
    }
}

/**
 * Effective CRM return settings: DB row first (restaurant_crm_settings), else env days, else defaults.
 *
 * @return array{inactive_return_days: int, enabled: bool, has_db_row: bool}
 */
function crm_restaurant_crm_settings_effective(int $restaurantId): array
{
    if ($restaurantId <= 0) {
        return ['inactive_return_days' => 14, 'enabled' => true, 'has_db_row' => false];
    }
    if (!isset($GLOBALS['_crm_rest_effective']) || !is_array($GLOBALS['_crm_rest_effective'])) {
        $GLOBALS['_crm_rest_effective'] = [];
    }
    if (isset($GLOBALS['_crm_rest_effective'][$restaurantId])) {
        return $GLOBALS['_crm_rest_effective'][$restaurantId];
    }

    $days = 14;
    $enabled = true;
    $hasDbRow = false;

    if (function_exists('db') && function_exists('db_table_exists') && db_table_exists('restaurant_crm_settings')) {
        try {
            $stmt = db()->prepare('SELECT inactive_return_days, enabled FROM restaurant_crm_settings WHERE restaurant_id = ? LIMIT 1');
            $stmt->execute([$restaurantId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $hasDbRow = true;
                $enabled = (int)($row['enabled'] ?? 1) === 1;
                $iv = (int)($row['inactive_return_days'] ?? 14);
                if ($iv >= 7 && $iv <= 90) {
                    $days = $iv;
                }
            }
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('crm_restaurant_crm_settings_effective ' . $e->getMessage());
            }
        }
    }

    if (!$hasDbRow) {
        $env = getenv('CRM_INACTIVE_VISIT_DAYS');
        if ($env !== false && $env !== '') {
            $ev = (int)$env;
            if ($ev >= 7 && $ev <= 90) {
                $days = $ev;
            }
        }
    }

    $out = ['inactive_return_days' => $days, 'enabled' => $enabled, 'has_db_row' => $hasDbRow];
    $GLOBALS['_crm_rest_effective'][$restaurantId] = $out;

    return $out;
}

/**
 * Whether automated / UI “guest return” flows are enabled for this restaurant.
 */
function crm_guest_return_enabled(int $restaurantId): bool
{
    if ($restaurantId <= 0) {
        return true;
    }

    return crm_restaurant_crm_settings_effective($restaurantId)['enabled'];
}

/**
 * Upsert restaurant_crm_settings (tenant-scoped).
 */
function crm_upsert_restaurant_crm_settings(int $restaurantId, bool $enabled, int $inactiveReturnDays): bool
{
    if (!crm_writes_allowed() || $restaurantId <= 0 || !function_exists('db') || !function_exists('db_table_exists') || !db_table_exists('restaurant_crm_settings')) {
        return false;
    }
    $inactiveReturnDays = max(7, min(90, $inactiveReturnDays));
    $en = $enabled ? 1 : 0;
    try {
        $stmt = db()->prepare(
            'INSERT INTO restaurant_crm_settings (restaurant_id, inactive_return_days, enabled) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE inactive_return_days = VALUES(inactive_return_days), enabled = VALUES(enabled)'
        );
        $stmt->execute([$restaurantId, $inactiveReturnDays, $en]);
        crm_crm_settings_cache_forget($restaurantId);

        return true;
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('crm_upsert_restaurant_crm_settings ' . $e->getMessage());
        }

        return false;
    }
}

/**
 * Days since last activity to still count as "active" (else "inactive" if repeat guest).
 * Order: DB (restaurant_crm_settings) → env CRM_INACTIVE_VISIT_DAYS (if no DB row) → 14.
 */
function crm_inactive_visit_threshold_days(?int $restaurantId = null): int
{
    if ($restaurantId === null || $restaurantId <= 0) {
        $days = 14;
        $env = getenv('CRM_INACTIVE_VISIT_DAYS');
        if ($env !== false && $env !== '') {
            $ev = (int)$env;
            if ($ev >= 7 && $ev <= 90) {
                $days = $ev;
            }
        }

        return $days;
    }

    return crm_restaurant_crm_settings_effective($restaurantId)['inactive_return_days'];
}

/**
 * Guest lifecycle segment for restaurant CRM UI: new | active | inactive.
 */
function crm_guest_ui_segment(array $row, ?int $restaurantId = null): string
{
    $rid = $restaurantId ?? (int)($row['restaurant_id'] ?? 0);
    $thresholdDays = $rid > 0 ? crm_inactive_visit_threshold_days($rid) : 14;
    $visits = (int)($row['visits_count'] ?? 0);
    $lastRaw = $row['last_seen_at'] ?? $row['last_order_at'] ?? null;
    $lastTs = $lastRaw ? strtotime((string)$lastRaw) : false;
    if ($lastTs === false) {
        $lastTs = 0;
    }
    if ($visits <= 1) {
        return 'new';
    }
    if ($lastTs >= strtotime('-' . $thresholdDays . ' days')) {
        return 'active';
    }

    return 'inactive';
}

/**
 * Guests of restaurant with paid-order aggregates (tenant-scoped).
 *
 * @return array<int, array<string, mixed>>
 */
function crm_restaurant_guests_dashboard_rows(int $restaurantId): array
{
    if ($restaurantId <= 0 || !function_exists('db') || !function_exists('db_table_exists') || !db_table_exists('crm_guests')) {
        return [];
    }

    $pdo = db();
    $amountCol = crm_orders_amount_column();
    $hasGuestId = db_column_exists('orders', 'guest_id');
    $hasPayment = db_column_exists('orders', 'payment_status');

    try {
        if ($hasGuestId && db_table_exists('orders')) {
            $paidSql = $hasPayment ? " AND o.payment_status = 'paid' " : '';
            $sql = "
                SELECT g.id, g.restaurant_id, g.phone, g.consent, g.first_seen_at, g.last_seen_at, g.visits_count,
                       COALESCE(a.order_count, 0) AS order_count,
                       COALESCE(a.total_spent, 0) AS total_spent,
                       a.last_order_at
                FROM crm_guests g
                LEFT JOIN (
                    SELECT o.guest_id AS gid,
                           COUNT(*) AS order_count,
                           SUM(COALESCE(o.{$amountCol}, 0)) AS total_spent,
                           MAX(o.created_at) AS last_order_at
                    FROM orders o
                    WHERE o.restaurant_id = :rid_o
                      AND o.guest_id IS NOT NULL AND o.guest_id > 0
                      {$paidSql}
                    GROUP BY o.guest_id
                ) a ON a.gid = g.id
                WHERE g.restaurant_id = :rid
                ORDER BY COALESCE(g.last_seen_at, a.last_order_at, g.created_at) DESC
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['rid_o' => $restaurantId, 'rid' => $restaurantId]);
        } else {
            $stmt = $pdo->prepare("
                SELECT g.id, g.restaurant_id, g.phone, g.consent, g.first_seen_at, g.last_seen_at, g.visits_count,
                       0 AS order_count, 0 AS total_spent, NULL AS last_order_at
                FROM crm_guests g
                WHERE g.restaurant_id = ?
                ORDER BY g.last_seen_at DESC, g.id DESC
            ");
            $stmt->execute([$restaurantId]);
        }
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('crm_restaurant_guests_dashboard_rows rid=' . $restaurantId . ' ' . $e->getMessage());
        }
        return [];
    }
}

/**
 * Order history for one guest (same restaurant only).
 *
 * @return array<int, array<string, mixed>>
 */
function crm_guest_orders_history(int $restaurantId, int $guestId, int $limit = 40): array
{
    if ($restaurantId <= 0 || $guestId <= 0 || !crm_guest_lookup($restaurantId, $guestId)) {
        return [];
    }
    if (!function_exists('db_table_exists') || !db_table_exists('orders') || !db_column_exists('orders', 'guest_id')) {
        return [];
    }

    $pdo = db();
    $amountCol = crm_orders_amount_column();
    $limit = max(1, min(100, $limit));
    try {
        $stmt = $pdo->prepare("
            SELECT id, created_at, {$amountCol} AS order_total, order_status, payment_status
            FROM orders
            WHERE restaurant_id = ? AND guest_id = ?
            ORDER BY created_at DESC
            LIMIT {$limit}
        ");
        $stmt->execute([$restaurantId, $guestId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Short line of dish names for an order (restaurant-scoped).
 */
function crm_order_items_line(int $restaurantId, int $orderId, int $maxItems = 4): string
{
    $order = crm_order_lookup($restaurantId, $orderId);
    if (!$order || !function_exists('db_table_exists') || !db_table_exists('order_items')) {
        return '';
    }
    $pdo = db();
    $qtyCol = (function_exists('db_column_exists') && db_column_exists('order_items', 'qty')) ? 'qty' : 'quantity';
    try {
        $stmt = $pdo->prepare("SELECT item_name, {$qtyCol} AS q FROM order_items WHERE order_id = ? ORDER BY id ASC LIMIT 12");
        $stmt->execute([$orderId]);
        $lines = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $nm = trim((string)($r['item_name'] ?? ''));
            if ($nm === '') {
                continue;
            }
            $q = (int)($r['q'] ?? 1);
            $lines[] = $q > 1 ? ($nm . ' ×' . $q) : $nm;
            if (count($lines) >= $maxItems) {
                break;
            }
        }
        return $lines !== [] ? implode(', ', $lines) : '';
    } catch (Throwable $e) {
        return '';
    }
}

/**
 * Insert crm_outbox row: channel manual, template manual_return (no auto-send for draft/ready_manual).
 *
 * @param array<string, mixed> $payloadAssoc
 */
function crm_outbox_insert_manual_return_row(int $restaurantId, int $guestId, array $payloadAssoc, string $status): bool
{
    if (!crm_writes_allowed() || $restaurantId <= 0 || $guestId <= 0 || !crm_outbox_table_ready()) {
        return false;
    }
    if (!in_array($status, ['draft', 'ready_manual'], true)) {
        return false;
    }
    if (function_exists('app_flow_id_inject_payload_array')) {
        $payloadAssoc = app_flow_id_inject_payload_array($payloadAssoc, $restaurantId);
    }
    $payloadJson = json_encode($payloadAssoc, JSON_UNESCAPED_UNICODE);
    if (!is_string($payloadJson)) {
        return false;
    }

    $scheduledAt = date('Y-m-d H:i:s', strtotime('+365 days'));

    $pdo = db();
    $fields = ['restaurant_id', 'guest_id', 'channel', 'template', 'payload_json', 'scheduled_at', 'status'];
    $placeholders = [':rid', ':gid', "'manual'", "'manual_return'", ':payload', ':scheduled_at', ':status'];
    $params = [
        ':rid' => $restaurantId,
        ':gid' => $guestId,
        ':payload' => $payloadJson,
        ':scheduled_at' => $scheduledAt,
        ':status' => $status,
    ];

    if (function_exists('db_column_exists') && db_column_exists('crm_outbox', 'dedupe_key')) {
        $fields[] = 'dedupe_key';
        $placeholders[] = ':dedupe_key';
        $params[':dedupe_key'] = hash('sha256', $restaurantId . '|' . $guestId . '|manual|' . microtime(true) . '|' . random_int(1, 999999));
    }

    try {
        $sql = 'INSERT INTO crm_outbox (' . implode(', ', $fields) . ') VALUES (' . implode(', ', $placeholders) . ')';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount() > 0;
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('crm_outbox_insert_manual_return_row ' . $e->getMessage());
        }

        return false;
    }
}

/**
 * Recent blocking manual_return row for guest (anti-duplicate for retention drafts).
 *
 * @return array<string, mixed>|null
 */
function crm_outbox_recent_blocking_manual_return(int $restaurantId, int $guestId, int $lookbackDays = 7): ?array
{
    if ($restaurantId <= 0 || $guestId <= 0 || !function_exists('db') || !crm_outbox_table_ready()) {
        return null;
    }
    $lookbackDays = max(1, min(90, $lookbackDays));
    $pdo = db();
    try {
        $stmt = $pdo->prepare(
            "SELECT id, status, created_at, template, channel
             FROM crm_outbox
             WHERE restaurant_id = ?
               AND guest_id = ?
               AND channel = 'manual'
               AND template = 'manual_return'
               AND status IN ('draft', 'ready_manual', 'pending')
               AND created_at >= DATE_SUB(NOW(), INTERVAL {$lookbackDays} DAY)
             ORDER BY created_at DESC
             LIMIT 1"
        );
        $stmt->execute([$restaurantId, $guestId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Deterministic Russian text for inactive-guest return (by visits_count).
 */
function crm_inactive_return_message_body(array $guestDashboardRow): string
{
    $visits = (int)($guestDashboardRow['visits_count'] ?? 0);

    if ($visits <= 1) {
        return 'Здравствуйте! Давно вас не было у нас. Нам было очень приятно ваше посещение — будем рады встретить вас снова. Возвращайтесь: мы обновили меню и с удовольствием угостим вас снова.';
    }
    if ($visits >= 2 && $visits <= 4) {
        return 'Здравствуйте! Давно вас не было у нас. Будем рады видеть вас снова — для вас готовы новые позиции и привычное гостеприимство. Для вас мы подготовили обновления; ждём вашего визита!';
    }

    return 'Здравствуйте! Давно вас не было у нас. Вы для нас особенный гость, и мы будем очень рады вашему возвращению. Приходите — у нас есть для вас новое предложение, и мы с нетерпением ждём вас!';
}

/**
 * Create inactive-return draft in crm_outbox (status draft, no send).
 *
 * @param array<string, mixed> $guestDashboardRow row from crm_restaurant_guests_dashboard_rows
 * @return array{ok: bool, reason?: string, outbox_id?: int}
 */
function crm_create_inactive_return_outbox_draft(int $restaurantId, array $guestDashboardRow): array
{
    if (!crm_writes_allowed() || $restaurantId <= 0 || !crm_outbox_table_ready()) {
        return ['ok' => false, 'reason' => 'writes_disabled'];
    }
    $guestId = (int)($guestDashboardRow['id'] ?? 0);
    if ($guestId <= 0) {
        return ['ok' => false, 'reason' => 'invalid_guest'];
    }
    $guest = crm_guest_lookup($restaurantId, $guestId);
    if (!$guest) {
        return ['ok' => false, 'reason' => 'invalid_guest'];
    }
    $phone = trim((string)($guest['phone'] ?? ''));
    if ($phone === '') {
        return ['ok' => false, 'reason' => 'no_phone'];
    }
    $block = crm_outbox_recent_blocking_manual_return($restaurantId, $guestId, 7);
    if ($block !== null) {
        return ['ok' => false, 'reason' => 'duplicate', 'outbox_id' => (int)($block['id'] ?? 0)];
    }
    if (crm_guest_ui_segment($guestDashboardRow, $restaurantId) !== 'inactive') {
        return ['ok' => false, 'reason' => 'not_inactive'];
    }

    $lastRaw = $guestDashboardRow['last_seen_at'] ?? $guestDashboardRow['last_order_at'] ?? null;
    $lastTs = $lastRaw ? strtotime((string)$lastRaw) : 0;
    $daysSince = $lastTs > 0 ? max(0, (int)floor((time() - $lastTs) / 86400)) : 0;
    $orderCount = (int)($guestDashboardRow['order_count'] ?? 0);
    $totalSpent = (float)($guestDashboardRow['total_spent'] ?? 0);
    $avgCheck = ($orderCount > 0 && $totalSpent > 0) ? round($totalSpent / $orderCount, 2) : null;

    $text = crm_inactive_return_message_body($guestDashboardRow);
    $payload = [
        'guest_id' => $guestId,
        'phone' => $phone,
        'text' => $text,
        'reason' => 'inactive_guest_return',
        'days_since_last_visit' => $daysSince,
        'visits_count' => (int)($guestDashboardRow['visits_count'] ?? 0),
        'total_orders' => $orderCount,
        'prepared_by_rule' => true,
        'manual_prepare' => true,
        'source' => 'inactive_return_rule',
    ];
    if ($avgCheck !== null) {
        $payload['avg_order_value'] = $avgCheck;
    }

    if (crm_outbox_insert_manual_return_row($restaurantId, $guestId, $payload, 'draft')) {
        return ['ok' => true];
    }

    return ['ok' => false, 'reason' => 'insert_failed'];
}

/**
 * Guests to show under "Готовы к возврату" with eligibility flags.
 *
 * @return list<array<string, mixed>>
 */
function crm_guests_ready_for_inactive_return_list(int $restaurantId): array
{
    if ($restaurantId <= 0) {
        return [];
    }
    if ((!function_exists('is_demo_mode') || !is_demo_mode()) && !crm_guest_return_enabled($restaurantId)) {
        return [];
    }
    if (function_exists('is_demo_mode') && is_demo_mode()) {
        return [
            [
                'id' => 201,
                'restaurant_id' => $restaurantId,
                'phone' => '+1 555 0201',
                'visits_count' => 6,
                'last_seen_at' => date('Y-m-d H:i:s', strtotime('-24 days')),
                'last_order_at' => date('Y-m-d H:i:s', strtotime('-24 days')),
                'order_count' => 6,
                'total_spent' => 18500.0,
                'guest_display_name' => 'Демо: постоянный гость',
                'can_generate' => true,
                'recent_outbox' => null,
            ],
            [
                'id' => 202,
                'restaurant_id' => $restaurantId,
                'phone' => '+1 555 0202',
                'visits_count' => 3,
                'last_seen_at' => date('Y-m-d H:i:s', strtotime('-20 days')),
                'last_order_at' => date('Y-m-d H:i:s', strtotime('-20 days')),
                'order_count' => 3,
                'total_spent' => 4200.0,
                'guest_display_name' => 'Демо: черновик уже есть',
                'can_generate' => false,
                'recent_outbox' => ['id' => 9991, 'status' => 'draft', 'created_at' => date('Y-m-d H:i:s')],
            ],
        ];
    }

    $rows = crm_restaurant_guests_dashboard_rows($restaurantId);
    $out = [];
    foreach ($rows as $row) {
        $gid = (int)($row['id'] ?? 0);
        if ($gid <= 0 || trim((string)($row['phone'] ?? '')) === '') {
            continue;
        }
        if (crm_guest_ui_segment($row, $restaurantId) !== 'inactive') {
            continue;
        }
        $block = crm_outbox_recent_blocking_manual_return($restaurantId, $gid, 7);
        $row['guest_display_name'] = $row['guest_display_name'] ?? '';
        $row['can_generate'] = $block === null;
        $row['recent_outbox'] = $block;
        $out[] = $row;
    }

    return $out;
}

/**
 * Bulk inactive-return drafts (max $maxCreate at once).
 *
 * @return array{created: int, skipped_duplicate: int, skipped_other: int}
 */
function crm_bulk_create_inactive_return_drafts(int $restaurantId, int $maxCreate = 50): array
{
    $result = ['created' => 0, 'skipped_duplicate' => 0, 'skipped_other' => 0];
    if ($restaurantId <= 0) {
        return $result;
    }
    $maxCreate = max(1, min(50, $maxCreate));
    $list = crm_guests_ready_for_inactive_return_list($restaurantId);
    $n = 0;
    foreach ($list as $row) {
        if ($n >= $maxCreate) {
            break;
        }
        if (empty($row['can_generate'])) {
            continue;
        }
        $res = crm_create_inactive_return_outbox_draft($restaurantId, $row);
        if (!empty($res['ok'])) {
            $result['created']++;
            $n++;
        } elseif (($res['reason'] ?? '') === 'duplicate') {
            $result['skipped_duplicate']++;
        } else {
            $result['skipped_other']++;
        }
    }

    return $result;
}

/**
 * Save manual return message into crm_outbox (no auto-send: status draft | ready_manual).
 * Does not require marketing consent (restaurant prepares text for manual channel).
 */
function crm_save_manual_return_message(int $restaurantId, int $guestId, string $message, string $saveMode): bool
{
    if (!crm_writes_allowed() || $restaurantId <= 0 || $guestId <= 0 || !crm_outbox_table_ready()) {
        return false;
    }
    $guest = crm_guest_lookup($restaurantId, $guestId);
    if (!$guest) {
        return false;
    }
    $message = trim($message);
    if ($message === '') {
        return false;
    }
    $status = ($saveMode === 'ready') ? 'ready_manual' : 'draft';
    if (!in_array($status, ['draft', 'ready_manual'], true)) {
        return false;
    }

    $payload = [
        'text' => $message,
        'phone' => (string)($guest['phone'] ?? ''),
        'source' => 'restaurant_crm_guests_ui',
        'manual_prepare' => true,
    ];

    return crm_outbox_insert_manual_return_row($restaurantId, $guestId, $payload, $status);
}
