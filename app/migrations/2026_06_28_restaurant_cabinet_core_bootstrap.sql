-- 2026-06-28
-- Restaurant cabinet core bootstrap (idempotent).
-- Ensures core tables required by dashboard CRM/upsell pages exist on legacy DBs.

-- usage metrics for dashboard usage cards
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
  KEY idx_usage_date (date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- CRM visits
CREATE TABLE IF NOT EXISTS crm_visits (
  id INT AUTO_INCREMENT PRIMARY KEY,
  restaurant_id INT NOT NULL,
  guest_id INT NOT NULL,
  order_id INT NULL,
  table_id INT NULL,
  total_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  visited_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_restaurant_id (restaurant_id),
  INDEX idx_guest_id (guest_id),
  INDEX idx_order_id (order_id),
  INDEX idx_visited_at (visited_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- CRM templates
CREATE TABLE IF NOT EXISTS crm_templates (
  id INT AUTO_INCREMENT PRIMARY KEY,
  restaurant_id INT NOT NULL,
  name VARCHAR(128) NOT NULL,
  channel VARCHAR(16) NOT NULL DEFAULT 'stub',
  template_text TEXT NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_restaurant_id (restaurant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- CRM campaigns
CREATE TABLE IF NOT EXISTS crm_campaigns (
  id INT AUTO_INCREMENT PRIMARY KEY,
  restaurant_id INT NOT NULL,
  name VARCHAR(128) NOT NULL,
  template_id INT NOT NULL,
  segment_type VARCHAR(32) NOT NULL,
  delay_days INT NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_restaurant_id (restaurant_id),
  INDEX idx_template_id (template_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- CRM outbox
CREATE TABLE IF NOT EXISTS crm_outbox (
  id INT AUTO_INCREMENT PRIMARY KEY,
  restaurant_id INT NOT NULL,
  guest_id INT NOT NULL,
  channel VARCHAR(16) NOT NULL DEFAULT 'stub',
  template VARCHAR(64) NOT NULL DEFAULT 'come_back',
  payload_json TEXT NOT NULL,
  scheduled_at TIMESTAMP NOT NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'pending',
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_restaurant_id (restaurant_id),
  INDEX idx_guest_id (guest_id),
  INDEX idx_scheduled_at (scheduled_at),
  INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Optional hardening columns used by retention analytics (safe if already present).
SET @has_col := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crm_outbox' AND COLUMN_NAME = 'campaign_id'
);
SET @sql := IF(@has_col = 0, 'ALTER TABLE crm_outbox ADD COLUMN campaign_id INT NULL AFTER guest_id', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crm_outbox' AND COLUMN_NAME = 'dedupe_key'
);
SET @sql := IF(@has_col = 0, 'ALTER TABLE crm_outbox ADD COLUMN dedupe_key VARCHAR(64) NULL AFTER status', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crm_outbox' AND COLUMN_NAME = 'processed_at'
);
SET @sql := IF(@has_col = 0, 'ALTER TABLE crm_outbox ADD COLUMN processed_at DATETIME NULL AFTER updated_at', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crm_outbox' AND COLUMN_NAME = 'sent_at'
);
SET @sql := IF(@has_col = 0, 'ALTER TABLE crm_outbox ADD COLUMN sent_at DATETIME NULL AFTER processed_at', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crm_outbox' AND COLUMN_NAME = 'failed_at'
);
SET @sql := IF(@has_col = 0, 'ALTER TABLE crm_outbox ADD COLUMN failed_at DATETIME NULL AFTER sent_at', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crm_outbox' AND COLUMN_NAME = 'last_error'
);
SET @sql := IF(@has_col = 0, 'ALTER TABLE crm_outbox ADD COLUMN last_error VARCHAR(255) NULL AFTER failed_at', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- combo rules for upsell pages
CREATE TABLE IF NOT EXISTS combo_rules (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  restaurant_id INT NOT NULL,
  trigger_item_id INT NOT NULL,
  suggested_item_ids JSON NOT NULL,
  priority INT NOT NULL DEFAULT 100,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

