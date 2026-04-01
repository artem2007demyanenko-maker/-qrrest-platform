<?php
/**
 * System Consistency Layer helper: flow_id + session keys.
 *
 * Goal:
 * - Create a stable flow_id for the current "checkout flow" in this PHP session.
 * - Propagate it via payload/meta_json into orders/upsell_events/CRM/growth_engine_suggestions.
 *
 * This file is intentionally safe:
 * - If session is missing/unavailable, functions become no-ops (best-effort).
 */

if (!function_exists('app_flow_id_generate')) {
    function app_flow_id_generate(): string
    {
        // Short hash (fast) instead of full UUID to reduce payload size.
        return bin2hex(random_bytes(8)); // 16 hex chars
    }
}

if (!function_exists('app_flow_id_get_current')) {
    /**
     * @return ?string flow_id for restaurant (or generic) in this PHP session.
     */
    function app_flow_id_get_current(?int $restaurantId = null): ?string
    {
        if (!isset($_SESSION) || !is_array($_SESSION)) {
            return null;
        }
        if ($restaurantId !== null && $restaurantId > 0) {
            if (isset($_SESSION['flow_id_by_rest']) && is_array($_SESSION['flow_id_by_rest'])) {
                $v = $_SESSION['flow_id_by_rest'][$restaurantId] ?? null;
                $v = is_string($v) ? trim($v) : null;
                return ($v !== null && $v !== '') ? $v : null;
            }
        }
        $v = $_SESSION['flow_id'] ?? null;
        $v = is_string($v) ? trim($v) : null;
        return ($v !== null && $v !== '') ? $v : null;
    }
}

if (!function_exists('app_flow_id_set_current')) {
    function app_flow_id_set_current(string $flowId, ?int $restaurantId = null): void
    {
        if (!isset($_SESSION) || !is_array($_SESSION)) {
            return;
        }
        if ($restaurantId !== null && $restaurantId > 0) {
            if (!isset($_SESSION['flow_id_by_rest']) || !is_array($_SESSION['flow_id_by_rest'])) {
                $_SESSION['flow_id_by_rest'] = [];
            }
            $_SESSION['flow_id_by_rest'][$restaurantId] = $flowId;
            return;
        }
        $_SESSION['flow_id'] = $flowId;
    }
}

if (!function_exists('app_flow_id_ensure_current')) {
    /**
     * @return string flow_id (created if missing)
     */
    function app_flow_id_ensure_current(?int $restaurantId = null): string
    {
        $existing = app_flow_id_get_current($restaurantId);
        if ($existing !== null) return $existing;
        $flowId = app_flow_id_generate();
        app_flow_id_set_current($flowId, $restaurantId);
        return $flowId;
    }
}

if (!function_exists('app_flow_id_inject_meta')) {
    /**
     * Inject flow_id into upsell_events.meta_json (best-effort).
     */
    function app_flow_id_inject_meta(array $meta, ?int $restaurantId = null): array
    {
        if (!array_key_exists('flow_id', $meta) || $meta['flow_id'] === null || $meta['flow_id'] === '') {
            $flowId = app_flow_id_get_current($restaurantId);
            if ($flowId !== null) {
                $meta['flow_id'] = $flowId;
            }
        }
        return $meta;
    }
}

if (!function_exists('app_flow_id_inject_payload_array')) {
    /**
     * Inject flow_id into CRM outbox payload array.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    function app_flow_id_inject_payload_array(array $payload, ?int $restaurantId = null): array
    {
        if (!array_key_exists('flow_id', $payload) || $payload['flow_id'] === null || $payload['flow_id'] === '') {
            $flowId = app_flow_id_get_current($restaurantId);
            if ($flowId !== null) {
                $payload['flow_id'] = $flowId;
            }
        }
        return $payload;
    }
}

if (!function_exists('app_flow_id_inject_payload_json')) {
    /**
     * Inject flow_id into growth_engine_suggestions.payload_json.
     */
    function app_flow_id_inject_payload_json(string $payloadJson, ?int $restaurantId = null): string
    {
        $payloadJson = (string)$payloadJson;
        if ($payloadJson === '') return $payloadJson;
        $payload = json_decode($payloadJson, true);
        if (!is_array($payload)) {
            return $payloadJson;
        }
        $payload = app_flow_id_inject_payload_array($payload, $restaurantId);
        $out = json_encode($payload, JSON_UNESCAPED_UNICODE);
        return is_string($out) ? $out : $payloadJson;
    }
}

if (!function_exists('app_upsell_session_key_get')) {
    /**
     * Stable session key for upsell_events dedupe, stored in PHP session.
     */
    function app_upsell_session_key_get(): string
    {
        if (!isset($_SESSION) || !is_array($_SESSION)) {
            // No PHP session: best-effort random to avoid fatal.
            return bin2hex(random_bytes(8));
        }
        $k = $_SESSION['upsell_session_key'] ?? null;
        if (!is_string($k) || trim($k) === '') {
            $k = bin2hex(random_bytes(8));
            $_SESSION['upsell_session_key'] = $k;
        }
        return $k;
    }
}

