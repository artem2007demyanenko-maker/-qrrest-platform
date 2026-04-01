-- 2026-03-04: Billing / Subscriptions (Variant 4). Idempotent.

-- 0) restaurants.owner_user_id (если ещё нет) — для связи владельца с рестораном
SET @col_exists = (
  SELECT COUNT(1) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'restaurants' AND COLUMN_NAME = 'owner_user_id'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE restaurants ADD COLUMN owner_user_id INT NULL, ADD KEY idx_restaurants_owner (owner_user_id);',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 1) plans
CREATE TABLE IF NOT EXISTS plans (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code VARCHAR(32) NOT NULL,
  name VARCHAR(190) NOT NULL,
  description TEXT NULL,
  price_month DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  currency VARCHAR(3) NOT NULL DEFAULT 'RUB',
  limits_json JSON NULL,
  is_active TINYINT NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_plan_code (code),
  KEY idx_plan_active_sort (is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2) subscriptions
CREATE TABLE IF NOT EXISTS subscriptions (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT NOT NULL,
  plan_id INT UNSIGNED NOT NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'active',
  current_period_start DATETIME NOT NULL,
  current_period_end DATETIME NOT NULL,
  cancel_at_period_end TINYINT NOT NULL DEFAULT 0,
  canceled_at DATETIME NULL,
  meta_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_sub_user (user_id),
  KEY idx_sub_status (status),
  KEY idx_sub_period_end (current_period_end),
  KEY idx_sub_user_status (user_id, status),
  CONSTRAINT fk_sub_plan FOREIGN KEY (plan_id) REFERENCES plans(id),
  CONSTRAINT fk_sub_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3) invoices
CREATE TABLE IF NOT EXISTS invoices (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT NOT NULL,
  subscription_id INT UNSIGNED NULL,
  amount DECIMAL(12,2) NOT NULL,
  currency VARCHAR(3) NOT NULL DEFAULT 'RUB',
  status VARCHAR(24) NOT NULL DEFAULT 'draft',
  period_start DATETIME NULL,
  period_end DATETIME NULL,
  provider VARCHAR(64) NULL,
  provider_invoice_id VARCHAR(190) NULL,
  payload_json JSON NULL,
  issued_at DATETIME NULL,
  paid_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_inv_user_created (user_id, created_at),
  KEY idx_inv_sub (subscription_id),
  KEY idx_inv_status (status),
  CONSTRAINT fk_inv_user FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT fk_inv_sub FOREIGN KEY (subscription_id) REFERENCES subscriptions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4) payments
CREATE TABLE IF NOT EXISTS payments (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT NOT NULL,
  invoice_id INT UNSIGNED NULL,
  amount DECIMAL(12,2) NOT NULL,
  currency VARCHAR(3) NOT NULL DEFAULT 'RUB',
  status VARCHAR(24) NOT NULL DEFAULT 'created',
  provider VARCHAR(64) NOT NULL DEFAULT 'manual',
  provider_payment_id VARCHAR(190) NULL,
  error_text TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_pay_user_created (user_id, created_at),
  KEY idx_pay_invoice (invoice_id),
  KEY idx_pay_status (status),
  CONSTRAINT fk_pay_user FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT fk_pay_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed plans (idempotent by code)
INSERT INTO plans (id, code, name, description, price_month, currency, limits_json, is_active, sort_order)
VALUES
  (1, 'free', 'Бесплатный', 'До 1 ресторана', 0.00, 'RUB', '{"restaurants_max":1,"staff_max":3,"menu_items_max":20,"orders_month_max":100}', 1, 0),
  (2, 'starter', 'Старт', 'До 3 ресторанов', 990.00, 'RUB', '{"restaurants_max":3,"staff_max":10,"menu_items_max":100,"orders_month_max":1000}', 1, 10),
  (3, 'pro', 'Про', 'Без ограничений по ресторанам', 2990.00, 'RUB', '{"restaurants_max":999,"staff_max":999,"menu_items_max":999,"orders_month_max":99999}', 1, 20)
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  description = VALUES(description),
  price_month = VALUES(price_month),
  currency = VALUES(currency),
  limits_json = VALUES(limits_json),
  is_active = VALUES(is_active),
  sort_order = VALUES(sort_order),
  updated_at = CURRENT_TIMESTAMP;
