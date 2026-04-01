<?php
/**
 * Lead scoring: score + priority (hot/warm/cold). Uses lead_requests.
 */

require_once __DIR__ . '/db.php';
if (file_exists(__DIR__ . '/schema_guard.php')) {
    require_once __DIR__ . '/schema_guard.php';
}

/**
 * @param array $lead lead_requests row (status, contact_phone, contact_email, city, created_at)
 * @param array $notes list of note rows
 * @param array $tasks list of task rows (status, due_at)
 * @param array $history list of history rows (event_type, created_at)
 * @return array{score:int, priority:string, reasons:array}
 */
function lead_score_calculate(array $lead, array $notes = [], array $tasks = [], array $history = []): array
{
    $score = 0;
    $reasons = [];

    $status = trim((string)($lead['status'] ?? 'new'));
    $statusScores = [
        'new'             => 5,
        'contacted'       => 15,
        'demo_scheduled'  => 30,
        'negotiation'     => 50,
        'won'             => 100,
        'lost'            => 0,
    ];
    $points = $statusScores[$status] ?? 5;
    $score += $points;
    if ($points > 0) {
        $reasons[] = 'Статус: ' . $status . ' (+' . $points . ')';
    }

    $hasPhone = trim((string)($lead['contact_phone'] ?? '')) !== '';
    $hasEmail = trim((string)($lead['contact_email'] ?? '')) !== '';
    $hasCity = trim((string)($lead['city'] ?? '')) !== '';
    if ($hasPhone) {
        $score += 10;
        $reasons[] = 'Есть телефон (+10)';
    }
    if ($hasEmail) {
        $score += 8;
        $reasons[] = 'Есть email (+8)';
    }
    if ($hasCity) {
        $score += 5;
        $reasons[] = 'Указан город (+5)';
    }

    if (count($notes) > 0) {
        $score += 5;
        $reasons[] = 'Есть заметки (+5)';
    }
    $openTasks = array_filter($tasks, function ($t) {
        return ($t['status'] ?? '') === 'open';
    });
    if (count($openTasks) > 0) {
        $score += 10;
        $reasons[] = 'Есть открытые задачи (+10)';
    }
    $completedTasks = array_filter($tasks, function ($t) {
        return ($t['status'] ?? '') === 'done';
    });
    if (count($completedTasks) > 0) {
        $score += 5;
        $reasons[] = 'Есть выполненные задачи (+5)';
    }

    $sevenDaysAgo = date('Y-m-d H:i:s', strtotime('-7 days'));
    $recentStatusChange = false;
    foreach ($history as $h) {
        if (($h['event_type'] ?? '') === 'status_changed' && ($h['created_at'] ?? '') >= $sevenDaysAgo) {
            $recentStatusChange = true;
            break;
        }
    }
    if ($recentStatusChange) {
        $score += 10;
        $reasons[] = 'Свежая смена статуса за 7 дней (+10)';
    }

    if ($status === 'new') {
        $createdAt = $lead['created_at'] ?? '';
        if ($createdAt !== '' && strtotime($createdAt) < strtotime('-7 days')) {
            $score -= 10;
            $reasons[] = 'Новый лид старше 7 дней (-10)';
        }
    }

    foreach ($openTasks as $t) {
        $dueAt = $t['due_at'] ?? null;
        if ($dueAt && strtotime($dueAt) < time()) {
            $score -= 15;
            $reasons[] = 'Просроченная задача (-15)';
            break;
        }
    }

    if (!$hasPhone && !$hasEmail) {
        $score -= 20;
        $reasons[] = 'Нет ни телефона, ни email (-20)';
    }

    if ($score < 0) {
        $score = 0;
    }

    $priority = 'cold';
    if ($score >= 60) {
        $priority = 'hot';
    } elseif ($score >= 25) {
        $priority = 'warm';
    }

    return [
        'score'    => $score,
        'priority' => $priority,
        'reasons'  => $reasons,
    ];
}

/**
 * Load lead + notes + tasks + history, recalc score, update lead_requests.
 */
function lead_score_refresh(int $leadId): bool
{
    if (!function_exists('lead_get') || !function_exists('lead_notes_list') || !function_exists('lead_tasks_list') || !function_exists('lead_history_list')) {
        require_once __DIR__ . '/leads_pipeline_repo.php';
    }
    if (!function_exists('db_column_exists') || !db_column_exists('lead_requests', 'score')) {
        return false;
    }
    try {
        $lead = lead_get($leadId);
        if (!$lead) {
            return false;
        }
        $notes = lead_notes_list($leadId);
        $tasks = lead_tasks_list($leadId);
        $history = lead_history_list($leadId);
        $result = lead_score_calculate($lead, $notes, $tasks, $history);

        $pdo = db();
        $stmt = $pdo->prepare("UPDATE lead_requests SET score = ?, priority = ?, last_scored_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([$result['score'], $result['priority'], $leadId]);
        return true;
    } catch (Throwable $e) {
        error_log('LEADS_SCORING refresh lead_id=' . $leadId . ' ' . $e->getMessage());
        return false;
    }
}

/**
 * @return array{updated_count:int, hot_count:int, warm_count:int, cold_count:int}
 */
function leads_score_refresh_all(int $limit = 500): array
{
    $out = ['updated_count' => 0, 'hot_count' => 0, 'warm_count' => 0, 'cold_count' => 0];
    if (!function_exists('db_column_exists') || !db_column_exists('lead_requests', 'score')) {
        return $out;
    }
    try {
        $pdo = db();
        $stmt = $pdo->query("SELECT id FROM lead_requests ORDER BY id ASC LIMIT " . (int)$limit);
        $ids = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $ids[] = (int)$row['id'];
        }
        foreach ($ids as $id) {
            if (lead_score_refresh($id)) {
                $out['updated_count']++;
            }
        }
        $stmt = $pdo->query("SELECT priority, COUNT(*) AS cnt FROM lead_requests GROUP BY priority");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $p = $row['priority'] ?? 'cold';
            if ($p === 'hot') {
                $out['hot_count'] = (int)$row['cnt'];
            } elseif ($p === 'warm') {
                $out['warm_count'] = (int)$row['cnt'];
            } else {
                $out['cold_count'] += (int)$row['cnt'];
            }
        }
    } catch (Throwable $e) {
        error_log('LEADS_SCORING refresh_all ' . $e->getMessage());
    }
    return $out;
}
