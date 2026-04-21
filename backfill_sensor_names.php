<?php
declare(strict_types=1);

/**
 * backfill_sensor_names.php — CLI-only one-shot backfill.
 *
 * Reads all upload_requests_raw rows that contain userShortName/userFullName
 * params, applies the correct leading-zero-stripped key normalization, and
 * UPDATEs the sensors table with the proper human-readable names.
 *
 * Safe to run multiple times.
 *
 * Usage:
 *   php backfill_sensor_names.php           — apply updates
 *   php backfill_sensor_names.php --dry-run — print what would change, no writes
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

// Fetch only rows that contain name metadata (subset of all raw rows)
$stmt = $pdo->query(
    "SELECT raw_query_string FROM upload_requests_raw
     WHERE raw_query_string LIKE '%userShortName%'
     ORDER BY id ASC"
);

// Accumulate names across all rows; last non-empty name wins per sensor key.
$sensorNames        = [];
$sensorDescriptions = [];

foreach ($stmt->fetchAll() as $row) {
    parse_str($row['raw_query_string'], $params);

    foreach ($params as $pk => $pv) {
        if (!is_string($pv) || $pv === '') {
            continue;
        }
        if (preg_match('/^userShortName(.+)$/', $pk, $m)) {
            // Strip leading zeros: userShortName0d → k + 'd' = 'kd'
            $key = 'k' . (ltrim($m[1], '0') ?: '0');
            $sensorNames[$key] = $pv;
        } elseif (preg_match('/^userFullName(.+)$/', $pk, $m)) {
            $key = 'k' . (ltrim($m[1], '0') ?: '0');
            $sensorDescriptions[$key] = $pv;
        }
    }
}

echo "Extracted names for " . count($sensorNames) . " sensor keys." . PHP_EOL;
if ($dryRun) {
    echo "[DRY RUN — no writes]" . PHP_EOL;
}
echo PHP_EOL;

// Now update the sensors table
$stmtCheck  = $pdo->prepare("SELECT short_name, full_name FROM sensors WHERE sensor_key = :key");
$stmtUpdate = $pdo->prepare(
    "UPDATE sensors
     SET short_name = :short_name, full_name = COALESCE(:full_name, full_name), last_updated = CURRENT_TIMESTAMP
     WHERE sensor_key = :key"
);

$updated = 0;
$skipped = 0;
$missing = 0;

foreach ($sensorNames as $key => $shortName) {
    $stmtCheck->execute([':key' => $key]);
    $row = $stmtCheck->fetch();

    if ($row === false) {
        echo "  [{$key}] MISSING — sensor not in sensors table (not yet seen in readings)" . PHP_EOL;
        $missing++;
        continue;
    }

    $fullName    = $sensorDescriptions[$key] ?? null;
    $currentName = $row['short_name'];

    if ($currentName === $shortName) {
        $skipped++;
        continue;
    }

    echo "  [{$key}] " . $currentName . " → " . $shortName
        . ($fullName ? " (full: {$fullName})" : '') . PHP_EOL;

    if (!$dryRun) {
        $stmtUpdate->execute([
            ':key'        => $key,
            ':short_name' => $shortName,
            ':full_name'  => $fullName,
        ]);
        $updated++;
    } else {
        $updated++;
    }
}

echo PHP_EOL;
echo "Done. updated={$updated}  already_correct={$skipped}  missing_sensor={$missing}" . PHP_EOL;
