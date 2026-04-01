-- Smart Upsell Engine: per-item upsell rules (idempotent).

-- Create table if missing
SET @exists := (
  SELECT COUNT(1) FROM INFORMATION_SCHEMA.TABLES
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'menu_upsell_rules'
);

SET @sql := IF(
  @exists = 0,
  'CREATE TABLE `menu_upsell_rules` (
      id INT AUTO_INCREMENT PRIMARY KEY,
      restaurant_id INT NOT NULL,
      trigger_item_id INT NOT NULL,
      suggest_item_id INT NOT NULL,
      priority INT DEFAULT 0,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;',
  'SELECT ''menu_upsell_rules already exists'';'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Index on restaurant_id
SET @exists := (
  SELECT COUNT(1) FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'menu_upsell_rules'
    AND INDEX_NAME = 'idx_upsell_restaurant'
);

SET @sql := IF(
  @exists = 0,
  'CREATE INDEX `idx_upsell_restaurant` ON `menu_upsell_rules` (restaurant_id);',
  'SELECT ''idx_upsell_restaurant exists'';'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Index on trigger_item_id
SET @exists := (
  SELECT COUNT(1) FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'menu_upsell_rules'
    AND INDEX_NAME = 'idx_upsell_trigger'
);

SET @sql := IF(
  @exists = 0,
  'CREATE INDEX `idx_upsell_trigger` ON `menu_upsell_rules` (trigger_item_id);',
  'SELECT ''idx_upsell_trigger exists'';'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

