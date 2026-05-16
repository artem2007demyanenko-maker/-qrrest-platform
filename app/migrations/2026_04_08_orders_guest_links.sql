-- Orders guest linking for loyalty v1:
-- - explicit order -> guest_id
-- - optional order -> guest_card_id
-- Keeps loyalty_phone as compat/snapshot only.

SET @exists := (
  SELECT COUNT(1) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'orders'
    AND COLUMN_NAME = 'guest_id'
);

SET @sql := IF(
  @exists = 0,
  'ALTER TABLE `orders` ADD COLUMN `guest_id` INT NULL AFTER `table_id`;',
  'SELECT ''orders.guest_id exists'';'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @exists := (
  SELECT COUNT(1) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'orders'
    AND COLUMN_NAME = 'guest_card_id'
);

SET @sql := IF(
  @exists = 0,
  'ALTER TABLE `orders` ADD COLUMN `guest_card_id` INT NULL AFTER `guest_id`;',
  'SELECT ''orders.guest_card_id exists'';'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @exists := (
  SELECT COUNT(1) FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'orders'
    AND INDEX_NAME = 'idx_orders_restaurant_guest_created'
);

SET @sql := IF(
  @exists = 0,
  'ALTER TABLE `orders` ADD KEY `idx_orders_restaurant_guest_created` (`restaurant_id`, `guest_id`, `created_at`);',
  'SELECT ''idx_orders_restaurant_guest_created exists'';'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @exists := (
  SELECT COUNT(1) FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'orders'
    AND INDEX_NAME = 'idx_orders_guest_card_id'
);

SET @sql := IF(
  @exists = 0,
  'ALTER TABLE `orders` ADD KEY `idx_orders_guest_card_id` (`guest_card_id`);',
  'SELECT ''idx_orders_guest_card_id exists'';'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
