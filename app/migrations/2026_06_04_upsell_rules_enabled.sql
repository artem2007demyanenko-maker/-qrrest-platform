-- Add optional enabled flag to menu_upsell_rules (idempotent).
SET @exists := (
  SELECT COUNT(1) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'menu_upsell_rules'
    AND COLUMN_NAME = 'enabled'
);

SET @sql := IF(
  @exists = 0,
  'ALTER TABLE `menu_upsell_rules` ADD COLUMN `enabled` TINYINT(1) NOT NULL DEFAULT 1 AFTER `priority`;',
  'SELECT ''enabled column already exists on menu_upsell_rules'';'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
