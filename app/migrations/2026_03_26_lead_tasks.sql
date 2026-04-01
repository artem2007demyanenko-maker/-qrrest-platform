-- Lead follow-up tasks
CREATE TABLE IF NOT EXISTS lead_tasks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    lead_id INT NOT NULL,
    assigned_user_id INT NULL,
    title VARCHAR(255) NOT NULL,
    due_at DATETIME NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'open',
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_lead_status_due (lead_id, status, due_at),
    INDEX idx_assigned_status (assigned_user_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
