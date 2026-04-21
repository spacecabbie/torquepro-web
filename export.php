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

