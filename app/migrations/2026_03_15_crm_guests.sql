-- CRM/Retention: guests by restaurant + phone
CREATE TABLE IF NOT EXISTS crm_guests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    restaurant_id INT NOT NULL,
    phone VARCHAR(32) NOT NULL,
    consent TINYINT(1) NOT NULL DEFAULT 0,
    first_seen_at TIMESTAMP NULL DEFAULT NULL,
    last_seen_at TIMESTAMP NULL DEFAULT NULL,
    visits_count INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_restaurant_id (restaurant_id),
    INDEX idx_phone (phone),
    UNIQUE KEY uniq_rest_phone (restaurant_id, phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
