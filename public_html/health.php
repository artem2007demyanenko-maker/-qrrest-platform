<?php
/**
 * Health endpoint. No bootstrap auth — minimal checks.
 * Always 200. status: ok | degraded | error.
 * Env-based config; app_env, version, writable_checks, cron_status when available.
 */

header('Content-Type: application/json; charset=utf-8');

$out = [
    'status' => 'ok',
    'db'     => 'ok',
    'time'   => gmdate('Y-m-d\TH:i:s\Z'),
];

try {
    $config = file_exists(__DIR__ . '/../app/config.php') ? require __DIR__ . '/../app/config.php' : [];
    $out['app_env'] = (string)($config['app']['env'] ?? 'local');
    if (!empty($config['app'])) {
        $out['app_protocol'] = (string)($config['app']['protocol'] ?? '');
        $out['app_main_domain'] = (string)($config['app']['main_domain'] ?? '');
        $out['cookie_domain'] = (string)($config['app']['cookie_domain'] ?? '');
        $out['hsts'] = [
            'enabled'           => !empty($config['app']['hsts']),
            'include_subdomains' => !empty($config['app']['hsts_include_subdomains']),
            'preload'           => !empty($config['app']['hsts_preload']),
        ];
        $out['forwarded_proto'] = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? null;
    }
    if (!empty($config['version']['app_version'])) {
        $out['version'] = $config['version']['app_version'];
    } elseif (!empty($config['version']['git_sha'])) {
        $out['version'] = substr($config['version']['git_sha'], 0, 12);
    }

    $db = $config['db'] ?? null;
    if (!$db || empty($db['host']) || empty($db['name'])) {
        $out['db'] = 'config_missing';
        $out['status'] = 'degraded';
    } else {
        $port = isset($db['port']) ? (int)$db['port'] : 3306;
        $dsn = "mysql:host={$db['host']};port={$port};dbname={$db['name']};charset=" . ($db['charset'] ?? 'utf8mb4');
        $pdo = new PDO($dsn, $db['user'] ?? '', $db['pass'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->query("SELECT 1");

        $keyTables = ['plans', 'subscriptions', 'invoices', 'payments', 'referral_codes', 'usage_metrics_daily', 'security_rate_limits'];
        $missing = [];
        foreach ($keyTables as $t) {
            $stmt = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t LIMIT 1");
            $stmt->execute(['t' => $t]);
            if (!$stmt->fetchColumn()) {
                $missing[] = $t;
            }
        }
        $stRest = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t LIMIT 1");
        $stRest->execute(['t' => 'restaurants']);
        if ($stRest->fetchColumn()) {
            $stCol = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :col LIMIT 1");
            $stCol->execute(['t' => 'restaurants', 'col' => 'deleted_at']);
            if (!$stCol->fetchColumn()) {
                $missing[] = 'restaurants.deleted_at';
            }
        }
        if (!empty($missing)) {
            $out['status'] = 'degraded';
            $out['migrations_pending'] = $missing;
        }
    }

    $writable = [];
    $base = __DIR__ . '/../storage';
    foreach (['logs', 'backups'] as $sub) {
        $dir = $base . '/' . $sub;
        $writable[$sub] = is_dir($dir) ? is_writable($dir) : false;
    }
    if (!empty($writable)) {
        $out['writable_checks'] = $writable;
    }

    $cronFile = __DIR__ . '/../storage/logs/cron_last_run.txt';
    if (file_exists($cronFile) && is_readable($cronFile)) {
        $ts = @file_get_contents($cronFile);
        $out['cron_status'] = trim((string)$ts) ?: null;
    } else {
        $out['cron_status'] = null;
    }

    $out['recent_cron_ok'] = null;
    try {
        $chk = $pdo->prepare("SELECT 1 FROM cron_runs WHERE status = 'success' AND started_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE) LIMIT 1");
        $chk->execute();
        $out['recent_cron_ok'] = $chk->fetchColumn() ? true : false;
    } catch (Throwable $e) {
        $out['recent_cron_ok'] = null;
    }
} catch (Throwable $e) {
    $out['db'] = 'error';
    $out['status'] = 'error';
    $out['message'] = 'Internal error';
    $out['migrations_pending'] = $out['migrations_pending'] ?? [];
    if (!headers_sent()) {
        http_response_code(200);
    }
}

echo json_encode($out, JSON_UNESCAPED_UNICODE);
