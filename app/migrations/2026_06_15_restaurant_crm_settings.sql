-- Per-restaurant CRM return settings (inactive threshold, feature toggle).
-- Idempotent: safe to run multiple times.

CREATE TABLE IF NOT EXISTS restaurant_crm_settings (
    restaurant_id INT NOT NULL,
    inactive_return_days INT NOT NULL DEFAULT 14,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (restaurant_id),
    CONSTRAINT fk_restaurant_crm_settings_restaurant FOREIGN KEY (restaurant_id) REFERENCES restaurants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
