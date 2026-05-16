-- Add restaurants.owner_user_id for project-admin owner assignment.
-- Production-safe, idempotent, legacy-friendly (no foreign key).

SET @q := (
  SELECT IF(
    EXISTS (
      SELECT 1
      FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'restaurants'
        AND COLUMN_NAME = 'owner_user_id'
    ),
    'SELECT ''restaurants.owner_user_id exists'';',
    'ALTER TABLE restaurants ADD COLUMN owner_user_id INT NULL;'
  )
);
PREPARE stmt FROM @q; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @q := (
  SELECT IF(
    EXISTS (
      SELECT 1
      FROM INFORMATION_SCHEMA.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'restaurants'
        AND INDEX_NAME = 'idx_restaurants_owner'
    ),
    'SELECT ''idx_restaurants_owner exists'';',
    'ALTER TABLE restaurants ADD INDEX idx_restaurants_owner (owner_user_id);'
  )
);
PREPARE stmt FROM @q; EXECUTE stmt; DEALLOCATE PREPARE stmt;
