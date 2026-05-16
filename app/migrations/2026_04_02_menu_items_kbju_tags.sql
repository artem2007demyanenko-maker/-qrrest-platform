-- Adds nutrition and label fields for premium guest menu.
-- Safe for repeated runs on MySQL that supports IF NOT EXISTS.

ALTER TABLE menu_items
    ADD COLUMN IF NOT EXISTS calories INT NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS proteins DECIMAL(6,2) NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS fats DECIMAL(6,2) NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS carbs DECIMAL(6,2) NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS dietary_tags JSON NULL;

