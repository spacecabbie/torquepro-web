-- ============================================================
-- Recommended additive schema improvements for Torque Pro web
-- Run these after the main schema.sql has been applied.
-- All changes are backward-compatible (additive only).
-- ============================================================

-- 1. Add source classification to sensors table
ALTER TABLE sensors
    ADD COLUMN source ENUM('obd','gps','calculated','custom') DEFAULT 'obd' AFTER is_gps,
    ADD COLUMN is_calculated BOOLEAN DEFAULT FALSE AFTER source,
    ADD INDEX idx_source (source);

-- 2. Add optional pid_mode for extended PIDs (Mode 22, etc.)
ALTER TABLE sensors
    ADD COLUMN pid_mode VARCHAR(8) NULL AFTER source;

-- 3. Optional: Add composite index for common "one sensor over time" queries
-- (Uncomment if you see slow dashboard queries)
-- CREATE INDEX idx_sensor_session_ts ON sensor_readings (sensor_key, session_id, timestamp);

-- 4. Ensure reprocessing updates an existing processed-upload row instead of
-- inserting duplicates. If this fails on an existing database, remove duplicate
-- raw_upload_id rows first and re-run it.
ALTER TABLE upload_requests_processed
    ADD UNIQUE KEY uq_raw_upload (raw_upload_id);

-- 5. (Optional) Unit alias table for future extensibility
CREATE TABLE IF NOT EXISTS unit_aliases (
    alias VARCHAR(32) PRIMARY KEY,
    canonical_unit_key VARCHAR(16) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (canonical_unit_key) REFERENCES unit_types(unit_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Example aliases (extend as needed)
INSERT IGNORE INTO unit_aliases (alias, canonical_unit_key) VALUES
('deg c', 'celsius'),
('deg f', 'fahrenheit'),
('deg', 'degree'),
('kph', 'kmh');
