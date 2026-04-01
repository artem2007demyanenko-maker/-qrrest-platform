-- Cron run tracking for diagnostics and health.
CREATE TABLE IF NOT EXISTS cron_runs (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    job_name VARCHAR(64) NOT NULL,
    status VARCHAR(32) NOT NULL,
    started_at DATETIME NOT NULL,
    finished_at DATETIME NULL,
    details_json LONGTEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_job_created (job_name, created_at),
    KEY idx_status_created (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
