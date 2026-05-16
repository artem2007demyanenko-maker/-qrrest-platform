-- QRRest: station-based KDS staff scope hardening (idempotent)
-- Safe for repeated execution on legacy/live schemas.

SET @db := DATABASE();

-- menu_items.station (legacy-facing station key: hot/cold/bar/dessert)
SET @menu_station_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @db
    AND TABLE_NAME = 'menu_items'
    AND COLUMN_NAME = 'station'
);
SET @sql := IF(
  @menu_station_exists > 0,
  "SELECT 'menu_items.station exists'",
  "ALTER TABLE menu_items ADD COLUMN station VARCHAR(32) NULL DEFAULT 'hot'"
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE menu_items
SET station = CASE
  WHEN LOWER(COALESCE(TRIM(station), '')) IN ('', 'kitchen') THEN 'hot'
  WHEN LOWER(COALESCE(TRIM(station), '')) = 'desserts' THEN 'dessert'
  ELSE LOWER(TRIM(station))
END;

-- users_restaurants.station (staff station hint)
SET @ur_station_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @db
    AND TABLE_NAME = 'users_restaurants'
    AND COLUMN_NAME = 'station'
);
SET @sql := IF(
  @ur_station_exists > 0,
  "SELECT 'users_restaurants.station exists'",
  "ALTER TABLE users_restaurants ADD COLUMN station VARCHAR(32) NULL DEFAULT NULL"
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- staff_users.station (if table exists on this installation)
SET @staff_users_table_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.TABLES
  WHERE TABLE_SCHEMA = @db
    AND TABLE_NAME = 'staff_users'
);
SET @staff_users_station_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @db
    AND TABLE_NAME = 'staff_users'
    AND COLUMN_NAME = 'station'
);
SET @sql := IF(
  @staff_users_table_exists = 0,
  "SELECT 'staff_users table missing'",
  IF(
    @staff_users_station_exists > 0,
    "SELECT 'staff_users.station exists'",
    "ALTER TABLE staff_users ADD COLUMN station VARCHAR(32) NULL DEFAULT 'all'"
  )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
