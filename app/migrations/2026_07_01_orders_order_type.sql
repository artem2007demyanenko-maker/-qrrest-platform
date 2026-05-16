-- Unified Order Center foundation: add normalized order_type to orders.
-- Safe for repeated runs on mixed legacy schemas.

SET @orders_table_exists := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'orders'
);

SET @orders_order_type_exists := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'orders'
      AND COLUMN_NAME = 'order_type'
);

SET @add_order_type_sql := IF(
    @orders_table_exists = 1 AND @orders_order_type_exists = 0,
    'ALTER TABLE orders ADD COLUMN order_type VARCHAR(16) NULL DEFAULT NULL',
    'SELECT 1'
);
PREPARE add_order_type_stmt FROM @add_order_type_sql;
EXECUTE add_order_type_stmt;
DEALLOCATE PREPARE add_order_type_stmt;

SET @orders_order_type_idx_exists := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'orders'
      AND INDEX_NAME = 'idx_orders_rest_type_created'
);

SET @add_order_type_idx_sql := IF(
    @orders_table_exists = 1 AND @orders_order_type_idx_exists = 0,
    'CREATE INDEX idx_orders_rest_type_created ON orders (restaurant_id, order_type, created_at)',
    'SELECT 1'
);
PREPARE add_order_type_idx_stmt FROM @add_order_type_idx_sql;
EXECUTE add_order_type_idx_stmt;
DEALLOCATE PREPARE add_order_type_idx_stmt;

SET @backfill_order_type_sql := IF(
    @orders_table_exists = 1 AND @orders_order_type_exists = 1,
    'UPDATE orders SET order_type = ''hall'' WHERE order_type IS NULL OR TRIM(order_type) = ''''',
    'SELECT 1'
);
PREPARE backfill_order_type_stmt FROM @backfill_order_type_sql;
EXECUTE backfill_order_type_stmt;
DEALLOCATE PREPARE backfill_order_type_stmt;
