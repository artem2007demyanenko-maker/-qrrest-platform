-- Production station routing foundation (idempotent)
-- Adds canonical station fields for station-aware KDS routing.

SET @db := DATABASE();

-- menu_items.production_station
SET @q := IF (
  EXISTS (
    SELECT 1
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @db
      AND TABLE_NAME = 'menu_items'
      AND COLUMN_NAME = 'production_station'
  ),
  'SELECT ''menu_items.production_station exists''',
  'ALTER TABLE menu_items ADD COLUMN production_station VARCHAR(32) NOT NULL DEFAULT ''kitchen'''
);
PREPARE stmt FROM @q; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- order_items.station_completed_at
SET @q := IF (
  EXISTS (
    SELECT 1
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @db
      AND TABLE_NAME = 'order_items'
      AND COLUMN_NAME = 'station_completed_at'
  ),
  'SELECT ''order_items.station_completed_at exists''',
  'ALTER TABLE order_items ADD COLUMN station_completed_at DATETIME NULL'
);
PREPARE stmt FROM @q; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- order_items.production_station
SET @q := IF (
  EXISTS (
    SELECT 1
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @db
      AND TABLE_NAME = 'order_items'
      AND COLUMN_NAME = 'production_station'
  ),
  'SELECT ''order_items.production_station exists''',
  'ALTER TABLE order_items ADD COLUMN production_station VARCHAR(32) NOT NULL DEFAULT ''kitchen'''
);
PREPARE stmt FROM @q; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- normalize menu stations
UPDATE menu_items
SET production_station = CASE
  WHEN LOWER(COALESCE(TRIM(production_station), '')) IN ('', 'hot', 'kitchen') THEN 'kitchen'
  WHEN LOWER(COALESCE(TRIM(production_station), '')) = 'desserts' THEN 'dessert'
  ELSE LOWER(TRIM(production_station))
END;

-- backfill order_items from menu_items canonical station where possible
UPDATE order_items oi
LEFT JOIN menu_items mi ON mi.id = oi.menu_item_id
SET oi.production_station = CASE
  WHEN LOWER(COALESCE(TRIM(oi.production_station), '')) IN ('', 'hot', 'kitchen') THEN
    CASE
      WHEN LOWER(COALESCE(TRIM(mi.production_station), '')) IN ('', 'hot', 'kitchen') THEN 'kitchen'
      WHEN LOWER(COALESCE(TRIM(mi.production_station), '')) = 'desserts' THEN 'dessert'
      WHEN LOWER(COALESCE(TRIM(mi.production_station), '')) <> '' THEN LOWER(TRIM(mi.production_station))
      ELSE 'kitchen'
    END
  WHEN LOWER(COALESCE(TRIM(oi.production_station), '')) = 'desserts' THEN 'dessert'
  ELSE LOWER(TRIM(oi.production_station))
END;

-- index menu_items(restaurant_id, production_station, available)
SET @q := IF (
  EXISTS (
    SELECT 1
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = @db
      AND TABLE_NAME = 'menu_items'
      AND INDEX_NAME = 'idx_menu_items_rest_prod_station_available'
  ),
  'SELECT ''menu_items.idx_menu_items_rest_prod_station_available exists''',
  'ALTER TABLE menu_items ADD INDEX idx_menu_items_rest_prod_station_available (restaurant_id, production_station, available)'
);
PREPARE stmt FROM @q; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- index order_items(order_id, production_station, id)
SET @q := IF (
  EXISTS (
    SELECT 1
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = @db
      AND TABLE_NAME = 'order_items'
      AND INDEX_NAME = 'idx_order_items_order_prod_station'
  ),
  'SELECT ''order_items.idx_order_items_order_prod_station exists''',
  'ALTER TABLE order_items ADD INDEX idx_order_items_order_prod_station (order_id, production_station, id)'
);
PREPARE stmt FROM @q; EXECUTE stmt; DEALLOCATE PREPARE stmt;
