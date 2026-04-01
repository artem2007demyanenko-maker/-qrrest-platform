-- Kitchen Display: station per menu item (BAR, HOT, COLD, DESSERT). Idempotent.

SET @col_exists := (
  SELECT COUNT(1) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'menu_items' AND COLUMN_NAME = 'kitchen_station'
);

SET @sql := IF(@col_exists = 0,
  'ALTER TABLE menu_items ADD COLUMN kitchen_station VARCHAR(32) NULL DEFAULT NULL AFTER available',
  'SELECT 1'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
