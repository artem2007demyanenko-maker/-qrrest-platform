<?php
/**
 * Plan definitions for FREE / GROWTH / PRO (no payments yet).
 * Used by subscription helpers for passive limit/feature checks only.
 */

return [
    'free' => [
        'name'           => 'Free',
        'max_orders'     => 50,
        'crm_enabled'    => false,
        'upsell_enabled' => false,
        'loyalty_enabled'=> false,
    ],
    'growth' => [
        'name'           => 'Growth',
        'max_orders'     => 500,
        'crm_enabled'    => true,
        'upsell_enabled' => true,
        'loyalty_enabled'=> false,
    ],
    'pro' => [
        'name'           => 'Pro',
        'max_orders'     => null, // unlimited
        'crm_enabled'    => true,
        'upsell_enabled' => true,
        'loyalty_enabled'=> true,
    ],
];
