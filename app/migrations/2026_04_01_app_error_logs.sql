-- App error log table for centralized error tracking (diagnostics).
CREATE TABLE IF NOT EXISTS app_error_logs (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    level VARCHAR(32) NOT NULL DEFAULT 'error',
    source VARCHAR(64) NOT NULL,
    message TEXT NOT NULL,
    context_json LONGTEXT NULL,
    rid VARCHAR(32) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_level_created (level, created_at),
    KEY idx_rid (rid),
    KEY idx_source_created (source, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
