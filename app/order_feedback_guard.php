<?php
/**
 * Guest order feedback: shared trust checks (session last_order_* context).
 * No feedback token may be minted or accepted without this context.
 */

if (!function_exists('order_feedback_track_context_valid')) {
    /**
     * True iff this browser session is the same checkout flow that created the order
     * (same as order_track.php Mode 2 validation).
     */
    function order_feedback_track_context_valid(int $restaurantId, int $tableId, int $orderId): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return false;
        }
        if ($restaurantId <= 0 || $tableId <= 0 || $orderId <= 0) {
            return false;
        }
        $key = 'last_order_' . $restaurantId . '_' . $tableId;
        return isset($_SESSION[$key]) && (int)$_SESSION[$key] === $orderId;
    }
}

if (!function_exists('order_feedback_mint_token')) {
    function order_feedback_mint_token(int $orderId): string
    {
        $token = bin2hex(random_bytes(16));
        $_SESSION['order_feedback_token_' . $orderId] = $token;
        return $token;
    }
}
