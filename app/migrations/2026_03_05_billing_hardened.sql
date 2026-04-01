-- 2026-03-05: Billing hardened (Variant 4). Idempotent.
-- 1) Backfill restaurants.owner_user_id from users_restaurants (один владелец на ресторан)
-- 2) Idempotency keys для invoices/payments (anti double-click)

-- Backfill owner_user_id: только где ровно один owner в users_restaurants
-- FIX ONLY_FULL_GROUP_BY: user_id не в GROUP BY — агрегируем (при HAVING COUNT(*)=1 одно значение)
UPDATE restaurants r
JOIN (
  SELECT ur.restaurant_id, ANY_VALUE(ur.user_id) AS user_id
  FROM users_restaurants ur
  WHERE ur.restaurant_role = 'owner'
  GROUP BY ur.restaurant_id
  HAVING COUNT(*) = 1
) one ON one.restaurant_id = r.id
SET r.owner_user_id = one.user_id
WHERE r.owner_user_id IS NULL;

-- Неоднозначные (несколько owners на ресторан) не трогаем — логировать вручную при необходимости:
-- SELECT restaurant_id, COUNT(*) FROM users_restaurants WHERE restaurant_role = 'owner' GROUP BY restaurant_id HAVING COUNT(*) > 1;

-- invoices: idempotency_key (UNIQUE, nullable для старых записей)
SET @col_exists = (
  SELECT COUNT(1) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invoices' AND COLUMN_NAME = 'idempotency_key'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE invoices ADD COLUMN idempotency_key VARCHAR(64) NULL, ADD UNIQUE KEY uniq_inv_idempotency (idempotency_key);',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- payments: idempotency_key для связи с тем же ключом, что и invoice (дубль submit не создаёт второй платёж)
SET @col_exists = (
  SELECT COUNT(1) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payments' AND COLUMN_NAME = 'idempotency_key'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE payments ADD COLUMN idempotency_key VARCHAR(64) NULL, ADD UNIQUE KEY uniq_pay_idempotency (idempotency_key);',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
