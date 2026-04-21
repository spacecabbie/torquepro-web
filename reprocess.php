<?php
declare(strict_types=1);

/**
 * reprocess.php — CLI-only script to replay failed upload_requests_raw rows.
 *
 * Parses each failed row's raw_query_string and re-runs the full business logic
 * (sessions, sensors, sensor_readings, gps_points, upload_requests_processed).
 *
 * Safe to run multiple times: INSERT IGNORE / ON DUPLICATE KEY guards prevent
 * duplicate data. Already-processed rows (in upload_requests_processed) are
 * skipped automatically.
 *
 * Usage:
 *   php reprocess.php           — reprocess all rows with result='error'
 *   php reprocess.php --dry-run — parse + report only, no writes
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/Database/Connection.php';

use TorqueLogs\Database\Connection;

$dryRun = in_array('--dry-run', $argv ?? [], true);

$pdo = Connection::get();

// ── Fetch all failed raw rows ──────────────────────────────────────────────
$stmtFetch = $pdo->prepare(
    "SELECT r.id, r.session_id, r.raw_query_string, r.ip
     FROM upload_requests_raw r
     WHERE r.result = 'error'
       AND NOT EXISTS (
           SELECT 1 FROM upload_requests_processed p WHERE p.raw_upload_id = r.id
       )
     ORDER BY r.id ASC"
);
$stmtFetch->execute();
$rawRows = $stmtFetch->fetchAll();

$total   = count($rawRows);
$success = 0;
$skipped = 0;
$errors  = 0;

echo "Found {$total} unprocessed error rows." . PHP_EOL;
if ($dryRun) {
    echo "[DRY RUN — no writes]" . PHP_EOL;
}
echo PHP_EOL;

// GPS sensor keys
$gpsSensorKeys = [
    'kff1001', 'kff1005', 'kff1006', 'kff1007',
    'kff1008', 'kff1009', 'kff1010', 'kff1011',
];

// Prepare reusable statements (outside per-row loop)
$stmtSessionInsert = $pdo->prepare("
    INSERT IGNORE INTO sessions
        (session_id, device_id, start_time, email, profile_name)
    VALUES
        (:session_id, :device_id, FROM_UNIXTIME(:ts / 1000), :email, :profile_name)
");

$stmtSessionUpdate = $pdo->prepare("
    UPDATE sessions
    SET end_time       = FROM_UNIXTIME(:ts / 1000),
        total_readings = total_readings + :sensor_count,
        profile_name   = COALESCE(:profile_name, profile_name)
    WHERE session_id = :session_id
");

$stmtCheck = $pdo->prepare(
    "SELECT sensor_key FROM sensors WHERE sensor_key = :key"
);

$stmtInsertSensor = $pdo->prepare("
    INSERT INTO sensors (sensor_key, short_name, full_name, category_id, unit_id, is_gps)
    VALUES (:key, :short_name, :full_name, 10, NULL, :is_gps)
");

$stmtUpdateSensor = $pdo->prepare("
    UPDATE sensors
    SET short_name = :name_new, last_updated = CURRENT_TIMESTAMP
    WHERE sensor_key = :key AND short_name != :name_check
");

$stmtReading = $pdo->prepare("
    INSERT IGNORE INTO sensor_readings (session_id, timestamp, sensor_key, value)
    VALUES (:session_id, :timestamp, :sensor_key, :value)
");

$stmtGps = $pdo->prepare("
    INSERT IGNORE INTO gps_points
        (session_id, timestamp, latitude, longitude, altitude, speed_kmh)
    VALUES (:session_id, :timestamp, :lat, :lon, :alt, :speed)
");

$stmtProcessed = $pdo->prepare("
    INSERT INTO upload_requests_processed
        (raw_upload_id, session_id, data_timestamp, sensor_count, new_sensors)
    VALUES (:raw_upload_id, :session_id, :data_timestamp, :sensor_count, :new_sensors)
    ON DUPLICATE KEY UPDATE
        sensor_count = VALUES(sensor_count),
        new_sensors  = VALUES(new_sensors)
");

$stmtMarkOk = $pdo->prepare("
    UPDATE upload_requests_raw
    SET result = 'ok', error_msg = NULL
    WHERE id = :id
");

// ── Process each row ───────────────────────────────────────────────────────
foreach ($rawRows as $raw) {
    $rawId     = (int) $raw['id'];
    $sessionId = $raw['session_id'];

    if ($sessionId === null) {
        echo "  [{$rawId}] SKIP — no session_id" . PHP_EOL;
        $skipped++;
        continue;
    }

    // Parse the stored query string back into an associative array
    parse_str($raw['raw_query_string'], $params);

    $deviceId    = ($params['id'] ?? '') !== '' ? md5($params['id']) : null;
    $eml         = (($params['eml']         ?? '') !== '') ? $params['eml']         : null;
    $profileName = (($params['profileName'] ?? '') !== '') ? $params['profileName'] : null;

    $rawTime   = $params['time'] ?? '';
    $timestamp = (is_numeric($rawTime) && (int)$rawTime > 0) ? (int)$rawTime : 0;

    if ($timestamp === 0) {
        echo "  [{$rawId}] SKIP — no valid timestamp in query string" . PHP_EOL;
        $skipped++;
        continue;
    }

    // Extract sensor names with leading-zero-strip normalisation
    $sensorNames        = [];
    $sensorDescriptions = [];
    foreach ($params as $pk => $pv) {
        if (!is_string($pv) || $pv === '') {
            continue;
        }
        if (preg_match('/^userShortName(.+)$/', $pk, $m)) {
            $sfx = ltrim($m[1], '0');
            $sensorNames['k' . ($sfx !== '' ? $sfx : $m[1])] = $pv;
        } elseif (preg_match('/^userFullName(.+)$/', $pk, $m)) {
            $sfx = ltrim($m[1], '0');
            $sensorDescriptions['k' . ($sfx !== '' ? $sfx : $m[1])] = $pv;
        }
    }

    // GPS from trip-start notice (lat=/lon= top-level params)
    $noticeGpsLat = null;
    $noticeGpsLon = null;
    $rawLat = $params['lat'] ?? '';
    $rawLon = $params['lon'] ?? '';
    if (is_numeric($rawLat) && is_numeric($rawLon)) {
        $latC = (float)$rawLat;
        $lonC = (float)$rawLon;
        if ($latC >= -90.0 && $latC <= 90.0 && $lonC >= -180.0 && $lonC <= 180.0
            && ($latC != 0.0 || $lonC != 0.0)
        ) {
            $noticeGpsLat = $latC;
            $noticeGpsLon = $lonC;
        }
    }

    if ($dryRun) {
        $sensorCount = 0;
        foreach ($params as $key => $value) {
            if (preg_match('/^k[0-9A-Za-z]+$/', $key) && is_numeric($value)) {
                $sensorCount++;
            }
        }
        echo "  [{$rawId}] DRY session={$sessionId} ts={$timestamp} sensors~{$sensorCount}" . PHP_EOL;
        $success++;
        continue;
    }

    try {
        $pdo->beginTransaction();

        // UPSERT session
        $stmtSessionInsert->execute([
            ':session_id'   => $sessionId,
            ':device_id'    => $deviceId,
            ':ts'           => $timestamp,
            ':email'        => $eml,
            ':profile_name' => $profileName,
        ]);

        $sensorCount    = 0;
        $newSensorCount = 0;
        // Seed GPS from trip-start notice params; overridden by k-sensor values below.
        $gpsLat   = $noticeGpsLat;
        $gpsLon   = $noticeGpsLon;
        $gpsAlt   = null;
        $gpsSpeed = null;

        foreach ($params as $key => $value) {
            if (!preg_match('/^k[0-9A-Za-z]+$/', $key)) {
                continue;
            }
            if (!is_numeric($value)) {
                continue;
            }

            $floatValue = (float) $value;

            switch ($key) {
                case 'kff1006':
                    if ($floatValue >= -90.0 && $floatValue <= 90.0) {
                        $gpsLat = $floatValue;
                    }
                    break;
                case 'kff1005':
                    if ($floatValue >= -180.0 && $floatValue <= 180.0) {
                        $gpsLon = $floatValue;
                    }
                    break;
                case 'kff1009':
                    if ($gpsAlt === null) {
                        $gpsAlt = $floatValue;
                    }
                    break;
                case 'kff1010':
                    $gpsAlt = $floatValue;
                    break;
                case 'kff1001':
                    $gpsSpeed = $floatValue;
                    break;
            }

            $sensorCount++;

            $stmtCheck->execute([':key' => $key]);
            $exists = $stmtCheck->fetchColumn();

            if ($exists === false) {
                $stmtInsertSensor->execute([
                    ':key'        => $key,
                    ':short_name' => $sensorNames[$key] ?? $key,
                    ':full_name'  => $sensorDescriptions[$key] ?? null,
                    ':is_gps'     => in_array($key, $gpsSensorKeys, true) ? 1 : 0,
                ]);
                $newSensorCount++;
            } elseif (isset($sensorNames[$key])) {
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

        // Update session end_time + totals (runs even for metadata-only rows)
        $stmtSessionUpdate->execute([
            ':ts'           => $timestamp,
            ':sensor_count' => $sensorCount,
            ':profile_name' => $profileName,
            ':session_id'   => $sessionId,
        ]);

        // GPS point (k-sensor or trip-start notice)
        if ($gpsLat !== null && $gpsLon !== null && ($gpsLat != 0.0 || $gpsLon != 0.0)) {
            $stmtGps->execute([
                ':session_id' => $sessionId,
                ':timestamp'  => $timestamp,
                ':lat'        => $gpsLat,
                ':lon'        => $gpsLon,
                ':alt'        => $gpsAlt,
                ':speed'      => $gpsSpeed,
            ]);
        }

        // Processed summary
        $stmtProcessed->execute([
            ':raw_upload_id'  => $rawId,
            ':session_id'     => $sessionId,
            ':data_timestamp' => $timestamp,
            ':sensor_count'   => $sensorCount,
            ':new_sensors'    => $newSensorCount,
        ]);

        // Mark raw row as ok
        $stmtMarkOk->execute([':id' => $rawId]);

        $pdo->commit();

        echo "  [{$rawId}] OK session={$sessionId} sensors={$sensorCount} new={$newSensorCount}" . PHP_EOL;
        $success++;

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo "  [{$rawId}] ERROR: " . $e->getMessage() . PHP_EOL;
        $errors++;
    }
}

echo PHP_EOL;
echo "Done. success={$success}  skipped={$skipped}  errors={$errors}" . PHP_EOL;
