-- Guest feedback after order (PICKED UP / delivered). Idempotent.

CREATE TABLE IF NOT EXISTS order_feedback (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    order_id INT UNSIGNED NOT NULL,
    restaurant_id INT UNSIGNED NOT NULL,
    rating TINYINT UNSIGNED NOT NULL COMMENT '1-5',
    comment VARCHAR(2000) NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_restaurant_created (restaurant_id, created_at DESC),
    INDEX idx_order (order_id),
    UNIQUE KEY uniq_order (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
