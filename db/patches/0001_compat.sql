-- 0001_compat.sql
-- Патч совместимости: добавляет колонки/настройки, которые ожидает код (без дублей)

-- orders.total_price
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE table_schema = DATABASE() AND table_name='orders' AND column_name='total_price') = 0,
  'ALTER TABLE orders ADD COLUMN total_price DECIMAL(10,2) NOT NULL DEFAULT 0',
  'DO 0'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- orders.payment_type (DEFAULT 'cash')
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE table_schema = DATABASE() AND table_name='orders' AND column_name='payment_type') = 0,
  'ALTER TABLE orders ADD COLUMN payment_type VARCHAR(32) NOT NULL DEFAULT ''cash''',
  'DO 0'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- orders.loyalty_phone
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE table_schema = DATABASE() AND table_name='orders' AND column_name='loyalty_phone') = 0,
  'ALTER TABLE orders ADD COLUMN loyalty_phone VARCHAR(32) NULL',
  'DO 0'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- orders.loyalty_points_accrued
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE table_schema = DATABASE() AND table_name='orders' AND column_name='loyalty_points_accrued') = 0,
  'ALTER TABLE orders ADD COLUMN loyalty_points_accrued INT NOT NULL DEFAULT 0',
  'DO 0'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- orders.loyalty_points_spent
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE table_schema = DATABASE() AND table_name='orders' AND column_name='loyalty_points_spent') = 0,
  'ALTER TABLE orders ADD COLUMN loyalty_points_spent INT NOT NULL DEFAULT 0',
  'DO 0'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- orders.loyalty_points_balance_after
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE table_schema = DATABASE() AND table_name='orders' AND column_name='loyalty_points_balance_after') = 0,
  'ALTER TABLE orders ADD COLUMN loyalty_points_balance_after INT NOT NULL DEFAULT 0',
  'DO 0'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- order_items.item_name DEFAULT ''
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE table_schema = DATABASE() AND table_name='order_items' AND column_name='item_name') > 0,
  'ALTER TABLE order_items MODIFY COLUMN item_name VARCHAR(255) NOT NULL DEFAULT ''''',
  'DO 0'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
