<?php
/**
 * Sales pipeline: leads (lead_requests), notes, tasks, history.
 */

require_once __DIR__ . '/db.php';
if (file_exists(__DIR__ . '/schema_guard.php')) {
    require_once __DIR__ . '/schema_guard.php';
}

const LEADS_PIPELINE_STATUSES = ['new', 'contacted', 'demo_scheduled', 'negotiation', 'won', 'lost'];

function leads_get_allowed_statuses(): array
{
    return LEADS_PIPELINE_STATUSES;
}

function lead_get(int $id): ?array
{
    try {
        $pdo = db();
        $cols = 'id, contact_name, contact_phone, contact_email, restaurant_name, message, user_id, status, created_at';
        if (function_exists('db_column_exists') && db_column_exists('lead_requests', 'city')) {
            $cols .= ', city';
        }
        if (function_exists('db_column_exists') && db_column_exists('lead_requests', 'score')) {
            $cols .= ', score, priority, last_scored_at';
        }
        if (function_exists('db_column_exists') && db_column_exists('lead_requests', 'expected_mrr')) {
            $cols .= ', expected_mrr';
        }
        $stmt = $pdo->prepare("SELECT {$cols} FROM lead_requests WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        error_log('LEADS_PIPELINE lead_get id=' . $id . ' ' . $e->getMessage());
        return null;
    }
}

function lead_notes_list(int $leadId): array
{
    if (!function_exists('db_table_exists') || !db_table_exists('lead_notes')) {
        return [];
    }
    try {
        $pdo = db();
        $stmt = $pdo->prepare("SELECT id, lead_id, user_id, note_text, created_at FROM lead_notes WHERE lead_id = ? ORDER BY created_at DESC");
        $stmt->execute([$leadId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('LEADS_PIPELINE lead_notes_list lead_id=' . $leadId . ' ' . $e->getMessage());
        return [];
    }
}

function lead_note_add(int $leadId, ?int $userId, string $text): bool
{
    if (!function_exists('db_table_exists') || !db_table_exists('lead_notes')) {
        return false;
    }
    $text = trim($text);
    if ($text === '') {
        return false;
    }
    try {
        $pdo = db();
        $stmt = $pdo->prepare("INSERT INTO lead_notes (lead_id, user_id, note_text) VALUES (?, ?, ?)");
        $stmt->execute([$leadId, $userId, $text]);
        if (db_table_exists('lead_history')) {
            $stmt2 = $pdo->prepare("INSERT INTO lead_history (lead_id, user_id, event_type, new_value) VALUES (?, ?, 'note_added', ?)");
            $stmt2->execute([$leadId, $userId, substr($text, 0, 255)]);
        }
        if (function_exists('lead_score_refresh')) {
            lead_score_refresh($leadId);
        }
        return true;
    } catch (Throwable $e) {
        error_log('LEADS_PIPELINE lead_note_add lead_id=' . $leadId . ' ' . $e->getMessage());
        return false;
    }
}

function lead_tasks_list(int $leadId): array
{
    if (!function_exists('db_table_exists') || !db_table_exists('lead_tasks')) {
        return [];
    }
    try {
        $pdo = db();
        $stmt = $pdo->prepare("SELECT id, lead_id, assigned_user_id, title, due_at, status, created_at, updated_at FROM lead_tasks WHERE lead_id = ? ORDER BY status ASC, due_at ASC");
        $stmt->execute([$leadId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('LEADS_PIPELINE lead_tasks_list lead_id=' . $leadId . ' ' . $e->getMessage());
        return [];
    }
}

function lead_task_add(int $leadId, ?int $userId, string $title, ?string $dueAt): bool
{
    if (!function_exists('db_table_exists') || !db_table_exists('lead_tasks')) {
        return false;
    }
    $title = trim($title);
    if ($title === '') {
        return false;
    }
    try {
        $pdo = db();
        $stmt = $pdo->prepare("INSERT INTO lead_tasks (lead_id, assigned_user_id, title, due_at, status) VALUES (?, ?, ?, ?, 'open')");
        $stmt->execute([$leadId, $userId, $title, $dueAt ?: null]);
        if (db_table_exists('lead_history')) {
            $stmt2 = $pdo->prepare("INSERT INTO lead_history (lead_id, user_id, event_type, new_value) VALUES (?, ?, 'task_added', ?)");
            $stmt2->execute([$leadId, $userId, substr($title, 0, 255)]);
        }
        if (function_exists('lead_score_refresh')) {
            lead_score_refresh($leadId);
        }
        return true;
    } catch (Throwable $e) {
        error_log('LEADS_PIPELINE lead_task_add lead_id=' . $leadId . ' ' . $e->getMessage());
        return false;
    }
}

function lead_task_update_status(int $taskId, string $status, ?int $userId = null): bool
{
    if (!in_array($status, ['open', 'done', 'canceled'], true)) {
        return false;
    }
    if (!function_exists('db_table_exists') || !db_table_exists('lead_tasks')) {
        return false;
    }
    try {
        $pdo = db();
        $stmt = $pdo->prepare("SELECT id, lead_id FROM lead_tasks WHERE id = ? LIMIT 1");
        $stmt->execute([$taskId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return false;
        }
        $upd = $pdo->prepare("UPDATE lead_tasks SET status = ? WHERE id = ?");
        $upd->execute([$status, $taskId]);
        if ($upd->rowCount() && db_table_exists('lead_history')) {
            $eventType = $status === 'done' ? 'task_done' : 'task_canceled';
            $stmt2 = $pdo->prepare("INSERT INTO lead_history (lead_id, user_id, event_type, new_value) VALUES (?, ?, ?, ?)");
            $stmt2->execute([(int)$row['lead_id'], $userId, $eventType, $status]);
        }
        if (function_exists('lead_score_refresh')) {
            lead_score_refresh((int)$row['lead_id']);
        }
        return true;
    } catch (Throwable $e) {
        error_log('LEADS_PIPELINE lead_task_update_status task_id=' . $taskId . ' ' . $e->getMessage());
        return false;
    }
}

function lead_update_status(int $leadId, string $newStatus, ?int $userId): bool
{
    if (!in_array($newStatus, LEADS_PIPELINE_STATUSES, true)) {
        return false;
    }
    try {
        $pdo = db();
        $stmt = $pdo->prepare("SELECT id, status FROM lead_requests WHERE id = ? LIMIT 1");
        $stmt->execute([$leadId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return false;
        }
        $oldStatus = $row['status'] ?? '';
        if ($oldStatus === $newStatus) {
            return true;
        }
        $upd = $pdo->prepare("UPDATE lead_requests SET status = ? WHERE id = ?");
        $upd->execute([$newStatus, $leadId]);
        if ($upd->rowCount() && function_exists('db_table_exists') && db_table_exists('lead_history')) {
            $stmt2 = $pdo->prepare("INSERT INTO lead_history (lead_id, user_id, event_type, old_value, new_value) VALUES (?, ?, 'status_changed', ?, ?)");
            $stmt2->execute([$leadId, $userId, $oldStatus, $newStatus]);
        }
        if (function_exists('lead_score_refresh')) {
            lead_score_refresh($leadId);
        }
        return true;
    } catch (Throwable $e) {
        error_log('LEADS_PIPELINE lead_update_status lead_id=' . $leadId . ' ' . $e->getMessage());
        return false;
    }
}

/**
 * Update expected_mrr for a lead. No-op if column does not exist.
 */
function lead_update_expected_mrr(int $leadId, ?float $value): bool
{
    if (!function_exists('db_column_exists') || !db_column_exists('lead_requests', 'expected_mrr')) {
        return false;
    }
    try {
        $pdo = db();
        $stmt = $pdo->prepare("UPDATE lead_requests SET expected_mrr = ? WHERE id = ? LIMIT 1");
        $stmt->execute([$value, $leadId]);
        return true;
    } catch (Throwable $e) {
        error_log('LEADS_PIPELINE lead_update_expected_mrr lead_id=' . $leadId . ' ' . $e->getMessage());
        return false;
    }
}

function lead_history_list(int $leadId): array
{
    if (!function_exists('db_table_exists') || !db_table_exists('lead_history')) {
        return [];
    }
    try {
        $pdo = db();
        $stmt = $pdo->prepare("SELECT id, lead_id, user_id, event_type, old_value, new_value, created_at FROM lead_history WHERE lead_id = ? ORDER BY created_at DESC");
        $stmt->execute([$leadId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('LEADS_PIPELINE lead_history_list lead_id=' . $leadId . ' ' . $e->getMessage());
        return [];
    }
}

/** @return array<string,int> Counts by status. */
function leads_pipeline_counts(): array
{
    $out = array_fill_keys(LEADS_PIPELINE_STATUSES, 0);
    try {
        $pdo = db();
        $stmt = $pdo->query("SELECT status, COUNT(*) AS cnt FROM lead_requests GROUP BY status");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $st = $row['status'] ?? '';
            $out[$st] = (int)$row['cnt'];
        }
    } catch (Throwable $e) {
        error_log('LEADS_PIPELINE leads_pipeline_counts ' . $e->getMessage());
    }
    return $out;
}

/**
 * List leads with optional status, priority filter and search.
 * Order: priority (hot first), score DESC, created_at DESC.
 */
function leads_pipeline_list(string $status = 'all', string $q = '', int $limit = 200, string $priority = 'all'): array
{
    try {
        $pdo = db();
        $params = [];
        $where = '1=1';
        if ($status !== '' && $status !== 'all' && in_array($status, LEADS_PIPELINE_STATUSES, true)) {
            $where .= ' AND lr.status = ?';
            $params[] = $status;
        }
        if ($priority !== '' && $priority !== 'all' && in_array($priority, ['hot', 'warm', 'cold'], true)) {
            if (function_exists('db_column_exists') && db_column_exists('lead_requests', 'priority')) {
                $where .= ' AND lr.priority = ?';
                $params[] = $priority;
            }
        }
        $q = trim($q);
        if ($q !== '') {
            $like = '%' . $q . '%';
            $where .= ' AND (lr.restaurant_name LIKE ? OR lr.contact_name LIKE ? OR lr.contact_phone LIKE ? OR lr.contact_email LIKE ?';
            if (function_exists('db_column_exists') && db_column_exists('lead_requests', 'city')) {
                $where .= ' OR lr.city LIKE ?';
            }
            $where .= ')';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            if (function_exists('db_column_exists') && db_column_exists('lead_requests', 'city')) {
                $params[] = $like;
            }
        }
        $cols = 'lr.id, lr.contact_name, lr.contact_phone, lr.contact_email, lr.restaurant_name, lr.message, lr.user_id, lr.status, lr.created_at';
        if (function_exists('db_column_exists') && db_column_exists('lead_requests', 'city')) {
            $cols .= ', lr.city';
        }
        if (function_exists('db_column_exists') && db_column_exists('lead_requests', 'score')) {
            $cols .= ', lr.score, lr.priority';
        }
        $order = 'lr.created_at DESC';
        if (function_exists('db_column_exists') && db_column_exists('lead_requests', 'priority')) {
            $order = "FIELD(lr.priority, 'hot', 'warm', 'cold'), lr.score DESC, lr.created_at DESC";
        }
        $sql = "SELECT {$cols} FROM lead_requests lr WHERE {$where} ORDER BY {$order} LIMIT " . (int)$limit;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('LEADS_PIPELINE leads_pipeline_list ' . $e->getMessage());
        return [];
    }
}
