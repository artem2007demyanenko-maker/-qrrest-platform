-- 2026-05-01: Viral growth — referrals (invite by email) and referral codes per restaurant. Idempotent.

-- referral_restaurant_codes: one code per restaurant (restaurant_slug + random), used in signup?ref=CODE
CREATE TABLE IF NOT EXISTS referral_restaurant_codes (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  restaurant_id INT NOT NULL,
  code VARCHAR(64) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_ref_rest_code (code),
  KEY idx_ref_rest_restaurant (restaurant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- referrals: invite tracking — referrer_restaurant_id, referred_email, status (invited/signed_up/active)
CREATE TABLE IF NOT EXISTS referrals (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  referrer_restaurant_id INT NOT NULL,
  referred_email VARCHAR(190) NOT NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'invited',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_referrals_restaurant (referrer_restaurant_id),
  KEY idx_referrals_email (referred_email),
  KEY idx_referrals_created (referrer_restaurant_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
