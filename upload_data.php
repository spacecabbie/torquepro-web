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

// ── Capture raw request metadata ───────────────────────────────────────────
$rawQueryString = $_SERVER['QUERY_STRING'] ?? '';
$clientIp       = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$deviceId       = md5($_GET['id'] ?? '');
$sessionId      = $_GET['session'] ?? null;
$eml            = $_GET['eml'] ?? null;
$profileName    = $_GET['profileName'] ?? null;
// Torque sends Unix ms in 'time'. Fall back to current time in ms.
$timestamp      = isset($_GET['time']) ? (int)$_GET['time'] : (int)(microtime(true) * 1000);

// ── Insert raw audit log BEFORE the main transaction (auto-commit) ─────────
// This row must survive even if the business-logic transaction is rolled back.
$stmtRaw = $pdo->prepare("
    INSERT INTO upload_requests_raw
        (upload_date, ip, device_id, session_id, raw_query_string, result)
    VALUES (CURDATE(), :ip, :device_id, :session_id, :raw_query_string, 'ok')
");
$stmtRaw->execute([
    ':ip'               => $clientIp,
    ':device_id'        => $deviceId,
    ':session_id'       => $sessionId,
    ':raw_query_string' => $rawQueryString,
]);
$rawUploadId = (int)$pdo->lastInsertId();

try {
    $pdo->beginTransaction();

    // ── Extract sensor names from flat keys ────────────────────────────────
    // Torque sends: userShortName222408=ShortLabel, userFullName222408=Description
    // We map these to sensor_key: k222408
    $sensorNames        = [];
    $sensorDescriptions = [];

    foreach ($_GET as $rawKey => $value) {
        if (!is_string($value) || $value === '') {
            continue;
        }
        if (preg_match('/^userShortName(.+)$/', $rawKey, $m)) {
            $sensorNames['k' . ltrim($m[1], '0')] = $value;
        } elseif (preg_match('/^userFullName(.+)$/', $rawKey, $m)) {
            $sensorDescriptions['k' . ltrim($m[1], '0')] = $value;
        }
    }

    // ── UPSERT session ──────────────────────────────────────────────────────
    // Two-step approach so total_readings can be incremented by the actual
    // sensor count (only known after the loop below).
    //
    // Step 1 — INSERT IGNORE: creates the row on the first upload for this
    //           session; subsequent uploads leave the existing row untouched.
    // Step 2 — UPDATE (after loop): advances end_time and adds the real
    //           reading count rather than a flat +1 per HTTP request.
    if ($sessionId !== null) {
        $stmtSessionInsert = $pdo->prepare("
            INSERT IGNORE INTO sessions
                (session_id, device_id, start_time, email, profile_name)
            VALUES
                (:session_id, :device_id, FROM_UNIXTIME(:ts / 1000), :email, :profile_name)
        ");
        $stmtSessionInsert->execute([
            ':session_id'  => $sessionId,
            ':device_id'   => $deviceId,
            ':ts'          => $timestamp,
            ':email'       => $eml,
            ':profile_name' => $profileName,
        ]);
    }

    // ── UPSERT sensors + Insert sensor readings ────────────────────────────
    $sensorCount    = 0;
    $newSensorCount = 0;
    $gpsLat         = null;
    $gpsLon         = null;
    $gpsAlt         = null;
    $gpsSpeed       = null;

    // GPS sensor keys as defined in the Torque Pro PID list.
    // These are flagged is_gps=1 when auto-registered in the sensors table.
    $gpsSensorKeys = [
        'kff1001', // GPS Speed (km/h)
        'kff1005', // GPS Longitude
        'kff1006', // GPS Latitude
        'kff1007', // GPS Bearing
        'kff1008', // GPS Accuracy (m)
        'kff1009', // GPS Altitude (some Torque versions)
        'kff1010', // GPS Altitude (m)
        'kff1011', // GPS Satellites
    ];

    // Prepare reusable statements once, outside the per-sensor loop.
    $stmtCheck   = $pdo->prepare(
        "SELECT sensor_key FROM sensors WHERE sensor_key = :key"
    );
    $stmtReading = $pdo->prepare("
        INSERT INTO sensor_readings (session_id, timestamp, sensor_key, value)
        VALUES (:session_id, :timestamp, :sensor_key, :value)
    ");
    // Sensor INSERT — prepared once; :is_gps bound per-execution.
    $stmtInsertSensor = $pdo->prepare("
        INSERT INTO sensors (sensor_key, short_name, full_name, category_id, unit_id, is_gps)
        VALUES (:key, :short_name, :full_name, 10, NULL, :is_gps)
    ");
    // Sensor name UPDATE — :name_new and :name_check are the same value but
    // must be distinct placeholders (PDO native mode forbids reusing a named
    // placeholder within one statement).
    $stmtUpdateSensor = $pdo->prepare("
        UPDATE sensors
        SET short_name = :name_new, last_updated = CURRENT_TIMESTAMP
        WHERE sensor_key = :key AND short_name != :name_check
    ");

    foreach ($_GET as $key => $value) {
        // Only process k* sensor keys (alphanumeric suffix: kd, kff1006, k222408, …)
        if (!preg_match('/^k[0-9A-Za-z]+$/', $key)) {
            continue;
        }

        // Skip non-numeric values to prevent silent 0.0 corruption in DECIMAL column.
        if (!is_numeric($value)) {
            continue;
        }

        $floatValue = (float)$value;

        // Capture GPS-specific keys for the dedicated gps_points columns.
        // kff1005 = Longitude, kff1006 = Latitude, kff1010 = Altitude (m),
        // kff1001 = GPS Speed (km/h)
        switch ($key) {
            case 'kff1005': $gpsLon   = $floatValue; break;
            case 'kff1006': $gpsLat   = $floatValue; break;
            case 'kff1010': $gpsAlt   = $floatValue; break;
            case 'kff1001': $gpsSpeed = $floatValue; break;
        }

        $sensorCount++;

        // Ensure sensor exists in the master registry.
        $stmtCheck->execute([':key' => $key]);
        $sensorKeyExists = $stmtCheck->fetchColumn();

        if ($sensorKeyExists === false) {
            $stmtInsertSensor->execute([
                ':key'        => $key,
                ':short_name' => $sensorNames[$key] ?? $key,
                ':full_name'  => $sensorDescriptions[$key] ?? null,
                ':is_gps'     => in_array($key, $gpsSensorKeys, true) ? 1 : 0,
            ]);
            $newSensorCount++;
        } elseif (isset($sensorNames[$key])) {
            // Update the short name if Torque supplied one and it has changed.
            $stmtUpdateSensor->execute([
                ':key'         => $key,
                ':name_new'    => $sensorNames[$key],
                ':name_check'  => $sensorNames[$key],
            ]);
        }

        // Insert time-series reading (timestamp stored as BIGINT Unix ms).
        if ($sessionId !== null) {
            $stmtReading->execute([
                ':session_id' => $sessionId,
                ':timestamp'  => $timestamp,
                ':sensor_key' => $key,
                ':value'      => $floatValue,
            ]);
        }
    }

    // ── Step 2: Update session end_time and true reading count ────────────
    // Runs after the loop so total_readings reflects the actual number of
    // sensor values in this request, not a flat +1 per HTTP call.
    if ($sessionId !== null && $sensorCount > 0) {
        $stmtSessionUpdate = $pdo->prepare("
            UPDATE sessions
            SET end_time       = FROM_UNIXTIME(:ts / 1000),
                total_readings = total_readings + :sensor_count,
                profile_name   = COALESCE(:profile_name, profile_name)
            WHERE session_id = :session_id
        ");
        $stmtSessionUpdate->execute([
            ':ts'           => $timestamp,
            ':sensor_count' => $sensorCount,
            ':profile_name' => $profileName,
            ':session_id'   => $sessionId,
        ]);
    }

    // ── Insert GPS point if coordinates are present ────────────────────────
    // A (0.0, 0.0) fix means no valid GPS lock; skip it.
    if ($gpsLat !== null && $gpsLon !== null && $sessionId !== null
        && ($gpsLat != 0.0 || $gpsLon != 0.0)
    ) {
        $stmtGps = $pdo->prepare("
            INSERT INTO gps_points
                (session_id, timestamp, latitude, longitude, altitude, speed_kmh)
            VALUES (:session_id, :timestamp, :lat, :lon, :alt, :speed)
        ");
        $stmtGps->execute([
            ':session_id' => $sessionId,
            ':timestamp'  => $timestamp,
            ':lat'        => $gpsLat,
            ':lon'        => $gpsLon,
            ':alt'        => $gpsAlt,
            ':speed'      => $gpsSpeed,
        ]);
    }

    // ── Insert processed upload summary (only when a session is present) ───
    // upload_requests_processed.session_id has NOT NULL + FK to sessions;
    // omit the row entirely when no session was supplied.
    if ($sessionId !== null) {
        $stmtProcessed = $pdo->prepare("
            INSERT INTO upload_requests_processed
                (raw_upload_id, session_id, data_timestamp, sensor_count, new_sensors)
            VALUES (:raw_upload_id, :session_id, :data_timestamp, :sensor_count, :new_sensors)
        ");
        $stmtProcessed->execute([
            ':raw_upload_id'  => $rawUploadId,
            ':session_id'     => $sessionId,
            ':data_timestamp' => $timestamp,
            ':sensor_count'   => $sensorCount,
            ':new_sensors'    => $newSensorCount,
        ]);
    }

    $pdo->commit();

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    // Update the pre-committed raw audit row with the error detail.
    // The row was inserted before beginTransaction(), so it survived the rollback.
    try {
        $stmtError = $pdo->prepare("
            UPDATE upload_requests_raw
            SET result = 'error', error_msg = :error
            WHERE id = :id
        ");
        $stmtError->execute([
            ':id'    => $rawUploadId,
            ':error' => $e->getMessage(),
        ]);
    } catch (Throwable $logError) {
        error_log('Torque upload error logging failed: ' . $logError->getMessage());
    }

    error_log('Torque upload error: ' . $e->getMessage());
}

// Return the response required by Torque (always return OK to prevent endless retries)
echo 'OK!';
