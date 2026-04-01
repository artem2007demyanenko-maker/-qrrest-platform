<?php
/**
 * Trial / paywall: helpers for restaurant panel. Dashboard, setup, qr_print always allowed.
 * Revenue, CRM, upsells: show upgrade block if trial expired and no paid plan.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
if (file_exists(__DIR__ . '/billing.php')) {
    require_once __DIR__ . '/billing.php';
}

/**
 * Whether the current user must see paywall (trial expired, no paid subscription).
 * Safe when billing not ready: returns false (no block).
 */
function trial_guard_requires_upgrade(): bool
{
    $user = auth_user();
    if (!$user || empty($user['id'])) {
        return false;
    }
    if (!function_exists('billing_trial_required_to_continue')) {
        return false;
    }
    return billing_trial_required_to_continue((int)$user['id']);
}

/**
 * Trial info for banner. Returns same shape as billing_get_trial_info; empty safe when billing missing.
 */
function trial_guard_trial_info(): array
{
    $user = auth_user();
    if (!$user || empty($user['id'])) {
        return ['is_trial' => false, 'trial_ends_at' => null, 'days_left' => 0, 'is_expired' => false, 'has_active_paid_plan' => false];
    }
    if (!function_exists('billing_get_trial_info')) {
        return ['is_trial' => false, 'trial_ends_at' => null, 'days_left' => 0, 'is_expired' => false, 'has_active_paid_plan' => false];
    }
    return billing_get_trial_info((int)$user['id'], null);
}
