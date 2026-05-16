-- Split guest upsell settings into independent menu/cart layers (idempotent).
-- Primary columns:
--   menu: guest_menu_upsell_enabled, guest_menu_upsell_limit, guest_menu_upsell_manual_only
--   cart: guest_cart_upsell_enabled, guest_cart_upsell_limit, guest_cart_upsell_use_manual,
--         guest_cart_upsell_use_contextual, guest_cart_upsell_use_popular

SET @q := (
  SELECT IF(
    EXISTS (
      SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'restaurants' AND COLUMN_NAME = 'guest_menu_upsell_enabled'
    ),
    'SELECT ''guest_menu_upsell_enabled exists'';',
    'ALTER TABLE restaurants ADD COLUMN guest_menu_upsell_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER smart_upsell_guest_max;'
  )
);
PREPARE stmt FROM @q; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @q := (
  SELECT IF(
    EXISTS (
      SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'restaurants' AND COLUMN_NAME = 'guest_menu_upsell_limit'
    ),
    'SELECT ''guest_menu_upsell_limit exists'';',
    'ALTER TABLE restaurants ADD COLUMN guest_menu_upsell_limit INT NOT NULL DEFAULT 1 AFTER guest_menu_upsell_enabled;'
  )
);
PREPARE stmt FROM @q; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @q := (
  SELECT IF(
    EXISTS (
      SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'restaurants' AND COLUMN_NAME = 'guest_menu_upsell_manual_only'
    ),
    'SELECT ''guest_menu_upsell_manual_only exists'';',
    'ALTER TABLE restaurants ADD COLUMN guest_menu_upsell_manual_only TINYINT(1) NOT NULL DEFAULT 1 AFTER guest_menu_upsell_limit;'
  )
);
PREPARE stmt FROM @q; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @q := (
  SELECT IF(
    EXISTS (
      SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'restaurants' AND COLUMN_NAME = 'guest_cart_upsell_enabled'
    ),
    'SELECT ''guest_cart_upsell_enabled exists'';',
    'ALTER TABLE restaurants ADD COLUMN guest_cart_upsell_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER guest_menu_upsell_manual_only;'
  )
);
PREPARE stmt FROM @q; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @q := (
  SELECT IF(
    EXISTS (
      SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'restaurants' AND COLUMN_NAME = 'guest_cart_upsell_limit'
    ),
    'SELECT ''guest_cart_upsell_limit exists'';',
    'ALTER TABLE restaurants ADD COLUMN guest_cart_upsell_limit INT NOT NULL DEFAULT 3 AFTER guest_cart_upsell_enabled;'
  )
);
PREPARE stmt FROM @q; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @q := (
  SELECT IF(
    EXISTS (
      SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'restaurants' AND COLUMN_NAME = 'guest_cart_upsell_use_manual'
    ),
    'SELECT ''guest_cart_upsell_use_manual exists'';',
    'ALTER TABLE restaurants ADD COLUMN guest_cart_upsell_use_manual TINYINT(1) NOT NULL DEFAULT 1 AFTER guest_cart_upsell_limit;'
  )
);
PREPARE stmt FROM @q; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @q := (
  SELECT IF(
    EXISTS (
      SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'restaurants' AND COLUMN_NAME = 'guest_cart_upsell_use_contextual'
    ),
    'SELECT ''guest_cart_upsell_use_contextual exists'';',
    'ALTER TABLE restaurants ADD COLUMN guest_cart_upsell_use_contextual TINYINT(1) NOT NULL DEFAULT 1 AFTER guest_cart_upsell_use_manual;'
  )
);
PREPARE stmt FROM @q; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @q := (
  SELECT IF(
    EXISTS (
      SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'restaurants' AND COLUMN_NAME = 'guest_cart_upsell_use_popular'
    ),
    'SELECT ''guest_cart_upsell_use_popular exists'';',
    'ALTER TABLE restaurants ADD COLUMN guest_cart_upsell_use_popular TINYINT(1) NOT NULL DEFAULT 1 AFTER guest_cart_upsell_use_contextual;'
  )
);
PREPARE stmt FROM @q; EXECUTE stmt; DEALLOCATE PREPARE stmt;
