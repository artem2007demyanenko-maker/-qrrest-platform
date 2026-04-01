-- Growth Engine: suggestion lifecycle, priority, source, deduplication support
-- Run once after 2026_05_03_growth_engine_suggestions.sql
ALTER TABLE growth_engine_suggestions
    ADD COLUMN priority VARCHAR(10) NOT NULL DEFAULT 'medium',
    ADD COLUMN source VARCHAR(50) NULL,
    ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'pending',
    ADD COLUMN dismissed_at DATETIME NULL;

-- Index for listing by status (pending count on dashboard)
CREATE INDEX idx_rest_status ON growth_engine_suggestions (restaurant_id, status);

-- Index for deduplication: same restaurant, type, recent
CREATE INDEX idx_rest_type_created ON growth_engine_suggestions (restaurant_id, type, created_at);
