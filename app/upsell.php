<?php

require_once __DIR__ . '/db.php';

if (!function_exists('menu_upsell_rules_exists')) {
    function menu_upsell_rules_exists(): bool
    {
        if (function_exists('db_table_exists')) {
            return db_table_exists('menu_upsell_rules');
        }
        try {
            $pdo = db();
            $stmt = $pdo->query("SHOW TABLES LIKE 'menu_upsell_rules'");
            return $stmt && $stmt->rowCount() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}

/**
 * Simple rule-based upsell suggestions for a single trigger item.
 *
 * @param int $restaurantId
 * @param int $itemId trigger item id (menu_items.id)
 * @param int $limit max suggestions to return
 * @return array<int, array{id:int,name:string,price:float,image:?string,description:string}>
 */
function get_upsell_suggestions(int $restaurantId, int $itemId, int $limit = 3): array
{
    if ($restaurantId <= 0 || $itemId <= 0 || $limit <= 0) {
        return [];
    }
    if (!menu_upsell_rules_exists()) {
        return [];
    }

    $pdo = db();
    $limit = (int)$limit;

    $enabledFilter = '';
    if (file_exists(__DIR__ . '/schema_guard.php')) {
        require_once __DIR__ . '/schema_guard.php';
    }
    if (function_exists('db_column_exists') && db_column_exists('menu_upsell_rules', 'enabled')) {
        $enabledFilter = ' AND (r.enabled IS NULL OR r.enabled = 1)';
    }
    $sql = "
        SELECT m.id, m.name, m.price, m.image_path, m.image_url, m.description
        FROM menu_upsell_rules r
        INNER JOIN menu_items m
            ON m.id = r.suggest_item_id
           AND m.restaurant_id = r.restaurant_id
           AND m.available = 1
        WHERE r.restaurant_id = :rest
          AND r.trigger_item_id = :trigger
          AND r.suggest_item_id <> :trigger
          $enabledFilter
        ORDER BY r.priority DESC, r.id DESC
        LIMIT {$limit}
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':rest'    => $restaurantId,
        ':trigger' => $itemId,
    ]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $row) {
        $out[] = [
            'id'          => (int)$row['id'],
            'name'        => (string)$row['name'],
            'price'       => (float)$row['price'],
            'image'       => menu_item_image_url($row),
            'description' => (string)($row['description'] ?? ''),
        ];
    }
    return $out;
}

