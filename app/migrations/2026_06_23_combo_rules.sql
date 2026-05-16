-- Таблица для combo upsell предложений
-- Используется для split upsell на menu и cart.

SET @q := (
  SELECT IF(
    EXISTS (
      SELECT 1
      FROM INFORMATION_SCHEMA.TABLES
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'combo_rules'
    ),
    'SELECT ''combo_rules table already exists'';',
    'CREATE TABLE combo_rules (
      id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
      restaurant_id INT NOT NULL,
      trigger_item_id INT NOT NULL,
      suggested_item_ids JSON NOT NULL,
      priority INT NOT NULL DEFAULT 100,
      active TINYINT(1) NOT NULL DEFAULT 1,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;'
  )
);
PREPARE stmt FROM @q; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @q := (
  SELECT IF(
    EXISTS (
      SELECT 1
      FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'combo_rules'
        AND COLUMN_NAME = 'id'
    ),
    'SELECT ''combo_rules.id exists'';',
    'ALTER TABLE combo_rules ADD COLUMN id INT NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST;'
  )
);
PREPARE stmt FROM @q; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @q := (
  SELECT IF(
    EXISTS (
      SELECT 1
      FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'combo_rules'
        AND COLUMN_NAME = 'restaurant_id'
    ),
    'SELECT ''combo_rules.restaurant_id exists'';',
    'ALTER TABLE combo_rules ADD COLUMN restaurant_id INT NOT NULL AFTER id;'
  )
);
PREPARE stmt FROM @q; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @q := (
  SELECT IF(
    EXISTS (
      SELECT 1
      FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'combo_rules'
        AND COLUMN_NAME = 'trigger_item_id'
    ),
    'SELECT ''combo_rules.trigger_item_id exists'';',
    'ALTER TABLE combo_rules ADD COLUMN trigger_item_id INT NOT NULL AFTER restaurant_id;'
  )
);
PREPARE stmt FROM @q; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @q := (
  SELECT IF(
    EXISTS (
      SELECT 1
      FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'combo_rules'
        AND COLUMN_NAME = 'suggested_item_ids'
    ),
    'SELECT ''combo_rules.suggested_item_ids exists'';',
    'ALTER TABLE combo_rules ADD COLUMN suggested_item_ids JSON NOT NULL AFTER trigger_item_id;'
  )
);
PREPARE stmt FROM @q; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @q := (
  SELECT IF(
    EXISTS (
      SELECT 1
      FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'combo_rules'
        AND COLUMN_NAME = 'priority'
    ),
    'SELECT ''combo_rules.priority exists'';',
    'ALTER TABLE combo_rules ADD COLUMN priority INT NOT NULL DEFAULT 100 AFTER suggested_item_ids;'
  )
);
PREPARE stmt FROM @q; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @q := (
  SELECT IF(
    EXISTS (
      SELECT 1
      FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'combo_rules'
        AND COLUMN_NAME = 'active'
    ),
    'SELECT ''combo_rules.active exists'';',
    'ALTER TABLE combo_rules ADD COLUMN active TINYINT(1) NOT NULL DEFAULT 1 AFTER priority;'
  )
);
PREPARE stmt FROM @q; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @q := (
  SELECT IF(
    EXISTS (
      SELECT 1
      FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'combo_rules'
        AND COLUMN_NAME = 'created_at'
    ),
    'SELECT ''combo_rules.created_at exists'';',
    'ALTER TABLE combo_rules ADD COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER active;'
  )
);
PREPARE stmt FROM @q; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @q := (
  SELECT IF(
    EXISTS (
      SELECT 1
      FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'combo_rules'
        AND COLUMN_NAME = 'updated_at'
    ),
    'SELECT ''combo_rules.updated_at exists'';',
    'ALTER TABLE combo_rules ADD COLUMN updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER created_at;'
  )
);
PREPARE stmt FROM @q; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Пример SELECT для проверки содержимого:
-- SELECT id, restaurant_id, trigger_item_id, suggested_item_ids, priority, active, created_at, updated_at
-- FROM combo_rules
-- ORDER BY priority DESC, id DESC
-- LIMIT 50;

