-- Guest SMS OTP + phone uniqueness (idempotent where supported).

CREATE TABLE IF NOT EXISTS otp_codes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    restaurant_id INT NOT NULL,
    phone VARCHAR(20) NOT NULL,
    code VARCHAR(10) NOT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL,
    attempts INT NOT NULL DEFAULT 0,
    consumed_at TIMESTAMP NULL DEFAULT NULL,
    KEY idx_restaurant_phone (restaurant_id, phone),
    KEY idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Optional: enforce unique phone on guests (run after deduplicating data):
-- ALTER TABLE guests ADD UNIQUE KEY uniq_guests_phone (phone);
