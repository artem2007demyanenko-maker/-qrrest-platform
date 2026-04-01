-- Upsell analytics v1: events shown / add_click / accepted_in_order
CREATE TABLE IF NOT EXISTS upsell_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    restaurant_id INT NOT NULL,
    table_id INT NULL,
    order_id INT NULL,
    session_key VARCHAR(64) NULL,
    event VARCHAR(32) NOT NULL,
    base_item_id INT NULL,
    upsell_item_id INT NULL,
    meta_json TEXT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_restaurant_id (restaurant_id),
    INDEX idx_table_id (table_id),
    INDEX idx_order_id (order_id),
    INDEX idx_session_key (session_key),
    INDEX idx_event (event),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
