CREATE TABLE IF NOT EXISTS stats_cache (
  cache_key VARCHAR(128) PRIMARY KEY,
  user_id INT NOT NULL,
  scope_hash VARCHAR(64) NOT NULL,
  created_at DATETIME NOT NULL,
  expires_at DATETIME NOT NULL,
  payload_json JSON NOT NULL,
  locked_until DATETIME NULL,
  KEY idx_user_scope (user_id, scope_hash),
  KEY idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Идемпотентное добавление locked_until при повторном запуске
SET @has_locked := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'stats_cache'
    AND COLUMN_NAME = 'locked_until'
);

SET @stmt_locked := IF(
  @has_locked = 0,
  'ALTER TABLE stats_cache ADD COLUMN locked_until DATETIME NULL',
  'SELECT 1'
);

PREPARE alter_locked FROM @stmt_locked;
EXECUTE alter_locked;
DEALLOCATE PREPARE alter_locked;

