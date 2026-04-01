#!/usr/bin/env php
<?php
/**
 * Schema audit: check critical and optional tables. Print missing/ok. Exit 0 if critical present, 1 otherwise.
 * Run from project root: php scripts/schema_audit.php
 * Or from container: docker compose exec -T web php scripts/schema_audit.php
 */

$base = dirname(__DIR__);
if (!file_exists($base . '/app/config.php')) {
    fwrite(STDERR, "schema_audit: config not found\n");
    exit(1);
}

$config = require $base . '/app/config.php';
$db = $config['db'] ?? null;
if (!$db || empty($db['host']) || empty($db['name'])) {
    fwrite(STDERR, "schema_audit: db config missing\n");
    exit(1);
}

try {
    $port = isset($db['port']) ? (int)$db['port'] : 3306;
    $dsn = "mysql:host={$db['host']};port={$port};dbname={$db['name']};charset=" . ($db['charset'] ?? 'utf8mb4');
    $pdo = new PDO($dsn, $db['user'] ?? '', $db['pass'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->query("SELECT 1");
} catch (Throwable $e) {
    fwrite(STDERR, "schema_audit: db connection failed - " . $e->getMessage() . "\n");
    exit(1);
}

$critical = ['users', 'restaurants', 'orders'];
$optional = ['plans', 'subscriptions', 'invoices', 'payments', 'lead_requests', 'app_error_logs', 'cron_runs', 'referral_codes', 'usage_metrics_daily', 'security_rate_limits'];

$check = function ($table) use ($pdo) {
    $stmt = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t LIMIT 1");
    $stmt->execute(['t' => $table]);
    return (bool)$stmt->fetchColumn();
};

$missingCritical = [];
foreach ($critical as $t) {
    if (!$check($t)) {
        $missingCritical[] = $t;
        echo "missing (critical): $t\n";
    } else {
        echo "ok (critical): $t\n";
    }
}

foreach ($optional as $t) {
    if (!$check($t)) {
        echo "missing (optional): $t\n";
    } else {
        echo "ok (optional): $t\n";
    }
}

if (count($missingCritical) > 0) {
    fwrite(STDERR, "schema_audit: critical tables missing: " . implode(', ', $missingCritical) . "\n");
    exit(1);
}
exit(0);
