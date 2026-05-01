<?php
declare(strict_types=1);

/**
 * reprocess.php — Re-process existing raw Torque uploads
 *
 * Useful for backfilling after parser changes or fixing data issues.
 * Uses the new single-ID parser signature (parseTorqueData($rawUploadId)).
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/Database/Connection.php';
require_once __DIR__ . '/parser.php';

use TorqueLogs\Database\Connection;

$pdo = Connection::get();

$batchSize = 25;
$dryRun = isset($_GET['dry']) && $_GET['dry'] === '1';

echo "<h1>Torque Reprocess Tool</h1>";
echo "<p>Batch size: $batchSize | Dry run: " . ($dryRun ? 'YES' : 'NO') . "</p>";

try {
    // Find raw uploads that have not been processed yet
    $stmt = $pdo->query("
        SELECT r.id, r.raw_query_string, r.session_id
        FROM upload_requests_raw r
        LEFT JOIN upload_requests_processed p ON p.raw_upload_id = r.id
        WHERE p.raw_upload_id IS NULL
          AND r.result = 'ok'
          AND r.session_id IS NOT NULL
        ORDER BY r.id ASC
        LIMIT $batchSize
    ");

    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    if (empty($rows)) {
        echo "<p>No pending raw uploads found.</p>";
        exit;
    }

    echo "<p>Found " . count($rows) . " raw uploads to reprocess...</p>";

    $log = [];

    foreach ($rows as $raw) {
        $rawId = (int)$raw['id'];

        try {
            if (!$dryRun) {
                parseTorqueData($rawId);
            }
            $log[] = "OK     [{$rawId}] session={$raw['session_id']}";
        } catch (Throwable $e) {
            $log[] = "ERROR  [{$rawId}] " . $e->getMessage();
        }
    }

    echo "<h3>Results:</h3>";
    echo "<pre>" . implode("\n", $log) . "</pre>";

    if ($dryRun) {
        echo "<p><strong>Dry run completed — no changes were made.</strong></p>";
    } else {
        echo "<p><strong>Reprocessing completed.</strong></p>";
    }

} catch (Throwable $e) {
    echo "<p style='color:red'>Fatal error: " . htmlspecialchars($e->getMessage()) . "</p>";
}
