-- Restaurant setting: Yandex Maps review link (post-feedback CTA). Idempotent.

SET @col_exists := (
  SELECT COUNT(1) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'restaurants' AND COLUMN_NAME = 'yandex_review_url'
);

SET @sql := IF(@col_exists = 0,
  'ALTER TABLE restaurants ADD COLUMN yandex_review_url VARCHAR(512) NULL DEFAULT NULL AFTER google_review_link',
  'SELECT 1'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
