-- Add setup fields to restaurants (idempotent).
SET @t = (SELECT COUNT(1) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'restaurants');
SET @city_exists = (SELECT COUNT(1) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'restaurants' AND COLUMN_NAME = 'city');
SET @currency_exists = (SELECT COUNT(1) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'restaurants' AND COLUMN_NAME = 'currency');
SET @timezone_exists = (SELECT COUNT(1) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'restaurants' AND COLUMN_NAME = 'timezone');
SET @language_exists = (SELECT COUNT(1) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'restaurants' AND COLUMN_NAME = 'language');

SET @sql = IF(@t > 0 AND @city_exists = 0, 'ALTER TABLE restaurants ADD COLUMN city VARCHAR(255) NULL', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(@t > 0 AND @currency_exists = 0, 'ALTER TABLE restaurants ADD COLUMN currency VARCHAR(8) NULL DEFAULT ''RUB''', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(@t > 0 AND @timezone_exists = 0, 'ALTER TABLE restaurants ADD COLUMN timezone VARCHAR(64) NULL DEFAULT ''Europe/Moscow''', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(@t > 0 AND @language_exists = 0, 'ALTER TABLE restaurants ADD COLUMN language VARCHAR(16) NULL DEFAULT ''ru''', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
