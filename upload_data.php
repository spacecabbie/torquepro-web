<?php
declare(strict_types=1);

/**
 * upload_data.php — Torque Pro upload endpoint (normalized schema).
 *
 * Receives sensor data from the Torque Pro Android app via HTTP GET,
 * validates and persists it to the normalized schema.
 *
 * Schema overview:
 * - sessions               One row per drive session
 * - sensors                Master registry of all k* sensor keys
 * - sensor_readings        Narrow time-series (session_id, timestamp, sensor_key, value)
 * - gps_points             GPS track points per session
 * - upload_requests_raw    Raw audit log (partitioned by month, auto-commit)
 * - upload_requests_processed  Summary of processed uploads
 *
 * Torque request types observed in the wild:
 *   A) Metadata-only     — contains only userShortName/userFullName params, no k-values.
 *                          Sent at session start; used to register sensor labels.
 *   B) Trip-start notice — contains lat=, lon= (top-level, not k-prefixed) and notice=.
 *                          Sent when the driver starts a trip; lat/lon is the first GPS fix.
 *   C) Sensor data       — contains k* sensor readings (kd, kff1006, k222408, …).
 *                          The main time-series payload; sent every ~1 second.
 *   D) Mixed             — metadata + sensor data in a single request.
 *
 * Auth:   Torque-ID based (Auth::checkApp).
 * Audit:  All uploads logged to upload_requests_raw before business-logic transaction.
 * Safety: All queries use PDO prepared statements; column names are never interpolated.
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
$startTime      = hrtime(true);                            // nanoseconds, for processing_time_ms
$rawQueryString = $_SERVER['QUERY_STRING'] ?? '';
$clientIp       = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

// Device ID: MD5 of the raw device identifier sent by Torque.
// Guard against empty 'id' to avoid storing md5('') as a real device.
$rawDeviceId = $_GET['id'] ?? '';
$deviceId    = ($rawDeviceId !== '') ? md5($rawDeviceId) : null;

// Session ID: Torque sends a Unix-ms timestamp string. Accept only digit strings
// up to 20 characters; reject anything that looks structurally wrong.
$rawSession = $_GET['session'] ?? '';
$sessionId  = (preg_match('/^\d{1,20}$/', $rawSession)) ? $rawSession : null;

// Normalize optional string fields — treat empty string same as absent.
$eml         = (($_GET['eml']         ?? '') !== '') ? $_GET['eml']         : null;
$profileName = (($_GET['profileName'] ?? '') !== '') ? $_GET['profileName'] : null;

// Torque sends Unix-ms in 'time'. Fall back to current server time in ms.
// Guard against a missing or clearly invalid (zero / negative) value.
$rawTime   = $_GET['time'] ?? '';
$timestamp = (is_numeric($rawTime) && (int)$rawTime > 0)
    ? (int)$rawTime
    : (int)(microtime(true) * 1000);

// ── Insert raw audit log BEFORE the main transaction (auto-commit) ─────────
// This row must survive even if the business-logic transaction is rolled back.
// Wrap in its own try/catch so a partition-miss or schema mismatch does not
// kill the entire request without a response to Torque.
$rawUploadId = 0;
try {
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
} catch (Throwable $auditError) {
    error_log('Torque upload: raw audit INSERT failed — ' . $auditError->getMessage());
    // Continue; losing the audit row is better than retrying indefinitely.
}

// ── Early-exit if no session ID ────────────────────────────────────────────
// Nothing useful can be stored without a session. Mark the raw row 'skipped'
// (if it was created) and return OK so Torque does not retry endlessly.
if ($sessionId === null) {
    if ($rawUploadId > 0) {
        try {
            $pdo->prepare("UPDATE upload_requests_raw SET result = 'skipped' WHERE id = :id")
                ->execute([':id' => $rawUploadId]);
        } catch (Throwable) { /* best-effort */ }
    }
    echo 'OK!';
    exit;
}

