-- Stop-list foundation: ensure menu_items.available exists and is normalized.
-- Idempotent, safe for legacy schemas.

SET @q := (
  SELECT IF(
    EXISTS (
      SELECT 1
      FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'menu_items'
        AND COLUMN_NAME = 'available'
    ),
    'SELECT ''menu_items.available exists'';',
    'ALTER TABLE menu_items ADD COLUMN available TINYINT(1) NOT NULL DEFAULT 1;'
  )
);
PREPARE stmt FROM @q; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE menu_items
SET available = 1
WHERE available IS NULL;
