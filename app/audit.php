<?php
/**
 * Audit log (Variant 7). All writes via prepared statements.
 */

require_once __DIR__ . '/db.php';

function audit_log(string $action, string $entityType = '', ?string $entityId = null): void
{
    $userId = null;
    if (function_exists('auth_user')) {
        $u = auth_user();
        $userId = $u ? (int)($u['id'] ?? 0) : null;
    }
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $ipHash = $ip !== '' ? hash('sha256', $ip) : null;
    $pdo = db();
    try {
        $stmt = $pdo->prepare("INSERT INTO audit_logs (user_id, action, entity_type, entity_id, ip_hash) VALUES (:uid, :action, :etype, :eid, :ip)");
        $stmt->execute([
            'uid' => $userId,
            'action' => $action,
            'etype' => $entityType,
            'eid' => $entityId,
            'ip' => $ipHash,
        ]);
    } catch (Throwable $e) {
        error_log('AUDIT_LOG_ERROR ' . $e->getMessage());
    }
}

/** Record abuse signal for future blocking/threshold. */
function abuse_signal(?int $userId, string $signalType, int $score = 1, array $meta = []): void
{
    $pdo = db();
    try {
        $stmt = $pdo->prepare("INSERT INTO abuse_signals (user_id, signal_type, score, meta_json) VALUES (:uid, :st, :score, :meta)");
        $stmt->execute([
            'uid' => $userId,
            'st' => $signalType,
            'score' => $score,
            'meta' => $meta !== [] ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
        ]);
    } catch (Throwable $e) {
        error_log('ABUSE_SIGNAL_ERROR ' . $e->getMessage());
    }
}
