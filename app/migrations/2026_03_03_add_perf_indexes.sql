-- 2026-03-03: Performance indexes for owner dashboard analytics (base subquery + joins).
-- Idempotent: checks INFORMATION_SCHEMA and only adds missing indexes. Safe to run multiple times.

-- ORDERS: covering index for base subquery (restaurant_id, payment_status, order_status, created_at)
SET @exists := (
  SELECT COUNT(1) FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'orders'
    AND INDEX_NAME = 'idx_orders_base_filter'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE `orders` ADD INDEX `idx_orders_base_filter` (`restaurant_id`, `payment_status`, `order_status`, `created_at`);',
  'SELECT ''idx_orders_base_filter exists'';'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ORDER_ITEMS: (order_id, menu_item_id) for join order_items + menu_items on order_id and menu_item_id
SET @exists := (
  SELECT COUNT(1) FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'order_items'
    AND INDEX_NAME = 'idx_order_items_order_menu'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE `order_items` ADD INDEX `idx_order_items_order_menu` (`order_id`, `menu_item_id`);',
  'SELECT ''idx_order_items_order_menu exists'';'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- MENU_ITEMS: (restaurant_id, category_id) for categories join/filter
SET @exists := (
  SELECT COUNT(1) FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'menu_items'
    AND INDEX_NAME = 'idx_menu_items_restaurant_category'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE `menu_items` ADD INDEX `idx_menu_items_restaurant_category` (`restaurant_id`, `category_id`);',
  'SELECT ''idx_menu_items_restaurant_category exists'';'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- MENU_CATEGORIES: restaurant_id for multi-tenant join
SET @exists := (
  SELECT COUNT(1) FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'menu_categories'
    AND INDEX_NAME = 'idx_menu_categories_restaurant'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE `menu_categories` ADD INDEX `idx_menu_categories_restaurant` (`restaurant_id`);',
  'SELECT ''idx_menu_categories_restaurant exists'';'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
