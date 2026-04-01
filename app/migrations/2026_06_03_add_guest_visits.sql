-- Guest Retention Engine: guest_visits table (idempotent).

-- Create table if missing
SET @exists := (
  SELECT COUNT(1) FROM INFORMATION_SCHEMA.TABLES
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'guest_visits'
);

SET @sql := IF(
  @exists = 0,
  'CREATE TABLE `guest_visits` (
      id INT AUTO_INCREMENT PRIMARY KEY,
      restaurant_id INT NOT NULL,
      order_id INT NOT NULL,
      guest_contact VARCHAR(255) NULL,
      guest_name VARCHAR(255) NULL,
      visited_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;',
  'SELECT ''guest_visits already exists'';'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Index on restaurant_id
SET @exists := (
  SELECT COUNT(1) FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'guest_visits'
    AND INDEX_NAME = 'idx_guest_visits_restaurant'
);

SET @sql := IF(
  @exists = 0,
  'CREATE INDEX `idx_guest_visits_restaurant` ON `guest_visits` (restaurant_id);',
  'SELECT ''idx_guest_visits_restaurant exists'';'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Index on guest_contact
SET @exists := (
  SELECT COUNT(1) FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'guest_visits'
    AND INDEX_NAME = 'idx_guest_visits_contact'
);

SET @sql := IF(
  @exists = 0,
  'CREATE INDEX `idx_guest_visits_contact` ON `guest_visits` (guest_contact);',
  'SELECT ''idx_guest_visits_contact exists'';'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Index on visited_at
SET @exists := (
  SELECT COUNT(1) FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'guest_visits'
    AND INDEX_NAME = 'idx_guest_visits_visited'
);

SET @sql := IF(
  @exists = 0,
  'CREATE INDEX `idx_guest_visits_visited` ON `guest_visits` (visited_at);',
  'SELECT ''idx_guest_visits_visited exists'';'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

