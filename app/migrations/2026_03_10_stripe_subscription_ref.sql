-- subscriptions.provider_ref for Stripe subscription id (idempotent lookups)
SET @col_exists = (SELECT COUNT(1) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subscriptions' AND COLUMN_NAME = 'provider_ref');
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE subscriptions ADD COLUMN provider_ref VARCHAR(128) NULL, ADD UNIQUE KEY uniq_sub_provider_ref (provider_ref);',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
