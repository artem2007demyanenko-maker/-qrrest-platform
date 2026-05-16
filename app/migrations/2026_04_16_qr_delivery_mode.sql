-- QR public menu: delivery mode support (virtual "delivery table" + persisted delivery contact fields).
-- Idempotent: safe to run multiple times.

-- tables.is_delivery: mark service rows used for platform/delivery carts (not a physical dine-in table).
SET @exists := (
  SELECT COUNT(1) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'tables'
    AND COLUMN_NAME = 'is_delivery'
);

SET @sql := IF(
  @exists = 0,
  'ALTER TABLE `tables` ADD COLUMN `is_delivery` TINYINT(1) NOT NULL DEFAULT 0 AFTER `name`;',
  'SELECT ''tables.is_delivery exists'';'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- orders: delivery contact snapshot (optional columns; guarded in PHP via db_column_exists)
SET @exists := (
  SELECT COUNT(1) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'orders'
    AND COLUMN_NAME = 'delivery_full_name'
);

SET @sql := IF(
  @exists = 0,
  'ALTER TABLE `orders` ADD COLUMN `delivery_full_name` VARCHAR(190) NULL AFTER `table_id`;',
  'SELECT ''orders.delivery_full_name exists'';'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @exists := (
  SELECT COUNT(1) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'orders'
    AND COLUMN_NAME = 'delivery_address'
);

SET @sql := IF(
  @exists = 0,
  'ALTER TABLE `orders` ADD COLUMN `delivery_address` VARCHAR(500) NULL AFTER `delivery_full_name`;',
  'SELECT ''orders.delivery_address exists'';'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @exists := (
  SELECT COUNT(1) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'orders'
    AND COLUMN_NAME = 'delivery_phone'
);

SET @sql := IF(
  @exists = 0,
  'ALTER TABLE `orders` ADD COLUMN `delivery_phone` VARCHAR(32) NULL AFTER `delivery_address`;',
  'SELECT ''orders.delivery_phone exists'';'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
