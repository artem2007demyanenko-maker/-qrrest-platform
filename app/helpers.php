<?php


require_once __DIR__ . '/db.php';

function e(?string $value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Public URL for a menu item image (image_url or legacy image_path).
 * image_url paths like "menu/123/file.jpg" → /uploads/...; else → /storage/...
 */
if (!function_exists('menu_item_image_url')) {
    function menu_item_image_url(array $item): ?string
    {
        // Prefer local `image_path` (uploads), but keep legacy compatibility with `image_url`.
        $url = !empty($item['image_path'])
            ? $item['image_path']
            : (!empty($item['image_url']) ? $item['image_url'] : null);
        if ($url === null || $url === '') {
            return null;
        }

        // Allow full external URLs to be used as-is (if configured).
        if (is_string($url) && preg_match('#^https?://#i', $url) === 1) {
            return $url;
        }

        $publicRoot = dirname(__DIR__) . '/public_html';

        if (strpos($url, 'menu/') === 0) {
            $abs = $publicRoot . '/uploads/' . $url;
            return file_exists($abs) ? ('/uploads/' . $url) : null;
        }
        $abs = $publicRoot . '/storage/' . $url;
        return file_exists($abs) ? ('/storage/' . $url) : null;
    }
}

function redirect(string $url): void
{
    header("Location: {$url}");
    exit;
}

/**
 * Безопасный редирект: только относительные пути (начинаются с /) или текущий main_domain.
 * После header('Location: ...') всегда вызывается exit.
 *
 * Запрещённые примеры (unit-level self-check):
 *   //evil.com           — protocol-relative
 *   javascript:alert(1)  — схема не http/https
 *   http://user:pass@host — embedded credentials
 *   http://good.com%0d%0aLocation:evil — encoded CRLF
 *   http://evil.com      — внешний домен
 *   http://main.com.    — trailing dot в host
 */
function safe_redirect(string $url): void
{
    $url = trim($url);
    if ($url === '') {
        header('Location: /');
        exit;
    }
    // CRLF, NUL — запрет injection в заголовок
    if (strpbrk($url, "\r\n\0") !== false) {
        header('Location: /');
        exit;
    }
    // Закодированные CRLF
    $decoded = rawurldecode($url);
    if (strpbrk($decoded, "\r\n\0") !== false) {
        header('Location: /');
        exit;
    }
    // Сначала разбираем через parse_url для единообразия
    $parsed = parse_url($url);
    if ($parsed === false) {
        header('Location: /');
        exit;
    }
    // Относительный путь: только если начинается с одного /
    if (!isset($parsed['host']) && !isset($parsed['scheme'])) {
        $path = $parsed['path'] ?? '';
        if ($path !== '' && $path[0] === '/' && strpos($path, '//') !== 0) {
            $out = $path;
            if (!empty($parsed['query'])) {
                $out .= '?' . $parsed['query'];
            }
            if (!empty($parsed['fragment'])) {
                $out .= '#' . $parsed['fragment'];
            }
            header('Location: ' . $out);
            exit;
        }
    }
    // Protocol-relative (//...) — запретить
    if (isset($parsed['host']) && (!isset($parsed['scheme']) || $parsed['scheme'] === '')) {
        header('Location: /');
        exit;
    }
    // Только http/https
    $scheme = strtolower((string)($parsed['scheme'] ?? ''));
    if (!in_array($scheme, ['http', 'https'], true)) {
        header('Location: /');
        exit;
    }
    // Embedded credentials — запретить
    if (!empty($parsed['user']) || !empty($parsed['pass'])) {
        header('Location: /');
        exit;
    }
    $host = isset($parsed['host']) ? strtolower($parsed['host']) : '';
    $host = rtrim($host, '.');
    if ($host === '') {
        header('Location: /');
        exit;
    }
    $config = require __DIR__ . '/config.php';
    $main   = strtolower((string)($config['app']['main_domain'] ?? ''));
    $main   = rtrim($main, '.');
    $allowed = ($host === $main || $host === 'www.' . $main);
    if (!$allowed) {
        header('Location: /');
        exit;
    }
    header('Location: ' . $url);
    exit;
}


function generate_random_password(int $length = 10): string
{
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $out = '';
    $max = strlen($chars) - 1;
    for ($i = 0; $i < $length; $i++) {
        $out .= $chars[random_int(0, $max)];
    }
    return $out;
}


function slugify_restaurant(string $name): string
{
    $name = mb_strtolower($name, 'UTF-8');

    $map = [
        'а'=>'a','б'=>'b','в'=>'v','г'=>'g','д'=>'d','е'=>'e','ё'=>'e','ж'=>'zh','з'=>'z',
        'и'=>'i','й'=>'y','к'=>'k','л'=>'l','м'=>'m','н'=>'n','о'=>'o','п'=>'p','р'=>'r',
        'с'=>'s','т'=>'t','у'=>'u','ф'=>'f','х'=>'h','ц'=>'c','ч'=>'ch','ш'=>'sh','щ'=>'sch',
        'ъ'=>'','ы'=>'y','ь'=>'','э'=>'e','ю'=>'yu','я'=>'ya',
    ];
    $name = strtr($name, $map);
    $name = preg_replace('~[^a-z0-9]+~', '-', $name);
    $name = trim($name, '-');
    if ($name === '') {
        $name = 'rest-' . substr(md5((string)microtime(true)), 0, 6);
    }
    return $name;
}

/**
 * Лог действий.
 */
function log_action($userId, $restaurantId, string $action, string $message, string $level = 'info'): void
{
    // Берём PDO так же, как в остальном проекте
    $pdo = function_exists('db') ? db() : ($GLOBALS['pdo'] ?? null);
    if (!$pdo instanceof PDO) {
        return;
    }

    // Если есть наш новый хелпер add_log — используем его
    if (function_exists('add_log')) {
        try {
            add_log($pdo, [
                'user_id'       => $userId !== null ? (int)$userId : null,
                'restaurant_id' => $restaurantId !== null ? (int)$restaurantId : null,
                'level'         => $level,          // info / error / security и т.д.
                'action'        => $action,
                'message'       => $message,
            ]);
        } catch (Throwable $e) {
            // Лог не должен ломать приложение
        }
        return;
    }

    // Фолбэк, если add_log по какой-то причине не подключен
    try {
        $stmt = $pdo->prepare("
            INSERT INTO logs (user_id, restaurant_id, level, action, message, created_at)
            VALUES (:user_id, :restaurant_id, :level, :action, :message, NOW())
        ");
        $stmt->execute([
            ':user_id'       => $userId !== null ? (int)$userId : null,
            ':restaurant_id' => $restaurantId !== null ? (int)$restaurantId : null,
            ':level'         => $level,
            ':action'        => $action,
            ':message'       => $message,
        ]);
    } catch (Throwable $e) {
        // Тихо глушим
    }
}

