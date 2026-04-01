<?php
/**
 * DISABLED: This endpoint was an unsafe public route (raw UID, no token, no tenant scope).
 * Use token-based lookup with restaurant scope instead (e.g. get_balance.php?token=... on restaurant subdomain).
 */
http_response_code(410);
header('Content-Type: application/json; charset=utf-8');
header('X-Loyalty-Disabled', '1');
echo json_encode(['ok' => false, 'error' => 'gone'], JSON_UNESCAPED_UNICODE);
