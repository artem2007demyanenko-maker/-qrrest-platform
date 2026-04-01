-- Guest QR smart upsell controls (enable + max 1–3 suggestions). Idempotent.

SET @col_en := (
  SELECT COUNT(1) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'restaurants' AND COLUMN_NAME = 'smart_upsell_guest_enabled'
);
SET @sql := IF(@col_en = 0,
  'ALTER TABLE restaurants ADD COLUMN smart_upsell_guest_enabled TINYINT(1) NOT NULL DEFAULT 1',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_mx := (
  SELECT COUNT(1) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'restaurants' AND COLUMN_NAME = 'smart_upsell_guest_max'
);
SET @sql2 := IF(@col_mx = 0,
  'ALTER TABLE restaurants ADD COLUMN smart_upsell_guest_max TINYINT NOT NULL DEFAULT 3',
  'SELECT 1'
);
PREPARE stmt2 FROM @sql2; EXECUTE stmt2; DEALLOCATE PREPARE stmt2;
