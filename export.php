<?php
declare(strict_types=1);

/**
 * export.php — CSV / JSON export endpoint.
 *
 * Requires browser auth. Exports all sensor readings for a given session as
 * either a CSV or JSON file download.
 *
 * Row format: session_id, timestamp (Unix ms), sensor_key, short_name, value
 *
 * Origin: export.php (updated for normalized schema)
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/Auth/Auth.php';
require_once __DIR__ . '/includes/Database/Connection.php';

use TorqueLogs\Auth\Auth;
use TorqueLogs\Database\Connection;

Auth::checkBrowser();

if (!isset($_GET['sid'])) {
    exit;
}

$session_id = preg_replace('/\D/', '', $_GET['sid']);
if ($session_id === '') {
    exit;
}

$filetype = $_GET['filetype'] ?? '';

$pdo  = Connection::get();

// Join sensor_readings with sensors to include the human-readable sensor name.
$stmt = $pdo->prepare(
    "SELECT
         sr.session_id,
         sr.timestamp,
         sr.sensor_key,
         COALESCE(s.short_name, sr.sensor_key) AS sensor_name,
         sr.value
     FROM sensor_readings sr
     LEFT JOIN sensors s ON s.sensor_key = sr.sensor_key
     WHERE sr.session_id = :sid
     ORDER BY sr.timestamp ASC, sr.sensor_key ASC"
);
$stmt->execute([':sid' => $session_id]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if ($filetype === 'csv') {
    // UTF-8 BOM so Excel opens the file with correct encoding.
    $output = "\xEF\xBB\xBF";

    if (!empty($rows)) {
        $escapeCsv = static fn (mixed $v): string => '"' . str_replace('"', '""', (string) $v) . '"';
        // Column headings
        $output .= implode(',', array_map($escapeCsv, array_keys($rows[0]))) . "\n";
        // Data rows
        foreach ($rows as $row) {
            $output .= implode(',', array_map($escapeCsv, $row)) . "\n";
        }
    }

    $csvfilename = 'torque_session_' . $session_id . '.csv';
    header('Content-Type: application/csv');
    header('Content-Disposition: attachment; filename="' . $csvfilename . '"');
    echo $output;
    exit;

} elseif ($filetype === 'json') {
    $jsonfilename = 'torque_session_' . $session_id . '.json';
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="' . $jsonfilename . '"');
    echo json_encode($rows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    exit;

} else {
    exit;
}

