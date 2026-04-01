-- 2026-03-07: Stability & Anti-Abuse (Variant 7). Idempotent.

-- 1) security_rate_limits (fallback when Redis not used)
CREATE TABLE IF NOT EXISTS security_rate_limits (
  key_hash VARCHAR(64) NOT NULL,
  hits INT UNSIGNED NOT NULL DEFAULT 0,
  window_start INT UNSIGNED NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (key_hash),
  KEY idx_rate_window (window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2) referral_clicks: day_bucket for UNIQUE dedup per day (MySQL 8 strict: no 0000-00-00)
SET @col_exists = (
  SELECT COUNT(1) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'referral_clicks' AND COLUMN_NAME = 'day_bucket'
);
SET @sql = IF(@col_exists = 0, 'ALTER TABLE referral_clicks ADD COLUMN day_bucket DATE NOT NULL DEFAULT (UTC_DATE());', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @rc_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'referral_clicks');
SET @day_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'referral_clicks' AND COLUMN_NAME = 'day_bucket');
SET @sql = IF(@rc_exists > 0 AND @day_exists > 0, 'UPDATE referral_clicks SET day_bucket = COALESCE(DATE(created_at), UTC_DATE())', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @idx_exists = (SELECT COUNT(1) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'referral_clicks' AND INDEX_NAME = 'uniq_ref_click_code_ip_day');
SET @sql = IF(@idx_exists = 0, 'ALTER TABLE referral_clicks ADD UNIQUE KEY uniq_ref_click_code_ip_day (referral_code_id, ip_hash, day_bucket);', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3) coupons: abuse protection columns (only if coupons table exists)
SET @coupons_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'coupons');
SET @col_exists = (SELECT COUNT(1) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'coupons' AND COLUMN_NAME = 'max_per_user');
SET @sql = IF(@coupons_exists > 0 AND @col_exists = 0, 'ALTER TABLE coupons ADD COLUMN max_per_user INT UNSIGNED NOT NULL DEFAULT 1;', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @col_exists = (SELECT COUNT(1) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'coupons' AND COLUMN_NAME = 'min_plan_price');
SET @sql = IF(@coupons_exists > 0 AND @col_exists = 0, 'ALTER TABLE coupons ADD COLUMN min_plan_price DECIMAL(10,2) NULL;', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @col_exists = (SELECT COUNT(1) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'coupons' AND COLUMN_NAME = 'first_time_only');
SET @sql = IF(@coupons_exists > 0 AND @col_exists = 0, 'ALTER TABLE coupons ADD COLUMN first_time_only TINYINT NOT NULL DEFAULT 0;', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4) payments.provider_ref (UNIQUE for idempotency; only if payments table exists)
SET @payments_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payments');
SET @col_exists = (SELECT COUNT(1) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payments' AND COLUMN_NAME = 'provider_ref');
SET @sql = IF(@payments_exists > 0 AND @col_exists = 0,
  'ALTER TABLE payments ADD COLUMN provider_ref VARCHAR(128) NULL, ADD UNIQUE KEY uniq_payments_provider_ref (provider_ref);',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 5) audit_logs
CREATE TABLE IF NOT EXISTS audit_logs (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT NULL,
  action VARCHAR(64) NOT NULL,
  entity_type VARCHAR(32) NOT NULL DEFAULT '',
  entity_id VARCHAR(64) NULL,
  ip_hash VARCHAR(64) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_audit_user (user_id),
  KEY idx_audit_created (created_at),
  KEY idx_audit_action (action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 6) abuse_signals
CREATE TABLE IF NOT EXISTS abuse_signals (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT NULL,
  signal_type VARCHAR(64) NOT NULL,
  score INT NOT NULL DEFAULT 0,
  meta_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_abuse_user (user_id),
  KEY idx_abuse_type (signal_type),
  KEY idx_abuse_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 7) restaurants.deleted_at (soft delete; 404 when not null)
SET @col_exists = (SELECT COUNT(1) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'restaurants' AND COLUMN_NAME = 'deleted_at');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE restaurants ADD COLUMN deleted_at DATETIME NULL;', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @idx_exists = (SELECT COUNT(1) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'restaurants' AND INDEX_NAME = 'idx_restaurants_deleted_at');
SET @sql = IF(@idx_exists = 0, 'ALTER TABLE restaurants ADD KEY idx_restaurants_deleted_at (deleted_at);', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
