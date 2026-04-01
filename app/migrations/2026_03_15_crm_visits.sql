-- CRM/Retention: visits per guest
CREATE TABLE IF NOT EXISTS crm_visits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    restaurant_id INT NOT NULL,
    guest_id INT NOT NULL,
    order_id INT NULL,
    table_id INT NULL,
    total_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    visited_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_restaurant_id (restaurant_id),
    INDEX idx_guest_id (guest_id),
    INDEX idx_order_id (order_id),
    INDEX idx_visited_at (visited_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
