-- db/patches/0002_add_some_columns.sql

-- Пример: добавить колонку, если её нет
SET @col := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'orders'
    AND COLUMN_NAME = 'some_new_col'
);

SET @sql := IF(@col = 0,
  'ALTER TABLE orders ADD COLUMN some_new_col VARCHAR(64) NULL;',
  'SELECT 1;'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;