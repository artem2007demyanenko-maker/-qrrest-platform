<?php

require_once __DIR__ . '/../app/bootstrap.php';

$tableId = isset($_REQUEST['table_id']) ? (int)$_REQUEST['table_id'] : 0;
if ($tableId > 0) {
    $target = qr_public_build_url('/qr.php', ['table_id' => $tableId]);
    header('Location: ' . $target, true, $_SERVER['REQUEST_METHOD'] === 'POST' ? 303 : 302);
    exit;
}

$target = qr_public_build_url('/qr.php', []);
header('Location: ' . $target, true, $_SERVER['REQUEST_METHOD'] === 'POST' ? 303 : 302);
exit;
