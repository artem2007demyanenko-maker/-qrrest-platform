-- Combo split settings for restaurants (idempotent, no AFTER dependencies).
-- Adds independent combo controls for menu/cart layers.

SET @q := (
  SELECT IF(
    EXISTS (
      SELECT 1
      FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'restaurants'
        AND COLUMN_NAME = 'guest_menu_combo_enabled'
    ),
    'SELECT ''guest_menu_combo_enabled exists'';',
    'ALTER TABLE restaurants ADD COLUMN guest_menu_combo_enabled TINYINT(1) NOT NULL DEFAULT 1;'
  )
);
PREPARE stmt FROM @q; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @q := (
  SELECT IF(
    EXISTS (
      SELECT 1
      FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'restaurants'
        AND COLUMN_NAME = 'guest_menu_combo_limit'
    ),
    'SELECT ''guest_menu_combo_limit exists'';',
    'ALTER TABLE restaurants ADD COLUMN guest_menu_combo_limit INT NOT NULL DEFAULT 1;'
  )
);
PREPARE stmt FROM @q; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @q := (
  SELECT IF(
    EXISTS (
      SELECT 1
      FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'restaurants'
        AND COLUMN_NAME = 'guest_cart_combo_enabled'
    ),
    'SELECT ''guest_cart_combo_enabled exists'';',
    'ALTER TABLE restaurants ADD COLUMN guest_cart_combo_enabled TINYINT(1) NOT NULL DEFAULT 1;'
  )
);
PREPARE stmt FROM @q; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @q := (
  SELECT IF(
    EXISTS (
      SELECT 1
      FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'restaurants'
        AND COLUMN_NAME = 'guest_cart_combo_limit'
    ),
    'SELECT ''guest_cart_combo_limit exists'';',
    'ALTER TABLE restaurants ADD COLUMN guest_cart_combo_limit INT NOT NULL DEFAULT 2;'
  )
);
PREPARE stmt FROM @q; EXECUTE stmt; DEALLOCATE PREPARE stmt;
