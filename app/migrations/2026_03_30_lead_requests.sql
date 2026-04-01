-- Lead requests table for SaaS landing form (create only if not exists).
-- If table already exists (e.g. pipeline with contact_name, contact_phone), this does nothing.
CREATE TABLE IF NOT EXISTS lead_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(190) NULL,
    restaurant_name VARCHAR(255) NULL,
    phone VARCHAR(64) NULL,
    city VARCHAR(255) NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
