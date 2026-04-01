-- 2026-03-06: Growth Features (Variant 6). Idempotent.

-- 1) referral_codes
CREATE TABLE IF NOT EXISTS referral_codes (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT NOT NULL,
  code VARCHAR(32) NOT NULL,
  clicks_count INT NOT NULL DEFAULT 0,
  conversions_count INT NOT NULL DEFAULT 0,
  reward_type VARCHAR(24) NOT NULL DEFAULT 'none',
  reward_value DECIMAL(10,2) NULL,
  is_active TINYINT NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_referral_code (code),
  KEY idx_referral_user (user_id),
  CONSTRAINT fk_referral_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2) referral_clicks
CREATE TABLE IF NOT EXISTS referral_clicks (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  referral_code_id INT UNSIGNED NOT NULL,
  ip_hash VARCHAR(64) NOT NULL,
  user_agent_hash VARCHAR(64) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ref_click_code_created (referral_code_id, created_at),
  CONSTRAINT fk_ref_click_code FOREIGN KEY (referral_code_id) REFERENCES referral_codes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3) referral_conversions
CREATE TABLE IF NOT EXISTS referral_conversions (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  referral_code_id INT UNSIGNED NOT NULL,
  new_user_id INT NOT NULL,
  rewarded TINYINT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_ref_conv_user (new_user_id),
  KEY idx_ref_conv_code (referral_code_id),
  CONSTRAINT fk_ref_conv_code FOREIGN KEY (referral_code_id) REFERENCES referral_codes(id) ON DELETE CASCADE,
  CONSTRAINT fk_ref_conv_user FOREIGN KEY (new_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4) coupons
CREATE TABLE IF NOT EXISTS coupons (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code VARCHAR(64) NOT NULL,
  type VARCHAR(16) NOT NULL DEFAULT 'percent',
  value DECIMAL(10,2) NOT NULL,
  valid_from DATETIME NULL,
  valid_until DATETIME NULL,
  usage_limit INT UNSIGNED NOT NULL DEFAULT 0,
  used_count INT UNSIGNED NOT NULL DEFAULT 0,
  is_active TINYINT NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_coupon_code (code),
  KEY idx_coupon_active (is_active, valid_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5) coupon_usages
CREATE TABLE IF NOT EXISTS coupon_usages (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  coupon_id INT UNSIGNED NOT NULL,
  user_id INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_coupon_user (coupon_id, user_id),
  KEY idx_coupon_usage_user (user_id),
  CONSTRAINT fk_coupon_usage_coupon FOREIGN KEY (coupon_id) REFERENCES coupons(id) ON DELETE CASCADE,
  CONSTRAINT fk_coupon_usage_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 6) usage_metrics_daily
CREATE TABLE IF NOT EXISTS usage_metrics_daily (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT NOT NULL,
  date DATE NOT NULL,
  restaurants_count INT UNSIGNED NOT NULL DEFAULT 0,
  orders_count INT UNSIGNED NOT NULL DEFAULT 0,
  revenue DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  staff_count INT UNSIGNED NOT NULL DEFAULT 0,
  menu_items_count INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_usage_user_date (user_id, date),
  KEY idx_usage_user (user_id),
  KEY idx_usage_date (date),
  CONSTRAINT fk_usage_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 7) utm_visits (FK added below after aligning restaurant_id type with restaurants.id)
CREATE TABLE IF NOT EXISTS utm_visits (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  restaurant_id INT NOT NULL,
  utm_source VARCHAR(190) NULL,
  utm_medium VARCHAR(190) NULL,
  utm_campaign VARCHAR(190) NULL,
  utm_content VARCHAR(190) NULL,
  utm_term VARCHAR(190) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_utm_rest_created (restaurant_id, created_at),
  KEY idx_utm_source (utm_source)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 7b) Align utm_visits.restaurant_id type with restaurants.id (idempotent), then add FK
SET @rest_type = (SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'restaurants' AND COLUMN_NAME = 'id' LIMIT 1);
SET @utm_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'utm_visits');
SET @sql = IF(@rest_type IS NOT NULL AND @utm_exists > 0, CONCAT('ALTER TABLE utm_visits MODIFY COLUMN restaurant_id ', @rest_type, ' NOT NULL'), 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @fk_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'utm_visits' AND CONSTRAINT_NAME = 'fk_utm_restaurant');
SET @sql = IF(@fk_exists = 0, 'ALTER TABLE utm_visits ADD CONSTRAINT fk_utm_restaurant FOREIGN KEY (restaurant_id) REFERENCES restaurants(id) ON DELETE CASCADE', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 8) invoices.coupon_id (optional, for audit; only if invoices table exists)
SET @inv_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invoices');
SET @col_exists = (SELECT COUNT(1) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invoices' AND COLUMN_NAME = 'coupon_id');
SET @sql = IF(@inv_exists > 0 AND @col_exists = 0, 'ALTER TABLE invoices ADD COLUMN coupon_id INT UNSIGNED NULL, ADD KEY idx_inv_coupon (coupon_id);', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
