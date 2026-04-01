-- DB-level idempotency: one order can credit a guest at most once per type.
-- Safe to re-run.

SET @tx_exists := (
  SELECT COUNT(1) FROM INFORMATION_SCHEMA.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'guest_loyalty_tx'
);

SET @idx_exists := (
  SELECT COUNT(1) FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'guest_loyalty_tx'
    AND INDEX_NAME = 'uniq_loyalty_order'
);

SET @sql := IF(@tx_exists > 0 AND @idx_exists = 0,
  'ALTER TABLE guest_loyalty_tx ADD UNIQUE KEY uniq_loyalty_order (guest_id, restaurant_id, order_id, type)',
  'SELECT 1'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
