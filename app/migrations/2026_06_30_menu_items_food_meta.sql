-- Add food metadata columns for guest/staff dish safety UI (idempotent).
-- Live-safe: no AFTER dependencies.

SET @q := (
  SELECT IF(
    EXISTS (
      SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'menu_items' AND COLUMN_NAME = 'ingredients'
    ),
    'SELECT ''menu_items.ingredients exists'';',
    'ALTER TABLE menu_items ADD COLUMN ingredients TEXT NULL;'
  )
);
PREPARE stmt FROM @q; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @q := (
  SELECT IF(
    EXISTS (
      SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'menu_items' AND COLUMN_NAME = 'allergens'
    ),
    'SELECT ''menu_items.allergens exists'';',
    'ALTER TABLE menu_items ADD COLUMN allergens TEXT NULL;'
  )
);
PREPARE stmt FROM @q; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @q := (
  SELECT IF(
    EXISTS (
      SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'menu_items' AND COLUMN_NAME = 'weight_grams'
    ),
    'SELECT ''menu_items.weight_grams exists'';',
    'ALTER TABLE menu_items ADD COLUMN weight_grams INT NULL;'
  )
);
PREPARE stmt FROM @q; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @q := (
  SELECT IF(
    EXISTS (
      SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'menu_items' AND COLUMN_NAME = 'tags'
    ),
    'SELECT ''menu_items.tags exists'';',
    'ALTER TABLE menu_items ADD COLUMN tags TEXT NULL;'
  )
);
PREPARE stmt FROM @q; EXECUTE stmt; DEALLOCATE PREPARE stmt;
