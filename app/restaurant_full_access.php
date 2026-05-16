<?php

require_once __DIR__ . '/db.php';

if (!function_exists('restaurant_has_full_access_override')) {
    /**
     * Scoped bypass for the dedicated test restaurant.
     * Current canonical target: restaurant with subdomain "test".
     */
    function restaurant_has_full_access_override(int $restaurantId, ?array $restaurant = null): bool
    {
        $restaurantId = (int)$restaurantId;
        if ($restaurant !== null) {
            $sub = strtolower(trim((string)($restaurant['subdomain'] ?? '')));
            if ($sub === 'test') {
                return true;
            }
            if ($restaurantId > 0 && (int)($restaurant['id'] ?? 0) === $restaurantId && $sub !== '') {
                return false;
            }
        }
        if ($restaurantId <= 0 || !function_exists('db')) {
            return false;
        }

        static $cache = [];
        if (array_key_exists($restaurantId, $cache)) {
            return $cache[$restaurantId];
        }

        try {
            $pdo = db();
            $stmt = $pdo->prepare('SELECT subdomain FROM restaurants WHERE id = ? LIMIT 1');
            $stmt->execute([$restaurantId]);
            $sub = strtolower(trim((string)$stmt->fetchColumn()));
            $cache[$restaurantId] = ($sub === 'test');
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('restaurant_full_access lookup rest_id=' . $restaurantId . ' ' . $e->getMessage());
            }
            $cache[$restaurantId] = false;
        }

        return $cache[$restaurantId];
    }
}
