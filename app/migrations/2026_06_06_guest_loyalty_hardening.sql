-- Loyalty hardening: guest_loyalty_accounts, guest_loyalty_tx, duplicate protection, created_at.
-- Idempotent: safe to re-run.

-- guest_loyalty_accounts: one row per (guest_id, restaurant_id)
SET @exists := (
  SELECT COUNT(1) FROM INFORMATION_SCHEMA.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'guest_loyalty_accounts'
);

SET @sql := IF(@exists = 0,
  'CREATE TABLE guest_loyalty_accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    guest_id INT NOT NULL,
    restaurant_id INT NOT NULL,
    balance INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_guest_rest (guest_id, restaurant_id),
    KEY idx_restaurant (restaurant_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
  'SELECT 1'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add unique on guest_loyalty_accounts if missing
SET @idx_exists := (
  SELECT COUNT(1) FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'guest_loyalty_accounts' AND INDEX_NAME = 'uniq_guest_rest'
);

SET @sql := IF(@idx_exists = 0,
  'ALTER TABLE guest_loyalty_accounts ADD UNIQUE KEY uniq_guest_rest (guest_id, restaurant_id)',
  'SELECT 1'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- guest_loyalty_tx: transaction log
SET @tx_exists := (
  SELECT COUNT(1) FROM INFORMATION_SCHEMA.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'guest_loyalty_tx'
);

SET @sql := IF(@tx_exists = 0,
  'CREATE TABLE guest_loyalty_tx (
    id INT AUTO_INCREMENT PRIMARY KEY,
    guest_id INT NOT NULL,
    restaurant_id INT NOT NULL,
    staff_user_id INT NULL,
    order_id INT NULL,
    type VARCHAR(32) NOT NULL,
    points INT NOT NULL,
    note VARCHAR(255) NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_guest_rest (guest_id, restaurant_id),
    KEY idx_restaurant_created (restaurant_id, created_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
  'SELECT 1'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add created_at to guest_loyalty_tx if missing
SET @col_exists := (
  SELECT COUNT(1) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'guest_loyalty_tx' AND COLUMN_NAME = 'created_at'
);

SET @sql := IF(@col_exists = 0,
  'ALTER TABLE guest_loyalty_tx ADD COLUMN created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP AFTER note',
  'SELECT 1'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- guest_cards: ensure unique (guest_id, restaurant_id) to prevent duplicate cards
SET @gc_exists := (
  SELECT COUNT(1) FROM INFORMATION_SCHEMA.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'guest_cards'
);

SET @uniq_gc := (
  SELECT COUNT(1) FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'guest_cards'
    AND INDEX_NAME IN ('uniq_guest_rest', 'uniq_guest_restaurant')
);

SET @sql := IF(@gc_exists > 0 AND @uniq_gc = 0,
  'ALTER TABLE guest_cards ADD UNIQUE KEY uniq_guest_rest (guest_id, restaurant_id)',
  'SELECT 1'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
