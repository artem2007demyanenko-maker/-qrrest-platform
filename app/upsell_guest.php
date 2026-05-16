<?php
/**
 * Guest-facing smart upsell settings (menu + cart layers).
 * Primary source: restaurants.guest_menu_upsell_* / guest_cart_upsell_*.
 * Backward-compatible fallback: smart_upsell_* and legacy special_offers_*.
 */

if (!function_exists('upsell_guest_mode_enabled')) {
    function upsell_guest_mode_enabled(array $restaurant, bool $planUpsellEnabled, string $mode = 'menu'): bool
    {
        if (!$planUpsellEnabled) {
            return false;
        }

        $mode = strtolower(trim($mode));
        if ($mode === 'cart') {
            if (isset($restaurant['guest_cart_upsell_enabled'])) {
                return (int)$restaurant['guest_cart_upsell_enabled'] === 1;
            }
            if (isset($restaurant['smart_upsell_cart_enabled'])) {
                return (int)$restaurant['smart_upsell_cart_enabled'] === 1;
            }
            if (isset($restaurant['smart_upsell_guest_enabled'])) {
                return (int)$restaurant['smart_upsell_guest_enabled'] === 1;
            }
        } else {
            if (isset($restaurant['guest_menu_upsell_enabled'])) {
                return (int)$restaurant['guest_menu_upsell_enabled'] === 1;
            }
            if (isset($restaurant['smart_upsell_menu_enabled'])) {
                return (int)$restaurant['smart_upsell_menu_enabled'] === 1;
            }
            if (isset($restaurant['smart_upsell_guest_enabled'])) {
                return (int)$restaurant['smart_upsell_guest_enabled'] === 1;
            }
        }

        if (array_key_exists('special_offers_enabled', $restaurant)) {
            return (int)$restaurant['special_offers_enabled'] === 1;
        }

        return true;
    }
}

if (!function_exists('upsell_guest_mode_max_items')) {
    function upsell_guest_mode_max_items(array $restaurant, string $mode = 'menu'): int
    {
        $mode = strtolower(trim($mode));
        if ($mode === 'cart') {
            if (isset($restaurant['guest_cart_upsell_limit'])) {
                return max(1, min(3, (int)$restaurant['guest_cart_upsell_limit']));
            }
            if (isset($restaurant['smart_upsell_cart_max'])) {
                return max(1, min(3, (int)$restaurant['smart_upsell_cart_max']));
            }
            if (isset($restaurant['smart_upsell_guest_max'])) {
                return max(1, min(3, (int)$restaurant['smart_upsell_guest_max']));
            }
        } else {
            if (isset($restaurant['guest_menu_upsell_limit'])) {
                return max(1, min(2, (int)$restaurant['guest_menu_upsell_limit']));
            }
            if (isset($restaurant['smart_upsell_menu_max'])) {
                return max(1, min(2, (int)$restaurant['smart_upsell_menu_max']));
            }
            if (isset($restaurant['smart_upsell_guest_max'])) {
                return max(1, min(2, (int)$restaurant['smart_upsell_guest_max']));
            }
        }

        if (!empty($restaurant['special_offers_limit'])) {
            return ($mode === 'cart')
                ? max(1, min(3, (int)$restaurant['special_offers_limit']))
                : max(1, min(2, (int)$restaurant['special_offers_limit']));
        }

        return ($mode === 'cart') ? 3 : 1;
    }
}

if (!function_exists('upsell_guest_mode_flags')) {
    /**
     * @return array<string,bool>
     */
    function upsell_guest_mode_flags(array $restaurant, string $mode = 'menu'): array
    {
        $mode = strtolower(trim($mode));

        if ($mode === 'cart') {
            $comboEnabled = isset($restaurant['guest_cart_combo_enabled'])
                ? ((int)$restaurant['guest_cart_combo_enabled'] === 1)
                : true;
            $comboLimit = isset($restaurant['guest_cart_combo_limit'])
                ? max(1, min(3, (int)$restaurant['guest_cart_combo_limit']))
                : 2;
            return [
                'allow_manual' => isset($restaurant['guest_cart_upsell_use_manual'])
                    ? ((int)$restaurant['guest_cart_upsell_use_manual'] === 1)
                    : (isset($restaurant['smart_upsell_cart_use_manual'])
                    ? ((int)$restaurant['smart_upsell_cart_use_manual'] === 1)
                    : true),
                'allow_contextual' => isset($restaurant['guest_cart_upsell_use_contextual'])
                    ? ((int)$restaurant['guest_cart_upsell_use_contextual'] === 1)
                    : (isset($restaurant['smart_upsell_cart_use_contextual'])
                    ? ((int)$restaurant['smart_upsell_cart_use_contextual'] === 1)
                    : true),
                'allow_popular_fallback' => isset($restaurant['guest_cart_upsell_use_popular'])
                    ? ((int)$restaurant['guest_cart_upsell_use_popular'] === 1)
                    : (isset($restaurant['smart_upsell_cart_use_popular'])
                    ? ((int)$restaurant['smart_upsell_cart_use_popular'] === 1)
                    : true),
                'allow_combo' => $comboEnabled,
                'combo_limit' => $comboLimit,
            ];
        }

        $manualOnly = isset($restaurant['guest_menu_upsell_manual_only'])
            ? ((int)$restaurant['guest_menu_upsell_manual_only'] === 1)
            : (isset($restaurant['smart_upsell_menu_manual_only'])
            ? ((int)$restaurant['smart_upsell_menu_manual_only'] === 1)
            : false);
        $menuComboEnabled = isset($restaurant['guest_menu_combo_enabled'])
            ? ((int)$restaurant['guest_menu_combo_enabled'] === 1)
            : true;
        $menuComboLimit = isset($restaurant['guest_menu_combo_limit'])
            ? max(1, min(2, (int)$restaurant['guest_menu_combo_limit']))
            : 1;

        return [
            'manual_only' => $manualOnly,
            'allow_manual' => true,
            'allow_contextual' => !$manualOnly,
            'allow_popular_fallback' => !$manualOnly,
            'allow_combo' => $menuComboEnabled,
            'combo_limit' => $menuComboLimit,
        ];
    }
}

if (!function_exists('upsell_guest_layer_enabled')) {
    function upsell_guest_layer_enabled(array $restaurant, bool $planUpsellEnabled): bool
    {
        // Backward-compatible alias (legacy single-layer behavior).
        return upsell_guest_mode_enabled($restaurant, $planUpsellEnabled, 'menu');
    }
}

if (!function_exists('upsell_guest_max_items')) {
    function upsell_guest_max_items(array $restaurant): int
    {
        // Backward-compatible alias (legacy single-layer behavior).
        return upsell_guest_mode_max_items($restaurant, 'menu');
    }
}
