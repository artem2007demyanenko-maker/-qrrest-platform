-- Restaurant setting: Google review link (for post-feedback CTA). Idempotent.

SET @col_exists := (
  SELECT COUNT(1) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'restaurants' AND COLUMN_NAME = 'google_review_link'
);

SET @sql := IF(@col_exists = 0,
  'ALTER TABLE restaurants ADD COLUMN google_review_link VARCHAR(512) NULL DEFAULT NULL AFTER description',
  'SELECT 1'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
