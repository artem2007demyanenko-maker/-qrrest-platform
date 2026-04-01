-- Growth Experiments: A/B tests and feature flags. Tenant-safe; assignments per restaurant.

CREATE TABLE IF NOT EXISTS growth_experiments (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(128) NOT NULL,
    description TEXT NULL,
    flag_key VARCHAR(64) NULL COMMENT 'For feature_flag type: key used in is_feature_enabled()',
    type VARCHAR(24) NOT NULL DEFAULT 'ab_test' COMMENT 'ab_test | feature_flag',
    status VARCHAR(24) NOT NULL DEFAULT 'draft' COMMENT 'draft | running | paused | completed',
    target_percentage INT NOT NULL DEFAULT 100 COMMENT '0-100, percent of restaurants to include',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    started_at DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_status (status),
    KEY idx_type_status (type, status),
    KEY idx_flag_key (flag_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS experiment_assignments (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    experiment_id INT UNSIGNED NOT NULL,
    restaurant_id INT NOT NULL,
    variant CHAR(1) NOT NULL DEFAULT 'A' COMMENT 'A | B',
    assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_exp_rest (experiment_id, restaurant_id),
    KEY idx_restaurant (restaurant_id),
    KEY idx_experiment (experiment_id),
    CONSTRAINT fk_ea_experiment FOREIGN KEY (experiment_id) REFERENCES growth_experiments(id) ON DELETE CASCADE,
    CONSTRAINT fk_ea_restaurant FOREIGN KEY (restaurant_id) REFERENCES restaurants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS experiment_metrics (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    experiment_id INT UNSIGNED NOT NULL,
    restaurant_id INT NOT NULL,
    orders INT NOT NULL DEFAULT 0,
    revenue DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    conversion DECIMAL(5,2) NOT NULL DEFAULT 0.00 COMMENT 'e.g. percentage 0-100',
    recorded_at DATE NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_exp_rest_date (experiment_id, restaurant_id, recorded_at),
    KEY idx_experiment (experiment_id),
    KEY idx_restaurant (restaurant_id),
    CONSTRAINT fk_em_experiment FOREIGN KEY (experiment_id) REFERENCES growth_experiments(id) ON DELETE CASCADE,
    CONSTRAINT fk_em_restaurant FOREIGN KEY (restaurant_id) REFERENCES restaurants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
