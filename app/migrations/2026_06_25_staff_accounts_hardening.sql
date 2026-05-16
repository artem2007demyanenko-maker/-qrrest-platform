-- Staff accounts hardening (idempotent)
-- Goal:
-- 1) Block login/access for inactive accounts.
-- 2) Allow per-restaurant enable/disable for staff links.
-- 3) Keep simple station context for kitchen/bar routing.

SET @db := DATABASE();

-- users.is_active (global account switch)
SET @q := IF (
  EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'is_active'
  ),
  'SELECT ''users.is_active exists''',
  'ALTER TABLE users ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1'
);
PREPARE stmt FROM @q; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- users_restaurants.is_active (per-restaurant switch)
SET @q := IF (
  EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users_restaurants' AND COLUMN_NAME = 'is_active'
  ),
  'SELECT ''users_restaurants.is_active exists''',
  'ALTER TABLE users_restaurants ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1'
);
PREPARE stmt FROM @q; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- users_restaurants.station (optional station hint: hot/cold/bar/dessert)
SET @q := IF (
  EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users_restaurants' AND COLUMN_NAME = 'station'
  ),
  'SELECT ''users_restaurants.station exists''',
  'ALTER TABLE users_restaurants ADD COLUMN station VARCHAR(32) NULL DEFAULT NULL'
);
PREPARE stmt FROM @q; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- index for frequent membership checks
SET @q := IF (
  EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users_restaurants' AND INDEX_NAME = 'idx_user_rest_active'
  ),
  'SELECT ''users_restaurants.idx_user_rest_active exists''',
  'ALTER TABLE users_restaurants ADD INDEX idx_user_rest_active (user_id, restaurant_id, is_active)'
);
PREPARE stmt FROM @q; EXECUTE stmt; DEALLOCATE PREPARE stmt;

