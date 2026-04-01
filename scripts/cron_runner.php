<?php
/**
 * Single entry point for cron jobs. Use a lock file to avoid parallel runs.
 * Jobs: crm_outbox (mark pending as sent stub), optional billing/lead scoring.
 * Run: php scripts/cron_runner.php
 */

$rid = bin2hex(random_bytes(4));
$projectRoot = dirname(__DIR__);
$lockFp = null;
$cronRunId = null;

try {
    $scriptDir = __DIR__;
    $storageLogs = $projectRoot . '/storage/logs';
    if (!is_dir($storageLogs)) {
        @mkdir($storageLogs, 0775, true);
    }
    $lockFile = $storageLogs . '/cron_runner.lock';
    $lockFp = @fopen($lockFile, 'c');
    if (!$lockFp) {
        throw new RuntimeException('Cannot open lock file');
    }
    if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
        fclose($lockFp);
        echo "CRON_SKIP: another instance running (rid=$rid)\n";
        exit(0);
    }

    if (!function_exists('app_log')) {
        require_once $projectRoot . '/app/logging.php';
    }
    app_log("cron_runner start rid=$rid", 'info');

    require_once $projectRoot . '/app/config.php';
    require_once $projectRoot . '/app/db.php';

    $config = require $projectRoot . '/app/config.php';
    $pdo = db();

    $startedAt = gmdate('Y-m-d H:i:s');
    try {
        $ins = $pdo->prepare("INSERT INTO cron_runs (job_name, status, started_at) VALUES ('cron_runner', 'running', :started)");
        $ins->execute(['started' => $startedAt]);
        $cronRunId = (int)$pdo->lastInsertId();
    } catch (Throwable $e) {
        $cronRunId = null;
    }

    // 1) CRM outbox: mark pending where scheduled_at <= NOW() as processed (stub, no real send)
    if (file_exists($projectRoot . '/app/schema_guard.php')) {
        require_once $projectRoot . '/app/schema_guard.php';
    }
    $crmProcessed = 0;
    if (function_exists('db_table_exists') && db_table_exists('crm_outbox')) {
        try {
            $stmt = $pdo->prepare("
                UPDATE crm_outbox
                SET status = 'processed', updated_at = NOW()
                WHERE status = 'pending' AND scheduled_at <= NOW()
            ");
            $stmt->execute();
            $crmProcessed = $stmt->rowCount();
        } catch (Throwable $e) {
            error_log("STABILITY_ERROR cron crm_outbox rid=$rid " . $e->getMessage());
            app_log("cron crm_outbox error rid=$rid " . $e->getMessage(), 'error');
        }
    }
    app_log("cron_runner crm_outbox processed=$crmProcessed rid=$rid", 'info');

    // 2) Optional: trial/billing maintenance (no-op in v1 if no helper)
    if (function_exists('db_table_exists') && db_table_exists('subscriptions')) {
        // Placeholder: future trial expiry notifications or billing sync
    }

    // 3) Automatic retention suggestions (inactive guests → crm_retention_draft, no send)
    $retentionDraftsCreated = 0;
    if (!function_exists('is_demo_mode') || !is_demo_mode()) {
        if (file_exists($projectRoot . '/app/guest_retention.php')) {
            require_once $projectRoot . '/app/guest_retention.php';
        }
        if (file_exists($projectRoot . '/app/growth_engine_arch.php')) {
            require_once $projectRoot . '/app/growth_engine_arch.php';
        }
        if (function_exists('db_table_exists') && db_table_exists('guest_visits') && db_table_exists('growth_engine_suggestions')) {
            try {
                $stmt = $pdo->query("SELECT DISTINCT restaurant_id FROM guest_visits WHERE restaurant_id > 0");
                $restaurantIds = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
                foreach ($restaurantIds as $ridRow) {
                    $restId = (int)$ridRow;
                    if ($restId <= 0) {
                        continue;
                    }
                    if (!function_exists('get_retention_opportunities')) {
                        break;
                    }
                    $opps = get_retention_opportunities($restId);
                    if (empty($opps)) {
                        continue;
                    }
                    foreach ($opps as $opp) {
                        $payload = json_encode([
                            'guest_contact' => (string)($opp['guest_contact'] ?? ''),
                            'guest_name'    => $opp['guest_name'] ?? null,
                            'message'       => (string)($opp['message'] ?? 'We miss you! Come back this week and enjoy a special offer.'),
                        ], JSON_UNESCAPED_UNICODE);
                        $title = 'Retention draft for ' . (string)($opp['guest_contact'] ?? '');
                        $skip = function_exists('growth_engine_suggestion_duplicate_exists')
                            ? growth_engine_suggestion_duplicate_exists($pdo, $restId, 'crm_retention_draft', $payload, $title)
                            : false;
                        if ($skip) {
                            continue;
                        }
                        $hasStatus = function_exists('db_column_exists') && db_column_exists('growth_engine_suggestions', 'status');
                        if ($hasStatus) {
                            $ins = $pdo->prepare("INSERT INTO growth_engine_suggestions (restaurant_id, type, title, description, payload_json, priority, source, status) VALUES (?, 'crm_retention_draft', ?, ?, ?, 'medium', 'guest_retention', 'pending')");
                        } else {
                            $ins = $pdo->prepare("INSERT INTO growth_engine_suggestions (restaurant_id, type, title, description, payload_json) VALUES (?, 'crm_retention_draft', ?, ?, ?)");
                        }
                        $ins->execute([$restId, $title, 'Draft comeback message for inactive guest (auto-generated, review before sending).', $payload]);
                        $retentionDraftsCreated++;
                    }
                }
            } catch (Throwable $e) {
                error_log("STABILITY_ERROR cron retention_drafts rid=$rid " . $e->getMessage());
                if (function_exists('app_log')) {
                    app_log("cron retention_drafts error rid=$rid " . $e->getMessage(), 'error');
                }
            }
        }
    }

    // 4) Optional: lead scoring refresh
    if (file_exists($projectRoot . '/app/lead_scoring.php')) {
        try {
            require_once $projectRoot . '/app/lead_scoring.php';
            if (function_exists('lead_scoring_refresh')) {
                lead_scoring_refresh();
                app_log("cron_runner lead_scoring_refresh rid=$rid", 'info');
            }
        } catch (Throwable $e) {
            error_log("STABILITY_ERROR cron lead_scoring rid=$rid " . $e->getMessage());
        }
    }

    $now = gmdate('Y-m-d\TH:i:s\Z');
    @file_put_contents($storageLogs . '/cron_last_run.txt', $now);
    app_log("cron_runner done rid=$rid retentionDraftsCreated=$retentionDraftsCreated", 'info');

    if ($cronRunId !== null) {
        try {
            $fin = gmdate('Y-m-d H:i:s');
            $details = json_encode(['crm_processed' => $crmProcessed, 'retention_drafts_created' => $retentionDraftsCreated, 'rid' => $rid], JSON_UNESCAPED_UNICODE);
            $upd = $pdo->prepare("UPDATE cron_runs SET status = 'success', finished_at = :fin, details_json = :details WHERE id = :id");
            $upd->execute(['fin' => $fin, 'details' => $details, 'id' => $cronRunId]);
        } catch (Throwable $e) {
            // ignore
        }
    }

    if ($lockFp) {
        flock($lockFp, LOCK_UN);
        fclose($lockFp);
    }
    exit(0);
} catch (Throwable $e) {
    if ($cronRunId !== null && isset($pdo)) {
        try {
            $fin = gmdate('Y-m-d H:i:s');
            $details = json_encode(['error' => $e->getMessage(), 'rid' => $rid], JSON_UNESCAPED_UNICODE);
            $upd = $pdo->prepare("UPDATE cron_runs SET status = 'failed', finished_at = :fin, details_json = :details WHERE id = :id");
            $upd->execute(['fin' => $fin, 'details' => $details, 'id' => $cronRunId]);
        } catch (Throwable $e2) {
            // ignore
        }
    }
    if (isset($lockFp) && $lockFp) {
        @flock($lockFp, LOCK_UN);
        @fclose($lockFp);
    }
    error_log("STABILITY_ERROR cron_runner rid=$rid " . $e->getMessage() . " " . $e->getFile() . ":" . $e->getLine());
    if (function_exists('app_log')) {
        app_log("cron_runner exception rid=$rid " . $e->getMessage(), 'error');
    }
    if (function_exists('app_error_log')) {
        app_error_log('cron_runner', $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()], 'error', $rid);
    }
    exit(1);
}
