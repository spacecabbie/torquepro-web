<?php
declare(strict_types=1);

/**
 * parser.php — Parsing logic for Torque upload data.
 *
 * Contains the business logic to parse and store sensor data from Torque uploads.
 * Called by upload_data.php after saving raw data.
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/Database/Connection.php';
require_once __DIR__ . '/includes/Helpers/DataHelper.php';

use TorqueLogs\Database\Connection;
use TorqueLogs\Helpers\DataHelper;

/**
 * Normalize a metadata value passed from Torque uploads.
 *
 * @param mixed $value
 * @return string|null
 */
function normalizeTorqueMetadataValue(mixed $value): ?string
{
    if (is_array($value)) {
        foreach ($value as $candidate) {
            if (is_scalar($candidate) && (string)$candidate !== '') {
                return (string)$candidate;
            }
        }

        return null;
    }

    if (!is_scalar($value)) {
        return null;
    }

    $normalized = trim((string)$value);

    return $normalized === '' ? null : $normalized;
}

/**
 * Parse and store Torque upload data.
 *
 * @param array $params The parsed GET parameters from the upload.
 * @param string|null $sessionId The session ID.
 * @param string|null $deviceId The device ID.
 * @param int $timestamp The timestamp.
 * @param string|null $eml Email.
 * @param string|null $profileName Profile name.
 * @param int $rawUploadId The raw upload ID.
 * @param int $startTime The start time in nanoseconds.
 * @return void
 * @throws \PDOException On database errors.
 */
