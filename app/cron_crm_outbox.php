<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/crm_repo.php';

// No auto-delivery by default (production minimal). Enable explicitly via env var.
$auto = getenv('CRM_AUTO_SEND') === '1';
$result = ['processed' => 0, 'sent' => 0, 'failed' => 0, 'auto_send' => $auto];
if ($auto) {
    $result = crm_process_outbox_due(500);
    $result['auto_send'] = true;
} else {
    $result['auto_send'] = false;
}

$logDir = dirname(__DIR__) . '/storage/logs';
if (is_dir($logDir) && is_writable($logDir)) {
    $line = sprintf(
        "[%s] crm_outbox_stub processed=%d sent=%d failed=%d\n",
        date('c'),
        (int)($result['processed'] ?? 0),
        (int)($result['sent'] ?? 0),
        (int)($result['failed'] ?? 0)
    );
    @file_put_contents($logDir . '/cron_last_run.txt', trim($line));
}

echo json_encode(['success' => true] + $result, JSON_UNESCAPED_UNICODE) . PHP_EOL;
