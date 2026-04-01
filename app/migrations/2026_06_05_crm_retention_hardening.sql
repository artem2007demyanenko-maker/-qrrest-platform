-- CRM retention hardening:
-- - persist checkout CRM contact on orders until payment is completed
-- - support outbox dedupe / campaign attribution / processing timestamps

-- orders.crm_phone
SET @exists := (
  SELECT COUNT(1) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'orders'
    AND COLUMN_NAME = 'crm_phone'
);

SET @sql := IF(
  @exists = 0,
  'ALTER TABLE `orders` ADD COLUMN `crm_phone` VARCHAR(32) NULL AFTER `payment_status`;',
  'SELECT ''orders.crm_phone exists'';'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- orders.crm_consent
SET @exists := (
  SELECT COUNT(1) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'orders'
    AND COLUMN_NAME = 'crm_consent'
);

SET @sql := IF(
  @exists = 0,
  'ALTER TABLE `orders` ADD COLUMN `crm_consent` TINYINT(1) NOT NULL DEFAULT 0 AFTER `crm_phone`;',
  'SELECT ''orders.crm_consent exists'';'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- orders index for restaurant + crm_phone
SET @exists := (
  SELECT COUNT(1) FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'orders'
    AND INDEX_NAME = 'idx_orders_restaurant_crm_phone'
);

SET @sql := IF(
  @exists = 0,
  'ALTER TABLE `orders` ADD KEY `idx_orders_restaurant_crm_phone` (`restaurant_id`, `crm_phone`);',
  'SELECT ''idx_orders_restaurant_crm_phone exists'';'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- crm_outbox.campaign_id
SET @exists := (
  SELECT COUNT(1) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'crm_outbox'
    AND COLUMN_NAME = 'campaign_id'
);

SET @sql := IF(
  @exists = 0,
  'ALTER TABLE `crm_outbox` ADD COLUMN `campaign_id` INT NULL AFTER `guest_id`;',
  'SELECT ''crm_outbox.campaign_id exists'';'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- crm_outbox.dedupe_key
SET @exists := (
  SELECT COUNT(1) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'crm_outbox'
    AND COLUMN_NAME = 'dedupe_key'
);

SET @sql := IF(
  @exists = 0,
  'ALTER TABLE `crm_outbox` ADD COLUMN `dedupe_key` VARCHAR(64) NULL AFTER `status`;',
  'SELECT ''crm_outbox.dedupe_key exists'';'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- crm_outbox.processed_at
SET @exists := (
  SELECT COUNT(1) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'crm_outbox'
    AND COLUMN_NAME = 'processed_at'
);

SET @sql := IF(
  @exists = 0,
  'ALTER TABLE `crm_outbox` ADD COLUMN `processed_at` DATETIME NULL AFTER `updated_at`;',
  'SELECT ''crm_outbox.processed_at exists'';'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- crm_outbox.sent_at
SET @exists := (
  SELECT COUNT(1) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'crm_outbox'
    AND COLUMN_NAME = 'sent_at'
);

SET @sql := IF(
  @exists = 0,
  'ALTER TABLE `crm_outbox` ADD COLUMN `sent_at` DATETIME NULL AFTER `processed_at`;',
  'SELECT ''crm_outbox.sent_at exists'';'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- crm_outbox.failed_at
SET @exists := (
  SELECT COUNT(1) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'crm_outbox'
    AND COLUMN_NAME = 'failed_at'
);

SET @sql := IF(
  @exists = 0,
  'ALTER TABLE `crm_outbox` ADD COLUMN `failed_at` DATETIME NULL AFTER `sent_at`;',
  'SELECT ''crm_outbox.failed_at exists'';'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- crm_outbox.last_error
SET @exists := (
  SELECT COUNT(1) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'crm_outbox'
    AND COLUMN_NAME = 'last_error'
);

SET @sql := IF(
  @exists = 0,
  'ALTER TABLE `crm_outbox` ADD COLUMN `last_error` VARCHAR(255) NULL AFTER `failed_at`;',
  'SELECT ''crm_outbox.last_error exists'';'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- crm_outbox unique dedupe key
SET @exists := (
  SELECT COUNT(1) FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'crm_outbox'
    AND INDEX_NAME = 'uniq_crm_outbox_dedupe_key'
);

SET @sql := IF(
  @exists = 0,
  'ALTER TABLE `crm_outbox` ADD UNIQUE KEY `uniq_crm_outbox_dedupe_key` (`dedupe_key`);',
  'SELECT ''uniq_crm_outbox_dedupe_key exists'';'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- crm_outbox composite index for queue processing
SET @exists := (
  SELECT COUNT(1) FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'crm_outbox'
    AND INDEX_NAME = 'idx_crm_outbox_restaurant_status_scheduled'
);

SET @sql := IF(
  @exists = 0,
  'ALTER TABLE `crm_outbox` ADD KEY `idx_crm_outbox_restaurant_status_scheduled` (`restaurant_id`, `status`, `scheduled_at`);',
  'SELECT ''idx_crm_outbox_restaurant_status_scheduled exists'';'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- crm_outbox composite index for guest cooldown lookups
SET @exists := (
  SELECT COUNT(1) FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'crm_outbox'
    AND INDEX_NAME = 'idx_crm_outbox_restaurant_guest_status'
);

SET @sql := IF(
  @exists = 0,
  'ALTER TABLE `crm_outbox` ADD KEY `idx_crm_outbox_restaurant_guest_status` (`restaurant_id`, `guest_id`, `status`);',
  'SELECT ''idx_crm_outbox_restaurant_guest_status exists'';'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
