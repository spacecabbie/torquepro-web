<?php
declare(strict_types=1);

/**
 * export.php — CSV / JSON export endpoint.
 *
 * Requires browser auth. Exports all sensor rows for a given session as
 * either a CSV or JSON file download.
 *
 * Origin: export.php (updated for OOP migration — Step 4)
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
$stmt = $pdo->prepare(
    'SELECT * FROM `' . DB_TABLE . '` WHERE session = :sid ORDER BY time DESC'
);
$stmt->execute([':sid' => $session_id]);
$rows = $stmt->fetchAll();

if ($filetype === 'csv') {
    $output = '';

    if (!empty($rows)) {
        // Column headings from the first row's keys.
        foreach (array_keys($rows[0]) as $heading) {
            $output .= '"' . addslashes((string) $heading) . '",';
        }
        $output .= "\n";

        foreach ($rows as $row) {
            foreach ($row as $cell) {
                $output .= '"' . addslashes((string) $cell) . '",';
            }
            $output .= "\n";
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
    echo json_encode($rows, JSON_THROW_ON_ERROR);
    exit;

} else {
    exit;
}

