-- Add image_path column to menu_items if it does not exist (idempotent).

SET @exists := (
  SELECT COUNT(1) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'menu_items'
    AND COLUMN_NAME = 'image_path'
);

SET @sql := IF(
  @exists = 0,
  'ALTER TABLE `menu_items` ADD COLUMN `image_path` VARCHAR(255) NULL AFTER `price`;',
  'SELECT ''image_path column already exists on menu_items'';'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

