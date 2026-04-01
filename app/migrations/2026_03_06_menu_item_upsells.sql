-- menu_item_upsells: contextual "add to order" suggestions
CREATE TABLE IF NOT EXISTS menu_item_upsells (
    id INT AUTO_INCREMENT PRIMARY KEY,
    restaurant_id INT NOT NULL,
    base_item_id INT NOT NULL,
    upsell_item_id INT NOT NULL,
    weight INT NOT NULL DEFAULT 100,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_rest_base (restaurant_id, base_item_id),
    INDEX idx_rest_upsell (restaurant_id, upsell_item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
