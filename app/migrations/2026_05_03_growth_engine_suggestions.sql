-- Growth Engine: stored suggestions/drafts (no auto-modify menu, no auto-send)
CREATE TABLE IF NOT EXISTS growth_engine_suggestions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    restaurant_id INT NOT NULL,
    type VARCHAR(48) NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT NULL,
    payload_json TEXT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_rest_created (restaurant_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
