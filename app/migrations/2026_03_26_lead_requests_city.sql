-- Optional: add city to lead_requests for pipeline (nullable)
SET @col_exists = (SELECT COUNT(1) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lead_requests' AND COLUMN_NAME = 'city');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE lead_requests ADD COLUMN city VARCHAR(255) NULL AFTER restaurant_name', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
