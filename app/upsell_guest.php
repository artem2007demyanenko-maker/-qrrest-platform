<?php
/**
 * Guest-facing smart upsell: enable flag + max cards (1–3) for QR menu.
 * Uses restaurants.smart_upsell_guest_* if present; falls back to special_offers_* or defaults.
 */

if (!function_exists('upsell_guest_layer_enabled')) {
    function upsell_guest_layer_enabled(array $restaurant, bool $planUpsellEnabled): bool
    {
        if (!$planUpsellEnabled) {
            return false;
        }
        if (isset($restaurant['smart_upsell_guest_enabled'])) {
            return (int)$restaurant['smart_upsell_guest_enabled'] === 1;
        }
        if (array_key_exists('special_offers_enabled', $restaurant)) {
            return (int)$restaurant['special_offers_enabled'] === 1;
        }

        return true;
    }
}

if (!function_exists('upsell_guest_max_items')) {
    function upsell_guest_max_items(array $restaurant): int
    {
        if (isset($restaurant['smart_upsell_guest_max'])) {
            return max(1, min(3, (int)$restaurant['smart_upsell_guest_max']));
        }
        if (!empty($restaurant['special_offers_limit'])) {
            return max(1, min(3, (int)$restaurant['special_offers_limit']));
        }

        return 3;
    }
}
