-- Speed up upsell conversion_rate learning queries
-- (conversion_rate = upsell_added_to_cart / upsell_shown)

SET @idx_exists := (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'upsell_events'
    AND INDEX_NAME = 'idx_upsell_events_rest_event_item_created'
);

SET @sql := IF(
  @idx_exists = 0,
  'CREATE INDEX idx_upsell_events_rest_event_item_created
     ON upsell_events (restaurant_id, event, upsell_item_id, created_at);',
  'SELECT ''index already exists'';'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

