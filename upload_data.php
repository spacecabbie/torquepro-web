<?php
declare(strict_types=1);

/**
 * upload_data.php — Torque Pro upload endpoint (normalized schema).
 *
 * Receives sensor data from the Torque Pro Android app via HTTP GET,
 * validates and persists it to the normalized schema (sessions, sensors, sensor_readings, gps_points).
 * 
 * New schema design:
 * - sensors: Master registry of all k* sensors with metadata
 * - sessions: One row per session_id
 * - sensor_readings: Narrow time-series table (session_id, timestamp, sensor_key, value)
 * - gps_points: GPS latitude/longitude track points
 * - upload_requests_raw: Raw audit log with partitioning for archival
 * - upload_requests_processed: Summary of processed uploads
 *
 * Auth: Torque-ID based (Auth::checkApp).
 * Audit: All uploads logged to upload_requests_raw + upload_requests_processed.
 * SQL safety: Prepared statements for all queries.
 *
 * Origin: upload_data.php (rewritten for normalized schema)
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/Auth/Auth.php';
require_once __DIR__ . '/includes/Database/Connection.php';

use TorqueLogs\Auth\Auth;
use TorqueLogs\Database\Connection;

// ── Auth guard (Torque-ID) ─────────────────────────────────────────────────
Auth::checkApp();

$pdo = Connection::get();
$pdo->beginTransaction();

try {
    // ── Capture raw request metadata ───────────────────────────────────────
    $rawQueryString = $_SERVER['QUERY_STRING'] ?? '';
    $clientIp       = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $deviceId       = md5($_GET['id'] ?? '');
    $sessionId      = $_GET['session'] ?? null;
    $eml            = $_GET['eml'] ?? null;
    $profileName    = $_GET['profileName'] ?? null;
    // Ensure we always have a timestamp (Torque sends Unix ms). Fallback to now in ms.
    $timestamp      = isset($_GET['time']) ? (int)$_GET['time'] : (int) (microtime(true) * 1000);
    $uploadDate     = date('Y-m-d');
    
    // ── Insert raw audit log (partitioned for archival) ────────────────────
    $stmtRaw = $pdo->prepare("
        INSERT INTO upload_requests_raw 
        (upload_date, ip, device_id, session_id, raw_query_string, result)
        VALUES (CURDATE(), :ip, :device_id, :session_id, :raw_query_string, 'ok')
    ");
    $stmtRaw->execute([
        ':ip'               => $clientIp,
        ':device_id'        => $deviceId,
        ':session_id'       => $sessionId,
        ':raw_query_string' => $rawQueryString
    ]);
    $rawUploadId = (int)$pdo->lastInsertId();
    
    // ── Extract sensor names from flat keys ────────────────────────────────
    // Torque sends: userShortName222408=Name, userFullName222408=Description
    // We map these to sensor_key: k222408
    $sensorNames = [];
    $sensorDescriptions = [];
    
    foreach ($_GET as $rawKey => $value) {
        if (!is_string($value) || $value === '') {
            continue;
        }
        if (preg_match('/^userShortName(.+)$/', $rawKey, $m)) {
            $sensorKey = 'k' . $m[1];
            $sensorNames[$sensorKey] = $value;
        } elseif (preg_match('/^userFullName(.+)$/', $rawKey, $m)) {
            $sensorKey = 'k' . $m[1];
            $sensorDescriptions[$sensorKey] = $value;
        }
    }
    
    // ── UPSERT session ──────────────────────────────────────────────────────
    // Upsert session even if email is not provided; profileName captured earlier.
    if ($sessionId !== null) {
        $stmtSession = $pdo->prepare("
            INSERT INTO sessions (session_id, device_id, start_time, end_time, email, profile_name)
            VALUES (:session_id, :device_id, FROM_UNIXTIME(:ts / 1000), FROM_UNIXTIME(:ts / 1000), :email, :profile_name)
            ON DUPLICATE KEY UPDATE
                end_time = FROM_UNIXTIME(:ts / 1000),
                total_readings = total_readings + 1,
                profile_name = COALESCE(:profile_name, profile_name)
        ");
        $stmtSession->execute([
            ':session_id'   => $sessionId,
            ':device_id'    => $deviceId,
            ':ts'           => $timestamp,
            ':email'        => $eml,
            ':profile_name' => $profileName,
        ]);
    }
    
    // ── UPSERT sensors + Insert sensor readings ────────────────────────────
    $sensorCount = 0;
    $newSensorCount = 0;
    $gpsLat = null;
    $gpsLon = null;
    
    foreach ($_GET as $key => $value) {
        // Only process k* sensor keys (alphanumeric allowed: kd, kf, k222408, etc.)
        if (!preg_match('/^k[0-9A-Za-z]+$/', $key)) {
            continue;
        }
        
        // Special handling for GPS coordinates (NOTE: Torque sends lon as kff1005, lat as kff1006)
        if ($key === 'kff1005') {
            $gpsLon = (float)$value;  // kff1005 = GPS Longitude
        } elseif ($key === 'kff1006') {
            $gpsLat = (float)$value;  // kff1006 = GPS Latitude
        }
        
        $sensorCount++;
        
    // Check if sensor exists (schema uses sensor_key as PRIMARY KEY)
    $stmtCheck = $pdo->prepare("SELECT sensor_key FROM sensors WHERE sensor_key = :key");
    $stmtCheck->execute([':key' => $key]);
    $sensorKeyExists = $stmtCheck->fetchColumn();
        
        // If sensor doesn't exist, create it
        if ($sensorKeyExists === false) {
            $shortName = $sensorNames[$key] ?? $key;
            $fullName = $sensorDescriptions[$key] ?? null;
            
            $stmtInsertSensor = $pdo->prepare("
                INSERT INTO sensors (sensor_key, short_name, full_name, category_id, unit_id)
                VALUES (:key, :short_name, :full_name, 10, 1)
            ");
            $stmtInsertSensor->execute([
                ':key'        => $key,
                ':short_name' => $shortName,
                ':full_name'  => $fullName
            ]);
            $sensorId = $key;
            $newSensorCount++;
        } else {
            // Update sensor name if provided and different
            if (isset($sensorNames[$key])) {
                $stmtUpdate = $pdo->prepare("
                    UPDATE sensors 
                    SET short_name = :name, last_updated = CURRENT_TIMESTAMP
                    WHERE sensor_key = :key AND short_name != :name
                ");
                $stmtUpdate->execute([
                    ':key'  => $key,
                    ':name' => $sensorNames[$key]
                ]);
            }
        }
        
        // Insert sensor reading (timestamp is BIGINT in Unix milliseconds)
        if ($sessionId !== null) {
            $stmtReading = $pdo->prepare("
                INSERT INTO sensor_readings (session_id, timestamp, sensor_key, value)
                VALUES (:session_id, :timestamp, :sensor_key, :value)
            ");
            $stmtReading->execute([
                ':session_id' => $sessionId,
                ':timestamp'  => $timestamp,
                ':sensor_key' => $key,
                ':value'      => (float)$value  // Schema uses DECIMAL(12,4)
            ]);
        }
    }
    
    // ── Insert GPS point if coordinates are present ────────────────────────
    if ($gpsLat !== null && $gpsLon !== null && $sessionId !== null && $timestamp !== null) {
        // Skip invalid GPS coordinates (0,0 means no fix)
        if ($gpsLat != 0.0 && $gpsLon != 0.0) {
            $stmtGps = $pdo->prepare("
                INSERT INTO gps_points (session_id, timestamp, latitude, longitude)
                VALUES (:session_id, :timestamp, :lat, :lon)
            ");
            $stmtGps->execute([
                ':session_id' => $sessionId,
                ':timestamp'  => $timestamp,
                ':lat'        => $gpsLat,
                ':lon'        => $gpsLon
            ]);
        }
    }
    
    // ── Insert processed upload summary ─────────────────────────────────────
    $stmtProcessed = $pdo->prepare("
        INSERT INTO upload_requests_processed 
        (raw_upload_id, session_id, data_timestamp, sensor_count, new_sensors)
        VALUES (:raw_upload_id, :session_id, :data_timestamp, :sensor_count, :new_sensors)
    ");
    $stmtProcessed->execute([
        ':raw_upload_id'   => $rawUploadId,
        ':session_id'      => $sessionId ?? '',
        ':data_timestamp'  => $timestamp,
        ':sensor_count'    => $sensorCount,
        ':new_sensors'     => $newSensorCount
    ]);
    
    $pdo->commit();
    
} catch (Throwable $e) {
    $pdo->rollBack();
    
    // Log error to raw audit log
    try {
        $stmtError = $pdo->prepare("
            UPDATE upload_requests_raw 
            SET result = 'error', error_msg = :error 
            WHERE id = :id
        ");
        $stmtError->execute([
            ':id'    => $rawUploadId ?? 0,
            ':error' => $e->getMessage()
        ]);
    } catch (Throwable $logError) {
        // If logging fails, write to PHP error log
        error_log("Torque upload error logging failed: " . $logError->getMessage());
    }
    
    // Log to PHP error log for debugging
    error_log("Torque upload error: " . $e->getMessage());
}

// Return the response required by Torque (always return OK to prevent endless retries)
echo 'OK!';
