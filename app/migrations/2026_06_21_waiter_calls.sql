CREATE TABLE IF NOT EXISTS waiter_calls (
    id INT AUTO_INCREMENT PRIMARY KEY,
    restaurant_id INT NOT NULL,
    table_id INT NOT NULL,
    order_id INT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolved_at DATETIME NULL,
    KEY idx_waiter_calls_rest_table (restaurant_id, table_id),
    KEY idx_waiter_calls_rest_status (restaurant_id, status),
    KEY idx_waiter_calls_rest_order (restaurant_id, order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
