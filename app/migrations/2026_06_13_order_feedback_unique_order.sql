-- Ensure one feedback per order at DB level.
-- Idempotent: adds UNIQUE(order_id) only when missing.

SET @uniq_exists := (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'order_feedback'
    AND COLUMN_NAME = 'order_id'
    AND NON_UNIQUE = 0
);

SET @sql := IF(
  @uniq_exists = 0,
  'ALTER TABLE order_feedback ADD UNIQUE KEY uniq_order_feedback_order (order_id)',
  'SELECT 1'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
