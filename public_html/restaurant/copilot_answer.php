<?php
/**
 * AJAX: answer a copilot question. Returns JSON. Read-only.
 */

require_once __DIR__ . '/../../app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

require_login();
require_current_restaurant();
require_current_restaurant_role(['owner', 'admin']);

$restId = (int) ($currentRestaurant['id'] ?? 0);
$question = trim((string) ($_POST['question'] ?? $_GET['question'] ?? ''));

$answer = '';
$suggestedActions = [];
if (file_exists(__DIR__ . '/../../app/ai_copilot.php')) {
    require_once __DIR__ . '/../../app/ai_copilot.php';
    try {
        $answer = answer_copilot_question($restId, $question);
        if (function_exists('get_copilot_suggested_actions')) {
            $suggestedActions = get_copilot_suggested_actions($restId, $question, $answer);
        }
    } catch (Throwable $e) {
        $answer = 'Sorry, I couldn\'t process that. Try asking about best sellers or peak hours.';
        if (function_exists('error_log')) {
            error_log('copilot_answer ' . $e->getMessage());
        }
    }
}

echo json_encode([
    'success' => true,
    'answer' => $answer,
    'suggested_actions' => $suggestedActions,
], JSON_UNESCAPED_UNICODE);
