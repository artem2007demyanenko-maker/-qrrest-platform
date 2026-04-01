CREATE TABLE IF NOT EXISTS order_feedback (
    id INT AUTO_INCREMENT PRIMARY KEY,
    restaurant_id INT NOT NULL,
    order_id INT NOT NULL,
    rating TINYINT NOT NULL,
    comment TEXT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_order_feedback_restaurant (restaurant_id),
    INDEX idx_order_feedback_order (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
