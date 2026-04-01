-- 2026-03-02: add missing indexes (idempotent)
-- This script checks INFORMATION_SCHEMA.STATISTICS and only adds missing indexes.
-- Safe to run multiple times.

-- ORDERS
SET @exists := (
  SELECT COUNT(1) FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'orders'
    AND INDEX_NAME = 'idx_restaurant_created'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE `orders` ADD INDEX `idx_restaurant_created` (`restaurant_id`, `created_at`);',
  'SELECT ''idx_restaurant_created exists'';'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
  SELECT COUNT(1) FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'orders'
    AND INDEX_NAME = 'idx_restaurant_payment'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE `orders` ADD INDEX `idx_restaurant_payment` (`restaurant_id`, `payment_status`);',
  'SELECT ''idx_restaurant_payment exists'';'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
  SELECT COUNT(1) FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'orders'
    AND INDEX_NAME = 'idx_restaurant_status'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE `orders` ADD INDEX `idx_restaurant_status` (`restaurant_id`, `order_status`);',
  'SELECT ''idx_restaurant_status exists'';'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ORDER_ITEMS
SET @exists := (
  SELECT COUNT(1) FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'order_items'
    AND INDEX_NAME = 'idx_order'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE `order_items` ADD INDEX `idx_order` (`order_id`);',
  'SELECT ''idx_order exists'';'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
  SELECT COUNT(1) FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'order_items'
    AND INDEX_NAME = 'idx_menu_item'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE `order_items` ADD INDEX `idx_menu_item` (`menu_item_id`);',
  'SELECT ''idx_menu_item exists'';'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- MENU_ITEMS
SET @exists := (
  SELECT COUNT(1) FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'menu_items'
    AND INDEX_NAME = 'idx_restaurant'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE `menu_items` ADD INDEX `idx_restaurant` (`restaurant_id`);',
  'SELECT ''idx_restaurant exists'';'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Additional composite indexes for analytics
SET @exists := (
  SELECT COUNT(1) FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'orders'
    AND INDEX_NAME = 'idx_restaurant_created_paid'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE `orders` ADD INDEX `idx_restaurant_created_paid` (`restaurant_id`, `payment_status`, `created_at`);',
  'SELECT ''idx_restaurant_created_paid exists'';'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
  SELECT COUNT(1) FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'orders'
    AND INDEX_NAME = 'idx_restaurant_created_status'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE `orders` ADD INDEX `idx_restaurant_created_status` (`restaurant_id`, `order_status`, `created_at`);',
  'SELECT ''idx_restaurant_created_status exists'';'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Reference (non-idempotent version requested earlier):
-- -- ORDERS
-- ALTER TABLE `orders`
--   ADD INDEX `idx_restaurant_created` (`restaurant_id`, `created_at`),
--   ADD INDEX `idx_restaurant_payment` (`restaurant_id`, `payment_status`),
--   ADD INDEX `idx_restaurant_status` (`restaurant_id`, `order_status`);
--
-- -- ORDER_ITEMS
-- ALTER TABLE `order_items`
--   ADD INDEX `idx_order` (`order_id`),
--   ADD INDEX `idx_menu_item` (`menu_item_id`);
--
-- -- MENU_ITEMS
-- ALTER TABLE `menu_items`
--   ADD INDEX `idx_restaurant` (`restaurant_id`);

