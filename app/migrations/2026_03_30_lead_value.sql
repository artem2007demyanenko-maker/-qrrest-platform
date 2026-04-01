-- Expected MRR for lead pipeline forecast (idempotent).
SET @t = (SELECT COUNT(1) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lead_requests');
SET @col_exists = (SELECT COUNT(1) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lead_requests' AND COLUMN_NAME = 'expected_mrr');
SET @sql = IF(@t > 0 AND @col_exists = 0, 'ALTER TABLE lead_requests ADD COLUMN expected_mrr DECIMAL(10,2) NULL', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
