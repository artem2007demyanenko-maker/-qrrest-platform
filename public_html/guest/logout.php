<?php
require_once __DIR__ . '/../../app/bootstrap.php';
$tid = (int)($_SESSION['guest_last_table_id'] ?? 0);
$ctx = (string)($_SESSION['guest_last_menu_context'] ?? '');
guest_logout();
if ($ctx === 'delivery') {
    header('Location: ' . qr_public_build_url('/qr.php', []));
} elseif ($tid > 0) {
    header('Location: ' . qr_public_build_url('/qr.php', ['table_id' => $tid]));
} else {
    header('Location: /');
}
exit;
