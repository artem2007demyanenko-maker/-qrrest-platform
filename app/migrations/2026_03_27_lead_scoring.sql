-- Lead scoring: score, priority, last_scored_at (idempotent for lead_requests)
SET @t = (SELECT COUNT(1) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lead_requests');
SET @score_exists = (SELECT COUNT(1) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lead_requests' AND COLUMN_NAME = 'score');
SET @priority_exists = (SELECT COUNT(1) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lead_requests' AND COLUMN_NAME = 'priority');
SET @last_scored_exists = (SELECT COUNT(1) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lead_requests' AND COLUMN_NAME = 'last_scored_at');

SET @sql = IF(@t > 0 AND @score_exists = 0, 'ALTER TABLE lead_requests ADD COLUMN score INT NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(@t > 0 AND @priority_exists = 0, 'ALTER TABLE lead_requests ADD COLUMN priority VARCHAR(16) NOT NULL DEFAULT ''cold''', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(@t > 0 AND @last_scored_exists = 0, 'ALTER TABLE lead_requests ADD COLUMN last_scored_at TIMESTAMP NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists = (SELECT COUNT(1) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lead_requests' AND INDEX_NAME = 'idx_priority_score');
SET @sql = IF(@t > 0 AND @idx_exists = 0, 'ALTER TABLE lead_requests ADD INDEX idx_priority_score (priority, score)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
