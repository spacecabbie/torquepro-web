<?php
declare(strict_types=1);

/**
 * reprocess.php — Web interface to reprocess raw upload data.
 *
 * Handles database reset and reprocessing of raw upload strings.
 * Requires authenticated user.
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/Auth/Auth.php';
require_once __DIR__ . '/includes/Database/Connection.php';
require_once __DIR__ . '/includes/Helpers/DataHelper.php';
require_once __DIR__ . '/parser.php';

use TorqueLogs\Auth\Auth;
use TorqueLogs\Database\Connection;
use TorqueLogs\Helpers\DataHelper;

// Auth check
Auth::checkBrowser();

$pdo = Connection::get();

if (isset($_GET['resetdb'])) {
    // Reset database: empty all tables except upload_requests_raw
    $tablesToEmpty = ['sessions', 'sensors', 'sensor_readings', 'gps_points', 'upload_requests_processed'];
    foreach ($tablesToEmpty as $table) {
        $pdo->exec("TRUNCATE TABLE $table");
    }
    echo "<p>Database reset complete. All tables except upload_requests_raw have been emptied.</p>";
    echo "<a href='reprocess.php'>Back</a>";
    exit;
}

if (isset($_GET['reprocess'])) {
    $confirm = $_GET['confirm'] ?? '';

    // Fetch all unprocessed raw rows
    $stmtFetch = $pdo->prepare(
        "SELECT r.id, r.session_id, r.raw_query_string, r.ip
         FROM upload_requests_raw r
         WHERE NOT EXISTS (
             SELECT 1 FROM upload_requests_processed p WHERE p.raw_upload_id = r.id
         )
         ORDER BY r.id ASC"
    );
    $stmtFetch->execute();
    $rawRows = $stmtFetch->fetchAll(\PDO::FETCH_ASSOC);

    $total = count($rawRows);

    if ($confirm !== 'all') {
        // Process first 25
        $batchSize = 25;
        $processed = 0;
        $log = [];

        foreach ($rawRows as $raw) {
            if ($processed >= $batchSize) break;

            $rawId = (int)$raw['id'];
            $sessionId = $raw['session_id'];

            if ($sessionId === null) {
                $log[] = "SKIP [{$rawId}] — no session_id";
                $processed++;
                continue;
            }

            // Parse the query string
            parse_str($raw['raw_query_string'], $params);

            $deviceId = ($params['id'] ?? '') !== '' ? md5($params['id']) : null;
            $eml = (($params['eml'] ?? '') !== '') ? $params['eml'] : null;
            $profileName = (($params['profileName'] ?? '') !== '') ? $params['profileName'] : null;

            $rawTime = $params['time'] ?? '';
            $timestamp = (is_numeric($rawTime) && (int)$rawTime > 0) ? (int)$rawTime : 0;

            if ($timestamp === 0) {
                $log[] = "SKIP [{$rawId}] — no valid timestamp";
                $processed++;
                continue;
            }

            try {
                parseTorqueData($params, $sessionId, $deviceId, $timestamp, $eml, $profileName, $rawId, hrtime(true));
                $log[] = "OK [{$rawId}] session={$sessionId}";
            } catch (Throwable $e) {
                $log[] = "ERROR [{$rawId}] " . $e->getMessage();
            }

            $processed++;
        }

        echo "<h2>Processed first 25 entries</h2>";
        echo "<pre>" . implode("\n", $log) . "</pre>";
        echo "<p>Total unprocessed rows: $total</p>";
        if ($total > $batchSize) {
            echo "<form method='get'>";
            echo "<input type='hidden' name='reprocess' value='1'>";
            echo "<input type='hidden' name='confirm' value='all'>";
            echo "<button type='submit'>Process All Remaining Rows</button>";
            echo "</form>";
        } else {
            echo "<p>All rows processed.</p>";
        }
    } else {
        // Process all remaining in batches
        $batchSize = 25;
        $totalProcessed = 0;
        $errors = 0;

        echo "<h2>Processing all remaining rows in batches of $batchSize</h2>";
        flush();

        $batches = array_chunk($rawRows, $batchSize);
        foreach ($batches as $batchIndex => $batch) {
            echo "<p>Processing batch " . ($batchIndex + 1) . "...</p>";
            flush();

            foreach ($batch as $raw) {
                $rawId = (int)$raw['id'];
                $sessionId = $raw['session_id'];

                if ($sessionId === null) {
                    $totalProcessed++;
                    continue;
                }

                parse_str($raw['raw_query_string'], $params);

                $deviceId = ($params['id'] ?? '') !== '' ? md5($params['id']) : null;
                $eml = (($params['eml'] ?? '') !== '') ? $params['eml'] : null;
                $profileName = (($params['profileName'] ?? '') !== '') ? $params['profileName'] : null;

                $rawTime = $params['time'] ?? '';
                $timestamp = (is_numeric($rawTime) && (int)$rawTime > 0) ? (int)$rawTime : 0;

                if ($timestamp === 0) {
                    $totalProcessed++;
                    continue;
                }

                try {
                    parseTorqueData($params, $sessionId, $deviceId, $timestamp, $eml, $profileName, $rawId, hrtime(true));
                } catch (Throwable $e) {
                    $errors++;
                }

                $totalProcessed++;
            }
        }

        echo "<p>Processing complete. Total processed: $totalProcessed, Errors: $errors</p>";
    }

    echo "<a href='reprocess.php'>Back</a>";
    exit;
}

// Default page
echo "<h1>Reprocess Raw Upload Data</h1>";
echo "<p><a href='?resetdb'>Reset Database (empty all tables except raw)</a></p>";
echo "<p><a href='?reprocess'>Reprocess Raw Strings</a></p>";
