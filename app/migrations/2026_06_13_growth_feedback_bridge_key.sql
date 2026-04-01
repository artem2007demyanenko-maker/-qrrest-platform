-- Deterministic dedupe for feedback->CRM bridge suggestions.
-- Adds bridge_key and unique index (restaurant_id, type, bridge_key) when absent.

SET @col_exists := (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'growth_engine_suggestions'
    AND COLUMN_NAME = 'bridge_key'
);

SET @sql_col := IF(
  @col_exists = 0,
  'ALTER TABLE growth_engine_suggestions ADD COLUMN bridge_key VARCHAR(128) NULL AFTER payload_json',
  'SELECT 1'
);
PREPARE stmt_col FROM @sql_col;
EXECUTE stmt_col;
DEALLOCATE PREPARE stmt_col;

SET @uniq_exists := (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'growth_engine_suggestions'
    AND INDEX_NAME = 'uniq_rest_type_bridge_key'
);

SET @sql_idx := IF(
  @uniq_exists = 0,
  'ALTER TABLE growth_engine_suggestions ADD UNIQUE KEY uniq_rest_type_bridge_key (restaurant_id, type, bridge_key)',
  'SELECT 1'
);
PREPARE stmt_idx FROM @sql_idx;
EXECUTE stmt_idx;
DEALLOCATE PREPARE stmt_idx;
