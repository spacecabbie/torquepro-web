<?php
declare(strict_types=1);

/**
 * Torque Pro configuration
 *
 * Central place for known keys, prefixes, and behavior flags.
 * Loaded via: $config = require __DIR__ . '/Config/Torque.php';
 */
return [
    // GPS-related keys (Torque internal namespace - always the same across vehicles)
    'gps_keys' => [
        'kff1001', // GPS Speed (km/h)
        'kff1005', // GPS Longitude
        'kff1006', // GPS Latitude
        'kff1007', // GPS Bearing
        'kff1008', // GPS Accuracy (m)
        'kff1009', // GPS Altitude - older Torque versions
        'kff1010', // GPS Altitude (m) - current versions (preferred)
        'kff1011', // GPS Satellites in view
    ],

    // Calculated / trip computer / derived values (kff12xx, kff52xx, etc.)
    'calculated_prefixes' => [
        'kff12',   // e.g. kff1225 Torque, kff1226 Horsepower, kff1237 GPS vs OBD speed diff
        'kff52',   // long-term averages
        'kff125',  // fuel flow, CO₂
        'kff126',  // distance to empty, fuel remaining, cost
        'kff127',  // barometer, engine kW, trip stats
    ],

    // Common OBD-II PID suffix normalization map
    // Used to convert userShortName0d → kd, userShortName0c → kc, etc.
    'obd_pid_map' => [
        '0d' => 'd',   // Speed (OBD)
        '0c' => 'c',   // RPM
        '05' => '5',   // Engine Coolant Temperature
        '0f' => 'f',   // Intake Air Temperature
        '11' => '11',  // Throttle Position (Manifold)
        '0b' => 'b',   // Intake Manifold Pressure
        '10' => '10',  // Mass Air Flow Rate
        '04' => '4',   // Calculated Engine Load
        '03' => '3',   // Fuel System Status
        '2f' => '2f',  // Fuel Level
        '33' => '33',  // Barometric Pressure
        '46' => '46',  // Ambient Air Temperature
    ],
];
