#!/usr/bin/env php
<?php
/**
 * Smoke check: verify critical tables/columns exist (after migrations).
 * Usage: php scripts/check_schema.php [--json]
 * Exit 0 = all present, 1 = missing or DB error.
 */

$baseDir = dirname(__DIR__);
$config = require $baseDir . '/app/config.php';
$json = in_array('--json', $argv ?? [], true);

$requiredTables = ['users', 'restaurants', 'plans', 'subscriptions', 'invoices', 'payments', 'referral_codes', 'usage_metrics_daily', 'security_rate_limits'];
$requiredColumns = [['restaurants', 'deleted_at']];

$missing = [];
$errors = [];

try {
    $db = $config['db'] ?? null;
    if (!$db || empty($db['host']) || empty($db['name'])) {
        $errors[] = 'config_missing';
        if (!$json) {
            echo "ERROR: DB config missing\n";
        }
    } else {
        $dsn = "mysql:host={$db['host']};dbname={$db['name']};charset=" . ($db['charset'] ?? 'utf8mb4');
        $pdo = new PDO($dsn, $db['user'] ?? '', $db['pass'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->query("SELECT 1");

        foreach ($requiredTables as $t) {
            $stmt = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t LIMIT 1");
            $stmt->execute(['t' => $t]);
            if (!$stmt->fetchColumn()) {
                $missing[] = $t;
            }
        }
        foreach ($requiredColumns as $pair) {
            [$table, $col] = $pair;
            $stmt = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c LIMIT 1");
            $stmt->execute(['t' => $table, 'c' => $col]);
            if (!$stmt->fetchColumn()) {
                $missing[] = $table . '.' . $col;
            }
        }
    }
} catch (Throwable $e) {
    $errors[] = $e->getMessage();
}

if ($json) {
    echo json_encode([
        'ok' => empty($missing) && empty($errors),
        'missing' => $missing,
        'errors' => $errors,
    ], JSON_UNESCAPED_UNICODE) . "\n";
} else {
    if (!empty($errors)) {
        foreach ($errors as $e) {
            echo "ERROR: $e\n";
        }
    }
    if (!empty($missing)) {
        echo "MISSING: " . implode(', ', $missing) . "\n";
    }
    if (empty($missing) && empty($errors)) {
        echo "OK: all critical tables/columns present\n";
    }
}

exit((empty($missing) && empty($errors)) ? 0 : 1);
