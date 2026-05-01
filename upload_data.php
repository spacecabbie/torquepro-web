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
require_once __DIR__ . '/includes/Helpers/DataHelper.php';
require_once __DIR__ . '/parser.php';

use TorqueLogs\Auth\Auth;
use TorqueLogs\Database\Connection;
use TorqueLogs\Helpers\DataHelper;

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
    parseTorqueData($_GET, $sessionId, $deviceId, $timestamp, $eml, $profileName, $rawUploadId, $startTime);

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
