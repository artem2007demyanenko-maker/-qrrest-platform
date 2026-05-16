-- CRM identity separation:
-- - orders.crm_guest_id keeps explicit order -> crm_guests link
-- - crm_guests.loyalty_guest_id keeps optional crm -> loyalty/auth guest link
-- Loyalty orders.guest_id remains canonical auth/loyalty guest identity.

SET @exists := (
  SELECT COUNT(1) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'orders'
    AND COLUMN_NAME = 'crm_guest_id'
);

SET @sql := IF(
  @exists = 0,
  'ALTER TABLE `orders` ADD COLUMN `crm_guest_id` INT NULL AFTER `guest_card_id`;',
  'SELECT ''orders.crm_guest_id exists'';'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @exists := (
  SELECT COUNT(1) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'crm_guests'
    AND COLUMN_NAME = 'loyalty_guest_id'
);

SET @sql := IF(
  @exists = 0,
  'ALTER TABLE `crm_guests` ADD COLUMN `loyalty_guest_id` INT NULL AFTER `phone`;',
  'SELECT ''crm_guests.loyalty_guest_id exists'';'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @exists := (
  SELECT COUNT(1) FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'orders'
    AND INDEX_NAME = 'idx_orders_restaurant_crm_guest_created'
);

SET @sql := IF(
  @exists = 0,
  'ALTER TABLE `orders` ADD KEY `idx_orders_restaurant_crm_guest_created` (`restaurant_id`, `crm_guest_id`, `created_at`);',
  'SELECT ''idx_orders_restaurant_crm_guest_created exists'';'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @exists := (
  SELECT COUNT(1) FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'crm_guests'
    AND INDEX_NAME = 'idx_crm_guests_restaurant_loyalty_guest'
);

SET @sql := IF(
  @exists = 0,
  'ALTER TABLE `crm_guests` ADD KEY `idx_crm_guests_restaurant_loyalty_guest` (`restaurant_id`, `loyalty_guest_id`);',
  'SELECT ''idx_crm_guests_restaurant_loyalty_guest exists'';'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
