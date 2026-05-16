-- Upsell module schema hardening: upsell_rules + combo_rules.
-- Idempotent and safe for repeated execution on mixed legacy schemas.

CREATE TABLE IF NOT EXISTS upsell_rules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    restaurant_id INT NOT NULL,
    rule_type VARCHAR(32) NOT NULL,
    rule_value VARCHAR(128) NULL,
    priority INT NOT NULL DEFAULT 100,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_upsell_rules_restaurant_priority_active (restaurant_id, priority, active),
    INDEX idx_upsell_rules_restaurant_rule_type (restaurant_id, rule_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS combo_rules (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    restaurant_id INT NOT NULL,
    trigger_item_id INT NOT NULL,
    suggested_item_ids JSON NOT NULL,
    priority INT NOT NULL DEFAULT 100,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_combo_rules_restaurant_active_priority (restaurant_id, active, priority),
    INDEX idx_combo_rules_restaurant_trigger_active (restaurant_id, trigger_item_id, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
