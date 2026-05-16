-- QRRest
-- Stage: station-based KDS item pipeline + stop-list foundation
-- Date: 2026-07-07

SET @schema := DATABASE();

-- order_items.kds_status
SET @oi_kds_status_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @schema
    AND TABLE_NAME = 'order_items'
    AND COLUMN_NAME = 'kds_status'
);
SET @sql := IF(
  @oi_kds_status_exists = 0,
  "ALTER TABLE order_items ADD COLUMN kds_status VARCHAR(32) NULL DEFAULT 'new'",
  "SELECT 'order_items.kds_status exists'"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- order_items.kds_started_at
SET @oi_kds_started_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @schema
    AND TABLE_NAME = 'order_items'
    AND COLUMN_NAME = 'kds_started_at'
);
SET @sql := IF(
  @oi_kds_started_exists = 0,
  "ALTER TABLE order_items ADD COLUMN kds_started_at DATETIME NULL",
  "SELECT 'order_items.kds_started_at exists'"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- order_items.kds_ready_at
SET @oi_kds_ready_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @schema
    AND TABLE_NAME = 'order_items'
    AND COLUMN_NAME = 'kds_ready_at'
);
SET @sql := IF(
  @oi_kds_ready_exists = 0,
  "ALTER TABLE order_items ADD COLUMN kds_ready_at DATETIME NULL",
  "SELECT 'order_items.kds_ready_at exists'"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- menu_items.is_temporarily_unavailable
SET @mi_tmp_unavailable_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @schema
    AND TABLE_NAME = 'menu_items'
    AND COLUMN_NAME = 'is_temporarily_unavailable'
);
SET @sql := IF(
  @mi_tmp_unavailable_exists = 0,
  "ALTER TABLE menu_items ADD COLUMN is_temporarily_unavailable TINYINT(1) NOT NULL DEFAULT 0",
  "SELECT 'menu_items.is_temporarily_unavailable exists'"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Backfill compatibility for legacy rows (dynamic SQL, parser-safe)
SET @oi_station_status_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @schema
    AND TABLE_NAME = 'order_items'
    AND COLUMN_NAME = 'station_status'
);
SET @sql := IF(
  @oi_kds_status_exists = 1 AND @oi_station_status_exists = 1,
  "UPDATE order_items SET kds_status = COALESCE(NULLIF(TRIM(kds_status), ''), NULLIF(TRIM(station_status), ''), 'new')",
  "SELECT 'order_items.kds_status backfill skipped'"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @oi_started_at_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @schema
    AND TABLE_NAME = 'order_items'
    AND COLUMN_NAME = 'started_at'
);
SET @sql := IF(
  @oi_kds_started_exists = 1 AND @oi_started_at_exists = 1,
  "UPDATE order_items SET kds_started_at = COALESCE(kds_started_at, started_at)",
  "SELECT 'order_items.kds_started_at backfill skipped'"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @oi_ready_at_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @schema
    AND TABLE_NAME = 'order_items'
    AND COLUMN_NAME = 'ready_at'
);
SET @sql := IF(
  @oi_kds_ready_exists = 1 AND @oi_ready_at_exists = 1,
  "UPDATE order_items SET kds_ready_at = COALESCE(kds_ready_at, ready_at)",
  "SELECT 'order_items.kds_ready_at backfill skipped'"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  @mi_tmp_unavailable_exists = 1,
  "UPDATE menu_items SET is_temporarily_unavailable = 0 WHERE is_temporarily_unavailable IS NULL",
  "SELECT 'menu_items.is_temporarily_unavailable normalize skipped'"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
