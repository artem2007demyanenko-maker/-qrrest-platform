-- Settings real-mode hardening for legacy DBs (idempotent, no AFTER dependencies).
-- 1) Ensures restaurant_crm_settings table exists for CRM settings section.
-- 2) Ensures split guest upsell columns exist in restaurants.
-- 3) Ensures split combo columns exist in restaurants.

CREATE TABLE IF NOT EXISTS restaurant_crm_settings (
    restaurant_id INT NOT NULL,
    inactive_return_days INT NOT NULL DEFAULT 14,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (restaurant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @q := (
  SELECT IF(
    EXISTS (
      SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'restaurants' AND COLUMN_NAME = 'guest_menu_upsell_enabled'
    ),
    'SELECT ''guest_menu_upsell_enabled exists'';',
    'ALTER TABLE restaurants ADD COLUMN guest_menu_upsell_enabled TINYINT(1) NOT NULL DEFAULT 1;'
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
    'ALTER TABLE restaurants ADD COLUMN guest_menu_upsell_limit INT NOT NULL DEFAULT 1;'
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
    'ALTER TABLE restaurants ADD COLUMN guest_menu_upsell_manual_only TINYINT(1) NOT NULL DEFAULT 1;'
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
    'ALTER TABLE restaurants ADD COLUMN guest_cart_upsell_enabled TINYINT(1) NOT NULL DEFAULT 1;'
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
    'ALTER TABLE restaurants ADD COLUMN guest_cart_upsell_limit INT NOT NULL DEFAULT 3;'
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
    'ALTER TABLE restaurants ADD COLUMN guest_cart_upsell_use_manual TINYINT(1) NOT NULL DEFAULT 1;'
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
    'ALTER TABLE restaurants ADD COLUMN guest_cart_upsell_use_contextual TINYINT(1) NOT NULL DEFAULT 1;'
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
    'ALTER TABLE restaurants ADD COLUMN guest_cart_upsell_use_popular TINYINT(1) NOT NULL DEFAULT 1;'
  )
);
PREPARE stmt FROM @q; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @q := (
  SELECT IF(
    EXISTS (
      SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'restaurants' AND COLUMN_NAME = 'guest_menu_combo_enabled'
    ),
    'SELECT ''guest_menu_combo_enabled exists'';',
    'ALTER TABLE restaurants ADD COLUMN guest_menu_combo_enabled TINYINT(1) NOT NULL DEFAULT 1;'
  )
);
PREPARE stmt FROM @q; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @q := (
  SELECT IF(
    EXISTS (
      SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'restaurants' AND COLUMN_NAME = 'guest_menu_combo_limit'
    ),
    'SELECT ''guest_menu_combo_limit exists'';',
    'ALTER TABLE restaurants ADD COLUMN guest_menu_combo_limit INT NOT NULL DEFAULT 1;'
  )
);
PREPARE stmt FROM @q; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @q := (
  SELECT IF(
    EXISTS (
      SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'restaurants' AND COLUMN_NAME = 'guest_cart_combo_enabled'
    ),
    'SELECT ''guest_cart_combo_enabled exists'';',
    'ALTER TABLE restaurants ADD COLUMN guest_cart_combo_enabled TINYINT(1) NOT NULL DEFAULT 1;'
  )
);
PREPARE stmt FROM @q; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @q := (
  SELECT IF(
    EXISTS (
      SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'restaurants' AND COLUMN_NAME = 'guest_cart_combo_limit'
    ),
    'SELECT ''guest_cart_combo_limit exists'';',
    'ALTER TABLE restaurants ADD COLUMN guest_cart_combo_limit INT NOT NULL DEFAULT 2;'
  )
);
PREPARE stmt FROM @q; EXECUTE stmt; DEALLOCATE PREPARE stmt;
