<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../app/bootstrap.php';

// Legacy route wrapper: keep URL backward-compatible, enforce new waiter guard model.
require_waiter_access();

$target = '/staff/loyalty_scan.php';
$query = (string)($_SERVER['QUERY_STRING'] ?? '');
if ($query !== '') {
    $target .= '?' . $query;
}

header('Location: ' . $target, true, 302);
exit;

