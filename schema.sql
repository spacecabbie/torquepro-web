-- ================================================================
-- Torque Logs — Complete Database Schema
-- Clean slate design for normalized, PSR-compliant architecture
-- 
-- Features:
-- - Sensor taxonomy with category/unit grouping
-- - Narrow time-series table (no dynamic ALTER TABLE)
-- - Raw upload preservation with date-based partitioning
-- - Unit conversion support (bar↔PSI, °C↔°F, km/h↔mph)
-- - Easy archival/cleanup via partition management
--
-- Compatible with: MariaDB 10.5+, MySQL 8.0+
-- Character set: UTF-8mb4 (supports emoji, international chars)
-- Engine: InnoDB (referential integrity, transactions, crash recovery)
-- ================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ================================================================
-- SENSOR TAXONOMY & METADATA
-- ================================================================

-- Category taxonomy (pressure, temperature, fuel, etc.)
CREATE TABLE sensor_categories (
  id TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  category_key VARCHAR(32) UNIQUE NOT NULL COMMENT 'Machine key (pressure, temperature, speed)',
  display_name VARCHAR(64) NOT NULL COMMENT 'Human-readable name',
  description TEXT DEFAULT NULL,
  icon VARCHAR(32) DEFAULT NULL COMMENT 'Optional icon/emoji identifier',
  sort_order SMALLINT UNSIGNED DEFAULT 100,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_category_key (category_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Sensor category taxonomy (pressure, temperature, speed, etc)';

-- Pre-populate common categories
INSERT INTO sensor_categories (category_key, display_name, icon, sort_order) VALUES
  ('pressure', 'Pressure', '📊', 10),
  ('temperature', 'Temperature', '🌡️', 20),
  ('speed', 'Speed & Distance', '🚗', 30),
  ('fuel', 'Fuel System', '⛽', 40),
  ('engine', 'Engine Performance', '⚙️', 50),
  ('electrical', 'Electrical', '🔋', 60),
  ('gps', 'GPS & Location', '📍', 70),
  ('emissions', 'Emissions & Exhaust', '💨', 80),
  ('transmission', 'Transmission', '⚡', 90),
  ('other', 'Other', '📋', 999);

-- Unit definitions with SI conversion factors
CREATE TABLE unit_types (
  id SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  category_id TINYINT UNSIGNED NOT NULL COMMENT 'Links to sensor_categories',
  unit_key VARCHAR(16) UNIQUE NOT NULL COMMENT 'Canonical unit (psi, bar, celsius, etc)',
  symbol VARCHAR(16) NOT NULL COMMENT 'Display symbol (PSI, bar, °C, km/h)',
  display_name VARCHAR(64) NOT NULL,
  to_si_multiplier DECIMAL(15,8) DEFAULT NULL COMMENT 'Multiply by this to get SI base unit',
  to_si_offset DECIMAL(10,4) DEFAULT 0 COMMENT 'Add this after multiplication (for °C→K)',
  si_base_unit VARCHAR(16) DEFAULT NULL COMMENT 'SI reference (pascal, kelvin, m/s)',
  is_default BOOLEAN DEFAULT FALSE COMMENT 'Default unit for this category',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_category (category_id),
  INDEX idx_unit_key (unit_key),
  FOREIGN KEY (category_id) REFERENCES sensor_categories(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Unit definitions with conversion factors to SI base units';

-- Pre-populate common units
INSERT INTO unit_types (category_id, unit_key, symbol, display_name, to_si_multiplier, si_base_unit, is_default) VALUES
  -- Pressure (SI base: pascal)
  (1, 'bar', 'bar', 'Bar', 100000, 'pascal', TRUE),
  (1, 'psi', 'PSI', 'Pounds per Square Inch', 6894.757, 'pascal', FALSE),
  (1, 'kpa', 'kPa', 'Kilopascal', 1000, 'pascal', FALSE),
  (1, 'mbar', 'mbar', 'Millibar', 100, 'pascal', FALSE),
  -- Temperature (SI base: kelvin, but we use °C as reference)
  (2, 'celsius', '°C', 'Celsius', 1, 'celsius', TRUE),
  (2, 'fahrenheit', '°F', 'Fahrenheit', 0.5555556, 'celsius', FALSE),
  (2, 'kelvin', 'K', 'Kelvin', 1, 'kelvin', FALSE),
  -- Speed (SI base: m/s)
  (3, 'kmh', 'km/h', 'Kilometers per Hour', 0.2777778, 'm/s', TRUE),
  (3, 'mph', 'mph', 'Miles per Hour', 0.44704, 'm/s', FALSE),
  (3, 'ms', 'm/s', 'Meters per Second', 1, 'm/s', FALSE),
  -- Fuel consumption
  (4, 'lh', 'L/h', 'Liters per Hour', 1, 'L/h', TRUE),
  (4, 'gph', 'gal/h', 'Gallons per Hour', 3.78541, 'L/h', FALSE),
  (4, 'mgcp', 'mg/cp', 'Milligram per Cycle', 1, 'mg/cp', FALSE),
  (4, 'ccmin', 'cc/min', 'Cubic Centimeters per Minute', 0.06, 'L/h', FALSE),
  -- Voltage
  (6, 'volt', 'V', 'Volt', 1, 'volt', TRUE),
  -- Percent
  (5, 'percent', '%', 'Percent', 1, 'percent', TRUE),
  -- RPM
  (5, 'rpm', 'RPM', 'Revolutions per Minute', 1, 'rpm', TRUE),
  -- Flow
  (8, 'm3h', 'm³/h', 'Cubic Meters per Hour', 1, 'm³/h', TRUE),
  -- Angle
  (9, 'degree', '°', 'Degree', 1, 'degree', TRUE);

-- Master sensor registry
CREATE TABLE sensors (
  sensor_key VARCHAR(32) PRIMARY KEY COMMENT 'Torque PID key (k222408, kff1005)',
  short_name VARCHAR(255) DEFAULT NULL COMMENT 'User-friendly short label',
  full_name VARCHAR(255) DEFAULT NULL COMMENT 'Full descriptive name with PID code',
  category_id TINYINT UNSIGNED DEFAULT NULL COMMENT 'Links to sensor_categories',
  unit_id SMALLINT UNSIGNED DEFAULT NULL COMMENT 'Links to unit_types',
  data_type ENUM('float', 'varchar', 'int') DEFAULT 'float',
  min_value DECIMAL(12,4) DEFAULT NULL COMMENT 'Expected minimum value',
  max_value DECIMAL(12,4) DEFAULT NULL COMMENT 'Expected maximum value',
  is_plottable BOOLEAN DEFAULT TRUE COMMENT 'Show in chart variable picker',
  is_gps BOOLEAN DEFAULT FALSE COMMENT 'GPS-related sensor',
  first_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_updated DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_category (category_id),
  INDEX idx_unit (unit_id),
  INDEX idx_plottable (is_plottable),
  INDEX idx_gps (is_gps),
  INDEX idx_short_name (short_name),
  FOREIGN KEY (category_id) REFERENCES sensor_categories(id) ON DELETE SET NULL,
  FOREIGN KEY (unit_id) REFERENCES unit_types(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Master registry of all Torque sensors with category/unit linkage';

-- ================================================================
-- SESSIONS & TIME-SERIES DATA
-- ================================================================

-- Drive sessions
CREATE TABLE sessions (
  session_id VARCHAR(32) PRIMARY KEY COMMENT 'Torque session ID (Unix ms timestamp)',
  device_id VARCHAR(64) NOT NULL COMMENT 'MD5 hashed Torque device ID',
  email VARCHAR(255) DEFAULT NULL COMMENT 'User email from Torque settings',
  profile_name VARCHAR(255) DEFAULT NULL COMMENT 'Torque profile name',
  start_time DATETIME NOT NULL COMMENT 'First upload timestamp for this session',
  end_time DATETIME DEFAULT NULL COMMENT 'Last upload timestamp (updated on each upload)',
  duration_seconds INT UNSIGNED DEFAULT NULL COMMENT 'Session duration (end - start)',
  total_readings INT UNSIGNED DEFAULT 0 COMMENT 'Total sensor readings in this session',
  distance_km DECIMAL(10,2) DEFAULT NULL COMMENT 'Total distance traveled (if GPS available)',
  avg_speed_kmh DECIMAL(6,2) DEFAULT NULL COMMENT 'Average speed (if available)',
  max_speed_kmh DECIMAL(6,2) DEFAULT NULL COMMENT 'Maximum speed',
  max_rpm DECIMAL(8,2) DEFAULT NULL COMMENT 'Maximum RPM',
  avg_coolant_temp_c DECIMAL(5,2) DEFAULT NULL COMMENT 'Average coolant temperature',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_device_id (device_id),
  INDEX idx_start_time (start_time),
  INDEX idx_email (email),
  INDEX idx_profile (profile_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Torque drive sessions';

-- Time-series sensor data (narrow table)
CREATE TABLE sensor_readings (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  session_id VARCHAR(32) NOT NULL,
  timestamp BIGINT NOT NULL COMMENT 'Data point timestamp (Unix ms from Torque)',
  sensor_key VARCHAR(32) NOT NULL,
  value DECIMAL(12,4) NOT NULL COMMENT 'Numeric value (unit as per sensors.unit_id)',
  INDEX idx_session_ts (session_id, timestamp),
  INDEX idx_sensor_session (sensor_key, session_id),
  INDEX idx_timestamp (timestamp),
  FOREIGN KEY (session_id) REFERENCES sessions(session_id) ON DELETE CASCADE,
  FOREIGN KEY (sensor_key) REFERENCES sensors(sensor_key) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Time-series sensor readings (narrow format)';

-- GPS track data (optimized for mapping)
CREATE TABLE gps_points (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  session_id VARCHAR(32) NOT NULL,
  timestamp BIGINT NOT NULL COMMENT 'GPS fix timestamp (Unix ms)',
  latitude DECIMAL(10,8) NOT NULL,
  longitude DECIMAL(11,8) NOT NULL,
  altitude DECIMAL(8,2) DEFAULT NULL COMMENT 'Altitude in meters',
  speed_kmh DECIMAL(6,2) DEFAULT NULL COMMENT 'GPS speed',
  bearing DECIMAL(5,2) DEFAULT NULL COMMENT 'GPS bearing (0-360°)',
  accuracy DECIMAL(6,2) DEFAULT NULL COMMENT 'GPS accuracy in meters',
  satellites TINYINT UNSIGNED DEFAULT NULL COMMENT 'Number of GPS satellites',
  INDEX idx_session_ts (session_id, timestamp),
  INDEX idx_lat_lng (latitude, longitude),
  FOREIGN KEY (session_id) REFERENCES sessions(session_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='GPS track points';

-- ================================================================
-- RAW UPLOAD AUDIT (archivable/cleanable)
-- ================================================================

-- Raw upload requests with date-based partitioning for easy archival
CREATE TABLE upload_requests_raw (
  id BIGINT UNSIGNED AUTO_INCREMENT,
  ts DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Server receipt time',
  upload_date DATE NOT NULL COMMENT 'Date for partitioning/archival (auto-set from ts)',
  ip VARCHAR(45) NOT NULL COMMENT 'Client IP address',
  device_id VARCHAR(64) NOT NULL COMMENT 'MD5 hashed Torque device ID',
  session_id VARCHAR(32) DEFAULT NULL COMMENT 'Session ID extracted from query string',
  raw_query_string TEXT NOT NULL COMMENT 'Complete unedited query string from Torque',
  result ENUM('ok', 'skipped', 'error') NOT NULL DEFAULT 'ok',
  error_msg TEXT DEFAULT NULL COMMENT 'Error message if result=error',
  processing_time_ms SMALLINT UNSIGNED DEFAULT NULL COMMENT 'Server processing time',
  PRIMARY KEY (id, upload_date),
  INDEX idx_session (session_id),
  INDEX idx_ts (ts),
  INDEX idx_device (device_id),
  INDEX idx_result (result)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Raw upload audit log with date-based partitioning'
PARTITION BY RANGE (TO_DAYS(upload_date)) (
  PARTITION p_2026_01 VALUES LESS THAN (TO_DAYS('2026-02-01')),
  PARTITION p_2026_02 VALUES LESS THAN (TO_DAYS('2026-03-01')),
  PARTITION p_2026_03 VALUES LESS THAN (TO_DAYS('2026-04-01')),
  PARTITION p_2026_04 VALUES LESS THAN (TO_DAYS('2026-05-01')),
  PARTITION p_2026_05 VALUES LESS THAN (TO_DAYS('2026-06-01')),
  PARTITION p_2026_06 VALUES LESS THAN (TO_DAYS('2026-07-01')),
  PARTITION p_2026_07 VALUES LESS THAN (TO_DAYS('2026-08-01')),
  PARTITION p_2026_08 VALUES LESS THAN (TO_DAYS('2026-09-01')),
  PARTITION p_2026_09 VALUES LESS THAN (TO_DAYS('2026-10-01')),
  PARTITION p_2026_10 VALUES LESS THAN (TO_DAYS('2026-11-01')),
  PARTITION p_2026_11 VALUES LESS THAN (TO_DAYS('2026-12-01')),
  PARTITION p_2026_12 VALUES LESS THAN (TO_DAYS('2027-01-01')),
  PARTITION p_future VALUES LESS THAN MAXVALUE
);

-- Processed upload summary (links to raw data)
CREATE TABLE upload_requests_processed (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  raw_upload_id BIGINT UNSIGNED NOT NULL COMMENT 'Links to upload_requests_raw.id',
  session_id VARCHAR(32) NOT NULL,
  data_timestamp BIGINT DEFAULT NULL COMMENT 'Data point timestamp from Torque (Unix ms)',
  sensor_count SMALLINT UNSIGNED DEFAULT 0 COMMENT 'Number of sensor values in request',
  new_sensors SMALLINT UNSIGNED DEFAULT 0 COMMENT 'Number of new sensors registered',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_raw (raw_upload_id),
  INDEX idx_session (session_id),
  FOREIGN KEY (session_id) REFERENCES sessions(session_id) ON DELETE CASCADE,
  FOREIGN KEY (raw_upload_id) REFERENCES upload_requests_raw(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Processed upload summary (linked to raw audit log)';

SET FOREIGN_KEY_CHECKS = 1;

-- ================================================================
-- USAGE EXAMPLES
-- ================================================================

-- Query all pressure sensors for multi-sensor chart
-- SELECT s.sensor_key, s.short_name, u.symbol
-- FROM sensors s
-- JOIN unit_types u ON s.unit_id = u.id
-- JOIN sensor_categories c ON u.category_id = c.id
-- WHERE c.category_key = 'pressure';

-- Convert all pressure values to PSI
-- SELECT 
--   sr.timestamp,
--   s.short_name,
--   sr.value * (u_target.to_si_multiplier / u_source.to_si_multiplier) AS value_psi
-- FROM sensor_readings sr
-- JOIN sensors s ON sr.sensor_key = s.sensor_key
-- JOIN unit_types u_source ON s.unit_id = u_source.id
-- JOIN unit_types u_target ON u_target.unit_key = 'psi'
-- WHERE s.category_id = 1 AND sr.session_id = '1776776031194';

-- Archive old raw uploads (export month to file, then drop partition)
-- mysqldump --where="upload_date >= '2026-01-01' AND upload_date < '2026-02-01'" \
--   spacecabbie_torque upload_requests_raw > archive_2026_01.sql
-- ALTER TABLE upload_requests_raw DROP PARTITION p_2026_01;

-- Add new partition for next month
-- ALTER TABLE upload_requests_raw ADD PARTITION (
--   PARTITION p_2027_01 VALUES LESS THAN (TO_DAYS('2027-02-01'))
-- );
