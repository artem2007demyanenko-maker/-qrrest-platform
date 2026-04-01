-- Optional loyalty return/recovery suggestion mode (per restaurant). Idempotent.

SET @col_exists := (
  SELECT COUNT(1) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'restaurant_loyalty_settings' AND COLUMN_NAME = 'loyalty_return_mode_enabled'
);

SET @sql := IF(@col_exists = 0,
  'ALTER TABLE restaurant_loyalty_settings ADD COLUMN loyalty_return_mode_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER enabled',
  'SELECT 1'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
