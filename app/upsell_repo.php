<?php
// app/upsell_repo.php — contextual upsell by base cart items

if (file_exists(__DIR__ . '/schema_guard.php')) {
    require_once __DIR__ . '/schema_guard.php';
}

function upsell_table_exists(): bool
{
    if (function_exists('db_table_exists')) {
        return db_table_exists('menu_item_upsells');
    }
    try {
        $pdo = db();
        $stmt = $pdo->query("SHOW TABLES LIKE 'menu_item_upsells'");
        return $stmt && $stmt->rowCount() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * @return array<int, array{id:int, name:string, price:float, image:?string, description:string}>
 */
function upsell_get_for_base_items(int $restaurantId, array $baseIds, int $limit = 6): array
{
    $baseIds = array_map('intval', array_filter($baseIds));
    if ($baseIds === []) {
        return [];
    }
    $pdo = db();
    $placeholders = implode(',', array_fill(0, count($baseIds), '?'));
    $exclude = implode(',', array_map('intval', $baseIds));
    $sql = "
        SELECT m.id, m.name, m.price, m.image_path, m.description, m.category_id
        FROM menu_item_upsells u
        INNER JOIN menu_items m ON m.id = u.upsell_item_id AND m.restaurant_id = u.restaurant_id AND m.available = 1
        WHERE u.restaurant_id = ? AND u.active = 1
          AND u.base_item_id IN ($placeholders)
          AND u.upsell_item_id NOT IN ($exclude)
        GROUP BY m.id
        ORDER BY SUM(u.weight) DESC, m.id DESC
        LIMIT " . (int)$limit;
    $params = array_merge([$restaurantId], $baseIds);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[(int)$row['id']] = [
            'id' => (int)$row['id'],
            'name' => (string)$row['name'],
            'price' => (float)$row['price'],
            'image' => menu_item_image_url($row),
            'description' => (string)($row['description'] ?? ''),
            'category_id' => isset($row['category_id']) ? (int)$row['category_id'] : null,
        ];
    }
    return array_values($out);
}

/**
 * @return array<int, array{id:int, name:string, price:float, image:?string, description:string}>
 */
function upsell_fallback_popular(int $restaurantId, int $limit = 6): array
{
    $pdo = db();
    $hasOrderItemsMenuItem = function_exists('db_column_exists') && db_column_exists('order_items', 'menu_item_id');
    if ($hasOrderItemsMenuItem) {
        $stmt = $pdo->prepare("
            SELECT m.id, m.name, m.price, m.image_path, m.description, m.category_id
            FROM menu_items m
            INNER JOIN order_items oi ON oi.menu_item_id = m.id
            INNER JOIN orders o ON o.id = oi.order_id AND o.restaurant_id = m.restaurant_id
            WHERE m.restaurant_id = ? AND m.available = 1
            GROUP BY m.id, m.name, m.price, m.image_path, m.description, m.category_id
            ORDER BY COUNT(*) DESC, m.id DESC
            LIMIT " . (int)$limit);
        $stmt->execute([$restaurantId]);
    } else {
        $stmt = $pdo->prepare("
            SELECT id, name, price, image_path, description, category_id
            FROM menu_items
            WHERE restaurant_id = ? AND available = 1
            ORDER BY id DESC
            LIMIT " . (int)$limit);
        $stmt->execute([$restaurantId]);
    }
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[] = [
            'id' => (int)$row['id'],
            'name' => (string)$row['name'],
            'price' => (float)$row['price'],
            'image' => menu_item_image_url($row),
            'description' => (string)($row['description'] ?? ''),
            'category_id' => isset($row['category_id']) ? (int)$row['category_id'] : null,
        ];
    }
    return $out;
}
