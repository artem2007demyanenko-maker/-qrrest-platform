-- P0: Hard duplicate protection for guest and CRM visits.

-- Unique index on guest_visits (restaurant_id, order_id)
SET @exists := (
  SELECT COUNT(1) FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'guest_visits'
    AND INDEX_NAME = 'uniq_guest_visits_restaurant_order'
);

SET @sql := IF(
  @exists = 0,
  'ALTER TABLE `guest_visits` ADD UNIQUE KEY `uniq_guest_visits_restaurant_order` (`restaurant_id`,`order_id`);',
  'SELECT ''uniq_guest_visits_restaurant_order exists'';'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Unique index on crm_visits (restaurant_id, order_id)
SET @exists := (
  SELECT COUNT(1) FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'crm_visits'
    AND INDEX_NAME = 'uniq_crm_visits_restaurant_order'
);

SET @sql := IF(
  @exists = 0,
  'ALTER TABLE `crm_visits` ADD UNIQUE KEY `uniq_crm_visits_restaurant_order` (`restaurant_id`,`order_id`);',
  'SELECT ''uniq_crm_visits_restaurant_order exists'';'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