function parseTorqueData(array $params, ?string $sessionId, ?string $deviceId, int $timestamp, ?string $eml, ?string $profileName, int $rawUploadId, int $startTime): void
{
    $pdo = Connection::get();

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
    $sensorNames         = [];
    $sensorDescriptions  = [];
    $sensorUnits         = [];
    $sensorDefaultUnits  = [];

    foreach ($params as $rawKey => $value) {
        $metadataValue = normalizeTorqueMetadataValue($value);
        if ($metadataValue === null) {
            continue;
        }

        if (preg_match('/^userShortName(.+)$/', $rawKey, $m)) {
            $suffix = ltrim($m[1], '0');
            $sensorNames['k' . ($suffix !== '' ? $suffix : $m[1])] = $metadataValue;
        } elseif (preg_match('/^userFullName(.+)$/', $rawKey, $m)) {
            $suffix = ltrim($m[1], '0');
            $sensorDescriptions['k' . ($suffix !== '' ? $suffix : $m[1])] = $metadataValue;
        } elseif (preg_match('/^userUnit(.+)$/', $rawKey, $m)) {
            $suffix = ltrim($m[1], '0');
            $sensorUnits['k' . ($suffix !== '' ? $suffix : $m[1])] = $metadataValue;
        } elseif (preg_match('/^defaultUnit(.+)$/', $rawKey, $m)) {
            $suffix = ltrim($m[1], '0');
            $sensorDefaultUnits['k' . ($suffix !== '' ? $suffix : $m[1])] = $metadataValue;
        }
    }

    // ── GPS from trip-start notice (type B request) ────────────────────────
    // Torque sends lat=/lon= as plain top-level params (not k-prefixed) in the
    // "Trip started" notification. These are valid GPS fixes and must be stored.
    // They are overwritten below if the same request also contains kff1006/kff1005.
    $gpsLat       = null;
    $gpsLon       = null;
    $gpsAlt       = null;
    $gpsSpeed     = null;
    $gpsBearing   = null;
    $gpsAccuracy  = null;
    $gpsSatellites = null;

    $rawLat = $params['lat'] ?? '';
    $rawLon = $params['lon'] ?? '';
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

    $unitTypeStmt = $pdo->query("SELECT id, unit_key FROM unit_types");
    $unitKeyToId  = [];
    foreach ($unitTypeStmt->fetchAll(
        \PDO::FETCH_ASSOC
    ) as $row) {
        $unitKeyToId[$row['unit_key']] = (int) $row['id'];
    }

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

    $stmtReading = $pdo->prepare("
        INSERT IGNORE INTO sensor_readings (session_id, timestamp, sensor_key, value)
        VALUES (:session_id, :timestamp, :sensor_key, :value)
    ");

    $stmtInsertSensor = $pdo->prepare("
        INSERT INTO sensors (sensor_key, short_name, full_name, category_id, unit_id, is_gps)
        VALUES (:key, COALESCE(:metadata_short_name, :key), :metadata_full_name, 10, :metadata_unit_id, :is_gps)
        ON DUPLICATE KEY UPDATE
            short_name   = CASE WHEN :metadata_short_name IS NOT NULL THEN VALUES(short_name) ELSE short_name END,
            full_name    = CASE WHEN :metadata_full_name IS NOT NULL THEN VALUES(full_name) ELSE full_name END,
            unit_id      = CASE WHEN :metadata_unit_id IS NOT NULL THEN VALUES(unit_id) ELSE unit_id END,
            is_gps       = VALUES(is_gps),
            last_updated = CURRENT_TIMESTAMP
    ");

    foreach ($params as $key => $value) {
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
            case 'kff1007':
                if ($floatValue >= 0.0 && $floatValue <= 360.0) {
                    $gpsBearing = $floatValue;
                }
                break;
            case 'kff1008':
                if ($floatValue >= 0.0) {
                    $gpsAccuracy = $floatValue;
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
            case 'kff1011':
                if ($floatValue >= 0.0) {
                    $gpsSatellites = (int) $floatValue;
                }
                break;
            case 'kff1001':
                $gpsSpeed = $floatValue;
                break;
        }

        $sensorCount++;

        $sensorIsNew = false;
        $stmtCheck->execute([':key' => $key]);
        if ($stmtCheck->fetchColumn() === false) {
            $sensorIsNew = true;
        }

        $unitId = null;
        $unitValue = $sensorUnits[$key] ?? $sensorDefaultUnits[$key] ?? null;
        if ($unitValue !== null) {
            $unitKey = DataHelper::normalizeUnitKey($unitValue);
            $unitId  = $unitKeyToId[$unitKey] ?? null;
        }

        $stmtInsertSensor->execute([
            ':key'                => $key,
            ':metadata_short_name'=> $sensorNames[$key] ?? null,
            ':metadata_full_name' => $sensorDescriptions[$key] ?? null,
            ':metadata_unit_id'   => $unitId,
            ':is_gps'             => in_array($key, $gpsSensorKeys, true) ? 1 : 0,
        ]);

        if ($sensorIsNew) {
            $newSensorCount++;
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
    // sensor values in this request, not a flat +1 per per-sensor call.
    // Also fires on metadata-only requests (sensorCount=0) to keep
    // end_time and profile_name in sync.
    $stmtSessionUpdate = $pdo->prepare("
        UPDATE sessions
        SET end_time         = FROM_UNIXTIME(:ts / 1000),
            duration_seconds = GREATEST(0, TIMESTAMPDIFF(SECOND, start_time, FROM_UNIXTIME(:ts / 1000))),
            total_readings   = total_readings + :sensor_count,
            profile_name     = COALESCE(:profile_name, profile_name)
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
                (session_id, timestamp, latitude, longitude, altitude, speed_kmh, bearing, accuracy, satellites)
            VALUES (:session_id, :timestamp, :lat, :lon, :alt, :speed, :bearing, :accuracy, :satellites)
        ");
        $stmtGps->execute([
            ':session_id' => $sessionId,
            ':timestamp'  => $timestamp,
            ':lat'        => $gpsLat,
            ':lon'        => $gpsLon,
            ':alt'        => $gpsAlt,
            ':speed'      => $gpsSpeed,
            ':bearing'    => $gpsBearing,
            ':accuracy'   => $gpsAccuracy,
            ':satellites' => $gpsSatellites,
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
    $processingMs = (int)round((hrtime(true) - $startTime) / 1_000_000);
    try {
        $pdo->prepare(
            "UPDATE upload_requests_raw SET processing_time_ms = :ms WHERE id = :id"
        )->execute([':ms' => $processingMs, ':id' => $rawUploadId]);
    } catch (Throwable) {
        // Audit update failure should not break the main parser operation.
    }
}