-- KDS stations + item-level status (idempotent, MySQL)

-- 1) menu_items.station
SET @menu_station_exists := (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'menu_items'
    AND COLUMN_NAME = 'station'
);
SET @sql := IF(
  @menu_station_exists = 0,
  "ALTER TABLE menu_items ADD COLUMN station VARCHAR(50) NOT NULL DEFAULT 'hot'",
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2) order_items.station_status
SET @oi_station_status_exists := (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'order_items'
    AND COLUMN_NAME = 'station_status'
);
SET @sql := IF(
  @oi_station_status_exists = 0,
  "ALTER TABLE order_items ADD COLUMN station_status VARCHAR(32) NOT NULL DEFAULT 'new'",
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 3) order_items.started_at
SET @oi_started_at_exists := (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'order_items'
    AND COLUMN_NAME = 'started_at'
);
SET @sql := IF(
  @oi_started_at_exists = 0,
  "ALTER TABLE order_items ADD COLUMN started_at DATETIME NULL",
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 4) order_items.ready_at
SET @oi_ready_at_exists := (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'order_items'
    AND COLUMN_NAME = 'ready_at'
);
SET @sql := IF(
  @oi_ready_at_exists = 0,
  "ALTER TABLE order_items ADD COLUMN ready_at DATETIME NULL",
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

