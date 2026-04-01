<?php

/**
 * Strict validation for Yandex Maps review URLs (settings + guest-facing CTA).
 */

function normalize_yandex_maps_review_url(string $s): string {
    $s = trim($s);
    if ($s === '') {
        return '';
    }
    if (!preg_match('~^https?://~i', $s)) {
        $s = 'https://' . $s;
    }
    return $s;
}

/**
 * Allowed hostnames (case-insensitive, no subdomains except maps.*).
 */
function yandex_maps_review_allowed_hosts(): array {
    return ['yandex.ru', 'maps.yandex.ru', 'yandex.com', 'maps.yandex.com'];
}

function yandex_maps_review_url_is_valid(string $url): bool {
    $url = trim($url);
    if ($url === '') {
        return false;
    }
    $normalized = normalize_yandex_maps_review_url($url);
    if (!filter_var($normalized, FILTER_VALIDATE_URL)) {
        return false;
    }
    $parts = parse_url($normalized);
    if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
        return false;
    }
    $scheme = strtolower((string)$parts['scheme']);
    if ($scheme !== 'http' && $scheme !== 'https') {
        return false;
    }
    $host = strtolower((string)$parts['host']);
    if (!in_array($host, yandex_maps_review_allowed_hosts(), true)) {
        return false;
    }
    $path = (string)($parts['path'] ?? '');
    $hasMaps = (strpos($path, '/maps/') !== false);
    $hasOrg = (strpos($path, '/org/') !== false);
    $hasReviews = (strpos($path, '/reviews') !== false);
    return $hasMaps || $hasOrg || $hasReviews;
}
