-- CRM/Retention: outbox for scheduled messages (stub, no real send)
CREATE TABLE IF NOT EXISTS crm_outbox (
    id INT AUTO_INCREMENT PRIMARY KEY,
    restaurant_id INT NOT NULL,
    guest_id INT NOT NULL,
    channel VARCHAR(16) NOT NULL DEFAULT 'stub',
    template VARCHAR(64) NOT NULL DEFAULT 'come_back',
    payload_json TEXT NOT NULL,
    scheduled_at TIMESTAMP NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_restaurant_id (restaurant_id),
    INDEX idx_guest_id (guest_id),
    INDEX idx_scheduled_at (scheduled_at),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