try {
    $pdo->beginTransaction();

    // ── Extract sensor labels from metadata params ─────────────────────────
    // Torque sends:
    //   userShortName<suffix> = short human label  (e.g. userShortName0d = "Speed")
    //   userFullName<suffix>  = full description   (e.g. userFullName0d  = "Speed (OBD)")
    //
    // The suffix is the raw PID in hex, possibly zero-padded (e.g. "0d", "0c").
    // The actual k-key in sensor readings strips that leading zero (e.g. "kd", "kc").
    // Apply ltrim to align the two. Guard against an all-zero suffix (e.g. "000")
    // which would produce an empty string after ltrim — keep the original in that case.
    $sensorNames        = [];
    $sensorDescriptions = [];

    foreach ($_GET as $rawKey => $value) {
        if (!is_string($value) || $value === '') {
            continue;
        }
        if (preg_match('/^userShortName(.+)$/', $rawKey, $m)) {
            $suffix = ltrim($m[1], '0');
            $sensorNames['k' . ($suffix !== '' ? $suffix : $m[1])] = $value;
        } elseif (preg_match('/^userFullName(.+)$/', $rawKey, $m)) {
            $suffix = ltrim($m[1], '0');
            $sensorDescriptions['k' . ($suffix !== '' ? $suffix : $m[1])] = $value;
        }
    }

    // ── GPS from trip-start notice (type B request) ────────────────────────
    // Torque sends lat=/lon= as plain top-level params (not k-prefixed) in the
    // "Trip started" notification. These are valid GPS fixes and must be stored.
    // They are overwritten below if the same request also contains kff1006/kff1005.
    $gpsLat   = null;
    $gpsLon   = null;
    $gpsAlt   = null;
    $gpsSpeed = null;

    $rawLat = $_GET['lat'] ?? '';
    $rawLon = $_GET['lon'] ?? '';
    if (is_numeric($rawLat) && is_numeric($rawLon)) {
        $latCandidate = (float)$rawLat;
        $lonCandidate = (float)$rawLon;
        // Validate geographic range before accepting.
        if ($latCandidate >= -90.0  && $latCandidate <= 90.0
            && $lonCandidate >= -180.0 && $lonCandidate <= 180.0
            && ($latCandidate != 0.0 || $lonCandidate != 0.0)
        ) {
            $gpsLat = $latCandidate;
            $gpsLon = $lonCandidate;
        }
    }

    // ── UPSERT session (step 1 of 2) ───────────────────────────────────────
    // INSERT IGNORE creates the row on first upload; subsequent uploads are
    // no-ops here — the real update (end_time, total_readings) happens after
    // the sensor loop once we know the actual reading count.
    $stmtSessionInsert = $pdo->prepare("
        INSERT IGNORE INTO sessions
            (session_id, device_id, start_time, email, profile_name)
        VALUES
            (:session_id, :device_id, FROM_UNIXTIME(:ts / 1000), :email, :profile_name)
    ");
    $stmtSessionInsert->execute([
        ':session_id'   => $sessionId,
        ':device_id'    => $deviceId,
        ':ts'           => $timestamp,
        ':email'        => $eml,
        ':profile_name' => $profileName,
    ]);

    // ── UPSERT sensors + insert sensor readings ────────────────────────────
    $sensorCount    = 0;
    $newSensorCount = 0;

    // GPS sensor keys (Torque Pro app built-in namespace — kff* prefix).
    //
    // IMPORTANT: These are NOT car-specific OBD PIDs. The kff* prefix is Torque
    // Pro's own internal namespace for app-calculated and device-sensor values.
    // kff1006 is ALWAYS GPS Latitude, kff1005 is ALWAYS GPS Longitude, etc.,
    // regardless of what car or OBD profile is in use. Torque never sends
    // userShortName for these because they are fixed app constants.
    //
    // By contrast, k222408 / kd / kf etc. (no ff) ARE car/profile-specific OBD
    // PIDs whose names come from userShortName*/userFullName* in each upload.
    $gpsSensorKeys = [
        'kff1001', // GPS Speed (km/h)
        'kff1005', // GPS Longitude
        'kff1006', // GPS Latitude
        'kff1007', // GPS Bearing
        'kff1008', // GPS Accuracy (m)
        'kff1009', // GPS Altitude — older Torque versions
        'kff1010', // GPS Altitude (m) — current Torque versions
        'kff1011', // GPS Satellites in view
    ];

    // Prepare reusable statements once, outside the per-sensor loop.
    $stmtCheck = $pdo->prepare(
        "SELECT sensor_key FROM sensors WHERE sensor_key = :key"
    );

    // INSERT IGNORE so replayed / retried requests are idempotent.
    $stmtReading = $pdo->prepare("
        INSERT IGNORE INTO sensor_readings (session_id, timestamp, sensor_key, value)
        VALUES (:session_id, :timestamp, :sensor_key, :value)
    ");

    $stmtInsertSensor = $pdo->prepare("
        INSERT INTO sensors (sensor_key, short_name, full_name, category_id, unit_id, is_gps)
        VALUES (:key, :short_name, :full_name, 10, NULL, :is_gps)
    ");

    // :name_new and :name_check carry the same value but must be distinct
    // named placeholders — PDO native-mode rejects reuse within one statement.
    $stmtUpdateSensor = $pdo->prepare("
        UPDATE sensors
        SET short_name = :name_new, last_updated = CURRENT_TIMESTAMP
        WHERE sensor_key = :key AND short_name != :name_check
    ");

    foreach ($_GET as $key => $value) {
        // Only process k-prefixed sensor keys with an alphanumeric suffix.
        if (!preg_match('/^k[0-9A-Za-z]+$/', $key)) {
            continue;
        }

        // Skip non-numeric values — prevents silent 0.0 corruption in the
        // DECIMAL column and ignores any erroneous string payloads.
        if (!is_numeric($value)) {
            continue;
        }

        $floatValue = (float)$value;

        // Extract GPS component values into dedicated gps_points typed columns.
        // These keys are Torque Pro app constants (kff* namespace) — they are
        // identical on every car/device. They are NOT OBD PIDs from the ECU.
        // The gps_points table provides purpose-built typed columns for these
        // values, so we must identify them by their fixed Torque-defined keys.
        // kff1006 = Latitude, kff1005 = Longitude, kff1001 = GPS Speed (km/h)
        // kff1009/kff1010 = Altitude (version-dependent, kff1010 preferred)
        // These override any lat=/lon= values from a trip-start notice.
        switch ($key) {
            case 'kff1006':
                // Latitude: valid range -90..90
                if ($floatValue >= -90.0 && $floatValue <= 90.0) {
                    $gpsLat = $floatValue;
                }
                break;
            case 'kff1005':
                // Longitude: valid range -180..180
                if ($floatValue >= -180.0 && $floatValue <= 180.0) {
                    $gpsLon = $floatValue;
                }
                break;
            case 'kff1009':
                // Altitude fallback (older Torque versions); only use if kff1010
                // has not already set a value.
                if ($gpsAlt === null) {
                    $gpsAlt = $floatValue;
                }
                break;
            case 'kff1010':
                // Altitude preferred (current Torque versions); overrides kff1009.
                $gpsAlt = $floatValue;
                break;
            case 'kff1001':
                $gpsSpeed = $floatValue;
                break;
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
                ':key'        => $key,
                ':name_new'   => $sensorNames[$key],
                ':name_check' => $sensorNames[$key],
            ]);
        }

        $stmtReading->execute([
            ':session_id' => $sessionId,
            ':timestamp'  => $timestamp,
            ':sensor_key' => $key,
            ':value'      => $floatValue,
        ]);
    }

    // ── Step 2: update session end_time and true reading count ─────────────
    // Runs after the loop so total_readings reflects the actual number of
    // sensor values in this request, not a flat +1 per HTTP call.
    // Also fires on metadata-only requests (sensorCount=0) to keep
    // end_time and profile_name in sync.
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

    // ── Insert GPS point if valid coordinates are available ────────────────
    // Sources (in decreasing priority):
    //   1. kff1006 (lat) / kff1005 (lon) — type C sensor readings
    //   2. lat= / lon= — type B trip-start notice
    // A (0.0, 0.0) coordinate means no GPS lock; skip it.
    if ($gpsLat !== null && $gpsLon !== null
        && ($gpsLat != 0.0 || $gpsLon != 0.0)
    ) {
        $stmtGps = $pdo->prepare("
            INSERT IGNORE INTO gps_points
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

    // ── Insert processed upload summary ────────────────────────────────────
    $stmtProcessed = $pdo->prepare("
        INSERT INTO upload_requests_processed
            (raw_upload_id, session_id, data_timestamp, sensor_count, new_sensors)
        VALUES (:raw_upload_id, :session_id, :data_timestamp, :sensor_count, :new_sensors)
        ON DUPLICATE KEY UPDATE
            sensor_count = VALUES(sensor_count),
            new_sensors  = VALUES(new_sensors)
    ");
    $stmtProcessed->execute([
        ':raw_upload_id'  => $rawUploadId,
        ':session_id'     => $sessionId,
        ':data_timestamp' => $timestamp,
        ':sensor_count'   => $sensorCount,
        ':new_sensors'    => $newSensorCount,
    ]);

    $pdo->commit();

    // ── Update processing_time_ms on the raw audit row ─────────────────────
    if ($rawUploadId > 0) {
        $processingMs = (int)round((hrtime(true) - $startTime) / 1_000_000);
        try {
            $pdo->prepare(
                "UPDATE upload_requests_raw SET processing_time_ms = :ms WHERE id = :id"
            )->execute([':ms' => $processingMs, ':id' => $rawUploadId]);
        } catch (Throwable) { /* non-critical; ignore */ }
    }

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    // Update the pre-committed raw audit row with the error detail.
    // The row was inserted before beginTransaction() so it survived the rollback.
    if ($rawUploadId > 0) {
        try {
            $pdo->prepare("
                UPDATE upload_requests_raw
                SET result = 'error', error_msg = :error
                WHERE id = :id
            ")->execute([
                ':id'    => $rawUploadId,
                ':error' => $e->getMessage(),
            ]);
        } catch (Throwable $logError) {
            error_log('Torque upload error logging failed: ' . $logError->getMessage());
        }
    }

    error_log('Torque upload error: ' . $e->getMessage());
}

// Return the response required by Torque (always return OK to prevent endless retries)
echo 'OK!';
