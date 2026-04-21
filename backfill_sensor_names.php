<?php
declare(strict_types=1);
/**
 * backfill_sensor_names.php
 *
 * One-time script that sets column COMMENTs for well-known Torque Pro PIDs
 * that were created before sensor-name capture was added (or whose names
 * Torque never sends in the userShortName[] array).
 *
 * Safe to re-run: only updates columns that currently have an empty comment.
 *
 * Run with:  /usr/local/php84/bin/php backfill_sensor_names.php
 */

require_once __DIR__ . '/creds.php';
require_once __DIR__ . '/db.php';

// ── Known Torque PID → short name map ──────────────────────────────────────
// Sources: Torque Pro app source, OBD-II standard PIDs, Torque wiki
$KNOWN = [
    // Standard OBD-II (SAE J1979) PIDs used by Torque
    'k4'      => 'Engine Load',
    'k5'      => 'Engine Coolant Temp',
    'k6'      => 'Short Term Fuel Trim Bank 1',
    'k7'      => 'Long Term Fuel Trim Bank 1',
    'k8'      => 'Short Term Fuel Trim Bank 2',
    'k9'      => 'Long Term Fuel Trim Bank 2',
    'ka'      => 'Fuel Pressure',
    'kb'      => 'Intake Manifold Pressure',
    'kc'      => 'Engine RPM',
    'kd'      => 'Speed (OBD)',
    'ke'      => 'Timing Advance',
    'kf'      => 'Intake Air Temp',
    'k10'     => 'Mass Air Flow Rate',
    'k11'     => 'Throttle Position',
    'k14'     => 'O2 Sensor V (B1S1)',
    'k15'     => 'O2 Sensor V (B1S2)',
    'k1c'     => 'OBD Standards',
    'k1f'     => 'Run Time Since Engine Start',
    'k21'     => 'Distance (MIL On)',
    'k22'     => 'Fuel Rail Pressure',
    'k23'     => 'Fuel Rail Pressure (diesel)',
    'k24'     => 'O2 Sensor (B1S1)',
    'k2c'     => 'EGR Commanded',
    'k2d'     => 'EGR Error',
    'k2e'     => 'Evap Purge',
    'k2f'     => 'Fuel Level',
    'k31'     => 'Distance Since Codes Cleared',
    'k33'     => 'Barometric Pressure',
    'k42'     => 'Control Module Voltage',
    'k43'     => 'Absolute Engine Load',
    'k45'     => 'Relative Throttle Position',
    'k46'     => 'Ambient Air Temp',
    'k47'     => 'Absolute Throttle Position B',
    'k49'     => 'Accelerator Pedal D',
    'k4a'     => 'Accelerator Pedal E',
    'k4c'     => 'Commanded Throttle Actuator',
    'k4d'     => 'Time Run (MIL On)',
    'k4e'     => 'Time Since Codes Cleared',
    'k52'     => 'Ethanol Fuel Percentage',
    'k59'     => 'Fuel Rail Absolute Pressure',
    'k5a'     => 'Relative Accelerator Pedal',
    'k5b'     => 'Hybrid Battery Pack Life',
    'k5c'     => 'Engine Oil Temp',
    'k5d'     => 'Fuel Injection Timing',
    'k5e'     => 'Engine Fuel Rate',

    // Torque internal / GPS / virtual sensors (kff* range)
    'kff1001' => 'Speed (GPS)',
    'kff1005' => 'GPS Longitude',
    'kff1006' => 'GPS Latitude',
    'kff1007' => 'GPS Bearing',
    'kff1008' => 'GPS Satellites',
    'kff1009' => 'GPS vs OBD Speed diff',
    'kff1200' => 'GPS Altitude',
    'kff1201' => 'GPS vs OBD Speed diff',
    'kff1203' => 'GPS Altitude (secondary)',
    'kff1204' => 'GPS vs OBD Speed diff (2)',
    'kff1205' => 'Turbo Boost & Vacuum',
    'kff1206' => 'GPS Altitude (m)',
    'kff1207' => 'GPS vs OBD Speed diff (3)',
    'kff1210' => 'Air Fuel Ratio (Measured)',
    'kff1220' => 'Acceleration Sensor X',
    'kff1221' => 'Acceleration Sensor Y',
    'kff1222' => 'Acceleration Sensor Z',
    'kff1223' => 'Acceleration Sensor Total',
    'kff1224' => 'Tilt (X)',
    'kff1225' => 'Tilt (Y)',
];

// ── Fetch columns that have no comment yet ─────────────────────────────────
$pdo  = get_pdo();
$stmt = $pdo->prepare(
    "SELECT COLUMN_NAME, COLUMN_TYPE, COLUMN_COMMENT
     FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = :tbl
       AND COLUMN_NAME  LIKE 'k%'"
);
$stmt->execute([':tbl' => $db_table]);
$columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

$updated = 0;
$skipped = 0;

foreach ($columns as $col) {
    $name    = $col['COLUMN_NAME'];
    $comment = trim($col['COLUMN_COMMENT']);
    $type    = $col['COLUMN_TYPE'];

    if ($comment !== '') {
        // Already has a comment — skip
        $skipped++;
        continue;
    }

    if (!isset($KNOWN[$name])) {
        // Unknown PID — leave for Torque to fill
        continue;
    }

    $quotedName    = '`' . str_replace('`', '``', $name) . '`';
    $escapedComment = addslashes($KNOWN[$name]);

    $pdo->exec(
        "ALTER TABLE `{$db_table}` MODIFY {$quotedName} {$type} NOT NULL DEFAULT '0' COMMENT '{$escapedComment}'"
    );
    echo "  SET  {$name}  →  {$KNOWN[$name]}\n";
    $updated++;
}

echo "\nDone. Updated: {$updated}, already had name: {$skipped}.\n";
