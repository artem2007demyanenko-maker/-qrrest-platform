-- Ensure menu_items has nullable image_path for local uploads.
-- Safe idempotent migration.

SET @col_exists := (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'menu_items'
    AND COLUMN_NAME = 'image_path'
);

SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE menu_items ADD COLUMN image_path VARCHAR(255) NULL DEFAULT NULL AFTER price',
  'SELECT 1'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
