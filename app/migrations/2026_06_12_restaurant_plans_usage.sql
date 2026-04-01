-- 2026-06-12: Restaurant-level subscription & usage metrics (passive, no payments).
-- Does not alter existing user-level subscriptions/invoices. Idempotent.

-- 1) restaurant_subscriptions (one active plan per restaurant; plan = free|growth|pro)
CREATE TABLE IF NOT EXISTS restaurant_subscriptions (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  restaurant_id INT NOT NULL,
  plan VARCHAR(24) NOT NULL DEFAULT 'free',
  status VARCHAR(24) NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_restaurant_sub (restaurant_id),
  KEY idx_rest_sub_plan (plan),
  KEY idx_rest_sub_status (status),
  KEY idx_rest_sub_expires (expires_at),
  CONSTRAINT fk_rest_sub_restaurant FOREIGN KEY (restaurant_id) REFERENCES restaurants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2) usage_metrics (per-restaurant metric counters per period; for limit checks later)
CREATE TABLE IF NOT EXISTS usage_metrics (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  restaurant_id INT NOT NULL,
  metric VARCHAR(64) NOT NULL,
  value BIGINT NOT NULL DEFAULT 0,
  period_start DATETIME NOT NULL,
  period_end DATETIME NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_usage_rest_metric_period (restaurant_id, metric, period_start),
  KEY idx_usage_restaurant (restaurant_id),
  KEY idx_usage_metric (metric),
  KEY idx_usage_period (period_start, period_end),
  CONSTRAINT fk_usage_metrics_restaurant FOREIGN KEY (restaurant_id) REFERENCES restaurants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
