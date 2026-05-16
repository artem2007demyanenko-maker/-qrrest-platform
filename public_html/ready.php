<?php
/**
 * Readiness probe. Stricter than health: DB must be reachable and critical tables present.
 * Always 200, JSON. status=error if DB down or critical schema missing.
 */

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$out = [
    'status' => 'ok',
    'db'     => 'ok',
    'time'   => gmdate('Y-m-d\TH:i:s\Z'),
];

try {
    $config = file_exists(__DIR__ . '/../app/config.php') ? require __DIR__ . '/../app/config.php' : [];
    $out['app_env'] = (string)($config['app']['env'] ?? 'local');

    $db = $config['db'] ?? null;
    if (!$db || empty($db['host']) || empty($db['name'])) {
        $out['db'] = 'config_missing';
        $out['status'] = 'error';
        echo json_encode($out, JSON_UNESCAPED_UNICODE);
        return;
    }

    $port = isset($db['port']) ? (int)$db['port'] : 3306;
    $dsn = "mysql:host={$db['host']};port={$port};dbname={$db['name']};charset=" . ($db['charset'] ?? 'utf8mb4');
    $pdo = new PDO($dsn, $db['user'] ?? '', $db['pass'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->query("SELECT 1");

    $criticalTables = ['users', 'restaurants', 'orders'];
    $missing = [];
    foreach ($criticalTables as $t) {
        $stmt = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t LIMIT 1");
        $stmt->execute(['t' => $t]);
        if (!$stmt->fetchColumn()) {
            $missing[] = $t;
        }
    }
    if (!empty($missing)) {
        $out['status'] = 'error';
        $out['db'] = 'schema_missing';
        $out['missing_tables'] = $missing;
    } else {
        $out['recent_cron_ok'] = null;
        try {
            $chk = $pdo->prepare("SELECT 1 FROM cron_runs WHERE status = 'success' AND started_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE) LIMIT 1");
            $chk->execute();
            $out['recent_cron_ok'] = (bool)$chk->fetchColumn();
        } catch (Throwable $e) {
            $out['recent_cron_ok'] = null;
        }
    }
} catch (Throwable $e) {
    $out['status'] = 'error';
    $out['db'] = 'error';
    $out['message'] = 'Internal error';
    if (!headers_sent()) {
        http_response_code(200);
    }
}

echo json_encode($out, JSON_UNESCAPED_UNICODE);
