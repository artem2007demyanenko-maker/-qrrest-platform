-- Courier foundation for delivery operations.
-- Adds minimal courier columns to orders in an idempotent way.

SET @orders_table_exists := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'orders'
);

SET @orders_courier_status_exists := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'orders'
      AND COLUMN_NAME = 'courier_status'
);

SET @add_courier_status_sql := IF(
    @orders_table_exists = 1 AND @orders_courier_status_exists = 0,
    'ALTER TABLE orders ADD COLUMN courier_status VARCHAR(24) NULL',
    'SELECT 1'
);
PREPARE add_courier_status_stmt FROM @add_courier_status_sql;
EXECUTE add_courier_status_stmt;
DEALLOCATE PREPARE add_courier_status_stmt;

SET @orders_courier_user_id_exists := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'orders'
      AND COLUMN_NAME = 'courier_user_id'
);

SET @add_courier_user_id_sql := IF(
    @orders_table_exists = 1 AND @orders_courier_user_id_exists = 0,
    'ALTER TABLE orders ADD COLUMN courier_user_id INT NULL',
    'SELECT 1'
);
PREPARE add_courier_user_id_stmt FROM @add_courier_user_id_sql;
EXECUTE add_courier_user_id_stmt;
DEALLOCATE PREPARE add_courier_user_id_stmt;

SET @orders_courier_taken_at_exists := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'orders'
      AND COLUMN_NAME = 'courier_taken_at'
);

SET @add_courier_taken_at_sql := IF(
    @orders_table_exists = 1 AND @orders_courier_taken_at_exists = 0,
    'ALTER TABLE orders ADD COLUMN courier_taken_at DATETIME NULL',
    'SELECT 1'
);
PREPARE add_courier_taken_at_stmt FROM @add_courier_taken_at_sql;
EXECUTE add_courier_taken_at_stmt;
DEALLOCATE PREPARE add_courier_taken_at_stmt;

SET @orders_courier_on_the_way_at_exists := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'orders'
      AND COLUMN_NAME = 'courier_on_the_way_at'
);

SET @add_courier_on_the_way_at_sql := IF(
    @orders_table_exists = 1 AND @orders_courier_on_the_way_at_exists = 0,
    'ALTER TABLE orders ADD COLUMN courier_on_the_way_at DATETIME NULL',
    'SELECT 1'
);
PREPARE add_courier_on_the_way_at_stmt FROM @add_courier_on_the_way_at_sql;
EXECUTE add_courier_on_the_way_at_stmt;
DEALLOCATE PREPARE add_courier_on_the_way_at_stmt;

SET @orders_delivered_at_exists := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'orders'
      AND COLUMN_NAME = 'delivered_at'
);

SET @add_delivered_at_sql := IF(
    @orders_table_exists = 1 AND @orders_delivered_at_exists = 0,
    'ALTER TABLE orders ADD COLUMN delivered_at DATETIME NULL',
    'SELECT 1'
);
PREPARE add_delivered_at_stmt FROM @add_delivered_at_sql;
EXECUTE add_delivered_at_stmt;
DEALLOCATE PREPARE add_delivered_at_stmt;

SET @orders_courier_idx_exists := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'orders'
      AND INDEX_NAME = 'idx_orders_rest_type_courier_created'
);

SET @add_courier_idx_sql := IF(
    @orders_table_exists = 1 AND @orders_courier_idx_exists = 0,
    'CREATE INDEX idx_orders_rest_type_courier_created ON orders (restaurant_id, order_type, courier_status, created_at)',
    'SELECT 1'
);
PREPARE add_courier_idx_stmt FROM @add_courier_idx_sql;
EXECUTE add_courier_idx_stmt;
DEALLOCATE PREPARE add_courier_idx_stmt;

SET @orders_courier_user_idx_exists := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'orders'
      AND INDEX_NAME = 'idx_orders_courier_user_created'
);

SET @add_courier_user_idx_sql := IF(
    @orders_table_exists = 1 AND @orders_courier_user_idx_exists = 0,
    'CREATE INDEX idx_orders_courier_user_created ON orders (courier_user_id, created_at)',
    'SELECT 1'
);
PREPARE add_courier_user_idx_stmt FROM @add_courier_user_idx_sql;
EXECUTE add_courier_user_idx_stmt;
DEALLOCATE PREPARE add_courier_user_idx_stmt;
