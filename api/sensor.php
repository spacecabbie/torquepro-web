<?php
declare(strict_types=1);

/**
 * api/sensor.php — Per-panel time-series data endpoint.
 *
 * Returns time-series JSON for one sensor in one session.
 * Called by each dashboard panel via AJAX after page load.
 *
 * Request (GET):
 *   ?sid=<session_id>&key=<sensor_key>
 *
 * Response (JSON):
 *   {
 *     "label": "Speed [km/h]",
 *     "unit":  "km/h",
 *     "data":  [[timestamp_ms, value], ...]   ← sorted by timestamp ASC
 *   }
 *
 * Errors:
 *   HTTP 400 + {"error": "..."} for bad input
 *   HTTP 404 + {"error": "..."} for unknown session or sensor
 *
 * Auth: none — read-only endpoint, safe to expose publicly.
 *       Scope: all sessions (v1). Future: filter by eml/device_id.
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/Database/Connection.php';
require_once __DIR__ . '/../includes/Helpers/DataHelper.php';

use TorqueLogs\Database\Connection;
use TorqueLogs\Helpers\DataHelper;

header('Content-Type: application/json; charset=utf-8');
// Allow browser caching for 60 s — sensor data for a past session never changes.
header('Cache-Control: public, max-age=60');

/**
 * Emit a JSON error response and exit.
 */
function jsonError(int $status, string $message): never
{
    http_response_code($status);
    echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Input validation ────────────────────────────────────────────────────────

$sid = $_GET['sid'] ?? '';
$key = $_GET['key'] ?? '';

if (!preg_match('/^\d{1,20}$/', $sid)) {
    jsonError(400, 'Invalid session ID.');
}

// Sensor key: Torque uses alphanumeric keys like kd, kf, kff1006, k222408.
// Allow only safe characters: letters, digits, underscore.
if (!preg_match('/^[a-zA-Z0-9_]{1,40}$/', $key)) {
    jsonError(400, 'Invalid sensor key.');
}

// ── Database ────────────────────────────────────────────────────────────────

$pdo = Connection::get();

// Verify the session exists (scope: all sessions in v1).
$sessionStmt = $pdo->prepare(
    'SELECT session_id FROM sessions WHERE session_id = ? LIMIT 1'
);
$sessionStmt->execute([$sid]);
if ($sessionStmt->fetchColumn() === false) {
    jsonError(404, 'Session not found.');
}

// Verify the sensor exists and fetch its display name and unit.
$sensorStmt = $pdo->prepare(
    'SELECT s.short_name, s.full_name, u.symbol AS unit_symbol
       FROM sensors s
       LEFT JOIN unit_types u ON s.unit_id = u.id
      WHERE s.sensor_key = ?
      LIMIT 1'
);
$sensorStmt->execute([$key]);
$sensor = $sensorStmt->fetch(\PDO::FETCH_ASSOC);

if ($sensor === false) {
    jsonError(404, 'Sensor not found.');
}

$fallbacks = DataHelper::csvToMap(__DIR__ . '/../data/torque_keys.csv');

// Build a human-readable label.
$label = $sensor['short_name'] ?: $sensor['full_name'] ?: ($fallbacks[$key] ?? $key);
if (!empty($sensor['unit_symbol'])) {
    $label .= ' [' . $sensor['unit_symbol'] . ']';
}

// Fetch the time-series data for this sensor in this session.
// Returns rows ordered by timestamp ASC.
// UNIX_TIMESTAMP returns seconds; multiply by 1000 for JS/uPlot milliseconds.
$dataStmt = $pdo->prepare(
    'SELECT timestamp AS ts,
            value
       FROM sensor_readings
      WHERE session_id = ?
        AND sensor_key = ?
      ORDER BY timestamp ASC'
);
$dataStmt->execute([$sid, $key]);
$rows = $dataStmt->fetchAll(\PDO::FETCH_NUM);

if (empty($rows)) {
    jsonError(404, 'No data for this sensor in the selected session.');
}

// Cast to correct types for compact JSON output.
$data = array_map(
    static fn(array $row): array => [(int) $row[0], (float) $row[1]],
    $rows
);

echo json_encode(
    [
        'label' => $label,
        'unit'  => $sensor['unit_symbol'] ?? '',
        'data'  => $data,
    ],
    JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
);
