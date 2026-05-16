<?php
declare(strict_types=1);

/**
 * Owner post-deploy smoke: validate health.php payload.
 *
 * Usage:
 *   php scripts/owner_post_deploy_smoke_health.php "https://example.com"
 *
 * Exits:
 *   0 - pass
 *   1 - fail
 */

function fail(string $msg, array $ctx = []): void
{
    fwrite(STDERR, "FAIL owner-health: {$msg}\n");
    if ($ctx) {
        fwrite(STDERR, json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    }
    exit(1);
}

$baseUrl = $argv[1] ?? '';
if ($baseUrl === '') {
    fail('Missing baseUrl argument');
}

// We rely on the app itself for correct JSON. Any network/TLS issue is treated as smoke failure.
$healthUrl = rtrim($baseUrl, '/') . '/health.php';
$raw = @file_get_contents($healthUrl);
if ($raw === false) {
    fail('Could not fetch health.php', ['url' => $healthUrl]);
}

$data = json_decode($raw, true);
if (!is_array($data)) {
    fail('health.php did not return valid JSON', ['url' => $healthUrl, 'raw_head' => substr($raw, 0, 300)]);
}

$db = $data['db'] ?? null;
if ($db !== 'ok' && $db !== 'config_missing' && $db !== 'error') {
    // Keep this permissive: app can mark degraded, but scripts expects db ok.
    fail('Unexpected health db value', ['db' => $db]);
}

if ($db !== 'ok') {
    fail('DB is not ok', ['db' => $db, 'status' => $data['status'] ?? null, 'message' => $data['message'] ?? null]);
}

$writable = $data['writable_checks'] ?? null;
if (!is_array($writable)) {
    fail('writable_checks missing or not an array', ['writable_checks' => $writable]);
}

$logsOk = array_key_exists('logs', $writable) ? (bool)$writable['logs'] : null;
$backupsOk = array_key_exists('backups', $writable) ? (bool)$writable['backups'] : null;

// Requirement: logs/backups true/false and db ok.
echo "PASS owner-health: db=ok logs=" . var_export($logsOk, true) . " backups=" . var_export($backupsOk, true) . "\n";
exit(0);

