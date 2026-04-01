-- Checkout funnel: started_checkout vs completed_checkout for conversion analytics
CREATE TABLE IF NOT EXISTS checkout_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    restaurant_id INT NOT NULL,
    table_id INT NULL,
    event_type VARCHAR(32) NOT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_rest_created (restaurant_id, created_at),
    INDEX idx_rest_type_created (restaurant_id, event_type, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
