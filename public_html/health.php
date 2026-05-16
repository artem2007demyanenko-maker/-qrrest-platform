<?php
/**
 * Health endpoint. No bootstrap auth — minimal checks.
 * Always 200. status: ok | degraded | error.
 * Env-based config; app_env, version, writable_checks, cron_status when available.
 */

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

/**
 * Best-effort writable check for directories.
 * is_writable() can be misleading in some container/volume setups, so we also try a tiny probe write.
 */
function health_is_dir_effectively_writable(string $dir): bool
{
    if (!is_dir($dir)) {
        return false;
    }
    if (is_writable($dir)) {
        return true;
    }
    $probe = rtrim($dir, '/\\') . '/.health_probe_' . bin2hex(random_bytes(4));
    $fh = @fopen($probe, 'wb');
    if ($fh === false) {
        return false;
    }
    @fwrite($fh, 'ok');
    @fclose($fh);
    @unlink($probe);
    return true;
}

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
    $writableMeta = [];
    $checks = [
        'logs' => [
            __DIR__ . '/../storage/logs',
        ],
        // In production backups live in /var/www/html/backups (compose volume), not storage/backups.
        // Keep storage/backups as a legacy fallback for older/local layouts.
        'backups' => [
            __DIR__ . '/../backups',
            __DIR__ . '/../storage/backups',
        ],
        // Dish images are served from uploads; include this in health to catch FS regressions early.
        'uploads' => [
            __DIR__ . '/uploads',
            __DIR__ . '/../public_html/uploads',
        ],
    ];
    foreach ($checks as $key => $candidates) {
        $resolved = null;
        foreach ($candidates as $candidate) {
            if (!is_dir($candidate)) {
                continue;
            }
            $resolved = $candidate;
            $writable[$key] = health_is_dir_effectively_writable($candidate);
            $writableMeta[$key] = [
                'checked_path' => $candidate,
                'realpath' => realpath($candidate) ?: $candidate,
            ];
            break;
        }
        if ($resolved === null) {
            $writable[$key] = false;
            $writableMeta[$key] = [
                'checked_path' => $candidates[0] ?? null,
                'realpath' => null,
            ];
        }
    }
    if (!empty($writable)) {
        $out['writable_checks'] = $writable;
        $out['writable_checks_meta'] = $writableMeta;
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
