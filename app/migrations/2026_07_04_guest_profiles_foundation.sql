-- Stage 9: Guest CRM by phone (foundation)
-- Safe, idempotent schema for guest identity profiles.

CREATE TABLE IF NOT EXISTS guest_profiles (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  restaurant_id INT NOT NULL,
  phone_normalized VARCHAR(16) NOT NULL,
  guest_name VARCHAR(190) NULL,
  orders_count INT UNSIGNED NOT NULL DEFAULT 0,
  total_spent DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  average_check DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  first_order_at DATETIME NULL,
  last_order_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_guest_profiles_rest_phone (restaurant_id, phone_normalized),
  KEY idx_guest_profiles_restaurant (restaurant_id),
  KEY idx_guest_profiles_phone (phone_normalized),
  KEY idx_guest_profiles_last_order (last_order_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
