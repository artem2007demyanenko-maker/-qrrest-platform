-- Menu image: add image_url for uploads/menu/ path. Idempotent.

SET @col_exists := (
  SELECT COUNT(1) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'menu_items' AND COLUMN_NAME = 'image_url'
);

SET @sql := IF(@col_exists = 0,
  'ALTER TABLE menu_items ADD COLUMN image_url VARCHAR(255) NULL DEFAULT NULL AFTER image_path',
  'SELECT 1'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
