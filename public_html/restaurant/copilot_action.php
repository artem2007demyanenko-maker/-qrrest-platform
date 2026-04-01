<?php
/**
 * AJAX: run a Copilot growth action (create draft/suggestion only).
 * POST action=create_combo|create_campaign|promote_menu_item|create_upsell
 * Security: require_login, require_current_restaurant, require_current_restaurant_role(['owner','admin']).
 * Demo: returns success but does not write to database.
 */

require_once __DIR__ . '/../../app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

require_login();
require_current_restaurant();
require_current_restaurant_role(['owner', 'admin']);

$restId = (int) ($currentRestaurant['id'] ?? 0);
$action = trim((string) ($_POST['action'] ?? $_GET['action'] ?? ''));

if ($restId <= 0 || $action === '') {
    echo json_encode(['success' => false, 'message' => 'Missing restaurant or action.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$out = ['success' => false, 'message' => 'Action not available.'];
if (file_exists(__DIR__ . '/../../app/ai_copilot.php')) {
    require_once __DIR__ . '/../../app/ai_copilot.php';
    if (function_exists('generate_copilot_action')) {
        try {
            $out = generate_copilot_action($restId, $action);
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('copilot_action ' . $e->getMessage());
            }
            $out = ['success' => false, 'message' => 'Could not create suggestion. Try again.'];
        }
    }
}

echo json_encode($out, JSON_UNESCAPED_UNICODE);
